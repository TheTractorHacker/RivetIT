<?php

require_once '../../../includes/modal_header.php';

$vendor_id = intval($_GET['id']);

$sql = mysqli_query($mysqli, "SELECT * FROM vendors WHERE vendor_id = $vendor_id LIMIT 1");

$row = mysqli_fetch_assoc($sql);
$client_id = intval($row['vendor_client_id']);

// A shared central vendor opened from a department's Vendors tab (?client_id=N): read-only, vendor's own fields only. The person needs
// access to that department and the vendor must currently be shared; an unshared central vendor, or any other department's vendor, is refused.
$dept_ctx = intval($_GET['client_id'] ?? 0);
$dept_view = false;
if ($client_id === 0 && $dept_ctx > 0) {
    enforceUserPermission('module_client');
    enforceUserPermission('module_support');
    enforceClientAccess($dept_ctx);
    if (!vendorIsSharedCentral($mysqli, $vendor_id)) {
        itflow_render_denied('That vendor is not available for this department.');
    }
    $dept_view = true;
} else {
    enforceVendorPermission($client_id, 1);
}

$name = sanitizeInput($row['vendor_name']);
$description = sanitizeInput($row['vendor_description']);
$account_number = sanitizeInput($row['vendor_account_number']);
$contact_name = sanitizeInput($row['vendor_contact_name']);
$phone = preg_replace("/[^0-9]/", '',$row['vendor_phone']);
$extension = preg_replace("/[^0-9]/", '',$row['vendor_extension']);
$email = nullable_htmlentities($row['vendor_email']);
$website = nullable_htmlentities($row['vendor_website']);
$hours = sanitizeInput($row['vendor_hours']);
$sla = sanitizeInput($row['vendor_sla']);
$code = sanitizeInput($row['vendor_code']);
$notes = sanitizeInput($row['vendor_notes']);
if ($dept_view) {
    // Central-only fields never reach a department: account number (finance data) and the owner's internal notes
    $account_number = '';
    $notes = '';
}

// Generate the HTML form content using output buffering.
ob_start();
?>

<div class="modal-header bg-dark text-white">
    <div class="d-flex align-items-center">
        <i class="fas fa-fw fa-building fa-2x me-3"></i>
        <div>
            <h5 class="modal-title mb-0"><?php echo $name; ?></h5>
            <div class="text-white-50"><?php echo getFallback($description); ?></div>
        </div>
    </div>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>

<div class="modal-body bg-light">

    <!-- Vendor Info Card -->
    <div class="card mb-3 shadow-sm rounded">
        <div class="card-body">
            <h6 class="text-secondary"><i class="fas fa-info-circle me-2"></i>Vendor Details</h6>
            <div class="row">
                <div class="col-sm-6">
                    <?php if (!$dept_view && vendorCanSeeAccountNumber($client_id)) { ?>
                    <div><strong>Account Number:</strong> <?php echo getFallback($account_number); ?></div>
                    <?php } ?>
                    <div><strong>Hours:</strong> <?php echo getFallback($hours); ?></div>
                    <div><strong>SLA:</strong> <?php echo getFallback($sla); ?></div>
                </div>
                <div class="col-sm-6">
                    <div><strong>Code:</strong> <?php echo getFallback($code); ?></div>
                    <div><strong>Website:</strong> <?php echo !empty($website) ? '<a href="https://' . $website . '" target="_blank" class="text-primary">' . $website . '</a>' : '<span class="text-muted">Not Available</span>'; ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Contact Info Card -->
    <div class="card mb-3 shadow-sm rounded">
        <div class="card-body">
            <h6 class="text-secondary"><i class="fas fa-user me-2"></i>Contact Information</h6>
            <div class="row">
                <div class="col-sm-6">
                    <div><strong>Contact Name:</strong> <?php echo getFallback($contact_name); ?></div>
                    <div><strong>Phone:</strong> <?php echo getFallback($phone); ?></div>
                </div>
                <div class="col-sm-6">
                    <div><strong>Email:</strong> <?php echo !empty($email) ? '<a href="mailto:' . $email . '" class="text-primary">' . $email . '</a>' : '<span class="text-muted">Not Available</span>'; ?></div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($dept_view) { ?>
    <div class="text-secondary small mb-3"><i class="fas fa-info-circle me-1"></i>Central vendor shared with all departments. It is edited in Vendors (central), not here.</div>
    <?php } else { ?>
    <!-- Notes Card -->
    <div class="card mb-3 shadow-sm rounded">
        <div class="card-body">
            <h6 class="text-secondary"><i class="fas fa-sticky-note me-2"></i>Notes</h6>
            <div>
                <?php echo getFallback($notes); ?>
            </div>
        </div>
    </div>
    <?php } ?>

    <?php
    // Relationships card: this pop-up is the vendor's details view (agent/vendor_details.php is a retired page), so the Link item form is inline.
    // Not for a department's read-only view: a central vendor's links reach records of other departments.
    if (!$dept_view) {
        $rel_type = 'vendor'; $rel_id = $vendor_id; $rel_in_modal = true;
        require __DIR__ . '/../../../includes/relationships_card.php';
    }
    ?>

</div>

<?php
require_once '../../../includes/modal_footer.php';
