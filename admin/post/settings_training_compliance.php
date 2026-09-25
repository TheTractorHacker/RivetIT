<?php

/*
 * Handler for admin/settings_training_compliance.php (Phase 2 spec §5.3).
 *
 * The filename is load-bearing: admin/post.php derives the module from the basename of the
 * HTTP referer, so this file must stay named after the page that posts to it. admin/post.php
 * only includes it for admins.
 *
 * Actions: edit_training_compliance_settings, training_odoo_link_check, training_odoo_accept_target,
 * training_odoo_link_relink, training_odoo_link_unlink, training_odoo_link_confirm,
 * training_reconcile_now, training_snapshot_now. Each validates the CSRF token, runs the service,
 * then logs (logAction + an audit event), flashes and redirects back.
 *
 * toastr renders flash text as HTML, so every flash that can carry Odoo- or DB-derived text goes
 * through nullable_htmlentities(). Odoo calls (Check now) run outside any transaction; the checker
 * takes the `trodoo` named lock itself, so it never overlaps a directory sync.
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\RecordsSettings;
use ITFlow\Training\Directory\OdooLinkChecker;
use ITFlow\Training\Directory\OdooTarget;

$tc_actions = ['edit_training_compliance_settings', 'training_odoo_link_check', 'training_odoo_accept_target', 'training_odoo_link_relink',
    'training_odoo_link_unlink', 'training_odoo_link_confirm', 'training_reconcile_now', 'training_snapshot_now'];
$tc_action = null;
foreach ($tc_actions as $tc_a) {
    if (isset($_POST[$tc_a])) {
        $tc_action = $tc_a;
        break;
    }
}

if ($tc_action !== null) {

    validateCSRFToken($_POST['csrf_token'] ?? '');

    $tc_schema_ok = false;
    if (!empty($config_training_schema_ready) && class_exists(RecordsSettings::class)) {
        try {
            $tc_schema_ok = RecordsSettings::fromDb($mysqli)->schemaReady;
        } catch (\Throwable $e) {
            $tc_schema_ok = false;
        }
    }
    if (!$tc_schema_ok) {
        flash_alert('Run the database update first: the Training compliance tables are not installed yet.', 'error');
        redirect();
    }

    /** One place for the messages of the services' ApiExceptions (their text is ours, still escaped). */
    $tc_api_message = static function (ApiException $e): string {
        if ($e->errCode === 'validation' && !empty($e->fields)) {
            return implode(' ', array_map('strval', array_values($e->fields)));
        }
        return $e->getMessage();
    };

    /** The latest integration, as the sync picks it; redirects with a flash when there is none. */
    $tc_integration = static function () use ($mysqli): array {
        $row = OdooLinkChecker::latestIntegration($mysqli);
        if ($row === null) {
            flash_alert('No Odoo integration is configured.', 'error');
            redirect();
        }
        return $row;
    };

    /** Link-action target: a positive contact id and the person's name for the log. */
    $tc_contact = static function () use ($mysqli): array {
        $cid = intval($_POST['contact_id'] ?? 0);
        if ($cid < 1) {
            flash_alert('Choose a linked person.', 'error');
            redirect();
        }
        $name = (string) (Db::one($mysqli, 'SELECT contact_name FROM contacts WHERE contact_id = ?', 'i', [$cid])['contact_name'] ?? ('#' . $cid));
        return [$cid, $name];
    };
}

// 1 + 4: defaults and the nightly-sync switch -------------------------------------------------------
if ($tc_action === 'edit_training_compliance_settings') {

    $tc_section = ($_POST['tc_section'] ?? '') === 'odoo_sync' ? 'odoo_sync' : 'defaults';
    $tc_old = Db::one($mysqli, 'SELECT config_training_due_soon_days, config_training_reissue_days, config_training_reopen_window_days,
            config_training_evidence_max_mb, config_training_compliance_target_pct, config_training_hire_fill_since, config_training_odoo_sync_enabled
        FROM settings WHERE company_id = 1') ?? [];

    if ($tc_section === 'odoo_sync') {
        $tc_new = ['config_training_odoo_sync_enabled' => !empty($_POST['config_training_odoo_sync_enabled']) ? 1 : 0];
        Db::exec($mysqli, 'UPDATE settings SET config_training_odoo_sync_enabled = ? WHERE company_id = 1', 'i', [$tc_new['config_training_odoo_sync_enabled']]);
    } else {
        $tc_int = static function (string $k, int $min, int $max): ?int {
            $v = trim((string) ($_POST[$k] ?? ''));
            if (preg_match('/^\d{1,6}$/', $v) !== 1 || (int) $v < $min || (int) $v > $max) {
                return null;
            }
            return (int) $v;
        };
        $tc_new = [
            'config_training_due_soon_days' => $tc_int('config_training_due_soon_days', 0, 365),
            'config_training_reissue_days' => $tc_int('config_training_reissue_days', 1, 365),
            'config_training_reopen_window_days' => $tc_int('config_training_reopen_window_days', 0, 365),
            'config_training_evidence_max_mb' => $tc_int('config_training_evidence_max_mb', 1, 95),
            'config_training_compliance_target_pct' => $tc_int('config_training_compliance_target_pct', 1, 100),
        ];
        $tc_labels = [
            'config_training_due_soon_days' => '"Due soon" window (0 to 365 days)',
            'config_training_reissue_days' => 'Redo after a voided record (1 to 365 days)',
            'config_training_reopen_window_days' => 'Reopen window (0 to 365 days)',
            'config_training_evidence_max_mb' => 'Evidence scan upload (1 to 95 MB)',
            'config_training_compliance_target_pct' => 'Compliance target (1 to 100%)',
        ];
        $tc_bad = array_keys(array_filter($tc_new, static fn($v) => $v === null));
        if ($tc_bad) {
            flash_alert('Nothing was saved. Check: ' . nullable_htmlentities(implode(', ', array_map(static fn($k) => $tc_labels[$k], $tc_bad))) . '.', 'error');
            redirect();
        }
        $tc_hire = trim((string) ($_POST['config_training_hire_fill_since'] ?? ''));
        if ($tc_hire !== '') {
            $tc_max = Clock::addDays(Clock::todayLocal(), 366);
            if (!Clock::isYmd($tc_hire) || $tc_hire < '2000-01-01' || $tc_hire > $tc_max) {
                flash_alert('Nothing was saved. The hire-date fill date must be a real date (or empty to leave hire dates alone).', 'error');
                redirect();
            }
        }
        $tc_new['config_training_hire_fill_since'] = $tc_hire === '' ? null : $tc_hire;
        Db::exec($mysqli, 'UPDATE settings SET config_training_due_soon_days = ?, config_training_reissue_days = ?, config_training_reopen_window_days = ?,
                config_training_evidence_max_mb = ?, config_training_compliance_target_pct = ?, config_training_hire_fill_since = ?
            WHERE company_id = 1', 'iiiiis', array_values($tc_new));
    }

    $tc_changed = [];
    foreach ($tc_new as $tc_col => $tc_val) {
        $tc_before = $tc_old[$tc_col] ?? null;
        if ((string) $tc_before !== (string) $tc_val) {
            $tc_changed[$tc_col] = ['from' => $tc_before, 'to' => $tc_val];
        }
    }

    logAction('Training', 'Edit', "$session_name edited Training compliance settings");
    if ($tc_changed) {
        try {
            \ITFlow\Audit\AuditService::record('training.settings_changed', $session_user_id, 'settings', 1, 'update',
                "$session_name changed Training compliance settings", ['changed' => $tc_changed]);
        } catch (\Throwable $e) {
            error_log('Training compliance: audit failed: ' . $e->getMessage());
        }
    }

    if ($tc_section === 'odoo_sync') {
        flash_alert($tc_new['config_training_odoo_sync_enabled'] ? 'Nightly Odoo directory sync turned on.' : 'Nightly Odoo directory sync turned off.');
    } elseif (array_key_exists('config_training_hire_fill_since', $tc_changed) && $tc_new['config_training_hire_fill_since'] !== null) {
        flash_alert(nullable_htmlentities('Training compliance settings saved. Empty hire dates of Odoo employees created on or after '
            . $tc_new['config_training_hire_fill_since'] . ' are filled on the next directory sync.'));
    } else {
        flash_alert('Training compliance settings saved.');
    }
    redirect();
}

// 2: Check now ---------------------------------------------------------------------------------
if ($tc_action === 'training_odoo_link_check') {

    $tc_row = $tc_integration();
    if (empty($tc_row['base_url']) || empty($tc_row['database_name']) || empty($tc_row['username']) || empty($tc_row['api_key_enc'])) {
        flash_alert('Save the Odoo base URL, database, username and API key first (Integrations > Directory Sync).', 'error');
        redirect();
    }
    @set_time_limit(95);   // Cloudflare cuts the request at 100 s
    try {
        $tc_client = \ITFlow\Integrations\Odoo\OdooConnectorFactory::clientFromRow($tc_row);
        $tc_res = (new OdooLinkChecker($mysqli, $tc_row))->run($tc_client, $tc_row, intval($session_user_id));
    } catch (\ITFlow\Integrations\Odoo\OdooAuthException $e) {
        error_log('Training link check: ' . $e->getMessage());
        flash_alert('Odoo refused the API key (expired or missing rights).', 'error');
        redirect();
    } catch (ApiException $e) {
        flash_alert(nullable_htmlentities($tc_api_message($e)), 'error');
        redirect();
    } catch (\Throwable $e) {
        error_log('Training link check: ' . get_class($e) . ': ' . $e->getMessage());
        flash_alert(nullable_htmlentities('The link check failed: ' . mb_substr($e->getMessage(), 0, 300)), 'error');
        redirect();
    }

    $tc_line = "{$tc_res['checked']} checked: {$tc_res['ok']} ok, {$tc_res['mismatch']} name changed, {$tc_res['missing']} missing, {$tc_res['repointed']} re-pointed"
        . ($tc_res['new'] ? ", {$tc_res['new']} new" : '');
    logAction('Training', 'Check', "$session_name checked the Odoo employee links ($tc_line)");
    try {
        \ITFlow\Audit\AuditService::record('training.odoo_links_checked', $session_user_id, 'odoo_integration', intval($tc_row['odoo_integration_id']), 'check',
            "Checked the Odoo employee links: $tc_line", [
                'ok' => $tc_res['ok'], 'mismatch' => $tc_res['mismatch'], 'missing' => $tc_res['missing'], 'repointed' => $tc_res['repointed'],
                'new' => $tc_res['new'], 'accepted_now' => $tc_res['accepted_now'],
            ]);
    } catch (\Throwable $e) {
        error_log('Training compliance: audit failed: ' . $e->getMessage());
    }

    $tc_flagged = $tc_res['mismatch'] + $tc_res['repointed'] + $tc_res['missing'];
    $tc_msg = "Link check: $tc_line.";
    if ($tc_res['accepted_now']) {
        $tc_msg .= ' The new Odoo connection was accepted; directory sync is allowed again.';
    } elseif (!empty($tc_res['target']['pending'])) {
        $tc_msg .= ' Directory sync stays blocked until the flagged links are resolved or the new connection is accepted.';
    }
    flash_alert(nullable_htmlentities($tc_msg), $tc_flagged > 0 || !empty($tc_res['target']['pending']) ? 'warning' : 'success');
    redirect();
}

// 2: Accept new target (admin override) ---------------------------------------------------------
if ($tc_action === 'training_odoo_accept_target') {

    $tc_row = $tc_integration();
    $tc_word = trim((string) ($_POST['accept_word'] ?? ''));
    $tc_reason = trim((string) ($_POST['accept_reason'] ?? ''));
    if ($tc_word !== 'ACCEPT') {
        flash_alert('Type ACCEPT to accept the new Odoo connection.', 'error');
        redirect();
    }
    if (!mb_check_encoding($tc_reason, 'UTF-8') || mb_strlen($tc_reason) < 10 || mb_strlen($tc_reason) > 400) {
        flash_alert('Give a reason of 10 to 400 characters.', 'error');
        redirect();
    }
    $tc_guard = OdooTarget::guard($mysqli, $tc_row);
    if ($tc_guard['ok']) {
        flash_alert('The Odoo connection is already accepted.', 'info');
        redirect();
    }
    try {
        OdooTarget::accept($mysqli, $tc_row, intval($session_user_id), $tc_reason);   // audits training.odoo_target_accepted
    } catch (\Throwable $e) {
        error_log('Training accept target: ' . get_class($e) . ': ' . $e->getMessage());
        flash_alert('The new Odoo connection could not be accepted. The details were written to the server error log.', 'error');
        redirect();
    }
    logAction('Training', 'Edit', "$session_name accepted the new Odoo connection " . OdooTarget::describe($tc_row) . " for Training: $tc_reason");
    flash_alert(nullable_htmlentities('Accepted the Odoo connection ' . OdooTarget::describe($tc_row) . '. Directory sync is allowed again; run Check now to refresh the link states.'));
    redirect();
}

// 2: per-link actions (S6) ------------------------------------------------------------------------
if (in_array($tc_action, ['training_odoo_link_relink', 'training_odoo_link_unlink', 'training_odoo_link_confirm'], true)) {

    $tc_row = $tc_integration();
    [$tc_cid, $tc_name] = $tc_contact();
    try {
        $tc_checker = new OdooLinkChecker($mysqli, $tc_row);
        if ($tc_action === 'training_odoo_link_relink') {
            $tc_eid = intval($_POST['odoo_employee_id'] ?? 0);
            if ($tc_eid < 1) {
                flash_alert('Choose the suggested Odoo employee.', 'error');
                redirect();
            }
            $tc_checker->relink($tc_cid, $tc_eid, intval($session_user_id));
            $tc_done = "relinked $tc_name to Odoo employee #$tc_eid";
        } elseif ($tc_action === 'training_odoo_link_unlink') {
            $tc_checker->unlink($tc_cid, intval($session_user_id));
            $tc_done = "unlinked $tc_name from Odoo";
        } else {
            $tc_checker->confirm($tc_cid, intval($session_user_id));
            $tc_done = "confirmed the Odoo link of $tc_name";
        }
    } catch (ApiException $e) {
        flash_alert(nullable_htmlentities($tc_api_message($e)), 'error');
        redirect();
    } catch (\Throwable $e) {
        error_log('Training link action: ' . get_class($e) . ': ' . $e->getMessage());
        flash_alert('The link could not be changed. The details were written to the server error log.', 'error');
        redirect();
    }
    // OdooLinkChecker audits training.odoo_link_changed itself.
    logAction('Training', 'Edit', "$session_name $tc_done", 0, $tc_cid);
    $tc_msg = ucfirst($tc_done) . '.';
    $tc_guard = OdooTarget::guard($mysqli, $tc_row);
    if (!$tc_guard['ok']) {
        $tc_msg .= ' Directory sync stays blocked until every flagged link is resolved.';
    }
    flash_alert(nullable_htmlentities($tc_msg));
    redirect();
}

// 3: Recalculate now -----------------------------------------------------------------------------
if ($tc_action === 'training_reconcile_now') {

    @set_time_limit(95);
    try {
        $tc_rec = (new \ITFlow\Training\Assign\AssignmentService(\ITFlow\Training\Core\Access::ctx($mysqli)))->reconcile(null, 'reconcile_now');
    } catch (\Throwable $e) {
        error_log('Training reconcile (admin): ' . get_class($e) . ': ' . $e->getMessage());
        flash_alert('Recalculating assignments failed. The details were written to the server error log.', 'error');
        redirect();
    }
    if (!empty($tc_rec['skipped_busy'])) {
        flash_alert('Another recalculation is running. Try again in a minute.', 'warning');
        redirect();
    }
    $tc_line = "{$tc_rec['created']} created, {$tc_rec['reopened']} reopened, {$tc_rec['completed']} completed, {$tc_rec['cancelled']} cancelled"
        . " for {$tc_rec['contacts']} people in {$tc_rec['ms']} ms" . ($tc_rec['failed_chunks'] ? "; {$tc_rec['failed_chunks']} batch(es) failed" : '');
    logAction('Training', 'Edit', "$session_name recalculated training assignments ($tc_line)");
    flash_alert(nullable_htmlentities('Assignments recalculated: ' . $tc_line . '.'), $tc_rec['failed_chunks'] ? 'warning' : 'success');
    redirect();
}

// 3: Snapshot now ---------------------------------------------------------------------------------
if ($tc_action === 'training_snapshot_now') {

    @set_time_limit(95);
    try {
        $tc_snap = \ITFlow\Training\Reports\SnapshotService::capture($mysqli, Clock::todayLocal());
    } catch (\Throwable $e) {
        error_log('Training snapshot (admin): ' . get_class($e) . ': ' . $e->getMessage());
        flash_alert('The snapshot could not be captured. The details were written to the server error log.', 'error');
        redirect();
    }
    if (!empty($tc_snap['skipped'])) {
        flash_alert('A snapshot is being captured right now. Try again in a minute.', 'warning');
        redirect();
    }
    logAction('Training', 'Edit', "$session_name captured the training compliance snapshot for {$tc_snap['date']} ({$tc_snap['rows']} rows)");
    flash_alert(nullable_htmlentities("Snapshot for {$tc_snap['date']} captured ({$tc_snap['rows']} rows)."));
    redirect();
}
