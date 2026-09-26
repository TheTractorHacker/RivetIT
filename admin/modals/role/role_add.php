<?php

require_once '../../../includes/modal_header.php';
require_once __DIR__ . '/role_lib.php';

ob_start();

?>
<div class="modal-header">
    <h5 class="modal-title"><i class="fas fa-fw fa-user-shield me-2"></i>New Role</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
</div>

<form action="post.php" method="post" enctype="multipart/form-data" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

    <div class="modal-body">
        <?php
        itflow_role_form_render($mysqli, [
            'id' => 0,
            'name' => '',
            'description' => '',
            'is_admin' => false,
            'levels' => [],
            'locked_admin' => false,
            'is_own_role' => false,
            'members' => 0,
        ]);
        ?>
    </div>

    <div class="modal-footer">
        <button type="submit" name="add_role" class="btn btn-primary text-bold">
            <i class="fas fa-check me-2"></i>Create
        </button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">
            <i class="fas fa-times me-2"></i>Cancel
        </button>
    </div>
</form>

<?php
require_once '../../../includes/modal_footer.php';
