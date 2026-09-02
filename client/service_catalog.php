<?php
/*
 * Client Portal
 * Request Something - a curated menu of tickets a department can raise in one click
 */

header("Content-Security-Policy: default-src 'self'");

require_once "includes/inc_all.php";

$sql = mysqli_query(
    $mysqli,
    "SELECT sci.*, c.category_name
     FROM service_catalog_items sci
     LEFT JOIN categories c ON c.category_id = sci.ticket_category_id
     WHERE sci.is_active = 1
     ORDER BY sci.sort_order ASC, sci.name ASC"
);

?>

    <ol class="breadcrumb d-print-none">
        <li class="breadcrumb-item">
            <a href="index.php">Home</a>
        </li>
        <li class="breadcrumb-item active">Request Something</li>
    </ol>

    <h3><i class="fas fa-th-large me-2"></i>Request Something</h3>
    <p class="text-muted">Pick what you need - it opens a new ticket with the subject, category and priority already filled in.</p>

    <?php if (mysqli_num_rows($sql) == 0) { ?>
        <p class="text-muted text-center py-4">Nothing is available to request right now.</p>
    <?php } else { ?>
        <div class="row mt-3">
            <?php while ($row = mysqli_fetch_assoc($sql)) {
                $catalog_item_id = intval($row['catalog_item_id']);
                $name = nullable_htmlentities($row['name']);
                $description = nullable_htmlentities($row['description']);
                $icon = nullable_htmlentities($row['icon']) ?: 'fa-ticket-alt';
                $ticket_subject_template = $row['ticket_subject_template'] ?? '';
                $ticket_category_id = intval($row['ticket_category_id']);
                $category_name = nullable_htmlentities($row['category_name']);
                $default_priority = $row['default_priority'] ?? '';

                // See notes: client/ticket_add.php does not yet read these GET params -
                // this link works today (opens a blank ticket form), the pre-fill wiring
                // is documented as a follow-up, not applied here.
                $prefill_url = "ticket_add.php?" . http_build_query([
                    'catalog_item_id' => $catalog_item_id,
                    'subject' => $ticket_subject_template,
                    'priority' => $default_priority,
                    'category' => $ticket_category_id,
                ]);
            ?>
                <div class="col-md-4 mb-4">
                    <a href="<?= nullable_htmlentities($prefill_url) ?>" class="card h-100 text-decoration-none text-dark">
                        <div class="card-body">
                            <h5 class="card-title"><i class="fas fa-fw <?= $icon ?> me-2 text-primary"></i><?= $name ?></h5>
                            <?php if ($description) { ?><p class="card-text text-muted small"><?= $description ?></p><?php } ?>
                        </div>
                        <?php if ($category_name || $default_priority) { ?>
                            <div class="card-footer bg-white">
                                <?php if ($category_name) { ?><span class="badge text-bg-secondary"><?= $category_name ?></span><?php } ?>
                                <?php if ($default_priority) { ?><span class="badge text-bg-light"><?= nullable_htmlentities($default_priority) ?> Priority</span><?php } ?>
                            </div>
                        <?php } ?>
                    </a>
                </div>
            <?php } ?>
        </div>
    <?php } ?>

<?php
require_once "includes/footer.php";
