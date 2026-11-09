<?php
defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

$name = sanitizeInput($_POST['name']);
$ip_address = sanitizeInput($_POST['ip_address']);
$location_id = intval($_POST['location_id'] ?? 0);
$physical_location = sanitizeInput($_POST['physical_location']);
$model = sanitizeInput($_POST['model']);
$serial_number = sanitizeInput($_POST['serial_number']);
$mac_address = sanitizeInput($_POST['mac_address']);
$notes = sanitizeInput($_POST['notes']);
