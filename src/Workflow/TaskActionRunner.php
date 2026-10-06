<?php

namespace ITFlow\Workflow;

use ITFlow\Workflow\Actions\ActionInterface;
use ITFlow\Workflow\Actions\CreateTicketAction;
use ITFlow\Workflow\Actions\DisableContactLoginAction;
use ITFlow\Workflow\Actions\NotifyUserAction;
use ITFlow\Workflow\Actions\SendMailAction;
use ITFlow\Workflow\Actions\SendWebhookAction;

/**
 * Runs automated ("action") workflow tasks. A small registry maps action_type to a class in src/Workflow/Actions.
 *
 * Failure handling: an execution makes up to MAX_ATTEMPTS attempts; every attempt is written to workflow_task_log (and the
 * audit trail) and counted on the task (attempts, last_error). If all fail the task moves to 'action_failed', a manual state
 * an agent can finish by hand (Complete) or run again (Retry). Nothing in a failed action is rolled back or hidden.
 */
class TaskActionRunner
{
    public const MAX_ATTEMPTS = 3;

    private \mysqli $mysqli;
    private ActionGateway $gateway;
    /** @var array<string, ActionInterface> */
    private array $actions = [];

    public function __construct(\mysqli $mysqli, ActionGateway $gateway, ?array $actions = null)
    {
        $this->mysqli = $mysqli;
        $this->gateway = $gateway;
        foreach ($actions ?? self::defaultActions() as $action) {
            $this->actions[$action->type()] = $action;
        }
    }

    /** @return ActionInterface[] */
    public static function defaultActions(): array
    {
        return [new CreateTicketAction(), new SendMailAction(), new NotifyUserAction(), new SendWebhookAction(), new DisableContactLoginAction()];
    }

    /** @return array<string,string> action_type => label (for the template editor); needs no database */
    public static function labels(): array
    {
        $out = [];
        foreach (self::defaultActions() as $a) {
            $out[$a->type()] = $a->label();
        }

        return $out;
    }

    public function has(string $type): bool
    {
        return isset($this->actions[$type]);
    }

    /**
     * Validates an action task definition when a template is saved. @param array<string,mixed>|string|null $config
     * @return array<string,mixed> the clean config
     * @throws \InvalidArgumentException
     */
    public function validate(string $type, $config, string $templateType = 'onboarding'): array
    {
        if (!isset($this->actions[$type])) {
            throw new \InvalidArgumentException('Choose what the automated task does.');
        }
        if (is_string($config)) {
            $config = json_decode($config, true);
        }
        if ($type === 'disable_contact_login' && $templateType !== 'offboarding') {
            throw new \InvalidArgumentException('Disabling a login is only available in offboarding templates.');
        }

        return $this->actions[$type]->validate(is_array($config) ? $config : []);
    }

    /** What an action would do, for the dry-run preview. No writes, nothing sent. */
    public function describe(string $type, ?string $configJson, array $ctx): string
    {
        $action = $this->actions[$type] ?? null;
        if ($action === null) {
            return "Unknown action \"$type\" (the task would need to be done by hand)";
        }
        $config = json_decode((string) $configJson, true);

        return $action->describe(is_array($config) ? $config : [], $ctx);
    }

    /**
     * Placeholder values and ids for one person. @param array<string,mixed> $contact row from loadContact()
     * @return array<string,mixed>
     */
    public static function contextFor(array $contact, string $templateName, string $taskTitle, int $runId, int $runTaskId): array
    {
        return [
            'contact_id' => (int) ($contact['contact_id'] ?? 0),
            'client_id' => (int) ($contact['contact_client_id'] ?? 0),
            'run_id' => $runId,
            'run_task_id' => $runTaskId,
            'vars' => [
                'employee_name' => (string) ($contact['contact_name'] ?? ''),
                'employee_email' => (string) ($contact['contact_email'] ?? ''),
                'employee_title' => (string) ($contact['contact_title'] ?? ''),
                'start_date' => (string) ($contact['contact_start_date'] ?? ''),
                'end_date' => (string) ($contact['contact_expected_end_date'] ?? ''),
                'manager_name' => (string) ($contact['manager_name'] ?? ''),
                'manager_email' => (string) ($contact['manager_email'] ?? ''),
                'department' => (string) ($contact['client_name'] ?? ''),
                'template_name' => $templateName,
                'run_id' => (string) $runId,
                'task_title' => $taskTitle,
            ],
        ];
    }

    /** @return array<string,mixed>|null */
    public static function loadContact(\mysqli $mysqli, int $contactId): ?array
    {
        $stmt = mysqli_prepare($mysqli, 'SELECT c.contact_id, c.contact_name, c.contact_email, c.contact_title, c.contact_start_date, c.contact_expected_end_date,
                c.contact_client_id, c.contact_user_id, c.contact_manager_id, cl.client_name, m.contact_name AS manager_name, m.contact_email AS manager_email, m.contact_user_id AS manager_user_id
            FROM contacts c
            LEFT JOIN clients cl ON cl.client_id = c.contact_client_id
            LEFT JOIN contacts m ON m.contact_id = c.contact_manager_id
            WHERE c.contact_id = ?');
        mysqli_stmt_bind_param($stmt, 'i', $contactId);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        return $row ?: null;
    }

    /**
     * Runs one action task now. Only a task that is waiting (pending, or action_failed for a retry) is claimed, so two requests
     * can never run the same action twice. @return bool true when the task completed
     */
    public function run(int $runTaskId, ?int $actorUserId = null): bool
    {
        // Claim atomically: whoever flips the status owns the execution.
        mysqli_query($this->mysqli, "UPDATE workflow_run_tasks SET status = 'running', running_since = NOW()
            WHERE run_task_id = $runTaskId AND task_type = 'action' AND status IN ('pending','action_failed')");
        if (mysqli_affected_rows($this->mysqli) !== 1) {
            return false;
        }

        $task = mysqli_fetch_assoc(mysqli_query($this->mysqli, "SELECT t.*, r.contact_id, wt.name AS template_name
            FROM workflow_run_tasks t
            JOIN workflow_runs r ON r.run_id = t.run_id
            LEFT JOIN workflow_templates wt ON wt.workflow_template_id = r.workflow_template_id
            WHERE t.run_task_id = $runTaskId"));
        $runId = (int) $task['run_id'];
        $contact = self::loadContact($this->mysqli, (int) $task['contact_id']) ?? [];
        $ctx = self::contextFor($contact + ['contact_id' => (int) $task['contact_id']], (string) $task['template_name'], (string) $task['title'], $runId, $runTaskId);
        $type = (string) $task['action_type'];
        $config = json_decode((string) $task['action_config'], true);
        $config = is_array($config) ? $config : [];
        $action = $this->actions[$type] ?? null;

        $lastError = '';
        for ($i = 0; $i < self::MAX_ATTEMPTS; $i++) {
            mysqli_query($this->mysqli, "UPDATE workflow_run_tasks SET attempts = attempts + 1 WHERE run_task_id = $runTaskId");
            $attempt = (int) mysqli_fetch_row(mysqli_query($this->mysqli, "SELECT attempts FROM workflow_run_tasks WHERE run_task_id = $runTaskId"))[0];
            $result = null;
            try {
                if ($action === null) {
                    throw new \RuntimeException("unknown action \"$type\"");
                }
                $result = $action->execute($config, $ctx, $this->gateway);
            } catch (\Throwable $e) {
                $lastError = mb_substr($e->getMessage(), 0, 450);
                $this->log($runId, $runTaskId, 'action_error', $type, false, $attempt, $lastError, $actorUserId);
                if ($action === null) {
                    break; // retrying an action that does not exist cannot help
                }
                continue;
            }
            // Bookkeeping happens outside the try above: once the action has really happened it must never be retried
            // (and sent twice) because writing its record failed.
            $this->setDone($runTaskId);
            $this->log($runId, $runTaskId, 'action_ok', $type, true, $attempt, $result, $actorUserId);
            $this->gateway->emitEvent('workflow.task_completed', ['run_id' => $runId, 'run_task_id' => $runTaskId, 'task_title' => (string) $task['title'], 'contact_id' => (int) $task['contact_id'], 'template_name' => (string) $task['template_name'], 'completed_by' => 'automation', 'action_type' => $type]);
            $this->gateway->audit('workflow.action_executed', $actorUserId, 'contact', (int) $task['contact_id'], 'executed', 'Workflow action "' . $task['title'] . '" (' . $type . '): ' . $result, ['run_id' => $runId, 'run_task_id' => $runTaskId, 'action_type' => $type, 'attempt' => $attempt]);

            return true;
        }

        $stmt = mysqli_prepare($this->mysqli, "UPDATE workflow_run_tasks SET status = 'action_failed', last_error = ?, running_since = NULL WHERE run_task_id = ?");
        mysqli_stmt_bind_param($stmt, 'si', $lastError, $runTaskId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $this->gateway->audit('workflow.action_failed', $actorUserId, 'contact', (int) $task['contact_id'], 'failed', 'Workflow action "' . $task['title'] . '" (' . $type . ') failed and needs a person: ' . $lastError, ['run_id' => $runId, 'run_task_id' => $runTaskId, 'action_type' => $type]);
        // Tell the run's owner; a failed automatic step must never sit unnoticed.
        $starter = mysqli_fetch_row(mysqli_query($this->mysqli, "SELECT COALESCE(started_by, 0) FROM workflow_runs WHERE run_id = $runId"));
        $this->gateway->notifyUser((int) ($starter[0] ?? 0), 'Workflow', 'Automated task "' . $task['title'] . '" failed for ' . ($contact['contact_name'] ?? 'an employee') . ': ' . $lastError, 'workflow_run.php?run_id=' . $runId, (int) ($contact['contact_client_id'] ?? 0), (int) $task['contact_id']);

        return false;
    }

    private function setDone(int $runTaskId): void
    {
        mysqli_query($this->mysqli, "UPDATE workflow_run_tasks SET status = 'completed', completed_by = NULL, completed_at = NOW(), skip_reason = NULL, last_error = NULL, running_since = NULL WHERE run_task_id = $runTaskId");
    }

    public function log(int $runId, int $runTaskId, string $event, ?string $actionType, bool $ok, int $attempt, string $detail, ?int $actor): void
    {
        $detail = mb_substr($detail, 0, 990);
        $ok = $ok ? 1 : 0;
        $stmt = mysqli_prepare($this->mysqli, 'INSERT INTO workflow_task_log (run_id, run_task_id, event, action_type, ok, attempt, detail, actor_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        mysqli_stmt_bind_param($stmt, 'iissiisi', $runId, $runTaskId, $event, $actionType, $ok, $attempt, $detail, $actor);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}
