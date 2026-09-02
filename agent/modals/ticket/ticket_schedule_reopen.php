<?php
require_once '../../../includes/modal_header.php';

$ticket_id = intval($_GET['ticket_id']);

$sql = mysqli_query($mysqli, "SELECT * FROM tickets WHERE ticket_id = $ticket_id LIMIT 1");
$row = mysqli_fetch_assoc($sql);
$ticket_prefix = nullable_htmlentities($row['ticket_prefix']);
$ticket_number = intval($row['ticket_number']);
$client_id = intval($row['ticket_client_id']);
$ticket_closed_at = $row['ticket_closed_at'];
$ticket_reopen_at = $row['ticket_reopen_at'];

if ($client_id) {
    enforceClientAccess($client_id);
}

// Datetime-local inputs want "Y-m-dTH:i" - default to a week from now if
// nothing is scheduled yet.
$reopen_at_value = $ticket_reopen_at
    ? date('Y-m-d\TH:i', strtotime($ticket_reopen_at))
    : date('Y-m-d\TH:i', strtotime('+1 week'));

ob_start();

?>

<div class="modal-header bg-dark">
    <h5 class="modal-title">
        <i class="fa fa-fw fa-history me-2"></i>
        Schedule Reopen: <?php echo "$ticket_prefix$ticket_number"; ?>
    </h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal"><span>&times;</span></button>
</div>

<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="ticket_id" value="<?php echo $ticket_id; ?>">

    <div class="modal-body">

        <?php if (empty($ticket_closed_at)) { ?>
            <div class="alert alert-warning mb-3">This ticket isn't closed yet - resolve/close it first, then schedule the reopen.</div>
        <?php } ?>

        <p class="text-secondary">
            Automatically reopens this ticket on the date below - no need to remember to check back yourself
            (e.g. waiting on a vendor fix or an update to ship).
        </p>

        <div class="form-group">
            <label>Reopen on <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-clock"></i></span>
                </div>
                <input type="datetime-local" class="form-control" name="reopen_at" value="<?php echo $reopen_at_value; ?>" required <?php if (empty($ticket_closed_at)) { echo 'disabled'; } ?>>
            </div>
        </div>

    </div>
    <div class="modal-footer">
        <?php if ($ticket_reopen_at) { ?>
            <button type="submit" name="clear_ticket_reopen" class="btn btn-outline-danger" formnovalidate>
                <i class="fa fa-times me-2"></i>Clear Schedule
            </button>
        <?php } ?>
        <button type="submit" name="schedule_ticket_reopen" class="btn btn-primary text-bold" <?php if (empty($ticket_closed_at)) { echo 'disabled'; } ?>>
            <i class="fa fa-check me-2"></i><?php echo $ticket_reopen_at ? 'Update Schedule' : 'Schedule'; ?>
        </button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php
require_once '../../../includes/modal_footer.php';
