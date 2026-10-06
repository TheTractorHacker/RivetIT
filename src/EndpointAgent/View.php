<?php

namespace ITFlow\EndpointAgent;

/** Read models for the technician-facing API and pages. Everything device-supplied is returned as data; escaping is the renderer's job. */
final class View
{
    /** @return array<string,mixed> */
    public static function summary(array $d, array $cfg): array
    {
        $st = Devices::status($d, $cfg);
        return [
            'device_id' => (int) $d['device_id'],
            'hostname' => $d['hostname'],
            'asset_id' => $d['asset_id'] === null ? null : (int) $d['asset_id'],
            'client_id' => (int) $d['client_id'],
            'link_state' => $d['link_state'],
            'status' => $st['state'],
            'last_checkin_at' => $st['last_checkin_at'],
            'offline_since' => $st['offline_since'],
            'agent_version' => $d['agent_version'],
            'ring' => $d['ring'],
            'os_version' => $d['os_version'],
            'revoked' => $d['revoked_at'] !== null,
            'retired' => $d['retired_at'] !== null,
        ];
    }

    /** @return array<string,mixed> */
    public static function detail(array $d, array $cfg, bool $showJobOutput): array
    {
        $out = self::summary($d, $cfg);
        $out['serial'] = $d['serial'];
        $out['manufacturer'] = $d['manufacturer'];
        $out['model'] = $d['model'];
        $out['arch'] = $d['arch'];
        $out['logged_in_user'] = $d['logged_in_user'];
        $out['pending_reboot'] = $d['pending_reboot'] === null ? null : (bool) $d['pending_reboot'];
        $out['uptime_s'] = $d['uptime_s'] === null ? null : (int) $d['uptime_s'];
        $out['last_collected_at'] = Db::iso($d['last_collected_at']);
        $out['inventory'] = $d['inventory_json'] ? json_decode((string) $d['inventory_json'], true) : null;
        $out['metrics'] = $d['last_metrics_json'] ? json_decode((string) $d['last_metrics_json'], true) : null;
        $out['match_reason'] = $d['match_reason'];
        $out['mesh_mapped'] = Db::val('SELECT 1 FROM endpoint_agent_mesh_nodes WHERE device_id = ?', [$d['device_id']]) !== null;
        $out['checks'] = array_map(static fn($c) => ['key' => $c['key'] ?? $c['check_key'], 'status' => $c['status'], 'detail' => $c['detail'],
            'consecutive_failures' => (int) $c['consecutive_failures'], 'last_reported_at' => Db::iso($c['last_reported_at'])],
            array_map(static fn($c) => $c + ['key' => $c['check_key']], Db::all('SELECT * FROM endpoint_agent_checks WHERE device_id = ? ORDER BY check_key', [$d['device_id']])));
        $out['jobs'] = self::jobs((int) $d['device_id'], 20, $showJobOutput);
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public static function jobs(int $deviceId, int $limit, bool $withOutput): array
    {
        $rows = Db::all('SELECT * FROM endpoint_agent_jobs WHERE device_id = ? ORDER BY created_at DESC, job_id LIMIT ' . max(1, min(200, $limit)), [$deviceId]);
        return array_map(static function ($j) use ($withOutput) {
            $o = ['job_id' => $j['job_id'], 'type' => $j['type'], 'state' => $j['state'], 'reason' => $j['reason'], 'attempt' => (int) $j['attempt'],
                'destructive' => (bool) $j['destructive'], 'run_as' => $j['run_as'], 'timeout_s' => (int) $j['timeout_s'], 'exit_code' => $j['exit_code'] === null ? null : (int) $j['exit_code'],
                'created_by' => (int) $j['created_by'], 'created_at' => Db::iso($j['created_at']), 'started_at' => Db::iso($j['started_at']), 'finished_at' => Db::iso($j['finished_at']),
                'expires_at' => Db::iso($j['expires_at'])];
            if ($withOutput) {
                $o['output'] = $j['output'];
                $o['output_truncated'] = (bool) $j['output_truncated'];
            }
            return $o;
        }, $rows);
    }
}
