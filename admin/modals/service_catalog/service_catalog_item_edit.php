<?php

require_once '../../../includes/modal_header.php';
require_once __DIR__ . '/../../../includes/icon_picker.php';

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
$catalog_category_id = intval($row['catalog_category_id'] ?? 0);
$reminder_after_hours = intval($row['reminder_after_hours'] ?? 0);
$escalate_after_hours = intval($row['escalate_after_hours'] ?? 0);
$expire_after_hours = intval($row['expire_after_hours'] ?? 0);

require_once $_SERVER['DOCUMENT_ROOT'] . '/src/ITSM/ServiceCatalogService.php';
$catalog_service = new \ITFlow\ITSM\ServiceCatalogService($mysqli);
$catalog_fields = $catalog_service->getFields($catalog_item_id);
$catalog_steps = $catalog_service->getSteps($catalog_item_id);
$catalog_risk_rules = $catalog_service->getRiskRules($catalog_item_id);
$catalog_categories_all = $catalog_service->getCategories(false);
// Two blank rows at the end are the "add another" rows; an empty label / unpicked approver is ignored on save.
for ($i = 0; $i < 3; $i++) { $catalog_fields[] = ['field_key' => '', 'label' => '', 'field_type' => 'text', 'options' => '', 'is_required' => 0, 'placeholder' => '']; }
// A step that shares its stage number with the one before it is a parallel branch (ticked "Parallel").
$prev_stage = null;
foreach ($catalog_steps as $k => $st0) { $catalog_steps[$k]['is_parallel'] = $prev_stage !== null && intval($st0['step_order']) === $prev_stage; $prev_stage = intval($st0['step_order']); }
for ($i = 0; $i < 2; $i++) { $catalog_steps[] = ['approver_type' => 'user', 'approver_id' => 0, 'mode' => 'any', 'risk_min' => 0, 'risk_max' => 100, 'is_parallel' => false]; }
for ($i = 0; $i < 2; $i++) { $catalog_risk_rules[] = ['field_key' => '', 'op' => 'equals', 'value' => '', 'points' => 0]; }

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
                    <label>Icon</label>
                    <?php iconPickerField('icon', (string) $icon, 'fa-ticket-alt'); ?>
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
            <label>Catalog category <small class="text-secondary">(the tile group this item appears under on Request Something)</small></label>
            <select class="form-select" name="catalog_category_id">
                <option value="0">- No category (shown under Other) -</option>
                <?php foreach ($catalog_categories_all as $cc) { ?>
                    <option value="<?= intval($cc['category_id']) ?>" <?php if ($catalog_category_id === intval($cc['category_id'])) { echo 'selected'; } ?>><?= nullable_htmlentities($cc['name']) ?><?= intval($cc['is_active']) ? '' : ' (inactive)' ?></option>
                <?php } ?>
            </select>
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
            <thead><tr><th style="width:70px">Order</th><th>Label</th><th style="width:120px">Type</th><th>Choices <small class="text-secondary">(one per line for Select; for Table one column per line: Heading or Heading|number or Heading|date)</small></th><th class="text-center" style="width:70px">Required</th><th style="width:240px">Show only when <small class="text-secondary">(optional, up to 3 conditions)</small></th><th class="text-center" style="width:70px">Remove</th></tr></thead>
            <tbody>
            <?php
            $field_labels_by_key = [];
            foreach ($catalog_fields as $cf) { if ($cf['field_key'] !== '') { $field_labels_by_key[$cf['field_key']] = $cf['label']; } }
            foreach ($catalog_fields as $i => $f) {
                $f_rule = \ITFlow\ITSM\ServiceCatalogService::parseShowIf($f['show_if'] ?? null);
                $f_rule_value = $f_rule === null ? '' : ($f_rule['op'] === 'in' ? implode('|', $f_rule['value']) : ($f_rule['op'] === 'equals' ? $f_rule['value'] : ''));
            ?>
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
                    <td>
                        <?php
                        $f_conds = \ITFlow\ITSM\ServiceCatalogService::showIfRules($f_rule);
                        $f_match = ($f_rule !== null && isset($f_rule['rules'])) ? ($f_rule['match'] ?? 'all') : 'all';
                        foreach ([1, 2, 3] as $slot) {
                            $c = $f_conds[$slot - 1] ?? null;
                            $c_value = $c === null ? '' : ($c['op'] === 'in' ? implode('|', $c['value']) : ($c['op'] === 'equals' ? $c['value'] : ''));
                            $pfx = $slot === 1 ? 'field_showif' : 'field_showif' . $slot;
                            if ($slot === 2) { echo '<details class="mt-1"' . (count($f_conds) > 1 ? ' open' : '') . '><summary class="small text-secondary">More conditions</summary>'; ?>
                                <select class="form-select form-select-sm mt-1" name="field_showif_match[<?= $i ?>]">
                                    <option value="all" <?php if ($f_match === 'all') { echo 'selected'; } ?>>All conditions must hold</option>
                                    <option value="any" <?php if ($f_match === 'any') { echo 'selected'; } ?>>Any one condition is enough</option>
                                </select>
                            <?php } ?>
                            <div class="<?= $slot > 1 ? 'border-top pt-1 mt-1' : '' ?>">
                            <select class="form-select form-select-sm" name="<?= $pfx ?>_field[<?= $i ?>]" aria-label="Condition <?= $slot ?> question">
                                <option value=""><?= $slot === 1 ? 'Always shown' : '- no condition -' ?></option>
                                <?php for ($j = 0; $j < $i; $j++) { if ($catalog_fields[$j]['field_key'] === '') { continue; } ?>
                                    <option value="<?= nullable_htmlentities($catalog_fields[$j]['field_key']) ?>" <?php if ($c !== null && $c['field'] === $catalog_fields[$j]['field_key']) { echo 'selected'; } ?>>When: <?= nullable_htmlentities($catalog_fields[$j]['label']) ?></option>
                                <?php } ?>
                            </select>
                            <select class="form-select form-select-sm mt-1" name="<?= $pfx ?>_op[<?= $i ?>]" aria-label="Condition <?= $slot ?> test">
                                <option value="equals" <?php if ($c === null || $c['op'] === 'equals') { echo 'selected'; } ?>>equals</option>
                                <option value="in" <?php if ($c !== null && $c['op'] === 'in') { echo 'selected'; } ?>>is one of (a|b)</option>
                                <option value="not_empty" <?php if ($c !== null && $c['op'] === 'not_empty') { echo 'selected'; } ?>>is answered</option>
                            </select>
                            <input type="text" class="form-control form-control-sm mt-1" name="<?= $pfx ?>_value[<?= $i ?>]" maxlength="400" value="<?= nullable_htmlentities($c_value) ?>" placeholder="Value" aria-label="Condition <?= $slot ?> value">
                            </div>
                        <?php }
                        echo '</details>';
                        if ($f_rule !== null) { ?><div class="small text-secondary mt-1"><?= nullable_htmlentities(\ITFlow\ITSM\ServiceCatalogService::describeShowIf($f_rule, $field_labels_by_key)) ?></div><?php } ?>
                    </td>
                    <td class="text-center"><?php if ($f['label'] !== '') { ?><input type="checkbox" class="form-check-input" name="field_remove[<?= $i ?>]" value="1"><?php } ?></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
        </div>
        <p class="small text-secondary">A <strong>Table</strong> question lets the requester add rows (for example several devices): list its columns one per line in Choices. A condition can only look at a question that comes earlier in the form (and that has been saved). A question whose condition is not met is hidden and is neither required nor stored. A required question that has a condition is required only while it is shown.</p>
        <?php $preview_fields = array_values(array_filter($catalog_fields, fn($pf) => $pf['label'] !== '')); if ($preview_fields) { ?>
            <details class="mb-3">
                <summary class="small">Preview the saved form (try the conditions)</summary>
                <div class="border rounded p-3 mt-2">
                    <?= \ITFlow\ITSM\ServiceCatalogService::renderInputs($preview_fields, true) ?>
                </div>
            </details>
        <?php } ?>

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
        <h6 class="mt-3">Risk rules <small class="text-secondary">(optional: the answers raise or lower the risk score of each request)</small></h6>
        <div class="table-responsive-sm">
        <table class="table table-sm align-middle">
            <thead><tr><th>Question</th><th style="width:150px">When it</th><th>Value</th><th style="width:90px">Points</th><th class="text-center" style="width:70px">Remove</th></tr></thead>
            <tbody>
            <?php foreach ($catalog_risk_rules as $i => $rr) { ?>
                <tr>
                    <td>
                        <select class="form-select form-select-sm" name="risk_field[<?= $i ?>]" aria-label="Risk rule question">
                            <option value="">- none -</option>
                            <?php foreach ($catalog_fields as $cf) { if ($cf['field_key'] === '' || $cf['label'] === '') { continue; } ?>
                                <option value="<?= nullable_htmlentities($cf['field_key']) ?>" <?php if ($rr['field_key'] === $cf['field_key']) { echo 'selected'; } ?>><?= nullable_htmlentities($cf['label']) ?></option>
                            <?php } ?>
                        </select>
                    </td>
                    <td>
                        <select class="form-select form-select-sm" name="risk_op[<?= $i ?>]" aria-label="Risk rule test">
                            <?php foreach (['equals' => 'equals', 'in' => 'is one of (a|b)', 'not_empty' => 'is answered', 'gte' => 'is at least (number)', 'lte' => 'is at most (number)'] as $ov => $ol) { ?>
                                <option value="<?= $ov ?>" <?php if ($rr['op'] === $ov) { echo 'selected'; } ?>><?= $ol ?></option>
                            <?php } ?>
                        </select>
                    </td>
                    <td><input type="text" class="form-control form-control-sm" name="risk_value[<?= $i ?>]" maxlength="400" value="<?= nullable_htmlentities($rr['value'] ?? '') ?>" aria-label="Risk rule value"></td>
                    <td><input type="number" class="form-control form-control-sm" name="risk_points[<?= $i ?>]" min="-100" max="100" value="<?= intval($rr['points']) ?>" aria-label="Risk rule points"></td>
                    <td class="text-center"><?php if ($rr['field_key'] !== '') { ?><input type="checkbox" class="form-check-input" name="risk_remove[<?= $i ?>]" value="1"><?php } ?></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
        </div>
        <p class="small text-secondary">The request's risk score is the score above plus the points of every rule that matches (never below 0 or above 100). It decides the auto-approve test and which steps below apply. Rules look at saved questions.</p>

        <h6 class="mt-3">Approval steps</h6>
        <div class="table-responsive-sm">
        <table class="table table-sm align-middle">
            <thead><tr><th style="width:70px">Step</th><th style="width:80px" title="Decides at the same time as the step above it">Parallel</th><th style="width:170px">Approver</th><th>Who</th><th style="width:150px">Mode</th><th style="width:130px" title="This step only applies to requests whose risk score is inside the band">Risk band</th><th class="text-center" style="width:70px">Remove</th></tr></thead>
            <tbody>
            <?php foreach ($catalog_steps as $i => $st) { $sid = intval($st['approver_id'] ?? 0); ?>
                <tr>
                    <td><input type="number" class="form-control form-control-sm" name="step_order[<?= $i ?>]" value="<?= $i + 1 ?>"></td>
                    <td class="text-center"><input type="checkbox" class="form-check-input" name="step_parallel[<?= $i ?>]" value="1" aria-label="Parallel with the step above" <?php if (!empty($st['is_parallel'])) { echo 'checked'; } ?>></td>
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
                    <td>
                        <div class="input-group input-group-sm">
                            <input type="number" class="form-control" name="step_risk_min[<?= $i ?>]" min="0" max="100" value="<?= intval($st['risk_min'] ?? 0) ?>" aria-label="Risk from">
                            <span class="input-group-text">to</span>
                            <input type="number" class="form-control" name="step_risk_max[<?= $i ?>]" min="0" max="100" value="<?= intval($st['risk_max'] ?? 100) ?>" aria-label="Risk to">
                        </div>
                    </td>
                    <td class="text-center"><?php if (!empty($st['approver_type']) && ($sid || $st['approver_type'] === 'requester_manager') && $i < count($catalog_steps) - 2) { ?><input type="checkbox" class="form-check-input" name="step_remove[<?= $i ?>]" value="1"><?php } ?></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
        </div>
        <p class="small text-secondary">Steps run in order. Tick <strong>Parallel</strong> to make a step decide at the same time as the step above it (the next step starts when every parallel step is satisfied). The <strong>risk band</strong> routes by risk: a step only applies to requests whose risk score is inside its band (0 to 100 = always), so a high-risk request can need an extra approver. A risk level with no step applying is approved without asking anyone. The requester's manager comes from the contact's Manager field; with no manager on file the step falls back to the administrators. Requests under the auto-approve threshold skip approval.</p>

        <h6 class="mt-3"><i class="fas fa-fw fa-bell me-2"></i>Reminders and timeouts <small class="text-secondary">(0 = off; only runs when the approval worker is switched on in Service Catalog)</small></h6>
        <div class="row">
            <div class="col-md-4"><div class="form-group"><label>Remind every <small class="text-secondary">(hours)</small></label><input type="number" class="form-control" name="reminder_after_hours" min="0" max="2160" value="<?= $reminder_after_hours ?>"></div></div>
            <div class="col-md-4"><div class="form-group"><label>Escalate to administrators after <small class="text-secondary">(hours)</small></label><input type="number" class="form-control" name="escalate_after_hours" min="0" max="2160" value="<?= $escalate_after_hours ?>"></div></div>
            <div class="col-md-4"><div class="form-group"><label>Reject if undecided after <small class="text-secondary">(hours)</small></label><input type="number" class="form-control" name="expire_after_hours" min="0" max="2160" value="<?= $expire_after_hours ?>"></div></div>
        </div>
        <p class="small text-secondary mb-0">The clock starts when a stage is asked. Reminders repeat (at most 5). Escalation adds the active administrators, any of whom can then decide the stage. A request nobody decides in time is rejected and its ticket closed.</p>

    </div>
    <div class="modal-footer">
        <button type="submit" name="edit_service_catalog_item" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Save changes</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php
require_once '../../../includes/modal_footer.php';
