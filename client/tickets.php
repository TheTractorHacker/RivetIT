<?php
/*
 * Client Portal
 * Landing / Home page for the client portal
 */

header("Content-Security-Policy: default-src 'self'");

require_once "includes/inc_all.php";


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

$contact_tickets = mysqli_query($mysqli, "SELECT ticket_id, ticket_prefix, ticket_number, ticket_subject, ticket_status_name, COALESCE(ticket_updated_at, ticket_created_at) AS last_update FROM tickets LEFT JOIN contacts ON ticket_contact_id = contact_id LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id WHERE $ticket_status_snippet AND ticket_contact_id = $session_contact_id AND ticket_client_id = $session_client_id ORDER BY ticket_id DESC");

//Get Total tickets closed
$sql_total_tickets_closed = mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS total_tickets_closed FROM tickets WHERE ticket_closed_at IS NOT NULL AND ticket_client_id = $session_client_id AND ticket_contact_id = $session_contact_id");
$row = mysqli_fetch_assoc($sql_total_tickets_closed);
$total_tickets_closed = intval($row['total_tickets_closed']);

//Get Total tickets open
$sql_total_tickets_open = mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS total_tickets_open FROM tickets WHERE ticket_closed_at IS NULL AND ticket_client_id = $session_client_id AND ticket_contact_id = $session_contact_id");
$row = mysqli_fetch_assoc($sql_total_tickets_open);
$total_tickets_open = intval($row['total_tickets_open']);

//Get Total tickets
$sql_total_tickets = mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS total_tickets FROM tickets WHERE ticket_client_id = $session_client_id AND ticket_contact_id = $session_contact_id");
$row = mysqli_fetch_assoc($sql_total_tickets);
$total_tickets = intval($row['total_tickets']);


?>

<?php
// Mine / Department: this page only ever lists tickets the logged-in contact raised ("Mine", the default and the only view for
// employees). Department administrators (primary / technical contacts) can switch to the whole department, which is
// ticket_view_all.php and keeps its own administrator check; employees get no such link, and ticket.php re-checks ownership.
$tickets_is_admin = ($session_contact_primary == 1 || $session_contact_is_technical_contact);
?>
<div class="row mb-4">
    <div class="col">
        <h3><i class="fas fa-fw fa-ticket-alt me-2"></i><?php echo $tickets_is_admin ? 'Tickets' : 'My requests'; ?></h3>
    </div>
    <?php if ($tickets_is_admin) { ?>
    <div class="col-auto">
        <div class="btn-group" role="group" aria-label="Which tickets">
            <a href="tickets.php" class="btn btn-sm btn-primary">Mine</a>
            <a href="ticket_view_all.php" class="btn btn-sm btn-outline-primary">Department</a>
        </div>
    </div>
    <?php } ?>
</div>
<?php
$tickets_badge = static function (string $name): string {
    $n = strtolower($name);
    if (strpos($n, 'resolved') !== false || strpos($n, 'closed') !== false) { return 'text-bg-success'; }
    if (strpos($n, 'hold') !== false || strpos($n, 'wait') !== false || strpos($n, 'pending') !== false) { return 'text-bg-warning'; }
    return 'text-bg-secondary';
};
?>
<div class="portal-tabs">
    <a href="?status=Open" class="portal-tab <?php echo $status == 'Open' ? 'is-active' : ''; ?>">Open <span class="portal-tab-n"><?php echo $total_tickets_open; ?></span></a>
    <a href="?status=Closed" class="portal-tab <?php echo $status == 'Closed' ? 'is-active' : ''; ?>">Closed <span class="portal-tab-n"><?php echo $total_tickets_closed; ?></span></a>
    <a href="?status=%25" class="portal-tab <?php echo $status == '%' ? 'is-active' : ''; ?>">All <span class="portal-tab-n"><?php echo $total_tickets; ?></span></a>
    <div class="portal-search">
        <i class="fas fa-search" aria-hidden="true"></i>
        <input type="search" class="form-control" id="ticketFilter" placeholder="Filter by subject or number" aria-label="Filter tickets" autocomplete="off">
    </div>
</div>

<div class="card card-outline card-primary">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h3 class="card-title"><?php echo $status == 'Open' ? 'Open tickets' : ($status == 'Closed' ? 'Closed tickets' : 'All tickets'); ?></h3>
        <a href="ticket_add.php" class="btn btn-sm btn-primary"><i class="fas fa-fw fa-plus me-1"></i>New ticket</a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" id="ticketTable">
                <thead class="thead-light">
                    <tr>
                        <th>#</th>
                        <th>Subject</th>
                        <th>Status</th>
                        <th>Last update</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (mysqli_num_rows($contact_tickets) == 0) { ?>
                    <tr>
                        <td colspan="4" class="text-center text-muted py-4">
                            <?php echo $status == 'Open' ? 'You have no open tickets.' : 'No tickets found.'; ?>
                            <a href="service_catalog.php">Request something</a>
                        </td>
                    </tr>
                <?php }
                while ($row = mysqli_fetch_assoc($contact_tickets)) {
                    $ticket_id = intval($row['ticket_id']);
                    $ticket_prefix = nullable_htmlentities($row['ticket_prefix']);
                    $ticket_number = intval($row['ticket_number']);
                    $ticket_subject = nullable_htmlentities($row['ticket_subject']);
                    $ticket_status = nullable_htmlentities($row['ticket_status_name']);
                    $ticket_last = $row['last_update'] ? nullable_htmlentities(timeAgo($row['last_update'])) : '-';
                ?>
                    <tr>
                        <td class="text-nowrap"><a href="ticket.php?id=<?php echo $ticket_id; ?>">#<?php echo "$ticket_prefix$ticket_number"; ?></a></td>
                        <td><a href="ticket.php?id=<?php echo $ticket_id; ?>"><?php echo $ticket_subject; ?></a></td>
                        <td><span class="badge <?php echo $tickets_badge((string) $row['ticket_status_name']); ?>"><?php echo $ticket_status; ?></span></td>
                        <td class="text-secondary text-nowrap"><?php echo $ticket_last; ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
        <div class="text-center text-muted py-4" id="ticketNoMatch" hidden>No tickets match that filter.</div>
    </div>
</div>




<?php require_once "includes/footer.php";
 ?>
