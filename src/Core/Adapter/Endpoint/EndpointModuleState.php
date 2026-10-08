<?php

declare(strict_types=1);

namespace ITFlow\Core\Adapter\Endpoint;

use RivetCore\Rmm\Contracts\RmmModuleStateInterface;

/**
 * RivetIT has no edition-level kill switch for the RMM module (its `settings` row is at the row-size limit): the master switch in
 * endpoint_agent_settings.enabled is the only one, so the edition always allows. The state file lives in the denied `backups/`
 * area (default backups/rmm-state, overridable by RMM_STATE_DIR in config.php; an empty string disables the zero-database fast path).
 * The pre-bootstrap gate api/v1/rmm_gate.php reads the same default directory, or RMM_GATE_STATE_DIR from the web server environment.
 */
final class EndpointModuleState implements RmmModuleStateInterface
{
    public function editionAllows(): bool
    {
        return true;
    }

    public function stateDirectory(): ?string
    {
        return self::directory();
    }

    public static function directory(): ?string
    {
        if (defined('RMM_STATE_DIR')) {
            $dir = (string) constant('RMM_STATE_DIR');

            return $dir === '' ? null : $dir;
        }

        return dirname(__DIR__, 4) . '/backups/rmm-state';
    }
}
