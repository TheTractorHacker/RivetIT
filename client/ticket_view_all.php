<?php
/*
 * Client Portal
 * Primary contact view: all tickets
 */

// inc_all.php streams the page chrome (header.php) before control returns
// here, so the redirect below needs output buffered - otherwise header()
// silently fails ("headers already sent") and the page just dies with no
// content and no redirect.
ob_start();
require_once 'includes/inc_all.php';


if ($session_contact_primary == 0 && !$session_contact_is_technical_contact) {
    ob_end_clean();
    // a contact without this role used to be signed out of the whole portal; send them home with a reason instead
    flash_alert(portal_no_access_message(__FILE__), 'warning');
    header("Location: index.php");
    exit();
}

// Ticket status from GET
if (!isset($_GET['status']) || ($_GET['status']) == 'Open') {
    // Default to showing open
    $status = 'Open';
    $ticket_status_snippet = "ticket_closed_at IS NULL";
} elseif (isset($_GET['status']) && ($_GET['status']) == 'Closed') {
    $status = 'Closed';
    $ticket_status_snippet = "ticket_closed_at IS NOT NULL";
} else {
    $status = '%';
    $ticket_status_snippet = "ticket_status LIKE '%'";
}

$all_tickets = mysqli_query($mysqli, "SELECT ticket_id, ticket_prefix, ticket_number, ticket_subject, ticket_status_name, contact_name, COALESCE(ticket_updated_at, ticket_created_at) AS last_update FROM tickets LEFT JOIN contacts ON ticket_contact_id = contact_id LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id WHERE $ticket_status_snippet AND ticket_client_id = $session_client_id ORDER BY ticket_id DESC");

$va_count = static function (string $where) use ($mysqli, $session_client_id): int {
    $r = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS n FROM tickets WHERE ticket_client_id = " . intval($session_client_id) . $where));
    return intval($r['n']);
};
$va_open = $va_count(' AND ticket_closed_at IS NULL');
$va_closed = $va_count(' AND ticket_closed_at IS NOT NULL');
$va_all = $va_open + $va_closed;
?>

<div class="row mb-4">
    <div class="col">
        <h3><i class="fas fa-fw fa-users me-2"></i>Department tickets</h3>
    </div>
    <div class="col-auto">
        <div class="btn-group" role="group" aria-label="Which tickets">
            <a href="tickets.php" class="btn btn-sm btn-outline-primary">Mine</a>
            <a href="ticket_view_all.php" class="btn btn-sm btn-primary" aria-current="page">Department</a>
        </div>
    </div>
</div>

<div class="portal-tabs">
    <a href="?status=Open" class="portal-tab <?php echo $status == 'Open' ? 'is-active' : ''; ?>">Open <span class="portal-tab-n"><?php echo $va_open; ?></span></a>
    <a href="?status=Closed" class="portal-tab <?php echo $status == 'Closed' ? 'is-active' : ''; ?>">Closed <span class="portal-tab-n"><?php echo $va_closed; ?></span></a>
    <a href="?status=%25" class="portal-tab <?php echo $status == '%' ? 'is-active' : ''; ?>">All <span class="portal-tab-n"><?php echo $va_all; ?></span></a>
    <div class="portal-search">
        <i class="fas fa-search" aria-hidden="true"></i>
        <input type="search" class="form-control" id="ticketFilter" placeholder="Filter by subject, number or person" aria-label="Filter tickets" autocomplete="off">
    </div>
</div>

<div class="card card-outline card-primary">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h3 class="card-title"><?php echo $status == 'Open' ? 'Open department tickets' : ($status == 'Closed' ? 'Closed department tickets' : 'All department tickets'); ?></h3>
        <a href="ticket_add.php" class="btn btn-sm btn-primary"><i class="fas fa-fw fa-plus me-1"></i>New ticket</a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" id="ticketTable">
                <thead class="thead-light">
                <tr>
                    <th scope="col">#</th>
                    <th scope="col">Subject</th>
                    <th scope="col">Raised by</th>
                    <th scope="col">Status</th>
                    <th scope="col">Last update</th>
                </tr>
                </thead>
                <tbody>
                <?php if (mysqli_num_rows($all_tickets) == 0) { ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">No tickets found.</td>
                    </tr>
                <?php }
                while ($row = mysqli_fetch_assoc($all_tickets)) {
                    $ticket_id = intval($row['ticket_id']);
                    $ticket_prefix = nullable_htmlentities($row['ticket_prefix']);
                    $ticket_number = intval($row['ticket_number']);
                    $ticket_subject = nullable_htmlentities($row['ticket_subject']);
                    $ticket_status = nullable_htmlentities($row['ticket_status_name']);
                    $ticket_contact_name = nullable_htmlentities($row['contact_name']);
                    $ticket_last = $row['last_update'] ? nullable_htmlentities(timeAgo($row['last_update'])) : '-';
                    ?>
                    <tr>
                        <td class="text-nowrap"><a href="ticket.php?id=<?php echo $ticket_id; ?>">#<?php echo "$ticket_prefix$ticket_number"; ?></a></td>
                        <td><a href="ticket.php?id=<?php echo $ticket_id; ?>"><?php echo $ticket_subject; ?></a></td>
                        <td><?php echo $ticket_contact_name !== '' ? $ticket_contact_name : '<span class="text-secondary">-</span>'; ?></td>
                        <td><span class="badge <?php echo portal_ticket_status_badge((string) $row['ticket_status_name']); ?>"><?php echo $ticket_status; ?></span></td>
                        <td class="text-secondary text-nowrap"><?php echo $ticket_last; ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
        <div class="text-center text-muted py-4" id="ticketNoMatch" hidden>No tickets match that filter.</div>
    </div>
</div>

<?php
require_once 'includes/footer.php';
