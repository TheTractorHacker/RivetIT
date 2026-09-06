<?php

/*
 * DEVELOPER NOTE - this modal has no server side yet.
 *
 * Nothing in the repo handles the `invite_user` POST (admin/post/users.php has
 * add_user / edit_user only), and its trigger in admin/users.php is deliberately
 * commented out, so the modal is currently unreachable in the UI. The markup below
 * was also unsubmittable on its own: the Role select carried only its placeholder
 * `<option>` on a required field. That is fixed here (the options now come from the
 * same user_roles query the add/edit modals use), and the chrome now matches its two
 * siblings - but the form still cannot do anything until an invite_user handler and
 * an invite token flow exist. Do not re-enable the trigger before then.
 */

require_once '../../../includes/modal_header.php';

ob_start();

?>
<div class="modal-header">
    <h5 class="modal-title"><i class="fas fa-fw fa-paper-plane me-2"></i>Invite User</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
</div>
<form action="post.php" method="post" enctype="multipart/form-data" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">
    <div class="modal-body">

        <p class="text-muted small mb-3">Fields marked <strong class="text-danger">*</strong> are required.</p>

        <div class="form-group">
            <label for="user_invite_email">Email <strong class="text-danger">*</strong></label>
            <input type="email" class="form-control" id="user_invite_email" name="email" placeholder="Email Address" maxlength="200" required>
            <small class="form-text text-muted">Also used as the sign-in username.</small>
        </div>

        <div class="form-group">
            <label for="user_invite_role">Role <strong class="text-danger">*</strong></label>
            <select class="form-control select2" id="user_invite_role" name="role" required>
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
            <small class="form-text text-muted">Sets what the user can do once they accept.</small>
        </div>

    </div>
    <div class="modal-footer">
        <button type="submit" name="invite_user" class="btn btn-primary text-bold"><i class="fas fa-paper-plane me-2"></i>Send Invite</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php
require_once "../../../includes/modal_footer.php";
