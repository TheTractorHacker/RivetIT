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
$pos = static fn(string $n, string $h = '') => strpos($h !== '' ? $h : $GLOBALS['ep'], $n);
chk('CSRF before permission', $pos('validateCSRFToken(') < $pos("enforceUserPermission('module_credential'"));
chk('module_client >= 1 enforced', str_contains($ep, "enforceUserPermission('module_client', 1)"));
chk('enforceClientAccess uses contact client var', str_contains($ep, 'enforceClientAccess($client_id)') && str_contains($ep, "\$client_id = (int) \$obpk_row['contact_client_id']"));
chk("no \$_POST['client_id']", !str_contains($ep, "\$_POST['client_id']") && !str_contains($ep, '$_POST["client_id"]'));
chk('department-denied is a 404', preg_match('/hit.*?obpk_text\(404/s', $ep) === 1);
chk('render before save', $pos('PacketPdf::render(') < $pos('PacketSaver::save('));
chk('save before logAction', $pos('PacketSaver::save(') < $pos("logAction('Onboarding Packet'"));
chk('logAction before PDF header', $pos("logAction('Onboarding Packet'") < $pos("header('Content-Type: application/pdf')"));
chk('preflight 422 before render', $pos('PacketSaver::preflight(') < $pos('PacketPdf::render('));
chk('saver: preflight before begin_transaction', strpos($sv, 'self::preflight') < strpos($sv, 'begin_transaction'));
chk('saver: 1000-byte limit', str_contains($sv, 'strlen($l[\'password\']) > self::MAX_PASSWORD_BYTES') && str_contains($sv, '= 1000'));
chk('saver: refuses without session master', str_contains($sv, 'sessionMaster() === null') && strpos($sv, 'sessionMaster') < strpos($sv, 'begin_transaction'));
chk('saver: sanitises name/uri/note', substr_count($sv, 'self::clean(') >= 3 && str_contains($sv, 'strip_tags'));
// Audit: per-row Credential/Create log after commit, before Generate log; names only.
chk('saver returns a list', str_contains($sv, '): array') && str_contains($sv, "'client_id' => \$clientId") && str_contains($sv, 'return $saved;'));
chk('saver commit before return of list', strpos($sv, '$db->commit()') < strpos($sv, 'return $saved;'));
chk('endpoint logs Credential Create per row', str_contains($ep, 'foreach ($obpk_saved as $obpk_cred)') && str_contains($ep, '(onboarding packet)') && str_contains($ep, 'logAction("Credential", "Create"'));
chk('order: save -> credential logAction -> Generate logAction -> headers', $pos('PacketSaver::save(') < $pos('logAction("Credential", "Create"') && $pos('logAction("Credential", "Create"') < $pos("logAction('Onboarding Packet'") && $pos("logAction('Onboarding Packet'") < $pos("header('Content-Type: application/pdf')"));
chk('count derived via count()', str_contains($ep, 'count($obpk_saved)'));
chk('credential log has no password', !preg_match('/logAction\("Credential"[^;]*pass/i', $ep));
// Output buffering.
chk('ob_start after bootstrap ob_end_clean', preg_match('/check_login\.php";\s*ob_end_clean\(\);.*?\nob_start\(\);/s', $ep) === 1);
chk('buffers cleaned before PDF headers', preg_match('/while \(ob_get_level\(\)\) \{\s*ob_end_clean\(\);\s*\}\s*header\(\'Content-Type: application\/pdf\'\)/', $ep) === 1);
chk('buffers cleaned in obpk_text', preg_match('/function obpk_text[^{]*\{\s*while \(ob_get_level\(\)\) \{\s*ob_end_clean\(\);\s*\}\s*http_response_code/', $ep) === 1);
echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
exit($fail ? 1 : 0);
