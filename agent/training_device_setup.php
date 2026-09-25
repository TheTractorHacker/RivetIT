<?php

/*
 * Training › Devices & PINs › Set up this device (plan A21; P3 spec §5.8, lane K2;
 * module_training_kiosk 3). Opened ON the tablet or PC itself from the kiosk's "not set up"
 * screen: pick the asset - or choose "This device isn't in Assets" and just name it (2.6.94) -
 * name it, choose the default department and how long it stays set up ("Keep until I remove it",
 * or Temporary: end of today, 4 / 8 / 24 hours or a date and time up to 30 days), then "Use this
 * device for training" (kiosk_enroll_here). The success panel shows the permanent start URL once (with Copy,
 * for Windows kiosk mode) and one button, "Open training on this device", which ALWAYS signs the
 * agent out first (GET /agent/post.php?logout) and then opens /kiosk/#d=<token> - the device must
 * never keep an agent session (stray cookies are also expired by the kiosk, P-11).
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
$tr_data = [
    'departments' => $tr_departments,
    'asset_types' => \ITFlow\Training\Kiosk\Core\KioskSettings::ASSET_TYPES,
    // Temporary devices: expiry times are shown (and 'until' is entered) in the app's time zone.
    'timezone' => date_default_timezone_get(),
    'max_days' => \ITFlow\Training\Kiosk\Device\DeviceLifecycle::MAX_DAYS,
];

render_page_header(
    'Set up this device for training',
    'Do this on the iPad or PC itself. It becomes a training device and you are signed out.',
    '',
    [['label' => 'Training', 'url' => '/agent/training_courses.php'], ['label' => 'Devices & PINs', 'url' => '/agent/training_devices.php'], ['label' => 'Set up this device']]
);
?>
<div class="row g-3" id="tr-setup">
    <div class="col-lg-7">
        <div class="card" id="tr-setup-form-card">
            <div class="card-header"><h2 class="card-title">1. Which device is this?</h2></div>
            <div class="card-body">
                <div class="btn-group w-100 mb-3" role="radiogroup" aria-label="Is this device in Assets?">
                    <input type="radio" class="btn-check" name="tr-setup-kind" id="tr-setup-kind-asset" value="asset" autocomplete="off" checked>
                    <label class="btn btn-outline-primary" for="tr-setup-kind-asset"><i class="fas fa-box me-2" aria-hidden="true"></i>It's in Assets</label>
                    <input type="radio" class="btn-check" name="tr-setup-kind" id="tr-setup-kind-unlisted" value="unlisted" autocomplete="off">
                    <label class="btn btn-outline-primary" for="tr-setup-kind-unlisted"><i class="fas fa-question-circle me-2" aria-hidden="true"></i>This device isn't in Assets</label>
                </div>
                <div id="tr-setup-asset-pane">
                    <label class="form-label" for="tr-setup-q">Find the asset</label>
                    <div class="input-icon mb-2">
                        <span class="input-icon-addon"><i class="fas fa-search" aria-hidden="true"></i></span>
                        <input type="search" class="form-control" id="tr-setup-q" placeholder="Name, serial or asset tag" autocomplete="off" maxlength="80">
                    </div>
                    <div class="form-hint mb-2">Tablets, phones, laptops and desktops that are not archived.</div>
                    <div class="list-group list-group-flush border rounded tr-setup-assets" id="tr-setup-assets" role="listbox" aria-label="Assets"></div>
                </div>
                <div id="tr-setup-unlisted-pane" hidden>
                    <p class="mb-2">For a device you don't keep in Assets, like a trainer's laptop or a borrowed iPad. You only give it a name.</p>
                    <ul class="text-secondary small mb-0 ps-3">
                        <li>People find their name, then enter their PIN (it never opens straight to one person).</li>
                        <li>It isn't tied to an asset, so asset changes never switch it off. Revoke it when you're done, or make it temporary below.</li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="card mt-3" id="tr-setup-details" hidden>
            <div class="card-header"><h2 class="card-title">2. Name it and choose how long</h2></div>
            <div class="card-body">
                <div class="alert alert-info d-flex gap-2 align-items-start" id="tr-setup-mode" role="status"></div>
                <div class="alert alert-warning d-none" id="tr-setup-replace" role="alert"></div>
                <div class="mb-3">
                    <label class="form-label required" for="tr-setup-label">Device name</label>
                    <input type="text" class="form-control" id="tr-setup-label" maxlength="100" placeholder="Fab Shop iPad 2" autocomplete="off">
                    <div class="form-hint">People see it at the bottom of the sign-in screen.</div>
                    <div class="invalid-feedback" data-field="label"></div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="tr-setup-dept">Default department</label>
                    <select class="form-select" id="tr-setup-dept"><option value="0">None</option></select>
                    <div class="form-hint">Used as the default for group sessions started on this device.</div>
                </div>
                <fieldset class="mb-3">
                    <legend class="form-label">How long?</legend>
                    <div class="d-flex flex-column gap-2">
                        <label class="form-check mb-0">
                            <input class="form-check-input" type="radio" name="tr-setup-life" id="tr-setup-life-keep" value="keep" checked>
                            <span class="form-check-label">Keep until I remove it</span>
                        </label>
                        <label class="form-check mb-0">
                            <input class="form-check-input" type="radio" name="tr-setup-life" id="tr-setup-life-temp" value="temp">
                            <span class="form-check-label">Temporary <span class="text-secondary">(stops working by itself)</span></span>
                        </label>
                    </div>
                    <div class="tr-setup-temp mt-2 ms-4" id="tr-setup-temp" hidden>
                        <label class="form-label visually-hidden" for="tr-setup-expires">Stops working</label>
                        <select class="form-select" id="tr-setup-expires">
                            <option value="today">Until the end of today</option>
                            <option value="4h">For 4 hours</option>
                            <option value="8h">For 8 hours</option>
                            <option value="24h">For 24 hours</option>
                            <option value="until">Until a date and time…</option>
                        </select>
                        <div class="mt-2" id="tr-setup-until-wrap" hidden>
                            <label class="form-label" for="tr-setup-until">Date and time</label>
                            <input type="datetime-local" class="form-control" id="tr-setup-until" step="60">
                            <div class="invalid-feedback" data-field="expires_until"></div>
                        </div>
                        <div class="form-hint mt-2" id="tr-setup-expiry-preview" aria-live="polite"></div>
                    </div>
                </fieldset>
                <button type="button" class="btn btn-primary btn-lg" id="tr-setup-go"><i class="fas fa-tablet-alt me-2"></i>Use this device for training</button>
            </div>
        </div>
        <div class="card mt-3 border-success" id="tr-setup-done" hidden>
            <div class="card-header"><h2 class="card-title"><i class="fas fa-check-circle text-success me-2" aria-hidden="true"></i><span id="tr-setup-done-title">Enrolled</span></h2></div>
            <div class="card-body">
                <p id="tr-setup-done-mode" class="mb-2"></p>
                <p id="tr-setup-done-expiry" class="mb-3 d-flex align-items-start gap-2"></p>
                <label class="form-label" for="tr-setup-url">Start URL</label>
                <div class="input-group mb-2">
                    <input type="text" class="form-control font-monospace" id="tr-setup-url" readonly>
                    <button type="button" class="btn btn-outline-secondary" id="tr-setup-copy"><i class="fas fa-copy me-1" aria-hidden="true"></i>Copy</button>
                </div>
                <div class="form-hint mb-3">For Windows kiosk mode, set this as the start page. It is shown only once — keep it somewhere safe (anyone with it can set up a copy of this device, though people still need their PIN).</div>
                <button type="button" class="btn btn-primary btn-lg" id="tr-setup-open"><i class="fas fa-sign-out-alt me-2"></i>Open training on this device</button>
                <div class="form-hint mt-2">This signs you out of ITFlow on this device first.</div>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header"><h2 class="card-title">Before you start</h2></div>
            <div class="card-body">
                <ul class="mb-0 ps-3">
                    <li class="mb-2"><strong>iPad:</strong> open the training site from the <em>Home Screen app</em> (Share › Add to Home Screen), then sign in to ITFlow <em>inside that app</em> and come here. iPadOS 16.4 or later.</li>
                    <li class="mb-2"><strong>Windows:</strong> do this in Edge. Afterwards copy the start URL into the Edge kiosk settings.</li>
                    <li class="mb-2">A device assigned to one person opens straight to that person's PIN. Unassigned devices are shared and show the name search.</li>
                    <li class="mb-2"><strong>Borrowed or one-off device?</strong> Choose <em>This device isn't in Assets</em> and/or <em>Temporary</em>. A temporary device stops working by itself at the time you pick, and so does its start URL.</li>
                    <li>More in <a href="/agent/training_devices.php?tab=guide">the setup guide</a>.</li>
                </ul>
            </div>
        </div>
    </div>
</div>
<script type="application/json" id="tr-page-data"><?= json_encode($tr_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="/js/training_common.js?v=<?= filemtime(__DIR__ . '/../js/training_common.js') ?>" defer></script>
<script src="/agent/js/training_device_setup.js?v=<?= filemtime(__DIR__ . '/js/training_device_setup.js') ?>" defer></script>
<?php require_once "../includes/footer.php";
