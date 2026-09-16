<?php

require_once '../../../includes/modal_header.php';

$client_id = intval($_GET['id']);

$sql = mysqli_query($mysqli, "SELECT * FROM clients WHERE client_id = $client_id $access_permission_query LIMIT 1");

$row = mysqli_fetch_assoc($sql);
$client_name = nullable_htmlentities($row['client_name']);
$client_type = nullable_htmlentities($row['client_type']);
$client_website = nullable_htmlentities($row['client_website']);
$client_referral = nullable_htmlentities($row['client_referral']);
$client_net_terms = intval($row['client_net_terms']);
$client_tax_id_number = nullable_htmlentities($row['client_tax_id_number']);
$client_abbreviation = nullable_htmlentities($row['client_abbreviation']);
$client_rate = floatval($row['client_rate']);
$client_status = nullable_htmlentities($row['client_status'] ?? 'Active');
$client_security_classification = nullable_htmlentities($row['client_security_classification'] ?? 'General');
$client_cost_center = nullable_htmlentities($row['client_cost_center']);
$client_notes = nullable_htmlentities($row['client_notes']);
$client_created_at = nullable_htmlentities($row['client_created_at']);
$client_archived_at = nullable_htmlentities($row['client_archived_at']);

// Client Tags
$client_tag_id_array = array();
$sql_client_tags = mysqli_query($mysqli, "SELECT tag_id FROM client_tags WHERE client_id = $client_id");
while ($row = mysqli_fetch_assoc($sql_client_tags)) {
    $client_tag_id = intval($row['tag_id']);
    $client_tag_id_array[] = $client_tag_id;
}

// Locations (many-to-many via department_sites - a department can have
// multiple locations, and a location can be shared by multiple departments)
$sql_locations_select = mysqli_query($mysqli, "SELECT location_id, location_name, location_city, location_state FROM locations WHERE location_archived_at IS NULL ORDER BY location_name ASC");
$client_location_id_array = array();
$sql_client_locations = mysqli_query($mysqli, "SELECT location_id FROM department_sites WHERE client_id = $client_id");
while ($loc_link_row = mysqli_fetch_assoc($sql_client_locations)) {
    $client_location_id_array[] = intval($loc_link_row['location_id']);
}

$net_terms_array = array (
    '0'=>'On Receipt',
    '7'=>'7 Days',
    '10'=>'10 Days',
    '15'=>'15 Days',
    '30'=>'30 Days',
    '45'=>'45 Days',
    '60'=>'60 Days',
    '90'=>'90 Days'
);

ob_start();

?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class='fa fa-fw fa-user-edit me-2'></i>Editing Department: <strong><?php echo $client_name; ?></strong></h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>

<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="client_id" value="<?= $client_id ?>">

    <ul class="modal-header nav nav-pills nav-justified mb-3">
        <li class="nav-item">
            <a class="nav-link active" data-bs-toggle="pill" href="#pills-client-details<?php echo $client_id; ?>">Details</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" data-bs-toggle="pill" href="#pills-client-locations<?php echo $client_id; ?>">Locations</a>
        </li>
        <?php if ($config_module_enable_accounting) { ?>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="pill" href="#pills-client-billing<?php echo $client_id; ?>">Billing</a>
            </li>
        <?php } ?>
        <li class="nav-item">
            <a class="nav-link" data-bs-toggle="pill" href="#pills-client-notes<?php echo $client_id; ?>">Notes</a>
        </li>
    </ul>

    <div class="modal-body">

        <div class="tab-content">

            <div class="tab-pane fade show active" id="pills-client-details<?php echo $client_id; ?>">

                <div class="form-group">
                    <label>Name <strong class="text-danger">*</strong></label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-id-badge"></i></span>
                        </div>
                        <input type="text" class="form-control" name="name" placeholder="Name or Company" maxlength="200"
                               value="<?php echo $client_name; ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Shortened Name</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-id-badge"></i></span>
                        </div>
                        <input type="text" class="form-control js-uppercase-input" name="abbreviation" placeholder="Shortened name for department - Max chars 6" value="<?php echo $client_abbreviation; ?>" maxlength="6">
                    </div>
                </div>

                <div class="form-group">
                    <label>Cost Center</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-hashtag"></i></span>
                        </div>
                        <input type="text" class="form-control" name="cost_center" placeholder="e.g. CC-410" maxlength="100"
                               value="<?php echo $client_cost_center; ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label>Status</label>
                    <select class="form-control select2" name="status">
                        <?php foreach (['Active', 'Inactive', 'On Hold'] as $status_option) { ?>
                            <option <?php if ($client_status == $status_option) { echo "selected"; } ?>><?php echo $status_option; ?></option>
                        <?php } ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Security Classification <small class="text-secondary">(controls nothing yet on its own - pairs with per-user Department access restrictions under Users)</small></label>
                    <select class="form-control select2" name="security_classification">
                        <?php foreach (['General', 'Confidential', 'Restricted'] as $classification_option) { ?>
                            <option <?php if ($client_security_classification == $classification_option) { echo "selected"; } ?>><?php echo $classification_option; ?></option>
                        <?php } ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Referral</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-link"></i></span>
                        </div>
                        <select class="form-control select2" data-tags="true" name="referral">
                            <option value="">Select Referral</option>
                            <?php

                            $referral_sql = mysqli_query($mysqli, "SELECT * FROM categories WHERE category_type = 'Referral' AND (category_archived_at > '$client_created_at' OR category_archived_at IS NULL) ORDER BY category_name ASC");
                            while ($row = mysqli_fetch_assoc($referral_sql)) {
                                $referral = nullable_htmlentities($row['category_name']);
                                ?>
                                <option <?php if ($client_referral == $referral) {
                                    echo "selected";
                                } ?>>
                                    <?php echo $referral; ?>
                                </option>

                                <?php
                            }
                            ?>
                        </select>
                        <div class="input-group-append">
                            <button class="btn btn-secondary ajax-modal" type="button"
                                data-modal-url="../admin/modals/category/category_add.php?category=Referral">
                                <i class="fas fa-fw fa-plus"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label>Website</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-globe"></i></span>
                        </div>
                        <input type="text" class="form-control" name="website" placeholder="ex. google.com" maxlength="200"
                               value="<?php echo $client_website; ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label>Tags</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-tags"></i></span>
                        </div>
                        <select class="form-control select2" name="tags[]" data-placeholder="Add some tags" multiple>
                            <?php

                            $sql_tags_select = mysqli_query($mysqli, "SELECT * FROM tags WHERE tag_type = 1 ORDER BY tag_name ASC");
                            while ($row = mysqli_fetch_assoc($sql_tags_select)) {
                                $tag_id_select = intval($row['tag_id']);
                                $tag_name_select = nullable_htmlentities($row['tag_name']);
                                ?>
                                <option value="<?php echo $tag_id_select; ?>" <?php if (in_array($tag_id_select, $client_tag_id_array)) { echo "selected"; } ?>><?php echo $tag_name_select; ?></option>
                            <?php } ?>

                        </select>
                        <div class="input-group-append">
                            <button class="btn btn-secondary ajax-modal" type="button"
                                data-modal-url="../admin/modals/tag/tag_add.php?type=1">
                                <i class="fas fa-fw fa-plus"></i>
                            </button>
                        </div>
                    </div>
                </div>

            </div>

            <div class="tab-pane fade" id="pills-client-locations<?php echo $client_id; ?>">

                <div class="form-group">
                    <label>Locations <small class="text-secondary">(optional)</small></label>
                    <div class="d-flex justify-content-end mb-2">
                        <button class="btn btn-secondary btn-sm ajax-modal" type="button"
                            data-modal-url="../modals/location/location_add.php">
                            <i class="fas fa-fw fa-plus me-1"></i>New Location
                        </button>
                    </div>
                    <div style="max-height:260px; overflow-y:auto;">
                        <?php if (mysqli_num_rows($sql_locations_select) === 0) { ?>
                            <p class="text-muted small mb-0">No locations yet.</p>
                        <?php }
                        while ($location_row = mysqli_fetch_assoc($sql_locations_select)) {
                            $location_row_id = intval($location_row['location_id']);
                            $location_row_label = nullable_htmlentities($location_row['location_name']);
                            $location_row_place = trim(($location_row['location_city'] ?: '') . (($location_row['location_city'] && $location_row['location_state']) ? ', ' : '') . ($location_row['location_state'] ?: ''));
                            if ($location_row_place !== '') { $location_row_label .= ' - ' . nullable_htmlentities($location_row_place); }
                        ?>
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" name="locations[]" value="<?= $location_row_id ?>" id="editloc_<?= $location_row_id ?>_<?= $client_id ?>" <?php if (in_array($location_row_id, $client_location_id_array)) { echo 'checked'; } ?>>
                                <label class="form-check-label" for="editloc_<?= $location_row_id ?>_<?= $client_id ?>"><?= $location_row_label ?></label>
                            </div>
                        <?php } ?>
                    </div>
                    <small class="text-muted">Links this department to any existing locations that apply.</small>
                </div>

            </div>

            <?php if ($config_module_enable_accounting) { ?>

                <div class="tab-pane fade" id="pills-client-billing<?php echo $client_id; ?>">

                    <div class="form-group">
                        <label>Hourly Rate</label>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text"><i class="fa fa-fw fa-clock"></i></span>
                            </div>
                            <input type="text" class="form-control" inputmode="decimal"
                                   pattern="[0-9]*\.?[0-9]{0,2}" name="rate" placeholder="0.00"
                                   value="<?php echo number_format($client_rate, 2, '.', ''); ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Invoice Net Terms</label>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text"><i class="fa fa-fw fa-calendar"></i></span>
                            </div>
                            <select class="form-control select2" name="net_terms">
                                <option value="">Net Terms</option>
                                <?php foreach ($net_terms_array as $net_term_value => $net_term_name) { ?>
                                    <option <?php if ($net_term_value == $client_net_terms) {
                                        echo "selected";
                                    } ?> value="<?php echo $net_term_value; ?>">
                                        <?php echo $net_term_name; ?>
                                    </option>
                                <?php } ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Tax ID</label>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text"><i class="fa fa-fw fa-balance-scale"></i></span>
                            </div>
                            <input type="text" class="form-control" name="tax_id_number" maxlength="255"
                                   placeholder="Tax ID Number" value="<?php echo $client_tax_id_number; ?>">
                        </div>
                    </div>

                </div>

            <?php } ?>

            <div class="tab-pane fade" id="pills-client-notes<?php echo $client_id; ?>">

                <div class="form-group">
                    <textarea class="form-control" rows="10" placeholder="Enter some notes" name="notes"><?php echo $client_notes; ?></textarea>
                </div>

            </div>

        </div>
    </div>
    <div class="modal-footer">
        <button type="submit" name="edit_client" class="btn btn-primary text-bold"><i class="fa fa-check me-2"></i>Save</button>
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php
require_once '../../../includes/modal_footer.php';
