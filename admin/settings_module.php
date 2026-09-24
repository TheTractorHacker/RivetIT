<?php
require_once "includes/inc_all_admin.php";
 ?>

<!-- Plain .card, not the legacy AdminLTE .card-dark. css/itflow_custom.css records
     that the class is otherwise inert, and on this page it only did harm: it squared
     off the card's bottom two corners (`.card.card-dark` carries the header's
     `border-radius: … 0 0 !important`) and out-ranked the design layer's own
     .form-control colours, so every field on the page painted white in dark mode. -->
<div class="card">
    <div class="card-header py-3">
        <h3 class="card-title"><i class="fas fa-fw fa-cube me-2"></i>Modules</h3>
    </div>
    <div class="card-body">
        <form action="post.php" method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">

            <div class="form-group">
                <div class="form-check form-check form-switch">
                    <input type="checkbox" class="form-check-input" name="config_module_enable_itdoc" <?php if ($config_module_enable_itdoc == 1) { echo "checked"; } ?> value="1" id="customSwitch1">
                    <label class="form-check-label" for="customSwitch1">Show IT Documentation</label>
                </div>
            </div>

            <div class="form-group">
                <div class="form-check form-check form-switch">
                    <input type="checkbox" class="form-check-input" name="config_module_enable_ticketing" <?php if ($config_module_enable_ticketing == 1) { echo "checked"; } ?> value="1" id="customSwitch2">
                    <label class="form-check-label" for="customSwitch2">Show Ticketing</label>
                </div>
            </div>

            <div class="form-group">
                <div class="form-check form-check form-switch">
                    <input type="checkbox" class="form-check-input" name="config_module_enable_kb" <?php if ($config_module_enable_kb == 1) { echo "checked"; } ?> value="1" id="customSwitch3c">
                    <label class="form-check-label" for="customSwitch3c">Show Knowledge Base</label>
                </div>
                <small class="form-text text-muted">Adds a Knowledge Base section for agents and departments - per-department articles plus a Central (company-wide) library.</small>
            </div>

            <?php if (!empty($config_training_schema_ready)) { ?>
            <div class="form-group">
                <div class="form-check form-check form-switch">
                    <input type="checkbox" class="form-check-input" name="config_module_enable_training" <?php if ($config_module_enable_training == 1) { echo "checked"; } ?> value="1" id="customSwitchTraining">
                    <label class="form-check-label" for="customSwitchTraining">Show Training (LMS)</label>
                </div>
                <small class="form-text text-muted">Course builder and quizzes now; compliance records and the iPad kiosk in later phases. Visible only to roles granted the Training permission.</small>
            </div>
            <?php } ?>

            <div class="form-group">
                <div class="form-check form-check form-switch">
                    <input type="checkbox" class="form-check-input" name="config_module_enable_live_chat" <?php if ($config_module_enable_live_chat == 1) { echo "checked"; } ?> value="1" id="customSwitch3d">
                    <label class="form-check-label" for="customSwitch3d">Show Live Chat on Tickets</label>
                </div>
                <small class="form-text text-muted">Adds a real-time chat panel to ticket views for agents and departments, alongside the normal email-style replies.</small>
            </div>

            <div class="form-group">
                <div class="form-check form-check form-switch">
                    <input type="checkbox" class="form-check-input" name="config_client_portal_enable" <?php if ($config_client_portal_enable == 1) { echo "checked"; } ?> value="1" id="customSwitch4">
                    <label class="form-check-label" for="customSwitch4">Enable Department Portal</label>
                </div>
            </div>

            <hr>

            <button type="submit" name="edit_module_settings" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Save</button>

        </form>
    </div>
</div>

<?php
require_once "../includes/footer.php";

