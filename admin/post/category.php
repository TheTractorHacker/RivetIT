<?php

/*
 * RivetIT - GET/POST request handler for categories ('category')
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/ticket_category_rules.php';

if (isset($_POST['add_category'])) {

    validateCSRFToken($_POST['csrf_token']);

    require_once 'category_model.php';

    // Per-category SLA-exempt / Non-billable (DB 2.6.205): Ticket categories only (gated here too, not just in the form, in case of a tampered POST).
    $category_sla_exempt = ($type === 'Ticket' && !empty($_POST['category_sla_exempt'])) ? 1 : 0;
    $category_non_billable = ($type === 'Ticket' && !empty($_POST['category_non_billable'])) ? 1 : 0;
    $category_flag_sql = '';
    $category_flag_summary = '';
    if (ticketCategoryRuleColumnsExist($mysqli)) {
        $category_flag_sql = ", category_sla_exempt = $category_sla_exempt, category_non_billable = $category_non_billable";
        $category_flag_summary = ($category_sla_exempt || $category_non_billable)
            ? ' (' . implode(', ', array_filter([$category_sla_exempt ? 'SLA exempt' : '', $category_non_billable ? 'Non-billable' : ''])) . ')' : '';
    }

    mysqli_query($mysqli,"INSERT INTO categories SET category_name = '$name', category_description = '$description', category_type = '$type', category_color = '$color', category_parent = $parent$category_flag_sql");

    $category_id = mysqli_insert_id($mysqli);

    logAction("Category", "Create", "$session_name created category $type $name" . $category_flag_summary, 0, $category_id);

    flash_alert("Category $type <strong>$name</strong> created");

    redirect();

}

if (isset($_POST['edit_category'])) {

    validateCSRFToken($_POST['csrf_token']);

    require_once 'category_model.php';

    $category_id = intval($_POST['category_id']);

    // Per-category SLA-exempt / Non-billable (DB 2.6.205): Ticket categories only (gated here too, not just in the form,
    // in case of a tampered POST). Audit-log what actually changed.
    $category_sla_exempt = ($type === 'Ticket' && !empty($_POST['category_sla_exempt'])) ? 1 : 0;
    $category_non_billable = ($type === 'Ticket' && !empty($_POST['category_non_billable'])) ? 1 : 0;
    $category_flag_sql = '';
    $flag_change_summary = '';
    if (ticketCategoryRuleColumnsExist($mysqli)) {
        $old_flags = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT category_sla_exempt, category_non_billable FROM categories WHERE category_id = $category_id LIMIT 1"));
        $flag_changes = [];
        if ((int) ($old_flags['category_sla_exempt'] ?? 0) !== $category_sla_exempt) {
            $flag_changes[] = 'SLA exempt ' . ($category_sla_exempt ? 'enabled' : 'disabled');
        }
        if ((int) ($old_flags['category_non_billable'] ?? 0) !== $category_non_billable) {
            $flag_changes[] = 'Non-billable ' . ($category_non_billable ? 'enabled' : 'disabled');
        }
        $flag_change_summary = $flag_changes ? (' (' . implode(', ', $flag_changes) . ')') : '';
        $category_flag_sql = ", category_sla_exempt = $category_sla_exempt, category_non_billable = $category_non_billable";
    }

    mysqli_query($mysqli,"UPDATE categories SET category_name = '$name', category_description = '$description', category_type = '$type', category_color = '$color', category_parent = $parent$category_flag_sql WHERE category_id = $category_id");

    logAction("Category", "Edit", "$session_name edited category $type $name" . $flag_change_summary, 0, $category_id);

    flash_alert("Category $type <strong>$name</strong> edited");

    redirect();

}

if (isset($_GET['archive_category'])) {

    validateCSRFToken($_GET['csrf_token']);

    $category_id = intval($_GET['archive_category']);

    // Get Category Name and Type for logging
    $sql = mysqli_query($mysqli,"SELECT category_name, category_type FROM categories WHERE category_id = $category_id");
    $row = mysqli_fetch_assoc($sql);
    $category_name = sanitizeInput($row['category_name']);
    $category_type = sanitizeInput($row['category_type']);

    mysqli_query($mysqli,"UPDATE categories SET category_archived_at = NOW() WHERE category_id = $category_id");

    logAction("Category", "Archive", "$session_name archived category $category_type $category_name", 0, $category_id);

    flash_alert("Category $category_type <strong>$category_name</strong> archived", 'error');

    redirect();

}

if (isset($_GET['restore_category'])) {

    validateCSRFToken($_GET['csrf_token']);

    $category_id = intval($_GET['restore_category']);

    // Get Category Name and Type for logging
    $sql = mysqli_query($mysqli,"SELECT category_name, category_type FROM categories WHERE category_id = $category_id");
    $row = mysqli_fetch_assoc($sql);
    $category_name = sanitizeInput($row['category_name']);
    $category_type = sanitizeInput($row['category_type']);

    mysqli_query($mysqli,"UPDATE categories SET category_archived_at = NULL WHERE category_id = $category_id");

    logAction("Category", "Restore", "$session_name retored category $category_type $category_name", 0, $category_id);

    flash_alert("Category $category_type <strong>$category_name</strong> restored");

    redirect();

}

if (isset($_GET['delete_category'])) {

    validateCSRFToken($_GET['csrf_token']);

    $category_id = intval($_GET['delete_category']);

    // Get Category Name and Type for logging
    $sql = mysqli_query($mysqli,"SELECT category_name, category_type FROM categories WHERE category_id = $category_id");
    $row = mysqli_fetch_assoc($sql);
    $category_name = sanitizeInput($row['category_name']);
    $category_type = sanitizeInput($row['category_type']);

    mysqli_query($mysqli,"DELETE FROM categories WHERE category_id = $category_id");

    logAction("Category", "Delete", "$session_name deleted category $category_type $category_name");

    flash_alert("Category $category_type <strong>$category_name</strong> deleted", 'error');

    redirect();

}

if (isset($_POST['apply_category_ticket_rules'])) {

    // Admin-only (the whole admin/ area is gated on $session_is_admin), explicit opt-in retrofit of this category's
    // CURRENT saved SLA-exempt / Non-billable flags onto every still-open ticket already in it (DB 2.6.205). Nothing is
    // back-filled automatically; this is the one deliberate exception, and only for open tickets. Re-queries and acts
    // fresh here rather than trusting the count shown on the form, which may be stale by the time this is clicked.

    validateCSRFToken($_POST['csrf_token']);

    $category_id = intval($_POST['category_id']);

    $sql = mysqli_query($mysqli, "SELECT category_name, category_type FROM categories WHERE category_id = $category_id LIMIT 1");
    $row = mysqli_fetch_assoc($sql);
    $category_name = sanitizeInput($row['category_name'] ?? '');
    $category_type = sanitizeInput($row['category_type'] ?? '');

    if ($row && $category_type === 'Ticket') {
        $result = ticketCategoryApplyToExistingOpenTickets($mysqli, $category_id, false);

        logAction("Category", "Apply rules", "$session_name applied category $category_name's SLA-exempt/Non-billable rules to existing open tickets ({$result['sla_count']} made SLA-exempt, {$result['billable_count']} made non-billable, {$result['total_count']} ticket(s) total)", 0, $category_id);

        if ($result['total_count'] > 0) {
            flash_alert("Applied <strong>$category_name</strong>'s current SLA-exempt/Non-billable settings to <strong>{$result['total_count']}</strong> existing open ticket(s)");
        } else {
            flash_alert("No existing open tickets in <strong>$category_name</strong> needed these rules applied");
        }
    }

    redirect();

}
