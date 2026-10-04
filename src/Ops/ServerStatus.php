<?php

namespace ITFlow\Ops;

/**
 * One-screen health of the server this app runs on, plus copy-ready commands for the few tasks that need
 * root and so cannot be done from a web page. Read-only: it never changes anything. Every probe is wrapped
 * so one broken dependency becomes one WARN/FAIL line instead of a broken page.
 */
final class ServerStatus
{
    public function __construct(
        private \mysqli $db,
        private string $appRoot,
        private ?object $redis = null,
        private string $cronHelper = '/usr/local/sbin/rivetit-cron-schedule',
        private ?int $now = null,
        private string $cronConfigDir = '/etc/rivetit',
    ) {
        $this->appRoot = realpath($appRoot) ?: rtrim($appRoot, '/');
        $this->now ??= time();
    }

    private static function c(string $status, string $label, string $detail): array
    {
        return ['status' => $status, 'label' => $label, 'detail' => $detail];
    }

    private function ago(int $ts): string
    {
        return $this->agoSeconds($this->now - $ts);
    }

    private function agoSeconds(int $d): string
    {
        $d = max(0, $d);
        return $d < 5400 ? max(1, (int) round($d / 60)) . ' min ago' : ($d < 172800 ? (int) round($d / 3600) . ' h ago' : (int) round($d / 86400) . ' days ago');
    }

    private static function bytes(float $b): string
    {
        foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $u) { if ($b < 1024 || $u === 'TB') return round($b, $u === 'B' ? 0 : 1) . " $u"; $b /= 1024; }
        return '';
    }

    /** @return list<array{title:string, checks:list<array{status:string,label:string,detail:string}>}> */
    public function run(): array
    {
        return [
            ['title' => 'Application and database', 'checks' => $this->app()],
            ['title' => 'Storage and backups', 'checks' => $this->storage()],
            ['title' => 'Scheduled work', 'checks' => $this->scheduled()],
            ['title' => 'Redis', 'checks' => $this->redisChecks()],
        ];
    }

    private function app(): array
    {
        $out = [];
        $current = null;
        try {
            $r = $this->db->query('SELECT config_current_database_version AS v FROM settings WHERE company_id = 1');
            $current = $r ? ($r->fetch_assoc()['v'] ?? null) : null;
        } catch (\Throwable) {}
        $latest = defined('LATEST_DATABASE_VERSION') ? LATEST_DATABASE_VERSION : null;
        if ($current === null || $latest === null) {
            $out[] = self::c('warn', 'Database version', 'Could not read the database version.');
        } elseif (version_compare($latest, $current, '>')) {
            $out[] = self::c('warn', 'Database version', "Database is at $current but this code needs $latest. Open Administration → Update and press Update database.");
        } else {
            $out[] = self::c('ok', 'Database version', "Up to date ($current).");
        }
        $out[] = self::c('ok', 'Application version', defined('APP_VERSION') ? (string) APP_VERSION : 'unknown');
        $missing = array_values(array_filter(['mysqli', 'curl', 'mbstring', 'gd', 'zip', 'openssl', 'json'], static fn($e) => !extension_loaded($e)));
        $out[] = $missing ? self::c('fail', 'PHP extensions', 'Missing: ' . implode(', ', $missing) . '.')
            : self::c('ok', 'PHP extensions', 'PHP ' . PHP_VERSION . ' with every required extension.');
        return $out;
    }

    private function storage(): array
    {
        $out = [];
        $free = @disk_free_space($this->appRoot);
        $total = @disk_total_space($this->appRoot);
        if ($free !== false && $total) {
            $pct = $free / $total * 100;
            $detail = self::bytes($free) . ' free of ' . self::bytes($total) . ' (' . round($pct) . '%).';
            $out[] = ($pct < 5 || $free < 2 * 1073741824) ? self::c('fail', 'Disk space', $detail . ' Free some space before backups fail.')
                : (($pct < 15 || $free < 5 * 1073741824) ? self::c('warn', 'Disk space', $detail) : self::c('ok', 'Disk space', $detail));
        }
        foreach (['uploads', 'backups'] as $dir) {
            $path = "{$this->appRoot}/$dir";
            $out[] = is_dir($path) && is_writable($path) ? self::c('ok', "$dir/ writable", 'The web server can write here.')
                : self::c('fail', "$dir/ writable", "The web server cannot write to $dir/.");
        }

        $zips = glob("{$this->appRoot}/backups/itflow_*.zip") ?: [];
        if (!$zips) {
            $out[] = self::c('warn', 'In-app backups', 'No backup zip found. Create one in Administration → Backups, or turn on scheduled backups.');
        } else {
            $newest = max(array_map('filemtime', $zips));
            $age = $this->now - $newest;
            $d = count($zips) . ' zip(s); newest ' . $this->ago($newest) . '.';
            $out[] = $age > 7 * 86400 ? self::c('fail', 'In-app backups', $d . ' Backups have stopped; check Scheduled Backups.')
                : ($age > 36 * 3600 ? self::c('warn', 'In-app backups', $d) : self::c('ok', 'In-app backups', $d));
        }

        $enc = glob("{$this->appRoot}/backups/backup-*.tar.gz.enc") ?: [];
        if (!$enc) {
            $out[] = self::c('warn', 'Encrypted disaster-recovery backups', 'None yet. Run the encrypted backup (see Server tasks below); it can rebuild this server from nothing.');
        } else {
            $newest = max(array_map('filemtime', $enc));
            $size = array_sum(array_map('filesize', $enc));
            $d = count($enc) . ' archive(s), ' . self::bytes($size) . '; newest ' . $this->ago($newest) . '. They are stored on this server, so copy the newest one off-site.';
            $out[] = ($this->now - $newest) > 14 * 86400 ? self::c('warn', 'Encrypted disaster-recovery backups', $d) : self::c('ok', 'Encrypted disaster-recovery backups', $d);
        }
        return $out;
    }

    private function scheduled(): array
    {
        $out = [];
        require_once $this->appRoot . '/includes/cron_jobs.php';
        $jobs = \rivetit_cron_jobs_for_app($this->appRoot);
        $out[] = $jobs ? self::c('ok', 'Scheduled jobs', count($jobs) . ' job(s) scheduled on this server. See Maintenance → Scheduled jobs.')
            : self::c('warn', 'Scheduled jobs', 'No jobs are scheduled for this install. Nothing runs automatically.');
        $instance = \rivetit_cron_manager_instance($this->appRoot, $this->cronConfigDir);
        if ($instance !== null && is_executable($this->cronHelper)) {
            $out[] = self::c('ok', 'Schedule editing', 'The Cron Manager helper is installed, so schedules can be edited in the browser.');
        } elseif ($this->cronConfigUnreadable()) {
            $out[] = self::c('warn', 'Schedule editing', "The web server cannot read {$this->cronConfigDir}, where the Cron Manager registration lives, so schedules show as view-only. The installer makes that folder world-readable; see the Server tasks below for the one-line fix.");
        } else {
            $out[] = self::c('warn', 'Schedule editing', 'The Cron Manager helper is not installed, so schedules are view-only. Installing it needs root (deploy/install.sh does it).');
        }
        try {
            $enabled = (int) ($this->db->query('SELECT config_enable_cron AS e FROM settings WHERE company_id = 1')->fetch_assoc()['e'] ?? 0) === 1;
            // The age is computed by the database so a PHP/database time-zone difference cannot skew it.
            $r = $this->db->query("SELECT TIMESTAMPDIFF(SECOND, app_log_created_at, NOW()) AS age FROM app_logs WHERE app_log_category = 'Cron' AND app_log_details = 'Cron executed successfully' ORDER BY app_log_id DESC LIMIT 1");
            $row = $r ? $r->fetch_assoc() : null;
            if (!$enabled) {
                $out[] = self::c('ok', 'Main cron', 'Switched off in Settings → Notifications' . ($row ? '; last ran ' . $this->agoSeconds((int) $row['age']) . '.' : '.') . ' Turn it on to send recurring tickets, invoices and reminders.');
            } elseif (!$row) {
                $out[] = self::c('warn', 'Main cron', 'Switched on, but no successful run has been recorded yet.');
            } else {
                $d = 'Last finished ' . $this->agoSeconds((int) $row['age']) . '.';
                $out[] = (int) $row['age'] > 3 * 3600 ? self::c('warn', 'Main cron', $d . ' It is switched on but has not run lately; check the schedule log.') : self::c('ok', 'Main cron', $d);
            }
        } catch (\Throwable) {}
        try {
            $r = $this->db->query("SELECT status, COUNT(*) AS n FROM integration_jobs GROUP BY status");
            $counts = [];
            while ($r && ($row = $r->fetch_assoc())) $counts[$row['status']] = (int) $row['n'];
            $dead = $counts['dead_letter'] ?? 0;
            $d = ($counts['pending'] ?? 0) . ' pending, ' . ($counts['running'] ?? 0) . ' running, ' . $dead . ' failed for good.';
            $out[] = $dead > 0 ? self::c('warn', 'Integration job queue', $d) : self::c('ok', 'Integration job queue', $d);
        } catch (\Throwable) {}
        return $out;
    }

    private function redisChecks(): array
    {
        if (!$this->redis) {
            return [self::c('warn', 'Redis', 'Not reachable. Live updates and rate limits are off; everything else works. See Administration → Redis.')];
        }
        $out = [];
        try {
            $this->redis->ping();
            $info = $this->redis->info();
            $mem = $info['Memory'] ?? ($info['memory'] ?? []);
            $srv = $info['Server'] ?? ($info['server'] ?? []);
            $out[] = self::c('ok', 'Redis', 'Connected (version ' . ($srv['redis_version'] ?? '?') . ', ' . ($mem['used_memory_human'] ?? '?') . ' used).');
            $max = (int) ($mem['maxmemory'] ?? 0);
            $out[] = $max > 0 ? self::c('ok', 'Redis memory limit', self::bytes($max) . ' (' . ($mem['maxmemory_policy'] ?? '?') . ').')
                : self::c('warn', 'Redis memory limit', 'No limit is set. Set maxmemory in the Redis config so it cannot grow without bound.');
        } catch (\Throwable $e) {
            $out[] = self::c('warn', 'Redis', 'Connected but a query failed (' . substr($e->getMessage(), 0, 120) . ').');
        }
        return $out;
    }

    private function cronConfigUnreadable(): bool
    {
        return is_executable($this->cronHelper) && is_dir($this->cronConfigDir) && !is_readable($this->cronConfigDir);
    }

    /** Overall counts for the page header. */
    public static function summarize(array $groups): array
    {
        $n = ['ok' => 0, 'warn' => 0, 'fail' => 0];
        foreach ($groups as $g) foreach ($g['checks'] as $c) { if (isset($n[$c['status']])) $n[$c['status']]++; }
        return $n;
    }

    /** Tasks that need root. Each has a ready-to-copy command for this install. @return list<array{title:string, why:string, command:string, caution:string}> */
    public function rootTasks(): array
    {
        $q = static fn(string $v) => preg_match('~^[A-Za-z0-9_./=-]+$~', $v) ? $v : escapeshellarg($v);
        $app = $q($this->appRoot);
        $extra = $this->cronConfigUnreadable() ? [['title' => 'Let the web server read the Cron Manager registration', 'why' => 'Schedules cannot be edited in the browser until the web server can read the folder that registers this install with the Cron Manager helper. The installer makes it world-readable; here it is root-only.',
                'command' => 'sudo chmod 755 ' . $this->cronConfigDir, 'caution' => '']] : [];
        return array_merge($extra, [
            ['title' => 'Create the backup passphrase file (once)', 'why' => 'The encrypted backup and the safe update both read their passphrase from a private file. Choose a long passphrase and store a copy somewhere safe: without it the archives cannot be opened.',
                'command' => "sudo install -m 600 /dev/null /root/rivetit-backup.pass\nsudoedit /root/rivetit-backup.pass", 'caution' => ''],
            ['title' => 'Encrypted disaster-recovery backup', 'why' => 'Database, uploads and the encryption key in one encrypted archive, written to the backups folder. Copy the newest file off this server afterwards.',
                'command' => "sudo bash $app/deploy/backup.sh --app-dir=$app --passphrase-file=/root/rivetit-backup.pass", 'caution' => ''],
            ['title' => 'Update with a backup first', 'why' => 'Takes an encrypted backup, updates the code as the owning user, refreshes dependencies and runs database updates. The Update page in the browser does the same update without the root-level backup.',
                'command' => "sudo bash $app/deploy/update.sh --app-dir=$app --passphrase-file=/root/rivetit-backup.pass", 'caution' => ''],
            ['title' => 'Preview server hardening (changes nothing)', 'why' => 'Lists every hardening step it would apply to PHP, MariaDB, nginx, the firewall and unattended upgrades.',
                'command' => "sudo bash $app/deploy/harden.sh --dry-run", 'caution' => ''],
            ['title' => 'Restore an encrypted backup', 'why' => 'Rebuilds this install from a backup archive.',
                'command' => "sudo bash $app/deploy/restore.sh --app-dir=$app --backup=$app/backups/backup-NAME.tar.gz.enc --passphrase-file=/root/rivetit-backup.pass --confirm-restore",
                'caution' => 'This REPLACES the database and uploads with the backup. It cannot be done from the browser, on purpose.'],
        ]);
    }
}
