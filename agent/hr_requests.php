<?php

/*
 * HR requests from the employee portal (issue #31): a manager or department administrator asked for an onboarding, offboarding or role-change
 * workflow. Nothing has started yet; approving here starts it (WorkflowService), rejecting starts nothing. Needs Departments edit access and
 * only lists departments this user may access.
 */

require_once "includes/inc_all.php";

enforceUserPermission('module_client', 2);

require_once $_SERVER['DOCUMENT_ROOT'] . '/src/Portal/EmployeeHome.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/src/Portal/HrRequests.php';
$hr = new \ITFlow\Portal\HrRequests($mysqli);
$hr_ready = \ITFlow\Portal\HrRequests::available($mysqli);

$status = in_array($_GET['status'] ?? 'pending', ['pending', 'approved', 'rejected', 'cancelled'], true) ? (string) ($_GET['status'] ?? 'pending') : 'pending';
$rows = $hr_ready ? $hr->queue($hr->allowedClients(intval($session_user_id)), $status) : [];
$focus = intval($_GET['request_id'] ?? 0);
$type_labels = \ITFlow\Portal\HrRequests::TYPES;
$h = static fn($v) => nullable_htmlentities((string) $v);
?>

<div class="card card-dark">
    <div class="card-header py-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h3 class="card-title mt-2 mb-0"><i class="fas fa-fw fa-user-clock me-2"></i>HR requests</h3>
        <div class="btn-group btn-group-sm" role="group" aria-label="Status">
            <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Withdrawn'] as $k => $l) { ?>
                <a class="btn btn-outline-secondary <?= $status === $k ? 'active' : '' ?>" href="hr_requests.php?status=<?= $k ?>"><?= $l ?></a>
            <?php } ?>
        </div>
    </div>
    <div class="card-body">
        <?php if (!$hr_ready) { ?>
            <p class="text-secondary mb-0">Run the database update first.</p>
        <?php } else { ?>
        <p class="text-secondary small">Managers and department administrators ask for these from the employee portal (when Administration &gt; Settings &gt; Employee portal allows it). <strong>Nothing has been started or changed</strong> until you approve a request here.</p>
        <?php if (!$rows) { ?><p class="text-secondary">No <?= $h($status) ?> requests.</p><?php } ?>
        <?php foreach ($rows as $r) {
            $p = json_decode((string) $r['hr_payload'], true) ?: [];
            $type = (string) $r['hr_type'];
            $cid = intval($r['hr_client_id']);
            $templates = $r['hr_status'] === 'pending' ? $hr->templatesFor($type) : [];
            $can = $r['hr_status'] === 'pending' && $hr->canDecide(intval($session_user_id), $cid); ?>
        <div class="card mb-3 <?= $focus === intval($r['hr_request_id']) ? 'border-primary' : '' ?>" id="hr<?= intval($r['hr_request_id']) ?>">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between gap-2">
                    <h5 class="mb-1"><?= $h($type_labels[$type] ?? $type) ?>: <?= $h($r['hr_subject_name']) ?></h5>
                    <span class="badge text-bg-<?= ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger', 'cancelled' => 'secondary'][$r['hr_status']] ?? 'secondary' ?> align-self-start"><?= $h($r['hr_status']) ?></span>
                </div>
                <p class="text-secondary small mb-2"><?= $h($r['client_name']) ?> &middot; requested by <?= $h($r['requester_name'] ?: 'a manager') ?> on <?= $h($r['hr_created_at']) ?></p>
                <dl class="row mb-2 small">
                    <?php if ($type === 'onboarding') { ?>
                        <dt class="col-sm-3">Email</dt><dd class="col-sm-9"><?= $h($p['email'] ?? '') ?></dd>
                        <dt class="col-sm-3">Start date</dt><dd class="col-sm-9"><?= $h($p['start_date'] ?? '') ?></dd>
                        <dt class="col-sm-3">Role / title</dt><dd class="col-sm-9"><?= $h($p['title'] ?? '') ?></dd>
                    <?php } elseif ($type === 'offboarding') { ?>
                        <dt class="col-sm-3">Last working day</dt><dd class="col-sm-9"><?= $h($p['effective_date'] ?? '') ?></dd>
                    <?php } else { ?>
                        <dt class="col-sm-3">New job title</dt><dd class="col-sm-9"><?= $h($p['new_title'] ?? '') ?></dd>
                        <dt class="col-sm-3">Effective</dt><dd class="col-sm-9"><?= $h($p['effective_date'] ?? '') ?></dd>
                    <?php } ?>
                    <?php if (($p['notes'] ?? '') !== '') { ?><dt class="col-sm-3">Notes</dt><dd class="col-sm-9"><?= nl2br($h($p['notes'])) ?></dd><?php } ?>
                    <?php if ($r['hr_status'] !== 'pending' && $r['hr_decision_comment']) { ?><dt class="col-sm-3">Decision note</dt><dd class="col-sm-9"><?= $h($r['hr_decision_comment']) ?></dd><?php } ?>
                    <?php if (intval($r['hr_run_id']) > 0) { ?><dt class="col-sm-3">Workflow</dt><dd class="col-sm-9"><a href="workflow_run.php?run_id=<?= intval($r['hr_run_id']) ?>">Open the run</a></dd><?php } ?>
                </dl>
                <?php if ($can) { ?>
                <form action="post.php" method="post" autocomplete="off" class="row g-2 align-items-end">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="hr_request_id" value="<?= intval($r['hr_request_id']) ?>">
                    <div class="col-md-4"><label class="form-label small mb-0" for="hrt<?= intval($r['hr_request_id']) ?>">Workflow to start</label>
                        <select class="form-select form-select-sm" name="template_id" id="hrt<?= intval($r['hr_request_id']) ?>">
                            <?php if (!$templates) { ?><option value="0">No active <?= $h(strtolower($type_labels[$type] ?? '')) ?> template</option><?php } ?>
                            <?php foreach ($templates as $t) { ?><option value="<?= intval($t['workflow_template_id']) ?>"><?= $h($t['name']) ?></option><?php } ?>
                        </select></div>
                    <div class="col-md-5"><label class="form-label small mb-0" for="hrc<?= intval($r['hr_request_id']) ?>">Note <span class="text-secondary">(required to reject; the requester sees it)</span></label>
                        <input type="text" class="form-control form-control-sm" name="comment" id="hrc<?= intval($r['hr_request_id']) ?>" maxlength="500"></div>
                    <div class="col-md-3 d-flex gap-2">
                        <button class="btn btn-sm btn-success flex-fill" type="submit" name="decide_hr_request" value="approve" <?= $templates ? '' : 'disabled' ?>><i class="fas fa-check me-1"></i>Approve</button>
                        <button class="btn btn-sm btn-outline-danger flex-fill" type="submit" name="decide_hr_request" value="reject"><i class="fas fa-times me-1"></i>Reject</button>
                    </div>
                </form>
                <?php } ?>
            </div>
        </div>
        <?php } ?>
        <?php } ?>
    </div>
</div>

<?php
require_once "../includes/footer.php";
