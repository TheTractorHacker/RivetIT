<?php
/*
 * Fails when PHP/JS source uses a Font Awesome class that the shipped Font Awesome (plugins/fontawesome-free, version 5.x)
 * does not contain - for example the Font Awesome 6 names fa-list-check or fa-circle-info, which render as an empty box.
 *   php tests/fa_icons.php
 */
$root = dirname(__DIR__);
$css = file_get_contents($root . '/plugins/fontawesome-free/css/all.min.css');
preg_match_all('/\.(fa-[a-z0-9-]+):/', $css, $m);
$have = array_flip($m[1]);
$skip = '/^fa-(fw|lg|sm|xs|\d+x|spin|pulse|ul|li|border|pull-.*|stack.*|inverse|rotate-.*|flip-.*|xl|2xs|fa|xxx|solid|regular|brands|light|duotone|layers.*|mm|.*-)$/';
$bad = [];
foreach (['admin', 'agent', 'client', 'includes', 'modals', 'guest', 'kiosk', 'js', 'src', 'api', 'setup'] as $d) {
    if (!is_dir("$root/$d")) {
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$d", FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $p = $f->getPathname();
        if (!preg_match('/\.(php|js)$/', $p) || str_contains($p, '/vendor/') || str_contains($p, '/plugins/')) {
            continue;
        }
        preg_match_all('/(?<![\w-])(fa-[a-z0-9]+(?:-[a-z0-9]+)*)(?![\w-])/', (string) file_get_contents($p), $mm);
        foreach (array_unique($mm[1]) as $c) {
            if (!isset($have[$c]) && !preg_match($skip, $c)) {
                $bad[$c][] = substr($p, strlen($root) + 1);
            }
        }
    }
}
ksort($bad);
foreach ($bad as $c => $files) {
    echo "UNKNOWN ICON $c in " . implode(', ', array_slice($files, 0, 3)) . "\n";
}
echo $bad ? count($bad) . " unknown icon class(es)\n" : "OK: every fa- class exists in the shipped Font Awesome\n";
exit($bad ? 1 : 0);
