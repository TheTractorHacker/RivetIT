<?php

// Default Column Sortby/Order Filter
$sort = "opportunity_created_at";
$order = "DESC";

// If client_id is in URI then show client Side Bar and client header
if (isset($_GET['client_id'])) {
    require_once "includes/inc_all_client.php";
    $client_filter_query = "AND opportunity_client_id = $client_id";
    $client_url = "client_id=$client_id&";
} else {
    require_once "includes/inc_all.php";
    $client_filter_query = '';
    $client_url = '';
}

// Perms
enforceUserPermission('module_sales');

// Client-scoping snippet for restricted users
$opp_access_snippet = '';
if (!empty($client_access_string) && empty($session_is_admin)) {
    $opp_access_snippet = "AND (opportunity_client_id IN ($client_access_string) OR opportunity_client_id IS NULL)";
}

$stages = getOpportunityStages();

// Stage filter
if (isset($_GET['stage']) && !empty($_GET['stage']) && isset($stages[$_GET['stage']])) {
    $stage_filter = sanitizeInput($_GET['stage']);
    $stage_query = "AND opportunity_stage = '$stage_filter'";
} else {
    $stage_filter = '';
    $stage_query = '';
}

// Status filter (open / won / lost / all)
if (isset($_GET['status']) && in_array($_GET['status'], array('open', 'won', 'lost', 'all'))) {
    $status_filter = $_GET['status'];
} else {
    $status_filter = 'open';
}
if ($status_filter === 'all') {
    $status_query = '';
} else {
    $status_query = "AND opportunity_status = '$status_filter'";
}

$sql = mysqli_query(
    $mysqli,
    "SELECT SQL_CALC_FOUND_ROWS opportunities.*, clients.client_name, contacts.contact_name, users.user_name
     FROM opportunities
     LEFT JOIN clients ON opportunity_client_id = client_id
     LEFT JOIN contacts ON opportunity_contact_id = contact_id
     LEFT JOIN users ON opportunity_owner = user_id
     WHERE opportunity_archived_at IS NULL
     AND (opportunity_name LIKE '%$q%' OR client_name LIKE '%$q%' OR contact_name LIKE '%$q%' OR user_name LIKE '%$q%')
     $stage_query
     $status_query
     $client_filter_query
     $opp_access_snippet
     ORDER BY $sort $order LIMIT $record_from, $record_to"
);

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

?>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-funnel-dollar me-2"></i>Opportunities</h3>
        <div class="card-tools">
            <a href="pipeline.php" class="btn btn-secondary btn-sm me-1"><i class="fas fa-fw fa-stream me-1"></i>Pipeline</a>
            <?php if (lookupUserPermission("module_sales") >= 2) { ?>
                <button type="button" class="btn btn-primary btn-sm ajax-modal" data-modal-url="modals/opportunity/opportunity_add.php?<?= $client_url ?>"><i class="fas fa-plus"></i><span class="d-none d-lg-inline ms-2">New Opportunity</span></button>
            <?php } ?>
        </div>
    </div>

    <div class="card-body">
        <form class="mb-3" autocomplete="off">
            <?php if ($client_url) { ?>
                <input type="hidden" name="client_id" value="<?php echo $client_id; ?>">
            <?php } ?>
            <input type="hidden" name="status" value="<?php echo nullable_htmlentities($status_filter); ?>">
            <div class="row">
                <div class="col-sm-4">
                    <div class="input-group mb-2 mb-sm-0">
                        <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search Opportunities">
                        <div class="input-group-append">
                            <button class="btn btn-primary"><i class="fa fa-search"></i></button>
                        </div>
                    </div>
                </div>
                <div class="col-sm-3">
                    <select class="form-control select2 auto-submit-select" name="stage">
                        <option value="">- All Stages -</option>
                        <?php foreach ($stages as $stage_name => $stage_prob) { ?>
                            <option value="<?php echo $stage_name; ?>" <?php if ($stage_filter === $stage_name) { echo 'selected'; } ?>><?php echo $stage_name; ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="col-sm-5">
                    <div class="btn-toolbar float-sm-end">
                        <div class="btn-group">
                            <?php
                            $status_tabs = array('open' => 'Open', 'won' => 'Won', 'lost' => 'Lost', 'all' => 'All');
                            foreach ($status_tabs as $st_key => $st_label) { ?>
                                <a href="?<?php echo $client_url; ?>status=<?php echo $st_key; ?><?php if ($stage_filter) { echo '&stage=' . urlencode($stage_filter); } ?>" class="btn btn-<?php echo ($status_filter === $st_key) ? 'primary' : 'default'; ?>"><?php echo $st_label; ?></a>
                            <?php } ?>
                        </div>
                    </div>
                </div>
            </div>
        </form>
        <hr>
        <div class="table-responsive">
            <table class="table table-striped table-hover table-borderless">
                <thead class="<?php if ($num_rows[0] == 0) { echo "d-none"; } ?> text-nowrap">
                <tr>
                    <th>
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=opportunity_name&order=<?php echo $disp; ?>">
                            Opportunity <?php if ($sort == 'opportunity_name') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <?php if (!$client_url) { ?>
                    <th>Client</th>
                    <?php } ?>
                    <th>
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=opportunity_stage&order=<?php echo $disp; ?>">
                            Stage <?php if ($sort == 'opportunity_stage') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th class="text-end">
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=opportunity_amount&order=<?php echo $disp; ?>">
                            Amount <?php if ($sort == 'opportunity_amount') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th class="text-end">Prob.</th>
                    <th class="text-end">Weighted</th>
                    <th>
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=opportunity_close_date&order=<?php echo $disp; ?>">
                            Close <?php if ($sort == 'opportunity_close_date') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>Owner</th>
                    <th class="text-center">Action</th>
                </tr>
                </thead>
                <tbody>
                <?php
                while ($row = mysqli_fetch_assoc($sql)) {
                    $opportunity_id = intval($row['opportunity_id']);
                    $opportunity_name = nullable_htmlentities($row['opportunity_name']);
                    $opp_client_id = intval($row['opportunity_client_id']);
                    $opp_client_name = nullable_htmlentities($row['client_name']);
                    $opp_stage = nullable_htmlentities($row['opportunity_stage']);
                    $opp_stage_color = opportunityStageColor($row['opportunity_stage']);
                    $opp_amount = floatval($row['opportunity_amount']);
                    $opp_prob = intval($row['opportunity_probability']);
                    $opp_weighted = $opp_amount * $opp_prob / 100;
                    $opp_close = nullable_htmlentities($row['opportunity_close_date']);
                    $opp_owner = nullable_htmlentities($row['user_name']);
                    $opp_status = nullable_htmlentities($row['opportunity_status']);
                    ?>
                    <tr>
                        <td>
                            <a class="text-dark ajax-modal" href="#" data-modal-url="modals/opportunity/opportunity_edit.php?id=<?php echo $opportunity_id; ?>">
                                <i class="fa fa-fw fa-funnel-dollar me-1 text-secondary"></i><?php echo $opportunity_name; ?>
                            </a>
                        </td>
                        <?php if (!$client_url) { ?>
                        <td>
                            <?php if ($opp_client_id) { ?>
                                <a href="client_overview.php?client_id=<?php echo $opp_client_id; ?>"><?php echo $opp_client_name; ?></a>
                            <?php } else { echo '<span class="text-muted">-</span>'; } ?>
                        </td>
                        <?php } ?>
                        <td><span class="badge badge-<?php echo $opp_stage_color; ?>"><?php echo $opp_stage; ?></span></td>
                        <td class="text-end"><?php echo numfmt_format_currency($currency_format, $opp_amount, "$session_company_currency"); ?></td>
                        <td class="text-end"><?php echo $opp_prob; ?>%</td>
                        <td class="text-end"><?php echo numfmt_format_currency($currency_format, $opp_weighted, "$session_company_currency"); ?></td>
                        <td><?php echo $opp_close ? $opp_close : '<span class="text-muted">-</span>'; ?></td>
                        <td><?php echo $opp_owner ? $opp_owner : '<span class="text-muted">-</span>'; ?></td>
                        <td>
                            <div class="dropdown dropleft text-center">
                                <button class="btn btn-secondary btn-sm" type="button" data-bs-toggle="dropdown" data-boundary="window">
                                    <i class="fas fa-ellipsis-h"></i>
                                </button>
                                <div class="dropdown-menu">
                                    <a class="dropdown-item ajax-modal" href="#" data-modal-url="modals/opportunity/opportunity_edit.php?id=<?php echo $opportunity_id; ?>">
                                        <i class="fas fa-fw fa-edit me-2"></i>Edit
                                    </a>
                                    <?php if (lookupUserPermission("module_sales") >= 3) { ?>
                                        <div class="dropdown-divider"></div>
                                        <a class="dropdown-item text-danger confirm-link" href="post.php?delete_opportunity=<?php echo $opportunity_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>">
                                            <i class="fas fa-fw fa-trash me-2"></i>Delete
                                        </a>
                                    <?php } ?>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
        <?php require_once "../includes/filter_footer.php"; ?>
    </div>
</div>

<?php require_once "../includes/footer.php"; ?>
