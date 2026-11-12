<?php

require_once '../../../includes/modal_header.php';

$ticket_status_id = intval($_GET['id']);

$sql = mysqli_query($mysqli, "SELECT * FROM ticket_statuses WHERE ticket_status_id = $ticket_status_id LIMIT 1");
$row = mysqli_fetch_assoc($sql);
$ticket_status_name = nullable_htmlentities($row['ticket_status_name']);
$ticket_status_color = nullable_htmlentities($row['ticket_status_color']);
$ticket_status_pauses_sla = intval($row['ticket_status_pauses_sla'] ?? 0);
$ticket_status_pauses_sla_available = array_key_exists('ticket_status_pauses_sla', $row);
$ticket_status_parked = intval($row['ticket_status_parked'] ?? 0);
$ticket_status_parked_available = array_key_exists('ticket_status_parked', $row);
$ticket_status_order = intval($row['ticket_status_order']);
$ticket_status_active = intval($row['ticket_status_active']);

// Generate the HTML form content using output buffering.
ob_start();
?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-info-circle me-2"></i>Editing Ticket Status: <strong><?php echo $ticket_status_name; ?></strong></h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="ticket_status_id" value="<?php echo $ticket_status_id; ?>">

    <div class="modal-body">

        <div class="form-group">
            <label>Name <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-tag"></i></span>
                </div>
                <input type="text" class="form-control" name="name" maxlength="200" value="<?php echo $ticket_status_name; ?>" required <?php if ($ticket_status_id <= 5) { echo "readonly"; } ?>>
            </div>
        </div>

        <div class="form-group">
            <label>Color <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-paint-brush"></i></span>
                </div>
                <input type="color" class="form-control form-control-color flex-grow-0" style="width:4rem" title="Pick a colour" name="color" value="<?php echo $ticket_status_color; ?>" required>
            </div>
        </div>

        <?php if ($ticket_status_pauses_sla_available) { ?>
        <div class="form-group">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="pauses_sla" value="1" id="pauses_sla_edit" <?php if ($ticket_status_pauses_sla) { echo "checked"; } ?>>
                <label class="form-check-label" for="pauses_sla_edit"><strong>Pauses the SLA clock</strong></label>
            </div>
            <small class="text-muted">While a ticket is in this status its SLA does not run and it is not shown as breached because of the wait. Open tickets already in the status are updated when you save.</small>
        </div>
        <?php } ?>

        <?php if ($ticket_status_parked_available) { ?>
        <div class="form-group">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="parked" value="1" id="parked_edit" <?php if ($ticket_status_parked) { echo "checked"; } ?>>
                <label class="form-check-label" for="parked_edit"><strong>Parked (hidden from dashboards and metrics)</strong></label>
            </div>
            <small class="text-muted">The ticket stays unresolved, but it disappears from the dashboards, the default ticket list and board, the open-ticket counts and the overdue and SLA-breach alerts. It is still found by search, on the client, contact, asset and project ticket tabs, in the &quot;All&quot; view and with &quot;Show parked&quot;. <strong>Parked does not pause the SLA clock</strong>: a parked ticket whose clock keeps running will still breach, so normally tick &quot;Pauses the SLA clock&quot; as well.</small>
        </div>
        <?php } ?>

        <div class="form-group">
            <label>Order</label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-sort-numeric-down"></i></span>
                </div>
                <input type="number" class="form-control" name="order" placeholder="Leave blank for no order" value="<?php echo $ticket_status_order; ?>">
            </div>
        </div>

        <div class="form-group">
            <label>Status <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-info-circle"></i></span>
                </div>
                <select class="form-control select2" name="status" required>
                    <option <?php if ($ticket_status_active == 1) { echo "selected"; } ?> value="1">Active</option>
                    <option <?php if ($ticket_status_active == 0) { echo "selected"; } ?> value="0" <?php if ($ticket_status_id <= 5) { echo "disabled"; } ?>>Inactive</option>
                </select>
            </div>
        </div>

    </div>
    <div class="modal-footer">
        <button type="submit" name="edit_ticket_status" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Save</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php
require_once '../../../includes/modal_footer.php';
