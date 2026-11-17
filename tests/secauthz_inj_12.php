<?php
declare(strict_types=1);
/* IT-INJ-12: problem pages list/count only the linked tickets the agent's departments allow. */
require __DIR__ . '/secauthz_bootstrap.php';
sec_fn(['clientAccessScopeSql']);
sec_reset_fixture(); $q("DELETE FROM problems");
$q("INSERT INTO problems SET problem_id = 1, title = 'P'");
$q("INSERT INTO tickets SET ticket_id = 1, ticket_prefix = 'T', ticket_number = 1, ticket_subject = 'A', ticket_client_id = 1, ticket_problem_id = 1");
$q("INSERT INTO tickets SET ticket_id = 2, ticket_prefix = 'T', ticket_number = 2, ticket_subject = 'B-secret', ticket_client_id = 2, ticket_problem_id = 1");
$q("INSERT INTO tickets SET ticket_id = 3, ticket_prefix = 'T', ticket_number = 3, ticket_subject = 'Global', ticket_client_id = 0, ticket_problem_id = 1");

// the real query out of problem_details.php, run as each kind of agent
$src = (string) file_get_contents(SECAUTHZ_ROOT . '/agent/problem_details.php');
preg_match('/\$linked_tickets = mysqli_query\(.*?\n\);/s', $src, $m);
$subjects = function (string $who) use ($m, $mysqli) {
    $GLOBALS['session_is_admin'] = $who === 'admin';
    $GLOBALS['client_access_string'] = $who === 'restricted' ? '1' : '';
    $problem_id = 1;
    eval($m[0]);
    $out = []; while ($r = mysqli_fetch_assoc($linked_tickets)) { $out[] = $r['ticket_subject']; }
    sort($out); return $out;
};
$ok($subjects('restricted') === ['A', 'Global'], "problem_details: restricted agent sees own-department + unassigned tickets only (" . implode(',', $subjects('restricted')) . ")");
$ok($subjects('admin') === ['A', 'B-secret', 'Global'], "problem_details: administrator sees all");
$ok($subjects('open') === ['A', 'B-secret', 'Global'], "problem_details: agent with no department restriction sees all");

// the count on problems.php
$src2 = (string) file_get_contents(SECAUTHZ_ROOT . '/agent/problems.php');
$ok(str_contains($src2, "clientAccessScopeSql('tickets.ticket_client_id')"), "problems.php: linked-ticket count is department-scoped");
$GLOBALS['session_is_admin'] = false; $GLOBALS['client_access_string'] = '1';
$cnt = (int) $one("SELECT (SELECT COUNT(*) FROM tickets WHERE ticket_problem_id = problems.problem_id" . clientAccessScopeSql('tickets.ticket_client_id') . ") FROM problems WHERE problem_id = 1");
$ok($cnt === 2, "problems.php count for a restricted agent excludes the other department ($cnt)");
$ok(clientAccessScopeSql('x; DROP') === ' AND 1 = 0', "helper refuses a hostile column name");
exit($T_fails ? 1 : 0);
