<?php

require_once '../../../includes/modal_header.php';

$network_drive_id = intval($_GET['id']);

$sql = mysqli_query($mysqli, "SELECT * FROM network_drives WHERE network_drive_id = $network_drive_id LIMIT 1");

$row = mysqli_fetch_assoc($sql);
$client_id = intval($row['network_drive_client_id']);
enforceUserPermission('module_support', 2);
enforceClientAccess($client_id);

$network_drive_name = nullable_htmlentities($row['network_drive_name']);
$network_drive_letter = nullable_htmlentities($row['network_drive_letter']);
$network_drive_path = nullable_htmlentities($row['network_drive_path']);
$network_drive_purpose = nullable_htmlentities($row['network_drive_purpose']);
$network_drive_notes = nullable_htmlentities($row['network_drive_notes']);

ob_start();

?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-hdd me-2"></i>Edit Network Drive</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="network_drive_id" value="<?= $network_drive_id ?>">

    <div class="modal-body">

        <div class="form-group">
            <label>Name <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-hdd"></i></span>
                </div>
                <input type="text" class="form-control" name="name" value="<?php echo $network_drive_name; ?>" placeholder="e.g. Accounting Share" maxlength="200" required autofocus>
            </div>
        </div>

        <div class="form-group">
            <label>Drive Letter</label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-font"></i></span>
                </div>
                <select class="form-control select2" name="letter">
                    <option value="">Drive Letter</option>
                    <?php foreach (range('A', 'Z') as $drive_letter_option) { ?>
                        <option value="<?php echo $drive_letter_option; ?>:" <?php if ($network_drive_letter === "$drive_letter_option:") { echo 'selected'; } ?> <?php if ($drive_letter_option === 'C') { echo 'disabled'; } ?>><?php echo $drive_letter_option; ?>: <?php if ($drive_letter_option === 'C') { echo '(system drive)'; } ?></option>
                    <?php } ?>
                </select>
            </div>
        </div>

        <div class="form-group">
            <label>Path</label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-share-alt"></i></span>
                </div>
                <input type="text" class="form-control" name="path" value="<?php echo $network_drive_path; ?>" placeholder="e.g. \\SERVER\ShareName" maxlength="500">
            </div>
        </div>

        <div class="form-group">
            <label>Purpose</label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-align-left"></i></span>
                </div>
                <input type="text" class="form-control" name="purpose" value="<?php echo $network_drive_purpose; ?>" placeholder="What this drive is for" maxlength="200">
            </div>
        </div>

        <div class="form-group">
            <label>Notes</label>
            <textarea class="form-control" rows="6" placeholder="Enter some notes" name="notes"><?php echo $network_drive_notes; ?></textarea>
        </div>

    </div>
    <div class="modal-footer">
        <button type="submit" name="edit_network_drive" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Save</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php

require_once '../../../includes/modal_footer.php';
