<?php
// Every ITFlow\ compatibility shim must carry a "@deprecated since ... use \RivetCore\..." tag.
// Run: php tests/shim_deprecations.php
$root = dirname(__DIR__);
$fail = 0; $n = 0;
$expected = [
    'Audit/AuditService', 'KB/DocxConverter', 'KB/DocxConversionException', 'KB/PdfConverter', 'KB/PdfConversionException',
    'Cron/JobRunner', 'ITSM/ChangeService', 'ITSM/ProblemService', 'Jobs/JobQueue', 'Webhooks/WebhookDispatcher',
    'Redis/Lock', 'Redis/RateLimit',
];
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->getExtension() !== 'php') continue;
    $s = file_get_contents($f->getPathname());
    $rel = substr($f->getPathname(), strlen($root . '/src/'), -4);
    // A shim announces itself ("Compatibility shim|alias") or is a bare class_alias to RivetCore.
    if (preg_match('/Compatibility (shim|alias)/', $s) || preg_match('/class_alias\(\s*\\\\RivetCore\\\\/', $s)) $files[$rel] = $s;
}
foreach ($expected as $e) {
    if (!isset($files[$e])) { echo "FAIL expected shim not found: $e\n"; $fail++; }
}
foreach ($files as $rel => $s) {
    $n++;
    if (!preg_match('/@deprecated since \d+\.\d+\.\d+ use \\\\RivetCore\\\\[A-Za-z\\\\]+/', $s)) { echo "FAIL $rel: missing '@deprecated since X use \\RivetCore\\...'\n"; $fail++; }
    else echo "ok   $rel\n";
}
$doc = @file_get_contents($root . '/docs/DEPRECATIONS.md') ?: '';
foreach ($files as $rel => $_) {
    $cls = 'ITFlow\\' . str_replace('/', '\\', $rel);
    if (!str_contains($doc, '`' . $cls . '`')) { echo "FAIL docs/DEPRECATIONS.md does not list $cls\n"; $fail++; }
}
echo $fail ? "FAILED ($fail)\n" : "PASS ($n shims)\n";
exit($fail ? 1 : 0);
