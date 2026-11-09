<?php

/*
 * Handler for admin/settings_training.php, the one Admin > Training page (spec §5.11; since
 * 2026-09-26 also the former Training compliance and Training kiosk pages).
 *
 * The filename is load-bearing: admin/post.php derives the module from the basename of the
 * HTTP referer, so this file must stay named after the page that posts to it. admin/post.php
 * only includes it for admins.
 *
 * Every form on the page posts here. This file runs the General & media and Records ledger
 * actions itself (edit_training_settings, training_ledger_verify, training_youtube_key_test,
 * training_media_purge) and, at the end, requires the two section handlers that keep their own
 * files, unchanged in what they check and write:
 *   settings_training_compliance.php  edit_training_compliance_settings (defaults and the nightly
 *                                     Odoo sync switch), training_odoo_link_check,
 *                                     training_odoo_accept_target, training_odoo_link_relink /
 *                                     _unlink / _confirm, training_reconcile_now, training_snapshot_now
 *   settings_training_kiosk.php       edit_training_kiosk_settings (thresholds and the Odoo-PIN switch)
 *   settings_training_automation.php  every ta_* action of the Phase 5 cards (certificates, reminders,
 *                                     video checks, Odoo write-back), via Settings\AutomationActions
 * Each action validates the CSRF token, runs, then logs (logAction + an audit event where the
 * spec names one), flashes and redirects back.
 *
 * Since the roles audit (2026-09-26, P2) the settings saves, Verify now, Recalculate and Snapshot
 * run in the shared ITFlow\Training\Settings\SettingsService, which agent/training_settings.php
 * (Training level 3, not admin) uses too; these handlers keep the CSRF check, the admin-only
 * actions (YouTube key test, media purge, Odoo links) and the flash + redirect.
 *
 * Back to the right section: every redirect() in these handlers is argument-less, so it returns
 * to HTTP_REFERER - and browsers never send the #fragment. Before any handler runs, the Referer
 * is pointed at the section the posted action belongs to (a fixed map below; nothing from the
 * request is echoed into the URL). A CSRF failure still goes to index.php as before.
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

// Action => section anchor on admin/settings_training.php, in the order the handlers test them.
$tr_return_to = [
    'edit_training_settings'            => 'general',
    'training_ledger_verify'            => 'ledger',
    'training_youtube_key_test'         => 'youtube',
    'training_media_purge'              => 'media-storage',
    'edit_training_compliance_settings' => (($_POST['tc_section'] ?? '') === 'odoo_sync') ? 'odoo-sync' : 'compliance',
    'training_odoo_link_check'          => 'odoo',
    'training_odoo_accept_target'       => 'odoo',
    'training_odoo_link_relink'         => 'odoo',
    'training_odoo_link_unlink'         => 'odoo',
    'training_odoo_link_confirm'        => 'odoo',
    'training_reconcile_now'            => 'maintenance',
    'training_snapshot_now'             => 'maintenance',
    'edit_training_kiosk_settings'      => 'kiosk',
];
foreach ($tr_return_to as $tr_action => $tr_anchor) {
    if (isset($_POST[$tr_action])) {
        $_SERVER['HTTP_REFERER'] = '/admin/settings_training.php#' . $tr_anchor;
        break;
    }
}
unset($tr_return_to, $tr_action, $tr_anchor);

if (isset($_POST['edit_training_settings']) || isset($_POST['training_ledger_verify'])
    || isset($_POST['training_youtube_key_test']) || isset($_POST['training_media_purge'])) {

    validateCSRFToken($_POST['csrf_token'] ?? '');

    if (empty($config_training_schema_ready)) {
        flash_alert('Run the database update first: the Training tables are not installed yet.', 'error');
        redirect();
    }
}

if (isset($_POST['edit_training_settings'])) {

    // Validation and the write live in the shared Training settings service (also used by
    // agent/training_settings.php for Training level 3); admins save every field here.
    $tr_out = (new \ITFlow\Training\Settings\SettingsService($mysqli, intval($session_user_id), $session_name))->saveGeneral($_POST, true);
    flash_alert($tr_out['message'], $tr_out['type']);
    redirect();
}

if (isset($_POST['training_ledger_verify'])) {

    $tr_out = (new \ITFlow\Training\Settings\SettingsService($mysqli, intval($session_user_id), $session_name))->verifyLedger();
    flash_alert($tr_out['message'], $tr_out['type']);
    redirect();
}

if (isset($_POST['training_youtube_key_test'])) {

    if (!class_exists('ITFlow\Training\Media\YouTubeDataApi')) {
        flash_alert('Key testing becomes available with the media pipeline update.', 'warning');
        redirect();
    }
    // The key is decrypted only through Ctx::youtubeKey() (spec §8 Secrets).
    $tr_key = \ITFlow\Training\Core\Access::ctx($mysqli)->youtubeKey();
    if ($tr_key === null) {
        flash_alert('No YouTube Data API key is saved, or it cannot be decrypted with this server\'s settings key. Save the key again.', 'warning');
        redirect();
    }

    // A long-lived public video ("Me at the zoo") - only its details are read.
    $tr_ok = false;
    $tr_msg = '';
    try {
        $tr_details = \ITFlow\Training\Media\YouTubeDataApi::details('jNQXAC9IVRw', $tr_key);
        $tr_ok = is_array($tr_details);
        if (!$tr_ok) {
            $tr_msg = 'YouTube did not accept the key, or the test video could not be read.';
        }
    } catch (\Throwable $e) {
        $tr_msg = str_replace($tr_key, '[key]', $e->getMessage());
    }
    unset($tr_key);

    logAction("Training", "Test", "$session_name tested the YouTube Data API key: " . ($tr_ok ? 'ok' : 'failed'));
    if ($tr_ok) {
        flash_alert('The YouTube Data API key works.');
    } else {
        flash_alert('Key test failed: ' . nullable_htmlentities($tr_msg), 'error');
    }
    redirect();
}

if (isset($_POST['training_media_purge'])) {

    if (!class_exists('ITFlow\Training\Media\MediaPurger')) {
        flash_alert('Purging becomes available with the media pipeline update.', 'warning');
        redirect();
    }
    $tr_reason = trim((string) ($_POST['purge_reason'] ?? ''));
    $tr_ids = [];
    foreach ((array) ($_POST['media_ids'] ?? []) as $tr_id) {
        $tr_id = intval($tr_id);
        if ($tr_id > 0 && !in_array($tr_id, $tr_ids, true)) {
            $tr_ids[] = $tr_id;
        }
    }
    if (!mb_check_encoding($tr_reason, 'UTF-8') || mb_strlen($tr_reason) < 5 || mb_strlen($tr_reason) > 500) {
        flash_alert('Type a reason (5 to 500 characters) to purge media.', 'error');
        redirect();
    }
    if (!$tr_ids) {
        flash_alert('Select at least one file to purge.', 'warning');
        redirect();
    }

    // Never trust the posted ids. The list on the page can be stale (an author may have attached
    // one of those files to a draft since it was rendered) and a crafted POST can name any id,
    // including evidence. Re-list what is unreferenced NOW and purge only the intersection.
    // This closes the stale-form case and keeps anything the list never offered out of the purge.
    // It does not close the race between this re-list and the unlink: MediaPurger::purge() must
    // itself re-check "still unreferenced, not evidence" per id under its 'trmedia' lock (Lane B).
    $tr_skipped = [];
    try {
        $tr_purger = new \ITFlow\Training\Media\MediaPurger(\ITFlow\Training\Core\Access::ctx($mysqli));
        $tr_still = [];
        foreach ($tr_purger->unreferenced(7) as $tr_u) {
            $tr_uid = intval($tr_u['media_id'] ?? $tr_u['id'] ?? 0);
            if ($tr_uid > 0 && (string) ($tr_u['media_kind'] ?? $tr_u['kind'] ?? '') !== 'evidence') {
                $tr_still[$tr_uid] = true;
            }
        }
        $tr_skipped = array_values(array_filter($tr_ids, fn($id) => !isset($tr_still[$id])));
        $tr_ids = array_values(array_filter($tr_ids, fn($id) => isset($tr_still[$id])));
        if (!$tr_ids) {
            flash_alert('Nothing was purged: the selected file(s) are in use again or can no longer be purged. The list has been refreshed.', 'warning');
            redirect();
        }
        $tr_outcome = $tr_purger->purge($tr_ids, $tr_reason);
    } catch (\Throwable $e) {
        error_log('Training media purge: ' . get_class($e) . ': ' . $e->getMessage());
        flash_alert('The purge could not run. The details were written to the server error log.', 'error');
        redirect();
    }

    $tr_count = is_array($tr_outcome['purged'] ?? null) ? count($tr_outcome['purged']) : count($tr_ids);
    logAction("Training", "Delete", "$session_name purged $tr_count unreferenced training media file(s)");
    \ITFlow\Audit\AuditService::record('training.media_purged', $session_user_id, 'training_media', null, 'purge',
        "$session_name purged $tr_count unreferenced training media file(s)", ['media_ids' => $tr_ids, 'skipped_in_use' => $tr_skipped, 'reason' => $tr_reason]);

    $tr_skip_note = $tr_skipped ? ' ' . count($tr_skipped) . ' selected file(s) were skipped because they are in use again or can no longer be purged.' : '';
    flash_alert("Purged $tr_count unreferenced media file(s).$tr_skip_note", $tr_skipped ? 'warning' : 'success');
    redirect();
}

// Compliance & assignments, Employee links (Odoo) and Kiosk & sign-in. Each file acts only on its
// own action names (and admin/post.php still includes it directly for a POST from the old page
// URL, e.g. a tab opened before the merge).
require_once __DIR__ . '/settings_training_compliance.php';
require_once __DIR__ . '/settings_training_kiosk.php';
// Training automation (Phase 5, DB 2.6.96): certificates, reminders, video checks, Odoo write-back (ta_* actions).
require_once __DIR__ . '/settings_training_automation.php';
