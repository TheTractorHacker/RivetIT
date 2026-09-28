<?php

/*
 * Training › Devices & PINs (P3 spec §5.8, lane K2; module_training_kiosk >= 1).
 *
 *   Devices tab       every training device: label, type, asset (or "Not in Assets" for an unlisted
 *                     device), personal owner (or "Assignment changed - re-enroll"), status, last
 *                     seen, browser, cooldown, "Temporary · expires <time>" / "Expired". Revoke, New
 *                     start URL, Change / Set end time, End now and Remove now (temporary devices, kiosk 3), Clear cooldown
 *                     (kiosk >= 2), [S] setup code. A banner for the system-wide sign-in pause with
 *                     Clear pause (kiosk >= 2).
 *   People & PINs     (scoped) PIN source, local PIN, failures, locks, Odoo block, last sign-in,
 *                     trainer. Unlock, [S★] Unblock Odoo link, Issue setup slips (-> print page),
 *                     [S★] Refresh PIN sources. The Odoo-PIN switch state is shown.
 *   Setup guide       [S] D-7: iPad and Windows kiosk setup (the docs/ path is denied by nginx).
 */

$page_extra_css = ['/css/itflow_training.css'];
require_once "includes/inc_all.php";
if (\ITFlow\Training\Core\Access::pageGuardKiosk(1)) { require_once "../includes/footer.php"; exit; }
define('TRAINING_PAGE', 1);

$tr_ctx = \ITFlow\Training\Core\Access::ctx($mysqli);
$tr_klevel = \ITFlow\Training\Core\Access::kioskLevel();
$tr_ks = \ITFlow\Training\Kiosk\Core\KioskSettings::fromDb($mysqli);
$tr_scope = \ITFlow\Training\Kiosk\Pin\Seam::scopeClientIds($tr_ctx);
$tr_departments = [];
$tr_res = mysqli_query($mysqli, 'SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL ORDER BY client_name');
while ($tr_res && ($tr_row = mysqli_fetch_assoc($tr_res))) {
    if ($tr_scope === null || in_array((int) $tr_row['client_id'], $tr_scope, true)) {
        $tr_departments[] = ['id' => (int) $tr_row['client_id'], 'name' => (string) $tr_row['client_name']];
    }
}
$tr_tab = in_array($_GET['tab'] ?? '', ['devices', 'people', 'guide'], true) ? $_GET['tab'] : 'devices';
$tr_data = [
    'kiosk_level' => $tr_klevel,
    'training_level' => $tr_ctx->level,
    'is_admin' => $tr_ctx->isAdmin,
    'departments' => $tr_departments,
    'odoo_pin_enabled' => $tr_ks->odooPinEnabled,
    'schema_ready' => $tr_ks->schemaReady,
    'tab' => $tr_tab,
    'timezone' => date_default_timezone_get(),
    'max_days' => \ITFlow\Training\Kiosk\Device\DeviceLifecycle::MAX_DAYS,
];

render_page_header(
    'Devices & PINs',
    'Training iPads and PCs, and the PINs people use on them.',
    $tr_klevel >= 3 ? '<a class="btn btn-outline-primary me-2" href="/agent/training_device_bulk.php"><i class="fas fa-print me-2"></i>Get setup codes</a>'
        . '<a class="btn btn-primary" href="/agent/training_device_setup.php"><i class="fas fa-plus me-2"></i>Set up a device</a>' : '',
    [['label' => 'Training', 'url' => '/agent/training_courses.php'], ['label' => 'Devices & PINs']]
);
?>
<?php if (!$tr_ks->schemaReady) { ?>
<div class="alert alert-warning" role="status">The training kiosk tables are not installed yet. Run Admin › Update › Update Database.</div>
<?php } else { ?>
<div id="tr-devices-root">
    <div id="tr-pause-banner"></div>
    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item" role="presentation"><button class="nav-link<?= $tr_tab === 'devices' ? ' active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tr-tab-devices" type="button" role="tab" aria-controls="tr-tab-devices" aria-selected="<?= $tr_tab === 'devices' ? 'true' : 'false' ?>" data-tab="devices"><i class="fas fa-tablet-alt me-2" aria-hidden="true"></i>Devices</button></li>
        <li class="nav-item" role="presentation"><button class="nav-link<?= $tr_tab === 'people' ? ' active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tr-tab-people" type="button" role="tab" aria-controls="tr-tab-people" aria-selected="<?= $tr_tab === 'people' ? 'true' : 'false' ?>" data-tab="people"><i class="fas fa-key me-2" aria-hidden="true"></i>People &amp; PINs</button></li>
        <li class="nav-item" role="presentation"><button class="nav-link<?= $tr_tab === 'guide' ? ' active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tr-tab-guide" type="button" role="tab" aria-controls="tr-tab-guide" aria-selected="<?= $tr_tab === 'guide' ? 'true' : 'false' ?>" data-tab="guide"><i class="fas fa-book me-2" aria-hidden="true"></i>Setup guide</button></li>
    </ul>
    <div class="tab-content">
        <div class="tab-pane fade<?= $tr_tab === 'devices' ? ' show active' : '' ?>" id="tr-tab-devices" role="tabpanel">
            <div id="tr-device-bulk-bar" class="alert alert-secondary d-flex align-items-center gap-2 mb-3" role="toolbar" hidden>
                <span id="tr-device-bulk-count"></span>
                <button type="button" class="btn btn-sm btn-outline-danger ms-auto" id="tr-device-bulk-remove"><i class="fas fa-trash-alt me-1" aria-hidden="true"></i>Remove selected</button>
                <button type="button" class="btn btn-sm btn-link" id="tr-device-bulk-clear">Clear</button>
            </div>
            <div class="row row-cards" id="tr-device-list" aria-busy="true"></div>
        </div>
        <div class="tab-pane fade<?= $tr_tab === 'people' ? ' show active' : '' ?>" id="tr-tab-people" role="tabpanel">
            <div class="alert <?= $tr_ks->odooPinEnabled ? 'alert-info' : 'alert-secondary' ?> d-flex flex-wrap align-items-center gap-2" role="note">
                <i class="fas <?= $tr_ks->odooPinEnabled ? 'fa-plug' : 'fa-key' ?>" aria-hidden="true"></i>
                <span class="me-auto"><?= $tr_ks->odooPinEnabled
                    ? 'Odoo-PIN sign-in is ON: people with a usable Odoo PIN sign in with it; everyone else uses a training PIN.'
                    : 'Odoo-PIN sign-in is OFF — everyone uses training PINs.' ?></span>
                <?php if ($tr_ctx->isAdmin) { ?><a class="btn btn-sm btn-outline-dark" href="/admin/settings_training_kiosk.php">Kiosk settings</a><?php } elseif ($tr_ctx->level >= 3) { ?><a class="btn btn-sm btn-outline-dark" href="/agent/training_settings.php#kiosk">Kiosk settings</a><?php } ?>
            </div>
            <div class="card">
                <div class="card-body border-bottom py-3">
                    <div class="d-flex flex-wrap gap-2 align-items-end">
                        <div class="flex-grow-1" style="min-width:200px">
                            <label class="form-label" for="tr-people-q">Search</label>
                            <input type="search" class="form-control" id="tr-people-q" placeholder="Name" autocomplete="off" maxlength="80">
                        </div>
                        <div>
                            <label class="form-label" for="tr-people-dept">Department</label>
                            <select class="form-select" id="tr-people-dept"><option value="0">All departments</option></select>
                        </div>
                        <div>
                            <label class="form-label" for="tr-people-filter">Show</label>
                            <select class="form-select" id="tr-people-filter">
                                <option value="all">Everyone</option>
                                <option value="needs_slip">Needs a setup slip</option>
                                <option value="locked">Locked</option>
                                <option value="blocked">Odoo link blocked</option>
                            </select>
                        </div>
                        <?php if ($tr_klevel >= 2) { ?>
                        <div class="d-flex gap-2 ms-auto">
                            <button type="button" class="btn btn-primary" id="tr-people-slips" disabled><i class="fas fa-receipt me-2" aria-hidden="true"></i>Issue setup slips <span class="badge bg-white text-primary ms-1" id="tr-people-count">0</span></button>
                            <?php if ($tr_ks->odooPinEnabled) { ?>
                            <button type="button" class="btn btn-outline-secondary" id="tr-people-refresh"><i class="fas fa-sync me-2" aria-hidden="true"></i>Refresh PIN sources</button>
                            <?php } ?>
                        </div>
                        <?php } ?>
                    </div>
                    <div class="form-check mt-2<?= $tr_ks->odooPinEnabled && $tr_klevel >= 2 ? '' : ' d-none' ?>">
                        <input class="form-check-input" type="checkbox" id="tr-people-switch">
                        <label class="form-check-label" for="tr-people-switch">Switch Odoo-PIN people to a training PIN (they keep it even after the nightly Odoo check)</label>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead><tr>
                            <?php if ($tr_klevel >= 2) { ?><th class="w-1"><input class="form-check-input" type="checkbox" id="tr-people-all" aria-label="Select everyone shown"></th><?php } ?>
                            <th>Name</th><th>Source</th><th>Training PIN</th><th>Failed</th><th>Locked</th><th>Blocked</th><th>Last sign-in</th><th>Trainer</th><th class="w-1"><span class="visually-hidden">Actions</span></th>
                        </tr></thead>
                        <tbody id="tr-people-body"></tbody>
                    </table>
                </div>
                <div class="card-footer text-secondary small" id="tr-people-foot"></div>
            </div>
        </div>
        <div class="tab-pane fade<?= $tr_tab === 'guide' ? ' show active' : '' ?>" id="tr-tab-guide" role="tabpanel">
            <div class="row row-cards">
                <div class="col-lg-6">
                    <div class="card h-100"><div class="card-header"><h2 class="card-title"><i class="fas fa-tablet-alt me-2" aria-hidden="true"></i>iPad</h2></div>
                    <div class="card-body">
                        <ol class="ps-3">
                            <li class="mb-2">Update to <strong>iPadOS 16.4 or later</strong> (older iPads can't play YouTube/Vimeo lessons safely).</li>
                            <li class="mb-2">In Safari open <code><?= nullable_htmlentities('https://' . preg_replace('#^https?://#', '', (string) $config_base_url)) ?>/kiosk/</code>, tap <strong>Share › Add to Home Screen</strong>, then open the new <strong>Training</strong> icon.</li>
                            <li class="mb-2">Inside that app tap <strong>Set up this device (admin)</strong>, sign in to <?= nullable_htmlentities(APP_NAME) ?>, pick the iPad's asset and tap <strong>Use this device for training</strong>. Enrollment must happen inside the Home Screen app — Safari and the app keep separate cookies.</li>
                            <li class="mb-2">Tap <strong>Open training on this device</strong>. You are signed out and the iPad shows the name search (or the owner's PIN on a personal iPad).</li>
                            <li class="mb-2">Settings › Display &amp; Brightness › <strong>Auto-Lock: Never</strong> while it sits on its charger.</li>
                            <li class="mb-2">Optional: Settings › Accessibility › <strong>Guided Access</strong> on, then triple-click the top button in the Training app to lock the iPad to it.</li>
                            <li>If the iPad was set up in Safari by mistake, or its cookies were cleared, open the <strong>start URL</strong> again (or use <strong>New start URL</strong> on the Devices tab).</li>
                        </ol>
                    </div></div>
                </div>
                <div class="col-lg-6">
                    <div class="card h-100"><div class="card-header"><h2 class="card-title"><i class="fab fa-windows me-2" aria-hidden="true"></i>Windows PC (Edge kiosk)</h2></div>
                    <div class="card-body">
                        <ol class="ps-3">
                            <li class="mb-2">Set up the PC in Edge: open <code>/kiosk/</code>, <strong>Set up this device (admin)</strong>, pick the PC's asset, and <strong>copy the start URL</strong> from the success screen before you tap <em>Open training</em>.</li>
                            <li class="mb-2">Run Edge in public-browsing kiosk mode with the start URL:<br><code>msedge --kiosk "&lt;start URL&gt;" --edge-kiosk-type=public-browsing</code><br>or Settings › Accounts › Other users › <strong>Set up a kiosk</strong> (assigned access) with Edge and the start URL.</li>
                            <li class="mb-2">Public browsing clears cookies between sessions; the start URL re-adopts the device each time, so it keeps working.</li>
                            <li class="mb-2">Power &amp; sleep: <strong>Screen and sleep: Never</strong> while plugged in; turn off Windows Update active-hours restarts during shifts.</li>
                            <li>Touch screens work like the iPad; with a mouse and keyboard people can type their PIN on the number keys.</li>
                        </ol>
                        <div class="alert alert-warning mb-0">Treat the start URL like a key: anyone with it can set up a copy of the device (people still need their own PIN). If it leaks, use <strong>New start URL</strong>.</div>
                    </div></div>
                </div>
                <div class="col-12" id="tr-guide-temporary">
                    <div class="card"><div class="card-header"><h2 class="card-title"><i class="fas fa-hourglass-half me-2" aria-hidden="true"></i>Temporary or unlisted devices</h2></div>
                    <div class="card-body">
                        <ul class="ps-3 mb-0">
                            <li class="mb-2"><strong>Not in Assets?</strong> On <em>Set up this device</em>, choose <strong>This device isn't in Assets</strong> and give it a name, like "Trainer's laptop" or "Borrowed iPad". It works like a shared device: people find their name, then enter their PIN. It never opens straight to one person, and asset changes never switch it off.</li>
                            <li class="mb-2"><strong>Only needed for a while?</strong> Under <em>How long?</em> choose <strong>Temporary</strong>: until the end of today, 4, 8 or 24 hours, or a date and time up to 30 days away (<?= nullable_htmlentities(date_default_timezone_get()) ?> time). Asset devices default to <em>Keep until I remove it</em>; devices that aren't in Assets default to <em>until the end of today</em>.</li>
                            <li class="mb-2">On the device, the sign-in screen and trainer mode show <em>This device: … · until 3:13 PM</em>. From 15 minutes before the end the top bar shows <em>Ends 3:13 PM</em>.</li>
                            <li class="mb-2">When the time is up the device stops working, just as if it were revoked: anyone signed in is signed out, the screen says "This device's training time is over" with the time it ended, and its start URL stops working. It is revoked ("Temporary device expired") on its next tap, or by the 10-minute housekeeping job if nobody uses it. To use it again, set it up again on the device.</li>
                            <li class="mb-2">On the Devices tab a temporary device shows a <strong>Temporary</strong> badge and <strong>Temporary · expires</strong> with the time (amber with the minutes left in its last hour). <strong>Change end time</strong> shows the current end and the new one before you save (counted from now, or <em>Keep until I remove it</em>; an earlier time is flagged and the button says <em>Shorten it</em>). <strong>End now</strong> switches it off at once. A device whose time is up but that nobody has touched since shows <strong>Expired</strong> and <strong>Remove now</strong>.</li>
                            <li>A device kept until you remove it can be made temporary later with <strong>Set end time</strong> (for example a borrowed laptop set up as permanent by mistake). Training records always show whether the device was temporary <em>when the person signed</em>; changing its end time later doesn't change them.</li>
                        </ul>
                    </div></div>
                </div>
                <div class="col-lg-6">
                    <div class="card h-100"><div class="card-header"><h2 class="card-title"><i class="fas fa-chalkboard-teacher me-2" aria-hidden="true"></i>Run a session</h2></div>
                    <div class="card-body">
                        <p class="text-muted small">A trainer teaching a group course (S4, People › Trainers) on a training device.</p>
                        <ol class="ps-3">
                            <li class="mb-2"><strong>First time only — set your trainer PIN.</strong> Becoming a trainer never forces this right away; whenever you're ready, sign in to the app and go to <strong>Account › Security</strong> to set it yourself (or ask an administrator to set/reset it from People › Trainers). It's separate from your own training PIN as a learner — changing one never touches the other.</li>
                            <li class="mb-2">On the device's <strong>Trainer sign-in</strong> (the link at the bottom of the regular sign-in screen), find your name and enter your <strong>trainer PIN</strong>. This opens the Trainer home, never the People &amp; PINs list of everyone's learner PINs.</li>
                            <li class="mb-2">Tap <strong>Run a session</strong>, pick a course (only ones with a group-session part that you're set up to teach) and a department (defaults to the device's own department when you cover it), then <strong>Start</strong>.</li>
                            <li class="mb-2">People check themselves in with their own name and PIN — either you hand the device around, or they use <strong>Check in to a class</strong> from the sign-in screen on another device while your session is open.</li>
                            <li class="mb-2">Mark attendance, a hands-on pass/fail (courses with a practical part) and notes as you go. Anything that changes a record — a mark, a removal, marking someone present without their own PIN, or giving a badge — re-enters your <strong>trainer PIN</strong>, not theirs.</li>
                            <li>When everyone is checked in, tap <strong>Finish</strong>, tick the confirmation, sign and enter your trainer PIN once more. The session closes and each present person's completion is issued right away.</li>
                        </ol>
                    </div></div>
                </div>
                <div class="col-lg-6">
                    <div class="card h-100"><div class="card-header"><h2 class="card-title"><i class="fas fa-clipboard-check me-2" aria-hidden="true"></i>Evaluate</h2></div>
                    <div class="card-body">
                        <p class="text-muted small">A one-on-one hands-on evaluation with a checklist, for a course's practical part.</p>
                        <ol class="ps-3">
                            <li class="mb-2">Sign in to <strong>Trainer sign-in</strong> with your trainer PIN, then tap <strong>Evaluate</strong> and pick a course with a practical part that you're set up to evaluate.</li>
                            <li class="mb-2">Pick the person from the list — people whose progress is waiting on this evaluation, in your departments — then work through the checklist, marking each item <strong>Pass</strong> or <strong>Fail</strong>. The device always rebuilds the checklist from the course's current revision, so an item can't be added, dropped or reworded; any failed item fails the whole evaluation.</li>
                            <li class="mb-2">Tap <strong>Hand the iPad to {name}</strong>. The device switches into a restricted mode — only the evaluation screens work, nothing else. The employee reads the summary, signs, and enters <strong>their own</strong> PIN (their regular training PIN, not a trainer PIN).</li>
                            <li class="mb-2">Take the iPad back, sign, and enter your <strong>trainer PIN</strong> under <strong>Hand back</strong> — this is what actually records the evaluation and returns the device to your normal Trainer home. <strong>Cancel</strong> at any point before hand-back also needs your trainer PIN, and records nothing.</li>
                            <li>A failed evaluation still records — the person simply doesn't pass that attempt and can be evaluated again once they're ready.</li>
                        </ol>
                    </div></div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php } ?>
<script type="application/json" id="tr-page-data"><?= json_encode($tr_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="/js/training_common.js?v=<?= filemtime(__DIR__ . '/../js/training_common.js') ?>" defer></script>
<script src="/agent/js/training_device_time.js?v=<?= filemtime(__DIR__ . '/js/training_device_time.js') ?>" defer></script>
<script src="/agent/js/training_devices.js?v=<?= filemtime(__DIR__ . '/js/training_devices.js') ?>" defer></script>
<?php require_once "../includes/footer.php";
