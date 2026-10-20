<?php
/*
 * RivetCore rc.25 adoption, part 2 (RivetIT 26.10.65): the clock notice (device page strip, legacy device page, fleet badge), the menu, the Changes tab data, the metrics keys.
 * Scratch database only:  RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=<scratch> ... php tests/rmm_rc25_ui.php
 */
require __DIR__ . '/endpoint_agent_lib.php';
require_once "$root/includes/rmm_ui.php";
require_once "$root/includes/rmm_ui_render.php";
require_once "$root/includes/rmm_clock_ui.php";

ea_reset();
$rmm = rivetRmmModule($db);

// ---------------------------------------------------------------- the notice in words
$n = rivetRmmClockNotice(['skew_s' => 300, 'measured_at' => null, 'agent_offset_s' => null, 'last_reject' => null], 'windows');
$ok($n !== null && $n['level'] === 'warning' && $n['minutes'] === 5 && $n['direction'] === 'ahead' && strpos($n['text'], "This PC's clock is 5 min ahead of the server: set the time zone and sync the time (Windows: tzutil /s") !== false && strpos($n['text'], 'w32tm /resync') !== false,
    '300 s ahead: amber notice "This PC\'s clock is 5 min ahead of the server: set the time zone and sync the time (Windows: tzutil /s ..., w32tm /resync)"');
$n = rivetRmmClockNotice(['skew_s' => -420, 'measured_at' => null, 'agent_offset_s' => null, 'last_reject' => null], 'windows');
$ok($n['minutes'] === 7 && $n['direction'] === 'behind' && strpos($n['text'], '7 min behind the server') !== false && $n['level'] === 'warning', '420 s behind: "7 min behind the server"');
$n = rivetRmmClockNotice(['skew_s' => 1000, 'measured_at' => null, 'agent_offset_s' => null, 'last_reject' => null], 'windows');
$ok($n['level'] === 'danger', 'a skew at or beyond the server window (900 s) is red');
$n = rivetRmmClockNotice(['skew_s' => 400, 'measured_at' => null, 'agent_offset_s' => 390, 'last_reject' => ['reason' => 'collected_at is in the future', 'at' => '2026-10-20T10:00:00Z']], 'windows');
$ok($n['level'] === 'danger' && $n['reject'] === 'collected_at is in the future', 'a refused check-in is red and carries the reason');
$ok(rivetRmmClockNotice(['skew_s' => 120, 'measured_at' => null, 'agent_offset_s' => null, 'last_reject' => null], 'windows') === null && rivetRmmClockNotice(['skew_s' => -90, 'measured_at' => null, 'agent_offset_s' => null, 'last_reject' => null]) === null
    && rivetRmmClockNotice(null) === null, 'exactly 120 s, a small skew and no clock object show nothing');
$n = rivetRmmClockNotice(['skew_s' => null, 'measured_at' => null, 'agent_offset_s' => null, 'last_reject' => ['reason' => 'bad time', 'at' => null]], 'linux');
$ok($n !== null && $n['minutes'] === null && strpos($n['text'], 'timedatectl') !== false && strpos($n['text'], 'w32tm') === false, 'a refusal without a measured skew still gives a notice; a Linux device gets the Linux commands');
$hostile = '<script>alert(1)</script>"><img src=x onerror=alert(2)>';
$html = rivetRmmClockBanner(rivetRmmClockNotice(['skew_s' => 700, 'measured_at' => null, 'agent_offset_s' => 650, 'last_reject' => ['reason' => $hostile, 'at' => '2026-10-20T10:00:00Z']], 'windows'), 'rivetRmmUiTime');
$ok(strpos($html, '<script>') === false && strpos($html, '<img') === false && strpos($html, '&lt;script&gt;') !== false && strpos($html, 'role="alert"') !== false && strpos($html, '12 min ahead') !== false, 'the banner escapes a hostile refusal text');
$badge = rivetRmmClockBadge(['skew_s' => 300, 'measured_at' => null, 'agent_offset_s' => null, 'last_reject' => ['reason' => $hostile, 'at' => null]]);
$ok(strpos($badge, '<img') === false && strpos($badge, 'Clock 5 min ahead') !== false && strpos($badge, 'text-bg-danger') !== false && rivetRmmClockBadge(null) === '', 'the fleet badge escapes its title and is empty for a good clock');

// ---------------------------------------------------------------- real device rows, the map, the strip, the legacy page
$mk = function (string $host, string $os, array $cols) use ($q, $db): int {
    $sets = ''; foreach ($cols as $k => $v) { $sets .= ", $k=" . ($v === null ? 'NULL' : "'" . $db->real_escape_string((string) $v) . "'"); }
    $q("INSERT INTO endpoint_agent_devices SET install_id='" . ea_uuid() . "', hostname='$host', os='$os', link_state='linked', client_id=1, first_seen_at=UTC_TIMESTAMP()$sets");
    return (int) $db->insert_id;
};
$never = $mk('REFUSED-PC', 'windows', ['clock_skew_s' => 300, 'clock_skew_at' => gmdate('Y-m-d H:i:s'), 'last_reject_reason' => $hostile, 'last_reject_at' => gmdate('Y-m-d H:i:s')]);
$ahead = $mk('AHEAD-PC', 'windows', ['last_checkin_at' => gmdate('Y-m-d H:i:s'), 'clock_skew_s' => 360, 'clock_skew_at' => gmdate('Y-m-d H:i:s')]);
$fine = $mk('FINE-PC', 'windows', ['last_checkin_at' => gmdate('Y-m-d H:i:s'), 'clock_skew_s' => 20, 'clock_skew_at' => gmdate('Y-m-d H:i:s')]);
$map = rivetRmmClockMap($db, [$never, $ahead, $fine, 0, -1]);
$ok(isset($map[$never], $map[$ahead]) && !isset($map[$fine]) && $map[$never]['last_reject']['reason'] === $hostile, 'the clock map reads the clock columns of several devices in one query and leaves out a good clock');
$ok(rivetRmmClockMap($db, []) === [] && rivetRmmClockMap($db, [$fine]) === [], 'an empty id list and a good clock are handled');

$q("INSERT INTO clients SET client_id=3, client_name='CLK'"); 
$asset = function (int $dev, string $name) use ($q, $db): int {
    $q("INSERT INTO assets SET asset_name='" . $db->real_escape_string($name) . "', asset_type='Laptop', asset_client_id=1"); $id = (int) $db->insert_id;
    $q("UPDATE endpoint_agent_devices SET asset_id=$id WHERE device_id=$dev"); return $id;
};
$aNever = $asset($never, 'REFUSED-PC'); $aAhead = $asset($ahead, 'AHEAD-PC');
$U = ea_seed_users(); \ITFlow\EndpointAgent\Config::enable(); $q("UPDATE settings SET config_module_enable_rmm=1 WHERE company_id=1");
$admin = 1;
$vm = rivetRmmUiPanel($db, $aNever, $admin, $never);
$ok($vm !== null && $vm['clock'] !== null && $vm['state'] === 'never', 'the panel view model carries the clock object');
$strip = $vm === null ? '' : rivetRmmUiStrip($vm);
$ok(strpos($strip, 'OS and agent not reported yet') === false && strpos($strip, 'Not reported yet: the server refused its check-in') !== false && strpos($strip, 'id="rmm-clock-notice"') !== false, '"OS and agent not reported yet" is replaced by the reject reason');
$ok(strpos($strip, '<script>') === false && strpos($strip, '<img src=x') === false && strpos($strip, '&lt;script&gt;') !== false, 'the strip escapes the hostile reason');
$vm = rivetRmmUiPanel($db, $aAhead, $admin, $ahead);
$strip = $vm === null ? '' : rivetRmmUiStrip($vm);
$ok(strpos($strip, "This PC's clock is 6 min ahead of the server") !== false || strpos($strip, 'This PC&#039;s clock is 6 min ahead of the server') !== false, 'a reporting device with a 6 minute skew shows the notice in its strip');

$f = rivetRmmUiFleet($db, $admin, []);
$items = array_column($f['list']['items'], null, 'device_id');
$ok($f !== null && isset($items[$never]['clock'], $items[$ahead]['clock']) && !isset($items[$fine]['clock']) && $f['clock_problems'] === 2, 'fleet rows carry the clock for the two devices with a problem and not for the good one');
$page = rivetRmmUiFleetPage($f, 'tok');
$ok(substr_count($page, 'rmm-clock-badge') >= 2 && strpos($page, 'id="rmm-clock-fleet"') !== false && strpos($page, '<img src=x') === false, 'the fleet page shows the badge and the summary line, escaped');

// the legacy device page
$_GET['device_id'] = (string) $ahead;
$fleetPageHtml = '';
$ok(strpos(file_get_contents("$root/agent/rmm_agent_device.php"), 'rivetRmmClockBanner') !== false, 'the legacy device page draws the clock banner');

// ---------------------------------------------------------------- clean up
$q("DELETE FROM endpoint_agent_devices"); $q("DELETE FROM assets");
