<?php
/*
 * Regression guard: the page CSP (script-src 'self' 'nonce-...', includes/header.php) blocks inline event-handler attributes
 * (onclick=, onsubmit=, onfocusout=, ...), so a template that carries one silently does nothing. Scans the PHP templates for them.
 * Use a data-* hook plus the delegated listeners in js/app.js instead. Run: php tests/csp_inline_handlers.php
 */
$root = dirname(__DIR__);
$events = 'click|dblclick|change|input|submit|reset|focus|blur|focusin|focusout|keydown|keyup|keypress|mousedown|mouseup|mouseover|mouseout|mouseenter|mouseleave|load|error|toggle|select|scroll|drop|dragover|dragstart';
$re = '/[\s\'"]on(' . $events . ')\s*=\s*\\\\?[\'"]/i';
$bad = [];
foreach (['agent', 'admin', 'client', 'includes', 'modals', 'guest', 'kiosk', 'setup', 'post', 'api'] as $dir) {
    if (!is_dir("$root/$dir")) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$dir", FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->getExtension() !== 'php') continue;
        foreach (file($f->getPathname()) as $n => $line) {
            if (preg_match('~^\s*(//|\*|/\*|#)~', $line)) continue;   // comments mentioning the old pattern
            if (preg_match($re, $line)) $bad[] = substr($f->getPathname(), strlen($root) + 1) . ':' . ($n + 1);
        }
    }
}
foreach ($bad as $b) echo "FAIL  inline event handler (blocked by the CSP): $b\n";
echo $bad ? count($bad) . " FAILED\n" : "PASS  no inline event-handler attributes in the PHP templates\nALL PASSED\n";
exit($bad ? 1 : 0);
