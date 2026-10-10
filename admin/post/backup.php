<?php

/*
 * RivetIT - Backup POST/GET handler
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

/**
 * $ledgerHead (by reference) receives the Training ledger head read INSIDE the dump's
 * consistent snapshot - ['seq' => int, 'hash' => string] - or stays null when the install has
 * no training_ledger_head table (pre-2.6.91) or the read fails. Callers write it into
 * version.txt, which anchors the hash chain outside the database (plan A8): a restored or
 * tampered database whose chain no longer reaches that head is detectable.
 */
function dump_database_streaming(mysqli $mysqli, string $sqlFile, ?array &$ledgerHead = null): void {
    $fh = fopen($sqlFile, 'wb');
    if (!$fh) { http_response_code(500); exit("Cannot open dump file"); }

    fwrite_ln($fh, "-- " . APP_NAME . " DB Dump | Generated: " . date('Y-m-d H:i:s'));
    fwrite_ln($fh, "SET NAMES 'utf8mb4';");
    fwrite_ln($fh, "SET FOREIGN_KEY_CHECKS = 0;");
    fwrite_ln($fh, "SET UNIQUE_CHECKS = 0;");
    fwrite_ln($fh, "SET AUTOCOMMIT = 0;");
    fwrite_ln($fh, "");

    // One consistent read view for the whole dump. Without it every table's
    // SELECT ran in its own autocommit snapshot, so a backup taken while
    // people were working could capture a child row whose parent was written
    // a moment later (or a counter ahead of the rows it counts) - a restore
    // then brings back data that never existed together. All tables are
    // InnoDB, and a consistent-snapshot read takes no locks, so writers are
    // never blocked by a backup.
    $mysqli->query("SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ");
    $mysqli->query("START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY");

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

    // Stored procedures/functions - parity with deploy/backup.sh's mysqldump, which is run with
    // --routines. This install currently defines none (verified via information_schema.ROUTINES),
    // but the two backup tools must stay equivalent regardless of what any given install has, or a
    // routine added later would silently vanish from every admin-panel backup while still showing
    // up fine in a deploy/backup.sh one. DEFINER is intentionally kept as-is (SHOW CREATE
    // PROCEDURE/FUNCTION always includes it) - same as mysqldump's own default behavior, and this
    // app's DB user already has CREATE ROUTINE-equivalent rights from its own schema.
    $dbNameRow = $mysqli->query('SELECT DATABASE() AS db')?->fetch_assoc();
    $dbName = $dbNameRow['db'] ?? '';
    if ($dbName !== '') {
        foreach (['PROCEDURE', 'FUNCTION'] as $routineType) {
            $rr = $mysqli->query("SHOW {$routineType} STATUS WHERE Db = '" . $mysqli->real_escape_string($dbName) . "'");
            if (!$rr) continue;
            while ($routine = $rr->fetch_assoc()) {
                $rName = $routine['Name'];
                $cr3 = $mysqli->query("SHOW CREATE {$routineType} `{$mysqli->real_escape_string($rName)}`");
                if (!$cr3) continue;
                $row = $cr3->fetch_assoc();
                // MySQL/MariaDB names this column "Create Function"/"Create Procedure" (title case),
                // NOT "Create FUNCTION"/"Create PROCEDURE" (matching $routineType's own all-caps
                // casing) - array keys are case-sensitive, so looking it up with $routineType's raw
                // casing silently missed on every row, always falling through to '' and skipping
                // every routine without ever raising an error. Confirmed empirically (this was NOT
                // caught by testing, since the install this was written against had zero routines to
                // exercise it against - see the comment above this loop).
                $sql = $row['Create ' . ucfirst(strtolower($routineType))] ?? '';
                $cr3->close();
                if ($sql === '') continue;
                fwrite_ln($fh, "DROP {$routineType} IF EXISTS `{$rName}`;");
                fwrite_ln($fh, "DELIMITER $$");
                fwrite_ln($fh, $sql . "$$");
                fwrite_ln($fh, "DELIMITER ;");
                fwrite_ln($fh, "");
            }
            $rr->close();
        }
    }

    // Training ledger head, from the same snapshot as the rows dumped above.
    $ledgerHead = null;
    if (in_array('training_ledger_head', $tables, true)) {
        try {
            $lh = $mysqli->query("SELECT lhead_last_seq, lhead_last_hash FROM training_ledger_head WHERE lhead_id = 1");
            $lhRow = $lh ? $lh->fetch_assoc() : null;
            if ($lh) { $lh->close(); }
            if ($lhRow) {
                $ledgerHead = ['seq' => (int) $lhRow['lhead_last_seq'], 'hash' => (string) $lhRow['lhead_last_hash']];
            }
        } catch (\Throwable $e) {
            $ledgerHead = null;
        }
    }

    // Ends the read-only snapshot opened above (nothing to commit).
    $mysqli->query("COMMIT");

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
        $rel = substr($fp, strlen($real) + 1);
        $zip->addFile($fp, $rel);
        // Training media (MP4, JPEG, PDF) is already compressed - deflating it again only
        // burns CPU on every backup. Store those entries as-is.
        if (strpos($rel, 'training/') === 0) {
            $zip->setCompressionName($rel, ZipArchive::CM_STORE);
        }
    }
    $zip->close();
}

/** Raised when a backup is requested without a usable backup passphrase. Callers show message(); cron logs it. */
class BackupPassphraseRequired extends RuntimeException {}

/** Shortest backup passphrase the app accepts (the key that unlocks the settings key and every secret in a restore). */
const BACKUP_PASSPHRASE_MIN_LENGTH = 16;

/**
 * The saved backup passphrase if it is usable (set and at least BACKUP_PASSPHRASE_MIN_LENGTH characters), else null.
 * Reads the decrypted global (admin pages load it through load_global_settings; cron through backup_cron_settings).
 */
function backup_passphrase_or_null(): ?string {
    $p = (string) ($GLOBALS['config_backup_passphrase'] ?? '');
    return strlen($p) >= BACKUP_PASSPHRASE_MIN_LENGTH ? $p : null;
}

/** Refuses (throws) when no usable passphrase is configured. Every backup path goes through build_backup(), which calls this. */
function backup_require_passphrase(): string {
    $p = backup_passphrase_or_null();
    if ($p === null) {
        throw new BackupPassphraseRequired(
            'A backup passphrase of at least ' . BACKUP_PASSPHRASE_MIN_LENGTH . ' characters is required before any backup can be built. '
            . 'Set one under Admin > Backup > Backup encryption passphrase, and store it somewhere other than this server.'
        );
    }
    return $p;
}

/** Short, non-reversible identifier for the settings key, written to manifests so a restore can tell which key a backup needs. */
function backup_settings_key_fingerprint(string $key): string {
    return $key === '' ? '' : substr(hash('sha256', 'rivetit-settings-key-fingerprint|v1|' . $key), 0, 16);
}

/**
 * The one thing db.sql + uploads.zip never carried: config.php's $config_settings_enc_key. Without
 * it, restoring this backup onto a different config.php (a fresh install's own freshly generated
 * key) leaves every SMTP/IMAP password, RMM/webhook secret and the wrapped credentials-vault master
 * key in db.sql undecryptable - the backup has the bytes but isn't actually restorable.
 *
 * The key is written ONLY inside the passphrase-encrypted manifest (backup-manifest.json.enc). A
 * manifest that cannot be encrypted (no passphrase passed) carries a key FINGERPRINT only, never the
 * key itself; a restore from such a manifest needs the operator to supply the key. build_backup()
 * never takes that path (it refuses to run without a passphrase); it exists for callers and tests.
 * Same shape as deploy/backup.sh's own manifest (schema_version, db_name, installation_id,
 * backup_timestamp), so deploy/restore.sh's key-recovery step can read either tool's file, and the
 * same admin-set passphrase decrypts either.
 *
 * @return array{name: string, path: string} the temp file to add to the zip, and its entry name
 *         (backup-manifest.json.enc, or backup-manifest.json when $passphrase is empty)
 */
function build_backup_manifest(mysqli $mysqli, string $baseName, ?string $passphrase): array {
    $manifestFile = tempnam(sys_get_temp_dir(), $baseName . '_man_');
    @chmod($manifestFile, 0600);

    $dbNameRow = $mysqli->query('SELECT DATABASE() AS db')?->fetch_assoc();
    $settingsKey = (string) ($GLOBALS['config_settings_enc_key'] ?? '');
    $encrypted   = $passphrase !== null && $passphrase !== '';
    $data = [
        'schema_version'               => 2,
        'db_name'                      => $dbNameRow['db'] ?? 'N/A',
        'installation_id'              => $GLOBALS['installation_id'] ?? 'N/A',
        'settings_enc_key_fingerprint' => backup_settings_key_fingerprint($settingsKey),
        'backup_timestamp'             => date('YmdHis'),
    ];
    // The key itself only ever goes into a manifest that is about to be encrypted.
    if ($encrypted) {
        $data['settings_enc_key'] = $settingsKey;
    }
    file_put_contents($manifestFile, json_encode($data, JSON_PRETTY_PRINT));

    if (!$encrypted) {
        return ['name' => 'backup-manifest.json', 'path' => $manifestFile];
    }

    // Same scheme as deploy/backup.sh: openssl enc -aes-256-cbc -pbkdf2 -salt -pass file:<tmp>.
    // Shelling out to the real openssl binary (rather than reimplementing its salted-header +
    // PBKDF2 framing in PHP) is what guarantees deploy/restore.sh's `openssl enc -d` can decrypt
    // this exact file with the exact same command it already uses.
    $passFile = tempnam(sys_get_temp_dir(), $baseName . '_pass_');
    @chmod($passFile, 0600);
    file_put_contents($passFile, $passphrase);
    $encFile = $manifestFile . '.enc';
    $cmd = sprintf(
        'openssl enc -aes-256-cbc -pbkdf2 -salt -in %s -out %s -pass file:%s 2>&1',
        escapeshellarg($manifestFile),
        escapeshellarg($encFile),
        escapeshellarg($passFile)
    );
    exec($cmd, $out, $exitCode);
    @unlink($passFile);
    @unlink($manifestFile);
    if ($exitCode !== 0 || !is_file($encFile)) {
        // Never silently fall back to plaintext when the admin explicitly opted into encryption -
        // fail the whole backup instead, the same way a failed mysqli/zip open does above.
        error_log('Backup: openssl manifest encryption failed: ' . implode(' ', $out));
        http_response_code(500); exit('Cannot encrypt backup manifest');
    }
    @chmod($encFile, 0600);
    return ['name' => 'backup-manifest.json.enc', 'path' => $encFile];
}

/**
 * Build a complete backup zip. Returns path to the zip (caller must delete temp files).
 * $type: 'manual' or 'auto'
 */
function build_backup(mysqli $mysqli, string $type, string $backupDir): array {
    // Refuse before any temp file or dump is created. Throws BackupPassphraseRequired.
    $passphrase   = backup_require_passphrase();
    // Status record (Recovery): a failed or interrupted run is alerted, see src/Recovery/BackupStatus.php.
    $statusId     = \ITFlow\Recovery\BackupStatus::begin($mysqli, $type === 'auto' ? 'app_auto' : 'app_manual');
    $timestamp    = date('YmdHis');
    $baseName     = "itflow_{$timestamp}_{$type}";
    $sqlFile      = tempnam(sys_get_temp_dir(), $baseName . '_sql_');
    $uploadsZip   = tempnam(sys_get_temp_dir(), $baseName . '_upl_');
    $versionFile  = tempnam(sys_get_temp_dir(), $baseName . '_ver_');
    $finalZip     = $backupDir . "/{$baseName}.zip";

    foreach ([$sqlFile, $uploadsZip, $versionFile] as $f) @chmod($f, 0600);

    $ledgerHead = null;
    dump_database_streaming($mysqli, $sqlFile, $ledgerHead);
    zip_uploads(dirname(__DIR__, 2) . '/uploads', $uploadsZip);

    $commitHash = trim(@shell_exec('git log -1 --format=%H 2>/dev/null') ?: 'N/A');
    $dbSha      = hash_file('sha256', $sqlFile) ?: 'N/A';
    $upSha      = hash_file('sha256', $uploadsZip) ?: 'N/A';

    $meta  = APP_NAME . " Backup Metadata\n";
    $meta .= "Generated: " . date('Y-m-d H:i:s') . "\n";
    $meta .= "Type: $type\n";
    $meta .= "Git Commit: $commitHash\n";
    $meta .= APP_NAME . " Version: " . (defined('APP_VERSION') ? APP_VERSION : 'N/A') . "\n";
    $meta .= "DB Version: " . (defined('CURRENT_DATABASE_VERSION') ? CURRENT_DATABASE_VERSION : 'N/A') . "\n";
    $meta .= "SHA256 db.sql: $dbSha\n";
    $meta .= "SHA256 uploads.zip: $upSha\n";
    if ($ledgerHead !== null) {
        $meta .= "Training ledger head: #{$ledgerHead['seq']} {$ledgerHead['hash']}\n";
    }

    $manifest   = build_backup_manifest($mysqli, $baseName, $passphrase);
    $meta      .= "Manifest: {$manifest['name']} (encrypted with the saved backup passphrase)\n";
    file_put_contents($versionFile, $meta);

    $final = new ZipArchive();
    if ($final->open($finalZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        http_response_code(500); exit("Cannot create backup zip");
    }
    $final->addFile($sqlFile,         'db.sql');
    $final->addFile($uploadsZip,      'uploads.zip');
    $final->addFile($versionFile,     'version.txt');
    $final->addFile($manifest['path'], $manifest['name']);
    // Table snapshot for the restore drill (table-snapshot.json, counts only, no secrets). Separate from the manifest on purpose.
    $snapshotFile = \ITFlow\Recovery\TableSnapshot::toTempFile($mysqli, $baseName);
    if ($snapshotFile !== null) {
        $final->addFile($snapshotFile, \ITFlow\Recovery\TableSnapshot::ENTRY);
    }
    $zipClosed = $final->close();
    @chmod($finalZip, 0640);

    @unlink($sqlFile); @unlink($uploadsZip); @unlink($versionFile); @unlink($manifest['path']);
    if ($snapshotFile !== null) { @unlink($snapshotFile); }

    $zipOk = $zipClosed && is_file($finalZip) && filesize($finalZip) > 0;
    \ITFlow\Recovery\BackupStatus::finish($mysqli, $statusId, [
        'ok'     => $zipOk,
        'file'   => $finalZip,
        'size'   => $zipOk ? filesize($finalZip) : null,
        'sha256' => $zipOk ? (hash_file('sha256', $finalZip) ?: null) : null,
        'error'  => $zipOk ? null : 'the backup zip could not be finalized (disk full or permissions on the backups folder)',
    ]);

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

// ── Remote Storage (S3-compatible) ──────────────────────────────────────────

/**
 * Builds an S3Client from an explicit config array (not globals), so the
 * same code path serves both the real save/upload flow (already-persisted
 * settings) and "Test Connection" (whatever is currently in the form,
 * possibly not saved yet). $cfg keys: endpoint, region, access_key,
 * secret_key, path_style.
 */
function backup_s3_client(array $cfg): \Aws\S3\S3Client {
    require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

    $args = [
        'version'                 => 'latest',
        'region'                  => $cfg['region'] ?: 'us-east-1',
        'credentials'             => [
            'key'    => $cfg['access_key'] ?? '',
            'secret' => $cfg['secret_key'] ?? '',
        ],
        'use_path_style_endpoint' => !empty($cfg['path_style']),
        // RustFS answers 503 "quota check unavailable" for a while after it starts; retry with backoff.
        'retries'                 => ['mode' => 'standard', 'max_attempts' => 8],
    ];
    // Only AWS itself resolves without one - a self-hosted service (RustFS,
    // MinIO, etc.) always needs its own API URL here.
    if (!empty($cfg['endpoint'])) {
        $args['endpoint'] = $cfg['endpoint'];
    }

    return new \Aws\S3\S3Client($args);
}

/**
 * Uploads one backup zip to the configured S3-compatible bucket. Reads the
 * already-saved, already-decrypted config_backup_s3_* globals (loaded by
 * includes/load_global_settings.php) - not meant for "Test Connection",
 * which builds its own $cfg from the submitted form instead. Returns true
 * on success; failures are logged, never thrown - a broken remote-storage
 * config must not stop the local backup that already succeeded.
 */
function backup_upload_to_s3(string $filePath, string $fileName, bool $manual = false): bool {
    global $mysqli, $config_backup_s3_enabled, $config_backup_s3_endpoint, $config_backup_s3_region,
           $config_backup_s3_bucket, $config_backup_s3_access_key, $config_backup_s3_secret_key,
           $config_backup_s3_path_style, $config_backup_s3_prefix;

    // A manual upload is an explicit request, so it goes ahead even when the "upload every backup"
    // switch is off; it still needs a bucket saved.
    $GLOBALS['backup_s3_last_error'] = '';
    if ((!$manual && empty($config_backup_s3_enabled)) || empty($config_backup_s3_bucket)) {
        return false;
    }

    try {
        $client = backup_s3_client([
            'endpoint'    => $config_backup_s3_endpoint,
            'region'      => $config_backup_s3_region,
            'access_key'  => $config_backup_s3_access_key,
            'secret_key'  => $config_backup_s3_secret_key,
            'path_style'  => $config_backup_s3_path_style,
        ]);

        $key = ltrim(($config_backup_s3_prefix ?: '') . $fileName, '/');

        $client->putObject([
            'Bucket'     => $config_backup_s3_bucket,
            'Key'        => $key,
            'SourceFile' => $filePath,
        ]);

        logApp('Backup', 'info', "Uploaded backup $fileName to S3 bucket {$config_backup_s3_bucket} (key: $key)");
        \ITFlow\Recovery\BackupStatus::noteOffsite($mysqli, $fileName, "ok: s3://{$config_backup_s3_bucket}/$key");
        return true;
    } catch (\Throwable $e) {
        $GLOBALS['backup_s3_last_error'] = $e instanceof \Aws\Exception\AwsException && $e->getAwsErrorMessage()
            ? ($e->getAwsErrorCode() . ': ' . $e->getAwsErrorMessage())
            : strtok($e->getMessage(), "\n");
        logApp('Backup', 'error', "S3 upload failed for $fileName: " . $e->getMessage());
        \ITFlow\Recovery\BackupStatus::noteOffsite($mysqli, $fileName, 'failed: ' . $GLOBALS['backup_s3_last_error']);
        return false;
    }
}

// ── Download fresh backup (stream to browser) ─────────────────────────────────
// Builds via the exact same build_backup() the "Save to Server" and cron
// auto-backup paths use (into a scratch temp directory instead of $BACKUP_DIR,
// so nothing is left on the server afterward), then streams that file and
// deletes it. Previously this duplicated build_backup()'s whole zip-assembly
// (db.sql + uploads.zip + version.txt + manifest) inline with its own,
// slightly different version.txt content - one source of truth now, so a
// future fix to what a backup contains can't be applied to one path and
// forgotten on the other.
if (isset($_GET['backup_download_fresh'])) {
    validateCSRFToken($_GET['csrf_token']);

    try {
        $result = build_backup($mysqli, 'manual', sys_get_temp_dir());
    } catch (BackupPassphraseRequired $e) {
        flash_alert(htmlspecialchars($e->getMessage(), ENT_QUOTES), 'error');
        redirect();
    }
    register_shutdown_function(function() use ($result) { @unlink($result['path']); });

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $result['name'] . '"');
    header('Content-Length: ' . filesize($result['path']));
    header('Pragma: public');
    header('Cache-Control: must-revalidate');
    readfile($result['path']);

    logAction('System', 'Backup Download', "$session_name downloaded a manual backup");
    exit;
}

// ── Save backup to server ─────────────────────────────────────────────────────
if (isset($_GET['backup_save'])) {
    validateCSRFToken($_GET['csrf_token']);
    try {
        $result = build_backup($mysqli, 'manual', $BACKUP_DIR);
    } catch (BackupPassphraseRequired $e) {
        flash_alert(htmlspecialchars($e->getMessage(), ENT_QUOTES), 'error');
        redirect();
    }
    logAction('System', 'Backup Save', "$session_name saved backup {$result['name']} to server");
    $s3_ok = backup_upload_to_s3($result['path'], $result['name']);
    $s3_note = $config_backup_s3_enabled ? ($s3_ok ? ' and uploaded to remote storage' : ' (remote storage upload failed - check Admin > Backup)') : '';
    flash_alert("Backup <strong>{$result['name']}</strong> saved to server$s3_note");
    redirect();
}

// ── Manual S3: fresh backup straight to remote storage ───────────────────────
// Same build_backup() as every other path, built in a scratch directory, uploaded, then removed -
// nothing is kept on this server (use Save to Server for a local copy as well).
if (isset($_GET['backup_s3_now'])) {
    validateCSRFToken($_GET['csrf_token']);
    if (empty($config_backup_s3_bucket)) {
        flash_alert('Remote storage is not configured - save a bucket under Remote Storage first.', 'error');
        redirect();
    }
    try {
        $result = build_backup($mysqli, 'manual', sys_get_temp_dir());
    } catch (BackupPassphraseRequired $e) {
        flash_alert(htmlspecialchars($e->getMessage(), ENT_QUOTES), 'error');
        redirect();
    }
    $s3_ok = backup_upload_to_s3($result['path'], $result['name'], true);
    @unlink($result['path']);
    if ($s3_ok) {
        logAction('System', 'Backup S3', "$session_name backed up {$result['name']} to remote storage");
        flash_alert("Backup <strong>{$result['name']}</strong> uploaded to remote storage");
    } else {
        flash_alert('Remote storage upload failed: ' . htmlspecialchars($GLOBALS['backup_s3_last_error'] ?: 'unknown error', ENT_QUOTES), 'error');
    }
    redirect();
}

// ── Manual S3: upload an existing stored backup ──────────────────────────────
if (isset($_GET['backup_s3_upload'])) {
    validateCSRFToken($_GET['csrf_token']);
    $safe = safe_backup_filename($_GET['backup_s3_upload'] ?? '');
    if (!$safe) { flash_alert('Invalid backup filename', 'error'); redirect(); }
    $path = $BACKUP_DIR . '/' . $safe;
    if (!is_file($path)) { flash_alert('Backup file not found', 'error'); redirect(); }
    if (backup_upload_to_s3($path, $safe, true)) {
        logAction('System', 'Backup S3', "$session_name uploaded stored backup $safe to remote storage");
        flash_alert("Backup <strong>$safe</strong> uploaded to remote storage");
    } else {
        flash_alert('Remote storage upload failed: ' . htmlspecialchars($GLOBALS['backup_s3_last_error'] ?: 'no bucket saved', ENT_QUOTES), 'error');
    }
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
    $set = "config_backup_auto_enabled = $auto, config_backup_frequency = '$freq', config_backup_retain_count = $retain";
    // Blank = keep whatever's already saved (same convention as the S3 secret key below it on this form).
    if (trim($_POST['config_backup_passphrase'] ?? '') !== '') {
        $new_passphrase = trim($_POST['config_backup_passphrase']);
        if (strlen($new_passphrase) < BACKUP_PASSPHRASE_MIN_LENGTH) {
            flash_alert('The backup passphrase must be at least ' . BACKUP_PASSPHRASE_MIN_LENGTH . ' characters. Nothing was saved.', 'error');
            redirect();
        }
        $passphrase = mysqli_real_escape_string($mysqli, encryptSetting($new_passphrase));
        $set .= ", config_backup_passphrase = '$passphrase'";
    }
    mysqli_query($mysqli, "UPDATE settings SET $set WHERE company_id = 1");
    logAction('Settings', 'Edit', "$session_name updated backup settings");
    flash_alert('Backup settings saved');
    redirect();
}

// ── Save Remote Storage (S3) settings ──────────────────────────────────────────
if (isset($_POST['save_backup_s3_settings'])) {
    validateCSRFToken($_POST['csrf_token']);

    $s3_enabled    = isset($_POST['config_backup_s3_enabled']) ? 1 : 0;
    $s3_endpoint   = mysqli_real_escape_string($mysqli, sanitizeInput($_POST['config_backup_s3_endpoint'] ?? ''));
    $s3_region     = mysqli_real_escape_string($mysqli, sanitizeInput($_POST['config_backup_s3_region'] ?? '') ?: 'us-east-1');
    $s3_bucket     = mysqli_real_escape_string($mysqli, sanitizeInput($_POST['config_backup_s3_bucket'] ?? ''));
    $s3_access_key = mysqli_real_escape_string($mysqli, sanitizeInput($_POST['config_backup_s3_access_key'] ?? ''));
    $s3_path_style = isset($_POST['config_backup_s3_path_style']) ? 1 : 0;
    $s3_prefix     = mysqli_real_escape_string($mysqli, sanitizeInput($_POST['config_backup_s3_prefix'] ?? ''));

    $set = "config_backup_s3_enabled = $s3_enabled, config_backup_s3_endpoint = '$s3_endpoint', config_backup_s3_region = '$s3_region', config_backup_s3_bucket = '$s3_bucket', config_backup_s3_access_key = '$s3_access_key', config_backup_s3_path_style = $s3_path_style, config_backup_s3_prefix = '$s3_prefix'";

    // Blank = keep whatever's already saved (same "don't overwrite a secret
    // with nothing" convention as Comet's admin password).
    if (trim($_POST['config_backup_s3_secret_key'] ?? '') !== '') {
        $s3_secret = mysqli_real_escape_string($mysqli, encryptSetting(trim($_POST['config_backup_s3_secret_key'])));
        $set .= ", config_backup_s3_secret_key = '$s3_secret'";
    }

    mysqli_query($mysqli, "UPDATE settings SET $set WHERE company_id = 1");
    logAction('Settings', 'Edit', "$session_name updated backup remote storage (S3) settings");
    flash_alert('Remote storage settings saved');
    redirect();
}

// ── Test Remote Storage (S3) connection ────────────────────────────────────────
if (isset($_POST['backup_s3_test'])) {
    validateCSRFToken($_POST['csrf_token']);

    $test_bucket = sanitizeInput($_POST['config_backup_s3_bucket'] ?? '');
    if ($test_bucket === '') {
        flash_alert('Enter a bucket name before testing', 'error');
        redirect();
    }

    // Uses whatever is in the form right now (so a not-yet-saved change can be
    // tested before committing it) - falls back to the already-saved secret
    // key when the field was left blank, same "blank = keep existing" as the
    // save handler above.
    $test_secret = trim($_POST['config_backup_s3_secret_key'] ?? '');
    if ($test_secret === '') {
        $test_secret = $config_backup_s3_secret_key;
    }

    try {
        $client = backup_s3_client([
            'endpoint'   => sanitizeInput($_POST['config_backup_s3_endpoint'] ?? ''),
            'region'     => sanitizeInput($_POST['config_backup_s3_region'] ?? '') ?: 'us-east-1',
            'access_key' => sanitizeInput($_POST['config_backup_s3_access_key'] ?? ''),
            'secret_key' => $test_secret,
            'path_style' => isset($_POST['config_backup_s3_path_style']),
        ]);
        $client->headBucket(['Bucket' => $test_bucket]);
        flash_alert("Connected to bucket <strong>" . nullable_htmlentities($test_bucket) . "</strong> successfully");
    } catch (\Throwable $e) {
        flash_alert('Connection failed: ' . nullable_htmlentities($e->getMessage()), 'error');
    }
    redirect();
}

// ── Recovery settings + "Run drill now" (restore drill, backup/sync alerts) ───
require_once __DIR__ . '/backup_recovery.php';

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
    try {
        $result = build_backup($mysqli, 'auto', $BACKUP_DIR);
    } catch (BackupPassphraseRequired $e) {
        logApp('Backup', 'error', 'Auto-backup refused: ' . $e->getMessage());
        fwrite(STDERR, 'Auto-backup refused: ' . $e->getMessage() . "\n");
        exit(1);
    }
    prune_backups($BACKUP_DIR, $config_backup_retain_count);
    logApp('Backup', 'info', "Auto-backup completed: {$result['name']}");
    echo "Auto-backup saved: {$result['name']}\n";
    exit;
}
