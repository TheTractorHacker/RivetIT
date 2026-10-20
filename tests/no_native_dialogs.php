<?php
/*
 * Regression guard: the app uses no native browser dialogs. window.alert(), window.confirm() and window.prompt() (also the bare alert( / confirm( / prompt( calls)
 * are replaced by RivetDialog (js/rivet_dialog.js: Promise based, built on the app's modal markup). This scans every first-party .js file and every PHP file (their
 * inline <script> blocks and any emitted script text) and fails on a native call that is not on tests/no_native_dialogs_allowlist.txt. It also checks that every layout
 * that loads the app's JavaScript loads js/rivet_dialog.js. onbeforeunload / beforeunload prompts are not matched: the browser offers no way to restyle that one.
 * Skipped: vendor, plugins (third party), tests, docs, and the CLI-only scripts / cron / deploy trees (a PHP prompt() helper in scripts/setup_cli.php is not a browser call).
 * Run: php tests/no_native_dialogs.php
 */
$root = dirname(__DIR__);
$skipDirs = ['vendor', 'plugins', 'node_modules', 'tests', 'android', 'docs', 'backups', 'uploads', '.git', 'docker', 'scripts', 'cron', 'deploy'];
$allow = [];
foreach (file(__DIR__ . '/no_native_dialogs_allowlist.txt') ?: [] as $l) {
    $l = trim($l);
    if ($l === '' || $l[0] === '#') continue;
    $allow[] = explode('|', $l, 2);
}
// a call: alert( confirm( prompt( , optionally window.-prefixed, not part of a longer name or a method / static call / function declaration
$re = '/(?<![\w$.>:\'"-])(?:window\s*\.\s*)?(alert|confirm|prompt)\s*\(/';
$bad = [];
$files = 0;
$walk = function (string $dir) use (&$walk, $root, $skipDirs, $re, $allow, &$bad, &$files) {
    foreach (scandir($dir) as $e) {
        if ($e === '.' || $e === '..') continue;
        $path = "$dir/$e";
        $rel = ltrim(substr($path, strlen($root)), '/');
        if (is_dir($path)) {
            if (in_array($e, $skipDirs, true) && $dir === $root) continue;
            if (is_link($path)) continue;
            $walk($path);
            continue;
        }
        if (!preg_match('/\.(js|mjs|php|html|htm)$/', $e) || preg_match('/\.min\.js$/', $e)) continue;
        $files++;
        foreach (file($path) as $n => $line) {
            if (!preg_match($re, $line, $m, PREG_OFFSET_CAPTURE)) continue;
            $t = ltrim($line);
            if (preg_match('~^(//|\*|/\*|#)~', $t)) continue;                       // comment lines
            if (preg_match('/\bfunction\s+(alert|confirm|prompt)\s*\(/', $line)) continue;   // a declaration, not a call
            if (str_contains($line, 'alert(s)')) continue;                          // prose: "3 open alert(s)"
            $text = preg_replace('~//.*$~', '', substr($line, 0, $m[0][1])) . '';
            if (substr_count($text, "'") % 2 === 1 && preg_match('~[A-Za-z] $~', $text)) continue; // inside a prose string such as 'open alert(s)'
            foreach ($allow as [$ap, $needle]) {
                if ($ap === $rel && str_contains($line, $needle)) continue 2;
            }
            $bad[] = $rel . ':' . ($n + 1) . '  ' . trim(substr($line, 0, 140));
        }
    }
};
$walk($root);
foreach ($bad as $b) echo "FAIL  native dialog call (use RivetDialog): $b\n";

// layouts: each must load the helper
$layouts = ['includes/footer.php', 'client/includes/footer.php', 'guest/includes/guest_header.php', 'kiosk/includes/layout_bottom.php', 'login.php', 'setup/index.php', 'client/login_reset.php'];
$missing = [];
foreach ($layouts as $l) {
    if (!is_file("$root/$l") || strpos((string) file_get_contents("$root/$l"), 'rivet_dialog.js') === false) $missing[] = $l;
}
foreach ($missing as $m) echo "FAIL  layout does not load js/rivet_dialog.js: $m\n";

$fail = count($bad) + count($missing);
echo $fail ? "$fail FAILED ($files files scanned)\n" : "PASS  no native alert/confirm/prompt in $files first-party files; every layout loads RivetDialog\nALL PASSED\n";
exit($fail ? 1 : 0);
