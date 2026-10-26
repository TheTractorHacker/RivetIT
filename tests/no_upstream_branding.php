<?php
/*
 * Regression guard: nothing a user sees points at the upstream ITFlow project's services or presents them as ours.
 * Fails on a hard-coded itflow.org URL (docs., forum., services., telemetry., ...) or the labels "Upstream Docs / Forum /
 * Services" in any first-party PHP, JS, HTML, JSON, XML, YAML or shell file, outside comments.
 * The GPL attribution is not affected: it uses APP_UPSTREAM_NAME / APP_UPSTREAM_URL (github.com/itflow-org/itflow) on the
 * About / debug page, the README and NOTICE, none of which match. Justified exceptions go in tests/no_upstream_branding_allowlist.txt.
 * Skipped: vendor, plugins (third party), tests, docs and *.md, backups, uploads, .git, node_modules, and db.sql / *.sql.
 * Run: php tests/no_upstream_branding.php
 */
$root = dirname(__DIR__);
$skipTop = ['.claude', '.vscode', 'vendor', 'plugins', 'node_modules', 'tests', 'docs', 'backups', 'uploads', '.git'];
$allow = [];
foreach (file(__DIR__ . '/no_upstream_branding_allowlist.txt') ?: [] as $l) {
    $l = trim($l);
    if ($l === '' || $l[0] === '#') continue;
    $allow[] = explode('|', $l, 2);
}
$re = '~itflow\.org|Upstream\s+(?:Docs|Forum|Services)~i';
$bad = [];
$files = 0;

$report = function (string $rel, string $text, int $line) use (&$bad, $allow, $re) {
    if (!preg_match($re, $text)) return;
    foreach ($allow as [$ap, $needle]) {
        if ($ap === $rel && str_contains($text, $needle)) return;
    }
    $bad[] = "$rel:$line  " . trim(substr(preg_replace('/\s+/', ' ', $text), 0, 140));
};

$walk = function (string $dir) use (&$walk, $root, $skipTop, $report, $re, &$files) {
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
        if (!preg_match('/\.(php|js|mjs|html|htm|json|xml|ya?ml|sh|svg|webmanifest)$/i', $e) || preg_match('/\.min\.(js|css)$/', $e)) continue;
        if (preg_match('/(^|\/)(package-lock|composer\.lock)/', $rel)) continue;
        $src = (string) file_get_contents($path);
        if ($src === '' || !preg_match($re, $src)) { $files++; continue; }
        $files++;
        if (str_ends_with($e, '.php')) {
            // PHP: only string literals and inline HTML count; comments (// # /* */ and docblocks) are ignored.
            foreach (token_get_all($src) as $t) {
                if (!is_array($t)) continue;
                if (in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true)) {
                    $report($rel, $t[1], $t[2]);
                }
            }
        } else {
            foreach (preg_split('/\R/', $src) as $n => $line) {
                if (preg_match('~^\s*(//|\*|/\*|#)~', $line)) continue;      // comment lines
                $report($rel, $line, $n + 1);
            }
        }
    }
};
$walk($root);
foreach ($bad as $b) echo "FAIL  upstream link or label (use the APP_* constants, or remove it): $b\n";
echo $bad ? count($bad) . " FAILED ($files files scanned)\n" : "PASS  no upstream itflow.org links or Upstream Docs/Forum/Services labels ($files files scanned)\n";
exit($bad ? 1 : 0);
