<?php

namespace ITFlow\Automation\Actions;

use ITFlow\Workflow\ActionGateway;
use ITFlow\Workflow\Actions\ActionInterface as WorkflowAction;
use ITFlow\Workflow\Actions\EntraAddToGroupsAction;
use ITFlow\Workflow\Actions\EntraDisableAccountAction;
use ITFlow\Workflow\DirectoryActionGateway;

/**
 * The Microsoft Entra actions of lifecycle workflows (src/Workflow/Actions/Entra*), usable from an event rule on events about a person
 * (employee.terminated, employee.offboarded, employee.hired ...). It adds nothing of its own: validation, the Graph calls, the department
 * check (IT-9), the "Allow RivetIT to change Entra accounts" switch (OFF by default) and the never-delete rule are the workflow action's.
 *
 * Which account: the person's email from THEIR RECORD (contact_id of the event), never an email taken from event data.
 * Creating an account is not offered here: it hands a one-time password to a technician through a workflow task, which a rule has no equivalent of.
 * UNVERIFIED LIVE: needs a Microsoft tenant; the workflow tests prove the Graph side against a local mock.
 */
abstract class EntraRuleAction extends AbstractAction
{
    abstract protected function inner(): WorkflowAction;

    /** @param array<string,mixed> $input @return array<string,mixed> the workflow action's own cleaned config */
    abstract protected function innerInput(array $input): array;

    public function validate(\mysqli $mysqli, array $input, string $triggerEvent): array
    {
        if (!$this->aboutAPerson($triggerEvent)) {
            throw new \InvalidArgumentException('"' . $this->label() . '" needs an event about a person (for example employee.terminated); ' . $triggerEvent . ' is not.');
        }

        return $this->inner()->validate($this->innerInput($input));
    }

    /** Events that carry a contact_id: employee.*, contact.*, workflow.*, asset.assigned and anything else a person is named in. Weak by design: execute() checks the real context. */
    private function aboutAPerson(string $event): bool
    {
        return str_starts_with($event, 'employee.') || str_starts_with($event, 'contact.') || str_starts_with($event, 'workflow.') || $event === 'asset.assigned' || str_starts_with($event, 'catalog.request_');
    }

    public function execute(\mysqli $mysqli, ActionGateway $gateway, array $config, array $context, array $rule, bool $dry): string
    {
        $cid = \ITFlow\Automation\PayloadEnricher::subjectId($context, 'contact');
        if ($cid <= 0) {
            throw new \RuntimeException('the event is not about a person (it has no contact_id)');
        }
        $stmt = mysqli_prepare($mysqli, 'SELECT contact_name, contact_email, contact_department FROM contacts WHERE contact_id = ?');
        mysqli_stmt_bind_param($stmt, 'i', $cid);
        mysqli_stmt_execute($stmt);
        $c = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$c) {
            throw new \RuntimeException("person #$cid no longer exists");
        }
        $ctx = ['run_task_id' => 0, 'vars' => ['employee_email' => (string) ($c['contact_email'] ?? ''), 'employee_name' => (string) $c['contact_name'], 'department' => (string) ($c['contact_department'] ?? '')]];
        if (!$gateway instanceof DirectoryActionGateway) {
            throw new \RuntimeException('Entra account actions are not available here');
        }
        if ($dry) {
            if (!$gateway->entraWritesAllowed()) {
                throw new \RuntimeException("the Entra write switch is off (Administration > Integrations > Directory Sync); the real run would send nothing");
            }

            return $this->inner()->describe($config, $ctx);
        }
        $result = $this->inner()->execute($config, $ctx, $gateway);
        $gateway->audit('automation.' . $this->key(), null, 'contact', $cid, 'update', mb_substr("Rule '" . $rule['name'] . "': " . $this->label() . ' for ' . (string) $c['contact_email'], 0, 480), ['rule_id' => (int) ($rule['rule_id'] ?? 0)]);

        return $result;
    }
}
