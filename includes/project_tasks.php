<?php
/*
 * Project task helpers: priority, description, colour, labels and the basic task actions (DB 2.6.207).
 *
 *   tasks.task_priority      0 none, 1 low, 2 medium, 3 high, 4 urgent
 *   tasks.task_description   plain text; always escaped on output, line breaks kept with nl2br()
 *   tasks.task_color         optional card accent, #rrggbb
 *   task_labels              label_project_id NULL = every project, otherwise that project only
 *   task_label_links         (task_id, label_id); both sides cascade
 *
 * Every function here is safe on a database that has not run 2.6.207 yet (Update App before Update Database):
 * projectTaskPhase1Ready() is the single switch the pages and handlers test, the same way
 * includes/ticket_category_rules.php tests its columns.
 *
 *   projectTaskColumnsExist() / projectTaskLabelTablesExist() / projectTaskPhase1Ready()
 *   projectTaskPriorities() / projectTaskPriorityMeta()         - the five priority levels (name, icon, colour)
 *   projectTaskNormalizeColor()                                 - '#rrggbb' or null
 *   projectTaskLabelsForProject() / projectTaskLabelsByTask()   - read labels
 *   projectTaskCreateLabel() / projectTaskSetLabels()           - write labels (scope-checked)
 *   projectTaskContext()                                        - task row + owning project + client, for access checks
 *   projectTaskStatusSql()                                      - the SET fragment for a lane change (same rules as the Kanban drag)
 *   projectTaskDelete() / projectTaskDuplicate() / projectTaskMove()
 *
 * Phases 2 and 3 (DB 2.6.208 / 2.6.209: checklists, subtasks, comments, several assignees, attachments, reminders, repeating tasks)
 * live in includes/project_task_extras.php, loaded below; projectTaskPhase2Ready() / projectTaskPhase3Ready() are their switches.
 */
require_once __DIR__ . '/project_task_extras.php';

/** Lanes of the project Kanban, in order. task_status holds one of these names (or NULL/other = derived). */
function projectTaskLanes(): array
{
    return ['To Do', 'In Progress', 'Blocked', 'Done'];
}

/** Have the DB 2.6.207 `tasks` columns been added yet? Cached once true. */
function projectTaskColumnsExist($mysqli): bool
{
    static $exists = null;
    if ($exists === null) {
        $res = @mysqli_query($mysqli, "SHOW COLUMNS FROM tasks LIKE 'task_priority'");
        $exists = (bool) ($res && mysqli_num_rows($res) > 0);
    }

    return $exists;
}

/** Have the DB 2.6.207 label tables been added yet? Cached once true. */
function projectTaskLabelTablesExist($mysqli): bool
{
    static $exists = null;
    if ($exists === null) {
        $res = @mysqli_query($mysqli, "SHOW TABLES LIKE 'task_label_links'");
        $res2 = @mysqli_query($mysqli, "SHOW TABLES LIKE 'task_labels'");
        $exists = (bool) ($res && mysqli_num_rows($res) > 0 && $res2 && mysqli_num_rows($res2) > 0);
    }

    return $exists;
}

/** True once DB 2.6.207 has run (columns and tables). When false every caller behaves exactly as before the upgrade. */
function projectTaskPhase1Ready($mysqli): bool
{
    return projectTaskColumnsExist($mysqli) && projectTaskLabelTablesExist($mysqli);
}

/** @return array<int, array{name:string, icon:string, color:string}> */
function projectTaskPriorities(): array
{
    return [
        0 => ['name' => 'None',   'icon' => 'fa-minus',               'color' => '#9aa5b1'],
        1 => ['name' => 'Low',    'icon' => 'fa-arrow-down',          'color' => '#2fb344'],
        2 => ['name' => 'Medium', 'icon' => 'fa-equals',              'color' => '#f59f00'],
        3 => ['name' => 'High',   'icon' => 'fa-arrow-up',            'color' => '#f76707'],
        4 => ['name' => 'Urgent', 'icon' => 'fa-exclamation-triangle', 'color' => '#d63939'],
    ];
}

function projectTaskPriorityMeta($priority): array
{
    $all = projectTaskPriorities();
    $p = intval($priority);

    return $all[$p] ?? $all[0];
}

/** Clamp a posted priority to 0-4. */
function projectTaskNormalizePriority($value): int
{
    return max(0, min(4, intval($value)));
}

/** '#rrggbb' (lower-cased) or null for anything else, including an empty value. */
function projectTaskNormalizeColor($value): ?string
{
    $v = trim((string) $value);
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $v)) {
        return strtolower($v);
    }

    return null;
}

/**
 * Labels a task in this project may carry: global ones plus the project's own.
 * @return array<int, array{label_id:int, label_name:string, label_color:string, label_project_id:?int}>
 */
function projectTaskLabelsForProject($mysqli, int $project_id): array
{
    if (!projectTaskLabelTablesExist($mysqli)) {
        return [];
    }
    $out = [];
    $res = mysqli_query($mysqli, "SELECT label_id, label_name, label_color, label_project_id FROM task_labels WHERE label_project_id IS NULL OR label_project_id = $project_id ORDER BY label_name ASC, label_id ASC");
    while ($r = mysqli_fetch_assoc($res)) {
        $out[intval($r['label_id'])] = [
            'label_id' => intval($r['label_id']),
            'label_name' => (string) $r['label_name'],
            'label_color' => (string) $r['label_color'],
            'label_project_id' => $r['label_project_id'] === null ? null : intval($r['label_project_id']),
        ];
    }

    return $out;
}

/**
 * Labels of many tasks in one query.
 * @param int[] $task_ids
 * @return array<int, array<int, array{label_id:int, label_name:string, label_color:string}>>  task_id => labels
 */
function projectTaskLabelsByTask($mysqli, array $task_ids): array
{
    $task_ids = array_values(array_unique(array_filter(array_map('intval', $task_ids))));
    if (!$task_ids || !projectTaskLabelTablesExist($mysqli)) {
        return [];
    }
    $list = implode(',', $task_ids);
    $out = [];
    $res = mysqli_query($mysqli, "SELECT l.task_id, t.label_id, t.label_name, t.label_color FROM task_label_links l JOIN task_labels t ON t.label_id = l.label_id WHERE l.task_id IN ($list) ORDER BY t.label_name ASC");
    while ($r = mysqli_fetch_assoc($res)) {
        $out[intval($r['task_id'])][] = [
            'label_id' => intval($r['label_id']),
            'label_name' => (string) $r['label_name'],
            'label_color' => (string) $r['label_color'],
        ];
    }

    return $out;
}

/** Chip HTML for a label (escaped; text colour chosen for contrast). */
function projectTaskLabelChip(array $label): string
{
    $color = projectTaskNormalizeColor($label['label_color'] ?? '') ?? '#206bc4';
    $text = tagTextClass($color) === 'text-dark' ? '#1f2937' : '#ffffff';

    return '<span class="badge task-label-chip" style="background:' . $color . ';color:' . $text . ';" data-label-id="' . intval($label['label_id']) . '">'
        . nullable_htmlentities($label['label_name']) . '</span>';
}

/**
 * Find or create a label by name in a scope ($project_id = null for a global label). Same name (any case) in the same scope is reused.
 * Returns the label id, or 0 when the name is empty or the tables do not exist yet.
 * $name must already be sanitizeInput()'d (SQL-safe) and cut to 100 characters BEFORE sanitising; $color is normalised here.
 */
function projectTaskCreateLabel($mysqli, string $name, ?string $color, ?int $project_id): int
{
    if ($name === '' || !projectTaskLabelTablesExist($mysqli)) {
        return 0;
    }
    $scope_sql = $project_id ? "label_project_id = " . intval($project_id) : "label_project_id IS NULL";
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT label_id FROM task_labels WHERE label_name = '$name' AND $scope_sql LIMIT 1"));
    if ($row) {
        return intval($row['label_id']);
    }
    $color = projectTaskNormalizeColor($color) ?? '#206bc4';
    $project_sql = $project_id ? intval($project_id) : 'NULL';
    mysqli_query($mysqli, "INSERT INTO task_labels SET label_name = '$name', label_color = '$color', label_project_id = $project_sql");

    return intval(mysqli_insert_id($mysqli));
}

/**
 * Replace a task's labels with $label_ids, keeping only labels that are global or belong to $project_id (the label-scoping rule:
 * a label from another project can never be attached, whatever is posted). Returns the ids actually linked.
 * @param int[] $label_ids
 * @return int[]
 */
function projectTaskSetLabels($mysqli, int $task_id, int $project_id, array $label_ids): array
{
    if (!projectTaskLabelTablesExist($mysqli)) {
        return [];
    }
    $label_ids = array_values(array_unique(array_filter(array_map('intval', $label_ids))));
    $keep = [];
    if ($label_ids) {
        $list = implode(',', $label_ids);
        $res = mysqli_query($mysqli, "SELECT label_id FROM task_labels WHERE label_id IN ($list) AND (label_project_id IS NULL OR label_project_id = $project_id)");
        while ($r = mysqli_fetch_assoc($res)) {
            $keep[] = intval($r['label_id']);
        }
    }
    mysqli_query($mysqli, "DELETE FROM task_label_links WHERE task_id = $task_id");
    foreach ($keep as $lid) {
        mysqli_query($mysqli, "INSERT IGNORE INTO task_label_links SET task_id = $task_id, label_id = $lid");
    }

    return $keep;
}

/**
 * The task row plus where it lives, for access checks and logging. A task belongs to a project directly (task_project_id) or through a
 * project ticket (tickets.ticket_project_id); client_id comes from the project, else from the ticket (same rule the edit handlers use).
 * Returns null when the task does not exist.
 * @return array{row:array, project_id:int, client_id:int}|null
 */
function projectTaskContext($mysqli, int $task_id): ?array
{
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT tasks.*, tickets.ticket_client_id, tickets.ticket_project_id FROM tasks LEFT JOIN tickets ON tickets.ticket_id = tasks.task_ticket_id WHERE tasks.task_id = $task_id LIMIT 1"));
    if (!$row) {
        return null;
    }
    $project_id = intval($row['task_project_id']);
    if ($project_id) {
        $client_id = intval(getFieldById('projects', $project_id, 'project_client_id'));
    } else {
        $project_id = intval($row['ticket_project_id'] ?? 0);
        $client_id = intval($row['ticket_client_id'] ?? 0);
        if (!$client_id && $project_id) {
            $client_id = intval(getFieldById('projects', $project_id, 'project_client_id'));
        }
    }

    return ['row' => $row, 'project_id' => $project_id, 'client_id' => $client_id];
}

/**
 * SET fragment (no leading comma) that moves a task into a lane with the same rules as the Kanban drag in agent/ajax.php:
 * Done completes it (keeping an earlier completion time/author), any other lane clears completion. Returns '' for an unknown lane.
 */
function projectTaskStatusSql($mysqli, string $status, int $user_id): string
{
    if (!in_array($status, projectTaskLanes(), true)) {
        return '';
    }
    $status_esc = mysqli_real_escape_string($mysqli, $status);
    if ($status === 'Done') {
        return "task_status = '$status_esc', task_progress = 100, task_completed_at = COALESCE(task_completed_at, NOW()), task_completed_by = COALESCE(task_completed_by, $user_id)";
    }

    return "task_status = '$status_esc', task_progress = IF(task_progress >= 100, 0, task_progress), task_completed_at = NULL, task_completed_by = NULL";
}

/**
 * Delete a task and everything hanging off it: label links, checklist, comments, assignees and attachments (rows and files), schedule
 * dependencies in both directions. Its subtasks are NOT deleted: they stop being subtasks and stay in the project as ordinary tasks.
 * Returns true if a row went.
 */
function projectTaskDelete($mysqli, int $task_id): bool
{
    // The child tables also cascade by foreign key; deleting them here keeps it correct on any engine/setup that skipped the keys,
    // and the attachment files have to be removed by hand either way.
    projectTaskReleaseSubtasks($mysqli, $task_id);
    projectTaskAttachmentsPurge($mysqli, $task_id);
    foreach (['task_label_links' => 'task_id', 'task_checklist_items' => 'item_task_id', 'task_comments' => 'comment_task_id', 'task_assignees' => 'task_id'] as $table => $col) {
        if (projectTaskTableExists($mysqli, $table)) {
            mysqli_query($mysqli, "DELETE FROM `$table` WHERE `$col` = $task_id");
        }
    }
    $dep = @mysqli_query($mysqli, "SHOW TABLES LIKE 'project_task_dependencies'");
    if ($dep && mysqli_num_rows($dep) > 0) {
        mysqli_query($mysqli, "DELETE FROM project_task_dependencies WHERE task_id = $task_id OR predecessor_id = $task_id");
    }
    mysqli_query($mysqli, "DELETE FROM tasks WHERE task_id = $task_id");

    return mysqli_affected_rows($mysqli) > 0;
}

/**
 * Copy a task row into a new task of the same project. Options ($o):
 *   suffix         text appended to the name (default ' (copy)')
 *   due / start / reminder   override those dates (a 'Y-m-d' / 'Y-m-d H:i:s' string, or null to clear); absent = copy the source's
 *                            (a copied reminder that is already in the past is dropped, it would fire at once)
 *   keep_parent    a subtask is copied as a sibling under the same parent (default false)
 *   parent_id      make the copy a subtask of this task
 *   copy_children  also copy the subtasks, as subtasks of the copy (default false)
 * Never copied: completion state, progress, ticket links, schedule dependencies, comments, attachments. Copied: description, priority,
 * colour, labels, milestone, assignees, estimate, repeat settings, and the checklist (every item unticked).
 * Returns the new task id (0 on failure).
 */
function projectTaskCopy($mysqli, array $ctx, array $o = []): int
{
    $src = $ctx['row'];
    $src_id = intval($src['task_id']);
    $project_id = intval($ctx['project_id']);
    $suffix = array_key_exists('suffix', $o) ? (string) $o['suffix'] : ' (copy)';
    $name = mb_substr((string) $src['task_name'], 0, 255 - mb_strlen($suffix)) . $suffix;
    $name_esc = mysqli_real_escape_string($mysqli, $name);
    $date_sql = function ($v) use ($mysqli) {
        return $v ? "'" . mysqli_real_escape_string($mysqli, (string) $v) . "'" : 'NULL';
    };
    $milestone = $src['task_milestone_id'] !== null ? intval($src['task_milestone_id']) : 'NULL';
    $assigned = $src['task_assigned_to'] !== null ? intval($src['task_assigned_to']) : 'NULL';
    $start = $date_sql(array_key_exists('start', $o) ? $o['start'] : $src['task_start']);
    $due = $date_sql(array_key_exists('due', $o) ? $o['due'] : $src['task_due']);
    $estimate = intval($src['task_completion_estimate']);
    $order = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COALESCE(MAX(task_order), 0) + 1 FROM tasks WHERE task_project_id = $project_id"))[0] ?? 1);

    $extra = '';
    if (projectTaskColumnsExist($mysqli)) {
        $color = projectTaskNormalizeColor($src['task_color'] ?? '');
        $extra .= ", task_priority = " . projectTaskNormalizePriority($src['task_priority'] ?? 0)
            . ", task_description = " . (($src['task_description'] ?? '') !== '' && $src['task_description'] !== null ? "'" . mysqli_real_escape_string($mysqli, $src['task_description']) . "'" : 'NULL')
            . ", task_color = " . ($color ? "'$color'" : 'NULL');
    }
    $parent_id = null;
    if (!empty($o['parent_id'])) {
        $parent_id = intval($o['parent_id']);
    } elseif (!empty($o['keep_parent']) && !empty($src['task_parent_id'])) {
        $parent_id = intval($src['task_parent_id']);
    }
    if (projectTaskHasColumn($mysqli, 'task_parent_id') && $parent_id) {
        $extra .= ", task_parent_id = $parent_id";
    }
    if (projectTaskPhase3Ready($mysqli)) {
        $reminder = array_key_exists('reminder', $o) ? $o['reminder'] : $src['task_reminder_at'];
        if ($reminder && strtotime((string) $reminder) <= time()) {
            $reminder = null;
        }
        $extra .= ', task_reminder_at = ' . $date_sql($reminder)
            . ', task_repeat_interval = ' . intval($src['task_repeat_interval'])
            . ", task_repeat_unit = '" . mysqli_real_escape_string($mysqli, (string) $src['task_repeat_unit']) . "'";
    }

    mysqli_query($mysqli, "INSERT INTO tasks SET task_name = '$name_esc', task_status = 'To Do', task_order = $order, task_project_id = $project_id, task_milestone_id = $milestone, task_assigned_to = $assigned, task_start = $start, task_due = $due, task_completion_estimate = $estimate$extra");
    $new_id = intval(mysqli_insert_id($mysqli));
    if (!$new_id) {
        return 0;
    }
    if (projectTaskLabelTablesExist($mysqli)) {
        mysqli_query($mysqli, "INSERT IGNORE INTO task_label_links (task_id, label_id) SELECT $new_id, label_id FROM task_label_links WHERE task_id = $src_id");
    }
    if (projectTaskPhase3Ready($mysqli)) {
        mysqli_query($mysqli, "INSERT IGNORE INTO task_assignees (task_id, user_id) SELECT $new_id, user_id FROM task_assignees WHERE task_id = $src_id");
        if ($src['task_assigned_to'] !== null) {
            mysqli_query($mysqli, "INSERT IGNORE INTO task_assignees (task_id, user_id) VALUES ($new_id, " . intval($src['task_assigned_to']) . ')');
        }
    }
    if (projectTaskPhase2Ready($mysqli)) {
        mysqli_query($mysqli, "INSERT INTO task_checklist_items (item_task_id, item_text, item_order) SELECT $new_id, item_text, item_order FROM task_checklist_items WHERE item_task_id = $src_id ORDER BY item_order, item_id");
        if (!empty($o['copy_children'])) {
            foreach (projectTaskSubtasks($mysqli, $src_id) as $child) {
                $child_ctx = projectTaskContext($mysqli, $child['task_id']);
                if ($child_ctx) {
                    projectTaskCopy($mysqli, $child_ctx, ['suffix' => '', 'parent_id' => $new_id, 'copy_children' => false]);
                }
            }
        }
    }

    return $new_id;
}

/**
 * Duplicate a task as a new, not-started project task in the same project ("(copy)" appended): description, priority, colour, labels,
 * milestone, assignees, dates, estimate, repeat settings, the checklist (unticked) and its subtasks; never the completion state,
 * progress, comments, attachments, ticket links or schedule dependencies. Returns the new task id (0 on failure).
 */
function projectTaskDuplicate($mysqli, array $ctx): int
{
    return projectTaskCopy($mysqli, $ctx, ['keep_parent' => true, 'copy_children' => true]);
}

/**
 * Move a project task to another project (and optionally a milestone of that project). A ticket-linked checklist item is not movable
 * (it belongs to its ticket). Labels that are scoped to the old project are dropped (global ones stay); schedule dependencies are
 * dropped because their other end stays behind. Returns '' on success or an error message.
 */
function projectTaskMove($mysqli, array $ctx, int $target_project_id, int $target_milestone_id): string
{
    $task_id = intval($ctx['row']['task_id']);
    if (intval($ctx['row']['task_ticket_id'])) {
        return 'A task that belongs to a ticket cannot be moved to another project.';
    }
    if (!empty($ctx['row']['task_parent_id'])) {
        return 'A subtask moves with its parent task. Detach it from the parent first.';
    }
    $project = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT project_id FROM projects WHERE project_id = $target_project_id AND project_completed_at IS NULL LIMIT 1"));
    if (!$project) {
        return 'That project does not exist or is already completed.';
    }
    $milestone_sql = 'NULL';
    if ($target_milestone_id) {
        $m = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT milestone_id FROM project_milestones WHERE milestone_id = $target_milestone_id AND milestone_project_id = $target_project_id LIMIT 1"));
        if (!$m) {
            return 'That milestone does not belong to the chosen project.';
        }
        $milestone_sql = $target_milestone_id;
    }
    $order = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COALESCE(MAX(task_order), 0) + 1 FROM tasks WHERE task_project_id = $target_project_id"))[0] ?? 1);
    // The task and its subtasks (a subtask must stay in its parent's project) move together
    $moving = [$task_id];
    if (projectTaskHasColumn($mysqli, 'task_parent_id')) {
        foreach (projectTaskSubtasks($mysqli, $task_id) as $child) {
            $moving[] = $child['task_id'];
        }
    }
    $moving_list = implode(',', $moving);
    if ($target_project_id !== intval($ctx['project_id'])) {
        if (projectTaskLabelTablesExist($mysqli)) {
            mysqli_query($mysqli, "DELETE l FROM task_label_links l JOIN task_labels t ON t.label_id = l.label_id WHERE l.task_id IN ($moving_list) AND t.label_project_id IS NOT NULL AND t.label_project_id <> $target_project_id");
        }
        $dep = @mysqli_query($mysqli, "SHOW TABLES LIKE 'project_task_dependencies'");
        if ($dep && mysqli_num_rows($dep) > 0) {
            mysqli_query($mysqli, "DELETE FROM project_task_dependencies WHERE task_id IN ($moving_list) OR predecessor_id IN ($moving_list)");
        }
    }
    mysqli_query($mysqli, "UPDATE tasks SET task_project_id = $target_project_id, task_milestone_id = $milestone_sql, task_order = $order WHERE task_id = $task_id");
    if (count($moving) > 1) {
        mysqli_query($mysqli, "UPDATE tasks SET task_project_id = $target_project_id, task_milestone_id = $milestone_sql WHERE task_parent_id = $task_id");
    }

    return '';
}

/**
 * Apply the DB 2.6.207 fields from the current POST to a task that was just inserted or updated by the caller: priority, description,
 * colour, lane (status, only when posted), and labels (chosen ids plus new names, created in the project or, with
 * label_global = 1 and $can_global, in every project). A no-op before the upgrade. Posted label ids that are not global or from this
 * project are ignored. Returns a short summary for the audit log ('' when nothing applies).
 */
function projectTaskApplyPostedExtras($mysqli, int $task_id, int $project_id, int $user_id, bool $can_global, int $old_primary = 0): string
{
    if (!projectTaskColumnsExist($mysqli)) {
        return '';
    }
    $sets = [];
    if (isset($_POST['priority'])) {
        $sets[] = 'task_priority = ' . projectTaskNormalizePriority($_POST['priority']);
    }
    if (isset($_POST['description'])) {
        // sanitizeInput() strips tags and trims; newlines are kept and the text is escaped again on output.
        $description = sanitizeInput(mb_substr((string) $_POST['description'], 0, 20000));
        $sets[] = $description === '' ? 'task_description = NULL' : "task_description = '$description'";
    }
    if (isset($_POST['color']) || isset($_POST['color_clear'])) {
        $color = empty($_POST['color_clear']) ? projectTaskNormalizeColor($_POST['color'] ?? '') : null;
        $sets[] = $color ? "task_color = '$color'" : 'task_color = NULL';
    }
    if ($sets) {
        mysqli_query($mysqli, 'UPDATE tasks SET ' . implode(', ', $sets) . " WHERE task_id = $task_id");
    }
    // Only a lane the person actually changed is applied (an untouched lane must not undo a progress they typed)
    $became_done = false;
    if (!empty($_POST['status']) && (string) $_POST['status'] !== (string) ($_POST['status_orig'] ?? '')) {
        $status_sql = projectTaskStatusSql($mysqli, (string) $_POST['status'], $user_id);
        if ($status_sql !== '') {
            mysqli_query($mysqli, "UPDATE tasks SET $status_sql WHERE task_id = $task_id");
            $became_done = (string) $_POST['status'] === 'Done';
        }
    }

    // DB 2.6.209: assignees, reminder, repeat (each only when the form says it carried that field)
    if (projectTaskPhase3Ready($mysqli)) {
        if (isset($_POST['assigned_to']) || !empty($_POST['assignees_present'])) {
            $primary = intval($_POST['assigned_to'] ?? 0);
            $others = !empty($_POST['assignees_present']) ? (is_array($_POST['assignee_ids'] ?? null) ? $_POST['assignee_ids'] : []) : null;
            projectTaskSyncAssignees($mysqli, $task_id, $primary, $old_primary, $others);
        }
        if (!empty($_POST['reminder_present'])) {
            $due = (string) (mysqli_fetch_row(mysqli_query($mysqli, "SELECT task_due FROM tasks WHERE task_id = $task_id"))[0] ?? '');
            $reminder = projectTaskReminderFromDue($due !== '' ? $due : null, (string) ($_POST['reminder_from_due'] ?? ''));
            if ($reminder === null) {
                $reminder = projectTaskNormalizeReminder($_POST['reminder_at'] ?? '');
            }
            if ($reminder !== false) {
                // The delivered flag is cleared only when the time really changes (the assignment is evaluated before task_reminder_at)
                $r = $reminder === null ? 'NULL' : "'" . mysqli_real_escape_string($mysqli, $reminder) . "'";
                mysqli_query($mysqli, "UPDATE tasks SET task_reminder_sent_at = IF(task_reminder_at <=> $r, task_reminder_sent_at, NULL), task_reminder_at = $r WHERE task_id = $task_id");
            }
        }
        if (!empty($_POST['repeat_present'])) {
            [$n, $u] = projectTaskNormalizeRepeat($_POST['repeat_interval'] ?? 0, $_POST['repeat_unit'] ?? 'day');
            $u_esc = mysqli_real_escape_string($mysqli, $u);
            mysqli_query($mysqli, "UPDATE tasks SET task_repeat_interval = $n, task_repeat_unit = '$u_esc' WHERE task_id = $task_id");
        }
        if ($became_done) {
            projectTaskCompleted($mysqli, $task_id, $user_id);
        }
    }

    $summary = '';
    if (projectTaskLabelTablesExist($mysqli) && (isset($_POST['label_ids']) || !empty($_POST['new_labels']) || isset($_POST['labels_present']))) {
        $ids = is_array($_POST['label_ids'] ?? null) ? $_POST['label_ids'] : [];
        $color = projectTaskNormalizeColor($_POST['new_label_color'] ?? '');
        $global = $can_global && !empty($_POST['label_global']);
        foreach (explode(',', (string) ($_POST['new_labels'] ?? '')) as $raw) {
            $name = sanitizeInput(mb_substr($raw, 0, 100));
            if ($name !== '') {
                $lid = projectTaskCreateLabel($mysqli, $name, $color, $global ? null : $project_id);
                if ($lid) {
                    $ids[] = $lid;
                }
            }
        }
        $linked = projectTaskSetLabels($mysqli, $task_id, $project_id, $ids);
        $summary = count($linked) . ' label(s)';
    }

    return $summary;
}

/**
 * The additive API fields for a task row (DB 2.6.207): priority (0-4), priority_name, description, color, labels. Before the upgrade they
 * are the neutral defaults (0 / 'None' / null / null / []), so a client can always read them.
 * @param array<int, array> $labels_by_task projectTaskLabelsByTask() result for the rows being serialised
 */
function projectTaskApiExtras(array $row, array $labels_by_task): array
{
    $priority = projectTaskNormalizePriority($row['task_priority'] ?? 0);
    $description = $row['task_description'] ?? null;
    $labels = [];
    foreach ($labels_by_task[intval($row['task_id'])] ?? [] as $l) {
        $labels[] = ['id' => $l['label_id'], 'name' => $l['label_name'], 'color' => $l['label_color']];
    }

    return [
        'priority' => $priority,
        'priority_name' => projectTaskPriorityMeta($priority)['name'],
        'description' => ($description === null || $description === '') ? null : (string) $description,
        'color' => projectTaskNormalizeColor($row['task_color'] ?? ''),
        'labels' => $labels,
    ];
}
