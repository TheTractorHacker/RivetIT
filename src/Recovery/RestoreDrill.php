<?php

namespace ITFlow\Recovery;

use ITFlow\Training\Core\LedgerVerifier;

/**
 * Restore drill: proves, on a schedule, that the newest backup can actually be restored.
 *
 * It picks the newest backup archive, restores db.sql into a scratch database drill_<yyyymmdd> using a DEDICATED database account
 * that only has rights on drill_% (never the application's own account), runs the checks in DrillVerifier, ALWAYS drops the
 * scratch database and removes every temp file, and records the result (including the measured restore time, which is the RTO
 * evidence) in restore_drill_log.
 *
 * SAFETY, by construction:
 *  - The application is never booted against the scratch database. This class talks to it with plain mysqli SELECTs and the
 *    mysql client importing db.sql; no cron job, integration sync, mail or webhook code is pointed at it.
 *  - It refuses to run with the application's own database user, and the scratch database name must match drill_<digits>[_suffix].
 *  - Archives are read entry by entry by exact name; nothing is extracted by path from the archive.
 *  - A named database lock prevents two drills overlapping; an interrupted run is marked on the next start.
 */
final class RestoreDrill
{
    public const SCRATCH_RE = '/^drill_\d{8}(?:_[a-z0-9]{3,12})?$/';
    private const ZIP_ENTRIES = ['db.sql', 'uploads.zip', 'version.txt', 'table-snapshot.json'];

    private ?\mysqli $d = null;
    private string $scratch = '';
    private string $work = '';
    private bool $scratchCreated = false;
    private bool $lock = false;

    /**
     * @param array<string,mixed> $opts trigger(cron|manual|script), backup_dir, backup_path, extracted_dir, source_name, force (run even when disabled),
     *        host/user/pass/port/socket (override configuration; tests), live_user (application's own DB user), mysql_bin, import_timeout,
     *        decrypt (callable), work_dir, uploads_sample, ledger_budget
     */
    public function __construct(private \mysqli $live, private string $appRoot, private array $opts = [])
    {
    }

    /** Copy-ready setup for the scoped drill account. */
    public static function setupSteps(string $user = 'rivet_drill', string $pass = '<a long random password>'): string
    {
        return "-- Run once as a MariaDB/MySQL administrator (root). The account can create, fill and drop ONLY databases named drill_*;\n"
            . "-- it has no rights on the RivetIT database, so a mistake in the drill cannot touch live data.\n"
            . "CREATE USER '$user'@'localhost' IDENTIFIED BY '$pass';\n"
            . "GRANT ALL PRIVILEGES ON `drill\\_%`.* TO '$user'@'localhost';\n"
            . "FLUSH PRIVILEGES;\n"
            . "-- Then: Admin > Backup > Restore drill: enter the user and password, tick Enable, and press Run drill now.";
    }

    /** @return array{user:string,pass:string,host:string,port:?int,socket:?string} */
    private function credentials(): array
    {
        $o = $this->opts;
        $g = $GLOBALS;
        $host = (string) ($o['host'] ?? ($g['config_drill_db_host'] ?? '') ?: RecoverySettings::get($this->live, 'drill_db_host'));
        if ($host === '') {
            $host = (string) ($g['dbhost'] ?? 'localhost');
        }
        $user = (string) ($o['user'] ?? ($g['config_drill_db_user'] ?? '') ?: RecoverySettings::get($this->live, 'drill_db_user'));
        $pass = (string) ($o['pass'] ?? ($g['config_drill_db_pass'] ?? '') ?: RecoverySettings::drillPassword($this->live));
        $port = null;
        if (preg_match('/^(.*):(\d{1,5})$/', $host, $m)) {
            [$host, $port] = [$m[1], (int) $m[2]];
        }
        $port = $o['port'] ?? $port;

        return ['user' => $user, 'pass' => $pass, 'host' => $host, 'port' => $port, 'socket' => $o['socket'] ?? ($g['config_drill_db_socket'] ?? null)];
    }

    private function mysqlBin(): ?string
    {
        foreach ([$this->opts['mysql_bin'] ?? null, '/usr/bin/mariadb', '/usr/bin/mysql', '/usr/local/bin/mysql'] as $b) {
            if ($b && is_executable($b)) {
                return $b;
            }
        }

        return null;
    }

    private function connect(): \mysqli
    {
        $c = $this->credentials();
        mysqli_report(MYSQLI_REPORT_OFF);
        $m = mysqli_init();
        $m->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10);
        if (!@$m->real_connect($c['host'], $c['user'], $c['pass'], null, $c['port'] ?: 3306, $c['socket'] ?: null)) {
            throw new \RuntimeException('cannot connect with the drill account: ' . mysqli_connect_error());
        }
        $m->set_charset('utf8mb4');

        return $m;
    }

    /**
     * Is the drill usable? Cheap: connects with the drill account and checks the mysql client.
     *
     * @return array{configured:bool,enabled:bool,problems:list<string>}
     */
    public function configuration(): array
    {
        $problems = [];
        $c = $this->credentials();
        $liveUser = (string) ($this->opts['live_user'] ?? ($GLOBALS['dbusername'] ?? ''));
        if ($c['user'] === '') {
            $problems[] = 'No drill database account is configured.';
        } elseif ($liveUser !== '' && $c['user'] === $liveUser) {
            $problems[] = 'The drill account must not be the application\'s own database account.';
        } else {
            try {
                $probe = $this->connect();
                $name = 'drill_' . date('Ymd') . '_probe';
                if (!$probe->query("CREATE DATABASE IF NOT EXISTS `$name`")) {
                    $problems[] = 'The drill account cannot create drill_* databases (' . $probe->error . ').';
                } else {
                    $probe->query("DROP DATABASE `$name`");
                }
                $probe->close();
            } catch (\Throwable $e) {
                $problems[] = ucfirst($e->getMessage()) . '.';
            }
        }
        if ($this->mysqlBin() === null) {
            $problems[] = 'The mysql / mariadb command-line client is not installed (needed to import the dump).';
        }

        return ['configured' => $problems === [], 'enabled' => RecoverySettings::get($this->live, 'drill_enabled') === '1', 'problems' => $problems];
    }

    /** Start a detached CLI drill (the "Run drill now" button). Returns false if the CLI could not be started. */
    public static function spawn(string $appRoot, string $trigger = 'manual'): bool
    {
        $php = null;
        foreach ([PHP_BINDIR . '/php', '/usr/bin/php', '/usr/local/bin/php'] as $p) {
            if (is_executable($p)) {
                $php = $p;
                break;
            }
        }
        if ($php === null || !function_exists('proc_open')) {
            return false;
        }
        $cmd = [$php, $appRoot . '/cron/restore_drill.php', '--trigger=' . $trigger, '--force'];
        $proc = @proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $appRoot . '/cron');
        if (!is_resource($proc)) {
            return false;
        }
        // Detach: the drill finishes (and cleans up) on its own; the page only needs it started.
        return true;
    }

    // ------------------------------------------------------------------------------------------------------------------------

    /** @return array<string,mixed> the result row: status, message, checks, restore_seconds, total_seconds, backup_file, ... */
    public function run(): array
    {
        $t0 = microtime(true);
        @set_time_limit(0);
        $trigger = (string) ($this->opts['trigger'] ?? 'cron');
        $result = ['status' => 'error', 'message' => '', 'checks' => [], 'restore_seconds' => null, 'total_seconds' => null, 'backup_file' => null,
            'backup_kind' => null, 'scratch_db' => null, 'cleanup_ok' => null, 'meta' => []];

        if (!$this->acquireLock()) {
            return $result + ['busy' => true, 'message' => 'another restore drill is already running'];
        }
        $logId = $this->openLog($trigger);
        try {
            if (!empty($this->opts['precheck_failure'])) {
                // deploy/restore_drill.sh found the archive unusable before the restore even started (checksum mismatch, cannot decrypt,
                // not a tar). That IS the drill result: this backup cannot be restored.
                $result['status'] = 'fail';
                $result['backup_kind'] = 'enc';
                $result['backup_file'] = basename((string) ($this->opts['source_name'] ?? 'backup.enc'));
                $result['message'] = substr((string) $this->opts['precheck_failure'], 0, 480);
                $result['checks'] = [DrillVerifier::check('archive', 'Archive integrity', DrillVerifier::FAIL, (string) $this->opts['precheck_failure'])];

                return $this->finish($logId, $result, $t0);
            }
            $cfg = $this->configuration();
            if (!$cfg['configured']) {
                $result['status'] = 'not_configured';
                $result['message'] = implode(' ', $cfg['problems']);
                $result['checks'] = [DrillVerifier::check('setup', 'Drill account', DrillVerifier::SKIP, $result['message'] . ' Setup steps are on Admin > Backup.')];

                return $this->finish($logId, $result, $t0);
            }
            $this->execute($result);
        } catch (\Throwable $e) {
            $result['status'] = 'error';
            $result['message'] = substr($e->getMessage(), 0, 480);
            $result['checks'][] = DrillVerifier::check('run', 'Drill run', DrillVerifier::FAIL, $e->getMessage());
        } finally {
            $result['cleanup_ok'] = $this->cleanup();
            $result['scratch_db'] = $this->scratch ?: null;
        }
        if ($result['cleanup_ok'] === false) {
            $result['checks'][] = DrillVerifier::check('cleanup', 'Scratch cleanup', DrillVerifier::FAIL, 'the scratch database or temp files could not be removed - remove ' . $this->scratch . ' by hand');
            $result['status'] = 'fail';
        }

        return $this->finish($logId, $result, $t0);
    }

    private function execute(array &$result): void
    {
        $o = $this->opts;
        $this->work = $this->makeWorkDir();
        $source = $this->resolveSource($result);

        // 1. Read the archive into the work dir (db.sql always; uploads.zip when there is room), hashing as we copy.
        $t1 = microtime(true);
        $facts = $source['kind'] === 'zip' ? $this->readZip($source['path'], $result) : $this->readDir($source['dir'], $result);
        $sqlFile = $facts['sql_file'];

        // 2. Scratch database + import. The restore stopwatch covers extraction plus import: that is the RTO figure.
        $this->d = $this->connect();
        $this->scratch = 'drill_' . date('Ymd') . ($source['kind'] === 'dir' ? '_enc' : '');
        if (!preg_match(self::SCRATCH_RE, $this->scratch)) {
            throw new \RuntimeException('refusing an unsafe scratch database name');
        }
        $this->sweepOldScratch();
        $this->d->query("DROP DATABASE IF EXISTS `{$this->scratch}`");
        if (!$this->d->query("CREATE DATABASE `{$this->scratch}` DEFAULT CHARACTER SET utf8mb4")) {
            throw new \RuntimeException('cannot create the scratch database: ' . $this->d->error);
        }
        $this->scratchCreated = true;
        $this->d->select_db($this->scratch);
        $this->import($sqlFile);
        $restoreSeconds = round(microtime(true) - $t1, 2);
        $result['restore_seconds'] = $restoreSeconds;

        // 3. Verify.
        $checks = [];
        $checks[] = DrillVerifier::check('archive', 'Archive readable', DrillVerifier::PASS, $facts['archive_note']);
        foreach (DrillVerifier::checkChecksums($facts['parsed'], $facts['sha_db'], $facts['sha_uploads']) as $c) {
            $checks[] = $c;
        }
        $restoredVersion = $this->scalar("SELECT config_current_database_version FROM settings ORDER BY company_id LIMIT 1");
        $liveVersion = $this->liveScalar("SELECT config_current_database_version FROM settings WHERE company_id = 1");
        $checks[] = DrillVerifier::checkSchemaVersion($facts['parsed']['db_version'], $restoredVersion, $liveVersion);

        $restoredTables = $this->tableList($this->d);
        $liveTables = $this->tableList($this->live);
        $checks[] = DrillVerifier::checkTables($restoredTables, $liveTables, $restoredVersion !== null && $restoredVersion === $liveVersion);

        $tol = (float) (RecoverySettings::get($this->live, 'drill_tolerance_pct') ?: 5);
        $expected = is_array($facts['snapshot']['core_counts'] ?? null) ? array_map('intval', $facts['snapshot']['core_counts']) : [];
        $actual = [];
        foreach (array_keys($expected) as $t) {
            if (in_array($t, $restoredTables, true) && preg_match('/^[A-Za-z0-9_]+$/', $t)) {
                $actual[$t] = (int) $this->scalar("SELECT COUNT(*) FROM `$t`");
            }
        }
        $rc = DrillVerifier::checkRowCounts($expected, $actual, $tol);
        if ($expected === [] && $source['kind'] === 'zip') {
            $rc['detail'] = 'this backup predates table snapshots; the next backup will carry one';
        }
        $checks[] = $rc;

        $checks[] = $this->ledgerCheck($facts['parsed'], $restoredTables);
        $checks[] = $this->secretCheck($restoredTables);
        $checks[] = $facts['uploads_check'];

        $result['checks'] = $checks;
        $result['status'] = DrillVerifier::overall($checks);
        $result['message'] = DrillVerifier::summary($checks);
        $result['meta'] = ['backup_generated' => $facts['parsed']['generated'], 'db_sql_bytes' => $facts['sql_bytes'], 'tables_restored' => count($restoredTables),
            'backup_age_hours' => $source['mtime'] ? round((time() - $source['mtime']) / 3600, 1) : null, 'tolerance_pct' => $tol];
    }

    // -- source ---------------------------------------------------------------------------------------------------------

    /** @return array{kind:string,path?:string,dir?:string,mtime:?int} */
    private function resolveSource(array &$result): array
    {
        $o = $this->opts;
        if (!empty($o['extracted_dir'])) {
            $dir = rtrim((string) $o['extracted_dir'], '/');
            if (!is_dir($dir)) {
                throw new \RuntimeException('the extracted archive folder does not exist');
            }
            $result['backup_kind'] = 'enc';
            $result['backup_file'] = basename((string) ($o['source_name'] ?? 'backup.enc'));

            return ['kind' => 'dir', 'dir' => $dir, 'mtime' => isset($o['source_mtime']) ? (int) $o['source_mtime'] : null];
        }
        $path = $o['backup_path'] ?? null;
        if ($path === null) {
            $dir = (string) ($o['backup_dir'] ?? $this->appRoot . '/backups');
            $files = glob($dir . '/itflow_*.zip') ?: [];
            usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));
            $path = $files[0] ?? null;
        }
        if ($path === null || !is_file($path)) {
            throw new \RuntimeException('there is no backup archive to restore');
        }
        $result['backup_kind'] = 'zip';
        $result['backup_file'] = basename($path);

        return ['kind' => 'zip', 'path' => $path, 'mtime' => (int) filemtime($path)];
    }

    /** @return array<string,mixed> */
    private function readZip(string $path, array &$result): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('the newest backup is not a readable zip archive: ' . basename($path));
        }
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[$zip->getNameIndex($i)] = $i;
        }
        foreach (['db.sql', 'version.txt'] as $req) {
            if (!isset($names[$req])) {
                $zip->close();
                throw new \RuntimeException("the backup is missing $req");
            }
        }
        $sqlFile = $this->work . '/db.sql';
        [$shaDb, $sqlBytes] = $this->copyEntry($zip, 'db.sql', $sqlFile);
        $version = (string) $zip->getFromName('version.txt');
        $parsed = DrillVerifier::parseVersionTxt($version);
        $snapshot = isset($names[TableSnapshot::ENTRY]) ? json_decode((string) $zip->getFromName(TableSnapshot::ENTRY), true) : null;
        $note = 'opened ' . basename($path) . ' (' . count($names) . ' entries)';
        $hasManifest = isset($names['backup-manifest.json']) || isset($names['backup-manifest.json.enc']);
        if (!$hasManifest) {
            $note .= '; no key manifest inside';
        }

        // uploads.zip: hash it as a stream; copy it to disk only when there is room, to CRC-sample its entries.
        $shaUp = null;
        $uploadsCheck = DrillVerifier::check('uploads', 'Uploads archive', DrillVerifier::FAIL, 'uploads.zip is missing from the backup');
        if (isset($names['uploads.zip'])) {
            $st = $zip->statIndex($names['uploads.zip']);
            $size = (int) ($st['size'] ?? 0);
            $free = @disk_free_space($this->work);
            if ($free !== false && $free > $size * 1.3 + 50_000_000) {
                $upFile = $this->work . '/uploads.zip';
                [$shaUp] = $this->copyEntry($zip, 'uploads.zip', $upFile);
                $uploadsCheck = $this->uploadsCheck($upFile);
                @unlink($upFile);
            } else {
                $shaUp = $this->hashEntry($zip, 'uploads.zip');
                $uploadsCheck = DrillVerifier::check('uploads', 'Uploads archive', DrillVerifier::WARN, 'checksum verified but not enough free disk to open uploads.zip for a sample check');
            }
        }
        $zip->close();

        return ['sql_file' => $sqlFile, 'sql_bytes' => $sqlBytes, 'sha_db' => $shaDb, 'sha_uploads' => $shaUp, 'parsed' => $parsed,
            'snapshot' => is_array($snapshot) ? $snapshot : [], 'archive_note' => $note, 'uploads_check' => $uploadsCheck];
    }

    /** deploy/backup.sh archive, already decrypted and unpacked by deploy/restore_drill.sh: backup-*.sql, optional table-snapshot.json, uploads/. */
    private function readDir(string $dir, array &$result): array
    {
        $sqls = glob($dir . '/backup-*.sql') ?: [];
        if (count($sqls) !== 1) {
            throw new \RuntimeException('the unpacked archive does not contain exactly one backup-*.sql');
        }
        $sqlFile = $sqls[0];
        $sha = hash_file('sha256', $sqlFile) ?: null;
        $snapshot = is_file($dir . '/' . TableSnapshot::ENTRY) ? json_decode((string) file_get_contents($dir . '/' . TableSnapshot::ENTRY), true) : null;
        $upDir = $dir . '/uploads';
        $count = 0;
        $sample = [];
        if (is_dir($upDir)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($upDir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile() && !$f->isLink()) {
                    $count++;
                    if (count($sample) < 5) {
                        $sample[] = $f->getPathname();
                    }
                }
            }
        }
        $bad = 0;
        foreach ($sample as $f) {
            if (@hash_file('sha256', $f) === false) {
                $bad++;
            }
        }
        $parsed = DrillVerifier::parseVersionTxt('');
        $parsed['sha_db'] = null;   // the .enc is checked against its .sha256 file by deploy/restore_drill.sh before decrypting

        return ['sql_file' => $sqlFile, 'sql_bytes' => (int) filesize($sqlFile), 'sha_db' => $sha, 'sha_uploads' => null, 'parsed' => $parsed,
            'snapshot' => is_array($snapshot) ? $snapshot : [], 'archive_note' => 'decrypted and unpacked ' . ($result['backup_file'] ?? 'archive'),
            'uploads_check' => DrillVerifier::checkUploads($count, count($sample), $bad, $this->liveHasUploads())];
    }

    private function uploadsCheck(string $upFile): array
    {
        $z = new \ZipArchive();
        if ($z->open($upFile) !== true) {
            return DrillVerifier::checkUploads(null, 0, 0, false);
        }
        $n = $z->numFiles;
        $want = (int) ($this->opts['uploads_sample'] ?? 8);
        $idx = [];
        if ($n > 0) {
            // Deterministic spread (first, last and evenly between) so repeated drills sample the same shape of files.
            for ($k = 0; $k < min($want, $n); $k++) {
                $idx[] = (int) floor($k * ($n - 1) / max(1, min($want, $n) - 1));
            }
            $idx = array_values(array_unique($idx));
        }
        $bad = 0;
        foreach ($idx as $i) {
            $st = $z->statIndex($i);
            $stream = $z->getStream($st['name']);
            if (!$stream) {
                $bad++;
                continue;
            }
            $h = hash_init('crc32b');
            while (!feof($stream)) {
                hash_update($h, (string) fread($stream, 1 << 20));
            }
            fclose($stream);
            if (hexdec(hash_final($h)) !== (int) $st['crc']) {
                $bad++;
            }
        }
        $z->close();

        return DrillVerifier::checkUploads($n, count($idx), $bad, $this->liveHasUploads());
    }

    private function liveHasUploads(): bool
    {
        $dir = $this->appRoot . '/uploads';
        if (!is_dir($dir)) {
            return false;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile()) {
                return true;
            }
        }

        return false;
    }

    /** Streams one zip entry to $dest (0600), returning [sha256, bytes]. */
    private function copyEntry(\ZipArchive $zip, string $name, string $dest): array
    {
        $in = $zip->getStream($name);
        if (!$in) {
            throw new \RuntimeException("cannot read $name from the backup");
        }
        $out = fopen($dest, 'wb');
        @chmod($dest, 0600);
        $h = hash_init('sha256');
        $bytes = 0;
        while (!feof($in)) {
            $buf = (string) fread($in, 1 << 20);
            if ($buf === '') {
                continue;
            }
            hash_update($h, $buf);
            $bytes += strlen($buf);
            if (fwrite($out, $buf) === false) {
                throw new \RuntimeException("disk full while extracting $name");
            }
        }
        fclose($in);
        fclose($out);

        return [hash_final($h), $bytes];
    }

    private function hashEntry(\ZipArchive $zip, string $name): ?string
    {
        $in = $zip->getStream($name);
        if (!$in) {
            return null;
        }
        $h = hash_init('sha256');
        while (!feof($in)) {
            hash_update($h, (string) fread($in, 1 << 20));
        }
        fclose($in);

        return hash_final($h);
    }

    // -- import / scratch --------------------------------------------------------------------------------------------------

    private function import(string $sqlFile): void
    {
        $bin = $this->mysqlBin();
        $c = $this->credentials();
        $esc = fn (string $v) => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
        $cnf = $this->work . '/client.cnf';
        $lines = ['[client]', 'user=' . $esc($c['user']), 'password=' . $esc($c['pass'])];
        if ($c['socket']) {
            $lines[] = 'socket=' . $esc((string) $c['socket']);
        } else {
            $lines[] = 'host=' . $esc($c['host']);
            if ($c['port']) {
                $lines[] = 'port=' . (int) $c['port'];
            }
        }
        file_put_contents($cnf, implode("\n", $lines) . "\n");
        @chmod($cnf, 0600);
        $errFile = $this->work . '/import.err';
        $cmd = [$bin, '--defaults-extra-file=' . $cnf, '--default-character-set=utf8mb4', '--database=' . $this->scratch];
        $proc = proc_open($cmd, [0 => ['file', $sqlFile, 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $errFile, 'w']], $pipes);
        if (!is_resource($proc)) {
            throw new \RuntimeException('cannot start the mysql client');
        }
        $deadline = microtime(true) + (int) ($this->opts['import_timeout'] ?? 3600);
        while (true) {
            $st = proc_get_status($proc);
            if (!$st['running']) {
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($proc, 9);
                proc_close($proc);
                throw new \RuntimeException('the import did not finish within the time limit');
            }
            usleep(100_000);
        }
        $exit = $st['exitcode'];
        proc_close($proc);
        @unlink($cnf);
        if ($exit !== 0) {
            $err = trim((string) @file_get_contents($errFile));
            throw new \RuntimeException('importing db.sql failed: ' . substr(preg_replace('/\s+/', ' ', $err), 0, 300));
        }
    }

    /** Drops leftover drill_* databases from earlier days (an interrupted run), but never today's name or anything not matching the pattern. */
    private function sweepOldScratch(): void
    {
        $res = $this->d->query("SHOW DATABASES LIKE 'drill\\_%'");
        while ($res && ($row = $res->fetch_row())) {
            $name = $row[0];
            if (preg_match(self::SCRATCH_RE, $name) && $name !== $this->scratch && !str_ends_with($name, '_probe')) {
                $this->d->query("DROP DATABASE `$name`");
            }
        }
    }

    // -- checks needing the scratch database -----------------------------------------------------------------------------

    private function ledgerCheck(array $parsed, array $restoredTables): array
    {
        if (!in_array('training_ledger_head', $restoredTables, true)) {
            return DrillVerifier::check('ledger', 'Training ledger chain', DrillVerifier::SKIP, 'no training ledger in this database');
        }
        $anchor = $parsed['ledger_seq'] !== null ? ['seq' => $parsed['ledger_seq'], 'hash' => $parsed['ledger_hash']] : null;
        try {
            $row = $this->d->query('SELECT lhead_last_seq, lhead_last_hash FROM training_ledger_head WHERE lhead_id = 1')->fetch_assoc();
            $head = $row ? ['seq' => (int) $row['lhead_last_seq'], 'hash' => (string) $row['lhead_last_hash']] : null;
            $walk = null;
            if (class_exists(LedgerVerifier::class) && in_array('training_events', $restoredTables, true)) {
                $walk = LedgerVerifier::verify($this->d, ['time_budget_s' => (int) ($this->opts['ledger_budget'] ?? 120), 'max_breaks' => 5, 'media_root' => $this->work . '/none']);
            }

            return DrillVerifier::checkLedger($anchor, $head, $walk);
        } catch (\Throwable $e) {
            return DrillVerifier::check('ledger', 'Training ledger chain', DrillVerifier::WARN, 'the chain check could not run: ' . substr($e->getMessage(), 0, 200));
        }
    }

    private function secretCheck(array $restoredTables): array
    {
        $decrypt = $this->opts['decrypt'] ?? (function_exists('decryptSetting') ? 'decryptSetting' : null);
        if ($decrypt === null || !in_array('settings', $restoredTables, true)) {
            return DrillVerifier::check('secret', 'Sample secret decrypts', DrillVerifier::SKIP, 'no decryption routine is available to this run');
        }
        $row = $this->d->query('SELECT * FROM settings ORDER BY company_id LIMIT 1')?->fetch_assoc() ?: [];
        foreach ($row as $col => $val) {
            if (is_string($val) && (str_starts_with($val, 'ENC2:') || str_starts_with($val, 'ENC:'))) {
                return DrillVerifier::checkSecret(true, (string) $decrypt($val), (string) $col);
            }
        }

        return DrillVerifier::checkSecret(false, null);
    }

    // -- small helpers -------------------------------------------------------------------------------------------------------

    private function scalar(string $sql): ?string
    {
        $r = $this->d ? @$this->d->query($sql) : false;
        $row = $r ? $r->fetch_row() : null;

        return $row && $row[0] !== null ? (string) $row[0] : null;
    }

    private function liveScalar(string $sql): ?string
    {
        $r = @$this->live->query($sql);
        $row = $r ? $r->fetch_row() : null;

        return $row && $row[0] !== null ? (string) $row[0] : null;
    }

    /** @return list<string> */
    private function tableList(\mysqli $db): array
    {
        $out = [];
        $r = $db->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        while ($r && ($row = $r->fetch_row())) {
            $out[] = $row[0];
        }

        return $out;
    }

    private function makeWorkDir(): string
    {
        $base = (string) ($this->opts['work_dir'] ?? sys_get_temp_dir());
        $dir = $base . '/rivetit-drill-' . bin2hex(random_bytes(6));
        if (!mkdir($dir, 0700, true)) {
            throw new \RuntimeException('cannot create a private temp folder');
        }

        return $dir;
    }

    /** Always runs. Returns true when nothing was left behind. */
    private function cleanup(): bool
    {
        $ok = true;
        if ($this->d instanceof \mysqli && $this->scratchCreated && preg_match(self::SCRATCH_RE, $this->scratch)) {
            @$this->d->query("DROP DATABASE IF EXISTS `{$this->scratch}`");
            $r = @$this->d->query("SHOW DATABASES LIKE '" . $this->d->real_escape_string($this->scratch) . "'");
            if ($r && $r->num_rows > 0) {
                $ok = false;
            }
        }
        if ($this->d instanceof \mysqli) {
            @$this->d->close();
            $this->d = null;
        }
        if ($this->work !== '' && is_dir($this->work)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->work, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->work);
            if (is_dir($this->work)) {
                $ok = false;
            }
        }
        $this->scratchCreated = false;
        $this->releaseLock();

        return $ok;
    }

    /**
     * One drill at a time across every user and process (the www-data nightly job, the root script, the Run-now button): a named
     * lock on the application's database connection, released when the drill ends or the connection drops.
     */
    private function acquireLock(): bool
    {
        $r = @$this->live->query("SELECT GET_LOCK('rivetit_restore_drill', 0)");
        $row = $r ? $r->fetch_row() : null;
        $this->lock = $row && (int) $row[0] === 1;

        return $this->lock;
    }

    private function releaseLock(): void
    {
        if ($this->lock) {
            @$this->live->query("DO RELEASE_LOCK('rivetit_restore_drill')");
            $this->lock = false;
        }
    }

    // -- log ---------------------------------------------------------------------------------------------------------------

    private function openLog(string $trigger): ?int
    {
        $r = @mysqli_query($this->live, "SHOW TABLES LIKE 'restore_drill_log'");
        if (!$r || mysqli_num_rows($r) === 0) {
            return null;
        }
        // A run that never finished (killed, host rebooted) is closed so it cannot look "running" forever.
        @mysqli_query($this->live, "UPDATE restore_drill_log SET drill_status = 'error', drill_finished_at = NOW(), drill_message = 'interrupted before it finished'
                                    WHERE drill_status = 'running' AND drill_started_at < NOW() - INTERVAL 3 HOUR");
        $t = mysqli_real_escape_string($this->live, substr($trigger, 0, 20));
        @mysqli_query($this->live, "INSERT INTO restore_drill_log (drill_started_at, drill_trigger, drill_status) VALUES (NOW(), '$t', 'running')");

        return (int) mysqli_insert_id($this->live) ?: null;
    }

    /** @param array<string,mixed> $result */
    private function finish(?int $logId, array $result, float $t0): array
    {
        $result['total_seconds'] = round(microtime(true) - $t0, 2);
        if ($logId !== null) {
            $e = fn ($v) => $v === null ? 'NULL' : "'" . mysqli_real_escape_string($this->live, (string) $v) . "'";
            $n = fn ($v) => $v === null ? 'NULL' : (string) (float) $v;
            $json = json_encode(['checks' => $result['checks'], 'meta' => $result['meta']], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            @mysqli_query($this->live, 'UPDATE restore_drill_log SET drill_finished_at = NOW(), drill_status = ' . $e($result['status'])
                . ', drill_message = ' . $e(substr((string) $result['message'], 0, 500)) . ', drill_backup_kind = ' . $e($result['backup_kind'])
                . ', drill_backup_file = ' . $e($result['backup_file']) . ', drill_restore_seconds = ' . $n($result['restore_seconds'])
                . ', drill_total_seconds = ' . $n($result['total_seconds']) . ', drill_scratch_db = ' . $e($result['scratch_db'])
                . ', drill_cleanup_ok = ' . ($result['cleanup_ok'] === null ? 'NULL' : ($result['cleanup_ok'] ? '1' : '0'))
                . ', drill_checks = ' . $e($json) . ' WHERE drill_id = ' . (int) $logId);
        }
        $this->releaseLock();
        $this->alert($result);

        return $result;
    }

    private function alert(array $result): void
    {
        if ($result['status'] === 'not_configured') {
            // Only an alert when the admin switched the drill on: "off and not set up yet" is the expected default.
            if (RecoverySettings::get($this->live, 'drill_enabled') === '1') {
                RecoveryAlerts::raise($this->live, 'restore_drill.notconfigured', 'Restore drill is enabled but cannot run: ' . substr((string) $result['message'], 0, 160),
                    "The restore drill is switched on but its database account does not work.\n\n" . $result['message'] . "\n\nSetup steps are on Admin > Backup > Restore drill.",
                    'restore_drill.failed', ['status' => 'not_configured', 'message' => $result['message']]);
            }

            return;
        }
        $bad = in_array($result['status'], ['fail', 'error'], true);
        if (!$bad) {
            if ($result['status'] === 'pass' || $result['status'] === 'warn') {
                RecoveryAlerts::clear($this->live, 'restore_drill.failed');
            }

            return;
        }
        $failed = array_filter($result['checks'], fn ($c) => $c['status'] === DrillVerifier::FAIL);
        $lines = array_map(fn ($c) => '- ' . $c['label'] . ': ' . $c['detail'], $failed);
        RecoveryAlerts::raise($this->live, 'restore_drill.failed', 'Restore drill failed: ' . substr((string) $result['message'], 0, 160),
            "The nightly restore drill could not prove the newest backup restores.\n\nBackup: " . ($result['backup_file'] ?? 'none') . "\n" . implode("\n", $lines)
            . "\n\nA backup that does not restore is not a backup. Open Admin > Backup > Restore drill for the full result.",
            'restore_drill.failed', ['status' => $result['status'], 'backup_file' => $result['backup_file'], 'message' => $result['message']]);
    }
}
