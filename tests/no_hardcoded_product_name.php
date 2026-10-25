<?php
/*
 * Regression guard: a visible product name comes from APP_NAME (includes/branding.php), never a literal, so a rename or a
 * RIVETIT_APP_NAME override reaches every page, e-mail and message (see REBRANDING.md). Fails on the word "RivetIT" inside a
 * PHP string literal or inline HTML in first-party code, with these exceptions that are not display text:
 *   - the fallback of an existing guard: defined('APP_NAME') ? APP_NAME : 'RivetIT'
 *   - protocol tokens: User-Agent values, RivetIT-<x> / RivetIT/<ver>, X-RivetIT-* headers, RivetITMcp class names, ITFlow\ namespaces
 *   - entries in tests/no_hardcoded_product_name_allowlist.txt (path|exact text, each with a reason)
 * Skipped: vendor, plugins, tests, docs, deploy and docker (they cannot load PHP and say RivetIT literally), backups, uploads,
 * includes/branding.php (where the name is defined), admin/database_updates.php (migrations write historical text), custom/readme.php stubs.
 * Run: php tests/no_hardcoded_product_name.php
 */
$root = dirname(__DIR__);
$skipTop = ['.claude', '.vscode', 'vendor', 'plugins', 'node_modules', 'tests', 'docs', 'deploy', 'docker', 'backups', 'uploads', '.git'];
$skipFiles = ['includes/branding.php', 'admin/database_updates.php'];
$allow = [];
foreach (file(__DIR__ . '/no_hardcoded_product_name_allowlist.txt') ?: [] as $l) {
    $l = trim($l);
    if ($l === '' || $l[0] === '#') continue;
    $allow[] = explode('|', $l, 2);
}
$protocol = '~User-Agent|RivetIT[-/]\w|X-RivetIT|RivetITMcp|RivetIT\\\\~';
$bad = [];
$files = 0;

$walk = function (string $dir) use (&$walk, $root, $skipTop, $skipFiles, $allow, $protocol, &$bad, &$files) {
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
        if (!str_ends_with($e, '.php') || in_array($rel, $skipFiles, true) || str_ends_with($rel, 'custom/readme.php')) continue;
        $src = (string) file_get_contents($path);
        $files++;
        if (!str_contains($src, 'RivetIT')) continue;
        $prev = '';
        foreach (token_get_all($src) as $t) {
            if (!is_array($t)) { $prev .= $t; $prev = substr($prev, -60); continue; }
            [$id, $text] = $t;
            if (in_array($id, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true)
                && str_contains($text, 'RivetIT') && !preg_match($protocol, $text)
                && !($id === T_CONSTANT_ENCAPSED_STRING && preg_match('~(?:APP_NAME|defined\(\s*)\s*:?\s*$~', $prev))) {
                foreach ($allow as [$ap, $needle]) {
                    if ($ap === $rel && str_contains($text, $needle)) { $prev .= $text; $prev = substr($prev, -60); continue 2; }
                }
                $bad[] = "$rel:{$t[2]}  " . trim(substr(preg_replace('/\s+/', ' ', $text), 0, 120));
            }
            $prev .= $text;
            $prev = substr($prev, -60);
        }
    }
};
$walk($root);
foreach ($bad as $b) echo "FAIL  hard-coded product name (use APP_NAME): $b\n";
echo $bad ? count($bad) . " FAILED ($files files scanned)\n" : "PASS  no hard-coded product name in visible strings ($files files scanned)\n";
exit($bad ? 1 : 0);
