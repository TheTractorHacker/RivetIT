<?php
require_once "includes/inc_all_admin.php";

/*
 * Admin > Training compliance - Phase 2 spec §5.3 (M15 cards 1-3, S6 card 4 and the link actions).
 *
 *   1. Compliance defaults: due-soon / reissue / reopen-window days, target %, evidence cap, and the
 *      opt-in "fill hire dates from Odoo for employees created on or after" date (empty = off, R2).
 *   2. Odoo employee links: the configured Odoo target (a STAGING chip when the host says so), the
 *      accepted-target state (a changed target blocks the directory sync until the links are
 *      checked, plan A22), Check now, Accept new target (typed ACCEPT + reason), and per-link
 *      Relink / Unlink / Confirm for flagged links.
 *   3. Maintenance: Recalculate assignments now, Capture today's snapshot.
 *   4. Nightly Odoo directory sync (cron/odoo_sync_cron.php) switch, default OFF, with its last result.
 *
 * Renders before the 2.6.92 migration: everything that touches a Phase 2 table or column is behind
 * $config_training_schema_ready plus a table-exists check and a try/catch (the settings_training.php
 * pattern). GET never calls Odoo: the link table is OdooLinkChecker::status(), read from the DB.
 *
 * Forms post to admin/post.php, which dispatches to admin/post/settings_training_compliance.php by
 * this page's basename.
 */

use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\RecordsSettings;
use ITFlow\Training\Directory\OdooLinkChecker;
use ITFlow\Training\Directory\OdooTarget;

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
?>

<!-- Plain .card throughout, not .card-dark - see the note in admin/settings_module.php. -->

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

<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <div>
        <h2 class="mb-0">Training compliance</h2>
        <div class="text-muted small">Assignment defaults, Odoo employee links and maintenance for the Training module.</div>
    </div>
    <div class="d-flex gap-2">
        <a href="/admin/settings_training.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-fw fa-hard-hat me-1"></i>Training (LMS) settings</a>
        <?php if (intval($tc_row['config_module_enable_training'] ?? 0) === 1) { ?>
            <a href="/agent/training_dashboard.php" class="btn btn-outline-primary btn-sm"><i class="fas fa-fw fa-chart-pie me-1"></i>Open Training overview</a>
        <?php } ?>
    </div>
</div>

<?php if (intval($tc_row['config_module_enable_training'] ?? 0) !== 1) { ?>
    <div class="alert alert-info">The Training module is off (Admin &rsaquo; Modules). These settings can be prepared now; assignments and snapshots start once it is on.</div>
<?php } ?>

<!-- 1. Compliance defaults ---------------------------------------------------------------------- -->
<form action="post.php" method="post" autocomplete="off">
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
            <button type="submit" name="edit_training_compliance_settings" class="btn btn-primary"><i class="fas fa-check me-2"></i>Save defaults</button>
        </div>
    </div>
</form>

<!-- 2. Odoo employee links ---------------------------------------------------------------------- -->
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
                    <form action="post.php" method="post" autocomplete="off" class="mt-2">
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

<!-- 3. Maintenance ------------------------------------------------------------------------------ -->
<div class="card mb-3">
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

<!-- 4. Nightly Odoo directory sync --------------------------------------------------------------- -->
<form action="post.php" method="post" autocomplete="off">
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
            <button type="submit" name="edit_training_compliance_settings" class="btn btn-primary"><i class="fas fa-check me-2"></i>Save</button>
        </div>
    </div>
</form>

<?php } ?>

<?php
require_once "../includes/footer.php";
