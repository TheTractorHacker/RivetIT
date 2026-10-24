<?php
/*
 * RivetPanel deploy page (per client): the installation-wide on/off switch (default off), mint a device-enrollment token
 * (optionally tied to one contact, or a capped multi-use "fleet" token for mass deployment, rc.38), show the plaintext token
 * ONCE with ready-to-copy install commands, list/revoke outstanding tokens and enrolled panel devices, and the per-client
 * "allow tickets from client-level devices" switch (default off). Modeled on agent/rmm_add_device.php's role in the RMM module.
 *
 * Every write (post below) re-derives client_id from the token/device ROW being acted on, never from a posted value, before calling
 * enforceClientAccess() - the same IDOR lesson already applied elsewhere in this app.
 */

require_once 'includes/inc_all_client.php';   // sets $client_id, enforces module_client + enforceClientAccess()
require_once dirname(__DIR__) . '/includes/panel_bootstrap.php';

enforceUserPermission('module_support', 1);

$panel = rivetPanelModule($mysqli);
$pd_enabled = rivetPanelEnabled($mysqli);
$pd_tokens = $panel->admin()->tokens($client_id);
$pd_devices = $panel->admin()->devices($client_id);
$pd_allow_client_level = $panel->settings()->allowClientLevelTickets($client_id);

// PanelAdmin::tokens() does not return max_uses; fetched separately to show "used X of N" for fleet tokens.
$pd_max_uses_by_token = [];
$res = mysqli_query($mysqli, "SELECT token_id, max_uses FROM endpoint_agent_enrollment_tokens WHERE kind = 'panel' AND client_id = $client_id");
while ($row = mysqli_fetch_assoc($res)) {
    $pd_max_uses_by_token[(int) $row['token_id']] = (int) $row['max_uses'];
}

$pd_contacts = [];
$res = mysqli_query($mysqli, "SELECT contact_id, contact_name FROM contacts WHERE contact_client_id = $client_id AND contact_archived_at IS NULL ORDER BY contact_name ASC");
while ($row = mysqli_fetch_assoc($res)) {
    $pd_contacts[] = $row;
}

// The freshly-minted plaintext token, shown exactly once (flash session value, never stored, never logged).
$pd_new_token = $_SESSION['panel_new_token'] ?? null;
$pd_new_token_max_uses = (int) ($_SESSION['panel_new_token_max_uses'] ?? 1);
unset($_SESSION['panel_new_token'], $_SESSION['panel_new_token_max_uses']);
$pd_base_url = rtrim((string) ($GLOBALS['config_base_url'] ?? ''), '/');
$pd_release_url = 'https://github.com/TheTractorHacker/rivetpanel/releases/latest';
$pd_installer_url = $pd_release_url . '/download/RivetPanelSetup.exe';
?>

<div class="card card-dark mb-3">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-comment-dots me-2"></i>RivetPanel</h3>
    </div>
    <div class="card-body">
        <p class="text-muted mb-0">Lets a person at this department open a ticket and chat with a technician from a desktop helper, authenticated with its own per-device token (never the shared API key).</p>
    </div>
</div>

<div class="alert <?= $pd_enabled ? 'alert-success' : 'alert-warning' ?> d-flex align-items-center justify-content-between">
    <div>
        <strong>RivetPanel service: <?= $pd_enabled ? 'ON' : 'OFF' ?></strong> for this whole installation (every client).
        <?php if (!$pd_enabled) { ?> Off by default - tokens and devices below do nothing until this is switched on.<?php } ?>
    </div>
    <form method="post" action="post/panel_deploy.php" class="mb-0">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <input type="hidden" name="client_id" value="<?= (int) $client_id ?>">
        <input type="hidden" name="panel_enabled" value="<?= $pd_enabled ? 0 : 1 ?>">
        <button type="submit" name="set_panel_enabled" class="btn btn-sm <?= $pd_enabled ? 'btn-outline-danger' : 'btn-outline-success' ?>">
            <?= $pd_enabled ? 'Turn off' : 'Turn on' ?>
        </button>
    </form>
</div>

<?php if ($pd_new_token !== null) { ?>
<div class="alert alert-success">
    <h4 class="alert-title">Enrollment token created<?= $pd_new_token_max_uses > 1 ? ' (fleet token, up to ' . $pd_new_token_max_uses . ' machines)' : '' ?></h4>
    <p>Give this to the person installing RivetPanel. It is shown only once and cannot be retrieved again - if it is lost, revoke it and mint a new one.</p>
    <code class="d-block p-2 bg-dark text-white" style="word-break:break-all;"><?= nullable_htmlentities($pd_new_token) ?></code>
    <hr>
    <p class="mb-1"><strong>Ready-to-copy install commands</strong> (<a href="<?= nullable_htmlentities($pd_release_url) ?>" target="_blank" rel="noopener">latest signed RivetPanel release</a>):</p>
    <p class="mb-1 text-muted small mb-0">Windows (silent installer):</p>
    <code class="d-block p-2 bg-dark text-white mb-2" style="word-break:break-all;">RivetPanelSetup.exe /VERYSILENT /EnrollmentToken=<?= nullable_htmlentities($pd_new_token) ?></code>
    <p class="mb-1 text-muted small mb-0">Windows (TacticalRMM deploy_rivetpanel.ps1, mass deployment):</p>
    <code class="d-block p-2 bg-dark text-white mb-2" style="word-break:break-all;">.\deploy_rivetpanel.ps1 -InstallerUrl "<?= nullable_htmlentities($pd_installer_url) ?>" -RivetBaseUrl "<?= nullable_htmlentities($pd_base_url) ?>" -EnrollmentToken "<?= nullable_htmlentities($pd_new_token) ?>"</code>
    <p class="mb-1 text-muted small mb-0">Linux / macOS (install.sh):</p>
    <code class="d-block p-2 bg-dark text-white mb-0" style="word-break:break-all;">./install.sh <?= nullable_htmlentities($pd_base_url) ?> <?= nullable_htmlentities($pd_new_token) ?></code>
</div>
<?php } ?>

            <div class="row row-cards">
                <div class="col-md-5">
                    <div class="card mb-3">
                        <div class="card-header"><h3 class="card-title">Allow client-level devices to open tickets</h3></div>
                        <div class="card-body">
                            <p class="text-muted">Off by default. A panel enrolled WITHOUT a specific contact can always read categories; this switch decides whether it may also create and read tickets it created itself.</p>
                            <form method="post" action="post/panel_deploy.php">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                <input type="hidden" name="client_id" value="<?= (int) $client_id ?>">
                                <input type="hidden" name="allow_client_level" value="<?= $pd_allow_client_level ? 0 : 1 ?>">
                                <button type="submit" name="set_client_level" class="btn <?= $pd_allow_client_level ? 'btn-outline-danger' : 'btn-outline-success' ?>">
                                    <?= $pd_allow_client_level ? 'Turn off' : 'Turn on' ?>
                                </button>
                                <span class="ms-2 badge <?= $pd_allow_client_level ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= $pd_allow_client_level ? 'Currently ON' : 'Currently OFF' ?></span>
                            </form>
                        </div>
                    </div>

                    <div class="card mb-3">
                        <div class="card-header"><h3 class="card-title">New enrollment token</h3></div>
                        <div class="card-body">
                            <form method="post" action="post/panel_deploy.php" id="pd_create_token_form">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                <input type="hidden" name="client_id" value="<?= (int) $client_id ?>">
                                <div class="mb-3">
                                    <label class="form-label">Number of PCs</label>
                                    <input type="number" name="max_uses" id="pd_max_uses" class="form-control" value="1" min="1" max="500">
                                    <div class="form-text">1 = single-use token (one machine). More than 1 mints a "fleet" token (up to 500) for an RMM push of one installer to many PCs - it must be client-level and expires in at most 72 hours.</div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Contact (optional - leave blank for a client-level device)</label>
                                    <select name="contact_id" id="pd_contact_id" class="form-select">
                                        <option value="">None (client-level)</option>
                                        <?php foreach ($pd_contacts as $c) { ?>
                                            <option value="<?= (int) $c['contact_id'] ?>"><?= nullable_htmlentities($c['contact_name']) ?></option>
                                        <?php } ?>
                                    </select>
                                    <div class="form-text" id="pd_contact_fleet_note" style="display:none;">Cleared: a fleet token (more than one PC) cannot be tied to one person.</div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Label</label>
                                    <input type="text" name="label" class="form-control" maxlength="100" placeholder="e.g. Jane's laptop">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Expires in (hours, max 72)</label>
                                    <input type="number" name="ttl_hours" class="form-control" value="24" min="1" max="72">
                                </div>
                                <button type="submit" name="create_token" class="btn btn-primary">Create token</button>
                            </form>
                            <script>
                            (function () {
                                var maxUses = document.getElementById('pd_max_uses');
                                var contact = document.getElementById('pd_contact_id');
                                var note = document.getElementById('pd_contact_fleet_note');
                                function sync() {
                                    var fleet = parseInt(maxUses.value, 10) > 1;
                                    contact.disabled = fleet;
                                    note.style.display = fleet ? '' : 'none';
                                    if (fleet) { contact.value = ''; }
                                }
                                maxUses.addEventListener('input', sync);
                                sync();
                            })();
                            </script>
                        </div>
                    </div>
                </div>

                <div class="col-md-7">
                    <div class="card mb-3">
                        <div class="card-header"><h3 class="card-title">Outstanding tokens</h3></div>
                        <div class="table-responsive">
                            <table class="table table-sm table-vcenter mb-0">
                                <thead><tr><th>Label</th><th>Contact</th><th>Expires</th><th>Used</th><th>Status</th><th></th></tr></thead>
                                <tbody>
                                <?php foreach ($pd_tokens as $t) {
                                    $t_contact = null;
                                    foreach ($pd_contacts as $c) { if ((int) $c['contact_id'] === $t['contact_id']) { $t_contact = $c['contact_name']; } }
                                    ?>
                                    <tr>
                                        <td><?= nullable_htmlentities($t['label'] !== '' ? $t['label'] : '-') ?></td>
                                        <td><?= $t['contact_id'] !== null ? nullable_htmlentities($t_contact ?? ('#' . $t['contact_id'])) : '<span class="text-muted">Client-level</span>' ?></td>
                                        <?php $t_max = $pd_max_uses_by_token[$t['token_id']] ?? 1; ?>
                                        <td><?= nullable_htmlentities($t['expires_at']) ?></td>
                                        <td><?= (int) $t['use_count'] ?> / <?= $t_max ?><?= $t_max > 1 ? ' <span class="badge text-bg-info">fleet</span>' : '' ?></td>
                                        <td><?= $t['revoked_at'] !== null ? '<span class="badge text-bg-secondary">Revoked</span>' : ($t['use_count'] >= $t_max ? '<span class="badge text-bg-success">Used</span>' : ($t['use_count'] > 0 ? '<span class="badge text-bg-info">In use</span>' : '<span class="badge text-bg-warning">Pending</span>')) ?></td>
                                        <td>
                                            <?php if ($t['revoked_at'] === null && $t['use_count'] < $t_max) { ?>
                                            <a class="btn btn-sm btn-outline-danger confirm-link" href="post/panel_deploy.php?revoke_token=<?= (int) $t['token_id'] ?>&client_id=<?= (int) $client_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">Revoke</a>
                                            <?php } ?>
                                        </td>
                                    </tr>
                                <?php } if ($pd_tokens === []) { ?>
                                    <tr><td colspan="6" class="text-muted">No tokens yet.</td></tr>
                                <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="card mb-3">
                        <div class="card-header"><h3 class="card-title">Enrolled panel devices</h3></div>
                        <div class="table-responsive">
                            <table class="table table-sm table-vcenter mb-0">
                                <thead><tr><th>Hostname</th><th>OS</th><th>Contact</th><th>First seen</th><th>Tickets</th><th>Status</th><th></th></tr></thead>
                                <tbody>
                                <?php foreach ($pd_devices as $d) {
                                    $d_contact = null;
                                    foreach ($pd_contacts as $c) { if ((int) $c['contact_id'] === $d['contact_id']) { $d_contact = $c['contact_name']; } }
                                    ?>
                                    <tr>
                                        <td><?= nullable_htmlentities($d['hostname']) ?></td>
                                        <td><?= nullable_htmlentities($d['os']) ?></td>
                                        <td><?= $d['contact_id'] !== null ? nullable_htmlentities($d_contact ?? ('#' . $d['contact_id'])) : '<span class="text-muted">Client-level</span>' ?></td>
                                        <td><?= nullable_htmlentities($d['first_seen_at']) ?></td>
                                        <td><?= (int) $d['tickets'] ?></td>
                                        <td><?= $d['revoked_at'] !== null ? '<span class="badge text-bg-secondary">Revoked</span>' : '<span class="badge text-bg-success">Active</span>' ?></td>
                                        <td>
                                            <?php if ($d['revoked_at'] === null) { ?>
                                            <a class="btn btn-sm btn-outline-danger confirm-link" href="post/panel_deploy.php?revoke_device=<?= (int) $d['device_id'] ?>&client_id=<?= (int) $client_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">Revoke</a>
                                            <?php } ?>
                                        </td>
                                    </tr>
                                <?php } if ($pd_devices === []) { ?>
                                    <tr><td colspan="7" class="text-muted">No devices enrolled yet.</td></tr>
                                <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

<?php require_once "../includes/footer.php"; ?>
