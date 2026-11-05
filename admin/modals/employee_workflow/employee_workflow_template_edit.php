<?php

require_once '../../../includes/modal_header.php';

$workflow_template_id = intval($_GET['id']);

$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM workflow_templates WHERE workflow_template_id = $workflow_template_id LIMIT 1"));

$name = nullable_htmlentities($row['name']);
$type = $row['type'];
$description = nullable_htmlentities($row['description']);

ob_start();

?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-tasks me-2"></i>Edit Employee Workflow Template</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="workflow_template_id" value="<?= $workflow_template_id ?>">

    <div class="modal-body">
        <div class="form-group">
            <label>Template Name <strong class="text-danger">*</strong></label>
            <input type="text" class="form-control" name="name" value="<?= $name ?>" maxlength="200" required autofocus>
        </div>

        <div class="form-group">
            <label>Type <strong class="text-danger">*</strong></label>
            <select class="form-control select2" name="type" required>
                <option value="onboarding" <?= $type === 'onboarding' ? 'selected' : '' ?>>Onboarding</option>
                <option value="offboarding" <?= $type === 'offboarding' ? 'selected' : '' ?>>Offboarding</option>
                <option value="role_change" <?= $type === 'role_change' ? 'selected' : '' ?>>Role change</option>
                <option value="transfer" <?= $type === 'transfer' ? 'selected' : '' ?>>Department transfer</option>
            </select>
        </div>

        <div class="form-group">
            <label>Description</label>
            <textarea class="form-control" name="description" rows="3" placeholder="What this template is for / when to use it"><?= $description ?></textarea>
        </div>

        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="tmplActive" <?= !empty($row['is_active']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="tmplActive">Active (can be started, and chosen by event rules)</label>
        </div>

        <h6 class="mb-1">Onboarding form <span class="text-muted small">(optional)</span></h6>
        <p class="text-muted small mb-2">Questions asked when someone starts this workflow. Use an answer as <code>{{var.field_name}}</code> in action text, or to include a task only for some answers (task option &ldquo;Only when&rdquo;). Field names: lower-case letters, digits and underscores.</p>
        <?php
        $defs = \ITFlow\Workflow\TemplateVariables::definitions($row['variables_json'] ?? null);
        for ($i = 0; $i < 6; $i++) { $d = $defs[$i] ?? ['name' => '', 'label' => '', 'type' => 'text', 'required' => false, 'default' => '', 'options' => []]; ?>
        <div class="row g-2 mb-2 align-items-center">
            <div class="col-4 col-md-3"><input type="text" class="form-control form-control-sm" name="vars[<?= $i ?>][name]" placeholder="field_name" maxlength="30" value="<?= nullable_htmlentities($d['name']) ?>"></div>
            <div class="col-8 col-md-3"><input type="text" class="form-control form-control-sm" name="vars[<?= $i ?>][label]" placeholder="Question" maxlength="100" value="<?= nullable_htmlentities($d['label'] === $d['name'] ? '' : $d['label']) ?>"></div>
            <div class="col-5 col-md-2"><select class="form-select form-select-sm" name="vars[<?= $i ?>][type]"><?php foreach (\ITFlow\Workflow\TemplateVariables::TYPES as $k => $l) { ?><option value="<?= $k ?>" <?= $d['type'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php } ?></select></div>
            <div class="col-7 col-md-2"><input type="text" class="form-control form-control-sm" name="vars[<?= $i ?>][options]" placeholder="Choices: a, b" title="Choices, comma separated" maxlength="400" value="<?= nullable_htmlentities(implode(', ', $d['options'])) ?>"></div>
            <div class="col-12 col-md-2"><label class="form-check small text-nowrap mb-0"><input type="checkbox" class="form-check-input" name="vars[<?= $i ?>][required]" value="1" <?= $d['required'] ? 'checked' : '' ?>><span class="form-check-label">Required</span></label></div>
        </div>
        <?php } ?>
    </div>
    <div class="modal-footer">
        <button type="submit" name="edit_employee_workflow_template" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Save changes</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php
require_once '../../../includes/modal_footer.php';
