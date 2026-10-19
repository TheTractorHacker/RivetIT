#!/usr/bin/env php
<?php

// Small CLI behind deploy/setup-build-host.sh (RivetIT 26.10.58): creates, rotates, checks and disables the build host row through the app's own code (rivetBuildHost*),
// so the token is made, salted and hashed exactly as on the Agent updates page. Run it as the web server user: sudo -u www-data php scripts/build_host_setup.php <command> [--name=NAME]
//
//   create  --name=N   makes the host and prints ONE line, TOKEN=rbh_..., on stdout (nothing else goes to stdout). Exit 3 if the name exists.
//   rotate  --name=N   new token for an existing host (enables it again), same output
//   status  --name=N   prints "exists=0|1 enabled=0|1 host_id=N"
//   disable --name=N   disables the host (its waiting and running builds are cancelled); exit 3 if the name is unknown
//   server-url         prints the SERVER_URL for build-host.conf (<site>/api/v1/build_host)
//
// The token is never put in an argument, a log line, the audit text or stderr. The caller (a root shell script) reads stdout into a variable and writes it to a 0600 file.

chdir(__DIR__);

if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.\n");
}

require_once "../config.php";
require_once "../functions.php";
require_once "../vendor/autoload.php";
require_once "../includes/rmm_build_host.php";

mysqli_report(MYSQLI_REPORT_OFF);
$fail = static function (string $m, int $code = 1): never {
    fwrite(STDERR, "build_host_setup: $m\n");
    exit($code);
};
$cmd = $argv[1] ?? '';
$name = null;
foreach (array_slice($argv, 2) as $a) {
    if (str_starts_with($a, '--name=')) {
        $name = substr($a, 7);
    } else {
        $fail('unknown argument', 2);
    }
}
if (!in_array($cmd, ['create', 'rotate', 'status', 'disable', 'server-url'], true)) {
    $fail('usage: build_host_setup.php create|rotate|status|disable --name=NAME | server-url', 2);
}
if ($cmd === 'server-url') {
    $base = trim((string) ($config_base_url ?? ''));
    if ($base === '') {
        $fail('no base URL is configured (Administration > Settings)', 4);
    }
    echo (empty($config_https_only) ? 'http' : 'https') . '://' . preg_replace('#^https?://#i', '', rtrim($base, '/')) . "/api/v1/build_host\n";
    exit(0);
}
if ($name === null || preg_match('/^[\w .:@()\/+-]{1,80}\z/', $name) !== 1) {
    $fail('--name must be 1-80 characters: letters, numbers, spaces and . - _ : @ ( ) / +', 2);
}
if (!rivetBuildHostReady($mysqli)) {
    $fail('the build host tables are missing: run the database update first (Administration > Updates)', 4);
}
$e = mysqli_real_escape_string($mysqli, $name);
$r = mysqli_query($mysqli, "SELECT host_id, enabled FROM endpoint_agent_build_hosts WHERE name = '$e' ORDER BY host_id LIMIT 1");
$row = $r ? mysqli_fetch_assoc($r) : null;
switch ($cmd) {
    case 'status':
        echo 'exists=' . ($row ? 1 : 0) . ' enabled=' . ($row ? (int) $row['enabled'] : 0) . ' host_id=' . ($row ? (int) $row['host_id'] : 0) . "\n";
        exit(0);
    case 'create':
        if ($row) {
            $fail("a build host named \"$name\" exists already (use rotate for a new token)", 3);
        }
        $res = rivetBuildHostCreate($mysqli, $name, 0);
        if (!$res['ok']) {
            $fail($res['message']);
        }
        rivetBuildHostAudit('Agent Build Host Added', 'Build host "' . $name . '" was added by the setup script (deploy/setup-build-host.sh).');
        echo 'TOKEN=' . $res['token'] . "\n";
        exit(0);
    case 'rotate':
        if (!$row) {
            $fail("no build host named \"$name\"", 3);
        }
        $tok = rivetBuildHostRotate($mysqli, (int) $row['host_id']);
        if ($tok === null) {
            $fail('the token could not be replaced');
        }
        rivetBuildHostSetEnabled($mysqli, (int) $row['host_id'], true);
        rivetBuildHostAudit('Agent Build Host Token Rotated', 'Build host "' . $name . '" got a new token from the setup script.');
        echo 'TOKEN=' . $tok . "\n";
        exit(0);
    case 'disable':
        if (!$row) {
            $fail("no build host named \"$name\"", 3);
        }
        rivetBuildHostSetEnabled($mysqli, (int) $row['host_id'], false);
        rivetBuildHostAudit('Agent Build Host Disabled', 'Build host "' . $name . '" was disabled by the setup script (uninstall).');
        exit(0);
}
