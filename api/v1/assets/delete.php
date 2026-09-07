<?php
require_once '../validate_api_key.php';


require_once '../require_post_method.php';


// Parse ID
$asset_id = intval($_POST['asset_id']);

// Default
$delete_count = false;

if (!empty($asset_id)) {
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM assets WHERE asset_id = $asset_id AND asset_client_id = $client_id LIMIT 1"));
    $asset_name = $row['asset_name'];

    $delete_sql = mysqli_query($mysqli, "DELETE FROM assets WHERE asset_id = $asset_id AND asset_client_id = $client_id LIMIT 1");

    // Grab the affected rows for the *asset* delete right here - mysqli_affected_rows()
    // reports the most recent query, so any query run in between would report its count instead
    $assets_deleted = mysqli_affected_rows($mysqli);

    /*
     * Interfaces are deleted by the database, not here: asset_interfaces_ibfk_1 is
     * FOREIGN KEY (interface_asset_id) REFERENCES assets (asset_id) ON DELETE CASCADE,
     * which in turn cascades asset_interface_links via fk_interface_a/fk_interface_b.
     * The explicit "DELETE FROM asset_interfaces WHERE interface_asset_id = $asset_id"
     * that used to sit here was therefore redundant, and it was unscoped - it ignored
     * asset_client_id and ran even when the scoped asset delete above matched nothing,
     * so a key pinned to one department could wipe another department's interfaces
     * (IPs, MACs, VLANs) and their links, with no log entry to show for it.
     */

    // Check delete & get affected rows
    if ($delete_sql && !empty($asset_name)) {
        $delete_count = $assets_deleted;

        // Logging
        logAction("Asset", "Delete", "$asset_name via API ($api_key_name)", $client_id);
    }
}

// Output
require_once '../delete_output.php';

