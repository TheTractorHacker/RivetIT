<?php
declare(strict_types=1);
/* Recurring tickets: contact/assets must belong to the ticket's department (same helper as IT-INJ-9). */
require __DIR__ . '/secauthz_bootstrap.php';
sec_fn(['ticketReferenceOutsideClient']);
chdir(SECAUTHZ_ROOT . '/agent/post'); set_include_path('.');   // the handler requires its model by bare name
sec_reset_fixture();
foreach (['recurring_tickets', 'recurring_ticket_assets'] as $t) { $q("DELETE FROM $t"); }
$q("INSERT INTO contacts SET contact_id = 1, contact_name = 'A1', contact_client_id = 1");
$q("INSERT INTO contacts SET contact_id = 2, contact_name = 'B1', contact_client_id = 2");
$q("INSERT INTO assets SET asset_id = 1, asset_name = 'A-pc', asset_client_id = 1");
$q("INSERT INTO assets SET asset_id = 2, asset_name = 'B-pc', asset_client_id = 2");
$GLOBALS['T_perm'] = ['module_support' => 2];
$base = ['csrf_token' => 't', 'subject' => 's', 'priority' => 'Low', 'details' => 'd', 'frequency' => 'month', 'start_date' => '2026-12-01', 'next_date' => '2026-12-01', 'assigned_to' => 0, 'category_id' => 0, 'billable' => 0];
$n = fn() => (int) $one("SELECT COUNT(*) FROM recurring_tickets");

$r = sec_run('agent/post/recurring_ticket.php', 'add_recurring_ticket', $base + ['add_recurring_ticket' => 1, 'client_id' => 1, 'contact_id' => 2, 'asset_id' => 0]);
$ok($n() === 0, "add: foreign-department contact refused ($r)");
$r = sec_run('agent/post/recurring_ticket.php', 'add_recurring_ticket', $base + ['add_recurring_ticket' => 1, 'client_id' => 1, 'contact_id' => 1, 'asset_id' => 2]);
$ok($n() === 0, "add: foreign-department asset refused ($r)");
$r = sec_run('agent/post/recurring_ticket.php', 'add_recurring_ticket', $base + ['add_recurring_ticket' => 1, 'client_id' => 1, 'contact_id' => 1, 'asset_id' => 1, 'additional_assets' => [2]]);
$ok($n() === 0 && (int) $one("SELECT COUNT(*) FROM recurring_ticket_assets") === 0, "add: foreign additional asset refused ($r)");
$r = sec_run('agent/post/recurring_ticket.php', 'add_recurring_ticket', $base + ['add_recurring_ticket' => 1, 'client_id' => 1, 'contact_id' => 1, 'asset_id' => 1, 'additional_assets' => [1]]);
$ok($n() === 1 && (int) $one("SELECT COUNT(*) FROM recurring_ticket_assets") === 1, "add: own contact/assets still work ($r)");
$rid = (int) $one("SELECT recurring_ticket_id FROM recurring_tickets LIMIT 1");
$r = sec_run('agent/post/recurring_ticket.php', 'edit_recurring_ticket', $base + ['edit_recurring_ticket' => 1, 'recurring_ticket_id' => $rid, 'contact_id' => 2, 'asset_id' => 1]);
$ok((int) $one("SELECT recurring_ticket_contact_id FROM recurring_tickets WHERE recurring_ticket_id = $rid") === 1, "edit: foreign-department contact refused ($r)");
$r = sec_run('agent/post/recurring_ticket.php', 'edit_recurring_ticket', $base + ['edit_recurring_ticket' => 1, 'recurring_ticket_id' => $rid, 'contact_id' => 1, 'asset_id' => 2]);
$ok((int) $one("SELECT recurring_ticket_asset_id FROM recurring_tickets WHERE recurring_ticket_id = $rid") === 1, "edit: foreign-department asset refused ($r)");
$r = sec_run('agent/post/recurring_ticket.php', 'edit_recurring_ticket', array_merge($base, ['edit_recurring_ticket' => 1, 'recurring_ticket_id' => $rid, 'contact_id' => 1, 'asset_id' => 1, 'subject' => 'renamed']));
$ok($one("SELECT recurring_ticket_subject FROM recurring_tickets WHERE recurring_ticket_id = $rid") === 'renamed', "edit: own contact/asset still work ($r)");
exit($T_fails ? 1 : 0);
