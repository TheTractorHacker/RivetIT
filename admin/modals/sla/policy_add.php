<?php
require_once '../../../includes/modal_header.php';

$calendars = mysqli_query($mysqli, "SELECT calendar_id, calendar_name, calendar_is_default FROM sla_business_hours ORDER BY calendar_name");
$statuses  = mysqli_query($mysqli, "SELECT ticket_status_id, ticket_status_name FROM ticket_statuses WHERE ticket_status_active = 1 ORDER BY ticket_status_order, ticket_status_name");

ob_start();
?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-stopwatch me-2"></i>New SLA Policy</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">

    <div class="modal-body">
        <div class="form-group">
            <label>Name <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-tag"></i></span>
                </div>
                <input type="text" class="form-control" name="policy_name" placeholder="e.g. Standard SLA" maxlength="150" required autofocus>
            </div>
        </div>

        <div class="form-group">
            <label>Business Hours Calendar</label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-business-time"></i></span>
                </div>
                <select class="form-control select2" name="policy_calendar_id">
                    <option value="0">24/7 (no business-hours calendar)</option>
                    <?php while ($c = mysqli_fetch_assoc($calendars)) { ?>
                        <option value="<?php echo intval($c['calendar_id']); ?>" <?php if (intval($c['calendar_is_default'])) { echo 'selected'; } ?>>
                            <?php echo nullable_htmlentities($c['calendar_name']); ?><?php if (intval($c['calendar_is_default'])) { echo ' (default)'; } ?>
                        </option>
                    <?php } ?>
                </select>
            </div>
        </div>

        <div class="form-group">
            <label>Pause Statuses <small class="text-secondary">(SLA timers pause while a ticket sits in these statuses)</small></label>
            <select class="form-control select2" name="policy_pause_status_ids[]" multiple data-placeholder="Select statuses">
                <?php while ($s = mysqli_fetch_assoc($statuses)) { ?>
                    <option value="<?php echo intval($s['ticket_status_id']); ?>" <?php if (intval($s['ticket_status_id']) === 3) { echo 'selected'; } ?>>
                        <?php echo nullable_htmlentities($s['ticket_status_name']); ?>
                    </option>
                <?php } ?>
            </select>
        </div>

        <hr>
        <p class="text-bold mb-1">Targets <small class="text-secondary fw-normal">(minutes of business time; blank = fall back to contract hours)</small></p>
        <table class="table table-sm table-borderless mb-0">
            <thead>
                <tr class="text-secondary small">
                    <th>Priority</th>
                    <th>Response (min)</th>
                    <th>Resolution (min)</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="align-middle">Low</td>
                    <td><input type="number" min="0" class="form-control form-control-sm" name="policy_low_response" placeholder="&mdash;"></td>
                    <td><input type="number" min="0" class="form-control form-control-sm" name="policy_low_resolution" placeholder="&mdash;"></td>
                </tr>
                <tr>
                    <td class="align-middle">Medium</td>
                    <td><input type="number" min="0" class="form-control form-control-sm" name="policy_medium_response" placeholder="&mdash;"></td>
                    <td><input type="number" min="0" class="form-control form-control-sm" name="policy_medium_resolution" placeholder="&mdash;"></td>
                </tr>
                <tr>
                    <td class="align-middle">High</td>
                    <td><input type="number" min="0" class="form-control form-control-sm" name="policy_high_response" placeholder="&mdash;"></td>
                    <td><input type="number" min="0" class="form-control form-control-sm" name="policy_high_resolution" placeholder="&mdash;"></td>
                </tr>
            </tbody>
        </table>

        <div class="form-group mt-3">
            <div class="form-check form-check form-switch">
                <input type="checkbox" class="form-check-input" name="policy_is_default" value="1" id="policyDefaultSwitch">
                <label class="form-check-label" for="policyDefaultSwitch">Set as default policy</label>
            </div>
        </div>
    </div>
    <div class="modal-footer">
        <button type="submit" name="add_sla_policy" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Create</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php
require_once '../../../includes/modal_footer.php';
