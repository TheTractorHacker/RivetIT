<?php

require_once '../../../includes/modal_header.php';

$network_drive_id = intval($_GET['id']);

$sql = mysqli_query($mysqli, "SELECT * FROM network_drives WHERE network_drive_id = $network_drive_id LIMIT 1");

$row = mysqli_fetch_assoc($sql);
$client_id = intval($row['network_drive_client_id']);
enforceUserPermission('module_support');
enforceClientAccess($client_id);

$name = sanitizeInput($row['network_drive_name']);
$letter = sanitizeInput($row['network_drive_letter']);
$path = sanitizeInput($row['network_drive_path']);
$purpose = sanitizeInput($row['network_drive_purpose']);
$notes = nullable_htmlentities($row['network_drive_notes']);

// Generate the HTML form content using output buffering.
ob_start();
?>

<div class="modal-header bg-dark text-white">
    <div class="d-flex align-items-center">
        <i class="fas fa-fw fa-hdd fa-2x me-3"></i>
        <div>
            <h5 class="modal-title mb-0"><?php echo $name; ?></h5>
            <div class="text-muted"><?php echo getFallback($purpose); ?></div>
        </div>
    </div>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>

<div class="modal-body bg-light">

    <!-- Drive Info Card -->
    <div class="card mb-3 shadow-sm rounded">
        <div class="card-body">
            <h6 class="text-secondary"><i class="fas fa-info-circle me-2"></i>Drive Details</h6>
            <div class="row">
                <div class="col-sm-6">
                    <div><strong>Drive Letter:</strong> <?php echo getFallback($letter); ?></div>
                </div>
                <div class="col-sm-6">
                    <div><strong>Path:</strong> <?php echo !empty($path) ? "<code>$path</code>" : '<span class="text-muted">Not Available</span>'; ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Notes Card -->
    <div class="card mb-3 shadow-sm rounded">
        <div class="card-body">
            <h6 class="text-secondary"><i class="fas fa-sticky-note me-2"></i>Notes</h6>
            <div>
                <?php echo getFallback($notes); ?>
            </div>
        </div>
    </div>

</div>

<?php
require_once '../../../includes/modal_footer.php';
