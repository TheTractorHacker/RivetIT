<?php
/*
 * Guest Portal
 * HTML Header
 *
 * TABLER SHELL - part 1 of 3 for the guest portal. Opens ONE wrapper
 * (<div class="page">). Closes nothing.
 *
 *   guest/includes/guest_header.php  opens  <html> <body> <div class="page">
 *   guest/includes/inc_wrapper.php   opens  .page-wrapper > .page-body > .container
 *   includes/footer.php  (SHARED)    closes four divs, then </body></html>
 *
 * *** THE NESTING-DEPTH INVARIANT ***
 * The guest portal does NOT have its own footer - it shares the SAME
 * includes/footer.php as the 209-page agent/admin surface. That footer closes
 * exactly FOUR structural levels below <body>. The agent shell reaches four as
 *   .page / .page-wrapper / .page-body / .container-xl
 * and the guest shell reaches four as
 *   .page / .page-wrapper / .page-body / .container
 * Different class on the innermost div, SAME depth - and depth is the only
 * thing the shared footer can see. Adding or removing a level in this file (or
 * in guest/includes/inc_wrapper.php) without changing includes/footer.php would
 * break the agent surface too, and nothing would error - the layout would just
 * silently go wrong on every page.
 *
 * (Previously this file opened AdminLTE 4's .app-wrapper and the wrapper opened
 *  .app-main > .app-content > .container. Same depth, different names.)
 */
?>
<!DOCTYPE html>
<?php
/* ---------------------------------------------------------------------------
   <html> attributes.

   data-bs-theme="light"   Hard-coded, unchanged. Guest pages are unauthenticated
                           magic-link surfaces shown to external recipients
                           (invoice/quote/ticket views, signature capture); they
                           deliberately ignore the company dark-mode default so
                           the rendered/printed document always looks the same.
                           This is now load-bearing rather than cosmetic: Tabler
                           keys its ENTIRE dark palette off
                           html[data-bs-theme="dark"], so pinning it to "light"
                           here is what keeps these pages light. The <body> below
                           correspondingly never gets .dark-mode.

   data-accent             Per-company accent name. Same hook the other two
                           shells expose; harmless when unset.

   Deliberately NOT set (see includes/header.php for the full rationale):
     data-bs-layout="fluid"            guest content keeps the centred, capped
                                       .container width it has always had.
     data-bs-navbar-position="vertical" guest pages have no sidebar and no
                                       navbar at all, so the layout switcher has
                                       nothing to arbitrate.
   --------------------------------------------------------------------------- */
?>
<html lang="en" data-bs-theme="light" data-accent="<?= nullable_htmlentities($config_theme ?? '') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="x-ua-compatible" content="ie=edge">
    <meta name="robots" content="noindex">

    <title><?php echo nullable_htmlentities($session_company_name); ?></title>

    <!-- 
    Favicon
    If Fav Icon exists else use the default one 
    -->
    <?php if(file_exists($_SERVER['DOCUMENT_ROOT'] . '/uploads/favicon.ico')) { ?>
        <link rel="icon" href="/uploads/favicon.ico">
    <?php } ?>

    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="/plugins/fontawesome-free/css/all.min.css">

    <!-- Core stack: Tabler 1.5 (vendored, self-contained - zero @font-face, all
         url() refs are inline data: SVGs, which matters here because the guest
         CSP is default-src 'self' with img-src 'self' data:).
         Tabler bundles its own Bootstrap 5 build, so
         plugins/bootstrap5/css/bootstrap.min.css and
         plugins/adminlte4/css/adminlte.min.css are both gone.
         bootstrap.bundle.min.js is still loaded by the shared includes/footer.php;
         plugins/tabler/js/tabler.min.js is NOT shipped (it exports window.tabler
         and would double-wire the data-bs-toggle data-api). CSS only. -->
    <link rel="stylesheet" href="/plugins/tabler/css/tabler.min.css">

    <!-- Toastr (used by inc_alert_feedback) -->
    <link rel="stylesheet" href="/plugins/toastr/toastr.min.css">

    <!-- Theme: BS5 bridge (self-hosted components + app shims) THEN the custom
         theme THEN the design layer. Deliberately still the REDUCED stylesheet
         set - guest pages load none of the tom-select / tempus-dominus /
         simple-datatables / intl-tel-input CSS the agent shell pulls in. -->
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

    <!-- Token seam: maps this app's --if-* / --color-* tokens onto Tabler's
         --tblr-*. MUST load after the design layer so the mappings win. -->
    <link rel="stylesheet" href="/css/itflow.bind-tabler.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/css/itflow.bind-tabler.css') ?>">

    <!-- Scripts: jQuery kept as a coexistence shim; toastr for alert feedback -->
    <script src="/plugins/jquery/jquery.min.js"></script>
    <script src="/plugins/toastr/toastr.min.js"></script>

</head>
<?php
/* ---------------------------------------------------------------------------
   BODY CLASSES

   Dropped:
     layout-top-nav   AdminLTE 4 layout modifier with no first-party consumer.
                      Nothing in css/ or js/ reads it; it is dead with AL4 gone.

   Kept:
     accent-<name>    per-company accent hook, mirroring includes/header.php.

   NOT set (and must not be):
     dark-mode        guest pages are pinned light - see data-bs-theme above.
   --------------------------------------------------------------------------- */
?>
<body class="accent-<?php echo nullable_htmlentities($config_theme ?? ''); ?>">
    <div class="page">
