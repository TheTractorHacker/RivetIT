<?php
/*
 * Fixtures for tests/browser/rmm_buildhost_smoke.mjs (Agent updates > Build on my build host; RivetIT 26.10.57), on the same throwaway install.
 *
 *   php tests/browser/rmm_buildhost_extra.php <seed.json> reset    remove build hosts, jobs, hosted binaries / MSI templates / releases
 *   php tests/browser/rmm_buildhost_extra.php <seed.json> state    JSON: hosts (no hashes), jobs, binaries, releases, audit counts, whether any token text is in the logs
 *
 * Prints one JSON line. Scratch databases only (the guard of tests/endpoint_agent_lib.php applies).
 */
require dirname(__DIR__) . '/endpoint_agent_lib.php';

$mode = $argv[2] ?? 'state';
$out = ['ok' => true];
if ($mode === 'reset') {
    foreach (['endpoint_agent_build_chunks', 'endpoint_agent_build_files', 'endpoint_agent_build_jobs', 'endpoint_agent_build_hosts', 'endpoint_agent_binaries', 'endpoint_agent_msi_templates', 'endpoint_agent_releases', 'endpoint_agent_linux_assets'] as $t) { $q("DELETE FROM $t"); }
    $q("UPDATE endpoint_agent_feed SET repo='TheTractorHacker/rivet-core', releases_json=NULL, etag='', fetched_at=NULL, checked_at=NULL, last_error='', rate_reset_at=NULL");
    $q("DELETE FROM logs WHERE log_action LIKE 'Agent Build Host%'");
} else {
    $out['hosts'] = $rows('SELECT host_id, name, enabled, last_seen_at IS NOT NULL AS seen, LENGTH(token_hash) AS hash_len FROM endpoint_agent_build_hosts');
    $out['jobs'] = $rows('SELECT job_id, version, source_ref, state, auto_publish, release_ring, rollout_pct, note FROM endpoint_agent_build_jobs ORDER BY job_id');
    $out['binaries'] = $rows('SELECT version, arch, is_current FROM endpoint_agent_binaries ORDER BY version, arch');
    $out['msi'] = $rows('SELECT version, arch, is_current FROM endpoint_agent_msi_templates ORDER BY version');
    $out['releases'] = $rows('SELECT version, ring, rollout_pct, arch FROM endpoint_agent_releases ORDER BY version, arch');
    $out['audit'] = array_column($rows("SELECT log_action, COUNT(*) c FROM logs WHERE log_action LIKE 'Agent Build Host%' GROUP BY log_action"), 'c', 'log_action');
    $out['token_in_logs'] = (int) $one("SELECT COUNT(*) FROM logs WHERE log_description LIKE '%rbh\\_%\\_%'");
    $out['quarantine_files'] = count(glob(RIVET_BUILD_HOST_DIR . '/*/*') ?: []);
}
echo json_encode($out) . "\n";
