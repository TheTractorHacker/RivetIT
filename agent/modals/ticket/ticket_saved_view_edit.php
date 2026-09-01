<?php

require_once '../../../includes/modal_header.php';

$ticket_saved_view_id = intval($_GET['id']);

$view = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM ticket_saved_views WHERE ticket_saved_view_id = $ticket_saved_view_id LIMIT 1"));

ob_start();

?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fa fa-fw fa-thumbtack me-2"></i>Rename Saved View</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
    <input type="hidden" name="ticket_saved_view_id" value="<?= $ticket_saved_view_id ?>">

    <div class="modal-body">

        <div class="form-group">
            <label>Name <strong class="text-danger">*</strong></label>
            <input type="text" class="form-control" name="name" maxlength="100" value="<?= nullable_htmlentities($view['ticket_saved_view_name']) ?>" required autofocus>
        </div>

        <div class="form-group">
            <label>Icon</label>
            <input type="text" class="form-control" name="icon" maxlength="50" value="<?= nullable_htmlentities($view['ticket_saved_view_icon']) ?>" placeholder="fa-filter">
            <small class="form-text text-muted">A Font Awesome icon class, e.g. <code>fa-fire</code>, <code>fa-star</code>, <code>fa-truck</code>.</small>
        </div>

    </div>
    <div class="modal-footer">
        <button type="submit" name="edit_ticket_saved_view" class="btn btn-primary text-bold"><i class="fa fa-check me-2"></i>Save</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php

require_once '../../../includes/modal_footer.php';
