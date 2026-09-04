<?php

// Default Column Sortby Filter
$sort = "location_name";
$order = "ASC";

// If client_id is in URI then show client Side Bar and client header
if (isset($_GET['client_id'])) {
    require_once "includes/inc_all_client.php";
    // Locations are an independent entity, linked to departments via
    // department_sites (many-to-many) rather than the legacy
    // location_client_id single-owner column - a department's own
    // locations page must show every location linked to it that way.
    $client_query = "AND EXISTS (SELECT 1 FROM department_sites ds WHERE ds.location_id = locations.location_id AND ds.client_id = $client_id)";
    $client_url = "client_id=$client_id&";
    // Overide Filter Header Archived
    if (isset($_GET['archived']) && $_GET['archived'] == 1) {
        $archived = 1;
        $archive_query = "location_archived_at IS NOT NULL";
    } else {
        $archived = 0;
        $archive_query = "location_archived_at IS NULL";
    }
} else {
    require_once "includes/inc_all.php";
    $client_query = '';
    $client_url = '';
}

// Perms
enforceUserPermission('module_support');

if (!$client_url) {
    // Client Filter
    if (isset($_GET['client']) & !empty($_GET['client'])) {
        $filter_client_id = intval($_GET['client']);
        $client_query = "AND EXISTS (SELECT 1 FROM department_sites ds WHERE ds.location_id = locations.location_id AND ds.client_id = $filter_client_id)";
        $client = $filter_client_id;
    } else {
        // Default - any
        $client_query = '';
        $client = '';
    }
    // Overide Filter Header Archived
    if (isset($_GET['archived']) && $_GET['archived'] == 1) {
        $archived = 1;
        $archive_query = "location_archived_at IS NOT NULL";
    } else {
        $archived = 0;
        $archive_query = "location_archived_at IS NULL";
    }
}

// Tags Filter
if (isset($_GET['tags']) && is_array($_GET['tags']) && !empty($_GET['tags'])) {
    // Sanitize each element of the tags array
    $sanitizedTags = array_map('intval', $_GET['tags']);
    // Convert the sanitized tags into a comma-separated string
    $tag_filter = implode(",", $sanitizedTags);
    $tag_query = "AND tags.tag_id IN ($tag_filter)";
} else {
    $tag_filter = 0;
    $tag_query = '';
}

$sql = mysqli_query(
    $mysqli,
    "SELECT SQL_CALC_FOUND_ROWS locations.*
    FROM locations
    LEFT JOIN location_tags ON location_tags.location_id = locations.location_id
    LEFT JOIN tags ON tags.tag_id = location_tags.tag_id
    LEFT JOIN department_sites ON department_sites.location_id = locations.location_id
    LEFT JOIN clients ON clients.client_id = department_sites.client_id AND clients.client_archived_at IS NULL
    WHERE $archive_query
    $tag_query
    AND (location_name LIKE '%$q%' OR location_description LIKE '%$q%' OR location_address LIKE '%$q%' OR location_city LIKE '%$q%' OR location_state LIKE '%$q%' OR location_zip LIKE '%$q%' OR location_country LIKE '%$q%' OR location_phone LIKE '%$phone_query%' OR tag_name LIKE '%$q%' OR client_name LIKE '%$q%')
    $access_permission_query
    $client_query
    GROUP BY location_id
    ORDER BY location_primary DESC, $sort $order LIMIT $record_from, $record_to"
);

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

?>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fa fa-fw fa-map-marker-alt me-2"></i>Locations</h3>
        <div class="card-tools">
            <div class="btn-group">
                <button type="button" class="btn btn-primary ajax-modal" data-modal-url="modals/location/location_add.php?<?= $client_url ?>">
                    <i class="fas fa-plus me-2"></i>New Location
                </button>
                <button type="button" class="btn btn-primary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown"></button>
                <div class="dropdown-menu">
                    <a class="dropdown-item text-dark ajax-modal" href="#"
                        data-modal-url="modals/location/location_import.php?<?= $client_url ?>">
                        <i class="fa fa-fw fa-upload me-2"></i>Import
                    </a>
                    <?php if ($num_rows[0] > 0) { ?>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item text-dark ajax-modal" href="#"
                            data-modal-url="modals/location/location_export.php?<?= $client_url ?>">
                            <i class="fa fa-fw fa-download me-2"></i>Export
                        </a>
                    <?php } ?>
                </div>
            </div>
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
                        <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search Locations">
                        <div class="input-group-append">
                            <button class="btn btn-dark"><i class="fa fa-search"></i></button>
                        </div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="input-group mb-3 mb-md-0">
                        <select class="form-control select2 auto-submit-select" name="tags[]" data-placeholder="- Select Tags -" multiple>
                            <?php
                            $sql_tags_filter = mysqli_query($mysqli, "
                                SELECT tags.tag_id, tags.tag_name, tag_type
                                FROM tags
                                LEFT JOIN location_tags ON location_tags.tag_id = tags.tag_id
                                LEFT JOIN locations ON location_tags.location_id = locations.location_id
                                WHERE tag_type = 2
                                $client_query OR tags.tag_id IN ($tag_filter)
                                GROUP BY tags.tag_id
                                HAVING COUNT(location_tags.location_id) > 0 OR tags.tag_id IN ($tag_filter)
                            ");
                            while ($row = mysqli_fetch_assoc($sql_tags_filter)) {
                                $tag_id = intval($row['tag_id']);
                                $tag_name = nullable_htmlentities($row['tag_name']); ?>

                                <option value="<?php echo $tag_id ?>" <?php if (isset($_GET['tags']) && is_array($_GET['tags']) && in_array($tag_id, $_GET['tags'])) { echo 'selected'; } ?>> <?php echo $tag_name ?> </option>

                            <?php } ?>
                        </select>
                    </div>
                </div>
                <?php if ($client_url) { ?>
                <div class="col-md-2"></div>
                <?php } else { ?>
                <div class="col-md-2">
                    <div class="input-group mb-3 mb-md-0">
                        <select class="form-control select2 auto-submit-select" name="client">
                            <option value="" <?php if ($client == "") { echo "selected"; } ?>>- All Departments -</option>

                            <?php
                            $sql_clients_filter = mysqli_query($mysqli, "
                                SELECT DISTINCT client_id, client_name
                                FROM clients
                                JOIN department_sites ON department_sites.client_id = clients.client_id
                                JOIN locations ON locations.location_id = department_sites.location_id
                                WHERE $archive_query AND clients.client_archived_at IS NULL
                                $access_permission_query
                                ORDER BY client_name ASC
                            ");
                            while ($row = mysqli_fetch_assoc($sql_clients_filter)) {
                                $client_id = intval($row['client_id']);
                                $client_name = nullable_htmlentities($row['client_name']);
                            ?>
                                <option <?php if ($client == $client_id) { echo "selected"; } ?> value="<?php echo $client_id; ?>"><?php echo $client_name; ?></option>
                            <?php
                            }
                            ?>

                        </select>
                    </div>
                </div>
                <?php } ?>

                <div class="col-md-3">
                    <div class="btn-group float-end">
                        <a href="?<?php echo $client_url; ?>archived=<?php if($archived == 1){ echo 0; } else { echo 1; } ?>"
                            class="btn btn-<?php if($archived == 1){ echo "primary"; } else { echo "default"; } ?>">
                            <i class="fa fa-fw fa-archive me-2"></i>Archived
                        </a>
                        <div class="dropdown ms-2" id="bulkActionButton" hidden>
                            <button class="btn btn-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                <i class="fas fa-fw fa-layer-group me-2"></i>Bulk Action (<span id="selectedCount">0</span>)
                            </button>
                            <div class="dropdown-menu">
                                <a class="dropdown-item ajax-modal" href="#"
                                    data-modal-url="modals/location/location_bulk_assign_tags.php"
                                    data-bulk="true">
                                    <i class="fas fa-fw fa-tags me-2"></i>Assign Tags
                                </a>
                                <?php if ($archived) { ?>
                                <div class="dropdown-divider"></div>
                                <button class="dropdown-item text-info"
                                    type="submit" form="bulkActions" name="bulk_restore_locations">
                                    <i class="fas fa-fw fa-redo me-2"></i>Restore
                                </button>
                                <div class="dropdown-divider"></div>
                                <button class="dropdown-item text-danger text-bold"
                                    type="submit" form="bulkActions" name="bulk_delete_locations">
                                    <i class="fas fa-fw fa-trash me-2"></i>Delete
                                </button>
                                <?php } else { ?>
                                <div class="dropdown-divider"></div>
                                <button class="dropdown-item text-danger confirm-link"
                                    type="submit" form="bulkActions" name="bulk_archive_locations">
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
        <form id="bulkActions" action="post.php" method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">

            <div class="table-responsive">
                <table class="table table-striped table-borderless table-hover">
                    <thead class="<?php if ($num_rows[0] == 0) { echo "d-none"; } ?>">
                    <tr>
                        <td class="bg-light checkbox-column">
                            <div class="form-check">
                                <input class="form-check-input" id="selectAllCheckbox" type="checkbox">
                            </div>
                        </td>
                        <th>
                            <a class="text-secondary" href="?<?php echo $url_query_strings_sort; ?>&sort=location_name&order=<?php echo $disp; ?>">
                                Name <?php if ($sort == 'location_name') { echo $order_icon; } ?>
                            </a>
                        </th>
                        <th>
                            <a class="text-secondary" href="?<?php echo $url_query_strings_sort; ?>&sort=location_address&order=<?php echo $disp; ?>">
                                Address <?php if ($sort == 'location_address') { echo $order_icon; } ?>
                            </a>
                        </th>
                        <th>
                            <a class="text-secondary" href="?<?php echo $url_query_strings_sort; ?>&sort=location_phone&order=<?php echo $disp; ?>">
                                Phone <?php if ($sort == 'location_phone') { echo $order_icon; } ?>
                            </a>
                        </th>
                        <th>
                            <a class="text-secondary" href="?<?php echo $url_query_strings_sort; ?>&sort=location_hours&order=<?php echo $disp; ?>">
                                Hours <?php if ($sort == 'location_hours') { echo $order_icon; } ?>
                            </a>
                        </th>
                        <?php if (!$client_url) { ?>
                        <th>
                            <a class="text-secondary" href="?<?php echo $url_query_strings_sort; ?>&sort=client_name&order=<?php echo $disp; ?>">
                                Department <?php if ($sort == 'client_name') { echo $order_icon; } ?>
                            </a>
                        </th>
                        <?php } ?>
                        <th class="text-center">Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php

                    $map_locations = [];

                    while ($row = mysqli_fetch_assoc($sql)) {
                        $location_id = intval($row['location_id']);
                        $location_name = nullable_htmlentities($row['location_name']);
                        $location_description = nullable_htmlentities($row['location_description']);
                        $location_country = nullable_htmlentities($row['location_country']);
                        $location_address = nullable_htmlentities($row['location_address']);
                        $location_city = nullable_htmlentities($row['location_city']);
                        $location_state = nullable_htmlentities($row['location_state']);
                        $location_zip = nullable_htmlentities($row['location_zip']);
                        $location_phone_country_code = nullable_htmlentities($row['location_phone_country_code']);
                        $location_phone = nullable_htmlentities(formatPhoneNumber($row['location_phone'], $location_phone_country_code));
                        if (empty($location_phone)) {
                            $location_phone_display = "-";
                        } else {
                            $location_phone_display = $location_phone;
                        }
                        $location_fax_country_code = nullable_htmlentities($row['location_fax_country_code']);
                        $location_fax = nullable_htmlentities(formatPhoneNumber($row['location_fax'], $location_fax_country_code));
                        if ($location_fax) {
                            $location_fax_display = "<div class='text-secondary'>Fax: $location_fax</div>";
                        } else {
                            $location_fax_display = '';
                        }
                        $location_hours_display = formatLocationHoursDisplay($row['location_hours']);
                        if ($location_hours_display === '') {
                            $location_hours_display = "-";
                        }
                        $location_photo = nullable_htmlentities($row['location_photo']);
                        $location_notes = nullable_htmlentities($row['location_notes']);
                        $location_created_at = nullable_htmlentities($row['location_created_at']);
                        $location_archived_at = nullable_htmlentities($row['location_archived_at']);
                        $location_contact_id = intval($row['location_contact_id']);
                        $location_primary = intval($row['location_primary']);
                        if ( $location_primary == 1 ) {
                            $location_primary_display = "<small class='text-success'><i class='fa fa-fw fa-check'></i> Primary</small>";
                        } else {
                            $location_primary_display = "";
                        }

                        // Collect coordinates for the map (geocoded once at save
                        // time in agent/post/location.php - see geocodeAddress()).
                        if ($row['location_latitude'] !== null && $row['location_longitude'] !== null) {
                            $map_locations[] = [
                                'id' => $location_id,
                                'name' => $location_name,
                                'lat' => (float) $row['location_latitude'],
                                'lng' => (float) $row['location_longitude'],
                                'address' => trim("$location_address, $location_city $location_state $location_zip"),
                            ];
                        }

                        // Tags

                        $location_tag_name_display_array = array();
                        $location_tag_id_array = array();
                        $sql_location_tags = mysqli_query($mysqli, "SELECT * FROM location_tags LEFT JOIN tags ON location_tags.tag_id = tags.tag_id WHERE location_tags.location_id = $location_id ORDER BY tag_name ASC");
                        while ($row = mysqli_fetch_assoc($sql_location_tags)) {

                            $location_tag_id = intval($row['tag_id']);
                            $location_tag_name = nullable_htmlentities($row['tag_name']);
                            $location_tag_color = nullable_htmlentities($row['tag_color']);
                            if (empty($location_tag_color)) {
                                $location_tag_color = "dark";
                            }
                            $location_tag_icon = nullable_htmlentities($row['tag_icon']);
                            if (empty($location_tag_icon)) {
                                $location_tag_icon = "tag";
                            }

                            $location_tag_id_array[] = $location_tag_id;
                            $location_tag_name_display_array[] = "<a href='locations.php?$client_url tags[]=$location_tag_id'><span class='badge " . tagTextClass($location_tag_color) . " p-1 me-1' style='background-color: $location_tag_color;'><i class='fa fa-fw fa-$location_tag_icon me-2'></i>$location_tag_name</span></a>";
                        }
                        $location_tags_display = implode('', $location_tag_name_display_array);

                        // Departments (locations are independent entities, linked
                        // to departments many-to-many via department_sites)
                        $location_department_display_array = array();
                        $sql_location_departments = mysqli_query($mysqli, "SELECT clients.client_id, clients.client_name FROM department_sites LEFT JOIN clients ON clients.client_id = department_sites.client_id WHERE department_sites.location_id = $location_id AND clients.client_archived_at IS NULL ORDER BY client_name ASC");
                        while ($row = mysqli_fetch_assoc($sql_location_departments)) {
                            $location_department_id = intval($row['client_id']);
                            $location_department_name = nullable_htmlentities($row['client_name']);
                            $location_department_display_array[] = "<a href='locations.php?client_id=$location_department_id' class='badge text-bg-secondary me-1 mb-1'>$location_department_name</a>";
                        }
                        $location_departments_display = implode('', $location_department_display_array);

                        ?>
                        <tr>
                            <td class="bg-light checkbox-column">
                                <div class="form-check">
                                    <input class="form-check-input bulk-select" type="checkbox" name="location_ids[]" value="<?php echo $location_id ?>">
                                </div>
                            </td>
                            <td>
                                <a class="text-dark ajax-modal" href="#" data-modal-url="modals/location/location_edit.php?id=<?= $location_id ?>">
                                    <div class="media">
                                        <i class="fa fa-fw fa-2x fa-map-marker-alt me-2"></i>
                                        <div class="media-body">
                                            <div <?php if($location_primary) { echo "class='text-bold'"; } ?>><?php echo $location_name; ?></div>
                                            <div><small class="text-secondary"><?php echo $location_description; ?></small></div>
                                            <div><?php echo $location_primary_display; ?></div>
                                             <?php
                                            if (!empty($location_tags_display)) { ?>
                                                <div class="mt-1">
                                                    <?php echo $location_tags_display; ?>
                                                </div>
                                            <?php } ?>
                                        </div>
                                    </div>
                                </a>
                            </td>
                            <td>
                                <?php if ($location_address || $location_city || $location_state || $location_zip) { ?>
                                <a href="//maps.<?php echo $session_map_source; ?>.com?q=<?php echo "$location_address $location_zip"; ?>" target="_blank">
                                    <?php if ($location_address) { ?><div><?php echo $location_address; ?></div><?php } ?>
                                    <?php $location_csz = trim(implode(' ', array_filter([$location_city ? "$location_city," : '', $location_state, $location_zip]))); ?>
                                    <?php if ($location_csz) { ?><div class="text-secondary"><?php echo $location_csz; ?></div><?php } ?>
                                </a>
                                <?php } else { ?>
                                -
                                <?php } ?>
                            </td>
                            <td>
                                <?php echo $location_phone_display; ?>
                                <?php echo $location_fax_display; ?>
                            </td>
                            <td><?php echo $location_hours_display; ?></td>
                            <?php if (!$client_url) { ?>
                            <td><?php echo $location_departments_display ?: '-'; ?></td>
                            <?php } ?>
                            <td>
                                <div class="dropdown dropleft text-center">
                                    <button class="btn btn-secondary btn-sm" type="button" data-bs-toggle="dropdown" data-boundary="window">
                                        <i class="fas fa-ellipsis-h"></i>
                                    </button>
                                    <div class="dropdown-menu">
                                        <a class="dropdown-item ajax-modal" href="#" data-modal-url="modals/location/location_edit.php?id=<?= $location_id ?>">
                                            <i class="fas fa-fw fa-edit me-2"></i>Edit
                                        </a>
                                        <?php if ($session_user_role == 3 && $location_primary == 0) { ?>
                                            <?php if ($location_archived_at) { ?>
                                            <div class="dropdown-divider"></div>
                                            <a class="dropdown-item text-info confirm-link" href="post.php?restore_location=<?= $location_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                                <i class="fas fa-fw fa-redo me-2"></i>Restore
                                            </a>
                                            <?php if ($config_destructive_deletes_enable) { ?>
                                            <div class="dropdown-divider"></div>
                                            <a class="dropdown-item text-danger text-bold confirm-link" href="post.php?delete_location=<?= $location_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                                <i class="fas fa-fw fa-trash me-2"></i>Delete
                                            </a>
                                            <?php } ?>
                                            <?php } else { ?>
                                            <div class="dropdown-divider"></div>
                                            <a class="dropdown-item text-danger confirm-link" href="post.php?archive_location=<?= $location_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                                <i class="fas fa-fw fa-archive me-2"></i>Archive
                                            </a>
                                            <?php } ?>

                                        <?php } ?>
                                    </div>
                                </div>
                            </td>
                        </tr>

                    <?php } ?>

                    </tbody>
                </table>
            </div>
        </form>
        <?php require_once "../includes/filter_footer.php"; ?>
    </div>
</div>

<?php if (!empty($map_locations)) { ?>
<link rel="stylesheet" href="../plugins/leaflet/leaflet.css">
<div class="card card-dark mt-3">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fa fa-fw fa-map me-2"></i>Map</h3>
    </div>
    <div class="card-body p-0">
        <div id="locationsMap" style="height: 420px;"></div>
    </div>
</div>
<script src="../plugins/leaflet/leaflet.js"></script>
<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
(function () {
    var locations = <?php echo json_encode($map_locations, JSON_HEX_TAG); ?>;
    if (!locations.length || typeof L === 'undefined') { return; }

    var map = L.map('locationsMap');
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank">OpenStreetMap</a> contributors'
    }).addTo(map);

    var bounds = [];
    locations.forEach(function (loc) {
        var marker = L.marker([loc.lat, loc.lng]).addTo(map);
        marker.bindPopup(
            '<strong>' + loc.name + '</strong><br>' + loc.address +
            '<br><a class="ajax-modal" href="#" data-modal-url="modals/location/location_edit.php?id=' + loc.id + '">Edit</a>'
        );
        bounds.push([loc.lat, loc.lng]);
    });

    if (bounds.length === 1) {
        map.setView(bounds[0], 14);
    } else {
        map.fitBounds(bounds, { padding: [30, 30] });
    }
})();
</script>
<?php } else if ($num_rows[0] > 0) { ?>
<div class="card card-dark mt-3">
    <div class="card-body text-center text-secondary py-4">
        <i class="fa fa-fw fa-map-marker-alt fa-2x mb-2"></i>
        <p class="mb-0">No locations have map coordinates yet. Re-saving a location's address will geocode it automatically.</p>
    </div>
</div>
<?php } ?>

<script src="../js/bulk_actions.js"></script>

<?php
require_once "../includes/footer.php";
