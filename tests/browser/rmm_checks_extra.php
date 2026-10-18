<?php
/*
 * Fixtures for tests/browser/rmm_checks_smoke.mjs (the plain-language check schedule, RivetIT 26.10.53), run on the same throwaway install after tests/browser/rmm_seed.php.
 *
 *   php tests/browser/rmm_checks_extra.php <seed.json> state          print the stored check list, whether every delivered check verifies against the instance key, and the audit count
 *   php tests/browser/rmm_checks_extra.php <seed.json> reset          clear the stored list (the built-in one applies) and the MeshCentral corrections
 *   php tests/browser/rmm_checks_extra.php <seed.json> custom         store a list with one check the options cannot show (cpu) next to the built-in ones
 *   php tests/browser/rmm_checks_extra.php <seed.json> mesh           print the stored MeshCentral agent id / install argument corrections
 *
 * Prints one JSON line. Scratch databases only (the guard of tests/endpoint_agent_lib.php applies).
 */
require dirname(__DIR__) . '/endpoint_agent_lib.php';
require_once dirname(__DIR__, 2) . '/includes/rmm_bootstrap.php';

$mode = $argv[2] ?? 'state';
$rmm = rivetRmmModule($db);
$out = ['ok' => true];
if ($mode === 'reset') {
    $q('UPDATE endpoint_agent_settings SET checks_json=NULL WHERE id=1');
    $rmm->meshAgentSettings()->update(['mesh_agent_ids' => [], 'mesh_install_args' => []], 1);
} elseif ($mode === 'custom') {
    $list = RivetCore\Rmm\Settings\RmmSettings::defaultChecks();
    $list[] = ['key' => 'cpu_hi', 'type' => 'cpu', 'params' => ['warn_pct' => 90, 'fail_pct' => 98], 'interval_s' => 60];
    $out['errors'] = $rmm->settings()->update(['checks_json' => json_encode($list)]);
} elseif ($mode === 'mesh') {
    $m = $rmm->meshAgentSettings()->get();
    $out['agent_ids'] = $m['agent_ids'] ?? null;
    $out['install_args'] = $m['install_args'] ?? null;
} else {
    $raw = (string) $one('SELECT checks_json FROM endpoint_agent_settings WHERE id=1');
    $out['stored'] = $raw === '' ? null : json_decode($raw, true);
    $pub = (string) $one('SELECT signing_public_key FROM endpoint_agent_settings WHERE id=1');
    $list = $raw === '' ? RivetCore\Rmm\Settings\RmmSettings::defaultChecks() : json_decode($raw, true);
    $signed = $rmm->settings()->signCheckList($list, null);
    $verified = 0;
    foreach ($signed as $c) {
        $sig = $c['signature'];
        unset($c['signature']);
        $verified += RivetCore\Rmm\Crypto\Signer::verify(RivetCore\Rmm\Crypto\Signer::checkMessage($c), $sig, $pub) ? 1 : 0;
    }
    $out['signed'] = count($signed);
    $out['verified'] = $verified;
    $out['audit_settings'] = (int) $one("SELECT COUNT(*) FROM logs WHERE log_description LIKE '%edited the endpoint agent settings%'");
}
echo json_encode($out), "\n";
