<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

use GuzzleHttp\Client;
use ITFlow\Audit\AuditService;
use ITFlow\Mcp\McpConfig;
use ITFlow\Mcp\McpDiagnostics;
use ITFlow\Mcp\McpIdentityLinks;

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
    if ($enabled && (!McpConfig::issuerValid($issuer) || !McpConfig::audienceValid($audience))) {
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
