<?php
require_once "includes/inc_all_admin.php";
enforceUserPermission('module_admin');

require_once "../src/Portal/Announcements.php";
$an = new \ITFlow\Portal\Announcements($mysqli);

$departments = [];
$dept_res = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL ORDER BY client_name ASC");
while ($dept_res && ($d = mysqli_fetch_assoc($dept_res))) {
    $departments[] = $d;
}

$edit_id = intval($_GET['edit'] ?? 0);
$editing = $edit_id > 0 ? $an->find($edit_id) : null;
$list = $an->adminList();

// datetime-local wants YYYY-MM-DDTHH:MM
$dt = static fn($v) => $v ? str_replace(' ', 'T', substr((string) $v, 0, 16)) : '';
$state_badge = ['live' => 'success', 'scheduled' => 'info', 'expired' => 'secondary'];
$sev_badge = ['info' => 'secondary', 'important' => 'warning', 'urgent' => 'danger'];
?>

<div class="card mb-3">
    <div class="card-header py-3 d-flex align-items-center justify-content-between">
        <h3 class="card-title"><i class="fas fa-fw fa-bullhorn me-2"></i>Portal announcements</h3>
        <a href="settings_portal.php" class="btn btn-sm btn-outline-secondary">Employee portal settings</a>
    </div>
    <div class="card-body">
        <p class="text-muted mb-3">Short notices shown at the top of the employee portal home page, for every department or for one. Plain text only. They appear from the publish time until the expiry (or until you remove them) and also show up in each employee's notification feed.</p>

        <form action="post.php" method="post" autocomplete="off" id="announcement_form">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <?php if ($editing) { ?><input type="hidden" name="announcement_id" value="<?= intval($editing['announcement_id']) ?>"><?php } ?>
            <h4 class="mb-3 h5"><?= $editing ? 'Edit announcement' : 'New announcement' ?></h4>
            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label" for="ann_title">Title</label>
                    <input type="text" class="form-control" name="title" id="ann_title" maxlength="<?= \ITFlow\Portal\Announcements::TITLE_MAX ?>" required value="<?= nullable_htmlentities($editing['announcement_title'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="ann_sev">Importance</label>
                    <select class="form-control" name="severity" id="ann_sev">
                        <?php foreach (\ITFlow\Portal\Announcements::SEVERITIES as $k => $label) { ?>
                            <option value="<?= nullable_htmlentities($k) ?>" <?= ($editing['announcement_severity'] ?? 'info') === $k ? 'selected' : '' ?>><?= nullable_htmlentities($label) ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label" for="ann_body">Text</label>
                    <textarea class="form-control" name="body" id="ann_body" rows="4" maxlength="<?= \ITFlow\Portal\Announcements::BODY_MAX ?>" required><?= nullable_htmlentities($editing['announcement_body'] ?? '') ?></textarea>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="ann_dept">Who sees it</label>
                    <select class="form-control" name="client_id" id="ann_dept">
                        <option value="0">All departments</option>
                        <?php foreach ($departments as $d) { ?>
                            <option value="<?= intval($d['client_id']) ?>" <?= intval($editing['announcement_client_id'] ?? 0) === intval($d['client_id']) ? 'selected' : '' ?>><?= nullable_htmlentities($d['client_name']) ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="ann_pub">Publish at <span class="text-muted">(blank = now)</span></label>
                    <input type="datetime-local" class="form-control" name="publish_at" id="ann_pub" value="<?= nullable_htmlentities($dt($editing['announcement_publish_at'] ?? '')) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="ann_exp">Expires at <span class="text-muted">(blank = never)</span></label>
                    <input type="datetime-local" class="form-control" name="expires_at" id="ann_exp" value="<?= nullable_htmlentities($dt($editing['announcement_expires_at'] ?? '')) ?>">
                </div>
                <div class="col-12">
                    <div class="form-check form-switch">
                        <input type="checkbox" class="form-check-input" name="pinned" value="1" id="ann_pin" <?= !empty($editing['announcement_pinned']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="ann_pin">Pin to the top</label>
                    </div>
                </div>
            </div>
            <div class="mt-3">
                <button type="submit" name="<?= $editing ? 'edit_announcement' : 'add_announcement' ?>" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i><?= $editing ? 'Save changes' : 'Publish' ?></button>
                <?php if ($editing) { ?><a href="portal_announcements.php" class="btn btn-link">Cancel</a><?php } ?>
            </div>
        </form>
    </div>
</div>

<style nonce="<?= htmlspecialchars($csp_nonce ?? '', ENT_QUOTES) ?>">
.ann-table th:first-child, .ann-table td:first-child { padding-left: 1.25rem; }
.ann-table th:last-child, .ann-table td:last-child { padding-right: 1.25rem; }
</style>
<div class="card">
    <div class="card-header py-3"><h3 class="card-title"><i class="fas fa-fw fa-list me-2"></i>All announcements</h3></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 ann-table">
                <thead><tr><th>Title</th><th>Who sees it</th><th>Importance</th><th>State</th><th>Publish</th><th>Expires</th><th></th></tr></thead>
                <tbody>
                <?php if (!$list) { ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No announcements yet.</td></tr>
                <?php }
                foreach ($list as $a) { ?>
                    <tr>
                        <td><?php if ($a['announcement_pinned']) { ?><i class="fas fa-thumbtack text-secondary me-1" title="Pinned"></i><?php } ?><?= nullable_htmlentities($a['announcement_title']) ?></td>
                        <td><?= intval($a['announcement_client_id']) === 0 ? 'All departments' : nullable_htmlentities($a['client_name'] ?? 'Unknown department') ?></td>
                        <td><span class="badge text-bg-<?= $sev_badge[$a['announcement_severity']] ?? 'secondary' ?>"><?= nullable_htmlentities(\ITFlow\Portal\Announcements::SEVERITIES[$a['announcement_severity']] ?? $a['announcement_severity']) ?></span></td>
                        <td><span class="badge text-bg-<?= $state_badge[$a['announcement_state']] ?? 'secondary' ?>"><?= nullable_htmlentities(ucfirst($a['announcement_state'])) ?></span></td>
                        <td class="text-nowrap"><?= nullable_htmlentities(substr((string) $a['announcement_publish_at'], 0, 16)) ?></td>
                        <td class="text-nowrap"><?= $a['announcement_expires_at'] ? nullable_htmlentities(substr((string) $a['announcement_expires_at'], 0, 16)) : '-' ?></td>
                        <td class="text-end text-nowrap">
                            <a class="btn btn-sm btn-outline-secondary" href="?edit=<?= intval($a['announcement_id']) ?>">Edit</a>
                            <form action="post.php" method="post" class="d-inline" data-confirm-submit="Remove this announcement? Employees stop seeing it.">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                <input type="hidden" name="announcement_id" value="<?= intval($a['announcement_id']) ?>">
                                <button type="submit" name="archive_announcement" class="btn btn-sm btn-outline-danger">Remove</button>
                            </form>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
require_once "../includes/footer.php";
