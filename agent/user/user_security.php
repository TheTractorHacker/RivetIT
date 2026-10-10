<?php
require_once "includes/inc_all_user.php";
require_once "../../includes/security_policy.php";
require_once "../../includes/security_sessions.php";

$sec_min_password = secSettingInt('password_min_length');
$sec_new_codes = $_SESSION['new_recovery_codes'] ?? null;   // shown once, then forgotten
unset($_SESSION['new_recovery_codes']);
$sec_codes_left = !empty($session_token) ? secRecoveryCodesRemaining($mysqli, $session_user_id) : 0;
$sec_sessions = secSessionList($mysqli, $session_user_id);

$sql_api_tokens = mysqli_query($mysqli, "SELECT token_id, token_name, token_fcm_token, token_last_used_at, token_created_at FROM api_tokens WHERE token_user_id = $session_user_id ORDER BY token_created_at DESC");
$sql_remember_tokens = mysqli_query($mysqli, "SELECT * FROM remember_tokens WHERE remember_token_user_id = $session_user_id ORDER BY remember_token_created_at DESC");
$remember_token_count = mysqli_num_rows($sql_remember_tokens);

// Trainer PIN (2.6.104): only shown when THIS user is themselves an active trainer
// (training_trainers.trainer_user_id = their own user_id) - self only, never anyone else's.
$trainer_pin_row = \ITFlow\Training\Kiosk\Pin\TrainerCredentialRepo::loadByUserId($mysqli, $session_user_id);

/** The Training module stores trainer_pin_set_at_utc in UTC; timeAgo() expects a string in
 *  the app's local (default) timezone, like every other timestamp on this page. */
function tps_local(?string $utc): ?string
{
    if ($utc === null || $utc === '') {
        return null;
    }
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
}
?>

<div class="security-cards">

<!-- Password -->
<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title"><i class="fas fa-fw fa-lock me-2"></i>Password</h3>
    </div>
    <div class="card-body">
        <form action="post.php" method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <div class="form-group mb-3">
                <label>Current Password</label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-lock"></i></span>
                    </div>
                    <input type="password" class="form-control"
                           name="current_password" placeholder="Enter your current password"
                           autocomplete="current-password" required>
                </div>
            </div>
            <div class="form-group mb-3">
                <label>New Password</label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-lock"></i></span>
                    </div>
                    <input type="password" class="form-control" data-toggle="password"
                           name="new_password" placeholder="Leave blank for no change"
                           autocomplete="new-password" minlength="<?= $sec_min_password ?>" required>
                    <div class="input-group-append">
                        <span class="input-group-text" style="cursor:pointer;">
                            <i class="fa fa-fw fa-eye"></i>
                        </span>
                    </div>
                </div>
                <small class="text-muted">Minimum <?= $sec_min_password ?> characters, and not the same as your name or email address.</small>
            </div>
            <button type="submit" name="edit_your_user_password" class="btn btn-primary btn-sm">
                <i class="fas fa-check me-1"></i>Update Password
            </button>
        </form>
    </div>
</div>

<!-- Two-Factor Authentication -->
<div class="card card-dark">
    <div class="card-header py-2 d-flex align-items-center">
        <h3 class="card-title mr-auto"><i class="fas fa-fw fa-mobile-alt me-2"></i>Two-Factor Authentication</h3>
        <?php if (empty($session_token)) { ?>
            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#enableMFAModal">
                <i class="fas fa-lock me-1"></i>Enable MFA
            </button>
            <?php require_once "modals/user_mfa_modal.php"; ?>
        <?php } else { ?>
            <span class="badge text-bg-success me-2"><i class="fas fa-check me-1"></i>Enabled</span>
            <a href="post.php?disable_mfa&csrf_token=<?= $_SESSION['csrf_token'] ?>"
               class="btn btn-outline-danger btn-sm confirm-link">
                <i class="fas fa-unlock me-1"></i>Disable
            </a>
        <?php } ?>
    </div>
    <?php if (!empty($session_token)) { ?>
    <div class="card-body py-2">
        <p class="mb-0 text-muted small">TOTP authentication is active on your account. Use your authenticator app each time you sign in.</p>
    </div>

    <div class="card-body border-top py-2" id="recovery-codes">
        <h6 class="text-muted mb-2"><i class="fas fa-fw fa-life-ring me-1"></i>Recovery codes
            <span class="badge <?= $sec_codes_left > 3 ? 'text-bg-secondary' : 'text-bg-warning' ?>"><?= $sec_codes_left ?> left</span></h6>
        <?php if (is_array($sec_new_codes) && $sec_new_codes) { ?>
            <div class="alert alert-warning mb-2">
                <strong>Save these codes now. They are shown only once.</strong>
                Each code works one time in place of your authenticator code if you lose your phone. Store them somewhere safe, away from this device.
                <pre class="mb-0 mt-2 user-select-all" style="font-size:1.05rem;"><?= nullable_htmlentities(implode("\n", $sec_new_codes)) ?></pre>
            </div>
        <?php } else { ?>
            <p class="text-muted small mb-2">Single-use codes you can type at sign-in instead of an authenticator code. We only keep a hash of each, so they cannot be shown again; make a new set if you lose them.</p>
        <?php } ?>
        <form action="post.php" method="post" autocomplete="off" class="row g-2 align-items-end">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <div class="col-auto">
                <label class="small text-muted mb-1">Current password</label>
                <input type="password" class="form-control form-control-sm" name="current_password" autocomplete="current-password" required>
            </div>
            <div class="col-auto">
                <button type="submit" name="regenerate_recovery_codes" class="btn btn-outline-primary btn-sm">
                    <i class="fas fa-sync-alt me-1"></i>Make new recovery codes
                </button>
            </div>
            <div class="col-12"><small class="text-muted">Making new codes cancels all the old ones.</small></div>
        </form>
    </div>
    <?php } ?>

    <?php if ($remember_token_count > 0) { ?>
    <div class="card-body border-top py-2">
        <h6 class="text-muted mb-2"><i class="fas fa-fw fa-clock me-1"></i>Remember-Me Tokens <span class="badge text-bg-secondary"><?= $remember_token_count ?></span></h6>
        <div class="table-responsive">
            <table class="table table-sm table-borderless mb-2">
                <tbody>
                <?php while ($row = mysqli_fetch_assoc($sql_remember_tokens)) { ?>
                    <tr>
                        <td class="text-muted py-1" style="width:30px;"><i class="fas fa-key"></i></td>
                        <td class="py-1"><?= nullable_htmlentities($row['remember_token_created_at']) ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
        <form action="post.php" method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <button type="submit" name="revoke_your_2fa_remember_tokens" class="btn btn-outline-danger btn-sm">
                <i class="fas fa-times me-1"></i>Revoke All Tokens
            </button>
        </form>
    </div>
    <?php } ?>
</div>

<!-- Active sessions -->
<div class="card card-dark" id="active-sessions">
    <div class="card-header py-2 d-flex align-items-center">
        <h3 class="card-title mr-auto"><i class="fas fa-fw fa-laptop me-2"></i>Active sessions</h3>
        <form action="post.php" method="post" class="mb-0">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <button type="submit" name="sign_out_everywhere" class="btn btn-outline-danger btn-sm">
                <i class="fas fa-sign-out-alt me-1"></i>Sign out everywhere
            </button>
        </form>
    </div>
    <div class="card-body p-0">
        <table class="table table-sm table-borderless table-hover mb-0">
            <thead class="text-muted small"><tr class="border-bottom"><th class="ps-3">Browser</th><th>IP address</th><th>Signed in</th><th>Last active</th><th></th></tr></thead>
            <tbody>
            <?php if (!$sec_sessions) { ?>
                <tr><td colspan="5" class="text-muted text-center py-3">No tracked sessions yet.</td></tr>
            <?php } foreach ($sec_sessions as $sx) { ?>
                <tr>
                    <td class="ps-3 small"><?= nullable_htmlentities(mb_strimwidth((string) $sx['session_user_agent'], 0, 60, '...')) ?>
                        <?php if ($sx['is_current']) { ?><span class="badge text-bg-success ms-1">This browser</span><?php } ?></td>
                    <td class="text-muted small"><?= nullable_htmlentities($sx['session_ip']) ?></td>
                    <td class="text-muted small"><?= nullable_htmlentities($sx['session_created_at']) ?></td>
                    <td class="text-muted small"><?= timeAgo($sx['session_last_seen_at']) ?></td>
                    <td class="pe-3 text-end">
                        <?php if (!$sx['is_current']) { ?>
                        <form action="post.php" method="post" class="mb-0 d-inline">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="session_row_id" value="<?= intval($sx['session_row_id']) ?>">
                            <button type="submit" name="revoke_session" class="btn btn-sm btn-outline-danger"><i class="fas fa-times me-1"></i>Sign out</button>
                        </form>
                        <?php } ?>
                    </td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    </div>
</div>

<?php
/* Passkeys can only open the credential vault when a canonical vault key exists.
   The vault key is wrapped with the user's password and a passkey has no password
   to unwrap it with, so without a canonical copy a passkey sign-in reaches the
   vault only while this browser still holds the encryption cookie from an earlier
   password sign-in. Surfaced here because this is where passkeys are added - the
   admin Security page states the same thing, but someone adding a passkey would
   never see it there. */
$vault_canonical_ok = !empty(mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT config_vault_canonical_key FROM settings WHERE company_id = 1"
))['config_vault_canonical_key']);
?>
<!-- Passkeys -->
<div class="card card-dark">
    <div class="card-header py-2 d-flex align-items-center">
        <h3 class="card-title mr-auto"><i class="fas fa-fw fa-fingerprint me-2"></i>Passkeys</h3>
        <button class="btn btn-primary btn-sm js-passkey-register" id="addPasskeyBtn">
            <i class="fas fa-plus me-1"></i>Add Passkey
        </button>
    </div>
    <div class="card-body p-0">
        <?php if (!$vault_canonical_ok && lookupUserPermission('module_admin') >= 0): ?>
        <div class="alert alert-warning mb-3">
            <div>
                <h4 class="alert-title"><i class="fas fa-fw fa-exclamation-triangle me-1"></i>Passkeys cannot open the credential vault yet</h4>
                <p class="mb-0">
                    The vault key is wrapped with your password, and a passkey has no password to unwrap it
                    with. Until a canonical vault key is established, a passkey sign-in reaches the vault only
                    while this browser still holds the cookie from an earlier password sign-in &mdash; on a new
                    device or after clearing cookies it will show as locked. An administrator can fix this in
                    one click under <a href="/admin/settings_security.php">Settings &rsaquo; Security</a>,
                    while signed in with a password.
                </p>
            </div>
        </div>
        <?php endif; ?>
        <table class="table table-sm table-borderless table-hover mb-0" id="passkey-table">
            <thead class="text-muted small">
                <tr class="border-bottom">
                    <th class="ps-3">Name</th>
                    <th>Added</th>
                    <th>Last used</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php
            $sql_pk = mysqli_query($mysqli, "SELECT * FROM user_passkeys WHERE passkey_user_id = $session_user_id ORDER BY passkey_created_at DESC");
            if (mysqli_num_rows($sql_pk) == 0) { ?>
                <tr><td colspan="4" class="text-muted text-center py-3">
                    <i class="fas fa-fingerprint fa-lg mb-1 d-block text-secondary"></i>
                    No passkeys yet. Add one above.
                </td></tr>
            <?php } else {
                while ($pk = mysqli_fetch_assoc($sql_pk)) {
                    $pkid     = intval($pk['passkey_id']);
                    $pkname   = nullable_htmlentities($pk['passkey_name']);
                    $pkcreated= nullable_htmlentities($pk['passkey_created_at']);
                    $pklast   = $pk['passkey_last_used_at'] ? timeAgo($pk['passkey_last_used_at']) : '—';
                    ?>
                    <tr>
                        <td class="ps-3"><i class="fas fa-fingerprint me-2 text-primary"></i><?= $pkname ?></td>
                        <td class="text-muted small"><?= $pkcreated ?></td>
                        <td class="text-muted small"><?= $pklast ?></td>
                        <td class="pe-3 text-end">
                            <button class="btn btn-sm btn-outline-danger js-delete-passkey"
                                    data-passkey-id="<?= $pkid ?>" data-passkey-name="<?= htmlspecialchars($pkname, ENT_QUOTES) ?>">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                <?php }
            } ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Passkey Registration Modal -->
<div class="modal fade" id="passkeyAddModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title"><i class="fas fa-fingerprint me-2"></i>Add Passkey</h5>
                <button type="button" class="close text-white" data-bs-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body">
                <div id="passkey-modal-idle">
                    <p class="text-muted mb-3">Name this passkey so you can identify which device it belongs to.</p>
                    <div class="form-group">
                        <label>Passkey name</label>
                        <input type="text" class="form-control" id="passkeyNameInput"
                               placeholder='e.g. "MacBook Touch ID", "iPhone Face ID"' maxlength="200">
                    </div>
                </div>
                <div id="passkey-modal-waiting" class="text-center py-3" style="display:none;">
                    <i class="fas fa-fingerprint fa-3x text-primary mb-3" style="animation:pulse 1.2s infinite;"></i>
                    <p class="mb-0"><strong>Waiting for your authenticator&hellip;</strong></p>
                    <p class="text-muted small">Touch ID, Face ID, or security key</p>
                </div>
                <div id="passkey-modal-error" class="alert alert-danger mt-2" style="display:none;"></div>
                <div id="passkey-modal-success" class="alert alert-success mt-2" style="display:none;">
                    <i class="fas fa-check-circle me-2"></i>Passkey registered!
                </div>
            </div>
            <div class="modal-footer" id="passkey-modal-footer">
                <button type="button" class="btn btn-primary js-passkey-do-register">
                    <i class="fas fa-fingerprint me-1"></i>Register Passkey
                </button>
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
            </div>
        </div>
    </div>
</div>
<style>@keyframes pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.6;transform:scale(1.1)}}</style>

<?php
// Show the error alert if it exists:
if (!empty($_SESSION['alert_type']) && $_SESSION['alert_type'] == 'error') {
    echo "<div class='alert alert-danger'>{$_SESSION['alert_message']}</div>";
    unset($_SESSION['alert_type'], $_SESSION['alert_message']);
}

if (!empty($_SESSION['show_mfa_modal'])) {
    echo "<script nonce=\"" . htmlspecialchars($csp_nonce ?? '') . "\">document.addEventListener('DOMContentLoaded',function(){\$('#enableMFAModal').modal('show');});</script>";
    unset($_SESSION['show_mfa_modal']);
}
?>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
function passkeyRegister() {
    document.getElementById('passkey-modal-idle').style.display    = '';
    document.getElementById('passkey-modal-waiting').style.display = 'none';
    document.getElementById('passkey-modal-error').style.display   = 'none';
    document.getElementById('passkey-modal-success').style.display = 'none';
    document.getElementById('passkey-modal-footer').style.display  = '';
    document.getElementById('passkeyNameInput').value = '';
    $('#passkeyAddModal').modal('show');
}

async function passkeyDoRegister() {
    const passkeyName = document.getElementById('passkeyNameInput').value.trim() || 'Passkey';
    const errBox  = document.getElementById('passkey-modal-error');
    const footer  = document.getElementById('passkey-modal-footer');
    errBox.style.display = 'none';
    document.getElementById('passkey-modal-idle').style.display    = 'none';
    document.getElementById('passkey-modal-waiting').style.display = '';
    footer.style.display = 'none';
    try {
        const beginResp = await fetch('passkey_enroll_start.php');
        const options   = await beginResp.json();
        if (options.error) throw new Error(options.error);
        options.challenge = b64u_to_buf(options.challenge);
        options.user.id   = b64u_to_buf(options.user.id);
        if (options.excludeCredentials)
            options.excludeCredentials = options.excludeCredentials.map(c => ({...c, id: b64u_to_buf(c.id)}));
        const credential = await navigator.credentials.create({ publicKey: options });
        const body = { passkeyName, id: credential.id, type: credential.type,
            response: {
                clientDataJSON:    buf_to_b64u(credential.response.clientDataJSON),
                attestationObject: buf_to_b64u(credential.response.attestationObject),
            }};
        const result = await (await fetch('passkey_enroll_finish.php', {
            method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(body)
        })).json();
        if (result.ok) {
            document.getElementById('passkey-modal-waiting').style.display = 'none';
            document.getElementById('passkey-modal-success').style.display = '';
            setTimeout(() => { $('#passkeyAddModal').modal('hide'); window.location.reload(); }, 1200);
        } else { throw new Error(result.error || 'Server rejected the passkey'); }
    } catch (err) {
        document.getElementById('passkey-modal-waiting').style.display = 'none';
        document.getElementById('passkey-modal-idle').style.display    = '';
        footer.style.display = '';
        if (err.name !== 'NotAllowedError' && err.name !== 'AbortError') {
            errBox.textContent = 'Error: ' + err.message;
            errBox.style.display = '';
        }
    }
}

async function deletePasskey(pkId, pkName, btn) {
    if (!confirm('Remove passkey "' + pkName + '"?')) return;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    try {
        const result = await (await fetch('passkey_delete.php', {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ passkey_id: pkId, csrf_token: '<?= $_SESSION["csrf_token"] ?>' })
        })).json();
        if (result.ok) {
            const row = btn.closest('tr');
            row.remove();
            if (!document.querySelector('#passkey-table tbody tr')) {
                document.querySelector('#passkey-table tbody').innerHTML =
                    '<tr><td colspan="4" class="text-muted text-center py-3"><i class="fas fa-fingerprint fa-lg mb-1 d-block text-secondary"></i>No passkeys yet. Add one above.</td></tr>';
            }
        } else {
            alert('Delete failed: ' + (result.error || 'Unknown error'));
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-trash"></i>';
        }
    } catch (err) {
        alert('Error: ' + err.message);
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-trash"></i>';
    }
}

function b64u_to_buf(str) {
    const b64 = str.replace(/-/g,'+').replace(/_/g,'/') + '=='.slice(0,(4-str.length%4)%4);
    return Uint8Array.from(atob(b64), c => c.charCodeAt(0)).buffer;
}
function buf_to_b64u(buf) {
    const bytes = new Uint8Array(buf); let s='';
    bytes.forEach(b => s += String.fromCharCode(b));
    return btoa(s).replace(/\+/g,'-').replace(/\//g,'_').replace(/=/g,'');
}
document.addEventListener('click', function (e) {
    var el;
    if ((el = e.target.closest('.js-passkey-register'))) { passkeyRegister(); return; }
    if ((el = e.target.closest('.js-passkey-do-register'))) { passkeyDoRegister(); return; }
    if ((el = e.target.closest('.js-delete-passkey'))) { deletePasskey(parseInt(el.dataset.passkeyId, 10), el.dataset.passkeyName, el); }
});
</script>

<!-- API Tokens -->
<div class="card card-dark">
    <div class="card-header py-2 d-flex align-items-center">
        <h3 class="card-title mr-auto"><i class="fas fa-fw fa-mobile-alt me-2"></i>Mobile App Tokens</h3>
        <span class="badge text-bg-secondary"><?= mysqli_num_rows($sql_api_tokens) ?> active</span>
    </div>
    <div class="card-body p-0">
        <?php if (mysqli_num_rows($sql_api_tokens) > 0): ?>
        <table class="table table-sm table-hover mb-0">
            <thead>
                <tr>
                    <th>Device</th>
                    <th>Created</th>
                    <th>Last Used</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php while ($tok = mysqli_fetch_assoc($sql_api_tokens)): ?>
                <tr id="api-tok-<?= $tok['token_id'] ?>">
                    <td>
                        <i class="fas fa-fw fa-mobile-alt text-secondary me-1"></i>
                        <?= nullable_htmlentities($tok['token_name']) ?>
                        <?php if ($tok['token_fcm_token']): ?>
                            <i class="fas fa-bell text-success ms-1" title="Push notifications active"></i>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted small"><?= $tok['token_created_at'] ?></td>
                    <td class="text-muted small"><?= $tok['token_last_used_at'] ?? 'Never' ?></td>
                    <td class="text-end">
                        <button class="btn btn-xs btn-danger js-revoke-api-token"
                                data-token-id="<?= $tok['token_id'] ?>"
                                data-csrf="<?= $_SESSION['csrf_token'] ?>">
                            <i class="fas fa-times"></i> Revoke
                        </button>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="p-3 text-muted text-center">
            <i class="fas fa-mobile-alt fa-2x mb-2 d-block text-secondary"></i>
            No mobile tokens. Log in from the <?= nullable_htmlentities(APP_NAME) ?> mobile app to create one.
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($trainer_pin_row !== null) { ?>
<!-- Trainer PIN -->
<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title"><i class="fas fa-fw fa-chalkboard-teacher me-2"></i>Trainer PIN</h3>
    </div>
    <div class="card-body">
        <p class="text-muted small mb-3">
            The PIN you use to sign in as a <strong>trainer</strong> on the training kiosk &mdash; kept completely
            separate from your own training PIN as a learner. Only you can set it here.
        </p>
        <?php if ((int) $trainer_pin_row['trainer_pin_hard_locked'] === 1) { ?>
        <div class="alert alert-danger py-2 small">
            <i class="fas fa-lock me-1"></i>Your trainer PIN is locked after repeated wrong entries at the kiosk.
            Setting a new one below clears the lock.
        </div>
        <?php } elseif (!empty($trainer_pin_row['trainer_pin_locked_until_utc']) && $trainer_pin_row['trainer_pin_locked_until_utc'] > gmdate('Y-m-d H:i:s')) { ?>
        <div class="alert alert-warning py-2 small">
            <i class="fas fa-lock me-1"></i>Your trainer PIN is temporarily locked after a few wrong entries.
            Setting a new one below clears the lock.
        </div>
        <?php } ?>
        <p class="mb-3">
            <?php if ($trainer_pin_row['has_pin']) { ?>
                <i class="fas fa-key text-success me-1"></i>Your trainer PIN is set<?php if (!empty($trainer_pin_row['trainer_pin_set_at_utc'])) { ?> (<?= nullable_htmlentities(timeAgo(tps_local($trainer_pin_row['trainer_pin_set_at_utc']))) ?>)<?php } ?>.
            <?php } else { ?>
                <i class="fas fa-key text-muted me-1"></i><span class="text-muted">No trainer PIN set yet.</span>
            <?php } ?>
        </p>
        <form action="post.php" method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <div class="form-group mb-3">
                <label>New Trainer PIN</label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-key"></i></span>
                    </div>
                    <input type="password" class="form-control" name="new_trainer_pin"
                           inputmode="numeric" pattern="[0-9]*" minlength="6" maxlength="6"
                           placeholder="6 digits" autocomplete="off" required>
                </div>
            </div>
            <div class="form-group mb-3">
                <label>Confirm Trainer PIN</label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-key"></i></span>
                    </div>
                    <input type="password" class="form-control" name="new_trainer_pin2"
                           inputmode="numeric" pattern="[0-9]*" minlength="6" maxlength="6"
                           placeholder="6 digits" autocomplete="off" required>
                </div>
                <small class="text-muted">Six digits. Not a straight run, a repeated pattern, or your last trainer PIN.</small>
            </div>
            <button type="submit" name="set_trainer_pin" class="btn btn-primary btn-sm">
                <i class="fas fa-check me-1"></i><?= $trainer_pin_row['has_pin'] ? 'Change Trainer PIN' : 'Set Trainer PIN' ?>
            </button>
        </form>
    </div>
</div>
<?php } ?>

</div>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
async function revokeApiToken(id, btn) {
    if (!confirm('Revoke this token? The device will be logged out.')) return;
    btn.disabled = true;
    const csrf = btn.dataset.csrf;
    try {
        const r = await fetch('/agent/user/api_token_revoke.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({token_id: id, csrf_token: csrf})
        });
        const j = await r.json();
        if (j.ok) {
            document.getElementById('api-tok-' + id).remove();
        } else {
            alert(j.error || 'Failed');
            btn.disabled = false;
        }
    } catch(e) {
        alert('Error: ' + e.message);
        btn.disabled = false;
    }
}
document.addEventListener('click', function (e) {
    var btn = e.target.closest('.js-revoke-api-token');
    if (btn) { revokeApiToken(parseInt(btn.dataset.tokenId, 10), btn); }
});
</script>

<?php require_once "../../includes/footer.php"; ?>
