<?php
require_once '../../../includes/modal_header.php';
ob_start();
?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-info-circle me-2"></i>New Ticket Status</h5>
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
                    <span class="input-group-text"><i class="fa fa-fw fa-info-circle"></i></span>
                </div>
                <input type="text" class="form-control" name="name" placeholder="Ticket Status name" maxlength="200" required autofocus>
            </div>
        </div>

        <div class="form-group">
            <label>Color <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-paint-brush"></i></span>
                </div>
                <input type="color" class="form-control form-control-color flex-grow-0" style="width:4rem" name="color" value="#0d6efd" title="Pick a colour" required>
            </div>
        </div>

        <?php if (ticketStatusClosesColumnExists($mysqli)) { ?>
        <div class="form-group">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="closes_ticket" value="1" id="closes_ticket_add">
                <label class="form-check-label" for="closes_ticket_add"><strong>Counts as closed</strong></label>
            </div>
            <small class="text-muted">Tick this for a status such as <em>Unresolved</em>: the ticket is finished without being resolved. It leaves the open lists and every open count (tiles, sidebar, dashboards) exactly like Closed, but nothing is e-mailed to the client and no "closed" entry or rating request is created. Moving it back to an open status reopens it.</small>
        </div>
        <?php } ?>

        <div class="form-group">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="pauses_sla" value="1" id="pauses_sla_add">
                <label class="form-check-label" for="pauses_sla_add"><strong>Pauses the SLA clock</strong></label>
            </div>
            <small class="text-muted">Tick this for statuses where the ticket is waiting on someone else, such as Waiting on Customer, Employee or Vendor. While a ticket is in the status its SLA does not run, and it is never shown as breached because of the wait.</small>
        </div>

    </div>
    <div class="modal-footer">
        <button type="submit" name="add_ticket_status" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Create</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php
require_once '../../../includes/modal_footer.php';
