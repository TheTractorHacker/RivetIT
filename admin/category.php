<?php

// Default Column Sortby Filter
$sort = "category_name";
$order = "ASC";

require_once "includes/inc_all_admin.php";


if (isset($_GET['category'])) {
    $category = sanitizeInput($_GET['category']);
} else {
    $category = "Expense";
}

if ($category === 'Ticket') {
    $sql = mysqli_query($mysqli, "SELECT SQL_CALC_FOUND_ROWS * FROM categories WHERE category_name LIKE '%$q%' AND category_type = 'Ticket' AND category_$archive_query ORDER BY category_parent ASC, category_name ASC LIMIT $record_from, $record_to");
} else {
    $sql = mysqli_query($mysqli, "SELECT SQL_CALC_FOUND_ROWS * FROM categories WHERE category_name LIKE '%$q%' AND category_type = '$category' AND category_$archive_query ORDER BY $sort $order LIMIT $record_from, $record_to");
}
$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

?>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fa fa-fw fa-list-ul me-2"></i>
            <?= nullable_htmlentities(ucwords(str_replace('_', ' ', $category))); ?> Categories
        </h3>
        <?php
            if (!isset($_GET['archived'])) {
        ?>
        <div class="card-tools">
            <button type="button" class="btn btn-primary ajax-modal" data-modal-url="modals/category/category_add.php?category=<?= nullable_htmlentities($category) ?>"><i
                    class="fas fa-plus me-2"></i>New <?= nullable_htmlentities(ucwords(str_replace('_', ' ', $category))); ?> Category</button>
        </div>
        <?php
            }
        ?>
    </div>
    <div class="card-body">
        <form autocomplete="off">
            <input type="hidden" name="category" value="<?php echo nullable_htmlentities($category); ?>">
            <div class="row">
                <div class="col-sm-4 mb-2">
                    <div class="input-group">
                        <input type="search" class="form-control" name="q"
                            value="<?php if (isset($q)) {
                                echo stripslashes(nullable_htmlentities($q));
                            } ?>"
                            placeholder="Search <?= nullable_htmlentities(ucwords(str_replace('_', ' ', $category))); ?> Categories ">
                        <div class="input-group-append">
                            <button class="btn btn-primary"><i class="fa fa-search"></i></button>
                        </div>
                    </div>
                </div>
                <div class="col-sm-8">
                    <div class="btn-group float-end">
                        <a href="?category=Expense"
                            class="btn <?php if ($category == 'Expense') {
                                echo 'btn-primary';
                            } else {
                                echo 'btn-default';
                            } ?>">Expense</a>
                        <a href="?category=Income"
                            class="btn <?php if ($category == 'Income') {
                                echo 'btn-primary';
                            } else {
                                echo 'btn-default';
                            } ?>">Income</a>
                        <a href="?category=Referral"
                            class="btn <?php if ($category == 'Referral') {
                                echo 'btn-primary';
                            } else {
                                echo 'btn-default';
                            } ?>">Referral</a>
                        <a href="?category=Ticket"
                           class="btn <?php if ($category == 'Ticket') {
                               echo 'btn-primary';
                           } else {
                               echo 'btn-default';
                           } ?>">Ticket</a>
                        <a href="?category=network_interface"
                           class="btn <?php if ($category == 'network_interface') {
                               echo 'btn-primary';
                           } else {
                               echo 'btn-default';
                           } ?>">Network Interface</a>
                        <a href="?category=asset_status"
                           class="btn <?php if ($category == 'asset_status') {
                               echo 'btn-primary';
                           } else {
                               echo 'btn-default';
                           } ?>">Asset Status</a>
                        <a href="?category=software_type"
                           class="btn <?php if ($category == 'software_type') {
                               echo 'btn-primary';
                           } else {
                               echo 'btn-default';
                           } ?>">Software Type</a>
                        <a href="?category=rack_type"
                           class="btn <?php if ($category == 'rack_type') {
                               echo 'btn-primary';
                           } else {
                               echo 'btn-default';
                           } ?>">Rack Type</a>
                        <a href="?category=contact_note_type"
                           class="btn <?php if ($category == 'contact_note_type') {
                               echo 'btn-primary';
                           } else {
                               echo 'btn-default';
                           } ?>">Contact Note Type</a>
                        <a href="?<?php echo $url_query_strings_sort ?>&archived=1"
                            class="btn <?php if (isset($_GET['archived'])) {
                                echo 'btn-primary';
                            } else {
                                echo 'btn-default';
                            } ?>"><i
                                class="fas fa-fw fa-archive me-2"></i>Archived</a>
                    </div>
                </div>
            </div>
        </form>
        <hr>
        <div class="table-responsive-sm">
            <table class="table table-striped table-borderless table-hover">
                <thead class="text-dark <?php if ($num_rows[0] == 0) { echo "d-none"; } ?>">
                    <tr>
                        <th>
                            <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=category_name&order=<?php echo $disp; ?>">
                                Name <?php if ($sort == 'category_name') { echo $order_icon; } ?>
                            </a>
                        </th>
                        <th>Color</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php

                    // Pre-load group names for Ticket categories
                    $group_names = [];
                    if ($category === 'Ticket') {
                        $sql_gn = mysqli_query($mysqli, "SELECT category_id, category_name FROM categories WHERE category_type = 'Ticket' AND category_parent = 0 AND category_archived_at IS NULL");
                        while ($gn = mysqli_fetch_assoc($sql_gn)) $group_names[intval($gn['category_id'])] = nullable_htmlentities($gn['category_name']);
                    }

                    while ($row = mysqli_fetch_assoc($sql)) {
                        $category_id = intval($row['category_id']);
                        $category_name = nullable_htmlentities($row['category_name']);
                        $category_description = nullable_htmlentities($row['category_description']);
                        $category_color = nullable_htmlentities($row['category_color']);
                        $category_parent = intval($row['category_parent'] ?? 0);
                        $is_group = ($category === 'Ticket' && $category_parent == 0);
                        $parent_name = ($category === 'Ticket' && $category_parent > 0) ? ($group_names[$category_parent] ?? '') : '';

                        ?>
                        <tr <?= $is_group ? 'class="table-secondary"' : '' ?>>
                            <td>
                                <a class="text-dark ajax-modal" href="#"
                                    data-modal-url="modals/category/category_edit.php?id=<?= $category_id ?>">
                                    <?php if ($is_group) { ?>
                                        <i class="fas fa-layer-group me-1 text-muted"></i><strong><?= $category_name ?></strong>
                                        <span class="badge text-bg-light ms-1">Group</span>
                                    <?php } else { ?>
                                        <?php if ($parent_name) { ?><small class="text-muted me-1"><?= $parent_name ?> /</small><?php } ?>
                                        <?= $category_name ?>
                                    <?php } ?>
                                    <div><small class="text-secondary"><?= $category_description ?></small></div>
                                </a>
                            </td>
                            <td><i class="fa fa-3x fa-circle" style="color:<?php echo $category_color; ?>;"></i></td>
                            <td>
                                <div class="dropdown dropleft text-center">
                                    <button class="btn btn-secondary btn-sm" type="button" data-bs-toggle="dropdown">
                                        <i class="fas fa-ellipsis-h"></i>
                                    </button>
                                    <div class="dropdown-menu">
                                        <?php
                                        if ($archived) {
                                            ?>
                                            <a class="dropdown-item text-info confirm-link"
                                                href="post.php?restore_category=<?php echo $category_id; ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                                <i class="fas fa-fw fa-redo me-2"></i>Restore
                                            </a>
                                            <a class="dropdown-item text-danger confirm-link"
                                                href="post.php?delete_category=<?php echo $category_id; ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                                <i class="fas fa-fw fa-trash me-2"></i>Delete
                                            </a>
                                            <?php
                                        } else {
                                            ?>
                                            <a class="dropdown-item ajax-modal" href="#"
                                                data-modal-url="modals/category/category_edit.php?id=<?= $category_id ?>">
                                                <i class="fas fa-fw fa-edit me-2"></i>Edit
                                            </a>
                                            <a class="dropdown-item text-danger confirm-link"
                                                href="post.php?archive_category=<?php echo $category_id; ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                                <i class="fas fa-fw fa-archive me-2"></i>Archive
                                            </a>
                                            <?php
                                        }
                                        ?>
                                    </div>
                                </div>
                            </td>
                        </tr>

                        <?php

                    }

                    ?>

                </tbody>
            </table>
        </div>
        <?php require_once "../includes/filter_footer.php";
 ?>
    </div>
</div>

<?php
require_once "../includes/footer.php";
