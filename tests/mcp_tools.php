<?php
/*
 * MCP read-tool functional test. Needs a DISPOSABLE schema-only database (never the live one):
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=... RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/mcp_tools.php
 * Uses user ids 9001+ so Redis rate-limit buckets cannot collide with real users, and cleans them up.
 */
if (getenv('RIVETIT_TEST_DB') !== '1') exit(2);
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/redis_functions.php';
require_once __DIR__ . '/../mcp_server/ReadTools.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$mysqli = new mysqli('localhost', getenv('RIVETIT_TEST_DB_USER'), getenv('RIVETIT_TEST_DB_PASS'), getenv('RIVETIT_TEST_DB_NAME'));
if (!preg_match('/scratch|test/i', (string) getenv('RIVETIT_TEST_DB_NAME'))) { fwrite(STDERR, "Refusing: DB name must contain scratch/test\n"); exit(2); }

const ADMIN = 9001, TECH = 9002, KBONLY = 9003, NOROLE = 9004, RATE = 9005;
$fail = 0;
$ok = function (bool $cond, string $label) use (&$fail) { echo ($cond ? 'PASS' : 'FAIL') . "  $label\n"; if (!$cond) $fail++; };

// ---- seed
$q = fn(string $sql) => $mysqli->query($sql);
foreach (['module_support' => 1, 'module_assets' => 2, 'module_client' => 3, 'module_kb' => 4] as $m => $id) $q("INSERT INTO modules (module_id, module_name) VALUES ($id, '$m')");
$q("INSERT INTO user_roles (role_id, role_name, role_is_admin) VALUES (91,'admin',1),(92,'tech',0),(93,'kbonly',0),(94,'none',0)");
foreach ([1, 2, 3, 4] as $m) { $q("INSERT INTO user_role_permissions VALUES (92, $m, 2)"); }
$q("INSERT INTO user_role_permissions VALUES (93, 4, 1)");
foreach ([ADMIN => 91, TECH => 92, KBONLY => 93, NOROLE => 94, RATE => 91] as $uid => $role) {
    $q("INSERT INTO users (user_id, user_name, user_email, user_password, user_auth_method, user_type, user_status, user_role_id)
        VALUES ($uid, 'u$uid', 'u$uid@example.test', '', 'local', 1, 1, $role)");
}
$q("INSERT INTO user_client_permissions VALUES (" . TECH . ", 10)");   // tech is limited to client 10
$q("INSERT INTO clients (client_id, client_name, client_currency_code, client_net_terms) VALUES (10,'Alpha','USD',30),(11,'Beta','USD',30)");
$q("INSERT INTO ticket_statuses (ticket_status_id, ticket_status_name, ticket_status_color) VALUES (1,'Open','blue')");
$q("INSERT INTO tickets (ticket_id, ticket_number, ticket_subject, ticket_details, ticket_status, ticket_created_by, ticket_client_id)
    VALUES (1,1001,'Printer jam <b>urgent</b>','<p>Paper stuck &amp; jammed</p>',1,1,10),(2,1002,'Beta VPN down','vpn',1,1,11)");
$q("INSERT INTO ticket_replies (ticket_reply, ticket_reply_type, ticket_reply_by, ticket_reply_ticket_id) VALUES ('<p>Checked rollers</p>','Internal',1,1)");
$q("INSERT INTO assets (asset_id, asset_type, asset_name, asset_make, asset_serial, asset_pin, asset_notes, asset_client_id)
    VALUES (1,'Laptop','ALPHA-PC1','Dell','SN-A1','SECRETPIN','alpha note',10),(2,'Laptop','BETA-PC1','HP','SN-B1','SECRETPIN','beta note',11)");
$q("INSERT INTO contacts (contact_id, contact_name, contact_email, contact_pin, contact_client_id) VALUES (1,'Ann Alpha','ann@a.test','1234',10),(2,'Bob Beta','bob@b.test','5678',11)");
$q("INSERT INTO kb_articles (kb_article_id, kb_article_title, kb_article_content_raw, kb_article_client_id) VALUES
    (1,'Global printer guide','Line one" . "\\n\\n\\n\\n" . "Line two',0),(2,'Alpha printer notes','alpha only',10),(3,'Beta printer notes','beta only',11)");

$tools = new RivetITMcpReadTools($mysqli);
$ctx = fn(int $uid, array $scopes = ['mcp:read']) => new Mcp\Server\RequestContext(
    new Mcp\Server\Session\Session(new Mcp\Server\Session\InMemorySessionStore()),
    (new Mcp\Schema\Request\CallToolRequest('t', []))->withMeta(['oauth' => ['oauth.user_id' => $uid, 'oauth.scopes' => $scopes]])
);
$ids = fn(array $r, string $k) => array_map('intval', array_column($r['data'], $k));
$code = fn(array $r) => $r['errors'][0]['code'] ?? null;
$_SERVER['RIVET_REQUEST_ID'] = 'req_test'; // server-assigned id (a client X-Request-ID header is ignored)

// ---- envelope + admin sees everything
$r = $tools->searchTicketsTool($ctx(ADMIN), '', true, 10);
$ok($r['success'] === true && $r['request_id'] === 'req_test' && $r['errors'] === [], 'envelope shape');
$ok($ids($r, 'ticket_id') === [2, 1] || $ids($r, 'ticket_id') === [1, 2], 'admin sees tickets of both clients');
$ok($tools->searchAssets($ctx(ADMIN))['data'] !== [] && count($tools->searchAssets($ctx(ADMIN))['data']) === 2, 'admin sees both assets');

// ---- client scoping
$ok($ids($tools->searchTicketsTool($ctx(TECH)), 'ticket_id') === [1], 'scoped tech: only client 10 tickets');
$ok($ids($tools->searchAssets($ctx(TECH)), 'asset_id') === [1], 'scoped tech: only client 10 assets');
$ok($code($tools->getAsset($ctx(TECH), 2)) === 'NOT_FOUND', 'scoped tech: client 11 asset by id -> NOT_FOUND');
$ok($code($tools->getTicket($ctx(TECH), 2)) === 'NOT_FOUND', 'scoped tech: client 11 ticket by id -> NOT_FOUND');
$ok($ids($tools->listClients($ctx(TECH)), 'client_id') === [10], 'scoped tech: only client 10 listed');
$ok($ids($tools->searchContacts($ctx(TECH)), 'contact_id') === [1], 'scoped tech: only client 10 contacts');
$kb = $tools->searchKb($ctx(TECH), 'printer');
$ok($ids($kb, 'kb_article_id') === [1, 2] || $ids($kb, 'kb_article_id') === [2, 1], 'scoped tech: global + own-client KB, not client 11');
$ok($code($tools->getKbArticle($ctx(TECH), 3)) === 'NOT_FOUND', 'scoped tech: client 11 KB by id -> NOT_FOUND');

// ---- role checks
$ok($code($tools->searchTicketsTool($ctx(KBONLY))) === 'PERMISSION_DENIED', 'kb-only role: tickets denied');
$ok($code($tools->searchAssets($ctx(KBONLY))) === 'PERMISSION_DENIED', 'kb-only role: assets denied');
$ok($tools->searchKb($ctx(KBONLY), 'printer')['success'] === true, 'kb-only role: KB allowed');
$ok($code($tools->searchKb($ctx(NOROLE), 'printer')) === 'PERMISSION_DENIED', 'no-permission role: KB denied');
$ok($code($tools->myProfile($ctx(ADMIN, ['openid']))) === 'PERMISSION_DENIED', 'missing mcp:read scope denied');

// ---- content hygiene
$get = $tools->getTicket($ctx(ADMIN), 1)['data'];
$ok($get['ticket_details'] === 'Paper stuck & jammed' && $get['recent_replies'][0]['ticket_reply'] === 'Checked rollers', 'ticket HTML stripped, replies included');
$all = json_encode([$tools->getAsset($ctx(ADMIN), 1), $tools->searchAssets($ctx(ADMIN)), $tools->searchContacts($ctx(ADMIN)), $get]);
$ok(!str_contains($all, 'SECRETPIN') && !str_contains($all, '1234') && !str_contains($all, '5678'), 'no asset/contact PINs in any output');
$ok(str_contains($tools->getKbArticle($ctx(ADMIN), 1)['data']['content'], "Line one\n\nLine two"), 'KB keeps paragraph breaks, collapses blank runs');
$ok($tools->searchAssets($ctx(ADMIN), '%')['data'] === [], 'LIKE wildcard in query is escaped');
$ok($tools->searchAssets($ctx(ADMIN), "x' OR '1'='1")['success'] === true, 'quote in query does not error');
$ok(count($tools->searchAssets($ctx(ADMIN), '', 0, 9999)['data']) <= 25, 'limit clamped');

// ---- audit
$n = fn(string $where) => (int) $mysqli->query("SELECT COUNT(*) c FROM audit_events WHERE event_type='mcp.tool_call' AND $where")->fetch_assoc()['c'];
$ok($n("actor_user_id=" . TECH . " AND summary LIKE '%: ok'") > 0, 'audit row for successful call');
$ok($n("actor_user_id=" . KBONLY . " AND summary LIKE '%: denied'") === 2, 'audit rows for denied calls');
$ok($n("actor_user_id=" . TECH . " AND summary LIKE '%: not_found'") === 3, 'audit rows for not_found calls');
$ok($n("request_id='req_test'") > 0, 'audit carries the request id');

// ---- rate limit (60/min per user) - only meaningful with Redis; fails open without it
if (getRedisClient()) {
    $last = null;
    for ($i = 0; $i < 62; $i++) $last = $tools->myProfile($ctx(RATE));
    $ok($code($last) === 'RATE_LIMITED', 'call 62 within a minute -> RATE_LIMITED');
    getRedisClient()->del(['rivetit:rl:mcp:u' . RATE]);
    foreach ([ADMIN, TECH, KBONLY, NOROLE] as $u) getRedisClient()->del(['rivetit:rl:mcp:u' . $u]);
} else {
    echo "SKIP  rate limit (Redis unavailable)\n";
}

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit($fail ? 1 : 0);
