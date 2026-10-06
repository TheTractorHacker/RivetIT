<?php
/*
 * Mobile API (api/v1/approvals, service_catalog, workflow_tasks, ticket_attachments, notification routing), over REAL HTTP against
 * a `php -S` instance and real API tokens, on a SCRATCH database that holds the fully migrated schema.
 *
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=scratch_x RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/mobile_api.php
 *
 * config.php in the repo root must point at the SAME scratch database (scripts/setup_cli.php --config-only); the test refuses to run
 * otherwise, so it can never touch a live database. It wipes and reseeds its own rows, and starts/stops its own server.
 */
if (getenv('RIVETIT_TEST_DB') !== '1') exit(2);
if (!preg_match('/scratch/i', (string) getenv('RIVETIT_TEST_DB_NAME'))) { fwrite(STDERR, "Refusing: DB name must contain 'scratch'\n"); exit(2); }
$root = dirname(__DIR__);
$cfg = @file_get_contents("$root/config.php");
if (!$cfg || !preg_match('/\$database\s*=\s*[\'"]' . preg_quote(getenv('RIVETIT_TEST_DB_NAME'), '/') . '[\'"]/', $cfg)) {
    fwrite(STDERR, "Refusing: config.php must point at the same scratch database\n"); exit(2);
}
mysqli_report(MYSQLI_REPORT_OFF);
$db = new mysqli('localhost', getenv('RIVETIT_TEST_DB_USER'), getenv('RIVETIT_TEST_DB_PASS'), getenv('RIVETIT_TEST_DB_NAME'));
if ($db->connect_errno) { fwrite(STDERR, "connect failed\n"); exit(2); }
date_default_timezone_set('UTC');
$db->query("SET SESSION sql_mode=''");
$q = fn(string $sql) => $db->query($sql);
$one = fn(string $sql) => ($r = $db->query($sql)) ? ($r->fetch_row()[0] ?? null) : null;
$fails = 0;
$ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };

// ------------------------------------------------------------------ seed
foreach (['api_tokens', 'notifications', 'logs', 'audit_events', 'ticket_attachments', 'ticket_replies', 'tickets', 'service_catalog_request_approvals', 'service_catalog_requests',
          'service_catalog_approval_steps', 'service_catalog_fields', 'service_catalog_items', 'workflow_task_log', 'workflow_run_tasks', 'workflow_runs', 'contacts', 'user_client_permissions',
          'user_role_permissions', 'modules', 'users', 'user_roles', 'ticket_statuses', 'clients', 'categories'] as $t) { $q("DELETE FROM $t"); }
$q("UPDATE settings SET config_ticket_prefix='T', config_ticket_next_number=1000, config_ticket_default_status_id=0 WHERE company_id=1");
foreach ([[1, 'New'], [2, 'Open'], [3, 'On Hold'], [4, 'Resolved'], [5, 'Closed'], [6, 'Assigned']] as [$id, $n]) {
    $q("INSERT INTO ticket_statuses SET ticket_status_id=$id, ticket_status_name='$n', ticket_status_color='#000', ticket_status_active=1, ticket_status_pauses_sla=" . ($n === 'On Hold' ? 1 : 0));
}
$q("INSERT INTO clients SET client_id=1, client_name='Dept A'"); $q("INSERT INTO clients SET client_id=2, client_name='Dept B'");
foreach (['module_support' => 1, 'module_client' => 2, 'module_assets' => 3, 'module_kb' => 4] as $m => $id) { $q("INSERT INTO modules SET module_id=$id, module_name='$m'"); }
// roles: 1 admin, 2 technician (support+client full), 3 limited (KB only), 4 viewer (support full, Departments view only)
foreach ([[1, 'Admin', 1], [2, 'Tech', 0], [3, 'Limited', 0], [4, 'Viewer', 0]] as [$id, $n, $adm]) { $q("INSERT INTO user_roles SET role_id=$id, role_name='$n', role_is_admin=$adm, role_type=1"); }
foreach ([[2, 1, 3], [2, 2, 3], [2, 3, 3], [3, 4, 1], [4, 1, 3], [4, 2, 1], [4, 3, 3]] as [$r, $m, $l]) { $q("INSERT INTO user_role_permissions SET user_role_id=$r, module_id=$m, user_role_permission_level=$l"); }
// users: 1 admin, 10 T1 (approver), 11 T2 (requester), 12 limited, 13 R (department A only), 14 viewer
foreach ([[1, 1], [10, 2], [11, 2], [12, 3], [13, 2], [14, 4]] as [$id, $role]) {
    $q("INSERT INTO users SET user_id=$id, user_name='User $id', user_email='u$id@example.test', user_password='x', user_type=1, user_status=1, user_role_id=$role");
}
$q("INSERT INTO user_client_permissions SET user_id=13, client_id=1");
$q("INSERT INTO contacts SET contact_id=100, contact_name='Pat Employee', contact_email='pat@example.test', contact_client_id=1, contact_primary=1");
$q("INSERT INTO contacts SET contact_id=200, contact_name='Bo Other', contact_email='bo@example.test', contact_client_id=2, contact_primary=1");
$tokens = [];
foreach ([1 => 'admin', 10 => 't1', 11 => 't2', 12 => 'limited', 13 => 'r', 14 => 'viewer'] as $uid => $name) {
    $tokens[$name] = bin2hex(random_bytes(20));
    $q("INSERT INTO api_tokens SET token_user_id=$uid, token_hash='" . hash('sha256', $tokens[$name]) . "', token_created_at=NOW(), token_last_used_at=NOW()");
}

// ------------------------------------------------------------------ server
$sock = stream_socket_server('tcp://127.0.0.1:0', $en, $es); $port = (int) explode(':', stream_socket_get_name($sock, false))[1]; fclose($sock);
$logf = sys_get_temp_dir() . "/mobile_api_server_$port.log";
$proc = proc_open([PHP_BINARY, '-d', 'upload_max_filesize=1M', '-d', 'post_max_size=3M', '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', "error_log=$logf",
    '-S', "127.0.0.1:$port", "$root/tests/mobile_api_router.php"], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $logf, 'a'], 2 => ['file', $logf, 'a']], $pipes, $root);
register_shutdown_function(function () use ($proc) { if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); } });
for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', $port)) break; usleep(100000); }
$base = "http://127.0.0.1:$port";

/** @return array{0:int,1:array,2:mixed,3:string} status, lowercased headers, decoded JSON (or null), raw body */
function http(string $method, string $path, ?string $token, $body = null, array $headers = []): array {
    global $base;
    $ch = curl_init($base . $path);
    $h = $headers;
    if ($token) $h[] = "Authorization: Bearer $token";
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 60]);
    if ($body !== null) {
        if (is_array($body) && !isset($body['__multipart'])) { $h[] = 'Content-Type: application/json'; curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
        elseif (is_array($body)) { unset($body['__multipart']); curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
        else { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $head = substr((string) $raw, 0, $hs); $content = substr((string) $raw, $hs);
    $hdrs = [];
    foreach (explode("\r\n", $head) as $line) { if (strpos($line, ':') !== false) { [$k, $v] = explode(':', $line, 2); $hdrs[strtolower(trim($k))][] = trim($v); } }
    return [$code, $hdrs, json_decode($content, true), $content];
}
function upload(string $token, array $fields, ?array $file): array {
    $p = $fields;
    if ($file) { $p['file'] = new CURLFile($file['path'], $file['type'] ?? 'application/octet-stream', $file['name']); }
    $p['__multipart'] = 1;
    return http('POST', '/api/v1/ticket_attachments.php', $token, $p);
}
$T = $tokens;
$PNG = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$tmp = sys_get_temp_dir() . '/mobile_api_' . bin2hex(random_bytes(4)); mkdir($tmp);
file_put_contents("$tmp/ok.png", $PNG);
file_put_contents("$tmp/script.php", '<?php echo 1;');
file_put_contents("$tmp/lie.png", '<?php system($_GET["c"]); ?>');
file_put_contents("$tmp/page.txt", '<html><script>alert(1)</script></html>');
file_put_contents("$tmp/note.txt", "hello mobile\n");
file_put_contents("$tmp/big.txt", str_repeat('A', 2 * 1024 * 1024));
file_put_contents("$tmp/huge.txt", str_repeat('A', 5 * 1024 * 1024));

// ------------------------------------------------------------------ auth + limited logins
$endpoints = ['/api/v1/approvals.php', '/api/v1/service_catalog.php', '/api/v1/workflow_tasks.php?scope=mine', '/api/v1/ticket_attachments.php?ticket_id=1'];
foreach ($endpoints as $e) {
    [$c] = http('GET', $e, null);            $ok($c === 401, "no token -> 401: $e");
    [$c] = http('GET', $e, 'not-a-token');   $ok($c === 401, "bad token -> 401: $e");
    [$c] = http('GET', $e, $T['limited']);   $ok($c === 403, "limited (module-only) login -> 403: $e");
}
[$c] = http('POST', '/api/v1/approvals.php', $T['limited'], ['kind' => 'catalog_request', 'id' => 1, 'decision' => 'approve']); $ok($c === 403, 'limited POST approvals -> 403');
[$c] = http('POST', '/api/v1/service_catalog.php', $T['limited'], ['catalog_item_id' => 1, 'answers' => new stdClass]); $ok($c === 403, 'limited POST catalog -> 403');
[$c] = http('POST', '/api/v1/workflow_tasks.php', $T['limited'], ['id' => 1, 'action' => 'complete']); $ok($c === 403, 'limited POST workflow task -> 403');
[$c] = upload($T['limited'], ['ticket_id' => 1], ['path' => "$tmp/ok.png", 'name' => 'ok.png']); $ok($c === 403, 'limited upload -> 403');
[$c] = http('GET', '/api/v1/me.php', $T['limited']); $ok($c === 200, 'limited can still read /me (sanity)');

// ------------------------------------------------------------------ service catalog
$q("INSERT INTO service_catalog_items SET catalog_item_id=1, name='New laptop', description='Order a laptop', icon='fa-laptop', ticket_subject_template='Laptop request', default_priority='high', requires_approval=1, risk_score=5, sort_order=1");
$q("INSERT INTO service_catalog_items SET catalog_item_id=2, name='Password reset', description='', icon=NULL, ticket_subject_template='Password reset', requires_approval=0, sort_order=2");
$q("INSERT INTO service_catalog_items SET catalog_item_id=3, name='Retired item', is_active=0, sort_order=3");
$q("INSERT INTO service_catalog_approval_steps SET catalog_item_id=1, step_order=1, approver_type='user', approver_id=10, mode='any'");
foreach ([['reason', 'Reason', 'text', '', 1], ['qty', 'Quantity', 'number', '', 0], ['model', 'Model', 'select', "Standard\nPower", 0], ['rush', 'Rush', 'checkbox', '', 0], ['needed', 'Needed by', 'date', '', 0], ['notes', 'Notes', 'textarea', '', 0]] as $i => [$k, $l, $t, $o, $req]) {
    $q("INSERT INTO service_catalog_fields SET catalog_item_id=1, field_key='$k', label='$l', field_type='$t', options='" . $db->real_escape_string($o) . "', is_required=$req, placeholder='ph $k', sort_order=$i");
}
[$c, , $j] = http('GET', '/api/v1/service_catalog.php', $T['t2']);
$ok($c === 200 && count($j['items']) === 2, 'catalog GET lists active items only');
$laptop = $j['items'][0] ?? [];
$ok(($laptop['id'] ?? 0) === 1 && $laptop['name'] === 'New laptop' && $laptop['requires_approval'] === true && $laptop['risk_score'] === 5 && $laptop['icon'] === 'fa-laptop', 'catalog item shape + types');
$ok(count($laptop['fields']) === 6 && array_keys($laptop['fields'][0]) === ['key', 'label', 'type', 'options', 'required', 'placeholder', 'show_if'], 'catalog field shape (key,label,type,options,required,placeholder,show_if)');
$ok($laptop['fields'][0]['required'] === true && $laptop['fields'][0]['show_if'] === null && $laptop['fields'][2]['options'] === ['Standard', 'Power'], 'required bool, show_if null, select options array');
$ok($j['items'][1]['icon'] === 'fa-ticket-alt' && $j['items'][1]['requires_approval'] === false && $j['items'][1]['fields'] === [], 'icon default, no-form item');
$ok($j['popular'] === [] && $j['recent'] === [], 'popular/recent empty before any request');
[$c] = http('GET', '/api/v1/service_catalog.php', $T['r']); $ok($c === 200, 'catalog GET for department-restricted tech ok');

$post = fn(string $tok, $body) => http('POST', '/api/v1/service_catalog.php', $tok, $body);
[$c, , $j] = $post($T['t2'], ['catalog_item_id' => 1, 'answers' => ['qty' => '2'], 'client_id' => 1]);
$ok($c === 422 && stripos($j['error'], 'Reason is required') !== false, 'required field missing -> 422');
[$c] = $post($T['t2'], ['catalog_item_id' => 1, 'answers' => ['reason' => 'x', 'qty' => 'abc'], 'client_id' => 1]); $ok($c === 422, 'number type enforced -> 422');
[$c] = $post($T['t2'], ['catalog_item_id' => 1, 'answers' => ['reason' => 'x', 'model' => 'Gold'], 'client_id' => 1]); $ok($c === 422, 'select choice enforced -> 422');
[$c] = $post($T['t2'], ['catalog_item_id' => 1, 'answers' => ['reason' => 'x', 'needed' => '2026-13-45'], 'client_id' => 1]); $ok($c === 422, 'date enforced -> 422');
[$c] = $post($T['t2'], ['catalog_item_id' => 1, 'answers' => ['reason' => ['a']], 'client_id' => 1]); $ok($c === 422, 'array value rejected -> 422');
[$c] = $post($T['t2'], ['catalog_item_id' => 99, 'answers' => new stdClass, 'client_id' => 1]); $ok($c === 404, 'unknown item -> 404');
[$c] = $post($T['t2'], ['catalog_item_id' => 3, 'answers' => new stdClass, 'client_id' => 1]); $ok($c === 404, 'inactive item -> 404');
[$c] = $post($T['t2'], ['catalog_item_id' => 'x', 'answers' => new stdClass]); $ok($c === 422, 'bad catalog_item_id -> 422');
[$c] = $post($T['r'], ['catalog_item_id' => 2, 'answers' => new stdClass, 'client_id' => 2]); $ok($c === 403, 'department-restricted user cannot raise for another department -> 403');
[$c] = $post($T['t2'], ['catalog_item_id' => 2, 'answers' => new stdClass, 'client_id' => 999]); $ok($c === 422, 'unknown client -> 422');
[$c] = $post($T['viewer'], ['catalog_item_id' => 2, 'answers' => new stdClass, 'client_id' => 1]); $ok($c === 201, 'viewer role (support=3) may raise');

[$c, , $j] = $post($T['t2'], ['catalog_item_id' => 2, 'answers' => ['junk' => 'x'], 'client_id' => 1]);
$ok($c === 201 && $j['ok'] === true && $j['status'] === 'created' && $j['ticket_id'] > 0, 'no-approval item -> created');
$plain_ticket = $j['ticket_id'];
$ok($one("SELECT COUNT(*) FROM service_catalog_requests WHERE ticket_id=$plain_ticket") == 0, 'item without form/approval writes no request row');
$ok($one("SELECT ticket_created_by FROM tickets WHERE ticket_id=$plain_ticket") == 11 && $one("SELECT ticket_catalog_item_id FROM tickets WHERE ticket_id=$plain_ticket") == 2, 'requester = API user, item remembered');

// hidden/unknown-field smuggling: undefined keys are never stored
[$c, , $j] = $post($T['t2'], ['catalog_item_id' => 1, 'answers' => ['reason' => 'Need it', 'qty' => 2, 'model' => 'Power', 'rush' => true, 'needed' => '2026-12-01', 'notes' => 'n', 'is_admin' => '1', 'ticket_priority' => 'Critical'], 'client_id' => 1]);
$ok($c === 201 && $j['status'] === 'pending_approval', 'approval item -> pending_approval');
$tk1 = $j['ticket_id'];
$req1 = intval($one("SELECT request_id FROM service_catalog_requests WHERE ticket_id=$tk1"));
$vals = json_decode($one("SELECT field_values FROM service_catalog_requests WHERE ticket_id=$req1 OR ticket_id=$tk1 LIMIT 1"), true);
$keys = array_column($vals, 'key'); sort($keys);
$ok($keys === ['model', 'needed', 'notes', 'qty', 'reason', 'rush'] && !in_array('is_admin', $keys), 'only defined fields stored (smuggled keys dropped)');
$byk = array_column($vals, 'value', 'key');
$ok($byk['rush'] === 'Yes' && $byk['qty'] === '2', 'JSON bool/number normalised like the web form');
$ok($one("SELECT ticket_status FROM tickets WHERE ticket_id=$tk1") == 3 && $one("SELECT ticket_priority FROM tickets WHERE ticket_id=$tk1") === 'High', 'ticket held (On Hold) and priority from the item, not from the payload');
$ok($one("SELECT COUNT(*) FROM service_catalog_request_approvals WHERE request_id=$req1 AND approver_user_id=10 AND status='pending'") == 1, 'approval row addressed to the step approver');

[$c, , $j] = http('GET', '/api/v1/service_catalog.php', $T['t2']);
$ok($j['recent'] === [2, 1] || $j['recent'] === [1, 2], 'recent = this user\'s distinct items');
[$c, , $j2] = http('GET', '/api/v1/service_catalog.php', $T['t1']); $ok($j2['recent'] === [], 'recent is per user');
$ok(count($j['popular']) >= 1 && count($j['popular']) <= 5 && $j['popular'][0] === 2, 'popular = trending ids (30 days)');

// ------------------------------------------------------------------ notification routing for the catalog approval
$n = $db->query("SELECT notification_type, notification_action FROM notifications WHERE notification_user_id=10 AND notification_action LIKE '%service_catalog_approvals.php%'")->fetch_assoc();
$ok($n && $n['notification_type'] === 'Ticket' && $n['notification_action'] === "/agent/service_catalog_approvals.php?request_id=$req1", 'approver got the same notification as before (type Ticket, same recipient), action now carries the request id');
[$c, , $j] = http('GET', '/api/v1/notifications.php', $T['t1']);
$appr = array_values(array_filter($j['data'] ?? [], fn($x) => ($x['type'] ?? '') === 'approval'));
$ok($c === 200 && count($appr) === 1 && $appr[0]['kind'] === 'catalog_request' && $appr[0]['ref_id'] === $req1, 'GET /notifications: type=approval, kind, ref_id');
[$c, , $j] = http('GET', '/api/v1/notifications.php', $T['t2']);
$ok(!array_filter($j['data'] ?? [], fn($x) => ($x['type'] ?? '') === 'approval'), 'requester is not notified as approver');
$src = file_get_contents("$root/functions.php");
preg_match('/function approvalRouteFromAction.*?\n}\n/s', $src, $m); eval($m[0]);
$ok(approvalRouteFromAction("/agent/service_catalog_approvals.php?request_id=42") === ['kind' => 'catalog_request', 'id' => 42]
    && approvalRouteFromAction('workflow_run.php?run_id=7&approval_task=9') === ['kind' => 'workflow_task', 'id' => 9]
    && approvalRouteFromAction('workflow_run.php?run_id=7') === null && approvalRouteFromAction('/agent/ticket.php?ticket_id=3') === null
    && approvalRouteFromAction(null) === null && approvalRouteFromAction('/agent/service_catalog_approvals.php?request_id=4x') === null, 'approvalRouteFromAction (push payload + API type) parses exactly the approval routes');
$ok(strpos($src, "'type' => 'approval', 'kind' => \$approval['kind'], 'id' => (string) \$approval['id']") !== false, 'notifyUser push payload carries type/kind/id for approval routes');

// ------------------------------------------------------------------ approvals: catalog
[$c, , $j] = http('GET', '/api/v1/approvals.php', $T['t1']);
$ok($c === 200 && $j['counts']['total'] === 1 && count($j['items']) === 1, 'approver sees exactly their pending item');
$it = $j['items'][0] ?? [];
$ok(array_keys($it) === ['kind', 'id', 'title', 'requester', 'summary', 'risk_score', 'step', 'ticket_id', 'requested_at', 'due_at', 'fields'], 'approval item has the contract fields in order');
$ok($it['kind'] === 'catalog_request' && $it['id'] === $req1 && $it['title'] === 'New laptop' && $it['requester'] === 'User 11' && $it['risk_score'] === 5 && $it['step'] === 'Step 1' && $it['ticket_id'] === $tk1 && $it['due_at'] === null, 'catalog approval values');
$ok(preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $it['requested_at']) === 1 && count($it['fields']) === 6 && $it['fields'][0] === ['label' => 'Reason', 'value' => 'Need it'], 'requested_at format, fields label/value');
[$c, , $j] = http('GET', '/api/v1/approvals.php', $T['t2']); $ok($c === 200 && $j['items'] === [] && $j['counts']['total'] === 0, 'a non-approver sees nothing');
[$c, , $j] = http('GET', '/api/v1/approvals.php', $T['admin']); $ok($c === 200 && $j['counts']['total'] === 1, 'admin sees the pending request (override list)');
[$c] = http('GET', '/api/v1/approvals.php', $T['r']); $ok($c === 200, 'tech with modules gets 200');

$dec = fn(string $tok, $b) => http('POST', '/api/v1/approvals.php', $tok, $b);
[$c, , $j] = $dec($T['t2'], ['kind' => 'catalog_request', 'id' => $req1, 'decision' => 'approve']); $ok($c === 404, "someone else's approval id -> 404 (IDOR)");
[$c, , $j9] = $dec($T['t2'], ['kind' => 'catalog_request', 'id' => 99999, 'decision' => 'approve']); $ok($c === 404 && $j9 === $j, 'unknown id -> identical 404 (no existence leak)');
$ok($one("SELECT status FROM service_catalog_requests WHERE request_id=$req1") === 'pending_approval', 'IDOR attempt changed nothing');
[$c] = $dec($T['t1'], ['kind' => 'catalog_request', 'id' => $req1, 'decision' => 'reject']); $ok($c === 422, 'reject without comment -> 422');
[$c] = $dec($T['t1'], ['kind' => 'catalog_request', 'id' => $req1, 'decision' => 'maybe']); $ok($c === 422, 'bad decision -> 422');
[$c] = $dec($T['t1'], ['kind' => 'nope', 'id' => $req1, 'decision' => 'approve']); $ok($c === 422, 'bad kind -> 422');
[$c] = $dec($T['t1'], ['kind' => 'catalog_request', 'id' => '1', 'decision' => 'approve']); $ok($c === 422, 'non-integer id -> 422');
[$c] = $dec($T['limited'], ['kind' => 'catalog_request', 'id' => $req1, 'decision' => 'approve']); $ok($c === 403, 'limited -> 403');
$logs0 = intval($one("SELECT COUNT(*) FROM logs WHERE log_type='Service Catalog'"));
[$c, , $j] = $dec($T['t1'], ['kind' => 'catalog_request', 'id' => $req1, 'decision' => 'approve', 'comment' => 'ok go']);
$ok($c === 200 && $j === ['ok' => true, 'status' => 'approved'], 'approve -> {ok:true,status:approved}');
$ok($one("SELECT status FROM service_catalog_requests WHERE request_id=$req1") === 'approved' && $one("SELECT comment FROM service_catalog_request_approvals WHERE request_id=$req1 AND approver_user_id=10") === 'ok go', 'DB: request approved, comment stored on the approval row');
$ok($one("SELECT ticket_status FROM tickets WHERE ticket_id=$tk1") == 1, 'DB: ticket released from On Hold to its creation status');
$ok($one("SELECT COUNT(*) FROM ticket_replies WHERE ticket_reply_ticket_id=$tk1 AND ticket_reply LIKE '%All approvals received%'") == 1, 'DB: system note written by the service');
$ok(intval($one("SELECT COUNT(*) FROM logs WHERE log_type='Service Catalog' AND log_user_id=10")) === $logs0 + 1, 'audit: Service Catalog / Approve log row written for the approver');
$ok($one("SELECT COUNT(*) FROM notifications WHERE notification_user_id=11 AND notification LIKE 'Request approved%'") == 1, 'requester notified of the outcome (service notification path)');
[$c] = $dec($T['t1'], ['kind' => 'catalog_request', 'id' => $req1, 'decision' => 'approve']); $ok($c === 404, 'replay of a decided approval -> 404');
[$c, , $j] = http('GET', '/api/v1/approvals.php', $T['t1']); $ok($j['counts']['total'] === 0, 'decided item leaves the list');

[$c, , $j] = $post($T['t2'], ['catalog_item_id' => 1, 'answers' => ['reason' => 'second'], 'client_id' => 1]);
$tk2 = $j['ticket_id']; $req2 = intval($one("SELECT request_id FROM service_catalog_requests WHERE ticket_id=$tk2"));
[$c, , $j] = $dec($T['t1'], ['kind' => 'catalog_request', 'id' => $req2, 'decision' => 'reject', 'comment' => 'Not budgeted']);
$ok($c === 200 && $j === ['ok' => true, 'status' => 'rejected'], 'reject -> status rejected');
$ok($one("SELECT status FROM service_catalog_requests WHERE request_id=$req2") === 'rejected' && $one("SELECT rejection_reason FROM service_catalog_requests WHERE request_id=$req2") === 'Not budgeted' && $one("SELECT ticket_status FROM tickets WHERE ticket_id=$tk2") == 5 && $one("SELECT ticket_closed_at IS NOT NULL FROM tickets WHERE ticket_id=$tk2") == 1, 'DB: rejected, reason stored, ticket closed');
$ok(intval($one("SELECT COUNT(*) FROM logs WHERE log_type='Service Catalog' AND log_action='Reject' AND log_user_id=10")) === 1, 'audit: Reject log row');

// admin override path (request addressed to someone else)
[$c, , $j] = $post($T['t2'], ['catalog_item_id' => 1, 'answers' => ['reason' => 'third'], 'client_id' => 1]);
$tk3 = $j['ticket_id']; $req3 = intval($one("SELECT request_id FROM service_catalog_requests WHERE ticket_id=$tk3"));
[$c, , $j] = $dec($T['admin'], ['kind' => 'catalog_request', 'id' => $req3, 'decision' => 'approve']);
$ok($c === 200 && $j['status'] === 'approved' && strpos((string) $one("SELECT comment FROM service_catalog_request_approvals WHERE request_id=$req3 AND status='approved'"), '[Administrator override]') === 0, 'admin may decide on behalf (recorded as override), like the web page');

// ------------------------------------------------------------------ workflow fixtures
$q("INSERT INTO workflow_runs SET run_id=1, contact_id=100, type='onboarding', status='in_progress', started_by=1");
$q("INSERT INTO workflow_runs SET run_id=2, contact_id=200, type='offboarding', status='in_progress', started_by=1");
$q("INSERT INTO workflow_runs SET run_id=3, contact_id=100, type='onboarding', status='cancelled', started_by=1");
$mk = function (int $id, int $run, string $title, string $status, string $type = 'manual', $assignee = 'NULL', $due = 'NULL', string $dep = 'NULL', string $extra = '') use ($q) {
    $q("INSERT INTO workflow_run_tasks SET run_task_id=$id, run_id=$run, title='$title', instructions='do $title', status='$status', task_type='$type', assignee_user_id=$assignee, due_at=$due, depends_on=$dep, sort_order=$id $extra");
};
$mk(1, 1, 'Create account', 'pending', 'manual', 10);
$mk(2, 1, 'Order desk', 'pending');
$mk(3, 1, 'Hand over laptop', 'blocked', 'manual', 10, 'NULL', "'1'");
$mk(4, 1, 'Manager sign-off', 'pending', 'approval', 'NULL', 'NULL', 'NULL', ", approver_type='user', approver_user_id=10, approval_status='pending'");
$mk(5, 1, 'T2 private chore', 'pending', 'manual', 11);
$mk(6, 1, 'Late thing', 'pending', 'manual', 10, "'2020-01-01 09:00:00'");
$mk(7, 1, 'Second sign-off', 'pending', 'approval', 'NULL', 'NULL', 'NULL', ", approver_type='user', approver_user_id=10, approval_status='pending', approval_notified_at=NOW()");
$mk(8, 1, 'Done already', 'completed');
$mk(41, 1, 'R sign-off', 'pending', 'approval', 'NULL', 'NULL', 'NULL', ", approver_type='user', approver_user_id=13, approval_status='pending', approval_notified_at=NOW()");
$mk(20, 2, 'Dept B chore', 'pending', 'manual', 13);
$mk(21, 2, 'Dept B approval', 'pending', 'approval', 'NULL', 'NULL', 'NULL', ", approver_type='user', approver_user_id=13, approval_status='pending', approval_notified_at=NOW()");
$mk(30, 3, 'Cancelled run task', 'pending', 'manual', 10);

// ------------------------------------------------------------------ workflow tasks: list
[$c, , $j] = http('GET', '/api/v1/workflow_tasks.php?scope=mine', $T['t1']);
$ids = array_column($j['items'] ?? [], 'id'); sort($ids);
$ok($c === 200 && $ids === [1, 2, 3, 4, 6, 7, 21, 41], 'mine (T1) = assigned to me + unassigned of runs I may see; not T2\'s or R\'s, not completed, not cancelled runs');
$by = array_column($j['items'], null, 'id');
$ok(array_keys($by[3]) === ['id', 'run_id', 'run_title', 'task_title', 'instructions', 'due_at', 'status', 'type', 'blocked_by', 'contact_name', 'assignee'], 'task item has the contract fields in order');
$ok($by[3]['blocked_by'] === ['Create account'] && $by[3]['status'] === 'blocked' && $by[1]['blocked_by'] === [] && $by[4]['type'] === 'approval' && $by[1]['assignee'] === 'User 10' && $by[2]['assignee'] === null && $by[1]['run_title'] === 'Onboarding: Pat Employee' && $by[1]['contact_name'] === 'Pat Employee' && $by[1]['instructions'] === 'do Create account', 'blocked_by, type, assignee, run_title, contact_name');
$ok($j['counts'] === ['open' => 8, 'overdue' => 1], 'counts open/overdue');
[$c, , $j] = http('GET', '/api/v1/workflow_tasks.php?scope=mine', $T['r']);
$ids = array_column($j['items'] ?? [], 'id'); sort($ids);
$ok($c === 200 && $ids === [2, 4, 7, 41], 'department-restricted tech: unassigned tasks of department A only (dept B run invisible, even its task assigned to them)');
$ok(!in_array(20, $ids, true) && !in_array(21, $ids, true), 'tasks of another department never listed');
[$c, , $j] = http('GET', '/api/v1/workflow_tasks.php?scope=all', $T['t1']); $ok($c === 200 && in_array(5, array_column($j['items'], 'id'), true), 'scope=all includes other people\'s tasks (edit access)');
[$c] = http('GET', '/api/v1/workflow_tasks.php?scope=all', $T['viewer']); $ok($c === 403, 'scope=all needs Departments edit -> 403 for a view-only role');
[$c, , $j] = http('GET', '/api/v1/workflow_tasks.php?scope=mine', $T['viewer']); $ok($c === 200, 'view-only role may list mine');
[$c] = http('GET', '/api/v1/workflow_tasks.php?scope=bogus', $T['t1']); $ok($c === 422, 'bad scope -> 422');

// ------------------------------------------------------------------ workflow tasks: act
$wt = fn(string $tok, $b) => http('POST', '/api/v1/workflow_tasks.php', $tok, $b);
[$c, , $j] = $wt($T['t1'], ['id' => 3, 'action' => 'complete']); $ok($c === 422 && stripos($j['error'], 'waiting for the tasks it depends on') !== false, 'blocked task cannot be completed -> 422');
[$c, , $j] = $wt($T['t1'], ['id' => 4, 'action' => 'complete']); $ok($c === 422 && stripos($j['error'], 'needs an approval') !== false, 'approval task cannot be completed by hand -> 422');
[$c, , $j] = $wt($T['t1'], ['id' => 4, 'action' => 'skip', 'reason' => 'nope']); $ok($c === 422 && stripos($j['error'], 'only be skipped by an administrator') !== false, 'non-admin cannot skip an approval -> 422');
[$c] = $wt($T['t1'], ['id' => 2, 'action' => 'skip']); $ok($c === 422, 'skip without reason -> 422');
[$c] = $wt($T['t1'], ['id' => 2, 'action' => 'skip', 'reason' => '   ']); $ok($c === 422, 'skip with blank reason -> 422');
[$c] = $wt($T['t1'], ['id' => 2, 'action' => 'dance']); $ok($c === 422, 'bad action -> 422');
[$c] = $wt($T['viewer'], ['id' => 2, 'action' => 'complete']); $ok($c === 403, 'view-only Departments role cannot work tasks -> 403');
[$c] = $wt($T['r'], ['id' => 20, 'action' => 'complete']); $ok($c === 404, 'task of another department -> 404 (IDOR)');
[$c, , $j9] = $wt($T['r'], ['id' => 9999, 'action' => 'complete']); $ok($c === 404 && $j9 === ['error' => 'Task not found'], 'unknown task -> same 404');
$ok($one("SELECT status FROM workflow_run_tasks WHERE run_task_id=20") === 'pending', 'IDOR attempt changed nothing');
[$c, , $j] = $wt($T['t1'], ['id' => 2, 'action' => 'skip', 'reason' => "Not <b>needed</b> it's fine"]);
$ok($c === 200 && $j === ['ok' => true, 'status' => 'skipped'] && $one("SELECT skip_reason FROM workflow_run_tasks WHERE run_task_id=2") === "Not needed it's fine", 'skip with reason ok (tags stripped, apostrophe kept unescaped)');
[$c, , $j] = $wt($T['t1'], ['id' => 1, 'action' => 'complete']);
$ok($c === 200 && $j === ['ok' => true, 'status' => 'completed'], 'complete a ready task');
$ok($one("SELECT status FROM workflow_run_tasks WHERE run_task_id=3") === 'pending' && $one("SELECT completed_by FROM workflow_run_tasks WHERE run_task_id=1") == 10, 'dependent task unblocked, completed_by = API user');
$ok(intval($one("SELECT COUNT(*) FROM logs WHERE log_type='Contact' AND log_user_id=10 AND log_description LIKE '%completed a workflow task%'")) === 1, 'audit: log row written');
$ok(intval($one("SELECT COUNT(*) FROM notifications WHERE notification_user_id=10 AND notification_action='workflow_run.php?run_id=1&approval_task=4'")) === 1, 'workflow approval request notification now carries approval_task id');
[$c, , $j] = http('GET', '/api/v1/notifications.php', $T['t1']);
$wf = array_values(array_filter($j['data'], fn($x) => ($x['type'] ?? '') === 'approval' && ($x['kind'] ?? '') === 'workflow_task'));
$ok(count($wf) === 1 && $wf[0]['ref_id'] === 4, 'GET /notifications routes the workflow approval as type=approval kind=workflow_task ref_id=run_task_id');
[$c, , $j] = $wt($T['admin'], ['id' => 7, 'action' => 'skip', 'reason' => 'Admin override']);
$ok($c === 200 && $j['status'] === 'skipped' && $one("SELECT COUNT(*) FROM workflow_task_log WHERE run_task_id=7 AND event='approval_overridden'") == 1, 'admin may skip an approval (logged as override)');

// ------------------------------------------------------------------ approvals: workflow
[$c, , $j] = http('GET', '/api/v1/approvals.php', $T['t1']);
$wfi = array_values(array_filter($j['items'], fn($x) => $x['kind'] === 'workflow_task'));
$ok(count($wfi) === 1 && $wfi[0]['id'] === 4 && $wfi[0]['title'] === 'Manager sign-off' && $wfi[0]['risk_score'] === null && $wfi[0]['ticket_id'] === null && $wfi[0]['step'] === null && $wfi[0]['requester'] === 'User 1', 'workflow approval item (null risk/step/ticket)');
$ok(array_keys($wfi[0]) === ['kind', 'id', 'title', 'requester', 'summary', 'risk_score', 'step', 'ticket_id', 'requested_at', 'due_at', 'fields'] && $wfi[0]['fields'][0] === ['label' => 'Person', 'value' => 'Pat Employee'], 'workflow approval shape');
[$c, , $j] = http('GET', '/api/v1/approvals.php', $T['t2']); $ok($j['counts']['total'] === 0, 'T2 (not approver, not admin) sees no workflow approvals');
[$c, , $j] = http('GET', '/api/v1/approvals.php', $T['r']);
$ok(array_column($j['items'], 'id') === [41], 'department-restricted approver sees their approval in department A, not the one in department B');
[$c] = $dec($T['t2'], ['kind' => 'workflow_task', 'id' => 4, 'decision' => 'approve']); $ok($c === 404, "someone else's workflow approval -> 404");
[$c] = $dec($T['r'], ['kind' => 'workflow_task', 'id' => 4, 'decision' => 'approve']); $ok($c === 404, 'approval in a run of another department -> 404');
[$c] = $dec($T['t1'], ['kind' => 'workflow_task', 'id' => 4, 'decision' => 'reject']); $ok($c === 422, 'workflow reject needs a comment -> 422');
[$c, , $j] = $dec($T['t1'], ['kind' => 'workflow_task', 'id' => 4, 'decision' => 'approve', 'comment' => 'looks right']);
$ok($c === 200 && $j === ['ok' => true, 'status' => 'approved'], 'workflow approve');
$ok($one("SELECT approval_status FROM workflow_run_tasks WHERE run_task_id=4") === 'approved' && $one("SELECT status FROM workflow_run_tasks WHERE run_task_id=4") === 'completed' && $one("SELECT approved_by FROM workflow_run_tasks WHERE run_task_id=4") == 10 && $one("SELECT approval_comment FROM workflow_run_tasks WHERE run_task_id=4") === 'looks right', 'DB: task approved by the API user');
$ok($one("SELECT COUNT(*) FROM audit_events WHERE event_type='workflow.approval_approved' AND actor_user_id=10") == 1, 'audit: workflow.approval_approved event recorded by the service');
$ok(intval($one("SELECT COUNT(*) FROM logs WHERE log_type='Contact' AND log_user_id=10 AND log_description LIKE '%workflow approval%'")) === 1, 'audit: log row for the decision');
[$c] = $dec($T['t1'], ['kind' => 'workflow_task', 'id' => 4, 'decision' => 'approve']); $ok($c === 404, 'replaying a decided workflow approval -> 404');
$q("INSERT INTO workflow_run_tasks SET run_task_id=40, run_id=1, title='Reject me', status='pending', task_type='approval', approver_type='user', approver_user_id=10, approval_status='pending', approval_notified_at=NOW(), sort_order=40");
[$c, , $j] = $dec($T['t1'], ['kind' => 'workflow_task', 'id' => 40, 'decision' => 'reject', 'comment' => 'no']);
$ok($c === 200 && $j['status'] === 'rejected' && $one("SELECT status FROM workflow_runs WHERE run_id=1") === 'paused' && $one("SELECT status FROM workflow_run_tasks WHERE run_task_id=40") === 'rejected', 'workflow reject pauses the run like the web handler');
[$c, , $j] = http('GET', '/api/v1/approvals.php', $T['admin']);
$ok(!in_array(40, array_column($j['items'], 'id'), true), 'decided/rejected tasks and paused runs drop out of approvals');

// ------------------------------------------------------------------ ticket attachments
$q("INSERT INTO tickets SET ticket_id=500, ticket_prefix='T', ticket_number=500, ticket_subject='A ticket', ticket_status=1, ticket_client_id=1, ticket_contact_id=100, ticket_created_by=10");
$q("INSERT INTO tickets SET ticket_id=501, ticket_prefix='T', ticket_number=501, ticket_subject='B ticket', ticket_status=1, ticket_client_id=2, ticket_contact_id=200, ticket_created_by=10");
$up = fn(string $tok, array $f, ?array $file) => upload($tok, $f, $file);
[$c, , $j] = $up($T['t1'], ['ticket_id' => 500], ['path' => "$tmp/ok.png", 'name' => 'photo.png', 'type' => 'image/png']);
$ok($c === 201 && $j['ok'] === true && $j['id'] > 0, 'upload png -> {ok:true,id}');
$a1 = $j['id'];
$row = $db->query("SELECT * FROM ticket_attachments WHERE ticket_attachment_id=$a1")->fetch_assoc();
$stored = "$root/uploads/tickets/500/{$row['ticket_attachment_reference_name']}";
$ok($row['ticket_attachment_name'] === 'photo.png' && $row['ticket_attachment_reply_id'] === null && $row['ticket_attachment_ticket_id'] == 500 && isUploadRef($row['ticket_attachment_reference_name']) && $row['ticket_attachment_reference_name'] !== 'photo.png' && is_file($stored) && filesize($stored) === strlen($PNG), 'stored under uploads/tickets/500 with a random reference name, original name kept as display');
function isUploadRef($n) { return preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9]+$/', $n) === 1; }
[$c, , $j] = $up($T['t1'], ['ticket_id' => 500], ['path' => "$tmp/note.txt", 'name' => 'note.txt', 'type' => 'application/x-httpd-php']);
$ok($c === 201, 'client-claimed mime is ignored (txt with a lying Content-Type stored by extension/content)');
$a2 = $j['id'];
$q("INSERT INTO ticket_replies SET ticket_reply_id=900, ticket_reply='r', ticket_reply_type='Public', ticket_reply_by=11, ticket_reply_ticket_id=500");
[$c, , $j] = $up($T['t1'], ['ticket_id' => 500, 'reply_id' => 900], ['path' => "$tmp/note.txt", 'name' => 'on-reply.txt']);
$ok($c === 201 && $one("SELECT ticket_attachment_reply_id FROM ticket_attachments WHERE ticket_attachment_id={$j['id']}") == 900, 'upload tied to a reply of the same ticket');
$a3 = $j['id'];
[$c] = $up($T['t1'], ['ticket_id' => 501, 'reply_id' => 900], ['path' => "$tmp/note.txt", 'name' => 'x.txt']); $ok($c === 403 || $c === 422, 'reply of another ticket refused');
$q("INSERT INTO ticket_replies SET ticket_reply_id=901, ticket_reply='r', ticket_reply_type='Public', ticket_reply_by=11, ticket_reply_ticket_id=501");
[$c] = $up($T['t1'], ['ticket_id' => 500, 'reply_id' => 901], ['path' => "$tmp/note.txt", 'name' => 'x.txt']); $ok($c === 422, 'reply_id that belongs to a different ticket -> 422');

$count0 = intval($one("SELECT COUNT(*) FROM ticket_attachments"));
$files0 = count(glob("$root/uploads/tickets/500/*"));
foreach ([['script.php', "$tmp/script.php"], ['evil.phtml', "$tmp/script.php"], ['evil.PHP', "$tmp/script.php"], ['shell.php.png', "$tmp/ok.png"], ['shell.php.', "$tmp/script.php"], ['noext', "$tmp/note.txt"], ['x.exe', "$tmp/note.txt"], ['x.svg', "$tmp/note.txt"], ['.htaccess', "$tmp/note.txt"], ['evil.php.jpg', "$tmp/lie.png"]] as [$nm, $path]) {
    [$c] = $up($T['t1'], ['ticket_id' => 500], ['path' => $path, 'name' => $nm]);
    $ok($c === 422, "disallowed upload refused: $nm");
}
[$c] = $up($T['t1'], ['ticket_id' => 500], ['path' => "$tmp/lie.png", 'name' => 'lie.png', 'type' => 'image/png']); $ok($c === 422, 'mime lie: PHP source named .png refused by content sniffing');
[$c] = $up($T['t1'], ['ticket_id' => 500], ['path' => "$tmp/page.txt", 'name' => 'page.txt', 'type' => 'text/plain']); $ok($c === 422, 'HTML content refused even with a safe extension');
[$c] = $up($T['t1'], ['ticket_id' => 500], ['path' => "$tmp/big.txt", 'name' => 'big.txt']); $ok($c === 413, 'over upload_max_filesize -> 413');
[$c] = $up($T['t1'], ['ticket_id' => 500], ['path' => "$tmp/huge.txt", 'name' => 'huge.txt']); $ok($c === 413, 'over post_max_size -> 413');
[$c] = $up($T['t1'], ['ticket_id' => 500], null); $ok($c === 422, 'no file -> 422');
[$c] = $up($T['t1'], [], ['path' => "$tmp/note.txt", 'name' => 'a.txt']); $ok($c === 422, 'no ticket_id -> 422');
$ok(intval($one("SELECT COUNT(*) FROM ticket_attachments")) === $count0 && count(glob("$root/uploads/tickets/500/*")) === $files0, 'no refused upload left a row or a file behind');

[$c, , $j] = $up($T['t1'], ['ticket_id' => 500], ['path' => "$tmp/ok.png", 'name' => '../../../../etc/cron.d/evil.png']);
$ok($c === 201, 'traversal-style client filename accepted but neutralised');
$row = $db->query("SELECT * FROM ticket_attachments WHERE ticket_attachment_id={$j['id']}")->fetch_assoc();
$ok($row['ticket_attachment_name'] === 'evil.png' && strpos($row['ticket_attachment_reference_name'], '/') === false && is_file("$root/uploads/tickets/500/{$row['ticket_attachment_reference_name']}") && !file_exists('/etc/cron.d/evil.png'), 'display name reduced to its last component; file stays in the ticket folder under a random name');
$a4 = $j['id'];
[$c, , $j] = $up($T['t1'], ['ticket_id' => 500], ['path' => "$tmp/note.txt", 'name' => "weird \"name\"\r\nX-Injected: 1.txt"]);
$a5 = $j['id'] ?? 0;

// access control on upload
[$c] = $up($T['r'], ['ticket_id' => 501], ['path' => "$tmp/ok.png", 'name' => 'x.png']); $ok($c === 403, "upload to another department's ticket -> 403");
[$c, , $j9] = $up($T['r'], ['ticket_id' => 99999], ['path' => "$tmp/ok.png", 'name' => 'x.png']); $ok($c === 403, 'upload to an unknown ticket -> same 403');
$ok(!is_dir("$root/uploads/tickets/501") || count(glob("$root/uploads/tickets/501/*")) === 0, 'nothing written for the refused department');
[$c] = $up($T['viewer'], ['ticket_id' => 500], ['path' => "$tmp/ok.png", 'name' => 'v.png']); $ok($c === 201, 'support=3 viewer may upload');
$q("UPDATE user_role_permissions SET user_role_permission_level=1 WHERE user_role_id=4 AND module_id=1");
[$c] = $up($T['viewer'], ['ticket_id' => 500], ['path' => "$tmp/ok.png", 'name' => 'v.png']); $ok($c === 403, 'read-only support role cannot upload -> 403');
[$c] = http('GET', '/api/v1/ticket_attachments.php?ticket_id=500', $T['viewer']); $ok($c === 200, 'read-only support role may list');
$q("UPDATE user_role_permissions SET user_role_permission_level=3 WHERE user_role_id=4 AND module_id=1");

// list
[$c, , $j] = http('GET', '/api/v1/ticket_attachments.php?ticket_id=500', $T['t1']);
$ok($c === 200 && isset($j['items']) && count($j['items']) >= 5, 'list returns the ticket\'s attachments');
$li = array_column($j['items'], null, 'id');
$ok(array_keys($li[$a1]) === ['id', 'name', 'size', 'mime', 'created_at', 'uploaded_by'] && $li[$a1]['size'] === strlen($PNG) && $li[$a1]['mime'] === 'image/png' && $li[$a1]['name'] === 'photo.png' && $li[$a1]['uploaded_by'] === null, 'list item shape, real size, mime from extension, ticket-level uploader null');
$ok($li[$a3]['uploaded_by'] === 'User 11', 'reply attachment shows the reply author');
[$c] = http('GET', '/api/v1/ticket_attachments.php?ticket_id=501', $T['r']); $ok($c === 403, "list of another department's ticket -> 403");
[$c] = http('GET', '/api/v1/ticket_attachments.php?ticket_id=99999', $T['r']); $ok($c === 403, 'list of unknown ticket -> same 403');
[$c] = http('GET', '/api/v1/ticket_attachments.php', $T['t1']); $ok($c === 403, 'list without ticket_id -> 403 (no ticket)');

// download
[$c, $h, , $raw] = http('GET', "/api/v1/ticket_attachments.php?id=$a1&download=1", $T['t1']);
$ok($c === 200 && $raw === $PNG, 'download streams the exact bytes');
$ok(($h['content-type'][0] ?? '') === 'image/png' && intval($h['content-length'][0] ?? -1) === strlen($PNG), 'Content-Type from extension, correct Content-Length');
$ok(strpos($h['content-disposition'][0] ?? '', 'attachment;') === 0 && strpos($h['content-disposition'][0], 'filename="photo.png"') !== false, 'Content-Disposition: attachment with the file name');
$ok(($h['x-content-type-options'][0] ?? '') === 'nosniff' && ($h['cache-control'][0] ?? '') === 'no-store', 'nosniff + Cache-Control: no-store');
[$c, $h, , $raw] = http('GET', "/api/v1/ticket_attachments.php?id=$a5&download=1", $T['t1']);
$ok($c === 200 && !isset($h['x-injected']) && substr_count($h['content-disposition'][0] ?? '', "\n") === 0, 'a hostile stored name cannot inject headers');
$ok(strpos($h['content-disposition'][0] ?? '', '"name"') === false && strpos($h['content-disposition'][0] ?? '', 'filename*=UTF-8\'\'') !== false, 'quotes are stripped from the quoted filename; RFC 5987 form present');
// hostile row planted directly in the DB (e.g. imported): CR/LF + quote + traversal in the *display* name, traversal in the reference name
$q("INSERT INTO ticket_attachments SET ticket_attachment_id=7001, ticket_attachment_name='a\";x=\r\nX-Evil: 1\r\n.txt', ticket_attachment_reference_name='" . basename($stored) . "', ticket_attachment_ticket_id=500");
$q("INSERT INTO ticket_attachments SET ticket_attachment_id=7002, ticket_attachment_name='t.txt', ticket_attachment_reference_name='../../../config.php', ticket_attachment_ticket_id=500");
$q("INSERT INTO ticket_attachments SET ticket_attachment_id=7003, ticket_attachment_name='t.txt', ticket_attachment_reference_name='gone.txt', ticket_attachment_ticket_id=500");
[$c, $h] = http('GET', '/api/v1/ticket_attachments.php?id=7001&download=1', $T['t1']);
$ok($c === 200 && !isset($h['x-evil']) && strpos($h['content-disposition'][0], "\r") === false, 'CRLF in a stored name cannot split the response');
[$c, , , $raw] = http('GET', '/api/v1/ticket_attachments.php?id=7002&download=1', $T['t1']); $ok($c === 404 && strpos($raw, '<?php') === false, 'traversal in a stored reference name is refused (config.php not served)');
[$c] = http('GET', '/api/v1/ticket_attachments.php?id=7003&download=1', $T['t1']); $ok($c === 404, 'row whose file is missing -> 404');
[$c, , $j403] = http('GET', "/api/v1/ticket_attachments.php?id=$a1&download=1", $T['r']);
$q("INSERT INTO ticket_attachments SET ticket_attachment_id=7010, ticket_attachment_name='b.txt', ticket_attachment_reference_name='" . basename($stored) . "', ticket_attachment_ticket_id=501");
[$c, , $jb, $rawb] = http('GET', '/api/v1/ticket_attachments.php?id=7010&download=1', $T['r']);
$ok($c === 403 && strpos($rawb, 'PNG') === false, 'download of an attachment on another department\'s ticket -> 403, no bytes');
[$c, , $ju] = http('GET', '/api/v1/ticket_attachments.php?id=999999&download=1', $T['r']); $ok($c === 403 && $ju === $jb, 'unknown attachment id -> identical 403 (no existence leak)');
[$c, , , $raw] = http('GET', "/api/v1/ticket_attachments.php?id=$a1&download=1", $T['r']); $ok($c === 200 && $raw === $PNG, 'department-restricted user can download in their own department');
[$c] = http('GET', "/api/v1/ticket_attachments.php?id=$a1&download=1", null); $ok($c === 401, 'download requires auth');
[$c] = http('GET', "/api/v1/ticket_attachments.php?id=$a1&download=1", $T['limited']); $ok($c === 403, 'download as limited login -> 403');

// ticket detail count
[$c, , $j] = http('GET', '/api/v1/tickets/500', $T['t1']);
$ok($c === 200 && $j['attachments_count'] === intval($one("SELECT COUNT(*) FROM ticket_attachments WHERE ticket_attachment_ticket_id=500")), 'ticket detail carries attachments_count');

// ------------------------------------------------------------------ clean up what the test wrote to disk
foreach (glob("$root/uploads/tickets/500/*") as $f) { @unlink($f); } @rmdir("$root/uploads/tickets/500");
foreach (glob("$root/uploads/tickets/501/*") ?: [] as $f) { @unlink($f); } @rmdir("$root/uploads/tickets/501");
foreach (glob("$tmp/*") as $f) { @unlink($f); } @rmdir($tmp);
$errs = @file_get_contents($logf);
$fatal = $errs && preg_match('/PHP (Fatal|Parse|Warning|Notice|Deprecated)[^\n]*(approvals|service_catalog|workflow_tasks|ticket_attachments|api_mobile)/', $errs, $mm);
$ok(!$fatal, 'no PHP errors/warnings raised by the mobile endpoints' . ($fatal ? ': ' . $mm[0] : ''));
@unlink($logf);
echo $fails ? "\n$fails FAILED\n" : "\nAll mobile API checks passed\n";
exit($fails ? 1 : 0);
