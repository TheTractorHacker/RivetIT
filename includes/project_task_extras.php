<?php
/*
 * Project task helpers, phases 2 and 3 of the Vikunja-style upgrade (DB 2.6.208 and 2.6.209). Loaded by includes/project_tasks.php.
 *
 * Phase 2 (2.6.208)   checklists (task_checklist_items), subtasks (tasks.task_parent_id), comments (task_comments)
 * Phase 3 (2.6.209)   several assignees (task_assignees; tasks.task_assigned_to stays the primary), attachments (task_attachments),
 *                     reminders (tasks.task_reminder_at, delivered by cron/task_reminders.php), repeating tasks (tasks.task_repeat_*)
 *
 * Like phase 1, every function is safe on a database that has not run the migration yet (Update App before Update Database):
 * projectTaskPhase2Ready() / projectTaskPhase3Ready() are the switches the pages and handlers test, and every reader falls back to
 * the neutral value (no checklist, no comments, only the primary assignee ...) when its table or column is missing.
 *
 * Text that is stored is plain text: tags are stripped and the value is trimmed on the way in (projectTaskPlainText()), and it is
 * always escaped again on the way out. Queries that carry user text use prepared statements.
 */

// ---------------------------------------------------------------- readiness

function projectTaskTableExists($mysqli, string $table): bool
{
    static $known = [];
    if (!isset($known[$table])) {
        $res = @mysqli_query($mysqli, "SHOW TABLES LIKE '" . mysqli_real_escape_string($mysqli, $table) . "'");
        $known[$table] = (bool) ($res && mysqli_num_rows($res) > 0);
    }

    return $known[$table];
}

function projectTaskHasColumn($mysqli, string $column): bool
{
    static $known = [];
    if (!isset($known[$column])) {
        $res = @mysqli_query($mysqli, "SHOW COLUMNS FROM tasks LIKE '" . mysqli_real_escape_string($mysqli, $column) . "'");
        $known[$column] = (bool) ($res && mysqli_num_rows($res) > 0);
    }

    return $known[$column];
}

/** DB 2.6.208 has run: checklist, subtask and comment tables/columns exist. */
function projectTaskPhase2Ready($mysqli): bool
{
    return projectTaskHasColumn($mysqli, 'task_parent_id')
        && projectTaskTableExists($mysqli, 'task_checklist_items')
        && projectTaskTableExists($mysqli, 'task_comments');
}

/** DB 2.6.209 has run: assignees, attachments, reminder and repeat. */
function projectTaskPhase3Ready($mysqli): bool
{
    return projectTaskHasColumn($mysqli, 'task_reminder_at')
        && projectTaskHasColumn($mysqli, 'task_repeat_interval')
        && projectTaskTableExists($mysqli, 'task_assignees')
        && projectTaskTableExists($mysqli, 'task_attachments');
}

// ---------------------------------------------------------------- small shared helpers

/** Plain text for storing: valid UTF-8, no tags, trimmed, cut to $max characters. (No SQL escaping: callers use prepared statements.) */
function projectTaskPlainText($value, int $max): string
{
    $v = (string) $value;
    if ($v !== '' && !mb_check_encoding($v, 'UTF-8')) {
        $v = mb_convert_encoding($v, 'UTF-8', 'Windows-1252');
    }
    $v = trim(strip_tags($v));

    return mb_substr($v, 0, $max);
}

/** Is this user an administrator (role_is_admin)? Used for the "admins can delete any comment" rule. */
function projectTaskUserIsAdmin($mysqli, int $user_id): bool
{
    $stmt = mysqli_prepare($mysqli, 'SELECT r.role_is_admin FROM users u JOIN user_roles r ON r.role_id = u.user_role_id WHERE u.user_id = ? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'i', $user_id);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_row(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    return !empty($row[0]);
}

/**
 * Same rule as enforceClientAccess() in functions.php, as a yes/no (that function redirects, which a JSON or download endpoint cannot
 * use): an administrator and department 0 are always allowed, a user with no department rows may see all, otherwise the row must exist.
 */
function projectTaskClientAccessOk($mysqli, int $user_id, bool $is_admin, int $client_id): bool
{
    if ($is_admin || $client_id === 0) {
        return true;
    }
    if ($client_id < 0 || $user_id <= 0) {
        return false;
    }
    $any = mysqli_fetch_row(mysqli_query($mysqli, "SELECT 1 FROM user_client_permissions WHERE user_id = $user_id LIMIT 1"));
    if (!$any) {
        return true;
    }

    return (bool) mysqli_fetch_row(mysqli_query($mysqli, "SELECT 1 FROM user_client_permissions WHERE user_id = $user_id AND client_id = $client_id LIMIT 1"));
}

/** A task the panels apply to: a project task (it has a project and is not a ticket's checklist item). */
function projectTaskIsProjectTask(array $ctx): bool
{
    return !empty($ctx['project_id']) && empty($ctx['row']['task_ticket_id']);
}

// ---------------------------------------------------------------- checklist

/** @return array<int, array{item_id:int, item_text:string, item_done:int, item_order:int}> */
function projectTaskChecklist($mysqli, int $task_id): array
{
    if (!projectTaskPhase2Ready($mysqli)) {
        return [];
    }
    $out = [];
    $res = mysqli_query($mysqli, "SELECT item_id, item_text, item_done, item_order FROM task_checklist_items WHERE item_task_id = $task_id ORDER BY item_order ASC, item_id ASC");
    while ($r = mysqli_fetch_assoc($res)) {
        $out[] = ['item_id' => intval($r['item_id']), 'item_text' => (string) $r['item_text'], 'item_done' => intval($r['item_done']), 'item_order' => intval($r['item_order'])];
    }

    return $out;
}

/** Add an item at the end. Returns the new id, 0 when the text is empty or the table is missing. */
function projectTaskChecklistAdd($mysqli, int $task_id, string $text): int
{
    $text = projectTaskPlainText($text, 255);
    if ($text === '' || !projectTaskPhase2Ready($mysqli)) {
        return 0;
    }
    $order = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COALESCE(MAX(item_order), 0) + 1 FROM task_checklist_items WHERE item_task_id = $task_id"))[0] ?? 1);
    $stmt = mysqli_prepare($mysqli, 'INSERT INTO task_checklist_items (item_task_id, item_text, item_order) VALUES (?, ?, ?)');
    mysqli_stmt_bind_param($stmt, 'isi', $task_id, $text, $order);
    mysqli_stmt_execute($stmt);
    $id = intval(mysqli_stmt_insert_id($stmt));
    mysqli_stmt_close($stmt);

    return $id;
}

/** Change an item's text and/or done flag (null = leave). The item must belong to $task_id. Returns true when the item exists. */
function projectTaskChecklistUpdate($mysqli, int $task_id, int $item_id, ?string $text, ?bool $done): bool
{
    if (!projectTaskPhase2Ready($mysqli)) {
        return false;
    }
    $exists = mysqli_fetch_row(mysqli_query($mysqli, "SELECT 1 FROM task_checklist_items WHERE item_id = $item_id AND item_task_id = $task_id"));
    if (!$exists) {
        return false;
    }
    if ($text !== null) {
        $text = projectTaskPlainText($text, 255);
        if ($text !== '') {
            $stmt = mysqli_prepare($mysqli, 'UPDATE task_checklist_items SET item_text = ? WHERE item_id = ? AND item_task_id = ?');
            mysqli_stmt_bind_param($stmt, 'sii', $text, $item_id, $task_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }
    if ($done !== null) {
        $flag = $done ? 1 : 0;
        mysqli_query($mysqli, "UPDATE task_checklist_items SET item_done = $flag, item_done_at = " . ($flag ? 'COALESCE(item_done_at, NOW())' : 'NULL') . " WHERE item_id = $item_id AND item_task_id = $task_id");
    }

    return true;
}

function projectTaskChecklistDelete($mysqli, int $task_id, int $item_id): bool
{
    if (!projectTaskPhase2Ready($mysqli)) {
        return false;
    }
    mysqli_query($mysqli, "DELETE FROM task_checklist_items WHERE item_id = $item_id AND item_task_id = $task_id");

    return mysqli_affected_rows($mysqli) > 0;
}

/** Put the items in the order of $ids (ids of other tasks' items are ignored; items not listed keep their relative order at the end). */
function projectTaskChecklistReorder($mysqli, int $task_id, array $ids): void
{
    if (!projectTaskPhase2Ready($mysqli)) {
        return;
    }
    $current = array_column(projectTaskChecklist($mysqli, $task_id), 'item_id');
    $wanted = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => in_array($i, $current, true))));
    $final = array_merge($wanted, array_values(array_diff($current, $wanted)));
    foreach ($final as $pos => $item_id) {
        mysqli_query($mysqli, 'UPDATE task_checklist_items SET item_order = ' . ($pos + 1) . " WHERE item_id = $item_id AND item_task_id = $task_id");
    }
}

/** Move one item a step up (-1) or down (+1). */
function projectTaskChecklistMove($mysqli, int $task_id, int $item_id, int $direction): void
{
    $ids = array_column(projectTaskChecklist($mysqli, $task_id), 'item_id');
    $i = array_search($item_id, $ids, true);
    $j = $i === false ? false : $i + ($direction < 0 ? -1 : 1);
    if ($i === false || $j < 0 || $j >= count($ids)) {
        return;
    }
    [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
    projectTaskChecklistReorder($mysqli, $task_id, $ids);
}

// ---------------------------------------------------------------- subtasks

/** @return array<int, array{task_id:int, task_name:string, done:bool}> */
function projectTaskSubtasks($mysqli, int $parent_id): array
{
    if (!projectTaskPhase2Ready($mysqli)) {
        return [];
    }
    $out = [];
    $res = mysqli_query($mysqli, "SELECT task_id, task_name, task_completed_at FROM tasks WHERE task_parent_id = $parent_id ORDER BY task_order ASC, task_id ASC");
    while ($r = mysqli_fetch_assoc($res)) {
        $out[] = ['task_id' => intval($r['task_id']), 'task_name' => (string) $r['task_name'], 'done' => $r['task_completed_at'] !== null];
    }

    return $out;
}

/**
 * Make $task_id a subtask of $parent_id (or a top-level task again with 0/null). Rules, all checked here so every caller (pop-up, API)
 * gets them: both are project tasks of the SAME project, a task is not its own parent, no cycle (walked up the chain), and only one level
 * deep (a subtask has no subtasks of its own, a task that has subtasks cannot become one). Returns '' on success or the reason.
 */
function projectTaskSetParent($mysqli, int $task_id, ?int $parent_id): string
{
    if (!projectTaskPhase2Ready($mysqli)) {
        return 'Subtasks turn on after an administrator runs Update Database.';
    }
    $task = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT task_id, task_project_id, task_ticket_id FROM tasks WHERE task_id = $task_id LIMIT 1"));
    if (!$task) {
        return 'That task no longer exists.';
    }
    if (!$parent_id) {
        mysqli_query($mysqli, "UPDATE tasks SET task_parent_id = NULL WHERE task_id = $task_id");

        return '';
    }
    if ($parent_id === $task_id) {
        return 'A task cannot be its own parent.';
    }
    $parent = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT task_id, task_project_id, task_ticket_id, task_parent_id FROM tasks WHERE task_id = $parent_id LIMIT 1"));
    if (!$parent) {
        return 'The parent task does not exist.';
    }
    if (!intval($task['task_project_id']) || intval($task['task_ticket_id']) || !intval($parent['task_project_id']) || intval($parent['task_ticket_id'])) {
        return 'Only project tasks can have subtasks.';
    }
    if (intval($task['task_project_id']) !== intval($parent['task_project_id'])) {
        return 'A subtask must be in the same project as its parent.';
    }
    // Cycle: walk up from the proposed parent; if we meet the task, the link would close a loop
    $seen = [];
    $cursor = $parent_id;
    while ($cursor && !isset($seen[$cursor])) {
        if ($cursor === $task_id) {
            return 'That would make the task its own ancestor.';
        }
        $seen[$cursor] = true;
        $next = mysqli_fetch_row(mysqli_query($mysqli, "SELECT task_parent_id FROM tasks WHERE task_id = $cursor LIMIT 1"));
        $cursor = $next && $next[0] !== null ? intval($next[0]) : 0;
    }
    if ($parent['task_parent_id'] !== null) {
        return 'Subtasks can only go one level deep: that task is already a subtask.';
    }
    $has_children = mysqli_fetch_row(mysqli_query($mysqli, "SELECT 1 FROM tasks WHERE task_parent_id = $task_id LIMIT 1"));
    if ($has_children) {
        return 'That task has subtasks of its own, so it cannot become a subtask.';
    }
    mysqli_query($mysqli, "UPDATE tasks SET task_parent_id = $parent_id WHERE task_id = $task_id");

    return '';
}

/** Create a subtask (name only; same project and milestone as the parent, lane To Do). Returns the new task id, 0 on failure. */
function projectTaskAddSubtask($mysqli, array $parent_ctx, string $name): int
{
    $name = projectTaskPlainText($name, 255);
    if ($name === '' || !projectTaskPhase2Ready($mysqli) || !projectTaskIsProjectTask($parent_ctx) || $parent_ctx['row']['task_parent_id'] !== null) {
        return 0;
    }
    $parent_id = intval($parent_ctx['row']['task_id']);
    $project_id = intval($parent_ctx['project_id']);
    $milestone = $parent_ctx['row']['task_milestone_id'] !== null ? intval($parent_ctx['row']['task_milestone_id']) : null;
    $order = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COALESCE(MAX(task_order), 0) + 1 FROM tasks WHERE task_parent_id = $parent_id"))[0] ?? 1);
    $stmt = mysqli_prepare($mysqli, "INSERT INTO tasks (task_name, task_status, task_order, task_project_id, task_milestone_id, task_parent_id) VALUES (?, 'To Do', ?, ?, ?, ?)");
    mysqli_stmt_bind_param($stmt, 'siiii', $name, $order, $project_id, $milestone, $parent_id);
    mysqli_stmt_execute($stmt);
    $id = intval(mysqli_stmt_insert_id($stmt));
    mysqli_stmt_close($stmt);

    return $id;
}

/** A task's subtasks stop being subtasks (they stay in the project as ordinary tasks). Used when the parent is deleted. */
function projectTaskReleaseSubtasks($mysqli, int $parent_id): void
{
    if (projectTaskHasColumn($mysqli, 'task_parent_id')) {
        mysqli_query($mysqli, "UPDATE tasks SET task_parent_id = NULL WHERE task_parent_id = $parent_id");
    }
}

/** Complete (true) or re-open (false) a task by the same rules as the Kanban lanes; completing runs the repeat hook. Returns the id of a repeated copy, 0 if none. */
function projectTaskSetDone($mysqli, int $task_id, bool $done, int $user_id): int
{
    $sql = projectTaskStatusSql($mysqli, $done ? 'Done' : 'To Do', $user_id);
    mysqli_query($mysqli, "UPDATE tasks SET $sql WHERE task_id = $task_id");

    return $done ? projectTaskCompleted($mysqli, $task_id, $user_id) : 0;
}

// ---------------------------------------------------------------- comments

/** @return array<int, array{comment_id:int, comment_user_id:?int, user_name:string, comment_body:string, comment_created_at:string, comment_updated_at:?string}> */
function projectTaskComments($mysqli, int $task_id): array
{
    if (!projectTaskPhase2Ready($mysqli)) {
        return [];
    }
    $out = [];
    $res = mysqli_query($mysqli, "SELECT c.comment_id, c.comment_user_id, c.comment_body, c.comment_created_at, c.comment_updated_at, u.user_name
        FROM task_comments c LEFT JOIN users u ON u.user_id = c.comment_user_id WHERE c.comment_task_id = $task_id ORDER BY c.comment_created_at ASC, c.comment_id ASC");
    while ($r = mysqli_fetch_assoc($res)) {
        $out[] = [
            'comment_id' => intval($r['comment_id']),
            'comment_user_id' => $r['comment_user_id'] === null ? null : intval($r['comment_user_id']),
            'user_name' => $r['user_name'] !== null && $r['user_name'] !== '' ? (string) $r['user_name'] : 'Former user',
            'comment_body' => (string) $r['comment_body'],
            'comment_created_at' => (string) $r['comment_created_at'],
            'comment_updated_at' => $r['comment_updated_at'] === null ? null : (string) $r['comment_updated_at'],
        ];
    }

    return $out;
}

/** Add a comment. Returns the new id, 0 when the body is empty or the table is missing. */
function projectTaskCommentAdd($mysqli, int $task_id, int $user_id, string $body): int
{
    $body = projectTaskPlainText($body, 20000);
    if ($body === '' || !projectTaskPhase2Ready($mysqli)) {
        return 0;
    }
    $stmt = mysqli_prepare($mysqli, 'INSERT INTO task_comments (comment_task_id, comment_user_id, comment_body) VALUES (?, ?, ?)');
    mysqli_stmt_bind_param($stmt, 'iis', $task_id, $user_id, $body);
    mysqli_stmt_execute($stmt);
    $id = intval(mysqli_stmt_insert_id($stmt));
    mysqli_stmt_close($stmt);

    return $id;
}

/** Edit a comment; only its author may. Returns '' on success or the reason. */
function projectTaskCommentEdit($mysqli, int $task_id, int $comment_id, int $user_id, string $body): string
{
    $row = projectTaskCommentRow($mysqli, $task_id, $comment_id);
    if (!$row) {
        return 'That comment no longer exists.';
    }
    if ($row['comment_user_id'] === null || intval($row['comment_user_id']) !== $user_id) {
        return 'You can only edit your own comments.';
    }
    $body = projectTaskPlainText($body, 20000);
    if ($body === '') {
        return 'A comment cannot be empty.';
    }
    $stmt = mysqli_prepare($mysqli, 'UPDATE task_comments SET comment_body = ?, comment_updated_at = NOW() WHERE comment_id = ? AND comment_task_id = ?');
    mysqli_stmt_bind_param($stmt, 'sii', $body, $comment_id, $task_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    return '';
}

/** Delete a comment; its author or an administrator may. Returns '' on success or the reason. */
function projectTaskCommentDelete($mysqli, int $task_id, int $comment_id, int $user_id, bool $is_admin): string
{
    $row = projectTaskCommentRow($mysqli, $task_id, $comment_id);
    if (!$row) {
        return 'That comment no longer exists.';
    }
    $own = $row['comment_user_id'] !== null && intval($row['comment_user_id']) === $user_id;
    if (!$own && !$is_admin) {
        return 'You can only delete your own comments.';
    }
    mysqli_query($mysqli, "DELETE FROM task_comments WHERE comment_id = $comment_id AND comment_task_id = $task_id");

    return '';
}

function projectTaskCommentRow($mysqli, int $task_id, int $comment_id): ?array
{
    if (!projectTaskPhase2Ready($mysqli)) {
        return null;
    }
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT comment_id, comment_user_id FROM task_comments WHERE comment_id = $comment_id AND comment_task_id = $task_id LIMIT 1"));

    return $row ?: null;
}

// ---------------------------------------------------------------- assignees

/**
 * Everyone on each task, primary first, in one query. Before 2.6.209 (or for a row written by something that only knows
 * task_assigned_to) the primary assignee is still included, so the answer is right on any database.
 * @param int[] $task_ids
 * @return array<int, array<int, array{user_id:int, user_name:string, primary:bool}>>  task_id => people
 */
function projectTaskAssigneesByTask($mysqli, array $task_ids): array
{
    $task_ids = array_values(array_unique(array_filter(array_map('intval', $task_ids))));
    if (!$task_ids) {
        return [];
    }
    $list = implode(',', $task_ids);
    $sql = "SELECT t.task_id, t.task_assigned_to AS user_id, 1 AS is_primary FROM tasks t WHERE t.task_id IN ($list) AND t.task_assigned_to IS NOT NULL AND t.task_assigned_to > 0";
    if (projectTaskTableExists($mysqli, 'task_assignees')) {
        $sql .= " UNION SELECT a.task_id, a.user_id, 0 FROM task_assignees a WHERE a.task_id IN ($list)";
    }
    $primary = [];
    $people = [];
    $res = mysqli_query($mysqli, $sql);
    while ($r = mysqli_fetch_assoc($res)) {
        $tid = intval($r['task_id']);
        $uid = intval($r['user_id']);
        if (!empty($r['is_primary'])) {
            $primary[$tid] = $uid;
        }
        $people[$tid][$uid] = true;
    }
    if (!$people) {
        return [];
    }
    $user_ids = [];
    foreach ($people as $set) {
        $user_ids += $set;
    }
    $names = [];
    $res = mysqli_query($mysqli, 'SELECT user_id, user_name FROM users WHERE user_id IN (' . implode(',', array_keys($user_ids)) . ')');
    while ($r = mysqli_fetch_assoc($res)) {
        $names[intval($r['user_id'])] = (string) $r['user_name'];
    }
    $out = [];
    foreach ($people as $tid => $set) {
        $ids = array_keys($set);
        usort($ids, function ($a, $b) use ($primary, $tid, $names) {
            $pa = ($primary[$tid] ?? 0) === $a ? 0 : 1;
            $pb = ($primary[$tid] ?? 0) === $b ? 0 : 1;

            return $pa <=> $pb ?: strcasecmp($names[$a] ?? '', $names[$b] ?? '');
        });
        foreach ($ids as $uid) {
            $out[$tid][] = ['user_id' => $uid, 'user_name' => $names[$uid] ?? 'Former user', 'primary' => ($primary[$tid] ?? 0) === $uid];
        }
    }

    return $out;
}

/** Keep only ids of active, non-archived users. @return int[] */
function projectTaskActiveUserIds($mysqli, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
    if (!$ids) {
        return [];
    }
    $res = mysqli_query($mysqli, 'SELECT user_id FROM users WHERE user_status = 1 AND user_archived_at IS NULL AND user_id IN (' . implode(',', $ids) . ')');
    $ok = [];
    while ($r = mysqli_fetch_row($res)) {
        $ok[intval($r[0])] = true;
    }

    return array_values(array_filter($ids, fn($i) => isset($ok[$i])));
}

/**
 * Bring task_assignees in line with the primary column after a writer has set tasks.task_assigned_to to $new_primary.
 *  - $others === null   (an older client that only knows the primary): the rest of the team stays, the previous primary is replaced.
 *  - $others = [...]    (the pop-up / API assignee list): the team is exactly $new_primary plus $others; when there is no primary the
 *                       first of $others becomes the primary, so the column and the table never disagree.
 * No-op before 2.6.209. Returns the final list of user ids (primary first).
 * @return int[]
 */
function projectTaskSyncAssignees($mysqli, int $task_id, int $new_primary, int $old_primary, ?array $others): array
{
    if (!projectTaskPhase3Ready($mysqli)) {
        return $new_primary > 0 ? [$new_primary] : [];
    }
    if ($others === null) {
        if ($new_primary <= 0) {
            $final = [];                      // "unassigned" from an older client clears the whole team
        } else {
            $existing = array_map('intval', array_column(mysqli_fetch_all(mysqli_query($mysqli, "SELECT user_id FROM task_assignees WHERE task_id = $task_id"), MYSQLI_ASSOC), 'user_id'));
            $keep = array_values(array_diff($existing, [$old_primary, $new_primary]));
            $primary_ok = $new_primary === $old_primary || projectTaskActiveUserIds($mysqli, [$new_primary]);
            $final = $primary_ok ? array_merge([$new_primary], $keep) : $keep;
        }
    } else {
        $final = projectTaskActiveUserIds($mysqli, array_merge($new_primary > 0 ? [$new_primary] : [], $others));
    }
    $primary = $final ? (in_array($new_primary, $final, true) ? $new_primary : $final[0]) : 0;
    $final = $primary ? array_values(array_unique(array_merge([$primary], $final))) : [];

    mysqli_query($mysqli, "DELETE FROM task_assignees WHERE task_id = $task_id");
    foreach ($final as $uid) {
        mysqli_query($mysqli, "INSERT IGNORE INTO task_assignees (task_id, user_id) VALUES ($task_id, " . intval($uid) . ')');
    }
    mysqli_query($mysqli, 'UPDATE tasks SET task_assigned_to = ' . ($primary ?: 'NULL') . " WHERE task_id = $task_id");

    return $final;
}

// ---------------------------------------------------------------- attachments

const PROJECT_TASK_ATTACHMENT_MAX_BYTES = 26214400;   // 25 MB per file (the web server / php.ini limit may be lower)
const PROJECT_TASK_ATTACHMENT_MAX_FILES = 50;         // per task

/** Where task files live. Not served by the web server: only agent/project_task_attachment.php and the API read them. */
function projectTaskAttachmentDir(): string
{
    return dirname(__DIR__) . '/uploads/task_attachments/';
}

/** Extensions never accepted (code the server or a browser would run, installers, scripts, shortcuts). Every dot-separated part is checked. */
function projectTaskBlockedExtensions(): array
{
    return ['php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phar', 'phps', 'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'zsh', 'csh', 'exe', 'dll', 'com', 'bat', 'cmd',
        'msi', 'scr', 'pif', 'vbs', 'vbe', 'js', 'jse', 'mjs', 'wsf', 'wsh', 'ps1', 'psm1', 'hta', 'lnk', 'reg', 'jar', 'war', 'jsp', 'jspx', 'asp', 'aspx', 'cer', 'html', 'htm',
        'xhtml', 'shtml', 'svg', 'svgz', 'xml', 'xsl', 'swf', 'htaccess', 'htpasswd', 'ini', 'inc', 'so', 'bin', 'apk', 'app', 'deb', 'rpm'];
}

/** Clean display name for an uploaded file: no path, no control characters or tags, at most 200 characters. '' when nothing usable is left. */
function projectTaskAttachmentCleanName($raw): string
{
    $name = str_replace('\\', '/', (string) $raw);
    $name = basename($name);
    $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
    $name = trim(strip_tags($name));
    if (!mb_check_encoding($name, 'UTF-8')) {
        $name = '';
    }

    return mb_substr($name, 0, 200);
}

/** '' when the file name is acceptable, otherwise the reason. */
function projectTaskAttachmentNameProblem(string $name): string
{
    if ($name === '' || $name === '.' || $name === '..') {
        return 'The file needs a name.';
    }
    foreach (explode('.', strtolower($name)) as $i => $part) {
        if ($i > 0 || str_starts_with($name, '.')) {
            if (in_array(trim($part), projectTaskBlockedExtensions(), true)) {
                return 'That file type is not allowed on tasks (' . strtolower(trim($part)) . ').';
            }
        }
    }

    return '';
}

/** @return array<int, array{attachment_id:int, attachment_name:string, attachment_size:int, attachment_mime:?string, user_name:?string, attachment_created_at:string}> */
function projectTaskAttachments($mysqli, int $task_id): array
{
    if (!projectTaskPhase3Ready($mysqli)) {
        return [];
    }
    $out = [];
    $res = mysqli_query($mysqli, "SELECT a.attachment_id, a.attachment_name, a.attachment_size, a.attachment_mime, a.attachment_uploaded_by, a.attachment_created_at, u.user_name
        FROM task_attachments a LEFT JOIN users u ON u.user_id = a.attachment_uploaded_by WHERE a.attachment_task_id = $task_id ORDER BY a.attachment_id ASC");
    while ($r = mysqli_fetch_assoc($res)) {
        $out[] = ['attachment_id' => intval($r['attachment_id']), 'attachment_name' => (string) $r['attachment_name'], 'attachment_size' => intval($r['attachment_size']),
            'attachment_mime' => $r['attachment_mime'], 'attachment_uploaded_by' => $r['attachment_uploaded_by'] === null ? null : intval($r['attachment_uploaded_by']),
            'user_name' => $r['user_name'], 'attachment_created_at' => (string) $r['attachment_created_at']];
    }

    return $out;
}

/**
 * Store one uploaded file ($_FILES entry) on a task. Returns ['ok' => true, 'id' => n] or ['ok' => false, 'error' => reason].
 * The bytes go to uploads/task_attachments/<random 40 hex>: no extension and never a name the visitor chose, so nothing in that
 * directory can be run as a script or guessed. The original name is kept in the database for the download.
 */
function projectTaskAttachmentStore($mysqli, array $ctx, array $file, int $user_id): array
{
    if (!projectTaskPhase3Ready($mysqli)) {
        return ['ok' => false, 'error' => 'Attachments turn on after an administrator runs Update Database.'];
    }
    if (!projectTaskIsProjectTask($ctx)) {
        return ['ok' => false, 'error' => 'Files can only be attached to project tasks.'];
    }
    $err = intval($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        return ['ok' => false, 'error' => 'That file is larger than the server allows.'];
    }
    if ($err !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'error' => 'The file did not upload. Try again.'];
    }
    $size = intval($file['size'] ?? 0);
    if ($size <= 0) {
        return ['ok' => false, 'error' => 'That file is empty.'];
    }
    if ($size > PROJECT_TASK_ATTACHMENT_MAX_BYTES) {
        return ['ok' => false, 'error' => 'Files can be up to ' . intval(PROJECT_TASK_ATTACHMENT_MAX_BYTES / 1048576) . ' MB.'];
    }
    $name = projectTaskAttachmentCleanName($file['name'] ?? '');
    $problem = projectTaskAttachmentNameProblem($name);
    if ($problem !== '') {
        return ['ok' => false, 'error' => $problem];
    }
    $task_id = intval($ctx['row']['task_id']);
    $count = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM task_attachments WHERE attachment_task_id = $task_id"))[0] ?? 0);
    if ($count >= PROJECT_TASK_ATTACHMENT_MAX_FILES) {
        return ['ok' => false, 'error' => 'A task can hold up to ' . PROJECT_TASK_ATTACHMENT_MAX_FILES . ' files.'];
    }

    $dir = projectTaskAttachmentDir();
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        return ['ok' => false, 'error' => 'The upload folder could not be created.'];
    }
    $reference = bin2hex(random_bytes(20));
    if (!move_uploaded_file($file['tmp_name'], $dir . $reference)) {
        return ['ok' => false, 'error' => 'The file could not be saved.'];
    }
    @chmod($dir . $reference, 0640);
    $mime = null;
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $m = $fi ? finfo_file($fi, $dir . $reference) : false;
        $mime = is_string($m) ? mb_substr($m, 0, 100) : null;
    }
    $stmt = mysqli_prepare($mysqli, 'INSERT INTO task_attachments (attachment_task_id, attachment_name, attachment_reference, attachment_size, attachment_mime, attachment_uploaded_by) VALUES (?, ?, ?, ?, ?, ?)');
    mysqli_stmt_bind_param($stmt, 'issisi', $task_id, $name, $reference, $size, $mime, $user_id);
    $okq = mysqli_stmt_execute($stmt);
    $id = intval(mysqli_stmt_insert_id($stmt));
    mysqli_stmt_close($stmt);
    if (!$okq) {
        @unlink($dir . $reference);

        return ['ok' => false, 'error' => 'The file could not be recorded.'];
    }

    return ['ok' => true, 'id' => $id];
}

/** Absolute path of an attachment's bytes, or null when the row or file is missing or the reference is not one we generated. */
function projectTaskAttachmentPath(array $row): ?string
{
    $ref = (string) ($row['attachment_reference'] ?? '');
    if (!preg_match('/^[0-9a-f]{40}$/', $ref)) {
        return null;
    }
    $path = projectTaskAttachmentDir() . $ref;

    return is_file($path) ? $path : null;
}

function projectTaskAttachmentRow($mysqli, int $task_id, int $attachment_id): ?array
{
    if (!projectTaskPhase3Ready($mysqli)) {
        return null;
    }
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM task_attachments WHERE attachment_id = $attachment_id AND attachment_task_id = $task_id LIMIT 1"));

    return $row ?: null;
}

/** Delete one attachment (row and file). */
function projectTaskAttachmentDelete($mysqli, int $task_id, int $attachment_id): bool
{
    $row = projectTaskAttachmentRow($mysqli, $task_id, $attachment_id);
    if (!$row) {
        return false;
    }
    $path = projectTaskAttachmentPath($row);
    mysqli_query($mysqli, "DELETE FROM task_attachments WHERE attachment_id = $attachment_id AND attachment_task_id = $task_id");
    if ($path) {
        @unlink($path);
    }

    return true;
}

/** Remove the files of every attachment of a task (the rows go with the task by foreign key). */
function projectTaskAttachmentsPurge($mysqli, int $task_id): void
{
    if (!projectTaskPhase3Ready($mysqli)) {
        return;
    }
    foreach (mysqli_fetch_all(mysqli_query($mysqli, "SELECT attachment_reference FROM task_attachments WHERE attachment_task_id = $task_id"), MYSQLI_ASSOC) as $row) {
        $path = projectTaskAttachmentPath($row);
        if ($path) {
            @unlink($path);
        }
    }
    mysqli_query($mysqli, "DELETE FROM task_attachments WHERE attachment_task_id = $task_id");
}

/** Send an attachment to the browser: forced download, never rendered, never sniffed. Ends the request. */
function projectTaskAttachmentServe(array $row): void
{
    $path = projectTaskAttachmentPath($row);
    if (!$path) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'File not found.';
        exit;
    }
    $name = projectTaskAttachmentCleanName($row['attachment_name'] ?? '') ?: 'attachment';
    $ascii = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/octet-stream');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
}

// ---------------------------------------------------------------- reminders

/** A posted/API date-time ('Y-m-d\TH:i', 'Y-m-d H:i[:s]') as 'Y-m-d H:i:s', or null when empty. Returns false for something that is not a date-time. */
function projectTaskNormalizeReminder($value)
{
    $v = trim((string) $value);
    if ($v === '') {
        return null;
    }
    $v = str_replace('T', ' ', $v);
    foreach (['Y-m-d H:i:s', 'Y-m-d H:i'] as $fmt) {
        $d = DateTime::createFromFormat('!' . $fmt, $v);
        if ($d && $d->format($fmt) === $v) {
            return $d->format('Y-m-d H:i:s');
        }
    }

    return false;
}

/**
 * Reminder time from a "relative to the due date" choice: '0' on the due date at 09:00, '1d' a day before, '1w' a week before.
 * Null when there is no due date or the choice is not one of these.
 */
function projectTaskReminderFromDue(?string $due, string $choice): ?string
{
    if (!$due || !in_array($choice, ['0', '1d', '1w'], true)) {
        return null;
    }
    $d = DateTime::createFromFormat('!Y-m-d', $due);
    if (!$d) {
        return null;
    }
    $d->setTime(9, 0, 0);
    if ($choice === '1d') {
        $d->modify('-1 day');
    } elseif ($choice === '1w') {
        $d->modify('-7 days');
    }

    return $d->format('Y-m-d H:i:s');
}

/**
 * Deliver due reminders: every open project task whose reminder time has passed and has not been delivered yet. Each task is claimed
 * with a single UPDATE (task_reminder_sent_at) before anything is sent, so two overlapping cron runs cannot both send it. Everyone on
 * the task is told; a task nobody is on goes to the administrators (same fallback as the CRM reminders).
 * $notify(?int $user_id, string $type, string $details, string $action, int $client_id, int $task_id) delivers one notification
 * (user_id null = administrators); the default is the app's in-app + push notifications. Returns how many tasks were reminded.
 */
function projectTaskProcessReminders($mysqli, ?callable $notify = null): int
{
    if (!projectTaskPhase3Ready($mysqli)) {
        return 0;
    }
    $notify = $notify ?? function (?int $user_id, string $type, string $details, string $action, int $client_id, int $task_id): void {
        if ($user_id) {
            notifyUser($user_id, $type, $details, $action, $client_id, $task_id);
        } else {
            appNotify($type, $details, $action, $client_id, $task_id);
        }
    };
    $sent = 0;
    $res = mysqli_query($mysqli, "SELECT task_id FROM tasks WHERE task_reminder_at IS NOT NULL AND task_reminder_at <= NOW() AND task_reminder_sent_at IS NULL AND task_completed_at IS NULL AND task_project_id IS NOT NULL AND task_ticket_id IS NULL ORDER BY task_reminder_at ASC LIMIT 500");
    $ids = array_map('intval', array_column(mysqli_fetch_all($res, MYSQLI_ASSOC), 'task_id'));
    foreach ($ids as $task_id) {
        mysqli_query($mysqli, "UPDATE tasks SET task_reminder_sent_at = NOW() WHERE task_id = $task_id AND task_reminder_sent_at IS NULL");
        if (mysqli_affected_rows($mysqli) !== 1) {
            continue;   // another run took it
        }
        $ctx = projectTaskContext($mysqli, $task_id);
        if (!$ctx) {
            continue;
        }
        $row = $ctx['row'];
        $project = mysqli_fetch_assoc(mysqli_query($mysqli, 'SELECT project_prefix, project_number, project_name FROM projects WHERE project_id = ' . intval($ctx['project_id']) . ' LIMIT 1'));
        $where = $project ? trim(($project['project_prefix'] ?? '') . ($project['project_number'] ?? '') . ' ' . ($project['project_name'] ?? '')) : '';
        $details = 'Reminder: ' . $row['task_name'] . ($where !== '' ? " ($where)" : '') . ($row['task_due'] ? ' - due ' . $row['task_due'] : '');
        $action = 'project_kanban.php?' . ($ctx['client_id'] ? 'client_id=' . $ctx['client_id'] . '&' : '') . 'project_id=' . intval($ctx['project_id']);
        $people = projectTaskAssigneesByTask($mysqli, [$task_id])[$task_id] ?? [];
        if ($people) {
            foreach ($people as $p) {
                $notify($p['user_id'], 'Task Reminder', $details, $action, intval($ctx['client_id']), $task_id);
            }
        } else {
            $notify(null, 'Task Reminder', $details, $action, intval($ctx['client_id']), $task_id);
        }
        $sent++;
    }

    return $sent;
}

// ---------------------------------------------------------------- repeating tasks

function projectTaskRepeatUnits(): array
{
    return ['day' => 'day(s)', 'week' => 'week(s)', 'month' => 'month(s)', 'year' => 'year(s)'];
}

/** @return array{0:int,1:string} [interval 0-999 (0 = does not repeat), unit] */
function projectTaskNormalizeRepeat($interval, $unit): array
{
    $n = max(0, min(999, intval($interval)));
    $u = (string) $unit;
    if (!isset(projectTaskRepeatUnits()[$u])) {
        $u = 'day';
    }

    return [$n, $n > 0 ? $u : 'day'];
}

/** 'Y-m-d' plus $n units. Months and years clamp to the last day of the target month (31 Jan + 1 month = 28/29 Feb, not 3 Mar). */
function projectTaskShiftDate(string $ymd, int $n, string $unit): string
{
    $d = DateTime::createFromFormat('!Y-m-d', $ymd);
    if (!$d) {
        return $ymd;
    }
    if ($unit === 'day' || $unit === 'week') {
        $d->modify(sprintf('%+d day', $unit === 'week' ? $n * 7 : $n));

        return $d->format('Y-m-d');
    }
    $months = $unit === 'year' ? $n * 12 : $n;
    $day = intval($d->format('j'));
    $d->setDate(intval($d->format('Y')), intval($d->format('n')), 1);
    $d->modify(sprintf('%+d month', $months));
    $d->setDate(intval($d->format('Y')), intval($d->format('n')), min($day, intval($d->format('t'))));

    return $d->format('Y-m-d');
}

/**
 * The next due date of a repeating task completed on $today. A task with a due date repeats from that date: due + n units, and while
 * that is already in the past (completed late) it keeps stepping by n units from the ORIGINAL date until it is today or later, so a
 * weekly task finished three weeks late is due next week, not three weeks ago. A task without a due date is due $today + n units.
 */
function projectTaskNextDue(?string $due, int $n, string $unit, string $today): string
{
    if (!$due) {
        return projectTaskShiftDate($today, $n, $unit);
    }
    for ($k = 1; $k <= 2000; $k++) {
        $next = projectTaskShiftDate($due, $k * $n, $unit);
        if ($next >= $today) {
            return $next;
        }
    }

    return projectTaskShiftDate($today, $n, $unit);
}

/**
 * Hook called whenever a project task becomes completed (Kanban drop on Done, Mark complete, the pop-up's status, API toggle / status,
 * ticking a subtask). If the task repeats, creates the next occurrence once: a copy in lane To Do with the due date (and start date and
 * reminder) shifted, checklist items unticked, same labels / assignees / parent. Chosen over a cron sweep because it is immediate and
 * needs no scheduling; the claim UPDATE (task_repeat_spawned_at) makes it fire at most once per task even if it is completed,
 * re-opened and completed again, or two requests race. Returns the new task id, 0 when nothing was created.
 */
function projectTaskCompleted($mysqli, int $task_id, int $user_id, ?string $today = null): int
{
    if (!projectTaskPhase3Ready($mysqli)) {
        return 0;
    }
    mysqli_query($mysqli, "UPDATE tasks SET task_repeat_spawned_at = NOW() WHERE task_id = $task_id AND task_repeat_interval > 0 AND task_repeat_spawned_at IS NULL AND task_completed_at IS NOT NULL AND task_ticket_id IS NULL AND task_project_id IS NOT NULL");
    if (mysqli_affected_rows($mysqli) !== 1) {
        return 0;
    }
    $ctx = projectTaskContext($mysqli, $task_id);
    if (!$ctx) {
        return 0;
    }
    $src = $ctx['row'];
    $today = $today ?? date('Y-m-d');
    $n = intval($src['task_repeat_interval']);
    $unit = (string) $src['task_repeat_unit'];
    $due = $src['task_due'] ?: null;
    $next_due = projectTaskNextDue($due, $n, $unit, $today);
    $shift_days = $due ? intval((new DateTime($due))->diff(new DateTime($next_due))->format('%r%a')) : null;

    $o = ['suffix' => '', 'due' => $next_due, 'copy_children' => false, 'reset_checklist' => true, 'keep_parent' => true];
    if ($src['task_start'] && $shift_days !== null) {
        $o['start'] = projectTaskShiftDate($src['task_start'], $shift_days, 'day');
    } elseif ($src['task_start']) {
        $o['start'] = $src['task_start'];
    }
    if ($src['task_reminder_at'] && $shift_days !== null) {
        $r = new DateTime($src['task_reminder_at']);
        $r->modify(sprintf('%+d day', $shift_days));
        $o['reminder'] = $r->format('Y-m-d H:i:s');
    }

    return projectTaskCopy($mysqli, $ctx, $o);
}

// ---------------------------------------------------------------- counts for cards and the API

/**
 * The little numbers on a Kanban card, in a handful of grouped queries (never one per card).
 * @param int[] $task_ids
 * @return array<int, array{chk_done:int, chk_total:int, sub_done:int, sub_total:int, comments:int, files:int}>  task_id => counts (every id present)
 */
function projectTaskCounts($mysqli, array $task_ids): array
{
    $task_ids = array_values(array_unique(array_filter(array_map('intval', $task_ids))));
    $out = [];
    foreach ($task_ids as $id) {
        $out[$id] = ['chk_done' => 0, 'chk_total' => 0, 'sub_done' => 0, 'sub_total' => 0, 'comments' => 0, 'files' => 0];
    }
    if (!$task_ids) {
        return $out;
    }
    $list = implode(',', $task_ids);
    if (projectTaskPhase2Ready($mysqli)) {
        $res = mysqli_query($mysqli, "SELECT item_task_id AS id, COUNT(*) AS total, SUM(item_done) AS done FROM task_checklist_items WHERE item_task_id IN ($list) GROUP BY item_task_id");
        while ($r = mysqli_fetch_assoc($res)) {
            $out[intval($r['id'])]['chk_total'] = intval($r['total']);
            $out[intval($r['id'])]['chk_done'] = intval($r['done']);
        }
        $res = mysqli_query($mysqli, "SELECT task_parent_id AS id, COUNT(*) AS total, SUM(task_completed_at IS NOT NULL) AS done FROM tasks WHERE task_parent_id IN ($list) GROUP BY task_parent_id");
        while ($r = mysqli_fetch_assoc($res)) {
            $out[intval($r['id'])]['sub_total'] = intval($r['total']);
            $out[intval($r['id'])]['sub_done'] = intval($r['done']);
        }
        $res = mysqli_query($mysqli, "SELECT comment_task_id AS id, COUNT(*) AS n FROM task_comments WHERE comment_task_id IN ($list) GROUP BY comment_task_id");
        while ($r = mysqli_fetch_assoc($res)) {
            $out[intval($r['id'])]['comments'] = intval($r['n']);
        }
    }
    if (projectTaskPhase3Ready($mysqli)) {
        $res = mysqli_query($mysqli, "SELECT attachment_task_id AS id, COUNT(*) AS n FROM task_attachments WHERE attachment_task_id IN ($list) GROUP BY attachment_task_id");
        while ($r = mysqli_fetch_assoc($res)) {
            $out[intval($r['id'])]['files'] = intval($r['n']);
        }
    }

    return $out;
}

/**
 * The additive API fields of phases 2 and 3 for many task rows (tasks.* rows), keyed by task id. Before the migrations they are the
 * neutral values (no parent, only the primary assignee, zero counts, no reminder, no repeat), so a client can always read them.
 * @param array<int, array> $rows
 * @return array<int, array>
 */
function projectTaskApiExtras2($mysqli, array $rows): array
{
    $ids = array_map('intval', array_column($rows, 'task_id'));
    $counts = projectTaskCounts($mysqli, $ids);
    $people = projectTaskAssigneesByTask($mysqli, $ids);
    $out = [];
    foreach ($rows as $row) {
        $id = intval($row['task_id']);
        $c = $counts[$id] ?? ['chk_done' => 0, 'chk_total' => 0, 'sub_done' => 0, 'sub_total' => 0, 'comments' => 0, 'files' => 0];
        $team = [];
        foreach ($people[$id] ?? [] as $p) {
            $team[] = ['id' => $p['user_id'], 'name' => $p['user_name'], 'primary' => $p['primary']];
        }
        $interval = intval($row['task_repeat_interval'] ?? 0);
        $out[$id] = [
            'parent_id' => isset($row['task_parent_id']) && $row['task_parent_id'] !== null ? intval($row['task_parent_id']) : null,
            'assignees' => $team,
            'checklist' => ['done' => $c['chk_done'], 'total' => $c['chk_total']],
            'subtasks' => ['done' => $c['sub_done'], 'total' => $c['sub_total']],
            'comment_count' => $c['comments'],
            'attachment_count' => $c['files'],
            'reminder_at' => $row['task_reminder_at'] ?? null,
            'reminder_sent_at' => $row['task_reminder_sent_at'] ?? null,
            'repeat' => $interval > 0 ? ['interval' => $interval, 'unit' => (string) ($row['task_repeat_unit'] ?? 'day')] : null,
        ];
    }

    return $out;
}
