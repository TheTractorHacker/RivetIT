<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

/*
 * Administration > Endpoint agent: form handlers. CSRF, the Referer-based dispatch of admin/post.php, $_POST extraction and flash messages are
 * RivetIT's; every validation, authorization decision, database write and audit entry is RivetCore\Rmm (rivet/rivet-core): RmmAdmin for the
 * settings, binaries, releases, installers and signing key, TechnicianActions for devices and enrollment tokens.
 */

require_once dirname(__DIR__, 2) . '/includes/rmm_bootstrap.php';

use RivetCore\Rmm\Technician\ActionResult;

mysqli_report(MYSQLI_REPORT_OFF);

/** Whole-number field clamped to a range. */
function ea_post_int(string $k, int $min, int $max, int $default): int
{
    $v = $_POST[$k] ?? null;
    return is_numeric($v) ? max($min, min($max, (int) $v)) : $default;
}

/** Flash the outcome of an action and return to the page. */
function ea_flash_result(ActionResult $r, ?string $okText = null): void
{
    flash_alert(nullable_htmlentities($r->ok ? ($okText ?? $r->message) : $r->message), $r->ok ? 'success' : 'error');
    redirect();
}

$rmm = rivetRmmModule();
$ea_admin = $rmm->admin();
$ea_tech = $rmm->technician();
$ea_who = rivetRmmPrincipal((int) $session_user_id, (string) $session_name);

// The module switch (Administration > Endpoint agent > RMM module). Works while the module is off: turning it on is one of its operations.
if (isset($_POST['rmm_module_switch'])) {
    validateCSRFToken($_POST['csrf_token']);
    $r = ($_POST['rmm_module_switch'] ?? '') === 'on' ? $ea_admin->enable($ea_who) : $ea_admin->disable($ea_who);
    if ($r->ok && ($_POST['rmm_module_switch'] ?? '') === 'on' && !empty($_POST['feature_preset'])) {
        $p = $ea_admin->applyFeaturePreset($ea_who, (string) $_POST['feature_preset']);
        if (!$p->ok) {
            ea_flash_result($p);
        }
    }
    if (!$rmm->syncState() && rivetRmmStateDir() !== null) {
        flash_alert('The module state file could not be written (' . nullable_htmlentities((string) rivetRmmStateDir()) . '). Make that directory writable by the web server user, then save the switch again.', 'warning');
    }
    ea_flash_result($r, ($_POST['rmm_module_switch'] ?? '') === 'on' ? 'RMM module switched on.' : 'RMM module switched off. Nothing was deleted; enrolled agents back off and come back by themselves when it is switched on again.');
}

if (isset($_POST['save_feature_preset'])) {
    validateCSRFToken($_POST['csrf_token']);
    ea_flash_result($ea_admin->applyFeaturePreset($ea_who, (string) ($_POST['feature_preset'] ?? '')));
}

if (isset($_POST['save_agent_settings'])) {
    validateCSRFToken($_POST['csrf_token']);
    $in = ['enabled' => isset($_POST['enabled']) ? 1 : 0];
    foreach (['service_url', 'check_in_interval_s', 'collect_interval_s', 'offline_after_s', 'stale_after_s', 'failure_debounce', 'recovery_debounce', 'retention_days',
        'job_retention_days', 'job_output_max_bytes', 'job_default_timeout_s', 'job_max_timeout_s', 'job_expiry_s', 'job_ack_timeout_s', 'job_max_attempts',
        'enroll_max_ttl_h', 'unmatched_policy', 'checks_json', 'coexistence_policy', 'ca_pem'] as $k) {
        if (isset($_POST[$k])) {
            $in[$k] = is_string($_POST[$k]) ? $_POST[$k] : '';
        }
    }
    ea_flash_result($ea_admin->saveSettings($ea_who, $in));
}

// Software inventory switch and the two history limits (RivetCore 1.0.0-rc.9). RmmAdmin validates and audits; only values that changed are written, so an install on
// the legacy feature defaults (features_json NULL) or the default limits stays that way until an administrator actually changes something here.
if (isset($_POST['save_inventory_settings'])) {
    validateCSRFToken($_POST['csrf_token']);
    $cur = $rmm->readModel()->settingsSummary();
    $in = [];
    $want = !empty($_POST['inventory_software']);
    if ($want !== !empty($cur['features']['inventory_software'])) {
        $features = $cur['features'];
        $features['inventory_software'] = $want;
        $in['features_json'] = $features;
    }
    $stored = is_string($cur['limits_json'] ?? null) && $cur['limits_json'] !== '' ? json_decode($cur['limits_json'], true) : [];
    $stored = is_array($stored) ? $stored : [];
    $changed = false;
    foreach (['check_history_days' => [0, 365], 'software_history_days' => [1, 3650]] as $k => [$lo, $hi]) {
        if (!isset($_POST[$k]) || !is_numeric($_POST[$k])) {
            continue;
        }
        $v = max($lo, min($hi, (int) $_POST[$k]));
        if ($v !== (int) ($cur['limits'][$k] ?? -1)) {
            $stored[$k] = $v;
            $changed = true;
        }
    }
    if ($changed) {
        $in['limits_json'] = $stored;
    }
    if ($in === []) {
        flash_alert('No change to save.', 'info');
        redirect();
    }
    ea_flash_result($ea_admin->saveSettings($ea_who, $in), 'Software inventory settings saved.');
}

// RMM Phase 2 and 3 (RivetCore 1.0.0-rc.10): the three sub-switches (policies, scripts, alerting), the approval and alerting settings, the escalation contact and
// the script/approval limits. RmmAdmin validates and audits the switches and limits; only values that changed are written, so an install on the legacy feature
// defaults (features_json NULL) stays that way until an administrator actually changes something here.
if (isset($_POST['save_automation_settings'])) {
    validateCSRFToken($_POST['csrf_token']);
    $cur = $rmm->readModel()->settingsSummary();
    $in = [];
    $features = $cur['features'];
    $changedFeature = false;
    foreach (['policies', 'scripts', 'alerting'] as $f) {
        $want = !empty($_POST['feature_' . $f]);
        if ($want !== !empty($features[$f])) {
            $features[$f] = $want;
            $changedFeature = true;
        }
    }
    if ($changedFeature) {
        $in['features_json'] = $features;
    }
    $stored = is_string($cur['limits_json'] ?? null) && $cur['limits_json'] !== '' ? json_decode($cur['limits_json'], true) : [];
    $stored = is_array($stored) ? $stored : [];
    $changedLimit = false;
    foreach (['approval_bulk_threshold' => [0, 100000], 'approval_expiry_h' => [1, 720], 'schedule_batch' => [10, 5000], 'bulk_run_max' => [1, 100000]] as $k => [$lo, $hi]) {
        if (!isset($_POST[$k]) || !is_numeric($_POST[$k])) {
            continue;
        }
        $v = max($lo, min($hi, (int) $_POST[$k]));
        if ($v !== (int) ($cur['limits'][$k] ?? -1)) {
            $stored[$k] = $v;
            $changedLimit = true;
        }
    }
    if ($changedLimit) {
        $in['limits_json'] = $stored;
    }
    $messages = [];
    if ($in !== []) {
        $r = $ea_admin->saveSettings($ea_who, $in);
        if (!$r->ok) {
            ea_flash_result($r);
        }
        $messages[] = 'Switches and limits saved.';
    }
    // The two settings RivetIT keeps itself: who besides an administrator may approve, and who is told when an alert has nobody assigned.
    $lvl3 = !empty($_POST['approve_scripts_lvl3']) ? 1 : 0;
    $contactRaw = trim((string) ($_POST['escalation_contact'] ?? ''));
    $contacts = [];
    foreach (preg_split('/[\s,;]+/', $contactRaw) ?: [] as $c) {
        if ($c === '') {
            continue;
        }
        if (ctype_digit($c)) {
            $u = mysqli_fetch_row(mysqli_query($mysqli, 'SELECT user_id FROM users WHERE user_type = 1 AND user_status = 1 AND user_archived_at IS NULL AND user_id = ' . (int) $c));
            if (!$u) {
                flash_alert('The escalation contact "' . nullable_htmlentities($c) . '" is not an active user id. Use an email address or the id of an active user.', 'error');
                redirect();
            }
        } elseif (!filter_var($c, FILTER_VALIDATE_EMAIL)) {
            flash_alert('The escalation contact "' . nullable_htmlentities($c) . '" is neither an email address nor a user id.', 'error');
            redirect();
        }
        $contacts[$c] = $c;
    }
    if (count($contacts) > 10) {
        flash_alert('At most 10 escalation contacts.', 'error');
        redirect();
    }
    $contactSql = mysqli_real_escape_string($mysqli, implode(', ', $contacts));
    $before = mysqli_fetch_assoc(mysqli_query($mysqli, 'SELECT config_rmm_approve_scripts_lvl3 AS a, config_rmm_escalation_contact AS c FROM settings WHERE company_id = 1')) ?: ['a' => 0, 'c' => ''];
    if ((int) $before['a'] !== $lvl3 || (string) $before['c'] !== implode(', ', $contacts)) {
        mysqli_query($mysqli, "UPDATE settings SET config_rmm_approve_scripts_lvl3 = $lvl3, config_rmm_escalation_contact = " . ($contacts === [] ? 'NULL' : "'$contactSql'") . ' WHERE company_id = 1');
        logAction('RMM', 'Settings Changed', "$session_name changed the RMM approval and escalation settings (level 3 script users may approve: " . ($lvl3 ? 'yes' : 'no') . '; escalation contact: ' . ($contacts === [] ? 'none' : implode(', ', $contacts)) . ')');
        $messages[] = 'Approval and escalation settings saved.';
    }
    // Storm control (rmm.admin; Core validates and audits).
    $storm = [];
    foreach (['storm_global_max', 'storm_global_window_s', 'storm_client_max', 'storm_client_window_s'] as $k) {
        if (isset($_POST[$k]) && is_numeric($_POST[$k])) {
            $storm[$k] = (int) $_POST[$k];
        }
    }
    if ($storm !== []) {
        $now = $rmm->alertingActions()->settings($ea_who);
        $curStorm = (array) ($now->data['storm'] ?? []);
        $diff = array_filter($storm, static fn ($v, $k) => ($curStorm[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH);
        if ($diff !== []) {
            $r = $rmm->alertingActions()->updateSettings($ea_who, $diff);
            if (!$r->ok) {
                ea_flash_result($r);
            }
            $messages[] = 'Storm control saved.';
        }
    }
    if ($messages === []) {
        flash_alert('No change to save.', 'info');
        redirect();
    }
    ea_flash_result(ActionResult::ok('Saved.'), implode(' ', $messages));
}

// RMM Phase 4 (RivetCore 1.0.0-rc.20, patch management): the `patching` sub-switch and its seven limits. RmmAdmin::saveSettings() (rmm.admin) validates the ranges and audits; this
// only reads the form. Switching LIVE installs on needs an explicit acknowledgement (the scheduler would otherwise only queue dry runs); only values that changed are written.
if (isset($_POST['save_patch_settings'])) {
    validateCSRFToken($_POST['csrf_token']);
    $cur = $rmm->readModel()->settingsSummary();
    $in = [];
    $features = $cur['features'];
    $wantOn = !empty($_POST['feature_patching']);
    if ($wantOn !== !empty($features['patching'])) {
        $features['patching'] = $wantOn;
        $in['features_json'] = $features;
    }
    $stored = is_string($cur['limits_json'] ?? null) && $cur['limits_json'] !== '' ? json_decode($cur['limits_json'], true) : [];
    $stored = is_array($stored) ? $stored : [];
    $changedLimit = false;
    foreach (['patch_scan_interval_s', 'patch_overdue_days', 'patch_max_installs', 'patch_max_installs_per_client', 'patch_history_days', 'patch_batch'] as $k) {
        if (!isset($_POST[$k]) || !is_string($_POST[$k]) || preg_match('/^\d{1,9}$/', trim($_POST[$k])) !== 1) {
            continue;
        }
        $v = (int) trim($_POST[$k]);
        if ($v !== (int) ($cur['limits'][$k] ?? -1)) {
            $stored[$k] = $v;   // out of range is refused by Core with its own message
            $changedLimit = true;
        }
    }
    $wantLive = !empty($_POST['patch_live_install']) ? 1 : 0;
    $liveChanged = $wantLive !== (int) ($cur['limits']['patch_live_install'] ?? 0);
    if ($liveChanged) {
        if ($wantLive === 1 && empty($_POST['accept_patch_live'])) {
            flash_alert('Tick the acknowledgement to switch live installs on. Nothing was saved.', 'error');
            redirect();
        }
        $stored['patch_live_install'] = $wantLive;
        $changedLimit = true;
    }
    if ($changedLimit) {
        $in['limits_json'] = $stored;
    }
    if ($in === []) {
        flash_alert('No change to save.', 'info');
        redirect();
    }
    $r = $ea_admin->saveSettings($ea_who, $in);
    if ($r->ok) {
        $rmm->syncState();
        if ($liveChanged) {
            logAction('RMM', 'Settings Changed', "$session_name switched patch management live installs " . ($wantLive === 1 ? 'ON' : 'off'));
        }
    }
    ea_flash_result($r, 'Patch management settings saved.' . (isset($in['features_json']) ? ' Patch management is now ' . ($wantOn ? 'on' : 'off') . '.' : ''));
}

// RMM Phase 5 (RivetCore 1.0.0-rc.21, software deployment): the `software_deploy` sub-switch and its five limits. RmmAdmin::saveSettings() (rmm.admin) validates the ranges and audits; this
// only reads the form. Switching LIVE installs on needs an explicit acknowledgement (the scheduler would otherwise only queue dry runs) and is audited by name; only values that changed are written.
if (isset($_POST['save_software_settings'])) {
    validateCSRFToken($_POST['csrf_token']);
    $cur = $rmm->readModel()->settingsSummary();
    $in = [];
    $features = $cur['features'];
    $wantOn = !empty($_POST['feature_software_deploy']);
    if ($wantOn !== !empty($features['software_deploy'])) {
        $features['software_deploy'] = $wantOn;
        $in['features_json'] = $features;
    }
    $stored = is_string($cur['limits_json'] ?? null) && $cur['limits_json'] !== '' ? json_decode($cur['limits_json'], true) : [];
    $stored = is_array($stored) ? $stored : [];
    $changedLimit = false;
    foreach (['software_detect_interval_s', 'software_max_installs', 'software_max_installs_per_client', 'software_deploy_history_days', 'software_batch'] as $k) {
        if (!isset($_POST[$k]) || !is_string($_POST[$k]) || preg_match('/^\d{1,9}$/', trim($_POST[$k])) !== 1) {
            continue;
        }
        $v = (int) trim($_POST[$k]);
        if ($v !== (int) ($cur['limits'][$k] ?? -1)) {
            $stored[$k] = $v;   // out of range is refused by Core with its own message
            $changedLimit = true;
        }
    }
    $wantLive = !empty($_POST['software_live_install']) ? 1 : 0;
    $liveChanged = $wantLive !== (int) ($cur['limits']['software_live_install'] ?? 0);
    if ($liveChanged) {
        if ($wantLive === 1 && empty($_POST['accept_software_live'])) {
            flash_alert('Tick the acknowledgement to switch live software installs on. Nothing was saved.', 'error');
            redirect();
        }
        if ($wantLive === 1 && !$wantOn) {
            flash_alert('Switch software deployment on first. Nothing was saved.', 'error');
            redirect();
        }
        $stored['software_live_install'] = $wantLive;
        $changedLimit = true;
    }
    if ($changedLimit) {
        $in['limits_json'] = $stored;
    }
    if ($in === []) {
        flash_alert('No change to save.', 'info');
        redirect();
    }
    $r = $ea_admin->saveSettings($ea_who, $in);
    if ($r->ok) {
        $rmm->syncState();
        if ($liveChanged) {
            logAction('RMM', 'Settings Changed', "$session_name switched software deployment live installs " . ($wantLive === 1 ? 'ON (acknowledged)' : 'off'));
        }
        if (isset($in['features_json'])) {
            logAction('RMM', 'Settings Changed', "$session_name switched software deployment " . ($wantOn ? 'ON' : 'off'));
        }
    }
    ea_flash_result($r, 'Software deployment settings saved.' . (isset($in['features_json']) ? ' Software deployment is now ' . ($wantOn ? 'on' : 'off') . '.' : ''));
}

// ---------------------------------------------------------------- agent binaries and per-department installers

if (isset($_POST['upload_agent_binary'])) {
    validateCSRFToken($_POST['csrf_token']);
    $f = $_FILES['agent_binary'] ?? null;
    $limit = $rmm->binaryStore()->effectiveUploadLimit();
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($f['tmp_name'] ?? ''))) {
        $code = (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE);
        $why = in_array($code, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? 'The file is larger than the limit of ' . \RivetCore\Rmm\Binaries\BinaryStore::human($limit) . ' (PHP upload_max_filesize ' . ini_get('upload_max_filesize') . ', post_max_size ' . ini_get('post_max_size') . '). Raise them in php.ini or upload with scripts/endpoint_agent_publish.php.'
            : ($code === UPLOAD_ERR_NO_FILE ? 'Choose the agent .exe to upload.' : 'The upload failed (PHP error ' . $code . ').');
        flash_alert(nullable_htmlentities($why), 'error');
        redirect();
    }
    $ring = (string) ($_POST['release_ring'] ?? '');
    ea_flash_result($ea_admin->uploadBinary($ea_who, (string) $f['tmp_name'], trim((string) ($_POST['version'] ?? '')), (string) ($_POST['arch'] ?? ''), [
        'activate' => isset($_POST['activate']),
        'release_ring' => in_array($ring, ['pilot', 'stable'], true) ? $ring : null,
        'rollout_pct' => ea_post_int('rollout_pct', 0, 100, 10),
    ]));
}

/**
 * "Publish agent" (drag and drop): one or two agent executables at once. The architecture is read from each file's own headers (never trusted from
 * the browser or the file name), the version comes from the form or, when left blank, from the file name (rivetit-agent-1.4.0-windows-arm64.exe).
 * Every file goes through the same RmmAdmin::uploadBinary() as the single-file form: header checks, size cap, SHA-256, audit entry.
 */
if (isset($_POST['upload_agent_binaries'])) {
    validateCSRFToken($_POST['csrf_token']);
    $files = [];
    $up = $_FILES['agent_binaries'] ?? null;
    if ($up && is_array($up['name'] ?? null)) {
        foreach (array_keys($up['name']) as $i) {
            $files[] = ['name' => (string) $up['name'][$i], 'tmp' => (string) $up['tmp_name'][$i], 'error' => (int) $up['error'][$i]];
        }
    }
    $files = array_slice(array_values(array_filter($files, static fn (array $f): bool => $f['error'] !== UPLOAD_ERR_NO_FILE)), 0, 4);
    if ($files === []) {
        flash_alert('Choose or drop the agent .exe file(s) to publish.', 'error');
        redirect();
    }
    $limit = $rmm->binaryStore()->effectiveUploadLimit();
    $ring = (string) ($_POST['release_ring'] ?? '');
    $versionIn = trim((string) ($_POST['version'] ?? ''));
    $done = [];
    $failed = [];
    $seen = [];
    foreach ($files as $f) {
        $label = basename($f['name']);
        if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp'])) {
            $failed[] = $label . ': ' . (in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'larger than the limit of ' . \RivetCore\Rmm\Binaries\BinaryStore::human($limit) . ' (PHP upload_max_filesize ' . ini_get('upload_max_filesize') . ', post_max_size ' . ini_get('post_max_size') . ')'
                : 'the upload failed (PHP error ' . $f['error'] . ')');
            continue;
        }
        $det = $rmm->binaryStore()->detect($f['tmp']);
        if (is_string($det) || $det['arch'] === null) {
            $failed[] = $label . ': ' . (is_string($det) ? $det : 'this is not an x64 or ARM64 agent executable');
            continue;
        }
        if (isset($seen[$det['arch']])) {
            $failed[] = $label . ': a second ' . $det['arch'] . ' file in the same upload (publish one file per architecture)';
            continue;
        }
        $seen[$det['arch']] = true;
        $version = $versionIn !== '' ? $versionIn : (preg_match('/(\d{1,5}\.\d{1,5}\.\d{1,5}(?:-(?:rc|alpha|beta|pre|dev)[0-9A-Za-z.]{0,16})?)/i', $label, $m) === 1 ? $m[1] : '');
        if ($version === '') {
            $failed[] = $label . ': the version is not in the file name. Type it in the Version box (for example 1.2.0).';
            continue;
        }
        $r = $ea_admin->uploadBinary($ea_who, $f['tmp'], $version, (string) $det['arch'], [
            'activate' => isset($_POST['activate']),
            'release_ring' => in_array($ring, ['pilot', 'stable'], true) ? $ring : null,
            'rollout_pct' => ea_post_int('rollout_pct', 0, 100, 10),
        ]);
        $r->ok ? $done[] = $label . ' as ' . $version . ' (' . ($det['arch'] === 'arm64' ? 'ARM64' : 'x64') . ')' : $failed[] = $label . ': ' . $r->message;
    }
    $msg = ($done ? 'Published ' . implode(' and ', $done) . (isset($_POST['activate']) ? ', now used for new installers.' : '.') : '') . ($failed ? ($done ? ' ' : '') . 'Not published: ' . implode('; ', $failed) : '');
    flash_alert(nullable_htmlentities($msg), $failed ? ($done ? 'warning' : 'error') : 'success');
    redirect();
}

if (isset($_POST['binary_action'])) {
    validateCSRFToken($_POST['csrf_token']);
    ea_flash_result($ea_admin->binaryAction($ea_who, intval($_POST['binary_id'] ?? 0), (string) $_POST['binary_action'], (string) ($_POST['release_ring'] ?? 'pilot'), ea_post_int('rollout_pct', 0, 100, 10)));
}

/**
 * MSI template (RivetCore 1.0.0-rc.16): upload one Windows Installer package as the template MSI installers are stamped from (RmmAdmin::uploadMsiTemplate: OLE compound
 * file check, size window, not already stamped, platform from the package, audit entry) and activate / deactivate it (RmmAdmin::msiTemplateAction).
 */
if (isset($_POST['upload_msi_template'])) {
    validateCSRFToken($_POST['csrf_token']);
    $f = $_FILES['msi_template'] ?? null;
    $limit = min($rmm->binaryStore()->effectiveUploadLimit(), $rmm->binaryStore()->maxBytes());
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($f['tmp_name'] ?? ''))) {
        $code = (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE);
        $why = in_array($code, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? 'The file is larger than the limit of ' . \RivetCore\Rmm\Binaries\BinaryStore::human($limit) . ' (PHP upload_max_filesize ' . ini_get('upload_max_filesize') . ', post_max_size ' . ini_get('post_max_size') . '). Raise them in php.ini or upload with scripts/endpoint_agent_publish.php --msi.'
            : ($code === UPLOAD_ERR_NO_FILE ? 'Choose the MSI template (.msi) to upload.' : 'The upload failed (PHP error ' . $code . ').');
        flash_alert(nullable_htmlentities($why), 'error');
        redirect();
    }
    $arch = (string) ($_POST['arch'] ?? 'amd64');
    ea_flash_result($ea_admin->uploadMsiTemplate($ea_who, (string) $f['tmp_name'], trim((string) ($_POST['version'] ?? '')), in_array($arch, ['amd64', 'arm64'], true) ? $arch : 'amd64', [
        'activate' => isset($_POST['activate']),
    ]));
}

if (isset($_POST['msi_template_action'])) {
    validateCSRFToken($_POST['csrf_token']);
    ea_flash_result($ea_admin->msiTemplateAction($ea_who, intval($_POST['template_id'] ?? 0), (string) $_POST['msi_template_action']));
}

if (isset($_POST['download_installer']) || isset($_POST['show_deploy_commands'])) {
    validateCSRFToken($_POST['csrf_token']);
    $arch = (string) ($_POST['arch'] ?? 'amd64');
    $format = (string) ($_POST['format'] ?? 'exe');   // RivetCore 1.0.0-rc.16: exe (the default) or msi; anything else is refused before a token exists
    if ($format === '') {
        $format = 'exe';
    }
    if (!in_array($format, ['exe', 'msi'], true)) {
        ea_flash_result(ActionResult::fail(422, 'invalid', 'Choose the installer format: exe or msi.'));
    }
    $args = [intval($_POST['client_id'] ?? 0), intval($_POST['location_id'] ?? 0), (string) ($_POST['ring'] ?? 'stable'), ea_post_int('ttl_hours', 1, 720, 72),
        ea_post_int('max_uses', 1, 5000, 25), trim((string) ($_POST['label'] ?? '')), $arch, ($_POST['install_mesh_agent'] ?? '') === '1', $format];   // last two: also install the MeshCentral agent (rc.12), the installer format (rc.16)
    if (isset($_POST['show_deploy_commands'])) {
        $r = $ea_admin->deploymentCommands($ea_who, ...$args);
        if (!$r->ok) {
            ea_flash_result($r);
        }
        // Shown once, on the next page load, like a plain enrollment token.
        $_SESSION['ea_new_token'] = ['token' => $r->data['token_plain'], 'id' => (int) $r->data['token']['token_id'], 'deploy' => ['arch' => $arch, 'department' => $r->data['department'],
            'expires_at' => $r->data['token']['expires_at'], 'max_uses' => (int) $r->data['token']['max_uses'], 'commands' => $r->data['commands'],
            'mesh' => $args[7], 'format' => $format]];
        flash_alert('Enrollment token created. Copy the commands now: the token is shown only once.');
        redirect();
    }
    $r = $ea_admin->downloadInstaller($ea_who, ...$args);
    if (!$r->ok) {
        ea_flash_result($r);
    }
    (new \RivetCore\Rmm\Http\SapiEmitter())->emit($r->data['download']);   // streamed with exact length after the size and SHA-256 check
    exit;
}

if (isset($_POST['rotate_signing_key'])) {
    validateCSRFToken($_POST['csrf_token']);
    $r = $ea_admin->rotateSigningKey($ea_who);
    flash_alert(nullable_htmlentities($r->message), $r->ok ? 'warning' : 'error');
    redirect();
}

if (isset($_POST['create_enroll_token'])) {
    validateCSRFToken($_POST['csrf_token']);
    $r = $ea_tech->createToken($ea_who, intval($_POST['client_id'] ?? 0), intval($_POST['location_id'] ?? 0), (string) ($_POST['ring'] ?? 'stable'),
        ea_post_int('ttl_hours', 1, 720, 24), ea_post_int('max_uses', 1, 5000, 1), trim((string) ($_POST['label'] ?? '')));
    if ($r->ok) {
        $_SESSION['ea_new_token'] = ['token' => $r->data['token'], 'id' => $r->data['token_id']];   // shown once, on the next page load
    }
    ea_flash_result($r);
}

if (isset($_POST['revoke_enroll_token'])) {
    validateCSRFToken($_POST['csrf_token']);
    $r = $ea_tech->revokeToken($ea_who, intval($_POST['token_id'] ?? 0));
    if ($r->ok) {
        flash_alert('Enrollment token revoked.');
    }
    redirect();
}

if (isset($_POST['device_action'])) {
    validateCSRFToken($_POST['csrf_token']);
    $id = intval($_POST['device_id'] ?? 0);
    $r = null;
    switch ((string) $_POST['device_action']) {
        case 'approve_link':
            $r = $ea_tech->resolvePending($ea_who, $id, 'link', intval($_POST['asset_id'] ?? 0)); break;
        case 'approve_create':
            $r = $ea_tech->resolvePending($ea_who, $id, 'create_asset'); break;
        case 'reject':
            $r = $ea_tech->resolvePending($ea_who, $id, 'reject'); break;
        case 'revoke':
            $r = $ea_tech->revoke($ea_who, $id, 'revoked by ' . $session_name); break;
        case 'rotate':
            $r = $ea_tech->rotateCredential($ea_who, $id); break;
        case 'retire':
            $r = $ea_tech->retire($ea_who, $id); break;
        case 'allow_reenroll':
            $r = $ea_tech->allowReenroll($ea_who, $id); break;
        case 'set_ring':
            $r = $ea_tech->setRing($ea_who, $id, (string) ($_POST['ring'] ?? '')); break;
        case 'clear_update_failures':
            $r = $ea_tech->clearUpdateFailures($ea_who, $id); break;
        case 'transfer':
            $r = $ea_tech->transfer($ea_who, $id, intval($_POST['client_id'] ?? 0), 0); break;
    }
    if ($r === null) {
        flash_alert('Nothing changed.', 'error');
        redirect();
    }
    ea_flash_result($r);
}

if (isset($_POST['add_agent_release'])) {
    validateCSRFToken($_POST['csrf_token']);
    ea_flash_result($ea_admin->addExternalRelease($ea_who, trim((string) ($_POST['version'] ?? '')), trim((string) ($_POST['url'] ?? '')), trim((string) ($_POST['sha256'] ?? '')),
        trim((string) ($_POST['min_version'] ?? '0.0.0')) ?: '0.0.0', (string) ($_POST['ring'] ?? 'stable'), ea_post_int('rollout_pct', 0, 100, 0), trim((string) ($_POST['notes'] ?? ''))));
}

if (isset($_POST['update_agent_release'])) {
    validateCSRFToken($_POST['csrf_token']);
    ea_flash_result($ea_admin->updateRelease($ea_who, intval($_POST['release_id'] ?? 0), ea_post_int('rollout_pct', 0, 100, 0), isset($_POST['active'])));
}

if (isset($_POST['save_mesh_settings']) || isset($_POST['test_mesh']) || isset($_POST['probe_mesh_pin'])) {
    validateCSRFToken($_POST['csrf_token']);
    $in = [];
    foreach (['mesh_url', 'mesh_domain', 'mesh_account_template', 'mesh_policy', 'mesh_token_ttl_s', 'mesh_login_key'] as $k) {
        if (isset($_POST[$k])) {
            $in[$k] = is_string($_POST[$k]) ? $_POST[$k] : '';
        }
    }
    $in['mesh_enabled'] = isset($_POST['mesh_enabled']) ? 1 : 0;
    // RivetCore rc.12: the install settings travel with the form (present only when the card printed them, i.e. the database is updated). Core validates all of them first and
    // writes nothing when any is invalid; what was typed is kept for the next page load. "Read it from the server" saves the rest of the card but NEVER the pin field, and the
    // proposal it gets back is only shown (it is saved when the administrator uses it and presses Save).
    $probing = isset($_POST['probe_mesh_pin']);
    $keep = [];
    // rc.17: the hide values travel only when the card printed them (migration 0026 applied). They are passed on exactly as typed; Core refuses anything but a whole number 0 to 255
    // (the second one may be empty = same as the desktop) and then writes nothing at all.
    if (isset($_POST['mesh_hide_present'])) {
        $in['mesh_hide'] = is_string($_POST['mesh_hide'] ?? null) ? trim($_POST['mesh_hide']) : '';
        $in['mesh_hide_tools'] = is_string($_POST['mesh_hide_tools'] ?? null) ? trim($_POST['mesh_hide_tools']) : '';
        $keep['mesh_hide'] = $in['mesh_hide'];
        $keep['mesh_hide_tools'] = $in['mesh_hide_tools'];
    }
    if (isset($_POST['mesh_install_present'])) {
        $jsonField = static function (string $k) use (&$keep): ?array {
            $raw = is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : '';
            $keep[$k] = $raw;
            if ($raw === '') {
                return [];
            }
            $d = json_decode($raw, true);

            return is_array($d) && ($d === [] || !array_is_list($d)) ? $d : null;
        };
        // rc.15: the device group id is sent to Core exactly as typed or pasted (only surrounding whitespace is trimmed): the bare 64-character meshid, the whole MeshCentral
        // download address, mesh//... or hex. Core parses all of them, refuses the rest with one message and warns about a pasted address for another host. No hex check here.
        $in['mesh_group_id'] = is_string($_POST['mesh_group_id'] ?? null) ? trim($_POST['mesh_group_id']) : '';
        $keep['mesh_group_id'] = $in['mesh_group_id'];
        $keep['mesh_server_cert_pin'] = is_string($_POST['mesh_server_cert_pin'] ?? null) ? trim($_POST['mesh_server_cert_pin']) : '';
        if (!$probing) {
            $in['mesh_server_cert_pin'] = $keep['mesh_server_cert_pin'];
        }
        $in['mesh_auto_map_overwrite'] = isset($_POST['mesh_auto_map_overwrite']);
        $ids = $jsonField('mesh_agent_ids');
        $args = $jsonField('mesh_install_args');
        if ($ids === null || $args === null) {
            $_SESSION['ea_mesh_keep'] = $keep;
            flash_alert('The agent id and install argument corrections must each be a JSON object (or empty). Nothing was saved.', 'error');
            ea_scale_back('mesh');
        }
        $in['mesh_agent_ids'] = $ids;
        $in['mesh_install_args'] = $args;
    }
    if ($probing) {
        $in['mesh_probe_pin'] = true;
    }
    $r = $ea_admin->saveMesh($ea_who, $in, isset($_POST['test_mesh']));
    $rmm->syncState();   // the remote sub-switch follows mesh_enabled
    if (!$r->ok && $keep !== []) {
        $_SESSION['ea_mesh_keep'] = $keep;
    }
    // rc.15: Core's `warnings` (a pasted address for another host than the saved MeshCentral address) are shown with the result; the save itself succeeded.
    $meshWarn = is_array($r->data['warnings'] ?? null) ? array_values(array_filter($r->data['warnings'], 'is_string')) : [];
    $meshWarnText = $meshWarn === [] ? '' : ' Warning: ' . implode(' ', $meshWarn);
    if ($probing) {
        $_SESSION['ea_mesh_pin_probe'] = is_array($r->data['pin_probe'] ?? null) ? $r->data['pin_probe'] : null;
        if ($r->ok) {
            $pp = $_SESSION['ea_mesh_pin_probe'];
            if (is_array($pp) && !empty($pp['ok'])) {
                $msg = !empty($pp['publicly_trusted'])
                    ? 'Read the certificate from the Mesh server. It is publicly trusted, so no pin is needed: leave the pin empty.'
                    : 'Read the certificate from the Mesh server. The proposed pin is shown under the pin field: compare it with the hash MeshCentral shows, then use it and press Save.';
            } else {
                $msg = 'The settings were saved, but the certificate could not be read: ' . (is_array($pp) ? (string) ($pp['error'] ?? 'unknown error') : 'unknown error');
            }
            flash_alert(nullable_htmlentities($msg . $meshWarnText), $meshWarn !== [] || !is_array($pp) || empty($pp['ok']) ? 'warning' : 'success');
            ea_scale_back('mesh');
        }
    }
    flash_alert(nullable_htmlentities($r->message . $meshWarnText), !$r->ok ? 'error' : ($meshWarn !== [] ? 'warning' : 'success'));
    redirect();
}

// ================================================================================================================================================================
// RivetCore 1.0.0-rc.11: remote tools, file transfer, log collection, scale settings, partitioning and the external metric store. Core validates, authorizes and
// audits (RemoteActions::updateSettings, RmmSettings::update through RmmAdmin::saveSettings); this file only reads the form, shows Core's refusal text in the card
// it belongs to (an aria-live region, kept after the redirect) and keeps what the person typed when something was refused. Only values that changed are written.
// ================================================================================================================================================================

require_once dirname(__DIR__, 2) . '/includes/rmm_scale_admin.php';

use RivetCore\Rmm\Authz\RmmAbility;

/** Go back to a card on the settings page. */
function ea_scale_back(string $anchor): void
{
    redirect('settings_endpoint_agent.php#' . $anchor);
}

/** Lines of a textarea: trimmed, empty ones dropped. @return list<string> */
function ea_scale_lines($v): array
{
    $out = [];
    foreach (preg_split('/\R/', is_string($v) ? $v : '') ?: [] as $l) {
        $l = trim($l);
        if ($l !== '') {
            $out[] = $l;
        }
    }

    return $out;
}

/** A whole number from the form, or null (and an error in $errors) when it is not one. Nothing is clamped: Core's range message is what the person should see. */
function ea_scale_whole(string $key, string $label, array &$errors, ?int $default = null): ?int
{
    $v = $_POST[$key] ?? null;
    if ($v === null || (is_string($v) && trim($v) === '')) {
        return $default;
    }
    if (is_string($v) && preg_match('/^\s*-?\d{1,12}\s*$/', $v) === 1) {
        return (int) trim($v);
    }
    $errors[] = $label . ' must be a whole number.';

    return null;
}

/** A number-plus-unit field (name and name_unit) as a whole number of base units, or null with an error. @param array<string,int> $units */
function ea_scale_unit_whole(string $key, string $label, array $units, array &$errors): ?int
{
    $v = $_POST[$key] ?? null;
    if (!is_string($v) || trim($v) === '') {
        return null;
    }
    $unit = (string) ($_POST[$key . '_unit'] ?? '');
    if (!isset($units[$unit]) || preg_match('/^\s*\d{1,9}(\.\d{1,3})?\s*$/', $v) !== 1) {
        $errors[] = $label . ' must be a number (for example 50) with a unit.';

        return null;
    }

    return (int) round((float) trim($v) * $units[$unit]);
}

/** The administrator check every write below makes on the server (Core repeats it for its own operations). */
function ea_scale_require_admin($rmm, int $userId, string $anchor): void
{
    $denied = $rmm->authorizer()->check($userId, RmmAbility::ADMIN, 0, false);
    if ($denied !== null) {
        rivetRmmScaleMsg($anchor, 'error', $denied);
        ea_scale_back($anchor);
    }
}

/** The limits an administrator stored (without the defaults), as the array RmmSettings::update() takes back. @return array<string,int> */
function ea_scale_stored_limits($rmm): array
{
    $raw = $rmm->settings()->get(true)['limits_json'] ?? null;
    $d = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];

    return is_array($d) ? $d : [];
}

// ---------------------------------------------------------------- Remote tools

if (isset($_POST['save_remote_settings'])) {
    validateCSRFToken($_POST['csrf_token']);
    ea_scale_require_admin($rmm, (int) $session_user_id, 'remote-tools');
    $rmm->settings()->refresh();
    $msgs = [];
    $want = !empty($_POST['feature_remote_tools']);
    if ($want !== $rmm->settings()->features()['remote_tools']) {
        $r = $rmm->remoteActions()->updateSettings($ea_who, ['features' => ['remote_tools' => $want]]);
        if (!$r->ok) {
            rivetRmmScaleMsg('remote-tools', 'error', $r->message);
            ea_scale_back('remote-tools');
        }
        $msgs[] = 'Remote tools switched ' . ($want ? 'on.' : 'off.');
    }
    $lvl3 = !empty($_POST['remote_manage_lvl3']) ? 1 : 0;
    $beforeRow = mysqli_fetch_assoc(mysqli_query($mysqli, 'SELECT config_rmm_remote_manage_lvl3 AS v FROM settings WHERE company_id = 1')) ?: ['v' => 0];
    if ((int) $beforeRow['v'] !== $lvl3) {
        mysqli_query($mysqli, "UPDATE settings SET config_rmm_remote_manage_lvl3 = $lvl3 WHERE company_id = 1");
        rivetRmmForgetAccess();
        logAction('RMM', 'Settings Changed', "$session_name changed the remote tools setting (level 3 script users with remote access may use them: " . ($lvl3 ? 'yes' : 'no') . ')');
        $msgs[] = 'Level 3 setting saved.';
    }
    $rmm->syncState();
    rivetRmmScaleMsg('remote-tools', $msgs === [] ? 'info' : 'success', $msgs === [] ? 'No change to save.' : implode(' ', $msgs));
    ea_scale_back('remote-tools');
}

// ---------------------------------------------------------------- File transfer

if (isset($_POST['save_filetransfer_settings'])) {
    validateCSRFToken($_POST['csrf_token']);
    ea_scale_require_admin($rmm, (int) $session_user_id, 'file-transfer');
    $rmm->settings()->refresh();
    $rs = $rmm->remoteSettings();
    $rs->refresh();
    $errors = [];
    $in = [];
    $old = ['allow_linux' => (string) ($_POST['allow_linux'] ?? ''), 'allow_windows' => (string) ($_POST['allow_windows'] ?? ''), 'feature_filetransfer' => !empty($_POST['feature_filetransfer']) ? 1 : 0,
        'transfer_max_bytes' => $_POST['transfer_max_bytes'] ?? '', 'transfer_max_bytes_unit' => $_POST['transfer_max_bytes_unit'] ?? '', 'transfer_ttl_s' => $_POST['transfer_ttl_s'] ?? '', 'transfer_ttl_s_unit' => $_POST['transfer_ttl_s_unit'] ?? '',
        'transfer_store_max_bytes' => $_POST['transfer_store_max_bytes'] ?? '', 'transfer_store_max_bytes_unit' => $_POST['transfer_store_max_bytes_unit'] ?? ''];
    $want = !empty($_POST['feature_filetransfer']);
    if ($want !== $rmm->settings()->features()['filetransfer']) {
        $in['features'] = ['filetransfer' => $want];
        if ($want) {
            $in['accept_filetransfer_risk'] = !empty($_POST['accept_filetransfer_risk']);
        }
    }
    $allow = ['linux' => ea_scale_lines($_POST['allow_linux'] ?? ''), 'windows' => ea_scale_lines($_POST['allow_windows'] ?? '')];
    if ($allow !== $rs->allowRoots()) {
        $in['transfer_allow'] = $allow;
    }
    $limits = [];
    $bytes = ['KB' => 1024, 'MB' => 1048576, 'GB' => 1073741824];
    foreach (['transfer_max_bytes' => ['The largest file', $bytes], 'transfer_ttl_s' => ['How long a transfer is kept', ['minutes' => 60, 'hours' => 3600, 'days' => 86400]],
        'transfer_store_max_bytes' => ['The space for stored transfers', $bytes]] as $k => [$label, $units]) {
        $v = ea_scale_unit_whole($k, $label, $units, $errors);
        if ($v !== null && $v !== $rs->limits()[$k]) {
            $limits[$k] = $v;
        }
    }
    if ($limits !== []) {
        $in['limits'] = $limits;
    }
    if ($errors !== []) {
        rivetRmmScaleMsg('file-transfer', 'error', implode(' ', $errors), $old);
        ea_scale_back('file-transfer');
    }
    if ($in === []) {
        rivetRmmScaleMsg('file-transfer', 'info', 'No change to save.');
        ea_scale_back('file-transfer');
    }
    $r = $rmm->remoteActions()->updateSettings($ea_who, $in);
    $rmm->syncState();
    if (!$r->ok) {
        rivetRmmScaleMsg('file-transfer', 'error', $r->message, $old);
    } else {
        $bits = [];
        if (isset($in['features'])) {
            $bits[] = 'File transfer switched ' . ($want ? 'on' : 'off');
        }
        if (isset($in['transfer_allow'])) {
            $bits[] = 'allowed folders saved';
        }
        if (isset($in['limits'])) {
            $bits[] = 'limits saved';
        }
        rivetRmmScaleMsg('file-transfer', 'success', ucfirst(implode(', ', $bits)) . '.');
    }
    ea_scale_back('file-transfer');
}

if (isset($_POST['create_transfer_folder'])) {
    validateCSRFToken($_POST['csrf_token']);
    ea_scale_require_admin($rmm, (int) $session_user_id, 'file-transfer');
    $r = rivetRmmTransferDirCreate();
    logAction('RMM', 'Settings Changed', "$session_name asked to create the file transfer folder: " . ($r['ok'] ? ($r['created'] ? 'created' : 'already there') : 'refused'));
    rivetRmmScaleMsg('file-transfer', $r['ok'] ? 'success' : 'error', $r['message']);
    ea_scale_back('file-transfer');
}

// ---------------------------------------------------------------- Log collection

if (isset($_POST['save_logs_settings'])) {
    validateCSRFToken($_POST['csrf_token']);
    ea_scale_require_admin($rmm, (int) $session_user_id, 'log-collection');
    $rmm->settings()->refresh();
    $rs = $rmm->remoteSettings();
    $rs->refresh();
    $errors = [];
    $in = [];
    $win = [];
    $lin = [];
    $winRows = [];
    $linRows = [];
    foreach (is_array($_POST['log_win'] ?? null) ? $_POST['log_win'] : [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $ch = trim((string) ($row['channel'] ?? ''));
        $levels = array_values(array_filter(array_map('strval', is_array($row['levels'] ?? null) ? $row['levels'] : [])));
        $ids = [];
        foreach (preg_split('/[\s,;]+/', (string) ($row['event_ids'] ?? '')) ?: [] as $t) {
            if ($t !== '') {
                $ids[] = ctype_digit($t) && strlen($t) < 8 ? (int) $t : $t;
            }
        }
        $contains = ea_scale_lines($row['contains'] ?? '');
        $winRows[] = ['channel' => $ch, 'levels' => $levels, 'event_ids' => (string) ($row['event_ids'] ?? ''), 'contains' => (string) ($row['contains'] ?? '')];
        if ($ch === '') {
            continue;
        }
        $win[] = ['kind' => 'eventlog', 'channel' => $ch, 'levels' => $levels === [] ? ['critical', 'error', 'warning'] : $levels, 'event_ids' => $ids, 'contains' => $contains];
    }
    foreach (is_array($_POST['log_lin'] ?? null) ? $_POST['log_lin'] : [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $units = preg_split('/[\s,;]+/', trim((string) ($row['units'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $idents = preg_split('/[\s,;]+/', trim((string) ($row['identifiers'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $contains = ea_scale_lines($row['contains'] ?? '');
        $min = (string) ($row['min_level'] ?? 'warning');
        $linRows[] = ['units' => (string) ($row['units'] ?? ''), 'identifiers' => (string) ($row['identifiers'] ?? ''), 'min_level' => $min, 'contains' => (string) ($row['contains'] ?? ''), 'keep' => !empty($row['keep']) ? 1 : 0];
        if ($units === [] && $idents === [] && $contains === [] && empty($row['keep'])) {
            continue;
        }
        $lin[] = ['kind' => 'journal', 'units' => $units, 'identifiers' => $idents, 'min_level' => $min, 'contains' => $contains];
    }
    $old = ['feature_logs' => !empty($_POST['feature_logs']) ? 1 : 0, 'win' => $winRows, 'lin' => $linRows];
    foreach (['logs_retention_days', 'logs_device_max_rows', 'logs_fleet_max_rows', 'logs_rate_per_min', 'logs_interval_s'] as $k) {
        $old[$k] = $_POST[$k] ?? '';
    }
    $want = !empty($_POST['feature_logs']);
    if ($want !== $rmm->settings()->features()['logs']) {
        $in['features'] = ['logs' => $want];
        if ($want) {
            $in['accept_logs_storage'] = !empty($_POST['accept_logs_storage']);
        }
    }
    $sources = ['linux' => $lin, 'windows' => $win];
    $curSources = ['linux' => $rs->logSources('linux'), 'windows' => $rs->logSources('windows')];
    if (json_encode($sources) !== json_encode($curSources)) {
        $in['logs_collect'] = $sources;
    }
    $limits = [];
    $labels = ['logs_retention_days' => 'Keep entries for (days)', 'logs_device_max_rows' => 'Entries kept per device', 'logs_fleet_max_rows' => 'Entries kept for the whole fleet',
        'logs_rate_per_min' => 'Entries one device may send per minute', 'logs_interval_s' => 'How often a device sends (seconds)'];
    foreach ($labels as $k => $label) {
        $v = ea_scale_whole($k, $label, $errors);
        if ($v !== null && $v !== $rs->limits()[$k]) {
            $limits[$k] = $v;
        }
    }
    if ($limits !== []) {
        $in['limits'] = $limits;
    }
    if ($errors !== []) {
        rivetRmmScaleMsg('log-collection', 'error', implode(' ', $errors), $old);
        ea_scale_back('log-collection');
    }
    if ($in === []) {
        rivetRmmScaleMsg('log-collection', 'info', 'No change to save.');
        ea_scale_back('log-collection');
    }
    $r = $rmm->remoteActions()->updateSettings($ea_who, $in);
    $rmm->syncState();
    if (!$r->ok) {
        rivetRmmScaleMsg('log-collection', 'error', $r->message, $old);
    } else {
        $bits = [];
        if (isset($in['features'])) {
            $bits[] = 'Log collection switched ' . ($want ? 'on' : 'off');
        }
        if (isset($in['logs_collect'])) {
            $bits[] = 'sources saved';
        }
        if (isset($in['limits'])) {
            $bits[] = 'limits saved';
        }
        rivetRmmScaleMsg('log-collection', 'success', ucfirst(implode(', ', $bits)) . '.');
    }
    ea_scale_back('log-collection');
}

// ---------------------------------------------------------------- Scale settings (cache, ingest, herd control, check touch, partition shape)

if (isset($_POST['save_scale_settings'])) {
    validateCSRFToken($_POST['csrf_token']);
    ea_scale_require_admin($rmm, (int) $session_user_id, 'scale');
    $rmm->settings()->refresh();
    $cur = $rmm->settings()->limits();
    $errors = [];
    $want = [];
    $labels = ['cache_fresh_s' => 'Cache fresh time', 'cache_stale_s' => 'Cache extra stale time', 'ingest_max_running' => 'Most ingest jobs running at once', 'ingest_batch' => 'Ingest jobs per worker pass',
        'check_touch_s' => 'Check "last seen" refresh', 'partition_span_days' => 'Days per partition', 'partition_ahead' => 'Partitions created ahead',
        'warm_start_pct' => 'Warm start share', 'warm_quiet_s' => 'Warm start quiet gap', 'warm_start_s' => 'Warm start length', 'client_bucket_burst' => 'Department burst', 'client_bucket_per_min' => 'Department check-ins per minute'];
    $old = [];
    foreach ($labels as $k => $label) {
        $old[$k] = $_POST[$k] ?? '';
        $v = ea_scale_whole($k, $label, $errors);
        if ($v !== null) {
            $want[$k] = $v;
        }
    }
    $old['warm_on'] = !empty($_POST['warm_on']) ? 1 : 0;
    $old['bucket_on'] = !empty($_POST['bucket_on']) ? 1 : 0;
    if (isset($_POST['warm_on']) || isset($_POST['herd_form'])) {
        if (!$old['warm_on']) {
            $want['warm_start_s'] = 0;
            unset($want['warm_start_pct'], $want['warm_quiet_s']);
        } elseif (($want['warm_start_s'] ?? 0) <= 0) {
            $want['warm_start_s'] = 600;
        }
        if (!$old['bucket_on']) {
            $want['client_bucket_burst'] = 0;
            $want['client_bucket_per_min'] = 0;
        } else {
            $sg = rivetRmmScaleBucketSuggestion($mysqli, (int) ($rmm->settings()->get()['check_in_interval_s'] ?? 300));
            if (($want['client_bucket_per_min'] ?? 0) <= 0) {
                $want['client_bucket_per_min'] = $sg['per_min'];
            }
            if (($want['client_bucket_burst'] ?? 0) <= 0) {
                $want['client_bucket_burst'] = $sg['burst'];
            }
        }
    }
    if ($errors !== []) {
        rivetRmmScaleMsg('scale', 'error', implode(' ', $errors), $old);
        ea_scale_back('scale');
    }
    $changed = array_filter($want, static fn ($v, $k) => ($cur[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH);
    if ($changed === []) {
        rivetRmmScaleMsg('scale', 'info', 'No change to save.');
        ea_scale_back('scale');
    }
    $r = $ea_admin->saveSettings($ea_who, ['limits_json' => array_merge(ea_scale_stored_limits($rmm), $changed)]);
    if (!$r->ok) {
        rivetRmmScaleMsg('scale', 'error', $r->message, $old);
        ea_scale_back('scale');
    }
    $rmm->syncState();
    logAction('RMM', 'Settings Changed', "$session_name changed the RMM scale settings (" . implode(', ', array_map(static fn ($k, $v) => "$k=$v", array_keys($changed), $changed)) . ')');
    rivetRmmScaleMsg('scale', 'success', 'Scale settings saved (' . count($changed) . ' value' . (count($changed) === 1 ? '' : 's') . ' changed).');
    ea_scale_back('scale');
}

// ---------------------------------------------------------------- Partitioning

if (isset($_POST['partition_probe'])) {
    validateCSRFToken($_POST['csrf_token']);
    ea_scale_require_admin($rmm, (int) $session_user_id, 'partitioning');
    $probe = $rmm->partitions()->probe();   // really creates, partitions and drops a one-column scratch table; nothing else is touched
    $w = rivetRmmScaleProbeWords($probe);
    rivetRmmScaleMsg('partitioning', $w['can'] || !empty($w['history']['partitioned']) ? 'success' : 'warning', $w['summary']);
    ea_scale_back('partitioning');
}

if (isset($_POST['partition_enable'])) {
    validateCSRFToken($_POST['csrf_token']);
    ea_scale_require_admin($rmm, (int) $session_user_id, 'partitioning');
    if (empty($_POST['confirm_partition'])) {
        rivetRmmScaleMsg('partitioning', 'error', 'Tick the box to confirm that you understand the table is copied once and writers wait while it is. Nothing was changed.');
        ea_scale_back('partitioning');
    }
    $probe = $rmm->partitions()->probe();   // always asked again: the answer on the screen may be old
    $w = rivetRmmScaleProbeWords($probe);
    if (!empty($w['history']['partitioned'])) {
        rivetRmmScaleMsg('partitioning', 'info', 'The check-history table is already partitioned.');
        ea_scale_back('partitioning');
    }
    if (!$w['can']) {
        rivetRmmScaleMsg('partitioning', 'error', 'Not enabled. ' . $w['summary']);
        ea_scale_back('partitioning');
    }
    @set_time_limit(0);
    ignore_user_abort(true);
    $lim = $rmm->settings()->limits();
    $c = $rmm->partitions()->convert('endpoint_agent_check_history', (int) $lim['partition_span_days'], (int) $lim['partition_ahead']);
    if (!$c['ok']) {
        logAction('RMM', 'Settings Changed', "$session_name tried to enable table partitioning: failed");
        rivetRmmScaleMsg('partitioning', 'error', 'The table could not be converted: ' . $c['message'] . ' Nothing was switched on.');
        ea_scale_back('partitioning');
    }
    $rmm->partitions()->probe();   // so the answer kept for the page says "already done" instead of the one from before the copy
    $r = $ea_admin->saveSettings($ea_who, ['limits_json' => array_merge(ea_scale_stored_limits($rmm), ['partitioning' => 1])]);
    logAction('RMM', 'Settings Changed', "$session_name enabled table partitioning for the endpoint agent check history (" . $c['message'] . ')');
    rivetRmmScaleMsg('partitioning', $r->ok ? 'success' : 'warning', 'Partitioning is on. ' . $c['message'] . ($r->ok ? '' : ' The setting itself was not saved: ' . $r->message));
    ea_scale_back('partitioning');
}

if (isset($_POST['partition_disable'])) {
    validateCSRFToken($_POST['csrf_token']);
    ea_scale_require_admin($rmm, (int) $session_user_id, 'partitioning');
    $r = $ea_admin->saveSettings($ea_who, ['limits_json' => array_merge(ea_scale_stored_limits($rmm), ['partitioning' => 0])]);
    logAction('RMM', 'Settings Changed', "$session_name turned off automatic table partitioning");
    rivetRmmScaleMsg('partitioning', $r->ok ? 'success' : 'error', $r->ok ? 'Automatic partitioning is off. Tables that are already partitioned stay partitioned and keep being maintained; see the note below to convert one back.' : $r->message);
    ea_scale_back('partitioning');
}

// ---------------------------------------------------------------- External metric store

if (isset($_POST['save_extstore'])) {
    validateCSRFToken($_POST['csrf_token']);
    ea_scale_require_admin($rmm, (int) $session_user_id, 'ext-store');
    $flags = rivetRmmExtStoreFlags($mysqli);
    $enabled = !empty($_POST['extstore_enabled']);
    $new = [];
    $errors = [];
    foreach (['write_url' => ['write', 'write address'], 'read_url' => ['read', 'read address']] as $f => [$flag, $label]) {
        $v = trim((string) ($_POST['extstore_' . $f] ?? ''));
        if (!empty($_POST['extstore_clear_' . $f])) {
            $new[$f] = null;
        } elseif ($v !== '') {
            $why = rivetRmmExtStoreUrlProblem($mysqli, $v);
            if ($why !== null) {
                $errors[] = 'The ' . $label . ' is not accepted: ' . $why;
            } else {
                $new[$f] = $v;
            }
        }
    }
    $tok = (string) ($_POST['extstore_token'] ?? '');
    if (!empty($_POST['extstore_clear_token'])) {
        $new['token'] = null;
    } elseif ($tok !== '') {
        if (strlen($tok) > 500 || preg_match('/[\x00-\x1f\x7f]/', $tok) === 1) {
            $errors[] = 'The token is too long or contains control characters.';
        } else {
            $new['token'] = $tok;
        }
    }
    $hasWrite = array_key_exists('write_url', $new) ? $new['write_url'] !== null : $flags['write'];
    if ($enabled && !$hasWrite && $errors === []) {
        $errors[] = 'The external store needs a write address before it can be switched on.';
    }
    if ($errors !== []) {
        rivetRmmScaleMsg('ext-store', 'error', implode(' ', $errors));
        ea_scale_back('ext-store');
    }
    $sets = ['config_rmm_extstore_enabled = ' . ($enabled ? 1 : 0)];
    foreach (['write_url' => 'config_rmm_extstore_write_url', 'read_url' => 'config_rmm_extstore_read_url', 'token' => 'config_rmm_extstore_token'] as $f => $col) {
        if (array_key_exists($f, $new)) {
            $sets[] = "$col = " . ($new[$f] === null ? 'NULL' : "'" . mysqli_real_escape_string($mysqli, encryptSetting($new[$f])) . "'");
        }
    }
    $changed = $enabled !== $flags['enabled'] || count($sets) > 1;
    if (!$changed) {
        rivetRmmScaleMsg('ext-store', 'info', 'No change to save.');
        ea_scale_back('ext-store');
    }
    mysqli_query($mysqli, 'UPDATE settings SET ' . implode(', ', $sets) . ' WHERE company_id = 1');
    $what = [];
    foreach (['write_url' => 'write address', 'read_url' => 'read address', 'token' => 'token'] as $f => $label) {
        if (array_key_exists($f, $new)) {
            $what[] = $label . ($new[$f] === null ? ' removed' : ' replaced');
        }
    }
    logAction('RMM', 'Settings Changed', "$session_name changed the external metric store settings (" . ($enabled ? 'on' : 'off') . ($what ? '; ' . implode(', ', $what) : '') . '; values are not logged)');
    rivetRmmScaleMsg('ext-store', 'success', 'External metric store settings saved (' . ($enabled ? 'switched on' : 'switched off') . '). Run "Test connection" to check them.');
    ea_scale_back('ext-store');
}

if (isset($_POST['test_extstore'])) {
    $json = stripos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;
    $okToken = is_string($_POST['csrf_token'] ?? null) && hash_equals($_POST['csrf_token'], (string) $_SESSION['csrf_token']);
    $denied = $okToken ? $rmm->authorizer()->check((int) $session_user_id, RmmAbility::ADMIN, 0, false) : 'The page security token is no longer valid. Reload the page and try again.';
    if ($denied !== null) {
        if ($json) {
            http_response_code($okToken ? 403 : 400);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'status' => 'denied', 'message' => $denied]);
            exit;
        }
        $okToken ? ea_scale_require_admin($rmm, (int) $session_user_id, 'ext-store') : validateCSRFToken('');
    }
    // What is typed is tested; what is left empty falls back to the saved value (which is never sent to the browser).
    $saved = rivetRmmExtStoreStored($mysqli);
    $w = trim((string) ($_POST['extstore_write_url'] ?? ''));
    $rd = trim((string) ($_POST['extstore_read_url'] ?? ''));
    $tk = (string) ($_POST['extstore_token'] ?? '');
    $res = rivetRmmExtStoreTest($mysqli, $w !== '' ? $w : $saved['write'], $rd !== '' ? $rd : ($w !== '' ? '' : $saved['read']), $tk !== '' ? $tk : $saved['token']);
    logAction('RMM', 'Settings Changed', "$session_name tested the external metric store connection: " . $res['status']);
    if ($json) {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode($res);
        exit;
    }
    rivetRmmScaleMsg('ext-store', $res['ok'] ? 'success' : 'error', $res['message']);
    ea_scale_back('ext-store');
}
