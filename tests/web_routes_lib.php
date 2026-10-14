<?php
/*
 * Shared helpers for tests/web_routes_*.php: a PRIVATE nginx layout in a temp directory (own prefix, own config, logs and pid in the
 * temp dir) so `nginx -t` can be run on rendered configuration without touching, reading or reloading any real nginx. Nothing here
 * listens on a port (nginx -t does not bind) and nothing here writes outside the directory it is given.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function wr_root(): string { return dirname(__DIR__); }

function wr_tmpdir(string $tag): string
{
    $d = sys_get_temp_dir() . '/rivetit-webroutes-' . $tag . '-' . bin2hex(random_bytes(4));
    mkdir($d, 0700, true);
    return $d;
}

function wr_rmrf(string $d): void
{
    if (!is_dir($d)) { return; }
    foreach (scandir($d) as $f) {
        if ($f === '.' || $f === '..') { continue; }
        $p = "$d/$f";
        is_dir($p) && !is_link($p) ? wr_rmrf($p) : @unlink($p);
    }
    @rmdir($d);
}

function wr_nginx_bin(): ?string
{
    foreach (['/usr/sbin/nginx', '/usr/local/sbin/nginx', '/usr/bin/nginx', '/usr/local/nginx/sbin/nginx'] as $p) {
        if (is_executable($p)) { return $p; }
    }
    $w = trim((string) @shell_exec('command -v nginx 2>/dev/null'));
    return $w !== '' && is_executable($w) ? $w : null;
}

/**
 * Lay out a private nginx tree: nginx.conf (http{} includes conf.d/*.conf and sites-enabled/*), snippets/fastcgi-php.conf and
 * fastcgi params (same shape as Debian's), the shared rate-limit zone, and one self-signed certificate for every vhost.
 * @return string the directory (also the nginx prefix and conf dir)
 */
function wr_make_env(string $dir, bool $withZone = true): string
{
    foreach (['conf.d', 'sites-available', 'sites-enabled', 'snippets', 'logs', 'html', 'tmp'] as $s) { @mkdir("$dir/$s", 0755, true); }
    file_put_contents("$dir/nginx.conf", "pid $dir/logs/nginx.pid;\nerror_log $dir/logs/error.log;\nevents { worker_connections 16; }\n"
        . "http {\n    access_log $dir/logs/access.log;\n    client_body_temp_path $dir/tmp/body;\n    proxy_temp_path $dir/tmp/proxy;\n    fastcgi_temp_path $dir/tmp/fcgi;\n"
        . "    uwsgi_temp_path $dir/tmp/uwsgi;\n    scgi_temp_path $dir/tmp/scgi;\n"
        . "    include $dir/conf.d/*.conf;\n    include $dir/sites-enabled/*;\n}\n");
    file_put_contents("$dir/snippets/fastcgi-php.conf", "fastcgi_split_path_info ^(.+?\\.php)(/.*)\$;\ntry_files \$fastcgi_script_name =404;\n"
        . "set \$path_info \$fastcgi_path_info;\nfastcgi_param PATH_INFO \$path_info;\nfastcgi_index index.php;\ninclude fastcgi.conf;\n");
    $params = "fastcgi_param QUERY_STRING \$query_string;\nfastcgi_param REQUEST_METHOD \$request_method;\nfastcgi_param CONTENT_TYPE \$content_type;\n"
        . "fastcgi_param CONTENT_LENGTH \$content_length;\nfastcgi_param REQUEST_URI \$request_uri;\nfastcgi_param DOCUMENT_ROOT \$document_root;\n";
    file_put_contents("$dir/fastcgi_params", $params);
    file_put_contents("$dir/fastcgi.conf", $params . "fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;\n");
    if ($withZone) {
        file_put_contents("$dir/conf.d/itflow-rate-limit.conf", "limit_req_zone \$binary_remote_addr zone=itflow_login:10m rate=5r/m;\n");
    }
    $key = "$dir/test.key"; $crt = "$dir/test.crt";
    shell_exec('openssl req -x509 -nodes -newkey rsa:2048 -keyout ' . escapeshellarg($key) . ' -out ' . escapeshellarg($crt) . ' -days 2 -subj /CN=test.invalid >/dev/null 2>&1');
    return $dir;
}

/** Put a vhost into the private tree (certificate paths re-pointed at the private test certificate) and enable it. */
function wr_install_vhost(string $dir, string $name, string $text): string
{
    $text = preg_replace('~^(\s*ssl_certificate\s+)\S+;~m', '${1}' . $dir . '/test.crt;', $text);
    $text = preg_replace('~^(\s*ssl_certificate_key\s+)\S+;~m', '${1}' . $dir . '/test.key;', $text);
    // This nginx build binds the listen sockets even under -t, and ports 80/443 are not ours (nor allowed without root): every
    // listen line becomes a private unix socket inside the temp dir (ssl kept). Listening is not what these tests are about.
    static $n = 0;
    $text = preg_replace_callback('~^(\s*)listen\s+([^;]*);~m', static function (array $m) use ($dir, &$n): string {
        $n++;
        return $m[1] . 'listen unix:' . $dir . '/l' . $n . '.sock' . (preg_match('~\bssl\b~', $m[2]) ? ' ssl' : '') . ';';
    }, $text);
    $f = "$dir/sites-available/$name";
    file_put_contents($f, $text);
    @unlink("$dir/sites-enabled/$name");
    symlink($f, "$dir/sites-enabled/$name");
    return $f;
}

/** @return array{0:bool,1:string} [passed, nginx output] */
function wr_nginx_t(string $dir): array
{
    $bin = wr_nginx_bin();
    $cmd = escapeshellarg($bin) . ' -t -e ' . escapeshellarg("$dir/logs/error.log") . ' -p ' . escapeshellarg("$dir/") . ' -c ' . escapeshellarg("$dir/nginx.conf") . ' 2>&1';
    exec($cmd, $out, $rc);
    return [$rc === 0, implode("\n", $out)];
}

/** The routes file exactly as apply-web-routes.sh installs it. */
function wr_routes_text(): string { return file_get_contents(wr_root() . '/deploy/templates/rivetit-locations.conf'); }

/** Render the per-site template the way deploy/install.sh does (envsubst placeholders + the mode strip); no shell needed. */
function wr_render_vhost(string $templatePath, string $domain, string $app, string $sock, bool $proxy): string
{
    $t = file_get_contents($templatePath);
    $t = str_replace(['${DOMAIN}', '${APP_ROOT}', '${PHP_SOCK}', '${SSL_CERT_PATH}', '${SSL_CERT_KEY_PATH}'],
        [$domain, $app, $sock, "/etc/ssl/certs/$domain.crt", "/etc/ssl/private/$domain.key"], $t);
    $t = preg_replace('~\A.*?^(server \{)~ms', '$1', $t, 1);   // the documentation header goes first (install.sh does the same)
    if ($proxy) {
        $t = preg_replace('~[ \t]*# BEGIN DIRECT-TLS ONLY.*?# END DIRECT-TLS ONLY\n~s', '', $t);
        $t = preg_replace('~^[ \t]*# (__PROXY_MODE_BLOCK__|BEGIN PROXY-MODE ONLY|END PROXY-MODE ONLY)\n~m', '', $t);
    } else {
        $t = preg_replace('~[ \t]*# BEGIN PROXY-MODE ONLY.*?# END PROXY-MODE ONLY\n~s', '', $t);
        $t = preg_replace('~^[ \t]*# (__PROXY_MODE_BLOCK__|BEGIN DIRECT-TLS ONLY|END DIRECT-TLS ONLY)\n~m', '', $t);
    }
    return $t;
}

function wr_checker(): array
{
    $fails = 0;
    return [
        function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) { $fails++; } },
        function () use (&$fails) { return $fails; },
    ];
}
