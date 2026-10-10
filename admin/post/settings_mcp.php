<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

use GuzzleHttp\Client;
use ITFlow\Audit\AuditService;
use ITFlow\Mcp\McpConfig;
use ITFlow\Mcp\McpDiagnostics;
use ITFlow\Mcp\McpIdentityLinks;
use ITFlow\Mcp\OAuth\OAuthConfig;
use ITFlow\Mcp\OAuth\OAuthService;

if (isset($_POST['save_mcp_settings']) || isset($_POST['run_mcp_checks'])) {
    validateCSRFToken($_POST['csrf_token']);
    $current = McpConfig::load($mysqli);
    if (!$current['schema_ready']) {
        flash_alert('Run the database update first.', 'error');
        redirect();
    }
    $enabled = isset($_POST['mcp_enabled']) ? 1 : 0;
    $issuer = $current['issuer_from_env'] ? $current['issuer'] : trim((string) ($_POST['mcp_issuer'] ?? ''));
    $audience = $current['audience_from_env'] ? $current['audience'] : trim((string) ($_POST['mcp_audience'] ?? ''));

    if (($issuer !== '' && !McpConfig::issuerValid($issuer)) || strlen($audience) > 255
        || ($audience !== '' && !McpConfig::audienceValid($audience))) {
        flash_alert('Enter an https:// issuer address and an audience without spaces.', 'error');
        redirect();
    }
    // With the built-in sign-in on, RivetIT is its own issuer: the external issuer and audience are not needed.
    $builtin_on = OAuthConfig::load($mysqli)['builtin'];
    if ($enabled && !$builtin_on && (!McpConfig::issuerValid($issuer) || !McpConfig::audienceValid($audience))) {
        flash_alert('Enter both the issuer and the audience before turning Remote MCP on.', 'error');
        redirect();
    }

    $stmt = $mysqli->prepare('UPDATE settings SET config_module_enable_mcp = ?, config_mcp_issuer = ?, config_mcp_audience = ? WHERE company_id = 1');
    $stmt->bind_param('iss', $enabled, $issuer, $audience);
    $stmt->execute();
    logAction('Settings', 'Edit', "$session_name edited Remote MCP settings (" . ($enabled ? 'on' : 'off') . ')');
    AuditService::record('mcp.settings_changed', (int) $session_user_id, 'settings', 'mcp', 'update', 'Remote MCP settings changed', ['enabled' => $enabled, 'issuer' => $issuer]);

    if (isset($_POST['run_mcp_checks'])) {
        $cfg = McpConfig::load($mysqli);
        $diag = new McpDiagnostics($mysqli, new Client(['verify' => true]));
        $_SESSION['mcp_checks'] = ['at' => time(), 'checks' => $diag->run($cfg, $config_base_url)];
        flash_alert('Settings saved. Health checks finished.');
    } else {
        flash_alert('Remote MCP settings saved.');
    }
    redirect();
}

if (isset($_POST['link_mcp_identity'])) {
    validateCSRFToken($_POST['csrf_token']);
    $pendingId = intval($_POST['pending_id'] ?? 0);
    $userId = intval($_POST['user_id'] ?? 0);
    [$ok, $message] = McpIdentityLinks::link($mysqli, $pendingId, $userId);
    if ($ok) {
        logAction('User', 'Edit', "$session_name linked a Remote MCP identity to user #$userId", 0, $userId);
        AuditService::record('mcp.identity_linked', (int) $session_user_id, 'user', $userId, 'link', "Remote MCP identity linked to user #$userId");
    }
    flash_alert($message, $ok ? 'success' : 'error');
    redirect();
}

if (isset($_POST['dismiss_mcp_identity'])) {
    validateCSRFToken($_POST['csrf_token']);
    McpIdentityLinks::dismiss($mysqli, intval($_POST['pending_id'] ?? 0));
    flash_alert('Dismissed.');
    redirect();
}

if (isset($_POST['unlink_mcp_agent'])) {
    validateCSRFToken($_POST['csrf_token']);
    $userId = intval($_POST['user_id'] ?? 0);
    McpIdentityLinks::unlink($mysqli, $userId);
    logAction('User', 'Edit', "$session_name unlinked the Remote MCP identity of user #$userId", 0, $userId);
    AuditService::record('mcp.identity_unlinked', (int) $session_user_id, 'user', $userId, 'unlink', "Remote MCP identity unlinked from user #$userId");
    flash_alert('Agent unlinked. Their MCP access has stopped.');
    redirect();
}

/* ---- built-in OAuth server (docs/REMOTE_MCP.md) ---- */

if (isset($_POST['save_mcp_oauth_settings'])) {
    validateCSRFToken($_POST['csrf_token']);
    if (!OAuthConfig::load($mysqli)['schema_ready']) {
        flash_alert('Run the database update first.', 'error');
        redirect();
    }
    $builtin = isset($_POST['mcp_oauth_builtin']) ? '1' : '0';
    $registration = isset($_POST['mcp_oauth_registration']) ? '1' : '0';
    OAuthConfig::set($mysqli, 'builtin_enabled', $builtin);
    OAuthConfig::set($mysqli, 'registration_enabled', $registration);
    logAction('Settings', 'Edit', "$session_name edited Remote MCP built-in sign-in (" . ($builtin ? 'on' : 'off') . ', self-registration ' . ($registration ? 'on' : 'off') . ')');
    AuditService::record('mcp.oauth_settings_changed', (int) $session_user_id, 'settings', 'mcp_oauth', 'update', 'Remote MCP built-in sign-in settings changed', ['builtin' => $builtin === '1', 'registration' => $registration === '1']);
    flash_alert('Built-in sign-in settings saved.');
    redirect();
}

if (isset($_POST['add_mcp_oauth_client'])) {
    validateCSRFToken($_POST['csrf_token']);
    $uris = preg_split('/\R/', (string) ($_POST['redirect_uris'] ?? '')) ?: [];
    $service = new OAuthService($mysqli, OAuthConfig::issuer($config_base_url), OAuthConfig::resource($config_base_url));
    [$ok, $result] = $service->registerManual(trim((string) ($_POST['client_name'] ?? '')), $uris, trim((string) ($_POST['client_id'] ?? '')) ?: null, (int) $session_user_id);
    if ($ok) {
        logAction('Settings', 'Edit', "$session_name added a Remote MCP app");
        flash_alert('App added. Its client ID is ' . htmlspecialchars($result, ENT_QUOTES) . '.');
    } else {
        flash_alert($result, 'error');
    }
    redirect();
}

if (isset($_POST['toggle_mcp_oauth_client'])) {
    validateCSRFToken($_POST['csrf_token']);
    $store = new \ITFlow\Mcp\OAuth\OAuthStore($mysqli);
    $client = $store->client((string) ($_POST['client_id'] ?? ''));
    if ($client) {
        $disable = $client['disabled_at'] === null;
        $store->setClientDisabled($client['client_id'], $disable);
        logAction('Settings', 'Edit', "$session_name " . ($disable ? 'disabled' : 'enabled') . ' a Remote MCP app');
        AuditService::record('mcp.oauth_client_' . ($disable ? 'disabled' : 'enabled'), (int) $session_user_id, 'mcp_oauth_client', $client['client_id'], 'update', 'MCP app "' . mb_substr($client['client_name'], 0, 60) . '" ' . ($disable ? 'disabled' : 'enabled'));
        flash_alert($disable ? 'App disabled. Its connections stopped working.' : 'App enabled.');
    }
    redirect();
}

if (isset($_POST['delete_mcp_oauth_client'])) {
    validateCSRFToken($_POST['csrf_token']);
    $store = new \ITFlow\Mcp\OAuth\OAuthStore($mysqli);
    $client = $store->client((string) ($_POST['client_id'] ?? ''));
    if ($client) {
        $store->deleteClient($client['client_id']);
        logAction('Settings', 'Delete', "$session_name deleted a Remote MCP app");
        AuditService::record('mcp.oauth_client_deleted', (int) $session_user_id, 'mcp_oauth_client', $client['client_id'], 'delete', 'MCP app "' . mb_substr($client['client_name'], 0, 60) . '" deleted with all its connections');
        flash_alert('App deleted.');
    }
    redirect();
}

if (isset($_POST['revoke_mcp_oauth_grant'])) {
    validateCSRFToken($_POST['csrf_token']);
    $service = new OAuthService($mysqli, OAuthConfig::issuer($config_base_url), OAuthConfig::resource($config_base_url));
    if ($service->revokeByAdmin(intval($_POST['grant_id'] ?? 0), (int) $session_user_id)) {
        logAction('Settings', 'Edit', "$session_name revoked a Remote MCP connection");
        flash_alert('Connection revoked. The app lost access immediately.');
    }
    redirect();
}
