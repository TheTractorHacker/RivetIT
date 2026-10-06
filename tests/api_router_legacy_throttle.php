<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/* Static check: the API router throttles and logs failed legacy-key attempts (pentest F-07). No DB. */
$s = file_get_contents(__DIR__ . '/../api/v1/index.php');
$fails = 0; $ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };
$ok(strpos($s, ">= 15") !== false && strpos($s, "api_error(429, 'Too many failed attempts") !== false, 'router returns 429 after 15 failures');
$ok(strpos($s, "log_type = 'API', log_action = 'Failed'") !== false, 'router logs failed legacy key attempts');
exit($fails ? 1 : 0);
