<?php

namespace ITFlow\Automation;

/**
 * Event-rule conditions: a validated model, an evaluator and a (de)serialiser.
 *
 * Model (automation_rules.condition_json):
 *   - legacy, still read and still written when it is enough:  {"priority":"High","ticket.client_id":"4"}  (every field equals the value)
 *   - v2:  {"version":2,"mode":"all","conditions":[
 *             {"field":"ticket_priority","op":"eq","value":"High"},
 *             {"mode":"any","conditions":[{"field":"client_id","op":"in","value":"4,7"},{"field":"ticket_subject","op":"contains","value":"VPN"}]} ]}
 *   "mode" is all (AND) or any (OR). A group holds plain conditions only (one level deep).
 *
 * Operators: eq, ne, in (comma separated list), contains, gt, lt (both sides numeric), is_empty.
 * A field missing from the event: eq/in/contains/gt/lt are false (as the original engine behaved: missing never equals anything),
 * ne and is_empty are true. eq is an exact, case-sensitive string comparison, like the original engine. contains is case-insensitive.
 * Malformed or invalid stored conditions never match (fail closed); empty conditions always match.
 */
final class ConditionEvaluator
{
    public const OPERATORS = [
        'eq' => 'equals', 'ne' => 'does not equal', 'in' => 'is one of', 'contains' => 'contains',
        'gt' => 'is greater than', 'lt' => 'is less than', 'is_empty' => 'is empty',
    ];
    public const MAX_CONDITIONS = 20;
    private const FIELD_RE = '/^[A-Za-z0-9_.]{1,100}$/';

    /**
     * Normalises stored or submitted conditions to the v2 shape. Accepts JSON text, a decoded legacy map or a v2 array.
     * @return array{mode:string, conditions:array<int,array<string,mixed>>}|null null when it is not a valid conditions structure
     */
    public static function normalize($raw): ?array
    {
        if (is_string($raw)) {
            $raw = trim($raw);
            if ($raw === '' || $raw === '{}' || $raw === '[]' || strtolower($raw) === 'null') {
                return ['mode' => 'all', 'conditions' => []];
            }
            $raw = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return null;
            }
        }
        if ($raw === null || $raw === []) {
            return ['mode' => 'all', 'conditions' => []];
        }
        if (!is_array($raw)) {
            return null;
        }
        if (($raw['version'] ?? null) === 2 || isset($raw['conditions'])) {
            return self::normalizeGroup($raw, true, $count);
        }
        // legacy flat map: field => expected
        $out = [];
        foreach ($raw as $field => $expected) {
            if (!is_string($field) || !preg_match(self::FIELD_RE, $field) || !is_scalar($expected)) {
                return null;
            }
            $out[] = ['field' => $field, 'op' => 'eq', 'value' => (string) $expected];
        }

        return count($out) > self::MAX_CONDITIONS ? null : ['mode' => 'all', 'conditions' => $out];
    }

    /** @return array{mode:string, conditions:array<int,array<string,mixed>>}|null */
    private static function normalizeGroup(array $g, bool $top, ?int &$count): ?array
    {
        static $total = 0;
        if ($top) {
            $total = 0;
        }
        $mode = $g['mode'] ?? 'all';
        if (!in_array($mode, ['all', 'any'], true) || !isset($g['conditions']) || !is_array($g['conditions']) || !array_is_list($g['conditions'])) {
            return $g === ['version' => 2] ? ['mode' => 'all', 'conditions' => []] : null;
        }
        $items = [];
        foreach ($g['conditions'] as $c) {
            if (!is_array($c)) {
                return null;
            }
            if (isset($c['conditions'])) {
                if (!$top) {
                    return null; // groups are one level deep
                }
                $sub = self::normalizeGroup($c, false, $count);
                if ($sub === null) {
                    return null;
                }
                if ($sub['conditions'] !== []) {
                    $items[] = $sub;
                }
                continue;
            }
            $leaf = self::normalizeLeaf($c);
            if ($leaf === null) {
                return null;
            }
            $items[] = $leaf;
            if (++$total > self::MAX_CONDITIONS) {
                return null;
            }
        }

        return ['mode' => $mode, 'conditions' => $items];
    }

    /** @return array{field:string,op:string,value:string}|null */
    private static function normalizeLeaf(array $c): ?array
    {
        $field = $c['field'] ?? null;
        $op = $c['op'] ?? null;
        $value = $c['value'] ?? '';
        if (!is_string($field) || !preg_match(self::FIELD_RE, $field) || !is_string($op) || !isset(self::OPERATORS[$op]) || !is_scalar($value)) {
            return null;
        }
        $value = (string) $value;
        if (mb_strlen($value) > ($op === 'in' ? 500 : 200)) {
            return null;
        }
        if ($op === 'is_empty') {
            $value = '';
        }
        if (in_array($op, ['gt', 'lt'], true) && !is_numeric($value)) {
            return null;
        }

        return ['field' => $field, 'op' => $op, 'value' => $value];
    }

    /**
     * Validates admin input (rows from the form). Rows with no field are skipped.
     * @param array<int,array<string,mixed>> $rows each: ['field','op','value','group'] (group '' = top level, otherwise a group number)
     * @param array<string,string> $groupModes group number => 'all'|'any'
     * @throws \InvalidArgumentException with a message safe to show an administrator
     */
    public static function fromForm(string $topMode, array $rows, array $groupModes = []): array
    {
        if (!in_array($topMode, ['all', 'any'], true)) {
            throw new \InvalidArgumentException('Choose whether all or any of the conditions must match.');
        }
        $top = [];
        $groups = [];
        foreach ($rows as $r) {
            $field = trim((string) ($r['field'] ?? ''));
            if ($field === '') {
                continue;
            }
            $leaf = self::normalizeLeaf(['field' => $field, 'op' => (string) ($r['op'] ?? 'eq'), 'value' => (string) ($r['value'] ?? '')]);
            if ($leaf === null) {
                throw new \InvalidArgumentException("The condition on \"$field\" is not valid (check the field name, the operator, and that greater/less than use a number).");
            }
            $g = (string) ($r['group'] ?? '');
            if ($g === '') {
                $top[] = $leaf;
            } else {
                $groups[$g][] = $leaf;
            }
        }
        $items = $top;
        foreach ($groups as $g => $leaves) {
            $m = $groupModes[$g] ?? 'any';
            if (!in_array($m, ['all', 'any'], true)) {
                throw new \InvalidArgumentException('A condition group has an invalid match type.');
            }
            $items[] = ['mode' => $m, 'conditions' => $leaves];
        }
        $model = ['mode' => $topMode, 'conditions' => $items];
        if (self::normalize(self::serialize($model) ?? '') === null) {
            throw new \InvalidArgumentException('Too many conditions (the limit is ' . self::MAX_CONDITIONS . ').');
        }

        return $model;
    }

    /** The JSON to store: the legacy flat map when the model is only "all of: field = value" with unique fields, otherwise v2. null = no conditions. */
    public static function serialize(array $model): ?string
    {
        if ($model['conditions'] === []) {
            return null;
        }
        $flat = [];
        $simple = $model['mode'] === 'all';
        foreach ($model['conditions'] as $c) {
            if (isset($c['conditions']) || $c['op'] !== 'eq' || isset($flat[$c['field']])) {
                $simple = false;
                break;
            }
            $flat[$c['field']] = $c['value'];
        }
        if ($simple) {
            return json_encode($flat, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return json_encode(['version' => 2] + $model, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** True when the event satisfies the stored conditions. @param array<string,string> $context flat event context */
    public static function matches($raw, array $context): bool
    {
        return self::explain($raw, $context)['matched'];
    }

    /**
     * Evaluates and reports each condition (for the Test rule page).
     * @return array{matched:bool, valid:bool, details:list<array{text:string, ok:bool}>}
     */
    public static function explain($raw, array $context): array
    {
        $model = self::normalize($raw);
        if ($model === null) {
            return ['matched' => false, 'valid' => false, 'details' => [['text' => 'The stored conditions are not valid, so the rule never fires.', 'ok' => false]]];
        }
        if ($model['conditions'] === []) {
            return ['matched' => true, 'valid' => true, 'details' => []];
        }
        $details = [];
        $matched = self::evalGroup($model, $context, $details, '');

        return ['matched' => $matched, 'valid' => true, 'details' => $details];
    }

    private static function evalGroup(array $g, array $ctx, array &$details, string $indent): bool
    {
        $results = [];
        foreach ($g['conditions'] as $c) {
            if (isset($c['conditions'])) {
                $details[] = ['text' => $indent . 'group (' . ($c['mode'] === 'all' ? 'all of' : 'any of') . '):', 'ok' => true];
                $results[] = self::evalGroup($c, $ctx, $details, $indent . '    ');
            } else {
                $ok = self::evalLeaf($c, $ctx);
                $results[] = $ok;
                $details[] = ['text' => $indent . $c['field'] . ' ' . self::OPERATORS[$c['op']] . ($c['op'] === 'is_empty' ? '' : ' "' . $c['value'] . '"') . '  (event has ' . (array_key_exists($c['field'], $ctx) ? '"' . mb_substr((string) $ctx[$c['field']], 0, 80) . '"' : 'no such field') . ')', 'ok' => $ok];
            }
        }
        if ($results === []) {
            return true;
        }

        return $g['mode'] === 'all' ? !in_array(false, $results, true) : in_array(true, $results, true);
    }

    private static function evalLeaf(array $c, array $ctx): bool
    {
        $has = array_key_exists($c['field'], $ctx);
        $actual = $has ? (string) $ctx[$c['field']] : '';
        $expected = (string) $c['value'];
        switch ($c['op']) {
            case 'eq':
                return $has && $actual === $expected;
            case 'ne':
                return !$has || $actual !== $expected;
            case 'in':
                if (!$has) {
                    return false;
                }
                foreach (explode(',', $expected) as $item) {
                    if (trim($item) === $actual) {
                        return true;
                    }
                }

                return false;
            case 'contains':
                return $has && $expected !== '' && mb_stripos($actual, $expected) !== false;
            case 'gt':
                return $has && is_numeric($actual) && (float) $actual > (float) $expected;
            case 'lt':
                return $has && is_numeric($actual) && (float) $actual < (float) $expected;
            case 'is_empty':
                return !$has || trim($actual) === '';
        }

        return false;
    }
}
