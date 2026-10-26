<?php

/*
 * RivetIT - Price Quote (detail)
 *
 * The internal "give management a price" tool: a client, a title/description, free-text line
 * items (description + qty + unit price), and a Draft/Sent/Accepted/Declined status. No product
 * catalog, no tax calculation, no "convert to invoice" button - genuinely light.
 *
 * ?print=1 renders the same page with the app chrome hidden and the browser print dialog opened
 * (same pattern as agent/reports/includes/report_toolbar.php - "Save as PDF" from there).
 */

if (!isset($_GET['price_quote_id'])) {
    header("Location: price_quotes.php");
    exit();
}

$price_quote_id = intval($_GET['price_quote_id']);

// If client_id is in the URI, show the client sidebar/header (price_quotes.php always links
// with it); otherwise resolve it ourselves below before falling back to the plain chrome.
if (isset($_GET['client_id'])) {
    require_once "includes/inc_all_client.php";
} else {
    require_once "includes/inc_all.php";
}

// Perms - everyone with module_client can view; editing needs level 2
enforceUserPermission('module_client');
$price_quote_can_edit = lookupUserPermission('module_client') >= 2;

$sql = mysqli_query($mysqli, "SELECT price_quotes.*, clients.client_name,
    creator.user_name AS created_by_name, updater.user_name AS updated_by_name
    FROM price_quotes
    LEFT JOIN clients ON clients.client_id = price_quote_client_id
    LEFT JOIN users creator ON creator.user_id = price_quote_created_by
    LEFT JOIN users updater ON updater.user_id = price_quote_updated_by
    WHERE price_quote_id = $price_quote_id");

if (mysqli_num_rows($sql) == 0) {
    echo "<center><h1 class='text-secondary mt-5'>Nothing to see here</h1><a class='btn btn-lg btn-secondary mt-3' href='price_quotes.php'><i class='fa fa-fw fa-arrow-left'></i> Go Back</a></center>";
    require_once "../includes/footer.php";
    exit();
}

$row = mysqli_fetch_assoc($sql);

// Always checked against the quote's OWN client_id, never the URL's: includes/inc_all_client.php (loaded
// above when client_id is in the URL) only verifies access to THAT client - it has no idea which quote is
// being requested. Without this, a department-restricted user could view another department's quote by
// pairing a guessed/enumerated price_quote_id with their own (legitimately accessible) client_id in the URL.
enforceClientAccess(intval($row['price_quote_client_id']));

$price_quote_title = nullable_htmlentities($row['price_quote_title']);
$price_quote_description = nullable_htmlentities($row['price_quote_description']);
$price_quote_status = nullable_htmlentities($row['price_quote_status']);
$price_quote_client_id = intval($row['price_quote_client_id']);
$price_quote_client_name = nullable_htmlentities($row['client_name']);
$price_quote_created_at = nullable_htmlentities($row['price_quote_created_at']);
$price_quote_updated_at = nullable_htmlentities($row['price_quote_updated_at']);
$price_quote_created_by_name = nullable_htmlentities($row['created_by_name'] ?? '');
$price_quote_updated_by_name = nullable_htmlentities($row['updated_by_name'] ?? '');
$price_quote_archived = !empty($row['price_quote_archived_at']);

$price_quote_statuses = ['Draft', 'Sent', 'Accepted', 'Declined'];
$price_quote_status_colors = [
    'Draft'    => 'secondary',
    'Sent'     => 'info',
    'Accepted' => 'success',
    'Declined' => 'danger',
];
$price_quote_status_color = $price_quote_status_colors[$row['price_quote_status']] ?? 'secondary';

$sql_items = mysqli_query($mysqli, "SELECT * FROM price_quote_items WHERE price_quote_item_price_quote_id = $price_quote_id ORDER BY price_quote_item_order ASC, price_quote_item_id ASC");
$price_quote_items = [];
$price_quote_grand_total = 0.00;
while ($item = mysqli_fetch_assoc($sql_items)) {
    $item_total = floatval($item['price_quote_item_quantity']) * floatval($item['price_quote_item_unit_price']);
    $price_quote_grand_total += $item_total;
    $price_quote_items[] = $item + ['line_total' => $item_total];
}

$price_quote_print = isset($_GET['print']);

if ($price_quote_print) {
    ?>
    <style nonce="<?php echo nullable_htmlentities($csp_nonce ?? ''); ?>">
        aside.navbar-vertical, header.navbar, .navbar-vertical, .d-print-none { display: none !important; }
        .page-wrapper, .page { margin-left: 0 !important; padding-left: 0 !important; }
        body { background: #fff !important; }
    </style>
    <script nonce="<?php echo nullable_htmlentities($csp_nonce ?? ''); ?>">
        window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 500); });
    </script>
    <?php
}
?>

<div class="card card-dark d-print-none">
    <div class="card-header py-2">
        <h3 class="card-title mt-2">
            <i class="fas fa-fw fa-file-invoice-dollar me-2"></i><?= $price_quote_title ?>
            <span class="badge text-bg-<?= $price_quote_status_color ?> ms-2"><?= $price_quote_status ?></span>
            <?php if ($price_quote_archived) { ?>
                <span class="badge text-bg-dark ms-1">Archived</span>
            <?php } ?>
        </h3>
        <div class="card-tools">
            <a href="?<?= isset($_GET['client_id']) ? 'client_id=' . $price_quote_client_id . '&' : '' ?>price_quote_id=<?= $price_quote_id ?>&print=1" target="_blank" class="btn btn-secondary">
                <i class="fas fa-fw fa-print me-2"></i>Print / PDF
            </a>
            <?php if ($price_quote_can_edit && !$price_quote_archived) { ?>
            <button type="button" class="btn btn-primary ajax-modal" data-modal-url="modals/price_quote/price_quote_edit.php?price_quote_id=<?= $price_quote_id ?>">
                <i class="fas fa-fw fa-edit me-2"></i>Edit
            </button>
            <?php } ?>
        </div>
    </div>
    <div class="card-body">
        <div class="row mb-3">
            <div class="col-md-4">
                <div class="text-secondary">Department</div>
                <div><a href="client_overview.php?client_id=<?= $price_quote_client_id ?>"><?= $price_quote_client_name ?></a></div>
            </div>
            <div class="col-md-4">
                <div class="text-secondary">Created</div>
                <div><?= $price_quote_created_at ?><?= $price_quote_created_by_name ? ' by ' . $price_quote_created_by_name : '' ?></div>
            </div>
            <div class="col-md-4">
                <div class="text-secondary">Last updated</div>
                <div><?= $price_quote_updated_at ?: '-' ?><?= $price_quote_updated_at && $price_quote_updated_by_name ? ' by ' . $price_quote_updated_by_name : '' ?></div>
            </div>
        </div>

        <?php if ($price_quote_description) { ?>
        <div class="mb-3"><?= nl2br($price_quote_description) ?></div>
        <?php } ?>

        <?php if ($price_quote_can_edit && !$price_quote_archived) { ?>
        <div class="form-group mb-3" style="max-width: 16rem;">
            <label>Status</label>
            <form action="post.php" method="post">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="price_quote_id" value="<?= $price_quote_id ?>">
                <select class="form-control select2 auto-submit-select" name="status">
                    <?php foreach ($price_quote_statuses as $status_option) { ?>
                        <option value="<?= $status_option ?>" <?= $price_quote_status === $status_option ? 'selected' : '' ?>><?= $status_option ?></option>
                    <?php } ?>
                </select>
                <button type="submit" name="edit_price_quote_status" class="d-none">Save</button>
            </form>
        </div>
        <?php } ?>
    </div>
</div>

<div class="card card-dark mt-3">
    <div class="card-header py-2 d-print-none">
        <h3 class="card-title mt-2">Line Items</h3>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped table-borderless mb-0">
                <thead class="text-dark">
                    <tr>
                        <th>Description</th>
                        <th class="text-end">Qty</th>
                        <th class="text-end">Unit Price</th>
                        <th class="text-end">Total</th>
                        <th class="d-print-none"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$price_quote_items) { ?>
                    <tr>
                        <td colspan="5" class="text-center text-secondary py-3">No line items yet.</td>
                    </tr>
                    <?php } ?>
                    <?php foreach ($price_quote_items as $item) {
                        $item_id = intval($item['price_quote_item_id']);
                        $item_description = nullable_htmlentities($item['price_quote_item_description']);
                        $item_qty = floatval($item['price_quote_item_quantity']);
                        $item_unit_price = floatval($item['price_quote_item_unit_price']);
                        $item_total = floatval($item['line_total']);
                        ?>
                        <tr>
                            <td><?= $item_description ?></td>
                            <td class="text-end"><?= rtrim(rtrim(number_format($item_qty, 2), '0'), '.') ?></td>
                            <td class="text-end"><?= numfmt_format_currency($currency_format, $item_unit_price, $session_company_currency); ?></td>
                            <td class="text-end"><?= numfmt_format_currency($currency_format, $item_total, $session_company_currency); ?></td>
                            <td class="text-end d-print-none">
                                <?php if ($price_quote_can_edit && !$price_quote_archived) { ?>
                                <a href="#" class="ajax-modal" data-modal-url="modals/price_quote/price_quote_item_edit.php?price_quote_item_id=<?= $item_id ?>" title="Edit"><i class="fas fa-edit"></i></a>
                                <a href="post.php?delete_price_quote_item=<?= $item_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>" class="confirm-link ms-2 text-danger" title="Delete"><i class="fas fa-trash"></i></a>
                                <?php } ?>
                            </td>
                        </tr>
                    <?php } ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="3" class="text-end"><strong>Total</strong></td>
                        <td class="text-end"><strong><?= numfmt_format_currency($currency_format, $price_quote_grand_total, $session_company_currency); ?></strong></td>
                        <td class="d-print-none"></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <?php if ($price_quote_can_edit && !$price_quote_archived) { ?>
        <div class="p-3 border-top d-print-none">
            <form action="post.php" method="post" class="row g-2 align-items-end" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="price_quote_id" value="<?= $price_quote_id ?>">
                <div class="col-md-5">
                    <label class="form-label mb-1">Description</label>
                    <input type="text" class="form-control" name="description" maxlength="255" placeholder="e.g. Dell Latitude 5440 laptop" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label mb-1">Qty</label>
                    <input type="text" class="form-control" inputmode="decimal" name="quantity" value="1" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label mb-1">Unit Price</label>
                    <input type="text" class="form-control" inputmode="decimal" name="unit_price" value="0.00" required>
                </div>
                <div class="col-md-3">
                    <button type="submit" name="add_price_quote_item" class="btn btn-primary w-100"><i class="fas fa-plus me-2"></i>Add Item</button>
                </div>
            </form>
        </div>
        <?php } ?>
    </div>
</div>

<?php
require_once "../includes/footer.php";
