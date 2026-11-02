<?php
/*
 * Issue #32 employee portal additions on a SCRATCH database (and a throwaway php -S server for the API):
 *   announcements (validation, department/all scoping, schedule/expiry/archive), KB popular + recent (visibility, dedupe), richer device facts,
 *   the notification feed (unread, sticky, mark read, scoping), the employee API (sign-in rules, token separation, scoping, 404 for other people's
 *   devices, lockout, 2FA prompt, disabled login / portal off), and migrations 2.6.215-2.6.218 (re-run changes nothing).
 * Needs a throwaway database holding this app's schema (import db.sql) AND a config.php pointing at that same database (the API runs the real
 * code through config.php):
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=rivetit_scratch_x RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/employee_portal_i32.php
 * It runs tests/browser/employee_portal_i32_seed.php first (which WIPES the scratch tables it fills).
 */
if (getenv('RIVETIT_TEST_DB') !== '1') exit(2);
if (!preg_match('/scratch/i', (string) getenv('RIVETIT_TEST_DB_NAME'))) { fwrite(STDERR, "Refusing: DB name must contain scratch\n"); exit(2); }
$root = dirname(__DIR__);
$cfg = (string) @file_get_contents($root . '/config.php');
if (!preg_match("/\\\$database\s*=\s*'([^']+)'/", $cfg, $m) || $m[1] !== getenv('RIVETIT_TEST_DB_NAME')) { fwrite(STDERR, "Refusing: config.php must point at the scratch database\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETIT_TEST_DB_USER'), getenv('RIVETIT_TEST_DB_PASS'), getenv('RIVETIT_TEST_DB_NAME'));
if ($mysqli->connect_errno) { fwrite(STDERR, "connect failed\n"); exit(2); }
$mysqli->query("SET SESSION sql_mode=''");
date_default_timezone_set('UTC');
require $root . '/vendor/autoload.php';
require_once $root . '/plugins/totp/totp.php';
use ITFlow\Portal\{Announcements as An, EmployeeHome as H, PortalFeed as F};

$seedOut = sys_get_temp_dir() . '/ep-i32-seed-' . getmypid() . '.json';
passthru('php ' . escapeshellarg($root . '/tests/browser/employee_portal_i32_seed.php') . ' ' . escapeshellarg($seedOut), $rc);
if ($rc !== 0) { fwrite(STDERR, "seed failed\n"); exit(2); }
$seed = json_decode(file_get_contents($seedOut), true); @unlink($seedOut);
$EP = $seed['employee_password'];

$fails = 0; $total = 0;
$ok = function (bool $c, string $l) use (&$fails, &$total) { $total++; echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };
$q = fn(string $sql) => mysqli_query($mysqli, $sql);
$one = fn(string $sql) => mysqli_fetch_row($q($sql))[0] ?? null;

// ---------------------------------------------------------------- announcements: validation (pure)
$depts = [1, 2];
$good = ['title' => ' Hello ', 'body' => "Line1\r\nLine2", 'severity' => 'important', 'client_id' => '1', 'pinned' => '1', 'publish_at' => '2026-12-01T09:30', 'expires_at' => '2026-12-02'];
$v = An::validate($good, $depts);
$ok($v['ok'] && $v['clean']['title'] === 'Hello' && $v['clean']['body'] === "Line1\nLine2" && $v['clean']['publish_at'] === '2026-12-01 09:30:00' && $v['clean']['expires_at'] === '2026-12-02 00:00:00' && $v['clean']['pinned'] === 1, 'validate: good input is cleaned (trim, newlines, datetime-local, pinned)');
$ok(!An::validate(['title' => '', 'body' => 'x'] + $good, $depts)['ok'] && !An::validate(['body' => ' '] + $good, $depts)['ok'], 'validate: title and text are required');
$ok(!An::validate(['severity' => 'bogus'] + $good, $depts)['ok'], 'validate: unknown importance refused');
$ok(!An::validate(['client_id' => '99'] + $good, $depts)['ok'] && An::validate(['client_id' => '0'] + $good, $depts)['ok'], 'validate: unknown department refused, 0 = all departments allowed');
$ok(!An::validate(['publish_at' => 'not a date'] + $good, $depts)['ok'] && !An::validate(['publish_at' => '2026-02-30T10:00'] + $good, $depts)['ok'], 'validate: bad / impossible dates refused');
$ok(!An::validate(['expires_at' => '2026-11-30T10:00'] + $good, $depts)['ok'], 'validate: expiry must be after publish');
$ok(!An::validate(['title' => str_repeat('x', 201)] + $good, $depts)['ok'] && !An::validate(['body' => str_repeat('x', 4001)] + $good, $depts)['ok'], 'validate: length limits');
$e = An::validate(['publish_at' => '', 'expires_at' => ''] + $good, $depts);
$ok($e['ok'] && $e['clean']['publish_at'] === null && $e['clean']['expires_at'] === null, 'validate: blank publish = now, blank expiry = never');

// ---------------------------------------------------------------- announcements: scoping
$an = new An($mysqli);
$titles = fn(array $rows) => array_column($rows, 'announcement_title');
$a1 = $an->visibleFor(1);
$ok($titles($a1) === ['Network maintenance tonight', 'Dept A lunch on Friday'], 'Dept A sees all-department + its own, pinned/urgent first, not Dept B, expired, scheduled or removed');
$ok($titles($an->visibleFor(2)) === ['Network maintenance tonight', 'Dept B only notice'], 'Dept B sees all-department + its own only');
$ok($titles($an->visibleFor(0)) === ['Network maintenance tonight'], 'department 0 (admin preview) sees only all-department notices');
$id = $an->create(An::validate(['title' => 'New one', 'body' => 'b', 'client_id' => 1], $depts)['clean'], 1);
$ok($id > 0 && in_array('New one', $titles($an->visibleFor(1)), true) && !in_array('New one', $titles($an->visibleFor(2)), true), 'create: visible to its department only, publish blank = now');
$an->update($id, An::validate(['title' => 'Renamed', 'body' => 'b', 'client_id' => 2], $depts)['clean']);
$ok(in_array('Renamed', $titles($an->visibleFor(2)), true) && !in_array('Renamed', $titles($an->visibleFor(1)), true), 'update: moves to another department');
$an->archive($id);
$ok(!in_array('Renamed', $titles($an->visibleFor(2)), true) && $an->find($id) === null, 'archive: gone for employees and for edit');
$states = array_column($an->adminList(), 'announcement_state', 'announcement_id');
$ok($states[61] === 'live' && $states[64] === 'expired' && $states[65] === 'scheduled' && !isset($states[66]), 'admin list: live / expired / scheduled, removed hidden');
$ok(count($an->visibleFor(1, 1)) === 1, 'limit respected');

// ---------------------------------------------------------------- KB popular / recent
$h = new H($mysqli);
$pop = $h->popularArticles(1, 5, 30);
$ok(array_column($pop, 'kb_article_id') == [41, 42] && (int) $pop[0]['read_count'] === 3, 'Dept A popular: company-wide 41 (3 reads) then 42; not B-only, not client-hidden, not archived');
$ok(array_column($h->popularArticles(2, 5, 30), 'kb_article_id') == [43, 41], 'Dept B popular: its own 43 (5 reads) then 41; never Dept A articles');
$rec = array_column($h->recentArticles(1, 10), 'kb_article_id');
$ok(sort($rec) && $rec == [41, 42], 'Dept A recent: only visible articles');
$ok($h->popularArticles(0) === [] && $h->recentArticles(0) === [], 'department 0 sees none');
$ok($h->recordKbView(42, 21, 1) === true && $h->recordKbView(42, 21, 1) === false, 'a view is counted once per contact per hour');
$ok($h->recordKbView(43, 21, 1) === false && $h->recordKbView(44, 21, 1) === false && $h->recordKbView(45, 21, 1) === false && $h->recordKbView(41, 0, 1) === false, 'views of articles the reader cannot see (other dept, hidden, archived) or from contact 0 are not recorded');
$ok((int) $one("SELECT COUNT(*) FROM kb_article_views WHERE view_article_id = 43 AND view_contact_id = 21") === 0, 'no forged view rows');

// ---------------------------------------------------------------- device facts
$now = strtotime('2026-11-01 12:00:00');
$f = H::deviceFacts(['asset_id' => 1, 'asset_name' => 'X', 'agent_seen' => '2026-11-01 11:50:00', 'rmm_seen' => '2026-10-20 00:00:00', 'asset_warranty_expire' => '2026-11-21', 'asset_os' => '', 'rmm_os' => 'macOS 14', 'agent_reboot' => '1'], $now);
$ok($f['seen_state'] === 'online' && $f['last_seen'] === '2026-11-01 11:50:00' && $f['warranty_state'] === 'expiring' && $f['warranty_days_left'] === 20 && $f['os'] === 'macOS 14' && $f['reboot_pending'] === true, 'deviceFacts: newest of agent/RMM, expiring warranty, OS falls back to RMM, restart pending');
$f = H::deviceFacts(['asset_id' => 1, 'asset_name' => 'X', 'rmm_seen' => '2026-10-20 00:00:00', 'asset_warranty_expire' => '2026-10-01', 'asset_os' => 'Win'], $now);
$ok($f['seen_state'] === 'stale' && $f['warranty_state'] === 'expired' && $f['warranty_days_left'] < 0 && $f['os'] === 'Win' && $f['reboot_pending'] === null, 'deviceFacts: stale, expired warranty, own OS wins, reboot unknown');
$f = H::deviceFacts(['asset_id' => 1, 'asset_name' => 'X', 'asset_warranty_expire' => '2027-06-01'], $now);
$ok($f['seen_state'] === 'unknown' && $f['warranty_state'] === 'ok' && $f['last_seen'] === null && H::seenLabel($f, $now) === ['secondary', 'Not reporting'], 'deviceFacts: never seen, warranty fine');
$ok(H::seenLabel(['last_seen' => '2026-10-29 12:00:00', 'seen_state' => 'recent'], $now)[1] === 'Last seen 3 days ago', 'seenLabel: days');
$ok(H::warrantyLabel(['warranty_state' => 'expiring', 'warranty_days_left' => 1, 'warranty_expires' => 'x'])[1] === 'Warranty ends in 1 day', 'warrantyLabel: singular');

$dev = $h->myDeviceDetails(21, 1);
$byId = array_column($dev, null, 'id');
$ok(array_keys($byId) == [31, 35], 'Alice sees exactly her two devices');
$ok($byId[31]['os'] === 'Windows 11 Pro' && $byId[31]['seen_state'] === 'online' && $byId[31]['reboot_pending'] === true && $byId[31]['warranty_state'] === 'expiring', 'agent device: OS, online, restart pending, warranty expiring');
$ok($byId[35]['os'] === 'macOS 14.4' && $byId[35]['seen_state'] === 'stale' && $byId[35]['warranty_state'] === 'expired', 'RMM-linked device: OS from the RMM link, seen days ago (stale), warranty expired');
$flat = json_encode($dev);
$ok(strpos($flat, 'SECRET NOTE') === false && strpos($flat, '10.9.9.9') === false && strpos($flat, '"alice"') === false && !array_key_exists('asset_notes', $dev[0]), 'no notes, IP or logged-in user in device facts');
$ok($h->myDeviceDetails(22, 1) !== [] && array_column($h->myDeviceDetails(22, 1), 'id') == [32] && $h->myDeviceDetails(21, 2) === [] && $h->myDeviceDetails(0, 1) === [], 'Bob sees only his; wrong department / contact 0 see none');
$q("UPDATE endpoint_agent_devices SET revoked_at = NOW() WHERE device_id = 1");
$ok(array_column($h->myDeviceDetails(21, 1), null, 'id')[31]['seen_state'] === 'unknown', 'a revoked agent no longer counts as seen');
$q("UPDATE endpoint_agent_devices SET revoked_at = NULL WHERE device_id = 1");

// ---------------------------------------------------------------- notification feed
$feed = new F($mysqli);
$items = $feed->items(21, 1);
$types = array_count_values(array_column($items, 'type'));
$ok(($types['announcement'] ?? 0) === 2 && ($types['reply'] ?? 0) === 1 && ($types['closed'] ?? 0) === 1 && ($types['task'] ?? 0) === 1 && ($types['warranty'] ?? 0) === 2, 'Alice feed: 2 announcements, a reply, a closure, a due task, 2 warranty items');
$titlesF = implode(' | ', array_column($items, 'title'));
$ok(strpos($titlesF, 'Dept B') === false && strpos($titlesF, 'Bob') === false && strpos($titlesF, 'internal') === false, 'no other department, other person or internal reply in the feed');
$ok($feed->unreadCount(21, 1) === count($items), 'everything starts unread');
$feed->markRead(21);
$left = array_filter($feed->items(21, 1), fn($i) => $i['unread']);
$leftTypes = array_values(array_unique(array_column($left, 'type'))); sort($leftTypes);
$ok(count($left) === 3 && $leftTypes === ['task', 'warranty'] && !array_filter($left, fn($i) => !$i['sticky']), 'mark read clears the non-sticky items; the due task and the two warranty items stay unread');
$ok($feed->unreadCount(22, 1) >= 1 && $feed->unreadCount(22, 1) !== $feed->unreadCount(21, 1), "Bob's read marker is separate from Alice's");
$ok($feed->items(0, 1) === [] && $feed->items(21, 0) === [] && $feed->items(21, 2) !== null && array_filter($feed->items(21, 2), fn($i) => $i['type'] === 'reply') === [], 'contact 0 / department 0 empty; another department shows none of Alice\'s tickets');
$q("INSERT INTO ticket_replies SET ticket_reply = 'later', ticket_reply_type = 'Public', ticket_reply_by = 1, ticket_reply_ticket_id = 51, ticket_reply_created_at = NOW() + INTERVAL 1 SECOND");
$ok(count(array_filter($feed->items(21, 1), fn($i) => $i['unread'] && $i['type'] === 'reply')) === 1, 'a reply after the read marker is unread again');

// ---------------------------------------------------------------- migrations 2.6.215-2.6.218
$cols = fn() => (int) $one("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('portal_announcements','kb_article_views','portal_api_tokens','portal_feed_state')");
$ok($cols() === 4, 'the four new tables exist');
$q("UPDATE settings SET config_current_database_version = '2.6.210', config_portal_home_sections = 'requests,devices'");
define('LATEST_DATABASE_VERSION', '2.6.218'); define('CURRENT_DATABASE_VERSION', '2.6.210');
require $root . '/admin/database_updates.php';
$ok($one("SELECT config_current_database_version FROM settings") === '2.6.218' && $cols() === 4, 're-running the 2.6.215-2.6.218 blocks changes nothing but the version');
$ok($one("SELECT config_portal_home_sections FROM settings") === 'requests,devices,announcements,kb', 'an install that chose its sections gets announcements and kb added');
$q("UPDATE settings SET config_current_database_version = '2.6.210', config_portal_home_sections = ''");
$q("UPDATE settings SET config_current_database_version = '2.6.217'");
require $root . '/admin/database_updates.php';   // (the closure re-reads the version: 2.6.217 -> 2.6.218)
$ok($one("SELECT config_portal_home_sections FROM settings") === '', 'an install with every section off stays off');
$ok(strpos((string) $one("SELECT column_default FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'settings' AND column_name = 'config_portal_home_sections'"), 'announcements,kb') !== false, 'column default includes the new sections');
$q("UPDATE settings SET config_portal_home_sections = 'requests,approvals,devices,onboarding,training,catalog,announcements,kb'");
$ok(H::parseSections('announcements,kb,bogus') === ['announcements', 'kb'] && count(H::SECTIONS) === 8, 'parseSections knows the new keys');

// ---------------------------------------------------------------- API over HTTP
$port = random_int(18700, 18999);
$router = $root . '/tests/browser/employee_portal_i32_router.php';
$proc = proc_open(['php', '-S', "127.0.0.1:$port", $router], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root);
register_shutdown_function(function () use ($proc) { if (is_resource($proc)) { proc_terminate($proc); } });
for ($i = 0; $i < 50; $i++) { if (($sock = @fsockopen('127.0.0.1', $port)) !== false) { fclose($sock); break; } usleep(100000); }   // close the probe: php -S serves one connection at a time
$call = function (string $method, string $path, ?array $body = null, ?string $token = null) use ($port) {
    $ch = curl_init("http://127.0.0.1:$port$path");
    $h = ['Accept: application/json'];
    if ($token) $h[] = "Authorization: Bearer $token";
    if ($body !== null) $h[] = 'Content-Type: application/json';
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 5]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, json_decode((string) $raw, true) ?? [], (string) $raw];
};
$login = fn(string $email, string $pw = null, array $extra = []) => $call('POST', '/api/v1/portal_auth', ['username' => $email, 'password' => $pw ?? $EP, 'device_name' => 'test phone'] + $extra);

[$c, $j] = $call('GET', '/api/v1/portal_home');
$ok($c === 401, 'API: no token is 401');
[$c] = $login('alice@a.test', 'wrong-password');
$ok($c === 401, 'API: wrong password is 401');
[$c] = $login('nobody@nowhere.test');
$ok($c === 401, 'API: unknown login is 401 (same answer)');
[$c] = $login('admin@scratch.test', 'Scratch-Admin-1234');
$ok($c === 401, 'API: a staff login cannot sign in to the employee API');
[$c, $j, $raw] = $login('alice@a.test');
if ($c !== 200) { echo "  (sign-in answered $c: $raw)\n"; }
$ok($c === 200 && preg_match('/^[0-9a-f]{64}$/', $j['token'] ?? '') && $j['contact']['id'] === 21 && $j['contact']['department_id'] === 1, 'API: Alice signs in');
$tokA = $j['token'];
$ok((int) $one("SELECT COUNT(*) FROM portal_api_tokens WHERE token_hash = '" . $tokA . "'") === 0 && (int) $one("SELECT COUNT(*) FROM portal_api_tokens WHERE token_hash = '" . hash('sha256', $tokA) . "'") === 1, 'API: only the SHA-256 of the token is stored');
[$c, $j] = $login('carl@b.test'); $tokC = $j['token'] ?? '';
[$c, $j] = $login('bob@a.test'); $tokB = $j['token'] ?? '';

[$c, $j] = $call('GET', '/api/v1/portal_home', null, $tokA);
$ok($c === 200 && $j['contact']['id'] === 21 && count($j['devices']) === 2 && count($j['open_requests']) === 1 && $j['open_requests'][0]['subject'] === 'Alice printer broken', 'API home: Alice\'s requests and devices');
$ok(array_column($j['announcements'], 'title') === ['Network maintenance tonight', 'Dept A lunch on Friday'], 'API home: announcements for Dept A + all');
$ok($j['checklist']['done'] === 1 && $j['checklist']['total'] === 3 && !str_contains(json_encode($j['checklist']), 'INTERNAL NOTE') && !str_contains(json_encode($j['checklist']), 'Create mailbox'), 'API home: checklist without instructions or automated tasks');
$ok(array_column($j['kb']['popular'], 'id') == [41, 42] && $j['unread_notifications'] >= 1, 'API home: KB popular and unread count');
[$c, $j] = $call('GET', '/api/v1/portal_home', null, $tokC);
$ok(array_column($j['announcements'], 'title') === ['Network maintenance tonight', 'Dept B only notice'] && $j['devices'][0]['name'] === 'CARL-LAPTOP' && count($j['devices']) === 1 && $j['checklist'] === null && $j['open_requests'][0]['subject'] === 'Carl secret', 'API home: Carl sees only his department\'s data');

[$c, $j] = $call('GET', '/api/v1/portal_devices', null, $tokA);
$ok($c === 200 && array_column($j['devices'], 'id') == [31, 35], 'API devices: Alice\'s list');
[$c] = $call('GET', '/api/v1/portal_devices/32', null, $tokA);
$ok($c === 404, 'API devices: Bob\'s device is 404 for Alice');
[$c] = $call('GET', '/api/v1/portal_devices/34', null, $tokA);
$ok($c === 404, 'API devices: another department\'s device is 404');
[$c] = $call('GET', '/api/v1/portal_devices/33', null, $tokA);
$ok($c === 404, 'API devices: an unassigned device is 404');
[$c, $j] = $call('GET', '/api/v1/portal_devices/31', null, $tokA);
$ok($c === 200 && $j['device']['os'] === 'Windows 11 Pro' && $j['device']['warranty_state'] === 'expiring', 'API devices: her own device by id');
[$c] = $call('POST', '/api/v1/portal_devices', [], $tokA);
$ok($c === 405, 'API devices: POST is 405');
[$c, $j] = $call('GET', '/api/v1/portal_checklist', null, $tokA);
$ok($c === 200 && $j['checklist']['type'] === 'onboarding' && $j['checklist']['tasks'][0]['state'] === 'todo' && $j['checklist']['tasks'][1]['state'] === 'done' && $j['checklist']['tasks'][2]['state'] === 'awaiting_approval', 'API checklist: states');
[$c, $j] = $call('GET', '/api/v1/portal_checklist', null, $tokB);
$ok($c === 200 && $j['checklist'] === null, 'API checklist: Bob has none');
[$c, $j] = $call('GET', '/api/v1/portal_announcements', null, $tokA);
$ok($c === 200 && count($j['announcements']) === 2 && $j['announcements'][0]['severity'] === 'urgent' && $j['announcements'][0]['pinned'] === true && $j['announcements'][0]['all_departments'] === true, 'API announcements');
[$c, $j] = $call('GET', '/api/v1/portal_notifications', null, $tokA);
$ok($c === 200 && $j['unread'] >= 1 && count($j['items']) >= 5, 'API notifications: feed with unread count');
[$c, $j] = $call('POST', '/api/v1/portal_notifications', ['action' => 'mark_read'], $tokA);
$ok($c === 200 && $j['ok'] === true && $j['unread'] === 3, 'API notifications: mark_read leaves the sticky items');
[$c] = $call('POST', '/api/v1/portal_notifications', ['action' => 'delete'], $tokA);
$ok($c === 422, 'API notifications: unknown action is 422');

// token separation both ways
[$c] = $call('GET', '/api/v1/me', null, $tokA);
$ok($c === 401, 'API: an employee token does not work on the staff API');
$ok((int) $one("SELECT COUNT(*) FROM api_tokens") === 0, 'API: employee sign-in created no staff api_tokens row');
$q("INSERT INTO api_tokens (token_user_id, token_hash, token_name, token_enc_master_key, token_enc_master_iv) VALUES (1, '" . hash('sha256', str_repeat('a', 64)) . "', 'staff', 'x', 'x')");
[$c] = $call('GET', '/api/v1/portal_home', null, str_repeat('a', 64));
$ok($c === 401, 'API: a staff token does not work on the employee API');
$q("DELETE FROM api_tokens");

// 2FA, lockout, SSO, disabled, portal off
$secret = ''; for ($i = 0; $i < 16; $i++) { $secret .= 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'[random_int(0, 31)]; }   // fresh seed per run: a code can be used only once
$q("UPDATE users SET user_token = '$secret' WHERE user_id = 12");
[$c, $j] = $login('bob@a.test');
$ok($c === 200 && ($j['requires_2fa'] ?? false) === true && !isset($j['token']), 'API: a login with 2FA asks for the code first');
[$c, , $raw] = $login('bob@a.test', null, ['totp_code' => '000000']);
if ($c !== 401) { echo "  (answered $c: $raw)\n"; }
$ok($c === 401, 'API: a wrong 2FA code is 401');
$code = (new TokenAuth6238())::getTokenCode($secret);
[$c, $j, $raw] = $login('bob@a.test', null, ['totp_code' => $code]);
if ($c !== 200) { echo "  (answered $c: $raw)\n"; }
$ok($c === 200 && !empty($j['token']), 'API: the right 2FA code signs in');
$q("UPDATE users SET user_token = NULL, user_failed_login_count = 0 WHERE user_id = 12");
$q("UPDATE user_settings SET user_config_force_mfa = 1 WHERE user_id = 12");
[$c] = $login('bob@a.test');
$ok($c === 403, 'API: forced 2FA without enrolment is refused (set it up in the web portal)');
$q("UPDATE user_settings SET user_config_force_mfa = 0 WHERE user_id = 12");
$q("UPDATE users SET user_auth_method = 'oidc' WHERE user_id = 12");
[$c] = $login('bob@a.test');
$ok($c === 401, 'API: a single sign-on account cannot use a password here');
$q("UPDATE users SET user_auth_method = 'local' WHERE user_id = 12");
for ($i = 0; $i < 5; $i++) { $login('bob@a.test', 'wrong'); }
[$c] = $login('bob@a.test');
$ok($c === 429, 'API: five failures lock the login for five minutes (even with the right password)');
$q("UPDATE users SET user_failed_login_count = 0 WHERE user_id = 12");
$q("UPDATE users SET user_status = 0 WHERE user_id = 12");
[$c] = $call('GET', '/api/v1/portal_home', null, $tokB);
$ok($c === 401, 'API: disabling the login ends its token at once');
$q("UPDATE users SET user_status = 1 WHERE user_id = 12");
$q("UPDATE contacts SET contact_archived_at = NOW() WHERE contact_id = 22");
[$c] = $call('GET', '/api/v1/portal_home', null, $tokB);
$ok($c === 401, 'API: archiving the contact ends its token');
$q("UPDATE contacts SET contact_archived_at = NULL WHERE contact_id = 22");
$q("UPDATE settings SET config_client_portal_enable = 0");
[$c] = $call('GET', '/api/v1/portal_home', null, $tokA);
[$c2] = $login('alice@a.test');
$ok($c === 401 && $c2 === 401, 'API: the portal switched off ends tokens and refuses sign-in');
$q("UPDATE settings SET config_client_portal_enable = 1");
$q("UPDATE users SET user_failed_login_count = 0");
[$c] = $call('DELETE', '/api/v1/portal_auth', null, $tokA);
[$c2] = $call('GET', '/api/v1/portal_home', null, $tokA);
$ok($c === 200 && $c2 === 401, 'API: sign out revokes the token');
$q("UPDATE portal_api_tokens SET token_last_used_at = NOW() - INTERVAL 91 DAY WHERE token_contact_id = 23");
[$c] = $call('GET', '/api/v1/portal_home', null, $tokC);
$ok($c === 401, 'API: a token unused for 90 days expires');

echo "\n$total checks, $fails failed\n";
exit($fails ? 1 : 0);
