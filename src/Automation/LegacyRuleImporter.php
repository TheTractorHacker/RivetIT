<?php

namespace ITFlow\Automation;

/**
 * The bridge from the older ticket automation rules (ticket_automation_rules, Administration > Ticket Automation, run by cron) to event rules.
 *
 * It NEVER changes or removes a legacy rule and never runs one: the old engine keeps running exactly as before. report() says, for every legacy rule,
 * whether an equivalent event rule exists and why not when it does not; import() creates the equivalent rules, always DISABLED, once each
 * (automation_rules.source_legacy_id is unique). An administrator reviews the copy, switches it on and then turns the legacy rule off, so the two
 * never both act.
 *
 * What can be carried over is deliberately narrow, because the two engines work differently:
 *   - trigger asset_offline / asset_online  -> event asset.offline / asset.online (cron raises them from the same RMM status changes)
 *   - action notify_assignee                -> "Notify a user"
 *   - conditions on asset_id, client_id, hostname, integration_id, rmm_status (all of them must match, as before)
 * Everything else is reported with a reason: ticket "schedule" rules scan tickets by age on every pass, which has no event; rmm_alert and vacation_return
 * read data the event bus does not carry; run_script points at a Tactical script id, not at a library script; ticket actions do nothing for asset
 * triggers in the old engine either. A legacy rule with no conditions never matched anything (the old engine requires at least one), so it is
 * not imported as a rule that would suddenly match everything.
 */
final class LegacyRuleImporter
{
    private const TRIGGERS = ['asset_offline' => 'asset.offline', 'asset_online' => 'asset.online'];
    private const FIELDS = ['asset_id', 'client_id', 'hostname', 'integration_id', 'rmm_status'];
    private const OPS = ['equals' => 'eq', 'not_equals' => 'ne', 'greater_than' => 'gt', 'less_than' => 'lt', 'contains' => 'contains'];

    public static function available(\mysqli $m): bool
    {
        foreach (['ticket_automation_rules', 'automation_rules'] as $t) {
            $res = @mysqli_query($m, "SHOW TABLES LIKE '$t'");
            if (!$res || mysqli_num_rows($res) === 0) {
                return false;
            }
        }
        $res = @mysqli_query($m, "SHOW COLUMNS FROM automation_rules LIKE 'source_legacy_id'");

        return (bool) ($res && mysqli_num_rows($res) > 0);
    }

    /**
     * @return list<array{legacy_id:int,name:string,trigger:string,enabled:bool,importable:bool,reason:string,notes:list<string>,event:string,imported_rule_id:int|null}>
     */
    public static function report(\mysqli $m): array
    {
        if (!self::available($m)) {
            return [];
        }
        $imported = [];
        $res = mysqli_query($m, 'SELECT rule_id, source_legacy_id FROM automation_rules WHERE source_legacy_id IS NOT NULL');
        while ($res && ($r = mysqli_fetch_row($res))) {
            $imported[(int) $r[1]] = (int) $r[0];
        }
        $out = [];
        $res = mysqli_query($m, 'SELECT * FROM ticket_automation_rules ORDER BY rule_order, rule_id');
        while ($res && ($rule = mysqli_fetch_assoc($res))) {
            $plan = self::plan($rule);
            $out[] = ['legacy_id' => (int) $rule['rule_id'], 'name' => (string) $rule['rule_name'], 'trigger' => (string) ($rule['rule_trigger'] ?: 'schedule'), 'enabled' => (bool) $rule['rule_enabled'],
                'importable' => $plan['ok'], 'reason' => $plan['reason'], 'notes' => $plan['notes'], 'event' => $plan['event'], 'imported_rule_id' => $imported[(int) $rule['rule_id']] ?? null];
        }

        return $out;
    }

    /**
     * Pure: what a legacy rule row would become. @param array<string,mixed> $rule a ticket_automation_rules row
     * @return array{ok:bool,reason:string,notes:list<string>,event:string,model:array<string,mixed>|null,action:string,input:array<string,mixed>}
     */
    public static function plan(array $rule): array
    {
        $no = static fn (string $why): array => ['ok' => false, 'reason' => $why, 'notes' => [], 'event' => '', 'model' => null, 'action' => '', 'input' => []];
        $trigger = (string) ($rule['rule_trigger'] ?: 'schedule');
        if (!isset(self::TRIGGERS[$trigger])) {
            return $no(match ($trigger) {
                'schedule' => 'A ticket schedule rule scans tickets by age and status on every cron pass; event rules react to single events, so it has no equivalent. It keeps running as it is.',
                'rmm_alert' => 'It reads the older RMM alert table, which the event bus does not carry. It keeps running as it is (the RMM module has its own alert rules).',
                'vacation_return' => 'It looks for tickets closed during a person\'s vacation; that is a scan over tickets and has no event. It keeps running as it is.',
                default => 'Unknown trigger "' . $trigger . '".',
            });
        }
        // conditions: the old engine needs at least one and ANDs them all
        $conds = self::conditionsOf($rule);
        if (!$conds) {
            return $no('It has no conditions, and the old engine never runs a rule without one, so it never did anything. Add a condition before moving it.');
        }
        $rows = [];
        $notes = [];
        foreach ($conds as $c) {
            $field = (string) ($c['field'] ?? '');
            $op = self::OPS[(string) ($c['op'] ?? 'equals')] ?? null;
            if (!in_array($field, self::FIELDS, true) || $op === null) {
                return $no('The condition "' . $field . ' ' . ($c['op'] ?? '') . '" has no equivalent on this event.');
            }
            $rows[] = ['field' => $field, 'op' => $op, 'value' => (string) ($c['value'] ?? '')];
            if (in_array($field, ['hostname', 'rmm_status'], true) && $op === 'eq') {
                $notes[] = 'The old engine compared "' . $field . '" ignoring upper/lower case; the event rule compares exactly.';
            }
        }
        $actions = self::actionsOf($rule);
        if (count($actions) !== 1) {
            return $no(count($actions) === 0 ? 'It has no action.' : 'It has ' . count($actions) . ' actions; an event rule does one thing. Create one event rule per action by hand.');
        }
        $act = (string) ($actions[0]['action'] ?? '');
        if ($act !== 'notify_assignee') {
            $why = match ($act) {
                'run_script' => 'run_script points at a Tactical RMM script id; the event rule action runs RMM library scripts (a different list), so it cannot be translated safely.',
                'set_priority', 'assign_to', 'escalate', 'set_status', 'add_note', 'ai_triage', 'close_ticket', 'reopen_ticket', 'add_worksheet' => 'Ticket actions do nothing on an asset trigger in the old engine either (there is no ticket).',
                'create_ticket_from_alert', 'acknowledge_alert' => 'These need an RMM alert, which an asset status change does not have.',
                default => 'The action "' . $act . '" has no equivalent.',
            };

            return $no($why);
        }
        $name = (string) $rule['rule_name'];
        $verb = $trigger === 'asset_offline' ? 'went offline' : 'came back online';

        return ['ok' => true, 'reason' => '', 'notes' => $notes, 'event' => self::TRIGGERS[$trigger], 'model' => ['mode' => 'all', 'conditions' => $rows], 'action' => 'notify_user',
            'input' => ['message' => "Rule '" . $name . "': asset {asset_name} ($verb, department {department}). Legacy rule #" . (int) $rule['rule_id'] . ' imported from Ticket Automation.']];
    }

    /** The old action list (rule_actions_json, else the single rule_action columns), as includes/automation_functions.php reads it. @return list<array<string,mixed>> */
    private static function actionsOf(array $rule): array
    {
        if (!empty($rule['rule_actions_json'])) {
            $d = json_decode((string) $rule['rule_actions_json'], true);
            if (is_array($d) && $d) {
                return array_values($d);
            }
        }

        return !empty($rule['rule_action']) ? [['action' => $rule['rule_action'], 'value' => $rule['rule_action_value'] ?? '']] : [];
    }

    /** The old condition list (rule_conditions_json, else the single rule_cond_* columns), as includes/automation_functions.php reads it. @return list<array<string,mixed>> */
    private static function conditionsOf(array $rule): array
    {
        if (!empty($rule['rule_conditions_json'])) {
            $d = json_decode((string) $rule['rule_conditions_json'], true);
            if (is_array($d) && $d) {
                return $d;
            }
        }

        return !empty($rule['rule_cond_field']) ? [['field' => $rule['rule_cond_field'], 'op' => $rule['rule_cond_op'], 'value' => $rule['rule_cond_value']]] : [];
    }

    /**
     * Creates the equivalent event rules (disabled) for the given legacy ids. A rule that is not importable or already imported is reported, not touched.
     * @param list<int> $ids @return list<array{legacy_id:int,ok:bool,rule_id:int|null,message:string}>
     */
    public static function import(\mysqli $m, RuleEngine $engine, array $ids): array
    {
        $out = [];
        if (!self::available($m)) {
            return $out;
        }
        foreach (array_values(array_unique(array_map('intval', $ids))) as $id) {
            $stmt = mysqli_prepare($m, 'SELECT * FROM ticket_automation_rules WHERE rule_id = ?');
            mysqli_stmt_bind_param($stmt, 'i', $id);
            mysqli_stmt_execute($stmt);
            $rule = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            if (!$rule) {
                $out[] = ['legacy_id' => $id, 'ok' => false, 'rule_id' => null, 'message' => 'That legacy rule no longer exists.'];
                continue;
            }
            $have = mysqli_fetch_row(mysqli_query($m, 'SELECT rule_id FROM automation_rules WHERE source_legacy_id = ' . $id));
            if ($have) {
                $out[] = ['legacy_id' => $id, 'ok' => false, 'rule_id' => (int) $have[0], 'message' => 'Already imported as event rule #' . (int) $have[0] . '.'];
                continue;
            }
            $plan = self::plan($rule);
            if (!$plan['ok']) {
                $out[] = ['legacy_id' => $id, 'ok' => false, 'rule_id' => null, 'message' => $plan['reason']];
                continue;
            }
            $newId = 0;
            try {
                $name = mb_substr('Imported: ' . $rule['rule_name'], 0, 200);
                $newId = $engine->save(null, $name, $plan['event'], $plan['model'], $plan['action'], $plan['input'], false);
                $stmt = mysqli_prepare($m, 'UPDATE automation_rules SET source_legacy_id = ? WHERE rule_id = ? AND source_legacy_id IS NULL');
                mysqli_stmt_bind_param($stmt, 'ii', $id, $newId);
                mysqli_stmt_execute($stmt);
                $linked = mysqli_stmt_affected_rows($stmt) === 1;
                mysqli_stmt_close($stmt);
                if (!$linked) {
                    mysqli_query($m, 'DELETE FROM automation_rules WHERE rule_id = ' . $newId);
                    $out[] = ['legacy_id' => $id, 'ok' => false, 'rule_id' => null, 'message' => 'Another import of this rule won the race; nothing was created.'];
                    continue;
                }
                $out[] = ['legacy_id' => $id, 'ok' => true, 'rule_id' => $newId, 'message' => 'Created event rule #' . $newId . ' (switched off). The legacy rule is unchanged and still running.'];
            } catch (\Throwable $e) {
                if ($newId > 0) {
                    mysqli_query($m, 'DELETE FROM automation_rules WHERE rule_id = ' . $newId . ' AND source_legacy_id IS NULL');
                }
                $out[] = ['legacy_id' => $id, 'ok' => false, 'rule_id' => null, 'message' => $e instanceof \InvalidArgumentException ? $e->getMessage() : 'Not imported: another import of this rule was running.'];
            }
        }

        return $out;
    }

    /**
     * The hand-over: switch the imported event rule ON and the legacy ticket rule OFF, so exactly one of the two acts from now on. Only for a rule that was
     * imported first (the copy is what is switched on, never anything built here), and only when the legacy rule is currently on; it does not delete anything and
     * the legacy rule can be switched back on in the ticket rule editor. @return array{ok:bool,message:string,rule_id:int|null}
     */
    public static function switchOver(\mysqli $m, int $legacyId): array
    {
        if (!self::available($m)) {
            return ['ok' => false, 'message' => 'Run the database update first.', 'rule_id' => null];
        }
        $have = mysqli_fetch_row(mysqli_query($m, 'SELECT rule_id FROM automation_rules WHERE source_legacy_id = ' . (int) $legacyId));
        if (!$have) {
            return ['ok' => false, 'message' => 'Convert the ticket rule to an event rule first.', 'rule_id' => null];
        }
        $legacy = mysqli_fetch_assoc(mysqli_query($m, 'SELECT rule_id, rule_enabled FROM ticket_automation_rules WHERE rule_id = ' . (int) $legacyId));
        if (!$legacy) {
            return ['ok' => false, 'message' => 'That ticket rule no longer exists.', 'rule_id' => (int) $have[0]];
        }
        $newId = (int) $have[0];
        mysqli_begin_transaction($m);
        mysqli_query($m, 'UPDATE automation_rules SET is_enabled = 1 WHERE rule_id = ' . $newId);
        mysqli_query($m, 'UPDATE ticket_automation_rules SET rule_enabled = 0 WHERE rule_id = ' . (int) $legacyId);
        mysqli_commit($m);

        return ['ok' => true, 'message' => 'Event rule #' . $newId . ' is on and the ticket rule is off.', 'rule_id' => $newId];
    }

    /**
     * One row per legacy rule for the unified rules list: the report() fields plus the current state of both sides, so the page can say "both on" etc.
     * @return list<array<string,mixed>>
     */
    public static function unifiedRows(\mysqli $m): array
    {
        $rows = self::report($m);
        foreach ($rows as &$r) {
            $r['new_enabled'] = null;
            if ($r['imported_rule_id'] !== null) {
                $x = mysqli_fetch_row(mysqli_query($m, 'SELECT is_enabled FROM automation_rules WHERE rule_id = ' . (int) $r['imported_rule_id']));
                $r['new_enabled'] = $x ? (bool) $x[0] : null;
            }
            $r['state'] = match (true) {
                $r['imported_rule_id'] === null => $r['importable'] ? 'convertible' : 'legacy_only',
                $r['enabled'] && $r['new_enabled'] => 'both_on',
                $r['enabled'] => 'converted_waiting',
                $r['new_enabled'] => 'switched',
                default => 'both_off',
            };
        }
        unset($r);

        return $rows;
    }
}
