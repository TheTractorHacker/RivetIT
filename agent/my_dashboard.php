<?php

/*
 * My dashboard (agent/my_dashboard.php): a per-user widget dashboard. Each user adds, removes, hides, resizes and
 * reorders widgets from src/Reports/Widgets/WidgetRegistry.php; the layout is stored in dashboard_layouts.
 * Widgets a user's role cannot read are not offered or computed, and every widget query is limited to the
 * user's departments. Customising is plain server-rendered POST forms (CSRF-protected); no script is required.
 * The classic agent/dashboard.php is unchanged. An administrator can make this page the start page
 * (Admin > Settings > Defaults).
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

$ctx = new WidgetContext(
    intval($session_user_id),
    ReportScope::sessionIds(),
    static fn (string $module, int $level = 1): bool => lookupUserPermission($module) >= $level,
    !empty($config_ticket_csat_enable),
    !empty($config_avg_resolution_exclude_projects),
    intval($config_ticket_csat_low_rating_threshold ?? 2)
);
$allowed = WidgetRegistry::available($ctx);

// ---- Customise ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['layout_action'])) {
    validateCSRFToken($_POST['csrf_token'] ?? '');
    $layout = DashboardLayout::load($mysqli, $session_user_id, $allowed);
    $layout = DashboardLayout::apply($layout, (string) $_POST['layout_action'], (string) ($_POST['widget'] ?? ''), (string) ($_POST['size'] ?? ''), $allowed);
    DashboardLayout::save($mysqli, $session_user_id, $layout, $allowed);
    header('Location: my_dashboard.php' . (!empty($_POST['customise']) ? '?customise=1' : ''));
    exit;
}

require_once "includes/inc_all.php";

$customise = isset($_GET['customise']);
$layout = DashboardLayout::load($mysqli, $session_user_id, $allowed);
$in_layout = array_column($layout, 'id');
$addable = array_values(array_diff($allowed, $in_layout));
$defs = WidgetRegistry::definitions();
$csrf = nullable_htmlentities($_SESSION['csrf_token']);

$post_button = static function (string $action, string $widget, string $label, string $icon, string $extra = '', string $class = 'btn-outline-secondary') use ($csrf) {
    return '<form method="post" class="d-inline"><input type="hidden" name="csrf_token" value="' . $csrf . '"><input type="hidden" name="layout_action" value="' . $action . '">'
        . '<input type="hidden" name="widget" value="' . nullable_htmlentities($widget) . '"><input type="hidden" name="customise" value="1">' . $extra
        . '<button type="submit" class="btn btn-sm ' . $class . '" title="' . nullable_htmlentities($label) . '" aria-label="' . nullable_htmlentities($label) . '"><i class="fas fa-fw ' . $icon . '"></i></button></form>';
};
?>

<div class="mb-3 d-flex align-items-center justify-content-between flex-wrap" style="gap:.5rem;">
    <div>
        <h4 class="mb-0 fw-bold">My dashboard</h4>
        <small class="text-muted"><?php echo date('l, F j, Y'); ?></small>
    </div>
    <div class="d-flex gap-2">
        <?php if ($customise) { ?>
            <form method="post" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                <input type="hidden" name="layout_action" value="reset">
                <input type="hidden" name="customise" value="1">
                <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="fas fa-fw fa-undo me-1"></i>Reset to default</button>
            </form>
            <a class="btn btn-sm btn-primary" href="my_dashboard.php"><i class="fas fa-fw fa-check me-1"></i>Done</a>
        <?php } else { ?>
            <a class="btn btn-sm btn-outline-secondary" href="my_dashboard.php?customise=1"><i class="fas fa-fw fa-sliders-h me-1"></i>Customise</a>
            <a class="btn btn-sm btn-outline-secondary" href="dashboard.php"><i class="fas fa-fw fa-tachometer-alt me-1"></i>Classic dashboard</a>
        <?php } ?>
    </div>
</div>

<?php if ($customise && $addable) { ?>
<div class="card mb-3">
    <div class="card-body py-2 d-flex flex-wrap align-items-center gap-2">
        <span class="text-muted">Add a widget:</span>
        <?php foreach ($addable as $wid) { ?>
            <?php echo $post_button('add', $wid, 'Add ' . $defs[$wid]['title'], 'fa-plus', '', 'btn-outline-primary'); ?>
            <span class="me-2"><i class="fas fa-fw <?php echo nullable_htmlentities($defs[$wid]['icon']); ?> me-1"></i><?php echo nullable_htmlentities($defs[$wid]['title']); ?></span>
        <?php } ?>
    </div>
</div>
<?php } ?>

<?php if (!$layout) { ?>
    <div class="card"><div class="card-body text-center text-muted py-5">
        <?php echo $allowed ? 'No widgets on your dashboard. Choose Customise to add some.' : 'Your role does not have access to any dashboard widgets yet.'; ?>
    </div></div>
<?php } ?>

<div class="row g-3">
<?php foreach ($layout as $i => $entry) {
    $wid = $entry['id'];
    $def = $defs[$wid];
    if ($entry['hidden'] && !$customise) { continue; }
    $col = WidgetRegistry::SIZES[$entry['size']];
    ?>
    <div class="col-12 col-md-<?php echo $col === 4 ? 6 : $col; ?> col-xl-<?php echo $col; ?>" data-widget="<?php echo nullable_htmlentities($wid); ?>">
        <div class="card h-100<?php echo $entry['hidden'] ? ' opacity-50' : ''; ?>">
            <div class="card-header py-2 d-flex align-items-center justify-content-between flex-wrap gap-2">
                <h3 class="card-title mb-0"><i class="fas fa-fw <?php echo nullable_htmlentities($def['icon']); ?> me-2"></i><?php echo nullable_htmlentities($def['title']); ?></h3>
                <?php if ($customise) { ?>
                <div class="d-flex gap-1">
                    <?php echo $post_button('up', $wid, 'Move up', 'fa-arrow-up'); ?>
                    <?php echo $post_button('down', $wid, 'Move down', 'fa-arrow-down'); ?>
                    <?php foreach (['sm' => 'Small', 'md' => 'Medium', 'lg' => 'Wide'] as $sz => $szl) {
                        echo $post_button('size', $wid, "Size: $szl", $sz === 'sm' ? 'fa-compress-alt' : ($sz === 'md' ? 'fa-columns' : 'fa-expand-alt'), '<input type="hidden" name="size" value="' . $sz . '">', $entry['size'] === $sz ? 'btn-secondary' : 'btn-outline-secondary');
                    } ?>
                    <?php echo $post_button('toggle', $wid, $entry['hidden'] ? 'Show' : 'Hide', $entry['hidden'] ? 'fa-eye' : 'fa-eye-slash'); ?>
                    <?php echo $post_button('remove', $wid, 'Remove', 'fa-times', '', 'btn-outline-danger'); ?>
                </div>
                <?php } ?>
            </div>
            <div class="card-body">
                <?php echo $entry['hidden'] ? '<div class="text-muted">Hidden. Choose Show to bring it back.</div>' : WidgetRenderer::render(WidgetRegistry::data($wid, $mysqli, $ctx)); ?>
            </div>
        </div>
    </div>
<?php } ?>
</div>

<?php require_once "../includes/footer.php"; ?>
