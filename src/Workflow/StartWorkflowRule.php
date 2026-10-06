<?php

namespace ITFlow\Workflow;

/**
 * The start_workflow event-rule action: "when this event happens, start an onboarding/offboarding template for the person".
 *
 * rivet-core's AutomationRuleStore only accepts the three actions it ships with and vendor/ is read-only, so this class owns
 * saving and running this one action locally (Administration > Event rules posts here for it; every other action still goes
 * through the Core store, and toggling/deleting any rule uses the Core store unchanged). The automation_rules.action_type
 * column is widened to include it by database migration 2.6.134.
 */
class StartWorkflowRule
{
    public const ACTION = 'start_workflow';
    public const LABEL = 'Start an employee workflow';

    /** The event that fits each template type; a rule on one of these events must pick the matching template type. */
    private const EVENT_TYPE = ['employee.hired' => 'onboarding', 'employee.terminated' => 'offboarding'];

    /** @return array{template_id:int} @throws \InvalidArgumentException with a message safe to show an administrator */
    public static function validateConfig(\mysqli $mysqli, array $config, string $triggerEvent): array
    {
        $templateId = (int) ($config['template_id'] ?? 0);
        $t = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT type FROM workflow_templates WHERE workflow_template_id = $templateId AND archived_at IS NULL AND is_active = 1"));
        if (!$t) {
            throw new \InvalidArgumentException('Choose an active workflow template to start.');
        }
        if (isset(self::EVENT_TYPE[$triggerEvent]) && self::EVENT_TYPE[$triggerEvent] !== $t['type']) {
            throw new \InvalidArgumentException('The event ' . $triggerEvent . ' starts ' . self::EVENT_TYPE[$triggerEvent] . ' templates, but the chosen template is ' . $t['type'] . '.');
        }

        return ['template_id' => $templateId];
    }

    /**
     * Creates or updates a start_workflow rule. Same limits the Core store applies to name, event and conditions.
     * @param array<string,string> $conditions field => expected value
     * @throws \InvalidArgumentException
     */
    public static function save(\mysqli $mysqli, ?int $id, string $name, string $triggerEvent, array $conditions, array $config, bool $enabled): int
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 200) {
            throw new \InvalidArgumentException('Give the rule a name (up to 200 characters).');
        }
        $triggerEvent = trim($triggerEvent);
        if (!preg_match('/^[a-z0-9_.]{1,150}$/', $triggerEvent)) {
            throw new \InvalidArgumentException('Choose the event that triggers the rule.');
        }
        $clean = [];
        foreach ($conditions as $field => $expected) {
            $field = trim((string) $field);
            if ($field === '') {
                continue;
            }
            if (!preg_match('/^[A-Za-z0-9_.]{1,100}$/', $field) || !is_scalar($expected) || mb_strlen((string) $expected) > 200) {
                throw new \InvalidArgumentException('A condition has an invalid field or value.');
            }
            $clean[$field] = (string) $expected;
        }
        $cfg = json_encode(self::validateConfig($mysqli, $config, $triggerEvent), JSON_UNESCAPED_SLASHES);
        $cond = $clean === [] ? null : json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $action = self::ACTION;
        $on = $enabled ? 1 : 0;

        if ($id === null) {
            $stmt = mysqli_prepare($mysqli, 'INSERT INTO automation_rules (name, trigger_event, condition_json, action_type, action_config_json, is_enabled) VALUES (?, ?, ?, ?, ?, ?)');
            mysqli_stmt_bind_param($stmt, 'sssssi', $name, $triggerEvent, $cond, $action, $cfg, $on);
            mysqli_stmt_execute($stmt);
            $id = (int) mysqli_insert_id($mysqli);
            mysqli_stmt_close($stmt);

            return $id;
        }
        if (!mysqli_fetch_row(mysqli_query($mysqli, 'SELECT 1 FROM automation_rules WHERE rule_id = ' . (int) $id))) {
            throw new \InvalidArgumentException('That rule no longer exists.');
        }
        $stmt = mysqli_prepare($mysqli, 'UPDATE automation_rules SET name = ?, trigger_event = ?, condition_json = ?, action_type = ?, action_config_json = ?, is_enabled = ? WHERE rule_id = ?');
        mysqli_stmt_bind_param($stmt, 'sssssii', $name, $triggerEvent, $cond, $action, $cfg, $on, $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        return $id;
    }

    /**
     * Runs the action for one event. Idempotent: a person with an open run of the template (in progress or paused) gets no second one.
     * @param array<string,string> $context flat event context (EventContext::flatten)
     * @return string result message for the rule's record
     * @throws \RuntimeException when the rule cannot run (reported as a failed rule run, retried by the job queue)
     */
    public static function run(\mysqli $mysqli, array $config, array $context, ?ActionGateway $gateway = null): string
    {
        if (!LifecycleEvents::enabled($mysqli)) {
            return 'skipped: lifecycle auto-start is turned off';
        }
        // The subject is always a person: either the event's own contact_id, or an event about a contact entity.
        $contactId = (int) ($context['contact_id'] ?? 0);
        if ($contactId <= 0 && ($context['entity_type'] ?? '') === 'contact') {
            $contactId = (int) ($context['entity_id'] ?? 0);
        }
        if ($contactId <= 0 || !mysqli_fetch_row(mysqli_query($mysqli, "SELECT 1 FROM contacts WHERE contact_id = $contactId AND contact_archived_at IS NULL"))) {
            return 'skipped: the event is not about an active person';
        }
        $templateId = (int) ($config['template_id'] ?? 0);
        $template = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT name, type FROM workflow_templates WHERE workflow_template_id = $templateId AND archived_at IS NULL AND is_active = 1"));
        if (!$template) {
            throw new \RuntimeException('the workflow template is gone or inactive');
        }
        $event = (string) ($context['event'] ?? '');
        if (isset(self::EVENT_TYPE[$event]) && self::EVENT_TYPE[$event] !== $template['type']) {
            throw new \RuntimeException("the template type does not fit the event $event");
        }

        $gateway ??= new LiveActionGateway($mysqli);
        $service = new WorkflowService($mysqli, $gateway);
        $runId = $service->startRunIfNone($templateId, $contactId, null);
        if ($runId === null) {
            return 'skipped: ' . $template['name'] . ' is already open for this person';
        }
        $gateway->audit($template['type'] === 'onboarding' ? 'workflow.onboarding_started' : 'workflow.offboarding_started', null, 'contact', $contactId, 'started', 'Event rule started ' . $template['name'], ['run_id' => $runId, 'template_id' => $templateId, 'trigger' => $event]);

        return 'started ' . $template['name'] . " (run #$runId)";
    }
}
