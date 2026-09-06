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
    <title><?php echo nullable_htmlentities($session_company_name); ?> | Department Portal</title>

    <!-- Tell the browser to be responsive to screen width -->
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">

    <!-- Favicon: If Fav Icon exists, else use the default one -->
    <?php if(file_exists($_SERVER['DOCUMENT_ROOT'] . '/uploads/favicon.ico')) { ?>
        <link rel="icon" href="/uploads/favicon.ico">
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

<!-- Navbar. A plain Bootstrap 5 navbar (it never used AdminLTE), kept verbatim.
     It is a direct child of .page and is internally balanced, so it adds no
     structural depth for client/includes/footer.php to close. -->

<nav class="navbar navbar-expand-lg navbar-dark bg-dark client-portal-nav" data-bs-theme="dark">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center" href="index.php">
            <?php if ($session_company_logo) { ?>
                <img height="28" class="me-2" src="<?php echo "/uploads/settings/$session_company_logo"; ?>" alt="">
            <?php } ?>
            <?php echo nullable_htmlentities($session_company_name); ?>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarSupportedContent">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarSupportedContent">
            <ul class="navbar-nav me-auto">
                <li class="nav-item <?php if (basename($_SERVER['PHP_SELF']) == "index.php") {echo "active";} ?>">
                    <a class="nav-link" href="/client/index.php">Home</a>
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
                        <a class="nav-link dropdown-toggle <?php echo in_array(basename($_SERVER['PHP_SELF']), ['documents.php', 'contacts.php', 'domains.php', 'certificates.php', 'contracts.php']) ? 'active' : ''; ?>" href="#" id="navbarDropdown2" role="button" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                            Technical
                        </a>
                        <div class="dropdown-menu" aria-labelledby="navbarDropdown2">
                            <a class="dropdown-item" href="/client/contacts.php">Contacts</a>
                            <a class="dropdown-item" href="/client/assets.php">Assets</a>
                            <a class="dropdown-item" href="/client/contracts.php">Contracts &amp; Docs</a>
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

            <ul class="nav navbar-nav pull-right">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown">
                        <?php echo stripslashes(nullable_htmlentities($session_contact_name)); ?>
                    </a>
                    <div class="dropdown-menu">
                        <a class="dropdown-item" href="/client/profile.php"><i class="fas fa-fw fa-user me-2"></i>Account</a>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item" href="/client/post.php?logout"><i class="fas fa-fw fa-sign-out-alt me-2"></i>Sign out</a>
                    </div>
                </li>
            </ul>
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

    <div class="card welcome-banner border-0 shadow-sm mb-4">
        <div class="card-body d-flex align-items-center">
            <?php if (!empty($session_contact_photo)) { ?>
                <img src="/uploads/clients/<?= $session_client_id ?>/<?= $session_contact_photo ?>" alt="" height="56" width="56" class="rounded-circle me-3">
            <?php } else { ?>
                <span class="fa-stack fa-3x me-3">
                    <i class="fa fa-circle fa-stack-2x text-primary"></i>
                    <span class="fa-stack-1x text-white fw-bold"><?php echo $session_contact_initials; ?></span>
                </span>
            <?php } ?>
            <div>
                <h4 class="mb-0">Welcome back, <strong><?php echo stripslashes(nullable_htmlentities($session_contact_name)); ?></strong></h4>
                <small class="text-muted"><?php echo nullable_htmlentities($session_company_name); ?> Department Portal</small>
            </div>
        </div>
    </div>

    <?php
    //Alert Feedback
    if (!empty($_SESSION['alert_message'])) {
        if (!isset($_SESSION['alert_type'])) {
            $_SESSION['alert_type'] = "info";
        }
        ?>
        <div class="alert alert-<?php echo $_SESSION['alert_type']; ?>" id="alert">
            <?php echo nullable_htmlentities($_SESSION['alert_message']); ?>
            <button class='close' data-bs-dismiss='alert'>&times;</button>
        </div>
        <?php

        unset($_SESSION['alert_type']);
        unset($_SESSION['alert_message']);

    }
    ?>
