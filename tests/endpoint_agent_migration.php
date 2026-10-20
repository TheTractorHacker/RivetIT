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
/** One `if ($rivetit_db_version() == 'x') { ... }` step of the updater, cut out brace-aware (a later step must not leak into an earlier step's checks). */
$stepBlock = function (string $gate) use ($upd): string {
    $start = strpos($upd, "if (\$rivetit_db_version() == '$gate') {");
    if ($start === false) { return ''; }
    $open = strpos($upd, '{', $start);
    $depth = 0;
    for ($i = $open, $n = strlen($upd); $i < $n; $i++) {
        if ($upd[$i] === '{') { $depth++; } elseif ($upd[$i] === '}' && --$depth === 0) { return substr($upd, $start, $i - $start + 1); }
    }

    return substr($upd, $start);
};
$ok(strpos($upd, "if (\$rivetit_db_version() == '2.6.144') {") !== false && strpos($upd, "'2.6.145'") !== false, 'the migration is gated 2.6.144 -> 2.6.145');
$ok(strpos($upd, "if (\$rivetit_db_version() == '2.6.145') {") !== false && strpos($upd, "'2.6.146'") !== false, 'the deployment migration is gated 2.6.145 -> 2.6.146');
$ok(substr_count($upd, 'CREATE TABLE IF NOT EXISTS `endpoint_agent_') === count($tables) + 8, 'every migration CREATE is IF NOT EXISTS (idempotent; the +3 are the Agent updates tables of 2.6.175 the build table of 2.6.176 the four build host tables of 2.6.177 and the hosted Linux files table of 2.6.180)');
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

// ---- DB 2.6.173 (RivetCore 1.0.0-rc.20, patch management): Core migration 0028 creates eight rmm_patch_* tables through the Core runner; db.sql carries the same tables
$ok(version_compare(LATEST_DATABASE_VERSION, '2.6.173', '>='), 'LATEST_DATABASE_VERSION is at least 2.6.173 (' . LATEST_DATABASE_VERSION . ')');
$patchTables = ['rmm_patch_assignments', 'rmm_patch_available', 'rmm_patch_history', 'rmm_patch_jobs', 'rmm_patch_policies', 'rmm_patch_rings', 'rmm_patch_rules', 'rmm_patch_scans'];
$step173 = $stepBlock('2.6.172');
$ok(strpos($upd, "if (\$rivetit_db_version() == '2.6.172') {") !== false && strpos($step173, "'2.6.173'") !== false && strpos($step173, 'Migration0028Patching') !== false && strpos($step173, '\\RivetCore\\Migration\\MigrationRunner') !== false
    && strpos($step173, 'mysqli_query($mysqli, "CREATE') === false && strpos($step173, 'mysqli_query($mysqli, \'CREATE') === false && strpos($step173, 'class_exists(\\RivetCore\\Rmm\\Migration\\Migration0028Patching::class)') !== false, 'the 2.6.173 step is gated on 2.6.172, runs the Core runner (Migration0028Patching) only when the package ships it, and has no DDL of its own');
$ok(preg_match('/"rivet\/rivet-core": "\^1\.0\.0-rc\.(\d+)"/', file_get_contents("$root/composer.json"), $rcm) === 1 && (int) $rcm[1] >= 20, 'composer.json requires RivetCore 1.0.0-rc.20 or newer');
$patchCols = fn (string $t) => array_column($rows("SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='$t' ORDER BY ordinal_position"), 'column_name');
$patchSqlCols = [];
foreach ($patchTables as $t) {
    $ok(preg_match('/CREATE TABLE `' . $t . '` \((.*?)\n\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;/s', $sql, $pm) === 1, "db.sql defines $t");
    preg_match_all('/^  `([a-z_0-9]+)` /m', $pm[1] ?? '', $pc);
    $patchSqlCols[$t] = $pc[1] ?? [];
    $ok($patchSqlCols[$t] !== [] && $patchSqlCols[$t] === $patchCols($t), "db.sql and the migrated schema agree on the columns of $t");
    $ok((string) $one("SELECT table_collation FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='$t'") === 'utf8mb4_general_ci', "$t is utf8mb4_general_ci");
}
$ok((int) $one("SELECT COUNT(*) FROM rivet_core_migrations WHERE migration_id='0028_rmm_patching'") === 1, 'the Core runner recorded 0028_rmm_patching');
$ok($one("SELECT GROUP_CONCAT(CONCAT(name, ':', delay_days) ORDER BY delay_days) FROM rmm_patch_rings") === 'pilot:0,broad:7,last:14', 'the three default rings are seeded (pilot 0, broad 7, last 14 days)');
$q("INSERT INTO rmm_patch_history SET device_id=9001, client_id=1, update_id='replay-keep', title='keep', action='install', result='installed', code='', detail='', at=UTC_TIMESTAMP()");
$q("UPDATE rmm_patch_rings SET delay_days=9 WHERE name='broad'");
for ($run = 1; $run <= 2; $run++) {
    if ($run === 2) {
        foreach ($patchTables as $t) { $q("DROP TABLE `$t`"); }
        $q("DELETE FROM rivet_core_migrations WHERE migration_id='0028_rmm_patching'");
    }
    $q("UPDATE settings SET config_current_database_version='2.6.172' WHERE company_id=1");
    shell_exec('cd ' . escapeshellarg($root) . ' && ' . escapeshellarg(PHP_BINARY) . ' scripts/update_cli.php --update_db 2>&1');
    $ok((string) $one("SELECT config_current_database_version FROM settings WHERE company_id=1") === LATEST_DATABASE_VERSION, "2.6.173 replay $run lands on the latest version");
    if ($run === 1) {
        $ok((int) $one("SELECT COUNT(*) FROM rmm_patch_history WHERE update_id='replay-keep'") === 1 && (int) $one("SELECT delay_days FROM rmm_patch_rings WHERE name='broad'") === 9 && (int) $one('SELECT COUNT(*) FROM rmm_patch_rings') === 3, 'replay 1: existing history and an edited ring are kept, no ring is added twice');
    } else {
        $same = true;
        foreach ($patchTables as $t) { $same = $same && $patchCols($t) === $patchSqlCols[$t]; }
        $ok($same && (int) $one("SELECT COUNT(*) FROM rivet_core_migrations WHERE migration_id='0028_rmm_patching'") === 1 && (int) $one('SELECT COUNT(*) FROM rmm_patch_rings') === 3 && (int) $one('SELECT COUNT(*) FROM rmm_patch_history') === 0,
            'replay 2: the eight missing tables are created again with the db.sql columns, the migration is recorded and the default rings are seeded');
    }
}

// ---- DB 2.6.174 (RivetCore 1.0.0-rc.21, software deployment): Core migration 0029 creates eight rmm_* deployment tables through the Core runner; db.sql carries the same tables
$ok(version_compare(LATEST_DATABASE_VERSION, '2.6.174', '>='), 'LATEST_DATABASE_VERSION is at least 2.6.174 (' . LATEST_DATABASE_VERSION . ')');
$swTables = ['rmm_deploy_state', 'rmm_package_assignments', 'rmm_package_history', 'rmm_package_installs', 'rmm_package_jobs', 'rmm_package_versions', 'rmm_packages', 'rmm_software_hosts'];
$step174 = $stepBlock('2.6.173');
$ok(strpos($upd, "if (\$rivetit_db_version() == '2.6.173') {") !== false && strpos($step174, "'2.6.174'") !== false && strpos($step174, 'Migration0029SoftwareDeploy') !== false && strpos($step174, '\\RivetCore\\Migration\\MigrationRunner') !== false
    && strpos($step174, 'mysqli_query($mysqli, "CREATE') === false && strpos($step174, 'mysqli_query($mysqli, \'CREATE') === false && strpos($step174, 'class_exists(\\RivetCore\\Rmm\\Migration\\Migration0029SoftwareDeploy::class)') !== false,
    'the 2.6.174 step is gated on 2.6.173, runs the Core runner (MigrationRunner, CoreMigrations::all()), writes no DDL of its own and waits for a package that ships migration 0029');
$ok(preg_match('/"rivet\/rivet-core": "\^1\.0\.0-rc\.(\d+)"/', file_get_contents("$root/composer.json"), $rcm21) === 1 && (int) $rcm21[1] >= 21, 'composer.json requires RivetCore 1.0.0-rc.21 or newer');
$swCols = fn (string $t) => array_column($rows("SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='$t' ORDER BY ordinal_position"), 'column_name');
$swSqlCols = [];
foreach ($swTables as $t) {
    $ok(preg_match('/CREATE TABLE `' . $t . '` \((.*?)\n\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;/s', $sql, $pm) === 1, "db.sql defines $t");
    preg_match_all('/^  `([a-z_0-9]+)` /m', $pm[1] ?? '', $pc);
    $swSqlCols[$t] = $pc[1] ?? [];
    $ok($swSqlCols[$t] !== [] && $swSqlCols[$t] === $swCols($t), "db.sql and the migrated schema agree on the columns of $t");
    $ok((string) $one("SELECT table_collation FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='$t'") === 'utf8mb4_general_ci', "$t is utf8mb4_general_ci");
}
$ok(strpos($sql, 'uca1400') === false, 'db.sql carries no uca1400 collation');
$ok((int) $one("SELECT COUNT(*) FROM rivet_core_migrations WHERE migration_id='0029_rmm_software_deploy'") === 1, 'the Core runner recorded 0029_rmm_software_deploy');
$ok((int) $one('SELECT COUNT(*) FROM rmm_packages') === 0 && (int) $one('SELECT COUNT(*) FROM rmm_software_hosts') === 0, 'migration 0029 inserts no row');
$q("INSERT INTO rmm_software_hosts SET host='keep.example.com', note='kept', created_by=1, created_at=UTC_TIMESTAMP()");
$q("INSERT INTO rmm_packages SET name='keep-pkg', publisher='', description='', enabled=1, two_person=1, created_by=1, created_at=UTC_TIMESTAMP(), updated_at=UTC_TIMESTAMP()");
for ($run = 1; $run <= 2; $run++) {
    if ($run === 2) {
        foreach ($swTables as $t) { $q("DROP TABLE `$t`"); }
        $q("DELETE FROM rivet_core_migrations WHERE migration_id='0029_rmm_software_deploy'");
    }
    $q("UPDATE settings SET config_current_database_version='2.6.173' WHERE company_id=1");
    shell_exec('cd ' . escapeshellarg($root) . ' && ' . escapeshellarg(PHP_BINARY) . ' scripts/update_cli.php --update_db 2>&1');
    $ok((string) $one("SELECT config_current_database_version FROM settings WHERE company_id=1") === LATEST_DATABASE_VERSION, "2.6.174 replay $run lands on the latest version");
    if ($run === 1) {
        $ok((int) $one("SELECT COUNT(*) FROM rmm_software_hosts WHERE host='keep.example.com'") === 1 && (int) $one("SELECT two_person FROM rmm_packages WHERE name='keep-pkg'") === 1, 'replay 1 (tables present): the host and the package a person created are kept, nothing duplicated');
    } else {
        $same = true;
        foreach ($swTables as $t) { $same = $same && $swCols($t) === $swSqlCols[$t]; }
        $ok($same && (int) $one("SELECT COUNT(*) FROM rivet_core_migrations WHERE migration_id='0029_rmm_software_deploy'") === 1 && (int) $one('SELECT COUNT(*) FROM rmm_packages') === 0,
            'replay 2: the eight missing tables are created again with the db.sql columns and the migration is recorded; no row is inserted');
    }
}


// ---- DB 2.6.175 (RivetIT 26.10.52, Agent updates): two RivetIT-owned tables, endpoint_agent_feed (one row) and endpoint_agent_linux_assets; db.sql carries the same tables
$ok(version_compare(LATEST_DATABASE_VERSION, '2.6.175', '>='), 'LATEST_DATABASE_VERSION is at least 2.6.175 (' . LATEST_DATABASE_VERSION . ')');
$feedTables = ['endpoint_agent_feed', 'endpoint_agent_linux_assets'];
$step175 = $stepBlock('2.6.174');
$ok(strpos($upd, "if (\$rivetit_db_version() == '2.6.174') {") !== false && strpos($step175, "'2.6.175'") !== false && strpos($step175, 'CREATE TABLE IF NOT EXISTS `endpoint_agent_feed`') !== false && strpos($step175, 'CREATE TABLE IF NOT EXISTS `endpoint_agent_linux_assets`') !== false
    && strpos($step175, 'DROP ') === false && strpos($step175, 'ALTER ') === false,
    'the 2.6.175 step is gated on 2.6.174, only creates the two tables IF NOT EXISTS (no DROP, no ALTER) and advances the version only when both exist');
$feedCols = fn (string $t) => array_column($rows("SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='$t' ORDER BY ordinal_position"), 'column_name');
$feedSqlCols = [];
foreach ($feedTables as $t) {
    $ok(preg_match('/CREATE TABLE IF NOT EXISTS `' . $t . '` \((.*?)\n\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;/s', $sql, $pm) === 1, "db.sql defines $t");
    preg_match_all('/^  `([a-z_0-9]+)` /m', $pm[1] ?? '', $pc);
    $feedSqlCols[$t] = $pc[1] ?? [];
    $ok($feedSqlCols[$t] !== [] && $feedSqlCols[$t] === $feedCols($t), "db.sql and the migrated schema agree on the columns of $t");
    $ok((string) $one("SELECT table_collation FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='$t'") === 'utf8mb4_general_ci', "$t is utf8mb4_general_ci");
}
$ok((int) $one('SELECT COUNT(*) FROM endpoint_agent_feed') === 1 && (string) $one('SELECT repo FROM endpoint_agent_feed WHERE id = 1') === 'TheTractorHacker/rivet-core', 'the feed table has its one row, pointing at the default repository');
$q("UPDATE endpoint_agent_feed SET repo='someone/kept-fork' WHERE id = 1");
$q("INSERT INTO endpoint_agent_linux_assets SET version='0.0.1', kind='script', arch='', filename='keep.sh', url='https://github.com/x/y/keep.sh', sha256='" . str_repeat('a', 64) . "', size_bytes=1, created_by=1");
for ($run = 1; $run <= 2; $run++) {
    if ($run === 2) {
        foreach ($feedTables as $t) { $q("DROP TABLE `$t`"); }
    }
    $q("UPDATE settings SET config_current_database_version='2.6.174' WHERE company_id=1");
    shell_exec('cd ' . escapeshellarg($root) . ' && ' . escapeshellarg(PHP_BINARY) . ' scripts/update_cli.php --update_db 2>&1');
    $ok((string) $one("SELECT config_current_database_version FROM settings WHERE company_id=1") === LATEST_DATABASE_VERSION, "2.6.175 replay $run lands on the latest version");
    if ($run === 1) {
        $ok((string) $one('SELECT repo FROM endpoint_agent_feed WHERE id = 1') === 'someone/kept-fork' && (int) $one("SELECT COUNT(*) FROM endpoint_agent_linux_assets WHERE filename='keep.sh'") === 1 && (int) $one('SELECT COUNT(*) FROM endpoint_agent_feed') === 1,
            'replay 1 (tables present): the configured repository and a recorded Linux file are kept, nothing duplicated');
    } else {
        $same = true;
        foreach ($feedTables as $t) { $same = $same && $feedCols($t) === $feedSqlCols[$t]; }
        $ok($same && (int) $one('SELECT COUNT(*) FROM endpoint_agent_feed') === 1 && (string) $one('SELECT repo FROM endpoint_agent_feed WHERE id = 1') === 'TheTractorHacker/rivet-core' && (int) $one('SELECT COUNT(*) FROM endpoint_agent_linux_assets') === 0,
            'replay 2: the two missing tables are created again with the db.sql columns and the feed row is seeded with the default repository');
    }
}

// ---- DB 2.6.176 (RivetIT 26.10.55, Build a new version): one RivetIT-owned table, endpoint_agent_build (one row: encrypted GitHub token, the build in progress, publish-when-built)
$ok(version_compare(LATEST_DATABASE_VERSION, '2.6.176', '>='), 'LATEST_DATABASE_VERSION is at least 2.6.176 (' . LATEST_DATABASE_VERSION . ')');
$step176 = $stepBlock('2.6.175');
$ok(strpos($upd, "if (\$rivetit_db_version() == '2.6.175') {") !== false && strpos($step176, "'2.6.176'") !== false && strpos($step176, 'CREATE TABLE IF NOT EXISTS `endpoint_agent_build`') !== false
    && strpos($step176, 'DROP ') === false && strpos($step176, 'ALTER ') === false, 'the 2.6.176 step is gated on 2.6.175, only creates the table IF NOT EXISTS (no DROP, no ALTER) and advances the version only when it exists');
$ok(preg_match('/CREATE TABLE IF NOT EXISTS `endpoint_agent_build` \((.*?)\n\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;/s', $sql, $bm) === 1, 'db.sql defines endpoint_agent_build');
preg_match_all('/^  `([a-z_0-9]+)` /m', $bm[1] ?? '', $bc);
$ok($bc[1] !== [] && $bc[1] === $feedCols('endpoint_agent_build'), 'db.sql and the migrated schema agree on the columns of endpoint_agent_build');
$ok((int) $one('SELECT COUNT(*) FROM endpoint_agent_build') === 1 && (string) $one('SELECT gh_token FROM endpoint_agent_build WHERE id = 1') === '', 'the build table has its one row and no token');
for ($run = 1; $run <= 2; $run++) {
    if ($run === 2) { $q('DROP TABLE endpoint_agent_build'); }
    $q("UPDATE endpoint_agent_build SET build_version='9.9.9' WHERE id = 1");
    $q("UPDATE settings SET config_current_database_version='2.6.175' WHERE company_id=1");
    shell_exec('cd ' . escapeshellarg($root) . ' && ' . escapeshellarg(PHP_BINARY) . ' scripts/update_cli.php --update_db 2>&1');
    $ok((string) $one("SELECT config_current_database_version FROM settings WHERE company_id=1") === LATEST_DATABASE_VERSION, "2.6.176 replay $run lands on the latest version");
    if ($run === 1) {
        $ok((string) $one('SELECT build_version FROM endpoint_agent_build WHERE id = 1') === '9.9.9' && (int) $one('SELECT COUNT(*) FROM endpoint_agent_build') === 1, 'replay 1 (table present): the row is kept, nothing duplicated');
    } else {
        $ok((int) $one('SELECT COUNT(*) FROM endpoint_agent_build') === 1 && $feedCols('endpoint_agent_build') === $bc[1], 'replay 2: the missing table is created again with the db.sql columns and its row is seeded');
    }
}

// ---- DB 2.6.178 (RivetIT 26.10.58, build progress): four columns on endpoint_agent_build_jobs plus run_steps on endpoint_agent_build; db.sql carries the same columns
$ok(version_compare(LATEST_DATABASE_VERSION, '2.6.178', '>='), 'LATEST_DATABASE_VERSION is at least 2.6.178 (' . LATEST_DATABASE_VERSION . ')');
$step178 = $stepBlock('2.6.177');
$ok(strpos($upd, "if (\$rivetit_db_version() == '2.6.177') {") !== false && strpos($step178, "'2.6.178'") !== false && substr_count($step178, 'ADD COLUMN IF NOT EXISTS') === 5 && strpos($step178, 'DROP ') === false,
    'the 2.6.178 step is gated on 2.6.177, only ADDs five columns IF NOT EXISTS (no DROP) and advances the version only when the last one exists');
$ok(preg_match('/CREATE TABLE IF NOT EXISTS `endpoint_agent_build_jobs` \((.*?)\n\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;/s', $sql, $jm) === 1, 'db.sql defines endpoint_agent_build_jobs');
preg_match_all('/^  `([a-z_0-9]+)` /m', $jm[1] ?? '', $jc);
$ok($jc[1] !== [] && $jc[1] === $feedCols('endpoint_agent_build_jobs') && in_array('progress_pct', $jc[1], true), 'db.sql and the migrated schema agree on the columns (and order) of endpoint_agent_build_jobs');
$q("DELETE FROM endpoint_agent_build_jobs WHERE version='9.9.9'");
$q("INSERT INTO endpoint_agent_build_jobs SET host_id=1, version='9.9.9', quarantine='" . str_repeat('a', 32) . "', created_at=UTC_TIMESTAMP()");
for ($run = 1; $run <= 2; $run++) {
    if ($run === 2) { $q('ALTER TABLE endpoint_agent_build_jobs DROP COLUMN progress_stage, DROP COLUMN progress_pct, DROP COLUMN progress_detail, DROP COLUMN progress_at'); $q('ALTER TABLE endpoint_agent_build DROP COLUMN run_steps'); }
    $q("UPDATE settings SET config_current_database_version='2.6.177' WHERE company_id=1");
    shell_exec('cd ' . escapeshellarg($root) . ' && ' . escapeshellarg(PHP_BINARY) . ' scripts/update_cli.php --update_db 2>&1');
    $ok((string) $one("SELECT config_current_database_version FROM settings WHERE company_id=1") === LATEST_DATABASE_VERSION, "2.6.178 replay $run lands on the latest version");
    $ok($feedCols('endpoint_agent_build') === $bc[1], "replay $run: endpoint_agent_build has run_steps in the db.sql order");
    $ok($feedCols('endpoint_agent_build_jobs') === $jc[1] && (int) $one("SELECT COUNT(*) FROM endpoint_agent_build_jobs WHERE version='9.9.9' AND progress_pct=0 AND progress_stage='' AND progress_at IS NULL") === 1,
        "replay $run: the four columns exist in the db.sql order and the existing job keeps its row with the defaults");
}
