<?php
/*
 * Client Portal
 * User profile
 */

header("Content-Security-Policy: default-src 'self'");

require_once 'includes/inc_all.php';

$profile_name = stripslashes(nullable_htmlentities($session_contact_name));
$profile_email = nullable_htmlentities($session_contact_email);
$profile_pin = (string) $session_contact_pin;
$profile_login = ($_SESSION['login_method'] ?? '') !== '' ? nullable_htmlentities($_SESSION['login_method']) : 'Not signed in';
$profile_is_local = ($_SESSION['login_method'] ?? '') == 'local';
// $session_user_id, not $_SESSION['user_id']: check_login.php zeroes the former during an admin preview so no real id is exposed.
?>

<div class="card portal-card mb-3">
    <div class="portal-profile-id">
        <?php if (!empty($session_contact_photo)) { ?>
            <img src="/uploads/clients/<?= intval($session_client_id) ?>/<?= nullable_htmlentities($session_contact_photo) ?>" alt="" class="portal-hero-avatar">
        <?php } else { ?>
            <span class="portal-hero-avatar" aria-hidden="true"><?php echo nullable_htmlentities($session_contact_initials); ?></span>
        <?php } ?>
        <div class="flex-grow-1">
            <h2 class="portal-profile-name"><?php echo $profile_name; ?></h2>
            <div class="text-secondary"><?php echo $profile_email; ?></div>
            <div class="portal-badges">
                <span class="portal-badge"><i class="fas fa-building" aria-hidden="true"></i><?php echo nullable_htmlentities($session_client_name); ?></span>
                <?php if ($session_contact_primary == 1) { ?><span class="portal-badge"><i class="fas fa-star" aria-hidden="true"></i>Primary contact</span><?php } ?>
                <?php if ($session_contact_is_technical_contact) { ?><span class="portal-badge"><i class="fas fa-wrench" aria-hidden="true"></i>Technical contact</span><?php } ?>
                <?php if ($session_contact_is_billing_contact == $session_contact_id) { ?><span class="portal-badge"><i class="fas fa-receipt" aria-hidden="true"></i>Billing contact</span><?php } ?>
                <span class="portal-badge portal-badge--muted"><i class="fas fa-sign-in-alt" aria-hidden="true"></i>Signed in via <?php echo $profile_login; ?></span>
            </div>
        </div>
    </div>
</div>

<div class="portal-profile-grid portal-stagger">

    <div class="card portal-card">
        <div class="card-header"><h3 class="card-title"><span class="portal-card-chip"><i class="fas fa-id-card" aria-hidden="true"></i></span>Your details</h3></div>
        <div class="card-body">
            <dl class="portal-dl">
                <div><dt>Name</dt><dd><?php echo $profile_name; ?></dd></div>
                <div><dt>Email</dt><dd><?php echo $profile_email; ?></dd></div>
                <div><dt>Department</dt><dd><?php echo nullable_htmlentities($session_client_name); ?></dd></div>
                <div>
                    <dt>Training PIN</dt>
                    <dd>
                        <?php if ($profile_pin !== '') { ?>
                            <span class="portal-secret" data-portal-secret>
                                <span data-portal-secret-value="<?php echo nullable_htmlentities($profile_pin); ?>">&bull;&bull;&bull;&bull;</span>
                                <button type="button" aria-label="Show or hide PIN"><i class="fas fa-eye" aria-hidden="true"></i></button>
                            </span>
                        <?php } else { ?>
                            <span class="text-secondary">Not set</span>
                        <?php } ?>
                    </dd>
                </div>
                <div><dt>User ID</dt><dd><?php echo intval($session_user_id); ?></dd></div>
            </dl>
        </div>
    </div>

    <?php if (!empty($portal_lms_ok)) { ?>
        <div class="card portal-card">
            <div class="card-header"><h3 class="card-title"><span class="portal-card-chip"><i class="fas fa-graduation-cap" aria-hidden="true"></i></span>Training management</h3></div>
            <div class="card-body">
                <p class="text-secondary">Your login can open the full training module: courses, assignments, reports and sign-offs.</p>
                <a href="/agent/training_dashboard.php" class="btn btn-primary"><i class="fas fa-external-link-alt me-2" aria-hidden="true"></i>Open training management</a>
            </div>
        </div>
    <?php } ?>

    <?php if (!empty($portal_agent_home)) { ?>
        <div class="card portal-card">
            <div class="card-header"><h3 class="card-title"><span class="portal-card-chip"><i class="fas fa-briefcase" aria-hidden="true"></i></span>Agent workspace</h3></div>
            <div class="card-body">
                <p class="text-secondary">Your login can open <?= nullable_htmlentities($portal_agent_home['label']) ?> in the agent app.</p>
                <a href="<?= nullable_htmlentities($portal_agent_home['url']) ?>" class="btn btn-primary"><i class="fas fa-external-link-alt me-2" aria-hidden="true"></i>Open <?= nullable_htmlentities($portal_agent_home['label']) ?></a>
            </div>
        </div>
    <?php } ?>

    <?php /* ?? '': the agent login flow never sets login_method, so an admin preview would otherwise warn on every view. */ ?>
    <?php if ($profile_is_local) { ?>
        <div class="card portal-card">
            <div class="card-header"><h3 class="card-title"><span class="portal-card-chip"><i class="fas fa-lock" aria-hidden="true"></i></span>Password</h3></div>
            <div class="card-body">
                <form action="post.php" method="post" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <label class="form-label" for="portal_new_password">New password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fa fa-fw fa-lock"></i></span>
                        <input type="password" class="form-control" id="portal_new_password" minlength="8" required data-toggle="password" name="new_password" placeholder="At least 8 characters" autocomplete="new-password">
                    </div>
                    <button type="submit" name="edit_profile" class="btn btn-primary mt-3"><i class="fas fa-check me-2"></i>Save password</button>
                </form>
            </div>
        </div>
    <?php } ?>

    <?php if ($profile_is_local && !$portal_preview_active) {
        require_once __DIR__ . '/../plugins/totp/totp.php';
        if (!$session_user_has_mfa && empty($_SESSION['portal_mfa_secret'])) {
            $_SESSION['portal_mfa_secret'] = key32gen();
        }
        $mfa_secret = (string) ($_SESSION['portal_mfa_secret'] ?? '');
        $mfa_uri = "otpauth://totp/" . rawurlencode(APP_NAME) . ":" . rawurlencode($session_contact_email) . "?secret=$mfa_secret&issuer=" . rawurlencode(APP_NAME);
    ?>
        <div class="card portal-card">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h3 class="card-title"><span class="portal-card-chip"><i class="fas fa-shield-alt" aria-hidden="true"></i></span>Two-factor authentication</h3>
                <?php if ($session_user_has_mfa) { ?>
                    <span class="portal-badge portal-badge--ok"><i class="fas fa-check" aria-hidden="true"></i>On</span>
                <?php } else { ?>
                    <span class="portal-badge portal-badge--muted">Off</span>
                <?php } ?>
            </div>
            <div class="card-body">
                <?php if ($session_user_has_mfa) { ?>
                    <p>You enter a code from your authenticator app each time you sign in.</p>
                    <?php if ($session_user_force_mfa) { ?>
                        <p class="text-muted small mb-0">Your administrator requires two-factor authentication, so it cannot be turned off.</p>
                    <?php } else { ?>
                        <form action="post.php" method="post" autocomplete="off">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <label class="form-label" for="portal_current_password">Current password</label>
                            <input type="password" class="form-control" id="portal_current_password" name="current_password" required autocomplete="current-password">
                            <button type="submit" name="disable_portal_mfa" class="btn btn-outline-danger mt-3"><i class="fas fa-unlock me-2"></i>Turn off 2FA</button>
                        </form>
                    <?php } ?>
                <?php } else { ?>
                    <p><?php if ($session_user_force_mfa) { ?><strong>Required.</strong> Set up two-factor authentication to continue. <?php } ?>Scan the code with an authenticator app (Microsoft Authenticator, Google Authenticator, Authy), then enter the 6 digit code it shows.</p>
                    <form action="post.php" method="post" autocomplete="off">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <p class="portal-qr"><img src="../plugins/barcode/barcode.php?f=png&amp;s=qr&amp;d=<?= rawurlencode($mfa_uri) ?>" alt="Authenticator QR code"></p>
                        <p class="small text-muted">Can't scan? Enter this key in your app: <code><?= nullable_htmlentities($mfa_secret) ?></code></p>
                        <input type="text" class="form-control mb-3" inputmode="numeric" pattern="[0-9]*" minlength="6" maxlength="6" name="verify_code" placeholder="6 digit code" required aria-label="6 digit code">
                        <button type="submit" name="enable_portal_mfa" class="btn btn-primary"><i class="fas fa-check me-2"></i>Enable 2FA</button>
                    </form>
                <?php } ?>
            </div>
        </div>
    <?php } ?>

</div>

<?php
require_once 'includes/footer.php';
