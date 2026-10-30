<?php
/*
 * Project tasks, phases 2 and 3 (DB 2.6.208 / 2.6.209): checklists, subtasks, comments, several assignees, attachments, reminders, repeating
 * tasks, the pop-up panels, the Kanban card, the API sub-resources, the reminder cron function.
 * Scratch database only (refuses unless the name contains "scratch"); starts and stops its own php -S server. Never sends a real
 * notification: reminders go through a stub notifier, and the notifications table is checked to be untouched.
 *
 *   RIVETIT_TEST_PORT=8098 php tests/project_tasks_phase2_3_test.php
 *
 * Needs: config.php pointing at a scratch DB built from the 2.6.206 schema with one admin (user 1 role admin), and a root MySQL helper
 * for the fresh-install comparison (RIVETIT_TEST_MYSQL_ROOT, default /home/sysadmin/demos/mysqlroot.sh; it creates and drops one more
 * scratch database). Never prints a password, token or secret.
 *
 * Covers: migration 2.6.206 -> 2.6.207 equals a fresh db.sql install (SHOW CREATE TABLE), rerun is a no-op that keeps data; the pages,
 * pop-ups, handlers and API on a 2.6.206 schema never fatal (Update App before Update Database); create / edit with priority, labels,
 * description, colour; XSS escaping; CSRF; permission levels and client access on delete; label scoping; duplicate / move / lane change;
 * API read / create / patch / delete, scopes, and that the old fields are unchanged.
 */
chdir(dirname(__DIR__));
$ROOT = getcwd();
require_once $ROOT . '/config.php';
if (!str_contains((string) $database, 'scratch')) {
    fwrite(STDERR, "Refusing: database is not a scratch database.\n");
    exit(1);
}
require_once $ROOT . '/functions.php';
require_once $ROOT . '/includes/database_version.php';

use ITFlow\Integration\IntegrationAuth;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli($dbhost, $dbusername, $dbpassword, $database);
$db->set_charset('utf8mb4');
$db->query("SET sql_mode=''");

$PASS = 0;
$FAIL = [];
function ok(bool $cond, string $name, string $detail = ''): void
{
    global $PASS, $FAIL;
    if ($cond) {
        $PASS++;
        echo "  ok   $name\n";
    } else {
        $FAIL[] = $name;
        echo "  FAIL $name" . ($detail !== '' ? " -- $detail" : '') . "\n";
    }
}
function section(string $s): void
{
    echo "\n== $s\n";
}
function q(mysqli $db, string $sql): array
{
    $r = $db->query($sql);
    return $r instanceof mysqli_result ? $r->fetch_all(MYSQLI_ASSOC) : [];
}
function q1(mysqli $db, string $sql)
{
    $r = q($db, $sql);
    return $r ? array_values($r[0])[0] : null;
}
$PORT = getenv('RIVETIT_TEST_PORT') ?: '8098';
$BASE = "http://127.0.0.1:$PORT";
$ROOTCMD = getenv('RIVETIT_TEST_MYSQL_ROOT') ?: '/home/sysadmin/demos/mysqlroot.sh';

/** @return array{0:int,1:string,2:array} status, body, lowercase headers */
function http(string $method, string $url, array $headers = [], $body = null, ?string $jar = null, bool $follow = false): array
{
    $ch = curl_init($url);
    $h = [];
    foreach ($headers as $k => $v) {
        $h[] = "$k: $v";
    }
    $opts = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => $follow];
    if ($jar) {
        $opts[CURLOPT_COOKIEJAR] = $jar;
        $opts[CURLOPT_COOKIEFILE] = $jar;
    }
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = $body;
    }
    curl_setopt_array($ch, $opts);
    $raw = (string) curl_exec($ch);
    $size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $hdr = [];
    foreach (explode("\r\n", substr($raw, 0, $size)) as $line) {
        if (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $hdr[strtolower(trim($k))] = trim($v);
        }
    }
    return [$status, substr($raw, $size), $hdr];
}

// ---------------------------------------------------------------- server
$serverLog = sys_get_temp_dir() . '/pt23_server_' . getmypid() . '.log';
$proc = proc_open(['php', '-S', "127.0.0.1:$PORT", '-t', $ROOT, '/home/sysadmin/demos/router.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $serverLog, 'w'], 2 => ['file', $serverLog, 'w']], $pipes, $ROOT);
register_shutdown_function(function () use ($proc, $serverLog) {
    if (is_resource($proc)) {
        proc_terminate($proc);
    }
    @unlink($serverLog);
});
for ($i = 0; $i < 40; $i++) {
    if (@fsockopen('127.0.0.1', (int) $PORT)) {
        break;
    }
    usleep(100000);
}

// ---------------------------------------------------------------- web session helpers
/** Log in and return [cookie jar, status]. */
function webLogin(string $base, string $email, string $password): array
{
    $jar = tempnam(sys_get_temp_dir(), 'pt23jar');
    http('GET', "$base/login.php", [], null, $jar);
    [$st] = http('POST', "$base/login.php", ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query(['email' => $email, 'password' => $password, 'login' => 1]), $jar);
    return [$jar, $st];
}
function csrfOf(string $base, string $jar, int $project_id): string
{
    [, $html] = http('GET', "$base/agent/project_kanban.php?project_id=$project_id", [], null, $jar);
    return preg_match('/PROJECT_KANBAN_CSRF = "([^"]+)"/', $html, $m) ? $m[1] : '';
}
/** POST to agent/post.php with the kanban page as referer; returns [status, location]. */
function webPost(string $base, string $jar, int $project_id, array $fields): array
{
    [$st, , $h] = http('POST', "$base/agent/post.php", ['Content-Type' => 'application/x-www-form-urlencoded', 'Referer' => "$base/agent/project_kanban.php?project_id=$project_id"], http_build_query($fields), $jar);
    return [$st, $h['location'] ?? ''];
}
function modal(string $base, string $jar, string $path): array
{
    [$st, $body] = http('GET', "$base/agent/modals/project/$path", ['X-Requested-With' => 'XMLHttpRequest'], null, $jar);
    $j = json_decode($body, true);
    return [$st, is_array($j) ? (string) ($j['content'] ?? json_encode($j)) : $body, $body];
}
function noPhpErrors(string $text): bool
{
    return !preg_match('/(Fatal error|Parse error|Warning:|Notice:|Deprecated:|Uncaught|Stack trace)/', $text);
}

require_once $ROOT . '/includes/database_version.php';
require_once $ROOT . '/includes/project_tasks.php';
$db->query("SET sql_mode=''");

function cardHtml(string $html, int $id): string
{
    if (!preg_match('/<div class="task grab-cursor[^"]*" data-task-id="' . $id . '".*?(?=<div class="task grab-cursor|<\/div>\s*<\/div>\s*<div class="kanban-column|<!-- Per-card)/s', $html, $m)) {
        return '';
    }
    return $m[0];
}
/** POST one panel action to agent/project_task_ajax.php. @return array{0:int,1:array,2:string} */
function ptAjax(string $base, string $jar, string $csrf, array $f, ?string $file = null, ?string $fileName = null): array
{
    $fields = $f + ['csrf_token' => $csrf];
    if ($file !== null) {
        $fields['file'] = new CURLFile($file, 'application/octet-stream', $fileName ?? basename($file));
        $body = $fields;
    } else {
        $body = http_build_query($fields);
    }
    [$st, $raw] = http('POST', "$base/agent/project_task_ajax.php", ['X-Requested-With' => 'XMLHttpRequest'], $body, $jar);
    return [$st, json_decode($raw, true) ?? [], $raw];
}
function panelHtml(array $j, string $name): string
{
    return (string) ($j['panels'][$name] ?? '');
}
function api(string $method, string $path, array $auth, ?array $json = null, $raw = null): array
{
    global $BASE;
    $h = $auth;
    $body = $raw;
    if ($json !== null) {
        $h['Content-Type'] = 'application/json';
        $body = json_encode($json);
    }
    [$st, $out, $hdr] = http($method, "$BASE/api/v1/" . ltrim($path, '/'), $h, $body);
    return [$st, json_decode($out, true), $out, $hdr];
}
/** Run PHP in a fresh process (so the readiness caches start empty) and return its stdout. */
function sub(string $code): string
{
    global $ROOT;
    return trim((string) shell_exec('cd ' . escapeshellarg($ROOT) . ' && php -r ' . escapeshellarg('require "config.php"; require "functions.php"; require "includes/project_tasks.php"; ' . $code) . ' 2>&1'));
}

// ---------------------------------------------------------------- fixtures
section('Fixtures (schema reset to 2.6.207)');
$db->query('SET FOREIGN_KEY_CHECKS=0');
foreach (['task_assignees', 'task_attachments', 'task_checklist_items', 'task_comments'] as $t) {
    $db->query("DROP TABLE IF EXISTS $t");
}
if (q1($db, "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'tasks' AND CONSTRAINT_NAME = 'tasks_parent_fk'")) {
    $db->query('ALTER TABLE tasks DROP FOREIGN KEY tasks_parent_fk');
}
$db->query('ALTER TABLE tasks DROP COLUMN IF EXISTS task_parent_id, DROP COLUMN IF EXISTS task_reminder_at, DROP COLUMN IF EXISTS task_reminder_sent_at, DROP COLUMN IF EXISTS task_repeat_interval, DROP COLUMN IF EXISTS task_repeat_unit, DROP COLUMN IF EXISTS task_repeat_spawned_at');
$db->query('SET FOREIGN_KEY_CHECKS=1');
// 2.6.207 must exist (Phase 1) for this test to mean anything
$db->query("UPDATE settings SET config_current_database_version = '2.6.207' WHERE company_id = 1");
foreach (['task_label_links', 'tasks', 'project_milestones', 'projects', 'user_client_permissions', 'integration_tokens', 'integration_client_secrets', 'integration_client_departments', 'integration_clients', 'api_tokens'] as $t) {
    $db->query("DELETE FROM `$t`");
}
$db->query("DELETE FROM task_labels");
$db->query("DELETE FROM clients");
$db->query("DELETE FROM users WHERE user_email LIKE '%@pt23.test'");
$db->query("DELETE FROM user_roles WHERE role_name LIKE 'PT23 %'");
$notifBefore = (int) q1($db, 'SELECT COUNT(*) FROM notifications');

$db->query("INSERT INTO clients SET client_name = 'PT23 Client A', client_created_at = NOW()");
$clientA = $db->insert_id;
$db->query("INSERT INTO clients SET client_name = 'PT23 Client B', client_created_at = NOW()");
$clientB = $db->insert_id;
$db->query("INSERT INTO projects SET project_prefix = 'QA', project_number = 1, project_name = 'Project A', project_client_id = $clientA");
$projA = $db->insert_id;
$db->query("INSERT INTO projects SET project_prefix = 'QA', project_number = 2, project_name = 'Project A2', project_client_id = $clientA");
$projA2 = $db->insert_id;
$db->query("INSERT INTO projects SET project_prefix = 'QB', project_number = 1, project_name = 'Project B', project_client_id = $clientB");
$projB = $db->insert_id;
$db->query("INSERT INTO project_milestones SET milestone_name = 'M-A', milestone_project_id = $projA");
$msA = $db->insert_id;

$adminId = (int) q1($db, "SELECT user_id FROM users WHERE user_role_id = 3 AND user_status = 1 ORDER BY user_id LIMIT 1");
$adminEmail = (string) q1($db, "SELECT user_email FROM users WHERE user_id = $adminId");
$ADMINPW = 'Pt23Admin#2026-test';
$db->query("UPDATE users SET user_password = '" . password_hash($ADMINPW, PASSWORD_DEFAULT) . "' WHERE user_id = $adminId");
$mkUser = function (string $name, int $roleId, int $status = 1) use ($db): int {
    $pw = password_hash('Pt23User#2026-test', PASSWORD_DEFAULT);
    $db->query("INSERT INTO users SET user_name = '$name', user_email = '" . strtolower(str_replace(' ', '.', $name)) . "@pt23.test', user_password = '$pw', user_role_id = $roleId, user_status = $status, user_type = 1");
    return $db->insert_id;
};
$supportModule = (int) q1($db, "SELECT module_id FROM modules WHERE module_name = 'module_support'");
$mkRole = function (string $name, int $level) use ($db, $supportModule): int {
    $db->query("INSERT INTO user_roles SET role_name = '$name', role_description = 'test', role_is_admin = 0");
    $rid = $db->insert_id;
    $db->query("INSERT INTO user_role_permissions SET user_role_id = $rid, module_id = $supportModule, user_role_permission_level = $level");
    return $rid;
};
$leadRole = $mkRole('PT23 Lead', 3);
$readerRole = $mkRole('PT23 Reader', 1);
$techId = $mkUser('Pt23 Tech', 2);
$tech2Id = $mkUser('Pt23 Tech Two', 2);
$leadId = $mkUser('Pt23 Lead', $leadRole);
$readerId = $mkUser('Pt23 Reader', $readerRole);
$gone = $mkUser('Pt23 Inactive', 2, 0);
$db->query("INSERT INTO user_client_permissions SET user_id = $leadId, client_id = $clientA");
$db->query("INSERT INTO user_client_permissions SET user_id = $tech2Id, client_id = $clientB");   // tech2 may only see Client B

$db->query("INSERT INTO tasks SET task_name = 'Legacy one', task_project_id = $projA, task_assigned_to = $techId, task_due = '2026-03-01'");
$legacy1 = $db->insert_id;
$db->query("INSERT INTO tasks SET task_name = 'Legacy two', task_project_id = $projA");
$legacy2 = $db->insert_id;
$db->query("INSERT INTO tasks SET task_name = 'B task', task_project_id = $projB");
$bTask = $db->insert_id;

[$adminJar, $st] = webLogin($BASE, $adminEmail, $ADMINPW);
ok($st === 302, 'admin web login (scratch)', "HTTP $st");
$csrf = csrfOf($BASE, $adminJar, $projA);
ok(strlen($csrf) > 10, 'admin CSRF token read from the Kanban page (schema 2.6.207)');
$rawAdmin = bin2hex(random_bytes(32));
$db->query("INSERT INTO api_tokens SET token_user_id = $adminId, token_hash = '" . hash('sha256', $rawAdmin) . "', token_created_at = NOW()");
$ADM = ['Authorization' => "Bearer $rawAdmin"];

// ================================================================ 1. Update App before Update Database
section('Update App before Update Database: a 2.6.207 schema never fatals');
ok(!(bool) q1($db, "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'tasks' AND column_name = 'task_parent_id'"), 'precondition: tasks.task_parent_id does not exist yet');
[$st, $html] = http('GET', "$BASE/agent/project_kanban.php?project_id=$projA", [], null, $adminJar);
ok($st === 200 && noPhpErrors($html) && str_contains($html, 'Legacy one') && str_contains($html, 'Pt23 Tech'), 'Kanban renders on 2.6.207 and still shows the primary assignee');
[$st, $html] = http('GET', "$BASE/agent/project_details.php?project_id=$projA", [], null, $adminJar);
ok($st === 200 && noPhpErrors($html) && str_contains($html, 'Legacy two'), 'project details list renders on 2.6.207');
foreach (["task_add.php?project_id=$projA", "task_edit.php?id=$legacy1", "task_detail.php?id=$legacy1"] as $m) {
    [$st, $h] = modal($BASE, $adminJar, $m);
    ok($st === 200 && noPhpErrors($h) && str_contains($h, 'name="name"'), "pop-up $m renders on 2.6.207", "HTTP $st");
}
[$st, $h] = modal($BASE, $adminJar, "task_detail.php?id=$legacy1");
ok(!str_contains($h, 'id="task-panels"') && !str_contains($h, 'name="assignee_ids[]"') && !str_contains($h, 'name="repeat_interval"'), 'pop-up shows no checklist / comments / assignee list / repeat before Update Database');
[$st, $j] = ptAjax($BASE, $adminJar, $csrf, ['action' => 'checklist_add', 'task_id' => $legacy1, 'text' => 'x']);
ok($st === 409 && ($j['ok'] ?? true) === false, 'panel endpoint answers 409 (run Update Database) instead of failing', "HTTP $st");
[$st, $j] = ptAjax($BASE, $adminJar, $csrf, ['action' => 'file_upload', 'task_id' => $legacy1]);
ok($st === 409, 'file upload answers 409 on 2.6.207');
[$st] = http('GET', "$BASE/agent/project_task_attachment.php?task_id=$legacy1&id=1", [], null, $adminJar);
ok($st === 404, 'attachment download answers 404 on 2.6.207');
[$st] = webPost($BASE, $adminJar, $projA, ['csrf_token' => $csrf, 'add_project_task' => 1, 'project_id' => $projA, 'name' => 'Pre task', 'assigned_to' => $adminId, 'assignees_present' => 1, 'assignee_ids' => [$techId], 'reminder_present' => 1, 'reminder_at' => '2030-01-01T10:00', 'repeat_present' => 1, 'repeat_interval' => 2, 'repeat_unit' => 'week']);
$pre = q($db, "SELECT * FROM tasks WHERE task_name = 'Pre task'");
ok($st === 302 && count($pre) === 1 && (int) $pre[0]['task_assigned_to'] === $adminId, 'create with assignees / reminder / repeat on 2.6.207 succeeds; the primary assignee is kept, the rest ignored');
$preId = (int) $pre[0]['task_id'];
[$st] = webPost($BASE, $adminJar, $projA, ['csrf_token' => $csrf, 'edit_project_task' => 1, 'task_id' => $preId, 'name' => 'Pre task edited', 'assigned_to' => $techId, 'assignees_present' => 1, 'assignee_ids' => [$adminId]]);
ok($st === 302 && (int) q1($db, "SELECT task_assigned_to FROM tasks WHERE task_id = $preId") === $techId, 'edit on 2.6.207 succeeds');
http('GET', "$BASE/agent/post.php?complete_task=$preId&csrf_token=" . urlencode($csrf), ['Referer' => "$BASE/agent/project_kanban.php?project_id=$projA"], null, $adminJar);
ok(q1($db, "SELECT task_completed_at FROM tasks WHERE task_id = $preId") !== null && (int) q1($db, "SELECT COUNT(*) FROM tasks WHERE task_name LIKE 'Pre task%'") === 1, 'Mark complete works on 2.6.207 (no repeat hook fatal, nothing spawned)');
[$st] = webPost($BASE, $adminJar, $projA, ['csrf_token' => $csrf, 'duplicate_project_task' => 1, 'task_id' => $preId]);
ok($st === 302 && (int) q1($db, "SELECT COUNT(*) FROM tasks WHERE task_name = 'Pre task edited (copy)'") === 1, 'duplicate works on 2.6.207');
[$st, $body] = http('POST', "$BASE/agent/ajax.php", ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query(['update_project_task_kanban' => 1, 'csrf_token' => $csrf, 'positions' => [['task_id' => $legacy2, 'task_order' => 3, 'task_status' => 'Done']]]), $adminJar);
ok($st === 200 && (json_decode($body, true)['status'] ?? '') === 'success' && q1($db, "SELECT task_completed_at FROM tasks WHERE task_id = $legacy2") !== null, 'Kanban drag to Done works on 2.6.207');
[$st] = webPost($BASE, $adminJar, $projA, ['csrf_token' => $csrf, 'delete_project_task' => 1, 'task_id' => $preId]);
ok($st === 302 && (int) q1($db, "SELECT COUNT(*) FROM tasks WHERE task_id = $preId") === 0, 'delete works on 2.6.207');
$db->query("DELETE FROM tasks WHERE task_name LIKE 'Pre task%'");
$db->query("UPDATE tasks SET task_completed_at = NULL, task_completed_by = NULL, task_progress = 0, task_status = 'To Do' WHERE task_id = $legacy2");
[$st, $j] = api('GET', "tasks/$legacy1", $ADM);
ok($st === 200 && $j['parent_id'] === null && $j['checklist'] === ['done' => 0, 'total' => 0] && $j['subtasks'] === ['done' => 0, 'total' => 0] && $j['comment_count'] === 0 && $j['attachment_count'] === 0 && $j['reminder_at'] === null && $j['repeat'] === null && count($j['assignees']) === 1 && $j['assignees'][0]['id'] === $techId && $j['assignees'][0]['primary'] === true, 'API task: the new fields read as neutral values on 2.6.207 (primary assignee still listed)', json_encode($j));
[$st, $j] = api('PATCH', "tasks/$legacy1", $ADM, ['parent_id' => $legacy2, 'assignee_ids' => [$adminId], 'reminder_at' => '2030-01-01 10:00', 'repeat' => ['interval' => 1, 'unit' => 'day'], 'name' => 'Legacy one']);
ok($st === 200 && $j['parent_id'] === null, 'API PATCH with the new fields on 2.6.207: accepted, new fields ignored');
foreach (['checklist', 'comments', 'subtasks', 'attachments'] as $subres) {
    [$st] = api('GET', "tasks/$legacy1/$subres", $ADM);
    ok($st === 409, "API GET /tasks/{id}/$subres answers 409 on 2.6.207");
}
$out = sub('echo json_encode([projectTaskPhase2Ready($mysqli), projectTaskPhase3Ready($mysqli), projectTaskProcessReminders($mysqli, function () { throw new Exception("notified"); }), projectTaskChecklist($mysqli, 1), projectTaskComments($mysqli, 1), projectTaskCompleted($mysqli, 1, 1), array_values(projectTaskCounts($mysqli, [1]))[0]["chk_total"]]);');
ok($out === '[false,false,0,[],[],0,0]', 'helpers are no-ops on 2.6.207 (readiness false, no reminders, empty lists)', $out);
$out = sub('echo projectTaskDelete($mysqli, ' . $bTask . ') ? "deleted" : "no"; ');
ok($out === 'deleted', 'projectTaskDelete works on 2.6.207 (no missing-table errors)', $out);
$db->query("INSERT INTO tasks SET task_name = 'B task', task_project_id = $projB");
$bTask = $db->insert_id;

// ================================================================ 2. migration
section('Migration 2.6.207 -> 2.6.209');
$db->query("UPDATE tasks SET task_assigned_to = $techId WHERE task_id = $legacy1");
$update = function () use ($ROOT): string {
    return (string) shell_exec('cd ' . escapeshellarg($ROOT . '/scripts') . ' && php update_cli.php --update_db 2>&1');
};
$out = $update();
ok(preg_match('/from version 2\.6\.207 to 2\.6\.209/', $out) === 1, 'Update Database applies 2.6.208 and 2.6.209', trim(substr($out, -200)));
ok(q1($db, "SELECT config_current_database_version FROM settings WHERE company_id = 1") === '2.6.209' && LATEST_DATABASE_VERSION === '2.6.209', 'settings version and LATEST_DATABASE_VERSION are 2.6.209');
$show = function (mysqli $c, string $t): string {
    $r = $c->query("SHOW CREATE TABLE `$t`")->fetch_row()[1];
    return preg_replace('/ AUTO_INCREMENT=\d+/', '', $r);
};
$freshDb = $database . '_fresh';
$rootRun = function (string $sql, ?string $file = null) use ($ROOTCMD): string {
    $cmd = escapeshellarg($ROOTCMD) . ($file ? ' ' . escapeshellarg($sql) . ' < ' . escapeshellarg($file) : ' -e ' . escapeshellarg($sql)) . ' 2>&1';
    return (string) shell_exec($cmd);
};
$rootRun("DROP DATABASE IF EXISTS `$freshDb`; CREATE DATABASE `$freshDb` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;");
$rootRun($freshDb, $ROOT . '/db.sql');
$freshShow = function (string $t) use ($rootRun, $freshDb): string {
    $o = $rootRun("SHOW CREATE TABLE `$freshDb`.`$t`\\G");
    $o = preg_replace('/^.*?Create Table: /s', '', $o);
    $o = preg_replace('/\n?\s*$/', '', $o);
    return preg_replace('/ AUTO_INCREMENT=\d+/', '', $o);
};
$P23TABLES = ['tasks', 'task_assignees', 'task_attachments', 'task_checklist_items', 'task_comments', 'task_labels', 'task_label_links'];
foreach ($P23TABLES as $t) {
    $a = $show($db, $t);
    $b = $freshShow($t);
    ok($a !== '' && trim($a) === trim($b), "SHOW CREATE TABLE $t: migrated == fresh install of db.sql", $a === '' ? 'missing' : substr($b, 0, 120));
}
$rootRun("DROP DATABASE IF EXISTS `$freshDb`;");
ok((int) q1($db, "SELECT COUNT(*) FROM task_assignees WHERE task_id = $legacy1 AND user_id = $techId") === 1 && (int) q1($db, "SELECT COUNT(*) FROM task_assignees") === 1, 'the existing primary assignee was back-filled into task_assignees (and nothing else)');
ok((int) q1($db, "SELECT COUNT(*) FROM tasks WHERE task_repeat_interval <> 0 OR task_parent_id IS NOT NULL OR task_reminder_at IS NOT NULL") === 0, 'no back-fill of the other new columns: existing tasks are unchanged');
$out2 = $update();
ok(!str_contains($out2, 'Database updated'), 'rerun at 2.6.209 is a no-op');
// the blocks themselves are idempotent: run both again over live data
$db->query("INSERT INTO task_checklist_items SET item_task_id = $legacy1, item_text = 'keep', item_order = 1");
$db->query("INSERT INTO task_comments SET comment_task_id = $legacy1, comment_user_id = $adminId, comment_body = 'keep'");
$db->query("INSERT INTO task_assignees SET task_id = $legacy1, user_id = $adminId");
$db->query("UPDATE tasks SET task_parent_id = $legacy1 WHERE task_id = $legacy2");
$structBefore = array_map(fn($t) => $show($db, $t), $P23TABLES);
$db->query("UPDATE settings SET config_current_database_version = '2.6.207' WHERE company_id = 1");
$update();
ok(q1($db, "SELECT config_current_database_version FROM settings WHERE company_id = 1") === '2.6.209', 'the 2.6.208 / 2.6.209 blocks can run again (idempotent)');
ok($structBefore === array_map(fn($t) => $show($db, $t), $P23TABLES), 'structure identical after the second run');
ok((int) q1($db, "SELECT COUNT(*) FROM task_checklist_items") === 1 && (int) q1($db, "SELECT COUNT(*) FROM task_comments") === 1 && (int) q1($db, "SELECT COUNT(*) FROM task_assignees WHERE task_id = $legacy1") === 2 && (int) q1($db, "SELECT task_parent_id FROM tasks WHERE task_id = $legacy2") === $legacy1, 'data survives the second run (checklist, comment, both assignees, parent link)');
// foreign keys
$db->query("DELETE FROM tasks WHERE task_id = $legacy1");
ok((int) q1($db, "SELECT COUNT(*) FROM task_checklist_items") === 0 && (int) q1($db, "SELECT COUNT(*) FROM task_comments") === 0 && (int) q1($db, "SELECT COUNT(*) FROM task_assignees") === 0 && q1($db, "SELECT task_parent_id FROM tasks WHERE task_id = $legacy2") === null, 'FK: deleting a task row cascades checklist/comments/assignees and releases its subtasks (SET NULL), never deletes them');
$db->query("INSERT INTO tasks SET task_name = 'Legacy one', task_project_id = $projA, task_assigned_to = $techId, task_due = '2026-03-01'");
$legacy1 = $db->insert_id;
$db->query("INSERT INTO task_assignees SET task_id = $legacy1, user_id = $techId");

// ================================================================ 3. checklist
section('Checklist');
$csrf = csrfOf($BASE, $adminJar, $projA);
[$techJar] = webLogin($BASE, 'pt23.tech@pt23.test', 'Pt23User#2026-test');
$techCsrf = csrfOf($BASE, $techJar, $projA);
[$readerJar] = webLogin($BASE, 'pt23.reader@pt23.test', 'Pt23User#2026-test');
$readerCsrf = csrfOf($BASE, $readerJar, $projA);
[$leadJar] = webLogin($BASE, 'pt23.lead@pt23.test', 'Pt23User#2026-test');
$leadCsrf = csrfOf($BASE, $leadJar, $projA);
[$tech2Jar] = webLogin($BASE, 'pt23.tech.two@pt23.test', 'Pt23User#2026-test');
$tech2Csrf = csrfOf($BASE, $tech2Jar, $projB);
ok(strlen($techCsrf) > 10 && strlen($readerCsrf) > 10 && strlen($leadCsrf) > 10 && strlen($tech2Csrf) > 10, 'technician, reader, lead and department-limited technician can log in and open a Kanban');

[$st, $h] = modal($BASE, $adminJar, "task_detail.php?id=$legacy1");
ok($st === 200 && noPhpErrors($h) && str_contains($h, 'id="task-panels"') && str_contains($h, 'data-pt-panel="checklist"') && str_contains($h, 'data-pt-panel="subtasks"') && str_contains($h, 'data-pt-panel="comments"') && str_contains($h, 'data-pt-panel="files"') && str_contains($h, 'project_task_panel.js'), 'pop-up on 2.6.209 shows the four panels');
ok(str_contains($h, 'name="assignee_ids[]"') && str_contains($h, 'name="reminder_at"') && str_contains($h, 'name="repeat_interval"') && str_contains($h, 'name="repeat_unit"'), 'pop-up form has the assignee list, reminder and repeat fields');
[$st, $h] = modal($BASE, $adminJar, "task_add.php?project_id=$projA");
ok($st === 200 && noPhpErrors($h) && str_contains($h, 'name="assignee_ids[]"') && str_contains($h, 'name="repeat_interval"') && !str_contains($h, 'id="task-panels"'), 'New Task pop-up has the new fields but no panels (the task does not exist yet)');

$t1 = $legacy1;
[$st, $j] = ptAjax($BASE, $adminJar, $csrf, ['action' => 'checklist_add', 'task_id' => $t1, 'text' => 'First <b>item</b> & <script>alert(1)</script>']);
ok($st === 200 && $j['ok'] === true && str_contains(panelHtml($j, 'checklist'), 'First item &amp; alert(1)') && !str_contains(panelHtml($j, 'checklist'), '<script>'), 'add: tags stripped, text escaped in the panel');
ptAjax($BASE, $adminJar, $csrf, ['action' => 'checklist_add', 'task_id' => $t1, 'text' => 'Second']);
[$st, $j] = ptAjax($BASE, $adminJar, $csrf, ['action' => 'checklist_add', 'task_id' => $t1, 'text' => 'Third']);
$items = q($db, "SELECT item_id, item_text, item_done, item_order FROM task_checklist_items WHERE item_task_id = $t1 ORDER BY item_order");
ok(count($items) === 3 && $items[0]['item_order'] < $items[1]['item_order'] && $items[1]['item_order'] < $items[2]['item_order'], 'three items, in order');
[$i1, $i2, $i3] = array_map(fn($r) => (int) $r['item_id'], $items);
[$st, $j] = ptAjax($BASE, $adminJar, $csrf, ['action' => 'checklist_add', 'task_id' => $t1, 'text' => '   ']);
ok($st === 200 && $j['ok'] === false && (int) q1($db, "SELECT COUNT(*) FROM task_checklist_items WHERE item_task_id = $t1") === 3, 'add: an empty item is refused');
[$st, $j] = ptAjax($BASE, $adminJar, $csrf, ['action' => 'checklist_toggle', 'task_id' => $t1, 'item_id' => $i1, 'done' => 1]);
ok($j['ok'] === true && (int) q1($db, "SELECT item_done FROM task_checklist_items WHERE item_id = $i1") === 1 && q1($db, "SELECT item_done_at FROM task_checklist_items WHERE item_id = $i1") !== null && str_contains(panelHtml($j, 'checklist'), '1/3'), 'toggle: done, with the 1/3 progress in the panel');
ptAjax($BASE, $adminJar, $csrf, ['action' => 'checklist_toggle', 'task_id' => $t1, 'item_id' => $i1, 'done' => 0]);
ok((int) q1($db, "SELECT item_done FROM task_checklist_items WHERE item_id = $i1") === 0 && q1($db, "SELECT item_done_at FROM task_checklist_items WHERE item_id = $i1") === null, 'toggle: un-done clears the done time');
ptAjax($BASE, $adminJar, $csrf, ['action' => 'checklist_toggle', 'task_id' => $t1, 'item_id' => $i1, 'done' => 1]);
[$st, $j] = ptAjax($BASE, $adminJar, $csrf, ['action' => 'checklist_edit', 'task_id' => $t1, 'item_id' => $i2, 'text' => 'Second (edited)']);
ok($j['ok'] === true && q1($db, "SELECT item_text FROM task_checklist_items WHERE item_id = $i2") === 'Second (edited)', 'edit text');
[$st, $j] = ptAjax($BASE, $adminJar, $csrf, ['action' => 'checklist_edit', 'task_id' => $t1, 'item_id' => $i2, 'text' => '  ']);
ok($j['ok'] === false && q1($db, "SELECT item_text FROM task_checklist_items WHERE item_id = $i2") === 'Second (edited)', 'edit to empty is refused');
ptAjax($BASE, $adminJar, $csrf, ['action' => 'checklist_up', 'task_id' => $t1, 'item_id' => $i3]);
$order = array_map('intval', array_column(q($db, "SELECT item_id FROM task_checklist_items WHERE item_task_id = $t1 ORDER BY item_order, item_id"), 'item_id'));
ok($order === [$i1, $i3, $i2], 'reorder: move up swaps with the previous item', json_encode($order));
ptAjax($BASE, $adminJar, $csrf, ['action' => 'checklist_up', 'task_id' => $t1, 'item_id' => $i1]);
ptAjax($BASE, $adminJar, $csrf, ['action' => 'checklist_down', 'task_id' => $t1, 'item_id' => $i2]);
$order2 = array_map('intval', array_column(q($db, "SELECT item_id FROM task_checklist_items WHERE item_task_id = $t1 ORDER BY item_order, item_id"), 'item_id'));
ok($order2 === [$i1, $i3, $i2], 'reorder: up on the first and down on the last do nothing');
ptAjax($BASE, $adminJar, $csrf, ['action' => 'checklist_down', 'task_id' => $t1, 'item_id' => $i1]);
ok(array_map('intval', array_column(q($db, "SELECT item_id FROM task_checklist_items WHERE item_task_id = $t1 ORDER BY item_order, item_id"), 'item_id')) === [$i3, $i1, $i2], 'reorder: move down');
// an item of another task cannot be reached through this task
$t2 = $legacy2;
[$st, $j] = ptAjax($BASE, $adminJar, $csrf, ['action' => 'checklist_delete', 'task_id' => $t2, 'item_id' => $i3]);
ok($j['ok'] === false && (int) q1($db, "SELECT COUNT(*) FROM task_checklist_items WHERE item_id = $i3") === 1, 'an item id of another task cannot be deleted through this task');
[$st, $j] = ptAjax($BASE, $adminJar, $csrf, ['action' => 'checklist_toggle', 'task_id' => $t2, 'item_id' => $i3, 'done' => 1]);
ok((int) q1($db, "SELECT item_done FROM task_checklist_items WHERE item_id = $i3") === 0, 'nor toggled');
// card count
[$st, $kb] = http('GET', "$BASE/agent/project_kanban.php?project_id=$projA", [], null, $adminJar);
ok(str_contains(cardHtml($kb, $t1), '1/3') && str_contains(cardHtml($kb, $t1), 'Checklist: 1 of 3 done'), 'Kanban card shows the checklist progress 1/3', substr(cardHtml($kb, $t1), 0, 100));
[$st, $j] = ptAjax($BASE, $adminJar, $csrf, ['action' => 'checklist_delete', 'task_id' => $t1, 'item_id' => $i2]);
ok($j['ok'] === true && (int) q1($db, "SELECT COUNT(*) FROM task_checklist_items WHERE item_id = $i2") === 0, 'delete an item');
// hostile text written straight to the table is escaped by the panel
$db->query("INSERT INTO task_checklist_items SET item_task_id = $t1, item_text = '" . $db->real_escape_string('<img src=x onerror=alert(2)>"><svg onload=alert(3)>') . "', item_order = 9");
[$st, $h] = modal($BASE, $adminJar, "task_detail.php?id=$t1");
ok(!str_contains($h, '<img src=x') && !str_contains($h, '<svg onload') && str_contains($h, '&lt;img src=x'), 'a hostile checklist item written to the database is escaped in the pop-up');
$db->query("DELETE FROM task_checklist_items WHERE item_text LIKE '<img%'");

section('Panel endpoint: CSRF, permissions, departments');
[$st, $j] = ptAjax($BASE, $adminJar, 'wrong', ['action' => 'checklist_add', 'task_id' => $t1, 'text' => 'csrf']);
ok($st === 403 && (int) q1($db, "SELECT COUNT(*) FROM task_checklist_items WHERE item_text = 'csrf'") === 0, 'a wrong CSRF token is refused (403)');
[$st, , ] = http('POST', "$BASE/agent/project_task_ajax.php", ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query(['action' => 'checklist_add', 'task_id' => $t1, 'text' => 'csrf']), $adminJar);
ok($st === 403 && (int) q1($db, "SELECT COUNT(*) FROM task_checklist_items WHERE item_text = 'csrf'") === 0, 'a missing CSRF token is refused (403)');
[$st] = http('GET', "$BASE/agent/project_task_ajax.php?action=checklist_add&task_id=$t1&text=get&csrf_token=" . urlencode($csrf), [], null, $adminJar);
ok($st === 405 && (int) q1($db, "SELECT COUNT(*) FROM task_checklist_items WHERE item_text = 'get'") === 0, 'GET is refused (405)');
[$st, $j] = ptAjax($BASE, $readerJar, $readerCsrf, ['action' => 'checklist_add', 'task_id' => $t1, 'text' => 'reader']);
ok($st === 403 && (int) q1($db, "SELECT COUNT(*) FROM task_checklist_items WHERE item_text = 'reader'") === 0, 'a read-only role (level 1) cannot change a checklist');
[$st, $h] = modal($BASE, $readerJar, "task_detail.php?id=$t1");
ok($st === 200 && str_contains($h, 'data-pt-panel="checklist"') && !str_contains($h, 'data-pt-act="checklist_add"') && !str_contains($h, 'data-pt-act="checklist_delete"'), 'a read-only role sees the panels without any edit control');
[$st, $j] = ptAjax($BASE, $tech2Jar, $tech2Csrf, ['action' => 'checklist_add', 'task_id' => $t1, 'text' => 'dept']);
ok($st === 403 && (int) q1($db, "SELECT COUNT(*) FROM task_checklist_items WHERE item_text = 'dept'") === 0, 'department scope: a technician limited to Client B cannot touch a Client A task');
[$st, $j] = ptAjax($BASE, $adminJar, $csrf, ['action' => 'checklist_add', 'task_id' => 99999999, 'text' => 'x']);
ok($st === 404, 'a missing task answers 404');
[$st, $j] = ptAjax($BASE, $adminJar, $csrf, ['action' => 'nope', 'task_id' => $t1]);
ok($st === 400, 'an unknown action answers 400');
$db->query("INSERT INTO tasks SET task_name = 'Ticket-ish', task_ticket_id = 1, task_project_id = $projA");
$ticketTask = $db->insert_id;
[$st, $j] = ptAjax($BASE, $adminJar, $csrf, ['action' => 'checklist_add', 'task_id' => $ticketTask, 'text' => 'x']);
ok($st === 400, 'a ticket checklist item (not a project task) is refused');
$db->query("DELETE FROM tasks WHERE task_id = $ticketTask");
$curl = http('POST', "$BASE/agent/project_task_ajax.php", ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query(['action' => 'checklist_add', 'task_id' => $t1, 'text' => 'anon', 'csrf_token' => 'x']));
ok($curl[0] !== 200 || !str_contains($curl[1], '"ok":true'), 'no session: the endpoint does not do the work');
ok((int) q1($db, "SELECT COUNT(*) FROM logs WHERE log_type = 'Department' AND log_action = 'Access'") >= 1, 'a department denial is audit-logged');

// ================================================================ 4. subtasks
section('Subtasks');
[$st, $j] = ptAjax($BASE, $adminJar, $csrf, ['action' => 'subtask_add', 'task_id' => $t1, 'name' => 'Sub A <script>x</script>']);
$sub1 = (int) q1($db, "SELECT task_id FROM tasks WHERE task_parent_id = $t1 AND task_name LIKE 'Sub A%'");
ok($j['ok'] === true && $sub1 > 0 && (int) q1($db, "SELECT task_project_id FROM tasks WHERE task_id = $sub1") === $projA && q1($db, "SELECT task_name FROM tasks WHERE task_id = $sub1") === 'Sub A x' && str_contains(panelHtml($j, 'subtasks'), 'Sub A x'), 'add subtask: same project, tags stripped, listed in the panel');
ptAjax($BASE, $adminJar, $csrf, ['action' => 'subtask_add', 'task_id' => $t1, 'name' => 'Sub B']);
$sub2 = (int) q1($db, "SELECT task_id FROM tasks WHERE task_parent_id = $t1 AND task_name = 'Sub B'");
[$st, $j] = ptAjax($BASE, $adminJar, $csrf, ['action' => 'subtask_add', 'task_id' => $sub1, 'name' => 'Grandchild']);
ok($j['ok'] === false && (int) q1($db, "SELECT COUNT(*) FROM tasks WHERE task_name = 'Grandchild'") === 0, 'a subtask cannot have subtasks of its own (one level)');
[$st, $j] = ptAjax($BASE, $adminJar, $csrf, ['action' => 'subtask_toggle', 'task_id' => $t1, 'subtask_id' => $sub1, 'done' => 1]);
ok($j['ok'] === true && q1($db, "SELECT task_completed_at FROM tasks WHERE task_id = $sub1") !== null && (int) q1($db, "SELECT task_progress FROM tasks WHERE task_id = $sub1") === 100 && q1($db, "SELECT task_status FROM tasks WHERE task_id = $sub1") === 'Done' && str_contains(panelHtml($j, 'subtasks'), '1/2'), 'complete a subtask: completed like a lane move to Done; the panel says 1/2');
ptAjax($BASE, $adminJar, $csrf, ['action' => 'subtask_toggle', 'task_id' => $t1, 'subtask_id' => $sub1, 'done' => 0]);
ok(q1($db, "SELECT task_completed_at FROM tasks WHERE task_id = $sub1") === null, 'reopen a subtask');
ptAjax($BASE, $adminJar, $csrf, ['action' => 'subtask_toggle', 'task_id' => $t1, 'subtask_id' => $sub1, 'done' => 1]);
[$st, $j] = ptAjax($BASE, $adminJar, $csrf, ['action' => 'subtask_toggle', 'task_id' => $t2, 'subtask_id' => $sub1, 'done' => 0]);
ok($st === 404 && q1($db, "SELECT task_completed_at FROM tasks WHERE task_id = $sub1") !== null, 'a subtask id is only reachable through its own parent');
[$st, $kb] = http('GET', "$BASE/agent/project_kanban.php?project_id=$projA", [], null, $adminJar);
ok(str_contains(cardHtml($kb, $t1), '1/2') && str_contains(cardHtml($kb, $t1), 'Subtasks: 1 of 2 done') && cardHtml($kb, $sub1) === '' && cardHtml($kb, $sub2) === '', 'Kanban: the parent card shows 1/2 subtasks; subtasks are not cards of their own');
[$st, $h] = modal($BASE, $adminJar, "task_detail.php?id=$sub1");
ok(str_contains($h, 'This is a subtask of') && str_contains($h, 'Legacy one') && !str_contains($h, 'data-pt-input="subtask_add"'), 'a subtask\'s pop-up names its parent and offers no subtask box');
// rule set (in process)
$setp = fn(int $a, ?int $b) => projectTaskSetParent($mysqli, $a, $b);
ok(str_contains($setp($t1, $t1), 'own parent'), 'rule: a task is not its own parent');
ok($setp($t1, $sub1) !== '' && q1($db, "SELECT task_parent_id FROM tasks WHERE task_id = $t1") === null, 'rule: no cycle (parent made a child of its own child) and no deeper nesting');
$db->query("INSERT INTO tasks SET task_name = 'In B', task_project_id = $projB");
$inB = $db->insert_id;
ok(str_contains($setp($inB, $t1), 'same project') && str_contains($setp($t1, $inB), 'same project'), 'rule: parent and child must be in the same project');
ok($setp($sub2, $sub1) !== '', 'rule: a subtask cannot be the parent of another');
$db->query("INSERT INTO tasks SET task_name = 'Loose', task_project_id = $projA");
$loose = $db->insert_id;
ok($setp($t1, $loose) !== '' && $setp($loose, $t1) === '' && (int) q1($db, "SELECT task_parent_id FROM tasks WHERE task_id = $loose") === $t1, 'rule: a task that has subtasks cannot become one; a loose task can');
ok($setp($loose, null) === '' && q1($db, "SELECT task_parent_id FROM tasks WHERE task_id = $loose") === null, 'detach with null');
ok($setp($loose, 99999999) !== '', 'rule: an unknown parent is refused');
$db->query("INSERT INTO tasks SET task_name = 'Ticket one', task_ticket_id = 5, task_project_id = $projA");
$tk = $db->insert_id;
ok($setp($tk, $t1) !== '' && $setp($loose, $tk) !== '', 'rule: ticket checklist items cannot be subtasks or parents');
$db->query("DELETE FROM tasks WHERE task_id IN ($tk, $inB, $loose)");
[$st, $j] = ptAjax($BASE, $adminJar, $csrf, ['action' => 'subtask_detach', 'task_id' => $t1, 'subtask_id' => $sub2]);
ok($j['ok'] === true && q1($db, "SELECT task_parent_id FROM tasks WHERE task_id = $sub2") === null && (int) q1($db, "SELECT COUNT(*) FROM tasks WHERE task_id = $sub2") === 1, 'detach: the subtask becomes a regular task, not deleted');
ptAjax($BASE, $adminJar, $csrf, ['action' => 'subtask_add', 'task_id' => $t1, 'name' => 'Sub C']);
$sub3 = (int) q1($db, "SELECT task_id FROM tasks WHERE task_parent_id = $t1 AND task_name = 'Sub C'");
[$st, $j] = ptAjax($BASE, $adminJar, $csrf, ['action' => 'subtask_detach_self', 'task_id' => $sub3]);
ok($j['ok'] === true && q1($db, "SELECT task_parent_id FROM tasks WHERE task_id = $sub3") === null, 'a subtask can detach itself from its pop-up');
ptAjax($BASE, $adminJar, $csrf, ['action' => 'subtask_add', 'task_id' => $t1, 'name' => 'Sub D']);
$sub4 = (int) q1($db, "SELECT task_id FROM tasks WHERE task_parent_id = $t1 AND task_name = 'Sub D'");
// move
[$st] = webPost($BASE, $adminJar, $projA, ['csrf_token' => $csrf, 'move_project_task' => 1, 'task_id' => $sub4, 'target_project_id' => $projA2]);
ok((int) q1($db, "SELECT task_project_id FROM tasks WHERE task_id = $sub4") === $projA, 'a subtask cannot be moved on its own');
webPost($BASE, $adminJar, $projA, ['csrf_token' => $csrf, 'move_project_task' => 1, 'task_id' => $t1, 'target_project_id' => $projA2]);
ok((int) q1($db, "SELECT task_project_id FROM tasks WHERE task_id = $t1") === $projA2 && (int) q1($db, "SELECT task_project_id FROM tasks WHERE task_id = $sub1") === $projA2 && (int) q1($db, "SELECT task_project_id FROM tasks WHERE task_id = $sub4") === $projA2 && (int) q1($db, "SELECT task_parent_id FROM tasks WHERE task_id = $sub1") === $t1, 'moving the parent moves its subtasks with it (still subtasks, same project)');
webPost($BASE, $adminJar, $projA2, ['csrf_token' => $csrf, 'move_project_task' => 1, 'task_id' => $t1, 'target_project_id' => $projA]);
ok((int) q1($db, "SELECT task_project_id FROM tasks WHERE task_id = $sub1") === $projA, 'and back again');
// duplicate: copies checklist (unticked) and subtasks
$db->query("INSERT INTO task_checklist_items SET item_task_id = $t1, item_text = 'dup-item-done', item_done = 1, item_done_at = NOW(), item_order = 20");
[$st] = webPost($BASE, $adminJar, $projA, ['csrf_token' => $csrf, 'duplicate_project_task' => 1, 'task_id' => $t1]);
$dup = (int) q1($db, "SELECT task_id FROM tasks WHERE task_name = 'Legacy one (copy)' AND task_parent_id IS NULL");
ok($dup > 0, 'duplicate of a task with checklist and subtasks works');
$dupItems = q($db, "SELECT item_text, item_done FROM task_checklist_items WHERE item_task_id = $dup ORDER BY item_order, item_id");
$srcItems = q($db, "SELECT item_text FROM task_checklist_items WHERE item_task_id = $t1 ORDER BY item_order, item_id");
ok(count($dupItems) === count($srcItems) && count($dupItems) >= 2 && !array_filter($dupItems, fn($r) => $r['item_done']) && array_column($dupItems, 'item_text') === array_column($srcItems, 'item_text'), 'duplicate copies the checklist, every item unticked, in order');
$dupSubs = q($db, "SELECT task_name, task_completed_at, task_project_id FROM tasks WHERE task_parent_id = $dup ORDER BY task_order, task_id");
$srcSubs = (int) q1($db, "SELECT COUNT(*) FROM tasks WHERE task_parent_id = $t1");
ok(count($dupSubs) === $srcSubs && $srcSubs >= 2 && !array_filter($dupSubs, fn($r) => $r['task_completed_at'] !== null) && !str_contains(implode('|', array_column($dupSubs, 'task_name')), '(copy)'), 'duplicate copies the subtasks as open subtasks of the copy (no "(copy)" suffix on them)');
// delete the copy: its subtasks go? no - released
webPost($BASE, $adminJar, $projA, ['csrf_token' => $csrf, 'delete_project_task' => 1, 'task_id' => $dup]);
ok((int) q1($db, "SELECT COUNT(*) FROM tasks WHERE task_id = $dup") === 0 && (int) q1($db, "SELECT COUNT(*) FROM task_checklist_items WHERE item_task_id = $dup") === 0 && (int) q1($db, "SELECT COUNT(*) FROM tasks WHERE task_name = 'Sub A x' AND task_parent_id IS NULL") >= 1, 'deleting a parent: its checklist goes; its subtasks stay as ordinary tasks (not deleted)');
$db->query("DELETE FROM tasks WHERE task_parent_id IS NULL AND task_name IN ('Sub A x', 'Sub B', 'Sub C', 'Sub D') AND task_id NOT IN ($sub1, $sub2, $sub3, $sub4)");
// delete parent via the real handler keeps children
ptAjax($BASE, $adminJar, $csrf, ['action' => 'subtask_add', 'task_id' => $t2, 'name' => 'Orphan-to-be']);
$orph = (int) q1($db, "SELECT task_id FROM tasks WHERE task_parent_id = $t2");
webPost($BASE, $adminJar, $projA, ['csrf_token' => $csrf, 'delete_project_task' => 1, 'task_id' => $t2]);
ok((int) q1($db, "SELECT COUNT(*) FROM tasks WHERE task_id = $orph") === 1 && q1($db, "SELECT task_parent_id FROM tasks WHERE task_id = $orph") === null, 'delete handler: the subtask survives its parent as a top-level task');
$db->query("INSERT INTO tasks SET task_name = 'Legacy two', task_project_id = $projA");
$t2 = $db->insert_id;

// ================================================================ 5. comments
section('Comments');
[$st, $j] = ptAjax($BASE, $techJar, $techCsrf, ['action' => 'comment_add', 'task_id' => $t1, 'body' => "Hello <b>team</b>\nline two <script>alert(1)</script>"]);
$cm1 = (int) q1($db, "SELECT comment_id FROM task_comments WHERE comment_task_id = $t1 ORDER BY comment_id LIMIT 1");
ok($j['ok'] === true && $cm1 > 0 && (int) q1($db, "SELECT comment_user_id FROM task_comments WHERE comment_id = $cm1") === $techId, 'a technician adds a comment (stored with their id)');
$ph = panelHtml($j, 'comments');
ok(str_contains($ph, 'Pt23 Tech') && preg_match('/\w{3} \d{1,2}, \d{4} \d{1,2}:\d{2} (am|pm)/', $ph) === 1 && str_contains($ph, '<br />') && str_contains($ph, 'alert(1)') && !str_contains($ph, '<script>') && !str_contains($ph, '<b>team'), 'panel shows author and time, keeps the line break, tags stripped');
ptAjax($BASE, $adminJar, $csrf, ['action' => 'comment_add', 'task_id' => $t1, 'body' => 'Admin says hi']);
$cm2 = (int) q1($db, "SELECT comment_id FROM task_comments WHERE comment_task_id = $t1 AND comment_user_id = $adminId");
[$st, $j] = ptAjax($BASE, $techJar, $techCsrf, ['action' => 'comment_edit', 'task_id' => $t1, 'comment_id' => $cm1, 'body' => 'Edited by me']);
ok($j['ok'] === true && q1($db, "SELECT comment_body FROM task_comments WHERE comment_id = $cm1") === 'Edited by me' && q1($db, "SELECT comment_updated_at FROM task_comments WHERE comment_id = $cm1") !== null && str_contains(panelHtml($j, 'comments'), '(edited)'), 'edit own comment: body changes and it is marked edited');
[$st, $j] = ptAjax($BASE, $techJar, $techCsrf, ['action' => 'comment_edit', 'task_id' => $t1, 'comment_id' => $cm2, 'body' => 'Hijacked']);
ok($j['ok'] === false && q1($db, "SELECT comment_body FROM task_comments WHERE comment_id = $cm2") === 'Admin says hi', 'cannot edit someone else\'s comment');
[$st, $j] = ptAjax($BASE, $adminJar, $csrf, ['action' => 'comment_edit', 'task_id' => $t1, 'comment_id' => $cm1, 'body' => 'Admin edits tech']);
ok($j['ok'] === false && q1($db, "SELECT comment_body FROM task_comments WHERE comment_id = $cm1") === 'Edited by me', 'even an administrator cannot edit another person\'s words');
[$st, $j] = ptAjax($BASE, $techJar, $techCsrf, ['action' => 'comment_delete', 'task_id' => $t1, 'comment_id' => $cm2]);
ok($j['ok'] === false && (int) q1($db, "SELECT COUNT(*) FROM task_comments WHERE comment_id = $cm2") === 1, 'a technician cannot delete another person\'s comment');
[$st, $j] = ptAjax($BASE, $leadJar, $leadCsrf, ['action' => 'comment_delete', 'task_id' => $t1, 'comment_id' => $cm1]);
ok($j['ok'] === false && (int) q1($db, "SELECT COUNT(*) FROM task_comments WHERE comment_id = $cm1") === 1, 'a non-admin lead (support level 3) cannot delete another person\'s comment either');
[$st, $h] = modal($BASE, $leadJar, "task_detail.php?id=$t1");
ok(!str_contains($h, 'data-pt-act="comment_delete"'), 'the lead is not even offered a delete button on others\' comments');
[$st, $j] = ptAjax($BASE, $adminJar, $csrf, ['action' => 'comment_delete', 'task_id' => $t1, 'comment_id' => $cm1]);
ok($j['ok'] === true && (int) q1($db, "SELECT COUNT(*) FROM task_comments WHERE comment_id = $cm1") === 0, 'an administrator deletes any comment');
[$st, $j] = ptAjax($BASE, $adminJar, $csrf, ['action' => 'comment_delete', 'task_id' => $t2, 'comment_id' => $cm2]);
ok($j['ok'] === false && (int) q1($db, "SELECT COUNT(*) FROM task_comments WHERE comment_id = $cm2") === 1, 'a comment id of another task cannot be deleted through this task');
[$st, $j] = ptAjax($BASE, $adminJar, $csrf, ['action' => 'comment_delete', 'task_id' => $t1, 'comment_id' => $cm2]);
ok($j['ok'] === true, 'an author deletes their own (admin, own comment)');
[$st, $j] = ptAjax($BASE, $adminJar, $csrf, ['action' => 'comment_add', 'task_id' => $t1, 'body' => '   ']);
ok($j['ok'] === false, 'an empty comment is refused');
[$st, $j] = ptAjax($BASE, $readerJar, $readerCsrf, ['action' => 'comment_add', 'task_id' => $t1, 'body' => 'reader comment']);
ok($st === 403, 'a read-only role cannot comment');
// former user + hostile body
$db->query("INSERT INTO task_comments SET comment_task_id = $t1, comment_user_id = 999999, comment_body = '" . $db->real_escape_string('<img src=x onerror=alert(4)>') . "'");
$db->query("INSERT INTO task_comments SET comment_task_id = $t1, comment_user_id = $adminId, comment_body = 'visible'");
[$st, $h] = modal($BASE, $adminJar, "task_detail.php?id=$t1");
ok(str_contains($h, 'Former user') && !str_contains($h, '<img src=x') && str_contains($h, '&lt;img src=x'), 'a comment by a removed user shows "Former user"; a hostile body is escaped');
[$st, $kb] = http('GET', "$BASE/agent/project_kanban.php?project_id=$projA", [], null, $adminJar);
ok(str_contains(cardHtml($kb, $t1), '2 comment(s)'), 'Kanban card shows the comment count');
$db->query("DELETE FROM task_comments WHERE comment_task_id = $t1");

// ================================================================ 6. assignees
section('Several assignees');
$db->query("UPDATE tasks SET task_assigned_to = NULL WHERE task_id = $t2");
[$st] = webPost($BASE, $adminJar, $projA, ['csrf_token' => $csrf, 'edit_project_task' => 1, 'task_id' => $t2, 'name' => 'Legacy two', 'assigned_to' => $adminId, 'assignees_present' => 1, 'assignee_ids' => [$techId, $readerId, $gone, 9999999]]);
$team = array_map('intval', array_column(q($db, "SELECT user_id FROM task_assignees WHERE task_id = $t2 ORDER BY user_id"), 'user_id'));
$expected = [$adminId, $techId, $readerId];
sort($expected);
ok($st === 302 && $team === $expected && (int) q1($db, "SELECT task_assigned_to FROM tasks WHERE task_id = $t2") === $adminId, 'form: primary + extra assignees saved; the primary column is the chosen primary; inactive / unknown ids are dropped', json_encode($team));
$tm = projectTaskAssigneesByTask($mysqli, [$t2])[$t2];
ok($tm[0]['user_id'] === $adminId && $tm[0]['primary'] === true && count($tm) === 3, 'read: the primary comes first');
[$st, $kb] = http('GET', "$BASE/agent/project_kanban.php?project_id=$projA", [], null, $adminJar);
$card = cardHtml($kb, $t2);
ok(preg_match('/data-assignees="' . $adminId . ',/', $card) === 1 && substr_count($card, 'class="task-avatar') >= 3 && str_contains($card, 'Pt23 Tech'), 'Kanban card carries every assignee and shows the avatars');
ok(str_contains($kb, '<option value="' . $techId . '">Pt23 Tech</option>') && str_contains($kb, '<option value="' . $readerId . '">Pt23 Reader</option>') && !str_contains($kb, '<option value="' . $gone . '">'), 'the assignee filter lists everyone on the board (secondary assignees too)');
$js = file_get_contents($ROOT . '/agent/js/project_kanban.js');
ok(str_contains($js, "data-assignees") && str_contains($js, 'list.indexOf(as) === -1'), 'the filter script matches against the whole assignee list');
// legacy single-field paths keep the team in step
[$st, $j] = api('PATCH', "tasks/$t2", $ADM, ['assigned_user_id' => $tech2Id]);
$team = array_map('intval', array_column(q($db, "SELECT user_id FROM task_assignees WHERE task_id = $t2 ORDER BY user_id"), 'user_id'));
$expected = [$tech2Id, $techId, $readerId];
sort($expected);
ok($st === 200 && (int) q1($db, "SELECT task_assigned_to FROM tasks WHERE task_id = $t2") === $tech2Id && $team === $expected, 'API assigned_user_id (older clients) swaps the primary and keeps the rest of the team', json_encode($team));
ok($j['assignees'][0]['id'] === $tech2Id && $j['assignees'][0]['primary'] === true && count($j['assignees']) === 3 && $j['assigned_to'] === 'Pt23 Tech Two', 'API: assignees list (primary first) and the old assigned_to name agree', json_encode($j['assignees']));
[$st, $j] = api('PATCH', "tasks/$t2", $ADM, ['assignee_ids' => [$readerId, $adminId]]);
$prim = (int) q1($db, "SELECT task_assigned_to FROM tasks WHERE task_id = $t2");
ok($st === 200 && count($j['assignees']) === 2 && in_array($prim, [$readerId, $adminId], true) && (int) q1($db, "SELECT COUNT(*) FROM task_assignees WHERE task_id = $t2") === 2, 'API assignee_ids replaces the team; the primary column stays one of them');
[$st] = api('PATCH', "tasks/$t2", $ADM, ['assignee_ids' => [$gone]]);
ok($st === 400, 'API assignee_ids with an inactive user -> 400');
[$st] = api('PATCH', "tasks/$t2", $ADM, ['assignee_ids' => 'x']);
ok($st === 400, 'API assignee_ids must be a list -> 400');
[$st, $j] = api('PATCH', "tasks/$t2", $ADM, ['assigned_user_id' => 0]);
ok($st === 200 && $j['assignees'] === [] && q1($db, "SELECT task_assigned_to FROM tasks WHERE task_id = $t2") === null && (int) q1($db, "SELECT COUNT(*) FROM task_assignees WHERE task_id = $t2") === 0, 'API assigned_user_id 0 (older clients) clears the whole team');
[$st] = webPost($BASE, $adminJar, $projA, ['csrf_token' => $csrf, 'edit_project_task' => 1, 'task_id' => $t2, 'name' => 'Legacy two', 'assigned_to' => 0, 'assignees_present' => 1, 'assignee_ids' => [$techId, $readerId]]);
ok((int) q1($db, "SELECT task_assigned_to FROM tasks WHERE task_id = $t2") === min($techId, $readerId) || (int) q1($db, "SELECT task_assigned_to FROM tasks WHERE task_id = $t2") === $techId, 'no primary chosen but others picked: the first becomes the primary (column and table never disagree)');
$prim = (int) q1($db, "SELECT task_assigned_to FROM tasks WHERE task_id = $t2");
ok((int) q1($db, "SELECT COUNT(*) FROM task_assignees WHERE task_id = $t2 AND user_id = $prim") === 1, 'the primary is always in task_assignees');
// edit by a stale form that does not know the list: team kept
[$st] = webPost($BASE, $adminJar, $projA, ['csrf_token' => $csrf, 'edit_project_task' => 1, 'task_id' => $t2, 'name' => 'Legacy two', 'assigned_to' => $prim]);
ok((int) q1($db, "SELECT COUNT(*) FROM task_assignees WHERE task_id = $t2") === 2, 'an older form (no assignee list) leaves the team untouched');
// create with a team
[$st] = webPost($BASE, $adminJar, $projA, ['csrf_token' => $csrf, 'add_project_task' => 1, 'project_id' => $projA, 'name' => 'Team task', 'assigned_to' => $techId, 'assignees_present' => 1, 'assignee_ids' => [$adminId]]);
$teamTask = (int) q1($db, "SELECT task_id FROM tasks WHERE task_name = 'Team task'");
ok((int) q1($db, "SELECT COUNT(*) FROM task_assignees WHERE task_id = $teamTask") === 2 && (int) q1($db, "SELECT task_assigned_to FROM tasks WHERE task_id = $teamTask") === $techId, 'create with a team');
[$st] = webPost($BASE, $adminJar, $projA, ['csrf_token' => $csrf, 'duplicate_project_task' => 1, 'task_id' => $teamTask]);
$teamCopy = (int) q1($db, "SELECT task_id FROM tasks WHERE task_name = 'Team task (copy)'");
ok((int) q1($db, "SELECT COUNT(*) FROM task_assignees WHERE task_id = $teamCopy") === 2, 'duplicate copies the team');
// a writer that only knows the old column (e.g. the ticket-task automation) is still shown correctly
$db->query("INSERT INTO tasks SET task_name = 'Old writer', task_project_id = $projA, task_assigned_to = $adminId");
$oldw = $db->insert_id;
$tm = projectTaskAssigneesByTask($mysqli, [$oldw])[$oldw] ?? [];
ok(count($tm) === 1 && $tm[0]['user_id'] === $adminId, 'a row written by something that only sets task_assigned_to still shows its assignee');
$db->query("DELETE FROM tasks WHERE task_id IN ($oldw)");

// ================================================================ 7. attachments
section('Attachments');
$tmpd = sys_get_temp_dir() . '/pt23_files_' . getmypid();
@mkdir($tmpd);
file_put_contents("$tmpd/notes.txt", "hello attachment\n");
file_put_contents("$tmpd/shell.php", "<?php echo 'x';");
file_put_contents("$tmpd/pic.php.jpg", "not really a jpg");
file_put_contents("$tmpd/page.html", "<script>alert(1)</script>");
file_put_contents("$tmpd/draw.svg", "<svg onload=alert(1)>");
file_put_contents("$tmpd/empty.txt", "");
file_put_contents("$tmpd/.htaccess", "Options +ExecCGI");
file_put_contents("$tmpd/ok.PDF", "%PDF-1.4 fake");
file_put_contents("$tmpd/travel.txt", "traversal");
$attDir = $ROOT . '/uploads/task_attachments/';
$before = is_dir($attDir) ? count(glob($attDir . '*')) : 0;
[$st, $j] = ptAjax($BASE, $adminJar, $csrf, ['action' => 'file_upload', 'task_id' => $t1], "$tmpd/notes.txt");
$att1 = (int) q1($db, "SELECT attachment_id FROM task_attachments WHERE attachment_task_id = $t1 AND attachment_name = 'notes.txt'");
$ref1 = (string) q1($db, "SELECT attachment_reference FROM task_attachments WHERE attachment_id = $att1");
ok($st === 200 && $j['ok'] === true && $att1 > 0 && preg_match('/^[0-9a-f]{40}$/', $ref1) === 1 && is_file($attDir . $ref1) && file_get_contents($attDir . $ref1) === "hello attachment\n" && str_contains(panelHtml($j, 'files'), 'notes.txt'), 'upload: stored under a random 40-hex name with no extension; listed in the panel', json_encode($j));
ok((int) q1($db, "SELECT attachment_size FROM task_attachments WHERE attachment_id = $att1") === 17 && str_contains((string) q1($db, "SELECT attachment_mime FROM task_attachments WHERE attachment_id = $att1"), 'text'), 'size and detected type recorded');
foreach (['shell.php' => 'php', 'pic.php.jpg' => 'php', 'page.html' => 'html', 'draw.svg' => 'svg', '.htaccess' => 'htaccess'] as $bad => $why) {
    [$st, $j] = ptAjax($BASE, $adminJar, $csrf, ['action' => 'file_upload', 'task_id' => $t1], "$tmpd/$bad");
    ok($j['ok'] === false && str_contains((string) $j['error'], 'not allowed') && (int) q1($db, "SELECT COUNT(*) FROM task_attachments WHERE attachment_name = '" . $db->real_escape_string($bad) . "'") === 0, "dangerous file refused: $bad");
}
[$st, $j] = ptAjax($BASE, $adminJar, $csrf, ['action' => 'file_upload', 'task_id' => $t1], "$tmpd/empty.txt");
ok($j['ok'] === false && str_contains((string) $j['error'], 'empty'), 'an empty file is refused');
[$st, $j] = ptAjax($BASE, $adminJar, $csrf, ['action' => 'file_upload', 'task_id' => $t1], "$tmpd/ok.PDF");
ok($j['ok'] === true, 'an ordinary document (upper-case .PDF) is accepted');
[$st, $j] = ptAjax($BASE, $adminJar, $csrf, ['action' => 'file_upload', 'task_id' => $t1], "$tmpd/travel.txt", '../../../etc/cron.d/evil<b>.txt');
$trav = q($db, "SELECT attachment_name, attachment_reference FROM task_attachments WHERE attachment_task_id = $t1 ORDER BY attachment_id DESC LIMIT 1")[0] ?? [];
ok($j['ok'] === true && $trav['attachment_name'] === 'evil.txt' && preg_match('/^[0-9a-f]{40}$/', $trav['attachment_reference']) === 1 && !file_exists('/etc/cron.d/evil.txt'), 'a path-traversal file name is reduced to its base name; the stored name is never the visitor\'s', json_encode($trav));
$after = count(glob($attDir . '*'));
ok($after === $before + 3, 'only the three accepted files exist on disk', "$before -> $after");
ok(!is_file($attDir . 'notes.txt') && !glob($attDir . '*.*'), 'no file on disk carries a visitor-chosen name or any extension');
// download
[$st, $body, $hd] = http('GET', "$BASE/agent/project_task_attachment.php?task_id=$t1&id=$att1", [], null, $adminJar);
ok($st === 200 && $body === "hello attachment\n" && str_contains($hd['content-type'] ?? '', 'application/octet-stream') && ($hd['x-content-type-options'] ?? '') === 'nosniff' && str_contains($hd['content-disposition'] ?? '', 'attachment; filename="notes.txt"'), 'download: bytes intact, forced download, nosniff', json_encode($hd));
[$st] = http('GET', "$BASE/agent/project_task_attachment.php?task_id=$t2&id=$att1", [], null, $adminJar);
ok($st === 404, 'an attachment id is only valid with its own task id');
[$st] = http('GET', "$BASE/agent/project_task_attachment.php?task_id=$t1&id=$att1", [], null, $tech2Jar);
ok($st === 403, 'department scope: a technician limited to Client B cannot download a Client A task\'s file');
[$st, $body] = http('GET', "$BASE/agent/project_task_attachment.php?task_id=$t1&id=$att1", [], null, $readerJar);
ok($st === 200 && $body === "hello attachment\n", 'a read-only role may download');
[$st, $body] = http('GET', "$BASE/agent/project_task_attachment.php?task_id=$t1&id=$att1");
ok($st !== 200 && !str_contains($body, 'hello attachment'), 'no session: no file');
[$st] = http('GET', "$BASE/agent/project_task_attachment.php?task_id=$t1&id=999999", [], null, $adminJar);
ok($st === 404, 'an unknown attachment is 404');
[$st, $j] = ptAjax($BASE, $readerJar, $readerCsrf, ['action' => 'file_upload', 'task_id' => $t1], "$tmpd/notes.txt");
ok($st === 403, 'a read-only role cannot upload');
[$st, $j] = ptAjax($BASE, $tech2Jar, $tech2Csrf, ['action' => 'file_upload', 'task_id' => $t1], "$tmpd/notes.txt");
ok($st === 403, 'department scope: upload to another department\'s task is refused');
// delete rules
[$st, $j] = ptAjax($BASE, $techJar, $techCsrf, ['action' => 'file_delete', 'task_id' => $t1, 'attachment_id' => $att1]);
ok($st === 403 && is_file($attDir . $ref1), 'a technician cannot delete a file someone else attached');
[$st, $j] = ptAjax($BASE, $techJar, $techCsrf, ['action' => 'file_upload', 'task_id' => $t1], "$tmpd/ok.PDF");
$techAtt = (int) q1($db, "SELECT attachment_id FROM task_attachments WHERE attachment_task_id = $t1 AND attachment_uploaded_by = $techId");
[$st, $j] = ptAjax($BASE, $techJar, $techCsrf, ['action' => 'file_delete', 'task_id' => $t1, 'attachment_id' => $techAtt]);
ok($j['ok'] === true && (int) q1($db, "SELECT COUNT(*) FROM task_attachments WHERE attachment_id = $techAtt") === 0, 'the uploader can delete their own file');
[$st, $kb] = http('GET', "$BASE/agent/project_kanban.php?project_id=$projA", [], null, $adminJar);
ok(str_contains(cardHtml($kb, $t1), '3 file(s)'), 'Kanban card shows the file count');
// hostile attachment name written to the table is escaped
$db->query("UPDATE task_attachments SET attachment_name = '" . $db->real_escape_string('"><img src=x onerror=alert(5)>.txt') . "' WHERE attachment_id = $att1");
[$st, $h] = modal($BASE, $adminJar, "task_detail.php?id=$t1");
ok(!str_contains($h, '<img src=x') && str_contains($h, '&lt;img src=x'), 'a hostile stored file name is escaped in the pop-up');
$db->query("UPDATE task_attachments SET attachment_name = 'notes.txt' WHERE attachment_id = $att1");
// a poisoned reference never reaches the filesystem
$db->query("UPDATE task_attachments SET attachment_reference = '../../../../etc/passwd' WHERE attachment_id = $att1");
[$st, $body] = http('GET', "$BASE/agent/project_task_attachment.php?task_id=$t1&id=$att1", [], null, $adminJar);
ok($st === 404 && !str_contains($body, 'root:'), 'a poisoned reference column is not followed (404)');
$db->query("UPDATE task_attachments SET attachment_reference = '$ref1' WHERE attachment_id = $att1");
// per-task cap
$db->query("DELETE FROM task_attachments WHERE attachment_task_id = $t2");
for ($i = 0; $i < PROJECT_TASK_ATTACHMENT_MAX_FILES; $i++) {
    $db->query("INSERT INTO task_attachments SET attachment_task_id = $t2, attachment_name = 'f$i.txt', attachment_reference = '" . bin2hex(random_bytes(20)) . "', attachment_size = 1");
}
[$st, $j] = ptAjax($BASE, $adminJar, $csrf, ['action' => 'file_upload', 'task_id' => $t2], "$tmpd/notes.txt");
ok($j['ok'] === false && str_contains((string) $j['error'], 'up to ' . PROJECT_TASK_ATTACHMENT_MAX_FILES), 'a task holds at most ' . PROJECT_TASK_ATTACHMENT_MAX_FILES . ' files');
$db->query("DELETE FROM task_attachments WHERE attachment_task_id = $t2");
// deleting a task removes its files from disk
ptAjax($BASE, $adminJar, $csrf, ['action' => 'file_upload', 'task_id' => $t2], "$tmpd/notes.txt");
$ref2 = (string) q1($db, "SELECT attachment_reference FROM task_attachments WHERE attachment_task_id = $t2");
ok(is_file($attDir . $ref2), 'precondition: file on disk for the doomed task');
webPost($BASE, $adminJar, $projA, ['csrf_token' => $csrf, 'delete_project_task' => 1, 'task_id' => $t2]);
clearstatcache();
ok(!is_file($attDir . $ref2) && (int) q1($db, "SELECT COUNT(*) FROM task_attachments WHERE attachment_reference = '$ref2'") === 0, 'deleting a task deletes its attachment rows and files');
$db->query("INSERT INTO tasks SET task_name = 'Legacy two', task_project_id = $projA");
$t2 = $db->insert_id;

// ================================================================ 8. reminders
section('Reminders');
$due = date('Y-m-d', strtotime('+20 days'));
$setRem = function (int $id, array $f) use ($BASE, $adminJar, $csrf, $projA) {
    return webPost($BASE, $adminJar, $projA, ['csrf_token' => $csrf, 'edit_project_task' => 1, 'task_id' => $id, 'name' => (string) q1($GLOBALS['db'], "SELECT task_name FROM tasks WHERE task_id = $id"), 'due' => $f['due'] ?? '', 'reminder_present' => 1] + $f);
};
$rem = fn(int $id) => q1($db, "SELECT task_reminder_at FROM tasks WHERE task_id = $id");
$setRem($t1, ['due' => $due, 'reminder_from_due' => '1d', 'reminder_at' => '']);
ok($rem($t1) === date('Y-m-d', strtotime("$due -1 day")) . ' 09:00:00', 'reminder: "a day before the due date" = 09:00 the day before', (string) $rem($t1));
$setRem($t1, ['due' => $due, 'reminder_from_due' => '0']);
ok($rem($t1) === "$due 09:00:00", 'reminder: on the due date');
$setRem($t1, ['due' => $due, 'reminder_from_due' => '1w']);
ok($rem($t1) === date('Y-m-d', strtotime("$due -7 days")) . ' 09:00:00', 'reminder: a week before');
$setRem($t1, ['due' => $due, 'reminder_at' => '2031-05-06T08:30']);
ok($rem($t1) === '2031-05-06 08:30:00', 'reminder: a custom date-time from the form (datetime-local)');
$setRem($t1, ['due' => $due, 'reminder_at' => 'banana']);
ok($rem($t1) === '2031-05-06 08:30:00', 'reminder: nonsense is ignored, the old time stays');
$setRem($t1, ['due' => '', 'reminder_from_due' => '1d', 'reminder_at' => '2031-05-06T08:30']);
ok($rem($t1) === '2031-05-06 08:30:00', 'reminder: "relative to the due date" with no due date falls back to the typed time');
$setRem($t1, ['due' => $due, 'reminder_at' => '']);
ok($rem($t1) === null, 'reminder: empty clears it');
[$st, $j] = api('PATCH', "tasks/$t1", $ADM, ['reminder_at' => '2031-05-06 08:30']);
ok($st === 200 && $j['reminder_at'] === '2031-05-06 08:30:00', 'API reminder_at');
[$st] = api('PATCH', "tasks/$t1", $ADM, ['reminder_at' => 'tomorrow-ish']);
ok($st === 400, 'API reminder_at nonsense -> 400');
[$st, $j] = api('PATCH', "tasks/$t1", $ADM, ['reminder_at' => null]);
ok($st === 200 && $j['reminder_at'] === null, 'API reminder_at null clears');

// delivery (stub notifier only: no real notification, no push)
$db->query("UPDATE tasks SET task_reminder_at = NULL, task_reminder_sent_at = NULL");
$mkR = function (string $name, array $set = []) use ($db, $projA): int {
    $db->query("INSERT INTO tasks SET task_name = '$name', task_project_id = $projA, task_due = '2030-01-01'");
    $id = $db->insert_id;
    foreach ($set as $sql) {
        $db->query(str_replace('{id}', (string) $id, $sql));
    }
    return $id;
};
$r1 = $mkR('Rem due now', ["UPDATE tasks SET task_reminder_at = NOW() - INTERVAL 1 HOUR WHERE task_id = {id}", "UPDATE tasks SET task_assigned_to = $adminId WHERE task_id = {id}", "INSERT INTO task_assignees SET task_id = {id}, user_id = $adminId", "INSERT INTO task_assignees SET task_id = {id}, user_id = $techId"]);
$r2 = $mkR('Rem completed', ["UPDATE tasks SET task_reminder_at = NOW() - INTERVAL 1 HOUR, task_completed_at = NOW() WHERE task_id = {id}"]);
$r3 = $mkR('Rem future', ["UPDATE tasks SET task_reminder_at = NOW() + INTERVAL 1 DAY WHERE task_id = {id}"]);
$r4 = $mkR('Rem nobody', ["UPDATE tasks SET task_reminder_at = NOW() - INTERVAL 2 HOUR WHERE task_id = {id}"]);
$r5 = $mkR('Rem ticket task', ["UPDATE tasks SET task_reminder_at = NOW() - INTERVAL 2 HOUR, task_ticket_id = 5 WHERE task_id = {id}"]);
$calls = [];
$stub = function (?int $u, string $type, string $details, string $action, int $client, int $task) use (&$calls) { $calls[] = compact('u', 'type', 'details', 'action', 'client', 'task'); };
$n = projectTaskProcessReminders($mysqli, $stub);
$byTask = [];
foreach ($calls as $c) { $byTask[$c['task']][] = $c['u']; }
ok($n === 2 && count($calls) === 3 && isset($byTask[$r1]) && count($byTask[$r1]) === 2 && in_array($adminId, $byTask[$r1], true) && in_array($techId, $byTask[$r1], true) && $byTask[$r4] === [null], 'cron: reminded the open task\'s two people and (no assignee) the administrators; not the completed, future or ticket task', json_encode($byTask));
$c1 = array_values(array_filter($calls, fn($c) => $c['task'] === $r1))[0] ?? [];
ok(($c1['type'] ?? '') === 'Task Reminder' && str_contains($c1['details'], 'Rem due now') && str_contains($c1['details'], 'QA1') && str_contains($c1['details'], 'due 2030-01-01') && str_contains($c1['action'], "project_id=$projA") && str_contains($c1['action'], "client_id=$clientA") && $c1['client'] === $clientA, 'the notification names the task, project and due date and links to the board', json_encode($c1));
ok(q1($db, "SELECT task_reminder_sent_at FROM tasks WHERE task_id = $r1") !== null && q1($db, "SELECT task_reminder_sent_at FROM tasks WHERE task_id = $r2") === null && q1($db, "SELECT task_reminder_sent_at FROM tasks WHERE task_id = $r3") === null, 'delivered tasks are stamped; skipped ones are not');
$calls = [];
ok(projectTaskProcessReminders($mysqli, $stub) === 0 && !$calls, 'a second run sends nothing (fires once)');
// re-arm: changing the time clears the delivered flag; re-saving the same time does not
$setRem($r1, ['due' => '2030-01-01', 'reminder_at' => (new DateTime('-30 minutes'))->format('Y-m-d\TH:i')]);
ok(q1($db, "SELECT task_reminder_sent_at FROM tasks WHERE task_id = $r1") === null, 'changing the reminder time re-arms it');
$calls = [];
ok(projectTaskProcessReminders($mysqli, $stub) === 1 && count($calls) === 2, 'and it fires again, once');
$sentAt = q1($db, "SELECT task_reminder_sent_at FROM tasks WHERE task_id = $r1");
$curRem = (string) q1($db, "SELECT task_reminder_at FROM tasks WHERE task_id = $r1");
$setRem($r1, ['due' => '2030-01-01', 'reminder_at' => date('Y-m-d\TH:i', strtotime($curRem))]);
ok(q1($db, "SELECT task_reminder_sent_at FROM tasks WHERE task_id = $r1") === $sentAt, 'saving the form again with the same time does not re-arm it');
// race: two runs cannot both claim
$db->query("UPDATE tasks SET task_reminder_at = NOW() - INTERVAL 1 HOUR, task_reminder_sent_at = NULL WHERE task_id = $r4");
$calls = [];
$both = projectTaskProcessReminders($mysqli, function ($u, ...$rest) use ($mysqli, &$calls, $stub, $r4) {
    // while the first run is delivering, a second run starts: it must find nothing left to claim
    $calls[] = $u;
    static $inner = false;
    if (!$inner) { $inner = true; $calls['inner'] = projectTaskProcessReminders($mysqli, function () {}); }
});
ok($both === 1 && ($calls['inner'] ?? -1) === 0, 'two overlapping runs: the claim UPDATE lets only one deliver');
ok((int) q1($db, 'SELECT COUNT(*) FROM notifications') === $notifBefore, 'no real notification row was created (stub notifier only)');
exec('php -l ' . escapeshellarg($ROOT . '/cron/task_reminders.php') . ' 2>&1', $o, $rc);
ok($rc === 0 && str_contains(file_get_contents($ROOT . '/cron/cron.php'), "cron/task_reminders.php") && str_contains(file_get_contents($ROOT . '/src/Cron/JobCatalog.php'), "'task_reminders.php'"), 'cron/task_reminders.php parses, is required from cron.php and listed in the Cron page catalog');
$db->query("DELETE FROM tasks WHERE task_id IN ($r1, $r2, $r3, $r4, $r5)");

// ================================================================ 9. repeating tasks
section('Repeating tasks');
ok(projectTaskShiftDate('2026-01-31', 1, 'month') === '2026-02-28' && projectTaskShiftDate('2024-01-31', 1, 'month') === '2024-02-29' && projectTaskShiftDate('2026-02-28', 1, 'month') === '2026-03-28' && projectTaskShiftDate('2024-02-29', 1, 'year') === '2025-02-28' && projectTaskShiftDate('2026-03-10', 2, 'week') === '2026-03-24' && projectTaskShiftDate('2026-12-31', 1, 'day') === '2027-01-01' && projectTaskShiftDate('2026-11-30', 3, 'month') === '2027-02-28' && projectTaskShiftDate('2026-12-15', 2, 'year') === '2028-12-15', 'date arithmetic: month and year clamp to the last day, weeks and days are exact');
ok(projectTaskNextDue('2026-03-01', 1, 'week', '2026-03-01') === '2026-03-08' && projectTaskNextDue('2026-03-01', 1, 'week', '2026-02-20') === '2026-03-08', 'next due: due + interval (on time or early)');
ok(projectTaskNextDue('2026-01-01', 1, 'week', '2026-01-22') === '2026-01-22' && projectTaskNextDue('2026-01-01', 1, 'week', '2026-01-23') === '2026-01-29', 'next due: completed late, it steps on until today or later (never due in the past)');
ok(projectTaskNextDue('2026-01-31', 1, 'month', '2026-03-15') === '2026-03-31', 'next due: catching up from the original date does not drift (31 Jan -> 31 Mar, not 28 Mar)');
ok(projectTaskNextDue(null, 2, 'week', '2026-03-30') === '2026-04-13' && projectTaskNextDue('', 1, 'month', '2026-03-30') === '2026-04-30', 'next due: no due date = completion day + interval');
ok(projectTaskNormalizeRepeat(0, 'week') === [0, 'day'] && projectTaskNormalizeRepeat(5000, 'week') === [999, 'week'] && projectTaskNormalizeRepeat(2, 'fortnight') === [2, 'day'] && projectTaskNormalizeRepeat(-3, 'day') === [0, 'day'], 'repeat values are clamped / defaulted');

$today = date('Y-m-d');
$dueR = date('Y-m-d', strtotime('+3 days'));
$startR = date('Y-m-d', strtotime('+1 day'));
$gl = $db->query("INSERT INTO task_labels SET label_name = 'Rep-L', label_color = '#00aa00'") ? $db->insert_id : 0;
[$st] = webPost($BASE, $adminJar, $projA, ['csrf_token' => $csrf, 'add_project_task' => 1, 'project_id' => $projA, 'name' => 'Weekly report', 'assigned_to' => $adminId, 'assignees_present' => 1, 'assignee_ids' => [$techId], 'due' => $dueR, 'start' => $startR, 'priority' => 3, 'labels_present' => 1, 'label_ids' => [$gl], 'description' => 'Do the report', 'repeat_present' => 1, 'repeat_interval' => 1, 'repeat_unit' => 'week', 'reminder_present' => 1, 'reminder_at' => date('Y-m-d\TH:i', strtotime('+2 days 09:00')), 'milestone_id' => $msA, 'completion_estimate' => 45]);
$rep = (int) q1($db, "SELECT task_id FROM tasks WHERE task_name = 'Weekly report'");
ok($st === 302 && $rep > 0 && (int) q1($db, "SELECT task_repeat_interval FROM tasks WHERE task_id = $rep") === 1 && q1($db, "SELECT task_repeat_unit FROM tasks WHERE task_id = $rep") === 'week', 'create with a weekly repeat');
$db->query("INSERT INTO task_checklist_items SET item_task_id = $rep, item_text = 'Gather numbers', item_done = 1, item_done_at = NOW(), item_order = 1");
$db->query("INSERT INTO task_checklist_items SET item_task_id = $rep, item_text = 'Send it', item_done = 1, item_done_at = NOW(), item_order = 2");
$db->query("INSERT INTO task_comments SET comment_task_id = $rep, comment_user_id = $adminId, comment_body = 'not copied'");
$db->query("INSERT INTO task_attachments SET attachment_task_id = $rep, attachment_name = 'a.txt', attachment_reference = '" . bin2hex(random_bytes(20)) . "', attachment_size = 1");
[$st, $kb] = http('GET', "$BASE/agent/project_kanban.php?project_id=$projA", [], null, $adminJar);
ok(str_contains(cardHtml($kb, $rep), 'Repeats every 1 week') && str_contains(cardHtml($kb, $rep), 'Reminder:'), 'Kanban card shows the repeat and reminder markers');
$cnt = fn() => (int) q1($db, "SELECT COUNT(*) FROM tasks WHERE task_name = 'Weekly report'");
http('GET', "$BASE/agent/post.php?complete_task=$rep&csrf_token=" . urlencode($csrf), ['Referer' => "$BASE/agent/project_kanban.php?project_id=$projA"], null, $adminJar);
$next = q($db, "SELECT * FROM tasks WHERE task_name = 'Weekly report' AND task_id <> $rep")[0] ?? [];
$nextId = (int) ($next['task_id'] ?? 0);
$expDue = projectTaskShiftDate($dueR, 1, 'week');
ok($cnt() === 2 && $nextId > 0 && $next['task_due'] === $expDue && $next['task_start'] === projectTaskShiftDate($startR, (int) round((strtotime($expDue) - strtotime($dueR)) / 86400), 'day'), 'Mark complete spawns the next occurrence with the due and start dates shifted a week', json_encode([$next['task_due'] ?? null, $expDue]));
ok($next['task_status'] === 'To Do' && $next['task_completed_at'] === null && (int) $next['task_progress'] === 0 && (int) $next['task_priority'] === 3 && $next['task_description'] === 'Do the report' && (int) $next['task_milestone_id'] === $msA && (int) $next['task_completion_estimate'] === 45 && (int) $next['task_repeat_interval'] === 1 && $next['task_repeat_unit'] === 'week' && $next['task_repeat_spawned_at'] === null && (int) $next['task_assigned_to'] === $adminId, 'the copy is open, with the same fields and the same repeat rule, and has not spawned yet');
ok((int) q1($db, "SELECT COUNT(*) FROM task_assignees WHERE task_id = $nextId") === 2 && (int) q1($db, "SELECT COUNT(*) FROM task_label_links WHERE task_id = $nextId AND label_id = $gl") === 1, 'the copy has the same team and labels');
$nc = q($db, "SELECT item_text, item_done FROM task_checklist_items WHERE item_task_id = $nextId ORDER BY item_order");
ok(array_column($nc, 'item_text') === ['Gather numbers', 'Send it'] && !array_filter($nc, fn($r) => $r['item_done']), 'the copy\'s checklist is back to unticked');
ok((int) q1($db, "SELECT COUNT(*) FROM task_comments WHERE comment_task_id = $nextId") === 0 && (int) q1($db, "SELECT COUNT(*) FROM task_attachments WHERE attachment_task_id = $nextId") === 0, 'comments and files are not copied');
$expRem = (new DateTime((string) q1($db, "SELECT task_reminder_at FROM tasks WHERE task_id = $rep")))->modify('+7 days')->format('Y-m-d H:i:s');
ok($next['task_reminder_at'] === $expRem && $next['task_reminder_sent_at'] === null, 'the reminder moves with the due date');
ok(q1($db, "SELECT task_repeat_spawned_at FROM tasks WHERE task_id = $rep") !== null, 'the completed task is marked as having repeated');
// undo + complete again: no second copy
http('GET', "$BASE/agent/post.php?undo_complete_task=$rep&csrf_token=" . urlencode($csrf), ['Referer' => "$BASE/agent/project_kanban.php?project_id=$projA"], null, $adminJar);
http('GET', "$BASE/agent/post.php?complete_task=$rep&csrf_token=" . urlencode($csrf), ['Referer' => "$BASE/agent/project_kanban.php?project_id=$projA"], null, $adminJar);
ok($cnt() === 2, 'completing, re-opening and completing again does not repeat twice');
// completing the copy repeats from ITS due date
http('GET', "$BASE/agent/post.php?complete_task=$nextId&csrf_token=" . urlencode($csrf), ['Referer' => "$BASE/agent/project_kanban.php?project_id=$projA"], null, $adminJar);
$third = (string) q1($db, "SELECT task_due FROM tasks WHERE task_name = 'Weekly report' AND task_id NOT IN ($rep, $nextId)");
ok($cnt() === 3 && $third === projectTaskShiftDate($expDue, 1, 'week'), 'the chain continues from each occurrence\'s own due date');
// non repeating: nothing spawned
http('GET', "$BASE/agent/post.php?complete_task=$t1&csrf_token=" . urlencode($csrf), ['Referer' => "$BASE/agent/project_kanban.php?project_id=$projA"], null, $adminJar);
$tasksNow = (int) q1($db, 'SELECT COUNT(*) FROM tasks');
http('GET', "$BASE/agent/post.php?undo_complete_task=$t1&csrf_token=" . urlencode($csrf), ['Referer' => "$BASE/agent/project_kanban.php?project_id=$projA"], null, $adminJar);
ok((int) q1($db, "SELECT COUNT(*) FROM tasks WHERE task_name = 'Legacy one'") === 1, 'a task without a repeat rule spawns nothing');
// other completion paths
$mkRep = function (string $name, string $unit = 'day', int $n = 2, ?string $due = null) use ($db, $projA): int {
    $d = $due ? "'$due'" : 'NULL';
    $db->query("INSERT INTO tasks SET task_name = '$name', task_project_id = $projA, task_due = $d, task_repeat_interval = $n, task_repeat_unit = '$unit'");
    return $db->insert_id;
};
$dragged = $mkRep('Dragged repeat', 'day', 2, $dueR);
http('POST', "$BASE/agent/ajax.php", ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query(['update_project_task_kanban' => 1, 'csrf_token' => $csrf, 'positions' => [['task_id' => $dragged, 'task_order' => 1, 'task_status' => 'Done']]]), $adminJar);
ok((int) q1($db, "SELECT COUNT(*) FROM tasks WHERE task_name = 'Dragged repeat'") === 2 && q1($db, "SELECT task_due FROM tasks WHERE task_name = 'Dragged repeat' AND task_completed_at IS NULL") === projectTaskShiftDate($dueR, 2, 'day'), 'dragging a card to Done spawns the next occurrence');
http('POST', "$BASE/agent/ajax.php", ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query(['update_project_task_kanban' => 1, 'csrf_token' => $csrf, 'positions' => [['task_id' => $dragged, 'task_order' => 2, 'task_status' => 'Done']]]), $adminJar);
ok((int) q1($db, "SELECT COUNT(*) FROM tasks WHERE task_name = 'Dragged repeat'") === 2, 'dragging inside Done again does not spawn another');
$alreadyDone = $mkRep('Already done', 'day', 1, $dueR);
$db->query("UPDATE tasks SET task_completed_at = NOW(), task_status = 'Done' WHERE task_id = $alreadyDone");
http('POST', "$BASE/agent/ajax.php", ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query(['update_project_task_kanban' => 1, 'csrf_token' => $csrf, 'positions' => [['task_id' => $alreadyDone, 'task_order' => 1, 'task_status' => 'Done']]]), $adminJar);
ok((int) q1($db, "SELECT COUNT(*) FROM tasks WHERE task_name = 'Already done'") === 1, 'a task that was already done and merely re-ordered (repeat added later) does not spawn');
$viaForm = $mkRep('Form repeat', 'month', 1, '2026-01-31');
webPost($BASE, $adminJar, $projA, ['csrf_token' => $csrf, 'edit_project_task' => 1, 'task_id' => $viaForm, 'name' => 'Form repeat', 'due' => '2026-01-31', 'status' => 'Done', 'status_orig' => 'To Do', 'repeat_present' => 1, 'repeat_interval' => 1, 'repeat_unit' => 'month']);
$late = q1($db, "SELECT task_due FROM tasks WHERE task_name = 'Form repeat' AND task_completed_at IS NULL");
ok($late === projectTaskNextDue('2026-01-31', 1, 'month', $today) && $late >= $today, 'completing through the pop-up status spawns too; a task completed late gets a due date today or later', (string) $late);
$viaApi = $mkRep('Api repeat', 'week', 1, $dueR);
[$st, $j] = api('POST', "tasks/$viaApi/toggle", $ADM);
ok($st === 200 && (int) q1($db, "SELECT COUNT(*) FROM tasks WHERE task_name = 'Api repeat'") === 2, 'API toggle spawns the next occurrence');
$viaApi2 = $mkRep('Api status repeat', 'week', 1, $dueR);
api('PATCH', "tasks/$viaApi2", $ADM, ['status' => 'Done']);
api('PATCH', "tasks/$viaApi2", $ADM, ['status' => 'Done']);
ok((int) q1($db, "SELECT COUNT(*) FROM tasks WHERE task_name = 'Api status repeat'") === 2, 'API status Done spawns once (a second Done does not)');
$noDue = $mkRep('No due repeat', 'day', 3, null);
$db->query("UPDATE tasks SET task_start = '$today' WHERE task_id = $noDue");
api('POST', "tasks/$noDue/toggle", $ADM);
ok(q1($db, "SELECT task_due FROM tasks WHERE task_name = 'No due repeat' AND task_completed_at IS NULL") === projectTaskShiftDate($today, 3, 'day'), 'a repeating task without a due date is due completion day + interval');
// repeating subtask keeps its parent
$par = $mkRep('Parent of repeater', 'day', 0, null);
$db->query("INSERT INTO tasks SET task_name = 'Repeating child', task_project_id = $projA, task_parent_id = $par, task_due = '$dueR', task_repeat_interval = 1, task_repeat_unit = 'day'");
$childRep = $db->insert_id;
ptAjax($BASE, $adminJar, $csrf, ['action' => 'subtask_toggle', 'task_id' => $par, 'subtask_id' => $childRep, 'done' => 1]);
ok((int) q1($db, "SELECT COUNT(*) FROM tasks WHERE task_name = 'Repeating child' AND task_parent_id = $par") === 2, 'ticking a repeating subtask repeats it under the same parent');
// ticket-linked tasks never repeat
$db->query("INSERT INTO tasks SET task_name = 'Ticket repeater', task_ticket_id = 7, task_repeat_interval = 1, task_repeat_unit = 'day', task_due = '$dueR'");
$tr = $db->insert_id;
http('GET', "$BASE/agent/post.php?complete_task=$tr&csrf_token=" . urlencode($csrf), ['Referer' => "$BASE/agent/project_kanban.php?project_id=$projA"], null, $adminJar);
ok((int) q1($db, "SELECT COUNT(*) FROM tasks WHERE task_name = 'Ticket repeater'") === 1, 'a ticket checklist item never repeats');
// API repeat field
[$st, $j] = api('PATCH', "tasks/$t1", $ADM, ['repeat' => ['interval' => 2, 'unit' => 'month']]);
ok($st === 200 && $j['repeat'] === ['interval' => 2, 'unit' => 'month'], 'API repeat {interval, unit}');
foreach ([['interval' => 0, 'unit' => 'day'], ['interval' => 1, 'unit' => 'hour'], ['interval' => 5000, 'unit' => 'day'], 'x'] as $bad) {
    [$st] = api('PATCH', "tasks/$t1", $ADM, ['repeat' => $bad]);
    ok($st === 400, 'API repeat ' . json_encode($bad) . ' -> 400');
}
[$st, $j] = api('PATCH', "tasks/$t1", $ADM, ['repeat' => null]);
ok($st === 200 && $j['repeat'] === null && (int) q1($db, "SELECT task_repeat_interval FROM tasks WHERE task_id = $t1") === 0, 'API repeat null stops repeating');
// duplicate keeps the rule
webPost($BASE, $adminJar, $projA, ['csrf_token' => $csrf, 'duplicate_project_task' => 1, 'task_id' => $dragged]);
ok((int) q1($db, "SELECT task_repeat_interval FROM tasks WHERE task_name = 'Dragged repeat (copy)'") === 2, 'duplicate keeps the repeat rule');
// pop-up shows the rule
[$st, $h] = modal($BASE, $adminJar, "task_edit.php?id=$rep");
ok(preg_match('/name="repeat_interval" value="1"/', $h) === 1 && str_contains($h, '<option value="week" selected>'), 'the edit pop-up shows the saved repeat rule');

// ================================================================ 10. API sub-resources and fields
section('API: checklist, subtasks, comments, attachments');
$rawTech = bin2hex(random_bytes(32));
$db->query("INSERT INTO api_tokens SET token_user_id = $techId, token_hash = '" . hash('sha256', $rawTech) . "', token_created_at = NOW()");
$TECH = ['Authorization' => "Bearer $rawTech"];
$rawRead = bin2hex(random_bytes(32));
$db->query("INSERT INTO api_tokens SET token_user_id = $readerId, token_hash = '" . hash('sha256', $rawRead) . "', token_created_at = NOW()");
$READ = ['Authorization' => "Bearer $rawRead"];
$rawT2 = bin2hex(random_bytes(32));
$db->query("INSERT INTO api_tokens SET token_user_id = $tech2Id, token_hash = '" . hash('sha256', $rawT2) . "', token_created_at = NOW()");
$T2 = ['Authorization' => "Bearer $rawT2"];
$db->query("INSERT INTO tasks SET task_name = 'Api playground', task_project_id = $projA");
$ap = $db->insert_id;
[$st, $j] = api('POST', "tasks/$ap/checklist", $TECH, ['text' => 'API item <b>one</b>']);
$apItem = (int) ($j['id'] ?? 0);
ok($st === 201 && $apItem > 0 && $j['text'] === 'API item one' && $j['done'] === false, 'POST checklist item');
[$st, $j] = api('PATCH', "tasks/$ap/checklist/$apItem", $TECH, ['done' => true, 'text' => 'API item 1']);
ok($st === 200 && $j['done'] === true && $j['text'] === 'API item 1', 'PATCH checklist item');
api('POST', "tasks/$ap/checklist", $TECH, ['text' => 'two']);
[$st, $j] = api('GET', "tasks/$ap/checklist", $TECH);
ok($st === 200 && count($j['items']) === 2, 'GET checklist');
[$st, $j] = api('GET', "tasks/$ap", $TECH);
ok($j['checklist'] === ['done' => 1, 'total' => 2], 'the task payload carries checklist {done, total}');
[$st] = api('POST', "tasks/$ap/checklist", $TECH, ['text' => '']);
ok($st === 400, 'POST checklist with no text -> 400');
[$st] = api('PATCH', "tasks/$ap/checklist/999999", $TECH, ['done' => true]);
ok($st === 404, 'PATCH an unknown item -> 404');
[$st] = api('POST', "tasks/$ap/checklist", $READ, ['text' => 'nope']);
ok($st === 403, 'a read-only role cannot POST a checklist item');
[$st] = api('GET', "tasks/$ap/checklist", $READ);
ok($st === 200, 'but can GET it');
[$st] = api('GET', "tasks/$ap/checklist", $T2);
ok($st === 403, 'department scope: another department\'s technician gets 403');
[$st] = api('PATCH', "tasks/$t1/checklist/$apItem", $TECH, ['done' => false]);
ok($st === 404 && (int) q1($db, "SELECT item_done FROM task_checklist_items WHERE item_id = $apItem") === 1, 'an item of another task is 404 through this task');
[$st, $j] = api('DELETE', "tasks/$ap/checklist/$apItem", $TECH);
ok($st === 200 && (int) q1($db, "SELECT COUNT(*) FROM task_checklist_items WHERE item_id = $apItem") === 0, 'DELETE checklist item');
// subtasks
[$st, $j] = api('POST', "tasks/$ap/subtasks", $TECH, ['name' => 'API sub']);
$apSub = (int) ($j['id'] ?? 0);
ok($st === 201 && $j['parent_id'] === $ap && $j['name'] === 'API sub', 'POST subtask');
[$st, $j] = api('GET', "tasks/$ap/subtasks", $TECH);
ok($st === 200 && count($j['subtasks']) === 1 && $j['subtasks'][0]['done'] === false, 'GET subtasks');
[$st, $j] = api('GET', "tasks/$ap", $TECH);
ok($j['subtasks'] === ['done' => 0, 'total' => 1], 'the task payload carries subtasks {done, total}');
[$st, $j] = api('POST', 'tasks', $ADM, ['project_id' => $projA, 'name' => 'Create as child', 'parent_id' => $ap]);
ok($st === 201 && $j['parent_id'] === $ap, 'POST /tasks with parent_id creates a subtask');
[$st] = api('POST', 'tasks', $ADM, ['project_id' => $projA, 'name' => 'Bad child', 'parent_id' => $apSub]);
ok($st === 400 && (int) q1($db, "SELECT COUNT(*) FROM tasks WHERE task_name = 'Bad child'") === 0, 'POST /tasks with a subtask as parent -> 400, nothing created');
[$st] = api('POST', 'tasks', $ADM, ['project_id' => $projA2, 'name' => 'Cross child', 'parent_id' => $ap]);
ok($st === 400 && (int) q1($db, "SELECT COUNT(*) FROM tasks WHERE task_name = 'Cross child'") === 0, 'POST /tasks with a parent from another project -> 400, nothing created');
[$st, $j] = api('PATCH', "tasks/$apSub", $ADM, ['parent_id' => null]);
ok($st === 200 && $j['parent_id'] === null, 'PATCH parent_id null detaches');
$db->query("INSERT INTO tasks SET task_name = 'Loose api', task_project_id = $projA");
$lz = $db->insert_id;
[$st] = api('PATCH', "tasks/$lz", $ADM, ['parent_id' => $ap]);
ok($st === 200 && (int) q1($db, "SELECT task_parent_id FROM tasks WHERE task_id = $lz") === $ap, 'PATCH parent_id: a loose task becomes a subtask');
[$st, $j] = api('PATCH', "tasks/$ap", $ADM, ['parent_id' => $lz]);
ok($st === 400 && q1($db, "SELECT task_parent_id FROM tasks WHERE task_id = $ap") === null, 'PATCH parent_id that would make a cycle (parent under its own child) -> 400');
[$st] = api('PATCH', "tasks/$ap", $ADM, ['parent_id' => $ap]);
ok($st === 400, 'PATCH parent_id to itself -> 400');
[$st] = api('PATCH', "tasks/$ap", $ADM, ['parent_id' => $bTask]);
ok($st === 400, 'PATCH parent_id into another project -> 400');
// comments
[$st, $j] = api('POST', "tasks/$ap/comments", $TECH, ['body' => 'api comment <i>x</i>']);
$apCm = (int) ($j['id'] ?? 0);
ok($st === 201 && $apCm > 0 && q1($db, "SELECT comment_body FROM task_comments WHERE comment_id = $apCm") === 'api comment x' && (int) q1($db, "SELECT comment_user_id FROM task_comments WHERE comment_id = $apCm") === $techId, 'POST comment (stored as the caller)');
[$st, $j] = api('GET', "tasks/$ap/comments", $ADM);
ok($st === 200 && $j['comments'][0]['user'] === 'Pt23 Tech' && $j['comments'][0]['body'] === 'api comment x' && $j['comments'][0]['created_at'] !== null, 'GET comments: author, body, time');
[$st] = api('PATCH', "tasks/$ap/comments/$apCm", $ADM, ['body' => 'hijack']);
ok($st === 403 && q1($db, "SELECT comment_body FROM task_comments WHERE comment_id = $apCm") === 'api comment x', 'PATCH someone else\'s comment -> 403');
[$st] = api('PATCH', "tasks/$ap/comments/$apCm", $TECH, ['body' => 'edited via api']);
ok($st === 200 && q1($db, "SELECT comment_body FROM task_comments WHERE comment_id = $apCm") === 'edited via api', 'PATCH own comment');
[$st, $j] = api('GET', "tasks/$ap", $ADM);
ok($j['comment_count'] === 1, 'the task payload carries comment_count');
$rawLead = bin2hex(random_bytes(32));
$db->query("INSERT INTO api_tokens SET token_user_id = $leadId, token_hash = '" . hash('sha256', $rawLead) . "', token_created_at = NOW()");
[$st] = api('DELETE', "tasks/$ap/comments/$apCm", ['Authorization' => "Bearer $rawLead"]);
ok($st === 403 && (int) q1($db, "SELECT COUNT(*) FROM task_comments WHERE comment_id = $apCm") === 1, 'DELETE another person\'s comment as a non-admin -> 403');
[$st] = api('DELETE', "tasks/$ap/comments/$apCm", $ADM);
ok($st === 200 && (int) q1($db, "SELECT COUNT(*) FROM task_comments WHERE comment_id = $apCm") === 0, 'an administrator token deletes any comment');
// attachments
[$st, $j] = api('POST', "tasks/$ap/attachments", $TECH, null, ['file' => new CURLFile("$tmpd/notes.txt", 'text/plain', 'api-notes.txt')]);
$apAtt = (int) ($j['id'] ?? 0);
ok($st === 201 && $apAtt > 0 && (int) q1($db, "SELECT attachment_uploaded_by FROM task_attachments WHERE attachment_id = $apAtt") === $techId, 'POST attachment (multipart "file")');
[$st, $j] = api('POST', "tasks/$ap/attachments", $TECH, null, ['file' => new CURLFile("$tmpd/shell.php", 'text/plain', 'shell.php')]);
ok($st === 400 && str_contains((string) ($j['error'] ?? ''), 'not allowed'), 'POST attachment with a script -> 400');
[$st, $j] = api('GET', "tasks/$ap/attachments", $TECH);
ok($st === 200 && count($j['attachments']) === 1 && $j['attachments'][0]['name'] === 'api-notes.txt' && $j['attachments'][0]['size'] === 17 && !isset($j['attachments'][0]['reference']) && !str_contains(json_encode($j), (string) q1($db, "SELECT attachment_reference FROM task_attachments WHERE attachment_id = $apAtt")), 'GET attachments: metadata only, never the stored name');
[$st, , $body, $hd] = api('GET', "tasks/$ap/attachments/$apAtt", $READ);
ok($st === 200 && $body === "hello attachment\n" && str_contains($hd['content-disposition'] ?? '', 'attachment;') && ($hd['x-content-type-options'] ?? '') === 'nosniff', 'GET one attachment downloads the bytes (a read-only role may)');
[$st] = api('GET', "tasks/$ap/attachments/$apAtt", $T2);
ok($st === 403, 'department scope on the download');
[$st] = api('GET', "tasks/$t1/attachments/$apAtt", $ADM);
ok($st === 404, 'an attachment id with the wrong task id is 404');
[$st, $j] = api('GET', "tasks/$ap", $ADM);
ok($j['attachment_count'] === 1, 'the task payload carries attachment_count');
[$st] = api('DELETE', "tasks/$ap/attachments/$apAtt", ['Authorization' => "Bearer $rawLead"]);
ok($st === 200, 'a level-3 role deletes a file');
// scopes via integration clients
function mkClient(mysqli $db, string $name, array $scopes): array
{
    $c = IntegrationAuth::createClient($db, $name, null, $scopes, '', null, 1, true, []);
    [$st, $raw] = http('POST', $GLOBALS['BASE'] . '/api/v1/oauth/token', ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query(['grant_type' => 'client_credentials', 'client_id' => $c['client_uid'], 'client_secret' => $c['secret']]));
    return ['Authorization' => 'Bearer ' . (json_decode($raw, true)['access_token'] ?? '')];
}
$RD = mkClient($db, 'pt23 reader', ['api:tasks:read', 'api:projects:read']);
$WR = mkClient($db, 'pt23 writer', ['api:tasks:read', 'api:tasks:write', 'api:projects:read']);
[$st] = api('GET', "tasks/$ap/checklist", $RD);
ok($st === 200, 'scope api:tasks:read reads the checklist');
[$st] = api('POST', "tasks/$ap/checklist", $RD + ['Idempotency-Key' => 'pt23-a'], ['text' => 'x']);
ok($st === 403, 'scope api:tasks:read cannot write a checklist item');
[$st] = api('POST', "tasks/$ap/checklist", $WR + ['Idempotency-Key' => 'pt23-b'], ['text' => 'written by a client']);
ok($st === 201, 'scope api:tasks:write can');
$itemW = (int) q1($db, "SELECT item_id FROM task_checklist_items WHERE item_text = 'written by a client'");
[$st] = api('DELETE', "tasks/$ap/checklist/$itemW", $WR);
ok($st === 403 && (int) q1($db, "SELECT COUNT(*) FROM task_checklist_items WHERE item_id = $itemW") === 1, 'without api:tasks:delete a checklist item cannot be DELETEd');
[$st, $j] = api('GET', "projects/$projA", $ADM);
$pt = array_values(array_filter($j['tasks'], fn($t) => $t['id'] === $ap))[0] ?? [];
ok(!array_diff(['checklist', 'subtasks', 'assignees', 'comment_count', 'attachment_count', 'repeat', 'parent_id', 'reminder_at'], array_keys($pt)) && !array_diff(['id', 'name', 'status', 'progress', 'due_at', 'start_at', 'milestone_id', 'completed_at', 'assigned_to', 'ticket_number', 'priority', 'labels'], array_keys($pt)), 'projects/{id} tasks gain the new fields and keep every old one');
$db->query("INSERT INTO tasks SET task_name = 'Ticket-ish', task_ticket_id = 1, task_project_id = $projA");
$ticketTask = $db->insert_id;
[$st] = api('GET', "tasks/$ticketTask/checklist", $ADM);
ok($st === 400, 'sub-resources of a ticket checklist item -> 400');
$db->query("DELETE FROM tasks WHERE task_id = $ticketTask");
// delete cascade through the API
$delTask = $ap;
$db->query("INSERT INTO task_assignees SET task_id = $delTask, user_id = $adminId");
$db->query("INSERT INTO task_comments SET comment_task_id = $delTask, comment_user_id = $adminId, comment_body = 'x'");
api('POST', "tasks/$delTask/attachments", $ADM, null, ['file' => new CURLFile("$tmpd/notes.txt", 'text/plain', 'cascade.txt')]);
$refC = (string) q1($db, "SELECT attachment_reference FROM task_attachments WHERE attachment_task_id = $delTask");
$DLT = mkClient($db, 'pt23 deleter', ['api:tasks:read', 'api:tasks:write', 'api:tasks:delete']);
[$st] = api('DELETE', "tasks/$delTask", $DLT);
ok($st === 200 && (int) q1($db, "SELECT COUNT(*) FROM tasks WHERE task_id = $delTask") === 0, 'DELETE /tasks/{id} (delete scope)');
clearstatcache();
ok(!is_file($attDir . $refC) && (int) q1($db, "SELECT COUNT(*) FROM task_checklist_items WHERE item_task_id = $delTask") === 0 && (int) q1($db, "SELECT COUNT(*) FROM task_comments WHERE comment_task_id = $delTask") === 0 && (int) q1($db, "SELECT COUNT(*) FROM task_assignees WHERE task_id = $delTask") === 0 && (int) q1($db, "SELECT COUNT(*) FROM task_attachments WHERE attachment_task_id = $delTask") === 0, 'it cascades checklist, comments, assignees and attachments (rows and the file on disk)');
ok((int) q1($db, "SELECT COUNT(*) FROM tasks WHERE task_id = $apSub") === 1 && q1($db, "SELECT task_parent_id FROM tasks WHERE task_id = $apSub") === null, 'and its subtask stays');

// ================================================================ 11. one pop-up render with everything
section('Pop-up with every panel populated');
$db->query("INSERT INTO task_checklist_items SET item_task_id = $t1, item_text = 'c1', item_order = 1, item_done = 1");
$db->query("INSERT INTO task_comments SET comment_task_id = $t1, comment_user_id = $adminId, comment_body = 'a comment'");
[$st, $h] = modal($BASE, $adminJar, "task_detail.php?id=$t1");
ok($st === 200 && noPhpErrors($h) && str_contains($h, 'a comment') && str_contains($h, 'c1') && str_contains($h, 'notes.txt') && str_contains($h, 'data-pt-act="comment_edit"') && str_contains($h, 'data-pt-act="file_delete"') && str_contains($h, 'data-pt-upload'), 'detail pop-up renders every panel with content and controls');
[$st, $h] = modal($BASE, $techJar, "task_detail.php?id=$t1");
ok($st === 200 && noPhpErrors($h) && !str_contains($h, 'name="delete_project_task"'), 'technician view of the same pop-up');
[$st, $h] = modal($BASE, $tech2Jar, "task_detail.php?id=$t1");
ok(!str_contains($h, 'c1') && !str_contains($h, 'a comment'), 'department scope: the pop-up of another department\'s task shows nothing');
[$st, $kb] = http('GET', "$BASE/agent/project_kanban.php?project_id=$projA", [], null, $adminJar);
ok($st === 200 && noPhpErrors($kb) && str_contains($kb, 'task-foot') && str_contains($kb, 'class="task-avatar'), 'Kanban renders with all the new markers');

// ---------------------------------------------------------------- cleanup
section('Cleanup');
foreach (mysqli_fetch_all(mysqli_query($mysqli, 'SELECT attachment_reference FROM task_attachments'), MYSQLI_ASSOC) as $r) {
    @unlink($attDir . $r['attachment_reference']);
}
foreach (glob($tmpd . '/{,.}*', GLOB_BRACE) as $f) {
    if (is_file($f)) { @unlink($f); }
}
@rmdir($tmpd);
@rmdir($attDir);
foreach (['task_label_links', 'task_labels', 'task_assignees', 'task_attachments', 'task_comments', 'task_checklist_items', 'tasks', 'project_milestones', 'projects', 'user_client_permissions', 'integration_tokens', 'integration_client_secrets', 'integration_client_departments', 'integration_clients', 'api_tokens'] as $t) {
    $db->query("DELETE FROM `$t`");
}
$db->query("DELETE FROM clients WHERE client_name LIKE 'PT23 %'");
$db->query("DELETE FROM users WHERE user_email LIKE '%@pt23.test'");
$db->query("DELETE FROM user_role_permissions WHERE user_role_id IN ($leadRole, $readerRole)");
$db->query("DELETE FROM user_roles WHERE role_id IN ($leadRole, $readerRole)");
foreach ([$adminJar, $techJar, $leadJar, $readerJar, $tech2Jar] as $jar) {
    @unlink($jar);
}

echo "\n" . ($FAIL ? "FAILED (" . count($FAIL) . "): " . implode('; ', $FAIL) : "ALL PASSED") . "  [$PASS passed]\n";
exit($FAIL ? 1 : 0);
