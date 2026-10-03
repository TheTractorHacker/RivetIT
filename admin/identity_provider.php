<?php
require_once "includes/inc_all_admin.php";
 ?>

<div class="card card-dark">
    <div class="card-header py-3">
        <h3 class="card-title"><i class="fas fa-fw fa-fingerprint me-2"></i>Identity Providers</h3>
    </div>
    <div class="card-body">
        <form action="post.php" method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">

            <h4>Department Portal SSO via Microsoft Entra</h4>

            <div class="form-group">
                <label>MS Entra OAuth App (Client) ID</label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-user"></i></span>
                    </div>
                    <input type="text" class="form-control" name="azure_client_id" placeholder="e721e3b6-01d6-50e8-7f22-c84d951a52e7" value="<?php echo nullable_htmlentities($config_azure_client_id); ?>">
                </div>
            </div>

            <div class="form-group">
                <label>MS Entra OAuth Secret</label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-key"></i></span>
                    </div>
                    <input type="password" class="form-control" name="azure_client_secret" placeholder="<?= $config_azure_client_secret !== '' ? 'Saved — leave blank to keep' : 'Client secret' ?>" autocomplete="new-password">
                </div>
            </div>

            <hr>

            <button type="submit" name="edit_identity_provider" class="btn btn-primary text-bold"><i class="fa fa-check me-2"></i>Save</button>

        </form>

        <hr class="my-4">
        <form action="post.php" method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <h4>OpenID Connect for Department Portal</h4>
            <p class="text-muted">Connect Authentik, Keycloak, or Ory Hydra. Register <code>https://<?= nullable_htmlentities($config_base_url) ?>/client/login_oidc.php</code> as the exact redirect URI. Link each Department login to its provider subject in Administration &gt; Users &gt; Department logins (or leave the subject blank and use first sign-in linking below).</p>
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" id="oidc_enabled" name="oidc_enabled" value="1" <?= $config_oidc_enabled ? 'checked' : '' ?>>
                <label class="form-check-label" for="oidc_enabled">Enable company SSO sign-in</label>
            </div>
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" id="oidc_link_by_email" name="oidc_link_by_email" value="1" <?= $config_oidc_link_by_email ? 'checked' : '' ?>>
                <label class="form-check-label" for="oidc_link_by_email">Link logins on first sign-in by verified email</label>
                <small class="form-text text-muted d-block">Optional. For a Department login set to OpenID Connect with the subject left blank, the first sign-in is linked when the provider reports <code>email_verified: true</code> and the email matches that login exactly. The subject is stored and used from then on, so later email changes never matter. Only turn this on if the provider verifies every email address and does not allow self-signup with arbitrary addresses.</small>
            </div>
            <div class="form-group">
                <label for="oidc_issuer">Issuer URL</label>
                <input class="form-control" type="url" id="oidc_issuer" name="oidc_issuer" value="<?= nullable_htmlentities($config_oidc_issuer) ?>" placeholder="https://login.example.org/application/o/rivetit" maxlength="255">
                <small class="form-text text-muted">Use the exact issuer from the provider's OpenID discovery document. HTTPS is required.</small>
            </div>
            <div class="form-group">
                <label for="oidc_client_id">Client ID</label>
                <input class="form-control" id="oidc_client_id" name="oidc_client_id" value="<?= nullable_htmlentities($config_oidc_client_id) ?>" maxlength="255">
            </div>
            <div class="form-group">
                <label for="oidc_client_secret">Client secret</label>
                <input class="form-control" type="password" id="oidc_client_secret" name="oidc_client_secret" autocomplete="new-password" placeholder="<?= $config_oidc_secret_saved ? 'Saved — leave blank to keep' : 'Client secret' ?>">
            </div>
            <button type="submit" name="edit_oidc_provider" class="btn btn-primary"><i class="fa fa-check me-2"></i>Save OpenID Connect</button>
        </form>
    </div>
</div>

<?php require_once "../includes/footer.php";
