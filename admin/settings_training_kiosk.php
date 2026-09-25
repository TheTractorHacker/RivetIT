<?php
require_once "includes/inc_all_admin.php";

/*
 * Admin > Training kiosk (P3 spec §5.8 [S], P-10): the kiosk thresholds and the Odoo-PIN switch.
 * The defaults are pilot-ready; everything is clamped again on the server
 * (admin/post/settings_training_kiosk.php via KioskSettings::clamp) and on every read.
 *
 * Renders before the 2.6.93 migration has run (column-exists guard): an unmigrated install
 * shows a "run the database update" card instead of a 500.
 */

use ITFlow\Training\Kiosk\Core\KioskSettings;
use ITFlow\Training\Kiosk\Core\KTime;

$tk_ready = false;
$tk_error = false;
$tk_row = [];
$tk_kiosks = null;
try {
    $tk_col = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(*) AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'settings' AND COLUMN_NAME IN ('config_training_kiosk_idle_s', 'config_training_pin_sources_synced_at_utc')"));
    if (intval($tk_col['n'] ?? 0) === 2) {
        $tk_cols = array_merge(array_keys(KioskSettings::RANGES), array_keys(KioskSettings::TIMES), [KioskSettings::SWITCH]);
        $tk_row = mysqli_fetch_assoc(mysqli_query($mysqli, 'SELECT ' . implode(', ', $tk_cols) . ' FROM settings WHERE company_id = 1')) ?: [];
        $tk_k = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(*) AS n FROM training_kiosks WHERE kiosk_status = 'active'"));
        $tk_kiosks = intval($tk_k['n'] ?? 0);
        $tk_ready = true;
    }
} catch (\Throwable $e) {
    error_log('Admin Training kiosk settings: ' . get_class($e) . ': ' . $e->getMessage());
    $tk_error = true;
}
$tk = KioskSettings::fromRow($tk_row, $tk_ready);

/** One number field: [column, label, unit, help]. Min/max come from KioskSettings::RANGES. */
$tk_groups = [
    ['icon' => 'fa-user-clock', 'title' => 'Sessions', 'intro' => 'How long a signed-in screen may sit untouched before it signs out, and the most a single sign-in can last.', 'fields' => [
        ['config_training_kiosk_idle_s', 'Learner idle sign-out', 'seconds', 'A "Still there?" warning shows 30 seconds before.'],
        ['config_training_trainer_idle_s', 'Trainer idle sign-out', 'seconds', 'Also used for the evaluation hand-off.'],
        ['config_training_checkin_idle_s', 'Group check-in idle sign-out', 'seconds', 'While the device is passed around a session.'],
        ['config_training_learner_max_minutes', 'Learner session limit', 'minutes', 'Progress is saved; the learner signs in again to continue.'],
        ['config_training_trainer_max_minutes', 'Trainer session limit', 'minutes', 'Also caps a group check-in.'],
    ]],
    ['icon' => 'fa-lock', 'title' => 'PIN lockouts', 'intro' => 'Per person. A soft lock doubles each time it repeats; the hard lock needs an agent to unlock (Devices & PINs).', 'fields' => [
        ['config_training_pin_soft_failures', 'Wrong PINs before a soft lock', 'tries', ''],
        ['config_training_pin_lock_minutes', 'First soft lock', 'minutes', ''],
        ['config_training_pin_hard_failures', 'Wrong PINs before a hard lock', 'tries', 'Must be more than the soft-lock count.'],
    ]],
    ['icon' => 'fa-shield-alt', 'title' => 'Device and system caps', 'intro' => 'Wrong-PIN limits across everyone, so one device cannot guess its way through the crew. Trainer PIN checks are exempt from the device cooldown and the system pause.', 'fields' => [
        ['config_training_kiosk_fail_cap', 'Wrong PINs per device in 10 minutes', 'tries', 'Then that device pauses sign-in (cooldown).'],
        ['config_training_global_fail_cap', 'Wrong PINs on all devices in 10 minutes', 'tries', 'Then sign-in pauses everywhere and admins are alerted.'],
        ['config_training_kiosk_fail_cap_24h', 'Wrong PINs per device in 24 hours', 'tries', ''],
        ['config_training_global_fail_cap_24h', 'Wrong PINs on all devices in 24 hours', 'tries', ''],
        ['config_training_kiosk_distinct_cap_24h', 'Different people with wrong PINs per device in 24 hours', 'people', 'People who later sign in correctly do not count.'],
        ['config_training_kiosk_search_per_min', 'Name searches per device per minute', 'searches', ''],
    ]],
    ['icon' => 'fa-receipt', 'title' => 'Setup slips', 'intro' => 'Printed PIN setup slips for people who use a training PIN.', 'fields' => [
        ['config_training_setup_code_days', 'A setup code works for', 'days', ''],
    ]],
];

$tk_until = static function (?string $utc): string {
    if ($utc === null || !KTime::isFuture($utc)) {
        return '';
    }
    return 'for about ' . KTime::minutesUntil($utc) . ' more minute(s)';
};
?>

<!-- Plain .card throughout, not .card-dark - see the note in admin/settings_module.php. -->

<?php if (!$tk_ready) { ?>
<div class="card mb-3">
    <div class="card-header py-3">
        <h3 class="card-title"><i class="fas fa-fw fa-tablet-alt me-2"></i>Training kiosk</h3>
    </div>
    <div class="card-body">
        <?php if ($tk_error) { ?>
            <div class="alert alert-danger mb-0">Training kiosk settings could not be loaded. The details were written to the server error log.</div>
        <?php } else { ?>
            <p class="mb-2">The Training kiosk settings are not installed yet.</p>
            <p class="text-muted mb-3">Run the database update (Admin &rsaquo; Update &rsaquo; <strong>Update Database</strong>) to add them.</p>
            <a href="/admin/update.php" class="btn btn-primary"><i class="fas fa-fw fa-database me-2"></i>Open Update</a>
        <?php } ?>
    </div>
</div>
<?php } else { ?>

<div class="card mb-3">
    <div class="card-header py-3">
        <h3 class="card-title"><i class="fas fa-fw fa-tablet-alt me-2"></i>Training kiosk</h3>
        <div class="card-actions">
            <?php if (is_file(dirname(__DIR__) . '/agent/training_devices.php') && !empty($config_module_enable_training)) { ?>
                <a href="/agent/training_devices.php" class="btn btn-outline-primary btn-sm"><i class="fas fa-fw fa-tablet-alt me-1"></i>Devices &amp; PINs</a>
            <?php } ?>
        </div>
    </div>
    <div class="card-body">
        <p class="mb-2">Employees sign in on an enrolled iPad or shop PC at <code>/kiosk/</code> by typing their name and then their PIN. The defaults below are ready for the pilot.</p>
        <p class="text-muted small mb-0">
            Enrolled devices: <strong><?php echo intval($tk_kiosks); ?></strong>.
            <?php if ($tk->pinPauseUntilUtc !== null && KTime::isFuture($tk->pinPauseUntilUtc)) { ?>
                <span class="badge text-bg-danger ms-1">Sign-in is paused system-wide <?php echo nullable_htmlentities($tk_until($tk->pinPauseUntilUtc)); ?></span>
            <?php } ?>
            <?php if ($tk->breakerUntilUtc !== null && KTime::isFuture($tk->breakerUntilUtc)) { ?>
                <span class="badge text-bg-warning ms-1">Odoo PIN checks are paused <?php echo nullable_htmlentities($tk_until($tk->breakerUntilUtc)); ?></span>
            <?php } ?>
            Device cooldowns and the system pause are cleared from Devices &amp; PINs.
        </p>
    </div>
</div>

<form action="post.php" method="post" autocomplete="off" id="tkSettingsForm">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">

    <?php foreach ($tk_groups as $tk_g) { ?>
    <div class="card mb-3">
        <div class="card-header py-3">
            <h3 class="card-title"><i class="fas fa-fw <?php echo nullable_htmlentities($tk_g['icon']); ?> me-2"></i><?php echo nullable_htmlentities($tk_g['title']); ?></h3>
        </div>
        <div class="card-body">
            <p class="text-muted small"><?php echo nullable_htmlentities($tk_g['intro']); ?></p>
            <div class="row">
                <?php foreach ($tk_g['fields'] as [$tk_col, $tk_label, $tk_unit, $tk_help]) {
                    [$tk_prop, $tk_def, $tk_min, $tk_max] = KioskSettings::RANGES[$tk_col];
                    $tk_id = 'tk_' . $tk_col; ?>
                <div class="col-sm-6 col-lg-4 mb-3">
                    <label class="form-label" for="<?php echo nullable_htmlentities($tk_id); ?>"><?php echo nullable_htmlentities($tk_label); ?></label>
                    <div class="input-group">
                        <input type="number" class="form-control" id="<?php echo nullable_htmlentities($tk_id); ?>" name="<?php echo nullable_htmlentities($tk_col); ?>"
                               min="<?php echo intval($tk_min); ?>" max="<?php echo intval($tk_max); ?>" step="1" required value="<?php echo intval($tk->{$tk_prop}); ?>">
                        <span class="input-group-text"><?php echo nullable_htmlentities($tk_unit); ?></span>
                    </div>
                    <div class="form-text">
                        <?php echo nullable_htmlentities(trim($tk_help . ' Default ' . $tk_def . ', allowed ' . $tk_min . '–' . $tk_max . '.')); ?>
                    </div>
                </div>
                <?php } ?>
            </div>
        </div>
    </div>
    <?php } ?>

    <div class="card mb-3">
        <div class="card-header py-3">
            <h3 class="card-title"><i class="fas fa-fw fa-id-badge me-2"></i>Odoo PIN sign-in</h3>
        </div>
        <div class="card-body">
            <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" role="switch" id="tkOdooPin" name="config_training_odoo_pin_enabled" value="1"
                    <?php if ($tk->odooPinEnabled) { echo 'checked'; } ?>>
                <label class="form-check-label fw-bold" for="tkOdooPin">Employees sign in with their Odoo (time clock) PIN</label>
            </div>
            <div class="alert alert-warning small mb-2">
                Turn on only after Odoo points at production, the employee-link check is clean, and PIN sources are refreshed.
            </div>
            <p class="text-muted small mb-0">
                While this is off, everyone uses a training PIN from a printed setup slip. Trainers always use a training PIN.
                <?php if ($tk->pinSourcesSyncedAtUtc !== null) { ?>
                    PIN sources were last refreshed <span class="font-monospace"><?php echo nullable_htmlentities($tk->pinSourcesSyncedAtUtc); ?> UTC</span>.
                <?php } else { ?>
                    PIN sources have not been refreshed yet.
                <?php } ?>
            </p>
        </div>
    </div>

    <div class="mb-4">
        <button type="submit" name="edit_training_kiosk_settings" class="btn btn-primary"><i class="fas fa-fw fa-check me-2"></i>Save kiosk settings</button>
    </div>
</form>

<?php } ?>

<?php
require_once "../includes/footer.php";
