<?php
// Technicians | Department logins switch shown on both Admin > Users pages. Expects $users_toggle_active.
$users_toggle_active = $users_toggle_active ?? 'technicians';
?>
<div class="btn-group mb-3" role="group" aria-label="User type">
    <a href="users.php" class="btn btn-<?= $users_toggle_active === 'technicians' ? 'primary' : 'outline-primary' ?>">
        <i class="fas fa-fw fa-headset me-2"></i>Technicians
    </a>
    <a href="portal_users.php" class="btn btn-<?= $users_toggle_active === 'department' ? 'primary' : 'outline-primary' ?>">
        <i class="fas fa-fw fa-building me-2"></i>Department logins
    </a>
</div>
