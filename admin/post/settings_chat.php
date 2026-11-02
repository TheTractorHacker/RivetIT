<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

// Administration > Chat: Slack / Teams two-way chat, commands, actions, virtual agent, identity links, and the Slack manifest / Teams app package.
// Every behaviour switch is OFF until an administrator turns it on here. Secrets (Slack signing secret, bot token, Teams client secret) are stored
// with encryptSetting() and never shown again; leave a secret field blank to keep it.

use ITFlow\Chat\ChatIdentity;
use ITFlow\Chat\ChatSettings;
use ITFlow\Chat\SlackManifest;
use ITFlow\Chat\TeamsPackage;

if (isset($_POST['save_chat_settings'])) {

    validateCSRFToken($_POST['csrf_token']);

    $flag = static fn (string $k): int => isset($_POST[$k]) ? 1 : 0;
    $errors = [];

    $team = substr(preg_replace('/[^A-Z0-9]/', '', strtoupper(trim((string) ($_POST['config_slack_team_id'] ?? '')))), 0, 32);
    $channel = strtoupper(trim((string) ($_POST['config_chat_slack_channel_id'] ?? '')));
    if ($channel !== '' && !preg_match('/^[CG][A-Z0-9]{4,20}$/', $channel)) {
        $errors[] = 'The Slack channel ID looks like C0123ABCD (channel details > copy channel ID).';
        $channel = '';
    }
    $reply_type = ($_POST['config_chat_thread_reply_type'] ?? 'internal') === 'public' ? 'public' : 'internal';
    $default_client = intval($_POST['config_chat_default_client_id'] ?? 0);
    if ($default_client > 0 && !mysqli_fetch_row(mysqli_query($mysqli, "SELECT 1 FROM clients WHERE client_id = $default_client AND client_archived_at IS NULL"))) {
        $errors[] = 'The default client does not exist.';
        $default_client = 0;
    }
    $teams_app_id = strtolower(trim((string) ($_POST['config_teams_app_id'] ?? '')));
    if ($teams_app_id !== '' && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $teams_app_id)) {
        $errors[] = 'The Microsoft App ID must be a GUID.';
        $teams_app_id = '';
    }
    $teams_tenant = strtolower(trim((string) ($_POST['config_teams_tenant_id'] ?? '')));
    if ($teams_tenant !== '' && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $teams_tenant)) {
        $errors[] = 'The tenant ID must be a GUID.';
        $teams_tenant = '';
    }

    $two_way = $flag('config_chat_two_way_enabled');
    $commands = $flag('config_chat_commands_enabled');
    $actions = $flag('config_chat_actions_enabled');
    $virtual = $flag('config_chat_virtual_agent_enabled');
    $mirror_internal = $flag('config_chat_mirror_internal');
    $teams_bot = $flag('config_teams_bot_enabled');
    $slack_email = $flag('config_slack_link_by_email');
    $teams_email = $flag('config_teams_link_by_email');

    $stmt = mysqli_prepare($mysqli, "UPDATE settings SET config_slack_team_id = ?, config_chat_slack_channel_id = ?, config_chat_thread_reply_type = ?, config_chat_default_client_id = ?,
        config_chat_two_way_enabled = ?, config_chat_commands_enabled = ?, config_chat_actions_enabled = ?, config_chat_virtual_agent_enabled = ?, config_chat_mirror_internal = ?,
        config_slack_link_by_email = ?, config_teams_bot_enabled = ?, config_teams_app_id = ?, config_teams_tenant_id = ?, config_teams_link_by_email = ? WHERE company_id = 1");
    mysqli_stmt_bind_param($stmt, 'sssiiiiiiiissi', $team, $channel, $reply_type, $default_client, $two_way, $commands, $actions, $virtual, $mirror_internal, $slack_email, $teams_bot, $teams_app_id, $teams_tenant, $teams_email);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    // Secrets: blank = keep, the clear box = remove, anything else is validated and stored encrypted.
    $secrets = [
        ['config_slack_signing_secret', 'slack_signing_secret_clear', '/^[A-Za-z0-9]{16,64}$/', 'The Slack signing secret is 32 letters and digits (Basic Information > App Credentials).'],
        ['config_slack_bot_token', 'slack_bot_token_clear', '/^xoxb-[A-Za-z0-9-]{10,200}$/', 'That does not look like a Slack bot token (it starts with xoxb-).'],
        ['config_teams_app_secret', 'teams_app_secret_clear', '/^[\x21-\x7E]{8,200}$/', 'The Azure Bot client secret value looks wrong.'],
    ];
    foreach ($secrets as [$col, $clear, $re, $msg]) {
        $val = trim((string) ($_POST[$col] ?? ''));
        if (isset($_POST[$clear])) {
            mysqli_query($mysqli, "UPDATE settings SET $col = '' WHERE company_id = 1");
        } elseif ($val !== '') {
            if (!preg_match($re, $val)) {
                $errors[] = $msg . ' Nothing was changed for it.';
                continue;
            }
            $enc = encryptSetting($val);
            $stmt = mysqli_prepare($mysqli, "UPDATE settings SET $col = ? WHERE company_id = 1");
            mysqli_stmt_bind_param($stmt, 's', $enc);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }

    logAction("Settings", "Edit", "$session_name saved the Slack/Teams chat settings (two-way " . ($two_way ? 'on' : 'off') . ", commands " . ($commands ? 'on' : 'off') . ", actions " . ($actions ? 'on' : 'off') . ", virtual agent " . ($virtual ? 'on' : 'off') . ", Teams bot " . ($teams_bot ? 'on' : 'off') . ")");

    flash_alert($errors ? 'Saved, with problems: ' . implode(' ', array_map('htmlspecialchars', $errors)) : 'Chat settings saved', $errors ? 'warning' : 'success');
    redirect();
}

if (isset($_POST['add_chat_identity'])) {

    validateCSRFToken($_POST['csrf_token']);

    $platform = ($_POST['identity_platform'] ?? '') === 'teams' ? 'teams' : 'slack';
    $external = trim((string) ($_POST['identity_external_id'] ?? ''));
    $team = trim((string) ($_POST['identity_team_id'] ?? ''));
    $user_id = intval($_POST['identity_user_id'] ?? 0);
    $contact_email = strtolower(trim((string) ($_POST['identity_contact_email'] ?? '')));
    $contact_id = 0;
    if ($user_id <= 0 && $contact_email !== '') {
        $stmt = mysqli_prepare($mysqli, 'SELECT contact_id FROM contacts WHERE LOWER(contact_email) = ? AND contact_archived_at IS NULL AND contact_client_id > 0');
        mysqli_stmt_bind_param($stmt, 's', $contact_email);
        mysqli_stmt_execute($stmt);
        $found = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
        mysqli_stmt_close($stmt);
        if (count($found) !== 1) {
            flash_alert(count($found) === 0 ? 'No active contact has that email address.' : 'More than one contact has that email address; link by agent or fix the duplicate first.', 'error');
            redirect();
        }
        $contact_id = (int) $found[0]['contact_id'];
    }

    $res = (new ChatIdentity($mysqli, new ChatSettings($mysqli)))->link($platform, $team, $external, $user_id, $contact_id, $session_user_id);
    if (!$res['ok']) {
        flash_alert(htmlspecialchars((string) $res['error']), 'error');
        redirect();
    }
    logAction("Settings", "Edit", "$session_name linked $platform user " . substr($external, 0, 40) . " to " . ($user_id > 0 ? "agent $user_id" : "contact $contact_id") . " (chat identity link)");
    flash_alert('Link saved');
    redirect();
}

if (isset($_POST['delete_chat_identity'])) {

    validateCSRFToken($_POST['csrf_token']);

    $id = intval($_POST['identity_id'] ?? 0);
    if ((new ChatIdentity($mysqli, new ChatSettings($mysqli)))->unlink($id)) {
        logAction("Settings", "Edit", "$session_name removed chat identity link $id");
        flash_alert('Link removed');
    } else {
        flash_alert('That link no longer exists', 'error');
    }
    redirect();
}

// Downloads. POST + CSRF so a third-party page cannot make an admin's browser fetch them; the response is a file, not a redirect.
if (isset($_POST['download_slack_manifest'])) {

    validateCSRFToken($_POST['csrf_token']);

    $base = ChatSettings::baseUrl();
    try {
        $json = SlackManifest::json($base, APP_NAME, !empty($_POST['manifest_commands']), !empty($_POST['manifest_events']));
    } catch (\InvalidArgumentException $e) {
        flash_alert('The Slack manifest needs this install\'s public https address. Set the base URL in Administration > Settings > General first.', 'error');
        redirect();
    }
    logAction("Settings", "Export", "$session_name downloaded the Slack app manifest");
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="rivetit-slack-app-manifest.json"');
    header('Cache-Control: no-store');
    echo $json;
    exit;
}

if (isset($_POST['download_teams_package'])) {

    validateCSRFToken($_POST['csrf_token']);

    $cfg = new ChatSettings($mysqli);
    try {
        $zip = TeamsPackage::zip(ChatSettings::baseUrl(), APP_NAME, $cfg->text('config_teams_app_id'));
    } catch (\Throwable $e) {
        flash_alert('Save the Azure Bot Microsoft App ID (a GUID) and make sure the base URL is set before building the Teams app package.', 'error');
        redirect();
    }
    logAction("Settings", "Export", "$session_name downloaded the Teams app package");
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="rivetit-teams-app.zip"');
    header('Content-Length: ' . strlen($zip));
    header('Cache-Control: no-store');
    echo $zip;
    exit;
}
