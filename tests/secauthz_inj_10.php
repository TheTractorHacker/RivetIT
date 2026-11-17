<?php
declare(strict_types=1);
/* IT-INJ-10: guest task-approval link records the guest channel, not the configured required user; single use. */
require __DIR__ . '/secauthz_bootstrap.php';
function getIP() { return '203.0.113.9'; }
function nullable_htmlentities($v) { return htmlentities((string) $v, ENT_QUOTES, 'UTF-8'); }
sec_reset_fixture();
foreach (['task_approvals', 'tasks', 'notifications'] as $t) { $q("DELETE FROM $t"); }
$q("INSERT INTO tasks SET task_id = 5, task_name = 'T', task_ticket_id = 1");
$q("INSERT INTO task_approvals SET approval_id = 7, approval_scope = 'client', approval_type = 'specific', approval_required_user_id = 10, approval_status = 'pending', approval_created_by = 10, approval_url_key = 'k3y', approval_task_id = 5");
$get = ['__get' => ['approve_ticket_task' => 5, 'approval_id' => 7, 'approval_url_key' => 'k3y']];
$st = fn() => mysqli_fetch_assoc($q("SELECT approval_status, approval_approved_by FROM task_approvals WHERE approval_id = 7"));

$r = sec_run('guest/guest_post.php', 'approve_ticket_task', $get);
$a = $st();
$ok($a['approval_status'] === 'approved' && str_starts_with((string) $a['approval_approved_by'], 'Guest link (203.0.113.9)'), "approval is recorded as the guest link ({$a['approval_approved_by']})");
$ok(!in_array($a['approval_approved_by'], ['10'], true), 'approval is NOT attributed to the required user id alone');
$q("UPDATE task_approvals SET approval_approved_by = 'orig'");
// a second click hits exit("Cannot find/approve that task"): judge the result from a shutdown hook
register_shutdown_function(function () use ($st, &$T_fails) {
    $same = $st()['approval_approved_by'] === 'orig';
    echo ($same ? 'PASS' : 'FAIL') . "  link is single use: a second click changes nothing\n";
    exit($same && !$T_fails ? 0 : 1);
});
sec_run('guest/guest_post.php', 'approve_ticket_task', $get);
