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

use ITFlow\Training\OdooSync\OdooAdmin;
use ITFlow\Training\OdooSync\OdooCard;
use ITFlow\Training\OdooSync\PushService;
use ITFlow\Training\OdooSync\Target;
use ITFlow\Training\OdooSync\Targets;

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
$tao_tpause = (array) ($tao['target_pauses'] ?? []);   // mode => reason: paused on its own, the other targets keep sending
?>

<div class="card mb-3">
    <div class="card-header py-3">
        <h3 class="card-title"><i class="fas fa-fw fa-cloud-upload-alt me-2" aria-hidden="true"></i>Odoo write-back</h3>
        <?php if ($tao['ready'] ?? false) { ?>
            <span class="badge <?php echo nullable_htmlentities(($tao['enabled'] ?? false) ? 'text-bg-success' : 'text-bg-secondary'); ?> ms-auto"><?php echo nullable_htmlentities(($tao['enabled'] ?? false) ? 'On' : 'Off'); ?></span>
        <?php } ?>
    </div>
    <div class="card-body">
<?php if ($tao_error) { ?>
        <div class="alert alert-danger mb-0">The Odoo write-back status could not be loaded. The details were written to the server error log.</div>
<?php } elseif (!($tao['ready'] ?? false)) { ?>
        <p class="text-muted mb-0">Run the database update to use Odoo write-back (Admin &rsaquo; Update).</p>
<?php } else { ?>
        <p class="small text-muted">
            Copies each training record to the employee in Odoo: course, date, certificate number, how it was recorded and expiry.
            Send it as a résumé line, a certification skill, an internal HR note, or any combination; each is sent, retried and revoked on its own.
            ITFlow stays the record of truth. Nothing is ever deleted in Odoo: a voided record's résumé line gets an end date and "(revoked)",
            its certification an end date, and its HR note a short follow-up note.
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
                <?php if (($tao['enabled'] ?? false) && $tao_tpause) { ?>
                    <?php foreach ($tao_tpause as $tao_pm => $tao_pr) {
                        if (count($tao_tpause) === 1 && (string) $tao_pr === (string) $tao_pause) { continue; }   // already said above ?>
                        <div class="small mt-1"><span class="badge text-bg-warning me-1"><?php echo nullable_htmlentities(Targets::label((string) $tao_pm)); ?> paused</span><?php echo nullable_htmlentities(PushService::describePause((string) $tao_pr)); ?>
                            <?php if (count($tao_tpause) < count((array) ($tao['targets'] ?? []))) { ?><span class="text-muted">The other targets keep sending, and voids still reach Odoo.</span><?php } ?></div>
                    <?php } ?>
                <?php } ?>
            </dd>
            <dt class="col-sm-3">Sent as</dt>
            <dd class="col-sm-9">
                <?php if (!($tao['targets'] ?? [])) { ?>
                    <span class="text-muted">Nothing chosen</span>
                <?php } else { ?>
                    <?php foreach ((array) $tao['targets'] as $tao_sm) { ?>
                        <span class="badge <?php echo nullable_htmlentities(isset($tao_tpause[$tao_sm]) && ($tao['enabled'] ?? false) ? 'text-bg-warning' : 'text-bg-light'); ?> me-1"><?php echo nullable_htmlentities(Targets::label((string) $tao_sm)); ?><?php if (isset($tao_tpause[$tao_sm]) && ($tao['enabled'] ?? false)) { ?> (paused)<?php } ?></span>
                    <?php } ?>
                <?php } ?>
            </dd>
            <dt class="col-sm-3">Last run</dt>
            <dd class="col-sm-9">
                <?php if (($tao['last_run'] ?? null) === null) { ?>
                    <span class="text-muted">Never</span>
                <?php } else { ?>
                    <?php echo nullable_htmlentities($tao['last_run']); ?>
                    <?php $tao_lr = \ITFlow\Training\Automation\ResultText::odoo($tao['last_result'] ?? null); ?>
                    <span class="small ms-1 text-break"><?php echo nullable_htmlentities($tao_lr['text']); ?></span>
                <?php } ?>
            </dd>
            <?php if (is_array($tao['counts'] ?? null)) { ?>
                <dt class="col-sm-3">Outbox</dt>
                <dd class="col-sm-9 d-flex flex-wrap gap-1">
                    <?php foreach (['pending' => 'text-bg-secondary', 'held' => 'text-bg-warning', 'done' => 'text-bg-success', 'failed' => 'text-bg-warning', 'dead' => 'text-bg-danger', 'skipped' => 'text-bg-light'] as $tao_st => $tao_cls) {
                        $tao_n = intval($tao['counts'][$tao_st] ?? 0);   // a zero count is grey, never a warning colour ?>
                        <span class="badge <?php echo nullable_htmlentities($tao_n === 0 ? 'text-bg-light text-muted' : $tao_cls); ?>"><?php echo intval($tao_n); ?> <?php echo nullable_htmlentities($tao_st); ?></span>
                    <?php } ?>
                </dd>
            <?php } ?>
        </dl>

        <!-- Check Odoo (read-only) -->
        <?php if ($tao_t !== null) { ?>
        <form action="<?php echo nullable_htmlentities($tao_post); ?>" method="post" class="mb-3">
            <input type="hidden" name="csrf_token" value="<?php echo nullable_htmlentities($tao_csrf); ?>">
            <button type="submit" name="ta_odoo_discover" class="btn btn-outline-primary btn-sm"<?php if (!$tao_t['https']) { echo ' disabled'; } ?>><i class="fas fa-fw fa-search me-1" aria-hidden="true"></i>Check Odoo (read-only)</button>
            <?php if (!$tao_t['https']) { ?>
            <span class="small text-danger ms-2">Needs an https:// Odoo address (the key would travel unencrypted). Change it under Integrations.</span>
            <?php } else { ?>
            <span class="small text-muted ms-2">Reads what this Odoo offers for résumé lines, certification skills and HR notes. Writes nothing.</span>
            <?php } ?>
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
                    <?php if (empty($tao_disc['skill']['available'])) { ?>
                        <span class="text-danger fw-bold">not available</span> <span class="text-muted">(the Odoo Skills app is not installed or not readable)</span>
                    <?php } elseif (!empty($tao_disc['skill']['cert_types'])) { ?>
                        <span class="text-success fw-bold">available</span> &middot;
                        <?php foreach ((array) $tao_disc['skill']['cert_types'] as $tao_ct) {
                            $tao_ctn = count(array_filter((array) ($tao_disc['skill']['skills'] ?? []), static fn($k) => (int) ($k['type_id'] ?? 0) === (int) ($tao_ct['id'] ?? 0))); ?>
                            <span class="badge text-bg-light"><?php echo nullable_htmlentities((string) ($tao_ct['name'] ?? '')); ?> (<?php echo intval($tao_ctn); ?> skill<?php echo nullable_htmlentities($tao_ctn === 1 ? '' : 's'); ?>)</span>
                        <?php } ?>
                    <?php } else { ?>
                        <span class="text-warning fw-bold">no certification type in Odoo yet</span>
                        <div class="text-muted">To send certifications, create one in Odoo: <strong>Employees &rsaquo; Configuration &rsaquo; Skill Types</strong> &rarr; New,
                            tick <strong>Certification</strong>, add one level such as <strong>Certified</strong> and at least one skill (Odoo requires one; a course name will do), save, then click <strong>Check Odoo</strong> again<?php if ($tao_t !== null && !$tao_t['staging']) { ?> and, on this production Odoo, <strong>Check now</strong> under Employee links (Odoo) before you save<?php } ?>.</div>
                    <?php } ?>
                </div>
                <div>HR notes:
                    <?php if (PushService::noteReady($tao_disc)) { ?>
                        <span class="text-success fw-bold">available</span> <span class="text-muted">(internal notes in the employee's chatter)</span>
                    <?php } else { ?>
                        <span class="text-danger fw-bold">not available</span> <span class="text-muted"><?php echo nullable_htmlentities(OdooAdmin::noteWhy($tao_disc, '(', ')')); ?></span>
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
            $tao_asel = $tao['settings']['award_type_id'] ?? null;
            $tao_on = array_fill_keys((array) ($tao['targets'] ?? ['resume']), true);
            $tao_tready = (bool) ($tao['targets_ready'] ?? false);
            // A target can be ticked when this Odoo offers it; one that is on stays changeable (so it can be switched off).
            $tao_skill_ok = !empty($tao_tready) && (isset($tao_on['skill']) || (!empty($tao_disc['skill']['available']) && !empty($tao_disc['skill']['cert_types'])));
            $tao_note_ok = !empty($tao_tready) && (isset($tao_on['note']) || !empty($tao['note_ready']));
            $tao_opts = (array) ($tao['skill_options'] ?? []);
            $tao_saved_pair = ($tao['settings']['skill_type_id'] ?? null) !== null && ($tao['settings']['skill_level_id'] ?? null) !== null
                ? intval($tao['settings']['skill_type_id']) . ':' . intval($tao['settings']['skill_level_id']) : '';
            // The saved type/level no longer offered by Odoo: show "Choose…" and say so - never quietly preselect another type.
            $tao_pair_lost = $tao_saved_pair !== '' && !in_array($tao_saved_pair, array_map('strval', array_column($tao_opts, 'value')), true);
            $tao_pair = $tao_pair_lost ? '' : $tao_saved_pair;
            if ($tao_saved_pair === '' && $tao_opts) {
                $tao_pair = (string) $tao_opts[0]['value'];   // never chosen yet: the first certification type's default level
            }
            $tao_lost_label = trim((string) ($tao['settings']['skill_label'] ?? '')); ?>
        <form action="<?php echo nullable_htmlentities($tao_post); ?>" method="post" autocomplete="off" data-ts-label="Odoo write-back">
            <input type="hidden" name="csrf_token" value="<?php echo nullable_htmlentities($tao_csrf); ?>">
            <input type="hidden" name="version" value="<?php echo intval($ta_version ?? ($tao['version'] ?? 0)); ?>">
            <input type="hidden" name="targets_form" value="1">
            <fieldset class="mb-3">
                <legend class="form-label float-none mb-1" style="font-size: inherit;">Send each training record to Odoo as</legend>
                <div class="d-flex flex-wrap column-gap-4 row-gap-1">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="taoSendResume" name="send_resume" value="1"<?php if (!$tao_tready || isset($tao_on['resume'])) { echo ' checked'; } ?><?php if (!$tao_tready) { echo ' disabled'; } ?>>
                        <label class="form-check-label" for="taoSendResume">Résumé line</label>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="taoSendSkill" name="send_skill" value="1"<?php if (isset($tao_on['skill'])) { echo ' checked'; } ?><?php if (!$tao_skill_ok) { echo ' disabled'; } ?>>
                        <label class="form-check-label" for="taoSendSkill">Certification skill</label><?php if ($tao_tready && !$tao_skill_ok && !empty($tao_disc['skill']['available'])) { ?> <span class="small text-muted">(no certification type in Odoo yet)</span><?php } ?>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="taoSendNote" name="send_note" value="1"<?php if (isset($tao_on['note'])) { echo ' checked'; } ?><?php if (!$tao_note_ok) { echo ' disabled'; } ?>>
                        <label class="form-check-label" for="taoSendNote">HR note (internal)</label><?php if ($tao_tready && !$tao_note_ok) { ?> <span class="small text-muted">(not available on this Odoo)</span><?php } ?>
                    </div>
                </div>
                <?php if (!$tao_tready) { ?>
                    <div class="form-text text-warning">Certification skills and HR notes need the database update (Admin &rsaquo; Update). Until then records are sent as résumé lines only.</div>
                <?php } else { ?>
                    <div class="form-text">Any combination. Each one is sent, retried and revoked on its own; a record is never sent twice to the same place.</div>
                <?php } ?>
            </fieldset>
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label" for="taoType">Résumé line type for training records</label>
                    <select class="form-select" id="taoType" name="resume_type_id">
                        <option value="">Choose&hellip;</option>
                        <?php foreach ($tao_types as $tao_ty) { ?>
                            <option value="<?php echo intval($tao_ty['id']); ?>" <?php if ((int) $tao_sel === (int) $tao_ty['id']) { echo 'selected'; } ?>><?php echo nullable_htmlentities((string) $tao_ty['name']); ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label" for="taoAwardType">Résumé line type for achievements</label>
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
                <div class="fw-semibold mb-1"><i class="fas fa-fw fa-award me-1" aria-hidden="true"></i>Certification skill</div>
                <?php if (empty($tao_disc['skill']['available'])) { ?>
                    <p class="small text-muted mb-0">This Odoo does not offer employee skills (the Skills app), so certifications cannot be sent.</p>
                <?php } elseif (!$tao_opts) { ?>
                    <?php if ($tao_pair_lost) { ?>
                        <div class="alert alert-warning py-2 small mb-2">The certification type and level saved here<?php if ($tao_lost_label !== '') { ?> (<strong><?php echo nullable_htmlentities($tao_lost_label); ?></strong>)<?php } ?> are not offered by this Odoo any more (archived or deleted in Odoo, or no longer marked Certification). New certifications wait; résumé lines and notes keep sending.</div>
                    <?php } ?>
                    <p class="small mb-0">Odoo has no certification skill type<?php echo $tao_pair_lost ? ' now' : ' yet'; ?>. In Odoo, open <strong>Employees &rsaquo; Configuration &rsaquo; Skill Types</strong>, click New,
                        tick <strong>Certification</strong>, add one level such as <strong>Certified</strong> and at least one skill (Odoo requires one), save,
                        then click <strong>Check Odoo</strong> above<?php if (!$tao_t['staging']) { ?> and, on this production Odoo, <strong>Check now</strong> under Employee links (Odoo) before you save<?php } ?>.</p>
                <?php } else { ?>
                    <label class="form-label small mb-1" for="taoSkillType">Certification type and level</label>
                    <select class="form-select<?php if ($tao_pair_lost) { echo ' is-invalid'; } ?>" id="taoSkillType" name="skill_type_level"<?php if ($tao_pair_lost) { echo ' aria-describedby="taoSkillLost"'; } ?>>
                        <option value=""<?php if ($tao_pair === '') { echo ' selected'; } ?>>Choose&hellip;</option>
                        <?php foreach ($tao_opts as $tao_o) { ?>
                            <option value="<?php echo nullable_htmlentities((string) $tao_o['value']); ?>"<?php if ((string) $tao_o['value'] === $tao_pair) { echo ' selected'; } ?>><?php echo nullable_htmlentities((string) $tao_o['label'] . ($tao_o['default'] ? ' (default level)' : '')); ?></option>
                        <?php } ?>
                    </select>
                    <?php if ($tao_pair_lost) { ?>
                        <div class="invalid-feedback d-block" id="taoSkillLost">The type and level saved here<?php if ($tao_lost_label !== '') { ?> (<strong><?php echo nullable_htmlentities($tao_lost_label); ?></strong>)<?php } ?> are not offered by this Odoo any more (archived or deleted in Odoo, or no longer marked Certification). New certifications wait until you choose one and save, or restore it in Odoo and click Check Odoo; résumé lines and notes keep sending. Courses stay mapped to their skills: pick a type that has them.</div>
                    <?php } ?>
                    <div class="form-text">Valid from = the completion date; valid to = the expiry (none when it does not expire). Only courses and achievements mapped to an Odoo skill under <a href="#odoo-send">Send to Odoo</a> are sent.</div>
                    <div class="form-text"><strong>A void</strong> ends the certification: its end date becomes the day before the void (as Odoo ends skills), or the void date when the record is voided on the day it started. Nothing is deleted.
                        Odoo has no "revoked" mark for a certification, so a voided one looks just like an expired one, and Odoo counts it as valid through its end date (one voided on the day it started still shows as valid until midnight).
                        Turn on <strong>HR note</strong> too, so the employee's chatter says it was voided.</div>
                <?php } ?>
            </div>
            <div class="border rounded p-2 mb-3 small">
                <div class="fw-semibold mb-1"><i class="fas fa-fw fa-sticky-note me-1" aria-hidden="true"></i>HR note</div>
                Posted in the employee's chatter as an <strong>internal note that goes to nobody</strong>: no one is e-mailed or notified (followers of the employee included), ITFlow's Odoo user does not start following the employee, and no out-of-office reply is triggered. Needs Odoo 19 or later.
                It says the course, completion date, certificate number, expiry and how it was recorded (no score, no link, no PDF).
                A void adds a short follow-up note naming the course; the first note is never changed.
            </div>
            <div class="border rounded p-2 mb-3">
                <div class="form-check form-switch mb-1">
                    <input type="checkbox" class="form-check-input" id="taoEnabled" name="enabled" value="1" <?php if ($tao['enabled'] ?? false) { echo 'checked'; } ?>>
                    <label class="form-check-label fw-bold" for="taoEnabled">Enable write-back</label>
                </div>
                <?php if ($tao_t['staging']) {
                    // Already acknowledged for this very Odoo (write-back on, same target): keep it ticked so an
                    // unrelated save (a new key-expiry date) is not refused. Untick it to be asked again.
                    $tao_acked = ($tao['enabled'] ?? false) && ($tao['confirmed_key'] ?? '') !== '' && hash_equals((string) $tao['confirmed_key'], (string) $tao_t['key']); ?>
                    <div class="form-check ms-4">
                        <input type="checkbox" class="form-check-input" id="taoStagingAck" name="staging_ack" value="1"<?php if ($tao_acked) { echo ' checked'; } ?>>
                        <label class="form-check-label" for="taoStagingAck">I understand this writes to the <strong>STAGING</strong> Odoo</label>
                        <?php if ($tao_acked) { ?><div class="form-text">Ticked when write-back was switched on for this Odoo.</div><?php } ?>
                    </div>
                <?php } ?>
            </div>
            <div class="small text-muted mb-2">
                <div class="fw-semibold"><i class="fas fa-fw fa-clipboard-list me-1" aria-hidden="true"></i>Before switching on for production Odoo</div>
                <ol class="mb-1 ps-4">
                    <li>In Odoo, create the ITFlow Integration user (Employees: Officer) and give its API key to the integration.</li>
                    <li>Point the Odoo integration at production (Integrations &rsaquo; Directory Sync).</li>
                    <li>Click <strong>Check Odoo</strong> above.</li>
                    <li>Run <strong>Check now</strong> under Employee links (Odoo) on this page (again after every <strong>Check Odoo</strong>, before you save).</li>
                    <li>Turn on <strong>Enable write-back</strong>, then <strong>Save Odoo write-back</strong>.</li>
                </ol>
            </div>
            <p class="small text-muted"><i class="fas fa-fw fa-eye me-1" aria-hidden="true"></i>Odoo is a copy, not evidence. Every Odoo user can read résumé lines, and employees with Odoo logins can edit or delete the line on their own résumé (for example remove "(revoked)" or change a date); HR officers can change certifications and notes. Check a record in ITFlow or with the certificate QR code.</p>
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
        <?php $tao_cbt = (array) ($tao['counts_by_target'] ?? []);
        $tao_show = array_values(array_filter(\ITFlow\Training\OdooSync\Targets::MODES, static fn($m) => in_array($m, (array) ($tao['targets'] ?? []), true)
            || array_sum((array) ($tao_cbt[$m] ?? [])) > 0)); ?>
        <?php if ($tao_show) { ?>
        <div class="table-responsive mb-3">
            <table class="table table-sm table-vcenter mb-0 small" aria-label="Outbox by target">
                <thead><tr><th>Sent as</th><th class="text-end">Waiting</th><th class="text-end">Held</th><th class="text-end">Sent</th><th class="text-end">Retrying</th><th class="text-end">Could not send</th><th class="text-end">Skipped</th></tr></thead>
                <tbody>
                <?php foreach ($tao_show as $tao_m) { $tao_c = (array) ($tao_cbt[$tao_m] ?? []); ?>
                    <tr>
                        <td><?php echo nullable_htmlentities(\ITFlow\Training\OdooSync\Targets::label($tao_m)); ?><?php if (!in_array($tao_m, (array) ($tao['targets'] ?? []), true)) { ?> <span class="badge text-bg-light text-muted">off</span><?php } ?></td>
                        <td class="text-end"><?php echo intval(($tao_c['pending'] ?? 0) + ($tao_c['running'] ?? 0)); ?></td>
                        <td class="text-end"><?php echo intval($tao_c['held'] ?? 0); ?></td>
                        <td class="text-end"><?php echo intval($tao_c['done'] ?? 0); ?></td>
                        <td class="text-end"><?php echo intval($tao_c['failed'] ?? 0); ?></td>
                        <td class="text-end"><?php echo intval($tao_c['dead'] ?? 0); ?></td>
                        <td class="text-end"><?php echo intval($tao_c['skipped'] ?? 0); ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
        <?php } ?>
        <?php if (!empty($tao['unmapped'])) { ?>
            <div class="alert alert-info py-2 small">
                <div><i class="fas fa-fw fa-info-circle me-1" aria-hidden="true"></i><strong>Not sent as certification skills</strong> (no Odoo skill mapped to the course or achievement on this Odoo):
                <?php $tao_um = []; foreach ((array) $tao['unmapped'] as $tao_u) { $tao_um[] = nullable_htmlentities((string) $tao_u['course_name']) . ' (' . intval($tao_u['n']) . ')'; } echo implode(', ', $tao_um); ?>.
                Map a skill under <a href="#odoo-send">Send to Odoo</a>; those records are queued on the next run. Their résumé lines and notes are not affected.</div>
            </div>
        <?php } ?>
        <?php if (is_array($tao['preview'] ?? null)) { ?>
            <div class="fw-bold mb-1">Next to send <span class="text-muted small fw-normal">(dry run: nothing was sent and nothing was queued)</span></div>
            <?php if (!$tao['preview']) { ?>
                <p class="text-muted small">Nothing is waiting to be sent.</p>
            <?php } else { ?>
            <div class="table-responsive mb-3">
                <table class="table table-sm table-vcenter mb-0">
                    <thead><tr><th>Person</th><th>Odoo employee</th><th>Record</th><th>Sent as</th><th>Dates</th><th>Marker</th></tr></thead>
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
                            <td class="small"><?php echo nullable_htmlentities((string) ($tao_r['mode_label'] ?? '')); ?></td>
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

        <div class="fw-bold mb-1">Needs attention <span class="text-muted small fw-normal">(latest 20 failed, dead or held, and certifications skipped for a missing skill)</span></div>
        <?php if (!$tao['problems']) { ?>
            <p class="text-muted small mb-0">Nothing needs attention.</p>
        <?php } else { ?>
        <div class="table-responsive">
            <table class="table table-sm table-vcenter mb-0">
                <thead><tr><th>Person</th><th>Record</th><th>Sent as</th><th>State</th><th>Error</th><th class="text-end">Actions</th></tr></thead>
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
                        <td class="small"><?php echo nullable_htmlentities((string) ($tao_r['mode_label'] ?? '')); ?></td>
                        <td class="small">
                            <?php // the same words as the counts table: Held, Retrying, Could not send, Skipped
                            if ($tao_r['held']) { ?>
                                <span class="badge text-bg-warning">Held</span>
                            <?php } else { ?>
                                <span class="badge <?php echo nullable_htmlentities($tao_r['status'] === 'dead' ? 'text-bg-danger' : ($tao_r['status'] === 'skipped' ? 'text-bg-secondary' : 'text-bg-warning')); ?>"><?php echo nullable_htmlentities(match ((string) $tao_r['status']) { 'dead' => 'Could not send', 'failed' => 'Retrying', 'skipped' => 'Skipped', default => ucfirst((string) $tao_r['status']) }); ?></span>
                            <?php } ?>
                            <div class="text-muted"><?php echo intval($tao_r['attempts']); ?> attempt(s)</div>
                        </td>
                        <td class="small text-break"><?php echo nullable_htmlentities((string) ($tao_r['error'] ?? '')); ?>
                            <?php if (!empty($tao_r['overlap'])) { ?>
                                <div class="text-success mt-1"><i class="fas fa-fw fa-check me-1" aria-hidden="true"></i>Odoo already shows this certification for this employee, so nothing needs fixing. Use <strong>Skip&hellip;</strong> to clear this row.</div>
                            <?php } ?>
                        </td>
                        <td class="text-end">
                            <div class="d-flex flex-wrap justify-content-end gap-1">
                                <?php if (empty($tao_r['overlap'])) { // Odoo refuses an identical certification: a retry cannot succeed ?>
                                <form action="<?php echo nullable_htmlentities($tao_post); ?>" method="post" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo nullable_htmlentities($tao_csrf); ?>">
                                    <input type="hidden" name="todoo_id" value="<?php echo intval($tao_r['id']); ?>">
                                    <button type="submit" name="ta_odoo_retry" class="btn btn-outline-primary btn-sm">Retry</button>
                                </form>
                                <?php } ?>
                                <?php if ($tao_r['status'] !== 'skipped') { ?>
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
                                <?php } ?>
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
        <?php $tao_cfg = $tao['skill_cfg'] ?? null;
        $tao_skills = is_array($tao_cfg) ? (array) $tao_cfg['skills'] : [];
        asort($tao_skills, SORT_NATURAL | SORT_FLAG_CASE); ?>
        <p class="small text-muted">Every training course is sent unless you turn it off. Achievements are sent only when you turn them on (and "Send achievements" is on above). Acknowledgment documents are never sent.</p>
        <p class="small text-muted mb-2">
            <i class="fas fa-fw fa-award me-1" aria-hidden="true"></i><strong>Certification skill:</strong>
            <?php if (!is_array($tao_cfg)) { ?>
                choose and save the certification type and level above first (after Check Odoo); then map each course to an Odoo skill here.
            <?php } else { ?>
                only courses and achievements mapped to an Odoo skill of the chosen certification type are sent as certifications.
                Pick a skill found by the last Check Odoo, or use <em>Create in Odoo</em> to add a skill named after the course (this writes to Odoo).
            <?php } ?>
        </p>
<?php
/** One row's certification cell: the mapping form, and the explicit "Create in Odoo" form. */
$tao_skill_cell = static function (string $entity, array $e) use ($tao_cfg, $tao_skills, $tao_post, $tao_csrf): void {
    $m = (array) ($e['skill'] ?? ['state' => 'none', 'skill_id' => null, 'name' => null]);
    if (!is_array($tao_cfg)) {
        if ($m['state'] !== 'none') { ?><span class="small text-muted">Odoo skill #<?php echo intval($m['skill_id']); ?></span><?php }
        else { ?><span class="small text-muted">&mdash;</span><?php }
        return;
    }
    $fid = 'taoSk' . ($entity === 'course' ? 'C' : 'A') . intval($e['id']); ?>
    <div class="d-flex flex-wrap align-items-start gap-1">
        <form action="<?php echo nullable_htmlentities($tao_post); ?>" method="post" class="d-flex gap-1 align-items-center">
            <input type="hidden" name="csrf_token" value="<?php echo nullable_htmlentities($tao_csrf); ?>">
            <input type="hidden" name="entity" value="<?php echo nullable_htmlentities($entity); ?>">
            <input type="hidden" name="entity_id" value="<?php echo intval($e['id']); ?>">
            <label class="visually-hidden" for="<?php echo nullable_htmlentities($fid); ?>">Odoo certification skill for <?php echo nullable_htmlentities((string) $e['name']); ?></label>
            <select class="form-select form-select-sm" id="<?php echo nullable_htmlentities($fid); ?>" name="skill_id" style="max-width: 16rem;">
                <option value="">Not sent as a certification</option>
                <?php foreach ($tao_skills as $tao_sid => $tao_sn) { ?>
                    <option value="<?php echo intval($tao_sid); ?>"<?php if ($m['state'] === 'ok' && intval($m['skill_id']) === intval($tao_sid)) { echo ' selected'; } ?>><?php echo nullable_htmlentities((string) $tao_sn); ?></option>
                <?php } ?>
            </select>
            <button type="submit" name="ta_odoo_skill_map" class="btn btn-outline-primary btn-sm">Save</button>
        </form>
        <?php if ($m['state'] !== 'ok') { ?>
        <details class="text-start">
            <summary class="btn btn-outline-secondary btn-sm">Create in Odoo&hellip;</summary>
            <form action="<?php echo nullable_htmlentities($tao_post); ?>" method="post" class="mt-2 small">
                <input type="hidden" name="csrf_token" value="<?php echo nullable_htmlentities($tao_csrf); ?>">
                <input type="hidden" name="entity" value="<?php echo nullable_htmlentities($entity); ?>">
                <input type="hidden" name="entity_id" value="<?php echo intval($e['id']); ?>">
                <p class="mb-1">Creates the skill "<?php echo nullable_htmlentities((string) $e['name']); ?>" in the Odoo certification type (or uses an existing skill with exactly that name) and maps it here.</p>
                <button type="submit" name="ta_odoo_skill_create" class="btn btn-primary btn-sm">Create skill in Odoo</button>
            </form>
        </details>
        <?php } ?>
    </div>
    <?php if ($m['state'] === 'other_odoo') { ?><div class="small text-warning">Mapped on another Odoo; map it again.</div><?php } ?>
    <?php if ($m['state'] === 'not_in_type') { ?><div class="small text-warning">Mapped to Odoo skill #<?php echo intval($m['skill_id']); ?>, which is not in the chosen certification type; map it again.</div><?php }
};
?>
        <details class="mb-2">
            <summary>Courses (<?php echo intval(count($tao['courses'])); ?>, <?php echo intval(count(array_filter($tao['courses'], static fn($x) => (int) $x['push'] === 0))); ?> turned off, <?php echo intval(count(array_filter($tao['courses'], static fn($x) => ($x['skill']['state'] ?? '') === 'ok'))); ?> with a certification skill)</summary>
            <?php if (!$tao['courses']) { ?>
                <p class="text-muted small mt-2 mb-0">No training courses yet.</p>
            <?php } else { ?>
            <div class="table-responsive mt-2" style="max-height: 520px;">
                <table class="table table-sm table-vcenter mb-0">
                    <thead><tr><th>Course</th><th>Sent to Odoo</th><th class="text-end"></th><th>Certification skill</th></tr></thead>
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
                            <td><?php $tao_skill_cell('course', $tao_c); ?></td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
            <?php } ?>
        </details>
        <details>
            <summary>Achievements (<?php echo intval(count($tao['achievements'])); ?>, <?php echo intval(count(array_filter($tao['achievements'], static fn($x) => (int) $x['push'] === 1))); ?> sent, <?php echo intval(count(array_filter($tao['achievements'], static fn($x) => ($x['skill']['state'] ?? '') === 'ok'))); ?> with a certification skill)</summary>
            <?php if (!$tao['achievements']) { ?>
                <p class="text-muted small mt-2 mb-0">No achievements yet.</p>
            <?php } else { ?>
            <div class="table-responsive mt-2" style="max-height: 520px;">
                <table class="table table-sm table-vcenter mb-0">
                    <thead><tr><th>Achievement</th><th>Sent to Odoo</th><th class="text-end"></th><th>Certification skill</th></tr></thead>
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
                            <td><?php $tao_skill_cell('achievement', $tao_a); ?></td>
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
