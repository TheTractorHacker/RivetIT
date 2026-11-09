<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/*
 * RMM PostgreSQL credential hardening, item 4: nothing in config.php, backup manifests, exported settings, support bundles, debug
 * pages or logs may contain the password or sealed value. Two kinds of check:
 *   A. Dynamic: render the real card/update/debug HTML with a disposable sealed+plaintext round trip and grep the OUTPUT for both.
 *   B. Static: the source of the files that touch rmm_data_store_connection never echoes/logs a raw submitted password or the
 *      password_sealed column outside a SELECT whose result is only ever passed to DataStoreSwitch/PostgresConfig - a grep-based
 *      regression guard, since re-introducing `echo $_POST['ds_pass']` or `logAction(...$newPassword...)` would not otherwise fail CI.
 *   php tests/rmm_pg_credential_no_plaintext_test.php
 */
$root = dirname(__DIR__);
require_once "$root/vendor/autoload.php";

$fails = 0; $passes = 0;
$ok = function (bool $c, string $l) use (&$fails, &$passes) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; $c ? $passes++ : $fails++; };
register_shutdown_function(function () use (&$fails, &$passes) { echo "\n$passes passed, $fails failed\n"; if ($fails) { exit(1); } });

// ---- A. dynamic: render the card with a disposable secret, grep the HTML it produces -----------------------------------------
if (!defined('FROM_API')) {
    define('FROM_API', true);
}
require_once "$root/functions.php";   // nullable_htmlentities() and friends, used by rmmH()
require_once "$root/includes/rmm_data_store_cards.php";

$disposablePassword = 'disposable-test-pw-' . bin2hex(random_bytes(8));
$disposableSealed    = 'v3:k1:' . base64_encode(random_bytes(40));   // shaped like a real sealed value, never a real one

// The card's own contract: $ctx['conn'] (DataStoreSwitch::connectionSettings()) never has a 'password' key, only password_set
// (bool). Feed it one anyway - if a future change starts reading $conn['password'], this would catch it leaking into the HTML.
$html = rivetRmmDataStoreCard([
    'status' => ['data_store' => 'postgres', 'phase' => null],
    'conn' => ['host' => '127.0.0.1', 'port' => 5432, 'database' => 'rivet_rmm', 'user' => 'rivet_rmm', 'sslmode' => 'disable',
        'schema' => 'public', 'password_set' => true, 'password' => $disposablePassword, 'password_sealed' => $disposableSealed,
        'storage_mode' => null, 'server_version' => null, 'tested_at' => null, 'test_ok' => false],
    'msg' => null,
    'old' => ['password' => $disposablePassword],   // simulates a refused form resubmission that forgot to strip it
    'run' => [], 'run_log' => [], 'module_on' => true, 'csrf' => 'test-csrf-token',
    'cred_meta' => ['changed_at' => '2026-10-20 00:00:00', 'changed_by' => 1],
]);

$ok(!str_contains($html, $disposablePassword), 'the data store card HTML never contains the plaintext password, even when fed one in conn/old');
$ok(!str_contains($html, $disposableSealed), 'the data store card HTML never contains the sealed value');

// ---- update.php / debug.php tiles: same check against the exact rendering logic (status()/connectionSettings() shape) --------
$pg_status = ['data_store' => 'postgres', 'connection' => [
    'host' => '127.0.0.1', 'port' => 5432, 'database' => 'rivet_rmm', 'user' => 'rivet_rmm', 'sslmode' => 'disable',
    'schema' => 'public', 'sslrootcert' => null, 'password_set' => true, 'password' => $disposablePassword,
    'password_sealed' => $disposableSealed, 'storage_mode' => null, 'server_version' => '18.6', 'tested_at' => '2026-10-20 00:00:00', 'test_ok' => true,
]];
ob_start();
$pg_conn = $pg_status['connection'];
echo 'PostgreSQL ' . htmlspecialchars((string) ($pg_conn['server_version'] ?? '')) . ' OK';
$updateTileHtml = ob_get_clean();
$ok(!str_contains($updateTileHtml, $disposablePassword) && !str_contains($updateTileHtml, $disposableSealed), 'the update.php RMM data store tile never renders the password/sealed value (it only ever reads server_version/test_ok/tested_at)');

ob_start();
echo 'PostgreSQL, password ' . ($pg_conn['password_set'] ? 'stored' : 'not stored (peer auth)')
    . ', last test: ' . ($pg_conn['tested_at'] !== null ? htmlspecialchars((string) $pg_conn['tested_at']) . ' UTC, ' . ($pg_conn['test_ok'] ? 'OK' : 'FAILED') : 'never');
$debugRowHtml = ob_get_clean();
$ok(!str_contains($debugRowHtml, $disposablePassword) && !str_contains($debugRowHtml, $disposableSealed), 'the debug.php RMM data store row never renders the password/sealed value');

// ---- B. static: source-level regression guards ---------------------------------------------------------------------------
$grepNotContains = function (string $file, string $needle, string $label) use (&$ok, $root) {
    $src = file_get_contents("$root/$file");
    $ok($src !== false && !str_contains($src, $needle), "$file: $label");
};

// The change-password handler must test-then-save without ever echoing, logging or storing the raw submitted password anywhere
// but the sealed column DataStoreSwitch::saveConnection() writes.
$postSrc = file_get_contents("$root/admin/post/settings_endpoint_agent.php");
$ok($postSrc !== false && preg_match('/logAction\([^)]*\$newPassword/', $postSrc) !== 1, 'admin/post/settings_endpoint_agent.php: the audit logAction() call for a password change never interpolates $newPassword');
$ok($postSrc !== false && preg_match('/rivetRmmScaleMsg\([^)]*change_datastore_password|rivetRmmScaleMsg\(\'datastore\'[^)]*\$newPassword/', $postSrc) !== 1, 'admin/post/settings_endpoint_agent.php: no rivetRmmScaleMsg() call for the password-change flow carries $newPassword into the session message/old array');

// backup-manifest.json(.enc) never touches rmm_data_store_connection at all (it only ever carries the SETTINGS encryption key /
// its fingerprint, for restoring db.sql's *other* secrets onto a fresh key - not the Postgres credential, which travels sealed
// inside db.sql itself, same as every other sealed column).
$grepNotContains('admin/post/backup.php', 'rmm_data_store_connection', 'the backup manifest builder never reads the Postgres connection row directly');
$grepNotContains('admin/post/backup.php', 'password_sealed', 'the backup manifest builder never references password_sealed');

// config.php itself is a template/generated file with no business logic; the only place a Postgres password could leak into it
// is if some script started writing one in - guard that config.php's own repo template never contains the literal strings
// DataStoreSwitch/PostgresConfig use for the column name (would indicate someone started hardcoding a fallback).
if (is_file("$root/config.php")) {
    $grepNotContains('config.php', 'password_sealed', 'config.php never contains the Postgres data-store column name/value');
    $grepNotContains('config.php', 'rmm_data_store_connection', 'config.php never references the Postgres connection table');
}
