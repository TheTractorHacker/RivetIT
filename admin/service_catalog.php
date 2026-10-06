<?php

// Default Column Sortby Filter
$sort = "sort_order";
$order = "ASC";

require_once "includes/inc_all_admin.php";

$sql = mysqli_query(
    $mysqli,
    "SELECT SQL_CALC_FOUND_ROWS sci.*, c.category_name
     FROM service_catalog_items sci
     LEFT JOIN categories c ON c.category_id = sci.ticket_category_id
     WHERE sci.name LIKE '%$q%'
     ORDER BY $sort $order, sci.name ASC
     LIMIT $record_from, $record_to"
);

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

?>

    <div class="card">
        <div class="card-header py-2">
            <h3 class="card-title mt-2"><i class="fas fa-fw fa-th-large me-2"></i>Service Catalog</h3>
            <div class="card-tools">
                <button type="button" class="btn btn-primary ajax-modal" data-modal-url="modals/service_catalog/service_catalog_item_add.php"><i class="fas fa-plus me-2"></i>New Catalog Item</button>
            </div>
        </div>

        <div class="card-body">
            <p class="text-secondary">Curated tickets your team &amp; departments can raise in one click from <strong>Request Something</strong>. Each item pre-fills the normal ticket form. Edit an item to add request form fields and an approval chain; items without them behave as plain shortcuts.</p>

            <div class="row">
                <div class="col-sm-4 mb-2">
                    <form autocomplete="off">
                        <div class="input-group">
                            <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search Catalog Items">
                            <div class="input-group-append">
                                <button class="btn btn-primary"><i class="fa fa-search"></i></button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <hr>

            <div class="table-responsive-sm">
                <table class="table table-striped table-borderless table-hover">
                    <thead class="text-dark <?php if ($num_rows[0] == 0) { echo "d-none"; } ?>">
                        <tr>
                            <th>
                                <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=sort_order&order=<?php echo sortLinkOrder('sort_order'); ?>">
                                    Order <?php if ($sort == 'sort_order') { echo $order_icon; } ?>
                                </a>
                            </th>
                            <th>
                                <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=name&order=<?php echo sortLinkOrder('name'); ?>">
                                    Name <?php if ($sort == 'name') { echo $order_icon; } ?>
                                </a>
                            </th>
                            <th>Ticket Subject</th>
                            <th>Category</th>
                            <th>Priority</th>
                            <th class="text-center">Active</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php

                        while ($row = mysqli_fetch_assoc($sql)) {
                            $catalog_item_id = intval($row['catalog_item_id']);
                            $name = nullable_htmlentities($row['name']);
                            $description = nullable_htmlentities($row['description']);
                            $icon = nullable_htmlentities($row['icon']);
                            $ticket_subject_template = nullable_htmlentities($row['ticket_subject_template']);
                            $category_name = nullable_htmlentities($row['category_name']);
                            $default_priority = nullable_htmlentities($row['default_priority']);
                            $is_active = intval($row['is_active']);
                            $sort_order = intval($row['sort_order']);

                            ?>
                            <tr class="<?php if (!$is_active) { echo 'text-muted'; } ?>">
                                <td><?php echo $sort_order; ?></td>
                                <td>
                                    <a class="<?php echo $is_active ? 'text-dark' : 'text-muted'; ?> ajax-modal" href="#" data-modal-size="lg"
                                        data-modal-url="modals/service_catalog/service_catalog_item_edit.php?id=<?= $catalog_item_id ?>">
                                        <?php if ($icon) { ?><i class="fas fa-fw <?php echo $icon; ?> me-2"></i><?php } ?><?php echo $name; ?>
                                        <?php if ($description) { ?><div><small class="text-secondary"><?php echo $description; ?></small></div><?php } ?>
                                    </a>
                                    <?php if (intval($row['requires_approval'])) { ?><span class="badge text-bg-warning" title="Risk score <?= intval($row['risk_score']) ?>">Approval</span><?php } ?>
                                </td>
                                <td><small><?php echo $ticket_subject_template ?: '<span class="text-secondary">&mdash;</span>'; ?></small></td>
                                <td><?php echo $category_name ?: '<span class="text-secondary">&mdash;</span>'; ?></td>
                                <td><?php echo $default_priority ?: '<span class="text-secondary">&mdash;</span>'; ?></td>
                                <td class="text-center">
                                    <?php if ($is_active) { ?>
                                        <span class="badge text-bg-success">Active</span>
                                    <?php } else { ?>
                                        <span class="badge text-bg-secondary">Inactive</span>
                                    <?php } ?>
                                </td>
                                <td>
                                    <div class="dropdown dropleft text-center">
                                        <button class="btn btn-secondary btn-sm" type="button" data-bs-toggle="dropdown">
                                            <i class="fas fa-ellipsis-h"></i>
                                        </button>
                                        <div class="dropdown-menu">
                                            <a class="dropdown-item ajax-modal" href="#" data-modal-size="lg"
                                                data-modal-url="modals/service_catalog/service_catalog_item_edit.php?id=<?= $catalog_item_id ?>">
                                                <i class="fas fa-fw fa-edit me-2"></i>Edit
                                            </a>
                                            <a class="dropdown-item confirm-link" href="post.php?toggle_service_catalog_item=<?php echo $catalog_item_id; ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                                <?php if ($is_active) { ?>
                                                    <i class="fas fa-fw fa-eye-slash me-2"></i>Deactivate
                                                <?php } else { ?>
                                                    <i class="fas fa-fw fa-eye me-2"></i>Activate
                                                <?php } ?>
                                            </a>
                                            <div class="dropdown-divider"></div>
                                            <a class="dropdown-item text-danger text-bold confirm-link" href="post.php?delete_service_catalog_item=<?php echo $catalog_item_id; ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                                <i class="fas fa-fw fa-trash me-2"></i>Delete
                                            </a>
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
            <?php require_once "../includes/filter_footer.php"; ?>
        </div>
    </div>

<?php

require_once "../includes/footer.php";
