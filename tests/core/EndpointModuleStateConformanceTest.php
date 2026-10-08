<?php

declare(strict_types=1);

require_once __DIR__ . '/EndpointKit.php';

use RivetCore\Rmm\Contracts\RmmModuleStateInterface;
use RivetCore\Testing\RmmModuleStateConformanceTestCase;

/** EndpointModuleState: RivetIT has no edition kill switch; the state directory is backups/rmm-state (created on demand by the first write). */
final class EndpointModuleStateConformanceTest extends RmmModuleStateConformanceTestCase
{
    protected function state(): RmmModuleStateInterface
    {
        $dir = sys_get_temp_dir() . '/rmm_state_conf_' . getmypid();
        @mkdir($dir, 0750, true);
        if (!defined('RMM_STATE_DIR')) {
            define('RMM_STATE_DIR', $dir);
        }

        return new \ITFlow\Core\Adapter\Endpoint\EndpointModuleState();
    }
}
