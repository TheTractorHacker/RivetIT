<?php
defined('TRAINING_AUTOMATION_PAGE') || exit;

/*
 * Training settings › Reminders card (Phase 5 S4, spec §5.4). Included by the one Training
 * settings page (admin/settings_training.php) and its Training-3 twin (agent/training_settings.php)
 * when this file exists; the including page has already checked who may see it. Reminders are
 * Training-3 territory, so the card is editable on both pages.
 *
 * Inputs, from admin/includes/training_automation/sections.php (Lane A), which also wraps this card
 * in <div id="reminders"> (so the card itself carries no id):
 *   $ta           AutomationSettings::load() row ('ready' => false before the 2.6.96 update)
 *   $ta_version   tauto_version, posted as `version` (optimistic lock)
 *   $ta_csrf      the CSRF token        $ta_post_url  where forms post        $ta_page_url  this page (GET links)
 *   $ta_can_edit  optional; false renders the card read-only (default true: Training 3 may change it)
 *   $mysqli, $config_base_url   page globals
 * GET ?preview=reminders renders today's digests as a dry run (ReminderService, nothing is sent
 * or logged). POST ta_rem_save goes through Settings\AutomationActions to
 * ITFlow\Training\Reminders\ReminderAdmin. Every DB-derived string is echoed through
 * nullable_htmlentities(); numbers through intval().
 */

use ITFlow\Training\Automation\Notify;
use ITFlow\Training\Automation\WorkerCtx;
use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Reminders\ReminderService;

$ta_rem_action = isset($ta_post_url) && is_string($ta_post_url) && $ta_post_url !== '' ? $ta_post_url : 'post.php';
$ta_rem_page = isset($ta_page_url) && is_string($ta_page_url) && $ta_page_url !== '' ? $ta_page_url : '';
$ta_rem_csrf = isset($ta_csrf) && is_string($ta_csrf) ? $ta_csrf : (string) ($_SESSION['csrf_token'] ?? '');
$ta_rem_version = isset($ta_version) ? intval($ta_version) : intval($ta['tauto_version'] ?? 0);
$ta_rem_edit = !isset($ta_can_edit) || $ta_can_edit === true;
$ta_rem_ready = !empty($ta['ready']);
$ta_rem_on = $ta_rem_ready && intval($ta['tauto_reminders_enabled'] ?? 0) === 1;
$ta_rem_days = ReminderService::weekdays((string) ($ta['tauto_reminder_weekdays'] ?? '1,2,3,4,5'));
$ta_rem_escalate = ReminderService::escalateDays($ta['tauto_escalate_after_days'] ?? 14);
$ta_rem_names = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
$ta_rem_long = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

$ta_rem_last = null;       // {date, people}
$ta_rem_preview = null;    // ReminderService::run() dry-run result
$ta_rem_preview_error = false;
if ($ta_rem_ready) {
    try {
        $ta_rem_last = Db::one($mysqli, "SELECT trem_date, COUNT(*) AS n FROM training_reminder_log WHERE trem_kind = 'digest'
            GROUP BY trem_date ORDER BY trem_date DESC LIMIT 1");
    } catch (\Throwable $e) {
        error_log('Training reminders card: ' . get_class($e) . ': ' . $e->getMessage());
    }
    if (($_GET['preview'] ?? '') === 'reminders') {
        try {
            $ta_rem_ctx = WorkerCtx::build($mysqli, (string) ($config_base_url ?? ''));
            $ta_rem_preview = (new ReminderService($ta_rem_ctx, new Notify($mysqli)))->run(Clock::todayLocal(), true, true);
        } catch (\Throwable $e) {
            error_log('Training reminders preview: ' . get_class($e) . ': ' . $e->getMessage());
            $ta_rem_preview_error = true;
        }
    }
}
$ta_rem_today_n = intval(date('N'));
?>
<div class="card mb-3">
    <div class="card-header py-3">
        <h3 class="card-title"><i class="fas fa-fw fa-bell me-2" aria-hidden="true"></i>Reminder digests</h3>
        <div class="card-actions">
            <?php if ($ta_rem_on) { ?>
                <span class="badge text-bg-success">On</span>
            <?php } else { ?>
                <span class="badge text-bg-secondary">Off</span>
            <?php } ?>
        </div>
    </div>
    <div class="card-body">
        <?php if (!$ta_rem_ready) { ?>
            <p class="text-muted mb-0">Run the database update (Admin &rsaquo; Update &rsaquo; <strong>Update Database</strong>) to use reminders.</p>
        <?php } else { ?>
            <p class="mb-2">
                Once a day, each person with Training access gets <strong>one in-app notification</strong> (the bell, and the phone app if they allow Training
                notifications) listing overdue, due-soon and renewal-due training for the departments they can see.
                Training managers and admins also get a separate note about people overdue more than <?php echo intval($ta_rem_escalate); ?> days.
                No email is sent.
            </p>
            <p class="small text-muted mb-3">
                <i class="fas fa-fw fa-info-circle me-1" aria-hidden="true"></i>Due-soon and renewal windows follow Training compliance settings.
                Only people with ITFlow accounts and department access receive digests: someone with no department rows gets none.
            </p>

            <form action="<?php echo nullable_htmlentities($ta_rem_action); ?>" method="post" autocomplete="off" data-ts-label="Reminders">
                <input type="hidden" name="csrf_token" value="<?php echo nullable_htmlentities($ta_rem_csrf); ?>">
                <input type="hidden" name="version" value="<?php echo intval($ta_rem_version); ?>">
                <fieldset <?php if (!$ta_rem_edit) { echo 'disabled'; } ?>>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" role="switch" name="enabled" value="1" id="taRemEnabled" <?php if ($ta_rem_on) { echo 'checked'; } ?>>
                        <label class="form-check-label" for="taRemEnabled">Send daily Training digests</label>
                    </div>
                    <div class="row">
                        <div class="col-lg-7 mb-3">
                            <span class="form-label d-block" id="taRemDaysLabel">Send on</span>
                            <div class="d-flex flex-wrap gap-3" role="group" aria-labelledby="taRemDaysLabel">
                                <?php foreach ($ta_rem_names as $ta_rem_n => $ta_rem_label) { ?>
                                    <div class="form-check mb-0">
                                        <input class="form-check-input" type="checkbox" name="weekdays[]" value="<?php echo intval($ta_rem_n); ?>" id="taRemDay<?php echo intval($ta_rem_n); ?>"
                                            <?php if (in_array($ta_rem_n, $ta_rem_days, true)) { echo 'checked'; } ?>>
                                        <label class="form-check-label" for="taRemDay<?php echo intval($ta_rem_n); ?>"><abbr title="<?php echo nullable_htmlentities($ta_rem_long[$ta_rem_n]); ?>"><?php echo nullable_htmlentities($ta_rem_label); ?></abbr></label>
                                    </div>
                                <?php } ?>
                            </div>
                            <div class="form-text">Digests go out after the daily Training worker runs (early morning).</div>
                        </div>
                        <div class="col-sm-6 col-lg-5 mb-3">
                            <label class="form-label" for="taRemEscalate">Tell Training managers about people overdue more than (days)</label>
                            <input type="number" class="form-control" id="taRemEscalate" name="escalate_after_days" min="1" max="180" step="1" required
                                   value="<?php echo intval($ta_rem_escalate); ?>">
                            <div class="form-text">1 to 180. Goes to Training 3 and admins only.</div>
                        </div>
                    </div>
                </fieldset>
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <?php if ($ta_rem_edit) { ?>
                        <button type="submit" name="ta_rem_save" value="1" class="btn btn-primary"><i class="fas fa-check me-2" aria-hidden="true"></i>Save reminders</button>
                    <?php } ?>
                    <a class="btn btn-outline-secondary" href="<?php echo nullable_htmlentities($ta_rem_page . '?preview=reminders#reminders'); ?>"><i class="fas fa-fw fa-eye me-1" aria-hidden="true"></i>Preview today's digests</a>
                    <span class="small text-muted ms-md-2">
                        Last digests sent:
                        <?php if ($ta_rem_last !== null) { ?>
                            <?php echo nullable_htmlentities(date('M j, Y', strtotime((string) $ta_rem_last['trem_date']))); ?>
                            (<?php echo intval($ta_rem_last['n']); ?> <?php echo intval($ta_rem_last['n']) === 1 ? 'person' : 'people'; ?>)
                        <?php } else { ?>
                            never
                        <?php } ?>
                    </span>
                </div>
                <?php if (!$ta_rem_edit) { ?>
                    <p class="small text-muted mt-2 mb-0"><i class="fas fa-fw fa-lock me-1" aria-hidden="true"></i>Read only. Ask a Training manager or an administrator to change this.</p>
                <?php } ?>
            </form>

            <?php if ($ta_rem_preview_error) { ?>
                <div class="alert alert-danger mt-3 mb-0">The preview could not be built. The details were written to the server error log.</div>
            <?php } elseif ($ta_rem_preview !== null) { ?>
                <div class="mt-3 border-top pt-3" id="reminders-preview">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                        <h4 class="h5 mb-0">Today's digests</h4>
                        <span class="badge text-bg-info"><i class="fas fa-fw fa-eye-slash me-1" aria-hidden="true"></i>Preview: nothing is sent</span>
                        <a class="small ms-auto" href="<?php echo nullable_htmlentities(($ta_rem_page !== '' ? $ta_rem_page : '?') . '#reminders'); ?>">Close preview</a>
                    </div>
                    <?php if (!$ta_rem_on) { ?>
                        <p class="small text-muted mb-2">Reminders are off, so none of these would go out today.</p>
                    <?php } elseif (!in_array($ta_rem_today_n, $ta_rem_days, true)) { ?>
                        <p class="small text-muted mb-2">Today is not a reminder day, so none of these would go out today.</p>
                    <?php } ?>
                    <?php if ($ta_rem_preview['state'] === 'unavailable') { ?>
                        <p class="text-muted mb-0">Training compliance is not available yet, so there is nothing to preview.</p>
                    <?php } elseif ($ta_rem_preview['users'] === []) { ?>
                        <p class="text-muted mb-0">Nobody has Training access yet.</p>
                    <?php } else { ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-vcenter mb-0">
                                <thead>
                                    <tr>
                                        <th scope="col">Person</th>
                                        <th scope="col">Digest</th>
                                        <th scope="col">Escalation</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($ta_rem_preview['users'] as $ta_rem_u) { ?>
                                        <tr>
                                            <td class="text-nowrap">
                                                <?php echo nullable_htmlentities($ta_rem_u['name']); ?>
                                                <div class="small text-muted"><?php echo $ta_rem_u['is_admin'] ? 'Admin' : 'Training ' . intval($ta_rem_u['level']); ?></div>
                                            </td>
                                            <?php if ($ta_rem_u['digest'] === null && $ta_rem_u['escalation'] === null) { ?>
                                                <td colspan="2" class="text-muted small"><?php echo nullable_htmlentities($ta_rem_u['note'] ?? 'Nothing to send'); ?></td>
                                            <?php } else { ?>
                                                <td class="small text-break"><?php echo $ta_rem_u['digest'] !== null ? nullable_htmlentities($ta_rem_u['digest']) : '<span class="text-muted">&mdash;</span>'; ?></td>
                                                <td class="small text-break">
                                                    <?php echo $ta_rem_u['escalation'] !== null ? nullable_htmlentities($ta_rem_u['escalation']) : '<span class="text-muted">&mdash;</span>'; ?>
                                                    <?php if (($ta_rem_u['note'] ?? null) !== null) { ?><div class="text-muted"><?php echo nullable_htmlentities($ta_rem_u['note']); ?></div><?php } ?>
                                                </td>
                                            <?php } ?>
                                        </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    <?php } ?>
                </div>
            <?php } ?>
        <?php } ?>
    </div>
</div>
