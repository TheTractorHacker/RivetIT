<?php
/*
 * Company-zone time display on the Endpoint agent admin page (Agent updates tables, build hosts and jobs, enrollment tokens, devices), over HTTP with forged
 * sessions, with the company time zone set to America/Chicago and storage values written in UTC (the endpoint_agent_* tables) or company-local time (audit_events).
 * Scratch DB only; same environment as tests/endpoint_agent_build_host_ui.php.
 */
putenv('RIVETIT_REDIS_PORT=' . (getenv('BH_REDIS_PORT') ?: '6379'));
require __DIR__ . '/endpoint_agent_lib.php';
require_once "$root/includes/rmm_build_host_ui.php";

shell_exec('redis-cli -p ' . (int) (getenv('BH_REDIS_PORT') ?: 6379) . ' flushall 2>&1');
ea_reset();
$U = ea_seed_users();
foreach (['endpoint_agent_binaries', 'endpoint_agent_msi_templates', 'endpoint_agent_releases', 'endpoint_agent_build_chunks', 'endpoint_agent_build_files', 'endpoint_agent_build_jobs', 'endpoint_agent_build_hosts'] as $t) { $q("DELETE FROM $t"); }
$q("UPDATE settings SET config_timezone = 'America/Chicago' WHERE company_id = 1");
$sdir = sys_get_temp_dir() . '/ea_rtp_sess_' . bin2hex(random_bytes(4)); mkdir($sdir);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
$srv = ea_start_php("$root/tests/mobile_api_router.php", ['RIVETIT_REDIS_PORT' => getenv('BH_REDIS_PORT') ?: '6379', 'RIVETIT_WEBHOOK_ALLOW_PRIVATE' => '1'], ["session.save_path=$sdir", 'post_max_size=32M', 'memory_limit=512M']);
$wb = 'http://127.0.0.1:' . $srv['port'];
\ITFlow\EndpointAgent\Config::enable();
\ITFlow\EndpointAgent\Config::set(['service_url' => $wb]);
$adminSess = ea_forge_session($sdir, 1);
$page = fn () => web($wb, 'GET', '/admin/settings_endpoint_agent.php', $adminSess);

// Winter (CST, UTC-6) and summer (CDT, UTC-5) rows: the conversion must follow DST, not a fixed offset.
$q("INSERT INTO endpoint_agent_binaries (version, arch, sha256, size_bytes, storage_name, uploaded_by, active, is_current, created_at) VALUES ('7.7.1','amd64','" . str_repeat('a', 64) . "',10,'x1',1,1,1,'2026-10-18 17:16:53'), ('7.7.0','amd64','" . str_repeat('b', 64) . "',10,'x2',1,1,0,'2026-01-18 17:16:53')");
$q("INSERT INTO endpoint_agent_msi_templates SET version='7.7.1', arch='amd64', sha256='" . str_repeat('c', 64) . "', size_bytes=5, storage_name='x3', created_at='2026-10-18 03:05:00'");
$q("INSERT INTO endpoint_agent_build_hosts (name, token_prefix, token_hash, token_salt, enabled, created_by, created_at, last_seen_at) VALUES ('lt-host','abcd1234','" . str_repeat('d', 64) . "','" . str_repeat('e', 32) . "',1,1,'2026-10-18 17:00:00','2026-10-18 20:30:00')");
$hostId = (int) $one("SELECT host_id FROM endpoint_agent_build_hosts WHERE name='lt-host'");
$q("INSERT INTO endpoint_agent_build_jobs SET host_id=$hostId, version='7.7.2', quarantine='" . str_repeat('f', 32) . "', created_at='2026-10-18 19:45:00'");

$r = $page();
$b = (string) ($r['body'] ?? $r[1] ?? '');
$ok(strpos($b, 'Agent updates') !== false, 'the page renders with the Agent updates card');
$ok(!preg_match('/<th[^>]*>[^<]*\(UTC\)/', $b), 'no column header says (UTC)');
$ok(strpos($b, 'Uploaded') !== false, 'the Uploaded columns are still there');
$ok(strpos($b, '2026-10-18 12:16 CDT') !== false, 'agent binary (UTC 17:16 in October) shows 12:16 CDT');
$ok(strpos($b, '2026-01-18 11:16 CST') !== false, 'agent binary (UTC 17:16 in January) shows 11:16 CST: DST is followed, not a fixed offset');
$ok(strpos($b, '2026-10-17 22:05 CDT') !== false, 'MSI template (UTC 03:05) shows the previous evening in company time');
$ok(strpos($b, '17:16 UTC') === false && strpos($b, '17:16</') === false, 'the stored UTC value is not printed raw');
$ok(strpos($b, '2026-10-18 15:30 CDT') !== false, 'build host last seen (UTC 20:30) shows 15:30 CDT');
$ok(strpos($b, '2026-10-18 14:45 CDT') !== false, 'build job created (UTC 19:45) shows 14:45 CDT');
$ok(!preg_match('/\d{2}:\d{2}(:\d{2})?\s+UTC\b/', strip_tags($b)), 'no time on the page is labelled UTC');

// Company zone UTC: the label may then say UTC, and values are unchanged.
$q("UPDATE settings SET config_timezone = 'UTC' WHERE company_id = 1");
$b2 = (string) ($page()['body'] ?? $page()[1] ?? '');
$ok(strpos($b2, '2026-10-18 17:16 UTC') !== false, 'with the company zone set to UTC the same row reads 17:16 UTC');
// Another non-UTC zone, no DST: Asia/Kolkata (UTC+5:30)
$q("UPDATE settings SET config_timezone = 'Asia/Kolkata' WHERE company_id = 1");
$b3 = (string) ($page()['body'] ?? $page()[1] ?? '');
$ok(strpos($b3, '2026-10-18 22:46 IST') !== false, 'Asia/Kolkata shows the half-hour offset (22:46 IST)');
$q("UPDATE settings SET config_timezone = 'America/Chicago' WHERE company_id = 1");
