<?php
require_once "../../config.php";
require_once "../../functions.php";
require_once "../../includes/check_login.php";
require_once '../../plugins/totp/totp.php'; //TOTP MFA Lib

// Get Company Logo
$sql = mysqli_query($mysqli, "SELECT company_logo FROM companies");
$row = mysqli_fetch_assoc($sql);
$company_logo = nullable_htmlentities($row['company_logo']);


// Only generate the token once and store it in session:
if (empty($_SESSION['mfa_token'])) {
    $token = key32gen();
    $_SESSION['mfa_token'] = $token;
}
$token = $_SESSION['mfa_token'];

// Generate QR Code
$data = "otpauth://totp/ITFlow:$session_email?secret=$token";

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="x-ua-compatible" content="ie=edge">
    <meta name="robots" content="noindex">

    <title>MFA Enforcement | <?php echo $session_company_name; ?></title>

    <!--
    Favicon
    If Fav Icon exists else use the default one
    -->
    <?php if(file_exists('../../uploads/favicon.ico')) { ?>
        <link rel="icon" type="image/x-icon" href="../../uploads/favicon.ico">
    <?php } ?>

    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="../../plugins/fontawesome-free/css/all.min.css">

    <!-- Core stack: Bootstrap 5.3 + AdminLTE 4 -->
    <link rel="stylesheet" href="../../plugins/bootstrap5/css/bootstrap.min.css">
    <link rel="stylesheet" href="../../plugins/adminlte4/css/adminlte.min.css">
    <link href="../../plugins/toastr/toastr.min.css" rel="stylesheet">

    <!-- Theme: BS5 bridge (maps BS vars -> Alga tokens) THEN the custom theme -->
    <link rel="stylesheet" href="../../css/itflow_bs5_bridge.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/css/itflow_bs5_bridge.css') ?>">
    <link rel="stylesheet" href="../../css/itflow_custom.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/css/itflow_custom.css') ?>">
    <link rel="stylesheet" href="../../css/itflow_design.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/css/itflow_design.css') ?>">

    <!-- jQuery -->
    <script src="../../plugins/jquery/jquery.min.js"></script>
    <script src="../../plugins/toastr/toastr.min.js"></script>

</head>
<body class="hold-transition login-page">
    <?php require_once "../../includes/inc_alert_feedback.php"; ?>
    <div class="login-box">
        <div class="login-logo">
            <?php if (!empty($company_logo)) { ?>
                <img alt="<?= nullable_htmlentities($company_name)?> logo" height="110" width="380" class="img-fluid" src="<?php echo "../../uploads/settings/$company_logo"; ?>">
            <?php } else { ?>
                <span class="text-primary text-bold"><i class="fas fa-paper-plane me-2"></i>IT</span>Flow
            <?php } ?>
        </div>

        <!-- /.login-logo -->
        <div class="card">
            <div class="card-body login-card-body text-center">

                <p class="login-box-msg">Multi-Factor Authentication Enforced</p>

                <form action="post.php" method="post" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">

                    <img src='../../plugins/barcode/barcode.php?f=png&s=qr&d=<?php echo $data; ?>' data-bs-toggle="tooltip" title="Scan QR code into your MFA App">

                    <p>
                        <small data-bs-toggle="tooltip" title="Can't Scan? Copy and paste this code into your app"><?php echo $token; ?></small>
                        <button type="button" class='btn btn-sm clipboardjs' data-clipboard-text='<?php echo $token; ?>'><i class='far fa-copy text-secondary'></i></button>
                    </p>

                    <div class="input-group mb-3">
                        <input type="text" class="form-control" inputmode="numeric" pattern="[0-9]*" minlength="6" maxlength="6" name="verify_code" placeholder="Enter 6 digit code to verify MFA" required>
                        <div class="input-group-append">
                            <div class="input-group-text">
                                <span class="fas fa-lock"></span>
                            </div>
                        </div>
                    </div>

                    <button type="submit" name="enable_mfa" class="btn btn-primary btn-block mb-3"><i class="fa fa-check me-2"></i>Enable MFA</button>
                </form>

            </div>
            <!-- /.login-card-body -->
        </div>
    </div>
    <!-- /.login-box -->

    <!-- REQUIRED SCRIPTS -->

    <!-- Bootstrap 5 (bundle includes Popper) -->
    <script src="../../plugins/bootstrap5/js/bootstrap.bundle.min.js"></script>

    <!-- Custom js-->
    <script src="../../plugins/clipboardjs/clipboard.min.js"></script>

    <script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">

    // Slide alert up after 4 secs
    $("#alert").fadeTo(5000, 500).slideUp(500, function(){
        $("#alert").slideUp(500);
    });

    // Tooltips & popovers (Bootstrap 5, vanilla)
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
        bootstrap.Tooltip.getOrCreateInstance(el);
    });
    document.querySelectorAll('[data-bs-toggle="popover"]').forEach(function (el) {
        bootstrap.Popover.getOrCreateInstance(el, { container: 'body' });
    });

    // Clipboard copy with BS5 tooltip feedback
    var clipboard = new ClipboardJS('.clipboardjs');

    function flashTooltip(el, message) {
        var tip = bootstrap.Tooltip.getOrCreateInstance(el, { trigger: 'manual', placement: 'bottom', title: message });
        tip.setContent({ '.tooltip-inner': message });
        tip.show();
        setTimeout(function () { tip.hide(); }, 1000);
    }

    clipboard.on('success', function(e) {
        flashTooltip(e.trigger, 'Copied!');
        e.clearSelection();
    });

    clipboard.on('error', function(e) {
        flashTooltip(e.trigger, 'Failed!');
    });

    </script>

</body>

</html>
