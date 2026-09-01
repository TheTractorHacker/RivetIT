<?php

require_once '../../../includes/modal_header.php';

$ticket_id = intval($_GET['id']);

$sql = mysqli_query($mysqli, "SELECT * FROM tickets
    LEFT JOIN clients ON client_id = ticket_client_id
    WHERE ticket_id = $ticket_id
    LIMIT 1"
);

$row = mysqli_fetch_assoc($sql);
$ticket_prefix = nullable_htmlentities($row['ticket_prefix']);
$ticket_number = intval($row['ticket_number']);
$ticket_delivery_method = nullable_htmlentities($row['ticket_delivery_method']);
// Convenience default: if this ticket was scheduled as an appointment (the
// existing Schedule feature already asks Remote vs Onsite) but the delivery
// method hasn't been explicitly set yet, pre-select to match it rather than
// making the agent re-answer the same question. Still requires an explicit
// Save - never auto-applied - so it never silently counts against a client's
// allowance without a person confirming it.
if ($ticket_delivery_method === null && !empty($row['ticket_schedule'])) {
    $ticket_delivery_method = intval($row['ticket_onsite']) ? 'Onsite' : 'Remote';
}
$client_id = intval($row['ticket_client_id']);
enforceClientAccess($client_id);

// Generate the HTML form content using output buffering.
ob_start();

?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fa fa-fw fa-route me-2"></i>Editing delivery method: <strong><?php echo "$ticket_prefix$ticket_number"; ?></strong></h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="ticket_id" value="<?php echo $ticket_id; ?>">
    <input type="hidden" name="client_id" value="<?php echo $client_id; ?>">

    <div class="modal-body">

        <div class="form-group">
            <label>Delivery Method <small class="text-secondary">(which included-issues allowance this ticket counts against, if any)</small></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-route"></i></span>
                </div>
                <select class="form-control select2" name="delivery_method">
                    <option value="" <?php if (!$ticket_delivery_method) { echo "selected"; } ?>>&mdash; Not set &mdash;</option>
                    <option value="Remote" <?php if ($ticket_delivery_method == 'Remote') { echo "selected"; } ?>>Remote</option>
                    <option value="Onsite" <?php if ($ticket_delivery_method == 'Onsite') { echo "selected"; } ?>>Onsite</option>
                </select>
            </div>
        </div>

    </div>

    <div class="modal-footer">
        <button type="submit" name="edit_ticket_delivery_method" class="btn btn-primary text-bold"><i class="fa fa-check me-2"></i>Save</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>

</form>

<?php

require_once '../../../includes/modal_footer.php';
