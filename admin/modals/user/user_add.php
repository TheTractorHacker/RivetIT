<?php

require_once '../../../includes/modal_header.php';

ob_start();

?>
<div class="modal-header">
    <h5 class="modal-title"><i class="fas fa-fw fa-user-plus me-2"></i>New User</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
</div>
<form action="post.php" method="post" enctype="multipart/form-data" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">
    <div class="modal-body">

        <ul class="nav nav-pills nav-justified mb-3">
            <li class="nav-item">
                <a class="nav-link active" data-bs-toggle="pill" href="#pills-user-details">Details</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="pill" href="#pills-user-access">Access</a>
            </li>
        </ul>

        <hr>

        <div class="tab-content">

            <div class="tab-pane fade show active" id="pills-user-details">

                <p class="text-muted small mb-3">Fields marked <strong class="text-danger">*</strong> are required.</p>

                <h6 class="text-uppercase text-muted mb-2" style="font-size:.75rem;letter-spacing:.05em">
                    <i class="fas fa-id-card me-1"></i>Account
                </h6>

                <div class="form-group">
                    <label for="user_add_name">Name <strong class="text-danger">*</strong></label>
                    <input type="text" class="form-control" id="user_add_name" name="name" placeholder="Full Name" maxlength="200" required>
                </div>

                <div class="form-group">
                    <label for="user_add_email">Email <strong class="text-danger">*</strong></label>
                    <input type="email" class="form-control" id="user_add_email" name="email" placeholder="Email Address" maxlength="200" required>
                    <small class="form-text text-muted">Also used as the sign-in username.</small>
                </div>

                <div class="form-group">
                    <label for="user_add_role">Role <strong class="text-danger">*</strong></label>
                    <select class="form-control select2" id="user_add_role" name="role" required>
                        <option value="">- Role -</option>
                        <?php
                            $sql_user_roles = mysqli_query($mysqli, "SELECT * FROM user_roles WHERE role_archived_at IS NULL");
                            while ($row = mysqli_fetch_assoc($sql_user_roles)) {
                                $role_id = intval($row['role_id']);
                                $role_name = nullable_htmlentities($row['role_name']);

                            ?>
                            <option value="<?php echo $role_id; ?>"><?php echo $role_name; ?></option>
                        <?php } ?>
                    </select>
                    <small class="form-text text-muted">Department limits are set on the Access tab.</small>
                </div>

                <hr class="my-3">

                <h6 class="text-uppercase text-muted mb-2" style="font-size:.75rem;letter-spacing:.05em">
                    <i class="fas fa-key me-1"></i>Sign-in
                </h6>

                <div class="form-group">
                    <label for="user_add_password">Password <strong class="text-danger">*</strong></label>
                    <div class="input-group">
                        <!-- The show/hide plugin binds EVERY .input-group-text inside this input's
                             parent ($(this).parent().find(".input-group-text")), so the eye must be
                             the only one here - a decorative addon would become a second, unlabelled
                             reveal button. The generate button is deliberately a .btn, not an addon. -->
                        <input type="password" class="form-control" data-toggle="password" name="password" id="user_add_password" placeholder="Enter a Password" autocomplete="new-password" minlength="8" required>
                        <span class="input-group-text" title="Show password"><i class="fa fa-fw fa-eye"></i></span>
                        <button type="button" class="btn btn-outline-secondary js-generate-password" title="Generate a random password" aria-label="Generate a random password"><i class="fa fa-fw fa-dice"></i></button>
                    </div>
                    <small class="form-text text-muted">Minimum 8 characters.</small>
                </div>

                <div class="form-group">
                    <div class="form-check form-check">
                        <input class="form-check-input" type="checkbox" id="forceMFACheckBox" name="force_mfa" value=1>
                        <label for="forceMFACheckBox" class="form-check-label">
                            Force MFA on next login
                        </label>
                    </div>
                </div>

                <?php if (empty($config_smtp_host)) { ?>
                    <div class="alert alert-warning py-2 px-3 small">
                        <i class="fas fa-exclamation-triangle me-1"></i>E-mail is not configured here, so nothing is sent. Give the user this password yourself.
                    </div>
                <?php } ?>

                <div class="form-group" <?php if(empty($config_smtp_host)) { echo "hidden"; } ?>>
                    <div class="form-check form-check">
                        <input class="form-check-input" type="checkbox" id="sendEmailCheckBox" name="send_email" value="" checked>
                        <label for="sendEmailCheckBox" class="form-check-label">
                            E-mail the user a welcome message with their sign-in link
                        </label>
                        <small class="form-text text-muted">The password is not included - hand it over separately.</small>
                    </div>
                </div>

                <hr class="my-3">

                <div class="form-group">
                    <label for="user_add_avatar">Avatar <span class="text-muted fw-normal">(optional)</span></label>
                    <input type="file" class="form-control" id="user_add_avatar" accept="image/*" name="file">
                </div>

            </div>

            <div class="tab-pane fade" id="pills-user-access">

                <div class="alert alert-info py-2 px-3 small">
                    Check boxes to authorize user department access. No boxes grant full department access. Admin users are unaffected.
                </div>

                <?php
                $sql_client_select = mysqli_query($mysqli, "SELECT * FROM clients WHERE client_archived_at IS NULL ORDER BY client_name ASC");
                $client_count = intval(mysqli_num_rows($sql_client_select));
                ?>

                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-muted small"><?php echo $client_count; ?> department<?php echo $client_count == 1 ? '' : 's'; ?></span>
                    <div class="form-check mb-0">
                        <input type="checkbox" class="form-check-input js-toggle-all-clients" id="user_add_all_departments">
                        <label class="form-check-label ms-1" for="user_add_all_departments">Select all</label>
                    </div>
                </div>

                <!-- Capped so the modal footer stays on screen: 15 full-height list rows
                     otherwise push Create ~700px below the fold at 1366x768. -->
                <div class="border rounded" style="max-height:min(46vh,340px); overflow-y:auto;">
                    <ul class="list-group list-group-flush">

                        <?php

                        while ($row = mysqli_fetch_assoc($sql_client_select)) {
                            $client_id = intval($row['client_id']);
                            $client_name = nullable_htmlentities($row['client_name']);

                        ?>
                        <li class="list-group-item py-2">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input client-checkbox" id="user_add_client_<?php echo $client_id; ?>" name="clients[]" value="<?php echo $client_id; ?>">
                                <label class="form-check-label ms-2" for="user_add_client_<?php echo $client_id; ?>"><?php echo $client_name; ?></label>
                            </div>
                        </li>

                        <?php } ?>

                    </ul>
                </div>

            </div>

        </div>

    </div>
    <div class="modal-footer">
        <button type="submit" name="add_user" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Create</button>
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

        // Cross-references between tabs (used by user_edit.php). data-bs-toggle="pill"
        // on a link inside the tab body does NOT switch tabs (Bootstrap resolves the
        // active state through the trigger's parent nav, which a body link has no part
        // in - measured: the pane stayed on Details), so drive the real nav pill instead.
        // Kept identical in both modals: whichever loads first owns the wiring guard.
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
    var field = document.getElementById('user_add_name');
    var modalEl = field ? field.closest('.modal') : null;
    if (modalEl) {
        modalEl.addEventListener('shown.bs.modal', function () { field.focus(); }, { once: true });
    }

})();
</script>

<?php
require_once "../../../includes/modal_footer.php";
