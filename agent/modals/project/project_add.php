<?php

require_once '../../../includes/modal_header.php';

$client_id = intval($_GET['client_id'] ?? 0);

ob_start();

?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-project-diagram me-2"></i>New Project</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

    <div class="modal-body">

        <?php if ($client_id) { ?>
            <input type="hidden" name="client_id" value="<?= $client_id ?>">
        <?php } else { ?>
            <div class="form-group">
                <label>Client</label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-users"></i></span>
                    </div>
                    <select class="form-control select2" name="client_id">
                        <option value="0">- No Client -</option>
                        <?php
                        $sql = mysqli_query($mysqli, "SELECT * FROM clients WHERE client_archived_at IS NULL $access_permission_query ORDER BY client_name ASC");
                        while ($row = mysqli_fetch_assoc($sql)) {
                            $client_id_select = intval($row['client_id']);
                            $client_name = nullable_htmlentities($row['client_name']);
                        ?>
                        <option value="<?php echo $client_id_select; ?>"><?php echo $client_name; ?></option>
                        <?php } ?>
                    </select>
                </div>
            </div>
        <?php } ?>

        <div class="form-group">
            <label>Project Name <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-project-diagram"></i></span>
                </div>
                <input type="text" class="form-control" name="name" placeholder="Project Name" maxlength="255" required autofocus>
            </div>
        </div>

        <div class="form-group">
            <label>Template</label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-cube"></i></span>
                </div>
                <select class="form-control select2" name="project_template_id" id="project_template_id">
                    <option value="">- Template -</option>
                    <?php
                    $sql = mysqli_query($mysqli, "SELECT * FROM project_templates WHERE project_template_archived_at IS NULL ORDER BY project_template_name ASC");
                    while ($row = mysqli_fetch_assoc($sql)) {
                        $project_template_id = intval($row['project_template_id']);
                        $project_template_name = nullable_htmlentities($row['project_template_name']);
                        $project_template_default_contract_template_id = intval($row['project_template_default_contract_template_id']);
                    ?>
                    <option value="<?php echo $project_template_id; ?>" data-contract-template-id="<?php echo $project_template_default_contract_template_id; ?>"><?php echo $project_template_name; ?></option>
                    <?php } ?>
                </select>
            </div>
        </div>

        <?php if ($client_id) { ?>
        <div class="form-group">
            <label>Apply Contract <small class="text-secondary">(optional)</small></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-file-contract"></i></span>
                </div>
                <select class="form-control select2" name="contract_template_id">
                    <option value="">- None -</option>
                    <?php
                    $sql_contract_templates = mysqli_query($mysqli, "SELECT contract_template_id, contract_template_name FROM contract_templates WHERE contract_template_archived_at IS NULL ORDER BY contract_template_name ASC");
                    while ($contract_template_row = mysqli_fetch_assoc($sql_contract_templates)) {
                    ?>
                    <option value="<?= intval($contract_template_row['contract_template_id']) ?>"><?= nullable_htmlentities($contract_template_row['contract_template_name']) ?></option>
                    <?php } ?>
                </select>
            </div>
            <small class="form-text text-secondary">Creates a contract for this client from the template and adds its terms to the onboarding ticket(s).</small>
        </div>
        <?php } ?>

        <div class="form-group">
            <label>Description</label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-align-left"></i></span>
                </div>
                <input type="text" class="form-control" name="description" placeholder="Description">
            </div>
        </div>


        <div class="form-row">
            <div class="form-group col-md-6">
                <label>Start Date</label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-calendar"></i></span>
                    </div>
                    <input type="date" class="form-control" name="start_date">
                </div>
            </div>
            <div class="form-group col-md-6">
                <label>Date Due <strong class="text-danger">*</strong></label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-calendar-check"></i></span>
                    </div>
                    <input type="date" class="form-control" name="due_date" required>
                </div>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group col-md-4">
                <label>Est. Hours</label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-clock"></i></span>
                    </div>
                    <input type="number" step="0.01" min="0" class="form-control" name="estimated_hours" placeholder="0">
                </div>
            </div>
            <div class="form-group col-md-4">
                <label>Budget</label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-dollar-sign"></i></span>
                    </div>
                    <input type="number" step="0.01" min="0" class="form-control" name="budget_amount" placeholder="0.00">
                </div>
            </div>
            <div class="form-group col-md-4">
                <label>Rate / hr</label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-tags"></i></span>
                    </div>
                    <input type="number" step="0.01" min="0" class="form-control" name="hourly_rate" placeholder="0.00">
                </div>
            </div>
        </div>

        <div class="form-group">
            <label>Project Manager</label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-user-tie"></i></span>
                </div>
                <select class="form-control select2" name="project_manager">
                    <option value="0">No Manager</option>
                    <?php

                    $sql = mysqli_query(
                        $mysqli,
                        "SELECT user_id, user_name FROM users
                        WHERE user_role_id > 1 AND user_status = 1 AND user_archived_at IS NULL ORDER BY user_name ASC"
                    );
                    while ($row = mysqli_fetch_assoc($sql)) {
                        $user_id = intval($row['user_id']);
                        $user_name = nullable_htmlentities($row['user_name']); ?>
                        <option value="<?php echo $user_id; ?>"><?php echo $user_name; ?></option>
                    <?php } ?>
                </select>
            </div>
        </div>

    </div>
    <div class="modal-footer">
        <button type="submit" name="add_project" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Create</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
$('#project_template_id').on('change', function () {
    const contractTemplateId = $(this).find(':selected').data('contract-template-id');
    const contractSelect = $('select[name="contract_template_id"]');

    if (contractSelect.length && contractTemplateId) {
        contractSelect.val(contractTemplateId).trigger('change');
    }
});
</script>

<?php
require_once '../../../includes/modal_footer.php';
