<?php

// Default Column Sortby Filter
$sort = "project_template_name";
$order = "ASC";

require_once "includes/inc_all_admin.php";

$sql = mysqli_query(
    $mysqli,
    "SELECT SQL_CALC_FOUND_ROWS * FROM project_templates
    WHERE (project_template_name LIKE '%$q%' OR project_template_description LIKE '%$q%')
    AND project_template_is_onboarding = 1
    AND project_template_archived_at IS NULL
    ORDER BY $sort $order LIMIT $record_from, $record_to"
);

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

?>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-user-plus me-2"></i>Onboarding Templates</h3>
        <div class="card-tools">
            <button type="button" class="btn btn-primary ajax-modal" data-modal-url="modals/onboarding_template/onboarding_template_add.php"><i class="fas fa-plus me-2"></i>New Onboarding Template</button>
        </div>
    </div>
    <div class="card-body">
        <form autocomplete="off">
            <div class="row">

                <div class="col-md-4">
                    <div class="input-group mb-3 mb-md-0">
                        <input type="search" class="form-control" name="q" value="<?php if(isset($q)){ echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search Onboarding Templates">
                        <div class="input-group-append">
                            <button class="btn btn-dark"><i class="fa fa-search"></i></button>
                        </div>
                    </div>
                </div>

                <div class="col-md-8">
                </div>

            </div>
        </form>
        <hr>

        <?php if ($num_rows[0] == 0) { ?>
            <p class="text-secondary">
                No onboarding templates yet. Create one to define a checklist of tasks (and an optional contract) to apply when onboarding a new client to your services.
            </p>
        <?php } ?>

        <div class="table-responsive-sm">
            <table class="table table-striped table-borderless table-hover">
                <thead class="text-dark <?php if($num_rows[0] == 0){ echo "d-none"; } ?>">
                <tr>
                    <th>
                        <a class="text-secondary" href="?<?php echo $url_query_strings_sort; ?>&sort=project_template_name&order=<?php echo $disp; ?>">
                            Template <?php if ($sort == 'project_template_name') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>Checklist Items</th>
                    <th>Default Contract</th>
                    <th class="text-center">Action</th>
                </tr>
                </thead>
                <tbody>
                <?php

                while($row = mysqli_fetch_assoc($sql)){
                    $project_template_id = intval($row['project_template_id']);
                    $project_template_name = nullable_htmlentities($row['project_template_name']);
                    $project_template_description = nullable_htmlentities($row['project_template_description']);
                    $project_template_default_contract_template_id = intval($row['project_template_default_contract_template_id']);

                    $default_contract_template_name = '-';
                    if ($project_template_default_contract_template_id) {
                        $default_contract_template_name = nullable_htmlentities(getFieldById('contract_templates', $project_template_default_contract_template_id, 'contract_template_name'));
                    }

                    // Get Checklist Item Count
                    $sql_task_templates = mysqli_query($mysqli,
                        "SELECT * FROM ticket_templates, task_templates, project_template_ticket_templates
                        WHERE ticket_templates.ticket_template_id = project_template_ticket_templates.ticket_template_id
                        AND project_template_ticket_templates.project_template_id = $project_template_id
                        AND ticket_templates.ticket_template_id = task_template_ticket_template_id"
                    );
                    $task_template_count = mysqli_num_rows($sql_task_templates);

                    ?>
                    <tr>
                        <td>
                            <a class="text-dark" href="onboarding_template_details.php?project_template_id=<?= $project_template_id ?>">
                                <div class="media">
                                    <i class="fa fa-fw fa-2x fa-user-plus me-3"></i>
                                    <div class="media-body">
                                        <div>
                                            <?= $project_template_name ?>
                                        </div>
                                        <div>
                                            <small class="text-secondary"><?= $project_template_description ?></small>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        </td>
                        <td><?php echo $task_template_count; ?></td>
                        <td><?php echo $default_contract_template_name; ?></td>
                        <td>
                            <div class="dropdown dropleft text-center">
                                <button class="btn btn-secondary btn-sm" data-bs-toggle="dropdown">
                                    <i class="fas fa-ellipsis-h"></i>
                                </button>
                                <div class="dropdown-menu">
                                    <a class="dropdown-item" href="onboarding_template_details.php?project_template_id=<?= $project_template_id ?>">
                                        <i class="fas fa-fw fa-eye me-2"></i>View
                                    </a>
                                    <a class="dropdown-item ajax-modal" href="#" data-modal-url="modals/onboarding_template/onboarding_template_edit.php?project_template_id=<?= $project_template_id ?>">
                                        <i class="fas fa-fw fa-edit me-2"></i>Edit
                                    </a>
                                    <?php if($session_user_role == 3) { ?>
                                        <div class="dropdown-divider"></div>
                                        <a class="dropdown-item text-danger text-bold confirm-link" href="post.php?delete_onboarding_template=<?php echo $project_template_id; ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                            <i class="fas fa-fw fa-trash me-2"></i>Delete
                                        </a>
                                    <?php } ?>
                                </div>
                            </div>
                        </td>
                    </tr>

                <?php

                }

                ?>

                </tbody>
            </table>
        </div>

        <?php require_once "../includes/filter_footer.php"; ?>
    </div>
</div>

<?php

require_once "../includes/footer.php";
