<?php

namespace ITFlow\EndpointAgent;

/** Periodic housekeeping, run from cron/cron.php. */
final class Maintenance
{
    /** @return array<string,int> */
    public static function run(): array
    {
        $out = ['offline' => 0, 'jobs_swept' => 0, 'pruned_checkins' => 0, 'pruned_attempts' => 0, 'pruned_jobs' => 0];
        $cfg = Config::get(true);
        if (!(int) $cfg['enabled']) {
            return $out;
        }
        $intg = Config::integrationId();
        // Devices that stopped checking in: flip the existing RMM link to offline (this also feeds the asset_offline automation trigger).
        $out['offline'] = Db::run("UPDATE asset_rmm_links arl JOIN endpoint_agent_devices d ON arl.integration_id = ? AND arl.tactical_agent_id = CONCAT('rivetit:', d.device_id)
            SET arl.rmm_status = 'offline', arl.rmm_status_changed_at = NOW() WHERE arl.rmm_status = 'online' AND (d.last_checkin_at IS NULL OR d.last_checkin_at < ?)",
            [$intg, gmdate('Y-m-d H:i:s', time() - (int) $cfg['offline_after_s'])]);
        Jobs::sweep();
        $out['pruned_checkins'] = Db::run('DELETE FROM endpoint_agent_checkins WHERE received_at < ?', [gmdate('Y-m-d H:i:s', time() - ((int) $cfg['retention_days'] + 1) * 86400)]);
        $out['pruned_attempts'] = Db::run('DELETE FROM endpoint_agent_enroll_attempts WHERE attempted_at < ?', [gmdate('Y-m-d H:i:s', time() - 30 * 86400)]);
        $out['pruned_jobs'] = Db::run("DELETE FROM endpoint_agent_jobs WHERE state IN ('succeeded','failed','timed_out','cancelled','expired') AND finished_at < ?",
            [gmdate('Y-m-d H:i:s', time() - (int) $cfg['job_retention_days'] * 86400)]);
        return $out;
    }
}
