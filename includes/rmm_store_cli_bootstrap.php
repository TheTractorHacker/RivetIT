<?php

/*
 * Standalone CLI entry point: what RivetCore's `scripts/rmm-store-migrate.php` and `scripts/rmm-store-verify.php` need (RivetCore\Rmm\Store\Cli\CliBootstrap,
 * "Adopting the data store setting and migration" in UPGRADING.md). Run as `--bootstrap=` of those scripts, as:
 *
 *   sudo -u www-data php vendor/rivet/rivet-core/scripts/rmm-store-migrate.php --bootstrap=includes/rmm_store_cli_bootstrap.php --from=mariadb --to=postgres --dry-run
 *
 * This file is `require`d by CliBootstrap::load(), so it bootstraps itself (a web request's config.php is not loaded in a CLI process) and returns the array
 * CliBootstrap::fromArray() expects. Keep it readable by the service account only (it reaches the control database directly, like config.php).
 *
 * CliBootstrap::load() requires this file from inside an anonymous function:
 *   $cfg = (static function (string $f): mixed { return require $f; })($file);
 * so everything this file itself requires (including config.php) runs in THAT closure's local scope, not the global one. RivetIT's
 * decrypt path (functions.php's decryptSetting() -> SettingsCrypto -> KeyStore::legacyKey()) reads $GLOBALS['config_settings_enc_key']
 * directly - never a local variable - to support the legacy ENC2 cipher (and the v3 vault's own legacy-reader for values not yet
 * rewrapped). Without the explicit copy below, a sealed value in ENC2 form reads back as '' through this CLI path (silently - decrypt()
 * never throws), even though the exact same value decrypts fine in a web request. A v3-sealed value happens to still work (the v3 key
 * ring loads from its own keystore file, not from config.php), which is why this was missed until an ENC2 install needed the CLI.
 */

if (!defined('FROM_API')) {
    define('FROM_API', true);   // short-circuits any session/auth bootstrapping in shared includes; this is a CLI-only script
}

$root = dirname(__DIR__);
require_once $root . '/config.php';
foreach (get_defined_vars() as $rivetCliVar => $rivetCliVal) {
    if (!array_key_exists($rivetCliVar, $GLOBALS)) {
        $GLOBALS[$rivetCliVar] = $rivetCliVal;
    }
}
require_once $root . '/vendor/autoload.php';
require_once $root . '/functions.php';   // encryptSetting()/decryptSetting(), used by EndpointSecretBox below
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
