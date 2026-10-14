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
use ITFlow\Integrations\ManageEngine\MdmSyncService;

(function () use ($mysqli) {
$csp_nonce = $GLOBALS["csp_nonce"] ?? "";
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
$mdm_edition = MdmClientFactory::edition($mdm ?? []);
$mdm_server_url = (string) ($mdm['server_url'] ?? '');
$mdm_has_key = !empty($mdm['api_key_enc']);
$mdm_key_expires = (string) ($mdm['api_key_expires_on'] ?? '');
$mdm_key_days = $mdm ? MdmSyncService::keyDaysLeft($mdm) : null;
$mdm_tls = (int) ($mdm['tls_verify'] ?? 1);
$mdm_msp = (int) ($mdm['msp_mode'] ?? 0);
$mdm_default_client = (int) ($mdm['default_client_id'] ?? 0);
$mdm_auto_create = $mdm ? (int) $mdm['auto_create_assets'] : 1;   // on for a first save; an existing connection keeps what it has
$mdm_retire = (int) ($mdm['stale_retire_enabled'] ?? 0);
$mdm_retire_days = (int) ($mdm['stale_retire_days'] ?? 30);
$mdm_clients = [];
$cres = mysqli_query($mysqli, 'SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL ORDER BY client_name ASC');
while ($cres && ($cr = mysqli_fetch_assoc($cres))) { $mdm_clients[] = $cr; }
$mdm_map_pending = 0;
if ($mdm) {
    $mres = mysqli_query($mysqli, "SELECT COUNT(*) FROM integration_client_map WHERE integration_id = " . \ITFlow\Integrations\ManageEngine\MdmDeviceMapper::mapKey((int) $mdm['id']) . " AND map_status = 'pending'");
    $mdm_map_pending = (int) ($mres ? mysqli_fetch_row($mres)[0] : 0);
}
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
$map_ready = $mdm && \ITFlow\Integrations\ManageEngine\MdmMappingSettings::schemaReady($mysqli);
$map_settings = $mdm ? \ITFlow\Integrations\ManageEngine\MdmMappingSettings::load($mysqli, (int) $mdm['id']) : \ITFlow\Integrations\ManageEngine\MdmMappingSettings::defaults();
$mdm_preview = $_SESSION['mdm_field_preview'] ?? null;   // one-time display of the last dry run
unset($_SESSION['mdm_field_preview']);
$csrf = $_SESSION['csrf_token'];
?>

<?php if (!$schema_ready) { ?>
    <div class="alert alert-warning">The ManageEngine MDM tables are not installed yet. Run Admin &rarr; Update &rarr; Update Database first.</div>
<?php } else { ?>

<div class="card mb-3">
    <div class="card-header py-3 d-flex align-items-center">
        <h3 class="card-title me-auto mb-0"><i class="fas fa-fw fa-tablet-alt me-2"></i>ManageEngine Mobile Device Manager Plus</h3>
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
        <?php if ($mdm_edition === 'onprem' && $mdm_key_days !== null && $mdm_key_days <= 30) { ?>
            <div class="alert alert-<?= $mdm_key_days < 0 ? 'danger' : 'warning' ?>"><i class="fas fa-key me-1"></i>
                <?= $mdm_key_days < 0 ? 'The API key expired on ' . nullable_htmlentities($mdm_key_expires) . '. Syncs fail until a new key is saved.' : 'The API key expires on ' . nullable_htmlentities($mdm_key_expires) . ' (' . (int) $mdm_key_days . ' day' . ($mdm_key_days === 1 ? '' : 's') . '). Generate a new one in the MDM server (Admin &gt; Integrations &gt; API Key Generation) and save it here.' ?>
            </div>
        <?php } ?>
        <?php if ($mdm_map_pending > 0) { ?>
            <div class="alert alert-info"><i class="fas fa-link me-1"></i><?= $mdm_map_pending ?> customer / account name<?= $mdm_map_pending === 1 ? '' : 's' ?> need a department before their devices get assets:
                <a href="/admin/settings_integrations.php?tab=rmm#client-mapping">Client mapping</a>.</div>
        <?php } ?>
        <p class="text-muted small">
            Read-only: <?= htmlspecialchars(APP_NAME) ?> lists your managed devices (iPads, Android tablets and phones) and links each one to an asset (serial number, IMEI/UDID, then name),
            creating a Phone or Tablet asset when there is none. It never locks, wipes, or changes a device, and never overwrites a field a person edited. Off until you save a connection and switch it on.
            Setup steps (on-premises API key, Zoho self client): <code>docs/MANAGEENGINE_MDM.md</code>.
        </p>

        <form action="post.php" method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">

            <div class="row g-3 mb-2">
                <div class="col-md-4">
                    <label class="form-label" for="mdm_edition">Edition</label>
                    <select class="form-select" name="edition" id="mdm_edition">
                        <option value="onprem" <?= $mdm_edition === 'onprem' ? 'selected' : '' ?>>On-premises (your own server, API key)</option>
                        <option value="cloud" <?= $mdm_edition === 'cloud' ? 'selected' : '' ?>>Cloud (Zoho sign-in)</option>
                    </select>
                </div>
                <div class="col-md-8 d-flex align-items-end"><div class="form-check form-switch mb-2">
                    <input type="checkbox" class="form-check-input" name="msp_mode" value="1" id="mdm_msp" <?= $mdm_msp ? 'checked' : '' ?>>
                    <label class="form-check-label" for="mdm_msp">MSP edition: sync every customer (each customer is mapped to a department)</label>
                </div></div>
            </div>

            <div class="row g-3" id="mdm_onprem_fields">
                <div class="col-md-6">
                    <label class="form-label" for="mdm_server_url">Server address</label>
                    <input type="text" class="form-control" name="server_url" id="mdm_server_url" maxlength="300" value="<?= nullable_htmlentities($mdm_server_url) ?>" placeholder="https://mdm.example.com:8383">
                    <div class="form-text">https only. A server on a private network must be inside a network listed under <a href="/admin/settings_webhooks.php">Webhooks &gt; Allowed internal networks</a> (the same list webhooks use).</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="mdm_api_key">API key</label>
                    <input type="password" class="form-control" name="api_key" id="mdm_api_key" autocomplete="new-password" placeholder="<?= $mdm_has_key ? 'Stored. Leave blank to keep it' : 'Paste the generated API key' ?>">
                    <div class="form-text">Stored encrypted, never shown again. Sent only in the Authorization header.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="mdm_key_expires">API key expires on</label>
                    <input type="date" class="form-control" name="api_key_expires_on" id="mdm_key_expires" value="<?= nullable_htmlentities($mdm_key_expires) ?>">
                    <div class="form-text">Keys last 6 months. You get a reminder 30 days before.</div>
                </div>
                <div class="col-md-8 d-flex align-items-end"><div class="form-check form-switch mb-4">
                    <input type="checkbox" class="form-check-input" name="tls_verify" value="1" id="mdm_tls" <?= $mdm_tls ? 'checked' : '' ?>>
                    <label class="form-check-label" for="mdm_tls">Verify the server's TLS certificate (turn off only for a self-signed certificate you trust)</label>
                </div></div>
            </div>

            <div class="row g-3" id="mdm_cloud_fields">
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
                <div class="col-12">
                    <label class="form-label" for="mdm_auth_code">One-time code from Zoho <span class="text-muted fw-normal">(easier than a refresh token)</span></label>
                    <input type="password" class="form-control" name="auth_code" id="mdm_auth_code" autocomplete="off" placeholder="1000.xxxxxxxx.xxxxxxxx (paste the code from the Generate Code tab, then Save straight away)">
                    <div class="form-text">
                        Instead of a refresh token, paste the <strong>code</strong> from the Zoho API console (Self Client &rarr; Generate Code) together with the client ID and secret above and click Save:
                        <?= htmlspecialchars(APP_NAME) ?> exchanges it for the refresh token and stores only that (encrypted). A code works once and expires within minutes, so generate it right before saving.
                        Never paste the code into the Refresh token box.
                    </div>
                </div>
            </div>

            <div class="row g-3 mt-0">
                <div class="col-md-6">
                    <label class="form-label" for="mdm_default_client">Default department</label>
                    <select class="form-select" name="default_client_id" id="mdm_default_client">
                        <option value="0">None (map it in the queue)</option>
                        <?php foreach ($mdm_clients as $cl) { ?><option value="<?= (int) $cl['client_id'] ?>" <?= $mdm_default_client === (int) $cl['client_id'] ? 'selected' : '' ?>><?= nullable_htmlentities($cl['client_name']) ?></option><?php } ?>
                    </select>
                    <div class="form-text">New assets go here when a customer / account has no saved mapping. Without it they wait in the <a href="/admin/settings_integrations.php?tab=rmm#client-mapping">Client mapping</a> queue.</div>
                </div>
                <div class="col-md-6 d-flex align-items-center"><div class="form-check form-switch">
                    <input type="checkbox" class="form-check-input" name="auto_create_assets" value="1" id="mdm_auto_create" <?= $mdm_auto_create ? 'checked' : '' ?>>
                    <label class="form-check-label" for="mdm_auto_create">Create and maintain assets: a device that matches none gets a Phone / Tablet asset, and linked assets keep make, model, OS and contact in step with the MDM, never over a person's edit (off: devices wait for review and a sync writes nothing on an asset)</label>
                </div></div>
                <div class="col-md-6"><div class="form-check form-switch">
                    <input type="checkbox" class="form-check-input" name="stale_retire_enabled" value="1" id="mdm_retire" <?= $mdm_retire ? 'checked' : '' ?>>
                    <label class="form-check-label" for="mdm_retire">Retire assets whose MDM device is gone or removed for</label>
                </div></div>
                <div class="col-md-6"><div class="input-group" style="max-width:14rem"><input type="number" class="form-control" name="stale_retire_days" min="7" max="3650" value="<?= $mdm_retire_days ?>"><span class="input-group-text">days</span></div>
                    <div class="form-text">Off by default. Retired assets are archived (never deleted) and listed under Integrations &gt; RMM &gt; Stale assets for review.</div></div>
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

            <div class="alert alert-secondary small" id="mdm_scope_note">
                <strong>Cloud scopes (read only):</strong> generate the self-client code with exactly
                <code><?= nullable_htmlentities(MdmClient::SCOPE_INVENTORY) ?></code> and
                <code><?= nullable_htmlentities(MdmClient::SCOPE_DEVICE_MGMT) ?></code>.
                MSP edition also needs <code><?= nullable_htmlentities(MdmClient::SCOPE_ADMIN) ?></code> (customer list). Nothing that can create, change or delete is requested.
            </div>

            <button type="submit" name="save_mdm_connection" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Save</button>
            <?php if ($mdm && MdmClientFactory::hasCredentials($mdm)) { ?>
                <button type="submit" name="test_mdm_connection" class="btn btn-secondary"><i class="fas fa-plug me-2"></i>Test connection</button>
                <?php if ($mdm_enabled) { ?>
                    <button type="submit" name="sync_mdm_now" class="btn btn-success"><i class="fas fa-sync me-2"></i>Sync now</button>
                <?php } ?>
            <?php } ?>
        </form>
    </div>
</div>

<?php if ($mdm && $map_ready) { ?>
<div class="card mb-3">
    <div class="card-header py-2">
        <h4 class="card-title mb-0"><i class="fas fa-fw fa-exchange-alt me-2"></i>Mapping options</h4>
    </div>
    <div class="card-body">
        <p class="text-muted small">
            Everything here is <strong>off</strong> until you switch it on. By default a sync only reads assets and shows ManageEngine's values in the MDM panel.
            Mapping devices to assets by hand, and users to contacts, is on the
            <a href="/agent/mdm_devices.php?view=mapping">Mapping</a> and <a href="/agent/mdm_devices.php?view=users">Users to contacts</a> pages under Endpoints &gt; MDM Devices.
        </p>
        <form action="post.php" method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">

            <h5 class="mt-2">Assign contact from MDM user</h5>
            <div class="form-check form-switch mb-1">
                <input type="checkbox" class="form-check-input" name="assign_contact" value="1" id="mdm_assign_contact" <?= $map_settings['assign_contact'] ? 'checked' : '' ?>>
                <label class="form-check-label" for="mdm_assign_contact">Set the asset's assigned contact from the device's user (the user must be mapped to a contact)</label>
            </div>
            <div class="form-check form-switch mb-1 ms-4">
                <input type="checkbox" class="form-check-input" name="assign_contact_overwrite" value="1" id="mdm_assign_overwrite" <?= $map_settings['assign_contact_overwrite'] ? 'checked' : '' ?>>
                <label class="form-check-label" for="mdm_assign_overwrite">Overwrite a contact that is already set (otherwise only an empty Assigned To is filled)</label>
            </div>
            <div class="form-text mb-3">The contact must be in the asset's department. Assignment history is recorded like a manual change.</div>

            <h5>Field mapping</h5>
            <div class="alert alert-warning small">
                <strong>Warning:</strong> with the master switch below on, every sync writes the fields you mark &quot;Write to asset&quot; onto the linked asset, for every linked device.
                &quot;Fill only when empty&quot; never replaces something a person typed. &quot;Always overwrite&quot; replaces the asset's value whenever ManageEngine reports a different one,
                so a manual edit is undone at the next sync. Use <strong>Preview</strong> first: it lists exactly what would change and writes nothing.
                Intune and RMM syncs can write the same asset columns; do not map the same column from two sources in overwrite mode.
            </div>
            <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead><tr><th>MDM field</th><th>Target</th><th>When writing to the asset</th></tr></thead>
                <tbody>
                <?php foreach (\ITFlow\Integrations\ManageEngine\MdmMappingSettings::FIELDS as $fk => $fd) {
                    $cfg = $map_settings['field_map'][$fk] ?? null; ?>
                    <tr>
                        <td><?= nullable_htmlentities($fd['label']) ?></td>
                        <td>
                            <select class="form-select form-select-sm" name="fm_target[<?= nullable_htmlentities($fk) ?>]">
                                <option value="panel">Show in the MDM panel only</option>
                                <?php if ($fd['asset'] !== null) { ?><option value="asset" <?= $cfg ? 'selected' : '' ?>>Write to asset: <?= nullable_htmlentities($fd['asset_label']) ?></option><?php } ?>
                            </select>
                        </td>
                        <td>
                            <?php if ($fd['asset'] === null) { ?>
                                <span class="text-muted small">No matching asset column</span>
                            <?php } else { ?>
                                <select class="form-select form-select-sm" name="fm_mode[<?= nullable_htmlentities($fk) ?>]">
                                    <option value="fill_empty" <?= ($cfg['mode'] ?? '') !== 'overwrite' ? 'selected' : '' ?>>Fill only when empty</option>
                                    <?php if (in_array('overwrite', $fd['modes'], true)) { ?><option value="overwrite" <?= ($cfg['mode'] ?? '') === 'overwrite' ? 'selected' : '' ?>>Always overwrite</option><?php } ?>
                                </select>
                            <?php } ?>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
            </div>

            <div class="form-check form-switch mb-1">
                <input type="checkbox" class="form-check-input" name="allow_asset_writes" value="1" id="mdm_allow_writes" <?= $map_settings['allow_asset_writes'] ? 'checked' : '' ?> <?= $mdm_enabled ? '' : 'disabled' ?>>
                <label class="form-check-label" for="mdm_allow_writes"><strong>Allow MDM to write asset fields</strong> (master switch, administrators only)</label>
            </div>
            <div class="form-text mb-3">Off by default, and switched off again whenever the integration is disabled. With it off nothing above is written, whatever the table says.</div>

            <button type="submit" name="save_mdm_mapping" class="btn btn-primary"><i class="fas fa-check me-2"></i>Save mapping options</button>
            <button type="submit" name="preview_mdm_field_writes" class="btn btn-secondary"><i class="fas fa-search me-2"></i>Preview changes (no writes)</button>
        </form>

        <?php if ($mdm_preview) { ?>
            <hr>
            <h5>Preview from <?= nullable_htmlentities($mdm_preview['at']) ?>: <?= (int) $mdm_preview['total'] ?> change(s) on the <?= (int) $mdm_preview['examined'] ?> linked, enrolled asset(s) examined. Nothing was written.</h5>
            <?php if (!$mdm_preview['master_on'] || !$mdm_preview['enabled']) { ?><div class="alert alert-info small">The master switch is off or the integration is disabled, so a sync would write nothing right now. This is what it would do once switched on.</div><?php } ?>
            <?php if ($mdm_preview['changes']) { ?>
            <div class="table-responsive"><table class="table table-sm">
                <thead><tr><th>Asset</th><th>Field</th><th>Mode</th><th>Now</th><th>Would become</th></tr></thead>
                <tbody>
                <?php foreach ($mdm_preview['changes'] as $c) { ?>
                    <tr>
                        <td><a href="/agent/asset_details.php?client_id=<?= (int) $c['client_id'] ?>&asset_id=<?= (int) $c['asset_id'] ?>"><?= nullable_htmlentities($c['asset_name']) ?></a></td>
                        <td><?= nullable_htmlentities($c['label']) ?></td>
                        <td><?= $c['mode'] === 'fill_empty' ? 'fill when empty' : 'overwrite' ?></td>
                        <td class="text-muted"><?= $c['old'] === '' ? '(empty)' : nullable_htmlentities($c['old']) ?></td>
                        <td><?= nullable_htmlentities($c['new']) ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table></div>
            <?php if ($mdm_preview['total'] > count($mdm_preview['changes'])) { ?><div class="text-muted small">Showing the first <?= count($mdm_preview['changes']) ?> of <?= (int) $mdm_preview['total'] ?>.</div><?php } ?>
            <?php } ?>
            <?php foreach ($mdm_preview['skipped'] as $c) { ?>
                <div class="small text-muted">Skipped: <?= nullable_htmlentities($c['asset_name']) ?>, <?= nullable_htmlentities(strtolower($c['label'])) ?>: <?= nullable_htmlentities($c['reason']) ?></div>
            <?php } ?>
        <?php } ?>
    </div>
</div>
<?php } elseif ($mdm) { ?>
    <div class="alert alert-warning">The MDM mapping tables are not installed yet. Run Admin &rarr; Update &rarr; Update Database to get the mapping options.</div>
<?php } ?>

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
            <div class="table-responsive"><table class="table table-sm mb-0">
                <thead><tr><th>Started</th><th>Status</th><th class="text-end">Devices</th><th class="text-end">New links</th><th class="text-end">Created</th><th class="text-end">Updated</th><th class="text-end">Review</th><th>By</th><th>Notes</th></tr></thead>
                <tbody>
                <?php foreach ($sync_log as $l) { ?>
                    <tr>
                        <td><?= nullable_htmlentities($l['started_at']) ?></td>
                        <td><span class="badge text-bg-<?= $l['status'] === 'success' ? 'success' : ($l['status'] === 'running' ? 'info' : 'danger') ?>"><?= nullable_htmlentities($l['status']) ?></span><?= $l['error_code'] ? ' <small class="text-muted">' . nullable_htmlentities($l['error_code']) . '</small>' : '' ?></td>
                        <td class="text-end"><?= (int) $l['devices_total'] ?></td>
                        <td class="text-end"><?= (int) $l['devices_matched'] ?></td>
                        <td class="text-end"><?= (int) ($l['devices_created'] ?? 0) ?></td>
                        <td class="text-end"><?= (int) $l['devices_updated'] ?></td>
                        <td class="text-end"><?= (int) $l['devices_unmatched'] ?></td>
                        <td><?= (int) $l['triggered_by'] === 0 ? 'schedule' : 'manual' ?></td>
                        <td class="small text-muted"><?= nullable_htmlentities(mb_substr((string) $l['errors'], 0, 300)) ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table></div>
        <?php } ?>
    </div>
</div>
<?php } ?>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
(function () {
    var ed = document.getElementById('mdm_edition');
    if (!ed) { return; }
    function sync() {
        var on = ed.value === 'onprem';
        document.getElementById('mdm_onprem_fields').classList.toggle('d-none', !on);
        document.getElementById('mdm_cloud_fields').classList.toggle('d-none', on);
        var a = document.getElementById('mdm_auth_code'); if (a) { a.closest('.col-12').classList.toggle('d-none', on); }
        var n = document.getElementById('mdm_scope_note'); if (n) { n.classList.toggle('d-none', on); }
    }
    ed.addEventListener('change', sync); sync();
})();
</script>
<?php } ?>
<?php })(); ?>
