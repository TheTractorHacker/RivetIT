<?php
/*
 * Client Portal
 * Request onboarding for a new hire. Off unless Settings > Portal turns it on; available to managers (contacts with direct
 * reports) and department administrators (primary / technical contacts) only. The new hire is always created in THIS contact's own
 * department; client/post.php (request_onboarding) re-checks everything.
 */

// inc_all.php streams the page chrome before control returns, so the not-allowed redirect below needs output buffered.
ob_start();
require_once "includes/inc_all.php";

require_once $_SERVER['DOCUMENT_ROOT'] . '/src/Portal/EmployeeHome.php';
$eh = new \ITFlow\Portal\EmployeeHome($mysqli);
$ob_is_admin = ($session_contact_primary == 1 || $session_contact_is_technical_contact);

if (!empty($portal_preview_active) || !$eh->canRequestOnboarding(intval($config_portal_onboarding_requests ?? 0) === 1, intval($session_contact_id), intval($session_client_id), $ob_is_admin)) {
    flash_alert('You cannot request onboarding', 'danger');
    ob_end_clean();
    redirect('index.php');
}

$ob_managers = $eh->departmentManagers(intval($session_client_id));
// A manager names themselves; an administrator can pick anyone in the department (default: themselves).
if (!$ob_is_admin) {
    $ob_managers = array_values(array_filter($ob_managers, static fn($m) => intval($m['contact_id']) === intval($session_contact_id)));
}
$ob_old = $_SESSION['onboarding_old'] ?? [];
unset($_SESSION['onboarding_old']);
$ob_old = is_array($ob_old) ? $ob_old : [];
?>

    <ol class="breadcrumb d-print-none">
        <li class="breadcrumb-item"><a href="index.php">Home</a></li>
        <li class="breadcrumb-item active">Request onboarding</li>
    </ol>

    <div class="portal-pagehead">
        <div>
            <h2 class="portal-pagehead-title">Request onboarding for a new hire</h2>
            <p class="text-secondary mb-0">We create their record in your department and start their onboarding checklist, so their accounts and equipment are ready.</p>
        </div>
    </div>

    <div class="card portal-card portal-form-card">
        <div class="card-body">
            <form action="post.php" method="post" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                <div class="row">
                    <div class="col-md-6 form-group">
                        <label for="ob_name">Full name <strong class="text-danger">*</strong></label>
                        <input type="text" class="form-control" id="ob_name" name="name" maxlength="200" required value="<?= nullable_htmlentities($ob_old['name'] ?? '') ?>">
                    </div>
                    <div class="col-md-6 form-group">
                        <label for="ob_email">Email, personal or work <strong class="text-danger">*</strong></label>
                        <input type="email" class="form-control" id="ob_email" name="email" maxlength="200" required value="<?= nullable_htmlentities($ob_old['email'] ?? '') ?>">
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-4 form-group">
                        <label for="ob_start">Start date <strong class="text-danger">*</strong></label>
                        <input type="date" class="form-control" id="ob_start" name="start_date" required value="<?= nullable_htmlentities($ob_old['start_date'] ?? '') ?>">
                    </div>
                    <div class="col-md-4 form-group">
                        <label for="ob_manager">Manager</label>
                        <select class="form-control" id="ob_manager" name="manager_id">
                            <?php foreach ($ob_managers as $m) { ?>
                                <option value="<?= intval($m['contact_id']) ?>"<?= intval($m['contact_id']) === intval($ob_old['manager_id'] ?? $session_contact_id) ? ' selected' : '' ?>><?= nullable_htmlentities($m['contact_name']) ?><?= intval($m['contact_id']) === intval($session_contact_id) ? ' (me)' : '' ?></option>
                            <?php } ?>
                        </select>
                    </div>
                    <div class="col-md-4 form-group">
                        <label for="ob_title">Role / title</label>
                        <input type="text" class="form-control" id="ob_title" name="title" maxlength="200" value="<?= nullable_htmlentities($ob_old['title'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label for="ob_notes">Notes <small class="text-secondary">(equipment, access, anything IT should know)</small></label>
                    <textarea class="form-control" id="ob_notes" name="notes" rows="4" maxlength="2000"><?= nullable_htmlentities($ob_old['notes'] ?? '') ?></textarea>
                </div>

                <button class="btn btn-primary" name="request_onboarding" type="submit"><i class="fas fa-user-plus me-2" aria-hidden="true"></i>Request onboarding</button>
                <a href="index.php" class="btn btn-link text-secondary">Cancel</a>
            </form>
        </div>
    </div>

<?php
require_once "includes/footer.php";
