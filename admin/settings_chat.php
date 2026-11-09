<?php
require_once "includes/inc_all_admin.php";

use ITFlow\Chat\ChatIdentity;
use ITFlow\Chat\ChatSettings;

$chat_ready = false;
try {
    $chat_ready = (bool) $mysqli->query("SHOW COLUMNS FROM settings LIKE 'config_chat_two_way_enabled'")->fetch_row() && (bool) $mysqli->query("SHOW TABLES LIKE 'chat_identities'")->fetch_row();
} catch (Throwable $e) {
}
$csrf = $_SESSION['csrf_token'];
$app = htmlspecialchars(APP_NAME);
$base = ChatSettings::baseUrl();
$host_url = $base !== '' ? $base : 'https://your-host';
$urls = [
    'Interactivity (buttons, modals, shortcuts)' => $host_url . '/slack_interactive.php',
    'Event subscriptions' => $host_url . '/slack_events.php',
    'Slash command /ticket' => $host_url . '/slack_commands.php',
    'Teams bot messaging endpoint' => $host_url . '/teams_bot.php',
];

if (!$chat_ready) { ?>
<div class="card"><div class="card-body">
    <h3 class="card-title"><i class="fas fa-fw fa-comments me-2"></i>Chat</h3>
    <div class="alert alert-warning mb-0">The chat tables are not in this database yet. Run <strong>Administration &gt; Update database</strong>, then reload this page.</div>
</div></div>
<?php require_once "../includes/footer.php"; return; }

$cfg = new ChatSettings($mysqli);
$clients = mysqli_fetch_all(mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL ORDER BY client_name ASC LIMIT 500"), MYSQLI_ASSOC);
$agents = mysqli_fetch_all(mysqli_query($mysqli, "SELECT user_id, user_name, user_email FROM users WHERE user_type = 1 AND user_status = 1 AND user_archived_at IS NULL ORDER BY user_name ASC"), MYSQLI_ASSOC);
$links = (new ChatIdentity($mysqli, $cfg))->listLinks();
$chk = static fn (bool $v): string => $v ? 'checked' : '';
$secret_state = static fn (string $col): string => $cfg->rawSecret($col) !== '' ? 'Stored encrypted - leave blank to keep' : '';
?>
<style nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
    .chat-url { display: flex; flex-wrap: wrap; gap: .25rem .75rem; align-items: baseline; padding: .4rem 0; border-bottom: 1px solid var(--if-border, var(--tblr-border-color)); }
    .chat-url:last-child { border-bottom: 0; }
    .chat-url span { flex: 0 0 15rem; max-width: 100%; color: var(--if-muted, var(--tblr-secondary)); font-size: .875rem; }
    .chat-url code { word-break: break-all; }
    .chat-switch small { display: block; margin: .1rem 0 .75rem 2.5rem; color: var(--if-muted, var(--tblr-secondary)); }
    .chat-tag { font-size: .75rem; white-space: normal; text-align: left; }
    /* amber text on the pale amber badge is about 2:1; darken it (lighten it in dark mode) to pass WCAG AA */
    .chat-tag.text-warning { color: #8a5a00 !important; }
    :root[data-bs-theme="dark"] .chat-tag.text-warning { color: #ffc14d !important; }
    @media (max-width: 575.98px) { .chat-url span { flex-basis: 100%; } }
</style>

<div class="card mb-3">
    <div class="card-header py-3 d-flex align-items-center gap-3">
        <h3 class="card-title me-auto mb-0"><i class="fas fa-fw fa-comments me-2"></i>Chat (Slack and Microsoft Teams)</h3>
        <a href="settings_webhooks.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-satellite-dish me-1"></i>Notification webhooks</a>
    </div>
    <div class="card-body">
        <p class="text-secondary mb-2">
            Everything on this page is <strong>off by default</strong>. Notifications into a channel are set up under Webhooks; this page adds two-way conversations, the <code>/ticket</code> command,
            actions from chat (status, priority, reply, approve / deny), the virtual agent and the Teams bot. Setup steps and a live checklist are in
            <code>docs/SLACK_TEAMS_SETUP.md</code> and <code>docs/CHAT_LIVE_VERIFICATION.md</code>.
            <span class="badge bg-warning-lt text-warning chat-tag">Not yet verified against a real Slack workspace or Teams tenant</span>
        </p>
        <h4 class="h6 mt-3">Addresses to give Slack and Azure</h4>
        <?php foreach ($urls as $label => $url) { ?>
        <div class="chat-url"><span><?= htmlspecialchars($label) ?></span><code><?= nullable_htmlentities($url) ?></code></div>
        <?php } ?>
        <?php if ($base === '') { ?><div class="alert alert-warning mt-2 mb-0">Set the base URL in Settings &gt; General first: Slack and Azure need a public https address.</div><?php } ?>
    </div>
</div>

<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">

    <div class="card mb-3">
        <div class="card-header py-3"><h3 class="card-title mb-0"><i class="fab fa-fw fa-slack me-2"></i>Slack app</h3></div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6 form-group">
                    <label for="chatSigning">Signing secret <small class="text-secondary">(Basic Information &gt; App Credentials)</small></label>
                    <input type="password" class="form-control font-monospace" id="chatSigning" name="config_slack_signing_secret" autocomplete="new-password" placeholder="<?= htmlspecialchars($secret_state('config_slack_signing_secret')) ?>">
                    <?php if ($cfg->rawSecret('config_slack_signing_secret') !== '') { ?><div class="form-check mt-1"><input type="checkbox" class="form-check-input" name="slack_signing_secret_clear" value="1" id="chatSigningClear"><label class="form-check-label small" for="chatSigningClear">Remove the stored secret</label></div><?php } ?>
                </div>
                <div class="col-md-6 form-group">
                    <label for="chatBot">Bot token <small class="text-secondary">(OAuth &amp; Permissions &gt; Bot User OAuth Token, <code>xoxb-</code>)</small></label>
                    <input type="password" class="form-control font-monospace" id="chatBot" name="config_slack_bot_token" autocomplete="new-password" placeholder="<?= htmlspecialchars($secret_state('config_slack_bot_token')) ?>">
                    <?php if ($cfg->rawSecret('config_slack_bot_token') !== '') { ?><div class="form-check mt-1"><input type="checkbox" class="form-check-input" name="slack_bot_token_clear" value="1" id="chatBotClear"><label class="form-check-label small" for="chatBotClear">Remove the stored token</label></div><?php } ?>
                </div>
                <div class="col-md-6 form-group">
                    <label for="chatTeam">Workspace (team) ID <small class="text-secondary">(recommended; starts with T)</small></label>
                    <input type="text" class="form-control font-monospace" id="chatTeam" name="config_slack_team_id" maxlength="32" value="<?= nullable_htmlentities($cfg->text('config_slack_team_id')) ?>" placeholder="T0123ABCD">
                </div>
                <div class="col-md-6 form-group">
                    <label for="chatChannel">Channel for ticket threads <small class="text-secondary">(channel ID, starts with C)</small></label>
                    <input type="text" class="form-control font-monospace" id="chatChannel" name="config_chat_slack_channel_id" maxlength="32" value="<?= nullable_htmlentities($cfg->text('config_chat_slack_channel_id')) ?>" placeholder="C0123ABCD">
                </div>
            </div>
            <div class="form-check form-switch chat-switch">
                <input type="checkbox" class="form-check-input" name="config_slack_link_by_email" value="1" id="chatSlackEmail" <?= $chk($cfg->flag('config_slack_link_by_email')) ?>>
                <label class="form-check-label" for="chatSlackEmail">Also match Slack users with no explicit link by their confirmed Slack email</label>
                <small>Off by default. An explicit link below always wins. Only turn this on for a workspace you control: it trusts the email address Slack says is confirmed.</small>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header py-3"><h3 class="card-title mb-0"><i class="fas fa-fw fa-sliders-h me-2"></i>What chat may do</h3></div>
        <div class="card-body">
            <div class="form-check form-switch chat-switch">
                <input type="checkbox" class="form-check-input" name="config_chat_two_way_enabled" value="1" id="chatTwoWay" <?= $chk($cfg->twoWay()) ?>>
                <label class="form-check-label" for="chatTwoWay">Two-way ticket threads</label>
                <small>New tickets are posted into the channel above as a thread, agent and customer replies follow into it, and a linked agent's reply in the thread becomes a reply on the ticket. Needs the Events API (slack_events.php).</small>
            </div>
            <div class="form-check form-switch chat-switch">
                <input type="checkbox" class="form-check-input" name="config_chat_mirror_internal" value="1" id="chatMirrorInt" <?= $chk($cfg->flag('config_chat_mirror_internal')) ?>>
                <label class="form-check-label" for="chatMirrorInt">Also post internal notes into the thread</label>
                <small>Off by default: only public and customer replies are mirrored. Turn on only if everyone in the channel may read internal notes.</small>
            </div>
            <div class="form-group mb-3" style="max-width: 28rem">
                <label for="chatReplyType">A reply typed in the thread becomes</label>
                <select class="form-select" name="config_chat_thread_reply_type" id="chatReplyType">
                    <option value="internal" <?= $cfg->threadReplyType() === 'Internal' ? 'selected' : '' ?>>An internal note (safe default)</option>
                    <option value="public" <?= $cfg->threadReplyType() === 'Public' ? 'selected' : '' ?>>A public reply (shown to the customer in the portal; no email is sent from chat)</option>
                </select>
            </div>
            <div class="form-check form-switch chat-switch">
                <input type="checkbox" class="form-check-input" name="config_chat_commands_enabled" value="1" id="chatCmds" <?= $chk($cfg->commands()) ?>>
                <label class="form-check-label" for="chatCmds">Slash command, ticket form and shortcuts</label>
                <small><code>/ticket new</code>, <code>/ticket status</code>, the "New ticket" form and "Create ticket from message". Each person acts with their own rights.</small>
            </div>
            <div class="form-check form-switch chat-switch">
                <input type="checkbox" class="form-check-input" name="config_chat_actions_enabled" value="1" id="chatActions" <?= $chk($cfg->actions()) ?>>
                <label class="form-check-label" for="chatActions">Actions from chat</label>
                <small>Status, priority and Reply on ticket messages; Approve / Deny messages for catalog and workflow approvals sent to approvers who linked their chat account. Closing a ticket is never offered.</small>
            </div>
            <div class="form-check form-switch chat-switch">
                <input type="checkbox" class="form-check-input" name="config_chat_virtual_agent_enabled" value="1" id="chatVa" <?= $chk($cfg->virtualAgent()) ?>>
                <label class="form-check-label" for="chatVa">Virtual agent</label>
                <small>Direct messages and mentions are answered from the knowledge base and service catalog, and can open a ticket or a catalog request. Portal contacts only see client-visible articles.</small>
            </div>
            <div class="form-group" style="max-width: 28rem">
                <label for="chatClient">Client for tickets an agent creates from chat without naming one</label>
                <select class="form-select" name="config_chat_default_client_id" id="chatClient">
                    <option value="0">None (the agent must say <code>client=&lt;id&gt;</code> or pick in the form)</option>
                    <?php foreach ($clients as $c) { ?><option value="<?= (int) $c['client_id'] ?>" <?= $cfg->int('config_chat_default_client_id') === (int) $c['client_id'] ? 'selected' : '' ?>><?= nullable_htmlentities($c['client_name']) ?></option><?php } ?>
                </select>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header py-3"><h3 class="card-title mb-0"><i class="fab fa-fw fa-microsoft me-2"></i>Microsoft Teams bot <span class="badge bg-warning-lt text-warning chat-tag ms-2">Unverified live</span></h3></div>
        <div class="card-body">
            <p class="text-secondary small">Needs an Azure Bot registration (single tenant) whose messaging endpoint is the Teams address above. Incoming requests are accepted only with a valid Bot Framework token for this App ID.</p>
            <div class="form-check form-switch chat-switch">
                <input type="checkbox" class="form-check-input" name="config_teams_bot_enabled" value="1" id="chatTeamsOn" <?= $chk($cfg->teamsBot()) ?>>
                <label class="form-check-label" for="chatTeamsOn">Teams bot enabled</label>
                <small>Uses the same switches above (commands, actions, virtual agent, two-way) for what it may do.</small>
            </div>
            <div class="row">
                <div class="col-md-6 form-group">
                    <label for="chatTeamsApp">Microsoft App ID</label>
                    <input type="text" class="form-control font-monospace" id="chatTeamsApp" name="config_teams_app_id" maxlength="36" value="<?= nullable_htmlentities($cfg->text('config_teams_app_id')) ?>" placeholder="00000000-0000-0000-0000-000000000000">
                </div>
                <div class="col-md-6 form-group">
                    <label for="chatTeamsTenant">Directory (tenant) ID <small class="text-secondary">(recommended: other tenants are ignored)</small></label>
                    <input type="text" class="form-control font-monospace" id="chatTeamsTenant" name="config_teams_tenant_id" maxlength="36" value="<?= nullable_htmlentities($cfg->text('config_teams_tenant_id')) ?>" placeholder="00000000-0000-0000-0000-000000000000">
                </div>
                <div class="col-md-6 form-group">
                    <label for="chatTeamsSecret">Client secret value <small class="text-secondary">(used only to send replies)</small></label>
                    <input type="password" class="form-control font-monospace" id="chatTeamsSecret" name="config_teams_app_secret" autocomplete="new-password" placeholder="<?= htmlspecialchars($secret_state('config_teams_app_secret')) ?>">
                    <?php if ($cfg->rawSecret('config_teams_app_secret') !== '') { ?><div class="form-check mt-1"><input type="checkbox" class="form-check-input" name="teams_app_secret_clear" value="1" id="chatTeamsSecretClear"><label class="form-check-label small" for="chatTeamsSecretClear">Remove the stored secret</label></div><?php } ?>
                </div>
            </div>
            <div class="form-check form-switch chat-switch">
                <input type="checkbox" class="form-check-input" name="config_teams_link_by_email" value="1" id="chatTeamsEmail" <?= $chk($cfg->flag('config_teams_link_by_email')) ?>>
                <label class="form-check-label" for="chatTeamsEmail">Also match Teams users with no explicit link by their directory email</label>
                <small>Off by default and only works with a tenant ID set above.</small>
            </div>
        </div>
    </div>

    <div class="mb-4"><button type="submit" name="save_chat_settings" class="btn btn-primary"><i class="fas fa-check me-1"></i>Save</button></div>
</form>

<div class="card mb-3" id="identity-links">
    <div class="card-header py-3"><h3 class="card-title mb-0"><i class="fas fa-fw fa-link me-2"></i>Identity links</h3></div>
    <div class="card-body">
        <p class="text-secondary small">Link a Slack member ID or a Teams user (Microsoft Entra object ID) to an agent or a portal contact. A link always wins over the email match, and a link to a disabled agent means that person can do nothing in chat (it does not fall back to email).</p>
        <?php if ($links) { ?>
        <div class="table-responsive mb-3"><table class="table table-sm align-middle">
            <thead><tr><th>Platform</th><th>Chat user</th><th>Workspace / tenant</th><th>Linked to</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($links as $l) { ?>
            <tr>
                <td><?= $l['identity_platform'] === 'teams' ? 'Teams' : 'Slack' ?></td>
                <td><code><?= nullable_htmlentities($l['identity_external_id']) ?></code></td>
                <td><code><?= nullable_htmlentities($l['identity_team_id']) ?: 'any' ?></code></td>
                <td><?= (int) $l['identity_user_id'] > 0 ? 'Agent: ' . nullable_htmlentities($l['user_name'] ?? '(removed)') : 'Contact: ' . nullable_htmlentities($l['contact_name'] ?? '(removed)') ?></td>
                <td class="text-end">
                    <form action="post.php" method="post" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <input type="hidden" name="identity_id" value="<?= (int) $l['identity_id'] ?>">
                        <button type="submit" name="delete_chat_identity" class="btn btn-sm btn-outline-danger" aria-label="Remove link"><i class="fas fa-trash-alt"></i></button>
                    </form>
                </td>
            </tr>
            <?php } ?>
            </tbody>
        </table></div>
        <?php } else { ?><p class="text-muted">No links yet.</p><?php } ?>
        <form action="post.php" method="post" autocomplete="off" class="row g-2 align-items-end">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <div class="col-sm-3 col-md-2"><label class="form-label" for="idPlat">Platform</label><select class="form-select" name="identity_platform" id="idPlat"><option value="slack">Slack</option><option value="teams">Teams</option></select></div>
            <div class="col-sm-5 col-md-3"><label class="form-label" for="idExt">Member ID / Entra object ID</label><input type="text" class="form-control font-monospace" name="identity_external_id" id="idExt" required maxlength="128" placeholder="U01ABCDE23"></div>
            <div class="col-sm-4 col-md-2"><label class="form-label" for="idTeam">Workspace / tenant <small class="text-secondary">(optional)</small></label><input type="text" class="form-control font-monospace" name="identity_team_id" id="idTeam" maxlength="64"></div>
            <div class="col-sm-6 col-md-3"><label class="form-label" for="idUser">Agent</label><select class="form-select" name="identity_user_id" id="idUser"><option value="0">(none, use a contact)</option><?php foreach ($agents as $a) { ?><option value="<?= (int) $a['user_id'] ?>"><?= nullable_htmlentities($a['user_name']) ?> (<?= nullable_htmlentities($a['user_email']) ?>)</option><?php } ?></select></div>
            <div class="col-sm-6 col-md-3"><label class="form-label" for="idContact">or contact email</label><input type="email" class="form-control" name="identity_contact_email" id="idContact" maxlength="200" placeholder="person@client.example"></div>
            <div class="col-12 col-md-auto"><button type="submit" name="add_chat_identity" class="btn btn-primary"><i class="fas fa-plus me-1"></i>Add link</button></div>
        </form>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header py-3"><h3 class="card-title mb-0"><i class="fas fa-fw fa-file-download me-2"></i>App files</h3></div>
    <div class="card-body">
        <p class="text-secondary small">Generated from this install's address, so you do not have to type any URL into Slack or Teams.</p>
        <div class="row g-3">
            <div class="col-md-6">
                <h4 class="h6">Slack app manifest</h4>
                <p class="small text-secondary">In Slack: api.slack.com/apps &gt; Create New App &gt; From an app manifest &gt; paste the JSON. Then install the app and copy the signing secret and bot token above.</p>
                <form action="post.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <div class="form-check"><input type="checkbox" class="form-check-input" name="manifest_commands" value="1" id="mfCmd" checked><label class="form-check-label" for="mfCmd">Include the /ticket command and shortcuts</label></div>
                    <div class="form-check mb-2"><input type="checkbox" class="form-check-input" name="manifest_events" value="1" id="mfEv" checked><label class="form-check-label" for="mfEv">Include event subscriptions (two-way, virtual agent)</label></div>
                    <button type="submit" name="download_slack_manifest" class="btn btn-outline-primary btn-sm"><i class="fas fa-download me-1"></i>Download manifest (JSON)</button>
                </form>
            </div>
            <div class="col-md-6">
                <h4 class="h6">Teams app package</h4>
                <p class="small text-secondary">Upload in Teams (Apps &gt; Manage your apps &gt; Upload an app) or in the Teams admin center. Needs the Microsoft App ID saved above.</p>
                <form action="post.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <button type="submit" name="download_teams_package" class="btn btn-outline-primary btn-sm" <?= $cfg->text('config_teams_app_id') === '' ? 'disabled' : '' ?>><i class="fas fa-download me-1"></i>Download app package (.zip)</button>
                    <?php if ($cfg->text('config_teams_app_id') === '') { ?><span class="small text-muted ms-2">Save the App ID first.</span><?php } ?>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require_once "../includes/footer.php"; ?>
