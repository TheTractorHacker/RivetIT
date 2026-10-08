<?php

namespace ITFlow\Automation;

/**
 * Turns SLA clocks into events: ticket.sla_warning (an open ticket's response or resolution clock passed $warnPct percent) and
 * ticket.sla_breached (a clock ran out). The SLA has no event of its own, so cron calls run() on every pass.
 * Each (ticket, clock, kind) is announced once (automation_sla_marks), and a breach that happened more than a day ago is recorded
 * but not announced, so switching this on does not fire a flood for tickets that were already overdue. Only runs when something
 * listens (an enabled event rule or an enabled webhook for these events), so installs that do not use them pay nothing.
 */
final class SlaEventEmitter
{
    public const EVENTS = ['ticket.sla_warning', 'ticket.sla_breached'];
    public const STALE_BREACH_SECONDS = 86400;

    /**
     * Table check that lives in this class: cron calls run() with only the autoloader, and a free function declared in
     * RuleEngine.php does not exist until that class has been loaded ("Call to undefined function ... rivetTableExistsSafe").
     */
    private static function tableExists(\mysqli $mysqli, string $table): bool
    {
        static $cache = [];
        $key = spl_object_id($mysqli) . ':' . $table;
        if (!array_key_exists($key, $cache)) {
            $res = @mysqli_query($mysqli, "SHOW TABLES LIKE '" . mysqli_real_escape_string($mysqli, $table) . "'");
            $cache[$key] = (bool) ($res && mysqli_num_rows($res) > 0);
        }

        return $cache[$key];
    }

    public static function someoneListens(\mysqli $mysqli): bool
    {
        if (!self::tableExists($mysqli, 'automation_sla_marks')) {
            return false;
        }
        if (self::tableExists($mysqli, 'automation_rules')
            && mysqli_fetch_row(mysqli_query($mysqli, "SELECT 1 FROM automation_rules WHERE is_enabled = 1 AND trigger_event IN ('ticket.sla_warning','ticket.sla_breached') LIMIT 1"))) {
            return true;
        }
        $res = @mysqli_query($mysqli, "SELECT 1 FROM webhooks WHERE webhook_enabled = 1 AND (webhook_events LIKE '%ticket.sla_warning%' OR webhook_events LIKE '%ticket.sla_breached%' OR webhook_events LIKE '%*%') LIMIT 1");

        return (bool) ($res && mysqli_fetch_row($res));
    }

    /**
     * @param callable(string,array):void $emit usually rivetEmitEvent
     * @return int events emitted
     */
    public static function run(\mysqli $mysqli, callable $emit, int $warnPct = 80, ?int $limit = 2000): int
    {
        if (!self::someoneListens($mysqli)) {
            return 0;
        }
        if (!function_exists('slaStatus')) {
            require_once dirname(__DIR__, 2) . '/includes/sla_functions.php';
        }
        $sent = 0;
        $res = mysqli_query($mysqli,
            'SELECT * FROM tickets WHERE ticket_archived_at IS NULL AND ticket_resolved_at IS NULL AND ticket_closed_at IS NULL
               AND (ticket_sla_response_due IS NOT NULL OR ticket_sla_resolution_due IS NOT NULL) ORDER BY ticket_id DESC LIMIT ' . max(1, (int) $limit));
        while ($res && ($t = mysqli_fetch_assoc($res))) {
            $status = slaStatus($t, []);
            foreach (['response', 'resolution'] as $clock) {
                $s = $status[$clock];
                if ($s['state'] === 'none' || $s['state'] === 'met' || ($clock === 'response' && !empty($t['ticket_first_response_at']))) {
                    continue;
                }
                if ($s['state'] === 'breached') {
                    $kind = 'breached';
                } elseif ($s['state'] === 'running' && (float) $s['pct'] >= $warnPct) {
                    $kind = 'warning';
                } else {
                    continue;
                }
                if (self::mark($mysqli, (int) $t['ticket_id'], "{$clock}_$kind")) {
                    if ($kind === 'breached' && ($s['remaining_sec'] ?? 0) < -self::STALE_BREACH_SECONDS) {
                        continue; // already overdue for a long time: recorded, not announced
                    }
                    $emit('ticket.sla_' . $kind, EventPayloads::ticket($mysqli, (int) $t['ticket_id']) + ['sla_clock' => $clock, 'sla_percent_used' => (float) $s['pct'], 'sla_remaining_seconds' => (int) ($s['remaining_sec'] ?? 0)]);
                    $sent++;
                }
            }
        }

        return $sent;
    }

    /** True the first time this (ticket, kind) is marked. */
    private static function mark(\mysqli $mysqli, int $ticketId, string $kind): bool
    {
        $stmt = mysqli_prepare($mysqli, 'INSERT IGNORE INTO automation_sla_marks (ticket_id, kind) VALUES (?, ?)');
        mysqli_stmt_bind_param($stmt, 'is', $ticketId, $kind);
        mysqli_stmt_execute($stmt);
        $new = mysqli_stmt_affected_rows($stmt) === 1;
        mysqli_stmt_close($stmt);

        return $new;
    }
}
