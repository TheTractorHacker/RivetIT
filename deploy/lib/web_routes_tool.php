<?php
/*
 * Command-line helper for deploy/apply-web-routes.sh. Pure analysis: reads nginx config text, writes nothing to disk (stdout only),
 * never touches nginx, never needs the database or the app's autoloader. All the logic is in src/Ops/WebRoutesConverter.php
 * (so the tests exercise the same code); this file only wires arguments to it and prints one "KEY: value" line per fact.
 *
 * IT RUNS AS AN UNPRIVILEGED USER (deploy/apply-web-routes.sh drops privileges with runuser before starting it) and writes NOTHING
 * to disk: everything goes to stdout, which root has redirected into a file of its own and then validates as untrusted input.
 *
 *   php web_routes_tool.php detect        --nginx-dir=/etc/nginx [--domain=D ...] [--all] [--legacy-snippet=NAME ...]
 *   php web_routes_tool.php registry-keys                         (one nginx location key per line)
 *   php web_routes_tool.php convert --vhost=FILE --routes=TEMPLATE --nginx-dir=/etc/nginx
 *                                   [--sock=/run/php/phpX.Y-fpm.sock] [--hsts=direct|proxy] [--include=snippets/rivetit-locations.conf]
 *      prints "KEY: value" report lines, a line "---CONTENT---", then the new vhost text (byte for byte) to the end.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__, 2);
require_once $root . '/src/Ops/NginxConf.php';
require_once $root . '/src/Ops/WebRoutes.php';
require_once $root . '/src/Ops/WebRoutesConverter.php';

use ITFlow\Ops\NginxConf;
use ITFlow\Ops\WebRoutes;
use ITFlow\Ops\WebRoutesConverter;

function wr_args(array $argv): array
{
    $o = [];
    foreach (array_slice($argv, 2) as $a) {
        if (!str_starts_with($a, '--')) { fwrite(STDERR, "Unexpected argument: $a\n"); exit(3); }
        [$k, $v] = array_pad(explode('=', substr($a, 2), 2), 2, '1');
        $o[$k][] = $v;
    }
    return $o;
}

function wr_read(string $f): ?string
{
    if (!is_file($f) || !is_readable($f) || filesize($f) > 2 * 1024 * 1024) { return null; }
    $t = file_get_contents($f);
    return $t === false ? null : $t;
}

/** Include argument -> file path (null for globs and anything unreadable). */
function wr_resolve(string $spec, string $nginxDir): ?string
{
    if (str_contains($spec, '*') || str_contains($spec, '$')) { return null; }
    return str_starts_with($spec, '/') ? $spec : rtrim($nginxDir, '/') . '/' . $spec;
}

/** The include specs of a server block that point at a readable file. @return array<string,string> */
function wr_snippets(array $srv, string $nginxDir): array
{
    $out = [];
    foreach ($srv['children'] as $c) {
        if ($c['name'] !== 'include' || !isset($c['args'][0])) { continue; }
        $f = wr_resolve($c['args'][0], $nginxDir);
        $t = $f === null ? null : wr_read($f);
        if ($t !== null) { $out[$c['args'][0]] = $t; }
    }
    return $out;
}

$cmd = $argv[1] ?? '';
$o = wr_args($argv);
$one = static fn(string $k, ?string $d = null): ?string => isset($o[$k]) ? (string) end($o[$k]) : $d;

if ($cmd === 'detect') {
    $dir = rtrim((string) $one('nginx-dir', '/etc/nginx'), '/');
    $domains = array_map('strtolower', $o['domain'] ?? []);
    $legacy = array_merge(['itflow-locations.conf'], $o['legacy-snippet'] ?? []);
    $files = [];
    foreach (['sites-enabled', 'conf.d'] as $sub) {
        foreach (glob("$dir/$sub/*") ?: [] as $f) {
            $real = realpath($f);
            if ($real !== false && is_file($real)) { $files[$real] = true; }
        }
    }
    $hit = [];
    foreach (array_keys($files) as $f) {
        $t = wr_read($f);
        if ($t === null) { continue; }
        try { $servers = NginxConf::servers(NginxConf::parse($t)); } catch (\RuntimeException) { continue; }
        foreach ($servers as $srv) {
            $match = false;
            foreach ($srv['children'] as $c) {
                if ($c['name'] === 'server_name') {
                    foreach ($c['args'] as $n) { if (in_array(strtolower($n), $domains, true)) { $match = true; } }
                }
            }
            if (!$match && isset($o['all'])) {
                $owned = 0;
                $inc = false;
                $keys = WebRoutes::keys();
                foreach ($srv['children'] as $c) {
                    if ($c['name'] === 'include' && isset($c['args'][0]) && (basename($c['args'][0]) === WebRoutesConverter::ROUTES_BASENAME || in_array(basename($c['args'][0]), $legacy, true))) { $inc = true; }
                    if ($c['name'] === 'location' && in_array((string) NginxConf::locationKey($c), $keys, true)) { $owned++; }
                }
                $match = $inc || $owned >= 5;
            }
            if ($match) { $hit[$f] = true; }
        }
    }
    foreach (array_keys($hit) as $f) { echo $f, "\n"; }
    exit(0);
}

if ($cmd === 'registry-keys') {
    foreach (WebRoutes::keys() as $k) { echo $k, "\n"; }
    exit(0);
}

if ($cmd === 'convert') {
    $vhost = (string) $one('vhost');
    $routes = (string) $one('routes');
    $dir = (string) $one('nginx-dir', '/etc/nginx');
    $text = wr_read($vhost);
    $rt = wr_read($routes);
    if ($text === null || $rt === null) { fwrite(STDERR, "convert needs readable --vhost and --routes files\n"); exit(3); }
    $opts = ['sock' => $one('sock'), 'hsts' => $one('hsts'), 'include' => $one('include', 'snippets/' . WebRoutesConverter::ROUTES_BASENAME)];
    if (!in_array($opts['hsts'], [null, 'direct', 'proxy'], true)) { fwrite(STDERR, "--hsts must be direct or proxy\n"); exit(3); }
    $snippets = [];
    try {
        foreach (NginxConf::servers(NginxConf::parse($text)) as $srv) { $snippets += wr_snippets($srv, $dir); }
    } catch (\RuntimeException) { /* the converter reports the parse error itself */ }
    $opts['snippets'] = $snippets;
    $r = (new WebRoutesConverter($rt))->convert($text, $opts);
    echo 'RESULT: ', $r['status'], "\n", 'INCLUDES: ', $r['includes_routes'] ? 'yes' : 'no', "\n";
    if ($r['sock'] !== null) { echo 'SOCK: ', $r['sock'], "\n"; }
    if ($r['hsts'] !== null) { echo 'HSTS: ', $r['hsts'], "\n"; }
    foreach ($r['removed'] as $x) { echo 'REMOVED: ', $x, "\n"; }
    foreach ($r['kept'] as $x) { echo 'KEPT: ', $x, "\n"; }
    foreach ($r['notes'] as $x) { echo 'NOTE: ', $x, "\n"; }
    foreach ($r['reasons'] as $x) { echo 'REASON: ', $x, "\n"; }
    echo "---CONTENT---\n", $r['content'];
    exit(0);
}

fwrite(STDERR, "Usage: web_routes_tool.php detect|convert ...\n");
exit(3);
