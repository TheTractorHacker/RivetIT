<?php

namespace ITFlow\Automation;

/**
 * Minimal automation rules engine (master plan Section 34) for NON-ticket
 * events - the existing ticket_automation_rules engine (admin/ticket_automation.php)
 * already covers ticket triggers and is untouched by this. This matches
 * automation_rules against AuditService event_type strings (person/asset/
 * workflow events - see src/Audit/AuditService.php callers for the real set).
 *
 * Deliberately evaluation-only: findMatchingRules() tells a caller which
 * enabled rules fire for a given event: it does not execute create_ticket/
 * send_webhook/notify_user. No real trigger point calls this yet - wiring
 * execution in is future work, once there's an agreed, safe place to call it
 * from without risking double-firing or blind ticket/webhook creation.
 */
class AutomationRuleEvaluator
{
    private \mysqli $mysqli;

    public function __construct(\mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    /**
     * @param array<string,mixed> $eventData flat key/value context for this event,
     *   e.g. ['entity_type' => 'contact', 'action' => 'started', 'client_id' => 5]
     * @return array<int,array<string,mixed>> enabled automation_rules rows whose
     *   trigger_event matches $eventType and whose condition_json (if any) is
     *   fully satisfied by $eventData
     */
    public function findMatchingRules(string $eventType, array $eventData): array
    {
        $stmt = $this->mysqli->prepare(
            "SELECT * FROM automation_rules WHERE trigger_event = ? AND is_enabled = 1"
        );
        $stmt->bind_param('s', $eventType);
        $stmt->execute();
        $result = $stmt->get_result();
        $rules = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        return array_values(array_filter(
            $rules,
            fn($rule) => $this->conditionsMatch($rule['condition_json'], $eventData)
        ));
    }

    /**
     * All conditions AND together. No conditions (null/empty/'{}') always
     * matches. A key missing from $eventData never matches - missing is not
     * treated as equal to any expected value, including null.
     */
    public function conditionsMatch(?string $conditionJson, array $eventData): bool
    {
        if ($conditionJson === null || trim($conditionJson) === '' || trim($conditionJson) === '{}') {
            return true;
        }

        $conditions = json_decode($conditionJson, true);
        if (!is_array($conditions)) {
            return false; // malformed condition_json - fail closed, never fire
        }

        foreach ($conditions as $field => $expected) {
            if (!array_key_exists($field, $eventData)) {
                return false;
            }
            if ((string) $eventData[$field] !== (string) $expected) {
                return false;
            }
        }

        return true;
    }
}
