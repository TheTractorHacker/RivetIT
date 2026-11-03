<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/* Static check: the API router throttles and logs failed legacy-key attempts (pentest F-07). No DB. */
$s = file_get_contents(__DIR__ . '/../api/v1/index.php');
$fails = 0; $ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };
$ok(strpos($s, 'legacy API keys are no longer accepted') !== false, 'router refuses X-Api-Key legacy keys');
$ok(strpos($s, "api_rate_limit('legacy_off_ip:'") !== false && strpos($s, "'Legacy Key Refused'") !== false, 'the refusal is audited, and the audit is rate limited per address');
exit($fails ? 1 : 0);
