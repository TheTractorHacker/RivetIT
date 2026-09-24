<?php

/*
 * Handler for admin/settings_training.php (spec §5.11).
 *
 * The filename is load-bearing: admin/post.php derives the module from the basename of the
 * HTTP referer, so this file must stay named after the page that posts to it. admin/post.php
 * only includes it for admins.
 *
 * Actions: edit_training_settings, training_ledger_verify, training_youtube_key_test,
 * training_media_purge. Each validates the CSRF token, runs, then logs (logAction + an audit
 * event where the spec names one), flashes and redirects back.
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

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

    if ($tr_record['new_break']) {
        $tr_first = $tr_result['breaks'][0];
        logApp('Training', 'error', 'Training ledger verification found a break: ' . $tr_record['line'] . ' - ' . $tr_first['detail']);
        \ITFlow\Audit\AuditService::record('training.ledger_break', $session_user_id, 'training_ledger', $tr_first['seq'], 'verify', $tr_record['line'], [
            'breaks' => array_slice($tr_result['breaks'], 0, 20),
            'head' => $tr_result['head'],
            'deep' => false,
        ]);
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
    $tr_enc = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_training_youtube_api_key FROM settings WHERE company_id = 1"))['config_training_youtube_api_key'] ?? '';
    $tr_key = decryptSetting((string) $tr_enc);
    if ($tr_key === '') {
        flash_alert('No YouTube Data API key is saved.', 'warning');
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

    try {
        $tr_purger = new \ITFlow\Training\Media\MediaPurger(\ITFlow\Training\Core\Access::ctx($mysqli));
        $tr_outcome = $tr_purger->purge($tr_ids, $tr_reason);
    } catch (\Throwable $e) {
        error_log('Training media purge: ' . get_class($e) . ': ' . $e->getMessage());
        flash_alert('The purge could not run. The details were written to the server error log.', 'error');
        redirect();
    }

    $tr_count = is_array($tr_outcome['purged'] ?? null) ? count($tr_outcome['purged']) : count($tr_ids);
    logAction("Training", "Delete", "$session_name purged $tr_count unreferenced training media file(s)");
    \ITFlow\Audit\AuditService::record('training.media_purged', $session_user_id, 'training_media', null, 'purge',
        "$session_name purged $tr_count unreferenced training media file(s)", ['media_ids' => $tr_ids, 'reason' => $tr_reason]);

    flash_alert("Purged $tr_count unreferenced media file(s).");
    redirect();
}
