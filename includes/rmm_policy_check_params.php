<?php

/*
 * Check policies for the Tactical RMM integration (agent/rmm_checks.php): the "extra settings" of a policy used to be a JSON text box. They are plain fields now
 * (drive, address, service name, event log options) and this builds the stored settings from them. Pure function, closed set of fields per kind of check, unknown fields
 * refused, nothing else is trusted. The stored form is unchanged (the same JSON object agent/post/rmm_check.php reads in buildTacticalCheckPayload()).
 */

/**
 * @return array{0:?array<string,mixed>,1:?string} [settings, error in plain words]
 */
function rivetPolicyCheckParams(string $type, mixed $pp): array
{
    $pp = $pp ?? [];
    if (!is_array($pp)) {
        return [null, 'The check settings were not sent correctly.'];
    }
    $fields = [
        'diskspace' => ['disk'], 'cpuload' => [], 'memory' => [], 'ping' => ['ip', 'failures'], 'winsvc' => ['svc_name', 'restart'],
        'eventlog' => ['log_name', 'event_type', 'event_id', 'fail_count', 'search_last_days'],
    ];
    if (!isset($fields[$type])) {
        return [null, 'Choose a kind of check.'];
    }
    foreach ($pp as $k => $v) {
        if (!in_array($k, $fields[$type], true) || !is_string($v)) {
            return [null, 'The check settings contain something that does not belong to this kind of check.'];
        }
    }
    $int = static fn (string $k, int $min, int $max, int $def): ?int => !isset($pp[$k]) || $pp[$k] === '' ? $def
        : (preg_match('/^\d{1,9}$/', $pp[$k]) === 1 && (int) $pp[$k] >= $min && (int) $pp[$k] <= $max ? (int) $pp[$k] : null);
    switch ($type) {
        case 'diskspace':
            $d = trim($pp['disk'] ?? 'C');
            if (preg_match('/^([A-Za-z]:?|\/)$/', $d) !== 1) {
                return [null, 'Drive to watch: type one drive letter such as C, or / on Linux and macOS.'];
            }

            return [['disk' => $d === '/' ? '/' : strtoupper($d[0])], null];
        case 'ping':
            $ip = trim($pp['ip'] ?? '');
            if (preg_match('/^[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$|^[0-9A-Fa-f:]{2,45}$/', $ip) !== 1) {
                return [null, 'Address to ping: type an IP address or a host name.'];
            }
            $f = $int('failures', 1, 20, 5);

            return $f === null ? [null, 'Alert after: choose a number from 1 to 20.'] : [['ip' => $ip, 'failures' => $f], null];
        case 'winsvc':
            $n = trim($pp['svc_name'] ?? '');
            if (preg_match('/^[A-Za-z0-9_.@:+ -]{1,128}$/', $n) !== 1) {
                return [null, 'Service to watch: type the short service name, for example Spooler.'];
            }

            return [['svc_name' => $n, 'restart_if_stopped' => ($pp['restart'] ?? '') === '1'], null];
        case 'eventlog':
            $log = $pp['log_name'] ?? 'Application';
            $kind = $pp['event_type'] ?? 'ERROR';
            if (!in_array($log, ['Application', 'System', 'Security', 'Setup'], true) || !in_array($kind, ['INFO', 'WARNING', 'ERROR', 'AUDIT_SUCCESS', 'AUDIT_FAILURE'], true)) {
                return [null, 'Event log: choose a log and a kind of event from the lists.'];
            }
            $id = $int('event_id', 1, 65535, 0);
            $cnt = $int('fail_count', 1, 1000, 1);
            $days = $int('search_last_days', 1, 365, 1);
            if ($id === null || $cnt === null || $days === null) {
                return [null, 'Event log: the event number, the count and the number of days must be whole numbers in range.'];
            }
            $out = ['log_name' => $log, 'event_type' => $kind, 'fail_count' => $cnt, 'search_last_days' => $days];
            if ($id > 0) {
                $out['event_id'] = $id;
            }

            return [$out, null];
    }

    return [[], null];   // cpuload, memory: no extra settings
}
