<?php

    // Calculate Execution time start
    // uncomment for test
    // $time_start = microtime(true);

// Per-request nonce so inline <script> blocks (used extensively throughout this
// app) can execute under a strict script-src without falling back to
// 'unsafe-inline', which would undo CSP's actual XSS protection.
$csp_nonce = base64_encode(random_bytes(16));

header("X-Frame-Options: DENY");
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$csp_nonce' https://static.cloudflareinsights.com; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https://*.foleyit.com; connect-src 'self' https://cloudflareinsights.com");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");

// ----- Theme resolution (computed early so <html> can carry the BS5 color mode) -----
// Preset accent name -> hex. Mirrors the AdminLTE accent-<name> presets and drives the
// CSS-variable theme (--color-accent) so both preset and custom paths recolor the whole app.
$theme_accent_presets = [
    'teal'    => '#0D9488',
    'blue'    => '#2563EB',
    'indigo'  => '#4F46E5',
    'purple'  => '#7C3AED',
    'green'   => '#16A34A',
    'red'     => '#DC2626',
    'orange'  => '#EA580C',
    'pink'    => '#DB2777',
    'cyan'    => '#0891B2',
    'yellow'  => '#D97706',
    'lime'    => '#65A30D',
    'fuchsia' => '#C026D3',
    'navy'    => '#1E3A8A',
    'maroon'  => '#9F1239',
    'gray'    => '#475569',
];

// Custom hex overrides the preset; otherwise fall back to the preset's hex (if known).
$theme_accent_hex = '';
if (!empty($config_theme_accent_custom) && preg_match('/^#[0-9A-Fa-f]{6}$/', $config_theme_accent_custom)) {
    $theme_accent_hex = strtoupper($config_theme_accent_custom);
} elseif (isset($theme_accent_presets[$config_theme])) {
    $theme_accent_hex = $theme_accent_presets[$config_theme];
}

// Effective dark mode: per-user preference wins; otherwise the company default applies.
$effective_theme_dark = $user_config_theme_dark ? 1 : $config_theme_dark_default;

?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="<?= $effective_theme_dark ? 'dark' : 'light' ?>" data-accent="<?= nullable_htmlentities($config_theme) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="x-ua-compatible" content="ie=edge">
    <meta name="robots" content="noindex">

    <title><?= $session_company_name; ?></title>

    <!-- Favicon -->
    <?php if(file_exists($_SERVER['DOCUMENT_ROOT'] . '/uploads/favicon.ico')) { ?>
        <link rel="icon" type="image/x-icon" href="/uploads/favicon.ico">
    <?php } ?>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="/plugins/fontawesome-free/css/all.min.css">

    <!-- Core stack: Bootstrap 5.3 + AdminLTE 4 -->
    <link rel="stylesheet" href="/plugins/bootstrap5/css/bootstrap.min.css">
    <link rel="stylesheet" href="/plugins/adminlte4/css/adminlte.min.css">

    <!-- Vanilla plugin styles (BS5 flavor) -->
    <link rel="stylesheet" href="/plugins/tom-select/css/tom-select.bootstrap5.min.css">
    <link rel="stylesheet" href="/plugins/tempus-dominus/css/tempus-dominus.min.css">
    <link rel="stylesheet" href="/plugins/simple-datatables/css/simple-datatables.css">
    <link rel="stylesheet" href="/plugins/toastr/toastr.min.css">
    <link rel="stylesheet" href="/plugins/intl-tel-input/css/intlTelInput.min.css">

    <!-- Theme: BS5 bridge (maps BS vars -> Alga tokens) THEN the custom theme -->
    <link rel="stylesheet" href="/css/itflow_bs5_bridge.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/css/itflow_bs5_bridge.css') ?>">
    <link rel="stylesheet" href="/css/itflow_custom.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/css/itflow_custom.css') ?>">
    <link rel="stylesheet" href="/css/itflow_design.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/css/itflow_design.css') ?>">

    <!-- Per-company appearance customizer: recolor the CSS-variable theme from the chosen accent.
         $theme_accent_hex / $effective_theme_dark were resolved above (before <html>). This block
         also pushes the accent straight into Bootstrap 5's own variables so BS5/AL4 components pick
         it up, and is emitted AFTER itflow_bs5_bridge.css so it wins. -->
    <?php if ($theme_accent_hex !== '' || !empty($config_theme_card_radius)): ?>
    <style nonce="<?php echo $csp_nonce; ?>">
    <?php if ($theme_accent_hex !== ''):
        $ar = hexdec(substr($theme_accent_hex, 1, 2));
        $ag = hexdec(substr($theme_accent_hex, 3, 2));
        $ab = hexdec(substr($theme_accent_hex, 5, 2));
        $darken  = function ($c) { return max(0, min(255, (int) round($c * 0.82))); };
        $lighten = function ($c) { return max(0, min(255, (int) round($c + (255 - $c) * 0.28))); };
        $hover_light = sprintf('#%02X%02X%02X', $darken($ar), $darken($ag), $darken($ab));
        $hover_dark  = sprintf('#%02X%02X%02X', $lighten($ar), $lighten($ag), $lighten($ab));
    ?>
    :root {
        --color-accent: <?php echo $theme_accent_hex; ?>;
        --color-accent-rgb: <?php echo "$ar, $ag, $ab"; ?>;
        --color-accent-hover: <?php echo $hover_light; ?>;
        --color-accent-soft: rgba(<?php echo "$ar, $ag, $ab"; ?>, .12);
        /* Bootstrap 5 accent bindings (exact hex, correct in both color modes) */
        --bs-primary: <?php echo $theme_accent_hex; ?>;
        --bs-primary-rgb: <?php echo "$ar, $ag, $ab"; ?>;
        --bs-link-color: <?php echo $theme_accent_hex; ?>;
        --bs-link-color-rgb: <?php echo "$ar, $ag, $ab"; ?>;
        --bs-link-hover-color: <?php echo $hover_light; ?>;
    }
    body.dark-mode {
        --color-accent: <?php echo $theme_accent_hex; ?>;
        --color-accent-rgb: <?php echo "$ar, $ag, $ab"; ?>;
        --color-accent-hover: <?php echo $hover_dark; ?>;
        --color-accent-soft: rgba(<?php echo "$ar, $ag, $ab"; ?>, .22);
    }
    /* Match bridge specificity so the accent survives the dark color mode */
    :root[data-bs-theme="dark"] {
        --bs-primary: <?php echo $theme_accent_hex; ?>;
        --bs-primary-rgb: <?php echo "$ar, $ag, $ab"; ?>;
        --bs-link-color: <?php echo $theme_accent_hex; ?>;
        --bs-link-color-rgb: <?php echo "$ar, $ag, $ab"; ?>;
        --bs-link-hover-color: <?php echo $hover_dark; ?>;
    }
    <?php endif; ?>
    <?php
    if (!empty($config_theme_card_radius)):
        $radius_css = preg_replace('/[^0-9a-z%.]/i', '', $config_theme_card_radius);
        if ($radius_css !== ''):
    ?>
    :root { --card-radius: <?php echo $radius_css; ?>; }
    <?php endif; endif; ?>
    </style>
    <?php endif; ?>

    <!-- Scripts: jQuery kept as a coexistence shim for un-ported inline $() calls -->
    <script src="/plugins/jquery/jquery.min.js"></script>
    <script src="/plugins/toastr/toastr.min.js"></script>
</head>
<body class="
    layout-fixed sidebar-expand-lg text-sm
    accent-<?php echo nullable_htmlentities($config_theme); ?>
    <?php if ($effective_theme_dark) echo 'dark-mode'; ?>
">
    <!-- AdminLTE 4 layout: app-wrapper > app-header + app-sidebar + app-main -->
    <div class="app-wrapper">

