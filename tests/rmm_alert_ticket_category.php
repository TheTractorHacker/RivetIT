<?php
/*
 * Alert ticket category (RivetIT 26.10.62, DB 2.6.179): real code against a SCRATCH database and a `php -S` instance of this checkout.
 *   createTicketFromRmmAlert() with nothing configured / a default / routes; the cron auto-ticketing run as a real process; the settings
 *   card over HTTP (CSRF, hostile input, non-admin, the Maintenance button, escaping); the 2.6.179 migration shape.
 *
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=<...scratch...> RIVETIT_TEST_DB_USER=.. RIVETIT_TEST_DB_PASS=.. \
 *   WEB_BASE=http://127.0.0.1:8741 ADMIN_EMAIL=admin@scratch.test ADMIN_PASSWORD=... php tests/rmm_alert_ticket_category.php
 * (config.php must point at the same scratch database; see tests/endpoint_agent_lib.php for the guards.)
 */
require __DIR__ . '/endpoint_agent_lib.php';
require_once "$root/includes/rmm_functions.php";
require_once "$root/includes/rmm_alert_routes.php";
require_once "$root/includes/database_version.php";
$base = rtrim((string) getenv('WEB_BASE'), '/');
if (!preg_match('#^http://(127\.0\.0\.1|localhost)(:\d+)?$#', $base)) { fwrite(STDERR, "set WEB_BASE to the loopback php -S instance\n"); exit(2); }

// ---- seed ----------------------------------------------------------------
$q("DELETE FROM rmm_alert_ticket_routes");
$q("UPDATE settings SET config_rmm_alert_ticket_category_id = NULL, config_rmm_auto_ticket_severities = '', config_module_enable_rmm = 1, config_enable_cron = 1 WHERE company_id = 1");
$q("DELETE FROM categories WHERE category_type = 'Ticket'");
$mk = function (string $n) use ($q, $db): int { $q("INSERT INTO categories SET category_name = '" . $db->real_escape_string($n) . "', category_type = 'Ticket'"); return (int) $db->insert_id; };
$catMaint = $mk('Maintenance'); $catDisk = $mk('Disk'); $catWarn = $mk('Warnings'); $catSvc = $mk('Services');
$q("INSERT INTO categories SET category_name = 'Expense one', category_type = 'Expense'"); $catExpense = (int) $db->insert_id;
$q("INSERT INTO categories SET category_name = 'Old', category_type = 'Ticket', category_archived_at = NOW()"); $catArchived = (int) $db->insert_id;
$q("DELETE FROM rmm_integrations");
$q("INSERT INTO rmm_integrations SET name='T', type='tactical_rmm', api_url='https://x.example', api_key_enc='', enabled=1"); $intT = (int) $db->insert_id;
$q("INSERT INTO rmm_integrations SET name='A', type='rivetit_agent', api_url='', api_key_enc='', enabled=1"); $intA = (int) $db->insert_id;
$seq = 0;
$alert = function (string $sev, int $integration, string $check = '') use ($q, $db, &$seq): array {
    $seq++;
    $key = $check === '' ? "T-$seq" : "agent:1:$check:$seq";
    $raw = $check === '' ? '{}' : json_encode(['check' => $check]);
    $q("INSERT INTO rmm_alerts SET integration_id=$integration, tactical_alert_id='" . $db->real_escape_string($key) . "', severity='$sev', status='new', message='msg $seq', raw_data_json='" . $db->real_escape_string($raw) . "', client_id=NULL, asset_id=NULL");
    if ($db->errno) { fwrite(STDERR, "alert insert: {$db->error}\n"); }
    return $db->query("SELECT * FROM rmm_alerts WHERE id = " . (int) $db->insert_id)->fetch_assoc();
};
$tk = function (array $a) use ($db) { $id = (int) createTicketFromRmmAlert($db, $a, 0, "RMM Automation")["ticket_id"]; $r = $db->query("SELECT ticket_category, ticket_priority FROM tickets WHERE ticket_id = $id"); if (!$r) { fwrite(STDERR, "tk: {$db->error} id=$id\n"); } return $r->fetch_assoc(); };
$setDefault = fn($c) => $q("UPDATE settings SET config_rmm_alert_ticket_category_id = " . ($c ? (int) $c : 'NULL') . " WHERE company_id = 1");
$route = fn(string $t, string $v, int $c, ?string $p = null) => $q("INSERT INTO rmm_alert_ticket_routes SET match_type='$t', match_value='$v', category_id=$c, priority=" . ($p ? "'$p'" : 'NULL') . ", sort=" . ++$seq);

// ---- 1. nothing configured: identical to before --------------------------
$t = $tk($alert('critical', $intT));
$ok($t["ticket_category"] === null && $t['ticket_priority'] === 'High', 'nothing configured: no category, priority from severity (as before)');

// ---- 2. default category ---------------------------------------------------
$setDefault($catMaint);
$t = $tk($alert('warning', $intT));
$ok((int) $t["ticket_category"] === $catMaint && $t['ticket_priority'] === 'Medium', 'default category is used, priority unchanged');

// ---- 3. routes: most specific wins -----------------------------------------
$route('severity', 'warning', $catWarn);
$route('check_prefix', 'disk', $catDisk, 'Low');
$route('source', 'rivetit_agent', $catSvc);
$t = $tk($alert('warning', $intT));
$ok((int) $t["ticket_category"] === $catWarn, 'severity route beats the default');
$t = $tk($alert('critical', $intT));
$ok((int) $t["ticket_category"] === $catMaint, 'a severity with no route uses the default');
$t = $tk($alert('warning', $intA, 'service:Spooler'));
$ok((int) $t["ticket_category"] === $catSvc, 'source route (endpoint agent) beats the severity route');
$t = $tk($alert('warning', $intA, 'disk:C'));
$ok((int) $t["ticket_category"] === $catDisk && $t['ticket_priority'] === 'Low', 'check family route beats source and severity, and sets the priority');
// archived / wrong-type category in the data is ignored, falling through
$q("UPDATE rmm_alert_ticket_routes SET category_id = $catArchived WHERE match_type='check_prefix'");
$t = $tk($alert('warning', $intA, 'disk:D'));
$ok((int) $t["ticket_category"] === $catSvc, 'a route to an archived category falls through to the next match');
$q("UPDATE rmm_alert_ticket_routes SET category_id = $catExpense WHERE match_type='source'");
$t = $tk($alert('warning', $intA, 'cpu'));
$ok((int) $t["ticket_category"] === $catWarn, 'a route to a non-ticket category falls through to the next match');
$q("UPDATE settings SET config_rmm_alert_ticket_category_id = $catExpense WHERE company_id = 1");
$t = $tk($alert('info', $intT));
$ok($t["ticket_category"] === null, 'a default pointing at a non-ticket category gives no category');
$q("DELETE FROM rmm_alert_ticket_routes"); $setDefault(0);

// ---- 4. cron auto-ticketing, as the real process ---------------------------
$setDefault($catMaint);
$route('severity', 'critical', $catWarn);
$q("UPDATE settings SET config_rmm_auto_ticket_severities = 'critical,warning' WHERE company_id = 1");
$a1 = $alert('critical', $intT); $a2 = $alert('warning', $intT); $a3 = $alert('info', $intT);
$out = []; exec('cd ' . escapeshellarg($root . '/cron') . ' && RIVETIT_REDIS_PORT=' . (int) (getenv('RIVETIT_REDIS_PORT') ?: 6379) . ' php cron.php 2>&1', $out, $rc);
$tid = fn(array $a) => $one("SELECT ticket_id FROM rmm_alerts WHERE id = " . (int) $a['id']);
$cat = fn($id) => $one("SELECT ticket_category FROM tickets WHERE ticket_id = " . (int) $id);
$ok($rc === 0, 'cron.php exits 0' . ($rc ? ': ' . implode(' | ', array_slice($out, -3)) : ''));
$ok($tid($a1) && (int) $cat($tid($a1)) === $catWarn, 'cron: the critical alert became a ticket in the critical route category');
$ok($tid($a2) && (int) $cat($tid($a2)) === $catMaint, 'cron: the warning alert became a ticket in the default category (Maintenance)');
$ok($tid($a3) === null, 'cron: an info alert (severity not selected) got no ticket');
// the manual path (agent/post/rmm_alert.php) calls the same function
$m = $alert('info', $intT);
$t = $tk($m);
$ok((int) $t["ticket_category"] === $catMaint, 'manual create-ticket-from-alert uses the same category');
$q("DELETE FROM rmm_alert_ticket_routes"); $setDefault(0); $q("UPDATE settings SET config_rmm_auto_ticket_severities = '' WHERE company_id = 1");

// ---- 5. migration shape -----------------------------------------------------
$cols = []; foreach ($rows("SHOW COLUMNS FROM rmm_alert_ticket_routes") as $c) { $cols[$c['Field']] = $c; }
$ok(array_keys($cols) === ['id', 'match_type', 'match_value', 'category_id', 'priority', 'sort'] && strpos($cols['match_type']['Type'], "enum('severity','source','check_prefix')") === 0 && $cols['priority']['Null'] === 'YES', 'rmm_alert_ticket_routes has the 2.6.179 shape');
$sc = $rows("SHOW COLUMNS FROM settings LIKE 'config_rmm_alert_ticket_category_id'")[0] ?? null;
$ok($sc && $sc['Null'] === 'YES' && $sc['Default'] === null, 'settings.config_rmm_alert_ticket_category_id is a nullable int');
$ok(version_compare((string) $one("SELECT config_current_database_version FROM settings WHERE company_id=1"), '2.6.179', '>='), 'database version is at least 2.6.179');
$ok(LATEST_DATABASE_VERSION === '2.6.179' || version_compare(LATEST_DATABASE_VERSION, '2.6.179', '>='), 'LATEST_DATABASE_VERSION is at least 2.6.179');
$updSrc = file_get_contents("$root/admin/database_updates.php");
$step = substr($updSrc, strpos($updSrc, "== '2.6.178') {"));
$ok(substr_count($step, 'IF NOT EXISTS') === 2 && strpos($step, 'DROP ') === false, 'the 2.6.179 step only creates IF NOT EXISTS (no DROP), so a replay changes nothing');
$sql = file_get_contents("$root/db.sql");
$ok(strpos($sql, 'CREATE TABLE `rmm_alert_ticket_routes`') !== false && strpos($sql, '`config_rmm_alert_ticket_category_id` int(11) DEFAULT NULL') !== false, 'db.sql carries the table and the column');

// ---- 6. the settings card over HTTP -----------------------------------------
$jar = tempnam(sys_get_temp_dir(), 'ck'); $jar2 = tempnam(sys_get_temp_dir(), 'ck');
register_shutdown_function(function () use ($jar, $jar2) { @unlink($jar); @unlink($jar2); });
$http = function (string $method, string $path, string $jarf, array $f = [], array $hdr = []) use ($base): array {
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jarf, CURLOPT_COOKIEFILE => $jarf, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => $hdr]);
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($f)); }
    $b = (string) curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    return [$c, $b];
};
$login = function (string $email, string $jarf) use ($http): void { $http('GET', '/login.php', $jarf); $http('POST', '/login.php', $jarf, ['email' => $email, 'password' => getenv('ADMIN_PASSWORD'), 'login' => '1']); };
$login((string) getenv('ADMIN_EMAIL'), $jar);
$REF = ['Referer: ' . $base . '/admin/settings_integrations.php'];
$page = fn(string $jarf) => $http('GET', '/admin/settings_integrations.php?tab=rmm', $jarf);
$csrfOf = function (string $body): string { preg_match('/id="alert-ticket-form">\s*<input type="hidden" name="csrf_token" value="([^"]+)"/', $body, $m); return $m[1] ?? ''; };
[$c, $body] = $page($jar);
$tok = $csrfOf($body);
$ok($c === 200 && $tok !== '' && strpos($body, 'Alert tickets') !== false, 'admin sees the Alert tickets card');
$ok(strpos($body, 'Create a Maintenance category') === false, 'no Maintenance button when a Maintenance ticket category exists');
$post = fn(array $f, string $jarf = null, string $t = null) => $http('POST', '/admin/post.php', $jarf ?? $jar, $f + ['csrf_token' => $t ?? $tok], $REF);
$state = fn() => [$one("SELECT config_rmm_alert_ticket_category_id FROM settings WHERE company_id=1"), (int) $one("SELECT COUNT(*) FROM rmm_alert_ticket_routes")];
$q("UPDATE settings SET config_rmm_alert_ticket_category_id = NULL WHERE company_id = 1");

$post(['save_rmm_alert_ticket_settings' => 1, 'alert_ticket_category_id' => $catMaint], null, 'wrong');
$ok($state() === [null, 0], 'wrong CSRF token: nothing saved');
$post(['save_rmm_alert_ticket_settings' => 1, 'alert_ticket_category_id' => $catMaint, 'route_when' => ['severity:warning', 'check_prefix:disk'], 'route_category' => [$catWarn, $catDisk], 'route_priority' => ['', 'Low']]);
$ok($state() === [(string) $catMaint, 2], 'valid save: default and two routes stored');
$r = $rows("SELECT * FROM rmm_alert_ticket_routes ORDER BY sort");
$ok($r[0]['match_type'] === 'severity' && $r[0]['match_value'] === 'warning' && (int) $r[0]['category_id'] === $catWarn && $r[0]['priority'] === null && $r[1]['priority'] === 'Low', 'route rows stored as chosen, in order');
[, $body] = $page($jar);
$ok(preg_match('/<option value="check_prefix:disk" selected>/', $body) === 1 && preg_match('/<option value="severity:warning" selected>/', $body) === 1, 'the card shows the saved routes selected');

$bad = [
  'unknown route type'         => ['route_when' => ['bogus:disk'], 'route_category' => [$catDisk], 'route_priority' => ['']],
  'unknown value'              => ['route_when' => ['check_prefix:nope'], 'route_category' => [$catDisk], 'route_priority' => ['']],
  'SQL in the value'           => ['route_when' => ["severity:warning' OR '1'='1"], 'route_category' => [$catDisk], 'route_priority' => ['']],
  'non-ticket category'        => ['route_when' => ['severity:info'], 'route_category' => [$catExpense], 'route_priority' => ['']],
  'archived category'          => ['route_when' => ['severity:info'], 'route_category' => [$catArchived], 'route_priority' => ['']],
  'missing category'           => ['route_when' => ['severity:info'], 'route_category' => [''], 'route_priority' => ['']],
  'category id as SQL'         => ['route_when' => ['severity:info'], 'route_category' => ["0 OR 1=1"], 'route_priority' => ['']],
  'unknown priority'           => ['route_when' => ['severity:info'], 'route_category' => [$catDisk], 'route_priority' => ['Urgent']],
  'duplicate when'             => ['route_when' => ['severity:info', 'severity:info'], 'route_category' => [$catDisk, $catDisk], 'route_priority' => ['', '']],
  'array where text expected'  => ['route_when' => [['severity:info']], 'route_category' => [$catDisk], 'route_priority' => ['']],
  'not an array at all'        => ['route_when' => 'severity:info', 'route_category' => $catDisk, 'route_priority' => ''],
];
foreach ($bad as $name => $f) {
    $before = $rows("SELECT match_type, match_value, category_id, priority FROM rmm_alert_ticket_routes ORDER BY sort");
    [$c] = $post(['save_rmm_alert_ticket_settings' => 1, 'alert_ticket_category_id' => $catDisk] + $f);
    $after = $rows("SELECT match_type, match_value, category_id, priority FROM rmm_alert_ticket_routes ORDER BY sort");
    $ok($c < 500 && $before === $after && $state()[0] === (string) $catMaint, "hostile/invalid input rejected, nothing changed: $name");
}
$post(['save_rmm_alert_ticket_settings' => 1, 'alert_ticket_category_id' => $catExpense]);
$ok($state()[0] === (string) $catMaint, 'a default that is not a ticket category is rejected');
$post(['save_rmm_alert_ticket_settings' => 1, 'alert_ticket_category_id' => '99999']);
$ok($state()[0] === (string) $catMaint, 'a default that does not exist is rejected');
$many = array_fill(0, 51, 'severity:info');
$post(['save_rmm_alert_ticket_settings' => 1, 'alert_ticket_category_id' => $catDisk, 'route_when' => $many, 'route_category' => array_fill(0, 51, $catDisk)]);
$ok($state() === [(string) $catMaint, 2], 'more than 50 routes is rejected');
$post(['save_rmm_alert_ticket_settings' => 1, 'alert_ticket_category_id' => 0, 'route_when' => ['', ''], 'route_category' => ['', ''], 'route_priority' => ['', '']]);
$ok($state() === [null, 0], 'No category and only blank rows clears everything (back to today\'s behaviour)');
$ok((int) $one("SELECT COUNT(*) FROM logs WHERE log_type='Settings' AND log_action='Edit' AND log_description LIKE '%RMM alert ticket category%'") >= 2, 'audit log entries were written');

// non-admin (a technician) cannot change it
$q("DELETE FROM users WHERE user_email = 'tech-alertcat@scratch.test'");
$q("INSERT INTO users (user_name, user_email, user_password, user_auth_method, user_type, user_status, user_specific_encryption_ciphertext, user_role_id)
    SELECT 'Tech', 'tech-alertcat@scratch.test', user_password, user_auth_method, user_type, 1, user_specific_encryption_ciphertext, 2 FROM users WHERE user_email = '" . $esc((string) getenv('ADMIN_EMAIL')) . "'");
$login('tech-alertcat@scratch.test', $jar2);
[$c, $tb] = $http('GET', '/admin/settings_integrations.php?tab=rmm', $jar2);
$tt = $csrfOf($tb);
$post(['save_rmm_alert_ticket_settings' => 1, 'alert_ticket_category_id' => $catDisk], $jar2, $tt !== '' ? $tt : $tok);
$post(['create_rmm_alert_maintenance_category' => 1], $jar2, $tt !== '' ? $tt : $tok);
$ok($state() === [null, 0], 'a technician cannot change the alert ticket settings (page ' . $c . ')');
$q("DELETE FROM users WHERE user_email = 'tech-alertcat@scratch.test'");
// logged out
[$c] = $http('POST', '/admin/post.php', tempnam(sys_get_temp_dir(), 'ck0'), ['save_rmm_alert_ticket_settings' => 1, 'alert_ticket_category_id' => $catDisk, 'csrf_token' => 'x'], $REF);
$ok($state() === [null, 0], 'logged out: nothing saved');

// Maintenance button: absent -> create -> present; escaping of category names
$q("DELETE FROM categories WHERE category_type = 'Ticket' AND category_name = 'Maintenance'");
$q("UPDATE categories SET category_name = '<script>alert(1)</script>' WHERE category_id = $catDisk");
[, $body] = $page($jar);
$tok = $csrfOf($body);
$ok(strpos($body, 'Create a Maintenance category') !== false, 'Maintenance button shown when there is no Maintenance ticket category');
$ok(strpos($body, '<script>alert(1)</script>') === false && strpos($body, '&lt;script&gt;alert(1)&lt;/script&gt;') !== false, 'category names are escaped in the dropdowns');
$post(['create_rmm_alert_maintenance_category' => 1]);
$m = $rows("SELECT category_id FROM categories WHERE category_type='Ticket' AND category_name='Maintenance'");
$ok(count($m) === 1 && $state()[0] === (string) $m[0]['category_id'], 'the button created one Maintenance Ticket category and made it the default');
$post(['create_rmm_alert_maintenance_category' => 1]);
$ok((int) $one("SELECT COUNT(*) FROM categories WHERE category_type='Ticket' AND LOWER(category_name)='maintenance'") === 1, 'pressing it again does not create a second one');
[, $body] = $page($jar);
$ok(strpos($body, 'Create a Maintenance category') === false, 'the button is gone once Maintenance exists');
$ok(preg_match('/<option value="' . $m[0]['category_id'] . '" selected>Maintenance<\/option>/', $body) === 1, 'Maintenance is selected in the dropdown');

// cleanup
$q("DELETE FROM rmm_alert_ticket_routes"); $setDefault(0);
$q("UPDATE categories SET category_name = 'Disk' WHERE category_id = $catDisk");
echo $fails ? "FAILED\n" : "";
exit($fails ? 1 : 0);
