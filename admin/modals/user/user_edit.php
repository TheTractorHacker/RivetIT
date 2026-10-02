<?php

require_once '../../../includes/modal_header.php';
require_once __DIR__ . '/../role/role_lib.php';

$user_id = intval($_GET['id']);

$sql = mysqli_query($mysqli, "SELECT * FROM users
    LEFT JOIN user_settings ON users.user_id = user_settings.user_id
    WHERE users.user_id = $user_id LIMIT 1"
);

$row = mysqli_fetch_assoc($sql);
$user_name = nullable_htmlentities($row['user_name']);
$user_email = nullable_htmlentities($row['user_email']);
$user_avatar = nullable_htmlentities($row['user_avatar']);
$user_token = nullable_htmlentities($row['user_token']);
$user_config_force_mfa = intval($row['user_config_force_mfa']);
$user_role_id = intval($row['user_role_id']);
$user_initials = nullable_htmlentities(initials($user_name));
$mcp_issuer = nullable_htmlentities($row['user_oidc_issuer'] ?? '');
$mcp_subject = nullable_htmlentities($row['user_oidc_subject'] ?? '');

// Get passkeys
$sql_passkeys = mysqli_query($mysqli, "SELECT * FROM user_passkeys WHERE passkey_user_id = $user_id ORDER BY passkey_created_at DESC");

// Get remember tokens
$remember_count = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM remember_tokens WHERE remember_token_user_id = $user_id"))[0]);

// Get User Client Access Permissions
$user_client_access_sql = mysqli_query($mysqli,"SELECT client_id FROM user_client_permissions WHERE user_id = $user_id");
$client_access_array = [];
while ($row = mysqli_fetch_assoc($user_client_access_sql)) {
    $client_access_array[] = intval($row['client_id']);
}

// Generate the HTML form content using output buffering.
ob_start();
?>
<div class="modal-header">
    <h5 class="modal-title"><i class="fas fa-fw fa-user-edit me-2"></i>Editing user:
        <strong><?php echo $user_name; ?></strong></h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
</div>
<form action="post.php" method="post" enctype="multipart/form-data" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="user_id" value="<?php echo $user_id; ?>">
    <div class="modal-body">

        <ul class="nav nav-pills nav-justified mb-3">
            <li class="nav-item">
                <a class="nav-link active" data-bs-toggle="pill" href="#pills-user-details<?php echo $user_id; ?>">Details</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="pill" href="#pills-user-security<?php echo $user_id; ?>">Security</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="pill" href="#pills-user-access<?php echo $user_id; ?>">Access</a>
            </li>
        </ul>

        <hr>

        <div class="tab-content">

            <div class="tab-pane fade show active" id="pills-user-details<?php echo $user_id; ?>">

                <p class="text-muted small mb-3">Fields marked <strong class="text-danger">*</strong> are required.</p>

                <h6 class="text-uppercase text-muted mb-2" style="font-size:.75rem;letter-spacing:.05em">
                    <i class="fas fa-id-card me-1"></i>Account
                </h6>

                <div class="form-group">
                    <label for="user_edit_name<?php echo $user_id; ?>">Name <strong class="text-danger">*</strong></label>
                    <input type="text" class="form-control" id="user_edit_name<?php echo $user_id; ?>" name="name" placeholder="Full Name" maxlength="200"
                           value="<?php echo $user_name; ?>" required>
                </div>

                <div class="form-group">
                    <label for="user_edit_email<?php echo $user_id; ?>">Email <strong class="text-danger">*</strong></label>
                    <input type="email" class="form-control" id="user_edit_email<?php echo $user_id; ?>" name="email" placeholder="Email Address" maxlength="200"
                           value="<?php echo $user_email; ?>" required>
                    <small class="form-text text-muted">Also used as the sign-in username.</small>
                </div>

                <div class="form-group">
                    <label for="user_edit_role<?php echo $user_id; ?>">Role <strong class="text-danger">*</strong></label>
                    <select class="form-control select2" id="user_edit_role<?php echo $user_id; ?>" name="role" required>
                        <?php
                        $sql_user_roles = mysqli_query($mysqli, "SELECT * FROM user_roles WHERE role_archived_at IS NULL");
                        while ($row = mysqli_fetch_assoc($sql_user_roles)) {
                            $role_id = intval($row['role_id']);
                            $role_name = nullable_htmlentities($row['role_name']);

                            ?>
                            <option <?php if ($role_id == $user_role_id) {echo "selected";} ?> value="<?php echo $role_id; ?>"><?php echo $role_name; ?></option>
                        <?php } ?>

                    </select>
                    <small class="form-text text-muted">Department limits are set on the Access tab.</small>
                </div>

                <hr class="my-3">

                <h6 class="text-uppercase text-muted mb-2" style="font-size:.75rem;letter-spacing:.05em">
                    <i class="fas fa-key me-1"></i>Sign-in
                </h6>

                <div class="form-group">
                    <label for="user_edit_password<?php echo $user_id; ?>">New Password</label>
                    <div class="input-group">
                        <!-- The show/hide plugin binds EVERY .input-group-text inside this input's
                             parent ($(this).parent().find(".input-group-text")), so the eye must be
                             the only one here - a decorative addon would become a second, unlabelled
                             reveal button. The generate button is deliberately a .btn, not an addon. -->
                        <input type="password" class="form-control" data-toggle="password" name="new_password" id="user_edit_password<?php echo $user_id; ?>"
                               placeholder="Leave Blank For No Password Change" autocomplete="new-password">
                        <span class="input-group-text" title="Show password"><i class="fa fa-fw fa-eye"></i></span>
                        <button type="button" class="btn btn-outline-secondary js-generate-password" title="Generate a random password" aria-label="Generate a random password"><i class="fa fa-fw fa-dice"></i></button>
                    </div>
                    <small class="form-text text-muted">Leave blank to keep the current password. Use at least 8 characters.</small>
                </div>

                <div class="form-group">
                    <div class="form-check form-check">
                        <input class="form-check-input" type="checkbox" id="forceMFASec<?php echo $user_id; ?>" name="force_mfa" value="1" <?php if($user_config_force_mfa == 1){ echo "checked"; } ?>>
                        <label for="forceMFASec<?php echo $user_id; ?>" class="form-check-label">Force MFA on next login</label>
                    </div>
                </div>

                <hr class="my-3">
                <h6 class="text-uppercase text-muted mb-2" style="font-size:.75rem;letter-spacing:.05em">Remote MCP identity</h6>
                <p class="text-muted small">Link this agent to the exact issuer and subject of a dedicated OAuth access token. Both fields must be set; clearing both revokes this mapping.</p>
                <div class="form-group">
                    <label for="user_edit_mcp_issuer<?= $user_id ?>">OAuth issuer</label>
                    <input type="url" class="form-control" id="user_edit_mcp_issuer<?= $user_id ?>" name="mcp_issuer" value="<?= $mcp_issuer ?>" maxlength="255" placeholder="https://login.example.com/realm">
                </div>
                <div class="form-group">
                    <label for="user_edit_mcp_subject<?= $user_id ?>">Immutable subject (sub)</label>
                    <input type="text" class="form-control" id="user_edit_mcp_subject<?= $user_id ?>" name="mcp_subject" value="<?= $mcp_subject ?>" maxlength="255" autocomplete="off">
                </div>

                <hr class="my-3">

                <div class="d-flex align-items-center">
                    <div class="me-3">
                        <?php if (!empty($user_avatar)) { ?>
                            <img class="rounded" style="width:64px; height:64px; object-fit:cover;"
                                 src="<?php echo "../uploads/users/$user_id/$user_avatar"; ?>" alt="Current avatar">
                        <?php } else { ?>
                            <span class="fa-stack fa-2x">
                                <i class="fa fa-circle fa-stack-2x text-secondary"></i>
                                <span class="fa fa-stack-1x text-white"><?php echo $user_initials; ?></span>
                            </span>
                        <?php } ?>
                    </div>
                    <div class="flex-fill">
                        <label for="user_edit_avatar<?php echo $user_id; ?>">Avatar <span class="text-muted fw-normal">(optional)</span></label>
                        <input type="file" class="form-control" id="user_edit_avatar<?php echo $user_id; ?>" accept="image/*" name="file">
                        <small class="form-text text-muted">Uploading a new image replaces the current one.</small>
                    </div>
                </div>

                <p class="text-muted small mt-3 mb-0"><i class="fas fa-shield-alt me-1"></i>Manage 2FA, passkeys, and sessions on the
                    <a href="#" class="js-goto-tab" data-target-pane="#pills-user-security<?= $user_id ?>">Security tab</a>.</p>
            </div>

            <!-- Security Tab -->
            <div class="tab-pane fade" id="pills-user-security<?php echo $user_id; ?>">

                <!-- 2FA -->
                <h6 class="text-uppercase text-muted mb-2" style="font-size:.75rem;letter-spacing:.05em">
                    <i class="fas fa-shield-alt me-1"></i>Two-Factor Authentication
                </h6>
                <?php if (!empty($user_token)): ?>
                    <div class="d-flex align-items-center justify-content-between p-2 mb-2 border rounded">
                        <span><i class="fas fa-lock text-success me-2"></i><strong>Enabled</strong> — TOTP authenticator app</span>
                        <a href="post.php?disable_2fa=<?= $user_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>"
                           class="btn btn-sm btn-outline-danger confirm-link">
                            <i class="fas fa-unlock me-1"></i>Disable
                        </a>
                    </div>
                <?php else: ?>
                    <div class="d-flex align-items-center p-2 mb-2 border rounded">
                        <i class="fas fa-unlock text-danger me-2"></i><span class="text-muted">Not configured</span>
                    </div>
                <?php endif; ?>

                <p class="text-muted small mb-3"><i class="fas fa-user-lock me-1"></i><strong>Force MFA on next login</strong> is set on the
                    <a href="#" class="js-goto-tab" data-target-pane="#pills-user-details<?= $user_id ?>">Details tab</a>,
                    alongside the password<?php if ($user_config_force_mfa == 1) { echo ' - it is currently ON for this user'; } ?>.</p>

                <hr>

                <!-- Passkeys -->
                <h6 class="text-uppercase text-muted mb-2" style="font-size:.75rem;letter-spacing:.05em">
                    <i class="fas fa-key me-1"></i>Passkeys
                </h6>
                <?php
                $passkey_rows = [];
                while ($pk = mysqli_fetch_assoc($sql_passkeys)) { $passkey_rows[] = $pk; }
                if (empty($passkey_rows)): ?>
                    <p class="text-muted small mb-3">No passkeys registered.</p>
                <?php else: ?>
                    <ul class="list-group mb-3">
                    <?php foreach ($passkey_rows as $pk):
                        $pk_id   = intval($pk['passkey_id']);
                        $pk_name = nullable_htmlentities($pk['passkey_name']);
                        $pk_used = nullable_htmlentities($pk['passkey_last_used_at'] ?? 'Never');
                        $pk_created = nullable_htmlentities($pk['passkey_created_at']);
                    ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center py-2">
                            <div>
                                <i class="fas fa-key text-secondary me-2"></i>
                                <strong><?= $pk_name ?></strong>
                                <small class="text-muted ms-2">Added <?= $pk_created ?> · Last used <?= $pk_used ?></small>
                            </div>
                            <a href="post.php?delete_passkey=<?= $pk_id ?>&user_id=<?= $user_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>"
                               class="btn btn-xs btn-outline-danger confirm-link" title="Delete passkey">
                                <i class="fas fa-trash"></i>
                            </a>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php if (!empty($user_token)): // Only relevant when 2FA is enabled ?>
                <hr>

                <!-- Sessions -->
                <h6 class="text-uppercase text-muted mb-2" style="font-size:.75rem;letter-spacing:.05em">
                    <i class="fas fa-desktop me-1"></i>Trusted Devices <small class="text-muted fw-normal">(2FA bypass tokens)</small>
                </h6>
                <div class="d-flex align-items-center justify-content-between p-2 border rounded">
                    <?php if ($remember_count > 0): ?>
                        <span><i class="fas fa-circle text-warning me-2"></i><?= $remember_count ?> trusted device<?= $remember_count > 1 ? 's' : '' ?></span>
                        <a href="post.php?revoke_remember_me=<?= $user_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>"
                           class="btn btn-sm btn-outline-warning confirm-link">
                            <i class="fas fa-ban me-1"></i>Revoke All
                        </a>
                    <?php else: ?>
                        <span class="text-muted"><i class="fas fa-circle text-secondary me-2"></i>No trusted devices</span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

            </div>

            <div class="tab-pane fade" id="pills-user-access<?php echo $user_id; ?>">

                <?php itflow_role_access_help_render($mysqli, 'user_edit_role' . $user_id); ?>

                <?php
                $sql_client_select = mysqli_query($mysqli, "SELECT * FROM clients WHERE client_archived_at IS NULL ORDER BY client_name ASC");
                $client_count = intval(mysqli_num_rows($sql_client_select));
                ?>

                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-muted small"><?php echo $client_count; ?> department<?php echo $client_count == 1 ? '' : 's'; ?></span>
                    <div class="form-check mb-0">
                        <input type="checkbox" class="form-check-input js-toggle-all-clients" id="user_edit_all_departments<?php echo $user_id; ?>">
                        <label class="form-check-label ms-1" for="user_edit_all_departments<?php echo $user_id; ?>">Select all</label>
                    </div>
                </div>

                <!-- Capped so the modal footer stays on screen: 15 full-height list rows
                     otherwise push Save ~700px below the fold at 1366x768. -->
                <div class="border rounded" style="max-height:min(46vh,340px); overflow-y:auto;">
                    <ul class="list-group list-group-flush">

                        <?php

                        while ($row = mysqli_fetch_assoc($sql_client_select)) {
                            $client_id_select = intval($row['client_id']);
                            $client_name_select = nullable_htmlentities($row['client_name']);

                        ?>

                        <li class="list-group-item py-2">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input client-checkbox" id="user_edit_client_<?php echo $user_id; ?>_<?php echo $client_id_select; ?>" name="clients[]" value="<?php echo $client_id_select; ?>" <?php if (in_array($client_id_select, $client_access_array)) { echo "checked"; } ?>>
                                <label class="form-check-label ms-2" for="user_edit_client_<?php echo $user_id; ?>_<?php echo $client_id_select; ?>"><?php echo $client_name_select; ?></label>
                            </div>
                        </li>

                        <?php } ?>

                    </ul>
                </div>

            </div>

        </div>

    </div>
    <div class="modal-footer">
        <button type="submit" name="edit_user" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Save</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Cancel</button>
    </div>
</form>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
(function () {

    // Fill whichever password box sits next to the clicked generate button.
    function generatePassword(input) {
        jQuery.get(
            "/agent/ajax.php", {
                get_readable_pass: 'true'
            },
            function(data) {
                input.value = JSON.parse(data);
            }
        );
    }

    // This script is re-executed on every ajax-modal open (ajax_modal.js re-runs
    // injected scripts), so these document-level listeners would otherwise stack
    // up - one extra ajax request / toggle pass per modal opened this page load.
    if (!window.itflowUserModalWired) {
        window.itflowUserModalWired = true;

        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.js-generate-password');
            if (!btn) return;
            var group = btn.closest('.input-group');
            var input = group && group.querySelector('input[name="password"], input[name="new_password"]');
            if (input) { generatePassword(input); }
        });

        document.addEventListener('click', function (e) {
            var el = e.target.closest('.js-toggle-all-clients');
            if (!el) return;
            el.closest('.tab-pane').querySelectorAll('.client-checkbox').forEach(function (checkbox) { checkbox.checked = el.checked; });
        });

        // Cross-references between tabs. data-bs-toggle="pill" on a link inside the
        // tab body does NOT switch tabs (Bootstrap resolves the active state through
        // the trigger's parent nav, which a body link has no part in - measured: the
        // pane stayed on Details), so drive the real nav pill instead.
        document.addEventListener('click', function (e) {
            var link = e.target.closest('.js-goto-tab');
            if (!link) return;
            e.preventDefault();
            var scope = link.closest('.modal') || document;
            var pill = scope.querySelector('.nav-link[href="' + link.getAttribute('data-target-pane') + '"]');
            if (pill && window.bootstrap) { bootstrap.Tab.getOrCreateInstance(pill).show(); }
        });
    }

    // The autofocus attribute never fires for markup injected via innerHTML, and
    // Bootstrap focuses the dialog itself on show - so focus the first field once
    // the modal has finished animating in.
    var field = document.getElementById('user_edit_name<?php echo $user_id; ?>');
    var modalEl = field ? field.closest('.modal') : null;
    if (modalEl) {
        modalEl.addEventListener('shown.bs.modal', function () { field.focus(); }, { once: true });
    }

})();
</script>

<?php
require_once "../../../includes/modal_footer.php";
