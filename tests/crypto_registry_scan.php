<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/*
 * Schema scan for the key-rotation registry: EVERY column that is named like an encryptSetting() column (`*_enc`, `*encrypted*`) in the schema
 * (db.sql, and the live scratch schema when a database is available) must be in ITFlow\Crypto\ColumnRegistry, or in the explicit list below of columns
 * that look like one but are not sealed with encryptSetting(). A new secret column that is not registered fails this test, because a key rotation
 * would otherwise leave it on the retired key.
 *   php tests/crypto_registry_scan.php                       (db.sql only)
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=...scratch... ... php tests/crypto_registry_scan.php   (also the live schema)
 */
$root = dirname(__DIR__);
require_once "$root/vendor/autoload.php";
$fails = 0; $passes = 0;
$ok = function (bool $c, string $l) use (&$fails, &$passes) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; $c ? $passes++ : $fails++; };
register_shutdown_function(function () use (&$fails, &$passes) { echo "\n$passes passed, $fails failed\n"; if ($fails) { exit(1); } });

/** table.column => why it is not in the registry */
$notSealedByEncryptSetting = [
    'asset_intune_links.is_encrypted' => 'a boolean (disk encryption state), not a secret',
    'credential_versions.version_previous_username_enc' => 'vault v3 (ITFlow\Crypto\VaultV3)',
    'credential_versions.version_previous_password_enc' => 'vault v3 (ITFlow\Crypto\VaultV3)',
    'shared_items.item_encrypted_credential' => 'vault share, sealed with the share key',
    'shared_items.item_encrypted_username' => 'vault share, sealed with the share key',
    'shared_items.item_encrypted_otp' => 'vault share, sealed with the share key',
    'rmm_job_extra.secret_params_enc' => 'sealed through EndpointSecretBox (registered as a special spec when listed)',
    'rmm_custom_field_values.value_enc' => 'sealed through EndpointSecretBox (registered as a special spec when listed)',
];
$registered = [];
foreach (ITFlow\Crypto\ColumnRegistry::all() as $spec) {
    $r = new ReflectionObject($spec);
    $props = [];
    foreach ($r->getProperties() as $p) { $p->setAccessible(true); $props[$p->getName()] = $p->getValue($spec); }
    $vals = array_values($props);
    $registered[(string) $vals[0] . '.' . (string) $vals[2]] = true;
}
$ok(count($registered) > 40 && isset($registered['manageengine_mdm_integrations.api_key_enc']) && isset($registered['manageengine_mdm_integrations.refresh_token_enc']), 'registry lists the ManageEngine MDM secrets, including the on-premises api_key_enc');

// 1. db.sql: every `*_enc` / `*encrypted*` column of every table
$sql = file_get_contents("$root/db.sql");
$found = [];
foreach (preg_split('/\nCREATE TABLE /', $sql) as $chunk) {
    if (!preg_match('/^(?:IF NOT EXISTS )?`([a-z0-9_]+)`/', $chunk, $t)) { continue; }
    preg_match_all('/^  `([a-z0-9_]+)` /m', $chunk, $cols);
    foreach ($cols[1] as $c) { if (preg_match('/_enc$|encrypted/', $c)) { $found[$t[1] . '.' . $c] = true; } }
}
$missing = array_diff(array_keys($found), array_keys($registered), array_keys($notSealedByEncryptSetting));
$ok($found !== [] && $missing === [], 'db.sql: every *_enc column is registered for rewrap or explicitly excluded' . ($missing ? ' (missing: ' . implode(', ', $missing) . ')' : ''));
// the exclusion list itself must not rot: every excluded column must still exist
$stale = array_diff(array_keys($notSealedByEncryptSetting), array_keys($found));
$ok($stale === [], 'the exclusion list names only columns that exist' . ($stale ? ' (stale: ' . implode(', ', $stale) . ')' : ''));
// a deliberately unregistered column must be caught by the same check (the test can fail)
$probe = array_diff(array_merge(array_keys($found), ['probe_table.new_secret_enc']), array_keys($registered), array_keys($notSealedByEncryptSetting));
$ok(array_values($probe) === ['probe_table.new_secret_enc'], 'self-check: a new unregistered *_enc column would be reported');

// 2. the live (migrated) schema, when a scratch database is given
if (getenv('RIVETIT_TEST_DB') === '1' && preg_match('/scratch/i', (string) getenv('RIVETIT_TEST_DB_NAME'))) {
    mysqli_report(MYSQLI_REPORT_OFF);
    $db = new mysqli('localhost', getenv('RIVETIT_TEST_DB_USER'), getenv('RIVETIT_TEST_DB_PASS'), getenv('RIVETIT_TEST_DB_NAME'));
    if (!$db->connect_errno) {
        $live = [];
        $res = $db->query("SELECT table_name, column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND (column_name LIKE '%\\_enc' OR column_name LIKE '%encrypted%')");
        while ($res && ($r = $res->fetch_row())) { $live[$r[0] . '.' . $r[1]] = true; }
        $missing = array_diff(array_keys($live), array_keys($registered), array_keys($notSealedByEncryptSetting));
        $ok($live !== [] && $missing === [], 'migrated schema: every *_enc column is registered for rewrap or explicitly excluded' . ($missing ? ' (missing: ' . implode(', ', $missing) . ')' : ''));
        $ok(array_diff(array_keys($live), array_keys($found)) === [] && array_diff(array_keys($found), array_keys($live)) === [], 'db.sql and the migrated schema have the same *_enc columns');
    }
}
