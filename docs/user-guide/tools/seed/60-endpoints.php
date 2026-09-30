<?php
/*
 * Demo data for the "Endpoints and Integrations" guide page (Summit Ridge Manufacturing).
 *
 *   php docs/user-guide/tools/seed/60-endpoints.php          (RIVETIT_APP_DIR points at the app copy)
 *
 * What this adds (everything is fictional, clearly sample data):
 *   - turns ON the integration flags this page documents: RMM, Intune, UniFi and Comet Backup
 *     (settings.config_module_enable_rmm / _intune / _unifi / config_comet_enabled) and sets the
 *     RMM default integration + the Comet connection fields;
 *   - one Tactical RMM integration and one Sophos Central integration, RMM links (health, status,
 *     alerts, scripts, script runs, check policies, remote-session history, sync log) for the
 *     contract assets from 10-infrastructure (linked BY NAME, skipped when absent) plus a few
 *     extra devices with names of their own;
 *   - UniFi controllers with site mappings and sync log, plus UniFi-style network assets;
 *   - Intune device links (and a disabled, credential-less Microsoft connection row that only
 *     carries the Intune sync log);
 *   - Comet Backup settings, department mappings and backup alerts (with their tickets).
 *
 * Secrets (API keys, passwords) are written through the app's own encryptSetting(); the values
 * are made-up placeholders. Nothing here talks to a real service. The Comet / Tactical URLs point
 * at 127.0.0.1:18060, where docs/user-guide/tools/capture/endpoints.cjs starts a small mock while
 * it takes screenshots.
 *
 * Idempotent: every insert is guarded by a natural key (name, unique index) or, for log-style
 * tables, by "this parent has no rows yet". Nothing is looked up by numeric id.
 */

$app = getenv('RIVETIT_APP_DIR') ?: '/tmp/claude-0/-home-user-RivetIT/8339db22-55d4-5b6a-82d2-15f5d4fccf58/scratchpad/demo-app';
chdir($app . '/scripts');
require_once $app . '/config.php';
require_once $app . '/functions.php';
mysqli_report(MYSQLI_REPORT_OFF);
// Same clock as the app: PHP and the MySQL session both use the company time zone, so NOW() in this
// script matches what the pages compute ("5 minutes ago" really is 5 minutes ago).
require_once $app . '/includes/inc_set_timezone.php';

const MOCK_URL = 'http://127.0.0.1:18060';   // where endpoints.cjs serves the mock Comet / Tactical API

// ------------------------------------------------------------------------------------------
// helpers
// ------------------------------------------------------------------------------------------
function q(string $sql)
{
    global $mysqli;
    $r = mysqli_query($mysqli, $sql);
    if ($r === false) {
        fwrite(STDERR, 'SQL error: ' . mysqli_error($mysqli) . "\n" . substr($sql, 0, 400) . "\n");
        exit(1);
    }
    return $r;
}
function one(string $sql): ?array
{
    $r = q($sql);
    $row = mysqli_fetch_assoc($r);
    return $row ?: null;
}
function e($v): string
{
    global $mysqli;
    return mysqli_real_escape_string($mysqli, (string) $v);
}
function s($v): string            // SQL string literal or NULL
{
    return $v === null ? 'NULL' : "'" . e($v) . "'";
}
function n($v): string            // SQL integer or NULL
{
    return $v === null ? 'NULL' : (string) intval($v);
}
function ago(?int $minutes): string   // SQL "NOW() - INTERVAL n MINUTE" or NULL
{
    return $minutes === null ? 'NULL' : 'NOW() - INTERVAL ' . intval($minutes) . ' MINUTE';
}
function client_id(string $name): ?int
{
    $r = one("SELECT client_id FROM clients WHERE client_name = " . s($name) . " LIMIT 1");
    return $r ? intval($r['client_id']) : null;
}
function user_id(string $email): ?int
{
    $r = one("SELECT user_id FROM users WHERE user_email = " . s($email) . " LIMIT 1");
    return $r ? intval($r['user_id']) : null;
}
function asset_row(string $name): ?array
{
    return one("SELECT * FROM assets WHERE asset_name = " . s($name) . " AND asset_archived_at IS NULL ORDER BY asset_id LIMIT 1");
}
function agent_id(string $name): string   // 40-hex, looks like a Tactical agent id, is just a hash of the name
{
    return substr(sha1('summit-ridge-demo-agent-' . $name), 0, 40);
}
function next_ticket_number(): int
{
    global $mysqli;
    q("UPDATE settings SET config_ticket_next_number = LAST_INSERT_ID(config_ticket_next_number), config_ticket_next_number = config_ticket_next_number + 1 WHERE company_id = 1");
    return intval(mysqli_insert_id($mysqli));
}
/** Create a ticket unless one with this subject already exists; returns its id. Times are minutes ago. */
function make_ticket(string $subject, string $details, string $source, string $priority, int $status, int $created_by,
                     int $assigned_to, int $client_id, int $asset_id, int $created_min_ago, ?int $closed_min_ago = null): int
{
    global $mysqli;
    $have = one("SELECT ticket_id FROM tickets WHERE ticket_subject = " . s($subject) . " AND ticket_source = " . s($source) . " LIMIT 1");
    if ($have) {
        return intval($have['ticket_id']);
    }
    $prefix = one("SELECT config_ticket_prefix p FROM settings WHERE company_id = 1")['p'];
    $num = next_ticket_number();
    $closed = $closed_min_ago === null ? 'NULL' : ago($closed_min_ago);
    q("INSERT INTO tickets SET ticket_prefix = " . s($prefix) . ", ticket_number = $num, ticket_source = " . s($source) . ",
        ticket_subject = " . s($subject) . ", ticket_details = " . s($details) . ", ticket_priority = " . s($priority) . ",
        ticket_status = $status, ticket_created_by = $created_by, ticket_assigned_to = $assigned_to,
        ticket_client_id = $client_id, ticket_asset_id = $asset_id, ticket_url_key = " . s(randomString(32)) . ",
        ticket_created_at = " . ago($created_min_ago) . ", ticket_updated_at = " . ago($created_min_ago) . ",
        ticket_resolved_at = $closed, ticket_closed_at = $closed");
    return intval(mysqli_insert_id($mysqli));
}

$uid_alex   = user_id('alex.morgan@summitridge.example')   ?? 0;
$uid_priya  = user_id('priya.nair@summitridge.example')    ?? 0;
$uid_marcus = user_id('marcus.lee@summitridge.example')    ?? 0;

// ------------------------------------------------------------------------------------------
// 1. Feature flags and connection settings
// ------------------------------------------------------------------------------------------
q("UPDATE settings SET config_module_enable_rmm = 1, config_module_enable_intune = 1, config_module_enable_unifi = 1,
        config_comet_enabled = 1 WHERE company_id = 1");

// ------------------------------------------------------------------------------------------
// 2. Integrations
// ------------------------------------------------------------------------------------------
function ensure_rmm_integration(string $name, string $type, string $api_url, string $web_url, string $secret_json_or_key): int
{
    $have = one("SELECT id FROM rmm_integrations WHERE name = " . s($name) . " LIMIT 1");
    if ($have) {
        return intval($have['id']);
    }
    global $mysqli, $uid_alex;
    q("INSERT INTO rmm_integrations SET name = " . s($name) . ", type = " . s($type) . ", api_url = " . s($api_url) . ",
        web_url = " . s($web_url) . ", api_key_enc = " . s(encryptSetting($secret_json_or_key)) . ", enabled = 1,
        created_by = $uid_alex, created_at = NOW() - INTERVAL 30 DAY");
    return intval(mysqli_insert_id($mysqli));
}
$intg_tac = ensure_rmm_integration('Summit Ridge RMM', 'tactical_rmm', MOCK_URL, 'https://rmm.summitridge.example', 'demo-tactical-api-key-not-real');
$intg_sop = ensure_rmm_integration('Sophos Central - Firewalls', 'sophos_central', 'https://api.central.sophos.com', '',
                                   json_encode(['client_id' => 'demo-client-id', 'client_secret' => 'demo-client-secret-not-real']));
q("UPDATE settings SET config_rmm_default_integration_id = $intg_tac WHERE company_id = 1 AND (config_rmm_default_integration_id IS NULL OR config_rmm_default_integration_id = 0)");

// Comet Backup connection (credentials are placeholders; the mock server ignores them)
q("UPDATE settings SET config_comet_server_url = " . s(MOCK_URL) . ", config_comet_admin_user = 'rivetit-api',
        config_comet_admin_pass = " . s(encryptSetting('demo-comet-password-not-real')) . ",
        config_comet_webhook_secret = " . s(encryptSetting('demo-webhook-secret-not-real')) . ",
        config_comet_auto_ticket = 1
    WHERE company_id = 1 AND (config_comet_admin_user IS NULL OR config_comet_admin_user = '')");

// UniFi controllers
function ensure_unifi(string $name, string $host, int $port): int
{
    $have = one("SELECT id FROM unifi_integrations WHERE name = " . s($name) . " LIMIT 1");
    if ($have) {
        return intval($have['id']);
    }
    global $mysqli, $uid_alex;
    q("INSERT INTO unifi_integrations SET name = " . s($name) . ", type = 'local', host = " . s($host) . ", port = $port,
        api_key_enc = " . s(encryptSetting('demo-unifi-api-key-not-real')) . ", verify_ssl = 0, enabled = 1,
        created_by = $uid_alex, created_at = NOW() - INTERVAL 30 DAY");
    return intval(mysqli_insert_id($mysqli));
}
$unifi_hq    = ensure_unifi('Headquarters UniFi (UDM Pro)', '10.0.0.3', 443);
$unifi_plant = ensure_unifi('Plant 1 UniFi (UDM SE)', '10.20.0.3', 443);

// Microsoft 365 connection: a disabled row with NO credentials, so the Directory Sync tab still looks
// like a fresh form. It exists only because Intune links and the Intune sync log hang off it.
$ms = one("SELECT microsoft_integration_id id FROM microsoft_integrations ORDER BY microsoft_integration_id LIMIT 1");
if (!$ms) {
    q("INSERT INTO microsoft_integrations SET enabled = 0, intune_sync_enabled = 0, directory_sync_enabled = 0");
    $ms = one("SELECT microsoft_integration_id id FROM microsoft_integrations ORDER BY microsoft_integration_id LIMIT 1");
}
$ms_id = intval($ms['id']);

// ------------------------------------------------------------------------------------------
// 3. Devices: contract assets (linked only if 10-infrastructure created them) + extras of our own
// ------------------------------------------------------------------------------------------
// cols: name, contract?, type, department, make, model, serial, OS name, OS version, CPU, RAM GB, logged-in user, status,
//       last seen (min ago), cpu %, ram %, disk %, needs reboot, maintenance, patches pending, last boot (days ago), IP, MAC
$W11 = 'Microsoft Windows 11 Pro';   $W10 = 'Microsoft Windows 10 Pro';
$devices = [
  ['LT-EXEC-01',   true,  'Laptop',  'Executive Office',      'Dell',   'Latitude 7440',           'SRDEMO-LT7440-0101', $W11, '10.0.22631 (23H2)', '13th Gen Intel(R) Core(TM) i7-1365U', '32', 'SUMMITRIDGE\\hbrandt',  'online',  2,    11, 44, 57, 0, 0, 0,    6,  '10.0.20.11', '3c:52:82:a1:00:11'],
  ['LT-FIN-02',    true,  'Laptop',  'Finance & Accounting',  'Dell',   'Latitude 5440',           'SRDEMO-LT5440-0202', $W11, '10.0.22631 (23H2)', '13th Gen Intel(R) Core(TM) i5-1345U', '16', 'SUMMITRIDGE\\tkessler',  'online',  1,    18, 79, 96, 0, 0, 1,    12, '10.0.20.12', '3c:52:82:a1:00:12'],
  ['DT-HR-01',     true,  'Desktop', 'Human Resources',       'HP',     'EliteDesk 800 G9',        'SRDEMO-ED800-0301', $W10, '10.0.19045 (22H2)', '12th Gen Intel(R) Core(TM) i5-12500',  '16', 'SUMMITRIDGE\\malvarez',  'offline', 2900, null, null, null, 0, 0, null, null, '10.0.20.21', '3c:52:82:a1:00:21'],
  ['DT-SALES-03',  true,  'Desktop', 'Sales & Marketing',     'Dell',   'OptiPlex 7010',           'SRDEMO-OP7010-0403', $W11, '10.0.22631 (23H2)', '13th Gen Intel(R) Core(TM) i7-13700',  '16', 'SUMMITRIDGE\\obaker',    'online',  3,    34, 91, 62, 1, 0, 1,    21, '10.0.20.33', '3c:52:82:a1:00:33'],
  ['LT-ENG-04',    true,  'Laptop',  'Engineering',           'Dell',   'Precision 5570',          'SRDEMO-PR5570-0504', 'Microsoft Windows 11 Pro for Workstations', '10.0.22631 (23H2)', '12th Gen Intel(R) Core(TM) i9-12900H', '64', 'SUMMITRIDGE\\msingh', 'online', 1, 97, 71, 82, 0, 0, 0, 4, '10.0.20.44', '3c:52:82:a1:00:44'],
  ['PC-PLANT-07',  true,  'Desktop', 'Production',            'Dell',   'OptiPlex 3000',           'SRDEMO-OP3000-0607', 'Microsoft Windows 10 Enterprise LTSC', '10.0.19044 (21H2)', '12th Gen Intel(R) Core(TM) i3-12100', '8', 'SUMMITRIDGE\\arahman', 'online', 2, 41, 68, 74, 1, 0, 1, 33, '10.20.1.17', '3c:52:82:a1:00:17'],
  ['SRV-FILE-01',  true,  'Server',  null,                    'Dell',   'PowerEdge T350',          'SRDEMO-T350-0701',  'Microsoft Windows Server 2022 Standard', '10.0.20348 (21H2)', 'Intel(R) Xeon(R) E-2378 CPU @ 2.60GHz', '64', '', 'online', 1, 23, 62, 88, 0, 0, 0, 47, '10.0.0.10', '3c:52:82:a1:00:10'],
  ['WS-QC-11',     false, 'Desktop', 'Production',            'Dell',   'OptiPlex 7010',           'SRDEMO-OP7010-1111', $W10, '10.0.19045 (22H2)', '12th Gen Intel(R) Core(TM) i5-12500',  '16', 'SUMMITRIDGE\\enovak',    'online',  4,    22, 61, 44, 0, 0, 0,    9,  '10.20.1.31', '3c:52:82:a1:00:31'],
  ['LT-OPS-12',    false, 'Laptop',  'Executive Office',      'Lenovo', 'ThinkPad T14 Gen 4',      'SRDEMO-T14G4-1212', $W11, '10.0.22631 (23H2)', 'AMD Ryzen 7 PRO 7840U w/ Radeon 780M', '32', '',                      'unknown', null, null, null, null, 0, 0, null, null, '10.0.20.13', '3c:52:82:a1:00:13'],
  ['SRV-APP-02',   false, 'Server',  'Engineering',           'Dell',   'PowerEdge R250',          'SRDEMO-R250-1302',  'Ubuntu 22.04.4 LTS', '5.15.0-107-generic', 'Intel(R) Xeon(R) E-2334 CPU @ 3.40GHz', '32', '', 'online', 1, 31, 72, 78, 0, 0, null, 58, '10.0.0.12', '3c:52:82:a1:00:1c'],
  ['DT-WHSE-05',   false, 'Desktop', 'Warehouse & Logistics', 'HP',     'EliteDesk 800 G6',        'SRDEMO-ED800-1405', $W10, '10.0.19045 (22H2)', '10th Gen Intel(R) Core(TM) i5-10500',  '16', 'SUMMITRIDGE\\fdelgado',  'offline', 1560, null, null, null, 0, 0, null, null, '10.30.1.15', '3c:52:82:a1:00:15'],
  ['MAC-MKT-03',   false, 'Laptop',  'Sales & Marketing',     'Apple',  'MacBook Pro 14-inch (M3 Pro)', 'SRDEMO-MBP14-1503', 'macOS Sonoma 14.5', '23F79', 'Apple M3 Pro', '18', 'zhartman', 'online', 5, 15, 58, 61, 0, 1, 0, 3, '10.0.20.35', '3c:52:82:a1:00:35'],
];

function ensure_asset(string $name, string $type, ?string $dept, string $make, string $model, string $serial, string $os,
                      string $status = 'Deployed', string $notes = ''): ?int
{
    global $mysqli;
    $have = asset_row($name);
    if ($have) {
        return intval($have['asset_id']);
    }
    $cid = $dept === null ? 0 : (client_id($dept) ?? 0);
    q("INSERT INTO assets SET asset_type = " . s($type) . ", asset_name = " . s($name) . ", asset_make = " . s($make) . ",
        asset_model = " . s($model) . ", asset_serial = " . s($serial) . ", asset_os = " . s($os) . ",
        asset_status = " . s($status) . ", asset_notes = " . ($notes === '' ? 'NULL' : s($notes)) . ",
        asset_client_id = $cid, asset_install_date = CURDATE() - INTERVAL 400 DAY, asset_created_at = NOW() - INTERVAL 30 DAY");
    return intval(mysqli_insert_id($mysqli));
}
function ensure_interface(int $asset_id, string $ip, string $mac, string $type = 'Ethernet'): void
{
    $have = one("SELECT interface_id FROM asset_interfaces WHERE interface_asset_id = $asset_id AND interface_primary = 1 LIMIT 1");
    if ($have) {
        return;
    }
    q("INSERT INTO asset_interfaces SET interface_asset_id = $asset_id, interface_name = " . s($type === 'WiFi' ? 'Wi-Fi' : 'Ethernet') . ",
        interface_type = " . s($type) . ", interface_mac = " . s($mac) . ", interface_ip = " . s($ip) . ", interface_primary = 1");
}

$link_id = [];      // device name => asset_rmm_links.id
$asset_of = [];     // device name => asset_id
foreach ($devices as $d) {
    [$name, $contract, $type, $dept, $make, $model, $serial, $os, $osver, $cpu, $ram, $user, $status, $seen, $cpuP, $ramP, $diskP, $reboot, $maint, $patch, $boot, $ip, $mac] = $d;
    $asset = asset_row($name);
    if (!$asset) {
        if ($contract) {
            continue;                                  // contract asset not created (yet): skip it, NULL-safe
        }
        $aid = ensure_asset($name, $type, $dept, $make, $model, $serial, trim("$os $osver"));
        ensure_interface($aid, $ip, $mac, $type === 'Laptop' ? 'WiFi' : 'Ethernet');
        $asset = asset_row($name);
    }
    $aid = intval($asset['asset_id']);
    $asset_of[$name] = $aid;
    $mk = trim((string) $asset['asset_make']) !== '' ? $asset['asset_make'] : $make;
    $md = trim((string) $asset['asset_model']) !== '' ? $asset['asset_model'] : $model;
    $has = one("SELECT id FROM asset_rmm_links WHERE asset_id = $aid AND integration_id = $intg_tac LIMIT 1");
    if (!$has) {
        $health_at = $status === 'online' ? ago(6) : ago($seen === null ? 6 : $seen);
        q("INSERT INTO asset_rmm_links SET asset_id = $aid, integration_id = $intg_tac, tactical_agent_id = " . s(agent_id($name)) . ",
            mesh_node_id = " . ($type === 'Server' || $type === 'Desktop' || $type === 'Laptop' ? s('node//' . substr(sha1('mesh' . $name), 0, 48)) : 'NULL') . ",
            hostname = " . s($name) . ", rmm_status = " . s($status) . ", last_seen = " . ago($seen) . ",
            os_name = " . s($os) . ", os_version = " . s($osver) . ", manufacturer = " . s($mk) . ", model = " . s($md) . ",
            cpu = " . s($cpu) . ", ram_gb = " . s($ram) . ", logged_in_user = " . s($user === '' ? null : $user) . ",
            last_sync = NOW() - INTERVAL 6 MINUTE, rmm_status_changed_at = " . ($status === 'online' ? ago(60 * 24 * 3) : ago($seen)) . ",
            rmm_cpu_percent = " . n($cpuP) . ", rmm_ram_percent = " . n($ramP) . ", rmm_disk_percent = " . n($diskP) . ",
            rmm_needs_reboot = $reboot, rmm_maintenance_mode = $maint, rmm_patches_pending = " . n($patch) . ",
            rmm_last_boot = " . ($boot === null ? 'NULL' : 'NOW() - INTERVAL ' . intval($boot) . ' DAY') . ",
            rmm_health_updated_at = $health_at, created_at = NOW() - INTERVAL 25 DAY");
        $has = one("SELECT id FROM asset_rmm_links WHERE asset_id = $aid AND integration_id = $intg_tac LIMIT 1");
    }
    $link_id[$name] = intval($has['id']);
}

// Firewalls managed through Sophos Central (link rows use the same table; no health columns)
$firewalls = [
  ['FW-EDGE-01', true,  null,                    'Sophos', 'XGS 2100', 'SRDEMO-XGS2100-0001', 'online',  4,   'SFOS 20.0.2 MR-2-Build378', '10.0.0.1'],
  ['FW-PLANT-01', false, 'Production',           'Sophos', 'XGS 87',   'SRDEMO-XGS87-0002',   'online',  4,   'SFOS 20.0.2 MR-2-Build378', '10.20.0.1'],
  ['FW-DC-01',   false, 'Warehouse & Logistics', 'Sophos', 'XGS 116',  'SRDEMO-XGS116-0003',  'offline', 190, 'SFOS 20.0.1 MR-1-Build394', '10.30.0.1'],
];
foreach ($firewalls as [$name, $contract, $dept, $make, $model, $serial, $status, $seen, $fw, $ip]) {
    $asset = asset_row($name);
    if (!$asset) {
        if ($contract) {
            continue;
        }
        $aid = ensure_asset($name, 'Firewall/Router', $dept, $make, $model, $serial, 'Sophos Firewall OS');
        ensure_interface($aid, $ip, '3c:52:82:f0:00:' . substr(sha1($name), 0, 2));
        $asset = asset_row($name);
    }
    $aid = intval($asset['asset_id']);
    $asset_of[$name] = $aid;
    $has = one("SELECT id FROM asset_rmm_links WHERE asset_id = $aid AND integration_id = $intg_sop LIMIT 1");
    if (!$has) {
        q("INSERT INTO asset_rmm_links SET asset_id = $aid, integration_id = $intg_sop, tactical_agent_id = " . s('sophos-' . substr(sha1($name), 0, 12)) . ",
            hostname = " . s($name) . ", rmm_status = " . s($status) . ", last_seen = " . ago($seen) . ",
            os_name = 'Sophos Firewall OS', os_version = " . s($fw) . ", manufacturer = 'Sophos', model = " . s($model) . ",
            last_sync = NOW() - INTERVAL 6 MINUTE, created_at = NOW() - INTERVAL 25 DAY");
        $has = one("SELECT id FROM asset_rmm_links WHERE asset_id = $aid AND integration_id = $intg_sop LIMIT 1");
    }
    $link_id[$name] = intval($has['id']);
}

// Switches and access points that are NOT managed by an RMM: manual entries and UniFi-synced ones.
$net = [
  // name, type, dept, make, model, serial, status, ip, notes, contract?
  ['SW-PLANT-02', 'Switch',       'Production',            'HPE Aruba', '2930F 24G PoE+',   'SRDEMO-2930F-0010', 'Deployed', '10.20.0.2',  '', false],
  ['USW-ENG-01',  'Switch',       'Engineering',           'Ubiquiti',  'USW-24-PoE',       'SRDEMO-USW24-0011', 'Deployed', '10.0.10.2',  'UniFi Device | MAC: 74:ac:b9:1a:00:11 | Firmware: 6.6.65 | Uptime: 88d | State: Connected | Last synced: %s', false],
  ['AP-ENG-01',   'Access Point', 'Engineering',           'Ubiquiti',  'U6-Pro',           'SRDEMO-U6P-0012',   'Deployed', '10.0.10.21', 'UniFi Device | MAC: 74:ac:b9:1a:00:12 | Firmware: 6.6.65 | Uptime: 41d | State: Connected | Last synced: %s', false],
  ['AP-ENG-02',   'Access Point', 'Engineering',           'Ubiquiti',  'U6-Lite',          'SRDEMO-U6L-0013',   'Deployed', '10.0.10.22', 'UniFi Device | MAC: 74:ac:b9:1a:00:13 | Firmware: 6.6.65 | Uptime: 41d | State: Connected | Last synced: %s', false],
  ['AP-EXEC-01',  'Access Point', 'Executive Office',      'Ubiquiti',  'U6-Pro',           'SRDEMO-U6P-0014',   'Deployed', '10.0.10.23', 'UniFi Device | MAC: 74:ac:b9:1a:00:14 | Firmware: 6.6.65 | Uptime: 41d | State: Connected | Last synced: %s', false],
  ['AP-PROD-01',  'Access Point', 'Production',            'Ubiquiti',  'U6-Enterprise-IW', 'SRDEMO-U6E-0015',   'Deployed', '10.20.10.21','UniFi Device | MAC: 74:ac:b9:2b:00:15 | Firmware: 6.6.65 | Uptime: 23d | State: Connected | Last synced: %s', false],
  ['AP-PROD-02',  'Access Point', 'Production',            'Ubiquiti',  'U6-Enterprise-IW', 'SRDEMO-U6E-0016',   'Spare',    '10.20.10.22','UniFi Device | MAC: 74:ac:b9:2b:00:16 | Firmware: 6.6.65 | Uptime: unknown | State: Disconnected | Last synced: %s', false],
  ['AP-WHSE-01',  'Access Point', 'Warehouse & Logistics', 'Ubiquiti',  'U6-Mesh',          'SRDEMO-U6M-0017',   'Deployed', '10.30.10.21','UniFi Device | MAC: 74:ac:b9:3c:00:17 | Firmware: 6.6.65 | Uptime: 19d | State: Connected | Last synced: %s', false],
];
foreach ($net as [$name, $type, $dept, $make, $model, $serial, $status, $ip, $notes, $contract]) {
    if (!asset_row($name)) {
        $note = $notes === '' ? '' : sprintf($notes, date('Y-m-d H:i', time() - 25 * 60));
        $aid = ensure_asset($name, $type, $dept, $make, $model, $serial, '', $status, $note);
        ensure_interface($aid, $ip, '74:ac:b9:' . substr(sha1($name), 0, 2) . ':' . substr(sha1($name), 2, 2) . ':' . substr(sha1($name), 4, 2));
    }
}

// Devices only managed through Intune (own assets so the Intune list has a tablet and a scanner even without other seeds)
$intune_only = [
  ['TAB-WHSE-01', 'Tablet', 'Warehouse & Logistics', 'Apple', 'iPad (10th generation)', 'SRDEMO-IPAD-2101'],
];
foreach ($intune_only as [$name, $type, $dept, $make, $model, $serial]) {
    if (!asset_row($name)) {
        ensure_asset($name, $type, $dept, $make, $model, $serial, 'iPadOS 17.5.1');
    }
}

// ------------------------------------------------------------------------------------------
// 4. RMM alerts (+ the tickets some of them were turned into)
// ------------------------------------------------------------------------------------------
function ensure_alert(int $intg, string $key, ?int $asset_id, string $severity, string $status, string $message, int $created_min,
                      ?int $ack_by = null, ?int $ack_min = null, ?int $resolved_min = null, ?int $ticket_id = null): void
{
    $client = 'NULL';
    if ($asset_id) {
        $a = one("SELECT asset_client_id c FROM assets WHERE asset_id = $asset_id");
        $client = ($a && intval($a['c']) > 0) ? (string) intval($a['c']) : 'NULL';
    }
    q("INSERT IGNORE INTO rmm_alerts SET integration_id = $intg, tactical_alert_id = " . s($key) . ", asset_id = " . n($asset_id) . ",
        client_id = $client, severity = " . s($severity) . ", status = " . s($status) . ", message = " . s($message) . ",
        created_at = " . ago($created_min) . ", acknowledged_by = " . n($ack_by) . ", acknowledged_at = " . ago($ack_min) . ",
        resolved_at = " . ago($resolved_min) . ", ticket_id = " . n($ticket_id));
}
function alert_ticket(string $device, string $key, string $severity, string $message, string $source, int $created_by, int $assigned,
                      int $status, int $created_min): ?int
{
    global $asset_of, $link_id;
    if (!isset($asset_of[$device])) {
        return null;
    }
    $aid = $asset_of[$device];
    $link = one("SELECT * FROM asset_rmm_links WHERE id = " . intval($link_id[$device]));
    $cid = intval(one("SELECT asset_client_id c FROM assets WHERE asset_id = $aid")['c']);
    $lines = ['RMM Alert', "Severity: $severity", "Message: $message", "Asset: $device", 'Hostname: ' . $link['hostname'],
              'OS: ' . trim($link['os_name'] . ' ' . $link['os_version'])];
    if (!empty($link['logged_in_user'])) { $lines[] = 'Logged-in User: ' . $link['logged_in_user']; }
    if (!empty($link['last_seen']))      { $lines[] = 'Last Seen: ' . $link['last_seen']; }
    $lines[] = "Alert ID: $key";
    $prio = ['critical' => 'High', 'error' => 'High', 'warning' => 'Medium', 'info' => 'Low'][$severity];
    return make_ticket('RMM Alert: ' . substr($message, 0, 200), implode("\n", $lines), $source, $prio, $status, $created_by, $assigned, $cid, $aid, $created_min);
}

// [key, device, integration ('t'|'s'), severity, status, message, minutes ago, acked by, acked minutes ago, resolved minutes ago, ticket source|null]
$P = 60 * 24;  // one day in minutes
$alerts = [
  // ---- new ----
  ['demo-alert-0101', 'PC-PLANT-07',  't', 'error',    'new', "Windows Service Check: 'Print Spooler' (Spooler) is not running", 25],
  ['demo-alert-0102', 'LT-ENG-04',    't', 'warning',  'new', 'CPU Load Check: 97% average over the last 3 runs (threshold 95%)', 40],
  ['demo-alert-0103', 'DT-SALES-03',  't', 'warning',  'new', 'Memory Check: 91% of RAM in use (threshold 85%)', 130],
  ['demo-alert-0104', 'SRV-FILE-01',  't', 'warning',  'new', 'Disk Space Check: D: is 88% used (240 GB free of 2 TB)', 300],
  ['demo-alert-0105', 'SRV-FILE-01',  't', 'error',    'new', "Event Log Check: 4 ERROR events from source 'VSS' in the Application log in the last day", 560],
  ['demo-alert-0106', 'DT-WHSE-05',   't', 'critical', 'new', 'Agent Check-in: no check-in from DT-WHSE-05 for 26 hours', 200],
  ['demo-alert-0107', 'FW-DC-01',     's', 'critical', 'new', 'Firewall FW-DC-01 lost its connection to Sophos Central', 190],
  ['demo-alert-0108', 'FW-EDGE-01',   's', 'error',    'new', 'WAN2 (backup ISP) link is down on FW-EDGE-01', 95],
  ['demo-alert-0109', 'PC-PLANT-07',  't', 'info',     'new', "Script Check 'Weekly software inventory' finished with warnings (2 packages unreadable)", 1300],
  // ---- acknowledged ----
  ['demo-alert-0201', 'LT-FIN-02',    't', 'critical', 'acknowledged', 'Disk Space Check: C: is 96% used (2.1 GB free of 476 GB)', 200, $uid_priya, 150, null, 'RMM Alert'],
  ['demo-alert-0202', 'DT-HR-01',     't', 'critical', 'acknowledged', 'Agent Check-in: no check-in from DT-HR-01 for 48 hours', 1000, null, 995, null, 'RMM Automation'],
  ['demo-alert-0203', 'SRV-APP-02',   't', 'warning',  'acknowledged', 'Memory Check: 90% of RAM in use (threshold 85%)', 1900, $uid_marcus, 1800],
  // ---- resolved (history: fills the 30-day charts) ----
  ['demo-alert-0301', 'LT-FIN-02',    't', 'warning',  'resolved', 'Memory Check: 86% of RAM in use (threshold 85%)', 6 * $P, $uid_priya, 6 * $P - 20, 6 * $P - 90],
  ['demo-alert-0302', 'LT-ENG-04',    't', 'warning',  'resolved', 'CPU Load Check: 94% average over the last 3 runs (threshold 95%)', 4 * $P, $uid_marcus, 4 * $P - 15, 4 * $P - 60],
  ['demo-alert-0303', 'SRV-FILE-01',  't', 'critical', 'resolved', 'Ping Check: 10.0.0.1 is unreachable (5 failures)', 12 * $P, $uid_alex, 12 * $P - 10, 12 * $P - 45],
  ['demo-alert-0304', 'PC-PLANT-07',  't', 'error',    'resolved', "Windows Service Check: 'Print Spooler' (Spooler) is not running", 9 * $P, $uid_marcus, 9 * $P - 30, 9 * $P - 75],
  ['demo-alert-0305', 'DT-SALES-03',  't', 'warning',  'resolved', 'Disk Space Check: C: is 83% used (81 GB free of 476 GB)', 15 * $P, $uid_priya, 15 * $P - 60, 15 * $P - 240],
  ['demo-alert-0306', 'LT-EXEC-01',   't', 'warning',  'resolved', 'Memory Check: 88% of RAM in use (threshold 85%)', 3 * $P, $uid_priya, 3 * $P - 25, 3 * $P - 80],
  ['demo-alert-0307', 'LT-EXEC-01',   't', 'info',     'resolved', "Script Check 'BitLocker status' returned exit code 1", 21 * $P, $uid_alex, 21 * $P - 30, 21 * $P - 120],
  ['demo-alert-0308', 'WS-QC-11',     't', 'warning',  'resolved', 'CPU Load Check: 91% average over the last 3 runs (threshold 95%)', 8 * $P, $uid_marcus, 8 * $P - 20, 8 * $P - 50],
  ['demo-alert-0309', 'WS-QC-11',     't', 'error',    'resolved', "Windows Service Check: 'Print Spooler' (Spooler) is not running", 17 * $P, $uid_priya, 17 * $P - 40, 17 * $P - 100],
  ['demo-alert-0310', 'SRV-APP-02',   't', 'warning',  'resolved', 'Disk Space Check: / is 82% used (57 GB free of 320 GB)', 11 * $P, $uid_marcus, 11 * $P - 30, 11 * $P - 200],
  ['demo-alert-0311', 'SRV-APP-02',   't', 'critical', 'resolved', 'Ping Check: 10.0.0.1 is unreachable (5 failures)', 24 * $P, $uid_alex, 24 * $P - 5, 24 * $P - 35],
  ['demo-alert-0312', 'DT-HR-01',     't', 'warning',  'resolved', 'Memory Check: 87% of RAM in use (threshold 85%)', 13 * $P, $uid_priya, 13 * $P - 45, 13 * $P - 130],
  ['demo-alert-0313', 'LT-FIN-02',    't', 'error',    'resolved', "Event Log Check: 3 ERROR events from source 'Disk' in the System log in the last day", 19 * $P, $uid_marcus, 19 * $P - 20, 19 * $P - 300],
  ['demo-alert-0314', 'FW-EDGE-01',   's', 'error',    'resolved', 'WAN2 (backup ISP) link is down on FW-EDGE-01', 5 * $P, $uid_marcus, 5 * $P - 10, 5 * $P - 25],
  ['demo-alert-0315', 'FW-EDGE-01',   's', 'error',    'resolved', 'WAN2 (backup ISP) link is down on FW-EDGE-01', 20 * $P, $uid_alex, 20 * $P - 10, 20 * $P - 55],
  ['demo-alert-0316', 'MAC-MKT-03',   't', 'warning',  'resolved', 'Disk Space Check: / is 84% used (98 GB free of 494 GB)', 27 * $P, $uid_priya, 27 * $P - 60, 27 * $P - 400],
  ['demo-alert-0317', 'LT-ENG-04',    't', 'warning',  'resolved', 'Memory Check: 90% of RAM in use (threshold 85%)', 2 * $P, $uid_priya, 2 * $P - 20, 2 * $P - 95],
  ['demo-alert-0318', 'PC-PLANT-07',  't', 'warning',  'resolved', 'CPU Load Check: 92% average over the last 3 runs (threshold 95%)', 14 * $P, $uid_marcus, 14 * $P - 30, 14 * $P - 90],
  ['demo-alert-0319', 'SRV-FILE-01',  't', 'warning',  'resolved', 'Disk Space Check: D: is 85% used (300 GB free of 2 TB)', 7 * $P, $uid_marcus, 7 * $P - 30, 7 * $P - 240],
  ['demo-alert-0320', 'DT-WHSE-05',   't', 'critical', 'resolved', 'Agent Check-in: no check-in from DT-WHSE-05 for 24 hours', 10 * $P, $uid_priya, 10 * $P - 30, 10 * $P - 380],
  ['demo-alert-0321', 'DT-SALES-03',  't', 'error',    'resolved', "Windows Service Check: 'Windows Update' (wuauserv) is not running", 22 * $P, $uid_marcus, 22 * $P - 20, 22 * $P - 60],
];
foreach ($alerts as $a) {
    [$key, $device, $which, $sev, $status, $msg, $min] = $a;
    $ack_by = $a[7] ?? null; $ack_min = $a[8] ?? null; $res_min = $a[9] ?? null; $tsource = $a[10] ?? null;
    if (!isset($asset_of[$device])) {
        continue;                                          // device (or its contract asset) does not exist here
    }
    $intg = $which === 's' ? $intg_sop : $intg_tac;
    $ticket = null;
    if ($tsource) {
        $ticket = alert_ticket($device, $key, $sev, $msg, $tsource, $tsource === 'RMM Alert' ? $uid_priya : 0,
                               $tsource === 'RMM Alert' ? $uid_priya : 0, $tsource === 'RMM Alert' ? 2 : 1, $ack_min ?? $min);
    }
    ensure_alert($intg, $key, $asset_of[$device], $sev, $status, $msg, $min, $ack_by, $ack_min, $res_min, $ticket);
}

// ------------------------------------------------------------------------------------------
// 5. Script library and script runs
// ------------------------------------------------------------------------------------------
$scripts = [
  // name, category, type, tactical id, enabled, description, body
  ['Clear Windows temp files', 'Maintenance', 'powershell', 101, 1, 'Deletes files in the Windows and user temp folders and reports the space freed.',
   "\$before = (Get-PSDrive C).Free\nRemove-Item \"\$env:windir\\Temp\\*\", \"\$env:TEMP\\*\" -Recurse -Force -ErrorAction SilentlyContinue\n\$after = (Get-PSDrive C).Free\n\"Freed {0:N1} GB on C:\" -f ((\$after - \$before) / 1GB)"],
  ['Windows Update: scan and report', 'Maintenance', 'powershell', 102, 1, 'Lists pending Windows updates without installing anything.',
   "\$session = New-Object -ComObject Microsoft.Update.Session\n\$result = \$session.CreateUpdateSearcher().Search(\"IsInstalled=0 and Type='Software'\")\n\$result.Updates | ForEach-Object { \"{0}  ({1})\" -f \$_.Title, \$_.MsrcSeverity }"],
  ['Show disk usage by folder (Linux)', 'Maintenance', 'bash', 103, 1, 'Top 10 largest folders under /var and /home.',
   "du -xh --max-depth=2 /var /home 2>/dev/null | sort -rh | head -n 10"],
  ['Restart Print Spooler and clear queue', 'Repair', 'powershell', 104, 1, 'Stops the spooler, clears stuck jobs and starts it again.',
   "Stop-Service Spooler -Force\n\$jobs = Get-ChildItem \"\$env:windir\\System32\\spool\\PRINTERS\" -File\n\$jobs | Remove-Item -Force\nStart-Service Spooler\n\"Cleared {0} stuck job(s). Spooler is {1}.\" -f \$jobs.Count, (Get-Service Spooler).Status"],
  ['Flush DNS and renew IP address', 'Repair', 'cmd', 105, 1, 'Runs ipconfig /flushdns and /renew.',
   "ipconfig /flushdns\nipconfig /release\nipconfig /renew"],
  ['Collect installed software list', 'Inventory', 'powershell', 106, 1, 'Lists installed programs with version and publisher.',
   "Get-ItemProperty HKLM:\\Software\\Microsoft\\Windows\\CurrentVersion\\Uninstall\\* |\n  Where-Object DisplayName | Sort-Object DisplayName |\n  Select-Object DisplayName, DisplayVersion, Publisher | Format-Table -AutoSize"],
  ['Report BIOS version and serial number', 'Inventory', 'powershell', 107, 1, 'Reads BIOS version, manufacturer and serial number.',
   "Get-CimInstance Win32_BIOS | Select-Object Manufacturer, SMBIOSBIOSVersion, SerialNumber | Format-List"],
  ['Check BitLocker status', 'Security', 'powershell', 108, 1, 'Shows encryption and protection state for each volume.',
   "Get-BitLockerVolume | Select-Object MountPoint, VolumeStatus, ProtectionStatus, EncryptionPercentage | Format-Table -AutoSize"],
  ['Audit local administrators', 'Security', 'powershell', 109, 1, 'Lists the members of the local Administrators group.',
   "Get-LocalGroupMember -Group 'Administrators' | Select-Object Name, ObjectClass, PrincipalSource | Format-Table -AutoSize"],
  ['Install 7-Zip (silent)', 'Software Install', 'powershell', 110, 1, 'Downloads and installs the current 7-Zip release without prompts.',
   "# Uses the company software share; see the change record for the approved version.\nStart-Process msiexec.exe -ArgumentList '/i', '\\\\SRV-FILE-01\\Software\\7z-x64.msi', '/qn', '/norestart' -Wait\n\"7-Zip install finished with exit code \$LASTEXITCODE\""],
  ['New laptop imaging checklist (manual)', 'Maintenance', 'powershell', null, 1, 'Reference copy of the imaging steps. Not linked to Tactical RMM, so it cannot be run from here.',
   "# 1. Join the domain  2. Install Office  3. Enable BitLocker  4. Register the device in Intune"],
  ['Legacy VPN client cleanup', 'Software Install', 'cmd', 111, 0, 'Removes the old VPN client. Disabled since the migration finished.',
   "wmic product where \"name like 'Legacy VPN%'\" call uninstall /nointeractive"],
];
foreach ($scripts as [$name, $cat, $type, $tid, $enabled, $desc, $body]) {
    $have = one("SELECT id FROM rmm_scripts WHERE name = " . s($name) . " AND rmm_integration_id = " . ($tid === null ? 0 : $intg_tac) . " LIMIT 1");
    if (!$have) {
        q("INSERT INTO rmm_scripts SET name = " . s($name) . ", category = " . s($cat) . ", description = " . s($desc) . ",
            script_type = " . s($type) . ", script_body = " . s($body) . ", tactical_script_id = " . n($tid) . ",
            rmm_integration_id = " . ($tid === null ? 0 : $intg_tac) . ", enabled = $enabled,
            created_by = " . ($tid === null ? $uid_alex : 0) . ", created_at = NOW() - INTERVAL 28 DAY");
    }
}
function script_id(string $name): ?int
{
    $r = one("SELECT id FROM rmm_scripts WHERE name = " . s($name) . " LIMIT 1");
    return $r ? intval($r['id']) : null;
}
{
    // script, device, user, status, started min ago, minutes it ran, job id, output, error
    $runs = [
      ['Clear Windows temp files', 'LT-FIN-02', $uid_priya, 'completed', 170, 2, 'demo-job-1001', "Removed 1,932 files from C:\\Windows\\Temp and the user temp folder.\nFreed 3.4 GB on C:", null],
      ['Restart Print Spooler and clear queue', 'PC-PLANT-07', $uid_marcus, 'completed', 15, 1, 'demo-job-1002', "Cleared 2 stuck job(s). Spooler is Running.", null],
      ['Check BitLocker status', 'LT-EXEC-01', $uid_alex, 'completed', 1300, 1, 'demo-job-1003', "MountPoint VolumeStatus         ProtectionStatus EncryptionPercentage\n---------- ------------         ---------------- --------------------\nC:         FullyEncrypted       On                                100", null],
      ['Install 7-Zip (silent)', 'DT-HR-01', $uid_priya, 'failed', 2800, 0, 'demo-job-1004', null, 'Tactical RMM API returned HTTP 400: Agent is offline'],
      ['Collect installed software list', 'SRV-FILE-01', $uid_marcus, 'running', 4, null, 'demo-job-1005', null, null],
      ['Report BIOS version and serial number', 'LT-ENG-04', $uid_priya, 'completed', 3 * 1440, 1, 'demo-job-1006', "Manufacturer SMBIOSBIOSVersion SerialNumber\n------------ ----------------- ------------\nDell Inc.    1.14.1            SRDEMO-PR5570-0504", null],
      ['Audit local administrators', 'DT-SALES-03', $uid_marcus, 'completed', 5 * 1440, 1, 'demo-job-1007', "Name                       ObjectClass PrincipalSource\n----                       ----------- ---------------\nDT-SALES-03\\Administrator  User        Local\nSUMMITRIDGE\\Domain Admins  Group       ActiveDirectory\nSUMMITRIDGE\\obaker         User        ActiveDirectory", null],
      ['Flush DNS and renew IP address', 'DT-SALES-03', $uid_marcus, 'completed', 6 * 1440, 1, 'demo-job-1008', "Windows IP Configuration\n\nSuccessfully flushed the DNS Resolver Cache.", null],
      ['Windows Update: scan and report', 'PC-PLANT-07', $uid_alex, 'completed', 2 * 1440, 3, 'demo-job-1009', "2024-09 Cumulative Update for Windows 10 Version 21H2  (Critical)\n2024-09 .NET Framework Security Update  (Important)\nMalicious Software Removal Tool  (Moderate)", null],
      ['Show disk usage by folder (Linux)', 'SRV-APP-02', $uid_marcus, 'completed', 4 * 1440, 1, 'demo-job-1010', "31G\t/var\n24G\t/var/lib\n19G\t/var/lib/postgresql\n11G\t/home\n8.2G\t/home/plm\n3.1G\t/var/log", null],
      ['Clear Windows temp files', 'DT-SALES-03', $uid_priya, 'failed', 7 * 1440, 1, 'demo-job-1011', "Freed 0.0 GB on C:", "Remove-Item : Access to the path 'C:\\Windows\\Temp' is denied. The script was not run as an administrator."],
      ['New laptop imaging checklist (manual)', 'LT-ENG-04', $uid_alex, 'pending', 8 * 1440, null, null, null, 'Script has no Tactical RMM ID - sync scripts first'],
    ];
    foreach ($runs as [$sname, $device, $uid, $status, $started, $dur, $job, $out, $err]) {
        $sid = script_id($sname);
        if (!$sid || !isset($asset_of[$device])) {
            continue;                                  // script or device (contract asset) not present here
        }
        $exists = $job !== null
            ? one("SELECT id FROM rmm_script_runs WHERE tactical_job_id = " . s($job) . " LIMIT 1")
            : one("SELECT id FROM rmm_script_runs WHERE script_id = $sid AND asset_id = " . intval($asset_of[$device]) . " AND tactical_job_id IS NULL LIMIT 1");
        if ($exists) {
            continue;
        }
        $fin = ($dur === null) ? 'NULL' : ago($started - $dur);
        q("INSERT INTO rmm_script_runs SET script_id = $sid, asset_id = " . intval($asset_of[$device]) . ", user_id = $uid,
            status = " . s($status) . ", tactical_job_id = " . s($job) . ", output = " . s($out) . ", error_message = " . s($err) . ",
            started_at = " . ago($started) . ", finished_at = $fin");
    }
}

// ------------------------------------------------------------------------------------------
// 6. Check policies and their deployments
// ------------------------------------------------------------------------------------------
$policies = [
  // name, platform, type, warn, crit, interval, params, description, enabled
  ['Disk space: C: drive',        'windows', 'diskspace', 80, 90, 300, '{"disk":"C"}', 'Alerts when the system drive is filling up.', 1],
  ['Disk space: D: data drive',   'windows', 'diskspace', 85, 95, 300, '{"disk":"D"}', 'Servers and workstations that have a data volume.', 1],
  ['CPU load (sustained)',        'windows', 'cpuload',   85, 95, 120, '{}',           'Alerts when average CPU stays high across several runs.', 1],
  ['Memory usage',                'windows', 'memory',    85, 95, 120, '{}',           '', 1],
  ['Print Spooler service',       'windows', 'winsvc',    null, null, 300, '{"svc_name":"Spooler","restart_if_stopped":true}', 'Restarts the spooler automatically and alerts if it keeps stopping.', 1],
  ['Application log errors',      'windows', 'eventlog',  null, null, 600, '{"log_name":"Application","event_type":"ERROR","fail_count":3,"search_last_days":1}', 'Three or more ERROR events in a day.', 1],
  ['Disk space: root filesystem', 'linux',   'diskspace', 80, 90, 300, '{"disk":"/"}', '', 1],
  ['Disk space: startup volume',  'macos',   'diskspace', 80, 90, 300, '{"disk":"/"}', '', 1],
  ['Ping: default gateway',       'any',     'ping',      null, null, 60, '{"ip":"10.0.0.1","failures":5}', 'Flags a device that loses its network gateway.', 1],
  ['Ping: ISP handoff',           'any',     'ping',      null, null, 60, '{"ip":"192.0.2.1","failures":5}', 'Switched off while the ISP works on the circuit.', 0],
];
foreach ($policies as [$name, $plat, $type, $warn, $crit, $interval, $params, $desc, $enabled]) {
    if (!one("SELECT id FROM rmm_check_policies WHERE name = " . s($name) . " LIMIT 1")) {
        q("INSERT INTO rmm_check_policies SET name = " . s($name) . ", platform = " . s($plat) . ", check_type = " . s($type) . ",
            warning_threshold = " . n($warn) . ", critical_threshold = " . n($crit) . ", check_interval = $interval,
            check_params = " . s($params) . ", description = " . s($desc === '' ? null : $desc) . ", enabled = $enabled,
            created_by = $uid_alex, created_at = NOW() - INTERVAL 27 DAY");
    }
}
$deploy = [
  'Disk space: C: drive'        => ['LT-EXEC-01', 'LT-FIN-02', 'DT-HR-01', 'DT-SALES-03', 'LT-ENG-04', 'PC-PLANT-07', 'SRV-FILE-01', 'WS-QC-11', 'DT-WHSE-05'],
  'Disk space: D: data drive'   => ['SRV-FILE-01'],
  'CPU load (sustained)'        => ['LT-EXEC-01', 'LT-FIN-02', 'LT-ENG-04', 'PC-PLANT-07', 'WS-QC-11', 'SRV-FILE-01'],
  'Memory usage'                => ['LT-EXEC-01', 'LT-FIN-02', 'DT-SALES-03', 'LT-ENG-04', 'DT-HR-01', 'WS-QC-11'],
  'Print Spooler service'       => ['PC-PLANT-07', 'WS-QC-11', 'DT-HR-01', 'DT-WHSE-05'],
  'Disk space: root filesystem' => ['SRV-APP-02'],
  'Ping: default gateway'       => ['SRV-FILE-01', 'SRV-APP-02', 'PC-PLANT-07'],
];
foreach ($deploy as $pname => $devs) {
    $p = one("SELECT id FROM rmm_check_policies WHERE name = " . s($pname) . " LIMIT 1");
    if (!$p) { continue; }
    foreach ($devs as $dname) {
        if (isset($link_id[$dname])) {
            q("INSERT IGNORE INTO rmm_check_deployments SET policy_id = " . intval($p['id']) . ", link_id = " . intval($link_id[$dname]) . ",
                tactical_check_id = " . s((string) (5000 + intval($p['id']) * 20 + intval($link_id[$dname]) % 20)) . ", status = 'active', deployed_at = NOW() - INTERVAL 20 DAY");
        }
    }
}

// ------------------------------------------------------------------------------------------
// 7. Remote-session history, sync logs
// ------------------------------------------------------------------------------------------
{
    $sessions = [
      ['LT-FIN-02',   $uid_priya,  'meshcentral', 'https://rmm.summitridge.example/mesh/?login=[redacted]&node=lt-fin-02', 160],
      ['PC-PLANT-07', $uid_marcus, 'reboot',      'Reboot requested', 1500],
      ['DT-SALES-03', $uid_marcus, 'command',     'Ran powershell command: Get-Volume', 2 * 1440],
      ['LT-ENG-04',   $uid_priya,  'meshcentral', 'https://rmm.summitridge.example/mesh/?login=[redacted]&node=lt-eng-04', 3 * 1440],
      ['SRV-FILE-01', $uid_alex,   'patch_scan',  'Patch scan queued', 3 * 1440 + 200],
      ['DT-SALES-03', $uid_priya,  'tactical',    'https://rmm.summitridge.example/takecontrol/dt-sales-03', 5 * 1440],
      ['LT-EXEC-01',  $uid_alex,   'meshcentral', 'https://rmm.summitridge.example/mesh/?login=[redacted]&node=lt-exec-01', 6 * 1440],
    ];
    $i = 0;
    foreach ($sessions as [$device, $uid, $type, $url, $min]) {
        $i++;
        if (!isset($asset_of[$device]) || one("SELECT id FROM rmm_remote_sessions WHERE user_agent LIKE 'demo-session-$i %' LIMIT 1")) { continue; }
        $aid = intval($asset_of[$device]);
        $cid = intval(one("SELECT asset_client_id c FROM assets WHERE asset_id = $aid")['c']);
        q("INSERT INTO rmm_remote_sessions SET asset_id = $aid, client_id = " . ($cid ?: 'NULL') . ", user_id = $uid,
            connection_type = " . s($type) . ", connection_url = " . s($url) . ", source_ip = '10.0.20.5',
            user_agent = " . s("demo-session-$i Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/126.0") . ", created_at = " . ago($min));
    }
}
if (!one("SELECT id FROM rmm_sync_log WHERE integration_id = $intg_sop LIMIT 1")) {
    foreach ([[3, 0, 0, 0, 'success', 9 * 1440], [0, 3, 0, 0, 'success', 126]] as [$c, $u, $m, $sk, $st, $min]) {
        q("INSERT INTO rmm_sync_log SET integration_id = $intg_sop, started_at = " . ago($min) . ", finished_at = NOW() - INTERVAL " . ($min - 1) . " MINUTE,
            status = " . s($st) . ", assets_created = $c, assets_updated = $u, assets_matched = $m, assets_skipped = $sk, triggered_by = $uid_alex");
    }
}

if (!one("SELECT id FROM rmm_sync_log WHERE integration_id = $intg_tac LIMIT 1")) {
    // created, updated, matched, skipped, errors, status, started min ago, triggered by
    $logs = [
      [4, 0,  6, 2, '',  'success', 9 * 1440,   $uid_alex],
      [0, 0,  0, 0, 'cURL error: Operation timed out after 15001 milliseconds with 0 bytes received', 'failed', 2 * 1440, $uid_alex],
      [0, 10, 0, 0, '',  'success', 126,        0],
      [0, 10, 0, 0, '',  'success', 66,         0],
      [0, 10, 0, 0, '',  'success', 6,          0],
    ];
    foreach ($logs as [$c, $u, $m, $sk, $err, $st, $min, $by]) {
        q("INSERT INTO rmm_sync_log SET integration_id = $intg_tac, started_at = " . ago($min) . ", finished_at = NOW() - INTERVAL " . ($min - 1) . " MINUTE + INTERVAL 40 SECOND,
            status = " . s($st) . ", assets_created = $c, assets_updated = $u, assets_matched = $m, assets_skipped = $sk,
            errors = " . s($err === '' ? null : $err) . ", triggered_by = $by");
    }
}
// ------------------------------------------------------------------------------------------
// 8. UniFi: site mappings and sync log
// ------------------------------------------------------------------------------------------
$sites = [
  [$unifi_hq,    'demo-site-eng',   'Engineering',           null],                       // auto-match by name
  [$unifi_hq,    'demo-site-exec',  'Executive Office',      null],
  [$unifi_hq,    'demo-site-guest', 'Guest Wi-Fi',           0],                          // skip
  [$unifi_plant, 'demo-site-prod',  'Production',            null],
  [$unifi_plant, 'demo-site-whse',  'Warehouse & Logistics', null],
  [$unifi_plant, 'demo-site-vis',   'Plant Visitors',        'Production'],               // explicit override
  [$unifi_plant, 'demo-site-kiosk', 'Break Room Kiosks',     null],                       // no matching department
];
foreach ($sites as [$intg, $sid, $sname, $map]) {
    $cid = $map === null ? 'NULL' : ($map === 0 ? '0' : (string) intval(client_id($map)));
    q("INSERT IGNORE INTO unifi_site_mappings SET integration_id = $intg, unifi_site_id = " . s($sid) . ", unifi_site_name = " . s($sname) . ", client_id = $cid");
}
foreach ([[$unifi_hq, [[0, 6, 0, 0, 0, 1, 0, 0, 2, 'success', 1530], [0, 6, 0, 0, 0, 1, 0, 0, 2, 'success', 90], [3, 0, 0, 0, 1, 0, 0, 2, 0, 'success', 30]]],
          [$unifi_plant, [[0, 0, 0, 0, 0, 0, 0, 0, 0, 'failed', 1530, 'Could not connect to 10.20.0.3:443 - Connection timed out'],
                          [0, 4, 0, 0, 0, 1, 0, 0, 3, 'success', 90], [4, 0, 0, 0, 1, 0, 0, 3, 0, 'success', 30]]]] as [$intg, $rows]) {
    if (one("SELECT id FROM unifi_sync_log WHERE integration_id = $intg LIMIT 1")) { continue; }
    foreach ($rows as $r) {
        [$dc, $du, $dm, $ds, $wc, $wu, $ws, $nc, $nu, $st, $min] = array_slice($r, 0, 11);
        $err = $r[11] ?? null;
        q("INSERT INTO unifi_sync_log SET integration_id = $intg, started_at = " . ago($min) . ", finished_at = NOW() - INTERVAL " . ($min - 1) . " MINUTE,
            status = " . s($st) . ", devices_created = $dc, devices_updated = $du, devices_matched = $dm, devices_skipped = $ds,
            wifi_created = $wc, wifi_updated = $wu, wifi_skipped = $ws, networks_created = $nc, networks_updated = $nu, networks_skipped = 0,
            errors = " . s($err) . ", triggered_by = $uid_alex");
    }
}

// ------------------------------------------------------------------------------------------
// 9. Intune device links and sync log
// ------------------------------------------------------------------------------------------
// name, OS, version, manufacturer, model, mgmt agent, compliance, encrypted, primary user, enrolled days ago, last sync min ago, serial
$intune = [
  ['LT-EXEC-01', 'Windows', '10.0.22631.3737', 'Dell Inc.',  'Latitude 7440',   'mdm', 'compliant',     1, 'helen.brandt@summitridge.example',   210, 95,   'SRDEMO-LT7440-0101'],
  ['LT-FIN-02',  'Windows', '10.0.22631.3737', 'Dell Inc.',  'Latitude 5440',   'mdm', 'noncompliant',  0, 'tom.kessler@summitridge.example',    198, 140,  'SRDEMO-LT5440-0202'],
  ['DT-HR-01',   'Windows', '10.0.19045.4651', 'HP',         'EliteDesk 800 G9','mdm', 'compliant',     1, 'miguel.alvarez@summitridge.example', 320, 2900, 'SRDEMO-ED800-0301'],
  ['LT-ENG-04',  'Windows', '10.0.22631.3737', 'Dell Inc.',  'Precision 5570',  'mdm', 'compliant',     1, 'maya.singh@summitridge.example',     150, 60,   'SRDEMO-PR5570-0504'],
  ['LT-OPS-12',  'Windows', '10.0.22631.3737', 'LENOVO',     'ThinkPad T14 Gen 4', 'mdm', 'compliant',  1, 'raj.patel@summitridge.example',      12,  35,   'SRDEMO-T14G4-1212'],
  ['SCAN-WH-02', 'Android', '13',              'Zebra Technologies', 'TC52',    'mdm', 'compliant',     1, 'tara.whitfield@summitridge.example', 260, 180,  'SRDEMO-TC52-0202'],
  ['TAB-WHSE-01','iPadOS',  '17.5.1',          'Apple',      'iPad (10th generation)', 'mdm', 'inGracePeriod', 1, 'frank.delgado@summitridge.example', 45, 400, 'SRDEMO-IPAD-2101'],
  ['MAC-MKT-03', 'macOS',   '14.5',            'Apple',      'MacBook Pro 14-inch (M3 Pro)', 'mdm', 'compliant', 1, 'zoe.hartman@summitridge.example', 60, 75, 'SRDEMO-MBP14-1503'],
];
foreach ($intune as [$name, $os, $osv, $mfr, $model, $mgmt, $comp, $enc, $upn, $enrolled, $sync, $serial]) {
    $asset = asset_row($name);
    if (!$asset) { continue; }
    $aid = intval($asset['asset_id']);
    q("INSERT IGNORE INTO asset_intune_links SET asset_id = $aid, microsoft_integration_id = $ms_id,
        intune_device_id = " . s(substr(sha1('intune' . $name), 0, 8) . '-' . substr(sha1('intune' . $name), 8, 4) . '-' . substr(sha1('intune' . $name), 12, 4) . '-' . substr(sha1('intune' . $name), 16, 4) . '-' . substr(sha1('intune' . $name), 20, 12)) . ",
        azure_ad_device_id = " . s(substr(sha1('aad' . $name), 0, 8) . '-' . substr(sha1('aad' . $name), 8, 4) . '-' . substr(sha1('aad' . $name), 12, 4) . '-' . substr(sha1('aad' . $name), 16, 4) . '-' . substr(sha1('aad' . $name), 20, 12)) . ",
        hostname = " . s($name) . ", serial_number = " . s($serial) . ", os_name = " . s($os) . ", os_version = " . s($osv) . ",
        manufacturer = " . s($mfr) . ", model = " . s($model) . ", management_agent = " . s($mgmt) . ", compliance_state = " . s($comp) . ",
        is_encrypted = $enc, primary_user_upn = " . s($upn) . ", enrolled_at = " . ago($enrolled * 1440) . ",
        intune_last_sync_at = " . ago($sync) . ", last_sync = " . ago(30) . ", created_at = NOW() - INTERVAL 20 DAY");
}
if (!one("SELECT id FROM intune_sync_log WHERE microsoft_integration_id = $ms_id LIMIT 1")) {
    foreach ([[8, 0, 0, 1, '', 'success', 12 * 1440], [0, 8, 0, 0, '', 'success', 1470], [0, 7, 0, 0, '', 'success', 30]] as [$c, $u, $m, $sk, $err, $st, $min]) {
        q("INSERT INTO intune_sync_log SET microsoft_integration_id = $ms_id, started_at = " . ago($min) . ", finished_at = NOW() - INTERVAL " . ($min - 1) . " MINUTE,
            status = " . s($st) . ", devices_created = $c, devices_updated = $u, devices_matched = $m, devices_skipped = $sk, triggered_by = $uid_alex");
    }
}

// ------------------------------------------------------------------------------------------
// 10. Comet Backup: department mapping, backup alerts and their tickets
// ------------------------------------------------------------------------------------------
$comet_map = [
  'Executive Office'      => 'summitridge-exec',
  'Finance & Accounting'  => 'summitridge-fin',
  'Human Resources'       => 'summitridge-hr',
  'Sales & Marketing'     => 'summitridge-sales',
  'Production'            => 'summitridge-plant',
  'Warehouse & Logistics' => 'summitridge-whse',
  'Engineering'           => 'summitridge-eng',
];
foreach ($comet_map as $dept => $cu) {
    $cid = client_id($dept);
    if ($cid) {
        q("INSERT IGNORE INTO comet_client_map SET map_client_id = $cid, map_comet_username = " . s($cu));
    }
}
$comet_alerts = [
  // user, device, type, severity, status, message, tickets: [subject, priority, ticket status], created min ago, ack by, ack min, resolved min
  ['summitridge-servers', 'SRV-FILE-01', 'failed', 'critical', 'new',
   'Comet Backup reported a failure for device SRV-FILE-01 (user: summitridge-servers). Status: Error (code: 7002). Error: VSS snapshot failed while reading D:\\Shares (access is denied)',
   'Backup Error — SRV-FILE-01', 380, null, null, null],
  ['summitridge-sales', 'DT-SALES-03', 'missed', 'warning', 'new',
   'No backup job reported for device DT-SALES-03 (user: summitridge-sales) in over 72 hours.',
   'Backup Missed — DT-SALES-03', 900, null, null, null],
  ['summitridge-eng', 'LT-ENG-04', 'failed', 'warning', 'acknowledged',
   'Comet Backup reported a failure for device LT-ENG-04 (user: summitridge-eng). Status: Warning (code: 7001). Error: 3 files were skipped because another program had them open',
   'Backup Warning — LT-ENG-04', 1700, $uid_priya, 1500, null],
  ['summitridge-fin', 'LT-FIN-02', 'failed', 'critical', 'resolved',
   'Comet Backup reported a failure for device LT-FIN-02 (user: summitridge-fin). Status: Timeout (code: 7000). Error: The backup server did not respond in time',
   'Backup Timeout — LT-FIN-02', 6 * 1440, null, null, 5 * 1440 + 600],
];
foreach ($comet_alerts as [$cu, $dev, $type, $sev, $status, $msg, $subject, $created, $ack_by, $ack_min, $res_min]) {
    if (one("SELECT alert_id FROM comet_backup_alerts WHERE alert_comet_username = " . s($cu) . " AND alert_device_name = " . s($dev) . " AND alert_type = " . s($type) . " LIMIT 1")) {
        continue;
    }
    $dept = array_search($cu, $comet_map, true);
    $cid = $dept ? intval(client_id($dept)) : 0;
    $details = $type === 'missed'
        ? $msg . "\n\nThe device may be offline, the Comet agent may not be running, or its backup schedule may be disabled. Please check the device."
        : preg_replace('/^Comet Backup reported a failure for device (\S+) \(user: ([^)]+)\)\. Status: (.+?) \(code: (\d+)\)\. Error: (.*)$/s',
            "Comet Backup reported a failure for device **$1** (user: $2).\n\nStatus: $3 (code: $4)\nError: $5\n\nPlease check the device is online, the Comet agent is running, and there are no storage issues.", $msg);
    $resolved = $status === 'resolved';
    $tstatus = $resolved ? 5 : ($status === 'acknowledged' ? 2 : 1);
    $tid = make_ticket($subject, $details, 'Comet Backup', 'High', $tstatus, 0, $status === 'acknowledged' ? $uid_priya : 0, $cid, 0, $created, $resolved ? $res_min : null);
    if ($resolved) {
        if (!one("SELECT ticket_reply_id FROM ticket_replies WHERE ticket_reply_ticket_id = $tid LIMIT 1")) {
            q("INSERT INTO ticket_replies SET ticket_reply = 'Backup succeeded - device is healthy again. Ticket auto-resolved by Comet integration.',
                ticket_reply_type = 'Internal', ticket_reply_by = 0, ticket_reply_ticket_id = $tid, ticket_reply_created_at = " . ago($res_min));
        }
    }
    q("INSERT INTO comet_backup_alerts SET alert_comet_username = " . s($cu) . ", alert_device_name = " . s($dev) . ", alert_type = " . s($type) . ",
        alert_severity = " . s($sev) . ", alert_message = " . s($msg) . ", alert_client_id = " . ($cid ?: 'NULL') . ", alert_ticket_id = $tid,
        alert_status = " . s($status) . ", alert_acknowledged_by = " . n($ack_by) . ", alert_acknowledged_at = " . ago($ack_min) . ",
        alert_created_at = " . ago($created) . ", alert_resolved_at = " . ago($res_min));
}

echo "60-endpoints seed applied: ", count($link_id), " device links, ",
     one("SELECT COUNT(*) c FROM rmm_alerts")['c'], " RMM alerts, ",
     one("SELECT COUNT(*) c FROM comet_backup_alerts")['c'], " backup alerts.\n";
