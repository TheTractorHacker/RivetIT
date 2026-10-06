<?php

/*
 * Saved report views (agent/reports/saved_views.php)
 *
 *   GET  ?go=ID            open a saved view (own, or shared by someone else) as its report with the stored filters
 *   POST action=save       save the filters of the report page you are on (report + p[...] whitelisted server-side)
 *   POST action=update     rename / share / unshare a view you own
 *   POST action=delete     delete a view you own
 *   GET                    manage list
 *
 * Every mutation is CSRF-protected and ownership-checked in ITFlow\Reports\SavedReports; stored parameters are
 * re-validated against ITFlow\Reports\ReportCatalog both when saved and when opened.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';

use ITFlow\Reports\ReportCatalog;
use ITFlow\Reports\SavedReports;

enforceUserPermission('module_reporting');

$flash = static function (string $type, string $msg): void {
    $_SESSION['alert_type'] = $type;
    $_SESSION['alert_message'] = $msg;
};
$can_module = static function (string $key): bool {
    $m = ReportCatalog::module($key);
    return $m === null || lookupUserPermission($m) >= 1;
};

// ---- Open a saved view ----------------------------------------------------
if (isset($_GET['go'])) {
    $id = intval($_GET['go']);
    $row = $id > 0 ? SavedReports::get($mysqli, $id) : null;
    if ($row && SavedReports::canView($row, $session_user_id) && $can_module($row['saved_report_key'])) {
        header('Location: ' . ReportCatalog::url($row['saved_report_key'], $row['params']));
        exit;
    }
    // Not found, not yours, or the report is not readable with your role: same answer for all three (no probing).
    if ($id > 0) {
        $flash('danger', 'That saved view is not available.');
    }
    $back = isset($_GET['report']) && ReportCatalog::exists((string) $_GET['report']) ? '/agent/reports/' . $_GET['report'] . '.php' : '/agent/reports/saved_views.php';
    header('Location: ' . $back);
    exit;
}

// ---- Mutations --------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRFToken($_POST['csrf_token'] ?? '');
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save') {
        $key = (string) ($_POST['report'] ?? '');
        $params = is_array($_POST['p'] ?? null) ? $_POST['p'] : [];
        if (!ReportCatalog::exists($key) || !$can_module($key)) {
            $flash('danger', 'You cannot save a view of that report.');
            header('Location: /agent/reports/');
            exit;
        }
        $result = SavedReports::create($mysqli, $session_user_id, $key, (string) ($_POST['name'] ?? ''), $params, !empty($_POST['shared']));
        if (is_int($result)) {
            logAction('Report View', 'Create', "$session_name saved report view #$result of " . ReportCatalog::label($key), 0, $result);
            $flash('success', 'Saved view created.');
        } else {
            $flash('danger', $result);
        }
        header('Location: ' . ReportCatalog::url($key, $params));
        exit;
    }

    if ($action === 'update' || $action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($action === 'update') {
            $ok = SavedReports::update($mysqli, $id, $session_user_id, $session_is_admin, (string) ($_POST['name'] ?? ''), !empty($_POST['shared']));
            if ($ok) {
                logAction('Report View', 'Edit', "$session_name edited report view #$id", 0, $id);
            }
            $flash($ok ? 'success' : 'danger', $ok ? 'Saved view updated.' : 'Could not update that view.');
        } else {
            $ok = SavedReports::delete($mysqli, $id, $session_user_id, $session_is_admin);
            if ($ok) {
                logAction('Report View', 'Delete', "$session_name deleted report view #$id", 0, $id);
            }
            $flash($ok ? 'success' : 'danger', $ok ? 'Saved view deleted.' : 'Could not delete that view.');
        }
        header('Location: /agent/reports/saved_views.php');
        exit;
    }

    header('Location: /agent/reports/saved_views.php');
    exit;
}

// ---- Manage list ----------------------------------------------------------
require_once "includes/inc_all_reports.php";

$views = SavedReports::listFor($mysqli, $session_user_id, null, static fn ($m) => $m === '' || lookupUserPermission($m) >= 1);
$mine = array_filter($views, static fn ($v) => intval($v['saved_report_user_id']) === $session_user_id);
$others = array_filter($views, static fn ($v) => intval($v['saved_report_user_id']) !== $session_user_id);
?>

<div class="card card-dark mb-3">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-bookmark me-2"></i>My saved views</h3>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive-sm">
            <table class="table table-striped mb-0">
                <thead><tr><th>Report</th><th>Name</th><th>Filters</th><th>Shared</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                <?php if (!$mine) { ?>
                    <tr><td colspan="5" class="text-center text-muted">No saved views yet. Open a report, set its filters and choose Save this view.</td></tr>
                <?php } foreach ($mine as $v) { $vid = intval($v['saved_report_id']); ?>
                    <tr>
                        <td><a href="<?php echo nullable_htmlentities(ReportCatalog::url($v['saved_report_key'], $v['params'])); ?>"><?php echo nullable_htmlentities(ReportCatalog::label($v['saved_report_key'])); ?></a></td>
                        <td>
                            <form method="post" id="sv-form-<?php echo $vid; ?>">
                                <input type="hidden" name="csrf_token" value="<?php echo nullable_htmlentities($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="id" value="<?php echo $vid; ?>">
                            </form>
                            <input type="text" class="form-control form-control-sm" form="sv-form-<?php echo $vid; ?>" name="name" maxlength="<?php echo SavedReports::MAX_NAME; ?>" value="<?php echo nullable_htmlentities($v['saved_report_name']); ?>" required>
                        </td>
                        <td class="small text-muted"><?php echo $v['params'] ? nullable_htmlentities(http_build_query($v['params'])) : 'defaults'; ?></td>
                        <td>
                            <div class="form-check mb-0"><input class="form-check-input" type="checkbox" form="sv-form-<?php echo $vid; ?>" name="shared" value="1" <?php echo intval($v['saved_report_shared']) === 1 ? 'checked' : ''; ?>></div>
                        </td>
                        <td class="text-end text-nowrap">
                            <button type="submit" form="sv-form-<?php echo $vid; ?>" class="btn btn-sm btn-outline-secondary"><i class="fas fa-fw fa-save"></i> Save</button>
                            <form method="post" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo nullable_htmlentities($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo $vid; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-fw fa-trash"></i> Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($others) { ?>
<div class="card card-dark">
    <div class="card-header py-2"><h3 class="card-title mt-2"><i class="fas fa-fw fa-share-alt me-2"></i>Shared with me</h3></div>
    <div class="card-body p-0">
        <div class="table-responsive-sm">
            <table class="table table-striped mb-0">
                <thead><tr><th>Report</th><th>Name</th><th>Shared by</th><th>Filters</th></tr></thead>
                <tbody>
                <?php foreach ($others as $v) { ?>
                    <tr>
                        <td><?php echo nullable_htmlentities(ReportCatalog::label($v['saved_report_key'])); ?></td>
                        <td><a href="/agent/reports/saved_views.php?go=<?php echo intval($v['saved_report_id']); ?>"><?php echo nullable_htmlentities($v['saved_report_name']); ?></a></td>
                        <td><?php echo nullable_htmlentities($v['user_name'] ?? ''); ?></td>
                        <td class="small text-muted"><?php echo $v['params'] ? nullable_htmlentities(http_build_query($v['params'])) : 'defaults'; ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php } ?>

<?php require_once "../../includes/footer.php"; ?>
