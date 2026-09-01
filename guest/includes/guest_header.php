<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
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

    <!-- Core stack: Bootstrap 5.3 + AdminLTE 4 -->
    <link rel="stylesheet" href="/plugins/bootstrap5/css/bootstrap.min.css">
    <link rel="stylesheet" href="/plugins/adminlte4/css/adminlte.min.css">

    <!-- Toastr (used by inc_alert_feedback) -->
    <link rel="stylesheet" href="/plugins/toastr/toastr.min.css">

    <!-- Theme: BS5 bridge (maps BS vars -> Alga tokens) THEN the custom theme -->
    <link rel="stylesheet" href="/css/itflow_bs5_bridge.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/css/itflow_bs5_bridge.css') ?>">
    <link rel="stylesheet" href="/css/itflow_custom.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/css/itflow_custom.css') ?>">
    <link rel="stylesheet" href="/css/itflow_design.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/css/itflow_design.css') ?>">

    <!-- Scripts: jQuery kept as a coexistence shim; toastr for alert feedback -->
    <script src="/plugins/jquery/jquery.min.js"></script>
    <script src="/plugins/toastr/toastr.min.js"></script>

</head>
<body class="layout-top-nav">
    <!-- AdminLTE 4 layout wrapper (no sidebar on guest pages, so the sidebar
         grid column simply collapses to 0 width) -->
    <div class="app-wrapper text-sm">