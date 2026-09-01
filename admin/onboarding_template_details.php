<?php

require_once "includes/inc_all_admin.php";

if (isset($_GET['project_template_id'])) {
    $project_template_id = intval($_GET['project_template_id']);

    $sql_project_template = mysqli_query($mysqli, "SELECT * FROM project_templates
        WHERE project_template_id = $project_template_id AND project_template_is_onboarding = 1 LIMIT 1");

    if (mysqli_num_rows($sql_project_template) == 0) {
        echo "<center><h1 class='text-secondary mt-5'>Nothing to see here</h1><a class='btn btn-lg btn-secondary mt-3' href='javascript:history.back()'><i class='fa fa-fw fa-arrow-left'></i> Go Back</a></center>";

        require_once "../includes/footer.php";
        exit;
    }

    $row = mysqli_fetch_assoc($sql_project_template);

    $project_template_name = nullable_htmlentities($row['project_template_name']);
    $project_template_description = nullable_htmlentities($row['project_template_description']);
    $project_template_default_contract_template_id = intval($row['project_template_default_contract_template_id']);

    $project_template_default_contract_template_name = '';
    if ($project_template_default_contract_template_id) {
        $project_template_default_contract_template_name = nullable_htmlentities(getFieldById('contract_templates', $project_template_default_contract_template_id, 'contract_template_name'));
    }

    // Get (or create) the companion checklist ticket template for this onboarding template
    $sql_ticket_template = mysqli_query($mysqli, "SELECT ticket_templates.ticket_template_id FROM ticket_templates
        INNER JOIN project_template_ticket_templates ON project_template_ticket_templates.ticket_template_id = ticket_templates.ticket_template_id
        WHERE project_template_ticket_templates.project_template_id = $project_template_id
        ORDER BY ticket_template_order ASC LIMIT 1");
    $ticket_template_row = mysqli_fetch_assoc($sql_ticket_template);
    $ticket_template_id = intval($ticket_template_row['ticket_template_id']);

    // Get Checklist Tasks
    $sql_task_templates = mysqli_query($mysqli, "SELECT * FROM task_templates WHERE task_template_ticket_template_id = $ticket_template_id ORDER BY task_template_order ASC, task_template_id ASC");

?>

<!-- Breadcrumbs-->
<ol class="breadcrumb d-print-none">
    <li class="breadcrumb-item">
        <a href="admin_user.php">Admin</a>
    </li>
    <li class="breadcrumb-item">
        <a href="onboarding_templates.php">Onboarding Templates</a>
    </li>
    <li class="breadcrumb-item active"><?php echo $project_template_name; ?></li>
</ol>

<!-- Header -->
<div class="card card-body">
    <div class="row">
        <div class="col-sm-7">
            <div class="media">
                <i class="fa fa-fw fa-2x fa-user-plus text-secondary me-3"></i>
                <div class="media-body">
                    <h3 class="mb-0"><?php echo $project_template_name; ?><span class='badge rounded-pill text-bg-info ms-2'>Onboarding Template</span></h3>
                    <div><small class="text-secondary"><?php echo $project_template_description; ?></small></div>
                </div>
            </div>
        </div>

        <div class="col-sm-3">
            <div class="media">
                <i class="fa fa-fw fa-2x fa-file-contract text-secondary me-3"></i>
                <div class="media-body">
                    <div>Default Contract</div>
                    <h6 class="mb-0"><?php echo $project_template_default_contract_template_name ?: '-'; ?></h6>
                </div>
            </div>
        </div>

        <div class="col-sm-2">
            <div class="btn-group float-end">
                <div class="dropdown dropleft text-center">
                    <button class="btn btn-secondary btn-sm" type="button" id="dropdownMenuButton" data-bs-toggle="dropdown" data-boundary="window">
                        <i class="fas fa-fw fa-ellipsis-v"></i>
                    </button>
                    <div class="dropdown-menu" aria-labelledby="dropdownMenuButton">
                        <a class="dropdown-item ajax-modal" href="#" data-modal-url="modals/onboarding_template/onboarding_template_edit.php?project_template_id=<?= $project_template_id ?>">
                            <i class="fas fa-fw fa-edit me-2"></i>Edit Template
                        </a>
                        <?php if ($session_user_role == 3) { ?>
                            <div class="dropdown-divider"></div>
                            <a class="dropdown-item text-danger confirm-link" href="post.php?delete_onboarding_template=<?php echo $project_template_id; ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                <i class="fas fa-fw fa-trash me-2"></i>Delete
                            </a>
                        <?php } ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-6">

        <!-- Checklist Card -->
        <div class="card card-body">
            <h5 class="text-secondary"><i class="fas fa-fw fa-tasks me-2"></i>Onboarding Checklist</h5>

            <form action="post.php" method="post" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="ticket_template_id" value="<?php echo $ticket_template_id; ?>">
                <div class="form-group">
                    <div class="input-group input-group-sm">
                        <input type="text" class="form-control" name="task_name" placeholder="Add a checklist item" required maxlength="200">
                        <div class="input-group-append">
                            <button type="submit" name="add_checklist_task" class="btn btn-primary"><i class="fas fa-fw fa-check"></i></button>
                        </div>
                    </div>
                </div>
            </form>

            <table class="table table-sm" id="checklist_tasks">
                <tbody>
                <?php
                while($task_row = mysqli_fetch_assoc($sql_task_templates)){
                    $task_template_id = intval($task_row['task_template_id']);
                    $task_template_name = nullable_htmlentities($task_row['task_template_name']);
                ?>
                    <tr data-task-id="<?php echo $task_template_id; ?>">
                        <td>
                            <a href="#" class="drag-handle"><i class="fas fa-bars text-muted me-2"></i></a>
                            <span class="text-dark"><?php echo $task_template_name; ?></span>
                        </td>
                        <td class="text-end">
                            <div class="float-end">
                                <div class="dropdown dropleft text-center">
                                    <button class="btn btn-light text-secondary btn-sm" type="button" data-bs-toggle="dropdown" data-boundary="window">
                                        <i class="fas fa-ellipsis-v"></i>
                                    </button>
                                    <div class="dropdown-menu">
                                        <a class="dropdown-item ajax-modal" href="#"
                                            data-modal-url="modals/ticket_template/ticket_template_task_edit.php?id=<?= $task_template_id ?>">
                                            <i class="fas fa-fw fa-edit me-2"></i>Edit
                                        </a>
                                        <div class="dropdown-divider"></div>
                                        <a class="dropdown-item text-danger confirm-link" href="post.php?delete_task_template=<?php echo $task_template_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token'] ?>">
                                            <i class="fas fa-fw fa-trash-alt me-2"></i>Delete
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>

            <?php if (mysqli_num_rows($sql_task_templates) == 0) { ?>
                <p class="text-secondary mb-0">No checklist items yet. Add tasks above to build out this onboarding checklist.</p>
            <?php } ?>
        </div>
        <!-- End Checklist Card -->

    </div>
</div>

<script src="../plugins/SortableJS/Sortable.min.js"></script>
<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
new Sortable(document.querySelector('table#checklist_tasks tbody'), {
    handle: '.drag-handle',
    animation: 150,
    onEnd: function (evt) {
        const rows = document.querySelectorAll('table#checklist_tasks tbody tr');
        const positions = Array.from(rows).map((row, index) => ({
            id: row.dataset.taskId,
            order: index
        }));

        $.post('/agent/ajax.php', {
            update_task_templates_order: true,
            csrf_token: '<?= $_SESSION['csrf_token'] ?>',
            ticket_template_id: <?php echo $ticket_template_id; ?>,
            positions: positions
        });
    }
});
</script>

<?php

}

require_once "../includes/footer.php";

?>

<script src="js/pretty_content.js"></script>
