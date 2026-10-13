<?php
/*
 * Saved ticket views: multi-value filters (assigned to, priority, board, category, on-site/remote, due, statuses) and the default view.
 *
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=rivet_scratch_x RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/ticket_views.php
 *
 * Same harness and rules as tests/endpoint_agent_lib.php: a SCRATCH database holding the fully migrated schema (2.6.166), config.php pointing at it,
 * real HTTP against `php -S` with forged sessions. Parts 1 needs no database at all: the validators and the SQL builder are pure functions.
 */
require __DIR__ . '/endpoint_agent_lib.php';
require_once "$root/agent/includes/ticket_view_filters.php";

// ---------------------------------------------------------------------------------------------------------------------
// 1. Pure: parsing, validation, SQL builder, canonical query, signature (no database)
// ---------------------------------------------------------------------------------------------------------------------
$sets = fn(array $g, int $me = 7) => ticketFilterSets($g, $me);

// old single values keep working
$s = $sets(['assigned' => 'me', 'priority' => 'High', 'board' => '5', 'category' => '9', 'onsite' => '1', 'overdue' => '1']);
$ok($s['assigned'] === [7] && $s['priority'] === ['High'] && $s['board'] === [5] && $s['category'] === [9] && $s['onsite'] === [1] && $s['overdue'] === true && $s['due_today'] === false, 'old single values (assigned=me, priority=High, board=5, category=9, onsite=1, overdue=1) still read');
$s = $sets(['assigned' => 'unassigned']);
$ok($s['assigned'] === [0], 'assigned=unassigned is the id 0');
$s = $sets(['assigned' => '0']);
$ok($s['assigned'] === [0], 'assigned=0 (the dashboard link) means unassigned');
$s = $sets(['assigned' => '12']);
$ok($s['assigned'] === [12], 'assigned=<id> single agent');
$ok($sets(['assigned_to' => '4'])['assigned'] === [4], 'assigned_to=<id> (dashboard "my tickets" link) is read as assigned');

// canonical list form
$s = $sets(['assigned' => ['me', '3', 'unassigned'], 'priority' => ['High', 'Medium'], 'board' => ['5', '6'], 'category' => ['1', '2', '3'], 'onsite' => ['1'], 'due' => ['overdue', 'due_today']]);
$ok($s['assigned'] === [7, 3, 0] && $s['priority'] === ['High', 'Medium'] && $s['board'] === [5, 6] && $s['category'] === [1, 2, 3] && $s['onsite'] === [1] && $s['overdue'] && $s['due_today'], 'list form: assigned[]=me&assigned[]=3&assigned[]=unassigned, priority[], board[], category[], due[]');
$ok($sets(['assigned' => ['me', '7', 7]])['assigned'] === [7], 'me and the viewer\'s own id are one entry');
$ok($sets(['priority' => 'medium,HIGH'])['priority'] === ['High', 'Medium'], 'comma list and mixed case: priorities come back canonical and in the fixed order');
$ok($sets(['onsite' => ['0', '1']])['onsite'] === [], 'on-site and remote both ticked is no filter');
$ok($sets(['onsite' => '0'])['onsite'] === [0], 'remote only');
$ok($sets(['assigned' => 'me'], 0)['assigned'] === [], '"me" with no signed-in user resolves to nothing, not to 0 (unassigned)');

// hostile values
$hostile = [
    'assigned' => ["1' OR '1'='1", ['x' => '2'], '3; DROP TABLE tickets', -4, '-5', '1e3', 1.5, true, null, '0x10', 'unassigned" --', ' 8 ', str_repeat('9', 40)],
    'priority' => ["High' OR 1=1 -- ", ['High'], 'Critical', 'high; DROP', 'Low'],
    'board' => ['1 OR 1=1', ['2'], '3', '3', '0', '-1', '4abc', str_repeat('7', 30)],
    'category' => ["5); DELETE FROM tickets; --", '6'],
    'onsite' => ['2', 'abc', ['1']],
];
$s = $sets($hostile);
$ok($s['assigned'] === [8], 'hostile assigned values: only the clean "8" survives (' . json_encode($s['assigned']) . ')');
$ok($s['priority'] === ['Low'], 'hostile priority values: only Low survives (' . json_encode($s['priority']) . ')');
$ok($s['board'] === [3], 'hostile board values: only 3 survives (' . json_encode($s['board']) . ')');
$ok($s['category'] === [6], 'hostile category values: only 6 survives');
$ok($s['onsite'] === [], 'hostile onsite values: nothing survives');
$many = range(1, 500);
$s = $sets(['assigned' => array_map('strval', $many), 'board' => $many, 'category' => implode(',', $many)]);
$ok(count($s['assigned']) === 50 && count($s['board']) === 50 && count($s['category']) === 50, 'huge lists are capped at 50 per group (' . count($s['assigned']) . '/' . count($s['board']) . '/' . count($s['category']) . ')');
$nested = $sets(['assigned' => [['a' => ['b' => 1]], [[['3']]]], 'priority' => [[['High']]]]);
$ok($nested['assigned'] === [] && $nested['priority'] === [], 'nested arrays are dropped');
$ok(ticketFilterSets(['assigned' => new stdClass()], 7)['assigned'] === [], 'an object is not a value');
$ok(ticketFilterIds(['5', '5', 'x', '07', '0', '-1', '1.2', ' 9 ']) === [5, 7, 9], 'ticketFilterIds: digits only, de-duplicated, leading zeros read as the number');

// SQL builder: only integers and fixed words can reach it
$sql = ticketFilterSql($sets($hostile));
$joined = implode(' ', $sql);
$ok(!preg_match('/[\'"]\s*OR|DROP|DELETE|--|;|0x/i', str_replace("'High'", '', str_replace("'Low'", '', str_replace("'Medium'", '', $joined)))), 'hostile input never reaches the SQL text: ' . $joined);
$sql = ticketFilterSql($sets(['assigned' => ['me', '3', 'unassigned'], 'priority' => ['High', 'Medium'], 'board' => ['5', '6'], 'category' => ['9'], 'onsite' => ['1'], 'due' => ['overdue', 'due_today']]));
$ok($sql['assigned'] === 'AND ticket_assigned_to IN (7,3,0)', 'assigned SQL: ' . $sql['assigned']);
$ok($sql['priority'] === "AND ticket_priority IN ('High','Medium')", 'priority SQL: ' . $sql['priority']);
$ok($sql['board'] === 'AND ticket_category IN (SELECT category_id FROM categories WHERE category_id IN (5,6) OR category_parent IN (5,6))', 'board SQL covers the boards and their sub-categories');
$ok($sql['category'] === 'AND ticket_category IN (9)', 'category SQL');
$ok($sql['onsite'] === 'AND ticket_onsite IN (1)', 'onsite SQL');
$ok(strpos($sql['due'], '(ticket_due_at < NOW() OR DATE(ticket_due_at) = CURDATE())') !== false && strpos($sql['due'], 'ticket_resolved_at IS NULL') !== false, 'due SQL: overdue OR due today, still open: ' . $sql['due']);
$ok(ticketFilterSql($sets([]), '') === ['assigned' => '', 'priority' => '', 'board' => '', 'category' => '', 'onsite' => '', 'due' => ''], 'no filters, no SQL');
$ok(strpos(ticketFilterSql($sets(['priority' => 'High']), 't.')['priority'], 't.ticket_priority') !== false, 'the table alias is honoured');
$ok(strpos(ticketFilterSql($sets(['priority' => 'High']), "t.; DROP")['priority'], 'DROP') === false, 'a bad alias is ignored');
$ok(ticketFilterSql($sets(['overdue' => '1']))['due'] !== '' && strpos(ticketFilterSql($sets(['overdue' => '1']))['due'], 'DATE(') === false, 'overdue alone has no due-today clause');
$ok(ticketFilterSql($sets(['due_today' => '1']))['due'] !== '' && strpos(ticketFilterSql($sets(['due_today' => '1']))['due'], '< NOW()') === false, 'due today alone has no overdue clause');
$ok(ticketFilterSets(['overdue' => '0', 'due_today' => ''], 1)['overdue'] === false, 'overdue=0 is off');

// canonical query + signature
$cq = ticketFilterBuildQuery(['status' => 'Open', 'assigned' => ['me', 3], 'priority' => ['High', 'Medium'], 'overdue' => 1, 'x y' => 'a&b=c']);
$ok($cq === 'status=Open&assigned[]=me&assigned[]=3&priority[]=High&priority[]=Medium&overdue=1&x%20y=a%26b%3Dc', 'canonical query: ' . $cq);
parse_str($cq, $back);
$ok($back['assigned'] === ['me', '3'] && $back['priority'] === ['High', 'Medium'], 'canonical query parses back to lists');
$ok(ticketFilterSignature(['assigned' => ['3', 'me'], 'priority' => 'High,Medium'], 7) === ticketFilterSignature(['priority' => ['Medium', 'High'], 'assigned' => ['7', '3']], 7), 'signature ignores order and treats me as the viewer\'s id');
$ok(ticketFilterSignature(['priority' => 'High'], 7) !== ticketFilterSignature(['priority' => ['High', 'Low']], 7), 'different priority sets have different signatures');
$ok(ticketFilterSignature(['status' => ['3', '2']], 7) === ticketFilterSignature(['status' => ['2', '3']], 7), 'status lists compare by content');
$ok(ticketFilterRequestIsExplicit(['view' => 'kanban', 'page' => '2', 'sort' => 'x']) === false, 'view, page and sort alone are not a filter choice');
$ok(ticketFilterRequestIsExplicit(['priority' => []]) === true && ticketFilterRequestIsExplicit(['status' => 'Open']) === true && ticketFilterRequestIsExplicit(['nodefault' => '1']) === true && ticketFilterRequestIsExplicit(['q' => '']) === true, 'any filter key (even empty) or nodefault is an explicit choice');

// ---------------------------------------------------------------------------------------------------------------------
// 2. Database: fixtures
// ---------------------------------------------------------------------------------------------------------------------
foreach (['ticket_view_defaults', 'ticket_saved_views', 'tickets', 'ticket_replies', 'user_client_permissions', 'user_role_permissions', 'users', 'user_roles', 'api_tokens', 'audit_events', 'logs', 'clients', 'user_settings'] as $t) { $q("DELETE FROM $t"); }
$q("DELETE FROM categories WHERE category_type = 'Ticket'");
$q("DELETE FROM tags WHERE tag_type = 6");
$mod = fn(string $n) => (int) $one("SELECT module_id FROM modules WHERE module_name = '" . $esc($n) . "'");
$q("INSERT INTO clients SET client_id=1, client_name='Dept A'"); $q("INSERT INTO clients SET client_id=2, client_name='Dept B'");
foreach ([[1, 'Admin', 1], [2, 'Tech', 0], [3, 'Viewer', 0], [4, 'NoSupport', 0], [5, 'Restricted', 0]] as [$id, $n, $adm]) { $q("INSERT INTO user_roles SET role_id=$id, role_name='$n', role_is_admin=$adm"); }
foreach ([[2, 'module_support', 2], [3, 'module_support', 1], [4, 'module_client', 1], [5, 'module_support', 2]] as [$r, $m, $l]) { $q("INSERT INTO user_role_permissions SET user_role_id=$r, module_id=" . $mod($m) . ", user_role_permission_level=$l"); }
$people = [1 => ['Ada Admin', 1], 2 => ['Tom Tech', 2], 3 => ['Vi Viewer', 3], 4 => ['Nora NoSupport', 4], 5 => ['Rae Restricted', 5], 6 => ['Ted Tech', 2]];
foreach ($people as $uid => [$nm, $role]) { $q("INSERT INTO users SET user_id=$uid, user_name='$nm', user_email='u$uid@example.test', user_password='x', user_type=1, user_status=1, user_role_id=$role"); $q("INSERT INTO user_settings SET user_id=$uid"); }
$q("INSERT INTO user_client_permissions SET user_id=5, client_id=1");
$q("INSERT INTO categories SET category_id=201, category_name='Infrastructure', category_type='Ticket', category_color='blue', category_parent=0");
$q("INSERT INTO categories SET category_id=202, category_name='Helpdesk', category_type='Ticket', category_color='red', category_parent=0");
$q("INSERT INTO categories SET category_id=203, category_name='Networking', category_type='Ticket', category_color='green', category_parent=201");
$q("INSERT INTO categories SET category_id=204, category_name='Printers', category_type='Ticket', category_color='green', category_parent=202");
$q("INSERT INTO categories SET category_id=205, category_name='Retired board', category_type='Ticket', category_color='gray', category_parent=0, category_archived_at=NOW()");
$q("UPDATE settings SET config_ticket_prefix='T', config_ticket_next_number=2000 WHERE company_id=1");

$tk = [  // key => [priority, assigned, category, onsite, due sql, resolved, client, status]
    'a1' => ['High', 2, '203', 0, 'NULL', 0, 0, 2],
    'a2' => ['Medium', 6, '204', 1, 'NULL', 0, 0, 2],
    'a3' => ['Low', 0, '203', 0, 'NULL', 0, 0, 1],
    'a4' => ['High', 0, '204', 1, 'NULL', 0, 0, 1],
    'a5' => ['Medium', 3, '201', 0, 'DATE_SUB(NOW(), INTERVAL 2 DAY)', 0, 0, 2],
    'a6' => ['High', 6, '202', 0, 'DATE_ADD(CURDATE(), INTERVAL 1439 MINUTE)', 0, 0, 3],
    'a7' => ['Low', 2, '203', 0, 'NULL', 1, 0, 5],
    'a8' => ['High', 0, '203', 0, 'NULL', 0, 2, 1],   // a department Rae may not see
];
$n = 5000; $tid = [];
foreach ($tk as $k => [$pr, $as, $cat, $on, $due, $res, $cl, $st]) {
    $n++;
    $q("INSERT INTO tickets SET ticket_prefix='T', ticket_number=$n, ticket_subject='zz$k" . "zz', ticket_details='d', ticket_priority='$pr', ticket_status=$st, ticket_created_by=1, ticket_assigned_to=$as, ticket_category='$cat', ticket_onsite=$on, ticket_due_at=$due, ticket_resolved_at=" . ($res ? 'NOW()' : 'NULL') . ", ticket_client_id=$cl");
    $tid[$k] = (int) $db->insert_id;
}

/** Which fixture tickets does this WHERE fragment select? (the real SQL, run against the scratch DB) */
$pick = function (array $get, int $me = 2, string $base = 'ticket_resolved_at IS NULL') use ($db, $tid): array {
    $sql = ticketFilterSql(ticketFilterSets($get, $me));
    $r = $db->query("SELECT ticket_subject FROM tickets WHERE $base {$sql['assigned']} {$sql['priority']} {$sql['board']} {$sql['category']} {$sql['onsite']} {$sql['due']} ORDER BY ticket_id");
    $out = [];
    while ($r && ($x = $r->fetch_row())) { $out[] = substr($x[0], 2, -2); }

    return $out;
};
$ok($pick([]) === ['a1', 'a2', 'a3', 'a4', 'a5', 'a6', 'a8'], 'no filters: every open ticket');
$ok($pick(['priority' => ['High', 'Medium']]) === ['a1', 'a2', 'a4', 'a5', 'a6', 'a8'], 'priority High + Medium (OR inside the group)');
$ok($pick(['priority' => 'Low']) === ['a3'], 'old single priority=Low');
$ok($pick(['assigned' => ['me', 'unassigned']], 2) === ['a1', 'a3', 'a4', 'a8'], 'assigned me + unassigned');
$ok($pick(['assigned' => ['6', '3']]) === ['a2', 'a5', 'a6'], 'assigned to two specific agents');
$ok($pick(['assigned' => 'me'], 6) === ['a2', 'a6'], 'old assigned=me resolves per viewer');
$ok($pick(['assigned' => ['me', 'unassigned'], 'priority' => ['High', 'Medium']], 2) === ['a1', 'a4', 'a8'], 'AND across groups: (me or unassigned) and (High or Medium)');
$ok($pick(['board' => '201']) === ['a1', 'a3', 'a5', 'a8'], 'old single board=201: the board and its sub-categories');
$ok($pick(['board' => ['201', '202']]) === ['a1', 'a2', 'a3', 'a4', 'a5', 'a6', 'a8'], 'two boards');
$ok($pick(['category' => ['203', '204']]) === ['a1', 'a2', 'a3', 'a4', 'a8'], 'two categories');
$ok($pick(['category' => '204', 'board' => '202']) === ['a2', 'a4'], 'category and board AND together');
$ok($pick(['onsite' => '1']) === ['a2', 'a4'] && $pick(['onsite' => ['0']]) === ['a1', 'a3', 'a5', 'a6', 'a8'] && count($pick(['onsite' => ['0', '1']])) === 7, 'on-site, remote, both');
$ok($pick(['overdue' => '1']) === ['a5'], 'overdue');
$ok(in_array('a6', $pick(['due_today' => '1']), true) && !in_array('a5', $pick(['due_today' => '1']), true), 'due today');
$ok(array_values(array_intersect($pick(['due' => ['overdue', 'due_today']]), ['a5', 'a6'])) === ['a5', 'a6'] && count($pick(['due' => ['overdue', 'due_today']])) >= 2, 'overdue + due today are independent (either)');
$ok($pick(['priority' => ['High']], 2, '1=1') === ['a1', 'a4', 'a6', 'a8'] && $pick(['assigned' => 'me'], 2, '1=1') === ['a1', 'a7'], 'the filters work with the status snippet "all"');
$ok($pick(['priority' => "High' OR '1'='1", 'assigned' => '1 OR 1=1']) === ['a1', 'a2', 'a3', 'a4', 'a5', 'a6', 'a8'], 'hostile values drop out instead of widening the query');

// ---------------------------------------------------------------------------------------------------------------------
// 3. Form → stored query → description round trips
// ---------------------------------------------------------------------------------------------------------------------
$post = ['f_status_mode' => 'open', 'f_assigned' => ['me', 'unassigned', '6'], 'f_priority' => ['High', 'Medium'], 'f_onsite' => ['1'], 'f_due' => ['overdue', 'due_today'], 'f_board' => ['201', '202'], 'f_category' => ['203', '204'], 'f_datefield' => 'created'];
$stored = ticketViewQueryFromPost($db, $post);
$ok($stored === 'status=Open&assigned[]=me&assigned[]=unassigned&assigned[]=6&priority[]=High&priority[]=Medium&onsite[]=1&board[]=201&board[]=202&category[]=203&category[]=204&overdue=1&due_today=1', 'the form becomes a canonical stored query: ' . $stored);
$p = ticketViewParseQuery($stored);
$ok($p['assigned'] === ['me', 'unassigned', '6'] && $p['priority'] === ['High', 'Medium'] && $p['board'] === ['201', '202'], 'the stored query parses back');
$ok(ticketViewDescribe($db, $stored) === 'Open tickets · Priority: High, Medium · Assigned to: me, Unassigned, Ted Tech · Board: Helpdesk, Infrastructure · Category: Networking, Printers · Where: On-site · Due: Overdue, Due today', 'describe: ' . ticketViewDescribe($db, $stored));
$ok(ticketViewQueryFromPost($db, ['f_priority' => ['High', 'Medium', 'Low']]) === 'status=Open', 'all three priorities is no priority filter');
$ok(ticketViewQueryFromPost($db, ['f_onsite' => ['0', '1']]) === 'status=Open', 'on-site and remote both is no filter');
$ok(ticketViewQueryFromPost($db, ['f_priority' => 'High', 'f_assigned' => 'me', 'f_board' => '201', 'f_onsite' => '0', 'f_due' => 'overdue']) === 'status=Open&assigned[]=me&priority[]=High&onsite[]=0&board[]=201&overdue=1', 'old single-value form fields are still accepted');
$h = ticketViewQueryFromPost($db, ['f_assigned' => ["1' OR 1=1", '999', ['x'], 'me', '2'], 'f_priority' => ['Bogus', ['High'], 'Low'], 'f_board' => ['205', '203', '999', '201'], 'f_category' => ['205', '204', 'x'], 'f_status_mode' => 'specific', 'f_status' => ['2', '99999', 'x', ['3']], 'f_tags' => ['x', '0'], 'f_due' => ['bogus'], 'f_onsite' => ['7']]);
$ok($h === 'status[]=2&assigned[]=me&assigned[]=2&priority[]=Low&board[]=201&category[]=204', 'hostile form values are filtered on the server (the board is top-level only, no archived or unknown ids): ' . $h);
$ok(ticketViewQueryFromPost($db, ['f_status_mode' => ['x'], 'f_date' => ['x'], 'f_datefield' => ['x']]) === 'status=Open', 'array where a scalar is expected does not break it');
$many = array_map('strval', range(1, 300));
$ok(substr_count(ticketViewQueryFromPost($db, ['f_assigned' => $many]), 'assigned[]') <= 50, 'a huge posted list stays capped');
// old stored queries
$ok(ticketViewDescribe($db, 'assigned=me&status=Open') === 'Open tickets · Assigned to: me', 'old stored query: ' . ticketViewDescribe($db, 'assigned=me&status=Open'));
$ok(ticketViewDescribe($db, 'priority=High&onsite=1&status=Open') === 'Open tickets · Priority: High · Where: On-site', 'old single priority and onsite described');
$ok(ticketViewDescribe($db, 'board=201&category=203&assigned=6&status=Open') === 'Open tickets · Assigned to: Ted Tech · Board: Infrastructure · Category: Networking', 'old board, category and agent described');
$ok(ticketViewDescribe($db, '') === 'Open tickets (no extra filters)' && ticketViewDescribe($db, 'status=Closed') === 'Closed tickets', 'empty and closed views');
$ok(ticketViewDescribe($db, 'status[]=2&status[]=3') === 'Status: Open, On Hold', 'specific statuses described');
$ok(ticketViewDescribe($db, 'priority[]=Low&assigned[]=555&assigned[]=0') === 'Open tickets · Priority: Low · Assigned to: an agent, Unassigned', 'an unknown agent id does not break it');
// the filter form itself
ob_start(); ticketViewFilterFields($db, $p, 'tvt'); $form = ob_get_clean();
$checked = function (string $name, string $val) use (&$form) { return (bool) preg_match('/name="' . preg_quote($name, '/') . '" value="' . preg_quote($val, '/') . '"( checked)?>/', $form, $mm) && !empty($mm[1]); };
$ok($checked('f_priority[]', 'High') && $checked('f_priority[]', 'Medium') && !$checked('f_priority[]', 'Low'), 'form: priority High and Medium ticked, Low not');
$ok($checked('f_assigned[]', 'me') && $checked('f_assigned[]', 'unassigned') && $checked('f_assigned[]', '6') && !$checked('f_assigned[]', '2'), 'form: assigned me, unassigned and Ted ticked');
$ok($checked('f_board[]', '201') && $checked('f_board[]', '202') && $checked('f_category[]', '203') && $checked('f_onsite[]', '1') && !$checked('f_onsite[]', '0') && $checked('f_due[]', 'overdue') && $checked('f_due[]', 'due_today'), 'form: boards, categories, on-site and both due boxes ticked');
$ok(substr_count($form, '<fieldset') === 7 && substr_count($form, '<legend') === 7 && substr_count($form, 'data-tv-all') === 7 && substr_count($form, 'data-tv-count') === 7, 'form: every group is a fieldset with a legend, Select all, Clear and a count');
$ok(strpos($form, 'Retired board') === false && strpos($form, ' onclick=') === false && strpos($form, ' onchange=') === false, 'form: archived boards are not offered, no inline handlers');
ob_start(); ticketViewFilterFields($db, ticketViewParseQuery('assigned=me&priority=High&board=201&onsite=0&status=Open'), 'tvt'); $oldform = ob_get_clean();
$form = $oldform;
$ok($checked('f_assigned[]', 'me') && $checked('f_priority[]', 'High') && $checked('f_board[]', '201') && $checked('f_onsite[]', '0'), 'form: an old single-value query shows its choices ticked');

// ---------------------------------------------------------------------------------------------------------------------
// 4. Default view: storage, rules, resolution order
// ---------------------------------------------------------------------------------------------------------------------
$mkview = function (string $name, int $owner, string $query) use ($q, $db): int { $q("INSERT INTO ticket_saved_views SET ticket_saved_view_name='$name', ticket_saved_view_query='" . $db->real_escape_string($query) . "', ticket_saved_view_user_id=$owner"); return (int) $db->insert_id; };
$vHM = $mkview('High+Medium', 2, 'status=Open&priority[]=High&priority[]=Medium');
$vLow = $mkview('Low only', 0, 'status=Open&priority=Low');
$vTed = $mkview('Ted private', 6, 'status=Open&assigned[]=6');
$vShared2 = $mkview('Shared two', 0, 'status=Open&priority[]=High');
$ok(ticketViewDefaultsAvailable($db) === true, 'ticket_view_defaults exists');
$ok(ticketViewDefaultFor($db, 2) === null, 'nothing set: no default view');
$r = ticketViewSetDefault($db, 2, $vHM, 'personal', false);
$ok($r['ok'] && ticketViewDefaultFor($db, 2)['ticket_saved_view_id'] == $vHM && ticketViewDefaultFor($db, 2)['default_source'] === 'personal', 'set a personal default');
$ok(ticketViewDefaultFor($db, 6) === null, 'it is personal: another agent has none');
$r = ticketViewSetDefault($db, 2, $vLow, 'personal', false);
$ok($r['ok'] && ticketViewDefaultFor($db, 2)['ticket_saved_view_id'] == $vLow && (int) $one("SELECT COUNT(*) FROM ticket_view_defaults WHERE default_key = 'u:2'") === 1, 'setting another replaces it: one personal default per user');
$r = ticketViewSetDefault($db, 2, $vTed, 'personal', false);
$ok(!$r['ok'] && ticketViewDefaultFor($db, 2)['ticket_saved_view_id'] == $vLow, 'another agent\'s private view cannot be made your default');
$r = ticketViewSetDefault($db, 2, 99999, 'personal', false);
$ok(!$r['ok'], 'an unknown view cannot be a default');
$r = ticketViewSetDefault($db, 2, $vShared2, 'shared', false);
$ok(!$r['ok'] && (int) $one("SELECT COUNT(*) FROM ticket_view_defaults WHERE default_key = 'shared'") === 0, 'a non-admin cannot set the shared default');
$r = ticketViewSetDefault($db, 1, $vHM, 'shared', true);
$ok(!$r['ok'], 'the shared default must be a view shared with everyone, not someone\'s private view');
$r = ticketViewSetDefault($db, 1, $vShared2, 'shared', true);
$ok($r['ok'] && (int) $one("SELECT COUNT(*) FROM ticket_view_defaults WHERE default_key = 'shared'") === 1, 'an administrator sets the shared default');
$r = ticketViewSetDefault($db, 1, $vLow, 'shared', true);
$ok($r['ok'] && (int) $one("SELECT COUNT(*) FROM ticket_view_defaults WHERE default_scope = 'shared'") === 1 && ticketViewDefaultIds($db, 6)['shared'] === $vLow, 'replacing the shared default keeps exactly one');
$ok(ticketViewDefaultFor($db, 6)['ticket_saved_view_id'] == $vLow && ticketViewDefaultFor($db, 6)['default_source'] === 'shared', 'an agent with no personal default gets the shared one');
$ok(ticketViewDefaultFor($db, 2)['default_source'] === 'personal', 'a personal default beats the shared one');
$ok(@$db->query("INSERT INTO ticket_view_defaults SET default_key='u:2', default_scope='personal', default_user_id=2, default_view_id=$vHM") === false, 'the unique key refuses a second row for one person');
$q("UPDATE ticket_saved_views SET ticket_saved_view_archived_at = NOW() WHERE ticket_saved_view_id = $vLow");
$ok(ticketViewDefaultFor($db, 2) === null && ticketViewDefaultFor($db, 6) === null, 'a deleted (archived) default falls back to the built-in list for both kinds');
$q("UPDATE ticket_saved_views SET ticket_saved_view_archived_at = NULL WHERE ticket_saved_view_id = $vLow");
$q("UPDATE ticket_saved_views SET ticket_saved_view_user_id = 6 WHERE ticket_saved_view_id = $vLow");
$ok(ticketViewDefaultFor($db, 2) === null, 'a shared default whose view stops being shared is not served to an agent who cannot see it');
$ok(ticketViewDefaultFor($db, 6) === null, 'and the view\'s new owner does not get it as the shared default either');
$q("UPDATE ticket_saved_views SET ticket_saved_view_user_id = 0 WHERE ticket_saved_view_id = $vLow");
ticketViewClearDefault($db, 2, 'personal', false);
$ok(ticketViewClearDefault($db, 2, 'personal', false) === false, 'clearing a default that is not set changes nothing');
ticketViewSetDefault($db, 2, $vHM, 'personal', false);
$ok(ticketViewClearDefault($db, 2, 'personal', false) === true && ticketViewDefaultIds($db, 2)['personal'] === null, 'clear the personal default');
$ok(ticketViewClearDefault($db, 2, 'shared', false) === false && ticketViewDefaultIds($db, 2)['shared'] === $vLow, 'a non-admin cannot clear the shared default');
$ok(ticketViewClearDefault($db, 1, 'shared', true) === true && ticketViewDefaultIds($db, 2)['shared'] === null, 'an administrator clears the shared default');
ticketViewSetDefault($db, 2, $vHM, 'personal', false);
ticketViewDropDefaultsFor($db, $vHM);
$ok(ticketViewDefaultFor($db, 2) === null, 'dropping a deleted view\'s defaults leaves none');

// applying a default to a request
ticketViewSetDefault($db, 2, $vHM, 'personal', false);
$get = ['view' => 'kanban'];
$applied = ticketViewApplyDefault($db, 2, $get);
$ok($applied && $get['priority'] === ['High', 'Medium'] && $get['status'] === 'Open' && $get['view'] === 'kanban', 'no filter in the URL: the default\'s filters are added (the list/kanban choice is kept)');
$get = ['status' => 'Closed'];
$ok(ticketViewApplyDefault($db, 2, $get) === null && $get === ['status' => 'Closed'], 'an explicit filter wins');
$get = ['q' => ''];
$ok(ticketViewApplyDefault($db, 2, $get) === null, 'even a bare search box submit is an explicit choice');
$get = ['nodefault' => '1'];
$ok(ticketViewApplyDefault($db, 2, $get) === null, 'nodefault=1 skips the default');
$get = ['page' => '3', 'sort' => 'ticket_priority', 'order' => 'ASC'];
$ok(ticketViewApplyDefault($db, 2, $get) !== null && $get['page'] === '3' && $get['sort'] === 'ticket_priority', 'paging and sorting do not count as a filter');
$get = [];
$ok(ticketViewApplyDefault($db, 6, $get) === null && $get === [], 'a user with no default gets the plain page');
$q("UPDATE ticket_saved_views SET ticket_saved_view_query = 'client_id=2&view=kanban&page=4&status=Open&priority[]=Low&evil=1&sort=x' WHERE ticket_saved_view_id = $vHM");
$get = [];
ticketViewApplyDefault($db, 2, $get);
$ok(!isset($get['client_id']) && !isset($get['view']) && !isset($get['page']) && !isset($get['evil']) && !isset($get['sort']) && $get['priority'] === ['Low'], 'only filter keys are taken from the stored query (never client_id, view, page, sort)');
$q("UPDATE ticket_saved_views SET ticket_saved_view_query = 'status=Open&priority[]=High&priority[]=Medium' WHERE ticket_saved_view_id = $vHM");

// ---------------------------------------------------------------------------------------------------------------------
// 5. HTTP: the page, the post handlers, the modals, the API
// ---------------------------------------------------------------------------------------------------------------------
$sdir = sys_get_temp_dir() . '/tvw_sess_' . bin2hex(random_bytes(3)); mkdir($sdir, 0700);
$web = ea_start_php($root . '/tests/mobile_api_router.php', ['RIVETIT_WEBHOOK_ALLOW_PRIVATE' => '1'], ["session.save_path=$sdir", 'mysqli.default_socket=' . ini_get('mysqli.default_socket')]);
$wb = "http://127.0.0.1:{$web['port']}";
$S = []; foreach ([1 => 'admin', 2 => 'tom', 3 => 'viewer', 4 => 'nosupport', 5 => 'rae', 6 => 'ted'] as $uid => $nm) { $S[$nm] = ea_forge_session($sdir, $uid); }
$page = function (string $who, string $qs) use ($wb, $S): string { [$c, $b] = web($wb, 'GET', '/agent/tickets.php' . $qs, $S[$who]); return $c === 200 ? $b : "HTTP $c"; };
$subjects = function (string $html): array { preg_match_all('/zz(a\d)zz/', $html, $m); $u = array_values(array_unique($m[1])); sort($u); return $u; };
$list = fn(string $who, string $qs) => $subjects($page($who, $qs));
$ok($page('tom', '?status=Open') !== 'HTTP 500' && count($list('tom', '?status=Open')) >= 6, 'the tickets page renders for an agent');

// filters through the real page: new array form, old single form, combinations
$ok($list('tom', '?priority[]=High&priority[]=Medium') === ['a1', 'a2', 'a4', 'a5', 'a6', 'a8'] || $list('tom', '?priority[]=High&priority[]=Medium') === ['a1', 'a2', 'a4', 'a5', 'a6'], 'page: priority[]=High&priority[]=Medium (' . implode(',', $list('tom', '?priority[]=High&priority[]=Medium')) . ')');
$ok(!in_array('a3', $list('tom', '?priority[]=High&priority[]=Medium'), true), 'page: Low is excluded');
$ok(array_diff($list('tom', '?priority=High'), ['a1', 'a4', 'a6', 'a8']) === [] && in_array('a1', $list('tom', '?priority=High'), true), 'page: old priority=High still works');
$ok(array_diff($list('tom', '?assigned[]=me&assigned[]=unassigned'), ['a1', 'a3', 'a4', 'a8']) === [] && in_array('a3', $list('tom', '?assigned[]=me&assigned[]=unassigned'), true), 'page: assigned[]=me&assigned[]=unassigned');
$ok(array_diff($list('ted', '?assigned=me'), ['a2', 'a6']) === [] && in_array('a2', $list('ted', '?assigned=me'), true), 'page: old assigned=me');
$ok(array_diff($list('tom', '?assigned[]=6&assigned[]=3'), ['a2', 'a5', 'a6']) === [] && in_array('a5', $list('tom', '?assigned[]=6&assigned[]=3'), true), 'page: two agents');
$ok(array_diff($list('tom', '?board[]=201&board[]=202'), ['a1', 'a2', 'a3', 'a4', 'a5', 'a6', 'a8']) === [] && in_array('a2', $list('tom', '?board[]=201&board[]=202'), true), 'page: two boards');
$ok(array_diff($list('tom', '?board=201'), ['a1', 'a3', 'a5', 'a8']) === [] && !in_array('a2', $list('tom', '?board=201'), true), 'page: old board=201');
$ok(array_diff($list('tom', '?category[]=203&category[]=204'), ['a1', 'a2', 'a3', 'a4', 'a8']) === [] && in_array('a4', $list('tom', '?category[]=203&category[]=204'), true), 'page: two categories');
$ok(array_diff($list('tom', '?onsite[]=1'), ['a2', 'a4']) === [] && in_array('a4', $list('tom', '?onsite[]=1'), true), 'page: on-site only');
$ok(in_array('a5', $list('tom', '?due[]=overdue&due[]=due_today'), true) && in_array('a6', $list('tom', '?due[]=overdue&due[]=due_today'), true) && !in_array('a1', $list('tom', '?due[]=overdue&due[]=due_today'), true), 'page: overdue + due today');
$ok(array_diff($list('tom', '?status=Closed&priority[]=Low'), ['a7']) === [] && in_array('a7', $list('tom', '?status=Closed&priority[]=Low'), true), 'page: closed tickets with a priority list');
$ok(in_array('a1', $list('tom', '?status[]=2&status[]=3&priority[]=High'), true) && !in_array('a4', $list('tom', '?status[]=2&status[]=3&priority[]=High'), true), 'page: specific statuses combine with the other groups');
$ok($page('tom', "?priority[]=High' OR '1'='1&assigned[]=1)%20OR%201=1") !== 'HTTP 500' && count($list('tom', "?assigned[]=1)%20OR%201=1")) >= 6, 'page: hostile values do not break it or widen it');
$ok(preg_match('/HTTP 500/', $page('tom', '?assigned[][]=1&priority[x][y]=High&board[]=9999999999999999999&status[a][b]=1')) === 0, 'page: nested hostile arrays do not 500');
$ok(!in_array('a8', $list('rae', '?assigned[]=unassigned&priority[]=High')) && in_array('a4', $list('rae', '?assigned[]=unassigned&priority[]=High')), 'a client-restricted agent still cannot see another department\'s ticket through the multi-filters');
// sort + pagination links keep the lists
$html = $page('tom', '?priority[]=High&priority[]=Medium&sort=ticket_priority&order=ASC');
$ok(strpos($html, 'priority%5B0%5D=High') !== false && strpos($html, 'priority%5B1%5D=Medium') !== false, 'sort links keep the multi-values');
// kanban
$ok(array_diff($list('tom', '?view=kanban&priority[]=Low'), ['a3']) === [] && in_array('a3', $list('tom', '?view=kanban&priority[]=Low'), true), 'kanban honours the priority list');
$ok(array_diff($list('tom', '?view=kanban&assigned[]=6&board[]=202'), ['a2', 'a6']) === [], 'kanban honours assigned and board lists');
$kb = $list('tom', '?view=kanban&assigned[]=me&assigned[]=unassigned');
$ok(in_array('a1', $kb, true) && in_array('a3', $kb, true) && !in_array('a2', $kb, true), 'kanban honours assigned me + unassigned');
// the panel
$html = $page('tom', '?priority[]=High&priority[]=Medium&assigned[]=me');
$ok(strpos($html, 'name="priority[]"') !== false && strpos($html, 'name="assigned[]"') !== false && strpos($html, 'name="board[]"') !== false && strpos($html, 'name="category[]"') !== false && strpos($html, 'name="onsite[]"') !== false && strpos($html, 'name="due[]"') !== false, 'filter bar: checkbox groups for priority, assigned, board, category, where, due');
$ok(preg_match('/name="priority\[\]" value="High" checked/', $html) && preg_match('/name="priority\[\]" value="Medium" checked/', $html) && !preg_match('/name="priority\[\]" value="Low" checked/', $html) && preg_match('/name="assigned\[\]" value="me" checked/', $html), 'filter bar: the current choices are ticked');
$ok(strpos($html, 'js/ticket_view_filters.js') !== false && strpos($html, 'data-tv-autosubmit') !== false, 'filter bar loads the external script and applies on close');
$ok(!preg_match('/ on(click|change)=/i', $html), 'no inline event handlers on the page');

// default view through the page
$q("DELETE FROM ticket_view_defaults");
$none = $list('tom', '');
$ok(in_array('a3', $none, true), 'no default: plain page shows all open tickets (incl. the Low one)');
ticketViewSetDefault($db, 2, $vHM, 'personal', false);
$dflt = $page('tom', '');
$ok(!in_array('a3', $subjects($dflt), true) && in_array('a1', $subjects($dflt), true) && strpos($dflt, 'Showing your default view') !== false && strpos($dflt, 'High+Medium') !== false, 'default: Tickets with no filter opens the default view and says so');
$ok(in_array('a3', $list('tom', '?status=Open'), true) && strpos($page('tom', '?status=Open'), 'Showing your default view') === false, 'explicit ?status=Open overrides the default (all open reachable)');
$ok(in_array('a3', $list('tom', '?nodefault=1'), true), '?nodefault=1 shows the plain list');
$ok(!in_array('a3', $list('tom', '?view=kanban'), true) && in_array('a1', $list('tom', '?view=kanban'), true), 'the kanban view without a filter uses the default too');
$ok(!in_array('a3', $list('tom', '?view=list'), true), 'the list view without a filter uses the default too');
$ok(in_array('a3', $list('tom', '?priority[]=Low'), true), 'an explicit priority overrides it');
$ok(strpos($dflt, 'Reset to my default') !== false, 'the reset button reads "Reset to my default"');
$ok(in_array('a3', $list('ted', ''), true) && strpos($page('ted', ''), 'Showing your default view') === false, 'another agent is unaffected by the default');
ticketViewSetDefault($db, 1, $vShared2, 'shared', true);
$shared = $page('ted', '');
$ok(!in_array('a3', $subjects($shared), true) && strpos($shared, 'Shared two') !== false, 'the shared default applies to an agent with no personal default');
$ok(strpos($page('tom', ''), 'High+Medium') !== false, 'a personal default beats the shared one');
$q("UPDATE ticket_saved_views SET ticket_saved_view_archived_at = NOW() WHERE ticket_saved_view_id = $vHM");
$ok(in_array('a3', $list('tom', ''), true) === false && strpos($page('tom', ''), 'Shared two') !== false, 'tom\'s personal default was deleted: the shared one applies');
$q("UPDATE ticket_saved_views SET ticket_saved_view_archived_at = NULL WHERE ticket_saved_view_id = $vHM");
$q("DELETE FROM ticket_view_defaults");
// sidebar
$side = $page('tom', '?status=Open');
$ok(strpos($side, 'set_ticket_view_default=' . $vHM) !== false && strpos($side, 'Make this my default view') !== false, 'sidebar: a star to make a view the default');
ticketViewSetDefault($db, 2, $vHM, 'personal', false);
$side = $page('tom', '?status=Open');
$ok(strpos($side, 'clear_ticket_view_default=' . $vHM) !== false, 'sidebar: the default view\'s star clears it');
$ok(strpos($side, 'Make default for everyone') === false, 'sidebar: a non-admin has no shared-default action');
$ok(strpos($page('admin', '?status=Open'), 'Make default for everyone') !== false, 'sidebar: an administrator can make a shared view the default for everyone');
$ok(strpos($page('tom', '?status=Open'), 'Ted private') === false, 'sidebar: another agent\'s private view is not listed');

// post handlers
$ref = ['Referer: ' . $wb . '/agent/tickets.php'];
$post = function (string $who, array $data) use ($wb, $S, $ref): array { $ch = curl_init("$wb/agent/post.php"); curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($data), CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIE => 'PHPSESSID=' . $S[$who], CURLOPT_HTTPHEADER => $ref]); $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch); return [$code, (string) $raw]; };
$get = function (string $who, string $qs) use ($wb, $S, $ref): array { $ch = curl_init("$wb/agent/post.php?$qs"); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIE => 'PHPSESSID=' . $S[$who], CURLOPT_HTTPHEADER => $ref]); $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch); return [$code, (string) $raw]; };
$csrf = 'csrftok1';
$q("DELETE FROM ticket_view_defaults"); $q("DELETE FROM logs"); $q("DELETE FROM audit_events");
// add a view with two priorities and two agents + make it my default
[$c] = $post('tom', ['csrf_token' => $csrf, 'add_ticket_saved_view' => 1, 'name' => 'Created by form', 'icon' => 'fa-filter', 'filters_present' => 1, 'f_status_mode' => 'open', 'f_priority' => ['High', 'Medium'], 'f_assigned' => ['me', '6'], 'make_default' => 1]);
$vid = (int) $one("SELECT ticket_saved_view_id FROM ticket_saved_views WHERE ticket_saved_view_name = 'Created by form'");
$ok($c === 302 && $vid > 0, 'add view: saved');
$ok($one("SELECT ticket_saved_view_query FROM ticket_saved_views WHERE ticket_saved_view_id = $vid") === 'status=Open&assigned[]=me&assigned[]=6&priority[]=High&priority[]=Medium', 'add view: two priorities and two agents stored as lists: ' . $one("SELECT ticket_saved_view_query FROM ticket_saved_views WHERE ticket_saved_view_id = $vid"));
$ok(ticketViewDefaultIds($db, 2)['personal'] === $vid, 'add view: "make this my default" set it');
$ok((int) $one("SELECT COUNT(*) FROM logs WHERE log_description LIKE '%Tom Tech set their default ticket view to Created by form%'") === 1 && (int) $one("SELECT COUNT(*) FROM audit_events WHERE event_type = 'ticket_view.default_set' AND actor_user_id = 2") === 1, 'add view: the log and the audit trail each have an entry for setting the default');
// a CSRF-less post is refused
$before = (int) $one("SELECT COUNT(*) FROM ticket_saved_views");
[$c, $raw] = $post('tom', ['csrf_token' => 'wrong', 'add_ticket_saved_view' => 1, 'name' => 'No csrf', 'filters_present' => 1]);
$ok((int) $one("SELECT COUNT(*) FROM ticket_saved_views") === $before, 'add view: a wrong CSRF token creates nothing');
// shared default through the form: non-admin refused
[$c] = $post('tom', ['csrf_token' => $csrf, 'add_ticket_saved_view' => 1, 'name' => 'Tom shared try', 'filters_present' => 1, 'shared' => 1, 'shared_default' => 1]);
$ok((int) $one("SELECT COUNT(*) FROM ticket_view_defaults WHERE default_scope = 'shared'") === 0 && (int) $one("SELECT ticket_saved_view_user_id FROM ticket_saved_views WHERE ticket_saved_view_name = 'Tom shared try'") === 2, 'add view: a non-admin cannot share a view or make a shared default (it stays private)');
[$c] = $post('admin', ['csrf_token' => $csrf, 'add_ticket_saved_view' => 1, 'name' => 'Everyone default', 'filters_present' => 1, 'f_priority' => ['High'], 'shared' => 1, 'shared_default' => 1]);
$evid = (int) $one("SELECT ticket_saved_view_id FROM ticket_saved_views WHERE ticket_saved_view_name = 'Everyone default'");
$ok(ticketViewDefaultIds($db, 6)['shared'] === $evid && (int) $one("SELECT ticket_saved_view_user_id FROM ticket_saved_views WHERE ticket_saved_view_id = $evid") === 0, 'add view: an administrator creates a shared view that is the default for everyone');
[$c] = $post('admin', ['csrf_token' => $csrf, 'add_ticket_saved_view' => 1, 'name' => 'Admin private default', 'filters_present' => 1, 'shared_default' => 1]);
$ok(ticketViewDefaultIds($db, 6)['shared'] === $evid, 'add view: a shared default needs a shared view; the earlier shared default is left alone');
// edit: uncheck the box clears it, check it on another replaces
[$c] = $post('tom', ['csrf_token' => $csrf, 'edit_ticket_saved_view' => 1, 'ticket_saved_view_id' => $vid, 'name' => 'Created by form', 'icon' => 'fa-filter', 'filters_present' => 1, 'default_present' => 1, 'f_status_mode' => 'open', 'f_priority' => ['High']]);
$ok(ticketViewDefaultIds($db, 2)['personal'] === null && $one("SELECT ticket_saved_view_query FROM ticket_saved_views WHERE ticket_saved_view_id = $vid") === 'status=Open&priority[]=High', 'edit view: unticking the default box clears it, filters updated');
[$c] = $post('tom', ['csrf_token' => $csrf, 'edit_ticket_saved_view' => 1, 'ticket_saved_view_id' => $vid, 'name' => 'Created by form', 'icon' => 'fa-filter', 'default_present' => 1, 'make_default' => 1]);
$ok(ticketViewDefaultIds($db, 2)['personal'] === $vid && $one("SELECT ticket_saved_view_query FROM ticket_saved_views WHERE ticket_saved_view_id = $vid") === 'status=Open&priority[]=High', 'edit view: ticking it sets the default; a rename without the filter form leaves the filters alone');
[$c] = $post('tom', ['csrf_token' => $csrf, 'edit_ticket_saved_view' => 1, 'ticket_saved_view_id' => $vTed, 'name' => 'Hijacked', 'make_default' => 1, 'default_present' => 1]);
$ok($one("SELECT ticket_saved_view_name FROM ticket_saved_views WHERE ticket_saved_view_id = $vTed") === 'Ted private' && ticketViewDefaultIds($db, 2)['personal'] === $vid, 'edit view: another agent\'s private view cannot be edited or made your default');
// star: set / clear
[$c] = $get('tom', "set_ticket_view_default=$vShared2&csrf_token=$csrf");
$ok($c === 302 && ticketViewDefaultIds($db, 2)['personal'] === $vShared2, 'star: set a shared view as my default (replaces the earlier one)');
[$c] = $get('tom', "clear_ticket_view_default=$vShared2&csrf_token=$csrf");
$ok(ticketViewDefaultIds($db, 2)['personal'] === null, 'star: clear my default');
[$c] = $get('tom', "set_ticket_view_default=$vShared2&csrf_token=wrong");
$ok(ticketViewDefaultIds($db, 2)['personal'] === null, 'star: a wrong CSRF token changes nothing');
[$c] = $get('tom', "set_ticket_view_default=$vTed&csrf_token=$csrf");
$ok(ticketViewDefaultIds($db, 2)['personal'] === null, 'star: another agent\'s private view cannot be set');
[$c] = $get('tom', "set_ticket_view_default=$vShared2&scope=shared&csrf_token=$csrf");
$ok(ticketViewDefaultIds($db, 6)['shared'] === $evid, 'star: a non-admin cannot change the shared default');
[$c] = $get('viewer', "set_ticket_view_default=$vShared2&csrf_token=$csrf");
$ok(ticketViewDefaultIds($db, 3)['personal'] === $vShared2, 'star: a read-only agent can pick a personal default (it is only their own preference)');
[$c] = $get('nosupport', "set_ticket_view_default=$vShared2&csrf_token=$csrf");
$ok(ticketViewDefaultIds($db, 4)['personal'] === null, 'star: a user without the support module cannot set one');
[$c] = $get('admin', "set_ticket_view_default=$vShared2&scope=shared&csrf_token=$csrf");
$ok(ticketViewDefaultIds($db, 6)['shared'] === $vShared2, 'star: an administrator replaces the shared default');
[$c] = $get('admin', "clear_ticket_view_default=$vShared2&scope=shared&csrf_token=$csrf");
$ok(ticketViewDefaultIds($db, 6)['shared'] === null, 'star: an administrator clears the shared default');
ticketViewSetDefault($db, 2, $vShared2, 'personal', false);
// deleting a view drops its defaults
[$c] = $get('admin', "delete_ticket_saved_view=$vShared2&csrf_token=$csrf");
$ok((int) $one("SELECT COUNT(*) FROM ticket_view_defaults WHERE default_view_id = $vShared2") === 0 && ticketViewDefaultFor($db, 2) === null && ticketViewDefaultFor($db, 3) === null, 'deleting a view removes it as a default and users fall back');
$ok((int) $one("SELECT COUNT(*) FROM audit_events WHERE event_type = 'ticket_view.default_cleared'") >= 2 && (int) $one("SELECT COUNT(*) FROM audit_events WHERE event_type = 'ticket_view.default_set'") >= 4 && (int) $one("SELECT COUNT(*) FROM logs WHERE log_description LIKE '%default ticket view%'") >= 8, 'every set and clear is logged (' . $one("SELECT COUNT(*) FROM logs WHERE log_description LIKE '%default ticket view%'") . ' log lines)');

// modals (they answer {"content": "<html>"})
$modal = function (string $who, string $path) use ($wb, $S): array { [$c, $b] = web($wb, 'GET', $path, $S[$who]); $j = json_decode($b, true); return [$c, is_array($j) && isset($j['content']) ? $j['content'] : $b]; };
[$c, $b] = $modal('tom', "/agent/modals/ticket/ticket_saved_view_edit.php?id=$vTed");
$ok(strpos($b, 'Ted private') === false && strpos($b, 'status=Open') === false, 'edit modal: another agent\'s private view is not shown (HTTP ' . $c . ')');
ticketViewSetDefault($db, 2, $vid, 'personal', false); ticketViewSetDefault($db, 1, $evid, 'shared', true);
[$c, $b] = $modal('tom', "/agent/modals/ticket/ticket_saved_view_edit.php?id=$vid");
$ok($c === 200 && strpos($b, 'Created by form') !== false && strpos($b, 'name="make_default"') !== false && preg_match('/name="make_default" value="1" id="savedViewDefault" checked/', $b) && strpos($b, 'name="shared_default"') === false, 'edit modal: own view, default box ticked, no shared-default box for a non-admin');
[$c, $b] = $modal('admin', "/agent/modals/ticket/ticket_saved_view_edit.php?id=$evid");
$ok(strpos($b, 'name="shared_default"') !== false && preg_match('/name="shared_default" value="1" id="savedViewSharedDefault" checked/', $b), 'edit modal: an administrator sees the checked shared-default box on a shared default view');
[$c, $b] = $modal('tom', '/agent/modals/ticket/ticket_saved_view_add.php?priority%5B0%5D=High&priority%5B1%5D=Medium&assigned%5B0%5D=me&assigned%5B1%5D=unassigned&view=list');
$ok($c === 200 && preg_match('/name="f_priority\[\]" value="High" checked/', $b) && preg_match('/name="f_priority\[\]" value="Medium" checked/', $b) && preg_match('/name="f_assigned\[\]" value="me" checked/', $b) && strpos($b, 'name="make_default"') !== false && strpos($b, 'name="shared_default"') === false && strpos($b, 'csrf_token') !== false, 'add modal: prefilled from the current filters, default box, CSRF field, no shared-default for a non-admin');
[$c, $b] = $modal('admin', '/agent/modals/ticket/ticket_saved_view_add.php');
$ok(strpos($b, 'name="shared_default"') !== false, 'add modal: an administrator gets the shared-default box');
[$c, $b] = $modal('viewer', '/agent/modals/ticket/ticket_saved_view_add.php');
$ok($c === 403 || strpos($b, 'name="add_ticket_saved_view"') === false, 'add modal: a read-only agent cannot open it (HTTP ' . $c . ')');
[$c, $b] = $modal('nosupport', '/agent/modals/ticket/ticket_saved_view_add.php');
$ok(strpos($b, 'name="add_ticket_saved_view"') === false, 'add modal: a user without the support module cannot open it');

// API
$tokens = [];
foreach ([2 => 'tom', 3 => 'viewer', 4 => 'nosupport', 6 => 'ted'] as $uid => $nm) { $tokens[$nm] = bin2hex(random_bytes(20)); $q("INSERT INTO api_tokens SET token_user_id=$uid, token_hash='" . hash('sha256', $tokens[$nm]) . "', token_created_at=NOW(), token_last_used_at=NOW()"); }
$api = function (string $who, string $path) use ($wb, $tokens): array { $ch = curl_init($wb . $path); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tokens[$who]]]); $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch); return [$code, json_decode((string) $raw, true)]; };
$vMulti = $mkview('API multi', 2, 'status=Open&assigned[]=me&assigned[]=unassigned&priority[]=High&priority[]=Medium&board[]=201&board[]=202&category[]=203&onsite[]=1&overdue=1&due_today=1');
$vOld = $mkview('API old', 2, 'assigned=me&priority=High&onsite=1&category=203&status=Open&overdue=1');
$vMine = $mkview('API mine', 2, 'status=Open&assigned[]=me');
ticketViewSetDefault($db, 2, $vMulti, 'personal', false);
[$c, $v] = $api('tom', '/api/v1/ticket-views');
$by = []; foreach ((array) $v as $row) { $by[$row['name']] = $row; }
$ok($c === 200 && isset($by['API multi']) && $by['API multi']['params'] === ['status' => 'open', 'assigned' => 'me,unassigned', 'onsite' => '1', 'priority' => 'high,medium', 'category_id' => '203', 'board' => '201,202', 'overdue' => '1', 'due_today' => '1'], 'API ticket-views: multi-values come out comma-separated in params: ' . json_encode($by['API multi']['params'] ?? null));
$ok(($by['API old']['params'] ?? null) === ['status' => 'open', 'mine' => '1', 'onsite' => '1', 'priority' => 'high', 'category_id' => '203', 'overdue' => '1'], 'API ticket-views: an old single-value view is unchanged (mine=1, priority=high, category_id=203): ' . json_encode($by['API old']['params'] ?? null));
$ok(($by['API mine']['params']['mine'] ?? null) === '1' && !isset($by['API mine']['params']['assigned']), 'API ticket-views: assigned to only me keeps the older "mine" form');
$ok(array_values(array_unique(array_map('gettype', $by['API multi']['params']))) === ['string'], 'API ticket-views: every param value is a string (the Android client reads Map<String, String>)');
$ok($by['API multi']['is_default'] === true && $by['API multi']['default_scope'] === 'personal' && $by['API multi']['shared'] === false && $by['API old']['is_default'] === false && $by['API old']['default_scope'] === null, 'API ticket-views: is_default, default_scope and shared');
$ok(($by['Everyone default']['shared'] ?? null) === true, 'API ticket-views: a shared view says so');
[$c, $v] = $api('ted', '/api/v1/ticket-views');
$names = array_column((array) $v, 'name');
$ok($c === 200 && !in_array('API multi', $names, true) && in_array('Ted private', $names, true), 'API ticket-views: another agent\'s private views are not listed');
[$c] = $api('nosupport', '/api/v1/ticket-views');
$ok($c === 403, 'API ticket-views: a token without the support module gets 403');
[$c, $v] = $api('viewer', '/api/v1/ticket-views');
$ok($c === 200 && is_array($v) && isset($v[0]['id'], $v[0]['name'], $v[0]['icon'], $v[0]['params']), 'API ticket-views: still a bare array of id/name/icon/params');
// API tickets filters
$apiList = function (string $qs) use ($api): array { [$c, $r] = $api('tom', '/api/v1/tickets?limit=50&' . $qs); $subj = []; foreach (($r['data'] ?? []) as $t) { if (preg_match('/zz(a\d)zz/', $t['subject'], $m)) { $subj[] = $m[1]; } } sort($subj); return $subj; };
$ok($apiList('priority=high,medium') === ['a1', 'a2', 'a4', 'a5', 'a6', 'a8'], 'API tickets: priority=high,medium');
$ok($apiList('priority=high&priority=low') !== [] , 'API tickets: repeated key is last-wins in PHP, still a valid single filter');
$ok($apiList('assigned=me,unassigned') === ['a1', 'a3', 'a4', 'a8'], 'API tickets: assigned=me,unassigned');
$ok($apiList('assigned=6,3') === ['a2', 'a5', 'a6'], 'API tickets: assigned=6,3');
$ok($apiList('board=201,202&onsite=1') === ['a2', 'a4'], 'API tickets: board list and onsite combine');
$ok($apiList('category_id=203,204') === ['a1', 'a2', 'a3', 'a4', 'a8'], 'API tickets: category_id list');
$ok($apiList('priority=high&mine=1') === ['a1'], 'API tickets: the older mine=1 and a single priority still work');
$ok($apiList('priority=critical') === [], 'API tickets: an unknown priority matches nothing, as before');
$ok($apiList("priority=high'%20OR%20'1'='1") === [] || count($apiList("priority=high'%20OR%20'1'='1")) <= 7, 'API tickets: SQL metacharacters in a value do not widen the list');
$ok(in_array('a5', $apiList('overdue=1'), true) && !in_array('a1', $apiList('overdue=1'), true), 'API tickets: overdue');

// scratch-only teardown of the temp session dir
foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir);
