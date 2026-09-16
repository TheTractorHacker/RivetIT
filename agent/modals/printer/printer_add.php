<?php

require_once '../../../includes/modal_header.php';

$client_id = intval($_GET['client_id'] ?? 0);

// location_client_id = 0 is a real, populated scope here (company-wide
// sites), not just an empty "no department picked yet" case - so this is
// NOT gated behind $client_id being set, unlike Assets' own Location select.
$sql_location_select = mysqli_query($mysqli, "SELECT location_id, location_name FROM locations WHERE location_archived_at IS NULL AND location_client_id = $client_id ORDER BY location_name ASC");

ob_start();

?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-print me-2"></i>New Printer</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="client_id" value="<?= $client_id ?>">

    <div class="modal-body">

        <div class="form-group">
            <label>Printer Name <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-print"></i></span>
                </div>
                <input type="text" class="form-control" name="name" placeholder="Printer Name" maxlength="200" required autofocus>
            </div>
        </div>

        <div class="form-group">
            <label>IP Address</label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-network-wired"></i></span>
                </div>
                <input type="text" class="form-control" name="ip_address" placeholder="e.g. 10.0.1.50" maxlength="200">
            </div>
        </div>

        <div class="form-group">
            <label>Location</label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-building"></i></span>
                </div>
                <select class="form-control select2" name="location_id">
                    <option value="">Select Location</option>
                    <?php
                    while ($row = mysqli_fetch_assoc($sql_location_select)) {
                        $location_id = intval($row['location_id']);
                        $location_name = nullable_htmlentities($row['location_name']);
                        ?>
                        <option value="<?= $location_id ?>"><?= $location_name ?></option>
                    <?php } ?>
                </select>
            </div>
        </div>

        <div class="form-group">
            <label>Physical Location</label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-map-marker-alt"></i></span>
                </div>
                <input type="text" class="form-control" name="physical_location" placeholder="e.g. 2nd Floor Copy Room" maxlength="200">
            </div>
        </div>

        <div class="form-group">
            <label>Model</label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-tag"></i></span>
                </div>
                <input type="text" class="form-control" name="model" placeholder="Manufacturer / Model" maxlength="200">
            </div>
        </div>

        <div class="form-group">
            <label>Serial Number</label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-fingerprint"></i></span>
                </div>
                <input type="text" class="form-control" name="serial_number" placeholder="Serial Number" maxlength="200">
            </div>
        </div>

        <div class="form-group">
            <label>MAC Address</label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-ethernet"></i></span>
                </div>
                <input type="text" class="form-control" name="mac_address" placeholder="e.g. 00:1A:2B:3C:4D:5E" maxlength="200">
            </div>
        </div>

        <div class="form-group">
            <label>Notes</label>
            <textarea class="form-control" rows="6" placeholder="Enter some notes" name="notes"></textarea>
        </div>

    </div>
    <div class="modal-footer">
        <button type="submit" name="add_printer" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Create</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php

require_once '../../../includes/modal_footer.php';
