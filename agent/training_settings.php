<?php

/*
 * Training › Settings for Training level 3 (roles audit 2026-09-26, P2). The same sections as the
 * admin one-page settings (admin/settings_training.php), which stays exactly as it is for admins;
 * admins may use either page.
 *
 * What can be changed here (ITFlow\Training\Settings\SettingsPolicy):
 *   Training 3            course defaults (#general); compliance defaults, Recalculate assignments,
 *                         Capture today's snapshot (#compliance, #maintenance); Verify now (#ledger)
 *   Training 3 + Kiosk 3  kiosk session timeouts and setup-slip validity (#kiosk)
 *   Training 3 (Phase 5)  the certificate signatory (#certificates), reminder digests (#reminders) and external
 *                         video checks (#video-watch); Odoo write-back and the public certificate check switch
 *                         are admin only (read-only here). Shared sections: admin/includes/training_automation/.
 *   Admin only            media limits and budget, the YouTube key, media purge, Odoo employee links,
 *                         the nightly Odoo sync, the Odoo hire-date fill, the Odoo PIN switch, PIN
 *                         lockouts, device and system caps. Shown read-only with "Ask an
 *                         administrator" (an admin gets a link to Admin › Training instead); never
 *                         saved from this page, for anyone.
 *
 * POST: every form posts back to this page. The CSRF token is checked, then
 * ITFlow\Training\Settings\AgentSettingsHandler checks Training 3 (and Kiosk 3), refuses admin-only
 * actions and fields, and saves through the same SettingsService the admin handlers use. It never
 * goes through admin/post.php. Post/redirect/get: the redirect returns to the action's section.
 *
 * GET never calls Odoo: the link summary is OdooLinkChecker::status(), read from the DB. Unlike
 * the admin page it does not scan uploads/ for a backup-size estimate, and it lists no media files.
 * The section nav and unsaved-changes guard are agent/js/training_settings.js (in memory only;
 * nothing is stored in the browser).
 */

use ITFlow\Training\Core\Access;
use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Ledger;
use ITFlow\Training\Core\MediaUsage;
use ITFlow\Training\Core\RecordsSettings;
use ITFlow\Training\Core\TrainingSettings;
use ITFlow\Training\Directory\OdooLinkChecker;
use ITFlow\Training\Directory\OdooTarget;
use ITFlow\Training\Kiosk\Core\KioskSettings;
use ITFlow\Training\Kiosk\Core\KTime;
use ITFlow\Training\Settings\AgentSettingsHandler;
use ITFlow\Training\Settings\SettingsPolicy;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_once "../config.php";
    require_once "../functions.php";
    require_once "../includes/check_login.php";

    validateCSRFToken($_POST['csrf_token'] ?? '');

    $ts_out = AgentSettingsHandler::handle($mysqli, $_POST, [
        'user_id' => intval($session_user_id),
        'name' => (string) $session_name,
        'is_admin' => ($session_is_admin ?? false) === true,
        'training_level' => Access::level(),
        'kiosk_level' => Access::kioskLevel(),
        'module_on' => Access::enabled(),
        'schema_ready' => !empty($config_training_schema_ready),
    ], $_FILES);
    flash_alert($ts_out['message'], $ts_out['type']);
    redirect($ts_out['url']);
}

require_once "includes/inc_all.php";
if (Access::pageGuard(3)) { require_once "../includes/footer.php"; exit; }

$ts_admin = ($session_is_admin ?? false) === true;
$ts_tlevel = Access::level();
$ts_klevel = Access::kioskLevel();
$ts_can_kiosk = SettingsPolicy::canOnAgentPage(SettingsPolicy::KIOSK_SESSIONS, $ts_admin, $ts_tlevel, $ts_klevel);
$ts_csrf = (string) ($_SESSION['csrf_token'] ?? '');

/**
 * The note on an item this page shows read-only. $why: 'admin' (admin-only item) or 'kiosk'
 * (needs Training kiosk Full). An admin gets a link to the admin page's section instead.
 */
function ts_locked_note(string $why, string $adminAnchor, bool $isAdmin): string
{
    if ($why === 'admin') {
        $text = $isAdmin
            ? 'Admin only. <a href="/admin/settings_training.php#' . nullable_htmlentities($adminAnchor) . '">Change in Admin &rsaquo; Training</a>.'
            : 'Admin only. Ask an administrator to change this.';
    } else {
        $text = 'Needs the Training kiosk permission at Full. Ask an administrator.';
    }
    return '<p class="ts-locked small mb-0"><i class="fas fa-fw fa-lock me-1" aria-hidden="true"></i>' . $text . '</p>';
}

function ts_locked_badge(): string
{
    return '<span class="badge text-bg-secondary ms-auto"><i class="fas fa-lock me-1" aria-hidden="true"></i>Read only</span>';
}

function ts_fmt_bytes(int $b): string {
    if ($b < 1024) return $b . ' B';
    $u = ['KB', 'MB', 'GB', 'TB'];
    $i = -1;
    $v = $b;
    do { $v /= 1024; $i++; } while ($v >= 1024 && $i < count($u) - 1);
    return ($v >= 100 ? round($v) : rtrim(rtrim(number_format($v, 1), '0'), '.')) . ' ' . $u[$i];
}

/** A UTC DATETIME(3) as local "Y-m-d H:i", or null. */
function ts_local_time(?string $utc): ?string {
    if ($utc === null || $utc === '') {
        return null;
    }
    $iso = Clock::toIso($utc, true);
    return $iso ? date('Y-m-d H:i', strtotime($iso)) : null;
}

/* ============================================================================================
 * General & media + Records ledger
 * ============================================================================================ */

$tr_ready = false;
$tr_error = false;
$tr_row = [];
$tr_head = null;
$tr_head_missing = false;
$tr_usage = [];
$tr_live_bytes = 0;
$tr_evidence = null;

if (!empty($config_training_schema_ready)) {
    try {
        $tr_tbl = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('training_ledger_head', 'training_media', 'training_events')"));
        if (intval($tr_tbl['n'] ?? 0) === 3) {
            $tr_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_module_enable_training, config_training_languages, config_training_default_pass_pct,
                    config_training_default_max_attempts, config_training_attestation_text, config_training_video_max_mb, config_training_pdf_max_mb,
                    config_training_pdf_max_pages, config_training_image_max_mb, config_training_file_max_mb, config_training_media_budget_mb,
                    (config_training_youtube_api_key IS NOT NULL AND config_training_youtube_api_key <> '') AS youtube_key_set,
                    config_training_ledger_verified_at_utc, config_training_ledger_verify_result
                FROM settings WHERE company_id = 1")) ?: [];
            try {
                $tr_head = Ledger::head($mysqli);
            } catch (\RuntimeException $e) {
                if ($e->getMessage() !== 'ledger_uninitialized') {
                    throw $e;
                }
                $tr_head_missing = true;
            }
            $tr_usage = MediaUsage::liveByKind($mysqli);
            if (isset($tr_usage['evidence'])) {
                $tr_evidence = $tr_usage['evidence'];
                unset($tr_usage['evidence']);
            }
            foreach ($tr_usage as $tr_k) {
                $tr_live_bytes += $tr_k['bytes'];
            }
            $tr_ready = true;
        }
    } catch (\Throwable $e) {
        error_log('Training settings: ' . get_class($e) . ': ' . $e->getMessage());
        $tr_error = true;
        $tr_ready = false;
    }
}

$tr_settings = TrainingSettings::fromRow($tr_row, $tr_ready);
$tr_budget_bytes = $tr_settings->budgetBytes;
$tr_budget_pct = $tr_budget_bytes > 0 ? min(100, round($tr_live_bytes * 100 / $tr_budget_bytes, 1)) : 0;

$tr_kind_labels = ['pdf' => 'PDF documents', 'page' => 'PDF page images', 'video' => 'Videos', 'image' => 'Images', 'file' => 'Resource files', 'evidence' => 'Evidence'];
$tr_kind_colors = ['pdf' => 'bg-red', 'page' => 'bg-orange', 'video' => 'bg-purple', 'image' => 'bg-cyan', 'file' => 'bg-blue', 'evidence' => 'bg-secondary'];

$tr_result_line = (string) ($tr_row['config_training_ledger_verify_result'] ?? '');
$tr_result_badge = 'text-bg-secondary';
if (str_starts_with($tr_result_line, 'ok')) {
    $tr_result_badge = 'text-bg-success';
} elseif (str_starts_with($tr_result_line, 'BREAK')) {
    $tr_result_badge = 'text-bg-danger';
} elseif ($tr_result_line !== '') {
    $tr_result_badge = 'text-bg-warning';
}
$tr_verified_iso = Clock::toIso($tr_row['config_training_ledger_verified_at_utc'] ?? null, true);
$tr_head_updated_iso = $tr_head ? Clock::toIso($tr_head['updated_at_utc'], true) : null;

/* ============================================================================================
 * Compliance & assignments + Employee links (Odoo)
 * ============================================================================================ */

$tc_ready = false;
$tc_error = false;
$tc_s = null;
$tc_integration = null;
$tc_status = null;
$tc_status_error = false;
$tc_open = ['open' => 0, 'overdue' => 0];

if (!empty($config_training_schema_ready) && class_exists(OdooLinkChecker::class) && class_exists(RecordsSettings::class)) {
    try {
        $tc_tbl = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME IN ('contact_odoo_attributes', 'training_assignments', 'training_compliance_daily')"));
        if (intval($tc_tbl['n'] ?? 0) === 3) {
            $tc_s = RecordsSettings::fromDb($mysqli);
            $tc_ready = $tc_s->schemaReady;
        }
        if ($tc_ready) {
            $tc_open_row = \ITFlow\Training\Core\Db::one($mysqli, "SELECT COUNT(*) AS open_n, COALESCE(SUM(tassign_due_on < ?), 0) AS overdue_n
                FROM training_assignments WHERE tassign_status = 'open'", 's', [Clock::todayLocal()]);
            $tc_open = ['open' => intval($tc_open_row['open_n'] ?? 0), 'overdue' => intval($tc_open_row['overdue_n'] ?? 0)];
            $tc_integration = OdooLinkChecker::latestIntegration($mysqli);
            if ($tc_integration !== null) {
                try {
                    $tc_status = (new OdooLinkChecker($mysqli, $tc_integration))->status();
                } catch (\Throwable $e) {
                    error_log('Training settings: link status failed: ' . get_class($e) . ': ' . $e->getMessage());
                    $tc_status_error = true;
                }
            }
        }
    } catch (\Throwable $e) {
        error_log('Training settings (compliance): ' . get_class($e) . ': ' . $e->getMessage());
        $tc_error = true;
        $tc_ready = false;
    }
}

$tc_state_chips = [
    'ok'        => ['text-bg-success', 'OK'],
    'unchecked' => ['text-bg-secondary', 'Not checked yet'],
    'mismatch'  => ['text-bg-warning', 'Name changed'],
    'missing'   => ['text-bg-danger', 'Missing in Odoo'],
    'repointed' => ['text-bg-danger', 'Re-pointed'],
];

// Odoo target facts: state only (no key, and no host or database name on this page).
$tc_staging = false;
$tc_target_state = 'none';     // none | unset | accepted | pending
if ($tc_integration !== null) {
    $tc_staging = stripos((string) $tc_integration['base_url'], 'staging') !== false || stripos((string) $tc_integration['database_name'], 'staging') !== false;
    if ($tc_status !== null) {
        $tc_target_state = $tc_status['target']['accepted'] === null ? 'unset' : ($tc_status['target']['pending'] ? 'pending' : 'accepted');
    } else {
        $tc_target = OdooTarget::guard($mysqli, $tc_integration);
        $tc_target_state = $tc_target['accepted_sha'] === null ? 'unset' : ($tc_target['ok'] ? 'accepted' : 'pending');
    }
}
$tc_counts = $tc_status['counts'] ?? [];

/* ============================================================================================
 * Kiosk & sign-in
 * ============================================================================================ */

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
    error_log('Training settings (kiosk): ' . get_class($e) . ': ' . $e->getMessage());
    $tk_error = true;
}
$tk = KioskSettings::fromRow($tk_row, $tk_ready);

/** One number field: [column, label, unit, help]. Min/max come from KioskSettings::RANGES. Same groups as the admin page. */
$tk_groups = [
    ['item' => SettingsPolicy::KIOSK_SESSIONS, 'icon' => 'fa-user-clock', 'title' => 'Sessions', 'intro' => 'How long a signed-in screen may sit untouched before it signs out, and the most a single sign-in can last.', 'fields' => [
        ['config_training_kiosk_idle_s', 'Learner idle sign-out', 'seconds', 'A "Still there?" warning shows 30 seconds before.'],
        ['config_training_trainer_idle_s', 'Trainer idle sign-out', 'seconds', 'Also used for the evaluation hand-off.'],
        ['config_training_checkin_idle_s', 'Group check-in idle sign-out', 'seconds', 'While the device is passed around a session.'],
        ['config_training_learner_max_minutes', 'Learner session limit', 'minutes', 'Progress is saved; the learner signs in again to continue.'],
        ['config_training_trainer_max_minutes', 'Trainer session limit', 'minutes', 'Also caps a group check-in.'],
    ]],
    ['item' => SettingsPolicy::PIN_LOCKOUTS, 'icon' => 'fa-lock', 'title' => 'PIN lockouts', 'intro' => 'Per person. A soft lock doubles each time it repeats; the hard lock needs an agent to unlock (Devices & PINs).', 'fields' => [
        ['config_training_pin_soft_failures', 'Wrong PINs before a soft lock', 'tries', ''],
        ['config_training_pin_lock_minutes', 'First soft lock', 'minutes', ''],
        ['config_training_pin_hard_failures', 'Wrong PINs before a hard lock', 'tries', 'Must be more than the soft-lock count.'],
    ]],
    ['item' => SettingsPolicy::DEVICE_CAPS, 'icon' => 'fa-shield-alt', 'title' => 'Device and system caps', 'intro' => 'Wrong-PIN limits across everyone, so one device cannot guess its way through the crew. Trainer PIN checks are exempt from the device cooldown and the system pause.', 'fields' => [
        ['config_training_kiosk_fail_cap', 'Wrong PINs per device in 10 minutes', 'tries', 'Then that device pauses sign-in (cooldown).'],
        ['config_training_global_fail_cap', 'Wrong PINs on all devices in 10 minutes', 'tries', 'Then sign-in pauses everywhere and admins are alerted.'],
        ['config_training_kiosk_fail_cap_24h', 'Wrong PINs per device in 24 hours', 'tries', ''],
        ['config_training_global_fail_cap_24h', 'Wrong PINs on all devices in 24 hours', 'tries', ''],
        ['config_training_kiosk_distinct_cap_24h', 'Different people with wrong PINs per device in 24 hours', 'people', 'People who later sign in correctly do not count.'],
        ['config_training_kiosk_search_per_min', 'Name searches per device per minute', 'searches', ''],
    ]],
    ['item' => SettingsPolicy::SETUP_SLIPS, 'icon' => 'fa-receipt', 'title' => 'Setup slips', 'intro' => 'Printed PIN setup slips for people who use a training PIN.', 'fields' => [
        ['config_training_setup_code_days', 'A setup code works for', 'days', ''],
    ]],
];

$tk_until = static function (?string $utc): string {
    if ($utc === null || !KTime::isFuture($utc)) {
        return '';
    }
    return 'for about ' . KTime::minutesUntil($utc) . ' more minute(s)';
};

/* ============================================================================================
 * Page chrome
 * ============================================================================================ */

$ts_sections = [
    'general'    => ['General & media', 'fa-sliders-h'],
    'compliance' => ['Compliance & assignments', 'fa-clipboard-check'],
    'odoo'       => ['Employee links (Odoo)', 'fa-address-card'],
    'kiosk'      => ['Kiosk & sign-in', 'fa-tablet-alt'],
    'certificates' => ['Certificates', 'fa-certificate'],
    'automation' => ['Reminders & automation', 'fa-robot'],
    'ledger'     => ['Records ledger', 'fa-link'],
];

$ts_actions = $ts_admin
    ? '<a href="/admin/settings_training.php" class="btn btn-outline-primary"><i class="fas fa-fw fa-cog me-1" aria-hidden="true"></i>Admin &rsaquo; Training</a>'
    : '';
render_page_header(
    'Training settings',
    'Every Training setting on one page. Each section saves on its own; items marked Read only are changed by an administrator.',
    $ts_actions,
    [['label' => 'Training', 'url' => '/agent/training.php'], ['label' => 'Settings']]
);

$ts_update_card = static function (string $title, string $icon, bool $error, string $errorText, string $what) use ($ts_admin): void {
    ?>
    <div class="card mb-3">
        <div class="card-header py-3">
            <h3 class="card-title"><i class="fas fa-fw <?php echo nullable_htmlentities($icon); ?> me-2" aria-hidden="true"></i><?php echo nullable_htmlentities($title); ?></h3>
        </div>
        <div class="card-body">
            <?php if ($error) { ?>
                <div class="alert alert-danger mb-0"><?php echo nullable_htmlentities($errorText); ?> The details were written to the server error log.</div>
            <?php } else { ?>
                <p class="mb-2"><?php echo nullable_htmlentities($what); ?> are not installed yet.</p>
                <?php if ($ts_admin) { ?>
                    <p class="text-muted mb-3">Run the database update (Admin &rsaquo; Update &rsaquo; <strong>Update Database</strong>) to add them.</p>
                    <a href="/admin/update.php" class="btn btn-primary"><i class="fas fa-fw fa-database me-2" aria-hidden="true"></i>Open Update</a>
                <?php } else { ?>
                    <p class="text-muted mb-0">Ask an administrator to run the database update.</p>
                <?php } ?>
            <?php } ?>
        </div>
    </div>
    <?php
};
?>

<style nonce="<?php echo nullable_htmlentities($csp_nonce ?? ''); ?>">
    /* Same look as admin/settings_training.php. */
    .ts-page [id] { scroll-margin-top: 5rem; }
    @media (prefers-reduced-motion: no-preference) {
        html:has(.ts-page) { scroll-behavior: smooth; }
    }
    .ts-nav {
        position: sticky; top: 0; z-index: 20;
        margin: 0 0 1.25rem; padding: .5rem 0;
        background: var(--if-bg, #eef2f2);
    }
    .ts-nav ul {
        display: flex; gap: .25rem; margin: 0; padding: .3rem; list-style: none;
        overflow-x: auto; scrollbar-width: thin; -webkit-overflow-scrolling: touch;
        background: var(--if-surface, #fff); border: 1px solid var(--if-border, #e3e9ea);
        border-radius: var(--if-radius, 12px); box-shadow: var(--if-shadow, none);
    }
    .ts-nav li { flex: 0 0 auto; }
    .ts-nav a {
        display: flex; align-items: center; gap: .45rem; padding: .45rem .8rem; border-radius: 8px;
        font-weight: 500; white-space: nowrap; text-decoration: none; color: var(--if-muted, #5d6f76);
    }
    .ts-nav a:hover { color: var(--if-ink, #16232a); background: rgba(var(--if-primary-rgb, 13, 148, 136), .06); }
    .ts-nav a:focus-visible { outline: 2px solid var(--if-primary, #0d9488); outline-offset: 1px; }
    .ts-nav a[aria-current="true"] { color: var(--if-primary, #0d9488); background: rgba(var(--if-primary-rgb, 13, 148, 136), .12); }
    .ts-nav a.ts-dirty::after { content: ""; flex: 0 0 auto; width: .5rem; height: .5rem; border-radius: 50%; background: var(--tblr-warning, #f59f00); }
    .ts-section + .ts-section { margin-top: 2.25rem; }
    .ts-section-head { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: .5rem 1rem;
        margin-bottom: .9rem; padding-bottom: .6rem; border-bottom: 1px solid var(--if-border-strong, #d3dbdc); }
    .ts-section-head h2 { margin: 0; font-size: 1.25rem; }
    .ts-section-head h2 i { color: var(--if-primary, #0d9488); }
    .ts-section-head p { margin: .2rem 0 0; font-size: .875rem; color: var(--if-muted, #5d6f76); }
    /* Read-only items (admin only, or kiosk items without Training kiosk Full). */
    .ts-page .card-header .badge { font-weight: 500; }
    .ts-page legend.form-label { float: none; font-size: inherit; }
    .ts-locked { color: var(--if-muted, #5d6f76); }
    .ts-locked i { color: var(--if-muted, #5d6f76); }
</style>

<div class="ts-page">

<nav class="ts-nav" aria-label="Training settings sections">
    <ul id="tsNav">
        <?php foreach ($ts_sections as $ts_id => [$ts_label, $ts_icon]) { ?>
            <li><a href="#<?php echo nullable_htmlentities($ts_id); ?>"><i class="fas fa-fw <?php echo nullable_htmlentities($ts_icon); ?>" aria-hidden="true"></i><?php echo nullable_htmlentities($ts_label); ?></a></li>
        <?php } ?>
    </ul>
</nav>

<!-- =========================================================================================== -->
<!-- General & media                                                                             -->
<!-- =========================================================================================== -->
<section id="general" class="ts-section" aria-labelledby="general-title">
<div class="ts-section-head">
    <div>
        <h2 id="general-title"><i class="fas fa-fw fa-sliders-h me-2" aria-hidden="true"></i>General &amp; media</h2>
        <p>Course defaults, upload limits, the YouTube key and stored media.</p>
    </div>
</div>

<?php if (!$tr_ready) {
    $ts_update_card('Training (LMS)', 'fa-hard-hat', $tr_error, 'Training settings could not be loaded.', 'The Training database tables');
} else { ?>

<form action="/agent/training_settings.php" method="post" autocomplete="off" id="trSettingsForm" data-ts-label="Course defaults">
    <input type="hidden" name="csrf_token" value="<?php echo nullable_htmlentities($ts_csrf); ?>">
    <div class="card mb-3">
        <div class="card-header py-3">
            <h3 class="card-title"><i class="fas fa-fw fa-sliders-h me-2" aria-hidden="true"></i>Defaults</h3>
        </div>
        <div class="card-body">
            <fieldset class="mb-3">
                <legend class="form-label">Languages offered to course authors</legend>
                <?php foreach (TrainingSettings::KNOWN_LANGUAGES as $tr_code => $tr_label) { ?>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" name="training_languages[]" value="<?php echo nullable_htmlentities($tr_code); ?>" id="trLang_<?php echo nullable_htmlentities($tr_code); ?>"
                            <?php if (in_array($tr_code, $tr_settings->languages, true)) { echo 'checked'; } ?> <?php if ($tr_code === 'en') { echo 'disabled'; } ?>>
                        <label class="form-check-label" for="trLang_<?php echo nullable_htmlentities($tr_code); ?>"><?php echo nullable_htmlentities($tr_label); ?></label>
                    </div>
                <?php } ?>
                <div class="form-text">English is always offered. A course publishes Spanish only when its Spanish content is complete.</div>
            </fieldset>
            <div class="row">
                <div class="col-sm-6 col-lg-3 mb-3">
                    <label class="form-label" for="trPassPct">Default pass mark (%)</label>
                    <input type="number" class="form-control" id="trPassPct" name="config_training_default_pass_pct" min="50" max="100" step="1" required
                           value="<?php echo intval($tr_settings->defaultPassPct); ?>">
                </div>
                <div class="col-sm-6 col-lg-3 mb-3">
                    <label class="form-label" for="trAttempts">Default attempts</label>
                    <input type="number" class="form-control" id="trAttempts" name="config_training_default_max_attempts" min="0" max="10" step="1" required
                           aria-describedby="trAttemptsHelp" value="<?php echo intval($tr_settings->defaultMaxAttempts); ?>">
                    <div class="form-text" id="trAttemptsHelp">0 = unlimited.</div>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label" for="trAttestation">Default attestation text</label>
                <textarea class="form-control" id="trAttestation" name="config_training_attestation_text" rows="3" maxlength="5000" aria-describedby="trAttestationHelp"
                          placeholder="I completed this training and understand it."><?php echo nullable_htmlentities($tr_settings->attestationDefault ?? ''); ?></textarea>
                <div class="form-text" id="trAttestationHelp">Prefills new courses; each course can change it.</div>
            </div>
            <button type="submit" name="edit_training_settings" class="btn btn-primary"><i class="fas fa-check me-2" aria-hidden="true"></i>Save course defaults</button>
        </div>
    </div>
</form>

<!-- Media limits (admin only) ------------------------------------------------------------ -->
<div class="card mb-3" id="media-limits">
    <div class="card-header py-3 d-flex align-items-center">
        <h3 class="card-title"><i class="fas fa-fw fa-photo-video me-2" aria-hidden="true"></i>Media limits</h3>
        <?php echo ts_locked_badge(); ?>
    </div>
    <div class="card-body">
        <div class="row">
            <?php foreach ([
                ['trVideoMb', 'Video upload (MB)', intval($tr_settings->videoMaxBytes / TrainingSettings::MB)],
                ['trPdfMb', 'PDF upload (MB)', intval($tr_settings->pdfMaxBytes / TrainingSettings::MB)],
                ['trPdfPages', 'PDF pages (max)', intval($tr_settings->pdfMaxPages)],
                ['trImageMb', 'Image upload (MB)', intval($tr_settings->imageMaxBytes / TrainingSettings::MB)],
                ['trFileMb', 'Resource file (MB)', intval($tr_settings->fileMaxBytes / TrainingSettings::MB)],
                ['trBudgetMb', 'Media budget (MB)', intval($tr_settings->budgetBytes / TrainingSettings::MB)],
            ] as [$ts_fid, $ts_flabel, $ts_fval]) { ?>
                <div class="col-sm-6 col-lg-4 mb-3">
                    <label class="form-label" for="<?php echo nullable_htmlentities($ts_fid); ?>"><?php echo nullable_htmlentities($ts_flabel); ?></label>
                    <input type="number" class="form-control" id="<?php echo nullable_htmlentities($ts_fid); ?>" value="<?php echo intval($ts_fval); ?>" disabled>
                </div>
            <?php } ?>
        </div>
        <?php echo ts_locked_note('admin', 'media-limits', $ts_admin); ?>
    </div>
</div>

<!-- YouTube Data API key (admin only) ------------------------------------------------------ -->
<div class="card mb-3" id="youtube">
    <div class="card-header py-3 d-flex align-items-center">
        <h3 class="card-title"><i class="fab fa-fw fa-youtube me-2" aria-hidden="true"></i>YouTube Data API key</h3>
        <?php echo ts_locked_badge(); ?>
    </div>
    <div class="card-body">
        <p class="text-muted small">
            Optional. With a key, a YouTube video's length and its live/embeddable status are read when the link is added.
            Without one, the length is taken from the verified play.
        </p>
        <div class="d-flex align-items-center gap-2 mb-2">
            <span class="fw-bold">Status</span>
            <?php if (!empty($tr_row['youtube_key_set'])) { ?>
                <span class="badge text-bg-success">&bull;&bull;&bull;&bull; set</span>
            <?php } else { ?>
                <span class="badge text-bg-secondary">Not set</span>
            <?php } ?>
        </div>
        <?php echo ts_locked_note('admin', 'youtube', $ts_admin); ?>
    </div>
</div>

<!-- Media storage ---------------------------------------------------------------------------- -->
<div class="card mb-3" id="media-storage">
    <div class="card-header py-3">
        <h3 class="card-title"><i class="fas fa-fw fa-hdd me-2" aria-hidden="true"></i>Media storage</h3>
    </div>
    <div class="card-body">
        <div class="d-flex justify-content-between small mb-1">
            <span><?php echo nullable_htmlentities(ts_fmt_bytes($tr_live_bytes)); ?> of <?php echo nullable_htmlentities(ts_fmt_bytes($tr_budget_bytes)); ?> budget</span>
            <span><?php echo nullable_htmlentities((string) $tr_budget_pct); ?>%</span>
        </div>
        <div class="progress mb-2" style="height: 10px;" role="img" aria-label="Media budget usage by kind">
            <?php foreach ($tr_usage as $tr_kind => $tr_k) {
                $tr_w = $tr_budget_bytes > 0 ? max(0.5, $tr_k['bytes'] * 100 / $tr_budget_bytes) : 0; ?>
                <div class="progress-bar <?php echo nullable_htmlentities($tr_kind_colors[$tr_kind] ?? 'bg-secondary'); ?>" style="width: <?php echo nullable_htmlentities(number_format(min(100, $tr_w), 2, '.', '')); ?>%"
                     title="<?php echo nullable_htmlentities(($tr_kind_labels[$tr_kind] ?? $tr_kind) . ': ' . ts_fmt_bytes($tr_k['bytes'])); ?>"></div>
            <?php } ?>
        </div>
        <?php if ($tr_usage) { ?>
            <ul class="list-inline small text-muted mb-3">
                <?php foreach ($tr_usage as $tr_kind => $tr_k) { ?>
                    <li class="list-inline-item me-3">
                        <span class="badge <?php echo nullable_htmlentities($tr_kind_colors[$tr_kind] ?? 'bg-secondary'); ?> me-1">&nbsp;</span>
                        <?php echo nullable_htmlentities(($tr_kind_labels[$tr_kind] ?? $tr_kind) . ': ' . ts_fmt_bytes($tr_k['bytes']) . ' (' . $tr_k['count'] . ')'); ?>
                    </li>
                <?php } ?>
            </ul>
        <?php } else { ?>
            <p class="small text-muted mb-3">No training media stored yet.</p>
        <?php } ?>
        <?php if ($tr_evidence !== null && intval($tr_evidence['count'] ?? 0) > 0) { ?>
            <p class="small text-muted mb-3">Evidence scans: <?php echo nullable_htmlentities(ts_fmt_bytes(intval($tr_evidence['bytes'])) . ' (' . intval($tr_evidence['count']) . ')'); ?> (not counted toward the budget)</p>
        <?php } ?>
        <div class="fw-bold">Unreferenced media</div>
        <p class="small text-muted mb-1">Files older than 7 days that no draft or published version uses can be purged, with a reason recorded in the training ledger.</p>
        <?php echo ts_locked_note('admin', 'media-storage', $ts_admin); ?>
    </div>
</div>

<?php } ?>
</section>


<!-- =========================================================================================== -->
<!-- Compliance & assignments                                                                    -->
<!-- =========================================================================================== -->
<section id="compliance" class="ts-section" aria-labelledby="compliance-title">
<div class="ts-section-head">
    <div>
        <h2 id="compliance-title"><i class="fas fa-fw fa-clipboard-check me-2" aria-hidden="true"></i>Compliance &amp; assignments</h2>
        <p>When training counts as due, the compliance target, evidence uploads, and recalculating on demand.</p>
    </div>
    <?php if ($tc_ready) { ?>
        <a href="/agent/training_dashboard.php" class="btn btn-outline-primary btn-sm"><i class="fas fa-fw fa-chart-pie me-1" aria-hidden="true"></i>Open Training overview</a>
    <?php } ?>
</div>

<?php if (!$tc_ready) {
    $ts_update_card('Training compliance', 'fa-clipboard-check', $tc_error, 'Training compliance settings could not be loaded.', 'The Training compliance tables');
} else { ?>

<!-- Compliance defaults ------------------------------------------------------------------------- -->
<form action="/agent/training_settings.php" method="post" autocomplete="off" data-ts-label="Compliance defaults">
    <input type="hidden" name="csrf_token" value="<?php echo nullable_htmlentities($ts_csrf); ?>">
    <input type="hidden" name="tc_section" value="defaults">
    <div class="card mb-3">
        <div class="card-header py-3">
            <h3 class="card-title"><i class="fas fa-fw fa-sliders-h me-2" aria-hidden="true"></i>Compliance defaults</h3>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-sm-6 col-lg-4 mb-3">
                    <label class="form-label" for="tcDueSoon">"Due soon" window (days)</label>
                    <input type="number" class="form-control" id="tcDueSoon" name="config_training_due_soon_days" min="0" max="365" step="1" required
                           aria-describedby="tcDueSoonHelp" value="<?php echo intval($tc_s->dueSoonDays); ?>">
                    <div class="form-text" id="tcDueSoonHelp">Assignments due within this many days show as due soon; certificates expiring within it show as expiring.</div>
                </div>
                <div class="col-sm-6 col-lg-4 mb-3">
                    <label class="form-label" for="tcReissue">Redo after a voided record (days)</label>
                    <input type="number" class="form-control" id="tcReissue" name="config_training_reissue_days" min="1" max="365" step="1" required
                           aria-describedby="tcReissueHelp" value="<?php echo intval($tc_s->reissueDays); ?>">
                    <div class="form-text" id="tcReissueHelp">When a record is voided and nothing else covers the course, the person is due again this many days later.</div>
                </div>
                <div class="col-sm-6 col-lg-4 mb-3">
                    <label class="form-label" for="tcReopen">Reopen window (days)</label>
                    <input type="number" class="form-control" id="tcReopen" name="config_training_reopen_window_days" min="0" max="365" step="1" required
                           aria-describedby="tcReopenHelp" value="<?php echo intval($tc_s->reopenWindowDays); ?>">
                    <div class="form-text" id="tcReopenHelp">Someone who moves back into a rule within this many days gets their old assignment back, with its original due date.</div>
                </div>
                <div class="col-sm-6 col-lg-4 mb-3">
                    <label class="form-label" for="tcTarget">Compliance target (%)</label>
                    <input type="number" class="form-control" id="tcTarget" name="config_training_compliance_target_pct" min="1" max="100" step="1" required
                           aria-describedby="tcTargetHelp" value="<?php echo intval($tc_s->targetPct); ?>">
                    <div class="form-text" id="tcTargetHelp">The target line on the dashboard trend and the heatmap's top band.</div>
                </div>
                <div class="col-sm-6 col-lg-4 mb-3">
                    <label class="form-label" for="tcEvidenceMb">Evidence scan upload (MB)</label>
                    <input type="number" class="form-control" id="tcEvidenceMb" name="config_training_evidence_max_mb" min="1" max="95" step="1" required
                           aria-describedby="tcEvidenceHelp" value="<?php echo intval($tc_s->evidenceMaxBytes / 1048576); ?>">
                    <div class="form-text" id="tcEvidenceHelp">Per file, at most 95 MB. Scans do not count toward the media budget.</div>
                </div>
                <div class="col-sm-6 col-lg-4 mb-3">
                    <label class="form-label" for="tcHireFill">Fill hire dates from Odoo for employees created on or after</label>
                    <input type="date" class="form-control" id="tcHireFill" value="<?php echo nullable_htmlentities($tc_s->hireFillSince ?? ''); ?>" disabled aria-describedby="tcHireFillHelp">
                    <div class="form-text" id="tcHireFillHelp"><?php echo $tc_s->hireFillSince === null ? 'Not set: hire dates are not filled from Odoo.' : 'Only empty hire dates are filled, on the next directory sync.'; ?></div>
                    <?php echo ts_locked_note('admin', 'compliance', $ts_admin); ?>
                </div>
            </div>
            <button type="submit" name="edit_training_compliance_settings" class="btn btn-primary"><i class="fas fa-check me-2" aria-hidden="true"></i>Save compliance defaults</button>
        </div>
    </div>
</form>

<!-- Maintenance -------------------------------------------------------------------------------- -->
<div class="card mb-3" id="maintenance">
    <div class="card-header py-3">
        <h3 class="card-title"><i class="fas fa-fw fa-tools me-2" aria-hidden="true"></i>Maintenance</h3>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-6 mb-3 mb-md-0">
                <div class="fw-bold">Assignments</div>
                <p class="small text-muted mb-2">
                    <?php echo intval($tc_open['open']); ?> open, <?php echo intval($tc_open['overdue']); ?> overdue.
                    Last full recalculation:
                    <?php $tc_rec = ts_local_time($tc_s->reconciledAtUtc); echo $tc_rec !== null ? nullable_htmlentities($tc_rec) : 'never'; ?>.
                    It also runs nightly and after every rule, roster or record change.
                </p>
                <form action="/agent/training_settings.php" method="post" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?php echo nullable_htmlentities($ts_csrf); ?>">
                    <button type="submit" name="training_reconcile_now" class="btn btn-outline-primary"><i class="fas fa-fw fa-calculator me-1" aria-hidden="true"></i>Recalculate assignments now</button>
                </form>
            </div>
            <div class="col-md-6">
                <div class="fw-bold">Compliance snapshot</div>
                <p class="small text-muted mb-2">
                    The dashboard trend reads one snapshot per day. Last snapshot:
                    <?php echo $tc_s->snapshotLastOn !== null ? nullable_htmlentities($tc_s->snapshotLastOn) : 'never'; ?>.
                    Capturing again on the same day replaces that day's numbers.
                </p>
                <form action="/agent/training_settings.php" method="post" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?php echo nullable_htmlentities($ts_csrf); ?>">
                    <button type="submit" name="training_snapshot_now" class="btn btn-outline-primary"><i class="fas fa-fw fa-camera me-1" aria-hidden="true"></i>Capture today's snapshot</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php } ?>
</section>

<!-- =========================================================================================== -->
<!-- Employee links (Odoo): read-only here                                                       -->
<!-- =========================================================================================== -->
<section id="odoo" class="ts-section" aria-labelledby="odoo-title">
<div class="ts-section-head">
    <div>
        <h2 id="odoo-title"><i class="fas fa-fw fa-address-card me-2" aria-hidden="true"></i>Employee links (Odoo)</h2>
        <p>Which Odoo employee each person is, the checks that keep those links right, and the nightly directory sync.</p>
    </div>
</div>

<?php if (!$tc_ready) { ?>
    <p class="text-muted">Available once the Training compliance tables are installed (see Compliance &amp; assignments above).</p>
<?php } else { ?>

<div class="card mb-3">
    <div class="card-header py-3 d-flex align-items-center">
        <h3 class="card-title"><i class="fas fa-fw fa-link me-2" aria-hidden="true"></i>Odoo employee links</h3>
        <?php echo ts_locked_badge(); ?>
    </div>
    <div class="card-body">
        <?php if ($tc_integration === null) { ?>
            <p class="text-muted">No Odoo integration is configured.</p>
        <?php } else { ?>
            <dl class="row mb-3">
                <dt class="col-sm-3">Connection</dt>
                <dd class="col-sm-9">
                    <?php if ($tc_staging) { ?><span class="badge text-bg-warning">Points at STAGING</span><?php } else { ?><span class="text-muted">Configured</span><?php } ?>
                    <?php if (empty($tc_integration['enabled'])) { ?><span class="badge text-bg-secondary ms-2">Integration disabled</span><?php } ?>
                </dd>
                <dt class="col-sm-3">Accepted target</dt>
                <dd class="col-sm-9">
                    <?php if ($tc_target_state === 'accepted') { ?>
                        <span class="badge text-bg-success">Accepted</span>
                    <?php } elseif ($tc_target_state === 'unset') { ?>
                        <span class="badge text-bg-secondary">Not set yet</span>
                    <?php } else { ?>
                        <span class="badge text-bg-danger">Changed</span>
                        <span class="text-muted small ms-1">Directory sync is blocked until the links are checked.</span>
                    <?php } ?>
                </dd>
                <dt class="col-sm-3">Last check</dt>
                <dd class="col-sm-9">
                    <?php $tc_checked = ts_local_time($tc_status['checked_at_utc'] ?? null); ?>
                    <?php echo $tc_checked !== null ? nullable_htmlentities($tc_checked) : '<span class="text-muted">Never</span>'; ?>
                </dd>
                <dt class="col-sm-3">Links</dt>
                <dd class="col-sm-9 d-flex flex-wrap gap-1">
                    <?php if ($tc_status_error) { ?>
                        <span class="text-danger small">The link list could not be loaded. The details were written to the server error log.</span>
                    <?php } else {
                        foreach ($tc_state_chips as $tc_state => [$tc_cls, $tc_label]) {
                            if (intval($tc_counts[$tc_state] ?? 0) === 0 && $tc_state !== 'ok') { continue; } ?>
                            <span class="badge <?php echo nullable_htmlentities($tc_cls); ?>"><?php echo intval($tc_counts[$tc_state] ?? 0) . ' ' . nullable_htmlentities($tc_label); ?></span>
                        <?php }
                    } ?>
                </dd>
            </dl>
        <?php } ?>
        <?php echo ts_locked_note('admin', 'odoo', $ts_admin); ?>
    </div>
</div>

<div class="card mb-3" id="odoo-sync">
    <div class="card-header py-3 d-flex align-items-center">
        <h3 class="card-title"><i class="fas fa-fw fa-moon me-2" aria-hidden="true"></i>Nightly Odoo directory sync</h3>
        <?php echo ts_locked_badge(); ?>
    </div>
    <div class="card-body">
        <dl class="row small mb-3">
            <dt class="col-sm-3">Every night at 4:30</dt>
            <dd class="col-sm-9"><?php echo $tc_s->odooSyncEnabled ? '<span class="badge text-bg-success">On</span>' : '<span class="badge text-bg-secondary">Off</span>'; ?></dd>
            <dt class="col-sm-3">Last automatic run</dt>
            <dd class="col-sm-9"><?php echo $tc_s->odooSyncLastOn !== null ? nullable_htmlentities($tc_s->odooSyncLastOn) : '<span class="text-muted">Never</span>'; ?></dd>
            <dt class="col-sm-3">Result</dt>
            <dd class="col-sm-9 text-break">
                <?php if ($tc_s->odooSyncLastResult !== null) { ?>
                    <span class="badge <?php echo str_starts_with($tc_s->odooSyncLastResult, 'ok') ? 'text-bg-success' : 'text-bg-danger'; ?> me-1"><?php echo str_starts_with($tc_s->odooSyncLastResult, 'ok') ? 'OK' : 'Failed'; ?></span>
                    <span class="font-monospace"><?php echo nullable_htmlentities($tc_s->odooSyncLastResult); ?></span>
                <?php } else { ?>
                    <span class="text-muted">&mdash;</span>
                <?php } ?>
            </dd>
        </dl>
        <?php echo ts_locked_note('admin', 'odoo-sync', $ts_admin); ?>
    </div>
</div>

<?php } ?>
</section>

<!-- =========================================================================================== -->
<!-- Kiosk & sign-in                                                                             -->
<!-- =========================================================================================== -->
<section id="kiosk" class="ts-section" aria-labelledby="kiosk-title">
<div class="ts-section-head">
    <div>
        <h2 id="kiosk-title"><i class="fas fa-fw fa-tablet-alt me-2" aria-hidden="true"></i>Kiosk &amp; sign-in</h2>
        <p>Sign-out timers, PIN lockouts and wrong-PIN caps for the shop iPads and PCs, and Odoo PIN sign-in.</p>
    </div>
</div>

<?php if (!$tk_ready) {
    $ts_update_card('Training kiosk', 'fa-tablet-alt', $tk_error, 'Training kiosk settings could not be loaded.', 'The Training kiosk settings');
} else { ?>

<div class="card mb-3">
    <div class="card-header py-3 d-flex align-items-center">
        <h3 class="card-title"><i class="fas fa-fw fa-tablet-alt me-2" aria-hidden="true"></i>Training kiosk</h3>
        <?php if ($ts_klevel >= 1 && is_file(__DIR__ . '/training_devices.php')) { ?>
            <a href="/agent/training_devices.php" class="btn btn-outline-primary btn-sm ms-auto"><i class="fas fa-fw fa-tablet-alt me-1" aria-hidden="true"></i>Devices &amp; PINs</a>
        <?php } ?>
    </div>
    <div class="card-body">
        <p class="mb-2">Employees sign in on an enrolled iPad or shop PC at <code>/kiosk/</code> by typing their name and then their PIN.</p>
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

<?php if ($ts_can_kiosk) { ?>
<form action="/agent/training_settings.php" method="post" autocomplete="off" id="tkSettingsForm" data-ts-label="Kiosk &amp; sign-in">
    <input type="hidden" name="csrf_token" value="<?php echo nullable_htmlentities($ts_csrf); ?>">
<?php } ?>

    <?php foreach ($tk_groups as $tk_g) {
        $tk_editable = SettingsPolicy::canOnAgentPage($tk_g['item'], $ts_admin, $ts_tlevel, $ts_klevel);
        $tk_why = SettingsPolicy::adminOnly($tk_g['item']) ? 'admin' : 'kiosk'; ?>
    <div class="card mb-3">
        <div class="card-header py-3 d-flex align-items-center">
            <h3 class="card-title"><i class="fas fa-fw <?php echo nullable_htmlentities($tk_g['icon']); ?> me-2" aria-hidden="true"></i><?php echo nullable_htmlentities($tk_g['title']); ?></h3>
            <?php if (!$tk_editable) { echo ts_locked_badge(); } ?>
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
                        <?php if ($tk_editable) { ?>
                            <input type="number" class="form-control" id="<?php echo nullable_htmlentities($tk_id); ?>" name="<?php echo nullable_htmlentities($tk_col); ?>"
                                   min="<?php echo intval($tk_min); ?>" max="<?php echo intval($tk_max); ?>" step="1" required value="<?php echo intval($tk->{$tk_prop}); ?>"
                                   aria-describedby="<?php echo nullable_htmlentities($tk_id); ?>_help">
                        <?php } else { ?>
                            <input type="number" class="form-control" id="<?php echo nullable_htmlentities($tk_id); ?>" value="<?php echo intval($tk->{$tk_prop}); ?>" disabled
                                   aria-describedby="<?php echo nullable_htmlentities($tk_id); ?>_help">
                        <?php } ?>
                        <span class="input-group-text"><?php echo nullable_htmlentities($tk_unit); ?></span>
                    </div>
                    <div class="form-text" id="<?php echo nullable_htmlentities($tk_id); ?>_help">
                        <?php echo nullable_htmlentities(trim($tk_help . ' Default ' . $tk_def . ', allowed ' . $tk_min . '–' . $tk_max . '.')); ?>
                    </div>
                </div>
                <?php } ?>
            </div>
            <?php if (!$tk_editable) { echo ts_locked_note($tk_why, 'kiosk', $ts_admin); } ?>
        </div>
    </div>
    <?php } ?>

    <div class="card mb-3">
        <div class="card-header py-3 d-flex align-items-center">
            <h3 class="card-title"><i class="fas fa-fw fa-id-badge me-2" aria-hidden="true"></i>Odoo PIN sign-in</h3>
            <?php echo ts_locked_badge(); ?>
        </div>
        <div class="card-body">
            <div class="d-flex align-items-center gap-2 mb-2">
                <span class="fw-bold">Employees sign in with their Odoo (time clock) PIN</span>
                <?php echo $tk->odooPinEnabled ? '<span class="badge text-bg-success">On</span>' : '<span class="badge text-bg-secondary">Off</span>'; ?>
            </div>
            <p class="text-muted small">
                While this is off, everyone uses a training PIN from a printed setup slip. Trainers always use a training PIN.
                <?php if ($tk->pinSourcesSyncedAtUtc !== null) { ?>
                    PIN sources were last refreshed <span class="font-monospace"><?php echo nullable_htmlentities($tk->pinSourcesSyncedAtUtc); ?> UTC</span>.
                <?php } else { ?>
                    PIN sources have not been refreshed yet.
                <?php } ?>
            </p>
            <?php echo ts_locked_note('admin', 'kiosk', $ts_admin); ?>
        </div>
    </div>

<?php if ($ts_can_kiosk) { ?>
    <div class="mb-4">
        <button type="submit" name="edit_training_kiosk_settings" class="btn btn-primary"><i class="fas fa-fw fa-check me-2" aria-hidden="true"></i>Save sessions &amp; setup slips</button>
    </div>
</form>
<?php } ?>

<?php } ?>
</section>

<?php
// Certificates + Reminders & automation (LMS Phase 5, DB 2.6.96): the same sections as Admin > Training. Training 3 may
// change the signatory, reminders and video checks; Odoo write-back and the public check switch are read-only here.
define('TRAINING_AUTOMATION_PAGE', true);
$ta_admin_page = false;
$ta_is_admin = $ts_admin;
$ta_module_on = Access::enabled();
require __DIR__ . '/../admin/includes/training_automation/sections.php';
?>

<!-- =========================================================================================== -->
<!-- Records ledger                                                                              -->
<!-- =========================================================================================== -->
<section id="ledger" class="ts-section" aria-labelledby="ledger-title">
<div class="ts-section-head">
    <div>
        <h2 id="ledger-title"><i class="fas fa-fw fa-link me-2" aria-hidden="true"></i>Records ledger</h2>
        <p>The tamper check on training records, files and published versions.</p>
    </div>
</div>

<?php if (!$tr_ready) { ?>
    <p class="text-muted">Available once the Training tables are installed (see General &amp; media above).</p>
<?php } else { ?>
<div class="card mb-3">
    <div class="card-header py-3">
        <h3 class="card-title"><i class="fas fa-fw fa-link me-2" aria-hidden="true"></i>Records ledger</h3>
    </div>
    <div class="card-body">
        <p class="text-muted small">
            Every stored file, published version and archive action is written to a hash-chained ledger. Verification re-computes
            every hash; it runs nightly (deep, with file checks, on Sundays) and on demand here.
        </p>
        <dl class="row mb-3">
            <dt class="col-sm-3">Head</dt>
            <dd class="col-sm-9">
                <?php if ($tr_head) { ?>
                    <span class="font-monospace">#<?php echo intval($tr_head['seq']); ?> / <?php echo nullable_htmlentities(substr($tr_head['hash'], 0, 16)); ?></span>
                    <?php if ($tr_head_updated_iso) { ?><span class="text-muted small ms-2">updated <?php echo nullable_htmlentities(date('Y-m-d H:i', strtotime($tr_head_updated_iso))); ?></span><?php } ?>
                <?php } elseif ($tr_head_missing) { ?>
                    <span class="badge text-bg-danger">Ledger head missing</span>
                    <div class="small text-danger mt-1">The ledger head row is gone: the records were tampered with or a restore was incomplete. Run <strong>Verify now</strong> and tell an administrator.</div>
                <?php } ?>
            </dd>
            <dt class="col-sm-3">Last verified</dt>
            <dd class="col-sm-9">
                <?php if ($tr_verified_iso) { ?>
                    <span title="<?php echo nullable_htmlentities($tr_verified_iso); ?>"><?php echo nullable_htmlentities(date('Y-m-d H:i', strtotime($tr_verified_iso))); ?></span>
                <?php } else { ?>
                    <span class="text-muted">Never</span>
                <?php } ?>
            </dd>
            <dt class="col-sm-3">Result</dt>
            <dd class="col-sm-9">
                <?php if ($tr_result_line !== '') { ?>
                    <span class="badge <?php echo nullable_htmlentities($tr_result_badge); ?> font-monospace"><?php echo nullable_htmlentities($tr_result_line); ?></span>
                <?php } else { ?>
                    <span class="text-muted">&mdash;</span>
                <?php } ?>
            </dd>
        </dl>
        <form action="/agent/training_settings.php" method="post" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?php echo nullable_htmlentities($ts_csrf); ?>">
            <button type="submit" name="training_ledger_verify" class="btn btn-outline-primary"><i class="fas fa-fw fa-check-double me-1" aria-hidden="true"></i>Verify now</button>
        </form>
        <span class="text-muted small ms-2">Shallow check (no file re-hash), stops after 60 seconds. A problem alerts every administrator.</span>
    </div>
</div>
<?php } ?>
</section>

</div><!-- /.ts-page -->

<script src="/agent/js/training_settings.js?v=<?php echo intval(@filemtime(__DIR__ . '/js/training_settings.js')); ?>" defer></script>
<?php
require_once "../includes/footer.php";
