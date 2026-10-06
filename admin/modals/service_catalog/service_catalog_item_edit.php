<?php

require_once '../../../includes/modal_header.php';

$catalog_item_id = intval($_GET['id']);

$sql = mysqli_query($mysqli, "SELECT * FROM service_catalog_items WHERE catalog_item_id = $catalog_item_id LIMIT 1");
$row = mysqli_fetch_assoc($sql);

if (!$row) {
    http_response_code(404);
    echo json_encode(['error' => 'Catalog item not found.']);
    exit;
}

$name = nullable_htmlentities($row['name']);
$description = nullable_htmlentities($row['description']);
$icon = nullable_htmlentities($row['icon']);
$ticket_subject_template = nullable_htmlentities($row['ticket_subject_template']);
$ticket_category_id = intval($row['ticket_category_id']);
$default_priority = $row['default_priority'];
$is_active = intval($row['is_active']);
$sort_order = intval($row['sort_order']);
$requires_approval = intval($row['requires_approval']);
$risk_score = intval($row['risk_score']);
$auto_approve_below = intval($row['auto_approve_below']);

require_once $_SERVER['DOCUMENT_ROOT'] . '/src/ITSM/ServiceCatalogService.php';
$catalog_service = new \ITFlow\ITSM\ServiceCatalogService($mysqli);
$catalog_fields = $catalog_service->getFields($catalog_item_id);
$catalog_steps = $catalog_service->getSteps($catalog_item_id);
// Two blank rows at the end are the "add another" rows; an empty label / unpicked approver is ignored on save.
for ($i = 0; $i < 3; $i++) { $catalog_fields[] = ['field_key' => '', 'label' => '', 'field_type' => 'text', 'options' => '', 'is_required' => 0, 'placeholder' => '']; }
for ($i = 0; $i < 2; $i++) { $catalog_steps[] = ['approver_type' => 'user', 'approver_id' => 0, 'mode' => 'any']; }

$catalog_users = [];
$sql_u = mysqli_query($mysqli, "SELECT user_id, user_name FROM users WHERE user_type = 1 AND user_status = 1 AND user_archived_at IS NULL ORDER BY user_name ASC");
while ($u = mysqli_fetch_assoc($sql_u)) { $catalog_users[] = $u; }
$catalog_roles = [];
$sql_r = mysqli_query($mysqli, "SELECT role_id, role_name FROM user_roles WHERE role_archived_at IS NULL AND role_type = 1 ORDER BY role_name ASC");
while ($r = mysqli_fetch_assoc($sql_r)) { $catalog_roles[] = $r; }

ob_start();

?>
<div class="modal-header bg-dark">
    <h5 class="modal-title text-white"><i class="fas fa-fw fa-th-large me-2"></i>Edit Catalog Item: <strong><?php echo $name; ?></strong></h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="catalog_item_id" value="<?php echo $catalog_item_id; ?>">

    <div class="modal-body">

        <div class="form-group">
            <label>Name <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-tag"></i></span>
                </div>
                <input type="text" class="form-control" name="name" maxlength="200" value="<?php echo $name; ?>" required autofocus>
            </div>
        </div>

        <div class="form-group">
            <label>Description <small class="text-secondary">(shown on the Request Something tile)</small></label>
            <textarea class="form-control" name="description" rows="2" maxlength="500"><?php echo $description; ?></textarea>
        </div>

        <div class="row">
            <div class="col">
                <div class="form-group">
                    <label>Icon <small class="text-secondary">(Font Awesome class, e.g. fa-laptop)</small></label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-icons"></i></span>
                        </div>
                        <input type="text" class="form-control" name="icon" placeholder="fa-laptop" maxlength="100" value="<?php echo $icon; ?>">
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="form-group">
                    <label>Sort Order</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-sort-numeric-down"></i></span>
                        </div>
                        <input type="number" class="form-control" name="sort_order" value="<?php echo $sort_order; ?>" step="1">
                    </div>
                </div>
            </div>
        </div>

        <div class="form-group">
            <label>Ticket Subject <strong class="text-danger">*</strong> <small class="text-secondary">(used as-is - no merge fields)</small></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-life-ring"></i></span>
                </div>
                <input type="text" class="form-control" name="ticket_subject_template" maxlength="500" value="<?php echo $ticket_subject_template; ?>" required>
            </div>
        </div>

        <div class="row">
            <div class="col">
                <div class="form-group">
                    <label>Ticket Category</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-layer-group"></i></span>
                        </div>
                        <select class="form-control select2" name="ticket_category_id">
                            <option value="0">- Not Categorized -</option>
                            <?php echo ticketCategoryOptions($mysqli, $ticket_category_id); ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="form-group">
                    <label>Default Priority</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-thermometer-half"></i></span>
                        </div>
                        <select class="form-control select2" name="default_priority">
                            <option value="" <?php if (!$default_priority) { echo "selected"; } ?>>- Not set -</option>
                            <?php foreach (['Low', 'Medium', 'High'] as $p) { ?>
                                <option <?php if ($default_priority === $p) { echo "selected"; } ?>><?php echo $p; ?></option>
                            <?php } ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <div class="form-group">
            <div class="form-check form-switch">
                <input type="checkbox" class="form-check-input" name="is_active" value="1" id="isActiveEdit" <?php if ($is_active) { echo "checked"; } ?>>
                <label class="form-check-label" for="isActiveEdit">Active (visible on Request Something)</label>
            </div>
        </div>

        <hr>
        <h6><i class="fas fa-fw fa-clipboard-list me-2"></i>Request form <small class="text-secondary">(extra questions asked when this item is requested)</small></h6>
        <div class="table-responsive-sm">
        <table class="table table-sm align-middle">
            <thead><tr><th style="width:70px">Order</th><th>Label</th><th style="width:120px">Type</th><th>Choices <small class="text-secondary">(one per line, for Select)</small></th><th class="text-center" style="width:70px">Required</th><th class="text-center" style="width:70px">Remove</th></tr></thead>
            <tbody>
            <?php foreach ($catalog_fields as $i => $f) { ?>
                <tr>
                    <td>
                        <input type="number" class="form-control form-control-sm" name="field_order[<?= $i ?>]" value="<?= $i + 1 ?>">
                        <input type="hidden" name="field_key[<?= $i ?>]" value="<?= nullable_htmlentities($f['field_key']) ?>">
                    </td>
                    <td>
                        <input type="text" class="form-control form-control-sm" name="field_label[<?= $i ?>]" maxlength="200" value="<?= nullable_htmlentities($f['label']) ?>" placeholder="<?= $f['label'] === '' ? 'New question' : '' ?>">
                        <input type="text" class="form-control form-control-sm mt-1" name="field_placeholder[<?= $i ?>]" maxlength="200" value="<?= nullable_htmlentities($f['placeholder'] ?? '') ?>" placeholder="Placeholder text (optional)">
                    </td>
                    <td>
                        <select class="form-select form-select-sm" name="field_type[<?= $i ?>]">
                            <?php foreach (\ITFlow\ITSM\ServiceCatalogService::FIELD_TYPES as $ft) { ?>
                                <option value="<?= $ft ?>" <?php if ($f['field_type'] === $ft) { echo 'selected'; } ?>><?= ucfirst($ft) ?></option>
                            <?php } ?>
                        </select>
                    </td>
                    <td><textarea class="form-control form-control-sm" name="field_options[<?= $i ?>]" rows="2"><?= nullable_htmlentities($f['options'] ?? '') ?></textarea></td>
                    <td class="text-center"><input type="checkbox" class="form-check-input" name="field_required[<?= $i ?>]" value="1" <?php if (!empty($f['is_required'])) { echo 'checked'; } ?>></td>
                    <td class="text-center"><?php if ($f['label'] !== '') { ?><input type="checkbox" class="form-check-input" name="field_remove[<?= $i ?>]" value="1"><?php } ?></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
        </div>

        <hr>
        <h6><i class="fas fa-fw fa-user-check me-2"></i>Approval <small class="text-secondary">(off unless switched on; the ticket is held until every step approves)</small></h6>
        <div class="form-group">
            <div class="form-check form-switch">
                <input type="checkbox" class="form-check-input" name="requires_approval" value="1" id="requiresApprovalEdit" <?php if ($requires_approval) { echo "checked"; } ?>>
                <label class="form-check-label" for="requiresApprovalEdit">Requires approval</label>
            </div>
        </div>
        <div class="row">
            <div class="col">
                <div class="form-group">
                    <label>Risk score <small class="text-secondary">(0-100)</small></label>
                    <input type="number" class="form-control" name="risk_score" min="0" max="100" value="<?= $risk_score ?>">
                </div>
            </div>
            <div class="col">
                <div class="form-group">
                    <label>Auto-approve below <small class="text-secondary">(0 = always ask)</small></label>
                    <input type="number" class="form-control" name="auto_approve_below" min="0" max="101" value="<?= $auto_approve_below ?>">
                </div>
            </div>
        </div>
        <table class="table table-sm align-middle">
            <thead><tr><th style="width:70px">Step</th><th style="width:170px">Approver</th><th>Who</th><th style="width:150px">Mode</th><th class="text-center" style="width:70px">Remove</th></tr></thead>
            <tbody>
            <?php foreach ($catalog_steps as $i => $st) { $sid = intval($st['approver_id'] ?? 0); ?>
                <tr>
                    <td><input type="number" class="form-control form-control-sm" name="step_order[<?= $i ?>]" value="<?= $i + 1 ?>"></td>
                    <td>
                        <select class="form-select form-select-sm" name="step_type[<?= $i ?>]">
                            <option value="user" <?php if ($st['approver_type'] === 'user') { echo 'selected'; } ?>>A person</option>
                            <option value="role" <?php if ($st['approver_type'] === 'role') { echo 'selected'; } ?>>A role</option>
                            <option value="requester_manager" <?php if ($st['approver_type'] === 'requester_manager') { echo 'selected'; } ?>>Requester's manager</option>
                        </select>
                    </td>
                    <td>
                        <select class="form-select form-select-sm mb-1" name="step_ref_user[<?= $i ?>]">
                            <option value="0">- person (if "A person") -</option>
                            <?php foreach ($catalog_users as $u) { ?><option value="<?= intval($u['user_id']) ?>" <?php if ($st['approver_type'] === 'user' && $sid === intval($u['user_id'])) { echo 'selected'; } ?>><?= nullable_htmlentities($u['user_name']) ?></option><?php } ?>
                        </select>
                        <select class="form-select form-select-sm" name="step_ref_role[<?= $i ?>]">
                            <option value="0">- role (if "A role") -</option>
                            <?php foreach ($catalog_roles as $r) { ?><option value="<?= intval($r['role_id']) ?>" <?php if ($st['approver_type'] === 'role' && $sid === intval($r['role_id'])) { echo 'selected'; } ?>><?= nullable_htmlentities($r['role_name']) ?></option><?php } ?>
                        </select>
                    </td>
                    <td>
                        <select class="form-select form-select-sm" name="step_mode[<?= $i ?>]">
                            <option value="any" <?php if ($st['mode'] === 'any') { echo 'selected'; } ?>>Any one approves</option>
                            <option value="all" <?php if ($st['mode'] === 'all') { echo 'selected'; } ?>>Everyone approves</option>
                        </select>
                    </td>
                    <td class="text-center"><?php if (!empty($st['approver_type']) && ($sid || $st['approver_type'] === 'requester_manager') && $i < count($catalog_steps) - 2) { ?><input type="checkbox" class="form-check-input" name="step_remove[<?= $i ?>]" value="1"><?php } ?></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
        <p class="small text-secondary mb-0">Steps run in order. The requester's manager comes from the contact's Manager field; with no manager on file the step falls back to the administrators. Requests under the auto-approve threshold skip approval.</p>

    </div>
    <div class="modal-footer">
        <button type="submit" name="edit_service_catalog_item" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Save changes</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php
require_once '../../../includes/modal_footer.php';
