<?php
require_once "includes/inc_all_admin.php";

use RivetCore\Compliance\RetentionPolicy;

$csrf = $_SESSION['csrf_token'];

// The two settings are added by database update 2.6.125; until it runs the page explains instead of failing.
$compliance_row = null;
$res = @mysqli_query($mysqli, "SELECT config_compliance_profile, config_audit_retention_days, config_log_retention FROM settings WHERE company_id = 1");
if ($res) {
    $compliance_row = mysqli_fetch_assoc($res) ?: null;
}
$schema_ready = is_array($compliance_row);

$profile = $schema_ready ? (string) $compliance_row['config_compliance_profile'] : RetentionPolicy::NONE;
if (!RetentionPolicy::isValidProfile($profile)) {
    $profile = RetentionPolicy::NONE;
}
$audit_days = $schema_ready ? (int) $compliance_row['config_audit_retention_days'] : 365;
$log_days = $schema_ready ? (int) $compliance_row['config_log_retention'] : (int) $config_log_retention;
$floor = RetentionPolicy::floorDays($profile);
$audit_effective = RetentionPolicy::effectiveDays($profile, $audit_days);
$log_effective = RetentionPolicy::effectiveDays($profile, $log_days);

// What the audit trail holds right now.
$audit_stats = ['rows' => 0, 'oldest' => null, 'newest' => null];
$res = @mysqli_query($mysqli, "SELECT COUNT(*) AS c, MIN(created_at) AS o, MAX(created_at) AS n FROM audit_events");
if ($res && ($r = mysqli_fetch_assoc($res))) {
    $audit_stats = ['rows' => (int) $r['c'], 'oldest' => $r['o'], 'newest' => $r['n']];
}
$badge = $profile === RetentionPolicy::NONE ? ['No preset', 'secondary'] : [RetentionPolicy::PROFILES[$profile]['label'], 'success'];
?>

<div class="card mb-3">
    <div class="card-header py-3 d-flex align-items-center justify-content-between">
        <h3 class="card-title mb-0"><i class="fas fa-fw fa-clipboard-check me-2"></i>Compliance</h3>
        <span class="badge bg-<?= $badge[1] ?> fs-6"><?= nullable_htmlentities($badge[0]) ?></span>
    </div>
    <div class="card-body">
        <p class="text-muted mb-2">Choose how long RivetIT keeps its audit records. A preset sets a <strong>minimum</strong>: nothing is deleted sooner than it says,
        even if a number below is set lower, and the nightly cleanup respects it. Keeping records forever (0) is always allowed.</p>
        <p class="text-muted small mb-0">A preset helps you meet a retention requirement. It does not make an organization compliant on its own, and the figures are common starting points
        for your own policy, not legal advice.</p>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header py-3"><h4 class="card-title mb-0"><i class="fas fa-fw fa-hourglass-half me-2"></i>Retention</h4></div>
    <div class="card-body">
        <?php if (!$schema_ready) { ?>
            <div class="alert alert-warning mb-0">Run the database update first (Administration &rarr; Update) to edit these settings.</div>
        <?php } else { ?>
        <form action="post.php" method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label" for="compliance_profile">Preset</label>
                    <select class="form-select" id="compliance_profile" name="compliance_profile">
                        <?php foreach (RetentionPolicy::PROFILES as $key => $def) { ?>
                            <option value="<?= nullable_htmlentities($key) ?>" <?= $key === $profile ? 'selected' : '' ?>><?= nullable_htmlentities($def['label']) ?><?= $def['min_days'] > 0 ? ' (minimum ' . (int) $def['min_days'] . ' days)' : '' ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="audit_retention_days">Audit trail retention <small class="text-secondary">(days; 0 keeps forever)</small></label>
                    <input class="form-control" type="number" min="0" max="36500" id="audit_retention_days" name="audit_retention_days" value="<?= $audit_days ?>">
                    <div class="form-text">Security events: sign-ins, changes to settings, and Remote MCP tool calls.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="log_retention_days">Activity log retention <small class="text-secondary">(days; 0 keeps forever)</small></label>
                    <input class="form-control" type="number" min="0" max="36500" id="log_retention_days" name="log_retention_days" value="<?= $log_days ?>">
                    <div class="form-text">The activity, app and sign-in logs, the webhook delivery log and finished background jobs. Same setting as Security &rarr; Log retention.</div>
                </div>
            </div>
            <ul class="small text-muted mt-3 mb-0">
                <?php foreach (RetentionPolicy::PROFILES as $key => $def) { ?>
                    <li><strong><?= nullable_htmlentities($def['label']) ?>:</strong> <?= nullable_htmlentities($def['note']) ?></li>
                <?php } ?>
            </ul>
            <div class="mt-3"><button type="submit" name="save_compliance_settings" class="btn btn-primary"><i class="fa fa-check me-2"></i>Save</button></div>
        </form>
        <?php } ?>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header py-3"><h4 class="card-title mb-0"><i class="fas fa-fw fa-info-circle me-2"></i>In effect now</h4></div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm mb-2">
                <tbody>
                    <tr><th class="w-50">Audit trail</th><td><?= nullable_htmlentities(RetentionPolicy::describeDays($audit_effective)) ?>
                        <?php if ($audit_effective !== max(0, $audit_days)) { ?><span class="badge bg-warning text-dark ms-1">raised from <?= nullable_htmlentities(RetentionPolicy::describeDays($audit_days)) ?> by the preset</span><?php } ?></td></tr>
                    <tr><th>Activity logs, webhook delivery log, finished jobs</th><td><?= nullable_htmlentities(RetentionPolicy::describeDays($log_effective)) ?>
                        <?php if ($log_effective !== max(0, $log_days)) { ?><span class="badge bg-warning text-dark ms-1">raised from <?= nullable_htmlentities(RetentionPolicy::describeDays($log_days)) ?> by the preset</span><?php } ?></td></tr>
                    <tr><th>Preset minimum</th><td><?= $floor > 0 ? nullable_htmlentities(RetentionPolicy::describeDays($floor)) : 'none' ?></td></tr>
                </tbody>
            </table>
        </div>
        <p class="text-muted small mb-2">Older records are deleted by the hourly cleanup. Recording is always on for the audit trail.</p>
        <div class="row g-3 text-center">
            <div class="col-6 col-md-4"><div class="border rounded p-2"><div class="small text-muted">Audit records</div><strong><?= number_format($audit_stats['rows']) ?></strong></div></div>
            <div class="col-6 col-md-4"><div class="border rounded p-2"><div class="small text-muted">Oldest</div><strong><?= $audit_stats['oldest'] ? nullable_htmlentities($audit_stats['oldest']) : '&mdash;' ?></strong></div></div>
            <div class="col-6 col-md-4"><div class="border rounded p-2"><div class="small text-muted">Newest</div><strong><?= $audit_stats['newest'] ? nullable_htmlentities($audit_stats['newest']) : '&mdash;' ?></strong></div></div>
        </div>
    </div>
</div>

<?php require_once "../includes/footer.php";
