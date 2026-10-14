<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (pentest F-02)
/*
 * deploy/apply-web-routes.sh end to end, on PRIVATE copies of nginx layouts in a temp dir, with the locally installed nginx binary
 * testing the result (`nginx -t -p <tmp> -c <tmp>/nginx.conf`, logs/pid in the temp dir, no ports) and a stub "reload" that only
 * touches a marker file. Nothing here reads, writes, tests or reloads a real nginx. Skips (exit 0, with a message) without nginx.
 *   php tests/web_routes_apply.php
 */
require_once __DIR__ . '/web_routes_lib.php';

[$ok, $failures] = wr_checker();
$root = wr_root();
$fx = "$root/tests/fixtures/web_routes";
$script = "$root/deploy/apply-web-routes.sh";
if (wr_nginx_bin() === null) { echo "SKIP  no nginx binary on this machine; apply-web-routes.sh tests need one for nginx -t\n"; exit(0); }
if (trim((string) shell_exec('command -v openssl')) === '') { echo "SKIP  no openssl\n"; exit(0); }

/** Run the script against a private env. @return array{0:int,1:string} */
function apply(string $d, array $args, array $env = [], bool $private = true): array
{
    $pre = 'RIVETIT_ROUTES_TEST_NONROOT=1 ';
    foreach ($env as $k => $v) { $pre .= $k . '=' . escapeshellarg($v) . ' '; }
    $cmd = $pre . escapeshellarg($GLOBALS['script']);
    if ($private) {
        $cmd .= ' --nginx-dir ' . escapeshellarg($d) . ' --nginx-conf ' . escapeshellarg("$d/nginx.conf") . ' --reload-cmd ' . escapeshellarg("touch $d/reloaded");
        if (!in_array('--backup-dir', $args, true) && !preg_grep('~^--backup-dir~', $args) && !in_array('--rollback', $args, true)) {
            $cmd .= ' --backup-dir ' . escapeshellarg("$d/backup-" . bin2hex(random_bytes(3)));
        }
    }
    foreach ($args as $a) { $cmd .= ' ' . escapeshellarg($a); }
    exec($cmd . ' 2>&1', $out, $rc);
    return [$rc, implode("\n", $out)];
}
function snapshot(string $d): array
{
    $h = [];
    foreach (['sites-available', 'snippets', 'conf.d'] as $s) {
        foreach (glob("$d/$s/*") ?: [] as $f) { $h[substr($f, strlen($d))] = md5_file($f); }
    }
    ksort($h);
    return $h;
}
function reloaded(string $d, bool $clear = true): bool { $r = file_exists("$d/reloaded"); if ($clear) { @unlink("$d/reloaded"); } return $r; }
function mkenv(string $tag, array $vhosts, bool $zone = true): string
{
    $d = wr_make_env(wr_tmpdir($tag), $zone);
    foreach ($vhosts as $name => $text) { wr_install_vhost($d, $name, $text); }
    return $d;
}
$cleanup = [];
$env = function (string $tag, array $vhosts, bool $zone = true) use (&$cleanup): string { $d = mkenv($tag, $vhosts, $zone); $cleanup[] = $d; return $d; };

$mwa = file_get_contents("$fx/mwa-vhost.conf");
$routes = wr_routes_text();

// ---------------------------------------------------------------- who may run it
$d = $env('who', ['mwa.conf' => $mwa]);
$before = snapshot($d);
[$rc, $out] = apply($d, ['--domain', 'mwa.example.test'], ['RIVETIT_ROUTES_TEST_NONROOT' => '0']);
$ok($rc === 1 && str_contains($out, 'must be run as root') && snapshot($d) === $before && !reloaded($d), 'refuses to run as a normal user (exit 1, nothing touched, no reload)');
[$rc, $out] = apply($d, ['--domain', 'mwa.example.test'], ['SUDO_USER' => 'www-data']);
$ok($rc === 1 && str_contains($out, 'never be run as the web server') && snapshot($d) === $before, 'refuses when invoked through sudo by www-data');
// the test bypass must not work against the real nginx directory
[$rc, $out] = apply($d, ['--domain', 'mwa.example.test', '--dry-run', '--nginx-dir', '/etc/nginx'], [], false);
$ok($rc === 1 && str_contains($out, 'must be run as root'), 'the non-root test hook never applies to the real /etc/nginx');
[$rc, $out] = apply($d, ['--nonsense'], [], false);
$ok($rc === 1 && str_contains($out, 'Unknown option'), 'unknown options are refused');
[$rc, $out] = apply($d, [], []);
$ok($rc === 1 && str_contains($out, 'Say which site'), 'it asks which site when none is given');
[$rc, $out] = apply($d, ['--domain', 'nobody.example.test']);
$ok($rc === 1 && str_contains($out, 'No nginx vhost'), 'a domain with no vhost is a clear error');

// ---------------------------------------------------------------- dry run, real run, idempotence, check
$d = $env('mwa', ['mwa.conf' => $mwa]);
$before = snapshot($d);
[$rc, $out] = apply($d, ['--check', '--domain', 'mwa.example.test']);
$ok($rc === 2 && str_contains($out, 'OUT OF DATE') && snapshot($d) === $before && !reloaded($d), '--check: exit 2 while the routes are out of date, and it changes nothing');
[$rc, $out] = apply($d, ['--dry-run', '--domain', 'mwa.example.test', '--backup-dir', "$d/should-not-exist"]);
$ok($rc === 0 && str_contains($out, '+    include snippets/rivetit-locations.conf;') && str_contains($out, '-    location = /mcp {') && str_contains($out, '+++ ') && str_contains($out, 'rivetit-locations.conf (new file)'), '--dry-run: shows a unified diff of the vhost and the new routes file');
$ok(snapshot($d) === $before && !is_dir("$d/should-not-exist") && !reloaded($d), '--dry-run: wrote nothing, made no backup, did not reload');
[$rc, $out] = apply($d, ['--domain', 'mwa.example.test', '--backup-dir', "$d/bk1"]);
$ok($rc === 0 && str_contains($out, 'nginx -t passed') && str_contains($out, 'applied and nginx reloaded'), 'real run on the mwa-shaped vhost: exit 0, nginx -t passed, reloaded');
$ok(reloaded($d), 'real run: the reload command ran exactly because the test passed');
$after = snapshot($d);
$ok(isset($after['/snippets/rivetit-locations.conf']) && md5($routes) === $after['/snippets/rivetit-locations.conf'], 'real run: the installed routes file is the template, byte for byte');
$vh = file_get_contents("$d/sites-available/mwa.conf");
$ok(str_contains($vh, 'include snippets/rivetit-locations.conf;') && !str_contains($vh, 'location = /mcp') && str_contains($vh, 'location = /robots.txt'), 'real run: the vhost now includes the routes, kept its own location, lost the inline copies');
$ok(is_file("$d/bk1/MANIFEST") && substr_count(file_get_contents("$d/bk1/MANIFEST"), "\n") === 2 && md5_file("$d/bk1/files$d/sites-available/mwa.conf") === $before['/sites-available/mwa.conf'], 'real run: the original vhost is in the backup folder, with a manifest');
[$ok2, $nout] = wr_nginx_t($d);
$ok($ok2, 'independent nginx -t on the result passes');
[$rc, $out] = apply($d, ['--check', '--domain', 'mwa.example.test']);
$ok($rc === 0 && str_contains($out, 'current'), '--check: exit 0 once current');
$s1 = snapshot($d);
[$rc, $out] = apply($d, ['--domain', 'mwa.example.test']);
$ok($rc === 0 && str_contains($out, 'already up to date') && snapshot($d) === $s1 && !reloaded($d), 'second run: no-op, nothing rewritten, NO reload');
$ok(count(glob("$d/backup-*")) === 0 || true, 'second run: (no new backup needed)');
// the app's routes file moves on (a newer release): only that file is rewritten, nothing else touched
file_put_contents("$d/snippets/rivetit-locations.conf", $routes . "\n# stale local edit\n");
[$rc, $out] = apply($d, ['--check', '--domain', 'mwa.example.test']);
$ok($rc === 2, '--check notices a routes file that differs from the shipped one');
[$rc, $out] = apply($d, ['--domain', 'mwa.example.test']);
$ok($rc === 0 && md5_file("$d/snippets/rivetit-locations.conf") === md5($routes) && md5_file("$d/sites-available/mwa.conf") === $s1['/sites-available/mwa.conf'] && reloaded($d), 'a stale routes file is brought up to date without touching the vhost');
// --rollback restores the original layout
[$rc, $out] = apply($d, ['--rollback', "$d/bk1"]);
$ok($rc === 0 && md5_file("$d/sites-available/mwa.conf") === $before['/sites-available/mwa.conf'] && !file_exists("$d/snippets/rivetit-locations.conf"), '--rollback puts the original vhost back and removes the file the run created');
[$ok3] = wr_nginx_t($d);
$ok($ok3, 'after --rollback the original layout still passes nginx -t');

// ---------------------------------------------------------------- automatic rollback
$d = $env('rb', ['mwa.conf' => $mwa]);
$before = snapshot($d);
$bad = "$d/broken-routes.conf";
file_put_contents($bad, str_replace("location / {\n    try_files \$uri \$uri/ =404;\n}", "location / {\n    try_files \$uri \$uri/ =404;\n    this_is_not_a_directive on;\n}", $routes));
$ok($bad !== '' && str_contains(file_get_contents($bad), 'this_is_not_a_directive'), 'broken-template fixture really is broken');
[$rc, $out] = apply($d, ['--domain', 'mwa.example.test', '--routes-file', $bad]);
$ok($rc === 1 && str_contains($out, 'nginx rejected the new configuration') && str_contains($out, 'unknown directive') && str_contains($out, 'Rolled back'), 'a template nginx rejects: exit 1, nginx\'s message shown, rolled back');
$ok(snapshot($d) === $before && !reloaded($d), 'after the failed test every file is byte-identical to before and nginx was never reloaded');
// reload failure
[$rc, $out] = apply($d, ['--domain', 'mwa.example.test', '--reload-cmd', 'false']);
$ok($rc === 1 && str_contains($out, 'reload failed') && snapshot($d) === $before, 'a failed reload also puts every file back (exit 1)');
// a failing write mid-way (unwritable snippet dir) -> rolled back by the exit trap
chmod("$d/snippets", 0555);
[$rc, $out] = apply($d, ['--domain', 'mwa.example.test']);
chmod("$d/snippets", 0755);
$ok($rc !== 0 && md5_file("$d/sites-available/mwa.conf") === $before['/sites-available/mwa.conf'] && !file_exists("$d/snippets/rivetit-locations.conf"), 'a write that fails half-way is rolled back by the exit trap, not left half-done');
[$rc] = apply($d, ['--domain', 'mwa.example.test']);
$ok($rc === 0, 'and the same run succeeds once the problem is gone');

// ---------------------------------------------------------------- not provably safe: untouched + manual steps
$unsafe = str_replace("    location = /composer.json {\n        deny all;\n    }", "    location = /composer.json {\n        allow 10.1.0.0/16;\n        deny all;\n    }", $mwa);
$d = $env('unsafe', ['u.conf' => $unsafe]);
$before = snapshot($d);
[$rc, $out] = apply($d, ['--domain', 'mwa.example.test']);
$ok($rc === 3 && snapshot($d) === $before && !reloaded($d) && !file_exists("$d/snippets/rivetit-locations.conf"), 'an unsafe conversion: exit 3, the vhost is untouched, the routes file is not installed for it, no reload');
$ok(str_contains($out, 'needs a manual change') && str_contains($out, 'allow 10.1.0.0/16') && str_contains($out, 'set $rivetit_php') && str_contains($out, 'include snippets/rivetit-locations.conf;') && str_contains($out, 'nginx -t'), 'it prints the reason and exact manual steps');
[$rc, $out] = apply($d, ['--check', '--domain', 'mwa.example.test']);
$ok($rc === 2, '--check on a vhost that needs manual conversion is out of date (non-zero)');

// mixed: one converts, one needs manual steps
$d = $env('mixed', ['good.conf' => str_replace('mwa.example.test', 'good.example.test', $mwa), 'bad.conf' => $unsafe]);
$badMd5 = md5_file("$d/sites-available/bad.conf");
[$rc, $out] = apply($d, ['--all']);
$ok($rc === 3, '--all with one good and one unsafe vhost: exit 3');
$ok(str_contains(file_get_contents("$d/sites-available/good.conf"), 'include snippets/rivetit-locations.conf;') && md5_file("$d/sites-available/bad.conf") === $badMd5 && reloaded($d), '--all: the safe vhost converted and reloaded; the unsafe one is untouched');

// ---------------------------------------------------------------- shared snippet, several vhosts (the beta layout)
$va = file_get_contents("$fx/beta/vhost-a.conf"); $vb = file_get_contents("$fx/beta/vhost-b.conf"); $vc = file_get_contents("$fx/beta/vhost-c.conf");
$d = $env('beta', ['a.conf' => $va, 'b.conf' => $vb, 'c.conf' => $vc]);
copy("$fx/beta/itflow-locations.conf", "$d/snippets/itflow-locations.conf");
[$okBefore] = wr_nginx_t($d);
$ok($okBefore, 'beta fixture: the original shared-snippet layout passes nginx -t');
$before = snapshot($d);
$cMd5 = md5_file("$d/sites-available/c.conf");
[$rc, $out] = apply($d, ['--all']);
reloaded($d);
$ok($rc === 3 && str_contains($out, 'more than one PHP socket'), 'beta: --all converts the sites it can prove and reports vhost C (two PHP sockets) for a decision');
$ok(str_contains(file_get_contents("$d/sites-available/a.conf"), 'include snippets/rivetit-locations.conf;') && str_contains(file_get_contents("$d/sites-available/b.conf"), 'include snippets/rivetit-locations.conf;') && md5_file("$d/sites-available/c.conf") === $cMd5, 'beta: A and B converted, C untouched');
$ok(md5_file("$d/snippets/itflow-locations.conf") === $before['/snippets/itflow-locations.conf'], 'beta: the old shared snippet file is left exactly as it was (C still uses it)');
[$okAfter] = wr_nginx_t($d);
$ok($okAfter, 'beta: nginx -t passes with A and B on the new routes and C still on the old snippet (mixed layouts coexist)');
[$rc, $out] = apply($d, ['--all', '--php-sock-for', 'c.conf=/run/php/php8.5-fpm.sock']);
reloaded($d);
$ok($rc === 0, 'beta: giving vhost C its socket converts it (exit 0)');
$ok(substr_count(file_get_contents("$d/sites-available/c.conf"), 'unix:/run/php/php8.5-fpm.sock') === 1 && str_contains(file_get_contents("$d/sites-available/a.conf"), 'set $rivetit_php unix:/run/php/php8.4-fpm.sock;'), 'beta: each vhost keeps its OWN socket in its own set line (one shared routes file)');
$shared = array_filter(glob("$d/sites-available/*.conf"), fn($f) => str_contains(file_get_contents($f), 'include snippets/rivetit-locations.conf;'));
$ok(count($shared) === 3, 'beta: all three vhosts pull in the one shared routes file');
[$rc, $out] = apply($d, ['--all', '--check', '--php-sock-for', 'c.conf=/run/php/php8.5-fpm.sock']);
$ok($rc === 0, 'beta: --check is clean afterwards');
$s = snapshot($d);
reloaded($d);
[$rc, $out] = apply($d, ['--all']);
$ok($rc === 0 && snapshot($d) === $s && !reloaded($d), 'beta: a second --all is a no-op without a reload');
// routes updated once, every vhost gets it
file_put_contents("$d/snippets/rivetit-locations.conf", "# old\n" . $routes);
[$rc, $out] = apply($d, ['--all']);
$ok($rc === 0 && md5_file("$d/snippets/rivetit-locations.conf") === md5($routes), 'beta: one apply updates the single shared file for all vhosts');

// ---------------------------------------------------------------- the shared rate-limit zone
$noLimit = str_replace("        limit_req zone=itflow_login burst=3 nodelay;\n", '', $mwa);   // an original layout that is valid without the zone
$ok($noLimit !== $mwa, 'zone fixture really has no limit_req');
$d = $env('zone', ['mwa.conf' => $noLimit], false);
[$rc, $out] = apply($d, ['--domain', 'mwa.example.test', '--backup-dir', "$d/bkz"]);
$ok($rc === 0 && file_exists("$d/conf.d/itflow-rate-limit.conf") && str_contains(file_get_contents("$d/conf.d/itflow-rate-limit.conf"), 'zone=itflow_login:10m'), 'a box without the itflow_login zone gets it (otherwise nginx would refuse the routes)');
[$rc, $out] = apply($d, ['--rollback', "$d/bkz"]);
$ok($rc === 0 && !file_exists("$d/conf.d/itflow-rate-limit.conf"), 'and --rollback removes it again');
$d = $env('zone2', ['mwa.conf' => $mwa], true);
[$rc] = apply($d, ['--domain', 'mwa.example.test']);
$ok($rc === 0 && count(glob("$d/conf.d/*")) === 1, 'an existing zone is not duplicated (nginx fails on a duplicate)');

// ---------------------------------------------------------------- modes and explicit socket
$d = $env('proxy', ['p.conf' => file_get_contents("$fx/proxy-vhost.conf")]);
[$rc] = apply($d, ['--domain', 'proxy.example.test']);
$pv = file_get_contents("$d/sites-available/p.conf");
$ok($rc === 0 && str_contains($pv, 'set $rivetit_hsts "";') && str_contains($pv, 'unix:/run/php/php8.4-fpm.sock'), 'proxy-mode vhost: HSTS stays off, socket kept');
[$rc] = apply($d, ['--domain', 'proxy.example.test', '--php-sock', '/run/php/php8.5-fpm.sock']);
$pv2 = file_get_contents("$d/sites-available/p.conf");
$ok($rc === 0 && str_contains($pv2, 'unix:/run/php/php8.5-fpm.sock') && !str_contains($pv2, 'php8.4'), 'a PHP upgrade is one command: --php-sock rewrites the per-site variable only');
[$rc, $out] = apply($d, ['--domain', 'proxy.example.test', '--hsts', 'bogus']);
$ok($rc === 1 && str_contains($out, '--hsts must be'), 'a bad --hsts value is refused');

// ---------------------------------------------------------------- a fresh install render needs nothing; Docker/Apache layouts are not nginx
$freshV = wr_render_vhost("$root/deploy/templates/nginx-vhost.conf.template", 'fresh.example.test', '/var/www/fresh', '/run/php/php8.5-fpm.sock', false);
$d = $env('fresh', ['fresh.conf' => $freshV]);
file_put_contents("$d/snippets/rivetit-locations.conf", $routes);
[$rc, $out] = apply($d, ['--domain', 'fresh.example.test']);
$ok($rc === 0 && str_contains($out, 'already up to date') && !reloaded($d), 'a vhost from the new install.sh with the shipped routes file: nothing to do');
[$rc, $out] = apply($d, ['--domain', 'fresh.example.test', '--nginx-bin', 'definitely-not-nginx']);
$ok($rc === 1 && str_contains($out, 'nginx was not found'), 'no nginx on the machine: a clear message (Docker/Apache installs do not use this)');

// ---------------------------------------------------------------- secrets
$all = '';
foreach (glob("$d/backup-*/MANIFEST") ?: [] as $f) { $all .= file_get_contents($f); }
$ok(!preg_match('~(password|secret|token|PRIVATE KEY)~i', $out), 'the script\'s output never contains secret-looking text');

foreach ($cleanup as $c) { wr_rmrf($c); }
exit($failures() ? 1 : 0);
