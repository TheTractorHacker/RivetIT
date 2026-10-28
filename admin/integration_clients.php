<?php

require_once "includes/inc_all_admin.php";

use ITFlow\Integration\IntegrationAuth;
use ITFlow\Integration\IntegrationScope;

$ic_scope_help = [
    'lms:read' => 'Read the published course catalog and assignments',
    'lms:assign' => 'Create (or reuse) training assignments',
    'lms:completions:read' => 'Read training completions',
    'lms:evidence:read' => 'Download completion evidence files',
    'directory:read' => 'Read the employee and department directory (reserved; not served yet)',
    'quality:events' => 'Send signed events to RivetIT (reserved; not served yet)',
];

// A secret created or rotated by the last action is shown exactly once.
$ic_once = $_SESSION['integration_secret_once'] ?? null;
unset($_SESSION['integration_secret_once']);

$ic_tables_ok = mysqli_num_rows(mysqli_query($mysqli, "SHOW TABLES LIKE 'integration_clients'")) > 0;
$ic_clients = $ic_tables_ok ? mysqli_query($mysqli, "SELECT * FROM integration_clients ORDER BY client_status, client_name") : false;
$ic_now = date('Y-m-d H:i:s');

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
    $h = '';
    foreach (IntegrationAuth::SCOPES as $s) {
        $id = $idPrefix . preg_replace('/[^a-z]/', '_', $s);
        $h .= '<div class="form-check"><input class="form-check-input" type="checkbox" name="scopes[]" value="' . nullable_htmlentities($s) . '" id="' . $id . '"'
            . (in_array($s, $selected, true) ? ' checked' : '') . '><label class="form-check-label" for="' . $id . '"><code>' . nullable_htmlentities($s) . '</code></label></div>';
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
        <p class="text-muted">Machine credentials for other systems, such as the QMS, to call the LMS API at <code>/api/v1/lms</code>. A client holds only the scopes you tick and sees only the departments you choose;
            nothing is allowed by default, and there is no wildcard. These credentials never act as a user and are not accepted on any other API route.</p>

        <?php if (!$ic_tables_ok) { ?>
            <div class="alert alert-warning mb-0">The database has not been updated for integration clients yet (needs DB 2.6.201). Take a backup, then run <strong>Administration &gt; Update &gt; Update Database</strong>.</div>
        <?php } else { ?>

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
            <div class="border rounded p-3 mb-3">
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
                        <div class="mt-1"><?php foreach ($scopes as $s) { ?><span class="badge text-bg-secondary me-1" title="<?php echo nullable_htmlentities($ic_scope_help[$s] ?? ''); ?>"><?php echo nullable_htmlentities($s); ?></span><?php } ?>
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
                            <div class="col-md-4"><?php echo $ic_scope_boxes($scopes, 'icEdit' . $cid . '_'); ?></div>
                            <div class="col-md-4"><?php echo $ic_dept_fields($c_depts === null, $c_depts ?? [], 'icEditDept' . $cid . '_'); ?></div>
                            <div class="col-md-4"><label class="form-label">Allowed IPs / CIDR</label>
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
                        <div class="col-auto"><button class="btn btn-outline-primary btn-sm" name="rotate_integration_secret" value="1"><i class="fas fa-sync-alt me-1"></i>Rotate secret</button></div>
                        <div class="col-auto small text-muted">A client holds at most two active secrets.</div>
                    </form>
                    <?php } ?>
                </div>
            </div>
        <?php } ?>
        <?php } ?>
    </div>
</div>

<?php
require_once "../includes/footer.php";
