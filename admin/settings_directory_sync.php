<?php
require_once "includes/inc_all_admin.php";

enforceUserPermission('module_client', 3);

$row_ms = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM microsoft_integrations ORDER BY microsoft_integration_id DESC LIMIT 1")) ?: [];
$ms_tenant_id = nullable_htmlentities($row_ms['tenant_id'] ?? '');
$ms_client_id = nullable_htmlentities($row_ms['client_id'] ?? '');
$ms_has_secret = !empty($row_ms['client_secret_enc']);
$ms_enabled = intval($row_ms['enabled'] ?? 0);
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

            <button type="submit" name="save_microsoft_integration" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Save</button>
            <button type="submit" name="test_microsoft_integration" class="btn btn-secondary"><i class="fas fa-plug me-2"></i>Test Connection</button>
        </form>
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
