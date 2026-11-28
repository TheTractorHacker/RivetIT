<?php
declare(strict_types=1);
/* IT-INJ-9: a ticket cannot reference another department's contact/asset/location/vendor. */
require __DIR__ . '/secauthz_bootstrap.php';
sec_fn(['ticketReferenceOutsideClient']);
function resolveTicketAssignee($x) { return (int) $x; }
function resolveTicketCreationStatus($x) { return 1; }
function resolveTicketCategory($x) { return (int) $x; }
sec_reset_fixture();
$q("INSERT INTO contacts SET contact_id = 1, contact_name = 'A1', contact_client_id = 1");
$q("INSERT INTO contacts SET contact_id = 2, contact_name = 'B1', contact_client_id = 2, contact_email = 'b1@b.example'");
$q("INSERT INTO assets SET asset_id = 1, asset_name = 'A-pc', asset_client_id = 1");
$q("INSERT INTO assets SET asset_id = 2, asset_name = 'B-pc', asset_client_id = 2");
$q("INSERT INTO locations SET location_id = 2, location_name = 'B-loc', location_client_id = 2");
$q("INSERT INTO vendors SET vendor_id = 1, vendor_name = 'Global', vendor_client_id = 0");
$q("INSERT INTO vendors SET vendor_id = 2, vendor_name = 'B-vendor', vendor_client_id = 2");
$q("INSERT INTO tickets SET ticket_id = 1, ticket_prefix = 'T', ticket_number = 1, ticket_subject = 's', ticket_client_id = 1, ticket_contact_id = 1, ticket_asset_id = 1");
$GLOBALS['T_perm'] = ['module_support' => 2, 'module_assets' => 2];

// helper
$ok(ticketReferenceOutsideClient(1, 1, 1, 0, 1) === null, "helper: own contact/asset and a global vendor pass");
$ok(ticketReferenceOutsideClient(1, 0, 0, 0, 0) === null, "helper: empty references pass");
$ok(ticketReferenceOutsideClient(1, 2) === 'contact' && ticketReferenceOutsideClient(1, 0, 2) === 'asset' && ticketReferenceOutsideClient(1, 0, 0, 2) === 'location' && ticketReferenceOutsideClient(1, 0, 0, 0, 2) === 'vendor', "helper: foreign contact/asset/location/vendor are flagged");
$ok(ticketReferenceOutsideClient(1, 0, 1, 0, 0, [1, 2]) === 'asset', "helper: a foreign additional asset is flagged");

// add_ticket: foreign contact is refused before a ticket number is consumed
$r = sec_run('agent/post/ticket.php', 'add_ticket', ['add_ticket' => 1, 'csrf_token' => 't', 'client_id' => 1, 'assigned_to' => 0, 'contact_id' => 2, 'category_id' => 0, 'subject' => 's', 'priority' => 'Low', 'details' => 'd', 'vendor_ticket_number' => '', 'vendor_id' => 0, 'asset_id' => 0, 'location_id' => 0, 'ticket_template_id' => 0]);
$ok((int) $one("SELECT COUNT(*) FROM tickets") === 1 && str_starts_with($r, 'redirect:'), "add_ticket: foreign-department contact refused, no ticket created ($r)");
$r = sec_run('agent/post/ticket.php', 'add_ticket', ['add_ticket' => 1, 'csrf_token' => 't', 'client_id' => 1, 'assigned_to' => 0, 'contact_id' => 1, 'category_id' => 0, 'subject' => 's', 'priority' => 'Low', 'details' => 'd', 'vendor_ticket_number' => '', 'vendor_id' => 0, 'asset_id' => 2, 'location_id' => 0, 'ticket_template_id' => 0]);
$ok((int) $one("SELECT COUNT(*) FROM tickets") === 1, "add_ticket: foreign-department asset refused ($r)");

// edit_ticket_contact
$r = sec_run('agent/post/ticket.php', 'edit_ticket_contact', ['edit_ticket_contact' => 1, 'csrf_token' => 't', 'ticket_id' => 1, 'contact' => 2, 'contact_notify' => 0]);
$ok((int) $one("SELECT ticket_contact_id FROM tickets WHERE ticket_id = 1") === 1, "edit_ticket_contact: foreign contact refused ($r)");
// edit_ticket_asset
$r = sec_run('agent/post/ticket.php', 'edit_ticket_asset', ['edit_ticket_asset' => 1, 'csrf_token' => 't', 'ticket_id' => 1, 'asset' => 2]);
$ok((int) $one("SELECT ticket_asset_id FROM tickets WHERE ticket_id = 1") === 1, "edit_ticket_asset: foreign asset refused ($r)");
$r = sec_run('agent/post/ticket.php', 'edit_ticket_asset', ['edit_ticket_asset' => 1, 'csrf_token' => 't', 'ticket_id' => 1, 'asset' => 1, 'additional_assets' => [2]]);
$ok((int) $one("SELECT COUNT(*) FROM ticket_assets WHERE ticket_id = 1 AND asset_id = 2") === 0, "edit_ticket_asset: foreign additional asset refused ($r)");
// legitimate edits still work (these run the real handler to its end)
$r = sec_run('agent/post/ticket.php', 'edit_ticket_asset', ['edit_ticket_asset' => 1, 'csrf_token' => 't', 'ticket_id' => 1, 'asset' => 1]);
$ok($r !== 'denied:' && (int) $one("SELECT ticket_asset_id FROM tickets WHERE ticket_id = 1") === 1 && !preg_grep('/does not belong/', array_column($GLOBALS['T_flash'], 0)), "edit_ticket_asset: own asset is accepted ($r)");
exit($T_fails ? 1 : 0);
