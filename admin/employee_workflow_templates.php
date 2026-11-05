<?php
require_once "includes/inc_all_admin.php";

enforceUserPermission('module_client', 2);

$sql = mysqli_query($mysqli, "SELECT wt.*,
    (SELECT COUNT(*) FROM workflow_template_tasks WHERE workflow_template_id = wt.workflow_template_id) AS task_count
    FROM workflow_templates wt
    WHERE wt.archived_at IS NULL
    ORDER BY wt.type ASC, wt.name ASC");

$lifecycle_ready = mysqli_num_rows(mysqli_query($mysqli, "SHOW COLUMNS FROM `settings` LIKE 'config_lifecycle_auto_start'")) > 0;
$lifecycle_on = $lifecycle_ready && \ITFlow\Workflow\LifecycleEvents::enabled($mysqli);
$odoo_ready = mysqli_num_rows(mysqli_query($mysqli, "SHOW COLUMNS FROM `settings` LIKE 'config_lifecycle_odoo_actions'")) > 0;
$odoo_on = $odoo_ready && (new \ITFlow\Workflow\LiveActionGateway($mysqli))->odooActionsAllowed();
$okta_ready = mysqli_num_rows(mysqli_query($mysqli, "SHOW COLUMNS FROM `settings` LIKE 'config_lifecycle_okta_actions'")) > 0;
$okta = $okta_ready ? (mysqli_fetch_assoc(mysqli_query($mysqli, 'SELECT config_lifecycle_okta_actions, config_lifecycle_okta_domain, (config_lifecycle_okta_token IS NOT NULL AND config_lifecycle_okta_token <> \'\') AS has_token FROM settings WHERE company_id = 1')) ?: []) : [];
$has_preset_col = mysqli_num_rows(mysqli_query($mysqli, "SHOW COLUMNS FROM `workflow_templates` LIKE 'preset_key'")) > 0;

?>

<?php if ($session_is_admin && $lifecycle_ready) { ?>
<div class="card mb-3">
    <div class="card-header py-2"><h5 class="card-title mb-0"><i class="fas fa-fw fa-bolt me-2"></i>Start workflows automatically</h5></div>
    <div class="card-body">
        <p class="text-muted small">When on, a hire, a departure, a new job title or a move to another department raises an <code>employee.hired</code>, <code>employee.terminated</code>, <code>employee.role_changed</code> (new job title) or <code>employee.transferred</code> (new department) event (a contact edit, the people import, or the Microsoft, Google and Odoo directory syncs). An <a href="event_rules.php">event rule</a> with the action &ldquo;Start an employee workflow&rdquo; then starts the template you choose, once per person. Off by default: with it off, nothing is detected and workflows only start when someone starts them.</p>
        <form action="post.php" method="post" class="d-flex flex-wrap align-items-end justify-content-between gap-3">
            <div class="d-flex flex-column gap-2">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <div class="form-check"><input class="form-check-input" type="checkbox" name="lifecycle_auto_start" value="1" id="lifecycle_auto_start" <?= $lifecycle_on ? 'checked' : '' ?>><label class="form-check-label" for="lifecycle_auto_start">Detect hires, departures, role changes and transfers and let event rules start workflows</label></div>
            <?php if ($odoo_ready) { ?>
            <div class="form-check"><input class="form-check-input" type="checkbox" name="lifecycle_odoo_actions" value="1" id="lifecycle_odoo_actions" <?= $odoo_on ? 'checked' : '' ?>><label class="form-check-label" for="lifecycle_odoo_actions">Allow workflows to create / archive employees in Odoo <span class="text-muted">(off by default; Odoo is a live system)</span></label></div>
            <?php } ?>
            </div>
            <button type="submit" name="save_lifecycle_settings" class="btn btn-primary">Save</button>
        </form>
    </div>
</div>
<?php } ?>

<?php if ($session_is_admin && $okta_ready) { ?>
<div class="card mb-3">
    <div class="card-header py-2"><h5 class="card-title mb-0"><i class="fas fa-fw fa-id-badge me-2"></i>Okta actions</h5></div>
    <div class="card-body">
        <p class="text-muted small">Lets a workflow create a user, suspend a user (never delete) and add a user to groups in Okta. <strong>Off by default</strong>, and it also needs the Okta domain and an API token (an Okta admin API token with only the permissions to manage users and groups). With it off nothing is sent: the task becomes a manual task. The token is stored encrypted and is never shown again; leave the box empty to keep it. Verified against a local mock only, not a real Okta org.</p>
        <form action="post.php" method="post" class="row g-2 align-items-end">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <div class="col-md-4"><label class="form-label small mb-1" for="okta_domain">Okta domain</label>
                <input type="text" class="form-control form-control-sm" id="okta_domain" name="okta_domain" maxlength="253" placeholder="example.okta.com" value="<?= nullable_htmlentities($okta['config_lifecycle_okta_domain'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label small mb-1" for="okta_token">API token <?= !empty($okta['has_token']) ? '<span class="badge text-bg-success">saved</span>' : '' ?></label>
                <input type="password" class="form-control form-control-sm" id="okta_token" name="okta_token" maxlength="300" autocomplete="new-password" placeholder="<?= !empty($okta['has_token']) ? 'leave empty to keep' : 'SSWS token' ?>"></div>
            <div class="col-md-4"><div class="form-check"><input class="form-check-input" type="checkbox" name="lifecycle_okta_actions" value="1" id="lifecycle_okta_actions" <?= !empty($okta['config_lifecycle_okta_actions']) ? 'checked' : '' ?>><label class="form-check-label" for="lifecycle_okta_actions">Allow workflows to change Okta</label></div>
                <?php if (!empty($okta['has_token'])) { ?><div class="form-check"><input class="form-check-input" type="checkbox" name="okta_clear_token" value="1" id="okta_clear_token"><label class="form-check-label" for="okta_clear_token">Remove the saved token</label></div><?php } ?></div>
            <div class="col-12"><button type="submit" name="save_okta_settings" class="btn btn-sm btn-primary">Save Okta settings</button></div>
        </form>
    </div>
</div>
<?php } ?>

<div class="card">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-tasks me-2"></i>Employee Workflow Templates</h3>
        <div class="card-tools">
            <a href="bulk_onboarding.php" class="btn btn-outline-secondary me-1"><i class="fas fa-users me-2"></i>Start for many people</a>
            <button type="button" class="btn btn-primary ajax-modal" data-modal-url="modals/employee_workflow/employee_workflow_template_add.php"><i class="fas fa-plus me-2"></i>New Template</button>
        </div>
    </div>
    <div class="card-body">
        <p class="text-muted">Onboarding/offboarding checklists you can start against a specific person from their contact page. Tasks can wait on other tasks, have an assignee and due date, need an approval, or run an automated action (a ticket, an email, a notification, a webhook event, or switching off a portal login). A task you leave as Manual is just a tracked checklist item with an audit trail.</p>
        <?php if ($has_preset_col) { ?>
        <form action="post.php" method="post" class="d-flex flex-wrap gap-2 align-items-center mb-3">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <span class="small text-muted">Built-in starters (installed inactive, edit before use):</span>
            <?php foreach (\ITFlow\Workflow\LifecyclePresets::STARTERS as $sk => $sd) { ?>
                <button type="submit" name="install_workflow_starter" value="1" class="btn btn-sm btn-outline-secondary js-starter" data-starter="<?= nullable_htmlentities($sk) ?>"><?= nullable_htmlentities($sd['name']) ?></button>
            <?php } ?>
            <input type="hidden" name="starter" value="">
        </form>
        <?php } ?>
        <div class="table-responsive">
        <table class="table table-hover">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Type</th>
                    <th>Tasks</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = mysqli_fetch_assoc($sql)) { ?>
                    <tr>
                        <td><a href="employee_workflow_template_details.php?id=<?= intval($row['workflow_template_id']) ?>"><?= nullable_htmlentities($row['name']) ?></a></td>
                        <td><span class="badge <?= ['onboarding' => 'text-bg-success', 'offboarding' => 'text-bg-danger'][$row['type']] ?? 'text-bg-info' ?>"><?= ucwords(str_replace('_', ' ', $row['type'])) ?></span></td>
                        <td><?= intval($row['task_count']) ?></td>
                        <td><?= $row['is_active'] ? '<span class="text-success">Active</span>' : '<span class="text-muted">Inactive</span>' ?></td>
                        <td class="text-end">
                            <a href="employee_workflow_template_details.php?id=<?= intval($row['workflow_template_id']) ?>" class="btn btn-sm btn-default" title="Manage tasks" aria-label="Manage tasks"><i class="fas fa-cog"></i></a>
                            <div class="dropdown d-inline-block">
                                <button class="btn btn-secondary btn-sm" type="button" data-bs-toggle="dropdown" title="More actions" aria-label="More actions">
                                    <i class="fas fa-ellipsis-h"></i>
                                </button>
                                <div class="dropdown-menu dropdown-menu-end">
                                    <a class="dropdown-item ajax-modal" href="#" data-modal-size="lg"
                                        data-modal-url="modals/employee_workflow/employee_workflow_template_edit.php?id=<?= intval($row['workflow_template_id']) ?>">
                                        <i class="fas fa-fw fa-edit me-2"></i>Edit
                                    </a>
                                    <a class="dropdown-item text-danger confirm-link"
                                        href="post.php?archive_employee_workflow_template=<?= intval($row['workflow_template_id']) ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                        <i class="fas fa-fw fa-archive me-2"></i>Archive
                                    </a>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
document.querySelectorAll('.js-starter').forEach(function (b) {
    b.addEventListener('click', function () { b.form.querySelector('input[name=starter]').value = b.getAttribute('data-starter'); });
});
</script>

<?php require_once "../includes/footer.php"; ?>
