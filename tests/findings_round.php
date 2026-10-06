<?php
/*
 * Findings round-up (issue #29) regression tests, against the REAL code:
 *   - sort-direction rule for column headings (nextSortOrder / sortLinkOrder in functions.php)
 *   - ticket creation status chosen by NAME with an explicit fallback (resolveTicketCreationStatus)
 *   - Comet auto-ticket flag: off = alert only, on = exactly one ticket per device, recovery closes it (includes/comet.php)
 *   - Devices & PINs guard accepts a kiosk-only role (Access::kioskDecision)
 *   - page-size normalisation (normalizeRecordsPerPage)
 *   - source guards: system notes log no time, every archived-view bulk Delete asks for confirmation, calendar Repeat
 *     is gone, portal documents are attributed to a real users row
 * Needs a THROWAWAY database holding this app's schema (import db.sql); it never reads config.php:
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=...scratch... RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/findings_round.php
 */
if (getenv('RIVETIT_TEST_DB') !== '1') exit(2);
if (!preg_match('/scratch|test/i', (string) getenv('RIVETIT_TEST_DB_NAME'))) { fwrite(STDERR, "Refusing: DB name must contain scratch/test\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETIT_TEST_DB_USER'), getenv('RIVETIT_TEST_DB_PASS'), getenv('RIVETIT_TEST_DB_NAME'));
$GLOBALS['mysqli'] = $mysqli;
$root = dirname(__DIR__);
$q = fn(string $sql) => mysqli_query($mysqli, $sql);
$one = fn(string $sql) => mysqli_fetch_row($q($sql))[0] ?? null;
$fails = 0; $ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };

// Pull the real helpers out of functions.php.
$src = file_get_contents($root . '/functions.php');
foreach (['nextSortOrder', 'sortLinkOrder', 'recordsPerPageOptions', 'normalizeRecordsPerPage', 'ticketCreationStatusCandidates', 'resolveTicketCreationStatus'] as $fn) {
    if (!preg_match('/^function ' . $fn . '\(.*?^}\n/ms', $src, $m)) { echo "FAIL  could not find $fn in functions.php\n"; exit(1); }
    eval($m[0]);
}

// ---- A2: sort direction -------------------------------------------------------------------------------------------
$ok(nextSortOrder('name', 'created', 'DESC') === 'ASC', 'clicking a different column while sorted DESC requests ASC');
$ok(nextSortOrder('name', 'created', 'ASC') === 'ASC', 'clicking a different column while sorted ASC requests ASC (was DESC before the fix)');
$ok(nextSortOrder('name', 'name', 'ASC') === 'DESC', 'clicking the active ASC column flips to DESC');
$ok(nextSortOrder('name', 'name', 'DESC') === 'ASC', 'clicking the active DESC column flips to ASC');
$sort = 'name'; $order = 'ASC';
$ok(sortLinkOrder('name') === 'DESC' && sortLinkOrder('email') === 'ASC', 'sortLinkOrder() reads the page $sort/$order');
$leftover = 0;
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/agent', FilesystemIterator::SKIP_DOTS)) as $f) {
    if (substr($f, -4) === '.php' && preg_match('/order=<\?php echo \$disp/', file_get_contents((string) $f))) $leftover++;
}
$ok($leftover === 0, 'no agent page still builds heading links from the shared $disp');

// ---- A1: ticket creation status -----------------------------------------------------------------------------------
$config_ticket_default_status_id = 0;
$q("DELETE FROM ticket_statuses");
foreach (['New', 'Open', 'On Hold', 'Resolved', 'Closed'] as $i => $n) { $q("INSERT INTO ticket_statuses SET ticket_status_name = '$n', ticket_status_color = '#000000', ticket_status_order = " . ($i + 1)); }
$id = fn($n) => (int) $one("SELECT ticket_status_id FROM ticket_statuses WHERE ticket_status_name = '$n'");
$ok(resolveTicketCreationStatus(0) === $id('New'), 'unassigned ticket -> New');
$ok(resolveTicketCreationStatus(7) === $id('Open'), 'assigned ticket on a stock install (no "Assigned") -> Open, by name');
$q("INSERT INTO ticket_statuses SET ticket_status_name = 'Assigned', ticket_status_color = '#000000', ticket_status_order = 9");
$ok(resolveTicketCreationStatus(7) === $id('Assigned'), 'assigned ticket prefers "Assigned" when the install has it');
$q("UPDATE ticket_statuses SET ticket_status_active = 0 WHERE ticket_status_name IN ('Assigned','Open','New')");
$first = (int) $one("SELECT ticket_status_id FROM ticket_statuses WHERE ticket_status_active = 1 ORDER BY ticket_status_order, ticket_status_id LIMIT 1");
$ok(resolveTicketCreationStatus(7) === $first && resolveTicketCreationStatus(0) === $first, 'no Assigned/Open/New active -> first active status by order');
$q("UPDATE ticket_statuses SET ticket_status_active = 1");
$config_ticket_default_status_id = $id('On Hold');
$ok(resolveTicketCreationStatus(7) === $id('On Hold'), 'an admin-chosen default status still wins');
$config_ticket_default_status_id = 0;

// ---- B4: Comet auto-ticket ----------------------------------------------------------------------------------------
$q("UPDATE settings SET config_ticket_prefix = 'TCK', config_ticket_next_number = 100 WHERE company_id = 1");
$q("DELETE FROM tickets"); $q("DELETE FROM comet_backup_alerts"); $q("DELETE FROM comet_client_map");
function sanitizeInput($v) { global $mysqli; return mysqli_real_escape_string($mysqli, trim((string) $v)); }
function resolveTicketAssignee($x) { return 0; }
function resolveTicketStatusId($s) { global $mysqli; return (int) mysqli_fetch_row(mysqli_query($mysqli, "SELECT ticket_status_id FROM ticket_statuses WHERE ticket_status_name = 'Closed'"))[0]; }
$GLOBALS['notifications'] = [];
function appNotify($type, $details, $action = null, $client_id = 0, $entity_id = 0, $push = true) { $GLOBALS['notifications'][] = $details; }
$config_comet_server_url = ''; // comet_api() has nothing to call: device name falls back to the DeviceID, as in production when unreachable
require_once $root . '/includes/comet.php';
$job = fn(int $status, string $dev = 'DEV1') => ['Status' => $status, 'Username' => 'acme', 'DeviceID' => $dev, 'Classification' => 4001, 'ErrorString' => 'disk full'];
$tickets = fn() => (int) $one("SELECT COUNT(*) FROM tickets WHERE ticket_source = 'Comet Backup'");
$open_alerts = fn() => (int) $one("SELECT COUNT(*) FROM comet_backup_alerts WHERE alert_resolved_at IS NULL");

$config_comet_auto_ticket = 0;
$r = comet_process_job($job(7002));
$ok($r['action'] === 'alert_created' && $tickets() === 0 && $open_alerts() === 1, 'flag OFF: a failed backup records the alert but opens no ticket');
$r = comet_process_job($job(7002));
$ok($r['action'] === 'existing_alert' && $open_alerts() === 1, 'flag OFF: a repeat failure does not duplicate the alert');
$r = comet_process_job($job(5000));
$ok($r['action'] === 'alert_resolved' && $open_alerts() === 0 && $tickets() === 0, 'flag OFF: recovery resolves the alert, still no ticket');

$config_comet_auto_ticket = 1;
$r = comet_process_job($job(7002));
$tid = (int) ($r['ticket_id'] ?? 0);
$ok($r['action'] === 'ticket_created' && $tid > 0 && $tickets() === 1, 'flag ON: a failed backup creates ONE ticket');
$ok((int) $one("SELECT ticket_status FROM tickets WHERE ticket_id = $tid") === (int) $one("SELECT ticket_status_id FROM ticket_statuses WHERE ticket_status_name = 'New'"), 'flag ON: the ticket starts in the New status, resolved by name');
$r = comet_process_job($job(7002)); $r2 = comet_process_job($job(7001));
$ok($tickets() === 1 && $open_alerts() === 1 && $r['action'] === 'existing_alert', 'flag ON: repeated failures never open a second ticket');
comet_process_job($job(7002, 'DEV2'));
$ok($tickets() === 2, 'flag ON: a different device gets its own ticket');
$r = comet_process_job($job(5000));
$closed = (int) $one("SELECT COUNT(*) FROM tickets WHERE ticket_id = $tid AND ticket_closed_at IS NOT NULL");
$note = (int) $one("SELECT COUNT(*) FROM ticket_replies WHERE ticket_reply_ticket_id = $tid AND ticket_reply LIKE 'Backup succeeded%'");
$ok($r['action'] === 'ticket_resolved' && $closed === 1 && $note === 1, 'flag ON: recovery adds the note and closes that ticket');
$r = comet_process_job($job(5000));
$ok($r['action'] === 'no_open_alert', 'a second success after recovery does nothing');
comet_process_job($job(7002));
$ok($tickets() === 3, 'flag ON: a new failure after recovery opens a fresh ticket');

// ---- B7: kiosk-only role ------------------------------------------------------------------------------------------
require_once $root . '/src/Training/Core/Access.php';
use ITFlow\Training\Core\Access;
$ok(Access::kioskDecision(true, 1, 1) === null, 'kiosk-only role (kiosk 1, no Training read) reaches Devices & PINs');
$ok(Access::kioskDecision(true, 0, 1) === 'forbidden', 'no kiosk permission is still refused');
$ok(Access::kioskDecision(true, 2, 3) === 'forbidden', 'kiosk level below the page minimum is still refused');
$ok(Access::kioskDecision(true, 3, 3) === null, 'kiosk Full reaches the Full-only pages');
$ok(Access::kioskDecision(false, 3, 1) === 'off', 'Training switched off still wins');
$router = file_get_contents($root . '/src/Training/Api/Router.php');
$ok(strpos($router, 'Access::apiKioskRoute()') !== false && strpos($router, 'Access::api(1)') !== false, 'Router: kiosk_admin routes use the kiosk gate, every other route keeps Training >= 1');

// ---- C3: page sizes -----------------------------------------------------------------------------------------------
$ok(recordsPerPageOptions() === [5, 10, 20, 50, 100, 500], 'page sizes are 5/10/20/50/100/500 everywhere');
foreach ([5, 10, 20, 50, 100, 500] as $n) { if (normalizeRecordsPerPage((string) $n, 10) !== $n) { $ok(false, "size $n accepted"); } }
$ok(normalizeRecordsPerPage('500', 10) === 500 && normalizeRecordsPerPage('5', 10) === 5, 'sizes 5 and 500 (the footer extremes) are accepted');
$ok(normalizeRecordsPerPage('25', 50) === 50 && normalizeRecordsPerPage('abc', 20) === 20 && normalizeRecordsPerPage(null, 100) === 100 && normalizeRecordsPerPage('0', 5) === 5, 'an unknown value keeps the current size instead of resetting to 10');
$footer = file_get_contents($root . '/includes/filter_footer.php');
preg_match_all('/user_config_records_per_page == (\d+)\)/', $footer, $mm);
$ok($mm[1] === array_map('strval', recordsPerPageOptions()), 'the list footer offers exactly the same sizes');

// ---- Source guards ------------------------------------------------------------------------------------------------
$reply_bad = 0;
foreach (['agent/post/ticket.php', 'api/v1/tickets.php', 'cron/outlook_schedule_sync.php'] as $f) { $reply_bad += substr_count(file_get_contents("$root/$f"), "ticket_reply_time_worked = '00:01:00'"); }
$ok($reply_bad === 0, 'automatic ticket notes log 00:00:00, not one minute');

$bad = [];
foreach (glob($root . '/agent/*.php') as $f) {
    $lines = explode("\n", file_get_contents($f));
    foreach ($lines as $i => $line) {
        if (preg_match('/name="bulk_delete_(\w+)"/', $line)) {
            $ctx = implode("\n", array_slice($lines, max(0, $i - 3), 4));
            if (strpos($ctx, 'confirm-link') === false) $bad[] = basename($f) . ':' . ($i + 1);
        }
    }
}
$ok($bad === [], 'every bulk Delete button asks for confirmation (confirm-link)' . ($bad ? ': ' . implode(', ', $bad) : ''));

$ev = file_get_contents($root . '/agent/post/event.php') . file_get_contents($root . '/agent/post/event_model.php');
$ok(strpos($ev, 'event_repeat =') === false && strpos($ev, '$repeat') === false && strpos(file_get_contents($root . '/agent/modals/calendar/calendar_event_add.php'), 'name="repeat"') === false, 'calendar events no longer write or show Repeat');

$portal = file_get_contents($root . '/client/post.php');
$ok(substr_count($portal, 'document_created_by = $session_user_id') === 2 && strpos($portal, 'document_created_by = $session_contact_id') === false, 'portal documents are attributed to the portal users row, never a contact id');

foreach (['agent/ticket.php' => 'ticket_edit_schedule.php', 'includes/modal_permissions.php' => 'ticket_edit_schedule.php'] as $f => $needle) {
    $ok(strpos(file_get_contents("$root/$f"), $needle) !== false, "$f references $needle");
}

echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
exit($fails ? 1 : 0);
