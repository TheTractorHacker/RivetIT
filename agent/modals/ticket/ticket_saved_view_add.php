<?php

require_once '../../../includes/modal_header.php';

// The current ticket dashboard filters, passed in via the querystring
$saved_query = $_SERVER['QUERY_STRING'] ?? '';
// Strip params that shouldn't be part of a saved view
parse_str($saved_query, $saved_query_params);
unset($saved_query_params['view']);
$saved_query = http_build_query($saved_query_params);

ob_start();

?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fa fa-fw fa-thumbtack me-2"></i>Save Current View</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
    <input type="hidden" name="query" value="<?= nullable_htmlentities($saved_query) ?>">

    <div class="modal-body">

        <div class="form-group">
            <label>Name <strong class="text-danger">*</strong></label>
            <input type="text" class="form-control" name="name" maxlength="100" placeholder="e.g. High Priority" required autofocus>
        </div>

        <div class="form-group">
            <label>Icon</label>
            <input type="text" class="form-control" name="icon" maxlength="50" value="fa-filter" placeholder="fa-filter">
            <small class="form-text text-muted">A Font Awesome icon class, e.g. <code>fa-fire</code>, <code>fa-star</code>, <code>fa-truck</code>.</small>
        </div>

        <?php if (lookupUserPermission("module_support") === 3) { ?>
        <div class="form-group form-check">
            <input type="checkbox" class="form-check-input" name="shared" value="1" id="savedViewShared">
            <label class="form-check-label" for="savedViewShared">Share with all agents</label>
        </div>
        <?php } ?>

    </div>
    <div class="modal-footer">
        <button type="submit" name="add_ticket_saved_view" class="btn btn-primary text-bold"><i class="fa fa-check me-2"></i>Save View</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php

require_once '../../../includes/modal_footer.php';
