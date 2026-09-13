<?php

//Alert Feedback
if (!empty($_SESSION['alert_message'])) {
    if (!isset($_SESSION['alert_type'])) {
        $_SESSION['alert_type'] = "success";
    }

    // toastr's real methods are success/error/warning/info - there is no
    // .danger(). flash_alert() callers have passed 'danger' (Bootstrap's
    // alert-class name, not toastr's) at dozens of call sites for a long
    // time, plus the occasional outright typo ('errpr', 'alert'). Every one
    // of those calls toastr[type](...) as a function that doesn't exist,
    // which throws and drops the message on the floor instead of showing it.
    // Normalizing once here - rather than fixing every call site - closes
    // all of those silently, including ones nobody's found yet.
    $toastr_type = $_SESSION['alert_type'];
    if (!in_array($toastr_type, ['success', 'error', 'warning', 'info'], true)) {
        $toastr_type = 'error';
    }
    ?>

    <script type="text/javascript" nonce="<?php echo htmlspecialchars($csp_nonce ?? '', ENT_QUOTES); ?>">

        /* Options are set once in js/app.js so every toast path shares them -
           including the AJAX ones, which never reached this file. */

        toastr[<?php echo json_encode($toastr_type); ?>](<?php echo json_encode($_SESSION['alert_message']); ?>)

    </script>

    <?php

    unset($_SESSION['alert_type']);
    unset($_SESSION['alert_message']);

}

?>
