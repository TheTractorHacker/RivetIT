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
$my_delegations = $catalog_service->listDelegations(intval($session_user_id));
$delegate_candidates = [];
$dc = mysqli_query($mysqli, "SELECT user_id, user_name FROM users WHERE user_type = 1 AND user_status = 1 AND user_archived_at IS NULL AND user_id <> " . intval($session_user_id) . " ORDER BY user_name ASC");
while ($dc && ($d = mysqli_fetch_assoc($dc))) { $delegate_candidates[] = $d; }
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
                        <dd class="col-sm-9"><?= (($v['type'] ?? '') === 'table' && (string) ($v['value'] ?? '') !== '') ? \ITFlow\ITSM\ServiceCatalogService::tableValueHtml((string) $v['value']) : nl2br(nullable_htmlentities($v['value'] ?? '')) ?></dd>
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
        <div class="border rounded p-3 mb-4" id="catalogDelegation">
            <h6 class="mb-1"><i class="fas fa-fw fa-plane-departure me-2"></i>Out of office</h6>
            <p class="small text-secondary">While you are away, approvals addressed to you go to the person you pick. Requests you already have pending are handed over too when the approval worker is on.</p>
            <?php foreach ($my_delegations as $dl) { ?>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span><i class="fas fa-fw fa-share me-2"></i><strong><?= nullable_htmlentities($dl['delegate_name'] ?? 'Unknown') ?></strong> covers from <?= nullable_htmlentities($dl['starts_at']) ?> to <?= nullable_htmlentities($dl['ends_at']) ?><?= (string) $dl['note'] !== '' ? ' (' . nullable_htmlentities($dl['note']) . ')' : '' ?></span>
                    <form action="post.php" method="post"><input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>"><input type="hidden" name="delegation_id" value="<?= intval($dl['delegation_id']) ?>"><button class="btn btn-sm btn-outline-danger" type="submit" name="delete_service_catalog_delegation"><i class="fas fa-times me-1"></i>End</button></form>
                </div>
            <?php } ?>
            <form action="post.php" method="post" autocomplete="off" class="row g-2 align-items-end">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <div class="col-md-3"><label class="form-label small mb-0" for="delegateUser">Delegate to</label>
                    <select class="form-select form-select-sm" name="delegate_user_id" id="delegateUser" required>
                        <option value="">- pick an agent -</option>
                        <?php foreach ($delegate_candidates as $d) { ?><option value="<?= intval($d['user_id']) ?>"><?= nullable_htmlentities($d['user_name']) ?></option><?php } ?>
                    </select></div>
                <div class="col-md-2 col-6"><label class="form-label small mb-0" for="delegateFrom">From</label><input type="date" class="form-control form-control-sm" name="delegate_from" id="delegateFrom" value="<?= date('Y-m-d') ?>" required></div>
                <div class="col-md-2 col-6"><label class="form-label small mb-0" for="delegateTo">Until (inclusive)</label><input type="date" class="form-control form-control-sm" name="delegate_to" id="delegateTo" value="<?= date('Y-m-d', time() + 7 * 86400) ?>" required></div>
                <div class="col-md-3"><label class="form-label small mb-0" for="delegateNote">Note</label><input type="text" class="form-control form-control-sm" name="delegate_note" id="delegateNote" maxlength="200" placeholder="Optional"></div>
                <div class="col-md-2"><button class="btn btn-sm btn-primary w-100" type="submit" name="add_service_catalog_delegation"><i class="fas fa-plus me-1"></i>Add</button></div>
            </form>
        </div>

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
