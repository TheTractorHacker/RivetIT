<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (pentest F-02)
/*
 * Route registry and template. No DB, no network, no nginx.
 *   - the registry (src/Ops/WebRoutes.php) is well formed, and equals the routes template location for location, in order (drift guard);
 *   - the routes template, rendered into a vhost, is SEMANTICALLY the same as the original inline template (tests/fixtures/web_routes/
 *     legacy-nginx-vhost.conf.template, frozen from before the split) in both modes, apart from the two documented, intended deltas;
 *   - the per-site template carries exactly the per-site parts.
 *   php tests/web_routes_registry.php
 */
require_once __DIR__ . '/../src/Ops/NginxConf.php';
require_once __DIR__ . '/../src/Ops/WebRoutes.php';
require_once __DIR__ . '/web_routes_lib.php';

use ITFlow\Ops\NginxConf;
use ITFlow\Ops\WebRoutes;

[$ok, $failures] = wr_checker();
$root = wr_root();

// ---------------------------------------------------------------- registry shape
$entries = WebRoutes::all();
$ids = array_column($entries, 'id');
$ok(count($ids) === count(array_unique($ids)), 'registry ids are unique');
$ok(count(WebRoutes::keys()) === count(array_unique(WebRoutes::keys())), 'registry location keys are unique');
$shapeOk = true;
foreach ($entries as $e) {
    $shapeOk = $shapeOk && preg_match('/^[a-z0-9_]+$/', $e['id']) === 1
        && in_array($e['match'], ['', '=', '^~', '~', '~*'], true)
        && $e['path'] !== '' && $e['purpose'] !== '' && $e['feature'] !== ''
        && in_array($e['severity'], ['security', 'feature'], true);
}
$ok($shapeOk, 'every entry has id, match, path, purpose, feature and a known severity');
$probeOk = true;
$nProbes = 0;
foreach ($entries as $e) {
    $p = $e['probe'];
    if ($p === null) { continue; }
    $nProbes++;
    $path = $p['path'];
    $okPath = is_array($path) ? (isset($path['dir'], $path['ext']) && preg_match('/^[a-z]+$/', $path['dir']) === 1 && $path['ext'] !== []) : (is_string($path) && str_starts_with($path, '/'));
    $probeOk = $probeOk && $okPath && in_array($p['method'], ['GET', 'HEAD'], true) && $p['status'] !== []
        && array_filter($p['status'], 'is_int') === $p['status']
        && (!isset($p['requires']) || isset(WebRoutes::CONDITIONS[$p['requires']]))
        && (!isset($p['headers']) || is_array($p['headers'])) && (!isset($p['absent']) || is_array($p['absent']));
}
$ok($probeOk, "all $nProbes probes are read-only (GET/HEAD), have a status set, a rooted path and a known condition");
$ok($nProbes >= 20, 'most routes have a probe');
$ok(WebRoutes::SENTINEL['status'] === [200], 'the sentinel is the PHP liveness endpoint');
$mustProbe = ['mcp', 'mcp_resource_metadata', 'mcp_authserver_metadata', 'oauth_token_endpoints', 'oauth_authorize', 'kiosk_entry', 'acme', 'private_trees', 'php', 'uploads_kb', 'backups_dir'];
$have = array_filter($mustProbe, fn($id) => WebRoutes::find($id)['probe'] !== null);
$ok(count($have) === count($mustProbe), 'health, mcp, the well-known pair, oauth, kiosk ?e=, ACME and the private-path denials are all probed');
$as = WebRoutes::find('mcp_authserver_metadata')['probe'];
$ok(($as['requires'] ?? '') === 'mcp_builtin' && $as['status'] === [200], 'the authorization-server metadata probe only applies when built-in sign-in is on');
$ok(strpos(WebRoutes::applyCommand('/var/www/app.example.com', 'app.example.com'), 'sudo /var/www/app.example.com/deploy/apply-web-routes.sh --domain app.example.com') === 0, 'the fix command is filled in from the app path and domain');
$ok(strpos(WebRoutes::applyCommand("/var/www/a b", 'x.test'), "'") !== false, 'a path with a space is quoted in the fix command');

// ---------------------------------------------------------------- drift guard: registry == template
$routesText = file_get_contents("$root/deploy/templates/rivetit-locations.conf");
$tplNodes = NginxConf::parse($routesText);
$tplLocs = NginxConf::locations($tplNodes);
$tplKeys = array_map(fn($l) => NginxConf::locationKey($l), $tplLocs);
$ok($tplKeys === WebRoutes::keys(), 'DRIFT GUARD: the registry lists exactly the routes template\'s locations, in the same order');
$onlyTpl = array_diff($tplKeys, WebRoutes::keys());
$onlyReg = array_diff(WebRoutes::keys(), $tplKeys);
$ok($onlyTpl === [] && $onlyReg === [], 'no location is missing from the registry' . ($onlyTpl ? ': ' . implode(', ', $onlyTpl) : '') . ' / none only in the registry' . ($onlyReg ? ': ' . implode(', ', $onlyReg) : ''));
$other = array_filter($tplNodes, fn($n) => $n['name'] !== 'location');
$ok($other === [], 'the routes template holds only location blocks (nothing server-level to lose in a shared file)');
$code = implode("\n", array_filter(explode("\n", $routesText), fn($l) => !preg_match('~^\s*#~', $l)));   // comments may mention anything
$ok(strpos($code, '${') === false && strpos($code, 'unix:/run') === false && strpos($code, 'php8.') === false, 'the routes template has no placeholder and no hard-coded PHP socket (it is shareable between sites)');
$ok(substr_count($code, 'fastcgi_pass $rivetit_php;') >= 10 && !preg_match('~fastcgi_pass (?!\$rivetit_php;)~', $code), 'every fastcgi_pass uses the per-site $rivetit_php');
$ok(!preg_match('~Strict-Transport-Security "~', $code), 'HSTS in the routes file always comes from the per-site $rivetit_hsts');
$ok(strpos($routesText, 'itflow_login') !== false, 'the routes file still rate-limits sign-in (and documents the zone it needs)');

// ---------------------------------------------------------------- semantic equivalence with the original inline template
$legacyPath = "$root/tests/fixtures/web_routes/legacy-nginx-vhost.conf.template";
$newTpl = "$root/deploy/templates/nginx-vhost.conf.template";
$normFor = static function (bool $dropHsts): Closure {
    return static function (array $lines) use ($dropHsts): array {
        $lines = array_map(static function (string $l): string {
            $l = preg_replace('~^(\s*fastcgi_pass) \S+$~', '$1 $rivetit_php', $l);
            return preg_replace('~^(\s*add_header Strict-Transport-Security) \S.*? always$~', '$1 $rivetit_hsts always', $l);
        }, $lines);
        // Proxy mode: the original removed the HSTS lines it marked; the new file keeps one with an EMPTY value, which nginx does not send.
        return $dropHsts ? array_values(array_filter($lines, static fn($l) => !str_contains($l, 'Strict-Transport-Security'))) : $lines;
    };
};
$server443 = static function (string $text): array {
    foreach (NginxConf::servers(NginxConf::parse($text)) as $s) {
        foreach ($s['children'] as $c) { if ($c['name'] === 'listen' && str_contains(implode(' ', $c['args']), '443')) { return $s; } }
    }
    throw new RuntimeException('no 443 server');
};
foreach (['direct' => false, 'proxy' => true] as $mode => $proxy) {
    $norm = $normFor($proxy);
    $old = $server443(wr_render_vhost($legacyPath, 'd.example.test', '/var/www/d', '/run/php/php8.5-fpm.sock', $proxy));
    $new = $server443(wr_render_vhost($newTpl, 'd.example.test', '/var/www/d', '/run/php/php8.5-fpm.sock', $proxy));
    // 1. the same locations, same order, same bodies
    $oldLocs = NginxConf::locations($old['children']);
    $newTplLocs = $tplLocs;
    $ok(count($oldLocs) === count($newTplLocs), "$mode: the same number of locations (" . count($oldLocs) . ')');
    $same = 0;
    $diffs = [];
    foreach ($oldLocs as $i => $o) {
        $n = $newTplLocs[$i] ?? null;
        if ($n === null || NginxConf::locationKey($o) !== NginxConf::locationKey($n)) { $diffs[] = "position $i: " . NginxConf::locationKey($o); continue; }
        if ($norm(NginxConf::flatten($o)) === $norm(NginxConf::flatten($n))) { $same++; continue; }
        $diffs[] = NginxConf::locationKey($o);
    }
    $ok($diffs === ['^~ /plugins/'], "$mode: every location is identical to the original except ^~ /plugins/ (the one intended delta); got: " . implode(' | ', $diffs));
    if ($proxy) {
        // The one behavioural difference in proxy mode: the original sent HSTS from the four static-asset locations even there
        // (they had no marker), against its own comment; the new file sends none when the proxy owns HSTS.
        $hstsIn = array_values(array_filter(array_map(fn($l) => preg_grep('~Strict-Transport-Security~', NginxConf::flatten($l)) ? NginxConf::locationKey($l) : null, $oldLocs)));
        $ok($hstsIn === ['^~ /plugins/', '^~ /fonts/', '^~ /css/', '^~ /js/'], 'proxy: the original sent HSTS only from the four static-asset locations (now none: the upstream proxy owns HSTS)');
    }
    // 2. the plugins delta is exactly the added nested blocks, nothing else
    $oPl = NginxConf::flatten($oldLocs[array_search('^~ /plugins/', array_map([NginxConf::class, 'locationKey'], $oldLocs), true)]);
    $nPl = NginxConf::flatten($tplLocs[array_search('^~ /plugins/', $tplKeys, true)]);
    $nPlTop = array_values(array_filter($nPl, fn($l) => !str_starts_with($l, '  ') && !str_starts_with($l, '}') && !str_starts_with($l, 'location ')));
    $ok($norm($oPl) === $norm($nPlTop), "$mode: ^~ /plugins/ keeps its original headers; the delta is only the nested PHP allow-list and deny");
    // 3. server-level directives (everything except the locations) of the old inline vhost == the new per-site vhost minus the managed block
    $srv = static fn(array $s) => array_values(array_filter($s['children'], fn($c) => $c['name'] !== 'location'));
    $flat = static function (array $nodes): array { return array_map(fn($c) => $c['name'] . ' ' . implode(' ', $c['args']), $nodes); };
    $newSrv = $srv($new);
    $managed = array_values(array_filter($newSrv, fn($c) => ($c['name'] === 'set' && str_starts_with($c['args'][0] ?? '', '$rivetit_')) || $c['name'] === 'include'));
    $newPlain = array_values(array_filter($newSrv, fn($c) => !in_array($c, $managed, true)));
    $ok($flat($srv($old)) === $flat($newPlain), "$mode: the per-site server-level directives (listen, root, TLS, headers, ...) are unchanged");
    $setNames = array_map(fn($c) => $c['name'] === 'set' ? $c['args'][0] : 'include ' . $c['args'][0], $managed);
    $ok($setNames === ['$rivetit_php', '$rivetit_hsts', 'include snippets/rivetit-locations.conf'], "$mode: the managed block is exactly two set lines and one include");
    $last = end($newSrv);
    $ok($last['name'] === 'include', "$mode: the include is the last thing in the server block (the locations sit where they always did)");
    $hs = array_values(array_filter($managed, fn($c) => ($c['args'][0] ?? '') === '$rivetit_hsts'))[0]['args'][1] ?? 'missing';
    $ok($mode === 'direct' ? $hs === 'max-age=63072000; includeSubDomains' : $hs === '', "$mode: \$rivetit_hsts is " . ($mode === 'direct' ? 'the HSTS value' : 'empty (nginx then sends no HSTS header)'));
    $ps = array_values(array_filter($managed, fn($c) => ($c['args'][0] ?? '') === '$rivetit_php'))[0]['args'][1] ?? '';
    $ok($ps === 'unix:/run/php/php8.5-fpm.sock', "$mode: \$rivetit_php carries the socket given at install time");
}

// ---------------------------------------------------------------- the original rules, expressed once more as raw text checks
$legacyText = file_get_contents($legacyPath);
preg_match_all('~^    location (.+?) \{~m', $legacyText, $m);
$legacyKeys = array_map(fn($s) => trim($s), $m[1]);
// "location ~ ^/api/v1/.+/.+\.php$ { deny all; return 403; }" is on one line; the regex above captures through the first brace
$ok(count($legacyKeys) === count($tplKeys), 'the frozen original template and the routes template have the same count of locations (' . count($legacyKeys) . ')');
$ok($legacyKeys === $tplKeys, 'and in the same order, byte for byte (a raw text check independent of the parser)');

// ---------------------------------------------------------------- per-site template
$site = file_get_contents($newTpl);
$ok(substr_count($site, "\n    location ") === 0, 'the per-site template no longer contains any location block');
$ok(strpos($site, 'include snippets/rivetit-locations.conf;') !== false && strpos($site, '# BEGIN RIVETIT ROUTES') !== false && strpos($site, '# END RIVETIT ROUTES') !== false, 'the per-site template has the managed include block');
$ok(strpos($site, '# __PROXY_MODE_BLOCK__') !== false, 'the install.sh sentinel is still there');

// ---------------------------------------------------------------- docs read the same list
$doc = file_get_contents("$root/docs/WEB_ROUTES.md");
$missingInDoc = array_filter($ids, fn($id) => !str_contains($doc, '| `' . $id . '` |'));
$ok($missingInDoc === [], 'docs/WEB_ROUTES.md lists every registry entry (regenerate its table when the registry changes)' . ($missingInDoc ? ': ' . implode(', ', $missingInDoc) : ''));
$ok(str_contains($doc, 'apply-web-routes.sh --domain') && str_contains($doc, '--dry-run') && str_contains($doc, '--rollback') && str_contains($doc, 'Docker') && str_contains($doc, 'Apache') && str_contains($doc, 'Adding a route'), 'the doc covers the one command, dry run, rollback, Docker/Apache and how to add a route');

// ---------------------------------------------------------------- install.sh wiring
$inst = file_get_contents("$root/deploy/install.sh");
$ok(strpos($inst, 'rivetit-locations.conf') !== false && strpos($inst, '/etc/nginx/snippets/rivetit-locations.conf') !== false, 'install.sh installs the shared routes file');
$ok(strpos($inst, 'install -m 644 -o root -g root "${routes_template}" "${routes_dest}"') < strpos($inst, 'nginx -t >"${nginx_test_log}"'), 'install.sh installs the routes file BEFORE it runs nginx -t');
$upd = file_get_contents("$root/deploy/update.sh");
$ok(strpos($upd, 'apply_web_routes') !== false && strpos($upd, 'apply-web-routes.sh') !== false, 'update.sh runs apply-web-routes.sh');
$ok(preg_match('~apply_web_routes\(\) \{.*return 0\n\}~s', $upd) === 1 && substr_count($upd, "\n    apply_web_routes\n") === 1, 'update.sh calls it once, and the function never fails the update');

exit($failures() ? 1 : 0);
