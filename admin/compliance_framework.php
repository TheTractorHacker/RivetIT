<?php
require_once "includes/inc_all_admin.php";
require_once dirname(__DIR__) . '/includes/rivet_time.php';

use ITFlow\Compliance\ComplianceService;
use RivetCore\Compliance\Backup\BackupStore;
use RivetCore\Compliance\Profile\DataClass;
use RivetCore\Compliance\Profile\FrameworkProfile;
use RivetCore\Compliance\Profile\RequirementType;
use RivetCore\Compliance\Profile\RetentionFloors;

// Administration > Compliance > Framework (RivetCore 1.0.0-rc.44 customizable frameworks). Administrators only (inc_all_admin). Every change is a POST to
// admin/post/compliance_framework.php with a CSRF token; the one GET with an effect is the read-only export.
$csrf = $_SESSION['csrf_token'];
$ready = ComplianceService::profilesReady($mysqli);
$h = static fn ($s): string => nullable_htmlentities((string) $s);

if (!$ready) { ?>
    <div class="alert alert-warning">Run the database update first (Administration &gt; Update). RivetCore's framework tables are not installed yet.</div>
    <?php require_once "../includes/footer.php"; exit;
}

$store = ComplianceService::profiles($mysqli);
$profiles = $store->list(true);
$selected = $store->selectedProfile();
$edit_key = (string) ($_GET['edit'] ?? '');
$edit = $edit_key !== '' ? $store->get($edit_key) : null;
if ($edit !== null && $edit->builtin) { $edit = null; }
$history = $edit ? $store->history($edit->key) : [];
$floors = RetentionFloors::fromStore($store);
$tests = ComplianceService::restoreTests($mysqli);
$test_list = $tests->list(10);

// The checks the selected framework asked for, from the same assessment the Compliance status page shows.
$check_ids = ['backup_coverage', 'backup_recency', 'backup_encrypted', 'backup_offsite', 'backup_retention', 'restore_test_recency', 'data_store_retention', 'audit_retention', 'integrity_chain', 'security_log_presets'];
$results = [];
if ($selected !== null) {
    foreach (ComplianceService::assess($mysqli)->automatic as $r) {
        if (in_array($r['id'], $check_ids, true)) { $results[] = $r; }
    }
}
$badge = ['pass' => 'success', 'warn' => 'warning', 'fail' => 'danger', 'na' => 'secondary', 'error' => 'dark'];
$unit_label = static fn (string $type): string => RequirementType::unit($type) === RequirementType::UNIT_FLAG ? 'yes' : RequirementType::unit($type);

/** One editor row. */
$row = static function (?\RivetCore\Compliance\Profile\Requirement $r) use ($h): string {
    $o = '<tr><td><select class="form-select form-select-sm" name="req_type[]"><option value="">-</option>';
    foreach (RequirementType::ids() as $t) {
        $o .= '<option value="' . $h($t) . '"' . ($r && $r->type === $t ? ' selected' : '') . '>' . $h(RequirementType::label($t)) . '</option>';
    }
    $o .= '</select></td><td><select class="form-select form-select-sm" name="req_class[]"><option value="">-</option>';
    foreach (DataClass::ids() as $c) {
        $o .= '<option value="' . $h($c) . '"' . ($r && $r->dataClass === $c ? ' selected' : '') . '>' . $h(DataClass::label($c)) . '</option>';
    }
    $o .= '</select></td><td><input type="number" class="form-control form-control-sm" name="req_value[]" min="1" value="' . ($r ? (int) $r->value : '') . '"></td>'
        . '<td><input type="text" class="form-control form-control-sm" name="req_note[]" maxlength="300" value="' . $h($r->note ?? '') . '"></td></tr>';

    return $o;
};
?>
<div class="card card-dark mb-3">
    <div class="card-header py-3"><h3 class="card-title"><i class="fas fa-fw fa-balance-scale mr-2"></i>Compliance framework</h3>
        <div class="card-tools"><a class="btn btn-sm btn-outline-secondary" href="compliance_status.php">Compliance status</a></div></div>
    <div class="card-body">
        <p class="text-muted">A framework is a list of requirements: retention minimums per kind of data, backup age, off-site copy, encryption and restore tests. Nothing changes until you select one.
        Once selected, its requirements are checked on the Compliance status page and its retention minimums are enforced in the RMM settings and the audit-trail cleanup.</p>

        <form action="post.php" method="post" class="row g-2 align-items-end mb-2">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <div class="col-md-6"><label class="form-label" for="profile_key">Selected framework</label>
                <select class="form-select" id="profile_key" name="profile_key">
                    <option value="">None (nothing enforced from a framework)</option>
                    <?php foreach ($profiles as $p) { if ($p->status === FrameworkProfile::STATUS_ARCHIVED) { continue; } ?>
                        <option value="<?= $h($p->key) ?>"<?= $selected && $selected->key === $p->key ? ' selected' : '' ?>><?= $h($p->displayName()) ?><?= $p->builtin ? ' (built in)' : ' (custom)' ?></option>
                    <?php } ?>
                </select></div>
            <div class="col-md-3"><button class="btn btn-primary" name="select_compliance_profile" value="1" type="submit">Select</button></div>
        </form>
        <?php if ($selected) { ?><p class="small text-muted mb-0">Selected: <strong><?= $h($selected->displayName()) ?></strong>, version <?= (int) $selected->version ?>.</p><?php } ?>
    </div>
</div>

<?php if ($selected) { ?>
<div class="card mb-3">
    <div class="card-header py-3"><h4 class="card-title mb-0">Requirements and results</h4></div>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead><tr><th>Requirement</th><th>Needed</th></tr></thead>
        <tbody><?php foreach ($selected->requirements as $r) { ?>
            <tr><td><?= $h(RequirementType::label($r->type)) ?><?= $r->dataClass !== null ? ' (' . $h(DataClass::label($r->dataClass)) . ')' : '' ?><?= $r->note ? '<div class="small text-muted">' . $h($r->note) . '</div>' : '' ?></td>
                <td><?= RequirementType::unit($r->type) === RequirementType::UNIT_FLAG ? 'yes' : (int) $r->value . ' ' . $h($unit_label($r->type)) ?></td></tr>
        <?php } ?></tbody></table></div>
    <?php if ($results) { ?>
    <div class="table-responsive border-top"><table class="table table-sm align-middle mb-0">
        <thead><tr><th>Check</th><th>Result</th><th>Evidence</th></tr></thead><tbody>
        <?php foreach ($results as $r) { ?>
            <tr><td><strong><?= $h($r['title']) ?></strong></td>
                <td><span class="badge bg-<?= $badge[$r['status']] ?? 'secondary' ?>"><?= $h($r['status_label']) ?></span></td>
                <td><?= $h($r['summary']) ?><?php if ($r['detail']) { ?><div class="small text-muted"><?= nl2br($h($r['detail']), false) ?></div><?php } ?></td></tr>
        <?php } ?></tbody></table></div>
    <?php } ?>
</div>

<div class="card mb-3">
    <div class="card-header py-3"><h4 class="card-title mb-0">Retention minimums enforced now</h4></div>
    <div class="card-body"><?php $fl = $floors->all(); if (!$fl) { ?><p class="mb-0 text-muted">This framework sets no retention minimum.</p><?php } else { ?>
        <p class="small text-muted">Required by <?= $h($floors->requiredBy()) ?>. A setting below its minimum is refused (RMM settings) or raised to it (the audit-trail cleanup); a minimum above a setting's own maximum applies automatically.</p>
        <ul class="mb-0"><?php foreach ($fl as $class => $days) { ?><li><?= $h(DataClass::label($class)) ?>: at least <?= (int) $days ?> days</li><?php } ?></ul>
    <?php } ?></div>
</div>
<?php } ?>

<div class="card mb-3">
    <div class="card-header py-3"><h4 class="card-title mb-0">All frameworks</h4></div>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead><tr><th>Name</th><th>Kind</th><th>Version</th><th>Status</th><th class="text-end">Actions</th></tr></thead><tbody>
        <?php foreach ($profiles as $p) { $archived = $p->status === FrameworkProfile::STATUS_ARCHIVED; ?>
            <tr><td><?= $h($p->displayName()) ?><?php if ($p->description !== '') { ?><div class="small text-muted"><?= $h($p->description) ?></div><?php } ?></td>
                <td class="text-nowrap"><?= $p->builtin ? 'Built in' : 'Custom' ?></td><td><?= (int) $p->version ?></td><td><?= $archived ? 'Archived' : 'Active' ?></td>
                <td class="text-end text-nowrap">
                    <a class="btn btn-sm btn-outline-secondary" href="post.php?export_compliance_profile=<?= $h($p->key) ?>&amp;csrf_token=<?= $csrf ?>">Export</a>
                    <?php if (!$p->builtin) { ?><a class="btn btn-sm btn-outline-primary" href="?edit=<?= $h($p->key) ?>">Edit</a><?php } ?>
                    <?php if (!$p->builtin) { ?>
                    <form action="post.php" method="post" class="d-inline"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="profile_key" value="<?= $h($p->key) ?>">
                        <button class="btn btn-sm btn-outline-secondary" name="<?= $archived ? 'unarchive' : 'archive' ?>_compliance_profile" value="1" type="submit"><?= $archived ? 'Unarchive' : 'Archive' ?></button></form>
                    <form action="post.php" method="post" class="d-inline" data-confirm-submit="Delete this framework? This only works for a framework that was never selected."><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="profile_key" value="<?= $h($p->key) ?>">
                        <button class="btn btn-sm btn-outline-danger" name="delete_compliance_profile" value="1" type="submit">Delete</button></form>
                    <?php } ?>
                </td></tr>
        <?php } ?></tbody></table></div>
</div>

<div class="row align-items-stretch">
<div class="col-lg-6">
<div class="card mb-3 h-100"><div class="card-header py-3"><h4 class="card-title mb-0">Copy a framework</h4></div><div class="card-body">
    <form action="post.php" method="post"><input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <div class="mb-2"><label class="form-label">Copy from</label><select class="form-select" name="source_key"><?php foreach ($profiles as $p) { ?><option value="<?= $h($p->key) ?>"><?= $h($p->displayName()) ?></option><?php } ?></select></div>
        <div class="mb-2"><label class="form-label">New key</label><input class="form-control" name="profile_key" required pattern="[a-z][a-z0-9_]{2,47}" placeholder="contract_x"><div class="form-text">Lower case letters, digits and underscores, 3 to 48 characters.</div></div>
        <div class="mb-2"><label class="form-label">New name</label><input class="form-control" name="label" required maxlength="120"></div>
        <button class="btn btn-primary" name="copy_compliance_profile" value="1" type="submit">Copy</button>
    </form></div></div>
</div>
<div class="col-lg-6">
<div class="card mb-3 h-100"><div class="card-header py-3"><h4 class="card-title mb-0">Import a framework</h4></div><div class="card-body">
    <form action="post.php" method="post" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <div class="mb-2"><label class="form-label">JSON file (64 KiB at most)</label><input type="file" class="form-control" name="profile_file" accept="application/json,.json" required></div>
        <div class="mb-2"><label class="form-label">Use this key instead (optional, if the file's key is taken)</label><input class="form-control" name="as_key" pattern="[a-z][a-z0-9_]{2,47}"></div>
        <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="as_new_version" name="as_new_version" value="1"><label class="form-check-label" for="as_new_version">If the key exists, add it as a new version</label></div>
        <button class="btn btn-primary" name="import_compliance_profile" value="1" type="submit">Import</button>
    </form></div></div>
</div>
</div>

<div class="card mb-3">
    <div class="card-header py-3"><h4 class="card-title mb-0"><?= $edit ? 'Edit ' . $h($edit->displayName()) : 'Create a framework' ?></h4></div>
    <div class="card-body">
        <form action="post.php" method="post"><input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <?php if ($edit) { ?><input type="hidden" name="profile_key" value="<?= $h($edit->key) ?>"><input type="hidden" name="expected_version" value="<?= (int) $edit->version ?>"><?php } else { ?>
            <div class="mb-2"><label class="form-label">Key</label><input class="form-control" name="profile_key" required pattern="[a-z][a-z0-9_]{2,47}" placeholder="contract_x"></div><?php } ?>
            <div class="mb-2"><label class="form-label">Name</label><input class="form-control" name="label" required maxlength="120" value="<?= $h($edit->label ?? '') ?>"></div>
            <div class="mb-2"><label class="form-label">Description</label><textarea class="form-control" name="description" rows="2" maxlength="1000"><?= $h($edit->description ?? '') ?></textarea></div>
            <div class="table-responsive"><table class="table table-sm"><thead><tr><th>Requirement</th><th>Data class</th><th>Days / hours</th><th>Note</th></tr></thead><tbody>
                <?php if ($edit) { foreach ($edit->requirements as $r) { echo $row($r); } }
                for ($i = 0; $i < ($edit ? 3 : 6); $i++) { echo $row(null); } ?>
            </tbody></table></div>
            <p class="small text-muted">Yes/no requirements ignore the number. Leave a row's requirement empty to skip it.</p>
            <div class="mb-2"><label class="form-label">What changed (optional)</label><input class="form-control" name="change_note" maxlength="300"></div>
            <button class="btn btn-primary" name="<?= $edit ? 'update' : 'create' ?>_compliance_profile" value="1" type="submit"><?= $edit ? 'Save as new version' : 'Create' ?></button>
            <?php if ($edit) { ?><a class="btn btn-link" href="compliance_framework.php">Cancel</a><?php } ?>
        </form>
        <?php if ($edit && $history) { ?>
        <h5 class="mt-4">Version history</h5>
        <div class="table-responsive"><table class="table table-sm"><thead><tr><th>Version</th><th>When</th><th>Who</th><th>Note</th><th></th></tr></thead><tbody>
            <?php foreach ($history as $v) { $v = (array) $v; ?>
                <tr><td><?= (int) ($v['version'] ?? 0) ?></td><td><?= $h($v['created_at'] ?? '') ?></td><td><?= $h($v['created_by_name'] ?? ($v['by'] ?? '')) ?></td><td><?= $h($v['change_note'] ?? ($v['note'] ?? '')) ?></td>
                    <td class="text-end"><?php if ((int) ($v['version'] ?? 0) !== (int) $edit->version) { ?>
                        <form action="post.php" method="post" class="d-inline" data-confirm-submit="Restore this version as a new version?"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="profile_key" value="<?= $h($edit->key) ?>"><input type="hidden" name="version" value="<?= (int) $v['version'] ?>">
                        <button class="btn btn-sm btn-outline-secondary" name="revert_compliance_profile" value="1" type="submit">Restore this version</button></form><?php } ?></td></tr>
            <?php } ?></tbody></table></div>
        <?php } ?>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header py-3"><h4 class="card-title mb-0">Restore tests</h4></div>
    <div class="card-body">
        <p class="small text-muted">Restoring a backup and checking it is the evidence behind "Restore tested within N days". The scheduled restore drill records itself on Administration &gt; Backup; use this form to record a test you ran by hand.</p>
        <form action="post.php" method="post" enctype="multipart/form-data" class="row g-2"><input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <div class="col-md-3"><label class="form-label">Tested at (<?= $h(rivetTimeZoneLabel()) ?>)</label><input class="form-control" name="tested_at" value="<?= $h(rivetFormatTime(gmdate('Y-m-d H:i:s'), 'utc', false)) ?>" required></div>
            <div class="col-md-3"><label class="form-label">Backup taken at (<?= $h(rivetTimeZoneLabel()) ?>, optional)</label><input class="form-control" name="backup_taken_at" placeholder="2026-10-30 03:00"></div>
            <div class="col-md-3"><label class="form-label">Who ran it</label><input class="form-control" name="who" maxlength="200" value="<?= $h($session_name) ?>"></div>
            <div class="col-md-3"><label class="form-label">Result</label><select class="form-select" name="result"><option value="pass">Passed</option><option value="fail">Failed</option></select></div>
            <div class="col-md-6"><label class="form-label d-block">Stores restored</label>
                <?php foreach (BackupStore::LABELS as $id => $label) { ?><div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="stores[]" id="st_<?= $h($id) ?>" value="<?= $h($id) ?>"<?= $id === BackupStore::CONTROL_DB ? ' checked' : '' ?>><label class="form-check-label" for="st_<?= $h($id) ?>"><?= $h($label) ?></label></div><?php } ?></div>
            <div class="col-md-3"><label class="form-label">Duration (seconds)</label><input type="number" class="form-control" name="duration_s" min="0"></div>
            <div class="col-md-3"><label class="form-label">rmm-store-verify --json (optional)</label><input type="file" class="form-control" name="verify_json" accept="application/json,.json"></div>
            <div class="col-12"><label class="form-label">Note (optional)</label><input class="form-control" name="note" maxlength="2000"></div>
            <div class="col-12"><button class="btn btn-primary" name="record_restore_test" value="1" type="submit">Record restore test</button></div>
        </form>
        <?php if ($test_list) { ?>
        <div class="table-responsive mt-3"><table class="table table-sm mb-0"><thead><tr><th>Tested</th><th>Result</th><th>Stores</th><th>By</th><th>Row counts</th></tr></thead><tbody>
            <?php foreach ($test_list as $t) { ?><tr><td><?= $h(rivetFormatTime($t->testedAt->format('Y-m-d H:i:s'), 'utc')) ?></td><td><?= $t->passed ? 'Passed' : 'Failed' ?></td><td><?= $h(implode(', ', $t->stores)) ?></td><td><?= $h($t->recordedByName) ?></td>
                <td><?= !$t->countsCompared() ? 'not compared' : ($t->countsMatch() ? 'match' : 'DIFFER') ?></td></tr><?php } ?>
        </tbody></table></div>
        <?php } ?>
    </div>
</div>
<?php require_once "../includes/footer.php"; ?>
