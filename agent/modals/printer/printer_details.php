<?php

require_once '../../../includes/modal_header.php';

$printer_id = intval($_GET['id']);

$sql = mysqli_query($mysqli, "SELECT * FROM printers LEFT JOIN locations ON location_id = printer_location_id WHERE printer_id = $printer_id LIMIT 1");

$row = mysqli_fetch_assoc($sql);
$client_id = intval($row['printer_client_id']);
enforceUserPermission('module_support');
enforceClientAccess($client_id);

$name = sanitizeInput($row['printer_name']);
$ip_address = sanitizeInput($row['printer_ip_address']);
$location_name = sanitizeInput($row['location_name']);
$physical_location = sanitizeInput($row['printer_physical_location']);
$model = sanitizeInput($row['printer_model']);
$serial_number = sanitizeInput($row['printer_serial_number']);
$mac_address = sanitizeInput($row['printer_mac_address']);
$notes = nullable_htmlentities($row['printer_notes']);

$location_display = trim(implode(' - ', array_filter([$location_name, $physical_location])));

// Generate the HTML form content using output buffering.
ob_start();
?>

<div class="modal-header bg-dark text-white">
    <div class="d-flex align-items-center">
        <i class="fas fa-fw fa-print fa-2x me-3"></i>
        <div>
            <h5 class="modal-title mb-0"><?php echo $name; ?></h5>
            <div class="text-muted"><?php echo getFallback($location_display); ?></div>
        </div>
    </div>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>

<div class="modal-body bg-light">

    <!-- Printer Info Card -->
    <div class="card mb-3 shadow-sm rounded">
        <div class="card-body">
            <h6 class="text-secondary"><i class="fas fa-info-circle me-2"></i>Printer Details</h6>
            <div class="row">
                <div class="col-sm-6">
                    <div><strong>IP Address:</strong> <?php echo getFallback($ip_address); ?></div>
                    <div><strong>Model:</strong> <?php echo getFallback($model); ?></div>
                    <div><strong>Location:</strong> <?php echo getFallback($location_name); ?></div>
                    <div><strong>Physical Location:</strong> <?php echo getFallback($physical_location); ?></div>
                </div>
                <div class="col-sm-6">
                    <div><strong>Serial Number:</strong> <?php echo getFallback($serial_number); ?></div>
                    <div><strong>MAC Address:</strong> <?php echo getFallback($mac_address); ?></div>
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
