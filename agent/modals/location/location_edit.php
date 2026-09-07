<?php

require_once '../../../includes/modal_header.php';

enforceUserPermission('module_support', 2);

$location_id = intval($_GET['id']);

$sql = mysqli_query($mysqli, "SELECT * FROM locations WHERE location_id = $location_id LIMIT 1");

$row = mysqli_fetch_assoc($sql);
$location_name = nullable_htmlentities($row['location_name']);
$location_description = nullable_htmlentities($row['location_description']);
$location_country = nullable_htmlentities($row['location_country']);
$location_address = nullable_htmlentities($row['location_address']);
$location_city = nullable_htmlentities($row['location_city']);
$location_state = nullable_htmlentities($row['location_state']);
$location_zip = nullable_htmlentities($row['location_zip']);
$location_phone_country_code = nullable_htmlentities($row['location_phone_country_code']);
$location_phone = nullable_htmlentities(formatPhoneNumber($row['location_phone'], $location_phone_country_code));
$location_extension = nullable_htmlentities($row['location_phone_extension']);
$location_fax_country_code = nullable_htmlentities($row['location_fax_country_code']);
$location_fax = nullable_htmlentities(formatPhoneNumber($row['location_fax'], $location_fax_country_code));
$location_hours = nullable_htmlentities($row['location_hours']);
$location_photo = nullable_htmlentities($row['location_photo']);
$location_notes = nullable_htmlentities($row['location_notes']);
$location_created_at = nullable_htmlentities($row['location_created_at']);
$location_archived_at = nullable_htmlentities($row['location_archived_at']);
$location_contact_id = intval($row['location_contact_id']);
$client_id = intval($row['location_client_id']);
$location_primary = intval($row['location_primary']);
// Unowned (no primary department) locations skip this - empty($client_id)
// in enforceClientAccess() would otherwise deny access outright.
if ($client_id > 0) {
    enforceClientAccess($client_id);
}

// Tags
$location_tag_id_array = array();
$sql_location_tags = mysqli_query($mysqli, "SELECT * FROM location_tags WHERE location_id = $location_id");
while ($row = mysqli_fetch_assoc($sql_location_tags)) {
    $location_tag_id = intval($row['tag_id']);
    $location_tag_id_array[] = $location_tag_id;
}

// Hours of Operation - parse the joined "Monday: 9-5, Tuesday: 9-5, ..."
// string (captured above as $location_hours, before $row got reused by the
// Tags loop) back into one value per day; unparseable/legacy text is simply
// left as blank fields rather than shown mangled.
$hours_day_labels = ['monday' => 'Monday', 'tuesday' => 'Tuesday', 'wednesday' => 'Wednesday', 'thursday' => 'Thursday', 'friday' => 'Friday', 'saturday' => 'Saturday', 'sunday' => 'Sunday'];
$hours_day_values = array_fill_keys(array_keys($hours_day_labels), '');
foreach (explode(',', $location_hours) as $hours_segment) {
    foreach ($hours_day_labels as $hours_day_key => $hours_day_label) {
        if (preg_match('/^\s*' . preg_quote($hours_day_label, '/') . ':\s*(.*)$/', $hours_segment, $hours_m)) {
            $hours_day_values[$hours_day_key] = trim($hours_m[1]);
        }
    }
}

// Departments checklist (optional many-to-many, separate from the single
// "owning" $client_id above)
$sql_departments_select = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL $access_permission_query ORDER BY client_name ASC");
$location_department_id_array = array();
$sql_location_departments = mysqli_query($mysqli, "SELECT client_id FROM department_sites WHERE location_id = $location_id");
while ($dept_link_row = mysqli_fetch_assoc($sql_location_departments)) {
    $location_department_id_array[] = intval($dept_link_row['client_id']);
}

// Generate the HTML form content using output buffering.
ob_start();
?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fa fa-fw fa-map-marker-alt me-2"></i>Editing location: <strong><?php echo $location_name; ?></strong></h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<form action="post.php" method="post" enctype="multipart/form-data" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="location_id" value="<?php echo $location_id; ?>">

    <div class="modal-body">

        <ul class="nav nav-pills nav-justified mb-3">
            <li class="nav-item">
                <a class="nav-link active" data-bs-toggle="pill" href="#pills-location-details<?php echo $location_id; ?>">Details</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="pill" href="#pills-location-contact<?php echo $location_id; ?>">Contact</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="pill" href="#pills-location-departments<?php echo $location_id; ?>">Departments</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="pill" href="#pills-location-notes<?php echo $location_id; ?>">Notes</a>
            </li>
        </ul>

        <hr>

        <div class="tab-content" <?php if (lookupUserPermission('module_client') <= 1) { echo 'inert'; } ?>>

            <!-- Details: identity + address. Address used to be its own tab, which left
                 this pane with three fields and a large empty gap above the footer. -->
            <div class="tab-pane fade show active" id="pills-location-details<?php echo $location_id; ?>">

                <div class="form-group">
                    <label class="form-label" for="location_name<?php echo $location_id; ?>">Location Name <strong class="text-danger">*</strong></label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-map-marker"></i></span>
                        </div>
                        <input type="text" class="form-control" id="location_name<?php echo $location_id; ?>" name="name" placeholder="Name of location" maxlength="200" value="<?php echo $location_name; ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="location_description<?php echo $location_id; ?>">Description</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-align-left"></i></span>
                        </div>
                        <input type="text" class="form-control" id="location_description<?php echo $location_id; ?>" name="description" placeholder="Short Description" value="<?php echo $location_description; ?>">
                    </div>
                </div>

                <div class="form-group">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="location_primary<?php echo $location_id; ?>" name="location_primary" value="1" <?php if ($location_primary == 1) { echo "checked"; } ?>>
                        <label class="form-check-label" for="location_primary<?php echo $location_id; ?>">Primary location for this department</label>
                    </div>
                    <small class="form-text text-muted">Sorts to the top of the locations list and supplies the department address on quotes and invoices. Only one location per department can be primary.</small>
                </div>

                <div class="form-group">
                    <div class="d-flex align-items-center">
                        <div class="me-3">
                            <?php if (!empty($location_photo)) { ?>
                                <img class="rounded border" style="width:64px; height:64px; object-fit:cover;" src="<?php echo "../uploads/clients/$client_id/$location_photo"; ?>" alt="Current location photo">
                            <?php } else { ?>
                                <span class="d-flex align-items-center justify-content-center rounded border bg-light text-muted" style="width:64px; height:64px;"><i class="fa fa-2x fa-image"></i></span>
                            <?php } ?>
                        </div>
                        <div class="flex-fill">
                            <label class="form-label" for="location_photo<?php echo $location_id; ?>">Photo</label>
                            <!-- .form-control, not the BS4-era .form-control-file: BS5 dropped that
                                 class and nothing in css/ or Tabler defines it, so the input rendered
                                 as raw OS chrome with no border, background or padding. -->
                            <input type="file" class="form-control" id="location_photo<?php echo $location_id; ?>" name="file" accept="image/*">
                            <small class="form-text text-muted"><?php echo empty($location_photo) ? 'Optional. A photo of the building or entrance.' : 'Uploading a new image replaces the current one.'; ?></small>
                        </div>
                    </div>
                </div>

                <hr class="my-3">

                <h6 class="text-uppercase text-muted mb-2" style="font-size:.75rem;letter-spacing:.05em">
                    <i class="fa fa-fw fa-map-marker-alt me-1"></i>Address
                </h6>

                <div class="form-group">
                    <label class="form-label" for="location_address<?php echo $location_id; ?>">Street Address</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-road"></i></span>
                        </div>
                        <input type="text" class="form-control" id="location_address<?php echo $location_id; ?>" name="address" placeholder="Street Address" maxlength="200" value="<?php echo $location_address; ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="location_city<?php echo $location_id; ?>">City</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-city"></i></span>
                        </div>
                        <input type="text" class="form-control" id="location_city<?php echo $location_id; ?>" name="city" placeholder="City" maxlength="200" value="<?php echo $location_city; ?>">
                    </div>
                </div>

                <div class="row g-2">
                    <div class="col-7">
                        <div class="form-group">
                            <label class="form-label" for="location_state<?php echo $location_id; ?>">State / Province</label>
                            <div class="input-group">
                                <div class="input-group-prepend">
                                    <span class="input-group-text"><i class="fa fa-fw fa-flag"></i></span>
                                </div>
                                <input type="text" class="form-control" id="location_state<?php echo $location_id; ?>" name="state" placeholder="State" maxlength="200" value="<?php echo $location_state; ?>">
                            </div>
                        </div>
                    </div>
                    <div class="col-5">
                        <div class="form-group">
                            <label class="form-label" for="location_zip<?php echo $location_id; ?>">Postal Code</label>
                            <div class="input-group">
                                <div class="input-group-prepend">
                                    <span class="input-group-text"><i class="fa fa-fw fa-mail-bulk"></i></span>
                                </div>
                                <input type="text" class="form-control" id="location_zip<?php echo $location_id; ?>" name="zip" placeholder="Zip" maxlength="200" value="<?php echo $location_zip; ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-group mb-0">
                    <label class="form-label" for="location_country<?php echo $location_id; ?>">Country</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-globe-americas"></i></span>
                        </div>
                        <select class="form-control select2" id="location_country<?php echo $location_id; ?>" name="country">
                            <option value="">- Country -</option>
                            <?php foreach($countries_array as $country_name) { ?>
                                <option <?php if ($location_country == $country_name) { echo "selected"; } ?>><?php echo $country_name; ?></option>
                            <?php } ?>
                        </select>
                    </div>
                </div>

            </div>

            <div class="tab-pane fade" id="pills-location-contact<?php echo $location_id; ?>">

                <div class="form-group">
                    <label class="form-label" for="location_contact<?php echo $location_id; ?>">Contact</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-user"></i></span>
                        </div>
                        <select class="form-control select2" id="location_contact<?php echo $location_id; ?>" name="contact">
                            <option value="">- Contact -</option>
                            <?php

                            $sql_contacts = mysqli_query($mysqli, "SELECT * FROM contacts WHERE (contact_archived_at > '$location_created_at' OR contact_archived_at IS NULL) AND contact_client_id = $client_id ORDER BY contact_archived_at ASC, contact_name ASC");
                            while ($row = mysqli_fetch_assoc($sql_contacts)) {
                                $contact_id_select = intval($row['contact_id']);
                                $contact_name_select = nullable_htmlentities($row['contact_name']);
                                $contact_archived_at = nullable_htmlentities($row['contact_archived_at']);
                                if (empty($contact_archived_at)) {
                                    $contact_archived_display = "";
                                } else {
                                    $contact_archived_display = "Archived - ";
                                }

                                ?>
                                <option <?php if ($location_contact_id == $contact_id_select) { echo "selected"; } ?> value="<?php echo $contact_id_select; ?>"><?php echo "$contact_archived_display$contact_name_select"; ?></option>
                            <?php } ?>

                        </select>
                    </div>
                </div>

                <div class="row g-2">
                    <div class="col-8">
                        <div class="form-group">
                            <label class="form-label" for="location_phone<?php echo $location_id; ?>">Phone</label>
                            <div class="input-group">
                                <div class="input-group-prepend">
                                    <span class="input-group-text"><i class="fa fa-fw fa-phone"></i></span>
                                </div>
                                <input type="tel" class="form-control flex-grow-0" style="width:4.5rem;" name="phone_country_code" value="<?php echo $location_phone_country_code; ?>" placeholder="+" maxlength="4" aria-label="Phone country code">
                                <input type="tel" class="form-control" id="location_phone<?php echo $location_id; ?>" name="phone" value="<?php echo $location_phone; ?>" placeholder="Phone Number" maxlength="200">
                            </div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="form-group">
                            <label class="form-label" for="location_extension<?php echo $location_id; ?>">Extension</label>
                            <input type="text" class="form-control" id="location_extension<?php echo $location_id; ?>" name="extension" value="<?php echo $location_extension; ?>" placeholder="ext." maxlength="200">
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="location_fax<?php echo $location_id; ?>">Fax</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-fax"></i></span>
                        </div>
                        <input type="tel" class="form-control flex-grow-0" style="width:4.5rem;" name="fax_country_code" value="<?php echo $location_fax_country_code; ?>" placeholder="+" maxlength="4" aria-label="Fax country code">
                        <input type="tel" class="form-control" id="location_fax<?php echo $location_id; ?>" name="fax" value="<?php echo $location_fax; ?>" placeholder="Fax Number" maxlength="200">
                    </div>
                </div>

                <hr class="my-3">

                <h6 class="text-uppercase text-muted mb-2" style="font-size:.75rem;letter-spacing:.05em">
                    <i class="fa fa-fw fa-clock me-1"></i>Hours of Operation
                </h6>

                <table class="table table-sm table-borderless mb-0">
                    <tbody>
                        <?php foreach ($hours_day_labels as $hours_day_key => $hours_day_label) { ?>
                        <tr>
                            <td class="align-middle" style="width:110px;">
                                <label class="form-label mb-0" for="hours_<?= $hours_day_key ?><?php echo $location_id; ?>"><?= $hours_day_label ?></label>
                            </td>
                            <td><input type="text" class="form-control form-control-sm" id="hours_<?= $hours_day_key ?><?php echo $location_id; ?>" name="hours_<?= $hours_day_key ?>" placeholder="e.g. 9:00 AM - 5:00 PM, or Closed" maxlength="40" value="<?php echo $hours_day_values[$hours_day_key]; ?>"></td>
                        </tr>
                        <?php } ?>
                    </tbody>
                </table>

            </div>

            <div class="tab-pane fade" id="pills-location-departments<?php echo $location_id; ?>">

                <p class="text-secondary small">Optional - link any departments that use this location.</p>

                <div class="form-group border rounded p-2" style="max-height:260px; overflow-y:auto;">
                    <?php if (mysqli_num_rows($sql_departments_select) === 0) { ?>
                        <p class="text-muted small mb-0">No departments yet.</p>
                    <?php } ?>
                    <?php while ($department_row = mysqli_fetch_assoc($sql_departments_select)) {
                        $department_row_id = intval($department_row['client_id']);
                        $department_row_name = nullable_htmlentities($department_row['client_name']);
                    ?>
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" name="departments[]" value="<?= $department_row_id ?>" id="dept<?= $location_id ?>_<?= $department_row_id ?>" <?php if (in_array($department_row_id, $location_department_id_array, true)) { echo 'checked'; } ?>>
                            <label class="form-check-label" for="dept<?= $location_id ?>_<?= $department_row_id ?>"><?= $department_row_name ?></label>
                        </div>
                    <?php } ?>
                </div>

            </div>

            <div class="tab-pane fade" id="pills-location-notes<?php echo $location_id; ?>">

                <div class="form-group">
                    <label class="form-label" for="location_notes<?php echo $location_id; ?>">Notes</label>
                    <textarea class="form-control" id="location_notes<?php echo $location_id; ?>" rows="10" name="notes" placeholder="Notes, eg Parking Info, Building Access etc"><?php echo $location_notes; ?></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label" for="location_tags<?php echo $location_id; ?>">Tags</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-tags"></i></span>
                        </div>
                        <select class="form-control select2" id="location_tags<?php echo $location_id; ?>" name="tags[]" data-placeholder="Add some tags" multiple>
                            <?php

                            $sql_tags_select = mysqli_query($mysqli, "SELECT * FROM tags WHERE tag_type = 2 ORDER BY tag_name ASC");
                            while ($row = mysqli_fetch_assoc($sql_tags_select)) {
                                $tag_id_select = intval($row['tag_id']);
                                $tag_name_select = nullable_htmlentities($row['tag_name']);
                                ?>
                                <option value="<?php echo $tag_id_select; ?>" <?php if (in_array($tag_id_select, $location_tag_id_array)) { echo "selected"; } ?>><?php echo $tag_name_select; ?></option>
                            <?php } ?>

                        </select>
                        <div class="input-group-append">
                            <button class="btn btn-secondary ajax-modal" type="button"
                                data-modal-url="../admin/modals/tag/tag_add.php?type=2">
                                <i class="fas fa-plus"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <p class="text-muted text-end mb-0">Location ID: <?= $location_id ?></p>

            </div>

        </div>

    </div>
    <div class="modal-footer">
        <button type="submit" name="edit_location" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Save</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php

require_once '../../../includes/modal_footer.php';
