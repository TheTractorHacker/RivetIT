<?php
/*
 * Confirms disabling MFA with a step-up check - the current TOTP code or a recovery code - instead of trusting
 * the session alone. Without this, anyone who rides an already-authenticated session (a stolen session cookie,
 * or a CSRF token leaked some other way) could turn MFA off permanently with nothing more than that session,
 * after which a leaked password alone would be enough for a fresh login. Same action (disable_mfa), same
 * post.php handler - it now also requires current_code to verify before it touches user_token.
 */
?>
<div class="modal" id="disableMFAModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-dark">
                <h5 class="modal-title"><i class="fa fa-fw fa-unlock me-2"></i>Disable Multi-Factor Authentication</h5>
                <button type="button" class="close text-white" data-bs-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <form action="post.php" method="post" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">
                <div class="modal-body">
                    <p class="text-muted small">To turn MFA off, confirm with your authenticator app's current code, or one of your recovery codes.</p>
                    <div class="form-group">
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text"><i class="fa fa-fw fa-lock"></i></span>
                            </div>
                            <input type="text" class="form-control" name="current_code" placeholder="6 digit code or recovery code" required autofocus>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" name="disable_mfa" class="btn btn-outline-danger text-bold"><i class="fa fa-unlock me-2"></i>Disable MFA</button>
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>
