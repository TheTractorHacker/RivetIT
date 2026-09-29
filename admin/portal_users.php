<?php

// Admin > Users > Department logins: portal (user_type 2) accounts for supervisors and managers.
$sort = "user_name";
$order = "ASC";

require_once "includes/inc_all_admin.php";

$show_all = isset($_GET['show']) && $_GET['show'] === 'all';
$role_filter = $show_all ? '' : "AND contact_portal_role <> 'none'";

$sql = mysqli_query(
    $mysqli,
    "SELECT SQL_CALC_FOUND_ROWS users.user_id, users.user_name, users.user_email, users.user_status, users.user_auth_method, (users.user_token IS NOT NULL AND users.user_token <> '') AS has_2fa,
            (SELECT user_config_force_mfa FROM user_settings WHERE user_settings.user_id = users.user_id) AS force_mfa,
            contacts.contact_id, contacts.contact_title, contacts.contact_portal_role,
            clients.client_id, clients.client_name,
            (SELECT COUNT(*) FROM contacts r WHERE r.contact_manager_id = contacts.contact_id AND r.contact_archived_at IS NULL) AS team_size,
            (SELECT MAX(log_created_at) FROM logs WHERE log_user_id = users.user_id AND log_type = 'Department Login' AND log_action = 'Success') AS last_login
    FROM users
    INNER JOIN contacts ON contacts.contact_user_id = users.user_id
    LEFT JOIN clients ON clients.client_id = contacts.contact_client_id
    WHERE (user_name LIKE '%$q%' OR user_email LIKE '%$q%' OR client_name LIKE '%$q%')
    AND user_type = 2
    $role_filter
    AND user_$archive_query
    ORDER BY $sort $order LIMIT $record_from, $record_to"
);

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

$users_toggle_active = 'department';
require "includes/users_toggle.php";

?>

<div class="card">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-building me-2"></i>Department logins</h3>
        <div class="card-tools">
            <button type="button" class="btn btn-primary ajax-modal" data-modal-url="modals/portal_user/portal_user_add.php">
                <i class="fas fa-fw fa-user-plus me-2"></i>New Department Login
            </button>
        </div>
    </div>
    <div class="card-body">
        <p class="text-muted">
            Supervisors and managers sign in at the same login page as everyone else and land in the department portal.
            A <strong>manager</strong> sees their whole department's training; a <strong>supervisor</strong> sees the people
            who report to them (set on each person's Manager field).
        </p>
        <form class="mb-4" autocomplete="off">
            <?php if ($show_all) { ?><input type="hidden" name="show" value="all"><?php } ?>
            <div class="row">
                <div class="col-md-4">
                    <div class="input-group">
                        <input type="search" class="form-control" name="q" value="<?php if (isset($q)) {echo stripslashes(nullable_htmlentities($q));} ?>" placeholder="Search name, email or department">
                        <div class="input-group-append">
                            <button class="btn btn-primary"><i class="fa fa-search"></i></button>
                        </div>
                    </div>
                </div>
                <div class="col-md-8">
                    <div class="btn-group float-end">
                        <a href="?show=<?= $show_all ? 'roles' : 'all' ?><?= $archived == 1 ? '&archived=1' : '' ?>"
                            class="btn btn-<?= $show_all ? 'primary' : 'default' ?>">
                            <i class="fa fa-fw fa-users me-2"></i>Include other portal logins
                        </a>
                        <a href="?archived=<?= $archived == 1 ? 0 : 1 ?><?= $show_all ? '&show=all' : '' ?>"
                            class="btn btn-<?= $archived == 1 ? 'primary' : 'default' ?>">
                            <i class="fa fa-fw fa-archive me-2"></i>Archived
                        </a>
                    </div>
                </div>
            </div>
        </form>
        <hr>
        <div class="table-responsive-sm">
            <table class="table table-striped table-borderless table-hover">
                <thead class="text-dark <?php if ($num_rows[0] == 0) { echo "d-none"; } ?>">
                <tr>
                    <th><a class="text-dark" href="?<?= $url_query_strings_sort; ?>&sort=user_name&order=<?= $disp; ?>">Name <?php if ($sort == 'user_name') { echo $order_icon; } ?></a></th>
                    <th><a class="text-dark" href="?<?= $url_query_strings_sort; ?>&sort=user_email&order=<?= $disp; ?>">Email <?php if ($sort == 'user_email') { echo $order_icon; } ?></a></th>
                    <th><a class="text-dark" href="?<?= $url_query_strings_sort; ?>&sort=client_name&order=<?= $disp; ?>">Department <?php if ($sort == 'client_name') { echo $order_icon; } ?></a></th>
                    <th><a class="text-dark" href="?<?= $url_query_strings_sort; ?>&sort=contact_portal_role&order=<?= $disp; ?>">Role <?php if ($sort == 'contact_portal_role') { echo $order_icon; } ?></a></th>
                    <th class="text-end">Team</th>
                    <th><a class="text-dark" href="?<?= $url_query_strings_sort; ?>&sort=user_status&order=<?= $disp; ?>">Status <?php if ($sort == 'user_status') { echo $order_icon; } ?></a></th>
                    <th>Last Login</th>
                    <th class="text-center">Action</th>
                </tr>
                </thead>
                <tbody>
                <?php
                while ($row = mysqli_fetch_assoc($sql)) {
                    $pu_id = intval($row['user_id']);
                    $pu_name = nullable_htmlentities($row['user_name']);
                    $pu_email = nullable_htmlentities($row['user_email']);
                    $pu_title = nullable_htmlentities($row['contact_title']);
                    $pu_dept = nullable_htmlentities($row['client_name']);
                    $pu_role = $row['contact_portal_role'];
                    $pu_status = intval($row['user_status']);
                    $pu_team = intval($row['team_size']);
                    $pu_last = nullable_htmlentities($row['last_login']);
                    $pu_local = $row['user_auth_method'] === 'local';
                    if ($pu_role === 'manager') {
                        $pu_role_display = "<span class='badge bg-primary'>Manager</span>";
                    } elseif ($pu_role === 'supervisor') {
                        $pu_role_display = "<span class='badge bg-info'>Supervisor</span>";
                    } else {
                        $pu_role_display = "<span class='text-muted'>Standard</span>";
                    }
                    if ($pu_status == 1) {
                        $pu_status_display = "<span class='text-success'>Active</span>";
                    } else {
                        $pu_status_display = "<span class='text-danger'>Disabled</span>";
                    }
                    ?>
                    <tr>
                        <td>
                            <a href="#" class="ajax-modal" data-modal-url="modals/portal_user/portal_user_edit.php?id=<?= $pu_id ?>">
                                <?= $pu_name ?>
                            </a>
                            <?php if ($pu_title) { ?><div class="small text-secondary"><?= $pu_title ?></div><?php } ?>
                        </td>
                        <td><a href="mailto:<?= $pu_email ?>"><?= $pu_email ?></a></td>
                        <td><?= $pu_dept ?></td>
                        <td><?= $pu_role_display ?><?php if (!$pu_local) { ?> <span class="badge bg-secondary" title="This login does not use a local password">SSO</span><?php } ?><?php if (intval($row['has_2fa']) === 1) { ?> <span class="badge bg-success" title="Two-factor authentication is on"><i class="fas fa-lock"></i> 2FA</span><?php } elseif (intval($row['force_mfa']) === 1) { ?> <span class="badge bg-warning text-dark" title="2FA is required but not set up yet"><i class="fas fa-lock-open"></i> 2FA pending</span><?php } ?></td>
                        <td class="text-end"><?= $pu_role === 'supervisor' && $pu_team == 0 ? "<span class='text-warning' title='Nobody has this person set as their Manager yet'>0</span>" : $pu_team ?></td>
                        <td><?= $pu_status_display ?></td>
                        <td><?= $pu_last ?: "<span class='text-bold'>Never logged in</span>" ?></td>
                        <td>
                            <div class="d-flex align-items-center">
                                <a class="btn btn-primary btn-sm me-1 ajax-modal" href="#" data-modal-url="modals/portal_user/portal_user_edit.php?id=<?= $pu_id ?>">
                                    <i class="fas fa-fw fa-user-edit me-1"></i>Edit
                                </a>
                                <div class="dropdown dropleft">
                                    <button class="btn btn-secondary btn-sm" type="button" data-bs-toggle="dropdown"><i class="fas fa-ellipsis-h"></i></button>
                                    <div class="dropdown-menu">
                                        <?php if ($pu_status == 1) { ?>
                                            <a class="dropdown-item text-danger" href="post.php?disable_portal_user=<?= $pu_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                                <i class="fas fa-fw fa-user-slash me-2"></i>Disable
                                            </a>
                                        <?php } else { ?>
                                            <a class="dropdown-item text-success" href="post.php?activate_portal_user=<?= $pu_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                                <i class="fas fa-fw fa-user-check me-2"></i>Activate
                                            </a>
                                        <?php } ?>
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php
                }
                if ($num_rows[0] == 0) { ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">
                        <?= $show_all ? 'No department logins found.' : 'No supervisor or manager logins yet. Use New Department Login to add one.' ?>
                    </td></tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
        <?php require_once "../includes/filter_footer.php"; ?>
    </div>
</div>

<?php
require_once "../includes/footer.php";
