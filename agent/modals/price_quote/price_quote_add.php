<?php

require_once '../../../includes/modal_header.php';

$client_id = intval($_GET['client_id'] ?? 0);

enforceUserPermission('module_client', 2);
if ($client_id) {
    enforceClientAccess($client_id);
}

ob_start();

?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-file-invoice-dollar me-2"></i>New Price Quote</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

    <div class="modal-body">

        <?php if ($client_id) { ?>
            <input type="hidden" name="client_id" value="<?= $client_id ?>">
        <?php } else { ?>
            <div class="form-group">
                <label>Department <strong class="text-danger">*</strong></label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-user"></i></span>
                    </div>
                    <select class="form-control select2" name="client_id" data-live-search="true" required>
                        <option value="">Select a Department</option>
                        <?php
                        // Department-restricted users only get the departments they can access
                        // ($access_permission_query from includes/load_user_session.php).
                        $sql_clients = mysqli_query($mysqli, "SELECT clients.client_id, client_name FROM clients
                            WHERE client_archived_at IS NULL
                            $access_permission_query
                            ORDER BY client_name ASC");
                        while ($row = mysqli_fetch_assoc($sql_clients)) {
                            $option_client_id = intval($row['client_id']);
                            $option_client_name = nullable_htmlentities($row['client_name']);
                            ?>
                            <option value="<?= $option_client_id ?>"><?= $option_client_name ?></option>
                        <?php } ?>
                    </select>
                </div>
            </div>
        <?php } ?>

        <div class="form-group">
            <label>Title <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-heading"></i></span>
                </div>
                <input type="text" class="form-control" name="title" placeholder="e.g. New Workstation Rollout" maxlength="255" required autofocus>
            </div>
        </div>

        <div class="form-group">
            <label>Description</label>
            <textarea class="form-control" rows="3" name="description" placeholder="What this quote covers (optional)"></textarea>
        </div>

    </div>
    <div class="modal-footer">
        <button type="submit" name="add_price_quote" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Create</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php

require_once '../../../includes/modal_footer.php';
