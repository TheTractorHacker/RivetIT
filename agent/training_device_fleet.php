<?php

/*
 * Training › Devices & PINs › Fleet links (DB 2.6.151, issue #43; module_training_kiosk 3). One enrollment link per
 * department for an MDM to push to many tablets (/kiosk/?e=<token>&sn=<serial>), alongside the per-device start URL and
 * the setup codes. This page creates / lists / revokes / rotates the links (the token is shown once, here), offers the
 * MDM URL template, the Apple Web Clip .mobileconfig and the per-device URL CSV, and approves or rejects the tablets
 * that are waiting ("pending"). All data comes from fleet_list / fleet_* in KioskAdminActions.
 */

$page_extra_css = ['/css/itflow_training.css'];
require_once "includes/inc_all.php";
if (\ITFlow\Training\Core\Access::pageGuardKiosk(3)) { require_once "../includes/footer.php"; exit; }
define('TRAINING_PAGE', 1);

$tr_ctx = \ITFlow\Training\Core\Access::ctx($mysqli);
$tr_ks = \ITFlow\Training\Kiosk\Core\KioskSettings::fromDb($mysqli);
$tr_scope = \ITFlow\Training\Kiosk\Pin\Seam::scopeClientIds($tr_ctx);
$tr_departments = [];
$tr_res = mysqli_query($mysqli, 'SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL ORDER BY client_name');
while ($tr_res && ($tr_row = mysqli_fetch_assoc($tr_res))) {
    if ($tr_scope === null || in_array((int) $tr_row['client_id'], $tr_scope, true)) {
        $tr_departments[] = ['id' => (int) $tr_row['client_id'], 'name' => (string) $tr_row['client_name']];
    }
}
$tr_data = [
    'departments' => $tr_departments,
    'can_assets' => \ITFlow\Training\Core\Access::canAssets(),
    'max_days' => \ITFlow\Training\Kiosk\Device\FleetLinks::MAX_DAYS,
    'max_uses' => \ITFlow\Training\Kiosk\Device\FleetLinks::MAX_USES_CAP,
    'timezone' => date_default_timezone_get(),
    'base_url' => 'https://' . preg_replace('#^https?://#', '', rtrim((string) $config_base_url, '/')),
];

render_page_header(
    'Fleet links',
    'One link for a whole department of tablets, pushed by your device manager (MDM).',
    '',
    [['label' => 'Training', 'url' => '/agent/training_courses.php'], ['label' => 'Devices & PINs', 'url' => '/agent/training_devices.php'], ['label' => 'Fleet links']]
);
?>
<?php if (!$tr_ks->schemaReady) { ?>
<div class="alert alert-warning" role="status">The training kiosk tables are not installed yet. Run Admin › Update › Update Database.</div>
<?php } else { ?>
<div id="trf-root">
    <div class="alert alert-info" role="note">
        Other ways to set up devices still work: <a href="/agent/training_device_setup.php">Set up this device</a> (sign in on the tablet),
        a per-device start URL, or <a href="/agent/training_device_bulk.php">printed setup codes</a>. A fleet link is for rolling out many tablets from an MDM
        with one shared URL. See <strong>docs/training-kiosk-setup.md</strong> for the Apple and Android steps.
    </div>

    <div class="card mb-3" id="trf-pending-card" hidden>
        <div class="card-header"><h2 class="card-title"><i class="fas fa-hourglass-half me-2" aria-hidden="true"></i>Waiting for approval <span class="badge bg-warning ms-2" id="trf-pending-count">0</span></h2></div>
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead><tr><th>Device</th><th>Serial</th><th>Why it waits</th><th>Department</th><th>Asked</th><th class="w-1"><span class="visually-hidden">Actions</span></th></tr></thead>
                <tbody id="trf-pending-body"></tbody>
            </table>
        </div>
        <div class="card-footer text-secondary small">A waiting tablet sees "Waiting for approval" and gets no training data until you approve it. Rejecting stops its token.</div>
    </div>

    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card mb-3">
                <div class="card-header"><h2 class="card-title">New fleet link</h2></div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label" for="trf-label">Name</label>
                        <input type="text" class="form-control" id="trf-label" maxlength="100" placeholder="Fab Shop iPads - October rollout">
                        <div class="invalid-feedback" data-field="label"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="trf-dept">Department</label>
                        <select class="form-select" id="trf-dept"><option value="0">Pick a department</option></select>
                        <div class="invalid-feedback" data-field="client_id"></div>
                        <div class="form-hint">Tablets that use this link are enrolled for this department only, and serials are matched to this department's Assets.</div>
                    </div>
                    <fieldset class="mb-3">
                        <legend class="form-label">New devices</legend>
                        <label class="form-check mb-1"><input class="form-check-input" type="radio" name="trf-approval" value="require" checked>
                            <span class="form-check-label"><strong>Require approval</strong> (default) - every tablet waits until an admin approves it</span></label>
                        <label class="form-check mb-0"><input class="form-check-input" type="radio" name="trf-approval" value="auto_match">
                            <span class="form-check-label"><strong>Auto-enroll</strong> when the serial already matches an active Asset of this department; everything else waits</span></label>
                    </fieldset>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label" for="trf-days">Link works for</label>
                            <select class="form-select" id="trf-days">
                                <option value="7">7 days</option><option value="30" selected>30 days</option><option value="60">60 days</option><option value="90">90 days</option>
                            </select>
                            <div class="invalid-feedback" data-field="expires"></div>
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="trf-max">Most devices</label>
                            <input type="number" class="form-control" id="trf-max" min="1" max="<?= (int) $tr_data['max_uses'] ?>" value="25">
                            <div class="invalid-feedback" data-field="max_uses"></div>
                        </div>
                    </div>
                    <button type="button" class="btn btn-primary" id="trf-create"><i class="fas fa-link me-2" aria-hidden="true"></i>Create fleet link</button>
                </div>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="card mb-3 border-success" id="trf-reveal" hidden>
                <div class="card-header"><h2 class="card-title"><i class="fas fa-key me-2" aria-hidden="true"></i>Your new link: <span id="trf-reveal-name"></span></h2></div>
                <div class="card-body">
                    <div class="alert alert-warning" role="alert">Copy it now. For security the link is shown only once; if you lose it, use <strong>Rotate</strong> to get a new one.</div>
                    <label class="form-label" for="trf-url-plain">Fleet link (no serial: tablets enroll as unlisted, waiting for approval)</label>
                    <div class="input-group mb-3">
                        <input type="text" class="form-control font-monospace" id="trf-url-plain" readonly>
                        <button type="button" class="btn btn-outline-secondary" data-copy="trf-url-plain"><i class="fas fa-copy me-1" aria-hidden="true"></i>Copy</button>
                    </div>
                    <div class="row g-2 mb-1">
                        <div class="col-md-5">
                            <label class="form-label" for="trf-mdm">Your MDM</label>
                            <select class="form-select" id="trf-mdm">
                                <option value="%SerialNumber%">Apple Configurator / generic: %SerialNumber%</option>
                                <option value="$SERIALNUMBER">Jamf Pro: $SERIALNUMBER</option>
                                <option value="{{serialnumber}}">Intune: {{serialnumber}}</option>
                                <option value="%serialnumber%">ManageEngine: %serialnumber%</option>
                                <option value="">Other (type the macro)</option>
                            </select>
                        </div>
                        <div class="col-md-7">
                            <label class="form-label" for="trf-macro">Serial-number macro</label>
                            <input type="text" class="form-control font-monospace" id="trf-macro" maxlength="40" value="%SerialNumber%" spellcheck="false" autocomplete="off">
                            <div class="invalid-feedback" data-field="placeholder"></div>
                        </div>
                    </div>
                    <div class="form-hint mb-2">Macro names differ by MDM and version - confirm yours in its documentation before a big push.</div>
                    <label class="form-label" for="trf-url-mdm">MDM URL template</label>
                    <div class="input-group mb-3">
                        <input type="text" class="form-control font-monospace" id="trf-url-mdm" readonly>
                        <button type="button" class="btn btn-outline-secondary" data-copy="trf-url-mdm"><i class="fas fa-copy me-1" aria-hidden="true"></i>Copy</button>
                    </div>
                    <div class="row g-2 align-items-end mb-2">
                        <div class="col-md-6">
                            <label class="form-label" for="trf-clip-name">Home Screen icon name (Apple)</label>
                            <input type="text" class="form-control" id="trf-clip-name" maxlength="40" value="Training">
                            <div class="invalid-feedback" data-field="name"></div>
                        </div>
                        <div class="col-md-6 d-flex gap-2 flex-wrap">
                            <button type="button" class="btn btn-primary" id="trf-dl-profile"><i class="fab fa-apple me-2" aria-hidden="true"></i>Download .mobileconfig</button>
                            <?php if ($tr_data['can_assets']) { ?><button type="button" class="btn btn-outline-primary" id="trf-dl-csv"><i class="fas fa-file-csv me-2" aria-hidden="true"></i>Per-device URLs (CSV)</button><?php } ?>
                        </div>
                    </div>
                    <div class="form-hint">The profile is a managed Web Clip: Full Screen, not removable, with the kiosk icon inside it. The CSV has one URL per tablet in Assets for this department, with that serial already filled in - for an MDM that cannot expand a macro.</div>
                </div>
            </div>
            <div class="card">
                <div class="card-header"><h2 class="card-title">Fleet links</h2></div>
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead><tr><th>Name</th><th>Department</th><th>Devices</th><th>Ends</th><th>Status</th><th class="w-1"><span class="visually-hidden">Actions</span></th></tr></thead>
                        <tbody id="trf-links-body"></tbody>
                    </table>
                </div>
                <div class="card-footer text-secondary small">Revoking a link stops new tablets only; tablets that already enrolled keep working (revoke them on the Devices tab).</div>
            </div>
        </div>
    </div>
</div>
<?php } ?>
<script type="application/json" id="tr-page-data"><?= json_encode($tr_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="/js/training_common.js?v=<?= filemtime(__DIR__ . '/../js/training_common.js') ?>" defer></script>
<script src="/agent/js/training_device_fleet.js?v=<?= filemtime(__DIR__ . '/js/training_device_fleet.js') ?>" defer></script>
<?php require_once "../includes/footer.php";
