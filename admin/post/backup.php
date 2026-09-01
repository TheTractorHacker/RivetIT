<?php

/*
 * ITFlow - Backup POST/GET handler
 * Actions: backup_download_fresh, backup_save, backup_serve, backup_delete,
 *          save_backup_settings, backup_master_key
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

require_once "../includes/app_version.php";

@set_time_limit(0);
@ini_set('memory_limit', '1024M');

$BACKUP_DIR = $_SERVER['DOCUMENT_ROOT'] . '/backups';

// ── Shared helpers ─────────────────────────────────────────────────────────────

function fwrite_ln($fh, string $s): void {
    fwrite($fh, $s . PHP_EOL);
}

function dump_database_streaming(mysqli $mysqli, string $sqlFile): void {
    $fh = fopen($sqlFile, 'wb');
    if (!$fh) { http_response_code(500); exit("Cannot open dump file"); }

    fwrite_ln($fh, "-- ITFlow DB Dump | Generated: " . date('Y-m-d H:i:s'));
    fwrite_ln($fh, "SET NAMES 'utf8mb4';");
    fwrite_ln($fh, "SET FOREIGN_KEY_CHECKS = 0;");
    fwrite_ln($fh, "SET UNIQUE_CHECKS = 0;");
    fwrite_ln($fh, "SET AUTOCOMMIT = 0;");
    fwrite_ln($fh, "");

    $tables = []; $views = [];
    $res = $mysqli->query("SHOW FULL TABLES");
    while ($row = $res->fetch_array(MYSQLI_NUM)) {
        strtoupper($row[1] ?? '') === 'VIEW' ? ($views[] = $row[0]) : ($tables[] = $row[0]);
    }
    $res->close();

    foreach ($tables as $table) {
        $cr = $mysqli->query("SHOW CREATE TABLE `{$mysqli->real_escape_string($table)}`");
        if (!$cr) continue;
        $createSQL = array_values($cr->fetch_assoc())[1] ?? '';
        $cr->close();
        fwrite_ln($fh, "DROP TABLE IF EXISTS `{$table}`;");
        fwrite_ln($fh, $createSQL . ";");
        fwrite_ln($fh, "");
        $dr = $mysqli->query("SELECT * FROM `{$mysqli->real_escape_string($table)}`", MYSQLI_USE_RESULT);
        if ($dr) {
            while ($row = $dr->fetch_assoc()) {
                $cols = array_map(fn($c) => '`' . $mysqli->real_escape_string($c) . '`', array_keys($row));
                $vals = array_map(fn($v) => is_null($v) ? 'NULL' : "'" . $mysqli->real_escape_string($v) . "'", array_values($row));
                fwrite_ln($fh, "INSERT INTO `{$table}` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ");");
            }
            $dr->close();
            fwrite_ln($fh, "");
        }
    }

    foreach ($views as $view) {
        $vr = $mysqli->query("SHOW CREATE VIEW `{$mysqli->real_escape_string($view)}`");
        if ($vr) {
            $row = $vr->fetch_assoc();
            $sql = $row['Create View'] ?? '';
            $vr->close();
            fwrite_ln($fh, "DROP VIEW IF EXISTS `{$view}`;");
            fwrite_ln($fh, $sql . ";");
            fwrite_ln($fh, "");
        }
    }

    $tr = $mysqli->query("SHOW TRIGGERS");
    if ($tr) {
        while ($t = $tr->fetch_assoc()) {
            $cr2 = $mysqli->query("SHOW CREATE TRIGGER `{$mysqli->real_escape_string($t['Trigger'])}`");
            if ($cr2) {
                $row = $cr2->fetch_assoc();
                $sql = $row['SQL Original Statement'] ?? ($row['Create Trigger'] ?? '');
                $cr2->close();
                fwrite_ln($fh, "DROP TRIGGER IF EXISTS `{$t['Trigger']}`;");
                fwrite_ln($fh, $sql . ";");
                fwrite_ln($fh, "");
            }
        }
        $tr->close();
    }

    fwrite_ln($fh, "SET FOREIGN_KEY_CHECKS = 1;");
    fwrite_ln($fh, "SET UNIQUE_CHECKS = 1;");
    fwrite_ln($fh, "COMMIT;");
    fclose($fh);
}

function zip_uploads(string $uploadsPath, string $zipFilePath): void {
    $zip = new ZipArchive();
    if ($zip->open($zipFilePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        http_response_code(500); exit("Cannot create uploads zip");
    }
    $real = realpath($uploadsPath);
    if (!$real || !is_dir($real)) { $zip->close(); return; }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($it as $file) {
        if ($file->isDir() || $file->isLink()) continue;
        $fp = $file->getRealPath();
        if (!$fp || strpos($fp, $real . DIRECTORY_SEPARATOR) !== 0) continue;
        $zip->addFile($fp, substr($fp, strlen($real) + 1));
    }
    $zip->close();
}

/**
 * Build a complete backup zip. Returns path to the zip (caller must delete temp files).
 * $type: 'manual' or 'auto'
 */
function build_backup(mysqli $mysqli, string $type, string $backupDir): array {
    $timestamp    = date('YmdHis');
    $baseName     = "itflow_{$timestamp}_{$type}";
    $sqlFile      = tempnam(sys_get_temp_dir(), $baseName . '_sql_');
    $uploadsZip   = tempnam(sys_get_temp_dir(), $baseName . '_upl_');
    $versionFile  = tempnam(sys_get_temp_dir(), $baseName . '_ver_');
    $finalZip     = $backupDir . "/{$baseName}.zip";

    foreach ([$sqlFile, $uploadsZip, $versionFile] as $f) @chmod($f, 0600);

    dump_database_streaming($mysqli, $sqlFile);
    zip_uploads(dirname(__DIR__, 2) . '/uploads', $uploadsZip);

    $commitHash = trim(@shell_exec('git log -1 --format=%H 2>/dev/null') ?: 'N/A');
    $dbSha      = hash_file('sha256', $sqlFile) ?: 'N/A';
    $upSha      = hash_file('sha256', $uploadsZip) ?: 'N/A';

    $meta  = "ITFlow Backup Metadata\n";
    $meta .= "Generated: " . date('Y-m-d H:i:s') . "\n";
    $meta .= "Type: $type\n";
    $meta .= "Git Commit: $commitHash\n";
    $meta .= "ITFlow Version: " . (defined('APP_VERSION') ? APP_VERSION : 'N/A') . "\n";
    $meta .= "DB Version: " . (defined('CURRENT_DATABASE_VERSION') ? CURRENT_DATABASE_VERSION : 'N/A') . "\n";
    $meta .= "SHA256 db.sql: $dbSha\n";
    $meta .= "SHA256 uploads.zip: $upSha\n";
    file_put_contents($versionFile, $meta);

    $final = new ZipArchive();
    if ($final->open($finalZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        http_response_code(500); exit("Cannot create backup zip");
    }
    $final->addFile($sqlFile,     'db.sql');
    $final->addFile($uploadsZip,  'uploads.zip');
    $final->addFile($versionFile, 'version.txt');
    $final->close();
    @chmod($finalZip, 0640);

    @unlink($sqlFile); @unlink($uploadsZip); @unlink($versionFile);

    return ['path' => $finalZip, 'name' => basename($finalZip)];
}

function prune_backups(string $backupDir, int $retainCount): void {
    $files = glob($backupDir . '/itflow_*.zip') ?: [];
    usort($files, fn($a, $b) => filemtime($b) - filemtime($a)); // newest first
    foreach (array_slice($files, $retainCount) as $old) {
        @unlink($old);
    }
}

function safe_backup_filename(string $name): string {
    // Strip any directory traversal and ensure it matches expected pattern
    $base = basename($name);
    if (!preg_match('/^itflow_\d{14}_(manual|auto)\.zip$/', $base)) return '';
    return $base;
}

// ── Download fresh backup (stream to browser) ─────────────────────────────────
if (isset($_GET['backup_download_fresh'])) {
    validateCSRFToken($_GET['csrf_token']);

    $timestamp  = date('YmdHis');
    $baseName   = "itflow_{$timestamp}_manual";
    $sqlFile    = tempnam(sys_get_temp_dir(), $baseName . '_sql_');
    $uploadsZip = tempnam(sys_get_temp_dir(), $baseName . '_upl_');
    $versionFile= tempnam(sys_get_temp_dir(), $baseName . '_ver_');
    $finalZip   = tempnam(sys_get_temp_dir(), $baseName . '_zip_');

    register_shutdown_function(function() use ($sqlFile, $uploadsZip, $versionFile, $finalZip) {
        foreach ([$sqlFile, $uploadsZip, $versionFile, $finalZip] as $f) @unlink($f);
    });

    dump_database_streaming($mysqli, $sqlFile);
    zip_uploads(dirname(__DIR__, 2) . '/uploads', $uploadsZip);

    $meta  = "ITFlow Backup Metadata\n";
    $meta .= "Generated: " . date('Y-m-d H:i:s') . "\n";
    $meta .= "Type: manual (browser download)\n";
    $meta .= "SHA256 db.sql: " . (hash_file('sha256', $sqlFile) ?: 'N/A') . "\n";
    $meta .= "SHA256 uploads.zip: " . (hash_file('sha256', $uploadsZip) ?: 'N/A') . "\n";
    file_put_contents($versionFile, $meta);

    $final = new ZipArchive();
    $final->open($finalZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $final->addFile($sqlFile, 'db.sql');
    $final->addFile($uploadsZip, 'uploads.zip');
    $final->addFile($versionFile, 'version.txt');
    $final->close();

    $dlName = $baseName . '.zip';
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $dlName . '"');
    header('Content-Length: ' . filesize($finalZip));
    header('Pragma: public');
    header('Cache-Control: must-revalidate');
    readfile($finalZip);

    logAction('System', 'Backup Download', "$session_name downloaded a manual backup");
    exit;
}

// ── Save backup to server ─────────────────────────────────────────────────────
if (isset($_GET['backup_save'])) {
    validateCSRFToken($_GET['csrf_token']);
    $result = build_backup($mysqli, 'manual', $BACKUP_DIR);
    logAction('System', 'Backup Save', "$session_name saved backup {$result['name']} to server");
    flash_alert("Backup <strong>{$result['name']}</strong> saved to server");
    redirect();
}

// ── Serve stored backup file ──────────────────────────────────────────────────
if (isset($_GET['backup_serve'])) {
    validateCSRFToken($_GET['csrf_token']);
    $safe = safe_backup_filename($_GET['backup_serve'] ?? '');
    if (!$safe) { flash_alert('Invalid backup filename', 'error'); redirect(); }
    $path = $BACKUP_DIR . '/' . $safe;
    if (!is_file($path)) { flash_alert('Backup file not found', 'error'); redirect(); }

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $safe . '"');
    header('Content-Length: ' . filesize($path));
    header('Pragma: public');
    header('Cache-Control: must-revalidate');
    readfile($path);
    logAction('System', 'Backup Download', "$session_name re-downloaded stored backup $safe");
    exit;
}

// ── Delete stored backup ──────────────────────────────────────────────────────
if (isset($_GET['backup_delete'])) {
    validateCSRFToken($_GET['csrf_token']);
    $safe = safe_backup_filename($_GET['backup_delete'] ?? '');
    if (!$safe) { flash_alert('Invalid backup filename', 'error'); redirect(); }
    $path = $BACKUP_DIR . '/' . $safe;
    if (is_file($path)) {
        @unlink($path);
        logAction('System', 'Backup Delete', "$session_name deleted backup $safe");
        flash_alert("Backup <strong>$safe</strong> deleted", 'error');
    }
    redirect();
}

// ── Save backup settings ──────────────────────────────────────────────────────
if (isset($_POST['save_backup_settings'])) {
    validateCSRFToken($_POST['csrf_token']);
    $auto    = isset($_POST['config_backup_auto_enabled']) ? 1 : 0;
    $freq    = in_array($_POST['config_backup_frequency'] ?? '', ['daily','weekly']) ? $_POST['config_backup_frequency'] : 'daily';
    $retain  = max(1, min(90, intval($_POST['config_backup_retain_count'] ?? 7)));
    mysqli_query($mysqli, "UPDATE settings SET config_backup_auto_enabled = $auto, config_backup_frequency = '$freq', config_backup_retain_count = $retain WHERE company_id = 1");
    logAction('Settings', 'Edit', "$session_name updated backup settings");
    flash_alert('Backup settings saved');
    redirect();
}

// ── Master key reveal ─────────────────────────────────────────────────────────
if (isset($_POST['backup_master_key'])) {
    validateCSRFToken($_POST['csrf_token']);
    $password = $_POST['password'];
    $sql = mysqli_query($mysqli, "SELECT * FROM users WHERE user_id = $session_user_id");
    $row = mysqli_fetch_assoc($sql);
    if (password_verify($password, $row['user_password'])) {
        $site_encryption_master_key = decryptUserSpecificKey($row['user_specific_encryption_ciphertext'], $password);
        logAction('Master Key', 'Download', "$session_name retrieved the master encryption key");
        appNotify('Master Key', "$session_name retrieved the master encryption key");
        echo "<div class='alert alert-warning mt-2'><strong>Master Encryption Key:</strong><br><code>$site_encryption_master_key</code></div>";
    } else {
        logAction('Master Key', 'Download', "$session_name failed to retrieve the master encryption key");
        flash_alert('Incorrect password.', 'error');
        redirect();
    }
}

// ── Cron-triggered auto-backup (called from cron.php) ────────────────────────
if (isset($_GET['cron_backup']) && php_sapi_name() === 'cli') {
    // Only callable from CLI (cron)
    $result = build_backup($mysqli, 'auto', $BACKUP_DIR);
    prune_backups($BACKUP_DIR, $config_backup_retain_count);
    logApp('Backup', 'info', "Auto-backup completed: {$result['name']}");
    echo "Auto-backup saved: {$result['name']}\n";
    exit;
}
