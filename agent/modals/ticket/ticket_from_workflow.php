<?php

require_once '../../../includes/modal_header.php';
require_once '../../includes/workflow_variable_fields.php';

enforceUserPermission('module_client', 2);

$client_id = intval($_GET['client_id'] ?? 0);
if ($client_id) {
    enforceClientAccess($client_id);
}

$templates = mysqli_fetch_all(mysqli_query($mysqli, "SELECT workflow_template_id, name, type, description, variables_json FROM workflow_templates WHERE is_active = 1 AND archived_at IS NULL ORDER BY type ASC, name ASC"), MYSQLI_ASSOC);

$client_sql = $client_id ? "AND contact_client_id = $client_id" : '';
$contacts = mysqli_query($mysqli, "SELECT contact_id, contact_name, contact_title, client_name FROM contacts LEFT JOIN clients ON client_id = contact_client_id WHERE contact_archived_at IS NULL $client_sql $access_permission_query ORDER BY client_name ASC, contact_name ASC LIMIT 2000");

ob_start();

?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-tasks me-2"></i>New from workflow</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<form action="post.php" method="post" autocomplete="off" id="ticketFromWorkflowForm">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <div class="modal-body">
        <?php if (!$templates) { ?>
            <p class="text-muted mb-0">No workflow templates exist yet. An administrator can create them under Administration &gt; Employee Workflow Templates.</p>
        <?php } else { ?>
            <p class="text-muted small">Pick a person and a workflow. Its tasks are created for that person, and every "Create a ticket" step opens a ticket in their department. You can follow progress on the workflow page.</p>
            <div class="mb-3">
                <label class="form-label fw-bold">Person <span class="text-danger">*</span></label>
                <select class="form-control select2" name="contact_id" required>
                    <option value="">Select a person</option>
                    <?php while ($c = mysqli_fetch_assoc($contacts)) { ?>
                        <option value="<?= intval($c['contact_id']) ?>"><?= nullable_htmlentities($c['contact_name']) ?><?= $c['contact_title'] ? ' - ' . nullable_htmlentities($c['contact_title']) : '' ?><?= !$client_id && $c['client_name'] ? ' (' . nullable_htmlentities($c['client_name']) . ')' : '' ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label fw-bold">Workflow <span class="text-danger">*</span></label>
                <select class="form-control select2" name="workflow_template_id" id="tfwTemplate" required>
                    <option value="">Select a workflow</option>
                    <?php foreach ($templates as $t) { ?>
                        <option value="<?= intval($t['workflow_template_id']) ?>" data-desc="<?= nullable_htmlentities($t['description'] ?? '') ?>">[<?= nullable_htmlentities(ucwords(str_replace('_', ' ', $t['type']))) ?>] <?= nullable_htmlentities($t['name']) ?></option>
                    <?php } ?>
                </select>
                <div class="form-text" id="tfwDesc"></div>
            </div>
            <?php foreach ($templates as $t) { $defs = \ITFlow\Workflow\TemplateVariables::definitions($t['variables_json']); if (!$defs) { continue; } ?>
                <fieldset class="border rounded p-2 mb-2 tfw-vars" data-template="<?= intval($t['workflow_template_id']) ?>" hidden disabled>
                    <legend class="small text-muted w-auto px-1 mb-1">Form</legend>
                    <?php workflowVariableFields($defs, [], 'tfw' . intval($t['workflow_template_id'])); ?>
                </fieldset>
            <?php } ?>
        <?php } ?>
    </div>
    <div class="modal-footer">
        <?php if ($templates) { ?>
        <button type="submit" name="start_employee_workflow" class="btn btn-primary text-bold"><i class="fas fa-play me-2"></i>Start workflow</button>
        <?php } ?>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>
<script>
(function () {
    var sel = document.getElementById('tfwTemplate');
    if (!sel) { return; }
    function sync() {
        var o = sel.options[sel.selectedIndex];
        document.getElementById('tfwDesc').textContent = o ? (o.getAttribute('data-desc') || '') : '';
        document.querySelectorAll('#ticketFromWorkflowForm .tfw-vars').forEach(function (fs) {
            var on = fs.getAttribute('data-template') === sel.value;
            fs.hidden = !on; fs.disabled = !on;
        });
    }
    sel.addEventListener('change', sync);
    if (window.jQuery) { window.jQuery(sel).on('change', sync); }
    sync();
})();
</script>

<?php

require_once '../../../includes/modal_footer.php';
