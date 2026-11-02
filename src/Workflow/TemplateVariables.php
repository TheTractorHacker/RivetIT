<?php

namespace ITFlow\Workflow;

use ITFlow\Workflow\Actions\Placeholders;

/**
 * The onboarding form of a template: questions asked when a run is started ("Needs a laptop?", "Building access level"). The answers
 * are stored on the run (workflow_runs.variables_json), usable in action text as {{var.name}} and in task conditions.
 *
 * A definition (workflow_templates.variables_json) is a list of {name, label, type: text|yes_no|select|date, required, default, options}.
 * Answers are plain strings, length-limited, and always escaped where they are shown or put in mail (Placeholders does that).
 */
final class TemplateVariables
{
    public const TYPES = ['text' => 'Text', 'yes_no' => 'Yes / no', 'select' => 'Choose one', 'date' => 'Date'];
    public const MAX_FIELDS = 12;

    /**
     * @param array<int,array<string,mixed>>|string|null $in definitions as posted or stored
     * @return list<array{name:string,label:string,type:string,required:bool,default:string,options:list<string>}>
     * @throws \InvalidArgumentException
     */
    public static function normalizeDefinitions($in): array
    {
        if (is_string($in)) {
            $in = json_decode($in, true);
        }
        $out = [];
        $seen = [];
        foreach (is_array($in) ? $in : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = strtolower(trim((string) ($row['name'] ?? '')));
            $label = mb_substr(trim((string) ($row['label'] ?? '')), 0, 100);
            if ($name === '' && $label === '') {
                continue; // an empty row in the editor
            }
            if (!preg_match('/^[a-z][a-z0-9_]{0,29}$/', $name)) {
                throw new \InvalidArgumentException('A form field name must start with a letter and use lower-case letters, digits and underscores (up to 30), for example needs_laptop.');
            }
            if (in_array($name, Placeholders::NAMES, true) || isset($seen[$name])) {
                throw new \InvalidArgumentException('The form field name "' . $name . '" is already used.');
            }
            $type = (string) ($row['type'] ?? 'text');
            if (!isset(self::TYPES[$type])) {
                $type = 'text';
            }
            $options = [];
            if ($type === 'select') {
                $raw = is_array($row['options'] ?? null) ? $row['options'] : preg_split('/[\r\n,;]+/', (string) ($row['options'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
                foreach ($raw as $o) {
                    $o = mb_substr(trim((string) $o), 0, 100);
                    if ($o !== '' && !in_array($o, $options, true)) {
                        $options[] = $o;
                    }
                }
                $options = array_slice($options, 0, 20);
                if (count($options) < 2) {
                    throw new \InvalidArgumentException('The choice field "' . $name . '" needs at least two options.');
                }
            }
            $default = mb_substr(trim((string) ($row['default'] ?? '')), 0, 200);
            if ($type === 'yes_no' && $default !== '' && !in_array(strtolower($default), ['yes', 'no'], true)) {
                $default = '';
            }
            if ($type === 'select' && $default !== '' && !in_array($default, $options, true)) {
                $default = '';
            }
            $seen[$name] = true;
            $out[] = [
                'name' => $name,
                'label' => $label !== '' ? $label : $name,
                'type' => $type,
                'required' => !empty($row['required']),
                'default' => $type === 'yes_no' ? strtolower($default) : $default,
                'options' => $options,
            ];
        }
        if (count($out) > self::MAX_FIELDS) {
            throw new \InvalidArgumentException('A template can ask at most ' . self::MAX_FIELDS . ' questions.');
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    public static function definitions(?string $json): array
    {
        try {
            return self::normalizeDefinitions($json);
        } catch (\InvalidArgumentException) {
            return [];
        }
    }

    /**
     * Checks submitted answers against the definitions. Automation cannot ask a person, so with $strict = false a missing answer takes
     * its default (or stays empty) instead of failing.
     * @param list<array<string,mixed>> $defs
     * @param array<string,mixed> $submitted
     * @return array<string,string> name => answer (unknown names are dropped)
     * @throws \InvalidArgumentException naming the first problem
     */
    public static function resolve(array $defs, array $submitted, bool $strict): array
    {
        $out = [];
        foreach ($defs as $d) {
            $name = $d['name'];
            $v = array_key_exists($name, $submitted) ? trim((string) $submitted[$name]) : '';
            $v = mb_substr(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/', ' ', $v), 0, 200);
            if ($v === '') {
                $v = (string) $d['default'];
            }
            if ($v === '' && $strict && $d['required']) {
                throw new \InvalidArgumentException('Answer "' . $d['label'] . '".');
            }
            if ($v !== '') {
                if ($d['type'] === 'yes_no') {
                    $v = strtolower($v);
                    if (!in_array($v, ['yes', 'no'], true)) {
                        throw new \InvalidArgumentException('"' . $d['label'] . '" must be yes or no.');
                    }
                } elseif ($d['type'] === 'select' && !in_array($v, $d['options'], true)) {
                    throw new \InvalidArgumentException('"' . $d['label'] . '": choose one of the listed options.');
                } elseif ($d['type'] === 'date' && (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) !== 1 || !checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4)))) {
                    throw new \InvalidArgumentException('"' . $d['label'] . '" must be a date (YYYY-MM-DD).');
                }
            }
            $out[$name] = $v;
        }

        return $out;
    }

    /** Answers as placeholder values: ['var.needs_laptop' => 'yes']. @param array<string,string> $answers @return array<string,string> */
    public static function asPlaceholders(array $answers): array
    {
        $out = [];
        foreach ($answers as $k => $v) {
            $out['var.' . $k] = (string) $v;
        }

        return $out;
    }
}
