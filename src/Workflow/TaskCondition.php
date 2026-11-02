<?php

namespace ITFlow\Workflow;

/**
 * "Only for some people" conditions on a template task. A task with a condition is copied onto a run only when the person matches
 * every part of it; otherwise the task does not exist on that run (nothing to tick, nothing to skip). A task without a condition
 * is on every run, exactly as before.
 *
 * The stored form (workflow_template_tasks.condition_json) is a small JSON object; every part is optional and all parts given must match:
 *   departments   list of department names (clients.client_name), case-insensitive, any one matches
 *   titles        list of words/phrases, any one contained in the job title (case-insensitive)
 *   employee_types list of contact_employee_type values (employee, contractor, ...)
 *   variables     {name: [allowed values]} answers from the onboarding form, case-insensitive, any value matches
 */
final class TaskCondition
{
    private const TYPES = ['employee', 'contractor', 'vendor', 'intern', 'service_account_owner'];

    /**
     * @param array<string,mixed>|string|null $in
     * @return string|null JSON to store, null when there is no condition
     * @throws \InvalidArgumentException
     */
    public static function normalize($in): ?string
    {
        if (is_string($in)) {
            $decoded = json_decode($in, true);
            $in = is_array($decoded) ? $decoded : [];
        }
        $in = is_array($in) ? $in : [];
        $out = [];
        $departments = self::words($in['departments'] ?? [], 20, 200);
        $titles = self::words($in['titles'] ?? [], 20, 100);
        $types = self::words($in['employee_types'] ?? [], 10, 30);
        foreach ($types as $t) {
            if (!in_array(strtolower($t), self::TYPES, true)) {
                throw new \InvalidArgumentException('Unknown employee type "' . $t . '" in the condition.');
            }
        }
        if ($departments) {
            $out['departments'] = $departments;
        }
        if ($titles) {
            $out['titles'] = $titles;
        }
        if ($types) {
            $out['employee_types'] = array_map('strtolower', $types);
        }
        $vars = [];
        foreach ((array) ($in['variables'] ?? []) as $name => $values) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            if (!preg_match('/^[a-z][a-z0-9_]{0,29}$/', $name)) {
                throw new \InvalidArgumentException('A condition names the form field "' . $name . '", which is not a valid field name.');
            }
            $vals = self::words($values, 20, 200);
            if ($vals) {
                $vars[$name] = $vals;
            }
        }
        if ($vars) {
            $out['variables'] = $vars;
        }

        return $out ? json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    }

    /**
     * Does the person match? $person: client_name, contact_title, contact_employee_type. $variables: the run's form answers.
     * @param array<string,mixed> $person
     * @param array<string,string> $variables
     */
    public static function matches(?string $conditionJson, array $person, array $variables = []): bool
    {
        return self::why($conditionJson, $person, $variables) === null;
    }

    /** null when the person matches, otherwise a short reason for the preview ("department is Sales, not Finance"). */
    public static function why(?string $conditionJson, array $person, array $variables = []): ?string
    {
        $c = json_decode((string) $conditionJson, true);
        if (!is_array($c) || !$c) {
            return null;
        }
        if (!empty($c['departments'])) {
            $dept = mb_strtolower(trim((string) ($person['client_name'] ?? '')));
            if (!in_array($dept, array_map('mb_strtolower', $c['departments']), true)) {
                return 'department is not ' . implode(' / ', $c['departments']);
            }
        }
        if (!empty($c['titles'])) {
            $title = mb_strtolower((string) ($person['contact_title'] ?? ''));
            $hit = false;
            foreach ($c['titles'] as $needle) {
                if ($needle !== '' && mb_strpos($title, mb_strtolower($needle)) !== false) {
                    $hit = true;
                    break;
                }
            }
            if (!$hit) {
                return 'job title does not contain ' . implode(' / ', $c['titles']);
            }
        }
        if (!empty($c['employee_types'])) {
            if (!in_array(strtolower((string) ($person['contact_employee_type'] ?? 'employee')), $c['employee_types'], true)) {
                return 'employee type is not ' . implode(' / ', $c['employee_types']);
            }
        }
        foreach ((array) ($c['variables'] ?? []) as $name => $allowed) {
            $have = mb_strtolower(trim((string) ($variables[$name] ?? '')));
            if (!in_array($have, array_map('mb_strtolower', $allowed), true)) {
                return 'form answer "' . $name . '" is not ' . implode(' / ', $allowed);
            }
        }

        return null;
    }

    /** One line for the template editor and the run page. */
    public static function describe(?string $conditionJson): string
    {
        $c = json_decode((string) $conditionJson, true);
        if (!is_array($c) || !$c) {
            return '';
        }
        $parts = [];
        if (!empty($c['departments'])) {
            $parts[] = 'department ' . implode(' / ', $c['departments']);
        }
        if (!empty($c['titles'])) {
            $parts[] = 'title contains ' . implode(' / ', $c['titles']);
        }
        if (!empty($c['employee_types'])) {
            $parts[] = 'type ' . implode(' / ', $c['employee_types']);
        }
        foreach ((array) ($c['variables'] ?? []) as $name => $vals) {
            $parts[] = $name . ' = ' . implode(' / ', $vals);
        }

        return 'Only when ' . implode(' and ', $parts);
    }

    /** @return list<string> */
    private static function words($raw, int $maxItems, int $maxLen): array
    {
        $items = is_array($raw) ? $raw : preg_split('/[\r\n,;]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY);
        $out = [];
        foreach ($items as $i) {
            $i = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) $i)), 0, $maxLen);
            if ($i !== '' && !in_array($i, $out, true)) {
                $out[] = $i;
            }
        }

        return array_slice($out, 0, $maxItems);
    }
}
