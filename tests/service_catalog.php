<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (pentest F-02)
/*
 * Service catalog request forms and approval chains, against the REAL src/ITSM/ServiceCatalogService.php on a scratch database:
 *   - request-form validation (required, types, select choices, dates, numbers, checkbox, array injection), row normalisation
 *   - approval state machine: any/all steps, ordered chain, reject, auto-approve below the risk threshold, replay, IDOR
 *   - approver resolution: user, role, requester manager (same department only), administrator fallback, admin override
 *   - hold / release / close of the ticket by status NAME (SLA clock paused while held), details block escaping
 *   - trending (30 days, top 5, active items) and recently used (this contact + department only)
 * Needs a THROWAWAY database that holds this app's schema (import db.sql); it never reads config.php:
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=...scratch... RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/service_catalog.php
 */
if (getenv('RIVETIT_TEST_DB') !== '1') exit(2);
if (!preg_match('/scratch|test/i', (string) getenv('RIVETIT_TEST_DB_NAME'))) { fwrite(STDERR, "Refusing: DB name must contain scratch/test\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETIT_TEST_DB_USER'), getenv('RIVETIT_TEST_DB_PASS'), getenv('RIVETIT_TEST_DB_NAME'));
if ($mysqli->connect_errno) { fwrite(STDERR, "connect failed\n"); exit(2); }
date_default_timezone_set('UTC');
$q = fn(string $sql) => mysqli_query($mysqli, $sql);
$one = fn(string $sql) => mysqli_fetch_row($q($sql))[0] ?? null;
$q("SET SESSION sql_mode=''");
if (mysqli_num_rows($q("SHOW COLUMNS FROM ticket_statuses LIKE 'ticket_status_pauses_sla'")) === 0) { $q("ALTER TABLE ticket_statuses ADD COLUMN ticket_status_pauses_sla tinyint(1) NOT NULL DEFAULT 0"); }
if (mysqli_num_rows($q("SHOW COLUMNS FROM tickets LIKE 'ticket_resolution_started_at'")) === 0) { $q("ALTER TABLE tickets ADD COLUMN ticket_resolution_started_at datetime DEFAULT NULL"); }
if (mysqli_num_rows($q("SHOW COLUMNS FROM settings LIKE 'config_ticket_default_status_id'")) === 0) { $q("ALTER TABLE settings ADD COLUMN config_ticket_default_status_id int(11) DEFAULT 0"); }

require __DIR__ . '/../src/ITSM/ServiceCatalogService.php';
use ITFlow\ITSM\ServiceCatalogService as S;
$svc = new S($mysqli);
$fails = 0; $ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };

foreach (['service_catalog_request_approvals', 'service_catalog_requests', 'service_catalog_approval_steps', 'service_catalog_fields', 'service_catalog_items', 'tickets', 'ticket_replies', 'ticket_statuses', 'contacts', 'users', 'user_roles', 'settings', 'clients'] as $t) { $q("DELETE FROM $t"); }
foreach ([[1, 'New', 0], [2, 'Open', 0], [3, 'On Hold', 1], [4, 'Resolved', 0], [5, 'Closed', 0], [6, 'Assigned', 0]] as [$id, $n, $f]) {
    $q("INSERT INTO ticket_statuses SET ticket_status_id=$id, ticket_status_name='$n', ticket_status_color='#000', ticket_status_pauses_sla=$f, ticket_status_active=1");
}
$q("INSERT INTO settings SET company_id=1, config_current_database_version='2.6.133'");
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
$setSteps = fn(int $item, array $steps) => $svc->saveSteps($item, S::normalizeStepRows(array_merge(['step_type' => [], 'step_order' => [], 'step_mode' => []], (function ($steps) { $o = ['step_type' => [], 'step_order' => [], 'step_mode' => [], 'step_ref_user' => [], 'step_ref_role' => []]; foreach ($steps as $i => [$type, $ref, $mode]) { $o['step_type'][$i] = $type; $o['step_order'][$i] = $i + 1; $o['step_mode'][$i] = $mode; $o['step_ref_user'][$i] = $type === 'user' ? $ref : 0; $o['step_ref_role'][$i] = $type === 'role' ? $ref : 0; } return $o; })($steps))));
$ticketStatus = fn(int $t) => intval($one("SELECT ticket_status FROM tickets WHERE ticket_id=$t"));
$reqStatus = fn(int $r) => $one("SELECT status FROM service_catalog_requests WHERE request_id=$r");

// ---------------------------------------------------------------- validation
$fields = [
    ['field_key' => 'name', 'label' => 'Name', 'field_type' => 'text', 'options' => null, 'is_required' => 1],
    ['field_key' => 'notes', 'label' => 'Notes', 'field_type' => 'textarea', 'options' => null, 'is_required' => 0],
    ['field_key' => 'size', 'label' => 'Size', 'field_type' => 'select', 'options' => "Small\nLarge", 'is_required' => 1],
    ['field_key' => 'agree', 'label' => 'Agree', 'field_type' => 'checkbox', 'options' => null, 'is_required' => 1],
    ['field_key' => 'start', 'label' => 'Start', 'field_type' => 'date', 'options' => null, 'is_required' => 0],
    ['field_key' => 'qty', 'label' => 'Qty', 'field_type' => 'number', 'options' => null, 'is_required' => 0],
];
$good = ['name' => ' Ada ', 'size' => 'Large', 'agree' => '1', 'start' => '2026-10-31', 'qty' => '2.5'];
$r = S::validateInput($fields, $good);
$ok($r['errors'] === [] && $r['values'][0]['value'] === 'Ada' && $r['values'][3]['value'] === 'Yes', 'valid input passes and is trimmed; checkbox becomes Yes');
$ok(count(S::validateInput($fields, [])['errors']) === 3, 'missing required text, select and checkbox are three errors');
$ok(S::validateInput($fields, ['size' => 'Huge'] + $good)['errors'] !== [], 'a select value outside the choices is rejected');
$ok(S::validateInput($fields, ['start' => '2026-02-31'] + $good)['errors'] !== [], 'an impossible date is rejected');
$ok(S::validateInput($fields, ['start' => '31/10/2026'] + $good)['errors'] !== [], 'a wrongly formatted date is rejected');
$ok(S::validateInput($fields, ['qty' => 'abc'] + $good)['errors'] !== [], 'a non-number is rejected');
$ok(S::validateInput($fields, ['name' => ['x']] + $good)['errors'] !== [], 'an array posted for a text field is rejected');
$ok(S::validateInput($fields, ['name' => str_repeat('a', 501)] + $good)['errors'] !== [], 'an over-long text value is rejected');
$ok(S::validateInput($fields, ['agree' => '0'] + $good)['errors'] !== [], 'an unticked required checkbox is rejected');
$ok(S::validateInput($fields, $good + ['extra' => 'x', 'qty2' => '1'])['values'] === $r['values'], 'unknown posted keys are ignored');
$ok(S::validateInput([], ['anything' => 'x']) === ['errors' => [], 'values' => []], 'an item with no fields validates trivially');

// ---------------------------------------------------------------- normalisation
$rows = S::normalizeFieldRows(['field_label' => ['B q', '', 'A q', 'B q', 'Gone'], 'field_type' => ['text', 'text', 'bogus', 'select', 'text'], 'field_order' => [2, 0, 1, 3, 4], 'field_key' => ['', '', '', '', ''], 'field_options' => ['', '', '', "x\n\nx\ny", ''], 'field_remove' => [4 => '1'], 'field_required' => [0 => '1']]);
$ok(count($rows) === 3 && $rows[0]['label'] === 'A q' && $rows[1]['label'] === 'B q' && $rows[2]['label'] === 'B q', 'field rows: blank and removed dropped, ordered by the order number');
$ok($rows[0]['field_type'] === 'text', 'an unknown field type falls back to text');
$ok(count(array_unique(array_column($rows, 'field_key'))) === 3, 'duplicate labels get distinct keys');
$ok($rows[2]['options'] === "x\ny" && $rows[1]['is_required'] === 1, 'select options are de-duplicated; required flag kept');
$st = S::normalizeStepRows(['step_type' => ['role', 'user', 'requester_manager', 'user', 'bogus'], 'step_ref_role' => [5], 'step_ref_user' => [1 => 7, 3 => 0], 'step_order' => [3, 1, 2, 4, 5], 'step_mode' => ['all', 'any', 'bogus', 'any', 'any']]);
$ok(count($st) === 3 && $st[0]['approver_type'] === 'user' && $st[1]['approver_type'] === 'requester_manager' && $st[2]['approver_type'] === 'role' && $st[2]['step_order'] === 3, 'step rows: empty user step and bogus type dropped, renumbered by order');
$ok($st[1]['mode'] === 'any' && $st[2]['mode'] === 'all', 'step mode validated');

// ---------------------------------------------------------------- inert by default
$plain = $mkItem(['name' => 'Plain']);
$ok(!$svc->needsApproval($plain) && !$svc->hasRequestFlow($plain), 'an item with no form and no approval has no request flow (behaves as before)');
$t = $mkTicket(1, 100, intval($plain['catalog_item_id']));
$ok($ticketStatus($t) === 1, 'a plain item leaves the ticket status alone');

// form only: stored, no approval
$formItem = $mkItem(['name' => 'FormOnly']);
$svc->saveFields(intval($formItem['catalog_item_id']), S::normalizeFieldRows(['field_label' => ['Why'], 'field_type' => ['text'], 'field_order' => [1]]));
$ok($svc->hasRequestFlow($formItem), 'an item with a form has a request flow');
$t = $mkTicket(1, 100, intval($formItem['catalog_item_id']));
$res = $svc->submit($formItem, $t, 1, 100, 0, S::validateInput($svc->getFields(intval($formItem['catalog_item_id'])), ['why' => 'Because'])['values']);
$ok($res['status'] === 'not_required' && $ticketStatus($t) === 1, 'form-only request: stored as not_required, ticket not held');
$ok(strpos($svc->requestDetailsHtml($t), 'Because') !== false, 'the details block shows the answer');

// ---------------------------------------------------------------- risk score
$risky = $mkItem(['name' => 'Risky', 'requires_approval' => 1, 'risk_score' => 20, 'auto_approve_below' => 30]);
$setSteps(intval($risky['catalog_item_id']), [['user', 10, 'any']]);
$ok(!$svc->needsApproval($risky), 'risk below the auto-approve threshold skips approval');
$t = $mkTicket(1, 100, intval($risky['catalog_item_id']));
$res = $svc->submit($risky, $t, 1, 100, 0, []);
$ok($res['status'] === 'approved' && $ticketStatus($t) === 1 && $one("SELECT COUNT(*) FROM service_catalog_request_approvals WHERE request_id={$res['request_id']}") == 0, 'auto-approved request is recorded approved, never held, no approvers asked');
$edge = array_merge($risky, ['risk_score' => 30]);
$ok($svc->needsApproval($edge), 'risk equal to the threshold still needs approval');
$ok($svc->needsApproval(array_merge($risky, ['auto_approve_below' => 0, 'risk_score' => 0])), 'threshold 0 never auto-approves');
$noSteps = $mkItem(['name' => 'NoSteps', 'requires_approval' => 1]);
$ok(!$svc->needsApproval($noSteps), 'requires_approval with no steps cannot hold a ticket forever');

// ---------------------------------------------------------------- two-step chain: user(any) then role(all)
$chain = $mkItem(['name' => 'Chain', 'requires_approval' => 1, 'risk_score' => 80]);
$cid = intval($chain['catalog_item_id']);
$setSteps($cid, [['user', 13, 'any'], ['role', 2, 'all']]);
$t = $mkTicket(1, 100, $cid);
$res = $svc->submit($chain, $t, 1, 100, 0, []);
$rid = $res['request_id'];
$ok($res['status'] === 'pending_approval' && $ticketStatus($t) === 3, 'approval request holds the ticket in On Hold (resolved by name)');
$ok($one("SELECT ticket_sla_paused_at IS NOT NULL FROM tickets WHERE ticket_id=$t") == 1, 'holding the ticket pauses the SLA clock (On Hold is flagged)');
$ok(intval($one("SELECT current_step FROM service_catalog_requests WHERE request_id=$rid")) === 1, 'step 1 is current');
$ok($svc->decide($rid, 10, null, true)['ok'] === false, 'a user from step 2 cannot approve step 1 early');
$ok($svc->decide($rid, 99, null, true)['ok'] === false, 'a stranger cannot approve');
$ok($svc->decide($rid, null, 100, true, '', false, 1)['ok'] === false, 'a contact cannot approve a user-type step');
$ok($reqStatus($rid) === 'pending_approval', 'failed decisions changed nothing');
$d = $svc->decide($rid, 13, null, true, 'looks fine');
$ok($d['ok'] && $d['status'] === 'pending_approval' && intval($one("SELECT current_step FROM service_catalog_requests WHERE request_id=$rid")) === 2, 'step 1 approval advances to step 2');
$ok($svc->decide($rid, 13, null, true)['ok'] === false, 'a replayed approval of the finished step fails');
$ok(intval($one("SELECT COUNT(*) FROM service_catalog_request_approvals WHERE request_id=$rid AND step_order=2 AND status='pending'")) === 2, 'role step snapshots the two active role members only (inactive user excluded)');
$ok($ticketStatus($t) === 3, 'ticket still held after step 1');
$d = $svc->decide($rid, 10, null, true);
$ok($d['ok'] && $d['status'] === 'pending_approval' && $ticketStatus($t) === 3, '"all" step: one of two approvals does not complete it');
$ok($svc->decide($rid, 10, null, true)['ok'] === false, 'the same approver cannot approve twice');
$d = $svc->decide($rid, 11, null, true, 'ok');
$ok($d['ok'] && $d['status'] === 'approved' && $reqStatus($rid) === 'approved', 'last approval completes the chain');
$ok($ticketStatus($t) === 1, 'approved ticket is released to the New status');
$ok($one("SELECT ticket_sla_paused_at IS NULL FROM tickets WHERE ticket_id=$t") == 1, 'release resumes the SLA clock');
$ok($svc->decide($rid, 11, null, false, 'late')['ok'] === false && $reqStatus($rid) === 'approved', 'a decision after the end is refused');
$ok(intval($one("SELECT COUNT(*) FROM service_catalog_request_approvals WHERE request_id=$rid AND status='approved'")) === 3 && $one("SELECT comment FROM service_catalog_request_approvals WHERE request_id=$rid AND approver_user_id=13") === 'looks fine', 'every decision is recorded with approver and comment');

// released assigned ticket goes to Assigned
$t2 = $mkTicket(1, 100, $cid, 'NOW()', 10);
$r2 = $svc->submit($chain, $t2, 1, 100, 0, [])['request_id'];
$svc->decide($r2, 13, null, true); $svc->decide($r2, 10, null, true); $svc->decide($r2, 11, null, true);
$ok($ticketStatus($t2) === 6, 'an assigned ticket is released to Assigned');

// ---------------------------------------------------------------- reject
$t3 = $mkTicket(1, 100, $cid);
$r3 = $svc->submit($chain, $t3, 1, 100, 0, [])['request_id'];
$ok($svc->decide($r3, 13, null, false, 'Not budgeted')['status'] === 'rejected', 'a rejection ends the request');
$ok($ticketStatus($t3) === 5 && $one("SELECT ticket_closed_at IS NOT NULL FROM tickets WHERE ticket_id=$t3") == 1, 'rejected ticket is closed');
$ok($one("SELECT rejection_reason FROM service_catalog_requests WHERE request_id=$r3") === 'Not budgeted' && strpos((string) $one("SELECT ticket_reply FROM ticket_replies WHERE ticket_reply_ticket_id=$t3 AND ticket_reply LIKE 'Request rejected%'"), 'Not budgeted') !== false, 'the reason is stored and noted on the ticket');
$ok($svc->decide($r3, 10, null, true)['ok'] === false && $ticketStatus($t3) === 5, 'nothing can approve a rejected request');
$ok(intval($one("SELECT COUNT(*) FROM service_catalog_request_approvals WHERE request_id=$r3 AND status='pending'")) === 0, 'no approval rows are left pending after a rejection');

// "any" role step: one approval is enough
$anyItem = $mkItem(['name' => 'AnyRole', 'requires_approval' => 1]);
$setSteps(intval($anyItem['catalog_item_id']), [['role', 2, 'any']]);
$t4 = $mkTicket(); $r4 = $svc->submit($anyItem, $t4, 1, 100, 0, [])['request_id'];
$ok($svc->decide($r4, 11, null, true)['status'] === 'approved' && $ticketStatus($t4) === 1, '"any" role step completes on the first approval');
$ok(intval($one("SELECT COUNT(*) FROM service_catalog_request_approvals WHERE request_id=$r4 AND status='skipped'")) === 1, 'the other role member is marked skipped');

// ---------------------------------------------------------------- manager resolution
$mgr = $mkItem(['name' => 'Mgr', 'requires_approval' => 1]);
$setSteps(intval($mgr['catalog_item_id']), [['requester_manager', 0, 'any']]);
$ok($svc->managerOf(100, 1) === 101, 'manager resolves from contact_manager_id');
$ok($svc->managerOf(102, 1) === null, 'no manager on file resolves to null');
$ok($svc->managerOf(104, 1) === null, 'a manager in another department is ignored');
$q("UPDATE contacts SET contact_manager_id = 100 WHERE contact_id = 100");
$ok($svc->managerOf(100, 1) === null, 'a contact cannot be their own manager');
$q("UPDATE contacts SET contact_manager_id = 101 WHERE contact_id = 100");
$t5 = $mkTicket(1, 100); $r5 = $svc->submit($mgr, $t5, 1, 100, 0, [])['request_id'];
$ok(intval($one("SELECT COUNT(*) FROM service_catalog_request_approvals WHERE request_id=$r5 AND approver_contact_id=101 AND status='pending'")) === 1, 'the requester\'s manager is asked');
$ok($svc->decide($r5, null, 101, true, '', false, 2)['ok'] === false, 'the manager cannot decide through another department');
$ok($svc->decide($r5, null, 102, true, '', false, 1)['ok'] === false, 'another contact in the department cannot decide');
$ok($svc->decide($r5, null, 101, true)['ok'] === false, 'a contact decision with no department scope is refused');
$ok(count($svc->pendingApprovals(null, 101, 1)) === 1 && count($svc->pendingApprovals(null, 101, 2)) === 0 && count($svc->pendingApprovals(null, 102, 1)) === 0, 'the portal inbox lists only this contact\'s own department-scoped approvals');
$ok($svc->contactIsApprover(101, 1) && !$svc->contactIsApprover(102, 1) && !$svc->contactIsApprover(101, 2), 'contactIsApprover: managers only, department scoped');
$ok($svc->decide($r5, null, 101, true, 'ok', false, 1)['status'] === 'approved' && $ticketStatus($t5) === 1, 'the manager approves and the ticket is released');
// no manager: falls back to the administrators
$t6 = $mkTicket(1, 102); $r6 = $svc->submit($mgr, $t6, 1, 102, 0, [])['request_id'];
$ok(intval($one("SELECT COUNT(*) FROM service_catalog_request_approvals WHERE request_id=$r6 AND approver_user_id=1 AND status='pending'")) === 1 && intval($one("SELECT COUNT(*) FROM service_catalog_request_approvals WHERE request_id=$r6")) === 1, 'no manager: only the active administrator is asked (archived admin excluded)');
// requester cannot approve their own agent-raised request when someone else can
$self = $mkItem(['name' => 'Self', 'requires_approval' => 1]);
$setSteps(intval($self['catalog_item_id']), [['role', 2, 'any']]);
$t7 = $mkTicket(); $r7 = $svc->submit($self, $t7, 1, 100, 10, [])['request_id'];
$ok(intval($one("SELECT COUNT(*) FROM service_catalog_request_approvals WHERE request_id=$r7 AND approver_user_id=10")) === 0 && intval($one("SELECT COUNT(*) FROM service_catalog_request_approvals WHERE request_id=$r7 AND approver_user_id=11")) === 1, 'the requesting agent is not asked to approve their own request when others can');

// ---------------------------------------------------------------- admin override
$t8 = $mkTicket(1, 102); $r8 = $svc->submit($mgr, $t8, 1, 102, 0, [])['request_id'];
$t9 = $mkTicket(1, 100); $r9 = $svc->submit($mgr, $t9, 1, 100, 0, [])['request_id'];
$ok($svc->decide($r9, 13, null, true, '', true)['ok'] === true, 'override: an administrator decides the manager\'s step');
$ok($reqStatus($r9) === 'approved' && $one("SELECT comment FROM service_catalog_request_approvals WHERE request_id=$r9 AND status='approved'") === '[Administrator override]' && intval($one("SELECT approver_user_id FROM service_catalog_request_approvals WHERE request_id=$r9 AND status='approved'")) === 13, 'the override is recorded against the administrator and marked as such');
$ok($svc->decide($r8, 13, null, true)['ok'] === false, 'without the override flag an unrelated user still cannot decide');

// ---------------------------------------------------------------- held ticket status by name
$q("INSERT INTO ticket_statuses SET ticket_status_id=9, ticket_status_name='Pending Approval', ticket_status_color='#000', ticket_status_pauses_sla=1, ticket_status_active=1");
$ok($svc->holdStatusId() === 9, 'a status named Pending Approval is preferred when it exists');
$q("UPDATE ticket_statuses SET ticket_status_active=0 WHERE ticket_status_id=9");
$ok($svc->holdStatusId() === 3, 'otherwise On Hold');
$q("UPDATE settings SET config_ticket_default_status_id=2");
$ok($svc->releaseStatusId(0) === 2, 'the configured default creation status wins on release');
$q("UPDATE settings SET config_ticket_default_status_id=0");

// ---------------------------------------------------------------- details block escaping
$xss = $mkItem(['name' => 'Xss']);
$svc->saveFields(intval($xss['catalog_item_id']), S::normalizeFieldRows(['field_label' => ['<img src=x onerror=alert(1)>'], 'field_type' => ['text'], 'field_order' => [1]]));
$tx = $mkTicket(1, 100, intval($xss['catalog_item_id']));
$vals = S::validateInput($svc->getFields(intval($xss['catalog_item_id'])), ['img_src_x_onerror_alert_1' => '<script>alert(1)</script>']);
$svc->submit($xss, $tx, 1, 100, 0, $vals['values']);
$html = $svc->requestDetailsHtml($tx);
$ok($vals['errors'] === [] && strpos($html, '<script>') === false && strpos($html, '<img') === false && strpos($html, '&lt;script&gt;') !== false, 'labels and values are escaped in the details block');
$ok($svc->requestDetailsHtml(999999) === '', 'a ticket with no request renders nothing');
$ok(strpos(S::renderInputs($svc->getFields(intval($xss['catalog_item_id']))), '<img') === false, 'rendered form inputs escape the label');

// ---------------------------------------------------------------- trending / recent
$q("DELETE FROM tickets");
$mk = [];
for ($i = 1; $i <= 7; $i++) { $mk[$i] = intval($mkItem(['name' => "Trend $i"])['catalog_item_id']); }
$inactive = intval($mkItem(['name' => 'Off', 'is_active' => 0])['catalog_item_id']);
foreach ([1 => 7, 2 => 6, 3 => 5, 4 => 4, 5 => 3, 6 => 2, 7 => 1] as $item => $n) { for ($j = 0; $j < $n; $j++) { $mkTicket(1, 100 + ($j % 2), $mk[$item], 'NOW() - INTERVAL 2 DAY'); } }
for ($j = 0; $j < 20; $j++) { $mkTicket(1, 100, $mk[7], 'NOW() - INTERVAL 40 DAY'); }   // old: outside 30 days
for ($j = 0; $j < 20; $j++) { $mkTicket(1, 100, $inactive, 'NOW()'); }                    // inactive item
for ($j = 0; $j < 20; $j++) { $mkTicket(1, 100, null, 'NOW()'); }                         // no catalog item
$tr = $svc->trending(5, 30);
$ok(count($tr) === 5 && $tr[0]['catalog_item_id'] === $mk[1] && $tr[0]['uses'] === 7 && $tr[4]['catalog_item_id'] === $mk[5], 'trending: top 5 by tickets in the last 30 days');
$ok(!in_array($inactive, array_column($tr, 'catalog_item_id'), true) && array_sum(array_column($svc->trending(50, 30), 'uses')) === 28, 'trending ignores inactive items, old tickets and tickets without an item');
$ok($svc->trending(50, 365)[count($svc->trending(50, 365)) - 1]['uses'] >= 1 && array_column($svc->trending(50, 365), 'uses', 'catalog_item_id')[$mk[7]] === 21, 'trending window is a parameter');
// recent: contact 100 in dept 1 used items 1..; a contact in another dept must not see them
$q("DELETE FROM tickets");
$mkTicket(1, 100, $mk[1], 'NOW() - INTERVAL 10 DAY'); $mkTicket(1, 100, $mk[2], 'NOW() - INTERVAL 9 DAY'); $mkTicket(1, 100, $mk[1], 'NOW() - INTERVAL 1 DAY');
$mkTicket(1, 100, $mk[3], 'NOW() - INTERVAL 8 DAY'); $mkTicket(1, 100, $mk[4], 'NOW() - INTERVAL 7 DAY'); $mkTicket(1, 100, $mk[5], 'NOW() - INTERVAL 6 DAY'); $mkTicket(1, 100, $mk[6], 'NOW() - INTERVAL 5 DAY');
$mkTicket(1, 101, $mk[7], 'NOW()'); $mkTicket(2, 100, $mk[7], 'NOW()'); $mkTicket(1, 100, $inactive, 'NOW()');
$rc = $svc->recentForContact(100, 1, 5);
$ok(count($rc) === 5 && $rc[0]['catalog_item_id'] === $mk[1] && count(array_unique(array_column($rc, 'catalog_item_id'))) === 5, 'recent: last 5 DISTINCT items, newest first');
$ok(!in_array($mk[7], array_column($rc, 'catalog_item_id'), true) && !in_array($inactive, array_column($rc, 'catalog_item_id'), true) && !in_array($mk[2], array_column($rc, 'catalog_item_id'), true), 'recent: not other contacts, other departments or inactive items; oldest distinct item drops off');
$ok($svc->recentForContact(100, 2, 5)[0]['catalog_item_id'] === $mk[7] && count($svc->recentForContact(100, 2, 5)) === 1, 'recent is scoped to the department as well as the contact');

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
