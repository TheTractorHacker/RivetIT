<?php
// Source-wiring checks for the onboarding packet endpoint and saver (CLI only; no DB).
if (PHP_SAPI !== 'cli') { exit(1); }
$root = dirname(__DIR__);
$fail = 0;
function chk(string $label, bool $ok): void { global $fail; echo ($ok ? 'PASS' : 'FAIL') . ": $label\n"; if (!$ok) { $fail++; } }

$ep = @file_get_contents("$root/agent/onboarding_packet_pdf.php");
$sv = @file_get_contents("$root/src/Onboarding/PacketSaver.php");
chk('endpoint exists', is_string($ep));
chk('saver exists', is_string($sv));
$ep = (string) $ep; $sv = (string) $sv;

foreach (['validateCSRFToken', "enforceUserPermission('module_credential', 2)", 'enforceClientAccess',
          "\$_SERVER['REQUEST_METHOD']", 'no-store', 'nosniff', "logAction('Onboarding Packet'",
          'PacketRequest::fromPost', 'PacketPdf::render', 'PacketSaver::save', 'catch (\Throwable'] as $needle) {
    chk("endpoint contains $needle", str_contains($ep, $needle));
}
foreach (['$_GET', 'file_put_contents', "Output('F'", 'session_write_close', 'error_log($e->getMessage'] as $needle) {
    chk("endpoint does not contain $needle", !str_contains($ep, $needle));
}
chk('POST-only 405', str_contains($ep, '405'));
chk('render happens before save', strpos($ep, 'PacketPdf::render') < strpos($ep, 'PacketSaver::save'));
chk('save happens before streaming output', strpos($ep, 'PacketSaver::save') < strpos($ep, 'Content-Type: application/pdf'));
chk('audit text has no password reference', !preg_match('/logAction\([^;]*password/i', $ep));

chk('saver calls finalizeCredential', str_contains($sv, 'finalizeCredential'));
chk('saver uses encryptCredentialEntry', str_contains($sv, 'encryptCredentialEntry'));
chk('saver uses a transaction', str_contains($sv, 'begin_transaction') && str_contains($sv, 'rollback') && str_contains($sv, 'commit'));
chk('saver uses prepared statements', str_contains($sv, 'prepare('));
chk('saver does not log', !str_contains($sv, 'logAction') && !str_contains($sv, 'error_log'));

foreach (['agent/onboarding_packet_pdf.php', 'src/Onboarding/PacketSaver.php'] as $f) {
    exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $o, $rc);
    chk("php -l $f", $rc === 0);
}
echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
exit($fail ? 1 : 0);
