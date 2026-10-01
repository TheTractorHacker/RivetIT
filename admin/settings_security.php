<?php
require_once "includes/inc_all_admin.php";

$vault_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_vault_canonical_key, config_vault_canonical_key_set_at FROM settings WHERE company_id = 1"));
$vault_canonical_key_set = !empty($vault_row['config_vault_canonical_key']);
$vault_canonical_key_set_at = $vault_row['config_vault_canonical_key_set_at'] ?? null;

$net_row = null;
try {
    $net_res = mysqli_query($mysqli, "SELECT config_proxy_hops, config_behind_cloudflare FROM settings WHERE company_id = 1");
    $net_row = $net_res ? mysqli_fetch_assoc($net_res) : null;
} catch (\Throwable $e) {
    // database older than 2.6.106: the network path fields are hidden until it is updated
}
$net_ready = is_array($net_row);
$net_hops = (!$net_ready || $net_row['config_proxy_hops'] === null) ? '' : (string) intval($net_row['config_proxy_hops']);
$net_cf = $net_ready && intval($net_row['config_behind_cloudflare']) === 1;
$net_peer = $_SERVER['REMOTE_ADDR'] ?? '';
$net_xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
$net_cfip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
$net_detected = getIP();
$net_looks_proxied = ($net_xff !== '' || $net_cfip !== '');

$vault_unsynced_users = intval(mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(*) AS c FROM users WHERE user_status = 1 AND (user_specific_encryption_ciphertext IS NULL OR user_specific_encryption_ciphertext = '')"))['c']);

?>

<div class="card">
    <div class="card-header py-3">
        <h3 class="card-title"><i class="fas fa-fw fa-key me-2"></i>Vault Encryption</h3>
    </div>
    <div class="card-body">

        <p class="text-muted">
            The credential vault is protected by a single shared encryption key. A canonical copy of
            this key is stored here (encrypted) so that user accounts which lose their personal copy
            (e.g. an archived/reactivated user) can automatically re-sync to the correct key on their
            next login, instead of being issued a new, incompatible one.
        </p>

        <?php if ($vault_canonical_key_set) { ?>
        <p>
            <i class="fas fa-fw fa-check-circle text-success me-1"></i>
            Canonical vault key established<?php if ($vault_canonical_key_set_at) { ?> on <?php echo nullable_htmlentities($vault_canonical_key_set_at); ?><?php } ?>.
        </p>
        <?php } else { ?>
        <div class="alert alert-warning">
            <div>
                <h4 class="alert-title"><i class="fas fa-fw fa-exclamation-circle me-1"></i>No canonical vault key has been established yet</h4>
                <p class="mb-2">
                    Until you establish one, <strong>signing in with a passkey cannot open the credential
                    vault</strong>. The vault key is wrapped with your password, and a passkey has no password
                    to unwrap it with, so a passkey sign-in can only reach the vault when this browser still
                    holds the encryption cookie from an earlier password sign-in. On a new device, after
                    clearing cookies, or once that cookie expires, the vault shows
                    &ldquo;locked &mdash; sign in with your password&rdquo;.
                </p>
                <p class="mb-0">
                    Establishing the canonical key stores one copy of the vault key that a passkey sign-in can
                    recover. Do it now, while this session is unlocked, with the button below &mdash; it reads
                    the key from your current session, so it only works when you signed in with your password.
                </p>
            </div>
        </div>
        <?php } ?>

        <?php if ($vault_unsynced_users > 0) { ?>
        <p class="text-muted">
            <?php echo $vault_unsynced_users; ?> active user(s) currently have no vault key of their own
            and will automatically re-sync to the canonical key on their next login.
        </p>
        <?php } ?>

        <form action="post.php" method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">
            <button type="submit" name="establish_canonical_vault_key" class="btn btn-secondary confirm-link">
                <i class="fas fa-key me-2"></i><?php echo $vault_canonical_key_set ? "Re-establish from my session" : "Establish from my session"; ?>
            </button>
        </form>

    </div>
</div>

<div class="card">
    <div class="card-header py-3">
        <h3 class="card-title"><i class="fas fa-fw fa-shield-alt me-2"></i>Security</h3>
    </div>
    <div class="card-body">
        <form action="post.php" method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">

            <div class="form-group">
                <label>Login Message</label>
                <textarea class="form-control" name="config_login_message" rows="5" placeholder="Enter a message to be displayed on the login screen"><?php echo nullable_htmlentities($config_login_message); ?></textarea>
            </div>

            <div class="form-group">
                <div class="form-check form-check form-switch">
                    <input type="checkbox" class="form-check-input" name="config_login_key_required" <?php if ($config_login_key_required == 1) { echo "checked"; } ?> value="1" id="customSwitch1">
                    <label class="form-check-label" for="customSwitch1">Require a login key to access the technician login page?</label>
                </div>
            </div>

            <div class="form-group">
                <label>Login key secret value <small class="text-secondary">(This must be provided in the URL as /login.php?key=<?php echo nullable_htmlentities($config_login_key_secret)?>)</small></label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-key"></i></span>
                    </div>
                    <input type="text" class="form-control" name="config_login_key_secret" pattern="\w{3,99}" placeholder="Something really easy for techs to remember: e.g. MYSECRET" value="<?php echo nullable_htmlentities($config_login_key_secret); ?>">
                </div>
            </div>

            <div class="form-group">
                <label>2FA Remember Me Expire <small class="text-secondary">(Days before a device 2FA remember-me token expires &mdash; 30 days minimum)</small></label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-clock"></i></span>
                    </div>
                    <input type="number" class="form-control" name="config_login_remember_me_expire" min="30" placeholder="Days (30 minimum)" value="<?php echo intval($config_login_remember_me_expire); ?>">
                </div>
            </div>

            <div class="form-group">
                <label>Session Lifetime <small class="text-secondary">(Minutes before re-login is required &mdash; 30-day minimum: 43200 = 30 days, 129600 = 90 days)</small></label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-hourglass-half"></i></span>
                    </div>
                    <input type="number" class="form-control" name="config_login_session_lifetime" min="43200" max="129600" placeholder="Minutes (43200 = 30 days)" value="<?php echo intval($config_login_session_lifetime); ?>">
                    <div class="input-group-append">
                        <span class="input-group-text">minutes</span>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label>Log retention <small class="text-secondary">(The amount of days before app/audit/auth logs are deleted during nightly cron)</small></label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-clock"></i></span>
                    </div>
                    <input type="number" class="form-control" name="config_log_retention" placeholder="Enter days to retain" value="<?php echo intval($config_log_retention); ?>">
                </div>
            </div>

            <?php if ($net_ready) { ?>
            <hr>

            <h5 class="mb-3"><i class="fas fa-fw fa-network-wired me-2"></i>Network path <small class="text-secondary">(what sits in front of this app)</small></h5>

            <div class="form-group">
                <label>Reverse proxies on your side <small class="text-secondary">(nginx, HAProxy, a load balancer, etc. between the internet or Cloudflare and this server &mdash; not counting Cloudflare)</small></label>
                <select class="form-control" name="config_proxy_hops">
                    <option value="" <?php if ($net_hops === '') { echo "selected"; } ?>>Not set (legacy detection, uses config.php)</option>
                    <option value="0" <?php if ($net_hops === '0') { echo "selected"; } ?>>0 &mdash; none, users connect straight to this server</option>
                    <?php for ($i = 1; $i <= 5; $i++) { ?>
                    <option value="<?php echo $i; ?>" <?php if ($net_hops === (string) $i) { echo "selected"; } ?>><?php echo $i; ?> &mdash; <?php echo $i === 1 ? "one proxy" : "$i proxies in a row"; ?></option>
                    <?php } ?>
                </select>
            </div>

            <div class="form-group">
                <div class="form-check form-switch">
                    <input type="checkbox" class="form-check-input" name="config_behind_cloudflare" id="netCloudflare" value="1" <?php if ($net_cf) { echo "checked"; } ?>>
                    <label class="form-check-label" for="netCloudflare">Traffic comes through Cloudflare</label>
                </div>
            </div>

            <div class="alert alert-info">
                <div class="fw-bold mb-1"><i class="fas fa-fw fa-search me-1"></i>Self-check (this request)</div>
                <table class="table table-sm table-borderless mb-2" style="max-width:640px;">
                    <tr><td class="text-secondary">Direct connection (REMOTE_ADDR)</td><td><code><?php echo nullable_htmlentities($net_peer); ?></code></td></tr>
                    <tr><td class="text-secondary">X-Forwarded-For</td><td><code><?php echo $net_xff !== '' ? nullable_htmlentities($net_xff) : '(none)'; ?></code></td></tr>
                    <tr><td class="text-secondary">CF-Connecting-IP</td><td><code><?php echo $net_cfip !== '' ? nullable_htmlentities($net_cfip) : '(none)'; ?></code></td></tr>
                    <tr><td class="text-secondary">Client address the app records</td><td><code class="fw-bold"><?php echo nullable_htmlentities($net_detected); ?></code></td></tr>
                </table>
                <?php if ($net_hops === '' && !$net_cf && $net_looks_proxied) { ?>
                    <div>Forwarding headers are arriving but no proxy setup is saved, so the app is probably recording the proxy&rsquo;s address instead of yours. Set the values above and check that &ldquo;Client address the app records&rdquo; becomes your own public IP.</div>
                <?php } elseif ($net_hops !== '' || $net_cf) { ?>
                    <div>If the last row is not your own IP, the counts above do not match your setup (or the direct connection is not a proxy address this app trusts, so the forwarded headers were ignored on purpose).</div>
                <?php } else { ?>
                    <div>No forwarding headers seen; the app records the direct connection address.</div>
                <?php } ?>
            </div>
            <?php } ?>

            <hr>

            <button type="submit" name="edit_security_settings" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Save</button>

        </form>
    </div>
</div>

<?php
require_once "../includes/footer.php";

