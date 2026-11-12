<?php

declare(strict_types=1);

// deploy/update.sh reload_php_fpm() must reload the unit that is actually running: php<major.minor.patch>-fpm on a PHP built
// from source (php8.5.12-fpm), php<major.minor>-fpm on a distro package, and say so when neither is active.
// Run: php tests/update_fpm_unit.php

$script = (string) file_get_contents(__DIR__ . '/../deploy/update.sh');
if (!preg_match('/^reload_php_fpm\(\) \{.*?^\}/ms', $script, $m)) {
    fwrite(STDERR, "FAIL: reload_php_fpm() not found\n");
    exit(1);
}

$fails = 0;
$check = static function (string $name, bool $ok, string $detail = '') use (&$fails): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . ($ok ? '' : "  $detail") . "\n";
    $fails += $ok ? 0 : 1;
};

/** @param list<string> $active units that systemctl reports active; @param string $phpv PHP_VERSION the stub php prints */
$run = static function (array $active, string $phpv) use ($m): string {
    $dir = sys_get_temp_dir() . '/fpmunit_' . bin2hex(random_bytes(4));
    mkdir($dir);
    // a stub "php" that answers the two -r calls the function makes
    file_put_contents("$dir/php", "#!/bin/bash\ncase \"\$2\" in\n  *PHP_MAJOR_VERSION*) echo -n \"" . implode('.', array_slice(explode('.', $phpv), 0, 2)) . "\";;\n  *PHP_VERSION*) echo -n \"$phpv\";;\nesac\n");
    chmod("$dir/php", 0755);
    file_put_contents("$dir/systemctl", "#!/bin/bash\necho \"systemctl \$*\"\n");
    chmod("$dir/systemctl", 0755);
    $harness = "set -u\nannounce(){ echo \"ANNOUNCE \$*\"; }\nwarn(){ echo \"WARN \$*\"; }\nsuccess(){ echo \"OK \$*\"; }\n"
        . 'service_is_active(){ case " ' . implode(' ', $active) . ' " in *" $1 "*) return 0;; esac; return 1; }' . "\n"
        . $m[0] . "\nreload_php_fpm\n";
    file_put_contents("$dir/h.sh", $harness);
    $out = (string) shell_exec('PATH=' . escapeshellarg($dir) . ':$PATH bash ' . escapeshellarg("$dir/h.sh") . ' 2>&1');
    array_map('unlink', glob("$dir/*") ?: []);
    rmdir($dir);
    return $out;
};

$o = $run(['php8.5.12-fpm'], '8.5.12');
$check('source build reloads php8.5.12-fpm', str_contains($o, 'systemctl reload php8.5.12-fpm'), $o);
$o = $run(['php8.5-fpm'], '8.5.12');
$check('distro package still reloads php8.5-fpm', str_contains($o, 'systemctl reload php8.5-fpm') && !str_contains($o, 'reload php8.5.12-fpm'), $o);
$o = $run(['php8.5.12-fpm', 'php8.5-fpm'], '8.5.12');
$check('both active: the full-version unit wins', str_contains($o, 'systemctl reload php8.5.12-fpm'), $o);
$o = $run([], '8.5.12');
$check('none active: warns, names what it tried, reloads nothing', str_contains($o, 'WARN') && str_contains($o, 'php8.5.12-fpm, php8.5-fpm') && !str_contains($o, 'systemctl reload'), $o);

echo $fails === 0 ? "all passed\n" : "$fails failed\n";
exit($fails === 0 ? 0 : 1);
