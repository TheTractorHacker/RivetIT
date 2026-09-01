<?php

require_once '../../../includes/modal_header.php';

$project_id = intval($_GET['project_id']);

$sql = mysqli_query($mysqli, "SELECT project_name FROM projects WHERE project_id = $project_id LIMIT 1");
$row = mysqli_fetch_assoc($sql);
$project_name = nullable_htmlentities($row['project_name']);

// Next order value (append to end)
$order_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COALESCE(MAX(milestone_order), 0) + 1 AS next_order FROM project_milestones WHERE milestone_project_id = $project_id"));
$next_order = intval($order_row['next_order']);

// Generate the HTML form content using output buffering.
ob_start();
?>

<div class="modal-header bg-dark">
    <h5 class="modal-title">
        <i class="fas fa-fw fa-flag-checkered me-2"></i>New Milestone: <strong><?php echo $project_name; ?></strong>
    </h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
    <div class="modal-body">
        <div class="form-group">
            <label>Milestone Name <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-flag-checkered"></i></span>
                </div>
                <input type="text" class="form-control" name="name" placeholder="Milestone Name" maxlength="255" required autofocus>
            </div>
        </div>
        <div class="form-group">
            <label>Description</label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-align-left"></i></span>
                </div>
                <textarea class="form-control" name="description" rows="2" placeholder="Description"></textarea>
            </div>
        </div>
        <div class="form-group">
            <label>Due Date</label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-calendar"></i></span>
                </div>
                <input type="date" class="form-control" name="due">
            </div>
        </div>
        <div class="form-group">
            <label>Order</label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-sort-numeric-down"></i></span>
                </div>
                <input type="number" class="form-control" name="order" value="<?php echo $next_order; ?>" min="0">
            </div>
        </div>
    </div>
    <div class="modal-footer">
        <button type="submit" name="add_milestone" class="btn btn-primary text-bold">
            <i class="fas fa-check me-2"></i>Create
        </button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">
            <i class="fa fa-times me-2"></i>Cancel
        </button>
    </div>
</form>

<?php

require_once '../../../includes/modal_footer.php';
