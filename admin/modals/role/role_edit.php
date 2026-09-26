<?php

require_once '../../../includes/modal_header.php';
require_once __DIR__ . '/role_lib.php';

$role_id = intval($_GET['id'] ?? 0);

$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM user_roles WHERE role_id = $role_id LIMIT 1"));
if (!$row) {
    echo json_encode(['error' => 'That role no longer exists.']);
    exit;
}

$role_admin = intval($row['role_is_admin']) === 1;

// Who is in this role (shown so the admin knows whose access changes on Save)
$user_names = [];
$sql_users = mysqli_query($mysqli, "SELECT user_name FROM users WHERE user_role_id = $role_id AND user_archived_at IS NULL ORDER BY user_name ASC");
while ($row_user = mysqli_fetch_assoc($sql_users)) {
    $user_names[] = (string) $row_user['user_name'];
}

$role = [
    'id' => $role_id,
    'name' => (string) $row['role_name'],
    'description' => (string) $row['role_description'],
    'is_admin' => $role_admin,
    'levels' => itflow_role_levels($mysqli, $role_id),
    // The last administrator role with an active user can't lose admin access (admin/post/roles.php enforces it too)
    'locked_admin' => $role_admin && itflow_role_other_admin_roles($mysqli, $role_id) === 0,
    'is_own_role' => intval($session_user_role ?? 0) === $role_id,
    'members' => count($user_names),
];

ob_start();

?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-user-shield me-2"></i>Editing role:
        <strong><?= htmlspecialchars($role['name'], ENT_QUOTES, 'UTF-8') ?></strong></h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal" aria-label="Close">
        <span aria-hidden="true">&times;</span>
    </button>
</div>
<form action="post.php" method="post" enctype="multipart/form-data" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="role_id" value="<?= $role_id ?>">
    <div class="modal-body">
        <p class="small text-secondary mb-2">
            <i class="fas fa-fw fa-users me-1"></i>
            <?php if ($user_names) { ?>
                Changes apply to <?= count($user_names) === 1 ? '1 user' : count($user_names) . ' users' ?>:
                <?= htmlspecialchars(implode(', ', $user_names), ENT_QUOTES, 'UTF-8') ?>
            <?php } else { ?>
                Nobody is in this role yet.
            <?php } ?>
        </p>
        <?php itflow_role_form_render($mysqli, $role); ?>
    </div>
    <div class="modal-footer">
        <button type="submit" name="edit_role" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Save</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php
require_once '../../../includes/modal_footer.php';
