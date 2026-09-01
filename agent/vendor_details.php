<?php

// If client_id is in URI then show client Side Bar and client header
if (isset($_GET['client_id'])) {
    require_once "includes/inc_all_client.php";
} else {
    require_once "includes/inc_all.php";
}

// Perms
enforceUserPermission('module_support');

if (isset($_GET['vendor_id'])) {
    $vendor_id = intval($_GET['vendor_id']);

    $sql = mysqli_query($mysqli, "SELECT * FROM vendors WHERE vendor_id = $vendor_id");

    $row = mysqli_fetch_assoc($sql);
    $vendor_id = intval($row['vendor_id']);
    $vendor_name = nullable_htmlentities($row['vendor_name']);
    $vendor_description = nullable_htmlentities($row['vendor_description']);
    if (empty($vendor_description)) {
        $vendor_description_display = "-";
    } else {
        $vendor_description_display = $vendor_description;
    }
    $vendor_account_number = nullable_htmlentities($row['vendor_account_number']);
    $vendor_contact_name = nullable_htmlentities($row['vendor_contact_name']);
    if (empty($vendor_contact_name)) {
        $vendor_contact_name_display = "-";
    } else {
        $vendor_contact_name_display = $vendor_contact_name;
    }
    $vendor_phone = formatPhoneNumber($row['vendor_phone']);
    $vendor_extension = nullable_htmlentities($row['vendor_extension']);
    $vendor_email = nullable_htmlentities($row['vendor_email']);
    $vendor_website = nullable_htmlentities($row['vendor_website']);
    $vendor_hours = nullable_htmlentities($row['vendor_hours']);
    $vendor_sla = nullable_htmlentities($row['vendor_sla']);
    $vendor_code = nullable_htmlentities($row['vendor_code']);
    $vendor_notes = nullable_htmlentities($row['vendor_notes']);
    $vendor_client_id = intval($row['vendor_client_id']);
    $vendor_created_at = nullable_htmlentities($row['vendor_created_at']);

    // Confirm the requesting agent has access to the client that actually
    // owns this vendor record, regardless of any client_id passed in the URL.
    if ($vendor_client_id) {
        enforceClientAccess($vendor_client_id);
    }

    // Vendor Contacts
    $sql_vendor_contacts = mysqli_query($mysqli, "SELECT * FROM vendor_contacts WHERE vendor_contact_vendor_id = $vendor_id AND vendor_contact_archived_at IS NULL ORDER BY vendor_contact_name DESC");
    $vendor_contact_count = mysqli_num_rows($sql_vendor_contacts);

    ?>

    <div class="row">

        <div class="col-md-3">

            <div class="card card-dark">
                <div class="card-body">
                    <button type="button" class="btn btn-default float-end" data-bs-toggle="modal" data-bs-target="#editVendorModal<?php echo $vendor_id; ?>">
                        <i class="fas fa-fw fa-edit"></i>
                    </button>
                    <h3 class="text-bold"><?php echo $vendor_name; ?></h3>
                    <?php if ($contact_title) { ?>
                        <div class="text-secondary"><?php echo $vendor_description; ?></div>
                    <?php } ?>
                    <hr>
                    <?php
                    if ($contact_email) { ?>
                        <div class="mt-2"><i class="fa fa-fw fa-envelope text-secondary me-2"></i><a href='mailto:<?php echo $contact_email; ?>'><?php echo $contact_email; ?></a><button class='btn btn-sm clipboardjs' data-clipboard-text='<?php echo $contact_email; ?>'><i class='far fa-copy text-secondary'></i></button></div>
                    <?php }
                    if ($contact_phone) { ?>
                        <div class="mt-2"><i class="fa fa-fw fa-phone text-secondary me-2"></i><a href="tel:<?php echo "$contact_phone"?>"><?php echo $contact_phone; ?></a></div>
                    <?php }
                    if ($contact_extension) { ?>
                        <div class="ms-4">x<?php echo $contact_extension; ?></div>
                    <?php }
                    if ($contact_mobile) { ?>
                        <div class="mt-l"><i class="fa fa-fw fa-mobile-alt text-secondary me-2"></i><a href="tel:<?php echo $contact_mobile; ?>"><?php echo $contact_mobile; ?></a></div>
                    <?php } ?>
                    <div class="mt-2"><i class="fa fa-fw fa-clock text-secondary me-2"></i><?php echo date('Y-m-d', strtotime($vendor_created_at)); ?></div>

                    <?php require_once "vendor_edit_modal.php";
 ?>

                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header">
                    <h5 class="card-title">Notes</h5>
                </div>
                <textarea class="form-control" rows=6 id="vendorNotes" placeholder="Notes"><?php echo $vendor_notes ?></textarea>
            </div>

        </div>

        <div class="col-md-9">

            <!-- Breadcrumbs-->
            <ol class="breadcrumb d-print-none">
                <?php if (isset($_GET['client_id'])) { ?>
                <li class="breadcrumb-item">
                    <a href="client_overview.php?client_id=<?php echo $client_id; ?>"><?php echo $client_name; ?></a>
                </li>
                <li class="breadcrumb-item">
                    <a href="client_vendors.php?client_id=<?php echo $client_id; ?>">Vendors</a>
                </li>
                <?php } else { ?>
                <li class="breadcrumb-item">
                    <a href="vendors.php">Vendors</a>
                </li>
                <?php } ?>
                <li class="breadcrumb-item active"><i class="fas fa-building me-1"></i><?php echo "$vendor_name";?></li>
            </ol>

            <div class="btn-group mb-3">
                <div class="dropdown dropleft me-2">
                    <button type="button" class="btn btn-primary" data-bs-toggle="dropdown" data-boundary="window"><i class="fas fa-plus me-2"></i>New</button>
                    <div class="dropdown-menu">
                        <a class="dropdown-item text-dark" href="#" data-bs-toggle="modal" data-bs-target="#addVendorContactModal">
                            <i class="fa fa-fw fa-user me-2"></i>New Vendor Contact
                        </a>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item text-dark" href="#" data-bs-toggle="modal" data-bs-target="#createVendorNoteModal<?php echo $vendor_id; ?>">
                            <i class="fa fa-fw fa-sticky-note me-2"></i>New Note
                        </a>
                    </div>
                </div>

                <div class="dropdown dropleft">
                    <button type="button" class="btn btn-outline-primary" data-bs-toggle="dropdown" data-boundary="window"><i class="fas fa-link me-2"></i>Link</button>
                    <div class="dropdown-menu">
                        <a class="dropdown-item text-dark" href="#" data-bs-toggle="modal" data-bs-target="#linkAssetModal">
                            <i class="fa fa-fw fa-desktop me-2"></i>Asset (WIP)
                        </a>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item text-dark" href="#" data-bs-toggle="modal" data-bs-target="#linkSoftwareModal">
                            <i class="fa fa-fw fa-cube me-2"></i>License (WIP)
                        </a>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item text-dark" href="#" data-bs-toggle="modal" data-bs-target="#linkCredentialModal">
                            <i class="fa fa-fw fa-key me-2"></i>Credential (WIP)
                        </a>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item text-dark" href="#" data-bs-toggle="modal" data-bs-target="#linkServiceModal">
                            <i class="fa fa-fw fa-stream me-2"></i>Service (WIP)
                        </a>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item text-dark" href="#" data-bs-toggle="modal" data-bs-target="#linkDocumentModal">
                            <i class="fa fa-fw fa-folder me-2"></i>Document (WIP)
                        </a>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item text-dark" href="#" data-bs-toggle="modal" data-bs-target="#linkFileModal">
                            <i class="fa fa-fw fa-paperclip me-2"></i>File (WIP)
                        </a>


                    </div>
                </div>
            </div>

            <div class="card card-dark <?php if ($vendor_contact_count == 0) { echo "d-none"; } ?>">
                <div class="card-header">
                    <h3 class="card-title"><i class="fa fa-fw fa-users me-2"></i>Contacts</h3>
                </div>
                <div class="card-body">
                    <div class="table-responsive-sm">
                        <table class="table table-striped table-borderless table-hover dataTables" style="width:100%">
                            <thead>
                            <tr>
                                <th>Name</th>
                                <th>Title</th>
                                <th>Department</th>
                                <th>Phone</th>
                                <th>Mobile</th>
                                <th>Email</th>
                                <th class="text-center">Action</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php

                            while ($row = mysqli_fetch_assoc($sql_vendor_contacts)) {
                                $vendor_contact_id = intval($row['vendor_contact_id']);
                                $vendor_contact_name = nullable_htmlentities($row['vendor_contact_name']);
                                $vendor_contact_title = nullable_htmlentities($row['vendor_contact_title']);
                                if (empty($vendor_contact_title)) {
                                    $vendor_contact_title_display = "";
                                } else {
                                    $vendor_contact_title_display = "<small class='text-secondary'>$vendor_contact_title</small>";
                                }
                                $vendor_contact_department = nullable_htmlentities($row['vendor_contact_department']);
                                if (empty($vendor_contact_department)) {
                                    $vendor_contact_department_display = "-";
                                } else {
                                    $vendor_contact_department_display = $vendor_contact_department;
                                }
                                $vendor_contact_extension = nullable_htmlentities($row['vendor_contact_extension']);
                                if (empty($vendor_contact_extension)) {
                                    $vendor_contact_extension_display = "";
                                } else {
                                    $vendor_contact_extension_display = "<small class='text-secondary ms-1'>x$vendor_contact_extension</small>";
                                }
                                $vendor_contact_phone = formatPhoneNumber($row['vendor_contact_phone']);
                                if (empty($vendor_contact_phone)) {
                                    $vendor_contact_phone_display = "";
                                } else {
                                    $vendor_contact_phone_display = "<div><i class='fas fa-fw fa-phone me-2'></i><a href='tel:$vendor_contact_phone'>$vendor_contact_phone$vendor_contact_extension_display</a></div>";
                                }

                                $vendor_contact_mobile = formatPhoneNumber($row['vendor_contact_mobile']);
                                if (empty($vendor_contact_mobile)) {
                                    $vendor_contact_mobile_display = "";
                                } else {
                                    $vendor_contact_mobile_display = "<div class='mt-2'><i class='fas fa-fw fa-mobile-alt me-2'></i><a href='tel:$vendor_contact_mobile'>$vendor_contact_mobile</a></div>";
                                }
                                $vendor_contact_email = nullable_htmlentities($row['vendor_contact_email']);
                                if (empty($vendor_contact_email)) {
                                    $vendor_contact_email_display = "";
                                } else {
                                    $vendor_contact_email_display = "<div class='mt-1'><i class='fas fa-fw fa-envelope me-2'></i><a href='mailto:$vendor_contact_email'>$vendor_contact_email</a><button class='btn btn-sm clipboardjs' type='button' data-clipboard-text='$vendor_contact_email'><i class='far fa-copy text-secondary'></i></button></div>";
                                }
                                $vendor_contact_info_display = "$vendor_contact_phone_display $vendor_contact_mobile_display $vendor_contact_email_display";
                                if (empty($vendor_contact_info_display)) {
                                    $vendor_contact_info_display = "-";
                                }
                                $vendor_contact_notes = nullable_htmlentities($row['vendor_contact_notes']);
                                $vendor_contact_created_at = nullable_htmlentities($row['vendor_contact_created_at']);
                                $vendor_contact_archived_at = nullable_htmlentities($row['vendor_contact_archived_at']);

                                ?>
                                <tr>
                                    <th><?php echo $vendor_contact_name; ?></th>
                                    <td><?php echo $vendor_contact_title_display; ?></td>
                                    <td><?php echo $vendor_contact_department_display; ?></td>
                                    <td><?php echo "$vendor_contact_phone_display $vendor_contact_extension_display"; ?></td>
                                    <td><?php echo $vendor_contact_mobile_display; ?></td>
                                    <td><?php echo $vendor_contact_email_display; ?></td>
                                </tr>

                                <?php

                                require "vendor_contact_edit_modal.php";


                            }

                            ?>

                            </tbody>
                        </table>
                    </div>

                </div>
            </div>
        </div>

<?php } ?>

<?php

require_once "../includes/footer.php";
