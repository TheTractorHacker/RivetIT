<?php

    // Calculate Execution time start
    // uncomment for test
    // $time_start = microtime(true);

// Per-request nonce so inline <script> blocks (used extensively throughout this
// app) can execute under a strict script-src without falling back to
// 'unsafe-inline', which would undo CSP's actual XSS protection.
$csp_nonce = base64_encode(random_bytes(16));

header("X-Frame-Options: DENY");
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$csp_nonce' https://static.cloudflareinsights.com; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https://*.foleyit.com https://tile.openstreetmap.org https://*.tile.openstreetmap.org; connect-src 'self' https://cloudflareinsights.com");
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
<?php
/* ---------------------------------------------------------------------------
   <html> attributes, and why each one is here.

   data-bs-theme            Bootstrap 5 / Tabler colour mode. Tabler keys its
                            entire dark palette off html[data-bs-theme="dark"],
                            and css/itflow_design.css already declares its dark
                            --if-* tokens on :root[data-bs-theme="dark"], so this
                            single root-level trigger drives both. (body.dark-mode
                            below is the app's older, non-equivalent trigger; it
                            still drives --color-* in itflow_custom.css and is
                            still read by the per-company accent block further
                            down, so it is kept.)

   data-accent              Per-company accent name. Pre-existing hook.

   data-bs-layout="fluid"   Tabler ships:
                              html[data-bs-layout=fluid] .container,
                              html[data-bs-layout=fluid] [class^=container-],
                              html[data-bs-layout=fluid] [class*=" container-"]
                                  { max-width: 100% }
                            The AdminLTE shell wrapped page content in
                            .container-fluid (full bleed). The Tabler shell wraps
                            it in .container-xl, which is capped at 1140/1320px.
                            For a dense ops tool full of wide tables that cap is a
                            regression, so this attribute restores exactly the old
                            full-bleed behaviour using Tabler's own supported
                            switch instead of a custom max-width override.
                            Remove this attribute to get the centred, capped
                            Tabler reading width instead - nothing else has to
                            change.

   data-bs-navbar-position  Tabler 1.5 ships a layout switcher implemented purely
     ="vertical"            in CSS:
                              html:not([data-bs-navbar-position=vertical])
                                .page:has(> [class*=navbar-expand]:not(.navbar-vertical))
                                > .navbar-vertical            { display: none }
                              html[data-bs-navbar-position=vertical]
                                .page:has(> .navbar-vertical)
                                > [class*=navbar-expand]:not(.navbar-vertical)
                                                              { display: none }
                            i.e. if .page has BOTH a vertical sidebar and a
                            horizontal navbar as DIRECT children, Tabler hides one
                            of them. This app needs both, so the horizontal top
                            navbar is deliberately rendered INSIDE .page-wrapper
                            (see includes/top_nav.php + includes/inc_wrapper.php)
                            where neither rule can reach it. Declaring the layout
                            as vertical here is belt-and-braces: if markup ever
                            drifts and a horizontal navbar does become a direct
                            child of .page, the sidebar - the app's only complete
                            navigation - survives rather than vanishing.
   --------------------------------------------------------------------------- */
?>
<html lang="en"
      data-bs-theme="<?= $effective_theme_dark ? 'dark' : 'light' ?>"
      data-accent="<?= nullable_htmlentities($config_theme) ?>"
      data-bs-layout="fluid"
      data-bs-navbar-position="vertical">
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

    <!-- Core stack: Tabler 1.5 (vendored, self-contained - zero @font-face, all url()
         refs are inline data: SVGs). Tabler bundles its own Bootstrap 5 build, so
         plugins/bootstrap5/css/bootstrap.min.css and plugins/adminlte4/css/adminlte.min.css
         are both gone. bootstrap.bundle.min.js is deliberately KEPT (see footer.php):
         only the CSS was replaced. -->
    <link rel="stylesheet" href="/plugins/tabler/css/tabler.min.css">

    <!-- Vanilla plugin styles (BS5 flavor) -->
    <link rel="stylesheet" href="/plugins/tom-select/css/tom-select.bootstrap5.min.css">
    <link rel="stylesheet" href="/plugins/tempus-dominus/css/tempus-dominus.min.css">
    <link rel="stylesheet" href="/plugins/simple-datatables/css/simple-datatables.css">
    <link rel="stylesheet" href="/plugins/toastr/toastr.min.css">
    <link rel="stylesheet" href="/plugins/intl-tel-input/css/intlTelInput.min.css">

    <!-- Theme: BS5 bridge (self-hosted AdminLTE components + app shims) THEN the
         custom theme THEN the design layer. -->
    <!-- Compatibility shims. These were split out of css/itflow_bs5_bridge.css and
         MUST be linked: 55 selectors the app still emits live only in these files
         now, so without them .info-box, .small-box, .card-tools, .form-group,
         .form-row, .input-group-prepend/-append, .btn-block and friends have no
         styling at all under Tabler. Both load anywhere after tabler.min.css, and
         both must precede css/itflow.bind-tabler.css (shim-adminlte's .small-box
         .icon rule depends on winning against bind-tabler's .icon reset). -->
    <link rel="stylesheet" href="/css/itflow.shim-bs4.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/css/itflow.shim-bs4.css') ?>">
    <link rel="stylesheet" href="/css/itflow.shim-adminlte.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/css/itflow.shim-adminlte.css') ?>">

    <link rel="stylesheet" href="/css/itflow_bs5_bridge.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/css/itflow_bs5_bridge.css') ?>">
    <link rel="stylesheet" href="/css/itflow_custom.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/css/itflow_custom.css') ?>">
    <link rel="stylesheet" href="/css/itflow_design.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/css/itflow_design.css') ?>">

    <!-- --color-* -> --if-* alias. MUST come after BOTH itflow_custom.css (which
         declares --color-*) and itflow_design.css (which declares --if-*): it is a
         pure alias layer and linked any earlier it silently does nothing. Keeps the
         ~500 existing var(--color-...) reads resolving to one source of truth, and
         fixes card headers rendering a different grey than their own card body in
         dark mode. -->
    <link rel="stylesheet" href="/css/itflow.compat-color.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/css/itflow.compat-color.css') ?>">

    <!-- Device metrics ("Metrics", never telemetry): styles for the Performance tab on
         agent/asset_details.php. Global, but every rule is scoped under .ifm-* so it
         cannot reach a page that does not render the tab. -->
    <link rel="stylesheet" href="/css/itflow_metrics.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/css/itflow_metrics.css') ?>">

    <!-- Token seam: maps this app's --if-* / --color-* tokens onto Tabler's --tblr-*.
         MUST load after the design layer (so the mappings win) and BEFORE the
         per-company accent block below (so a custom accent still overrides them). -->
    <link rel="stylesheet" href="/css/itflow.bind-tabler.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/css/itflow.bind-tabler.css') ?>">

    <!-- Per-company appearance customizer: recolor the CSS-variable theme from the chosen accent.
         $theme_accent_hex / $effective_theme_dark were resolved above (before <html>). This block
         also pushes the accent straight into Bootstrap 5's own variables; css/itflow.bind-tabler.css
         re-exports those onto --tblr-* so Tabler components pick the accent up too. Emitted LAST
         so it wins. -->
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
<?php
/* ---------------------------------------------------------------------------
   BODY CLASSES

   Dropped (all AdminLTE 4 only, and all now dead):
     layout-fixed        - AL4 grid layout modifier, no first-party consumer.
     sidebar-expand-lg   - AL4 PushMenu read its responsive breakpoint out of
                           getComputedStyle(body,'::before').content on this class,
                           which only AL4's own CSS supplied. Both the CSS and the
                           JS are gone in the same change, as they must be.
     text-sm             - AL4 set 0.875rem here; Tabler's base font-size is
                           already 0.875rem, so this is a no-op.

   Kept (both still have live first-party consumers):
     accent-<name>       - per-company accent hook.
     dark-mode           - itflow_custom.css declares the dark --color-* token set
                           on body.dark-mode, and the per-company accent block
                           above targets body.dark-mode for its dark hover/soft
                           variants. Removing it would silently break the accent
                           in dark mode.
   --------------------------------------------------------------------------- */
?>
<body class="accent-<?php echo nullable_htmlentities($config_theme); ?><?php if ($effective_theme_dark) echo ' dark-mode'; ?>">
    <?php
    /* -----------------------------------------------------------------------
       TABLER SHELL - part 1 of 3. Opens ONE wrapper (.page). Closes nothing.

         includes/header.php      opens  <html> <body> <div class="page">
         includes/inc_wrapper.php opens  .page-wrapper > .page-body > .container-xl
         includes/footer.php      closes container-xl / page-body / page-wrapper /
                                  page, then </body></html>

       Four structural levels below <body>, exactly as the AdminLTE shell had
       (app-wrapper / app-main / app-content / container-fluid). Do not add or
       remove a level here without changing footer.php AND guest/includes/inc_wrapper.php
       in the same commit - 209 pages require includes/footer.php and none of them
       would error, they would just render wrong.

       .page's direct children, in DOM order, are:
         1. the vertical sidebar  <aside class="navbar navbar-vertical navbar-expand-lg">
            emitted by whichever side_nav include the page family uses
         2. <div class="page-wrapper">
       The horizontal top navbar is NOT a direct child of .page - see the
       data-bs-navbar-position note above and includes/top_nav.php.
       ----------------------------------------------------------------------- */
    ?>
    <div class="page">
