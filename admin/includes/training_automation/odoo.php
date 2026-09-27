<?php
/*
 * Odoo write-back cards (Training LMS Phase 5 spec §5.4 "Odoo card", S5/S8). ADMIN PAGE ONLY: included
 * by admin/includes/training_automation/sections.php inside its #odoo-writeback wrapper on
 * admin/settings_training.php (agent/training_settings.php shows Lane A's read-only summary instead).
 *
 * From sections.php: $ta (AutomationSettings::load()), $ta_version, $ta_target (?OdooSync\Target),
 * $ta_csrf, $ta_post_url ('post.php'), $ta_page_url, $ta_admin_page. Forms post ta_odoo_* keys to
 * admin/post.php -> settings_training_automation.php -> Settings\AutomationActions -> OdooSync\OdooAdmin.
 * A page view never calls Odoo (OdooCard reads the database only; "Preview next 10" is a dry run).
 *
 * Every value from Odoo or the database is echoed through nullable_htmlentities() or intval().
 * Card anchors: #odoo-writeback (the wrapper), #odoo-outbox, #odoo-send.
 */

defined('TRAINING_AUTOMATION_PAGE') || exit;

use ITFlow\Training\OdooSync\OdooCard;
use ITFlow\Training\OdooSync\PushService;
use ITFlow\Training\OdooSync\Target;

if (isset($ta_admin_page) && !$ta_admin_page) {
    return;   // never render the admin forms outside Admin > Training
}
$tao_csrf = (string) ($ta_csrf ?? ($_SESSION['csrf_token'] ?? ''));
$tao_post = (string) ($ta_post_url ?? 'post.php');
$tao_page = (string) ($ta_page_url ?? '/admin/settings_training.php');
$tao = ['ready' => false];
$tao_error = false;
try {
    $tao_target = isset($ta_target) ? $ta_target : Target::current($mysqli);
    $tao = OdooCard::build($mysqli, isset($ta) && is_array($ta) ? $ta : null, $tao_target instanceof Target ? $tao_target : null,
        ($_GET['ta_preview'] ?? '') === 'odoo');
} catch (\Throwable $e) {
    error_log('Training Odoo write-back card: ' . get_class($e) . ': ' . $e->getMessage());
    $tao_error = true;
}
$tao_disc = $tao['discovery'] ?? null;
$tao_t = $tao['target'] ?? null;
$tao_pause = $tao['would_pause'] ?? null;
?>

<div class="card mb-3">
    <div class="card-header py-3">
        <h3 class="card-title"><i class="fas fa-fw fa-cloud-upload-alt me-2" aria-hidden="true"></i>Odoo write-back</h3>
        <?php if ($tao['ready'] ?? false) { ?>
            <span class="badge <?php echo nullable_htmlentities(($tao['enabled'] ?? false) ? 'text-bg-success' : 'text-bg-secondary'); ?> ms-auto"><?php echo nullable_htmlentities(($tao['enabled'] ?? false) ? 'ON' : 'OFF'); ?></span>
        <?php } ?>
    </div>
    <div class="card-body">
<?php if ($tao_error) { ?>
        <div class="alert alert-danger mb-0">The Odoo write-back status could not be loaded. The details were written to the server error log.</div>
<?php } elseif (!($tao['ready'] ?? false)) { ?>
        <p class="text-muted mb-0">Run the database update to use Odoo write-back (Admin &rsaquo; Update).</p>
<?php } else { ?>
        <p class="small text-muted">
            Copies each training record to the employee's Odoo résumé as a line: course, date, certificate number, how it was recorded and expiry.
            ITFlow stays the record of truth. Nothing is ever deleted in Odoo; a voided record gets an end date and "(revoked)".
        </p>

        <dl class="row mb-3">
            <dt class="col-sm-3">Odoo</dt>
            <dd class="col-sm-9">
                <?php if ($tao_t === null) { ?>
                    <span class="text-muted">No enabled Odoo integration.</span>
                    <a href="/admin/settings_integrations.php?tab=directorysync">Set one up under Integrations</a>
                <?php } else { ?>
                    <span class="text-break"><?php echo nullable_htmlentities($tao_t['host']); ?></span>
                    <span class="text-muted">/</span>
                    <span class="font-monospace text-break"><?php echo nullable_htmlentities($tao_t['database']); ?></span>
                    <span class="badge text-bg-light ms-1"><?php echo nullable_htmlentities($tao_t['protocol']); ?></span>
                    <?php if ($tao_t['staging']) { ?><span class="badge text-bg-danger ms-1">STAGING</span><?php } else { ?><span class="badge text-bg-primary ms-1">Production</span><?php } ?>
                    <?php if (!$tao_t['https']) { ?><span class="badge text-bg-danger ms-1">Not https</span><?php } ?>
                <?php } ?>
            </dd>
            <dt class="col-sm-3">Status</dt>
            <dd class="col-sm-9">
                <?php if (!($tao['enabled'] ?? false)) { ?>
                    <span class="text-muted">Off. Nothing is sent to Odoo.</span>
                <?php } elseif ($tao_pause !== null) { ?>
                    <span class="badge text-bg-warning">Paused</span>
                    <span class="small"><?php echo nullable_htmlentities(PushService::describePause((string) $tao_pause)); ?></span>
                <?php } elseif (!empty($tao['paused_reason'])) { ?>
                    <span class="badge text-bg-warning">Paused</span>
                    <span class="small"><?php echo nullable_htmlentities(PushService::describePause((string) $tao['paused_reason'])); ?></span>
                <?php } else { ?>
                    <span class="badge text-bg-success">Sending</span>
                    <span class="small text-muted">Every 10 minutes (worker).</span>
                <?php } ?>
            </dd>
            <dt class="col-sm-3">Last run</dt>
            <dd class="col-sm-9">
                <?php if (($tao['last_run'] ?? null) === null) { ?>
                    <span class="text-muted">Never</span>
                <?php } else { ?>
                    <?php echo nullable_htmlentities($tao['last_run']); ?>
                    <span class="font-monospace small ms-1 text-break"><?php echo nullable_htmlentities((string) ($tao['last_result'] ?? '')); ?></span>
                <?php } ?>
            </dd>
            <?php if (is_array($tao['counts'] ?? null)) { ?>
                <dt class="col-sm-3">Outbox</dt>
                <dd class="col-sm-9 d-flex flex-wrap gap-1">
                    <?php foreach (['pending' => 'text-bg-secondary', 'held' => 'text-bg-warning', 'done' => 'text-bg-success', 'failed' => 'text-bg-warning', 'dead' => 'text-bg-danger', 'skipped' => 'text-bg-light'] as $tao_st => $tao_cls) { ?>
                        <span class="badge <?php echo nullable_htmlentities($tao_cls); ?>"><?php echo intval($tao['counts'][$tao_st] ?? 0); ?> <?php echo nullable_htmlentities($tao_st); ?></span>
                    <?php } ?>
                </dd>
            <?php } ?>
        </dl>

        <!-- Check Odoo (read-only) -->
        <?php if ($tao_t !== null) { ?>
        <form action="<?php echo nullable_htmlentities($tao_post); ?>" method="post" class="mb-3">
            <input type="hidden" name="csrf_token" value="<?php echo nullable_htmlentities($tao_csrf); ?>">
            <button type="submit" name="ta_odoo_discover" class="btn btn-outline-primary btn-sm"><i class="fas fa-fw fa-search me-1" aria-hidden="true"></i>Check Odoo (read-only)</button>
            <span class="small text-muted ms-2">Reads what this Odoo offers for résumé lines. Writes nothing.</span>
        </form>
        <?php } ?>

        <?php if ($tao_disc === null) { ?>
            <p class="small text-muted">Odoo has not been checked yet.</p>
        <?php } else { ?>
            <?php if ($tao['discovery_other_target'] ?? false) { ?>
                <div class="alert alert-warning py-2 small">Checked against a different Odoo (<?php echo nullable_htmlentities((string) ($tao_disc['target']['base_url'] ?? '')); ?> / <?php echo nullable_htmlentities((string) ($tao_disc['target']['database'] ?? '')); ?>); check again.</div>
            <?php } ?>
            <div class="border rounded p-2 mb-3 small">
                <div class="fw-bold mb-1">Last check <?php echo nullable_htmlentities((string) ($tao['discovered_at'] ?? '')); ?>
                    <?php if (!empty($tao_disc['server_version'])) { ?><span class="text-muted fw-normal">&middot; Odoo <?php echo nullable_htmlentities((string) $tao_disc['server_version']); ?></span><?php } ?></div>
                <div>Résumé lines:
                    <?php if (!empty($tao_disc['resume']['available'])) { ?>
                        <span class="text-success fw-bold">available</span>
                        &middot; Types:
                        <?php foreach ((array) ($tao_disc['resume']['types'] ?? []) as $tao_ty) { ?>
                            <span class="badge text-bg-light"><?php echo nullable_htmlentities((string) ($tao_ty['name'] ?? '')); ?><?php if (($tao_ty['id'] ?? null) === ($tao_disc['resume']['suggested_type_id'] ?? false)) { ?> &check;<?php } ?></span>
                        <?php } ?>
                    <?php } else { ?>
                        <span class="text-danger fw-bold">not available</span>
                    <?php } ?>
                </div>
                <div>Certification skills:
                    <?php if (!empty($tao_disc['skill']['cert_types'])) { ?>
                        <?php echo intval(count($tao_disc['skill']['cert_types'])); ?> set up in Odoo (not used yet)
                    <?php } else { ?>
                        none set up in Odoo (optional, later)
                    <?php } ?>
                </div>
                <div>Employees with Odoo users:
                    <?php if (($tao_disc['gamification']['employees_with_users'] ?? null) === null) { ?>unknown<?php } else { echo intval($tao_disc['gamification']['employees_with_users']); } ?>
                    (badges not used)</div>
                <?php if (!empty($tao_disc['errors'])) { ?>
                    <details class="mt-1"><summary class="text-warning">Some checks failed (<?php echo intval(count($tao_disc['errors'])); ?>)</summary>
                        <ul class="mb-0">
                            <?php foreach ((array) $tao_disc['errors'] as $tao_err) { ?><li class="text-break"><?php echo nullable_htmlentities((string) $tao_err); ?></li><?php } ?>
                        </ul>
                    </details>
                <?php } ?>
            </div>
        <?php } ?>

        <p class="small mb-3">
            <i class="fas fa-fw fa-link me-1" aria-hidden="true"></i>Employee links last checked
            <?php if (!($tao['links']['available'] ?? false)) { ?>
                <span class="text-muted">(the employee link check is not available)</span>
            <?php } elseif (($tao['links']['checked_at'] ?? null) === null) { ?>
                <span class="text-muted">never</span>
            <?php } else { ?>
                <?php echo nullable_htmlentities((string) $tao['links']['checked_at']); ?>
            <?php } ?>
            &mdash;
            <?php if ($tao['links']['after_discovery'] ?? false) { ?>
                <span class="text-success">ok (after the last Odoo check)</span>
            <?php } elseif ($tao_t !== null && $tao_t['staging']) { ?>
                <span class="text-muted">not required on a staging Odoo</span>
            <?php } else { ?>
                <span class="text-danger">required before enabling on production</span>
            <?php } ?>
            &middot; <a href="#odoo">Employee links (Odoo)</a>
        </p>

        <?php if ($tao_disc !== null && !($tao['discovery_other_target'] ?? false) && $tao_t !== null) {
            $tao_types = array_values(array_filter((array) ($tao_disc['resume']['types'] ?? []), static fn($x) => ($x['is_course'] ?? null) !== false));
            $tao_sel = $tao['settings']['resume_type_id'] ?? ($tao_disc['resume']['suggested_type_id'] ?? null);
            $tao_asel = $tao['settings']['award_type_id'] ?? null; ?>
        <form action="<?php echo nullable_htmlentities($tao_post); ?>" method="post" autocomplete="off" data-ts-label="Odoo write-back">
            <input type="hidden" name="csrf_token" value="<?php echo nullable_htmlentities($tao_csrf); ?>">
            <input type="hidden" name="version" value="<?php echo intval($ta_version ?? ($tao['version'] ?? 0)); ?>">
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label" for="taoMode">Mode</label>
                    <select class="form-select" id="taoMode" name="mode">
                        <option value="resume" selected>Résumé line</option>
                        <option value="skill" disabled>Certification skill (later)</option>
                        <option value="note" disabled>HR note (later)</option>
                    </select>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label" for="taoType">Line type for training records</label>
                    <select class="form-select" id="taoType" name="resume_type_id">
                        <option value="">Choose&hellip;</option>
                        <?php foreach ($tao_types as $tao_ty) { ?>
                            <option value="<?php echo intval($tao_ty['id']); ?>" <?php if ((int) $tao_sel === (int) $tao_ty['id']) { echo 'selected'; } ?>><?php echo nullable_htmlentities((string) $tao_ty['name']); ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label" for="taoAwardType">Line type for achievements</label>
                    <select class="form-select" id="taoAwardType" name="award_type_id">
                        <option value="">Same as training records</option>
                        <?php foreach ($tao_types as $tao_ty) { ?>
                            <option value="<?php echo intval($tao_ty['id']); ?>" <?php if ((int) $tao_asel === (int) $tao_ty['id']) { echo 'selected'; } ?>><?php echo nullable_htmlentities((string) $tao_ty['name']); ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label" for="taoSince">Send records recorded on or after</label>
                    <input type="date" class="form-control" id="taoSince" name="push_since" value="<?php echo nullable_htmlentities((string) ($tao['settings']['push_since'] ?? date('Y-m-d'))); ?>" max="<?php echo nullable_htmlentities(date('Y-m-d')); ?>">
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label" for="taoKeyExp">Odoo key expires on <span class="text-muted small">(optional)</span></label>
                    <input type="date" class="form-control" id="taoKeyExp" name="key_expires_on" value="<?php echo nullable_htmlentities((string) ($tao['settings']['key_expires_on'] ?? '')); ?>">
                    <div class="form-text">Admins are warned 14 days before.</div>
                </div>
                <div class="col-md-4 mb-3 d-flex align-items-end">
                    <div class="form-check form-switch">
                        <input type="checkbox" class="form-check-input" id="taoAwards" name="push_awards" value="1" <?php if ($tao['settings']['push_awards'] ?? false) { echo 'checked'; } ?>>
                        <label class="form-check-label" for="taoAwards">Send achievements (the ones switched on below)</label>
                    </div>
                </div>
            </div>
            <div class="border rounded p-2 mb-3">
                <div class="form-check form-switch mb-1">
                    <input type="checkbox" class="form-check-input" id="taoEnabled" name="enabled" value="1" <?php if ($tao['enabled'] ?? false) { echo 'checked'; } ?>>
                    <label class="form-check-label fw-bold" for="taoEnabled">Enable write-back</label>
                </div>
                <?php if ($tao_t['staging']) { ?>
                    <div class="form-check ms-4">
                        <input type="checkbox" class="form-check-input" id="taoStagingAck" name="staging_ack" value="1">
                        <label class="form-check-label" for="taoStagingAck">I understand this writes to the <strong>STAGING</strong> Odoo</label>
                    </div>
                <?php } ?>
            </div>
            <p class="small text-muted mb-1"><i class="fas fa-fw fa-clipboard-list me-1" aria-hidden="true"></i>Before production: create the ITFlow Integration bot (Employees: Officer), point the integration at production, Check Odoo, run Check now under Employee links (Odoo), then confirm here (plan A22).</p>
            <p class="small text-muted"><i class="fas fa-fw fa-eye me-1" aria-hidden="true"></i>Odoo is a copy. Every Odoo user can read these lines, and employees with Odoo logins can edit their own.</p>
            <button type="submit" name="ta_odoo_save" class="btn btn-primary"><i class="fas fa-check me-2" aria-hidden="true"></i>Save Odoo write-back</button>
        </form>
        <?php } elseif ($tao_t !== null) { ?>
            <p class="small text-muted mb-0">Run <strong>Check Odoo</strong> first; the settings appear once this Odoo has been checked.</p>
        <?php } ?>
<?php } ?>
    </div>
</div>

<?php if (($tao['ready'] ?? false) && is_array($tao['counts'] ?? null)) { ?>
<div class="card mb-3" id="odoo-outbox">
    <div class="card-header py-3">
        <h3 class="card-title"><i class="fas fa-fw fa-inbox me-2" aria-hidden="true"></i>Odoo outbox</h3>
        <div class="card-actions d-flex gap-2">
            <a class="btn btn-outline-secondary btn-sm" href="<?php echo nullable_htmlentities($tao_page); ?>?ta_preview=odoo#odoo-outbox"><i class="fas fa-fw fa-eye me-1" aria-hidden="true"></i>Preview next 10</a>
            <?php if (intval($tao['counts']['failed'] ?? 0) + intval($tao['counts']['dead'] ?? 0) > 0) { ?>
                <form action="<?php echo nullable_htmlentities($tao_post); ?>" method="post" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?php echo nullable_htmlentities($tao_csrf); ?>">
                    <button type="submit" name="ta_odoo_retry_failed" class="btn btn-outline-primary btn-sm">Retry all failed</button>
                </form>
            <?php } ?>
        </div>
    </div>
    <div class="card-body">
        <?php if (is_array($tao['preview'] ?? null)) { ?>
            <div class="fw-bold mb-1">Next to send <span class="text-muted small fw-normal">(dry run: nothing was sent and nothing was queued)</span></div>
            <?php if (!$tao['preview']) { ?>
                <p class="text-muted small">Nothing is waiting to be sent.</p>
            <?php } else { ?>
            <div class="table-responsive mb-3">
                <table class="table table-sm table-vcenter mb-0">
                    <thead><tr><th>Person</th><th>Odoo employee</th><th>Record</th><th>Dates</th><th>Marker</th></tr></thead>
                    <tbody>
                    <?php foreach ($tao['preview'] as $tao_r) { ?>
                        <tr>
                            <td class="text-break"><?php echo nullable_htmlentities($tao_r['contact']); ?></td>
                            <td class="small">
                                <?php if ($tao_r['employee'] === null) { ?>
                                    <span class="badge text-bg-warning">no Odoo link</span>
                                <?php } else { ?>
                                    #<?php echo intval($tao_r['employee']['id']); ?>
                                    <?php if (in_array($tao_r['employee']['state'], ['repointed', 'mismatch', 'missing'], true) && !$tao_r['employee']['confirmed']) { ?>
                                        <span class="badge text-bg-warning">link <?php echo nullable_htmlentities($tao_r['employee']['state']); ?></span>
                                    <?php } ?>
                                <?php } ?>
                            </td>
                            <td class="small text-break">
                                <?php echo nullable_htmlentities((string) ($tao_r['course'] ?? $tao_r['source'])); ?>
                                <?php if ($tao_r['action'] === 'close') { ?><span class="badge text-bg-danger ms-1">revoke</span><?php } ?>
                                <?php if (!$tao_r['queued']) { ?><span class="badge text-bg-light ms-1">not queued yet</span><?php } ?>
                                <?php if (!empty($tao_r['cert_number'])) { ?><div class="font-monospace text-muted"><?php echo nullable_htmlentities((string) $tao_r['cert_number']); ?></div><?php } ?>
                            </td>
                            <td class="small text-nowrap">
                                <?php echo nullable_htmlentities((string) ($tao_r['completed_on'] ?? '')); ?>
                                <?php if (!empty($tao_r['expires_on'])) { ?>&rarr; <?php echo nullable_htmlentities((string) $tao_r['expires_on']); ?><?php } ?>
                            </td>
                            <td class="small font-monospace text-break"><?php echo nullable_htmlentities($tao_r['marker']); ?></td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
            <?php } ?>
        <?php } ?>

        <div class="fw-bold mb-1">Needs attention <span class="text-muted small fw-normal">(latest 20 failed, dead or held)</span></div>
        <?php if (!$tao['problems']) { ?>
            <p class="text-muted small mb-0">Nothing needs attention.</p>
        <?php } else { ?>
        <div class="table-responsive">
            <table class="table table-sm table-vcenter mb-0">
                <thead><tr><th>Person</th><th>Record</th><th>State</th><th>Error</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($tao['problems'] as $tao_r) { ?>
                    <tr>
                        <td class="text-break"><?php echo nullable_htmlentities($tao_r['contact']); ?></td>
                        <td class="small text-break">
                            <?php if ($tao_r['record_link'] !== null) { ?>
                                <a href="<?php echo nullable_htmlentities($tao_r['record_link']); ?>"><?php echo nullable_htmlentities((string) ($tao_r['course'] ?? $tao_r['source'])); ?></a>
                            <?php } else { ?>
                                <?php echo nullable_htmlentities($tao_r['source']); ?>
                            <?php } ?>
                            <?php if ($tao_r['action'] === 'close') { ?><span class="badge text-bg-danger ms-1">revoke</span><?php } ?>
                        </td>
                        <td class="small">
                            <?php if ($tao_r['held']) { ?>
                                <span class="badge text-bg-warning">held</span>
                            <?php } else { ?>
                                <span class="badge <?php echo nullable_htmlentities($tao_r['status'] === 'dead' ? 'text-bg-danger' : 'text-bg-warning'); ?>"><?php echo nullable_htmlentities((string) $tao_r['status']); ?></span>
                            <?php } ?>
                            <div class="text-muted"><?php echo intval($tao_r['attempts']); ?> attempt(s)</div>
                        </td>
                        <td class="small text-break"><?php echo nullable_htmlentities((string) ($tao_r['error'] ?? '')); ?></td>
                        <td class="text-end">
                            <div class="d-flex flex-wrap justify-content-end gap-1">
                                <form action="<?php echo nullable_htmlentities($tao_post); ?>" method="post" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo nullable_htmlentities($tao_csrf); ?>">
                                    <input type="hidden" name="todoo_id" value="<?php echo intval($tao_r['id']); ?>">
                                    <button type="submit" name="ta_odoo_retry" class="btn btn-outline-primary btn-sm">Retry</button>
                                </form>
                                <details class="text-start">
                                    <summary class="btn btn-outline-secondary btn-sm">Skip&hellip;</summary>
                                    <form action="<?php echo nullable_htmlentities($tao_post); ?>" method="post" class="mt-2 small">
                                        <input type="hidden" name="csrf_token" value="<?php echo nullable_htmlentities($tao_csrf); ?>">
                                        <input type="hidden" name="todoo_id" value="<?php echo intval($tao_r['id']); ?>">
                                        <label class="form-label" for="taoSkip<?php echo intval($tao_r['id']); ?>">Reason</label>
                                        <input type="text" class="form-control form-control-sm mb-1" id="taoSkip<?php echo intval($tao_r['id']); ?>" name="reason" required minlength="3" maxlength="255">
                                        <button type="submit" name="ta_odoo_skip" class="btn btn-secondary btn-sm">Skip for good</button>
                                    </form>
                                </details>
                            </div>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
        <?php } ?>
    </div>
</div>

<div class="card mb-3" id="odoo-send">
    <div class="card-header py-3">
        <h3 class="card-title"><i class="fas fa-fw fa-toggle-on me-2" aria-hidden="true"></i>Send to Odoo</h3>
    </div>
    <div class="card-body">
        <p class="small text-muted">Every training course is sent unless you turn it off. Achievements are sent only when you turn them on (and "Send achievements" is on above). Acknowledgment documents are never sent.</p>
        <details class="mb-2">
            <summary>Courses (<?php echo intval(count($tao['courses'])); ?>, <?php echo intval(count(array_filter($tao['courses'], static fn($x) => (int) $x['push'] === 0))); ?> turned off)</summary>
            <?php if (!$tao['courses']) { ?>
                <p class="text-muted small mt-2 mb-0">No training courses yet.</p>
            <?php } else { ?>
            <div class="table-responsive mt-2" style="max-height: 420px;">
                <table class="table table-sm table-vcenter mb-0">
                    <thead><tr><th>Course</th><th>Sent to Odoo</th><th class="text-end"></th></tr></thead>
                    <tbody>
                    <?php foreach ($tao['courses'] as $tao_c) { $tao_on = (int) $tao_c['push'] === 1; ?>
                        <tr>
                            <td class="text-break"><?php echo nullable_htmlentities((string) $tao_c['name']); ?><?php if (!empty($tao_c['code'])) { ?> <span class="text-muted small font-monospace"><?php echo nullable_htmlentities((string) $tao_c['code']); ?></span><?php } ?></td>
                            <td><span class="badge <?php echo nullable_htmlentities($tao_on ? 'text-bg-success' : 'text-bg-secondary'); ?>"><?php echo nullable_htmlentities($tao_on ? 'Yes' : 'No'); ?></span></td>
                            <td class="text-end">
                                <form action="<?php echo nullable_htmlentities($tao_post); ?>" method="post" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo nullable_htmlentities($tao_csrf); ?>">
                                    <input type="hidden" name="entity" value="course">
                                    <input type="hidden" name="entity_id" value="<?php echo intval($tao_c['id']); ?>">
                                    <input type="hidden" name="push" value="<?php echo nullable_htmlentities($tao_on ? '0' : '1'); ?>">
                                    <button type="submit" name="ta_odoo_map" class="btn btn-sm <?php echo nullable_htmlentities($tao_on ? 'btn-outline-secondary' : 'btn-outline-primary'); ?>"><?php echo nullable_htmlentities($tao_on ? 'Turn off' : 'Send to Odoo'); ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
            <?php } ?>
        </details>
        <details>
            <summary>Achievements (<?php echo intval(count($tao['achievements'])); ?>, <?php echo intval(count(array_filter($tao['achievements'], static fn($x) => (int) $x['push'] === 1))); ?> sent)</summary>
            <?php if (!$tao['achievements']) { ?>
                <p class="text-muted small mt-2 mb-0">No achievements yet.</p>
            <?php } else { ?>
            <div class="table-responsive mt-2" style="max-height: 420px;">
                <table class="table table-sm table-vcenter mb-0">
                    <thead><tr><th>Achievement</th><th>Sent to Odoo</th><th class="text-end"></th></tr></thead>
                    <tbody>
                    <?php foreach ($tao['achievements'] as $tao_a) { $tao_on = (int) $tao_a['push'] === 1; ?>
                        <tr>
                            <td class="text-break"><?php echo nullable_htmlentities((string) $tao_a['name']); ?></td>
                            <td><span class="badge <?php echo nullable_htmlentities($tao_on ? 'text-bg-success' : 'text-bg-secondary'); ?>"><?php echo nullable_htmlentities($tao_on ? 'Yes' : 'No'); ?></span></td>
                            <td class="text-end">
                                <form action="<?php echo nullable_htmlentities($tao_post); ?>" method="post" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo nullable_htmlentities($tao_csrf); ?>">
                                    <input type="hidden" name="entity" value="achievement">
                                    <input type="hidden" name="entity_id" value="<?php echo intval($tao_a['id']); ?>">
                                    <input type="hidden" name="push" value="<?php echo nullable_htmlentities($tao_on ? '0' : '1'); ?>">
                                    <button type="submit" name="ta_odoo_map" class="btn btn-sm <?php echo nullable_htmlentities($tao_on ? 'btn-outline-secondary' : 'btn-outline-primary'); ?>"><?php echo nullable_htmlentities($tao_on ? 'Turn off' : 'Send to Odoo'); ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
            <?php } ?>
        </details>
    </div>
</div>
<?php } ?>
