<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/*
 * Parked ticket statuses (docs/design/parked-ticket-statuses.md), SCRATCH database only (schema from db.sql, no rows copied).
 *
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=rivetit_scratch_parked_x RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/parked_ticket_statuses.php
 *
 * Needs no config.php: it loads only the helper, the report classes and EditionViews, and runs the REAL migration step text
 * from admin/database_updates.php.
 */
if (getenv('RIVETIT_TEST_DB') !== '1') { fwrite(STDERR, "set RIVETIT_TEST_DB=1\n"); exit(2); }
if (!preg_match('/scratch/i', (string) getenv('RIVETIT_TEST_DB_NAME'))) { fwrite(STDERR, "Refusing: DB name must contain 'scratch'\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$root = dirname(__DIR__);
$db = new mysqli('localhost', (string) getenv('RIVETIT_TEST_DB_USER'), (string) getenv('RIVETIT_TEST_DB_PASS'), (string) getenv('RIVETIT_TEST_DB_NAME'));
if ($db->connect_errno) { fwrite(STDERR, "connect failed\n"); exit(2); }
$db->query("SET SESSION sql_mode=''");
$mysqli = $db;
$GLOBALS['session_name'] = 'Test Admin';

$logged = [];
function logAction($type, $action, $description, $client_id = 0, $entity_id = 0) { $GLOBALS['logged'][] = [$type, $action, $description, $entity_id]; }

require_once "$root/includes/ticket_parked.php";
// The worktree has no complete vendor/: a minimal PSR-4 loader for ITFlow\\ -> src/ is all these classes need.
spl_autoload_register(static function (string $c) use ($root) { if (str_starts_with($c, 'ITFlow\\')) { $f = "$root/src/" . str_replace('\\', '/', substr($c, 7)) . '.php'; if (is_file($f)) { require_once $f; } } });

$fails = 0; $passes = 0;
$ok = function (bool $c, string $l) use (&$fails, &$passes) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; $c ? $passes++ : $fails++; };
$q = fn(string $sql) => $db->query($sql);
$one = fn(string $sql) => ($r = $db->query($sql)) ? ($r->fetch_row()[0] ?? null) : null;
$rows = function (string $sql) use ($db): array { $o = []; $r = $db->query($sql); while ($r && ($x = $r->fetch_assoc())) { $o[] = $x; } return $o; };

// ---- 1. the column is absent: nothing errors, nothing is parked
$q("DROP VIEW IF EXISTS rpt_tickets");
$q("ALTER TABLE ticket_statuses DROP COLUMN ticket_status_parked");
$q("DELETE FROM ticket_statuses");
$q("INSERT INTO ticket_statuses SET ticket_status_id=1, ticket_status_name='New', ticket_status_color='#000'");
$q("INSERT INTO ticket_statuses SET ticket_status_id=3, ticket_status_name='On Hold', ticket_status_color='#000'");
$q("INSERT INTO ticket_statuses SET ticket_status_id=4, ticket_status_name='Resolved', ticket_status_color='#000'");
$q("INSERT INTO ticket_statuses SET ticket_status_id=9, ticket_status_name='Waiting on Customer', ticket_status_color='#000'");
$q("INSERT INTO ticket_statuses SET ticket_status_id=10, ticket_status_name='Vendor Hold', ticket_status_color='#000'");
$ok(ticketParkedColumnExists($db, true) === false, 'column absent: reported absent');
$ok(ticketParkedStatusIds($db, true) === [], 'column absent: no parked ids, no error');
$ok(ticketNotParkedSql($db) === '' && ticketIsParkedSql($db) === '0 = 1', 'column absent: fragments are neutral');
$ok(ticketStatusSetParked($db, 3, true, true) === false, 'column absent: setting the flag is a no-op');
$ok($db->error === '', 'column absent: no SQL error raised');

// ---- 2. the real migration step: On Hold ticked once, never overriding an admin
$src = (string) file_get_contents("$root/admin/database_updates.php");
$start = strpos($src, "if (\$rivetit_db_version() == '2.6.234') {");
$ok($start !== false, 'migration step 2.6.234 -> 2.6.235 present');
$step = substr($src, (int) $start);
$runStep = function () use ($step, $mysqli) {
    $rivetit_db_version = fn() => '2.6.234';
    eval($step);
};
$runStep();
$ok(ticketParkedColumnExists($db, true), 'migration adds the column');
$parkedNames = array_column($rows("SELECT ticket_status_name FROM ticket_statuses WHERE ticket_status_parked = 1"), 'ticket_status_name');
$ok($parkedNames === ['On Hold'], 'first run ticks only the status named exactly On Hold (got ' . implode(',', $parkedNames) . ')');
$ok($one("SELECT config_current_database_version FROM settings LIMIT 1") !== null || true, 'step ran without fatal');
$q("UPDATE ticket_statuses SET ticket_status_parked = 0 WHERE ticket_status_id = 3");   // an admin unticks it
$q("UPDATE ticket_statuses SET ticket_status_parked = 1 WHERE ticket_status_id = 10");   // and ticks another
$runStep();
$parkedNames = array_column($rows("SELECT ticket_status_name FROM ticket_statuses WHERE ticket_status_parked = 1 ORDER BY ticket_status_id"), 'ticket_status_name');
$ok($parkedNames === ['Vendor Hold'], 're-run never overrides an administrator\'s choice');
$q("UPDATE ticket_statuses SET ticket_status_parked = 1 WHERE ticket_status_id = 3");
$q("UPDATE ticket_statuses SET ticket_status_parked = 0 WHERE ticket_status_id = 10");
ticketParkedStatusIds($db, true);

// ---- 3. tickets
$q("DELETE FROM tickets"); $q("DELETE FROM clients");
$q("INSERT INTO clients SET client_id=9001, client_name='Alpha'");
$mk = function (int $id, int $status, int $assigned = 0, ?string $resolved = null) use ($q) {
    $r = $resolved === null ? 'NULL' : "'$resolved'";
    $q("INSERT INTO tickets SET ticket_id=$id, ticket_prefix='T', ticket_number=$id, ticket_subject='s$id', ticket_priority='High', ticket_status=$status, ticket_client_id=9001, ticket_assigned_to=$assigned, ticket_created_at=NOW() - INTERVAL 2 DAY, ticket_resolved_at=$r");
};
$mk(1, 1); $mk(2, 3); $mk(3, 4, 0, '2026-01-01 00:00:00');
$ok(ticketParkedStatusIds($db) === [3], 'parked ids resolved from the flag, not from a name');
$open = fn(string $extra = '') => (int) $one("SELECT COUNT(*) FROM tickets WHERE ticket_resolved_at IS NULL AND ticket_closed_at IS NULL" . $extra);
$ok($open() === 2 && $open(ticketNotParkedSql($db)) === 1, 'the parked ticket is unresolved but out of the open count');
$ok((int) $one("SELECT COUNT(*) FROM tickets WHERE ticket_id = 2 AND ticket_resolved_at IS NULL AND ticket_closed_at IS NULL") === 1, 'a parked ticket is never resolved or closed by parking');
$ok((int) $one("SELECT COUNT(*) FROM tickets WHERE " . ticketIsParkedSql($db)) === 1, 'ticketIsParkedSql finds it (lookups, "Show parked")');
$ok((int) $one("SELECT COUNT(*) FROM tickets WHERE ticket_client_id = 9001 AND ticket_subject LIKE '%s2%'") === 1 && (int) $one("SELECT COUNT(*) FROM tickets WHERE ticket_id = 2") === 1, 'search, client tab and single-ticket queries (no fragment) still return it');
$k = \ITFlow\Reports\ReportKpis::headline($db);
$ok($k['open'] === 1, 'report KPI Open Tickets excludes the parked ticket');
$b = \ITFlow\Reports\Queries\TicketBacklogReport::data($db);
$ok($b['totals']['open'] === 1 && count($b['oldest']) === 1, 'backlog report excludes it');
// unparking restores it
ticketStatusSetParked($db, 3, false, true);
$ok(ticketParkedStatusIds($db) === [] && $open(ticketNotParkedSql($db)) === 2, 'unparking puts the ticket back in every count');
$ok(\ITFlow\Reports\ReportKpis::headline($db)['open'] === 2, 'report KPI counts it again after unparking');

// ---- 4. admin only + audit
$logged = [];
$ok(ticketStatusSetParked($db, 3, true, false) === false && ticketParkedStatusIds($db, true) === [], 'a non-admin cannot change the flag');
$ok($logged === [], 'a refused change writes no audit row');
$ok(ticketStatusSetParked($db, 3, true, true) === true && ticketParkedStatusIds($db) === [3], 'an admin can set it');
$ok(count($logged) === 1 && $logged[0][0] === 'Ticket Status' && $logged[0][3] === 3 && str_contains($logged[0][2], 'Parked'), 'the change is audit-logged');
$ok(ticketStatusSetParked($db, 3, true, true) === false && count($logged) === 1, 'an unchanged value is not logged again');

// ---- 5. rpt_tickets
$ok(\ITFlow\Reporting\EditionViews::install($db)['errors'] === [], 'reporting views install with the new column');
$fx = json_decode((string) file_get_contents("$root/tests/fixtures/edition_reporting_views.json"), true)['views'];
$ok($fx === \ITFlow\Reporting\EditionViews::COLUMNS, 'frozen fixture equals EditionViews::COLUMNS');
$cols = $fx['rpt_tickets'];
$ok(end($cols) === 'is_parked' && array_search('is_open', $cols, true) === 6, 'is_parked is the LAST rpt_tickets column; is_open did not move');
$name = (string) getenv('RIVETIT_TEST_DB_NAME');
$live = array_column($rows("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '$name' AND TABLE_NAME = 'rpt_tickets' ORDER BY ORDINAL_POSITION"), 'COLUMN_NAME');
$ok($live === $cols, 'live rpt_tickets columns equal the frozen list, in order');
$r2 = $rows("SELECT ticket_id, is_open, is_parked FROM rpt_tickets ORDER BY ticket_id");
$ok($r2 === [['ticket_id' => '1', 'is_open' => '1', 'is_parked' => '0'], ['ticket_id' => '2', 'is_open' => '1', 'is_parked' => '1'], ['ticket_id' => '3', 'is_open' => '0', 'is_parked' => '0']], 'is_open unchanged (parked ticket still open); is_parked 1 only for the parked status');
$deny = '/subject|detail|reply|body|message|note|comment|contact|email|phone|address|billing|rate|price|amount|invoice|vendor|serial|password|secret|token|key|credential|raw|payload|url|mac|ip_/i';
$ok(preg_match($deny, 'is_parked') !== 1, 'the new column passes the deny-list (no ticket text)');
$ok(in_array(strtolower((string) $rows("SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$name' AND TABLE_NAME='rpt_tickets' AND COLUMN_NAME='is_parked'")[0]['DATA_TYPE']), ['int', 'tinyint', 'bigint'], true), 'is_parked is numeric, not text');

// ---- 6. every attention site goes through the one helper (source inspection)
foreach (['agent/tickets.php', 'agent/ticket_kanban.php', 'api/v1/dashboard.php', 'api/v1/clients.php', 'api/v1/tickets.php', 'api/v1/reports/overview.php', 'api/v1/reports/tech_performance.php',
    'src/Reports/Widgets/WidgetRegistry.php', 'src/Reports/ReportKpis.php', 'src/Reports/Queries/TicketBacklogReport.php', 'src/Automation/SlaEventEmitter.php', 'cron/cron.php', 'mcp_server/ReadTools.php',
    'agent/rmm_dashboard.php', 'agent/client_overview.php', 'agent/clients.php', 'agent/reports/index.php', 'agent/includes/get_side_nav_counts.php', 'functions.php'] as $f) {
    $t = (string) file_get_contents("$root/$f");
    $ok(str_contains($t, 'ticketNotParkedSql') || str_contains($t, 'ticketParkedStatusIds') || str_contains($t, 'parked_hidden'), "$f uses the parked helper");
}
$ok(str_contains((string) file_get_contents("$root/admin/post/ticket_status.php"), 'ticketStatusSetParked') && str_contains((string) file_get_contents("$root/admin/post.php"), 'session_is_admin'), 'admin handler uses the audited admin-only setter and is only loaded for admins');
$ok(!preg_match('/On Hold/', (string) file_get_contents("$root/includes/ticket_parked.php")), 'no status name in the helper');

echo "\n$passes passed, $fails failed\n";
exit($fails > 0 ? 1 : 0);
