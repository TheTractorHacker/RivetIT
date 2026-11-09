<?php

// Default Column Sortby Filter
$sort = "sort_order";
$order = "ASC";

require_once "includes/inc_all_admin.php";
require_once $_SERVER['DOCUMENT_ROOT'] . '/src/ITSM/ServiceCatalogService.php';

$sql = mysqli_query(
    $mysqli,
    "SELECT SQL_CALC_FOUND_ROWS sci.*, c.category_name, cat.name AS catalog_category_name
     FROM service_catalog_items sci
     LEFT JOIN categories c ON c.category_id = sci.ticket_category_id
     LEFT JOIN service_catalog_categories cat ON cat.category_id = sci.catalog_category_id
     WHERE sci.name LIKE '%$q%'
     ORDER BY $sort $order, sci.name ASC
     LIMIT $record_from, $record_to"
);

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

require_once $_SERVER['DOCUMENT_ROOT'] . '/src/ITSM/ServiceCatalogService.php';
$catalog_service = new \ITFlow\ITSM\ServiceCatalogService($mysqli);
$catalog_cat_rows = $catalog_service->getCategories(false);
$catalog_cat_counts = [];
$cc_res = mysqli_query($mysqli, "SELECT catalog_category_id, COUNT(*) AS n FROM service_catalog_items WHERE catalog_category_id IS NOT NULL GROUP BY catalog_category_id");
while ($cc_res && ($ccr = mysqli_fetch_assoc($cc_res))) { $catalog_cat_counts[intval($ccr['catalog_category_id'])] = intval($ccr['n']); }
$catalog_worker_on = \ITFlow\ITSM\ServiceCatalogService::timeoutsEnabled($mysqli);

?>

    <div class="card">
        <div class="card-header py-2">
            <h3 class="card-title mt-2"><i class="fas fa-fw fa-th-large me-2"></i>Service Catalog</h3>
            <div class="card-tools">
                <button type="button" class="btn btn-primary ajax-modal" data-modal-url="modals/service_catalog/service_catalog_item_add.php"><i class="fas fa-plus me-2"></i>New Catalog Item</button>
            </div>
        </div>

        <div class="card-body">
            <p class="text-secondary">Curated tickets your team &amp; departments can raise in one click from <strong>Request Something</strong>. Each item pre-fills the normal ticket form. Group items into <strong>categories</strong> to show them as tiles. Edit an item to add request form fields (including repeating tables and conditions), risk rules and an approval chain (parallel steps, risk-based routing, reminders and timeouts); items without them behave as plain shortcuts.</p>

            <div class="row g-3 mb-3">
                <div class="col-lg-7">
                  <div class="border rounded p-3 h-100">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <h6 class="mb-0"><i class="fas fa-fw fa-folder me-2"></i>Categories <small class="text-secondary">(the tile groups on Request Something)</small></h6>
                        <button type="button" class="btn btn-sm btn-primary ajax-modal" data-modal-url="modals/service_catalog/service_catalog_category_edit.php"><i class="fas fa-plus me-1"></i>New category</button>
                    </div>
                    <?php if (!$catalog_cat_rows) { ?>
                        <p class="text-secondary small mt-2 mb-0">No categories yet. Without categories every item is listed in one group.</p>
                    <?php } else { ?>
                    <ul class="list-group" id="catalogCategoryList">
                        <?php foreach ($catalog_cat_rows as $cat) { $cid = intval($cat['category_id']); ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center <?= intval($cat['is_active']) ? '' : 'text-muted' ?>">
                                <span><i class="fas fa-fw <?= nullable_htmlentities($cat['icon'] ?: 'fa-folder') ?> me-2"></i><?= nullable_htmlentities($cat['name']) ?>
                                    <span class="badge text-bg-light"><?= $catalog_cat_counts[$cid] ?? 0 ?> item<?= ($catalog_cat_counts[$cid] ?? 0) === 1 ? '' : 's' ?></span>
                                    <?php if (!intval($cat['is_active'])) { ?><span class="badge text-bg-secondary">Inactive</span><?php } ?></span>
                                <span>
                                    <a href="#" class="btn btn-sm btn-secondary ajax-modal" data-modal-url="modals/service_catalog/service_catalog_category_edit.php?id=<?= $cid ?>" aria-label="Edit category"><i class="fas fa-edit"></i></a>
                                    <a href="post.php?delete_service_catalog_category=<?= $cid ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>" class="btn btn-sm btn-outline-danger confirm-link" data-confirm="Delete this category? Its items are kept and move to the ungrouped list." aria-label="Delete category"><i class="fas fa-trash"></i></a>
                                </span>
                            </li>
                        <?php } ?>
                    </ul>
                    <?php } ?>
                  </div>
                </div>
                <?php if ($session_is_admin) { ?>
                <div class="col-lg-5">
                  <div class="border rounded p-3 h-100">
                    <h6><i class="fas fa-fw fa-bell me-2"></i>Approval worker</h6>
                    <form action="post.php" method="post" autocomplete="off">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="catalog_approval_cron" value="1" id="catalog_approval_cron" <?= $catalog_worker_on ? 'checked' : '' ?>>
                            <label class="form-check-label" for="catalog_approval_cron">Send approval reminders, escalate and time out requests, and hand approvals to out-of-office delegates</label>
                        </div>
                        <p class="small text-secondary mt-1 mb-2">Off by default. Each item sets its own hours (edit an item, Reminders and timeouts). Runs with the main cron; reminders go out as in-app notifications and email.</p>
                        <button type="submit" name="save_service_catalog_worker" class="btn btn-sm btn-primary">Save</button>
                    </form>
                  </div>
                </div>
                <?php } ?>
            </div>
            <div class="row">
                <div class="col-sm-6 col-lg-4 mb-2">
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
                            <th>Tile group</th>
                            <th>Ticket category</th>
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
                                    <?php if (intval($row['requires_approval'])) { ?><span class="badge text-bg-warning">Approval</span><?php } ?>
                                    <?= \ITFlow\ITSM\ServiceCatalogService::riskBadgeHtml(intval($row['risk_score'])) ?>
                                </td>
                                <td><small><?php echo $ticket_subject_template ?: '<span class="text-secondary">&mdash;</span>'; ?></small></td>
                                <td><?php echo nullable_htmlentities($row['catalog_category_name'] ?? '') ?: '<span class="text-secondary">&mdash;</span>'; ?></td>
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
                                            <a class="dropdown-item text-danger text-bold confirm-link" data-confirm="Delete this catalog item permanently? Items with requests waiting for approval cannot be deleted." href="post.php?delete_service_catalog_item=<?php echo $catalog_item_id; ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
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
