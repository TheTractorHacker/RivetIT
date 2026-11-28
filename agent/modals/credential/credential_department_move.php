<?php

require_once '../../../includes/modal_header.php';

enforceUserPermission('module_credential', 2);

// Selected IDs: from the bulk action (credential_ids[]) or a single row
$credential_ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_GET['credential_ids'] ?? [])))));
$total = count($credential_ids);

$from_name = '';
if ($total === 1) {
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT c.credential_name, c.credential_client_id, cl.client_name FROM credentials c LEFT JOIN clients cl ON cl.client_id = c.credential_client_id WHERE c.credential_id = $credential_ids[0]"));
    if ($row) {
        enforceClientAccess(intval($row['credential_client_id']));
        $from_name = nullable_htmlentities($row['client_name'] ?? '');
    }
}

$sql_depts = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_lead = 0 AND client_archived_at IS NULL $access_permission_query ORDER BY client_name ASC");

ob_start();
?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fa fa-fw fa-folder-open me-2"></i>Move <strong><?= $total ?></strong> credential<?= $total === 1 ? '' : 's' ?> to another department</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal"><span>&times;</span></button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <?php foreach ($credential_ids as $id) { ?>
        <input type="hidden" name="credential_ids[]" value="<?= $id ?>">
    <?php } ?>
    <div class="modal-body">
        <?php if ($from_name !== '') { ?><p class="text-muted mb-3">Currently in <strong><?= $from_name ?></strong>.</p><?php } ?>
        <div class="form-group">
            <label>Move to department</label>
            <div class="input-group">
                <span class="input-group-text"><i class="fa fa-fw fa-folder"></i></span>
                <select class="form-control select2" name="department_id" required>
                    <option value="">- Choose a department -</option>
                    <?php while ($d = mysqli_fetch_assoc($sql_depts)) { ?>
                        <option value="<?= intval($d['client_id']) ?>"><?= nullable_htmlentities($d['client_name']) ?></option>
                    <?php } ?>
                </select>
            </div>
        </div>
        <div class="alert alert-info mt-3 mb-0 small">
            The credential lands at the top level of the new department. Links to a person, device or vendor in the old department are cleared, since those belong to that department.
        </div>
    </div>
    <div class="modal-footer">
        <button type="submit" name="move_credentials_department" class="btn btn-primary text-bold" <?= $total ? '' : 'disabled' ?>><i class="fa fa-check me-2"></i>Move</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>

<script src="/agent/js/modal_form_ux.js"></script>

<?php
require_once '../../../includes/modal_footer.php';
