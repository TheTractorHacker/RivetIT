<?php
require_once "includes/inc_all_admin.php";

use ITFlow\Mcp\McpConfig;
use ITFlow\Mcp\McpIdentityLinks;

$mcp = McpConfig::load($mysqli);
$mcp_url = 'https://' . $config_base_url . '/mcp';
$tables_ready = false;
try { $tables_ready = (bool) $mysqli->query("SHOW TABLES LIKE 'mcp_unlinked_identities'")->fetch_row(); } catch (Throwable $e) {}

$mcp_state = !$mcp['schema_ready'] || !$tables_ready ? ['Update needed', 'warning']
    : (!$mcp['enabled'] ? ['Off', 'secondary']
    : (!$mcp['configured'] ? ['Needs setup', 'warning'] : ['On', 'success']));

$checks = $_SESSION['mcp_checks'] ?? null;
$pending = $tables_ready ? McpIdentityLinks::pending($mysqli) : [];
$linkable = $tables_ready ? McpIdentityLinks::linkableAgents($mysqli) : [];
$linked = McpIdentityLinks::linkedAgents($mysqli);
$activity = $mysqli->query("SELECT created_at, actor_user_id, summary, request_id FROM audit_events
    WHERE event_type = 'mcp.tool_call' ORDER BY audit_id DESC LIMIT 15")->fetch_all(MYSQLI_ASSOC);
$badge = ['ok' => 'success', 'warn' => 'warning', 'fail' => 'danger', 'skip' => 'secondary'];
$csrf = $_SESSION['csrf_token'];
?>

<div class="card mb-3">
    <div class="card-header py-3 d-flex align-items-center justify-content-between">
        <h3 class="card-title mb-0"><i class="fas fa-fw fa-plug-circle-bolt me-2"></i>Remote MCP <span class="badge bg-warning text-dark ms-1">Experimental</span></h3>
        <span class="badge bg-<?= $mcp_state[1] ?> fs-6"><?= $mcp_state[0] ?></span>
    </div>
    <div class="card-body">
        <p class="text-muted mb-3">Lets AI tools such as Claude read RivetIT (tickets, assets, contacts, knowledge base) as one of your agents, with that agent's own permissions. It is read-only. People sign in through your identity provider, for example Authentik.</p>

        <?php if (!$mcp['schema_ready'] || !$tables_ready) { ?>
            <div class="alert alert-warning mb-0">Run the database update first (Administration &rarr; Updates), then reload this page.</div>
        <?php } else { ?>

        <?php if ($mcp['killed']) { ?>
            <div class="alert alert-danger">The server has <code>RIVETIT_MCP_ENABLED=0</code> set, which keeps Remote MCP off whatever this page says.</div>
        <?php } ?>

        <form action="post.php" method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <div class="form-check form-switch mb-3">
                <input type="checkbox" class="form-check-input" id="mcp_enabled" name="mcp_enabled" value="1" <?= $mcp['module_on'] ? 'checked' : '' ?>>
                <label class="form-check-label" for="mcp_enabled">Turn on Remote MCP</label>
            </div>
            <div class="row g-3">
                <div class="col-lg-7">
                    <label for="mcp_issuer" class="form-label">Issuer URL</label>
                    <input type="url" class="form-control" id="mcp_issuer" name="mcp_issuer" maxlength="255" value="<?= nullable_htmlentities($mcp['issuer']) ?>" <?= $mcp['issuer_from_env'] ? 'readonly' : '' ?> placeholder="https://auth.example.com/application/o/rivetit-mcp/">
                    <div class="form-text"><?= $mcp['issuer_from_env'] ? 'Set by the server (RIVETIT_MCP_ISSUER).' : 'Copy it exactly from your provider, including any trailing slash. Authentik: the issuer in the application\'s OpenID configuration.' ?></div>
                </div>
                <div class="col-lg-5">
                    <label for="mcp_audience" class="form-label">Audience</label>
                    <input type="text" class="form-control" id="mcp_audience" name="mcp_audience" maxlength="255" value="<?= nullable_htmlentities($mcp['audience']) ?>" <?= $mcp['audience_from_env'] ? 'readonly' : '' ?>>
                    <div class="form-text"><?= $mcp['audience_from_env'] ? 'Set by the server (RIVETIT_MCP_AUDIENCE).' : 'Authentik: the provider\'s Client ID.' ?></div>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2 mt-3">
                <button type="submit" name="save_mcp_settings" class="btn btn-primary"><i class="fa fa-check me-2"></i>Save</button>
                <button type="submit" name="run_mcp_checks" class="btn btn-outline-secondary"><i class="fa fa-stethoscope me-2"></i>Save and run checks</button>
            </div>
        </form>

        <hr>
        <label class="form-label" for="mcp_url">Address to give your MCP client</label>
        <div class="input-group" style="max-width:36rem">
            <input type="text" class="form-control" id="mcp_url" value="<?= nullable_htmlentities($mcp_url) ?>" readonly>
            <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('mcp_url').value).then(()=>{this.textContent='Copied'},()=>{document.getElementById('mcp_url').select()})">Copy</button>
        </div>
        <?php } ?>
    </div>
</div>

<?php if ($mcp['schema_ready'] && $tables_ready) { ?>

<div class="card mb-3">
    <div class="card-header py-3"><h4 class="card-title mb-0"><i class="fas fa-fw fa-stethoscope me-2"></i>Health checks</h4></div>
    <div class="card-body">
        <?php if (!$checks) { ?>
            <p class="text-muted mb-0">Press <strong>Save and run checks</strong> above. It contacts your identity provider and this server's own address, and takes a few seconds.</p>
        <?php } else { ?>
            <p class="text-muted small">Last run <?= nullable_htmlentities(date('Y-m-d H:i:s', (int) $checks['at'])) ?>.</p>
            <ul class="list-group list-group-flush">
            <?php foreach ($checks['checks'] as $c) { ?>
                <li class="list-group-item px-0 d-flex gap-3">
                    <span class="badge bg-<?= $badge[$c['status']] ?? 'secondary' ?> align-self-start" style="min-width:3.2rem"><?= nullable_htmlentities(strtoupper($c['status'])) ?></span>
                    <div><strong><?= nullable_htmlentities($c['label']) ?></strong><div class="text-muted small"><?= nullable_htmlentities($c['detail']) ?></div></div>
                </li>
            <?php } ?>
            </ul>
        <?php } ?>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header py-3"><h4 class="card-title mb-0"><i class="fas fa-fw fa-user-clock me-2"></i>People who tried to connect <span class="badge bg-secondary"><?= count($pending) ?></span></h4></div>
    <div class="card-body">
        <?php if (!$pending) { ?>
            <p class="text-muted mb-0">Nobody is waiting. When someone signs in from an MCP client but is not linked to an agent yet, they appear here and you link them in one click. Their sign-in has already been verified by your identity provider.</p>
        <?php } else { ?>
            <div class="table-responsive"><table class="table align-middle mb-0">
                <thead><tr><th>Person</th><th>Last tried</th><th>Link to agent</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($pending as $p) { ?>
                    <tr>
                        <td><strong><?= nullable_htmlentities($p['display_name'] ?: 'Unnamed') ?></strong><div class="text-muted small"><?= nullable_htmlentities($p['email'] ?: 'no email in token') ?></div>
                            <div class="text-muted small text-break">ID <?= nullable_htmlentities($p['subject']) ?></div></td>
                        <td class="small"><?= nullable_htmlentities($p['last_seen_at']) ?><div class="text-muted"><?= (int) $p['attempts'] ?> attempt(s)</div></td>
                        <td>
                            <form action="post.php" method="post" class="d-flex gap-2">
                                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                <input type="hidden" name="pending_id" value="<?= (int) $p['mcp_unlinked_id'] ?>">
                                <select name="user_id" class="form-select form-select-sm" required aria-label="Agent to link">
                                    <option value="">Choose agent&hellip;</option>
                                    <?php foreach ($linkable as $a) { ?>
                                        <option value="<?= (int) $a['user_id'] ?>"><?= nullable_htmlentities($a['user_name']) ?> (<?= nullable_htmlentities($a['user_email']) ?>)</option>
                                    <?php } ?>
                                </select>
                                <button type="submit" name="link_mcp_identity" class="btn btn-sm btn-primary">Link</button>
                            </form>
                        </td>
                        <td>
                            <form action="post.php" method="post">
                                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                <input type="hidden" name="pending_id" value="<?= (int) $p['mcp_unlinked_id'] ?>">
                                <button type="submit" name="dismiss_mcp_identity" class="btn btn-sm btn-outline-secondary">Dismiss</button>
                            </form>
                        </td>
                    </tr>
                <?php } ?>
                </tbody></table></div>
            <p class="form-text mt-2 mb-0">Nothing is preselected. Pick the agent yourself, and check it is the right person before you press Link: the email shown comes from the sign-in provider and is only a hint.</p>
        <?php } ?>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header py-3"><h4 class="card-title mb-0"><i class="fas fa-fw fa-link me-2"></i>Linked agents <span class="badge bg-secondary"><?= count($linked) ?></span></h4></div>
    <div class="card-body">
        <?php if (!$linked) { ?>
            <p class="text-muted mb-0">No agent is linked yet.</p>
        <?php } else { ?>
            <div class="table-responsive"><table class="table align-middle mb-0">
                <thead><tr><th>Agent</th><th>Identity</th><th></th></tr></thead><tbody>
                <?php foreach ($linked as $l) { ?>
                    <tr>
                        <td><strong><?= nullable_htmlentities($l['user_name']) ?></strong>
                            <?php if (!$l['user_status'] || $l['user_archived_at']) { ?><span class="badge bg-secondary ms-1">Disabled</span><?php } ?>
                            <div class="text-muted small"><?= nullable_htmlentities($l['user_email']) ?></div></td>
                        <td class="small text-break text-muted"><?= nullable_htmlentities($l['user_oidc_subject']) ?></td>
                        <td class="text-end">
                            <form action="post.php" method="post" onsubmit="return confirm('Unlink this agent? Their MCP access stops immediately.')">
                                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                <input type="hidden" name="user_id" value="<?= (int) $l['user_id'] ?>">
                                <button type="submit" name="unlink_mcp_agent" class="btn btn-sm btn-outline-danger">Unlink</button>
                            </form>
                        </td>
                    </tr>
                <?php } ?>
                </tbody></table></div>
        <?php } ?>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header py-3"><h4 class="card-title mb-0"><i class="fas fa-fw fa-clock-rotate-left me-2"></i>Recent activity</h4></div>
    <div class="card-body">
        <?php if (!$activity) { ?>
            <p class="text-muted mb-0">No MCP calls yet.</p>
        <?php } else { ?>
            <div class="table-responsive"><table class="table table-sm mb-0">
                <thead><tr><th>When</th><th>Agent</th><th>Call</th></tr></thead><tbody>
                <?php foreach ($activity as $a) { ?>
                    <tr><td class="small text-nowrap"><?= nullable_htmlentities($a['created_at']) ?></td><td class="small">#<?= (int) $a['actor_user_id'] ?></td><td class="small"><?= nullable_htmlentities($a['summary']) ?></td></tr>
                <?php } ?>
                </tbody></table></div>
        <?php } ?>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header py-3"><h4 class="card-title mb-0"><i class="fas fa-fw fa-life-ring me-2"></i>Setup help</h4></div>
    <div class="card-body">
        <details class="mb-3"><summary class="fw-semibold">Authentik settings that matter</summary>
            <ul class="mt-2 mb-0">
                <li>Create a scope mapping with scope name <code>mcp:read</code> and select it on the provider.</li>
                <li>Client type <strong>Public</strong> (PKCE), with the redirect address your MCP client documents.</li>
                <li>Set a <strong>Signing Key</strong> (an RSA certificate). Without one, tokens are not accepted.</li>
                <li>Subject mode: <strong>Based on the User's UUID</strong>. Keep token validity at 60 minutes or less.</li>
                <li>Audience here is the provider's <strong>Client ID</strong>. Issuer mode: each provider has a different issuer.</li>
                <li>Your MCP client must let you enter a Client ID by hand, because Authentik does not offer automatic client registration.</li>
            </ul>
        </details>
        <details><summary class="fw-semibold">Web server setup (nginx)</summary>
            <p class="mt-2">If the health check says the address returns 404, add these two blocks to this site's nginx configuration, then test and reload nginx. Apache users need nothing: the bundled <code>.htaccess</code> already has the rules.</p>
            <div class="position-relative">
            <button type="button" class="btn btn-sm btn-outline-secondary position-absolute top-0 end-0 m-2" onclick="var el=document.getElementById('mcp-nginx');var b=this;navigator.clipboard.writeText(el.textContent).then(function(){b.textContent='Copied'},function(){var r=document.createRange();r.selectNodeContents(el);var s=getSelection();s.removeAllRanges();s.addRange(r);b.textContent='Selected'});">Copy</button>
            <pre id="mcp-nginx" class="border rounded p-3 pe-5 small mb-0" style="overflow-x:auto">location = /mcp {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root/mcp_server/index.php;
    fastcgi_param SCRIPT_NAME /mcp_server/index.php;
    fastcgi_pass unix:/run/php/php8.4-fpm.sock;   # use your site's PHP-FPM socket
    fastcgi_buffering off;
}
location = /.well-known/oauth-protected-resource {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root/mcp_server/index.php;
    fastcgi_param SCRIPT_NAME /mcp_server/index.php;
    fastcgi_pass unix:/run/php/php8.4-fpm.sock;
}</pre>
            </div>
        </details>
    </div>
</div>

<?php } ?>

<?php require_once "../includes/footer.php";
