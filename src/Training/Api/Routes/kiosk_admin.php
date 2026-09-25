<?php

/*
 * Training JSON routes for devices and PINs (P3 spec §4.3, lane K2). Every route is module_training
 * level 1 here; each handler then checks module_training_kiosk (Access::apiKiosk) at its own level
 * and, for per-person actions, the fail-closed scope and the trainer rule.
 */

use ITFlow\Training\Api\KioskAdminActions;

return [
    'kiosk_list'            => ['handler' => KioskAdminActions::class . '::kioskList',          'method' => 'GET',  'level' => 1],
    'kiosk_asset_options'   => ['handler' => KioskAdminActions::class . '::kioskAssetOptions',  'method' => 'GET',  'level' => 1],
    'kiosk_enroll_here'     => ['handler' => KioskAdminActions::class . '::kioskEnrollHere',    'method' => 'POST', 'level' => 1],
    'kiosk_enroll_code'     => ['handler' => KioskAdminActions::class . '::kioskEnrollCode',    'method' => 'POST', 'level' => 1],
    'kiosk_revoke'          => ['handler' => KioskAdminActions::class . '::kioskRevoke',        'method' => 'POST', 'level' => 1],
    'kiosk_reissue'         => ['handler' => KioskAdminActions::class . '::kioskReissue',       'method' => 'POST', 'level' => 1],
    'kiosk_clear_cooldown'  => ['handler' => KioskAdminActions::class . '::kioskClearCooldown', 'method' => 'POST', 'level' => 1],
    'pin_clear_pause'       => ['handler' => KioskAdminActions::class . '::pinClearPause',      'method' => 'POST', 'level' => 1],
    'pin_people'            => ['handler' => KioskAdminActions::class . '::pinPeople',          'method' => 'GET',  'level' => 1],
    'pin_unlock'            => ['handler' => KioskAdminActions::class . '::pinUnlock',          'method' => 'POST', 'level' => 1],
    'pin_slips_issue'       => ['handler' => KioskAdminActions::class . '::pinSlipsIssue',      'method' => 'POST', 'level' => 1],
    'pin_slips_clear'       => ['handler' => KioskAdminActions::class . '::pinSlipsClear',      'method' => 'POST', 'level' => 1],
    'pin_odoo_unblock'      => ['handler' => KioskAdminActions::class . '::pinOdooUnblock',     'method' => 'POST', 'level' => 1],
    'pin_sources_refresh'   => ['handler' => KioskAdminActions::class . '::pinSourcesRefresh',  'method' => 'POST', 'level' => 1],
    'pin_odoo_bulk_unblock' => ['handler' => KioskAdminActions::class . '::pinOdooBulkUnblock', 'method' => 'POST', 'level' => 1],
];
