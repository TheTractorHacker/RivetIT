<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['add_ticket_status'])) {

    validateCSRFToken($_POST['csrf_token']);

    $name = sanitizeInput($_POST['name']);
    $color = sanitizeInput($_POST['color']);
    // "Pauses the SLA clock": for statuses where the ticket is waiting on someone else (customer, employee, vendor...).
    $pauses_sla_sql = '';
    require_once dirname(__DIR__, 2) . '/includes/sla_functions.php';
    if (slaStatusColumnExists($mysqli)) {
        $pauses_sla_sql = ', ticket_status_pauses_sla = ' . (isset($_POST['pauses_sla']) ? 1 : 0);
    }

    mysqli_query($mysqli, "INSERT INTO ticket_statuses SET ticket_status_name = '$name', ticket_status_color = '$color'$pauses_sla_sql");

    $ticket_status_id = mysqli_insert_id($mysqli);

    logAction("Ticket Status", "Create", "$session_name created custom ticket status $name", 0, $ticket_status_id);

    flash_alert("Custom Ticket Status <strong>$name</strong> created");

    redirect();

}

if (isset($_POST['edit_ticket_status'])) {

    validateCSRFToken($_POST['csrf_token']);

    $ticket_status_id = intval($_POST['ticket_status_id']);
    $name = sanitizeInput($_POST['name']);
    $color = sanitizeInput($_POST['color']);
    $order = intval($_POST['order']);
    $status = intval($_POST['status']);
    $pauses_sla_sql = '';
    require_once dirname(__DIR__, 2) . '/includes/sla_functions.php';
    if (slaStatusColumnExists($mysqli)) {
        $pauses_sla_sql = ', ticket_status_pauses_sla = ' . (isset($_POST['pauses_sla']) ? 1 : 0);
    }

    mysqli_query($mysqli, "UPDATE ticket_statuses SET ticket_status_name = '$name', ticket_status_color = '$color', ticket_status_order = $order, ticket_status_active = $status$pauses_sla_sql WHERE ticket_status_id = $ticket_status_id");

    // Open tickets already sitting in this status start or stop their SLA pause to match the new setting.
    if ($pauses_sla_sql !== '') {
        slaReconcilePauses($mysqli);
    }

    logAction("Ticket Status", "Edit", "$session_name edited custom ticket status $name", 0, $ticket_status_id);

    flash_alert("Custom Ticket Status <strong>$name</strong> edited");

    redirect();

}

if (isset($_GET['delete_ticket_status'])) {

    validateCSRFToken($_GET['csrf_token']);

    $ticket_status_id = intval($_GET['delete_ticket_status']);

    if ($ticket_status_id <= 5) {
        exit("Can't delete built-in statuses");
    }

    $ticket_status_name = sanitizeInput(getFieldById('ticket_statuses', $ticket_status_id, 'ticket_status_name'));

    mysqli_query($mysqli, "DELETE FROM ticket_statuses WHERE ticket_status_id = $ticket_status_id");

    logAction("Ticket Status", "Delete", "$session_name deleted custom ticket status $ticket_status_name");

    flash_alert("Custom Ticket Status <strong>$ticket_status_name</strong> Deleted", 'error');

    redirect();

}
