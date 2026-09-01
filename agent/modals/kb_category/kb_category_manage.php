<?php

require_once '../../../includes/modal_header.php';

$sql_categories = mysqli_query($mysqli, "SELECT * FROM kb_categories WHERE kb_category_archived_at IS NULL ORDER BY kb_category_name ASC");

ob_start();

?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fa fa-fw fa-folder me-2"></i>Manage Knowledge Base Categories</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<div class="modal-body">

    <button type="button" class="btn btn-primary btn-sm mb-3 ajax-modal" data-modal-url="modals/kb_category/kb_category_add.php">
        <i class="fas fa-plus me-2"></i>New Category
    </button>

    <?php if (mysqli_num_rows($sql_categories) == 0) { ?>
        <p class="text-secondary text-center">No categories yet.</p>
    <?php } else { ?>
    <ul class="list-group">
        <?php while ($row = mysqli_fetch_assoc($sql_categories)) {
            $kb_category_id = intval($row['kb_category_id']);
            $kb_category_name = nullable_htmlentities($row['kb_category_name']);
            $kb_category_parent_id = intval($row['kb_category_parent_id']);
        ?>
        <li class="list-group-item d-flex align-items-center justify-content-between">
            <span>
                <?php if ($kb_category_parent_id) { ?><i class="fas fa-fw fa-level-up-alt fa-rotate-90 text-muted me-1"></i><?php } ?>
                <i class="fas fa-fw fa-folder text-secondary me-1"></i><?= $kb_category_name ?>
            </span>
            <div class="dropdown dropleft text-center">
                <button class="btn btn-secondary btn-sm" data-bs-toggle="dropdown" data-boundary="window">
                    <i class="fas fa-ellipsis-h"></i>
                </button>
                <div class="dropdown-menu">
                    <a class="dropdown-item ajax-modal" href="#" data-modal-url="modals/kb_category/kb_category_edit.php?id=<?= $kb_category_id ?>">
                        <i class="fas fa-fw fa-edit me-2"></i>Edit
                    </a>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item text-danger text-bold confirm-link" href="post.php?delete_kb_category=<?= $kb_category_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                        <i class="fas fa-fw fa-trash me-2"></i>Delete
                    </a>
                </div>
            </div>
        </li>
        <?php } ?>
    </ul>
    <?php } ?>

</div>
<div class="modal-footer">
    <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Close</button>
</div>

<?php

require_once '../../../includes/modal_footer.php';
