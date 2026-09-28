#!/usr/bin/env php
<?php

// RivetIT command-line restore for the in-app backup format (the .zip produced by
// Settings > Backup / admin/post/backup.php, NOT deploy/backup.sh's encrypted
// backup-*.tar.gz.enc - see deploy/restore.sh for that one instead).
//
// The only other place this .zip format can be restored is the browser upload form
// in setup/index.php ($_POST['restore']) - there was no way to drive it from a
// script or over SSH. This reuses the exact same hardened helpers that browser path
// uses (setup/setup_functions.php: safeExtractZip, importSqlFile,
// extractUploadsZipWithValidationReport, setConfigFlagAtomic) rather than
// re-implementing zip-slip/upload validation a second time.
//
// Example
//   php restore_zip_cli.php --zip=/path/to/itflow_20260101120000_manual.zip --confirm-restore

chdir(__DIR__);

// PHP's die()/exit() with a STRING argument always exits 0, even for a real
// error - only an integer argument sets the exit code. A shell wrapper (e.g.
// deploy/restore_zip.sh) checking $? needs a real failure to look like one,
// so every error path below goes through this instead of a bare die().
function fail(string $message, ?string $tempDir = null): void {
    if ($tempDir !== null && is_dir($tempDir)) {
        deleteDir($tempDir);
    }
    fwrite(STDERR, "Error: $message\n");
    exit(1);
}

if (php_sapi_name() !== 'cli') {
    fail("This script must be run from the command line.");
}

require_once __DIR__ . '/../includes/branding.php';

$longopts = ["help", "zip:", "confirm-restore"];
$options = getopt("", $longopts);

if (isset($options['help'])) {
    echo APP_NAME . " CLI Restore (.zip backup format)\n\n";
    echo "Usage:\n";
    echo "  php restore_zip_cli.php --zip=<path> --confirm-restore\n\n";
    echo "Options:\n";
    echo "  --zip=<path>        Path to the .zip backup to restore (Settings > Backup's\n";
    echo "                      \"Download Backup\" / \"Save to Server\", or one pulled from\n";
    echo "                      configured S3-compatible remote storage). Required.\n";
    echo "  --confirm-restore   Required acknowledgment that this REPLACES every table in\n";
    echo "                      this instance's database and everything under uploads/\n";
    echo "                      with what's inside the zip. No interactive prompt exists\n";
    echo "                      to click through instead.\n";
    echo "  --help              Show this help message\n\n";
    echo "Requires ../config.php to already exist (run deploy/install.sh or\n";
    echo "scripts/setup_cli.php --config-only first) - this only restores data into an\n";
    echo "already-configured instance, it never creates one from nothing.\n";
    exit(0);
}

if (!file_exists('../config.php')) {
    fail("No config.php found. This script restores data into an already-configured instance - run deploy/install.sh or scripts/setup_cli.php --config-only first.");
}
require '../config.php';
require_once '../functions.php';
require_once '../setup/setup_functions.php';

$zipPath = $options['zip'] ?? '';
if ($zipPath === '') {
    fail("Missing required argument: --zip");
}
if (!is_file($zipPath) || !is_readable($zipPath)) {
    fail("--zip '$zipPath' does not exist or is not readable.");
}
if (!isset($options['confirm-restore'])) {
    fail("Refusing to run without --confirm-restore. This REPLACES every table in the '$database' database and everything under uploads/ with the contents of '$zipPath'. Re-run with --confirm-restore once you're sure.");
}

// logAction() below reads these via `global` - a CLI run has no HTTP session to
// pull them from, so they're set here once rather than left to emit "undefined
// variable" notices into otherwise-clean progress output.
$session_user_id = 0;
$session_ip = 'cli';
$session_user_agent = 'restore_zip_cli.php';

echo "=== " . APP_NAME . " zip-backup restore ===\n";
echo "Backup file: $zipPath\n";
echo "Target database: $database\n\n";

$tempDir = sys_get_temp_dir() . '/rivetit_restore_' . uniqid('', true);

echo "[1/5] Extracting backup archive...\n";
$zip = new ZipArchive();
if ($zip->open($zipPath) !== true) {
    fail("Failed to open '$zipPath' as a zip archive.", $tempDir);
}
try {
    // The outer archive holds exactly db.sql/uploads.zip/version.txt (produced by
    // this app's own backup feature), but the more hardened extractor is used
    // anyway rather than the simpler ad hoc check setup/index.php's browser path
    // still has - there's no downside to it here, and one fewer thing to keep in
    // sync between the two restore entry points.
    safeExtractZip($zip, $tempDir);
} catch (Throwable $e) {
    $zip->close();
    fail("Failed to extract backup archive: " . $e->getMessage(), $tempDir);
}
$zip->close();
echo "      Done.\n";

echo "[2/5] Importing database dump into '$database' (this replaces every existing table)...\n";
$sqlPath = "$tempDir/db.sql";
if (!is_file($sqlPath)) {
    fail("Missing db.sql in the backup archive.", $tempDir);
}
mysqli_query($mysqli, "SET FOREIGN_KEY_CHECKS = 0");
$dropped = 0;
$tables = mysqli_query($mysqli, "SHOW TABLES");
if ($tables) {
    while ($row = mysqli_fetch_row($tables)) {
        mysqli_query($mysqli, "DROP TABLE IF EXISTS `" . $row[0] . "`");
        $dropped++;
    }
}
mysqli_query($mysqli, "SET FOREIGN_KEY_CHECKS = 1");
echo "      Dropped $dropped existing table(s).\n";
try {
    importSqlFile($mysqli, $sqlPath);
} catch (Throwable $e) {
    fail("SQL import failed: " . $e->getMessage(), $tempDir);
}
echo "      Database import complete.\n";

echo "[3/5] Restoring uploads/...\n";
$uploadsZipPath = "$tempDir/uploads.zip";
if (!is_file($uploadsZipPath)) {
    fail("Missing uploads.zip in the backup archive.", $tempDir);
}
$uploadDir = rtrim(__DIR__ . '/../uploads', '/\\') . '/';
$uploads = new ZipArchive();
if ($uploads->open($uploadsZipPath) !== true) {
    fail("Failed to open uploads.zip in the backup archive.", $tempDir);
}
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0750, true)) {
        $uploads->close();
        fail("Failed to create uploads directory.", $tempDir);
    }
} else {
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($uploadDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    ) as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
}
$extractResult = extractUploadsZipWithValidationReport($uploads, $uploadDir);
$uploads->close();
if (!$extractResult['ok']) {
    $reasons = array_map(
        fn($issue) => $issue['path'] . ': ' . $issue['reason'],
        array_slice($extractResult['issues'] ?? [], 0, 25)
    );
    fail("uploads.zip failed validation and was not restored:\n  " . implode("\n  ", $reasons), $tempDir);
}
$fileCount = 0;
if (is_dir($uploadDir)) {
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($uploadDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    ) as $node) {
        if (!$node->isDir()) $fileCount++;
    }
}
if ($fileCount === 0) {
    fail("Uploads restore appears empty after extraction.", $tempDir);
}
echo "      Restored $fileCount file(s).\n";

echo "[4/5] Recording backup version info...\n";
$versionTxt = "$tempDir/version.txt";
if (file_exists($versionTxt)) {
    $versionInfo = @file_get_contents($versionTxt);
    if ($versionInfo !== false) {
        logAction("Backup Restore", "Version Info", $versionInfo);
        echo "      " . trim($versionInfo) . "\n";
    }
} else {
    echo "      (no version.txt in this backup - skipped)\n";
}

echo "[5/5] Finalizing configuration...\n";
try {
    setConfigFlagAtomic(__DIR__ . '/../config.php', 'config_enable_setup', 0);
} catch (Throwable $e) {
    fail("Failed to finalize config.php: " . $e->getMessage(), $tempDir);
}

deleteDir($tempDir);

echo "\nRestore complete.\n";
echo "You can now log in with an account from the restored backup at: https://$config_base_url/login.php\n";

exit(0);
