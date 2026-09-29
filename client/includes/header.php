<?php
/*
 * Client Portal
 * HTML Header
 *
 * TABLER SHELL - part 1 of 2 for the client portal.
 *
 * The client portal does NOT share /includes/footer.php; it has its own
 * client/includes/footer.php, so this header + that footer are a closed pair
 * and the two must be edited together.
 *
 *   client/includes/header.php  opens  <html> <body>
 *                                      <div class="page">
 *                                        (navbar - internally balanced)
 *                                        <div class="page-wrapper">
 *                                          <div class="page-body">
 *                                            <div class="container">
 *   client/includes/footer.php  closes  container / page-body / page-wrapper /
 *                                       page, then </body></html>
 *
 * Four structural levels below <body>, i.e. exactly the same depth the
 * agent/admin and guest shells use, so the mental model is identical even
 * though the closing file is different.
 *
 * TWO DEFECTS FIXED HERE (both pre-existing):
 *   1. This file used to emit NO <body> tag at all and the client footer never
 *      closed <body> or <html>. The document was invalid, and Tabler's .page
 *      cannot lay out correctly without a real body element.
 *   2. Because there was no body tag the portal never received the .dark-mode
 *      class, so css/itflow_custom.css's dark --color-* token set never applied
 *      and the portal's dark mode was broken. The body tag below carries it.
 *      Tabler's own dark palette keys off html[data-bs-theme="dark"], which this
 *      file already server-rendered, so both triggers now agree.
 */

header("X-Frame-Options: DENY"); // Legacy
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");

/* ═════════════════════════════════════════════════════════════════════════════
   PORTAL PREVIEW BANNER - state resolution
   ─────────────────────────────────────────────────────────────────────────────
   An admin agent can open a READ-ONLY preview of a department's portal. This
   file is included by client/includes/inc_all.php, which every portal page
   pulls in, so putting the banner here is what makes it appear on ALL of them
   instead of on whichever ones someone remembered.

   The preview's own state lives in client/includes/portal_preview.php and is
   re-proved against the database on every request. This file only READS it -
   it must never decide, or appear to decide, whether a preview is legitimate.

   NOTE ON $portal_preview* NAMES: client/includes/check_login.php already
   publishes $portal_preview, $portal_preview_active, $portal_preview_client_id,
   $portal_preview_agent_user_id and $portal_preview_agent_name into this scope.
   Nothing here reassigns any of them - the banner keeps its own
   $portal_preview_banner so a rename or a re-order on either side can never
   silently flip the other lane's answer.

   NOTE ON STYLING, because it constrains the markup further down: 19 of the
   portal's pages send `Content-Security-Policy: default-src 'self'` from their
   own line 7, before this file runs. Under CSP3 that blocks <style> blocks AND
   style="" attributes alike, and the portal never defines $csp_nonce (only the
   agent shell does). So the banner is built entirely from classes that already
   exist in the vendored Tabler / Font Awesome CSS: no inline style, nothing to
   nonce, and no CSP relaxation anywhere. That inline styles really are dead on
   those pages is not a guess - client/kb_articles.php:61 already ships a
   style="" that the browser drops.
   ═════════════════════════════════════════════════════════════════════════════ */

/*
 * $portal_preview_banner is the context array the banner renders from, or null
 * when this is an ordinary portal request:
 *
 *   ['client_id','client_name','agent_user_id','agent_name','started_at','exit_url']
 *
 * Three sources, in cost order:
 *
 *   1. $portal_preview, already resolved by check_login.php THIS request. Reuse
 *      it rather than re-running portalPreviewResolve(), which is deliberately
 *      not memoised and would repeat its database checks.
 *   2. portalPreviewContext(), if this file is ever reached without that.
 *   3. A fail-CLOSED stub. If the preview namespace is populated but the helper
 *      is unavailable, we cannot name the department - but rendering a portal
 *      that LOOKS like the real thing is the one outcome that must not happen,
 *      so the banner still goes up, saying exactly that. This branch can only
 *      ever ADD a warning; it can never suppress one, and it grants nothing.
 */
$portal_preview_banner = null;

if (isset($portal_preview) && is_array($portal_preview)) {
    $portal_preview_banner = $portal_preview;
} elseif (function_exists('portalPreviewContext')) {
    $portal_preview_banner = portalPreviewContext();
} elseif (!empty($_SESSION['portal_preview'])) {
    $portal_preview_banner = [
        'client_name' => '',
        'agent_name'  => '',
        // Spelled literally: the constant that names this route lives in the
        // very file whose absence put us in this branch.
        'exit_url'    => '/client/index.php?exit_portal_preview=1',
    ];
}

// Escape once, here, so the markup below stays readable. client_name and
// agent_name are raw database values by documented contract.
$portal_preview_dept  = '';
$portal_preview_agent = '';
$portal_preview_exit  = '';

if ($portal_preview_banner !== null) {
    $portal_preview_dept = trim((string) ($portal_preview_banner['client_name'] ?? '')) !== ''
        ? nullable_htmlentities($portal_preview_banner['client_name'])
        : 'an unidentified department';
    $portal_preview_agent = nullable_htmlentities((string) ($portal_preview_banner['agent_name'] ?? ''));
    $portal_preview_exit  = nullable_htmlentities(
        (string) ($portal_preview_banner['exit_url'] ?? '/client/index.php?exit_portal_preview=1')
    );
}

/* ---------------------------------------------------------------------------
   SHELL IDENTITY: which department, and which organisation.

   The portal is scoped to exactly ONE department for the whole session - every
   list it renders is filtered `WHERE *_client_id = $session_client_id` - but
   until now it printed only the COMPANY name in the tab title, the navbar brand
   and the welcome banner, and the department name appeared in exactly one place
   in the entire portal (client/profile.php:18). All 15 live departments produced
   a byte-identical tab title, so an admin previewing several in a row could not
   tell the tabs apart.

   Both names are kept, with distinct jobs:
     department = WHERE YOU ARE   -> tab title, navbar brand line 1, welcome banner
     company    = WHO RUNS THIS   -> logo, navbar brand line 2, footer
   Swapping one for the other would just move the lie, so neither is dropped.

   $session_client_name is set at client/includes/check_login.php:315 from
   `$client['client_name']` with NO null guard - if the clients row is deleted
   mid-session $client is false and the value is null. Every use below therefore
   goes through $portal_dept_html and falls back to the old company-only strings
   rather than rendering an empty brand. (The missing guard itself is in
   check_login.php, which this change does not own.)

   Escaped once here so the markup stays readable, matching the
   $portal_preview_* block directly above. --------------------------------- */
$portal_dept_html = trim((string) ($session_client_name ?? '')) !== ''
    ? nullable_htmlentities($session_client_name)
    : '';
$portal_org_html  = nullable_htmlentities((string) ($session_company_name ?? ''));
?>

<!DOCTYPE html>
<?php
/* ---------------------------------------------------------------------------
   <html> attributes.

   data-bs-theme   Bootstrap 5 / Tabler colour mode. Tabler keys its entire dark
                   palette off html[data-bs-theme="dark"], and
                   css/itflow_design.css declares its dark --if-* tokens on
                   :root[data-bs-theme="dark"], so this one root-level trigger
                   drives both. Unchanged from before.

   data-accent     Per-company accent name. Same hook the agent shell exposes.

   Deliberately NOT set here (unlike includes/header.php):

     data-bs-layout="fluid"          The agent app is a dense ops tool and wants
                                     full-bleed width. The portal has always used
                                     a centred, capped .container (its navbar uses
                                     one too) and keeps that reading width.

     data-bs-navbar-position="vertical"
                                     That attribute tells Tabler "this page's
                                     navigation is the vertical sidebar", which
                                     makes Tabler hide any horizontal navbar that
                                     is a direct child of .page. The portal's only
                                     navigation IS a horizontal navbar and it has
                                     no sidebar at all, so setting it would hide
                                     the portal's entire nav. Left unset, the
                                     converse Tabler rule
                                       html:not([data-bs-navbar-position=vertical])
                                         .page:has(> [class*=navbar-expand]:not(.navbar-vertical))
                                         > .navbar-vertical { display:none }
                                     only ever hides .navbar-vertical elements,
                                     of which this page has none. Safe.
   --------------------------------------------------------------------------- */
?>
<html lang="en"
      data-bs-theme="<?= (!empty($config_theme_dark_default)) ? 'dark' : 'light' ?>"
      data-accent="<?= nullable_htmlentities($config_theme ?? '') ?>">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <!-- Tab title is prefixed during a preview so a tab left open in the
         background is still identifiable as one, not as the real portal.

         DEPARTMENT FIRST, company last. A browser tab truncates from the RIGHT,
         so whatever distinguishes one tab from another has to be leftmost: with
         the company name in front, all 15 departments rendered the identical
         string "[PREVIEW] Midwest Automation & Custom Fabrication | Department
         Portal" and a previewing admin with several tabs open could not tell
         which was which. Reversed, the widest live case measured 90 chars
         ("[PREVIEW] Shipping & Receiving Department Portal | Midwest Automation
         & Custom Fabrication") and the narrowest 72 (IT's) - and both are
         distinguishable inside the ~25 characters a tab actually shows, which
         is the only length that matters here.

         The old company-only string is the fallback, so a portal whose clients
         row has gone missing still renders a sane title rather than a stray
         leading space. -->
    <title><?php if ($portal_preview_banner !== null) echo '[PREVIEW] '; ?><?php
        echo $portal_dept_html !== ''
            ? "$portal_dept_html Department Portal | $portal_org_html"
            : "$portal_org_html | Department Portal";
    ?></title>

    <!-- Tell the browser to be responsive to screen width -->
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">

    <!-- Favicon: If Fav Icon exists, else use the default one -->
    <?php if(file_exists($_SERVER['DOCUMENT_ROOT'] . '/uploads/favicon.ico')) { ?>
        <link rel="icon" href="/uploads/favicon.ico">
    <?php } else { /* no uploaded favicon: the product icon (includes/branding.php) */ ?>
        <link rel="icon" href="/favicon.ico" sizes="32x32">
        <link rel="icon" href="<?= htmlspecialchars(APP_FAVICON_URL) ?>" type="image/svg+xml">
    <?php } ?>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="/plugins/fontawesome-free/css/all.min.css">

    <!-- Core stack: Tabler 1.5 (vendored, self-contained). Tabler bundles its own
         Bootstrap 5 build, so plugins/bootstrap5/css/bootstrap.min.css and
         plugins/adminlte4/css/adminlte.min.css are both gone from this page.
         bootstrap.bundle.min.js is deliberately KEPT in the footer - only the CSS
         was replaced; plugins/tabler/js/tabler.min.js is NOT shipped because it
         exports window.tabler and would double-wire the data-bs-toggle data-api.
         (This portal never used a single AdminLTE class, so nothing else needed
         to change to drop adminlte.min.css.) -->
    <link rel="stylesheet" href="/plugins/tabler/css/tabler.min.css">

    <!-- Theme: BS5 bridge (self-hosted components + app shims) THEN the custom
         theme THEN the design layer. -->
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

    <!-- Token seam: maps this app's --if-* / --color-* tokens onto Tabler's
         --tblr-*. MUST load after the design layer so the mappings win. -->
    <link rel="stylesheet" href="/css/itflow.bind-tabler.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/css/itflow.bind-tabler.css') ?>">

    <!-- Interactive KB blocks (checklist / guided steps / tabs / accordion /
         decision tree / copy). Scoped entirely under .ikb*, so it cannot reach a
         page that renders no blocks; loaded unconditionally because the portal's
         header is emitted from client/includes/inc_all.php before
         client/kb_article.php could set a flag, and keeping the two shells
         identical on this point is worth 15.3 KB raw / 4.9 KB gzipped (measured
         against the file on disk). Last of the first-party sheets: it consumes
         the --if-* tokens the design layer declares and overrides no framework
         rule, every class name in it being novel. -->
    <link rel="stylesheet" href="/css/itflow_kb.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/css/itflow_kb.css') ?>">

    <!-- Company appearance (Admin > Appearance / Theme): the same accent + card radius the agent shell and the
         kiosk use. A stylesheet, not an inline <style>, because portal pages send default-src 'self'. Sits after
         the framework/token sheets so it wins, and before the portal layer that consumes --color-accent. -->
    <?php
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/theme_accent.php';
    $portal_accent_hex = itflow_theme_accent_hex($config_theme ?? '', $config_theme_accent_custom ?? null);
    $portal_radius = itflow_theme_radius($config_theme_card_radius ?? '');
    if ($portal_accent_hex !== '' || $portal_radius !== '') { ?>
    <link rel="stylesheet" href="/client/portal_theme.css.php?a=<?= ltrim($portal_accent_hex, '#') ?>&amp;r=<?= rawurlencode($portal_radius) ?>">
    <?php } ?>

    <!-- Portal UI layer (navbar, hero, stat cards, tiles, profile). Portal-only
         .portal-* selectors, so it goes last and needs no !important. -->
    <link rel="stylesheet" href="/css/itflow_portal.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/css/itflow_portal.css') ?>">

    <!-- Saved light/dark choice, applied before first paint. Synchronous on purpose. -->
    <script src="/js/portal_theme.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/js/portal_theme.js') ?>"></script>

</head>
<?php
/* ---------------------------------------------------------------------------
   BODY

   This element did not exist before this migration - see defect (2) at the top
   of the file. Its classes mirror includes/header.php exactly:

     accent-<name>  per-company accent hook.
     dark-mode      css/itflow_custom.css declares the dark --color-* token set
                    on body.dark-mode. Without it the portal rendered light
                    --color-* tokens on a dark Tabler palette.
   --------------------------------------------------------------------------- */
?>
<body class="accent-<?php echo nullable_htmlentities($config_theme ?? ''); ?><?php if (!empty($config_theme_dark_default)) echo ' dark-mode'; ?>">
<div class="page">
<?php
/* ---------------------------------------------------------------------------
   PORTAL PREVIEW BANNER

   PLACEMENT: first child of <div class="page">, above the navbar. That position
   is not cosmetic, it is what makes the banner stay put.

   .sticky-top is position:sticky, and a sticky element can only travel within
   its PARENT's box. The obvious home - first child of <body> - does not work
   here: Tabler sets html/body to height:100%, so body's box is exactly one
   viewport tall (measured: 900px against a 2387px document). A banner parented
   there sticks for the first 800px of scroll and then slides away with the
   page, which is the failure this comment exists to stop someone reintroducing.
   Measured in Chromium at 1280x900: parented to <body> the banner's top went to
   -587px at scrollY 1387; parented to .page it holds at 0px.

   .page is display:flex/column and, being the shell that wraps the navbar and
   all page content, is as tall as the document (measured 2287px), so a sticky
   child of it has the whole page to stick across. flex-shrink-0 keeps the flex
   column from ever compressing the banner to buy space for content.

   The portal navbar is position:relative and scrolls away underneath, so there
   is no z-index contest with it, and modals (z-index 1055) still correctly
   cover the banner. Nothing here fights the layout: the banner is a normal
   block in flow, so it simply pushes the navbar down and needs no compensating
   body padding anywhere.

   COLOUR, and why it uses no theme token. The app has two non-equivalent dark
   triggers - html[data-bs-theme="dark"] (Tabler's palette plus itflow_design's
   --if-* set) and body.dark-mode (itflow_custom's --color-* set) - and this
   file emits both together. Rather than satisfy two trigger systems, the banner
   is painted in colours that move under neither: .bg-warning resolves
   --tblr-warning, declared #f59f00 in BOTH Tabler's :root and its dark block
   and overridden by no app stylesheet, and .text-black is a flat #000
   !important in both tabler.min.css and css/itflow.shim-adminlte.css:345.
   Verified identical in Chromium under both themes: background
   color(srgb 0.960784 0.623529 0) = #f59f00, text rgb(0,0,0) - about 11:1
   either way. That is right on the merits too: a warning about your own session
   should look the same wherever you meet it, the way private-mode browser
   chrome does.

   Deliberately AVOIDED, each for a specific measured reason:
     .text-dark        css/itflow_custom.css:509 remaps it to var(--color-text),
                       i.e. near-WHITE in dark mode - white on amber is ~2.1:1.
     .text-bg-warning  Tabler forces color:#fff on it. Same ~2.1:1 problem.
     .alert-warning    tinted, not solid; far too quiet for this.
     .btn-outline-dark css/itflow_custom.css repaints it from --color-* tokens.
     .bg-light         css/itflow_custom.css:523 repaints it on dark pages.

   NOT DISMISSIBLE by construction: no close control, no data-bs-dismiss, and no
   JavaScript at all. The only way it leaves the page is by leaving the preview.
   --------------------------------------------------------------------------- */
?>
<?php if ($portal_preview_banner !== null) { ?>
<div class="sticky-top flex-shrink-0 bg-warning text-black shadow-sm" role="region" aria-label="Read-only portal preview">
    <div class="container d-flex flex-wrap align-items-center gap-2 py-2">
        <i class="fas fa-eye fa-lg" aria-hidden="true"></i>
        <strong class="text-uppercase text-nowrap">Read-only preview</strong>
        <span>
            <span aria-hidden="true">&middot;</span>
            You are viewing the <strong><?php echo $portal_preview_dept; ?></strong> department portal
            as an agent<?php if ($portal_preview_agent !== '') { ?><span class="d-none d-xxl-inline"> (<?php echo $portal_preview_agent; ?>)</span><?php } ?>.
            Nothing here can be changed<span class="d-none d-xxl-inline"> &mdash; every action is blocked and logged</span>.
        </span>
        <a class="btn btn-sm btn-dark ms-auto text-nowrap" href="<?php echo $portal_preview_exit; ?>">
            <i class="fas fa-sign-out-alt me-1" aria-hidden="true"></i>Exit preview
        </a>
    </div>
</div>
<?php } ?>

<!-- Navbar. A plain Bootstrap 5 navbar (it never used AdminLTE), kept verbatim.
     It is a direct child of .page and is internally balanced, so it adds no
     structural depth for client/includes/footer.php to close. -->

<nav class="navbar navbar-expand-lg navbar-dark bg-dark client-portal-nav<?php if ($portal_preview_banner === null) { echo ' portal-nav-sticky'; } ?>" data-bs-theme="dark">
    <div class="container">
        <?php
        /* NAVBAR BRAND - two stacked lines, department over company.

           This is the most prominent identity string in the portal and it used
           to be the company name alone, which named a scope this page does not
           have: the navbar's own Technical dropdown, ticket list, asset list and
           document list are every one of them filtered to
           `WHERE *_client_id = $session_client_id`. The department is now the
           primary line; the company stays as the secondary line and keeps the
           logo, because the company is genuinely the operator of the install.

           THIS ALSO FIXES A LIVE HORIZONTAL-SCROLL BUG, measured before the
           change on mw-itflow.foleyit.com at a 390px viewport: the page's
           scrollWidth was 461px against a 390px client width - 71px of sideways
           scroll on every portal page on a phone. The sole offending element was
           this brand, 419px wide inside a 380px navbar, because Bootstrap's
           `.navbar-brand { white-space: nowrap }` gives an unbreakable text run a
           min-content width equal to its full one-line width, and a flex item's
           default `min-width: auto` floors it there - so flex-shrink could never
           act however narrow the viewport got.

           Two lines rather than one is what fixes it: at 11px the company name
           no longer sets the brand's width, and the brand's max-content drops
           from 419px to 270px, which fits. Measured after, at 390px: scrollWidth
           380 against clientWidth 390 (no horizontal scroll), and the toggler
           comes back onto the brand's own flex row, taking the navbar from 93px
           tall to 61px. css/itflow_custom.css carries the guard that keeps this
           true for a company name longer than this install's, with the
           per-declaration measurements.

           Each line carries title= with its own full text: css/itflow_custom.css
           ellipsis-truncates both, and a clipped name with no title cannot be
           read at all - not on hover, not by a screen reader. The attribute
           holds the same nullable_htmlentities() output the element does, which
           is attribute-safe.

           Each line is its own element so each truncates independently - the
           39-character company name ellipsing must never be allowed to push the
           20-character department name (the longest live one, "Shipping &
           Receiving") off screen. */
        ?>
        <a class="navbar-brand portal-brand d-flex align-items-center" href="index.php">
            <?php if ($session_company_logo) { ?>
                <img height="28" class="me-2 flex-shrink-0" src="<?php echo "/uploads/settings/$session_company_logo"; ?>" alt="">
            <?php } ?>
            <?php if ($portal_dept_html !== '') { ?>
                <span class="portal-brand-text">
                    <span class="portal-brand-dept" title="<?php echo $portal_dept_html; ?>"><?php echo $portal_dept_html; ?></span>
                    <span class="portal-brand-org" title="<?php echo $portal_org_html; ?>"><?php echo $portal_org_html; ?></span>
                </span>
            <?php } else { ?>
                <span class="portal-brand-text">
                    <span class="portal-brand-dept" title="<?php echo $portal_org_html; ?>"><?php echo $portal_org_html; ?></span>
                </span>
            <?php } ?>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarSupportedContent">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarSupportedContent">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link <?php if (basename($_SERVER['PHP_SELF']) == "index.php") {echo "active";} ?>" href="/client/index.php">Home</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php if (basename($_SERVER['PHP_SELF']) == "tickets.php" || basename($_SERVER['PHP_SELF']) == "ticket_add.php" || basename($_SERVER['PHP_SELF']) == "ticket.php") {echo "active";} ?>" href="/client/tickets.php">Tickets</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php if (basename($_SERVER['PHP_SELF']) == "service_catalog.php") {echo "active";} ?>" href="/client/service_catalog.php">Request Something</a>
                </li>

                <?php if ($config_module_enable_kb == 1) { ?>
                    <li class="nav-item">
                        <a class="nav-link <?php if (basename($_SERVER['PHP_SELF']) == "kb_articles.php" || basename($_SERVER['PHP_SELF']) == "kb_article.php") {echo "active";} ?>" href="/client/kb_articles.php">Knowledge Base</a>
                    </li>
                <?php } ?>

                <?php if (intval($config_module_enable_training ?? 0) === 1 && !empty($config_training_schema_ready)) { ?>
                    <li class="nav-item">
                        <a class="nav-link <?php if (basename($_SERVER['PHP_SELF']) == "training.php") {echo "active";} ?>" href="/client/training.php">Training</a>
                    </li>
                <?php } ?>

                <?php if (($session_contact_primary == 1 || $session_contact_is_billing_contact) && $config_module_enable_accounting == 1) { ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?php echo in_array(basename($_SERVER['PHP_SELF']), ['invoices.php', 'quotes.php', 'autopay.php']) ? 'active' : ''; ?>" href="#" id="navbarDropdown1" role="button" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                            Finance
                        </a>
                        <div class="dropdown-menu" aria-labelledby="navbarDropdown1">
                            <a class="dropdown-item" href="/client/invoices.php">Invoices</a>
                            <a class="dropdown-item" href="/client/recurring_invoices.php">Recurring Invoices</a>
                            <a class="dropdown-item" href="/client/quotes.php">Quotes</a>
                            <a class="dropdown-item" href="/client/saved_payment_methods.php">Saved Payments</a>
                        </div>
                    </li>
                <?php } ?>

                <?php if ($config_module_enable_itdoc && ($session_contact_primary == 1 || $session_contact_is_technical_contact)) { ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?php echo in_array(basename($_SERVER['PHP_SELF']), ['documents.php', 'contacts.php', 'domains.php', 'certificates.php', 'contracts.php', 'allowance.php']) ? 'active' : ''; ?>" href="#" id="navbarDropdown2" role="button" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                            Technical
                        </a>
                        <div class="dropdown-menu" aria-labelledby="navbarDropdown2">
                            <a class="dropdown-item" href="/client/contacts.php">Contacts</a>
                            <a class="dropdown-item" href="/client/assets.php">Assets</a>
                            <a class="dropdown-item" href="/client/contracts.php">Contracts &amp; Docs</a>
                            <a class="dropdown-item" href="/client/allowance.php">Support Allowance</a>
                            <a class="dropdown-item" href="/client/documents.php">Documents</a>
                            <a class="dropdown-item" href="/client/domains.php">Domains</a>
                            <a class="dropdown-item" href="/client/certificates.php">Certificates</a>
                            <a class="dropdown-item" href="/client/ticket_view_all.php">All tickets</a>
                        </div>
                    </li>
                <?php } ?>

                <?php
                $sql_custom_links = mysqli_query($mysqli, "SELECT * FROM custom_links WHERE custom_link_location = 3 AND custom_link_archived_at IS NULL
                    ORDER BY custom_link_order ASC, custom_link_name ASC"
                );

                while ($row = mysqli_fetch_assoc($sql_custom_links)) {
                    $custom_link_name = nullable_htmlentities($row['custom_link_name']);
                    $custom_link_uri = nullable_htmlentities($row['custom_link_uri']);
                    $custom_link_new_tab = intval($row['custom_link_new_tab']);
                    if ($custom_link_new_tab == 1) {
                        $target = "target='_blank' rel='noopener noreferrer'";
                    } else {
                        $target = "";
                    }

                    ?>

                    <li class="nav-item">
                        <a href="<?php echo $custom_link_uri; ?>" <?php echo $target; ?> class="nav-link <?php if (basename($_SERVER["PHP_SELF"]) == basename($custom_link_uri)) { echo "active"; } ?>"><?php echo $custom_link_name ?></a>
                    </li>

                <?php } ?>

            </ul><!-- End left nav -->

            <div class="portal-nav-right">
                <button type="button" class="portal-theme-toggle" data-portal-theme-toggle aria-label="Switch light or dark mode" title="Switch light or dark mode">
                    <i class="fas fa-moon portal-icon-moon" aria-hidden="true"></i><i class="fas fa-sun portal-icon-sun" aria-hidden="true"></i>
                </button>
                <ul class="nav navbar-nav">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <span class="portal-user-chip">
                                <span class="portal-user-avatar" aria-hidden="true"><?php echo nullable_htmlentities($session_contact_initials); ?></span>
                                <span class="portal-user-name"><?php echo stripslashes(nullable_htmlentities($session_contact_name)); ?></span>
                            </span>
                        </a>
                        <div class="dropdown-menu dropdown-menu-end">
                            <a class="dropdown-item" href="/client/profile.php"><i class="fas fa-fw fa-user me-2"></i>Profile</a>
                            <?php if (!empty($portal_lms_ok)) { ?>
                                <a class="dropdown-item" href="/agent/training_dashboard.php"><i class="fas fa-fw fa-graduation-cap me-2"></i>Training management</a>
                            <?php } elseif (!empty($portal_agent_home)) { ?>
                                <a class="dropdown-item" href="<?= nullable_htmlentities($portal_agent_home['url']) ?>"><i class="fas fa-fw fa-briefcase me-2"></i><?= nullable_htmlentities($portal_agent_home['label']) ?> (agent workspace)</a>
                            <?php } ?>
                            <div class="dropdown-divider"></div>
                            <a class="dropdown-item" href="/client/post.php?logout"><i class="fas fa-fw fa-sign-out-alt me-2"></i>Sign out</a>
                        </div>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</nav>

<?php
/* ---------------------------------------------------------------------------
   Page content wrappers. Three levels, closed by client/includes/footer.php.

   .page-body supplies the vertical rhythm (margin-block: var(--tblr-page-padding-y))
   that the old markup faked with a bare <br> after the navbar, so that <br> is gone.

   .container (NOT .container-xl) keeps the portal's existing centred, capped
   reading width, which also lines up with the .container inside the navbar above.
   --------------------------------------------------------------------------- */
?>
<div class="page-wrapper">
    <div class="page-body">
        <div class="container">

    <?php
    /* Full hero on Home, slim identity strip everywhere else. */
    $portal_is_home = basename($_SERVER['PHP_SELF']) === 'index.php';
    $portal_hour = intval(date('G'));
    $portal_greeting = $portal_hour < 12 ? 'Good morning' : ($portal_hour < 18 ? 'Good afternoon' : 'Good evening');
    $portal_first_name = trim((string) strtok((string) $session_contact_name, ' '));
    ?>
    <?php if (basename($_SERVER['PHP_SELF']) !== 'profile.php') { ?>
    <div class="card portal-hero mb-4<?php if (!$portal_is_home) { echo ' portal-hero--compact'; } ?>">
        <div class="portal-hero-body">
            <?php if (!empty($session_contact_photo)) { ?>
                <img src="/uploads/clients/<?= $session_client_id ?>/<?= $session_contact_photo ?>" alt="" class="portal-hero-avatar">
            <?php } else { ?>
                <span class="portal-hero-avatar" aria-hidden="true"><?php echo nullable_htmlentities($session_contact_initials); ?></span>
            <?php } ?>
            <div class="portal-hero-text">
                <div class="portal-hero-eyebrow"><?php echo $portal_greeting; ?></div>
                <h1 class="portal-hero-title"><?php echo stripslashes(nullable_htmlentities($portal_is_home ? $portal_first_name : $session_contact_name)); ?></h1>
                <span class="portal-hero-sub"><i class="fas fa-building" aria-hidden="true"></i><?php
                    echo $portal_dept_html !== ''
                        ? "$portal_dept_html Department Portal"
                        : "$portal_org_html Department Portal";
                ?></span>
            </div>
            <?php if ($portal_is_home) { ?>
                <div class="portal-hero-actions">
                    <a href="/client/ticket_add.php" class="btn btn-light"><i class="fas fa-plus me-2" aria-hidden="true"></i>New ticket</a>
                    <?php if (intval($config_module_enable_training ?? 0) === 1 && !empty($config_training_schema_ready)) { ?>
                        <a href="/client/training.php" class="btn btn-glass"><i class="fas fa-graduation-cap me-2" aria-hidden="true"></i>Training</a>
                    <?php } ?>
                </div>
            <?php } ?>
        </div>
    </div>
    <?php } ?>

    <?php
    //Alert Feedback
    if (!empty($_SESSION['alert_message'])) {
        if (!isset($_SESSION['alert_type'])) {
            $_SESSION['alert_type'] = "info";
        }
        ?>
        <div class="alert alert-<?php echo $_SESSION['alert_type']; ?> portal-alert" id="alert">
            <?php echo nullable_htmlentities($_SESSION['alert_message']); ?>
            <button class='close' data-bs-dismiss='alert'>&times;</button>
        </div>
        <?php

        unset($_SESSION['alert_type']);
        unset($_SESSION['alert_message']);

    }
    ?>
