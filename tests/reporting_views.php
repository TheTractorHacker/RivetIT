<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/*
 * RivetIT's reporting views (src/Reporting/EditionViews.php), SCRATCH database:
 *   - frozen columns (tests/fixtures/edition_reporting_views.json) and the live view columns agree, in order
 *   - deny-list: no view has a column that could carry free text, contact, billing, vendor, credential or alert payload data
 *   - tenant: two clients, a query filtered to one returns only its rows; archived tickets are not exposed
 *   - SLA columns are empty for a ticket in an SLA-exempt category; the SLA numbers are the ticket's own
 *   - time zones: UTC company = no conversion; a named zone converts (proved on a throwaway MariaDB 10.11 with its time zone tables, if RIVETIT_TEST_TZ_PORT is set);
 *     without the tables the views are created unconverted and install() says so
 *   - the migration step is idempotent
 *
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=x_scratch RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/reporting_views.php
 *   [RIVETIT_TEST_TZ_PORT=55306 RIVETIT_TEST_TZ_ROOT_PASSWORD=...]   (docker run mariadb:10.11, 127.0.0.1 only; the tz tables are loaded by this test)
 */
require __DIR__ . '/support/security_lib.php';

use ITFlow\Reporting\EditionViews;

$fx = json_decode((string) file_get_contents(__DIR__ . '/fixtures/edition_reporting_views.json'), true)['views'];
$db_name = getenv('RIVETIT_TEST_DB_NAME');
$ok($fx === EditionViews::COLUMNS, 'the frozen fixture equals EditionViews::COLUMNS');

$r0 = EditionViews::install($db);
$ok($r0['errors'] === [] && count($r0['created']) === count(EditionViews::COLUMNS), 'install creates all ' . count(EditionViews::COLUMNS) . ' views without errors: ' . implode('; ', $r0['errors']));
$r1 = EditionViews::install($db);
$ok($r1['errors'] === [] && $r1['created'] === $r0['created'], 'install is idempotent');
$ok($r0['utc'] === true && $r0['converted'] === false && $r0['warning'] === null, 'a UTC company needs no conversion and no warning');

foreach ($fx as $view => $cols) {
    $live = array_column($rows("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '$db_name' AND TABLE_NAME = '$view' ORDER BY ORDINAL_POSITION"), 'COLUMN_NAME');
    $ok($live === $cols, "$view: live columns equal the frozen columns");
}

// deny-list
$deny = '/subject|detail|reply|body|message|note|comment|contact|email|phone|address|billing|rate|price|amount|invoice|vendor|serial|password|secret|token|key|credential|raw|payload|url|mac|ip_/i';
foreach ($fx as $view => $cols) {
    $bad = array_values(array_filter($cols, static fn ($c) => preg_match($deny, $c) === 1));
    $ok($bad === [], "$view: no deny-listed column" . ($bad ? ' (' . implode(',', $bad) . ')' : ''));
}
// tenant + content
foreach (['tickets', 'assets', 'locations', 'clients', 'rmm_alerts', 'sla_policies'] as $t) { $q("DELETE FROM $t"); }
$q("INSERT INTO clients SET client_id=9001, client_name='Alpha', client_created_at='2026-01-01 08:00:00'");
$q("INSERT INTO clients SET client_id=9002, client_name='Beta', client_created_at='2026-01-02 08:00:00'");
$q("INSERT INTO locations SET location_id=9101, location_name='HQ', location_client_id=9001, location_address='1 Secret St'");
$q("INSERT INTO assets SET asset_id=9201, asset_type='Laptop', asset_name='A-PC', asset_serial='SERIAL-A', asset_client_id=9001, asset_location_id=9101, asset_created_at='2026-02-01 10:00:00'");
$q("INSERT INTO assets SET asset_id=9202, asset_type='Server', asset_name='B-SRV', asset_serial='SERIAL-B', asset_client_id=9002, asset_location_id=0, asset_created_at='2026-02-02 10:00:00'");
$q("INSERT INTO ticket_statuses SET ticket_status_id=9301, ticket_status_name='Open Test', ticket_status_active=1, ticket_status_order=1") ;
$q("INSERT INTO tickets SET ticket_id=9401, ticket_prefix='T', ticket_number=1, ticket_subject='TOP SECRET SUBJECT', ticket_details='SECRET DETAILS', ticket_priority='High', ticket_status=9301, ticket_client_id=9001, ticket_location_id=9101, ticket_asset_id=9201,
    ticket_created_at='2026-03-01 09:00:00', ticket_first_response_at='2026-03-01 09:30:00', ticket_resolved_at='2026-03-01 11:00:00', ticket_sla_response_due='2026-03-01 10:00:00', ticket_sla_resolution_due='2026-03-01 17:00:00', ticket_sla_response_met=1, ticket_sla_resolution_met=1, ticket_csat_rating=5, ticket_counts_sla=1");
$q("INSERT INTO tickets SET ticket_id=9402, ticket_prefix='T', ticket_number=2, ticket_subject='B subject', ticket_priority='Low', ticket_status=9301, ticket_client_id=9002, ticket_location_id=0, ticket_asset_id=0, ticket_created_at='2026-03-02 09:00:00', ticket_sla_response_due='2026-03-02 10:00:00', ticket_sla_response_met=0, ticket_counts_sla=0");
$q("INSERT INTO tickets SET ticket_id=9403, ticket_prefix='T', ticket_number=3, ticket_subject='archived', ticket_priority='Low', ticket_status=9301, ticket_client_id=9001, ticket_created_at='2026-03-03 09:00:00', ticket_archived_at='2026-03-04 09:00:00'");
$q("INSERT INTO rmm_alerts SET id=9501, client_id=9001, severity='high', status='open', message='SECRET ALERT MESSAGE', raw_data_json='{\"secret\":1}', ticket_id=9401, created_at='2026-03-01 09:00:00'");
$q("INSERT INTO rmm_alerts SET id=9502, client_id=9002, severity='low', status='open', message='no ticket', created_at='2026-03-01 09:00:00'");
$q("INSERT INTO sla_policies SET policy_id=9601, policy_name='Gold', policy_low_response=60, policy_low_resolution=480, policy_medium_response=30, policy_medium_resolution=240, policy_high_response=15, policy_high_resolution=120");

$cnt = fn (string $sql) => (int) $one($sql);
$ok($cnt('SELECT COUNT(*) FROM rpt_clients') === 2, 'rpt_clients: both clients');
$ok($cnt('SELECT COUNT(*) FROM rpt_clients WHERE client_id = 9001') === 1 && $cnt("SELECT COUNT(*) FROM rpt_assets WHERE client_id = 9001") === 1 && $cnt("SELECT COUNT(*) FROM rpt_assets WHERE client_id = 9002") === 1, 'tenant: a query filtered to one client returns only its assets');
$ok($cnt('SELECT COUNT(*) FROM rpt_tickets WHERE client_id = 9001') === 1 && $cnt('SELECT COUNT(*) FROM rpt_tickets WHERE client_id = 9002') === 1, 'tickets: one each; the archived ticket is not exposed');
$ok($cnt('SELECT COUNT(*) FROM rpt_sites WHERE client_id = 9002') === 0 && $cnt('SELECT COUNT(*) FROM rpt_sites WHERE client_id = 9001') === 1, 'tenant: sites are per client');
$ok($cnt('SELECT COUNT(*) FROM rpt_alert_tickets') === 1 && $cnt('SELECT COUNT(*) FROM rpt_alert_tickets WHERE client_id = 9001 AND ticket_id = 9401') === 1, 'alert-to-ticket: only alerts that have a ticket');
$t1 = $rows('SELECT * FROM rpt_tickets WHERE ticket_id = 9401')[0];
$ok($t1['priority'] === 'High' && $t1['status_name'] === 'Open Test' && (int) $t1['is_open'] === 0 && (int) $t1['first_response_s'] === 1800 && (int) $t1['resolution_s'] === 7200 && (int) $t1['csat_rating'] === 5, 'rpt_tickets: priority, status name, is_open, response and resolution seconds, CSAT');
$ok((int) $t1['sla_applies'] === 1 && $t1['sla_response_due_at'] === '2026-03-01 10:00:00' && (int) $t1['sla_response_met'] === 1, 'rpt_tickets: SLA columns for a ticket that counts toward SLA');
$t2 = $rows('SELECT * FROM rpt_tickets WHERE ticket_id = 9402')[0];
$ok((int) $t2['sla_applies'] === 0 && $t2['sla_response_due_at'] === null && $t2['sla_response_met'] === null, 'rpt_tickets: an SLA-exempt ticket has empty SLA columns');
$ok($t2['asset_id'] === null && $t2['site_id'] === null, 'rpt_tickets: 0 ids are NULL');
$ok((int) $one('SELECT low_response_min FROM rpt_sla WHERE policy_id = 9601') === 60, 'rpt_sla: the policy numbers');
// nothing secret in any view
$all = '';
foreach (EditionViews::names() as $v) { foreach ($rows("SELECT * FROM `$v`") as $row) { $all .= json_encode($row); } }
foreach (['TOP SECRET', 'SECRET DETAILS', 'SERIAL-A', 'SERIAL-B', '1 Secret St', 'SECRET ALERT', 'secret'] as $needle) {
    $ok(stripos($all, $needle) === false, "no view row contains \"$needle\"");
}

// ------------------------------------------------------------------ time zones
$ok(EditionViews::validZone('America/Chicago') === 'America/Chicago' && EditionViews::validZone("x'; DROP TABLE users; --") === null && EditionViews::validZone('') === null, 'a zone name is validated before it reaches a view definition');
$sql = EditionViews::statements('America/Chicago')['rpt_tickets'];
$ok(str_contains($sql, "CONVERT_TZ(t.ticket_created_at, 'America/Chicago', 'UTC')") && !str_contains($sql, "CONVERT_TZ(t.ticket_created_at, 'America/Chicago', 'UTC'), CONVERT_TZ(t.ticket_created_at"), 'a named zone is converted with CONVERT_TZ and the zone NAME');
$ok(!str_contains(EditionViews::statements(null)['rpt_tickets'], 'CONVERT_TZ'), 'no zone: no conversion');
$q("UPDATE settings SET config_timezone = 'America/Chicago' WHERE company_id = 1");
$rz = EditionViews::install($db);
if (EditionViews::zoneUsable($db, 'America/Chicago')) {
    $ok($rz['converted'] === true && $rz['warning'] === null, 'this server has the time zone tables: converted');
} else {
    $ok($rz['converted'] === false && $rz['warning'] !== null && str_contains($rz['warning'], 'time zone tables') && $rz['errors'] === [], 'no time zone tables here: the views are created UNCONVERTED and install() says so');
    $ok(!str_contains((string) ($rows('SHOW CREATE VIEW rpt_tickets')[0]['Create View'] ?? ''), 'CONVERT_TZ'), 'and the view has no half conversion');
}
$q("UPDATE settings SET config_timezone = 'UTC' WHERE company_id = 1");
EditionViews::install($db);

$port = getenv('RIVETIT_TEST_TZ_PORT');
$rootpw = (string) getenv('RIVETIT_TEST_TZ_ROOT_PASSWORD');
if (!$port || $rootpw === '') {
    echo "SKIP  conversion proof: set RIVETIT_TEST_TZ_PORT / RIVETIT_TEST_TZ_ROOT_PASSWORD (throwaway mariadb:10.11)\n";
    return;
}
mysqli_report(MYSQLI_REPORT_OFF);
$m = new mysqli('127.0.0.1', 'root', $rootpw, '', (int) $port);
$ok(!$m->connect_errno, 'throwaway MariaDB reachable');
$m->query('DROP DATABASE IF EXISTS tzproof');
$m->query('CREATE DATABASE tzproof');
$m->select_db('tzproof');
foreach ([
    "CREATE TABLE settings (company_id int, config_timezone varchar(64))", "INSERT INTO settings VALUES (1, 'America/Chicago')",
    "CREATE TABLE clients (client_id int, client_name varchar(200), client_created_at datetime, client_archived_at datetime)",
    "CREATE TABLE locations (location_id int, location_client_id int, location_name varchar(200), location_archived_at datetime)",
    "CREATE TABLE assets (asset_id int, asset_client_id int, asset_location_id int, asset_type varchar(100), asset_name varchar(200), asset_status varchar(100), asset_created_at datetime, asset_archived_at datetime)",
    "CREATE TABLE ticket_statuses (ticket_status_id int, ticket_status_name varchar(100))",
    "CREATE TABLE tickets (ticket_id int, ticket_client_id int, ticket_location_id int, ticket_asset_id int, ticket_priority varchar(20), ticket_status int, ticket_closed_at datetime, ticket_resolved_at datetime,
        ticket_created_at datetime, ticket_first_response_at datetime, ticket_counts_sla tinyint, ticket_sla_response_due datetime, ticket_sla_resolution_due datetime, ticket_sla_response_met tinyint, ticket_sla_resolution_met tinyint,
        ticket_csat_rating int, ticket_archived_at datetime)",
    "CREATE TABLE rmm_alerts (id int, client_id int, ticket_id int)",
    "CREATE TABLE sla_policies (policy_id int, policy_name varchar(100), policy_low_response int, policy_low_resolution int, policy_medium_response int, policy_medium_resolution int, policy_high_response int, policy_high_resolution int, policy_archived_at datetime)",
] as $sql) { $m->query($sql); }
$before = EditionViews::install($m);
if (!EditionViews::zoneUsable($m, 'America/Chicago')) {
    $ok($before['converted'] === false && $before['warning'] !== null && $before['errors'] === [], 'throwaway server WITHOUT time zone tables: unconverted views and a warning');
}
// load the tz tables the supported way, inside the throwaway container only (some images already have them)
$cid = trim((string) shell_exec("docker ps --filter publish=$port --format '{{.ID}}' | head -1"));
if ($cid !== '') {
    $o = shell_exec('docker exec ' . escapeshellarg($cid) . " sh -c 'mariadb-tzinfo-to-sql /usr/share/zoneinfo 2>/dev/null | mariadb -uroot -p\"\$MARIADB_ROOT_PASSWORD\" mysql' 2>&1");
}
$ok(EditionViews::zoneUsable($m, 'America/Chicago'), 'time zone tables loaded in the throwaway container');
$after = EditionViews::install($m);
$ok($after['converted'] === true && $after['warning'] === null && $after['errors'] === [], 'with the tables: converted views');
$m->query("INSERT INTO ticket_statuses VALUES (1, 'Open')");
// 2026-07-01 12:00 Chicago (CDT, UTC-5) = 17:00 UTC; 2026-01-15 12:00 Chicago (CST, UTC-6) = 18:00 UTC. A fixed offset would be wrong for one of them.
$m->query("INSERT INTO tickets (ticket_id, ticket_client_id, ticket_status, ticket_created_at, ticket_counts_sla) VALUES (1, 1, 1, '2026-07-01 12:00:00', 1), (2, 1, 1, '2026-01-15 12:00:00', 1)");
$summer = $m->query('SELECT created_at FROM rpt_tickets WHERE ticket_id = 1')->fetch_row()[0];
$winter = $m->query('SELECT created_at FROM rpt_tickets WHERE ticket_id = 2')->fetch_row()[0];
$ok($summer === '2026-07-01 17:00:00', "summer 12:00 Chicago is 17:00 UTC (got $summer)");
$ok($winter === '2026-01-15 18:00:00', "winter 12:00 Chicago is 18:00 UTC (got $winter)");
$m->query('DROP DATABASE tzproof');
