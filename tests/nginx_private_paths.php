<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/*
 * Static check: the repo's nginx templates must deny the non-public trees and metadata files (pentest F-02). No DB, no network.
 *   php tests/nginx_private_paths.php
 */
$fails = 0; $ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };
require_once __DIR__ . '/../src/Ops/NginxConf.php';
// The bare-metal rules live in the shared routes file (deploy/templates/rivetit-locations.conf, see docs/WEB_ROUTES.md); the per-site vhost
// template only includes it. Docker keeps its own single file.
foreach (['deploy/templates/rivetit-locations.conf', 'docker/nginx.conf'] as $rel) {
    $s = file_get_contents(__DIR__ . '/../' . $rel);
    $ok(strpos($s, 'location ~ ^/(tests|vendor|src|includes|odoo_addons|mcp_server|setup/setup_functions\\.php) {') !== false, "$rel denies tests/vendor/src/includes/mcp_server");
    $ok(strpos($s, 'location ~* ^/[^/]+\\.(md|lock|sql|yml|yaml)$ {') !== false, "$rel denies *.md/lock/sql/yml at root");
    $ok(strpos($s, 'location = /composer.json') !== false, "$rel denies composer.json");
    // RivetCore 1.0.0-rc.11 stores file transfers from devices in backups/endpoint-transfer (includes/rmm_bootstrap.php, EA_TRANSFER_DIR): they are served by nobody but the technician download
    $ok(preg_match('/location \^~ \/backups\/ \{\s*deny all;/', $s) === 1, "$rel denies /backups/ (and with it backups/endpoint-transfer, backups/endpoint-agent, backups/rmm-state)");
    // add_header does not inherit into a location that declares its own: the static-asset locations must repeat the security headers (IT-4)
    foreach (['plugins', 'fonts', 'css', 'js'] as $loc) {
        // Parsed, not pattern-matched: /plugins/ also holds a nested PHP location in the routes file.
        $body = [];
        foreach (ITFlow\Ops\NginxConf::servers(ITFlow\Ops\NginxConf::parse($s)) ?: [['children' => ITFlow\Ops\NginxConf::parse($s)]] as $srv) {
            foreach (ITFlow\Ops\NginxConf::locations($srv['children']) as $l) {
                if (ITFlow\Ops\NginxConf::locationKey($l) === "^~ /$loc/") {
                    foreach ($l['children'] as $c) { if ($c['name'] === 'add_header') { $body[] = $c['args'][0]; } }
                }
            }
        }
        foreach (['Permissions-Policy', 'X-Content-Type-Options', 'Referrer-Policy', 'X-Frame-Options'] as $h) {
            $ok(in_array($h, $body, true), "$rel: /$loc/ repeats $h");
        }
    }
}
$vhost = file_get_contents(__DIR__ . '/../deploy/templates/nginx-vhost.conf.template');
$ok(strpos($vhost, 'include snippets/rivetit-locations.conf;') !== false && substr_count($vhost, "\n    location ") === 0, 'the per-site vhost template includes the shared routes file and holds no location of its own');
$boot = file_get_contents(__DIR__ . '/../includes/rmm_bootstrap.php');
$ok(strpos($boot, "\$root . '/backups/endpoint-transfer'") !== false, 'the default transfer folder of the RMM module is inside the denied backups/ area');
exit($fails ? 1 : 0);
