<?php
/*
 * Client Portal
 * Request Something - a curated menu of tickets a department can raise in one click.
 * Picking an item opens ticket_add.php?catalog_item_id=N, which reads the item back from the database.
 */

header("Content-Security-Policy: default-src 'self'");

require_once "includes/inc_all.php";

$sql = mysqli_query(
    $mysqli,
    "SELECT sci.*, c.category_name
     FROM service_catalog_items sci
     LEFT JOIN categories c ON c.category_id = sci.ticket_category_id AND c.category_archived_at IS NULL
     WHERE sci.is_active = 1
     ORDER BY sci.sort_order ASC, sci.name ASC"
);

$catalog_items = [];
$catalog_categories = [];
while ($row = mysqli_fetch_assoc($sql)) {
    // The admin form stores "fa-laptop" or a bare "laptop"; both must render as a Font Awesome class.
    $icon = preg_replace('/[^a-z0-9-]/', '', strtolower(trim((string) $row['icon'])));
    $icon = preg_replace('/^fa-/', '', $icon);
    $row['icon_class'] = 'fa-' . ($icon !== '' ? $icon : 'ticket-alt');
    $row['cat_label'] = trim((string) $row['category_name']) !== '' ? $row['category_name'] : 'General';
    $catalog_categories[$row['cat_label']] = true;
    $catalog_items[] = $row;
}
ksort($catalog_categories);

// Popular this month / recently used by you (pure queries over tickets.ticket_catalog_item_id)
require_once $_SERVER['DOCUMENT_ROOT'] . '/src/ITSM/ServiceCatalogService.php';
$catalog_service = new \ITFlow\ITSM\ServiceCatalogService($mysqli);
$catalog_trending = $catalog_service->trending(5, 30);
$catalog_recent = $catalog_service->recentForContact(intval($session_contact_id), intval($session_client_id), 5);
if (isset($catalog_categories['General'])) { // "General" last
    unset($catalog_categories['General']);
    $catalog_categories['General'] = true;
}

// Catalog categories (Administration > Service Catalog > Categories) drive the tile row. With none set up the page keeps its old
// behaviour: chips made from each item's ticket category.
$catalog_groups = $catalog_service->groupByCategory($catalog_items);
$catalog_has_tiles = count(array_filter($catalog_groups, fn($g) => $g['id'] > 0)) > 0;
if ($catalog_has_tiles) {
    $group_name_by_id = [];
    foreach ($catalog_groups as $g) { $group_name_by_id[$g['id']] = $g['name']; }
    foreach ($catalog_items as &$ci) {
        $gid = 0;
        foreach ($catalog_groups as $g) { foreach ($g['items'] as $gi) { if (intval($gi['catalog_item_id']) === intval($ci['catalog_item_id'])) { $gid = $g['id']; } } }
        $ci['cat_label'] = $group_name_by_id[$gid] ?? 'Other';
    }
    unset($ci);
}

?>

    <ol class="breadcrumb d-print-none">
        <li class="breadcrumb-item">
            <a href="index.php">Home</a>
        </li>
        <li class="breadcrumb-item active">Request Something</li>
    </ol>

    <div class="portal-pagehead">
        <div>
            <h2 class="portal-pagehead-title">What do you need?</h2>
            <p class="text-secondary mb-0">Pick a request and we open the ticket for you, with the subject, category and priority already filled in.</p>
        </div>
    </div>

    <?php if (count($catalog_items) === 0) { ?>
        <div class="card portal-card">
            <div class="portal-empty">
                <i class="fas fa-concierge-bell" aria-hidden="true"></i>
                Nothing is on the request menu yet.
                <div class="mt-3"><a href="ticket_add.php" class="btn btn-primary"><i class="fas fa-plus me-2" aria-hidden="true"></i>Raise a ticket</a></div>
            </div>
        </div>
    <?php } else { ?>

        <?php foreach ([['Popular this month', 'fa-fire', $catalog_trending], ['Recently used by you', 'fa-history', $catalog_recent]] as [$shelf_title, $shelf_icon, $shelf_rows]) { if (!$shelf_rows) { continue; } ?>
            <div class="mb-3">
                <div class="small text-secondary mb-1"><i class="fas <?= $shelf_icon ?> me-1" aria-hidden="true"></i><?= $shelf_title ?></div>
                <?php foreach ($shelf_rows as $shelf) { ?>
                    <a href="ticket_add.php?catalog_item_id=<?= intval($shelf['catalog_item_id']) ?>" class="portal-badge portal-badge--muted me-1"><?= nullable_htmlentities($shelf['name']) ?></a>
                <?php } ?>
            </div>
        <?php } ?>

        <div class="portal-filterbar" data-portal-filter>
            <div class="portal-search">
                <i class="fas fa-search" aria-hidden="true"></i>
                <input type="search" class="form-control" placeholder="Search requests" aria-label="Search requests" data-portal-filter-input autocomplete="off" value="<?= nullable_htmlentities(mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100)) ?>">
            </div>
            <?php if ($catalog_has_tiles) { ?>
                <div class="portal-cattiles" role="group" aria-label="Request categories" data-portal-cattiles>
                    <button type="button" class="portal-cattile is-active" data-portal-filter-chip="" aria-pressed="true">
                        <span class="portal-cattile-icon"><i class="fas fa-th-large" aria-hidden="true"></i></span>
                        <span class="portal-cattile-name">Everything</span>
                        <span class="portal-cattile-count"><?= count($catalog_items) ?> requests</span>
                    </button>
                    <?php foreach ($catalog_groups as $g) {
                        $g_icon = preg_replace('/[^a-z0-9-]/', '', strtolower(trim((string) $g['icon'])));
                        $g_icon = 'fa-' . (preg_replace('/^fa-/', '', $g_icon) ?: 'folder'); ?>
                        <button type="button" class="portal-cattile" data-portal-filter-chip="<?= nullable_htmlentities($g['name']) ?>" aria-pressed="false">
                            <span class="portal-cattile-icon"><i class="fas <?= $g_icon ?>" aria-hidden="true"></i></span>
                            <span class="portal-cattile-name"><?= nullable_htmlentities($g['name']) ?></span>
                            <?php if ($g['description'] !== '') { ?><span class="portal-cattile-desc"><?= nullable_htmlentities($g['description']) ?></span><?php } ?>
                            <span class="portal-cattile-count"><?= count($g['items']) ?> request<?= count($g['items']) === 1 ? '' : 's' ?></span>
                        </button>
                    <?php } ?>
                </div>
            <?php } ?>

            <?php if (!$catalog_has_tiles && count($catalog_categories) > 1) { ?>
                <div class="portal-chips" role="group" aria-label="Filter by category">
                    <button type="button" class="portal-chip is-active" data-portal-filter-chip="" aria-pressed="true">All</button>
                    <?php foreach (array_keys($catalog_categories) as $cat) { ?>
                        <button type="button" class="portal-chip" data-portal-filter-chip="<?= nullable_htmlentities($cat) ?>" aria-pressed="false"><?= nullable_htmlentities($cat) ?></button>
                    <?php } ?>
                </div>
            <?php } ?>
        </div>

        <div class="portal-requests portal-stagger" data-portal-filter-list>
            <?php foreach ($catalog_items as $row) {
                $catalog_item_id = intval($row['catalog_item_id']);
                $name = nullable_htmlentities($row['name']);
                $description = nullable_htmlentities($row['description']);
                $default_priority = in_array($row['default_priority'], ['Low', 'Medium', 'High'], true) ? $row['default_priority'] : '';
                $search = strtolower($row['name'] . ' ' . $row['description'] . ' ' . $row['cat_label']);
            ?>
                <a href="ticket_add.php?catalog_item_id=<?= $catalog_item_id ?>" class="portal-request"
                   data-portal-filter-item data-cat="<?= nullable_htmlentities($row['cat_label']) ?>" data-search="<?= nullable_htmlentities($search) ?>">
                    <span class="portal-request-icon"><i class="fas fa-fw <?= $row['icon_class'] ?>" aria-hidden="true"></i></span>
                    <span class="portal-request-body">
                        <span class="portal-request-name"><?= $name ?></span>
                        <?php if ($description) { ?><span class="portal-request-desc"><?= $description ?></span><?php } ?>
                        <span class="portal-request-meta">
                            <span class="portal-badge portal-badge--muted"><?= nullable_htmlentities($row['cat_label']) ?></span>
                            <?php if ($default_priority) { ?><span class="portal-badge portal-badge--prio-<?= strtolower($default_priority) ?>"><?= $default_priority ?> priority</span><?php } ?>
                        </span>
                    </span>
                    <i class="fas fa-arrow-right portal-request-go" aria-hidden="true"></i>
                </a>
            <?php } ?>
            <a href="ticket_add.php" class="portal-request portal-request--other" data-portal-filter-other>
                <span class="portal-request-icon"><i class="fas fa-fw fa-pen" aria-hidden="true"></i></span>
                <span class="portal-request-body">
                    <span class="portal-request-name">Something else</span>
                    <span class="portal-request-desc">Don't see it here? Describe the problem or request in your own words.</span>
                </span>
                <i class="fas fa-arrow-right portal-request-go" aria-hidden="true"></i>
            </a>
        </div>

        <div class="card portal-card d-none" data-portal-filter-empty>
            <div class="portal-empty">
                <i class="fas fa-search" aria-hidden="true"></i>
                No requests match. <a href="ticket_add.php">Raise a ticket</a> and tell us what you need.
            </div>
        </div>

    <?php } ?>

<?php
require_once "includes/footer.php";
