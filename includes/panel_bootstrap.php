<?php

/*
 * RivetPanel module bootstrap: builds RivetCore\Panel\PanelModule from RivetIT's adapters.
 *
 * RivetPanel (the desktop ticket/chat helper authenticated with a per-device token, see vendor/rivet/rivet-core/docs/panel/PANEL_API.md) shares
 * the RMM module's tables (endpoint_agent_devices/enrollment_tokens with kind = 'panel'), tenancy and audit ports, but NOT the RMM module itself -
 * it works whether or not RMM is switched on (UPGRADING.md "Adopting the RivetPanel API", step 3). The only new adapter is the ticket gateway
 * (src/Core/Adapter/Endpoint/EndpointPanelGateway.php); EndpointTenancy and EndpointAudit are reused unchanged from includes/rmm_bootstrap.php.
 *
 * Master switch (rc.38, Prompt 1 step 4): PlatformSettings 'panel_enabled', OFF by default. PanelModule's 'enabled' option gates PanelApi - with it off,
 * every panel_* route answers 503 module_disabled before touching tenancy/gateway, even if a token were somehow minted or an old device credential still
 * exists. rivetPanelEnabled()/rivetPanelSetEnabled() read and flip it; the Deploy page (Admin > RivetPanel) is the only UI that calls the setter.
 * The per-client "allow tickets from client-level devices" switch is separate: PanelModule::settings(), also default off.
 */

require_once __DIR__ . '/event_bus.php';   // rivetCoreDb()

use ITFlow\Core\Adapter\Endpoint\EndpointAudit;
use ITFlow\Core\Adapter\Endpoint\EndpointPanelGateway;
use ITFlow\Core\Adapter\Endpoint\EndpointTenancy;
use ITFlow\Platform\PlatformSettings;
use RivetCore\Panel\PanelModule;
use RivetCore\Support\SystemClock;

/** Is the RivetPanel service switched on? Off by default until an administrator turns it on. */
function rivetPanelEnabled($mysqli = null): bool
{
    $mysqli ??= $GLOBALS['mysqli'] ?? null;
    if (!$mysqli instanceof \mysqli) {
        return false;
    }

    return PlatformSettings::bool($mysqli, 'panel_enabled');
}

function rivetPanelSetEnabled(\mysqli $mysqli, bool $enabled): void
{
    PlatformSettings::set($mysqli, 'panel_enabled', $enabled ? '1' : '0');
}

/**
 * The module for this request's database connection (built once per connection, same pattern as rivetRmmModule()).
 *
 * @param \mysqli|null $mysqli defaults to the global connection
 */
function rivetPanelModule($mysqli = null): PanelModule
{
    static $built = [];
    $mysqli ??= $GLOBALS['mysqli'] ?? null;
    if (!$mysqli instanceof \mysqli) {
        throw new \RuntimeException('The RivetPanel module needs the database connection.');
    }
    $key = spl_object_id($mysqli);
    if (isset($built[$key]) && $built[$key][0] === $mysqli) {
        return $built[$key][1];
    }
    $module = new PanelModule(
        rivetCoreDb($mysqli),
        new SystemClock(),
        new EndpointTenancy(rivetCoreDb($mysqli)),
        new EndpointAudit(),
        new EndpointPanelGateway($mysqli),
        [
            'enabled' => static fn (): bool => rivetPanelEnabled($mysqli),
            // Same escape hatch as rivetRmmModule(): loopback test servers only, never set in production.
            'allow_insecure_http' => defined('EA_ALLOW_INSECURE_HTTP') && EA_ALLOW_INSECURE_HTTP === true,
        ],
    );
    $built[$key] = [$mysqli, $module];

    return $module;
}
