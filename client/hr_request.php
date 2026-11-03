<?php
/*
 * Client Portal
 * HR requests: a manager or department administrator asks HR to start an onboarding, an offboarding or a role-change workflow. Nothing starts until
 * an HR agent approves it. Off unless Settings > Employee portal turns it on. The department is always this contact's own; client/post.php
 * (submit_hr_request / cancel_hr_request) re-checks everything.
 */

ob_start();
require_once "includes/inc_all.php";

require_once $_SERVER['DOCUMENT_ROOT'] . '/src/Portal/EmployeeHome.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/src/Portal/HrRequests.php';
$eh = new \ITFlow\Portal\EmployeeHome($mysqli);
$hr = new \ITFlow\Portal\HrRequests($mysqli);
$hr_is_admin = ($session_contact_primary == 1 || $session_contact_is_technical_contact);

if (!empty($portal_preview_active) || !\ITFlow\Portal\HrRequests::available($mysqli) || !$hr->canRequest(intval($config_portal_hr_requests ?? 0) === 1, intval($session_contact_id), intval($session_client_id), $hr_is_admin)) {
    flash_alert('You cannot make HR requests', 'danger');
    ob_end_clean();
    redirect('index.php');
}

$hr_managers = $eh->departmentManagers(intval($session_client_id));
if (!$hr_is_admin) {
    $hr_managers = array_values(array_filter($hr_managers, static fn($m) => intval($m['contact_id']) === intval($session_contact_id)));
}
$hr_subjects = $hr->subjects(intval($session_contact_id), intval($session_client_id), $hr_is_admin);
$hr_old = $_SESSION['hr_request_old'] ?? [];
unset($_SESSION['hr_request_old']);
$hr_old = is_array($hr_old) ? $hr_old : [];
$hr_type = isset(\ITFlow\Portal\HrRequests::TYPES[$hr_old['type'] ?? '']) ? (string) $hr_old['type'] : 'onboarding';
$hr_mine = $hr->mine(intval($session_contact_id), intval($session_client_id));
$hr_status_badge = ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger', 'cancelled' => 'secondary'];
$hr_status_text = ['pending' => 'Waiting for HR', 'approved' => 'Approved, started', 'rejected' => 'Rejected', 'cancelled' => 'Withdrawn'];
?>

    <ol class="breadcrumb d-print-none">
        <li class="breadcrumb-item"><a href="index.php">Home</a></li>
        <li class="breadcrumb-item active">HR requests</li>
    </ol>

    <div class="portal-pagehead">
        <div>
            <h2 class="portal-pagehead-title">Ask HR to start a workflow</h2>
            <p class="text-secondary mb-0">New hire, leaver or role change. HR reviews every request first; nothing starts and nobody's record changes until they approve it.</p>
        </div>
    </div>

    <div class="card portal-card portal-form-card mb-4">
        <div class="card-body">
            <form action="post.php" method="post" autocomplete="off" id="hrRequestForm">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                <div class="form-group">
                    <label for="hr_type">What do you need? <strong class="text-danger">*</strong></label>
                    <select class="form-control" id="hr_type" name="type">
                        <?php foreach (\ITFlow\Portal\HrRequests::TYPES as $k => $l) { ?>
                            <option value="<?= $k ?>"<?= $hr_type === $k ? ' selected' : '' ?>><?= nullable_htmlentities($l) ?></option>
                        <?php } ?>
                    </select>
                </div>

                <div data-hr-show="onboarding">
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label for="hr_name">Full name <strong class="text-danger">*</strong></label>
                            <input type="text" class="form-control" id="hr_name" name="name" maxlength="200" value="<?= nullable_htmlentities($hr_old['name'] ?? '') ?>">
                        </div>
                        <div class="col-md-6 form-group">
                            <label for="hr_email">Email, personal or work <strong class="text-danger">*</strong></label>
                            <input type="email" class="form-control" id="hr_email" name="email" maxlength="200" value="<?= nullable_htmlentities($hr_old['email'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4 form-group">
                            <label for="hr_start">Start date <strong class="text-danger">*</strong></label>
                            <input type="date" class="form-control" id="hr_start" name="start_date" value="<?= nullable_htmlentities($hr_old['start_date'] ?? '') ?>">
                        </div>
                        <div class="col-md-4 form-group">
                            <label for="hr_manager">Manager</label>
                            <select class="form-control" id="hr_manager" name="manager_id">
                                <?php foreach ($hr_managers as $m) { ?>
                                    <option value="<?= intval($m['contact_id']) ?>"<?= intval($m['contact_id']) === intval($hr_old['manager_id'] ?? $session_contact_id) ? ' selected' : '' ?>><?= nullable_htmlentities($m['contact_name']) ?><?= intval($m['contact_id']) === intval($session_contact_id) ? ' (me)' : '' ?></option>
                                <?php } ?>
                            </select>
                        </div>
                        <div class="col-md-4 form-group">
                            <label for="hr_title">Role / title</label>
                            <input type="text" class="form-control" id="hr_title" name="title" maxlength="200" value="<?= nullable_htmlentities($hr_old['title'] ?? '') ?>">
                        </div>
                    </div>
                </div>

                <div data-hr-show="offboarding role_change">
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label for="hr_subject">Person <strong class="text-danger">*</strong></label>
                            <select class="form-control" id="hr_subject" name="subject_contact_id">
                                <option value="0">Choose...</option>
                                <?php foreach ($hr_subjects as $s) { ?>
                                    <option value="<?= intval($s['contact_id']) ?>"<?= intval($s['contact_id']) === intval($hr_old['subject_contact_id'] ?? 0) ? ' selected' : '' ?>><?= nullable_htmlentities($s['contact_name']) ?><?= $s['contact_title'] !== '' ? ' - ' . nullable_htmlentities($s['contact_title']) : '' ?></option>
                                <?php } ?>
                            </select>
                        </div>
                        <div class="col-md-6 form-group">
                            <label for="hr_date"><span data-hr-label="offboarding">Last working day</span><span data-hr-label="role_change">Effective date</span> <strong class="text-danger">*</strong></label>
                            <input type="date" class="form-control" id="hr_date" name="effective_date" value="<?= nullable_htmlentities($hr_old['effective_date'] ?? '') ?>">
                        </div>
                    </div>
                </div>

                <div data-hr-show="role_change" class="form-group">
                    <label for="hr_newtitle">New job title <strong class="text-danger">*</strong></label>
                    <input type="text" class="form-control" id="hr_newtitle" name="new_title" maxlength="200" value="<?= nullable_htmlentities($hr_old['new_title'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label for="hr_notes">Notes <small class="text-secondary">(equipment, access, anything HR or IT should know)</small></label>
                    <textarea class="form-control" id="hr_notes" name="notes" rows="3" maxlength="2000"><?= nullable_htmlentities($hr_old['notes'] ?? '') ?></textarea>
                </div>

                <button class="btn btn-primary" name="submit_hr_request" type="submit"><i class="fas fa-paper-plane me-2" aria-hidden="true"></i>Send to HR</button>
                <a href="index.php" class="btn btn-link text-secondary">Cancel</a>
            </form>
        </div>
    </div>

    <div class="card portal-card">
        <div class="card-header"><h3 class="card-title mb-0">My HR requests</h3></div>
        <div class="table-responsive">
            <table class="table mb-0 align-middle">
                <thead><tr><th>Request</th><th>For</th><th class="d-none d-sm-table-cell">Date</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php if (!$hr_mine) { ?><tr><td colspan="5" class="text-center text-secondary py-3">You have not made any HR requests.</td></tr><?php } ?>
                <?php foreach ($hr_mine as $r) { ?>
                    <tr>
                        <td><?= nullable_htmlentities(\ITFlow\Portal\HrRequests::TYPES[$r['hr_type']] ?? $r['hr_type']) ?></td>
                        <td><?= nullable_htmlentities($r['hr_subject_name']) ?><div class="small text-secondary d-sm-none"><?= nullable_htmlentities($r['hr_effective_date'] ?? '') ?></div></td>
                        <td class="d-none d-sm-table-cell"><?= nullable_htmlentities($r['hr_effective_date'] ?? '') ?></td>
                        <td><span class="badge text-nowrap text-bg-<?= $hr_status_badge[$r['hr_status']] ?? 'secondary' ?>"><?= nullable_htmlentities($hr_status_text[$r['hr_status']] ?? $r['hr_status']) ?></span>
                            <?php if ($r['hr_status'] === 'rejected' && (string) $r['hr_decision_comment'] !== '') { ?><div class="small text-secondary"><?= nullable_htmlentities($r['hr_decision_comment']) ?></div><?php } ?></td>
                        <td class="text-end">
                            <?php if ($r['hr_status'] === 'pending') { ?>
                            <form action="post.php" method="post" class="d-inline"><input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>"><input type="hidden" name="hr_request_id" value="<?= intval($r['hr_request_id']) ?>">
                                <button class="btn btn-sm btn-outline-secondary" type="submit" name="cancel_hr_request">Withdraw</button></form>
                            <?php } ?>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    </div>

    <script src="/js/portal_hr_request.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . '/js/portal_hr_request.js') ?>" defer></script>

<?php
require_once "includes/footer.php";
