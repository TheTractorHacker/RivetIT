<?php

namespace ITFlow\Automation;

/**
 * Events that nothing "does" but time passing: cron looks and raises them so event rules (and webhooks) can react.
 *
 *   asset.offline|online     an asset's RMM link went offline / came back (once per status change)
 *   certificate.expiring     a certificate is N days from expiring (N = Administration > Automation > certificate days, default 30; again at 7, 1 and 0)
 *   credential.rotation_due  a credential's rotation date has arrived (once per due date); the payload NEVER carries the username or password
 *   schedule.hourly|daily|weekly|monthly   a clock tick, once per period, for "every Monday at 8" style rules (conditions on hour / weekday / day_of_month)
 *
 * Same discipline as SlaEventEmitter: only runs for events something listens to (an enabled rule, or a webhook naming the event or "group.*";
 * a bare "*" subscription does not count, so a catch-all webhook is not flooded with clock ticks), each thing is announced ONCE (automation_emit_marks),
 * and something already long overdue when first seen is recorded but not announced, so switching a rule on never fires for the whole backlog.
 * The emit callable is usually rivetEmitEvent; tests hand in a recorder.
 */
final class CronEmitters
{
    public const EVENTS = ['asset.offline', 'asset.online', 'certificate.expiring', 'credential.rotation_due', 'schedule.hourly', 'schedule.daily', 'schedule.weekly', 'schedule.monthly'];
    /** An outage / overdue item older than this when first seen is marked, not announced. */
    public const STALE_OFFLINE_SECONDS = 86400;
    public const STALE_ROTATION_DAYS = 30;
    public const LIMIT = 500;
    public const WEEKDAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    private static function tableExists(\mysqli $m, string $table): bool
    {
        $res = @mysqli_query($m, "SHOW TABLES LIKE '" . mysqli_real_escape_string($m, $table) . "'");

        return (bool) ($res && mysqli_num_rows($res) > 0);
    }

    /** @return array<string,bool> clock event => whether an enabled rule or a webhook listens for it (false for all while the marks table is missing) */
    public static function listening(\mysqli $m): array
    {
        return self::tableExists($m, 'automation_emit_marks') ? self::listeningTo($m, self::EVENTS) : array_fill_keys(self::EVENTS, false);
    }

    /**
     * Whether an enabled event rule, or a webhook that names the event ("x.y" or "x.*"; a bare "*" does not count), listens for each event.
     * @param list<string> $events @return array<string,bool>
     */
    public static function listeningTo(\mysqli $m, array $events): array
    {
        $out = array_fill_keys($events, false);
        if ($events === []) {
            return $out;
        }
        if (self::tableExists($m, 'automation_rules')) {
            $in = "'" . implode("','", array_map(static fn (string $e): string => mysqli_real_escape_string($m, $e), $events)) . "'";
            $res = mysqli_query($m, "SELECT DISTINCT trigger_event FROM automation_rules WHERE is_enabled = 1 AND trigger_event IN ($in)");
            while ($res && ($r = mysqli_fetch_row($res))) {
                $out[(string) $r[0]] = true;
            }
        }
        $res = @mysqli_query($m, "SELECT webhook_events FROM webhooks WHERE webhook_enabled = 1");
        while ($res && ($r = mysqli_fetch_row($res))) {
            foreach (array_map('trim', explode(',', (string) $r[0])) as $pattern) {
                foreach ($events as $ev) {
                    if ($pattern === $ev || (str_ends_with($pattern, '.*') && $pattern !== '*' && str_starts_with($ev, substr($pattern, 0, -1)))) {
                        $out[$ev] = true;
                    }
                }
            }
        }

        return $out;
    }

    /** True the first time (kind, entity, mark) is seen. */
    public static function mark(\mysqli $m, string $kind, string $entity, string $mark): bool
    {
        $stmt = mysqli_prepare($m, 'INSERT IGNORE INTO automation_emit_marks (kind, entity_id, mark) VALUES (?, ?, ?)');
        mysqli_stmt_bind_param($stmt, 'sss', $kind, $entity, $mark);
        mysqli_stmt_execute($stmt);
        $new = mysqli_stmt_affected_rows($stmt) === 1;
        mysqli_stmt_close($stmt);

        return $new;
    }

    /**
     * @param callable(string,array):void $emit
     * @return array<string,int> event => number emitted
     */
    public static function run(\mysqli $m, callable $emit, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $listen = self::listening($m);
        $sent = array_fill_keys(self::EVENTS, 0);
        if (!in_array(true, $listen, true)) {
            return $sent;
        }
        foreach (['offline', 'online'] as $status) {
            if ($listen['asset.' . $status]) {
                $sent['asset.' . $status] = self::assetsByStatus($m, $emit, $status);
            }
        }
        if ($listen['certificate.expiring']) {
            $sent['certificate.expiring'] = self::certificates($m, $emit, $now);
        }
        if ($listen['credential.rotation_due']) {
            $sent['credential.rotation_due'] = self::credentials($m, $emit, $now);
        }
        foreach (['schedule.hourly' => 'Y-m-d H', 'schedule.daily' => 'Y-m-d', 'schedule.weekly' => 'o-\WW', 'schedule.monthly' => 'Y-m'] as $ev => $fmt) {
            if ($listen[$ev]) {
                $sent[$ev] = self::tick($m, $emit, $now, $ev, $now->format($fmt));
            }
        }
        // housekeeping: clock ticks are only ever compared with the current period, the rest are kept for a year
        mysqli_query($m, "DELETE FROM automation_emit_marks WHERE (kind LIKE 'schedule.%' AND created_at < NOW() - INTERVAL 8 DAY) OR created_at < NOW() - INTERVAL 400 DAY");

        return $sent;
    }

    private static function assetsByStatus(\mysqli $m, callable $emit, string $status): int
    {
        $event = 'asset.' . $status;
        if (!self::tableExists($m, 'asset_rmm_links')) {
            return 0;
        }
        $n = 0;
        $res = mysqli_query($m, "SELECT arl.asset_id, arl.hostname, arl.integration_id, arl.rmm_status_changed_at, a.asset_name, a.asset_type, a.asset_client_id, a.asset_contact_id,
                TIMESTAMPDIFF(SECOND, arl.rmm_status_changed_at, NOW()) AS age
            FROM asset_rmm_links arl JOIN assets a ON a.asset_id = arl.asset_id
            WHERE arl.rmm_status = '$status' AND arl.rmm_status_changed_at IS NOT NULL AND a.asset_archived_at IS NULL
            ORDER BY arl.rmm_status_changed_at DESC LIMIT " . self::LIMIT);
        $rows = $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [];
        foreach ($rows as $r) {
            if (!self::mark($m, $event, (string) $r['asset_id'], (string) $r['rmm_status_changed_at'])) {
                continue;
            }
            if ((int) $r['age'] > self::STALE_OFFLINE_SECONDS) {
                continue; // an old outage found for the first time: recorded, not announced
            }
            $emit($event, ['asset_id' => (int) $r['asset_id'], 'asset_name' => (string) $r['asset_name'], 'asset_type' => (string) $r['asset_type'], 'client_id' => (int) $r['asset_client_id'],
                'contact_id' => (int) $r['asset_contact_id'], 'hostname' => (string) ($r['hostname'] ?? ''), 'integration_id' => (int) $r['integration_id'], 'rmm_status' => $status,
                'status_since' => (string) $r['rmm_status_changed_at'], 'source' => 'rmm']);
            $n++;
        }

        return $n;
    }

    private static function certificates(\mysqli $m, callable $emit, \DateTimeImmutable $now): int
    {
        $res = @mysqli_query($m, 'SELECT config_automation_cert_days FROM settings WHERE company_id = 1');
        $row = $res ? mysqli_fetch_row($res) : null;
        $max = max(1, min(365, (int) ($row[0] ?? 30)));
        $thresholds = array_values(array_unique(array_filter([$max, 7, 1, 0], static fn (int $t): bool => $t <= $max)));
        rsort($thresholds);
        $n = 0;
        $res = mysqli_query($m, "SELECT certificate_id, certificate_name, certificate_domain, certificate_issued_by, certificate_expire, certificate_client_id, DATEDIFF(certificate_expire, CURDATE()) AS days_left
            FROM certificates WHERE certificate_archived_at IS NULL AND certificate_expire IS NOT NULL AND certificate_expire <= CURDATE() + INTERVAL $max DAY
            ORDER BY certificate_expire LIMIT " . self::LIMIT);
        $rows = $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [];
        foreach ($rows as $r) {
            $left = (int) $r['days_left'];
            $crossed = array_values(array_filter($thresholds, static fn (int $t): bool => $t >= $left));
            if (!$crossed) {
                continue;
            }
            $current = min($crossed);
            $fresh = false;
            foreach ($crossed as $t) {
                $fresh = self::mark($m, 'certificate.expiring', (string) $r['certificate_id'], $r['certificate_expire'] . '@' . $t) || $fresh;
            }
            if (!$fresh || $left < -1) {
                continue; // already announced at this threshold, or expired days ago (recorded only)
            }
            $emit('certificate.expiring', ['certificate_id' => (int) $r['certificate_id'], 'certificate_name' => (string) $r['certificate_name'], 'domain' => (string) ($r['certificate_domain'] ?? ''),
                'issued_by' => (string) $r['certificate_issued_by'], 'client_id' => (int) $r['certificate_client_id'], 'expires_on' => (string) $r['certificate_expire'], 'days_left' => $left, 'threshold_days' => $current]);
            $n++;
        }

        return $n;
    }

    private static function credentials(\mysqli $m, callable $emit, \DateTimeImmutable $now): int
    {
        $n = 0;
        $res = mysqli_query($m, "SELECT credential_id, credential_name, credential_type, credential_client_id, credential_contact_id, credential_asset_id, credential_rotation_due_at, DATEDIFF(CURDATE(), credential_rotation_due_at) AS days_overdue
            FROM credentials WHERE credential_archived_at IS NULL AND credential_rotation_due_at IS NOT NULL AND credential_rotation_due_at <= CURDATE()
            ORDER BY credential_rotation_due_at DESC LIMIT " . self::LIMIT);
        $rows = $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [];
        foreach ($rows as $r) {
            if (!self::mark($m, 'credential.rotation_due', (string) $r['credential_id'], (string) $r['credential_rotation_due_at'])) {
                continue;
            }
            if ((int) $r['days_overdue'] > self::STALE_ROTATION_DAYS) {
                continue;
            }
            // Deliberately no username, password, URI or note: this payload goes to webhooks and rule templates.
            $emit('credential.rotation_due', ['credential_id' => (int) $r['credential_id'], 'credential_name' => (string) $r['credential_name'], 'credential_type' => (string) $r['credential_type'],
                'client_id' => (int) $r['credential_client_id'], 'contact_id' => (int) $r['credential_contact_id'], 'asset_id' => (int) $r['credential_asset_id'],
                'due_on' => (string) $r['credential_rotation_due_at'], 'days_overdue' => (int) $r['days_overdue']]);
            $n++;
        }

        return $n;
    }

    private static function tick(\mysqli $m, callable $emit, \DateTimeImmutable $now, string $event, string $period): int
    {
        if (!self::mark($m, $event, 'clock', $period)) {
            return 0;
        }
        $wd = (int) $now->format('N');
        $emit($event, ['scheduled_for' => $now->format('Y-m-d\TH:i:sP'), 'period' => $period, 'date' => $now->format('Y-m-d'), 'hour' => (int) $now->format('G'), 'minute' => (int) $now->format('i'),
            'weekday' => $wd, 'weekday_name' => self::WEEKDAYS[$wd], 'day_of_month' => (int) $now->format('j'), 'month' => (int) $now->format('n'), 'year' => (int) $now->format('Y')]);

        return 1;
    }
}
