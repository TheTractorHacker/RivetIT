<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/*
 * Static check: the repo's nginx templates must deny the non-public trees and metadata files (pentest F-02). No DB, no network.
 *   php tests/nginx_private_paths.php
 */
$fails = 0; $ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };
foreach (['deploy/templates/nginx-vhost.conf.template', 'docker/nginx.conf'] as $rel) {
    $s = file_get_contents(__DIR__ . '/../' . $rel);
    $ok(strpos($s, 'location ~ ^/(tests|vendor|src|includes|odoo_addons|mcp_server|setup/setup_functions\\.php) {') !== false, "$rel denies tests/vendor/src/includes/mcp_server");
    $ok(strpos($s, 'location ~* ^/[^/]+\\.(md|lock|sql|yml|yaml)$ {') !== false, "$rel denies *.md/lock/sql/yml at root");
    $ok(strpos($s, 'location = /composer.json') !== false, "$rel denies composer.json");
    // RivetCore 1.0.0-rc.11 stores file transfers from devices in backups/endpoint-transfer (includes/rmm_bootstrap.php, EA_TRANSFER_DIR): they are served by nobody but the technician download
    $ok(preg_match('/location \^~ \/backups\/ \{\s*deny all;/', $s) === 1, "$rel denies /backups/ (and with it backups/endpoint-transfer, backups/endpoint-agent, backups/rmm-state)");
    // add_header does not inherit into a location that declares its own: the static-asset locations must repeat the security headers (IT-4)
    foreach (['plugins', 'fonts', 'css', 'js'] as $loc) {
        preg_match('/location \^~ \/' . $loc . '\/ \{(.*?)\n    \}/s', $s, $b);
        $body = $b[1] ?? '';
        foreach (['Permissions-Policy', 'X-Content-Type-Options', 'Referrer-Policy', 'X-Frame-Options'] as $h) {
            $ok(strpos($body, "add_header $h ") !== false, "$rel: /$loc/ repeats $h");
        }
    }
}
$boot = file_get_contents(__DIR__ . '/../includes/rmm_bootstrap.php');
$ok(strpos($boot, "\$root . '/backups/endpoint-transfer'") !== false, 'the default transfer folder of the RMM module is inside the denied backups/ area');
exit($fails ? 1 : 0);
