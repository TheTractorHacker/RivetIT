<?php
require_once "includes/inc_all_admin.php";
enforceUserPermission('module_admin');
require_once "../includes/comet.php";

$active_tab = in_array($_GET['tab'] ?? '', ['rmm', 'backups', 'firewalls', 'unifi']) ? $_GET['tab'] : 'rmm';

// ─── RMM (non-Sophos) ───────────────────────────────────────────────────────
$sql_rmm_integrations = mysqli_query($mysqli, "SELECT * FROM rmm_integrations WHERE type != 'sophos_central' ORDER BY name ASC");
$sql_rmm_clients      = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL ORDER BY client_name ASC");

// ─── Backups (Comet) ────────────────────────────────────────────────────────
$comet_connected = $config_comet_enabled ? comet_test() : null;
$comet_error     = ($config_comet_enabled && !$comet_connected) ? comet_get_last_error() : null;

// ─── Firewalls (Sophos Central) ─────────────────────────────────────────────
$sql_fw_integrations = mysqli_query($mysqli, "SELECT * FROM rmm_integrations WHERE type='sophos_central' ORDER BY name ASC");
$sql_fw_assets       = mysqli_query($mysqli,
    "SELECT arl.*, a.asset_id, a.asset_name, a.asset_client_id, c.client_name
     FROM asset_rmm_links arl
     JOIN assets a ON a.asset_id = arl.asset_id
     LEFT JOIN clients c ON c.client_id = a.asset_client_id
     WHERE a.asset_type = 'Firewall/Router'
     ORDER BY arl.rmm_status ASC, arl.hostname ASC"
);
$sql_fw_clients_tmp = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL ORDER BY client_name ASC");
$all_fw_clients = [];
while ($c = mysqli_fetch_assoc($sql_fw_clients_tmp)) {
    $all_fw_clients[] = ['id' => intval($c['client_id']), 'name' => $c['client_name']];
}

// ─── UniFi ──────────────────────────────────────────────────────────────────
$sql_unifi_integrations = mysqli_query($mysqli, "SELECT * FROM unifi_integrations ORDER BY name ASC");
$sql_unifi_clients_tmp  = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL ORDER BY client_name ASC");
$all_unifi_clients = [];
while ($c = mysqli_fetch_assoc($sql_unifi_clients_tmp)) {
    $all_unifi_clients[] = ['id' => intval($c['client_id']), 'name' => $c['client_name']];
}

// All discovered site mappings, grouped by integration
$sql_unifi_site_maps = mysqli_query($mysqli,
    "SELECT sm.id, sm.integration_id, sm.unifi_site_id, sm.unifi_site_name, sm.client_id,
            i.name AS integration_name, i.type AS integration_type,
            c.client_name AS mapped_client_name,
            ac.client_id AS auto_client_id, ac.client_name AS auto_client_name
     FROM unifi_site_mappings sm
     JOIN unifi_integrations i ON i.id = sm.integration_id
     LEFT JOIN clients c  ON c.client_id  = sm.client_id AND c.client_archived_at IS NULL
     LEFT JOIN clients ac ON LOWER(ac.client_name) = LOWER(sm.unifi_site_name) AND ac.client_archived_at IS NULL
     ORDER BY i.name ASC, sm.unifi_site_name ASC"
);
$unifi_site_maps_by_integration = [];
while ($sm = mysqli_fetch_assoc($sql_unifi_site_maps)) {
    $iid = intval($sm['integration_id']);
    if (!isset($unifi_site_maps_by_integration[$iid])) {
        $unifi_site_maps_by_integration[$iid] = [
            'name' => $sm['integration_name'],
            'type' => $sm['integration_type'] ?? 'local',
            'sites' => [],
        ];
    }
    $unifi_site_maps_by_integration[$iid]['sites'][] = $sm;
}
?>

<div class="d-flex align-items-center mb-3">
    <h4 class="mb-0"><i class="fas fa-plug me-2"></i>Integrations</h4>
</div>

<ul class="nav nav-tabs mb-3" id="integrationsTabs">
    <li class="nav-item">
        <a class="nav-link <?= $active_tab === 'rmm'       ? 'active' : '' ?>" data-bs-toggle="tab" href="#tab-rmm"       data-tabkey="rmm"><i class="fas fa-desktop me-1"></i>RMM</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $active_tab === 'backups'   ? 'active' : '' ?>" data-bs-toggle="tab" href="#tab-backups"   data-tabkey="backups"><i class="fas fa-cloud-upload-alt me-1"></i>Backups</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $active_tab === 'firewalls' ? 'active' : '' ?>" data-bs-toggle="tab" href="#tab-firewalls" data-tabkey="firewalls"><i class="fas fa-fire-alt me-1"></i>Firewalls</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $active_tab === 'unifi'     ? 'active' : '' ?>" data-bs-toggle="tab" href="#tab-unifi"     data-tabkey="unifi"><i class="fas fa-wifi me-1"></i>UniFi</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="settings_accounting.php"><i class="fas fa-file-invoice-dollar me-1"></i>Accounting</a>
    </li>
</ul>

<div class="tab-content">

<!-- ═══════════════════════════════════════════════════════════════════════════
     RMM TAB
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="tab-pane <?= $active_tab === 'rmm' ? 'show active' : '' ?>" id="tab-rmm">

    <div class="card mb-3" style="border-top:3px solid #17a2b8;">
        <div class="card-header py-2 d-flex align-items-center">
            <h3 class="card-title me-auto"><i class="fas fa-fw fa-desktop me-2"></i>RMM Integration Settings</h3>
            <?php if ($config_module_enable_rmm): ?>
                <span class="badge text-bg-success"><i class="fas fa-check-circle me-1"></i>Module Enabled</span>
            <?php else: ?>
                <span class="badge text-bg-secondary"><i class="fas fa-times-circle me-1"></i>Module Disabled</span>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <form action="post.php" method="post">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <div class="form-group mb-2">
                    <div class="form-check form-check form-switch">
                        <input type="checkbox" class="form-check-input" id="rmm_module_enabled"
                               name="config_module_enable_rmm" value="1" <?= $config_module_enable_rmm ? 'checked' : '' ?>>
                        <label class="form-check-label" for="rmm_module_enabled">Enable RMM module (shows RMM features in asset and client pages)</label>
                    </div>
                </div>
                <button type="submit" name="save_rmm_module_settings" class="btn btn-primary btn-sm">
                    <i class="fas fa-check me-1"></i>Save Module Settings
                </button>
            </form>
        </div>
    </div>

    <?php if ($config_module_enable_rmm): ?>
    <div class="card mb-3">
        <div class="card-header py-2">
            <h3 class="card-title"><i class="fas fa-fw fa-desktop me-2"></i>Connect/Remote Preference</h3>
        </div>
        <div class="card-body">
            <p class="text-muted small">When a device is monitored by both Tactical RMM and Level.io at the same time, this controls which integration is used for the Connect button and status display on the asset page and asset list.</p>
            <form action="post.php" method="post">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <div class="form-group mb-2">
                    <div class="form-check form-check form-switch">
                        <input type="checkbox" class="form-check-input" id="rmm_prefer_tactical"
                               name="config_rmm_prefer_tactical" value="1" <?= $config_rmm_prefer_tactical ? 'checked' : '' ?>>
                        <label class="form-check-label" for="rmm_prefer_tactical">Prefer Tactical RMM for Connect/Remote when a device is tracked by multiple RMMs</label>
                    </div>
                    <small class="form-text text-muted">When off, no preference is applied and whichever integration synced/linked most recently is shown.</small>
                </div>
                <button type="submit" name="save_rmm_prefer_settings" class="btn btn-primary btn-sm">
                    <i class="fas fa-check me-1"></i>Save Preference
                </button>
            </form>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header py-2">
            <h3 class="card-title"><i class="fas fa-fw fa-ticket-alt me-2"></i>Automatic Ticket Creation</h3>
        </div>
        <div class="card-body">
            <p class="text-muted small">When a new RMM alert comes in with one of the selected severities, a ticket will be created automatically.</p>
            <form action="post.php" method="post">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <?php
                $auto_ticket_severities = array_filter(explode(',', $config_rmm_auto_ticket_severities));
                foreach (['critical' => 'Critical', 'error' => 'Error', 'warning' => 'Warning', 'info' => 'Info'] as $sev => $label):
                ?>
                <div class="form-check form-check form-check-inline">
                    <input type="checkbox" class="form-check-input" id="rmm_auto_ticket_<?= $sev ?>"
                           name="auto_ticket_severities[]" value="<?= $sev ?>"
                           <?= in_array($sev, $auto_ticket_severities) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="rmm_auto_ticket_<?= $sev ?>"><?= $label ?></label>
                </div>
                <?php endforeach; ?>
                <div class="mt-3">
                    <button type="submit" name="save_rmm_auto_ticket_settings" class="btn btn-primary btn-sm">
                        <i class="fas fa-check me-1"></i>Save Automation Settings
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div class="card mb-3">
        <div class="card-header py-2 d-flex align-items-center">
            <h3 class="card-title me-auto"><i class="fas fa-fw fa-plug me-2"></i>RMM Integrations</h3>
            <button class="btn btn-primary btn-sm js-rmm-reset-modal" data-bs-toggle="modal" data-bs-target="#rmm_addIntegrationModal">
                <i class="fas fa-plus me-1"></i>Add Integration
            </button>
        </div>
        <div class="card-body p-0">
            <?php if (mysqli_num_rows($sql_rmm_integrations) == 0): ?>
                <div class="text-center text-muted py-5">
                    <i class="fas fa-plug fa-3x mb-3"></i>
                    <p class="mb-1">No integrations configured.</p>
                    <p class="small">Add a Tactical RMM, Level.io, or Action1 connection to get started.</p>
                </div>
            <?php else: ?>
            <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="text-muted small border-bottom" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;">
                    <tr>
                        <th class="ps-3">Name</th>
                        <th>Provider</th>
                        <th>API URL</th>
                        <th>Status</th>
                        <th>Last Sync</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php
                mysqli_data_seek($sql_rmm_integrations, 0);
                while ($intg = mysqli_fetch_assoc($sql_rmm_integrations)):
                    $intg_id   = intval($intg['id']);
                    $intg_type = $intg['type'] ?? 'tactical_rmm';
                    $last_sync_row = mysqli_fetch_assoc(mysqli_query($mysqli,
                        "SELECT MAX(finished_at) as ls FROM rmm_sync_log WHERE integration_id=$intg_id LIMIT 1"
                    ));
                    $provider_label = ['tactical_rmm' => 'Tactical RMM', 'level' => 'Level.io', 'action1' => 'Action1'][$intg_type] ?? $intg_type;
                    $provider_color = ['tactical_rmm' => 'info',         'level' => 'primary',   'action1' => 'warning'][$intg_type] ?? 'secondary';
                ?>
                <tr>
                    <td class="ps-3 fw-bold"><?= nullable_htmlentities($intg['name']) ?></td>
                    <td><span class="badge text-bg-<?= $provider_color ?>"><?= $provider_label ?></span></td>
                    <td class="text-muted small"><?= nullable_htmlentities($intg['api_url']) ?></td>
                    <td><?= $intg['enabled'] ? '<span class="badge text-bg-success">Enabled</span>' : '<span class="badge text-bg-secondary">Disabled</span>' ?></td>
                    <td class="text-muted small"><?= $last_sync_row['ls'] ? nullable_htmlentities($last_sync_row['ls']) : 'Never' ?></td>
                    <td class="text-end pe-3" style="white-space:nowrap">
                        <button class="btn btn-xs btn-info js-rmm-test" data-intg-id="<?= $intg_id ?>">
                            <i class="fas fa-plug me-1"></i>Test
                        </button>
                        <button class="btn btn-xs btn-success js-rmm-sync" data-intg-id="<?= $intg_id ?>">
                            <i class="fas fa-sync me-1"></i>Sync Now
                        </button>
                        <button class="btn btn-xs btn-secondary js-rmm-edit"
                                data-intg='<?= json_encode([
                                    "id"              => $intg_id,
                                    "name"            => $intg['name'],
                                    "type"            => $intg_type,
                                    "api_url"         => $intg['api_url'],
                                    "web_url"         => $intg['web_url'] ?? '',
                                    "enabled"         => intval($intg['enabled']),
                                ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>'>
                            <i class="fas fa-edit"></i>
                        </button>
                        <form action="post.php" method="post" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="integration_id" value="<?= $intg_id ?>">
                            <button type="submit" name="delete_rmm_integration" class="btn btn-xs btn-danger confirm-link">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header py-2">
            <h3 class="card-title"><i class="fas fa-fw fa-history me-2"></i>Recent Sync Log</h3>
        </div>
        <div class="card-body p-0">
            <?php
            $sql_rmm_log = mysqli_query($mysqli,
                "SELECT l.*, i.name as integration_name, i.type as integration_type
                 FROM rmm_sync_log l
                 LEFT JOIN rmm_integrations i ON i.id = l.integration_id
                 WHERE i.type IN ('tactical_rmm','level','action1') OR i.id IS NULL
                 ORDER BY l.id DESC LIMIT 20"
            );
            if (mysqli_num_rows($sql_rmm_log) == 0): ?>
                <p class="text-muted text-center py-3 mb-0">No sync history yet.</p>
            <?php else: ?>
            <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="text-muted small border-bottom" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;">
                    <tr>
                        <th class="ps-3">Integration</th>
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
                <?php while ($lr = mysqli_fetch_assoc($sql_rmm_log)):
                    $badge = ['success' => 'text-bg-success', 'failed' => 'text-bg-danger', 'running' => 'text-bg-warning'];
                ?>
                <tr>
                    <td class="ps-3">
                        <?= nullable_htmlentities($lr['integration_name']) ?>
                        <?php $tp = $lr['integration_type'] ?? ''; if ($tp): ?>
                        <span class="badge text-bg-secondary ms-1" style="font-size:10px"><?= ['level' => 'Level.io', 'action1' => 'Action1'][$tp] ?? 'Tactical' ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted small"><?= nullable_htmlentities($lr['started_at']) ?></td>
                    <td><span class="badge <?= $badge[$lr['status']] ?? 'text-bg-secondary' ?>"><?= htmlspecialchars($lr['status']) ?></span></td>
                    <td><?= intval($lr['assets_created']) ?></td>
                    <td><?= intval($lr['assets_updated']) ?></td>
                    <td><?= intval($lr['assets_matched']) ?></td>
                    <td><?= intval($lr['assets_skipped']) ?></td>
                    <td class="text-muted small" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= nullable_htmlentities($lr['errors']) ?></td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add/Edit RMM Integration Modal -->
    <div class="modal fade" id="rmm_addIntegrationModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="rmm_integrationModalTitle">Add RMM Integration</h5>
                    <button type="button" class="close" data-bs-dismiss="modal">&times;</button>
                </div>
                <form action="post.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="integration_id" id="rmm_edit_integration_id" value="">
                    <div class="modal-body">

                        <div class="form-group">
                            <label class="small">Provider Type</label>
                            <div class="btn-group btn-group-sm w-100" role="group">
                                <input type="radio" class="btn-check js-rmm-type-radio" name="integration_type" id="rmm_type_tactical"
                                       value="tactical_rmm" checked>
                                <label class="btn btn-outline-info flex-fill" for="rmm_type_tactical" id="rmm_lbl_tactical"
                                       style="border-radius:4px 0 0 0">
                                    <i class="fas fa-server me-1"></i>Tactical RMM
                                </label>
                                <input type="radio" class="btn-check js-rmm-type-radio" name="integration_type" id="rmm_type_level"
                                       value="level">
                                <label class="btn btn-outline-primary flex-fill" for="rmm_type_level" id="rmm_lbl_level">
                                    <i class="fas fa-layer-group me-1"></i>Level.io
                                </label>
                                <input type="radio" class="btn-check js-rmm-type-radio" name="integration_type" id="rmm_type_action1"
                                       value="action1">
                                <label class="btn btn-outline-warning flex-fill" for="rmm_type_action1" id="rmm_lbl_action1"
                                       style="border-radius:0 4px 4px 0">
                                    <i class="fas fa-shield-alt me-1"></i>Action1
                                </label>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="small">Integration Name</label>
                            <input type="text" class="form-control form-control-sm" name="integration_name" id="rmm_integration_name" required
                                   placeholder="e.g. Primary Tactical RMM">
                        </div>

                        <div class="form-group">
                            <label class="small" id="rmm_label_api_url">API URL</label>
                            <input type="url" class="form-control form-control-sm" name="integration_api_url" id="rmm_integration_api_url"
                                   placeholder="https://api.yourdomain.com" required>
                            <small class="text-muted" id="rmm_help_api_url">
                                Tactical RMM: enter API server base URL (no trailing slash).
                            </small>
                        </div>

                        <div class="form-group">
                            <label class="small" id="rmm_label_web_url">Dashboard / Web URL</label>
                            <input type="url" class="form-control form-control-sm" name="integration_web_url" id="rmm_integration_web_url"
                                   placeholder="https://rmm.yourdomain.com">
                            <small class="text-muted" id="rmm_help_web_url">Browser-accessible dashboard URL (used for Connect button).</small>
                        </div>

                        <div class="form-group">
                            <label class="small" id="rmm_label_api_key">API Key</label>
                            <input type="password" class="form-control form-control-sm" name="integration_api_key" id="rmm_integration_api_key"
                                   autocomplete="new-password" placeholder="(leave blank to keep existing when editing)">
                            <small class="text-muted" id="rmm_help_api_key">
                                Stored encrypted. Generate in Tactical RMM → Settings → Global Settings → API Keys.
                            </small>
                        </div>

                        <div class="form-group" id="rmm_client_secret_group" style="display:none">
                            <label class="small">Client Secret</label>
                            <input type="password" class="form-control form-control-sm" name="integration_client_secret" id="rmm_integration_client_secret"
                                   autocomplete="new-password" placeholder="(leave blank to keep existing when editing)">
                            <small class="text-muted">Stored encrypted.</small>
                        </div>

                        <div class="form-check form-check form-switch">
                            <input type="checkbox" class="form-check-input" id="rmm_integration_enabled"
                                   name="integration_enabled" value="1" checked>
                            <label class="form-check-label" for="rmm_integration_enabled">Enabled</label>
                        </div>

                        <div id="rmm_test_result" class="mt-3"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="save_rmm_integration" class="btn btn-primary btn-sm">
                            <i class="fas fa-check me-1"></i>Save Integration
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

</div><!-- /#tab-rmm -->

<!-- ═══════════════════════════════════════════════════════════════════════════
     BACKUPS (COMET) TAB
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="tab-pane <?= $active_tab === 'backups' ? 'show active' : '' ?>" id="tab-backups">

    <div class="card mb-3" style="border-top:3px solid #f39c12;">
        <div class="card-header py-2 d-flex align-items-center">
            <h3 class="card-title me-auto"><i class="fas fa-fw fa-cloud-upload-alt me-2"></i>Comet Backup Integration</h3>
            <?php if ($config_comet_enabled): ?>
                <?php if ($comet_connected): ?>
                    <span class="badge text-bg-success"><i class="fas fa-check-circle me-1"></i>Connected</span>
                <?php else: ?>
                    <span class="badge text-bg-danger"><i class="fas fa-times-circle me-1"></i>Cannot reach server</span>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php if ($comet_error): ?>
        <div class="px-3 pt-2">
            <div class="small text-danger"><i class="fas fa-exclamation-triangle me-1"></i><?= nullable_htmlentities($comet_error) ?></div>
        </div>
        <?php endif; ?>
        <div class="card-body">
            <form action="post.php" method="post">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                <div class="form-group">
                    <div class="form-check form-check form-switch">
                        <input type="checkbox" class="form-check-input" id="comet_enabled"
                               name="config_comet_enabled" value="1" <?= $config_comet_enabled ? 'checked' : '' ?>>
                        <label class="form-check-label" for="comet_enabled">Enable Comet Backup integration</label>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="text-muted small mb-1">Server URL</label>
                            <input type="text" class="form-control form-control-sm" name="config_comet_server_url"
                                   value="<?= nullable_htmlentities($config_comet_server_url) ?>" placeholder="http://10.1.0.35:8060">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="text-muted small mb-1">Admin Username</label>
                            <input type="text" class="form-control form-control-sm" name="config_comet_admin_user" autocomplete="off"
                                   value="<?= nullable_htmlentities($config_comet_admin_user) ?>">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="text-muted small mb-1">Admin Password</label>
                            <input type="password" class="form-control form-control-sm" name="config_comet_admin_pass" autocomplete="new-password"
                                   placeholder="<?= $config_comet_admin_pass ? '(saved — leave blank to keep)' : '' ?>">
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="text-muted small mb-1">TOTP Secret <small>(for 2FA admin accounts — base32 secret from your authenticator)</small></label>
                            <input type="password" class="form-control form-control-sm font-monospace"
                                   name="config_comet_totp_secret" autocomplete="new-password"
                                   placeholder="<?= $config_comet_totp_secret ? '(saved)' : 'JBSWY3DPEHPK3PXP...' ?>">
                            <small class="text-muted">Leave blank to keep existing. Stored to generate TOTP codes automatically.</small>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="text-muted small mb-1">Webhook Secret</label>
                            <input type="text" class="form-control form-control-sm font-monospace"
                                   name="config_comet_webhook_secret" autocomplete="off"
                                   value="<?= nullable_htmlentities($config_comet_webhook_secret) ?>"
                                   placeholder="random-secret-string">
                            <small class="text-muted">Comet sends this in the <code>X-Comet-Secret</code> header. Leave blank to skip verification.</small>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <div class="form-check form-check form-switch">
                        <input type="checkbox" class="form-check-input" id="comet_auto_ticket"
                               name="config_comet_auto_ticket" value="1" <?= $config_comet_auto_ticket ? 'checked' : '' ?>>
                        <label class="form-check-label" for="comet_auto_ticket">
                            Auto-create tickets on backup failure (one ticket per device, auto-resolves on success)
                        </label>
                    </div>
                </div>

                <?php if (!empty($config_base_url)): ?>
                <div class="alert alert-secondary py-2 mb-3">
                    <strong><i class="fas fa-link me-1"></i>Webhook URL</strong> — add this in Comet Server → Admin → Server Settings → Webhooks:<br>
                    <code>https://<?= nullable_htmlentities($config_base_url) ?>/comet_webhook.php</code><br>
                    <small class="text-muted">Event: <strong>Job Completed (4201)</strong> &middot; Custom Header: <strong>X-Comet-Secret</strong></small>
                </div>
                <?php endif; ?>

                <hr>
                <button type="submit" name="save_comet_settings" class="btn btn-primary btn-sm">
                    <i class="fas fa-check me-1"></i>Save &amp; Test Connection
                </button>
                <?php if ($config_comet_enabled): ?>
                <a href="comet_status.php" class="btn btn-secondary btn-sm ms-2">
                    <i class="fas fa-th-list me-1"></i>View Backup Status
                </a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- Client mapping -->
    <div class="card">
        <div class="card-header py-2">
            <h3 class="card-title"><i class="fas fa-fw fa-link me-2"></i>Client → Comet User Mapping</h3>
        </div>
        <div class="card-body p-0">
            <?php if (!$config_comet_enabled || !$comet_connected): ?>
                <p class="text-muted text-center py-3 mb-0">
                    <?= !$config_comet_enabled ? 'Enable and configure Comet above to manage mappings.' : 'Cannot reach Comet server. Check connection settings.' ?>
                </p>
            <?php else:
                $comet_users = array_keys(comet_get_users() ?: []);
                sort($comet_users);
                $sql_comet_clients = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL ORDER BY client_name");
                $sql_comet_maps    = mysqli_query($mysqli, "SELECT map_client_id, map_comet_username FROM comet_client_map");
                $comet_maps = [];
                while ($m = mysqli_fetch_assoc($sql_comet_maps)) {
                    $comet_maps[intval($m['map_client_id'])] = $m['map_comet_username'];
                }
            ?>
            <form action="post.php" method="post">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <div class="table-responsive">
                <table class="table table-sm table-borderless table-hover mb-0">
                    <thead class="text-muted small border-bottom" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;">
                        <tr>
                            <th class="ps-3">ITFlow Client</th>
                            <th>Comet Username</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php while ($client = mysqli_fetch_assoc($sql_comet_clients)):
                        $cid    = intval($client['client_id']);
                        $cname  = nullable_htmlentities($client['client_name']);
                        $mapped = $comet_maps[$cid] ?? '';
                    ?>
                        <tr>
                            <td class="ps-3"><?= $cname ?></td>
                            <td style="width:55%;">
                                <select class="form-control form-control-sm" name="comet_map[<?= $cid ?>]">
                                    <option value="">— Not mapped —</option>
                                    <?php foreach ($comet_users as $cu): ?>
                                        <option value="<?= htmlspecialchars($cu) ?>" <?= $mapped === $cu ? 'selected' : '' ?>><?= htmlspecialchars($cu) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
                </div>
                <div class="card-footer py-2">
                    <button type="submit" name="save_comet_maps" class="btn btn-primary btn-sm">
                        <i class="fas fa-check me-1"></i>Save Mappings
                    </button>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>

</div><!-- /#tab-backups -->

<!-- ═══════════════════════════════════════════════════════════════════════════
     FIREWALLS (SOPHOS CENTRAL) TAB
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="tab-pane <?= $active_tab === 'firewalls' ? 'show active' : '' ?>" id="tab-firewalls">

    <!-- Sophos Central connections -->
    <div class="card mb-3" style="border-top:3px solid #28a745;">
        <div class="card-header py-2 d-flex align-items-center">
            <h3 class="card-title me-auto"><i class="fas fa-fw fa-fire-alt me-2"></i>Sophos Central Connections</h3>
            <button class="btn btn-primary btn-sm js-fw-reset-modal" data-bs-toggle="modal" data-bs-target="#fw_addModal">
                <i class="fas fa-plus me-1"></i>Add Connection
            </button>
        </div>
        <div class="card-body p-0">
            <?php if (mysqli_num_rows($sql_fw_integrations) == 0): ?>
                <div class="text-center text-muted py-5">
                    <i class="fas fa-fire-alt fa-3x mb-3"></i>
                    <p class="mb-1">No Sophos Central connections configured.</p>
                    <p class="small">Add a Sophos Central API credential (Client ID + Secret) to start syncing firewall inventory.</p>
                </div>
            <?php else: ?>
            <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="text-muted small border-bottom" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;">
                    <tr>
                        <th class="ps-3">Name</th>
                        <th>Status</th>
                        <th>Last Sync</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php
                mysqli_data_seek($sql_fw_integrations, 0);
                while ($intg = mysqli_fetch_assoc($sql_fw_integrations)):
                    $intg_id = intval($intg['id']);
                    $last_sync_row = mysqli_fetch_assoc(mysqli_query($mysqli,
                        "SELECT MAX(finished_at) as ls FROM rmm_sync_log WHERE integration_id=$intg_id LIMIT 1"
                    ));
                ?>
                <tr>
                    <td class="ps-3 fw-bold"><?= nullable_htmlentities($intg['name']) ?></td>
                    <td><?= $intg['enabled'] ? '<span class="badge text-bg-success">Enabled</span>' : '<span class="badge text-bg-secondary">Disabled</span>' ?></td>
                    <td class="text-muted small"><?= $last_sync_row['ls'] ? nullable_htmlentities($last_sync_row['ls']) : 'Never' ?></td>
                    <td class="text-end pe-3" style="white-space:nowrap">
                        <button class="btn btn-xs btn-info js-fw-test" data-intg-id="<?= $intg_id ?>">
                            <i class="fas fa-plug me-1"></i>Test
                        </button>
                        <button class="btn btn-xs btn-success js-fw-sync" data-intg-id="<?= $intg_id ?>">
                            <i class="fas fa-sync me-1"></i>Sync Now
                        </button>
                        <button class="btn btn-xs btn-secondary js-fw-edit"
                                data-intg='<?= json_encode([
                                    "id"               => $intg_id,
                                    "name"             => $intg['name'],
                                    "api_url"          => $intg['api_url'] ?? 'https://api.central.sophos.com',
                                    "web_url"          => $intg['web_url'] ?? '',
                                    "default_client_id"=> intval($intg['default_client_id'] ?? 0),
                                    "enabled"          => intval($intg['enabled']),
                                ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>'>
                            <i class="fas fa-edit"></i>
                        </button>
                        <form action="post.php" method="post" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="integration_id" value="<?= $intg_id ?>">
                            <button type="submit" name="delete_rmm_integration" class="btn btn-xs btn-danger confirm-link">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Firewall → Client Mapping -->
    <div class="card mb-3">
        <div class="card-header py-2">
            <h3 class="card-title"><i class="fas fa-fw fa-link me-2"></i>Firewall &rarr; Client Mapping</h3>
        </div>
        <div class="card-body p-0">
            <?php if (mysqli_num_rows($sql_fw_assets) === 0): ?>
                <div class="text-center text-muted py-5">
                    <i class="fas fa-fire-alt fa-3x mb-3"></i>
                    <p class="mb-1">No firewalls synced yet.</p>
                    <p class="small">Add a Sophos Central connection above and click <strong>Sync Now</strong> to import firewall inventory.</p>
                </div>
            <?php else: ?>
            <form action="post.php" method="post">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <div class="table-responsive">
                <table class="table table-sm table-borderless table-hover mb-0">
                    <thead class="text-muted small border-bottom" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;">
                        <tr>
                            <th class="ps-3" style="width:28px;"></th>
                            <th>Device</th>
                            <th>Model</th>
                            <th>Firmware</th>
                            <th>Assigned Client</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php while ($fw = mysqli_fetch_assoc($sql_fw_assets)):
                        $st = $fw['rmm_status'] ?: 'unknown';
                        $sc = ['online' => 'success', 'offline' => 'danger', 'unknown' => 'secondary'][$st] ?? 'secondary';
                        $si = ['online' => 'check-circle', 'offline' => 'times-circle', 'unknown' => 'question-circle'][$st] ?? 'question-circle';
                    ?>
                        <tr>
                            <td class="ps-3 text-center"><i class="fas fa-<?= $si ?> text-<?= $sc ?>"></i></td>
                            <td class="fw-bold small">
                                <a href="/agent/asset_details.php?asset_id=<?= intval($fw['asset_id']) ?>">
                                    <?= nullable_htmlentities($fw['hostname'] ?: $fw['asset_name']) ?>
                                </a>
                            </td>
                            <td class="text-muted small"><?= nullable_htmlentities($fw['model']) ?: '—' ?></td>
                            <td class="text-muted small"><?= nullable_htmlentities($fw['os_version']) ?: '—' ?></td>
                            <td style="min-width:200px;">
                                <select class="form-control form-control-sm" name="fw_client_map[<?= intval($fw['asset_id']) ?>]">
                                    <option value="0">— Not assigned —</option>
                                    <?php foreach ($all_fw_clients as $cl): ?>
                                        <option value="<?= $cl['id'] ?>" <?= intval($fw['asset_client_id']) === $cl['id'] ? 'selected' : '' ?>>
                                            <?= nullable_htmlentities($cl['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
                </div>
                <div class="card-footer py-2">
                    <button type="submit" name="save_firewall_client_mappings" class="btn btn-primary btn-sm">
                        <i class="fas fa-check me-1"></i>Save Mappings
                    </button>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Sophos sync log -->
    <div class="card">
        <div class="card-header py-2">
            <h3 class="card-title"><i class="fas fa-fw fa-history me-2"></i>Recent Sync Log</h3>
        </div>
        <div class="card-body p-0">
            <?php
            $sql_fw_log = mysqli_query($mysqli,
                "SELECT l.*, i.name as integration_name
                 FROM rmm_sync_log l
                 INNER JOIN rmm_integrations i ON i.id = l.integration_id AND i.type = 'sophos_central'
                 ORDER BY l.id DESC LIMIT 20"
            );
            if (mysqli_num_rows($sql_fw_log) == 0): ?>
                <p class="text-muted text-center py-3 mb-0">No sync history yet.</p>
            <?php else: ?>
            <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="text-muted small border-bottom" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;">
                    <tr>
                        <th class="ps-3">Connection</th>
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
                <?php while ($lr = mysqli_fetch_assoc($sql_fw_log)):
                    $badge = ['success' => 'text-bg-success', 'failed' => 'text-bg-danger', 'running' => 'text-bg-warning'];
                ?>
                <tr>
                    <td class="ps-3"><?= nullable_htmlentities($lr['integration_name']) ?></td>
                    <td class="text-muted small"><?= nullable_htmlentities($lr['started_at']) ?></td>
                    <td><span class="badge <?= $badge[$lr['status']] ?? 'text-bg-secondary' ?>"><?= htmlspecialchars($lr['status']) ?></span></td>
                    <td><?= intval($lr['assets_created']) ?></td>
                    <td><?= intval($lr['assets_updated']) ?></td>
                    <td><?= intval($lr['assets_matched']) ?></td>
                    <td><?= intval($lr['assets_skipped']) ?></td>
                    <td class="text-muted small" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= nullable_htmlentities($lr['errors']) ?></td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add/Edit Sophos Connection Modal -->
    <div class="modal fade" id="fw_addModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="fw_modalTitle">Add Sophos Central Connection</h5>
                    <button type="button" class="close" data-bs-dismiss="modal">&times;</button>
                </div>
                <form action="post.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="integration_id" id="fw_edit_id" value="">
                    <input type="hidden" name="integration_type" value="sophos_central">
                    <div class="modal-body">

                        <div class="form-group">
                            <label class="small">Connection Name</label>
                            <input type="text" class="form-control form-control-sm" name="integration_name" id="fw_name" required
                                   placeholder="e.g. Sophos Central Firewalls">
                        </div>

                        <div class="form-group">
                            <label class="small">API Entry Point</label>
                            <input type="url" class="form-control form-control-sm" name="integration_api_url" id="fw_api_url"
                                   value="https://api.central.sophos.com" required>
                            <small class="text-muted">Fixed Sophos Central endpoint — leave as-is.</small>
                        </div>

                        <div class="form-group">
                            <label class="small">Client ID</label>
                            <input type="password" class="form-control form-control-sm" name="integration_api_key" id="fw_client_id"
                                   autocomplete="new-password" placeholder="(leave blank to keep existing when editing)">
                            <small class="text-muted">From Sophos Central → Global Settings → API Credentials. Single-tenant credentials only.</small>
                        </div>

                        <div class="form-group">
                            <label class="small">Client Secret</label>
                            <input type="password" class="form-control form-control-sm" name="integration_client_secret" id="fw_client_secret"
                                   autocomplete="new-password" placeholder="(leave blank to keep existing when editing)">
                            <small class="text-muted">Stored encrypted.</small>
                        </div>

                        <div class="form-group">
                            <label class="small">Default Client</label>
                            <select class="form-control form-control-sm" name="integration_default_client_id" id="fw_default_client_id">
                                <option value="0">— None —</option>
                                <?php foreach ($all_fw_clients as $cl): ?>
                                <option value="<?= $cl['id'] ?>"><?= nullable_htmlentities($cl['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Newly-synced firewalls are auto-assigned to this client. Override per-device in the mapping table.</small>
                        </div>

                        <div class="form-check form-check form-switch">
                            <input type="checkbox" class="form-check-input" id="fw_enabled"
                                   name="integration_enabled" value="1" checked>
                            <label class="form-check-label" for="fw_enabled">Enabled</label>
                        </div>

                        <div id="fw_test_result" class="mt-3"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="save_rmm_integration" class="btn btn-primary btn-sm">
                            <i class="fas fa-check me-1"></i>Save Connection
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

</div><!-- /#tab-firewalls -->

<!-- ═══════════════════════════════════════════════════════════════════════════
     UNIFI TAB
     ═══════════════════════════════════════════════════════════════════════════ -->
<div class="tab-pane <?= $active_tab === 'unifi' ? 'show active' : '' ?>" id="tab-unifi">

    <div class="card mb-3" style="border-top:3px solid #17a2b8;">
        <div class="card-header py-2 d-flex align-items-center">
            <h3 class="card-title me-auto"><i class="fas fa-fw fa-wifi me-2"></i>UniFi Integration Settings</h3>
            <?php if ($config_module_enable_unifi): ?>
                <span class="badge text-bg-success"><i class="fas fa-check-circle me-1"></i>Module Enabled</span>
            <?php else: ?>
                <span class="badge text-bg-secondary"><i class="fas fa-times-circle me-1"></i>Module Disabled</span>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <p class="text-muted small">
                Syncs UniFi access points/switches to Assets, Wi-Fi SSIDs to Credentials, and networks (VLANs/subnets)
                to Networks. UniFi sites are matched to ITFlow clients by name (case-insensitive).
            </p>
            <form action="post.php" method="post">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <div class="form-group mb-2">
                    <div class="form-check form-check form-switch">
                        <input type="checkbox" class="form-check-input" id="unifi_module_enabled"
                               name="config_module_enable_unifi" value="1" <?= $config_module_enable_unifi ? 'checked' : '' ?>>
                        <label class="form-check-label" for="unifi_module_enabled">Enable UniFi module</label>
                    </div>
                </div>
                <button type="submit" name="save_unifi_module_settings" class="btn btn-primary btn-sm">
                    <i class="fas fa-check me-1"></i>Save Module Settings
                </button>
            </form>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header py-2 d-flex align-items-center">
            <h3 class="card-title me-auto"><i class="fas fa-fw fa-plug me-2"></i>UniFi Controllers</h3>
            <button class="btn btn-primary btn-sm js-unifi-reset-modal" data-bs-toggle="modal" data-bs-target="#unifi_addIntegrationModal">
                <i class="fas fa-plus me-1"></i>Add Controller
            </button>
        </div>
        <div class="card-body p-0">
            <?php if (mysqli_num_rows($sql_unifi_integrations) == 0): ?>
                <div class="text-center text-muted py-5">
                    <i class="fas fa-wifi fa-3x mb-3"></i>
                    <p class="mb-1">No UniFi controllers configured.</p>
                    <p class="small">Add a UniFi OS controller connection to get started.</p>
                </div>
            <?php else: ?>
            <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="text-muted small border-bottom" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;">
                    <tr>
                        <th class="ps-3">Name</th>
                        <th>Type</th>
                        <th>Host / Endpoint</th>
                        <th>Status</th>
                        <th>Last Sync</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php
                mysqli_data_seek($sql_unifi_integrations, 0);
                while ($intg = mysqli_fetch_assoc($sql_unifi_integrations)):
                    $intg_id   = intval($intg['id']);
                    $intg_type = $intg['type'] ?? 'local';
                    $last_sync_row = mysqli_fetch_assoc(mysqli_query($mysqli,
                        "SELECT MAX(finished_at) as ls FROM unifi_sync_log WHERE integration_id=$intg_id LIMIT 1"
                    ));
                    $host_display = $intg_type === 'cloud'
                        ? 'api.ui.com (Cloud)'
                        : nullable_htmlentities($intg['host']) . ':' . intval($intg['port']);
                ?>
                <tr>
                    <td class="ps-3 fw-bold"><?= nullable_htmlentities($intg['name']) ?></td>
                    <td>
                        <?php if ($intg_type === 'cloud'): ?>
                            <span class="badge text-bg-info"><i class="fas fa-cloud me-1"></i>Cloud</span>
                        <?php else: ?>
                            <span class="badge text-bg-secondary"><i class="fas fa-server me-1"></i>Local</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted small"><?= $host_display ?></td>
                    <td><?= $intg['enabled'] ? '<span class="badge text-bg-success">Enabled</span>' : '<span class="badge text-bg-secondary">Disabled</span>' ?></td>
                    <td class="text-muted small"><?= $last_sync_row['ls'] ? nullable_htmlentities($last_sync_row['ls']) : 'Never' ?></td>
                    <td class="text-end pe-3" style="white-space:nowrap">
                        <button class="btn btn-xs btn-info js-unifi-test" data-intg-id="<?= $intg_id ?>">
                            <i class="fas fa-plug me-1"></i>Test
                        </button>
                        <button class="btn btn-xs btn-success js-unifi-sync" data-intg-id="<?= $intg_id ?>">
                            <i class="fas fa-sync me-1"></i>Sync Now
                        </button>
                        <button class="btn btn-xs btn-primary js-unifi-open-sites" data-intg-id="<?= $intg_id ?>" data-intg-name="<?= htmlspecialchars($intg['name'], ENT_QUOTES) ?>">
                            <i class="fas fa-sitemap me-1"></i>Sites
                        </button>
                        <?php if ($intg_type === 'cloud'): ?>
                        <button class="btn btn-xs btn-warning js-unifi-inspect-sites" data-intg-id="<?= $intg_id ?>">
                            <i class="fas fa-bug me-1"></i>Inspect Hosts
                        </button>
                        <button class="btn btn-xs btn-warning js-unifi-inspect-devices" data-intg-id="<?= $intg_id ?>">
                            <i class="fas fa-hdd me-1"></i>Inspect Devices
                        </button>
                        <?php endif; ?>
                        <button class="btn btn-xs btn-secondary js-unifi-edit"
                                data-intg='<?= json_encode([
                                    "id"         => $intg_id,
                                    "name"       => $intg['name'],
                                    "type"       => $intg_type,
                                    "host"       => $intg['host'],
                                    "port"       => intval($intg['port']),
                                    "verify_ssl" => intval($intg['verify_ssl']),
                                    "enabled"    => intval($intg['enabled']),
                                ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>'>
                            <i class="fas fa-edit"></i>
                        </button>
                        <form action="post.php" method="post" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="integration_id" value="<?= $intg_id ?>">
                            <button type="submit" name="delete_unifi_integration" class="btn btn-xs btn-danger confirm-link">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header py-2">
            <h3 class="card-title"><i class="fas fa-fw fa-history me-2"></i>Recent Sync Log</h3>
        </div>
        <div class="card-body p-0">
            <?php
            $sql_unifi_log = mysqli_query($mysqli,
                "SELECT l.*, i.name as integration_name
                 FROM unifi_sync_log l
                 LEFT JOIN unifi_integrations i ON i.id = l.integration_id
                 ORDER BY l.id DESC LIMIT 20"
            );
            if (mysqli_num_rows($sql_unifi_log) == 0): ?>
                <p class="text-muted text-center py-3 mb-0">No sync history yet.</p>
            <?php else: ?>
            <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="text-muted small border-bottom" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;">
                    <tr>
                        <th class="ps-3">Controller</th>
                        <th>Started</th>
                        <th>Status</th>
                        <th>Devices</th>
                        <th>Wi-Fi</th>
                        <th>Networks</th>
                        <th>Errors</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($lr = mysqli_fetch_assoc($sql_unifi_log)):
                    $badge = ['success' => 'text-bg-success', 'failed' => 'text-bg-danger', 'running' => 'text-bg-warning'];
                ?>
                <tr>
                    <td class="ps-3"><?= nullable_htmlentities($lr['integration_name']) ?></td>
                    <td class="text-muted small"><?= nullable_htmlentities($lr['started_at']) ?></td>
                    <td><span class="badge <?= $badge[$lr['status']] ?? 'text-bg-secondary' ?>"><?= htmlspecialchars($lr['status']) ?></span></td>
                    <td class="text-muted small">+<?= intval($lr['devices_created']) ?> / ~<?= intval($lr['devices_updated']) ?> / -<?= intval($lr['devices_skipped']) ?></td>
                    <td class="text-muted small">+<?= intval($lr['wifi_created']) ?> / ~<?= intval($lr['wifi_updated']) ?> / -<?= intval($lr['wifi_skipped']) ?></td>
                    <td class="text-muted small">+<?= intval($lr['networks_created']) ?> / ~<?= intval($lr['networks_updated']) ?> / -<?= intval($lr['networks_skipped']) ?></td>
                    <td class="text-muted small" style="max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= nullable_htmlentities($lr['errors']) ?></td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Site → Client Mappings (all controllers) ─────────────────────── -->
    <div class="card mt-3">
        <div class="card-header py-2 d-flex align-items-center">
            <h3 class="card-title me-auto"><i class="fas fa-fw fa-sitemap me-2"></i>Site &rarr; Client Mappings</h3>
            <button type="button" class="btn btn-secondary btn-sm js-unifi-refresh-all-sites">
                <i class="fas fa-sync me-1"></i>Refresh All Sites
            </button>
        </div>
        <?php if (empty($unifi_site_maps_by_integration)): ?>
        <div class="card-body text-center text-muted py-5">
            <i class="fas fa-sitemap fa-3x mb-3"></i>
            <p class="mb-1">No sites discovered yet.</p>
            <p class="small">Click <strong>Sync Now</strong> on a controller above, or <strong>Refresh All Sites</strong>, to import site data.</p>
        </div>
        <?php else: ?>
        <form action="post.php" method="post">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <?php $_unifi_intg_idx = 0; foreach ($unifi_site_maps_by_integration as $intg_id => $intg_data):
                $type_badge = $intg_data['type'] === 'cloud'
                    ? '<span class="badge text-bg-info ms-1"><i class="fas fa-cloud me-1"></i>Cloud</span>'
                    : '<span class="badge text-bg-secondary ms-1"><i class="fas fa-server me-1"></i>Local</span>';
            ?>
            <div class="px-3 pt-3 pb-1">
                <div class="d-flex align-items-center mb-2">
                    <strong class="me-2"><?= nullable_htmlentities($intg_data['name']) ?></strong>
                    <?= $type_badge ?>
                    <button type="button" class="btn btn-xs btn-outline-secondary ms-auto js-unifi-refresh-sites"
                            data-intg-id="<?= $intg_id ?>">
                        <i class="fas fa-sync me-1"></i>Refresh
                    </button>
                </div>
                <div class="table-responsive">
                <table class="table table-sm table-borderless mb-2" style="background:transparent">
                    <thead class="text-muted small" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;">
                        <tr>
                            <th style="width:30%">UniFi Site</th>
                            <th style="width:25%">Auto-Match</th>
                            <th>Client Mapping</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($intg_data['sites'] as $sm):
                        $map_id = intval($sm['id']);
                        if ($sm['client_id'] === null) {
                            $selected = 'auto';
                        } elseif (intval($sm['client_id']) === 0) {
                            $selected = 'skip';
                        } else {
                            $selected = strval(intval($sm['client_id']));
                        }
                    ?>
                    <tr>
                        <td class="small fw-bold align-middle"><?= nullable_htmlentities($sm['unifi_site_name']) ?></td>
                        <td class="small text-muted align-middle">
                            <?php if ($sm['auto_client_name']): ?>
                                <i class="fas fa-check-circle text-success me-1"></i><?= nullable_htmlentities($sm['auto_client_name']) ?>
                            <?php else: ?>
                                <span class="text-muted"><em>no match</em></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <select class="form-control form-control-sm" name="site_map[<?= $map_id ?>]">
                                <option value="auto" <?= $selected === 'auto' ? 'selected' : '' ?>>Auto (match by name)</option>
                                <option value="skip" <?= $selected === 'skip' ? 'selected' : '' ?>>Skip (don't sync)</option>
                                <?php foreach ($all_unifi_clients as $cl): ?>
                                <option value="<?= $cl['id'] ?>" <?= $selected === strval($cl['id']) ? 'selected' : '' ?>>
                                    <?= nullable_htmlentities($cl['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
            <?php if (++$_unifi_intg_idx < count($unifi_site_maps_by_integration)): ?>
            <hr class="my-0">
            <?php endif; ?>
            <?php endforeach; unset($_unifi_intg_idx); ?>
            <div class="card-footer py-2">
                <button type="submit" name="save_all_unifi_site_mappings" class="btn btn-primary btn-sm">
                    <i class="fas fa-check me-1"></i>Save All Mappings
                </button>
            </div>
        </form>
        <?php endif; ?>
    </div>

    <!-- Add/Edit UniFi Controller Modal -->
    <div class="modal fade" id="unifi_addIntegrationModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="unifi_integrationModalTitle">Add UniFi Connection</h5>
                    <button type="button" class="close" data-bs-dismiss="modal">&times;</button>
                </div>
                <form action="post.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="integration_id" id="unifi_edit_integration_id" value="">
                    <div class="modal-body">

                        <input type="hidden" name="integration_type" id="unifi_integration_type" value="local">
                        <div class="form-group">
                            <label class="small mb-2">Connection Type</label>
                            <div class="d-flex" style="gap:10px">
                                <div id="unifi_card_local" data-unifi-type="local"
                                     class="js-unifi-select-type flex-fill text-center rounded py-3 px-2"
                                     style="cursor:pointer;border:2px solid #6c757d;background:rgba(108,117,125,.15);transition:border-color .15s,background .15s;">
                                    <i class="fas fa-server d-block mb-1" style="font-size:1.3rem"></i>
                                    <div class="fw-bold" style="font-size:.85rem">Local Controller</div>
                                    <div class="text-muted" style="font-size:11px">UDM / CloudKey / etc.</div>
                                </div>
                                <div id="unifi_card_cloud" data-unifi-type="cloud"
                                     class="js-unifi-select-type flex-fill text-center rounded py-3 px-2"
                                     style="cursor:pointer;border:2px solid transparent;background:transparent;transition:border-color .15s,background .15s;">
                                    <i class="fas fa-cloud d-block mb-1 text-info" style="font-size:1.3rem"></i>
                                    <div class="fw-bold" style="font-size:.85rem">Cloud Site Manager</div>
                                    <div class="text-muted" style="font-size:11px">api.ui.com</div>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="small">Name</label>
                            <input type="text" class="form-control form-control-sm" name="integration_name" id="unifi_integration_name" required
                                   placeholder="e.g. Main Office UniFi">
                        </div>

                        <div id="unifi_local_fields">
                            <div class="form-group">
                                <label class="small">Controller Host / IP</label>
                                <input type="text" class="form-control form-control-sm" name="integration_host" id="unifi_integration_host"
                                       placeholder="10.1.0.30">
                                <small class="text-muted">Hostname or IP of your UniFi OS device (UDM, CloudKey, etc.)</small>
                            </div>

                            <div class="form-group">
                                <label class="small">Port</label>
                                <input type="number" class="form-control form-control-sm" name="integration_port" id="unifi_integration_port"
                                       placeholder="443" min="1" max="65535" value="443">
                            </div>

                            <div class="form-check form-check form-switch mb-3">
                                <input type="checkbox" class="form-check-input" id="unifi_integration_verify_ssl"
                                       name="integration_verify_ssl" value="1">
                                <label class="form-check-label" for="unifi_integration_verify_ssl">Verify SSL certificate</label>
                            </div>
                        </div>

                        <div id="unifi_cloud_info" style="display:none">
                            <div class="alert alert-info py-2 mb-3">
                                <i class="fas fa-cloud me-1"></i>
                                <strong>UniFi Site Manager</strong> — connects to <code>api.ui.com</code> and syncs devices from
                                <em>all</em> sites in your account. Each UniFi site is matched to an ITFlow client by name.<br>
                                <small class="text-muted mt-1 d-block">Devices sync from the cloud API for all sites. Wi-Fi SSIDs/passwords and networks sync via each host's <code>*.id.ui.direct</code> local proxy — works for controllers on the same network as this server or with remote access enabled.</small>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="small">API Key</label>
                            <input type="password" class="form-control form-control-sm" name="integration_api_key" id="unifi_integration_api_key"
                                   autocomplete="new-password" placeholder="(leave blank to keep existing when editing)">
                            <small class="text-muted" id="unifi_api_key_help">Stored encrypted. Generate in UniFi OS &rarr; Settings &rarr; Control Plane &rarr; Integrations &rarr; API Key.</small>
                        </div>

                        <div class="form-check form-check form-switch">
                            <input type="checkbox" class="form-check-input" id="unifi_integration_enabled"
                                   name="integration_enabled" value="1" checked>
                            <label class="form-check-label" for="unifi_integration_enabled">Enabled</label>
                        </div>

                        <div id="unifi_test_result" class="mt-3"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="save_unifi_integration" class="btn btn-primary btn-sm">
                            <i class="fas fa-check me-1"></i>Save
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Cloud API Inspect Modal -->
    <div class="modal fade" id="unifi_inspectModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header py-2">
                    <h5 class="modal-title"><i class="fas fa-bug me-2"></i><span id="unifi_inspectTitle">Raw API Response</span></h5>
                    <button type="button" class="close" data-bs-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small mb-2" id="unifi_inspectDesc"></p>
                    <pre id="unifi_inspectBody" class="p-3 rounded small" style="background:var(--if-bg);border:1px solid var(--if-border-strong);max-height:500px;overflow:auto;white-space:pre-wrap;word-break:break-all;">Loading...</pre>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Site Mapping Modal -->
    <div class="modal fade" id="unifi_siteMappingModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Site &rarr; Client Mapping <span id="unifi_siteMappingIntgName" class="text-muted"></span></h5>
                    <button type="button" class="close" data-bs-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small">
                        By default, each UniFi site is matched to an ITFlow client by name (case-insensitive).
                        Use this to override that match, or skip syncing a site entirely.
                    </p>
                    <div id="unifi_siteMappingBody">
                        <div class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin me-1"></i>Loading sites...</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary btn-sm js-unifi-save-site-mappings" id="unifi_saveSiteMappingBtn" disabled>
                        <i class="fas fa-check me-1"></i>Save Mapping
                    </button>
                </div>
            </div>
        </div>
    </div>

</div><!-- /#tab-unifi -->

</div><!-- /.tab-content -->

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
const CSRF = '<?= $_SESSION['csrf_token'] ?>';

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

$('#integrationsTabs a').on('shown.bs.tab', function (e) {
    const tab = e.target.getAttribute('data-tabkey');
    const url = new URL(window.location);
    url.searchParams.set('tab', tab);
    history.replaceState(null, '', url);
});

/* ═══════════════════════════════════════════════════════════════════════════
   RMM
   ═══════════════════════════════════════════════════════════════════════════ */

const RMM_TYPE_HINTS = {
    tactical_rmm: {
        placeholder_name:    'e.g. Primary Tactical RMM',
        placeholder_api_url: 'https://api.yourdomain.com',
        help_api_url:        'API server base URL. Older installs: <code>https://api.yourdomain.com</code>. Newer (v0.18+): <code>https://api.yourdomain.com/api/v3</code>',
        label_web_url:       'Dashboard URL',
        placeholder_web_url: 'https://rmm.yourdomain.com',
        help_web_url:        'Browser dashboard URL (used for Connect button).',
        help_api_key:        'Generate in Tactical RMM → Settings → Global Settings → API Keys.',
    },
    level: {
        placeholder_name:    'e.g. Level.io RMM',
        placeholder_api_url: 'https://api.level.io',
        help_api_url:        'Level.io API server. Enter <code>https://api.level.io</code> — the <code>/v2</code> prefix is added automatically.',
        label_web_url:       'Organization ID (optional)',
        placeholder_web_url: 'your-org-id',
        help_web_url:        'Your Level.io organization slug (leave blank if unsure).',
        help_api_key:        'Generate in Level.io → Settings → API Keys.',
    },
    action1: {
        placeholder_name:    'e.g. Action1 RMM',
        placeholder_api_url: 'https://app.action1.com/api/3.0',
        help_api_url:        'Action1 API base URL. e.g. <code>https://app.action1.com/api/3.0</code>',
        label_web_url:       'Dashboard URL',
        placeholder_web_url: 'https://app.action1.com',
        help_web_url:        'Browser dashboard URL (used for Connect button).',
        label_api_key:       'Client ID',
        help_api_key:        'Generate in Action1 → Automation → API → Add API Credential.',
    },
};

function rmmUpdateModalLabels(type) {
    const h = RMM_TYPE_HINTS[type] || RMM_TYPE_HINTS.tactical_rmm;
    document.getElementById('rmm_integration_name').placeholder    = h.placeholder_name;
    document.getElementById('rmm_integration_api_url').placeholder = h.placeholder_api_url;
    document.getElementById('rmm_help_api_url').innerHTML          = h.help_api_url;
    document.getElementById('rmm_label_web_url').textContent       = h.label_web_url || 'Dashboard / Web URL';
    document.getElementById('rmm_integration_web_url').placeholder = h.placeholder_web_url;
    document.getElementById('rmm_help_web_url').innerHTML          = h.help_web_url;
    document.getElementById('rmm_label_api_key').textContent       = h.label_api_key || 'API Key';
    document.getElementById('rmm_help_api_key').innerHTML          = h.help_api_key;

    document.getElementById('rmm_lbl_tactical').className = type === 'tactical_rmm'
        ? 'btn btn-info flex-fill' : 'btn btn-outline-info flex-fill';
    document.getElementById('rmm_lbl_level').className = type === 'level'
        ? 'btn btn-primary flex-fill' : 'btn btn-outline-primary flex-fill';
    document.getElementById('rmm_lbl_action1').className = type === 'action1'
        ? 'btn btn-warning flex-fill' : 'btn btn-outline-warning flex-fill';

    document.getElementById('rmm_lbl_tactical').style.borderRadius = '4px 0 0 0';
    document.getElementById('rmm_lbl_level').style.borderRadius    = '0';
    document.getElementById('rmm_lbl_action1').style.borderRadius  = '0 4px 4px 0';

    document.getElementById('rmm_client_secret_group').style.display = type === 'action1' ? '' : 'none';
}

function rmmResetModal() {
    document.getElementById('rmm_integrationModalTitle').textContent = 'Add RMM Integration';
    document.getElementById('rmm_edit_integration_id').value = '';
    document.getElementById('rmm_integration_name').value    = '';
    document.getElementById('rmm_integration_api_url').value = '';
    document.getElementById('rmm_integration_web_url').value = '';
    document.getElementById('rmm_integration_api_key').value = '';
    document.getElementById('rmm_integration_client_secret').value = '';
    document.getElementById('rmm_integration_api_key').placeholder = '(leave blank to keep existing when editing)';
    document.getElementById('rmm_integration_enabled').checked = true;
    document.getElementById('rmm_type_tactical').checked = true;
    document.getElementById('rmm_test_result').innerHTML = '';
    rmmUpdateModalLabels('tactical_rmm');
}

function rmmEditIntegration(data) {
    document.getElementById('rmm_integrationModalTitle').textContent = 'Edit Integration';
    document.getElementById('rmm_edit_integration_id').value    = data.id;
    document.getElementById('rmm_integration_name').value       = data.name;
    document.getElementById('rmm_integration_api_url').value    = data.api_url;
    document.getElementById('rmm_integration_web_url').value    = data.web_url || '';
    document.getElementById('rmm_integration_api_key').value    = '';
    document.getElementById('rmm_integration_client_secret').value = '';
    document.getElementById('rmm_integration_api_key').placeholder = '(leave blank to keep existing)';
    document.getElementById('rmm_integration_client_secret').placeholder = '(leave blank to keep existing)';
    document.getElementById('rmm_integration_enabled').checked  = data.enabled == 1;
    document.getElementById('rmm_test_result').innerHTML = '';

    const typeIds = {level: 'rmm_type_level', action1: 'rmm_type_action1', tactical_rmm: 'rmm_type_tactical'};
    const typeRadio = document.getElementById(typeIds[data.type] || 'rmm_type_tactical');
    if (typeRadio) typeRadio.checked = true;
    rmmUpdateModalLabels(data.type || 'tactical_rmm');

    $('#rmm_addIntegrationModal').modal('show');
}

function rmmTestConnection(integrationId) {
    const btn = event.target.closest('button');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Testing...';
    fetch('/admin/post.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'csrf_token=' + CSRF + '&test_rmm_connection=1&integration_id=' + integrationId
    })
    .then(r => r.json())
    .then(d => {
        btn.disabled = false;
        if (d.success) {
            btn.innerHTML = '<i class="fas fa-check me-1"></i>Connected';
            btn.classList.replace('btn-info', 'btn-success');
        } else {
            btn.innerHTML = '<i class="fas fa-times me-1"></i>Failed';
            btn.classList.replace('btn-info', 'btn-danger');
            alert('Connection failed: ' + (d.error || 'Unknown error'));
        }
        setTimeout(() => {
            btn.innerHTML = '<i class="fas fa-plug me-1"></i>Test';
            btn.className = btn.className.replace(/btn-(success|danger)/g, 'btn-info');
            btn.disabled = false;
        }, 3000);
    })
    .catch(() => { btn.innerHTML = '<i class="fas fa-times me-1"></i>Error'; btn.disabled = false; });
}

function rmmSyncNow(integrationId) {
    const btn = event.target.closest('button');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Syncing...';
    fetch('/admin/post.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'csrf_token=' + CSRF + '&sync_rmm_now=1&integration_id=' + integrationId
    })
    .then(r => r.json())
    .then(d => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-sync me-1"></i>Sync Now';
        if (d.success) { location.reload(); } else { alert('Sync failed: ' + (d.error || 'Unknown error')); }
    })
    .catch(() => { btn.disabled = false; btn.innerHTML = '<i class="fas fa-sync me-1"></i>Sync Now'; });
}

/* ═══════════════════════════════════════════════════════════════════════════
   FIREWALLS (SOPHOS CENTRAL)
   ═══════════════════════════════════════════════════════════════════════════ */

function fwResetModal() {
    document.getElementById('fw_modalTitle').textContent = 'Add Sophos Central Connection';
    document.getElementById('fw_edit_id').value          = '';
    document.getElementById('fw_name').value             = '';
    document.getElementById('fw_api_url').value          = 'https://api.central.sophos.com';
    document.getElementById('fw_client_id').value        = '';
    document.getElementById('fw_client_secret').value    = '';
    document.getElementById('fw_client_id').placeholder  = '(required)';
    document.getElementById('fw_client_secret').placeholder = '(required)';
    document.getElementById('fw_default_client_id').value = '0';
    document.getElementById('fw_enabled').checked        = true;
    document.getElementById('fw_test_result').innerHTML  = '';
}

function fwEditIntegration(data) {
    document.getElementById('fw_modalTitle').textContent    = 'Edit Sophos Connection';
    document.getElementById('fw_edit_id').value             = data.id;
    document.getElementById('fw_name').value                = data.name;
    document.getElementById('fw_api_url').value             = data.api_url || 'https://api.central.sophos.com';
    document.getElementById('fw_client_id').value           = '';
    document.getElementById('fw_client_secret').value       = '';
    document.getElementById('fw_client_id').placeholder     = '(leave blank to keep existing)';
    document.getElementById('fw_client_secret').placeholder = '(leave blank to keep existing)';
    document.getElementById('fw_default_client_id').value   = data.default_client_id || '0';
    document.getElementById('fw_enabled').checked           = data.enabled == 1;
    document.getElementById('fw_test_result').innerHTML     = '';
    $('#fw_addModal').modal('show');
}

function fwTestConnection(integrationId) {
    const btn = event.target.closest('button');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Testing...';
    fetch('/admin/post.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'csrf_token=' + CSRF + '&test_rmm_connection=1&integration_id=' + integrationId
    })
    .then(r => r.json())
    .then(d => {
        btn.disabled = false;
        if (d.success) {
            btn.innerHTML = '<i class="fas fa-check me-1"></i>Connected';
            btn.classList.replace('btn-info', 'btn-success');
        } else {
            btn.innerHTML = '<i class="fas fa-times me-1"></i>Failed';
            btn.classList.replace('btn-info', 'btn-danger');
            alert('Connection failed: ' + (d.error || 'Unknown error'));
        }
        setTimeout(() => {
            btn.innerHTML = '<i class="fas fa-plug me-1"></i>Test';
            btn.className = btn.className.replace(/btn-(success|danger)/g, 'btn-info');
            btn.disabled = false;
        }, 3000);
    })
    .catch(err => {
        btn.innerHTML = '<i class="fas fa-times me-1"></i>Error';
        btn.disabled = false;
        alert('Connection test error: ' + err.message);
    });
}

function fwSyncNow(integrationId) {
    const btn = event.target.closest('button');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Syncing...';
    fetch('/admin/post.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'csrf_token=' + CSRF + '&sync_rmm_now=1&integration_id=' + integrationId
    })
    .then(r => {
        if (!r.ok) throw new Error('HTTP ' + r.status + ' — check server logs');
        return r.json();
    })
    .then(d => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-sync me-1"></i>Sync Now';
        if (d.success) {
            location.reload();
        } else {
            alert('Sync failed: ' + (d.error || 'Unknown error'));
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-sync me-1"></i>Sync Now';
        alert('Sync error: ' + err.message);
    });
}

/* ═══════════════════════════════════════════════════════════════════════════
   UNIFI
   ═══════════════════════════════════════════════════════════════════════════ */

const UNIFI_ALL_CLIENTS = <?= json_encode($all_unifi_clients) ?>;
let unifiSiteMappingIntegrationId = null;

function unifiSelectType(type) {
    const isCloud = type === 'cloud';
    document.getElementById('unifi_integration_type').value = type;

    // Card highlight
    const localCard = document.getElementById('unifi_card_local');
    const cloudCard = document.getElementById('unifi_card_cloud');
    localCard.style.borderColor  = !isCloud ? '#6c757d' : 'transparent';
    localCard.style.background   = !isCloud ? 'rgba(108,117,125,.15)' : 'transparent';
    cloudCard.style.borderColor  = isCloud  ? '#17a2b8' : 'transparent';
    cloudCard.style.background   = isCloud  ? 'rgba(23,162,184,.12)' : 'transparent';

    // Field visibility
    document.getElementById('unifi_local_fields').style.display = isCloud ? 'none' : '';
    document.getElementById('unifi_cloud_info').style.display   = isCloud ? '' : 'none';
    document.getElementById('unifi_integration_host').required  = !isCloud;

    document.getElementById('unifi_api_key_help').innerHTML = isCloud
        ? 'Stored encrypted. Generate at <strong>unifi.ui.com</strong> &rarr; Account &rarr; API Keys.'
        : 'Stored encrypted. Generate in UniFi OS &rarr; Settings &rarr; Control Plane &rarr; Integrations &rarr; API Key.';
}

function unifiResetModal() {
    document.getElementById('unifi_integrationModalTitle').textContent = 'Add UniFi Connection';
    document.getElementById('unifi_edit_integration_id').value = '';
    document.getElementById('unifi_integration_name').value = '';
    document.getElementById('unifi_integration_host').value = '';
    document.getElementById('unifi_integration_port').value = '443';
    document.getElementById('unifi_integration_api_key').value = '';
    document.getElementById('unifi_integration_api_key').placeholder = '(required)';
    document.getElementById('unifi_integration_verify_ssl').checked = false;
    document.getElementById('unifi_integration_enabled').checked = true;
    document.getElementById('unifi_test_result').innerHTML = '';
    unifiSelectType('local');
}

function unifiEditIntegration(data) {
    document.getElementById('unifi_integrationModalTitle').textContent = 'Edit UniFi Connection';
    document.getElementById('unifi_edit_integration_id').value = data.id;
    document.getElementById('unifi_integration_name').value = data.name;
    document.getElementById('unifi_integration_host').value = data.host || '';
    document.getElementById('unifi_integration_port').value = data.port || 443;
    document.getElementById('unifi_integration_api_key').value = '';
    document.getElementById('unifi_integration_api_key').placeholder = '(leave blank to keep existing)';
    document.getElementById('unifi_integration_verify_ssl').checked = data.verify_ssl == 1;
    document.getElementById('unifi_integration_enabled').checked = data.enabled == 1;
    document.getElementById('unifi_test_result').innerHTML = '';
    unifiSelectType(data.type || 'local');
    $('#unifi_addIntegrationModal').modal('show');
}

function unifiTestConnection(integrationId) {
    const btn = event.target.closest('button');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Testing...';
    fetch('/admin/post.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'csrf_token=' + CSRF + '&test_unifi_connection=1&integration_id=' + integrationId
    })
    .then(r => r.json())
    .then(d => {
        btn.disabled = false;
        if (d.success) {
            btn.innerHTML = '<i class="fas fa-check me-1"></i>Connected';
            btn.classList.replace('btn-info', 'btn-success');
        } else {
            btn.innerHTML = '<i class="fas fa-times me-1"></i>Failed';
            btn.classList.replace('btn-info', 'btn-danger');
            alert('Connection failed: ' + (d.error || 'Unknown error'));
        }
        setTimeout(() => {
            btn.innerHTML = '<i class="fas fa-plug me-1"></i>Test';
            btn.className = btn.className.replace(/btn-(success|danger)/g, 'btn-info');
            btn.disabled = false;
        }, 3000);
    })
    .catch(() => { btn.innerHTML = '<i class="fas fa-times me-1"></i>Error'; btn.disabled = false; });
}

function unifiSyncNow(integrationId) {
    const btn = event.target.closest('button');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Syncing...';
    fetch('/admin/post.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'csrf_token=' + CSRF + '&sync_unifi_now=1&integration_id=' + integrationId
    })
    .then(r => r.json())
    .then(d => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-sync me-1"></i>Sync Now';
        if (d.success) { location.reload(); } else { alert('Sync failed: ' + (d.error || 'Unknown error')); }
    })
    .catch(() => { btn.disabled = false; btn.innerHTML = '<i class="fas fa-sync me-1"></i>Sync Now'; });
}

function unifiOpenSiteMappings(integrationId, integrationName) {
    unifiSiteMappingIntegrationId = integrationId;
    document.getElementById('unifi_siteMappingIntgName').textContent = '- ' + integrationName;
    document.getElementById('unifi_saveSiteMappingBtn').disabled = true;
    document.getElementById('unifi_siteMappingBody').innerHTML =
        '<div class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin me-1"></i>Loading sites...</div>';

    $('#unifi_siteMappingModal').modal('show');

    fetch('/admin/post.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'csrf_token=' + CSRF + '&load_unifi_sites=1&integration_id=' + integrationId
    })
    .then(r => r.json())
    .then(d => {
        if (!d.success) {
            document.getElementById('unifi_siteMappingBody').innerHTML =
                '<div class="alert alert-danger mb-0">' + escapeHtml(d.error || 'Failed to load sites') + '</div>';
            return;
        }
        unifiRenderSiteMappings(d.sites);
        document.getElementById('unifi_saveSiteMappingBtn').disabled = false;
    })
    .catch(() => {
        document.getElementById('unifi_siteMappingBody').innerHTML =
            '<div class="alert alert-danger mb-0">Failed to load sites</div>';
    });
}

function unifiRenderSiteMappings(sites) {
    if (!sites.length) {
        document.getElementById('unifi_siteMappingBody').innerHTML =
            '<p class="text-muted text-center mb-0">No sites found on this controller.</p>';
        return;
    }

    let html = '<table class="table table-sm table-hover mb-0"><thead class="text-muted small">' +
        '<tr><th>UniFi Site</th><th>Auto-Match</th><th>Mapping</th></tr></thead><tbody>';

    sites.forEach(site => {
        const siteId  = site.unifi_site_id;
        const current = site.client_id;
        const autoName = site.auto_client_name;

        let selected = 'auto';
        if (current !== null) {
            selected = (current === '0' || current === 0) ? 'skip' : String(current);
        }

        let options = `<option value="auto" ${selected === 'auto' ? 'selected' : ''}>Auto (match by name)</option>`;
        options += `<option value="skip" ${selected === 'skip' ? 'selected' : ''}>Skip (don't sync)</option>`;
        UNIFI_ALL_CLIENTS.forEach(c => {
            options += `<option value="${c.id}" ${selected === String(c.id) ? 'selected' : ''}>${escapeHtml(c.name)}</option>`;
        });

        html += '<tr>' +
            `<td>${escapeHtml(site.unifi_site_name)}</td>` +
            `<td class="text-muted small">${autoName ? escapeHtml(autoName) : '<em>no match</em>'}</td>` +
            `<td><select class="form-control form-control-sm unifi-site-mapping-select" data-site-id="${escapeHtml(siteId)}">${options}</select></td>` +
            '</tr>';
    });

    html += '</tbody></table>';
    document.getElementById('unifi_siteMappingBody').innerHTML = html;
}

// Refresh a single controller's sites then reload page so inline table updates
function unifiRefreshSites(integrationId, btn) {
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Refreshing...';
    fetch('/admin/post.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'csrf_token=' + CSRF + '&load_unifi_sites=1&integration_id=' + integrationId
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) { location.reload(); }
        else {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-sync me-1"></i>Refresh';
            alert('Failed to load sites: ' + (d.error || 'Unknown error'));
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-sync me-1"></i>Refresh';
    });
}

// Refresh ALL controllers in sequence then reload
function unifiRefreshAllSites(btn) {
    const ids = <?= json_encode(array_keys($unifi_site_maps_by_integration) ?: []) ?>;

    // Also pick up integrations that have no sites yet
    <?php
    mysqli_data_seek($sql_unifi_integrations, 0);
    $all_intg_ids = [];
    while ($r = mysqli_fetch_assoc($sql_unifi_integrations)) $all_intg_ids[] = intval($r['id']);
    mysqli_data_seek($sql_unifi_integrations, 0);
    ?>
    const allIds = <?= json_encode($all_intg_ids) ?>;
    const toRefresh = [...new Set([...allIds, ...ids])];

    if (!toRefresh.length) { location.reload(); return; }

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Refreshing...';

    const chain = toRefresh.reduce((p, id) =>
        p.then(() => fetch('/admin/post.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'csrf_token=' + CSRF + '&load_unifi_sites=1&integration_id=' + id
        }).then(r => r.json())),
    Promise.resolve());

    chain.then(() => location.reload()).catch(() => location.reload());
}

function unifiInspectCloudSites(integrationId) {
    document.getElementById('unifi_inspectTitle').textContent = 'Raw API Response — api.ui.com/ea/hosts';
    document.getElementById('unifi_inspectDesc').textContent  = 'Raw JSON from /ea/hosts. Each object is a UDM/gateway/CloudKey in your account.';
    document.getElementById('unifi_inspectBody').textContent  = 'Loading...';
    $('#unifi_inspectModal').modal('show');
    fetch('/admin/post.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'csrf_token=' + CSRF + '&inspect_unifi_cloud_sites=1&integration_id=' + integrationId
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            document.getElementById('unifi_inspectBody').textContent =
                'HTTP ' + d.http_status + '\n\n' + JSON.stringify(d.raw, null, 2);
        } else {
            document.getElementById('unifi_inspectBody').textContent = 'Error: ' + (d.error || 'Unknown');
        }
    })
    .catch(e => { document.getElementById('unifi_inspectBody').textContent = 'Request failed: ' + e.message; });
}

function unifiInspectCloudDevices(integrationId) {
    document.getElementById('unifi_inspectTitle').textContent = 'Raw API Response — api.ui.com/ea/devices';
    document.getElementById('unifi_inspectDesc').textContent  = 'Raw JSON from /v1/devices. Response is nested: data[].devices[]. Each outer object is a host; devices[] contains the actual network gear.';
    document.getElementById('unifi_inspectBody').textContent  = 'Loading...';
    $('#unifi_inspectModal').modal('show');
    fetch('/admin/post.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'csrf_token=' + CSRF + '&inspect_unifi_cloud_devices=1&integration_id=' + integrationId + '&host_id='
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            const count = (d.raw && d.raw.data) ? d.raw.data.length : 0;
            document.getElementById('unifi_inspectBody').textContent =
                'HTTP ' + d.http_status + ' — URL: ' + d.url + '\n' +
                'Devices returned: ' + count + '\n\n' +
                JSON.stringify(d.raw, null, 2);
        } else {
            document.getElementById('unifi_inspectBody').textContent = 'Error: ' + (d.error || 'Unknown');
        }
    })
    .catch(e => { document.getElementById('unifi_inspectBody').textContent = 'Request failed: ' + e.message; });
}

function unifiSaveSiteMappings() {
    const btn = document.getElementById('unifi_saveSiteMappingBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Saving...';

    const params = new URLSearchParams();
    params.append('csrf_token', CSRF);
    params.append('save_unifi_site_mapping', '1');
    params.append('integration_id', unifiSiteMappingIntegrationId);

    document.querySelectorAll('.unifi-site-mapping-select').forEach(sel => {
        params.append('mapping[' + sel.dataset.siteId + ']', sel.value);
    });

    fetch('/admin/post.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: params.toString()
    })
    .then(r => r.json())
    .then(d => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check me-1"></i>Save Mapping';
        if (d.success) { $('#unifi_siteMappingModal').modal('hide'); }
        else { alert('Failed to save mapping: ' + (d.error || 'Unknown error')); }
    })
    .catch(() => { btn.disabled = false; btn.innerHTML = '<i class="fas fa-check me-1"></i>Save Mapping'; });
}

// Delegated wiring (CSP forbids inline onclick=/onchange= attributes): every
// button above used to call its handler directly via an inline attribute,
// silently blocked by this app's CSP - none of them did anything.
document.addEventListener('click', function (e) {
    let el;
    if ((el = e.target.closest('.js-rmm-reset-modal')))        { rmmResetModal(); return; }
    if ((el = e.target.closest('.js-rmm-test')))                { rmmTestConnection(parseInt(el.dataset.intgId, 10)); return; }
    if ((el = e.target.closest('.js-rmm-sync')))                { rmmSyncNow(parseInt(el.dataset.intgId, 10)); return; }
    if ((el = e.target.closest('.js-rmm-edit')))                { rmmEditIntegration(JSON.parse(el.dataset.intg)); return; }
    if ((el = e.target.closest('.js-fw-reset-modal')))          { fwResetModal(); return; }
    if ((el = e.target.closest('.js-fw-test')))                 { fwTestConnection(parseInt(el.dataset.intgId, 10)); return; }
    if ((el = e.target.closest('.js-fw-sync')))                 { fwSyncNow(parseInt(el.dataset.intgId, 10)); return; }
    if ((el = e.target.closest('.js-fw-edit')))                 { fwEditIntegration(JSON.parse(el.dataset.intg)); return; }
    if ((el = e.target.closest('.js-unifi-reset-modal')))       { unifiResetModal(); return; }
    if ((el = e.target.closest('.js-unifi-test')))              { unifiTestConnection(parseInt(el.dataset.intgId, 10)); return; }
    if ((el = e.target.closest('.js-unifi-sync')))              { unifiSyncNow(parseInt(el.dataset.intgId, 10)); return; }
    if ((el = e.target.closest('.js-unifi-open-sites')))        { unifiOpenSiteMappings(parseInt(el.dataset.intgId, 10), el.dataset.intgName); return; }
    if ((el = e.target.closest('.js-unifi-inspect-sites')))     { unifiInspectCloudSites(parseInt(el.dataset.intgId, 10)); return; }
    if ((el = e.target.closest('.js-unifi-inspect-devices')))   { unifiInspectCloudDevices(parseInt(el.dataset.intgId, 10)); return; }
    if ((el = e.target.closest('.js-unifi-edit')))              { unifiEditIntegration(JSON.parse(el.dataset.intg)); return; }
    if ((el = e.target.closest('.js-unifi-refresh-all-sites'))) { unifiRefreshAllSites(el); return; }
    if ((el = e.target.closest('.js-unifi-refresh-sites')))     { unifiRefreshSites(parseInt(el.dataset.intgId, 10), el); return; }
    if ((el = e.target.closest('.js-unifi-select-type')))       { unifiSelectType(el.dataset.unifiType); return; }
    if ((el = e.target.closest('.js-unifi-save-site-mappings'))){ unifiSaveSiteMappings(); return; }
});

document.addEventListener('change', function (e) {
    if (e.target.closest('.js-rmm-type-radio')) { rmmUpdateModalLabels(e.target.value); }
});
</script>

<?php require_once "../includes/footer.php"; ?>
