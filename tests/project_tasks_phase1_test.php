<?php
/*
 * Project tasks, phase 1 (DB 2.6.207): priority, description, colour, labels, task detail pop-up, delete / duplicate / move, API fields.
 * Scratch database only (refuses unless the name contains "scratch"); starts and stops its own php -S server.
 *
 *   RIVETIT_TEST_PORT=8098 php tests/project_tasks_phase1_test.php
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
$serverLog = sys_get_temp_dir() . '/ptp1_server_' . getmypid() . '.log';
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
/** Log in and return [cookie jar, csrf token]. */
function webLogin(string $base, string $email, string $password): array
{
    $jar = tempnam(sys_get_temp_dir(), 'ptp1jar');
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

// ---------------------------------------------------------------- fixtures
section('Fixtures (schema reset to 2.6.206)');
$db->query('SET FOREIGN_KEY_CHECKS=0');
$db->query('DROP TABLE IF EXISTS task_label_links');
$db->query('DROP TABLE IF EXISTS task_labels');
$db->query('ALTER TABLE tasks DROP COLUMN IF EXISTS task_priority, DROP COLUMN IF EXISTS task_description, DROP COLUMN IF EXISTS task_color');
$db->query('SET FOREIGN_KEY_CHECKS=1');
$db->query("UPDATE settings SET config_current_database_version = '2.6.206' WHERE company_id = 1");
foreach (['tasks', 'project_milestones', 'projects', 'user_client_permissions', 'integration_tokens', 'integration_client_secrets', 'integration_client_departments', 'integration_clients', 'api_tokens'] as $t) {
    $db->query("DELETE FROM `$t`");
}
$db->query("DELETE FROM clients");
$db->query("DELETE FROM users WHERE user_email LIKE '%@ptp1.test'");
$db->query("DELETE FROM user_roles WHERE role_name = 'PTP1 Lead'");

$db->query("INSERT INTO clients SET client_name = 'PTP1 Client A', client_created_at = NOW()");
$clientA = $db->insert_id;
$db->query("INSERT INTO clients SET client_name = 'PTP1 Client B', client_created_at = NOW()");
$clientB = $db->insert_id;
$db->query("INSERT INTO projects SET project_prefix = 'PA', project_number = 1, project_name = 'Project A', project_client_id = $clientA");
$projA = $db->insert_id;
$db->query("INSERT INTO projects SET project_prefix = 'PB', project_number = 1, project_name = 'Project B', project_client_id = $clientB");
$projB = $db->insert_id;
$db->query("INSERT INTO project_milestones SET milestone_name = 'M-A', milestone_project_id = $projA");
$msA = $db->insert_id;
$db->query("INSERT INTO tasks SET task_name = 'Legacy task one', task_project_id = $projA, task_due = '2020-01-01', task_progress = 20");
$legacy1 = $db->insert_id;
$db->query("INSERT INTO tasks SET task_name = 'Legacy task two', task_project_id = $projA");
$legacy2 = $db->insert_id;

$adminId = (int) q1($db, "SELECT user_id FROM users WHERE user_role_id = 3 AND user_status = 1 ORDER BY user_id LIMIT 1");
$adminEmail = (string) q1($db, "SELECT user_email FROM users WHERE user_id = $adminId");
$ADMINPW = 'PtpAdmin#2026-test';
$db->query("UPDATE users SET user_password = '" . password_hash($ADMINPW, PASSWORD_DEFAULT) . "' WHERE user_id = $adminId");

// A Technician (support level 2) and a "Lead" (support level 3, not an administrator) restricted to Client A
$mkUser = function (string $name, int $roleId) use ($db): int {
    $pw = password_hash('PtpUser#2026-test', PASSWORD_DEFAULT);
    $db->query("INSERT INTO users SET user_name = '$name', user_email = '" . strtolower(str_replace(' ', '.', $name)) . "@ptp1.test', user_password = '$pw', user_role_id = $roleId, user_status = 1, user_type = 1");
    return $db->insert_id;
};
$db->query("INSERT INTO user_roles SET role_name = 'PTP1 Lead', role_description = 'test', role_is_admin = 0");
$leadRole = $db->insert_id;
$supportModule = (int) q1($db, "SELECT module_id FROM modules WHERE module_name = 'module_support'");
$db->query("INSERT INTO user_role_permissions SET user_role_id = $leadRole, module_id = $supportModule, user_role_permission_level = 3");
$techId = $mkUser('Ptp Tech', 2);
$leadId = $mkUser('Ptp Lead', $leadRole);
$db->query("INSERT INTO user_client_permissions SET user_id = $leadId, client_id = $clientA");

[$adminJar, $st] = webLogin($BASE, $adminEmail, $ADMINPW);
ok($st === 302, 'admin web login (scratch)', "HTTP $st");
$csrf = csrfOf($BASE, $adminJar, $projA);
ok(strlen($csrf) > 10, 'admin CSRF token read from the Kanban page (schema 2.6.206)');

// ================================================================ 1. code on a 2.6.206 schema
section('Update App before Update Database: nothing fatals on a 2.6.206 schema');
ok(!(bool) q1($db, "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'tasks' AND column_name = 'task_priority'"), 'precondition: tasks.task_priority does not exist yet');
[$st, $html] = http('GET', "$BASE/agent/project_kanban.php?project_id=$projA", [], null, $adminJar);
ok($st === 200 && noPhpErrors($html) && str_contains($html, 'Legacy task one'), 'Kanban renders old schema', "HTTP $st");
ok(!str_contains($html, 'id="kanban-sort"'), 'priority sort/filter controls hidden before the upgrade');
ok(!str_contains($html, 'drag-handle-class'), 'the hamburger drag handle is gone');
[$st, $html] = http('GET', "$BASE/agent/project_details.php?project_id=$projA", [], null, $adminJar);
ok($st === 200 && noPhpErrors($html) && str_contains($html, 'Legacy task two'), 'project details list renders old schema', "HTTP $st");
foreach (["task_add.php?project_id=$projA", "task_edit.php?id=$legacy1", "task_detail.php?id=$legacy1"] as $m) {
    [$st, $h] = modal($BASE, $adminJar, $m);
    ok($st === 200 && noPhpErrors($h) && str_contains($h, 'name="name"'), "pop-up $m renders old schema", "HTTP $st");
}
[$st, $h] = modal($BASE, $adminJar, "task_edit.php?id=$legacy1");
ok(str_contains($h, 'Update Database') && !str_contains($h, 'name="priority"'), 'pop-up shows the Update Database notice instead of the new fields');
[$st] = webPost($BASE, $adminJar, $projA, ['csrf_token' => $csrf, 'add_project_task' => 1, 'project_id' => $projA, 'name' => 'Pre task', 'priority' => 3, 'description' => 'x', 'color' => '#ff0000', 'label_ids' => [1], 'new_labels' => 'a,b', 'status' => 'Done', 'status_orig' => 'To Do']);
$pre = q($db, "SELECT * FROM tasks WHERE task_name = 'Pre task'");
ok($st === 302 && count($pre) === 1 && $pre[0]['task_completed_at'] === null, 'create with new fields on the old schema succeeds and ignores them');
$preId = (int) ($pre[0]['task_id'] ?? 0);
[$st] = webPost($BASE, $adminJar, $projA, ['csrf_token' => $csrf, 'edit_project_task' => 1, 'task_id' => $preId, 'name' => 'Pre task edited', 'priority' => 2]);
ok($st === 302 && q1($db, "SELECT task_name FROM tasks WHERE task_id = $preId") === 'Pre task edited', 'edit on the old schema succeeds');
[$st] = webPost($BASE, $adminJar, $projA, ['csrf_token' => $csrf, 'duplicate_project_task' => 1, 'task_id' => $preId]);
ok($st === 302 && (int) q1($db, "SELECT COUNT(*) FROM tasks WHERE task_name = 'Pre task edited (copy)'") === 1, 'duplicate on the old schema succeeds');
[$st] = webPost($BASE, $adminJar, $projA, ['csrf_token' => $csrf, 'delete_project_task' => 1, 'task_id' => $preId]);
ok($st === 302 && (int) q1($db, "SELECT COUNT(*) FROM tasks WHERE task_id = $preId") === 0, 'delete on the old schema succeeds');
$db->query("DELETE FROM tasks WHERE task_name LIKE 'Pre task%'");
// kanban reorder AJAX unchanged
[$st, $body] = http('POST', "$BASE/agent/ajax.php", ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query(['update_project_task_kanban' => 1, 'csrf_token' => $csrf, 'positions' => [['task_id' => $legacy1, 'task_order' => 5, 'task_status' => 'In Progress']]]), $adminJar);
ok($st === 200 && (json_decode($body, true)['status'] ?? '') === 'success' && q1($db, "SELECT task_status FROM tasks WHERE task_id = $legacy1") === 'In Progress' && (int) q1($db, "SELECT task_order FROM tasks WHERE task_id = $legacy1") === 5, 'Kanban reorder/status AJAX is unchanged');
// API on the old schema
$rawAdmin = bin2hex(random_bytes(32));
$db->query("INSERT INTO api_tokens SET token_user_id = $adminId, token_hash = '" . hash('sha256', $rawAdmin) . "', token_created_at = NOW()");
$ADM = ['Authorization' => "Bearer $rawAdmin"];
function api(string $method, string $path, array $auth, ?array $json = null): array
{
    global $BASE;
    $h = $auth;
    $body = null;
    if ($json !== null) {
        $h['Content-Type'] = 'application/json';
        $body = json_encode($json);
    }
    [$st, $raw] = http($method, "$BASE/api/v1/" . ltrim($path, '/'), $h, $body);
    return [$st, json_decode($raw, true), $raw];
}
[$st, $j] = api('GET', "projects/$projA", $ADM);
$pt = $j['tasks'][0] ?? [];
ok($st === 200 && array_key_exists('priority', $pt) && $pt['priority'] === 0 && $pt['labels'] === [] && $pt['color'] === null && array_key_exists('name', $pt) && array_key_exists('progress', $pt) && array_key_exists('due_at', $pt), 'API project tasks: new fields default sensibly on the old schema, old fields still there', "HTTP $st");
[$st, $j] = api('POST', "tasks/$legacy2/toggle", $ADM);
ok($st === 200 && $j['completed_at'] !== null && $j['priority'] === 0, 'API toggle still works on the old schema');
[$st] = api('POST', "tasks/$legacy2/toggle", $ADM);
[$st, $j] = api('PATCH', "tasks/$legacy2", $ADM, ['name' => 'Legacy task two', 'priority' => 3, 'labels' => ['x']]);
ok($st === 200 && $j['priority'] === 0, 'API PATCH with new fields on the old schema: accepted, new fields ignored');

// ================================================================ 2. migration
section('Migration 2.6.206 -> 2.6.207');
$update = function () use ($ROOT): string {
    return (string) shell_exec('cd ' . escapeshellarg($ROOT . '/scripts') . ' && php update_cli.php --update_db 2>&1');
};
$out = $update();
ok(str_contains($out, 'to version 2.6.207') || str_contains($out, 'from version 2.6.206 to 2.6.207'), 'Update Database applies 2.6.207', trim(substr($out, -200)));
ok(q1($db, "SELECT config_current_database_version FROM settings WHERE company_id = 1") === '2.6.207', 'settings version is 2.6.207');
$show = function (mysqli $c, string $t): string {
    $r = $c->query("SHOW CREATE TABLE `$t`")->fetch_row()[1];
    return preg_replace('/ AUTO_INCREMENT=\d+/', '', $r);
};
// fresh install of db.sql into a second scratch database
$freshDb = $database . '_fresh';
$rootRun = function (string $sql, ?string $file = null) use ($ROOTCMD): string {
    $cmd = escapeshellarg($ROOTCMD) . ($file ? ' ' . escapeshellarg($sql) . ' < ' . escapeshellarg($file) : ' -e ' . escapeshellarg($sql)) . ' 2>&1';
    return (string) shell_exec($cmd);
};
$rootRun("DROP DATABASE IF EXISTS `$freshDb`; CREATE DATABASE `$freshDb` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;");
$rootRun($freshDb, $ROOT . '/db.sql');
// Compare via the root helper (the test user has no rights on the fresh database)
$freshShow = function (string $t) use ($rootRun, $freshDb): string {
    $o = $rootRun("SHOW CREATE TABLE `$freshDb`.`$t`\\G");
    $o = preg_replace('/^.*?Create Table: /s', '', $o);
    $o = preg_replace('/\n?\s*$/', '', $o);
    return preg_replace('/ AUTO_INCREMENT=\d+/', '', $o);
};
foreach (['tasks', 'task_labels', 'task_label_links'] as $t) {
    $a = $show($db, $t);
    $b = $freshShow($t);
    ok($a !== '' && trim($a) === trim($b), "SHOW CREATE TABLE $t: migrated == fresh install of db.sql", $a === '' ? 'missing' : '');
}
$rootRun("DROP DATABASE IF EXISTS `$freshDb`;");
// rerun is a no-op, and re-running the block itself keeps data and structure
$out2 = $update();
ok(!str_contains($out2, 'Database updated'), 'rerun at 2.6.207 is a no-op');
$before = ['tasks' => $show($db, 'tasks'), 'task_labels' => $show($db, 'task_labels'), 'task_label_links' => $show($db, 'task_label_links')];
$db->query("INSERT INTO task_labels SET label_name = 'keep-me', label_color = '#112233'");
$keepLabel = $db->insert_id;
$db->query("UPDATE tasks SET task_priority = 3, task_description = 'kept' WHERE task_id = $legacy1");
$db->query("INSERT INTO task_label_links SET task_id = $legacy1, label_id = $keepLabel");
$db->query("UPDATE settings SET config_current_database_version = '2.6.206' WHERE company_id = 1");
$out3 = $update();
ok(q1($db, "SELECT config_current_database_version FROM settings WHERE company_id = 1") === '2.6.207', 'the 2.6.207 block can run again (idempotent)');
ok($before === ['tasks' => $show($db, 'tasks'), 'task_labels' => $show($db, 'task_labels'), 'task_label_links' => $show($db, 'task_label_links')], 'structure identical after the second run');
ok((int) q1($db, "SELECT task_priority FROM tasks WHERE task_id = $legacy1") === 3 && (int) q1($db, "SELECT COUNT(*) FROM task_label_links WHERE task_id = $legacy1") === 1, 'data survives the second run');
ok((int) q1($db, "SELECT COUNT(*) FROM tasks WHERE task_priority <> 0") === 1, 'no back-fill: every other task stays priority 0');
$db->query("UPDATE tasks SET task_priority = 0, task_description = NULL WHERE task_id = $legacy1");
$db->query("DELETE FROM task_labels WHERE label_id = $keepLabel");

// ================================================================ 3. handlers
section('Create / edit with priority, labels, description, colour');
$xss = '<script>alert(1)</script>';
$xssImg = '"><img src=x onerror=alert(2)>';
[$st] = webPost($BASE, $adminJar, $projA, [
    'csrf_token' => $csrf, 'add_project_task' => 1, 'project_id' => $projA, 'name' => 'Rich task', 'milestone_id' => $msA, 'assigned_to' => $adminId,
    'priority' => 4, 'description' => "Line one\nLine <b>two</b> $xss", 'color' => '#a1b2c3', 'labels_present' => 1,
    'new_labels' => "Urgent-fix, $xssImg, Backend", 'new_label_color' => '#ff8800', 'start' => '2026-01-01', 'due' => '2026-02-01', 'progress' => 10, 'completion_estimate' => 30,
]);
$rich = q($db, "SELECT * FROM tasks WHERE task_name = 'Rich task'")[0] ?? [];
$richId = (int) ($rich['task_id'] ?? 0);
ok($st === 302 && $richId > 0 && (int) $rich['task_priority'] === 4 && $rich['task_color'] === '#a1b2c3', 'create stores priority and colour');
ok(str_contains((string) $rich['task_description'], "Line one\nLine") && !str_contains((string) $rich['task_description'], '<script>'), 'description keeps line breaks, tags are stripped on save');
$labs = q($db, "SELECT l.label_name, l.label_color, l.label_project_id FROM task_label_links k JOIN task_labels l USING (label_id) WHERE k.task_id = $richId ORDER BY l.label_name");
ok(count($labs) === 3 && (int) $labs[0]['label_project_id'] === $projA && $labs[0]['label_color'] === '#ff8800', 'new labels are created in the project with the chosen colour and linked', json_encode(array_column($labs, 'label_name')));
[$st, $html] = http('GET', "$BASE/agent/project_kanban.php?project_id=$projA", [], null, $adminJar);
ok($st === 200 && noPhpErrors($html) && str_contains($html, 'Rich task') && str_contains($html, 'Urgent') && str_contains($html, 'Urgent-fix'), 'Kanban shows priority badge text and label chips');
ok(str_contains($html, 'data-priority="4"') && str_contains($html, '--task-accent:#a1b2c3'), 'card carries priority and accent');
ok(str_contains($html, 'id="kanban-sort"') && str_contains($html, 'id="kanban-filter-priority"') && str_contains($html, 'id="kanban-filter-label"') && str_contains($html, 'id="kanban-filter-assignee"'), 'sort and filter bar present');
ok(!str_contains($html, 'drag-handle-class') && str_contains($html, 'class="task grab-cursor'), 'no drag handle; whole card is the item');
// Output escaping: payloads written straight to the database (bypassing the input stripping) must still come out inert everywhere
$payload = '<script>alert(1)</script><img src=x onerror=alert(2)>';
$db->query("INSERT INTO tasks SET task_name = '" . $db->real_escape_string("XSS $payload") . "', task_project_id = $projA, task_priority = 2, task_description = '" . $db->real_escape_string($payload) . "', task_color = '#00ff00'");
$xssTask = $db->insert_id;
$db->query("INSERT INTO task_labels SET label_name = '" . $db->real_escape_string($payload) . "', label_color = '#0000ff', label_project_id = $projA");
$db->query("INSERT INTO task_label_links SET task_id = $xssTask, label_id = " . $db->insert_id);
$db->query("UPDATE tasks SET task_color = 'red;\"onmouseover=alert(3)' WHERE task_id = $xssTask");
[$st, $kb] = http('GET', "$BASE/agent/project_kanban.php?project_id=$projA", [], null, $adminJar);
[$st, $dm] = modal($BASE, $adminJar, "task_detail.php?id=$xssTask");
[$st, $em] = modal($BASE, $adminJar, "task_edit.php?id=$xssTask");
[$st, $pd] = http('GET', "$BASE/agent/project_details.php?project_id=$projA", [], null, $adminJar);
foreach (['Kanban' => $kb, 'detail pop-up' => $dm, 'edit pop-up' => $em, 'project list' => $pd] as $where => $page) {
    ok(!str_contains($page, '<img src=x') && !str_contains($page, '<script>alert') && !str_contains($page, 'onmouseover=alert'), "$where: task name, label name, description and a hostile colour are escaped / dropped");
}
ok(str_contains($dm, '&lt;script&gt;alert(1)&lt;/script&gt;'), 'detail pop-up shows the description as escaped text');
$db->query("DELETE FROM tasks WHERE task_id = $xssTask");
$db->query("DELETE FROM task_labels WHERE label_name LIKE '<script>%'");
[$st, $h] = modal($BASE, $adminJar, "task_detail.php?id=$richId");
ok($st === 200 && noPhpErrors($h), 'detail pop-up renders for the rich task', "HTTP $st");
ok(str_contains($h, 'name="priority"') && str_contains($h, 'name="description"') && str_contains($h, 'name="label_ids[]"') && str_contains($h, 'name="status"') && str_contains($h, 'name="color"') && str_contains($h, 'name="delete_project_task"') && str_contains($h, 'name="duplicate_project_task"'), 'detail pop-up has every field and the actions');
[$st, $html] = http('GET', "$BASE/agent/project_details.php?project_id=$projA", [], null, $adminJar);
ok($st === 200 && noPhpErrors($html) && str_contains($html, 'Urgent') && str_contains($html, 'Backend') && !str_contains($html, '<img src=x onerror'), 'project list view shows the priority marker and label chips, escaped');

// edit
[$st] = webPost($BASE, $adminJar, $projA, ['csrf_token' => $csrf, 'edit_project_task' => 1, 'task_id' => $richId, 'name' => 'Rich task v2', 'milestone_id' => 0, 'assigned_to' => 0, 'priority' => 1, 'description' => '', 'color_clear' => 1, 'color' => '#000000', 'labels_present' => 1, 'label_ids' => [$labs ? (int) q1($db, "SELECT label_id FROM task_labels WHERE label_name = 'Backend'") : 0], 'status' => 'Blocked', 'status_orig' => 'To Do', 'progress' => 30]);
$r2 = q($db, "SELECT * FROM tasks WHERE task_id = $richId")[0];
ok($st === 302 && $r2['task_name'] === 'Rich task v2' && (int) $r2['task_priority'] === 1 && $r2['task_description'] === null && $r2['task_color'] === null && $r2['task_status'] === 'Blocked', 'edit changes priority, clears description and colour, moves lane');
ok((int) q1($db, "SELECT COUNT(*) FROM task_label_links WHERE task_id = $richId") === 1, 'edit replaces the label set');
// untouched lane must not undo typed progress
[$st] = webPost($BASE, $adminJar, $projA, ['csrf_token' => $csrf, 'edit_project_task' => 1, 'task_id' => $richId, 'name' => 'Rich task v2', 'status' => 'Blocked', 'status_orig' => 'Blocked', 'progress' => 100, 'priority' => 1]);
ok((int) q1($db, "SELECT task_progress FROM tasks WHERE task_id = $richId") === 100 && q1($db, "SELECT task_completed_at FROM tasks WHERE task_id = $richId") === null, 'an untouched lane does not reset a typed progress');
[$st] = webPost($BASE, $adminJar, $projA, ['csrf_token' => $csrf, 'edit_project_task' => 1, 'task_id' => $richId, 'name' => 'Rich task v2', 'status' => 'Done', 'status_orig' => 'Blocked', 'progress' => 40]);
ok(q1($db, "SELECT task_completed_at FROM tasks WHERE task_id = $richId") !== null && (int) q1($db, "SELECT task_progress FROM tasks WHERE task_id = $richId") === 100, 'choosing Done completes the task (same rules as the Kanban drag)');
[$st] = webPost($BASE, $adminJar, $projA, ['csrf_token' => $csrf, 'edit_project_task' => 1, 'task_id' => $richId, 'name' => 'Rich task v2', 'status' => 'In Progress', 'status_orig' => 'Done', 'progress' => 0]);
ok(q1($db, "SELECT task_completed_at FROM tasks WHERE task_id = $richId") === null && q1($db, "SELECT task_status FROM tasks WHERE task_id = $richId") === 'In Progress', 'moving out of Done clears completion');
// bad inputs
[$st] = webPost($BASE, $adminJar, $projA, ['csrf_token' => $csrf, 'edit_project_task' => 1, 'task_id' => $richId, 'name' => 'Rich task v2', 'priority' => 99, 'color' => 'red; background:url(x)', 'status' => 'Hacked', 'status_orig' => 'In Progress']);
$r3 = q($db, "SELECT task_priority, task_color, task_status FROM tasks WHERE task_id = $richId")[0];
ok((int) $r3['task_priority'] === 4 && $r3['task_color'] === null && $r3['task_status'] === 'In Progress', 'priority is clamped to 0-4, a bad colour is dropped, an unknown lane is ignored', json_encode($r3));

section('CSRF');
$cnt = (int) q1($db, 'SELECT COUNT(*) FROM tasks');
foreach (['add_project_task' => ['project_id' => $projA, 'name' => 'No csrf'], 'edit_project_task' => ['task_id' => $richId, 'name' => 'Edited w/o csrf'], 'delete_project_task' => ['task_id' => $richId], 'duplicate_project_task' => ['task_id' => $richId], 'move_project_task' => ['task_id' => $richId, 'target_project_id' => $projB]] as $action => $f) {
    webPost($BASE, $adminJar, $projA, [$action => 1] + $f);          // no csrf_token at all
    webPost($BASE, $adminJar, $projA, [$action => 1, 'csrf_token' => 'wrong'] + $f);
}
ok((int) q1($db, 'SELECT COUNT(*) FROM tasks') === $cnt && q1($db, "SELECT task_name FROM tasks WHERE task_id = $richId") === 'Rich task v2' && (int) q1($db, "SELECT task_project_id FROM tasks WHERE task_id = $richId") === $projA, 'add / edit / delete / duplicate / move without a valid CSRF token change nothing');
[$st] = http('GET', "$BASE/agent/post.php?delete_project_task=$richId", [], null, $adminJar);
ok((int) q1($db, "SELECT COUNT(*) FROM tasks WHERE task_id = $richId") === 1, 'delete is POST-only (a GET does nothing)');

section('Label scoping');
$gl = $db->query("INSERT INTO task_labels SET label_name = 'Global-L', label_color = '#00aa00'") ? $db->insert_id : 0;
$db->query("INSERT INTO task_labels SET label_name = 'B-only', label_color = '#aa0000', label_project_id = $projB");
$bOnly = $db->insert_id;
$aLab = (int) q1($db, "SELECT label_id FROM task_labels WHERE label_name = 'Backend' AND label_project_id = $projA");
webPost($BASE, $adminJar, $projA, ['csrf_token' => $csrf, 'edit_project_task' => 1, 'task_id' => $richId, 'name' => 'Rich task v2', 'labels_present' => 1, 'label_ids' => [$gl, $bOnly, $aLab, 999999]]);
$linked = array_map('intval', array_column(q($db, "SELECT label_id FROM task_label_links WHERE task_id = $richId"), 'label_id'));
sort($linked);
ok($linked === [min($gl, $aLab), max($gl, $aLab)], 'global + own-project labels attach; another project\'s label and an unknown id are ignored', json_encode($linked));
[$st, $h] = modal($BASE, $adminJar, "task_edit.php?id=$richId");
ok(str_contains($h, 'Global-L') && !str_contains($h, 'B-only'), 'the label picker lists global and this project\'s labels only');
$db->query("INSERT INTO tasks SET task_name = 'B task', task_project_id = $projB");
$bTask = $db->insert_id;
[$st, $h] = modal($BASE, $adminJar, "task_edit.php?id=$bTask");
ok(str_contains($h, 'B-only') && !str_contains($h, 'Backend'), 'project B\'s picker shows B-only and global, not project A\'s labels');
// global label creation: admin yes, technician no
webPost($BASE, $adminJar, $projA, ['csrf_token' => $csrf, 'edit_project_task' => 1, 'task_id' => $richId, 'name' => 'Rich task v2', 'labels_present' => 1, 'new_labels' => 'Admin-global', 'label_global' => 1]);
ok((int) q1($db, "SELECT COUNT(*) FROM task_labels WHERE label_name = 'Admin-global' AND label_project_id IS NULL") === 1, 'an administrator can create an every-project label');
[$techJar] = webLogin($BASE, 'ptp.tech@ptp1.test', 'PtpUser#2026-test');
$techCsrf = csrfOf($BASE, $techJar, $projA);
ok(strlen($techCsrf) > 10, 'technician (support level 2) can open the Kanban');
webPost($BASE, $techJar, $projA, ['csrf_token' => $techCsrf, 'edit_project_task' => 1, 'task_id' => $richId, 'name' => 'Rich task v2', 'labels_present' => 1, 'new_labels' => 'Tech-global', 'label_global' => 1]);
ok((int) q1($db, "SELECT COUNT(*) FROM task_labels WHERE label_name = 'Tech-global' AND label_project_id IS NULL") === 0 && (int) q1($db, "SELECT COUNT(*) FROM task_labels WHERE label_name = 'Tech-global' AND label_project_id = $projA") === 1, 'a technician cannot make a global label: it is created in the project instead');

section('Delete, duplicate, move: permissions and client access');
[$st, $h] = modal($BASE, $techJar, "task_detail.php?id=$richId");
ok(!str_contains($h, 'name="delete_project_task"') && str_contains($h, 'name="duplicate_project_task"'), 'technician sees Duplicate but no Delete in the pop-up');
webPost($BASE, $techJar, $projA, ['csrf_token' => $techCsrf, 'delete_project_task' => 1, 'task_id' => $richId]);
ok((int) q1($db, "SELECT COUNT(*) FROM tasks WHERE task_id = $richId") === 1, 'delete needs support level 3: a technician (2) cannot delete');
webPost($BASE, $techJar, $projA, ['csrf_token' => $techCsrf, 'duplicate_project_task' => 1, 'task_id' => $richId]);
$dup = q($db, "SELECT * FROM tasks WHERE task_name = 'Rich task v2 (copy)'")[0] ?? [];
$dupId = (int) ($dup['task_id'] ?? 0);
ok($dupId > 0 && (int) $dup['task_priority'] === (int) q1($db, "SELECT task_priority FROM tasks WHERE task_id = $richId") && $dup['task_completed_at'] === null && (int) $dup['task_progress'] === 0 && (int) $dup['task_project_id'] === $projA, 'duplicate copies priority/fields, not completion or progress');
ok((int) q1($db, "SELECT COUNT(*) FROM task_label_links WHERE task_id = $dupId") === (int) q1($db, "SELECT COUNT(*) FROM task_label_links WHERE task_id = $richId"), 'duplicate copies the labels');
// move (give the source both a project label and a global one first)
webPost($BASE, $adminJar, $projA, ['csrf_token' => $csrf, 'edit_project_task' => 1, 'task_id' => $richId, 'name' => 'Rich task v2', 'labels_present' => 1, 'label_ids' => [$gl, $aLab]]);
$db->query("INSERT IGNORE INTO task_label_links (task_id, label_id) SELECT $dupId, label_id FROM task_label_links WHERE task_id = $richId");
webPost($BASE, $techJar, $projA, ['csrf_token' => $techCsrf, 'move_project_task' => 1, 'task_id' => $dupId, 'target_project_id' => $projB]);
ok((int) q1($db, "SELECT task_project_id FROM tasks WHERE task_id = $dupId") === $projB && (int) q1($db, "SELECT task_milestone_id FROM tasks WHERE task_id = $dupId") === 0, 'move changes the project and clears the milestone');
ok((int) q1($db, "SELECT COUNT(*) FROM task_label_links WHERE task_id = $dupId AND label_id = $aLab") === 0 && (int) q1($db, "SELECT COUNT(*) FROM task_label_links WHERE task_id = $dupId AND label_id = $gl") === 1, 'move drops project-scoped labels, keeps global ones');
webPost($BASE, $techJar, $projA, ['csrf_token' => $techCsrf, 'move_project_task' => 1, 'task_id' => $dupId, 'target_project_id' => 999999]);
ok((int) q1($db, "SELECT task_project_id FROM tasks WHERE task_id = $dupId") === $projB, 'move to a missing project is refused');
// lead (level 3, only Client A): may delete in A, not in B
[$leadJar] = webLogin($BASE, 'ptp.lead@ptp1.test', 'PtpUser#2026-test');
$leadCsrf = csrfOf($BASE, $leadJar, $projA);
webPost($BASE, $leadJar, $projA, ['csrf_token' => $leadCsrf, 'delete_project_task' => 1, 'task_id' => $dupId]);
ok((int) q1($db, "SELECT COUNT(*) FROM tasks WHERE task_id = $dupId") === 1, 'client access: a level-3 user limited to Client A cannot delete a task of Client B\'s project');
webPost($BASE, $leadJar, $projA, ['csrf_token' => $leadCsrf, 'edit_project_task' => 1, 'task_id' => $dupId, 'name' => 'hijack']);
ok(q1($db, "SELECT task_name FROM tasks WHERE task_id = $dupId") !== 'hijack', 'client access: edit of another client\'s task is refused');
[$st, $h] = modal($BASE, $leadJar, "task_detail.php?id=$dupId");
ok(!str_contains($h, 'Rich task v2 (copy)'), 'client access: the detail pop-up does not open another client\'s task');
// admin delete cleans links
$linksBefore = (int) q1($db, "SELECT COUNT(*) FROM task_label_links WHERE task_id = $richId");
webPost($BASE, $leadJar, $projA, ['csrf_token' => $leadCsrf, 'delete_project_task' => 1, 'task_id' => $richId]);
ok((int) q1($db, "SELECT COUNT(*) FROM tasks WHERE task_id = $richId") === 0 && (int) q1($db, "SELECT COUNT(*) FROM task_label_links WHERE task_id = $richId") === 0 && $linksBefore > 0, 'a level-3 user deletes a task in their own client; its label links go too');
ok((int) q1($db, "SELECT COUNT(*) FROM task_labels WHERE label_name = 'Global-L'") === 1, 'deleting a task keeps the labels');
ok((int) q1($db, "SELECT COUNT(*) FROM logs WHERE log_type = 'Task' AND log_action = 'Delete' AND log_description LIKE '%Rich task v2%'") >= 1, 'delete is audit-logged');
// project-only task in a client the existing GET handler used to skip
$db->query("INSERT INTO tasks SET task_name = 'GET-del task', task_project_id = $projB");
$getDel = $db->insert_id;
http('GET', "$BASE/agent/post.php?delete_task=$getDel&csrf_token=" . urlencode($leadCsrf), ['Referer' => "$BASE/agent/project_kanban.php?project_id=$projB"], null, $leadJar);
ok((int) q1($db, "SELECT COUNT(*) FROM tasks WHERE task_id = $getDel") === 1, 'existing delete_task link now applies client access to project-only tasks too');
$db->query("INSERT INTO task_label_links SET task_id = $getDel, label_id = $gl");
http('GET', "$BASE/agent/post.php?delete_task=$getDel&csrf_token=" . urlencode($csrf), ['Referer' => "$BASE/agent/project_details.php?project_id=$projB"], null, $adminJar);
ok((int) q1($db, "SELECT COUNT(*) FROM tasks WHERE task_id = $getDel") === 0 && (int) q1($db, "SELECT COUNT(*) FROM task_label_links WHERE task_id = $getDel") === 0, 'existing delete_task link still works for an administrator and cleans label links');
// project delete cascades its labels
$db->query("INSERT INTO projects SET project_prefix = 'PX', project_number = 9, project_name = 'Doomed', project_client_id = $clientA");
$px = $db->insert_id;
$db->query("INSERT INTO task_labels SET label_name = 'Doomed-L', label_project_id = $px");
$db->query("DELETE FROM projects WHERE project_id = $px");
ok((int) q1($db, "SELECT COUNT(*) FROM task_labels WHERE label_name = 'Doomed-L'") === 0, 'deleting a project deletes its own labels (FK cascade)');

// ================================================================ 4. API
section('API: additive fields, create / patch / delete, scopes');
$legacyBody = api('GET', "projects/$projA", $ADM)[1]['tasks'][0] ?? [];
ok(!array_diff(['id', 'name', 'status', 'progress', 'due_at', 'start_at', 'milestone_id', 'completed_at', 'assigned_to', 'ticket_number'], array_keys($legacyBody)), 'projects/{id} tasks keep every old field');
ok(array_key_exists('priority', $legacyBody) && array_key_exists('priority_name', $legacyBody) && array_key_exists('description', $legacyBody) && array_key_exists('color', $legacyBody) && array_key_exists('labels', $legacyBody), 'projects/{id} tasks gain priority, priority_name, description, color, labels');
[$st, $j] = api('POST', 'tasks', $ADM, ['project_id' => $projA, 'name' => 'API task', 'priority' => 3, 'description' => "api\ndesc", 'color' => '#123456', 'labels' => ['api-l', ['name' => 'api-c', 'color' => '#abcdef']], 'due_at' => '2026-05-05', 'status' => 'Blocked']);
$apiId = (int) ($j['id'] ?? 0);
ok($st === 201 && $apiId > 0 && $j['priority'] === 3 && $j['priority_name'] === 'High' && $j['color'] === '#123456' && $j['description'] === "api\ndesc" && count($j['labels']) === 2 && $j['due_at'] === '2026-05-05' && $j['status'] === 'Blocked', 'POST /tasks creates with the new fields', "HTTP $st " . json_encode($j));
[$st, $j] = api('GET', "tasks/$apiId", $ADM);
ok($st === 200 && $j['name'] === 'API task' && $j['labels'][0]['color'] !== null, 'GET /tasks/{id}');
[$st, $j] = api('PATCH', "tasks/$apiId", $ADM, ['priority' => 1, 'color' => null, 'labels' => [], 'progress' => 55]);
ok($st === 200 && $j['priority'] === 1 && $j['color'] === null && $j['labels'] === [] && $j['progress'] === 55 && $j['name'] === 'API task', 'PATCH changes only the given fields; labels [] clears them');
[$st] = api('PATCH', "tasks/$apiId", $ADM, ['priority' => 9]);
ok($st === 400, 'PATCH priority out of range -> 400');
[$st] = api('PATCH', "tasks/$apiId", $ADM, ['color' => 'blue']);
ok($st === 400, 'PATCH bad colour -> 400');
[$st] = api('PATCH', "tasks/$apiId", $ADM, ['status' => 'Nope']);
ok($st === 400, 'PATCH bad status -> 400');
[$st] = api('PATCH', "tasks/$apiId", $ADM, ['due_at' => '05/05/2026']);
ok($st === 400, 'PATCH bad date -> 400');
[$st] = api('PATCH', "tasks/$apiId", $ADM, ['milestone_id' => $msA + 1000]);
ok($st === 400, 'PATCH milestone from another project -> 400');
[$st, $j] = api('PATCH', "tasks/$apiId", $ADM, ['description' => '<script>alert(1)</script>x']);
ok($st === 200 && !str_contains((string) $j['description'], '<script>'), 'API description is stored without tags');
[$st, $j] = api('POST', 'tasks', $ADM, ['name' => 'no project']);
ok($st === 400, 'POST /tasks without project_id -> 400');
// label scoping through the API
[$st, $j] = api('PATCH', "tasks/$apiId", $ADM, ['label_ids' => [$bOnly, $gl]]);
ok($st === 200 && array_column($j['labels'], 'id') === [$gl], 'API label_ids: another project\'s label is ignored, a global one attaches');
// permissions: technician token cannot delete (needs 3); integration scopes
$rawTech = bin2hex(random_bytes(32));
$db->query("INSERT INTO api_tokens SET token_user_id = $techId, token_hash = '" . hash('sha256', $rawTech) . "', token_created_at = NOW()");
$TECH = ['Authorization' => "Bearer $rawTech"];
[$st] = api('DELETE', "tasks/$apiId", $TECH);
ok($st === 403 && (int) q1($db, "SELECT COUNT(*) FROM tasks WHERE task_id = $apiId") === 1, 'user token: a technician (support level 2) cannot DELETE');
[$st] = api('PATCH', "tasks/$apiId", $TECH, ['priority' => 2]);
ok($st === 200, 'user token: a technician can PATCH (write level 2)');
function mkClient(mysqli $db, string $name, array $scopes): array
{
    $c = IntegrationAuth::createClient($db, $name, null, $scopes, '', null, 1, true, []);
    [$st, $raw] = http('POST', $GLOBALS['BASE'] . '/api/v1/oauth/token', ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query(['grant_type' => 'client_credentials', 'client_id' => $c['client_uid'], 'client_secret' => $c['secret']]));
    return ['Authorization' => 'Bearer ' . (json_decode($raw, true)['access_token'] ?? '')];
}
$RD = mkClient($db, 'ptp1 reader', ['api:tasks:read', 'api:projects:read']);
$WR = mkClient($db, 'ptp1 writer', ['api:tasks:read', 'api:tasks:write', 'api:projects:read']);
$DL = mkClient($db, 'ptp1 deleter', ['api:tasks:read', 'api:tasks:write', 'api:tasks:delete', 'api:projects:read']);
[$st] = api('GET', "tasks/$apiId", $RD);
ok($st === 200, 'api:tasks:read -> GET task');
[$st, $j] = api('PATCH', "tasks/$apiId", $RD, ['priority' => 4]);
ok($st === 403 && str_contains((string) ($j['error'] ?? ''), 'api:tasks:write'), 'read-only scope cannot PATCH, error names api:tasks:write');
[$st] = api('POST', 'tasks', $RD, ['project_id' => $projA, 'name' => 'x']);
ok($st === 403, 'read-only scope cannot create');
[$st] = api('DELETE', "tasks/$apiId", $WR);
ok($st === 403 && (int) q1($db, "SELECT COUNT(*) FROM tasks WHERE task_id = $apiId") === 1, 'write scope without api:tasks:delete cannot DELETE');
[$st, $j] = api('PATCH', "tasks/$apiId", $WR, ['priority' => 4]);
ok($st === 200 && $j['priority'] === 4, 'api:tasks:write -> PATCH');
[$st, $j] = api('POST', 'tasks', $WR + ['Idempotency-Key' => 'ptp1-create-0001'], ['project_id' => $projA, 'name' => 'Created by a write-scope client', 'priority' => 2]);
ok($st === 201 && $j['priority'] === 2, 'api:tasks:write client can create a task (with the Idempotency-Key the API asks of POST)', "HTTP $st");
// department scope: a client limited to Client A cannot touch B's task
[$st] = api('GET', "tasks/$bTask", $ADM);
ok($st === 200, 'administrator token reads any task');
$LIM = IntegrationAuth::createClient($db, 'ptp1 limited', null, ['api:tasks:read', 'api:tasks:write', 'api:tasks:delete'], '', null, 1, false, [$clientA]);
[, $rawL] = http('POST', "$BASE/api/v1/oauth/token", ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query(['grant_type' => 'client_credentials', 'client_id' => $LIM['client_uid'], 'client_secret' => $LIM['secret']]));
$LIMH = ['Authorization' => 'Bearer ' . (json_decode($rawL, true)['access_token'] ?? '')];
[$st] = api('GET', "tasks/$bTask", $LIMH);
ok($st === 403, 'a department-limited client cannot read another department\'s task');
[$st] = api('DELETE', "tasks/$bTask", $LIMH);
ok($st === 403 && (int) q1($db, "SELECT COUNT(*) FROM tasks WHERE task_id = $bTask") === 1, 'a department-limited client cannot delete another department\'s task');
// toggle unchanged
[$st, $j] = api('POST', "tasks/$apiId/toggle", $WR);
ok($st === 200 && $j['completed_at'] !== null && $j['progress'] === 100, 'toggle still completes (scope write)');
[$st] = api('POST', "tasks/$apiId/toggle", $WR);
// delete
$db->query("INSERT IGNORE INTO task_label_links SET task_id = $apiId, label_id = $gl");
[$st, $j] = api('DELETE', "tasks/$apiId", $DL);
ok($st === 200 && ($j['deleted'] ?? false) === true && (int) q1($db, "SELECT COUNT(*) FROM tasks WHERE task_id = $apiId") === 0 && (int) q1($db, "SELECT COUNT(*) FROM task_label_links WHERE task_id = $apiId") === 0, 'api:tasks:delete -> DELETE removes the task and its label links');
[$st] = api('DELETE', "tasks/$apiId", $DL);
ok($st === 404, 'deleting it again -> 404');
[$st] = api('GET', "tasks/$apiId", $ADM);
ok($st === 404, 'GET of a deleted task -> 404');

// ---------------------------------------------------------------- cleanup
section('Cleanup');
foreach (['task_label_links', 'task_labels', 'tasks', 'project_milestones', 'projects', 'user_client_permissions', 'integration_tokens', 'integration_client_secrets', 'integration_client_departments', 'integration_clients', 'api_tokens'] as $t) {
    $db->query("DELETE FROM `$t`");
}
$db->query("DELETE FROM clients WHERE client_name LIKE 'PTP1 %'");
$db->query("DELETE FROM users WHERE user_email LIKE '%@ptp1.test'");
$db->query("DELETE FROM user_role_permissions WHERE user_role_id = $leadRole");
$db->query("DELETE FROM user_roles WHERE role_id = $leadRole");
foreach ([$adminJar, $techJar, $leadJar] as $jar) {
    @unlink($jar);
}

echo "\n" . ($FAIL ? "FAILED (" . count($FAIL) . "): " . implode('; ', $FAIL) : "ALL PASSED") . "  [$PASS passed]\n";
exit($FAIL ? 1 : 0);
