<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP
/*
 * The RMM module's secrets across a REAL rewrap (RivetCore\Crypto adoption, docs/KEY_MANAGEMENT.md). The module seals four things through
 * EndpointSecretBox -> encryptSetting(): the Ed25519 signing key, the Mesh login key, the secret parameters of a queued job and the values of
 * secret custom fields. A rewrap turns every ENC2: value into v3:, and the box must still open them. Proven here end to end, in a scratch
 * database and against a real `php -S` server:
 *
 *   1. the module is switched on with NO key file (signing key stored ENC2:), a device enrolls over HTTP, a technician queues a job and the
 *      device receives it signed (signature verified with the public key the agent pinned);
 *   2. the key file is created, a second key is added and made active, and RewrapService runs for real: the signing key, the Mesh login key,
 *      the job secrets and the custom field secrets are v3: under the new key;
 *   3. the same device gets a SECOND job over HTTP, signed with the SAME key (public key unchanged, signature verifies): the server opened the
 *      v3: signing key through EndpointSecretBox. The Mesh login key opens and builds a login cookie; a job's secret parameters and a secret
 *      custom field resolve to their plaintext; the vendor RMM clients (Tactical, Level, Action1, Sophos) read their credentials;
 *   4. a key file the web user cannot read: the signing key is "not available" (no job offered) rather than anything unsafe, and works again
 *      once the file is readable.
 *
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=rivetit_x_scratch RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/crypto_rmm_after_rewrap.php
 */
$cdir = sys_get_temp_dir() . '/crypto-rmm-' . bin2hex(random_bytes(4));
mkdir($cdir, 0755, true);
$kf = "$cdir/keys.json";
putenv('SCRATCH_KEYFILE=' . $kf);      // config.php of the scratch install turns this into $config_keyfile (the file does not exist yet); the server inherits it
$sd = sys_get_temp_dir() . '/crypto_rmm_state_' . bin2hex(random_bytes(4));
putenv("RMM_TEST_STATE_DIR=$sd");
putenv("RMM_GATE_STATE_DIR=$sd");
require __DIR__ . '/endpoint_agent_lib.php';
require_once "$root/includes/rmm_bootstrap.php";
register_shutdown_function(function () use ($sd, $cdir) {
    foreach (glob("$sd/*") ?: [] as $f) { @unlink($f); } @rmdir($sd);
    @chmod($cdir, 0755);
    foreach (glob("$cdir/*") ?: [] as $f) { @unlink($f); } @rmdir($cdir);
});

use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Crypto\Signer;
use RivetCore\Rmm\Mesh\MeshCookie;
use ITFlow\Core\Adapter\Endpoint\EndpointSecretBox;
use ITFlow\Crypto\KeyAdmin;
use ITFlow\Crypto\KeyStore;
use ITFlow\Crypto\RewrapService;
use ITFlow\Crypto\SettingsCrypto;

SettingsCrypto::reset();
$ok(!is_file($kf) && KeyStore::load()->source === 'legacy', 'start: no key file, the install is on the config.php key');

ea_reset();
$tokens = ea_seed_users();
$q("UPDATE settings SET config_module_enable_rmm = 1 WHERE company_id = 1");
$rmm = rivetRmmModule($db);
$admin = new RmmPrincipal(1, 'admin');
$box = new EndpointSecretBox();
$r = $rmm->admin()->enable($admin);
$ok($r->ok, 'the module is switched on (the signing key is minted through the secret box)');
$rmm->syncState();
$sign1 = (string) $one('SELECT signing_private_key_enc FROM endpoint_agent_settings WHERE id=1');
$pub1 = (string) $one('SELECT signing_public_key FROM endpoint_agent_settings WHERE id=1');
$ok(str_starts_with($sign1, 'ENC2:') && $pub1 !== '', 'before the key file the signing key is an ENC2: value');
$meshHex = bin2hex(random_bytes(48));
$rmm->settings()->set(['mesh_enabled' => 1, 'mesh_url' => 'https://mesh.example.test', 'mesh_domain' => '', 'mesh_login_key_enc' => encryptSetting($meshHex), 'mesh_account_template' => 'rivetit-support']);
$ok(str_starts_with((string) $one('SELECT mesh_login_key_enc FROM endpoint_agent_settings WHERE id=1'), 'ENC2:'), 'the Mesh login key is an ENC2: value');

// a custom field of type secret, and a queued job with secret parameters (the module's own services)
$fieldId = (int) $rmm->customFields()->define(['name' => 'vpn_psk', 'scope' => 'client', 'type' => 'secret', 'label' => 'VPN PSK'], 1)['field_id'];
$rmm->customFields()->setValue($fieldId, 1, 'psk-plain-VALUE-1', 1);
$ok(str_starts_with((string) $one("SELECT value_enc FROM rmm_custom_field_values WHERE field_id=$fieldId AND scope_id=1"), 'ENC2:'), 'a secret custom field value is stored ENC2:');
$jobUuid = ea_uuid();
$ok($rmm->jobExtras()->record($jobUuid, 1, ['secret' => ['api_token' => 'job-secret-TOKEN-9']]), 'a job sidecar with a secret parameter is recorded');
$ok(str_starts_with((string) $one("SELECT secret_params_enc FROM rmm_job_extra WHERE job_id='$jobUuid'"), 'ENC2:'), 'the job secrets are stored ENC2:');

// the vendor RMM integrations (credentials as the settings page stores them)
$q("DELETE FROM rmm_integrations WHERE type <> 'rivetit_agent'");
$vendors = [
    'tactical_rmm' => ['tac-api-KEY', 'https://tactical.example.test'],
    'level' => ['level-api-KEY', 'https://level.example.test'],
    'action1' => [json_encode(['client_id' => 'a1-client', 'client_secret' => 'a1-SECRET']), 'https://action1.example.test'],
    'sophos_central' => [json_encode(['client_id' => 'sc-client', 'client_secret' => 'sc-SECRET']), 'https://sophos.example.test'],
];
$vid = [];
foreach ($vendors as $type => [$secret, $url]) {
    $q("INSERT INTO rmm_integrations SET type='$type', name='crypto $type', api_url='" . $esc($url) . "', web_url='" . $esc($url) . "', api_key_enc='" . $esc(encryptSetting($secret)) . "', enabled=1");
    $vid[$type] = (int) $db->insert_id;
}

// ------------------------------------------------------------------ 1. before: a device enrolls, a job is queued and arrives signed
$tok = (string) $rmm->technician()->createToken($admin, 1, 0, 'stable', 24, 50, 'crypto test')->data['token'];
$q("INSERT INTO assets SET asset_type='Laptop', asset_name='CRYPTO-PC', asset_make='Dell', asset_serial='CRY-1', asset_client_id=1, asset_status='Active'");   // a device linked to an asset can receive jobs
[$c, , $j] = ea_enroll($tok, ea_dev(['hostname' => 'CRYPTO-PC', 'serial' => 'CRY-1']));
$ok($c === 201 && isset($j['device_token']), 'a device enrolls over HTTP');
$devTok = $j['device_token']; $devId = (int) $j['device_id'];
[$c] = ea_checkin($devTok);
$ok($c === 200, 'and checks in');
$offer = function () use ($devTok, $tokens, $devId): ?array {
    [$c, , $j] = http('POST', "/api/v1/endpoint_devices/$devId/jobs", $tokens['admin'], ['type' => 'reboot', 'confirm' => true]);
    if (!in_array($c, [200, 201, 202], true)) { fwrite(STDERR, "queue job -> $c " . json_encode($j) . "\n"); return null; }
    [$c2, , $jj] = http('GET', '/api/v1/agent_jobs', $devTok);
    $job = $jj['jobs'][0] ?? null;
    if ($job === null) { fwrite(STDERR, "agent_jobs -> $c2 " . json_encode($jj) . "\n"); }
    if ($job !== null) {   // finish it so the next one is offered
        http('POST', '/api/v1/agent_jobs', $devTok, ['job_id' => $job['job_id'], 'attempt' => 1, 'state' => 'succeeded', 'exit_code' => 0, 'output' => 'ok']);
    }
    return $job;
};
$verifies = fn (?array $job, string $pub) => $job !== null && !empty($job['signature']) && Signer::verify(Signer::jobMessage($job), $job['signature'], $pub);
$j1 = $offer();
$ok($verifies($j1, $pub1), 'BEFORE: the device is offered a job signed with the instance key (signature verifies against the pinned public key)');

// ------------------------------------------------------------------ 2. the real rewrap
KeyAdmin::generate($kf);
KeyAdmin::addKey(true);        // the active key is NOT k1: every value has somewhere to go
SettingsCrypto::reset();
$active = (string) KeyStore::load()->ring->activeKid();
$ok($active !== 'k1' && SettingsCrypto::v3Enabled(), 'a key file with two keys exists and the v3 stage is on (active ' . $active . ')');
$svc = new RewrapService($db, 1, "$cdir/state");
$dry = $svc->run(null, true);
$wouldMove = 0; foreach ($dry as $rep) { $wouldMove += $rep->rewrapped; }
$ok($wouldMove >= 5, "dry run: $wouldMove value(s) would move (signing key, Mesh key, job secret, custom field, vendor keys) and nothing is written");
$ok((string) $one('SELECT signing_private_key_enc FROM endpoint_agent_settings WHERE id=1') === $sign1, 'the dry run changed nothing');
$bad = 0; $moved = 0;
foreach ([1, 2] as $pass) { foreach ($svc->run() as $rep) { $bad += $rep->failed + $rep->conflicts; $moved += $rep->rewrapped; } }
$ok($bad === 0 && $moved >= 5, "the real rewrap moved $moved value(s) with no failures");
foreach ([
    'signing key' => 'SELECT signing_private_key_enc FROM endpoint_agent_settings WHERE id=1',
    'Mesh login key' => 'SELECT mesh_login_key_enc FROM endpoint_agent_settings WHERE id=1',
    'job secrets' => "SELECT secret_params_enc FROM rmm_job_extra WHERE job_id='$jobUuid'",
    'custom field secret' => "SELECT value_enc FROM rmm_custom_field_values WHERE field_id=$fieldId AND scope_id=1",
    'Tactical key' => "SELECT api_key_enc FROM rmm_integrations WHERE id={$vid['tactical_rmm']}",
    'Sophos credentials' => "SELECT api_key_enc FROM rmm_integrations WHERE id={$vid['sophos_central']}",
] as $label => $sql) {
    $ok(str_starts_with((string) $one($sql), 'v3:' . $active . ':'), "AFTER the rewrap the $label is v3: under the active key");
}
$ok((string) $one('SELECT signing_public_key FROM endpoint_agent_settings WHERE id=1') === $pub1, 'the public key (what agents pinned) is untouched');

// ------------------------------------------------------------------ 3. after: everything still opens
$j2 = $offer();
$ok($j2 !== null && $verifies($j2, $pub1), 'AFTER: the device is offered a NEW job over HTTP, signed with the same key (the server opened the v3: signing key through EndpointSecretBox)');
$ok($j2 !== null && $j1 !== null && $j2['job_id'] !== $j1['job_id'], 'and it is a different job');
$sec = Signer::openSecretKey($box, (string) $one('SELECT signing_private_key_enc FROM endpoint_agent_settings WHERE id=1'));
$ok(strlen(base64_decode($sec, true) ?: '') === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES, 'Signer::openSecretKey() opens the v3: signing key directly');
$sigDirect = Signer::sign('proof', $sec);
$ok(Signer::verify('proof', $sigDirect, $pub1), 'and a signature made with it verifies against the original public key');
$meshOpen = $box->decrypt((string) $one('SELECT mesh_login_key_enc FROM endpoint_agent_settings WHERE id=1'));
$ok($meshOpen === $meshHex, 'the Mesh login key opens to the original value');
$cookie = MeshCookie::login($meshOpen, '', MeshCookie::accountName('rivetit-support', 1, 'admin'), time());
$ok($cookie !== null && $cookie !== '', 'and builds a Mesh login cookie');
$withSecrets = $rmm->jobExtras()->withSecrets(['job_id' => $jobUuid, 'params_json' => json_encode(['api_token' => \RivetCore\Rmm\Job\JobExtras::SECRET_MARK])]);
$ok($withSecrets !== null && (json_decode($withSecrets['params_json'], true)['api_token'] ?? '') === 'job-secret-TOKEN-9', 'the job secret parameter resolves to its plaintext');
$dev = ['client_id' => 1, 'location_id' => 0, 'device_id' => $devId];
$resolved = $rmm->customFields()->resolveFor($dev, true, ['vpn_psk']);
$ok(($resolved['vpn_psk']['value'] ?? null) === 'psk-plain-VALUE-1', 'the secret custom field resolves to its plaintext');
$rmm->customFields()->setValue($fieldId, 1, 'psk-new-VALUE-2', 1);
$ok(str_starts_with((string) $one("SELECT value_enc FROM rmm_custom_field_values WHERE field_id=$fieldId AND scope_id=1"), 'v3:' . $active . ':') && ($rmm->customFields()->resolveFor($dev, true, ['vpn_psk'])['vpn_psk']['value'] ?? null) === 'psk-new-VALUE-2', 'a NEW secret custom field value is written v3: and reads back');

require_once "$root/includes/rmm_client_factory.php";
$cred = function (object $c, string $prop): string { $p = new ReflectionProperty($c, $prop); $p->setAccessible(true); return (string) $p->getValue($c); };
$ok($cred(getRmmClient($vid['tactical_rmm']), 'api_key') === 'tac-api-KEY', 'Tactical RMM: the API key decrypts');
$ok($cred(getRmmClient($vid['level']), 'api_key') === 'level-api-KEY', 'Level: the API key decrypts');
$a1 = getRmmClient($vid['action1']);
$ok($cred($a1, 'client_id') === 'a1-client' && $cred($a1, 'client_secret') === 'a1-SECRET', 'Action1: the client id and secret decrypt');
$sc = getRmmClient($vid['sophos_central']);
$ok($cred($sc, 'client_id') === 'sc-client' && $cred($sc, 'client_secret') === 'sc-SECRET', 'Sophos Central: the client id and secret decrypt');

// ------------------------------------------------------------------ 4. the web user cannot read the key file: unavailable, never unsafe
[$c, , $jx] = http('GET', '/api/v1/agent_jobs', $devTok);
$ok($c === 200, 'control: the job endpoint answers while the key file is readable');
chmod($kf, 0000);
SettingsCrypto::reset();
$ok(KeyStore::load()->source === 'file-error' && (string) KeyStore::load()->error !== '', 'unreadable key file: the key store says so');
$ok($box->decrypt((string) $one('SELECT signing_private_key_enc FROM endpoint_agent_settings WHERE id=1')) === '', 'the signing key reads as not available (empty), not as an error and not as garbage');
$ok(decryptSetting((string) $one("SELECT api_key_enc FROM rmm_integrations WHERE id={$vid['tactical_rmm']}")) === '', 'and a vendor key reads as empty');
$threwUnavailable = false;
try { getRmmClient($vid['tactical_rmm']); } catch (\RuntimeException $e) { $threwUnavailable = str_contains($e->getMessage(), 'decryptable'); }
$ok($threwUnavailable, 'the vendor client refuses to start with a clear message');
chmod($kf, 0640);
SettingsCrypto::reset();
$ok($box->decrypt((string) $one('SELECT mesh_login_key_enc FROM endpoint_agent_settings WHERE id=1')) === $meshHex, 'with the file readable again everything opens');
$j3 = $offer();
$ok($j3 !== null && $verifies($j3, $pub1), 'and jobs are signed again');

// cleanup
$q("DELETE FROM rmm_integrations WHERE type <> 'rivetit_agent'");
$q("DELETE FROM rmm_job_extra WHERE job_id='$jobUuid'");
$q("DELETE FROM rmm_custom_field_values WHERE field_id=$fieldId");
$q("DELETE FROM rmm_custom_fields WHERE field_id=$fieldId");
$q("UPDATE settings SET config_module_enable_rmm = 0 WHERE company_id = 1");
