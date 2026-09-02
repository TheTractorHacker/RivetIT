<?php

namespace ITFlow\Workflow;

/**
 * Manual-first employee lifecycle workflow engine (master plan Section 16,
 * scoped to Section 53's recommended first release: a checklist tied to a
 * person, not the fuller model with task dependencies/approvals/automation
 * actions - those need real usage first to know if they're worth the
 * complexity, see PROGRESS.md).
 *
 * Template tasks are snapshotted onto the run when it starts (title/
 * instructions copied, not referenced) so editing a template later never
 * rewrites the history of a run already in progress or completed.
 */
class WorkflowService
{
    private \mysqli $mysqli;

    public function __construct(\mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    public function startRun(int $templateId, int $contactId, ?int $startedByUserId): int
    {
        $template = $this->fetchOne("SELECT * FROM workflow_templates WHERE workflow_template_id = ?", 'i', [$templateId]);
        if (!$template) {
            throw new \InvalidArgumentException("Workflow template $templateId not found");
        }

        $stmt = $this->mysqli->prepare("INSERT INTO workflow_runs (workflow_template_id, contact_id, type, started_by) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('iisi', $templateId, $contactId, $template['type'], $startedByUserId);
        $stmt->execute();
        $runId = $stmt->insert_id;
        $stmt->close();

        $tasks = $this->fetchAll("SELECT * FROM workflow_template_tasks WHERE workflow_template_id = ? ORDER BY sort_order ASC", 'i', [$templateId]);
        foreach ($tasks as $task) {
            $stmt = $this->mysqli->prepare(
                "INSERT INTO workflow_run_tasks (run_id, title, instructions, category, default_owner, required, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param(
                'issssii',
                $runId,
                $task['title'],
                $task['instructions'],
                $task['category'],
                $task['default_owner'],
                $task['required'],
                $task['sort_order']
            );
            $stmt->execute();
            $stmt->close();
        }

        return $runId;
    }

    public function completeTask(int $runTaskId, ?int $userId): void
    {
        $stmt = $this->mysqli->prepare("UPDATE workflow_run_tasks SET status = 'completed', completed_by = ?, completed_at = NOW(), skip_reason = NULL WHERE run_task_id = ?");
        $stmt->bind_param('ii', $userId, $runTaskId);
        $stmt->execute();
        $stmt->close();

        $this->refreshRunStatus($this->runIdForTask($runTaskId));
    }

    public function reopenTask(int $runTaskId): void
    {
        $stmt = $this->mysqli->prepare("UPDATE workflow_run_tasks SET status = 'pending', completed_by = NULL, completed_at = NULL, skip_reason = NULL WHERE run_task_id = ?");
        $stmt->bind_param('i', $runTaskId);
        $stmt->execute();
        $stmt->close();

        $runId = $this->runIdForTask($runTaskId);
        // Reopening a task un-completes the run too, if it had been marked done.
        $stmt = $this->mysqli->prepare("UPDATE workflow_runs SET status = 'in_progress', completed_at = NULL WHERE run_id = ? AND status != 'cancelled'");
        $stmt->bind_param('i', $runId);
        $stmt->execute();
        $stmt->close();
    }

    public function skipTask(int $runTaskId, string $reason, ?int $userId): void
    {
        $stmt = $this->mysqli->prepare("UPDATE workflow_run_tasks SET status = 'skipped', completed_by = ?, completed_at = NOW(), skip_reason = ? WHERE run_task_id = ?");
        $stmt->bind_param('isi', $userId, $reason, $runTaskId);
        $stmt->execute();
        $stmt->close();

        $this->refreshRunStatus($this->runIdForTask($runTaskId));
    }

    public function cancelRun(int $runId): void
    {
        $stmt = $this->mysqli->prepare("UPDATE workflow_runs SET status = 'cancelled' WHERE run_id = ?");
        $stmt->bind_param('i', $runId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Recomputes and persists the run's status from its tasks: complete
     * (all required tasks done), completed_with_exceptions (all required
     * tasks resolved but at least one was skipped rather than completed),
     * or left in_progress if any required task is still pending.
     */
    private function refreshRunStatus(int $runId): void
    {
        $tasks = $this->fetchAll("SELECT status, required FROM workflow_run_tasks WHERE run_id = ?", 'i', [$runId]);

        $requiredPending = array_filter($tasks, fn($t) => $t['required'] && $t['status'] === 'pending');
        if ($requiredPending) {
            return; // still in progress, nothing to update
        }

        $anySkipped = array_filter($tasks, fn($t) => $t['status'] === 'skipped');
        $newStatus = $anySkipped ? 'completed_with_exceptions' : 'completed';

        $stmt = $this->mysqli->prepare("UPDATE workflow_runs SET status = ?, completed_at = NOW() WHERE run_id = ? AND status = 'in_progress'");
        $stmt->bind_param('si', $newStatus, $runId);
        $stmt->execute();
        $stmt->close();
    }

    private function runIdForTask(int $runTaskId): int
    {
        $row = $this->fetchOne("SELECT run_id FROM workflow_run_tasks WHERE run_task_id = ?", 'i', [$runTaskId]);
        return (int) ($row['run_id'] ?? 0);
    }

    private function fetchOne(string $sql, string $types, array $params): ?array
    {
        $stmt = $this->mysqli->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    private function fetchAll(string $sql, string $types, array $params): array
    {
        $stmt = $this->mysqli->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = [];
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }
}
