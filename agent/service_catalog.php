<?php

require_once "includes/inc_all.php";

enforceUserPermission('module_support');

// Optional: arriving from a client-scoped page keeps the New Ticket modal scoped to that department
$client_id = intval($_GET['client_id'] ?? 0);
if ($client_id) enforceClientAccess($client_id);

$sql = mysqli_query(
    $mysqli,
    "SELECT sci.*, c.category_name
     FROM service_catalog_items sci
     LEFT JOIN categories c ON c.category_id = sci.ticket_category_id
     WHERE sci.is_active = 1
     ORDER BY sci.sort_order ASC, sci.name ASC"
);

?>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-th-large me-2"></i>Request Something</h3>
        <?php if ($session_is_admin) { ?>
            <div class="card-tools">
                <a href="../admin/service_catalog.php" class="btn btn-secondary"><i class="fas fa-cog me-2"></i>Manage Catalog</a>
            </div>
        <?php } ?>
    </div>
    <div class="card-body">
        <p class="text-secondary">Pick what you need - it opens a new ticket with the subject, category and priority already filled in.</p>

        <?php if (mysqli_num_rows($sql) == 0) { ?>
            <p class="text-secondary text-center py-4">No catalog items have been set up yet.</p>
        <?php } else { ?>
            <div class="row" id="serviceCatalogGrid">
                <?php while ($row = mysqli_fetch_assoc($sql)) {
                    $catalog_item_id = intval($row['catalog_item_id']);
                    $name = nullable_htmlentities($row['name']);
                    $description = nullable_htmlentities($row['description']);
                    $icon = nullable_htmlentities($row['icon']) ?: 'fa-ticket-alt';
                    $ticket_subject_template = nullable_htmlentities($row['ticket_subject_template']);
                    $ticket_category_id = intval($row['ticket_category_id']);
                    $category_name = nullable_htmlentities($row['category_name']);
                    $default_priority = nullable_htmlentities($row['default_priority']);
                ?>
                    <div class="col-md-4 mb-4">
                        <button type="button" class="card h-100 w-100 text-start border-0 shadow-sm service-catalog-tile"
                            data-subject="<?= $ticket_subject_template ?>"
                            data-priority="<?= $default_priority ?>"
                            data-category-id="<?= $ticket_category_id ?>">
                            <div class="card-body">
                                <h5 class="card-title"><i class="fas fa-fw <?= $icon ?> me-2 text-primary"></i><?= $name ?></h5>
                                <?php if ($description) { ?><p class="card-text text-secondary small"><?= $description ?></p><?php } ?>
                            </div>
                            <?php if ($category_name || $default_priority) { ?>
                                <div class="card-footer bg-white">
                                    <?php if ($category_name) { ?><span class="badge text-bg-secondary"><?= $category_name ?></span><?php } ?>
                                    <?php if ($default_priority) { ?><span class="badge text-bg-light"><?= $default_priority ?> Priority</span><?php } ?>
                                </div>
                            <?php } ?>
                        </button>
                    </div>
                <?php } ?>
            </div>
        <?php } ?>
    </div>
</div>

<?php
require_once "../includes/footer.php";
?>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '', ENT_QUOTES) ?>">
document.getElementById('serviceCatalogGrid') && document.getElementById('serviceCatalogGrid').addEventListener('click', function (e) {
    var tile = e.target.closest('.service-catalog-tile');
    if (!tile) { return; }

    var subject = tile.getAttribute('data-subject') || '';
    var priority = tile.getAttribute('data-priority') || '';
    var categoryId = tile.getAttribute('data-category-id') || '0';
    var clientId = <?= json_encode((string) $client_id) ?>;

    var modalUrl = 'modals/ticket/ticket_add_v2.php' + (clientId && clientId !== '0' ? '?client_id=' + encodeURIComponent(clientId) : '');

    window.openAjaxModal(modalUrl, 'lg', {
        onShown: function (modalEl) {
            function apply() {
                var subjectEl = modalEl.querySelector('#subjectInput');
                if (subjectEl && subject) { subjectEl.value = subject; }

                var prioritySel = modalEl.querySelector('select[name="priority"]');
                if (prioritySel && priority) {
                    prioritySel.value = priority;
                    if (prioritySel.tomselect) { prioritySel.tomselect.setValue(priority); }
                }

                var categorySel = modalEl.querySelector('select[name="category_id"]');
                if (categorySel && categoryId && categoryId !== '0') {
                    categorySel.value = categoryId;
                    if (categorySel.tomselect) { categorySel.tomselect.setValue(categoryId); }
                }
            }
            // Once immediately (plain <select> - covers pre-TomSelect state), once
            // after modal_footer.php's re-executed app.js has had time to turn these
            // into TomSelect widgets (see js/app.js initSelect2Widgets comment).
            apply();
            setTimeout(apply, 250);
        }
    });
});
</script>
