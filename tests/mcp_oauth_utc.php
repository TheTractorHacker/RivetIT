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
// every INSERT must stamp created_at explicitly (a DEFAULT CURRENT_TIMESTAMP follows the session zone)
$ins = preg_match_all('/INSERT INTO mcp_oauth_(clients|codes|grants|tokens) \(([^)]*)\)/', $src, $m, PREG_SET_ORDER);
$noCreated = [];
foreach ($m as $x) { if (strpos($x[2], 'created_at') === false) { $noCreated[] = $x[1]; } }
echo $noCreated === [] ? "PASS  every OAuth INSERT stamps created_at in UTC ($ins inserts)\n" : 'FAIL  INSERT without created_at: ' . implode(', ', $noCreated) . "\n";
echo ($count >= 15 ? 'PASS' : 'FAIL') . "  UTC_TIMESTAMP() used ($count places)\n";
// PHP side: a stored time is UTC, so it must never be parsed with the process time zone (strtotime/new DateTime without 'UTC'),
// which the app switches to the company zone after sign-in. The token validator asks the database for seconds left instead.
$parse = [];
foreach (glob(__DIR__ . '/../src/Mcp/OAuth/*.php') as $f) {
    foreach (explode("\n", (string) file_get_contents($f)) as $i => $line) {
        if (preg_match('/^\s*(\*|\/\/)/', $line)) { continue; }
        if ((str_contains($line, 'strtotime(') && !str_contains($line, "' UTC'")) || preg_match('/new \\?DateTime(Immutable)?\((?!.*UTC)/', $line) || preg_match('/\bdate\(/', $line)) { $parse[] = basename($f) . ':' . ($i + 1) . ': ' . trim($line); }
    }
}
foreach ($parse as $b) { echo "FAIL  stored time parsed with the process time zone: $b\n"; }
echo $parse === [] ? "PASS  no stored time is parsed with the process time zone in src/Mcp/OAuth\n" : '';
exit($bad === [] && $noCreated === [] && $count >= 15 && $parse === [] ? 0 : 1);
