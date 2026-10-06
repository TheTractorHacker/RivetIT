<?php

/*
 * Service catalog approvals inbox: requests waiting on this user (directly or through a role), with approve/reject + comment.
 * Administrators also see every pending request and may decide a step on an approver's behalf (e.g. a manager with no portal login).
 */

require_once "includes/inc_all.php";

enforceUserPermission('module_support');

require_once $_SERVER['DOCUMENT_ROOT'] . '/src/ITSM/ServiceCatalogService.php';
$catalog_service = new \ITFlow\ITSM\ServiceCatalogService($mysqli);

$mine = $catalog_service->pendingApprovals(intval($session_user_id));
$all = $session_is_admin ? $catalog_service->pendingApprovals(null, null, 0, true) : [];
$mine_request_ids = array_map(fn($r) => intval($r['request_id']), $mine);
// Admin list: everything pending that is not already in "Waiting for me" (those are decided as the approver, not as an override)
$others = array_values(array_filter($all, fn($r) => !in_array(intval($r['request_id']), $mine_request_ids, true)));

function catalog_approval_card(array $a, bool $override) {
    $values = json_decode((string) $a['field_values'], true);
    $values = is_array($values) ? $values : [];
    ?>
    <div class="card mb-3">
        <div class="card-body">
            <div class="d-flex justify-content-between">
                <h5 class="mb-1"><?= nullable_htmlentities($a['item_name']) ?></h5>
                <span class="badge text-bg-secondary align-self-start">Risk <?= intval($a['risk_score']) ?></span>
            </div>
            <p class="text-secondary small mb-2">
                <?= nullable_htmlentities($a['client_name']) ?> &middot; requested by <?= nullable_htmlentities($a['requester_name'] ?: 'an agent') ?> on <?= nullable_htmlentities($a['created_at']) ?>
                &middot; <a href="ticket.php?ticket_id=<?= intval($a['ticket_id']) ?>&amp;client_id=<?= intval($a['client_id']) ?>"><?= nullable_htmlentities($a['ticket_prefix']) . intval($a['ticket_number']) ?> - <?= nullable_htmlentities($a['ticket_subject']) ?></a>
                &middot; step <?= intval($a['step_order']) ?>
            </p>
            <?php if ($values) { ?>
                <dl class="row mb-2">
                    <?php foreach ($values as $v) { ?>
                        <dt class="col-sm-3"><?= nullable_htmlentities($v['label'] ?? '') ?></dt>
                        <dd class="col-sm-9"><?= nl2br(nullable_htmlentities($v['value'] ?? '')) ?></dd>
                    <?php } ?>
                </dl>
            <?php } ?>
            <form action="post.php" method="post" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="request_id" value="<?= intval($a['request_id']) ?>">
                <?php if ($override) { ?><input type="hidden" name="override" value="1"><?php } ?>
                <div class="input-group mb-2">
                    <input type="text" class="form-control" name="comment" maxlength="2000" placeholder="Comment (required if you reject)">
                    <button class="btn btn-success" type="submit" name="decide_service_catalog_request" value="approve"><i class="fas fa-check me-2"></i>Approve</button>
                    <button class="btn btn-outline-danger" type="submit" name="decide_service_catalog_request" value="reject"><i class="fas fa-times me-2"></i>Reject</button>
                </div>
            </form>
        </div>
    </div>
    <?php
}

?>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-user-check me-2"></i>Approvals</h3>
    </div>
    <div class="card-body">
        <h6>Waiting for me</h6>
        <?php if (!$mine) { ?>
            <p class="text-secondary">Nothing is waiting for your approval.</p>
        <?php } ?>
        <?php foreach ($mine as $a) { catalog_approval_card($a, false); } ?>

        <?php if ($session_is_admin) { ?>
            <hr>
            <h6>Other pending requests <small class="text-secondary">(administrator override: decides the current step for its approvers, and is recorded as such)</small></h6>
            <?php if (!$others) { ?>
                <p class="text-secondary">No other requests are pending.</p>
            <?php } ?>
            <?php foreach ($others as $a) { catalog_approval_card($a, true); } ?>
        <?php } ?>
    </div>
</div>

<?php
require_once "../includes/footer.php";
