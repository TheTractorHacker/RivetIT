<?php
/* The company's terms link for the signing pages (includes/company_terms.php): only a plain http(s) address is ever drawn as a link.
 * Pure, no database.   php tests/company_terms_url.php */
require dirname(__DIR__) . '/includes/company_terms.php';
$fails = 0; $passes = 0;
$ok = function (bool $c, string $l) use (&$fails, &$passes) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; $c ? $passes++ : $fails++; };

$ok(itflow_company_terms_url('https://example.com/terms') === 'https://example.com/terms', 'an https address is kept');
$ok(itflow_company_terms_url('http://example.com/terms?lang=en&v=2') === 'http://example.com/terms?lang=en&v=2', 'http and a query string are kept as typed');
$ok(itflow_company_terms_url("  https://example.com/t  ") === 'https://example.com/t', 'surrounding spaces are trimmed');
$ok(itflow_company_terms_url('') === '' && itflow_company_terms_url(null) === '', 'blank and null mean "no link"');
$ok(itflow_company_terms_url('javascript:alert(1)') === '', 'javascript: is refused');
$ok(itflow_company_terms_url('data:text/html,<b>x</b>') === '', 'data: is refused');
$ok(itflow_company_terms_url('ftp://example.com/terms') === '', 'ftp: is refused');
$ok(itflow_company_terms_url('//example.com/terms') === '' && itflow_company_terms_url('/terms') === '', 'a relative or scheme-less address is refused');
$ok(itflow_company_terms_url('https://') === '' && itflow_company_terms_url('https:///terms') === '', 'an address with no host is refused');
$ok(itflow_company_terms_url('https://example.com/a b') === '', 'a space inside the address is refused');
$ok(itflow_company_terms_url('https://example.com/"onmouseover="x') === '', 'a double quote is refused');
$ok(itflow_company_terms_url("https://example.com/'><script>") === '', 'quote and angle brackets are refused');
$ok(itflow_company_terms_url('https://example.com/' . str_repeat('a', 500)) === '', 'an address over 500 characters is refused');
$ok(itflow_company_terms_url('https://example.com/' . str_repeat('a', 470)) !== '', 'a long address under the limit is kept');

echo "\n$passes passed, $fails failed\n";
exit($fails ? 1 : 0);
