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
    $rmm->syncState();
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

if (isset($_POST['binary_action'])) {
    validateCSRFToken($_POST['csrf_token']);
    ea_flash_result($ea_admin->binaryAction($ea_who, intval($_POST['binary_id'] ?? 0), (string) $_POST['binary_action'], (string) ($_POST['release_ring'] ?? 'pilot'), ea_post_int('rollout_pct', 0, 100, 10)));
}

if (isset($_POST['download_installer']) || isset($_POST['show_deploy_commands'])) {
    validateCSRFToken($_POST['csrf_token']);
    $arch = (string) ($_POST['arch'] ?? 'amd64');
    $args = [intval($_POST['client_id'] ?? 0), intval($_POST['location_id'] ?? 0), (string) ($_POST['ring'] ?? 'stable'), ea_post_int('ttl_hours', 1, 720, 72),
        ea_post_int('max_uses', 1, 5000, 25), trim((string) ($_POST['label'] ?? '')), $arch];
    if (isset($_POST['show_deploy_commands'])) {
        $r = $ea_admin->deploymentCommands($ea_who, ...$args);
        if (!$r->ok) {
            ea_flash_result($r);
        }
        // Shown once, on the next page load, like a plain enrollment token.
        $_SESSION['ea_new_token'] = ['token' => $r->data['token_plain'], 'id' => (int) $r->data['token']['token_id'], 'deploy' => ['arch' => $arch, 'department' => $r->data['department'],
            'expires_at' => $r->data['token']['expires_at'], 'max_uses' => (int) $r->data['token']['max_uses'], 'commands' => $r->data['commands']]];
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

if (isset($_POST['save_mesh_settings']) || isset($_POST['test_mesh'])) {
    validateCSRFToken($_POST['csrf_token']);
    $in = [];
    foreach (['mesh_url', 'mesh_domain', 'mesh_account_template', 'mesh_policy', 'mesh_token_ttl_s', 'mesh_login_key'] as $k) {
        if (isset($_POST[$k])) {
            $in[$k] = is_string($_POST[$k]) ? $_POST[$k] : '';
        }
    }
    $in['mesh_enabled'] = isset($_POST['mesh_enabled']) ? 1 : 0;
    $r = $ea_admin->saveMesh($ea_who, $in, isset($_POST['test_mesh']));
    $rmm->syncState();   // the remote sub-switch follows mesh_enabled
    ea_flash_result($r);
}
