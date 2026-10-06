<?php
/*
 * Client Portal
 * New ticket form
 */

// Ticket bodies and comments carry agent-authored HTML that can embed data: images
// (TinyMCE inlines a pasted screenshot as base64). Same widening client/document.php
// and client/kb_article.php make, and it must precede the include - see inc_all.php.
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:");

require_once 'includes/inc_all.php';

// Allow clients to select a related asset when raising a ticket
$sql_assets = mysqli_query($mysqli, "SELECT asset_id, asset_name, asset_type FROM assets WHERE asset_contact_id = $session_contact_id AND asset_client_id = $session_client_id AND asset_archived_at IS NULL ORDER BY asset_name ASC");


// Arriving from Request Something: read the item back from the database (the query string only carries its id, so
// nothing a visitor edits in the URL can set the subject, category or priority of the ticket).
$catalog_item = null;
$catalog_item_id = intval($_GET['catalog_item_id'] ?? 0);
if ($catalog_item_id > 0) {
    $catalog_item = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT catalog_item_id, name, description, icon, ticket_subject_template, ticket_category_id, default_priority, requires_approval, risk_score, auto_approve_below FROM service_catalog_items WHERE catalog_item_id = $catalog_item_id AND is_active = 1 LIMIT 1"));
}
// Request form fields and whether this item waits for approval (src/ITSM/ServiceCatalogService.php)
require_once $_SERVER['DOCUMENT_ROOT'] . '/src/ITSM/ServiceCatalogService.php';
$catalog_service = new \ITFlow\ITSM\ServiceCatalogService($mysqli);
$catalog_fields = $catalog_item ? $catalog_service->getFields($catalog_item_id) : [];
$catalog_needs_approval = $catalog_item ? $catalog_service->needsApproval($catalog_item) : false;
$prefill_subject = '';
$prefill_priority = 'Low';
$prefill_category = 0;
$catalog_icon = 'fa-ticket-alt';
if ($catalog_item) {
    $prefill_subject = (string) $catalog_item['ticket_subject_template'];
    $prefill_priority = in_array($catalog_item['default_priority'], ['Low', 'Medium', 'High'], true) ? $catalog_item['default_priority'] : 'Low';
    $prefill_category = intval($catalog_item['ticket_category_id']);
    $icon_clean = preg_replace('/^fa-/', '', preg_replace('/[^a-z0-9-]/', '', strtolower(trim((string) $catalog_item['icon']))));
    $catalog_icon = 'fa-' . ($icon_clean !== '' ? $icon_clean : 'ticket-alt');
}

?>

    <ol class="breadcrumb d-print-none">
        <li class="breadcrumb-item">
            <a href="index.php">Home</a>
        </li>
        <li class="breadcrumb-item">
            <a href="tickets.php">Tickets</a>
        </li>
        <li class="breadcrumb-item active">New Ticket</li>
    </ol>

    <div class="portal-pagehead">
        <div>
            <h2 class="portal-pagehead-title">Raise a new ticket</h2>
            <p class="text-secondary mb-0">Tell us what is going on and the IT team will pick it up.</p>
        </div>
    </div>

    <?php if ($catalog_item) { ?>
        <div class="portal-request-banner">
            <span class="portal-request-icon"><i class="fas fa-fw <?= $catalog_icon ?>" aria-hidden="true"></i></span>
            <div class="flex-grow-1">
                <div class="portal-request-name"><?= nullable_htmlentities($catalog_item['name']) ?></div>
                <?php if (!empty($catalog_item['description'])) { ?><div class="portal-request-desc"><?= nullable_htmlentities($catalog_item['description']) ?></div><?php } ?>
            </div>
            <a href="service_catalog.php" class="btn btn-sm btn-outline-secondary">Change</a>
        </div>
        <?php if ($catalog_needs_approval) { ?>
            <div class="alert alert-info"><i class="fas fa-user-check me-2" aria-hidden="true"></i>This request needs approval before the IT team starts on it. You will be told the outcome.</div>
        <?php } ?>
    <?php } ?>

    <div class="card portal-card portal-form-card">
      <div class="card-body">
        <form action="post.php" method="post">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <?php if ($catalog_item) { ?><input type="hidden" name="catalog_item_id" value="<?= intval($catalog_item_id) ?>"><?php } ?>

            <div class="form-group">
                <label>Subject <strong class="text-danger">*</strong></label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-tag"></i></span>
                    </div>
                    <input type="text" class="form-control" name="subject" placeholder="Subject" value="<?= nullable_htmlentities($prefill_subject) ?>" required>
                </div>
            </div>

            <div class="row">
                <div class="col">
                    <div class="form-group">
                        <label>Priority <strong class="text-danger">*</strong></label>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text"><i class="fa fa-fw fa-thermometer-half"></i></span>
                            </div>
                            <select class="form-control select2" name="priority" required>
                                <?php foreach (['Low', 'Medium', 'High'] as $prio) { ?>
                                <option<?php if ($prio === $prefill_priority) { echo ' selected'; } ?>><?= $prio ?></option>
                                <?php } ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="col">
                    <div class="form-group">
                    <label>Category</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-layer-group"></i></span>
                        </div>
                        <select class="form-control select2" name="category">
                            <option value="0">- No Category -</option>
                            <?php
                            $sql_categories = mysqli_query($mysqli, "SELECT category_id, category_name FROM categories WHERE category_type = 'Ticket' AND category_archived_at IS NULL");
                            while ($row = mysqli_fetch_assoc($sql_categories)) {
                                $category_id = intval($row['category_id']);
                                $category_name = nullable_htmlentities($row['category_name']);

                                ?>
                                <option value="<?php echo $category_id; ?>"<?php if ($category_id === $prefill_category) { echo ' selected'; } ?>><?php echo $category_name; ?></option>
                            <?php } ?>

                        </select>
                    </div>
                </div>
                </div>
            </div>

            <?php if (mysqli_num_rows($sql_assets) > 0) { ?>
                <div class="form-group">
                    <label>Asset</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-desktop"></i></span>
                        </div>
                        <select class="form-control select2" name="asset">
                            <option value="0">- None -</option>
                            <?php

                            while ($row = mysqli_fetch_assoc($sql_assets)) {
                                $asset_id = intval($row['asset_id']);
                                $asset_name = sanitizeInput($row['asset_name']);
                                $asset_type = sanitizeInput($row['asset_type']);
                                ?>
                                <option value="<?php echo $asset_id ?>"><?php echo "$asset_name ($asset_type)"; ?></option>
                                <?php
                            }
                            ?>
                        </select>
                    </div>
                </div>
            <?php } ?>


            <?php if ($catalog_fields) { ?>
                <h6 class="mt-4 mb-3">Request details</h6>
                <?= \ITFlow\ITSM\ServiceCatalogService::renderInputs($catalog_fields) ?>
            <?php } ?>

            <div class="form-group">
                <label>Details <strong class="text-danger">*</strong></label>
                <textarea class="form-control tinymce" name="details"></textarea>
            </div>

            <button class="btn btn-primary" name="add_ticket"><i class="fas fa-paper-plane me-2" aria-hidden="true"></i>Raise ticket</button>
            <a href="<?= $catalog_item ? 'service_catalog.php' : 'index.php' ?>" class="btn btn-link text-secondary">Cancel</a>

        </form>
      </div>
    </div>

<?php
require_once 'includes/footer.php';
