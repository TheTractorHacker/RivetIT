<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (pentest F-02)
/*
 * The vhost converter and the nginx reader (src/Ops/NginxConf.php, WebRoutesConverter.php), on text only: no nginx, no files outside the
 * fixtures. Covers the real layouts (a hand-grown single vhost shaped like mwa's, the shared-snippet beta layout, proxy mode) and the
 * refusal cases (anything not provably safe is left untouched and explained).
 *   php tests/web_routes_convert.php
 */
require_once __DIR__ . '/../src/Ops/NginxConf.php';
require_once __DIR__ . '/../src/Ops/WebRoutes.php';
require_once __DIR__ . '/../src/Ops/WebRoutesConverter.php';
require_once __DIR__ . '/web_routes_lib.php';

use ITFlow\Ops\NginxConf;
use ITFlow\Ops\WebRoutes;
use ITFlow\Ops\WebRoutesConverter;

[$ok, $failures] = wr_checker();
$root = wr_root();
$fx = "$root/tests/fixtures/web_routes";
$conv = new WebRoutesConverter(wr_routes_text());
$keys = WebRoutes::keys();

$ownedLeft = static function (string $text) use ($keys): array {
    $left = [];
    foreach (NginxConf::servers(NginxConf::parse($text)) as $s) {
        foreach (NginxConf::locations($s['children']) as $l) { if (in_array(NginxConf::locationKey($l), $keys, true)) { $left[] = NginxConf::locationKey($l); } }
    }
    return $left;
};
$locKeys = static function (string $text): array {
    $out = [];
    foreach (NginxConf::servers(NginxConf::parse($text)) as $s) { foreach (NginxConf::locations($s['children']) as $l) { $out[] = NginxConf::locationKey($l); } }
    return $out;
};

// ---------------------------------------------------------------- the reader
$p = NginxConf::parse('# c
server { listen 443 ssl; location ~ "^d=([A-Za-z0-9_-]{43})$" { return 302 /x#$1; }
 location = /a { deny all; } # trailing
}
');
$ok(count($p) === 1 && $p[0]['name'] === 'server' && count($p[0]['children']) === 3, 'reader: quoted regex with braces, comments and blocks parse');
$ok(NginxConf::locationKey($p[0]['children'][1]) === '~ ^d=([A-Za-z0-9_-]{43})$' && NginxConf::locationKey($p[0]['children'][2]) === '= /a', 'reader: location keys are canonical');
foreach (['server { listen 80;' => 'unclosed block', 'server { listen 80 }' => 'missing semicolon', "server { return 'x; }" => 'unterminated string', 'listen 80; }' => 'stray brace'] as $bad => $why) {
    $threw = false;
    try { NginxConf::parse($bad); } catch (RuntimeException) { $threw = true; }
    $ok($threw, "reader: refuses $why");
}
$ok(NginxConf::parse('set $a "x;y"; include a.conf;')[0]['args'] === ['$a', 'x;y'], 'reader: a semicolon inside quotes does not end the directive');

// ---------------------------------------------------------------- mwa-shaped single vhost, direct TLS
$mwa = file_get_contents("$fx/mwa-vhost.conf");
$r = $conv->convert($mwa);
$ok($r['status'] === 'changed' && $r['reasons'] === [], 'mwa-shaped vhost: converted (' . implode('; ', $r['reasons']) . ')');
$ok($r['sock'] === 'unix:/run/php/php8.5-fpm.sock' && $r['hsts'] === 'direct', 'mwa-shaped vhost: PHP socket and HSTS mode auto-detected');
$ok($ownedLeft($r['content']) === [], 'mwa-shaped vhost: no location the routes file owns is left in the vhost (no duplicates)');
$ok($locKeys($r['content']) === ['= /robots.txt', '^~ /monitoring-agent/'], 'mwa-shaped vhost: the local, unrecognised locations stay, in order');
$ok($r['kept'] === ['= /robots.txt', '^~ /monitoring-agent/'], 'mwa-shaped vhost: they are reported as kept');
$ok(count($r['removed']) >= 38 && in_array('= /.well-known/oauth-authorization-server', $r['removed'], true) && in_array('= /mcp', $r['removed'], true), 'mwa-shaped vhost: the hand-added MCP blocks are recognised as owned and removed (' . count($r['removed']) . ' removed)');
$ok(substr_count($r['content'], "include snippets/rivetit-locations.conf;") === 1 && substr_count($r['content'], '# BEGIN RIVETIT ROUTES') === 1 && substr_count($r['content'], '# END RIVETIT ROUTES') === 1, 'mwa-shaped vhost: exactly one managed block');
$ok(str_contains($r['content'], 'set $rivetit_php unix:/run/php/php8.5-fpm.sock;') && str_contains($r['content'], 'set $rivetit_hsts "max-age=63072000; includeSubDomains";'), 'mwa-shaped vhost: the block carries this site\'s socket and HSTS');
$ok(str_contains($r['content'], 'managed by Certbot') && str_contains($r['content'], 'client_max_body_size 500M;') && str_contains($r['content'], 'allow 10.0.0.0/8;'), 'mwa-shaped vhost: everything else (certbot comment, server directives, the local allow/deny block) is untouched');
$ok(strpos($r['content'], 'Root .htaccess') === false && strpos($r['content'], 'added by hand for Claude') === false, 'mwa-shaped vhost: the comments attached to removed blocks went with them');
// the kiosk ?e= rule and every other missing route now come from the routes file
$ok(strpos($mwa, 'rvkf1_') === false, 'fixture sanity: the mwa-shaped vhost really lacks the kiosk ?e= rule');
$ok(strpos(wr_routes_text(), 'rvkf1_') !== false, 'fixture sanity: the routes file has it');
// idempotent
$r2 = $conv->convert($r['content']);
$ok($r2['status'] === 'unchanged' && $r2['content'] === $r['content'], 'mwa-shaped vhost: converting the result again changes nothing (idempotent)');
$ok($r2['includes_routes'] === true, 'mwa-shaped vhost: the converted vhost reports that it includes the routes file');
// an explicit socket overrides, and a re-run rewrites only the set line
$r3 = $conv->convert($r['content'], ['sock' => '/run/php/php8.4-fpm.sock']);
$ok($r3['status'] === 'changed' && str_contains($r3['content'], 'set $rivetit_php unix:/run/php/php8.4-fpm.sock;') && substr_count($r3['content'], 'set $rivetit_php') === 1 && str_replace('php8.4', 'php8.5', $r3['content']) === $r['content'], 'a PHP upgrade: --php-sock rewrites only the $rivetit_php line of the managed block');
$r4 = $conv->convert($r['content'], ['hsts' => 'proxy']);
$ok($r4['status'] === 'changed' && str_contains($r4['content'], 'set $rivetit_hsts "";'), 'an explicit hsts mode rewrites only the $rivetit_hsts line');
$custom = str_replace('max-age=63072000; includeSubDomains";', 'max-age=31536000";', $r['content']);
$ok($conv->convert($custom)['status'] === 'unchanged', 'a hand-tuned HSTS value inside the block is kept unless asked to change');

// ---------------------------------------------------------------- proxy mode single vhost (fresh render of the original template)
$proxy = file_get_contents("$fx/proxy-vhost.conf");
$rp = $conv->convert($proxy);
$ok($rp['status'] === 'changed' && $rp['hsts'] === 'proxy' && $rp['sock'] === 'unix:/run/php/php8.4-fpm.sock' && str_contains($rp['content'], 'set $rivetit_hsts "";'), 'proxy-mode vhost: converted, HSTS stays off, socket detected');
$ok($ownedLeft($rp['content']) === [] && $locKeys($rp['content']) === [], 'proxy-mode vhost: all 40 locations moved, none left behind');
$ok(str_contains($rp['content'], 'listen 8443 ssl http2;') && str_contains($rp['content'], 'absolute_redirect off;'), 'proxy-mode vhost: the proxy-mode server directives are untouched');

// ---------------------------------------------------------------- fresh install render is already current
$fresh = wr_render_vhost("$root/deploy/templates/nginx-vhost.conf.template", 'f.example.test', '/var/www/f', '/run/php/php8.5-fpm.sock', false);
$rf = $conv->convert($fresh);
$ok($rf['status'] === 'unchanged' && $rf['includes_routes'] === true, 'a vhost rendered by the new install.sh needs no conversion');

// ---------------------------------------------------------------- shared snippet layout (the beta box)
$snip = file_get_contents("$fx/beta/itflow-locations.conf");
$snipOpts = ['snippets' => ['snippets/itflow-locations.conf' => $snip]];
$a = file_get_contents("$fx/beta/vhost-a.conf");
$ra = $conv->convert($a, $snipOpts);
$ok($ra['status'] === 'changed' && $ra['sock'] === 'unix:/run/php/php8.4-fpm.sock' && $ra['hsts'] === 'proxy', 'shared snippet: vhost A converted; socket taken from the snippet, HSTS off (it sends none today)');
$ok(!str_contains($ra['content'], 'itflow-locations.conf') && str_contains($ra['content'], 'include snippets/rivetit-locations.conf;') && str_contains($ra['content'], 'ssl_certificate_key'), 'shared snippet: the old include is replaced, the rest of the vhost is intact');
$ok(count($ra['notes']) === 1 && str_contains($ra['notes'][0], 'left in place'), 'shared snippet: it tells the operator the old snippet file is left alone');
$rb = $conv->convert(file_get_contents("$fx/beta/vhost-b.conf"), $snipOpts);
$ok($rb['status'] === 'changed' && $rb['hsts'] === 'direct' && str_contains($rb['content'], 'set $rivetit_hsts "max-age=63072000; includeSubDomains";'), 'shared snippet: vhost B keeps its HSTS (server-level header found)');
$rc = $conv->convert(file_get_contents("$fx/beta/vhost-c.conf"), $snipOpts);
$ok($rc['status'] === 'manual' && $rc['content'] === file_get_contents("$fx/beta/vhost-c.conf") && str_contains(implode(' ', $rc['reasons']), 'more than one PHP socket'), 'shared snippet: vhost C uses two PHP sockets, so it is NOT converted until told which one');
$rc2 = $conv->convert(file_get_contents("$fx/beta/vhost-c.conf"), $snipOpts + ['sock' => '/run/php/php8.5-fpm.sock']);
$ok($rc2['status'] === 'changed' && $locKeys($rc2['content']) === [] && !str_contains($rc2['content'], 'itflow-locations') && substr_count($rc2['content'], 'set $rivetit_php unix:/run/php/php8.5-fpm.sock;') === 1, 'shared snippet: with --php-sock vhost C converts; its hand-added /mcp blocks are replaced by the shared ones');
$ok(!array_key_exists('snippets', ['x' => 1]) && $conv->convert($a)['status'] === 'manual', 'shared snippet: when the snippet file cannot be read the vhost is not touched');

// ---------------------------------------------------------------- refusals: never loosen, never guess
$base = $mwa;
$cases = [
    'an access rule the routes file lacks (allow/deny in an owned block)' => [
        str_replace("    location = /composer.json {\n        deny all;\n    }", "    location = /composer.json {\n        allow 10.1.0.0/16;\n        deny all;\n    }", $base), 'allow 10.1.0.0/16'],
    'an auth_basic on an owned block' => [
        str_replace("    location ^~ /backups/ {\n        deny all;", "    location ^~ /backups/ {\n        auth_basic \"x\";\n        deny all;", $base), 'auth_basic'],
    'a hand-made redirect on an owned block (return)' => [
        str_replace("    location ^~ /restore/ {\n        deny all;", "    location ^~ /restore/ {\n        return 301 https://elsewhere.example/;\n        deny all;", $base), 'return 301'],
    'a regex location of your own between the owned regexes' => [
        str_replace("    location ~ ^/(cron|scripts)/.*\\.php\$ {", "    location ~ ^/agent/special\\.php\$ {\n        return 410;\n    }\n\n    location ~ ^/(cron|scripts)/.*\\.php\$ {", $base), 'sits between'],
    'two different PHP sockets in owned blocks' => [
        str_replace("    location = /mcp {\n        include fastcgi_params;\n        fastcgi_param SCRIPT_FILENAME \$document_root/mcp_server/index.php;\n        fastcgi_param SCRIPT_NAME /mcp_server/index.php;\n        fastcgi_pass unix:/run/php/php8.5-fpm.sock;", "    location = /mcp {\n        include fastcgi_params;\n        fastcgi_param SCRIPT_FILENAME \$document_root/mcp_server/index.php;\n        fastcgi_param SCRIPT_NAME /mcp_server/index.php;\n        fastcgi_pass unix:/run/php/php7.4-fpm.sock;", $base), 'more than one PHP socket'],
    'variables already set by hand' => [
        str_replace("    root /var/www/mwa.example.test;", "    root /var/www/mwa.example.test;\n    set \$rivetit_php unix:/run/php/php8.5-fpm.sock;", $base), 'already sets'],
    'a file that does not parse' => [$base . "\nserver {", 'could not be read'],
    'nothing to convert' => ["server {\n    listen 80;\n    server_name x.test;\n    root /var/www/x;\n}\n", 'No RivetIT'],
];
foreach ($cases as $label => [$text, $needle]) {
    $res = $conv->convert($text);
    $ok($text !== $base || $label === 'nothing to convert', "refusal fixture '$label' really differs from the base");
    $ok($res['status'] === 'manual' && $res['content'] === $text && str_contains(implode(' ', $res['reasons']), $needle), "refuses (and leaves the text untouched): $label" . ($res['status'] === 'manual' ? '' : ' [got ' . $res['status'] . ']'));
}
// a restriction the routes file ALSO has is fine (the owned blocks in the fixture all carry their deny/return lines)
$ok($conv->convert($base)['status'] === 'changed', 'the fixture\'s own deny/return/allow lines inside owned blocks that match the routes file are not objections');
// an owned block that merely lacks newer lines (older template) is fine; so is a kiosk block that differs (superseded)
$oldKiosk = str_replace('rvkf1_', 'zzzz1_', file_get_contents("$fx/proxy-vhost.conf"));
$ok($conv->convert($oldKiosk)['status'] === 'changed', 'an older kiosk redirect differing from the current one is superseded, not an objection');

// ---------------------------------------------------------------- unknown locations stay exactly as written
$with = str_replace("    location = /robots.txt {", "    location ~* \\.(jpg|png)\$ {\n        expires 7d;\n    }\n\n    location = /robots.txt {", $mwa);
$rw = $conv->convert($with);
$ok($rw['status'] === 'changed' && in_array('~* \.(jpg|png)$', $rw['kept'], true) && str_contains($rw['content'], "expires 7d;"), 'a regex location of your own AFTER the owned group is kept (nginx order is unchanged)');
$ownedFirst = str_replace("    # BEGIN", "    # BEGIN", $mwa);
$before = preg_replace('~(\n    # Root \.htaccess)~', "\n    location ~* \\.(gif)\$ {\n        expires 1d;\n    }\n\$1", $mwa, 1);
$rbf = $conv->convert($before);
$ok($rbf['status'] === 'changed' && strpos($rbf['content'], 'expires 1d;') < strpos($rbf['content'], '# BEGIN RIVETIT ROUTES'), 'a regex location of your own BEFORE the owned group stays before the include');

exit($failures() ? 1 : 0);
