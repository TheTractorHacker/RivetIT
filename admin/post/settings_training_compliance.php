<?php

/*
 * Compliance & assignments and Employee links (Odoo) actions of admin/settings_training.php
 * (Phase 2 spec §5.3; a page of its own, settings_training_compliance.php, until 2026-09-26).
 *
 * Required by admin/post/settings_training.php, which admin/post.php includes (admins only) for
 * every form on the merged page and which points redirect() at the right section first.
 * admin/post.php also includes this file directly for a POST whose Referer is the old page URL
 * (it now 302s to settings_training.php#compliance), so keep the filename.
 *
 * Actions: edit_training_compliance_settings, training_odoo_link_check, training_odoo_accept_target,
 * training_odoo_link_relink, training_odoo_link_unlink, training_odoo_link_confirm,
 * training_reconcile_now, training_snapshot_now. Each validates the CSRF token, runs the service,
 * then logs (logAction + an audit event), flashes and redirects back.
 *
 * The settings save, Recalculate and Snapshot (and the schema check) run in the shared
 * ITFlow\Training\Settings\SettingsService, which agent/training_settings.php (Training level 3)
 * uses too. The Odoo actions stay here: they are admin-only (roles audit 2026-09-26, P2).
 *
 * toastr renders flash text as HTML, so every flash that can carry Odoo- or DB-derived text goes
 * through nullable_htmlentities(). Odoo calls (Check now) run outside any transaction; the checker
 * takes the `trodoo` named lock itself, so it never overlaps a directory sync.
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Db;
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

    $tc_schema_ok = (new \ITFlow\Training\Settings\SettingsService($mysqli, intval($session_user_id), $session_name))
        ->complianceSchemaReady(!empty($config_training_schema_ready));
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
// Validation and the write live in the shared Training settings service (also used by
// agent/training_settings.php for Training level 3, without the hire-date fill and the sync switch).
if ($tc_action === 'edit_training_compliance_settings') {

    $tc_service = new \ITFlow\Training\Settings\SettingsService($mysqli, intval($session_user_id), $session_name);
    $tc_out = (($_POST['tc_section'] ?? '') === 'odoo_sync') ? $tc_service->saveOdooSync($_POST) : $tc_service->saveComplianceDefaults($_POST, true);
    flash_alert($tc_out['message'], $tc_out['type']);
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

// 3: Recalculate now / Snapshot now (shared Training settings service) -------------------------------
if ($tc_action === 'training_reconcile_now' || $tc_action === 'training_snapshot_now') {

    $tc_service = new \ITFlow\Training\Settings\SettingsService($mysqli, intval($session_user_id), $session_name);
    $tc_out = $tc_action === 'training_reconcile_now' ? $tc_service->reconcileNow() : $tc_service->snapshotNow();
    flash_alert($tc_out['message'], $tc_out['type']);
    redirect();
}
