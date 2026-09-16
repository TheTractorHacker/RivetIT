<?php

require_once '../../../includes/modal_header.php';

$client_id = intval($_GET['client_id'] ?? 0);

// Departments checklist (optional - a location no longer requires a single
// owning department at creation time; use this to link any that apply)
$sql_departments_select = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL $access_permission_query ORDER BY client_name ASC");

ob_start();

?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fa fa-fw fa-map-marker-alt me-2"></i>Creating location</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<form action="post.php" method="post" enctype="multipart/form-data" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

    <div class="modal-body">

        <ul class="nav nav-pills nav-justified mb-3">
            <li class="nav-item">
                <a class="nav-link active" data-bs-toggle="pill" href="#pills-location-add-details">Details</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="pill" href="#pills-location-add-contact">Contact</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="pill" href="#pills-location-add-departments">Departments</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="pill" href="#pills-location-add-notes">Notes</a>
            </li>

        </ul>

        <hr>

        <div class="tab-content">

            <!-- Details: identity + address, laid out to match location_edit.php field
                 for field - add and edit disagreeing about the shape of the same form
                 is its own defect. -->
            <div class="tab-pane fade show active" id="pills-location-add-details">

                <?php if ($client_id) { ?>
                    <input type="hidden" name="client_id" value="<?php echo $client_id; ?>">
                <?php } ?>

                <div class="form-group">
                    <label class="form-label" for="location_add_name">Location Name <strong class="text-danger">*</strong></label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-map-marker"></i></span>
                        </div>
                        <input type="text" class="form-control" id="location_add_name" name="name" placeholder="Name of location" maxlength="200" required autofocus>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="location_add_description">Description</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-align-left"></i></span>
                        </div>
                        <input type="text" class="form-control" id="location_add_description" name="description" placeholder="Short Description">
                    </div>
                </div>

                <div class="form-group">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="location_add_primary" name="location_primary" value="1">
                        <label class="form-check-label" for="location_add_primary">Primary location for this department</label>
                    </div>
                    <small class="form-text text-muted">Sorts to the top of the locations list and supplies the department address on quotes and invoices. Only one location per department can be primary.</small>
                </div>

                <div class="form-group">
                    <div class="d-flex align-items-center">
                        <div class="me-3">
                            <span class="d-flex align-items-center justify-content-center rounded border bg-light text-muted" style="width:64px; height:64px;"><i class="fa fa-2x fa-image"></i></span>
                        </div>
                        <div class="flex-fill">
                            <label class="form-label" for="location_add_photo">Photo</label>
                            <!-- .form-control, not the BS4-era .form-control-file: BS5 dropped that
                                 class and nothing in css/ or Tabler defines it, so the input rendered
                                 as raw OS chrome with no border, background or padding. -->
                            <input type="file" class="form-control" id="location_add_photo" name="file" accept="image/*">
                            <small class="form-text text-muted">Optional. A photo of the building or entrance.</small>
                        </div>
                    </div>
                </div>

                <hr class="my-3">

                <h6 class="text-uppercase text-muted mb-2" style="font-size:.75rem;letter-spacing:.05em">
                    <i class="fa fa-fw fa-map-marker-alt me-1"></i>Address
                </h6>

                <div class="form-group">
                    <label class="form-label" for="location_add_address">Street Address</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-road"></i></span>
                        </div>
                        <input type="text" class="form-control" id="location_add_address" name="address" placeholder="Street Address" maxlength="200">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="location_add_city">City</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-city"></i></span>
                        </div>
                        <input type="text" class="form-control" id="location_add_city" name="city" placeholder="City" maxlength="200">
                    </div>
                </div>

                <div class="row g-2">
                    <div class="col-7">
                        <div class="form-group">
                            <label class="form-label" for="location_add_state">State / Province</label>
                            <div class="input-group">
                                <div class="input-group-prepend">
                                    <span class="input-group-text"><i class="fa fa-fw fa-flag"></i></span>
                                </div>
                                <input type="text" class="form-control" id="location_add_state" name="state" placeholder="State" maxlength="200">
                            </div>
                        </div>
                    </div>
                    <div class="col-5">
                        <div class="form-group">
                            <label class="form-label" for="location_add_zip">Postal Code</label>
                            <div class="input-group">
                                <div class="input-group-prepend">
                                    <span class="input-group-text"><i class="fa fa-fw fa-mail-bulk"></i></span>
                                </div>
                                <input type="text" class="form-control" id="location_add_zip" name="zip" placeholder="Zip" maxlength="200">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-group mb-0">
                    <label class="form-label" for="location_add_country">Country</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-globe-americas"></i></span>
                        </div>
                        <select class="form-control select2" id="location_add_country" name="country">
                            <option value="">Country</option>
                            <?php foreach($countries_array as $country_name) { ?>
                                <option <?php if ($session_company_country == $country_name) { echo "selected"; } ?> ><?php echo $country_name; ?></option>
                            <?php } ?>
                        </select>
                    </div>
                </div>

            </div>

            <div class="tab-pane fade" id="pills-location-add-contact">
                <?php if ($client_id) { ?>
                <div class="form-group">
                    <label class="form-label" for="location_add_contact">Contact</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-user"></i></span>
                        </div>
                        <select class="form-control select2" id="location_add_contact" name="contact">
                            <option value="">Contact</option>
                            <?php

                            $sql_contacts = mysqli_query($mysqli, "SELECT * FROM contacts WHERE contact_archived_at IS NULL AND contact_client_id = $client_id ORDER BY contact_name ASC");
                            while ($row = mysqli_fetch_assoc($sql_contacts)) {
                                $contact_id = $row['contact_id'];
                                $contact_name = nullable_htmlentities($row['contact_name']);
                                ?>
                                <option value="<?php echo $contact_id; ?>"><?php echo $contact_name; ?></option>
                            <?php } ?>

                        </select>
                    </div>
                </div>
                <?php } ?>

                <div class="row g-2">
                    <div class="col-8">
                        <div class="form-group">
                            <label class="form-label" for="location_add_phone">Phone</label>
                            <div class="input-group">
                                <div class="input-group-prepend">
                                    <span class="input-group-text"><i class="fa fa-fw fa-phone"></i></span>
                                </div>
                                <input type="tel" class="form-control phone-country-code" name="phone_country_code" value="<?php echo $config_phone_default_country_code ?? '1'; ?>" placeholder="+" maxlength="4" aria-label="Phone country code">
                                <input type="tel" class="form-control phone-number-format" id="location_add_phone" name="phone" placeholder="Phone Number" maxlength="200">
                            </div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="form-group">
                            <label class="form-label" for="location_add_extension">Extension</label>
                            <input type="text" class="form-control" id="location_add_extension" name="extension" placeholder="ext." maxlength="200">
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="location_add_fax">Fax</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-fax"></i></span>
                        </div>
                        <input type="tel" class="form-control phone-country-code" name="fax_country_code" value="<?php echo $config_phone_default_country_code ?? '1'; ?>" placeholder="+" maxlength="4" aria-label="Fax country code">
                        <input type="tel" class="form-control phone-number-format" id="location_add_fax" name="fax" placeholder="Fax Number" maxlength="200">
                    </div>
                </div>

                <hr class="my-3">

                <h6 class="text-uppercase text-muted mb-2" style="font-size:.75rem;letter-spacing:.05em">
                    <i class="fa fa-fw fa-clock me-1"></i>Hours of Operation
                </h6>

                <table class="table table-sm table-borderless mb-0">
                    <tbody>
                        <?php foreach (['monday' => 'Monday', 'tuesday' => 'Tuesday', 'wednesday' => 'Wednesday', 'thursday' => 'Thursday', 'friday' => 'Friday', 'saturday' => 'Saturday', 'sunday' => 'Sunday'] as $hours_day_key => $hours_day_label) { ?>
                        <tr>
                            <td class="align-middle" style="width:110px;">
                                <label class="form-label mb-0" for="location_add_hours_<?= $hours_day_key ?>"><?= $hours_day_label ?></label>
                            </td>
                            <td><input type="text" class="form-control form-control-sm" id="location_add_hours_<?= $hours_day_key ?>" name="hours_<?= $hours_day_key ?>" placeholder="e.g. 9:00 AM - 5:00 PM, or Closed" maxlength="40"></td>
                        </tr>
                        <?php } ?>
                    </tbody>
                </table>

            </div>

            <div class="tab-pane fade" id="pills-location-add-departments">

                <p class="text-secondary small">Optional - link any departments that use this location. Not required to create the location.</p>

                <div class="form-group border rounded p-2" style="max-height:260px; overflow-y:auto;">
                    <?php if (mysqli_num_rows($sql_departments_select) === 0) { ?>
                        <p class="text-muted small mb-0">No departments yet.</p>
                    <?php } ?>
                    <?php while ($department_row = mysqli_fetch_assoc($sql_departments_select)) {
                        $department_row_id = intval($department_row['client_id']);
                        $department_row_name = nullable_htmlentities($department_row['client_name']);
                    ?>
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" name="departments[]" value="<?= $department_row_id ?>" id="dept_<?= $department_row_id ?>" <?php if ($client_id === $department_row_id) { echo 'checked'; } ?>>
                            <label class="form-check-label" for="dept_<?= $department_row_id ?>"><?= $department_row_name ?></label>
                        </div>
                    <?php } ?>
                </div>

            </div>

            <div class="tab-pane fade" id="pills-location-add-notes">

                <div class="form-group">
                    <label class="form-label" for="location_add_notes">Notes</label>
                    <textarea class="form-control" id="location_add_notes" rows="10" name="notes" placeholder="Notes, eg Parking Info, Building Access etc"></textarea>
                </div>

                <div class="form-group mb-0">
                    <label class="form-label" for="location_add_tags">Tags</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fa fa-fw fa-tags"></i></span>
                        </div>
                        <select class="form-control select2" id="location_add_tags" name="tags[]" data-placeholder="Add some tags" multiple>
                            <?php

                            $sql_tags_select = mysqli_query($mysqli, "SELECT * FROM tags WHERE tag_type = 2 ORDER BY tag_name ASC");
                            while ($row = mysqli_fetch_assoc($sql_tags_select)) {
                                $tag_id_select = intval($row['tag_id']);
                                $tag_name_select = nullable_htmlentities($row['tag_name']);
                                ?>
                                <option value="<?php echo $tag_id_select; ?>"><?php echo $tag_name_select; ?></option>
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

            </div>

        </div>

    </div>
    <div class="modal-footer">
        <button type="submit" name="add_location" class="btn btn-primary text-bold"><i class="fa fa-check me-2"></i>Create</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php

require_once '../../../includes/modal_footer.php';
