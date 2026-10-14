<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (pentest F-02)
/*
 * The rendered configuration against a REAL nginx binary, in a private temp tree (own prefix, config, logs and pid; the only sockets are
 * unix sockets inside the temp dir; no port is opened, nothing real is read or reloaded). Skips (exit 0, message) without nginx/openssl/curl.
 *   1. nginx -t passes on: a fresh install render (direct and proxy), the mwa-shaped vhost and the proxy vhost after conversion, and the
 *      multi-vhost shared-snippet layout (before, mixed and after conversion).
 *   2. Behaviour: the ORIGINAL inline vhost, the new split vhost, and the original converted by WebRoutesConverter are each served by a
 *      private nginx and asked the same ~70 questions; status and headers must be identical (apart from the documented deltas).
 *   php tests/web_routes_nginx.php
 */
require_once __DIR__ . '/web_routes_lib.php';
require_once __DIR__ . '/../src/Ops/WebRoutes.php';
require_once __DIR__ . '/../src/Ops/WebRoutesConverter.php';

use ITFlow\Ops\WebRoutesConverter;

[$ok, $failures] = wr_checker();
$root = wr_root();
$fx = "$root/tests/fixtures/web_routes";
if (wr_nginx_bin() === null) { echo "SKIP  no nginx binary on this machine\n"; exit(0); }
if (trim((string) shell_exec('command -v openssl')) === '' || !function_exists('curl_init')) { echo "SKIP  needs openssl and PHP curl\n"; exit(0); }
$conv = new WebRoutesConverter(wr_routes_text());
$cleanup = [];
$routes = wr_routes_text();

// ---------------------------------------------------------------- 1. nginx -t on every shape
$envT = function (array $vhosts, array $extra = []) use (&$cleanup, $routes): string {
    $d = wr_make_env(wr_tmpdir('t'));
    $cleanup[] = $d;
    file_put_contents("$d/snippets/rivetit-locations.conf", $routes);
    foreach ($extra as $n => $t) { file_put_contents("$d/snippets/$n", $t); }
    foreach ($vhosts as $n => $t) { wr_install_vhost($d, $n, $t); }
    return $d;
};
foreach (['direct' => false, 'proxy' => true] as $mode => $proxy) {
    $v = wr_render_vhost("$root/deploy/templates/nginx-vhost.conf.template", 'f.example.test', '/var/www/f', '/run/php/php8.5-fpm.sock', $proxy);
    [$p, $out] = wr_nginx_t($envT(['f.conf' => $v]));
    $ok($p, "nginx -t: fresh install render, $mode mode" . ($p ? '' : "\n$out"));
}
$mwa = file_get_contents("$fx/mwa-vhost.conf");
$cm = $conv->convert($mwa);
[$p, $out] = wr_nginx_t($envT(['mwa.conf' => $cm['content']]));
$ok($cm['status'] === 'changed' && $p, 'nginx -t: the mwa-shaped vhost after conversion' . ($p ? '' : "\n$out"));
$cp = $conv->convert(file_get_contents("$fx/proxy-vhost.conf"));
[$p, $out] = wr_nginx_t($envT(['p.conf' => $cp['content']]));
$ok($cp['status'] === 'changed' && $p, 'nginx -t: the proxy-mode vhost after conversion' . ($p ? '' : "\n$out"));
// the unconverted originals pass too (so a failure above is the conversion's, not the fixture's)
[$p] = wr_nginx_t($envT(['mwa.conf' => $mwa]));
$ok($p, 'nginx -t: the mwa-shaped ORIGINAL passes (control)');
// several vhosts sharing one snippet
$snip = file_get_contents("$fx/beta/itflow-locations.conf");
$bv = ['a.conf' => file_get_contents("$fx/beta/vhost-a.conf"), 'b.conf' => file_get_contents("$fx/beta/vhost-b.conf"), 'c.conf' => file_get_contents("$fx/beta/vhost-c.conf")];
[$p, $out] = wr_nginx_t($envT($bv, ['itflow-locations.conf' => $snip]));
$ok($p, 'nginx -t: the shared-snippet layout (3 vhosts, one old snippet), original' . ($p ? '' : "\n$out"));
$snipOpts = ['snippets' => ['snippets/itflow-locations.conf' => $snip]];
$conv3 = [];
foreach ($bv as $n => $t) { $conv3[$n] = $conv->convert($t, $snipOpts + ($n === 'c.conf' ? ['sock' => '/run/php/php8.5-fpm.sock'] : [])); }
$ok(array_filter($conv3, fn($r) => $r['status'] !== 'changed') === [], 'shared snippet: all three vhosts convert (C given its socket)');
[$p, $out] = wr_nginx_t($envT(array_map(fn($r) => $r['content'], $conv3)));
$ok($p, 'nginx -t: the shared-snippet layout converted to ONE routes file for all 3 vhosts (each with its own socket variable)' . ($p ? '' : "\n$out"));
$mixed = ['a.conf' => $conv3['a.conf']['content'], 'b.conf' => $bv['b.conf'], 'c.conf' => $bv['c.conf']];
[$p, $out] = wr_nginx_t($envT($mixed, ['itflow-locations.conf' => $snip]));
$ok($p, 'nginx -t: a mixed layout (one vhost converted, two still on the old snippet) coexists' . ($p ? '' : "\n$out"));
// the failure mode we rely on: a vhost that includes the routes but lacks the per-site variables is rejected, not silently wrong
$noVars = preg_replace('~^\s*set \$rivetit_php [^;]*;\n~m', '', $cm['content']);
[$p, $out] = wr_nginx_t($envT(['mwa.conf' => $noVars]));
$ok(!$p && str_contains($out, 'rivetit_php'), 'nginx -t REJECTS a vhost that includes the routes without $rivetit_php (so apply-web-routes.sh would roll back)');

// ---------------------------------------------------------------- 2. behaviour
class PrivateNginx
{
    public $proc;
    public function __construct(public string $dir, public string $sock) {}
    public function start(): bool
    {
        $bin = wr_nginx_bin();
        $this->proc = proc_open([$bin, '-p', "{$this->dir}/", '-c', "{$this->dir}/nginx.conf", '-e', "{$this->dir}/logs/error.log", '-g', 'daemon off; master_process off;'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', "{$this->dir}/logs/out.log", 'w'], 2 => ['file', "{$this->dir}/logs/err.log", 'w']], $pipes);
        for ($i = 0; $i < 100; $i++) { if (file_exists($this->sock)) { return true; } usleep(50000); }
        return false;
    }
    public function stop(): void { if (is_resource($this->proc)) { proc_terminate($this->proc, SIGTERM); usleep(100000); proc_close($this->proc); } }
    /** @return array{status:int, headers:array<string,string>} */
    public function get(string $path): array
    {
        $h = curl_init('https://t.example.test' . $path);
        $heads = [];
        curl_setopt_array($h, [CURLOPT_UNIX_SOCKET_PATH => $this->sock, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 10, CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_HEADERFUNCTION => function ($c, $l) use (&$heads) { if (str_contains($l, ':')) { [$n, $v] = explode(':', $l, 2); $heads[strtolower(trim($n))] = trim($v); } return strlen($l); }]);
        curl_exec($h);
        $st = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
        curl_close($h);
        foreach (['date', 'server', 'content-length', 'last-modified', 'etag', 'connection', 'transfer-encoding', 'keep-alive', 'accept-ranges'] as $skip) { unset($heads[$skip]); }
        return ['status' => $st, 'headers' => $heads];
    }
}

$docroot = wr_tmpdir('docroot');
$cleanup[] = $docroot;
foreach (['css/a.css', 'js/a.js', 'fonts/a.woff2', 'plugins/lib/x.js', 'plugins/barcode/barcode.php', 'plugins/PHPMailer/get_oauth_token.php', 'plugins/other/lib.php', 'plugins/TCPDF/tools/t.php',
    'uploads/a.txt', 'uploads/a.svg', 'uploads/kb/a.txt', 'uploads/training/a.pdf', 'uploads/x.php', 'index.php', 'login.php', 'setup.php', 'README.md', 'composer.json', 'composer.lock',
    'agent/ticket.php', 'agent/training_video_frame.php', 'client/login_odoo.php', 'agent/sse_ticket_stream.php', 'api/v1/index.php', 'mcp_server/index.php', 'src/Ops/X.php', 'includes/a.php',
    'tests/t.php', 'vendor/v.php', 'docs/a.md', 'deploy/install.sh', 'cron/cron.php', 'scripts/s.php', 'backups/b.zip', 'config/c.php', 'config.php', 'oauth/token.php', 'oauth/authorize.php',
    'kiosk/index.php', 'kiosk/includes/b.php', 'kiosk/page.php', 'health/live.php', 'restore/r.enc', 'a.html', '.env', '.git/config', '.well-known/acme-challenge/tok', 'api/v1/contacts/read.php'] as $f) {
    @mkdir(dirname("$docroot/$f"), 0755, true);
    file_put_contents("$docroot/$f", "x $f\n");
}
$urls = [
    '/', '/index.php', '/index.php/extra', '/login.php', '/setup.php', '/a.html', '/missing', '/missing/', '/a.css',
    '/css/a.css', '/css/missing.css', '/js/a.js', '/fonts/a.woff2', '/plugins/lib/x.js', '/plugins/missing.js',
    '/uploads/a.txt', '/uploads/a.svg', '/uploads/x.php', '/uploads/missing.png', '/uploads/kb/a.txt', '/uploads/kb/missing', '/uploads/training/a.pdf', '/uploads/training/missing',
    '/README.md', '/composer.json', '/composer.lock', '/rivetit-route-check.md', '/x.sql', '/x.yml',
    '/config.php', '/.git/config', '/.github/x', '/.env', '/.htaccess', '/.well-known/acme-challenge/tok', '/.well-known/acme-challenge/none', '/.well-known/x',
    '/restore/r.enc', '/docs/a.md', '/deploy/install.sh', '/docker/x', '/src/Ops/X.php', '/includes/a.php', '/tests/t.php', '/vendor/v.php', '/mcp_server/index.php', '/odoo_addons/x', '/setup/setup_functions.php',
    '/cron/cron.php', '/scripts/s.php', '/backups/b.zip', '/config/c.php', '/plugins/TCPDF/tools/t.php', '/api/v1/contacts/read.php', '/api/v1/index.php', '/api/v1/tickets/1', '/api/v1/docs', '/api/v2/x', '/api/v2/index.php',
    '/agent/ticket.php', '/agent/training_video_frame.php', '/agent/sse_ticket_stream.php', '/client/login_odoo.php', '/mcp', '/mcp/', '/.well-known/oauth-protected-resource', '/.well-known/oauth-authorization-server',
    '/oauth/token.php', '/oauth/authorize.php', '/kiosk/', '/kiosk/?d=' . str_repeat('A', 43), '/kiosk/?e=rvkf1_' . str_repeat('B', 43) . '&sn=SN1', '/kiosk/?e=bad', '/kiosk/page.php', '/kiosk/includes/b.php', '/health/live.php',
    '/plugins/barcode/barcode.php', '/plugins/PHPMailer/get_oauth_token.php', '/plugins/other/lib.php',
];
$intended = ['/plugins/barcode/barcode.php', '/plugins/PHPMailer/get_oauth_token.php', '/plugins/other/lib.php'];
$staticDirs = ['/css/', '/js/', '/fonts/', '/plugins/'];

$legacyTpl = "$root/tests/fixtures/web_routes/legacy-nginx-vhost.conf.template";
$newTpl = "$root/deploy/templates/nginx-vhost.conf.template";
foreach (['direct' => false, 'proxy' => true] as $mode => $proxy) {
    $legacy = str_replace('/var/www/t', $docroot, wr_render_vhost($legacyTpl, 't.example.test', $docroot, '/nonexistent/php-fpm.sock', $proxy));
    $fresh = wr_render_vhost($newTpl, 't.example.test', $docroot, '/nonexistent/php-fpm.sock', $proxy);
    $conv2 = $conv->convert($legacy);
    $ok($conv2['status'] === 'changed', "$mode: the original inline vhost converts");
    $variants = ['original inline' => [$legacy, false], 'new split' => [$fresh, true], 'original converted' => [$conv2['content'], true]];
    $answers = [];
    foreach ($variants as $label => [$vhost, $withRoutes]) {
        $d = wr_make_env(wr_tmpdir('b'));
        $cleanup[] = $d;
        if ($withRoutes) { file_put_contents("$d/snippets/rivetit-locations.conf", $routes); }
        $f = wr_install_vhost($d, 't.conf', $vhost);
        $nx = new PrivateNginx($d, $GLOBALS['wr_listen'][$f]['https'] ?? '');
        if (!$nx->start()) { $ok(false, "$mode/$label: private nginx started (" . @file_get_contents("$d/logs/err.log") . ')'); $nx->stop(); continue; }
        $res = [];
        foreach ($urls as $u) { $res[$u] = $nx->get($u); }
        $nx->stop();
        $answers[$label] = $res;
        $ok(count(array_filter($res, fn($r) => $r['status'] === 0)) === 0, "$mode/$label: every request got an answer from the private nginx");
    }
    if (count($answers) !== 3) { continue; }
    foreach (['new split', 'original converted'] as $label) {
        $diff = [];
        foreach ($urls as $u) {
            $a = $answers['original inline'][$u];
            $b = $answers[$label][$u];
            if ($a === $b) { continue; }
            if (in_array($u, $intended, true)) { continue; }
            // proxy mode: the original sent HSTS from the static-asset locations even there; the new routes send none (documented)
            if ($proxy) {
                $a2 = $a; unset($a2['headers']['strict-transport-security']);
                $okStatic = false;
                foreach ($staticDirs as $sd) { if (str_starts_with($u, $sd)) { $okStatic = true; } }
                if ($okStatic && $a2 === $b && isset($a['headers']['strict-transport-security'])) { continue; }
            }
            $diff[] = $u . ' (' . $a['status'] . ' vs ' . $b['status'] . ')';
        }
        $ok($diff === [], "$mode: '$label' answers all " . count($urls) . " URLs exactly like the original inline vhost (status and every header)" . ($diff ? ': ' . implode(', ', $diff) : ''));
    }
    // sanity of the harness: the answers are not all the same boring thing
    $statuses = array_unique(array_map(fn($r) => $r['status'], $answers['new split']));
    sort($statuses);
    $ok(count($statuses) >= 5, "$mode: the question list exercises many different answers (" . implode(',', $statuses) . ')');
    $n = $answers['new split'];
    $ok($n['/kiosk/?e=rvkf1_' . str_repeat('B', 43) . '&sn=SN1']['status'] === 302 && ($n['/kiosk/?e=rvkf1_' . str_repeat('B', 43) . '&sn=SN1']['headers']['location'] ?? '') === '/kiosk/#e=rvkf1_' . str_repeat('B', 43) . '&sn=SN1', "$mode: the kiosk ?e= fleet link is answered by nginx with the fragment redirect");
    $ok($n['/backups/b.zip']['status'] === 403 && $n['/uploads/kb/a.txt']['status'] === 403 && $n['/.env']['status'] === 403 && $n['/README.md']['status'] === 403 && $n['/src/Ops/X.php']['status'] === 403, "$mode: the private-path denials answer 403");
    $ok(($n['/uploads/a.txt']['headers']['content-disposition'] ?? '') === 'attachment', "$mode: uploads are forced to download");
    $ok(str_contains($n['/css/a.css']['headers']['cache-control'] ?? '', 'immutable') && str_contains($n['/js/a.js']['headers']['cache-control'] ?? '', 'max-age=3600'), "$mode: static caching headers");
    $ok(isset($n['/css/a.css']['headers']['strict-transport-security']) === !$proxy, "$mode: HSTS is " . ($proxy ? 'absent (the upstream proxy owns it)' : 'sent'));
    // the intended deltas, asserted rather than ignored
    $ok($n['/plugins/barcode/barcode.php']['status'] === 502 && ($n['/plugins/barcode/barcode.php']['headers']['cache-control'] ?? '') === 'no-store', "$mode: plugins/barcode/barcode.php now goes to PHP-FPM (502 here: the private test has no FPM) and is never cached");
    $ok($n['/plugins/PHPMailer/get_oauth_token.php']['status'] === 502, "$mode: the PHPMailer OAuth helper goes to PHP-FPM too");
    $ok($n['/plugins/other/lib.php']['status'] === 403, "$mode: any other plugin .php is refused (it used to be sent as raw source)");
    $o = $answers['original inline'];
    $ok($o['/plugins/barcode/barcode.php']['status'] === 200 && $o['/plugins/other/lib.php']['status'] === 200, "$mode: (the original served those as raw text, which is the defect this fixes)");
}

foreach ($cleanup as $c) { wr_rmrf($c); }
exit($failures() ? 1 : 0);
