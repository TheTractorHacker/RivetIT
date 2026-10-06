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

use ITFlow\Reports\ReportCatalog;
use ITFlow\Reports\ReportScheduler;
use ITFlow\Reports\SavedReports;

enforceUserPermission('module_reporting');

// Creating, pausing and deleting schedules (which email report data to arbitrary addresses) needs Reporting level 2;
// level 1 can view the list. Schedules created by someone else can be changed only by their owner or an administrator.
$can_manage_schedules = lookupUserPermission('module_reporting') >= 2;
$schedule_deny = static function () {
    $_SESSION['alert_type'] = 'danger';
    $_SESSION['alert_message'] = 'Managing report schedules needs Reporting modify access.';
    header('Location: schedules.php');
    exit;
};
$owns_schedule = static function (int $schedule_id) use ($mysqli, $session_user_id, $session_is_admin): bool {
    if ($session_is_admin) {
        return true;
    }
    $r = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT schedule_owner_user_id FROM report_schedules WHERE schedule_id = " . intval($schedule_id)));
    // Legacy rows (no owner) stay manageable by any level-2 user, as before.
    return $r && ($r['schedule_owner_user_id'] === null || intval($r['schedule_owner_user_id']) === intval($session_user_id));
};

$schedulable = report_schedulable_reports();
$valid_frequencies = ['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'];
$valid_formats = ['summary' => 'Headline summary (email)', 'csv' => 'CSV download link (7 days)', 'tables' => 'Report tables in the email'];
$can_module_row = static fn (string $key): bool => (ReportCatalog::module($key) === null) || lookupUserPermission(ReportCatalog::module($key)) >= 1;

// ---- Add a schedule -------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_schedule'])) {
    validateCSRFToken($_POST['csrf_token'] ?? '');
    if (!$can_manage_schedules) {
        $schedule_deny();
    }

    // Source is "r:<report key>" or "v:<saved view id>"; delivery format is summary | csv | html.
    $source     = (string) ($_POST['schedule_source'] ?? '');
    $delivery   = (string) ($_POST['schedule_delivery'] ?? 'summary');
    $report     = '';
    $saved_view_id = 0;
    if (strpos($source, 'v:') === 0) {
        $saved_view_id = intval(substr($source, 2));
        $view = $saved_view_id > 0 ? SavedReports::get($mysqli, $saved_view_id) : null;
        if ($view && SavedReports::canView($view, $session_user_id) && $can_module_row($view['saved_report_key'])) {
            $report = $view['saved_report_key'];
        } else {
            $saved_view_id = 0;
        }
    } elseif (strpos($source, 'r:') === 0) {
        $report = substr($source, 2);
    }
    $frequency  = $_POST['schedule_frequency'] ?? '';
    $recipients = trim($_POST['schedule_recipients'] ?? '');

    // Validate against the whitelists so only known reports/frequencies land in the table.
    $emails = preg_split('/[,;\s]+/', $recipients, -1, PREG_SPLIT_NO_EMPTY);
    $valid_emails = array_filter($emails, function ($e) {
        return filter_var($e, FILTER_VALIDATE_EMAIL);
    });

    if (!isset($valid_formats[$delivery])
        || ($delivery === 'summary' && (!isset($schedulable[$report]) || $saved_view_id > 0))
        || ($delivery !== 'summary' && (!ReportCatalog::exists($report) || !$can_module_row($report)))
        || ($report === '')) {
        $_SESSION['alert_type'] = 'danger';
        $_SESSION['alert_message'] = 'Please choose a valid report to schedule (a headline summary is only available for some reports, and you need access to the report).';
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

        $format_db = $delivery === 'summary' ? 'html' : $delivery; // html = headline summary, csv, tables
        $saved_db  = $saved_view_id > 0 ? $saved_view_id : 'NULL';
        $owner_db  = intval($session_user_id);

        mysqli_query($mysqli,
            "INSERT INTO report_schedules (schedule_report, schedule_frequency, schedule_recipients, schedule_active, schedule_saved_report_id, schedule_format, schedule_owner_user_id)
             VALUES ('$report_esc', '$frequency_esc', '$recipients_esc', 1, $saved_db, '$format_db', $owner_db)");

        if (function_exists('logAction')) {
            logAction("Report Schedule", "Create", "Scheduled '" . (ReportCatalog::label($report) ?? $report) . "' ($frequency, $delivery) to " . implode(', ', $valid_emails));
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
    $schedule_id = intval($_GET['delete']);
    if (!$can_manage_schedules || !$owns_schedule($schedule_id)) {
        $schedule_deny();
    }
    mysqli_query($mysqli, "DELETE FROM report_schedules WHERE schedule_id = $schedule_id");

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
    $schedule_id = intval($_GET['toggle']);
    if (!$can_manage_schedules || !$owns_schedule($schedule_id)) {
        $schedule_deny();
    }
    mysqli_query($mysqli, "UPDATE report_schedules SET schedule_active = IF(schedule_active = 1, 0, 1) WHERE schedule_id = $schedule_id");

    $_SESSION['alert_type'] = 'success';
    $_SESSION['alert_message'] = 'Report schedule updated.';
    header('Location: schedules.php');
    exit;
}

// ---- Render (chrome + list) ----------------------------------------------
require_once "includes/inc_all_reports.php";

$sql_schedules = mysqli_query($mysqli,
    "SELECT rs.*, sr.saved_report_name, u.user_name AS owner_name
     FROM report_schedules rs
     LEFT JOIN saved_reports sr ON sr.saved_report_id = rs.schedule_saved_report_id
     LEFT JOIN users u ON u.user_id = rs.schedule_owner_user_id
     ORDER BY rs.schedule_created_at DESC");

$my_views = SavedReports::listFor($mysqli, $session_user_id, null, static fn ($m) => $m === '' || lookupUserPermission($m) >= 1);
$all_reports = ReportCatalog::definitions();

?>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-paper-plane me-2"></i>Scheduled &amp; Emailed Reports</h3>
    </div>
    <div class="card-body p-0">

        <div class="px-3 pt-3 pb-1">
            <small class="text-muted">
                Each active schedule emails its report to the recipients at the selected cadence. A <b>headline summary</b> is a short table of key figures;
                <b>CSV</b> emails a link to download the report's export (the link expires after <?php echo intval(ReportScheduler::EXPIRY_DAYS); ?> days, the file is not attached because the mail queue cannot carry attachments);
                <b>tables in the email</b> puts the report's tables in the message. CSV and table schedules run as the person who created them, so that person's department restrictions apply.
                Delivery runs from the <?= nullable_htmlentities(APP_NAME) ?> cron; a schedule is sent again once its frequency has elapsed since the last send.
            </small>
        </div>

        <?php if ($can_manage_schedules) { ?>
        <!-- Add schedule -->
        <form method="post" class="p-3 form-row align-items-end">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="add_schedule" value="1">
            <div class="col-md-3 col-6 mb-2">
                <label class="mb-1">Report or saved view</label>
                <select class="form-control" name="schedule_source" required>
                    <optgroup label="Reports">
                        <?php foreach ($all_reports as $key => $def) {
                            if (!$can_module_row($key)) { continue; } ?>
                            <option value="r:<?php echo nullable_htmlentities($key); ?>"><?php echo nullable_htmlentities($def['label']); ?></option>
                        <?php } ?>
                    </optgroup>
                    <?php if ($my_views) { ?>
                    <optgroup label="Saved views (CSV or tables only)">
                        <?php foreach ($my_views as $v) { ?>
                            <option value="v:<?php echo intval($v['saved_report_id']); ?>"><?php echo nullable_htmlentities($v['saved_report_name'] . ' - ' . ReportCatalog::label($v['saved_report_key'])); ?></option>
                        <?php } ?>
                    </optgroup>
                    <?php } ?>
                </select>
            </div>
            <div class="col-md-2 col-6 mb-2">
                <label class="mb-1">Delivery</label>
                <select class="form-control" name="schedule_delivery" required>
                    <?php foreach ($valid_formats as $key => $label) { ?>
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
            <div class="col-md-3 col-8 mb-2">
                <label class="mb-1">Recipients</label>
                <input type="text" class="form-control" name="schedule_recipients" placeholder="ops@example.com, owner@example.com" required>
            </div>
            <div class="col-md-2 col-4 mb-2">
                <button type="submit" class="btn btn-primary btn-block"><i class="fas fa-fw fa-plus me-1"></i>Add</button>
            </div>
        </form>
        <?php } else { ?>
        <div class="px-3 py-2"><span class="text-muted">You can see the schedules; adding, pausing or deleting one needs Reporting modify access.</span></div>
        <?php } ?>

        <!-- Existing schedules -->
        <div class="table-responsive-sm px-3 pb-3">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Report</th>
                        <th>Delivery</th>
                        <th>Frequency</th>
                        <th>Recipients</th>
                        <th>Owner</th>
                        <th>Status</th>
                        <th>Last sent</th>
                        <th>Last run</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$sql_schedules || mysqli_num_rows($sql_schedules) === 0) { ?>
                        <tr><td colspan="9" class="text-center text-muted">No report schedules yet.</td></tr>
                    <?php } else {
                        while ($row = mysqli_fetch_assoc($sql_schedules)) {
                            $schedule_id = intval($row['schedule_id']);
                            $report_key  = $row['schedule_report'];
                            $report_name = ReportCatalog::label($report_key) ?? ($schedulable[$report_key] ?? ($report_key . ' (unavailable)'));
                            if (!empty($row['saved_report_name'])) {
                                $report_name .= ' - view: ' . $row['saved_report_name'];
                            }
                            $delivery_label = ReportScheduler::usesDataPath($row) ? ($row['schedule_format'] === 'csv' ? 'CSV link' : 'Tables in email') : 'Headline summary';
                            $frequency   = $valid_frequencies[$row['schedule_frequency']] ?? ucfirst((string) $row['schedule_frequency']);
                            $active      = intval($row['schedule_active']) === 1;
                            $last_sent   = !empty($row['schedule_last_sent']) ? nullable_htmlentities($row['schedule_last_sent']) : '<span class="text-muted">Never</span>';
                            $last_status = (string) ($row['schedule_last_status'] ?? '');
                            $may_change  = $can_manage_schedules && ($session_is_admin || $row['schedule_owner_user_id'] === null || intval($row['schedule_owner_user_id']) === intval($session_user_id));
                            ?>
                        <tr>
                            <td><?php echo nullable_htmlentities($report_name); ?></td>
                            <td><?php echo nullable_htmlentities($delivery_label); ?></td>
                            <td><?php echo nullable_htmlentities($frequency); ?></td>
                            <td><?php echo nullable_htmlentities($row['schedule_recipients']); ?></td>
                            <td><?php echo nullable_htmlentities($row['owner_name'] ?? ''); ?></td>
                            <td>
                                <?php if ($active) { ?>
                                    <span class="badge text-bg-success">Active</span>
                                <?php } else { ?>
                                    <span class="badge text-bg-secondary">Paused</span>
                                <?php } ?>
                            </td>
                            <td><?php echo $last_sent; ?></td>
                            <td class="small">
                                <?php if ($last_status !== '') { ?>
                                    <span class="<?php echo strpos($last_status, 'error') === 0 ? 'text-danger' : 'text-muted'; ?>"><?php echo nullable_htmlentities($last_status); ?></span>
                                    <br><span class="text-muted"><?php echo nullable_htmlentities($row['schedule_last_run_at'] ?? ''); ?></span>
                                <?php } ?>
                            </td>
                            <td class="text-end text-nowrap">
                                <?php if ($may_change) { ?>
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
