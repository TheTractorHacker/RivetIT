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

$contact_tickets = mysqli_query($mysqli, "SELECT ticket_id, ticket_prefix, ticket_number, ticket_subject, ticket_status_name FROM tickets LEFT JOIN contacts ON ticket_contact_id = contact_id LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id WHERE $ticket_status_snippet AND ticket_contact_id = $session_contact_id AND ticket_client_id = $session_client_id ORDER BY ticket_id DESC");

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

<div class="row mb-4">
    <div class="col">
        <h3><i class="fas fa-fw fa-ticket-alt me-2"></i>Tickets</h3>
    </div>
</div>
<div class="row">

    <div class="col-md-9">

        <div class="card card-outline card-primary">
            <div class="card-header">
                <h3 class="card-title">
                    <?php
                    if ($status == 'Open') {
                        echo 'Open Tickets';
                    } elseif ($status == 'Closed') {
                        echo 'Closed Tickets';
                    } else {
                        echo 'All Tickets';
                    }
                    ?>
                </h3>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="thead-light">
                            <tr>
                                <th>#</th>
                                <th>Subject</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>

                        <?php
                        if (mysqli_num_rows($contact_tickets) == 0) { ?>
                            <tr>
                                <td colspan="3" class="text-center text-muted py-4">No tickets found.</td>
                            </tr>
                        <?php
                        }
                        while ($row = mysqli_fetch_assoc($contact_tickets)) {
                            $ticket_id = intval($row['ticket_id']);
                            $ticket_prefix = nullable_htmlentities($row['ticket_prefix']);
                            $ticket_number = intval($row['ticket_number']);
                            $ticket_subject = nullable_htmlentities($row['ticket_subject']);
                            $ticket_status = nullable_htmlentities($row['ticket_status_name']);
                        ?>

                            <tr>
                                <td class="text-nowrap">
                                    <a href="ticket.php?id=<?php echo $ticket_id; ?>">#<?php echo "$ticket_prefix$ticket_number"; ?></a>
                                </td>
                                <td>
                                    <a href="ticket.php?id=<?php echo $ticket_id; ?>"><?php echo $ticket_subject; ?></a>
                                </td>
                                <td><span class="badge text-bg-secondary"><?php echo $ticket_status; ?></span></td>
                            </tr>
                        <?php
                        }
                        ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>

    <div class="col-md-3">

        <a href="ticket_add.php" class="btn btn-primary btn-block mb-3"><i class="fas fa-fw fa-plus me-1"></i>New Ticket</a>

        <div class="card card-outline card-secondary">
            <div class="card-header">
                <h3 class="card-title">My Tickets</h3>
            </div>
            <div class="list-group list-group-flush">
                <a href="?status=Open" class="list-group-item d-flex justify-content-between align-items-center <?php echo $status == 'Open' ? 'active' : ''; ?>">
                    Open <span class="badge text-bg-danger"><?php echo $total_tickets_open ?></span>
                </a>
                <a href="?status=Closed" class="list-group-item d-flex justify-content-between align-items-center <?php echo $status == 'Closed' ? 'active' : ''; ?>">
                    Closed <span class="badge text-bg-success"><?php echo $total_tickets_closed ?></span>
                </a>
                <a href="?status=%" class="list-group-item d-flex justify-content-between align-items-center <?php echo $status == '%' ? 'active' : ''; ?>">
                    All <span class="badge text-bg-secondary"><?php echo $total_tickets ?></span>
                </a>
            </div>
        </div>

        <?php if ($session_contact_primary == 1 || $session_contact_is_technical_contact) { ?>
            <a href="ticket_view_all.php" class="btn btn-dark btn-block mt-3"><i class="fas fa-fw fa-users me-1"></i>All Company Tickets</a>
        <?php } ?>

    </div>
</div>

<?php require_once "includes/footer.php";
 ?>
