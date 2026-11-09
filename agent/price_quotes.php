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

// Optional status filter (from the pipeline strip); whitelisted, never interpolated raw.
$pq_statuses = ['Draft', 'Sent', 'Accepted', 'Declined'];
$pq_status = (isset($_GET['status']) && in_array($_GET['status'], $pq_statuses, true)) ? $_GET['status'] : '';
$pq_status_query = $pq_status ? "AND price_quote_status = '$pq_status'" : '';

$sql = mysqli_query(
    $mysqli,
    "SELECT SQL_CALC_FOUND_ROWS price_quotes.*, clients.client_name FROM price_quotes
    LEFT JOIN clients ON clients.client_id = price_quote_client_id
    WHERE price_quote_$archive_query
    AND (price_quote_title LIKE '%$q%' OR price_quote_description LIKE '%$q%' OR client_name LIKE '%$q%')
    $client_query
    $access_permission_query
    $pq_status_query
    ORDER BY $sort $order LIMIT $record_from, $record_to"
);

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Pipeline strip: count and value per status for the quotes this user can see (archived view excluded).
$pq_pipe = [];
foreach ($pq_statuses as $st) { $pq_pipe[$st] = ['n' => 0, 'value' => 0.0]; }
$pq_rs = mysqli_query($mysqli, "SELECT price_quote_status AS st, COUNT(DISTINCT price_quote_id) AS n, COALESCE(SUM(price_quote_item_quantity * price_quote_item_unit_price), 0) AS v
    FROM price_quotes
    LEFT JOIN clients ON clients.client_id = price_quote_client_id
    LEFT JOIN price_quote_items ON price_quote_item_price_quote_id = price_quote_id
    WHERE price_quote_archived_at IS NULL $client_query $access_permission_query
    GROUP BY price_quote_status");
while ($pr = mysqli_fetch_assoc($pq_rs)) {
    if (isset($pq_pipe[$pr['st']])) { $pq_pipe[$pr['st']] = ['n' => intval($pr['n']), 'value' => floatval($pr['v'])]; }
}
$pq_any = array_sum(array_column($pq_pipe, 'n')) > 0;
$suppress_filter_empty = true;

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
            <?php if ($pq_status) { ?><input type="hidden" name="status" value="<?= htmlspecialchars($pq_status) ?>"><?php } ?>
            <div class="row">
                <div class="col-md-4">
                    <div class="input-group mb-3 mb-md-0">
                        <input type="search" class="form-control" name="q" value="<?php if (isset($q)) {
                            echo stripslashes(nullable_htmlentities($q));
                        } ?>" placeholder="Search Quotes">
                        <div class="input-group-append">
                            <button class="btn btn-primary" aria-label="Search"><i class="fa fa-search"></i></button>
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
        <?php if ($pq_any && !$archived) { ?>
        <div class="row g-2 my-3" id="quotePipeline">
            <?php foreach ($pq_pipe as $st => $d) { $c = $price_quote_status_colors[$st]; ?>
            <div class="col-6 col-lg-3">
                <a href="?<?= $client_url ?>status=<?= $pq_status === $st ? '' : urlencode($st) ?>" class="card card-body py-2 text-decoration-none <?= $pq_status === $st ? 'border-' . $c : '' ?>"<?= $pq_status === $st ? ' aria-current="true" title="Showing only ' . htmlspecialchars($st, ENT_QUOTES) . ' quotes. Click again to show all."' : '' ?> style="border-left:4px solid var(--tblr-<?= $c ?>, #888);<?= $pq_status === $st ? 'box-shadow:0 0 0 2px var(--tblr-' . $c . ', #888);' : '' ?>">
                    <div class="d-flex justify-content-between align-items-baseline">
                        <span class="text-secondary text-uppercase small"><?= $st ?></span>
                        <span class="fw-bold fs-4"><?= $d['n'] ?></span>
                    </div>
                    <div class="small text-secondary"><?= numfmt_format_currency($currency_format, $d['value'], $session_company_currency) ?> quoted</div>
                </a>
            </div>
            <?php } ?>
        </div>
        <?php if ($pq_status) { ?><p class="small text-secondary mb-2">Showing <?= htmlspecialchars($pq_status) ?> quotes. <a href="?<?= $client_url ?>">Show all</a></p><?php } ?>
        <?php } else { ?><hr><?php } ?>

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
                        <td class="text-end text-nowrap"><?= numfmt_format_currency($currency_format, $price_quote_total, $session_company_currency); ?></td>
                        <td class="text-nowrap"><?= $price_quote_created_at ?></td>
                        <td class="text-center">
                            <div class="dropdown dropstart">
                                <button class="btn btn-secondary btn-sm" type="button" data-bs-toggle="dropdown" aria-label="Actions" title="Actions">
                                    <i class="fas fa-ellipsis-h"></i>
                                </button>
                                <div class="dropdown-menu">
                                    <a class="dropdown-item" href="price_quote.php?client_id=<?= $price_quote_client_id ?>&price_quote_id=<?= $price_quote_id ?>">
                                        <i class="fas fa-fw fa-eye me-2"></i>Open
                                    </a>
                                    <?php if (lookupUserPermission("module_client") >= 2) { ?>
                                        <?php if (!$archived) { ?>
                                        <div class="dropdown-divider"></div>
                                        <a class="dropdown-item text-danger confirm-link" data-confirm-message="Archive this price quote? You can restore it from the Archived view." href="post.php?archive_price_quote=<?= $price_quote_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
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
        <?php if ($num_rows[0] == 0) {
            $pq_filtered = $pq_status || trim((string) ($q ?? '')) !== ''; ?>
            <div class="text-center py-5">
                <i class="fas fa-file-invoice-dollar fa-2x text-secondary mb-3 d-block opacity-50"></i>
                <?php if ($pq_filtered) { ?>
                    <p class="mb-2">No quotes match this filter.</p>
                    <a class="btn btn-sm btn-outline-secondary" href="?<?= $client_url ?>">Clear filter</a>
                <?php } elseif ($archived) { ?>
                    <p class="mb-0 text-secondary">No archived quotes.</p>
                <?php } else { ?>
                    <p class="fw-semibold mb-1">No price quotes yet</p>
                    <p class="text-secondary mb-3">Give a department a price in minutes: pick the department, add line items, then mark it Sent and track Accepted or Declined here.</p>
                    <?php if (lookupUserPermission("module_client") >= 2) { ?>
                    <button type="button" class="btn btn-primary ajax-modal" data-modal-url="modals/price_quote/price_quote_add.php?<?= $client_url ?>"><i class="fas fa-plus me-2"></i>Create the first quote</button>
                    <?php } ?>
                <?php } ?>
            </div>
        <?php } ?>
        <?php require_once "../includes/filter_footer.php"; ?>
    </div>
</div>

<?php
require_once "../includes/footer.php";
