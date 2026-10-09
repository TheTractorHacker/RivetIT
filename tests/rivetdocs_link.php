<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/*
 * RivetDocs add-on address handling (includes/rivetdocs.php). Pure PHP, no database needed:
 *   php tests/rivetdocs_link.php        (exit 0 = all pass)
 */
require __DIR__ . '/../includes/rivetdocs.php';

$fails = 0;
$ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };

$ok(rivetdocsNormalizeUrl('https://Docs.Example.com/') === 'https://docs.example.com', 'host lower-cased, trailing slash dropped');
$ok(rivetdocsNormalizeUrl(' http://10.0.0.5:3000 ') === 'http://10.0.0.5:3000', 'port kept, whitespace trimmed');
$ok(rivetdocsNormalizeUrl('https://example.com/docs/') === 'https://example.com/docs', 'sub-path kept');
foreach (['', 'docs.example.com', 'javascript:alert(1)', 'ftp://x.io', 'https://u:p@x.io', 'https://x.io?a=1', 'https://x.io#f', "https://x.io/\"onmouseover=", 'https://x.io/<script>', 'https://', str_repeat('a', 300)] as $bad) {
    $ok(rivetdocsNormalizeUrl($bad) === '', 'rejected: ' . substr($bad, 0, 30));
}

$ok(rivetdocsClientLink(true, 'https://d.io', 'Acme & Sons') === 'https://d.io/admin/companies?q=Acme%20%26%20Sons', 'client name is URL-encoded');
$ok(rivetdocsClientLink(false, 'https://d.io', 'Acme') === '', 'off -> no link');
$ok(rivetdocsClientLink(true, '', 'Acme') === '', 'no address -> no link');
$ok(rivetdocsClientLink(true, 'javascript:x', 'Acme') === '', 'bad address -> no link');

echo $fails ? "\n$fails FAILED\n" : "\nAll passed\n";
exit($fails ? 1 : 0);
