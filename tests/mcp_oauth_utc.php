<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/*
 * Guard: the OAuth store must never use NOW() for expiry/usage columns. The authorize page runs after the app's login code
 * has switched the session to company-local time; the token endpoint does not. A code written with NOW() by one and
 * checked with NOW() by the other looked five hours expired (found live on mwa, 2026-10-14). Needs no database.
 *   php tests/mcp_oauth_utc.php
 */
$src = (string) file_get_contents(__DIR__ . '/../src/Mcp/OAuth/OAuthStore.php');
$bad = [];
foreach (explode("\n", $src) as $i => $line) {
    if (strpos($line, 'NOW()') === false) { continue; }
    // NOW() is only allowed against created_at (a DEFAULT CURRENT_TIMESTAMP column) and in comments
    if (preg_match('/^\s*(\*|\/\/)/', $line)) { continue; }
    if (preg_match('/expires_at|used_at|revoked_at|last_used_at|disabled_at/', $line)) { $bad[] = ($i + 1) . ': ' . trim($line); }
}
foreach ($bad as $b) { echo "FAIL  NOW() on a UTC column, line $b\n"; }
echo $bad === [] ? "PASS  no NOW() on expires/used/revoked/last_used/disabled columns\n" : '';
$count = substr_count($src, 'UTC_TIMESTAMP()');
echo ($count >= 15 ? 'PASS' : 'FAIL') . "  UTC_TIMESTAMP() used ($count places)\n";
exit($bad === [] && $count >= 15 ? 0 : 1);
