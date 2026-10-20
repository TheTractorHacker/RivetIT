<?php
/*
 * Fragment of tests/rmm_ui.php (RMM Phase 8 UI, RivetCore 1.0.0-rc.11): the Logs page, the Logs tab of the asset page and the Log rules page. Shares that file's
 * variables. Everything is driven through the product: devices enroll and check in through the real endpoints, log lines arrive through the real /api/v1/agent_logs
 * endpoint with the device credential, rules go through the real handler (agent/post/rmm_logs.php, CSRF) over HTTP, and the recovery clock runs through the module's
 * housekeeping (what cron does). The `logs` switch is OFF at the start (the default) and is put back OFF at the end.
 */

use RivetCore\Rmm\Authz\RmmAbility;

$lgOrigLimits = $rmm->remoteSettings()->limits();

$lgGet = fn (string $who, string $path) => web($wb, 'GET', $path, $SS[$who]);
$lgPost = fn (string $who, array $f, array $headers = []) => web($wb, 'POST', '/agent/post/rmm_logs.php', $SS[$who], $f + ['csrf_token' => 'csrftok1'], $headers);
$lgLoc = fn (string $hdr) => preg_match('/^location:\s*(\S+)/mi', $hdr, $mm) === 1 ? $mm[1] : '';
$lgRows = fn (string $sql) => $rows($sql);
$lgBulk = fn (string $body) => (function () use ($body) { preg_match_all('/BULK-\d{4}/', $body, $m); return $m[0]; })();
$lgRule = fn (string $name) => $rows("SELECT * FROM endpoint_agent_log_rules WHERE name='" . $esc($name) . "'")[0] ?? null;
$lgEvents = fn () => array_map(fn ($r) => json_decode($r[0], true), $db->query("SELECT payload FROM integration_jobs WHERE job_type='webhook.deliver' ORDER BY job_id")->fetch_all());
$lgNow = time();
$lgIso = fn (int $ago) => gmdate('Y-m-d\TH:i:s\Z', $lgNow - $ago);
$lgEv = fn (int $ago, string $level, string $src, ?int $code, string $msg) => ['ts' => $lgIso($ago), 'level' => $level, 'source' => $src, 'event_id' => $code, 'message' => $msg];

// ---- the starting state: module on, logs OFF (the default)
$ok($rmm->enabled() && !$rmm->featureOn('logs') && !rivetRmmFeatureOn('logs'), 'logs: the module is on and the logs switch is off at the start');
$logNav = fn (?int $uid = null) => array_values(array_filter(array_column(rivetRmmAutoNav($uid), 'href'), fn ($h) => str_contains($h, 'rmm_log')));
$ok($logNav() === [] && $logNav($USER['admin']) === [], 'menu: no Logs entries while the switch is off');
// statements of THIS connection only (the global counter also counts other schemas on a shared server)
$lgQ = function () use ($db): int { return (int) $db->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch_row()[1]; };
$lgOver = (function () use ($lgQ) { $x = $lgQ(); return $lgQ() - $x; })();
$a = $lgQ(); $logNav(); $b = $lgQ();
$ok($b - $a === $lgOver, 'menu: the check costs no statement while the switch is off (' . ($b - $a) . ' vs ' . $lgOver . ')');
foreach (['/agent/rmm_logs.php', '/agent/rmm_log_rules.php'] as $path) {
    [$c, $body] = $lgGet('admin', $path);
    $ok($c === 403 && str_contains($body, 'switched off'), "$path: 403 and the switched-off notice while the logs switch is off ($c)");
}
[$c, , $hd] = $lgPost('admin', ['action' => 'save_rule', 'name' => 'LG off rule', 'min_level' => 'error', 'contains' => 'x', 'threshold' => '1', 'window_min' => '5', 'severity' => 'warn', 'resolve_after_min' => '30', 'client_id' => '0', 'enabled' => '1']);
$ok($lgRule('LG off rule') === null, 'handler: a rule cannot be created while the logs switch is off (' . $c . ')');
[$c, $b2] = $lgPost('admin', ['action' => 'save_rule', 'name' => 'LG off rule', 'min_level' => 'error', 'contains' => 'x', 'threshold' => '1', 'window_min' => '5', 'severity' => 'warn', 'resolve_after_min' => '30', 'client_id' => '0'], ['Accept: application/json']);
$j = json_decode($b2, true);
$ok($c === 404 && ($j['success'] ?? null) === false && str_contains((string) ($j['error'] ?? ''), 'switched off'), 'handler (JSON): the switched-off refusal is a 404 with a message');

// ---- turning it on takes the acknowledgement; the sources are chosen by an administrator
$srcs = ['linux' => [['kind' => 'journal', 'units' => ['ssh.service'], 'identifiers' => [], 'min_level' => 'warning', 'contains' => []]],
    'windows' => [['kind' => 'eventlog', 'channel' => 'Security', 'levels' => ['critical', 'error', 'warning'], 'event_ids' => [], 'contains' => []]]];
$r = $rmm->remoteActions()->updateSettings($admin, ['features' => ['logs' => true]]);
$fresh();
$ok(!$r->ok && $r->code === 'confirmation_required' && str_contains($r->message, 'database') && !$rmm->featureOn('logs'), 'Core refuses to switch logs on without accept_logs_storage and shows the storage warning');
$r = $rmm->remoteActions()->updateSettings($admin, ['features' => ['logs' => true], 'accept_logs_storage' => true, 'logs_collect' => $srcs, 'limits' => ['logs_retention_days' => 90]]);
$fresh();
$ok($r->ok && $rmm->featureOn('logs') && rivetRmmFeatureOn('logs'), 'logs switched on through RemoteActions::updateSettings() with the acknowledgement and one source per platform (' . $r->message . ')');

// ---- devices (own assets so the seed is left alone): two in Dept A (the second is a Linux agent that never announces logs:collect), one in Dept B
$lgDev = $lgTok = $lgAsset = [];
foreach (['LGWIN1' => [1, 'A', 'windows'], 'LGLNX2' => [1, 'A', 'linux'], 'LGB' => [2, 'B', 'linux']] as $n => [$client, $tk, $os]) {
    $q("INSERT INTO assets SET asset_type='Laptop', asset_name='$n', asset_make='Dell', asset_serial='SER-$n', asset_client_id=$client, asset_status='Active'");
    $lgAsset[$n] = (int) $db->insert_id;
    [$c, , $j] = ea_enroll($S['enroll'][$tk], ea_dev(['serial' => "SER-$n", 'hostname' => $n, 'os' => $os, 'os_version' => $os === 'linux' ? 'Ubuntu 24.04' : 'Windows 11 23H2']));
    $lgDev[$n] = (int) ($j['device_id'] ?? 0);
    $lgTok[$n] = (string) ($j['device_token'] ?? '');
    $ok($c === 201 && $lgDev[$n] > 0, "$n enrolled through the real endpoint");
}
[$c, , $j] = ea_checkin($lgTok['LGWIN1'], ['capabilities' => ['job:collect', 'logs:collect']]);
$ok($c === 200 && in_array('logs:collect', (array) ($j['features'] ?? []), true) && isset($j['config']['logs']), 'check-in: a device that announced logs:collect is offered log collection (a source is configured)');
ea_checkin($lgTok['LGLNX2'], ['capabilities' => ['job:collect']]);
ea_checkin($lgTok['LGB'], ['capabilities' => ['job:collect', 'logs:collect']]);
$lgPostLogs = fn (string $dev, array $events) => http('POST', '/api/v1/agent_logs', $lgTok[$dev], ['events' => $events, 'dropped' => 0]);

// ---- the rules come first, so the lines below are evaluated against them (see the rule section for the cases)
// (the search section is first in the file; the rule section creates its rules through the real handler before its own lines are sent)

// ---- lines through the real device endpoint
$bulk = [];
for ($i = 1; $i <= 130; $i++) {
    $bulk[] = $lgEv(600 + $i * 5, 'info', 'Bulk.Source', null, sprintf('BULK-%04d routine entry', $i));
}
$hostile = '<script>alert(1)</script> "dq" \'sq\' & </td></tr><img src=x onerror=alert(2)>';
$long = str_repeat('lorem ipsum ', 60) . 'LONGTAIL';
$special = [
    $lgEv(30, 'critical', 'Kernel', 41, 'LV-critical kernel power loss'),
    $lgEv(40, 'error', 'Service Control Manager', 7034, 'LV-error service crashed'),
    $lgEv(50, 'warning', 'Security', 4625, 'LV-warning an account failed to log on DEPTA-SECRET-LINE'),
    $lgEv(60, 'info', 'Application', 1000, 'LV-info routine'),
    $lgEv(70, 'debug', 'Application', null, 'LV-debug trace'),
    $lgEv(80, 'error', 'Evil<b>src', null, $hostile),
    $lgEv(90, 'warning', 'Long.Source', null, $long),
    $lgEv(100, 'info', 'Percent.Source', null, 'PCT 100% done'),
    $lgEv(3 * 86400, 'info', 'Old.Source', null, 'OLD3D line'),
    $lgEv(10 * 86400, 'info', 'Old.Source', null, 'OLD10D line'),
];
[$c, , $j] = $lgPostLogs('LGWIN1', array_merge($special, $bulk));
$ok($c === 200 && ($j['accepted'] ?? 0) === 140 && ($j['dropped'] ?? 1) === 0, 'LGWIN1: 140 lines accepted by /api/v1/agent_logs (' . json_encode($j) . ')');
[$c, , $j] = $lgPostLogs('LGLNX2', [$lgEv(20, 'error', 'sshd', null, 'LG2-ONLY-LINE one'), $lgEv(21, 'warning', 'sshd', null, 'LG2-ONLY-LINE two')]);
$ok($c === 200 && ($j['accepted'] ?? 0) === 2, 'LGLNX2: two lines accepted');
[$c, , $j] = $lgPostLogs('LGB', [$lgEv(10, 'error', 'journal', null, 'DEPTB-ONLY-LINE one'), $lgEv(11, 'error', 'journal', null, 'DEPTB-ONLY-LINE two'), $lgEv(12, 'warning', 'journal', null, 'DEPTB-ONLY-LINE three'),
    $lgEv(13, 'info', 'journal', 5, 'DEPTB-ONLY-LINE four')]);
$ok($c === 200 && ($j['accepted'] ?? 0) === 4, 'LGB (Dept B): four lines accepted');
$fleetRows = (int) $one('SELECT COALESCE(SUM(row_count),0) FROM endpoint_agent_log_state');
$ok($fleetRows === 146, 'the stored counters agree: 146 lines in total (' . $fleetRows . ')');

// ============================================================ the Logs page: who sees it, and the scope
$perm = fn (string $who) => [$rmm->authorizer()->allowed($USER[$who], RmmAbility::DEVICE_VIEW, 0) && $rmm->authorizer()->allowed($USER[$who], RmmAbility::JOB_RUN_SAVED, 0),
    $rmm->authorizer()->allowed($USER[$who], RmmAbility::DEVICE_VIEW, 0) && $rmm->authorizer()->allowed($USER[$who], RmmAbility::ALERT_MANAGE, 0)];
rivetRmmForgetAccess();
$expect = ['admin' => [true, true], 'tech' => [true, true], 'rebootonly' => [true, false], 'viewer' => [false, false], 'remoteonly' => [false, false], 'moduleonly' => [false, false], 'normo' => [false, false], 'deptb' => [true, true]];
foreach ($expect as $who => $e) {
    $ok($perm($who) === $e, "abilities: $who reads logs = " . json_encode($e[0]) . ", manages rules = " . json_encode($e[1]));
}
foreach ($expect as $who => [$read, $rules]) {
    [$c, $body] = $lgGet($who, '/agent/rmm_logs.php');
    $ok($read ? ($c === 200 && str_contains($body, 'id="lg-count"')) : ($c === 403 && !str_contains($body, 'DEPTA-SECRET-LINE') && !str_contains($body, 'id="lg-count"')), "Logs page ($who): " . ($read ? 'allowed' : 'refused') . " ($c)");
    [$c, $body] = $lgGet($who, '/agent/rmm_log_rules.php');
    $ok($rules ? ($c === 200 && str_contains($body, 'How an alert is made')) : ($c === 403 && !str_contains($body, 'How an alert is made')), "Log rules page ($who): " . ($rules ? 'allowed' : 'refused') . " ($c)");
}
// the menu: an entry only for a user who has the ability (state-file answer for the module, then Core for the user)
$ok($logNav() === ['/agent/rmm_logs.php', '/agent/rmm_log_rules.php'], 'menu: both entries are offered while the switch is on and no user is named');
$ok($logNav($USER['admin']) === ['/agent/rmm_logs.php', '/agent/rmm_log_rules.php'] && $logNav($USER['tech']) === ['/agent/rmm_logs.php', '/agent/rmm_log_rules.php'], 'menu: administrator and technician see Logs and Log rules');
$ok($logNav($USER['rebootonly']) === ['/agent/rmm_logs.php'] && $logNav($USER['viewer']) === [] && $logNav($USER['remoteonly']) === [] && $logNav($USER['moduleonly']) === [], 'menu: reboot-only sees Logs only; viewer, remote-only and module-only roles see neither');
[$c, $body] = $lgGet('admin', '/agent/rmm_fleet.php');
$ok(str_contains($body, 'href="/agent/rmm_logs.php"') && str_contains($body, 'href="/agent/rmm_log_rules.php"'), 'the Endpoints menu of the real page links Logs and Log rules for an administrator');
[$c, $body] = $lgGet('viewer', '/agent/rmm_fleet.php');
$ok($c === 200 && !str_contains($body, 'href="/agent/rmm_logs.php"') && !str_contains($body, 'href="/agent/rmm_log_rules.php"'), 'the Endpoints menu does not link the log pages for a viewer');

// ---- admin: everything, with the header
[$c, $body] = $lgGet('admin', '/agent/rmm_logs.php?size=200&level=debug');
$ok($c === 200 && str_contains($body, 'DEPTA-SECRET-LINE') && str_contains($body, 'DEPTB-ONLY-LINE') && str_contains($body, 'LG2-ONLY-LINE'), 'admin: the fleet search shows Dept A and Dept B lines');
$ok(str_contains($body, 'of 144') && str_contains($body, '(newest first)'), 'admin: "Showing 1-144 of 144 (newest first)" with totals');
$ok(str_contains($body, 'Last 24 hours') && !str_contains($body, 'Last 30 days') && str_contains($body, 'at most 7 days') && str_contains($body, 'pick one device to go back up to 90 days'), 'admin: the fleet presets stop at 7 days and the cap is stated in plain words');
$ok(str_contains($body, 'entries stored') && str_contains($body, '>' . $fleetRows . '<') && str_contains($body, 'oldest entry') && str_contains($body, 'worst-case storage'), 'admin: the header shows rows stored, the oldest entry and the worst-case storage');
$ok(str_contains($body, 'LGWIN1') && str_contains($body, 'href="/agent/asset_details.php?asset_id=' . $lgAsset['LGWIN1'] . '#rmm-logs"'), 'a line links to its device asset page (Logs tab)');
$ok(str_contains($body, 'badge text-bg-danger') && str_contains($body, 'Critical') && str_contains($body, 'Warning') && str_contains($body, 'Information'), 'level pills carry a word, not only a colour');
$ok(str_contains($body, '<time datetime=') && preg_match('~\d{2}:\d{2}:\d{2} (?!UTC)[A-Z]{3,5}</div>~', $body) === 1, 'each line shows a relative time and the absolute time in the company zone (a zone label other than UTC when the company zone is not UTC)');
// hostile text and a long message
$ok(!str_contains($body, '<script>alert(1)') && !str_contains($body, '<img src=x onerror=alert(2)>') && str_contains($body, '&lt;script&gt;alert(1)&lt;/script&gt;') && str_contains($body, '&quot;dq&quot;') && str_contains($body, 'Evil&lt;b&gt;src'),
    'hostile message and source text is escaped (script, quotes, tag in the source)');
$ok(str_contains($body, '<details class="rmm-log-msg">') && str_contains($body, 'show more') && str_contains($body, 'LONGTAIL') && substr_count($body, 'LONGTAIL') === 1, 'a long message sits behind a <details> that reads without JavaScript, once in the page');
$ok(str_contains($body, 'data-rmm-log-toggle-all') && str_contains($body, '/js/rmm_logs.js'), 'the page links js/rmm_logs.js and offers "show all messages"');
$ok(!str_contains($body, $lgTok['LGWIN1']) && !str_contains($body, $S['enroll']['A']), 'no device credential or enrollment token appears in the page');

// ---- the restricted user (Dept B only): no Dept A line anywhere
[$c, $body] = $lgGet('deptb', '/agent/rmm_logs.php?size=200&level=debug');
$ok($c === 200 && str_contains($body, 'DEPTB-ONLY-LINE') && !str_contains($body, 'DEPTA-SECRET-LINE') && !str_contains($body, 'BULK-') && !str_contains($body, 'LG2-ONLY-LINE') && !str_contains($body, 'LONGTAIL'), 'Dept B user: the fleet search holds only Dept B lines');
$ok(str_contains($body, 'of 4') && !str_contains($body, 'LGWIN1') && !str_contains($body, 'LGLNX2') && !str_contains($body, 'Dept A'), 'Dept B user: totals, device picker and department picker hold nothing of Dept A');
$ok(str_contains($body, 'fleet totals need access to every department') && !str_contains($body, '>' . $fleetRows . '<'), 'Dept B user: the header does not reveal the size of the fleet');
[$c, $body] = $lgGet('deptb', '/agent/rmm_logs.php?device_id=' . $lgDev['LGWIN1']);
$ok($c === 200 && str_contains($body, 'does not exist, or it belongs to a department you cannot see') && !str_contains($body, 'DEPTA-SECRET-LINE') && !str_contains($body, 'BULK-'), 'Dept B user: another department\'s device is the same "not found" as a missing one');
[$c, $body2] = $lgGet('deptb', '/agent/rmm_logs.php?device_id=999999');
$ok(str_contains($body2, 'does not exist, or it belongs to a department you cannot see'), 'Dept B user: a missing device gives the same message');
[$c, $body] = $lgGet('deptb', '/agent/rmm_logs.php?client_id=1&size=200');
$ok(str_contains($body, 'does not exist, or you cannot see it') && !str_contains($body, 'DEPTA-SECRET-LINE'), 'Dept B user: asking for Dept A as the department filter is refused');
[$c, $body] = $lgGet('deptb', '/agent/rmm_logs.php?device_id=' . $lgDev['LGB'] . '&level=debug');
$ok(str_contains($body, 'DEPTB-ONLY-LINE four') && str_contains($body, 'of 4') && str_contains($body, 'entries stored for this device'), 'Dept B user: own device search works and the device header shows its own figures');
$st = rivetRmmLogStatsView(['uid' => $USER['deptb'], 'name' => 'deptb', 'csrf' => '', 'perm' => [], 'mysqli' => $db], null);
$ok($st['ok'] && $st['fleet_rows'] === null && $st['oldest'] === null && $st['unrestricted'] === false, 'stats view-model: a restricted user gets no fleet row count and no oldest entry');
$st = rivetRmmLogStatsView(['uid' => $USER['deptb'], 'name' => 'deptb', 'csrf' => '', 'perm' => [], 'mysqli' => $db], $lgDev['LGWIN1']);
$ok(!$st['ok'], 'stats view-model: a restricted user asking for another department\'s device gets Core\'s refusal');

// ---- filters, one at a time (admin)
$u = '/agent/rmm_logs.php?size=200&';
[$c, $body] = $lgGet('admin', $u . 'device_id=' . $lgDev['LGWIN1'] . '&level=debug');
$ok(str_contains($body, 'LV-critical') && str_contains($body, 'BULK-0001') && !str_contains($body, 'DEPTB-ONLY') && !str_contains($body, 'LG2-ONLY') && str_contains($body, 'of 138'), 'filter device: only that device (138 lines in 24 hours)');
[$c, $body] = $lgGet('admin', $u . 'level=error');
$ok(str_contains($body, 'LV-critical') && str_contains($body, 'LV-error') && str_contains($body, 'DEPTB-ONLY-LINE one') && str_contains($body, 'LG2-ONLY-LINE one') && !str_contains($body, 'LV-warning') && !str_contains($body, 'LV-info') && !str_contains($body, 'LV-debug') && !str_contains($body, 'BULK-')
    && !str_contains($body, 'DEPTB-ONLY-LINE three'), 'filter level "error or worse": critical and error only');
[$c, $body] = $lgGet('admin', $u . 'level=warning');
$ok(str_contains($body, 'LV-warning') && str_contains($body, 'LV-error') && !str_contains($body, 'LV-info') && !str_contains($body, 'LV-debug'), 'filter level "warning or worse"');
[$c, $body] = $lgGet('admin', $u . 'level=debug');
$ok(str_contains($body, 'LV-debug') && str_contains($body, 'LV-info') && str_contains($body, 'BULK-0130'), 'filter level "debug or worse": everything');
[$c, $body] = $lgGet('admin', $u . 'level=nonsense');
$ok(str_contains($body, 'of 144'), 'an unknown level is ignored (any level)');
[$c, $body] = $lgGet('admin', $u . 'source=kernel');
$ok(str_contains($body, 'LV-critical') && !str_contains($body, 'LV-error') && str_contains($body, 'Showing 1-1 of 1 (newest first)'), 'filter source (substring, any case): one line');
[$c, $body] = $lgGet('admin', $u . 'code=4625');
$ok(str_contains($body, 'LV-warning') && !str_contains($body, 'LV-critical') && str_contains($body, 'of 1 '), 'filter event id: one line');
[$c, $body] = $lgGet('admin', $u . 'code=abc');
$ok(str_contains($body, 'The event id must be a whole number, so it was ignored'), 'a bad event id is ignored with a message');
[$c, $body] = $lgGet('admin', $u . 'q=LONGTAIL');
$ok(str_contains($body, 'LONGTAIL') && !str_contains($body, 'LV-') && str_contains($body, 'of 1 '), 'filter text: one line');
[$c, $body] = $lgGet('admin', $u . 'q=' . rawurlencode('%'));
$ok(str_contains($body, 'PCT 100% done') && str_contains($body, 'of 1 ') && !str_contains($body, 'BULK-'), 'filter text: a percent sign is a literal character, not a wildcard');
[$c, $body] = $lgGet('admin', $u . 'q=' . rawurlencode('lv-ERROR'));
$ok(str_contains($body, 'LV-error') && str_contains($body, 'of 1 '), 'filter text ignores upper and lower case');
[$c, $body] = $lgGet('admin', $u . 'q=NOSUCHTEXT');
$ok(str_contains($body, 'No log entries match') && str_contains($body, 'role="status"'), 'no match: an empty state, not an error');
[$c, $body] = $lgGet('admin', $u . 'client_id=2&level=debug');
$ok(str_contains($body, 'DEPTB-ONLY-LINE') && !str_contains($body, 'BULK-') && str_contains($body, 'of 4'), 'filter department');
// time
[$c, $body] = $lgGet('admin', $u . 'range=1h&level=debug');
$ok(str_contains($body, 'BULK-0130') && !str_contains($body, 'OLD3D') && !str_contains($body, 'OLD10D'), 'range 1 hour: recent lines, not the 3-day-old one');
[$c, $body] = $lgGet('admin', $u . 'range=7d&level=debug');
$ok(str_contains($body, 'OLD3D') && !str_contains($body, 'OLD10D') && str_contains($body, 'of 145'), 'range 7 days: includes the 3-day-old line, not the 10-day-old one');
[$c, $body] = $lgGet('admin', $u . 'level=debug');
$ok(!str_contains($body, 'OLD3D'), 'the default range is 24 hours');
$from = gmdate('Y-m-d\TH:i', time() - 12 * 86400);
$to = gmdate('Y-m-d\TH:i', time());
[$c, $body] = $lgGet('admin', $u . 'level=debug&range=custom&from=' . $from . '&to=' . $to);
$ok(str_contains($body, 'A search covers at most 7 days across devices (one device can go back 90 days)') && str_contains($body, 'OLD3D') && !str_contains($body, 'OLD10D'), 'custom range wider than 7 days: the fleet search is clamped and the page says so with the range shown');
$ok(str_contains($body, 'Range searched: ') && str_contains($body, 'value="' . $from . '"'), 'the clamped range is stated and the typed values stay in the form');
[$c, $body] = $lgGet('admin', $u . 'level=debug&device_id=' . $lgDev['LGWIN1'] . '&range=custom&from=' . $from . '&to=' . $to);
$ok(!str_contains($body, 'A search covers at most') && str_contains($body, 'OLD10D') && str_contains($body, 'OLD3D'), 'custom range on one device goes back 12 days without a clamp');
[$c, $body] = $lgGet('admin', $u . 'range=custom&from=' . $to . '&to=' . $from);
$ok(str_contains($body, 'The start must be before the end'), 'custom range with the start after the end: a message and the last 24 hours');
[$c, $body] = $lgGet('admin', $u . 'range=custom&from=garbage&to=more%20garbage');
$ok($c === 200 && str_contains($body, 'id="lg-count"'), 'custom range with garbage dates still answers');
// pagination
$seen = [];
$tot = 0;
foreach ([1, 2, 3] as $pg) {
    [$c, $body] = $lgGet('admin', '/agent/rmm_logs.php?device_id=' . $lgDev['LGWIN1'] . '&level=debug&size=50&page=' . $pg);
    $m = $lgBulk($body);
    $ok($c === 200 && str_contains($body, 'of 138'), "pagination: page $pg answers with the same total");
    $ok(count($m) === count(array_unique($m)) && count(array_intersect($seen, $m)) === 0, "pagination: page $pg repeats nothing from the earlier pages");
    $seen = array_merge($seen, $m);
    if ($pg === 1) {
        $ok(str_contains($body, 'Showing 1-50 of 138') && str_contains($body, 'rel="next"') && preg_match('/class="page-item disabled"><a class="page-link" rel="prev"/', $body) === 1, 'pagination: page 1 says "Showing 1-50 of 138", Newer is disabled, Older is a link');
    }
    if ($pg === 3) {
        $ok(str_contains($body, 'Showing 101-138 of 138') && preg_match('/class="page-item disabled"><a class="page-link" rel="next"/', $body) === 1, 'pagination: the last page says "Showing 101-138 of 138" and Older is disabled');
    }
}
$ok(count($seen) === 130 && count(array_unique($seen)) === 130 && $seen[0] === 'BULK-0001' && end($seen) === 'BULK-0130', 'pagination: three pages cover all 130 bulk lines once each, newest first (' . count($seen) . ')');
[$c, $body] = $lgGet('admin', '/agent/rmm_logs.php?device_id=' . $lgDev['LGWIN1'] . '&level=debug&size=50&page=99');
$ok($c === 200 && str_contains($body, 'No entries on this page') && str_contains($body, 'Back to the first page') && !str_contains($body, 'BULK-'), 'pagination: an offset past the end is an empty page with a way back, not an error');
[$c, $body] = $lgGet('admin', '/agent/rmm_logs.php?device_id=' . $lgDev['LGWIN1'] . '&level=debug&size=7&page=-3');
$ok($c === 200 && str_contains($body, 'Showing 1-50 of 138'), 'pagination: an unsupported page size and a negative page fall back to 50 and page 1');
[$c, $body] = $lgGet('admin', '/agent/rmm_logs.php?device_id=' . $lgDev['LGWIN1'] . '&level=debug&size=100');
$ok(str_contains($body, 'Showing 1-100 of 138'), 'pagination: page size 100');

// ============================================================ the Logs tab of the asset page
[$c, $body] = $lgGet('admin', '/agent/asset_details.php?asset_id=' . $lgAsset['LGWIN1']);
$ok($c === 200 && str_contains($body, 'id="rmm-tab-logs"') && str_contains($body, 'id="rmm-pane-logs"') && str_contains($body, 'aria-controls="rmm-pane-logs"') && str_contains($body, '/js/rmm_logs.js'), 'asset page: the Logs tab, its pane and js/rmm_logs.js appear while the switch is on');
$ok(str_contains($body, 'BULK-0001') && str_contains($body, 'LV-critical') && !str_contains($body, 'DEPTB-ONLY') && !str_contains($body, 'LG2-ONLY'), 'asset page: the tab shows this device\'s lines only');
$ok(!str_contains($body, 'has not announced') && !str_contains($body, 'No log source is configured'), 'asset page: no explanatory note for a device that announced logs:collect with a source configured');
$ok(str_contains($body, 'name="lg_q"') && str_contains($body, 'name="lg_range"') && str_contains($body, 'action="/agent/asset_details.php#rmm-logs"') && str_contains($body, 'name="asset_id" value="' . $lgAsset['LGWIN1'] . '"'), 'asset page: the filters are a GET form back to the asset page with #rmm-logs');
$ok(str_contains($body, 'value="90d"') && str_contains($body, 'Last 90 days') && str_contains($body, 'One device can be searched up to 90 days back'), 'asset page: the device presets go to 90 days and the cap is in words');
[$c, $body] = $lgGet('admin', '/agent/asset_details.php?asset_id=' . $lgAsset['LGWIN1'] . '&lg_q=LONGTAIL');
$ok(str_contains($body, 'LONGTAIL') && !str_contains($body, 'BULK-0001') && str_contains($body, 'of 1 '), 'asset page: the text filter narrows the tab');
[$c, $body] = $lgGet('admin', '/agent/asset_details.php?asset_id=' . $lgAsset['LGWIN1'] . '&lg_range=90d&lg_level=debug&lg_size=200');
$ok(str_contains($body, 'OLD10D') && str_contains($body, 'of 140'), 'asset page: 90 days reaches the 10-day-old line');
[$c, $body] = $lgGet('admin', '/agent/asset_details.php?asset_id=' . $lgAsset['LGWIN1'] . '&lg_level=debug&lg_size=50&lg_page=2');
$ok(str_contains($body, 'Showing 51-100 of 138') && str_contains($body, 'href="/agent/asset_details.php?asset_id=' . $lgAsset['LGWIN1'] . '&amp;lg_level=debug&amp;lg_page=3#rmm-logs"'), 'asset page: pagination links keep the filters and the #rmm-logs anchor');
[$c, $body] = $lgGet('admin', '/agent/asset_details.php?asset_id=' . $lgAsset['LGWIN1'] . '&lg_q=NOSUCHTEXT');
$ok(str_contains($body, 'No entries from this device in this range'), 'asset page: no entries in range is an explanatory empty state');
[$c, $body] = $lgGet('admin', '/agent/asset_details.php?asset_id=' . $lgAsset['LGLNX2']);
$ok(str_contains($body, 'LG2-ONLY-LINE') && str_contains($body, 'has not announced that it can collect logs') && str_contains($body, 'logs:collect'), 'asset page: a device that never announced logs:collect says so and still shows what it sent');
// no source configured for a platform
$r = $rmm->remoteActions()->updateSettings($admin, ['logs_collect' => ['linux' => [], 'windows' => $srcs['windows']]]);
$fresh();
[$c, $body] = $lgGet('admin', '/agent/asset_details.php?asset_id=' . $lgAsset['LGLNX2']);
$ok($r->ok && str_contains($body, 'No log source is configured for Linux') && str_contains($body, 'href="/admin/settings_endpoint_agent.php"'), 'asset page (admin): no source configured for the platform, with a link to Administration');
[$c, $body] = $lgGet('tech', '/agent/asset_details.php?asset_id=' . $lgAsset['LGLNX2']);
$ok(str_contains($body, 'No log source is configured for Linux') && !str_contains($body, 'href="/admin/settings_endpoint_agent.php"') && str_contains($body, 'Ask an administrator'), 'asset page (technician): the same note without the link');
$r = $rmm->remoteActions()->updateSettings($admin, ['logs_collect' => $srcs]);
$fresh();
// who does not get the tab
[$c, $body] = $lgGet('viewer', '/agent/asset_details.php?asset_id=' . $lgAsset['LGWIN1']);
$ok($c === 200 && str_contains($body, 'id="rmm-strip"') && !str_contains($body, 'rmm-tab-logs') && !str_contains($body, 'rmm-pane-logs') && !str_contains($body, 'BULK-'), 'asset page (viewer: cannot read job output): no Logs tab, no lines');
[$c, $body] = $lgGet('deptb', '/agent/asset_details.php?asset_id=' . $lgAsset['LGWIN1']);
$ok(!str_contains($body, 'rmm-tab-logs') && !str_contains($body, 'BULK-') && !str_contains($body, 'DEPTA-SECRET-LINE'), 'asset page (Dept B user, Dept A device): no tab, no lines');
[$c, $body] = $lgGet('deptb', '/agent/asset_details.php?asset_id=' . $lgAsset['LGB']);
$ok(str_contains($body, 'id="rmm-tab-logs"') && str_contains($body, 'DEPTB-ONLY-LINE') && !str_contains($body, 'DEPTA-SECRET-LINE'), 'asset page (Dept B user, Dept B device): the tab shows the device\'s own lines');
foreach (['rebootonly' => true, 'viewer' => false, 'remoteonly' => false, 'tech' => true, 'moduleonly' => false] as $who => $tab) {
    $pv = rivetRmmUiPanel($db, $lgAsset['LGWIN1'], $USER[$who]);
    $html = $pv === null ? '' : rivetRmmLogPanelSection($pv, $db, 'tok');
    $ok(($html !== '') === $tab, "panel hook ($who): the Logs tab is " . ($tab ? 'drawn' : 'not drawn') . ' (the job-output grant decides)');
}

// ============================================================ the Log rules page and its handler
$goodRule = ['action' => 'save_rule', 'name' => 'LR disk', 'enabled' => '1', 'client_id' => '0', 'min_level' => 'error', 'source_contains' => '', 'code' => '', 'contains' => 'disk [sda] (error) a.c*',
    'threshold' => '1', 'window_min' => '5', 'severity' => 'warn', 'resolve_after_min' => '30'];
// validation errors reach the user and the typed values come back
[$c, , $hd] = $lgPost('admin', ['name' => '   '] + $goodRule);
$ok($c === 302 && str_contains($lgLoc($hd), 'new=1') && $lgRule('LR disk') === null && $one("SELECT COUNT(*) FROM endpoint_agent_log_rules") === '0', 'rules: an empty name is refused by Core and the user is sent back to the form');
[$c, $body] = $lgGet('admin', '/agent/rmm_log_rules.php?new=1');
$ok(str_contains($body, 'A rule needs a name of 1 to 100 characters') && str_contains($body, 'value="disk [sda] (error) a.c*"'), 'rules: Core\'s message is shown next to the form and the phrase typed is still in it');
[$c, $body] = $lgGet('admin', '/agent/rmm_log_rules.php?new=1');
$ok(!str_contains($body, 'A rule needs a name'), 'rules: the refusal is shown once');
[$c, , $hd] = $lgPost('admin', ['min_level' => 'info', 'contains' => '', 'name' => 'LR too wide'] + $goodRule);
[$c, $body] = $lgGet('admin', '/agent/rmm_log_rules.php?new=1');
$ok($lgRule('LR too wide') === null && str_contains($body, 'needs a source, an event id or a phrase'), 'rules: an info-level rule with no source, id or phrase is refused with Core\'s words');
[$c, , $hd] = $lgPost('admin', ['threshold' => 'many'] + $goodRule);
[$c, $body] = $lgGet('admin', '/agent/rmm_log_rules.php?new=1');
$ok($lgRule('LR disk') === null && str_contains($body, 'Matches needed must be a whole number'), 'rules: a count that is not a number is refused before Core with a plain message');
[$c, , $hd] = $lgPost('admin', ['threshold' => '5000'] + $goodRule);
[$c, $body] = $lgGet('admin', '/agent/rmm_log_rules.php?new=1');
$ok($lgRule('LR disk') === null && str_contains($body, 'threshold must be a whole number from 1 to 1000'), 'rules: a count above the limit is refused by Core');
[$c, $body] = $lgGet('admin', '/agent/rmm_log_rules.php');
$ok(str_contains($body, 'No log rules yet') && str_contains($body, 'rmmAutoConfirm') && str_contains($body, 'data-rmm-confirm') === false, 'rules: the empty state, with the shared confirm dialog on the page');
// create
[$c, , $hd] = $lgPost('admin', $goodRule);
$r1 = $lgRule('LR disk');
$ok($c === 302 && str_contains($lgLoc($hd), '/agent/rmm_log_rules.php') && $r1 !== null && $r1['contains'] === 'disk [sda] (error) a.c*' && (int) $r1['min_level'] === 2 && (int) $r1['threshold'] === 1 && $r1['severity'] === 'warn' && (int) $r1['client_id'] === 0 && (int) $r1['enabled'] === 1,
    'rules: a rule is created through the real handler with the typed values');
$rid1 = (int) ($r1['rule_id'] ?? 0);
$ok((int) $one("SELECT COUNT(*) FROM logs WHERE log_description LIKE '%created log rule #$rid1%'") === 1, 'rules: the creation is in the audit log');
[$c, $body] = $lgGet('admin', '/agent/rmm_log_rules.php');
$ok(str_contains($body, 'Rule created.') && str_contains($body, 'LR disk') && str_contains($body, 'log.rule_' . $rid1) && str_contains($body, 'Error or worse') && str_contains($body, '<code class="rmm-mono">disk [sda] (error) a.c*</code>'), 'rules: the list shows the new rule, its check key and the match in words');
$ok(str_contains($body, 'data-rmm-confirm="Delete the rule LR disk?') && str_contains($body, 'Turn off'), 'rules: delete asks first in the in-page dialog (data-rmm-confirm); no window.confirm');
$ok(!str_contains($body, 'onclick=') && !str_contains($body, 'javascript:'), 'rules: no inline handler and no javascript: URL');
$ok(str_contains($body, 'never a regular expression') && str_contains($body, 'How an alert is made') && str_contains($body, 'log.rule_&lt;id&gt;'), 'rules: the help text says the phrase is plain text and explains how an alert is made');
$ok(str_contains($body, 'Alerting</strong> switch is on'), 'rules: the explanation says what the Alerting switch does while it is on');
$ok(str_contains($body, '0 devices matching') && str_contains($body, '/agent/rmm_agent_alerts.php?state=active&amp;check_key=log.rule_' . $rid1) && str_contains($body, '0 open alerts'), 'rules: the row shows its state and links to the Agent alerts page filtered by the check key');
// edit
[$c, $body] = $lgGet('admin', '/agent/rmm_log_rules.php?rule_id=' . $rid1);
$ok($c === 200 && str_contains($body, 'Edit rule') && str_contains($body, 'value="LR disk"') && str_contains($body, 'name="rule_id" value="' . $rid1 . '"') && preg_match('/id="lr-enabled"[^>]*checked/', $body) === 1, 'rules: the edit form is filled with the rule');
[$c, , $hd] = $lgPost('admin', ['rule_id' => (string) $rid1, 'name' => 'LR disk renamed', 'severity' => 'fail', 'resolve_after_min' => '45'] + $goodRule);
$r1 = $lgRule('LR disk renamed');
$ok($c === 302 && $r1 !== null && (int) $r1['rule_id'] === $rid1 && $r1['severity'] === 'fail' && (int) $r1['resolve_after_min'] === 45, 'rules: edit changes the same rule (name, severity, resolve after)');
[$c, , $hd] = $lgPost('admin', ['rule_id' => (string) $rid1, 'name' => ''] + $goodRule);
$ok(str_contains($lgLoc($hd), 'rule_id=' . $rid1) && $lgRule('LR disk renamed') !== null, 'rules: a refused edit sends the user back to that rule\'s form and changes nothing');
// toggle
[$c, , $hd] = $lgPost('admin', ['action' => 'toggle_rule', 'rule_id' => (string) $rid1]);
$ok($c === 302 && (int) $one("SELECT enabled FROM endpoint_agent_log_rules WHERE rule_id=$rid1") === 0, 'rules: turn off');
[$c, $body] = $lgGet('admin', '/agent/rmm_log_rules.php');
$ok(str_contains($body, 'Rule turned off') && str_contains($body, 'badge text-bg-secondary') && str_contains($body, 'Turn on'), 'rules: the list shows it off, in words, with a Turn on button');
[$c, , $hd] = $lgPost('admin', ['action' => 'toggle_rule', 'rule_id' => (string) $rid1]);
$ok((int) $one("SELECT enabled FROM endpoint_agent_log_rules WHERE rule_id=$rid1") === 1, 'rules: turn back on');
[$c, $b2] = $lgPost('admin', ['action' => 'toggle_rule', 'rule_id' => '99999'], ['Accept: application/json']);
$j = json_decode($b2, true);
$ok($c === 404 && ($j['success'] ?? null) === false && str_contains((string) $j['error'], 'Rule not found'), 'rules: toggling a missing rule is Core\'s 404 (JSON)');
// CSRF
[$c] = web($wb, 'POST', '/agent/post/rmm_logs.php', $SS['admin'], ['csrf_token' => 'wrong', 'action' => 'delete_rule', 'rule_id' => (string) $rid1]);
[$c2] = web($wb, 'POST', '/agent/post/rmm_logs.php', $SS['admin'], ['action' => 'delete_rule', 'rule_id' => (string) $rid1]);
[$c3] = web($wb, 'POST', '/agent/post/rmm_logs.php', $SS['admin'], ['csrf_token' => 'wrong'] + $goodRule + ['name' => 'LR csrf']);
$ok($lgRule('LR disk renamed') !== null && $lgRule('LR csrf') === null, 'rules: a wrong or missing CSRF token changes nothing (' . $c . ', ' . $c2 . ', ' . $c3 . ')');
[$c] = web($wb, 'GET', '/agent/post/rmm_logs.php?action=delete_rule&rule_id=' . $rid1, $SS['admin']);
$ok($c === 405 && $lgRule('LR disk renamed') !== null, 'rules: the handler refuses GET (405) and a link cannot delete');
// who may not write
foreach (['viewer', 'rebootonly', 'remoteonly', 'moduleonly', 'normo'] as $who) {
    [$c, $b2] = $lgPost($who, ['name' => "LR by $who"] + $goodRule, ['Accept: application/json']);
    $j = json_decode($b2, true);
    $ok($lgRule("LR by $who") === null && ($j['success'] ?? null) === false, "rules: $who cannot create a rule (" . $c . ': ' . ($j['error'] ?? '') . ')');
    [$c, $b2] = $lgPost($who, ['action' => 'delete_rule', 'rule_id' => (string) $rid1], ['Accept: application/json']);
    $ok($lgRule('LR disk renamed') !== null, "rules: $who cannot delete a rule");
}
// technician: allowed (alert.manage), all-departments rule needs access to every department
[$c, , $hd] = $lgPost('tech', ['name' => 'LR by tech'] + $goodRule);
$ok($lgRule('LR by tech') !== null, 'rules: a technician (alert management) can create a rule');
// restricted user: own department only
[$c, $b2] = $lgPost('deptb', ['name' => 'LR deptb all'] + $goodRule, ['Accept: application/json']);
$j = json_decode($b2, true);
$ok($lgRule('LR deptb all') === null && str_contains((string) ($j['error'] ?? ''), 'all clients'), 'rules: a Dept B user cannot make a rule for all departments (' . ($j['error'] ?? '') . ')');
[$c, $b2] = $lgPost('deptb', ['name' => 'LR deptb A', 'client_id' => '1'] + $goodRule, ['Accept: application/json']);
$ok($lgRule('LR deptb A') === null, 'rules: a Dept B user cannot make a rule for Dept A');
[$c, , $hd] = $lgPost('deptb', ['name' => 'LR deptb B', 'client_id' => '2'] + $goodRule);
$rb = $lgRule('LR deptb B');
$ok($rb !== null && (int) $rb['client_id'] === 2, 'rules: a Dept B user can make a rule for Dept B');
[$c, $body] = $lgGet('deptb', '/agent/rmm_log_rules.php');
$ok(str_contains($body, 'LR deptb B') && !str_contains($body, 'LR disk renamed') && !str_contains($body, 'LR by tech') && !str_contains($body, '>All departments<'), 'rules: the Dept B user\'s list holds only Dept B rules, and the form offers no "all departments"');
[$c, $body] = $lgGet('deptb', '/agent/rmm_log_rules.php?rule_id=' . $rid1);
$ok(str_contains($body, 'does not exist, or it belongs to a department you cannot see') && !str_contains($body, 'value="LR disk renamed"'), 'rules: another department\'s rule is the same "not found" as a missing one');
[$c, $b2] = $lgPost('deptb', ['action' => 'delete_rule', 'rule_id' => (string) $rid1], ['Accept: application/json']);
[$c2, $b3] = $lgPost('deptb', ['action' => 'toggle_rule', 'rule_id' => (string) $rid1], ['Accept: application/json']);
[$c3, $b4] = $lgPost('deptb', ['rule_id' => (string) $rid1, 'name' => 'hijacked'] + $goodRule, ['Accept: application/json']);
$ok($lgRule('LR disk renamed') !== null && (int) $one("SELECT enabled FROM endpoint_agent_log_rules WHERE rule_id=$rid1") === 1 && $c === 404 && $c2 === 404 && $c3 === 404, 'rules: a Dept B user cannot delete, switch or edit a rule of another department (' . "$c $c2 $c3" . ')');
[$c, , $hd] = $lgPost('admin', ['action' => 'delete_rule', 'rule_id' => (string) $rb['rule_id']]);
$ok($lgRule('LR deptb B') === null, 'rules: delete removes the rule');
[$c, , $hd] = $lgPost('tech', ['action' => 'delete_rule', 'rule_id' => (string) $lgRule('LR by tech')['rule_id']]);
$ok($lgRule('LR by tech') === null, 'rules: a technician can delete a rule');
// a hostile name is escaped on both the list and the form
[$c, , $hd] = $lgPost('admin', ['name' => $hostile, 'contains' => $hostile] + $goodRule);
$rh = $rows("SELECT * FROM endpoint_agent_log_rules WHERE name LIKE '%<script>%'")[0] ?? null;
[$c, $body] = $lgGet('admin', '/agent/rmm_log_rules.php');
[$c2, $body2] = $lgGet('admin', '/agent/rmm_log_rules.php?rule_id=' . (int) ($rh['rule_id'] ?? 0));
$ok($rh !== null && !str_contains($body . $body2, '<script>alert(1)') && !str_contains($body . $body2, '<img src=x onerror') && str_contains($body, '&lt;script&gt;alert(1)&lt;/script&gt;') && str_contains($body2, 'value="&lt;script&gt;alert(1)'),
    'rules: a hostile name and phrase are escaped on the list and in the form');
if ($rh !== null) {
    $q('DELETE FROM endpoint_agent_log_rules WHERE rule_id=' . (int) $rh['rule_id']);
}

// ============================================================ log rule -> alert -> recovery
$ok($rmm->featureOn('alerting'), 'alerting is on for the alert part (set by the earlier fragments)');
$checks0 = $rmm->settings()->checks();
$q("INSERT INTO webhooks SET webhook_name='lg events', webhook_url='http://127.0.0.1:9/lg', webhook_secret='', webhook_events='rmm.*', webhook_enabled=1");
$evBefore = count(array_filter($lgEvents(), fn ($p) => ($p['event'] ?? '') === 'rmm.log.rule_matched'));
[$c, , $hd] = $lgPost('admin', ['name' => 'LR sshd', 'client_id' => '1', 'min_level' => 'warning', 'source_contains' => 'sshd', 'contains' => 'Failed login', 'threshold' => '3', 'window_min' => '10', 'severity' => 'fail', 'resolve_after_min' => '15'] + $goodRule);
$r2 = $lgRule('LR sshd');
$rid2 = (int) ($r2['rule_id'] ?? 0);
$ok($r2 !== null && (int) $r2['threshold'] === 3 && (int) $r2['window_min'] === 10 && (int) $r2['client_id'] === 1 && $r2['severity'] === 'fail', 'alert: rule "LR sshd" (3 matches in 10 minutes, Dept A, critical) created through the handler');
$ok((int) $one("SELECT enabled FROM endpoint_agent_log_rules WHERE rule_id=$rid1") === 1, 'alert: rule "LR disk renamed" is on (phrase with regex characters, 1 match)');
$key1 = 'log.rule_' . $rid1;
$key2 = 'log.rule_' . $rid2;
$alrOpen = fn (string $key) => $rows("SELECT * FROM rmm_alert_meta WHERE check_key='$key' AND state IN ('open','acknowledged')");
// the regex-looking phrase is matched literally: this line would match a regular expression but not the phrase
$lgPostLogs('LGWIN1', [$lgEv(5, 'error', 'kernel', null, 'disk s (error) abccc - looks like the pattern but is not the phrase')]);
$ok($alrOpen($key1) === [] && $one("SELECT COUNT(*) FROM endpoint_agent_checks WHERE check_key='$key1'") === '0', 'alert: a line that a regular expression would match, but the phrase does not, raises nothing');
// the literal phrase, on Dept B's device too (the rule covers all departments)
$lgPostLogs('LGWIN1', [$lgEv(4, 'error', 'kernel', null, 'prefix disk [sda] (error) a.c* suffix')]);
$a1 = $alrOpen($key1);
$ok(count($a1) === 1 && (int) $a1[0]['device_id'] === $lgDev['LGWIN1'], 'alert: the literal phrase raised exactly one alert on that device (' . count($a1) . ')');
$st1 = $rows("SELECT status, detail FROM endpoint_agent_checks WHERE check_key='$key1'");
$ok(count($st1) === 1 && $st1[0]['status'] === 'fail' && str_contains($st1[0]['detail'], 'LR disk renamed: 1 matching log entry; latest: prefix disk [sda]'), 'alert: the check log.rule_' . $rid1 . ' is reported with the rule\'s detail (' . json_encode($st1) . ')');
// threshold: two lines raise nothing, the third does
$lgPostLogs('LGWIN1', [$lgEv(3, 'warning', 'sshd', null, 'Failed login for root from 10.0.0.1'), $lgEv(2, 'warning', 'sshd', null, 'Failed login for admin from 10.0.0.2')]);
$ok($alrOpen($key2) === [], 'alert: two matching lines are below the count of 3 and raise nothing');
$lgPostLogs('LGLNX2', [$lgEv(3, 'warning', 'sshd', null, 'Failed login for guest from 10.0.0.9')]);
$ok($alrOpen($key2) === [], 'alert: the count is per device (a line on another device does not add up)');
$lgPostLogs('LGWIN1', [$lgEv(1, 'warning', 'sshd', null, 'Failed login for oracle from 10.0.0.3')]);
$a2 = $alrOpen($key2);
$ok(count($a2) === 1 && (int) $a2[0]['device_id'] === $lgDev['LGWIN1'] && $a2[0]['severity'] === 'crit', 'alert: the third matching line within the window raised a critical alert (' . json_encode(array_column($a2, 'severity')) . ')');
// events
$evs = array_values(array_filter($lgEvents(), fn ($p) => ($p['event'] ?? '') === 'rmm.log.rule_matched'));
$ok(count($evs) > $evBefore && in_array($rid2, array_map(fn ($p) => (int) ($p['data']['rule_id'] ?? $p['rule_id'] ?? 0), $evs), true), 'alert: rmm.log.rule_matched was queued for the rmm.* webhook (' . count($evs) . ' events)');
// pages
[$c, $body] = $lgGet('admin', '/agent/rmm_agent_alerts.php?check_key=' . rawurlencode($key2));
$ok($c === 200 && str_contains($body, 'LGWIN1') && str_contains($body, $key2) && !str_contains($body, $key1), 'alert: the Agent alerts page filtered by the check key shows it');
[$c, $body] = $lgGet('deptb', '/agent/rmm_agent_alerts.php?check_key=' . rawurlencode($key2));
$ok(!str_contains($body, 'LGWIN1'), 'alert: a Dept B user does not see Dept A\'s log alert');
[$c, $body] = $lgGet('admin', '/agent/rmm_log_rules.php');
$ok(str_contains($body, '1 device matching') && str_contains($body, '1 open alert<'), 'rules: the list shows 1 device matching and 1 open alert for a rule that fired');
[$c, $body] = $lgGet('deptb', '/agent/rmm_log_rules.php');
$ok(!str_contains($body, 'LR sshd') && !str_contains($body, 'LR disk renamed'), 'rules: the Dept B user sees neither rule');
// recovery after the quiet period (the clock moves by SQL on the check, as Core's own tests do), then housekeeping as cron runs it
$res = $rmm->housekeeping()->run();
$ok(count($alrOpen($key2)) === 1 && ($res['log_rule_alerts_recovered'] ?? 0) === 0, 'recovery: housekeeping before the quiet period closes nothing');
$q("UPDATE endpoint_agent_checks SET last_reported_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 16 MINUTE) WHERE check_key='$key2'");
$res = $rmm->housekeeping()->run();
$ok(($res['log_rule_alerts_recovered'] ?? 0) >= 1, 'recovery: housekeeping reports the quiet check OK after 16 minutes (rule resolves after 15) (' . json_encode($res['log_rule_alerts_recovered'] ?? null) . ')');
$rs = $rows("SELECT m.state FROM rmm_alert_meta m WHERE m.check_key='$key2'");
$ok($alrOpen($key2) === [] && $rs !== [] && $rs[0]['state'] === 'resolved', 'recovery: the alert is resolved (' . json_encode($rs) . ')');
$ok(count($alrOpen($key1)) === 1, 'recovery: the other rule\'s alert (quiet for less than 45 minutes) stays open');
// deleting a rule closes its alert at the next housekeeping run
[$c, , $hd] = $lgPost('admin', ['action' => 'delete_rule', 'rule_id' => (string) $rid1]);
$ok($lgRule('LR disk renamed') === null, 'recovery: the rule with an open alert is deleted');
$rmm->housekeeping()->run();
$ok($alrOpen($key1) === [], 'recovery: deleting the rule closed its alert at the next housekeeping run');
// disabling closes it too, and a disabled rule does not raise
[$c, , $hd] = $lgPost('admin', ['name' => 'LR switch', 'contains' => 'SWITCHME', 'min_level' => 'error'] + $goodRule);
$rid3 = (int) ($lgRule('LR switch')['rule_id'] ?? 0);
$lgPostLogs('LGWIN1', [$lgEv(1, 'error', 'app', null, 'SWITCHME now')]);
$ok(count($alrOpen('log.rule_' . $rid3)) === 1, 'alert: a third rule raised its alert');
[$c, , $hd] = $lgPost('admin', ['action' => 'toggle_rule', 'rule_id' => (string) $rid3]);
$rmm->housekeeping()->run();
$ok($alrOpen('log.rule_' . $rid3) === [], 'recovery: turning the rule off closed its alert at the next housekeeping run');
$lgPostLogs('LGWIN1', [$lgEv(1, 'error', 'app', null, 'SWITCHME again')]);
$ok($alrOpen('log.rule_' . $rid3) === [], 'alert: a rule that is off raises nothing');
$q('DELETE FROM endpoint_agent_log_rules');
$rmm->housekeeping()->run();

// ---- with the Alerting switch off the alert still opens, through the edition's own path, and the page says what is missing
$features = $rmm->settings()->features();
$features['alerting'] = false;
$rmm->admin()->saveSettings($admin, ['features_json' => $features]);
$fresh();
$ok(!$rmm->featureOn('alerting'), 'alerting switched off for the next part');
[$c, , $hd] = $lgPost('admin', ['name' => 'LR noalert', 'contains' => 'NOALERTSWITCH', 'min_level' => 'error'] + $goodRule);
$rid4 = (int) ($lgRule('LR noalert')['rule_id'] ?? 0);
$legacyBefore = (int) $one('SELECT COUNT(*) FROM rmm_alerts');
$lgPostLogs('LGWIN1', [$lgEv(1, 'error', 'app', null, 'NOALERTSWITCH happened')]);
$chk = $rows("SELECT status FROM endpoint_agent_checks WHERE check_key='log.rule_$rid4'");
$ok(count($chk) === 1 && $chk[0]['status'] === 'warn', 'alerting off: the rule still reports its check');
$ok((int) $one('SELECT COUNT(*) FROM rmm_alerts') === $legacyBefore + 1, 'alerting off: the alert opens through the edition\'s own alert table (RMM Alerts)');
[$c, $body] = $lgGet('admin', '/agent/rmm_log_rules.php');
$ok(str_contains($body, 'Alerting</strong> switch is off') && str_contains($body, 'maintenance windows, dependencies, storm control and escalation policies do not apply') && str_contains($body, 'href="/agent/rmm_alerts.php"') && !str_contains($body, 'rmm_agent_alerts.php?'),
    'alerting off: the page says what does not apply and links RMM Alerts instead of Agent alerts');
$features['alerting'] = true;
$rmm->admin()->saveSettings($admin, ['features_json' => $features]);
$fresh();
$q('DELETE FROM endpoint_agent_log_rules');
$rmm->housekeeping()->run();
$ok($rmm->featureOn('alerting'), 'alerting switched back on');

// ============================================================ switching it off again: nothing is left behind
$r = $rmm->remoteActions()->updateSettings($admin, ['features' => ['logs' => false]]);
$fresh();
$ok($r->ok && !$rmm->featureOn('logs') && $logNav($USER['admin']) === [], 'logs switched off again: the menu entries are gone');
foreach (['/agent/rmm_logs.php', '/agent/rmm_log_rules.php'] as $path) {
    [$c, $body] = $lgGet('admin', $path);
    $ok($c === 403 && str_contains($body, 'switched off') && !str_contains($body, 'DEPTA-SECRET-LINE'), "$path: switched off again (refused with the notice)");
}
[$c, $body] = $lgGet('admin', '/agent/asset_details.php?asset_id=' . $lgAsset['LGWIN1']);
$ok($c === 200 && str_contains($body, 'id="rmm-strip"') && !str_contains($body, 'rmm-tab-logs') && !str_contains($body, 'rmm-pane-logs') && !str_contains($body, 'BULK-') && !str_contains($body, 'rmm_logs.js'), 'asset page: the Logs tab, its pane and its script are gone with the switch off');
[$c, $b2] = $lgPost('admin', ['name' => 'LR late'] + $goodRule, ['Accept: application/json']);
$ok($lgRule('LR late') === null && $c === 404, 'handler: refused with the switch off again');
[$c, , $j] = $lgPostLogs('LGWIN1', [$lgEv(1, 'info', 'x', null, 'late')]);
$ok($c === 503 && ($j['code'] ?? '') === 'feature_disabled', 'the device endpoint answers 503 feature_disabled with the switch off (' . $c . ')');
$ok((int) $one("SELECT COUNT(*) FROM endpoint_agent_log_events WHERE message='late'") === 0 && (int) $one('SELECT COUNT(*) FROM endpoint_agent_log_events') > 100, 'the lines already stored are kept (switching off deletes nothing; retention prunes them)');

// ---- the module itself off: every log entry point refuses without the switch mattering
$rmm->remoteActions()->updateSettings($admin, ['features' => ['logs' => true], 'accept_logs_storage' => true]);
$fresh();
$rmm->admin()->disable($admin); $fresh();
$a = $lgQ();
$nav = $logNav($USER['admin']);
$b = $lgQ();
$ok($nav === [] && $b - $a === $lgOver, 'module off: no menu entries and the check costs no statement (' . ($b - $a) . ' vs ' . $lgOver . ')');
foreach (['/agent/rmm_logs.php', '/agent/rmm_log_rules.php'] as $path) {
    [$c, $body] = $lgGet('admin', $path);
    $ok($c === 403 && str_contains($body, 'RMM module') && !str_contains($body, 'DEPTA-SECRET-LINE'), "$path: module off (refused with the notice)");
}
[$c, $b2] = $lgPost('admin', ['name' => 'LR module off'] + $goodRule, ['Accept: application/json']);
$ok($lgRule('LR module off') === null && in_array($c, [403, 404], true), 'handler: refused with the module off (' . $c . ')');
$rmm->admin()->enable($admin); $fresh();
$rmm->remoteActions()->updateSettings($admin, ['features' => ['logs' => false]]);
$fresh();

// ---- put everything back
$rmm->remoteActions()->updateSettings($admin, ['logs_collect' => ['linux' => [], 'windows' => []], 'limits' => ['logs_retention_days' => $lgOrigLimits['logs_retention_days']]]);
$q('DELETE FROM endpoint_agent_log_rules');
$q("UPDATE webhooks SET webhook_enabled=0");
$fresh();
$ok(!$rmm->featureOn('logs') && $rmm->enabled(), 'logs: the switch is off and the module is on again at the end of the fragment');
