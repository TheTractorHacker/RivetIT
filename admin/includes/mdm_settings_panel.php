<?php
/*
 * ManageEngine MDM connection panel: the form (save / test / sync now) and the recent-syncs card.
 * Rendered on the Device Sync tab of admin/settings_integrations.php (admin/settings_manageengine_mdm.php now only redirects there).
 * Posts to post.php; admin/post/settings_integrations.php fans out to admin/post/settings_manageengine_mdm.php.
 * Runs in its own closure so it cannot clobber the including page's variables.
 */
use ITFlow\Integrations\ManageEngine\DataCenters;
use ITFlow\Integrations\ManageEngine\MdmClient;
use ITFlow\Integrations\ManageEngine\MdmClientFactory;

(function () use ($mysqli) {
$mdm = MdmClientFactory::row($mysqli);
$schema_ready = $mdm !== null || (($r = @mysqli_query($mysqli, "SHOW TABLES LIKE 'manageengine_mdm_integrations'")) && mysqli_num_rows($r) > 0);
$mdm_enabled = (int) ($mdm['enabled'] ?? 0) === 1;
$mdm_dc = DataCenters::normalize($mdm['data_center'] ?? '');
$mdm_client_id = (string) ($mdm['client_id'] ?? '');
$mdm_has_secret = !empty($mdm['client_secret_enc']);
$mdm_has_refresh = !empty($mdm['refresh_token_enc']);
$mdm_interval = (int) ($mdm['sync_interval_minutes'] ?? 60);
$mdm_enrich = (int) ($mdm['enrich_details'] ?? 1);
$mdm_stale_days = (int) ($mdm['stale_days'] ?? 7);
$error_labels = [
    'auth_failed' => 'Sign-in failed', 'scope_missing' => 'Scope missing', 'throttled' => 'Throttled',
    'unreachable' => 'Could not reach ManageEngine', 'server_error' => 'ManageEngine error', 'bad_response' => 'Unexpected response',
    'time_limit' => 'Time limit reached', 'other' => 'Error',
];

$sync_log = [];
$unmatched_count = 0;
if ($mdm) {
    $iid = (int) $mdm['id'];
    $res = mysqli_query($mysqli, "SELECT * FROM mdm_sync_log WHERE integration_id = $iid ORDER BY id DESC LIMIT 8");
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $sync_log[] = $r;
    }
    $unmatched_count = (int) (mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM mdm_unmatched_devices WHERE integration_id = $iid AND dismissed = 0"))[0] ?? 0);
}
$csrf = $_SESSION['csrf_token'];
?>

<?php if (!$schema_ready) { ?>
    <div class="alert alert-warning">The ManageEngine MDM tables are not installed yet. Run Admin &rarr; Update &rarr; Update Database first.</div>
<?php } else { ?>

<div class="card mb-3">
    <div class="card-header py-3 d-flex align-items-center">
        <h3 class="card-title me-auto mb-0"><i class="fas fa-fw fa-tablet-alt me-2"></i>ManageEngine Mobile Device Manager Plus (cloud)</h3>
        <?php if ($mdm && $mdm['last_test_at']) { ?>
            <span class="badge <?= $mdm['last_test_success'] ? 'text-bg-success' : 'text-bg-danger' ?>">
                Last test: <?= $mdm['last_test_success'] ? 'Success' : 'Failed' ?> (<?= nullable_htmlentities($mdm['last_test_at']) ?>)
            </span>
        <?php } ?>
    </div>
    <div class="card-body">
        <?php if ($mdm && $mdm['last_test_error']) { ?>
            <div class="alert alert-danger"><?php if (!empty($mdm['last_test_error_code'])) { ?><strong><?= nullable_htmlentities($error_labels[$mdm['last_test_error_code']] ?? 'Error') ?>.</strong> <?php } ?><?= nullable_htmlentities($mdm['last_test_error']) ?></div>
        <?php } ?>
        <p class="text-muted small">
            Read-only: <?= htmlspecialchars(APP_NAME) ?> lists your managed devices (iPads, Android tablets and phones) and links each one to an asset by serial number.
            It never locks, wipes, or changes a device. Off until you save a connection and switch it on.
            Setup steps (Zoho self client and refresh token): <code>docs/MANAGEENGINE_MDM.md</code>.
        </p>

        <form action="post.php" method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">

            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label" for="mdm_data_center">Data center</label>
                    <select class="form-select" name="data_center" id="mdm_data_center">
                        <?php foreach (DataCenters::options() as $key => $label) { ?>
                            <option value="<?= nullable_htmlentities($key) ?>" <?= $key === $mdm_dc ? 'selected' : '' ?>><?= nullable_htmlentities($label) ?></option>
                        <?php } ?>
                    </select>
                    <div class="form-text">The region your ManageEngine MDM account lives in (the domain you sign in at).</div>
                </div>
                <div class="col-md-8">
                    <label class="form-label" for="mdm_client_id">Client ID</label>
                    <input type="text" class="form-control" name="client_id" id="mdm_client_id" maxlength="200" value="<?= nullable_htmlentities($mdm_client_id) ?>" placeholder="1000.XXXXXXXXXXXXXXXX">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="mdm_client_secret">Client secret</label>
                    <input type="password" class="form-control" name="client_secret" id="mdm_client_secret" autocomplete="new-password" placeholder="<?= $mdm_has_secret ? 'Stored. Leave blank to keep it' : 'Enter client secret' ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="mdm_refresh_token">Refresh token</label>
                    <input type="password" class="form-control" name="refresh_token" id="mdm_refresh_token" autocomplete="new-password" placeholder="<?= $mdm_has_refresh ? 'Stored. Leave blank to keep it' : 'Enter refresh token' ?>">
                </div>
            </div>

            <div class="row g-3 mt-0">
                <div class="col-md-4">
                    <label class="form-label" for="mdm_interval">Sync every (minutes)</label>
                    <input type="number" class="form-control" name="sync_interval_minutes" id="mdm_interval" min="5" max="1440" value="<?= $mdm_interval ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="mdm_stale">"Not seen" after (days)</label>
                    <input type="number" class="form-control" name="stale_days" id="mdm_stale" min="1" max="365" value="<?= $mdm_stale_days ?>">
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <div class="form-check form-switch mb-2">
                        <input type="checkbox" class="form-check-input" name="enrich_details" value="1" id="mdm_enrich" <?= $mdm_enrich ? 'checked' : '' ?>>
                        <label class="form-check-label" for="mdm_enrich">Fetch battery, storage, passcode and last contact</label>
                    </div>
                </div>
            </div>
            <div class="form-text mb-3">With the last switch on, each sync makes two extra read calls per device. Turn it off for very large fleets or if ManageEngine throttles you; the list data (name, OS, serial, user, lost mode) is still synced.</div>

            <div class="form-check form-switch mb-3">
                <input type="checkbox" class="form-check-input" name="enabled" value="1" id="mdm_enabled" <?= $mdm_enabled ? 'checked' : '' ?>>
                <label class="form-check-label" for="mdm_enabled">Enabled</label>
            </div>

            <div class="alert alert-secondary small">
                <strong>Scopes (read only):</strong> generate the self-client code with exactly
                <code><?= nullable_htmlentities(MdmClient::SCOPE_INVENTORY) ?></code> and
                <code><?= nullable_htmlentities(MdmClient::SCOPE_DEVICE_MGMT) ?></code>.
                Nothing that can create, change or delete is requested.
            </div>

            <button type="submit" name="save_mdm_connection" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Save</button>
            <?php if ($mdm && $mdm_has_secret && $mdm_has_refresh) { ?>
                <button type="submit" name="test_mdm_connection" class="btn btn-secondary"><i class="fas fa-plug me-2"></i>Test connection</button>
                <?php if ($mdm_enabled) { ?>
                    <button type="submit" name="sync_mdm_now" class="btn btn-success"><i class="fas fa-sync me-2"></i>Sync now</button>
                <?php } ?>
            <?php } ?>
        </form>
    </div>
</div>

<?php if ($mdm) { ?>
<div class="card mb-3">
    <div class="card-header py-2 d-flex align-items-center">
        <h4 class="card-title me-auto mb-0"><i class="fas fa-fw fa-history me-2"></i>Recent syncs</h4>
        <a class="btn btn-sm btn-outline-primary me-2" href="/agent/mdm_devices.php"><i class="fas fa-tablet-alt me-1"></i>Devices</a>
        <a class="btn btn-sm btn-outline-<?= $unmatched_count ? 'warning' : 'secondary' ?>" href="/agent/mdm_devices.php?view=unmatched"><?= $unmatched_count ?> need review</a>
    </div>
    <div class="card-body p-0">
        <?php if (!$sync_log) { ?>
            <div class="p-3 text-muted">No sync has run yet. Last sync: <?= $mdm['last_sync_at'] ? nullable_htmlentities($mdm['last_sync_at']) : 'never' ?>.</div>
        <?php } else { ?>
            <table class="table table-sm mb-0">
                <thead><tr><th>Started</th><th>Status</th><th class="text-end">Devices</th><th class="text-end">New links</th><th class="text-end">Updated</th><th class="text-end">Review</th><th>By</th><th>Notes</th></tr></thead>
                <tbody>
                <?php foreach ($sync_log as $l) { ?>
                    <tr>
                        <td><?= nullable_htmlentities($l['started_at']) ?></td>
                        <td><span class="badge text-bg-<?= $l['status'] === 'success' ? 'success' : ($l['status'] === 'running' ? 'info' : 'danger') ?>"><?= nullable_htmlentities($l['status']) ?></span><?= $l['error_code'] ? ' <small class="text-muted">' . nullable_htmlentities($l['error_code']) . '</small>' : '' ?></td>
                        <td class="text-end"><?= (int) $l['devices_total'] ?></td>
                        <td class="text-end"><?= (int) $l['devices_matched'] ?></td>
                        <td class="text-end"><?= (int) $l['devices_updated'] ?></td>
                        <td class="text-end"><?= (int) $l['devices_unmatched'] ?></td>
                        <td><?= (int) $l['triggered_by'] === 0 ? 'schedule' : 'manual' ?></td>
                        <td class="small text-muted"><?= nullable_htmlentities(mb_substr((string) $l['errors'], 0, 160)) ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        <?php } ?>
    </div>
</div>
<?php } ?>

<?php } ?>
<?php })(); ?>
