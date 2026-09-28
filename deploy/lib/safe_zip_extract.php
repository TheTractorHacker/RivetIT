<?php

/**
 * RivetIT — hardened zip-extraction shim for deploy/restore_admin_zip.sh.
 *
 * Reuses the app's own already-hardened extraction function
 * (setup/setup_functions.php's safeExtractZip()) instead of trusting a bare
 * `unzip` CLI call, for BOTH zips restore_admin_zip.sh opens: the outer
 * admin-panel backup zip itself (db.sql/uploads.zip/manifest/version.txt)
 * and, critically, the nested uploads.zip — whose extracted contents are
 * rsync'd straight into the live, web-served --app-dir/uploads tree
 * afterward.
 *
 * Why this exists (regression this closes): Info-ZIP `unzip` does not block
 * a Unix symlink entry inside a zip — it creates a real symlink pointing
 * wherever the archive says. `rsync -a` (used by restore_uploads() to place
 * the nested uploads.zip's contents into --app-dir/uploads) preserves
 * symlinks as symlinks rather than dereferencing them. A crafted
 * uploads.zip containing e.g. `leak.txt -> /var/www/.../config.php` would
 * therefore land as a real, web-served symlink, and this box's live nginx
 * config has no `disable_symlinks` directive — nginx would follow it and
 * serve config.php's bytes (DB credentials, config_settings_enc_key) as a
 * forced download. safeExtractZip() already defends against exactly this
 * (realpath() boundary re-checks plus an explicit is_link() check that
 * deletes the offending entry and throws) for the browser-based restore at
 * setup/index.php; this shim runs that SAME function against the CLI
 * restore path instead of re-implementing (or omitting) that protection.
 *
 * Deliberately NOT using setup/setup_functions.php's
 * extractUploadsZipWithValidationReport() for the nested uploads.zip case:
 * that function also enforces a dangerous-file-extension blocklist, content
 * sniffing, and per-file/total size caps sized for an anonymous browser
 * upload during /setup. Those are appropriate there, but here the operator
 * has already proven real Administrator authority over this exact box (or
 * supplied the backup's own passphrase) before this ever runs, and a
 * genuine backup can legitimately contain years of arbitrary historical
 * ticket-attachment content (any extension, any size, any total volume) —
 * failing the ENTIRE restore closed over one old attachment matching an
 * extension blocklist would turn "restore my own known-good backup" into a
 * silent data-loss trap, which is a worse outcome than the boundary/symlink
 * checks below are fixing. safeExtractZip() is exactly the same
 * boundary+symlink-only protection this file's own "outer zip" call already
 * gets, applied consistently to both.
 *
 * Usage:
 *   php safe_zip_extract.php <app_dir> <zip_path> <dest_dir>
 *
 * <app_dir> must be the target instance's own webroot (the SAME --app-dir
 * restore_admin_zip.sh is restoring into) — safeExtractZip() is loaded from
 * THAT install's own setup/setup_functions.php, so the exact validation
 * logic the running instance itself already ships is what runs, not
 * whatever version happens to live in this script's own repo checkout.
 *
 * Exit codes: 0 success ("OK" on stdout). 1 usage/setup error (bad args,
 * app_dir has no setup/setup_functions.php, zip file missing). 2 the zip
 * itself could not be opened (corrupt/not a zip). 3 safeExtractZip()
 * rejected an entry (path traversal, symlink, etc — stderr has the reason).
 */

if ($argc < 4) {
    fwrite(STDERR, "Usage: php safe_zip_extract.php <app_dir> <zip_path> <dest_dir>\n");
    exit(1);
}

[, $appDir, $zipPath, $destDir] = $argv;

$setupFunctions = rtrim($appDir, '/') . '/setup/setup_functions.php';
if (!is_file($setupFunctions)) {
    fwrite(STDERR, "Could not find setup/setup_functions.php under '$appDir' — is --app-dir a real RivetIT/ITFlow install?\n");
    exit(1);
}
require_once $setupFunctions;

if (!function_exists('safeExtractZip')) {
    fwrite(STDERR, "safeExtractZip() is not defined after loading '$setupFunctions' — this install's setup_functions.php may be an unexpectedly old/modified copy.\n");
    exit(1);
}

if (!is_file($zipPath)) {
    fwrite(STDERR, "Zip file not found: $zipPath\n");
    exit(1);
}

if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "PHP's ZipArchive class is not available (php-zip extension missing).\n");
    exit(1);
}

$zip = new ZipArchive();
$openResult = $zip->open($zipPath);
if ($openResult !== true) {
    fwrite(STDERR, "Failed to open zip '$zipPath' (ZipArchive error code $openResult).\n");
    exit(2);
}

try {
    safeExtractZip($zip, $destDir);
} catch (\Throwable $e) {
    $zip->close();
    fwrite(STDERR, 'Extraction rejected: ' . $e->getMessage() . "\n");
    exit(3);
}

$zip->close();
echo "OK\n";
exit(0);
