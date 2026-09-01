<?php
require_once "includes/inc_all_admin.php";
 ?>

<div class="card card-dark">
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
                    <input type="checkbox" class="form-check-input" name="config_module_enable_accounting" <?php if ($config_module_enable_accounting == 1) { echo "checked"; } ?> value="1" id="customSwitch3">
                    <label class="form-check-label" for="customSwitch3">Show Invoicing / Accounting</label>
                </div>
            </div>

            <div class="form-group">
                <div class="form-check form-check form-switch">
                    <input type="checkbox" class="form-check-input" name="config_module_enable_ticket_charges" <?php if ($config_module_enable_ticket_charges == 1) { echo "checked"; } ?> value="1" id="customSwitch3b">
                    <label class="form-check-label" for="customSwitch3b">Show Ticket Charges (Billing)</label>
                </div>
                <small class="form-text text-muted">Lets techs add billable charges/labor to tickets, independent of Invoicing / Accounting. Useful if you handle invoicing elsewhere (e.g. QuickBooks) but still want to track billable time per ticket.</small>
            </div>

            <div class="form-group">
                <div class="form-check form-check form-switch">
                    <input type="checkbox" class="form-check-input" name="config_module_enable_kb" <?php if ($config_module_enable_kb == 1) { echo "checked"; } ?> value="1" id="customSwitch3c">
                    <label class="form-check-label" for="customSwitch3c">Show Knowledge Base</label>
                </div>
                <small class="form-text text-muted">Adds a Knowledge Base section for agents and clients - per-client articles plus a Central (company-wide) library.</small>
            </div>

            <div class="form-group">
                <div class="form-check form-check form-switch">
                    <input type="checkbox" class="form-check-input" name="config_module_enable_live_chat" <?php if ($config_module_enable_live_chat == 1) { echo "checked"; } ?> value="1" id="customSwitch3d">
                    <label class="form-check-label" for="customSwitch3d">Show Live Chat on Tickets</label>
                </div>
                <small class="form-text text-muted">Adds a real-time chat panel to ticket views for agents and clients, alongside the normal email-style replies.</small>
            </div>

            <div class="form-group">
                <div class="form-check form-check form-switch">
                    <input type="checkbox" class="form-check-input" name="config_module_enable_payroll" <?php if ($config_module_enable_payroll == 1) { echo "checked"; } ?> value="1" id="customSwitch3e">
                    <label class="form-check-label" for="customSwitch3e">Show Payroll</label>
                </div>
                <small class="form-text text-muted">Gross-pay-only payroll runs (hours &times; rate, overtime, deductions) for your own staff. No tax withholding is calculated - that stays with your accountant/payroll service.</small>
            </div>

            <div class="form-group">
                <div class="form-check form-check form-switch">
                    <input type="checkbox" class="form-check-input" name="config_client_portal_enable" <?php if ($config_client_portal_enable == 1) { echo "checked"; } ?> value="1" id="customSwitch4">
                    <label class="form-check-label" for="customSwitch4">Enable Client Portal</label>
                </div>
            </div>

            <hr>

            <button type="submit" name="edit_module_settings" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Save</button>

        </form>
    </div>
</div>

<?php
require_once "../includes/footer.php";

