<?php

require_once "includes/inc_all_admin.php";

$template_id = intval($_GET['id']);

$sql = mysqli_query($mysqli, "SELECT * FROM worksheet_templates WHERE worksheet_template_id = $template_id LIMIT 1");
if (mysqli_num_rows($sql) == 0) {
    header("Location: worksheet_template.php");
    exit;
}
$row = mysqli_fetch_assoc($sql);
$tmpl_name = nullable_htmlentities($row['worksheet_template_name']);
$tmpl_desc = nullable_htmlentities($row['worksheet_template_description']);

$sql_fields = mysqli_query($mysqli, "SELECT * FROM worksheet_template_fields WHERE field_template_id = $template_id ORDER BY field_order ASC");

?>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-clipboard-list me-2"></i><?= $tmpl_name ?></h3>
        <div class="card-tools">
            <button type="button" class="btn btn-primary ajax-modal" data-modal-url="modals/worksheet_template/worksheet_field_add.php?template_id=<?= $template_id ?>"><i class="fas fa-plus me-2"></i>Add Field</button>
            <a href="worksheet_template.php" class="btn btn-secondary ms-2"><i class="fas fa-arrow-left me-1"></i>Back</a>
        </div>
    </div>
    <div class="card-body">

        <!-- Template Info -->
        <div class="row mb-3">
            <div class="col-md-6">
                <form action="post.php" method="post" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="worksheet_template_id" value="<?= $template_id ?>">
                    <div class="form-group">
                        <label>Template Name</label>
                        <input type="text" class="form-control" name="worksheet_template_name" value="<?= $tmpl_name ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <input type="text" class="form-control" name="worksheet_template_description" value="<?= $tmpl_desc ?>">
                    </div>
                    <button type="submit" name="edit_worksheet_template" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save</button>
                </form>
            </div>
        </div>

        <hr>
        <h5>Fields <small class="text-muted ms-2" style="font-size:13px;"><i class="fas fa-grip-vertical me-1"></i>Drag rows to reorder</small></h5>
        <div class="table-responsive">
            <table class="table table-sm table-bordered table-hover" id="fields-table">
                <thead>
                <tr>
                    <th style="width:32px;"></th>
                    <th>Field Name</th>
                    <th>Type</th>
                    <th>Options</th>
                    <th>Required</th>
                    <th class="text-center">Action</th>
                </tr>
                </thead>
                <tbody id="fields-sortable">
                <?php
                while ($frow = mysqli_fetch_assoc($sql_fields)) {
                    $fid = intval($frow['field_id']);
                    $fname = nullable_htmlentities($frow['field_name']);
                    $ftype = nullable_htmlentities($frow['field_type']);
                    $fopts = nullable_htmlentities($frow['field_options']);
                    $freq = intval($frow['field_required']);
                ?>
                <tr data-id="<?= $fid ?>">
                    <td class="text-center text-muted" style="cursor:grab;"><i class="fas fa-grip-vertical"></i></td>
                    <td><?= $fname ?></td>
                    <td><span class="badge text-bg-secondary"><?= $ftype ?></span></td>
                    <td><small><?= $fopts ?></small></td>
                    <td><?= $freq ? '<i class="fas fa-check text-success"></i>' : '-' ?></td>
                    <td class="text-center">
                        <a href="modals/worksheet_template/worksheet_field_edit.php?field_id=<?= $fid ?>" class="btn btn-sm btn-secondary ajax-modal"><i class="fas fa-edit"></i></a>
                        <a href="post.php?delete_worksheet_field=<?= $fid ?>&template_id=<?= $template_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>" class="btn btn-sm btn-danger confirm-link"><i class="fas fa-trash"></i></a>
                    </td>
                </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="../plugins/SortableJS/Sortable.min.js"></script>
<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
Sortable.create(document.getElementById('fields-sortable'), {
    handle: 'td:first-child',
    animation: 150,
    onEnd: function() {
        var order = [];
        document.querySelectorAll('#fields-sortable tr[data-id]').forEach(function(tr) {
            order.push(tr.getAttribute('data-id'));
        });
        fetch('post.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'reorder_worksheet_fields=1&template_id=<?= $template_id ?>&order=' + encodeURIComponent(JSON.stringify(order)) + '&csrf_token=<?= $_SESSION['csrf_token'] ?>'
        });
    }
});
</script>

<?php require_once "../includes/footer.php"; ?>
