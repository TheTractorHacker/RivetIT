<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/* TokenAuth6238::verifyOnce rejects replays and out-of-window codes (pentest F-08). No DB, no network. */
require_once __DIR__ . '/../plugins/totp/totp.php';
$fails = 0; $ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };
$secret = 'JBSWY3DPEHPK3PXP';
@unlink(sys_get_temp_dir() . '/rivetit_totp_replay/' . hash('sha256', $secret));
$code = TokenAuth6238::getTokenCode($secret);
$ok(TokenAuth6238::verifyOnce($secret, $code) === true, 'fresh code accepted');
$ok(TokenAuth6238::verifyOnce($secret, $code) === false, 'same code rejected on replay');
$ok(TokenAuth6238::verifyOnce($secret, '000000') === false || TokenAuth6238::matchStep($secret, '000000') !== null, 'wrong code rejected');
$ok(TokenAuth6238::matchStep($secret, $code, 0) !== null, 'matchStep finds current step');
@unlink(sys_get_temp_dir() . '/rivetit_totp_replay/' . hash('sha256', $secret));
exit($fails ? 1 : 0);
