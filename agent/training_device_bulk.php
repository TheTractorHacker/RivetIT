<?php

/*
 * Training › Devices & PINs › Get setup codes (2.6.101; module_training_kiosk 3). The remote/mass
 * sibling of training_device_setup.php's "Set up this device" (owner ask 2026-09-28: "like how we
 * add non asset devices via pin do the same for iPad" + "Need to think on mass scale"). Where
 * training_device_setup.php is done ON the device itself (agent signs in there, enrolls, is signed
 * out), this page is done from the office/desk for a whole fleet at once: pick existing assets
 * (multi-select, reusing DeviceEnrollment::assetOptions()) and/or type or paste a list of unlisted
 * device names (one per line, or a "prefix" + count -> "iPad 1".."iPad 12"), one shared department
 * and duration for the batch, then "Get setup codes" (kiosk_enroll_codes) redirects to the print
 * page (training_device_slips.php) with its Scratch token - exactly paralleling how the People &
 * PINs tab's "Issue setup slips" works (pin_slips_issue -> training_pin_slips.php).
 * Each printed code is typed into the kiosk's own "not set up" -> "Enter a setup code" screen
 * (kiosk/index.php kx-code-open -> enroll_code) - no admin session ever touches the device itself.
 */

$page_extra_css = ['/css/itflow_training.css'];
require_once "includes/inc_all.php";
if (\ITFlow\Training\Core\Access::pageGuardKiosk(3)) { require_once "../includes/footer.php"; exit; }
define('TRAINING_PAGE', 1);

$tr_departments = [];
$tr_res = mysqli_query($mysqli, 'SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL ORDER BY client_name');
while ($tr_res && ($tr_row = mysqli_fetch_assoc($tr_res))) {
    $tr_departments[] = ['id' => (int) $tr_row['client_id'], 'name' => (string) $tr_row['client_name']];
}
$tr_can_assets = \ITFlow\Training\Core\Access::canAssets();
$tr_data = [
    'can_assets' => $tr_can_assets,
    'departments' => $tr_departments,
    'asset_types' => \ITFlow\Training\Kiosk\Core\KioskSettings::ASSET_TYPES,
    'timezone' => date_default_timezone_get(),
    'max_days' => \ITFlow\Training\Kiosk\Device\DeviceLifecycle::MAX_DAYS,
    'max_devices' => \ITFlow\Training\Kiosk\Device\DeviceEnrollment::MAX_DEVICES,
];

render_page_header(
    'Get setup codes',
    'Issue a printable setup code for several devices at once - no need to sign in on each one.',
    '',
    [['label' => 'Training', 'url' => '/agent/training_courses.php'], ['label' => 'Devices & PINs', 'url' => '/agent/training_devices.php'], ['label' => 'Get setup codes']]
);
?>
<div class="row g-3" id="trb-bulk">
    <div class="col-lg-8">
        <?php if ($tr_can_assets) { ?>
        <div class="card mb-3">
            <div class="card-header"><h2 class="card-title">Pick devices already in Assets</h2></div>
            <div class="card-body">
                <label class="form-label" for="trb-q">Find assets</label>
                <div class="input-icon mb-2">
                    <span class="input-icon-addon"><i class="fas fa-search" aria-hidden="true"></i></span>
                    <input type="search" class="form-control" id="trb-q" placeholder="Name, serial or asset tag" autocomplete="off" maxlength="80">
                </div>
                <div class="form-hint mb-2">Tablets, phones, laptops and desktops that are not archived. Tick each one to add it below.</div>
                <div class="list-group list-group-flush border rounded" id="trb-assets" style="max-height: 260px; overflow-y: auto;" role="listbox" aria-label="Assets" aria-multiselectable="true"></div>
            </div>
        </div>
        <?php } ?>
        <div class="card mb-3">
            <div class="card-header"><h2 class="card-title">Add devices that aren't in Assets</h2></div>
            <div class="card-body">
                <p class="text-secondary small mb-2">One name per line - for a fleet of iPads you don't track individually, like "iPad 1" through "iPad 12".</p>
                <div class="row g-2 align-items-end mb-2">
                    <div class="col-auto">
                        <label class="form-label mb-0" for="trb-prefix">Quick-fill</label>
                        <input type="text" class="form-control form-control-sm" id="trb-prefix" placeholder="iPad" maxlength="80" style="width: 12rem;">
                    </div>
                    <div class="col-auto">
                        <label class="form-label mb-0" for="trb-count">Count</label>
                        <input type="number" class="form-control form-control-sm" id="trb-count" min="1" max="40" value="12" style="width: 6rem;">
                    </div>
                    <div class="col-auto">
                        <button type="button" class="btn btn-outline-primary btn-sm" id="trb-prefix-add"><i class="fas fa-list-ol me-1" aria-hidden="true"></i>Add "Name 1..N"</button>
                    </div>
                </div>
                <label class="form-label visually-hidden" for="trb-names">Device names</label>
                <textarea class="form-control" id="trb-names" rows="6" placeholder="Trainer's laptop&#10;Fab Shop iPad&#10;Borrowed iPad"></textarea>
                <div class="invalid-feedback" data-field="items"></div>
                <div class="form-hint">Two unlisted devices can share the same name - each gets its own code.</div>
            </div>
        </div>
        <div class="card mb-3">
            <div class="card-header"><h2 class="card-title">Department &amp; how long</h2></div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label" for="trb-dept">Default department</label>
                    <select class="form-select" id="trb-dept" style="max-width: 24rem;"><option value="0">None</option></select>
                    <div class="form-hint">Shared by every device in this batch. Used as the default for group sessions.</div>
                </div>
                <fieldset class="mb-2">
                    <legend class="form-label">How long?</legend>
                    <div class="d-flex flex-column gap-2">
                        <label class="form-check mb-0">
                            <input class="form-check-input" type="radio" name="trb-life" id="trb-life-keep" value="keep" checked>
                            <span class="form-check-label">Keep until I remove it</span>
                        </label>
                        <label class="form-check mb-0">
                            <input class="form-check-input" type="radio" name="trb-life" id="trb-life-temp" value="temp">
                            <span class="form-check-label">Temporary <span class="text-secondary">(stops working by itself)</span></span>
                        </label>
                    </div>
                    <div class="mt-2 ms-4" id="trb-temp" hidden>
                        <label class="form-label visually-hidden" for="trb-expires">Stops working</label>
                        <select class="form-select" id="trb-expires" style="max-width: 24rem;">
                            <option value="today">Until the end of today</option>
                            <option value="4h">For 4 hours</option>
                            <option value="8h">For 8 hours</option>
                            <option value="24h">For 24 hours</option>
                            <option value="until">Until a date and time…</option>
                        </select>
                        <div class="mt-2" id="trb-until-wrap" hidden>
                            <label class="form-label" for="trb-until">Date and time</label>
                            <input type="datetime-local" class="form-control" id="trb-until" step="60" style="max-width: 24rem;">
                            <div class="invalid-feedback" data-field="expires_until"></div>
                        </div>
                        <div class="form-hint mt-2" id="trb-expiry-preview" aria-live="polite"></div>
                    </div>
                </fieldset>
                <div class="form-check mb-0 d-none" id="trb-replace-wrap">
                    <input class="form-check-input" type="checkbox" id="trb-replace">
                    <label class="form-check-label" for="trb-replace">Replace already-enrolled assets</label>
                </div>
            </div>
        </div>
        <div class="alert alert-danger d-none" id="trb-conflict" role="alert"></div>
        <button type="button" class="btn btn-primary btn-lg" id="trb-go"><i class="fas fa-print me-2" aria-hidden="true"></i>Get setup codes <span class="badge bg-white text-primary ms-1" id="trb-count-badge">0</span></button>
    </div>
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><h2 class="card-title">How this works</h2></div>
            <div class="card-body">
                <ul class="mb-0 ps-3">
                    <li class="mb-2">Pick assets and/or type unlisted device names, up to <?= (int) $tr_data['max_devices'] ?> at a time.</li>
                    <li class="mb-2">Every device in the batch gets its own 10-character setup code, valid for a few days (Training settings &rsaquo; Setup slips &amp; codes).</li>
                    <li class="mb-2">The next page prints one slip per device. Hand each slip to whoever sets up that device.</li>
                    <li class="mb-2">On the device: open <code>/kiosk/</code>, tap <strong>Enter a setup code</strong>, and type the code. No sign-in needed on the device itself.</li>
                    <li>Setting up ONE device yourself, on that device? Use <a href="/agent/training_device_setup.php">Set up this device</a> instead.</li>
                </ul>
            </div>
        </div>
    </div>
</div>
<script type="application/json" id="tr-page-data"><?= json_encode($tr_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="/js/training_common.js?v=<?= filemtime(__DIR__ . '/../js/training_common.js') ?>" defer></script>
<script src="/agent/js/training_device_time.js?v=<?= filemtime(__DIR__ . '/js/training_device_time.js') ?>" defer></script>
<script src="/agent/js/training_device_bulk.js?v=<?= filemtime(__DIR__ . '/js/training_device_bulk.js') ?>" defer></script>
<?php require_once "../includes/footer.php";
