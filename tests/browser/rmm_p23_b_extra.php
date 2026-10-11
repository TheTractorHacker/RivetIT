<?php
/*
 * Extra fixtures for tests/browser/rmm_p23_b_smoke.mjs, run after tests/browser/rmm_seed.php on the same throwaway install: a script that needs approval, published
 * and run on LNX1 by the technician (user 10) through the technician API, so the browser (signed in as the administrator) finds a request made by someone else.
 * Prints one JSON line. Scratch databases only (the guard of tests/endpoint_agent_lib.php applies).
 */
require dirname(__DIR__) . '/endpoint_agent_lib.php';
require_once dirname(__DIR__, 2) . '/includes/rmm_bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/rmm_automation.php';

$seed = json_decode((string) file_get_contents($argv[1]), true);
$api = fn(string $m, array $seg, array $qs = [], ?array $b = null) => rivetRmmAutoApi($db, 10, 'tech', $m, $seg, $qs, $b);
$s = $api('POST', ['scripts'], [], ['name' => 'Needs sign-off (smoke)', 'language' => 'bash', 'body' => "echo preview-line <not-html>\nexit 0\n", 'requires_approval' => true, 'description' => 'Waits for a second person']);
$sid = (int) ($s['data']['script']['script_id'] ?? 0);
$r = $api('POST', [(int) $seed['dev']['LNX1'], 'jobs'], [], ['library_script_id' => $sid, 'params' => new stdClass()]);
echo json_encode(['script_id' => $sid, 'status' => $r['status'], 'approval_id' => (int) ($r['data']['approval_id'] ?? 0), 'error' => $s['error'] . ' ' . $r['error']]) . "\n";
