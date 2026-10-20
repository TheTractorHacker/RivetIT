<?php
/*
 * Lint: no user-visible time may be labelled "UTC" in the RMM / Endpoint agent / admin screens (see docs/TIMESTAMP_DISPLAY.md).
 * Fails on a hard-coded "(UTC)" column header, "Label (UTC)" form label, or a printed time with " UTC" appended. Not covered on purpose: comments, DB code
 * (UTC_TIMESTAMP, strtotime(.. ' UTC') arithmetic), cron expressions (the scheduler really reads them as UTC), and rivet_time.php itself.
 *   php tests/rivet_time_lint.php          (no database)
 */
$root = dirname(__DIR__);
$files = array_merge(
    glob("$root/admin/*.php") ?: [], glob("$root/admin/post/*.php") ?: [], glob("$root/agent/rmm_*.php") ?: [], glob("$root/agent/post/rmm_*.php") ?: [],
    glob("$root/agent/includes/asset/*.php") ?: [], glob("$root/includes/rmm_*.php") ?: [], glob("$root/js/rmm_*.js") ?: [], glob("$root/js/asset_metrics.js") ?: [],
    glob("$root/includes/endpoint_agent*.php") ?: []
);
$skip = ['database_updates.php'];
$bad = [];
foreach ($files as $f) {
    if (in_array(basename($f), $skip, true)) { continue; }
    foreach (file($f) as $n => $line) {
        $t = ltrim($line);
        if (str_starts_with($t, '//') || str_starts_with($t, '*') || str_starts_with($t, '/*') || str_starts_with($t, '#')) { continue; }
        if (preg_match('/cron/i', $line)) { continue; }                                   // cron expressions are UTC by definition
        if (preg_match('/UTC_TIMESTAMP|\bstrtotime\(|DateTimeZone\(.UTC.\)|Date\.UTC|timeZone: .UTC./', $line) && !preg_match('/<th|<label/', $line)) { continue; }
        $hit = preg_match('/<th[^>]*>[^<]*\(UTC\)/i', $line)                              // a column header
            || preg_match('/<label[^>]*>[^<]*\(UTC\)/i', $line)                            // a form label
            || preg_match('/[\'"]\s*[A-Za-z][A-Za-z -]*\(UTC\)[\'"]/', $line)               // 'Time range (UTC)' passed as a label
            || preg_match('/\?>\s*UTC\b/', $line)                                          // a printed time followed by the word UTC
            || preg_match('/\.\s*\' UTC[.,\' <)]/', $line)                                 // . ' UTC'
            || preg_match('/\bUTC: \'/', $line);                                           // 'UTC: ' prefix
        if ($hit) { $bad[] = str_replace($root . '/', '', $f) . ':' . ($n + 1) . '  ' . trim(substr($line, 0, 140)); }
    }
}
// The helper itself must exist and the Agent updates headers must be plain.
$sea = (string) file_get_contents("$root/admin/settings_endpoint_agent.php");
foreach (['Uploaded', 'Expires', 'Last check-in'] as $h) {
    if (!preg_match('/<th[^>]*>' . preg_quote($h, '/') . '<\/th>/', $sea)) { $bad[] = "admin/settings_endpoint_agent.php: expected a plain <th>$h</th>"; }
}
if ($bad) { echo "FAIL " . count($bad) . " hard-coded UTC label(s):\n  " . implode("\n  ", $bad) . "\n"; exit(1); }
echo "rivet_time_lint: " . count($files) . " files scanned, no hard-coded UTC labels\n";
