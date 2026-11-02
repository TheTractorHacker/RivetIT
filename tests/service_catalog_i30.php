<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (pentest F-02)
/*
 * Service catalog issue #30 additions, against the REAL src/ITSM/ServiceCatalogService.php on a scratch database:
 *   - categories and tile grouping; multi-condition show_if; repeating table field (validation, storage, escaping)
 *   - computed risk score (rules, clamping, breakdown) and risk-based routing to different approval chains
 *   - parallel approval groups (all branches needed, any/all inside a branch, one person in two branches, reject, override)
 *   - delegation / out of office (resolution, expiry, requester rule, re-routing of pending rows)
 *   - reminders, escalation and timeouts (worker pass, claim-once, mocked mail + notifications: NOTHING is sent for real)
 *   - request statistics for the report; describeItem()
 * Needs a THROWAWAY database that holds this app's schema (import db.sql); it never reads config.php:
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=...scratch... RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/service_catalog_i30.php
 */
if (getenv('RIVETIT_TEST_DB') !== '1') exit(2);
if (!preg_match('/scratch|test/i', (string) getenv('RIVETIT_TEST_DB_NAME'))) { fwrite(STDERR, "Refusing: DB name must contain scratch/test\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETIT_TEST_DB_USER'), getenv('RIVETIT_TEST_DB_PASS'), getenv('RIVETIT_TEST_DB_NAME'));
if ($mysqli->connect_errno) { fwrite(STDERR, "connect failed\n"); exit(2); }
date_default_timezone_set('UTC');
$q = fn(string $sql) => mysqli_query($mysqli, $sql);
$one = fn(string $sql) => mysqli_fetch_row($q($sql))[0] ?? null;
$q("SET SESSION sql_mode=''"); $q("SET time_zone = '+00:00'"); // the app aligns the session zone with PHP's (inc_set_timezone.php)
if (mysqli_num_rows($q("SHOW COLUMNS FROM ticket_statuses LIKE 'ticket_status_pauses_sla'")) === 0) { $q("ALTER TABLE ticket_statuses ADD COLUMN ticket_status_pauses_sla tinyint(1) NOT NULL DEFAULT 0"); }
if (mysqli_num_rows($q("SHOW COLUMNS FROM tickets LIKE 'ticket_resolution_started_at'")) === 0) { $q("ALTER TABLE tickets ADD COLUMN ticket_resolution_started_at datetime DEFAULT NULL"); }
if (mysqli_num_rows($q("SHOW COLUMNS FROM settings LIKE 'config_ticket_default_status_id'")) === 0) { $q("ALTER TABLE settings ADD COLUMN config_ticket_default_status_id int(11) DEFAULT 0"); }


// Mocks: nothing here ever sends real mail or notifications. The service calls these when they exist.
$GLOBALS['sent_mail'] = []; $GLOBALS['sent_notify'] = [];
function addToMailQueue(array $mails) { foreach ($mails as $m) { $GLOBALS['sent_mail'][] = $m; } }
function notifyUser($uid, $type, $msg, $url = '', $client = 0, $ticket = 0) { $GLOBALS['sent_notify'][] = [$uid, $msg]; }
$config_ticket_from_email = 'noreply@example.test'; $config_ticket_from_name = 'Test';
require __DIR__ . '/../src/ITSM/ServiceCatalogService.php';
use ITFlow\ITSM\ServiceCatalogService as S;
$svc = new S($mysqli);
$fails = 0; $ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };

foreach (['service_catalog_delegations', 'service_catalog_risk_rules', 'service_catalog_categories', 'service_catalog_request_approvals', 'service_catalog_requests', 'service_catalog_approval_steps', 'service_catalog_fields', 'service_catalog_items', 'tickets', 'ticket_replies', 'ticket_statuses', 'contacts', 'users', 'user_roles', 'settings', 'clients'] as $t) { $q("DELETE FROM $t"); }
foreach ([[1, 'New', 0], [2, 'Open', 0], [3, 'On Hold', 1], [4, 'Resolved', 0], [5, 'Closed', 0], [6, 'Assigned', 0]] as [$id, $n, $f]) {
    $q("INSERT INTO ticket_statuses SET ticket_status_id=$id, ticket_status_name='$n', ticket_status_color='#000', ticket_status_pauses_sla=$f, ticket_status_active=1");
}
$q("INSERT INTO settings SET company_id=1, config_current_database_version='2.6.212'");
$q("INSERT INTO clients SET client_id=1, client_name='Dept A'"); $q("INSERT INTO clients SET client_id=2, client_name='Dept B'");
$q("INSERT INTO user_roles SET role_id=1, role_name='Admin', role_is_admin=1, role_type=1");
$q("INSERT INTO user_roles SET role_id=2, role_name='Tech', role_is_admin=0, role_type=1");
// users: 1 admin, 2 admin-archived, 10/11 techs (role 2), 12 tech inactive, 13 other (no role)
foreach ([[1, 1, 1, 'NULL', 1], [2, 1, 1, "NOW()", 1], [10, 1, 1, 'NULL', 2], [11, 1, 1, 'NULL', 2], [12, 1, 0, 'NULL', 2], [13, 1, 1, 'NULL', 0]] as [$id, $type, $st, $arch, $role]) {
    $q("INSERT INTO users SET user_id=$id, user_name='User $id', user_email='u$id@example.test', user_password='x', user_type=$type, user_status=$st, user_archived_at=$arch, user_role_id=$role");
}
// contacts: 100 requester (manager 101), 101 manager, 102 no manager, 103 manager in OTHER dept, 200 dept-B contact
foreach ([[100, 1, 101], [101, 1, 'NULL'], [102, 1, 'NULL'], [103, 2, 'NULL'], [104, 1, 103], [200, 2, 'NULL']] as [$id, $cl, $mgr]) {
    $q("INSERT INTO contacts SET contact_id=$id, contact_name='Contact $id', contact_email='c$id@example.test', contact_client_id=$cl, contact_manager_id=$mgr");
}

$mkItem = function (array $o = []) use ($q, $mysqli) {
    $o += ['name' => 'Item', 'requires_approval' => 0, 'risk_score' => 0, 'auto_approve_below' => 0, 'is_active' => 1];
    $q("INSERT INTO service_catalog_items SET name='" . mysqli_real_escape_string($mysqli, $o['name']) . "', ticket_subject_template='s', requires_approval={$o['requires_approval']}, risk_score={$o['risk_score']}, auto_approve_below={$o['auto_approve_below']}, is_active={$o['is_active']}");
    return mysqli_fetch_assoc($q("SELECT * FROM service_catalog_items WHERE catalog_item_id=" . mysqli_insert_id($mysqli)));
};
$mkTicket = function (int $client = 1, int $contact = 100, ?int $item = null, string $created = 'NOW()', int $assigned = 0) use ($q, $mysqli) {
    static $n = 5000;
    $n++;
    $q("INSERT INTO tickets SET ticket_prefix='T', ticket_number=$n, ticket_subject='Laptop <b>request</b>', ticket_status=1, ticket_client_id=$client, ticket_contact_id=$contact, ticket_assigned_to=$assigned, ticket_catalog_item_id=" . ($item ?? 'NULL') . ", ticket_created_at=$created");
    return mysqli_insert_id($mysqli);
};
$steps = function (int $item, array $rows) use ($svc) { // rows: [type, ref, mode, parallel?, riskMin?, riskMax?]
    $o = ['step_type' => [], 'step_order' => [], 'step_mode' => [], 'step_ref_user' => [], 'step_ref_role' => [], 'step_parallel' => [], 'step_risk_min' => [], 'step_risk_max' => []];
    foreach ($rows as $i => $r) {
        $o['step_type'][$i] = $r[0]; $o['step_order'][$i] = $i + 1; $o['step_mode'][$i] = $r[2];
        $o['step_ref_user'][$i] = $r[0] === 'user' ? $r[1] : 0; $o['step_ref_role'][$i] = $r[0] === 'role' ? $r[1] : 0;
        $o['step_parallel'][$i] = !empty($r[3]) ? 1 : 0; $o['step_risk_min'][$i] = $r[4] ?? 0; $o['step_risk_max'][$i] = $r[5] ?? 100;
    }
    $svc->saveSteps($item, S::normalizeStepRows($o));
};
$ticketStatus = fn(int $t) => intval($one("SELECT ticket_status FROM tickets WHERE ticket_id=$t"));
$reqStatus = fn(int $r) => $one("SELECT status FROM service_catalog_requests WHERE request_id=$r");
$fieldRows = fn(array $defs) => S::normalizeFieldRows(['field_label' => array_column($defs, 0), 'field_type' => array_column($defs, 1), 'field_order' => range(1, count($defs)), 'field_options' => array_map(fn($d) => $d[2] ?? '', $defs), 'field_required' => array_map(fn($d) => $d[3] ?? 0, $defs)]);
$start = fn(array $item, array $answers, int $client = 1, int $contact = 100, int $by = 0) => (function () use ($item, $answers, $client, $contact, $by, $svc, $mkTicket) {
    $chk = S::validateInput($svc->getFields(intval($item['catalog_item_id'])), $answers);
    $t = $mkTicket($client, $contact, intval($item['catalog_item_id']));
    $res = $svc->submit($item, $t, $client, $contact, $by, $chk['values']);
    return [$t, $res['request_id'], $res['status']];
})();
$pendingUsers = fn(int $rid) => array_map('intval', array_column(mysqli_fetch_all($q("SELECT approver_user_id FROM service_catalog_request_approvals WHERE request_id=$rid AND status='pending' ORDER BY approval_id"), MYSQLI_ASSOC), 'approver_user_id'));

// ---------------------------------------------------------------- multi-condition show_if
$single = S::buildShowIfMulti([['a', 'equals', 'x']]);
$ok(json_decode($single, true) === ['field' => 'a', 'op' => 'equals', 'value' => 'x'], 'one condition is stored in the original single-rule shape (older readers keep working)');
$two = S::buildShowIfMulti([['a', 'equals', 'x'], ['b', 'not_empty', ''], ['', 'equals', 'ignored'], ['c', 'bogus', 'y']], 'any');
$rule = S::parseShowIf($two);
$ok($rule !== null && $rule['match'] === 'any' && count($rule['rules']) === 2, 'two usable conditions make a compound rule; blank and unknown-op conditions are dropped');
$ok(S::buildShowIfMulti([['', 'equals', 'x']]) === null, 'no usable condition = no rule');
$ok(S::buildShowIfMulti([['a', 'equals', '1'], ['b', 'equals', '2'], ['c', 'equals', '3'], ['d', 'equals', '4']]) !== null && count(S::parseShowIf(S::buildShowIfMulti([['a', 'equals', '1'], ['b', 'equals', '2'], ['c', 'equals', '3'], ['d', 'equals', '4']]))['rules']) === 3, 'at most three conditions are kept');
$all = S::parseShowIf(S::buildShowIfMulti([['a', 'equals', 'x'], ['b', 'in', 'p|q']], 'all'));
$ok(S::showIfMet($all, ['a' => 'x', 'b' => 'q']) && !S::showIfMet($all, ['a' => 'x', 'b' => 'z']) && !S::showIfMet($all, ['a' => 'no', 'b' => 'p']), 'match=all needs every condition');
$any = S::parseShowIf(S::buildShowIfMulti([['a', 'equals', 'x'], ['b', 'in', 'p|q']], 'any'));
$ok(S::showIfMet($any, ['a' => 'x', 'b' => 'z']) && S::showIfMet($any, ['a' => 'no', 'b' => 'p']) && !S::showIfMet($any, ['a' => 'no', 'b' => 'z']), 'match=any needs one condition');
$ok(S::parseShowIf(['match' => 'all', 'rules' => [['field' => 'a', 'op' => 'equals', 'value' => 'x'], ['field' => 'b', 'op' => 'nope']]]) === null, 'one bad inner rule makes the whole rule malformed (and a malformed rule never hides a field)');
$ok(S::parseShowIf(['match' => 'all', 'rules' => []]) === null, 'an empty compound rule is malformed');
$ok(strpos(S::describeShowIf($any, ['a' => 'Device']), '"Device" is x OR') !== false, 'describeShowIf reads a compound rule');
$rows = $fieldRows([['Device', 'select', "Laptop\nPhone"], ['Reason', 'text'], ['Extra', 'text']]);
$rows[2]['show_if'] = S::buildShowIfMulti([['device', 'equals', 'Laptop'], ['reason', 'not_empty', '']], 'all');
$ok(S::validateShowIf($rows) === [], 'a compound rule on earlier questions validates');
$rows[2]['show_if'] = S::buildShowIfMulti([['device', 'equals', 'Laptop'], ['extra', 'not_empty', '']], 'all');
$ok(count(S::validateShowIf($rows)) === 1, 'a compound rule whose SECOND condition points at itself/forward is refused');
$rows[2]['show_if'] = S::buildShowIfMulti([['device', 'equals', 'Laptop'], ['ghost', 'not_empty', '']], 'all');
$ok(count(S::validateShowIf($rows)) === 1, 'a compound rule pointing at a question that does not exist is refused');
$norm = S::normalizeFieldRows(['field_label' => ['A', 'B'], 'field_type' => ['text', 'text'], 'field_order' => [1, 2], 'field_showif_field' => [1 => 'a'], 'field_showif_op' => [1 => 'equals'], 'field_showif_value' => [1 => 'x'], 'field_showif2_field' => [1 => 'a'], 'field_showif2_op' => [1 => 'not_empty'], 'field_showif_match' => [1 => 'any']]);
$ok(S::parseShowIf($norm[1]['show_if'])['match'] === 'any' && $norm[0]['show_if'] === null, 'the editor posts (condition 1, condition 2, match) into a compound rule');
$f2 = [['field_key' => 'a', 'label' => 'A', 'field_type' => 'text', 'options' => null, 'is_required' => 0],
       ['field_key' => 'b', 'label' => 'B', 'field_type' => 'text', 'options' => null, 'is_required' => 1, 'show_if' => $two = S::buildShowIfMulti([['a', 'equals', 'x'], ['a', 'equals', 'y']], 'all')]];
$ok(S::validateInput($f2, ['a' => 'x'])['errors'] === [], 'server: a required question hidden by an unmet compound rule is neither required nor stored');
$f2[1]['show_if'] = S::buildShowIfMulti([['a', 'equals', 'x'], ['a', 'equals', 'y']], 'any');
$ok(count(S::validateInput($f2, ['a' => 'x'])['errors']) === 1, 'server: the same question becomes required when one condition of match=any holds');

// ---------------------------------------------------------------- repeating table
$norm = S::normalizeFieldRows(['field_label' => ['Monitors', 'Broken'], 'field_type' => ['table', 'table'], 'field_order' => [1, 2], 'field_options' => ["Model\nQty|number\nArrival|date\nQty|text\nModel", '  |  ']]);
$ok(count($norm) === 2 && $norm[0]['field_type'] === 'table' && $norm[0]['options'] === "Model|text\nQty|number\nArrival|date", 'table columns normalised: types kept, duplicate headings dropped');
$ok($norm[1]['field_type'] === 'text', 'a table with no usable column falls back to a text question');
$many = implode("\n", array_map(fn($i) => "C$i", range(1, 12)));
$ok(count(S::tableColumns(S::normalizeTableColumns($many))) === 8, 'at most 8 columns');
$tf = ['field_key' => 'monitors', 'label' => 'Monitors', 'field_type' => 'table', 'options' => "Model\nQty|number\nArrival|date", 'is_required' => 1];
$r = S::validateInput([$tf], ['monitors' => [['Dell', '2', '2026-11-30'], ['', '', ''], ['LG', '1', '']]]);
$vv = json_decode($r['values'][0]['value'] ?? '{}', true);
$ok($r['errors'] === [] && $vv['cols'] === ['Model', 'Qty', 'Arrival'] && count($vv['rows']) === 2 && $vv['rows'][1] === ['LG', '1', ''] && $r['values'][0]['type'] === 'table', 'a table answer keeps its non-blank rows and the column headings');
$r = S::validateInput([$tf], ['monitors' => [['Model' => 'HP', 'Qty' => 4]]]);
$ok($r['errors'] === [] && json_decode($r['values'][0]['value'], true)['rows'] === [['HP', '4', '']], 'the API may send rows as objects keyed by heading (numbers allowed)');
$ok(count(S::validateInput([$tf], ['monitors' => [['', '', '']]])['errors']) === 1 && count(S::validateInput([$tf], [])['errors']) === 1, 'a required table needs at least one non-blank row');
$ok(S::validateInput([array_merge($tf, ['is_required' => 0])], [])['errors'] === [], 'an optional table may be empty');
$ok(count(S::validateInput([$tf], ['monitors' => [['Dell', 'many', '']]])['errors']) === 1, 'a number column rejects text');
$ok(count(S::validateInput([$tf], ['monitors' => [['Dell', '1', '2026-02-31']]])['errors']) === 1, 'a date column rejects an impossible date');
$ok(count(S::validateInput([$tf], ['monitors' => 'oops'])['errors']) === 1 && count(S::validateInput([$tf], ['monitors' => [['x', ['nested'], '']]])['errors']) === 1, 'a scalar, or a nested array cell, is refused');
$ok(count(S::validateInput([$tf], ['monitors' => array_fill(0, 26, ['Dell', '1', ''])])['errors']) === 1 && S::validateInput([$tf], ['monitors' => array_fill(0, 25, ['Dell', '1', ''])])['errors'] === [], '25 rows are accepted, 26 are refused');
$ok(count(S::validateInput([$tf], ['monitors' => [[str_repeat('a', 201), '1', '']]])['errors']) === 1, 'an over-long cell is refused');
$ti = $mkItem(['name' => 'TableItem']); $tid = intval($ti['catalog_item_id']);
$svc->saveFields($tid, $norm);
$gotFields = $svc->getFields($tid);
$ok($gotFields[0]['field_type'] === 'table' && strpos($gotFields[0]['options'], 'Qty|number') !== false, 'the table field round-trips through saveFields/getFields');
[$tk, $trid, $tst] = $start($ti, ['monitors' => [['<script>alert(1)</script>', '2', '']]]);
$html = $svc->requestDetailsHtml($tk);
$ok(strpos($html, '<script>alert') === false && strpos($html, '&lt;script&gt;alert(1)&lt;/script&gt;') !== false && strpos($html, '<table') !== false, 'the ticket page shows the table, every cell HTML-escaped');
$rendered = S::renderInputs($gotFields);
$ok(strpos($rendered, 'data-catalog-table="monitors"') !== false && strpos($rendered, 'name="catalog_field[monitors][0][1]"') !== false && strpos($rendered, 'type="number"') !== false && strpos($rendered, 'js/catalog_table.js') !== false, 'the form renders a table with indexed cell names, typed inputs and the add-row script');
$ok(strpos(S::renderInputs($gotFields, true), 'name="catalog_field') === false, 'the editor preview posts nothing');
$d = $svc->describeItem($ti);
$ok($d['fields'][0]['type'] === 'table' && $d['fields'][0]['columns'][1] === ['label' => 'Qty', 'type' => 'number'], 'describeItem (API/chat) lists table columns');

// ---------------------------------------------------------------- computed risk
$ri = $mkItem(['name' => 'Risky form', 'requires_approval' => 1, 'risk_score' => 30]); $rid0 = intval($ri['catalog_item_id']);
$svc->saveFields($rid0, $fieldRows([['Device', 'select', "Laptop\nServer"], ['Admin rights', 'checkbox'], ['Seats', 'number'], ['Why', 'text']]));
$svc->saveRiskRules($rid0, S::normalizeRiskRuleRows(['risk_field' => ['device', 'admin_rights', 'seats', 'why', 'device', 'seats'], 'risk_op' => ['equals', 'equals', 'gte', 'not_empty', 'in', 'lte'], 'risk_value' => ['Server', 'Yes', '10', '', 'Laptop|Phone', 'abc'], 'risk_points' => [40, 25, 15, 5, -10, 5]]));
$rules = $svc->getRiskRules($rid0);
$ok(count($rules) === 5, 'risk rules saved (the lte rule with a non-number threshold is dropped)');
$vals = fn(array $a) => S::validateInput($svc->getFields($rid0), $a)['values'];
$c = $svc->computeRisk($ri, $vals([]));
$ok($c['score'] === 30 && $c['matched'] === [], 'no answers: the base score');
$c = $svc->computeRisk($ri, $vals(['device' => 'Server']));
$ok($c['score'] === 70 && count($c['matched']) === 1, 'an equals rule adds its points');
$c = $svc->computeRisk($ri, $vals(['device' => 'Server', 'admin_rights' => '1', 'seats' => '12', 'why' => 'x']));
$ok($c['score'] === 100 && $c['base'] === 30, 'points add up and the score is clamped to 100 (30+40+25+15+5=115)');
$c = $svc->computeRisk($ri, $vals(['device' => 'Laptop']));
$ok($c['score'] === 20, 'a negative rule lowers the score (30-10)');
$c = $svc->computeRisk($ri, $vals(['seats' => '9']));
$ok($c['score'] === 30, 'gte 10 does not match 9');
$c = $svc->computeRisk(array_merge($ri, ['risk_score' => 5]), $vals(['device' => 'Laptop']));
$ok($c['score'] === 0, 'the score never goes below 0');
$c = $svc->computeRisk($ri, $vals(['admin_rights' => '0']));
$ok($c['score'] === 30, 'an unticked checkbox does not match "Yes"');
$ok(S::normalizeRiskRuleRows(['risk_field' => ['x'], 'risk_op' => ['equals'], 'risk_value' => [''], 'risk_points' => [10]]) === [] && S::normalizeRiskRuleRows(['risk_field' => ['x'], 'risk_op' => ['bogus'], 'risk_value' => ['a'], 'risk_points' => [10]]) === [] && S::normalizeRiskRuleRows(['risk_field' => ['x'], 'risk_op' => ['not_empty'], 'risk_points' => [0]]) === [], 'rules with no value, an unknown operator or zero points are dropped');
$ok(S::normalizeRiskRuleRows(['risk_field' => ['x'], 'risk_op' => ['not_empty'], 'risk_points' => [500]])[0]['points'] === 100, 'points are clamped to +-100');

// ---------------------------------------------------------------- risk-based routing
$steps($rid0, [['user', 10, 'any', false, 0, 100], ['user', 11, 'any', false, 60, 100]]); // everyone: user 10; high risk adds user 11
$mk2 = fn() => $svc->getSteps($rid0);
$ok(count($svc->getSteps($rid0, 20)) === 1 && count($svc->getSteps($rid0, 60)) === 2 && count($svc->getSteps($rid0, 59)) === 1, 'getSteps filters by the risk band (inclusive)');
[$t1, $r1, $s1] = $start($ri, ['device' => 'Laptop']); // risk 20
$ok($s1 === 'pending_approval' && $pendingUsers($r1) === [10], 'low-risk request: only the first chain (user 10)');
$ok(intval($one("SELECT risk_score FROM service_catalog_requests WHERE request_id=$r1")) === 20, 'the computed score is stored on the request');
$d = $svc->decide($r1, 10, null, true);
$ok($d['status'] === 'approved' && $ticketStatus($t1) === 1, 'low-risk request is approved after step 1 (the high-risk step is skipped) and the ticket released');
[$t2, $r2, $s2] = $start($ri, ['device' => 'Server']); // risk 70
$ok($pendingUsers($r2) === [10], 'high-risk request starts at step 1');
$d = $svc->decide($r2, 10, null, true);
$ok($d['status'] === 'pending_approval' && $pendingUsers($r2) === [11] && intval($one("SELECT current_step FROM service_catalog_requests WHERE request_id=$r2")) === 2, 'high-risk request continues to the extra security step');
$ok($svc->decide($r2, 11, null, true)['status'] === 'approved', 'and is approved once that step approves');
$bd = json_decode($one("SELECT risk_breakdown FROM service_catalog_requests WHERE request_id=$r2"), true);
$ok($bd['base'] === 30 && $bd['matched'][0]['points'] === 40, 'the risk breakdown (base + matched rules) is stored for audit');
$ok(strpos($svc->requestDetailsHtml($t2), 'Risk score <strong>70</strong>') !== false, 'the ticket page shows the score and its breakdown');
// auto-approve threshold uses the COMPUTED score
$svc->saveRiskRules($rid0, S::normalizeRiskRuleRows(['risk_field' => ['device'], 'risk_op' => ['equals'], 'risk_value' => ['Server'], 'risk_points' => [60]]));
$q("UPDATE service_catalog_items SET risk_score=10, auto_approve_below=40 WHERE catalog_item_id=$rid0"); $ri = mysqli_fetch_assoc($q("SELECT * FROM service_catalog_items WHERE catalog_item_id=$rid0"));
[$t3, $r3, $s3] = $start($ri, ['device' => 'Laptop']);
$ok($s3 === 'approved' && $ticketStatus($t3) === 1, 'computed score 10 < 40: auto-approved, ticket not held');
[$t4, $r4, $s4] = $start($ri, ['device' => 'Server']);
$ok($s4 === 'pending_approval' && $ticketStatus($t4) === 3, 'computed score 70 >= 40: held for approval even though the base score alone would auto-approve');
// a risk level with no configured chain is recorded approved
$q("UPDATE service_catalog_items SET auto_approve_below=0 WHERE catalog_item_id=$rid0"); $ri = mysqli_fetch_assoc($q("SELECT * FROM service_catalog_items WHERE catalog_item_id=$rid0"));
$steps($rid0, [['user', 11, 'any', false, 50, 100]]);
[$t5, $r5, $s5] = $start($ri, ['device' => 'Laptop']);
$ok($s5 === 'approved' && intval($one("SELECT COUNT(*) FROM service_catalog_request_approvals WHERE request_id=$r5")) === 0, 'a risk level no step applies to is recorded approved without asking anyone');

// ---------------------------------------------------------------- parallel groups
$st = S::normalizeStepRows(['step_type' => ['user', 'role', 'requester_manager', 'user'], 'step_ref_user' => [10, 0, 0, 11], 'step_ref_role' => [1 => 2], 'step_order' => [1, 2, 3, 4], 'step_mode' => ['any', 'all', 'any', 'any'], 'step_parallel' => [1 => 1, 2 => 1], 'step_risk_min' => [3 => 90], 'step_risk_max' => [3 => 20]]);
$ok($st[0]['step_order'] === 1 && $st[1]['step_order'] === 1 && $st[2]['step_order'] === 1 && $st[3]['step_order'] === 2, 'rows ticked "parallel" share the previous row\'s stage; an unticked row starts the next');
$ok($st[3]['risk_min'] === 20 && $st[3]['risk_max'] === 90, 'a reversed risk band is put right');
$ok(S::normalizeStepRows(['step_type' => ['user'], 'step_ref_user' => [10], 'step_parallel' => [1]])[0]['step_order'] === 1, 'the first row cannot be parallel to nothing');
$noRisk = S::normalizeStepRows(['step_type' => ['user', 'user'], 'step_ref_user' => [10, 11], 'step_order' => [1, 2]]);
$ok($noRisk[0]['step_order'] === 1 && $noRisk[1]['step_order'] === 2 && $noRisk[0]['risk_min'] === 0 && $noRisk[0]['risk_max'] === 100, 'without the new fields steps behave as before: sequential, full risk band');

$pi = $mkItem(['name' => 'Parallel', 'requires_approval' => 1, 'risk_score' => 50]); $pid = intval($pi['catalog_item_id']);
$steps($pid, [['user', 10, 'any'], ['user', 11, 'any', true], ['user', 13, 'any']]); // stage 1 = {10, 11} both required; stage 2 = {13}
[$tp, $rp] = $start($pi, []);
$ok($pendingUsers($rp) === [10, 11], 'a parallel stage asks all of its branches at once');
$ok(intval($one("SELECT COUNT(DISTINCT step_id) FROM service_catalog_request_approvals WHERE request_id=$rp")) === 2, 'each branch is its own step_id');
$d = $svc->decide($rp, 10, null, true);
$ok($d['status'] === 'pending_approval' && intval($one("SELECT current_step FROM service_catalog_requests WHERE request_id=$rp")) === 1 && $pendingUsers($rp) === [11], 'one branch approving does NOT finish a parallel stage');
$d = $svc->decide($rp, 11, null, true);
$ok($d['status'] === 'pending_approval' && intval($one("SELECT current_step FROM service_catalog_requests WHERE request_id=$rp")) === 2 && $pendingUsers($rp) === [13], 'when every branch approved the next stage starts');
$ok($svc->decide($rp, 13, null, true)['status'] === 'approved', 'and the request is approved at the end');
[$tp2, $rp2] = $start($pi, []);
$d = $svc->decide($rp2, 11, null, false, 'no');
$ok($d['status'] === 'rejected' && $pendingUsers($rp2) === [] && $ticketStatus($tp2) === 5, 'one rejection in a parallel stage rejects the request, skips the other branch and closes the ticket');
// role branch (all) in parallel with a person branch (any)
$pr = $mkItem(['name' => 'Parallel role', 'requires_approval' => 1, 'risk_score' => 50]); $prid = intval($pr['catalog_item_id']);
$steps($prid, [['role', 2, 'all'], ['user', 13, 'any', true]]); // role 2 = users 10, 11 (12 inactive); everyone of them AND user 13
[$tq, $rq] = $start($pr, []);
$ok($pendingUsers($rq) === [10, 11, 13], 'role branch resolves to its active members; the person branch alongside');
$svc->decide($rq, 13, null, true); $svc->decide($rq, 10, null, true);
$ok($reqStatus($rq) === 'pending_approval', 'the "all" role branch still waits for its last member');
$ok($svc->decide($rq, 11, null, true)['status'] === 'approved', 'every branch satisfied: approved');
// the same person in two branches decides once for both
$pd = $mkItem(['name' => 'Same person twice', 'requires_approval' => 1, 'risk_score' => 50]); $pdid = intval($pd['catalog_item_id']);
$steps($pdid, [['user', 10, 'any'], ['role', 2, 'any', true]]);
[$td, $rd] = $start($pd, []);
$ok(count($pendingUsers($rd)) === 3, 'user 10 appears in both branches (3 pending rows)');
$d = $svc->decide($rd, 10, null, true);
$ok($d['status'] === 'approved', 'one decision by that person settles both of their rows, so the stage completes');
// admin override decides the whole parallel stage
[$to, $ro] = $start($pi, []);
$d = $svc->decide($ro, 1, null, true, 'urgent', true);
$ok($d['ok'] && intval($one("SELECT current_step FROM service_catalog_requests WHERE request_id=$ro")) === 2, 'an administrator override settles every branch of a parallel stage');
$ok($svc->decide($ro, 10, null, true)['ok'] === false, 'and the skipped branch members cannot decide afterwards');

// ---------------------------------------------------------------- delegation
$future = date('Y-m-d H:i:s', time() + 86400 * 3); $past = date('Y-m-d H:i:s', time() - 86400 * 3); $now = date('Y-m-d H:i:s', time() - 3600);
$ok($svc->addDelegation(10, 10, $now, $future)['ok'] === false, 'nobody can delegate to themself');
$ok($svc->addDelegation(10, 12, $now, $future)['ok'] === false, 'the delegate must be an active agent');
$ok($svc->addDelegation(10, 11, $future, $now)['ok'] === false, 'the end must be after the start');
$ok($svc->addDelegation(10, 11, $now, date('Y-m-d H:i:s', time() + 86400 * 400))['ok'] === false, 'at most one year');
$ok($svc->addDelegation(10, 11, 'garbage', $future)['ok'] === false, 'dates are validated');
$old = $svc->addDelegation(10, 13, $past, date('Y-m-d H:i:s', time() - 86400)); // already ended
$ok($old['ok'] && $svc->activeDelegate(10) === null, 'an ended delegation is not active');
$dl = $svc->addDelegation(10, 11, $now, $future, 'on leave');
$ok($dl['ok'] && $svc->activeDelegate(10) === 11 && $svc->activeDelegate(11) === null, 'an active delegation covers the person');
$ok(count($svc->listDelegations(10)) === 1, 'listDelegations hides ended ones');
$de = $mkItem(['name' => 'Delegated', 'requires_approval' => 1, 'risk_score' => 50]); $deid = intval($de['catalog_item_id']);
$steps($deid, [['user', 10, 'any']]);
$GLOBALS['sent_notify'] = []; $GLOBALS['sent_mail'] = [];
[$tde, $rde] = $start($de, []);
$ok($pendingUsers($rde) === [11], 'a new request goes to the delegate instead of the person who is out');
$ok(intval($one("SELECT delegated_from_user_id FROM service_catalog_request_approvals WHERE request_id=$rde")) === 10, 'the row records who it covers for');
$ok($svc->decide($rde, 10, null, true)['ok'] === false, 'the absent person cannot decide while delegated (the delegate does)');
$ok(strpos($svc->requestDetailsHtml($tde), 'covering for a colleague') !== false, 'the trail says the delegate is covering');
$ok($svc->decide($rde, 11, null, true)['status'] === 'approved', 'the delegate can approve');
$ok(count(array_filter($GLOBALS['sent_notify'], fn($n) => $n[0] === 11)) >= 1 && count(array_filter($GLOBALS['sent_notify'], fn($n) => $n[0] === 10)) === 0, 'the delegate is notified (mock); the absent person is not');
// requester who is the delegate: the delegation is ignored for their own request
[$tdr, $rdr] = $start($de, [], 1, 100, 11);
$ok($pendingUsers($rdr) === [10], 'when the delegate raised the request, it is not routed to them (nobody approves their own request)');
// pending rows are re-routed when a delegation starts later
$svc->deleteDelegation($dl['id'], 10);
$ok($svc->activeDelegate(10) === null && $svc->deleteDelegation($dl['id'], 10) === false, 'deleting a delegation works once, only for the owner');
[$tlate, $rlate] = $start($de, []);
$ok($pendingUsers($rlate) === [10], 'before the delegation, the request is with user 10');
$svc->addDelegation(10, 11, $now, $future);
$ok($svc->rerouteDelegated() >= 1 && $pendingUsers($rlate) === [11], 'a delegation that starts later moves pending rows to the delegate');
$ok($svc->rerouteDelegated() === 0, 'and re-routing is idempotent');
$ok(intval($one("SELECT COUNT(*) FROM service_catalog_request_approvals WHERE request_id=$rlate AND status='skipped' AND approver_user_id=10")) === 1, 'the original row is kept as skipped (audit trail)');
$q("DELETE FROM service_catalog_delegations");

// ---------------------------------------------------------------- reminders, escalation, timeouts (mocked mail / notifications)
$ok(S::timeoutsEnabled($mysqli) === false, 'the worker flag is off by default');
$q("UPDATE settings SET config_catalog_approval_cron=1"); $ok(S::timeoutsEnabled($mysqli) === true, 'and reads on when switched on');
$ti2 = $mkItem(['name' => 'Timed', 'requires_approval' => 1, 'risk_score' => 50]); $tiid = intval($ti2['catalog_item_id']);
$steps($tiid, [['user', 10, 'any'], ['user', 13, 'any']]);
$setHours = fn(int $rem, int $esc, int $exp) => $q("UPDATE service_catalog_items SET reminder_after_hours=$rem, escalate_after_hours=$esc, expire_after_hours=$exp WHERE catalog_item_id=$tiid");
$age = fn(int $rid, int $hours) => $q("UPDATE service_catalog_request_approvals SET created_at = NOW() - INTERVAL $hours HOUR WHERE request_id=$rid AND is_escalation=0");
$sentTo = fn(string $needle) => count(array_filter($GLOBALS['sent_mail'], fn($m) => strpos($m['subject'], $needle) !== false));
$setHours(0, 0, 0);
[$tt, $rt] = $start($ti2, []); $age($rt, 100);
$res = $svc->processTimeouts();
$ok($res === ['rerouted' => 0, 'expired' => 0, 'escalated' => 0, 'reminded' => 0], 'items with no hours set are never touched, however old the request');
$setHours(4, 0, 0);
$GLOBALS['sent_mail'] = []; $GLOBALS['sent_notify'] = [];
$res = $svc->processTimeouts();
$ok($res['reminded'] === 1 && $sentTo('Reminder: approval still needed') === 1 && $GLOBALS['sent_notify'][0][0] === 10, 'a request waiting longer than the reminder time reminds the approver (mock mail + in-app)');
$res = $svc->processTimeouts();
$ok($res['reminded'] === 0, 'a second pass right away does not remind again (claimed first)');
$q("UPDATE service_catalog_request_approvals SET last_reminded_at = NOW() - INTERVAL 5 HOUR WHERE request_id=$rt");
$ok($svc->processTimeouts()['reminded'] === 1 && intval($one("SELECT reminder_count FROM service_catalog_request_approvals WHERE request_id=$rt")) === 2, 'it reminds again after another interval');
$q("UPDATE service_catalog_request_approvals SET last_reminded_at = NOW() - INTERVAL 5 HOUR, reminder_count = 5 WHERE request_id=$rt");
$ok($svc->processTimeouts()['reminded'] === 0, 'reminders stop after the maximum');
// young request: not due
[$ty, $ry] = $start($ti2, []); $GLOBALS['sent_mail'] = [];
$svc->processTimeouts(); $ok($sentTo('Reminder') === 0, 'a request younger than the reminder time is left alone');
// escalation
$setHours(0, 8, 0); $q("UPDATE service_catalog_request_approvals SET reminder_count=0, last_reminded_at=NULL");
$res = $svc->processTimeouts();
$ok($res['escalated'] === 1 && intval($one("SELECT COUNT(*) FROM service_catalog_request_approvals WHERE request_id=$rt AND is_escalation=1 AND status='pending'")) === 1, 'after the escalation time the administrators are added as escalation approvers (once)');
$ok($svc->processTimeouts()['escalated'] === 0, 'escalation happens only once per stage');
$ok(strpos($one("SELECT ticket_reply FROM ticket_replies WHERE ticket_reply_ticket_id=$tt ORDER BY ticket_reply_id DESC LIMIT 1"), 'escalated') !== false, 'the ticket gets a System note');
$ok($pendingUsers($rt) === [10, 1], 'the original approver stays, administrator 1 is added (archived admin 2 is not)');
$d = $svc->decide($rt, 1, null, true, 'escalation ok');
$ok($d['ok'] && intval($one("SELECT current_step FROM service_catalog_requests WHERE request_id=$rt")) === 2, 'an escalation approver decides the whole stage (here: moves on to stage 2)');
$ok($pendingUsers($rt) === [13], 'the original approver was skipped and the next stage started');
$ok(strpos($svc->requestDetailsHtml($tt), 'Escalated') !== false, 'the trail marks the escalation');
// expiry
$setHours(0, 0, 24); [$te, $re] = $start($ti2, []); $age($re, 30);
$res = $svc->processTimeouts();
$ok($res['expired'] >= 1 && $reqStatus($re) === 'rejected' && $ticketStatus($te) === 5, 'a request undecided past the timeout is rejected and its ticket closed');
$ok(strpos((string) $one("SELECT rejection_reason FROM service_catalog_requests WHERE request_id=$re"), 'timed out') !== false && $pendingUsers($re) === [], 'with a clear reason, and nothing is left pending');
$ok($svc->decide($re, 10, null, true)['ok'] === false, 'a late approval of an expired request is refused');
[$te2, $re2] = $start($ti2, []);
$ok($svc->processTimeouts()['expired'] === 0 && $reqStatus($re2) === 'pending_approval', 'a fresh request is not expired');
// reminders in the second stage are timed from that stage's start
$setHours(2, 0, 0);
[$ts, $rs] = $start($ti2, []); $age($rs, 3); $GLOBALS['sent_mail'] = [];
$svc->decide($rs, 10, null, true); $svc->processTimeouts();
$ok($sentTo('Reminder') === 0 || intval($one("SELECT COUNT(*) FROM service_catalog_request_approvals WHERE request_id=$rs AND step_order=2 AND reminder_count>0")) === 0, 'stage 2 starts its own clock (no instant reminder after stage 1 was approved)');
$setHours(0, 0, 0);

// ---------------------------------------------------------------- categories and tiles
$c1 = $svc->saveCategory(0, 'Hardware', 'Laptops etc', 'fa-laptop', 2, true);
$c2 = $svc->saveCategory(0, 'Access', '', 'fa-key<script>', 1, true);
$c3 = $svc->saveCategory(0, 'Hidden', '', '', 3, false);
$ok($c1 > 0 && $c2 > 0 && count($svc->getCategories(true)) === 2 && count($svc->getCategories(false)) === 3, 'categories saved; inactive ones are hidden from the active list');
$ok($one("SELECT icon FROM service_catalog_categories WHERE category_id=$c2") === 'fa-keyscript', 'category icons are reduced to a safe class name');
$a = $mkItem(['name' => 'Laptop']); $b = $mkItem(['name' => 'Mailbox']); $cc = $mkItem(['name' => 'Odd one']); $dd = $mkItem(['name' => 'In hidden category']);
$q("UPDATE service_catalog_items SET catalog_category_id=$c1 WHERE catalog_item_id={$a['catalog_item_id']}"); $q("UPDATE service_catalog_items SET catalog_category_id=$c2 WHERE catalog_item_id={$b['catalog_item_id']}"); $q("UPDATE service_catalog_items SET catalog_category_id=$c3 WHERE catalog_item_id={$dd['catalog_item_id']}");
$items = array_values(array_filter($svc->activeItems(), fn($i) => in_array($i['name'], ['Laptop', 'Mailbox', 'Odd one', 'In hidden category'], true)));
$g = $svc->groupByCategory($items);
$ok(array_column($g, 'name') === ['Access', 'Hardware', 'Other'], 'tiles: active categories in their order, then Other');
$ok(array_column($g[2]['items'], 'name') === ['In hidden category', 'Odd one'], 'uncategorised items and items in an inactive category fall into Other');
$ok($svc->groupByCategory([]) === [], 'no items, no tiles');
$g1 = $svc->groupByCategory([$cc]); $ok($g1[0]['name'] === 'All requests' && $g[2]['name'] === 'Other', 'with nothing categorised the lone group is called All requests; next to real categories it is Other');
$svc->deleteCategory($c1);
$ok($one("SELECT catalog_category_id FROM service_catalog_items WHERE catalog_item_id={$a['catalog_item_id']}") === null && count($svc->getCategories(false)) === 2, 'deleting a category keeps its items (uncategorised)');
$ok($svc->describeItem(mysqli_fetch_assoc($q("SELECT * FROM service_catalog_items WHERE catalog_item_id={$b['catalog_item_id']}")))['category_id'] === $c2, 'describeItem carries the category id');

// ---------------------------------------------------------------- statistics
$q("DELETE FROM service_catalog_request_approvals"); $q("DELETE FROM service_catalog_requests"); $q("DELETE FROM tickets");
$si = $mkItem(['name' => 'Stat item', 'requires_approval' => 1, 'risk_score' => 50]); $sid = intval($si['catalog_item_id']); $steps($sid, [['user', 10, 'any']]);
$q("UPDATE service_catalog_items SET catalog_category_id=$c2 WHERE catalog_item_id=$sid"); $si = mysqli_fetch_assoc($q("SELECT * FROM service_catalog_items WHERE catalog_item_id=$sid"));
$ids = [];
for ($i = 0; $i < 4; $i++) { $ids[] = $start($si, [])[1]; }
$svc->decide($ids[0], 10, null, true); $svc->decide($ids[1], 10, null, false, 'no'); $svc->decide($ids[2], 10, null, true);
$q("UPDATE service_catalog_requests SET created_at = NOW() - INTERVAL 10 HOUR, decided_at = NOW() - INTERVAL 6 HOUR WHERE request_id={$ids[0]}"); // 4h
$q("UPDATE service_catalog_requests SET created_at = NOW() - INTERVAL 10 HOUR, decided_at = NOW() - INTERVAL 2 HOUR WHERE request_id={$ids[1]}"); // 8h
$q("UPDATE service_catalog_requests SET created_at = NOW() - INTERVAL 10 HOUR, decided_at = NOW() - INTERVAL 4 HOUR WHERE request_id={$ids[2]}"); // 6h
$auto = $mkItem(['name' => 'Auto item', 'requires_approval' => 1, 'risk_score' => 5, 'auto_approve_below' => 50]); $steps(intval($auto['catalog_item_id']), [['user', 10, 'any']]); $start($auto, []);
$stats = $svc->requestStats(date('Y-m-d', time() - 86400), date('Y-m-d', time() + 86400));
$ok($stats['totals']['requests'] === 5 && $stats['totals']['approved'] === 3 && $stats['totals']['rejected'] === 1 && $stats['totals']['pending'] === 1, 'stats: volume and outcomes (4 + 1 auto)');
$ok($stats['totals']['auto_approved'] === 1, 'stats: auto-approved requests counted separately');
$ok($stats['totals']['rejection_rate'] === 25.0, 'stats: rejection rate = rejected / decided (1 of 4)');
$row = array_values(array_filter($stats['items'], fn($r) => $r['name'] === 'Stat item'))[0];
$ok($row['requests'] === 4 && $row['avg_hours'] === 6.0 && $row['max_hours'] === 8.0, 'stats: approval turnaround avg 6h and max 8h per item, auto-approvals excluded');
$ok($stats['by_category'][0]['category_name'] === 'Access' && $stats['by_category'][0]['requests'] === 4, 'stats: grouped by category');
$ok(array_sum(array_column($stats['trend'], 'requests')) === 5, 'stats: daily trend');
$none = $svc->requestStats('2001-01-01', '2001-01-31');
$ok($none['totals']['requests'] === 0 && $none['totals']['rejection_rate'] === null && $none['totals']['avg_hours'] === null && $none['items'] === [], 'stats: an empty window has no division by zero');
$scoped = $svc->requestStats(date('Y-m-d', time() - 86400), date('Y-m-d', time() + 86400), ' AND r.client_id = 2');
$ok($scoped['totals']['requests'] === 0, 'stats: the department scope fragment narrows the rows');
$ok($svc->requestStats("2026-01-01' OR 1=1 -- ", '2099-12-31') === $svc->requestStats('1970-01-01', '2099-12-31'), 'stats: a date argument that is not Y-m-d never reaches the query (falls back to the open window)');

// ---------------------------------------------------------------- createRequest guards that need no app shell
$res = $svc->createRequest(999999, [], 0, 0, 1, 'Chat');
$ok($res['ok'] === false && $res['code'] === 404, 'createRequest: unknown item is a 404');
$res = $svc->createRequest(intval($ti['catalog_item_id']), ['monitors' => 'x'], 0, 0, 1, 'Chat');
$ok($res['ok'] === false && $res['code'] === 422 && $res['errors'] !== [], 'createRequest: invalid answers are a 422 with the list of errors, no ticket');
$ok(intval($one("SELECT COUNT(*) FROM tickets WHERE ticket_catalog_item_id=" . intval($ti['catalog_item_id']))) === 0, 'createRequest: nothing was created');
$q("UPDATE service_catalog_items SET is_active=0 WHERE catalog_item_id=" . intval($ti['catalog_item_id']));
$ok($svc->createRequest(intval($ti['catalog_item_id']), [], 0, 0, 1, 'Chat')['code'] === 404, 'createRequest: an inactive item is not requestable');
$res = $svc->createRequest(intval($pi['catalog_item_id']), [], 99999, 0, 1, 'Chat');
$ok($res['code'] === 422, 'createRequest: a department that does not exist is a 422');

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
