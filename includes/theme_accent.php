<?php
/*
 * Company appearance (Admin > Appearance / Theme) for pages that cannot carry an inline <style>, i.e. the client
 * portal (its pages send `Content-Security-Policy: default-src 'self'`). Same accent rule as includes/header.php and
 * kiosk/includes/layout_top.php: a valid custom hex wins, otherwise the preset name's hex.
 */

function itflow_theme_accent_presets(): array
{
    return [
        'teal' => '#0D9488', 'blue' => '#2563EB', 'indigo' => '#4F46E5', 'purple' => '#7C3AED', 'green' => '#16A34A',
        'red' => '#DC2626', 'orange' => '#EA580C', 'pink' => '#DB2777', 'cyan' => '#0891B2', 'yellow' => '#D97706',
        'lime' => '#65A30D', 'fuchsia' => '#C026D3', 'navy' => '#1E3A8A', 'maroon' => '#9F1239', 'gray' => '#475569',
    ];
}

/** '#RRGGBB' or '' when the company has no known accent. */
// Backing behind the company logo: '' = none, else #RRGGBB (NULL/invalid = white).
function itflow_logo_bg($raw): string
{
    $raw = (string) $raw;
    if ($raw === 'none') {
        return '';
    }
    return preg_match('/^#[0-9A-Fa-f]{6}$/', $raw) ? strtoupper($raw) : '#FFFFFF';
}

function itflow_theme_accent_hex($theme, $custom): string
{
    if (!empty($custom) && preg_match('/^#[0-9A-Fa-f]{6}$/D', (string) $custom) === 1) {
        return strtoupper((string) $custom);
    }
    $presets = itflow_theme_accent_presets();
    return $presets[(string) $theme] ?? '';
}

/** Card radius override, reduced to characters that are safe in a CSS value. */
function itflow_theme_radius($radius): string
{
    return preg_replace('/[^0-9a-z%.]/i', '', (string) $radius);
}

/** The CSS custom properties the agent shell emits inline, as a stylesheet body. $hex is '#RRGGBB'. */
function itflow_theme_accent_css(string $hex, string $radius = ''): string
{
    $css = '';
    if (preg_match('/^#[0-9A-F]{6}$/D', $hex) === 1) {
        $r = hexdec(substr($hex, 1, 2));
        $g = hexdec(substr($hex, 3, 2));
        $b = hexdec(substr($hex, 5, 2));
        $darken = function ($c) { return max(0, min(255, (int) round($c * 0.82))); };
        $lighten = function ($c) { return max(0, min(255, (int) round($c + (255 - $c) * 0.28))); };
        $hl = sprintf('#%02X%02X%02X', $darken($r), $darken($g), $darken($b));
        $hd = sprintf('#%02X%02X%02X', $lighten($r), $lighten($g), $lighten($b));
        $rgb = "$r, $g, $b";
        $css .= ":root{--color-accent:$hex;--color-accent-rgb:$rgb;--color-accent-hover:$hl;--color-accent-soft:rgba($rgb,.12);"
              . "--bs-primary:$hex;--bs-primary-rgb:$rgb;--bs-link-color:$hex;--bs-link-color-rgb:$rgb;--bs-link-hover-color:$hl}\n"
              . "body.dark-mode{--color-accent:$hex;--color-accent-rgb:$rgb;--color-accent-hover:$hd;--color-accent-soft:rgba($rgb,.22)}\n"
              . ":root[data-bs-theme=\"dark\"]{--bs-primary:$hex;--bs-primary-rgb:$rgb;--bs-link-color:$hex;--bs-link-color-rgb:$rgb;--bs-link-hover-color:$hd}\n";
    }
    $radius = itflow_theme_radius($radius);
    if ($radius !== '') {
        $css .= ":root{--card-radius:$radius}\n";
    }
    return $css;
}

/** WCAG 2.x relative luminance of '#RRGGBB' (0 = black .. 1 = white). */
function itflow_rel_luminance(string $hex): float
{
    $c = [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
    foreach ($c as $i => $v) {
        $v /= 255;
        $c[$i] = $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
    }
    return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
}

/** WCAG 2.x contrast ratio of two '#RRGGBB' colours, 1.0 .. 21.0. */
function itflow_contrast_ratio(string $a, string $b): float
{
    $la = itflow_rel_luminance($a);
    $lb = itflow_rel_luminance($b);
    return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
}

/**
 * Plain-language warnings for a company accent that will be hard to read, [] when it is fine. Mirrors what the CSS does with
 * it (itflow_theme_accent_css): primary buttons in the agent shell use the accent at ~84% brightness with white text (measured: #2563EB -> rgb(31,83,197); the portal's value differs by a few points), and links and focus
 * rings use the accent itself on the light page background (~#F0F2F5). The dark theme adapts the accent and is not checked here.
 * WCAG AA asks 4.5:1 for text and 3:1 for focus indicators.
 *
 * @return list<string>
 */
function itflow_accent_contrast_notes(string $hex): array
{
    if (preg_match('/^#[0-9A-Fa-f]{6}$/D', $hex) !== 1) {
        return [];
    }
    $notes = [];
    $btn = sprintf('#%02X%02X%02X', (int) round(hexdec(substr($hex, 1, 2)) * 0.84), (int) round(hexdec(substr($hex, 3, 2)) * 0.84), (int) round(hexdec(substr($hex, 5, 2)) * 0.84));
    $btnRatio = itflow_contrast_ratio($btn, '#FFFFFF');
    if ($btnRatio < 4.5) {
        $notes[] = sprintf('White text on primary buttons has %.1f:1 contrast (WCAG AA asks 4.5:1), so button labels will be hard to read.', $btnRatio);
    }
    $linkRatio = itflow_contrast_ratio($hex, '#F0F2F5');
    if ($linkRatio < 4.5) {
        $notes[] = sprintf('Links in this colour have about %.1f:1 contrast on light pages (WCAG AA asks 4.5:1 for text%s).', $linkRatio, $linkRatio < 3 ? ' and 3:1 for focus outlines' : '');
    }
    return $notes;
}
