<?php

// Default Column Sortby Filter
$sort = "ticket_csat_rated_at";
$order = "DESC";

// If client_id is in URI then show client Side Bar and client header
if (isset($_GET['client_id'])) {
    require_once "includes/inc_all_client.php";
    $client_query = "AND ticket_client_id = $client_id";
    $client_url = "client_id=$client_id&";
} else {
    require_once "includes/inc_all.php";
    $client_query = '';
    $client_url = '';
}

// Perms
enforceUserPermission('module_support');

if (!$client_url) {
    // Client Filter
    if (isset($_GET['client']) && !empty($_GET['client'])) {
        $client_query = 'AND (ticket_client_id = ' . intval($_GET['client']) . ')';
        $client = intval($_GET['client']);
    } else {
        $client_query = '';
        $client = '';
    }
}

// Rating filter
if (isset($_GET['rating']) && $_GET['rating'] !== '') {
    $rating_filter = max(1, min(5, intval($_GET['rating'])));
    $rating_query = "AND ticket_csat_rating = $rating_filter";
} else {
    $rating_filter = '';
    $rating_query = '';
}

// Technician filter
if (isset($_GET['tech']) && !empty($_GET['tech'])) {
    $tech_filter = intval($_GET['tech']);
    $tech_query = "AND ticket_assigned_to = $tech_filter";
} else {
    $tech_filter = '';
    $tech_query = '';
}

// Needs-follow-up only filter
$followup_only = (isset($_GET['followup']) && $_GET['followup'] == 1);
$followup_query = $followup_only ? "AND ticket_csat_rating <= $config_ticket_csat_low_rating_threshold AND ticket_status != 5" : '';

$sql = mysqli_query($mysqli, "SELECT SQL_CALC_FOUND_ROWS tickets.*, clients.client_name, users.user_name AS tech_name, ticket_statuses.ticket_status_name
    FROM tickets
    LEFT JOIN clients ON clients.client_id = tickets.ticket_client_id
    LEFT JOIN users ON users.user_id = tickets.ticket_assigned_to
    LEFT JOIN ticket_statuses ON ticket_statuses.ticket_status_id = tickets.ticket_status
    WHERE ticket_csat_rating IS NOT NULL
    AND ticket_archived_at IS NULL
    AND (tickets.ticket_subject LIKE '%$q%' OR clients.client_name LIKE '%$q%' OR users.user_name LIKE '%$q%' OR ticket_csat_comment LIKE '%$q%')
    $rating_query
    $tech_query
    $followup_query
    $access_permission_query
    $client_query
    ORDER BY $sort $order LIMIT $record_from, $record_to");

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

?>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fa fa-fw fa-star me-2"></i>CSAT Ratings</h3>
        <div class="card-tools">
            <a href="reports/csat.php<?php if ($client_url) { echo "?" . $client_url; } ?>" class="btn btn-primary"><i class="fas fa-chart-bar me-2"></i>Analytics &amp; Trends</a>
        </div>
    </div>

    <div class="card-body">
        <form autocomplete="off">
            <?php if ($client_url) { ?>
            <input type="hidden" name="client_id" value="<?php echo $client_id; ?>">
            <?php } ?>
            <div class="row">
                <div class="col-md-4">
                    <div class="input-group mb-3 mb-md-0">
                        <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search ticket, client, technician, comment">
                        <div class="input-group-append">
                            <button class="btn btn-dark"><i class="fa fa-search"></i></button>
                        </div>
                    </div>
                </div>

                <div class="col-md-2">
                    <select class="form-control auto-submit-select" name="rating">
                        <option value="" <?php if ($rating_filter === '') { echo "selected"; } ?>>- Any Rating -</option>
                        <?php for ($r = 5; $r >= 1; $r--) { ?>
                            <option value="<?php echo $r; ?>" <?php if ($rating_filter === $r) { echo "selected"; } ?>><?php echo csatFaceEmoji($r) . ' ' . csatFaceLabel($r); ?></option>
                        <?php } ?>
                    </select>
                </div>

                <div class="col-md-2">
                    <select class="form-control select2 auto-submit-select" name="tech">
                        <option value="" <?php if ($tech_filter === '') { echo "selected"; } ?>>- All Technicians -</option>
                        <?php
                        $sql_techs_filter = mysqli_query($mysqli, "SELECT user_id, user_name FROM users WHERE user_type = 1 AND user_archived_at IS NULL ORDER BY user_name ASC");
                        while ($row = mysqli_fetch_assoc($sql_techs_filter)) {
                            $tech_id_opt = intval($row['user_id']);
                            $tech_name_opt = nullable_htmlentities($row['user_name']);
                        ?>
                            <option value="<?php echo $tech_id_opt; ?>" <?php if ($tech_filter === $tech_id_opt) { echo "selected"; } ?>><?php echo $tech_name_opt; ?></option>
                        <?php } ?>
                    </select>
                </div>

                <?php if ($client_url) { ?>
                <div class="col-md-2"></div>
                <?php } else { ?>
                <div class="col-md-2">
                    <select class="form-control select2 auto-submit-select" name="client">
                        <option value="" <?php if ($client == "") { echo "selected"; } ?>>- All Clients -</option>
                        <?php
                        $sql_clients_filter = mysqli_query($mysqli, "SELECT DISTINCT client_id, client_name FROM clients
                            JOIN tickets ON ticket_client_id = client_id
                            WHERE client_archived_at IS NULL AND ticket_csat_rating IS NOT NULL
                            $access_permission_query
                            ORDER BY client_name ASC");
                        while ($row = mysqli_fetch_assoc($sql_clients_filter)) {
                            $client_id_opt = intval($row['client_id']);
                            $client_name_opt = nullable_htmlentities($row['client_name']);
                        ?>
                            <option value="<?php echo $client_id_opt; ?>" <?php if ($client == $client_id_opt) { echo "selected"; } ?>><?php echo $client_name_opt; ?></option>
                        <?php } ?>
                    </select>
                </div>
                <?php } ?>

                <div class="col-md-2">
                    <a href="?<?php echo $client_url; ?>followup=<?php if ($followup_only) { echo 0; } else { echo 1; } ?>"
                        class="btn btn-block <?php echo $followup_only ? "btn-danger" : "btn-default"; ?>">
                        <i class="fa fa-fw fa-exclamation-triangle me-2"></i>Needs Follow-up
                    </a>
                </div>
            </div>
        </form>
        <hr>
        <div class="table-responsive">
            <table class="table table-striped table-borderless table-hover">
                <thead class="text-dark <?php if ($num_rows[0] == 0) { echo "d-none"; } ?> text-nowrap">
                <tr>
                    <th>
                        <a class="text-secondary" href="?<?php echo $url_query_strings_sort; ?>&sort=ticket_number&order=<?php echo $disp; ?>">
                            Ticket <?php if ($sort == 'ticket_number') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <?php if (!$client_url) { ?>
                    <th>
                        <a class="text-secondary" href="?<?php echo $url_query_strings_sort; ?>&sort=client_name&order=<?php echo $disp; ?>">
                            Client <?php if ($sort == 'client_name') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <?php } ?>
                    <th>
                        <a class="text-secondary" href="?<?php echo $url_query_strings_sort; ?>&sort=tech_name&order=<?php echo $disp; ?>">
                            Technician <?php if ($sort == 'tech_name') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>
                        <a class="text-secondary" href="?<?php echo $url_query_strings_sort; ?>&sort=ticket_csat_rating&order=<?php echo $disp; ?>">
                            Rating <?php if ($sort == 'ticket_csat_rating') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>Comment</th>
                    <th>
                        <a class="text-secondary" href="?<?php echo $url_query_strings_sort; ?>&sort=ticket_csat_rated_at&order=<?php echo $disp; ?>">
                            Rated At <?php if ($sort == 'ticket_csat_rated_at') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>Status</th>
                    <th title="Shown in the public rating widget (GET /api/v1/csat) when checked">Public</th>
                </tr>
                </thead>
                <tbody>
                <?php while ($row = mysqli_fetch_assoc($sql)) {
                    $ticket_id = intval($row['ticket_id']);
                    $ticket_number = intval($row['ticket_number']);
                    $ticket_status_id = intval($row['ticket_status']);
                    $client_name = nullable_htmlentities($row['client_name']);
                    $ticket_client_id = intval($row['ticket_client_id']);
                    $tech_name = nullable_htmlentities($row['tech_name']) ?: '-';
                    $csat_rating = intval($row['ticket_csat_rating']);
                    $csat_comment = nullable_htmlentities($row['ticket_csat_comment']);
                    $csat_rated_at = nullable_htmlentities($row['ticket_csat_rated_at']);
                    $csat_public_approved = intval($row['ticket_csat_public_approved']);
                    $needs_followup = ($csat_rating <= $config_ticket_csat_low_rating_threshold && $ticket_status_id != 5);
                    ?>
                    <tr class="<?php echo $needs_followup ? "table-danger" : ""; ?>">
                        <td><a href="ticket.php?ticket_id=<?php echo $ticket_id; ?>"><?php echo nullable_htmlentities($config_ticket_prefix . $ticket_number); ?></a></td>
                        <?php if (!$client_url) { ?>
                        <td><a href="csat.php?client_id=<?php echo $ticket_client_id; ?>"><?php echo $client_name; ?></a></td>
                        <?php } ?>
                        <td><?php echo $tech_name; ?></td>
                        <td>
                            <span class="csat-face-display" title="<?= csatFaceLabel($csat_rating) ?>"><?= csatFaceEmoji($csat_rating) ?></span>
                        </td>
                        <td class="text-truncate" style="max-width:260px;"><?php echo $csat_comment ?: '-'; ?></td>
                        <td><?php echo $csat_rated_at; ?></td>
                        <td>
                            <?php if ($needs_followup) { ?>
                                <span class="badge text-bg-danger">Needs follow-up</span>
                            <?php } else { ?>
                                <span class="badge text-bg-secondary"><?php echo nullable_htmlentities($row['ticket_status_name']); ?></span>
                            <?php } ?>
                        </td>
                        <td class="text-center">
                            <?php if ($csat_rating >= 4 && $csat_comment) { ?>
                                <div class="form-check form-switch d-inline-block mb-0">
                                    <input type="checkbox" class="form-check-input js-csat-public-toggle" data-ticket-id="<?php echo $ticket_id; ?>" <?php echo $csat_public_approved ? "checked" : ""; ?>>
                                </div>
                            <?php } else { ?>
                                <span class="text-muted small">-</span>
                            <?php } ?>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
        <?php require_once "../includes/filter_footer.php"; ?>
    </div>
</div>

<script>
$(document).on('change', '.js-csat-public-toggle', function () {
    var $box = $(this);
    var approved = $box.is(':checked') ? 1 : 0;
    var csrf = $('input[name="csrf_token"]').first().val();

    $box.prop('disabled', true);
    $.post('post.php', { toggle_csat_public_approved: 1, ticket_id: $box.data('ticket-id'), approved: approved, csrf_token: csrf }, function (res) {
        if (!res.ok) {
            $box.prop('checked', !approved);
            alert('Could not update - please try again.');
        }
    }, 'json').fail(function () {
        $box.prop('checked', !approved);
        alert('Could not update - please try again.');
    }).always(function () {
        $box.prop('disabled', false);
    });
});
</script>

<?php require_once "../includes/footer.php"; ?>
