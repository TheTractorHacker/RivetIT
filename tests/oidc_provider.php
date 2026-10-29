<?php
/*
 * SSO provider (issue #55) end-to-end tests. Needs the scratch environment (see the PR): a schema-only/demo scratch database whose name
 * contains "scratch", config.php pointing at it, and the router server running:
 *
 *   php -d session.save_path=$SESS -S 127.0.0.1:8765 -t <worktree> tests/support/oidc_router.php
 *   RIVETIT_TEST_DB=1 OIDC_TEST_SESSIONS=$SESS php tests/oidc_provider.php
 *
 * It MUTATES the scratch database (fixtures, contact status changes). The relying party side uses firebase/php-jwt on its own, as a
 * stand-in for Datum: it knows nothing but the discovery document, the JWKS and the client credentials.
 * Nothing secret is printed: only pass/fail lines.
 */

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use ITFlow\Integration\IntegrationAuth;
use ITFlow\Oidc\KeyStore;
use ITFlow\Oidc\OidcConfig;
use ITFlow\Oidc\OidcProvider;
use ITFlow\Oidc\PortalResume;
use ITFlow\Oidc\QualityAccessPolicy;

if (getenv('RIVETIT_TEST_DB') !== '1') {
    fwrite(STDERR, "Refusing: set RIVETIT_TEST_DB=1 (this suite mutates the database config.php points at).\n");
    exit(2);
}
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
// Same clock as the endpoints (company time zone), so NOW() in the fixtures below means what it means to the server.
$tzRow = $mysqli->query('SELECT config_timezone FROM settings WHERE company_id = 1')->fetch_assoc();
$tzObj = new DateTimeZone((string) ($tzRow['config_timezone'] ?? 'UTC'));
date_default_timezone_set($tzObj->getName());
$mysqli->query("SET time_zone = '" . (new DateTime('now', $tzObj))->format('P') . "'");
$sessDir = (string) getenv('OIDC_TEST_SESSIONS');
if ($sessDir === '' || !is_dir($sessDir)) {
    fwrite(STDERR, "Set OIDC_TEST_SESSIONS to the session.save_path the test server uses.\n");
    exit(2);
}
$BASE = 'http://127.0.0.1:8765';
$ISS = 'https://127.0.0.1:8765';   // what the configured base URL yields: https:// + host (IntegrationAuth::issuer)
$REDIRECT = 'https://datum.example/auth/callback';
$PORTAL_PW = 'T3st-Portal-Pw#2026';

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
    return $rows;
}
function b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

/** HTTP without following redirects. $o: jar (cookie file), headers[], body (array|string), basic [user, pass], method. */
function http(string $method, string $path, array $o = []): array
{
    global $BASE;
    $ch = curl_init($BASE . $path);
    $hdr = [];
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 20]);
    if (!empty($o['jar'])) { curl_setopt($ch, CURLOPT_COOKIEJAR, $o['jar']); curl_setopt($ch, CURLOPT_COOKIEFILE, $o['jar']); }
    if (isset($o['cookie'])) { curl_setopt($ch, CURLOPT_COOKIE, $o['cookie']); }
    if (isset($o['basic'])) { curl_setopt($ch, CURLOPT_USERPWD, $o['basic'][0] . ':' . $o['basic'][1]); }
    if (isset($o['body'])) {
        $body = is_array($o['body']) ? http_build_query($o['body']) : $o['body'];
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
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
function qs(string $url): array { parse_str((string) parse_url($url, PHP_URL_QUERY), $o); return $o; }
function newJar(): string { $f = tempnam(sys_get_temp_dir(), 'oidcjar'); return $f; }

/** Authorize query for the test client. */
function authq(array $over = []): string
{
    global $CLIENT_UID, $REDIRECT, $VERIFIER;
    $p = ['response_type' => 'code', 'client_id' => $CLIENT_UID, 'redirect_uri' => $REDIRECT, 'scope' => 'openid profile', 'state' => 'st_' . bin2hex(random_bytes(8)),
        'nonce' => 'no_' . bin2hex(random_bytes(8)), 'code_challenge' => b64u(hash('sha256', $VERIFIER, true)), 'code_challenge_method' => 'S256'];
    foreach ($over as $k => $v) { if ($v === null) { unset($p[$k]); } else { $p[$k] = $v; } }
    return http_build_query($p);
}
/** Writes a PHP session file the test server will read: a crafted portal/agent/kiosk session. Returns the Cookie value. */
function craftSession(array $data): string
{
    global $sessDir;
    $sid = bin2hex(random_bytes(16));
    $php = 'php -d session.save_path=' . escapeshellarg($sessDir) . ' -r ' . escapeshellarg('session_id(' . var_export($sid, true) . '); session_start(); $_SESSION = ' . var_export($data, true) . '; session_write_close();');
    exec($php);
    return 'PHPSESSID=' . $sid;
}
function portalSession(array $over = []): string
{
    global $CONTACT_ID, $USER_ID, $CLIENT_DEPT;
    return craftSession($over + ['client_logged_in' => true, 'client_id' => $CLIENT_DEPT, 'user_id' => $USER_ID, 'user_type' => 2, 'contact_id' => $CONTACT_ID, 'logged' => true,
        'csrf_token' => 'csrf' . bin2hex(random_bytes(8)), 'login_method' => 'local', 'portal_auth_time' => time() - 60, 'portal_amr' => 'pwd']);
}
/** Runs a full authorize with a portal cookie; returns [code|null, state, nonce, response]. */
function authorizeCode(string $cookie, array $over = []): array
{
    $query = authq($over);
    $r = http('GET', '/oidc/authorize?' . $query, ['cookie' => $cookie]);
    $q = $r['loc'] ? qs($r['loc']) : [];
    return [$q['code'] ?? null, $q['state'] ?? null, qs('?' . $query)['nonce'] ?? null, $r];
}
function tokenReq(array $form, ?array $basic = null, array $o = []): array
{
    global $CLIENT_UID, $CLIENT_SECRET;
    $basic ??= [$CLIENT_UID, $CLIENT_SECRET];
    return http('POST', '/oidc/token', ['body' => $form, 'basic' => $basic] + $o);
}
function exchange(string $code, array $over = [], ?array $basic = null): array
{
    global $REDIRECT, $VERIFIER;
    return tokenReq($over + ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $REDIRECT, 'code_verifier' => $VERIFIER], $basic);
}
function setContact(string $col, $val): void
{
    global $mysqli, $CONTACT_ID;
    $st = $mysqli->prepare("UPDATE contacts SET $col = ? WHERE contact_id = ?");
    $st->bind_param(is_int($val) ? 'ii' : 'si', $val, $CONTACT_ID);
    $st->execute();
    $st->close();
}
function events(string $reason): int
{
    return (int) (q('SELECT COUNT(*) n FROM oidc_events WHERE oev_reason LIKE ?', 's', [$reason . '%'])[0]['n'] ?? 0);
}
function jwksKeySet(): array
{
    $r = http('GET', '/oidc/jwks');
    return $r['json'] ?? ['keys' => []];
}

// ================================================================================================================ fixtures
echo "== fixtures\n";
$VERIFIER = b64u(random_bytes(48));
$CONTACT_ID = 6;   // Sophie Tran (fictional demo data), portal login user 13, department 18
$USER_ID = 13;
$CLIENT_DEPT = 18;
$mysqli->query("UPDATE contacts SET contact_employment_status = 'active', contact_archived_at = NULL, contact_name = 'Sophie Tran' WHERE contact_id = $CONTACT_ID");
$hash = password_hash($PORTAL_PW, PASSWORD_DEFAULT);
$st = $mysqli->prepare("UPDATE users SET user_password = ?, user_status = 1, user_archived_at = NULL, user_token = NULL WHERE user_id = ?");
$st->bind_param('si', $hash, $USER_ID);
$st->execute();
$mysqli->query("UPDATE settings SET config_client_portal_enable = 1");
$mysqli->query("DELETE FROM user_settings WHERE user_id = $USER_ID AND 0");
$mysqli->query("UPDATE clients SET client_archived_at = NULL, client_status = 'Active' WHERE client_id = $CLIENT_DEPT");
$mysqli->query("DELETE FROM contact_odoo_links WHERE contact_id = $CONTACT_ID");
$mysqli->query("INSERT INTO contact_odoo_links (contact_id, odoo_integration_id, odoo_employee_id) VALUES ($CONTACT_ID, 1, 1842)");
foreach (['oidc_codes', 'oidc_tokens', 'oidc_pending', 'oidc_events', 'oidc_client_config', 'oidc_signing_keys'] as $tbl) { $mysqli->query("DELETE FROM $tbl"); }
$mysqli->query("DELETE FROM integration_clients WHERE client_name LIKE 'OIDC TEST%'");
OidcConfig::saveSettings($mysqli, true, 480, 300, 300, 1);

$made = IntegrationAuth::createClient($mysqli, 'OIDC TEST Datum', null, ['sso'], '', null, 1, true, []);
$CLIENT_UID = $made['client_uid']; $CLIENT_SECRET = $made['secret']; $CLIENT_ID = $made['client_id'];
OidcConfig::saveClient($mysqli, $CLIENT_ID, $REDIRECT . "\nhttps://datum.example/other/callback", 'https://datum.example/auth/login', true);
$other = IntegrationAuth::createClient($mysqli, 'OIDC TEST Other SSO', null, ['sso'], '', null, 1, true, []);
OidcConfig::saveClient($mysqli, $other['client_id'], 'https://other.example/cb', null, false);
$noprof = IntegrationAuth::createClient($mysqli, 'OIDC TEST No profile', null, ['sso'], '', null, 1, true, []);
OidcConfig::saveClient($mysqli, $noprof['client_id'], 'https://noprof.example/cb', null, false);
$lms = IntegrationAuth::createClient($mysqli, 'OIDC TEST LMS only', null, ['lms:read'], '', null, 1, true, []);
$ssoNoReg = IntegrationAuth::createClient($mysqli, 'OIDC TEST sso but unregistered', null, ['sso'], '', null, 1, true, []);

// ================================================================================================================ discovery + JWKS
echo "== discovery and JWKS\n";
$d = http('GET', '/.well-known/openid-configuration');
t('discovery 200 json', $d['status'] === 200 && str_contains($d['h']['content-type'][0] ?? '', 'application/json'));
$doc = $d['json'] ?? [];
t('issuer is the configured base URL (not a request header)', ($doc['issuer'] ?? '') === $ISS);
$d2 = http('GET', '/.well-known/openid-configuration', ['headers' => ['Host: evil.example', 'X-Forwarded-Host: evil.example', 'X-Forwarded-Proto: http']]);
t('Host / X-Forwarded-* headers do not change the issuer or endpoints', ($d2['json']['issuer'] ?? '') === $ISS && !str_contains($d2['body'], 'evil.example'));
t('endpoints under the issuer', ($doc['authorization_endpoint'] ?? '') === "$ISS/oidc/authorize" && ($doc['token_endpoint'] ?? '') === "$ISS/oidc/token"
    && ($doc['userinfo_endpoint'] ?? '') === "$ISS/oidc/userinfo" && ($doc['jwks_uri'] ?? '') === "$ISS/oidc/jwks");
t('only code flow, S256, ES256, confidential auth', ($doc['response_types_supported'] ?? null) === ['code'] && ($doc['grant_types_supported'] ?? null) === ['authorization_code']
    && ($doc['code_challenge_methods_supported'] ?? null) === ['S256'] && ($doc['id_token_signing_alg_values_supported'] ?? null) === ['ES256']
    && ($doc['token_endpoint_auth_methods_supported'] ?? null) === ['client_secret_basic', 'client_secret_post'] && !in_array('none', $doc['token_endpoint_auth_methods_supported'] ?? [], true));
t('no implicit, password, refresh, plain, email scope advertised', !in_array('token', $doc['response_types_supported'] ?? [], true) && !in_array('refresh_token', $doc['grant_types_supported'] ?? [], true)
    && !in_array('plain', $doc['code_challenge_methods_supported'] ?? [], true) && !in_array('email', $doc['scopes_supported'] ?? [], true) && ($doc['scopes_supported'] ?? null) === ['openid', 'profile']);
t('discovery advertises no email/phone/pin claims', !array_intersect(['email', 'phone_number', 'pin'], $doc['claims_supported'] ?? []));
$jw = http('GET', '/oidc/jwks');
$keys = $jw['json']['keys'] ?? [];
t('JWKS: one EC P-256 ES256 sig key with kid, public members only', count($keys) === 1 && $keys[0]['kty'] === 'EC' && $keys[0]['crv'] === 'P-256' && $keys[0]['alg'] === 'ES256' && $keys[0]['use'] === 'sig'
    && preg_match('/^[0-9a-f]{16}$/', $keys[0]['kid']) === 1 && !isset($keys[0]['d']) && isset($keys[0]['x'], $keys[0]['y']));
$row = q('SELECT okey_private_enc, okey_public_pem FROM oidc_signing_keys');
t('private key stored encrypted (no PEM in the database)', count($row) === 1 && !str_contains($row[0]['okey_private_enc'], 'PRIVATE KEY') && $row[0]['okey_private_enc'] !== '');
t('private key opens only under its own context', decryptSetting($row[0]['okey_private_enc'], KeyStore::CONTEXT) !== '' && decryptSetting($row[0]['okey_private_enc'], 'integration_token_key') === '');
t('discovery/JWKS cacheable for 5 minutes, POST refused', str_contains($jw['h']['cache-control'][0] ?? '', 'max-age=300') && http('POST', '/oidc/jwks')['status'] === 405);

// ================================================================================================================ happy path (real portal login)
echo "== happy path: code + PKCE with a real portal login\n";
$jar = newJar();
$state = 'state_' . bin2hex(random_bytes(8));
$nonce = 'nonce_' . bin2hex(random_bytes(8));
$authQuery = authq(['state' => $state, 'nonce' => $nonce]);
$r1 = http('GET', '/oidc/authorize?' . $authQuery, ['jar' => $jar]);
t('no portal session: redirect to /login.php (fixed path)', $r1['status'] === 302 && $r1['loc'] === '/login.php');
t('pending request kept in a cookie, not in the URL', $r1['loc'] === '/login.php' && !str_contains((string) $r1['loc'], 'state') && preg_grep('/rivet_oidc_pending=/', $r1['h']['set-cookie'] ?? []) !== []);
$pendCookie = implode(';', preg_grep('/rivet_oidc_pending=/', $r1['h']['set-cookie'] ?? []));
t('pending cookie is HttpOnly + SameSite=Lax', str_contains($pendCookie, 'HttpOnly') && stripos($pendCookie, 'SameSite=Lax') !== false);
$rl = http('GET', '/login.php', ['jar' => $jar]);
t('login page renders', $rl['status'] === 200);
$login = http('POST', '/login.php', ['jar' => $jar, 'body' => ['login' => '1', 'email' => 'sophie.tran@summitridge.example', 'password' => $PORTAL_PW]]);
t('portal login succeeds (redirect to the portal)', in_array($login['status'], [302, 303], true) && str_contains((string) $login['loc'], 'client/'), 'status ' . $login['status'] . ' loc ' . ($login['loc'] ?? '-'));
$land = http('GET', '/client/' . basename((string) ($login['loc'] ?? 'index.php')), ['jar' => $jar]);
t('portal landing hands back to /oidc/authorize (fixed path)', $land['status'] === 302 && $land['loc'] === '/oidc/authorize', 'status ' . $land['status'] . ' loc ' . ($land['loc'] ?? '-'));
$r2 = http('GET', '/oidc/authorize', ['jar' => $jar]);
t('resume issues a code to the registered redirect URI with state and iss', $r2['status'] === 302 && str_starts_with((string) $r2['loc'], $REDIRECT . '?'), (string) ($r2['loc'] ?? $r2['status']));
$q2 = qs((string) $r2['loc']);
t('state echoed unchanged, iss present (RFC 9207)', ($q2['state'] ?? '') === $state && ($q2['iss'] ?? '') === $ISS && isset($q2['code']) && preg_match('/^[A-Za-z0-9_-]{43}$/', $q2['code']) === 1);
t('authorize response never cached, not frameable, no referrer', str_contains($r2['h']['cache-control'][0] ?? '', 'no-store') && ($r2['h']['pragma'][0] ?? '') === 'no-cache'
    && ($r2['h']['x-frame-options'][0] ?? '') === 'DENY' && str_contains($r2['h']['content-security-policy'][0] ?? '', "frame-ancestors 'none'") && ($r2['h']['referrer-policy'][0] ?? '') === 'no-referrer');
$again = http('GET', '/oidc/authorize', ['jar' => $jar]);
t('back/forward replay of the resume finds no pending request', $again['status'] === 400 && $again['loc'] === null);
$code = $q2['code'];
$ex = exchange($code);
t('token endpoint 200, bearer, no refresh token', $ex['status'] === 200 && ($ex['json']['token_type'] ?? '') === 'Bearer' && isset($ex['json']['id_token'], $ex['json']['access_token']) && !isset($ex['json']['refresh_token']));
t('token response not cacheable', str_contains($ex['h']['cache-control'][0] ?? '', 'no-store') && ($ex['h']['pragma'][0] ?? '') === 'no-cache');
$idt = (string) ($ex['json']['id_token'] ?? '');
$access = (string) ($ex['json']['access_token'] ?? '');
t('access token opaque rvo_ + 43 chars, stored only as a hash', preg_match('/^rvo_[A-Za-z0-9_-]{43}$/', $access) === 1
    && q('SELECT COUNT(*) n FROM oidc_tokens WHERE otok_hash = ?', 's', [hash('sha256', $access)])[0]['n'] == 1
    && q('SELECT COUNT(*) n FROM oidc_tokens WHERE otok_hash = ?', 's', [$access])[0]['n'] == 0);
t('code stored only as a hash', q('SELECT COUNT(*) n FROM oidc_codes WHERE ocode_hash = ?', 's', [$code])[0]['n'] == 0);

// Independent relying party: only the discovery doc + JWKS + client id.
JWT::$leeway = 5;
$decoded = null;
try {
    $decoded = (array) JWT::decode($idt, JWK::parseKeySet(jwksKeySet(), 'ES256'));
    t('RP: ES256 signature verifies against the published JWKS', true);
} catch (\Throwable $e) {
    t('RP: ES256 signature verifies against the published JWKS', false, $e::class . ' ' . $e->getMessage());
    $decoded = [];
}
[$h64] = explode('.', $idt);
$hdr = json_decode((string) base64_decode(strtr($h64, '-_', '+/')), true) ?: [];
t('RP: header alg ES256, typ JWT, kid in JWKS', ($hdr['alg'] ?? '') === 'ES256' && ($hdr['typ'] ?? '') === 'JWT' && in_array($hdr['kid'] ?? '', array_column(jwksKeySet()['keys'], 'kid'), true));
t('RP: iss == issuer from discovery', ($decoded['iss'] ?? '') === $doc['issuer']);
t('RP: aud == the client id (string, single)', ($decoded['aud'] ?? null) === $CLIENT_UID);
t('RP: nonce matches the request', ($decoded['nonce'] ?? '') === $nonce);
t('RP: exp - iat == 300 (5 minutes), not expired, iat not in the future', (($decoded['exp'] ?? 0) - ($decoded['iat'] ?? 0)) === 300 && ($decoded['exp'] ?? 0) > time() && ($decoded['iat'] ?? PHP_INT_MAX) <= time() + 5);
t('RP: sub is the Odoo employee id, rivetit_id is the contact id', ($decoded['sub'] ?? '') === '1842' && ($decoded['rivetit_id'] ?? '') === (string) $CONTACT_ID);
t('RP: auth_time is the portal sign-in time, amr pwd', ($decoded['auth_time'] ?? 0) >= time() - 60 && ($decoded['auth_time'] ?? 0) <= time() && ($decoded['amr'] ?? []) === ['pwd']);
t('RP: name present (profile requested and allowed)', ($decoded['name'] ?? '') === 'Sophie Tran');
t('RP: no email / phone / pin claims', !array_intersect(['email', 'email_verified', 'phone_number', 'phone', 'pin', 'mobile', 'password'], array_keys($decoded)));
$ui = http('GET', '/oidc/userinfo', ['headers' => ['Authorization: Bearer ' . $access]]);
t('userinfo returns sub, rivetit_id, name; no-store', $ui['status'] === 200 && ($ui['json']['sub'] ?? '') === '1842' && ($ui['json']['rivetit_id'] ?? '') === (string) $CONTACT_ID && ($ui['json']['name'] ?? '') === 'Sophie Tran'
    && str_contains($ui['h']['cache-control'][0] ?? '', 'no-store') && ($ui['h']['pragma'][0] ?? '') === 'no-cache' && !isset($ui['json']['email']));
t('userinfo does not read the token from the query string or body', http('GET', '/oidc/userinfo?access_token=' . $access)['status'] === 401 && http('POST', '/oidc/userinfo', ['body' => ['access_token' => $access]])['status'] === 401);

// ================================================================================================================ replay revokes
echo "== code replay\n";
$replay = exchange($code);
t('replay of a used code: invalid_grant', $replay['status'] === 400 && ($replay['json']['error'] ?? '') === 'invalid_grant');
t('replay revoked the tokens issued from the code', http('GET', '/oidc/userinfo', ['headers' => ['Authorization: Bearer ' . $access]])['status'] === 401);
t('replay is audited as code_replay', events('code_replay') >= 1 && (int) q("SELECT COUNT(*) n FROM oidc_events WHERE oev_type = 'code_replay' AND oev_result = 'refused'")[0]['n'] >= 1);

// ================================================================================================================ authorize refusals
echo "== authorize: request validation\n";
$ck = portalSession();
foreach ([
    'wrong host' => 'https://evil.example/auth/callback',
    'path traversal' => 'https://datum.example/auth/callback/../evil',
    'extra query' => $REDIRECT . '?x=1',
    'fragment' => $REDIRECT . '#f',
    'case change' => 'https://DATUM.example/auth/callback',
    'suffix host' => 'https://datum.example.evil.com/auth/callback',
    'trailing slash' => $REDIRECT . '/',
    'http scheme' => 'http://datum.example/auth/callback',
    'userinfo trick' => 'https://datum.example@evil.example/auth/callback',
    'empty' => '',
] as $label => $ru) {
    $r = http('GET', '/oidc/authorize?' . authq(['redirect_uri' => $ru]), ['cookie' => $ck]);
    t("wrong redirect_uri ($label): plain page, no redirect, nothing reflected", $r['status'] === 400 && $r['loc'] === null && !str_contains($r['body'], 'evil') && !str_contains($r['body'], 'datum.example'));
}
$r = http('GET', '/oidc/authorize?' . authq(['client_id' => 'ic_00000000000000000000']), ['cookie' => $ck]);
t('unknown client: plain page', $r['status'] === 400 && $r['loc'] === null);
$r = http('GET', '/oidc/authorize?' . authq(['client_id' => $lms['client_uid']]), ['cookie' => $ck]);
t('client without the sso capability: plain page', $r['status'] === 400 && $r['loc'] === null);
$r = http('GET', '/oidc/authorize?' . authq(['client_id' => $ssoNoReg['client_uid']]), ['cookie' => $ck]);
t('sso client with no registration: plain page', $r['status'] === 400 && $r['loc'] === null);
$r = http('GET', '/oidc/authorize?' . authq() . '&state=other', ['cookie' => $ck]);
t('duplicate parameter: plain page', $r['status'] === 400 && $r['loc'] === null);
$r = http('GET', '/oidc/authorize?' . authq(['redirect_uri' => 'https://other.example/cb']), ['cookie' => $ck]);
t("another client's redirect URI is not accepted", $r['status'] === 400 && $r['loc'] === null);

$errCases = [
    'PKCE missing' => [['code_challenge' => null, 'code_challenge_method' => null], 'invalid_request'],
    'PKCE method missing' => [['code_challenge_method' => null], 'invalid_request'],
    'PKCE plain' => [['code_challenge_method' => 'plain'], 'invalid_request'],
    'PKCE challenge malformed' => [['code_challenge' => 'short'], 'invalid_request'],
    'state missing' => [['state' => null], 'invalid_request'],
    'state too short' => [['state' => 'abc'], 'invalid_request'],
    'nonce missing' => [['nonce' => null], 'invalid_request'],
    'implicit (token)' => [['response_type' => 'token'], 'unsupported_response_type'],
    'implicit (id_token)' => [['response_type' => 'id_token'], 'unsupported_response_type'],
    'hybrid (code id_token)' => [['response_type' => 'code id_token'], 'unsupported_response_type'],
    'response_mode fragment' => [['response_mode' => 'fragment'], 'invalid_request'],
    'scope without openid' => [['scope' => 'profile'], 'invalid_scope'],
    'scope email' => [['scope' => 'openid email'], 'invalid_scope'],
    'scope offline_access' => [['scope' => 'openid offline_access'], 'invalid_scope'],
    'request object' => [['request' => 'eyJ'], 'request_not_supported'],
    'request_uri' => [['request_uri' => 'https://x.example/r'], 'request_uri_not_supported'],
    'bad prompt' => [['prompt' => 'consent'], 'invalid_request'],
    'bad max_age' => [['max_age' => '-1'], 'invalid_request'],
];
foreach ($errCases as $label => [$over, $err]) {
    $qq = authq($over);
    $r = http('GET', '/oidc/authorize?' . $qq, ['cookie' => $ck]);
    $lq = $r['loc'] ? qs($r['loc']) : [];
    $stateIn = qs('?' . $qq)['state'] ?? null;
    $stateOk = $stateIn !== null && strlen($stateIn) >= 8;
    t("$label: error $err returned to the verified redirect URI", $r['status'] === 302 && str_starts_with((string) $r['loc'], $REDIRECT . '?') && ($lq['error'] ?? '') === $err && !isset($lq['code'])
        && ($lq['iss'] ?? '') === $ISS && ($stateOk ? ($lq['state'] ?? null) === $stateIn : !isset($lq['state'])), (string) ($r['loc'] ?? $r['status']));
}
$r = http('GET', '/oidc/authorize?' . authq(['client_id' => $noprof['client_uid'], 'redirect_uri' => 'https://noprof.example/cb', 'scope' => 'openid profile']), ['cookie' => $ck]);
t('profile scope refused for a client not allowed it', ($r['loc'] ? (qs($r['loc'])['error'] ?? '') : '') === 'invalid_scope');
t('POST to authorize with a code request is not accepted', http('POST', '/oidc/authorize', ['body' => authq(), 'cookie' => $ck])['status'] === 400);
$r = http('GET', '/oidc/authorize?' . authq(['prompt' => 'none']), ['cookie' => '']);
t('prompt=none without a session: login_required to the client, no page', ($r['loc'] ? (qs($r['loc'])['error'] ?? '') : '') === 'login_required' && str_starts_with((string) $r['loc'], $REDIRECT));

echo "== authorize: who is signing in\n";
$r = http('GET', '/oidc/authorize?' . authq(['next' => 'https://evil.example/', 'redirect' => '//evil.example', 'last_visited' => b64u('https://evil.example')]));
t('no session: login redirect target is fixed; extra redirect-looking params are ignored', $r['status'] === 302 && $r['loc'] === '/login.php');
$r = http('GET', '/oidc/authorize');
t('resume without a pending cookie: plain page', $r['status'] === 400 && $r['loc'] === null);
$r = http('GET', '/oidc/authorize?next=https://evil.example', ['cookie' => 'rivet_oidc_pending=' . b64u(random_bytes(32))]);
t('resume with an unknown pending id: plain page, no redirect', $r['status'] === 400 && $r['loc'] === null);
$kiosk = portalSession(['kiosk_session' => true]);
$r = http('GET', '/oidc/authorize?' . authq(), ['cookie' => $kiosk]);
t('kiosk/shared-screen session refused (audited)', $r['status'] === 403 && $r['loc'] === null && events('kiosk_session') >= 1);
$agent = craftSession(['logged' => true, 'user_id' => 9, 'user_type' => 1, 'csrf_token' => 'x']);
$r = http('GET', '/oidc/authorize?' . authq(), ['cookie' => $agent]);
t('agent session is not a department person: refused (audited)', $r['status'] === 403 && $r['loc'] === null && events('not_portal_session') >= 1);
$preview = craftSession(['portal_preview' => ['x' => 1], 'logged' => true, 'user_id' => 9, 'user_type' => 1]);
$r = http('GET', '/oidc/authorize?' . authq(), ['cookie' => $preview]);
t('admin portal-preview state is not a portal login: refused', $r['status'] === 403 && $r['loc'] === null);
$mismatch = portalSession(['user_id' => 14]);
$r = http('GET', '/oidc/authorize?' . authq(), ['cookie' => $mismatch]);
t('session user not owning the contact: refused', $r['status'] === 403 && events('session_contact_mismatch') >= 1);

foreach ([
    ['contact terminated', 'contact_employment_status', 'terminated', 'active', 'policy:contact_not_active'],
    ['contact on leave', 'contact_employment_status', 'on_leave', 'active', 'policy:contact_not_active'],
    ['contact archived', 'contact_archived_at', '2026-01-01 00:00:00', null, 'policy:contact_archived'],
] as [$label, $col, $bad, $good, $reason]) {
    setContact($col, $bad);
    $r = http('GET', '/oidc/authorize?' . authq(), ['cookie' => $ck]);
    t("$label: refused with a plain page and an audit row", $r['status'] === 403 && $r['loc'] === null && !str_contains($r['body'], 'terminated') && events($reason) >= 1);
    if ($good === null) { $mysqli->query("UPDATE contacts SET $col = NULL WHERE contact_id = $CONTACT_ID"); } else { setContact($col, $good); }
}
$mysqli->query("UPDATE users SET user_status = 0 WHERE user_id = $USER_ID");
$r = http('GET', '/oidc/authorize?' . authq(), ['cookie' => $ck]);
t('portal login disabled: refused', $r['status'] === 403 && events('policy:login_disabled') >= 1);
$mysqli->query("UPDATE users SET user_status = 1, user_archived_at = NOW() WHERE user_id = $USER_ID");
$r = http('GET', '/oidc/authorize?' . authq(), ['cookie' => $ck]);
t('portal login archived: refused', $r['status'] === 403);
$mysqli->query("UPDATE users SET user_archived_at = NULL WHERE user_id = $USER_ID");
$mysqli->query("UPDATE users SET user_type = 1 WHERE user_id = $USER_ID");
$r = http('GET', '/oidc/authorize?' . authq(), ['cookie' => $ck]);
t('contact whose login is not a portal user: refused', $r['status'] === 403 && events('policy:not_portal_user') >= 1);
$mysqli->query("UPDATE users SET user_type = 2 WHERE user_id = $USER_ID");
$mysqli->query("UPDATE contacts SET contact_user_id = 0 WHERE contact_id = $CONTACT_ID");
$r = http('GET', '/oidc/authorize?' . authq(), ['cookie' => $ck]);
t('contact with no portal login at all: refused', $r['status'] === 403);
$mysqli->query("UPDATE contacts SET contact_user_id = $USER_ID WHERE contact_id = $CONTACT_ID");
$mysqli->query("UPDATE clients SET client_status = 'Inactive' WHERE client_id = $CLIENT_DEPT");
$r = http('GET', '/oidc/authorize?' . authq(), ['cookie' => $ck]);
t('department inactive: refused', $r['status'] === 403 && events('policy:department_inactive') >= 1);
$mysqli->query("UPDATE clients SET client_status = 'Active' WHERE client_id = $CLIENT_DEPT");
$mysqli->query("UPDATE settings SET config_client_portal_enable = 0");
$r = http('GET', '/oidc/authorize?' . authq(), ['cookie' => $ck]);
t('department portal switched off: refused', $r['status'] === 403 && events('policy:portal_disabled') >= 1);
$mysqli->query("UPDATE settings SET config_client_portal_enable = 1");
$mysqli->query("INSERT INTO user_settings (user_id, user_config_force_mfa) VALUES ($USER_ID, 1) ON DUPLICATE KEY UPDATE user_config_force_mfa = 1");
$r = http('GET', '/oidc/authorize?' . authq(), ['cookie' => $ck]);
t('administrator-required 2FA not enrolled yet: refused', $r['status'] === 403 && events('policy:mfa_enrollment_required') >= 1);
$mysqli->query("UPDATE user_settings SET user_config_force_mfa = 0 WHERE user_id = $USER_ID");
t('policy: eligible contact is allowed again', QualityAccessPolicy::allows($CONTACT_ID, $mysqli) === ['ok' => true, 'reason' => 'ok']);
t('policy: unknown contact refused', QualityAccessPolicy::allows(999999, $mysqli)['ok'] === false);

echo "== authorize: provider switched off\n";
OidcConfig::saveSettings($mysqli, false, 480, 300, 300, 1);
$r = http('GET', '/oidc/authorize?' . authq(), ['cookie' => $ck]);
t('provider off: authorize shows a plain unavailable page', $r['status'] === 503 && $r['loc'] === null && events('provider_disabled') >= 1);
t('provider off: discovery and JWKS answer 404', http('GET', '/.well-known/openid-configuration')['status'] === 404 && http('GET', '/oidc/jwks')['status'] === 404);
t('provider off: policy refuses', QualityAccessPolicy::allows($CONTACT_ID, $mysqli)['reason'] === 'provider_disabled');
OidcConfig::saveSettings($mysqli, true, 480, 300, 300, 1);

echo "== authorize: sign-in age\n";
$stale = portalSession(['portal_auth_time' => time() - 9 * 3600]);
$r = http('GET', '/oidc/authorize?' . authq(), ['cookie' => $stale]);
t('session older than 8 h: re-authentication prompt, no code', $r['status'] === 200 && $r['loc'] === null && str_contains($r['body'], 'sign in again') && !str_contains($r['body'], 'code='));
preg_match('/name="csrf_token" value="([^"]+)"/', $r['body'], $cm);
$noAuthTime = craftSession(['client_logged_in' => true, 'client_id' => $CLIENT_DEPT, 'user_id' => $USER_ID, 'user_type' => 2, 'contact_id' => $CONTACT_ID, 'logged' => true, 'csrf_token' => 'c1', 'login_method' => 'local']);
$r0 = http('GET', '/oidc/authorize?' . authq(), ['cookie' => $noAuthTime]);
t('session with no recorded sign-in time (older than this feature): treated as stale', $r0['status'] === 200 && str_contains($r0['body'], 'sign in again'));
$pendC = implode(';', preg_grep('/rivet_oidc_pending=/', $r['h']['set-cookie'] ?? []));
preg_match('/rivet_oidc_pending=([A-Za-z0-9_-]+)/', $pendC, $pm);
$bad = http('POST', '/oidc/authorize', ['cookie' => $stale . '; rivet_oidc_pending=' . ($pm[1] ?? ''), 'body' => ['action' => 'reauth', 'csrf_token' => 'wrong']]);
t('re-auth POST without the right CSRF token is refused (session kept)', $bad['status'] === 400);
$ok = http('POST', '/oidc/authorize', ['cookie' => $stale . '; rivet_oidc_pending=' . ($pm[1] ?? ''), 'body' => ['action' => 'reauth', 'csrf_token' => $cm[1] ?? '']]);
t('re-auth POST ends the portal session and goes to /login.php', $ok['status'] === 302 && $ok['loc'] === '/login.php');
t('the stale session no longer works', http('GET', '/oidc/authorize?' . authq(), ['cookie' => $stale])['loc'] === '/login.php');
$r = http('GET', '/oidc/authorize?' . authq(['max_age' => '30']), ['cookie' => portalSession(['portal_auth_time' => time() - 600])]);
t('max_age=30 with a 10 minute old sign-in: re-auth prompt', $r['status'] === 200 && str_contains($r['body'], 'sign in again'));
$r = http('GET', '/oidc/authorize?' . authq(['prompt' => 'login']), ['cookie' => $ck]);
t('prompt=login forces a fresh sign-in', $r['status'] === 200 && str_contains($r['body'], 'sign in again'));
$r = http('GET', '/oidc/authorize?' . authq(['prompt' => 'none']), ['cookie' => $stale === '' ? '' : portalSession(['portal_auth_time' => time() - 9 * 3600])]);
t('prompt=none with a stale session: login_required to the client', ($r['loc'] ? (qs($r['loc'])['error'] ?? '') : '') === 'login_required');
// Full re-auth round trip through the real login page.
$jar2 = newJar();
$staleSess = portalSession(['portal_auth_time' => time() - 12 * 3600]);
preg_match('/PHPSESSID=([0-9a-f]+)/', $staleSess, $sm);
file_put_contents($jar2, "#HttpOnly_127.0.0.1\tFALSE\t/\tFALSE\t0\tPHPSESSID\t{$sm[1]}\n");
$rr = http('GET', '/oidc/authorize?' . authq(), ['jar' => $jar2]);
preg_match('/name="csrf_token" value="([^"]+)"/', $rr['body'], $cm2);
http('POST', '/oidc/authorize', ['jar' => $jar2, 'body' => ['action' => 'reauth', 'csrf_token' => $cm2[1] ?? '']]);
http('GET', '/login.php', ['jar' => $jar2]);
$lg = http('POST', '/login.php', ['jar' => $jar2, 'body' => ['login' => '1', 'email' => 'sophie.tran@summitridge.example', 'password' => $PORTAL_PW]]);
$ld = http('GET', '/client/' . basename((string) ($lg['loc'] ?? 'index.php')), ['jar' => $jar2]);
$rs = http('GET', '/oidc/authorize', ['jar' => $jar2]);
t('stale -> re-auth -> real login -> resume issues a code', $ld['loc'] === '/oidc/authorize' && str_starts_with((string) $rs['loc'], $REDIRECT . '?code='), (string) ($rs['loc'] ?? $rs['status']));

// ================================================================================================================ token endpoint
echo "== token endpoint\n";
[$c1, , $n1] = authorizeCode($ck);
t('(setup) code issued for a crafted fresh portal session', $c1 !== null);
$wrongPw = tokenReq(['grant_type' => 'authorization_code', 'code' => $c1, 'redirect_uri' => $REDIRECT, 'code_verifier' => $VERIFIER], [$CLIENT_UID, 'rvs_wrong']);
t('wrong client secret: 401 invalid_client, uniform', $wrongPw['status'] === 401 && ($wrongPw['json']['error'] ?? '') === 'invalid_client' && str_contains($wrongPw['h']['www-authenticate'][0] ?? '', 'Basic'));
$unk = tokenReq(['grant_type' => 'authorization_code', 'code' => $c1, 'redirect_uri' => $REDIRECT, 'code_verifier' => $VERIFIER], ['ic_00000000000000000000', 'rvs_x']);
t('unknown client id: same 401 invalid_client body', $unk['status'] === 401 && $unk['json'] === $wrongPw['json']);
$nosso = tokenReq(['grant_type' => 'authorization_code', 'code' => $c1, 'redirect_uri' => $REDIRECT, 'code_verifier' => $VERIFIER], [$lms['client_uid'], $lms['secret']]);
t('valid integration credential without sso capability: 401 invalid_client', $nosso['status'] === 401 && $nosso['json'] === $wrongPw['json']);
$unreg = tokenReq(['grant_type' => 'authorization_code', 'code' => $c1, 'redirect_uri' => $REDIRECT, 'code_verifier' => $VERIFIER], [$ssoNoReg['client_uid'], $ssoNoReg['secret']]);
t('sso client with no registration: 401 invalid_client', $unreg['status'] === 401);
t('no client authentication at all: 401', http('POST', '/oidc/token', ['body' => ['grant_type' => 'authorization_code', 'code' => $c1, 'redirect_uri' => $REDIRECT, 'code_verifier' => $VERIFIER]])['status'] === 401);
t('client_id only (public client): 401', http('POST', '/oidc/token', ['body' => ['client_id' => $CLIENT_UID, 'grant_type' => 'authorization_code', 'code' => $c1, 'redirect_uri' => $REDIRECT, 'code_verifier' => $VERIFIER]])['status'] === 401);
$post = http('POST', '/oidc/token', ['body' => ['client_id' => $CLIENT_UID, 'client_secret' => $CLIENT_SECRET, 'grant_type' => 'authorization_code', 'code' => $c1, 'redirect_uri' => $REDIRECT, 'code_verifier' => $VERIFIER . 'x']]);
t('client_secret_post accepted (wrong verifier then refused)', ($post['json']['error'] ?? '') === 'invalid_grant');
t('wrong PKCE verifier burns the code (a retry with the right one fails)', (exchange($c1)['json']['error'] ?? '') === 'invalid_grant');
[$c2] = authorizeCode($ck);
$both = http('POST', '/oidc/token', ['body' => ['client_secret' => $CLIENT_SECRET, 'grant_type' => 'authorization_code', 'code' => $c2, 'redirect_uri' => $REDIRECT, 'code_verifier' => $VERIFIER], 'basic' => [$CLIENT_UID, $CLIENT_SECRET]]);
t('two client authentication methods at once: invalid_request', $both['status'] === 400 && ($both['json']['error'] ?? '') === 'invalid_request');
foreach (['password' => ['username' => 'a', 'password' => 'b'], 'client_credentials' => [], 'refresh_token' => ['refresh_token' => 'x'], 'implicit' => [], 'urn:ietf:params:oauth:grant-type:jwt-bearer' => ['assertion' => 'x']] as $g => $extra) {
    $r = tokenReq(['grant_type' => $g] + $extra);
    t("grant_type $g: unsupported_grant_type", $r['status'] === 400 && ($r['json']['error'] ?? '') === 'unsupported_grant_type');
}
t('missing grant_type: refused', tokenReq([])['status'] === 400);
$wr = exchange($c2, ['redirect_uri' => 'https://datum.example/other/callback']);
t('redirect_uri differing from the one in the authorization request: invalid_grant', ($wr['json']['error'] ?? '') === 'invalid_grant');
[$c3] = authorizeCode($ck);
$cross = exchange($c3, [], [$other['client_uid'], $other['secret']]);
t("another client cannot redeem this client's code", ($cross['json']['error'] ?? '') === 'invalid_grant' && (exchange($c3)['json']['error'] ?? '') === 'invalid_grant');
[$c4] = authorizeCode($ck);
$mysqli->query("UPDATE oidc_codes SET ocode_expires_at = NOW() - INTERVAL 1 SECOND WHERE ocode_hash = '" . hash('sha256', (string) $c4) . "'");
t('expired code (60 s) refused', (exchange($c4)['json']['error'] ?? '') === 'invalid_grant');
[$cTtl] = authorizeCode($ck);
$ttl = q('SELECT TIMESTAMPDIFF(SECOND, ocode_created_at, ocode_expires_at) s FROM oidc_codes WHERE ocode_hash = ?', 's', [hash('sha256', (string) $cTtl)])[0]['s'] ?? 0;
t('code lifetime is 60 seconds', (int) $ttl === 60);
t('malformed / unknown codes refused', (exchange('short')['json']['error'] ?? '') === 'invalid_grant' && (exchange(b64u(random_bytes(32)))['json']['error'] ?? '') === 'invalid_grant');
[$c5] = authorizeCode($ck);
t('verifier too short: invalid_request (code not burned)', (exchange($c5, ['code_verifier' => 'short'])['json']['error'] ?? '') === 'invalid_request' && exchange($c5)['status'] === 200);
t('token endpoint refuses GET and JSON bodies', http('GET', '/oidc/token')['status'] === 405 && tokenReq([], null, ['body' => '{"a":1}', 'ctype' => 'application/json'])['status'] === 400);
$dup = http('POST', '/oidc/token', ['body' => 'grant_type=authorization_code&grant_type=password', 'basic' => [$CLIENT_UID, $CLIENT_SECRET]]);
t('duplicate parameter refused', $dup['status'] === 400);
[$c6] = authorizeCode($ck);
setContact('contact_employment_status', 'terminated');
t('person terminated between authorize and token: no tokens', (exchange($c6)['json']['error'] ?? '') === 'invalid_grant' && events('policy:contact_not_active') >= 1);
setContact('contact_employment_status', 'active');
[$c7] = authorizeCode($ck, ['scope' => 'openid']);
$ex7 = exchange($c7);
$d7 = (array) JWT::decode((string) ($ex7['json']['id_token'] ?? ''), JWK::parseKeySet(jwksKeySet(), 'ES256'));
t('no profile scope: no name claim in the ID token or userinfo', $ex7['status'] === 200 && !isset($d7['name']) && !isset(http('GET', '/oidc/userinfo', ['headers' => ['Authorization: Bearer ' . $ex7['json']['access_token']]])['json']['name']));
$mysqli->query("DELETE FROM contact_odoo_links WHERE contact_id = $CONTACT_ID");
[$c8] = authorizeCode($ck);
$d8 = (array) JWT::decode((string) (exchange($c8)['json']['id_token'] ?? ''), JWK::parseKeySet(jwksKeySet(), 'ES256'));
t("contact not linked to Odoo: sub is 'rivetit:<contact id>'", ($d8['sub'] ?? '') === 'rivetit:' . $CONTACT_ID && ($d8['rivetit_id'] ?? '') === (string) $CONTACT_ID);
$mysqli->query("INSERT INTO contact_odoo_links (contact_id, odoo_integration_id, odoo_employee_id) VALUES ($CONTACT_ID, 1, 1842)");
// userinfo after the person is disabled
[$c9] = authorizeCode($ck);
$tok9 = exchange($c9)['json']['access_token'] ?? '';
t('userinfo works while the person is eligible', http('GET', '/oidc/userinfo', ['headers' => ['Authorization: Bearer ' . $tok9]])['status'] === 200);
$mysqli->query("UPDATE users SET user_status = 0 WHERE user_id = $USER_ID");
t('userinfo refused immediately once the login is disabled', http('GET', '/oidc/userinfo', ['headers' => ['Authorization: Bearer ' . $tok9]])['status'] === 401);
$mysqli->query("UPDATE users SET user_status = 1 WHERE user_id = $USER_ID");
$mysqli->query("UPDATE oidc_tokens SET otok_expires_at = NOW() - INTERVAL 1 SECOND WHERE otok_hash = '" . hash('sha256', (string) $tok9) . "'");
t('expired access token refused', http('GET', '/oidc/userinfo', ['headers' => ['Authorization: Bearer ' . $tok9]])['status'] === 401);
$ui401 = http('GET', '/oidc/userinfo');
t('userinfo without a token: 401 with WWW-Authenticate', $ui401['status'] === 401 && str_contains($ui401['h']['www-authenticate'][0] ?? '', 'Bearer'));
// token lifetimes within bounds; provider off blocks the token endpoint
OidcConfig::saveSettings($mysqli, true, 99999, 5, 99999, 1);
$cf = OidcConfig::load($mysqli);
t('lifetimes are clamped to safe bounds', $cf['max_auth_age_minutes'] === 1440 && $cf['id_token_ttl'] === 60 && $cf['access_token_ttl'] === 600);
OidcConfig::saveSettings($mysqli, true, 480, 120, 90, 1);
[$c10] = authorizeCode($ck);
$e10 = exchange($c10);
$d10 = (array) JWT::decode((string) $e10['json']['id_token'], JWK::parseKeySet(jwksKeySet(), 'ES256'));
t('configured lifetimes apply (ID 120 s, access 90 s)', ($d10['exp'] - $d10['iat']) === 120 && ($e10['json']['expires_in'] ?? 0) === 90);
OidcConfig::saveSettings($mysqli, true, 480, 300, 300, 1);
[$c11] = authorizeCode($ck);
OidcConfig::saveSettings($mysqli, false, 480, 300, 300, 1);
$off = exchange($c11);
t('provider off: token endpoint refuses (503), userinfo 401', $off['status'] === 503 && http('GET', '/oidc/userinfo', ['headers' => ['Authorization: Bearer ' . $e10['json']['access_token']]])['status'] === 401);
OidcConfig::saveSettings($mysqli, true, 480, 300, 300, 1);

// ---- single use under concurrency: two simultaneous redemptions of one code, exactly one wins
[$cc] = authorizeCode($ck);
$mh = curl_multi_init();
$handles = [];
for ($i = 0; $i < 2; $i++) {
    $ch = curl_init($BASE . '/oidc/token');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_USERPWD => "$CLIENT_UID:$CLIENT_SECRET",
        CURLOPT_POSTFIELDS => http_build_query(['grant_type' => 'authorization_code', 'code' => $cc, 'redirect_uri' => $REDIRECT, 'code_verifier' => $VERIFIER])]);
    curl_multi_add_handle($mh, $ch);
    $handles[] = $ch;
}
do { curl_multi_exec($mh, $running); curl_multi_select($mh, 0.1); } while ($running > 0);
$statuses = array_map(fn($ch) => curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $handles);
sort($statuses);
t('two simultaneous redemptions of one code: exactly one succeeds', $statuses === [200, 400], implode(',', $statuses));
$tokensForCode = (int) q('SELECT COUNT(*) n FROM oidc_tokens WHERE otok_code_hash = ? AND otok_revoked_at IS NULL', 's', [hash('sha256', (string) $cc)])[0]['n'];
t('...and if the loser was treated as a replay the winner is revoked, otherwise one token exists', $tokensForCode <= 1);

// ================================================================================================================ key rotation
echo "== signing key rotation\n";
[$ca] = authorizeCode($ck);
$exA = exchange($ca);
$kidA = (json_decode((string) base64_decode(strtr(explode('.', $exA['json']['id_token'])[0], '-_', '+/')), true) ?: [])['kid'] ?? '';
$rot = KeyStore::rotate($mysqli, 1);
$set = jwksKeySet();
$kids = array_column($set['keys'], 'kid');
t('after rotation JWKS holds the new key AND the previous one (overlap)', count($kids) === 2 && in_array($kidA, $kids, true) && in_array($rot['kid'], $kids, true));
[$cb] = authorizeCode($ck);
$exB = exchange($cb);
$kidB = (json_decode((string) base64_decode(strtr(explode('.', $exB['json']['id_token'])[0], '-_', '+/')), true) ?: [])['kid'] ?? '';
t('new tokens are signed by the new key', $kidB === $rot['kid'] && $kidB !== $kidA);
$okA = $okB = false;
try { JWT::decode($exA['json']['id_token'], JWK::parseKeySet($set, 'ES256')); $okA = true; } catch (\Throwable) {}
try { JWT::decode($exB['json']['id_token'], JWK::parseKeySet($set, 'ES256')); $okB = true; } catch (\Throwable) {}
t('token signed by the old key still verifies during the overlap; the new one verifies', $okA && $okB);
t('a token verified against a key set without its kid fails (kid is honoured)', (function () use ($exA, $set, $kidA) {
    $only = ['keys' => array_values(array_filter($set['keys'], fn($k) => $k['kid'] !== $kidA))];
    try { JWT::decode($exA['json']['id_token'], JWK::parseKeySet($only, 'ES256')); return false; } catch (\Throwable) { return true; }
})());
$left = q("SELECT TIMESTAMPDIFF(SECOND, NOW(), okey_publish_until) s FROM oidc_signing_keys WHERE okey_kid = ?", 's', [$kidA])[0]['s'] ?? 0;
t('retired key stays published 15 minutes (longest ID token life + skew)', $left > 800 && $left <= 900);
$mysqli->query("UPDATE oidc_signing_keys SET okey_publish_until = NOW() - INTERVAL 1 SECOND WHERE okey_kid = '$kidA'");
t('after the overlap the old key leaves the JWKS', array_column(jwksKeySet()['keys'], 'kid') === [$kidB]);
t('exactly one active signing key', (int) q("SELECT COUNT(*) n FROM oidc_signing_keys WHERE okey_status = 'active'")[0]['n'] === 1);
$cipher = q("SELECT okey_private_enc FROM oidc_signing_keys WHERE okey_kid = ?", 's', [$kidB])[0]['okey_private_enc'];
t('rotation registered with the settings-key rewrap registry', (function () {
    foreach (\ITFlow\Crypto\ColumnRegistry::all() as $s) {
        if ($s->table === 'oidc_signing_keys' && $s->column === 'okey_private_enc') {
            return $s->contextFor('anything') === \ITFlow\Crypto\SettingsCrypto::contextFor(\RivetCore\Crypto\KeyPurpose::SETTINGS, KeyStore::CONTEXT);
        }
    }
    return false;
})());

// ================================================================================================================ isolation
echo "== token isolation\n";
$ssoOnlyApi = http('POST', '/api/v1/oauth/token', ['body' => ['grant_type' => 'client_credentials', 'client_id' => $CLIENT_UID, 'client_secret' => $CLIENT_SECRET]]);
t('sso-only client cannot mint an API token', $ssoOnlyApi['status'] === 400 && !isset($ssoOnlyApi['json']['access_token']));
$ssoAsk = http('POST', '/api/v1/oauth/token', ['body' => ['grant_type' => 'client_credentials', 'client_id' => $CLIENT_UID, 'client_secret' => $CLIENT_SECRET, 'scope' => 'sso']]);
t('asking for the sso scope on the API token endpoint is refused', $ssoAsk['status'] === 400);
$both = IntegrationAuth::createClient($mysqli, 'OIDC TEST both', null, ['sso', 'lms:read'], '', null, 1, true, []);
$bothTok = http('POST', '/api/v1/oauth/token', ['body' => ['grant_type' => 'client_credentials', 'client_id' => $both['client_uid'], 'client_secret' => $both['secret']]]);
t('a client granted both gets an API token with only the lms scope (never sso)', $bothTok['status'] === 200 && ($bothTok['json']['scope'] ?? '') === 'lms:read');
foreach (['SSO access token' => $access . 'x', 'live SSO access token' => (string) ($e10['json']['access_token'] ?? ''), 'SSO ID token' => (string) $exB['json']['id_token'], 'SSO access token (fresh)' => (string) (exchange(authorizeCode($ck)[0] ?? 'x')['json']['access_token'] ?? 'rvo_none')] as $label => $tk) {
    $r = http('GET', '/api/v1/me', ['headers' => ['Authorization: Bearer ' . $tk]]);
    $l = http('GET', '/api/v1/lms/courses', ['headers' => ['Authorization: Bearer ' . $tk]]);
    t("$label rejected on /api/v1 and /api/v1/lms", $r['status'] === 401 && in_array($l['status'], [401, 403], true), "me={$r['status']} lms={$l['status']}");
}
$lmsTok = http('POST', '/api/v1/oauth/token', ['body' => ['grant_type' => 'client_credentials', 'client_id' => $lms['client_uid'], 'client_secret' => $lms['secret']]]);
$lmsBearer = (string) ($lmsTok['json']['access_token'] ?? '');
t('(setup) LMS token minted', $lmsTok['status'] === 200 && $lmsBearer !== '');
t('LMS token works on /api/v1/lms (control)', http('GET', '/api/v1/lms/courses', ['headers' => ['Authorization: Bearer ' . $lmsBearer]])['status'] === 200);
t('LMS token rejected at /oidc/userinfo', http('GET', '/oidc/userinfo', ['headers' => ['Authorization: Bearer ' . $lmsBearer]])['status'] === 401);
t('LMS client credentials rejected at /oidc/token', tokenReq(['grant_type' => 'client_credentials'], [$lms['client_uid'], $lms['secret']])['status'] === 401);
t('a legacy-looking hex API key is rejected at /oidc/userinfo', http('GET', '/oidc/userinfo', ['headers' => ['Authorization: Bearer ' . bin2hex(random_bytes(32))]])['status'] === 401);

// ================================================================================================================ portal resume + button
echo "== portal: resume safety and the Open Quality button\n";
foreach (['//evil.example/x', 'https://evil.example/', '/\\evil.example', '../../etc/passwd', 'javascript:alert(1)', str_repeat('A', 43) . '/../x', ''] as $junk) {
    t('PortalResume ignores a junk cookie value (' . substr($junk, 0, 20) . ')', PortalResume::resumeUrl($mysqli, [PortalResume::COOKIE => $junk]) === null);
}
t('PortalResume: well-formed but unknown id gives nothing', PortalResume::resumeUrl($mysqli, [PortalResume::COOKIE => b64u(random_bytes(32))]) === null);
$pv = new OidcProvider($mysqli, OidcConfig::load($mysqli));
$pid = $pv->createPending(OidcConfig::ssoClient($mysqli, $CLIENT_UID), ['redirect_uri' => $REDIRECT, 'scope' => 'openid', 'state' => 'abcdefgh', 'nonce' => 'nonce1234', 'challenge' => b64u(random_bytes(32)), 'force_login' => 0, 'max_age' => null]);
t('PortalResume: a real pending id yields only the fixed path', PortalResume::resumeUrl($mysqli, [PortalResume::COOKIE => $pid]) === '/oidc/authorize');
$pv->consumePending($pid);
t('PortalResume: a consumed pending id yields nothing', PortalResume::resumeUrl($mysqli, [PortalResume::COOKIE => $pid]) === null);
$pid2 = $pv->createPending(OidcConfig::ssoClient($mysqli, $CLIENT_UID), ['redirect_uri' => $REDIRECT, 'scope' => 'openid', 'state' => 'abcdefgh', 'nonce' => 'nonce1234', 'challenge' => b64u(random_bytes(32)), 'force_login' => 0, 'max_age' => null]);
$mysqli->query("UPDATE oidc_pending SET opend_expires_at = NOW() - INTERVAL 1 SECOND WHERE opend_hash = '" . hash('sha256', $pid2) . "'");
t('PortalResume: an expired pending id yields nothing', PortalResume::resumeUrl($mysqli, [PortalResume::COOKIE => $pid2]) === null);
$pc = portalSession();
$h1 = http('GET', '/client/index.php', ['cookie' => $pc . '; rivet_oidc_pending=https://evil.example/']);
t('portal home with a hostile pending cookie: no redirect', $h1['status'] === 200 && $h1['loc'] === null);
$pid3 = $pv->createPending(OidcConfig::ssoClient($mysqli, $CLIENT_UID), ['redirect_uri' => $REDIRECT, 'scope' => 'openid', 'state' => 'abcdefgh', 'nonce' => 'nonce1234', 'challenge' => b64u(random_bytes(32)), 'force_login' => 0, 'max_age' => null]);
$h2 = http('GET', '/client/index.php', ['cookie' => $pc . '; rivet_oidc_pending=' . $pid3]);
t('portal home with a valid pending cookie: redirect to exactly /oidc/authorize', $h2['status'] === 302 && $h2['loc'] === '/oidc/authorize');
$h2b = http('GET', '/client/tickets.php', ['cookie' => $pc . '; rivet_oidc_pending=' . $pid3]);
t('other portal pages do not redirect', $h2b['loc'] !== '/oidc/authorize');
$h3 = http('GET', '/client/index.php', ['cookie' => $pc]);
t('Open Quality button shown (provider on, client with login URL)', $h3['status'] === 200 && str_contains($h3['body'], 'Open Quality') && str_contains($h3['body'], 'href="https://datum.example/auth/login"'));
$h4 = http('GET', '/client/index.php', ['cookie' => portalSession(['kiosk_session' => true])]);
t('button hidden on a kiosk session', !str_contains($h4['body'], 'Open Quality'));
OidcConfig::saveSettings($mysqli, false, 480, 300, 300, 1);
t('button hidden when the provider is off', !str_contains(http('GET', '/client/index.php', ['cookie' => $pc])['body'], 'Open Quality'));
OidcConfig::saveSettings($mysqli, true, 480, 300, 300, 1);
OidcConfig::saveClient($mysqli, $CLIENT_ID, $REDIRECT, null, true);
t('button hidden when no login URL is configured', !str_contains(http('GET', '/client/index.php', ['cookie' => $pc])['body'], 'Open Quality'));
OidcConfig::saveClient($mysqli, $CLIENT_ID, $REDIRECT . "\nhttps://datum.example/other/callback", 'javascript:alert(1)', true);
t('a stored non-https login URL is never rendered', !str_contains(http('GET', '/client/index.php', ['cookie' => $pc])['body'], 'javascript:alert'));
OidcConfig::saveClient($mysqli, $CLIENT_ID, $REDIRECT . "\nhttps://datum.example/other/callback", 'https://datum.example/auth/login?a=1&b="><script>x</script>', true);
$hx = http('GET', '/client/index.php', ['cookie' => $pc]);
t('login URL is HTML-escaped where rendered', !str_contains($hx['body'], '<script>x</script>'));
OidcConfig::saveClient($mysqli, $CLIENT_ID, $REDIRECT . "\nhttps://datum.example/other/callback", 'https://datum.example/auth/login', true);

// ================================================================================================================ config validation
echo "== registration validation (redirect URIs, login URL)\n";
foreach (['http://datum.example/cb', 'https://*.example/cb', 'https://datum.example/cb#x', 'ftp://datum.example/cb', 'https://u:p@datum.example/cb', '/relative', 'https://datum.example/' . str_repeat('a', 400), "https://datum.example/a b", 'https://datum.example\\evil'] as $bad) {
    t('redirect URI rejected: ' . substr($bad, 0, 36), OidcConfig::validateRedirectUri($bad) !== null);
}
foreach (['https://datum.example/cb', 'https://datum.example:8443/a/b?x=1', 'http://localhost:3000/cb', 'http://127.0.0.1/cb'] as $good) {
    t('redirect URI accepted: ' . $good, OidcConfig::validateRedirectUri($good) === null);
}
foreach (['http://example.com/login', 'https://127.0.0.1/login', 'https://localhost/login', 'https://169.254.169.254/latest', 'https://user:pw@example.com/', 'https://example.com/login#x', 'javascript:alert(1)', 'https://[::1]/'] as $bad) {
    t('login URL rejected (UrlPolicy / https): ' . substr($bad, 0, 36), OidcConfig::validateLoginUrl($bad, $mysqli) !== null);
}
$goodLogin = OidcConfig::validateLoginUrl('https://example.com/auth/login', $mysqli);
t('login URL accepted when it resolves to a public address (needs DNS)', $goodLogin === null || str_contains((string) $goodLogin, 'not allowed'), (string) $goodLogin);
t('structural check alone (page render) accepts a plain https URL', OidcConfig::validateLoginUrl('https://datum.example/auth/login') === null);

// ================================================================================================================ admin page
echo "== admin page\n";
$adminCsrf = 'csrf' . bin2hex(random_bytes(8));
$admin = craftSession(['logged' => true, 'user_id' => 9, 'user_type' => 1, 'csrf_token' => $adminCsrf, 'login_time' => time()]);
$pg = http('GET', '/admin/sso_provider.php', ['cookie' => $admin]);
t('admin page renders for an administrator', $pg['status'] === 200 && str_contains($pg['body'], 'Single sign-on provider') && str_contains($pg['body'], $ISS . '/.well-known/openid-configuration'), 'status ' . $pg['status'] . ' loc ' . ($pg['loc'] ?? '-'));
t('admin page lists the sso client and the signing key id, no private material', str_contains($pg['body'], 'OIDC TEST Datum') && str_contains($pg['body'], $kidB) && !str_contains($pg['body'], 'PRIVATE KEY') && !str_contains($pg['body'], $CLIENT_SECRET));
t('admin page lists recent audit rows (escaped)', str_contains($pg['body'], 'code_issued') && str_contains($pg['body'], 'code_replay'));
$ref = ['Referer: ' . $BASE . '/admin/sso_provider.php'];
$ap = fn(array $body, ?string $cookie = null) => http('POST', '/admin/post.php', ['cookie' => $cookie ?? $admin, 'headers' => $ref, 'body' => $body]);
OidcConfig::saveSettings($mysqli, true, 480, 300, 300, 1);
$ap(['save_sso_settings' => '1', 'csrf_token' => 'wrong', 'max_auth_age' => 60, 'id_token_ttl' => 200, 'access_token_ttl' => 200]);
t('admin POST without a valid CSRF token changes nothing', OidcConfig::load($mysqli)['max_auth_age_minutes'] === 480);
$ap(['save_sso_settings' => '1', 'csrf_token' => $adminCsrf, 'max_auth_age' => 60, 'id_token_ttl' => 200, 'access_token_ttl' => 200]);
$c = OidcConfig::load($mysqli);
t('admin POST: settings saved; the switch (unticked) turns the provider off', $c['max_auth_age_minutes'] === 60 && $c['id_token_ttl'] === 200 && $c['access_token_ttl'] === 200 && $c['enabled'] === false);
$ap(['save_sso_settings' => '1', 'csrf_token' => $adminCsrf, 'enabled' => '1', 'max_auth_age' => 5000, 'id_token_ttl' => 200, 'access_token_ttl' => 200]);
t('admin POST: out-of-range numbers are refused outright', OidcConfig::load($mysqli)['enabled'] === false);
$ap(['save_sso_settings' => '1', 'csrf_token' => $adminCsrf, 'enabled' => '1', 'max_auth_age' => 480, 'id_token_ttl' => 300, 'access_token_ttl' => 300]);
t('admin POST: kill switch back on', OidcConfig::load($mysqli)['enabled'] === true);
$ap(['save_sso_client' => '1', 'csrf_token' => $adminCsrf, 'client_id' => $CLIENT_ID, 'redirect_uris' => "http://datum.example/cb", 'login_url' => '', 'allow_profile' => '1']);
t('admin POST: an http:// redirect URI is refused, registration unchanged', str_contains((string) q('SELECT ocfg_redirect_uris u FROM oidc_client_config WHERE ocfg_client_id = ?', 'i', [$CLIENT_ID])[0]['u'], $REDIRECT));
$ap(['save_sso_client' => '1', 'csrf_token' => $adminCsrf, 'client_id' => $CLIENT_ID, 'redirect_uris' => $REDIRECT, 'login_url' => 'https://127.0.0.1/login']);
t('admin POST: a loopback login URL is refused by UrlPolicy', q('SELECT ocfg_login_url u FROM oidc_client_config WHERE ocfg_client_id = ?', 'i', [$CLIENT_ID])[0]['u'] === 'https://datum.example/auth/login');
$ap(['save_sso_client' => '1', 'csrf_token' => $adminCsrf, 'client_id' => $lms['client_id'], 'redirect_uris' => $REDIRECT]);
t('admin POST: a client without the sso capability cannot be registered', (int) q('SELECT COUNT(*) n FROM oidc_client_config WHERE ocfg_client_id = ?', 'i', [$lms['client_id']])[0]['n'] === 0);
$ap(['save_sso_client' => '1', 'csrf_token' => $adminCsrf, 'client_id' => $CLIENT_ID, 'redirect_uris' => $REDIRECT . "\n" . 'https://datum.example/third/callback', 'login_url' => 'https://example.com/auth/login']);
t('admin POST: valid registration saved (login URL passed UrlPolicy)', str_contains((string) q('SELECT ocfg_redirect_uris u FROM oidc_client_config WHERE ocfg_client_id = ?', 'i', [$CLIENT_ID])[0]['u'], 'third/callback')
    && q('SELECT ocfg_login_url u FROM oidc_client_config WHERE ocfg_client_id = ?', 'i', [$CLIENT_ID])[0]['u'] === 'https://example.com/auth/login' && (int) q('SELECT ocfg_allow_profile p FROM oidc_client_config WHERE ocfg_client_id = ?', 'i', [$CLIENT_ID])[0]['p'] === 0);
OidcConfig::saveClient($mysqli, $CLIENT_ID, $REDIRECT . "\nhttps://datum.example/other/callback", 'https://datum.example/auth/login', true);
$kidBefore = KeyStore::status($mysqli)['active_kid'];
$ap(['rotate_sso_key' => '1', 'csrf_token' => 'wrong']);
t('admin POST: key rotation needs a valid CSRF token', KeyStore::status($mysqli)['active_kid'] === $kidBefore);
$ap(['rotate_sso_key' => '1', 'csrf_token' => $adminCsrf]);
$st2 = KeyStore::status($mysqli);
t('admin POST: key rotation creates a new active key and retires the old one', $st2['active_kid'] !== $kidBefore && in_array($kidBefore, array_column($st2['retired'], 'kid'), true));
$tech = craftSession(['logged' => true, 'user_id' => 10, 'user_type' => 1, 'csrf_token' => $adminCsrf]);
$beforeKey = KeyStore::status($mysqli)['active_kid'];
$ap(['rotate_sso_key' => '1', 'csrf_token' => $adminCsrf], $tech);
t('admin POST by a non-administrator does nothing', KeyStore::status($mysqli)['active_kid'] === $beforeKey);
t('admin actions are audited', events('key_rotated') >= 1 && events('client_registration_saved') >= 1 && events('enabled') >= 1 && events('disabled') >= 1);
$logs = (int) q("SELECT COUNT(*) n FROM logs WHERE log_type = 'SSO Provider'")[0]['n'];
t('and recorded in the general audit log', $logs > 10);
t('the secrets never reach the logs or events', (int) q("SELECT COUNT(*) n FROM logs WHERE log_description LIKE ? OR log_description LIKE '%rvo\\_%'", 's', ['%' . $CLIENT_SECRET . '%'])[0]['n'] === 0
    && (int) q('SELECT COUNT(*) n FROM oidc_events WHERE oev_detail LIKE ? OR oev_reason LIKE ?', 'ss', ['%' . $access . '%', '%' . $code . '%'])[0]['n'] === 0);

// ================================================================================================================ rate limits (last: they lock this address out for a while)
echo "== rate limits\n";
$mysqli->query('DELETE FROM oidc_events');
$codes429 = [];
for ($i = 0; $i < 30; $i++) {
    $codes429[] = tokenReq(['grant_type' => 'authorization_code', 'code' => 'x', 'redirect_uri' => $REDIRECT, 'code_verifier' => $VERIFIER], [$CLIENT_UID, 'rvs_wrong'])['status'];
}
$firstLimited = array_search(429, $codes429, true);
t('repeated bad client credentials are throttled (429 + Retry-After) within 9 attempts', $firstLimited !== false && $firstLimited <= 9, 'first 429 at attempt ' . var_export($firstLimited, true));
$thr = tokenReq(['grant_type' => 'authorization_code'], [$CLIENT_UID, 'rvs_wrong']);
t('throttled responses carry Retry-After and the same error shape', $thr['status'] === 429 && isset($thr['h']['retry-after']) && isset($thr['json']['error']));
t('even the right secret is throttled while locked out (no credential oracle)', exchange('x')['status'] === 429);
$mysqli->query('DELETE FROM oidc_events');
$a429 = [];
for ($i = 0; $i < 140; $i++) {
    $a429[] = http('GET', '/oidc/authorize?' . authq(['redirect_uri' => 'https://evil.example/x']))['status'];
    if (end($a429) === 429) { break; }
}
t('authorize flood is throttled with a plain 429 page', in_array(429, $a429, true), 'statuses ' . implode(',', array_unique($a429)));
$mysqli->query('DELETE FROM oidc_events');

if (getenv('OIDC_TEST_REDIS') === '1') {
    echo "== Redis limiter (a throwaway Redis on 127.0.0.1:6399 that the scratch settings point at)\n";
    $n429 = 0;
    for ($i = 0; $i < 135; $i++) { if (http('GET', '/oidc/jwks')['status'] === 429) { $n429++; } }
    t('discovery/JWKS requests over 120 per minute from one address get 429', $n429 >= 10, "429s: $n429");
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
