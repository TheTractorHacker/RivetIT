<?php

namespace ITFlow\Automation;

/**
 * {field} placeholders for rule action text. The values come from the event (a ticket subject or a contact name can hold anything),
 * so every value is escaped for where the text ends up: 'html' (mail bodies, ticket notes) or 'text' (subjects; control characters,
 * so CR/LF, are flattened). One pass only: a substituted value is never expanded again, and a placeholder can never choose a recipient or a URL
 * (those fields are not templated at all).
 */
final class Template
{
    public const MAX_VALUE = 500;

    /** @param array<string,string> $context */
    public static function render(string $template, array $context, string $mode = 'text'): string
    {
        return (string) preg_replace_callback('/\{([A-Za-z0-9_.]{1,100})\}/', static function (array $m) use ($context, $mode): string {
            $value = mb_substr((string) ($context[$m[1]] ?? ''), 0, self::MAX_VALUE);

            return $mode === 'html'
                ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8')
                : trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value));
        }, $template);
    }

    /** Plain admin-written text to safe HTML: escaped, newlines kept. Call after render($t, $ctx, 'html') on the same text is wrong; use this. */
    public static function renderHtmlBlock(string $template, array $context): string
    {
        // Escape the template first (admin text), then substitute escaped values, so neither side can inject markup.
        $escapedTemplate = htmlspecialchars($template, ENT_QUOTES, 'UTF-8');

        return nl2br(self::render($escapedTemplate, $context, 'html'), false);
    }
}
