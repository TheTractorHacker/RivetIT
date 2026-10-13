<?php

require_once '../../../includes/modal_header.php';
require_once __DIR__ . '/../../../includes/icon_picker.php';
require_once __DIR__ . '/../../includes/ticket_view_filters.php';

$ticket_saved_view_id = intval($_GET['id']);

enforceUserPermission('module_support', 2);

// Only a view this person may edit: their own, or (administrators) one shared with everyone. Never another agent's private view.
$_edit_scope = (lookupUserPermission("module_support") === 3)
    ? "(ticket_saved_view_user_id = 0 OR ticket_saved_view_user_id = " . intval($session_user_id) . ")"
    : "ticket_saved_view_user_id = " . intval($session_user_id);
$view = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM ticket_saved_views WHERE ticket_saved_view_id = $ticket_saved_view_id AND $_edit_scope AND ticket_saved_view_archived_at IS NULL LIMIT 1"));
if (!$view) {
    http_response_code(404);
    exit('That view is not available.');
}

ob_start();

?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fa fa-fw fa-thumbtack me-2"></i>Edit Saved View</h5>
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
            <?php iconPickerField('icon', (string) $view['ticket_saved_view_icon'], 'fa-filter'); ?>
        </div>

        <hr>
        <h6 class="mb-3"><i class="fa fa-fw fa-filter me-2"></i>Filters this view uses</h6>
        <?php ticketViewFilterFields($mysqli, ticketViewParseQuery((string) $view['ticket_saved_view_query']), 'tvfe'); ?>
        <small class="form-text text-muted">Currently: <?= nullable_htmlentities(ticketViewDescribe($mysqli, (string) $view['ticket_saved_view_query'])) ?></small>

        <?php if (ticketViewDefaultsAvailable($mysqli)) {
            $_def = ticketViewDefaultIds($mysqli, (int) $session_user_id);
            $_is_shared_view = (int) $view['ticket_saved_view_user_id'] === 0;
        ?>
        <hr>
        <input type="hidden" name="default_present" value="1">
        <div class="form-group form-check">
            <input type="checkbox" class="form-check-input" name="make_default" value="1" id="savedViewDefault" <?= $_def['personal'] === $ticket_saved_view_id ? 'checked' : '' ?>>
            <label class="form-check-label" for="savedViewDefault">Make this my default view <small class="text-muted">(Tickets opens with it when you have not chosen a filter)</small></label>
        </div>
        <?php if ($_is_shared_view && lookupUserPermission("module_support") === 3) { ?>
        <div class="form-group form-check">
            <input type="checkbox" class="form-check-input" name="shared_default" value="1" id="savedViewSharedDefault" <?= $_def['shared'] === $ticket_saved_view_id ? 'checked' : '' ?>>
            <label class="form-check-label" for="savedViewSharedDefault">Default for everyone who has no personal default</label>
        </div>
        <?php } ?>
        <?php } ?>

    </div>
    <div class="modal-footer">
        <button type="submit" name="edit_ticket_saved_view" class="btn btn-primary text-bold"><i class="fa fa-check me-2"></i>Save</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php

require_once '../../../includes/modal_footer.php';
