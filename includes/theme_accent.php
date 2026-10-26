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
    $css .= itflow_accent_aa_css($hex);
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

/** '#RRGGBB' -> [r, g, b]. */
function itflow_hex_rgb(string $hex): array
{
    return [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
}

/** CSS color-mix(in srgb, $a (1-$t), $b $t) for two '#RRGGBB' colours. */
function itflow_mix_hex(string $a, string $b, float $t): string
{
    $x = itflow_hex_rgb($a);
    $y = itflow_hex_rgb($b);
    return sprintf('#%02X%02X%02X', (int) round($x[0] * (1 - $t) + $y[0] * $t), (int) round($x[1] * (1 - $t) + $y[1] * $t), (int) round($x[2] * (1 - $t) + $y[2] * $t));
}

/** Moves $hex toward $toward in 6% steps until it has $min contrast against $bg (or it can go no further). */
function itflow_hex_until_contrast(string $hex, string $toward, string $bg, float $min): string
{
    for ($i = 0; $i < 18 && itflow_contrast_ratio($hex, $bg) < $min; $i++) {
        $hex = itflow_mix_hex($hex, $toward, 0.06);
    }
    return $hex;
}

/**
 * The readable (WCAG AA) versions of a company accent, for the jobs where the accent is used as ink or as a button fill. The
 * accent itself is untouched everywhere it is only decoration. Surfaces are the ones measured on a running install: light page
 * #EEF2F2, dark page #0D1417 with the lightest surface text normally sits on at #1F2937 (portal cards). Mirrors the shared CSS: .btn-primary is the accent mixed 84% with black,
 * and --color-link is the accent in light mode and the accent mixed 72% with white in dark mode.
 *
 * @return array{btn:string,btn_text:string,btn_hover:string,link:string,link_hover:string,link_dark:string,link_dark_hover:string,
 *   focus:string,focus_dark:string,btn_changed:bool,link_changed:bool,link_dark_changed:bool,focus_changed:bool,focus_dark_changed:bool,
 *   btn_white:float,link_ratio:float,link_dark_ratio:float}
 */
function itflow_accent_aa(string $hex): array
{
    $hex = strtoupper($hex);
    $lightBg = '#EEF2F2';
    $darkBg = '#1F2937';
    $ink = '#111827';

    $base = itflow_mix_hex($hex, '#000000', 0.16);
    $white = itflow_contrast_ratio($base, '#FFFFFF');
    $btn = $base;
    $text = '#FFFFFF';
    if ($white < 4.5) {
        if (itflow_contrast_ratio($base, $ink) >= 4.5) {
            $text = $ink;                                   // a light accent: dark label text
        } else {
            $btn = itflow_hex_until_contrast($base, '#000000', '#FFFFFF', 4.5);   // a mid accent: deepen the fill
        }
    }
    $btnHover = $text === '#FFFFFF' ? itflow_mix_hex($btn, '#000000', 0.10) : itflow_mix_hex($btn, '#FFFFFF', 0.12);
    if (itflow_contrast_ratio($btnHover, $text) < 4.5) {
        $btnHover = $btn;
    }

    $link = itflow_hex_until_contrast($hex, '#000000', $lightBg, 4.5);
    $linkHover = itflow_mix_hex($link, '#000000', 0.18);
    $coreDark = itflow_mix_hex($hex, '#FFFFFF', 0.28);
    $linkDark = itflow_hex_until_contrast($coreDark, '#FFFFFF', $darkBg, 4.5);
    $linkDarkHover = itflow_mix_hex($linkDark, '#FFFFFF', 0.20);
    $focus = itflow_hex_until_contrast($hex, '#000000', $lightBg, 3.0);
    // the shared CSS draws the focus border in the raw accent in both themes (--if-primary), so that is the baseline, not the lightened link colour
    $focusDark = itflow_hex_until_contrast($hex, '#FFFFFF', $darkBg, 3.0);

    return [
        'btn' => $btn, 'btn_text' => $text, 'btn_hover' => $btnHover,
        'link' => $link, 'link_hover' => $linkHover, 'link_dark' => $linkDark, 'link_dark_hover' => $linkDarkHover,
        'focus' => $focus, 'focus_dark' => $focusDark,
        'btn_changed' => $btn !== $base || $text !== '#FFFFFF',
        'link_changed' => $link !== $hex,
        'link_dark_changed' => $linkDark !== $coreDark,
        'focus_changed' => $focus !== $hex,
        'focus_dark_changed' => $focusDark !== $hex,
        'btn_white' => $white,
        'link_ratio' => itflow_contrast_ratio($hex, $lightBg),
        'link_dark_ratio' => itflow_contrast_ratio($coreDark, $darkBg),
    ];
}

/**
 * CSS that makes a company accent readable where it is used as ink or as a button fill (see itflow_accent_aa). Empty for an accent that
 * is already fine, so a compliant accent changes nothing. Meant to be emitted after the accent variables, last in the cascade.
 */
function itflow_accent_aa_css(string $hex): string
{
    if (preg_match('/^#[0-9A-Fa-f]{6}$/D', $hex) !== 1) {
        return '';
    }
    $a = itflow_accent_aa($hex);
    $rgb = static fn (string $h): string => implode(', ', itflow_hex_rgb($h));
    $css = '';
    $light = '';
    if ($a['link_changed']) {
        $light .= "--color-link:{$a['link']};--color-link-hover:{$a['link_hover']};--bs-link-color:{$a['link']};--bs-link-color-rgb:" . $rgb($a['link']) . ";--bs-link-hover-color:{$a['link_hover']};";
    }
    if ($a['focus_changed'] || $a['focus_dark_changed']) {
        $light .= '--color-accent-focus:' . ($a['focus_changed'] ? $a['focus'] : strtoupper($hex)) . ';';
    }
    if ($light !== '') {
        $css .= ":root{{$light}}\n";
    }
    $dark = '';
    if ($a['link_dark_changed']) {
        $dark .= "--color-link:{$a['link_dark']};--color-link-hover:{$a['link_dark_hover']};--bs-link-color:{$a['link_dark']};--bs-link-color-rgb:" . $rgb($a['link_dark']) . ";--bs-link-hover-color:{$a['link_dark_hover']};";
    }
    if ($a['focus_changed'] || $a['focus_dark_changed']) {
        $dark .= '--color-accent-focus:' . ($a['focus_dark_changed'] ? $a['focus_dark'] : strtoupper($hex)) . ';';
    }
    if ($dark !== '') {
        $css .= "body.dark-mode,:root[data-bs-theme=\"dark\"]{{$dark}}\n";
    }
    if ($a['focus_changed'] || $a['focus_dark_changed']) {
        $css .= ".form-control:focus,.form-select:focus,.ts-control.focus,.ts-wrapper.focus .ts-control{border-color:var(--color-accent-focus,var(--if-primary));box-shadow:0 0 0 1px var(--color-accent-focus,var(--if-primary))}\n";
    }
    if ($a['btn_changed']) {
        // html body.portal-ui: the portal sets its own .portal-ui .btn-primary colours in a sheet that loads after this one, so match it with more weight.
        $css .= ".btn-primary,html body.portal-ui .btn-primary{background-color:{$a['btn']};border-color:{$a['btn']};color:{$a['btn_text']}}\n"
              . ".btn-primary:hover,.btn-primary:focus,.btn-primary:active,.btn-primary:not(:disabled):not(.disabled):active,"
              . "html body.portal-ui .btn-primary:hover,html body.portal-ui .btn-primary:focus,html body.portal-ui .btn-primary:not(:disabled):not(.disabled):active"
              . "{background-color:{$a['btn_hover']};border-color:{$a['btn_hover']};color:{$a['btn_text']}}\n";
    }
    return $css;
}

/**
 * Plain-language notes for Admin > Appearance about an accent that cannot be used as-is for text and buttons, [] when it is fine. The
 * app already adjusts those uses (itflow_accent_aa_css), so these say what is being done, not that something is broken.
 *
 * @return list<string>
 */
function itflow_accent_contrast_notes(string $hex): array
{
    if (preg_match('/^#[0-9A-Fa-f]{6}$/D', $hex) !== 1) {
        return [];
    }
    $a = itflow_accent_aa($hex);
    $notes = [];
    if ($a['btn_changed']) {
        $notes[] = sprintf('Buttons use %s so their label stays readable (the exact color with white text would be only %.1f:1; WCAG AA asks 4.5:1).',
            $a['btn_text'] === '#FFFFFF' ? 'a deeper shade of this color' : 'dark label text', $a['btn_white']);
    }
    if ($a['link_changed']) {
        $notes[] = sprintf('On light pages, links and focus outlines use a darker shade (the exact color is only %.1f:1 against the page; WCAG AA asks 4.5:1 for text).', $a['link_ratio']);
    }
    if ($a['link_dark_changed']) {
        $notes[] = sprintf('On the dark theme, links use a lighter shade (the exact color is only %.1f:1 against the page).', $a['link_dark_ratio']);
    }
    return $notes;
}
