<?php
require_once "includes/inc_all_admin.php";

/*
 * Admin > Training: every Training setting on one page. Until 2026-09-26 this was three pages
 * (Training, Training compliance, Training kiosk); the old URLs now 302 here (#compliance, #kiosk).
 *
 * Sections, in page order, each with an anchor that the section nav, deep links, notifications
 * and the post handler's redirects use:
 *   #general     General & media (spec §5.11): module status, defaults, media limits (#media-limits),
 *                the YouTube key (#youtube) and media storage with the unreferenced-media purge
 *                (#media-storage).
 *   #compliance  Compliance & assignments (Phase 2 spec §5.3, M15 card 1): due-soon / reissue /
 *                reopen-window days, target %, evidence cap, the opt-in hire-date fill; and
 *                maintenance (#maintenance): Recalculate assignments now, Capture today's snapshot.
 *   #odoo        Employee links (Odoo) (M15 card 2, S6, plan A22): the configured Odoo target, the
 *                accepted-target state, Check now, Accept new target, per-link Relink / Unlink /
 *                Confirm; and the nightly Odoo directory sync switch (#odoo-sync).
 *   #kiosk       Kiosk & sign-in (P3 spec §5.8 [S], P-10): kiosk thresholds and the Odoo-PIN switch.
 *   #certificates  Certificates (LMS Phase 5, DB 2.6.96): the certificate signatory, sample PDF and the public
 *                certificate check switch (Lane C's card).
 *   #automation  Reminders & automation (Phase 5): reminder digests (#reminders), external video checks
 *                (#video-watch), Odoo write-back (#odoo-writeback) and the worker (#automation-worker). Both
 *                sections come from admin/includes/training_automation/sections.php, shared with
 *                agent/training_settings.php; their ta_* forms post to admin/post/settings_training_automation.php.
 *   #ledger      Records ledger: head, last verification, Verify now.
 *
 * Each section renders on its own readiness guard, exactly as its former page did, so an install
 * whose 2.6.91 / 2.6.92 / 2.6.93 migration has not run shows that section's "run the database
 * update" card instead of a 500. GET never calls Odoo: the link table is OdooLinkChecker::status(),
 * read from the DB.
 *
 * Every form posts to admin/post.php with this page as the Referer, so admin/post.php includes
 * admin/post/settings_training.php for all of them. That handler runs the General/ledger actions
 * itself and requires admin/post/settings_training_compliance.php and settings_training_kiosk.php
 * for the rest; it returns each action to its section's anchor.
 *
 * The section nav is plain anchor links; the first script below only marks the section in view.
 * Each form saves on its own, so the second script guards unsaved changes: saving one form while
 * another has edits asks first (confirm), leaving the page with edits asks too (beforeunload), and
 * the nav marks sections with edits. Both scripts keep their state in memory; nothing is stored in
 * the browser. Forms that hold settings name themselves for that prompt with data-ts-label.
 */

use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Ledger;
use ITFlow\Training\Core\MediaUsage;
use ITFlow\Training\Core\RecordsSettings;
use ITFlow\Training\Core\TrainingSettings;
use ITFlow\Training\Directory\OdooLinkChecker;
use ITFlow\Training\Directory\OdooTarget;
use ITFlow\Training\Kiosk\Core\KioskSettings;
use ITFlow\Training\Kiosk\Core\KTime;

/* ============================================================================================
 * General & media + Records ledger (spec §5.11)
 * ============================================================================================ */

$tr_ready = false;
$tr_error = false;
$tr_row = [];
$tr_head = null;
$tr_head_missing = false;    // head row gone (tamper / bad restore): the page still renders, Verify now reports it
$tr_usage = [];
$tr_live_bytes = 0;
$tr_evidence = null;         // {bytes, count} of evidence scans (Phase 2), outside the budget
$tr_unreferenced = null;     // null = purger not installed yet
$tr_unreferenced_error = false;

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
            // Phase 2: evidence scans are records, not course content - never counted toward the budget
            // (MediaUsage::liveBytes excludes them too); shown on their own line under the bar.
            if (isset($tr_usage['evidence'])) {
                $tr_evidence = $tr_usage['evidence'];
                unset($tr_usage['evidence']);
            }
            foreach ($tr_usage as $tr_k) {
                $tr_live_bytes += $tr_k['bytes'];
            }
            $tr_ready = true;

            if (class_exists('ITFlow\Training\Media\MediaPurger')) {
                try {
                    $tr_purger = new \ITFlow\Training\Media\MediaPurger(\ITFlow\Training\Core\Access::ctx($mysqli));
                    $tr_unreferenced = $tr_purger->unreferenced(7);
                } catch (\Throwable $e) {
                    error_log('Admin Training settings: unreferenced media lookup failed: ' . get_class($e) . ': ' . $e->getMessage());
                    $tr_unreferenced_error = true;
                }
            }
        }
    } catch (\Throwable $e) {
        error_log('Admin Training settings: ' . get_class($e) . ': ' . $e->getMessage());
        $tr_error = true;
        $tr_ready = false;
    }
}

$tr_settings = TrainingSettings::fromRow($tr_row, $tr_ready);
$tr_budget_bytes = $tr_settings->budgetBytes;
$tr_budget_pct = $tr_budget_bytes > 0 ? min(100, round($tr_live_bytes * 100 / $tr_budget_bytes, 1)) : 0;

// Projected size of one in-app backup: everything under uploads/ (zipped, but media barely
// compresses) plus the last database dump. Measured, not estimated from settings.
$tr_uploads_bytes = 0;
$tr_uploads_partial = false;
$tr_uploads_root = realpath(dirname(__DIR__) . '/uploads');
if ($tr_uploads_root && is_dir($tr_uploads_root)) {
    $tr_scan_deadline = microtime(true) + 3;
    try {
        $tr_it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tr_uploads_root, FilesystemIterator::SKIP_DOTS));
        foreach ($tr_it as $tr_f) {
            if ($tr_f->isFile() && !$tr_f->isLink()) {
                $tr_uploads_bytes += $tr_f->getSize();
            }
            if (microtime(true) > $tr_scan_deadline) {
                $tr_uploads_partial = true;
                break;
            }
        }
    } catch (\Throwable $e) {
        $tr_uploads_partial = true;
    }
}
$tr_db_dump_bytes = null;
$tr_backups = glob(dirname(__DIR__) . '/backups/itflow_*.zip') ?: [];
usort($tr_backups, fn($a, $b) => filemtime($b) - filemtime($a));
if ($tr_backups) {
    $tr_zip = new ZipArchive();
    if ($tr_zip->open($tr_backups[0]) === true) {
        $tr_stat = $tr_zip->statName('db.sql');
        if ($tr_stat !== false) {
            $tr_db_dump_bytes = (int) $tr_stat['size'];
        }
        $tr_zip->close();
    }
}
$tr_projected_bytes = $tr_uploads_bytes + (int) $tr_db_dump_bytes;

function tr_admin_fmt_bytes(int $b): string {
    if ($b < 1024) return $b . ' B';
    $u = ['KB', 'MB', 'GB', 'TB'];
    $i = -1;
    $v = $b;
    do { $v /= 1024; $i++; } while ($v >= 1024 && $i < count($u) - 1);
    return ($v >= 100 ? round($v) : rtrim(rtrim(number_format($v, 1), '0'), '.')) . ' ' . $u[$i];
}

// The module switch appears in Modules only once the Training pages are installed (the course
// pages ship after this foundation update); until then there is nothing to open.
$tr_pages_ready = is_file(dirname(__DIR__) . '/agent/training_courses.php');

$tr_kind_labels = ['pdf' => 'PDF documents', 'page' => 'PDF page images', 'video' => 'Videos', 'image' => 'Images', 'file' => 'Resource files', 'caption' => 'Caption files', 'evidence' => 'Evidence'];
$tr_kind_colors = ['pdf' => 'bg-red', 'page' => 'bg-orange', 'video' => 'bg-purple', 'image' => 'bg-cyan', 'file' => 'bg-blue', 'caption' => 'bg-teal', 'evidence' => 'bg-secondary'];

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
 * Compliance & assignments + Employee links (Odoo) (Phase 2 spec §5.3)
 * Renders before the 2.6.92 migration: everything that touches a Phase 2 table or column is
 * behind $config_training_schema_ready plus a table-exists check and a try/catch.
 * ============================================================================================ */

$tc_ready = false;
$tc_error = false;
$tc_s = null;                  // RecordsSettings
$tc_row = [];                  // module switch
$tc_integration = null;        // latest odoo_integrations row (no key material is rendered)
$tc_status = null;             // OdooLinkChecker::status()
$tc_status_error = false;
$tc_open = ['open' => 0, 'overdue' => 0];
$tc_classes_ok = class_exists(OdooLinkChecker::class) && class_exists(RecordsSettings::class);

if (!empty($config_training_schema_ready) && $tc_classes_ok) {
    try {
        $tc_tbl = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME IN ('contact_odoo_attributes', 'training_assignments', 'training_compliance_daily')"));
        if (intval($tc_tbl['n'] ?? 0) === 3) {
            $tc_s = RecordsSettings::fromDb($mysqli);
            $tc_ready = $tc_s->schemaReady;
        }
        if ($tc_ready) {
            $tc_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_module_enable_training FROM settings WHERE company_id = 1")) ?: [];
            $tc_open_row = \ITFlow\Training\Core\Db::one($mysqli, "SELECT COUNT(*) AS open_n, COALESCE(SUM(tassign_due_on < ?), 0) AS overdue_n
                FROM training_assignments WHERE tassign_status = 'open'", 's', [Clock::todayLocal()]);
            $tc_open = ['open' => intval($tc_open_row['open_n'] ?? 0), 'overdue' => intval($tc_open_row['overdue_n'] ?? 0)];
            $tc_integration = OdooLinkChecker::latestIntegration($mysqli);
            if ($tc_integration !== null) {
                try {
                    $tc_status = (new OdooLinkChecker($mysqli, $tc_integration))->status();
                } catch (\Throwable $e) {
                    error_log('Admin Training compliance: link status failed: ' . get_class($e) . ': ' . $e->getMessage());
                    $tc_status_error = true;
                }
            }
        }
    } catch (\Throwable $e) {
        error_log('Admin Training compliance: ' . get_class($e) . ': ' . $e->getMessage());
        $tc_error = true;
        $tc_ready = false;
    }
}

/** A UTC DATETIME(3) as local "Y-m-d H:i", or null. */
function tc_local_time(?string $utc): ?string {
    if ($utc === null || $utc === '') {
        return null;
    }
    $iso = Clock::toIso($utc, true);
    return $iso ? date('Y-m-d H:i', strtotime($iso)) : null;
}

$tc_state_chips = [
    'ok'        => ['text-bg-success', 'OK'],
    'unchecked' => ['text-bg-secondary', 'Not checked yet'],
    'mismatch'  => ['text-bg-warning', 'Name changed'],
    'missing'   => ['text-bg-danger', 'Missing in Odoo'],
    'repointed' => ['text-bg-danger', 'Re-pointed'],
];

// Odoo target facts (the key is never read here; base URL and database only).
$tc_host = '';
$tc_staging = false;
$tc_target_state = 'none';     // none | unset | accepted | pending
if ($tc_integration !== null) {
    $tc_host = (string) (parse_url(trim((string) $tc_integration['base_url']), PHP_URL_HOST) ?: trim((string) $tc_integration['base_url']));
    $tc_staging = stripos((string) $tc_integration['base_url'], 'staging') !== false || stripos((string) $tc_integration['database_name'], 'staging') !== false;
    if ($tc_status !== null) {
        $tc_target_state = $tc_status['target']['accepted'] === null ? 'unset' : ($tc_status['target']['pending'] ? 'pending' : 'accepted');
    } else {
        $tc_target = OdooTarget::guard($mysqli, $tc_integration);
        $tc_target_state = $tc_target['accepted_sha'] === null ? 'unset' : ($tc_target['ok'] ? 'accepted' : 'pending');
    }
}

$tc_flag_states = ['repointed', 'mismatch', 'missing'];
$tc_flagged = [];
$tc_other = [];
foreach (($tc_status['rows'] ?? []) as $tc_r) {
    if (in_array($tc_r['state'], $tc_flag_states, true)) {
        $tc_flagged[] = $tc_r;
    } else {
        $tc_other[] = $tc_r;
    }
}
$tc_counts = $tc_status['counts'] ?? [];
$tc_csrf = $_SESSION['csrf_token'] ?? '';

/* ============================================================================================
 * Kiosk & sign-in (P3 spec §5.8 [S], P-10). The defaults are pilot-ready; everything is clamped
 * again on the server (admin/post/settings_training_kiosk.php via KioskSettings::clamp) and on
 * every read. Renders before the 2.6.93 migration has run (column-exists guard).
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
    ['icon' => 'fa-receipt', 'title' => 'Setup slips & codes', 'intro' => 'Printed PIN setup slips for people, and setup codes for iPads and PCs set up remotely (Devices & PINs > Get setup codes).', 'fields' => [
        ['config_training_setup_code_days', 'A PIN setup code works for', 'days', ''],
        ['config_training_device_code_days', 'A device setup code works for', 'days', 'Typed into a device\'s "Enter a setup code" screen; shorter by default since an unused code sits on the hardware.'],
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

// Section nav: anchor => [label, icon]. The anchors are also the post handler's return targets.
$ts_sections = [
    'general'    => ['General & media', 'fa-sliders-h'],
    'compliance' => ['Compliance & assignments', 'fa-clipboard-check'],
    'odoo'       => ['Employee links (Odoo)', 'fa-address-card'],
    'kiosk'      => ['Kiosk & sign-in', 'fa-tablet-alt'],
    'certificates' => ['Certificates', 'fa-certificate'],
    'automation' => ['Reminders & automation', 'fa-robot'],
    'ledger'     => ['Records ledger', 'fa-link'],
];
$ts_module_on = !empty($config_module_enable_training);
?>

<!-- Plain .card throughout, not .card-dark - see the note in admin/settings_module.php. -->
<style nonce="<?php echo nullable_htmlentities($csp_nonce ?? ''); ?>">
    /* Every anchor target (sections, sub-cards, fields focused by the browser) clears the sticky nav. */
    .ts-page [id] { scroll-margin-top: 5rem; }
    @media (prefers-reduced-motion: no-preference) {
        html:has(.ts-page) { scroll-behavior: smooth; }
    }
    .ts-head { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: .75rem 1rem; margin-bottom: 1rem; }
    .ts-head h1 { margin: 0; }
    .ts-head p { margin: .15rem 0 0; color: var(--if-muted, #5d6f76); }
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
    /* A section with unsaved edits (set by the unsaved-changes script; the link also gets a hidden " (unsaved changes)"). */
    .ts-nav a.ts-dirty::after { content: ""; flex: 0 0 auto; width: .5rem; height: .5rem; border-radius: 50%; background: var(--tblr-warning, #f59f00); }
    .ts-section + .ts-section { margin-top: 2.25rem; }
    .ts-section-head { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: .5rem 1rem;
        margin-bottom: .9rem; padding-bottom: .6rem; border-bottom: 1px solid var(--if-border-strong, #d3dbdc); }
    .ts-section-head h2 { margin: 0; font-size: 1.25rem; }
    .ts-section-head h2 i { color: var(--if-primary, #0d9488); }
    .ts-section-head p { margin: .2rem 0 0; font-size: .875rem; color: var(--if-muted, #5d6f76); }
</style>

<div class="ts-page">

<div class="ts-head">
    <div>
        <h1 class="h2"><i class="fas fa-fw fa-hard-hat me-2" aria-hidden="true"></i>Training</h1>
        <p class="small">Every setting for the Training module on one page. Pick a section, or scroll.</p>
        <p class="small"><i class="fas fa-fw fa-info-circle me-1" aria-hidden="true"></i>Each section saves on its own.</p>
    </div>
    <?php if ($ts_module_on && $tr_pages_ready) { ?>
        <div class="d-flex flex-wrap gap-2">
            <a href="/agent/training_courses.php" class="btn btn-outline-primary btn-sm"><i class="fas fa-fw fa-graduation-cap me-1"></i>Open Training</a>
        </div>
    <?php } ?>
</div>

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
        <p>Module status, course defaults, upload limits, the YouTube key and stored media.</p>
    </div>
</div>

<?php if (!$tr_ready) { ?>
<div class="card mb-3">
    <div class="card-header py-3">
        <h3 class="card-title"><i class="fas fa-fw fa-hard-hat me-2"></i>Training (LMS)</h3>
    </div>
    <div class="card-body">
        <?php if ($tr_error) { ?>
            <div class="alert alert-danger mb-0">Training settings could not be loaded. The details were written to the server error log.</div>
        <?php } else { ?>
            <p class="mb-2">The Training database tables are not installed yet.</p>
            <p class="text-muted mb-3">Run the database update (Admin &rsaquo; Update &rsaquo; <strong>Update Database</strong>) to add them. Nothing else in the app changes until the module is switched on.</p>
            <a href="/admin/update.php" class="btn btn-primary"><i class="fas fa-fw fa-database me-2"></i>Open Update</a>
        <?php } ?>
    </div>
</div>
<?php } else { ?>

<!-- Module ------------------------------------------------------------------------------ -->
<div class="card mb-3">
    <div class="card-header py-3">
        <h3 class="card-title"><i class="fas fa-fw fa-hard-hat me-2"></i>Training module</h3>
    </div>
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
            <span class="fw-bold">Module status</span>
            <?php if (intval($tr_row['config_module_enable_training'] ?? 0) === 1) { ?>
                <span class="badge text-bg-success">On</span>
            <?php } else { ?>
                <span class="badge text-bg-secondary">Off</span>
            <?php } ?>
            <?php if ($tr_pages_ready || intval($tr_row['config_module_enable_training'] ?? 0) === 1) { ?>
                <a href="/admin/settings_module.php" class="ms-2">Change in Modules</a>
            <?php } else { ?>
                <span class="text-muted small ms-2">The switch appears in Modules once the Training pages are installed (a later update).</span>
            <?php } ?>
        </div>
        <p class="text-muted small mb-0">
            Training is visible only to roles granted the <code>module_training</code> permission (1 Read, 2 Modify, 3 Full); admins always have full access.
            Suggested role for course authors: <strong>Training Author</strong> with <code>module_training</code> = 3 only.
            Leave <code>module_kb</code> off for that role unless its department rows are set, because department scoping fails open for users without department rows.
        </p>
    </div>
</div>

<form action="post.php" method="post" autocomplete="off" id="trSettingsForm" data-ts-label="General &amp; media">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">

    <!-- Defaults -------------------------------------------------------------------------- -->
    <div class="card mb-3">
        <div class="card-header py-3">
            <h3 class="card-title"><i class="fas fa-fw fa-sliders-h me-2"></i>Defaults</h3>
        </div>
        <div class="card-body">
            <div class="mb-3">
                <label class="form-label">Languages offered to course authors</label>
                <?php foreach (TrainingSettings::KNOWN_LANGUAGES as $tr_code => $tr_label) { ?>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" name="training_languages[]" value="<?php echo nullable_htmlentities($tr_code); ?>" id="trLang_<?php echo nullable_htmlentities($tr_code); ?>"
                            <?php if (in_array($tr_code, $tr_settings->languages, true)) { echo 'checked'; } ?> <?php if ($tr_code === 'en') { echo 'disabled'; } ?>>
                        <label class="form-check-label" for="trLang_<?php echo nullable_htmlentities($tr_code); ?>"><?php echo nullable_htmlentities($tr_label); ?></label>
                    </div>
                <?php } ?>
                <div class="form-text">English is always offered. A course publishes Spanish only when its Spanish content is complete.</div>
            </div>
            <div class="row">
                <div class="col-sm-6 col-lg-3 mb-3">
                    <label class="form-label" for="trPassPct">Default pass mark (%)</label>
                    <input type="number" class="form-control" id="trPassPct" name="config_training_default_pass_pct" min="50" max="100" step="1" required
                           value="<?php echo intval($tr_settings->defaultPassPct); ?>">
                </div>
                <div class="col-sm-6 col-lg-3 mb-3">
                    <label class="form-label" for="trAttempts">Default attempts</label>
                    <input type="number" class="form-control" id="trAttempts" name="config_training_default_max_attempts" min="0" max="10" step="1" required
                           value="<?php echo intval($tr_settings->defaultMaxAttempts); ?>">
                    <div class="form-text">0 = unlimited.</div>
                </div>
            </div>
            <div class="mb-0">
                <label class="form-label" for="trAttestation">Default attestation text</label>
                <textarea class="form-control" id="trAttestation" name="config_training_attestation_text" rows="3" maxlength="5000"
                          placeholder="I completed this training and understand it."><?php echo nullable_htmlentities($tr_settings->attestationDefault ?? ''); ?></textarea>
                <div class="form-text">Prefills new courses; each course can change it.</div>
            </div>
        </div>
    </div>

    <!-- Media limits ---------------------------------------------------------------------- -->
    <div class="card mb-3" id="media-limits">
        <div class="card-header py-3">
            <h3 class="card-title"><i class="fas fa-fw fa-photo-video me-2"></i>Media limits</h3>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-sm-6 col-lg-4 mb-3">
                    <label class="form-label" for="trVideoMb">Video upload (MB)</label>
                    <input type="number" class="form-control" id="trVideoMb" name="config_training_video_max_mb" min="1" max="<?php echo TrainingSettings::UPLOAD_CAP_MB; ?>" required
                           value="<?php echo intval($tr_settings->videoMaxBytes / TrainingSettings::MB); ?>">
                    <div class="form-text">At most <?php echo TrainingSettings::UPLOAD_CAP_MB; ?> MB. Longer videos go on the company YouTube channel as Unlisted.</div>
                </div>
                <div class="col-sm-6 col-lg-4 mb-3">
                    <label class="form-label" for="trPdfMb">PDF upload (MB)</label>
                    <input type="number" class="form-control" id="trPdfMb" name="config_training_pdf_max_mb" min="1" max="<?php echo TrainingSettings::UPLOAD_CAP_MB; ?>" required
                           value="<?php echo intval($tr_settings->pdfMaxBytes / TrainingSettings::MB); ?>">
                </div>
                <div class="col-sm-6 col-lg-4 mb-3">
                    <label class="form-label" for="trPdfPages">PDF pages (max)</label>
                    <input type="number" class="form-control" id="trPdfPages" name="config_training_pdf_max_pages" min="1" max="1000" required
                           value="<?php echo intval($tr_settings->pdfMaxPages); ?>">
                </div>
                <div class="col-sm-6 col-lg-4 mb-3">
                    <label class="form-label" for="trImageMb">Image upload (MB)</label>
                    <input type="number" class="form-control" id="trImageMb" name="config_training_image_max_mb" min="1" max="<?php echo TrainingSettings::UPLOAD_CAP_MB; ?>" required
                           value="<?php echo intval($tr_settings->imageMaxBytes / TrainingSettings::MB); ?>">
                </div>
                <div class="col-sm-6 col-lg-4 mb-3">
                    <label class="form-label" for="trFileMb">Resource file (MB)</label>
                    <input type="number" class="form-control" id="trFileMb" name="config_training_file_max_mb" min="1" max="<?php echo TrainingSettings::UPLOAD_CAP_MB; ?>" required
                           value="<?php echo intval($tr_settings->fileMaxBytes / TrainingSettings::MB); ?>">
                    <div class="form-text">At most <?php echo TrainingSettings::UPLOAD_CAP_MB; ?> MB.</div>
                </div>
                <div class="col-sm-6 col-lg-4 mb-3">
                    <label class="form-label" for="trBudgetMb">Media budget (MB)</label>
                    <input type="number" class="form-control" id="trBudgetMb" name="config_training_media_budget_mb" min="100" max="1048576" required
                           value="<?php echo intval($tr_settings->budgetBytes / TrainingSettings::MB); ?>">
                    <div class="form-text">Total stored training media. Uploads beyond it are refused.</div>
                </div>
            </div>
        </div>
    </div>

    <!-- YouTube Data API key -------------------------------------------------------------- -->
    <div class="card mb-3" id="youtube">
        <div class="card-header py-3">
            <h3 class="card-title"><i class="fab fa-fw fa-youtube me-2"></i>YouTube Data API key</h3>
        </div>
        <div class="card-body">
            <p class="text-muted small">
                Optional. With a key, a YouTube video's length and its live/embeddable status are read when the link is added.
                Without one, the length is taken from the verified play. The key is stored encrypted and never shown again.
            </p>
            <div class="d-flex align-items-center gap-2 mb-2">
                <span class="fw-bold">Status</span>
                <?php if (!empty($tr_row['youtube_key_set'])) { ?>
                    <span class="badge text-bg-success">&bull;&bull;&bull;&bull; set</span>
                <?php } else { ?>
                    <span class="badge text-bg-secondary">Not set</span>
                <?php } ?>
            </div>
            <div class="row align-items-end">
                <div class="col-lg-6 mb-2">
                    <label class="form-label" for="trYoutubeKey"><?php echo !empty($tr_row['youtube_key_set']) ? 'Replace key' : 'API key'; ?></label>
                    <input type="password" class="form-control" id="trYoutubeKey" name="config_training_youtube_api_key" maxlength="200" autocomplete="new-password" spellcheck="false"
                           placeholder="<?php echo !empty($tr_row['youtube_key_set']) ? 'Leave blank to keep the saved key' : 'Paste the key'; ?>">
                </div>
                <div class="col-lg-6 mb-2">
                    <?php if (!empty($tr_row['youtube_key_set'])) { ?>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" name="training_youtube_key_clear" value="1" id="trYoutubeKeyClear">
                            <label class="form-check-label" for="trYoutubeKeyClear">Remove the saved key</label>
                        </div>
                        <button type="submit" form="trYoutubeTestForm" name="training_youtube_key_test" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-fw fa-vial me-1"></i>Test key
                        </button>
                    <?php } ?>
                </div>
            </div>
        </div>
    </div>

    <div class="mb-4">
        <button type="submit" name="edit_training_settings" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Save general &amp; media</button>
    </div>
</form>

<form action="post.php" method="post" id="trYoutubeTestForm">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">
</form>

<!-- Media storage ---------------------------------------------------------------------------- -->
<div class="card mb-3" id="media-storage">
    <div class="card-header py-3">
        <h3 class="card-title"><i class="fas fa-fw fa-hdd me-2"></i>Media storage</h3>
    </div>
    <div class="card-body">
        <div class="d-flex justify-content-between small mb-1">
            <span><?php echo nullable_htmlentities(tr_admin_fmt_bytes($tr_live_bytes)); ?> of <?php echo nullable_htmlentities(tr_admin_fmt_bytes($tr_budget_bytes)); ?> budget</span>
            <span><?php echo nullable_htmlentities((string) $tr_budget_pct); ?>%</span>
        </div>
        <div class="progress mb-2" style="height: 10px;" role="img" aria-label="Media budget usage by kind">
            <?php foreach ($tr_usage as $tr_kind => $tr_k) {
                $tr_w = $tr_budget_bytes > 0 ? max(0.5, $tr_k['bytes'] * 100 / $tr_budget_bytes) : 0; ?>
                <div class="progress-bar <?php echo nullable_htmlentities($tr_kind_colors[$tr_kind] ?? 'bg-secondary'); ?>" style="width: <?php echo nullable_htmlentities(number_format(min(100, $tr_w), 2, '.', '')); ?>%"
                     title="<?php echo nullable_htmlentities(($tr_kind_labels[$tr_kind] ?? $tr_kind) . ': ' . tr_admin_fmt_bytes($tr_k['bytes'])); ?>"></div>
            <?php } ?>
        </div>
        <?php if ($tr_usage) { ?>
            <ul class="list-inline small text-muted mb-3">
                <?php foreach ($tr_usage as $tr_kind => $tr_k) { ?>
                    <li class="list-inline-item me-3">
                        <span class="badge <?php echo nullable_htmlentities($tr_kind_colors[$tr_kind] ?? 'bg-secondary'); ?> me-1">&nbsp;</span>
                        <?php echo nullable_htmlentities(($tr_kind_labels[$tr_kind] ?? $tr_kind) . ': ' . tr_admin_fmt_bytes($tr_k['bytes']) . ' (' . $tr_k['count'] . ')'); ?>
                    </li>
                <?php } ?>
            </ul>
        <?php } else { ?>
            <p class="small text-muted mb-3">No training media stored yet.</p>
        <?php } ?>
        <?php if ($tr_evidence !== null && intval($tr_evidence['count'] ?? 0) > 0) { ?>
            <p class="small text-muted mb-3">Evidence scans: <?php echo nullable_htmlentities(tr_admin_fmt_bytes(intval($tr_evidence['bytes'])) . ' (' . intval($tr_evidence['count']) . ')'); ?> (not counted toward the budget)</p>
        <?php } ?>

        <div class="mb-3">
            <div class="fw-bold">Projected backup size</div>
            <div>
                about <?php echo nullable_htmlentities(tr_admin_fmt_bytes($tr_projected_bytes)); ?>
                <span class="text-muted small">(uploads <?php echo nullable_htmlentities(tr_admin_fmt_bytes($tr_uploads_bytes)); ?><?php echo $tr_uploads_partial ? '+' : ''; ?><?php
                    if ($tr_db_dump_bytes !== null) { echo ' + database ' . nullable_htmlentities(tr_admin_fmt_bytes($tr_db_dump_bytes)); } else { echo ' + database (no backup yet to measure)'; } ?>)</span>
            </div>
            <?php if ($tr_projected_bytes > 1073741824) { ?>
                <div class="alert alert-warning small mt-2 mb-0">
                    Backups are over 1 GB. A manual <strong>Download Backup</strong> may exceed Cloudflare's 100-second limit; use <strong>Save to Server</strong> instead.
                </div>
            <?php } ?>
        </div>

        <div class="fw-bold">Unreferenced media</div>
        <?php if ($tr_unreferenced === null && !$tr_unreferenced_error) { ?>
            <p class="small text-muted mb-0">Review and purge becomes available with the media pipeline update.</p>
        <?php } elseif ($tr_unreferenced_error) { ?>
            <p class="small text-danger mb-0">Could not list unreferenced media. The details were written to the server error log.</p>
        <?php } else {
            $tr_unref_bytes = 0;
            foreach ($tr_unreferenced as $tr_u) {
                $tr_unref_bytes += intval($tr_u['media_bytes'] ?? $tr_u['bytes'] ?? 0);
            } ?>
            <p class="small mb-2">
                <?php echo intval(count($tr_unreferenced)); ?> file(s), <?php echo nullable_htmlentities(tr_admin_fmt_bytes($tr_unref_bytes)); ?>, older than 7 days and not used by any draft or published version.
                Purging deletes the file only; its record and hash stay, and the purge is written to the training ledger.
            </p>
            <?php if ($tr_unreferenced) { ?>
                <details>
                    <summary class="btn btn-outline-danger btn-sm mb-2">Review &amp; purge&hellip;</summary>
                    <form action="post.php" method="post" autocomplete="off" data-ts-label="Media purge">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">
                        <div class="table-responsive" style="max-height: 320px;">
                            <table class="table table-sm table-striped mb-2">
                                <thead><tr><th class="w-1"></th><th>File</th><th>Kind</th><th class="text-end">Size</th></tr></thead>
                                <tbody>
                                <?php foreach ($tr_unreferenced as $tr_u) {
                                    $tr_uid = intval($tr_u['media_id'] ?? $tr_u['id'] ?? 0);
                                    if ($tr_uid < 1) { continue; } ?>
                                    <tr>
                                        <td><input class="form-check-input" type="checkbox" name="media_ids[]" value="<?php echo $tr_uid; ?>" checked aria-label="Select media <?php echo $tr_uid; ?>"></td>
                                        <td class="text-break"><?php echo nullable_htmlentities((string) ($tr_u['media_original_name'] ?? $tr_u['original_name'] ?? ('#' . $tr_uid))); ?></td>
                                        <td><?php echo nullable_htmlentities((string) ($tr_u['media_kind'] ?? $tr_u['kind'] ?? '')); ?></td>
                                        <td class="text-end"><?php echo nullable_htmlentities(tr_admin_fmt_bytes(intval($tr_u['media_bytes'] ?? $tr_u['bytes'] ?? 0))); ?></td>
                                    </tr>
                                <?php } ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="row align-items-end">
                            <div class="col-md-8 mb-2">
                                <label class="form-label" for="trPurgeReason">Reason (required, recorded in the ledger)</label>
                                <input type="text" class="form-control" id="trPurgeReason" name="purge_reason" minlength="5" maxlength="500" required placeholder="e.g. Old drafts replaced by new uploads">
                            </div>
                            <div class="col-md-4 mb-2">
                                <button type="submit" name="training_media_purge" class="btn btn-danger w-100"><i class="fas fa-fw fa-trash-alt me-1"></i>Purge selected</button>
                            </div>
                        </div>
                    </form>
                </details>
            <?php } ?>
        <?php } ?>
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
    <?php if ($tc_ready && intval($tc_row['config_module_enable_training'] ?? 0) === 1) { ?>
        <a href="/agent/training_dashboard.php" class="btn btn-outline-primary btn-sm"><i class="fas fa-fw fa-chart-pie me-1"></i>Open Training overview</a>
    <?php } ?>
</div>

<?php if (!$tc_ready) { ?>
<div class="card mb-3">
    <div class="card-header py-3">
        <h3 class="card-title"><i class="fas fa-fw fa-clipboard-check me-2"></i>Training compliance</h3>
    </div>
    <div class="card-body">
        <?php if ($tc_error) { ?>
            <div class="alert alert-danger mb-0">Training compliance settings could not be loaded. The details were written to the server error log.</div>
        <?php } else { ?>
            <p class="mb-2">The Training compliance tables are not installed yet.</p>
            <p class="text-muted mb-3">Run the database update (Admin &rsaquo; Update &rsaquo; <strong>Update Database</strong>) to add them. Nothing else in the app changes until the Training module is switched on.</p>
            <a href="/admin/update.php" class="btn btn-primary"><i class="fas fa-fw fa-database me-2"></i>Open Update</a>
        <?php } ?>
    </div>
</div>
<?php } else { ?>

<?php if (intval($tc_row['config_module_enable_training'] ?? 0) !== 1) { ?>
    <div class="alert alert-info">The Training module is off (Admin &rsaquo; Modules). These settings can be prepared now; assignments and snapshots start once it is on.</div>
<?php } ?>

<!-- Compliance defaults ------------------------------------------------------------------------- -->
<form action="post.php" method="post" autocomplete="off" data-ts-label="Compliance defaults">
    <input type="hidden" name="csrf_token" value="<?php echo nullable_htmlentities($tc_csrf); ?>">
    <input type="hidden" name="tc_section" value="defaults">
    <div class="card mb-3">
        <div class="card-header py-3">
            <h3 class="card-title"><i class="fas fa-fw fa-sliders-h me-2"></i>Compliance defaults</h3>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-sm-6 col-lg-4 mb-3">
                    <label class="form-label" for="tcDueSoon">"Due soon" window (days)</label>
                    <input type="number" class="form-control" id="tcDueSoon" name="config_training_due_soon_days" min="0" max="365" step="1" required
                           value="<?php echo intval($tc_s->dueSoonDays); ?>">
                    <div class="form-text">Assignments due within this many days show as due soon; certificates expiring within it show as expiring.</div>
                </div>
                <div class="col-sm-6 col-lg-4 mb-3">
                    <label class="form-label" for="tcReissue">Redo after a voided record (days)</label>
                    <input type="number" class="form-control" id="tcReissue" name="config_training_reissue_days" min="1" max="365" step="1" required
                           value="<?php echo intval($tc_s->reissueDays); ?>">
                    <div class="form-text">When a record is voided and nothing else covers the course, the person is due again this many days later.</div>
                </div>
                <div class="col-sm-6 col-lg-4 mb-3">
                    <label class="form-label" for="tcReopen">Reopen window (days)</label>
                    <input type="number" class="form-control" id="tcReopen" name="config_training_reopen_window_days" min="0" max="365" step="1" required
                           value="<?php echo intval($tc_s->reopenWindowDays); ?>">
                    <div class="form-text">Someone who moves back into a rule within this many days gets their old assignment back, with its original due date.</div>
                </div>
                <div class="col-sm-6 col-lg-4 mb-3">
                    <label class="form-label" for="tcTarget">Compliance target (%)</label>
                    <input type="number" class="form-control" id="tcTarget" name="config_training_compliance_target_pct" min="1" max="100" step="1" required
                           value="<?php echo intval($tc_s->targetPct); ?>">
                    <div class="form-text">The target line on the dashboard trend and the heatmap's top band.</div>
                </div>
                <div class="col-sm-6 col-lg-4 mb-3">
                    <label class="form-label" for="tcEvidenceMb">Evidence scan upload (MB)</label>
                    <input type="number" class="form-control" id="tcEvidenceMb" name="config_training_evidence_max_mb" min="1" max="95" step="1" required
                           value="<?php echo intval($tc_s->evidenceMaxBytes / 1048576); ?>">
                    <div class="form-text">Per file, at most 95 MB. Scans do not count toward the media budget.</div>
                </div>
                <div class="col-sm-6 col-lg-4 mb-3">
                    <label class="form-label" for="tcHireFill">Fill hire dates from Odoo for employees created on or after</label>
                    <input type="date" class="form-control" id="tcHireFill" name="config_training_hire_fill_since"
                           value="<?php echo nullable_htmlentities($tc_s->hireFillSince ?? ''); ?>">
                    <div class="form-text">Leave empty so long-serving staff are not marked as new hires. Only empty hire dates are filled, on the next directory sync.</div>
                </div>
            </div>
            <button type="submit" name="edit_training_compliance_settings" class="btn btn-primary"><i class="fas fa-check me-2"></i>Save compliance defaults</button>
        </div>
    </div>
</form>

<!-- Maintenance -------------------------------------------------------------------------------- -->
<div class="card mb-3" id="maintenance">
    <div class="card-header py-3">
        <h3 class="card-title"><i class="fas fa-fw fa-tools me-2"></i>Maintenance</h3>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-6 mb-3 mb-md-0">
                <div class="fw-bold">Assignments</div>
                <p class="small text-muted mb-2">
                    <?php echo intval($tc_open['open']); ?> open, <?php echo intval($tc_open['overdue']); ?> overdue.
                    Last full recalculation:
                    <?php $tc_rec = tc_local_time($tc_s->reconciledAtUtc); echo $tc_rec !== null ? nullable_htmlentities($tc_rec) : 'never'; ?>.
                    It also runs nightly and after every rule, roster or record change.
                </p>
                <form action="post.php" method="post" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?php echo nullable_htmlentities($tc_csrf); ?>">
                    <button type="submit" name="training_reconcile_now" class="btn btn-outline-primary"><i class="fas fa-fw fa-calculator me-1"></i>Recalculate assignments now</button>
                </form>
            </div>
            <div class="col-md-6">
                <div class="fw-bold">Compliance snapshot</div>
                <p class="small text-muted mb-2">
                    The dashboard trend reads one snapshot per day. Last snapshot:
                    <?php echo $tc_s->snapshotLastOn !== null ? nullable_htmlentities($tc_s->snapshotLastOn) : 'never'; ?>.
                    Capturing again on the same day replaces that day's numbers.
                </p>
                <form action="post.php" method="post" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?php echo nullable_htmlentities($tc_csrf); ?>">
                    <button type="submit" name="training_snapshot_now" class="btn btn-outline-primary"><i class="fas fa-fw fa-camera me-1"></i>Capture today's snapshot</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php } ?>
</section>

<!-- =========================================================================================== -->
<!-- Employee links (Odoo)                                                                       -->
<!-- =========================================================================================== -->
<section id="odoo" class="ts-section" aria-labelledby="odoo-title">
<div class="ts-section-head">
    <div>
        <h2 id="odoo-title"><i class="fas fa-fw fa-address-card me-2" aria-hidden="true"></i>Employee links (Odoo)</h2>
        <p>Which Odoo employee each person is, the checks that keep those links right, and the nightly directory sync.</p>
    </div>
</div>

<?php if (!$tc_ready) { ?>
    <!-- Shares the Compliance & assignments readiness guard (the former compliance page's): its card says why. -->
    <p class="text-muted">Available once the Training compliance tables are installed (see Compliance &amp; assignments above).</p>
<?php } else { ?>

<!-- Odoo employee links ------------------------------------------------------------------------- -->
<div class="card mb-3">
    <div class="card-header py-3">
        <h3 class="card-title"><i class="fas fa-fw fa-link me-2"></i>Odoo employee links</h3>
        <?php if ($tc_integration !== null) { ?>
        <div class="card-actions">
            <form action="post.php" method="post" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?php echo nullable_htmlentities($tc_csrf); ?>">
                <button type="submit" name="training_odoo_link_check" class="btn btn-primary btn-sm"><i class="fas fa-fw fa-sync me-1"></i>Check now</button>
            </form>
        </div>
        <?php } ?>
    </div>
    <div class="card-body">
        <?php if ($tc_integration === null) { ?>
            <p class="mb-0 text-muted">No Odoo integration is configured. Set one up under <a href="/admin/settings_integrations.php?tab=directorysync">Integrations &rsaquo; Directory Sync</a>.</p>
        <?php } else { ?>
            <dl class="row mb-3">
                <dt class="col-sm-3">Connected to</dt>
                <dd class="col-sm-9">
                    <span class="text-break"><?php echo nullable_htmlentities($tc_host); ?></span>
                    <span class="text-muted">/</span>
                    <span class="font-monospace text-break"><?php echo nullable_htmlentities((string) $tc_integration['database_name']); ?></span>
                    <?php if ($tc_staging) { ?><span class="badge text-bg-warning ms-2">Points at STAGING</span><?php } ?>
                    <?php if (empty($tc_integration['enabled'])) { ?><span class="badge text-bg-secondary ms-2">Integration disabled</span><?php } ?>
                </dd>
                <dt class="col-sm-3">Accepted target</dt>
                <dd class="col-sm-9">
                    <?php if ($tc_target_state === 'accepted') { ?>
                        <span class="badge text-bg-success">Accepted</span>
                        <span class="text-muted small ms-1">The links were checked against this Odoo database.</span>
                    <?php } elseif ($tc_target_state === 'unset') { ?>
                        <span class="badge text-bg-secondary">Not set yet</span>
                        <span class="text-muted small ms-1">It is set by the first clean directory sync or link check.</span>
                    <?php } else { ?>
                        <span class="badge text-bg-danger">Changed</span>
                    <?php } ?>
                </dd>
                <dt class="col-sm-3">Last check</dt>
                <dd class="col-sm-9">
                    <?php $tc_checked = tc_local_time($tc_status['checked_at_utc'] ?? null); ?>
                    <?php echo $tc_checked !== null ? nullable_htmlentities($tc_checked) : '<span class="text-muted">Never</span>'; ?>
                </dd>
                <dt class="col-sm-3">Links</dt>
                <dd class="col-sm-9 d-flex flex-wrap gap-1">
                    <?php foreach ($tc_state_chips as $tc_state => [$tc_cls, $tc_label]) {
                        if (intval($tc_counts[$tc_state] ?? 0) === 0 && $tc_state !== 'ok') { continue; } ?>
                        <span class="badge <?php echo $tc_cls; ?>"><?php echo intval($tc_counts[$tc_state] ?? 0) . ' ' . nullable_htmlentities($tc_label); ?></span>
                    <?php } ?>
                </dd>
            </dl>

            <?php if ($tc_target_state === 'pending') { ?>
                <div class="alert alert-danger">
                    <div class="fw-bold mb-1">The Odoo connection changed. Directory sync is blocked until links are checked.</div>
                    <div class="small">Run <strong>Check now</strong>. When every link checks out (none missing, re-pointed or with a changed name), the new connection is accepted automatically.
                        Otherwise resolve the flagged links below, or accept the new connection if you are sure the employee ids still mean the same people.</div>
                </div>
                <details class="mb-3">
                    <summary class="btn btn-outline-danger btn-sm">Accept new Odoo target&hellip;</summary>
                    <form action="post.php" method="post" autocomplete="off" class="mt-2" data-ts-label="Accept new Odoo target">
                        <input type="hidden" name="csrf_token" value="<?php echo nullable_htmlentities($tc_csrf); ?>">
                        <p class="small text-muted mb-2">Only when the new Odoo database holds the same employees under the same ids (for example a refreshed copy).
                            The next directory sync then updates names and departments from it, and Odoo PIN sign-in is allowed again for links that check out.</p>
                        <div class="row align-items-end">
                            <div class="col-md-3 mb-2">
                                <label class="form-label" for="tcAcceptWord">Type ACCEPT</label>
                                <input type="text" class="form-control" id="tcAcceptWord" name="accept_word" required pattern="ACCEPT" autocomplete="off" spellcheck="false">
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="form-label" for="tcAcceptReason">Reason (recorded in the audit log)</label>
                                <input type="text" class="form-control" id="tcAcceptReason" name="accept_reason" required minlength="10" maxlength="400">
                            </div>
                            <div class="col-md-3 mb-2">
                                <button type="submit" name="training_odoo_accept_target" class="btn btn-danger w-100">Accept new target</button>
                            </div>
                        </div>
                    </form>
                </details>
            <?php } ?>

            <?php if ($tc_status_error) { ?>
                <div class="alert alert-danger mb-0">The link list could not be loaded. The details were written to the server error log.</div>
            <?php } elseif ($tc_status !== null) { ?>
                <?php if ($tc_flagged) { ?>
                    <div class="fw-bold mb-2">Links that need a decision (<?php echo count($tc_flagged); ?>)</div>
                    <div class="table-responsive mb-3">
                        <table class="table table-sm table-vcenter mb-0">
                            <thead>
                                <tr><th>Person</th><th>Odoo employee</th><th>State</th><th>Detail</th><th>Name in Odoo</th><th>Suggestion</th><th class="text-end">Actions</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($tc_flagged as $tc_r) {
                                [$tc_cls, $tc_label] = $tc_state_chips[$tc_r['state']] ?? ['text-bg-secondary', $tc_r['state']];
                                $tc_cid = intval($tc_r['contact_id']);
                                $tc_sugg = $tc_r['suggestion']; ?>
                                <tr>
                                    <td class="text-break">
                                        <a href="/agent/contact_details.php?contact_id=<?php echo $tc_cid; ?>"><?php echo nullable_htmlentities($tc_r['contact_name']); ?></a>
                                        <?php if (!empty($tc_r['confirmed_name']) && $tc_r['confirmed_name'] !== $tc_r['contact_name']) { ?>
                                            <div class="small text-muted">Confirmed as <?php echo nullable_htmlentities($tc_r['confirmed_name']); ?></div>
                                        <?php } ?>
                                    </td>
                                    <td class="font-monospace">#<?php echo intval($tc_r['odoo_employee_id']); ?></td>
                                    <td><span class="badge <?php echo $tc_cls; ?>"><?php echo nullable_htmlentities($tc_label); ?></span></td>
                                    <td class="small text-break"><?php echo nullable_htmlentities((string) ($tc_r['detail'] ?? '')); ?></td>
                                    <td class="small text-break"><?php echo nullable_htmlentities((string) ($tc_r['seen_name'] ?? '')); ?></td>
                                    <td class="small text-break">
                                        <?php if ($tc_sugg !== null) { ?>
                                            #<?php echo intval($tc_sugg['id']); ?> <?php echo nullable_htmlentities((string) ($tc_sugg['name'] ?? '')); ?>
                                        <?php } else { ?>
                                            <span class="text-muted">&mdash;</span>
                                        <?php } ?>
                                    </td>
                                    <td class="text-end">
                                        <div class="d-flex flex-wrap justify-content-end gap-1">
                                            <?php if (in_array($tc_r['state'], ['mismatch', 'repointed'], true)) { ?>
                                                <form action="post.php" method="post" class="d-inline">
                                                    <input type="hidden" name="csrf_token" value="<?php echo nullable_htmlentities($tc_csrf); ?>">
                                                    <input type="hidden" name="contact_id" value="<?php echo $tc_cid; ?>">
                                                    <button type="submit" name="training_odoo_link_confirm" class="btn btn-outline-success btn-sm"
                                                            title="Keep this link: Odoo employee #<?php echo intval($tc_r['odoo_employee_id']); ?> is this person">Confirm</button>
                                                </form>
                                            <?php } ?>
                                            <?php if ($tc_sugg !== null) { ?>
                                                <form action="post.php" method="post" class="d-inline">
                                                    <input type="hidden" name="csrf_token" value="<?php echo nullable_htmlentities($tc_csrf); ?>">
                                                    <input type="hidden" name="contact_id" value="<?php echo $tc_cid; ?>">
                                                    <input type="hidden" name="odoo_employee_id" value="<?php echo intval($tc_sugg['id']); ?>">
                                                    <button type="submit" name="training_odoo_link_relink" class="btn btn-outline-primary btn-sm"
                                                            title="Link this person to Odoo employee #<?php echo intval($tc_sugg['id']); ?>">Relink</button>
                                                </form>
                                            <?php } ?>
                                            <?php if (in_array($tc_r['state'], ['missing', 'mismatch'], true)) { ?>
                                                <details class="text-start">
                                                    <summary class="btn btn-outline-danger btn-sm">Unlink&hellip;</summary>
                                                    <form action="post.php" method="post" class="mt-2 small" style="max-width: 22rem;">
                                                        <input type="hidden" name="csrf_token" value="<?php echo nullable_htmlentities($tc_csrf); ?>">
                                                        <input type="hidden" name="contact_id" value="<?php echo $tc_cid; ?>">
                                                        <?php if ($tc_r['state'] === 'mismatch') { ?>
                                                            <p class="mb-2">The next directory sync creates a new contact for Odoo employee #<?php echo intval($tc_r['odoo_employee_id']); ?>
                                                                (<?php echo nullable_htmlentities((string) ($tc_r['seen_name'] ?? '')); ?>). This person keeps their training records.</p>
                                                        <?php } else { ?>
                                                            <p class="mb-2">The link to Odoo employee #<?php echo intval($tc_r['odoo_employee_id']); ?> is removed. This person keeps their training records.</p>
                                                        <?php } ?>
                                                        <button type="submit" name="training_odoo_link_unlink" class="btn btn-danger btn-sm">Unlink</button>
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
                <?php } elseif (($tc_counts['unchecked'] ?? 0) === 0 && $tc_other) { ?>
                    <p class="text-success mb-3"><i class="fas fa-check-circle me-1"></i>No link needs a decision.</p>
                <?php } ?>

                <?php if (($tc_counts['unchecked'] ?? 0) > 0) { ?>
                    <p class="small text-muted mb-2"><?php echo intval($tc_counts['unchecked']); ?> link(s) have not been checked against Odoo yet. Run <strong>Check now</strong>; Odoo PIN sign-in on the kiosk needs a checked link.</p>
                <?php } ?>

                <?php if ($tc_other) { ?>
                    <details>
                        <summary class="small">Show the other <?php echo count($tc_other); ?> linked people</summary>
                        <div class="table-responsive mt-2" style="max-height: 420px;">
                            <table class="table table-sm table-striped mb-0">
                                <thead><tr><th>Person</th><th>Odoo employee</th><th>State</th><th>Name in Odoo</th><th>Checked</th></tr></thead>
                                <tbody>
                                <?php foreach ($tc_other as $tc_r) {
                                    [$tc_cls, $tc_label] = $tc_state_chips[$tc_r['state']] ?? ['text-bg-secondary', $tc_r['state']]; ?>
                                    <tr>
                                        <td class="text-break"><?php echo nullable_htmlentities($tc_r['contact_name']); ?></td>
                                        <td class="font-monospace">#<?php echo intval($tc_r['odoo_employee_id']); ?></td>
                                        <td><span class="badge <?php echo $tc_cls; ?>"><?php echo nullable_htmlentities($tc_label); ?></span></td>
                                        <td class="small text-break"><?php echo nullable_htmlentities((string) ($tc_r['seen_name'] ?? $tc_r['confirmed_name'] ?? '')); ?></td>
                                        <td class="small text-nowrap"><?php echo nullable_htmlentities((string) (tc_local_time($tc_r['checked_at_utc'] ?? null) ?? '')); ?></td>
                                    </tr>
                                <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </details>
                <?php } elseif (!$tc_flagged) { ?>
                    <p class="text-muted mb-0">No contacts are linked to Odoo employees yet. The directory sync creates the links.</p>
                <?php } ?>
            <?php } ?>
        <?php } ?>
    </div>
</div>

<!-- Nightly Odoo directory sync ------------------------------------------------------------------ -->
<form action="post.php" method="post" autocomplete="off" id="odoo-sync" data-ts-label="Nightly Odoo directory sync">
    <input type="hidden" name="csrf_token" value="<?php echo nullable_htmlentities($tc_csrf); ?>">
    <input type="hidden" name="tc_section" value="odoo_sync">
    <div class="card mb-3">
        <div class="card-header py-3">
            <h3 class="card-title"><i class="fas fa-fw fa-moon me-2"></i>Nightly Odoo directory sync</h3>
        </div>
        <div class="card-body">
            <div class="form-check form-switch mb-2">
                <input type="checkbox" class="form-check-input" name="config_training_odoo_sync_enabled" value="1" id="tcOdooSync" <?php if ($tc_s->odooSyncEnabled) { echo 'checked'; } ?>>
                <label class="form-check-label" for="tcOdooSync">Run the Odoo directory sync every night at 4:30</label>
            </div>
            <p class="small text-muted">
                The same sync as <a href="/admin/settings_integrations.php?tab=directorysync">Integrations &rsaquo; Directory Sync</a> &rsaquo; Sync now, followed by the
                Training link check and attribute update. It is refused while the Odoo connection points at a database that has not been accepted above.
                Admins are notified when it fails.
            </p>
            <dl class="row small mb-3">
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
            <button type="submit" name="edit_training_compliance_settings" class="btn btn-primary"><i class="fas fa-check me-2"></i>Save nightly sync</button>
        </div>
    </div>
</form>

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

<form action="post.php" method="post" autocomplete="off" id="tkSettingsForm" data-ts-label="Kiosk &amp; sign-in">
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
</section>

<?php
// Certificates + Reminders & automation (LMS Phase 5, DB 2.6.96): the shared sections and their card partials.
define('TRAINING_AUTOMATION_PAGE', true);
$ta_admin_page = true;
$ta_is_admin = true;
$ta_module_on = $ts_module_on;
require __DIR__ . '/includes/training_automation/sections.php';
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
        <h3 class="card-title"><i class="fas fa-fw fa-link me-2"></i>Records ledger</h3>
    </div>
    <div class="card-body">
        <p class="text-muted small">
            Every stored file, published version and archive action is written to a hash-chained ledger. Verification re-computes
            every hash; it runs nightly (deep, with file checks, on Sundays) and on demand here. The head is also written into every backup's <code>version.txt</code>.
        </p>
        <dl class="row mb-3">
            <dt class="col-sm-3">Head</dt>
            <dd class="col-sm-9">
                <?php if ($tr_head) { ?>
                    <span class="font-monospace">#<?php echo intval($tr_head['seq']); ?> / <?php echo nullable_htmlentities(substr($tr_head['hash'], 0, 16)); ?></span>
                    <?php if ($tr_head_updated_iso) { ?><span class="text-muted small ms-2">updated <?php echo nullable_htmlentities(date('Y-m-d H:i', strtotime($tr_head_updated_iso))); ?></span><?php } ?>
                <?php } elseif ($tr_head_missing) { ?>
                    <span class="badge text-bg-danger">Ledger head missing</span>
                    <div class="small text-danger mt-1">The ledger head row is gone, which means the records were tampered with or a restore was incomplete. New media and publishes will fail until it is back. Run <strong>Verify now</strong> and restore from a backup whose <code>version.txt</code> head matches.</div>
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
                    <span class="badge <?php echo $tr_result_badge; ?> font-monospace"><?php echo nullable_htmlentities($tr_result_line); ?></span>
                <?php } else { ?>
                    <span class="text-muted">&mdash;</span>
                <?php } ?>
            </dd>
        </dl>
        <form action="post.php" method="post" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">
            <button type="submit" name="training_ledger_verify" class="btn btn-outline-primary"><i class="fas fa-fw fa-check-double me-1"></i>Verify now</button>
        </form>
        <span class="text-muted small ms-2">Shallow check (no file re-hash), stops after 60 seconds.</span>
    </div>
</div>
<?php } ?>
</section>

</div><!-- /.ts-page -->

<script nonce="<?php echo nullable_htmlentities($csp_nonce ?? ''); ?>">
// Section nav: marks the section in view (aria-current) and keeps its pill visible when the nav
// scrolls sideways on a phone. The links themselves are plain #anchors. Nothing is stored.
(function () {
    var nav = document.getElementById('tsNav');
    if (!nav) { return; }
    var links = Array.prototype.slice.call(nav.querySelectorAll('a[href^="#"]'));
    var sections = links.map(function (a) { return document.getElementById(a.getAttribute('href').slice(1)); });
    var current = null;
    function mark() {
        // The line a section must cross to count as "in view": a little below the sticky nav, past
        // the scroll-margin that anchor jumps leave above a section heading.
        var offset = nav.getBoundingClientRect().bottom + Math.min(140, window.innerHeight * 0.25);
        var idx = 0;
        for (var i = 0; i < sections.length; i++) {
            if (sections[i] && sections[i].getBoundingClientRect().top <= offset) { idx = i; }
        }
        // At the very bottom the last section may be too short to reach the line: count it as in view.
        if (window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 2) { idx = sections.length - 1; }
        if (current === idx) { return; }
        current = idx;
        links.forEach(function (a, i) {
            if (i === idx) { a.setAttribute('aria-current', 'true'); } else { a.removeAttribute('aria-current'); }
        });
        var a = links[idx];
        if (nav.scrollWidth > nav.clientWidth) {
            var left = a.offsetLeft - nav.offsetLeft;
            if (left < nav.scrollLeft || left + a.offsetWidth > nav.scrollLeft + nav.clientWidth) {
                nav.scrollLeft = Math.max(0, left - 16);
            }
        }
    }
    var queued = false;
    function onScroll() {
        if (queued) { return; }
        queued = true;
        window.requestAnimationFrame(function () { queued = false; mark(); });
    }
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', onScroll);
    window.addEventListener('hashchange', onScroll);
    mark();
})();
</script>

<script nonce="<?php echo nullable_htmlentities($csp_nonce ?? ''); ?>">
// Unsaved changes. Every form on this page saves on its own, so a Save in one section would drop
// edits made in another without a word. A form counts as changed while its fields differ from how
// the page loaded them (typing a value back undoes it). Submitting one form while another has
// changes asks first; leaving the page with changes asks too; the section nav marks sections with
// changes. Everything is kept in memory; nothing is stored in the browser.
(function () {
    var page = document.querySelector('.ts-page');
    if (!page) { return; }
    var nav = document.getElementById('tsNav');

    // The fields a person can change: hidden inputs (CSRF, section markers) and buttons never count.
    function state(form) {
        var out = [];
        Array.prototype.forEach.call(form.elements, function (el) {
            var t = (el.type || '').toLowerCase();
            if (!el.name || el.disabled || t === 'hidden' || t === 'submit' || t === 'button' || t === 'reset' || t === 'file') { return; }
            out.push(el.name + '=' + ((t === 'checkbox' || t === 'radio') ? (el.checked ? 'on:' + el.value : 'off') : el.value));
        });
        return out.length ? out.join('\n') : null;
    }
    function label(form) {
        var named = form.getAttribute('data-ts-label');
        if (named) { return named; }
        var h = form.closest('section') && form.closest('section').querySelector('h2');
        return h ? h.textContent.trim() : 'another part of this page';
    }

    var initial = new Map();
    Array.prototype.forEach.call(page.querySelectorAll('form'), function (f) {
        var s = state(f);
        if (s !== null) { initial.set(f, s); }
    });
    var dirty = new Set();

    function paint() {
        if (!nav) { return; }
        Array.prototype.forEach.call(nav.querySelectorAll('a[href^="#"]'), function (a) {
            var section = document.getElementById(a.getAttribute('href').slice(1));
            var has = false;
            dirty.forEach(function (f) { if (section && section.contains(f)) { has = true; } });
            var note = a.querySelector('.ts-dirty-note');
            a.classList.toggle('ts-dirty', has);
            if (has && !note) {
                note = document.createElement('span');
                note.className = 'visually-hidden ts-dirty-note';
                note.textContent = ' (unsaved changes)';
                a.appendChild(note);
            } else if (!has && note) {
                note.remove();
            }
        });
    }
    function refresh(e) {
        var form = e.target && e.target.form;   // .form follows a form="" attribute too
        if (!form || !initial.has(form)) { return; }
        if (state(form) === initial.get(form)) { dirty.delete(form); } else { dirty.add(form); }
        paint();
    }
    document.addEventListener('input', refresh);
    document.addEventListener('change', refresh);

    var leaving = false;
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (e.defaultPrevented || !page.contains(form)) { return; }   // other forms (e.g. search) leave the page: beforeunload asks
        var others = [];
        dirty.forEach(function (f) {
            var l = label(f);
            if (f !== form && others.indexOf(l) === -1) { others.push(l); }
        });
        if (others.length) {
            var names = others.length === 1 ? others[0] : others.slice(0, -1).join(', ') + ' and ' + others[others.length - 1];
            if (!window.confirm('Unsaved changes in ' + names + ' will be lost.\n\nPress OK to continue anyway, or Cancel to go back and save them first.')) {
                e.preventDefault();
                return;
            }
        }
        leaving = true;   // this submit may drop the changes it asked about: no second prompt on unload
        window.setTimeout(function () { if (e.defaultPrevented) { leaving = false; } }, 0);
    });
    window.addEventListener('beforeunload', function (e) {
        if (leaving || dirty.size === 0) { return undefined; }
        e.preventDefault();
        e.returnValue = '';
        return '';
    });
    window.addEventListener('pageshow', function (e) { if (e.persisted) { leaving = false; } });
})();
</script>

<?php
require_once "../includes/footer.php";
