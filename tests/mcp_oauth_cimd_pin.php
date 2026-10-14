<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/* CIMD fetch: which vetted addresses get pinned. No database, no network:  php tests/mcp_oauth_cimd_pin.php */
require_once __DIR__ . '/../src/Mcp/OAuth/CimdFetcher.php';
use ITFlow\Mcp\OAuth\CimdFetcher;
$fail = 0;
$t = function (bool $c, string $l) use (&$fail) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) { $fail++; } };
$t(CimdFetcher::pinList('claude.ai', 443, ['160.79.104.10', '2607:6bc0::10']) === ['claude.ai:443:160.79.104.10'], 'dual-stack host: IPv4 only (IPv6 route may not work)');
$t(CimdFetcher::pinList('a.example', 443, ['203.0.113.5', '203.0.113.6']) === ['a.example:443:203.0.113.5', 'a.example:443:203.0.113.6'], 'several IPv4 addresses all kept');
$t(CimdFetcher::pinList('v6.example', 443, ['2607:6bc0::10']) === ['v6.example:443:[2607:6bc0::10]'], 'IPv6-only host still works, bracketed');
$t(CimdFetcher::pinList('x.example', 8443, []) === [], 'no addresses -> nothing pinned');
exit($fail ? 1 : 0);
