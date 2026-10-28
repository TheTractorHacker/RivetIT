<?php

require_once "includes/inc_all_admin.php";

use ITFlow\Integration\IntegrationAuth;
use ITFlow\Oidc\KeyStore;
use ITFlow\Oidc\OidcAudit;
use ITFlow\Oidc\OidcConfig;

$sso_tables_ok = mysqli_num_rows(mysqli_query($mysqli, "SHOW TABLES LIKE 'oidc_provider_settings'")) > 0
    && mysqli_num_rows(mysqli_query($mysqli, "SHOW TABLES LIKE 'integration_clients'")) > 0;
$sso_issuer = OidcConfig::issuer();
$sso_cfg = $sso_tables_ok ? OidcConfig::load($mysqli) : null;
$sso_enabled_flag = false;
if ($sso_tables_ok) {
    $sso_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ops_enabled FROM oidc_provider_settings WHERE ops_id = 1"));
    $sso_enabled_flag = (int) ($sso_row['ops_enabled'] ?? 0) === 1;
}
?>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-id-badge me-2"></i>Single sign-on provider (OpenID Connect)</h3>
    </div>
    <div class="card-body">
        <p class="text-muted">RivetIT can sign people in to the Quality system (Datum) so they do not need a second password. A person who is signed in to the
            department portal presses <strong>Open Quality</strong> and lands in Datum already signed in. RivetIT only shares a stable person id (and, if you allow it,
            the display name). Setup steps and the checks Datum must make are in <code>docs/integrations/qms-sso.md</code>.</p>

        <?php if (!$sso_tables_ok) { ?>
            <div class="alert alert-warning mb-0">The database has not been updated for single sign-on yet (needs DB 2.6.202). Take a backup, then run <strong>Administration &gt; Update &gt; Update Database</strong>.</div>
        <?php } else { ?>

        <?php if ($sso_issuer === null) { ?>
            <div class="alert alert-danger">The server's base URL is not set or is not valid, so there is no issuer address. Single sign-on stays off until it is fixed.</div>
        <?php } ?>

        <form action="post.php" method="post" autocomplete="off" class="border rounded p-3 mb-4">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" role="switch" id="ssoEnabled" name="enabled" value="1"<?php echo $sso_enabled_flag ? ' checked' : ''; ?>>
                <label class="form-check-label" for="ssoEnabled"><strong>Single sign-on is on</strong>
                    <span class="text-muted">(the kill switch: off ends sign-ins and token exchanges at once and hides the portal button)</span></label>
            </div>
            <div class="row g-3">
                <div class="col-md-4"><label class="form-label">Longest portal sign-in age (minutes)</label>
                    <input type="number" class="form-control" name="max_auth_age" min="<?php echo OidcConfig::MAX_AGE_MIN; ?>" max="<?php echo OidcConfig::MAX_AGE_MAX; ?>" value="<?php echo intval($sso_cfg['max_auth_age_minutes']); ?>">
                    <div class="form-text">Older portal sessions are asked to sign in again before Quality opens (<?php echo OidcConfig::MAX_AGE_MIN; ?>-<?php echo OidcConfig::MAX_AGE_MAX; ?>; default 480 = 8 hours).</div></div>
                <div class="col-md-4"><label class="form-label">ID token lifetime (seconds)</label>
                    <input type="number" class="form-control" name="id_token_ttl" min="<?php echo OidcConfig::ID_TOKEN_TTL_MIN; ?>" max="<?php echo OidcConfig::ID_TOKEN_TTL_MAX; ?>" value="<?php echo intval($sso_cfg['id_token_ttl']); ?>">
                    <div class="form-text"><?php echo OidcConfig::ID_TOKEN_TTL_MIN; ?>-<?php echo OidcConfig::ID_TOKEN_TTL_MAX; ?>; default 300.</div></div>
                <div class="col-md-4"><label class="form-label">Access token lifetime (seconds)</label>
                    <input type="number" class="form-control" name="access_token_ttl" min="<?php echo OidcConfig::ACCESS_TOKEN_TTL_MIN; ?>" max="<?php echo OidcConfig::ACCESS_TOKEN_TTL_MAX; ?>" value="<?php echo intval($sso_cfg['access_token_ttl']); ?>">
                    <div class="form-text"><?php echo OidcConfig::ACCESS_TOKEN_TTL_MIN; ?>-<?php echo OidcConfig::ACCESS_TOKEN_TTL_MAX; ?>; default 300. No refresh tokens are issued. Authorization codes last 60 seconds and cannot be changed.</div></div>
            </div>
            <button class="btn btn-primary mt-3" name="save_sso_settings" value="1"><i class="fas fa-check me-2"></i>Save</button>
        </form>

        <h4 class="h6">Addresses to give the Quality system</h4>
        <?php if ($sso_issuer !== null) { ?>
        <div class="row g-2 mb-4">
            <div class="col-md-6"><label class="form-label small mb-0">Issuer</label>
                <input type="text" class="form-control" readonly value="<?php echo nullable_htmlentities($sso_issuer); ?>" onclick="this.select()"></div>
            <div class="col-md-6"><label class="form-label small mb-0">Discovery URL</label>
                <div class="input-group">
                    <input type="text" class="form-control" id="ssoDiscovery" readonly value="<?php echo nullable_htmlentities($sso_issuer . '/.well-known/openid-configuration'); ?>" onclick="this.select()">
                    <button type="button" class="btn btn-outline-secondary" onclick="var f=document.getElementById('ssoDiscovery');f.select();navigator.clipboard&&navigator.clipboard.writeText(f.value);"><i class="fas fa-copy"></i> Copy</button>
                </div></div>
        </div>
        <?php } ?>

        <h4 class="h6">Quality system registration</h4>
        <?php
        $sso_clients = [];
        $res = mysqli_query($mysqli, "SELECT c.client_id, c.client_uid, c.client_name, c.client_scopes, c.client_status, c.client_expires_at, g.ocfg_redirect_uris, g.ocfg_login_url, g.ocfg_allow_profile
            FROM integration_clients c LEFT JOIN oidc_client_config g ON g.ocfg_client_id = c.client_id WHERE c.client_status = 'active' ORDER BY c.client_name");
        while ($res && ($c = mysqli_fetch_assoc($res))) {
            if (in_array(IntegrationAuth::SSO_SCOPE, IntegrationAuth::parseScopes((string) $c['client_scopes']), true)) {
                $sso_clients[] = $c;
            }
        }
        if (!$sso_clients) { ?>
            <div class="alert alert-info">No client can sign people in yet. Create one under <a href="/admin/integration_clients.php">Integration clients</a> and tick the
                <code>sso</code> capability (add the LMS scopes only if the same system also needs the training API). Then come back to register its addresses.</div>
        <?php }
        foreach ($sso_clients as $c) { $cid = intval($c['client_id']); ?>
            <form action="post.php" method="post" autocomplete="off" class="border rounded p-3 mb-3">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="client_id" value="<?php echo $cid; ?>">
                <div class="mb-2"><strong><?php echo nullable_htmlentities($c['client_name']); ?></strong>
                    <span class="text-muted small ms-2">client id <code><?php echo nullable_htmlentities($c['client_uid']); ?></code></span>
                    <?php if (empty($c['ocfg_redirect_uris'])) { ?><span class="badge text-bg-warning ms-2">Not registered yet</span><?php } ?></div>
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label">Redirect URIs (one per line)</label>
                        <textarea class="form-control" name="redirect_uris" rows="3" maxlength="2000" placeholder="https://datum.example.com/auth/oidc/callback"><?php echo nullable_htmlentities((string) $c['ocfg_redirect_uris']); ?></textarea>
                        <div class="form-text">Exact addresses only: no wildcards, https required (http only for localhost). The return address in every sign-in request must match one of these character for character.</div></div>
                    <div class="col-md-6"><label class="form-label">Login address for the portal button</label>
                        <input type="url" class="form-control" name="login_url" maxlength="500" value="<?php echo nullable_htmlentities((string) $c['ocfg_login_url']); ?>" placeholder="https://datum.example.com/auth/oidc/login">
                        <div class="form-text">Where <strong>Open Quality</strong> sends the browser (the Quality system's own "start sign-in" address). Must be https. Leave blank to hide the button.</div>
                        <div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="allow_profile" value="1" id="ssoProf<?php echo $cid; ?>"<?php echo (int) $c['ocfg_allow_profile'] === 1 ? ' checked' : ''; ?>>
                            <label class="form-check-label" for="ssoProf<?php echo $cid; ?>">Also share the person's display name (<code>profile</code> scope). Email, phone and PIN are never shared.</label></div></div>
                </div>
                <button class="btn btn-primary btn-sm mt-3" name="save_sso_client" value="1">Save registration</button>
            </form>
        <?php } ?>

        <h4 class="h6 mt-4">Signing key</h4>
        <?php $sso_keys = KeyStore::status($mysqli); ?>
        <div class="border rounded p-3 mb-4">
            <?php if ($sso_keys['active_kid'] === null) { ?>
                <p class="mb-2 text-muted">No signing key yet. One is created automatically the first time it is needed (or press Rotate).</p>
            <?php } else { ?>
                <p class="mb-1">Active key id: <code><?php echo nullable_htmlentities($sso_keys['active_kid']); ?></code> (ES256), created <?php echo nullable_htmlentities((string) $sso_keys['active_since']); ?></p>
                <?php foreach ($sso_keys['retired'] as $k) { ?>
                    <p class="mb-1 text-muted small">Retired key <code><?php echo nullable_htmlentities($k['kid']); ?></code>: still published until <?php echo nullable_htmlentities($k['until']); ?> so tokens it signed stay verifiable.</p>
                <?php } ?>
            <?php } ?>
            <form action="post.php" method="post" class="mt-2">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">
                <button class="btn btn-outline-primary btn-sm" name="rotate_sso_key" value="1" onclick="return confirm('Create a new signing key? The current key stays published for 15 minutes so tokens already issued still verify.');"><i class="fas fa-sync-alt me-1"></i>Rotate signing key</button>
                <span class="text-muted small ms-2">The private key is stored encrypted and is never shown. The public key is at <code>/oidc/jwks</code>.</span>
            </form>
        </div>

        <h4 class="h6">Recent sign-in activity</h4>
        <div class="table-responsive">
            <table class="table table-sm table-striped mb-0">
                <thead class="text-muted"><tr><th>When</th><th>Event</th><th>Result</th><th>Reason</th><th>Person</th><th>Client</th><th>Address</th><th>Note</th></tr></thead>
                <tbody>
                <?php $sso_events = OidcAudit::recent($mysqli, 50);
                if (!$sso_events) { ?><tr><td colspan="8" class="text-muted">Nothing yet.</td></tr><?php }
                foreach ($sso_events as $e) { ?>
                    <tr>
                        <td class="text-nowrap"><?php echo nullable_htmlentities($e['oev_at']); ?></td>
                        <td><?php echo nullable_htmlentities($e['oev_type']); ?></td>
                        <td><?php echo $e['oev_result'] === 'ok' ? '<span class="badge text-bg-success">ok</span>' : ($e['oev_result'] === 'refused' ? '<span class="badge text-bg-danger">refused</span>' : '<span class="badge text-bg-secondary">' . nullable_htmlentities($e['oev_result']) . '</span>'); ?></td>
                        <td><code><?php echo nullable_htmlentities($e['oev_reason']); ?></code></td>
                        <td><?php echo $e['contact_name'] ? nullable_htmlentities($e['contact_name']) : ($e['oev_contact_id'] > 0 ? '#' . intval($e['oev_contact_id']) : ''); ?></td>
                        <td><?php echo nullable_htmlentities((string) $e['client_name']); ?></td>
                        <td><?php echo nullable_htmlentities($e['oev_ip']); ?></td>
                        <td class="small"><?php echo nullable_htmlentities($e['oev_detail']); ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
        <?php } ?>
    </div>
</div>

<?php
require_once "../includes/footer.php";
