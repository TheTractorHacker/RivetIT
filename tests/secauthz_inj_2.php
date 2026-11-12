<?php
declare(strict_types=1);
/* IT-INJ-2: client_set_notes / contact_set_notes / asset_set_notes must enforce department access. */
require __DIR__ . '/secauthz_bootstrap.php';
sec_reset_fixture();
$q("INSERT INTO contacts SET contact_id = 1, contact_name = 'A1', contact_client_id = 1, contact_notes = 'orig'");
$q("INSERT INTO contacts SET contact_id = 2, contact_name = 'B1', contact_client_id = 2, contact_notes = 'orig'");
$q("INSERT INTO assets SET asset_id = 1, asset_name = 'A-pc', asset_client_id = 1, asset_notes = 'orig'");
$q("INSERT INTO assets SET asset_id = 2, asset_name = 'B-pc', asset_client_id = 2, asset_notes = 'orig'");
$GLOBALS['T_perm'] = ['module_client' => 2, 'module_assets' => 2];
$F = 'agent/ajax.php';

foreach ([
    ['client_set_notes',  'client_id',  1, 2, "SELECT client_notes FROM clients WHERE client_id = %d"],
    ['contact_set_notes', 'contact_id', 1, 2, "SELECT contact_notes FROM contacts WHERE contact_id = %d"],
    ['asset_set_notes',   'asset_id',   1, 2, "SELECT asset_notes FROM assets WHERE asset_id = %d"],
] as [$key, $idf, $own, $foreign, $sel]) {
    $r = sec_run($F, $key, [$key => 1, 'csrf_token' => 't', $idf => $foreign, 'notes' => 'PWNED']);
    $ok(str_starts_with($r, 'redirect:') && $one(sprintf($sel, $foreign)) !== 'PWNED', "$key: restricted user cannot overwrite another department's notes (got $r)");
    $r = sec_run($F, $key, [$key => 1, 'csrf_token' => 't', $idf => $own, 'notes' => 'mine']);
    $ok($r === 'done' && $one(sprintf($sel, $own)) === 'mine', "$key: own department still works");
    $q("UPDATE clients SET client_notes='b' WHERE client_id=2");
    $GLOBALS['session_is_admin'] = true;
    $r = sec_run($F, $key, [$key => 1, 'csrf_token' => 't', $idf => $foreign, 'notes' => 'admin']);
    $ok($r === 'done' && $one(sprintf($sel, $foreign)) === 'admin', "$key: administrator unaffected");
    $GLOBALS['session_is_admin'] = false;
}
exit($T_fails ? 1 : 0);
