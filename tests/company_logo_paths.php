<?php
/*
 * Regression guard: an <img> that shows the company logo must point under /uploads/settings/. The logo column holds only a file
 * name, so printing it straight into src (guest/worksheet_sign.php once did) resolves against the current page's folder and
 * shows a broken image. Fails on any first-party PHP file where an <img src> starts with a company-logo variable
 * ($company_logo, $co_logo, $trr_logo, $session_company_logo, $k_logo_file, $tv_logo) instead of a path.
 * Run: php tests/company_logo_paths.php
 */
$root = dirname(__DIR__);
$skipTop = ['.claude', '.vscode', 'vendor', 'plugins', 'node_modules', 'tests', 'docs', 'backups', 'uploads', '.git'];
$re = '~<img\b[^>]*\bsrc\s*=\s*["\']\s*(?:<\?(?:=|php\s+echo)\s*)\$(?:company_logo|co_logo|trr_logo|session_company_logo|k_logo_file|tv_logo)\b~i';
$bad = [];
$files = 0;
$walk = function (string $dir) use (&$walk, $root, $skipTop, $re, &$bad, &$files) {
    foreach (scandir($dir) as $e) {
        if ($e === '.' || $e === '..') continue;
        $path = "$dir/$e";
        if (is_link($path)) continue;
        if (is_dir($path)) {
            if ($dir === $root && in_array($e, $skipTop, true)) continue;
            $walk($path);
            continue;
        }
        if (!str_ends_with($e, '.php')) continue;
        $src = (string) file_get_contents($path);
        $files++;
        if (preg_match_all($re, $src, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [, $off]) {
                $bad[] = ltrim(substr($path, strlen($root)), '/') . ':' . (substr_count(substr($src, 0, $off), "\n") + 1);
            }
        }
    }
};
$walk($root);
foreach ($bad as $b) echo "FAIL  company logo src is a bare file name (prefix /uploads/settings/): $b\n";
echo $bad ? count($bad) . " FAILED ($files files scanned)\n" : "PASS  every company-logo <img> points under /uploads/settings/ ($files files scanned)\n";
exit($bad ? 1 : 0);
