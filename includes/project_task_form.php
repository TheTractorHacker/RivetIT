<?php
/*
 * The task fields shared by the New Task, Edit Task and Task detail pop-ups (agent/modals/project/task_add.php, task_edit.php,
 * task_detail.php), so the three never drift apart. Include after includes/project_tasks.php; call inside the pop-up's <form>.
 *
 * $tf = [
 *   'project_id' => int,            // project the task is (or will be) in; scopes the milestone and label lists
 *   'task'       => array|null,     // the tasks row when editing, null for a new task
 *   'milestone_preset' => int,      // new task only: milestone to preselect
 * ]
 * Before DB 2.6.207 only the original fields render; the assignee list, reminder and repeat fields need 2.6.209, with a one-line notice instead of the new ones.
 */
function projectTaskRenderFields($mysqli, array $tf): void
{
    $project_id = intval($tf['project_id']);
    $t = $tf['task'] ?? null;
    $ready = projectTaskPhase1Ready($mysqli);
    $ready3 = projectTaskPhase3Ready($mysqli);

    $name = nullable_htmlentities($t['task_name'] ?? '');
    $description = nullable_htmlentities($t['task_description'] ?? '');
    $priority = projectTaskNormalizePriority($t['task_priority'] ?? 0);
    $color = projectTaskNormalizeColor($t['task_color'] ?? '') ?? '';
    $milestone_id = intval($t ? ($t['task_milestone_id'] ?? 0) : ($tf['milestone_preset'] ?? 0));
    $assigned_to = intval($t['task_assigned_to'] ?? 0);
    $start = nullable_htmlentities($t['task_start'] ?? '');
    $due = nullable_htmlentities($t['task_due'] ?? '');
    $estimate = intval($t['task_completion_estimate'] ?? 0);
    $progress = intval($t['task_progress'] ?? 0);
    $reminder_at = !empty($t['task_reminder_at']) ? date('Y-m-d\TH:i', strtotime($t['task_reminder_at'])) : '';
    $reminder_sent = !empty($t['task_reminder_sent_at']);
    $repeat_interval = intval($t['task_repeat_interval'] ?? 0);
    $repeat_unit = (string) ($t['task_repeat_unit'] ?? 'day');

    // Current lane (same derivation as the Kanban board)
    $lane = 'To Do';
    if ($t) {
        if (!empty($t['task_completed_at'])) {
            $lane = 'Done';
        } elseif (in_array(trim((string) $t['task_status']), projectTaskLanes(), true)) {
            $lane = trim((string) $t['task_status']);
        } elseif ($progress >= 100) {
            $lane = 'Done';
        } elseif ($progress > 0) {
            $lane = 'In Progress';
        }
    }

    $selected_labels = [];
    if ($t && $ready) {
        foreach (projectTaskLabelsByTask($mysqli, [intval($t['task_id'])])[intval($t['task_id'])] ?? [] as $l) {
            $selected_labels[$l['label_id']] = true;
        }
    }
    ?>
    <div class="form-group">
        <label>Task Name <strong class="text-danger">*</strong></label>
        <div class="input-group">
            <div class="input-group-prepend">
                <span class="input-group-text"><i class="fa fa-fw fa-tasks"></i></span>
            </div>
            <input type="text" class="form-control" name="name" placeholder="Task Name" maxlength="255" value="<?= $name ?>" required autofocus>
        </div>
    </div>

    <?php if ($ready) { ?>
        <div class="form-group">
            <label>Description</label>
            <textarea class="form-control" name="description" rows="4" maxlength="20000" placeholder="Details, links, acceptance notes (plain text, line breaks are kept)"><?= $description ?></textarea>
        </div>

        <div class="form-row">
            <div class="form-group col-md-6">
                <label>Priority</label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-flag"></i></span>
                    </div>
                    <select class="form-control" name="priority">
                        <?php foreach (projectTaskPriorities() as $pv => $pm) { ?>
                            <option value="<?= $pv ?>" <?= $priority === $pv ? 'selected' : '' ?>><?= nullable_htmlentities($pm['name']) ?></option>
                        <?php } ?>
                    </select>
                </div>
            </div>
            <div class="form-group col-md-6">
                <label>Status</label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-columns"></i></span>
                    </div>
                    <input type="hidden" name="status_orig" value="<?= nullable_htmlentities($lane) ?>">
                    <select class="form-control" name="status">
                        <?php foreach (projectTaskLanes() as $ln) { ?>
                            <option value="<?= nullable_htmlentities($ln) ?>" <?= $lane === $ln ? 'selected' : '' ?>><?= nullable_htmlentities($ln) ?></option>
                        <?php } ?>
                    </select>
                </div>
            </div>
        </div>

        <div class="form-group">
            <label>Labels</label>
            <input type="hidden" name="labels_present" value="1">
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-tags"></i></span>
                </div>
                <select class="form-control select2" name="label_ids[]" multiple data-placeholder="Choose labels">
                    <?php foreach (projectTaskLabelsForProject($mysqli, $project_id) as $l) { ?>
                        <option value="<?= $l['label_id'] ?>" <?= isset($selected_labels[$l['label_id']]) ? 'selected' : '' ?>><?= nullable_htmlentities($l['label_name']) ?><?= $l['label_project_id'] === null ? ' (all projects)' : '' ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="input-group mt-2">
                <input type="text" class="form-control" name="new_labels" maxlength="300" placeholder="New labels, comma separated (optional)">
                <input type="color" class="form-control form-control-color flex-grow-0" name="new_label_color" value="#206bc4" title="Colour for the new labels" style="width: 3rem;">
            </div>
            <?php if (lookupUserPermission('module_support') >= 3) { ?>
                <div class="form-check mt-1">
                    <input class="form-check-input" type="checkbox" name="label_global" value="1" id="taskLabelGlobal<?= intval($t['task_id'] ?? 0) ?>">
                    <label class="form-check-label small text-secondary" for="taskLabelGlobal<?= intval($t['task_id'] ?? 0) ?>">Make new labels available in every project</label>
                </div>
            <?php } ?>
        </div>
    <?php } else { ?>
        <div class="alert alert-info py-2 small">Priority, description, labels and colour turn on after an administrator runs Update Database.</div>
    <?php } ?>

    <div class="form-group">
        <label>Milestone</label>
        <div class="input-group">
            <div class="input-group-prepend">
                <span class="input-group-text"><i class="fa fa-fw fa-flag-checkered"></i></span>
            </div>
            <select class="form-control select2" name="milestone_id">
                <option value="0">- No Milestone -</option>
                <?php
                $sql_milestones = mysqli_query($mysqli, "SELECT milestone_id, milestone_name FROM project_milestones WHERE milestone_project_id = $project_id ORDER BY milestone_order ASC, milestone_id ASC");
                while ($m = mysqli_fetch_assoc($sql_milestones)) {
                    $m_id = intval($m['milestone_id']);
                    $m_name = nullable_htmlentities($m['milestone_name']); ?>
                    <option value="<?= $m_id ?>" <?= $milestone_id == $m_id ? 'selected' : '' ?>><?= $m_name ?></option>
                <?php } ?>
            </select>
        </div>
    </div>
    <?php
    $active_users = [];
    $sql_users = mysqli_query($mysqli, "SELECT user_id, user_name FROM users WHERE user_status = 1 AND user_archived_at IS NULL ORDER BY user_name ASC");
    while ($u = mysqli_fetch_assoc($sql_users)) {
        $active_users[] = ['id' => intval($u['user_id']), 'name' => nullable_htmlentities($u['user_name'])];
    }
    $team_ids = [];
    if ($t && $ready3) {
        foreach (projectTaskAssigneesByTask($mysqli, [intval($t['task_id'])])[intval($t['task_id'])] ?? [] as $p) {
            if (!$p['primary']) { $team_ids[$p['user_id']] = true; }
        }
    }
    ?>
    <div class="form-group">
        <label>Assigned To<?= $ready3 ? ' <small class="text-secondary">(primary)</small>' : '' ?></label>
        <div class="input-group">
            <div class="input-group-prepend">
                <span class="input-group-text"><i class="fa fa-fw fa-user"></i></span>
            </div>
            <select class="form-control select2" name="assigned_to">
                <option value="0">- Unassigned -</option>
                <?php foreach ($active_users as $u) { ?>
                    <option value="<?= $u['id'] ?>" <?= $assigned_to == $u['id'] ? 'selected' : '' ?>><?= $u['name'] ?></option>
                <?php } ?>
            </select>
        </div>
    </div>
    <?php if ($ready3) { ?>
        <div class="form-group">
            <label>Also assigned to</label>
            <input type="hidden" name="assignees_present" value="1">
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-users"></i></span>
                </div>
                <select class="form-control select2" name="assignee_ids[]" multiple data-placeholder="Add more people">
                    <?php foreach ($active_users as $u) { ?>
                        <option value="<?= $u['id'] ?>" <?= isset($team_ids[$u['id']]) ? 'selected' : '' ?>><?= $u['name'] ?></option>
                    <?php } ?>
                </select>
            </div>
            <small class="text-secondary">The primary person above plus anyone here. Everyone listed sees the task on their filter and gets its reminder.</small>
        </div>
    <?php } ?>
    <div class="form-row">
        <div class="form-group col-md-6">
            <label>Start Date</label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-calendar"></i></span>
                </div>
                <input type="date" class="form-control" name="start" value="<?= $start ?>">
            </div>
        </div>
        <div class="form-group col-md-6">
            <label>Due Date</label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-calendar-check"></i></span>
                </div>
                <input type="date" class="form-control" name="due" value="<?= $due ?>">
            </div>
        </div>
    </div>
    <div class="form-row">
        <div class="form-group col-md-6">
            <label>Time Estimate <small class="text-secondary">(minutes)</small></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-clock"></i></span>
                </div>
                <input type="number" class="form-control" name="completion_estimate" value="<?= $estimate ?>" min="0">
            </div>
        </div>
        <div class="form-group col-md-6">
            <label>Progress <small class="text-secondary">(%)</small></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-percent"></i></span>
                </div>
                <input type="number" class="form-control" name="progress" value="<?= $progress ?>" min="0" max="100">
            </div>
        </div>
    </div>
    <?php if ($ready3) { ?>
        <div class="form-row">
            <div class="form-group col-md-6">
                <label>Reminder</label>
                <input type="hidden" name="reminder_present" value="1">
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-bell"></i></span>
                    </div>
                    <input type="datetime-local" class="form-control" name="reminder_at" value="<?= $reminder_at ?>">
                </div>
                <select class="form-control mt-2" name="reminder_from_due" aria-label="Or set the reminder relative to the due date">
                    <option value="">Or relative to the due date...</option>
                    <option value="0">On the due date, 9:00</option>
                    <option value="1d">A day before, 9:00</option>
                    <option value="1w">A week before, 9:00</option>
                </select>
                <small class="text-secondary"><?= $reminder_sent ? 'Delivered. Change the time to be reminded again.' : 'Everyone on the task gets a notification then. Empty = no reminder.' ?></small>
            </div>
            <div class="form-group col-md-6">
                <label>Repeat</label>
                <input type="hidden" name="repeat_present" value="1">
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-redo"></i> Every</span>
                    </div>
                    <input type="number" class="form-control" name="repeat_interval" value="<?= $repeat_interval ?>" min="0" max="999" aria-label="Repeat every">
                    <select class="form-control" name="repeat_unit" aria-label="Repeat unit">
                        <?php foreach (projectTaskRepeatUnits() as $uv => $ul) { ?>
                            <option value="<?= $uv ?>" <?= $repeat_unit === $uv ? 'selected' : '' ?>><?= $ul ?></option>
                        <?php } ?>
                    </select>
                </div>
                <small class="text-secondary">0 = does not repeat. When this task is completed, the next one is created with the due date moved on.</small>
            </div>
        </div>
    <?php } ?>
    <?php if ($ready) { ?>
        <div class="form-group">
            <label>Card colour</label>
            <div class="input-group">
                <input type="color" class="form-control form-control-color flex-grow-0" name="color" value="<?= $color !== '' ? $color : '#206bc4' ?>" style="width: 3rem;">
                <div class="form-check ms-3 align-self-center">
                    <input class="form-check-input" type="checkbox" name="color_clear" value="1" id="taskColorClear<?= intval($t['task_id'] ?? 0) ?>" <?= $color === '' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="taskColorClear<?= intval($t['task_id'] ?? 0) ?>">No colour</label>
                </div>
            </div>
        </div>
        <script>
            // Choosing a colour means "use a colour": untick "No colour" for them
            document.querySelectorAll('input[name="color"]').forEach(function (c) {
                if (c.dataset.bound) { return; }
                c.dataset.bound = '1';
                c.addEventListener('input', function () {
                    var clear = c.closest('form').querySelector('input[name="color_clear"]');
                    if (clear) { clear.checked = false; }
                });
            });
        </script>
    <?php } ?>
    <?php
}
