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
use ITFlow\Reports\RecipientGroups;
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/report_schedule_policy.php';

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
    // Legacy rows (no owner) are administrator-only.
    return $r && $r['schedule_owner_user_id'] !== null && intval($r['schedule_owner_user_id']) === intval($session_user_id);
};

$schedulable = report_schedulable_reports();

// A schedule emails figures the recipient may never have been allowed to open. Financial reports (income, expenses,
// AR aging, MRR) need module_financial exactly like their on-screen pages, and every change needs Reporting edit rights.
$can_edit_schedules = lookupUserPermission('module_reporting') >= 2;
$can_schedule_report = function ($key) {
    $module = report_schedule_required_module($key);
    return $module === null || lookupUserPermission($module) >= 1;
};
$schedule_scope_sql = $session_is_admin ? '' : ' AND schedule_owner_user_id = ' . intval($session_user_id);
$valid_frequencies = ['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'quarterly' => 'Quarterly'];
$valid_formats = ['summary' => 'Headline summary (email)', 'csv' => 'CSV download link (7 days)', 'tables' => 'Report tables in the email', 'pdf' => 'PDF attached to the email'];
$can_module_row = static fn (string $key): bool => (ReportCatalog::module($key) === null) || lookupUserPermission(ReportCatalog::module($key)) >= 1;

// ---- Add a schedule -------------------------------------------------------
// ---- Recipient groups -----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_group'])) {
    validateCSRFToken($_POST['csrf_token'] ?? '');
    if (!$can_manage_schedules) {
        $schedule_deny();
    }
    enforceUserPermission('module_reporting', 2);
    $res = RecipientGroups::create($mysqli, intval($session_user_id), (string) ($_POST['group_name'] ?? ''), (string) ($_POST['group_recipients'] ?? ''),
        report_schedule_staff_emails($mysqli), report_schedule_allowlist($mysqli));
    if (is_int($res)) {
        logAction("Report Schedule", "Create", "Created report recipient group #$res");
        $_SESSION['alert_type'] = 'success';
        $_SESSION['alert_message'] = 'Recipient group added.';
    } else {
        $_SESSION['alert_type'] = 'danger';
        $_SESSION['alert_message'] = $res;
    }
    header('Location: schedules.php');
    exit;
}

if (isset($_GET['delete_group'])) {
    validateCSRFToken($_GET['csrf_token'] ?? '');
    enforceUserPermission('module_reporting', 2);
    if (!$can_manage_schedules) {
        $schedule_deny();
    }
    $gid = intval($_GET['delete_group']);
    if (RecipientGroups::delete($mysqli, $gid, intval($session_user_id), (bool) $session_is_admin)) {
        logAction("Report Schedule", "Delete", "Deleted report recipient group #$gid");
        $_SESSION['alert_type'] = 'success';
        $_SESSION['alert_message'] = 'Recipient group deleted.';
    } else {
        $_SESSION['alert_type'] = 'danger';
        $_SESSION['alert_message'] = 'Only the person who created a group, or an administrator, can delete it.';
    }
    header('Location: schedules.php');
    exit;
}

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
    if (!$can_manage_schedules) {
        $schedule_deny();
    }
    enforceUserPermission('module_reporting', 2);

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
    // Recipients must be active staff addresses or addresses an administrator approved; anything else is refused.
    [$valid_emails, $refused_emails] = report_schedule_filter_recipients($recipients, report_schedule_staff_emails($mysqli), report_schedule_allowlist($mysqli));

    // Group targets: group_target[GID]=1 with an optional group_view[GID] = a saved view that group receives instead of the schedule's own report.
    $group_targets = [];
    $group_error = '';
    foreach ((array) ($_POST['group_target'] ?? []) as $gid => $on) {
        $gid = intval($gid);
        if ($gid <= 0 || !RecipientGroups::get($mysqli, $gid)) {
            continue;
        }
        $gv = intval($_POST['group_view'][$gid] ?? 0);
        if ($gv > 0) {
            $gview = SavedReports::get($mysqli, $gv);
            if (!$gview || !SavedReports::canView($gview, $session_user_id) || !$can_module_row($gview['saved_report_key'])) {
                $group_error = 'You cannot use one of the saved views you picked for a group.';
                break;
            }
        }
        $group_targets[$gid] = $gv;
    }

    if (!isset($valid_formats[$delivery])
        || ($delivery === 'summary' && (!isset($schedulable[$report]) || $saved_view_id > 0))
        || ($delivery !== 'summary' && (!ReportCatalog::exists($report) || !$can_module_row($report)))
        || ($report === '')) {
        $_SESSION['alert_type'] = 'danger';
        if ($report !== '' && isset($valid_formats[$delivery]) && $delivery === 'summary' && !isset($schedulable[$report]) && $saved_view_id <= 0) {
            $_SESSION['alert_message'] = 'That report has no headline summary. Choose CSV, tables or PDF delivery for it, or pick a different report.';
        } else {
            $_SESSION['alert_message'] = 'Please choose a valid report to schedule (a headline summary is only available for some reports, and you need access to the report).';
        }
    } elseif (!$can_schedule_report($report)) {
        $_SESSION['alert_type'] = 'danger';
        $_SESSION['alert_message'] = 'Your role does not have access to the data in that report.';
    } elseif (!empty($refused_emails)) {
        $_SESSION['alert_type'] = 'danger';
        $_SESSION['alert_message'] = 'Not an agent address or an administrator-approved recipient: ' . implode(', ', $refused_emails) . '. Ask an administrator to approve external addresses.';
    } elseif (!isset($valid_frequencies[$frequency])) {
        $_SESSION['alert_type'] = 'danger';
        $_SESSION['alert_message'] = 'Please choose a valid frequency.';
    } elseif ($group_error !== '') {
        $_SESSION['alert_type'] = 'danger';
        $_SESSION['alert_message'] = $group_error;
    } elseif ($delivery === 'summary' && $group_targets) {
        $_SESSION['alert_type'] = 'danger';
        $_SESSION['alert_message'] = 'Recipient groups need a CSV, tables or PDF delivery (the headline summary has no per-group views).';
    } elseif (empty($valid_emails) && !$group_targets) {
        $_SESSION['alert_type'] = 'danger';
        $_SESSION['alert_message'] = 'Please enter at least one valid recipient email address, or choose a recipient group.';
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

        $new_schedule_id = (int) mysqli_insert_id($mysqli);
        if ($group_targets && $new_schedule_id > 0) {
            RecipientGroups::setTargets($mysqli, $new_schedule_id, $group_targets);
        }

        if (function_exists('logAction')) {
            logAction("Report Schedule", "Create", "Scheduled '" . (ReportCatalog::label($report) ?? $report) . "' ($frequency, $delivery) to " . implode(', ', $valid_emails) . ($group_targets ? ' and ' . count($group_targets) . ' recipient group(s)' : ''));
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
    if (!$can_manage_schedules || !$owns_schedule($schedule_id)) {
        $schedule_deny();
    }
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
    if (!$can_manage_schedules || !$owns_schedule($schedule_id)) {
        $schedule_deny();
    }
    mysqli_query($mysqli, "UPDATE report_schedules SET schedule_active = IF(schedule_active = 1, 0, 1) WHERE schedule_id = $schedule_id" . $schedule_scope_sql);

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
     WHERE 1 = 1 " . str_replace('schedule_owner_user_id', 'rs.schedule_owner_user_id', $schedule_scope_sql) . "
     ORDER BY rs.schedule_created_at DESC");

$recipient_groups = RecipientGroups::all($mysqli);
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
                <b>CSV</b> emails a link to download the report's export (the link expires after <?php echo intval(ReportScheduler::EXPIRY_DAYS); ?> days);
                <b>PDF</b> attaches the report to the email as a PDF file; <b>tables in the email</b> puts the report's tables in the message. CSV, PDF and table schedules run as the person who created them, so that person's department restrictions apply.
                <b>Recipient groups</b> let one schedule send a different saved view to each group.
                Delivery runs from the <?= nullable_htmlentities(APP_NAME) ?> cron; a schedule is sent again once its frequency has elapsed since the last send.
            </small>
        </div>

        <?php if ($can_manage_schedules) { ?>
        <!-- Add schedule -->
        <form method="post" class="p-3 form-row align-items-end">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="add_schedule" value="1">
            <div class="col-md-3 col-12 mb-2">
                <label class="mb-1">Report or saved view</label>
                <select class="form-control" name="schedule_source" required>
                    <optgroup label="Reports">
                        <?php foreach ($all_reports as $key => $def) {
                            if (!$can_module_row($key)) { continue; } ?>
                            <option value="r:<?php echo nullable_htmlentities($key); ?>"<?php if (!isset($sched_default_set) && isset($schedulable[$key])) { $sched_default_set = true; echo ' selected'; } ?>><?php echo nullable_htmlentities($def['label']); ?></option>
                        <?php } ?>
                    </optgroup>
                    <?php if ($my_views) { ?>
                    <optgroup label="Saved views (CSV, PDF or tables only)">
                        <?php foreach ($my_views as $v) { ?>
                            <option value="v:<?php echo intval($v['saved_report_id']); ?>"><?php echo nullable_htmlentities($v['saved_report_name'] . ' - ' . ReportCatalog::label($v['saved_report_key'])); ?></option>
                        <?php } ?>
                    </optgroup>
                    <?php } ?>
                </select>
            </div>
            <div class="col-md-3 col-12 mb-2">
                <label class="mb-1">Delivery</label>
                <select class="form-control" name="schedule_delivery" required>
                    <?php foreach ($valid_formats as $key => $label) { ?>
                        <option value="<?php echo nullable_htmlentities($key); ?>"><?php echo nullable_htmlentities($label); ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="col-md-2 col-12 mb-2">
                <label class="mb-1">Frequency</label>
                <select class="form-control" name="schedule_frequency" required>
                    <?php foreach ($valid_frequencies as $key => $label) { ?>
                        <option value="<?php echo nullable_htmlentities($key); ?>"><?php echo nullable_htmlentities($label); ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="col-md-4 col-12 mb-2">
                <label class="mb-1">Recipients</label>
                <input type="text" class="form-control" name="schedule_recipients" placeholder="ops@example.com, owner@example.com">
            </div>
            <div class="col-12 mb-2">
                <button type="submit" class="btn btn-primary"><i class="fas fa-fw fa-plus me-1"></i>Add</button>
            </div>
            <?php if ($recipient_groups) { ?>
            <div class="col-12 mb-2">
                <div class="fw-bold mb-1">Also send to recipient groups <span class="text-muted fw-normal">(CSV, PDF or tables; each group can receive a different saved view)</span></div>
                <div class="row g-2">
                    <?php foreach ($recipient_groups as $g) { $gid = intval($g['group_id']); ?>
                        <div class="col-md-4 col-12">
                            <div class="d-flex align-items-center gap-2">
                                <div class="form-check mb-0 text-nowrap">
                                    <input class="form-check-input" type="checkbox" name="group_target[<?php echo $gid; ?>]" value="1" id="gt-<?php echo $gid; ?>">
                                    <label class="form-check-label" for="gt-<?php echo $gid; ?>"><?php echo nullable_htmlentities($g['group_name']); ?></label>
                                </div>
                                <select class="form-control form-control-sm" name="group_view[<?php echo $gid; ?>]" aria-label="Saved view for <?php echo nullable_htmlentities($g['group_name']); ?>">
                                    <option value="0">Same report as above</option>
                                    <?php foreach ($my_views as $v) { ?>
                                        <option value="<?php echo intval($v['saved_report_id']); ?>"><?php echo nullable_htmlentities($v['saved_report_name']); ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                        </div>
                    <?php } ?>
                </div>
            </div>
            <?php } ?>
        </form>

        <!-- Recipient groups -->
        <div class="px-3 pb-3">
            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#rpt-groups"><i class="fas fa-fw fa-users me-1"></i>Recipient groups (<?php echo count($recipient_groups); ?>)</button>
            <div class="collapse mt-2" id="rpt-groups">
                <form method="post" class="row g-2 align-items-end mb-2">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="add_group" value="1">
                    <div class="col-md-3 col-12"><label class="mb-1" for="grp-name">Group name</label><input type="text" id="grp-name" class="form-control form-control-sm" name="group_name" maxlength="100" required placeholder="Executives"></div>
                    <div class="col-md-6 col-12"><label class="mb-1" for="grp-rcpt">Recipients</label><input type="text" id="grp-rcpt" class="form-control form-control-sm" name="group_recipients" required placeholder="cio@example.com, coo@example.com"></div>
                    <div class="col-md-3 col-12"><button type="submit" class="btn btn-sm btn-primary w-100"><i class="fas fa-fw fa-plus me-1"></i>Add group</button></div>
                </form>
                <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Group</th><th>Recipients</th><th>In schedules</th><th class="text-end">Actions</th></tr></thead>
                    <tbody>
                    <?php if (!$recipient_groups) { ?><tr><td colspan="4" class="text-muted text-center">No recipient groups yet.</td></tr><?php } ?>
                    <?php foreach ($recipient_groups as $g) { ?>
                        <tr>
                            <td><?php echo nullable_htmlentities($g['group_name']); ?></td>
                            <td class="small"><?php echo nullable_htmlentities($g['group_recipients']); ?></td>
                            <td><?php echo intval($g['used_by']); ?></td>
                            <td class="text-end">
                                <?php if ($session_is_admin || intval($g['group_created_by']) === intval($session_user_id)) { ?>
                                <a class="btn btn-sm btn-outline-danger confirm-link" href="schedules.php?delete_group=<?php echo intval($g['group_id']); ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>"><i class="fas fa-fw fa-trash"></i> Delete</a>
                                <?php } ?>
                            </td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
                </div>
                <small class="text-muted">Addresses must be active agents or administrator-approved recipients. Deleting a group removes it from every schedule that used it.</small>
            </div>
        </div>
        <?php } else { ?>
        <div class="px-3 py-2"><span class="text-muted">You can see the schedules; adding, pausing or deleting one needs Reporting modify access.</span></div>
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
                            $delivery_label = ReportScheduler::usesDataPath($row) ? (['csv' => 'CSV link', 'pdf' => 'PDF attached'][$row['schedule_format']] ?? 'Tables in email') : 'Headline summary';
                            $row_targets = ReportScheduler::targets($mysqli, $schedule_id);
                            $frequency   = $valid_frequencies[$row['schedule_frequency']] ?? ucfirst((string) $row['schedule_frequency']);
                            $active      = intval($row['schedule_active']) === 1;
                            $last_sent   = !empty($row['schedule_last_sent']) ? nullable_htmlentities($row['schedule_last_sent']) : '<span class="text-muted">Never</span>';
                            $last_status = (string) ($row['schedule_last_status'] ?? '');
                            $may_change  = $can_manage_schedules && ($session_is_admin || ($row['schedule_owner_user_id'] !== null && intval($row['schedule_owner_user_id']) === intval($session_user_id)));
                            ?>
                        <tr>
                            <td><?php echo nullable_htmlentities($report_name); ?></td>
                            <td><?php echo nullable_htmlentities($delivery_label); ?></td>
                            <td><?php echo nullable_htmlentities($frequency); ?></td>
                            <td class="text-break">
                                <?php echo nullable_htmlentities($row['schedule_recipients']); ?>
                                <?php foreach ($row_targets as $rt) {
                                    $rt_view = !empty($rt['target_saved_report_id']) ? SavedReports::get($mysqli, intval($rt['target_saved_report_id'])) : null; ?>
                                    <div class="small"><i class="fas fa-fw fa-users text-muted"></i> <?php echo nullable_htmlentities($rt['group_name']); ?><?php echo $rt_view ? ' &rarr; ' . nullable_htmlentities($rt_view['saved_report_name']) : ''; ?></div>
                                <?php } ?>
                            </td>
                            <td><?php echo ($row['owner_name'] ?? '') !== '' ? nullable_htmlentities($row['owner_name']) : '<span class="text-muted" title="Created before owners were recorded; administrators only">No owner</span>'; ?></td>
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
                                <?php } else { ?><span class="text-muted">Not run yet</span><?php } ?>
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
