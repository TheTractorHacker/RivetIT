<?php

/*
 * Standalone CLI entry point: what RivetCore's `scripts/rmm-store-migrate.php` and `scripts/rmm-store-verify.php` need (RivetCore\Rmm\Store\Cli\CliBootstrap,
 * "Adopting the data store setting and migration" in UPGRADING.md). Run as `--bootstrap=` of those scripts, as:
 *
 *   sudo -u www-data php vendor/rivet/rivet-core/scripts/rmm-store-migrate.php --bootstrap=includes/rmm_store_cli_bootstrap.php --from=mariadb --to=postgres --dry-run
 *
 * This file is `require`d by CliBootstrap::load(), so it bootstraps itself (a web request's config.php is not loaded in a CLI process) and returns the array
 * CliBootstrap::fromArray() expects. Keep it readable by the service account only (it reaches the control database directly, like config.php).
 */

if (!defined('FROM_API')) {
    define('FROM_API', true);   // short-circuits any session/auth bootstrapping in shared includes; this is a CLI-only script
}

$root = dirname(__DIR__);
require_once $root . '/config.php';
require_once $root . '/vendor/autoload.php';
require_once $root . '/includes/event_bus.php';   // rivetCoreDb()

use ITFlow\Core\Adapter\Endpoint\EndpointAudit;
use ITFlow\Core\Adapter\Endpoint\EndpointSecretBox;
use RivetCore\Support\SystemClock;

/** @var \mysqli $mysqli set by config.php */
return [
    'database' => rivetCoreDb($mysqli),
    'clock' => new SystemClock(),
    'secretBox' => new EndpointSecretBox(),
    'audit' => new EndpointAudit(),
];
