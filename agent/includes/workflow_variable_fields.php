<?php

/**
 * Renders the onboarding-form questions of a workflow template as inputs named var[<name>]. Everything echoed is escaped.
 * @param list<array<string,mixed>> $defs from TemplateVariables::definitions()
 * @param array<string,string> $values answers to pre-fill
 */
function workflowVariableFields(array $defs, array $values = [], string $idPrefix = 'wv'): void
{
    $h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    foreach ($defs as $d) {
        $id = $idPrefix . '_' . $d['name'];
        $val = (string) ($values[$d['name']] ?? $d['default']);
        echo '<div class="mb-2"><label class="form-label small mb-1" for="' . $h($id) . '">' . $h($d['label']) . ($d['required'] ? ' <span class="text-danger">*</span>' : '') . '</label>';
        $name = 'var[' . $d['name'] . ']';
        $req = $d['required'] ? ' required' : '';
        if ($d['type'] === 'yes_no') {
            echo '<select class="form-select form-select-sm" id="' . $h($id) . '" name="' . $h($name) . '"' . $req . '><option value="">-</option>';
            foreach (['yes' => 'Yes', 'no' => 'No'] as $k => $l) {
                echo '<option value="' . $k . '"' . ($val === $k ? ' selected' : '') . '>' . $l . '</option>';
            }
            echo '</select>';
        } elseif ($d['type'] === 'select') {
            echo '<select class="form-select form-select-sm" id="' . $h($id) . '" name="' . $h($name) . '"' . $req . '><option value="">-</option>';
            foreach ($d['options'] as $o) {
                echo '<option value="' . $h($o) . '"' . ($val === $o ? ' selected' : '') . '>' . $h($o) . '</option>';
            }
            echo '</select>';
        } else {
            echo '<input class="form-control form-control-sm" id="' . $h($id) . '" name="' . $h($name) . '" type="' . ($d['type'] === 'date' ? 'date' : 'text') . '" maxlength="200" value="' . $h($val) . '"' . $req . '>';
        }
        echo '</div>';
    }
}

/** The answers posted by workflowVariableFields(): var[name] => value, strings only. @return array<string,string> */
function workflowVariablesFromRequest(array $request): array
{
    $out = [];
    foreach ((array) ($request['var'] ?? []) as $k => $v) {
        if (is_string($k) && is_scalar($v)) {
            $out[$k] = (string) $v;
        }
    }

    return $out;
}
