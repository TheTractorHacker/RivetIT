<?php

/*
 * Training automation (LMS Phase 5, DB 2.6.96) on the one-page Training settings: the "Certificates" and
 * "Reminders & automation" sections. Included by BOTH pages, before their Records ledger section:
 *   admin/settings_training.php   (admins; forms post to admin/post.php -> settings_training_automation.php)
 *   agent/training_settings.php   (Training 3; forms post back to the page -> AgentSettingsHandler)
 * Both hand the POST to ITFlow\Training\Settings\AutomationActions, which refuses admin-only actions and
 * fields from the agent page.
 *
 * The including page defines TRAINING_AUTOMATION_PAGE and sets, before including this file:
 *   $ta_admin_page  bool    true on Admin > Training
 *   $ta_is_admin    bool    the session user is an administrator
 *   $ta_module_on   bool    Training is switched on
 * This file gives every card partial:
 *   $ta             AutomationSettings::load() (every tauto_ column, typed, plus 'ready')
 *   $ta_version     int     tauto_version - post it as `version` (optimistic lock)
 *   $ta_target      ?OdooSync\Target  Target::current() (null when none is enabled or Lane B is not installed)
 *   $ta_csrf        string  the CSRF token (raw: echo it through nullable_htmlentities)
 *   $ta_post_url    string  'post.php' on the admin page, '/agent/training_settings.php' on the agent page
 *   $ta_page_url    string  this page's own URL, for GET links such as ?preview=reminders
 *   $ta_admin_page, $ta_is_admin, $ta_module_on (as above)
 *   ta_admin_only_note(string $anchor): string   the "Admin only" line for a read-only control
 *   ta_read_only_badge(): string                 the "Read only" card-header badge
 *
 * Card partials (each starts with `defined('TRAINING_AUTOMATION_PAGE') || exit;`), included only if the file
 * exists, each inside a wrapper that carries its anchor (do not repeat these ids in the partial):
 *   certificates.php  Lane C  #certificates section   both pages; on the agent page the public-check switch
 *                                                     is admin only: render it read-only (no enabled
 *                                                     `verify_enabled` input), the handler refuses it anyway
 *   reminders.php     Lane D  #reminders              both pages (Training 3 may change it)
 *   video.php         Lane D  #video-watch            both pages (Training 3 may change it)
 *   odoo.php          Lane B  #odoo-writeback         ADMIN PAGE ONLY; the agent page shows a read-only
 *                                                     summary rendered here instead
 *   worker.php        Lane A  #automation-worker      both pages (cron lines on the admin page only)
 * Every string from Odoo or the database is echoed through nullable_htmlentities(); forms carry
 * data-ts-label (the unsaved-changes guard); no inline script (the agent page has no nonce'd block).
 */

use ITFlow\Training\Automation\AutomationSettings;

defined('TRAINING_AUTOMATION_PAGE') || exit;   // the including page defines it (a direct request stops here)

if (!function_exists('ta_admin_only_note')) {
    /** "Admin only" line under a read-only control; an admin gets a link to Admin > Training instead. */
    function ta_admin_only_note(string $anchor): string
    {
        global $ta_is_admin;
        $text = !empty($ta_is_admin)
            ? 'Admin only. <a href="/admin/settings_training.php#' . nullable_htmlentities($anchor) . '">Change in Admin &rsaquo; Training</a>.'
            : 'Admin only. Ask an administrator to change this.';
        return '<p class="ts-locked small mb-0"><i class="fas fa-fw fa-lock me-1" aria-hidden="true"></i>' . $text . '</p>';
    }
}
if (!function_exists('ta_read_only_badge')) {
    function ta_read_only_badge(): string
    {
        return '<span class="badge text-bg-secondary ms-auto"><i class="fas fa-lock me-1" aria-hidden="true"></i>Read only</span>';
    }
}
if (!function_exists('ta_local_time')) {
    /** A UTC DATETIME(3) as local "Y-m-d H:i", or null. */
    function ta_local_time(?string $utc): ?string
    {
        if ($utc === null || $utc === '') {
            return null;
        }
        $iso = \ITFlow\Training\Core\Clock::toIso($utc, true);
        return $iso ? date('Y-m-d H:i', strtotime($iso)) : null;
    }
}

$ta_admin_page = !empty($ta_admin_page);
$ta_is_admin = !empty($ta_is_admin);
$ta_module_on = !empty($ta_module_on);
$ta_error = false;
$ta = class_exists(AutomationSettings::class) ? AutomationSettings::load($mysqli) : ['ready' => false];
$ta_ready = !empty($ta['ready']);
$ta_version = (int) ($ta['tauto_version'] ?? 0);
$ta_csrf = (string) ($_SESSION['csrf_token'] ?? '');
$ta_post_url = $ta_admin_page ? 'post.php' : '/agent/training_settings.php';
$ta_page_url = $ta_admin_page ? '/admin/settings_training.php' : '/agent/training_settings.php';
$ta_target = null;
if ($ta_ready && class_exists('ITFlow\\Training\\OdooSync\\Target')) {
    try {
        $ta_target = \ITFlow\Training\OdooSync\Target::current($mysqli);
    } catch (\Throwable $e) {
        error_log('Training automation settings: Odoo target lookup failed: ' . get_class($e) . ': ' . $e->getMessage());
        $ta_target = null;
    }
}
$ta_dir = __DIR__;

if (!function_exists('ta_missing_card')) {
    /** The stand-in for a card whose lane has not shipped its partial yet. */
    function ta_missing_card(string $title, string $icon): void
    {
        echo '<div class="card mb-3"><div class="card-header py-3"><h3 class="card-title"><i class="fas fa-fw ' . nullable_htmlentities($icon)
            . ' me-2" aria-hidden="true"></i>' . nullable_htmlentities($title) . '</h3></div><div class="card-body"><p class="text-muted mb-0">'
            . 'Not installed yet: this arrives with a later Training update.</p></div></div>';
    }
}

$ta_update_card = static function () use ($ta_admin_page, $ta_is_admin): void {
    ?>
    <div class="card mb-3">
        <div class="card-header py-3">
            <h3 class="card-title"><i class="fas fa-fw fa-database me-2" aria-hidden="true"></i>Run the database update to use Training automation</h3>
        </div>
        <div class="card-body">
            <p class="mb-2">Certificates, reminders, video checks and Odoo write-back need the Training automation tables, which are not installed yet.</p>
            <?php if ($ta_is_admin) { ?>
                <p class="text-muted mb-3">Run the database update (Admin &rsaquo; Update &rsaquo; <strong>Update Database</strong>) to add them. Everything that acts stays off until it is switched on here.</p>
                <a href="/admin/update.php" class="btn btn-primary"><i class="fas fa-fw fa-database me-2" aria-hidden="true"></i>Open Update</a>
            <?php } else { ?>
                <p class="text-muted mb-0">Ask an administrator to run the database update.</p>
            <?php } ?>
        </div>
    </div>
    <?php
};
?>

<!-- =========================================================================================== -->
<!-- Certificates (Phase 5)                                                                      -->
<!-- =========================================================================================== -->
<section id="certificates" class="ts-section" aria-labelledby="certificates-title">
<div class="ts-section-head">
    <div>
        <h2 id="certificates-title"><i class="fas fa-fw fa-certificate me-2" aria-hidden="true"></i>Certificates</h2>
        <p>The signatory on printed certificates, certificate PDFs and the public certificate check behind the QR code.</p>
    </div>
</div>

<?php if (!$ta_ready) {
    $ta_update_card();
} else { ?>
    <?php if (!$ta_module_on) { ?>
        <div class="alert alert-warning py-2"><i class="fas fa-fw fa-power-off me-1" aria-hidden="true"></i>Training is off; these settings take effect when it is turned on. Until then every certificate QR code shows "not available".</div>
    <?php } ?>
    <?php if (is_file($ta_dir . '/certificates.php')) { require $ta_dir . '/certificates.php'; } else { ta_missing_card('Certificates', 'fa-certificate'); } ?>
<?php } ?>
</section>

<!-- =========================================================================================== -->
<!-- Reminders & automation (Phase 5)                                                            -->
<!-- =========================================================================================== -->
<section id="automation" class="ts-section" aria-labelledby="automation-title">
<div class="ts-section-head">
    <div>
        <h2 id="automation-title"><i class="fas fa-fw fa-robot me-2" aria-hidden="true"></i>Reminders &amp; automation</h2>
        <p>Daily reminder digests, external video checks, Odoo write-back and the worker that runs them. Everything that acts is off until switched on.</p>
    </div>
</div>

<?php if (!$ta_ready) { ?>
    <p class="text-muted">Available once the Training automation tables are installed (see Certificates above).</p>
<?php } else { ?>
    <?php if (!$ta_module_on) { ?>
        <div class="alert alert-warning py-2"><i class="fas fa-fw fa-power-off me-1" aria-hidden="true"></i>Training is off; these settings take effect when it is turned on. The worker does nothing while Training is off.</div>
    <?php } ?>

    <div id="reminders"><?php if (is_file($ta_dir . '/reminders.php')) { require $ta_dir . '/reminders.php'; } else { ta_missing_card('Reminder digests', 'fa-bell'); } ?></div>

    <div id="video-watch"><?php if (is_file($ta_dir . '/video.php')) { require $ta_dir . '/video.php'; } else { ta_missing_card('External video checks', 'fa-video'); } ?></div>

    <div id="odoo-writeback">
    <?php if ($ta_admin_page) {
        if (is_file($ta_dir . '/odoo.php')) { require $ta_dir . '/odoo.php'; } else { ta_missing_card('Odoo write-back', 'fa-cloud-upload-alt'); }
    } else {
        $ta_odoo_on = intval($ta['tauto_odoo_push_enabled'] ?? 0) === 1;
        $ta_odoo_run = ta_local_time($ta['tauto_odoo_last_run_at_utc'] ?? null);
        $ta_odoo_paused = (string) ($ta['tauto_odoo_paused_reason'] ?? '');
        $ta_key_exp = (string) ($ta['tauto_odoo_key_expires_on'] ?? '');
        ?>
        <div class="card mb-3">
            <div class="card-header py-3 d-flex align-items-center">
                <h3 class="card-title"><i class="fas fa-fw fa-cloud-upload-alt me-2" aria-hidden="true"></i>Odoo write-back</h3>
                <?php echo ta_read_only_badge(); ?>
            </div>
            <div class="card-body">
                <p class="text-muted small">Copies training records to each employee's résumé in Odoo. ITFlow stays the record of truth.</p>
                <dl class="row small mb-3">
                    <dt class="col-sm-3">Write-back</dt>
                    <dd class="col-sm-9"><?php echo $ta_odoo_on ? '<span class="badge text-bg-success">On</span>' : '<span class="badge text-bg-secondary">Off</span>'; ?></dd>
                    <dt class="col-sm-3">Last run</dt>
                    <dd class="col-sm-9"><?php echo $ta_odoo_run !== null ? nullable_htmlentities($ta_odoo_run) : '<span class="text-muted">Never</span>'; ?></dd>
                    <dt class="col-sm-3">Result</dt>
                    <dd class="col-sm-9 text-break"><?php echo (string) ($ta['tauto_odoo_last_result'] ?? '') !== '' ? '<span class="font-monospace">' . nullable_htmlentities((string) $ta['tauto_odoo_last_result']) . '</span>' : '<span class="text-muted">&mdash;</span>'; ?></dd>
                    <?php if ($ta_odoo_paused !== '') { ?>
                        <dt class="col-sm-3">Paused</dt>
                        <dd class="col-sm-9 text-break"><span class="badge text-bg-warning me-1">Paused</span><?php echo nullable_htmlentities($ta_odoo_paused); ?></dd>
                    <?php } ?>
                    <?php if ($ta_key_exp !== '') { ?>
                        <dt class="col-sm-3">Odoo key expires</dt>
                        <dd class="col-sm-9"><?php echo nullable_htmlentities($ta_key_exp); ?></dd>
                    <?php } ?>
                </dl>
                <?php echo ta_admin_only_note('odoo-writeback'); ?>
            </div>
        </div>
    <?php } ?>
    </div>

    <div id="automation-worker"><?php if (is_file($ta_dir . '/worker.php')) { require $ta_dir . '/worker.php'; } else { ta_missing_card('Automation worker', 'fa-cogs'); } ?></div>
<?php } ?>
</section>
