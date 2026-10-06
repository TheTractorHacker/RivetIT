<?php
/*
 * HTTP walk of the findings round-up (issue #29) against a throwaway instance: scratch DB + `php -S` with a FORGED
 * administrator session, no real login, no email. Set up like tests/reports_http_walk.php:
 *   - serve the app with `php -S 127.0.0.1:PORT -d session.save_path=SESSDIR -t <app dir>` (config.php pointing at the scratch DB,
 *     $config_https_only = FALSE)
 *   - RIVETIT_WALK_BASE=http://127.0.0.1:PORT RIVETIT_WALK_SESSION_DIR=SESSDIR RIVETIT_WALK_DOCROOT=<app dir>
 *     RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=...scratch... RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=...
 * Seeds its own rows; refuses non-scratch databases. Covers: A2 sort links, B2 edit schedule, B3 vendor warning payload,
 * B10 permanent-delete switch (and the Delete buttons that follow it), C1 archived bulk Delete confirmation.
 */
require __DIR__ . '/reports_bootstrap.php';
$base = rtrim((string) getenv('RIVETIT_WALK_BASE'), '/');
$sessdir = (string) getenv('RIVETIT_WALK_SESSION_DIR');
$docroot = (string) getenv('RIVETIT_WALK_DOCROOT');
if ($base === '' || $sessdir === '' || $docroot === '') { echo "set RIVETIT_WALK_BASE, RIVETIT_WALK_SESSION_DIR, RIVETIT_WALK_DOCROOT\n"; exit(2); }

function http(string $method, string $path, array $post = [], array $headers = []): array {
    global $base;
    $ch = curl_init($base . $path);
    $h = array_merge(['Cookie: PHPSESSID=walkadmin'], $headers);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 90]);
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $raw = curl_exec($ch);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $r = ['code' => curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'headers' => substr((string) $raw, 0, $hs), 'body' => substr((string) $raw, $hs)];
    curl_close($ch);
    return $r;
}
$tok = 'walkcsrf0123456789abcdef0123456789';

// --- seed: role, admin user, forged session, a department with a ticket, an archived printer, an RMM alert
foreach (['user_roles', 'users', 'user_settings', 'clients', 'tickets', 'printers', 'rmm_alerts', 'rmm_integrations', 'logs', 'ticket_statuses'] as $t) { $q("DELETE FROM $t"); }
$q("INSERT INTO user_roles SET role_id = 1, role_name = 'Administrator', role_is_admin = 1");
$q("INSERT INTO users SET user_id = 1, user_name = 'Walk Admin', user_email = 'walk@example.test', user_password = 'x', user_role_id = 1, user_type = 1, user_status = 1");
$q("INSERT INTO user_settings SET user_id = 1");
$q("INSERT INTO clients SET client_id = 1, client_name = 'Dept A', client_type = ''");
$q("INSERT INTO ticket_statuses SET ticket_status_id = 1, ticket_status_name = 'Open', ticket_status_color = '#000'");
$q("INSERT INTO tickets SET ticket_id = 1, ticket_prefix = 'T', ticket_number = 1, ticket_subject = 'Walk ticket', ticket_status = 1, ticket_priority = 'Low', ticket_client_id = 1, ticket_assigned_to = 1");
$q("INSERT INTO printers SET printer_name = 'Archived printer', printer_client_id = 1, printer_archived_at = NOW()");
$q("INSERT INTO rmm_integrations SET id = 1, name = 'Walk', type = 'sophos', api_url = 'http://127.0.0.1:9', api_key_enc = 'x'");
$q("INSERT INTO rmm_alerts SET id = 1, integration_id = 1, tactical_alert_id = 'a1', client_id = 1, status = 'new', severity = 'error', message = 'walk'");
$q("UPDATE settings SET config_destructive_deletes_enable = 0 WHERE company_id = 1");
file_put_contents("$sessdir/sess_walkadmin", 'logged|b:1;user_id|i:1;csrf_token|s:' . strlen($tok) . ':"' . $tok . '";');

// --- A2: first click on a different column sorts ASC (3 list pages)
foreach (['/agent/clients.php' => ['client_name', 'contact_name'], '/agent/contacts.php' => ['contact_name', 'contact_email'], '/agent/assets.php' => ['asset_name', 'asset_type']] as $page => [$active, $other]) {
    $r = http('GET', "$page?sort=$active&order=ASC");
    $okpage = $r['code'] === 200;
    preg_match_all('/sort=' . $other . '&(?:amp;)?order=(ASC|DESC)/', $r['body'], $m1);
    preg_match_all('/sort=' . $active . '&(?:amp;)?order=(ASC|DESC)/', $r['body'], $m2);
    $ok($okpage && $m1[1] && $m1[1][0] === 'ASC', "$page: with $active ASC active, the heading for $other links to ASC (was DESC)" . ($okpage ? '' : " [HTTP {$r['code']}]"));
    $ok($okpage && $m2[1] && $m2[1][0] === 'DESC', "$page: the active ASC column $active links to DESC");
    $r = http('GET', "$page?sort=$active&order=DESC");
    preg_match_all('/sort=' . $other . '&(?:amp;)?order=(ASC|DESC)/', $r['body'], $m3);
    $ok($m3[1] && $m3[1][0] === 'ASC', "$page: with $active DESC active, $other links to ASC");
}

// --- B2: Edit schedule on the ticket page
$r = http('GET', '/agent/ticket.php?ticket_id=1');
$ok($r['code'] === 200 && substr_count($r['body'], 'modals/ticket/ticket_edit_schedule.php?ticket_id=1') >= 2, 'ticket page links to Edit schedule (schedule line and menu item)');
$r = http('GET', '/agent/modals/ticket/ticket_edit_schedule.php?ticket_id=1');
$ok($r['code'] === 200 && strpos($r['body'], 'edit_ticket_schedule') !== false && strpos($r['body'], 'Onsite') !== false, 'the schedule pop-up loads');
$r = http('POST', '/agent/post.php', ['csrf_token' => $tok, 'edit_ticket_schedule' => 1, 'ticket_id' => 1, 'onsite' => 1, 'scheduled_date_time' => date('Y-m-d', strtotime('+2 days')) . 'T10:00', 'scheduled_end_time' => date('Y-m-d', strtotime('+2 days')) . 'T11:00', 'appointment_notes' => 'Gate code 1234'], ['Referer: ' . $base . '/agent/ticket.php?ticket_id=1']);
$row = mysqli_fetch_assoc($q("SELECT ticket_onsite, ticket_schedule, ticket_schedule_end, ticket_appointment_notes FROM tickets WHERE ticket_id = 1"));
$ok($row && (int) $row['ticket_onsite'] === 1 && $row['ticket_schedule'] !== null && $row['ticket_appointment_notes'] === 'Gate code 1234', 'saving the pop-up sets ticket_onsite, start, end and notes');
$r = http('GET', '/agent/ticket.php?ticket_id=1');
$ok(strpos($r['body'], '· Onsite') !== false, 'the ticket page then shows the appointment as Onsite');
$conf = file_get_contents($docroot . '/config.php');
preg_match("/\\\$installation_id = '([^']+)'/", $conf, $im);
$feed = http('GET', '/agent/calendar_feed.php?token=' . hash_hmac('sha256', '1', $im[1] ?? ''));
$ok($feed['code'] === 200 && strpos($feed['body'], 'BEGIN:VEVENT') !== false && strpos($feed['body'], 'Onsite') !== false, 'calendar_feed.php lists the scheduled ticket and labels it Onsite');

// --- B3: vendor warning is returned for a vendor that cannot acknowledge, and the page carries the toast code
$r = http('POST', '/agent/post/rmm_alert.php', ['csrf_token' => $tok, 'action' => 'acknowledge', 'alert_id' => 1]);
$j = json_decode($r['body'], true);
$ok(is_array($j) && !empty($j['success']) && !empty($j['vendor_warning']), 'acknowledge succeeds locally and returns a vendor_warning');
$ok(mysqli_fetch_assoc($q("SELECT status FROM rmm_alerts WHERE id = 1"))['status'] === 'acknowledged', 'the local acknowledge still happened');
$r = http('GET', '/agent/rmm_alerts.php?status=all');
$ok($r['code'] === 200 && strpos($r['body'], 'function rmmToast') !== false && strpos($r['body'], 'rmmVendorSummary(warnings') !== false, 'the alerts page has the toast and the single bulk summary');

// --- B10 + C1: permanent-delete switch and archived bulk Delete
$r = http('GET', '/agent/printers.php?archived=1');
$ok($r['code'] === 200 && strpos($r['body'], 'name="bulk_delete_printers"') === false, 'switch off: archived printers view has no bulk Delete');
$r = http('GET', '/admin/settings_security.php');
$ok($r['code'] === 200 && strpos($r['body'], 'name="config_destructive_deletes_enable"') !== false && strpos($r['body'], 'cannot be undone') !== false, 'Security settings shows the permanent-delete switch with its warning');
$ok(!preg_match('/name="config_destructive_deletes_enable"[^>]*checked/', $r['body']), 'the switch is off by default');
preg_match('/name="config_login_message"[^>]*>/', $r['body'], $x);
$form = ['csrf_token' => $tok, 'edit_security_settings' => 1, 'config_login_message' => '', 'config_login_remember_me_expire' => 30, 'config_login_session_lifetime' => 43200, 'config_log_retention' => 0, 'destructive_deletes_present' => 1, 'config_destructive_deletes_enable' => 1];
http('POST', '/admin/post.php', $form, ['Referer: ' . $base . '/admin/settings_security.php']);
$ok((int) $one("SELECT config_destructive_deletes_enable FROM settings WHERE company_id = 1") === 1, 'saving with the switch ticked turns it on');
$ok((int) $one("SELECT COUNT(*) FROM logs WHERE log_description LIKE '%ENABLED permanent deletes%'") === 1, 'turning it on is written to the audit log');
$r = http('GET', '/agent/printers.php?archived=1');
$ok(preg_match('/<button[^>]*confirm-link[^>]*\s+type="submit" form="bulkActions" name="bulk_delete_printers"/s', $r['body']) === 1, 'switch on: archived printers shows bulk Delete, with the confirm-link class');
$r = http('GET', '/agent/contacts.php?archived=1');
$ok(strpos($r['body'], 'name="bulk_delete_contacts"') !== false, 'switch on: archived people view shows bulk Delete');
// a post that does not carry the marker must not change it
unset($form['destructive_deletes_present'], $form['config_destructive_deletes_enable']);
http('POST', '/admin/post.php', $form, ['Referer: ' . $base . '/admin/settings_security.php']);
$ok((int) $one("SELECT config_destructive_deletes_enable FROM settings WHERE company_id = 1") === 1, 'a partial post (no marker) cannot switch it off');
$form['destructive_deletes_present'] = 1;
http('POST', '/admin/post.php', $form, ['Referer: ' . $base . '/admin/settings_security.php']);
$ok((int) $one("SELECT config_destructive_deletes_enable FROM settings WHERE company_id = 1") === 0, 'saving with the switch cleared turns it off again');
$r = http('GET', '/agent/printers.php?archived=1');
$ok(strpos($r['body'], 'name="bulk_delete_printers"') === false, 'switch off again: the Delete button is gone');

echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
exit($fails ? 1 : 0);
