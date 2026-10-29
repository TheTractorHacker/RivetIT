<?php

require_once "includes/inc_all_admin.php";

use ITFlow\Integration\IntegrationAuth;
use ITFlow\Integration\IntegrationScope;
use ITFlow\Integration\ApiScopes;
use ITFlow\Integration\LegacyKeys;

$ic_scope_help = [
    'lms:read' => 'Read the published course catalog and assignments',
    'lms:assign' => 'Create (or reuse) training assignments',
    'lms:completions:read' => 'Read training completions',
    'lms:evidence:read' => 'Download completion evidence files',
    'directory:read' => 'Read the employee and department directory (names, work email, job title, Odoo ids, employment status)',
    'quality:events' => 'Send signed events to RivetIT (reserved; not served yet)',
    'sso' => 'Sign people in through RivetIT single sign-on (OpenID Connect). Not an API scope: register addresses under Single sign-on provider',
];

// A secret created or rotated by the last action is shown exactly once.
$ic_once = $_SESSION['integration_secret_once'] ?? null;
unset($_SESSION['integration_secret_once']);

$ic_tables_ok = mysqli_num_rows(mysqli_query($mysqli, "SHOW TABLES LIKE 'integration_clients'")) > 0;
$ic_clients = $ic_tables_ok ? mysqli_query($mysqli, "SELECT * FROM integration_clients ORDER BY client_status, client_name") : false;
$ic_now = date('Y-m-d H:i:s');
$ic_migrated = $ic_tables_ok && mysqli_num_rows(mysqli_query($mysqli, "SHOW TABLES LIKE 'integration_legacy_keys'")) > 0;
$ic_policy = $ic_migrated ? LegacyKeys::policy($mysqli) : null;
// client_id => legacy key usage, for the "uses the old X-Api-Key header" badge
$ic_legacy = [];
if ($ic_migrated) {
    $lres = mysqli_query($mysqli, "SELECT lk_client_id, lk_api_key_id, lk_last_used_at, lk_use_count, lk_last_ip FROM integration_legacy_keys");
    while ($lres && ($l = mysqli_fetch_assoc($lres))) {
        $ic_legacy[(int) $l['lk_client_id']] = $l;
    }
}
$ic_legacy_in_use = array_filter($ic_legacy, static fn($l) => (int) $l['lk_use_count'] > 0);

// Non-archived departments (clients.client_id) for the department scope; id => name.
$ic_departments = [];
if ($ic_tables_ok) {
    $ic_dres = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL ORDER BY client_name");
    while ($ic_dres && ($d = mysqli_fetch_assoc($ic_dres))) {
        $ic_departments[(int) $d['client_id']] = (string) $d['client_name'];
    }
}
/**
 * The department-scope fields. Nothing is pre-selected on a new client (an explicit choice is required); an existing client shows its saved choice.
 * $granted = department ids the client holds; $all = true/false for a saved client, null for the creation form.
 */
$ic_dept_fields = static function (?bool $all, array $granted, string $idPrefix) use ($ic_departments, $mysqli): string {
    $h = '<label class="form-label">Departments <span class="text-danger">*</span></label>'
        . '<div class="form-check"><input class="form-check-input" type="radio" name="dept_mode" value="all" id="' . $idPrefix . 'All"' . ($all === true ? ' checked' : '') . ' required>'
        . '<label class="form-check-label" for="' . $idPrefix . 'All">All departments (including people with no department)</label></div>'
        . '<div class="form-check"><input class="form-check-input" type="radio" name="dept_mode" value="selected" id="' . $idPrefix . 'Sel"' . ($all === false ? ' checked' : '') . ' required>'
        . '<label class="form-check-label" for="' . $idPrefix . 'Sel">Only these departments</label></div>'
        . '<select class="form-control select2 mt-1" name="departments[]" multiple data-placeholder="Select departments">';
    foreach ($ic_departments as $id => $name) {
        $h .= '<option value="' . $id . '"' . (in_array($id, $granted, true) ? ' selected' : '') . '>' . nullable_htmlentities($name) . '</option>';
    }
    // A department that was granted and has since been archived stays visible so the admin can see it and remove it (saving it again is refused).
    foreach (array_diff($granted, array_keys($ic_departments)) as $id) {
        $r = mysqli_fetch_assoc(mysqli_query($mysqli, 'SELECT client_name FROM clients WHERE client_id = ' . (int) $id));
        $h .= '<option value="' . (int) $id . '" selected>' . nullable_htmlentities($r ? $r['client_name'] . ' (archived: remove)' : 'Department #' . (int) $id . ' (removed: remove)') . '</option>';
    }
    return $h . '</select><div class="form-text">The client sees only people, assignments, completions and courses of the departments chosen here. '
        . 'Choose "All departments" or pick at least one; a client with no department sees nothing.</div>';
};

$ic_scope_boxes = static function (array $selected, string $idPrefix) use ($ic_scope_help): string {
    $h = '<div class="small fw-bold text-muted mb-1">LMS, directory and sign-in</div>';
    foreach (IntegrationAuth::SCOPES as $s) {
        $id = $idPrefix . preg_replace('/[^a-z]/', '_', $s);
        $h .= '<div class="form-check"><input class="form-check-input" type="checkbox" name="scopes[]" value="' . nullable_htmlentities($s) . '" id="' . $id . '"'
            . (in_array($s, $selected, true) ? ' checked' : '') . '><label class="form-check-label" for="' . $id . '"><code>' . nullable_htmlentities($s) . '</code></label></div>';
    }
    // General REST API: one row per resource, one box per action. Nothing is ticked by default and there is no wildcard.
    $h .= '<div class="small fw-bold text-muted mt-3 mb-1">General API (api:&lt;resource&gt;:read | write | delete)</div>'
        . '<div class="mb-1 ic-presets">'
        . '<button type="button" class="btn btn-outline-secondary btn-sm me-1" data-ic-preset="read">Read only</button>'
        . '<button type="button" class="btn btn-outline-secondary btn-sm me-1" data-ic-preset="readwrite">Read &amp; write</button>'
        . '<button type="button" class="btn btn-outline-secondary btn-sm" data-ic-preset="clear">Clear</button></div>'
        . '<div class="table-responsive"><table class="table table-sm table-borderless mb-0 ic-scope-table"><thead class="text-muted"><tr><th>Resource</th><th class="text-center">Read</th><th class="text-center">Write</th><th class="text-center">Delete</th></tr></thead><tbody>';
    foreach (ApiScopes::RESOURCES as $res => $label) {
        $h .= '<tr><td>' . nullable_htmlentities($label) . '</td>';
        foreach (ApiScopes::ACTIONS as $act) {
            $sc = ApiScopes::scope($res, $act);
            $id = $idPrefix . preg_replace('/[^a-z]/', '_', $sc);
            $h .= '<td class="text-center"><input class="form-check-input" type="checkbox" name="scopes[]" value="' . $sc . '" id="' . $id . '" data-ic-action="' . $act . '" title="' . $sc . '"'
                . (in_array($sc, $selected, true) ? ' checked' : '') . '></td>';
        }
        $h .= '</tr>';
    }
    return $h . '</tbody></table></div><div class="form-text">Person-bound routes (credentials, profile, notifications, approvals) are never available to a client.</div>';
};
/** Compact scope badges: the fixed scopes as they are, the general-API scopes grouped per resource ("tickets: read write"). */
$ic_scope_badges = static function (array $scopes) use ($ic_scope_help): string {
    $h = '';
    $byRes = [];
    foreach ($scopes as $s) {
        if (preg_match('/^api:([a-z_]+):(read|write|delete)$/D', $s, $m)) {
            $byRes[$m[1]][] = $m[2];
            continue;
        }
        $h .= '<span class="badge text-bg-secondary me-1" title="' . nullable_htmlentities($ic_scope_help[$s] ?? '') . '">' . nullable_htmlentities($s) . '</span>';
    }
    foreach ($byRes as $res => $acts) {
        $h .= '<span class="badge text-bg-dark me-1" title="api:' . nullable_htmlentities($res) . ':' . implode(' ', array_map('nullable_htmlentities', $acts)) . '">api ' . nullable_htmlentities($res) . ': ' . nullable_htmlentities(implode(' ', $acts)) . '</span>';
    }
    return $h;
};
?>

<?php if ($ic_once) { ?>
    <div class="card card-dark border-warning mb-3">
        <div class="card-header py-2">
            <h3 class="card-title mt-2"><i class="fas fa-fw fa-key me-2"></i>Client secret for <?php echo nullable_htmlentities($ic_once['name']); ?></h3>
        </div>
        <div class="card-body">
            <p class="mb-2"><strong>Copy it now. It is shown once and cannot be retrieved later</strong>; only a hash is stored. If it is lost, rotate the secret.</p>
            <div class="row g-2">
                <div class="col-md-5"><label class="form-label">Client ID</label>
                    <input type="text" class="form-control" readonly value="<?php echo nullable_htmlentities($ic_once['client_uid']); ?>" onclick="this.select()"></div>
                <div class="col-md-7"><label class="form-label">Client secret</label>
                    <input type="text" class="form-control" readonly value="<?php echo nullable_htmlentities($ic_once['secret']); ?>" onclick="this.select()" autocomplete="off"></div>
            </div>
            <p class="text-muted small mt-2 mb-0">Token endpoint: <code>POST <?php echo nullable_htmlentities(IntegrationAuth::issuer()); ?>/api/v1/oauth/token</code>
                with <code>grant_type=client_credentials</code>, <code>client_id</code> and <code>client_secret</code> (form-encoded). Access tokens last 10 minutes.</p>
        </div>
    </div>
<?php } ?>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-plug me-2"></i>Integration clients</h3>
        <div class="card-tools">
            <button type="button" class="btn btn-primary" data-bs-toggle="collapse" data-bs-target="#icAddForm"><i class="fas fa-plus me-2"></i>New client</button>
        </div>
    </div>
    <div class="card-body">
        <p class="text-muted">The one place for machine credentials: other systems, such as the QMS or your own scripts, authenticate with a Client ID and secret
            (<code>POST /api/v1/oauth/token</code>) and receive a 10 minute access token. A client holds only the scopes you tick (LMS, directory, and the general API per resource and action)
            and sees only the departments you choose; nothing is allowed by default and there is no wildcard. A client never acts as a user or an administrator.
            This page also carries the former API keys: each was converted into a client with the same permissions, departments, IP allow-list and expiry.</p>

        <?php if (!$ic_tables_ok) { ?>
            <div class="alert alert-warning mb-0">The database has not been updated for integration clients yet (needs DB 2.6.201). Take a backup, then run <strong>Administration &gt; Update &gt; Update Database</strong>.</div>
        <?php } else { ?>

        <?php if (!$ic_migrated) { ?>
            <div class="alert alert-warning">The database has not been updated for the unified API credentials yet (needs DB 2.6.204). Take a backup, then run <strong>Administration &gt; Update &gt; Update Database</strong>. Until then the old API keys cannot authenticate.</div>
        <?php } else { ?>
        <div class="border rounded p-3 mb-4 <?php echo $ic_policy['accepted'] ? 'border-warning' : ''; ?>" id="legacy-header">
            <div class="d-flex justify-content-between flex-wrap">
                <div>
                    <strong><i class="fas fa-fw fa-key me-1"></i>Old <code>X-Api-Key</code> header</strong>
                    <?php if ($ic_policy['accepted']) { ?>
                        <span class="badge text-bg-warning ms-2">Accepted (deprecated)</span>
                    <?php } else { ?>
                        <span class="badge text-bg-success ms-2">Switched off</span>
                    <?php } ?>
                    <div class="small text-muted mt-1">
                        <?php if ($ic_policy['accepted']) { ?>
                            Migrated keys still work through the <code>X-Api-Key</code> header. Every use is logged and counted below.
                            <?php if ($ic_policy['sunset']) { ?>It stops working on <strong><?php echo nullable_htmlentities($ic_policy['sunset']); ?></strong>.<?php } else { ?>No removal date is set.<?php } ?>
                        <?php } else { ?>
                            <?php echo $ic_policy['reason'] === 'sunset_reached' ? 'The removal date (' . nullable_htmlentities((string) $ic_policy['sunset']) . ') has been reached. ' : ''; ?>The header answers 401 with a message telling the caller to use its Client ID and secret.
                        <?php } ?>
                    </div>
                </div>
            </div>
            <?php if ($ic_legacy_in_use) { ?>
                <div class="alert alert-warning py-2 mt-2 mb-2 small"><strong><?php echo count($ic_legacy_in_use); ?></strong> client(s) still use the old header (listed below with a badge).
                    Give each one a secret (<em>Rotate secret</em> on its card), switch the integration to <code>POST /api/v1/oauth/token</code>, then turn the header off.</div>
            <?php } ?>
            <form action="post.php" method="post" class="row g-2 align-items-end mt-1">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">
                <div class="col-md-4"><label class="form-label small mb-0">Header</label>
                    <select class="form-select form-select-sm" name="legacy_header_enabled" id="legacy_header_enabled">
                        <option value="1"<?php echo $ic_policy['enabled'] ? ' selected' : ''; ?>>Accept (deprecated)</option>
                        <option value="0"<?php echo !$ic_policy['enabled'] ? ' selected' : ''; ?>>Switch off</option>
                    </select></div>
                <div class="col-md-4"><label class="form-label small mb-0">Removal date (optional)</label>
                    <input type="date" class="form-control form-control-sm" name="legacy_header_sunset" value="<?php echo nullable_htmlentities((string) ($ic_policy['sunset'] ?? '')); ?>"></div>
                <div class="col-md-4"><button class="btn btn-outline-primary btn-sm" name="save_legacy_header_policy" value="1"><i class="fas fa-save me-1"></i>Save</button></div>
            </form>
        </div>
        <?php } ?>

        <div class="collapse mb-4" id="icAddForm">
            <form action="post.php" method="post" autocomplete="off" class="border rounded p-3">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" maxlength="150" required placeholder="e.g. QMS (production)"></div>
                    <div class="col-md-6"><label class="form-label">Expires (optional)</label>
                        <input type="date" class="form-control" name="expires" min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>"></div>
                    <div class="col-12"><label class="form-label">Description</label>
                        <input type="text" class="form-control" name="description" maxlength="500"></div>
                    <div class="col-md-6"><label class="form-label">Scopes <span class="text-danger">*</span></label>
                        <?php echo $ic_scope_boxes([], 'icNew'); ?></div>
                    <div class="col-md-6"><label class="form-label">Allowed IPs / CIDR (optional)</label>
                        <textarea class="form-control" name="allowed_ips" rows="4" placeholder="One per line or comma separated. Blank = any address."></textarea></div>
                    <div class="col-md-6"><?php echo $ic_dept_fields(null, [], 'icNewDept'); ?></div>
                </div>
                <button class="btn btn-primary mt-3" name="add_integration_client" value="1"><i class="fas fa-check me-2"></i>Create client and show secret</button>
            </form>
        </div>

        <?php if ($ic_clients && mysqli_num_rows($ic_clients) === 0) { ?>
            <p class="text-muted mb-0">No integration clients yet.</p>
        <?php } ?>

        <?php while ($ic_clients && ($c = mysqli_fetch_assoc($ic_clients))) {
            $cid = intval($c['client_id']);
            $scopes = IntegrationAuth::parseScopes((string) $c['client_scopes']);
            $c_depts = IntegrationScope::allowedDepartmentIds($mysqli, $c);   // null = all departments
            $expired = $c['client_expires_at'] !== null && $c['client_expires_at'] < $ic_now;
            $active = $c['client_status'] === 'active' && !$expired;
            $secrets = mysqli_query($mysqli, "SELECT * FROM integration_client_secrets WHERE secret_client_id = $cid ORDER BY secret_id DESC LIMIT 10");
            ?>
            <div class="border rounded p-3 mb-3" id="client-<?php echo $cid; ?>">
                <div class="d-flex justify-content-between flex-wrap">
                    <div>
                        <strong><?php echo nullable_htmlentities($c['client_name']); ?></strong>
                        <?php if ($active) { ?><span class="badge text-bg-success ms-2">Active</span>
                        <?php } elseif ($c['client_status'] === 'revoked') { ?><span class="badge text-bg-danger ms-2">Revoked</span>
                        <?php } else { ?><span class="badge text-bg-warning ms-2">Expired</span><?php } ?>
                        <div class="text-muted small">Client ID <code><?php echo nullable_htmlentities($c['client_uid']); ?></code>
                            &middot; created <?php echo nullable_htmlentities($c['client_created_at']); ?>
                            &middot; last used <?php echo $c['client_last_used_at'] ? nullable_htmlentities($c['client_last_used_at']) : 'never'; ?>
                            <?php if ($c['client_expires_at']) { ?>&middot; expires <?php echo nullable_htmlentities($c['client_expires_at']); ?><?php } ?></div>
                        <?php if ($c['client_description']) { ?><div class="small"><?php echo nullable_htmlentities($c['client_description']); ?></div><?php } ?>
                        <?php $lk = $ic_legacy[$cid] ?? null; if ($lk) { $lk_active = $active && $ic_policy && $ic_policy['accepted']; ?>
                            <div class="mt-1"><span class="badge <?php echo (int) $lk['lk_use_count'] > 0 ? 'text-bg-warning' : 'text-bg-secondary'; ?>"><i class="fas fa-key me-1"></i>uses the old X-Api-Key header</span>
                                <span class="small text-muted">migrated from legacy API key #<?php echo intval($lk['lk_api_key_id']); ?>
                                    &middot; header last used <?php echo $lk['lk_last_used_at'] ? nullable_htmlentities($lk['lk_last_used_at']) : 'never'; ?>
                                    &middot; <?php echo intval($lk['lk_use_count']); ?> request(s)<?php echo $lk['lk_last_ip'] ? ' &middot; last from ' . nullable_htmlentities($lk['lk_last_ip']) : ''; ?>
                                    <?php if (!$lk_active && $active) { ?>&middot; <strong>header switched off: this key no longer works</strong><?php } ?></span></div>
                        <?php } ?>
                        <div class="mt-1"><?php echo $ic_scope_badges($scopes); ?>
                            <?php if (!empty($c['client_allowed_ips'])) { ?><span class="badge text-bg-info"><i class="fas fa-network-wired me-1"></i>IP restricted</span><?php } ?></div>
                        <div class="mt-1 small">Departments:
                            <?php if ($c_depts === null) { ?><span class="badge text-bg-warning">All departments</span>
                            <?php } elseif ($c_depts === []) { ?><span class="badge text-bg-danger">None (sees nothing; choose departments)</span>
                            <?php } else { foreach ($c_depts as $did) { ?><span class="badge text-bg-primary me-1"><?php echo nullable_htmlentities($ic_departments[$did] ?? ('#' . $did . ' (archived)')); ?></span><?php } } ?></div>
                    </div>
                    <?php if ($c['client_status'] === 'active') { ?>
                    <div>
                        <form action="post.php" method="post" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="client_id" value="<?php echo $cid; ?>">
                            <button class="btn btn-outline-danger btn-sm confirm-link" name="revoke_integration_client" value="1" onclick="return confirm('Revoke this client? Its tokens stop working immediately.');"><i class="fas fa-ban me-1"></i>Revoke client</button>
                        </form>
                    </div>
                    <?php } ?>
                </div>

                <?php if ($c['client_status'] === 'active') { ?>
                <details class="mt-2">
                    <summary class="small text-muted">Edit scopes, departments, IP allow-list and expiry</summary>
                    <form action="post.php" method="post" class="mt-2">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="client_id" value="<?php echo $cid; ?>">
                        <div class="row g-3">
                            <div class="col-lg-5"><?php echo $ic_scope_boxes($scopes, 'icEdit' . $cid . '_'); ?></div>
                            <div class="col-lg-4"><?php echo $ic_dept_fields($c_depts === null, $c_depts ?? [], 'icEditDept' . $cid . '_'); ?></div>
                            <div class="col-lg-3"><label class="form-label">Allowed IPs / CIDR</label>
                                <textarea class="form-control" name="allowed_ips" rows="3"><?php echo nullable_htmlentities((string) $c['client_allowed_ips']); ?></textarea>
                                <label class="form-label mt-2">Expires</label>
                                <input type="date" class="form-control" name="expires" value="<?php echo $c['client_expires_at'] ? nullable_htmlentities(substr($c['client_expires_at'], 0, 10)) : ''; ?>"></div>
                        </div>
                        <button class="btn btn-primary btn-sm mt-2" name="update_integration_client" value="1">Save changes</button>
                    </form>
                </details>
                <?php } ?>

                <div class="table-responsive mt-3">
                    <table class="table table-sm table-borderless mb-2">
                        <thead class="text-muted"><tr><th>Secret</th><th>Created</th><th>Valid until</th><th>Last used</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                        <?php while ($secrets && ($s = mysqli_fetch_assoc($secrets))) {
                            $s_live = $s['secret_status'] === 'active' && ($s['secret_expires_at'] === null || $s['secret_expires_at'] > $ic_now);
                            ?>
                            <tr>
                                <td>#<?php echo intval($s['secret_id']); ?> <span class="text-muted">&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;</span></td>
                                <td><?php echo nullable_htmlentities($s['secret_created_at']); ?></td>
                                <td><?php echo $s['secret_expires_at'] ? nullable_htmlentities($s['secret_expires_at']) : 'until revoked'; ?></td>
                                <td><?php echo $s['secret_last_used_at'] ? nullable_htmlentities($s['secret_last_used_at']) : 'never'; ?></td>
                                <td><?php echo $s_live ? '<span class="badge text-bg-success">Active</span>' : ($s['secret_status'] === 'revoked' ? '<span class="badge text-bg-danger">Revoked</span>' : '<span class="badge text-bg-secondary">Expired</span>'); ?></td>
                                <td class="text-end"><?php if ($s_live && $c['client_status'] === 'active') { ?>
                                    <form action="post.php" method="post" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">
                                        <input type="hidden" name="client_id" value="<?php echo $cid; ?>">
                                        <input type="hidden" name="secret_id" value="<?php echo intval($s['secret_id']); ?>">
                                        <button class="btn btn-outline-danger btn-sm" name="revoke_integration_secret" value="1" onclick="return confirm('Revoke this secret now?');">Revoke</button>
                                    </form><?php } ?></td>
                            </tr>
                        <?php } ?>
                        </tbody>
                    </table>
                    <?php if ($c['client_status'] === 'active') { ?>
                    <form action="post.php" method="post" class="row g-2 align-items-end">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="client_id" value="<?php echo $cid; ?>">
                        <div class="col-auto"><label class="form-label small mb-0">Rotate: keep the current secret working for</label>
                            <select class="form-select form-select-sm" name="overlap_hours">
                                <option value="1">1 hour</option><option value="24" selected>24 hours</option><option value="168">7 days</option><option value="720">30 days</option>
                            </select></div>
                        <div class="col-auto"><button class="btn btn-outline-primary btn-sm" name="rotate_integration_secret" value="1"><i class="fas fa-sync-alt me-1"></i><?php echo $secrets && mysqli_num_rows($secrets) === 0 ? 'Create secret' : 'Rotate secret'; ?></button></div>
                        <div class="col-auto small text-muted">A client holds at most two active secrets.</div>
                    </form>
                    <?php } ?>
                </div>
            </div>
        <?php } ?>
        <?php } ?>
    </div>
</div>

<script src="../js/integration_clients.js"></script>

<?php
require_once "../includes/footer.php";
