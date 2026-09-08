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

<?php
require_once 'includes/footer.php';
