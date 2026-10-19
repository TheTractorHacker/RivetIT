<?php
/*
 * scripts/build_host_setup.php (RivetIT 26.10.58), the CLI behind deploy/setup-build-host.sh: create / rotate / status / disable / server-url against the scratch database.
 * The token must be the only thing on stdout of create / rotate, must verify with the real authentication code, and must not appear in stderr, the audit log or the process arguments.
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=scratch_x RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/build_host_setup_cli.php
 */
require __DIR__ . '/endpoint_agent_lib.php';
require_once "$root/includes/rmm_build_host.php";

$cli = function (string $args) use ($root): array {
    $p = proc_open(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$root/scripts/build_host_setup.php") . ' ' . $args, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);

    return [proc_close($p), $out, $err];
};
$q('DELETE FROM endpoint_agent_build_jobs'); $q('DELETE FROM endpoint_agent_build_hosts'); $q("DELETE FROM logs WHERE log_action LIKE 'Agent Build Host%'");

[$c, $o, $e] = $cli('status --name=Local'); $ok($c === 0 && trim($o) === 'exists=0 enabled=0 host_id=0', 'status of an unknown host');
[$c, $o, $e] = $cli('create --name=Local'); $tok = '';
$ok($c === 0 && preg_match('/^TOKEN=(rbh_[0-9a-f]{16}_[0-9a-f]{48})\n\z/', $o, $m) === 1 && ($tok = $m[1]) !== '' && $e === '', 'create prints exactly one TOKEN= line on stdout and nothing on stderr');
$h = $rows("SELECT * FROM endpoint_agent_build_hosts WHERE name='Local'")[0] ?? [];
$ok($h && (int) $h['enabled'] === 1 && $h['token_hash'] === hash('sha256', $h['token_salt'] . substr($tok, 21)) && !str_contains(json_encode($h), substr($tok, 21)), 'the host row holds only the salted hash');
$ok(rivetBuildHostAuth($db, 'Bearer ' . $tok) !== null, 'the token authenticates with the real code');
$ok((int) $one("SELECT COUNT(*) FROM logs WHERE log_description LIKE '%rbh\\_%\\_%' OR log_description LIKE '%" . substr($tok, 21, 12) . "%'") === 0 && (int) $one("SELECT COUNT(*) FROM logs WHERE log_action='Agent Build Host Added'") === 1, 'the audit log has the event and no token');
[$c, $o, $e] = $cli('create --name=Local'); $ok($c === 3 && $o === '' && !str_contains($e, 'rbh_'), 'create again: refused (3), no token printed');
[$c, $o, $e] = $cli('status --name=Local'); $ok($c === 0 && preg_match('/^exists=1 enabled=1 host_id=\d+$/', trim($o)) === 1, 'status of the host');
[$c, $o] = $cli('rotate --name=Local'); preg_match('/^TOKEN=(rbh_[0-9a-f]{16}_[0-9a-f]{48})$/', trim($o), $m2); $tok2 = $m2[1] ?? '';
$ok($c === 0 && $tok2 !== '' && $tok2 !== $tok && rivetBuildHostAuth($db, 'Bearer ' . $tok2) !== null && rivetBuildHostAuth($db, 'Bearer ' . $tok) === null, 'rotate: new token works, the old one stopped');
[$c] = $cli('disable --name=Local'); $ok($c === 0 && (int) $one("SELECT enabled FROM endpoint_agent_build_hosts WHERE name='Local'") === 0 && rivetBuildHostAuth($db, 'Bearer ' . $tok2) === null, 'disable: the token stops working');
[$c, $o] = $cli('rotate --name=Local'); $ok($c === 0 && (int) $one("SELECT enabled FROM endpoint_agent_build_hosts WHERE name='Local'") === 1, 'rotate enables the host again');
[$c, $o, $e] = $cli('disable --name=Nope'); $ok($c === 3, 'disable of an unknown host: 3');
[$c, $o, $e] = $cli('rotate --name=Nope'); $ok($c === 3 && $o === '', 'rotate of an unknown host: 3, no token');
foreach (['--name=Local', 'create', 'create --name=', 'create --name=' . escapeshellarg("a'; DROP TABLE x; --"), 'create --name=' . escapeshellarg(str_repeat('n', 81)), 'create --name=' . escapeshellarg('<script>'), 'create --name=Local --bogus', 'wipe --name=Local', ''] as $bad) {
    [$c, $o] = $cli($bad); $ok($c === 2 && $o === '', 'refused with usage (2): ' . substr($bad, 0, 40));
}
$ok((int) $one('SELECT COUNT(*) FROM endpoint_agent_build_hosts') === 1, 'hostile names created nothing');
[$c, $o] = $cli('server-url'); $ok($c === 0 && preg_match('#^https?://[^\s/]+(/[^\s]*)?/api/v1/build_host$#', trim($o)) === 1 && !str_contains($o, "\n\n"), 'server-url prints <site>/api/v1/build_host: ' . trim($o));
$q('DELETE FROM endpoint_agent_build_hosts'); $q("DELETE FROM logs WHERE log_action LIKE 'Agent Build Host%'");
