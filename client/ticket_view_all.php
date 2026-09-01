<?php
/*
 * Client Portal
 * Primary contact view: all tickets
 */

require_once 'includes/inc_all.php';


if ($session_contact_primary == 0 && !$session_contact_is_technical_contact) {
    header("Location: post.php?logout");
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

$all_tickets = mysqli_query($mysqli, "SELECT ticket_id, ticket_prefix, ticket_number, ticket_subject, ticket_status_name, contact_name FROM tickets LEFT JOIN contacts ON ticket_contact_id = contact_id LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id WHERE $ticket_status_snippet AND ticket_client_id = $session_client_id ORDER BY ticket_id DESC");
?>

    <div class="row">
        <div class="col">
            <h3><i class="fas fa-fw fa-users me-2"></i>All Company Tickets</h3>
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">

            <div class="card card-outline card-primary">
                <div class="card-header">
                    <form method="get" class="form-inline">
                        <label class="me-2 mb-0">Ticket Status</label>
                        <select class="form-control form-control-sm auto-submit-select" name="status">
                            <option value="%" <?php if ($status == "%") {echo "selected";}?> >Any</option>
                            <option value="Open" <?php if ($status == "Open") {echo "selected";}?> >Open</option>
                            <option value="Closed" <?php if ($status == "Closed") {echo "selected";}?> >Closed</option>
                        </select>
                    </form>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="thead-light">
                        <tr>
                            <th scope="col">#</th>
                            <th scope="col">Subject</th>
                            <th scope="col">Contact</th>
                            <th scope="col">Status</th>
                        </tr>
                        </thead>
                        <tbody>

                        <?php
                        if (mysqli_num_rows($all_tickets) == 0) { ?>
                            <tr>
                                <td colspan="4" class="text-center text-muted py-4">No tickets found.</td>
                            </tr>
                        <?php }
                        while ($row = mysqli_fetch_assoc($all_tickets)) {
                            $ticket_id = intval($row['ticket_id']);
                            $ticket_prefix = nullable_htmlentities($row['ticket_prefix']);
                            $ticket_number = intval($row['ticket_number']);
                            $ticket_subject = nullable_htmlentities($row['ticket_subject']);
                            $ticket_status = nullable_htmlentities($row['ticket_status_name']);
                            $ticket_contact_name = nullable_htmlentities($row['contact_name']);
                            ?>
                            <tr>
                                <td class="text-nowrap"><a href="ticket.php?id=<?php echo $ticket_id; ?>">#<?php echo "$ticket_prefix$ticket_number"; ?></a></td>
                                <td><a href="ticket.php?id=<?php echo $ticket_id; ?>"><?php echo $ticket_subject; ?></a></td>
                                <td><?php echo $ticket_contact_name; ?></td>
                                <td><span class="badge text-bg-secondary"><?php echo $ticket_status; ?></span></td>
                            </tr>
                        <?php } ?>
                        </tbody>
                    </table>
                    </div>
                </div>
            </div>

        </div>
    </div>

<?php
require_once 'includes/footer.php';
