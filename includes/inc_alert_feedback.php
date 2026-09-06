<?php

//Alert Feedback
if (!empty($_SESSION['alert_message'])) {
    if (!isset($_SESSION['alert_type'])) {
        $_SESSION['alert_type'] = "success";
    }
    ?>

    <script type="text/javascript" nonce="<?php echo htmlspecialchars($csp_nonce ?? '', ENT_QUOTES); ?>">

        toastr.options = {
            "closeButton": false,
            "debug": false,
            "newestOnTop": false,
            "progressBar": false,
            "positionClass": "toast-top-center",
            "preventDuplicates": false,
            "onclick": null,
            /* SHOW is handed to CSS. css/itflow_motion.css animates the toast in
               (#toast-container > div). jQuery's fadeIn writes an inline
               style="opacity:..." on every frame, which outranks that animation,
               and the two fought: the toast faded in over 240ms, then the CSS
               animation's `backwards` fill handed control back to jQuery's inline
               opacity:0 - which was still 0 because the queued fadeIn had not
               started - so every toast blinked out for ~50ms mid-entrance.
               Measured live: op 0->1.00 by t=223ms, then op=0 at t=240/256/273/290ms.
               .show() only sets display, so the CSS entrance now owns the reveal
               and there is nothing to fight.

               HIDE stays with jQuery: toastr removes the node when its own callback
               fires and there is no CSS hook for that. 1000ms was a full second of a
               dismissed message still covering the page; 160ms matches --if-dur-ui.
               It is opacity-only, so it stays honest under reduced motion even though
               a media query cannot reach a JS animation. */
            "showDuration": "0",
            "hideDuration": "160",
            "timeOut": "5000",
            "extendedTimeOut": "1000",
            "showEasing": "linear",
            "hideEasing": "linear",
            "showMethod": "show",
            "hideMethod": "fadeOut"
        }

        toastr[<?php echo json_encode($_SESSION['alert_type']); ?>](<?php echo json_encode($_SESSION['alert_message']); ?>)

    </script>

    <?php

    unset($_SESSION['alert_type']);
    unset($_SESSION['alert_message']);

}

?>
