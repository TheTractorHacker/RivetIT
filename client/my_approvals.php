<?php
/*
 * Client Portal
 * My approvals - service catalog requests waiting on this contact (a manager named by "Requester's manager" steps).
 * Only the logged-in contact's own pending approvals are listed, scoped to their department.
 */

// inc_all.php streams the page chrome before control returns, so the not-an-approver redirect below needs output buffered.
ob_start();
require_once "includes/inc_all.php";

require_once $_SERVER['DOCUMENT_ROOT'] . '/src/ITSM/ServiceCatalogService.php';
$catalog_service = new \ITFlow\ITSM\ServiceCatalogService($mysqli);

// Gate: only a contact who is (or has been) an approver, i.e. a manager somebody reports to, sees this page.
if (!$catalog_service->contactIsApprover($session_contact_id, $session_client_id)) {
    flash_alert('You are not an approver for any requests', 'danger');
    redirect('index.php');
}

$approvals = $catalog_service->pendingApprovals(null, $session_contact_id, $session_client_id);

?>

    <ol class="breadcrumb d-print-none">
        <li class="breadcrumb-item"><a href="index.php">Home</a></li>
        <li class="breadcrumb-item active">My approvals</li>
    </ol>

    <div class="portal-pagehead">
        <div>
            <h2 class="portal-pagehead-title">My approvals</h2>
            <p class="text-secondary mb-0">Requests from your team that are waiting for your decision.</p>
        </div>
    </div>

    <?php if (count($approvals) === 0) { ?>
        <div class="card portal-card">
            <div class="portal-empty"><i class="fas fa-check-circle" aria-hidden="true"></i>Nothing is waiting for your approval.</div>
        </div>
    <?php } ?>

    <?php foreach ($approvals as $a) {
        $values = json_decode((string) $a['field_values'], true);
        $values = is_array($values) ? $values : [];
    ?>
        <div class="card portal-card mb-3">
            <div class="card-body">
                <h5 class="mb-1"><?= nullable_htmlentities($a['item_name']) ?></h5>
                <p class="text-secondary small mb-3">
                    Requested by <strong><?= nullable_htmlentities($a['requester_name']) ?></strong> on <?= nullable_htmlentities($a['created_at']) ?>
                    &middot; Ticket <?= nullable_htmlentities($a['ticket_prefix']) . intval($a['ticket_number']) ?> - <?= nullable_htmlentities($a['ticket_subject']) ?>
                </p>
                <?php if ($values) { ?>
                    <dl class="row mb-3">
                        <?php foreach ($values as $v) { ?>
                            <dt class="col-sm-4"><?= nullable_htmlentities($v['label'] ?? '') ?></dt>
                            <dd class="col-sm-8"><?= nl2br(nullable_htmlentities($v['value'] ?? '')) ?></dd>
                        <?php } ?>
                    </dl>
                <?php } ?>
                <form action="post.php" method="post" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="catalog_decide" value="1">
                    <input type="hidden" name="request_id" value="<?= intval($a['request_id']) ?>">
                    <div class="form-group">
                        <label>Comment <small class="text-secondary">(required if you reject)</small></label>
                        <textarea class="form-control" name="comment" rows="2" maxlength="2000"></textarea>
                    </div>
                    <button class="btn btn-success" name="decision" value="approve" type="submit"><i class="fas fa-check me-2" aria-hidden="true"></i>Approve</button>
                    <button class="btn btn-outline-danger" name="decision" value="reject" type="submit"><i class="fas fa-times me-2" aria-hidden="true"></i>Reject</button>
                </form>
            </div>
        </div>
    <?php } ?>

<?php
require_once "includes/footer.php";
