<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/*
 * Static check: the repo's nginx templates must deny the non-public trees and metadata files (pentest F-02). No DB, no network.
 *   php tests/nginx_private_paths.php
 */
$fails = 0; $ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };
foreach (['deploy/templates/nginx-vhost.conf.template', 'docker/nginx.conf'] as $rel) {
    $s = file_get_contents(__DIR__ . '/../' . $rel);
    $ok(strpos($s, 'location ~ ^/(tests|vendor|src|includes|odoo_addons|setup/setup_functions\\.php) {') !== false, "$rel denies tests/vendor/src/includes");
    $ok(strpos($s, 'location ~* ^/[^/]+\\.(md|lock|sql|yml|yaml)$ {') !== false, "$rel denies *.md/lock/sql/yml at root");
    $ok(strpos($s, 'location = /composer.json') !== false, "$rel denies composer.json");
}
exit($fails ? 1 : 0);
