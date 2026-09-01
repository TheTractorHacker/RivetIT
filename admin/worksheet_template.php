<?php

$sort = "worksheet_template_name";
$order = "ASC";

require_once "includes/inc_all_admin.php";

$sql = mysqli_query($mysqli, "SELECT SQL_CALC_FOUND_ROWS * FROM worksheet_templates WHERE worksheet_template_archived_at IS NULL AND (worksheet_template_name LIKE '%$q%') ORDER BY $sort $order LIMIT $record_from, $record_to");
$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

?>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-clipboard-list me-2"></i>Worksheet Templates</h3>
        <div class="card-tools">
            <button type="button" class="btn btn-primary ajax-modal" data-modal-url="modals/worksheet_template/worksheet_template_add.php" data-modal-size="lg"><i class="fas fa-plus me-2"></i>New Template</button>
        </div>
    </div>
    <div class="card-body">
        <form autocomplete="off">
            <div class="row">
                <div class="col-md-4">
                    <div class="input-group mb-3 mb-md-0">
                        <input type="search" class="form-control" name="q" value="<?php if(isset($q)){ echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search Worksheet Templates">
                        <div class="input-group-append">
                            <button class="btn btn-dark"><i class="fa fa-search"></i></button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
        <hr>
        <div class="table-responsive-sm">
            <table class="table table-striped table-borderless table-hover">
                <thead class="text-dark <?php if($num_rows[0] == 0) { echo "d-none"; } ?>">
                <tr>
                    <th>Template Name</th>
                    <th>Description</th>
                    <th class="text-center">Fields</th>
                    <th class="text-center">Action</th>
                </tr>
                </thead>
                <tbody>
                <?php
                while($row = mysqli_fetch_assoc($sql)){
                    $tmpl_id = intval($row['worksheet_template_id']);
                    $tmpl_name = nullable_htmlentities($row['worksheet_template_name']);
                    $tmpl_desc = nullable_htmlentities($row['worksheet_template_description']);
                    $field_count = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM worksheet_template_fields WHERE field_template_id = $tmpl_id"))[0]);
                ?>
                    <tr>
                        <td><strong><?= $tmpl_name ?></strong></td>
                        <td><?= $tmpl_desc ?></td>
                        <td class="text-center"><?= $field_count ?></td>
                        <td class="text-center">
                            <a href="worksheet_template_details.php?id=<?= $tmpl_id ?>" class="btn btn-sm btn-secondary"><i class="fas fa-edit me-1"></i>Edit</a>
                            <a href="post.php?delete_worksheet_template=<?= $tmpl_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>" class="btn btn-sm btn-danger confirm-link"><i class="fas fa-trash"></i></a>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
        <?php if($num_rows[0] == 0) { echo "<p class='text-center text-secondary mt-3'>No worksheet templates yet. Create one to get started.</p>"; } ?>
    </div>
</div>

<?php require_once "../includes/footer.php"; ?>
