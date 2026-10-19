<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

/*
 * Administration > Remote access (MeshCentral) setup: form handlers (RivetIT 26.10.54). admin/post.php runs this file only for an administrator and picks it from the
 * Referer. CSRF, $_POST extraction and the flash messages are RivetIT's; every validation, the encryption of the login key, the database write and the audit entry are
 * RivetCore\Rmm\Admin\RmmAdmin::saveMesh(); the connection test and the certificate reading are RivetCore\Rmm\Mesh\MeshService.
 *
 * mw_action: save_server (steps 1, with the optional connection test) | save_group (2) | probe_pin (3, reads the certificate, saves nothing) | save_pin (3) | live (JSON: the two
 * network checks the page runs after it has loaded; saves nothing).
 * saveMesh() replaces every connection field it is given, so each action starts from the SAVED values and changes only its own fields (the login key stays when the box is empty).
 */

require_once dirname(__DIR__, 2) . '/includes/rmm_bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/rmm_mesh_wizard.php';

mysqli_report(MYSQLI_REPORT_OFF);

if (!isset($_POST['mw_action'])) {
    return;
}

$mw_back = static function (string $anchor): void {
    redirect('settings_mesh_wizard.php#' . $anchor);
    exit;
};
$mw_json = static function (array $body, int $http = 200): void {
    http_response_code($http);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($body);
    exit;
};

$mw_rmm = rivetRmmModule();
$mw_who = rivetRmmPrincipal((int) $session_user_id, (string) $session_name);
$mw_action = (string) $_POST['mw_action'];
$mw_wantsJson = $mw_action === 'live';

$mw_tok = (string) ($_SESSION['csrf_token'] ?? '');
if ($mw_tok === '' || !is_string($_POST['csrf_token'] ?? null) || !hash_equals($mw_tok, $_POST['csrf_token'])) {
    if ($mw_wantsJson) {
        $mw_json(['success' => false, 'message' => 'Your session expired. Reload the page.'], 403);
    }
    validateCSRFToken($_POST['csrf_token'] ?? null);   // the standard refusal (flash and redirect)
    exit;
}

$mw_cfg = $mw_rmm->readModel()->settingsSummary();
/** The saved connection fields, in the shape saveMesh() takes. @return array<string,mixed> */
$mw_base = static fn (): array => [
    'mesh_enabled' => (int) ($mw_cfg['mesh_enabled'] ?? 0), 'mesh_url' => (string) ($mw_cfg['mesh_url'] ?? ''), 'mesh_domain' => (string) ($mw_cfg['mesh_domain'] ?? ''),
    'mesh_account_template' => (string) ($mw_cfg['mesh_account_template'] ?? ''), 'mesh_policy' => (string) ($mw_cfg['mesh_policy'] ?? 'unattended'), 'mesh_token_ttl_s' => (int) ($mw_cfg['mesh_token_ttl_s'] ?? 300),
];
$mw_str = static fn (string $k): string => is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : '';
$mw_warn = static function (\RivetCore\Rmm\Technician\ActionResult $r): string {
    $w = is_array($r->data['warnings'] ?? null) ? array_values(array_filter($r->data['warnings'], 'is_string')) : [];

    return $w === [] ? '' : ' Warning: ' . implode(' ', $w);
};

if ($mw_action === 'live') {
    // The two network checks of the page. Nothing is saved or audited. Core runs them through its network policy (no redirects, short timeouts).
    $url = trim((string) ($mw_cfg['mesh_url'] ?? ''));
    if ($url === '') {
        $mw_json(['success' => true, 'conn' => ['state' => 'none', 'text' => 'No MeshCentral address is saved yet.'], 'trust' => ['state' => 'none', 'text' => 'Finish step 1 first.']]);
    }
    $norm = $mw_rmm->mesh()->normalizeUrl($url);
    $err = $norm === null ? 'The saved MeshCentral address is not a plain https:// address.' : $mw_rmm->mesh()->probe($norm);
    $conn = $err === null ? ['state' => 'ok', 'text' => 'MeshCentral answered.'] : ['state' => 'fail', 'text' => 'MeshCentral did not answer: ' . $err];
    $trust = ['state' => 'none', 'text' => 'Not checked.'];
    if ($norm !== null && $err === null) {
        $p = $mw_rmm->mesh()->probePin($norm);
        $pin = (string) ($mw_rmm->meshAgentSettings()->get()['server_cert_pin'] ?? '');
        $_SESSION['mw_trust'] = ['url' => $url, 'ok' => !empty($p['ok']), 'public' => !empty($p['publicly_trusted']), 'at' => time()];
        if ($pin !== '') {
            $trust = ['state' => 'pinned', 'text' => 'A pin is saved: the installer accepts only that exact certificate.'];
        } elseif (!empty($p['ok']) && !empty($p['publicly_trusted'])) {
            $trust = ['state' => 'public', 'text' => 'The server has a publicly trusted certificate: no pin needed.'];
        } elseif (!empty($p['ok'])) {
            $trust = ['state' => 'private', 'text' => 'The server uses a private or self-signed certificate: read it from the server and save the pin.'];
        } else {
            $trust = ['state' => 'fail', 'text' => 'Could not read the certificate: ' . (string) ($p['error'] ?? 'unknown error')];
        }
    } elseif ($norm !== null) {
        $trust = ['state' => 'none', 'text' => 'Not checked: the server did not answer.'];
    }
    $mw_json(['success' => true, 'conn' => $conn, 'trust' => $trust]);
}

if ($mw_action === 'save_server') {
    $in = $mw_base();
    $in['mesh_enabled'] = isset($_POST['mesh_enabled']) ? 1 : 0;
    $in['mesh_url'] = $mw_str('mesh_url');
    $in['mesh_domain'] = $mw_str('mesh_domain');
    $in['mesh_login_key'] = $mw_str('mesh_login_key');   // empty keeps the stored key
    $r = $mw_rmm->admin()->saveMesh($mw_who, $in, isset($_POST['test']));
    $mw_rmm->syncState();   // the remote sub-switch follows mesh_enabled
    if (!$r->ok && empty($r->data['saved'])) {
        $_SESSION['mw_keep'] = ['mesh_url' => $in['mesh_url'], 'mesh_domain' => $in['mesh_domain'], 'mesh_enabled' => (string) $in['mesh_enabled']];
        flash_alert(nullable_htmlentities($r->message), 'error');
    } elseif (!$r->ok) {
        flash_alert(nullable_htmlentities('Saved, but MeshCentral did not answer: ' . $r->message), 'warning');
    } else {
        flash_alert(nullable_htmlentities(isset($_POST['test']) ? 'Saved. MeshCentral answered.' : 'Saved.'), 'success');
    }
    unset($_SESSION['mw_trust']);   // the address may have changed
    $mw_back('step-1');
}

if ($mw_action === 'save_group') {
    $in = $mw_base();
    $in['mesh_group_id'] = isset($_POST['clear']) ? '' : $mw_str('mesh_group_id');
    if (!isset($_POST['clear']) && $in['mesh_group_id'] === '') {
        flash_alert(nullable_htmlentities('Paste the download address or the id of the device group first.'), 'error');
        $mw_back('step-2');
    }
    $r = $mw_rmm->admin()->saveMesh($mw_who, $in, false);
    if (!$r->ok) {
        $_SESSION['mw_keep'] = ['mesh_group_id' => $in['mesh_group_id']];
        flash_alert(nullable_htmlentities($r->message), 'error');
    } else {
        flash_alert(nullable_htmlentities((isset($_POST['clear']) ? 'The device group was removed.' : 'The device group was saved.') . $mw_warn($r)), $mw_warn($r) !== '' ? 'warning' : 'success');
    }
    $mw_back('step-2');
}

if ($mw_action === 'probe_pin') {
    $url = trim((string) ($mw_cfg['mesh_url'] ?? ''));
    $norm = $url === '' ? null : $mw_rmm->mesh()->normalizeUrl($url);
    $p = $norm === null ? ['ok' => false, 'error' => 'Save the MeshCentral address in step 1 first.'] : $mw_rmm->mesh()->probePin($norm);
    $_SESSION['mw_pin_probe'] = $p;
    if ($norm !== null) {
        $_SESSION['mw_trust'] = ['url' => $url, 'ok' => !empty($p['ok']), 'public' => !empty($p['publicly_trusted']), 'at' => time()];
    }
    flash_alert(nullable_htmlentities(!empty($p['ok'])
        ? (!empty($p['publicly_trusted']) ? 'Read the certificate. It is publicly trusted, so no pin is needed: leave the pin empty.' : 'Read the certificate. Check the proposed pin below, then use it and save.')
        : 'The certificate could not be read: ' . (string) ($p['error'] ?? 'unknown error')), !empty($p['ok']) ? 'success' : 'warning');
    $mw_back('step-3');
}

if ($mw_action === 'save_pin') {
    $in = $mw_base();
    $in['mesh_server_cert_pin'] = isset($_POST['clear']) ? '' : $mw_str('mesh_server_cert_pin');
    $r = $mw_rmm->admin()->saveMesh($mw_who, $in, false);
    if (!$r->ok) {
        $_SESSION['mw_keep'] = ['mesh_server_cert_pin' => $in['mesh_server_cert_pin']];
        flash_alert(nullable_htmlentities($r->message), 'error');
    } else {
        flash_alert(nullable_htmlentities($in['mesh_server_cert_pin'] === '' ? 'The pin was removed: the installer trusts the server\'s public certificate.' : 'The pin was saved.'), 'success');
    }
    $mw_back('step-3');
}

flash_alert('Unknown action.', 'error');
$mw_back('step-1');
