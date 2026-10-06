<?php
require_once "includes/inc_all_admin.php";
enforceUserPermission('module_admin');

require_once "../src/Portal/EmployeeHome.php";
$eh = new \ITFlow\Portal\EmployeeHome($mysqli);

$portal_ready = !empty($config_portal_settings_ready);
$portal_on = \ITFlow\Portal\EmployeeHome::parseSections($config_portal_home_sections ?? null);
$portal_templates = $portal_ready ? $eh->onboardingTemplates() : [];
?>

<div class="card">
    <div class="card-header py-3">
        <h3 class="card-title"><i class="fas fa-fw fa-user-circle me-2"></i>Employee portal</h3>
    </div>
    <div class="card-body">
        <?php if (!$portal_ready) { ?>
            <div class="alert alert-warning">Apply the database update before changing these settings.</div>
        <?php } ?>
        <form action="post.php" method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

            <h5 class="mb-1">Home page sections</h5>
            <p class="text-muted">What an employee sees on the department portal home page. Every section is read-only and shows only that person's own requests, devices, approvals, checklist and training. Department administrators keep their existing home page and get the sections below it.</p>
            <?php foreach (\ITFlow\Portal\EmployeeHome::SECTIONS as $key => $label) { ?>
                <div class="form-check form-switch mb-2">
                    <input type="checkbox" class="form-check-input" name="sections[]" value="<?= nullable_htmlentities($key) ?>" id="portal_sec_<?= nullable_htmlentities($key) ?>" <?= in_array($key, $portal_on, true) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="portal_sec_<?= nullable_htmlentities($key) ?>"><?= nullable_htmlentities($label) ?></label>
                </div>
            <?php } ?>

            <hr>

            <h5 class="mb-1">Onboarding requests</h5>
            <p class="text-muted">Lets managers (people who have direct reports) and department administrators request onboarding for a new hire from the portal. The new hire's record is created in the requester's own department as a pre-hire. Off by default.</p>
            <div class="form-check form-switch mb-3">
                <input type="checkbox" class="form-check-input" name="onboarding_requests" value="1" id="portal_onb" <?= intval($config_portal_onboarding_requests ?? 0) === 1 ? 'checked' : '' ?>>
                <label class="form-check-label" for="portal_onb">Allow onboarding requests from the portal</label>
            </div>
            <div class="form-group">
                <label for="portal_onb_tpl">Onboarding workflow to start</label>
                <select class="form-control" name="onboarding_template_id" id="portal_onb_tpl">
                    <option value="0">None: open a ticket with the details instead</option>
                    <?php foreach ($portal_templates as $t) { ?>
                        <option value="<?= intval($t['workflow_template_id']) ?>" <?= intval($t['workflow_template_id']) === intval($config_portal_onboarding_template_id ?? 0) ? 'selected' : '' ?>><?= nullable_htmlentities($t['name']) ?></option>
                    <?php } ?>
                </select>
                <small class="form-text text-muted">Templates are managed under Employee workflows. If the template has a manager approval task, it waits for the new hire's manager as usual.</small>
            </div>

            <hr>

            <button type="submit" name="edit_portal_settings" class="btn btn-primary text-bold" <?= $portal_ready ? '' : 'disabled' ?>><i class="fas fa-check me-2"></i>Save</button>
        </form>
    </div>
</div>

<?php
require_once "../includes/footer.php";
