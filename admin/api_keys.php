<?php

// Administration > API Keys: the one place for machine credentials (Client ID + secret). Formerly "Integration clients" (admin/integration_clients.php
// redirects here). Internal names (tables, classes, scope strings) still say "integration client".

require_once "includes/inc_all_admin.php";
require_once __DIR__ . '/modals/api/api_key_form_lib.php';

use ITFlow\Integration\IntegrationAuth;
use ITFlow\Integration\IntegrationScope;
use ITFlow\Integration\ApiScopes;
use ITFlow\Integration\LegacyKeys;

// A secret created or rotated by the last action is shown exactly once.
$ic_once = $_SESSION['integration_secret_once'] ?? null;
unset($_SESSION['integration_secret_once']);

$ic_tables_ok = mysqli_num_rows(mysqli_query($mysqli, "SHOW TABLES LIKE 'integration_clients'")) > 0;
$ic_clients = $ic_tables_ok ? mysqli_query($mysqli, "SELECT * FROM integration_clients ORDER BY client_status, client_name") : false;
$ic_now = date('Y-m-d H:i:s');
$ic_migrated = $ic_tables_ok && mysqli_num_rows(mysqli_query($mysqli, "SHOW TABLES LIKE 'integration_legacy_keys'")) > 0;
$ic_policy = $ic_migrated ? LegacyKeys::policy($mysqli) : null;
// client_id => legacy key usage, for the "Old X-Api-Key header" badge (tombstones of deleted keys have a negative client id and are skipped)
$ic_legacy = [];
if ($ic_migrated) {
    $lres = mysqli_query($mysqli, "SELECT lk_client_id, lk_api_key_id, lk_last_used_at, lk_use_count, lk_last_ip FROM integration_legacy_keys WHERE lk_client_id > 0");
    while ($lres && ($l = mysqli_fetch_assoc($lres))) {
        $ic_legacy[(int) $l['lk_client_id']] = $l;
    }
}
$ic_legacy_in_use = array_filter($ic_legacy, static fn($l) => (int) $l['lk_use_count'] > 0);
$ic_departments = $ic_tables_ok ? ak_departments($mysqli) : [];
$ic_total = $ic_clients ? mysqli_num_rows($ic_clients) : 0;

/** "2026-10-29 06:42:00" -> "2026-10-29" (the full value stays in the tooltip) */
$ic_day = static fn(?string $dt): string => $dt ? nullable_htmlentities(substr($dt, 0, 10)) : '';
$ic_csrf = $_SESSION['csrf_token'];
?>

<?php if ($ic_once) { ?>
    <div class="modal fade ak-secret-modal" id="akSecretModal" tabindex="-1" aria-labelledby="akSecretTitle" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-dark">
                    <h5 class="modal-title" id="akSecretTitle"><i class="fas fa-fw fa-key me-2"></i><?php echo ($ic_once['event'] ?? '') === 'created' ? 'API key created' : 'New secret created'; ?>: <?php echo nullable_htmlentities($ic_once['name']); ?></h5>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning" role="alert"><strong>Copy the secret now. It is shown once and cannot be shown again</strong>; RivetIT keeps only a hash of it. If it is lost, create a new secret.</div>
                    <label class="form-label" for="akClientIdValue">Client ID</label>
                    <div class="input-group mb-3">
                        <textarea class="form-control ak-secret-value ak-select-all" id="akClientIdValue" rows="1" readonly autocomplete="off" spellcheck="false"><?php echo nullable_htmlentities($ic_once['client_uid']); ?></textarea>
                        <button type="button" class="btn btn-outline-secondary" data-ak-copy="akClientIdValue"><i class="fas fa-fw fa-copy me-1"></i><span class="ak-copy-text">Copy</span></button>
                    </div>
                    <label class="form-label" for="akSecretValue">Client secret</label>
                    <div class="input-group mb-3">
                        <textarea class="form-control ak-secret-value ak-select-all" id="akSecretValue" rows="2" readonly autocomplete="off" spellcheck="false"><?php echo nullable_htmlentities($ic_once['secret']); ?></textarea>
                        <button type="button" class="btn btn-primary" data-ak-copy="akSecretValue"><i class="fas fa-fw fa-copy me-1"></i><span class="ak-copy-text">Copy</span></button>
                    </div>
                    <p class="text-muted small mb-0">The Client ID and secret are the username and password the other system uses. It exchanges them for a 10 minute access token:
                        <code>POST <?php echo nullable_htmlentities(IntegrationAuth::issuer()); ?>/api/v1/oauth/token</code> with <code>grant_type=client_credentials</code>, <code>client_id</code> and <code>client_secret</code> (form-encoded).</p>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-primary" data-bs-dismiss="modal">I have copied the secret</button></div>
            </div>
        </div>
    </div>
<?php } ?>
<div class="visually-hidden" id="ak-live" role="status" aria-live="polite"></div>
<link rel="stylesheet" href="/css/api_keys.css?v=<?php echo (int) @filemtime(__DIR__ . '/../css/api_keys.css'); ?>">

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-key me-2"></i>API Keys</h3>
        <div class="card-tools">
            <?php if ($ic_tables_ok) { ?><button type="button" class="btn btn-primary ajax-modal" data-modal-url="modals/api/api_key_add.php" data-modal-size="lg"><i class="fas fa-plus me-2"></i>Add API key</button><?php } ?>
        </div>
    </div>
    <div class="card-body">
        <p class="text-muted">API keys let other systems, such as the QMS or your own scripts, connect to RivetIT. Each key has a <strong>Client ID</strong> and a <strong>secret</strong>: they are the username and password
            the other system uses to get a short-lived access token (<code>POST /api/v1/oauth/token</code>). A key can do only what you tick, and sees only the departments you choose. It never acts as a person or an administrator.</p>

        <?php if (!$ic_tables_ok) { ?>
            <div class="alert alert-warning mb-0">The database has not been updated for API keys yet (needs DB 2.6.201). Take a backup, then run <strong>Administration &gt; Update &gt; Update Database</strong>.</div>
        <?php } else { ?>

        <?php if (!$ic_migrated) { ?>
            <div class="alert alert-warning">The database has not been updated for the unified API credentials yet (needs DB 2.6.204). Take a backup, then run <strong>Administration &gt; Update &gt; Update Database</strong>. Until then keys that came from the old X-Api-Key header cannot authenticate.</div>
        <?php } elseif ($ic_legacy) { ?>
        <div class="alert <?php echo $ic_policy['accepted'] ? 'alert-warning' : 'alert-info'; ?> mb-3" id="legacy-header" role="region" aria-label="Old X-Api-Key header">
            <div class="d-flex flex-wrap gap-3 align-items-start justify-content-between">
                <div class="flex-grow-1">
                    <strong><i class="fas fa-fw fa-exclamation-triangle me-1"></i>The old <code>X-Api-Key</code> header is <?php echo $ic_policy['accepted'] ? 'deprecated' : 'switched off'; ?>.</strong>
                    <?php if ($ic_policy['accepted']) { ?>
                        Keys that came from the old API keys still work through it for now
                        <?php if ($ic_policy['sunset']) { ?>until <strong><?php echo nullable_htmlentities($ic_policy['sunset']); ?></strong><?php } else { ?>(no removal date is set)<?php } ?>.
                        <?php if ($ic_legacy_in_use) { ?><strong><?php echo count($ic_legacy_in_use); ?></strong> of them used it recently.<?php } ?>
                        To switch a system over: open the key, choose <em>Create secret</em>, then have the system use its Client ID and secret instead.
                    <?php } else { ?>
                        <?php echo $ic_policy['reason'] === 'sunset_reached' ? 'The removal date (' . nullable_htmlentities((string) $ic_policy['sunset']) . ') has been reached. ' : ''; ?>The header now answers 401 and tells the caller to use its Client ID and secret.
                    <?php } ?>
                </div>
                <form action="post.php" method="post" class="d-flex flex-wrap gap-2 align-items-end">
                    <input type="hidden" name="csrf_token" value="<?php echo $ic_csrf; ?>">
                    <div><label class="form-label small mb-0" for="legacy_header_enabled">Header</label>
                        <select class="form-select form-select-sm" name="legacy_header_enabled" id="legacy_header_enabled">
                            <option value="1"<?php echo $ic_policy['enabled'] ? ' selected' : ''; ?>>Accept (deprecated)</option>
                            <option value="0"<?php echo !$ic_policy['enabled'] ? ' selected' : ''; ?>>Switch off</option>
                        </select></div>
                    <div><label class="form-label small mb-0" for="legacy_header_sunset">Removal date (optional)</label>
                        <input type="date" class="form-control form-control-sm" id="legacy_header_sunset" name="legacy_header_sunset" value="<?php echo nullable_htmlentities((string) ($ic_policy['sunset'] ?? '')); ?>"></div>
                    <button class="btn btn-outline-primary btn-sm" name="save_legacy_header_policy" value="1"><i class="fas fa-save me-1"></i>Save</button>
                </form>
            </div>
        </div>
        <?php } ?>

        <?php if ($ic_total === 0) { ?>
            <p class="text-muted mb-0">No API keys yet. Use <strong>Add API key</strong> to create one.</p>
        <?php } else { ?>
        <div class="table-responsive">
            <table class="table table-hover ak-table mb-0">
                <thead class="text-dark">
                <tr>
                    <th scope="col" class="ak-toggle"><span class="visually-hidden">Details</span></th>
                    <th scope="col">Name</th>
                    <th scope="col" class="d-none d-xl-table-cell">Client ID</th>
                    <th scope="col" class="d-none d-sm-table-cell">Access</th>
                    <th scope="col" class="d-none d-xxl-table-cell">Departments</th>
                    <th scope="col" class="ak-wide">Last used</th>
                    <th scope="col" class="ak-wide">Expires</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="text-end">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php while ($ic_clients && ($c = mysqli_fetch_assoc($ic_clients))) {
                    $cid = intval($c['client_id']);
                    $name = nullable_htmlentities($c['client_name']);
                    $uid = nullable_htmlentities($c['client_uid']);
                    $scopes = IntegrationAuth::parseScopes((string) $c['client_scopes']);
                    $sum = ApiScopes::summary($scopes);
                    $c_depts = IntegrationScope::allowedDepartmentIds($mysqli, $c);   // null = all departments
                    $dept_names = $c_depts === null ? [] : array_map(static fn($d) => $ic_departments[$d] ?? ('#' . $d . ' (archived)'), $c_depts);
                    $expired = $c['client_expires_at'] !== null && $c['client_expires_at'] < $ic_now;
                    $is_active = $c['client_status'] === 'active';
                    $live = $is_active && !$expired;
                    $secrets = mysqli_query($mysqli, "SELECT * FROM integration_client_secrets WHERE secret_client_id = $cid ORDER BY secret_id DESC LIMIT 10");
                    $n_secrets = $secrets ? mysqli_num_rows($secrets) : 0;
                    $lk = $ic_legacy[$cid] ?? null;
                    $status = $live ? '<span class="badge text-bg-success">Active</span>' : ($c['client_status'] === 'revoked' ? '<span class="badge text-bg-danger">Revoked</span>' : '<span class="badge text-bg-warning">Expired</span>');
                    $access_title = nullable_htmlentities($sum['text']);
                    $extra = $sum['extras'] ? '<div class="ak-sub">' . nullable_htmlentities(implode(', ', array_slice($sum['extras'], 0, 2))) . (count($sum['extras']) > 2 ? ' +' . (count($sum['extras']) - 2) : '') . '</div>' : '';
                    ?>
                    <tr id="client-<?php echo $cid; ?>">
                        <td class="ak-toggle"><button type="button" class="btn btn-sm btn-ghost-secondary ak-toggle" data-ak-toggle aria-expanded="false" aria-controls="ak-details-<?php echo $cid; ?>"
                                aria-label="Show details of <?php echo $name; ?>"><i class="fas fa-fw fa-chevron-right"></i></button></td>
                        <td class="ak-namecell">
                            <div class="ak-name"><?php echo $name; ?></div>
                            <div class="ak-sub d-sm-none"><?php echo nullable_htmlentities($sum['headline']); ?></div>
                            <?php if ($lk) { ?><span class="badge text-bg-secondary fw-normal" title="Migrated from old API key #<?php echo intval($lk['lk_api_key_id']); ?>. Header last used: <?php echo $lk['lk_last_used_at'] ? nullable_htmlentities($lk['lk_last_used_at']) : 'never'; ?>; <?php echo intval($lk['lk_use_count']); ?> request(s)<?php echo $lk['lk_last_ip'] ? '; last from ' . nullable_htmlentities($lk['lk_last_ip']) : ''; ?>">Old X-Api-Key header</span><?php } ?>
                            <?php if (!empty($c['client_allowed_ips'])) { ?><span class="badge text-bg-secondary fw-normal" title="Only the listed IP addresses may use this key">IP restricted</span><?php } ?>
                        </td>
                        <td class="d-none d-xl-table-cell text-nowrap"><span class="ak-id" id="ak-uid-<?php echo $cid; ?>"><?php echo $uid; ?></span>
                            <button type="button" class="btn btn-sm btn-ghost-secondary" data-ak-copy="ak-uid-<?php echo $cid; ?>" title="Copy Client ID" aria-label="Copy Client ID of <?php echo $name; ?>"><i class="fas fa-fw fa-copy"></i><span class="visually-hidden ak-copy-text">Copy</span></button></td>
                        <td class="text-nowrap d-none d-sm-table-cell"><span class="ak-access" title="<?php echo $access_title; ?>"><?php echo nullable_htmlentities($sum['headline']); ?></span><?php echo $extra; ?></td>
                        <td class="d-none d-xxl-table-cell"><?php
                            if ($c_depts === null) { echo '<span class="text-muted">All departments</span>'; }
                            elseif ($c_depts === []) { echo '<span class="text-danger" title="This key sees nothing until departments are chosen">None</span>'; }
                            else { echo '<span title="' . nullable_htmlentities(implode(', ', $dept_names)) . '">' . nullable_htmlentities(implode(', ', array_slice($dept_names, 0, 2))) . '</span>'
                                . (count($dept_names) > 2 ? ' <span class="badge text-bg-secondary fw-normal">+' . (count($dept_names) - 2) . '</span>' : ''); }
                            ?></td>
                        <td class="ak-wide text-nowrap"><?php echo $c['client_last_used_at'] ? '<span title="' . nullable_htmlentities($c['client_last_used_at']) . '">' . $ic_day($c['client_last_used_at']) . '</span>' : '<span class="text-muted">never</span>'; ?></td>
                        <td class="ak-wide text-nowrap"><?php echo $c['client_expires_at'] ? '<span class="' . ($expired ? 'text-danger' : '') . '" title="' . nullable_htmlentities($c['client_expires_at']) . '">' . $ic_day($c['client_expires_at']) . '</span>' : '<span class="text-muted">never</span>'; ?></td>
                        <td class="text-nowrap"><?php echo $status; ?></td>
                        <td class="text-end text-nowrap ak-actions">
                            <?php if ($is_active) { ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary ajax-modal d-none d-sm-inline-block" data-modal-url="modals/api/api_key_edit.php?id=<?php echo $cid; ?>" data-modal-size="lg"><i class="fas fa-fw fa-pen me-1"></i>Edit</button>
                            <?php } ?>
                            <div class="dropdown d-inline-block">
                                <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="More actions for <?php echo $name; ?>"><i class="fas fa-ellipsis-h"></i></button>
                                <div class="dropdown-menu dropdown-menu-end">
                                    <?php if ($is_active) { ?>
                                        <button type="button" class="dropdown-item ajax-modal d-sm-none" data-modal-url="modals/api/api_key_edit.php?id=<?php echo $cid; ?>" data-modal-size="lg"><i class="fas fa-fw fa-pen me-2"></i>Edit key</button>
                                        <button type="button" class="dropdown-item" data-ak-confirm="revoke" data-id="<?php echo $cid; ?>" data-name="<?php echo $name; ?>"><i class="fas fa-fw fa-ban me-2"></i>Revoke key</button>
                                    <?php } ?>
                                    <button type="button" class="dropdown-item text-danger text-bold" data-ak-confirm="delete" data-id="<?php echo $cid; ?>" data-name="<?php echo $name; ?>"><i class="fas fa-fw fa-trash me-2"></i>Delete key</button>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <tr class="ak-details-row" id="ak-details-<?php echo $cid; ?>" hidden>
                        <td colspan="9">
                            <div class="ak-details-grid">
                                <section aria-label="Details of <?php echo $name; ?>">
                                    <h4>Details</h4>
                                    <dl class="ak-dl">
                                        <dt>Client ID</dt><dd><span class="ak-id" id="ak-uid2-<?php echo $cid; ?>"><?php echo $uid; ?></span> <button type="button" class="btn btn-sm btn-outline-secondary py-0" data-ak-copy="ak-uid2-<?php echo $cid; ?>" aria-label="Copy Client ID of <?php echo $name; ?>"><i class="fas fa-fw fa-copy me-1"></i><span class="ak-copy-text">Copy</span></button></dd>
                                        <dt>Created</dt><dd><?php echo nullable_htmlentities($c['client_created_at']); ?></dd>
                                        <dt>Last used</dt><dd><?php echo $c['client_last_used_at'] ? nullable_htmlentities($c['client_last_used_at']) : 'never'; ?></dd>
                                        <dt>Expires</dt><dd><?php echo $c['client_expires_at'] ? nullable_htmlentities($c['client_expires_at']) : 'never'; ?></dd>
                                        <dt>Departments</dt><dd><?php echo $c_depts === null ? 'All departments' : ($c_depts === [] ? '<span class="text-danger">None (sees nothing; choose departments)</span>' : nullable_htmlentities(implode(', ', $dept_names))); ?></dd>
                                        <dt>IP addresses</dt><dd><?php echo !empty($c['client_allowed_ips']) ? nullable_htmlentities(str_replace("\n", ', ', (string) $c['client_allowed_ips'])) : 'any'; ?></dd>
                                        <?php if ($c['client_description']) { ?><dt>Notes</dt><dd><?php echo nullable_htmlentities($c['client_description']); ?></dd><?php } ?>
                                        <?php if ($lk) { $lk_on = $live && $ic_policy && $ic_policy['accepted']; ?>
                                            <dt>Old header</dt><dd>Converted from old API key #<?php echo intval($lk['lk_api_key_id']); ?>; header last used <?php echo $lk['lk_last_used_at'] ? nullable_htmlentities($lk['lk_last_used_at']) : 'never'; ?>, <?php echo intval($lk['lk_use_count']); ?> request(s)<?php echo $lk['lk_last_ip'] ? ', last from ' . nullable_htmlentities($lk['lk_last_ip']) : ''; ?>.<?php if (!$lk_on && $live) { ?> <strong>The header is switched off, so the old key no longer works.</strong><?php } ?></dd>
                                        <?php } ?>
                                    </dl>
                                </section>
                                <section aria-label="Access of <?php echo $name; ?>">
                                    <h4>Access (R read, W write, D delete)</h4>
                                    <?php if ($sum['groups']) { ?>
                                        <div class="ak-areas">
                                            <?php foreach ($sum['groups'] as $g) { ?>
                                                <div class="ak-area"><span><?php echo nullable_htmlentities(trim(preg_replace('/\s*\(.*$/', '', $g['label']))); ?></span>
                                                    <span class="ak-marks" title="<?php echo nullable_htmlentities('api:' . $g['resource']); ?>"><?php foreach (['R' => $g['read'], 'W' => $g['write'], 'D' => $g['delete']] as $m => $on) { echo '<span class="' . ($on ? 'on' : 'off') . '">' . $m . '</span>'; } ?></span></div>
                                            <?php } ?>
                                        </div>
                                    <?php } else { ?><div class="text-muted small">No general API access.</div><?php } ?>
                                    <?php foreach ($sum['extras'] as $ex) { ?><div class="small mt-1"><?php echo nullable_htmlentities($ex); ?></div><?php } ?>
                                </section>
                                <section aria-label="Secrets of <?php echo $name; ?>">
                                    <h4>Secrets</h4>
                                    <?php if ($n_secrets === 0) { ?>
                                        <div class="text-muted small mb-2"><?php echo $lk ? 'No secret yet: this key still uses the old header. Create a secret to switch it over.' : 'No secret yet.'; ?></div>
                                    <?php } else { ?>
                                        <ul class="list-unstyled small mb-2">
                                        <?php while ($secrets && ($s = mysqli_fetch_assoc($secrets))) {
                                            $s_live = $s['secret_status'] === 'active' && ($s['secret_expires_at'] === null || $s['secret_expires_at'] > $ic_now);
                                            ?>
                                            <li class="d-flex flex-wrap justify-content-between gap-2 py-1 border-bottom">
                                                <span><strong>Secret #<?php echo intval($s['secret_id']); ?></strong>
                                                    <?php echo $s_live ? '<span class="badge text-bg-success">Active</span>' : ($s['secret_status'] === 'revoked' ? '<span class="badge text-bg-danger">Revoked</span>' : '<span class="badge text-bg-secondary">Expired</span>'); ?><br>
                                                    <span class="text-muted">created <?php echo $ic_day($s['secret_created_at']); ?>; valid until <?php echo $s['secret_expires_at'] ? nullable_htmlentities($s['secret_expires_at']) : 'revoked'; ?>; last used <?php echo $s['secret_last_used_at'] ? nullable_htmlentities($s['secret_last_used_at']) : 'never'; ?></span></span>
                                                <?php if ($s_live && $is_active) { ?><button type="button" class="btn btn-sm btn-outline-danger align-self-start" data-ak-confirm="secret" data-id="<?php echo $cid; ?>" data-secret="<?php echo intval($s['secret_id']); ?>" data-name="secret #<?php echo intval($s['secret_id']); ?> of <?php echo $name; ?>">Revoke</button><?php } ?>
                                            </li>
                                        <?php } ?>
                                        </ul>
                                    <?php } ?>
                                    <?php if ($is_active) { ?>
                                    <form action="post.php" method="post" class="d-flex flex-wrap gap-2 align-items-end">
                                        <input type="hidden" name="csrf_token" value="<?php echo $ic_csrf; ?>">
                                        <input type="hidden" name="client_id" value="<?php echo $cid; ?>">
                                        <?php if ($n_secrets > 0) { ?>
                                        <div><label class="form-label small mb-0" for="ak-overlap-<?php echo $cid; ?>">Keep the current secret working for</label>
                                            <select class="form-select form-select-sm" id="ak-overlap-<?php echo $cid; ?>" name="overlap_hours">
                                                <option value="1">1 hour</option><option value="24" selected>24 hours</option><option value="168">7 days</option><option value="720">30 days</option>
                                            </select></div>
                                        <?php } else { ?><input type="hidden" name="overlap_hours" value="24"><?php } ?>
                                        <button class="btn btn-outline-primary btn-sm" name="rotate_integration_secret" value="1"><i class="fas fa-sync-alt me-1"></i><?php echo $n_secrets === 0 ? 'Create secret' : 'Rotate secret'; ?></button>
                                    </form>
                                    <div class="small text-muted mt-1">A key holds at most two active secrets. The new secret is shown once.</div>
                                    <?php } ?>
                                </section>
                            </div>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
        <?php } ?>
        <?php } ?>
    </div>
</div>

<div class="modal fade" id="akConfirm" tabindex="-1" aria-labelledby="akConfirmTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" action="post.php" method="post">
            <input type="hidden" name="csrf_token" value="<?php echo $ic_csrf; ?>">
            <input type="hidden" name="client_id" value="">
            <input type="hidden" name="secret_id" value="">
            <div class="modal-header bg-dark"><h5 class="modal-title ak-confirm-title" id="akConfirmTitle">Confirm</h5>
                <button type="button" class="close text-white" data-bs-dismiss="modal" aria-label="Close"><span>&times;</span></button></div>
            <div class="modal-body"><p class="fw-bold ak-confirm-name"></p><p class="mb-0 ak-confirm-body"></p></div>
            <div class="modal-footer"><button type="submit" class="btn btn-danger ak-confirm-go" name="">Confirm</button><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button></div>
        </form>
    </div>
</div>

<script src="../js/api_keys.js?v=<?php echo (int) @filemtime(__DIR__ . '/../js/api_keys.js'); ?>"></script>

<?php
require_once "../includes/footer.php";
