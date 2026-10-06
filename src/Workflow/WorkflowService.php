<?php

namespace ITFlow\Workflow;

/**
 * Employee lifecycle workflow engine (master plan Section 16). A run is a checklist tied to a person; template tasks are
 * snapshotted onto the run when it starts, so editing a template later never rewrites a run already in progress.
 *
 * This started as a compatibility shim over RivetCore\Workflow\WorkflowService (flat checklists). Lifecycle automation needs
 * dependencies, approvals and action tasks in the same transactions, and vendor/ is read-only, so the engine now lives here.
 * A template with none of the new features (manual tasks, no dependencies, no due dates) produces exactly the rows and the
 * behaviour the flat engine did.
 *
 * Task states:
 *   pending         ready to be worked
 *   blocked         waiting for the tasks it depends on to be completed or skipped; cannot be completed or skipped
 *   running         an automated action is executing right now
 *   action_failed   an automated action failed after its retries; an agent completes it by hand or retries it
 *   rejected        an approval was refused; the run is paused until someone reopens or (an administrator) skips it
 *   completed / skipped
 * Run states add 'paused' (a rejected approval is waiting for a decision).
 */
class WorkflowService
{
    /** Tasks in these states count as resolved for dependencies and for finishing the run. */
    private const RESOLVED = ['completed', 'skipped'];

    private \mysqli $mysqli;
    private ?ActionGateway $gateway;
    private ?TaskActionRunner $runner = null;

    public function __construct(\mysqli $mysqli, ?ActionGateway $gateway = null)
    {
        $this->mysqli = $mysqli;
        $this->gateway = $gateway;
    }

    private function gateway(): ActionGateway
    {
        return $this->gateway ??= new LiveActionGateway($this->mysqli);
    }

    private function runner(): TaskActionRunner
    {
        return $this->runner ??= new TaskActionRunner($this->mysqli, $this->gateway());
    }

    /** @return array<string,mixed>|null */
    private function one(string $sql, string $types = '', array $params = []): ?array
    {
        $stmt = mysqli_prepare($this->mysqli, $sql);
        if ($types !== '') {
            mysqli_stmt_bind_param($stmt, $types, ...$params);
        }
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        return $row ?: null;
    }

    /** @return array<int,array<string,mixed>> */
    private function all(string $sql, string $types = '', array $params = []): array
    {
        $stmt = mysqli_prepare($this->mysqli, $sql);
        if ($types !== '') {
            mysqli_stmt_bind_param($stmt, $types, ...$params);
        }
        mysqli_stmt_execute($stmt);
        $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
        mysqli_stmt_close($stmt);

        return $rows;
    }

    // ------------------------------------------------------------------ starting

    /**
     * Starts a run and returns its id. $onlyIfNone: do nothing (return 0) when this person already has an open run
     * (in progress or paused) of this template; the check and the insert happen under a lock on the person's row, so two
     * events arriving together cannot start two runs.
     */
    public function startRun(int $templateId, int $contactId, ?int $startedByUserId, bool $onlyIfNone = false): int
    {
        $template = $this->one('SELECT * FROM workflow_templates WHERE workflow_template_id = ?', 'i', [$templateId]);
        if (!$template) {
            throw new \InvalidArgumentException("Workflow template $templateId not found");
        }

        mysqli_begin_transaction($this->mysqli);
        try {
            mysqli_query($this->mysqli, "SELECT contact_id FROM contacts WHERE contact_id = $contactId FOR UPDATE");
            if ($onlyIfNone && $this->openRunId($templateId, $contactId) > 0) {
                mysqli_commit($this->mysqli);

                return 0;
            }

            $stmt = mysqli_prepare($this->mysqli, 'INSERT INTO workflow_runs (workflow_template_id, contact_id, type, started_by) VALUES (?, ?, ?, ?)');
            mysqli_stmt_bind_param($stmt, 'iisi', $templateId, $contactId, $template['type'], $startedByUserId);
            mysqli_stmt_execute($stmt);
            $runId = (int) mysqli_insert_id($this->mysqli);
            mysqli_stmt_close($stmt);

            $contact = $this->one('SELECT contact_start_date, contact_expected_end_date FROM contacts WHERE contact_id = ?', 'i', [$contactId]) ?? [];
            $runStartedAt = (string) mysqli_fetch_row(mysqli_query($this->mysqli, "SELECT started_at FROM workflow_runs WHERE run_id = $runId"))[0];

            $templateTasks = $this->all('SELECT * FROM workflow_template_tasks WHERE workflow_template_id = ? ORDER BY sort_order ASC, template_task_id ASC', 'i', [$templateId]);
            $map = []; // template_task_id => run_task_id
            $insert = mysqli_prepare($this->mysqli, "INSERT INTO workflow_run_tasks
                (run_id, template_task_id, title, instructions, category, default_owner, required, sort_order, task_type, assignee_user_id, due_at,
                 approver_type, approver_user_id, approver_role_id, approval_status, action_type, action_config)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($templateTasks as $task) {
                $tid = (int) $task['template_task_id'];
                $required = (int) $task['required'];
                $sort = (int) $task['sort_order'];
                $type = (string) ($task['task_type'] ?? 'manual');
                $assignee = $task['assignee_user_id'] !== null ? (int) $task['assignee_user_id'] : null;
                $due = DueDateResolver::resolve($task['due_offset_days'] !== null ? (int) $task['due_offset_days'] : null, (string) ($task['due_anchor'] ?? 'run'), $contact['contact_start_date'] ?? null, $contact['contact_expected_end_date'] ?? null, $runStartedAt);
                $approverType = $type === 'approval' ? $task['approver_type'] : null;
                $approverUser = $type === 'approval' && $task['approver_user_id'] !== null ? (int) $task['approver_user_id'] : null;
                $approverRole = $type === 'approval' && $task['approver_role_id'] !== null ? (int) $task['approver_role_id'] : null;
                $approval = $type === 'approval' ? 'pending' : null;
                $actionType = $type === 'action' ? $task['action_type'] : null;
                $actionConfig = $type === 'action' ? $task['action_config'] : null;
                mysqli_stmt_bind_param($insert, 'iissssiisissiisss', $runId, $tid, $task['title'], $task['instructions'], $task['category'], $task['default_owner'], $required, $sort, $type, $assignee, $due, $approverType, $approverUser, $approverRole, $approval, $actionType, $actionConfig);
                mysqli_stmt_execute($insert);
                $map[$tid] = (int) mysqli_insert_id($this->mysqli);
            }
            mysqli_stmt_close($insert);

            // Second pass: translate dependencies from template task ids to this run's task ids.
            foreach ($templateTasks as $task) {
                $deps = [];
                foreach (DependencyGraph::parse($task['depends_on'] ?? null) as $dep) {
                    if (isset($map[$dep])) {
                        $deps[] = $map[$dep]; // a dependency on a task since deleted is dropped
                    }
                }
                if ($deps) {
                    $runTaskId = $map[(int) $task['template_task_id']];
                    $list = DependencyGraph::format($deps);
                    mysqli_query($this->mysqli, "UPDATE workflow_run_tasks SET depends_on = '$list', status = 'blocked' WHERE run_task_id = $runTaskId");
                }
            }
            mysqli_commit($this->mysqli);
        } catch (\Throwable $e) {
            mysqli_rollback($this->mysqli);
            throw $e;
        }

        // Automated tasks that are ready run now; approvers of ready approval tasks are told.
        $this->advance($runId);

        return $runId;
    }

    /** Idempotent start for automation: null when this person already has an open run of this template. */
    public function startRunIfNone(int $templateId, int $contactId, ?int $startedByUserId): ?int
    {
        $id = $this->startRun($templateId, $contactId, $startedByUserId, true);

        return $id > 0 ? $id : null;
    }

    public function openRunId(int $templateId, int $contactId): int
    {
        $row = $this->one("SELECT run_id FROM workflow_runs WHERE workflow_template_id = ? AND contact_id = ? AND status IN ('in_progress','paused') ORDER BY run_id DESC LIMIT 1", 'ii', [$templateId, $contactId]);

        return (int) ($row['run_id'] ?? 0);
    }

    /**
     * Dry run of startRun(): what would happen, in what order, with due dates resolved and what each action would do.
     * Strictly read-only: it writes nothing, queues nothing and sends nothing.
     *
     * @return array{template: array<string,mixed>, contact: array<string,mixed>, tasks: list<array<string,mixed>>, warnings: list<string>}
     */
    public function previewRun(int $templateId, int $contactId): array
    {
        $template = $this->one('SELECT * FROM workflow_templates WHERE workflow_template_id = ?', 'i', [$templateId]);
        $contact = TaskActionRunner::loadContact($this->mysqli, $contactId);
        if (!$template || !$contact) {
            throw new \InvalidArgumentException('Template or person not found');
        }
        $now = (string) mysqli_fetch_row(mysqli_query($this->mysqli, 'SELECT NOW()'))[0];
        $rows = $this->all('SELECT * FROM workflow_template_tasks WHERE workflow_template_id = ? ORDER BY sort_order ASC, template_task_id ASC', 'i', [$templateId]);
        $byId = [];
        foreach ($rows as $r) {
            $byId[(int) $r['template_task_id']] = $r;
        }

        // "Wave" = how many dependency steps come before the task; tasks in wave 1 are ready as soon as the run starts.
        $wave = [];
        $waveOf = function (int $id, int $depth = 0) use (&$waveOf, &$wave, $byId): int {
            if (isset($wave[$id])) {
                return $wave[$id];
            }
            if ($depth > 50) {
                return 1; // a loop cannot be saved, but never recurse forever on bad data
            }
            $w = 1;
            foreach (DependencyGraph::parse($byId[$id]['depends_on'] ?? null) as $dep) {
                if (isset($byId[$dep])) {
                    $w = max($w, 1 + $waveOf($dep, $depth + 1));
                }
            }

            return $wave[$id] = $w;
        };

        $runner = $this->runner();
        $warnings = [];
        $tasks = [];
        foreach ($rows as $r) {
            $id = (int) $r['template_task_id'];
            $type = (string) ($r['task_type'] ?? 'manual');
            $deps = array_values(array_filter(DependencyGraph::parse($r['depends_on'] ?? null), static fn ($d) => isset($byId[$d])));
            $what = '';
            if ($type === 'action') {
                $ctx = TaskActionRunner::contextFor($contact, (string) $template['name'], (string) $r['title'], 0, 0);
                $what = $runner->describe((string) $r['action_type'], $r['action_config'], $ctx);
                if (!$runner->has((string) $r['action_type'])) {
                    $warnings[] = 'Task "' . $r['title'] . '" uses an unknown action.';
                }
            } elseif ($type === 'approval') {
                $what = 'Waits for approval by ' . $this->approverLabel($r, $contact);
            }
            $tasks[] = [
                'template_task_id' => $id,
                'order' => 0,
                'wave' => $waveOf($id),
                'title' => (string) $r['title'],
                'type' => $type,
                'required' => (int) $r['required'],
                'depends_on_titles' => array_map(static fn ($d) => (string) $byId[$d]['title'], $deps),
                'starts' => $deps ? 'blocked' : 'ready',
                'due_at' => DueDateResolver::resolve($r['due_offset_days'] !== null ? (int) $r['due_offset_days'] : null, (string) ($r['due_anchor'] ?? 'run'), $contact['contact_start_date'] ?? null, $contact['contact_expected_end_date'] ?? null, $now),
                'due_anchor' => (string) ($r['due_anchor'] ?? 'run'),
                'assignee' => $this->userName($r['assignee_user_id'] !== null ? (int) $r['assignee_user_id'] : 0) ?: (string) $r['default_owner'],
                'what' => $what,
            ];
            if (($r['due_anchor'] ?? 'run') === 'start' && $r['due_offset_days'] !== null && empty($contact['contact_start_date'])) {
                $warnings[] = 'Task "' . $r['title'] . '" is due relative to the start date, but this person has none; it falls back to the run start.';
            }
            if (($r['due_anchor'] ?? 'run') === 'end' && $r['due_offset_days'] !== null && empty($contact['contact_expected_end_date'])) {
                $warnings[] = 'Task "' . $r['title'] . '" is due relative to the end date, but this person has none; it falls back to the run start.';
            }
        }
        usort($tasks, static fn ($a, $b) => [$a['wave'], $a['template_task_id']] <=> [$b['wave'], $b['template_task_id']]);
        foreach ($tasks as $i => &$t) {
            $t['order'] = $i + 1;
        }
        unset($t);

        return ['template' => $template, 'contact' => $contact, 'tasks' => $tasks, 'warnings' => $warnings];
    }

    private function userName(int $userId): string
    {
        if ($userId <= 0) {
            return '';
        }

        return (string) ($this->one('SELECT user_name FROM users WHERE user_id = ?', 'i', [$userId])['user_name'] ?? '');
    }

    /** @param array<string,mixed> $task template or run task row @param array<string,mixed> $contact */
    public function approverLabel(array $task, array $contact): string
    {
        switch ($task['approver_type'] ?? null) {
            case 'user':
                return $this->userName((int) ($task['approver_user_id'] ?? 0)) ?: 'a specific user';
            case 'role':
                $role = $this->one('SELECT role_name FROM user_roles WHERE role_id = ?', 'i', [(int) ($task['approver_role_id'] ?? 0)]);

                return 'anyone with the role ' . ($role['role_name'] ?? 'unknown');
            case 'manager':
                return 'the manager of ' . ($contact['contact_name'] ?? 'the employee') . (!empty($contact['manager_name']) ? ' (' . $contact['manager_name'] . ')' : ' (none set: administrators decide)');
        }

        return 'nobody (approver not set: administrators decide)';
    }

    // ------------------------------------------------------------------ working tasks

    /** @return array<string,mixed> */
    private function task(int $runTaskId): array
    {
        $t = $this->one('SELECT t.*, r.status AS run_status, r.contact_id, r.started_by FROM workflow_run_tasks t JOIN workflow_runs r ON r.run_id = t.run_id WHERE t.run_task_id = ?', 'i', [$runTaskId]);
        if (!$t) {
            throw new \DomainException('That task no longer exists.');
        }

        return $t;
    }

    /** True when the task belongs to the run (handlers use it so a task id from another run/department cannot be acted on). */
    public function taskInRun(int $runTaskId, int $runId): bool
    {
        return $this->one('SELECT 1 AS x FROM workflow_run_tasks WHERE run_task_id = ? AND run_id = ?', 'ii', [$runTaskId, $runId]) !== null;
    }

    /** @throws \DomainException when the task is waiting on others, running, rejected or needs approval */
    public function completeTask(int $runTaskId, ?int $userId): void
    {
        $t = $this->task($runTaskId);
        if (in_array($t['status'], self::RESOLVED, true)) {
            return; // already done: a second click is harmless
        }
        $this->assertWorkable($t, 'completed');
        if ($t['task_type'] === 'approval' && $t['approval_status'] !== 'approved') {
            throw new \DomainException('This task needs an approval. It is completed by the approver, not by ticking it off.');
        }
        mysqli_query($this->mysqli, "UPDATE workflow_run_tasks SET status = 'completed', completed_by = " . ($userId === null ? 'NULL' : (int) $userId) . ", completed_at = NOW(), skip_reason = NULL, running_since = NULL WHERE run_task_id = $runTaskId");
        if ($t['task_type'] === 'action' && $t['status'] === 'action_failed') {
            $this->runner()->log((int) $t['run_id'], $runTaskId, 'manual_complete', $t['action_type'], true, (int) $t['attempts'], 'completed by hand after the automated action failed', $userId);
        }
        $this->gateway()->emitEvent('workflow.task_completed', ['run_id' => (int) $t['run_id'], 'run_task_id' => $runTaskId, 'task_title' => (string) $t['title'], 'contact_id' => (int) $t['contact_id'], 'completed_by' => 'person', 'completed_by_user_id' => $userId]);
        $this->afterResolve((int) $t['run_id']);
    }

    /**
     * @param bool $isAdmin administrators may skip an approval task (that is overriding the approval, and is logged as such)
     * @throws \DomainException
     */
    public function skipTask(int $runTaskId, string $reason, ?int $userId, bool $isAdmin = false): void
    {
        $t = $this->task($runTaskId);
        if (in_array($t['status'], self::RESOLVED, true)) {
            return;
        }
        if ($t['status'] === 'rejected' && !$isAdmin) {
            throw new \DomainException('This approval was rejected. Only an administrator can continue without it.');
        }
        if ($t['status'] !== 'rejected') {
            $this->assertWorkable($t, 'skipped');
        }
        if ($t['task_type'] === 'approval' && !$isAdmin) {
            throw new \DomainException('An approval task can only be skipped by an administrator.');
        }
        $stmt = mysqli_prepare($this->mysqli, "UPDATE workflow_run_tasks SET status = 'skipped', completed_by = ?, completed_at = NOW(), skip_reason = ?, running_since = NULL WHERE run_task_id = ?");
        mysqli_stmt_bind_param($stmt, 'isi', $userId, $reason, $runTaskId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        if ($t['task_type'] === 'approval') {
            $this->runner()->log((int) $t['run_id'], $runTaskId, 'approval_overridden', null, true, 0, 'approval skipped by an administrator: ' . $reason, $userId);
            $this->gateway()->audit('workflow.approval_overridden', $userId, 'contact', (int) $t['contact_id'], 'skipped', 'Approval task "' . $t['title'] . '" skipped by an administrator', ['run_id' => (int) $t['run_id'], 'run_task_id' => $runTaskId]);
        }
        $this->resumeIfNothingRejected((int) $t['run_id']);
        $this->afterResolve((int) $t['run_id']);
    }

    private function assertWorkable(array $t, string $verb): void
    {
        if ($t['status'] === 'blocked') {
            throw new \DomainException('This task is waiting for the tasks it depends on and cannot be ' . $verb . ' yet.');
        }
        if ($t['status'] === 'running') {
            throw new \DomainException('This automated task is running right now.');
        }
        if ($t['status'] === 'rejected') {
            throw new \DomainException('This approval was rejected. Reopen it to ask again.');
        }
    }

    /** Puts a finished (or failed/rejected) task back to work. Dependents that were waiting on it wait again. */
    public function reopenTask(int $runTaskId): void
    {
        $t = $this->task($runTaskId);
        if (in_array($t['status'], ['pending', 'blocked', 'running'], true)) {
            return;
        }
        $extra = '';
        if ($t['task_type'] === 'approval') {
            $this->runner()->log((int) $t['run_id'], $runTaskId, 'approval_reset', null, true, 0, 'task reopened; approval asked for again', null);
            $extra = ", approval_status = 'pending', approval_notified_at = NULL, approved_by = NULL, approved_at = NULL, approval_comment = NULL";
        }
        mysqli_query($this->mysqli, "UPDATE workflow_run_tasks SET status = 'pending', completed_by = NULL, completed_at = NULL, skip_reason = NULL$extra WHERE run_task_id = $runTaskId");
        $runId = (int) $t['run_id'];
        // Reopening a task un-completes the run too, if it had been marked done.
        mysqli_query($this->mysqli, "UPDATE workflow_runs SET status = 'in_progress', completed_at = NULL WHERE run_id = $runId AND status IN ('completed','completed_with_exceptions')");
        $this->resumeIfNothingRejected($runId);
        $this->advance($runId);
    }

    /** Runs an automated task again (after a failure, or one that was reopened). @return bool true when it completed */
    public function retryAction(int $runTaskId, ?int $userId): bool
    {
        $t = $this->task($runTaskId);
        if ($t['task_type'] !== 'action' || !in_array($t['status'], ['action_failed', 'pending'], true)) {
            throw new \DomainException('Only an automated task that failed or was reopened can be run again.');
        }
        if (in_array($t['run_status'], ['cancelled', 'paused'], true)) {
            throw new \DomainException('This workflow is ' . $t['run_status'] . '.');
        }
        $this->runner()->log((int) $t['run_id'], $runTaskId, 'retry_requested', $t['action_type'], true, (int) $t['attempts'], 'run again by an agent', $userId);
        $ok = $this->runner()->run($runTaskId, $userId);
        $this->afterResolve((int) $t['run_id']);

        return $ok;
    }

    public function cancelRun(int $runId): void
    {
        mysqli_query($this->mysqli, "UPDATE workflow_runs SET status = 'cancelled' WHERE run_id = $runId");
    }

    // ------------------------------------------------------------------ approvals

    /** @return int[] agents who can decide this approval task: the named approver(s), plus every administrator. */
    public function approverUserIds(array $task, array $contact = []): array
    {
        $ids = [];
        switch ($task['approver_type'] ?? null) {
            case 'user':
                $ids[] = (int) ($task['approver_user_id'] ?? 0);
                break;
            case 'role':
                foreach ($this->all('SELECT user_id FROM users WHERE user_role_id = ? AND user_type = 1 AND user_status = 1 AND user_archived_at IS NULL', 'i', [(int) ($task['approver_role_id'] ?? 0)]) as $r) {
                    $ids[] = (int) $r['user_id'];
                }
                break;
            case 'manager':
                $mu = (int) ($contact['manager_user_id'] ?? 0);
                if ($mu > 0 && $this->one('SELECT 1 AS x FROM users WHERE user_id = ? AND user_type = 1 AND user_status = 1 AND user_archived_at IS NULL', 'i', [$mu])) {
                    $ids[] = $mu;
                }
                break;
        }
        $ids = array_values(array_filter(array_unique($ids)));
        if (!$ids) {
            $ids = $this->adminUserIds(); // nobody named (or reachable): administrators decide, so the run never gets stuck
        }

        return $ids;
    }

    /** @return int[] */
    private function adminUserIds(): array
    {
        return array_map(static fn ($r) => (int) $r['user_id'], $this->all('SELECT u.user_id FROM users u JOIN user_roles r ON r.role_id = u.user_role_id WHERE r.role_is_admin = 1 AND u.user_type = 1 AND u.user_status = 1 AND u.user_archived_at IS NULL'));
    }

    public function canDecide(int $runTaskId, int $userId): bool
    {
        $t = $this->task($runTaskId);
        if ($t['task_type'] !== 'approval') {
            return false;
        }
        $u = $this->one('SELECT u.user_id, u.user_role_id, COALESCE(r.role_is_admin, 0) AS is_admin FROM users u LEFT JOIN user_roles r ON r.role_id = u.user_role_id WHERE u.user_id = ? AND u.user_type = 1 AND u.user_status = 1 AND u.user_archived_at IS NULL', 'i', [$userId]);
        if (!$u) {
            return false;
        }
        if ((int) $u['is_admin'] === 1) {
            return true;
        }
        $contact = TaskActionRunner::loadContact($this->mysqli, (int) $t['contact_id']) ?? [];

        return in_array($userId, $this->approverUserIds($t, $contact), true);
    }

    /** @throws \DomainException */
    public function approveTask(int $runTaskId, int $userId, string $comment = ''): void
    {
        $t = $this->decisionTask($runTaskId, $userId);
        $stmt = mysqli_prepare($this->mysqli, "UPDATE workflow_run_tasks SET approval_status = 'approved', status = 'completed', approved_by = ?, approved_at = NOW(), approval_comment = ?, completed_by = ?, completed_at = NOW()
            WHERE run_task_id = ? AND status = 'pending' AND approval_status = 'pending'");
        $comment = mb_substr($comment, 0, 500);
        mysqli_stmt_bind_param($stmt, 'isii', $userId, $comment, $userId, $runTaskId);
        mysqli_stmt_execute($stmt);
        $changed = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);
        if ($changed !== 1) {
            throw new \DomainException('This approval has already been decided.');
        }
        $this->runner()->log((int) $t['run_id'], $runTaskId, 'approval_approved', null, true, 0, $comment !== '' ? $comment : 'approved', $userId);
        $this->gateway()->audit('workflow.approval_approved', $userId, 'contact', (int) $t['contact_id'], 'approved', 'Approval task "' . $t['title'] . '" approved', ['run_id' => (int) $t['run_id'], 'run_task_id' => $runTaskId]);
        $this->gateway()->emitEvent('workflow.task_completed', ['run_id' => (int) $t['run_id'], 'run_task_id' => $runTaskId, 'task_title' => (string) $t['title'], 'contact_id' => (int) $t['contact_id'], 'completed_by' => 'approval', 'completed_by_user_id' => $userId]);
        $this->afterResolve((int) $t['run_id']);
    }

    /** A rejection pauses the whole run and notifies whoever started it (and the task's assignee). @throws \DomainException */
    public function rejectTask(int $runTaskId, int $userId, string $comment = ''): void
    {
        $t = $this->decisionTask($runTaskId, $userId);
        $stmt = mysqli_prepare($this->mysqli, "UPDATE workflow_run_tasks SET approval_status = 'rejected', status = 'rejected', approved_by = ?, approved_at = NOW(), approval_comment = ?
            WHERE run_task_id = ? AND status = 'pending' AND approval_status = 'pending'");
        $comment = mb_substr($comment, 0, 500);
        mysqli_stmt_bind_param($stmt, 'isi', $userId, $comment, $runTaskId);
        mysqli_stmt_execute($stmt);
        $changed = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);
        if ($changed !== 1) {
            throw new \DomainException('This approval has already been decided.');
        }
        $runId = (int) $t['run_id'];
        mysqli_query($this->mysqli, "UPDATE workflow_runs SET status = 'paused' WHERE run_id = $runId AND status = 'in_progress'");
        $this->runner()->log($runId, $runTaskId, 'approval_rejected', null, true, 0, $comment !== '' ? $comment : 'rejected', $userId);
        $contact = TaskActionRunner::loadContact($this->mysqli, (int) $t['contact_id']) ?? [];
        $this->gateway()->audit('workflow.approval_rejected', $userId, 'contact', (int) $t['contact_id'], 'rejected', 'Approval task "' . $t['title'] . '" rejected; the workflow is paused', ['run_id' => $runId, 'run_task_id' => $runTaskId]);
        $message = 'Approval "' . $t['title'] . '" for ' . ($contact['contact_name'] ?? 'an employee') . ' was rejected' . ($comment !== '' ? ': ' . $comment : '') . '. The workflow is paused.';
        $targets = array_values(array_unique(array_filter([(int) ($t['started_by'] ?? 0), (int) ($t['assignee_user_id'] ?? 0)])));
        foreach ($targets ?: [0] as $uid) {
            $this->gateway()->notifyUser($uid, 'Workflow', $message, 'workflow_run.php?run_id=' . $runId, (int) ($contact['contact_client_id'] ?? 0), (int) $t['contact_id']);
        }
    }

    /** @return array<string,mixed> */
    private function decisionTask(int $runTaskId, int $userId): array
    {
        $t = $this->task($runTaskId);
        if ($t['task_type'] !== 'approval' || $t['status'] !== 'pending' || $t['approval_status'] !== 'pending') {
            throw new \DomainException('This task is not waiting for an approval.');
        }
        if (in_array($t['run_status'], ['cancelled', 'paused'], true)) {
            throw new \DomainException('This workflow is ' . $t['run_status'] . '.');
        }
        if (!$this->canDecide($runTaskId, $userId)) {
            throw new \DomainException('You are not an approver for this task.');
        }

        return $t;
    }

    // ------------------------------------------------------------------ engine

    private function afterResolve(int $runId): void
    {
        $this->syncBlocked($runId);
        $this->refreshRunStatus($runId);
        $this->advance($runId);
    }

    /** Moves tasks between blocked and pending as their dependencies resolve or reopen. @return bool whether anything changed */
    private function syncBlocked(int $runId): bool
    {
        $rows = $this->all('SELECT run_task_id, status, depends_on FROM workflow_run_tasks WHERE run_id = ?', 'i', [$runId]);
        $status = [];
        foreach ($rows as $r) {
            $status[(int) $r['run_task_id']] = $r['status'];
        }
        $changed = false;
        foreach ($rows as $r) {
            if (!in_array($r['status'], ['pending', 'blocked'], true)) {
                continue;
            }
            $deps = DependencyGraph::parse($r['depends_on']);
            if (!$deps) {
                continue;
            }
            $unmet = false;
            foreach ($deps as $d) {
                if (isset($status[$d]) && !in_array($status[$d], self::RESOLVED, true)) {
                    $unmet = true;
                    break;
                }
            }
            $want = $unmet ? 'blocked' : 'pending';
            if ($want !== $r['status']) {
                $id = (int) $r['run_task_id'];
                mysqli_query($this->mysqli, "UPDATE workflow_run_tasks SET status = '$want' WHERE run_task_id = $id AND status = '" . $r['status'] . "'");
                $status[$id] = $want;
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * Does the work that is due: unblocks tasks, runs ready automated tasks (once; a retry is a person's decision), and tells
     * the approvers of ready approval tasks. Does nothing on a cancelled or paused run.
     */
    public function advance(int $runId): void
    {
        for ($pass = 0; $pass < 25; $pass++) {
            $changed = $this->syncBlocked($runId);
            $run = $this->one('SELECT status, contact_id, started_by FROM workflow_runs WHERE run_id = ?', 'i', [$runId]);
            if (!$run || in_array($run['status'], ['cancelled', 'paused'], true)) {
                return;
            }
            foreach ($this->all("SELECT run_task_id FROM workflow_run_tasks WHERE run_id = ? AND status = 'pending' AND task_type = 'action' AND attempts = 0 ORDER BY sort_order, run_task_id", 'i', [$runId]) as $r) {
                $this->runner()->run((int) $r['run_task_id'], null);
                $changed = true;
            }
            if ($changed) {
                $this->refreshRunStatus($runId);
            }
            $contact = null;
            foreach ($this->all("SELECT * FROM workflow_run_tasks WHERE run_id = ? AND status = 'pending' AND task_type = 'approval' AND approval_notified_at IS NULL", 'i', [$runId]) as $t) {
                $contact ??= TaskActionRunner::loadContact($this->mysqli, (int) $run['contact_id']) ?? [];
                $this->notifyApprovers($t, $contact);
                mysqli_query($this->mysqli, 'UPDATE workflow_run_tasks SET approval_notified_at = NOW() WHERE run_task_id = ' . (int) $t['run_task_id']);
                $changed = true;
            }
            if (!$changed) {
                return;
            }
        }
    }

    private function notifyApprovers(array $task, array $contact): void
    {
        $message = 'Approval needed: "' . $task['title'] . '" for ' . ($contact['contact_name'] ?? 'an employee');
        foreach ($this->approverUserIds($task, $contact) as $uid) {
            $this->gateway()->notifyUser($uid, 'Workflow', $message, 'workflow_run.php?run_id=' . (int) $task['run_id'], (int) ($contact['contact_client_id'] ?? 0), (int) ($contact['contact_id'] ?? 0));
        }
    }

    private function resumeIfNothingRejected(int $runId): void
    {
        if (!$this->one("SELECT 1 AS x FROM workflow_run_tasks WHERE run_id = ? AND status = 'rejected'", 'i', [$runId])) {
            mysqli_query($this->mysqli, "UPDATE workflow_runs SET status = 'in_progress' WHERE run_id = $runId AND status = 'paused'");
        }
    }

    /**
     * Recomputes and persists the run's status from its tasks: completed (all required tasks done), completed_with_exceptions
     * (all required tasks resolved but at least one was skipped), or left in progress while any required task is unresolved
     * (pending, blocked, running, failed or rejected). Optional tasks never gate completion.
     */
    private function refreshRunStatus(int $runId): void
    {
        $tasks = $this->all('SELECT status, required FROM workflow_run_tasks WHERE run_id = ?', 'i', [$runId]);
        foreach ($tasks as $t) {
            if ($t['required'] && !in_array($t['status'], self::RESOLVED, true)) {
                return;
            }
        }
        $newStatus = array_filter($tasks, static fn ($t) => $t['status'] === 'skipped') ? 'completed_with_exceptions' : 'completed';
        mysqli_query($this->mysqli, "UPDATE workflow_runs SET status = '$newStatus', completed_at = NOW() WHERE run_id = $runId AND status = 'in_progress'");
    }
}
