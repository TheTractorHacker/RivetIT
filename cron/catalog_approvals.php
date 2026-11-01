<?php
/*
 * catalog_approvals.php - service catalog approval worker (DB 2.6.212, issue #30)
 *
 * One pass over requests that are waiting for approval: moves pending approvals from agents who have since gone out of office to their
 * delegates, sends reminders to approvers who have not decided, escalates a stage to the administrators and rejects a request nobody
 * decided in time. Each behaviour is per catalog item (hours; 0 = off), and the whole worker stays OFF until an administrator switches
 * it on (Administration > Service Catalog, settings.config_catalog_approval_cron), so installing this changes nothing by itself.
 * Reminders go out as in-app notifications and as email through the normal mail queue (nothing is sent directly from here).
 *
 * WIRING: required from cron/cron.php on its normal cadence. Also runnable standalone from the CLI:  php cron/catalog_approvals.php
 * The bootstrap below only loads config/functions when they are not already present, so it is safe to require_once from cron.php.
 */

$catalog_approvals_standalone = !isset($mysqli);

if ($catalog_approvals_standalone) {
    chdir(dirname(__FILE__));

    if (php_sapi_name() !== 'cli') {
        die("This script must be run from the command line.\n");
    }

    require_once "../config.php";
    require_once "../includes/inc_set_timezone.php";
    require_once "../functions.php";
    require_once dirname(__DIR__) . '/includes/redis_guards.php';
    rivetCronGuard('catalog_approvals', 600);
}

require_once dirname(__DIR__) . '/src/ITSM/ServiceCatalogService.php';

$catalog_approvals_result = null;
if (\ITFlow\ITSM\ServiceCatalogService::timeoutsEnabled($mysqli)) {
    try {
        $catalog_approvals_result = (new \ITFlow\ITSM\ServiceCatalogService($mysqli))->processTimeouts();
        $catalog_approvals_total = array_sum($catalog_approvals_result);
        if ($catalog_approvals_total > 0 && function_exists('logApp')) {
            logApp("Cron", "info", "Service catalog approvals: " . json_encode($catalog_approvals_result));
        }
    } catch (\Throwable $e) {
        error_log('catalog_approvals: ' . $e->getMessage());
        if (function_exists('logApp')) {
            logApp("Cron", "error", "Service catalog approvals failed: " . $e->getMessage());
        }
    }
}

if ($catalog_approvals_standalone) {
    echo $catalog_approvals_result === null
        ? "Service catalog approval worker is switched off (Administration > Service Catalog).\n"
        : "Service catalog approvals: " . json_encode($catalog_approvals_result) . "\n";
}
