<?php
/*
 * "Allow Linux devices to enroll" (RivetIT 26.10.65): a setting in platform_settings (rmm_allow_linux, ON by default), read by includes/rmm_bootstrap.php; config.php's
 * EA_ALLOW_NON_WINDOWS stays an override that forces it on. Run with EA_TEST_NO_FORCE=1 so the scratch config.php does NOT define that constant:
 *   EA_TEST_NO_FORCE=1 RIVETIT_TEST_DB=1 ... php tests/rmm_rc25_linux.php
 */
if (getenv('EA_TEST_NO_FORCE') !== '1') { fwrite(STDERR, "set EA_TEST_NO_FORCE=1 (the scratch config must not force Linux on)\n"); exit(2); }
require __DIR__ . '/endpoint_agent_lib.php';
ea_reset();
$ok(!defined('EA_ALLOW_NON_WINDOWS'), 'the constant is not defined in this run');
$q("DELETE FROM platform_settings WHERE setting_key='rmm_allow_linux'");
$ok(\ITFlow\Platform\PlatformSettings::bool($db, 'rmm_allow_linux') === true, 'the setting is ON by default');
$U = ea_seed_users();
$tok = ea_token(1, 24, 20);
[$c, , $j] = ea_enroll($tok, ea_dev(['os' => 'linux', 'os_version' => 'Ubuntu 24.04', 'hostname' => 'LNX-ON']));
$ok($c === 200 || $c === 201, "default: a Linux device enrolls ($c " . json_encode($j) . ')');
$ok((string) $one("SELECT os FROM endpoint_agent_devices WHERE hostname='LNX-ON'") === 'linux', 'it is stored as linux');

// the administrator's switch over real HTTP
$sdir = sys_get_temp_dir() . '/ea_sessions_' . bin2hex(random_bytes(4)); mkdir($sdir);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
$web = ea_start_php($root . '/tests/mobile_api_router.php', ['RIVETIT_WEBHOOK_ALLOW_PRIVATE' => '1', 'EA_TEST_NO_FORCE' => '1'], ["session.save_path=$sdir"]);
$wb = "http://127.0.0.1:{$web['port']}";
$S = []; foreach ([1 => 'admin', 12 => 'viewer'] as $uid => $n) { $S[$n] = ea_forge_session($sdir, $uid); }
$ref = ['Referer: ' . $wb . '/admin/settings_endpoint_agent.php'];
$post = fn(array $f, string $who = 'admin') => web($wb, 'POST', '/admin/post.php', $S[$who], $f + ['csrf_token' => 'csrftok1'], $ref);
[$c, $b] = web($wb, 'GET', '/admin/settings_endpoint_agent.php', $S['admin']);
$ok($c === 200 && strpos($b, 'name="allow_linux"') !== false && strpos($b, 'Allow Linux devices to enroll') !== false && preg_match('/id="ea_allow_linux"[^>]* checked/', $b) === 1, 'the Overview tab shows the switch, ticked');
$post(['save_platforms' => '1'], 'viewer');
$ok(\ITFlow\Platform\PlatformSettings::bool($db, 'rmm_allow_linux'), 'a non-administrator cannot change it');
$post(['save_platforms' => '1']);   // allow_linux not posted = off
$ok(\ITFlow\Platform\PlatformSettings::get($db, 'rmm_allow_linux') === '0', 'the administrator switches it off');
$ok((int) $one("SELECT COUNT(*) FROM logs WHERE log_type='Endpoint Agent' AND log_action='Linux Enrollment Blocked'") === 1, 'the change is audited');
[$c, , $j] = ea_enroll(ea_token(1, 24, 20), ea_dev(['os' => 'linux', 'os_version' => 'Ubuntu 24.04', 'hostname' => 'LNX-OFF']));
$ok($c === 422 && strpos(json_encode($j), 'device.os') !== false, "off: a Linux device is refused with the existing 422 ($c " . substr(json_encode($j), 0, 120) . ')');
[$c] = ea_enroll(ea_token(1, 24, 20), ea_dev(['hostname' => 'WIN-OFF']));
$ok($c === 200 || $c === 201, 'off: a Windows device still enrolls');
$ok((int) $one("SELECT COUNT(*) FROM endpoint_agent_devices WHERE hostname='LNX-ON'") === 1, 'the Linux device enrolled earlier is untouched');
$post(['save_platforms' => '1', 'allow_linux' => '1']);
$ok(\ITFlow\Platform\PlatformSettings::get($db, 'rmm_allow_linux') === '1' && (int) $one("SELECT COUNT(*) FROM logs WHERE log_action='Linux Enrollment Allowed'") === 1, 'switched on again and audited');
[$c] = ea_enroll(ea_token(1, 24, 20), ea_dev(['os' => 'linux', 'os_version' => 'Debian 12', 'hostname' => 'LNX-AGAIN']));
$ok($c === 200 || $c === 201, 'on again: a Linux device enrolls');
$q("DELETE FROM endpoint_agent_devices"); $q("DELETE FROM platform_settings WHERE setting_key='rmm_allow_linux'");

// the asset made for a device carries its own OS text (a Linux device was labelled "Windows Ubuntu ...")
$as = new \ITFlow\Core\Adapter\Endpoint\EndpointAssets(new \ITFlow\Core\Adapter\Database\MysqliDatabaseAdapter($db));
$id = $as->createForDevice(['os' => 'linux', 'os_version' => 'Ubuntu 24.04', 'hostname' => 'LNXA', 'model' => 'x', 'serial' => 's1', 'manufacturer' => 'm'], 1, 0);
$id2 = $as->createForDevice(['os' => 'windows', 'os_version' => 'Windows 11 23H2', 'hostname' => 'WINA', 'model' => 'x', 'serial' => 's2', 'manufacturer' => 'm'], 1, 0);
$ok((string) $one("SELECT asset_os FROM assets WHERE asset_id=$id") === 'Ubuntu 24.04' && (string) $one("SELECT asset_os FROM assets WHERE asset_id=$id2") === 'Windows Windows 11 23H2', 'asset OS text: Linux as reported; Windows keeps its existing label');
$q("DELETE FROM assets");
