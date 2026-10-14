<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (pentest F-02)
/*
 * The trust model of deploy/apply-web-routes.sh and deploy/update.sh: nothing from the (web-writable) app tree runs as root.
 *   - the permission check (root-owned, no group/world write, parents too) and the refusals it drives;
 *   - the privilege drop: the PHP tool is only ever started through runuser, and cannot write to disk;
 *   - the root-side validator: every dangerous directive in a poisoned template or a tampered converter result is rejected;
 *   - the human review gate: diff hash, --yes-diff-sha, --yes after --dry-run, the interactive prompt;
 *   - update.sh only runs a root-owned tool, and only as a dry run;
 *   - --install-root-copy.
 * Private temp trees only; the "root-owned" owner is stood in for by the current user through a test-only hook that the scripts
 * honour only when they are NOT root. Skips (exit 0) without nginx/openssl.
 *   php tests/web_routes_security.php
 */
require_once __DIR__ . '/web_routes_lib.php';

[$ok, $failures] = wr_checker();
$root = wr_root();
$fx = "$root/tests/fixtures/web_routes";
if (wr_nginx_bin() === null || trim((string) shell_exec('command -v openssl')) === '') { echo "SKIP  needs nginx and openssl\n"; exit(0); }
$me = posix_getuid();
$cleanup = [];
$env = function (array $vhosts = null) use (&$cleanup, $fx): string {
    $d = mkenv('sec', $vhosts ?? ['mwa.conf' => file_get_contents("$fx/mwa-vhost.conf")]);
    $cleanup[] = $d;
    return $d;
};
$tmp = function (string $tag) use (&$cleanup): string { $d = wr_tmpdir($tag); $cleanup[] = $d; return $d; };
/** A private copy of the files root would run or install, in the real layout. */
$tree = function (string $t) use ($root): string {
    foreach (['deploy/apply-web-routes.sh', 'deploy/lib/web_routes_tool.php', 'src/Ops/NginxConf.php', 'src/Ops/WebRoutes.php', 'src/Ops/WebRoutesConverter.php', 'deploy/templates/rivetit-locations.conf'] as $f) {
        @mkdir(dirname("$t/$f"), 0755, true);
        copy("$root/$f", "$t/$f");
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($t, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    chmod($t, 0755);
    foreach ($it as $p) { chmod($p->getPathname(), $p->isDir() ? 0755 : 0644); }
    chmod("$t/deploy/apply-web-routes.sh", 0755);
    return "$t/deploy/apply-web-routes.sh";
};
$mine = ['RIVETIT_TRUST_UID' => (string) $me];

// ---------------------------------------------------------------- the permission check
$t = $tmp('trust');
$stop = ['RIVETIT_TRUST_UID' => (string) $me, 'RIVETIT_TRUST_STOP' => $t];
$tree("$t/ok");
$stop['RIVETIT_TRUST_STOP'] = $t;
$check = function (string $path, array $extra = []) use ($stop): array { return apply('', ['--check-trust', $path], $extra + $stop, false); };
foreach (glob("$t/ok/*", GLOB_ONLYDIR) as $dir) { chmod($dir, 0755); }
[$rc, $out] = $check("$t/ok/deploy/apply-web-routes.sh");
$ok($rc === 0 && str_contains($out, 'trusted'), 'permission check: root-owned (stood in by the current user), no group/world write anywhere up to the boundary: trusted');
chmod("$t/ok/deploy/apply-web-routes.sh", 0775);
[$rc, $out] = $check("$t/ok/deploy/apply-web-routes.sh");
$ok($rc === 1 && str_contains($out, 'group or others'), 'permission check: a group-writable file is NOT trusted');
chmod("$t/ok/deploy/apply-web-routes.sh", 0755);
chmod("$t/ok/deploy", 0777);
[$rc, $out] = $check("$t/ok/deploy/apply-web-routes.sh");
$ok($rc === 1 && str_contains($out, 'group or others') && str_contains($out, 'deploy'), 'permission check: a world-writable PARENT directory makes the file untrusted');
chmod("$t/ok/deploy", 0755);
chmod("$t/ok", 0775);
[$rc, $out] = $check("$t/ok/deploy/apply-web-routes.sh");
$ok($rc === 1, 'permission check: a group-writable grandparent makes it untrusted too');
chmod("$t/ok", 0755);
[$rc, $out] = $check("$t/ok/deploy/apply-web-routes.sh", ['RIVETIT_TRUST_UID' => (string) ($me + 1)]);
$ok($rc === 1 && str_contains($out, 'not owned by root'), 'permission check: a file owned by someone else is NOT trusted');
file_put_contents("$t/loose.php", "x"); chmod("$t/loose.php", 0666);
symlink("$t/loose.php", "$t/ok/deploy/link.php");
[$rc, $out] = $check("$t/ok/deploy/link.php");
$ok($rc === 1, 'permission check: a symlink in a trusted folder pointing at a web-writable file is NOT trusted (the target is checked)');
[$rc, $out] = $check("$t/ok/deploy/missing.sh");
$ok($rc === 1 && str_contains($out, 'missing'), 'permission check: a missing path is not trusted');
[$rc, $out] = $check('relative/path.sh');
$ok($rc === 1 && str_contains($out, 'absolute'), 'permission check: a relative path is refused');
// without the test hook the real check wants uid 0, so none of this tree (owned by a normal user) is trusted
exec('RIVETIT_ROUTES_TEST_NONROOT= ' . escapeshellarg("$root/deploy/apply-web-routes.sh") . ' --check-trust ' . escapeshellarg("$t/ok/deploy/apply-web-routes.sh") . ' 2>&1', $o2, $rc);
$ok($rc === 1 && str_contains(implode("\n", $o2), 'not owned by root'), 'permission check: with no hook the owner must be root, and a normal user\'s files never pass');

// the same text in both scripts
$apply = file_get_contents("$root/deploy/apply-web-routes.sh");
$upd = file_get_contents("$root/deploy/update.sh");
$blk = fn(string $s) => substr($s, strpos($s, '# BEGIN trust-check'), strpos($s, '# END trust-check') - strpos($s, '# BEGIN trust-check'));
$ok($blk($apply) !== '' && $blk($apply) === $blk($upd), 'the trust-check function is the same text in apply-web-routes.sh and update.sh');

// ---------------------------------------------------------------- refusal when the tree is web-writable
$d = $env();
$before = snapshot($d);
[$rc, $out] = apply($d, ['--domain', 'mwa.example.test', '--dry-run'], ['RIVETIT_ROUTES_TEST_STRICT' => '1']);
$ok($rc === 1 && str_contains($out, 'Refusing to run unattended') && str_contains($out, '--install-root-copy') && snapshot($d) === $before, 'unattended run from a copy a non-root account can write: refused, with the way out, nothing touched');
[$rc, $out] = apply($d, ['--domain', 'mwa.example.test', '--dry-run', '--allow-untrusted-script'], ['RIVETIT_ROUTES_TEST_STRICT' => '1']);
$ok($rc === 0 && str_contains($out, 'not root-protected'), '--allow-untrusted-script runs it, with a warning, after a human decided');
$t2 = $tmp('trusted');
$script2 = $tree("$t2/app");
[$rc, $out] = apply($d, ['--domain', 'mwa.example.test', '--dry-run'], ['RIVETIT_ROUTES_TEST_STRICT' => '1', 'RIVETIT_TRUST_UID' => (string) $me, 'RIVETIT_TRUST_STOP' => $t2], true, true, $script2);
$ok($rc === 0 && !str_contains($out, 'not root-protected'), 'a copy that IS root-protected runs without complaint');

// ---------------------------------------------------------------- update.sh: never applies, only a dry run of a root-owned tool
$sites = is_dir('/etc/nginx/sites-enabled');   // update.sh's own precondition (read-only look)
$fnText = (function () use ($upd) { preg_match('/^apply_web_routes\(\) \{\n.*?^\}\n/ms', $upd, $m); return $m[0] ?? ''; })();
$ok($fnText !== '' && preg_match('/"\$\{script\}" --domain "\$\{domain\}" --dry-run/', $fnText) === 1 && !preg_match('/"\$\{script\}"[^\n]*--yes/', $fnText) && substr_count($fnText, '"${script}" --') === 1 && !str_contains($fnText, 'source '), 'update.sh: apply_web_routes starts the tool in exactly one place, and only with --dry-run (never --yes / --yes-diff-sha)');
if (!$sites) {
    echo "SKIP  /etc/nginx/sites-enabled absent: update.sh's behaviour checks need it\n";
} else {
    $mkApp = function (string $tag) use ($tmp, $tree): array {
        $a = $tmp($tag);
        $script = $tree("$a/app");
        file_put_contents("$a/app/config.php", "<?php\n\$config_base_url = 'x.example.test';\n");
        // a stand-in tool that only records how it was called
        file_put_contents($script, "#!/usr/bin/env bash\necho \"\$@\" >> " . escapeshellarg("$a/called.log") . "\n[ -f " . escapeshellarg("$a/nodiff") . " ] || echo 'DIFF-SHA: " . str_repeat('a', 64) . "'\n");
        chmod($script, 0755);
        @mkdir("$a/bin"); file_put_contents("$a/bin/id", "#!/bin/sh\n[ \"\$1\" = \"-u\" ] && { echo 0; exit 0; }\nexec /usr/bin/id \"\$@\"\n"); chmod("$a/bin/id", 0755);
        return [$a, "$a/app"];
    };
    $harness = function (string $a, string $app, array $env) use ($fnText, $blk, $upd): string {
        $h = "set -u\ninfo(){ echo \"INFO \$*\"; }\nwarn(){ echo \"WARN \$*\"; }\nannounce(){ echo \"ACTION \$*\"; }\nsuccess(){ echo \"OK \$*\"; }\ncommand_exists(){ return 0; }\n"
            . 'APP_DIR=' . escapeshellarg($app) . "\n" . $blk($upd) . "\n" . $fnText . "\napply_web_routes\n";
        file_put_contents("$a/h.sh", $h);
        $e = '';
        foreach ($env as $k => $v) { $e .= "$k=" . escapeshellarg($v) . ' '; }
        exec("PATH=" . escapeshellarg("$a/bin") . ":\$PATH $e bash " . escapeshellarg("$a/h.sh") . ' 2>&1', $out);
        return implode("\n", $out);
    };
    // (1) a web-writable tree: nothing runs, the command is printed
    [$a, $app] = $mkApp('upd1');
    $out = $harness($a, $app, []);
    $ok(!file_exists("$a/called.log") && str_contains($out, 'NOT applied') && str_contains($out, "sudo $app/deploy/apply-web-routes.sh --domain x.example.test --dry-run") && str_contains($out, '--install-root-copy'), 'update.sh: tool not root-owned -> NOT run, prints the exact command and the root-copy hint, exits cleanly');
    // (2) a trusted tree (owner stood in for root): runs as a DRY RUN only
    [$a, $app] = $mkApp('upd2');
    $out = $harness($a, $app, ['RIVETIT_ROUTES_TEST_NONROOT' => '1', 'RIVETIT_TRUST_UID' => (string) $me, 'RIVETIT_TRUST_STOP' => $a]);
    $log = (string) @file_get_contents("$a/called.log");
    $ok(trim($log) === "--domain x.example.test --dry-run", 'update.sh: a root-protected tool is run with --domain and --dry-run, and nothing else: "' . trim($log) . '"');
    $ok(str_contains($out, 'out of date') && str_contains($out, 'did not change nginx'), 'update.sh: says the routes are out of date and that it changed nothing');
    // (3) trusted and current
    [$a, $app] = $mkApp('upd3');
    touch("$a/nodiff");
    $out = $harness($a, $app, ['RIVETIT_ROUTES_TEST_NONROOT' => '1', 'RIVETIT_TRUST_UID' => (string) $me, 'RIVETIT_TRUST_STOP' => $a]);
    $ok(str_contains($out, 'routes are current'), 'update.sh: nothing in the dry run -> routes are current');
    // (4) one file group-writable -> not run
    [$a, $app] = $mkApp('upd4');
    chmod("$app/src/Ops/WebRoutes.php", 0664);
    $out = $harness($a, $app, ['RIVETIT_ROUTES_TEST_NONROOT' => '1', 'RIVETIT_TRUST_UID' => (string) $me, 'RIVETIT_TRUST_STOP' => $a]);
    $ok(!file_exists("$a/called.log") && str_contains($out, 'NOT applied') && str_contains($out, 'group or others'), 'update.sh: ONE group-writable file the tool loads is enough to refuse (and it names why)');
    // (5) the parent directory is writable
    [$a, $app] = $mkApp('upd5');
    chmod("$app/deploy", 0777);
    $out = $harness($a, $app, ['RIVETIT_ROUTES_TEST_NONROOT' => '1', 'RIVETIT_TRUST_UID' => (string) $me, 'RIVETIT_TRUST_STOP' => $a]);
    $ok(!file_exists("$a/called.log") && str_contains($out, 'NOT applied'), 'update.sh: a world-writable parent directory -> refused');
}

// ---------------------------------------------------------------- privilege drop
$d = $env();
$bin = "$d/shims"; mkdir($bin);
file_put_contents("$bin/runuser", "#!/usr/bin/env bash\necho \"runuser \$*\" >> " . escapeshellarg("$d/drop.log") . "\nshift 3\nexport RIVETIT_VIA_RUNUSER=1\nexec \"\$@\"\n");
file_put_contents("$bin/php", "#!/usr/bin/env bash\necho \"via=\${RIVETIT_VIA_RUNUSER:-0} \$1 \$2\" >> " . escapeshellarg("$d/php.log") . "\nexec " . escapeshellarg(PHP_BINARY) . " \"\$@\"\n");
chmod("$bin/runuser", 0755); chmod("$bin/php", 0755);
[$rc, $out] = apply($d, ['--domain', 'mwa.example.test', '--dry-run'], ['RIVETIT_RUNUSER_BIN' => "$bin/runuser", 'PATH' => "$bin:" . getenv('PATH')]);
$php = (string) @file_get_contents("$d/php.log");
$drop = (string) @file_get_contents("$d/drop.log");
$ok($rc === 0 && preg_match_all('/^via=1 /m', $php) >= 3 && !preg_match('/^via=0 /m', $php), 'privilege drop: every start of the PHP tool went through runuser (' . substr_count($php, "\n") . ' starts, none direct)');
foreach (['registry-keys', 'detect', 'convert'] as $sub) { $ok(str_contains($php, $sub), "privilege drop: '$sub' ran unprivileged"); }
$ok(str_contains($drop, 'runuser -u ' . get_current_user() . ' -- cat -- ') && str_contains($drop, 'deploy/templates/rivetit-locations.conf'), 'privilege drop: even reading the routes template from the app tree goes through the unprivileged user');
$ok(preg_match('/^runuser -u \S+ -- /m', $drop) === 1 && substr_count($drop, "\n") === substr_count($php, "\n") + 1, 'privilege drop: runuser started exactly the PHP tool calls and the template read, nothing else');
$toolSrc = file_get_contents("$root/deploy/lib/web_routes_tool.php");
$ok(!preg_match('/\b(file_put_contents|fopen|fwrite\((?!STDERR)|unlink|rename|mkdir|rmdir|chmod|chown|symlink|copy|exec|shell_exec|system|passthru|proc_open|popen)\s*\(/', $toolSrc), 'the tool cannot write files or run commands (stdout only)');
$ok(!preg_match('/^\s*(source|\.)\s+\S/m', $apply) && !preg_match('/\bexec\s+"?\$\{?(APP|SCRIPT_DIR|TOOL)/', $apply) && !preg_match('/\bbash\s+"?\$\{?(APP|TOOL)/', $apply) && !str_contains($apply, 'common.sh'), 'root-side script: sources and executes nothing from the app tree');
$ok(str_contains($apply, 'owner="nobody"') && str_contains($apply, 'Refusing to run the tool as uid 0'), 'a root-owned tool is run as "nobody", and never as uid 0');

// ---------------------------------------------------------------- the validator: poisoned routes template
$d = $env();
$routes = wr_routes_text();
$before = snapshot($d);
$inRoot = fn(string $line) => str_replace("location / {\n    try_files \$uri \$uri/ =404;\n}", "location / {\n    try_files \$uri \$uri/ =404;\n    $line\n}", $routes);
$poison = [
    'load_module (top level)' => $routes . "\nload_module /tmp/evil.so;\n",
    'include of an unknown path (top level)' => $routes . "\ninclude /etc/passwd;\n",
    'include of an unknown path (in a location)' => str_replace('include snippets/fastcgi-php.conf;', 'include /tmp/evil.conf;', $routes),
    'proxy_pass to an outside host' => $inRoot('proxy_pass http://evil.example;'),
    'alias outside the app root' => $inRoot('alias /etc/;'),
    'root outside the app root' => $inRoot('root /;'),
    'access_by_lua_file' => $inRoot('access_by_lua_file /tmp/evil.lua;'),
    'access_by_lua_block' => $inRoot('access_by_lua_block { os.execute("id") }'),
    'content_by_lua_block' => $inRoot('content_by_lua_block { ngx.say(1) }'),
    'perl handler' => $inRoot('perl /tmp/evil;'),
    'perl_set' => $inRoot('perl_set $x \'sub { return 1 }\';'),
    'js_content' => $inRoot('js_content main.handler;'),
    'js_import (top level)' => $routes . "\njs_import main from /tmp/evil.js;\n",
    'a literal fastcgi_pass socket' => preg_replace('/fastcgi_pass \$rivetit_php;/', 'fastcgi_pass unix:/run/other.sock;', $routes, 1),
    'a script outside $document_root' => $inRoot('fastcgi_param SCRIPT_FILENAME /etc/passwd;'),
    'an arbitrary fastcgi_param' => $inRoot('fastcgi_param HTTP_PROXY http://evil.example;'),
    'set' => $inRoot('set $x 1;'),
    'rewrite' => $inRoot('rewrite ^ http://evil.example/ permanent;'),
    'return to another site' => $inRoot('return 301 http://evil.example/;'),
    'a header with a request variable' => $inRoot('add_header X-Evil $http_x_forwarded_for;'),
    'sub_filter' => $inRoot('sub_filter a b;'),
    'ssi' => $inRoot('ssi on;'),
    'a server-level directive in the routes file' => $routes . "\nserver_tokens off;\n",
    'a location the registry does not list' => $routes . "\nlocation = /evil { return 403; }\n",
    'a registry location removed' => preg_replace('/location \^~ \/backups\/ \{.*?\n\}\n/s', '', $routes, 1),
    'a duplicated location' => $routes . "\nlocation ^~ /backups/ { deny all; }\n",
    'a file that does not parse' => $routes . "\nlocation = /x {\n",
];
foreach ($poison as $label => $text) {
    $f = "$d/poison.conf";
    file_put_contents($f, $text);
    $changed = $text !== $routes;
    [$rc, $out] = apply($d, ['--domain', 'mwa.example.test', '--dry-run', '--routes-file', $f]);
    $ok($changed && $rc === 1 && preg_match('/^REJECTED: /m', $out) === 1 && snapshot($d) === $before && !reloaded($d), "validator rejects: $label");
}
[$rc, $out] = apply($d, ['--domain', 'mwa.example.test', '--dry-run']);
$ok($rc === 0 && str_contains($out, 'DIFF-SHA'), 'control: the genuine template passes the validator');

// ---------------------------------------------------------------- the validator: tampered converter output
$d = $env();
$before = snapshot($d);
$tamper = [
    'an extra server block with an alias' => '$out . "\nserver { listen 9; location / { alias /; } }\n"',
    'a changed server_name' => 'str_replace("server_name", "server_name evil.example", $out)',
    'the include pointing elsewhere' => 'str_replace("include snippets/rivetit-locations.conf;", "include /etc/shadow;", $out)',
    'a removed access rule on a location of the vhost\'s own' => 'str_replace("        allow 10.0.0.0/8;\n", "", $out)',
    'a removed local location' => 'preg_replace("/    location = \/robots.txt \{.*?\n    \}\n/s", "", $out)',
    'a PHP socket outside /run' => 'str_replace("unix:/run/php/php8.5-fpm.sock;", "unix:/etc/evil.sock;", $out)',
    'a load_module line' => '"load_module /tmp/x.so;\n" . $out',
    'a second managed include' => 'str_replace("# END RIVETIT ROUTES", "include snippets/rivetit-locations.conf;\n    # END RIVETIT ROUTES", $out)',
    'an unexpected HSTS value' => 'str_replace("max-age=63072000; includeSubDomains\";\n    include", "max-age=1\";\n    include", $out)',
];
foreach ($tamper as $label => $expr) {
    $t3 = $tmp('tamper');
    $sc = $tree($t3);
    $src = file_get_contents("$t3/src/Ops/WebRoutesConverter.php");
    $patched = str_replace("\$res['content'] = \$out;", "\$res['content'] = $expr;", $src, $n);
    file_put_contents("$t3/src/Ops/WebRoutesConverter.php", $patched);
    [$rc, $out] = apply($d, ['--domain', 'mwa.example.test', '--dry-run'], [], true, true, $sc);
    $ok($n === 1 && $rc === 1 && preg_match('/^REJECTED: /m', $out) === 1 && snapshot($d) === $before && !reloaded($d), "validator rejects a tampered converter result: $label" . ($rc === 1 ? '' : " (rc=$rc)"));
}
$t3 = $tmp('control');
$sc = $tree($t3);
[$rc, $out] = apply($d, ['--domain', 'mwa.example.test', '--dry-run'], [], true, true, $sc);
$ok($rc === 0 && str_contains($out, 'DIFF-SHA'), 'control: an untampered copy of the tool passes');

// ---------------------------------------------------------------- the human review gate
$d = $env();
$before = snapshot($d);
[$rc, $out] = apply($d, ['--domain', 'mwa.example.test', '--dry-run']);
preg_match('/^DIFF-SHA: ([0-9a-f]{64})$/m', $out, $m);
$hash = $m[1] ?? '';
$ok(strlen($hash) === 64 && str_contains($out, '+    include snippets/rivetit-locations.conf;'), 'the dry run prints the diff and its hash');
[$rc2, $out2] = apply($d, ['--domain', 'mwa.example.test', '--dry-run']);
preg_match('/^DIFF-SHA: ([0-9a-f]{64})$/m', $out2, $m2);
$ok(($m2[1] ?? '') === $hash, 'the diff hash is reproducible (no timestamps or temp paths in it)');
[$rc, $out] = apply($d, ['--domain', 'mwa.example.test'], [], true, false);
$ok($rc === 1 && str_contains($out, 'without a human review') && str_contains($out, $hash) && snapshot($d) === $before && !reloaded($d), 'no terminal and no confirmation: refused, nothing changed, the hash to pass is shown');
[$rc, $out] = apply($d, ['--domain', 'mwa.example.test', '--yes-diff-sha', str_repeat('0', 64)], [], true, false);
$ok($rc === 1 && str_contains($out, 'not the changes you reviewed') && snapshot($d) === $before, '--yes-diff-sha with the wrong hash: refused');
[$rc, $out] = apply($d, ['--domain', 'mwa.example.test', '--yes-diff-sha', 'abc'], [], true, false);
$ok($rc === 1 && str_contains($out, '64-character'), '--yes-diff-sha must look like a hash');
@unlink("$d/state/last-dry-run");
[$rc, $out] = apply($d, ['--domain', 'mwa.example.test', '--yes'], [], true, false);
$ok($rc === 1 && str_contains($out, 'needs a --dry-run') && snapshot($d) === $before, '--yes without a dry run in this session: refused');
[$rc, $out] = apply($d, ['--domain', 'mwa.example.test', '--dry-run']);
$vh = "$d/sites-available/mwa.conf";
$orig = file_get_contents($vh);
file_put_contents($vh, str_replace('Root .htaccess', 'Root htaccess', $orig));   // inside a block the diff removes
$b2 = snapshot($d);
[$rc, $out] = apply($d, ['--domain', 'mwa.example.test', '--yes'], [], true, false);
$ok($rc === 1 && str_contains($out, 'needs a --dry-run') && snapshot($d) === $b2, '--yes after a dry run of a DIFFERENT diff (the vhost changed in between): refused');
file_put_contents($vh, $orig);
[$rc, $out] = apply($d, ['--domain', 'mwa.example.test', '--dry-run']);
file_put_contents("$d/state/last-dry-run", "$hash " . (time() - 7200) . "\n");
[$rc, $out] = apply($d, ['--domain', 'mwa.example.test', '--yes'], [], true, false);
$ok($rc === 1 && str_contains($out, 'needs a --dry-run') && snapshot($d) === $before, '--yes after a dry run that is more than an hour old: refused');
[$rc, $out] = apply($d, ['--domain', 'mwa.example.test', '--dry-run']);
[$rc, $out] = apply($d, ['--domain', 'mwa.example.test', '--yes'], [], true, false);
$ok($rc === 0 && str_contains($out, 'a dry run in this session showed exactly this diff') && reloaded($d), '--yes right after the dry run that showed this exact diff: applied');
// a stale hash after the files changed
$d = $env();
[$rc, $out] = apply($d, ['--domain', 'mwa.example.test', '--dry-run']);
preg_match('/^DIFF-SHA: ([0-9a-f]{64})$/m', $out, $m);
file_put_contents("$d/sites-available/mwa.conf", str_replace('Root .htaccess', 'Root htaccess', file_get_contents("$d/sites-available/mwa.conf")));
$b3 = snapshot($d);
[$rc, $out] = apply($d, ['--domain', 'mwa.example.test', '--yes-diff-sha', $m[1]], [], true, false);
$ok($rc === 1 && str_contains($out, 'not the changes you reviewed') && snapshot($d) === $b3 && !reloaded($d), 'a hash from before the vhost changed no longer authorises anything');
// a different routes template changes the hash
$d = $env();
[$rc, $out] = apply($d, ['--domain', 'mwa.example.test', '--dry-run']);
preg_match('/^DIFF-SHA: ([0-9a-f]{64})$/m', $out, $ma);
file_put_contents("$d/alt.conf", str_replace('300s', '301s', $routes));
[$rc, $out] = apply($d, ['--domain', 'mwa.example.test', '--dry-run', '--routes-file', "$d/alt.conf"]);
preg_match('/^DIFF-SHA: ([0-9a-f]{64})$/m', $out, $mb);
$ok(($ma[1] ?? 'a') !== ($mb[1] ?? 'a'), 'the hash covers the routes file too: a different template, a different hash');
// the interactive prompt (needs a pty)
if (trim((string) shell_exec('command -v script')) !== '') {
    $d = $env();
    $base = 'RIVETIT_ROUTES_TEST_NONROOT=1 ' . escapeshellarg("$root/deploy/apply-web-routes.sh") . ' --nginx-dir ' . escapeshellarg($d) . ' --nginx-conf ' . escapeshellarg("$d/nginx.conf") . ' --reload-cmd ' . escapeshellarg("touch $d/reloaded") . ' --state-dir ' . escapeshellarg("$d/state") . ' --domain mwa.example.test --backup-dir ';
    $before = snapshot($d);
    exec('printf "n\n" | script -qec ' . escapeshellarg($base . escapeshellarg("$d/bkn")) . ' /dev/null 2>&1', $o, $rc);
    $outN = implode("\n", $o);
    $ok(str_contains($outN, 'Apply these changes') && snapshot($d) === $before && !reloaded($d), 'interactive: the diff and hash are shown with a y/N question; "n" changes nothing');
    $o = [];
    exec('printf "y\n" | script -qec ' . escapeshellarg($base . escapeshellarg("$d/bky")) . ' /dev/null 2>&1', $o, $rc);
    $ok(str_contains(implode("\n", $o), 'applied and nginx reloaded') && reloaded($d), 'interactive: "y" applies');
} else {
    echo "SKIP  no 'script' utility for the interactive prompt checks\n";
}

// ---------------------------------------------------------------- install the root-owned copy
$d = $env();
$t4 = $tmp('rc');
$sc = $tree("$t4/src");
$rcDir = "$d/rootcopy";
[$rc, $out] = apply($d, ['--install-root-copy', '--root-copy-dir', $rcDir, '--dry-run'], [], true, true, $sc);
preg_match('/^DIFF-SHA: ([0-9a-f]{64})$/m', $out, $m);
$ok($rc === 0 && !is_dir($rcDir) && strlen($m[1] ?? '') === 64 && str_contains($out, 'deploy/apply-web-routes.sh') && str_contains($out, 'deploy/templates/rivetit-locations.conf'), '--install-root-copy --dry-run: lists the files with their sha256 and copies nothing');
[$rc, $out] = apply($d, ['--install-root-copy', '--root-copy-dir', $rcDir], [], true, false, $sc);
$ok($rc === 1 && !is_dir($rcDir) && str_contains($out, 'without a human review'), '--install-root-copy without confirmation: refused');
[$rc, $out] = apply($d, ['--install-root-copy', '--root-copy-dir', $rcDir, '--yes-diff-sha', $m[1]], [], true, false, $sc);
$okFiles = true;
foreach (['deploy/apply-web-routes.sh', 'deploy/lib/web_routes_tool.php', 'src/Ops/NginxConf.php', 'src/Ops/WebRoutes.php', 'src/Ops/WebRoutesConverter.php', 'deploy/templates/rivetit-locations.conf'] as $f) {
    $okFiles = $okFiles && is_file("$rcDir/$f") && md5_file("$rcDir/$f") === md5_file("$t4/src/$f")
        && (substr(sprintf('%o', fileperms("$rcDir/$f")), -3) === (str_ends_with($f, '.sh') ? '755' : '644'));
}
$ok($rc === 0 && $okFiles, '--install-root-copy with the matching hash: the six files are copied byte for byte, 755 for the script and 644 for the rest');
$ok(str_contains($out, 'only as fresh as this install'), 'it says the copy goes stale until refreshed');
// the installed copy works (same layout) and runs the tool from its own folder
[$rc, $out] = apply($d, ['--domain', 'mwa.example.test', '--dry-run'], [], true, true, "$rcDir/deploy/apply-web-routes.sh");
$ok($rc === 0 && str_contains($out, 'DIFF-SHA'), 'the installed copy runs from its own layout');
// a symlink in the source is refused
$t5 = $tmp('rc2');
$sc5 = $tree("$t5/src");
unlink("$t5/src/deploy/templates/rivetit-locations.conf");
symlink("$root/deploy/templates/rivetit-locations.conf", "$t5/src/deploy/templates/rivetit-locations.conf");
[$rc, $out] = apply($d, ['--install-root-copy', '--root-copy-dir', "$d/rc5", '--dry-run'], [], true, true, $sc5);
$ok($rc === 1 && str_contains($out, 'symlink') && !is_dir("$d/rc5"), '--install-root-copy refuses a symlinked source file');

foreach ($cleanup as $c) { wr_rmrf($c); }
exit($failures() ? 1 : 0);
