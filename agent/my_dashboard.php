<?php

/*
 * My dashboard (agent/my_dashboard.php): THE dashboard - a per-user widget dashboard. Each user adds, removes,
 * hides, resizes and reorders widgets from src/Reports/Widgets/WidgetRegistry.php; the layout is stored in
 * dashboard_layouts. Widgets a user's role cannot read are not offered or computed, and every widget query is
 * limited to the user's departments (financial widgets are the one deliberate exception - see the note above
 * WidgetRegistry::FINANCE_WIDGETS). Customising is plain server-rendered POST forms (CSRF-protected); no script
 * is required. agent/dashboard.php (the old fixed-layout page) now just redirects here - its content lives on
 * as widgets a user can add if they want them, same as everything else. An administrator can make this the
 * start page (Admin > Settings > Defaults).
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';

use ITFlow\Reports\ReportScope;
use ITFlow\Reports\Widgets\DashboardLayout;
use ITFlow\Reports\Widgets\WidgetContext;
use ITFlow\Reports\Widgets\WidgetRegistry;
use ITFlow\Reports\Widgets\WidgetRenderer;

if (itflow_is_limited_user()) {   // belt and braces; check_login.php already denies module-only logins
    header('Location: ' . itflow_home_url());
    exit;
}

// A handful of widgets ported from the classic dashboard (Historical Tickets, Financial) are "for a year", same
// as that page's own (now-removed-everywhere-else) year picker. Not saved - a plain GET param, same as before.
$dash_year = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));
if ($dash_year < 2000 || $dash_year > 2100) {
    $dash_year = intval(date('Y'));
}

$ctx = new WidgetContext(
    intval($session_user_id),
    ReportScope::sessionIds(),
    static fn (string $module, int $level = 1): bool => lookupUserPermission($module) >= $level,
    !empty($config_ticket_csat_enable),
    !empty($config_avg_resolution_exclude_projects),
    intval($config_ticket_csat_low_rating_threshold ?? 2),
    $dash_year,
    !empty($config_dashboard_avg_resolution_enable)
);
$allowed = WidgetRegistry::available($ctx);

// ---- Customise ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['layout_action'])) {
    validateCSRFToken($_POST['csrf_token'] ?? '');
    $layout = DashboardLayout::load($mysqli, $session_user_id, $allowed);
    // Drag-and-drop reorder (agent/js/my_dashboard.js): a plain fetch() with the dragged DOM's new widget-id
    // order, not a page navigation - answers JSON instead of redirecting, since Sortable already moved the
    // cards client-side and a reload would just be a flash with nothing new to show.
    if ($_POST['layout_action'] === 'reorder') {
        $order = json_decode((string) ($_POST['order'] ?? '[]'), true);
        $order = is_array($order) ? array_values(array_filter($order, 'is_string')) : [];
        $layout = DashboardLayout::reorder($layout, $order);
        DashboardLayout::save($mysqli, $session_user_id, $layout, $allowed);
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    }
    $layout = DashboardLayout::apply($layout, (string) $_POST['layout_action'], (string) ($_POST['widget'] ?? ''), (string) ($_POST['size'] ?? ''), $allowed, (string) ($_POST['chart'] ?? ''));
    DashboardLayout::save($mysqli, $session_user_id, $layout, $allowed);
    header('Location: my_dashboard.php' . (!empty($_POST['customise']) ? '?customise=1' : ''));
    exit;
}

require_once "includes/inc_all.php";

$customise = isset($_GET['customise']);
$layout = DashboardLayout::load($mysqli, $session_user_id, $allowed);
$in_layout = array_column($layout, 'id');
$addable = array_values(array_diff($allowed, $in_layout));
$at_cap = count($layout) >= DashboardLayout::MAX_WIDGETS;   // DashboardLayout::save() drops anything past the cap, so say so instead of letting Add do nothing
$defs = WidgetRegistry::definitions();
$csrf = nullable_htmlentities($_SESSION['csrf_token']);

$post_button = static function (string $action, string $widget, string $label, string $icon, string $extra = '', string $class = 'btn-outline-secondary') use ($csrf) {
    return '<form method="post" class="d-inline"><input type="hidden" name="csrf_token" value="' . $csrf . '"><input type="hidden" name="layout_action" value="' . $action . '">'
        . '<input type="hidden" name="widget" value="' . nullable_htmlentities($widget) . '"><input type="hidden" name="customise" value="1">' . $extra
        . '<button type="submit" class="btn btn-sm ' . $class . '" title="' . nullable_htmlentities($label) . '" aria-label="' . nullable_htmlentities($label) . '"><i class="fas fa-fw ' . $icon . '"></i></button></form>';
};
?>
<style nonce="<?php echo $csp_nonce; ?>">
    .dash-drag { cursor: grab; }
    .dash-drag:active { cursor: grabbing; }
    #dash-widgets .sortable-ghost { opacity: .4; }
    #dash-widgets .sortable-chosen .card { box-shadow: 0 0 0 2px var(--tblr-primary, #0054a6); }
    .dash-add-category { display: flex; align-items: baseline; gap: .5rem; font-size: .8rem; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; margin-bottom: .5rem; color: var(--if-muted, #4b5d64); }
    .dash-add-count { font-weight: 500; letter-spacing: 0; text-transform: none; color: var(--if-muted, #4b5d64); }
    /* Widget picker chips: a calm neutral chip with an accent icon, not an outline button in the brand colour. */
    .dash-chip { display: inline-flex; align-items: center; gap: .45rem; height: 32px; padding: 0 .8rem; font: 500 13px/1 var(--if-sans, inherit); white-space: nowrap; cursor: pointer;
        color: var(--if-ink, var(--tblr-body-color, #16232a)); background: var(--if-surface, var(--tblr-bg-surface, #fff)); border: 1px solid var(--if-border-strong, #c5d0d4); border-radius: 8px; }
    .dash-chip i { color: var(--color-accent, var(--tblr-primary, #206bc4)); }
    .dash-chip:hover { border-color: var(--color-accent, var(--tblr-primary, #206bc4)); background: color-mix(in srgb, var(--color-accent, var(--tblr-primary, #206bc4)) 8%, var(--if-surface, var(--tblr-bg-surface, #fff))); }
    .dash-chip:focus-visible, .dash-toggle:focus-visible { outline: 2px solid var(--color-accent, var(--tblr-primary, #206bc4)); outline-offset: 2px; }
    .dash-chip:focus:not(:focus-visible), .dash-toggle:focus:not(:focus-visible) { outline: 0; box-shadow: none; }
    .dash-chip.is-added, .dash-chip:disabled { cursor: default; opacity: .6; background: transparent; }
    /* "N available": a plain bordered button; the expanded state is a quiet tint, never a ring. */
    .dash-toggle { display: inline-flex; align-items: center; height: 30px; padding: 0 .7rem; font: 500 13px/1 var(--if-sans, inherit); cursor: pointer; color: var(--if-ink, var(--tblr-body-color, #16232a));
        background: var(--if-surface, var(--tblr-bg-surface, #fff)); border: 1px solid var(--if-border-strong, #c5d0d4); border-radius: 8px; box-shadow: none; }
    .dash-toggle:hover, .dash-toggle[aria-expanded="true"] { background: color-mix(in srgb, var(--color-accent, var(--tblr-primary, #206bc4)) 8%, var(--if-surface, var(--tblr-bg-surface, #fff))); }
    button[data-bs-target="#dash-add-widget"] .fa-chevron-down { transition: transform .15s ease; }
    button[data-bs-target="#dash-add-widget"][aria-expanded="true"] .fa-chevron-down { transform: rotate(180deg); }
</style>

<div class="mb-3 d-flex align-items-center justify-content-between flex-wrap" style="gap:.5rem;">
    <div>
        <h4 class="mb-0 fw-bold">My dashboard</h4>
        <small class="text-muted"><?php echo date('l, F j, Y'); ?></small>
    </div>
    <div class="d-flex align-items-center gap-2">
        <?php
        // Only worth showing when a "for a year" widget is actually on the dashboard - most users have none of
        // these and the control would otherwise be dead weight with nothing on the page for it to affect.
        $dash_has_year_widget = (bool) array_intersect($in_layout, ['departments_added_year', 'contacts_added_year', 'assets_added_year', 'tickets_flow_year',
            'tickets_created_year', 'tickets_resolved_year', 'avg_resolution_year', 'tickets_resolved_by_technician_year']);
        if ($dash_has_year_widget) { ?>
            <form method="get" class="d-flex align-items-center gap-1">
                <?php if ($customise) { ?><input type="hidden" name="customise" value="1"><?php } ?>
                <label for="dash-year" class="mb-0 small text-muted">Year</label>
                <select id="dash-year" name="year" class="form-select form-select-sm auto-submit-select" style="width:auto;min-width:6rem;">
                    <?php for ($y = intval(date('Y')); $y >= intval(date('Y')) - 5; $y--) { ?>
                        <option value="<?php echo $y; ?>" <?php echo $y === $dash_year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                    <?php } ?>
                </select>
            </form>
        <?php } ?>
        <?php if ($customise) { ?>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#dash-reset-modal"><i class="fas fa-fw fa-undo me-1" aria-hidden="true"></i>Reset to default</button>
            <a class="btn btn-sm btn-primary" href="my_dashboard.php"><i class="fas fa-fw fa-check me-1"></i>Done</a>
        <?php } else { ?>
            <a class="btn btn-sm btn-outline-secondary" href="my_dashboard.php?customise=1"><i class="fas fa-fw fa-sliders-h me-1"></i>Customise</a>
        <?php } ?>
    </div>
</div>

<?php if ($customise) { ?>
<div class="modal fade" id="dash-reset-modal" tabindex="-1" aria-labelledby="dash-reset-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="post" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
            <input type="hidden" name="layout_action" value="reset">
            <input type="hidden" name="customise" value="1">
            <div class="modal-header">
                <h5 class="modal-title" id="dash-reset-title">Reset your dashboard?</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">Your widgets, their order, sizes and chart types go back to the default layout. This only changes your own dashboard.</div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Reset to default</button>
            </div>
        </form>
    </div>
</div>
<?php } ?>

<?php if ($customise && $addable) { ?>
<div class="card mb-3">
    <div class="card-header py-2 d-flex align-items-center justify-content-between">
        <strong><i class="fas fa-fw fa-plus me-1"></i>Add a widget</strong>
        <button class="dash-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#dash-add-widget" aria-expanded="false" aria-controls="dash-add-widget">
            <?php echo count($addable); ?> available <i class="fas fa-fw fa-chevron-down ms-1" aria-hidden="true"></i>
        </button>
    </div>
    <div class="collapse" id="dash-add-widget">
        <div class="card-body py-2">
            <?php if ($at_cap) { ?>
                <div class="alert alert-warning py-2 mb-3" role="status">Your dashboard already has <?php echo DashboardLayout::MAX_WIDGETS; ?> widgets, the most it can hold. Remove one to add another.</div>
            <?php } ?>
            <?php foreach (WidgetRegistry::categories() as $catLabel => $catIds) {
                $catAddable = array_values(array_intersect($catIds, $addable));
                if (!$catAddable) { continue; }
            ?>
            <div class="mb-3">
                <div class="dash-add-category">
                    <?php echo nullable_htmlentities($catLabel); ?> <span class="dash-add-count"><?php echo count($catAddable); ?></span>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($catAddable as $wid) { ?>
                        <form method="post" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                            <input type="hidden" name="layout_action" value="add">
                            <input type="hidden" name="widget" value="<?php echo nullable_htmlentities($wid); ?>">
                            <input type="hidden" name="customise" value="1">
                            <button type="submit" class="dash-chip" <?php echo $at_cap ? 'disabled title="Your dashboard is full: remove a widget first"' : 'title="Add &ldquo;' . nullable_htmlentities($defs[$wid]['title']) . '&rdquo; to your dashboard"'; ?>>
                                <i class="fas fa-fw <?php echo nullable_htmlentities($defs[$wid]['icon']); ?>" aria-hidden="true"></i><?php echo nullable_htmlentities($defs[$wid]['title']); ?>
                            </button>
                        </form>
                    <?php } ?>
                </div>
            </div>
            <?php } ?>
        </div>
    </div>
</div>
<?php } ?>

<?php if (!$layout) { ?>
    <div class="card"><div class="card-body text-center text-muted py-5">
        <?php echo $allowed ? 'No widgets on your dashboard. Choose Customise to add some.' : 'Your role does not have access to any dashboard widgets yet.'; ?>
    </div></div>
<?php } ?>

<div class="row g-3" id="dash-widgets" data-csrf="<?php echo $csrf; ?>">
<?php foreach ($layout as $i => $entry) {
    $wid = $entry['id'];
    $def = $defs[$wid];
    if ($entry['hidden'] && !$customise) { continue; }
    $col = WidgetRegistry::SIZES[$entry['size']];
    ?>
    <div class="col-12 col-md-<?php echo $col === 4 ? 6 : $col; ?> col-xl-<?php echo $col; ?>" data-widget="<?php echo nullable_htmlentities($wid); ?>">
        <div class="card h-100<?php echo $entry['hidden'] ? ' opacity-50' : ''; ?>">
            <div class="card-header py-2 d-flex align-items-center justify-content-between flex-wrap gap-2">
                <h3 class="card-title mb-0 d-flex align-items-center">
                    <?php if ($customise) { ?><span class="dash-drag me-2" title="Drag to reorder" aria-hidden="true"><i class="fas fa-fw fa-grip-vertical text-muted"></i></span><?php } ?>
                    <i class="fas fa-fw <?php echo nullable_htmlentities($def['icon']); ?> me-2"></i><?php echo nullable_htmlentities($def['title']); ?>
                </h3>
                <?php if ($customise) { ?>
                <div class="d-flex gap-1">
                    <?php foreach (['sm' => 'Small', 'md' => 'Medium', 'lg' => 'Wide'] as $sz => $szl) {
                        echo $post_button('size', $wid, "Size: $szl", $sz === 'sm' ? 'fa-compress-alt' : ($sz === 'md' ? 'fa-columns' : 'fa-expand-alt'), '<input type="hidden" name="size" value="' . $sz . '">', $entry['size'] === $sz ? 'btn-secondary' : 'btn-outline-secondary');
                    } ?>
                    <?php
                    $chartOpts = WidgetRegistry::chartOptions($wid);
                    $chartIcons = ['list' => 'fa-list', 'bar' => 'fa-chart-bar', 'pie' => 'fa-chart-pie', 'line' => 'fa-chart-line'];
                    foreach ($chartOpts as $cv => $cl) {
                        echo $post_button('chart', $wid, "Chart: $cl", $chartIcons[$cv] ?? 'fa-chart-bar', '<input type="hidden" name="chart" value="' . $cv . '">', ($entry['chart'] ?? '') === $cv ? 'btn-secondary' : 'btn-outline-secondary');
                    }
                    ?>
                    <?php echo $post_button('toggle', $wid, $entry['hidden'] ? 'Show' : 'Hide', $entry['hidden'] ? 'fa-eye' : 'fa-eye-slash'); ?>
                    <?php echo $post_button('remove', $wid, 'Remove', 'fa-times', '', 'btn-outline-danger'); ?>
                </div>
                <?php } ?>
            </div>
            <div class="card-body">
                <?php echo $entry['hidden'] ? '<div class="text-muted">Hidden. Choose Show to bring it back.</div>' : WidgetRenderer::render(WidgetRegistry::data($wid, $mysqli, $ctx), $entry['chart'] ?? null); ?>
            </div>
        </div>
    </div>
<?php } ?>
</div>

<script src="/agent/js/dashboard_widget_charts.js?v=<?php echo filemtime(__DIR__ . '/js/dashboard_widget_charts.js'); ?>" defer></script>
<?php if ($customise) { ?>
<script src="/plugins/SortableJS/Sortable.min.js" defer></script>
<script src="/agent/js/my_dashboard.js?v=<?php echo filemtime(__DIR__ . '/js/my_dashboard.js'); ?>" defer></script>
<?php } ?>
<?php require_once "../includes/footer.php"; ?>
