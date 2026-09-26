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
 * Each action validates the CSRF token, runs, then logs (logAction + an audit event where the
 * spec names one), flashes and redirects back.
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

    $tr_old = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_training_languages, config_training_default_pass_pct, config_training_default_max_attempts,
            config_training_attestation_text, config_training_video_max_mb, config_training_pdf_max_mb, config_training_pdf_max_pages,
            config_training_image_max_mb, config_training_file_max_mb, config_training_media_budget_mb
        FROM settings WHERE company_id = 1")) ?: [];

    $tr_clamp = fn($v, int $min, int $max): int => max($min, min($max, intval($v)));
    $tr_cap = \ITFlow\Training\Core\TrainingSettings::UPLOAD_CAP_MB;

    // English is always offered; the other known languages come from the checkboxes.
    $tr_langs = ['en'];
    foreach (array_keys(\ITFlow\Training\Core\TrainingSettings::KNOWN_LANGUAGES) as $tr_code) {
        if ($tr_code !== 'en' && in_array($tr_code, (array) ($_POST['training_languages'] ?? []), true)) {
            $tr_langs[] = $tr_code;
        }
    }

    $tr_attestation = trim((string) ($_POST['config_training_attestation_text'] ?? ''));
    if (!mb_check_encoding($tr_attestation, 'UTF-8')) {
        flash_alert('The attestation text contains characters that could not be read. Retype it and save again.', 'error');
        redirect();
    }
    $tr_attestation = $tr_attestation === '' ? null : mb_substr($tr_attestation, 0, 5000);

    $tr_new = [
        'config_training_languages'            => implode(',', $tr_langs),
        'config_training_default_pass_pct'     => $tr_clamp($_POST['config_training_default_pass_pct'] ?? 80, 50, 100),
        'config_training_default_max_attempts' => $tr_clamp($_POST['config_training_default_max_attempts'] ?? 3, 0, 10),
        'config_training_attestation_text'     => $tr_attestation,
        'config_training_video_max_mb'         => $tr_clamp($_POST['config_training_video_max_mb'] ?? 95, 1, $tr_cap),
        'config_training_pdf_max_mb'           => $tr_clamp($_POST['config_training_pdf_max_mb'] ?? 50, 1, $tr_cap),
        'config_training_pdf_max_pages'        => $tr_clamp($_POST['config_training_pdf_max_pages'] ?? 150, 1, 1000),
        'config_training_image_max_mb'         => $tr_clamp($_POST['config_training_image_max_mb'] ?? 15, 1, $tr_cap),
        'config_training_file_max_mb'          => $tr_clamp($_POST['config_training_file_max_mb'] ?? 50, 1, $tr_cap),
        'config_training_media_budget_mb'      => $tr_clamp($_POST['config_training_media_budget_mb'] ?? 1024, 100, 1048576),
    ];

    // YouTube Data API key: blank keeps the saved one, "Remove" clears it, anything else replaces it.
    $tr_key_change = 'unchanged';
    $tr_key_sql = '';
    $tr_key_value = null;
    $tr_key_input = trim((string) ($_POST['config_training_youtube_api_key'] ?? ''));
    if (!empty($_POST['training_youtube_key_clear'])) {
        $tr_key_change = 'removed';
        $tr_key_sql = ', config_training_youtube_api_key = NULL';
    } elseif ($tr_key_input !== '') {
        if (!preg_match('/^[A-Za-z0-9_\-]{20,200}$/', $tr_key_input)) {
            flash_alert('That does not look like a YouTube Data API key (letters, digits, - and _ only). Nothing was saved.', 'error');
            redirect();
        }
        try {
            $tr_key_value = encryptSetting($tr_key_input);
        } catch (\RuntimeException $e) {
            flash_alert('The API key could not be stored securely: the settings encryption key is missing from config.php. Nothing was saved.', 'error');
            redirect();
        }
        $tr_key_change = 'replaced';
        $tr_key_sql = ', config_training_youtube_api_key = ?';
    }

    $tr_sql = "UPDATE settings SET config_training_languages = ?, config_training_default_pass_pct = ?, config_training_default_max_attempts = ?,
        config_training_attestation_text = ?, config_training_video_max_mb = ?, config_training_pdf_max_mb = ?, config_training_pdf_max_pages = ?,
        config_training_image_max_mb = ?, config_training_file_max_mb = ?, config_training_media_budget_mb = ?$tr_key_sql
        WHERE company_id = 1";
    $tr_types = 'siisiiiiii' . ($tr_key_value !== null ? 's' : '');
    $tr_params = array_values($tr_new);
    if ($tr_key_value !== null) {
        $tr_params[] = $tr_key_value;
    }
    \ITFlow\Training\Core\Db::exec($mysqli, $tr_sql, $tr_types, $tr_params);

    $tr_changed = [];
    foreach ($tr_new as $tr_col => $tr_val) {
        $tr_before = $tr_old[$tr_col] ?? null;
        if ((string) $tr_before !== (string) $tr_val) {
            // Never put the attestation text itself in the audit trail; just that it changed.
            $tr_changed[$tr_col] = $tr_col === 'config_training_attestation_text' ? 'changed' : ['from' => $tr_before, 'to' => $tr_val];
        }
    }
    if ($tr_key_change !== 'unchanged') {
        $tr_changed['config_training_youtube_api_key'] = $tr_key_change;   // never the value
    }

    logAction("Training", "Edit", "$session_name edited Training settings");
    if ($tr_changed) {
        \ITFlow\Audit\AuditService::record('training.settings_changed', $session_user_id, 'settings', 1, 'update',
            "$session_name changed Training settings", ['changed' => $tr_changed]);
    }

    flash_alert("Training settings saved");
    redirect();
}

if (isset($_POST['training_ledger_verify'])) {

    @set_time_limit(90);
    try {
        $tr_result = \ITFlow\Training\Core\LedgerVerifier::verify($mysqli, ['time_budget_s' => 60]);
        $tr_record = \ITFlow\Training\Core\LedgerVerifier::recordResult($mysqli, $tr_result);
    } catch (\Throwable $e) {
        error_log('Training ledger verify (admin): ' . get_class($e) . ': ' . $e->getMessage());
        flash_alert('The ledger check could not run. The details were written to the server error log.', 'error');
        redirect();
    }

    // A NEW break signature alerts once, from whichever path saw it first - the same block as
    // cron/training_cron.php. recordResult() just stored this line, so the nightly run will not
    // see it as new; if this path did not notify, no other admin would ever hear of it.
    if ($tr_record['new_break']) {
        $tr_first = $tr_result['breaks'][0];
        logApp('Training', 'error', 'Training ledger verification found a break: ' . $tr_record['line'] . ' - ' . $tr_first['detail']);
        $tr_admins = mysqli_query($mysqli, "SELECT users.user_id FROM users
            JOIN user_roles ON users.user_role_id = user_roles.role_id
            WHERE user_roles.role_is_admin = 1 AND users.user_type = 1 AND users.user_status = 1 AND users.user_archived_at IS NULL");
        while ($tr_admin = mysqli_fetch_assoc($tr_admins)) {
            notifyUser(intval($tr_admin['user_id']), 'Training', 'Training records integrity check found a problem: ' . $tr_record['line'] . '. Open Admin > Training for details.', '/admin/settings_training.php#ledger');
        }
        try {
            \ITFlow\Audit\AuditService::record('training.ledger_break', $session_user_id, 'training_ledger', $tr_first['seq'], 'verify', $tr_record['line'], [
                'breaks' => array_slice($tr_result['breaks'], 0, 20),
                'head' => $tr_result['head'],
                'deep' => false,
            ]);
        } catch (\Throwable $e) {
            logApp('Training', 'error', 'Could not write the ledger-break audit event: ' . $e->getMessage());
        }
    }

    logAction("Training", "Verify", "$session_name verified the training ledger: " . $tr_record['line']);

    if ($tr_result['ok']) {
        flash_alert('Ledger verified: ' . nullable_htmlentities($tr_record['line']));
    } elseif (!empty($tr_result['breaks'])) {
        $tr_first = $tr_result['breaks'][0];
        flash_alert('Ledger check found a problem: ' . nullable_htmlentities($tr_record['line'] . ' - ' . $tr_first['detail']), 'error');
    } else {
        flash_alert('Ledger check stopped at the 60-second limit before finishing: ' . nullable_htmlentities($tr_record['line']), 'warning');
    }
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
