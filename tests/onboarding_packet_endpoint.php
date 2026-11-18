<?php
// Source-wiring checks for the onboarding packet endpoint and saver, plus no-DB behaviour checks of the
// transaction / ticket-attachment cleanup (a mysqli subclass that never connects). CLI only.
if (PHP_SAPI !== 'cli') { exit(1); }
$root = dirname(__DIR__);
$fail = 0;
function chk(string $label, bool $ok): void { global $fail; echo ($ok ? 'PASS' : 'FAIL') . ": $label\n"; if (!$ok) { $fail++; } }

$ep = @file_get_contents("$root/agent/onboarding_packet_pdf.php");
$sv = @file_get_contents("$root/src/Onboarding/PacketSaver.php");
$cx = @file_get_contents("$root/src/Onboarding/PacketContext.php");
$ta = @file_get_contents("$root/src/Onboarding/PacketTicketAttachment.php");
$md = (string) @file_get_contents("$root/agent/modals/contact/contact_onboarding_packet.php");
chk('endpoint exists', is_string($ep));
chk('saver exists', is_string($sv));
chk('context exists', is_string($cx));
chk('ticket attachment writer exists', is_string($ta));
$ep = (string) $ep; $sv = (string) $sv; $cx = (string) $cx; $ta = (string) $ta;

foreach (['validateCSRFToken', "enforceUserPermission('module_credential', 2)", 'enforceClientAccess',
          "\$_SERVER['REQUEST_METHOD']", 'no-store', 'nosniff', "logAction('Onboarding Packet'",
          'PacketRequest::fromPost', 'PacketPdf::render', 'PacketSaver::save', 'catch (\Throwable'] as $needle) {
    chk("endpoint contains $needle", str_contains($ep, $needle));
}
// The download itself is never written to disk (the ticket copy is written by PacketTicketAttachment, inside the transaction).
foreach (['$_GET', 'file_put_contents', 'fopen(', "Output('F'", "'F')", 'session_write_close', 'error_log($e->getMessage', '$_SESSION'] as $needle) {
    chk("endpoint does not contain $needle", !str_contains($ep, $needle));
}
chk('POST-only 405', str_contains($ep, '405'));
chk('render happens before save', strpos($ep, 'PacketPdf::render') < strpos($ep, 'PacketSaver::save'));
chk('save happens before streaming output', strpos($ep, 'PacketSaver::save') < strpos($ep, 'Content-Type: application/pdf'));
chk('audit text has no password reference', !preg_match('/logAction\([^;]*password/i', $ep));
chk('audit args never carry posted data or password fields', !preg_match('/logAction\([^;]*(\$_POST|\[\'password\'\]|->lines\[)/', $ep));

chk('saver calls finalizeCredential', str_contains($sv, 'finalizeCredential'));
chk('saver uses encryptCredentialEntry', str_contains($sv, 'encryptCredentialEntry'));
chk('saver uses a transaction', str_contains($sv, 'begin_transaction') && str_contains($sv, 'rollback') && str_contains($sv, 'commit'));
chk('saver uses prepared statements', str_contains($sv, 'prepare('));
chk('saver does not log', !str_contains($sv, 'logAction') && !str_contains($sv, 'error_log'));

foreach (['agent/onboarding_packet_pdf.php', 'src/Onboarding/PacketSaver.php', 'src/Onboarding/PacketContext.php', 'src/Onboarding/PacketTicketAttachment.php', 'src/Onboarding/PacketPdf.php', 'src/Onboarding/PacketRequest.php'] as $f) {
    exec('php -l ' . escapeshellarg("$root/$f") . ' 2>&1', $o, $rc);
    chk("php -l $f", $rc === 0);
}
$pos = static fn(string $n, string $h = '') => strpos($h !== '' ? $h : $GLOBALS['ep'], $n);
chk('CSRF before permission', $pos('validateCSRFToken(') < $pos("enforceUserPermission('module_credential'"));
chk('module_client >= 1 enforced', str_contains($ep, "enforceUserPermission('module_client', 1)"));
chk('enforceClientAccess uses the context department', str_contains($ep, 'enforceClientAccess($client_id)') && str_contains($ep, '$client_id = $obpk_ctx->clientId;'));
chk('department-denied / unknown is a 404', preg_match('/PacketContext::resolve\(.*?if \(\$obpk_ctx === null\) \{\s*obpk_text\(404/s', $ep) === 1);
chk('context: department rule runs in both modes', substr_count($cx, 'self::departmentDenied(') === 2 && str_contains($cx, 'SUM(client_id = ?) AS hit'));
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

// ---- The department never comes from the request (endpoint, context, modal).
$clientFromReq = '/\$_(POST|GET|REQUEST|COOKIE)\s*\[\s*[\'"]client_id/';
chk('endpoint never reads client_id from the request', preg_match($clientFromReq, $ep) === 0 && !str_contains($ep, "\$_POST['client_id']") && !str_contains($ep, '$_POST["client_id"]'));
chk('context never reads the request', preg_match('/\$_(POST|GET|REQUEST|COOKIE|SESSION)/', $cx) === 0);
chk('modal never reads client_id from the request', preg_match($clientFromReq, $md) === 0);
chk('endpoint: $client_id assigned only from the context', substr_count($ep, '$client_id =') === 1);

// ---- Two modes: ticket mode (ticket_id + person_name; contact_id ignored) or contact mode (contact_id).
chk('mode: strict id parser', str_contains($ep, "preg_match('/^[1-9][0-9]{0,9}\$/D', \$raw)") && str_contains($ep, '<= 2147483647'));
chk('mode: contact_id read only when there is no ticket_id', str_contains($ep, "\$contact_id = \$obpk_ticket_id === 0 ? obpk_id('contact_id') : 0;") && substr_count($ep, "obpk_id('contact_id')") === 1);
chk('mode: neither (or a malformed id) is a 404', preg_match('/\(\$obpk_ticket_id === 0 && \$contact_id === 0\)\) \{\s*obpk_text\(404/', $ep) === 1);
chk('mode: decided before the context lookup', $pos('$obpk_ticket_mode = $obpk_ticket_id > 0;') < $pos('PacketContext::resolve('));
chk('ticket mode needs Support read, before the lookup', preg_match("/if \(\\\$obpk_ticket_mode\) \{\s*enforceUserPermission\('module_support', 1\);/", $ep) === 1 && $pos("enforceUserPermission('module_support', 1)") < $pos('PacketContext::resolve('));
chk('context: ticket branch returns before any contact lookup', strpos($cx, 'if ($ticketId > 0)') < strpos($cx, 'return $ctx;') && strpos($cx, 'return $ctx;') < strpos($cx, 'FROM contacts LEFT JOIN clients'));
chk('context: ticket mode takes the department from the ticket row', str_contains($cx, "\$ctx->clientId = (int) \$t['ticket_client_id'];") && str_contains($cx, 'JOIN clients ON client_id = ticket_client_id'));
chk('context: a ticket with no department is a 404', str_contains($cx, "(int) \$t['ticket_client_id'] <= 0"));
chk('context: ticket contact is only the manager prefill, never the person', str_contains($cx, "\$ctx->ticketContactName = (string) (\$t['contact_name'] ?? '');") && substr_count($cx, '$ctx->contactId =') === 1 && substr_count($cx, '$ctx->personFromContact = true;') === 1 && strpos($cx, '$ctx->contactId =') > strpos($cx, 'FROM contacts LEFT JOIN clients'));
chk('ticket mode: Logins get credential_contact_id 0 (contact id comes from the context only)', str_contains($ep, '$contact_id = $obpk_ctx->contactId;') && str_contains($cx, 'public int $contactId = 0;') && str_contains($sv, 'credential_contact_id = ?') && str_contains($sv, '$contactId, $clientId);'));
chk('ticket mode: Logins named after the person', str_contains($ep, "PacketSaver::save(\$mysqli, \$obpk_req, \$client_id, \$contact_id, \$obpk_in_tx, \$obpk_ticket_mode ? \$person : '', \$obpk_committed)") && str_contains($sv, "self::credentialName(\$l['name'], \$l['kind'], \$personPrefix)"));
chk('person: contact name in contact mode, validated person_name in ticket mode', str_contains($ep, "\$person = \$obpk_ctx->personFromContact ? \$obpk_ctx->personName : PacketRequest::personName(\$_POST['person_name'] ?? '');"));
chk('person_name read in exactly one place', substr_count($ep, "\$_POST['person_name']") === 1 && substr_count($ep, 'PacketRequest::personName(') === 1);
chk('person_name validated inside the 422 try', preg_match('/try \{\s*\$obpk_req = PacketRequest::fromPost\(\$_POST\);.*?PacketRequest::personName\(.*?\} catch \(\\\\InvalidArgumentException \$e\) \{\s*obpk_text\(422/s', $ep) === 1);
chk('audit uses a tag-stripped person name', str_contains($ep, '$obpk_person_log = trim(strip_tags($person));') && str_contains($ep, 'onboarding packet for $obpk_person_log'));

// ---- Attach to the ticket.
chk('attach: attach_passwords ignored without attach_ticket', str_contains($ep, "\$obpk_attach_pw = \$obpk_attach && (\$_POST['attach_passwords'] ?? null) === '1';") && substr_count($ep, "\$_POST['attach_passwords']") === 1);
chk('attach: only "1" opts in', str_contains($ep, "\$obpk_attach = (\$_POST['attach_ticket'] ?? null) === '1';") && substr_count($ep, "\$_POST['attach_ticket']") === 1);
chk('attach: refused (422) in contact mode', preg_match('/if \(\$obpk_attach && !\$obpk_ticket_mode\) \{\s*obpk_text\(422/', $ep) === 1);
chk('attach: Support edit permission, like upload_ticket_attachment', preg_match("/if \(\\\$obpk_attach\) \{\s*(\/\/[^\n]*\n\s*)?enforceUserPermission\('module_support', 2\);/", $ep) === 1);
chk('attach: same-department check (422) before render', preg_match("/\\\$obpk_ticket\['client_id'\] !== \\\$client_id\) \{\s*obpk_text\(422/", $ep) === 1 && $pos("\$obpk_ticket['client_id'] !== \$client_id") < $pos('PacketPdf::render('));
chk('attach: enforceClientAccess on the ticket department', str_contains($ep, "enforceClientAccess(\$obpk_ticket['client_id']);"));
chk('attach: closed ticket refused (422) before render', preg_match("/if \(\\\$obpk_ticket\['closed'\]\) \{\s*obpk_text\(422/", $ep) === 1 && $pos("\$obpk_ticket['closed']") < $pos('PacketPdf::render('));
chk('attach: all checks before the first render', $pos("enforceUserPermission('module_support', 2)") < $pos('PacketPdf::render(') && $pos('PacketContext::resolve(') < $pos('PacketPdf::render('));
chk('attach: ticket copy masked unless opted in; download unmasked', str_contains($ep, '$obpk_att_pdf = $obpk_attach_pw ? $obpk_pdf : PacketPdf::render($obpk_req, $person, $client_name, $obpk_support, $obpk_logo, true, true);') && str_contains($ep, '$obpk_pdf = PacketPdf::render($obpk_req, $person, $client_name, $obpk_support, $obpk_logo);') && str_contains($ep, 'echo $obpk_pdf;') && !str_contains($ep, 'echo $obpk_att_pdf'));
chk('attach: both renders before save', $pos('true, true);') < $pos('PacketSaver::save('));
chk('attach: written inside the Logins transaction (callback passed to save)', str_contains($ep, "PacketTicketAttachment::store(\$db, dirname(__DIR__) . '/uploads', \$obpk_att_ticket, \$obpk_att_name, \$obpk_att_pdf, \$obpk_att_path)") && $pos('PacketTicketAttachment::store(') < $pos('PacketSaver::save(') && str_contains($ep, '$obpk_in_tx = static function (\mysqli $db)'));
chk('saver: callback runs after begin_transaction and before commit', strpos($sv, 'begin_transaction') < strpos($sv, '$inTransaction($db)') && strpos($sv, '$inTransaction($db)') < strpos($sv, '$db->commit()'));
chk('saver: failed commit is an error (rolls back)', str_contains($sv, 'if (!$db->commit())'));
chk('committed flag: post-commit failure keeps the file, streams, no "Nothing was saved"', $pos('$obpk_committed = false;') < $pos('PacketSaver::save(') && $pos('if ($obpk_committed) {') < $pos('@unlink($obpk_att_path)') && $pos('if ($obpk_committed) {') < $pos('Nothing was saved.') && str_contains($sv, '$committed = true;') && $pos('$committed = true;', $sv) > $pos('$db->commit()', $sv));
chk('attach: file removed when the save/attach transaction fails', preg_match('/PacketSaver::save\([^;]*;\s*\} catch \(\\\\Throwable \$e\) \{.*?is_file\(\$obpk_att_path\).*?@unlink\(\$obpk_att_path\);.*?obpk_text\(500, [^;]*Nothing was saved\./s', $ep) === 1);
chk('attach: failure message carries no packet data', preg_match('/obpk_text\(500, [^;]*(\$person|\$obpk_req|\$_POST|\$e->)/', $ep) === 0);
chk('attach: Ticket/Attachment audit after Credential logs, before Generate and headers', $pos('logAction("Credential", "Create"') < $pos("logAction('Ticket', 'Attachment'") && $pos("logAction('Ticket', 'Attachment'") < $pos("logAction('Onboarding Packet'") && $pos("logAction('Ticket', 'Attachment'") < $pos("header('Content-Type: application/pdf')"));
chk('attach: audit says whether passwords were included, never the passwords', str_contains($ep, "'passwords included in the attached copy'") && str_contains($ep, "'passwords hidden in the attached copy'") && str_contains($ep, 'attached an onboarding packet for $obpk_person_log to ticket $obpk_ticket_ref ($obpk_copy_label)'));
chk('attach: audit only when a row was stored', str_contains($ep, 'if ($obpk_att_id > 0)'));
// Writer rules mirror upload_ticket_attachment.
chk('writer: allow-list via ticketAttachmentAllowedExtensions()', str_contains($ta, "in_array('pdf', \\ticketAttachmentAllowedExtensions(), true)"));
chk('writer: name checked with isUploadReferenceName()', str_contains($ta, '\isUploadReferenceName($ref)'));
chk('writer: uploads/tickets/<id>/ via mkdirMissing()', str_contains($ta, "'/tickets/'") && substr_count($ta, '\mkdirMissing(') === 2);
chk('writer: 500 MB ceiling like checkFileUpload()', str_contains($ta, '500 * 1024 * 1024'));
chk('writer: random reference name', str_contains($ta, 'bin2hex(random_bytes(16))'));
chk('writer: never overwrites', str_contains($ta, "fopen(\$path, 'xb')"));
chk('writer: row with reply id NULL, bound parameters', str_contains($ta, 'ticket_attachment_reply_id = NULL') && str_contains($ta, "bind_param('ssi', \$displayName, \$ref, \$ticketId)"));
chk('writer: unlinks its own file on failure', preg_match('/catch \(\\\\Throwable \$e\) \{.*?@unlink\(\$path\);\s*\$writtenPath = null;\s*throw \$e;/s', $ta) === 1);

// ---- Behaviour, no DB: the real writer and saver against a mysqli subclass that never connects.
require_once "$root/src/Onboarding/PacketRequest.php";
require_once "$root/src/Onboarding/PacketSaver.php";
require_once "$root/src/Onboarding/PacketTicketAttachment.php";
// functions.php cannot be loaded standalone here (it pulls in the whole app), so the three real helper definitions are
// lifted out of it verbatim with the tokenizer and eval'd. Only this repository's own source is ever eval'd (the same
// trust as require_once), never input.
$fsrc = (string) file_get_contents("$root/functions.php");
$toks = token_get_all($fsrc);
foreach (['ticketAttachmentAllowedExtensions', 'isUploadReferenceName', 'mkdirMissing'] as $fn) {
    if (function_exists($fn)) { continue; }
    $code = ''; $depth = 0; $on = false; $started = false;
    for ($i = 0, $n = count($toks); $i < $n; $i++) {
        $t = $toks[$i];
        if (!$on && is_array($t) && $t[0] === T_FUNCTION) {
            $j = $i + 1; while (is_array($toks[$j]) && $toks[$j][0] === T_WHITESPACE) { $j++; }
            if (is_array($toks[$j]) && $toks[$j][0] === T_STRING && $toks[$j][1] === $fn) { $on = true; }
        }
        if ($on) {
            $s = is_array($t) ? $t[1] : $t;
            $code .= $s;
            if ($s === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) { $depth++; $started = true; }
            if ($s === '}') { $depth--; if ($started && $depth === 0) { break; } }
        }
    }
    eval($code);
}
chk('real helpers loaded from functions.php', function_exists('ticketAttachmentAllowedExtensions') && function_exists('isUploadReferenceName') && function_exists('mkdirMissing'));

final class ObpkFakeDb extends \mysqli
{
    public array $calls = [];
    public bool $commitOk = true;
    public function __construct() {}
    public function begin_transaction(int $flags = 0, ?string $name = null): bool { $this->calls[] = 'begin'; return true; }
    public function commit(int $flags = 0, ?string $name = null): bool { $this->calls[] = 'commit'; return $this->commitOk; }
    public function rollback(int $flags = 0, ?string $name = null): bool { $this->calls[] = 'rollback'; return true; }
    public function prepare(string $query): \mysqli_stmt|false { $this->calls[] = 'prepare'; return false; } // the INSERT "fails"
}
use ITFlow\Onboarding\PacketRequest;
use ITFlow\Onboarding\PacketSaver;
use ITFlow\Onboarding\PacketTicketAttachment;

$tmp = sys_get_temp_dir() . '/obpk_test_' . bin2hex(random_bytes(6));
mkdir($tmp, 0700);
$uploads = "$tmp/uploads";
mkdir($uploads, 0700);
$listFiles = static function (string $dir): array {
    if (!is_dir($dir)) { return []; }
    $out = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) { $out[] = (string) $f; }
    return $out;
};
$noSave = PacketRequest::fromPost(['computer' => ['username' => 'jdoe', 'password' => 'pw-not-saved']]);

// 1. Writer: file written, INSERT fails -> its own file removed, $writtenPath cleared, exception rethrown.
$db = new ObpkFakeDb();
$wp = 'unset';
$threw = false;
try { PacketTicketAttachment::store($db, $uploads, 42, 'Onboarding-packet-Jane-2026-11-18.pdf', '%PDF-1.7 test', $wp); } catch (\Throwable $e) { $threw = true; }
chk('writer: INSERT failure throws', $threw);
chk('writer: INSERT failure leaves no file', $listFiles("$uploads/tickets") === []);
chk('writer: INSERT failure clears $writtenPath', $wp === null);
chk('writer: created uploads/tickets/42/', is_dir("$uploads/tickets/42"));

// 2. Saver + writer, attach only (no Logins ticked): rolled back, never committed, no file left.
$db = new ObpkFakeDb();
$path = null;
$threw = false;
try {
    PacketSaver::save($db, $noSave, 7, 0, static function (\mysqli $d) use ($uploads, &$path): void {
        PacketTicketAttachment::store($d, $uploads, 43, 'x.pdf', '%PDF-1.7 test', $path);
    });
} catch (\Throwable $e) { $threw = true; }
chk('save+attach: failure propagates', $threw);
chk('save+attach: begin -> rollback, never commit', $db->calls === ['begin', 'prepare', 'rollback']);
chk('save+attach: no orphan file', $listFiles("$uploads/tickets/43") === []);

// 3. Callback succeeds (file on disk) but COMMIT fails: save throws after rolling back, and the endpoint's catch
//    (asserted above) removes the file via $obpk_att_path. Simulated here with the same variable contract.
$db = new ObpkFakeDb();
$db->commitOk = false;
$path = null;
$threw = false;
try {
    PacketSaver::save($db, $noSave, 7, 0, static function (\mysqli $d) use ($uploads, &$path): void {
        @mkdir("$uploads/tickets/44", 0700, true);
        $path = "$uploads/tickets/44/" . PacketTicketAttachment::referenceName();
        file_put_contents($path, '%PDF-1.7 test');
    });
} catch (\Throwable $e) {
    $threw = true;
    if ($path !== null && is_file($path)) { @unlink($path); }
}
chk('commit failure: save throws', $threw);
chk('commit failure: begin -> commit -> rollback', $db->calls === ['begin', 'commit', 'rollback']);
chk('commit failure: the written path was reported and removed', $path !== null && !is_file($path));

// 4. Callback succeeds and commit succeeds: callback ran inside the transaction, nothing saved to Logins.
$db = new ObpkFakeDb();
$ran = false;
$saved = PacketSaver::save($db, $noSave, 7, 0, static function (\mysqli $d) use (&$ran): void { $ran = true; });
chk('success: callback ran between begin and commit', $ran && $db->calls === ['begin', 'commit'] && $saved === []);

// 5. No Logins and no attachment: no transaction at all (unchanged behaviour).
$db = new ObpkFakeDb();
chk('no save, no attach: no transaction', PacketSaver::save($db, $noSave, 7, 0) === [] && $db->calls === []);

// 6. Names.
chk('display name: Onboarding-packet-<slug>-<date>.pdf', PacketTicketAttachment::displayName("Zo\u{eb} O'Brien <x>", 1795000000) === 'Onboarding-packet-Zo-O-Brien-x-' . date('Y-m-d', 1795000000) . '.pdf');
chk('display name: empty slug falls back', PacketTicketAttachment::displayName("\u{e9}\u{e9}", 1795000000) === 'Onboarding-packet-person-' . date('Y-m-d', 1795000000) . '.pdf');
chk('display name: long names capped', strlen(PacketTicketAttachment::displayName(str_repeat('a', 500))) <= 140);
$r1 = PacketTicketAttachment::referenceName(); $r2 = PacketTicketAttachment::referenceName();
chk('reference name: random, passes isUploadReferenceName, .pdf', $r1 !== $r2 && isUploadReferenceName($r1) && preg_match('/^[0-9a-f]{32}\.pdf$/', $r1) === 1);
chk('credential name: contact mode unchanged', PacketSaver::credentialName('', 'email') === 'Email' && PacketSaver::credentialName('CRM', 'software') === 'CRM');
chk('credential name: ticket mode prefixed with the person', PacketSaver::credentialName('', 'email', 'Jane Smith') === 'Jane Smith - Email' && PacketSaver::credentialName('CRM', 'software', 'Jane Smith') === 'Jane Smith - CRM');
chk('credential name: tags stripped from the prefix', PacketSaver::credentialName('CRM', 'software', 'Jane <b>Smith</b>') === 'Jane Smith - CRM');
$long = PacketSaver::credentialName(str_repeat('n', 190), 'software', str_repeat('p', 200));
chk('credential name: fits varchar(200); line name never cut', mb_strlen($long) <= 200 && str_ends_with($long, ' - ' . str_repeat('n', 190)));
chk('credential name: no room -> line name alone', PacketSaver::credentialName(str_repeat('n', 200), 'software', 'Jane') === str_repeat('n', 200));

// Clean up the temp tree.
foreach (array_reverse($listFiles($tmp)) as $f) { @unlink($f); }
foreach (["$uploads/tickets/42", "$uploads/tickets/43", "$uploads/tickets/44", "$uploads/tickets", $uploads, $tmp] as $d) { @rmdir($d); }
chk('temp tree removed', !is_dir($tmp));

echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
exit($fail ? 1 : 0);
