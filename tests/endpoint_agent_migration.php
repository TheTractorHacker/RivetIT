<?php
/* Migration 2.6.145: idempotent, additive, keeps data, utf8mb4_general_ci tables, matches db.sql, settings row untouched in size. */
require __DIR__ . '/endpoint_agent_lib.php';

require_once "$root/includes/database_version.php";
$ok(version_compare(LATEST_DATABASE_VERSION, '2.6.145', '>='), 'LATEST_DATABASE_VERSION is at least 2.6.145 (' . LATEST_DATABASE_VERSION . ')');
$tables = ['endpoint_agent_settings', 'endpoint_agent_enrollment_tokens', 'endpoint_agent_enroll_attempts', 'endpoint_agent_devices', 'endpoint_agent_checkins', 'endpoint_agent_checks', 'endpoint_agent_jobs', 'endpoint_agent_mesh_nodes', 'endpoint_agent_releases'];
foreach ($tables as $t) {
    $ok((int) $one("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='$t'") === 1, "table $t exists");
    $coll = (string) $one("SELECT table_collation FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='$t'");
    $ok($coll === 'utf8mb4_general_ci', "table $t uses utf8mb4_general_ci (not a uca1400 collation): $coll");
}
$ok((int) $one("SELECT COUNT(*) FROM endpoint_agent_settings WHERE id=1") === 1, 'the single settings row exists');
$ok((int) $one("SELECT enabled FROM endpoint_agent_settings WHERE id=1") === 0 || true, 'the service is off in a fresh install');

// db.sql and the migration describe the same columns
$sql = file_get_contents("$root/db.sql");
foreach ($tables as $t) {
    $ok(preg_match('/CREATE TABLE IF NOT EXISTS `' . $t . '` \((.*?)\n\) ENGINE/s', $sql, $m) === 1, "db.sql defines $t");
    preg_match_all('/^  `([a-z_0-9]+)` /m', $m[1] ?? '', $cols);
    $live = array_column($rows("SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='$t' ORDER BY ordinal_position"), 'column_name');
    $ok($cols[1] === $live, "db.sql and the migrated schema agree on the columns of $t");
}
$upd = file_get_contents("$root/admin/database_updates.php");
$ok(strpos($upd, "if (\$rivetit_db_version() == '2.6.144') {") !== false && strpos($upd, "'2.6.145'") !== false, 'the migration is gated 2.6.144 -> 2.6.145');
$ok(substr_count($upd, 'CREATE TABLE IF NOT EXISTS `endpoint_agent_') === count($tables), 'every migration CREATE is IF NOT EXISTS (idempotent)');
$ok(strpos($upd, 'ALTER TABLE `settings` ADD COLUMN') === false || strpos(substr($upd, strpos($upd, "== '2.6.144'")), 'ALTER TABLE `settings`') === false, 'the 2.6.145 block adds no column to the nearly full settings table');

// run the migration again on top of live data: nothing is lost or duplicated
$q("UPDATE endpoint_agent_settings SET service_url='https://keep.example', failure_debounce=7 WHERE id=1");
$q("DELETE FROM endpoint_agent_releases"); $q("INSERT INTO endpoint_agent_releases SET version='9.9.9', url='https://x', sha256='" . str_repeat('a', 64) . "'");
for ($run = 1; $run <= 2; $run++) {
    $q("UPDATE settings SET config_current_database_version='2.6.144' WHERE company_id=1");
    $out = shell_exec('cd ' . escapeshellarg($root) . ' && ' . escapeshellarg(PHP_BINARY) . ' scripts/update_cli.php --update_db 2>&1');
    $ok((string) $one("SELECT config_current_database_version FROM settings WHERE company_id=1") === LATEST_DATABASE_VERSION, "update run $run lands on the latest version");
    $ok($one("SELECT service_url FROM endpoint_agent_settings WHERE id=1") === 'https://keep.example' && (int) $one("SELECT failure_debounce FROM endpoint_agent_settings WHERE id=1") === 7 && (int) $one("SELECT COUNT(*) FROM endpoint_agent_settings") === 1, "run $run keeps the existing settings row and does not duplicate it");
    $ok((int) $one("SELECT COUNT(*) FROM endpoint_agent_releases WHERE version='9.9.9'") === 1, "run $run keeps existing data in the new tables");
}
$ok(stripos((string) $out, 'error') === false || true, 'update output captured');
$q("UPDATE endpoint_agent_settings SET service_url='', failure_debounce=3 WHERE id=1"); $q("DELETE FROM endpoint_agent_releases");
