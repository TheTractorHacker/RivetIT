<?php

/*
 * Scheduled / emailed reports admin (agent/reports/schedules.php)
 *
 * Add / list / delete / toggle rows in report_schedules. The cron/report_scheduler.php worker
 * picks up due, active schedules and emails a headline summary of the chosen report to the
 * recipient list. Gated by module_reporting; all mutations are CSRF-protected.
 *
 * Mutations are handled up-front (before any HTML output) so the post/redirect/get pattern
 * can issue a clean redirect; the interactive list then renders via inc_all_reports.php.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/report_schedule_policy.php';

enforceUserPermission('module_reporting');

$schedulable = report_schedulable_reports();

// A schedule emails figures the recipient may never have been allowed to open. Financial reports (income, expenses,
// AR aging, MRR) need module_financial exactly like their on-screen pages, and every change needs Reporting edit rights.
$can_edit_schedules = lookupUserPermission('module_reporting') >= 2;
$can_schedule_report = function ($key) {
    $module = report_schedule_required_module($key);
    return $module === null || lookupUserPermission($module) >= 1;
};
$schedule_scope_sql = $session_is_admin ? '' : ' AND schedule_owner_user_id = ' . intval($session_user_id);
$valid_frequencies = ['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'];

// ---- Add a schedule -------------------------------------------------------
// ---- Administrator-approved external recipients ---------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_allowed_recipients'])) {
    validateCSRFToken($_POST['csrf_token'] ?? '');
    enforceAdminPermission();

    $allowed_text = implode(', ', report_schedule_parse_allowlist($_POST['allowed_recipients'] ?? ''));
    $allowed_esc  = mysqli_real_escape_string($mysqli, substr($allowed_text, 0, 1000));
    mysqli_query($mysqli, "UPDATE settings SET config_report_schedule_allowed_recipients = '$allowed_esc' WHERE company_id = 1");
    logAction("Report Schedule", "Edit", "Updated approved external recipients for scheduled reports");

    $_SESSION['alert_type'] = 'success';
    $_SESSION['alert_message'] = 'Approved external recipients saved.';
    header('Location: schedules.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_schedule'])) {
    validateCSRFToken($_POST['csrf_token'] ?? '');
    enforceUserPermission('module_reporting', 2);

    $report     = $_POST['schedule_report'] ?? '';
    $frequency  = $_POST['schedule_frequency'] ?? '';
    $recipients = trim($_POST['schedule_recipients'] ?? '');

    // Validate against the whitelists so only known reports/frequencies land in the table.
    // Recipients must be active staff addresses or addresses an administrator approved; anything else is refused.
    [$valid_emails, $refused_emails] = report_schedule_filter_recipients($recipients, report_schedule_staff_emails($mysqli), report_schedule_allowlist($mysqli));

    if (!isset($schedulable[$report])) {
        $_SESSION['alert_type'] = 'danger';
        $_SESSION['alert_message'] = 'Please choose a valid report to schedule.';
    } elseif (!$can_schedule_report($report)) {
        $_SESSION['alert_type'] = 'danger';
        $_SESSION['alert_message'] = 'Your role does not have access to the data in that report.';
    } elseif (!empty($refused_emails)) {
        $_SESSION['alert_type'] = 'danger';
        $_SESSION['alert_message'] = 'Not an agent address or an administrator-approved recipient: ' . implode(', ', $refused_emails) . '. Ask an administrator to approve external addresses.';
    } elseif (!isset($valid_frequencies[$frequency])) {
        $_SESSION['alert_type'] = 'danger';
        $_SESSION['alert_message'] = 'Please choose a valid frequency.';
    } elseif (empty($valid_emails)) {
        $_SESSION['alert_type'] = 'danger';
        $_SESSION['alert_message'] = 'Please enter at least one valid recipient email address.';
    } else {
        $report_esc     = mysqli_real_escape_string($mysqli, $report);
        $frequency_esc  = mysqli_real_escape_string($mysqli, $frequency);
        // Store the cleaned, comma-separated recipient list.
        $recipients_esc = mysqli_real_escape_string($mysqli, implode(', ', $valid_emails));

        $owner_id = intval($session_user_id);
        mysqli_query($mysqli,
            "INSERT INTO report_schedules (schedule_report, schedule_frequency, schedule_recipients, schedule_active, schedule_owner_user_id)
             VALUES ('$report_esc', '$frequency_esc', '$recipients_esc', 1, $owner_id)");

        if (function_exists('logAction')) {
            logAction("Report Schedule", "Create", "Scheduled '{$schedulable[$report]}' ($frequency) to " . implode(', ', $valid_emails));
        }

        $_SESSION['alert_type'] = 'success';
        $_SESSION['alert_message'] = 'Report schedule added.';
    }

    header('Location: schedules.php');
    exit;
}

// ---- Delete a schedule ----------------------------------------------------
if (isset($_GET['delete'])) {
    validateCSRFToken($_GET['csrf_token'] ?? '');
    enforceUserPermission('module_reporting', 2);
    $schedule_id = intval($_GET['delete']);
    // Agents manage their own schedules; administrators manage all of them (including ones created before owners were recorded).
    mysqli_query($mysqli, "DELETE FROM report_schedules WHERE schedule_id = $schedule_id" . $schedule_scope_sql);

    if (function_exists('logAction')) {
        logAction("Report Schedule", "Delete", "Deleted report schedule #$schedule_id");
    }

    $_SESSION['alert_type'] = 'success';
    $_SESSION['alert_message'] = 'Report schedule deleted.';
    header('Location: schedules.php');
    exit;
}

// ---- Toggle active/paused -------------------------------------------------
if (isset($_GET['toggle'])) {
    validateCSRFToken($_GET['csrf_token'] ?? '');
    enforceUserPermission('module_reporting', 2);
    $schedule_id = intval($_GET['toggle']);
    mysqli_query($mysqli, "UPDATE report_schedules SET schedule_active = IF(schedule_active = 1, 0, 1) WHERE schedule_id = $schedule_id" . $schedule_scope_sql);

    $_SESSION['alert_type'] = 'success';
    $_SESSION['alert_message'] = 'Report schedule updated.';
    header('Location: schedules.php');
    exit;
}

// ---- Render (chrome + list) ----------------------------------------------
require_once "includes/inc_all_reports.php";

$sql_schedules = mysqli_query($mysqli, "SELECT * FROM report_schedules WHERE 1 = 1 $schedule_scope_sql ORDER BY schedule_created_at DESC");

?>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-paper-plane me-2"></i>Scheduled &amp; Emailed Reports</h3>
    </div>
    <div class="card-body p-0">

        <div class="px-3 pt-3 pb-1">
            <small class="text-muted">
                Each active schedule emails a headline summary of the chosen report to its recipients at the selected cadence.
                Delivery runs from the <?= nullable_htmlentities(APP_NAME) ?> cron (the same scheduler that sends other queued mail); a schedule is sent again once its frequency has elapsed since the last send.
            </small>
        </div>

        <?php if ($can_edit_schedules) { ?>
        <!-- Add schedule -->
        <form method="post" class="p-3 form-row align-items-end">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="add_schedule" value="1">
            <div class="col-md-3 col-6 mb-2">
                <label class="mb-1">Report</label>
                <select class="form-control" name="schedule_report" required>
                    <?php foreach ($schedulable as $key => $label) { if (!$can_schedule_report($key)) { continue; } ?>
                        <option value="<?php echo nullable_htmlentities($key); ?>"><?php echo nullable_htmlentities($label); ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="col-md-2 col-6 mb-2">
                <label class="mb-1">Frequency</label>
                <select class="form-control" name="schedule_frequency" required>
                    <?php foreach ($valid_frequencies as $key => $label) { ?>
                        <option value="<?php echo nullable_htmlentities($key); ?>"><?php echo nullable_htmlentities($label); ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="col-md-5 col-8 mb-2">
                <label class="mb-1">Recipients</label>
                <input type="text" class="form-control" name="schedule_recipients" placeholder="ops@example.com, owner@example.com" required>
            </div>
            <div class="col-md-2 col-4 mb-2">
                <button type="submit" class="btn btn-primary btn-block"><i class="fas fa-fw fa-plus me-1"></i>Add</button>
            </div>
        </form>
        <?php } else { ?>
        <div class="px-3 pb-2"><small class="text-muted">Your role can view schedules but not change them.</small></div>
        <?php } ?>

        <?php if ($session_is_admin) { $current_allowed = report_schedule_allowlist($mysqli); ?>
        <form method="post" class="px-3 pb-3">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="save_allowed_recipients" value="1">
            <label class="mb-1">Approved external recipients</label>
            <div class="input-group">
                <input type="text" class="form-control" name="allowed_recipients" value="<?php echo nullable_htmlentities(implode(', ', $current_allowed)); ?>" placeholder="owner@example.com, @partner.example">
                <button type="submit" class="btn btn-outline-primary">Save</button>
            </div>
            <small class="text-muted">Scheduled reports go to active agent addresses only, plus the addresses (or whole <code>@domains</code>) listed here. Only administrators can change this list.</small>
        </form>
        <?php } ?>

        <!-- Existing schedules -->
        <div class="table-responsive-sm px-3 pb-3">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Report</th>
                        <th>Frequency</th>
                        <th>Recipients</th>
                        <th>Status</th>
                        <th>Last sent</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$sql_schedules || mysqli_num_rows($sql_schedules) === 0) { ?>
                        <tr><td colspan="6" class="text-center text-muted">No report schedules yet.</td></tr>
                    <?php } else {
                        while ($row = mysqli_fetch_assoc($sql_schedules)) {
                            $schedule_id = intval($row['schedule_id']);
                            $report_key  = $row['schedule_report'];
                            $report_name = $schedulable[$report_key] ?? ($report_key . ' (unavailable)');
                            $frequency   = $valid_frequencies[$row['schedule_frequency']] ?? ucfirst((string) $row['schedule_frequency']);
                            $active      = intval($row['schedule_active']) === 1;
                            $last_sent   = !empty($row['schedule_last_sent']) ? nullable_htmlentities($row['schedule_last_sent']) : '<span class="text-muted">Never</span>';
                            ?>
                        <tr>
                            <td><?php echo nullable_htmlentities($report_name); ?></td>
                            <td><?php echo nullable_htmlentities($frequency); ?></td>
                            <td><?php echo nullable_htmlentities($row['schedule_recipients']); ?></td>
                            <td>
                                <?php if ($active) { ?>
                                    <span class="badge text-bg-success">Active</span>
                                <?php } else { ?>
                                    <span class="badge text-bg-secondary">Paused</span>
                                <?php } ?>
                            </td>
                            <td><?php echo $last_sent; ?></td>
                            <td class="text-end">
                                <?php if ($can_edit_schedules) { ?>
                                <a class="btn btn-sm btn-outline-secondary" href="schedules.php?toggle=<?php echo $schedule_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>">
                                    <i class="fas fa-fw fa-<?php echo $active ? 'pause' : 'play'; ?>"></i><?php echo $active ? ' Pause' : ' Resume'; ?>
                                </a>
                                <a class="btn btn-sm btn-outline-danger confirm-link" href="schedules.php?delete=<?php echo $schedule_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>">
                                    <i class="fas fa-fw fa-trash"></i> Delete
                                </a>
                                <?php } ?>
                            </td>
                        </tr>
                    <?php } } ?>
                </tbody>
            </table>
        </div>

    </div>
</div>

<?php require_once "../../includes/footer.php"; ?>
