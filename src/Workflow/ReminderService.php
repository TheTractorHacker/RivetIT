<?php

namespace ITFlow\Workflow;

/**
 * Due-date reminders for workflow tasks (cron/workflow_cron.php runs this). A task that is due within a day gets one
 * "due soon" notification and, if it is still open once overdue, one "overdue" notification: once per task per state, recorded
 * in reminder_state / reminded_at, so running the job every few minutes never repeats itself.
 *
 * Recipient: the task's assignee, otherwise whoever started the run, otherwise every agent. Only tasks an agent can act on are
 * reminded (pending or failed action): a blocked task cannot be worked yet, and a paused or cancelled run is not chased.
 */
class ReminderService
{
    /** An action that has been "running" this long was interrupted (a crashed request); it is handed to a person. */
    public const STALE_RUNNING_MINUTES = 15;

    private \mysqli $mysqli;
    private ActionGateway $gateway;

    public function __construct(\mysqli $mysqli, ActionGateway $gateway)
    {
        $this->mysqli = $mysqli;
        $this->gateway = $gateway;
    }

    /** @return array{due_soon:int, overdue:int, recovered:int} */
    public function run(): array
    {
        $out = ['due_soon' => 0, 'overdue' => 0, 'recovered' => 0];

        mysqli_query($this->mysqli, "UPDATE workflow_run_tasks SET status = 'action_failed', running_since = NULL, last_error = 'interrupted while running; check whether the action happened before retrying'
            WHERE status = 'running' AND running_since IS NOT NULL AND running_since < NOW() - INTERVAL " . self::STALE_RUNNING_MINUTES . ' MINUTE');
        $out['recovered'] = max(0, (int) mysqli_affected_rows($this->mysqli));

        // A temporary password nobody read within its 7 days is erased, not kept. (Column absent before migration 2.6.140: ignored.)
        try {
            mysqli_query($this->mysqli, 'UPDATE workflow_run_tasks SET secret_result_enc = NULL, secret_user_id = NULL, secret_expires_at = NULL WHERE secret_expires_at IS NOT NULL AND secret_expires_at < NOW()');
        } catch (\Throwable $e) {
        }

        $res = mysqli_query($this->mysqli, "SELECT t.run_task_id, t.run_id, t.title, t.due_at, t.assignee_user_id, t.reminder_state, r.started_by, r.contact_id, c.contact_name, c.contact_client_id,
                (t.due_at < NOW()) AS is_overdue
            FROM workflow_run_tasks t
            JOIN workflow_runs r ON r.run_id = t.run_id AND r.status = 'in_progress'
            LEFT JOIN contacts c ON c.contact_id = r.contact_id
            WHERE t.status IN ('pending','action_failed') AND t.due_at IS NOT NULL AND t.due_at <= NOW() + INTERVAL 1 DAY
            ORDER BY t.due_at ASC");
        $rows = $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [];
        foreach ($rows as $t) {
            $state = (int) $t['is_overdue'] === 1 ? 'overdue' : 'due_soon';
            if ($t['reminder_state'] === $state || ($t['reminder_state'] === 'overdue')) {
                continue;
            }
            // Claim the state first: a second cron run (or a second server) that loses this race sends nothing.
            $id = (int) $t['run_task_id'];
            $stmt = mysqli_prepare($this->mysqli, "UPDATE workflow_run_tasks SET reminder_state = ?, reminded_at = NOW() WHERE run_task_id = ? AND (reminder_state IS NULL OR reminder_state <> ?) AND (reminder_state IS NULL OR reminder_state <> 'overdue')");
            mysqli_stmt_bind_param($stmt, 'sis', $state, $id, $state);
            mysqli_stmt_execute($stmt);
            $claimed = mysqli_stmt_affected_rows($stmt) === 1;
            mysqli_stmt_close($stmt);
            if (!$claimed) {
                continue;
            }
            $who = (int) ($t['assignee_user_id'] ?? 0) ?: (int) ($t['started_by'] ?? 0);
            $text = ($state === 'overdue' ? 'Overdue' : 'Due within a day') . ': "' . $t['title'] . '" for ' . ($t['contact_name'] ?? 'an employee') . ' (due ' . $t['due_at'] . ')';
            try {
                $this->gateway->notifyUser($who, 'Workflow', $text, 'workflow_run.php?run_id=' . (int) $t['run_id'], (int) ($t['contact_client_id'] ?? 0), (int) $t['contact_id']);
            } catch (\Throwable $e) {
                error_log('workflow reminder not delivered: ' . $e->getMessage());
            }
            $this->log((int) $t['run_id'], $id, $state, $text);
            $out[$state]++;
        }

        return $out;
    }

    private function log(int $runId, int $runTaskId, string $state, string $text): void
    {
        $event = 'reminder_' . $state;
        $detail = mb_substr($text, 0, 990);
        $stmt = mysqli_prepare($this->mysqli, "INSERT INTO workflow_task_log (run_id, run_task_id, event, ok, attempt, detail) VALUES (?, ?, ?, 1, 0, ?)");
        mysqli_stmt_bind_param($stmt, 'iiss', $runId, $runTaskId, $event, $detail);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}
