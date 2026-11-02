<?php
require_once "includes/inc_all_admin.php";

enforceUserPermission('module_client', 2);

$preview = $_SESSION['bulk_onboarding_preview'] ?? null;
$templates = mysqli_fetch_all(mysqli_query($mysqli, "SELECT workflow_template_id, name, type, variables_json FROM workflow_templates WHERE is_active = 1 AND archived_at IS NULL ORDER BY type, name"), MYSQLI_ASSOC);
$h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-fw fa-users me-2"></i>Start a workflow for many people</h3>
    </div>
    <div class="card-body">

    <?php if (!$preview) { ?>
        <p class="text-muted">Start one onboarding, offboarding, role-change or transfer template for several people at once. Paste email addresses (one per line) or upload a CSV with an <code>email</code> column; if the template has an onboarding form, add one column per question (the field name). Nothing starts until you review the preview. A person who already has the workflow open is skipped.</p>
        <?php if (!$templates) { ?>
            <div class="alert alert-info">There is no active workflow template yet. <a href="employee_workflow_templates.php">Create one</a> first.</div>
        <?php } else { ?>
        <form action="post.php" method="post" enctype="multipart/form-data" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <div class="mb-3">
                <label class="form-label" for="bulkTemplate">Workflow template</label>
                <select class="form-select" id="bulkTemplate" name="workflow_template_id" required>
                    <?php foreach ($templates as $t) { $defs = \ITFlow\Workflow\TemplateVariables::definitions($t['variables_json']); ?>
                        <option value="<?= intval($t['workflow_template_id']) ?>">[<?= $h(ucwords(str_replace('_', ' ', $t['type']))) ?>] <?= $h($t['name']) ?><?= $defs ? ' (form: ' . $h(implode(', ', array_column($defs, 'name'))) . ')' : '' ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label" for="bulkEmails">Email addresses or CSV text</label>
                <textarea class="form-control" id="bulkEmails" name="emails" rows="6" placeholder="jane@example.com&#10;sam@example.com" maxlength="40000"></textarea>
            </div>
            <div class="mb-3">
                <label class="form-label" for="bulkFile">or a CSV file</label>
                <input type="file" class="form-control" id="bulkFile" name="file" accept=".csv">
            </div>
            <button type="submit" name="preview_bulk_onboarding" class="btn btn-primary text-bold"><i class="fas fa-eye me-2"></i>Preview</button>
        </form>
        <?php } ?>
    <?php } else { ?>
        <?php
        $ready = count(array_filter($preview['rows'], static fn ($r) => $r['status'] === 'ready'));
        $skip = count(array_filter($preview['rows'], static fn ($r) => $r['status'] === 'skip'));
        $err = count($preview['rows']) - $ready - $skip;
        ?>
        <p><strong><?= $h($preview['template']['name']) ?></strong>:
            <span class="badge text-bg-success"><?= $ready ?> to start</span>
            <span class="badge text-bg-secondary"><?= $skip ?> already open</span>
            <span class="badge text-bg-danger"><?= $err ?> with problems (skipped)</span></p>
        <div class="table-responsive">
            <table class="table table-sm table-bordered">
                <thead><tr><th>Result</th><th>Person</th><th>Email</th><th>Form answers</th><th>Note</th></tr></thead>
                <tbody>
                <?php foreach ($preview['rows'] as $r) { ?>
                    <tr class="<?= $r['status'] === 'error' ? 'table-danger' : '' ?>">
                        <td><?= $r['status'] === 'ready' ? '<span class="badge text-bg-success">Start</span>' : ($r['status'] === 'skip' ? '<span class="badge text-bg-secondary">Skip</span>' : '<span class="badge text-bg-danger">Problem</span>') ?></td>
                        <td><?= $h($r['name']) ?></td>
                        <td><?= $h($r['email']) ?></td>
                        <td class="small"><?php foreach ($r['answers'] as $k => $v) { echo $h($k) . ' = ' . $h($v) . '<br>'; } ?></td>
                        <td class="small text-danger"><?= $h($r['message']) ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
        <form action="post.php" method="post" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <button type="submit" name="approve_bulk_onboarding" class="btn btn-primary text-bold" <?= $ready === 0 ? 'disabled' : '' ?>><i class="fas fa-play me-2"></i>Start <?= $ready ?> workflow(s)</button>
        </form>
        <form action="post.php" method="post" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <button type="submit" name="cancel_bulk_onboarding" class="btn btn-light"><i class="fas fa-times me-2"></i>Cancel, start over</button>
        </form>
    <?php } ?>

    </div>
</div>

<?php require_once "../includes/footer.php"; ?>
