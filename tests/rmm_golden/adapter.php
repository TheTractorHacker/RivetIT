<?php
declare(strict_types=1);

/**
 * RivetIT edition adapter for RivetCore's golden-transcript driver (rivet-core scripts/rmm-golden/golden.php), for the ADOPTED code: it resets and
 * seeds a SCRATCH RivetIT install, and runs state hooks and snapshots through RivetCore\Rmm (rivetRmmModule()) instead of the deleted
 * ITFlow\EndpointAgent classes. Same three commands as the original adapter that recorded the transcripts from the pre-adoption RivetIT code.
 *
 *   RIVET_CORE_DIR=<rivet-core checkout> php tests/rmm_golden/adapter.php <install-dir> <command> [<json-args>]   (prints one JSON document)
 *
 * It refuses to run unless the install's config.php points at a database whose name contains "scratch". Run it through
 * tests/endpoint_agent_golden.php.
 */

if ($argc < 3) {
    fwrite(STDERR, "usage: adapter-rivetit.php <install-dir> <command> [<json-args>]\n");
    exit(2);
}
$root = rtrim($argv[1], '/');
$cmd = $argv[2];
$args = isset($argv[3]) ? (json_decode($argv[3], true) ?: []) : [];
$coreDir = rtrim((string) getenv('RIVET_CORE_DIR'), '/');
if ($coreDir === '' || !is_file("$coreDir/scripts/rmm-golden/constants.php")) {
    fwrite(STDERR, "Set RIVET_CORE_DIR to a rivet-core checkout (it holds scripts/rmm-golden/constants.php)\n");
    exit(2);
}
$K = require "$coreDir/scripts/rmm-golden/constants.php";

$cfgText = (string) @file_get_contents("$root/config.php");
if (!preg_match('/\$database\s*=\s*[\'"]([^\'"]*scratch[^\'"]*)[\'"]/i', $cfgText)) {
    fwrite(STDERR, "Refusing: config.php of $root does not point at a scratch database\n");
    exit(2);
}
mysqli_report(MYSQLI_REPORT_OFF);
date_default_timezone_set('UTC');
$_SERVER['DOCUMENT_ROOT'] = $root;
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once "$root/config.php";
require_once "$root/functions.php";
$db = mysqli_init();
$db->options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, 1);   // integer columns come back as integers (stable snapshots)
$db->real_connect($dbhost ?? 'localhost', $dbusername, $dbpassword, $database);
if ($db->connect_errno) {
    fwrite(STDERR, "connect failed\n");
    exit(2);
}
$db->set_charset('utf8mb4');
$db->query("SET SESSION sql_mode=''");   // same as the RivetIT test harness: edition tables have many NOT NULL columns without defaults
$GLOBALS['mysqli'] = $db;
$mysqli = $db;
$session_user_id = 0;
$session_ip = '127.0.0.1';
$session_user_agent = 'golden';
require_once "$root/vendor/autoload.php";

require_once "$root/includes/rmm_bootstrap.php";
$rmm = rivetRmmModule($db);
$cfgSvc = $rmm->settings();
$ARCHS = ['amd64' => 0x8664, 'arm64' => 0xAA64];

$q = static function (string $sql) use ($db) {
    $r = $db->query($sql);
    if ($r === false) {
        fwrite(STDERR, "SQL failed: {$db->error}\n$sql\n");
        exit(1);
    }
    return $r;
};
$esc = static fn(string $s): string => $db->real_escape_string($s);

function fakePe(int $machine, int $size, string $fill): string
{
    $b = 'MZ' . str_repeat("\0", 0x3A) . pack('V', 128);
    $b = str_pad($b, 128, "\0");
    $b .= "PE\0\0" . pack('v', $machine) . pack('v', 3) . str_repeat("\0", 12) . pack('v', 0xE0) . pack('v', 0x0022);
    $chunk = hash('sha256', $fill . $machine, true);
    $need = $size - strlen($b);
    return $b . substr(str_repeat($chunk, intdiv($need, 32) + 1), 0, $need);
}

function flushRedis(): void
{
    $port = (int) (getenv('RIVETIT_REDIS_PORT') ?: 0);
    if ($port <= 0) {
        return;
    }
    $s = @stream_socket_client("tcp://127.0.0.1:$port", $en, $es, 2);
    if ($s) {
        fwrite($s, "*1\r\n\$8\r\nFLUSHALL\r\n");
        fgets($s);
        fclose($s);
    }
}

$out = [];
switch ($cmd) {
    case 'reset':
        // Wipe everything the scenarios touch. DELETE (not TRUNCATE): auto-increment ids keep growing, so ids differ between runs
        // (the driver masks them) and per-device rate-limit buckets can never collide across runs.
        // Hosted binaries: remove the stored files of the rows about to go (the reset must not accumulate bin_*.bin files).
        $storeDir = $rmm->binaryStore()->storageDir();
        $stored = $db->query('SELECT storage_name FROM endpoint_agent_binaries');
        while ($storeDir !== null && $stored && ($sr = $stored->fetch_row())) {
            if (preg_match('/^bin_[0-9a-f]{32}\.bin$/', (string) $sr[0])) {
                @unlink($storeDir . '/' . $sr[0]);
            }
        }
        foreach (['endpoint_agent_checkins', 'endpoint_agent_checks', 'endpoint_agent_jobs', 'endpoint_agent_mesh_nodes', 'endpoint_agent_releases', 'endpoint_agent_enroll_attempts',
            'endpoint_agent_enrollment_tokens', 'endpoint_agent_devices', 'endpoint_agent_binaries', 'device_metric_samples', 'device_metric_instances', 'device_metric_collection_state',
            'device_metric_rollups', 'asset_rmm_links', 'rmm_alerts', 'rmm_remote_sessions', 'ticket_replies', 'tickets', 'asset_interfaces', 'assets', 'api_tokens', 'logs', 'audit_events', 'rmm_scripts'] as $t) {
            $q("DELETE FROM `$t`");
        }
        $q('DELETE FROM rmm_integrations');
        $q('DELETE FROM endpoint_agent_settings');
        $q('INSERT INTO endpoint_agent_settings (id) VALUES (1)');
        $q('UPDATE settings SET config_module_enable_rmm = 1 WHERE company_id = 1');
        $q('DELETE FROM user_client_permissions');
        foreach ($K['clients'] as $id => $name) {
            $q("INSERT INTO clients SET client_id=$id, client_name='" . $esc($name) . "' ON DUPLICATE KEY UPDATE client_name=VALUES(client_name)");
        }
        $q("DELETE FROM clients WHERE client_id NOT IN (1, 2)");
        $assetIds = [];
        foreach ($K['assets'] as $k => $a) {
            $q("INSERT INTO assets SET asset_type='Laptop', asset_name='" . $esc($a['name']) . "', asset_make='Dell', asset_serial='" . $esc($a['serial']) . "', asset_client_id={$a['client_id']}, asset_status='Active'");
            $assetIds[$k] = $db->insert_id;
        }
        $adminId = (int) ($q('SELECT u.user_id FROM users u JOIN user_roles r ON r.role_id = u.user_role_id WHERE r.role_is_admin = 1 AND u.user_status = 1 AND u.user_archived_at IS NULL AND u.user_id <> 1 ORDER BY u.user_id LIMIT 1')->fetch_row()[0] ?? 0);
        if ($adminId === 0) {
            // No administrator other than user 1: seed a role and an administrator with id 2 (scratch only). The id must not be 1 because the transcripts
            // mask user ids by order of appearance and the seeded tokens are created_by 1, so an admin with id 1 would shift the aliases..
            $q("INSERT INTO user_roles SET role_id=9001, role_name='GoldenAdmin', role_is_admin=1, role_type=1 ON DUPLICATE KEY UPDATE role_is_admin=1");
            $q("INSERT INTO users SET user_id=2, user_name='golden-admin', user_email='golden-admin@example.test', user_password='x', user_type=1, user_status=1, user_role_id=9001 ON DUPLICATE KEY UPDATE user_status=1, user_role_id=9001, user_archived_at=NULL, user_type=1");
            $adminId = 2;
        }
        $q("INSERT INTO api_tokens SET token_user_id=$adminId, token_name='golden', token_hash='" . hash('sha256', $K['admin_api_token']) . "', token_created_at=NOW(), token_last_used_at=NOW()");
        $base = (string) ($args['base_url'] ?? '');
        // The instance signing key is the fixed public test key, so every deterministic signature is stable across runs.
        $kid = substr(hash('sha256', $K['signing_public_b64']), 0, 16);
        $q("UPDATE endpoint_agent_settings SET signing_key_id='$kid', signing_public_key='" . $esc($K['signing_public_b64']) . "', signing_private_key_enc='"
            . $esc(encryptSetting($K['signing_secret_b64'])) . "', signing_key_created_at=UTC_TIMESTAMP(), service_url='" . $esc($base) . "' WHERE id=1");
        $cfgSvc->get(true);
        $cfgSvc->integrationId();
        foreach ($K['tokens'] as $name => $t) {
            $q("INSERT INTO endpoint_agent_enrollment_tokens (token_selector, token_hash, label, client_id, location_id, ring, expires_at, max_uses, use_count, revoked_at, created_by, created_at)
                VALUES ('" . explode('.', $t['token'])[1] . "', '" . hash('sha256', explode('.', $t['token'])[2]) . "', 'golden-$name', {$t['client_id']}, 0, '{$t['ring']}', '"
                . gmdate('Y-m-d H:i:s', time() + $t['ttl_h'] * 3600) . "', {$t['max_uses']}, {$t['used']}, " . ($t['revoked'] ? 'UTC_TIMESTAMP()' : 'NULL') . ", 1, UTC_TIMESTAMP())");
        }
        if (!empty($args['enabled'])) {
            $cfgSvc->set(['enabled' => 1]);
        }
        $tmp = sys_get_temp_dir() . '/rmm_golden_pe_' . bin2hex(random_bytes(4));
        mkdir($tmp, 0700);
        $bins = [];
        foreach ($K['binaries'] as $b) {
            $path = "$tmp/{$b['version']}_{$b['arch']}.exe";
            $bytes = fakePe($ARCHS[$b['arch']], $b['size'], $b['fill']);
            file_put_contents($path, $bytes);
            $r = $rmm->binaryStore()->publish($path, $b['version'], $b['arch'], 1, ['activate' => $b['current'], 'release_ring' => $b['release'] ?: null, 'rollout_pct' => 100, 'notes' => 'golden']);
            if (!$r['ok']) {
                fwrite(STDERR, 'publish failed: ' . ($r['error'] ?? '?') . "\n");
                exit(1);
            }
            $bins[] = ['version' => $b['version'], 'arch' => $b['arch'], 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
            @unlink($path);
        }
        @rmdir($tmp);
        flushRedis();
        $out = ['admin_user_id' => $adminId, 'asset_ids' => $assetIds, 'binaries' => $bins, 'signing_key_id' => $kid];
        break;

    case 'hook':
        $h = (string) ($args['hook'] ?? '');
        switch ($h) {
            case 'enable':
                $cfgSvc->set(['enabled' => 1]);
                break;
            case 'disable':
                $cfgSvc->set(['enabled' => 0]);
                break;
            case 'clear_attempts':
                $q('DELETE FROM endpoint_agent_enroll_attempts');
                break;
            case 'cleanup':   // end of a run: remove the hosted binaries the seed stored (scratch install only: this adapter refuses any other database)
                $storeDir = $rmm->binaryStore()->storageDir();
                foreach ($storeDir === null ? [] : (glob($storeDir . '/bin_*.bin') ?: []) as $f) {
                    if (preg_match('/^bin_[0-9a-f]{32}\.bin$/', basename($f))) {
                        @unlink($f);
                    }
                }
                $q('DELETE FROM endpoint_agent_binaries');
                break;
            case 'flush_redis':
                flushRedis();
                break;
            case 'revoke_device':
                $q("UPDATE endpoint_agent_devices SET revoked_at=UTC_TIMESTAMP(), revoked_reason='golden' WHERE device_id=" . (int) $args['device_id']);
                break;
            case 'expire_device_token':
                $when = !empty($args['restore']) ? gmdate('Y-m-d H:i:s', time() + 3600) : gmdate('Y-m-d H:i:s', time() - 5);
                $q("UPDATE endpoint_agent_devices SET token_expires_at='$when' WHERE device_id=" . (int) $args['device_id']);
                break;
            case 'set_setting':
                $allowed = ['failure_debounce', 'recovery_debounce', 'unmatched_policy', 'check_in_interval_s', 'job_ack_timeout_s', 'job_max_attempts'];
                $set = [];
                foreach ((array) ($args['values'] ?? []) as $k => $v) {
                    if (!in_array($k, $allowed, true)) {
                        fwrite(STDERR, "setting $k not allowed\n");
                        exit(1);
                    }
                    $set[$k] = $v;
                }
                $cfgSvc->set($set);
                break;
            default:
                fwrite(STDERR, "unknown hook $h\n");
                exit(2);
        }
        $out = ['ok' => true];
        break;

    case 'snapshot':
        // Row-level state of the endpoint_agent_* tables (no edition tables): the driver masks volatile columns before storing.
        $tables = [
            'endpoint_agent_devices' => 'device_id',
            'endpoint_agent_checks' => 'device_id, check_key',
            'endpoint_agent_checkins' => 'device_id, seq',
            'endpoint_agent_jobs' => 'type, script, state',
            'endpoint_agent_enrollment_tokens' => 'token_id',
            'endpoint_agent_releases' => 'release_id',
            'endpoint_agent_binaries' => 'binary_id',
        ];
        foreach ($tables as $t => $order) {
            $rows = [];
            $res = $q("SELECT * FROM `$t` ORDER BY $order");
            while ($r = $res->fetch_assoc()) {
                $rows[] = $r;
            }
            $out[$t] = $rows;
        }
        $res = $q("SELECT reason, success, COUNT(*) n FROM endpoint_agent_enroll_attempts GROUP BY reason, success ORDER BY reason, success");
        $out['endpoint_agent_enroll_attempts_by_reason'] = $res->fetch_all(MYSQLI_ASSOC);
        $out['endpoint_agent_settings'] = $q('SELECT * FROM endpoint_agent_settings WHERE id=1')->fetch_assoc();
        // RivetCore migration 0016 (module switch) added five columns the baseline (DB 2.6.146) did not have.
        foreach (['features_json', 'limits_json', 'shed_level', 'ingest_mode', 'max_devices'] as $new) {
            unset($out['endpoint_agent_settings'][$new]);
        }
        break;

    default:
        fwrite(STDERR, "unknown command $cmd\n");
        exit(2);
}
echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
