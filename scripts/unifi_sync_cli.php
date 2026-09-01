#!/usr/bin/env php
<?php

// Change to the directory of this script so relative requires resolve
chdir(__DIR__);

// Ensure script is run only from the CLI
if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.\n");
}

require_once "../config.php";
require_once "../functions.php";
require_once "../includes/class_unifi.php";
require_once "../includes/class_unifi_sync_mapper.php";

$result = mysqli_query($mysqli, "SELECT id, name, type FROM unifi_integrations WHERE enabled=1");
if (!$result || mysqli_num_rows($result) === 0) {
    echo date('Y-m-d H:i:s') . " - No enabled UniFi integrations configured.\n";
    exit(0);
}

while ($integration = mysqli_fetch_assoc($result)) {
    $integration_id = intval($integration['id']);
    $name           = $integration['name'];
    $type           = $integration['type'] ?? 'local';

    echo date('Y-m-d H:i:s') . " - Starting UniFi sync for '$name' [type=$type] (id=$integration_id)...\n";

    try {
        $mapper = new UnifiSyncMapper($mysqli, $integration_id, 0);
        $log_id = $mapper->startSyncLog();

        if ($type === 'cloud') {
            $client = new UnifiCloudClient($integration_id);
            $stats  = $mapper->syncCloud($client);
        } else {
            $client = new UnifiClient($integration_id);
            $stats  = $mapper->sync($client);
        }

        $mapper->finishSyncLog($log_id, $stats);

        echo date('Y-m-d H:i:s') . " - Devices: {$stats['devices_created']} created, {$stats['devices_updated']} updated, {$stats['devices_skipped']} skipped\n";
        echo date('Y-m-d H:i:s') . " - Wi-Fi: {$stats['wifi_created']} created, {$stats['wifi_updated']} updated, {$stats['wifi_skipped']} skipped\n";
        echo date('Y-m-d H:i:s') . " - Networks: {$stats['networks_created']} created, {$stats['networks_updated']} updated, {$stats['networks_skipped']} skipped\n";

        if (!empty($stats['errors'])) {
            foreach ($stats['errors'] as $error) {
                echo date('Y-m-d H:i:s') . " - ERROR: $error\n";
            }
        }

        logAction('UniFi', 'Import',
            "CLI sync for '$name': {$stats['devices_created']} devices created, {$stats['devices_updated']} updated, " .
            "{$stats['wifi_created']} Wi-Fi creds created, {$stats['networks_created']} networks created"
        );
    } catch (RuntimeException $e) {
        if (isset($log_id)) {
            mysqli_query($mysqli, "UPDATE unifi_sync_log SET finished_at=NOW(), status='failed', errors='" .
                mysqli_real_escape_string($mysqli, $e->getMessage()) . "' WHERE id=$log_id");
        }
        echo date('Y-m-d H:i:s') . " - FAILED: " . $e->getMessage() . "\n";
    }
}

echo date('Y-m-d H:i:s') . " - UniFi sync complete.\n";
