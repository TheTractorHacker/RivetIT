<?php

namespace ITFlow\Quality;

/**
 * One honest summary of the integration for the admin page: is it on, can we reach the QMS, is anything stuck, is anything failing.
 * Never reports "ok" from a stale test; ages are computed from timestamps the workers wrote.
 */
final class SyncHealth
{
    /** @return array{state:string, headline:string, reasons:list<string>, facts:array<string,mixed>} state: off | not_configured | ok | degraded | failing */
    public static function summary(\mysqli $db): array
    {
        $facts = [];
        if (!QualityConfig::ready($db)) {
            return ['state' => 'off', 'headline' => 'The database has not been updated for Quality yet.', 'reasons' => [], 'facts' => []];
        }
        $row = QualityConfig::load($db);
        $on = QualityConfig::moduleOn($db);
        $facts['module_on'] = $on;
        $facts['configured'] = QualityConfig::configured($row);
        if (!$on) {
            return ['state' => 'off', 'headline' => 'Quality is switched off. Settings and history are kept; nothing is synchronized.', 'reasons' => [], 'facts' => $facts];
        }
        if (!$facts['configured']) {
            return ['state' => 'not_configured', 'headline' => 'Quality is on, but the QMS connection is not set up.', 'reasons' => [], 'facts' => $facts];
        }
        $health = SyncWorker::state($db, 'health');
        $facts['last_health_check_at'] = $health['qss_last_run_at'] ?? null;
        $facts['last_health_ok_at'] = $health['qss_last_ok_at'] ?? null;
        $facts['last_health_error'] = $health['qss_last_error'] ?? null;
        $facts['last_test_at'] = $row['qs_last_test_at'];
        $facts['last_test_ok'] = $row['qs_last_test_ok'] === null ? null : (int) $row['qs_last_test_ok'] === 1;
        $facts['last_qms_ok_at'] = $row['qs_last_ok_at'];
        $facts['outbox'] = Outbox::counts($db);
        $streams = [];
        foreach (QualityConfig::STREAMS as $s) {
            $st = SyncWorker::state($db, $s);
            $streams[$s] = ['cursor' => $st['qss_cursor'] ?? null, 'last_ok_at' => $st['qss_last_ok_at'] ?? null, 'last_run_at' => $st['qss_last_run_at'] ?? null,
                'last_error' => $st['qss_last_error'] ?? null, 'events_total' => (int) ($st['qss_events_total'] ?? 0)];
        }
        $facts['streams'] = $streams;
        $facts['reconciled_at'] = SyncWorker::state($db, 'reconcile')['qss_last_full_at'] ?? null;
        $inb = $db->query("SELECT MAX(qi_received_at) AS last, SUM(qi_status = 'failed' AND qi_received_at > (NOW() - INTERVAL 1 DAY)) AS failed FROM quality_inbound_events")->fetch_assoc();
        $facts['inbound_last_at'] = $inb['last'] ?? null;
        $facts['inbound_failed_24h'] = (int) ($inb['failed'] ?? 0);
        $facts['errors_24h'] = (int) ($db->query('SELECT COUNT(*) n FROM quality_error_log WHERE qel_at > (NOW() - INTERVAL 1 DAY)')->fetch_assoc()['n'] ?? 0);
        $facts['events_enabled'] = (int) $row['qs_events_enabled'] === 1;
        $facts['outbound_secret_set'] = (string) $row['qs_outbound_secret_enc'] !== '';
        $facts['inbound_secret_set'] = (string) $row['qs_inbound_secret_enc'] !== '';

        $failing = [];
        $degraded = [];
        $gapMin = QualityConfig::clamp((int) $row['qs_sync_interval_minutes'], 1, 1440);
        $checkAt = $facts['last_health_check_at'];
        if ($checkAt === null) {
            $degraded[] = 'The QMS has not been checked yet (the scheduled sync has not run).';
        } else {
            $age = time() - (int) strtotime((string) $checkAt);
            if ($age > max(900, $gapMin * 60 * 3)) {
                $degraded[] = 'The sync has not run for ' . self::age($age) . '; check the scheduled job.';
            }
            if (!empty($facts['last_health_error'])) {
                $failing[] = 'The last health check failed: ' . $facts['last_health_error'];
            }
        }
        if ($facts['last_test_ok'] === false) {
            $degraded[] = 'The last manual connection test failed.';
        }
        if ($facts['events_enabled']) {
            if (!$facts['outbound_secret_set']) {
                $failing[] = 'Events are on but no outbound signing secret is set.';
            }
            if ($facts['outbox']['dead'] > 0) {
                $failing[] = $facts['outbox']['dead'] . ' event(s) could not be delivered and are waiting for a replay.';
            }
            $old = $facts['outbox']['oldest_pending_seconds'];
            if ($old !== null && $old > 3600) {
                $degraded[] = 'The oldest pending event has waited ' . self::age($old) . '.';
            }
            foreach ($streams as $name => $s) {
                if (!empty($s['last_error'])) {
                    $degraded[] = "Stream $name: " . $s['last_error'];
                }
            }
        }
        if ((int) $row['qs_inbound_enabled'] === 1 && !$facts['inbound_secret_set']) {
            $failing[] = 'Inbound events are on but no inbound signing secret is set.';
        }
        if ($facts['inbound_failed_24h'] > 0) {
            $degraded[] = $facts['inbound_failed_24h'] . ' inbound event(s) failed in the last 24 hours.';
        }
        $state = $failing !== [] ? 'failing' : ($degraded !== [] ? 'degraded' : 'ok');
        $headline = match ($state) {
            'ok' => 'Connected and in sync.',
            'degraded' => 'Working, but needs attention.',
            default => 'Not working.',
        };
        return ['state' => $state, 'headline' => $headline, 'reasons' => array_merge($failing, $degraded), 'facts' => $facts];
    }

    public static function age(int $seconds): string
    {
        if ($seconds < 120) {
            return $seconds . ' seconds';
        }
        if ($seconds < 7200) {
            return intdiv($seconds, 60) . ' minutes';
        }
        if ($seconds < 172800) {
            return intdiv($seconds, 3600) . ' hours';
        }
        return intdiv($seconds, 86400) . ' days';
    }
}
