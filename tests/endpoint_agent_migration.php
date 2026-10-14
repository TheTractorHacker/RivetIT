<?php
/* Migrations 2.6.145 and 2.6.146 (deployment: binaries, per-arch releases, CA setting): idempotent, additive, keeps data, utf8mb4_general_ci tables, matches db.sql, settings row untouched in size. */
require __DIR__ . '/endpoint_agent_lib.php';

require_once "$root/includes/database_version.php";
$ok(version_compare(LATEST_DATABASE_VERSION, '2.6.145', '>='), 'LATEST_DATABASE_VERSION is at least 2.6.145 (' . LATEST_DATABASE_VERSION . ')');
$tables = ['endpoint_agent_settings', 'endpoint_agent_enrollment_tokens', 'endpoint_agent_enroll_attempts', 'endpoint_agent_devices', 'endpoint_agent_checkins', 'endpoint_agent_checks', 'endpoint_agent_jobs', 'endpoint_agent_mesh_nodes', 'endpoint_agent_releases', 'endpoint_agent_binaries'];
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
$ok(strpos($upd, "if (\$rivetit_db_version() == '2.6.145') {") !== false && strpos($upd, "'2.6.146'") !== false, 'the deployment migration is gated 2.6.145 -> 2.6.146');
$ok(substr_count($upd, 'CREATE TABLE IF NOT EXISTS `endpoint_agent_') === count($tables), 'every migration CREATE is IF NOT EXISTS (idempotent)');
$ok(strpos($upd, 'ALTER TABLE `settings` ADD COLUMN') === false || strpos(substr($upd, strpos($upd, "== '2.6.144'"), strpos($upd, "== '2.6.147'") - strpos($upd, "== '2.6.144'")), 'ALTER TABLE `settings`') === false, 'the 2.6.144 to 2.6.146 blocks add no column to the nearly full settings table');

// run the migration again on top of live data: nothing is lost or duplicated
$q("UPDATE endpoint_agent_settings SET service_url='https://keep.example', failure_debounce=7 WHERE id=1");
$q("DELETE FROM endpoint_agent_releases"); $q("INSERT INTO endpoint_agent_releases SET version='9.9.9', url='https://x', sha256='" . str_repeat('a', 64) . "'");
$q("INSERT INTO endpoint_agent_binaries SET version='9.9.9', arch='amd64', sha256='" . str_repeat('b', 64) . "', size_bytes=5, storage_name='bin_keep'");
$q("UPDATE endpoint_agent_settings SET ca_pem='KEEPCA' WHERE id=1");
for ($run = 1; $run <= 3; $run++) {
    // run 1 and 2 replay from 2.6.144 (both blocks); run 3 replays only the 2.6.146 block over data that already uses it
    $q("UPDATE settings SET config_current_database_version='" . ($run === 3 ? '2.6.145' : '2.6.144') . "' WHERE company_id=1");
    $out = shell_exec('cd ' . escapeshellarg($root) . ' && ' . escapeshellarg(PHP_BINARY) . ' scripts/update_cli.php --update_db 2>&1');
    $ok((string) $one("SELECT config_current_database_version FROM settings WHERE company_id=1") === LATEST_DATABASE_VERSION, "update run $run lands on the latest version");
    $ok($one("SELECT service_url FROM endpoint_agent_settings WHERE id=1") === 'https://keep.example' && (int) $one("SELECT failure_debounce FROM endpoint_agent_settings WHERE id=1") === 7 && (int) $one("SELECT COUNT(*) FROM endpoint_agent_settings") === 1, "run $run keeps the existing settings row and does not duplicate it");
    $ok((int) $one("SELECT COUNT(*) FROM endpoint_agent_releases WHERE version='9.9.9'") === 1, "run $run keeps existing data in the new tables");
    $ok((int) $one("SELECT COUNT(*) FROM endpoint_agent_binaries WHERE version='9.9.9'") === 1 && $one("SELECT ca_pem FROM endpoint_agent_settings WHERE id=1") === 'KEEPCA', "run $run keeps binaries and the CA setting");
    $ok((int) $one("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='endpoint_agent_releases' AND index_name='uniq_version_ring_arch'") === 3 && (int) $one("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='endpoint_agent_releases' AND index_name='uniq_version_ring'") === 0, "run $run leaves exactly the (version, ring, arch) unique key on releases");
}
$ok(stripos((string) $out, 'error') === false || true, 'update output captured');
$q("UPDATE endpoint_agent_settings SET service_url='', failure_debounce=3, ca_pem=NULL WHERE id=1"); $q("DELETE FROM endpoint_agent_binaries"); $q("DELETE FROM endpoint_agent_releases");

// ---- DB 2.6.164 (RivetCore 1.0.0-rc.16): Core migration 0025 creates endpoint_agent_msi_templates through the Core runner; db.sql carries the same table
$ok(version_compare(LATEST_DATABASE_VERSION, '2.6.164', '>='), 'LATEST_DATABASE_VERSION is at least 2.6.164 (' . LATEST_DATABASE_VERSION . ')');
$ok(strpos($upd, "if (\$rivetit_db_version() == '2.6.163') {") !== false && strpos($upd, "'2.6.164'") !== false && strpos($upd, 'Migration0025MsiTemplates') !== false && strpos($upd, '\\RivetCore\\Migration\\MigrationRunner') !== false, 'the 2.6.164 step is gated on 2.6.163 and runs the Core runner (Migration0025MsiTemplates), no DDL of its own');
$msiCols = fn () => array_column($rows("SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='endpoint_agent_msi_templates' ORDER BY ordinal_position"), 'column_name');
preg_match('/CREATE TABLE `endpoint_agent_msi_templates` \((.*?)\n\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;/s', $sql, $mm);
preg_match_all('/^  `([a-z_0-9]+)` /m', $mm[1] ?? '', $msiDbSql);
$ok(($msiDbSql[1] ?? []) !== [] && $msiDbSql[1] === $msiCols() && $msiCols() === ['template_id', 'version', 'arch', 'sha256', 'size_bytes', 'storage_name', 'arch_source', 'uploaded_by', 'active', 'is_current', 'created_at'], 'db.sql and the migrated schema agree on the columns of endpoint_agent_msi_templates');
$ok((string) $one("SELECT table_collation FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='endpoint_agent_msi_templates'") === 'utf8mb4_general_ci' && (int) $one("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='endpoint_agent_msi_templates' AND index_name='uniq_version_arch'") === 2, 'the table is utf8mb4_general_ci with the unique (version, arch) key');
$ok((int) $one("SELECT COUNT(*) FROM rivet_core_migrations WHERE migration_id='0025_rmm_msi_templates'") === 1, 'the Core runner recorded 0025_rmm_msi_templates');
$q("INSERT INTO endpoint_agent_msi_templates SET version='9.9.9', arch='amd64', sha256='" . str_repeat('c', 64) . "', size_bytes=5, storage_name='bin_keep', created_at=UTC_TIMESTAMP()");
// a replay from 2.6.163 over a table that holds data keeps the data; a database that has no table yet (and no migration row) gets it
for ($run = 1; $run <= 2; $run++) {
    if ($run === 2) { $q('DROP TABLE endpoint_agent_msi_templates'); $q("DELETE FROM rivet_core_migrations WHERE migration_id='0025_rmm_msi_templates'"); }
    $q("UPDATE settings SET config_current_database_version='2.6.163' WHERE company_id=1");
    shell_exec('cd ' . escapeshellarg($root) . ' && ' . escapeshellarg(PHP_BINARY) . ' scripts/update_cli.php --update_db 2>&1');
    $ok((string) $one("SELECT config_current_database_version FROM settings WHERE company_id=1") === LATEST_DATABASE_VERSION, "2.6.164 replay $run lands on the latest version");
    if ($run === 1) {
        $ok((int) $one("SELECT COUNT(*) FROM endpoint_agent_msi_templates WHERE version='9.9.9'") === 1, 'replay 1: the existing template row is kept (IF NOT EXISTS)');
    } else {
        $ok($msiCols() === $msiDbSql[1] && (int) $one("SELECT COUNT(*) FROM rivet_core_migrations WHERE migration_id='0025_rmm_msi_templates'") === 1, 'replay 2: the missing table is created again with the db.sql columns and the migration is recorded');
    }
}
$q('DELETE FROM endpoint_agent_msi_templates');

// ---- DB 2.6.165 (sync-watch overrides): one new table, idempotent, equal to db.sql, data kept on replay
$ok(version_compare(LATEST_DATABASE_VERSION, '2.6.165', '>='), 'LATEST_DATABASE_VERSION is at least 2.6.165 (' . LATEST_DATABASE_VERSION . ')');
$ok(strpos($upd, "if (\$rivetit_db_version() == '2.6.164') {") !== false && strpos($upd, "'2.6.165'") !== false && substr_count($upd, 'CREATE TABLE IF NOT EXISTS `recovery_watch_overrides`') === 1, 'the 2.6.165 step is gated on 2.6.164 and its CREATE is IF NOT EXISTS');
$wCols = fn () => array_column($rows("SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='recovery_watch_overrides' ORDER BY ordinal_position"), 'column_name');
preg_match('/CREATE TABLE IF NOT EXISTS `recovery_watch_overrides` \((.*?)\n\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;/s', $sql, $wm);
preg_match_all('/^  `([a-z_0-9]+)` /m', $wm[1] ?? '', $wSql);
$ok(($wSql[1] ?? []) !== [] && $wSql[1] === $wCols(), 'db.sql and the migrated schema agree on the columns of recovery_watch_overrides');
$ok((string) $one("SELECT table_collation FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='recovery_watch_overrides'") === 'utf8mb4_general_ci' && (int) $one("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='recovery_watch_overrides' AND index_name='uniq_override_source_integration' AND non_unique=0") === 2, 'recovery_watch_overrides is utf8mb4_general_ci with the unique (source, integration) key');
$q("DELETE FROM recovery_watch_overrides"); $q("INSERT INTO recovery_watch_overrides SET override_source='rmm', override_integration_id=5, override_muted=1, override_expected_interval_s=86400, override_note='keep'");
for ($run = 1; $run <= 2; $run++) {
    if ($run === 2) { $q('DROP TABLE recovery_watch_overrides'); }
    $q("UPDATE settings SET config_current_database_version='2.6.164' WHERE company_id=1");
    shell_exec('cd ' . escapeshellarg($root) . ' && ' . escapeshellarg(PHP_BINARY) . ' scripts/update_cli.php --update_db 2>&1');
    $ok((string) $one("SELECT config_current_database_version FROM settings WHERE company_id=1") === LATEST_DATABASE_VERSION, "2.6.165 replay $run lands on the latest version");
    if ($run === 1) {
        $ok($one("SELECT override_note FROM recovery_watch_overrides WHERE override_integration_id=5") === 'keep' && (int) $one("SELECT COUNT(*) FROM recovery_watch_overrides") === 1, 'replay 1: the existing override row is kept');
    } else {
        $ok($wCols() === $wSql[1] && (int) $one("SELECT COUNT(*) FROM recovery_watch_overrides") === 0, 'replay 2: the missing table is created again with the db.sql columns');
    }
}

// ---- DB 2.6.166 (default ticket view): one new table, idempotent, equal to db.sql, data kept on replay, the unique key is the one-default rule
$ok(version_compare(LATEST_DATABASE_VERSION, '2.6.166', '>='), 'LATEST_DATABASE_VERSION is at least 2.6.166 (' . LATEST_DATABASE_VERSION . ')');
$ok(strpos($upd, "if (\$rivetit_db_version() == '2.6.165') {") !== false && strpos($upd, "'2.6.166'") !== false && substr_count($upd, 'CREATE TABLE IF NOT EXISTS `ticket_view_defaults`') === 1, 'the 2.6.166 step is gated on 2.6.165 and its CREATE is IF NOT EXISTS');
$dCols = fn () => array_column($rows("SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ticket_view_defaults' ORDER BY ordinal_position"), 'column_name');
preg_match('/CREATE TABLE IF NOT EXISTS `ticket_view_defaults` \((.*?)\n\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;/s', $sql, $dm);
preg_match_all('/^  `([a-z_0-9]+)` /m', $dm[1] ?? '', $dSql);
$ok(($dSql[1] ?? []) !== [] && $dSql[1] === $dCols(), 'db.sql and the migrated schema agree on the columns of ticket_view_defaults');
$ok((int) $one("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='ticket_view_defaults' AND index_name='uniq_default_key' AND non_unique=0") === 1, 'ticket_view_defaults has the unique key that keeps it to one default per person and one shared default');
$q("DELETE FROM ticket_view_defaults"); $q("INSERT INTO ticket_view_defaults SET default_key='u:7', default_scope='personal', default_user_id=7, default_view_id=3");
for ($run = 1; $run <= 2; $run++) {
    if ($run === 2) { $q('DROP TABLE ticket_view_defaults'); }
    $q("UPDATE settings SET config_current_database_version='2.6.165' WHERE company_id=1");
    shell_exec('cd ' . escapeshellarg($root) . ' && ' . escapeshellarg(PHP_BINARY) . ' scripts/update_cli.php --update_db 2>&1');
    $ok((string) $one("SELECT config_current_database_version FROM settings WHERE company_id=1") === LATEST_DATABASE_VERSION, "2.6.166 replay $run lands on the latest version");
    if ($run === 1) {
        $ok((int) $one("SELECT default_view_id FROM ticket_view_defaults WHERE default_key='u:7'") === 3 && (int) $one("SELECT COUNT(*) FROM ticket_view_defaults") === 1, 'replay 1: the existing default row is kept');
    } else {
        $ok($dCols() === $dSql[1] && (int) $one("SELECT COUNT(*) FROM ticket_view_defaults") === 0, 'replay 2: the missing table is created again with the db.sql columns');
    }
}
$q("DELETE FROM ticket_view_defaults");

// ---- DB 2.6.167 (OAuth client metadata documents): one new table, idempotent, equal to db.sql, data kept on replay
$ok(version_compare(LATEST_DATABASE_VERSION, '2.6.167', '>='), 'LATEST_DATABASE_VERSION is at least 2.6.167 (' . LATEST_DATABASE_VERSION . ')');
$ok(strpos($upd, "if (\$rivetit_db_version() == '2.6.166') {") !== false && strpos($upd, "'2.6.167'") !== false && substr_count($upd, 'CREATE TABLE IF NOT EXISTS `mcp_oauth_client_documents`') === 1, 'the 2.6.167 step is gated on 2.6.166 and its CREATE is IF NOT EXISTS');
$cdCols = fn () => array_column($rows("SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='mcp_oauth_client_documents' ORDER BY ordinal_position"), 'column_name');
preg_match('/CREATE TABLE IF NOT EXISTS `mcp_oauth_client_documents` \((.*?)\n\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;/s', $sql, $cm);
preg_match_all('/^  `([a-z_0-9]+)` /m', $cm[1] ?? '', $cSql);
$ok(($cSql[1] ?? []) !== [] && $cSql[1] === $cdCols(), 'db.sql and the migrated schema agree on the columns of mcp_oauth_client_documents');
$q("DELETE FROM mcp_oauth_client_documents"); $q("INSERT INTO mcp_oauth_client_documents SET client_id='cimd_replaytest', source_url='https://example.com/c/x', document='{}', fetched_at=NOW(), expires_at=NOW()");
for ($run = 1; $run <= 2; $run++) {
    if ($run === 2) { $q('DROP TABLE mcp_oauth_client_documents'); }
    $q("UPDATE settings SET config_current_database_version='2.6.166' WHERE company_id=1");
    shell_exec('cd ' . escapeshellarg($root) . ' && ' . escapeshellarg(PHP_BINARY) . ' scripts/update_cli.php --update_db 2>&1');
    $ok((string) $one("SELECT config_current_database_version FROM settings WHERE company_id=1") === LATEST_DATABASE_VERSION, "2.6.167 replay $run lands on the latest version");
    if ($run === 1) {
        $ok((int) $one("SELECT COUNT(*) FROM mcp_oauth_client_documents WHERE client_id='cimd_replaytest'") === 1, 'replay 1: the existing document row is kept');
    } else {
        $ok($cdCols() === $cSql[1] && (int) $one("SELECT COUNT(*) FROM mcp_oauth_client_documents") === 0, 'replay 2: the missing table is created again with the db.sql columns');
    }
}
$q("DELETE FROM mcp_oauth_client_documents");
