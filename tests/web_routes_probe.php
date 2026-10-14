<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (pentest F-02)
/*
 * The in-app route self-check (src/Ops/WebRouteCheck.php) against a STUB web server (php -S on 127.0.0.1, random port, started and
 * stopped here). The stub answers every registry probe like a correctly configured nginx would, or like one that is missing chosen
 * routes; the checker must report exactly those, stay quiet when it cannot judge, stay within its safety rules, and never throw.
 * No DB, no real nginx, no outside network.
 *   php tests/web_routes_probe.php
 */
require_once __DIR__ . '/../src/Ops/WebRoutes.php';
require_once __DIR__ . '/../src/Ops/WebRouteCheck.php';
require_once __DIR__ . '/../src/Ops/ServerStatus.php';
require_once __DIR__ . '/web_routes_lib.php';

use ITFlow\Ops\ServerStatus;
use ITFlow\Ops\WebRouteCheck;
use ITFlow\Ops\WebRoutes;

[$ok, $failures] = wr_checker();
$root = wr_root();
if (!function_exists('curl_multi_init')) { echo "SKIP  PHP curl is not available\n"; exit(0); }

// ---------------------------------------------------------------- fake app root
$tmp = wr_tmpdir('probe');
$app = "$tmp/app";
foreach (['css/a.css', 'js/a.js', 'fonts/a.woff2', 'plugins/lib/x.js', 'deploy/templates/placeholder'] as $f) { @mkdir(dirname("$app/$f"), 0755, true); file_put_contents("$app/$f", "x\n"); }
copy("$root/deploy/templates/rivetit-locations.conf", "$app/deploy/templates/rivetit-locations.conf");
$installed = "$tmp/installed-routes.conf";
copy("$root/deploy/templates/rivetit-locations.conf", $installed);
$cacheDir = "$tmp/cache"; mkdir($cacheDir);
$dirPaths = ['css' => '/css/a.css', 'js' => '/js/a.js', 'fonts' => '/fonts/a.woff2', 'plugins' => '/plugins/lib/x.js'];

// ---------------------------------------------------------------- the stub server
$answers = [];   // "path?query" => [status, headers, absent-header-leak, id]
foreach (WebRoutes::all() as $e) {
    $p = $e['probe'];
    if ($p === null) { continue; }
    $path = is_array($p['path']) ? $dirPaths[$p['path']['dir']] : $p['path'];
    $h = [];
    foreach ($p['headers'] ?? [] as $n => $needle) { $h[$n] = $needle === 'no-cache' ? 'no-cache' : $needle; }
    if (($p['headers']['Content-Type'] ?? '') === 'json') { $h['Content-Type'] = 'application/json'; }
    $answers[$path] = ['status' => $p['status'][0], 'headers' => $h, 'id' => $e['id'], 'php_header' => in_array('Cross-Origin-Opener-Policy', $p['absent'] ?? [], true)];
}
$answers['/health/live.php'] = ['status' => 200, 'headers' => [], 'id' => 'php', 'php_header' => false];
$modeFile = "$tmp/mode.json"; $hitFile = "$tmp/hits.log";
file_put_contents("$tmp/answers.json", json_encode($answers));
file_put_contents("$tmp/router.php", '<?php
$a = json_decode(file_get_contents(' . var_export("$tmp/answers.json", true) . '), true);
$m = json_decode(file_get_contents(' . var_export($modeFile, true) . '), true) ?: [];
$uri = $_SERVER["REQUEST_URI"];
file_put_contents(' . var_export($hitFile, true) . ', $uri . "\n", FILE_APPEND);
$path = $uri;
if (($m["sentinel"] ?? 200) !== 200 && $uri === "/health/live.php") { http_response_code($m["sentinel"]); return; }
if ($uri === "/rivetit-route-check-control") { http_response_code(($m["control"] ?? 404)); return; }
if (isset($m["status_for"][$uri])) { http_response_code($m["status_for"][$uri]); return; }
if (isset($m["redirect_for"][$uri])) { http_response_code(302); header("Location: " . $m["redirect_for"][$uri]); return; }
$e = $a[$uri] ?? null;
if ($e === null) { http_response_code(404); return; }
if (in_array($e["id"], $m["missing"] ?? [], true)) {
    // what a server lacking the route does: the next location answers (404), or the application answers where it can
    if ($e["php_header"]) { http_response_code(302); header("Cross-Origin-Opener-Policy: same-origin"); header("Location: /kiosk/"); return; }
    http_response_code(404); return;
}
http_response_code($e["status"]);
foreach ($e["headers"] as $n => $v) { header("$n: $v"); }
');
$setMode = function (array $m) use ($modeFile) { file_put_contents($modeFile, json_encode($m)); };
$hits = function () use ($hitFile): array { return is_file($hitFile) ? array_values(array_filter(explode("\n", (string) file_get_contents($hitFile)))) : []; };
$setMode([]);
$sock = stream_socket_server('tcp://127.0.0.1:0', $en, $es);
$port = (int) substr(stream_socket_get_name($sock, false), strrpos(stream_socket_get_name($sock, false), ':') + 1);
fclose($sock);
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", "$tmp/router.php"], [0 => ['file', '/dev/null', 'r'], 1 => ['file', "$tmp/srv.out", 'w'], 2 => ['file', "$tmp/srv.err", 'w']], $pipes);
$up = false;
for ($i = 0; $i < 60 && !$up; $i++) { $c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2); if ($c) { fclose($c); $up = true; } else { usleep(100000); } }
if (!$up) { echo "SKIP  could not start the stub server\n"; wr_rmrf($tmp); exit(0); }

$now = 1_800_000_000;
$mk = fn(array $ctx = ['mcp_ready' => true, 'mcp_builtin' => true], string $host = null, ?int $at = null, ?callable $fetch = null) =>
    new WebRouteCheck($app, $host ?? "127.0.0.1:$port", $ctx, 'http', $fetch, $installed, $cacheDir, 300, $at ?? $now);
$clearCache = function () use ($cacheDir) { foreach (glob("$cacheDir/*") ?: [] as $f) { unlink($f); } };

// ---------------------------------------------------------------- everything present
$chk = $mk();
$r = $chk->run(true);
$ok($r['state'] === 'ok' && $r['missing'] === [], 'a correctly configured server: every probed route answers as expected' . ($r['missing'] ? ' ' . json_encode($r['missing']) : ' (' . $r['note'] . ')'));
$total = count(array_filter(WebRoutes::all(), fn($e) => $e['probe'] !== null));
$ok($r['checked'] === $total && $r['unknown'] === 0, "all $total registry probes were judged (none skipped, none unknown)");
$ok($r['file'] === 'current', 'the installed routes file equals the shipped one');
$rows = $chk->rows($r);
$ok(count($rows) === 1 && $rows[0]['status'] === 'ok' && str_contains($rows[0]['detail'], "All $total"), 'the status row is a single green line');
$ok($chk->hint($r) === null, 'no banner/hint when everything is fine');

// ---------------------------------------------------------------- routes missing
$setMode(['missing' => ['mcp_authserver_metadata', 'backups_dir', 'kiosk_entry', 'oauth_token_endpoints']]);
$r = $chk->run(true);
$ids = array_column($r['missing'], 'id');
sort($ids);
$ok($r['state'] === 'missing' && $ids === ['backups_dir', 'kiosk_entry', 'mcp_authserver_metadata', 'oauth_token_endpoints'], 'missing routes are found by id: ' . implode(',', $ids));
$byId = array_column($r['missing'], null, 'id');
$ok($byId['mcp_authserver_metadata']['feature'] === 'Remote MCP built-in sign-in' && str_contains($byId['mcp_authserver_metadata']['problem'], 'HTTP 404, expected 200'), 'each is named by the feature it breaks, with what was seen');
$ok(str_contains($byId['kiosk_entry']['problem'], 'answered by the application'), 'the kiosk ?e= rule is told apart from the PHP fallback by a header only PHP sends');
$ok(str_contains($byId['oauth_token_endpoints']['problem'], 'Pragma header is missing'), 'the oauth endpoints are flagged when the route (and with it the Pragma: no-cache header) is absent');
$rows = $chk->rows($r);
$ok($rows[0]['label'] === 'Web server is missing routes (4)' && $rows[0]['status'] === 'fail', 'the row reads "Web server is missing routes (N)" and is RED when a denial is missing (backups_dir is a security route)');
$ok(str_contains($rows[0]['detail'], 'Remote MCP built-in sign-in') && str_contains($rows[0]['detail'], 'Training kiosk') && str_contains($rows[0]['detail'], 'sudo ' . $app . '/deploy/apply-web-routes.sh --domain 127.0.0.1'), 'it lists the features and shows the exact one-line command, filled in from the app path and domain');
$ok(str_contains((string) $chk->hint($r), 'missing 4 routes') && str_contains((string) $chk->hint($r), 'apply-web-routes.sh'), 'the banner/hint sentence carries the same command');
$setMode(['missing' => ['mcp_authserver_metadata', 'oauth_token_endpoints']]);
$r = $chk->run(true);
$rows = $chk->rows($r);
$ok($rows[0]['status'] === 'warn' && $rows[0]['label'] === 'Web server is missing routes (2)', 'only feature routes missing: amber, not red');
// only the three MCP routes (the MCP settings page asks for just these)
$setMode(['missing' => ['mcp_authserver_metadata', 'backups_dir']]);
$r = $chk->run(true, ['mcp', 'mcp_resource_metadata', 'mcp_authserver_metadata']);
$ok(array_column($r['missing'], 'id') === ['mcp_authserver_metadata'] && $r['checked'] === 3, 'the MCP settings page check asks only for the three MCP routes');

// ---------------------------------------------------------------- conditions
$setMode(['missing' => ['mcp', 'mcp_resource_metadata', 'mcp_authserver_metadata']]);
$r = $mk(['mcp_ready' => false, 'mcp_builtin' => false])->run(true);
$ok($r['state'] === 'ok' && $r['checked'] === $total - 3, 'MCP off: the three MCP probes are skipped, so an off feature is never reported as a missing route');
$r = $mk(['mcp_ready' => true, 'mcp_builtin' => false])->run(true);
$ok(array_column($r['missing'], 'id') === ['mcp', 'mcp_resource_metadata'], 'MCP on with an external provider: only the built-in sign-in probe is skipped');
$r = $mk(['mcp_ready' => true, 'mcp_builtin' => true])->run(true);
$ok(count($r['missing']) === 3, 'MCP built-in sign-in on: all three are probed');
$setMode([]);

// ---------------------------------------------------------------- cannot judge: stands down with a note
$clearCache();
$r = $mk(host: '127.0.0.1:1')->run(true);
$ok($r['state'] === 'skipped' && str_contains($r['note'], 'could not reach its own public address') && $r['missing'] === [], 'unreachable (loopback blocked, behind another proxy): skipped with a clear note, nothing reported missing');
$rows = $mk(host: '127.0.0.1:1')->rows($r);
$ok($rows[0]['status'] === 'skip' && str_contains($rows[0]['detail'], 'apply-web-routes.sh'), 'the skipped row says so and still shows the command');
$setMode(['control' => 200]);
$r = $mk()->run(true);
$ok($r['state'] === 'skipped' && str_contains($r['note'], 'in front of this server'), 'a proxy that answers an unknown address with 200: skipped (answers cannot be compared)');
$setMode(['sentinel' => 503]);
$r = $mk()->run(true);
$ok($r['state'] === 'skipped' && str_contains($r['note'], 'HTTP 503'), 'the site\'s own liveness failing: skipped, not blamed on routes');
$setMode(['status_for' => ['/backups/rivetit-route-check' => 503, '/src/rivetit-route-check' => 429]]);
$r = $mk()->run(true);
$ok($r['state'] === 'ok' && $r['unknown'] === 2, 'a busy/overloaded answer (429/502/503/504) on one probe is "unknown", not a missing route');
$setMode([]);

// ---------------------------------------------------------------- safety rules
$setMode(['redirect_for' => ['/.well-known/acme-challenge/rivetit-route-check' => '/followed-redirect']]);
@unlink($hitFile);
$r = $mk()->run(true);
$ok(!in_array('/followed-redirect', $hits(), true), 'redirects are never followed');
$ok(array_column($r['missing'], 'id') === ['acme'] && str_contains($r['missing'][0]['problem'], 'HTTP 302'), 'a redirect is simply reported as the answer it is');
$setMode([]);
@unlink($hitFile);
$mk()->run(true);
$allowed = array_merge(array_map(fn($e) => is_array($e['probe']['path']) ? $dirPaths[$e['probe']['path']['dir']] : $e['probe']['path'], array_filter(WebRoutes::all(), fn($e) => $e['probe'] !== null)), ['/health/live.php', '/rivetit-route-check-control']);
$ok(array_diff($hits(), $allowed) === [] && count($hits()) <= 40, 'only registry paths are ever requested (' . count($hits()) . ' requests), nothing else');
// the same, with a recording fetcher: one host, GET only, https by default, no user input
$seen = [];
$rec = function (array $reqs) use (&$seen) { foreach ($reqs as $q) { $seen[] = $q; } return array_map(fn($q) => ['status' => 0, 'headers' => [], 'error' => 'recorded'], $reqs); };
$cr = new WebRouteCheck($app, 'app.example.com', ['mcp_ready' => true], 'https', $rec, $installed, $cacheDir, 300, $now + 9999);
$cr->run(true);
$hostsSeen = array_unique(array_map(fn($q) => parse_url($q['url'], PHP_URL_HOST), $seen));
$ok($hostsSeen === ['app.example.com'] && array_unique(array_column($seen, 'method')) === ['GET'] && str_starts_with($seen[0]['url'], 'https://app.example.com/'), 'requests go to the configured host only, by GET, over https unless told otherwise');
// hostile or malformed base URLs: nothing is requested at all
foreach (['evil.example/x', 'user:pw@evil.example', 'a b.example', '', 'https://evil.example', 'evil.example/../..', '127.0.0.1:99999x', "evil.example\r\nX: y", '-bad.example', 'ex ample.com'] as $bad) {
    $seen = [];
    $r = (new WebRouteCheck($app, $bad, [], 'https', $rec, $installed, $cacheDir, 300, $now))->run(true);
    $ok($seen === [] && $r['state'] === 'skipped', 'refuses a hostile or malformed site address without any request: ' . json_encode($bad));
}
$seen = [];
(new WebRouteCheck($app, 'a.example', [], 'gopher', $rec, $installed, $cacheDir, 300, $now))->run(true);
$ok($seen === [], 'refuses a scheme other than http/https');
$src = file_get_contents("$root/src/Ops/WebRouteCheck.php");
$ok(preg_match('~CURLOPT_FOLLOWLOCATION => false~', $src) === 1 && preg_match('~CURLOPT_CONNECTTIMEOUT => 3~', $src) === 1 && preg_match('~CURLOPT_TIMEOUT => 5~', $src) === 1 && str_contains($src, 'CURLPROTO_HTTP | CURLPROTO_HTTPS') && str_contains($src, 'MAX_IN_FLIGHT = 4'), 'the HTTP client is bounded: no redirects, 3 s connect, 5 s total, http(s) only, 4 in flight');
$ok(!str_contains($src, '$_GET') && !str_contains($src, '$_POST') && !str_contains($src, '$_REQUEST') && !str_contains($src, '$_COOKIE'), 'the checker never reads visitor input');
// it never throws
$boom = new WebRouteCheck($app, 'a.example', [], 'https', function () { throw new RuntimeException('boom'); }, $installed, $cacheDir, 300, $now + 1);
$r = $boom->run(true);
$ok($r['state'] === 'skipped' && str_contains($r['note'], 'could not run'), 'an exception inside the fetcher becomes a skipped note, never an error page');

// ---------------------------------------------------------------- cache
$clearCache(); $setMode([]); @unlink($hitFile);
$c1 = $mk();
$c1->run();
$n1 = count($hits());
$r2 = $c1->run();
$ok($r2['cached'] === true && count($hits()) === $n1, 'the second call within the TTL is served from the cache (no requests)');
$ok($mk(at: $now + 100)->run()['cached'] === true && count($hits()) === $n1, 'a fresh object within the TTL also hits the cache');
$ok($c1->cached() !== null && $c1->cached()['state'] === 'ok', 'cached() returns the last result without any network access');
$c1->run(true);
$ok(count($hits()) === $n1 * 2, 'a forced re-check goes to the network again');
$mk(at: $now + 301)->run();
$ok(count($hits()) === $n1 * 3, 'after the TTL (300 s) it checks again');
$a = $mk(['mcp_ready' => true, 'mcp_builtin' => true]);
$b = $mk(['mcp_ready' => false, 'mcp_builtin' => false]);
$a->run(true);
$ok($b->cached() === null, 'a result for one set of conditions is never served for another');
file_put_contents($installed, file_get_contents($installed) . "\n# local edit\n");
$ok($mk()->cached() === null, 'changing the installed routes file invalidates the cache');
copy("$root/deploy/templates/rivetit-locations.conf", $installed);

// ---------------------------------------------------------------- routes file state, rows and hints
$chk = $mk();
$ok($chk->fileState() === 'current', 'fileState: current');
file_put_contents($installed, "# older\n");
$ok($chk->fileState() === 'outdated', 'fileState: outdated');
$r = $chk->run(true);
$rows = $chk->rows($r);
$ok($r['file'] === 'outdated' && count($rows) === 2 && $rows[1]['label'] === 'Web server routes file' && $rows[1]['status'] === 'warn' && str_contains($rows[1]['detail'], 'apply-web-routes.sh'), 'an out-of-date routes file adds an amber row with the command');
$ok(str_contains((string) $chk->hint($r), 'older than this version'), 'and a hint for the Update page');
unlink($installed);
$ok($chk->fileState() === 'absent', 'fileState: absent (an older install that still has its rules inline)');
$rows = $chk->rows($chk->run(true));
$ok(count($rows) === 2 && str_contains($rows[1]['detail'], 'inline') && str_contains($rows[1]['detail'], 'rollback'), 'absent file: the row explains the one-command conversion, backup and rollback');
copy("$root/deploy/templates/rivetit-locations.conf", $installed);

// not nginx / Docker: nothing to say
$apache = WebRouteCheck::forApp($app, 'x.example.com', null, ['SERVER_SOFTWARE' => 'Apache/2.4.58 (Ubuntu)'], false);
$r = $apache->run(true);
$ok($r['state'] === 'skipped' && str_contains($r['note'], 'not served by nginx') && $apache->hint($r) === null, 'Apache: skipped with a note, no hint');
$docker = WebRouteCheck::forApp($app, 'x.example.com', null, ['SERVER_SOFTWARE' => 'nginx/1.27.0'], true);
$r = $docker->run(true);
$ok($r['state'] === 'skipped' && str_contains($r['note'], 'Docker') && $docker->hint($r) === null, 'Docker: skipped with a note (docker/nginx.conf is its single source), no hint');
$nginx = WebRouteCheck::forApp($app, 'x.example.com:8443', null, ['SERVER_SOFTWARE' => 'nginx/1.27.0'], false);
$ok($nginx->domain() === 'x.example.com' && str_contains($nginx->command(), '--domain x.example.com') && !str_contains($nginx->command(), '8443'), 'the command carries the bare domain (no port)');

// ---------------------------------------------------------------- ServerStatus integration
$db = mysqli_init();
$ss = new ServerStatus($db, $app, null, '/nonexistent/helper', $now, '/nonexistent/etc', $mk());
$m = new ReflectionMethod($ss, 'webServer');
$setMode(['missing' => ['mcp_authserver_metadata']]);
$rowsSs = $m->invoke($ss, true);
$ok($rowsSs[0]['label'] === 'Web server is missing routes (1)' && $rowsSs[0]['status'] === 'warn', 'ServerStatus: shows the "Web server is missing routes (N)" row');
$tasks = $ss->rootTasks();
$ok(count(array_filter($tasks, fn($t) => str_contains($t['command'], 'apply-web-routes.sh --domain 127.0.0.1'))) === 1, 'ServerStatus: the Server tasks list has the one-line command with a Copy button');
$boomSs = new ServerStatus($db, $app, null, '/nonexistent/helper', $now, '/nonexistent/etc', new WebRouteCheck($app, 'a.example', [], 'https', function () { throw new Error('fatal-ish'); }, $installed, $cacheDir, 300, $now + 7));
$rowsBoom = (new ReflectionMethod($boomSs, 'webServer'))->invoke($boomSs, true);
$ok($rowsBoom[0]['status'] === 'skip', 'ServerStatus: even a throwing checker only produces a skipped row; the page is never lost');
$noCheck = new ServerStatus($db, $app, null, '/nonexistent/helper', $now);
$ok(count(array_filter($noCheck->rootTasks(), fn($t) => str_contains($t['command'], 'apply-web-routes'))) === 0, 'ServerStatus without a checker (CLI, tests) behaves exactly as before');

// ---------------------------------------------------------------- never touches health, never writes nginx
foreach (['health/ready.php', 'health/live.php'] as $f) {
    $t = file_get_contents("$root/$f");
    $ok(!preg_match('~WebRoute|apply-web-routes~', $t), "$f does not know about the route check (the check can never affect readiness)");
}
$ok(!str_contains($src, '/etc/nginx') || preg_match('~INSTALLED_PATH~', $src) === 1, 'the app only ever READS the nginx routes file');
$ok(substr_count($src, 'file_put_contents') === 1 && str_contains($src, 'cacheWrite') && !preg_match('~file_put_contents\([^)]*(installedPath|INSTALLED_PATH)~', $src), 'the only file the checker writes is its own cache file in the temp dir');
$noComments = preg_replace('~^\s*(//|\*|/\*).*$~m', '', preg_replace('~/\*.*?\*/~s', '', $src));
$ok(!preg_match('~shell_exec|\bexec\(|\bsystem\(|passthru|proc_open|popen|\bsudo ~', $noComments), 'no code path in the app can run commands or rewrite nginx (the fix is a command for a person to run as root)');
$upd = file_get_contents("$root/admin/update.php");
$ok(str_contains($upd, 'cached()') && !str_contains($upd, '->run('), 'the Update page only reads the cached result (no network on page load)');

proc_terminate($proc); proc_close($proc);
wr_rmrf($tmp);
exit($failures() ? 1 : 0);
