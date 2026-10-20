<?php
/*
 * RivetCore rc.25 adoption, part 1 (RivetIT 26.10.65, DB 2.6.181): per-OS service checks in the schedule builder, the upgrade of a stored schedule, and the clean-up of checks that
 * no longer apply (a Windows PC that was given the Linux service checks) with alert resolution and NO ticket. Scratch database only.
 *
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=<scratch> RIVETIT_TEST_DB_USER=.. RIVETIT_TEST_DB_PASS=.. EA_TEST_NON_WINDOWS=1 RMM_TEST_STATE_DIR=none RMM_GATE_STATE_DIR=/nonexistent php tests/rmm_rc25_os_cleanup.php
 */
require __DIR__ . '/endpoint_agent_lib.php';
require_once "$root/includes/rmm_check_cleanup.php";
require_once "$root/includes/rmm_check_schedule_ui.php";

ea_reset();
$rmm = rivetRmmModule($db);
$byKey = static function (array $l): array { $o = []; foreach ($l as $c) { $o[$c['key']] = $c; } return $o; };

// ---------------------------------------------------------------- the choice table decides the OS
$linuxSvc = ['ssh', 'sshd', 'cron', 'crond', 'chronyd', 'systemd-timesyncd', 'rsyslog', 'fail2ban'];
$winSvc = ['EventLog', 'wuauserv', 'WinDefend', 'mpssvc', 'W32Time', 'Spooler', 'TermService', 'Mesh Agent', 'VeeamEndpointBackupSvc'];
$base = ['disk_on' => '1', 'disk_warn' => '15', 'reboot_on' => '1', 'svc' => array_merge($winSvc, $linuxSvc), 'svc_extra' => '', 'interval' => '900'];
$r = rivetCsBuild($base, []);
$ok($r['errors'] === [] && $r['list'] !== null, 'every service of the choice table builds');
$m = $byKey($r['list'] ?? []);
$bad = [];
foreach ($winSvc as $n) { $k = 'svc_' . strtolower(preg_replace('/[^A-Za-z0-9_.-]+/', '_', $n)); if (($m[$k]['os'] ?? null) !== 'windows') { $bad[] = $n; } }
foreach ($linuxSvc as $n) { $k = 'svc_' . strtolower($n); if (($m[$k]['os'] ?? null) !== 'linux') { $bad[] = $n; } }
$ok($bad === [], 'all 9 Windows services carry os=windows and all 8 Linux services os=linux (' . implode(',', $bad) . ')');
$ok(!isset($m['disk_free']['os']) && !isset($m['pending_reboot']['os']), 'the disk and restart checks run everywhere (no os)');
[, $e] = \RivetCore\Rmm\Settings\ChecksValidator::validate(json_encode($r['list']));
$ok($e === null, 'RivetCore accepts the list with os');

// ---------------------------------------------------------------- add another service: Windows or Linux, default Windows
$r = rivetCsBuild(['svc' => [], 'svc_extra' => "nginx\nmysql", 'interval' => '900'], []);
$m = $byKey($r['list'] ?? []);
$ok(($m['svc_nginx']['os'] ?? '') === 'windows' && ($m['svc_mysql']['os'] ?? '') === 'windows', 'added services default to Windows when no choice is posted');
$r = rivetCsBuild(['svc' => [], 'svc_extra' => "nginx\nmysql", 'svc_extra_os' => 'linux', 'interval' => '900'], []);
$m = $byKey($r['list'] ?? []);
$ok(($m['svc_nginx']['os'] ?? '') === 'linux' && ($m['svc_mysql']['os'] ?? '') === 'linux', 'the dropdown makes the added services Linux');
$r = rivetCsBuild(['svc' => [], 'svc_extra' => "linux: nginx\nWindows : Foo\nBar", 'svc_extra_os' => 'linux', 'interval' => '900'], []);
$m = $byKey($r['list'] ?? []);
$ok(($m['svc_nginx']['os'] ?? '') === 'linux' && ($m['svc_foo']['os'] ?? '') === 'windows' && ($m['svc_bar']['os'] ?? '') === 'linux' && $r['errors'] === [], 'a typed "linux:" / "windows:" prefix wins over the dropdown');
$r = rivetCsBuild(['svc' => [], 'svc_extra' => 'Spooler', 'svc_extra_os' => 'linux', 'interval' => '900'], []);
$ok(($byKey($r['list'] ?? [])['svc_spooler']['os'] ?? '') === 'windows', 'a service the table knows keeps the table OS even when typed into the box');
foreach (['mac', 'both', '', ['windows'], 'LINUX', ' windows'] as $badOs) {
    $r = rivetCsBuild(['svc' => [], 'svc_extra' => 'nginx', 'svc_extra_os' => $badOs, 'interval' => '900'], []);
    $ok($r['list'] === null && $r['errors'] !== [], 'hostile svc_extra_os ' . json_encode($badOs) . ' is refused');
}

// ---------------------------------------------------------------- a stored schedule is upgraded without losing anything
$stored = [
    ['key' => 'disk_free', 'type' => 'disk', 'params' => ['mount' => 'C:', 'warn_free_pct' => 15, 'fail_free_pct' => 7], 'interval_s' => 900],
    ['key' => 'pending_reboot', 'type' => 'pending_reboot', 'params' => new stdClass(), 'interval_s' => 900],
    ['key' => 'svc_eventlog', 'type' => 'service', 'params' => ['name' => 'EventLog', 'expected' => 'running'], 'interval_s' => 900],
    ['key' => 'svc_sshd', 'type' => 'service', 'params' => ['name' => 'sshd', 'expected' => 'running'], 'interval_s' => 900],
    ['key' => 'svc_nginx', 'type' => 'service', 'params' => ['name' => 'nginx', 'expected' => 'running'], 'interval_s' => 900],
    ['key' => 'svc_pinned', 'type' => 'service', 'params' => ['name' => 'cron', 'expected' => 'running'], 'interval_s' => 900, 'os' => 'windows'],
    ['key' => 'odd', 'type' => 'ping', 'params' => ['host' => 'x'], 'interval_s' => 300],
    ['key' => 'scr', 'type' => 'script', 'params' => ['script' => 'exit 0', 'timeout_s' => 10], 'interval_s' => 900],
];
$up = $byKey(rivetCsUpgradeChecks(json_decode(json_encode($stored), true)));
$ok($up['svc_eventlog']['os'] === 'windows' && $up['svc_sshd']['os'] === 'linux', 'stored services get the table OS');
$ok(!isset($up['svc_nginx']['os']) && $up['svc_pinned']['os'] === 'windows' && !isset($up['disk_free']['os']) && !isset($up['odd']['os']), 'a service outside the table, an OS already stored and other checks are untouched');
$ok(rivetCsUpgradeChecks(array_values($up)) == array_values($up), 'the upgrade is idempotent');
$st = rivetCsStateFromChecks(json_decode(json_encode($stored), true));
$ok($st['services'] === ['EventLog', 'sshd', 'nginx'] && ($st['svc_os']['sshd'] ?? '') === 'linux' && ($st['svc_os']['nginx'] ?? 'x') === '' && isset($st['kept']['svc_pinned']) && isset($st['kept']['odd']),
    'the page reads the old list: nginx stays "both", a service pinned to another OS than the table says is kept as stored, unknown checks kept');
$page = rivetCsRender($st);
$ok(strpos($page, 'A check ticked for one OS is only sent to devices of that OS.') !== false && strpos($page, 'name="cs[svc_extra_os]"') !== false && strpos($page, '<option value="windows" selected>') !== false, 'the page has the note and the Windows/Linux dropdown (Windows selected)');
$r = rivetCsBuild(['disk_on' => '1', 'disk_warn' => '15', 'reboot_on' => '1', 'svc' => ['EventLog', 'sshd'], 'svc_extra' => 'nginx', 'svc_extra_os' => 'linux', 'interval' => '900'], json_decode(json_encode($stored), true));
$m = $byKey($r['list'] ?? []);
$ok($r['errors'] === [] && !isset($m['svc_nginx']['os']) && $m['svc_sshd']['os'] === 'linux' && isset($m['svc_pinned']['os']) && $m['svc_pinned']['os'] === 'windows' && isset($m['odd'])
    && $m['disk_free']['params']['mount'] === 'C:', 're-saving the old list keeps nginx as it was (both), keeps the kept checks and the disk mount');
$ok(strpos(rivetCsDescribe($m['svc_sshd']), 'Linux devices only') !== false, 'the description says which OS');

// ---------------------------------------------------------------- the clean-up on a real database
$integration = $rmm->settings()->integrationId();
$mk = function (string $host, string $os) use ($q, $db): int {
    $q("INSERT INTO endpoint_agent_devices SET install_id='" . ea_uuid() . "', hostname='$host', os='$os', link_state='linked', client_id=1, last_checkin_at=UTC_TIMESTAMP(), first_seen_at=UTC_TIMESTAMP()");
    return (int) $db->insert_id;
};
$seedCheck = function (int $dev, string $key, string $status) use ($q, $db, $integration): ?int {
    $aid = 'NULL';
    if ($status === 'fail') {
        $q("INSERT INTO rmm_alerts SET integration_id=$integration, tactical_alert_id='agent:$dev:$key:1', severity='error', status='new', message='service $key not found', raw_data_json='{}', client_id=1");
        $aid = (string) $db->insert_id;
    }
    $q("INSERT INTO endpoint_agent_checks SET device_id=$dev, check_key='$key', status='$status', detail='x', alert_id=$aid, last_reported_at=UTC_TIMESTAMP()");
    return $aid === 'NULL' ? null : (int) $aid;
};
$win = $mk('MWA', 'windows'); $lin = $mk('LNX1', 'linux');
$linuxKeys = array_map(static fn ($n) => 'svc_' . $n, $linuxSvc);
$alertsWin = []; $alertsLin = [];
foreach ($linuxKeys as $k) { $alertsWin[$k] = $seedCheck($win, $k, 'fail'); $alertsLin[$k] = $seedCheck($lin, $k, 'fail'); }
$alertWinOwn = $seedCheck($win, 'svc_eventlog', 'fail'); $seedCheck($win, 'disk_free', 'ok'); $seedCheck($win, 'log.rule_5', 'fail'); $seedCheck($win, 'patch.compliance', 'ok');
// the stored schedule is the old one: every service without os
$oldList = []; foreach (array_merge($winSvc, $linuxSvc) as $n) { $oldList[] = ['key' => 'svc_' . strtolower(preg_replace('/[^A-Za-z0-9_.-]+/', '_', $n)), 'type' => 'service', 'params' => ['name' => $n, 'expected' => 'running'], 'interval_s' => 900]; }
$oldList[] = ['key' => 'disk_free', 'type' => 'disk', 'params' => ['warn_free_pct' => 20, 'fail_free_pct' => 10], 'interval_s' => 900];
$ok($rmm->settings()->update(['checks_json' => json_encode($oldList)]) === [], 'the old, OS-less schedule is stored');
$ticketsBefore = (int) $one('SELECT COUNT(*) FROM tickets');
$n = rivetRmmUpgradeScheduleOs($db);
$ok($n === 17, "the stored schedule is upgraded in place ($n services got an OS)");
$stored2 = json_decode((string) $one('SELECT checks_json FROM endpoint_agent_settings WHERE id=1'), true);
$s2 = $byKey($stored2);
$ok(count($stored2) === 18 && $s2['svc_sshd']['os'] === 'linux' && $s2['svc_eventlog']['os'] === 'windows' && $s2['svc_mesh_agent']['os'] === 'windows' && $s2['disk_free']['params']['warn_free_pct'] === 20 && !isset($s2['disk_free']['os']), 'nothing was lost, the OS is stored');
$ok(rivetRmmUpgradeScheduleOs($db) === 0, 'a second upgrade changes nothing');

$res = rivetRmmCleanupChecks($db);
$ok($res['devices'] === 2 && $res['removed'] === 8, 'the clean-up reports 2 devices, 8 checks removed: ' . json_encode($res));
$ok((int) $one("SELECT COUNT(*) FROM endpoint_agent_checks WHERE device_id=$win AND check_key IN ('" . implode("','", $linuxKeys) . "')") === 0, 'the 8 Linux checks are gone from the Windows PC');
$ok((int) $one("SELECT COUNT(*) FROM endpoint_agent_checks WHERE device_id=$lin") === 8, 'the Linux machine keeps its checks');
$ok((int) $one("SELECT COUNT(*) FROM endpoint_agent_checks WHERE device_id=$win AND check_key IN ('svc_eventlog','disk_free','log.rule_5','patch.compliance')") === 4, 'the Windows checks and the server-reported keys stay');
$resolved = 0; foreach ($alertsWin as $a) { $resolved += (int) $one("SELECT COUNT(*) FROM rmm_alerts WHERE id=$a AND status='resolved' AND resolved_at IS NOT NULL"); }
$ok($resolved === 8, 'the 8 false alerts of the Windows PC are resolved');
$stillOpen = 0; foreach ($alertsLin as $a) { $stillOpen += (int) $one("SELECT COUNT(*) FROM rmm_alerts WHERE id=$a AND status='new'"); }
$ok($stillOpen === 8 && (int) $one("SELECT COUNT(*) FROM rmm_alerts WHERE id=$alertWinOwn AND status='new'") === 1, 'the Linux machine alerts and the Windows PC own alert stay open');
$ok((int) $one('SELECT COUNT(*) FROM tickets') === $ticketsBefore && (int) $one("SELECT COUNT(*) FROM rmm_alerts WHERE ticket_id IS NOT NULL AND id IN (" . implode(',', $alertsWin) . ")") === 0, 'no ticket was created for the resolved alerts');
$ok(rivetRmmCleanupChecks($db)['removed'] === 0, 'the clean-up is idempotent');

// ---------------------------------------------------------------- the updater step (2.6.179 and 2.6.180 both land on 2.6.181)
require_once "$root/includes/database_version.php";
$ok(LATEST_DATABASE_VERSION === '2.6.181', 'LATEST_DATABASE_VERSION is 2.6.181 (' . LATEST_DATABASE_VERSION . ')');
$upd = file_get_contents("$root/admin/database_updates.php");
$ok(strpos($upd, "in_array(\$rivetit_db_version(), ['2.6.179', '2.6.180'], true)") !== false && strpos($upd, "'2.6.181'") !== false && strpos($upd, 'Migration0032CheckSet') !== false && strpos($upd, 'rivetRmmCleanupChecks') !== false, 'the step is gated on 2.6.179/2.6.180 and runs the Core runner, the schedule upgrade and the clean-up');
foreach (['2.6.179', '2.6.180', '2.6.181'] as $from) {
    // a fresh false state for each replay
    $seedCheck($win, 'svc_cron', 'fail');
    if ($from !== '2.6.181') {
        $q("DELETE FROM rivet_core_migrations WHERE migration_id IN ('0030_rmm_change_monitor','0031_rmm_clock_skew','0032_rmm_check_set')");
        $q("DROP TABLE IF EXISTS rmm_device_checkset");
    }
    $q("UPDATE settings SET config_current_database_version='$from' WHERE company_id=1");
    shell_exec('cd ' . escapeshellarg($root) . ' && ' . escapeshellarg(PHP_BINARY) . ' scripts/update_cli.php --update_db 2>&1');
    $v = (string) $one('SELECT config_current_database_version FROM settings WHERE company_id=1');
    $ok($v === '2.6.181', "update from $from lands on 2.6.181 ($v)");
    $ok((int) $one("SELECT COUNT(*) FROM rivet_core_migrations WHERE migration_id IN ('0030_rmm_change_monitor','0031_rmm_clock_skew','0032_rmm_check_set')") === 3, "replay from $from: the Core runner has recorded 0030, 0031, 0032");
    $ok((int) $one("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='rmm_device_checkset'") === 1, "replay from $from: rmm_device_checkset recreated");
    $ok((int) $one("SELECT COUNT(*) FROM endpoint_agent_checks WHERE device_id=$win AND check_key='svc_cron'") === ($from === '2.6.181' ? 1 : 0), $from === '2.6.181' ? 'a database already on 2.6.181 is not touched by the step' : "replay from $from: the step cleans the stale Windows check again");
    $ok((int) $one("SELECT COUNT(*) FROM endpoint_agent_devices WHERE hostname IN ('MWA','LNX1')") === 2, "replay from $from keeps the devices");
}

// ---------------------------------------------------------------- fresh install == migrated (db.sql against the Core DDL)
$sql = file_get_contents("$root/db.sql");
$tables = ['endpoint_agent_change_records', 'endpoint_agent_change_rules', 'endpoint_agent_change_settings', 'endpoint_agent_change_state', 'rmm_device_checkset', 'endpoint_agent_devices'];
foreach ($tables as $t) {
    $isDump = preg_match('/DROP TABLE IF EXISTS `' . $t . '`;.*?CREATE TABLE `' . $t . '` \((.*?)\n\) ENGINE/s', $sql, $mm) === 1 || preg_match('/CREATE TABLE IF NOT EXISTS `' . $t . '` \((.*?)\n\) ENGINE/s', $sql, $mm) === 1;
    $ok($isDump, "db.sql defines $t");
    preg_match_all('/^  `([a-z_0-9]+)` ([^\n]*?),?$/m', $mm[1] ?? '', $cols);
    $live = []; foreach ($rows("SELECT column_name, column_type, is_nullable, column_default FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='$t' ORDER BY ordinal_position") as $c) { $live[] = $c['column_name']; }
    $ok($cols[1] === $live, "db.sql and the migrated database agree on the columns (and their order) of $t");
    // the DDL text of every column equals SHOW CREATE TABLE's
    $cr = (string) ($db->query("SHOW CREATE TABLE `$t`")->fetch_row()[1] ?? '');
    $same = true; foreach ($cols[0] as $line) { if (strpos($cr, rtrim($line, ',')) === false) { $same = false; echo "   differs: $line\n"; } }
    $ok($same, "db.sql column definitions of $t equal the migrated ones");
    $ok((string) $one("SELECT table_collation FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='$t'") === 'utf8mb4_general_ci', "$t uses utf8mb4_general_ci");
}
$ok((int) $one('SELECT COUNT(*) FROM endpoint_agent_change_rules') === 0 && (int) $one('SELECT COUNT(*) FROM endpoint_agent_change_settings') <= 1, 'migration 0030 inserted no rule');

// ---------------------------------------------------------------- clean up what this test made
$q("DELETE FROM endpoint_agent_checks"); $q("DELETE FROM endpoint_agent_devices"); $q("DELETE FROM rmm_alerts"); $q("UPDATE endpoint_agent_settings SET checks_json=NULL WHERE id=1");
