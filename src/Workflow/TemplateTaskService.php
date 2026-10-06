<?php

namespace ITFlow\Workflow;

/**
 * Validated create / update / delete of a workflow template task, including dependencies, assignee, due date, approval and
 * action settings. The admin pages call this so the rules (same-template dependencies, no loops, valid action config) live in
 * one place that can be tested without HTTP.
 */
class TemplateTaskService
{
    private \mysqli $mysqli;
    private TaskActionRunner $runner;

    public function __construct(\mysqli $mysqli, ?TaskActionRunner $runner = null)
    {
        $this->mysqli = $mysqli;
        // Validation never executes an action, so the gateway is never used; a stub keeps this class free of app globals.
        $this->runner = $runner ?? new TaskActionRunner($mysqli, new class implements ActionGateway {
            public function createTicket(string $subject, string $detailsHtml, string $priority, int $clientId, string $source): int { throw new \LogicException('validation only'); }
            public function queueMail(string $to, string $toName, string $subject, string $bodyHtml): void { throw new \LogicException('validation only'); }
            public function notifyUser(int $userId, string $type, string $message, ?string $action, int $clientId, int $entityId): void { throw new \LogicException('validation only'); }
            public function emitEvent(string $event, array $data): void { throw new \LogicException('validation only'); }
            public function audit(string $event, ?int $actor, string $entityType, $entityId, string $action, string $summary, array $metadata = []): void { throw new \LogicException('validation only'); }
            public function disablePortalLogin(int $contactId): string { throw new \LogicException('validation only'); }
        });
    }

    /**
     * @param array<string,mixed> $in raw form values
     * @return array<string,mixed> clean column values
     * @throws \InvalidArgumentException with a message safe to show an administrator
     */
    public function normalize(int $templateId, int $templateTaskId, array $in): array
    {
        $template = mysqli_fetch_assoc(mysqli_query($this->mysqli, "SELECT type FROM workflow_templates WHERE workflow_template_id = $templateId"));
        if (!$template) {
            throw new \InvalidArgumentException('Template not found.');
        }
        $title = mb_substr(trim((string) ($in['title'] ?? '')), 0, 255);
        if ($title === '') {
            throw new \InvalidArgumentException('Give the task a title.');
        }
        $type = (string) ($in['task_type'] ?? 'manual');
        $type = in_array($type, ['manual', 'approval', 'action'], true) ? $type : 'manual';

        $deps = DependencyGraph::parse($in['depends_on'] ?? []);
        if ($deps && ($err = DependencyGraph::validateForTemplate($this->mysqli, $templateId, $templateTaskId, $deps))) {
            throw new \InvalidArgumentException($err);
        }

        $assignee = (int) ($in['assignee_user_id'] ?? 0);
        if ($assignee > 0 && !$this->agentExists($assignee)) {
            throw new \InvalidArgumentException('The assignee must be an active agent.');
        }

        $offsetRaw = trim((string) ($in['due_offset_days'] ?? ''));
        $offset = null;
        if ($offsetRaw !== '') {
            if (!preg_match('/^-?\d{1,4}$/', $offsetRaw) || abs((int) $offsetRaw) > 3650) {
                throw new \InvalidArgumentException('The due date offset must be a whole number of days (negative means before).');
            }
            $offset = (int) $offsetRaw;
        }
        $anchor = (string) ($in['due_anchor'] ?? 'run');
        $anchor = in_array($anchor, DueDateResolver::ANCHORS, true) ? $anchor : 'run';

        $approverType = $approverUser = $approverRole = null;
        if ($type === 'approval') {
            $approverType = (string) ($in['approver_type'] ?? '');
            $approverType = in_array($approverType, ['user', 'role', 'manager'], true) ? $approverType : null;
            if ($approverType === null) {
                throw new \InvalidArgumentException('Choose who approves this task.');
            }
            if ($approverType === 'user') {
                $approverUser = (int) ($in['approver_user_id'] ?? 0);
                if (!$this->agentExists($approverUser)) {
                    throw new \InvalidArgumentException('Choose an active agent as the approver.');
                }
            } elseif ($approverType === 'role') {
                $approverRole = (int) ($in['approver_role_id'] ?? 0);
                if (!mysqli_fetch_row(mysqli_query($this->mysqli, "SELECT 1 FROM user_roles WHERE role_id = $approverRole AND role_archived_at IS NULL"))) {
                    throw new \InvalidArgumentException('Choose a role as the approver.');
                }
            }
        }

        $actionType = $actionConfig = null;
        if ($type === 'action') {
            $actionType = (string) ($in['action_type'] ?? '');
            $clean = $this->runner->validate($actionType, (array) ($in['action_config'] ?? []), (string) $template['type']);
            $actionConfig = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return [
            'title' => $title,
            'category' => mb_substr(trim((string) ($in['category'] ?? '')), 0, 100) ?: null,
            'default_owner' => mb_substr(trim((string) ($in['default_owner'] ?? '')), 0, 100) ?: null,
            'instructions' => mb_substr(trim((string) ($in['instructions'] ?? '')), 0, 2000) ?: null,
            'required' => array_key_exists('required', $in) ? (empty($in['required']) ? 0 : 1) : 1, // the form always sends it; a caller that omits it gets a required task
            'task_type' => $type,
            'depends_on' => DependencyGraph::format($deps),
            'assignee_user_id' => $assignee > 0 ? $assignee : null,
            'due_offset_days' => $offset,
            'due_anchor' => $anchor,
            'approver_type' => $approverType,
            'approver_user_id' => $approverUser,
            'approver_role_id' => $approverRole,
            'action_type' => $actionType,
            'action_config' => $actionConfig,
        ];
    }

    private function agentExists(int $userId): bool
    {
        return $userId > 0 && (bool) mysqli_fetch_row(mysqli_query($this->mysqli, "SELECT 1 FROM users WHERE user_id = $userId AND user_type = 1 AND user_status = 1 AND user_archived_at IS NULL"));
    }

    /** @param array<string,mixed> $in @return int the new template_task_id @throws \InvalidArgumentException */
    public function add(int $templateId, array $in): int
    {
        $c = $this->normalize($templateId, 0, $in);
        $sort = (int) mysqli_fetch_row(mysqli_query($this->mysqli, "SELECT COALESCE(MAX(sort_order), -1) + 1 FROM workflow_template_tasks WHERE workflow_template_id = $templateId"))[0];
        $stmt = mysqli_prepare($this->mysqli, 'INSERT INTO workflow_template_tasks
            (workflow_template_id, title, category, default_owner, instructions, required, sort_order, task_type, depends_on, assignee_user_id, due_offset_days, due_anchor, approver_type, approver_user_id, approver_role_id, action_type, action_config)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        mysqli_stmt_bind_param($stmt, 'issssiissiissiiss', $templateId, $c['title'], $c['category'], $c['default_owner'], $c['instructions'], $c['required'], $sort, $c['task_type'], $c['depends_on'], $c['assignee_user_id'], $c['due_offset_days'], $c['due_anchor'], $c['approver_type'], $c['approver_user_id'], $c['approver_role_id'], $c['action_type'], $c['action_config']);
        mysqli_stmt_execute($stmt);
        $id = (int) mysqli_insert_id($this->mysqli);
        mysqli_stmt_close($stmt);

        return $id;
    }

    /** @param array<string,mixed> $in @throws \InvalidArgumentException */
    public function update(int $templateId, int $templateTaskId, array $in): void
    {
        if (!mysqli_fetch_row(mysqli_query($this->mysqli, "SELECT 1 FROM workflow_template_tasks WHERE template_task_id = $templateTaskId AND workflow_template_id = $templateId"))) {
            throw new \InvalidArgumentException('Task not found in this template.');
        }
        $c = $this->normalize($templateId, $templateTaskId, $in);
        $stmt = mysqli_prepare($this->mysqli, 'UPDATE workflow_template_tasks SET title = ?, category = ?, default_owner = ?, instructions = ?, required = ?, task_type = ?, depends_on = ?, assignee_user_id = ?, due_offset_days = ?, due_anchor = ?, approver_type = ?, approver_user_id = ?, approver_role_id = ?, action_type = ?, action_config = ?
            WHERE template_task_id = ? AND workflow_template_id = ?');
        mysqli_stmt_bind_param($stmt, 'ssssissiissiissii', $c['title'], $c['category'], $c['default_owner'], $c['instructions'], $c['required'], $c['task_type'], $c['depends_on'], $c['assignee_user_id'], $c['due_offset_days'], $c['due_anchor'], $c['approver_type'], $c['approver_user_id'], $c['approver_role_id'], $c['action_type'], $c['action_config'], $templateTaskId, $templateId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }

    /** Removes a task and takes it out of every other task's dependency list (runs already started keep their own copy). */
    public function delete(int $templateId, int $templateTaskId): void
    {
        mysqli_query($this->mysqli, "DELETE FROM workflow_template_tasks WHERE template_task_id = $templateTaskId AND workflow_template_id = $templateId");
        if (mysqli_affected_rows($this->mysqli) < 1) {
            return;
        }
        $res = mysqli_query($this->mysqli, "SELECT template_task_id, depends_on FROM workflow_template_tasks WHERE workflow_template_id = $templateId AND depends_on IS NOT NULL");
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $deps = DependencyGraph::parse($r['depends_on']);
            $left = array_values(array_diff($deps, [$templateTaskId]));
            if (count($left) !== count($deps)) {
                $list = DependencyGraph::format($left);
                $sql = $list === null ? 'NULL' : "'" . $list . "'";
                mysqli_query($this->mysqli, "UPDATE workflow_template_tasks SET depends_on = $sql WHERE template_task_id = " . (int) $r['template_task_id']);
            }
        }
    }
}
