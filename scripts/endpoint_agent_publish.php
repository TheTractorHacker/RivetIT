#!/usr/bin/env php
<?php

// Publishes an agent executable to the RivetIT server (same validation as Administration > Endpoint agent > Agent binaries).
//   php scripts/endpoint_agent_publish.php dist/rivetit-agent-windows-amd64.exe --version 1.2.0 --arch amd64 [--activate]
//        [--release stable|pilot [--rollout 10] [--notes "text"]]
//   --activate  make it the binary new per-department installers are stamped from for that architecture
//   --release   also offer it to already enrolled agents of that architecture as a self-update (staged by --rollout percent)
// Run it as the web server user (sudo -u www-data php ...) or as root (the stored file is then handed to the directory owner).

chdir(__DIR__);

if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.\n");
}

require_once "../config.php";
require_once "../functions.php";
require_once "../vendor/autoload.php";
require_once "../includes/rmm_bootstrap.php";

mysqli_report(MYSQLI_REPORT_OFF);
// Own argument parser: the executable may come before or after the options (PHP's getopt() stops at the first non-option).
$opts = [];
$file = null;
$args = array_slice($argv, 1);
$valueOpts = ['version', 'arch', 'release', 'rollout', 'notes'];
for ($i = 0; $i < count($args); $i++) {
    $a = $args[$i];
    if (strncmp($a, '--', 2) === 0) {
        $name = substr($a, 2);
        $val = null;
        if (strpos($name, '=') !== false) {
            [$name, $val] = explode('=', $name, 2);
        }
        if (in_array($name, $valueOpts, true)) {
            $opts[$name] = $val ?? ($args[++$i] ?? '');
        } elseif ($name === 'activate' || $name === 'help') {
            $opts[$name] = true;
        } else {
            fwrite(STDERR, "Error: unknown option --$name\n");
            exit(1);
        }
    } elseif ($file === null) {
        $file = $a;
    } else {
        fwrite(STDERR, "Error: only one executable may be given.\n");
        exit(1);
    }
}
if (isset($opts['help']) || $file === null || !isset($opts['version'], $opts['arch'])) {
    echo "Usage: php endpoint_agent_publish.php <exe> --version X.Y.Z --arch amd64|arm64 [--activate] [--release stable|pilot [--rollout N] [--notes TEXT]]\n";
    exit(isset($opts['help']) ? 0 : 1);
}
if (Db_tableMissing()) {
    fwrite(STDERR, "Error: the endpoint agent tables are missing. Run: php scripts/update_cli.php --update_db\n");
    exit(1);
}
$session_user_id = 0;
$session_ip = 'cli';
$session_user_agent = 'endpoint_agent_publish.php';
// BinaryStore::publish() (rivet/rivet-core): the same validation as the upload form, no module switch involved.
$res = rivetRmmModule()->binaryStore()->publish($file, (string) $opts['version'], (string) $opts['arch'], 0, [
    'activate' => isset($opts['activate']),
    'release_ring' => $opts['release'] ?? null,
    'rollout_pct' => (int) ($opts['rollout'] ?? 10),
    'notes' => (string) ($opts['notes'] ?? ''),
]);
if (!$res['ok']) {
    fwrite(STDERR, 'Error: ' . $res['error'] . "\n");
    exit(1);
}
$b = $res['binary'];
logAction('Endpoint Agent', 'Binary Published', "CLI published agent binary {$b['version']} ({$b['arch']}, sha256 {$b['sha256']})" . (isset($opts['activate']) ? ' as current' : ''));
printf("%s %s %s  %s  %s bytes%s\n", $res['created'] ? 'Published' : 'Already published', $b['version'], $b['arch'], $b['sha256'], $b['size_bytes'], $b['is_current'] ? '  (current)' : '');
exit(0);

function Db_tableMissing(): bool
{
    global $mysqli;
    $r = mysqli_query($mysqli, "SHOW TABLES LIKE 'endpoint_agent_binaries'");
    return !$r || mysqli_num_rows($r) === 0;
}
