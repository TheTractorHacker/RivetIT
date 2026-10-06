<?php

namespace ITFlow\Workflow\Actions;

/**
 * {{employee_name}} style placeholders for action text. Values come from the directory (a name can contain anything), so each
 * mode escapes for where the text ends up: 'html' for mail bodies and ticket details, 'text' for plain text (strips control characters).
 */
final class Placeholders
{
    public const NAMES = [
        'employee_name', 'employee_email', 'employee_title', 'start_date', 'end_date',
        'manager_name', 'manager_email', 'department', 'template_name', 'run_id', 'task_title',
    ];

    public static function render(string $template, array $vars, string $mode = 'text'): string
    {
        return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', static function (array $m) use ($vars, $mode): string {
            if (!in_array($m[1], self::NAMES, true)) {
                return $m[0]; // an unknown name stays visible instead of silently vanishing
            }
            $value = (string) ($vars[$m[1]] ?? '');
            if ($mode === 'html') {
                return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
            }

            return trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value));
        }, $template) ?? $template;
    }

    /** Names used in $template that are not known placeholders (for validation). @return string[] */
    public static function unknown(string $template): array
    {
        preg_match_all('/\{\{\s*([a-zA-Z_]+)\s*\}\}/', $template, $m);

        return array_values(array_unique(array_filter($m[1], static fn ($n) => !in_array($n, self::NAMES, true))));
    }
}
