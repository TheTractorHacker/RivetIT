<?php

/*
 * RivetIT - Price Quotes (list)
 *
 * A light, standalone "give management a price" tool. Replaces the old quotes.php/quote.php,
 * which was really invoice-conversion machinery (pulled from products/taxes, converted straight
 * into an invoice). This is just: a client, a title, free-text line items, a simple status.
 *
 * Permission: module_client (the same department-scoped permission other client-facing document
 * tools use) - viewing needs level 1, creating/editing/archiving needs level 2.
 */

// Default Column Sortby Filter
$sort = "price_quote_created_at";
$order = "DESC";

// If client_id is in the URI, show the client sidebar/header and scope the list to that client
if (isset($_GET['client_id'])) {
    require_once "includes/inc_all_client.php";
    $client_query = "AND price_quote_client_id = $client_id";
    $client_url = "client_id=$client_id&";
} else {
    require_once "includes/inc_all.php";
    $client_query = "";
    $client_url = '';
}

// Perms
enforceUserPermission('module_client');

$sql = mysqli_query(
    $mysqli,
    "SELECT SQL_CALC_FOUND_ROWS price_quotes.*, clients.client_name FROM price_quotes
    LEFT JOIN clients ON clients.client_id = price_quote_client_id
    WHERE price_quote_$archive_query
    AND (price_quote_title LIKE '%$q%' OR price_quote_description LIKE '%$q%' OR client_name LIKE '%$q%')
    $client_query
    $access_permission_query
    ORDER BY $sort $order LIMIT $record_from, $record_to"
);

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

$price_quote_status_colors = [
    'Draft'    => 'secondary',
    'Sent'     => 'info',
    'Accepted' => 'success',
    'Declined' => 'danger',
];

?>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2">
            <i class="fas fa-fw fa-file-invoice-dollar me-2"></i>Price Quotes
        </h3>
        <div class="card-tools">
            <?php if (lookupUserPermission("module_client") >= 2) { ?>
            <button type="button" class="btn btn-primary ajax-modal" data-modal-url="modals/price_quote/price_quote_add.php?<?= $client_url ?>">
                <i class="fas fa-plus me-2"></i>New Quote
            </button>
            <?php } ?>
        </div>
    </div>
    <div class="card-body">
        <form autocomplete="off">
            <?php if ($client_url) { ?>
                <input type="hidden" name="client_id" value="<?php echo $client_id; ?>">
            <?php } ?>
            <input type="hidden" name="archived" value="<?php echo $archived; ?>">
            <div class="row">
                <div class="col-md-4">
                    <div class="input-group mb-3 mb-md-0">
                        <input type="search" class="form-control" name="q" value="<?php if (isset($q)) {
                            echo stripslashes(nullable_htmlentities($q));
                        } ?>" placeholder="Search Quotes">
                        <div class="input-group-append">
                            <button class="btn btn-dark"><i class="fa fa-search"></i></button>
                        </div>
                    </div>
                </div>
                <div class="col-md-8">
                    <div class="btn-group float-end">
                        <a href="?<?php echo "$client_url"; ?>archived=<?php if ($archived == 1) {
                            echo 0;
                        } else {
                            echo 1;
                        } ?>"
                            class="btn btn-<?php if ($archived == 1) {
                                echo "primary";
                            } else {
                                echo "default";
                            } ?>">
                            <i class="fa fa-fw fa-archive me-2"></i>Archived
                        </a>
                    </div>
                </div>
            </div>
        </form>
        <hr>

        <div class="table-responsive">
            <table class="table table-striped table-borderless table-hover">
                <thead class="text-dark <?php if ($num_rows[0] == 0) {
                    echo "d-none";
                } ?> text-nowrap">
                <tr>
                    <th>
                        <a class="text-secondary" href="?<?php echo $url_query_strings_sort; ?>&sort=price_quote_title&order=<?php echo sortLinkOrder('price_quote_title'); ?>">
                            Title <?php if ($sort == 'price_quote_title') {
                                echo $order_icon;
                            } ?>
                        </a>
                    </th>
                    <?php if (!$client_url) { ?>
                    <th>Department</th>
                    <?php } ?>
                    <th>
                        <a class="text-secondary" href="?<?php echo $url_query_strings_sort; ?>&sort=price_quote_status&order=<?php echo sortLinkOrder('price_quote_status'); ?>">
                            Status <?php if ($sort == 'price_quote_status') {
                                echo $order_icon;
                            } ?>
                        </a>
                    </th>
                    <th class="text-end">Total</th>
                    <th>
                        <a class="text-secondary" href="?<?php echo $url_query_strings_sort; ?>&sort=price_quote_created_at&order=<?php echo sortLinkOrder('price_quote_created_at'); ?>">
                            Created <?php if ($sort == 'price_quote_created_at') {
                                echo $order_icon;
                            } ?>
                        </a>
                    </th>
                    <th class="text-center">Action</th>
                </tr>
                </thead>
                <tbody>
                <?php
                while ($row = mysqli_fetch_assoc($sql)) {
                    $price_quote_id = intval($row['price_quote_id']);
                    $price_quote_title = nullable_htmlentities($row['price_quote_title']);
                    $price_quote_status = nullable_htmlentities($row['price_quote_status']);
                    $price_quote_status_color = $price_quote_status_colors[$row['price_quote_status']] ?? 'secondary';
                    $price_quote_client_id = intval($row['price_quote_client_id']);
                    $price_quote_client_name = nullable_htmlentities($row['client_name']);
                    $price_quote_created_at = nullable_htmlentities($row['price_quote_created_at']);

                    $sql_total = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT SUM(price_quote_item_quantity * price_quote_item_unit_price) AS total FROM price_quote_items WHERE price_quote_item_price_quote_id = $price_quote_id"));
                    $price_quote_total = floatval($sql_total['total'] ?? 0);
                    ?>
                    <tr>
                        <td>
                            <a href="price_quote.php?client_id=<?= $price_quote_client_id ?>&price_quote_id=<?= $price_quote_id ?>"><?= $price_quote_title ?></a>
                        </td>
                        <?php if (!$client_url) { ?>
                        <td>
                            <?php if ($price_quote_client_id) { ?>
                            <a href="client_overview.php?client_id=<?= $price_quote_client_id ?>"><?= $price_quote_client_name ?></a>
                            <?php } ?>
                        </td>
                        <?php } ?>
                        <td><span class="badge text-bg-<?= $price_quote_status_color ?>"><?= $price_quote_status ?></span></td>
                        <td class="text-end"><?= numfmt_format_currency($currency_format, $price_quote_total, $session_company_currency); ?></td>
                        <td><?= $price_quote_created_at ?></td>
                        <td class="text-center">
                            <div class="dropdown dropstart">
                                <button class="btn btn-secondary btn-sm" type="button" data-bs-toggle="dropdown">
                                    <i class="fas fa-ellipsis-h"></i>
                                </button>
                                <div class="dropdown-menu">
                                    <a class="dropdown-item" href="price_quote.php?client_id=<?= $price_quote_client_id ?>&price_quote_id=<?= $price_quote_id ?>">
                                        <i class="fas fa-fw fa-eye me-2"></i>Open
                                    </a>
                                    <?php if (lookupUserPermission("module_client") >= 2) { ?>
                                        <?php if (!$archived) { ?>
                                        <div class="dropdown-divider"></div>
                                        <a class="dropdown-item text-danger confirm-link" href="post.php?archive_price_quote=<?= $price_quote_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                            <i class="fas fa-fw fa-archive me-2"></i>Archive
                                        </a>
                                        <?php } else { ?>
                                        <div class="dropdown-divider"></div>
                                        <a class="dropdown-item text-info" href="post.php?restore_price_quote=<?= $price_quote_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                            <i class="fas fa-fw fa-redo me-2"></i>Restore
                                        </a>
                                        <?php } ?>
                                    <?php } ?>
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
