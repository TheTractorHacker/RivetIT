<?php
defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

/**
 * Server-side check of the asset / interface network fields (the browser input mask is only a hint and can be bypassed).
 * Empty values are fine; ip may also be the word DHCP. Returns a message, or null when everything is valid.
 */
function assetNetworkFieldError($ip, $ipv6, $nat_ip, $mac)
{
    $ip = trim((string) $ip);
    if ($ip !== '' && strcasecmp($ip, 'DHCP') !== 0 && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return "'" . htmlspecialchars($ip) . "' is not a valid IPv4 address (use e.g. 192.168.1.10, or tick DHCP).";
    }
    $nat_ip = trim((string) $nat_ip);
    if ($nat_ip !== '' && !filter_var($nat_ip, FILTER_VALIDATE_IP)) {
        return "'" . htmlspecialchars($nat_ip) . "' is not a valid NAT IP address.";
    }
    $ipv6 = trim((string) $ipv6);
    if ($ipv6 !== '' && !filter_var($ipv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return "'" . htmlspecialchars($ipv6) . "' is not a valid IPv6 address.";
    }
    $mac = trim((string) $mac);
    if ($mac !== '' && !preg_match('/^([0-9A-Fa-f]{2}([:-])?){5}[0-9A-Fa-f]{2}$|^([0-9A-Fa-f]{4}\.){2}[0-9A-Fa-f]{4}$/', $mac)) {
        return "'" . htmlspecialchars($mac) . "' is not a valid MAC address (use e.g. 00:1A:2B:3C:4D:5E).";
    }
    return null;
}

// Runs the check against the variables the model file just built; on failure shows the message and returns to the form.
function assetNetworkFieldsOrBack($ip, $ipv6, $nat_ip, $mac)
{
    $error = assetNetworkFieldError($ip, $ipv6, $nat_ip, $mac);
    if ($error !== null) {
        flash_alert($error, 'error');
        redirect();
    }
}
