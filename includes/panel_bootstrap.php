<?php

/*
 * RivetPanel module bootstrap: builds RivetCore\Panel\PanelModule from RivetIT's adapters.
 *
 * RivetPanel (the desktop ticket/chat helper authenticated with a per-device token, see vendor/rivet/rivet-core/docs/panel/PANEL_API.md) shares
 * the RMM module's tables (endpoint_agent_devices/enrollment_tokens with kind = 'panel'), tenancy and audit ports, but NOT the RMM module itself -
 * it works whether or not RMM is switched on (UPGRADING.md "Adopting the RivetPanel API", step 3). The only new adapter is the ticket gateway
 * (src/Core/Adapter/Endpoint/EndpointPanelGateway.php); EndpointTenancy and EndpointAudit are reused unchanged from includes/rmm_bootstrap.php.
 *
 * There is no master on/off switch for the panel service itself: PanelModule::api() defaults to enabled (PanelApi's own default). Nobody can reach
 * it until an administrator mints an enrollment token, which only the Deploy page UI exposes, so there is nothing to switch on behaviourally.
 * The per-client "allow tickets from client-level devices" switch is PanelModule::settings(), default off.
 */

require_once __DIR__ . '/event_bus.php';   // rivetCoreDb()

use ITFlow\Core\Adapter\Endpoint\EndpointAudit;
use ITFlow\Core\Adapter\Endpoint\EndpointPanelGateway;
use ITFlow\Core\Adapter\Endpoint\EndpointTenancy;
use RivetCore\Panel\PanelModule;
use RivetCore\Support\SystemClock;

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
        [],
    );
    $built[$key] = [$mysqli, $module];

    return $module;
}
