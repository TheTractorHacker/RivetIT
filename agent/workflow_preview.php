<?php
require_once "includes/inc_all.php";

// Dry run of starting a workflow: read-only (it writes, queues and sends nothing), so no CSRF token is needed.
enforceUserPermission('module_client');

$contact_id = intval($_GET['contact_id'] ?? 0);
$workflow_template_id = intval($_GET['workflow_template_id'] ?? 0);

$contact = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT contact_client_id FROM contacts WHERE contact_id = $contact_id"));
if (!$contact) {
    flash_alert('Contact not found', 'error');
    redirect('clients.php');
}
$client_id = intval($contact['contact_client_id']);
enforceClientAccess($client_id);
require_once __DIR__ . '/includes/workflow_variable_fields.php';
$wf_answers = workflowVariablesFromRequest($_GET);

try {
    $preview = (new \ITFlow\Workflow\WorkflowService($mysqli))->previewRun($workflow_template_id, $contact_id, $wf_answers);
} catch (\InvalidArgumentException $e) {
    flash_alert('Workflow template not found', 'error');
    redirect("contact_details.php?contact_id=$contact_id&client_id=$client_id");
}
$tmpl = $preview['template'];
$type_labels = ['manual' => 'Manual', 'approval' => 'Approval', 'action' => 'Automated'];

?>

<div class="card card-dark mb-3">
    <div class="card-header py-2 d-flex align-items-center">
        <h3 class="card-title me-auto">
            <i class="fas fa-fw fa-eye me-2"></i>Preview: <?= nullable_htmlentities($tmpl['name']) ?> for <?= nullable_htmlentities($preview['contact']['contact_name']) ?>
            <span class="badge <?= ['onboarding' => 'text-bg-success', 'offboarding' => 'text-bg-danger'][$tmpl['type']] ?? 'text-bg-info' ?> ms-2"><?= ucwords(str_replace('_', ' ', nullable_htmlentities($tmpl['type']))) ?></span>
        </h3>
        <a href="contact_details.php?contact_id=<?= $contact_id ?>&client_id=<?= $client_id ?>" class="btn btn-sm btn-default"><i class="fas fa-arrow-left me-1"></i>Back to Contact</a>
    </div>
    <div class="card-body">
        <div class="alert alert-info py-2">This is a dry run. Nothing has been started, created, sent or queued. Tasks are listed in the order they become ready.</div>
        <?php foreach ($preview['warnings'] as $w) { ?><div class="alert alert-warning py-2"><?= nullable_htmlentities($w) ?></div><?php } ?>
        <?php if ($preview['excluded']) { ?>
            <div class="alert alert-secondary py-2"><strong>Left out for this person:</strong>
                <?php foreach ($preview['excluded'] as $x) { ?><div class="small"><?= nullable_htmlentities($x['title']) ?> &mdash; <?= nullable_htmlentities($x['reason']) ?></div><?php } ?>
            </div>
        <?php } ?>
        <?php $wf_defs = \ITFlow\Workflow\TemplateVariables::definitions($tmpl['variables_json'] ?? null); if ($wf_defs) { ?>
            <form method="get" action="workflow_preview.php" class="border rounded p-2 mb-3">
                <input type="hidden" name="contact_id" value="<?= $contact_id ?>"><input type="hidden" name="workflow_template_id" value="<?= intval($workflow_template_id) ?>">
                <div class="small text-muted mb-1">Onboarding form: change an answer to see which tasks it adds or removes.</div>
                <?php workflowVariableFields($wf_defs, $wf_answers, 'pv'); ?>
                <button type="submit" class="btn btn-sm btn-outline-secondary">Update preview</button>
            </form>
        <?php } ?>
        <?php if (!$preview['tasks']) { ?>
            <p class="text-muted">This template has no tasks.</p>
        <?php } else { ?>
        <div class="table-responsive"><table class="table table-sm" style="min-width:720px">
            <thead><tr><th>#</th><th>Task</th><th>Type</th><th>Starts</th><th>Waits for</th><th>Due</th><th>Assigned to</th><th>What would happen</th></tr></thead>
            <tbody>
            <?php foreach ($preview['tasks'] as $t) { ?>
                <tr>
                    <td><?= intval($t['order']) ?></td>
                    <td><strong><?= nullable_htmlentities($t['title']) ?></strong><?= $t['required'] ? '' : ' <span class="badge text-bg-light text-secondary">optional</span>' ?></td>
                    <td><?= nullable_htmlentities($type_labels[$t['type']] ?? $t['type']) ?></td>
                    <td><?= $t['starts'] === 'ready' ? '<span class="badge text-bg-primary">ready</span>' : '<span class="badge text-bg-secondary">blocked</span>' ?></td>
                    <td class="small"><?= nullable_htmlentities(implode(', ', $t['depends_on_titles'])) ?></td>
                    <td class="small text-nowrap"><?= $t['due_at'] ? nullable_htmlentities($t['due_at']) : '<span class="text-muted">none</span>' ?><?= !empty($t['run_after']) ? '<div class="text-info">Runs not before ' . nullable_htmlentities($t['run_after']) . '</div>' : '' ?></td>
                    <td class="small"><?= nullable_htmlentities($t['assignee']) ?></td>
                    <td class="small"><?= nullable_htmlentities($t['what']) ?></td>
                </tr>
            <?php } ?>
            </tbody>
        </table></div>
        <?php if (lookupUserPermission('module_client') >= 2) { ?>
        <form action="post.php" method="post">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="contact_id" value="<?= $contact_id ?>">
            <input type="hidden" name="workflow_template_id" value="<?= intval($workflow_template_id) ?>">
            <?php foreach ($preview['variables'] as $vn => $vv) { ?><input type="hidden" name="var[<?= nullable_htmlentities($vn) ?>]" value="<?= nullable_htmlentities($vv) ?>"><?php } ?>
            <button type="submit" name="start_employee_workflow" class="btn btn-primary"><i class="fas fa-play me-2"></i>Start this workflow</button>
        </form>
        <?php } ?>
        <?php } ?>
    </div>
</div>

<?php require_once "../includes/footer.php"; ?>
