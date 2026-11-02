<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/*
 * REST API for event rules and their run log (api/v1/automation_rules.php) against a throw-away instance on a scratch database.
 * Needs the instance running (php -S ... with a config.php that points at the scratch DB) and the same scratch-DB variables as the other tests, plus
 *   RIVETIT_TEST_BASE=http://127.0.0.1:PORT
 * Seeds its own users and API tokens (ids >= 3400) and removes them. Sends no mail: the queue table is only written.
 */
if (getenv('RIVETIT_TEST_DB') !== '1' || !getenv('RIVETIT_TEST_BASE')) exit(2);
if (!preg_match('/scratch|test/i', (string) getenv('RIVETIT_TEST_DB_NAME'))) { fwrite(STDERR, "Refusing: DB name must contain scratch/test\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETIT_TEST_DB_USER'), getenv('RIVETIT_TEST_DB_PASS'), getenv('RIVETIT_TEST_DB_NAME'));
if ($mysqli->connect_errno) { fwrite(STDERR, "connect failed\n"); exit(2); }
$mysqli->query("SET SESSION sql_mode=''");
date_default_timezone_set('UTC');
require __DIR__ . '/../vendor/autoload.php';
$base = rtrim((string) getenv('RIVETIT_TEST_BASE'), '/') . '/api/v1';

$fails = 0;
$ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) { $fails++; if (getenv('RIVETIT_TEST_VERBOSE')) { echo '      last response: ' . substr((string) ($GLOBALS['lastRaw'] ?? ''), 0, 400) . "\n"; } } };
$q = fn(string $sql) => mysqli_query($mysqli, $sql);
$one = fn(string $sql) => ($r = mysqli_query($mysqli, $sql)) ? (mysqli_fetch_row($r)[0] ?? null) : null;
function call(string $base, string $method, string $path, ?string $token, $body = null): array {
    $ch = curl_init($base . $path);
    $h = ['Accept: application/json'];
    if ($token) $h[] = 'Authorization: Bearer ' . $token;
    if ($body !== null) { $h[] = 'Content-Type: application/json'; curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
    $out = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = json_decode((string) $out, true);
    $GLOBALS['lastRaw'] = $code . ' ' . $out;
    return [$code, is_array($j) ? $j : [], (string) $out];
}

// ---- fixtures: an administrator, a plain technician, and tokens for both
$cleanup = function () use ($q) {
    $q("DELETE FROM api_tokens WHERE token_user_id >= 3400"); $q("DELETE FROM users WHERE user_id >= 3400"); $q("DELETE FROM user_roles WHERE role_id >= 3400");
    $q("DELETE FROM audit_events WHERE event_type LIKE 'automation.%'"); $q("DELETE FROM automation_rule_runs"); $q("DELETE FROM automation_rules"); $q("DELETE FROM email_queue WHERE email_recipient LIKE '%@api33.test'");
};
$cleanup();
$q("INSERT INTO user_roles SET role_id = 3400, role_name = 'API Admin', role_is_admin = 1");
$q("INSERT INTO user_roles SET role_id = 3401, role_name = 'API Tech', role_is_admin = 0");
$q("INSERT INTO users SET user_id = 3400, user_name = 'Api Admin', user_email = 'apiadmin@api33.test', user_password = 'x', user_type = 1, user_status = 1, user_role_id = 3400");
$q("INSERT INTO users SET user_id = 3401, user_name = 'Api Tech', user_email = 'apitech@api33.test', user_password = 'x', user_type = 1, user_status = 1, user_role_id = 3401");
$q("INSERT INTO users SET user_id = 3402, user_name = 'Api Recipient', user_email = 'recipient@api33.test', user_password = 'x', user_type = 1, user_status = 1, user_role_id = 3401");
$admin = 'a-' . bin2hex(random_bytes(16)); $tech = 't-' . bin2hex(random_bytes(16));
foreach ([[3400, $admin], [3401, $tech]] as [$u, $t]) { $h = hash('sha256', $t); $q("INSERT INTO api_tokens SET token_user_id = $u, token_name = 'test', token_hash = '$h'"); }

[$c] = call($base, 'GET', '/automation_rules', null);
$ok($c === 401, 'no token: 401');
[$c, $j] = call($base, 'GET', '/automation_rules', $tech);
$ok($c === 403, 'a non-administrator gets 403');
[$c] = call($base, 'POST', '/automation_rules', $tech, ['name' => 'x']);
$ok($c === 403, 'a non-administrator cannot create');

[$c, $j] = call($base, 'GET', '/automation_rules/meta', $admin);
$keys = array_column($j['actions'] ?? [], 'key');
$ok($c === 200 && in_array('run_script', $keys, true) && in_array('notify_chat', $keys, true) && !in_array('entra_create_account', $keys, true) && in_array('certificate.expiring', array_column($j['events'] ?? [], 'id'), true), 'meta: events and actions');
$ok(($j['switches'] ?? null) === ['run_script' => false, 'create_odoo_user' => false] && $j['max_attempts'] === 3 && isset($j['enrichment_fields']['department']), 'meta: risky switches off, 3 attempts, enrichment fields');
$risky = array_column(array_filter($j['actions'], fn($a) => $a['risky']), 'key');
sort($risky);
$ok($risky === ['create_odoo_user', 'entra_add_to_groups', 'entra_disable_account', 'notify_chat', 'run_script'], 'meta: risky actions flagged');

// create
$body = ['name' => 'Cert chat', 'trigger_event' => 'certificate.expiring', 'conditions' => ['mode' => 'all', 'conditions' => [['field' => 'department', 'op' => 'eq', 'value' => 'Finance Dept'], ['field' => 'days_left', 'op' => 'lt', 'value' => '8']]],
    'action_type' => 'notify_chat', 'action_config' => ['destination' => 'it-alerts', 'message' => 'Cert {certificate_name} in {days_left}d'], 'is_enabled' => false, 'priority' => 50];
[$c, $j] = call($base, 'POST', '/automation_rules', $admin, $body);
$rid = (int) ($j['rule']['rule_id'] ?? 0);
$ok($c === 201 && $rid > 0 && $j['rule']['is_enabled'] === false && $j['rule']['priority'] === 50 && $j['rule']['conditions']['conditions'][0]['field'] === 'department', 'create: 201, stored as sent');
$ok(str_contains($j['rule']['summary'], 'certificate') && str_contains($j['rule']['summary'], 'chat'), 'create: the plain-English summary is returned: ' . $j['rule']['summary']);
$ok((int) $one("SELECT COUNT(*) FROM audit_events WHERE event_type='automation.rule_created' AND entity_id='$rid'") === 1, 'create is in the audit trail');
[$c, $j] = call($base, 'POST', '/automation_rules', $admin, ['name' => 'bad', 'trigger_event' => 'ticket created', 'action_type' => 'notify_user', 'action_config' => ['message' => 'x']]);
$ok($c === 422, 'invalid trigger: 422');
[$c, $j] = call($base, 'POST', '/automation_rules', $admin, ['name' => 'bad', 'trigger_event' => 'certificate.expiring', 'action_type' => 'set_ticket_field', 'action_config' => ['status' => 'Open']]);
$ok($c === 422 && str_contains($j['error'], 'ticket'), 'a ticket action on a non-ticket event: 422 with the editor\'s message');
[$c, $j] = call($base, 'POST', '/automation_rules', $admin, ['name' => 'bad', 'trigger_event' => 'certificate.expiring', 'conditions' => ['mode' => 'xor', 'conditions' => []], 'action_type' => 'notify_user', 'action_config' => ['message' => 'x']]);
$ok($c === 422 && str_contains($j['error'], 'conditions'), 'invalid conditions: 422');
[$c, $j] = call($base, 'POST', '/automation_rules', $admin, ['name' => 'bad', 'trigger_event' => 'certificate.expiring', 'action_type' => 'nope', 'action_config' => []]);
$ok($c === 422, 'unknown action: 422');
[$c, $j] = call($base, 'POST', '/automation_rules', $admin, ['name' => 'bad', 'trigger_event' => 'certificate.expiring', 'action_type' => 'notify_chat', 'action_config' => 'oops']);
$ok($c === 422, 'action_config that is not an object: 422');
[$c, $j] = call($base, 'POST', '/automation_rules', $admin, ['name' => 'run script', 'trigger_event' => 'rmm.device.offline', 'action_type' => 'run_script', 'action_config' => ['script_id' => 9, 'run_as_user_id' => 3402, 'params' => ['Service' => 'Spooler']]]);
$ok($c === 201 && $j['rule']['action_config']['params']['Service'] === 'Spooler' && $j['rule']['is_enabled'] === false, 'a run_script rule can be saved over the API (disabled unless asked; the switch still guards the run)');
$rScript = (int) $j['rule']['rule_id'];
[$c, $j] = call($base, 'POST', '/automation_rules', $admin, ['name' => 'hook', 'trigger_event' => 'ticket.created', 'action_type' => 'send_webhook', 'action_config' => ['url' => 'https://example.com/h', 'secret' => 'topsecret']]);
$rHook = (int) ($j['rule']['rule_id'] ?? 0);
$ok($c === 201 && $j['rule']['action_config']['has_secret'] === true && !str_contains(json_encode($j), 'topsecret'), 'a webhook secret is stored but never returned');
[$c, $j, $raw] = call($base, 'GET', "/automation_rules/$rHook", $admin);
$ok($c === 200 && !str_contains($raw, 'topsecret') && $j['rule']['last_run'] === null, 'get: no secret, no last run yet');
[$c, $j, $raw] = call($base, 'GET', '/automation_rules?limit=1', $admin);
$ok($c === 200 && count($j['rules']) === 1 && $j['total'] === 3 && !str_contains($raw, 'topsecret'), 'list: paging and totals, no secrets');
[$c, $j] = call($base, 'GET', '/automation_rules?action_type=run_script', $admin);
$ok($c === 200 && $j['total'] === 1 && $j['rules'][0]['rule_id'] === $rScript, 'list: filter by action');
[$c, $j] = call($base, 'GET', '/automation_rules?q=cert&enabled=0', $admin);
$ok($c === 200 && $j['total'] === 1 && $j['rules'][0]['rule_id'] === $rid, 'list: search and enabled filter');
[$c, $j] = call($base, 'GET', "/automation_rules?q=" . rawurlencode("' OR 1=1 -- "), $admin);
$ok($c === 200 && $j['total'] === 0, 'list: a quote in the search is just text');
// patch
[$c, $j] = call($base, 'PATCH', "/automation_rules/$rid", $admin, ['is_enabled' => true]);
$ok($c === 200 && $j['rule']['is_enabled'] === true && $j['rule']['action_config']['destination'] === 'it-alerts' && $j['rule']['conditions']['conditions'][1]['field'] === 'days_left', 'patch: changes only what was sent; action and conditions are kept');
[$c, $j] = call($base, 'PATCH', "/automation_rules/$rHook", $admin, ['priority' => 5]);
$ok($c === 200 && $j['rule']['priority'] === 5 && $j['rule']['action_config']['has_secret'] === true, 'patch: a webhook rule keeps its secret when action_config is not sent');
[$c, $j] = call($base, 'PATCH', "/automation_rules/$rid", $admin, ['action_type' => 'notify_user']);
$ok($c === 422 && str_contains($j['error'], 'action_config'), 'patch: changing the action needs a new action_config');
[$c, $j] = call($base, 'PATCH', "/automation_rules/$rid", $admin, ['conditions' => null]);
$ok($c === 200 && $j['rule']['conditions']['conditions'] === [], 'patch: conditions null clears them');
[$c, $j] = call($base, 'PUT', "/automation_rules/$rid", $admin, ['name' => 'only a name']);
$ok($c === 422, 'put needs the whole rule');
[$c, $j] = call($base, 'PUT', "/automation_rules/$rid", $admin, ['name' => 'Cert chat v2', 'trigger_event' => 'certificate.expiring', 'action_type' => 'notify_chat', 'action_config' => ['destination' => 'oncall', 'message' => 'm'], 'is_enabled' => false]);
$ok($c === 200 && $j['rule']['name'] === 'Cert chat v2' && $j['rule']['action_config']['destination'] === 'oncall' && $j['rule']['is_enabled'] === false, 'put replaces the rule');
[$c] = call($base, 'GET', '/automation_rules/999999', $admin);
$ok($c === 404, 'unknown rule: 404');
[$c] = call($base, 'GET', '/automation_rules/nonsense', $admin);
$ok($c === 404, 'unknown sub-route: 404');
[$c] = call($base, 'DELETE', '/automation_rules', $admin);
$ok($c === 405, 'DELETE on the collection: 405');

// runs + retry: a rule that fails because its recipient is archived, then works once the recipient is back
[$c, $j] = call($base, 'POST', '/automation_rules', $admin, ['name' => 'mail the recipient', 'trigger_event' => 'certificate.expiring', 'action_type' => 'send_mail', 'is_enabled' => true,
    'action_config' => ['to_type' => 'user', 'user_id' => 3402, 'subject' => 'Cert {certificate_name}', 'body' => 'It expires']]);
$rMail = (int) ($j['rule']['rule_id'] ?? 0);
$ok($c === 201 && $rMail > 0, 'create a send_mail rule');
$q("UPDATE users SET user_archived_at = NOW() WHERE user_id = 3402");
$engine = new \ITFlow\Automation\RuleEngine($mysqli, new \ITFlow\Workflow\LiveActionGateway($mysqli));
$ctx = \ITFlow\Automation\EventPayloads::context('certificate.expiring', ['certificate_name' => 'web', 'client_id' => 0]);
$failed = $engine->run($rMail, 'certificate.expiring', $ctx, 1);
$ok($failed['status'] === 'failed', 'the rule fails while its recipient is archived');
[$c, $j] = call($base, 'GET', "/automation_rules/runs?rule_id=$rMail&failed=1", $admin);
$run = $j['runs'][0] ?? [];
$ok($c === 200 && $j['total'] === 1 && $run['run_id'] === $failed['run_id'] && $run['attempt'] === 1 && $run['can_rerun'] === true && $run['max_attempts'] === 3 && $j['counts']['failed'] === 1, 'runs: the failed run with attempt, can_rerun and counts');
[$c, $j] = call($base, 'GET', "/automation_rules/runs/{$failed['run_id']}", $admin);
$ok($c === 200 && $j['run']['run_id'] === $failed['run_id'] && !isset($j['run']['context_json']), 'one run (the stored event itself is not exposed)');
[$c] = call($base, 'GET', '/automation_rules/runs/999999', $admin);
$ok($c === 404, 'unknown run: 404');
[$c, $j] = call($base, 'POST', "/automation_rules/retry/{$failed['run_id']}", $tech);
$ok($c === 403, 'a non-administrator cannot retry');
[$c, $j] = call($base, 'POST', "/automation_rules/retry/{$failed['run_id']}", $admin);
$ok($c === 200 && $j['status'] === 'failed' && $j['retry_of_run_id'] === $failed['run_id'], 'retry while the cause is still there: a new failed run, linked');
$q("UPDATE users SET user_archived_at = NULL WHERE user_id = 3402");
[$c, $j] = call($base, 'POST', "/automation_rules/retry/{$failed['run_id']}", $admin);
$ok($c === 200 && $j['status'] === 'ok' && $j['run_id'] > $failed['run_id'], 'retry once the cause is fixed: ok');
$ok((int) $one("SELECT COUNT(*) FROM email_queue WHERE email_recipient = 'recipient@api33.test'") === 1, 'exactly one email was queued (queue table only; nothing is sent here)');
[$c, $j] = call($base, 'POST', "/automation_rules/retry/{$failed['run_id']}", $admin);
$ok($c === 409 && str_contains($j['error'], 'already repeated'), 'a second retry is refused (409): nothing is done twice');
[$c, $j] = call($base, 'POST', "/automation_rules/retry/" . (int) $one("SELECT MAX(run_id) FROM automation_rule_runs WHERE status='ok'"), $admin);
$ok($c === 409 && str_contains($j['error'], 'Only a failed'), 'retry of a run that did not fail: 409');
[$c] = call($base, 'GET', "/automation_rules/retry/{$failed['run_id']}", $admin);
$ok($c === 405, 'retry is POST only');
[$c, $j] = call($base, 'GET', "/automation_rules/runs?status=ok&event=certificate.expiring&limit=5", $admin);
$ok($c === 200 && $j['total'] >= 1 && $j['runs'][0]['source'] === 'manual', 'runs: filters, and the retry shows source manual');
$ok((int) $one("SELECT COUNT(*) FROM audit_events WHERE event_type='automation.rule_rerun'") === 2, 'every manual re-run is in the audit trail');
// delete
[$c, $j] = call($base, 'DELETE', "/automation_rules/$rHook", $admin);
$ok($c === 200 && $j['deleted'] === true && (int) $one("SELECT COUNT(*) FROM automation_rules WHERE rule_id = $rHook") === 0, 'delete');
[$c] = call($base, 'GET', "/automation_rules/$rHook", $admin);
$ok($c === 404, 'deleted rule: 404');

$cleanup();
echo $fails === 0 ? "\nALL PASSED\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
