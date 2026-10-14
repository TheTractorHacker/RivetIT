<?php
/*
 * Fragment of tests/rmm_ui.php (RMM Phase 3 UI, group C): agent alerts, maintenance windows, escalation policies and the Alerting tab of the asset page.
 * Shares that file's variables. Everything is driven through the product: devices enroll and check in through the real endpoints, windows / policies / acks go
 * through the real handler (agent/post/rmm_automation_alr.php, CSRF) over HTTP, the escalation clock runs through the module's housekeeping (what cron does).
 */

use RivetCore\Rmm\Authz\RmmPrincipal;

$alrOrigChecks = $rmm->settings()->checks();
$alrOrigSettings = $rmm->settings()->get(true);

$alrGet = fn (string $who, string $path) => web($wb, 'GET', $path, $SS[$who]);
$alrPost = fn (string $who, array $f, array $headers = []) => web($wb, 'POST', '/agent/post/rmm_automation_alr.php', $SS[$who], $f + ['csrf_token' => 'csrftok1'], $headers);
$alrJson = fn (string $who, array $f) => web($wb, 'POST', '/agent/post/rmm_automation_alr.php', $SS[$who], $f + ['csrf_token' => 'csrftok1'], ['Accept: application/json']);
$alrLoc = fn (string $hdr) => preg_match('/^location:\s*(\S+)/mi', $hdr, $mm) === 1 ? $mm[1] : '';
$alrRows = fn (string $sql) => $rows($sql);

// ---- devices of this fragment (own assets so the Phase 1 seed is left alone)
$alrDev = [];
$alrTok = [];
$alrAsset = [];
foreach (['ALR1' => [1, 'A'], 'ALR2' => [1, 'A'], 'ALR3' => [1, 'A'], 'ALRB' => [2, 'B']] as $n => [$client, $tk]) {
    $q("INSERT INTO assets SET asset_type='Laptop', asset_name='$n', asset_make='Dell', asset_serial='SER-$n', asset_client_id=$client, asset_status='Active'");
    $alrAsset[$n] = (int) $db->insert_id;
    [$c, , $j] = ea_enroll($S['enroll'][$tk], ea_dev(['serial' => "SER-$n", 'hostname' => $n]));
    $alrDev[$n] = (int) ($j['device_id'] ?? 0);
    $alrTok[$n] = (string) ($j['device_token'] ?? '');
    $ok($c === 201 && $alrDev[$n] > 0, "$n enrolled through the real endpoint");
}
// a value-reporting check with thresholds, delivered through the global checks, plus one without thresholds
$checks = $alrOrigChecks;
$checks[] = ['key' => 'alr_cpu', 'type' => 'cpu', 'interval_s' => 60, 'params' => ['thresholds' => ['warn' => ['op' => 'gt', 'value' => 80], 'crit' => ['op' => 'gt', 'value' => 95], 'for_samples' => 1]]];
$checks[] = ['key' => 'alr_plain', 'type' => 'cpu', 'interval_s' => 60, 'params' => new stdClass()];
$ok($rmm->settings()->update(['checks_json' => json_encode($checks), 'failure_debounce' => 2, 'recovery_debounce' => 2]) === [], 'checks with thresholds saved through the settings');
$fresh();

// ---- policy and window first: the check-in server caches both for a short while, so they must exist before the first alert opens
$aU = $USER;
$hostile = '<script>alert(1)</script>';
[$c, , $hd] = $alrPost('admin', ['action' => 'save_escalation', 'name' => 'ALR crit escalation', 'min_severity' => 'crit', 'p_type' => 'device', 'p_id_device' => $alrDev['ALR3'], 'repeat_every_min' => 10, 'repeat_max' => 2, 'enabled' => '1',
    'steps' => [0 => ['after_min' => '0', 'targets' => [0 => ['type' => 'user', 'ref_user' => '10'], 1 => ['type' => 'email', 'ref_email' => 'alr-noc@example.test']]],
        1 => ['after_min' => '30', 'targets' => [0 => ['type' => 'user', 'ref_user' => '1']]]]]);
$ok($c === 302 && str_contains($alrLoc($hd), '/agent/rmm_escalations.php'), 'save_escalation (admin, CSRF): redirects back to the page (' . $c . ')');
$pol = $alrRows("SELECT * FROM rmm_escalation_policies WHERE name='ALR crit escalation'");
$polId = (int) ($pol[0]['policy_id'] ?? 0);
$ok(count($pol) === 1 && $pol[0]['scope_type'] === 'device' && (int) $pol[0]['scope_id'] === $alrDev['ALR3'] && $pol[0]['min_severity'] === 'crit' && (int) $pol[0]['repeat_every_min'] === 10 && (int) $pol[0]['repeat_max'] === 2, 'the policy is in the Core tables with its scope, severity and repeat');
$st = $alrRows("SELECT after_min, targets_json FROM rmm_escalation_steps WHERE policy_id=$polId ORDER BY step_no");
$ok(count($st) === 2 && (int) $st[0]['after_min'] === 0 && (int) $st[1]['after_min'] === 30 && json_decode($st[0]['targets_json'], true) === [['type' => 'user', 'ref' => '10'], ['type' => 'email', 'ref' => 'alr-noc@example.test']]
    && json_decode($st[1]['targets_json'], true) === [['type' => 'user', 'ref' => '1']], 'two steps with typed targets (user id as a string ref, email) written');
// bad CSRF changes nothing
[$c] = web($wb, 'POST', '/agent/post/rmm_automation_alr.php', $SS['admin'], ['csrf_token' => 'wrong', 'action' => 'save_escalation', 'name' => 'ALR nope', 'p_type' => 'all', 'steps' => [0 => ['after_min' => '0', 'targets' => [0 => ['type' => 'user', 'ref_user' => '1']]]]]);
[$c2] = web($wb, 'POST', '/agent/post/rmm_automation_alr.php', $SS['admin'], ['action' => 'save_escalation', 'name' => 'ALR nope', 'p_type' => 'all', 'steps' => [0 => ['after_min' => '0', 'targets' => [0 => ['type' => 'user', 'ref_user' => '1']]]]]);
$ok($one("SELECT COUNT(*) FROM rmm_escalation_policies WHERE name='ALR nope'") === '0', 'a wrong or missing CSRF token changes nothing (' . $c . ', ' . $c2 . ')');
// validation errors reach the user: steps out of order, chat target not offered
[$c, , $hd] = $alrPost('admin', ['action' => 'save_escalation', 'name' => 'ALR bad', 'p_type' => 'all', 'steps' => [0 => ['after_min' => '30', 'targets' => [0 => ['type' => 'user', 'ref_user' => '1']]], 1 => ['after_min' => '5', 'targets' => [0 => ['type' => 'user', 'ref_user' => '1']]]]]);
$ok($one("SELECT COUNT(*) FROM rmm_escalation_policies WHERE name='ALR bad'") === '0' && str_contains($alrLoc($hd), 'new=1'), 'a step earlier than the one before is refused by Core and the user is sent back to the form');
[$c, $body] = $alrGet('admin', '/agent/rmm_escalations.php?new=1');
$ok($c === 200 && str_contains($body, 'earlier than the step before'), 'the refusal of Core is shown to the user on the form page');
[$c, , $hd] = $alrPost('tech', ['action' => 'save_escalation', 'name' => 'ALR tech', 'p_type' => 'all', 'steps' => [0 => ['after_min' => '0', 'targets' => [0 => ['type' => 'user', 'ref_user' => '1']]]]]);
$ok($one("SELECT COUNT(*) FROM rmm_escalation_policies WHERE name='ALR tech'") === '0', 'a technician (not an administrator) cannot create an escalation policy: Core refuses');
// a disabled policy with a hostile name, to prove escaping
[$c] = $alrPost('admin', ['action' => 'save_escalation', 'name' => $hostile, 'p_type' => 'all', 'min_severity' => 'warn', 'repeat_every_min' => 0, 'repeat_max' => 0,
    'steps' => [0 => ['after_min' => '5', 'targets' => [0 => ['type' => 'group', 'ref_group' => '1']]]]]);
$hostilePol = (int) $one("SELECT policy_id FROM rmm_escalation_policies WHERE name='" . $esc($hostile) . "'");
$ok($hostilePol > 0 && $one("SELECT enabled FROM rmm_escalation_policies WHERE policy_id=$hostilePol") === '0', 'a policy with a hostile name saved (switch left off so it never fires), role target');

// a mute window on ALR2 (device scope), created in Chicago time, one hour ago to two hours ahead
$tzChi = new DateTimeZone('America/Chicago');
$loc = fn (int $off) => (new DateTimeImmutable('@' . (time() + $off)))->setTimezone($tzChi)->format('Y-m-d\TH:i');
[$c, , $hd] = $alrPost('admin', ['action' => 'save_window', 'name' => 'ALR mute ALR2', 'mode' => 'mute', 'w_type' => 'device', 'w_id_device' => $alrDev['ALR2'], 'kind' => 'once', 'timezone' => 'America/Chicago',
    'starts_local' => $loc(-3600), 'ends_local' => $loc(7200), 'note' => 'planned', 'enabled' => '1']);
$win = $alrRows("SELECT * FROM rmm_maintenance_windows WHERE name='ALR mute ALR2'");
$winId = (int) ($win[0]['window_id'] ?? 0);
$expStart = gmdate('Y-m-d H:i:s', strtotime((new DateTimeImmutable($loc(-3600) . ':00', $tzChi))->format('c')));
$ok($c === 302 && count($win) === 1 && $win[0]['mode'] === 'mute' && $win[0]['scope_type'] === 'device' && (int) $win[0]['scope_id'] === $alrDev['ALR2'] && $win[0]['timezone'] === 'America/Chicago', 'save_window: one-time mute window for the device written');
$ok(($win[0]['starts_at'] ?? '') === $expStart, 'the local start in the chosen zone was converted to UTC for Core (' . ($win[0]['starts_at'] ?? '?') . ' = ' . $expStart . ')');
// windows over all devices need an administrator; a technician may do a device window only
[$c, , $hd] = $alrPost('tech', ['action' => 'save_window', 'name' => 'ALR tech all', 'mode' => 'mute', 'w_type' => 'all', 'kind' => 'once', 'timezone' => 'UTC', 'starts_local' => $loc(0), 'ends_local' => $loc(3600)]);
$ok($one("SELECT COUNT(*) FROM rmm_maintenance_windows WHERE name='ALR tech all'") === '0', 'a technician cannot make a window for all devices (Core: administrators only)');
[$c, , $hd] = $alrPost('tech', ['action' => 'save_window', 'name' => 'ALR tech dev', 'mode' => 'suppress', 'w_type' => 'device', 'w_id_device' => $alrDev['ALR1'], 'kind' => 'once', 'timezone' => 'UTC', 'starts_local' => gmdate('Y-m-d\TH:i', time() + 86400 * 2), 'ends_local' => gmdate('Y-m-d\TH:i', time() + 86400 * 2 + 3600)]);
$techWin = (int) $one("SELECT window_id FROM rmm_maintenance_windows WHERE name='ALR tech dev'");
$ok($techWin > 0, 'a technician can make a window for one device');
[$c, , $hd] = $alrPost('admin', ['action' => 'save_window', 'name' => 'ALR bad window', 'mode' => 'mute', 'w_type' => 'all', 'kind' => 'once', 'timezone' => 'UTC', 'starts_local' => $loc(3600), 'ends_local' => $loc(0)]);
$ok($one("SELECT COUNT(*) FROM rmm_maintenance_windows WHERE name='ALR bad window'") === '0' && str_contains($alrLoc($hd), 'new=1'), 'a window that ends before it starts is refused and the user is sent back');
// a repeating window: weekly Saturday 22:00, 4 h, Chicago, on the whole department; hostile name
[$c] = $alrPost('admin', ['action' => 'save_window', 'name' => $hostile, 'mode' => 'suppress', 'w_type' => 'client', 'w_id_client' => 1, 'kind' => 'recurring', 'timezone' => 'America/Chicago', 'recur_freq' => 'weekly', 'recur_interval' => '1',
    'recur_days_w' => ['6'], 'local_start' => '22:00', 'duration_min' => '240', 'recur_from' => '', 'recur_until' => '', 'enabled' => '1']);
$recId = (int) $one("SELECT window_id FROM rmm_maintenance_windows WHERE name='" . $esc($hostile) . "'");
$rec = $alrRows("SELECT * FROM rmm_maintenance_windows WHERE window_id=$recId")[0] ?? [];
$ok($recId > 0 && $rec['recur_freq'] === 'weekly' && $rec['recur_days'] === '6' && $rec['local_start'] === '22:00' && (int) $rec['duration_min'] === 240 && $rec['scope_type'] === 'client', 'a repeating weekly window (Saturday 22:00, 4 h, Chicago) for a department written');
$ok(rivetRmmAlrScheduleWords(['kind' => 'recurring', 'recur_freq' => 'weekly', 'recur_interval' => 1, 'recur_days' => '6', 'local_start' => '22:00', 'duration_min' => 240, 'timezone' => 'America/Chicago']) === 'Every Saturday 22:00, 4 h, America/Chicago', 'schedule in words (weekly)');
$ok(rivetRmmAlrScheduleWords(['kind' => 'once', 'starts_at' => '2026-10-12T22:00:00Z', 'ends_at' => '2026-10-13T02:00:00Z']) === 'Once, 2026-10-12 22:00 to 2026-10-13 02:00 UTC', 'schedule in words (once)');
$ok(rivetRmmAlrScheduleWords(['kind' => 'recurring', 'recur_freq' => 'monthly', 'recur_interval' => 1, 'recur_days' => '1,15,last', 'local_start' => '03:30', 'duration_min' => 90, 'timezone' => 'UTC']) === 'On day 1, day 15, the last day of every month 03:30, 1 h 30 min, UTC', 'schedule in words (monthly)');

// ---- value-reporting check opens an alert at the crit tier through the real check-in endpoint
$alrBad = function (string $dev, float $value, int $n = 3, string $key = 'alr_cpu') use ($alrTok) {
    for ($i = 0; $i < $n; $i++) {
        ea_checkin($alrTok[$dev], ['checks' => [['key' => $key, 'status' => 'ok', 'detail' => "load $value", 'value' => $value]]]);
    }
};
$alrBad('ALR1', 99.0);
$a1 = $alrRows("SELECT * FROM rmm_alert_meta WHERE device_id={$alrDev['ALR1']} AND check_key='alr_cpu'");
$alert1 = (int) ($a1[0]['alert_id'] ?? 0);
$ok(count($a1) === 1 && $a1[0]['severity'] === 'crit' && $a1[0]['state'] === 'open', 'a value of 99 against crit > 95 opened a critical alert through the real check-in (' . json_encode(array_column($a1, 'severity')) . ')');
$ev = $rmm->alerts()->find($alert1);
$ok($ev !== null && $ev['state'] === 'open', 'Core has the alert');
// a warning-tier alert on ALRB (Dept B)
$alrBad('ALRB', 85.0);
$aB = $alrRows("SELECT * FROM rmm_alert_meta WHERE device_id={$alrDev['ALRB']}");
$alertB = (int) ($aB[0]['alert_id'] ?? 0);
$ok(count($aB) === 1 && $aB[0]['severity'] === 'warn', 'a value of 85 opened a warning alert on the Dept B device');
// the muted device holds its alert back
$alrBad('ALR2', 99.0);
$ok((int) $one("SELECT COUNT(*) FROM rmm_alert_meta WHERE device_id={$alrDev['ALR2']}") === 0, 'a bad check on a device inside an open mute window opens no alert');
$rmm->maintenance()->refresh();   // the window was written by the web server: drop this process's cached list
$al2 = rivetRmmAutoApi($db, 1, 'admin', 'GET', [$alrDev['ALR2'], 'alerting'])['data'];
$held = array_column($al2['checks'], 'held_by', 'check_key');
$ok(($held['alr_cpu'] ?? '') === 'maintenance' && $al2['muted'] === true, 'the device alerting state says why: held back by maintenance');
// the escalating device opens its critical alert (policy is for ALR3)
$alrBad('ALR3', 99.0);
$a3 = $alrRows("SELECT * FROM rmm_alert_meta WHERE device_id={$alrDev['ALR3']} AND check_key='alr_cpu'");
$alert3 = (int) ($a3[0]['alert_id'] ?? 0);
$ok(count($a3) === 1 && (int) $a3[0]['policy_id'] === $polId && $a3[0]['esc_next_at'] !== null, 'the critical alert took the escalation policy and has its first notice scheduled');

ea_checkin($alrTok['ALR1'], ['checks' => [['key' => 'alr_plain', 'status' => 'ok', 'detail' => 'fine', 'value' => 12.0]]]);

// ============================================================ pages render
foreach (['/agent/rmm_agent_alerts.php', '/agent/rmm_maintenance.php', '/agent/rmm_escalations.php'] as $p) {
    foreach (['admin', 'tech', 'viewer', 'rebootonly', 'moduleonly', 'deptb'] as $who) {
        [$c, $body] = $alrGet($who, $p);
        $ok($c === 200 && !str_contains($body, 'Fatal error') && !str_contains($body, 'Warning:') && !str_contains($body, 'Deprecated:'), "$p renders for $who ($c)");
    }
}
[$c] = $alrGet('normo', '/agent/rmm_agent_alerts.php');
$ok($c === 403, 'agent alerts: a user without an RMM role is denied');

[$c, $body] = $alrGet('admin', '/agent/rmm_agent_alerts.php');
$ok(str_contains($body, 'ALR1') && str_contains($body, 'alr_cpu') && str_contains($body, 'Critical') && str_contains($body, 'Warning') && str_contains($body, 'Open critical') && str_contains($body, 'Flapping')
    && str_contains($body, '#rmm-alerting') && str_contains($body, '<caption class="visually-hidden">') && str_contains($body, 'scope="col"') && str_contains($body, '/agent/rmm_alerts.php'), 'agent alerts (admin): rows, KPI cards, device link to #rmm-alerting, accessible table, link to the older page');
$ok(str_contains($body, 'value="ack_alert"') && str_contains($body, 'value="resolve_alert"') && str_contains($body, 'data-rmm-confirm="Resolve this alert by hand? This closes the alert even though the check may still be failing.'), 'agent alerts (admin): Acknowledge and Resolve (with the confirm text)');
$ok(str_contains($body, 'ALRB') && str_contains($body, 'ALR1'), 'agent alerts (admin): sees alerts of both departments');
[$c, $body] = $alrGet('deptb', '/agent/rmm_agent_alerts.php');
$ok(str_contains($body, 'ALRB') && !str_contains($body, 'ALR1') && !str_contains($body, 'ALR3'), 'agent alerts (Dept B user): only Dept B alerts');
[$c, $body] = $alrGet('deptb', '/agent/rmm_agent_alerts.php?alert_id=' . $alert1);
$ok(str_contains($body, 'does not exist, or it belongs to a department you cannot see') && !str_contains($body, 'ALR1'), 'agent alerts (Dept B user): an alert of Dept A is the same "not found" as a missing one');
[$c, $body] = $alrGet('viewer', '/agent/rmm_agent_alerts.php');
$ok(str_contains($body, 'ALR1') && !str_contains($body, 'value="ack_alert"') && !str_contains($body, 'value="resolve_alert"') && !str_contains($body, 'data-alr-ticket'), 'agent alerts (viewer): no Acknowledge / Resolve / Create ticket');
[$c, $body] = $alrGet('moduleonly', '/agent/rmm_agent_alerts.php');
$ok($c === 200 && !str_contains($body, 'value="ack_alert"'), 'agent alerts (module-only login): view only');
[$c, $body] = $alrGet('tech', '/agent/rmm_agent_alerts.php');
$ok(str_contains($body, 'value="ack_alert"') && str_contains($body, 'value="resolve_alert"') && !str_contains($body, 'data-alr-ticket'), 'agent alerts (technician without the support module): can acknowledge and resolve, no Create ticket');
[$c, $body] = $alrGet('admin', '/agent/rmm_agent_alerts.php');
$ok(str_contains($body, 'data-alr-ticket="' . $alert1 . '"') && str_contains($body, 'js/rmm_alr.js'), 'agent alerts (administrator, support module): Create ticket posts to the existing handler through rmm_alr.js');
[$c, $body] = $alrGet('admin', '/agent/rmm_agent_alerts.php?severity=warn&state=open');
$alrHosts = fn (string $b) => array_values(array_unique((array) (preg_match_all('/#rmm-alerting">([^<]*)</', $b, $mm) ? $mm[1] : [])));
$ok(in_array('ALRB', $alrHosts($body), true) && !in_array('ALR1', $alrHosts($body), true), 'filters: severity warning + state open shows the warning alert and not the critical one (' . json_encode($alrHosts($body)) . ')');
[$c, $body] = $alrGet('admin', '/agent/rmm_agent_alerts.php?view=device');
$ok(str_contains($body, 'Alerts of ALR1') && str_contains($body, 'Alerts of ALRB'), 'grouped by device: one table per device');
[$c, $body] = $alrGet('admin', '/agent/rmm_agent_alerts.php?view=check');
$ok(str_contains($body, 'alr_cpu') && str_contains($body, 'Worst severity') && str_contains($body, 'Show the alerts'), 'grouped by check: counts per check and department');
[$c, $body] = $alrGet('admin', '/agent/rmm_agent_alerts.php?device_id=' . $alrDev['ALR1'] . '&check_key=' . urlencode('<script>') . '&severity=zzz&state=zzz&page=-4');
$ok($c === 200 && !str_contains($body, '<script>alert'), 'bad filter values are ignored');
[$c, $body] = $alrGet('admin', '/agent/rmm_agent_alerts.php?alert_id=' . $alert3);
$ok(str_contains($body, 'ALR crit escalation') && str_contains($body, 'After 0 min: tech, alr-noc@example.test; after 30 min: admin') && str_contains($body, 'The check now') && str_contains($body, 'Sent so far'), 'alert detail: the escalation policy in words and what was sent');
$ok(str_contains($body, 'Critical level'), 'alert detail: the check\'s current threshold tier');

// ---- escalation page
[$c, $body] = $alrGet('admin', '/agent/rmm_escalations.php');
$ok(str_contains($body, 'ALR crit escalation') && str_contains($body, 'After 0 min: tech, alr-noc@example.test; after 30 min: admin') && str_contains($body, 'Every 10 min, at most 2 times') && str_contains($body, 'Storm control') && str_contains($body, 'Fallback contact')
    && str_contains($body, 'rmm_escalations.php?new=1') && str_contains($body, 'value="delete_escalation"'), 'escalations (admin): table with steps in words, repeat, storm control, fallback contact, create and delete controls');
$ok(!str_contains($body, '<script>alert(1)</script>') && str_contains($body, '&lt;script&gt;alert(1)&lt;/script&gt;'), 'escalations: hostile policy name is escaped');
$ok(str_contains($body, 'role Service Desk') === false && preg_match('/role (Admin|#1)/', $body) === 1, 'escalations: a role target is named in words');
[$c, $body] = $alrGet('tech', '/agent/rmm_escalations.php');
$ok(str_contains($body, 'ALR crit escalation') && !str_contains($body, 'value="delete_escalation"') && !str_contains($body, 'rmm_escalations.php?new=1') && str_contains($body, 'Storm control') && str_contains($body, 'Only administrators can create'), 'escalations (technician): read only, storm control visible');
[$c, $body] = $alrGet('admin', '/agent/rmm_escalations.php?policy_id=' . $polId);
foreach (['name="steps[0][after_min]"', 'name="steps[1][after_min]"', 'name="steps[0][targets][1][ref_email]"', 'data-rmm-add="#alr-steps"', 'data-alr-add-target', 'name="repeat_every_min"', 'name="min_severity"', 'name="p_type"', 'rmm.alert.escalated'] as $needle) {
    $ok(str_contains($body, $needle), "policy form (edit) contains $needle");
}
$ok(!str_contains($body, 'value="chat"') && !str_contains($body, 'value="webhook"'), 'policy form: no chat or webhook target types');
[$c, $body] = $alrGet('tech', '/agent/rmm_escalations.php?new=1');
$ok(!str_contains($body, 'name="steps[0][after_min]"'), 'policy form is not drawn for a technician');

// ---- maintenance page
[$c, $body] = $alrGet('admin', '/agent/rmm_maintenance.php');
$ok(str_contains($body, 'Maintenance is open now') && str_contains($body, 'ALR mute ALR2') && str_contains($body, 'Every Saturday 22:00, 4 h, America/Chicago') && str_contains($body, 'rmm_maintenance.php?new=1') && str_contains($body, 'value="delete_window"')
    && str_contains($body, 'Department Dept A') && str_contains($body, 'Device ALR2'), 'maintenance (admin): active-now banner, schedules in words, scopes in words, create and delete controls');
$ok(str_contains($body, '&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($body, '<script>alert(1)</script>'), 'maintenance: hostile window name escaped');
[$c, $body] = $alrGet('admin', '/agent/rmm_maintenance.php?window_id=' . $winId);
foreach (['name="starts_local"', 'name="ends_local"', 'name="timezone"', 'name="recur_freq"', 'name="recur_days_w[]"', 'name="recur_days_m"', 'name="w_type"', 'name="mode" value="mute"', 'name="mode" value="suppress"', 'A day a month does not have uses the last day', 'America/Chicago'] as $needle) {
    $ok(str_contains($body, $needle), "window form (edit) contains $needle");
}
$ok(preg_match('/name="starts_local"[^>]*value="' . preg_quote($loc(-3600), '/') . '"/', $body) === 1, 'window form: the one-time start is shown back in the window\'s own time zone');
[$c, $body] = $alrGet('tech', '/agent/rmm_maintenance.php?new=1');
$ok(preg_match('/<option value="all" disabled>/', $body) === 1 && preg_match('/<option value="site" disabled>/', $body) === 1 && preg_match('/<option value="client"( selected)?>/', $body) === 1 && preg_match('/<option value="device"( selected)?>/', $body) === 1
    && str_contains($body, 'Only administrators can make a window for all devices'), 'window form (technician): all / site / group / tag are drawn disabled with the reason; department and device are allowed');
[$c, $body] = $alrGet('viewer', '/agent/rmm_maintenance.php?new=1');
$ok(!str_contains($body, 'name="starts_local"') && !str_contains($body, 'rmm_maintenance.php?new=1"'), 'maintenance (viewer): no form and no New button');
[$c, $body] = $alrGet('deptb', '/agent/rmm_maintenance.php');
$ok(!str_contains($body, 'ALR mute ALR2') && !str_contains($body, 'Device ALR2'), 'maintenance (Dept B user): a window on a Dept A device is invisible');
[$c, $body] = $alrGet('deptb', '/agent/rmm_maintenance.php?window_id=' . $winId);
$ok(!str_contains($body, 'name="starts_local"') && str_contains($body, 'does not exist'), 'maintenance (Dept B user): editing a Dept A window is "not found"');
[$c, , $hd] = $alrPost('deptb', ['action' => 'delete_window', 'window_id' => $winId]);
$ok($one("SELECT COUNT(*) FROM rmm_maintenance_windows WHERE window_id=$winId") === '1', 'a Dept B user cannot delete a Dept A window (Core: not found)');
[$c, $body] = $alrGet('admin', '/agent/rmm_maintenance.php?window_id=' . $recId);
$ok(str_contains($body, 'id="w-day-6" name="recur_days_w[]" value="6" checked'), 'window form: the weekly day checkboxes show the saved weekday');

// ============================================================ acknowledge and resolve through the handler (Core audit + event)
[$c, , $hd] = $alrPost('viewer', ['action' => 'ack_alert', 'alert_id' => $alert1, 'return' => '/agent/rmm_agent_alerts.php']);
$ok($rmm->alerts()->find($alert1)['state'] === 'open', 'a viewer cannot acknowledge (Core: forbidden)');
[$c, , $hd] = $alrPost('deptb', ['action' => 'ack_alert', 'alert_id' => $alert1, 'return' => '/agent/rmm_agent_alerts.php']);
$ok($rmm->alerts()->find($alert1)['state'] === 'open', 'a Dept B user cannot acknowledge a Dept A alert (Core: not found)');
$evCount = (int) $one("SELECT COUNT(*) FROM rmm_alert_meta WHERE alert_id=$alert1 AND state='acknowledged'");
[$c, , $hd] = $alrPost('tech', ['action' => 'ack_alert', 'alert_id' => $alert1, 'return' => '/agent/rmm_agent_alerts.php?alert_id=' . $alert1]);
$m1 = $alrRows("SELECT * FROM rmm_alert_meta WHERE alert_id=$alert1")[0];
$ok($c === 302 && str_contains($alrLoc($hd), 'alert_id=' . $alert1) && $m1['state'] === 'acknowledged' && (int) $m1['acked_by'] === 10 && $m1['esc_next_at'] === null, 'ack_alert (technician): the Core alert is acknowledged by the user, escalation clock cleared');
$ok((int) $one("SELECT COUNT(*) FROM audit_log WHERE log_description LIKE '%acknowledged alert $alert1%'") >= 1 || (int) $one("SELECT COUNT(*) FROM logs WHERE log_description LIKE '%acknowledged alert $alert1%'") >= 1, 'the acknowledgement is in the audit log');
[$c, $body] = $alrGet('admin', '/agent/rmm_agent_alerts.php?alert_id=' . $alert1);
$ok(str_contains($body, 'Acknowledged') && str_contains($body, 'by tech') && !str_contains($body, 'value="ack_alert"') && str_contains($body, 'value="resolve_alert"'), 'alert detail: acknowledged by tech; only Resolve is offered');
[$c, $b] = $alrJson('admin', ['action' => 'resolve_alert', 'alert_id' => $alert1]);
$j = json_decode($b, true);
$m1 = $alrRows("SELECT * FROM rmm_alert_meta WHERE alert_id=$alert1")[0];
$ok($c === 200 && ($j['success'] ?? false) === true && $m1['state'] === 'resolved' && (int) $m1['resolved_by'] === 1 && $m1['resolve_reason'] === 'manual', 'resolve_alert (JSON): the alert is resolved by the user');
$ok(in_array('Alert Resolved', array_column($alrRows("SELECT log_action FROM logs WHERE log_description LIKE '%resolved alert $alert1%'"), 'log_action'), true) || (int) $one("SELECT COUNT(*) FROM logs WHERE log_description LIKE '%alert $alert1%'") >= 1, 'the resolution is in the audit log');
[$c, $b] = $alrJson('admin', ['action' => 'resolve_alert']);
$ok($c === 422 && ($j = json_decode($b, true)) && $j['success'] === false, 'resolve_alert without an id is a validation error (422 JSON)');
[$c, $b] = $alrJson('admin', ['action' => 'nonsense']);
$ok($c === 422, 'an unknown action is refused');

// ============================================================ the escalation clock really notifies
$q("UPDATE settings SET config_mail_from_email='noreply@example.test', config_mail_from_name='RivetIT test' WHERE company_id=1");
$GLOBALS['mysqli'] = $db;
$notifBefore = (int) $one("SELECT COUNT(*) FROM notifications WHERE notification_user_id=10");
$mailBefore = (int) $one("SELECT COUNT(*) FROM email_queue WHERE email_recipient='alr-noc@example.test'");
$q("UPDATE rmm_alert_meta SET esc_next_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE alert_id=$alert3");
$res = rivetRmmModule($db)->housekeeping()->run();
$m3 = $alrRows("SELECT * FROM rmm_alert_meta WHERE alert_id=$alert3")[0];
$ok(($res['escalations_sent'] ?? 0) >= 1 && (int) $m3['esc_count'] === 1 && $m3['last_notified_at'] !== null, 'housekeeping fired step 1 (sent ' . ($res['escalations_sent'] ?? '?') . ', failed ' . ($res['escalations_failed'] ?? '?') . ')');
$ok((int) $one("SELECT COUNT(*) FROM notifications WHERE notification_user_id=10") === $notifBefore + 1, 'step 1: an in-app notification row exists for the target user');
$ok((int) $one("SELECT COUNT(*) FROM email_queue WHERE email_recipient='alr-noc@example.test'") === $mailBefore + 1, 'step 1: a row in the mail queue for the email target');
$ok((int) $one("SELECT COUNT(*) FROM tickets WHERE ticket_source='RMM Escalation'") >= 1, 'step 1: a critical alert opened a ticket (ticket_source RMM Escalation)');
[$c, $body] = $alrGet('admin', '/agent/rmm_agent_alerts.php?alert_id=' . $alert3);
$ok(preg_match('~/agent/ticket\.php\?ticket_id=\d+~', $body) === 1 && str_contains($body, '1 notice'), 'alert detail: shows the ticket and "1 notice" sent');
$q("UPDATE rmm_alert_meta SET esc_next_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE alert_id=$alert3");
$n1 = (int) $one("SELECT COUNT(*) FROM notifications WHERE notification_user_id=1");
rivetRmmModule($db)->housekeeping()->run();
$ok((int) $one("SELECT esc_count FROM rmm_alert_meta WHERE alert_id=$alert3") === 2 && (int) $one("SELECT COUNT(*) FROM notifications WHERE notification_user_id=1") === $n1 + 1, 'step 2 (after 30 min): the second target (admin) is notified');
// acknowledging stops the next step
$q("UPDATE rmm_alert_meta SET esc_next_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE alert_id=$alert3");   // the repeat is due now
[$c] = $alrPost('admin', ['action' => 'ack_alert', 'alert_id' => $alert3, 'return' => '/agent/rmm_agent_alerts.php']);
$notifAll = (int) $one("SELECT COUNT(*) FROM notifications");
$mailAll = (int) $one("SELECT COUNT(*) FROM email_queue");
$res = rivetRmmModule($db)->housekeeping()->run();
$ok($one("SELECT state FROM rmm_alert_meta WHERE alert_id=$alert3") === 'acknowledged' && (int) $one("SELECT COUNT(*) FROM notifications") === $notifAll && (int) $one("SELECT COUNT(*) FROM email_queue") === $mailAll, 'after the acknowledgement in the handler nothing more is sent, even though the next step was due');
$ok($one("SELECT esc_next_at FROM rmm_alert_meta WHERE alert_id=$alert3") === null, 'the escalation clock is cleared');

// ============================================================ the older alert actions keep Core in step (UPGRADING item 5)
$integ = (int) $one("SELECT integration_id FROM rmm_alerts WHERE id=$alertB");
$ok($one("SELECT type FROM rmm_integrations WHERE id=$integ") === 'rivetit_agent', 'the alert belongs to the endpoint agent integration');
$ack5 = fn (int $id, string $action, string $who = 'admin') => web($wb, 'POST', '/agent/post/rmm_alert.php', $SS[$who], ['csrf_token' => 'csrftok1', 'action' => $action, 'alert_id' => $id]);
[$c, $b] = $ack5($alertB, 'acknowledge');
$j = json_decode($b, true);
$ok($c === 200 && ($j['success'] ?? false) === true && $one("SELECT status FROM rmm_alerts WHERE id=$alertB") === 'acknowledged', 'rmm_alert.php acknowledge: the legacy row is updated and the answer is unchanged (' . substr($b, 0, 80) . ')');
$ok($one("SELECT state FROM rmm_alert_meta WHERE alert_id=$alertB") === 'acknowledged' && (int) $one("SELECT acked_by FROM rmm_alert_meta WHERE alert_id=$alertB") === 1 && $one("SELECT esc_next_at FROM rmm_alert_meta WHERE alert_id=$alertB") === null, 'rmm_alert.php acknowledge: the Core alert became acknowledged, the escalation clock stopped');
[$c, $b] = $ack5($alertB, 'resolve');
$ok(($j = json_decode($b, true)) && ($j['success'] ?? false) === true && $one("SELECT state FROM rmm_alert_meta WHERE alert_id=$alertB") === 'resolved', 'rmm_alert.php resolve: the Core alert is resolved too');
$ok((int) $one("SELECT COUNT(*) FROM endpoint_agent_checks WHERE device_id={$alrDev['ALRB']} AND check_key='alr_cpu' AND alert_id IS NOT NULL") === 0, 'rmm_alert.php resolve: the check no longer points at the closed alert');
$q("INSERT INTO rmm_integrations SET name='Other', type='tactical_rmm', api_url='http://x', api_key_enc='x', enabled=1");
$oi = (int) $db->insert_id;
$q("INSERT INTO rmm_alerts SET integration_id=$oi, tactical_alert_id='z1', client_id=1, severity='warning', status='new', message='other vendor'");
$oa = (int) $db->insert_id;
[$c, $b] = $ack5($oa, 'acknowledge');
$ok(($j = json_decode($b, true)) && ($j['success'] ?? false) === true && $one("SELECT status FROM rmm_alerts WHERE id=$oa") === 'acknowledged', 'rmm_alert.php: an alert of another integration still works exactly as before');

// ============================================================ the Alerting tab of the asset page
[$c, $body] = web($wb, 'GET', '/agent/asset_details.php?asset_id=' . $alrAsset['ALR1'], $SS['admin']);
foreach (['id="rmm-tab-alerting"', 'id="rmm-pane-alerting"', 'id="rmm-alerting"', 'Depends on', 'Checks and alerting', 'Thresholds', 'name="warn_value"', 'name="crit_value"', 'name="hysteresis"', 'name="for_samples"', 'name="for_minutes"', 'value="save_thresholds"',
    'This check declares no thresholds; add them to the check definition', 'name="parent_device_id"', '/js/rmm_alr.js'] as $needle) {
    $ok(str_contains($body, $needle), "asset page Alerting tab (admin) contains $needle");
}
$ok(!str_contains($body, 'value="clear_thresholds"'), 'no "remove override" button before there is an override');
// threshold override through the handler
$thr = ['action' => 'save_thresholds', 'device_id' => $alrDev['ALR1'], 'check_key' => 'alr_cpu', 'warn_op' => 'gt', 'warn_value' => '70', 'crit_op' => 'gt', 'crit_value' => '90', 'hysteresis' => '5', 'for_samples' => '2', 'for_minutes' => '0',
    'return' => '/agent/asset_details.php?asset_id=' . $alrAsset['ALR1'] . '#rmm-alerting'];
[$c, , $hd] = $alrPost('viewer', $thr);
$ok($one("SELECT override_json FROM rmm_check_eval WHERE device_id={$alrDev['ALR1']} AND check_key='alr_cpu'") === null, 'a viewer cannot override thresholds (Core: rmm.device.manage)');
[$c, , $hd] = $alrPost('tech', $thr);
$ok($one("SELECT override_json FROM rmm_check_eval WHERE device_id={$alrDev['ALR1']} AND check_key='alr_cpu'") === null, 'a technician cannot either: in RivetIT rmm.device.manage is for administrators');
[$c, $body] = web($wb, 'GET', '/agent/asset_details.php?asset_id=' . $alrAsset['ALR1'], $SS['tech']);
$ok(str_contains($body, 'Thresholds') && !str_contains($body, 'value="save_thresholds"') && str_contains($body, 'Defined:') && str_contains($body, 'value="set_parent"'), 'the technician sees the limits but no override form, and may still set the parent device');
[$c, , $hd] = $alrPost('admin', $thr);
$ov = json_decode((string) $one("SELECT override_json FROM rmm_check_eval WHERE device_id={$alrDev['ALR1']} AND check_key='alr_cpu'"), true);
$ok($c === 302 && str_contains($alrLoc($hd), '/agent/asset_details.php?asset_id=' . $alrAsset['ALR1'] . '#rmm-alerting') && ($ov['warn']['value'] ?? null) === 70 && ($ov['crit']['value'] ?? null) === 90 && ($ov['hysteresis'] ?? null) === 5 && ($ov['for_samples'] ?? null) === 2,
    'save_thresholds: whole numbers stored as integers in the override, back to #rmm-alerting');
[$c, $body] = web($wb, 'GET', '/agent/asset_details.php?asset_id=' . $alrAsset['ALR1'], $SS['admin']);
$ok(str_contains($body, 'value="clear_thresholds"') && str_contains($body, 'Changed for this device') && str_contains($body, 'Warning when above 70'), 'the tab shows the override, the defined and the effective values');
[$c, , $hd] = $alrPost('admin', ['action' => 'save_thresholds', 'device_id' => $alrDev['ALR1'], 'check_key' => 'alr_cpu', 'warn_op' => 'gt', 'warn_value' => '70.5', 'crit_op' => 'gt', 'crit_value' => '90', 'hysteresis' => '0', 'for_samples' => '1', 'for_minutes' => '0']);
$ok(json_decode((string) $one("SELECT override_json FROM rmm_check_eval WHERE device_id={$alrDev['ALR1']} AND check_key='alr_cpu'"), true)['warn']['value'] === 70, 'a decimal limit is refused (the override is unchanged)');
[$c, , $hd] = $alrPost('admin', ['action' => 'save_thresholds', 'device_id' => $alrDev['ALR1'], 'check_key' => 'alr_cpu', 'warn_op' => 'gt', 'warn_value' => '95', 'crit_op' => 'gt', 'crit_value' => '90', 'hysteresis' => '0', 'for_samples' => '1', 'for_minutes' => '0']);
$ok(json_decode((string) $one("SELECT override_json FROM rmm_check_eval WHERE device_id={$alrDev['ALR1']} AND check_key='alr_cpu'"), true)['warn']['value'] === 70, 'a critical limit below the warning limit is refused by Core');
[$c, , $hd] = $alrPost('admin', ['action' => 'save_thresholds', 'device_id' => $alrDev['ALR1'], 'check_key' => 'alr_plain', 'warn_op' => 'gt', 'warn_value' => '70', 'crit_op' => 'gt', 'crit_value' => '', 'hysteresis' => '0', 'for_samples' => '1', 'for_minutes' => '0']);
$ok($one("SELECT override_json FROM rmm_check_eval WHERE device_id={$alrDev['ALR1']} AND check_key='alr_plain'") === null, 'a check that declares no thresholds cannot be overridden');
[$c, , $hd] = $alrPost('admin', ['action' => 'clear_thresholds', 'device_id' => $alrDev['ALR1'], 'check_key' => 'alr_cpu']);
$ok($one("SELECT override_json FROM rmm_check_eval WHERE device_id={$alrDev['ALR1']} AND check_key='alr_cpu'") === null, 'clear_thresholds removes the override');
// parent
[$c, , $hd] = $alrPost('viewer', ['action' => 'set_parent', 'device_id' => $alrDev['ALR1'], 'parent_device_id' => $alrDev['ALR2']]);
$ok((int) $one("SELECT COUNT(*) FROM rmm_device_parents WHERE device_id={$alrDev['ALR1']}") === 0, 'a viewer cannot set a parent');
[$c, , $hd] = $alrPost('tech', ['action' => 'set_parent', 'device_id' => $alrDev['ALR1'], 'parent_device_id' => $alrDev['ALR2'], 'return' => '/agent/asset_details.php?asset_id=' . $alrAsset['ALR1'] . '#rmm-alerting']);
$ok((int) $one("SELECT parent_device_id FROM rmm_device_parents WHERE device_id={$alrDev['ALR1']}") === $alrDev['ALR2'], 'set_parent: the parent is stored');
[$c, $body] = web($wb, 'GET', '/agent/asset_details.php?asset_id=' . $alrAsset['ALR1'], $SS['tech']);
$ok(str_contains($body, 'This device depends on') && str_contains($body, 'ALR2') && str_contains($body, 'Parent is reachable') && str_contains($body, 'value="clear_parent"'), 'the tab shows the parent and its state');
$ok(preg_match('/<select[^>]*name="parent_device_id".*?<\/select>/s', $body, $mm) === 1 && !str_contains($mm[0], 'ALRB') && !str_contains($mm[0], '>ALR1<'), 'parent choices are other devices of the same department only');
[$c, $body] = web($wb, 'GET', '/agent/asset_details.php?asset_id=' . $alrAsset['ALR1'], $SS['viewer']);
$ok(str_contains($body, 'id="rmm-pane-alerting"') && !str_contains($body, 'value="set_parent"') && !str_contains($body, 'value="clear_parent"') && !str_contains($body, 'value="save_thresholds"'), 'asset page (viewer): the tab is read only');
[$c, , $hd] = $alrPost('tech', ['action' => 'clear_parent', 'device_id' => $alrDev['ALR1']]);
$ok((int) $one("SELECT COUNT(*) FROM rmm_device_parents WHERE device_id={$alrDev['ALR1']}") === 0, 'clear_parent removes the parent');
[$c, $body] = web($wb, 'GET', '/agent/asset_details.php?asset_id=' . $alrAsset['ALR2'], $SS['admin']);
$ok(str_contains($body, 'A maintenance window is open for this device') && str_contains($body, 'ALR mute ALR2') && str_contains($body, 'maintenance window in Mute mode is open'), 'asset page: the muted device shows the window banner and why its bad check has no alert');
[$c, $body] = web($wb, 'GET', '/agent/asset_details.php?asset_id=' . $alrAsset['ALR3'], $SS['admin']);
$ok(str_contains($body, 'Open alerts of this device') && str_contains($body, 'alr_cpu') && preg_match('~Ticket #\d+~', $body) === 1, 'asset page: open alerts of the device with the ticket link');
[$c, $body] = web($wb, 'GET', '/agent/asset_details.php?asset_id=' . $alrAsset['ALRB'], $SS['deptb']);
$ok(str_contains($body, 'id="rmm-pane-alerting"'), 'asset page (Dept B user): the tab of its own device');
[$c, $body] = web($wb, 'GET', '/agent/asset_details.php?asset_id=' . $alrAsset['ALR1'], $SS['deptb']);
$ok(!str_contains($body, 'id="rmm-pane-alerting"') && !str_contains($body, 'alr_cpu'), 'asset page (Dept B user): nothing of a Dept A device');

// ---- delete: windows and policies
[$c, , $hd] = $alrPost('admin', ['action' => 'delete_window', 'window_id' => $winId]);
$ok($one("SELECT COUNT(*) FROM rmm_maintenance_windows WHERE window_id=$winId") === '0', 'delete_window (admin) removes the window');
$alrBad('ALR2', 99.0, 3);
$ok((int) $one("SELECT COUNT(*) FROM rmm_alert_meta WHERE device_id={$alrDev['ALR2']} AND state<>'resolved'") === 1, 'with the window gone the still-bad check opens its alert');
[$c, , $hd] = $alrPost('tech', ['action' => 'delete_escalation', 'policy_id' => $hostilePol]);
$ok($one("SELECT COUNT(*) FROM rmm_escalation_policies WHERE policy_id=$hostilePol") === '1', 'a technician cannot delete an escalation policy');
[$c, , $hd] = $alrPost('admin', ['action' => 'delete_escalation', 'policy_id' => $hostilePol]);
$ok($one("SELECT COUNT(*) FROM rmm_escalation_policies WHERE policy_id=$hostilePol") === '0', 'delete_escalation (admin) removes the policy');
// update (edit) keeps the id
[$c, , $hd] = $alrPost('admin', ['action' => 'save_escalation', 'policy_id' => $polId, 'name' => 'ALR crit escalation', 'min_severity' => 'crit', 'p_type' => 'device', 'p_id_device' => $alrDev['ALR3'], 'repeat_every_min' => 15, 'repeat_max' => 1, 'enabled' => '1',
    'steps' => [0 => ['after_min' => '0', 'targets' => [0 => ['type' => 'user', 'ref_user' => '10']]]]]);
$ok((int) $one("SELECT repeat_every_min FROM rmm_escalation_policies WHERE policy_id=$polId") === 15 && (int) $one("SELECT COUNT(*) FROM rmm_escalation_steps WHERE policy_id=$polId") === 1, 'editing a policy updates it in place (new repeat, one step)');

// ============================================================ the `alerting` switch off, then the module off
$feat = $rmm->settings()->features();
$feat['alerting'] = false;
$rmm->admin()->saveSettings($admin, ['features_json' => $feat]);
$fresh();
$polCount = (int) $one('SELECT COUNT(*) FROM rmm_escalation_policies');
foreach (['/agent/rmm_agent_alerts.php', '/agent/rmm_maintenance.php', '/agent/rmm_escalations.php'] as $p) {
    [$c, $body] = $alrGet('admin', $p);
    $ok($c === 403 && str_contains($body, 'Alerting is switched off'), "alerting off: $p is the switched-off notice");
}
[$c, $b] = $alrJson('admin', ['action' => 'save_escalation', 'name' => 'ALR off', 'p_type' => 'all', 'steps' => [0 => ['after_min' => '0', 'targets' => [0 => ['type' => 'user', 'ref_user' => '1']]]]]);
$ok(in_array($c, [403, 404], true) && (int) $one('SELECT COUNT(*) FROM rmm_escalation_policies') === $polCount, 'alerting off: the handler refuses and nothing is written (' . $c . ')');
[$c, $body] = web($wb, 'GET', '/agent/asset_details.php?asset_id=' . $alrAsset['ALR1'], $SS['admin']);
$ok(!str_contains($body, 'id="rmm-tab-alerting"') && !str_contains($body, 'rmm_alr.js'), 'alerting off: no Alerting tab on the asset page');
// the older acknowledge with the switch off changes the legacy row only and does not build the module for Core
$alert2 = (int) $one("SELECT alert_id FROM rmm_alert_meta WHERE device_id={$alrDev['ALR2']} AND state='open' LIMIT 1");
[$c, $b] = $ack5($alert2, 'acknowledge');
$ok($alert2 > 0 && $one("SELECT status FROM rmm_alerts WHERE id=$alert2") === 'acknowledged' && $one("SELECT state FROM rmm_alert_meta WHERE alert_id=$alert2") === 'open', 'alerting off: rmm_alert.php acknowledge leaves Core\'s record alone');
$feat['alerting'] = true;
$rmm->admin()->saveSettings($admin, ['features_json' => $feat]);
$fresh();

// ============================================================ RivetCore 1.0.0-rc.14 regression: check params are arrays inside the module
// An install that never edited its checks has no checks_json and gets the built-in list (pending_reboot has empty params). With rc.13 that list carried a
// \stdClass and the thresholds part of the Alerting tab logged "Cannot use object of type stdClass as array" and showed nothing. Real-shaped data: the
// device is enrolled and reports the four checks of the real agent through the real check-in endpoint, the tab is requested over HTTP.
$rc14Log = tempnam(sys_get_temp_dir(), 'rc14err');
$rc14Prev = ini_set('error_log', $rc14Log);
$rc14Srv = static fn (): string => (string) @file_get_contents($web['log']);
$rc14SrvBefore = strlen($rc14Srv());
$ok($rmm->settings()->update(['checks_json' => '']) === [], 'rc.14: the stored check list is cleared (the built-in list applies, as on an install that never edited its checks)');
$fresh();
$rc14Def = $rmm->settings()->checks();
$ok(array_column($rc14Def, 'key') === ['disk_c', 'pending_reboot', 'svc_eventlog'] && is_array($rc14Def[1]['params']) && $rc14Def[1]['params'] === [], 'rc.14: the built-in list gives pending_reboot an empty ARRAY of params, not an object');
$q("INSERT INTO assets SET asset_type='Laptop', asset_name='RC14A', asset_make='Dell', asset_serial='SER-RC14A', asset_client_id=1, asset_status='Active'");
$rc14Asset = (int) $db->insert_id;
[$c, , $j] = ea_enroll($S['enroll']['A'], ea_dev(['serial' => 'SER-RC14A', 'hostname' => 'RC14A']));
$rc14Dev = (int) ($j['device_id'] ?? 0);
$ok($c === 201 && $rc14Dev > 0, 'rc.14: RC14A enrolled through the real endpoint');
[$c] = ea_checkin((string) ($j['device_token'] ?? ''), ['checks' => [
    ['key' => 'disk_c', 'status' => 'ok', 'detail' => 'C: is 41% full'],
    ['key' => 'pending_reboot', 'status' => 'ok', 'detail' => 'no'],
    ['key' => 'svc_eventlog', 'status' => 'ok', 'detail' => 'running'],
    ['key' => 'agent_update', 'status' => 'ok', 'detail' => 'agent is current'],
]]);
$ok($c === 200, 'rc.14: the four checks of the real agent are accepted by the check-in (' . $c . ')');
foreach (['disk_c', 'pending_reboot', 'svc_eventlog'] as $k) {
    $t = rivetRmmAutoApi($db, 1, 'admin', 'GET', [$rc14Dev, 'checks', $k, 'thresholds']);
    $ok($t['ok'] === true && ($t['data']['check_key'] ?? '') === $k && array_key_exists('defined', $t['data']) && $t['data']['defined'] === null, "rc.14: GET checks/$k/thresholds answers ok for a built-in check that declares none");
}
$t = rivetRmmAutoApi($db, 1, 'admin', 'GET', [$rc14Dev, 'checks', 'agent_update', 'thresholds']);
$ok($t['ok'] === false && !str_contains(json_encode($t), 'stdClass'), 'rc.14: a reported check that is not in the list is a plain refusal, not an exception');
$al = rivetRmmAutoApi($db, 1, 'admin', 'GET', [$rc14Dev, 'alerting']);
$ok($al['ok'] === true && count($al['data']['checks'] ?? []) >= 3, 'rc.14: device alerting state lists the reported checks');
[$c, $body] = web($wb, 'GET', '/agent/asset_details.php?asset_id=' . $rc14Asset, $SS['admin']);
$ok($c === 200 && str_contains($body, 'id="rmm-pane-alerting"') && str_contains($body, 'Checks and alerting') && str_contains($body, 'Thresholds'), 'rc.14: the asset page Alerting tab renders for a device on the built-in checks');
foreach (['disk_c', 'pending_reboot', 'svc_eventlog'] as $k) {
    $ok(str_contains($body, '<code class="rmm-mono">' . $k . '</code>'), "rc.14: the thresholds part lists $k");
}
$ok(!str_contains($body, 'Fatal error') && !str_contains($body, 'Uncaught'), 'rc.14: no PHP error text in the page');
// the same device with a stored list that holds both shapes of empty params ({} and []) next to a check that has thresholds: the editor renders for the one that has them
$rc14Checks = $rc14Def;
$rc14Checks[] = ['key' => 'rc14_cpu', 'type' => 'cpu', 'interval_s' => 60, 'params' => ['thresholds' => ['warn' => ['op' => 'gt', 'value' => 80], 'crit' => ['op' => 'gt', 'value' => 95], 'for_samples' => 1]]];
$rc14Checks[] = ['key' => 'rc14_plain', 'type' => 'cpu', 'interval_s' => 60, 'params' => new stdClass()];
$ok($rmm->settings()->update(['checks_json' => json_encode($rc14Checks)]) === [], 'rc.14: a stored list with {} and [] params and one thresholds check is accepted');
$fresh();
ea_checkin((string) ($j['device_token'] ?? ''), ['checks' => [['key' => 'rc14_cpu', 'status' => 'ok', 'detail' => 'load 10', 'value' => 10.0], ['key' => 'rc14_plain', 'status' => 'ok', 'detail' => 'fine', 'value' => 12.0]]]);
foreach (['pending_reboot', 'rc14_cpu', 'rc14_plain'] as $k) {
    $t = rivetRmmAutoApi($db, 1, 'admin', 'GET', [$rc14Dev, 'checks', $k, 'thresholds']);
    $ok($t['ok'] === true, "rc.14: GET checks/$k/thresholds answers ok with a stored list holding both shapes");
}
[$c, $body] = web($wb, 'GET', '/agent/asset_details.php?asset_id=' . $rc14Asset, $SS['admin']);
$ok($c === 200 && str_contains($body, '<code class="rmm-mono">rc14_cpu</code>') && str_contains($body, 'name="warn_value"') && str_contains($body, 'name="crit_value"') && str_contains($body, 'value="save_thresholds"'), 'rc.14: the thresholds editor renders for the check that declares thresholds');
$ok(str_contains($body, 'This check declares no thresholds'), 'rc.14: the checks without thresholds say so');
// nothing was logged, in this process or by the web server
clearstatcache();
$rc14Own = (string) file_get_contents($rc14Log);
$rc14New = substr($rc14Srv(), $rc14SrvBefore);
$ok(!str_contains($rc14Own, 'stdClass') && !str_contains($rc14New, 'stdClass'), 'rc.14: "Cannot use object of type stdClass as array" was not logged (test process and web server)');
// (the test client sends no User-Agent header, so every request logs the same harmless "Undefined array key HTTP_USER_AGENT" warnings; they are not what is checked here)
$rc14Php = array_values(array_filter(preg_split('/\R/', $rc14Own . "\n" . $rc14New) ?: [], static fn (string $l): bool => preg_match('/PHP (Fatal error|Warning|Notice|Deprecated|Parse error)/', $l) === 1 && !str_contains($l, 'HTTP_USER_AGENT')));
$ok($rc14Php === [], 'rc.14: no PHP fatal, warning, notice or deprecation was logged while the tab loaded' . ($rc14Php === [] ? '' : ' [' . substr($rc14Php[0], 0, 240) . ']'));
ini_set('error_log', $rc14Prev === false ? '' : (string) $rc14Prev);
@unlink($rc14Log);
$rmm->settings()->update(['checks_json' => json_encode($alrOrigChecks)]);
$fresh();

// ============================================================ RivetCore 1.0.0-rc.19 regression: a check that only a POLICY defines has thresholds
// Found on a real Windows device that received the starter workstation policy: GET/PUT endpoint_devices/{id}/checks/{key}/thresholds answered 404 for every check the
// policy delivered, because Core only looked in the global check list. The Alerting tab of the asset page then showed no editor, and an override could not be saved.
// Real-shaped data: the policy is created and assigned through the real handler (rmm_automation_pol.php), the device reports the check through the real check-in.
$rc19Feat0 = $rmm->settings()->features();
$rc19Feat = $rc19Feat0;
$rc19Feat['policies'] = true;
$rmm->admin()->saveSettings($admin, ['features_json' => $rc19Feat]);
$fresh();
rivetRmmForgetAccess();
$rc19Post = fn (array $f) => web($wb, 'POST', '/agent/post/rmm_automation_pol.php', $SS['admin'], $f + ['csrf_token' => 'csrftok1']);
$rc19Page = '/agent/asset_details.php?asset_id=' . $alrAsset['ALR2'];
$ok(!in_array('rc19_pol_cpu', array_column($rmm->settings()->checks(), 'key'), true), 'rc.19: the check is not in the global check list (only a policy will define it)');
[$c] = $rc19Post(['action' => 'save_policy', 'policy_id' => 0, 'name' => 'RC19 policy checks', 'description' => '', 'enabled' => '1',
    'chk' => [0 => ['key' => 'rc19_pol_cpu', 'mode' => 'override', 'type' => 'cpu', 'interval_s' => '60', 'p' => ['cpu' => ['window_s' => '5']],
        'th' => ['warn_op' => 'gt', 'warn_val' => '80', 'crit_op' => 'gt', 'crit_val' => '95', 'hysteresis' => '5', 'for_samples' => '2', 'for_minutes' => '', 'ignore' => '']]]]);
$rc19Pid = (int) $one("SELECT policy_id FROM rmm_policies WHERE name='RC19 policy checks'");
$ok($c === 302 && $rc19Pid > 0, 'rc.19: a policy with a thresholds check is created through the real handler');
$rc19Post(['action' => 'assign_policy', 'policy_id' => $rc19Pid, 'as_type' => 'device', 'as_id_device' => $alrDev['ALR2'], 'priority' => '50']);
$ok((int) $one("SELECT COUNT(*) FROM rmm_policy_assignments WHERE policy_id=$rc19Pid AND scope_type='device' AND scope_id={$alrDev['ALR2']}") === 1, 'rc.19: the policy is assigned to the device');
$fresh();
[$c] = ea_checkin($alrTok['ALR2'], ['capabilities' => ['check:cpu', 'policies'], 'checks' => [['key' => 'rc19_pol_cpu', 'status' => 'ok', 'detail' => 'load 10', 'value' => 10.0]]]);
$ok($c === 200, 'rc.19: the device reports the policy check through the real check-in (' . $c . ')');
$t = rivetRmmAutoApi($db, 1, 'admin', 'GET', [$alrDev['ALR2'], 'checks', 'rc19_pol_cpu', 'thresholds']);
$ok($t['ok'] === true && ($t['data']['check_key'] ?? '') === 'rc19_pol_cpu' && ($t['data']['defined']['warn']['value'] ?? null) === 80 && ($t['data']['defined']['crit']['value'] ?? null) === 95,
    'rc.19: GET checks/rc19_pol_cpu/thresholds answers ok with the limits the policy defines (was 404 before rc.19)');
[$c, $body] = web($wb, 'GET', $rc19Page, $SS['admin']);
$ok($c === 200 && str_contains($body, '<code class="rmm-mono">rc19_pol_cpu</code>') && str_contains($body, 'name="check_key" value="rc19_pol_cpu"') && str_contains($body, 'name="warn_value"') && str_contains($body, 'value="save_thresholds"'),
    'rc.19: the asset page Alerting tab renders the thresholds editor for a policy-defined check');
$rc19Thr = ['action' => 'save_thresholds', 'device_id' => $alrDev['ALR2'], 'check_key' => 'rc19_pol_cpu', 'warn_op' => 'gt', 'warn_value' => '60', 'crit_op' => 'gt', 'crit_value' => '85', 'hysteresis' => '5', 'for_samples' => '2', 'for_minutes' => '0',
    'return' => $rc19Page . '#rmm-alerting'];
[$c, , $hd] = $alrPost('admin', $rc19Thr);
$ov = json_decode((string) $one("SELECT override_json FROM rmm_check_eval WHERE device_id={$alrDev['ALR2']} AND check_key='rc19_pol_cpu'"), true);
$ok($c === 302 && str_contains($alrLoc($hd), '#rmm-alerting') && ($ov['warn']['value'] ?? null) === 60 && ($ov['crit']['value'] ?? null) === 85, 'rc.19: a threshold override for the policy check is saved through the handler (was refused before rc.19)');
[$c, $body] = web($wb, 'GET', $rc19Page, $SS['admin']);
$ok(str_contains($body, 'value="clear_thresholds"') && str_contains($body, 'Changed for this device') && str_contains($body, 'Warning when above 60'), 'rc.19: the tab shows the override of the policy check');
[$c, , $hd] = $alrPost('admin', ['action' => 'clear_thresholds', 'device_id' => $alrDev['ALR2'], 'check_key' => 'rc19_pol_cpu']);
$ok($one("SELECT override_json FROM rmm_check_eval WHERE device_id={$alrDev['ALR2']} AND check_key='rc19_pol_cpu'") === null, 'rc.19: the override of the policy check is removed again');
$t = rivetRmmAutoApi($db, 1, 'admin', 'GET', [$alrDev['ALR2'], 'checks', 'rc19_not_a_check', 'thresholds']);
$ok($t['ok'] === false, 'rc.19: a check that neither the list nor a policy defines is still a plain refusal');
$t = rivetRmmAutoApi($db, 1, 'admin', 'GET', [$alrDev['ALR3'], 'checks', 'rc19_pol_cpu', 'thresholds']);
$ok($t['ok'] === false, 'rc.19: the policy check has no thresholds endpoint on a device the policy does not reach');
$rc19Post(['action' => 'delete_policy', 'policy_id' => $rc19Pid]);
$q("DELETE FROM rmm_policy_assignments WHERE policy_id=$rc19Pid"); $q("DELETE FROM rmm_policy_versions WHERE policy_id=$rc19Pid"); $q("DELETE FROM rmm_policies WHERE policy_id=$rc19Pid");
$rmm->admin()->saveSettings($admin, ['features_json' => $rc19Feat0]);
$fresh();
rivetRmmForgetAccess();

$rmm->admin()->disable($admin); $fresh();
$a = $questions();
$o1 = [rivetRmmEnabled($db) || rivetRmmFeatureOn("alerting", $db) ? true : null];
$b = $questions();
$ok($o1[0] === null && $b - $a === $overhead, 'module off: the page gate answers without asking the database anything');
foreach (['/agent/rmm_agent_alerts.php', '/agent/rmm_maintenance.php', '/agent/rmm_escalations.php'] as $p) {
    [$c, $body] = $alrGet('admin', $p);
    $ok($c === 403 && str_contains($body, 'RMM module is switched off'), "module off: $p is the module-off notice");
}
[$c, $b] = $alrJson('admin', ['action' => 'save_window', 'name' => 'ALR off', 'w_type' => 'all', 'kind' => 'once', 'timezone' => 'UTC', 'starts_local' => $loc(0), 'ends_local' => $loc(3600)]);
$ok(in_array($c, [403, 404], true) && $one("SELECT COUNT(*) FROM rmm_maintenance_windows WHERE name='ALR off'") === '0', 'module off: the handler refuses (' . $c . ')');
$rmm->admin()->enable($admin); $fresh();

// ---- restore what this fragment changed in the shared settings
$rmm->settings()->update(['checks_json' => json_encode($alrOrigChecks), 'failure_debounce' => (int) $alrOrigSettings['failure_debounce'], 'recovery_debounce' => (int) $alrOrigSettings['recovery_debounce']]);
$fresh();
