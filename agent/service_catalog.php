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


// Popular this month (count of tickets per catalog item over the last 30 days)
require_once $_SERVER['DOCUMENT_ROOT'] . '/src/ITSM/ServiceCatalogService.php';
$catalog_service = new \ITFlow\ITSM\ServiceCatalogService($mysqli);
$catalog_trending = $catalog_service->trending(5, 30);

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

        <?php if (mysqli_num_rows($sql) == 0) { ?>
            <p class="text-secondary text-center py-4">No catalog items have been set up yet.</p>
        <?php } else { ?>
            <div class="d-flex flex-wrap align-items-center gap-2 mb-3" id="catalogFilterBar">
                <div class="input-group" style="max-width:22rem;">
                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                    <input type="search" class="form-control" id="catalogSearch" placeholder="Search requests: laptop, password, wifi..." aria-label="Search the service catalog" autocomplete="off">
                </div>
                <div class="d-flex flex-wrap gap-1" id="catalogCats" role="group" aria-label="Filter by category"></div>
                <span class="small text-secondary ms-auto" id="catalogCount" aria-live="polite"></span>
            </div>
            <p class="text-secondary text-center py-4 d-none" id="catalogNone">Nothing matches. Try a different word, or open a ticket from Tickets and describe it yourself.</p>
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
                    <div class="col-md-4 mb-4 catalog-col" data-search="<?= strtolower($name . ' ' . $description . ' ' . $category_name) ?>" data-cat="<?= $category_name ?>">
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
                                <div class="card-footer bg-transparent">
                                    <?php if ($category_name) { ?><span class="badge text-bg-secondary"><?= $category_name ?></span><?php } ?>
                                    <?php if ($default_priority) { ?><span class="badge text-bg-light"><?= $default_priority ?> Priority</span><?php } ?>
                                    <?php if (intval($row['requires_approval'])) { ?><span class="badge text-bg-warning">Needs approval</span><?php } ?>
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
// Instant filter: free text plus category chips, so a tech or end user finds the right request without scanning every tile.
(function () {
    var cols = Array.prototype.slice.call(document.querySelectorAll('.catalog-col')), box = document.getElementById('catalogSearch');
    if (!box || !cols.length) { return; }
    var chips = document.getElementById('catalogCats'), none = document.getElementById('catalogNone'), count = document.getElementById('catalogCount'), activeCat = '';
    var cats = [];
    cols.forEach(function (c) { var k = c.getAttribute('data-cat'); if (k && cats.indexOf(k) < 0) { cats.push(k); } });
    function mk(label, val) {
        var b = document.createElement('button'); b.type = 'button'; b.className = 'btn btn-sm btn-outline-secondary'; b.textContent = label; b.setAttribute('data-cat', val);
        b.addEventListener('click', function () { activeCat = val; apply(); }); chips.appendChild(b);
    }
    if (cats.length > 1) { mk('All', ''); cats.sort().forEach(function (k) { mk(k, k); }); }
    function apply() {
        var norm = function (t) { return t.toLowerCase().replace(/[^a-z0-9]+/g, ''); }, q = norm(box.value), shown = 0;
        cols.forEach(function (c) {
            var ok = (!q || norm(c.getAttribute('data-search')).indexOf(q) >= 0) && (!activeCat || c.getAttribute('data-cat') === activeCat);
            c.classList.toggle('d-none', !ok); if (ok) { shown++; }
        });
        Array.prototype.forEach.call(chips.children, function (b) { var on = b.getAttribute('data-cat') === activeCat; b.classList.toggle('btn-secondary', on); b.classList.toggle('btn-outline-secondary', !on); });
        none.classList.toggle('d-none', shown > 0);
        count.textContent = (box.value.trim() || activeCat) ? shown + ' of ' + cols.length + ' shown' : '';
    }
    box.addEventListener('input', apply); apply();
})();
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
</script>
