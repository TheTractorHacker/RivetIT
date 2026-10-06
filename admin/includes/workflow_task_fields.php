<?php

/**
 * The form fields of one workflow template task (add form and edit modal share them). Everything echoed is escaped.
 * Fields for approval and automated tasks show only for the matching task type.
 *
 * @param array<string,mixed> $task existing task row for the edit form, [] for a new task
 */
function workflowTaskFields(mysqli $mysqli, int $workflow_template_id, string $template_type, array $task = []): void
{
    global $csp_nonce; // full pages need it on inline scripts (CSP); an AJAX modal gets the page's nonce from js/ajax_modal.js
    $h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $id = intval($task['template_task_id'] ?? 0);
    $uid = 'wtf' . $id; // unique ids when the page holds several forms
    $cfg = json_decode((string) ($task['action_config'] ?? ''), true) ?: [];
    $deps = \ITFlow\Workflow\DependencyGraph::parse($task['depends_on'] ?? null);

    $others = mysqli_query($mysqli, "SELECT template_task_id, title FROM workflow_template_tasks WHERE workflow_template_id = $workflow_template_id AND template_task_id <> $id ORDER BY sort_order, template_task_id");
    $agents = mysqli_query($mysqli, "SELECT user_id, user_name FROM users WHERE user_type = 1 AND user_status = 1 AND user_archived_at IS NULL ORDER BY user_name");
    $agent_rows = $agents ? mysqli_fetch_all($agents, MYSQLI_ASSOC) : [];
    $roles = mysqli_query($mysqli, "SELECT role_id, role_name FROM user_roles WHERE role_archived_at IS NULL ORDER BY role_name");
    $role_rows = $roles ? mysqli_fetch_all($roles, MYSQLI_ASSOC) : [];
    $type = $task['task_type'] ?? 'manual';
    ?>
    <div class="row" id="<?= $uid ?>">
        <div class="col-md-4 form-group">
            <label>Title <strong class="text-danger">*</strong></label>
            <input type="text" class="form-control" name="title" maxlength="255" required value="<?= $h($task['title'] ?? '') ?>">
        </div>
        <div class="col-md-2 form-group">
            <label>Category</label>
            <input type="text" class="form-control" name="category" placeholder="e.g. Identity" maxlength="100" value="<?= $h($task['category'] ?? '') ?>">
        </div>
        <div class="col-md-2 form-group">
            <label>Owner label</label>
            <input type="text" class="form-control" name="default_owner" placeholder="e.g. IT" maxlength="100" value="<?= $h($task['default_owner'] ?? '') ?>">
        </div>
        <div class="col-md-3 form-group">
            <label>Instructions</label>
            <input type="text" class="form-control" name="instructions" maxlength="500" value="<?= $h($task['instructions'] ?? '') ?>">
        </div>
        <div class="col-md-1 form-group">
            <label>Required</label><br>
            <input type="checkbox" name="required" value="1" <?= !$task || !empty($task['required']) ? 'checked' : '' ?> class="form-check-input mt-2">
        </div>
    </div>

    <div class="row">
        <div class="col-md-3 form-group">
            <label>Task type</label>
            <select class="form-control" name="task_type" data-wtf-type="<?= $uid ?>">
                <option value="manual" <?= $type === 'manual' ? 'selected' : '' ?>>Manual (a person ticks it off)</option>
                <option value="approval" <?= $type === 'approval' ? 'selected' : '' ?>>Approval</option>
                <option value="action" <?= $type === 'action' ? 'selected' : '' ?>>Automated action</option>
            </select>
        </div>
        <div class="col-md-3 form-group">
            <label>Waits for <span class="text-muted small">(dependencies)</span></label>
            <select class="form-control" name="depends_on[]" multiple size="3">
                <?php while ($o = mysqli_fetch_assoc($others)) { ?>
                    <option value="<?= intval($o['template_task_id']) ?>" <?= in_array(intval($o['template_task_id']), $deps, true) ? 'selected' : '' ?>><?= $h($o['title']) ?></option>
                <?php } ?>
            </select>
        </div>
        <div class="col-md-2 form-group">
            <label>Assignee</label>
            <select class="form-control" name="assignee_user_id">
                <option value="0">Nobody (owner label only)</option>
                <?php foreach ($agent_rows as $a) { ?>
                    <option value="<?= intval($a['user_id']) ?>" <?= intval($task['assignee_user_id'] ?? 0) === intval($a['user_id']) ? 'selected' : '' ?>><?= $h($a['user_name']) ?></option>
                <?php } ?>
            </select>
        </div>
        <div class="col-md-2 form-group">
            <label>Due (days)</label>
            <input type="number" class="form-control" name="due_offset_days" min="-3650" max="3650" placeholder="none" value="<?= $h($task['due_offset_days'] ?? '') ?>">
        </div>
        <div class="col-md-2 form-group">
            <label>Counted from</label>
            <select class="form-control" name="due_anchor">
                <option value="run" <?= ($task['due_anchor'] ?? 'run') === 'run' ? 'selected' : '' ?>>Workflow start</option>
                <option value="start" <?= ($task['due_anchor'] ?? '') === 'start' ? 'selected' : '' ?>>Employee start date</option>
                <option value="end" <?= ($task['due_anchor'] ?? '') === 'end' ? 'selected' : '' ?>>Employee end date</option>
            </select>
        </div>
    </div>

    <div class="row" data-wtf-show="approval" data-wtf-group="<?= $uid ?>">
        <div class="col-md-3 form-group">
            <label>Approver</label>
            <select class="form-control" name="approver_type">
                <option value="manager" <?= ($task['approver_type'] ?? 'manager') === 'manager' ? 'selected' : '' ?>>The employee's manager</option>
                <option value="user" <?= ($task['approver_type'] ?? '') === 'user' ? 'selected' : '' ?>>A specific user</option>
                <option value="role" <?= ($task['approver_type'] ?? '') === 'role' ? 'selected' : '' ?>>Anyone with a role</option>
            </select>
        </div>
        <div class="col-md-3 form-group">
            <label>Approver user</label>
            <select class="form-control" name="approver_user_id">
                <option value="0">-</option>
                <?php foreach ($agent_rows as $a) { ?>
                    <option value="<?= intval($a['user_id']) ?>" <?= intval($task['approver_user_id'] ?? 0) === intval($a['user_id']) ? 'selected' : '' ?>><?= $h($a['user_name']) ?></option>
                <?php } ?>
            </select>
        </div>
        <div class="col-md-3 form-group">
            <label>Approver role</label>
            <select class="form-control" name="approver_role_id">
                <option value="0">-</option>
                <?php foreach ($role_rows as $r) { ?>
                    <option value="<?= intval($r['role_id']) ?>" <?= intval($task['approver_role_id'] ?? 0) === intval($r['role_id']) ? 'selected' : '' ?>><?= $h($r['role_name']) ?></option>
                <?php } ?>
            </select>
        </div>
        <div class="col-md-12 small text-muted">Until approved the workflow waits at this task. A rejection pauses the workflow and notifies whoever started it. Administrators can always decide; if the manager has no login, only administrators can.</div>
    </div>

    <div class="row" data-wtf-show="action" data-wtf-group="<?= $uid ?>">
        <div class="col-md-4 form-group">
            <label>Action</label>
            <select class="form-control" name="action_type">
                <?php foreach (\ITFlow\Workflow\TaskActionRunner::labels() as $k => $label) {
                    if ($k === 'disable_contact_login' && $template_type !== 'offboarding') { continue; } ?>
                    <option value="<?= $h($k) ?>" <?= ($task['action_type'] ?? '') === $k ? 'selected' : '' ?>><?= $h($label) ?></option>
                <?php } ?>
            </select>
        </div>
        <div class="col-md-8 small text-muted pt-4">Placeholders: <?= $h(implode(' ', array_map(static fn ($n) => '{{' . $n . '}}', \ITFlow\Workflow\Actions\Placeholders::NAMES))) ?>. Ready automated tasks run when the workflow starts or when what they wait for is done; if one fails after retries it becomes a manual task.</div>
        <div class="col-md-6 form-group">
            <label>Subject (ticket or email)</label>
            <input type="text" class="form-control" name="cfg_subject" maxlength="500" value="<?= $h($cfg['subject'] ?? '') ?>">
        </div>
        <div class="col-md-3 form-group">
            <label>Ticket priority</label>
            <select class="form-control" name="cfg_priority">
                <?php foreach (['Low', 'Medium', 'High'] as $pr) { ?><option <?= ($cfg['priority'] ?? 'Low') === $pr ? 'selected' : '' ?>><?= $pr ?></option><?php } ?>
            </select>
        </div>
        <div class="col-md-3 form-group">
            <label>Email to</label>
            <select class="form-control" name="cfg_to">
                <?php foreach (\ITFlow\Workflow\Actions\SendMailAction::TO as $k => $label) { ?><option value="<?= $h($k) ?>" <?= ($cfg['to'] ?? 'employee') === $k ? 'selected' : '' ?>><?= $h($label) ?></option><?php } ?>
            </select>
        </div>
        <div class="col-md-4 form-group">
            <label>Fixed email address</label>
            <input type="text" class="form-control" name="cfg_address" maxlength="200" value="<?= $h($cfg['address'] ?? '') ?>">
        </div>
        <div class="col-md-4 form-group">
            <label>Ticket details</label>
            <textarea class="form-control" name="cfg_details" rows="2" maxlength="5000"><?= $h($cfg['details'] ?? '') ?></textarea>
        </div>
        <div class="col-md-4 form-group">
            <label>Email body</label>
            <textarea class="form-control" name="cfg_body" rows="2" maxlength="5000"><?= $h($cfg['body'] ?? '') ?></textarea>
        </div>
        <div class="col-md-4 form-group">
            <label>Notification message</label>
            <input type="text" class="form-control" name="cfg_message" maxlength="900" value="<?= $h($cfg['message'] ?? '') ?>">
        </div>
        <div class="col-md-4 form-group">
            <label>Notify user</label>
            <select class="form-control" name="cfg_user_id">
                <option value="0">Every agent</option>
                <?php foreach ($agent_rows as $a) { ?>
                    <option value="<?= intval($a['user_id']) ?>" <?= intval($cfg['user_id'] ?? 0) === intval($a['user_id']) ? 'selected' : '' ?>><?= $h($a['user_name']) ?></option>
                <?php } ?>
            </select>
        </div>
        <div class="col-md-4 form-group">
            <label>Webhook event name</label>
            <input type="text" class="form-control" name="cfg_event" maxlength="110" placeholder="workflow.task_webhook" value="<?= $h($cfg['event'] ?? '') ?>">
        </div>
    </div>
    <script<?= !empty($csp_nonce) ? ' nonce="' . $h($csp_nonce) . '"' : '' ?>>
    (function () {
        var sel = document.querySelector('[data-wtf-type="<?= $uid ?>"]');
        if (!sel) { return; }
        function sync() {
            document.querySelectorAll('[data-wtf-group="<?= $uid ?>"]').forEach(function (el) {
                el.style.display = el.getAttribute('data-wtf-show') === sel.value ? '' : 'none';
            });
        }
        sel.addEventListener('change', sync);
        sync();
    })();
    </script>
    <?php
}

/** Reads the form values posted by workflowTaskFields() into the array TemplateTaskService expects. */
function workflowTaskInputFromPost(array $post): array
{
    return [
        'title' => $post['title'] ?? '',
        'category' => $post['category'] ?? '',
        'default_owner' => $post['default_owner'] ?? '',
        'instructions' => $post['instructions'] ?? '',
        'required' => isset($post['required']),
        'task_type' => $post['task_type'] ?? 'manual',
        'depends_on' => (array) ($post['depends_on'] ?? []),
        'assignee_user_id' => $post['assignee_user_id'] ?? 0,
        'due_offset_days' => $post['due_offset_days'] ?? '',
        'due_anchor' => $post['due_anchor'] ?? 'run',
        'approver_type' => $post['approver_type'] ?? '',
        'approver_user_id' => $post['approver_user_id'] ?? 0,
        'approver_role_id' => $post['approver_role_id'] ?? 0,
        'action_type' => $post['action_type'] ?? '',
        'action_config' => [
            'subject' => $post['cfg_subject'] ?? '', 'details' => $post['cfg_details'] ?? '', 'priority' => $post['cfg_priority'] ?? 'Low',
            'to' => $post['cfg_to'] ?? 'employee', 'address' => $post['cfg_address'] ?? '', 'body' => $post['cfg_body'] ?? '',
            'message' => $post['cfg_message'] ?? '', 'user_id' => $post['cfg_user_id'] ?? 0, 'event' => $post['cfg_event'] ?? '',
        ],
    ];
}
