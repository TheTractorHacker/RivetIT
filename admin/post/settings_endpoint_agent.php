<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

use ITFlow\EndpointAgent\Config;
use ITFlow\EndpointAgent\Db;
use ITFlow\EndpointAgent\Devices;
use ITFlow\EndpointAgent\Enrollment;
use ITFlow\EndpointAgent\Mesh;
use ITFlow\EndpointAgent\Updates;

mysqli_report(MYSQLI_REPORT_OFF);

/** Whole-number field clamped to a range. */
function ea_post_int(string $k, int $min, int $max, int $default): int
{
    $v = $_POST[$k] ?? null;
    return is_numeric($v) ? max($min, min($max, (int) $v)) : $default;
}

if (isset($_POST['save_agent_settings'])) {
    validateCSRFToken($_POST['csrf_token']);
    $enable = isset($_POST['enabled']) ? 1 : 0;
    $url = trim((string) ($_POST['service_url'] ?? ''));
    if ($url !== '') {
        $p = parse_url($url);
        $secure = ($p['scheme'] ?? '') === 'https' || (defined('EA_ALLOW_INSECURE_HTTP') && EA_ALLOW_INSECURE_HTTP === true && ($p['scheme'] ?? '') === 'http');
        if (!$p || !$secure || empty($p['host']) || isset($p['user']) || strlen($url) > 500) {
            flash_alert('The service URL must be an https:// address that agents can reach.', 'error');
            redirect();
        }
    }
    $checksJson = trim((string) ($_POST['checks_json'] ?? ''));
    $checksOut = '';
    if ($checksJson !== '') {
        [$list, $err] = Config::validateChecks($checksJson);
        if ($err !== null) {
            flash_alert(nullable_htmlentities($err), 'error');
            redirect();
        }
        $checksOut = json_encode($list);
    }
    $policy = in_array($_POST['unmatched_policy'] ?? '', ['approval', 'auto_create'], true) ? $_POST['unmatched_policy'] : 'approval';
    if ($enable) {
        Config::enable();
    }
    Config::set([
        'enabled' => $enable,
        'service_url' => $url,
        'check_in_interval_s' => ea_post_int('check_in_interval_s', 30, 3600, 300),
        'collect_interval_s' => ea_post_int('collect_interval_s', 10, 3600, 60),
        'offline_after_s' => ea_post_int('offline_after_s', 60, 86400, 900),
        'stale_after_s' => ea_post_int('stale_after_s', 3600, 31536000, 604800),
        'failure_debounce' => ea_post_int('failure_debounce', 1, 20, 3),
        'recovery_debounce' => ea_post_int('recovery_debounce', 1, 20, 2),
        'retention_days' => ea_post_int('retention_days', 1, 400, 30),
        'job_retention_days' => ea_post_int('job_retention_days', 1, 3650, 180),
        'job_output_max_bytes' => ea_post_int('job_output_max_bytes', 1024, 200000, 65536),
        'job_default_timeout_s' => ea_post_int('job_default_timeout_s', 5, 3600, 300),
        'job_max_timeout_s' => ea_post_int('job_max_timeout_s', 30, 86400, 3600),
        'job_expiry_s' => ea_post_int('job_expiry_s', 60, 604800, 3600),
        'job_ack_timeout_s' => ea_post_int('job_ack_timeout_s', 30, 3600, 120),
        'job_max_attempts' => ea_post_int('job_max_attempts', 1, 10, 3),
        'enroll_max_ttl_h' => ea_post_int('enroll_max_ttl_h', 1, 720, 72),
        'unmatched_policy' => $policy,
        'checks_json' => $checksOut === '' ? null : $checksOut,
        'coexistence_policy' => mb_substr(trim((string) ($_POST['coexistence_policy'] ?? '')), 0, 4000),
    ]);
    logAction('Settings', 'Edit', "$session_name edited the endpoint agent settings (" . ($enable ? 'on' : 'off') . ')');
    flash_alert('Endpoint agent settings saved.');
    redirect();
}

if (isset($_POST['rotate_signing_key'])) {
    validateCSRFToken($_POST['csrf_token']);
    $kid = Config::generateSigningKey();
    logAction('Settings', 'Edit', "$session_name rotated the endpoint agent signing key (new key id $kid)");
    flash_alert('A new signing key was generated. Agents trust the key they received at enrollment, so every device must re-enroll (Rotate credential) before it accepts jobs again.', 'warning');
    redirect();
}

if (isset($_POST['create_enroll_token'])) {
    validateCSRFToken($_POST['csrf_token']);
    $client = intval($_POST['client_id'] ?? 0);
    if ($client <= 0 || Db::val('SELECT client_id FROM clients WHERE client_id = ?', [$client]) === null) {
        flash_alert('Choose the department the devices belong to.', 'error');
        redirect();
    }
    $loc = intval($_POST['location_id'] ?? 0);
    if ($loc > 0 && Db::val('SELECT location_id FROM locations WHERE location_id = ? AND location_client_id = ?', [$loc, $client]) === null) {
        $loc = 0;
    }
    $t = Enrollment::createToken($client, $loc, (string) ($_POST['ring'] ?? 'stable'), ea_post_int('ttl_hours', 1, 720, 24), ea_post_int('max_uses', 1, 5000, 1),
        trim((string) ($_POST['label'] ?? '')), (int) $session_user_id);
    $_SESSION['ea_new_token'] = ['token' => $t['token'], 'id' => $t['token_id']];   // shown once, on the next page load
    logAction('Endpoint Agent', 'Enrollment Token Created', "$session_name created enrollment token #{$t['token_id']} for department $client", $client);
    flash_alert('Enrollment token created. Copy it now: it is shown only once.');
    redirect();
}

if (isset($_POST['revoke_enroll_token'])) {
    validateCSRFToken($_POST['csrf_token']);
    $id = intval($_POST['token_id'] ?? 0);
    if (Enrollment::revokeToken($id, (int) $session_user_id)) {
        logAction('Endpoint Agent', 'Enrollment Token Revoked', "$session_name revoked enrollment token #$id");
        flash_alert('Enrollment token revoked.');
    }
    redirect();
}

if (isset($_POST['device_action'])) {
    validateCSRFToken($_POST['csrf_token']);
    $id = intval($_POST['device_id'] ?? 0);
    $act = (string) $_POST['device_action'];
    $uid = (int) $session_user_id;
    $done = false;
    $msg = '';
    switch ($act) {
        case 'approve_link':
            $r = Enrollment::resolvePending($id, 'link', intval($_POST['asset_id'] ?? 0), $uid);
            $done = $r['ok']; $msg = $r['message']; break;
        case 'approve_create':
            $r = Enrollment::resolvePending($id, 'create_asset', null, $uid);
            $done = $r['ok']; $msg = $r['message']; break;
        case 'reject':
            $r = Enrollment::resolvePending($id, 'reject', null, $uid);
            $done = $r['ok']; $msg = $r['message']; break;
        case 'revoke':
            $done = Devices::revoke($id, 'revoked by ' . $session_name, $uid); $msg = 'Device revoked. It can no longer check in or fetch jobs.'; break;
        case 'rotate':
            $done = Devices::rotate($id, $uid); $msg = 'Credential invalidated. The agent must re-enroll with a new enrollment token.'; break;
        case 'retire':
            $done = Devices::retire($id, $uid); $msg = 'Device retired: credential revoked, queued jobs cancelled, monitoring stopped. The asset was kept.'; break;
        case 'allow_reenroll':
            $done = Devices::allowReenroll($id, $uid); $msg = 'The device may enroll again with a fresh enrollment token.'; break;
        case 'set_ring':
            $done = Devices::setRing($id, (string) ($_POST['ring'] ?? '')); $msg = 'Ring updated.'; break;
        case 'clear_update_failures':
            Updates::clearFailures($id); $done = true; $msg = 'Update failures cleared for this device.'; break;
        case 'transfer':
            $done = Devices::transfer($id, intval($_POST['client_id'] ?? 0), 0, $uid); $msg = 'Device and asset moved to the new department.'; break;
    }
    flash_alert($done ? nullable_htmlentities($msg) : 'Nothing changed.', $done ? 'success' : 'error');
    redirect();
}

if (isset($_POST['add_agent_release'])) {
    validateCSRFToken($_POST['csrf_token']);
    $err = Updates::addRelease(trim((string) ($_POST['version'] ?? '')), trim((string) ($_POST['url'] ?? '')), trim((string) ($_POST['sha256'] ?? '')),
        trim((string) ($_POST['min_version'] ?? '0.0.0')) ?: '0.0.0', (string) ($_POST['ring'] ?? 'stable'), ea_post_int('rollout_pct', 0, 100, 0),
        trim((string) ($_POST['notes'] ?? '')), (int) $session_user_id);
    flash_alert($err === null ? 'Release saved.' : nullable_htmlentities($err), $err === null ? 'success' : 'error');
    redirect();
}

if (isset($_POST['update_agent_release'])) {
    validateCSRFToken($_POST['csrf_token']);
    Db::run('UPDATE endpoint_agent_releases SET rollout_pct = ?, active = ? WHERE release_id = ?',
        [ea_post_int('rollout_pct', 0, 100, 0), isset($_POST['active']) ? 1 : 0, intval($_POST['release_id'] ?? 0)]);
    logAction('Endpoint Agent', 'Release Updated', "$session_name changed rollout of agent release #" . intval($_POST['release_id'] ?? 0));
    flash_alert('Release updated.');
    redirect();
}

if (isset($_POST['save_mesh_settings']) || isset($_POST['test_mesh'])) {
    validateCSRFToken($_POST['csrf_token']);
    $url = trim((string) ($_POST['mesh_url'] ?? ''));
    $norm = $url === '' ? '' : Mesh::normalizeUrl($url);
    if ($norm === null) {
        flash_alert('The MeshCentral address must be a plain https:// address without credentials or a query.', 'error');
        redirect();
    }
    $domain = trim((string) ($_POST['mesh_domain'] ?? ''));
    $account = trim((string) ($_POST['mesh_account_template'] ?? 'rivetit-support'));
    if (!preg_match('/^[A-Za-z0-9._{}-]{1,100}$/', $account) || ($domain !== '' && !preg_match('/^[A-Za-z0-9._-]{1,100}$/', $domain))) {
        flash_alert('The MeshCentral domain and account name may only contain letters, digits and . _ -', 'error');
        redirect();
    }
    $vals = [
        'mesh_enabled' => isset($_POST['mesh_enabled']) ? 1 : 0,
        'mesh_url' => $norm,
        'mesh_domain' => $domain,
        'mesh_account_template' => $account,
        'mesh_policy' => in_array($_POST['mesh_policy'] ?? '', ['unattended', 'attended', 'both'], true) ? $_POST['mesh_policy'] : 'unattended',
        'mesh_token_ttl_s' => ea_post_int('mesh_token_ttl_s', 60, 3600, 300),
    ];
    $key = trim((string) ($_POST['mesh_login_key'] ?? ''));
    if ($key !== '') {
        if (!preg_match('/^[0-9a-fA-F]{64,}$/', $key)) {
            flash_alert('The login token key is the long hex string printed by "meshcentral --loginTokenKey".', 'error');
            redirect();
        }
        $vals['mesh_login_key_enc'] = encryptSetting(strtolower($key));
    }
    Config::set($vals);
    logAction('Settings', 'Edit', "$session_name edited the MeshCentral settings for the endpoint agent" . ($key !== '' ? ' (login key replaced)' : ''));
    if (isset($_POST['test_mesh']) && $norm !== '') {
        $err = Mesh::probe($norm);
        flash_alert($err === null ? 'MeshCentral answered.' : nullable_htmlentities($err), $err === null ? 'success' : 'error');
    } else {
        flash_alert('MeshCentral settings saved.');
    }
    redirect();
}
