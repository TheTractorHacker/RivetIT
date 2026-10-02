<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['edit_identity_provider'])) {

    validateCSRFToken($_POST['csrf_token']);

    $azure_client_id = sanitizeInput($_POST['azure_client_id']);
    $azure_secret = trim((string) ($_POST['azure_client_secret'] ?? ''));
    if ($azure_secret !== '') {
        $azure_client_secret = mysqli_real_escape_string($mysqli, encryptSetting($azure_secret));
        mysqli_query($mysqli,"UPDATE settings SET config_azure_client_id = '$azure_client_id', config_azure_client_secret = '$azure_client_secret' WHERE company_id = 1");
    } else {
        mysqli_query($mysqli,"UPDATE settings SET config_azure_client_id = '$azure_client_id' WHERE company_id = 1");
    }

    logAction("Settings", "Edit", "$session_name edited identity provider settings");

    flash_alert("Identity Provider Settings updated");

    redirect();

}

if (isset($_POST['edit_oidc_provider'])) {
    validateCSRFToken($_POST['csrf_token']);
    require_once __DIR__ . '/../../includes/oidc_portal.php';

    $enabled = isset($_POST['oidc_enabled']) ? 1 : 0;
    $issuer = trim((string) ($_POST['oidc_issuer'] ?? ''));
    $clientId = trim((string) ($_POST['oidc_client_id'] ?? ''));
    $newSecret = trim((string) ($_POST['oidc_client_secret'] ?? ''));
    $stored = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_oidc_client_secret FROM settings WHERE company_id = 1"));

    if (strlen($issuer) > 255 || strlen($clientId) > 255 || strlen($newSecret) > 1000
        || ($issuer !== '' && !portalOidcIssuerValid($issuer))) {
        flash_alert('Enter a valid HTTPS issuer URL, client ID and secret.', 'error');
        redirect();
    }
    if ($enabled) {
        if ($issuer === '' || $clientId === '' || ($newSecret === '' && empty($stored['config_oidc_client_secret']))) {
            flash_alert('Issuer URL, client ID and client secret are required to enable OpenID Connect.', 'error');
            redirect();
        }
        try {
            portalOidcDiscovery($issuer);
        } catch (Throwable $e) {
            flash_alert('Could not verify this provider\'s OpenID discovery document. Settings were not enabled.', 'error');
            redirect();
        }
    }

    $stmt = $mysqli->prepare("UPDATE settings SET config_oidc_enabled = ?,
        config_oidc_issuer = ?, config_oidc_client_id = ? WHERE company_id = 1");
    $stmt->bind_param('iss', $enabled, $issuer, $clientId);
    $stmt->execute();
    if ($newSecret !== '') {
        $encrypted = encryptSetting($newSecret);
        $stmt = $mysqli->prepare("UPDATE settings SET config_oidc_client_secret = ? WHERE company_id = 1");
        $stmt->bind_param('s', $encrypted);
        $stmt->execute();
    }
    logAction('Settings', 'Edit', "$session_name edited OpenID Connect provider settings");
    flash_alert('OpenID Connect settings updated');
    redirect();
}
