<?php
defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

$name = sanitizeInput($_POST['name']);
$description = sanitizeInput($_POST['description']);
$vlan = intval($_POST['vlan']);
$network = sanitizeInput($_POST['network']);
$gateway = sanitizeInput($_POST['gateway']);
$primary_dns = sanitizeInput($_POST['primary_dns']);
$secondary_dns = sanitizeInput($_POST['secondary_dns']);
$dhcp_range = sanitizeInput($_POST['dhcp_range']);
$notes = sanitizeInput($_POST['notes']);
$location_id = intval($_POST['location'] ?? 0);

// Server-side validation: the form has no pattern, and a typo like "not-a-cidr" used to be stored as-is.
$network_input_error = null;
$network_parts = explode('/', stripslashes($network), 2);
if (!filter_var($network_parts[0], FILTER_VALIDATE_IP)
    || (isset($network_parts[1]) && (!ctype_digit($network_parts[1]) || (int) $network_parts[1] > (strpos($network_parts[0], ':') !== false ? 128 : 32)))) {
    $network_input_error = 'Network must be an IP address with a prefix, like 192.168.1.0/24 or 2001:db8::/64.';
}
foreach (['Gateway' => $gateway, 'Primary DNS' => $primary_dns, 'Secondary DNS' => $secondary_dns] as $network_field_label => $network_field_value) {
    if ($network_field_value !== '' && !filter_var(stripslashes($network_field_value), FILTER_VALIDATE_IP)) {
        $network_input_error = $network_field_label . ' must be a valid IP address.';
        break;
    }
}
if ($network_input_error !== null) {
    flash_alert($network_input_error, 'error');
    redirect();
}
