<?php
/*
 * Client Portal
 * HTML Header
 */

header("X-Frame-Options: DENY"); // Legacy
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="<?= (!empty($config_theme_dark_default)) ? 'dark' : 'light' ?>">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title><?php echo nullable_htmlentities($session_company_name); ?> | Client Portal</title>

    <!-- Tell the browser to be responsive to screen width -->
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">

    <!-- Favicon: If Fav Icon exists, else use the default one -->
    <?php if(file_exists($_SERVER['DOCUMENT_ROOT'] . '/uploads/favicon.ico')) { ?>
        <link rel="icon" href="/uploads/favicon.ico">
    <?php } ?>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="/plugins/fontawesome-free/css/all.min.css">

    <!-- Core stack: Bootstrap 5.3 + AdminLTE 4 -->
    <link rel="stylesheet" href="/plugins/bootstrap5/css/bootstrap.min.css">
    <link rel="stylesheet" href="/plugins/adminlte4/css/adminlte.min.css">

    <!-- Theme: BS5 bridge (maps BS vars -> Alga tokens) THEN the custom theme -->
    <link rel="stylesheet" href="/css/itflow_bs5_bridge.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/css/itflow_bs5_bridge.css') ?>">
    <link rel="stylesheet" href="/css/itflow_custom.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/css/itflow_custom.css') ?>">
    <link rel="stylesheet" href="/css/itflow_design.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/css/itflow_design.css') ?>">

</head>

<!-- Navbar -->

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

<br>

<!-- Page content container -->
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
                <small class="text-muted"><?php echo nullable_htmlentities($session_company_name); ?> Client Portal</small>
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
