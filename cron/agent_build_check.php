<?php

/*
 * RivetIT 26.10.55 - follow an agent build started from Administration > Endpoint agent > Agent updates > "Build a new version".
 *
 * Does something only while a build is running (or a finished one still has to be published automatically); otherwise it exits at once without touching the network.
 * It asks GitHub how the run is doing, refreshes the release list when the run finished, and publishes the release if "publish automatically when built" was ticked
 * (as the user who started the build; the publish rules and permissions are the card's). The card does the same while someone has the page open; this makes it work
 * when nobody does.
 *
 * Schedule: /etc/cron.d/rivetit-<name>   every 5 minutes, www-data /usr/bin/php <app>/cron/agent_build_check.php >> /var/log/<log> 2>&1   (the log file must exist and be writable by www-data)
 * Must run as www-data (config.php is www-data 0640). Prints one line when it did something.
 */

chdir(dirname(__FILE__));

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once "../config.php";
require_once "../includes/inc_set_timezone.php";
require_once "../functions.php";
require_once dirname(__DIR__) . '/includes/redis_guards.php';
rivetCronGuard('agent_build_check', 900);
require_once dirname(__DIR__) . '/includes/rmm_agent_build.php';

mysqli_report(MYSQLI_REPORT_OFF);

if (!rivetAgentBuildReady($mysqli)) {
    exit(0);
}
$row = rivetAgentBuildRow($mysqli);
if (!rivetAgentBuildIsActive($row) && !rivetAgentBuildAutoPending($row) && !in_array($row['run_state'], ['dispatching', 'queued', 'building'], true)) {
    exit(0);
}
$res = rivetAgentBuildTick($mysqli, rivetRmmModule($mysqli), false);
$after = rivetAgentBuildRow($mysqli);
echo date('Y-m-d H:i:s') . ' agent build ' . $after['build_version'] . ': ' . $after['run_state'] . ($after['auto_state'] !== '' ? ', auto publish ' . $after['auto_state'] : '') . ($res['message'] !== '' ? ' - ' . $res['message'] : '') . "\n";
