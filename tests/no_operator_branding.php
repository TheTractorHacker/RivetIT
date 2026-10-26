<?php
/*
 * Regression guard: no first-party PHP string or inline HTML names the operator's own domain (foleyit.com). The signing pages used to
 * link "foleyit.com/ticket-terms" for every install; that is now a setting (includes/company_terms.php). Fails on the domain inside a
 * PHP string literal or inline HTML, outside comments, unless the line is on tests/no_operator_branding_allowlist.txt
 * (path|exact text, each with a reason). Skipped: vendor, plugins, tests, docs, backups, uploads, deploy and docker.
 * Run: php tests/no_operator_branding.php
 */
$root = dirname(__DIR__);
$skipTop = ['.claude', '.vscode', 'vendor', 'plugins', 'node_modules', 'tests', 'docs', 'deploy', 'docker', 'backups', 'uploads', '.git'];
$allow = [];
foreach (file(__DIR__ . '/no_operator_branding_allowlist.txt') ?: [] as $l) {
    $l = trim($l);
    if ($l === '' || $l[0] === '#') continue;
    $allow[] = explode('|', $l, 2);
}
$bad = [];
$files = 0;
$walk = function (string $dir) use (&$walk, $root, $skipTop, $allow, &$bad, &$files) {
    foreach (scandir($dir) as $e) {
        if ($e === '.' || $e === '..') continue;
        $path = "$dir/$e";
        $rel = ltrim(substr($path, strlen($root)), '/');
        if (is_link($path)) continue;
        if (is_dir($path)) {
            if ($dir === $root && in_array($e, $skipTop, true)) continue;
            $walk($path);
            continue;
        }
        if (!str_ends_with($e, '.php')) continue;
        $src = (string) file_get_contents($path);
        $files++;
        if (stripos($src, 'foleyit.com') === false) continue;
        foreach (token_get_all($src) as $t) {
            if (!is_array($t) || !in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true)) continue;
            if (stripos($t[1], 'foleyit.com') === false) continue;
            foreach ($allow as [$ap, $needle]) {
                if ($ap === $rel && str_contains($t[1], $needle)) continue 2;
            }
            $bad[] = "$rel:{$t[2]}  " . trim(substr(preg_replace('/\s+/', ' ', $t[1]), 0, 100));
        }
    }
};
$walk($root);
foreach ($bad as $b) echo "FAIL  operator domain in first-party code (make it a setting): $b\n";
echo $bad ? count($bad) . " FAILED ($files files scanned)\n" : "PASS  no operator domain in PHP strings or inline HTML ($files files scanned)\n";
exit($bad ? 1 : 0);
