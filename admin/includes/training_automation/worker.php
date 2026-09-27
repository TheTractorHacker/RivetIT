<?php

/*
 * Training automation > Worker card (LMS Phase 5 S3, Lane A): what cron/training_worker.php last did,
 * a warning until it has ever run, and (admin page only) the exact cron, log and logrotate lines for ops.
 * Read-only on both pages: there is nothing to save here. Variables: see sections.php.
 */

defined('TRAINING_AUTOMATION_PAGE') || exit;

$taw_daily_on = (string) ($ta['tauto_daily_last_run_on'] ?? '');
$taw_daily_result = (string) ($ta['tauto_daily_last_result'] ?? '');
$taw_odoo_at = ta_local_time($ta['tauto_odoo_last_run_at_utc'] ?? null);
$taw_odoo_result = (string) ($ta['tauto_odoo_last_result'] ?? '');
$taw_paused = (string) ($ta['tauto_odoo_paused_reason'] ?? '');
$taw_never = $taw_daily_on === '' && $taw_odoo_at === null;
$taw_stale = false;
if ($taw_daily_on !== '' && \ITFlow\Training\Core\Clock::isYmd($taw_daily_on)) {
    $taw_stale = $taw_daily_on < \ITFlow\Training\Core\Clock::addDays(\ITFlow\Training\Core\Clock::todayLocal(), -2);
}
$taw_switches = [
    ['Reminder digests', intval($ta['tauto_reminders_enabled'] ?? 0) === 1],
    ['External video checks', intval($ta['tauto_video_recheck_enabled'] ?? 0) === 1],
    ['Odoo write-back', intval($ta['tauto_odoo_push_enabled'] ?? 0) === 1],
];
$taw_script = realpath(dirname(__DIR__, 3) . '/cron/training_worker.php') ?: (dirname(__DIR__, 3) . '/cron/training_worker.php');
$taw_log = '/var/log/itflow_mw_training_worker.log';
$taw_daily_text = \ITFlow\Training\Automation\ResultText::daily($taw_daily_result);
$taw_odoo_text = \ITFlow\Training\Automation\ResultText::odoo($taw_odoo_result);
/** The OK / Failed / Paused badge in front of a result sentence (plain words from ResultText). */
$taw_badge = static function (?bool $ok, bool $paused = false): string {
    if ($ok === null) {
        return '';
    }
    [$cls, $word] = $ok ? ['text-bg-success', 'OK'] : ($paused ? ['text-bg-warning', 'Paused'] : ['text-bg-danger', 'Failed']);
    return '<span class="badge ' . $cls . ' me-1">' . $word . '</span>';
};
?>
<div class="card mb-3">
    <div class="card-header py-3 d-flex align-items-center">
        <h3 class="card-title"><i class="fas fa-fw fa-cogs me-2" aria-hidden="true"></i>Automation worker</h3>
    </div>
    <div class="card-body">
        <p class="text-muted small">
            A scheduled script sends the reminders, runs the video checks and copies records to Odoo: every 10 minutes for Odoo,
            once a day at 5:40 for the rest. It never sends email.
        </p>
        <?php if ($taw_never) { ?>
            <div class="alert alert-warning py-2"><i class="fas fa-fw fa-exclamation-triangle me-1" aria-hidden="true"></i>
                The worker has never run. <?php echo $ta_admin_page ? 'Install the schedule below.' : 'Ask an administrator to install its schedule.'; ?>
                Until then no reminders are sent and nothing is copied to Odoo.</div>
        <?php } elseif ($taw_stale) { ?>
            <div class="alert alert-warning py-2"><i class="fas fa-fw fa-exclamation-triangle me-1" aria-hidden="true"></i>
                The daily run has not run since <?php echo nullable_htmlentities($taw_daily_on); ?>.
                <?php echo $ta_admin_page ? 'Check the schedule and its log below.' : 'Ask an administrator to check its schedule.'; ?></div>
        <?php } ?>
        <dl class="row small mb-3">
            <dt class="col-sm-3">Last daily run</dt>
            <dd class="col-sm-9 text-break">
                <?php if ($taw_daily_on !== '') { ?>
                    <?php echo nullable_htmlentities($taw_daily_on); ?>
                    <?php if ($taw_daily_text['ok'] !== null) { ?><br><?php echo $taw_badge($taw_daily_text['ok']); ?><span><?php echo nullable_htmlentities($taw_daily_text['text']); ?></span><?php } ?>
                <?php } else { ?>
                    <span class="text-muted">Never</span>
                <?php } ?>
            </dd>
            <dt class="col-sm-3">Last Odoo run</dt>
            <dd class="col-sm-9 text-break">
                <?php if ($taw_odoo_at !== null) { ?>
                    <?php echo nullable_htmlentities($taw_odoo_at); ?>
                    <?php if ($taw_odoo_text['ok'] !== null) { ?><br><?php echo $taw_badge($taw_odoo_text['ok'], true); ?><span><?php echo nullable_htmlentities($taw_odoo_text['text']); ?></span><?php } ?>
                <?php } else { ?>
                    <span class="text-muted">Never<?php echo intval($ta['tauto_odoo_push_enabled'] ?? 0) === 1 ? '' : ' (write-back is off)'; ?></span>
                <?php } ?>
            </dd>
            <?php if ($taw_paused !== '' && $taw_odoo_text['ok'] !== false) { // a paused last run already says why ?>
                <dt class="col-sm-3">Odoo paused</dt>
                <dd class="col-sm-9 text-break"><span class="badge text-bg-warning me-1">Paused</span><?php echo nullable_htmlentities(class_exists('ITFlow\\Training\\OdooSync\\PushService') ? \ITFlow\Training\OdooSync\PushService::describePause($taw_paused) : $taw_paused); ?></dd>
            <?php } ?>
            <dt class="col-sm-3">Switched on</dt>
            <dd class="col-sm-9 d-flex flex-wrap gap-1">
                <?php foreach ($taw_switches as [$taw_label, $taw_on]) { ?>
                    <span class="badge <?php echo $taw_on ? 'text-bg-success' : 'text-bg-secondary'; ?>"><?php echo nullable_htmlentities($taw_label) . ': ' . ($taw_on ? 'On' : 'Off'); ?></span>
                <?php } ?>
            </dd>
        </dl>

        <?php if ($ta_admin_page) { ?>
            <details class="small">
                <summary class="fw-semibold">Schedule for the server (ops)</summary>
                <p class="mt-2 mb-1">Install as <code>/etc/cron.d/mw-itflow-training-worker</code> (owner root, mode 0644). It runs as www-data; a switched-off task exits without output.</p>
<pre class="bg-body-tertiary border rounded p-2 small mb-2"><code>SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin

*/10 * * * * www-data /usr/bin/php <?php echo nullable_htmlentities($taw_script); ?> --task=odoo &gt;&gt; <?php echo nullable_htmlentities($taw_log); ?> 2&gt;&amp;1
40 5 * * * www-data /usr/bin/php <?php echo nullable_htmlentities($taw_script); ?> --task=daily &gt;&gt; <?php echo nullable_htmlentities($taw_log); ?> 2&gt;&amp;1</code></pre>
                <p class="mb-1">Create the log first (a missing log file stops a cron.d job silently):</p>
<pre class="bg-body-tertiary border rounded p-2 small mb-2"><code>sudo install -o www-data -g adm -m 0640 /dev/null <?php echo nullable_htmlentities($taw_log); ?></code></pre>
                <p class="mb-1">Try it first, as www-data (sends nothing): <code>sudo -u www-data php <?php echo nullable_htmlentities($taw_script); ?> --task=daily --dry-run --force</code></p>
            </details>
        <?php } ?>
    </div>
</div>
