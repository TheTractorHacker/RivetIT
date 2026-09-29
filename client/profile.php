<?php
/*
 * Client Portal
 * User profile
 */

header("Content-Security-Policy: default-src 'self'");

require_once 'includes/inc_all.php';

?>

    <h2>Profile</h2>

    <p>Name: <?php echo stripslashes(nullable_htmlentities($session_contact_name)); ?></p>
    <p>Email: <?php echo $session_contact_email ?></p>
    <p>PIN: <?php echo $session_contact_pin ?></p>
    <p>Department: <?php echo nullable_htmlentities($session_client_name) ?></p>
    <br>
    <p>Department Primary Contact: <?php if ($session_contact_primary == 1) {echo "Yes"; } else {echo "No";} ?></p>
    <p>Department Technical Contact: <?php if ($session_contact_is_technical_contact) {echo "Yes"; } else {echo "No";} ?></p>
    <p>Department Billing Contact: <?php if ($session_contact_is_billing_contact == $session_contact_id) {echo "Yes"; } else {echo "No";} ?></p>
    <br>
    <p>Login via: <?php echo nullable_htmlentities($_SESSION['login_method'] ?? 'Not signed in') ?> </p>
    <?php /* $session_user_id, not $_SESSION['user_id']: check_login.php deliberately sets the
       former to 0 during an admin portal preview so no real user id is exposed or targetable
       from inside the portal. Reading the superglobal bypassed that and printed the ADMIN's own
       agent user id on a page presented as the department's profile. */ ?>
    <p>User ID: <?php echo intval($session_user_id) ?> </p>


    <!--  // Show option to change password if auth provider is local -->
<?php /* ?? '': the agent login flow never sets login_method - only the client flows do - so in an
   admin portal preview this raised an undefined-key warning on every view. */ ?>
<?php if (($_SESSION['login_method'] ?? '') == 'local'): ?>
    <hr>
    <div class="col-md-6">
        <h4>Password</h4>
        <form action="post.php" method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <div class="form-group">
                <label>New Password</label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-lock"></i></span>
                    </div>
                    <input type="password" class="form-control" minlength="8" required data-toggle="password" name="new_password" placeholder="Leave blank for no change" autocomplete="new-password">
                </div>
            </div>
            <button type="submit" name="edit_profile" class="btn btn-primary text-bold mt-3"><i class="fas fa-check me-2"></i>Save password</button>
        </form>
    </div>
<?php endif ?>

<?php if (($_SESSION['login_method'] ?? '') == 'local' && !$portal_preview_active):
    require_once __DIR__ . '/../plugins/totp/totp.php';
    if (!$session_user_has_mfa && empty($_SESSION['portal_mfa_secret'])) {
        $_SESSION['portal_mfa_secret'] = key32gen();
    }
    $mfa_secret = (string) ($_SESSION['portal_mfa_secret'] ?? '');
    $mfa_uri = "otpauth://totp/" . rawurlencode(APP_NAME) . ":" . rawurlencode($session_contact_email) . "?secret=$mfa_secret&issuer=" . rawurlencode(APP_NAME);
?>
    <hr>
    <div class="col-md-6">
        <h4>Two-factor authentication</h4>
        <?php if ($session_user_has_mfa): ?>
            <p><i class="fas fa-lock text-success me-2"></i><strong>Enabled</strong> &mdash; you enter a code from your authenticator app each time you sign in.</p>
            <?php if ($session_user_force_mfa): ?>
                <p class="text-muted small">Your administrator requires two-factor authentication, so it cannot be turned off.</p>
            <?php else: ?>
                <form action="post.php" method="post" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <div class="form-group">
                        <label>Current password</label>
                        <input type="password" class="form-control" name="current_password" required autocomplete="current-password">
                    </div>
                    <button type="submit" name="disable_portal_mfa" class="btn btn-outline-danger mt-3"><i class="fas fa-unlock me-2"></i>Turn off 2FA</button>
                </form>
            <?php endif ?>
        <?php else: ?>
            <p><?php if ($session_user_force_mfa) { ?><strong>Required.</strong> Set up two-factor authentication to continue. <?php } ?>Scan the code with an authenticator app (Microsoft Authenticator, Google Authenticator, Authy), then enter the 6 digit code it shows.</p>
            <form action="post.php" method="post" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <p><img src="../plugins/barcode/barcode.php?f=png&amp;s=qr&amp;d=<?= rawurlencode($mfa_uri) ?>" alt="Authenticator QR code"></p>
                <p class="small text-muted">Can't scan? Enter this key in your app: <code><?= nullable_htmlentities($mfa_secret) ?></code></p>
                <div class="input-group mb-3">
                    <input type="text" class="form-control" inputmode="numeric" pattern="[0-9]*" minlength="6" maxlength="6" name="verify_code" placeholder="6 digit code" required>
                </div>
                <button type="submit" name="enable_portal_mfa" class="btn btn-primary"><i class="fas fa-check me-2"></i>Enable 2FA</button>
            </form>
        <?php endif ?>
    </div>
<?php endif ?>

<?php
require_once 'includes/footer.php';
