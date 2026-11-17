<?php
declare(strict_types=1);
/* IT-INJ-5: bulk mail needs write level and uses only the configured From identity. */
require __DIR__ . '/secauthz_bootstrap.php';
sec_fn(['resolveBulkMailSender']);
sec_reset_fixture();
$q("INSERT INTO contacts SET contact_id = 1, contact_name = 'A1', contact_email = 'a1@cust.example', contact_client_id = 1, contact_primary = 1");
$g = ['config_mail_from_email' => 'noreply@msp.example', 'config_mail_from_name' => 'MSP', 'config_invoice_from_email' => 'billing@msp.example', 'config_invoice_from_name' => 'MSP Billing',
      'config_quote_from_email' => 'sales@msp.example', 'config_quote_from_name' => 'MSP Sales', 'config_ticket_from_email' => 'support@msp.example', 'config_ticket_from_name' => 'MSP Support'];
$spoof = ['csrf_token' => 't', 'mail_from' => 'ceo@scratch.example', 'mail_from_name' => 'The CEO', 'subject' => 's', 'body' => 'b', 'queued_at' => '2026-01-01 00:00:00'];
$cases = [
    ['agent/post/contact.php', 'send_bulk_mail_now', ['contact_ids' => [1]]],
    ['agent/post/client.php', 'bulk_send_client_email', ['client_ids' => [1], 'primary_contacts' => 1]],
];
foreach ($cases as [$file, $key, $extra]) {
    $GLOBALS['T_perm'] = ['module_client' => 1];
    $r = sec_run($file, $key, array_merge([$key => 1], $spoof, $extra), $g);
    $ok(str_starts_with($r, 'denied') && !$GLOBALS['T_queue'], "$key: read-only (level 1) role cannot send ($r)");
    $GLOBALS['T_perm'] = ['module_client' => 2];
    $r = sec_run($file, $key, array_merge([$key => 1], $spoof, $extra), $g);
    $m = $GLOBALS['T_queue'][0] ?? [];
    $ok(count($GLOBALS['T_queue']) === 1 && ($m['from'] ?? '') === 'noreply@msp.example' && ($m['from_name'] ?? '') === 'MSP', "$key: spoofed From/name ignored, configured sender used (from=" . ($m['from'] ?? '-') . ", name=" . ($m['from_name'] ?? '-') . ")");
    $r = sec_run($file, $key, array_merge([$key => 1], $spoof, ['mail_from' => 'billing@msp.example'], $extra), $g);
    $m = $GLOBALS['T_queue'][0] ?? [];
    $ok(($m['from'] ?? '') === 'billing@msp.example' && ($m['from_name'] ?? '') === 'MSP Billing', "$key: another configured identity from the form still works, with its configured name");
}
exit($T_fails ? 1 : 0);
