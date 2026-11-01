<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/*
 * Administration > Reporting / Power BI over HTTP (real pages, forged sessions, SCRATCH database) and the account lifecycle against a THROWAWAY
 * MariaDB 10.11 that holds a copy of the scratch schema (never a live server):
 *   - page: administrators only (403 for a technician), CSRF enforced, every POST needs the administrator flag
 *   - create: the secret page shows the password once with Cache-Control: no-store; the password is in no audit row, session file, later page or error
 *   - the account can read the views (the Core ones and RivetIT's) and nothing else; the audit view only when asked for
 *   - rotate: the old password stops working; drop: the account is gone; a wrong administrator login says so without echoing any password
 *   - connection test: PASS/FAIL per check with the exact error, no password
 *   - hostile input (account name, host, XSS) is refused or escaped
 *
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=x_scratch RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... \
 *   RIVETIT_TEST_RPT_PORT=55307 RIVETIT_TEST_RPT_ROOT_PASSWORD=... RIVETIT_TEST_SCHEMA_DUMP=/path/to/scratch-schema.sql php tests/reporting_page.php
 * The container must be mariadb:10.11 on 127.0.0.1 with a root password; the test loads the scratch dump into it (user parity_t is created for the view definers).
 */
require __DIR__ . '/support/security_lib.php';
require_once "$root/includes/security_crypto.php";

$port = getenv('RIVETIT_TEST_RPT_PORT');
$rootpw = (string) getenv('RIVETIT_TEST_RPT_ROOT_PASSWORD');
$dump = (string) getenv('RIVETIT_TEST_SCHEMA_DUMP');
if (!$port || $rootpw === '' || !is_file($dump)) {
    echo "SKIP  needs RIVETIT_TEST_RPT_PORT, RIVETIT_TEST_RPT_ROOT_PASSWORD and RIVETIT_TEST_SCHEMA_DUMP (throwaway mariadb:10.11)\n";
    exit(0);
}
$dbname = getenv('RIVETIT_TEST_DB_NAME');
mysqli_report(MYSQLI_REPORT_OFF);
$srv = new mysqli('127.0.0.1', 'root', $rootpw, '', (int) $port);
$ok(!$srv->connect_errno, 'throwaway MariaDB reachable');
$srv->query("DROP DATABASE IF EXISTS `$dbname`");
$srv->query("CREATE DATABASE `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
$srv->query("CREATE USER IF NOT EXISTS '" . getenv('RIVETIT_TEST_DB_USER') . "'@'localhost'");
$srv->query("GRANT ALL ON *.* TO '" . getenv('RIVETIT_TEST_DB_USER') . "'@'localhost'");
$cmd = 'mysql -h 127.0.0.1 -P ' . (int) $port . ' -uroot ' . escapeshellarg($dbname) . ' < ' . escapeshellarg($dump) . ' 2>&1';
putenv('MYSQL_PWD=' . $rootpw);
[$rc, $out] = sec_run($cmd);
putenv('MYSQL_PWD');
$ok($rc === 0, 'the scratch schema is loaded into the throwaway server' . ($rc ? ": $out" : ''));
$srv->select_db($dbname);
$ok((int) $srv->query("SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA = '$dbname' AND TABLE_NAME = 'rpt_tickets'")->fetch_row()[0] === 1, 'the throwaway server has the reporting views');

function sec_run(string $cmd): array { exec($cmd, $o, $c); return [$c, implode("\n", $o)]; }

$q("DELETE FROM users WHERE user_id IN (741,742)");
$q("DELETE FROM user_roles WHERE role_id IN (97,98)");
$q("INSERT INTO user_roles SET role_id=97, role_name='Reporting Admin', role_is_admin=1, role_type=1");
$q("INSERT INTO user_roles SET role_id=98, role_name='Reporting Tech', role_is_admin=0, role_type=1");
foreach ([741 => [97, 'rpadmin'], 742 => [98, 'rptech']] as $id => [$role, $tag]) {
    $q("INSERT INTO users SET user_id=$id, user_name='Reporting $tag', user_email='rp-$tag@example.test', user_password='x', user_type=1, user_status=1, user_role_id=$role");
    $q("INSERT INTO user_settings SET user_id=$id ON DUPLICATE KEY UPDATE user_id=user_id");
}
$q("DELETE FROM audit_events WHERE event_type LIKE 'reporting.%'");
$sdir = sys_get_temp_dir() . '/rp_sess_' . bin2hex(random_bytes(3));
mkdir($sdir, 0700);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
$base = sec_start_server($sdir);
$csrf = 'csrftok12345678';
$sess = fn (int $uid) => sec_forge_session($sdir, ['logged' => true, 'user_id' => $uid, 'csrf_token' => $csrf, 'sec_created' => time(), 'sec_last' => time()]);
$admin = $sess(741); $tech = $sess(742);
$ref = ['Referer: ' . $base . '/admin/reporting.php'];
$post = fn (string $sid, array $f, bool $withCsrf = true) => sec_web($base, 'POST', '/admin/post.php', $sid, $withCsrf ? $f + ['csrf_token' => $csrf] : $f, [], $ref);

[$c, $body] = sec_web($base, 'GET', '/admin/reporting.php', $admin);
$ok($c === 200 && str_contains($body, 'Reporting / Power BI') && str_contains($body, 'rpt_tickets') && str_contains($body, 'RLS is not a tenant boundary'), 'administrator: the page renders with the views and the RLS warning');
[$c, $body] = sec_web($base, 'GET', '/admin/reporting.php', $tech);
$ok($c === 403 && !str_contains($body, 'Create / rotate account'), 'technician: the page is refused (' . $c . ')');

$acct = 'rpt_page_test';
$res = $srv->query("SELECT Host FROM mysql.user WHERE User = '$acct'");
while ($res && ($old = $res->fetch_row())) { $srv->query("DROP USER IF EXISTS '$acct'@'" . $srv->real_escape_string($old[0]) . "'"); }
$good = ['provision_reporting' => 1, 'account_user' => $acct, 'account_host' => '172.17.%', 'public_host' => 'db.example.test', 'require_tls' => '', 'db_host' => '127.0.0.1', 'db_port' => $port, 'admin_user' => 'root', 'admin_pass' => $rootpw];
$exists = fn () => (int) $srv->query("SELECT COUNT(*) FROM mysql.user WHERE User = '$acct'")->fetch_row()[0];
[$c] = $post($tech, $good);
$ok($exists() === 0, 'technician: a create POST changes nothing');
[$c] = $post($admin, $good, false);
$ok($exists() === 0, 'a create POST without the CSRF token changes nothing');
foreach (['account_user' => "x'; DROP USER root; --", 'account_host' => "%'; --"] as $k => $v) {
    $post($admin, [$k => $v] + $good);
    $ok($exists() === 0, "hostile $k is refused and nothing is created");
}
$bad = $post($admin, ['admin_pass' => 'WRONG-ADMIN-PASS-123'] + $good);
$ok($exists() === 0 && !str_contains($bad[1], 'WRONG-ADMIN-PASS-123'), 'a wrong administrator login creates nothing and the page never echoes the password typed');
[$c, $page] = sec_web($base, 'GET', '/admin/reporting.php', $admin);
$ok(!str_contains($page, 'WRONG-ADMIN-PASS-123') && !str_contains($page, $rootpw), 'neither administrator password is in a later page');

// create
[$c, $body, $hdr] = $post($admin, $good);
$ok($c === 200 && stripos($hdr, 'no-store') !== false, 'create: the secret page answers 200 with Cache-Control: no-store');
$ok(preg_match('/id="rp_pw" value="([^"]+)"/', $body, $m) === 1, 'create: the password is shown');
$pw1 = $m[1] ?? '';
$ok(strlen($pw1) >= 24 && str_contains($body, 'db.example.test:' . $port), 'create: a long generated password and the host Power BI will use');
$ok($exists() === 1, 'create: the account exists on the throwaway server');
[$c, $page] = sec_web($base, 'GET', '/admin/reporting.php', $admin);
$ok(!str_contains($page, $pw1) && !str_contains($page, $rootpw), 'the password is not on the page afterwards');
$leak = false;
foreach (glob("$sdir/*") ?: [] as $f) { if (str_contains((string) file_get_contents($f), $pw1) || str_contains((string) file_get_contents($f), $rootpw)) { $leak = true; } }
$ok(!$leak, 'neither password is in any session file');
$aud = json_encode($rows("SELECT * FROM audit_events WHERE event_type LIKE 'reporting.%'"));
$ok(str_contains($aud, 'reporting.account.created') && !str_contains($aud, $pw1) && !str_contains($aud, $rootpw) && str_contains($aud, $acct), 'the audit row names the account and carries no password');
$logs = json_encode($rows("SELECT log_description FROM logs ORDER BY log_id DESC LIMIT 20"));
$ok(!str_contains($logs, $pw1) && !str_contains($logs, $rootpw), 'no password in the application log');

// what the account can do
$as = function (string $pw) use ($port, $acct, $dbname) {
    $c = new mysqli('127.0.0.1', $acct, $pw, $dbname, (int) $port);
    return $c->connect_errno ? null : $c;
};
$c1 = $as($pw1);
$ok($c1 !== null, 'the account signs in with the shown password');
$can = fn (mysqli $c, string $sql) => (bool) @$c->query($sql);
foreach (['rpt_devices', 'rpt_tickets', 'rpt_clients', 'rpt_sla', 'rpt_assets', 'rpt_sites', 'rpt_alert_tickets', 'rpt_alerts'] as $v) {
    $ok($c1 && $can($c1, "SELECT 1 FROM `$v` LIMIT 1"), "the account can read $v");
}
foreach (['users', 'clients', 'tickets', 'assets', 'settings', 'rpt_audit_events'] as $t) {
    $ok($c1 && !$can($c1, "SELECT 1 FROM `$t` LIMIT 1"), "the account cannot read $t");
}
$ok($c1 && !$can($c1, "INSERT INTO rpt_sla VALUES (1,'x',1,1,1,1,1,1)") && !$can($c1, 'DROP VIEW rpt_sla'), 'the account cannot write or drop');
$c1 && $c1->close();

// test connection action (the exact error, no password)
$test = $post($admin, ['test_reporting' => 1, 'test_host' => '127.0.0.1', 'test_port' => $port, 'account_user' => $acct, 'test_pass' => $pw1]);
[$c, $page] = sec_web($base, 'GET', '/admin/reporting.php', $admin);
$ok($c === 200, 'page after the test post renders');
$post($admin, ['test_reporting' => 1, 'test_host' => '127.0.0.1', 'test_port' => $port, 'account_user' => $acct, 'test_pass' => 'wrong-account-pass']);
$aud = json_encode($rows("SELECT * FROM audit_events WHERE event_type = 'reporting.account.tested'"));
$ok(str_contains($aud, 'pass') && str_contains($aud, 'fail') && !str_contains($aud, $pw1) && !str_contains($aud, 'wrong-account-pass'), 'tests are audited (pass and fail) without any password');
$checks = \ITFlow\Reporting\ReportingAdmin::testConnection('127.0.0.1', (int) $port, $dbname, $acct, $pw1, false);
$ok(count($checks) === 4 && !array_filter($checks, fn ($x) => !$x['ok']), 'testConnection: four checks, all PASS (connect, read a view, refused on users and clients)');
$bad = \ITFlow\Reporting\ReportingAdmin::testConnection('127.0.0.1', (int) $port, $dbname, $acct, 'nope-nope', false);
$ok($bad[0]['ok'] === false && str_contains($bad[0]['detail'], 'Access denied') && !str_contains($bad[0]['detail'], 'nope-nope'), 'testConnection: a wrong password fails with the server\'s text and without the password');

// rotate
[$c, $body] = $post($admin, $good);
preg_match('/id="rp_pw" value="([^"]+)"/', $body, $m2);
$pw2 = $m2[1] ?? '';
$ok($pw2 !== '' && $pw2 !== $pw1, 'rotate: a different password is generated');
$ok($as($pw1) === null && $as($pw2) !== null, 'rotate: the old password stops working, the new one works');

// include the audit view only when asked
$post($admin, ['include_audit' => '1'] + $good);
$ok(($c2 = $as($pw2)) === null, 'a third create rotates again (old password invalid)');
[$c, $body] = $post($admin, ['include_audit' => '1'] + $good);
preg_match('/id="rp_pw" value="([^"]+)"/', $body, $m3);
$c3 = $as($m3[1] ?? '');
$ok($c3 !== null && $can($c3, 'SELECT 1 FROM rpt_audit_events LIMIT 1'), 'with "also grant the audit view" the audit view is readable');
$c3 && $c3->close();
[$c, $body] = $post($admin, $good);
preg_match('/id="rp_pw" value="([^"]+)"/', $body, $m4);
$c4 = $as($m4[1] ?? '');
$ok($c4 !== null && !$can($c4, 'SELECT 1 FROM rpt_audit_events LIMIT 1'), 'and rotating without it takes the audit view away again');
$c4 && $c4->close();

// TLS required: a plain connection is refused by the server
[$c, $body] = $post($admin, ['require_tls' => '1'] + $good);
preg_match('/id="rp_pw" value="([^"]+)"/', $body, $m5);
$ok(($m5[1] ?? '') !== '' && $as($m5[1]) === null && str_contains($body, 'Required'), 'with "Require TLS" a connection without TLS is refused by the server');

// drop
$post($admin, ['drop_reporting' => 1, 'account_user' => $acct, 'account_host' => '172.17.%', 'confirm_name' => 'wrong', 'db_host' => '127.0.0.1', 'db_port' => $port, 'admin_user' => 'root', 'admin_pass' => $rootpw]);
$ok($exists() === 1, 'drop without the typed confirmation does nothing');
$post($tech, ['drop_reporting' => 1, 'account_user' => $acct, 'account_host' => '172.17.%', 'confirm_name' => $acct, 'db_host' => '127.0.0.1', 'db_port' => $port, 'admin_user' => 'root', 'admin_pass' => $rootpw]);
$ok($exists() === 1, 'technician: drop does nothing');
$post($admin, ['drop_reporting' => 1, 'account_user' => $acct, 'account_host' => '172.17.%', 'confirm_name' => $acct, 'db_host' => '127.0.0.1', 'db_port' => $port, 'admin_user' => 'root', 'admin_pass' => $rootpw]);
$ok($exists() === 0, 'drop with the typed confirmation removes the account');
$aud = json_encode($rows("SELECT event_type FROM audit_events WHERE event_type LIKE 'reporting.%'"));
foreach (['created', 'dropped', 'tested'] as $e) { $ok(str_contains($aud, "reporting.account.$e"), "audit event reporting.account.$e exists"); }

// Power Query for this install
[$c, $page] = sec_web($base, 'GET', '/admin/reporting.php?pq=rpt_devices&pq_server=' . rawurlencode('db.example.test:3306'), $admin);
$ok(str_contains($page, 'ServerName = &quot;db.example.test:3306&quot;') && str_contains($page, 'DatabaseName = &quot;' . $dbname . '&quot;'), 'Power Query is filled with this install\'s server and database');
[$c, $page] = sec_web($base, 'GET', '/admin/reporting.php?pq=rpt_devices&pq_server=' . rawurlencode('"><script>alert(1)</script>'), $admin);
$ok(!str_contains($page, '<script>alert(1)</script>'), 'a hostile server text is escaped');
$srv->query("DROP DATABASE IF EXISTS `$dbname`");
$srv->query("DROP USER IF EXISTS '" . getenv('RIVETIT_TEST_DB_USER') . "'@'localhost'");

// ------------------------------------------------------------------ PostgreSQL role (optional: a throwaway PostgreSQL whose RMM schema is migrated)
$pgPort = getenv('RIVETIT_TEST_PG_PORT');
$pgPw = (string) getenv('RIVETIT_TEST_PG_PASSWORD');
if (!$pgPort || $pgPw === '') {
    echo "SKIP  PostgreSQL role: set RIVETIT_TEST_PG_PORT / RIVETIT_TEST_PG_PASSWORD (throwaway container with the RMM schema, e.g. after tests/backup_rmm_postgres.php's switch)\n";
    return;
}
$cfg = \RivetCore\Rmm\Store\Postgres\PostgresConfig::fromArray(['host' => '127.0.0.1', 'port' => (int) $pgPort, 'database' => getenv('RIVETIT_TEST_PG_DB') ?: 'rmm_scratch', 'user' => getenv('RIVETIT_TEST_PG_USER') ?: 'postgres', 'password' => $pgPw, 'sslmode' => 'prefer', 'schema' => 'public']);
$res = \ITFlow\Reporting\ReportingAdmin::provisionPostgres($cfg, 'rpt_pg_test');
$ok(strlen($res['password']) >= 24 && $res['statements'] >= 3, 'PostgreSQL: the role is created with a generated password');
$conn = fn (string $pw) => new PDO('pgsql:host=127.0.0.1;port=' . (int) $pgPort . ';dbname=' . (getenv('RIVETIT_TEST_PG_DB') ?: 'rmm_scratch'), 'rpt_pg_test', $pw, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
try { $p = $conn($res['password']); $ok(true, 'PostgreSQL: the role signs in'); } catch (PDOException $e) { $p = null; $ok(false, 'PostgreSQL: the role signs in: ' . $e->getMessage()); }
if ($p) {
    $v = $p->query("SELECT table_name FROM information_schema.views WHERE table_schema = 'public' AND table_name LIKE 'rpt\\_%' LIMIT 1")->fetchColumn();
    $ok($v !== false && $p->query('SELECT 1 FROM ' . $v . ' LIMIT 1') !== false, 'PostgreSQL: the role can read a reporting view');
    $refused = false;
    try { $p->query('SELECT 1 FROM rmm_devices LIMIT 1'); } catch (PDOException $e) { $refused = true; }
    $ok($refused, 'PostgreSQL: the role cannot read a base table');
}
$res2 = \ITFlow\Reporting\ReportingAdmin::provisionPostgres($cfg, 'rpt_pg_test');
$stale = false; try { $conn($res['password']); } catch (PDOException $e) { $stale = true; }
$ok($stale && $res2['password'] !== $res['password'], 'PostgreSQL: rotating invalidates the old password');
\ITFlow\Reporting\ReportingAdmin::provisionPostgres($cfg, 'rpt_pg_test', true);
$gone = false; try { $conn($res2['password']); } catch (PDOException $e) { $gone = true; }
$ok($gone, 'PostgreSQL: dropping removes the role');
try { \ITFlow\Reporting\ReportingAdmin::provisionPostgres(\RivetCore\Rmm\Store\Postgres\PostgresConfig::fromArray(['host' => '127.0.0.1', 'port' => (int) $pgPort, 'database' => 'rmm_scratch', 'user' => 'postgres', 'password' => 'WRONG-PG-PW', 'sslmode' => 'prefer', 'schema' => 'public']), 'rpt_pg_test'); $ok(false, 'PostgreSQL: a wrong password fails'); }
catch (InvalidArgumentException $e) { $ok(!str_contains($e->getMessage(), 'WRONG-PG-PW'), 'PostgreSQL: a wrong password fails without echoing it'); }
