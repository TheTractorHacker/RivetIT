<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/*
 * Static check: ticket modals that load a ticket by id must call enforceClientAccess() (pentest F-03). No DB, no network.
 *   php tests/ticket_modal_scoping.php
 */
$fails = 0; $ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };
foreach (['ticket_edit_schedule', 'ticket_edit_vendor'] as $m) {
    $s = file_get_contents(__DIR__ . "/../agent/modals/ticket/$m.php");
    $q = strpos($s, 'mysqli_query'); $e = strpos($s, 'enforceClientAccess(');
    $ok($e !== false && $q !== false && $e > $q && strpos($s, '<div class="modal-header') > $e, "$m enforces client access after loading the ticket and before output");
}
$t = file_get_contents(__DIR__ . '/../agent/post/ticket.php');
$ok(strpos($t, '$conflict_scope') !== false, 'schedule conflict listing is department scoped');
exit($fails ? 1 : 0);
