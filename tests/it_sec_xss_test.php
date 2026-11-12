<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/*
 * Regression test for pre-launch findings IT-WEB-1 (HTML policy + modal script token), IT-WEB-2 (JSON content type, synced-name
 * tag stripping) and IT-WEB-3 (contract_add escaping). Pure checks need no DB; the directory-mapper checks need a schema-only scratch DB:
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=rivetit_scratch_... RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/it_sec_xss_test.php
 */
$root = dirname(__DIR__);
// PSR-4 for src/ only (no vendor needed)
spl_autoload_register(function ($c) use ($root) { if (str_starts_with($c, 'ITFlow\\')) { $f = $root . '/src/' . str_replace('\\', '/', substr($c, 7)) . '.php'; if (is_file($f)) require $f; } });
require_once $root . '/plugins/htmlpurifier/HTMLPurifier.standalone.php';
$pass = 0; $fail = 0;
$ok = function (bool $c, string $l, string $d = '') use (&$pass, &$fail) { if ($c) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l" . ($d !== '' ? " -- $d" : '') . "\n"; } };
$src = fn (string $p) => file_get_contents("$root/$p");

echo "== IT-WEB-1 HTML policy\n";
$payload = '<p class="x"><a class="ajax-modal btn" data-modal-url="/uploads/tickets/1/a.json" href="/uploads/tickets/1/a.json">open</a></p>';
$mk = function (?string $mode): HTMLPurifier {
    $c = HTMLPurifier_Config::createDefault();
    $c->set('Cache.DefinitionImpl', null);
    if ($mode !== null) { require_once dirname(__DIR__) . '/plugins/htmlpurifier/rivet_html_policy.php'; rivet_html_policy_apply($c, $mode); }
    return new HTMLPurifier($c);
};
$ok(str_contains($mk(null)->purify($payload), 'ajax-modal'), 'baseline: the default purifier config keeps class="ajax-modal" (the vulnerable behaviour)');
$strict = $mk('strict')->purify($payload);
$ok(!str_contains($strict, 'class') && !str_contains($strict, 'ajax-modal') && !str_contains($strict, 'data-modal-url'), 'strict policy drops every class and data-modal-url', $strict);
$ok(str_contains($strict, 'href="/uploads/tickets/1/a.json"'), 'strict policy keeps a normal link');
$rich = $mk('rich')->purify($payload . '<div class="ikb ikb-part evil">k</div><table class="table"><tr><td>1</td></tr></table>');
$ok(!str_contains($rich, 'ajax-modal') && !str_contains($rich, 'evil'), 'rich policy drops ajax-modal and unknown classes', $rich);
$ok(str_contains($rich, 'class="ikb ikb-part"') && str_contains($rich, 'class="table"'), 'rich policy keeps ikb-* and table formatting classes', $rich);
// every purifier site is wired to the policy
$sites = ['agent/ticket.php','client/ticket.php','guest/guest_view_ticket.php','guest/guest_approve_ticket_task.php','admin/ticket_template_details.php','admin/modals/mail_request/mail_request_view.php','agent/modals/mail_request/mail_request_view.php','admin/modals/mail_queue/mail_queue_message_view.php','agent/global_search.php','agent/ajax.php','guest/outtake_sign.php','client/kb_article.php','kiosk/kb_article.php','client/document.php','guest/guest_view_item.php','admin/document_template_details.php','admin/modals/kb_article/kb_article_archive_view.php','agent/kb_article.php','agent/document_details.php','agent/modals/document/document_view.php','agent/modals/document/document_version_view.php','agent/modals/kb_article/kb_article_version_view.php','agent/post/kb_article.php','api/v1/kb.php'];
$unwired = [];
foreach ($sites as $s) { $t = $src($s); if (!str_contains($t, 'rivet_html_policy_apply(') || !str_contains($t, 'rivet_html_policy.php')) { $unwired[] = $s; } }
$ok(!$unwired, 'all ' . count($sites) . ' purifier sites apply the policy', implode(', ', $unwired));
$all = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = $f->getPathname();
    if ($f->getExtension() !== 'php' || preg_match('~/(vendor|plugins|tests|node_modules|\.git)/~', $p)) continue;
    if (preg_match('/HTMLPurifier_Config::createDefault\(\)/', file_get_contents($p)) && !str_contains(file_get_contents($p), 'rivet_html_policy_apply(') && !str_contains($p, 'src/Training/Media/ArticleSanitizer.php')) { $all[] = substr($p, strlen($root) + 1); }
}
$ok(!$all, 'no other file builds a default HTMLPurifier config without the policy', implode(', ', $all));

echo "\n== IT-WEB-1 modal script token\n";
require_once $root . '/includes/modal_script_token.php';
$tok = str_repeat('a', 32);
$out = rivet_modal_stamp_scripts(json_encode(['content' => '<div>x</div><script>a()</script><SCRIPT src="/js/a.js" data-modal-script="attacker"></SCRIPT><script nonce="n">b()</script>']), $tok);
$c = json_decode($out, true)['content'];
$ok(substr_count($c, 'data-modal-script="' . $tok . '"') === 3, 'every script is stamped with the response token', $c);
$ok(!str_contains($c, 'attacker'), 'a data-modal-script supplied by the content is removed');
$ok(rivet_modal_stamp_scripts('{"error":"x"}', $tok) === '{"error":"x"}' && rivet_modal_stamp_scripts('not json', $tok) === 'not json', 'non-modal output is untouched');
$h = $src('includes/modal_header.php');
$ok(str_contains($h, "X-Rivet-Modal-Token") && str_contains($h, 'nosniff') && str_contains($h, 'rivet_modal_stamp_scripts'), 'modal_header sends the token header + nosniff and stamps scripts');

echo "\n== IT-WEB-1 ajax_modal.js (static checks; behaviour is covered by tests/it_sec_xss_modal_js.js)\n";
$js = $src('js/ajax_modal.js');
$ok(str_contains($js, 'isAllowedModalUrl(modalUrl)') && str_contains($js, "indexOf('application/json') !== 0") && str_contains($js, "getAttribute('data-modal-script') === token"), 'loader checks URL allow-list, JSON content type and script token');
$ok(!preg_match('/oldScripts\s*=\s*Array\.prototype\.slice\.call\(container\.querySelectorAll\(.script.\)\);/', $js), 'loader no longer runs every script blindly');

echo "\n== IT-WEB-2 JSON endpoints\n";
$aj = $src('agent/ajax.php');
$first = strpos($aj, "header('Content-Type: application/json; charset=utf-8');");
$ok($first !== false && $first < 2500 && str_contains(substr($aj, $first, 200), 'nosniff'), 'agent/ajax.php sends application/json + nosniff before any branch');
$ok(str_contains($src('agent/ajax_ticket_similar.php'), 'nosniff'), 'ajax_ticket_similar.php sends nosniff');

echo "\n== IT-WEB-2 PlainText\n";
use ITFlow\Security\PlainText;
foreach ([['<img src=x onerror=alert(1)>Bob', 'Bob'], ['<script src=//evil/p.js></script>Sales', 'Sales'], ['Ann <b>Lee</b>', 'Ann Lee'], ['  R&D  ', 'R&D'], ['x<img src=x onerror=1', 'x'], [null, ''], [['a'], '']] as [$in, $exp]) {
    $ok(PlainText::clean($in) === $exp, 'PlainText::clean(' . json_encode($in) . ')', PlainText::clean($in));
}

echo "\n== IT-WEB-3 contract_add\n";
$ca = $src('agent/modals/contract/contract_add.php');
$ok(!str_contains($ca, "{\$c['client_name']}") && str_contains($ca, "nullable_htmlentities(\$c['client_name'])") && str_contains($ca, '$access_permission_query'), 'department name is escaped and access-scoped');
$bad = [];
foreach (['document/document_move','document/document_bulk_move','file/file_move','file/file_bulk_move','credential/credential_move','credential/credential_bulk_move'] as $m) { if (str_contains($src("agent/modals/$m.php"), "{\$folder['folder_name']}")) $bad[] = $m; }
$ok(!$bad, 'sibling folder-option modals escape folder_name', implode(',', $bad));

if (getenv('RIVETIT_TEST_DB') === '1' && preg_match('/scratch/i', (string) getenv('RIVETIT_TEST_DB_NAME'))) {
    echo "\n== IT-WEB-2 directory mappers store tags-stripped names (scratch DB)\n";
    function encryptSetting(string $p): string { return $p; }
    function logApp($a = null, $b = null, $c = null) {}
    function appNotify() {}
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli('localhost', getenv('RIVETIT_TEST_DB_USER'), getenv('RIVETIT_TEST_DB_PASS'), getenv('RIVETIT_TEST_DB_NAME'));
    $db->set_charset('utf8mb4'); $db->query("SET SESSION sql_mode=''");
    $one = fn (string $sql) => ($r = $db->query($sql)) ? ($r->fetch_row()[0] ?? null) : null;
    $xss = '<img src=x onerror=alert(1)>';
    $db->query("INSERT INTO microsoft_integrations SET tenant_id='t', client_id='c', client_secret_enc='x', enabled=1, directory_sync_enabled=1");
    $mid = $db->insert_id;
    $mm = new \ITFlow\Integrations\Microsoft\MicrosoftDirectoryMapper($db, $mid);
    $d = $mm->syncDepartments([['department' => '<script src=//evil/p.js></script>Sales', 'displayName' => 'x']]);
    $e = $mm->syncEmployees([['displayName' => $xss . 'Eve', 'mail' => 'eve@example.test', 'department' => '<script src=//evil/p.js></script>Sales', 'accountEnabled' => true, 'jobTitle' => $xss . 'Boss']], $d['idMap']);
    $ok($one("SELECT COUNT(*) FROM clients WHERE client_name LIKE '%<%'") == 0 && $one("SELECT COUNT(*) FROM clients WHERE client_name='Sales'") == 1, 'Microsoft: department name stored without tags');
    $ok($one("SELECT contact_name FROM contacts WHERE contact_email='eve@example.test'") === 'Eve', 'Microsoft: displayName stored without tags', (string) $one("SELECT contact_name FROM contacts WHERE contact_email='eve@example.test'"));
    $ok($one("SELECT COUNT(*) FROM contacts WHERE contact_title LIKE '%<%'") == 0, 'Microsoft: mapped field values stored without tags');
    $ok((int) $one("SELECT contact_client_id FROM contacts WHERE contact_email='eve@example.test'") === (int) $one("SELECT client_id FROM clients WHERE client_name='Sales'"), 'Microsoft: employee still links to the cleaned department');
    $db->query("INSERT INTO odoo_integrations SET base_url='http://x', database_name='d', username='u', api_key_enc='k', enabled=1");
    $oid = $db->insert_id;
    $om = new \ITFlow\Integrations\Odoo\OdooDirectoryMapper($db, $oid);
    $od = $om->syncDepartments([['id' => 7, 'name' => $xss . 'Ops', 'parent_id' => false]]);
    $om->syncEmployees([['id' => 9, 'name' => $xss . 'Olga', 'work_email' => 'olga@example.test', 'job_title' => $xss . 'X', 'department_id' => [7, 'Ops']]], $od['idMap']);
    $ok($one("SELECT COUNT(*) FROM clients WHERE client_name='Ops'") == 1, 'Odoo: department name stored without tags');
    $ok($one("SELECT contact_name FROM contacts WHERE contact_email='olga@example.test'") === 'Olga', 'Odoo: employee name stored without tags');
}

echo "\n$pass passed, $fail failed\n";
echo $fail ? "FAILED\n" : "ALL PASSED\n";
exit($fail ? 1 : 0);
