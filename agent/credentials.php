<?php

// Default Column Sortby Filter
$sort = "credential_name";
$order = "ASC";

// If client_id is in URI then show client Side Bar and client header
if (isset($_GET['client_id'])) {
    require_once "includes/inc_all_client.php";
    $client_query = "AND credential_client_id = $client_id";
    $client_url = "client_id=$client_id&";
    // Overide Filter Header Archived
    if (isset($_GET['archived']) && $_GET['archived'] == 1) {
        $archived = 1;
        $archive_query = "credential_archived_at IS NOT NULL";
    } else {
        $archived = 0;
        $archive_query = "credential_archived_at IS NULL";
    }

    // Log when users load the Credentials page
    logAction("Credential", "View", "$session_name viewed the Credentials page for client", $client_id);

} else {
    require_once "includes/inc_client_overview_all.php";
    $client_query = '';
    $client_url = '';
    // Overide Filter Header Archived
    if (isset($_GET['archived']) && $_GET['archived'] == 1) {
        $archived = 1;
        $archive_query = "(client_archived_at IS NOT NULL OR credential_archived_at IS NOT NULL)";
    } else {
        $archived = 0;
        $archive_query = "(client_archived_at IS NULL AND credential_archived_at IS NULL)";
    }
    // Log when users load the Credentials page
    logAction("Credential", "View", "$session_name viewed the All Credentials page");
}

// Perms
enforceUserPermission('module_credential');

// Folder navigation (client view only) - credential folders use folder_location = 2
$folder_id = 0;
$folder_path = [];
$credential_folders = [];          // folder_id => ['folder_id', 'folder_name', 'parent_folder']
$credential_folder_children = [];  // parent_folder_id => [folder_id, folder_id, ...]
$credential_folder_counts = [];    // folder_id => credential count, filtered by $archive_query (0 = root)
$credential_folder_total_counts = []; // folder_id => credential count, unfiltered (used for "is this folder empty" checks)

if ($client_url) {
    if (!empty($_GET['folder_id'])) {
        $folder_id = intval($_GET['folder_id']);
    }

    // Load the whole credential folder tree for this client in one query, indexed for O(1) lookups -
    // avoids running a query per folder per tab row when navigating into deeper folders.
    $sql_all_credential_folders = mysqli_query($mysqli, "SELECT folder_id, folder_name, parent_folder FROM folders WHERE folder_client_id = $client_id AND folder_location = 2 ORDER BY folder_name ASC");
    while ($row = mysqli_fetch_assoc($sql_all_credential_folders)) {
        $fid = intval($row['folder_id']);
        $pid = intval($row['parent_folder']);
        $credential_folders[$fid] = [
            'folder_id'     => $fid,
            'folder_name'   => nullable_htmlentities($row['folder_name']),
            'parent_folder' => $pid
        ];
        $credential_folder_children[$pid][] = $fid;
    }

    // Credential counts per folder in one grouped query (respects the current archive filter)
    $sql_folder_counts = mysqli_query($mysqli, "SELECT credential_folder_id, COUNT(*) AS num FROM credentials WHERE credential_client_id = $client_id AND $archive_query GROUP BY credential_folder_id");
    while ($row = mysqli_fetch_assoc($sql_folder_counts)) {
        $credential_folder_counts[intval($row['credential_folder_id'])] = intval($row['num']);
    }

    // Unfiltered counts (active + archived) so we don't offer to delete a folder that still has archived credentials in it
    if ($session_user_role == 3) {
        $sql_folder_total_counts = mysqli_query($mysqli, "SELECT credential_folder_id, COUNT(*) AS num FROM credentials WHERE credential_client_id = $client_id GROUP BY credential_folder_id");
        while ($row = mysqli_fetch_assoc($sql_folder_total_counts)) {
            $credential_folder_total_counts[intval($row['credential_folder_id'])] = intval($row['num']);
        }
    }

    // Breadcrumbs: walk the in-memory folder map, no extra queries
    $breadcrumb_folder_id = $folder_id;
    while ($breadcrumb_folder_id > 0 && isset($credential_folders[$breadcrumb_folder_id])) {
        array_unshift($folder_path, $credential_folders[$breadcrumb_folder_id]);
        $breadcrumb_folder_id = $credential_folders[$breadcrumb_folder_id]['parent_folder'];
    }
}
$get_folder_id = $folder_id;

// Direct child folders of a given credential folder, with item counts (used to render one row of folder tabs)
function get_credential_subfolders($parent_folder_id) {
    global $credential_folders, $credential_folder_children, $credential_folder_counts;

    $subfolders = [];
    foreach ($credential_folder_children[$parent_folder_id] ?? [] as $child_id) {
        $subfolders[] = [
            'folder_id'   => $child_id,
            'folder_name' => $credential_folders[$child_id]['folder_name'],
            'count'       => $credential_folder_counts[$child_id] ?? 0
        ];
    }

    return $subfolders;
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

if (!$client_url) {
    // Client Filter
    if (isset($_GET['client']) & !empty($_GET['client'])) {
        $client_query = 'AND (credential_client_id = ' . intval($_GET['client']) . ')';
        $client = intval($_GET['client']);
    } else {
        // Default - any
        $client_query = '';
        $client = '';
    }
}

// Location Filter
if ($client_url && isset($_GET['location']) && !empty($_GET['location'])) {
    $location_query = 'AND (a.asset_location_id = ' . intval($_GET['location']) . ')';
    $location_query_innerjoin = 'INNER JOIN assets a on a.asset_id = c.credential_asset_id ';
    $location_filter = intval($_GET['location']);
} else {
    // Default - any
    $location_query_innerjoin = '';
    $location_query = '';
    $location_filter = '';
}

// Folder filter (client view only)
if ($client_url) {
    if ($get_folder_id == 0 && !empty($q)) {
        // Searching from root - search across all folders
        $folder_query = '';
    } else {
        $folder_query = "AND c.credential_folder_id = $get_folder_id";
    }
} else {
    $folder_query = '';
}

$sql = mysqli_query(
    $mysqli,
    "SELECT SQL_CALC_FOUND_ROWS c.credential_id AS c_credential_id, c.*, credential_tags.*, tags.*, clients.*, contacts.*, assets.*
    FROM credentials c
    LEFT JOIN credential_tags ON credential_tags.credential_id = c.credential_id
    LEFT JOIN tags ON tags.tag_id = credential_tags.tag_id
    LEFT JOIN clients ON client_id = credential_client_id
    LEFT JOIN contacts ON contact_id = credential_contact_id
    LEFT JOIN assets ON asset_id = credential_asset_id
    $location_query_innerjoin
    WHERE $archive_query
    $tag_query
    AND (c.credential_name LIKE '%$q%' OR c.credential_description LIKE '%$q%' OR c.credential_uri LIKE '%$q%' OR tag_name LIKE '%$q%' OR client_name LIKE '%$q%')
    $location_query
    $folder_query
    $access_permission_query
    $client_query
    GROUP BY c.credential_id
    ORDER BY c.credential_favorite DESC, $sort $order LIMIT $record_from, $record_to"
);

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

?>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fa fa-fw fa-key me-2"></i>Credentials</h3>
        <div class="card-tools">
            <?php if (lookupUserPermission("module_credential") >= 2) { ?>
                <div class="btn-group">
                <button type="button" class="btn btn-primary ajax-modal" data-modal-url="modals/credential/credential_add.php?<?= $client_url ?>folder_id=<?= $get_folder_id ?>" <?php if (!isset($_COOKIE['user_encryption_session_key'])) { echo 'disabled title="Credential encryption is not set up for your account. Ask an administrator to reset your password under Admin > Users to enable this."'; } ?>>
                    <i class="fas fa-plus me-2"></i>New Credential
                </button>
                <button type="button" class="btn btn-primary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown"></button>
                <div class="dropdown-menu">
                    <?php if ($client_url) { ?>
                    <a class="dropdown-item text-dark ajax-modal" href="#"
                        data-modal-url="modals/folder/folder_add.php?client_id=<?= $client_id ?>&current_folder_id=<?= $get_folder_id ?>&folder_location=2">
                        <i class="fa fa-fw fa-folder-plus me-2"></i>New Folder
                    </a>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item text-dark ajax-modal" href="#"
                        data-modal-url="modals/credential/credential_import.php?<?= $client_url ?>">
                        <i class="fa fa-fw fa-upload me-2"></i>Import
                    </a>
                    <div class="dropdown-divider"></div>
                    <?php } ?>
                    <?php if ($num_rows[0] > 0) { ?>
                        <a class="dropdown-item text-dark ajax-modal" href="#"
                            data-modal-url="modals/credential/credential_export.php?<?= $client_url ?>">
                            <i class="fa fa-fw fa-download me-2"></i>Export
                        </a>
                    <?php } ?>
                </div>
            </div>
            <?php } ?>
        </div>
    </div>
    <div class="card-body">
        <?php if (!isset($_COOKIE['user_encryption_session_key'])) { ?>
            <div class="alert alert-warning">
                <i class="fas fa-fw fa-lock me-2"></i>Credential vault is locked for this session - sign in with your password to view, copy, or add credentials.
            </div>
        <?php } ?>
        <form autocomplete="off">
            <?php if ($client_url) { ?>
            <input type="hidden" name="client_id" value="<?php echo $client_id; ?>">
            <input type="hidden" name="folder_id" value="<?php echo $get_folder_id; ?>">
            <?php } ?>
            <input type="hidden" name="archived" value="<?php echo $archived; ?>">
            <div class="row">

                <div class="col-md-4">
                    <div class="input-group mb-3 mb-md-0">
                        <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search Credentials">
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
                                SELECT tags.tag_id, tags.tag_name
                                FROM tags
                                LEFT JOIN credential_tags ON credential_tags.tag_id = tags.tag_id
                                LEFT JOIN credentials ON credential_tags.credential_id = credentials.credential_id
                                WHERE tag_type = 4
                                $client_query OR tags.tag_id IN ($tag_filter)
                                GROUP BY tags.tag_id
                                HAVING COUNT(credential_tags.credential_id) > 0 OR tags.tag_id IN ($tag_filter)
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
                <div class="col-md-2">
                    <div class="input-group mb-3 mb-md-0">
                        <select class="form-control select2 auto-submit-select" name="location">
                            <option value="">- All Asset Locations -</option>

                            <?php
                            $sql_locations_filter = mysqli_query($mysqli, "SELECT * FROM locations WHERE location_client_id = $client_id AND location_archived_at IS NULL ORDER BY location_name ASC");
                            while ($row = mysqli_fetch_assoc($sql_locations_filter)) {
                                $location_id = intval($row['location_id']);
                                $location_name = nullable_htmlentities($row['location_name']);
                            ?>
                                <option <?php if ($location_filter == $location_id) { echo "selected"; } ?> value="<?php echo $location_id; ?>"><?php echo $location_name; ?></option>
                            <?php
                            }
                            ?>

                        </select>
                    </div>
                </div>
                <?php } else { ?>
                <div class="col-md-2">
                    <div class="input-group mb-3 mb-md-0">
                        <select class="form-control select2 auto-submit-select" name="client">
                            <option value="" <?php if ($client == "") { echo "selected"; } ?>>- All Clients -</option>

                            <?php
                            $sql_clients_filter = mysqli_query($mysqli, "
                                SELECT DISTINCT client_id, client_name
                                FROM clients
                                JOIN credentials ON credential_client_id = client_id
                                WHERE $archive_query
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
                        <a href="?<?php echo $client_url; ?>folder_id=<?= $get_folder_id ?>&archived=<?php if($archived == 1){ echo 0; } else { echo 1; } ?>"
                            class="btn btn-<?php if($archived == 1){ echo "primary"; } else { echo "default"; } ?>">
                            <i class="fa fa-fw fa-archive me-2"></i>Archived
                        </a>
                        <div class="dropdown ms-2" id="bulkActionButton" hidden>
                            <button class="btn btn-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                <i class="fas fa-fw fa-layer-group me-2"></i>Bulk Action (<span id="selectedCount">0</span>)
                            </button>
                            <div class="dropdown-menu">
                                <?php if ($client_url) { ?>
                                <a class="dropdown-item ajax-modal" href="#"
                                    data-modal-url="modals/credential/credential_bulk_move.php?client_id=<?= $client_id ?>&current_folder_id=<?= $get_folder_id ?>"
                                    data-bulk="true">
                                    <i class="fas fa-fw fa-exchange-alt me-2"></i>Move
                                </a>
                                <div class="dropdown-divider"></div>
                                <?php } ?>
                                <button class="dropdown-item"
                                    type="submit" form="bulkActions" name="bulk_favorite_credentials">
                                    <i class="fas fa-fw fa-star text-warning me-2"></i>Favorite
                                </button>
                                <div class="dropdown-divider"></div>
                                <button class="dropdown-item"
                                    type="submit" form="bulkActions" name="bulk_unfavorite_credentials">
                                    <i class="far fa-fw fa-star me-2"></i>Unfavorite
                                </button>
                                <?php if ($archived) { ?>
                                <div class="dropdown-divider"></div>
                                <button class="dropdown-item text-info"
                                    type="submit" form="bulkActions" name="bulk_restore_credentials">
                                    <i class="fas fa-fw fa-redo me-2"></i>Restore
                                </button>
                                <div class="dropdown-divider"></div>
                                <button class="dropdown-item text-danger text-bold"
                                    type="submit" form="bulkActions" name="bulk_delete_credentials">
                                    <i class="fas fa-fw fa-trash me-2"></i>Delete
                                </button>
                                <?php } else { ?>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item ajax-modal" href="#"
                                    data-modal-url="modals/credential/credential_bulk_assign_tags.php"
                                    data-bulk="true">
                                    <i class="fas fa-fw fa-tags me-2"></i>Assign Tags
                                </a>
                                    <div class="dropdown-divider"></div>
                                <button class="dropdown-item text-danger confirm-link"
                                    type="submit" form="bulkActions" name="bulk_archive_credentials">
                                    <i class="fas fa-fw fa-archive me-2"></i>Archive
                                </button>
                                <?php } ?>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </form>

        <?php if ($client_url) { ?>
        <?php
        // One tab row per folder depth: row 0 = top-level folders (plus "Root" itself),
        // each subsequent row = children of the folder active in the row above.
        $tab_row_parent_ids = [0];
        foreach ($folder_path as $folder) {
            $tab_row_parent_ids[] = $folder['folder_id'];
        }
        ?>
        <?php foreach ($tab_row_parent_ids as $row_index => $row_parent_id) {
            $active_child_id = $folder_path[$row_index]['folder_id'] ?? null; // which tab in this row is active, if any
            $subfolders = get_credential_subfolders($row_parent_id);

            if ($row_index > 0 && empty($subfolders)) {
                continue; // nothing to navigate to at this depth
            }
        ?>
            <ul class="nav nav-tabs <?= $row_index == 0 ? 'mt-3' : '' ?>">

                <?php if ($row_index == 0) { ?>
                <li class="nav-item">
                    <a class="nav-link <?= $get_folder_id == 0 ? 'active' : '' ?>" href="?client_id=<?= $client_id ?>&folder_id=0&archived=<?= $archived ?>">
                        <i class="fas fa-fw fa-folder me-1"></i>Root
                        <?php if (($credential_folder_counts[0] ?? 0) > 0) { ?><span class="badge rounded-pill text-bg-dark ms-1"><?= $credential_folder_counts[0] ?></span><?php } ?>
                    </a>
                </li>
                <?php } ?>
                <?php foreach ($subfolders as $subfolder) { ?>
                <li class="nav-item">
                    <a class="nav-link <?= $active_child_id == $subfolder['folder_id'] ? 'active' : '' ?>" href="?client_id=<?= $client_id ?>&folder_id=<?= $subfolder['folder_id'] ?>&archived=<?= $archived ?>">
                        <i class="fas fa-fw fa-folder me-1"></i><?= $subfolder['folder_name'] ?>
                        <?php if ($subfolder['count'] > 0) { ?><span class="badge rounded-pill text-bg-dark ms-1"><?= $subfolder['count'] ?></span><?php } ?>
                    </a>
                </li>
                <?php } ?>
                <?php if (lookupUserPermission("module_credential") >= 2 && $active_child_id) { ?>
                <li class="nav-item ml-auto">
                    <div class="dropdown">
                        <a class="nav-link" href="#" data-bs-toggle="dropdown"><i class="fas fa-ellipsis-v text-secondary"></i></a>
                        <div class="dropdown-menu dropdown-menu-right">
                            <a class="dropdown-item ajax-modal" href="#"
                               data-modal-url="modals/folder/folder_rename.php?id=<?= $active_child_id ?>">
                                <i class="fas fa-fw fa-edit me-2"></i>Rename Folder
                            </a>
                            <?php
                            $folder_is_empty = ($session_user_role == 3)
                                && (($credential_folder_total_counts[$active_child_id] ?? 0) == 0)
                                && empty($credential_folder_children[$active_child_id]);
                            if ($folder_is_empty) { ?>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item text-danger text-bold confirm-link" href="post.php?delete_folder=<?php echo $active_child_id; ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                    <i class="fas fa-fw fa-trash me-2"></i>Delete Folder
                                </a>
                            <?php } ?>
                        </div>
                    </div>
                </li>
                <?php } ?>
            </ul>
        <?php } ?>
        <?php } ?>

        <hr class="mt-3">
        <form id="bulkActions" action="post.php" method="post">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">

            <div class="table-responsive">
                <table class="table table-striped table-borderless table-hover">
                    <thead class="text-dark <?php if ($num_rows[0] == 0) { echo "d-none"; } ?> text-nowrap">
                        <tr>
                            <td class="bg-light checkbox-column">
                                <div class="form-check">
                                    <input class="form-check-input" id="selectAllCheckbox" type="checkbox">
                                </div>
                            </td>
                            <th>
                                <a class="text-secondary" href="?<?php echo $url_query_strings_sort; ?>&sort=credential_name&order=<?php echo $disp; ?>">
                                    Name <?php if ($sort == 'credential_name') { echo $order_icon; } ?>
                                </a>
                            </th>
                            <th>Username / ID</th>
                            <th>Password / Key</th>
                            <th>OTP</th>
                            <th>
                                <a class="text-secondary" href="?<?php echo $url_query_strings_sort; ?>&sort=credential_uri&order=<?php echo $disp; ?>">
                                    URI <?php if ($sort == 'credential_uri') { echo $order_icon; } ?>
                                </a>
                            </th>
                            <th></th>
                            <?php if (!$client_url) { ?>
                            <th>
                                <a class="text-secondary" href="?<?php echo $url_query_strings_sort; ?>&sort=client_name&order=<?php echo $disp; ?>">
                                    Client <?php if ($sort == 'client_name') { echo $order_icon; } ?>
                                </a>
                            </th>
                            <?php } ?>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php

                        while ($row = mysqli_fetch_assoc($sql)) {
                            $client_id = intval($row['client_id']);
                            $client_name = nullable_htmlentities($row['client_name']);
                            $credential_id = intval($row['c_credential_id']);
                            $credential_name = nullable_htmlentities($row['credential_name']);
                            $credential_description = nullable_htmlentities($row['credential_description']);
                            $credential_uri = sanitize_url($row['credential_uri']);
                            if (empty($credential_uri)) {
                                $credential_uri_display = "-";
                            } else {
                                $credential_uri_display = "<a href='$credential_uri'>" . truncate($credential_uri,40) . "</a><button class='btn btn-sm clipboardjs' type='button' title='$credential_uri' data-clipboard-text='$credential_uri'><i class='far fa-copy text-secondary'></i></button>";
                            }
                            $credential_uri_2 = sanitize_url($row['credential_uri_2']);
                            $credential_username = nullable_htmlentities(decryptCredentialEntry($row['credential_username']));
                            if (empty($credential_username)) {
                                $credential_username_display = "-";
                            } else {
                                $credential_username_display = "$credential_username<button class='btn btn-sm clipboardjs' type='button' data-clipboard-text='$credential_username'><i class='far fa-copy text-secondary'></i></button>";
                            }
                            $credential_password = nullable_htmlentities(decryptCredentialEntry($row['credential_password']));
                            $credential_otp_secret = nullable_htmlentities($row['credential_otp_secret']);
                            $credential_id_with_secret = '"' . $row['credential_id'] . '","' . $row['credential_otp_secret'] . '"';
                            if (empty($credential_otp_secret)) {
                                $otp_display = "-";
                            } else {
                                $otp_display = "<span class='otp-reveal-trigger' data-credential-id='$credential_id'><i class='far fa-clock'></i> <span id='otp_$credential_id'><i>Hover..</i></span></span>";
                            }
                            $credential_note = nullable_htmlentities($row['credential_note']);
                            $credential_created_at = nullable_htmlentities($row['credential_created_at']);
                            $credential_archived_at = nullable_htmlentities($row['credential_archived_at']);
                            $credential_favorite = intval($row['credential_favorite']);
                            $credential_contact_id = intval($row['credential_contact_id']);
                            $contact_name = nullable_htmlentities($row['contact_name']);
                            $credential_asset_id = intval($row['credential_asset_id']);
                            $asset_name = nullable_htmlentities($row['asset_name']);

                            // Tags
                            $credential_tag_name_display_array = array();
                            $credential_tag_id_array = array();
                            $sql_credential_tags = mysqli_query($mysqli, "SELECT * FROM credential_tags LEFT JOIN tags ON credential_tags.tag_id = tags.tag_id WHERE credential_id = $credential_id ORDER BY tag_name ASC");
                            while ($row = mysqli_fetch_assoc($sql_credential_tags)) {

                                $credential_tag_id = intval($row['tag_id']);
                                $credential_tag_name = nullable_htmlentities($row['tag_name']);
                                $credential_tag_color = nullable_htmlentities($row['tag_color']);
                                if (empty($credential_tag_color)) {
                                    $credential_tag_color = "dark";
                                }
                                $credential_tag_icon = nullable_htmlentities($row['tag_icon']);
                                if (empty($credential_tag_icon)) {
                                    $credential_tag_icon = "tag";
                                }

                                $credential_tag_id_array[] = $credential_tag_id;
                                $credential_tag_name_display_array[] = "<a href='credentials.php?$client_url tags[]=$credential_tag_id'><span class='badge " . tagTextClass($credential_tag_color) . " p-1 me-1' style='background-color: $credential_tag_color;'><i class='fa fa-fw fa-$credential_tag_icon me-2'></i>$credential_tag_name</span></a>";
                            }
                            $credential_tags_display = implode('', $credential_tag_name_display_array);

                            if ($credential_contact_id) {
                                $credential_contact_display = "<a href='#' class='me-2 mb-1 badge rounded-pill text-bg-dark p-2 ajax-modal' title='$contact_name'
                                    data-modal-size='lg'
                                    data-modal-url='modals/contact/contact_details.php?id=$credential_contact_id'>
                                    <i class='fas fa-fw fa-user'></i></a>";
                            } else {
                                $credential_contact_display = '';
                            }

                            if ($credential_asset_id) {
                                $credential_asset_display = "<a href='#' class='me-2 mb-1 badge rounded-pill text-bg-secondary p-2 ajax-modal' title='$asset_name' data-toggle=''
                                    data-modal-size='lg'
                                    data-modal-url='modals/asset/asset_details.php?id=$credential_asset_id'>
                                    <i class='fas fa-fw fa-desktop'></i></a>";
                            } else {
                                $credential_asset_display = '';
                            }

                            // Check if shared
                            $sql_shared = mysqli_query(
                                $mysqli,
                                "SELECT * FROM shared_items
                                WHERE item_client_id = $client_id
                                AND item_active = 1
                                AND item_views != item_view_limit
                                AND item_expire_at > NOW()
                                AND item_type = 'Credential'
                                AND item_related_id = $credential_id
                                LIMIT 1"
                            );
                            if (mysqli_num_rows($sql_shared) > 0) {
                                $row = mysqli_fetch_assoc($sql_shared);
                                $item_id = intval($row['item_id']);
                                $item_active = nullable_htmlentities($row['item_active']);
                                $item_key = nullable_htmlentities($row['item_key']);
                                $item_type = nullable_htmlentities($row['item_type']);
                                $item_related_id = intval($row['item_related_id']);
                                $item_note = nullable_htmlentities($row['item_note']);
                                $item_recipient = nullable_htmlentities($row['item_recipient']);
                                $item_views = nullable_htmlentities($row['item_views']);
                                $item_view_limit = nullable_htmlentities($row['item_view_limit']);
                                $item_created_at = nullable_htmlentities($row['item_created_at']);
                                $item_expire_at = nullable_htmlentities($row['item_expire_at']);
                                $item_expire_at_human = timeAgo($row['item_expire_at']);
                            }


                        ?>
                            <tr class="<?php if ($credential_favorite) { echo "text-bold"; } ?>">
                                <td class="bg-light checkbox-column">
                                    <div class="form-check">
                                        <input class="form-check-input bulk-select" type="checkbox" name="credential_ids[]" value="<?php echo $credential_id ?>">
                                    </div>
                                </td>
                                <td>
                                    <a class="text-dark ajax-modal" href="#"
                                        data-modal-url="modals/credential/credential_view.php?id=<?= $credential_id ?>">
                                        <div class="media">
                                            <i class="fa fa-fw fa-2x fa-key me-3"></i>
                                            <div class="media-body">
                                                <div><?php echo $credential_name; ?> <?php if ($credential_favorite) { echo "<i class='fas fa-fw fa-star text-warning' title='Favorite'></i>"; } ?></div>
                                                <div><small class="text-secondary"><?php echo $credential_description; ?></small></div>
                                                <?php
                                                if (!empty($credential_tags_display)) { ?>
                                                    <div class="mt-1">
                                                        <?php echo $credential_tags_display; ?>
                                                    </div>
                                                <?php } ?>
                                            </div>
                                        </div>
                                    </a>
                                </td>
                                <td class="text-nowrap"><?php echo $credential_username_display; ?></td>
                                <td class="text-nowrap">
                                    <div class="d-flex align-items-center">
                                        <button class="btn p-0" type="button" data-bs-toggle="popover" data-trigger="focus" data-placement="top" data-content="<?php echo $credential_password; ?>"><i class="fas fa-2x fa-ellipsis-h text-secondary"></i><i class="fas fa-2x fa-ellipsis-h text-secondary"></i></button><button class="btn btn-sm clipboardjs" type="button" data-clipboard-text="<?php echo $credential_password; ?>"><i class="far fa-copy text-secondary"></i></button>
                                    </div>
                                </td>
                                <td class="text-nowrap"><?php echo $otp_display; ?></td>
                                <td><?php echo $credential_uri_display; ?></td>
                                <td>
                                    <?php echo "$credential_contact_display$credential_asset_display"; ?>
                                    <?php if (mysqli_num_rows($sql_shared) > 0) { ?>
                                        <div class="media" title="Expires <?php echo $item_expire_at_human; ?>">
                                            <i class="fas fa-link me-2 mt-1"></i>
                                            <div class="media-body">Shared
                                                <br>
                                                <small class="text-secondary"><?php echo $item_recipient; ?></small>
                                            </div>
                                        </div>
                                    <?php } ?>
                                </td>
                                <?php if (!$client_url) { ?>
                                <td><a href="credentials.php?client_id=<?php echo $client_id; ?>"><?php echo $client_name; ?></a></td>
                                <?php } ?>
                                <td class="text-center">
                                    <div class="btn-group">
                                        <?php if ( !empty($credential_uri) || !empty($credential_uri_2) ) { ?>
                                        <div class="dropdown text-center">
                                            <button class="btn btn-default btn-sm" type="button" data-bs-toggle="dropdown" data-display="static">
                                                <i class="fa fa-fw fa-external-link-alt"></i>
                                            </button>
                                            <div class="dropdown-menu">
                                                <?php if ($credential_uri) { ?>
                                                <a href="<?php echo $credential_uri; ?>" alt="<?php echo $credential_uri; ?>" target="_blank" class="dropdown-item" >
                                                    <i class="fa fa-fw fa-external-link-alt"></i> <?php echo truncate($credential_uri,40); ?>
                                                </a>
                                                <?php } ?>
                                                <?php if ($credential_uri_2) { ?>
                                                <div class="dropdown-divider"></div>
                                                <a href="<?php echo $credential_uri_2; ?>" target="_blank" class="dropdown-item" >
                                                    <i class="fa fa-fw fa-external-link-alt"></i> <?php echo truncate($credential_uri_2,40); ?>
                                                </a>
                                                <?php } ?>
                                            </div>
                                        </div>
                                        <?php } ?>
                                        <div class="dropdown text-center">
                                            <button class="btn btn-secondary btn-sm" type="button" data-bs-toggle="dropdown" data-display="static">
                                                <i class="fas fa-ellipsis-h"></i>
                                            </button>
                                            <div class="dropdown-menu">
                                                <a class="dropdown-item ajax-modal" href="#"
                                                    data-modal-url="modals/credential/credential_edit.php?id=<?= $credential_id ?>">
                                                    <i class="fas fa-fw fa-edit me-2"></i>Edit
                                                </a>
                                                <?php if (lookupUserPermission("module_credential") >= 2) { ?>
                                                <a class="dropdown-item ajax-modal" href="#"
                                                    data-modal-url="modals/credential/credential_move.php?id=<?= $credential_id ?>">
                                                    <i class="fas fa-fw fa-exchange-alt me-2"></i>Move
                                                </a>
                                                <?php } ?>
                                                <div class="dropdown-divider"></div>
                                                <?php if ($client_url) { ?>
                                                <a class="dropdown-item share-link-trigger" href="#" data-bs-toggle="modal" data-bs-target="#shareModal" data-client-id="<?php echo $client_id; ?>" data-item-type="Credential" data-item-ref-id="<?php echo $credential_id; ?>">
                                                    <i class="fas fa-fw fa-share me-2"></i>Share
                                                </a>
                                                <?php } ?>
                                                <?php  if (lookupUserPermission("module_credential") >= 2) { ?>
                                                    <?php if ($credential_archived_at) { ?>
                                                        <div class="dropdown-divider"></div>
                                                        <a class="dropdown-item text-info confirm-link" href="post.php?restore_credential=<?php echo $credential_id; ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                                            <i class="fas fa-fw fa-redo me-2"></i>Restore
                                                        </a>
                                                        <?php if (lookupUserPermission("module_credential") >= 3) { ?>
                                                            <div class="dropdown-divider"></div>
                                                            <a class="dropdown-item text-danger text-bold confirm-link" href="post.php?delete_credential=<?php echo $credential_id; ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                                                <i class="fas fa-fw fa-trash me-2"></i>Delete
                                                        <?php } ?>
                                                        </a>
                                                    <?php } else { ?>
                                                        <div class="dropdown-divider"></div>
                                                        <a class="dropdown-item text-danger confirm-link" href="post.php?archive_credential=<?php echo $credential_id; ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                                            <i class="fas fa-fw fa-archive me-2"></i>Archive
                                                        </a>
                                                    <?php } ?>
                                                <?php } ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>

                        <?php

                        }

                        ?>

                    </tbody>
                </table>
            </div>
        </form>
        <?php require_once "../includes/filter_footer.php"; ?>
    </div>
</div>

<!-- Include script to get TOTP code via the login ID -->
<script src="js/credential_show_otp_via_id.js"></script>
<script src="../js/bulk_actions.js"></script>
<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
$(function () {
    $('[data-bs-toggle="popover"]').popover();
});
</script>

<?php
if ($client_url) {
    require_once "modals/share_modal.php";
}
require_once "../includes/footer.php";
