<?php
/*
 * IT-27 regression: agent/tickets.php must load the ticket filter helpers on EVERY request, including the department tab
 * (tickets.php?client_id=N). Before the fix they were required only inside `if (!isset($_GET['client_id']))`, so the department
 * Tickets tab fataled with "Call to undefined function ticketFilterSets()". No database needed.
 *
 *   php tests/ticket_tab_helpers_loaded.php
 */
$root = dirname(__DIR__);
$fail = 0;
$ok = function (bool $cond, string $msg) use (&$fail) { echo ($cond ? "PASS  " : "FAIL  ") . $msg . "\n"; if (!$cond) { $fail++; } };

$src = file_get_contents("$root/agent/tickets.php");
$ok($src !== false && $src !== '', 'agent/tickets.php is readable');

// Strip the first top-level `if (!isset($_GET['client_id'])) { ... }` block (brace matched); the helper require must survive outside it.
$start = strpos($src, "if (!isset(\$_GET['client_id'])) {");
$ok($start !== false, 'the no-client_id bootstrap block exists');
$depth = 0; $end = null;
for ($i = strpos($src, '{', (int) $start); $i < strlen($src); $i++) {
    if ($src[$i] === '{') { $depth++; }
    elseif ($src[$i] === '}') { $depth--; if ($depth === 0) { $end = $i; break; } }
}
$ok($end !== null, 'the bootstrap block is brace-balanced');
$outside = substr($src, 0, (int) $start) . substr($src, (int) $end + 1);
$ok((bool) preg_match('~require_once\s+__DIR__\s*\.\s*\'/includes/ticket_view_filters\.php\'~', $outside), 'ticket_view_filters.php is required outside the no-client_id block');

// Every helper the page calls is defined once that file is loaded (no config, no session, no database).
require_once "$root/agent/includes/ticket_view_filters.php";
foreach (['ticketFilterSets', 'ticketViewDefaultsAvailable', 'ticketViewDefaultIds', 'ticketFilterCheckGroup'] as $fn) {
    $ok(function_exists($fn), "$fn() is defined by loading ticket_view_filters.php");
    $ok(strpos($src, $fn . '(') !== false, "tickets.php still calls $fn()");
}

echo $fail === 0 ? "ALL PASSED\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
