<?php
require_once "includes/inc_all_admin.php";

$vault_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_vault_canonical_key, config_vault_canonical_key_set_at FROM settings WHERE company_id = 1"));
$vault_canonical_key_set = !empty($vault_row['config_vault_canonical_key']);
$vault_canonical_key_set_at = $vault_row['config_vault_canonical_key_set_at'] ?? null;

$vault_unsynced_users = intval(mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(*) AS c FROM users WHERE user_status = 1 AND (user_specific_encryption_ciphertext IS NULL OR user_specific_encryption_ciphertext = '')"))['c']);

?>

<div class="card card-dark">
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
        <p>
            <i class="fas fa-fw fa-exclamation-circle text-warning me-1"></i>
            No canonical vault key has been established yet.
        </p>
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

<div class="card card-dark">
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
                <label>2FA Remember Me Expire <small class="text-secondary">(The amount of days before a device 2FA remember me token will expire)</small></label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-clock"></i></span>
                    </div>
                    <input type="number" class="form-control" name="config_login_remember_me_expire" placeholder="Enter Days to Expire" value="<?php echo intval($config_login_remember_me_expire); ?>">
                </div>
            </div>

            <div class="form-group">
                <label>Session Lifetime <small class="text-secondary">(Minutes of inactivity before re-login is required &mdash; 480 = 8 hrs, 43200 = 30 days)</small></label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-hourglass-half"></i></span>
                    </div>
                    <input type="number" class="form-control" name="config_login_session_lifetime" min="30" max="43200" placeholder="Minutes (e.g. 480)" value="<?php echo intval($config_login_session_lifetime); ?>">
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

            <hr>

            <button type="submit" name="edit_security_settings" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Save</button>

        </form>
    </div>
</div>

<?php
require_once "../includes/footer.php";

