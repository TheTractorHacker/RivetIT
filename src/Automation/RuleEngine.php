<?php

namespace ITFlow\Automation;

use ITFlow\Automation\Actions\ActionRegistry;
use ITFlow\Workflow\ActionGateway;

/**
 * The event-rule engine: which rules match an event, running one rule safely, saving and testing rules.
 *
 *   matching()  enabled rules for the event, in priority order (lower number first), conditions evaluated by ConditionEvaluator;
 *               a matching rule with "stop on first match" ends the list.
 *   run()       loop guard (the same rule never runs twice in one causal chain, and a chain is at most MAX_DEPTH rules long),
 *               per-rule rate limit (state "throttled"), the action, and a row in automation_rule_runs.
 *   test()      the same decisions with nothing written and nothing sent (actions run in dry-run mode).
 *
 * The causal chain travels in the event context as _chain.id / _chain.depth / _chain.rules: while a rule runs, every event its action
 * emits carries the chain (see chainForEmit()), so a rule that triggers itself, directly or through other rules, is cut off.
 */
final class RuleEngine
{
    public const MAX_DEPTH = 3;
    public const DEFAULT_RATE = 30;
    public const STATUSES = ['ok', 'failed', 'throttled', 'loop_blocked', 'skipped'];

    /** @var array{id:string,depth:int,rules:list<int>}|null the chain of the rule currently executing */
    private static ?array $active = null;
    private \mysqli $mysqli;
    private ActionGateway $gateway;

    public function __construct(\mysqli $mysqli, ActionGateway $gateway)
    {
        $this->mysqli = $mysqli;
        $this->gateway = $gateway;
    }

    // ---------------------------------------------------------------- the on/off switch

    /** Whether event rules run. RivetMSP keeps its own Core flag; RivetIT stores the switch in settings (default on, as before the switch existed). */
    public static function switchOn(?\mysqli $mysqli): bool
    {
        if (!($mysqli instanceof \mysqli)) {
            return true;
        }
        $res = @mysqli_query($mysqli, 'SELECT config_automation_enabled FROM settings WHERE company_id = 1');
        $row = $res ? mysqli_fetch_row($res) : null;

        return $row === null || (int) $row[0] !== 0;
    }

    public static function setSwitch(\mysqli $mysqli, bool $on): void
    {
        mysqli_query($mysqli, 'UPDATE settings SET config_automation_enabled = ' . ($on ? 1 : 0) . ' WHERE company_id = 1');
    }

    // ---------------------------------------------------------------- causal chain

    /** The chain to attach to an event emitted right now, or null when no rule is executing. @return array{id:string,depth:int,rules:string}|null */
    public static function chainForEmit(): ?array
    {
        return self::$active === null ? null : ['id' => self::$active['id'], 'depth' => self::$active['depth'], 'rules' => implode(',', self::$active['rules'])];
    }

    /** @return array{id:string,depth:int,rules:list<int>} */
    private static function chainOf(array $context): array
    {
        $rules = array_values(array_filter(array_map('intval', explode(',', (string) ($context['_chain.rules'] ?? ''))), static fn ($i) => $i > 0));

        return ['id' => preg_match('/^[a-f0-9]{8,32}$/', (string) ($context['_chain.id'] ?? '')) ? (string) $context['_chain.id'] : bin2hex(random_bytes(6)),
            'depth' => max(0, (int) ($context['_chain.depth'] ?? 0)), 'rules' => $rules];
    }

    // ---------------------------------------------------------------- matching

    /** @return list<array<string,mixed>> */
    public function matching(string $event, array $context): array
    {
        $stmt = mysqli_prepare($this->mysqli, 'SELECT * FROM automation_rules WHERE trigger_event = ? AND is_enabled = 1');
        mysqli_stmt_bind_param($stmt, 's', $event);
        mysqli_stmt_execute($stmt);
        $rules = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
        mysqli_stmt_close($stmt);
        usort($rules, static fn ($a, $b) => [(int) ($a['priority'] ?? 100), (int) $a['rule_id']] <=> [(int) ($b['priority'] ?? 100), (int) $b['rule_id']]);
        $out = [];
        foreach ($rules as $r) {
            if (!ConditionEvaluator::matches($r['condition_json'], $context)) {
                continue;
            }
            $out[] = $r;
            if (!empty($r['stop_on_match'])) {
                break;
            }
        }

        return $out;
    }

    // ---------------------------------------------------------------- running

    /** @return array{rule_id:int, ok:bool, status:string, message:string} */
    public function run(int $ruleId, string $event, array $context): array
    {
        $rule = $this->find($ruleId);
        if ($rule === null || (int) $rule['is_enabled'] !== 1) {
            return ['rule_id' => $ruleId, 'ok' => true, 'status' => 'skipped', 'message' => 'rule is gone or disabled; skipped'];
        }
        $chain = self::chainOf($context);
        if (in_array($ruleId, $chain['rules'], true)) {
            return $this->finish($rule, $event, $chain, 'loop_blocked', 'blocked: this rule already ran earlier in the same chain of events (it would trigger itself)', 0);
        }
        if ($chain['depth'] >= self::MAX_DEPTH) {
            return $this->finish($rule, $event, $chain, 'loop_blocked', 'blocked: ' . self::MAX_DEPTH . ' rules already ran in this chain of events', 0);
        }
        $limit = max(1, (int) ($rule['rate_limit_per_min'] ?? self::DEFAULT_RATE));
        if ($this->recentRuns($ruleId) >= $limit) {
            return $this->finish($rule, $event, $chain, 'throttled', "throttled: more than $limit runs in the last minute", 0);
        }
        $action = ActionRegistry::get((string) $rule['action_type']);
        if ($action === null) {
            return $this->finish($rule, $event, $chain, 'failed', "no handler for action '" . $rule['action_type'] . "'", 0);
        }
        $config = json_decode((string) ($rule['action_config_json'] ?? ''), true);
        $config = is_array($config) ? $config : [];

        $next = ['id' => $chain['id'], 'depth' => $chain['depth'] + 1, 'rules' => array_merge($chain['rules'], [$ruleId])];
        $previous = self::$active;
        self::$active = $next;
        $t0 = microtime(true);
        try {
            $message = $action->execute($this->mysqli, $this->gateway, $config, $context, $rule, false);
            $status = 'ok';
        } catch (\Throwable $e) {
            $message = mb_substr($e->getMessage(), 0, 500);
            $status = 'failed';
        } finally {
            self::$active = $previous;
        }

        return $this->finish($rule, $event, $chain, $status, $message, (int) round((microtime(true) - $t0) * 1000));
    }

    /** Writes the run log, and the audit trail row the older "Recent activity" list reads. @return array{rule_id:int, ok:bool, status:string, message:string} */
    private function finish(array $rule, string $event, array $chain, string $status, string $message, int $ms): array
    {
        $ruleId = (int) $rule['rule_id'];
        $message = mb_substr($message, 0, 500);
        if ($status === 'throttled' && $this->recentThrottleLogged($ruleId)) {
            // one "throttled" row per minute is enough; a flood must not fill the log
            return ['rule_id' => $ruleId, 'ok' => true, 'status' => $status, 'message' => $message];
        }
        try {
            if (rivetTableExistsSafe($this->mysqli, 'automation_rule_runs')) {
                $actions = json_encode([['action' => $rule['action_type'], 'ok' => $status === 'ok', 'message' => $message]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $matched = 1;
                $depth = $chain['depth'];
                $cid = $chain['id'];
                $stmt = mysqli_prepare($this->mysqli, 'INSERT INTO automation_rule_runs (rule_id, event_type, matched, status, actions_json, message, duration_ms, chain_id, chain_depth) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                mysqli_stmt_bind_param($stmt, 'isisssisi', $ruleId, $event, $matched, $status, $actions, $message, $ms, $cid, $depth);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }
            // Written straight to the audit table (not through the after-log hook) so a rule's own record can never trigger rules again.
            $stmt = mysqli_prepare($this->mysqli, "INSERT INTO audit_events (event_type, entity_type, entity_id, action, summary, metadata_json) VALUES ('automation.rule_fired', 'automation_rule', ?, ?, ?, ?)");
            $id = (string) $ruleId;
            $action = $status === 'ok' ? 'ok' : ($status === 'failed' ? 'failed' : $status);
            $summary = mb_substr("Rule '" . $rule['name'] . "' on $event: $message", 0, 500);
            $meta = json_encode(['event' => $event, 'rule_id' => $ruleId, 'status' => $status], JSON_UNESCAPED_SLASHES);
            mysqli_stmt_bind_param($stmt, 'ssss', $id, $action, $summary, $meta);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        } catch (\Throwable $e) {
            // the record is best effort
        }

        // throttled / loop_blocked are decisions, not failures: they must not be retried by the job queue
        return ['rule_id' => $ruleId, 'ok' => $status !== 'failed', 'status' => $status, 'message' => $message];
    }

    private function recentRuns(int $ruleId): int
    {
        if (!rivetTableExistsSafe($this->mysqli, 'automation_rule_runs')) {
            return 0;
        }
        $row = mysqli_fetch_row(mysqli_query($this->mysqli, "SELECT COUNT(*) FROM automation_rule_runs WHERE rule_id = $ruleId AND status IN ('ok','failed') AND created_at >= NOW() - INTERVAL 60 SECOND"));

        return (int) ($row[0] ?? 0);
    }

    private function recentThrottleLogged(int $ruleId): bool
    {
        if (!rivetTableExistsSafe($this->mysqli, 'automation_rule_runs')) {
            return false;
        }

        return (bool) mysqli_fetch_row(mysqli_query($this->mysqli, "SELECT 1 FROM automation_rule_runs WHERE rule_id = $ruleId AND status = 'throttled' AND created_at >= NOW() - INTERVAL 60 SECOND LIMIT 1"));
    }

    // ---------------------------------------------------------------- test (dry run)

    /**
     * What the rule would do for this event, changing nothing. Works on a saved rule (by id) or an unsaved form (a rule-shaped array).
     * @param array<string,mixed> $rule rule row (rule_id may be 0)
     * @return array{matched:bool, valid:bool, conditions:list<array{text:string,ok:bool}>, guard:string|null, action:array{ok:bool, message:string}|null}
     */
    public function test(array $rule, string $event, array $context): array
    {
        $explain = ConditionEvaluator::explain($rule['condition_json'] ?? null, $context);
        $out = ['matched' => $explain['matched'], 'valid' => $explain['valid'], 'conditions' => $explain['details'], 'guard' => null, 'action' => null];
        if (($rule['trigger_event'] ?? $event) !== $event) {
            $out['guard'] = 'This rule listens for ' . $rule['trigger_event'] . ', not ' . $event . ', so it would not run.';
            $out['matched'] = false;
        }
        if (!$out['matched']) {
            return $out;
        }
        $chain = self::chainOf($context);
        $ruleId = (int) ($rule['rule_id'] ?? 0);
        if ($ruleId > 0 && in_array($ruleId, $chain['rules'], true)) {
            $out['guard'] = 'Loop guard: this rule already ran earlier in the same chain of events.';

            return $out;
        }
        if ($ruleId > 0 && $this->recentRuns($ruleId) >= max(1, (int) ($rule['rate_limit_per_min'] ?? self::DEFAULT_RATE))) {
            $out['guard'] = 'Rate limit: the rule is throttled right now (too many runs in the last minute).';

            return $out;
        }
        $action = ActionRegistry::get((string) ($rule['action_type'] ?? ''));
        if ($action === null) {
            $out['action'] = ['ok' => false, 'message' => 'Unknown action.'];

            return $out;
        }
        $config = $rule['action_config'] ?? json_decode((string) ($rule['action_config_json'] ?? ''), true);
        try {
            $out['action'] = ['ok' => true, 'message' => $action->execute($this->mysqli, $this->gateway, is_array($config) ? $config : [], $context, $rule, true)];
        } catch (\Throwable $e) {
            $out['action'] = ['ok' => false, 'message' => 'Would fail: ' . mb_substr($e->getMessage(), 0, 300)];
        }

        return $out;
    }

    // ---------------------------------------------------------------- save

    /**
     * Creates or updates a rule from validated input. @param array<string,mixed> $input the action's raw form values
     * @return int rule id @throws \InvalidArgumentException with a message safe to show an administrator
     */
    public function save(?int $id, string $name, string $trigger, array $conditionModel, string $actionKey, array $input, bool $enabled, int $priority = 100, bool $stopOnMatch = false, int $rate = self::DEFAULT_RATE): int
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 200) {
            throw new \InvalidArgumentException('Give the rule a name (up to 200 characters).');
        }
        $trigger = trim($trigger);
        if (!preg_match('/^[a-z0-9_.]{1,150}$/', $trigger)) {
            throw new \InvalidArgumentException('Choose the event that triggers the rule.');
        }
        $action = ActionRegistry::get($actionKey);
        if ($action === null) {
            throw new \InvalidArgumentException('Choose what the rule does.');
        }
        if (in_array($actionKey, ActionRegistry::TICKET_ACTIONS, true) && !self::eventHasTicket($trigger)) {
            throw new \InvalidArgumentException('"' . $action->label() . '" needs an event that is about a ticket (for example ticket.created); ' . $trigger . ' is not.');
        }
        if ($priority < 1 || $priority > 9999 || $rate < 1 || $rate > 1000) {
            throw new \InvalidArgumentException('Priority must be 1-9999 and the rate limit 1-1000 runs per minute.');
        }
        $cfg = json_encode($action->validate($this->mysqli, $input, $trigger), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $cond = ConditionEvaluator::serialize($conditionModel);
        $on = $enabled ? 1 : 0;
        $stop = $stopOnMatch ? 1 : 0;
        if ($id === null) {
            $stmt = mysqli_prepare($this->mysqli, 'INSERT INTO automation_rules (name, trigger_event, condition_json, action_type, action_config_json, is_enabled, priority, stop_on_match, rate_limit_per_min) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            mysqli_stmt_bind_param($stmt, 'sssssiiii', $name, $trigger, $cond, $actionKey, $cfg, $on, $priority, $stop, $rate);
            mysqli_stmt_execute($stmt);
            $id = (int) mysqli_insert_id($this->mysqli);
            mysqli_stmt_close($stmt);

            return $id;
        }
        if ($this->find($id) === null) {
            throw new \InvalidArgumentException('That rule no longer exists.');
        }
        $stmt = mysqli_prepare($this->mysqli, 'UPDATE automation_rules SET name = ?, trigger_event = ?, condition_json = ?, action_type = ?, action_config_json = ?, is_enabled = ?, priority = ?, stop_on_match = ?, rate_limit_per_min = ? WHERE rule_id = ?');
        mysqli_stmt_bind_param($stmt, 'sssssiiiii', $name, $trigger, $cond, $actionKey, $cfg, $on, $priority, $stop, $rate, $id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        return $id;
    }

    /** Whether events of this type carry a ticket (ticket.* and catalog.request_*). */
    public static function eventHasTicket(string $event): bool
    {
        return str_starts_with($event, 'ticket.') || str_starts_with($event, 'catalog.request_');
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = mysqli_prepare($this->mysqli, 'SELECT * FROM automation_rules WHERE rule_id = ?');
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        return $row ?: null;
    }
}

/** Table check without the event bus helper (this class must work in tests that do not load includes/event_bus.php). */
function rivetTableExistsSafe(\mysqli $mysqli, string $table): bool
{
    static $cache = [];
    $key = spl_object_id($mysqli) . ':' . $table;
    if (!array_key_exists($key, $cache)) {
        $res = @mysqli_query($mysqli, "SHOW TABLES LIKE '" . mysqli_real_escape_string($mysqli, $table) . "'");
        $cache[$key] = (bool) ($res && mysqli_num_rows($res) > 0);
    }

    return $cache[$key];
}
