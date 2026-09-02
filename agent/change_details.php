<?php

require_once "includes/inc_all.php";

enforceUserPermission('module_support');

$change_id = intval($_GET['id'] ?? 0);

$change = mysqli_fetch_assoc(mysqli_query(
    $mysqli,
    "SELECT changes.*, u.user_name AS created_by_name
     FROM changes
     LEFT JOIN users u ON u.user_id = changes.created_by
     WHERE changes.change_id = $change_id"
));

if (!$change) {
    echo "<center><h1 class='text-secondary mt-5'>Nothing to see here</h1><a class='btn btn-lg btn-secondary mt-3' href='changes.php'><i class='fa fa-fw fa-arrow-left'></i> Go Back</a></center>";
    require_once "includes/footer.php";
    exit;
}

$change_status_badge = [
    'draft' => 'text-bg-secondary',
    'awaiting_approval' => 'text-bg-warning',
    'approved' => 'text-bg-info',
    'scheduled' => 'text-bg-primary',
    'in_progress' => 'text-bg-primary',
    'successful' => 'text-bg-success',
    'failed' => 'text-bg-danger',
    'rolled_back' => 'text-bg-dark',
    'cancelled' => 'text-bg-secondary',
];

$change_risk_badge = [
    'low' => 'text-bg-success',
    'medium' => 'text-bg-warning',
    'high' => 'text-bg-danger',
];

// Mirrors ChangeService::TRANSITIONS - kept in sync by hand since it's only
// used here to decide which action buttons to show; the service is what
// actually enforces this server-side.
$next_statuses = [
    'draft' => ['awaiting_approval' => 'Submit for Approval', 'cancelled' => 'Cancel'],
    'awaiting_approval' => ['draft' => 'Send Back to Draft', 'approved' => 'Approve', 'cancelled' => 'Cancel'],
    'approved' => ['scheduled' => 'Schedule', 'in_progress' => 'Start Now', 'cancelled' => 'Cancel'],
    'scheduled' => ['approved' => 'Unschedule', 'in_progress' => 'Start Now', 'cancelled' => 'Cancel'],
    'in_progress' => ['successful' => 'Mark Successful', 'failed' => 'Mark Failed', 'cancelled' => 'Cancel'],
    'failed' => ['rolled_back' => 'Mark Rolled Back', 'cancelled' => 'Cancel'],
    'successful' => ['rolled_back' => 'Mark Rolled Back'],
    'rolled_back' => [],
    'cancelled' => [],
][$change['status']] ?? [];

$linked_problems = mysqli_query(
    $mysqli,
    "SELECT problem_id, title, status FROM problems WHERE change_problem_id = $change_id ORDER BY created_at DESC"
);

?>

<ol class="breadcrumb d-print-none">
    <li class="breadcrumb-item"><a href="changes.php"><i class="fas fa-fw fa-exchange-alt me-1"></i>Changes</a></li>
    <li class="breadcrumb-item active"><?= nullable_htmlentities($change['title']) ?></li>
</ol>

<div class="row">
    <div class="col-md-8">
        <div class="card card-dark mb-3">
            <div class="card-header py-2 d-flex align-items-center">
                <h3 class="card-title me-auto mb-0">
                    <?= nullable_htmlentities($change['title']) ?>
                    <span class="badge <?= $change_risk_badge[$change['risk']] ?? 'text-bg-secondary' ?> ms-2"><?= ucfirst($change['risk']) ?> Risk</span>
                    <span class="badge <?= $change_status_badge[$change['status']] ?? 'text-bg-secondary' ?> ms-1"><?= ucwords(str_replace('_', ' ', $change['status'])) ?></span>
                </h3>
                <button type="button" class="btn btn-sm btn-default ajax-modal" data-modal-url="modals/change/change_edit.php?id=<?= $change_id ?>"><i class="fas fa-edit me-1"></i>Edit</button>
            </div>
            <div class="card-body">
                <?php if ($change['reason']) { ?>
                    <h6 class="text-muted">Reason</h6>
                    <p style="white-space: pre-wrap;"><?= nullable_htmlentities($change['reason']) ?></p>
                <?php } ?>
                <?php if ($change['impact']) { ?>
                    <h6 class="text-muted">Impact</h6>
                    <p style="white-space: pre-wrap;"><?= nullable_htmlentities($change['impact']) ?></p>
                <?php } ?>
                <?php if ($change['implementation_plan']) { ?>
                    <h6 class="text-muted">Implementation Plan</h6>
                    <p style="white-space: pre-wrap;"><?= nullable_htmlentities($change['implementation_plan']) ?></p>
                <?php } ?>
                <?php if ($change['rollback_plan']) { ?>
                    <h6 class="text-muted">Rollback Plan</h6>
                    <p style="white-space: pre-wrap;"><?= nullable_htmlentities($change['rollback_plan']) ?></p>
                <?php } ?>

                <?php if (!empty($next_statuses)) { ?>
                    <hr>
                    <div>
                        <?php foreach ($next_statuses as $status_value => $label) { ?>
                            <form action="post.php" method="post" class="d-inline-flex align-items-center me-1 mb-1">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                <input type="hidden" name="change_id" value="<?= $change_id ?>">
                                <input type="hidden" name="status" value="<?= $status_value ?>">
                                <?php if ($status_value === 'scheduled') { ?>
                                    <input type="datetime-local" class="form-control form-control-sm me-1" name="scheduled_at" style="width:200px;" value="<?= $change['scheduled_at'] ? date('Y-m-d\TH:i', strtotime($change['scheduled_at'])) : '' ?>" <?= $change['scheduled_at'] ? '' : 'required' ?>>
                                <?php } ?>
                                <button type="submit" name="set_change_status" class="btn btn-sm btn-outline-secondary"><?= $label ?></button>
                            </form>
                        <?php } ?>
                    </div>
                <?php } ?>

                <?php if ($change['scheduled_at'] && !in_array($change['status'], ['successful', 'failed', 'rolled_back', 'cancelled'], true)) { ?>
                    <form action="post.php" method="post" class="d-inline-flex align-items-center mt-2">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="change_id" value="<?= $change_id ?>">
                        <label class="small text-muted me-2 mb-0">Reschedule</label>
                        <input type="datetime-local" class="form-control form-control-sm me-1" name="scheduled_at" style="width:200px;" value="<?= date('Y-m-d\TH:i', strtotime($change['scheduled_at'])) ?>" required>
                        <button type="submit" name="reschedule_change" class="btn btn-sm btn-light">Update</button>
                    </form>
                <?php } ?>
            </div>
            <div class="card-footer py-2 text-muted small">
                Created by <?= nullable_htmlentities($change['created_by_name'] ?? 'Unknown') ?> on <?= nullable_htmlentities($change['created_at']) ?>
                <?php if ($change['scheduled_at']) { ?>
                    &middot; Scheduled for <?= nullable_htmlentities($change['scheduled_at']) ?>
                <?php } ?>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card card-dark">
            <div class="card-header py-2">
                <h5 class="card-title mb-0"><i class="fas fa-exclamation-circle me-2"></i>Linked Problems</h5>
            </div>
            <div class="card-body">
                <?php if (mysqli_num_rows($linked_problems) === 0) { ?>
                    <p class="text-muted mb-0">No problems link to this change.</p>
                <?php } else { ?>
                    <ul class="list-unstyled mb-0">
                        <?php while ($p = mysqli_fetch_assoc($linked_problems)) { ?>
                            <li class="mb-1">
                                <a href="problem_details.php?id=<?= intval($p['problem_id']) ?>"><?= nullable_htmlentities($p['title']) ?></a>
                                <span class="text-muted small">(<?= ucfirst($p['status']) ?>)</span>
                            </li>
                        <?php } ?>
                    </ul>
                <?php } ?>
            </div>
        </div>
    </div>
</div>

<?php require_once "includes/footer.php"; ?>
