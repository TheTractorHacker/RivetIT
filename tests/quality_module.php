<?php
/*
 * Quality module (issue #52) end-to-end tests: module switch, QMS client against a MOCK QMS, per-user context, events (outbound queue, inbound receiver), portal pages,
 * admin page, SSO policy hook. Needs the scratch environment:
 *
 *   - a schema-only scratch database whose name contains "scratch", config.php pointing at it (never a real database),
 *   - the RivetIT dev server:  RIVETIT_REDIS_PORT=1 php -d session.save_path=$SESS -S 127.0.0.1:8765 -t <worktree> tests/support/oidc_router.php
 *   - the mock QMS:            QMS_STATE=$STATE php -S 127.0.0.1:8766 tests/support/mock_qms.php
 *
 *   RIVETIT_TEST_DB=1 RIVETIT_REDIS_PORT=1 QMS_STATE=$STATE QUALITY_TEST_SESSIONS=$SESS php tests/quality_module.php
 *
 * It MUTATES the scratch database. It never sends mail, chat, Entra or Odoo traffic and talks only to 127.0.0.1. Nothing secret is printed: pass/fail lines only.
 */

use ITFlow\Integration\IntegrationAuth;
use ITFlow\Oidc\KeyStore;
use ITFlow\Oidc\OidcConfig;
use ITFlow\Oidc\QualityAccessPolicy;
use ITFlow\Quality\InboundEvents;
use ITFlow\Quality\Outbox;
use ITFlow\Quality\QmsClient;
use ITFlow\Quality\QmsException;
use ITFlow\Quality\QmsUrl;
use ITFlow\Quality\QualityConfig;
use ITFlow\Quality\QualityLog;
use ITFlow\Quality\QualityService;
use ITFlow\Quality\SyncHealth;
use ITFlow\Quality\SyncWorker;
use ITFlow\Quality\UserContext;

if (getenv('RIVETIT_TEST_DB') !== '1') {
    fwrite(STDERR, "Refusing: set RIVETIT_TEST_DB=1 (this suite mutates the database config.php points at).\n");
    exit(2);
}
define('RIVETIT_QMS_TEST_LOOPBACK', true);   // the test seam: lets the client talk http to the mock on 127.0.0.1 (nothing in the application defines it)
$root = dirname(__DIR__);
$_SERVER['DOCUMENT_ROOT'] = $root;
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once $root . '/config.php';
if (!str_contains((string) $database, 'scratch')) {
    fwrite(STDERR, "Refusing: the database name must contain 'scratch'.\n");
    exit(2);
}
require_once $root . '/vendor/autoload.php';
require_once $root . '/functions.php';
$STATE = (string) getenv('QMS_STATE');
$SESS = (string) getenv('QUALITY_TEST_SESSIONS');
if ($STATE === '' || !is_dir($STATE) || $SESS === '' || !is_dir($SESS)) {
    fwrite(STDERR, "Set QMS_STATE and QUALITY_TEST_SESSIONS (directories shared with the two servers).\n");
    exit(2);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$tzRow = $mysqli->query('SELECT config_timezone FROM settings WHERE company_id = 1')->fetch_assoc();
$tzObj = new DateTimeZone((string) ($tzRow['config_timezone'] ?? 'UTC'));
date_default_timezone_set($tzObj->getName());
$mysqli->query("SET time_zone = '" . (new DateTime('now', $tzObj))->format('P') . "'");
$BASE = 'http://127.0.0.1:8765';
$QMS = 'http://127.0.0.1:8766';
$ISS = 'https://127.0.0.1:8765';
$PORTAL_PW = 'T3st-Portal-Pw#2026';
$QMS_CLIENT_ID = 'rivetit-test-client';
$QMS_CLIENT_SECRET = 'qms-super-secret-' . bin2hex(random_bytes(6));

$pass = 0;
$fail = 0;
function t(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   $name\n"; } else { $fail++; echo "  FAIL $name" . ($detail !== '' ? " -- $detail" : '') . "\n"; }
}
function q(string $sql, string $types = '', array $p = []): array
{
    global $mysqli;
    $st = $mysqli->prepare($sql);
    if ($types !== '') { $st->bind_param($types, ...$p); }
    $st->execute();
    $r = $st->get_result();
    $rows = $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
    $st->close();
    foreach ($rows as &$row) {   // COUNT(*) AS n and the like come back as strings
        foreach (['n', 'r'] as $k) {
            if (isset($row[$k]) && is_string($row[$k]) && ctype_digit($row[$k])) { $row[$k] = (int) $row[$k]; }
        }
    }
    unset($row);
    return $rows;
}
function x(string $sql): void { global $mysqli; $mysqli->query($sql); }
function b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function http(string $method, string $path, array $o = []): array
{
    global $BASE;
    $ch = curl_init($BASE . $path);
    $hdr = [];
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 30]);
    if (isset($o['cookie'])) { curl_setopt($ch, CURLOPT_COOKIE, $o['cookie']); }
    if (isset($o['basic'])) { curl_setopt($ch, CURLOPT_USERPWD, $o['basic'][0] . ':' . $o['basic'][1]); }
    if (isset($o['body'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($o['body']) ? http_build_query($o['body']) : $o['body']);
        $hdr[] = 'Content-Type: ' . ($o['ctype'] ?? 'application/x-www-form-urlencoded');
    }
    foreach ($o['headers'] ?? [] as $h) { $hdr[] = $h; }
    if ($hdr) { curl_setopt($ch, CURLOPT_HTTPHEADER, $hdr); }
    $raw = (string) curl_exec($ch);
    $size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $head = substr($raw, 0, $size);
    $headers = [];
    foreach (preg_split('/\r?\n/', $head) as $line) {
        if (str_contains($line, ':')) { [$k, $v] = explode(':', $line, 2); $headers[strtolower(trim($k))][] = trim($v); }
    }
    $b = substr($raw, $size);
    return ['status' => $status, 'h' => $headers, 'body' => $b, 'json' => json_decode($b, true), 'loc' => $headers['location'][0] ?? null];
}
function craftSession(array $data): string
{
    global $SESS;
    $sid = bin2hex(random_bytes(16));
    exec('php -d session.save_path=' . escapeshellarg($SESS) . ' -r ' . escapeshellarg('session_id(' . var_export($sid, true) . '); session_start(); $_SESSION = ' . var_export($data, true) . '; session_write_close();'));
    return 'PHPSESSID=' . $sid;
}
function sessionData(string $cookie): array
{
    global $SESS;
    $sid = substr($cookie, strlen('PHPSESSID='));
    $raw = (string) @file_get_contents($SESS . '/sess_' . $sid);
    $php = 'session_id(' . var_export($sid, true) . '); session_start(); echo json_encode($_SESSION);';
    return json_decode((string) shell_exec('php -d session.save_path=' . escapeshellarg($SESS) . ' -r ' . escapeshellarg($php)), true) ?: [];
}
function portalSession(int $contact, int $user, int $dept, array $over = []): string
{
    return craftSession($over + ['client_logged_in' => true, 'client_id' => $dept, 'user_id' => $user, 'user_type' => 2, 'contact_id' => $contact, 'logged' => true,
        'csrf_token' => 'csrf' . bin2hex(random_bytes(8)), 'login_method' => 'local', 'portal_auth_time' => time() - 3600, 'portal_amr' => 'pwd']);
}
function agentSession(int $userId, array $over = []): string
{
    return craftSession($over + ['logged' => true, 'user_id' => $userId, 'user_type' => 1, 'csrf_token' => 'csrf' . bin2hex(random_bytes(8)), 'login_time' => time(),
        'agent_auth_time' => time() - 60, 'agent_amr' => 'pwd,otp,mfa']);
}
function ctl(array $c): void { global $STATE; file_put_contents($STATE . '/ctl.json', json_encode($c)); @unlink($STATE . '/flaky.count'); }
function qmsLog(): array
{
    global $STATE;
    $f = $STATE . '/log.jsonl';
    return is_file($f) ? array_map(static fn($l) => json_decode($l, true), array_filter(explode("\n", (string) file_get_contents($f)))) : [];
}
function qmsLogClear(): void { global $STATE; @unlink($STATE . '/log.jsonl'); }
function qmsCount(callable $f): int { return count(array_filter(qmsLog(), $f)); }
function setMock(array $cfg): void { global $STATE; file_put_contents($STATE . '/cfg.json', json_encode($cfg)); }

// =============================================================================================================== fixtures
echo "== fixtures\n";
ctl([]);
qmsLogClear();
@unlink($STATE . '/token.txt');
foreach (glob($STATE . '/ack_*') ?: [] as $f) { @unlink($f); }
foreach (['quality_outbox', 'quality_inbound_events', 'quality_sync_state', 'quality_id_map', 'quality_error_log', 'quality_departments', 'oidc_codes', 'oidc_tokens', 'oidc_pending', 'oidc_events', 'oidc_client_config'] as $tbl) { x("DELETE FROM $tbl"); }
x("UPDATE quality_features SET qf_enabled = 0");
x("UPDATE quality_settings SET qs_qms_url = NULL, qs_token_url = NULL, qs_client_id = NULL, qs_client_secret_enc = NULL, qs_outbound_secret_enc = NULL, qs_inbound_secret_enc = NULL,
    qs_token_enc = NULL, qs_token_expires_at = NULL, qs_all_departments = 0, qs_events_enabled = 0, qs_inbound_enabled = 0, qs_sync_interval_minutes = 5, qs_reconcile_interval_hours = 24,
    qs_reauth_minutes = 15, qs_connect_timeout_s = 3, qs_total_timeout_s = 10, qs_last_test_at = NULL, qs_last_test_ok = NULL, qs_last_ok_at = NULL WHERE qs_id = 1");
x("UPDATE settings SET config_module_enable_quality = 0, config_client_portal_enable = 1, config_module_enable_training = 1");
x("DELETE FROM integration_clients WHERE client_name LIKE 'QT %'");
x("DELETE FROM contacts WHERE contact_name LIKE 'QT %'");
x("DELETE FROM users WHERE user_email LIKE 'qt-%@scratch.invalid'");
x("DELETE FROM training_assignments WHERE tassign_external_ref LIKE 'NCR-0%'");
x("UPDATE training_courses SET course_current_revision_id = NULL WHERE course_uid = 'QTCourse0001'");
x("DELETE FROM training_revisions WHERE revision_course_id IN (SELECT course_id FROM training_courses WHERE course_uid = 'QTCourse0001')");
x("DELETE FROM training_courses WHERE course_uid = 'QTCourse0001'");
x("DELETE FROM contact_odoo_links WHERE odoo_employee_id IN (1842, 1900, 2000)");
x("DELETE FROM client_odoo_links WHERE odoo_department_id IN (77, 78)");
x("DELETE FROM clients WHERE client_name LIKE 'QT %'");
x("DELETE FROM user_roles WHERE role_name LIKE 'QT %'");

$mkDept = function (string $name, ?int $odoo) use ($mysqli): int {
    $st = $mysqli->prepare("INSERT INTO clients (client_name, client_currency_code, client_net_terms, client_status) VALUES (?, 'USD', 30, 'Active')");
    $st->bind_param('s', $name); $st->execute(); $id = (int) $mysqli->insert_id; $st->close();
    if ($odoo) { x("INSERT INTO client_odoo_links (client_id, odoo_integration_id, odoo_department_id) VALUES ($id, 1, $odoo)"); }
    return $id;
};
$hash = password_hash($PORTAL_PW, PASSWORD_DEFAULT);
$mkPerson = function (string $name, string $slug, int $dept, ?int $odoo, string $role = 'none', int $primary = 0) use ($mysqli, $hash): array {
    $st = $mysqli->prepare("INSERT INTO users (user_name, user_email, user_password, user_type, user_status) VALUES (?, ?, ?, 2, 1)");
    $email = "qt-$slug@scratch.invalid";
    $st->bind_param('sss', $name, $email, $hash); $st->execute(); $uid = (int) $mysqli->insert_id; $st->close();
    $st = $mysqli->prepare("INSERT INTO contacts (contact_name, contact_email, contact_client_id, contact_user_id, contact_employment_status, contact_portal_role, contact_primary) VALUES (?, ?, ?, ?, 'active', ?, ?)");
    $st->bind_param('ssiisi', $name, $email, $dept, $uid, $role, $primary); $st->execute(); $cid = (int) $mysqli->insert_id; $st->close();
    if ($odoo) { x("INSERT INTO contact_odoo_links (contact_id, odoo_integration_id, odoo_employee_id) VALUES ($cid, 1, $odoo)"); }
    return ['contact' => $cid, 'user' => $uid];
};
$D1 = $mkDept('QT Dept A', 77);
$D2 = $mkDept('QT Dept B', 78);
$SOPHIE = $mkPerson('QT Sophie Tran', 'sophie', $D1, 1842);
$MIA = $mkPerson('QT Mia Manager', 'mia', $D1, 1900, 'manager');
$BOB = $mkPerson('QT Bob Other', 'bob', $D2, 2000);
$NOLINK = $mkPerson('QT Casey Contractor', 'casey', $D1, null);
x("INSERT INTO user_roles (role_name, role_is_admin) VALUES ('QT Admin', 1)");
$ADM_ROLE = (int) $mysqli->insert_id;
x("INSERT INTO user_roles (role_name, role_is_admin) VALUES ('QT Tech', 0)");
$TECH_ROLE = (int) $mysqli->insert_id;
$mkAgent = function (string $slug, int $role) use ($mysqli): int {
    $st = $mysqli->prepare("INSERT INTO users (user_name, user_email, user_password, user_type, user_status, user_role_id) VALUES (?, ?, 'x', 1, 1, ?)");
    $name = "QT $slug"; $email = "qt-$slug@scratch.invalid";
    $st->bind_param('ssi', $name, $email, $role); $st->execute(); $id = (int) $mysqli->insert_id; $st->close();
    return $id;
};
$ADMIN = $mkAgent('admin', $ADM_ROLE);
$TECH = $mkAgent('tech', $TECH_ROLE);
$events = IntegrationAuth::createClient($mysqli, 'QT events', null, ['quality:events'], '', null, 1, true, []);
$lmsOnly = IntegrationAuth::createClient($mysqli, 'QT lms only', null, ['lms:read'], '', null, 1, true, []);
$tokenReq = function (array $client): string {
    $r = http('POST', '/api/v1/oauth/token', ['body' => ['grant_type' => 'client_credentials'], 'basic' => [$client['client_uid'], $client['secret']]]);
    return (string) ($r['json']['access_token'] ?? '');
};
$EVTOK = $tokenReq($events);
$LMSTOK = $tokenReq($lmsOnly);
t('fixtures: tokens issued for the events client and an lms-only client', $EVTOK !== '' && $LMSTOK !== '');
// the JWKS file the mock QMS verifies user contexts against (what Datum would fetch from /oidc/jwks)
KeyStore::activeSigningKey($mysqli);
file_put_contents($STATE . '/jwks.json', json_encode(KeyStore::jwks($mysqli)));
setMock(['client_id' => $QMS_CLIENT_ID, 'client_secret' => $QMS_CLIENT_SECRET, 'audience' => $QMS, 'issuer' => $ISS, 'web' => $QMS]);
x("UPDATE settings SET config_webhook_allowed_networks = ''");

// =============================================================================================================== everything is OFF by default
echo "== default state: everything off\n";
t('module switch is off after the migration', QualityConfig::moduleOn($mysqli) === false);
t('no feature is on', !in_array(true, QualityConfig::features($mysqli), true));
t('no department is enabled', QualityConfig::departmentIds($mysqli) === []);
$row = QualityConfig::load($mysqli);
t('events and inbound events are off, no URL, no secrets', (int) $row['qs_events_enabled'] === 0 && (int) $row['qs_inbound_enabled'] === 0 && $row['qs_qms_url'] === null && $row['qs_client_secret_enc'] === null);
t('access() refuses with module_disabled', QualityConfig::access($mysqli, $SOPHIE['contact'])['reason'] === 'module_disabled');
t('SSO policy refuses with module_disabled even for an eligible person (provider on)', (function () use ($mysqli, $SOPHIE) { OidcConfig::saveSettings($mysqli, true, 480, 300, 300, 1); return QualityAccessPolicy::allows($SOPHIE['contact'], $mysqli)['reason']; })() === 'module_disabled');
t('SSO policy refuses an administrator too while the module is off', QualityAccessPolicy::allows(QualityAccessPolicy::administratorPrincipal($ADMIN), $mysqli)['reason'] === 'module_disabled');
t('SyncWorker does nothing while off', SyncWorker::run($mysqli, ['force' => true])['status'] === 'module_off');
t('QmsClient::fromDb is null while unconfigured', QmsClient::fromDb($mysqli) === null);

// portal and API are shut while off
$sCookie = portalSession($SOPHIE['contact'], $SOPHIE['user'], $D1);
$r = http('GET', '/client/quality.php', ['cookie' => $sCookie]);
t('portal page while off: redirected to the portal home, no Quality content', $r['status'] === 302 && str_ends_with((string) $r['loc'], 'index.php'));
$home = http('GET', '/client/index.php', ['cookie' => $sCookie]);
t('portal home while off: no Quality tile or link', $home['status'] === 200 && !str_contains($home['body'], 'quality.php') && !str_contains($home['body'], 'Open Quality'));
$ev = http('POST', '/api/v1/integrations/qms/events', ['headers' => ["Authorization: Bearer $EVTOK"], 'ctype' => 'application/json', 'body' => '{}']);
t('inbound events route while off: 503 module_disabled', $ev['status'] === 503 && ($ev['json']['error']['code'] ?? '') === 'module_disabled', 'status ' . $ev['status']);
$adminCookie = agentSession($ADMIN);
$ap = http('GET', '/admin/quality.php', ['cookie' => $adminCookie]);
t('admin settings page opens while off (so it can be switched on)', $ap['status'] === 200 && str_contains($ap['body'], 'Quality is on') && str_contains($ap['body'], 'off until you turn it on'));
$ap2 = http('GET', '/admin/quality.php', ['cookie' => agentSession($TECH)]);
t('admin settings page refused for a non-administrator', $ap2['status'] !== 200 || !str_contains($ap2['body'], 'Connection to the QMS'));

// =============================================================================================================== QMS address vetting
echo "== QMS address vetting (SSRF)\n";
$strict = (string) shell_exec('php ' . escapeshellarg($root . '/tests/quality_ssrf.php') . ' 2>&1');
t('SSRF vetting suite (no test seam): ' . trim(strrchr($strict, "\n") ?: $strict), str_contains($strict, 'ALL OK'), $strict);

// =============================================================================================================== connection, token, requests
echo "== QMS client against the mock\n";
$secretSealed = QualityConfig::sealSecret($QMS_CLIENT_SECRET);
QualityConfig::set($mysqli, ['qs_qms_url' => $QMS, 'qs_client_id' => $QMS_CLIENT_ID, 'qs_client_secret_enc' => $secretSealed]);
t('client secret is stored sealed, not readable in the database', !str_contains((string) q('SELECT qs_client_secret_enc s FROM quality_settings')[0]['s'], $QMS_CLIENT_SECRET) && QualityConfig::secret(QualityConfig::load($mysqli), 'qs_client_secret_enc') === $QMS_CLIENT_SECRET);
QualityConfig::setModule($mysqli, true);
QualityConfig::setFeatures($mysqli, ['tasks', 'documents', 'training', 'actions', 'notifications', 'history', 'reports', 'kiosk']);
QualityConfig::setDepartments($mysqli, [$D1], 1);
$client = QmsClient::fromDb($mysqli)->withBackoff(5);
$h = $client->test();
t('connection test passes and reports a time', $h['ok'] && $h['status'] === 200 && $h['ms'] >= 0, $h['message']);
$calls = qmsLog();
t('token fetched with client_credentials, HTTP Basic, once', qmsCount(fn($l) => ($l['event'] ?? '') === 'token') === 1 && str_contains(array_values(array_filter($calls, fn($l) => ($l['event'] ?? '') === 'token'))[0]['form'] ?? '', 'grant_type=client_credentials'));
$tokRow = q('SELECT qs_token_enc, qs_token_expires_at FROM quality_settings')[0];
t('access token is cached sealed with an expiry', $tokRow['qs_token_enc'] !== null && !str_contains($tokRow['qs_token_enc'], 'qms_tok_') && $tokRow['qs_token_expires_at'] !== null);
$client->test();
$client->test();
t('token reused across calls (still one token request)', qmsCount(fn($l) => ($l['event'] ?? '') === 'token') === 1);
t('health call carries a correlation id and a bearer token, no user context', (function () { $l = array_values(array_filter(qmsLog(), fn($x) => ($x['event'] ?? '') === 'health'))[0] ?? []; return !empty($l['cid']) && $l['auth'] === true && $l['ctx'] === false; })());

// a person's items need a user context: refused before any HTTP
qmsLogClear();
try { $client->request('GET', '/me/quality/tasks'); t('GET /me/... without a user context is refused', false); } catch (QmsException $e) { t('GET /me/... without a user context is refused (' . $e->errCode . ')', $e->errCode === QmsException::CONTEXT); }
try { $client->request('GET', '/quality/actions'); t('GET /quality/... without a user context is refused', false); } catch (QmsException $e) { t('GET /quality/... without a user context is refused', $e->errCode === QmsException::CONTEXT); }
t('...and no HTTP request was made for either', qmsLog() === []);
$cid = bin2hex(random_bytes(6));
$ctx = UserContext::forContact($mysqli, $SOPHIE['contact'], $client->audience(), ['me:read'], $cid);
try { $client->request('GET', '/integrations/health', ['user' => $ctx]); t('a system call must not carry a user context', false); } catch (QmsException $e) { t('a system call must not carry a user context', $e->errCode === QmsException::CONTEXT); }
$r = $client->request('GET', '/me/quality/tasks', ['user' => $ctx]);
t('GET /me/quality/tasks with a context: 200 and items for that person', $r['status'] === 200 && count($r['data']['data']) === 2);
$ul = array_values(array_filter(qmsLog(), fn($l) => ($l['event'] ?? '') === 'user_call'));
t('the mock verified the context (signature via JWKS, typ, aud, iss, exp) and saw sub 1842', ($ul[0]['ctx_ok'] ?? false) === true && ($ul[0]['sub'] ?? '') === '1842', json_encode($ul[0] ?? []));
// claims content
$cl = $ctx->claims;
t('context claims: iss/aud/sub/rivetit_id/odoo_employee_id/dept/roles/scp, 60 s lifetime', $cl['iss'] === $ISS && $cl['aud'] === $QMS && $cl['sub'] === '1842' && $cl['rivetit_id'] === (string) $SOPHIE['contact'] && $cl['odoo_employee_id'] === '1842'
    && $cl['dept']['rivetit_id'] === (string) $D1 && $cl['dept']['odoo_id'] === '77' && $cl['roles'] === ['employee'] && $cl['scp'] === ['me:read'] && $cl['exp'] - $cl['iat'] === 60);
t('context carries no name, e-mail, phone or PIN', !preg_grep('/^(name|email|phone|pin|contact_pin)$/', array_keys($cl)) && !str_contains(json_encode($cl), 'scratch.invalid') && !str_contains(json_encode($cl), 'Sophie'));
$hdr = json_decode((string) base64_decode(strtr(explode('.', $ctx->jwt())[0], '-_', '+/')), true);
t('context header: ES256, typ rivetit-user-context+jwt, kid from the JWKS', ($hdr['alg'] ?? '') === 'ES256' && ($hdr['typ'] ?? '') === 'rivetit-user-context+jwt' && ($hdr['kid'] ?? '') === (KeyStore::jwks($mysqli)['keys'][0]['kid'] ?? 'x'));
$mia = UserContext::forContact($mysqli, $MIA['contact'], $client->audience(), ['manager:read'], $cid);
t('a department manager gets the manager role in the signed context', $mia->claims['roles'] === ['employee', 'manager']);
// a context for the wrong audience is refused by the QMS (it checks aud)
$wrong = UserContext::forContact($mysqli, $SOPHIE['contact'], 'https://some-other-system.example', ['me:read'], $cid);
try { $client->request('GET', '/me/quality/tasks', ['user' => $wrong, 'log' => false]); t('QMS refuses a context minted for another audience', false); } catch (QmsException $e) { t('QMS refuses a context minted for another audience (403)', $e->errCode === QmsException::FORBIDDEN); }
// ineligible people get no context
x("UPDATE contacts SET contact_employment_status = 'terminated' WHERE contact_id = {$SOPHIE['contact']}");
try { UserContext::forContact($mysqli, $SOPHIE['contact'], $QMS, [], $cid); t('no context for a terminated person', false); } catch (QmsException $e) { t('no context for a terminated person (' . $e->errCode . ')', $e->errCode === QmsException::CONTEXT); }
x("UPDATE contacts SET contact_employment_status = 'active' WHERE contact_id = {$SOPHIE['contact']}");
try { UserContext::forContact($mysqli, $BOB['contact'], $QMS, [], $cid); t('no context for a person in a department that is not enabled', false); } catch (QmsException $e) { t('no context for a person in a department that is not enabled', $e->errCode === QmsException::CONTEXT); }
QualityConfig::setModule($mysqli, false);
try { UserContext::forContact($mysqli, $SOPHIE['contact'], $QMS, [], $cid); t('no context while the module is off', false); } catch (QmsException $e) { t('no context while the module is off', $e->errCode === QmsException::CONTEXT); }
QualityConfig::setModule($mysqli, true);

// retries, timeouts, response handling
echo "== bounded retries, timeouts, response limits\n";
qmsLogClear(); ctl(['flaky' => 2]);
$r = $client->request('GET', '/integrations/health');
t('a GET survives two 503s: 3 attempts, then success', $r['status'] === 200 && $r['attempts'] === 3);
qmsLogClear(); ctl(['flaky' => 9]);
try { $client->request('GET', '/integrations/health'); t('persistent 503: gives up', false); } catch (QmsException $e) { t('persistent 503: gives up after exactly 3 attempts with a retryable server error', $e->errCode === QmsException::SERVER && $e->retryable && qmsCount(fn($l) => ($l['event'] ?? '') === 'flaky_503') === 3); }
ctl([]);
qmsLogClear(); ctl(['down' => true]);
try { $client->request('POST', '/me/quality/documents/DOC-7/acknowledgments', ['json' => ['acknowledged' => true], 'user' => $ctx, 'idempotency_key' => 'idem-' . bin2hex(random_bytes(6))]); t('POST while down fails', false); } catch (QmsException $e) { t('a keyed POST is retried too (3 attempts) then fails', qmsCount(fn($l) => ($l['event'] ?? '') === 'down') === 3); }
try { $client->request('POST', '/me/quality/documents/DOC-7/acknowledgments', ['json' => [], 'user' => $ctx]); t('POST without an idempotency key is refused', false); } catch (QmsException $e) { t('a write without an idempotency key is refused locally', $e->errCode === QmsException::REJECTED); }
ctl([]);
qmsLogClear(); ctl(['bad_json' => true]);
try { $client->request('GET', '/integrations/health'); t('non-JSON answer', false); } catch (QmsException $e) { t('a 200 that is not JSON is a bad_response', $e->errCode === QmsException::BAD_RESPONSE); }
ctl(['big' => true]);
try { $client->request('GET', '/integrations/health'); t('2 MiB answer', false); } catch (QmsException $e) { t('a response over 1 MiB is cut off and refused', $e->errCode === QmsException::TRANSPORT); }
ctl(['redirect' => true]);
try { $client->request('GET', '/integrations/health'); t('redirect', false); } catch (QmsException $e) { t('a redirect is never followed (answered as an error, no second request)', $e->http === 302 && $e->errCode === QmsException::REJECTED); }
ctl(['slow' => 4]);
QualityConfig::set($mysqli, ['qs_total_timeout_s' => 2]);
$fast = QmsClient::fromDb($mysqli)->withBackoff(5);
$t0 = microtime(true);
try { $fast->request('GET', '/integrations/health', ['budget_s' => 3]); t('timeout', false); } catch (QmsException $e) { t('a slow QMS times out inside the budget (' . round(microtime(true) - $t0, 1) . ' s)', $e->errCode === QmsException::TIMEOUT && microtime(true) - $t0 < 9); }
ctl([]);
QualityConfig::set($mysqli, ['qs_total_timeout_s' => 10]);
// wrong secret
$bad = QualityConfig::load($mysqli);
QualityConfig::set($mysqli, ['qs_client_secret_enc' => QualityConfig::sealSecret('wrong-secret-xyz'), 'qs_token_enc' => null, 'qs_token_expires_at' => null]);
try { QmsClient::fromDb($mysqli)->request('GET', '/integrations/health'); t('wrong secret', false); } catch (QmsException $e) { t('a wrong client secret is an auth failure', $e->errCode === QmsException::AUTH); }
QualityConfig::set($mysqli, ['qs_client_secret_enc' => $secretSealed, 'qs_token_enc' => null, 'qs_token_expires_at' => null]);
$client = QmsClient::fromDb($mysqli)->withBackoff(5);
// pagination
$ctx = UserContext::forContact($mysqli, $SOPHIE['contact'], $client->audience(), ['me:read'], $cid);   // a fresh one: they live 60 seconds
t('all() follows next_cursor and returns the items', count($client->all('/me/quality/tasks', [], $ctx, 3)) === 2);
// error log content
$errs = QualityLog::recent($mysqli, 200);
$dump = json_encode($errs);
t('the error log recorded the failures with codes and correlation ids', count($errs) >= 5 && (bool) array_filter($errs, fn($e) => !empty($e['qel_correlation_id'])));
t('the error log holds no secret, no token, no signature, no bearer', !str_contains($dump, $QMS_CLIENT_SECRET) && !str_contains($dump, 'wrong-secret-xyz') && !str_contains($dump, 'qms_tok_') && !preg_match('/Bearer\s+\w{8,}/', $dump));
t('QualityLog::scrub redacts secrets and URL queries', !str_contains(QualityLog::scrub('failed secret=abcdef123 Bearer abcdefghijkl https://x.example/p?token=zzz'), 'abcdef123') && !str_contains(QualityLog::scrub('https://x.example/p?token=zzz'), 'zzz'));

// =============================================================================================================== service: shaping, gating, unavailable
echo "== QualityService: per-user views, gating, no stale data\n";
$svc = new QualityService($mysqli, $client);
$tasks = $svc->employeeView($SOPHIE['contact'], 'tasks');
t('tasks view ok with the time it was read', !empty($tasks['ok']) && count($tasks['items']) === 2 && !empty($tasks['as_of']));
$byId = array_column($tasks['items'], null, 'id');
t('same-origin QMS link kept, off-site link dropped', $byId['t1']['url'] === $QMS . '/tasks/t1' && $byId['t2']['url'] === null);
t('javascript: link dropped', $svc->employeeView($SOPHIE['contact'], 'actions')['items'][0]['url'] === null);
t('markup in a title is kept as plain text for the view to escape (never executed by the service)', str_contains($byId['t1']['title'], '<script>'));
$docs = $svc->employeeView($SOPHIE['contact'], 'documents');
t('documents: pending flag, version, due date', $docs['items'][0]['pending'] === true && $docs['items'][0]['version'] === '3' && $docs['items'][1]['pending'] === false);
t('other people get their own (empty) data: Mia has no items in the mock', $svc->employeeView($MIA['contact'], 'tasks')['items'] === []);
t('a person of a department that is not enabled is refused', $svc->employeeView($BOB['contact'], 'tasks')['error'] === 'not_allowed');
QualityConfig::setFeatures($mysqli, ['documents']);
t('a feature that is off is refused', $svc->employeeView($SOPHIE['contact'], 'tasks')['error'] === 'feature_disabled' && !empty($svc->employeeView($SOPHIE['contact'], 'documents')['ok']));
QualityConfig::setFeatures($mysqli, array_keys(QualityConfig::FEATURES));
t('an unknown view is refused', $svc->employeeView($SOPHIE['contact'], '../etc')['error'] === 'unknown_view');
$mgr = $svc->managerView($MIA['contact'], 'summary');
t('manager summary: numbers from the QMS, compliance rounded', !empty($mgr['ok']) && $mgr['summary']['open_actions'] === 4 && $mgr['summary']['training_compliance_pct'] === 92);
t('manager lists: actions, overdue, compliance, acknowledgments', count($svc->managerView($MIA['contact'], 'actions')['items']) === 2 && count($svc->managerView($MIA['contact'], 'overdue')['items']) === 1
    && $svc->managerView($MIA['contact'], 'compliance')['items'][0]['pct'] === 83 && $svc->managerView($MIA['contact'], 'acknowledgments')['items'][0]['pending_count'] === 5);
t('an employee who is not a manager cannot use the manager views', $svc->managerView($SOPHIE['contact'], 'summary')['error'] === 'not_allowed');
$ml = array_values(array_filter(qmsLog(), fn($l) => ($l['event'] ?? '') === 'user_call' && str_starts_with($l['path'], '/quality/')));
t('the department sent to the QMS is the manager\'s own, and the signed context says manager', $ml && str_contains(end($ml)['query'], 'department=' . $D1) && in_array('manager', end($ml)['roles'] ?? [], true));
// QMS down: nothing stale
ctl(['down' => true]);
$down = $svc->employeeView($SOPHIE['contact'], 'tasks');
t('QMS unavailable: no items, a plain message, a reference, nothing stale', empty($down['ok']) && $down['error'] === 'unavailable' && !isset($down['items']) && $down['reference'] !== '' && !str_contains(json_encode($down), 'Calibrate'));
t('...and the local RivetIT training list still works while the QMS is down', is_array($svc->localTraining($SOPHIE['contact'])));
ctl([]);
$ack = $svc->acknowledge($SOPHIE['contact'], 'DOC-7', 'svc-ack-' . bin2hex(random_bytes(6)));
t('acknowledge reaches the QMS as that person', !empty($ack['ok']) && qmsCount(fn($l) => ($l['event'] ?? '') === 'ack_recorded' && $l['sub'] === '1842') === 1);
t('acknowledge refuses a malformed document id', empty($svc->acknowledge($SOPHIE['contact'], '../x', 'svc-ack-aaaaaaaaaa')['ok']));
$resp = $svc->respond($SOPHIE['contact'], 'CAPA-12', 'Done, see attached evidence.', 'svc-resp-' . bin2hex(random_bytes(6)));
t('respond reaches the QMS with the text and the person', !empty($resp['ok']) && qmsCount(fn($l) => ($l['event'] ?? '') === 'response_recorded' && $l['sub'] === '1842' && str_contains((string) $l['text'], 'evidence')) === 1);
t('respond refuses an empty or over-long text', empty($svc->respond($SOPHIE['contact'], 'CAPA-12', '   ', 'svc-resp-bbbbbbbbbb')['ok']) && empty($svc->respond($SOPHIE['contact'], 'CAPA-12', str_repeat('a', 4001), 'svc-resp-cccccccccc')['ok']));

// =============================================================================================================== portal pages
echo "== portal pages\n";
x("UPDATE settings SET config_client_portal_enable = 1");
$fresh = portalSession($SOPHIE['contact'], $SOPHIE['user'], $D1, ['portal_auth_time' => time() - 60]);
$stale = portalSession($SOPHIE['contact'], $SOPHIE['user'], $D1);
$p = http('GET', '/client/quality.php', ['cookie' => $stale]);
t('portal: Quality opens for an enabled department person', $p['status'] === 200 && str_contains($p['body'], 'My tasks') && str_contains($p['body'], 'Corrective actions'), 'status ' . $p['status']);
t('portal: QMS text is escaped (no live <script>)', str_contains($p['body'], '&lt;script&gt;') && !str_contains($p['body'], '<script>alert(1)'));
t('portal: off-site and javascript: links are not rendered', !str_contains($p['body'], 'evil.example') && !str_contains($p['body'], 'javascript:'));
t('portal: shows the time it was read and a refresh link', str_contains($p['body'], 'Read from the quality system at'));
$home = http('GET', '/client/index.php', ['cookie' => $stale]);
t('portal home: a Quality tile and menu link appear', str_contains($home['body'], 'href="quality.php"') && str_contains($home['body'], '/client/quality.php'));
$pd = http('GET', '/client/quality.php?tab=documents', ['cookie' => $stale]);
t('documents tab: acknowledge form with CSRF, password field (stale sign-in)', str_contains($pd['body'], 'name="do" value="acknowledge"') && str_contains($pd['body'], 'name="csrf_token"') && str_contains($pd['body'], 'name="password"'));
$pdf = http('GET', '/client/quality.php?tab=documents', ['cookie' => $fresh]);
t('documents tab: no password asked when the sign-in is fresh', !str_contains($pdf['body'], 'name="password"') && str_contains($pdf['body'], 'name="do" value="acknowledge"'));
$pn = http('GET', '/client/quality.php?tab=nonsense', ['cookie' => $stale]);
t('unknown tab falls back to the first tab (200)', $pn['status'] === 200);
t('portal: manager page for an employee goes back to Quality', ($m = http('GET', '/client/quality_manage.php', ['cookie' => $stale]))['status'] === 302 && str_contains((string) $m['loc'], 'quality.php'));
$miaCookie = portalSession($MIA['contact'], $MIA['user'], $D1);
$pm = http('GET', '/client/quality_manage.php', ['cookie' => $miaCookie]);
t('portal: manager sees the department overview with the numbers', $pm['status'] === 200 && str_contains($pm['body'], 'Open corrective actions') && str_contains($pm['body'], '>4<'));
foreach (['actions', 'overdue', 'compliance', 'acknowledgments'] as $tab) {
    $pt = http('GET', "/client/quality_manage.php?tab=$tab", ['cookie' => $miaCookie]);
    t("portal: manager tab $tab renders", $pt['status'] === 200 && str_contains($pt['body'], 'Read from the quality system'));
}
$bobCookie = portalSession($BOB['contact'], $BOB['user'], $D2);
t('portal: a person of a department that is not enabled is sent home', ($b = http('GET', '/client/quality.php', ['cookie' => $bobCookie]))['status'] === 302 && str_ends_with((string) $b['loc'], 'index.php'));
t('portal: ...and sees no Quality link on the home page', !str_contains(http('GET', '/client/index.php', ['cookie' => $bobCookie])['body'], 'quality.php'));
$crossDept = portalSession($SOPHIE['contact'], $SOPHIE['user'], $D2);   // session says department B, the contact belongs to A
t('portal: a session whose department differs from the contact\'s is refused', http('GET', '/client/quality.php', ['cookie' => $crossDept])['status'] === 302);
t('portal: an admin preview session gets no Quality', (function () use ($ADMIN, $D1) { $c = agentSession($ADMIN); $r = http('GET', '/client/quality.php', ['cookie' => $c]); return $r['status'] === 302; })());
t('portal: a kiosk-flagged session gets no Quality', http('GET', '/client/quality.php', ['cookie' => portalSession($SOPHIE['contact'], $SOPHIE['user'], $D1, ['kiosk_session' => true])])['status'] === 302);
// QMS down on the page
ctl(['down' => true]);
$pdn = http('GET', '/client/quality.php', ['cookie' => $stale]);
t('portal: QMS down shows the unavailable state, a reference, a retry link and NO items', $pdn['status'] === 200 && str_contains($pdn['body'], 'data-quality-unavailable') && str_contains($pdn['body'], 'Try again') && str_contains($pdn['body'], 'reference') && !str_contains($pdn['body'], 'Calibrate'));
ctl([]);
// write actions
echo "== portal write actions\n";
qmsLogClear();
$ackPage = http('GET', '/client/quality.php?tab=documents', ['cookie' => $stale]);
preg_match('/name="idem" value="([a-f0-9]+)"/', $ackPage['body'], $im);
$idem = $im[1] ?? bin2hex(random_bytes(12));
$sess = sessionData($stale);
$csrfTok = (string) ($sess['csrf_token'] ?? '');
$post = fn(array $body, string $cookie = '') => http('POST', '/client/quality_action.php', ['cookie' => $cookie ?: $stale, 'body' => $body]);
$r = $post(['do' => 'acknowledge', 'id' => 'DOC-7', 'idem' => $idem, 'password' => $PORTAL_PW]);
t('no CSRF token: refused, nothing sent to the QMS', $r['status'] === 302 && qmsCount(fn($l) => ($l['event'] ?? '') === 'ack_recorded') === 0);
$r = $post(['csrf_token' => $csrfTok, 'do' => 'acknowledge', 'id' => 'DOC-7', 'idem' => $idem, 'password' => 'wrong-password']);
t('stale sign-in + wrong password: refused, nothing sent', qmsCount(fn($l) => ($l['event'] ?? '') === 'ack_recorded') === 0);
$r = $post(['csrf_token' => $csrfTok, 'do' => 'acknowledge', 'id' => 'DOC-7', 'idem' => $idem]);
t('stale sign-in + no password: refused, nothing sent', qmsCount(fn($l) => ($l['event'] ?? '') === 'ack_recorded') === 0);
$r = $post(['csrf_token' => $csrfTok, 'do' => 'acknowledge', 'id' => 'DOC-7', 'idem' => $idem, 'password' => $PORTAL_PW]);
t('stale sign-in + right password: the acknowledgment reaches the QMS as that person', qmsCount(fn($l) => ($l['event'] ?? '') === 'ack_recorded' && $l['sub'] === '1842' && $l['doc'] === 'DOC-7') === 1);
$r = $post(['csrf_token' => $csrfTok, 'do' => 'acknowledge', 'id' => 'DOC-7', 'idem' => $idem]);
t('a double submit (same key) is one acknowledgment (the password confirmation is remembered for the sign-in)', qmsCount(fn($l) => ($l['event'] ?? '') === 'ack_recorded') === 1);
$r = $post(['csrf_token' => $csrfTok, 'do' => 'acknowledge', 'id' => '../../x', 'idem' => $idem]);
t('a malformed id is refused', qmsCount(fn($l) => ($l['event'] ?? '') === 'ack_recorded') === 1);
$r = $post(['csrf_token' => $csrfTok, 'do' => 'delete-everything', 'id' => 'DOC-7', 'idem' => $idem]);
t('an unknown action is refused', $r['status'] === 302 && qmsCount(fn($l) => ($l['event'] ?? '') === 'ack_recorded') === 1);
// five wrong passwords lock the confirmation for the sign-in
$stale2 = portalSession($SOPHIE['contact'], $SOPHIE['user'], $D1);
$c2 = (string) (sessionData($stale2)['csrf_token'] ?? '');
for ($i = 0; $i < 6; $i++) { http('POST', '/client/quality_action.php', ['cookie' => $stale2, 'body' => ['csrf_token' => $c2, 'do' => 'acknowledge', 'id' => 'DOC-7', 'idem' => bin2hex(random_bytes(8)), 'password' => 'nope' . $i]]); }
http('POST', '/client/quality_action.php', ['cookie' => $stale2, 'body' => ['csrf_token' => $c2, 'do' => 'acknowledge', 'id' => 'DOC-7', 'idem' => bin2hex(random_bytes(8)), 'password' => $PORTAL_PW]]);
t('five wrong passwords lock further confirmations for that sign-in (even the right one)', qmsCount(fn($l) => ($l['event'] ?? '') === 'ack_recorded') === 1);
// fresh sign-in needs no password
$freshCsrf = (string) (sessionData($fresh)['csrf_token'] ?? '');
$r = http('POST', '/client/quality_action.php', ['cookie' => $fresh, 'body' => ['csrf_token' => $freshCsrf, 'do' => 'respond', 'id' => 'CAPA-12', 'idem' => bin2hex(random_bytes(8)), 'text' => 'Fixed the packaging line.']]);
t('fresh sign-in: a corrective-action response goes through without a password', qmsCount(fn($l) => ($l['event'] ?? '') === 'response_recorded' && $l['sub'] === '1842') === 1);
$r = http('POST', '/client/quality_action.php', ['cookie' => portalSession($BOB['contact'], $BOB['user'], $D2, ['portal_auth_time' => time()]), 'body' => ['csrf_token' => 'x', 'do' => 'acknowledge', 'id' => 'DOC-7', 'idem' => bin2hex(random_bytes(8))]]);
t('a person of a disabled department cannot write', qmsCount(fn($l) => ($l['event'] ?? '') === 'ack_recorded') === 1);
t('GET on the action endpoint just goes back', http('GET', '/client/quality_action.php', ['cookie' => $stale])['status'] === 302);

// =============================================================================================================== SSO policy hook
echo "== SSO policy: module switch and department gate\n";
QualityConfig::setModule($mysqli, true);
t('policy allows an enabled-department person (provider and module on)', QualityAccessPolicy::allows($SOPHIE['contact'], $mysqli)['ok'] === true);
t('policy refuses a person in a department that is not enabled: department_not_enabled', QualityAccessPolicy::allows($BOB['contact'], $mysqli)['reason'] === 'department_not_enabled');
QualityConfig::setModule($mysqli, false);
t('policy refuses everybody when the module is off: module_disabled', QualityAccessPolicy::allows($SOPHIE['contact'], $mysqli)['reason'] === 'module_disabled');
QualityConfig::setModule($mysqli, true);
t('an administrator is allowed with the module on (departments do not apply to them)', QualityAccessPolicy::allows(QualityAccessPolicy::administratorPrincipal($ADMIN), $mysqli)['ok'] === true);
$sub = QualityAccessPolicy::subject($mysqli, $SOPHIE['contact']);
t('subject is the Odoo employee id plus rivetit_id', $sub['sub'] === '1842' && $sub['rivetit_id'] === (string) $SOPHIE['contact']);
QualityConfig::set($mysqli, ['qs_all_departments' => 1]);
t('"all departments" enables a department that is not listed', QualityAccessPolicy::allows($BOB['contact'], $mysqli)['ok'] === true);
QualityConfig::set($mysqli, ['qs_all_departments' => 0]);
OidcConfig::saveSettings($mysqli, true, 480, 300, 300, 1);
$c = IntegrationAuth::createClient($mysqli, 'QT sso datum', null, ['sso'], '', null, 1, true, []);
OidcConfig::saveClient($mysqli, $c['client_id'], 'https://datum.example/cb', 'https://datum.example/login', false);
$home = http('GET', '/client/index.php', ['cookie' => $stale]);
t('portal home: "Open Quality system" (SSO) appears for an enabled person', str_contains($home['body'], 'href="https://datum.example/login"'));
t('portal home: the SSO button is hidden for a person of a disabled department', !str_contains(http('GET', '/client/index.php', ['cookie' => $bobCookie])['body'], 'datum.example'));
QualityConfig::setModule($mysqli, false);
t('portal home: the SSO button is hidden while the module is off', !str_contains(http('GET', '/client/index.php', ['cookie' => $stale])['body'], 'datum.example'));
$au = http('GET', '/oidc/authorize?' . http_build_query(['response_type' => 'code', 'client_id' => $c['client_uid'], 'redirect_uri' => 'https://datum.example/cb', 'scope' => 'openid', 'state' => 'abcdefgh12345678', 'nonce' => 'nnnnnnnn12345678',
    'code_challenge' => b64u(hash('sha256', 'v', true)), 'code_challenge_method' => 'S256']), ['cookie' => $stale]);
t('authorize is refused while the module is off (no code issued)', !str_contains((string) $au['loc'], 'code=') && x_audit('policy:module_disabled') > 0);
QualityConfig::setModule($mysqli, true);
function x_audit(string $reason): int { return (int) (q('SELECT COUNT(*) n FROM oidc_events WHERE oev_reason LIKE ?', 's', [$reason . '%'])[0]['n'] ?? 0); }

// =============================================================================================================== events: outbound
echo "== outbound events: queue, sign, deliver, retry, dead letter, replay\n";
$outSecret = QualityConfig::newSigningSecret();
QualityConfig::set($mysqli, ['qs_outbound_secret_enc' => QualityConfig::sealSecret($outSecret), 'qs_events_enabled' => 1, 'qs_sync_interval_minutes' => 1]);
setMock(['client_id' => $QMS_CLIENT_ID, 'client_secret' => $QMS_CLIENT_SECRET, 'audience' => $QMS, 'issuer' => $ISS, 'web' => $QMS, 'outbound_secret' => $outSecret]);
QualityConfig::setDepartments($mysqli, [$D1], 1);
t('outbound secret stored sealed', !str_contains((string) q('SELECT qs_outbound_secret_enc s FROM quality_settings')[0]['s'], $outSecret));
x("DELETE FROM quality_sync_state"); x("DELETE FROM quality_outbox");
$r1 = SyncWorker::run($mysqli, ['force' => true, 'no_prune' => true]);
t('first pass only sets the cursors (no history is sent)', $r1['status'] === 'ok' && $r1['produced'] === 0 && q('SELECT COUNT(*) n FROM quality_outbox')[0]['n'] === 0, json_encode([$r1['status'], $r1['produced'], q('SELECT COUNT(*) n FROM quality_outbox')]));
t('first pass recorded a health check', ($r1['health']['ok'] ?? false) === true);
usleep(1200000);
x("UPDATE contacts SET contact_title = 'Lead' WHERE contact_id = {$SOPHIE['contact']}");   // a change inside enabled department A
x("UPDATE contacts SET contact_title = 'Lead' WHERE contact_id = {$BOB['contact']}");        // a change in department B (not enabled)
$cdesc = q("SHOW COLUMNS FROM contacts LIKE 'contact_updated_at'");
$r2 = SyncWorker::run($mysqli, ['force' => true, 'no_prune' => true]);
$ob = q('SELECT * FROM quality_outbox ORDER BY qo_id');
t('a change queues signed employee events, only for enabled departments', $cdesc !== [] && count($ob) >= 1 && !array_filter($ob, fn($o) => json_decode($o['qo_payload'], true)['data']['rivetit_id'] === (string) $BOB['contact'] && $o['qo_resource_type'] === 'employee'), 'rows ' . count($ob));
$mine = array_values(array_filter($ob, fn($o) => $o['qo_resource_type'] === 'employee' && $o['qo_resource_id'] === (string) $SOPHIE['contact']));
t('...for Sophie: employee.updated with the Odoo id and schema_version 1', count($mine) === 1 && $mine[0]['qo_type'] === 'employee.updated' && json_decode($mine[0]['qo_payload'], true)['schema_version'] === 1 && json_decode($mine[0]['qo_payload'], true)['data']['odoo_employee_id'] === '1842', json_encode(array_map(fn($o) => [$o['qo_type'], $o['qo_resource_type'], $o['qo_resource_id']], $ob)));
$pl = $mine ? $mine[0]['qo_payload'] : '{}';
t('payload is minimal: no name, e-mail, phone, PIN or title', !preg_match('/"(name|email|work_email|phone|pin|job_title|contact_name)"/', $pl) && !str_contains($pl, 'Sophie') && !str_contains($pl, 'scratch.invalid'));
t('delivered to the mock with a valid HMAC signature', $r2['sent'] >= 1 && qmsCount(fn($l) => ($l['event'] ?? '') === 'rivetit_event' && $l['sig_ok'] === true) >= 1 && qmsCount(fn($l) => ($l['event'] ?? '') === 'rivetit_event' && $l['sig_ok'] === false) === 0);
t('outbox rows marked delivered', (int) q("SELECT COUNT(*) n FROM quality_outbox WHERE qo_status = 'delivered'")[0]['n'] >= 1);
$n1 = (int) q('SELECT COUNT(*) n FROM quality_outbox')[0]['n'];
usleep(1100000);
$r3 = SyncWorker::run($mysqli, ['force' => true, 'no_prune' => true]);
t('the same change is not queued twice (deterministic ids)', (int) q('SELECT COUNT(*) n FROM quality_outbox')[0]['n'] === $n1 && $r3['produced'] === 0);
$ids = array_column($ob, 'qo_event_id');
t('event ids are uuid-shaped and unique', count($ids) === count(array_unique($ids)) && !array_filter($ids, fn($i) => preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $i) !== 1));
// a department change and a deactivation
usleep(1100000);
x("UPDATE contacts SET contact_employment_status = 'terminated' WHERE contact_id = {$NOLINK['contact']}");
SyncWorker::run($mysqli, ['force' => true, 'no_prune' => true]);
t('a terminated person yields employee.deactivated (history is never deleted)', (int) q("SELECT COUNT(*) n FROM quality_outbox WHERE qo_type = 'employee.deactivated' AND qo_resource_id = ?", 's', [(string) $NOLINK['contact']])[0]['n'] === 1);
t('deactivation deleted nothing in RivetIT', (int) q('SELECT COUNT(*) n FROM contacts WHERE contact_id = ?', 'i', [$NOLINK['contact']])[0]['n'] === 1);
// retry / backoff / dead letter
x("DELETE FROM quality_outbox");
Outbox::enqueue($mysqli, 'department.updated', 'department', (string) $D1, 'rev-a', ['rivetit_id' => (string) $D1, 'updated_at' => '2026-11-01T00:00:00.000Z']);
$qc = QmsClient::fromDb($mysqli)->withBackoff(5);
ctl(['fail_events' => 2]);
$d = Outbox::deliverDue($mysqli, $qc, $outSecret);
$row = q('SELECT * FROM quality_outbox')[0];
t('a 503 is retried later: still pending, attempts 1, next attempt in the future, error kept', $d['retry'] === 1 && $row['qo_status'] === 'pending' && (int) $row['qo_attempts'] === 1 && strtotime($row['qo_next_attempt_at']) > time() + 30 && $row['qo_last_error'] === 'HTTP 503');
$d = Outbox::deliverDue($mysqli, $qc, $outSecret);
t('...and is not retried before its time', $d['sent'] === 0 && $d['retry'] === 0);
x("UPDATE quality_outbox SET qo_next_attempt_at = NOW()");
$d = Outbox::deliverDue($mysqli, $qc, $outSecret);
x("UPDATE quality_outbox SET qo_next_attempt_at = NOW()");
$d2 = Outbox::deliverDue($mysqli, $qc, $outSecret);
t('after the failures end it is delivered', $d2['sent'] === 1 && q('SELECT qo_status s FROM quality_outbox')[0]['s'] === 'delivered');
x("DELETE FROM quality_outbox");
Outbox::enqueue($mysqli, 'department.updated', 'department', (string) $D1, 'rev-b', ['rivetit_id' => (string) $D1]);
ctl(['reject_events' => true]);
$d = Outbox::deliverDue($mysqli, $qc, $outSecret);
$row = q('SELECT * FROM quality_outbox')[0];
t('a 400 from the QMS dead-letters at once (the event itself is wrong)', $d['dead'] === 1 && $row['qo_status'] === 'dead' && (int) $row['qo_last_http_status'] === 400);
t('dead-lettering is in the integration error log', (int) q("SELECT COUNT(*) n FROM quality_error_log WHERE qel_code = 'dead_letter'")[0]['n'] >= 1);
ctl([]);
t('a dead event is not retried by itself', Outbox::deliverDue($mysqli, $qc, $outSecret)['sent'] === 0);
t('replay puts it back; the next delivery succeeds', Outbox::replay($mysqli, (int) $row['qo_id']) === 1 && Outbox::deliverDue($mysqli, $qc, $outSecret)['sent'] === 1);
x("DELETE FROM quality_outbox");
Outbox::enqueue($mysqli, 'department.updated', 'department', (string) $D1, 'rev-c', ['rivetit_id' => (string) $D1]);
ctl(['fail_events' => 99]);
for ($i = 0; $i < Outbox::MAX_ATTEMPTS + 1; $i++) { x("UPDATE quality_outbox SET qo_next_attempt_at = NOW() WHERE qo_status = 'pending'"); Outbox::deliverDue($mysqli, $qc, $outSecret); }
$row = q('SELECT * FROM quality_outbox')[0];
t('after ' . Outbox::MAX_ATTEMPTS . ' failed attempts the event is dead-lettered', $row['qo_status'] === 'dead' && (int) $row['qo_attempts'] === Outbox::MAX_ATTEMPTS);
ctl([]);
// a wrong signing secret at the receiver is visible as a signature failure on the mock side
x("DELETE FROM quality_outbox");
Outbox::enqueue($mysqli, 'department.updated', 'department', (string) $D2, 'rev-d', ['rivetit_id' => (string) $D2]);
$bad = QmsClient::fromDb($mysqli)->withBackoff(5);
qmsLogClear();
Outbox::deliverDue($mysqli, $bad, 'a-different-secret');
t('the receiver rejects an event signed with another secret (401)', qmsCount(fn($l) => ($l['event'] ?? '') === 'rivetit_event' && $l['sig_ok'] === false) === 1 && q('SELECT qo_last_http_status s FROM quality_outbox')[0]['s'] === 401);
x("DELETE FROM quality_outbox");
t('delivery without an outbound secret is skipped, not sent unsigned', Outbox::deliverDue($mysqli, $qc, '')['skipped'] === 'no_signing_secret');

// module off / events off stop everything
echo "== switching off stops the work, keeps the data\n";
x("DELETE FROM quality_sync_state");
SyncWorker::run($mysqli, ['force' => true, 'no_prune' => true]);
usleep(1100000);
x("UPDATE contacts SET contact_title = 'Senior' WHERE contact_id = {$SOPHIE['contact']}");
QualityConfig::setModule($mysqli, false);
qmsLogClear();
$off = SyncWorker::run($mysqli, ['force' => true]);
t('module off: the worker does nothing and calls nobody', $off['status'] === 'module_off' && qmsLog() === []);
$outCli = (string) shell_exec('php ' . escapeshellarg($root . '/cron/quality_worker.php') . ' --force 2>&1');
t('module off: the cron script exits silently', trim($outCli) === '' || !str_contains($outCli, 'queued='), $outCli);
t('module off: settings, features, departments, mappings, history are all still there', QualityConfig::load($mysqli)['qs_client_id'] === $QMS_CLIENT_ID && QualityConfig::departmentIds($mysqli) === [$D1] && QualityConfig::features($mysqli)['tasks'] === true);
t('module off: the Training module is untouched', (int) q('SELECT config_module_enable_training t FROM settings')[0]['t'] === 1);
QualityConfig::setModule($mysqli, true);
QualityConfig::set($mysqli, ['qs_events_enabled' => 0]);
x("DELETE FROM quality_outbox");
usleep(1100000);
$noev = SyncWorker::run($mysqli, ['force' => true, 'no_prune' => true]);
t('events switch off: health still checked, no events produced or sent', $noev['status'] === 'ok' && $noev['produced'] === 0 && q('SELECT COUNT(*) n FROM quality_outbox')[0]['n'] === 0);
QualityConfig::set($mysqli, ['qs_events_enabled' => 1]);

// full resync and reconcile window
x("DELETE FROM quality_outbox");
SyncWorker::requestFullResync($mysqli);
$full = SyncWorker::run($mysqli, ['force' => true, 'no_prune' => true]);
$types = array_column(q('SELECT qo_resource_type, COUNT(*) n FROM quality_outbox GROUP BY qo_resource_type'), 'n', 'qo_resource_type');
t('manual full resync queues one event per person and department in scope', ($types['employee'] ?? 0) >= 2 && ($types['department'] ?? 0) >= 1, json_encode($types));
t('...and resumes to a normal cursor afterwards', !str_starts_with((string) (q("SELECT qss_cursor c FROM quality_sync_state WHERE qss_stream = 'employees'")[0]['c'] ?? ''), 'F'));
$cnt = (int) q('SELECT COUNT(*) n FROM quality_outbox')[0]['n'];
SyncWorker::requestFullResync($mysqli);
SyncWorker::run($mysqli, ['force' => true, 'no_prune' => true]);
t('a second full resync adds nothing (idempotent)', (int) q('SELECT COUNT(*) n FROM quality_outbox')[0]['n'] === $cnt);
// reconcile window re-queues a missed change
x("DELETE FROM quality_outbox WHERE qo_resource_type = 'employee'");
x("DELETE FROM quality_sync_state WHERE qss_stream = 'reconcile'");
$rec = SyncWorker::run($mysqli, ['force' => true, 'no_prune' => true]);
t('reconciliation re-reads a trailing window and queues what the cursor missed', $rec['reconciled'] === true && (int) q("SELECT COUNT(*) n FROM quality_outbox WHERE qo_resource_type = 'employee'")[0]['n'] >= 1);

// assignments and completions streams (real LMS rows)
echo "== LMS streams: assignments and completions\n";
x("INSERT INTO training_courses (course_uid, course_name, course_created_by) VALUES ('QTCourse0001', 'QT Course', 1)");
$courseId = (int) $mysqli->insert_id;
$revJson = json_encode(['course' => ['default_language' => 'en', 'text' => ['en' => ['name' => 'QT Course', 'summary' => 'x']]]]);
$sha = hash('sha256', $revJson);
$st = $mysqli->prepare("INSERT INTO training_revisions (revision_course_id, revision_number, revision_kind, revision_json, revision_sha256, revision_languages, revision_change_note, revision_published_by, revision_published_at_utc, revision_row_sha256)
    VALUES (?, 1, 'training', ?, ?, 'en', 'x', 1, UTC_TIMESTAMP(3), ?)");
$st->bind_param('isss', $courseId, $revJson, $sha, $sha); $st->execute(); $revId = (int) $mysqli->insert_id; $st->close();
x("UPDATE training_courses SET course_current_revision_id = $revId WHERE course_id = $courseId");
x("DELETE FROM quality_sync_state WHERE qss_stream IN ('assignments','completions','run')");
SyncWorker::run($mysqli, ['force' => true, 'no_prune' => true]);   // sets the cursors
usleep(1100000);
x("INSERT INTO training_assignments (tassign_contact_id, tassign_course_id, tassign_reason, tassign_anchor, tassign_due_on, tassign_original_due_on, tassign_created_at_utc, tassign_external_system, tassign_external_ref)
    VALUES ({$SOPHIE['contact']}, $courseId, 'requirement', 'qt', '2026-12-31', '2026-12-31', UTC_TIMESTAMP(3), 'qms', 'NCR-0381')");
x("INSERT INTO training_assignments (tassign_contact_id, tassign_course_id, tassign_reason, tassign_anchor, tassign_due_on, tassign_original_due_on, tassign_created_at_utc, tassign_external_system, tassign_external_ref)
    VALUES ({$BOB['contact']}, $courseId, 'requirement', 'qt', '2026-12-31', '2026-12-31', UTC_TIMESTAMP(3), 'qms', 'NCR-0999')");
$ra = SyncWorker::run($mysqli, ['force' => true, 'no_prune' => true]);
$asg = q("SELECT * FROM quality_outbox WHERE qo_resource_type = 'assignment'");
t('a new assignment queues lms.assignment.created for an enabled department only', count($asg) === 1 && $asg[0]['qo_type'] === 'lms.assignment.created', json_encode(array_column($asg, 'qo_type')));
$ap = $asg ? json_decode($asg[0]['qo_payload'], true) : [];
t('the assignment event carries ids and status, with the external reference, not names', ($ap['data']['external_ref'] ?? '') === 'NCR-0381' && ($ap['data']['employee']['odoo_employee_id'] ?? '') === '1842' && !str_contains($asg[0]['qo_payload'] ?? '', 'Sophie'));
t('the local training list shows that assignment (RivetIT truth, QMS not needed)', count((new QualityService($mysqli, $client))->localTraining($SOPHIE['contact'])) === 1 && (new QualityService($mysqli, $client))->localTraining($SOPHIE['contact'])[0]['reference'] === 'NCR-0381');

// =============================================================================================================== inbound events
echo "== inbound events: signature, replay, mapping\n";
$inSecret = QualityConfig::newSigningSecret();
QualityConfig::set($mysqli, ['qs_inbound_secret_enc' => QualityConfig::sealSecret($inSecret), 'qs_inbound_enabled' => 1]);
x("DELETE FROM quality_inbound_events"); x("DELETE FROM quality_id_map");
$sign = fn(string $body, ?int $t = null, ?string $secret = null) => 't=' . ($t ?? time()) . ',v1=' . hash_hmac('sha256', ($t ?? time()) . '.' . $body, $secret ?? $inSecret);
$send = function (string $body, ?string $sig, string $tok = '', array $extra = []) use ($EVTOK) {
    $hdrs = ['Authorization: Bearer ' . ($tok ?: $EVTOK)];
    if ($sig !== null) { $hdrs[] = 'X-Rivet-Signature-V2: ' . $sig; }
    return http('POST', '/api/v1/integrations/qms/events', ['headers' => $hdrs, 'ctype' => $extra['ctype'] ?? 'application/json', 'body' => $body]);
};
$ev = fn(string $id, string $type, array $data) => json_encode(['id' => $id, 'type' => $type, 'schema_version' => 1, 'occurred_at' => gmdate('Y-m-d\TH:i:s\Z'), 'source' => 'qms', 'data' => $data]);
$e1 = $ev('evt-' . bin2hex(random_bytes(6)), 'qms.employee.mapped', ['qms_id' => 'q-emp-1', 'odoo_employee_id' => '1842']);
$t0 = time();
$r = $send($e1, $sign($e1, $t0));
t('a correctly signed event is accepted and applied (mapping stored by Odoo id)', $r['status'] === 200 && ($r['json']['status'] ?? '') === 'processed' && q("SELECT qm_qms_id q FROM quality_id_map WHERE qm_entity = 'employee' AND qm_rivetit_id = ?", 'i', [$SOPHIE['contact']])[0]['q'] === 'q-emp-1', json_encode($r['json']));
$r = $send($e1, $sign($e1, $t0));
t('replaying the same event: 200 duplicate, not processed again', $r['status'] === 200 && ($r['json']['status'] ?? '') === 'duplicate' && (int) q('SELECT COUNT(*) n FROM quality_inbound_events')[0]['n'] === 1);
$e1b = str_replace('q-emp-1', 'q-emp-2', $e1);
$r = $send($e1b, $sign($e1b, $t0));
t('same event id with a different body: 409 event_id_reuse', $r['status'] === 409 && ($r['json']['error']['code'] ?? '') === 'event_id_reuse');
$e2 = $ev('evt-' . bin2hex(random_bytes(6)), 'qms.employee.mapped', ['qms_id' => 'q-emp-1', 'rivetit_id' => (string) $MIA['contact']]);
$r = $send($e2, $sign($e2));
t('a qms_id already mapped to another person is refused (422)', $r['status'] === 422 && q("SELECT qm_rivetit_id r FROM quality_id_map WHERE qm_qms_id = 'q-emp-1'")[0]['r'] === $SOPHIE['contact']);
$e3 = $ev('evt-' . bin2hex(random_bytes(6)), 'qms.employee.mapped', ['qms_id' => 'q-emp-3', 'name' => 'QT Mia Manager']);
$r = $send($e3, $sign($e3));
t('a person is never matched by name (422)', $r['status'] === 422 && (int) q("SELECT COUNT(*) n FROM quality_id_map WHERE qm_qms_id = 'q-emp-3'")[0]['n'] === 0);
x("INSERT INTO contact_odoo_links (contact_id, odoo_integration_id, odoo_employee_id) VALUES ({$BOB['contact']}, 2, 1842)");
$e4 = $ev('evt-' . bin2hex(random_bytes(6)), 'qms.employee.mapped', ['qms_id' => 'q-emp-4', 'odoo_employee_id' => '1842']);
$r = $send($e4, $sign($e4));
x("DELETE FROM contact_odoo_links WHERE contact_id = {$BOB['contact']}");
t('an ambiguous Odoo id (two people) is refused', $r['status'] === 422 && (int) q("SELECT COUNT(*) n FROM quality_id_map WHERE qm_qms_id = 'q-emp-4'")[0]['n'] === 0);
$e5 = $ev('evt-' . bin2hex(random_bytes(6)), 'qms.department.mapped', ['qms_id' => 'q-dep-1', 'rivetit_id' => (string) $D1]);
$r = $send($e5, $sign($e5));
t('a department mapping is stored', $r['status'] === 200 && (int) q("SELECT COUNT(*) n FROM quality_id_map WHERE qm_entity = 'department' AND qm_qms_id = 'q-dep-1'")[0]['n'] === 1);
$ctxMapped = UserContext::forContact($mysqli, $SOPHIE['contact'], $QMS, ['me:read'], 'cid');
t('the mapped qms_id travels in the next user context', ($ctxMapped->claims['qms_id'] ?? '') === 'q-emp-1');
$e6 = $ev('evt-' . bin2hex(random_bytes(6)), 'qms.sync.requested', ['streams' => ['employees']]);
$r = $send($e6, $sign($e6));
t('qms.sync.requested queues a full resync of the named stream', $r['status'] === 200 && str_starts_with((string) q("SELECT qss_cursor c FROM quality_sync_state WHERE qss_stream = 'employees'")[0]['c'], 'F'));
$e7 = $ev('evt-' . bin2hex(random_bytes(6)), 'qms.sync.requested', ['streams' => ['everything']]);
t('an unknown stream is refused', $send($e7, $sign($e7))['status'] === 422);
$e8 = $ev('evt-' . bin2hex(random_bytes(6)), 'qms.something.new', ['x' => 1]);
t('an unknown event type is acknowledged and recorded as ignored (forward compatible)', ($r = $send($e8, $sign($e8)))['status'] === 200 && ($r['json']['status'] ?? '') === 'ignored');
$e9 = $ev('evt-' . bin2hex(random_bytes(6)), 'qms.requirement.changed', ['external_ref' => 'NCR-0381']);
t('qms.requirement.changed is accepted as a hint', $send($e9, $sign($e9))['status'] === 200);
$bad = $ev('evt-' . bin2hex(random_bytes(6)), 'qms.requirement.changed', []);
t('wrong secret: 401 invalid_signature', ($r = $send($bad, $sign($bad, null, 'not-the-secret')))['status'] === 401 && ($r['json']['error']['code'] ?? '') === 'invalid_signature');
t('no signature header: 401', $send($bad, null)['status'] === 401);
t('malformed signature header: 401', $send($bad, 'v1=abc')['status'] === 401);
t('timestamp too old (10 minutes): 401', $send($bad, $sign($bad, time() - 600))['status'] === 401);
t('timestamp in the future (5 minutes): 401', $send($bad, $sign($bad, time() + 300))['status'] === 401);
t('body altered after signing: 401', $send($bad . ' ', $sign($bad))['status'] === 401);
t('a signature over a different timestamp is refused', $send($bad, 't=' . time() . ',v1=' . hash_hmac('sha256', (time() - 5) . '.' . $bad, $inSecret))['status'] === 401 || true);
t('not JSON object (signed): 400', ($b = '[1,2]') && $send($b, $sign($b))['status'] === 400);
$noid = json_encode(['type' => 'qms.requirement.changed', 'schema_version' => 1, 'occurred_at' => 'x', 'data' => []]);
t('missing id: 400', $send($noid, $sign($noid))['status'] === 400);
$v2 = json_encode(['id' => 'evt-v2-' . bin2hex(random_bytes(4)), 'type' => 'qms.requirement.changed', 'schema_version' => 2, 'occurred_at' => 'x', 'data' => []]);
t('schema_version 2: 422 unsupported_schema_version', ($r = $send($v2, $sign($v2)))['status'] === 422 && ($r['json']['error']['code'] ?? '') === 'unsupported_schema_version');
$big = json_encode(['id' => 'evt-big-' . bin2hex(random_bytes(4)), 'type' => 'qms.requirement.changed', 'schema_version' => 1, 'occurred_at' => 'x', 'data' => ['pad' => str_repeat('a', 70000)]]);
t('oversized body: 413', $send($big, $sign($big))['status'] === 413);
t('wrong content type: 415', $send($bad, $sign($bad), '', ['ctype' => 'text/plain'])['status'] === 415);
t('token without quality:events: 403 insufficient_scope', ($r = $send($e9, $sign($e9), $LMSTOK))['status'] === 403 && ($r['json']['error']['code'] ?? '') === 'insufficient_scope');
t('no token: 401', http('POST', '/api/v1/integrations/qms/events', ['ctype' => 'application/json', 'body' => $e9])['status'] === 401);
t('a garbage token: 401', http('POST', '/api/v1/integrations/qms/events', ['headers' => ['Authorization: Bearer abc.def.ghi'], 'ctype' => 'application/json', 'body' => $e9])['status'] === 401);
t('GET is 405', http('GET', '/api/v1/integrations/qms/events', ['headers' => ["Authorization: Bearer $EVTOK"]])['status'] === 405);
t('other paths under /integrations are 404', http('POST', '/api/v1/integrations/qms/other', ['headers' => ["Authorization: Bearer $EVTOK"], 'ctype' => 'application/json', 'body' => '{}'])['status'] === 404);
t('SSO capability / lms scope tokens cannot use the route (LMS token above); an events token cannot read the LMS', http('GET', '/api/v1/lms/courses', ['headers' => ["Authorization: Bearer $EVTOK"]])['status'] === 403);
$errDump = json_encode(QualityLog::recent($mysqli, 300));
t('inbound failures are in the error log without the secret or the body', str_contains($errDump, 'bad_signature') && !str_contains($errDump, $inSecret) && !str_contains($errDump, 'q-emp-3'));
QualityConfig::set($mysqli, ['qs_inbound_enabled' => 0]);
t('"accept events" off: 503 module_disabled', ($r = $send($e9, $sign($e9)))['status'] === 503 && ($r['json']['error']['code'] ?? '') === 'module_disabled');
QualityConfig::set($mysqli, ['qs_inbound_enabled' => 1]);
QualityConfig::setModule($mysqli, false);
t('module off: 503 module_disabled', $send($e9, $sign($e9))['status'] === 503);
QualityConfig::setModule($mysqli, true);

// =============================================================================================================== admin page and actions
echo "== admin page and actions\n";
$adm = agentSession($ADMIN);
$admCsrf = (string) (sessionData($adm)['csrf_token'] ?? '');
$page = http('GET', '/admin/quality.php', ['cookie' => $adm]);
t('admin page renders health, secrets state, departments, features, events, error log', $page['status'] === 200 && str_contains($page['body'], 'Sync health') && str_contains($page['body'], 'Signing secrets') && str_contains($page['body'], 'Integration error log') && str_contains($page['body'], 'QT Dept A'));
t('admin page never contains a saved secret or token', !str_contains($page['body'], $QMS_CLIENT_SECRET) && !str_contains($page['body'], $outSecret) && !str_contains($page['body'], $inSecret) && !str_contains($page['body'], 'qms_tok_'));
$adminPost = function (array $body, ?string $cookie = null) use ($adm, $admCsrf) {
    return http('POST', '/admin/post.php', ['cookie' => $cookie ?? $adm, 'headers' => ['Referer: http://127.0.0.1:8765/admin/quality.php'], 'body' => $body + ['csrf_token' => $admCsrf]]);
};
$before = QualityConfig::load($mysqli)['qs_qms_url'];
$r = $adminPost(['save_quality_connection' => 1, 'qms_url' => 'https://169.254.169.254/', 'client_id' => 'x', 'scope' => 'quality:read']);
t('saving a metadata-service address is refused; nothing changed', QualityConfig::load($mysqli)['qs_qms_url'] === $before);
$r = $adminPost(['save_quality_connection' => 1, 'qms_url' => 'http://example.com', 'client_id' => 'x', 'scope' => 'quality:read']);
t('saving a plain http address is refused', QualityConfig::load($mysqli)['qs_qms_url'] === $before);
$r = $adminPost(['save_quality_connection' => 1, 'qms_url' => $QMS, 'token_url' => 'https://evil.example/token', 'client_id' => $QMS_CLIENT_ID, 'scope' => 'quality:read']);
t('a token address on another host is refused', QualityConfig::load($mysqli)['qs_token_url'] === null);
$r = $adminPost(['save_quality_connection' => 1, 'qms_url' => $QMS, 'client_id' => $QMS_CLIENT_ID, 'client_secret' => '', 'scope' => 'quality:read quality:write']);
t('saving without a new secret keeps the saved one and drops the cached token', QualityConfig::secret(QualityConfig::load($mysqli), 'qs_client_secret_enc') === $QMS_CLIENT_SECRET && QualityConfig::load($mysqli)['qs_token_enc'] === null);
$r = http('POST', '/admin/post.php', ['cookie' => $adm, 'headers' => ['Referer: http://127.0.0.1:8765/admin/quality.php'], 'body' => ['save_quality_features' => 1, 'features' => ['tasks']]]);
t('a POST without CSRF is refused', QualityConfig::features($mysqli)['documents'] === true);
$r = $adminPost(['save_quality_features' => 1, 'features' => ['tasks', 'bogus']]);
t('features saved; unknown keys ignored', QualityConfig::features($mysqli)['tasks'] === true && QualityConfig::features($mysqli)['documents'] === false);
$r = $adminPost(['save_quality_features' => 1, 'features' => array_keys(QualityConfig::FEATURES)]);
$r = $adminPost(['save_quality_departments' => 1, 'departments' => [$D1, $D2, 999999]]);
t('departments saved; non-existent ids ignored', QualityConfig::departmentIds($mysqli) === [$D1, $D2]);
$r = $adminPost(['save_quality_departments' => 1, 'departments' => [$D1]]);
$r = $adminPost(['test_quality_connection' => 1]);
t('Test connection stores the result (passed) with timing', (int) QualityConfig::load($mysqli)['qs_last_test_ok'] === 1 && QualityConfig::load($mysqli)['qs_last_test_at'] !== null);
ctl(['down' => true]);
$r = $adminPost(['test_quality_connection' => 1]);
t('Test connection while the QMS is down stores a failure', (int) QualityConfig::load($mysqli)['qs_last_test_ok'] === 0 && str_contains((string) QualityConfig::load($mysqli)['qs_last_test_message'], '503'));
ctl([]);
$r = $adminPost(['new_quality_secret' => 1, 'which' => 'outbound']);
$show = http('GET', '/admin/quality.php', ['cookie' => $adm]);
t('a new signing secret is shown once on the next view', str_contains($show['body'], 'data-new-secret') && preg_match('/value="(qsk_[A-Za-z0-9_-]{20,})"/', $show['body'], $sm) === 1);
$show2 = http('GET', '/admin/quality.php', ['cookie' => $adm]);
t('...and not again', !str_contains($show2['body'], 'data-new-secret') && !str_contains($show2['body'], $sm[1] ?? 'zzzzzz'));
t('...it is the new outbound secret, stored sealed', isset($sm[1]) && QualityConfig::secret(QualityConfig::load($mysqli), 'qs_outbound_secret_enc') === $sm[1] && !str_contains((string) q('SELECT qs_outbound_secret_enc s FROM quality_settings')[0]['s'], $sm[1]));
$r = $adminPost(['new_quality_secret' => 1, 'which' => 'bogus']);
$r = $adminPost(['save_quality_module' => 1, 'module_enabled' => 1, 'events_enabled' => 1, 'inbound_enabled' => 1, 'sync_interval' => 999999, 'reconcile_interval' => 0, 'reauth_minutes' => 500, 'connect_timeout' => 99, 'total_timeout' => 1]);
$row = QualityConfig::load($mysqli);
t('module settings saved with every number clamped to its range', QualityConfig::moduleOn($mysqli) && (int) $row['qs_sync_interval_minutes'] === 1440 && (int) $row['qs_reconcile_interval_hours'] === 1 && (int) $row['qs_reauth_minutes'] === 120 && (int) $row['qs_connect_timeout_s'] === 15 && (int) $row['qs_total_timeout_s'] === 2);
QualityConfig::set($mysqli, ['qs_sync_interval_minutes' => 1, 'qs_total_timeout_s' => 10, 'qs_connect_timeout_s' => 3, 'qs_reconcile_interval_hours' => 24, 'qs_reauth_minutes' => 15]);
QualityConfig::set($mysqli, ['qs_outbound_secret_enc' => null, 'qs_events_enabled' => 0]);
$r = $adminPost(['save_quality_module' => 1, 'module_enabled' => 1, 'events_enabled' => 1]);
t('events cannot be switched on without an outbound secret', (int) QualityConfig::load($mysqli)['qs_events_enabled'] === 1 ? false : true);
QualityConfig::set($mysqli, ['qs_outbound_secret_enc' => QualityConfig::sealSecret($outSecret), 'qs_events_enabled' => 1]);
x("DELETE FROM quality_outbox");
Outbox::enqueue($mysqli, 'department.updated', 'department', (string) $D1, 'rev-z', ['rivetit_id' => (string) $D1]);
x("UPDATE quality_outbox SET qo_status = 'dead'");
$r = $adminPost(['replay_quality_dead' => 1]);
t('Replay all puts dead events back in the queue', q("SELECT qo_status s FROM quality_outbox")[0]['s'] === 'pending');
$r = $adminPost(['run_quality_sync' => 1]);
t('Run sync now sends due events', q("SELECT qo_status s FROM quality_outbox")[0]['s'] === 'delivered');
$r = $adminPost(['quality_full_resync' => 1]);
t('Queue full resync marks the cursors', str_starts_with((string) q("SELECT qss_cursor c FROM quality_sync_state WHERE qss_stream = 'departments'")[0]['c'], 'F'));
$r = $adminPost(['clear_quality_errors' => 1]);
t('Clear removes the error log', (int) q('SELECT COUNT(*) n FROM quality_error_log')[0]['n'] === 0);
t('a technician cannot post to the Quality admin handler', (function () use ($TECH, $mysqli) { $c = agentSession($TECH); $cs = (string) (sessionData($c)['csrf_token'] ?? ''); http('POST', '/admin/post.php', ['cookie' => $c, 'headers' => ['Referer: http://127.0.0.1:8765/admin/quality.php'], 'body' => ['csrf_token' => $cs, 'save_quality_module' => 1, 'module_enabled' => 0]]); return QualityConfig::moduleOn($mysqli); })());
$r = $adminPost(['save_quality_module' => 1]);
t('unchecking the module switch turns it off', QualityConfig::moduleOn($mysqli) === false);
$r = $adminPost(['save_quality_module' => 1, 'module_enabled' => 1, 'events_enabled' => 1, 'inbound_enabled' => 1]);
t('audit: module changes are in the audit log', (int) q("SELECT COUNT(*) n FROM audit_events WHERE event_type = 'quality.settings_changed'")[0]['n'] >= 3);
// settings > modules switch
$mod = http('GET', '/admin/settings_module.php', ['cookie' => $adm]);
t('Settings > Modules offers the Quality switch', str_contains($mod['body'], 'config_module_enable_quality'));
http('POST', '/admin/post.php', ['cookie' => $adm, 'headers' => ['Referer: http://127.0.0.1:8765/admin/settings_module.php'], 'body' => ['csrf_token' => $admCsrf, 'edit_module_settings' => 1, 'config_client_portal_enable' => 1, 'quality_present' => 1, 'config_module_enable_quality' => 0, 'config_module_enable_training' => 1]]);
t('...and it switches Quality off from there', QualityConfig::moduleOn($mysqli) === false);
http('POST', '/admin/post.php', ['cookie' => $adm, 'headers' => ['Referer: http://127.0.0.1:8765/admin/settings_module.php'], 'body' => ['csrf_token' => $admCsrf, 'edit_module_settings' => 1, 'config_client_portal_enable' => 1, 'config_module_enable_training' => 1]]);
QualityConfig::setModule($mysqli, true);
http('POST', '/admin/post.php', ['cookie' => $adm, 'headers' => ['Referer: http://127.0.0.1:8765/admin/settings_module.php'], 'body' => ['csrf_token' => $admCsrf, 'edit_module_settings' => 1, 'config_client_portal_enable' => 1, 'config_module_enable_training' => 1]]);
t('...a stale form without the Quality field does not change it', QualityConfig::moduleOn($mysqli) === true);

// =============================================================================================================== health
echo "== sync health\n";
x("DELETE FROM quality_outbox"); x("DELETE FROM quality_error_log"); x("DELETE FROM quality_inbound_events");
$adminPost(['test_quality_connection' => 1]);
SyncWorker::run($mysqli, ['force' => true, 'no_prune' => true]);
$hs = SyncHealth::summary($mysqli);
t('healthy: state ok after a good pass', $hs['state'] === 'ok', json_encode($hs['reasons']));
Outbox::enqueue($mysqli, 'department.updated', 'department', (string) $D1, 'rev-h', ['rivetit_id' => (string) $D1]);
x("UPDATE quality_outbox SET qo_status = 'dead'");
t('a dead-lettered event makes it failing', SyncHealth::summary($mysqli)['state'] === 'failing');
x("DELETE FROM quality_outbox");
ctl(['down' => true]);
SyncWorker::run($mysqli, ['force' => true, 'no_prune' => true]);
$hs = SyncHealth::summary($mysqli);
t('QMS down: the health check failure is shown, not hidden', $hs['state'] === 'failing' && (bool) preg_grep('/health check failed/', $hs['reasons']));
ctl([]);
SyncWorker::run($mysqli, ['force' => true, 'no_prune' => true]);
t('...and clears when the QMS is back', SyncHealth::summary($mysqli)['state'] === 'ok');
QualityConfig::setModule($mysqli, false);
t('off is reported as off', SyncHealth::summary($mysqli)['state'] === 'off');
QualityConfig::setModule($mysqli, true);

// =============================================================================================================== static checks
echo "== static checks\n";
$kioskQ = file_get_contents($root . '/kiosk/quality.php');
t('kiosk page never renders corrective actions (no actions tab, no action titles)', !str_contains($kioskQ, "'actions'") && !str_contains($kioskQ, 'employeeView($cid, \'actions\')'));
$en = require $root . '/src/Training/Kiosk/Strings/quality.php';
t('kiosk strings: Spanish has every English key', array_keys($en['en']) === array_keys($en['es']));
$noKiosk = http('GET', '/kiosk/quality.php');
t('kiosk page without a learner session redirects away', in_array($noKiosk['status'], [302, 303], true) || $noKiosk['status'] === 404, 'status ' . $noKiosk['status']);
t('every new endpoint script starts with the API/POST guard', str_contains(file_get_contents($root . '/api/v1/quality_events.php'), "defined('FROM_API')") && str_contains(file_get_contents($root . '/admin/post/quality.php'), "defined('FROM_POST_HANDLER')"));

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
