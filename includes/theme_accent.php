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
