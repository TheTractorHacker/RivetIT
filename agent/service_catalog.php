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
$catalog_item_rows = [];
while ($sr = mysqli_fetch_assoc($sql)) { $catalog_item_rows[] = $sr; }

// Popular this month (count of tickets per catalog item over the last 30 days)
require_once $_SERVER['DOCUMENT_ROOT'] . '/src/ITSM/ServiceCatalogService.php';
$catalog_service = new \ITFlow\ITSM\ServiceCatalogService($mysqli);
$catalog_trending = $catalog_service->trending(5, 30);
$catalog_groups = $catalog_service->groupByCategory($catalog_item_rows);
$catalog_has_tiles = count(array_filter($catalog_groups, fn($g) => $g['id'] > 0)) > 0;
$catalog_fa = function ($icon, $fallback) { $i = preg_replace('/[^a-z0-9-]/', '', strtolower(trim((string) $icon))); $i = preg_replace('/^fa-/', '', $i); return 'fa-' . ($i !== '' ? $i : $fallback); };

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

        <?php if ($catalog_trending) { ?>
            <div class="mb-3">
                <div class="small text-secondary mb-1"><i class="fas fa-fire me-1"></i>Popular this month</div>
                <?php foreach ($catalog_trending as $trend) { ?>
                    <span class="badge text-bg-light me-1"><?= nullable_htmlentities($trend['name']) ?> <span class="text-secondary">(<?= intval($trend['uses']) ?>)</span></span>
                <?php } ?>
            </div>
        <?php } ?>

        <?php if (!$catalog_item_rows) { ?>
            <p class="text-secondary text-center py-4">No catalog items have been set up yet.</p>
        <?php } else { ?>

            <?php if ($catalog_has_tiles) { ?>
                <div class="catalog-cattiles" id="catalogCategoryTiles" role="group" aria-label="Request categories">
                    <button type="button" class="catalog-cattile active" data-cat="" aria-pressed="true">
                        <span class="catalog-cattile-icon"><i class="fas fa-th-large"></i></span>
                        <span class="catalog-cattile-name">Everything</span>
                        <span class="catalog-cattile-count"><?= count($catalog_item_rows) ?> requests</span>
                    </button>
                    <?php foreach ($catalog_groups as $g) { ?>
                        <button type="button" class="catalog-cattile" data-cat="<?= intval($g['id']) ?>" aria-pressed="false">
                            <span class="catalog-cattile-icon"><i class="fas <?= $catalog_fa($g['icon'], 'folder') ?>"></i></span>
                            <span class="catalog-cattile-name"><?= nullable_htmlentities($g['name']) ?></span>
                            <?php if ($g['description'] !== '') { ?><span class="catalog-cattile-desc"><?= nullable_htmlentities($g['description']) ?></span><?php } ?>
                            <span class="catalog-cattile-count"><?= count($g['items']) ?> request<?= count($g['items']) === 1 ? '' : 's' ?></span>
                        </button>
                    <?php } ?>
                </div>
            <?php } ?>

            <div id="serviceCatalogGrid">
            <?php foreach ($catalog_groups as $g) { ?>
                <section class="catalog-group mb-4" data-cat="<?= intval($g['id']) ?>">
                    <?php if ($catalog_has_tiles) { ?>
                        <h5 class="catalog-group-title"><i class="fas fa-fw <?= $catalog_fa($g['icon'], 'folder') ?> me-2 text-secondary"></i><?= nullable_htmlentities($g['name']) ?></h5>
                    <?php } ?>
                    <div class="row">
                    <?php foreach ($g['items'] as $row) {
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
                                data-item-id="<?= $catalog_item_id ?>"
                                data-subject="<?= $ticket_subject_template ?>"
                                data-priority="<?= $default_priority ?>"
                                data-category-id="<?= $ticket_category_id ?>">
                                <div class="card-body">
                                    <h5 class="card-title"><i class="fas fa-fw <?= $icon ?> me-2 text-primary"></i><?= $name ?></h5>
                                    <?php if ($description) { ?><p class="card-text text-secondary small"><?= $description ?></p><?php } ?>
                                </div>
                                <?php if ($category_name || $default_priority || intval($row['requires_approval'])) { ?>
                                    <div class="card-footer">
                                        <?php if ($category_name) { ?><span class="badge text-bg-secondary"><?= $category_name ?></span><?php } ?>
                                        <?php if ($default_priority) { ?><span class="badge text-bg-light"><?= $default_priority ?> Priority</span><?php } ?>
                                        <?php if (intval($row['requires_approval'])) { ?><span class="badge text-bg-warning">Needs approval</span><?php } ?>
                                    </div>
                                <?php } ?>
                            </button>
                        </div>
                    <?php } ?>
                    </div>
                </section>
            <?php } ?>
            </div>
        <?php } ?>
    </div>
</div>

<style>
    .catalog-cattiles { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: .75rem; margin-bottom: 1.25rem; }
    .catalog-cattile { display: flex; flex-direction: column; align-items: flex-start; gap: .3rem; text-align: left; padding: .85rem 1rem; cursor: pointer; font: inherit;
        background: var(--tblr-bg-surface, var(--bs-body-bg)); color: var(--tblr-body-color, var(--bs-body-color)); border: 1px solid var(--tblr-border-color, var(--bs-border-color)); border-radius: .5rem; }
    .catalog-cattile:hover { border-color: var(--tblr-primary, var(--bs-primary)); }
    .catalog-cattile:focus-visible { outline: 2px solid var(--tblr-primary, var(--bs-primary)); outline-offset: 2px; }
    .catalog-cattile.active { border-color: var(--tblr-primary, var(--bs-primary)); box-shadow: 0 0 0 1px var(--tblr-primary, var(--bs-primary)) inset; }
    .catalog-cattile-icon { width: 36px; height: 36px; display: inline-flex; align-items: center; justify-content: center; border-radius: .5rem; background: rgba(var(--tblr-primary-rgb, 13, 110, 253), .12); color: var(--tblr-primary, var(--bs-primary)); }
    .catalog-cattile-name { font-weight: 600; }
    .catalog-cattile-desc, .catalog-cattile-count { font-size: .8rem; color: var(--tblr-secondary, var(--bs-secondary-color)); }
    .catalog-group[hidden] { display: none; }
    .catalog-group-title { font-size: 1rem; margin-bottom: .75rem; }
    @media (max-width: 575.98px) { .catalog-cattiles { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>

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
    var itemId = tile.getAttribute('data-item-id') || '0';

    var modalParams = [];
    if (clientId && clientId !== '0') { modalParams.push('client_id=' + encodeURIComponent(clientId)); }
    if (itemId && itemId !== '0') { modalParams.push('catalog_item_id=' + encodeURIComponent(itemId)); }
    var modalUrl = 'modals/ticket/ticket_add_v2.php' + (modalParams.length ? '?' + modalParams.join('&') : '');

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

var catTiles = document.getElementById('catalogCategoryTiles');
if (catTiles) {
    catTiles.addEventListener('click', function (e) {
        var t = e.target.closest('.catalog-cattile');
        if (!t) { return; }
        var cat = t.getAttribute('data-cat') || '';
        catTiles.querySelectorAll('.catalog-cattile').forEach(function (b) {
            var on = b === t;
            b.classList.toggle('active', on);
            b.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        document.querySelectorAll('#serviceCatalogGrid .catalog-group').forEach(function (g) {
            g.hidden = cat !== '' && g.getAttribute('data-cat') !== cat;
        });
    });
}
</script>
