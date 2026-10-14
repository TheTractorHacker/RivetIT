<?php

namespace ITFlow\Recovery;

/**
 * Alerts for integration syncs that stopped running or keep failing: RMM (rmm_sync_log), Intune (intune_sync_log), UniFi
 * (unifi_sync_log) and ManageEngine MDM (mdm_sync_log). Per integration the watcher is in one of five states:
 *
 *   ok       (shown as "healthy") ran recently and is not failing
 *   stale    no run in 3x its expected interval
 *   failing  its last N runs (N = setting sync_error_threshold, default 3) each ended in a REAL error
 *   dormant  its newest run is older than sync_dormant_days (default 14): treated as no longer running, no stale/failing mail
 *   muted    an administrator muted it (recovery_watch_overrides); nothing is mailed
 *
 * A "real error" is a failed run whose error text is not empty and is not the sentinel the RMM layer writes for integration
 * types that have no vendor API (rivetit_agent), so one noisy external script cannot make another integration look broken.
 * The expected interval comes from an administrator override (recovery_watch_overrides.expected_interval_s) first, then the
 * per-source setting, then (UniFi "auto") the median gap of its own scheduled runs. A problem is mailed at most once per
 * alert_repeat_hours (default 24 h) and a recovery is announced once (RecoveryAlerts).
 *
 * assess(), runErrored(), realError() and inferInterval() are pure so the rules are tested without a database; inspect() reads and
 * run() additionally raises the alerts.
 */
final class SyncWatch
{
    public const STALE_FACTOR = 3;
    private const STUCK_RUNNING_SECONDS = 3600;
    /** Substring (lower case) of the text rmm_client_factory writes for integration types that are managed outside any vendor API. */
    public const SENTINEL = 'agent devices are managed from the device page';
    /** A "no longer running" notice is only sent while the integration is this recently dormant, and at most once per this many hours. */
    public const DORMANT_NOTICE_WINDOW_DAYS = 30;
    public const DORMANT_NOTICE_REPEAT_HOURS = 720;

    /** Pure. True for error text that names a real failure: not blank, not "[]"/"null", not the no-vendor-API sentinel. */
    public static function realError(?string $text): bool
    {
        $t = strtolower(trim((string) $text));
        if ($t === '' || $t === '[]' || $t === 'null' || $t === '{}' || $t === '""') {
            return false;
        }

        return !str_contains($t, self::SENTINEL);
    }

    /**
     * Pure. A run is an error when its status says failed AND it carries real error text. A run that is merely marked failed with
     * no text (or only the sentinel) is not counted, nor is a run still "running" (the stuck-run rule needs real error text too).
     */
    public static function runErrored(array $run, int $nowTs): bool
    {
        if (!self::realError($run['error'] ?? null)) {
            return false;
        }
        $status = strtolower((string) ($run['status'] ?? ''));
        if (in_array($status, ['failed', 'error', 'errored'], true)) {
            return true;
        }

        return $status === 'running' && empty($run['finished']) && ($nowTs - (int) ($run['started'] ?? $nowTs)) > self::STUCK_RUNNING_SECONDS;
    }

    /**
     * Pure. Median gap in minutes between scheduled runs, from run start times (newest first), or null when there are too few
     * to tell. Floored at 5 minutes.
     *
     * @param list<int> $startsDesc
     */
    public static function inferInterval(array $startsDesc): ?int
    {
        $startsDesc = array_values($startsDesc);
        if (count($startsDesc) < 3) {
            return null;
        }
        $gaps = [];
        for ($i = 0; $i + 1 < count($startsDesc); $i++) {
            $gaps[] = max(0, $startsDesc[$i] - $startsDesc[$i + 1]);
        }
        sort($gaps);
        $median = $gaps[intdiv(count($gaps), 2)];

        return max(5, (int) round($median / 60));
    }

    /**
     * Pure.
     *
     * @param list<array{status:string,started:int,finished:?int,error?:?string}> $runs newest first
     * @param ?int $forgottenTs when an administrator cleared the history (runs before it are already left out of $runs)
     * @return array{state:string,detail:string,since:?int,was_healthy:bool} state: ok | stale | failing | dormant
     */
    public static function assess(array $runs, ?int $createdTs, int $nowTs, ?int $intervalMin, int $errorThreshold, int $dormantDays = 14, ?int $forgottenTs = null): array
    {
        $errorThreshold = max(1, $errorThreshold);
        $limit = ($intervalMin !== null && $intervalMin > 0) ? self::STALE_FACTOR * $intervalMin * 60 : null;
        // Dormant after the setting, but never sooner than twice the staleness limit (a monthly job must be allowed to be quiet for a month).
        $dormantLimit = $dormantDays > 0 ? max($dormantDays * 86400, $limit !== null ? 2 * $limit : 0) : null;
        $res = fn (string $state, string $detail, ?int $since = null, bool $healthy = false) => ['state' => $state, 'detail' => $detail, 'since' => $since, 'was_healthy' => $healthy];

        if ($runs === []) {
            if ($forgottenTs !== null) {
                return $res('dormant', 'history cleared ' . date('Y-m-d', $forgottenTs) . ', no run since', $forgottenTs);
            }
            if ($createdTs !== null && $dormantLimit !== null && ($nowTs - $createdTs) > $dormantLimit) {
                return $res('dormant', 'has never run (set up ' . date('Y-m-d', $createdTs) . ')', $createdTs);
            }
            if ($limit !== null && $createdTs !== null && ($nowTs - $createdTs) > $limit) {
                return $res('stale', 'it has never run since it was set up');
            }

            return $res('ok', 'no runs yet');
        }

        $lastStart = (int) $runs[0]['started'];
        if ($dormantLimit !== null && ($nowTs - $lastStart) > $dormantLimit) {
            return $res('dormant', 'dormant since ' . date('Y-m-d', $lastStart) . ' (no run for ' . self::human($nowTs - $lastStart) . ')', $lastStart, !self::runErrored($runs[0], $nowTs));
        }
        if (count($runs) >= $errorThreshold) {
            $allBad = true;
            for ($i = 0; $i < $errorThreshold; $i++) {
                if (!self::runErrored($runs[$i], $nowTs)) {
                    $allBad = false;
                    break;
                }
            }
            if ($allBad) {
                return $res('failing', "the last $errorThreshold runs all ended in an error");
            }
        }
        if ($limit === null) {
            return $res('ok', 'staleness watch off');
        }
        if (($nowTs - $lastStart) > $limit) {
            return $res('stale', 'no run for ' . self::human($nowTs - $lastStart) . ' (expected every ' . self::human($intervalMin * 60) . ')');
        }

        return $res('ok', 'last run ' . self::human($nowTs - $lastStart) . ' ago');
    }

    public static function human(int $seconds): string
    {
        $seconds = max(0, $seconds);
        if ($seconds < 5400) {
            return max(1, (int) round($seconds / 60)) . ' min';
        }

        return $seconds < 172800 ? round($seconds / 3600, 1) . ' h' : round($seconds / 86400, 1) . ' days';
    }

    /**
     * Every watched integration with the state the watcher sees, plus what an administrator overrode. No side effects: this is what
     * the Backup page shows and what run() alerts from.
     *
     * @return list<array<string,mixed>> source, label, id, name, state (ok|stale|failing|dormant|muted), underlying (the state without the mute),
     *                                   detail, last_ts, last_status, interval_min, interval_source (override|setting|inferred|off), override, since, was_healthy
     */
    public static function inspect(\mysqli $db, bool $rmmModuleOn = true): array
    {
        $res = @mysqli_query($db, 'SELECT UNIX_TIMESTAMP() AS n');
        $nowTs = $res ? (int) (mysqli_fetch_assoc($res)['n'] ?? time()) : time();
        $threshold = RecoverySettings::int($db, 'sync_error_threshold');
        $dormantDays = RecoverySettings::int($db, 'sync_dormant_days');
        $overrides = WatchOverrides::all($db);

        $sources = [
            ['rmm', 'RMM', 'rmm_sync_log', 'integration_id',
                $rmmModuleOn ? "SELECT id, name, UNIX_TIMESTAMP(created_at) AS created FROM rmm_integrations WHERE enabled = 1 AND (type IS NULL OR type <> 'rivetit_agent')" : null],
            ['intune', 'Intune', 'intune_sync_log', 'microsoft_integration_id',
                "SELECT microsoft_integration_id AS id, 'Microsoft 365' AS name, NULL AS created FROM microsoft_integrations WHERE enabled = 1 AND intune_sync_enabled = 1"],
            ['unifi', 'UniFi', 'unifi_sync_log', 'integration_id',
                'SELECT id, name, UNIX_TIMESTAMP(created_at) AS created FROM unifi_integrations WHERE enabled = 1'],
            // ManageEngine MDM: its own expected interval is the integration's configured sync interval (expected_min), used unless an administrator overrides it.
            ['mdm', 'ManageEngine MDM', 'mdm_sync_log', 'integration_id',
                "SELECT id, 'ManageEngine MDM' AS name, UNIX_TIMESTAMP(created_at) AS created, sync_interval_minutes AS expected_min FROM manageengine_mdm_integrations WHERE enabled = 1"],
        ];
        $out = [];
        foreach ($sources as [$key, $label, $logTable, $idCol, $listSql]) {
            if ($listSql === null) {
                continue;
            }
            $list = @mysqli_query($db, $listSql);
            while ($list && ($intg = mysqli_fetch_assoc($list))) {
                $id = (int) $intg['id'];
                $ov = $overrides[$key . ':' . $id] ?? null;
                $forgotten = $ov && $ov['forget_before_ts'] !== null ? (int) $ov['forget_before_ts'] : null;
                $since = $forgotten !== null ? ' AND started_at >= FROM_UNIXTIME(' . $forgotten . ')' : '';
                $runs = [];
                $sched = [];
                $q = @mysqli_query($db, "SELECT status, errors, triggered_by, UNIX_TIMESTAMP(started_at) AS s, UNIX_TIMESTAMP(finished_at) AS f FROM `$logTable` WHERE `$idCol` = $id$since ORDER BY id DESC LIMIT 10");
                while ($q && ($r = mysqli_fetch_assoc($q))) {
                    $runs[] = ['status' => (string) $r['status'], 'error' => $r['errors'], 'started' => (int) $r['s'], 'finished' => $r['f'] === null ? null : (int) $r['f']];
                    if ((int) $r['triggered_by'] === 0) {
                        $sched[] = (int) $r['s'];
                    }
                }
                $lastTs = $runs ? $runs[0]['started'] : null;

                if ($ov && $ov['expected_interval_s'] !== null && (int) $ov['expected_interval_s'] > 0) {
                    $interval = max(1, (int) ceil((int) $ov['expected_interval_s'] / 60));
                    $intervalSource = 'override';
                } elseif (isset($intg['expected_min']) && (int) $intg['expected_min'] > 0) {
                    $interval = (int) $intg['expected_min'];
                    $intervalSource = 'setting';
                } else {
                    $setting = RecoverySettings::get($db, 'sync_interval_' . $key);
                    if ($setting === 'auto') {
                        $interval = self::inferInterval(count($sched) >= 3 ? $sched : array_column($runs, 'started'));
                        $intervalSource = $interval === null ? 'off' : 'inferred';
                    } else {
                        $interval = (int) $setting;
                        $intervalSource = $interval > 0 ? 'setting' : 'off';
                    }
                }
                $a = self::assess($runs, $intg['created'] === null ? null : (int) $intg['created'], $nowTs, $interval, $threshold, $dormantDays, $forgotten);
                $muted = $ov && (int) $ov['muted'] === 1;
                $out[] = [
                    'source' => $key, 'label' => $label, 'id' => $id, 'name' => (string) $intg['name'],
                    'state' => $muted ? 'muted' : $a['state'], 'underlying' => $a['state'], 'detail' => $a['detail'],
                    'last_ts' => $lastTs, 'last_status' => $runs ? $runs[0]['status'] : null,
                    'interval_min' => $interval, 'interval_source' => $intervalSource,
                    'override' => $ov, 'since' => $a['since'], 'was_healthy' => $a['was_healthy'], 'now' => $nowTs,
                ];
            }
        }

        return $out;
    }

    /**
     * Alerts for what inspect() finds.
     *
     * @return list<array{source:string,id:int,name:string,state:string,detail:string,alerted:bool}> the problem ones (and a dormant notice when one went out)
     */
    public static function run(\mysqli $db, bool $rmmModuleOn = true): array
    {
        $problems = [];
        $dormantDays = max(1, RecoverySettings::int($db, 'sync_dormant_days'));
        foreach (self::inspect($db, $rmmModuleOn) as $row) {
            $key = $row['source'];
            $id = $row['id'];
            $alertKey = "sync.$key.$id.";
            $name = $row['name'];
            $label = $row['label'];
            if ($row['state'] === 'muted') {
                foreach (['failing', 'stale', 'dormant'] as $k) {
                    RecoveryAlerts::clear($db, $alertKey . $k);
                }
                continue;
            }
            if ($row['state'] === 'ok') {
                // Back to healthy after we alerted: say so once, then forget the keys so the next failure is "first" again.
                $wasAlerted = RecoveryAlerts::exists($db, $alertKey . 'failing') || RecoveryAlerts::exists($db, $alertKey . 'stale');
                foreach (['failing', 'stale', 'dormant'] as $k) {
                    RecoveryAlerts::clear($db, $alertKey . $k);
                }
                if ($wasAlerted) {
                    $msg = "$label sync ($name) has recovered: " . $row['detail'];
                    RecoveryAlerts::notice($db, $msg, $msg . '.', '/admin/backup.php#sync-watch');
                }
                continue;
            }
            if ($row['state'] === 'dormant') {
                RecoveryAlerts::clear($db, $alertKey . 'failing');
                RecoveryAlerts::clear($db, $alertKey . 'stale');
                // One "no longer running" notice, only for something that was healthy and only while it is newly dormant, at most every 30 days.
                // A long-dormant integration (an old UniFi sync replaced by an external script) never mails.
                $sinceTs = $row['since'];
                if ($row['was_healthy'] && $sinceTs !== null && $row['last_ts'] !== null && ($row['now'] - $sinceTs) <= ($dormantDays + self::DORMANT_NOTICE_WINDOW_DAYS) * 86400) {
                    $subject = "$label sync ($name) is no longer running: " . $row['detail'];
                    $sent = RecoveryAlerts::raise($db, $alertKey . 'dormant', substr($subject, 0, 220),
                        $subject . ".\n\nIt was healthy before. If it was retired on purpose, open Admin > Backup > Integration sync watch and choose Mute or Forget history. If a script now syncs it outside RivetIT, declare how often it runs there.",
                        'integration.sync_stale', ['source' => $key, 'integration_id' => $id, 'name' => $name, 'detail' => $row['detail'], 'state' => 'dormant'],
                        '/admin/backup.php#sync-watch', self::DORMANT_NOTICE_REPEAT_HOURS);
                    if ($sent) {
                        $problems[] = ['source' => $key, 'id' => $id, 'name' => $name, 'state' => 'dormant', 'detail' => $row['detail'], 'alerted' => true];
                    }
                }
                continue;
            }
            $subject = "$label sync " . ($row['state'] === 'failing' ? 'is failing' : 'has stopped') . " ($name): " . $row['detail'];
            $sent = RecoveryAlerts::raise($db, $alertKey . $row['state'], substr($subject, 0, 220),
                $subject . ".\n\nOpen Admin > Integrations and run a manual sync to see the error. If the cron job itself stopped, check Admin > Server Status. If this is an external script, set its expected interval or mute it under Admin > Backup > Integration sync watch.",
                $row['state'] === 'failing' ? 'integration.sync_failed' : 'integration.sync_stale',
                ['source' => $key, 'integration_id' => $id, 'name' => $name, 'detail' => $row['detail']], '/admin/backup.php#sync-watch');
            $problems[] = ['source' => $key, 'id' => $id, 'name' => $name, 'state' => $row['state'], 'detail' => $row['detail'], 'alerted' => $sent];
        }

        return $problems;
    }
}
