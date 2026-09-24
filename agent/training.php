<?php

/*
 * Training › entry point (spec §5.1, plan reconciliation §1.3 #9).
 *
 * Phase 1 has no Overview dashboard yet (it arrives in Phase 2), so a permitted user is sent
 * straight to the course list. A lean bootstrap decides that before any page markup; everyone
 * else falls through to the normal shell, where Access::pageGuard renders the "turned off" or
 * "no access" state with the footer at top level.
 */

require_once "../config.php";
require_once "../functions.php";
require_once "../includes/check_login.php";

if (\ITFlow\Training\Core\Access::enabled() && \ITFlow\Training\Core\Access::level() >= 1) {
    header('Location: training_courses.php');
    exit;
}

$page_extra_css = ['/css/itflow_training.css'];   // BEFORE inc_all: header.php reads it
require_once "includes/inc_all.php";
if (\ITFlow\Training\Core\Access::pageGuard(1)) { require_once "../includes/footer.php"; exit; }

// pageGuard(1) passes only when the module is on and the level is >= 1, which the redirect above
// already handled; headers are sent by now, so the safety net is a plain link.
?>
<div class="card"><div class="card-body">
    <?php render_empty_state('fas fa-hard-hat', 'Training', 'Courses and required documents.', '<a class="btn btn-primary" href="training_courses.php">Open courses</a>'); ?>
</div></div>
<?php
require_once "../includes/footer.php";
