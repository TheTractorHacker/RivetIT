<?php
/*
 * Odoo employee links and the nightly directory sync, rendered inside Admin > Integrations > Odoo.
 * Moved here from the Training settings page: they are about the Odoo connection, not about training.
 * The forms post to admin/post.php; because the Referer is settings_integrations.php, that page's
 * handler (admin/post/settings_integrations.php) requires settings_training_compliance.php, which runs
 * the same actions as before (training_odoo_link_check, _relink, _unlink, _confirm, _dismiss, _bulk,
 * training_odoo_accept_target and the nightly-sync switch) and returns to the Odoo tab.
 *
 * Read-only apart from those forms; a page view never calls Odoo. Needs the Training database update
 * (the link checks keep their state in Training tables) and says so when it is missing.
 */

use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\RecordsSettings;
use ITFlow\Training\Directory\OdooLinkChecker;
use ITFlow\Training\Directory\OdooTarget;

$tc_ready = false;
$tc_s = null;                  // RecordsSettings
$tc_integration = null;        // latest odoo_integrations row (no key material is rendered)
$tc_status = null;             // OdooLinkChecker::status()
$tc_status_error = false;
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
            $tc_integration = OdooLinkChecker::latestIntegration($mysqli);
            if ($tc_integration !== null) {
                try {
                    $tc_status = (new OdooLinkChecker($mysqli, $tc_integration))->status();
                } catch (\Throwable $e) {
                    error_log('Odoo employee links: link status failed: ' . get_class($e) . ': ' . $e->getMessage());
                    $tc_status_error = true;
                }
            }
        }
    } catch (\Throwable $e) {
        error_log('Odoo employee links: ' . get_class($e) . ': ' . $e->getMessage());
        $tc_ready = false;
    }
}

if (!function_exists('tc_local_time')) {
    /** A UTC DATETIME(3) as local "Y-m-d H:i", or null. */
    function tc_local_time(?string $utc): ?string {
        if ($utc === null || $utc === '') {
            return null;
        }
        $iso = Clock::toIso($utc, true);
        return $iso ? date('Y-m-d H:i', strtotime($iso)) : null;
    }
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
$tc_dismissed = intval($tc_status['dismissed'] ?? 0);
$tc_csrf = $_SESSION['csrf_token'] ?? '';
?>
<!-- =========================================================================================== -->
<!-- Employee links (Odoo)                                                                       -->
<!-- =========================================================================================== -->
<div id="odoo-employee-links" class="mt-4">
<div class="mb-3 px-1">
    <h4 class="mb-1"><i class="fas fa-fw fa-address-card me-2" aria-hidden="true"></i>Employee links</h4>
    <p class="text-muted mb-0">Which Odoo employee each person is, the checks that keep those links right, and the nightly directory sync.</p>
</div>

<?php if (!$tc_ready) { ?>
    <p class="text-muted">Available once the Training database update has been applied; the link checks use its tables.</p>
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
            <p class="mb-0 text-muted">No Odoo integration is configured. Set one up in the Odoo connection card above.</p>
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
                    <div class="fw-bold mb-2">Links that need a decision (<?php echo count($tc_flagged); ?>)<?php if ($tc_dismissed > 0) { ?>
                        <span class="fw-normal small text-muted">&mdash; <?php echo $tc_dismissed; ?> dismissed as having no Odoo record</span>
                    <?php } ?></div>
                    <form action="post.php" method="post" id="tcBulkForm" data-ts-label="Odoo link bulk actions">
                        <input type="hidden" name="csrf_token" value="<?php echo nullable_htmlentities($tc_csrf); ?>">
                    </form>
                    <div id="tcBulkBar" class="d-flex flex-wrap align-items-center gap-2 mb-2">
                        <span class="small text-muted"><span id="tcBulkCount">0</span> selected</span>
                        <button type="submit" form="tcBulkForm" name="training_odoo_link_bulk" value="confirm" class="btn btn-outline-success btn-sm" data-bulk="confirm" disabled
                                title="Keep the current Odoo link for each selected person whose name changed or whose link was re-pointed">Confirm</button>
                        <button type="submit" form="tcBulkForm" name="training_odoo_link_bulk" value="relink" class="btn btn-outline-primary btn-sm" data-bulk="relink" disabled
                                title="Link each selected person to their suggested Odoo employee">Relink to suggestion</button>
                        <button type="submit" form="tcBulkForm" name="training_odoo_link_bulk" value="unlink" class="btn btn-outline-danger btn-sm" data-bulk="unlink" disabled
                                title="Remove the Odoo link of each selected person (they keep their training records)">Unlink</button>
                        <button type="submit" form="tcBulkForm" name="training_odoo_link_bulk" value="dismiss" class="btn btn-outline-secondary btn-sm" data-bulk="dismiss" disabled
                                title="Confirm there is no Odoo record for each selected person with no link">No Odoo record</button>
                        <span class="small text-muted">People an action does not apply to are skipped.</span>
                    </div>
                    <div class="table-responsive mb-3">
                        <table class="table table-sm table-vcenter mb-0" id="tcLinkTable">
                            <thead>
                                <tr><th style="width:2rem"><input type="checkbox" class="form-check-input" id="tcBulkAll" aria-label="Select all people needing a decision"></th><th>Person</th><th>Odoo employee</th><th>State</th><th>Detail</th><th>Name in Odoo</th><th>Suggestion</th><th class="text-end">Actions</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($tc_flagged as $tc_r) {
                                [$tc_cls, $tc_label] = $tc_state_chips[$tc_r['state']] ?? ['text-bg-secondary', $tc_r['state']];
                                $tc_cid = intval($tc_r['contact_id']);
                                $tc_sugg = $tc_r['suggestion'];
                                $tc_can = [];
                                if (in_array($tc_r['state'], ['mismatch', 'repointed'], true)) { $tc_can[] = 'confirm'; }
                                if ($tc_sugg !== null) { $tc_can[] = 'relink'; }
                                if (in_array($tc_r['state'], ['missing', 'mismatch'], true)) { $tc_can[] = 'unlink'; }
                                if ($tc_r['state'] === 'missing' && !$tc_r['has_link'] && $tc_sugg === null) { $tc_can[] = 'dismiss'; } ?>
                                <tr>
                                    <td><input type="checkbox" class="form-check-input tc-bulk-row" value="<?php echo $tc_cid; ?>" data-can="<?php echo implode(' ', $tc_can); ?>"
                                               aria-label="Select <?php echo nullable_htmlentities($tc_r['contact_name']); ?>"></td>
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
                                            <?php if ($tc_r['state'] === 'missing' && !$tc_r['has_link'] && $tc_sugg === null) { ?>
                                                <details class="text-start">
                                                    <summary class="btn btn-outline-secondary btn-sm">No Odoo record&hellip;</summary>
                                                    <form action="post.php" method="post" class="mt-2 small" style="max-width: 22rem;">
                                                        <input type="hidden" name="csrf_token" value="<?php echo nullable_htmlentities($tc_csrf); ?>">
                                                        <input type="hidden" name="contact_id" value="<?php echo $tc_cid; ?>">
                                                        <p class="mb-2">Only if you've checked Odoo yourself (including archived employees) and confirmed there's no record for this person. Drops this off the list; it comes back automatically if a matching Odoo employee ever shows up on a future Check now.</p>
                                                        <button type="submit" name="training_odoo_link_dismiss" class="btn btn-secondary btn-sm">Confirm: no Odoo record</button>
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
                    <p class="text-success mb-3"><i class="fas fa-check-circle me-1"></i>No link needs a decision.<?php if ($tc_dismissed > 0) { ?>
                        <span class="text-muted">(<?php echo $tc_dismissed; ?> dismissed as having no Odoo record.)</span>
                    <?php } ?></p>
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
                The same sync as <strong>Sync Now</strong> in the Odoo connection card above, followed by the
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
</div><!-- /#odoo-employee-links -->

<script nonce="<?php echo nullable_htmlentities($csp_nonce ?? ''); ?>">
// Odoo link bulk actions. Row boxes carry no name (the unsaved-changes guard below ignores them); the
// chosen ids are added as hidden inputs on submit. The server re-checks each person's state.
(function () {
    var form = document.getElementById('tcBulkForm');
    var all = document.getElementById('tcBulkAll');
    if (!form || !all) { return; }
    var rows = Array.prototype.slice.call(document.querySelectorAll('.tc-bulk-row'));
    var buttons = Array.prototype.slice.call(document.querySelectorAll('#tcBulkBar [data-bulk]'));
    var count = document.getElementById('tcBulkCount');
    var labels = { confirm: 'Confirm', relink: 'Relink to suggestion', unlink: 'Unlink', dismiss: 'No Odoo record' };
    function picked() { return rows.filter(function (r) { return r.checked; }); }
    function eligible(action) { return picked().filter(function (r) { return (' ' + r.getAttribute('data-can') + ' ').indexOf(' ' + action + ' ') !== -1; }); }
    function refresh() {
        var n = picked().length;
        count.textContent = n;
        all.checked = n > 0 && n === rows.length;
        all.indeterminate = n > 0 && n < rows.length;
        buttons.forEach(function (b) {
            var k = eligible(b.getAttribute('data-bulk')).length;
            b.disabled = k === 0;
            b.textContent = labels[b.getAttribute('data-bulk')] + (n > 0 ? ' (' + k + ')' : '');
        });
    }
    all.addEventListener('change', function () { rows.forEach(function (r) { r.checked = all.checked; }); refresh(); });
    rows.forEach(function (r) { r.addEventListener('change', refresh); });
    form.addEventListener('submit', function (e) {
        var action = e.submitter && e.submitter.getAttribute('data-bulk');
        var ids = action ? eligible(action) : [];
        if (!ids.length) { e.preventDefault(); return; }
        if ((action === 'unlink' || action === 'dismiss') && !window.confirm(labels[action] + ' for ' + ids.length + ' ' + (ids.length === 1 ? 'person' : 'people') + '?')) {
            e.preventDefault();
            return;
        }
        Array.prototype.slice.call(form.querySelectorAll('input[name="contact_ids[]"]')).forEach(function (x) { x.remove(); });
        ids.forEach(function (r) {
            var h = document.createElement('input');
            h.type = 'hidden'; h.name = 'contact_ids[]'; h.value = r.value;
            form.appendChild(h);
        });
    });
    refresh();
})();
</script>
