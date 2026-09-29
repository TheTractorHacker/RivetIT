#!/usr/bin/env php
<?php

// Records what sits in front of the app so client IPs are read correctly (same fields as
// Admin > Security > Network path). Needs database 2.6.106 or newer.
//   php set_network_cli.php --proxies=1 --cloudflare=no
//   php set_network_cli.php --show

chdir(__DIR__);

if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.\n");
}

require_once "../config.php";

$opts = getopt('', ['proxies:', 'cloudflare:', 'show', 'help']);

if (isset($opts['help']) || (!isset($opts['show']) && !isset($opts['proxies']) && !isset($opts['cloudflare']))) {
    echo "Usage: php set_network_cli.php [--proxies=N] [--cloudflare=yes|no] | --show\n\n";
    echo "  --proxies=N          Local reverse proxies in front of the app, 0-10, not counting Cloudflare.\n";
    echo "                       'unset' returns to legacy detection (config.php CONST_GET_IP_METHOD).\n";
    echo "  --cloudflare=yes|no  Whether traffic arrives through Cloudflare.\n";
    echo "  --show               Print the saved values.\n";
    exit(isset($opts['help']) ? 0 : 1);
}

$sets = [];

if (isset($opts['proxies'])) {
    $p = strtolower(trim((string) $opts['proxies']));
    if ($p === 'unset') {
        $sets[] = "config_proxy_hops = NULL";
    } elseif (ctype_digit($p) && (int) $p <= 10) {
        $sets[] = "config_proxy_hops = " . (int) $p;
    } else {
        fwrite(STDERR, "Error: --proxies must be a whole number from 0 to 10, or 'unset'.\n");
        exit(1);
    }
}

if (isset($opts['cloudflare'])) {
    $c = strtolower(trim((string) $opts['cloudflare']));
    if (in_array($c, ['yes', 'y', '1', 'true', 'on'], true)) {
        $sets[] = "config_behind_cloudflare = 1";
    } elseif (in_array($c, ['no', 'n', '0', 'false', 'off'], true)) {
        $sets[] = "config_behind_cloudflare = 0";
    } else {
        fwrite(STDERR, "Error: --cloudflare must be yes or no.\n");
        exit(1);
    }
}

if ($sets) {
    if (!mysqli_query($mysqli, "UPDATE settings SET " . implode(', ', $sets) . " WHERE company_id = 1")) {
        fwrite(STDERR, "Error: could not save (is the database at 2.6.106 or newer? run update_cli.php --update_db): " . mysqli_error($mysqli) . "\n");
        exit(1);
    }
    if (mysqli_affected_rows($mysqli) < 0) {
        fwrite(STDERR, "Error: no settings row to update.\n");
        exit(1);
    }
}

$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_proxy_hops, config_behind_cloudflare FROM settings WHERE company_id = 1"));
if (!$row) {
    fwrite(STDERR, "Error: no settings row found.\n");
    exit(1);
}
echo "Local reverse proxies: " . ($row['config_proxy_hops'] === null ? 'not set (legacy detection)' : $row['config_proxy_hops']) . "\n";
echo "Behind Cloudflare:     " . ($row['config_behind_cloudflare'] ? 'yes' : 'no') . "\n";
