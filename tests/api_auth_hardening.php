<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/*
 * API password login hardening (pentest IT-2), no database needed:  php tests/api_auth_hardening.php
 *  - api_rate_limit() fails open by default but CLOSED when asked and Redis is unavailable
 *  - api/v1/auth.php uses the single-use TOTP check, counts a wrong code against the user lockout counter and only
 *    resets the counter after the second factor passed (source-order contract)
 *  - the login endpoint asks for fail-closed rate limiting (logout excepted)
 */
define('FROM_API', true);
function getRedisClient() { return null; } // Redis unavailable
require __DIR__ . '/../api/v1/includes/api_ratelimit.php';

$fails = 0;
$ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };

$ok(api_rate_limit('x', 1, 60) === true, 'default: fails open when Redis is down (other endpoints keep serving)');
$ok(api_rate_limit('x', 1, 60, true) === false, 'fail_closed: denied when Redis is down');

$src = file_get_contents(__DIR__ . '/../api/v1/auth.php');
$verifyPos = strpos($src, 'TokenAuth6238::verifyOnce($totp_secret, $totp, 1)');
$ok($verifyPos !== false, 'TOTP verified single-use with a +/-1 step window');
$ok(strpos($src, 'TokenAuth6238::verify(') === false, 'the replayable +/-3 verify() is no longer used');
$reset_sql = 'SET user_failed_login_count = 0 WHERE user_id = $uid' . '"';
$incPos = strpos($src, 'user_failed_login_count = user_failed_login_count + 1', $verifyPos ?: 0);
$resetPos = strpos($src, $reset_sql);
$ok($incPos !== false && $incPos > $verifyPos && $incPos < $resetPos, 'a wrong TOTP increments the failure counter before any reset');
$ok($resetPos !== false && $resetPos > $verifyPos, 'failure counter is reset only AFTER the second factor passed');
$ok(substr_count($src, $reset_sql) === 1, 'no earlier reset between the password check and the TOTP check');
$ok(strpos($src, 'MFA Failed') !== false, 'TOTP failure is logged');

$idx = file_get_contents(__DIR__ . '/../api/v1/index.php');
$ok(strpos($idx, "api_rate_limit('auth_ip:' . \$auth_ip, 30, 60, (\$_SERVER['REQUEST_METHOD'] ?? '') !== 'DELETE')") !== false, 'login endpoint rate limit fails closed (logout excepted)');

// the verifier itself: a code is accepted once, then rejected
require __DIR__ . '/../plugins/totp/totp.php';
$secret = 'JBSWY3DPEHPK3PXQ';
@unlink(sys_get_temp_dir() . '/rivetit_totp_replay/' . hash('sha256', $secret));
$code = TokenAuth6238::getTokenCode($secret);
$ok(TokenAuth6238::verifyOnce($secret, $code, 1) === true && TokenAuth6238::verifyOnce($secret, $code, 1) === false, 'a TOTP code works once, the replay is refused');
@unlink(sys_get_temp_dir() . '/rivetit_totp_replay/' . hash('sha256', $secret));

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
