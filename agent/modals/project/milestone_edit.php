<?php

require_once '../../../includes/modal_header.php';

$milestone_id = intval($_GET['id']);

$sql = mysqli_query($mysqli, "SELECT * FROM project_milestones WHERE milestone_id = $milestone_id LIMIT 1");
$row = mysqli_fetch_assoc($sql);
$project_id = intval($row['milestone_project_id']);
$milestone_name = nullable_htmlentities($row['milestone_name']);
$milestone_description = nullable_htmlentities($row['milestone_description']);
$milestone_due = nullable_htmlentities($row['milestone_due']);
$milestone_order = intval($row['milestone_order']);

// Resolve client from the milestone's project for access control
$client_id = intval(getFieldById('projects', $project_id, 'project_client_id'));
if ($client_id) {
    enforceClientAccess($client_id);
}

// Generate the HTML form content using output buffering.
ob_start();
?>

<div class="modal-header bg-dark">
    <h5 class="modal-title">
        <i class="fas fa-fw fa-flag-checkered me-2"></i>Editing Milestone: <strong><?php echo $milestone_name; ?></strong>
    </h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="milestone_id" value="<?php echo $milestone_id; ?>">
    <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
    <div class="modal-body">
        <div class="form-group">
            <label>Milestone Name <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-flag-checkered"></i></span>
                </div>
                <input type="text" class="form-control" name="name" placeholder="Milestone Name" maxlength="255" value="<?php echo $milestone_name; ?>" required autofocus>
            </div>
        </div>
        <div class="form-group">
            <label>Description</label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-align-left"></i></span>
                </div>
                <textarea class="form-control" name="description" rows="2" placeholder="Description"><?php echo $milestone_description; ?></textarea>
            </div>
        </div>
        <div class="form-group">
            <label>Due Date</label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-calendar"></i></span>
                </div>
                <input type="date" class="form-control" name="due" value="<?php echo $milestone_due; ?>">
            </div>
        </div>
        <div class="form-group">
            <label>Order</label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-sort-numeric-down"></i></span>
                </div>
                <input type="number" class="form-control" name="order" value="<?php echo $milestone_order; ?>" min="0">
            </div>
        </div>
    </div>
    <div class="modal-footer">
        <button type="submit" name="edit_milestone" class="btn btn-primary text-bold">
            <i class="fas fa-check me-2"></i>Save
        </button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">
            <i class="fa fa-times me-2"></i>Cancel
        </button>
    </div>
</form>

<?php

require_once '../../../includes/modal_footer.php';
