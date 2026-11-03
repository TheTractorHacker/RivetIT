<?php

require_once '../../../includes/modal_header.php';

enforceUserPermission('module_client', 2);

$contact_id = intval($_GET['id'] ?? 0);

$sql = mysqli_query($mysqli, "SELECT contact_id, contact_name, contact_client_id, contact_primary, client_name FROM contacts LEFT JOIN clients ON client_id = contact_client_id WHERE contact_id = $contact_id LIMIT 1");
$row = mysqli_fetch_assoc($sql);
if (!$row) {
    exit('That person no longer exists.');
}
$contact_name = nullable_htmlentities($row['contact_name']);
$client_id = intval($row['contact_client_id']);
$client_name = nullable_htmlentities($row['client_name']);
$contact_primary = intval($row['contact_primary']);
enforceClientAccess($client_id);

$asset_count = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM assets WHERE asset_contact_id = $contact_id AND asset_client_id = $client_id AND asset_archived_at IS NULL"))[0] ?? 0);
$license_count = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM software_contacts sc JOIN software s ON s.software_id = sc.software_id WHERE sc.contact_id = $contact_id AND s.software_client_id = $client_id"))[0] ?? 0);

ob_start();

?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fa fa-fw fa-people-arrows me-2"></i>Move <strong><?= $contact_name ?></strong> to another department</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="contact_id" value="<?= $contact_id ?>">
    <div class="modal-body">

        <p class="text-secondary">Currently in <strong><?= $client_name ?></strong>. The person moves with their record and history. Tickets, notes and credentials stay with the department they were created in.</p>

        <div class="form-group">
            <label>New department <strong class="text-danger">*</strong></label>
            <select class="form-control select2" name="new_client_id" required>
                <option value="">- Choose a department -</option>
                <?php
                $sql_clients = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL AND client_id != $client_id $access_permission_query ORDER BY client_name ASC");
                while ($c = mysqli_fetch_assoc($sql_clients)) { ?>
                    <option value="<?= intval($c['client_id']) ?>"><?= nullable_htmlentities($c['client_name']) ?></option>
                <?php } ?>
            </select>
        </div>

        <div class="form-group">
            <div class="form-check">
                <input type="checkbox" class="form-check-input" id="moveUnassign<?= $contact_id ?>" name="unassign_items" value="1" checked>
                <label class="form-check-label" for="moveUnassign<?= $contact_id ?>">Unassign their <?= $asset_count ?> device(s) and <?= $license_count ?> licence seat(s) in <?= $client_name ?> (they stay in <?= $client_name ?>)</label>
            </div>
        </div>

        <ul class="text-secondary small mb-0">
            <li>Their location is kept only if it is also a site of the new department.</li>
            <?php if ($contact_primary) { ?><li>They stop being the primary contact of <?= $client_name ?>.</li><?php } ?>
            <li>If Employee workflows are on, this raises the <code>employee.transferred</code> event.</li>
        </ul>

    </div>

    <div class="modal-footer">
        <button type="submit" name="move_contact_department" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Move</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php
require_once '../../../includes/modal_footer.php';
