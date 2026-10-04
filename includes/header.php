<?php

    // Calculate Execution time start
    // uncomment for test
    // $time_start = microtime(true);

// Per-request nonce so inline <script> blocks (used extensively throughout this
// app) can execute under a strict script-src without falling back to
// 'unsafe-inline', which would undo CSP's actual XSS protection.
$csp_nonce = base64_encode(random_bytes(16));

header("X-Frame-Options: DENY");
$csp_script_src = "'self' 'nonce-$csp_nonce' https://static.cloudflareinsights.com";
$csp_frame_src  = '';
// Training preview only (agent/training_preview.php sets this BEFORE inc_all.php): the YouTube/Vimeo player
// APIs and embed frames. Path-scoped sources. 'self' is kept because declaring frame-src stops the
// default-src fallback for frames.
if (!empty($page_csp_external_video)) {
    $csp_script_src .= " https://www.youtube.com/iframe_api https://www.youtube.com/s/player/ https://player.vimeo.com/api/player.js";
    $csp_frame_src   = " frame-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com;";
}
header("Content-Security-Policy: default-src 'self'; script-src $csp_script_src; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https://*.foleyit.com https://tile.openstreetmap.org https://*.tile.openstreetmap.org; connect-src 'self' https://cloudflareinsights.com;$csp_frame_src");
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

// Effective dark mode: per-user preference wins (1 dark, 2 light); 0 follows the company default.
$effective_theme_dark = $user_config_theme_dark === 2 ? 0 : ($user_config_theme_dark ? 1 : $config_theme_dark_default);

// ----- Shell helper: custom-link icons -----
// Custom links (admin/custom_link.php) store a BARE Font Awesome 5 name - "handshake",
// "question-circle" - and the icon field is optional, so a link saved without one used
// to emit class="fas fa-": a 0x0 <i> that punched a hole in the sidebar's icon column
// and left an orphan chevron behind in the folded 4rem rail. Normalised once here for
// the three places that render those links (includes/top_nav.php,
// agent/includes/side_nav.php, admin/includes/side_nav.php) so they cannot drift:
// tolerate a name typed WITH its "fa-" / "fas fa-" prefix, drop anything that is not
// safe in a class attribute, and fall back to fa-link so the row always has an icon.
// Takes the RAW column value - it does its own escaping by construction.
function itflow_nav_icon_class($icon, $fallback = 'fa-link')
{
    $icon = strtolower(trim((string) $icon));
    $icon = preg_replace('/^fa[bdlrs]?\s+/', '', $icon);      // "fas fa-cog" -> "fa-cog"
    $icon = preg_replace('/^fa-/', '', $icon);                // "fa-cog"     -> "cog"
    $icon = preg_replace('/[^a-z0-9-]/', '', $icon);          // class-attribute safe
    return $icon === '' ? $fallback : 'fa-' . $icon;
}

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
    <?php } else { /* no uploaded favicon: the product icon (includes/branding.php) */ ?>
        <link rel="icon" href="/favicon.ico" sizes="32x32">
        <link rel="icon" href="<?= htmlspecialchars(APP_FAVICON_URL) ?>" type="image/svg+xml">
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
    <link rel="stylesheet" href="/css/file_upload.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/css/file_upload.css') ?>">

    <!-- Motion layer. Owns every animation in the app, including the single global
         prefers-reduced-motion guard, so no later rule can forget it. Must sit AFTER
         itflow_design.css (it reads --if-* tokens and retunes Tabler's own .card /
         .nav-link / .modal transitions, winning on cascade order) and BEFORE
         itflow.compat-color.css / itflow_metrics.css / itflow.bind-tabler.css. -->
    <link rel="stylesheet" href="/css/itflow_motion.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/css/itflow_motion.css') ?>">

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

    <!-- Interactive KB blocks (checklist / guided steps / tabs / accordion /
         decision tree / copy). Scoped entirely under .ikb*, so it cannot reach a
         page that renders no blocks.

         LOADED UNCONDITIONALLY HERE FOR A DIFFERENT REASON THAN THE PORTAL'S.
         client/includes/header.php's matching comment cites its header being
         emitted from client/includes/inc_all.php before client/kb_article.php
         can set a flag - a real constraint THERE, because the portal's header
         truly runs before that page's own code does. It does not apply to this
         shell: an agent-side page could set a flag before includes/header.php
         runs (includes/footer.php's versioned first-party JS loop already shows
         the pattern for scripts). This file is unconditional anyway because KB
         article content shows up in more than one place under this shell - the
         article page, the version-history modal, and any future page that
         embeds a snippet of one - and every one of them would have to remember
         to set that flag or silently lose the styling with no error. Skipping
         that bookkeeping costs this file even on a page with no KB content:
         15.3 KB raw / 4.9 KB gzipped, measured against the file on disk.
         Last of the first-party sheets: it consumes the --if-* tokens the
         design layer declares and overrides no framework rule, every class
         name in it being novel. -->
    <link rel="stylesheet" href="/css/itflow_kb.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/css/itflow_kb.css') ?>">

    <!-- Per-page first-party sheets (Training pages set $page_extra_css BEFORE inc_all.php).
         Only /css/*.css files that exist are emitted, versioned like the sheets above. They
         load after the design layer, so they can consume the --if-* tokens, and before the
         accent block below, so a custom accent still wins. -->
    <?php foreach ((isset($page_extra_css) && is_array($page_extra_css) ? $page_extra_css : []) as $__css) {
        if (is_string($__css) && preg_match('#^/css/[a-z0-9_.-]+\.css$#', $__css) && is_file($_SERVER['DOCUMENT_ROOT'] . $__css)) {
            echo '<link rel="stylesheet" href="' . $__css . '?v=' . filemtime($_SERVER['DOCUMENT_ROOT'] . $__css) . '">' . "\n";
        }
    } ?>

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

    <!-- Sidebar scroll affordance. The sidebar is position:fixed and #sidebar-menu is
         its own scroll container, so a nav taller than the viewport (admin at 1100px:
         1182px of items in a 1061px column) is cut flush at the column's bottom edge
         with nothing to say so - scrolling the WINDOW moves none of it, and overlay
         scrollbars, which is what current Chromium draws here, paint nothing at rest.
         js/shell.js owns .has-scroll-start / .has-scroll-end and also scrolls the
         current page's own entry into view on first paint; these two marks are the
         visible half of that and are meaningless without it, which is why they live
         with the rest of the shell rather than in a stylesheet.

         Desktop only: below Tabler's navbar-expand-lg breakpoint the aside is not a
         fixed full-height column and has no cut edge to mark. The gradient hangs off
         the aside itself (already a containing block at position:fixed) so it adds no
         new one - putting it on .container-fluid would have re-based the folded rail's
         absolutely positioned flyouts. -->
    <style nonce="<?php echo $csp_nonce; ?>">
    @media (min-width: 992px) {
        /* More nav below the fold: dissolve the last row into the sidebar. */
        aside.navbar-vertical::after {
            content: "";
            position: absolute;
            inset-inline: 0;
            bottom: 0;
            height: 3.5rem;
            z-index: 3;
            pointer-events: none;
            opacity: 0;
            transition: opacity .15s ease-out;
            background: linear-gradient(to top, var(--tblr-bg-surface) 35%, transparent);
        }
        aside.navbar-vertical.has-scroll-end::after { opacity: 1; }

        /* More nav above the fold: lift the brand onto a shadow so the list reads as
           running underneath it instead of starting mid-item. */
        aside.navbar-vertical > .container-fluid > .navbar-brand {
            position: relative;
            z-index: 1;
            transition: box-shadow .15s ease-out;
        }
        aside.navbar-vertical.has-scroll-start > .container-fluid > .navbar-brand {
            box-shadow: 0 .75rem .75rem -.75rem rgba(0, 0, 0, .45);
        }
    }
    </style>

    <!-- Scripts: jQuery kept as a coexistence shim for un-ported inline $() calls -->
    <script src="/plugins/jquery/jquery.min.js"></script>
    <script src="/plugins/toastr/toastr.min.js"></script>
    <!-- Toast options - the single source of truth, applied on every page.

         These used to live in includes/inc_alert_feedback.php inside
         `if (!empty($_SESSION['alert_message']))`, so they applied only on a request
         that already carried a flash message; every other toast (the AJAX ones,
         agent/js/project_kanban.js) ran with toastr's stock defaults.

         The ENTRANCE is toastr's own fadeIn and css/itflow_motion.css deliberately
         does not animate #toast-container - toastr stamps an inline opacity on the
         node and animates it in JS, and a CSS animation cannot win that cleanly
         (it outranks the inline style only while it runs, then snaps back to
         whatever jQuery left). That was measured twice, both ways, and both blinked.

         Only the EXIT is retuned: 1000ms was a full second of a dismissed message
         still covering the page, and 160ms matches --if-dur-ui. It is opacity-only,
         so it stays honest under reduced motion, which a media query cannot reach
         inside a JS animation.

         It lives HERE, not in js/app.js, because app.js is deferred and the flash
         toast is emitted by the inc_all_*.php bootstraps near the top of the body -
         a deferred config would be set after the toast had already been raised. -->
    <script nonce="<?php echo $csp_nonce; ?>">
    if (window.toastr) {
        toastr.options = {
            "closeButton": false, "debug": false, "newestOnTop": false,
            "progressBar": false, "positionClass": "toast-top-center",
            "preventDuplicates": false, "onclick": null,
            "showDuration": "300", "showEasing": "swing",  "showMethod": "fadeIn",
            "hideDuration": "160", "hideEasing": "linear", "hideMethod": "fadeOut",
            "timeOut": "5000", "extendedTimeOut": "1000"
        };
    }
    </script>
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
