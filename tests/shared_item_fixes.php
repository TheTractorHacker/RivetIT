<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/* Share links (pentest IT-7a/b) and the vendor detail page (IT-7c). No DB, no network (node is used only to run the page's own TOTP JS). */
$fails = 0; $ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };
$r = function ($f) { return file_get_contents(__DIR__ . '/../' . $f); };

// (a) the share query selects the OTP secret; the seed is never sent to the server
$ajax = $r('agent/ajax.php');
$ok(strpos($ajax, 'SELECT credential_name, credential_username, credential_password, credential_otp_secret FROM credentials') !== false, 'share link query selects credential_otp_secret');
$view = $r('guest/guest_view_item.php');
$ok(strpos($view, 'get_totp_token') === false && strpos($view, 'totp_secret') === false, 'shared page no longer sends the TOTP seed to /agent/ajax.php');
$ok(strpos($view, 'crypto.subtle.sign') !== false, 'shared page computes the TOTP code in the browser');

// (b) "never expires" (NULL) links are valid, expired ones are not
foreach (['guest/guest_view_item.php', 'guest/guest_download_file.php'] as $f) {
    $s = $r($f);
    $ok(strpos($s, '(item_expire_at IS NULL OR item_expire_at > NOW())') !== false && strpos($s, 'AND item_expire_at > NOW()') === false, "$f accepts NULL (never) and unexpired links");
}

// (c) vendor_details.php must not require a file that does not exist
$vd = $r('agent/vendor_details.php');
preg_match_all('/\brequire(?:_once)?\s+["\']([^"\']+)["\']/', $vd, $m);
$missing = [];
foreach ($m[1] as $inc) {
    if ($inc[0] !== '/' && !is_file(__DIR__ . '/../agent/' . $inc) && !is_file(__DIR__ . '/../' . $inc)) $missing[] = $inc;
}
$ok($missing === [], 'vendor_details.php requires only files that exist' . ($missing ? ' (missing: ' . implode(', ', $missing) . ')' : ''));

// The page's JS TOTP against the RFC 6238 SHA-1 vector (secret "12345678901234567890", t=59 -> 94287082 -> 287082)
if (preg_match('/function base32ToBytes.*?\n            \}\n\n            function totpCode.*?\n            \}\n/s', $view, $m) && trim((string) shell_exec('command -v node'))) {
    $js = "const crypto = require('crypto').webcrypto; Date.now = () => 59000;\n" . $m[0] . "\ntotpCode('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ').then(c => console.log(c));\n";
    $f = tempnam(sys_get_temp_dir(), 'totp') . '.js';
    file_put_contents($f, $js);
    $out = trim((string) shell_exec('node ' . escapeshellarg($f) . ' 2>&1'));
    @unlink($f);
    $ok($out === '287082', "browser TOTP matches the RFC 6238 vector (got '$out')");
} else {
    echo "SKIP  node not available or JS not found\n";
}
exit($fails ? 1 : 0);
