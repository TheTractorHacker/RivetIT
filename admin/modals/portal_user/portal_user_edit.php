<?php

require_once '../../../includes/modal_header.php';

ob_start();

$user_id = intval($_GET['id'] ?? 0);

$sql = mysqli_query($mysqli, "SELECT users.user_id, users.user_name, users.user_email, users.user_auth_method, users.user_token, COALESCE(user_settings.user_config_force_mfa, 0) AS force_mfa,
        contacts.contact_title, contacts.contact_portal_role, clients.client_name
    FROM users
    INNER JOIN contacts ON contacts.contact_user_id = users.user_id
    LEFT JOIN clients ON clients.client_id = contacts.contact_client_id
    LEFT JOIN user_settings ON user_settings.user_id = users.user_id
    WHERE users.user_id = $user_id AND users.user_type = 2 LIMIT 1");
$row = mysqli_fetch_assoc($sql);
if (!$row) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Department login not found.']);
    exit;
}

$name = nullable_htmlentities($row['user_name']);
$email = nullable_htmlentities($row['user_email']);
$title = nullable_htmlentities($row['contact_title']);
$dept = nullable_htmlentities($row['client_name']);
$role = $row['contact_portal_role'];
$is_local = $row['user_auth_method'] === 'local';
$has_2fa = !empty($row['user_token']);
$force_mfa = intval($row['force_mfa']) === 1;

?>
<div class="modal-header">
    <h5 class="modal-title"><i class="fas fa-fw fa-user-edit me-2"></i>Edit Department Login</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="user_id" value="<?= $user_id ?>">
    <div class="modal-body">

        <p class="text-muted mb-3"><i class="fas fa-fw fa-building me-1"></i>Department: <strong><?= $dept ?></strong></p>

        <div class="form-group">
            <label for="pu_edit_role<?= $user_id ?>">Role</label>
            <select class="form-control" id="pu_edit_role<?= $user_id ?>" name="portal_role">
                <option value="none" <?= $role === 'none' ? 'selected' : '' ?>>Standard - own training only</option>
                <option value="supervisor" <?= $role === 'supervisor' ? 'selected' : '' ?>>Supervisor - sees the people who report to them</option>
                <option value="manager" <?= $role === 'manager' ? 'selected' : '' ?>>Manager - sees the whole department</option>
            </select>
        </div>

        <div class="form-group">
            <label for="pu_edit_name<?= $user_id ?>">Name <strong class="text-danger">*</strong></label>
            <input type="text" class="form-control" id="pu_edit_name<?= $user_id ?>" name="name" value="<?= $name ?>" maxlength="200" required>
        </div>

        <div class="form-group">
            <label for="pu_edit_email<?= $user_id ?>">Email <strong class="text-danger">*</strong></label>
            <input type="email" class="form-control" id="pu_edit_email<?= $user_id ?>" name="email" value="<?= $email ?>" maxlength="200" required>
        </div>

        <div class="form-group">
            <label for="pu_edit_title<?= $user_id ?>">Title</label>
            <input type="text" class="form-control" id="pu_edit_title<?= $user_id ?>" name="title" value="<?= $title ?>" maxlength="200">
        </div>

        <?php if ($is_local) { ?>
        <div class="form-group">
            <label for="pu_edit_password<?= $user_id ?>">New password</label>
            <div class="input-group">
                <input type="password" class="form-control" data-toggle="password" name="new_password" id="pu_edit_password<?= $user_id ?>" autocomplete="new-password" minlength="8" placeholder="Leave blank to keep the current password">
                <span class="input-group-text" title="Show password"><i class="fa fa-fw fa-eye"></i></span>
                <button type="button" class="btn btn-outline-secondary js-portal-generate" title="Generate a random password" aria-label="Generate a random password"><i class="fa fa-fw fa-dice"></i></button>
            </div>
            <small class="form-text text-muted">Changing the password also clears any saved "remember me" sign-ins.</small>
        </div>
        <?php } else { ?>
        <div class="alert alert-info py-2 px-3 small">This login does not use a local password (single sign-on), so there is no password to set here.</div>
        <?php } ?>

        <?php if ($is_local) { ?>
        <hr class="my-3">
        <h6 class="text-uppercase text-muted mb-2" style="font-size:.75rem;letter-spacing:.05em"><i class="fas fa-shield-alt me-1"></i>Two-Factor Authentication</h6>
        <?php if ($has_2fa) { ?>
            <div class="d-flex align-items-center justify-content-between p-2 mb-2 border rounded">
                <span><i class="fas fa-lock text-success me-2"></i><strong>Enabled</strong> &mdash; TOTP authenticator app</span>
                <a href="post.php?disable_portal_2fa=<?= $user_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>" class="btn btn-sm btn-outline-danger confirm-link"><i class="fas fa-unlock me-1"></i>Disable</a>
            </div>
        <?php } else { ?>
            <div class="d-flex align-items-center p-2 mb-2 border rounded"><i class="fas fa-unlock text-danger me-2"></i><span class="text-muted">Not configured</span></div>
        <?php } ?>
        <div class="form-check">
            <input class="form-check-input" type="checkbox" id="pu_edit_force_mfa<?= $user_id ?>" name="force_mfa" value="1" <?= $force_mfa ? 'checked' : '' ?>>
            <label for="pu_edit_force_mfa<?= $user_id ?>" class="form-check-label">Require 2FA <span class="text-muted">(they must set it up on their next sign-in)</span></label>
        </div>
        <?php } ?>

    </div>
    <div class="modal-footer">
        <button type="submit" name="edit_portal_user" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Save</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Cancel</button>
    </div>
</form>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
(function () {
    if (window.rivetPortalUserModalWired) { return; }
    window.rivetPortalUserModalWired = true;
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.js-portal-generate');
        if (!btn) { return; }
        var input = btn.closest('.input-group').querySelector('input[name="password"], input[name="new_password"]');
        if (input) {
            jQuery.get("/agent/ajax.php", { get_readable_pass: 'true' }, function (data) { input.value = JSON.parse(data); });
        }
    });
})();
</script>

<?php
require_once "../../../includes/modal_footer.php";
