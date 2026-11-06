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

// Catch obvious typos before saving: a dotted-number "IP" must be a real IPv4 address (hostnames and IPv6 are still fine),
// and a MAC address must be 12 hex digits with optional : - . separators.
$printer_ip_check = trim((string) ($_POST['ip_address'] ?? ''));
if ($printer_ip_check !== '' && preg_match('/^[0-9.]+$/', $printer_ip_check) && !filter_var($printer_ip_check, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    flash_alert("That is not a valid IP address. Use a form like 10.0.1.50, or a hostname. Nothing was saved.", 'error');
    redirect();
}
$printer_mac_check = trim((string) ($_POST['mac_address'] ?? ''));
if ($printer_mac_check !== '' && !preg_match('/^[0-9A-Fa-f]{12}$/', preg_replace('/[:\-.]/', '', $printer_mac_check))) {
    flash_alert("That is not a valid MAC address. Use 12 hex digits such as 00:1A:2B:3C:4D:5E. Nothing was saved.", 'error');
    redirect();
}
