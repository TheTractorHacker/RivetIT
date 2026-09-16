<?php

// Default Column Sortby Filter
$sort = "printer_name";
$order = "ASC";

// If client_id is in URI then show client Side Bar and client header
if (isset($_GET['client_id'])) {
    require_once "includes/inc_all_client.php";
    $client_query = "AND printer_client_id = $client_id";
    $client_url = "client_id=$client_id&";
} else {
    require_once "includes/inc_all.php";
    $client_query = "AND printer_client_id = 0";
    $client_url = '';
}

// Perms
enforceUserPermission('module_support');

$sql = mysqli_query(
    $mysqli,
    "SELECT SQL_CALC_FOUND_ROWS * FROM printers
    LEFT JOIN clients ON client_id = printer_client_id
    LEFT JOIN locations ON location_id = printer_location_id
    WHERE printer_$archive_query
    AND (printer_name LIKE '%$q%' OR printer_ip_address LIKE '%$q%' OR location_name LIKE '%$q%' OR printer_physical_location LIKE '%$q%' OR printer_model LIKE '%$q%' OR printer_serial_number LIKE '%$q%')
    $client_query
    $access_permission_query
    ORDER BY $sort $order LIMIT $record_from, $record_to"
);

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

?>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2">
            <i class="fas fa-fw fa-print me-2"></i>Printers
        </h3>
        <div class="card-tools">
            <button type="button" class="btn btn-primary ajax-modal" data-modal-url="modals/printer/printer_add.php?<?= $client_url ?>">
                <i class="fas fa-plus me-2"></i>New Printer
            </button>
        </div>
    </div>
    <div class="card-body">
        <form autocomplete="off">
            <?php if ($client_url) { ?>
                <input type="hidden" name="client_id" value="<?php echo $client_id; ?>">
            <?php } ?>
            <input type="hidden" name="archived" value="<?php echo $archived; ?>">
            <div class="row">

                <div class="col-md-4">
                    <div class="input-group mb-3 mb-md-0">
                        <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search Printers">
                        <div class="input-group-append">
                            <button class="btn btn-dark"><i class="fa fa-search"></i></button>
                        </div>
                    </div>
                </div>

                <div class="col-md-8">
                    <div class="btn-group float-end">
                        <a href="?<?php echo "$client_url"; ?>archived=<?php if($archived == 1){ echo 0; } else { echo 1; } ?>"
                            class="btn btn-<?php if($archived == 1){ echo "primary"; } else { echo "default"; } ?>">
                            <i class="fa fa-fw fa-archive me-2"></i>Archived
                        </a>
                        <div class="dropdown ms-2" id="bulkActionButton" hidden>
                            <button class="btn btn-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                <i class="fas fa-fw fa-layer-group me-2"></i>Bulk Action (<span id="selectedCount">0</span>)
                            </button>
                            <div class="dropdown-menu">
                                <?php if ($archived) { ?>
                                <button class="dropdown-item text-info"
                                    type="submit" form="bulkActions" name="bulk_restore_printers">
                                    <i class="fas fa-fw fa-redo me-2"></i>Restore
                                </button>
                                <div class="dropdown-divider"></div>
                                <button class="dropdown-item text-danger text-bold"
                                    type="submit" form="bulkActions" name="bulk_delete_printers">
                                    <i class="fas fa-fw fa-trash me-2"></i>Delete
                                </button>
                                <?php } else { ?>
                                <button class="dropdown-item text-danger confirm-link"
                                    type="submit" form="bulkActions" name="bulk_archive_printers">
                                    <i class="fas fa-fw fa-archive me-2"></i>Archive
                                </button>
                                <?php } ?>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </form>
        <hr>
        <form id="bulkActions" action="post.php" method="post">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">

            <div class="table-responsive">
                <table class="table table-striped table-borderless table-hover">
                    <thead class="text-dark <?php if ($num_rows[0] == 0) { echo "d-none"; } ?> text-nowrap">
                    <tr>
                        <td class="bg-light checkbox-column">
                            <div class="form-check">
                                <input class="form-check-input" id="selectAllCheckbox" type="checkbox">
                            </div>
                        </td>
                        <th>
                            <a class="text-secondary" href="?<?php echo $url_query_strings_sort; ?>&sort=printer_name&order=<?php echo $disp; ?>">
                                Printer <?php if ($sort == 'printer_name') { echo $order_icon; } ?>
                            </a>
                        </th>
                        <th>
                            <a class="text-secondary" href="?<?php echo $url_query_strings_sort; ?>&sort=printer_ip_address&order=<?php echo $disp; ?>">
                                IP Address <?php if ($sort == 'printer_ip_address') { echo $order_icon; } ?>
                            </a>
                        </th>
                        <th>
                            <a class="text-secondary" href="?<?php echo $url_query_strings_sort; ?>&sort=printer_model&order=<?php echo $disp; ?>">
                                Model <?php if ($sort == 'printer_model') { echo $order_icon; } ?>
                            </a>
                        </th>
                        <th>
                            Serial / MAC
                        </th>
                        <th class="text-center">Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php

                    while ($row = mysqli_fetch_assoc($sql)) {
                        $printer_id = intval($row['printer_id']);
                        $printer_name = nullable_htmlentities($row['printer_name']);
                        $printer_ip_address = nullable_htmlentities($row['printer_ip_address']);
                        $location_name = nullable_htmlentities($row['location_name']);
                        $printer_physical_location = nullable_htmlentities($row['printer_physical_location']);
                        $printer_location_parts = array_filter([$location_name, $printer_physical_location]);
                        if ($printer_location_parts) {
                            $printer_location_display = "<div class='text-secondary'>" . implode(' - ', $printer_location_parts) . "</div>";
                        } else {
                            $printer_location_display = '';
                        }
                        $printer_model = nullable_htmlentities($row['printer_model']);
                        if (!$printer_model) {
                            $printer_model = "-";
                        }
                        $printer_serial_number = nullable_htmlentities($row['printer_serial_number']);
                        $printer_mac_address = nullable_htmlentities($row['printer_mac_address']);
                        $printer_archived_at = nullable_htmlentities($row['printer_archived_at']);

                        ?>
                        <tr>
                            <td class="bg-light checkbox-column">
                                <div class="form-check">
                                    <input class="form-check-input bulk-select" type="checkbox" name="printer_ids[]" value="<?php echo $printer_id ?>">
                                </div>
                            </td>
                            <td>
                                <a class="ajax-modal" href="#" data-modal-url="modals/printer/printer_details.php?id=<?= $printer_id ?>">
                                    <div class="media">
                                        <i class="fas fa-fw fa-2x fa-print text-dark me-2"></i>
                                        <div class="media-body">
                                            <div><?php echo $printer_name; ?></div>
                                            <?php echo $printer_location_display; ?>
                                        </div>
                                    </div>
                                </a>
                            </td>
                            <td><?php echo $printer_ip_address ?: '-'; ?></td>
                            <td><?php echo $printer_model; ?></td>
                            <td>
                                <?php if ($printer_serial_number) { ?>
                                    <div class="text-secondary">SN: <?php echo $printer_serial_number; ?></div>
                                <?php } ?>
                                <?php if ($printer_mac_address) { ?>
                                    <div class="text-secondary">MAC: <?php echo $printer_mac_address; ?></div>
                                <?php } ?>
                            </td>
                            <td>
                                <div class="dropdown dropleft text-center">
                                    <button class="btn btn-secondary btn-sm" type="button" data-bs-toggle="dropdown" data-boundary="window">
                                        <i class="fas fa-ellipsis-h"></i>
                                    </button>
                                    <div class="dropdown-menu">
                                        <a class="dropdown-item ajax-modal" href="#" data-modal-url="modals/printer/printer_edit.php?id=<?= $printer_id ?>">
                                            <i class="fas fa-fw fa-edit me-2"></i>Edit
                                        </a>
                                        <?php if ($session_user_role == 3) { ?>
                                            <?php if ($printer_archived_at) { ?>
                                            <div class="dropdown-divider"></div>
                                            <a class="dropdown-item text-info confirm-link" href="post.php?restore_printer=<?= $printer_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                                <i class="fas fa-fw fa-redo me-2"></i>Restore
                                            </a>
                                            <?php if ($config_destructive_deletes_enable) { ?>
                                            <div class="dropdown-divider"></div>
                                            <a class="dropdown-item text-danger text-bold confirm-link" href="post.php?delete_printer=<?= $printer_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                                <i class="fas fa-fw fa-trash me-2"></i>Delete
                                            </a>
                                            <?php } ?>
                                            <?php } else { ?>
                                            <div class="dropdown-divider"></div>
                                            <a class="dropdown-item text-danger confirm-link" href="post.php?archive_printer=<?= $printer_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                                <i class="fas fa-fw fa-archive me-2"></i>Archive
                                            </a>
                                            <?php } ?>
                                        <?php } ?>
                                    </div>
                                </div>
                            </td>
                        </tr>

                        <?php
                    }

                    ?>

                    </tbody>
                </table>
            </div>
        </form>
        <?php require_once "../includes/filter_footer.php";
?>
    </div>
</div>

<script src="../js/bulk_actions.js"></script>

<?php
require_once "../includes/footer.php";
