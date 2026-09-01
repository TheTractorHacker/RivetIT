<?php

if (isset($_GET['client_id'])) {
    require_once "includes/inc_all_client.php";
    $client_url = "client_id=$client_id&";
} else {
    require_once "includes/inc_all.php";
    $client_url = '';
    $client_id = 0;
}

$sort = "contract_renewal_date";
$order = "ASC";

$client_query = $client_id ? "AND contract_client_id = $client_id" : '';

$sql = mysqli_query($mysqli, "SELECT SQL_CALC_FOUND_ROWS contracts.*, client_name
    FROM contracts
    LEFT JOIN clients ON contract_client_id = client_id
    WHERE contract_archived_at IS NULL $client_query
    AND (contract_name LIKE '%$q%' OR client_name LIKE '%$q%' OR contract_type LIKE '%$q%')
    $access_permission_query
    ORDER BY contract_renewal_date IS NULL, $sort $order
    LIMIT $record_from, $record_to");

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

$today = date('Y-m-d');
$warn_date = date('Y-m-d', strtotime('+45 days'));

?>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-file-contract me-2"></i>Contracts</h3>
        <div class="card-tools">
            <button type="button" class="btn btn-primary ajax-modal"
                data-modal-url="modals/contract/contract_add.php?<?= $client_url ?>">
                <i class="fas fa-plus"></i><span class="d-none d-lg-inline ms-2">New Contract</span>
            </button>
        </div>
    </div>
    <div class="card-body">
        <form autocomplete="off">
            <?php if ($client_id) { ?><input type="hidden" name="client_id" value="<?= $client_id ?>"><?php } ?>
            <div class="row">
                <div class="col-sm-4">
                    <div class="input-group mb-3 mb-sm-0">
                        <input type="search" class="form-control" name="q" value="<?php if(isset($q)) echo stripslashes(nullable_htmlentities($q)); ?>" placeholder="Search Contracts">
                        <div class="input-group-append">
                            <button class="btn btn-primary"><i class="fa fa-search"></i></button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
        <hr>
        <div class="table-responsive">
            <table class="table table-striped table-borderless table-hover">
                <thead class="text-dark <?php if(!$num_rows[0]) echo 'd-none'; ?>">
                <tr>
                    <?php if (!$client_id) { ?><th>Client</th><?php } ?>
                    <th>Contract Name</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Value</th>
                    <th>Frequency</th>
                    <th>SLA</th>
                    <th>Start Date</th>
                    <th>End Date</th>
                    <th>Renewal Date</th>
                    <th class="text-center">Action</th>
                </tr>
                </thead>
                <tbody>
                <?php while ($row = mysqli_fetch_assoc($sql)) {
                    $cid = intval($row['contract_id']);
                    $cclient_id = intval($row['contract_client_id']);
                    $cname = nullable_htmlentities($row['contract_name']);
                    $ctype = nullable_htmlentities($row['contract_type']);
                    $cstatus = nullable_htmlentities($row['contract_status']);
                    $cvalue = $row['contract_value'] !== null ? '$' . number_format(floatval($row['contract_value']), 2) : '-';
                    $cfreq = nullable_htmlentities($row['contract_renewal_frequency'] ?? '');
                    $cstart = nullable_htmlentities($row['contract_start_date']);
                    $cend = nullable_htmlentities($row['contract_end_date']);
                    $crenewal = nullable_htmlentities($row['contract_renewal_date']);
                    $has_sla = $row['contract_sla_high_response_time'] || $row['contract_sla_medium_response_time'] || $row['contract_sla_low_response_time'];
                    $cclient_name = nullable_htmlentities($row['client_name']);

                    $renewal_class = '';
                    if ($crenewal) {
                        if ($crenewal < $today) $renewal_class = 'text-danger fw-bold';
                        elseif ($crenewal <= $warn_date) $renewal_class = 'text-warning fw-bold';
                    }
                ?>
                <tr>
                    <?php if (!$client_id) { ?>
                    <td><a href="contracts.php?client_id=<?= $cclient_id ?>"><?= $cclient_name ?></a></td>
                    <?php } ?>
                    <td><strong><?= $cname ?></strong></td>
                    <td><?= $ctype ?></td>
                    <td><?= $cstatus ?></td>
                    <td><?= $cvalue ?></td>
                    <td><?= $cfreq ?: '-' ?></td>
                    <td><?= $has_sla ? '<span class="badge text-bg-info"><i class="fas fa-stopwatch me-1"></i>SLA</span>' : '-' ?></td>
                    <td><?= $cstart ?: '-' ?></td>
                    <td><?= $cend ?: '-' ?></td>
                    <td class="<?= $renewal_class ?>">
                        <?php if ($crenewal) {
                            echo $crenewal;
                            if ($crenewal < $today) echo " <span class='badge text-bg-danger'>Expired</span>";
                            elseif ($crenewal <= $warn_date) echo " <span class='badge text-bg-warning text-dark'>Due Soon</span>";
                        } else { echo '-'; } ?>
                    </td>
                    <td class="text-center">
                        <a href="#" class="btn btn-sm btn-outline-primary ajax-modal"
                            data-modal-url="modals/contract/contract_documents.php?contract_id=<?= $cid ?>"
                            title="Documents">
                            <i class="fas fa-file-pdf"></i>
                        </a>
                        <a href="#" class="btn btn-sm btn-secondary ajax-modal ms-1"
                            data-modal-url="modals/contract/contract_edit.php?contract_id=<?= $cid ?>">
                            <i class="fas fa-edit"></i>
                        </a>
                        <a href="post.php?delete_contract=<?= $cid ?>&client_id=<?= $cclient_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>" class="btn btn-sm btn-danger confirm-link ms-1">
                            <i class="fas fa-trash"></i>
                        </a>
                    </td>
                </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
        <?php if (!$num_rows[0]) echo "<p class='text-center text-secondary mt-3'>No contracts yet. Add one to get started.</p>"; ?>
        <?php require_once "../includes/filter_footer.php"; ?>
    </div>
</div>

<?php require_once "../includes/footer.php"; ?>
