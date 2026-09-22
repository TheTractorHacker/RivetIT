<?php

require_once '../../../includes/modal_header.php';

enforceUserPermission('module_client');

$contact_id = intval($_GET['id']);

$sql = mysqli_query($mysqli, "SELECT * FROM contacts
    LEFT JOIN clients ON client_id = contact_client_id
    LEFT JOIN locations ON location_id = contact_location_id
    LEFT JOIN users ON user_id = contact_user_id
    WHERE contact_id = $contact_id
    LIMIT 1
");

$row = mysqli_fetch_assoc($sql);

$client_id = intval($row['client_id']);
$client_name = nullable_htmlentities($row['client_name']);
$contact_name = nullable_htmlentities($row['contact_name']);
$contact_title = nullable_htmlentities($row['contact_title']);
$contact_department = nullable_htmlentities($row['contact_department']);
$contact_phone_country_code = nullable_htmlentities($row['contact_phone_country_code']);
$contact_phone = nullable_htmlentities(formatPhoneNumber($row['contact_phone'], $contact_phone_country_code));
$contact_extension = nullable_htmlentities($row['contact_extension']);
$contact_mobile_country_code = nullable_htmlentities($row['contact_mobile_country_code']);
$contact_mobile = nullable_htmlentities(formatPhoneNumber($row['contact_mobile'], $contact_mobile_country_code));
$contact_email = nullable_htmlentities($row['contact_email']);
$contact_photo = nullable_htmlentities($row['contact_photo']);
$contact_pin = nullable_htmlentities($row['contact_pin']);
$contact_initials = initials($contact_name);
$contact_notes = nullable_htmlentities($row['contact_notes']);
$contact_primary = intval($row['contact_primary']);
$contact_important = intval($row['contact_important']);
$contact_billing = intval($row['contact_billing']);
$contact_technical = intval($row['contact_technical']);
$contact_created_at = nullable_htmlentities($row['contact_created_at']);
$contact_location_id = intval($row['contact_location_id']);

$location_name = nullable_htmlentities($row['location_name']);
$location_country = nullable_htmlentities($row['location_country']);
$location_address = nullable_htmlentities($row['location_address']);
$location_city = nullable_htmlentities($row['location_city']);
$location_state = nullable_htmlentities($row['location_state']);
$location_zip = nullable_htmlentities($row['location_zip']);
$location_phone_country_code = nullable_htmlentities($row['location_phone_country_code']);
$location_phone = nullable_htmlentities(formatPhoneNumber($row['location_phone'], $location_phone_country_code));
$location_phone_display = empty($location_phone) ? "-" : $location_phone;

$auth_method = nullable_htmlentities($row['user_auth_method']);
$contact_client_id = intval($row['contact_client_id']);

// Related Assets Query - 1 to 1 relationship
$sql_related_assets = mysqli_query($mysqli, "SELECT *, status_cat.category_color AS asset_status_color FROM assets
    LEFT JOIN asset_interfaces ON interface_asset_id = asset_id AND interface_primary = 1
    LEFT JOIN asset_tags ON asset_tag_asset_id = asset_id
    LEFT JOIN tags ON tag_id = asset_tag_tag_id
    LEFT JOIN categories AS status_cat ON status_cat.category_name = assets.asset_status AND status_cat.category_type = 'asset_status' AND status_cat.category_archived_at IS NULL
    WHERE asset_contact_id = $contact_id
    GROUP BY asset_id
    ORDER BY asset_name ASC"
);
$asset_count = mysqli_num_rows($sql_related_assets);

// Linked Software Licenses
$sql_linked_software = mysqli_query($mysqli, "SELECT * FROM software_contacts, software
    WHERE software_contacts.contact_id = $contact_id
    AND software_contacts.software_id = software.software_id
    AND software_archived_at IS NULL
    ORDER BY software_name ASC"
);
$software_count = mysqli_num_rows($sql_linked_software);
$linked_software = array();

// Related Credentials Query 1 to 1 relationship
$sql_related_credentials = mysqli_query($mysqli, "
    SELECT
        credentials.credential_id AS credentials_credential_id,
        credentials.*,
        credential_tags.*,
        tags.*
    FROM credentials
    LEFT JOIN credential_tags ON credential_tags.credential_id = credentials.credential_id
    LEFT JOIN tags ON tags.tag_id = credential_tags.tag_id
    WHERE credential_contact_id = $contact_id
    GROUP BY credentials.credential_id
    ORDER BY credential_name ASC
");
$credential_count = mysqli_num_rows($sql_related_credentials);

// Related Tickets Query - 1 to 1 relationship
$sql_related_tickets = mysqli_query($mysqli, "SELECT * FROM tickets
    LEFT JOIN users ON ticket_assigned_to = user_id
    LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id
    WHERE ticket_contact_id = $contact_id
    ORDER BY ticket_id DESC
");
$ticket_count = mysqli_num_rows($sql_related_tickets);

// Related Recurring Tickets Query
$sql_related_recurring_tickets = mysqli_query($mysqli, "SELECT * FROM recurring_tickets
    WHERE recurring_ticket_contact_id = $contact_id
    ORDER BY recurring_ticket_next_run DESC"
);
$recurring_ticket_count = mysqli_num_rows($sql_related_recurring_tickets);

// Tags - many to many relationship
$contact_tag_name_display_array = array();
$contact_tag_id_array = array();
$sql_contact_tags = mysqli_query($mysqli, "SELECT * FROM contact_tags
    LEFT JOIN tags ON contact_tags.tag_id = tags.tag_id
    WHERE contact_id = $contact_id
    ORDER BY tag_name ASC
");
while ($row = mysqli_fetch_assoc($sql_contact_tags)) {

    $contact_tag_id = intval($row['tag_id']);
    $contact_tag_name = nullable_htmlentities($row['tag_name']);
    $contact_tag_color = nullable_htmlentities($row['tag_color']);
    if (empty($contact_tag_color)) {
        $contact_tag_color = "dark";
    }
    $contact_tag_icon = nullable_htmlentities($row['tag_icon']);
    if (empty($contact_tag_icon)) {
        $contact_tag_icon = "tag";
    }

    $contact_tag_id_array[] = $contact_tag_id;
    $contact_tag_name_display_array[] = "<a href='client_contacts.php?client_id=$client_id&q=$contact_tag_name'><span class='badge " . tagTextClass($contact_tag_color) . " p-1 me-1' style='background-color: $contact_tag_color;'><i class='fa fa-fw fa-$contact_tag_icon me-2'></i>$contact_tag_name</span></a>";
}
$contact_tags_display = implode('', $contact_tag_name_display_array);

// Notes - 1 to 1 relationship
$sql_related_notes = mysqli_query($mysqli, "SELECT * FROM contact_notes
    LEFT JOIN users ON contact_note_created_by = user_id
    WHERE contact_note_contact_id = $contact_id
    AND contact_note_archived_at IS NULL
    ORDER BY contact_note_created_at DESC
");
$note_count = mysqli_num_rows($sql_related_notes);

// Linked Services
$sql_linked_services = mysqli_query($mysqli, "SELECT * FROM service_contacts, services
    WHERE service_contacts.contact_id = $contact_id
    AND service_contacts.service_id = services.service_id
    ORDER BY service_name ASC"
);
$services_count = mysqli_num_rows($sql_linked_services);
$linked_services = array();

// Linked Documents
$sql_linked_documents = mysqli_query($mysqli, "SELECT * FROM contact_documents, documents
    LEFT JOIN users ON document_created_by = user_id
    WHERE contact_documents.contact_id = $contact_id
    AND contact_documents.document_id = documents.document_id
    AND document_archived_at IS NULL
    ORDER BY document_name ASC"
);
$document_count = mysqli_num_rows($sql_linked_documents);
$linked_documents = array();

// Linked Files
$sql_linked_files = mysqli_query($mysqli, "SELECT * FROM contact_files, files
    WHERE contact_files.contact_id = $contact_id
    AND contact_files.file_id = files.file_id
    AND file_archived_at IS NULL
    ORDER BY file_name ASC"
);
$file_count = mysqli_num_rows($sql_linked_files);
$linked_files = array();

if (isset($_GET['client_id'])) {
    $client_url = "client_id=$client_id&";
} else {
    $client_url = '';
}

// Pick first available tab (Details tab removed)
$first_tab = null;
if ($asset_count) { $first_tab = "assets"; }
elseif ($credential_count) { $first_tab = "credentials"; }
elseif ($software_count) { $first_tab = "licenses"; }
elseif ($ticket_count) { $first_tab = "tickets"; }
elseif ($recurring_ticket_count) { $first_tab = "recurring"; }
elseif ($document_count) { $first_tab = "documents"; }
elseif ($file_count) { $first_tab = "files"; }
elseif ($note_count) { $first_tab = "notes"; }

enforceClientAccess();

// Generate the HTML form content using output buffering.
ob_start();
?>
<?php
// Header subtitle: title and department, whichever exist.
$contact_subtitle = implode(' · ', array_filter([$contact_title, $client_name]));

// One tab per non-empty relation - built once so the nav and the empty state agree.
$contact_tabs = [];
if ($asset_count) { $contact_tabs[] = ['assets', "pills-contact-assets$contact_id", 'fa-desktop', 'Assets', $asset_count]; }
if (lookupUserPermission('module_credential') && $credential_count) { $contact_tabs[] = ['credentials', "pills-contact-credentials$contact_id", 'fa-key', 'Credentials', $credential_count]; }
if ($software_count) { $contact_tabs[] = ['licenses', "pills-contact-licenses$contact_id", 'fa-cube', 'Licenses', $software_count]; }
if ($ticket_count) { $contact_tabs[] = ['tickets', "pills-contact-tickets$contact_id", 'fa-life-ring', 'Tickets', $ticket_count]; }
if ($recurring_ticket_count) { $contact_tabs[] = ['recurring', "pills-contact-recurring-tickets$contact_id", 'fa-redo-alt', 'Recurring', $recurring_ticket_count]; }
if ($document_count) { $contact_tabs[] = ['documents', "pills-contact-documents$contact_id", 'fa-file-alt', 'Documents', $document_count]; }
if ($file_count) { $contact_tabs[] = ['files', "pills-contact-files$contact_id", 'fa-briefcase', 'Files', $file_count]; }
if ($note_count) { $contact_tabs[] = ['notes', "pills-contact-notes$contact_id", 'fa-sticky-note', 'Notes', $note_count]; }
// Credentials can be hidden by permission even when it's the first non-empty relation.
if ($first_tab && !in_array($first_tab, array_column($contact_tabs, 0), true)) {
    $first_tab = $contact_tabs[0][0] ?? null;
}

$contact_location_line = trim(implode(', ', array_filter([$location_address, trim("$location_city $location_state $location_zip")])));
$has_contact_facts = $contact_email || $contact_phone || $contact_mobile || $location_name || $contact_pin;
?>
<div class="modal-header bg-dark">
    <div class="d-flex align-items-center gap-3 min-w-0">
        <?php if ($contact_photo) { ?>
            <img class="contact-card-avatar" src="<?= "../uploads/clients/$client_id/$contact_photo" ?>" alt="">
        <?php } else { ?>
            <span class="contact-card-avatar contact-card-initials"><?= $contact_initials ?></span>
        <?php } ?>
        <div class="min-w-0">
            <h5 class="modal-title mb-0 text-truncate"><?= $contact_name ?></h5>
            <?php if ($contact_subtitle) { ?>
                <div class="contact-card-subtitle text-truncate"><?= $contact_subtitle ?></div>
            <?php } ?>
        </div>
    </div>
    <button type="button" class="close text-white ms-auto" data-bs-dismiss="modal" aria-label="Close">
        <span>&times;</span>
    </button>
</div>

<div class="modal-body">

    <?php if ($contact_primary || $contact_important || $contact_billing || $contact_technical || $contact_tags_display) { ?>
        <div class="d-flex flex-wrap align-items-center gap-1 mb-3">
            <?php if ($contact_primary) { ?><span class="badge rounded-pill bg-success-subtle text-success-emphasis"><i class="fas fa-fw fa-star me-1"></i>Primary</span><?php } ?>
            <?php if ($contact_important) { ?><span class="badge rounded-pill bg-warning-subtle text-warning-emphasis"><i class="fas fa-fw fa-exclamation me-1"></i>Important</span><?php } ?>
            <?php if ($contact_billing) { ?><span class="badge rounded-pill bg-info-subtle text-info-emphasis"><i class="fas fa-fw fa-file-invoice-dollar me-1"></i>Billing</span><?php } ?>
            <?php if ($contact_technical) { ?><span class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis"><i class="fas fa-fw fa-wrench me-1"></i>Technical</span><?php } ?>
            <?= $contact_tags_display ?>
        </div>
    <?php } ?>

    <!-- Contact details: only the fields that have a value, in a wrapping grid. -->
    <?php if ($has_contact_facts) { ?>
    <div class="contact-card-facts mb-3">
        <?php if ($contact_email) { ?>
            <div class="contact-card-fact">
                <div class="contact-card-label"><i class="fas fa-fw fa-envelope me-1"></i>Email</div>
                <div class="d-flex align-items-center gap-1 min-w-0">
                    <a class="text-truncate" href="mailto:<?= $contact_email ?>"><?= $contact_email ?></a>
                    <button type="button" class="btn btn-link btn-sm p-0 clipboardjs" data-clipboard-text="<?= $contact_email ?>" title="Copy email">
                        <i class="far fa-copy text-secondary"></i>
                    </button>
                </div>
            </div>
        <?php } ?>
        <?php if ($contact_phone) { ?>
            <div class="contact-card-fact">
                <div class="contact-card-label"><i class="fas fa-fw fa-phone-alt me-1"></i>Phone</div>
                <div>
                    <a href="tel:<?= $contact_phone ?>"><?= $contact_phone ?></a>
                    <?php if ($contact_extension) { ?><span class="text-secondary ms-1">ext. <?= $contact_extension ?></span><?php } ?>
                </div>
            </div>
        <?php } ?>
        <?php if ($contact_mobile) { ?>
            <div class="contact-card-fact">
                <div class="contact-card-label"><i class="fas fa-fw fa-mobile-alt me-1"></i>Mobile</div>
                <div><a href="tel:<?= $contact_mobile ?>"><?= $contact_mobile ?></a></div>
            </div>
        <?php } ?>
        <?php if ($location_name) { ?>
            <div class="contact-card-fact">
                <div class="contact-card-label"><i class="fas fa-fw fa-map-marker-alt me-1"></i>Location</div>
                <div class="fw-semibold"><?= $location_name ?></div>
                <?php if ($contact_location_line) { ?><div class="small text-secondary"><?= $contact_location_line ?></div><?php } ?>
            </div>
        <?php } ?>
        <?php if ($contact_pin) { ?>
            <div class="contact-card-fact">
                <div class="contact-card-label"><i class="fas fa-fw fa-key me-1"></i>PIN</div>
                <div class="font-monospace"><?= $contact_pin ?></div>
            </div>
        <?php } ?>
    </div>
    <?php } else { ?>
        <div class="small text-secondary mb-3"><i class="fas fa-fw fa-address-card me-1"></i>No email, phone or location on file.</div>
    <?php } ?>

    <?php if ($contact_tabs) { ?>
        <ul class="nav nav-tabs contact-card-tabs mb-3" role="tablist">
            <?php foreach ($contact_tabs as [$tab_key, $tab_id, $tab_icon, $tab_label, $tab_count]) { ?>
                <li class="nav-item" role="presentation">
                    <a class="nav-link <?= $first_tab === $tab_key ? 'active' : '' ?>" data-bs-toggle="tab" href="#<?= $tab_id ?>" role="tab" aria-controls="<?= $tab_id ?>" aria-selected="<?= $first_tab === $tab_key ? 'true' : 'false' ?>">
                        <i class="fas fa-fw <?= $tab_icon ?> me-1"></i><?= $tab_label ?>
                        <span class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis ms-1"><?= $tab_count ?></span>
                    </a>
                </li>
            <?php } ?>
        </ul>
    <?php } else { ?>
        <div class="text-center text-secondary py-3">
            <i class="fas fa-fw fa-inbox me-1"></i>No assets, tickets or documents linked to this contact yet.
        </div>
    <?php } ?>

            <div class="tab-content">

                <?php if ($asset_count) { ?>
                <div class="tab-pane fade <?= ($first_tab === "assets") ? "show active" : "" ?>" id="pills-contact-assets<?= $contact_id ?>">
                    <?php
                    // Install Date only earns a column when some asset has one.
                    $show_install_date = false;
                    while ($row = mysqli_fetch_assoc($sql_related_assets)) {
                        if (!empty($row['asset_install_date'])) { $show_install_date = true; break; }
                    }
                    mysqli_data_seek($sql_related_assets, 0);
                    ?>

                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0 contact-card-table">
                            <thead>
                            <tr>
                                <th>Name</th>
                                <th>Type</th>
                                <th>Make / Model</th>
                                <th>Serial</th>
                                <?php if ($show_install_date) { ?><th>Installed</th><?php } ?>
                                <th>Status</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php
                            while ($row = mysqli_fetch_assoc($sql_related_assets)) {
                                $asset_id = intval($row['asset_id']);
                                $asset_type = nullable_htmlentities($row['asset_type']);
                                $asset_name = nullable_htmlentities($row['asset_name']);
                                $asset_description = nullable_htmlentities($row['asset_description']);
                                $asset_make = nullable_htmlentities($row['asset_make']);
                                $asset_model = nullable_htmlentities($row['asset_model']);
                                $asset_serial = nullable_htmlentities($row['asset_serial']);
                                $asset_serial_display = empty($asset_serial) ? "-" : $asset_serial;

                                $asset_install_date = nullable_htmlentities($row['asset_install_date']);
                                $asset_install_date_display = empty($asset_install_date) ? "-" : $asset_install_date;

                                $asset_status = nullable_htmlentities($row['asset_status']);
                                // Hex only - it lands in a style="" attribute.
                                $asset_status_color = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) $row['asset_status_color']) ? $row['asset_status_color'] : '';
                                $asset_archived_at = $row['asset_archived_at'];

                                $device_icon = getAssetIcon($asset_type);

                                // Tags
                                $asset_tag_name_display_array = array();
                                $sql_asset_tags = mysqli_query($mysqli, "SELECT * FROM asset_tags LEFT JOIN tags ON asset_tag_tag_id = tag_id WHERE asset_tag_asset_id = $asset_id ORDER BY tag_name ASC");
                                while ($row2 = mysqli_fetch_assoc($sql_asset_tags)) {
                                    $asset_tag_id = intval($row2['tag_id']);
                                    $asset_tag_name = nullable_htmlentities($row2['tag_name']);
                                    $asset_tag_color = nullable_htmlentities($row2['tag_color']);
                                    if (empty($asset_tag_color)) {
                                        $asset_tag_color = "dark";
                                    }
                                    $asset_tag_icon = nullable_htmlentities($row2['tag_icon']);
                                    if (empty($asset_tag_icon)) {
                                        $asset_tag_icon = "tag";
                                    }

                                    $asset_tag_name_display_array[] = "<a href='assets.php?$client_url tags[]=$asset_tag_id'><span class='badge " . tagTextClass($asset_tag_color) . " p-1 me-1' style='background-color: $asset_tag_color;'><i class='fa fa-fw fa-$asset_tag_icon me-2'></i>$asset_tag_name</span></a>";
                                }
                                $asset_tags_display = implode('', $asset_tag_name_display_array);
                                $asset_favorite = intval($row['asset_favorite']);

                                ?>
                                <tr>
                                    <td>
                                        <a href="#" class="ajax-modal fw-semibold text-nowrap"
                                           data-modal-size="lg"
                                           data-modal-url="modals/asset/asset_details.php?id=<?= $asset_id ?>">
                                            <i class="fa fa-fw text-secondary fa-<?= $device_icon ?> me-1"></i><?= $asset_name ?>
                                            <?php if ($asset_favorite) { echo "<i class='fas fa-fw fa-star text-warning' title='Favorite'></i>"; } ?>
                                        </a>
                                        <?php if ($asset_archived_at) { ?><span class="small text-danger ms-1">(Archived)</span><?php } ?>
                                        <?php if ($asset_description) { ?>
                                            <div class="small text-secondary"><?= $asset_description ?></div>
                                        <?php } ?>
                                        <?php if ($asset_tags_display) { ?>
                                            <div class="mt-1"><?= $asset_tags_display ?></div>
                                        <?php } ?>
                                    </td>
                                    <td><?= $asset_type ?></td>
                                    <td>
                                        <?= $asset_make ?>
                                        <?php if ($asset_model) { ?><div class="small text-secondary"><?= $asset_model ?></div><?php } ?>
                                    </td>
                                    <td class="font-monospace small"><?= $asset_serial_display ?></td>
                                    <?php if ($show_install_date) { ?><td class="text-nowrap"><?= $asset_install_date_display ?></td><?php } ?>
                                    <td>
                                        <?php if ($asset_status === '') { ?>
                                            <span class="text-secondary">-</span>
                                        <?php } elseif ($asset_status_color) { ?>
                                            <span class="badge rounded-pill <?= tagTextClass($asset_status_color) ?>" style="background-color: <?= $asset_status_color ?>;"><?= $asset_status ?></span>
                                        <?php } else { ?>
                                            <span class="badge rounded-pill text-bg-secondary"><?= $asset_status ?></span>
                                        <?php } ?>
                                    </td>
                                </tr>
                                <?php
                            }
                            ?>
                            </tbody>
                        </table>
                    </div>

                </div>
                <?php } ?>

                <?php if (lookupUserPermission('module_credential') && ($credential_count)) { ?>
                <div class="tab-pane fade <?= ($first_tab === "credentials") ? "show active" : "" ?>" id="pills-contact-credentials<?= $contact_id ?>">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0 contact-card-table dataTables" style="width:100%">
                            <thead>
                            <tr>
                                <th>Name</th>
                                <th>Description</th>
                                <th>Username</th>
                                <th>Password</th>
                                <th>OTP</th>
                                <th>URI</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php
                            while ($row = mysqli_fetch_assoc($sql_related_credentials)) {
                                $credential_id = intval($row['credentials_credential_id']);
                                $credential_name = nullable_htmlentities($row['credential_name']);
                                $credential_description = nullable_htmlentities($row['credential_description']);

                                $credential_uri = nullable_htmlentities($row['credential_uri']);
                                $credential_uri_display = empty($credential_uri) ? "-" : $credential_uri;

                                $credential_username = nullable_htmlentities(decryptCredentialEntry($row['credential_username']));
                                if (empty($credential_username)) {
                                    $credential_username_display = "-";
                                } else {
                                    $credential_username_display = "$credential_username <button type='button' class='btn btn-sm clipboardjs' data-clipboard-text='$credential_username'><i class='far fa-copy text-secondary'></i></button>";
                                }

                                $credential_password = nullable_htmlentities(decryptCredentialEntry($row['credential_password']));

                                $credential_otp_secret = nullable_htmlentities(decryptOtpSecret($row['credential_otp_secret'] ?? ''));
                                if (empty($credential_otp_secret)) {
                                    $otp_display = "-";
                                } else {
                                    $otp_display = "<span onmouseenter='showOTPViaCredentialID($credential_id)'><i class='far fa-clock'></i> <span id='otp_$credential_id'><i>Hover..</i></span></span>";
                                }
                                ?>
                                <tr>
                                    <td><i class="fa fa-fw fa-key text-secondary me-2"></i><?= $credential_name ?></td>
                                    <td><?= $credential_description ?></td>
                                    <td><?= $credential_username_display ?></td>
                                    <td>
                                        <button class="btn p-0" type="button" data-bs-toggle="popover" data-trigger="focus" data-placement="top" data-content="<?= $credential_password ?>">
                                            <i class="fas fa-ellipsis-h text-secondary"></i><i class="fas fa-ellipsis-h text-secondary"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm clipboardjs" data-clipboard-text="<?= $credential_password ?>">
                                            <i class="far fa-copy text-secondary"></i>
                                        </button>
                                    </td>
                                    <td><?= $otp_display ?></td>
                                    <td><?= $credential_uri_display ?></td>
                                </tr>
                                <?php
                            }
                            ?>
                            </tbody>
                        </table>
                    </div>
                    <script src="js/credential_show_otp_via_id.js"></script>
                </div>
                <?php } ?>

                <?php if ($ticket_count) { ?>
                <div class="tab-pane fade <?= ($first_tab === "tickets") ? "show active" : "" ?>" id="pills-contact-tickets<?= $contact_id ?>">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0 contact-card-table">
                            <thead class="text-dark">
                            <tr>
                                <th>Number</th>
                                <th>Subject</th>
                                <th>Priority</th>
                                <th>Status</th>
                                <th>Assigned</th>
                                <th>Last Response</th>
                                <th>Created</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php
                            while ($row = mysqli_fetch_assoc($sql_related_tickets)) {
                                $ticket_id = intval($row['ticket_id']);
                                $ticket_prefix = nullable_htmlentities($row['ticket_prefix']);
                                $ticket_number = intval($row['ticket_number']);
                                $ticket_subject = nullable_htmlentities($row['ticket_subject']);
                                $ticket_priority = nullable_htmlentities($row['ticket_priority']);
                                $ticket_status = nullable_htmlentities($row['ticket_status']);
                                $ticket_status_name = nullable_htmlentities($row['ticket_status_name']);
                                $ticket_status_color = nullable_htmlentities($row['ticket_status_color']);
                                $ticket_created_at = nullable_htmlentities($row['ticket_created_at']);
                                $ticket_updated_at = nullable_htmlentities($row['ticket_updated_at']);

                                if (empty($ticket_updated_at)) {
                                    if ($ticket_status == "Closed") {
                                        $ticket_updated_at_display = "<span class='text-secondary'>Never</span>";
                                    } else {
                                        $ticket_updated_at_display = "<span class='text-danger'>Never</span>";
                                    }
                                } else {
                                    $ticket_updated_at_display = $ticket_updated_at;
                                }

                                if ($ticket_priority == "High") {
                                    $ticket_priority_display = "<span class='p-2 badge text-bg-danger'>$ticket_priority</span>";
                                } elseif ($ticket_priority == "Medium") {
                                    $ticket_priority_display = "<span class='p-2 badge text-bg-warning'>$ticket_priority</span>";
                                } elseif ($ticket_priority == "Low") {
                                    $ticket_priority_display = "<span class='p-2 badge text-bg-info'>$ticket_priority</span>";
                                } else {
                                    $ticket_priority_display = "-";
                                }

                                $ticket_assigned_to = intval($row['ticket_assigned_to']);
                                if (empty($ticket_assigned_to)) {
                                    if ($ticket_status == "Closed") {
                                        $ticket_assigned_to_display = "<span class='text-secondary'>Not Assigned</span>";
                                    } else {
                                        $ticket_assigned_to_display = "<span class='text-danger'>Not Assigned</span>";
                                    }
                                } else {
                                    $ticket_assigned_to_display = nullable_htmlentities($row['user_name']);
                                }
                                ?>
                                <tr>
                                    <td>
                                        <a href="ticket.php?client_id=<?= $client_id ?>&ticket_id=<?= $ticket_id ?>">
                                            <span class="badge rounded-pill text-bg-secondary"><?= "$ticket_prefix$ticket_number" ?></span>
                                        </a>
                                    </td>
                                    <td><a href="ticket.php?client_id=<?= $client_id ?>&ticket_id=<?= $ticket_id ?>"><?= $ticket_subject ?></a></td>
                                    <td><?= $ticket_priority_display ?></td>
                                    <td><span class="badge rounded-pill <?= tagTextClass($ticket_status_color) ?> p-2" style="background-color: <?= $ticket_status_color ?>"><?= $ticket_status_name ?></span></td>
                                    <td><?= $ticket_assigned_to_display ?></td>
                                    <td><?= $ticket_updated_at_display ?></td>
                                    <td><?= $ticket_created_at ?></td>
                                </tr>
                                <?php
                            }
                            ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php } ?>

                <?php if ($recurring_ticket_count) { ?>
                <div class="tab-pane fade <?= ($first_tab === "recurring") ? "show active" : "" ?>" id="pills-contact-recurring-tickets<?= $contact_id ?>">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0 contact-card-table">
                            <thead class="text-dark">
                            <tr>
                                <th>Subject</th>
                                <th>Priority</th>
                                <th>Frequency</th>
                                <th>Next Run</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php
                            while ($row = mysqli_fetch_assoc($sql_related_recurring_tickets)) {
                                $recurring_ticket_subject = nullable_htmlentities($row['recurring_ticket_subject']);
                                $recurring_ticket_priority = nullable_htmlentities($row['recurring_ticket_priority']);
                                $recurring_ticket_frequency = nullable_htmlentities($row['recurring_ticket_frequency']);
                                $recurring_ticket_next_run = nullable_htmlentities($row['recurring_ticket_next_run']);
                                ?>
                                <tr>
                                    <td class="text-bold"><?= $recurring_ticket_subject ?></td>
                                    <td><?= $recurring_ticket_priority ?></td>
                                    <td><?= $recurring_ticket_frequency ?></td>
                                    <td><?= $recurring_ticket_next_run ?></td>
                                </tr>
                                <?php
                            }
                            ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php } ?>

                <?php if ($software_count) { ?>
                <div class="tab-pane fade <?= ($first_tab === "licenses") ? "show active" : "" ?>" id="pills-contact-licenses<?= $contact_id ?>">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0 contact-card-table">
                            <thead class="text-dark">
                            <tr>
                                <th>Software</th>
                                <th>Type</th>
                                <th>Key</th>
                                <th>Seats</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php
                            while ($row = mysqli_fetch_assoc($sql_linked_software)) {
                                $software_id = intval($row['software_id']);
                                $software_name = nullable_htmlentities($row['software_name']);
                                $software_version = nullable_htmlentities($row['software_version']);
                                $software_type = nullable_htmlentities($row['software_type']);
                                $software_key = nullable_htmlentities($row['software_key']);
                                $software_seats = nullable_htmlentities($row['software_seats']);

                                $seat_count = 0;

                                // Asset Licenses
                                $asset_licenses_sql = mysqli_query($mysqli, "SELECT asset_id FROM software_assets WHERE software_id = $software_id");
                                while ($row2 = mysqli_fetch_assoc($asset_licenses_sql)) {
                                    $seat_count = $seat_count + 1;
                                }

                                // Contact Licenses
                                $contact_licenses_sql = mysqli_query($mysqli, "SELECT contact_id FROM software_contacts WHERE software_id = $software_id");
                                while ($row2 = mysqli_fetch_assoc($contact_licenses_sql)) {
                                    $seat_count = $seat_count + 1;
                                }

                                $linked_software[] = $software_id;
                                ?>
                                <tr>
                                    <td><?= "$software_name $software_version" ?></td>
                                    <td><?= $software_type ?></td>
                                    <td><?= $software_key ?></td>
                                    <td><?= "$seat_count / $software_seats" ?></td>
                                </tr>
                                <?php
                            }
                            ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php } ?>

                <?php if ($document_count) { ?>
                <div class="tab-pane fade <?= ($first_tab === "documents") ? "show active" : "" ?>" id="pills-contact-documents<?= $contact_id ?>">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0 contact-card-table">
                            <thead class="text-dark">
                            <tr>
                                <th>Document Title</th>
                                <th>By</th>
                                <th>Created</th>
                                <th>Updated</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php
                            while ($row = mysqli_fetch_assoc($sql_linked_documents)) {
                                $document_id = intval($row['document_id']);
                                $document_name = nullable_htmlentities($row['document_name']);
                                $document_description = nullable_htmlentities($row['document_description']);
                                $document_created_by = nullable_htmlentities($row['user_name']);
                                $document_created_at = nullable_htmlentities($row['document_created_at']);
                                $document_updated_at = nullable_htmlentities($row['document_updated_at']);

                                $linked_documents[] = $document_id;
                                ?>
                                <tr>
                                    <td>
                                        <a class="ajax-modal" href="#"
                                           data-modal-size="lg"
                                           data-modal-url="modals/document/document_view.php?id=<?= $document_id ?>">
                                            <?= $document_name ?>
                                        </a>
                                        <div class="text-secondary"><?= $document_description ?></div>
                                    </td>
                                    <td><?= $document_created_by ?></td>
                                    <td><?= $document_created_at ?></td>
                                    <td><?= $document_updated_at ?></td>
                                </tr>
                                <?php
                            }
                            ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php } ?>

                <?php if ($file_count) { ?>
                <div class="tab-pane fade <?= ($first_tab === "files") ? "show active" : "" ?>" id="pills-contact-files<?= $contact_id ?>">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0 contact-card-table">
                            <thead class="text-dark">
                            <tr>
                                <th>File Name</th>
                                <th>Type</th>
                                <th>Size</th>
                                <th>Uploaded</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php
                            while ($row = mysqli_fetch_assoc($sql_linked_files)) {
                                $file_name = nullable_htmlentities($row['file_name']);
                                $file_description = nullable_htmlentities($row['file_description']);
                                $file_size = nullable_htmlentities($row['file_size']);
                                $file_size_KB = round($file_size / 1024);
                                $file_reference_name = nullable_htmlentities($row['file_reference_name']);
                                $file_mime_type = nullable_htmlentities($row['file_mime_type']);
                                $file_created_at = nullable_htmlentities($row['file_created_at']);

                                $linked_files[] = intval($row['file_id']);
                                ?>
                                <tr>
                                    <td>
                                        <div><a href="../uploads/clients/<?= $client_id ?>/<?= $file_reference_name ?>" target="_blank"><?= $file_name ?></a></div>
                                        <div class="text-secondary"><?= $file_description ?></div>
                                    </td>
                                    <td><?= $file_mime_type ?></td>
                                    <td><?= $file_size_KB ?> KB</td>
                                    <td><?= $file_created_at ?></td>
                                </tr>
                                <?php
                            }
                            ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php } ?>

                <?php if ($note_count) { ?>
                <div class="tab-pane fade <?= ($first_tab === "notes") ? "show active" : "" ?>" id="pills-contact-notes<?= $contact_id ?>">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0 contact-card-table">
                            <thead class="text-dark">
                            <tr>
                                <th>Type</th>
                                <th>Note</th>
                                <th>By</th>
                                <th>Created</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php
                            while ($row = mysqli_fetch_assoc($sql_related_notes)) {
                                $contact_note_type = nullable_htmlentities($row['contact_note_type']);
                                $contact_note = nullable_htmlentities($row['contact_note']);
                                $note_by = nullable_htmlentities($row['user_name']);
                                $contact_note_created_at = nullable_htmlentities($row['contact_note_created_at']);

                                $note_type_icon = isset($note_types_array[$contact_note_type]) ? $note_types_array[$contact_note_type] : 'fa-fw fa-sticky-note';
                                ?>
                                <tr>
                                    <td><i class="fa fa-fw <?= $note_type_icon ?> me-2"></i><?= $contact_note_type ?></td>
                                    <td><?= $contact_note ?></td>
                                    <td><?= $note_by ?></td>
                                    <td><?= $contact_note_created_at ?></td>
                                </tr>
                                <?php
                            }
                            ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php } ?>

            </div>

</div>

<div class="modal-footer">
    <a href="#" class="btn btn-secondary ajax-modal"
       data-modal-url="modals/contact/contact_edit.php?id=<?= $contact_id ?>">
        <i class="fas fa-edit me-2"></i>Edit
    </a>
    <a href="contact_details.php?client_id=<?= $client_id ?>&contact_id=<?= $contact_id ?>" class="btn btn-dark">
        <i class="fas fa-external-link-alt me-2"></i>Open Full Contact
    </a>
</div>

<?php
require_once '../../../includes/modal_footer.php';
