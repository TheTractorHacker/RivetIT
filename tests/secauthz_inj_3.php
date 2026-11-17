<?php
declare(strict_types=1);
/* IT-INJ-3: link_ticket_to_project / link_problem_ticket must enforce department access on the ticket end. */
require __DIR__ . '/secauthz_bootstrap.php';
// The worktree has no composer vendor/: stand in for the core service's one write (ticket_problem_id) - the handler code under test is real.
eval('namespace ITFlow\\ITSM; class ProblemService { public function __construct(private \\mysqli $db) {} public function linkTicket(int $p, int $t): void { $this->db->query("UPDATE tickets SET ticket_problem_id = $p WHERE ticket_id = $t"); } }');
sec_reset_fixture();
if (!function_exists('findTicketIdForLinking')) {
    $ps = (string) file_get_contents(SECAUTHZ_ROOT . '/agent/post/problem.php');
    preg_match('/^function findTicketIdForLinking\(.*?^}\n/ms', $ps, $m); eval($m[0]);
}
$q("INSERT INTO tickets SET ticket_id = 1, ticket_prefix = 'T', ticket_number = 1001, ticket_subject = 'A ticket', ticket_client_id = 1");
$q("INSERT INTO tickets SET ticket_id = 2, ticket_prefix = 'T', ticket_number = 1002, ticket_subject = 'B secret', ticket_client_id = 2");
$q("INSERT INTO tickets SET ticket_id = 3, ticket_prefix = 'T', ticket_number = 1003, ticket_subject = 'Global', ticket_client_id = 0");
$q("INSERT INTO projects SET project_id = 1, project_name = 'PA', project_client_id = 1");
$q("INSERT INTO problems SET problem_id = 1, title = 'P'");
$GLOBALS['T_perm'] = ['module_support' => 2];
$pj = fn(int $t) => (int) $one("SELECT ticket_project_id FROM tickets WHERE ticket_id = $t");
$pb = fn(int $t) => (int) $one("SELECT COALESCE(ticket_problem_id,0) FROM tickets WHERE ticket_id = $t");

$r = sec_run('agent/post/project.php', 'link_ticket_to_project', ['link_ticket_to_project' => 1, 'csrf_token' => 't', 'project_id' => 1, 'tickets' => [1, 2]]);
$ok($pj(2) === 0 && $pj(1) === 0 && str_starts_with($r, 'redirect:'), "project: a foreign-department ticket in the batch is refused and nothing is half-linked ($r)");
$r = sec_run('agent/post/project.php', 'link_ticket_to_project', ['link_ticket_to_project' => 1, 'csrf_token' => 't', 'project_id' => 1, 'tickets' => [1, 3]]);
$ok($pj(1) === 1 && $pj(3) === 1, "project: own-department and unassigned tickets still link ($r)");
$q("UPDATE tickets SET ticket_project_id = 0");

$r = sec_run('agent/post/problem.php', 'link_problem_ticket', ['link_problem_ticket' => 1, 'csrf_token' => 't', 'problem_id' => 1, 'ticket_number' => '1002']);
$ok($pb(2) === 0, "problem: foreign-department ticket cannot be linked ($r)");
$r = sec_run('agent/post/problem.php', 'link_problem_ticket', ['link_problem_ticket' => 1, 'csrf_token' => 't', 'problem_id' => 1, 'ticket_number' => '1001']);
$ok($pb(1) === 1, "problem: own-department ticket still links ($r)");
$GLOBALS['session_is_admin'] = true;
$r = sec_run('agent/post/problem.php', 'link_problem_ticket', ['link_problem_ticket' => 1, 'csrf_token' => 't', 'problem_id' => 1, 'ticket_number' => '1002']);
$ok($pb(2) === 1, "problem: administrator can link any ticket ($r)");
$r = sec_run('agent/post/project.php', 'link_ticket_to_project', ['link_ticket_to_project' => 1, 'csrf_token' => 't', 'project_id' => 1, 'tickets' => [2]]);
$ok($pj(2) === 1, "project: administrator can link any ticket ($r)");
exit($T_fails ? 1 : 0);
