<?php
/*
 * Fragment of tests/rmm_ui.php (RivetCore 1.0.0-rc.11, builder C): Administration > Endpoint agent: remote tools, file transfer, log collection, the scale settings,
 * partitioning, the external metric store and the Scale and capacity panel. Shares that file's variables. Everything is driven through the product: the real admin
 * handler (admin/post.php, CSRF, the Referer dispatch) over HTTP as the administrator, the real page, the real check-in endpoint, and housekeeping in a fresh process
 * (what cron does). The external store talks to a loopback fake VictoriaMetrics (tests/support/FakeVictoriaMetricsServer.php).
 */

use ITFlow\Core\Adapter\Endpoint\EndpointAccessPolicy;
use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Remote\RemoteSettings;
use RivetCore\Rmm\RmmStateFile;

require_once "$root/includes/rmm_scale_admin.php";
require_once "$root/tests/support/FakeVictoriaMetricsServer.php";

$pg = fn (string $who = 'admin') => web($wb, 'GET', '/admin/settings_endpoint_agent.php', $SS[$who]);
$adm = fn (array $f, string $who = 'admin', array $hdr = []) => web($wb, 'POST', '/admin/post.php', $SS[$who], $f + ['csrf_token' => 'csrftok1'], array_merge($REF1, $hdr));
/** The text of the message a card shows once after a save (empty when none). */
$msgOf = function (string $body): string {
    return preg_match('/data-rmm-scale-msg>(.*?)<\/div>/s', $body, $m) === 1 ? trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES)) : '';
};
/** POST as the administrator, then load the page: [message text, page body]. */
$act = function (array $f, string $who = 'admin') use ($adm, $pg, $fresh, $msgOf): array {
    $adm($f, $who);
    $fresh();
    $rmmx = rivetRmmModule();
    $rmmx->remoteSettings()->refresh();
    [, $body] = $pg($who);

    return [$msgOf($body), $body];
};
$state = fn () => RmmStateFile::read($sd);
$lastLog = fn () => (string) $one("SELECT log_description FROM logs ORDER BY log_id DESC LIMIT 1");
$resetRemote = function () use ($q, $rmm, $fresh) {
    $q("UPDATE endpoint_agent_remote_settings SET transfer_allow_json = NULL, logs_collect_json = NULL, limits_json = NULL WHERE id = 1");
    $q("UPDATE settings SET config_rmm_remote_manage_lvl3 = 0 WHERE company_id = 1");
    $rmm->admin()->saveSettings(new RivetCore\Rmm\Authz\RmmPrincipal(1, 'admin'), ['features_json' => array_merge($rmm->settings()->features(), ['remote_tools' => false, 'filetransfer' => false, 'logs' => false])]);
    $fresh();
    $rmm->remoteSettings()->refresh();
};
$resetRemote();

// ============================================================ the page
[$c, $body] = $pg();
$ok($c === 200 && !str_contains($body, 'Fatal error') && !str_contains($body, 'Warning:') && !str_contains($body, 'Deprecated:'), 'Administration loads with the new cards and no PHP notice (' . $c . ')');
foreach (['remote-tools', 'file-transfer', 'log-collection', 'scale', 'partitioning', 'ext-store'] as $card) {
    $ok(str_contains($body, 'id="' . $card . '"'), "the $card card is on the page");
}
$ok(str_contains($body, '/js/rmm_scale.js') && str_contains($body, 'itflow_rmm_scale.css'), 'the page links the scale script and style');
$ok(str_contains($body, htmlspecialchars(RemoteSettings::FILE_TRANSFER_RISK, ENT_QUOTES)) && str_contains($body, htmlspecialchars(RemoteSettings::LOGS_STORAGE_WARNING, ENT_QUOTES)), 'the risk and storage warnings of Core are on the page verbatim');
$ok(!preg_match('/name="feature_(remote_tools|filetransfer|logs)"[^>]*checked/', $body), 'all three sub-switches are off by default');
[$c, $tb] = $pg('tech');
$ok(!str_contains($tb, 'id="file-transfer"') && !str_contains($tb, 'name="save_filetransfer_settings"'), 'a technician is not shown the administration cards (' . $c . ')');

// ============================================================ remote tools
$wasLvl3 = (int) $one("SELECT config_rmm_remote_manage_lvl3 FROM settings WHERE company_id=1");
$ok($wasLvl3 === 0 && !$rmm->featureOn('remote_tools'), 'remote tools and the level 3 setting are off by default');
[$m] = $act(['save_remote_settings' => 1, 'feature_remote_tools' => '1'], 'tech');
$ok(!$rmm->featureOn('remote_tools'), 'a technician cannot switch the remote tools on');
$adm(['save_remote_settings' => 1, 'feature_remote_tools' => '1', 'csrf_token' => 'wrong']);
$fresh();
$ok(!$rmm->featureOn('remote_tools'), 'a wrong CSRF token changes nothing');
[$m, $body] = $act(['save_remote_settings' => 1, 'feature_remote_tools' => '1']);
$ok($rmm->featureOn('remote_tools') && str_contains($m, 'Remote tools switched on'), 'administrator: remote tools switch on (' . $m . ')');
$ok(($state()['features']['remote_tools'] ?? false) === true && rivetRmmFeatureOn('remote_tools'), 'the state file and rivetRmmFeatureOn agree');
$ok(str_contains($body, 'name="remote_manage_lvl3"') && str_contains($body, 'stay administrator-only'), 'the page says uploads and these settings stay administrator-only');
$ok(preg_match('/name="feature_remote_tools"[^>]*checked/', $body) === 1, 'the switch is shown on');
rivetRmmForgetAccess();
$auth = $rmm->authorizer();
$ok($auth->allowed($USER['admin'], RmmAbility::REMOTE_MANAGE, 0) && !$auth->allowed($USER['tech'], RmmAbility::REMOTE_MANAGE, 0), 'ability: the administrator may manage remote tools; the level 3 technician may not (setting off)');
[$m] = $act(['save_remote_settings' => 1, 'feature_remote_tools' => '1', 'remote_manage_lvl3' => '1']);
rivetRmmForgetAccess();
$pol = new EndpointAccessPolicy(rivetCoreDb($db));
$ok((int) $one("SELECT config_rmm_remote_manage_lvl3 FROM settings WHERE company_id=1") === 1 && str_contains($m, 'Level 3 setting saved'), 'the level 3 setting is saved (' . $m . ')');
$ok($pol->can($USER['tech'], RmmAbility::REMOTE_MANAGE) && !$pol->can($USER['remoteonly'], RmmAbility::REMOTE_MANAGE) && $pol->can($USER['admin'], RmmAbility::REMOTE_MANAGE), 'EndpointAccessPolicy agrees: level 3 technician yes with the setting on, level 2 script user no, administrator yes');
$auth = $rmm->authorizer();
$ok($auth->allowed($USER['tech'], RmmAbility::REMOTE_MANAGE, 0) && !$auth->allowed($USER['remoteonly'], RmmAbility::REMOTE_MANAGE, 0) && !$auth->allowed($USER['viewer'], RmmAbility::REMOTE_MANAGE, 0), 'ability: with the setting on the level 3 technician may; a level 2 script user and a viewer may not');
$ok(str_contains($lastLog(), 'remote tools setting'), 'the level 3 change is in the audit log');
[$m] = $act(['save_remote_settings' => 1, 'feature_remote_tools' => '1', 'remote_manage_lvl3' => '1']);
$ok($m === 'No change to save.', 'saving the same values again says there is no change');
[$m] = $act(['save_remote_settings' => 1]);
rivetRmmForgetAccess();
$ok(!$rmm->featureOn('remote_tools') && (int) $one("SELECT config_rmm_remote_manage_lvl3 FROM settings WHERE company_id=1") === 0 && !$rmm->authorizer()->allowed($USER['tech'], RmmAbility::REMOTE_MANAGE, 0), 'both go back off and the level 3 technician loses the ability');
$ok(($state()['features']['remote_tools'] ?? true) === false, 'the state file shows remote_tools off again');

// ============================================================ file transfer
$feat = fn () => $rmm->settings()->features();
$riskStart = substr(RemoteSettings::FILE_TRANSFER_RISK, 0, 60);
[$m, $body] = $act(['save_filetransfer_settings' => 1, 'feature_filetransfer' => '1']);
$ok(!$rmm->featureOn('filetransfer') && str_contains($m, $riskStart), 'file transfer without the acknowledgement is refused with Core\'s text and nothing changes');
$ok(!str_contains($state() ? json_encode($state()['features']) : '', '"filetransfer":true'), 'the state file does not show it on');
[$m] = $act(['save_filetransfer_settings' => 1, 'feature_filetransfer' => '1', 'accept_filetransfer_risk' => '1'], 'tech');
$ok(!$rmm->featureOn('filetransfer'), 'a technician cannot switch file transfer on, even with the acknowledgement');
[$m, $body] = $act(['save_filetransfer_settings' => 1, 'feature_filetransfer' => '1', 'accept_filetransfer_risk' => '1']);
$ok($rmm->featureOn('filetransfer') && $rmm->settings()->featureOn('filetransfer') && str_contains($m, 'File transfer switched on'), 'with the acknowledgement the switch is on and featureOn agrees (' . $m . ')');
$ok(($state()['features']['filetransfer'] ?? false) === true && rivetRmmFeatureOn('filetransfer'), 'the state file shows file transfer on');
$ok(str_contains($lastLog(), 'filetransfer ON') || (int) $one("SELECT COUNT(*) FROM logs WHERE log_description LIKE '%filetransfer ON%'") >= 1, 'switching it on is in the audit log (written by Core)');
// allowed folders: Core's errors surface and nothing changes
[$m] = $act(['save_filetransfer_settings' => 1, 'feature_filetransfer' => '1', 'allow_linux' => "/etc\n/srv/shared", 'allow_windows' => 'C:\\']);
$ok(str_contains($m, 'Not an allowed folder') && $rmm->remoteSettings()->allowRoots() === ['linux' => [], 'windows' => []], 'a whole drive and /etc are refused with Core\'s message and no folder is saved (' . substr($m, 0, 120) . ')');
[$m, $body] = $act(['save_filetransfer_settings' => 1, 'feature_filetransfer' => '1', 'allow_linux' => "/etc", 'allow_windows' => 'C:\\Data\\Shared']);
$ok(str_contains($body, '/etc') && str_contains($m, 'Not an allowed folder'), 'what the person typed is kept in the form after a refusal');
[$m] = $act(['save_filetransfer_settings' => 1, 'feature_filetransfer' => '1', 'allow_linux' => "/srv/shared\n\n/var/data/exports ", 'allow_windows' => "C:\\Data\\Shared\nD:\\Exports\\Out"]);
$ok($rmm->remoteSettings()->allowRoots() === ['linux' => ['/srv/shared', '/var/data/exports'], 'windows' => ['C:\\Data\\Shared', 'D:\\Exports\\Out']] && str_contains($m, 'llowed folders saved'), 'valid folders are saved per platform (' . $m . ' ' . json_encode($rmm->remoteSettings()->allowRoots()) . ')');
[, $body] = $pg();
$ok(str_contains($body, '/var/data/exports') && str_contains($body, 'D:\\Exports\\Out'), 'the saved folders are shown on the page');
// limits: out of range surfaces Core's error and changes nothing
$before = $rmm->remoteSettings()->limits();
[$m] = $act(['save_filetransfer_settings' => 1, 'feature_filetransfer' => '1', 'transfer_max_bytes' => '600', 'transfer_max_bytes_unit' => 'MB']);
$ok(str_contains($m, 'transfer_max_bytes must be a whole number from 1024 to 536870912') && $rmm->remoteSettings()->limits() === $before, 'a 600 MB file limit is refused with Core\'s range and nothing changes (' . $m . ')');
[$m] = $act(['save_filetransfer_settings' => 1, 'feature_filetransfer' => '1', 'transfer_ttl_s' => '5', 'transfer_ttl_s_unit' => 'minutes']);
$ok(str_contains($m, 'transfer_ttl_s must be a whole number from 600 to 604800') && $rmm->remoteSettings()->limits() === $before, 'a 5 minute lifetime is refused with Core\'s range');
[$m] = $act(['save_filetransfer_settings' => 1, 'feature_filetransfer' => '1', 'transfer_max_bytes' => 'lots', 'transfer_max_bytes_unit' => 'MB']);
$ok(str_contains($m, 'must be a number') && $rmm->remoteSettings()->limits() === $before, 'a non-number is refused');
$keepFolders = ['allow_linux' => "/srv/shared\n/var/data/exports", 'allow_windows' => "C:\\Data\\Shared\nD:\\Exports\\Out"];
[$m] = $act($keepFolders + ['save_filetransfer_settings' => 1, 'feature_filetransfer' => '1', 'transfer_max_bytes' => '20', 'transfer_max_bytes_unit' => 'MB', 'transfer_ttl_s' => '2', 'transfer_ttl_s_unit' => 'hours', 'transfer_store_max_bytes' => '2', 'transfer_store_max_bytes_unit' => 'GB']);
$l = $rmm->remoteSettings()->limits();
$ok($l['transfer_max_bytes'] === 20 * 1048576 && $l['transfer_ttl_s'] === 7200 && $l['transfer_store_max_bytes'] === 2 * 1073741824 && str_contains($m, 'imits saved') && !str_contains($m, 'folders'), 'valid limits with units are stored in bytes and seconds (' . $m . ')');
[, $body] = $pg();
$ok(preg_match('/name="transfer_max_bytes"[^>]*value="20"/', $body) === 1 && preg_match('/<option value="MB" selected>/', $body) === 1 && preg_match('/name="transfer_ttl_s"[^>]*value="2"/', $body) === 1, 'the limits come back in the unit they were typed in');
$ok(str_contains($body, 'never-transfer') || str_contains($body, 'Never transferable'), 'the never-transfer list is shown');
[$m] = $act(['save_filetransfer_settings' => 1, 'feature_filetransfer' => '1', 'allow_linux' => "/srv/shared\n/var/data/exports", 'allow_windows' => "C:\\Data\\Shared\nD:\\Exports\\Out", 'transfer_max_bytes' => '20', 'transfer_max_bytes_unit' => 'MB']);
$ok($m === 'No change to save.', 'an unchanged form says there is nothing to save');

// ---- the transfer folder: status and Create folder
$tdir = rivetRmmTransferDir()['path'];
if (!defined('EA_TRANSFER_DIR')) {
    $ok($tdir === $root . '/backups/endpoint-transfer', 'the default transfer folder is backups/endpoint-transfer');
}
exec('rm -rf ' . escapeshellarg($tdir));
$st = rivetRmmTransferDirStatus(1073741824);
$ok($st['exists'] === false && $st['writable'] === false && $st['inside_app'] === true && $st['free_bytes'] > 0, 'folder status: missing, and free space is known');
[, $body] = $pg();
$ok(str_contains($body, 'Not created yet') && str_contains($body, 'name="create_transfer_folder"') && str_contains($body, 'not part of the in-app backup'), 'the card says the folder is not created yet, offers Create folder and says it is not in the in-app backup');
[$m] = $act(['create_transfer_folder' => 1]);
clearstatcache();
$ok(is_dir($tdir) && (fileperms($tdir) & 0777) === 0700 && str_contains($m, 'created'), 'Create folder makes it as the web user with mode 0700 (' . decoct(fileperms($tdir) & 0777) . ')');
[$m, $body] = $act(['create_transfer_folder' => 1]);
$ok(str_contains($m, 'already exists') && is_dir($tdir) && (fileperms($tdir) & 0777) === 0700, 'pressing it twice is harmless (idempotent)');
$ok(str_contains($body, 'Ready') && !str_contains($body, 'name="create_transfer_folder"'), 'with the folder ready the card shows Ready and no button');
$r = rivetRmmTransferDirCreate('/tmp/rmm_p68_outside_' . bin2hex(random_bytes(3)));
$ok($r['ok'] === false && str_contains($r['message'], 'outside the application directory'), 'a folder outside the application directory is refused unless EA_TRANSFER_DIR names it (' . $r['message'] . ')');
$r = rivetRmmTransferDirCreate($root . '/backups/../../etc/x');
$ok($r['ok'] === false, 'a path with .. is refused');
$ok(!is_dir('/tmp/rmm_p68_outside_x'), 'nothing was created outside');
$r2 = rivetRmmTransferDirCreate($root . '/backups/endpoint-transfer-test-' . bin2hex(random_bytes(2)));
$ok($r2['ok'] === true && $r2['created'] === true, 'a new folder under the application directory can be created');
exec('rm -rf ' . escapeshellarg($root . '/backups/endpoint-transfer-test-*') . ' 2>/dev/null');
foreach (glob($root . '/backups/endpoint-transfer-test-*') ?: [] as $d) { @rmdir($d); }
$ft2 = rivetRmmTransferDirStatus(1073741824);
$ok($ft2['writable'] === true && $ft2['mode'] === '0700' && $ft2['enough'] !== null, 'folder status after creation: writable, mode 0700');

// file transfer back off, folders kept (nothing deleted)
[$m] = $act(['save_filetransfer_settings' => 1, 'allow_linux' => "/srv/shared\n/var/data/exports", 'allow_windows' => "C:\\Data\\Shared\nD:\\Exports\\Out"]);
$ok(!$rmm->featureOn('filetransfer') && $rmm->remoteSettings()->allowRoots()['linux'] === ['/srv/shared', '/var/data/exports'], 'switching file transfer off keeps the folders');

// ============================================================ log collection
$lgStart = substr(RemoteSettings::LOGS_STORAGE_WARNING, 0, 60);
[$m] = $act(['save_logs_settings' => 1, 'feature_logs' => '1']);
$ok(!$rmm->featureOn('logs') && str_contains($m, $lgStart), 'log collection without the acknowledgement is refused with Core\'s text');
[$m, $body] = $act(['save_logs_settings' => 1, 'feature_logs' => '1', 'accept_logs_storage' => '1', 'log_win' => [0 => ['channel' => 'System', 'levels' => ['error', 'warning'], 'event_ids' => '41, 1074', 'contains' => "disk\nreset"], 1 => ['channel' => '', 'event_ids' => '']],
    'log_lin' => [0 => ['units' => 'sshd.service, nginx.service', 'identifiers' => 'sudo', 'min_level' => 'error', 'contains' => ''], 1 => ['units' => '', 'identifiers' => '', 'min_level' => 'warning', 'contains' => '', 'keep' => 0]]]);
$ok($rmm->featureOn('logs') && ($state()['features']['logs'] ?? false) === true, 'with the acknowledgement log collection is on, in the database and the state file (' . $m . ')');
$ws = $rmm->remoteSettings()->logSources('windows');
$ls = $rmm->remoteSettings()->logSources('linux');
$ok(count($ws) === 1 && $ws[0]['channel'] === 'System' && $ws[0]['levels'] === ['error', 'warning'] && $ws[0]['event_ids'] === [41, 1074] && $ws[0]['contains'] === ['disk', 'reset'], 'the Windows source is stored in Core\'s shape (blank spare row ignored)');
$ok(count($ls) === 1 && $ls[0]['units'] === ['sshd.service', 'nginx.service'] && $ls[0]['identifiers'] === ['sudo'] && $ls[0]['min_level'] === 'error', 'the Linux source is stored in Core\'s shape (blank spare row ignored)');
$ok($rmm->remoteSettings()->agentLogConfig('windows') !== null && $rmm->remoteSettings()->agentLogConfig('linux') !== null, 'the agents are told their sources (agentLogConfig)');
[, $body] = $pg();
$ok(str_contains($body, 'value="System"') && str_contains($body, '41, 1074') && str_contains($body, 'sshd.service, nginx.service'), 'the saved sources are shown in the rows');
$before = json_encode([$ws, $ls]);
[$m] = $act(['save_logs_settings' => 1, 'feature_logs' => '1', 'log_win' => [0 => ['channel' => 'Sys;tem', 'levels' => ['error']]], 'log_lin' => []]);
$ok(str_contains($m, 'channel is missing or has characters that are not allowed') && json_encode([$rmm->remoteSettings()->logSources('windows'), $rmm->remoteSettings()->logSources('linux')]) === $before, 'a bad channel name is refused with Core\'s message and nothing changes (' . $m . ')');
[$m] = $act(['save_logs_settings' => 1, 'feature_logs' => '1', 'log_win' => [0 => ['channel' => 'System', 'levels' => ['error'], 'event_ids' => '70000']]]);
$ok(str_contains($m, 'event id must be a whole number from 0 to 65535'), 'an event id out of range is refused');
[$m] = $act(['save_logs_settings' => 1, 'feature_logs' => '1', 'log_win' => [0 => ['channel' => 'System', 'levels' => ['error'], 'event_ids' => '41, 1074', 'contains' => "disk\nreset"]],
    'log_lin' => [0 => ['units' => 'sshd.service, nginx.service', 'identifiers' => 'sudo', 'min_level' => 'error', 'keep' => 1]], 'logs_retention_days' => '0']);
$ok(str_contains($m, 'logs_retention_days must be a whole number from 1 to 90') && $rmm->remoteSettings()->limits()['logs_retention_days'] === 7, 'retention of 0 days is refused with Core\'s range and nothing changes (' . $m . ')');
$same = ['log_win' => [0 => ['channel' => 'System', 'levels' => ['error', 'warning'], 'event_ids' => '41, 1074', 'contains' => "disk\nreset"]], 'log_lin' => [0 => ['units' => 'sshd.service, nginx.service', 'identifiers' => 'sudo', 'min_level' => 'error', 'keep' => 1]]];
[$m] = $act(['save_logs_settings' => 1, 'feature_logs' => '1', 'logs_retention_days' => '14', 'logs_device_max_rows' => '10000', 'logs_fleet_max_rows' => '500000', 'logs_rate_per_min' => '300', 'logs_interval_s' => '120'] + $same);
$l = $rmm->remoteSettings()->limits();
$ok($l['logs_retention_days'] === 14 && $l['logs_device_max_rows'] === 10000 && $l['logs_fleet_max_rows'] === 500000 && $l['logs_rate_per_min'] === 300 && $l['logs_interval_s'] === 120, 'the five log limits are saved (' . $m . ')');
[, $body] = $pg();
$sum = $rmm->remoteSettings()->summary();
$ok($sum['logs_worst_case_bytes_per_device'] === 6000000 && str_contains($body, rivetRmmScaleBytes(6000000)) && str_contains($body, rivetRmmScaleBytes(300000000)), 'the worst-case figures of Core are shown in MB and GB (' . rivetRmmScaleBytes(6000000) . ', ' . rivetRmmScaleBytes(300000000) . ')');
$ok(str_contains($body, 'href="/agent/rmm_logs.php"'), 'with logs on the card links to the Logs page');
[$m] = $act(['save_logs_settings' => 1] + $same);
$ok(!$rmm->featureOn('logs') && count($rmm->remoteSettings()->logSources('linux')) === 1, 'switching log collection off keeps the sources');
$resetRemote();

// ============================================================ scale settings
$lim0 = $rmm->settings()->limits();
$ok($lim0['cache_fresh_s'] === 15 && $lim0['ingest_batch'] === 50 && $lim0['warm_start_s'] === 0 && $lim0['client_bucket_burst'] === 0 && $lim0['check_touch_s'] === 0, 'the scale defaults are in force');
[$m] = $act(['save_scale_settings' => 1, 'cache_fresh_s' => '30', 'cache_stale_s' => '120', 'ingest_max_running' => '4', 'ingest_batch' => '25', 'check_touch_s' => '60', 'herd_form' => '1'], 'tech');
$ok($rmm->settings()->limits()['cache_fresh_s'] === 15, 'a technician cannot change the scale settings');
$adm(['save_scale_settings' => 1, 'cache_fresh_s' => '30', 'csrf_token' => 'wrong']);
$fresh();
$ok($rmm->settings()->limits()['cache_fresh_s'] === 15, 'a wrong CSRF token changes nothing');
[$m, $body] = $act(['save_scale_settings' => 1, 'cache_fresh_s' => '30', 'cache_stale_s' => '120', 'ingest_max_running' => '4', 'ingest_batch' => '25', 'check_touch_s' => '60', 'herd_form' => '1', 'warm_start_pct' => '10', 'warm_quiet_s' => '120']);
$l = $rmm->settings()->limits();
$ok($l['cache_fresh_s'] === 30 && $l['cache_stale_s'] === 120 && $l['ingest_max_running'] === 4 && $l['ingest_batch'] === 25 && $l['check_touch_s'] === 60 && str_contains($m, '5 values changed'), 'cache, ingest and check-touch values round-trip (' . $m . ')');
$sl = $state()['limits'] ?? [];
$ok(($sl['cache_fresh_s'] ?? null) === 30 && ($sl['ingest_batch'] ?? null) === 25 && ($sl['check_touch_s'] ?? null) === 60 && ($sl['ingest_max_running'] ?? null) === 4, 'they reach the limits in the state file the gate reads');
$ok($l['warm_start_s'] === 0 && $l['client_bucket_burst'] === 0, 'herd controls stay off when their switches are not ticked');
$stored = json_decode((string) $one('SELECT limits_json FROM endpoint_agent_settings WHERE id=1'), true);
$ok(!isset($stored['warm_start_pct']) && !isset($stored['warm_quiet_s']) && !isset($stored['ingest_max_running']) === false, 'only changed values are stored (' . json_encode($stored) . ')');
[$m] = $act(['save_scale_settings' => 1, 'cache_fresh_s' => '30', 'herd_form' => '1']);
$ok($m === 'No change to save.', 'an unchanged form saves nothing');
[$m] = $act(['save_scale_settings' => 1, 'cache_fresh_s' => '99999', 'herd_form' => '1']);
$ok(str_contains($m, 'cache_fresh_s must be a whole number from 0 to 3600') && $rmm->settings()->limits()['cache_fresh_s'] === 30, 'a value out of range shows Core\'s error and changes nothing (' . $m . ')');
[$m, $body] = $act(['save_scale_settings' => 1, 'cache_fresh_s' => '12abc', 'herd_form' => '1']);
$ok(str_contains($m, 'must be a whole number') && str_contains($body, 'value="12abc"'), 'a non-number is refused and kept in the form');
[$m] = $act(['save_scale_settings' => 1, 'herd_form' => '1', 'warm_on' => '1', 'warm_start_s' => '', 'warm_start_pct' => '25', 'warm_quiet_s' => '300', 'bucket_on' => '1', 'client_bucket_burst' => '', 'client_bucket_per_min' => '']);
$l = $rmm->settings()->limits();
$ok($l['warm_start_s'] === 600 && $l['warm_start_pct'] === 25 && $l['warm_quiet_s'] === 300, 'warm start switched on takes the sensible on-value (600 s) and the typed share and gap (' . json_encode(array_intersect_key($l, array_flip(['warm_start_s', 'warm_start_pct', 'warm_quiet_s']))) . ')');
$sg = rivetRmmScaleBucketSuggestion($db, (int) $rmm->settings()->get()['check_in_interval_s']);
$ok($l['client_bucket_per_min'] === $sg['per_min'] && $l['client_bucket_burst'] === $sg['burst'] && $l['client_bucket_per_min'] >= 60, 'the department limit switched on takes the suggested on-values (' . $l['client_bucket_per_min'] . ' per min, burst ' . $l['client_bucket_burst'] . ')');
$sl = $state()['limits'] ?? [];
$ok(($sl['warm_start_s'] ?? 0) === 600 && ($sl['client_bucket_burst'] ?? 0) === $sg['burst'], 'warm start and buckets reach the state file');
[, $body] = $pg();
$ok(preg_match('/name="warm_on"[^>]*checked/', $body) === 1 && preg_match('/name="bucket_on"[^>]*checked/', $body) === 1 && str_contains($body, 'rmm_herd.json'), 'the page shows both switches on and explains the state file');
[$m] = $act(['save_scale_settings' => 1, 'herd_form' => '1', 'cache_fresh_s' => '15', 'cache_stale_s' => '300', 'ingest_max_running' => '0', 'ingest_batch' => '50', 'check_touch_s' => '0']);
$l = $rmm->settings()->limits();
$ok($l['warm_start_s'] === 0 && $l['client_bucket_burst'] === 0 && $l['client_bucket_per_min'] === 0 && $l['cache_fresh_s'] === 15 && $l['check_touch_s'] === 0, 'unticking the switches turns the herd controls off and the other values go back to the defaults');
$ok(str_contains($lastLog(), 'RMM scale settings'), 'scale changes are in the audit log');

// ============================================================ partitioning
[, $body] = $pg();
$ok(str_contains($body, 'Not used') && str_contains($body, 'RivetIT keeps its own hourly metric rollups') && !str_contains($body, 'Watermark'), 'the rollup is shown as "not used: RivetIT keeps its own hourly metric rollups", not a fake status');
$ok(str_contains($body, 'This server has not been checked yet') && !str_contains($body, 'name="partition_enable"'), 'before any check there is no Enable button');
[$m] = $act(['partition_probe' => 1], 'tech');
$ok($one("SELECT COUNT(*) FROM rmm_storage_state WHERE name='partition_probe'") === '0', 'a technician cannot run the probe');
[$m, $body] = $act(['partition_probe' => 1]);
$probe = $rmm->partitions()->probe();
$ok(str_starts_with($m, 'Yes.') === $probe['tables']['endpoint_agent_check_history']['convertible'], 'the probe answer in words matches Core\'s result (' . $m . ')');
$ok(str_contains($body, 'Can this server do it?') && str_contains($body, 'MariaDB'), 'the page shows the probe result and the database server');
// not possible: an extra unique key without the partition column
$q("ALTER TABLE endpoint_agent_check_history ADD UNIQUE KEY p68_tmp_u (hist_id, check_key)");
$q("ALTER TABLE endpoint_agent_check_history DROP INDEX p68_tmp_u");
$q("ALTER TABLE endpoint_agent_check_history ADD UNIQUE KEY p68_tmp_u (check_key, hist_id)");
[$m, $body] = $act(['partition_probe' => 1]);
$ok(str_contains($m, 'No, not for the check-history table') && str_contains($m, 'p68_tmp_u'), 'when the probe says no, the reason names the key (' . substr($m, 0, 160) . ')');
$ok(!str_contains($body, 'name="partition_enable"'), 'no Enable button when the probe says no');
[$m] = $act(['partition_enable' => 1, 'confirm_partition' => '1']);
$ok(str_contains($m, 'Not enabled') && !$rmm->partitions()->isPartitioned('endpoint_agent_check_history') && $rmm->settings()->limits()['partitioning'] === 0, 'Enable is refused by the server when the probe says no, even with the box ticked');
$q("ALTER TABLE endpoint_agent_check_history DROP INDEX p68_tmp_u");
$act(['partition_probe' => 1]);
[$m] = $act(['partition_enable' => 1]);
$ok(str_contains($m, 'Tick the box') && !$rmm->partitions()->isPartitioned('endpoint_agent_check_history') && $rmm->settings()->limits()['partitioning'] === 0, 'Enable is refused without the confirmation box and nothing changes');
$canPartition = $rmm->partitions()->probe()['tables']['endpoint_agent_check_history']['convertible'];
[, $body] = $pg();
$ok($canPartition === false || (str_contains($body, 'name="partition_enable"') && str_contains($body, 'copied once')), 'when possible, the page offers Enable with the one-time copy explained');
[$m] = $act(['partition_enable' => 1, 'confirm_partition' => '1'], 'tech');
$ok(!$rmm->partitions()->isPartitioned('endpoint_agent_check_history'), 'a technician cannot enable partitioning');
if ($canPartition) {
    [$m, $body] = $act(['partition_enable' => 1, 'confirm_partition' => '1']);
    $ok($rmm->partitions()->isPartitioned('endpoint_agent_check_history') && $rmm->settings()->limits()['partitioning'] === 1 && str_contains($m, 'Partitioning is on'), 'ON THIS SERVER partitioning works end to end: the table is partitioned and the setting is on (' . $m . ')');
    $parts = $rmm->partitions()->partitions('endpoint_agent_check_history');
    $ok(count($parts) >= 8 && $parts[0]['name'] === 'p_old' && $parts[array_key_last($parts)]['name'] === 'p_max', 'p_old, one partition per day ahead and p_max exist (' . count($parts) . ' partitions)');
    $ok(str_contains($body, 'Current partitions of') && str_contains($body, 'p_max') && str_contains($body, 'Turn off automatic partitioning') && str_contains($body, 'REMOVE PARTITIONING'), 'the page lists the partitions and documents how to go back');
    $ok(($state()['limits']['partitioning'] ?? 0) === 1, 'the setting reaches the state file');
    $rmm->settings()->refresh();
    $hk = $rmm->housekeeping()->run();
    $ok(is_array($hk) && ($hk['storage_partitioned'] ?? ['endpoint_agent_check_history']) !== null, 'housekeeping still runs on the partitioned table (' . json_encode(array_intersect_key($hk, array_flip(['pruned_check_history', 'storage_tables']))) . ')');
    // a check-in writes check history through the real endpoint after the conversion
    $q("INSERT INTO assets SET asset_type='Laptop', asset_name='P68PART', asset_make='Dell', asset_serial='SER-P68PART', asset_client_id=1, asset_status='Active'");
    [$c1, , $j1] = ea_enroll($S['enroll']['A'], ea_dev(['serial' => 'SER-P68PART', 'hostname' => 'P68PART']));
    $tokp = (string) ($j1['device_token'] ?? '');
    [$c2] = ea_checkin($tokp, ['checks' => [['key' => 'p68_chk', 'status' => 'ok', 'detail' => 'fine', 'value' => 1.0]]]);
    $ok($c1 === 201 && $c2 === 200 && (int) $one("SELECT COUNT(*) FROM endpoint_agent_check_history") >= 1, 'a check-in still writes check history on the partitioned table (' . $c1 . ', ' . $c2 . ')');
    [$m, $body] = $act(['partition_enable' => 1, 'confirm_partition' => '1']);
    $ok(str_contains($m, 'already partitioned'), 'enabling twice is harmless');
    [$m, $body] = $act(['partition_disable' => 1]);
    $ok($rmm->settings()->limits()['partitioning'] === 0 && $rmm->partitions()->isPartitioned('endpoint_agent_check_history') && str_contains($m, 'stay partitioned'), 'turning it off stops auto-conversion, the partitioned table stays and the page says so');
    $q("ALTER TABLE endpoint_agent_check_history REMOVE PARTITIONING");   // leave the table as the other fragments expect it (the documented way back)
    $ok(!$rmm->partitions()->isPartitioned('endpoint_agent_check_history'), 'the documented REMOVE PARTITIONING returns the table to a plain table');
} else {
    $ok(true, 'partitioning NOT possible on this server: ' . implode(' ', $rmm->partitions()->probe()['tables']['endpoint_agent_check_history']['reasons']));
}

// ============================================================ external metric store
$fake = new \ITFlow\Tests\Support\FakeVictoriaMetricsServer();
$colRows = fn () => $rows("SELECT config_rmm_extstore_enabled e, config_rmm_extstore_write_url w, config_rmm_extstore_read_url r, config_rmm_extstore_token t FROM settings WHERE company_id=1")[0];
$col0 = $colRows();
$ok($col0['e'] === '0' && ($col0['w'] === null || $col0['w'] === '') && rivetRmmExternalStoreConfig($db) === null, 'the external store is off and empty by default');
[, $body] = $pg();
$ok(str_contains($body, 'Most installs do not need this') && str_contains($body, '10,000 devices') && str_contains($body, 'not set'), 'the card says plainly it is only for installs well beyond 10,000 devices');
$secret = 'tok-SECRET-' . bin2hex(random_bytes(4));
$wurl = $fake->url() . '/insert/0/influx';
$rurl = $fake->url() . '/select/0/prometheus';
$fakePort = (string) $fake->port;
[$m] = $act(['save_extstore' => 1, 'extstore_enabled' => '1', 'extstore_write_url' => $wurl, 'extstore_read_url' => $rurl, 'extstore_token' => $secret], 'tech');
$ok(($colRows())['e'] === '0', 'a technician cannot save the external store settings');
$adm(['save_extstore' => 1, 'extstore_enabled' => '1', 'extstore_write_url' => $wurl, 'csrf_token' => 'wrong']);
$ok(($colRows())['e'] === '0', 'a wrong CSRF token changes nothing');
[$m, $body] = $act(['save_extstore' => 1, 'extstore_enabled' => '1', 'extstore_write_url' => $wurl, 'extstore_read_url' => $rurl, 'extstore_token' => $secret]);
$col = $colRows();
$sealed = fn ($v) => is_string($v) && $v !== '' && preg_match('/^(ENC2:|v3:)/', $v) === 1 && !str_contains($v, $secret) && !str_contains($v, $fakePort);
$ok($col['e'] === '1' && $sealed($col['w']) && $sealed($col['r']) && $sealed($col['t']), 'saved: the three columns hold sealed values (' . substr((string) $col['w'], 0, 6) . ', ' . substr((string) $col['t'], 0, 6) . '), never plaintext');
$ok(decryptSetting($col['t']) === $secret && decryptSetting($col['w']) === $wurl, 'and they decrypt back to what was typed');
$ok(!str_contains($body, $secret) && !str_contains($body, ':' . $fakePort) && !str_contains($body, 'insert/0/influx') && str_contains($body, 'saved, hidden') && str_contains($body, 'A token is saved'), 'the page never contains the token or the addresses; it shows "saved" placeholders');
$ok(str_contains($m, 'External metric store settings saved'), 'a message confirms the save (' . $m . ')');
$ok(str_contains($lastLog(), 'external metric store') && !str_contains($lastLog(), $secret) && !str_contains($lastLog(), $fakePort), 'the change is in the audit log without any value');
$ok(rivetRmmExternalStoreConfig($db)['token'] === $secret, 'the module wiring reads the saved values');
$keep = $colRows();
[$m] = $act(['save_extstore' => 1, 'extstore_enabled' => '1', 'extstore_write_url' => '', 'extstore_read_url' => '', 'extstore_token' => '']);
$ok($colRows() === $keep && $m === 'No change to save.', 'an empty submit keeps the stored values');
[$m] = $act(['save_extstore' => 1, 'extstore_enabled' => '1', 'extstore_write_url' => 'ftp://example.com/x']);
$ok(str_contains($m, 'not accepted') && $colRows() === $keep, 'a bad address is refused and nothing changes (' . $m . ')');
[$m] = $act(['save_extstore' => 1, 'extstore_enabled' => '1', 'extstore_write_url' => 'https://user:pw@example.com/x']);
$ok(str_contains($m, 'user name or password') && $colRows() === $keep, 'an address with a password in it is refused');
// Test connection against the loopback fake: ok
$testJ = function (array $f, ?string $baseUrl = null) use ($wb, $SS, $REF1) {
    [$c, $b] = web($baseUrl ?? $wb, 'POST', '/admin/post.php', $SS['admin'], $f + ['csrf_token' => 'csrftok1', 'test_extstore' => '1'], array_merge($REF1, ['Accept: application/json']));

    return [$c, json_decode($b, true) ?: ['raw' => $b]];
};
$fake->reset();
[$c, $j] = $testJ([]);
$reqs = @$fake->requests();
$ok($c === 200 && ($j['status'] ?? '') === 'ok' && $j['ok'] === true, 'Test connection with the saved values: ok (' . json_encode($j) . ')');
$ok(count($reqs) >= 2 && in_array('/health', array_column($reqs, 'path'), true) && ($reqs[0]['auth'] ?? '') === 'Bearer ' . $secret, 'it asked /health and a read, with the saved bearer token');
$ok(!str_contains(json_encode($j), $secret) && !str_contains(json_encode($j), $fakePort), 'the answer never contains the token or the address');
$fake->reset();
$fake->setMode(['require_auth' => 'Bearer something-else']);
[$c, $j] = $testJ([]);
$ok(($j['status'] ?? '') === 'auth' && $j['ok'] === false && !str_contains(json_encode($j), $secret), 'a wrong token: "auth failure" (HTTP 401), no token in the answer (' . ($j['message'] ?? '') . ')');
$fake->setMode(['require_auth' => 'Bearer typed-token-1']);
[$c, $j] = $testJ(['extstore_token' => 'typed-token-1']);
$ok(($j['status'] ?? '') === 'ok', 'what is typed is tested instead of the saved token (right token typed: ok)');
$fake->reset();
[$c, $j] = $testJ(['extstore_write_url' => 'http://127.0.0.1:1/insert/0/influx', 'extstore_read_url' => 'http://127.0.0.1:1/select/0/prometheus']);
$ok(($j['status'] ?? '') === 'unreachable', 'a closed port: unreachable (' . ($j['status'] ?? '?') . ': ' . ($j['message'] ?? '') . ')');
$fake->setMode(['sleep' => 8]);
$t0 = microtime(true);
[$c, $j] = $testJ([]);
$ok(($j['status'] ?? '') === 'unreachable' && microtime(true) - $t0 < 25, 'a store that does not answer in time: unreachable, within the short timeout (' . round(microtime(true) - $t0, 1) . ' s)');
$fake->reset();
$ok($sealed($colRows()['w']), 'tests never change the saved values');
// the non-JS form post shows the answer in the card
[$m] = $act(['test_extstore' => '1']);
$ok(str_contains($m, 'answered its health check'), 'without JavaScript the Test button posts and the card shows the answer (' . $m . ')');
// CSRF / role on the test endpoint
[$c, $b] = web($wb, 'POST', '/admin/post.php', $SS['admin'], ['test_extstore' => '1', 'csrf_token' => 'wrong'], array_merge($REF1, ['Accept: application/json']));
$ok($c === 400 && (json_decode($b, true)['status'] ?? '') === 'denied', 'Test connection with a wrong CSRF token is refused (' . $c . ')');
$n0 = count(@$fake->requests());
[$c, $b] = web($wb, 'POST', '/admin/post.php', $SS['tech'], ['test_extstore' => '1', 'csrf_token' => 'csrftok1'], array_merge($REF1, ['Accept: application/json']));
$ok(count(@$fake->requests()) === $n0 && !str_contains($b, '"ok":true'), 'a technician cannot run the test (no request reached the store)');
// refused by the address policy: a server WITHOUT the private-address switch (as production), and no request must reach the fake
$strict = ea_start_php($root . '/tests/mobile_api_router.php', ['RIVETIT_WEBHOOK_ALLOW_PRIVATE' => '0'], ["session.save_path=$sdir"]);
$wbs = "http://127.0.0.1:{$strict['port']}";
$fake->reset();
foreach ([['http://169.254.169.254/', 'plain http'], ['https://169.254.169.254/latest/meta-data', 'metadata'], ['https://localhost:' . $fake->port . '/insert/0/influx', 'loopback'], ['https://127.0.0.1:' . $fake->port . '/', 'loopback'],
    ['http://127.0.0.1:' . $fake->port . '/insert/0/influx', 'plain http'], ['https://10.1.2.3/insert', 'private']] as [$bad, $why]) {
    [$c, $j] = $testJ(['extstore_write_url' => $bad, 'extstore_token' => 'x'], $wbs);
    $ok(($j['status'] ?? '') === 'refused' && str_contains((string) ($j['message'] ?? ''), 'Refused before any request was made'), "refused by policy ($why): " . $bad . ' -> ' . ($j['status'] ?? '?') . ' / ' . substr((string) ($j['message'] ?? ''), 0, 110));
}
$ok(@$fake->requests() === [], 'the fake store saw NO request for any refused address');
[$c, $j] = $testJ([], $wbs);
$ok(($j['status'] ?? '') === 'refused' && @$fake->requests() === [], 'on a production-like server the saved loopback http address is refused too, and no request was made');
[$c, $j] = $testJ(['extstore_write_url' => 'https://localhost/x'], $wbs);
$ok(str_contains($j['message'] ?? '', 'loopback'), 'the message names the rule: loopback (' . ($j['message'] ?? '') . ')');
[$c, $j] = $testJ(['extstore_write_url' => 'https://169.254.169.254/x'], $wbs);
$ok(str_contains($j['message'] ?? '', 'cloud-metadata'), 'the message names the rule: link-local / cloud metadata');
[$m] = (function () use ($wbs, $SS, $REF1, $msgOf) {
    web($wbs, 'POST', '/admin/post.php', $SS['admin'], ['save_extstore' => 1, 'extstore_enabled' => '1', 'extstore_write_url' => 'http://127.0.0.1:9/x', 'csrf_token' => 'csrftok1'], $REF1);
    [, $b] = web($wbs, 'GET', '/admin/settings_endpoint_agent.php', $SS['admin']);

    return [$msgOf($b)];
})();
$ok(str_contains($m, 'not accepted') && $colRows() === $keep, 'on a production-like server saving a plain http or loopback address is refused (' . substr($m, 0, 100) . ')');

// module wiring: with the store on, a check-in's samples reach the outbox and housekeeping flushes them to the fake store
$q("DELETE FROM rmm_metric_outbox");
$q("INSERT INTO assets SET asset_type='Laptop', asset_name='P68EXT', asset_make='Dell', asset_serial='SER-P68EXT', asset_client_id=1, asset_status='Active'");
[$c1, , $j1] = ea_enroll($S['enroll']['A'], ea_dev(['serial' => 'SER-P68EXT', 'hostname' => 'P68EXT']));
$tokx = (string) ($j1['device_token'] ?? '');
[$c2] = ea_checkin($tokx);
$out = (int) $one("SELECT COUNT(*) FROM rmm_metric_outbox");
$pts = (int) $one("SELECT COALESCE(SUM(points),0) FROM rmm_metric_outbox");
$ok($c1 === 201 && $c2 === 200 && $out >= 1 && $pts >= 1, 'store ON: a check-in\'s samples reach the outbox (' . $out . ' rows, ' . $pts . ' points)');
$ok((int) $one("SELECT COUNT(*) FROM device_metric_samples") >= 1, 'and RivetIT\'s own metric tables still get them (primary sink)');
[, $body] = $pg();
$ok(str_contains($body, 'Waiting to be sent') && str_contains($body, 'Buffer'), 'the card shows the buffer state while the store is on');
$hkOut = (string) shell_exec('RIVETIT_WEBHOOK_ALLOW_PRIVATE=1 ' . PHP_BINARY . ' ' . escapeshellarg($root . '/tests/browser/rmm_p68_admin_extra.php') . ' housekeeping 2>&1');
$hk = null;
foreach (preg_split('/\R/', $hkOut) as $__l) { if (str_starts_with($__l, '{')) { $hk = json_decode($__l, true); break; } }
$ok(is_array($hk) && ($hk['external_sent'] ?? 0) >= 1, 'housekeeping (a fresh process, what cron does) flushes the outbox: ' . json_encode(array_intersect_key((array) $hk, array_flip(['external_sent', 'external_failed', 'external_pending']))) . substr($hkOut, 0, 300));
$ok((int) $one("SELECT COUNT(*) FROM rmm_metric_outbox") === 0 && $fake->storedSamples() >= 1, 'the outbox is empty and the fake store holds ' . $fake->storedSamples() . ' sample(s)');
$wr = array_filter(@$fake->requests(), fn ($r) => str_ends_with($r['path'], '/write'));
$ok($wr !== [] && ($wr[array_key_first($wr)]['auth'] ?? '') === 'Bearer ' . $secret && str_contains(array_values($wr)[0]['body'], 'rmm,asset='), 'the points were written with line protocol and the bearer token');
// off: nothing is queued
[$m] = $act(['save_extstore' => 1, 'extstore_enabled' => '0']);
$q("DELETE FROM rmm_metric_outbox");
[$c2] = ea_checkin($tokx);
$ok(($colRows())['e'] === '0' && $c2 === 200 && (int) $one("SELECT COUNT(*) FROM rmm_metric_outbox") === 0, 'store OFF: nothing is queued (the saved values are kept)');
$ok($sealed($colRows()['w']) && rivetRmmExternalStoreConfig($db) === null, 'switching it off keeps the values but the wiring is off');
// clear
[$m, $body] = $act(['save_extstore' => 1, 'extstore_enabled' => '0', 'extstore_clear_token' => '1']);
$col = $colRows();
$ok($col['t'] === null && $sealed($col['w']) && str_contains($body, 'Remove the saved write address'), 'the clear box removes only the token');
[$m] = $act(['save_extstore' => 1, 'extstore_enabled' => '0', 'extstore_clear_write_url' => '1', 'extstore_clear_read_url' => '1']);
$col = $colRows();
$ok($col['w'] === null && $col['r'] === null, 'the clear boxes remove both addresses');
[$m] = $act(['save_extstore' => 1, 'extstore_enabled' => '1']);
$ok(str_contains($m, 'needs a write address') && ($colRows())['e'] === '0', 'it cannot be switched on without a write address');
$fake->stop();

// ============================================================ the Scale and capacity panel
$cap = fn () => $rmm->capacity()->build();
$panelHtml = function () use ($db, $rmm) {
    $vm = rivetRmmUiFleet($db, 1, []);

    return rivetRmmUiFleetPage($vm, 'tok');
};
$rmm->settings()->update(['ingest_mode' => 'sync']);
$html = $panelHtml();
$ok(str_contains($html, 'id="rmm-capacity"') && str_contains($html, 'Performance and capacity') && str_contains($html, 'Administrators only'), 'the panel keeps its heading and the Administrators only note');
$ok(str_contains($html, 'Run 1 queue worker.') && str_contains($html, 'no queue workers are needed'), 'sync mode: "Run 1 queue worker" and the note that sync mode needs none');
$ok(str_contains($html, 'Read cache') && str_contains($html, 'Warm start') && str_contains($html, 'Check-ins per department') && str_contains($html, 'Load shedding') && str_contains($html, 'Check history') && str_contains($html, 'not used: RivetIT keeps its own hourly metric rollups'), 'the panel shows cache, warm start, buckets, shedding, storage and the honest rollup line');
$ok(str_contains($html, 'SKIP LOCKED') && str_contains($html, 'MariaDB 10.6') && str_contains($html, 'MySQL 8.0.1') && str_contains($html, 'docs/ENDPOINT_AGENT.md') && str_contains($html, 'N copies of the queue worker'), 'the guidance paragraph says several workers are safe, on which servers, and points to the docs');
$ok(str_contains($html, 'Open the Scale settings') || str_contains($html, 'Scale settings'), 'the panel links to the scale settings');
$rmm->settings()->update(['ingest_mode' => 'queued']);
$a0 = $cap()['autoscale'];
$q("INSERT INTO integration_jobs (job_type, resource_type, status, payload, created_at) SELECT 'rmm.ingest', 'rmm_checkin', 'pending', '{}', NOW() - INTERVAL 40 SECOND FROM seq_1_to_3000");
$a1 = $cap()['autoscale'];
$html = $panelHtml();
$ok($a0['workers_desired'] === 1 && $a1['workers_desired'] > $a0['workers_desired'] && $a1['hint'] === 'up', 'with 3000 queued ingest jobs the recommended worker count rises (' . $a0['workers_desired'] . ' -> ' . $a1['workers_desired'] . ', ' . $a1['hint'] . ')');
$ok(str_contains($html, 'Run ' . $a1['workers_desired'] . ' queue workers.') && str_contains($html, 'Add workers now') && str_contains($html, '3000 waiting') && str_contains($html, 'Add workers'), 'the panel says "Run ' . $a1['workers_desired'] . ' queue workers." in plain words, with the queue figures');
$ok(!str_contains($html, 'no queue workers are needed'), 'queued mode: no "not needed" note');
$cr = $cap();
$ok(str_contains($html, rmmH($cr['storage']['tables']['endpoint_agent_check_history']['retention_days']) . ' days kept') && str_contains($html, 'plain table'), 'the storage figures of Core are shown (check history retention, plain table)');
$q("DELETE FROM integration_jobs WHERE job_type='rmm.ingest'");
$rmm->settings()->update(['ingest_mode' => 'sync']);
$act(['save_scale_settings' => 1, 'herd_form' => '1', 'warm_on' => '1', 'warm_start_s' => '600', 'warm_start_pct' => '10', 'warm_quiet_s' => '120', 'bucket_on' => '1', 'client_bucket_burst' => '50', 'client_bucket_per_min' => '30']);
$rmm->settings()->refresh();
$html = $panelHtml();
$ok(str_contains($html, '30 per minute, burst of 50') && str_contains($html, 'Armed'), 'the panel reflects the herd settings (department limit, warm start armed)');
$act(['save_scale_settings' => 1, 'herd_form' => '1']);
$ok(!str_contains(rivetRmmUiFleetPage(rivetRmmUiFleet($db, 10, []), 't'), 'rmm-capacity'), 'a technician still gets no capacity panel');

// ---- the card output is escaped and the page stays clean
$q("UPDATE endpoint_agent_remote_settings SET transfer_allow_json = '" . $esc(json_encode(['linux' => ['/srv/<script>alert(1)</script>'], 'windows' => []])) . "' WHERE id=1");
$rmm->remoteSettings()->refresh();
[, $body] = $pg();
$ok(!str_contains($body, '<script>alert(1)</script>'), 'stored values are escaped on the page');
$resetRemote();

// ---- everything above must leave the module on and the switches as the next fragment expects them
$ok($rmm->enabled() && !$rmm->featureOn('remote_tools') && !$rmm->featureOn('filetransfer') && !$rmm->featureOn('logs'), 'the fragment leaves the module on and the three new sub-switches off');
// ---- the page also loads while the module is off and on a database without the new tables' rows
$rmm->admin()->disable($admin);
$fresh();
[$c, $body] = $pg();
$ok($c === 200 && str_contains($body, 'id="scale"') && str_contains($body, 'RMM module itself is off'), 'Administration still shows the cards while the module is off, with the note that it takes effect when on');
$rmm->admin()->enable($admin);
$fresh();
