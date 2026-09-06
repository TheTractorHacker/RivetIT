<?php

//Alert Feedback
if (!empty($_SESSION['alert_message'])) {
    if (!isset($_SESSION['alert_type'])) {
        $_SESSION['alert_type'] = "success";
    }
    ?>

    <script type="text/javascript" nonce="<?php echo htmlspecialchars($csp_nonce ?? '', ENT_QUOTES); ?>">

        /* Options are set once in js/app.js so every toast path shares them -
           including the AJAX ones, which never reached this file. */

        toastr[<?php echo json_encode($_SESSION['alert_type']); ?>](<?php echo json_encode($_SESSION['alert_message']); ?>)

    </script>

    <?php

    unset($_SESSION['alert_type']);
    unset($_SESSION['alert_message']);

}

?>
