<?php

/**
 * The portal sends "Content-Security-Policy: default-src 'self'", so every inline style="" attribute in a KB article is blocked
 * and the browser logs a violation per element. Rather than loosen the policy, rewrite the handful of formatting properties editors
 * actually emit into utility classes (defined in css/itflow_custom.css) and drop the rest.
 * Runs on already-purified HTML; output is still purifier-clean because only class names from a fixed list are added.
 */
function kbPortalStripInlineStyles(string $html): string
{
    if (stripos($html, 'style=') === false) {
        return $html;
    }

    $dom = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8"?><div id="kb-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    $xpath = new DOMXPath($dom);
    foreach ($xpath->query('//*[@style]') as $el) {
        $classes = [];
        foreach (explode(';', $el->getAttribute('style')) as $decl) {
            [$prop, $val] = array_pad(explode(':', $decl, 2), 2, '');
            $prop = strtolower(trim($prop));
            $val = strtolower(trim($val));
            if ($prop === 'text-align' && in_array($val, ['left', 'center', 'right', 'justify'], true)) {
                $classes[] = 'kb-ta-' . $val;
            } elseif ($prop === 'font-weight' && ($val === 'bold' || (int) $val >= 600)) {
                $classes[] = 'kb-bold';
            } elseif ($prop === 'font-style' && $val === 'italic') {
                $classes[] = 'kb-italic';
            } elseif ($prop === 'text-decoration' && str_contains($val, 'underline')) {
                $classes[] = 'kb-underline';
            } elseif ($prop === 'text-decoration' && str_contains($val, 'line-through')) {
                $classes[] = 'kb-strike';
            } elseif ($prop === 'padding-left' || $prop === 'margin-left') {
                $classes[] = 'kb-indent';
            }
        }
        $el->removeAttribute('style');
        if ($classes) {
            $existing = trim($el->getAttribute('class'));
            $el->setAttribute('class', trim($existing . ' ' . implode(' ', array_unique($classes))));
        }
    }

    $root = $dom->getElementById('kb-root') ?? $xpath->query('//div[@id="kb-root"]')->item(0);
    $out = '';
    foreach ($root->childNodes as $child) {
        $out .= $dom->saveHTML($child);
    }
    return $out;
}
