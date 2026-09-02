<?php
require_once "includes/inc_all_admin.php";

enforceUserPermission('module_client', 3);

$row_ms = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM microsoft_integrations ORDER BY microsoft_integration_id DESC LIMIT 1")) ?: [];
$ms_id = intval($row_ms['microsoft_integration_id'] ?? 0);
$ms_tenant_id = nullable_htmlentities($row_ms['tenant_id'] ?? '');
$ms_client_id = nullable_htmlentities($row_ms['client_id'] ?? '');
$ms_has_secret = !empty($row_ms['client_secret_enc']);
$ms_enabled = intval($row_ms['enabled'] ?? 0);
$ms_intune_sync_enabled = intval($row_ms['intune_sync_enabled'] ?? 0);
$ms_last_test_at = $row_ms['last_test_at'] ?? null;
$ms_last_test_success = $row_ms['last_test_success'] ?? null;
$ms_last_test_error = nullable_htmlentities($row_ms['last_test_error'] ?? '');

$row_odoo = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM odoo_integrations ORDER BY odoo_integration_id DESC LIMIT 1")) ?: [];
$odoo_base_url = nullable_htmlentities($row_odoo['base_url'] ?? '');
$odoo_database = nullable_htmlentities($row_odoo['database_name'] ?? '');
$odoo_username = nullable_htmlentities($row_odoo['username'] ?? '');
$odoo_has_key = !empty($row_odoo['api_key_enc']);
$odoo_enabled = intval($row_odoo['enabled'] ?? 0);
$odoo_last_test_at = $row_odoo['last_test_at'] ?? null;
$odoo_last_test_success = $row_odoo['last_test_success'] ?? null;
$odoo_last_test_error = nullable_htmlentities($row_odoo['last_test_error'] ?? '');

?>

<div class="alert alert-info">
    <i class="fas fa-info-circle me-2"></i>
    Scaffolding: both integrations below are fully wired (real OAuth2/JSON-RPC clients, Test Connection buttons, encrypted credential storage) but disabled until real credentials are entered - this internal-IT instance doesn't have a connected Microsoft tenant or Odoo instance yet.
</div>

<div class="card card-dark mb-3">
    <div class="card-header py-2 d-flex align-items-center">
        <h3 class="card-title me-auto"><i class="fab fa-fw fa-microsoft me-2"></i>Microsoft 365 / Entra ID</h3>
        <?php if ($ms_last_test_at) { ?>
            <span class="badge <?= $ms_last_test_success ? 'text-bg-success' : 'text-bg-danger' ?>">
                Last test: <?= $ms_last_test_success ? 'Success' : 'Failed' ?> (<?= nullable_htmlentities($ms_last_test_at) ?>)
            </span>
        <?php } ?>
    </div>
    <div class="card-body">
        <?php if ($ms_last_test_error) { ?>
            <div class="alert alert-danger"><?= $ms_last_test_error ?></div>
        <?php } ?>
        <form action="post.php" method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

            <div class="form-group">
                <label>Tenant ID</label>
                <input type="text" class="form-control" name="tenant_id" value="<?= $ms_tenant_id ?>" placeholder="e.g. 72f988bf-86f1-41af-91ab-2d7cd011db47">
            </div>
            <div class="form-group">
                <label>Application (Client) ID</label>
                <input type="text" class="form-control" name="client_id" value="<?= $ms_client_id ?>">
            </div>
            <div class="form-group">
                <label>Client Secret</label>
                <input type="password" class="form-control" name="client_secret" placeholder="<?= $ms_has_secret ? 'Stored - leave blank to keep current' : 'Enter client secret' ?>" autocomplete="new-password">
            </div>
            <div class="form-check form-switch mb-3">
                <input type="checkbox" class="form-check-input" name="enabled" value="1" id="msEnabled" <?= $ms_enabled ? 'checked' : '' ?>>
                <label class="form-check-label" for="msEnabled">Enabled</label>
            </div>

            <hr>

            <div class="form-check form-switch mb-1">
                <input type="checkbox" class="form-check-input" name="intune_sync_enabled" value="1" id="msIntuneSyncEnabled" <?= $ms_intune_sync_enabled ? 'checked' : '' ?>>
                <label class="form-check-label" for="msIntuneSyncEnabled">Sync devices from Intune</label>
            </div>
            <small class="text-muted d-block mb-3">
                Requires the <code>DeviceManagementManagedDevices.Read.All</code> Application permission on this same app registration - grant it in Entra ID → App registrations → (your app) → API permissions → Add a permission → Microsoft Graph → Application permissions, then Grant admin consent. This app cannot grant that permission for you; it must be added in the Azure/Entra portal.
            </small>

            <button type="submit" name="save_microsoft_integration" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Save</button>
            <button type="submit" name="test_microsoft_integration" class="btn btn-secondary"><i class="fas fa-plug me-2"></i>Test Connection</button>
            <?php if ($ms_enabled && $ms_intune_sync_enabled && $ms_has_secret): ?>
            <button type="submit" name="sync_intune_devices" class="btn btn-success"><i class="fas fa-sync me-2"></i>Sync Now</button>
            <?php endif; ?>
        </form>

        <hr>

        <h5 class="mb-2"><i class="fas fa-fw fa-history me-2"></i>Recent Intune Syncs</h5>
        <?php
        $sql_intune_log = mysqli_query($mysqli,
            "SELECT * FROM intune_sync_log WHERE microsoft_integration_id = $ms_id ORDER BY id DESC LIMIT 5"
        );
        if (!$sql_intune_log || mysqli_num_rows($sql_intune_log) == 0): ?>
            <p class="text-muted small mb-0">No syncs yet.</p>
        <?php else: ?>
        <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead class="text-muted small border-bottom" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;">
                <tr>
                    <th>Started</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th>Updated</th>
                    <th>Matched</th>
                    <th>Skipped</th>
                    <th>Errors</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $intune_log_badge = ['success' => 'text-bg-success', 'failed' => 'text-bg-danger', 'running' => 'text-bg-secondary'];
            while ($lr = mysqli_fetch_assoc($sql_intune_log)):
            ?>
                <tr>
                    <td class="text-muted small"><?= nullable_htmlentities($lr['started_at']) ?></td>
                    <td><span class="badge <?= $intune_log_badge[$lr['status']] ?? 'text-bg-secondary' ?>"><?= nullable_htmlentities($lr['status']) ?></span></td>
                    <td><?= intval($lr['devices_created']) ?></td>
                    <td><?= intval($lr['devices_updated']) ?></td>
                    <td><?= intval($lr['devices_matched']) ?></td>
                    <td><?= intval($lr['devices_skipped']) ?></td>
                    <td class="text-muted small" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= nullable_htmlentities($lr['errors']) ?></td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="card card-dark mb-3">
    <div class="card-header py-2 d-flex align-items-center">
        <h3 class="card-title me-auto"><i class="fas fa-fw fa-cogs me-2"></i>Odoo</h3>
        <?php if ($odoo_last_test_at) { ?>
            <span class="badge <?= $odoo_last_test_success ? 'text-bg-success' : 'text-bg-danger' ?>">
                Last test: <?= $odoo_last_test_success ? 'Success' : 'Failed' ?> (<?= nullable_htmlentities($odoo_last_test_at) ?>)
            </span>
        <?php } ?>
    </div>
    <div class="card-body">
        <?php if ($odoo_last_test_error) { ?>
            <div class="alert alert-danger"><?= $odoo_last_test_error ?></div>
        <?php } ?>
        <form action="post.php" method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

            <div class="form-group">
                <label>Base URL</label>
                <input type="text" class="form-control" name="base_url" value="<?= $odoo_base_url ?>" placeholder="e.g. https://yourcompany.odoo.com">
            </div>
            <div class="form-group">
                <label>Database Name</label>
                <input type="text" class="form-control" name="database_name" value="<?= $odoo_database ?>">
            </div>
            <div class="form-group">
                <label>Username</label>
                <input type="text" class="form-control" name="username" value="<?= $odoo_username ?>" placeholder="e.g. admin@yourcompany.com">
            </div>
            <div class="form-group">
                <label>API Key</label>
                <input type="password" class="form-control" name="api_key" placeholder="<?= $odoo_has_key ? 'Stored - leave blank to keep current' : 'Enter API key' ?>" autocomplete="new-password">
            </div>
            <div class="form-check form-switch mb-3">
                <input type="checkbox" class="form-check-input" name="enabled" value="1" id="odooEnabled" <?= $odoo_enabled ? 'checked' : '' ?>>
                <label class="form-check-label" for="odooEnabled">Enabled</label>
            </div>

            <button type="submit" name="save_odoo_integration" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Save</button>
            <button type="submit" name="test_odoo_integration" class="btn btn-secondary"><i class="fas fa-plug me-2"></i>Test Connection</button>
        </form>
    </div>
</div>

<?php require_once "../includes/footer.php"; ?>
