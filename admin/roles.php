<?php

// Default Column Sortby Filter
$sort = "role_is_admin";
$order = "DESC";

require_once "includes/inc_all_admin.php";
require_once "modals/role/role_lib.php";

$sql = mysqli_query(
    $mysqli,
    "SELECT SQL_CALC_FOUND_ROWS * FROM user_roles
    WHERE (role_name LIKE '%$q%' OR role_description LIKE '%$q%')
    AND role_archived_at IS NULL
    ORDER BY $sort $order LIMIT $record_from, $record_to"
);

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

?>

    <div class="card card-dark">
        <div class="card-header py-2">
            <h3 class="card-title mt-2"><i class="fas fa-fw fa-user-shield me-2"></i>Roles</h3>
            <div class="card-tools">
                <div class="btn-group">
                    <button type="button" class="btn btn-primary ajax-modal" data-modal-url="modals/role/role_add.php" data-modal-size="lg">
                        <i class="fas fa-fw fa-user-plus me-2"></i>New Role
                    </button>
                </div>
            </div>
        </div>
        <div class="card-body">
            <form class="mb-4" autocomplete="off">
                <div class="row">
                    <div class="col-md-4">
                        <div class="input-group">
                            <input type="search" class="form-control" name="q" value="<?php if (isset($q)) {echo stripslashes(nullable_htmlentities($q));} ?>" placeholder="Search Roles">
                            <div class="input-group-append">
                                <button class="btn btn-primary"><i class="fa fa-search"></i></button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
            <hr>
            <div class="table-responsive-sm">
                <table class="table table-striped table-borderless table-hover">
                    <thead class="text-dark <?php if ($num_rows[0] == 0) { echo "d-none"; } ?> text-nowrap">
                    <tr>
                        <th>
                            <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=role_name&order=<?php echo $disp; ?>">
                                Role <?php if ($sort == 'role_name') { echo $order_icon; } ?>
                            </a>
                        </th>
                        <th>Members</th>
                        <th>
                            <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=role_is_admin&order=<?php echo $disp; ?>">
                                Admin <?php if ($sort == 'role_is_admin') { echo $order_icon; } ?>
                            </a>
                        </th>
                        <th class="text-center">Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php

                    while ($row = mysqli_fetch_assoc($sql)) {
                        $role_id = intval($row['role_id']);
                        $role_name = nullable_htmlentities($row['role_name']);
                        $role_description = nullable_htmlentities($row['role_description']);
                        $role_admin = intval($row['role_is_admin']);
                        $role_archived_at = nullable_htmlentities($row['role_archived_at']);

                        // Count number of users that have each role
                        $sql_role_user_count = mysqli_query($mysqli, "SELECT COUNT(user_id) FROM users WHERE user_role_id = $role_id AND user_archived_at IS NULL");
                        $role_user_count = mysqli_fetch_row($sql_role_user_count)[0];

                        $sql_users = mysqli_query($mysqli, "SELECT * FROM users WHERE user_role_id = $role_id AND user_archived_at IS NULL");
                        // Initialize an empty array to hold user names
                        $user_names = [];

                        // Fetch each row and store the user_name in the array
                        while($row_user = mysqli_fetch_assoc($sql_users)) {
                            $user_names[] = nullable_htmlentities($row_user['user_name']);
                        }

                        // The last administrator role that still has an active user can't be edited
                        // or archived from here (admin/post/roles.php refuses it too). Keyed on the
                        // admin flag, not on a role id: ids differ between installs.
                        $role_locked = $role_admin && itflow_role_other_admin_roles($mysqli, $role_id) === 0;

                        // What the role holds, on one line, so roles with similar names can be told apart.
                        $role_summary = itflow_role_summary(itflow_role_levels($mysqli, $role_id), $role_admin === 1);

                        // Convert the array of user names to a comma-separated string
                        $user_names_string = implode(", ", $user_names);

                        if (empty($user_names_string)) {
                            $user_names_string = "-";
                        }

                        ?>
                        <tr>
                            <td>
                                <?php if (!$role_locked) { ?>
                                <a class="ajax-modal" data-modal-url="modals/role/role_edit.php?id=<?= $role_id ?>" data-modal-size="lg" href="#">
                                <?php } ?>
                                    <div class="media">
                                        <i class="fas fa-fw fa-2x fa-user-shield text-dark me-2"></i>
                                        <div class="media-body">
                                            <div><?= $role_name ?></div>
                                            <div><small class="text-secondary"><?= $role_description ?></small></div>
                                            <div><small class="text-muted"><?= htmlspecialchars($role_summary, ENT_QUOTES, 'UTF-8') ?></small></div>
                                        </div>
                                    </div>
                                <?php if (!$role_locked) { ?>
                                </a>
                                <?php } ?>
                            </td>
                            <td><?php echo $user_names_string; ?></td>
                            <td><?php echo $role_admin ? 'Yes' : 'No' ; ?></td>
                            <td>
                                <?php if ($role_locked) { ?>
                                    <div class="text-center text-secondary" data-bs-toggle="tooltip" title="The only administrator role with an active user. It always has full access and can't be edited or archived until another role has admin access.">
                                        <i class="fas fa-lock" role="img" aria-label="Protected: the only administrator role with an active user"></i>
                                    </div>
                                <?php } else { ?>
                                    <div class="dropdown dropleft text-center">
                                        <button class="btn btn-secondary btn-sm" type="button" data-bs-toggle="dropdown">
                                            <i class="fas fa-ellipsis-h"></i>
                                        </button>
                                        <div class="dropdown-menu">

                                            <a class="dropdown-item ajax-modal" href="#"
                                                data-modal-url="modals/role/role_edit.php?id=<?= $role_id ?>" data-modal-size="lg">
                                                <i class="fas fa-fw fa-user-edit me-2"></i>Edit
                                            </a>

                                            <?php if (empty($role_archived_at) && $role_user_count == 0) { ?>
                                                <div class="dropdown-divider"></div>
                                                <a class="dropdown-item text-danger confirm-link" href="post.php?archive_role=<?php echo $role_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token'] ?>">
                                                    <i class="fas fa-fw fa-archive me-2"></i>Archive
                                                </a>
                                            <?php } ?>

                                        </div>
                                    </div>
                                <?php } ?>
                            </td>
                        </tr>

                        <?php

                    }

                    ?>

                    </tbody>
                </table>
            </div>
            <?php require_once "../includes/filter_footer.php";
            ?>
        </div>
    </div>

<?php
require_once "../includes/footer.php";
