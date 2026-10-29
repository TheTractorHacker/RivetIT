<?php

require_once '../../../includes/modal_header.php';

$category_id = intval($_GET['id']);

$sql = mysqli_query($mysqli, "SELECT * FROM categories WHERE category_id = $category_id LIMIT 1");

$row = mysqli_fetch_assoc($sql);
$category_name = nullable_htmlentities($row['category_name']);
$category_description = nullable_htmlentities($row['category_description']);
$category_color = nullable_htmlentities($row['category_color']);
$category_type = nullable_htmlentities($row['category_type']);
$category_parent = intval($row['category_parent']);
$category_sla_exempt = intval($row['category_sla_exempt'] ?? 0);
$category_non_billable = intval($row['category_non_billable'] ?? 0);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/ticket_category_rules.php';
// "Apply to existing open tickets": only offered once the category has at least one flag saved ON, and only for an
// already-saved Ticket category (an admin-only bulk sweep; see includes/ticket_category_rules.php). The count is
// informational only; the real action re-queries at submit time.
$apply_counts = ($category_type === 'Ticket' && ($category_sla_exempt || $category_non_billable))
    ? ticketCategoryApplyToExistingOpenTickets($mysqli, $category_id, true)
    : ['sla_count' => 0, 'billable_count' => 0, 'total_count' => 0];
$apply_affected_count = $apply_counts['total_count'];

// Generate the HTML form content using output buffering.
ob_start();
?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fa fa-fw fa-list-ul me-2"></i>Editing category: <strong><?php echo $category_name; ?></strong></h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="category_id" value="<?php echo $category_id; ?>">
    <input type="hidden" name="type" value="<?php echo $category_type; ?>">
    <div class="modal-body">

        <?php if ($category_type === 'Ticket') { ?>
        <div class="form-group">
            <label>Group <small class="text-muted">(leave blank = this is a group itself)</small></label>
            <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-fw fa-layer-group"></i></span></div>
                <select class="form-control select2" name="category_parent">
                    <option value="0">— New Group / Ungrouped —</option>
                    <?php
                    $sql_groups = mysqli_query($mysqli, "SELECT category_id, category_name FROM categories WHERE category_type = 'Ticket' AND category_parent = 0 AND category_archived_at IS NULL AND category_id != $category_id ORDER BY category_name");
                    while ($g = mysqli_fetch_assoc($sql_groups)) echo "<option value=\"{$g['category_id']}\"" . ($category_parent == $g['category_id'] ? ' selected' : '') . ">" . nullable_htmlentities($g['category_name']) . "</option>";
                    ?>
                </select>
            </div>
        </div>
        <?php } else { ?>
        <input type="hidden" name="category_parent" value="0">
        <?php } ?>

        <?php if ($category_type === 'Ticket') { ?>
        <div class="form-group">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="category_sla_exempt" value="1" id="categorySlaExempt" <?= $category_sla_exempt ? 'checked' : '' ?>>
                <label class="form-check-label" for="categorySlaExempt">SLA exempt <small class="text-muted">(tickets in this category get no SLA policy, due dates or breaches)</small></label>
            </div>
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="category_non_billable" value="1" id="categoryNonBillable" <?= $category_non_billable ? 'checked' : '' ?>>
                <label class="form-check-label" for="categoryNonBillable">Non-billable <small class="text-muted">(tickets in this category default to not billable)</small></label>
            </div>
            <small class="text-muted">Changing these only affects new tickets and tickets later moved into/out of this category - existing tickets keep their current SLA/billable values. Save first, then use "Apply to existing open tickets" below to retrofit them.</small>
        </div>
        <?php } ?>

        <div class="form-group">
            <label>Name <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-list-ul"></i></span>
                </div>
                <input type="text" class="form-control" name="name" maxlength="200" value="<?php echo $category_name; ?>" required>
            </div>
        </div>

        <div class="form-group">
            <label>Color <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-paint-brush"></i></span>
                </div>
                <input type="color" class="form-control col-3" name="color" value="<?php echo $category_color; ?>" required>
            </div>
        </div>

        <div class="form-group">
            <label>Description</label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fas fa-fw fa-align-left"></i></span>
                </div>
                <input type="text" class="form-control" name="description" placeholder="Enter a description" maxlength="200" value="<?= $category_description ?>">
            </div>
        </div>

    </div>
    <div class="modal-footer flex-wrap">
        <?php if ($apply_affected_count > 0) { ?>
        <button type="submit" name="apply_category_ticket_rules" class="btn btn-outline-warning js-confirm-native" formnovalidate
            data-confirm-message="Apply this category's current SLA-exempt / Non-billable settings to the <?= $apply_affected_count ?> currently open ticket(s) already in <?= $category_name ?>? This changes those tickets right away and cannot be undone automatically."
            data-confirm-variant="warning">
            <i class="fas fa-fw fa-bolt me-2"></i>Apply to <?= $apply_affected_count ?> existing open ticket<?= $apply_affected_count === 1 ? '' : 's' ?>
        </button>
        <?php } elseif ($category_type === 'Ticket' && ($category_sla_exempt || $category_non_billable)) { ?>
        <small class="text-muted me-auto align-self-center">No open tickets in this category currently need these rules applied.</small>
        <?php } ?>
        <button type="submit" name="edit_category" class="btn btn-primary text-bold"><i class="fa fa-check me-2"></i>Save</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php
require_once '../../../includes/modal_footer.php';
