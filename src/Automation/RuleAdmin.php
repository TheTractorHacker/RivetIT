<?php

namespace ITFlow\Automation;

/**
 * Database side of the Event rules admin page and its JSON endpoint (admin/modals/event_rules_api.php): the rule list with run statistics,
 * name lookups for summaries, duplicate / toggle / reorder, and the per-rule run history. Engine behaviour (matching, running, testing,
 * saving) stays in RuleEngine; nothing here changes how a rule runs.
 */
final class RuleAdmin
{
    public const COPY_SUFFIX = ' (copy)';

    /** @return array{agents:array<int,string>,clients:array<int,string>,categories:array<int,string>,templates:array<int,string>,statuses:list<string>} */
    public static function lookups(\mysqli $mysqli): array
    {
        $out = ['agents' => [], 'clients' => [], 'categories' => [], 'templates' => [], 'statuses' => []];
        $q = static function (string $sql) use ($mysqli): array {
            $res = @mysqli_query($mysqli, $sql);

            return $res ? mysqli_fetch_all($res, MYSQLI_NUM) : [];
        };
        foreach ($q("SELECT user_id, user_name FROM users WHERE user_type = 1 AND user_status = 1 AND user_archived_at IS NULL ORDER BY user_name") as $r) {
            $out['agents'][(int) $r[0]] = (string) $r[1];
        }
        foreach ($q('SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL ORDER BY client_name LIMIT 500') as $r) {
            $out['clients'][(int) $r[0]] = (string) $r[1];
        }
        foreach ($q("SELECT category_id, category_name FROM categories WHERE category_type = 'Ticket' AND category_archived_at IS NULL ORDER BY category_name") as $r) {
            $out['categories'][(int) $r[0]] = (string) $r[1];
        }
        foreach ($q('SELECT workflow_template_id, name, type FROM workflow_templates WHERE archived_at IS NULL AND is_active = 1 ORDER BY type, name') as $r) {
            $out['templates'][(int) $r[0]] = '[' . ucfirst((string) $r[2]) . '] ' . $r[1];
        }
        foreach ($q('SELECT ticket_status_name FROM ticket_statuses WHERE ticket_status_active = 1 ORDER BY ticket_status_order, ticket_status_id') as $r) {
            $out['statuses'][] = (string) $r[0];
        }

        return $out;
    }

    /** Lookups in the shape RuleSummary wants (workflow template names without the "[Type]" prefix). */
    public static function summaryLookups(array $lookups, array $eventLabels = []): array
    {
        return ['agents' => $lookups['agents'], 'clients' => $lookups['clients'], 'categories' => $lookups['categories'],
            'templates' => array_map(static fn (string $n): string => (string) preg_replace('/^\[[^\]]*\]\s*/', '', $n), $lookups['templates']), 'events' => $eventLabels];
    }

    /**
     * Every rule with its run statistics. Each row: the automation_rules columns plus
     *   last_status, last_age (seconds since the last run), runs_total, runs_7d, ok_7d, failed_7d, other_7d (throttled / loop blocked / skipped),
     *   spark (7 buckets, oldest first: ['ok'=>n,'failed'=>n,'other'=>n]), failed_24h.
     * @return list<array<string,mixed>>
     */
    public static function rules(\mysqli $mysqli): array
    {
        if (!self::tableExists($mysqli, 'automation_rules')) {
            return [];
        }
        $res = mysqli_query($mysqli, 'SELECT * FROM automation_rules ORDER BY trigger_event, ' . (self::hasEngineColumns($mysqli) ? 'priority, ' : '') . 'rule_id');
        $rules = [];
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $r['rule_id'] = (int) $r['rule_id'];
            $r['priority'] = (int) ($r['priority'] ?? 100);
            $r += ['last_status' => null, 'last_age' => null, 'runs_total' => 0, 'ok_7d' => 0, 'failed_7d' => 0, 'other_7d' => 0, 'failed_24h' => 0, 'spark' => array_fill(0, 7, ['ok' => 0, 'failed' => 0, 'other' => 0])];
            $rules[$r['rule_id']] = $r;
        }
        if ($rules && self::tableExists($mysqli, 'automation_rule_runs')) {
            $res = mysqli_query($mysqli, 'SELECT r.rule_id, r.status, TIMESTAMPDIFF(SECOND, r.created_at, NOW()) AS age FROM automation_rule_runs r JOIN (SELECT rule_id, MAX(run_id) AS m FROM automation_rule_runs GROUP BY rule_id) x ON x.m = r.run_id');
            while ($res && ($r = mysqli_fetch_assoc($res))) {
                if (isset($rules[(int) $r['rule_id']])) {
                    $rules[(int) $r['rule_id']]['last_status'] = $r['status'];
                    $rules[(int) $r['rule_id']]['last_age'] = max(0, (int) $r['age']);
                }
            }
            $res = mysqli_query($mysqli, 'SELECT rule_id, COUNT(*) FROM automation_rule_runs GROUP BY rule_id');
            while ($res && ($r = mysqli_fetch_row($res))) {
                if (isset($rules[(int) $r[0]])) {
                    $rules[(int) $r[0]]['runs_total'] = (int) $r[1];
                }
            }
            // 7 daily buckets: bucket 6 = today (server day), bucket 0 = six days ago
            $res = mysqli_query($mysqli, "SELECT rule_id, 6 - DATEDIFF(CURDATE(), DATE(created_at)) AS b, status, COUNT(*) FROM automation_rule_runs WHERE created_at >= CURDATE() - INTERVAL 6 DAY GROUP BY rule_id, b, status");
            while ($res && ($r = mysqli_fetch_row($res))) {
                $id = (int) $r[0];
                $b = (int) $r[1];
                if (!isset($rules[$id]) || $b < 0 || $b > 6) {
                    continue;
                }
                $kind = $r[2] === 'ok' ? 'ok' : ($r[2] === 'failed' ? 'failed' : 'other');
                $rules[$id]['spark'][$b][$kind] += (int) $r[3];
                $rules[$id][$kind . '_7d'] += (int) $r[3];
            }
            $res = mysqli_query($mysqli, "SELECT rule_id, COUNT(*) FROM automation_rule_runs WHERE status = 'failed' AND created_at >= NOW() - INTERVAL 24 HOUR GROUP BY rule_id");
            while ($res && ($r = mysqli_fetch_row($res))) {
                if (isset($rules[(int) $r[0]])) {
                    $rules[(int) $r[0]]['failed_24h'] = (int) $r[1];
                }
            }
        }

        return array_values($rules);
    }

    /** Whether the engine columns (priority, rate limit, stop on match) exist, i.e. the engine migration ran. */
    public static function hasEngineColumns(\mysqli $mysqli): bool
    {
        $res = @mysqli_query($mysqli, "SHOW COLUMNS FROM automation_rules LIKE 'rate_limit_per_min'");

        return (bool) ($res && mysqli_num_rows($res) > 0);
    }

    public static function tableExists(\mysqli $mysqli, string $table): bool
    {
        $res = @mysqli_query($mysqli, "SHOW TABLES LIKE '" . mysqli_real_escape_string($mysqli, $table) . "'");

        return (bool) ($res && mysqli_num_rows($res) > 0);
    }

    /**
     * Applies the list filters and sort. $f: q, group (event group key or ''), action, state (''|on|off|failing), sort (name|trigger|last_run|priority|runs), dir (asc|desc).
     * $groupOf maps an event id to its group key. @param list<array<string,mixed>> $rules @param callable(string):string $groupOf
     * @param callable(array<string,mixed>):string $summaryOf text searched in addition to the name
     * @return list<array<string,mixed>>
     */
    public static function filter(array $rules, array $f, callable $groupOf, callable $summaryOf): array
    {
        $q = mb_strtolower(trim((string) ($f['q'] ?? '')));
        $out = array_values(array_filter($rules, static function (array $r) use ($f, $q, $groupOf, $summaryOf): bool {
            if (($f['group'] ?? '') !== '' && $groupOf((string) $r['trigger_event']) !== $f['group']) {
                return false;
            }
            if (($f['action'] ?? '') !== '' && $r['action_type'] !== $f['action']) {
                return false;
            }
            $state = (string) ($f['state'] ?? '');
            if (($state === 'on' && !$r['is_enabled']) || ($state === 'off' && $r['is_enabled']) || ($state === 'failing' && $r['last_status'] !== 'failed')) {
                return false;
            }

            return $q === '' || str_contains(mb_strtolower($r['name'] . ' ' . $r['trigger_event'] . ' ' . $summaryOf($r)), $q);
        }));
        $sort = (string) ($f['sort'] ?? 'priority');
        $dir = ($f['dir'] ?? 'asc') === 'desc' ? -1 : 1;
        usort($out, static function (array $a, array $b) use ($sort, $dir): int {
            switch ($sort) {
                case 'name':
                    $c = strcasecmp($a['name'], $b['name']);
                    break;
                case 'trigger':
                    $c = [$a['trigger_event'], $a['priority']] <=> [$b['trigger_event'], $b['priority']];
                    break;
                case 'last_run':
                    // never-run rules sort as the oldest
                    $c = ($b['last_age'] ?? PHP_INT_MAX) <=> ($a['last_age'] ?? PHP_INT_MAX);
                    break;
                case 'runs':
                    $c = $a['runs_total'] <=> $b['runs_total'];
                    break;
                default:
                    $c = [$a['trigger_event'], $a['priority']] <=> [$b['trigger_event'], $b['priority']];
            }

            return ($c ?: $a['rule_id'] <=> $b['rule_id']) * $dir;
        });

        return $out;
    }

    /** Copies a rule as a disabled rule named "<name> (copy)". @return int|null the new rule id, null when the rule does not exist */
    public static function duplicate(\mysqli $mysqli, int $id): ?int
    {
        $stmt = mysqli_prepare($mysqli, 'SELECT * FROM automation_rules WHERE rule_id = ?');
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $r = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$r) {
            return null;
        }
        $name = mb_substr((string) $r['name'], 0, 200 - mb_strlen(self::COPY_SUFFIX)) . self::COPY_SUFFIX;
        $stmt = mysqli_prepare($mysqli, 'INSERT INTO automation_rules (name, trigger_event, condition_json, action_type, action_config_json, is_enabled, priority, stop_on_match, rate_limit_per_min) VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?)');
        $prio = (int) ($r['priority'] ?? 100);
        $stop = (int) ($r['stop_on_match'] ?? 0);
        $rate = (int) ($r['rate_limit_per_min'] ?? RuleEngine::DEFAULT_RATE);
        mysqli_stmt_bind_param($stmt, 'sssssiii', $name, $r['trigger_event'], $r['condition_json'], $r['action_type'], $r['action_config_json'], $prio, $stop, $rate);
        mysqli_stmt_execute($stmt);
        $new = (int) mysqli_insert_id($mysqli);
        mysqli_stmt_close($stmt);

        return $new > 0 ? $new : null;
    }

    /** Sets a rule on or off (null = flip). @return bool|null the new state, null when the rule does not exist */
    public static function toggle(\mysqli $mysqli, int $id, ?bool $on = null): ?bool
    {
        $res = mysqli_query($mysqli, 'SELECT is_enabled FROM automation_rules WHERE rule_id = ' . $id);
        $row = $res ? mysqli_fetch_row($res) : null;
        if (!$row) {
            return null;
        }
        $new = $on ?? ((int) $row[0] === 0);
        mysqli_query($mysqli, 'UPDATE automation_rules SET is_enabled = ' . ($new ? 1 : 0) . ' WHERE rule_id = ' . $id);

        return $new;
    }

    /**
     * Gives rules for ONE event the priorities 10, 20, 30... in the given order (the engine runs lower numbers first, per event).
     * @param list<int> $ids @return bool false when the ids are not all rules of one and the same event
     */
    public static function reorder(\mysqli $mysqli, array $ids): bool
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0)));
        if (count($ids) < 2 || count($ids) > 500) {
            return false;
        }
        $res = mysqli_query($mysqli, 'SELECT COUNT(*), COUNT(DISTINCT trigger_event) FROM automation_rules WHERE rule_id IN (' . implode(',', $ids) . ')');
        $row = $res ? mysqli_fetch_row($res) : null;
        if (!$row || (int) $row[0] !== count($ids) || (int) $row[1] !== 1) {
            return false;
        }
        foreach ($ids as $i => $id) {
            mysqli_query($mysqli, 'UPDATE automation_rules SET priority = ' . (($i + 1) * 10) . ' WHERE rule_id = ' . $id);
        }

        return true;
    }

    /** Whether automation_rule_runs has the retry columns (DB 2.6.227). */
    public static function hasRetryColumns(\mysqli $mysqli): bool
    {
        $res = @mysqli_query($mysqli, "SHOW COLUMNS FROM automation_rule_runs LIKE 'context_json'");

        return (bool) ($res && mysqli_num_rows($res) > 0);
    }

    /**
     * Run history of one rule, newest first. @return array{rows:list<array<string,mixed>>,total:int,counts:array<string,int>}
     * Rows carry: run_id, rule_id, event_type, status, message, duration_ms, chain_depth, created_at, age (seconds), and (after DB 2.6.227)
     * attempt (which try of the queue's MAX_ATTEMPTS), max_attempts, source (event|retry|manual), retry_of_run_id, retried_by_run_id (a later run that repeated it),
     * can_rerun (failed, kept its event, not yet repeated successfully).
     */
    public static function history(\mysqli $mysqli, int $ruleId, bool $failedOnly = false, int $limit = 50, int $offset = 0): array
    {
        return self::runs($mysqli, ['rule_id' => $ruleId, 'failed_only' => $failedOnly], $limit, $offset);
    }

    /**
     * Run log across rules. $f: rule_id (int, 0 = all), status (one of RuleEngine::STATUSES or ''), failed_only (bool), event (exact event id or '').
     * @param array<string,mixed> $f @return array{rows:list<array<string,mixed>>,total:int,counts:array<string,int>}
     */
    public static function runs(\mysqli $mysqli, array $f = [], int $limit = 50, int $offset = 0): array
    {
        $out = ['rows' => [], 'total' => 0, 'counts' => []];
        if (!self::tableExists($mysqli, 'automation_rule_runs')) {
            return $out;
        }
        $retry = self::hasRetryColumns($mysqli);
        $ruleId = (int) ($f['rule_id'] ?? 0);
        $base = $ruleId > 0 ? 'rule_id = ' . $ruleId : '1=1';
        $res = mysqli_query($mysqli, 'SELECT status, COUNT(*) FROM automation_rule_runs WHERE ' . $base . ' GROUP BY status');
        while ($res && ($r = mysqli_fetch_row($res))) {
            $out['counts'][(string) $r[0]] = (int) $r[1];
        }
        $where = $base;
        if ((int) ($f['run_id'] ?? 0) > 0) {
            $where .= ' AND run_id = ' . (int) $f['run_id'];
        }
        $status = (string) ($f['status'] ?? '');
        if (!empty($f['failed_only'])) {
            $status = 'failed';
        }
        if ($status !== '' && in_array($status, RuleEngine::STATUSES, true)) {
            $where .= " AND status = '" . $status . "'";
        }
        if (($f['event'] ?? '') !== '') {
            $where .= " AND event_type = '" . mysqli_real_escape_string($mysqli, (string) $f['event']) . "'";
        }
        $out['total'] = (int) (mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM automation_rule_runs WHERE $where"))[0] ?? 0);
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        $extra = $retry ? ', attempt, trigger_source, retry_of_run_id, (context_json IS NOT NULL) AS has_context, (SELECT MIN(r2.run_id) FROM automation_rule_runs r2 WHERE r2.retry_of_run_id = automation_rule_runs.run_id) AS retried_by, (SELECT COUNT(*) FROM automation_rule_runs r3 WHERE r3.retry_of_run_id = automation_rule_runs.run_id AND r3.status = \'ok\') AS retry_ok' : '';
        $res = mysqli_query($mysqli, "SELECT run_id, rule_id, event_type, status, message, duration_ms, chain_depth, created_at, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age$extra FROM automation_rule_runs WHERE $where ORDER BY run_id DESC LIMIT $limit OFFSET $offset");
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $row = ['run_id' => (int) $r['run_id'], 'rule_id' => (int) $r['rule_id'], 'event_type' => $r['event_type'], 'status' => $r['status'], 'message' => (string) $r['message'],
                'duration_ms' => (int) $r['duration_ms'], 'chain_depth' => (int) $r['chain_depth'], 'created_at' => $r['created_at'], 'age' => max(0, (int) $r['age'])];
            if ($retry) {
                $row += ['attempt' => (int) $r['attempt'], 'max_attempts' => RuleEngine::MAX_ATTEMPTS, 'source' => (string) $r['trigger_source'], 'retry_of_run_id' => $r['retry_of_run_id'] === null ? null : (int) $r['retry_of_run_id'],
                    'retried_by_run_id' => $r['retried_by'] === null ? null : (int) $r['retried_by'],
                    'can_rerun' => $r['status'] === 'failed' && (int) $r['has_context'] === 1 && (int) $r['retry_ok'] === 0];
            }
            $out['rows'][] = $row;
        }

        return $out;
    }

    /** "5 min ago" style text from seconds. */
    public static function ago(?int $seconds): string
    {
        if ($seconds === null) {
            return 'never';
        }
        if ($seconds < 60) {
            return 'just now';
        }
        if ($seconds < 3600) {
            return intdiv($seconds, 60) . ' min ago';
        }
        if ($seconds < 86400) {
            return intdiv($seconds, 3600) . ' h ago';
        }
        $d = intdiv($seconds, 86400);

        return $d . ($d === 1 ? ' day ago' : ' days ago');
    }
}
