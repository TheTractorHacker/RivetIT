<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (pentest F-02)
/*
 * DB 2.6.157 (platform wave 2): the migration block is gated on 2.6.156, ends at 2.6.157, is idempotent, and a fresh db.sql import has exactly the
 * schema the migration produces. Builds a throwaway "<scratch>_mig" database next to the scratch one and drops it afterwards.
 *
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=x_scratch RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/platform_migration.php
 */
if (getenv('RIVETIT_TEST_DB') !== '1') { fwrite(STDERR, "set RIVETIT_TEST_DB=1\n"); exit(2); }
if (!preg_match('/scratch/i', (string) getenv('RIVETIT_TEST_DB_NAME'))) { fwrite(STDERR, "Refusing: DB name must contain 'scratch'\n"); exit(2); }
$root = dirname(__DIR__);
mysqli_report(MYSQLI_REPORT_OFF);
$fresh = (string) getenv('RIVETIT_TEST_DB_NAME');
$mig = $fresh . '_mig';
$frs = $fresh . '_fresh';
$admin = new mysqli('localhost', getenv('RIVETIT_TEST_DB_USER'), getenv('RIVETIT_TEST_DB_PASS'));
if ($admin->connect_errno) { fwrite(STDERR, "connect failed\n"); exit(2); }
$fails = 0; $n = 0;
$ok = function (bool $c, string $l) use (&$fails, &$n) { $n++; echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) { $fails++; } };

$import = function (mysqli $c, string $sqlFile): bool {
    $c->query("SET SESSION sql_mode=''");
    if (!$c->multi_query((string) file_get_contents($sqlFile))) { return false; }
    do { if ($r = $c->store_result()) { $r->free(); } } while ($c->more_results() && $c->next_result());
    return $c->errno === 0;
};
$fingerprint = function (mysqli $c, string $db): array {
    $e = $c->real_escape_string($db);
    $cols = []; $idx = [];
    $r = $c->query("SELECT table_name, column_name, column_type, is_nullable, column_default, ordinal_position FROM information_schema.columns WHERE table_schema = '$e' ORDER BY table_name, ordinal_position");
    while ($x = $r->fetch_row()) { $cols[] = implode('|', array_map('strval', $x)); }
    $r = $c->query("SELECT table_name, index_name, seq_in_index, column_name, non_unique FROM information_schema.statistics WHERE table_schema = '$e' ORDER BY table_name, index_name, seq_in_index");
    while ($x = $r->fetch_row()) { $idx[] = implode('|', array_map('strval', $x)); }
    $r = $c->query("SELECT table_name, table_collation FROM information_schema.tables WHERE table_schema = '$e' AND table_type = 'BASE TABLE' ORDER BY table_name");
    $tbl = [];
    while ($x = $r->fetch_row()) { $tbl[] = implode('|', $x); }
    return ['cols' => $cols, 'idx' => $idx, 'tables' => $tbl];
};

try {
    foreach ([$mig, $frs] as $d) { $admin->query("DROP DATABASE IF EXISTS `$d`"); $admin->query("CREATE DATABASE `$d` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"); }
    $f = new mysqli('localhost', getenv('RIVETIT_TEST_DB_USER'), getenv('RIVETIT_TEST_DB_PASS'), $frs);
    $m = new mysqli('localhost', getenv('RIVETIT_TEST_DB_USER'), getenv('RIVETIT_TEST_DB_PASS'), $mig);
    $ok($import($f, "$root/db.sql"), 'db.sql imports into a fresh database');
    $import($m, "$root/db.sql");
    $ok(!str_contains((string) file_get_contents("$root/db.sql"), 'uca1400'), 'db.sql carries no uca1400 collation');

    // Put the database back to its 2.6.156 shape: drop what the step adds, keep a primary vendor on an asset and a software row to back-fill.
    foreach (['entity_links', 'asset_vendors', 'software_vendors', 'platform_settings', 'asset_sync_state', 'integration_client_map', 'asset_retire_queue'] as $t) { $m->query("DROP TABLE `$t`"); }
    $m->query("ALTER TABLE assets DROP COLUMN asset_cpu, DROP COLUMN asset_ram");
    $m->query("ALTER TABLE documents DROP KEY idx_documents_review, DROP COLUMN document_review_at, DROP COLUMN document_review_reminded_at");
    $m->query("ALTER TABLE audit_events DROP COLUMN prev_hash, DROP COLUMN row_hash");
    $m->query("INSERT INTO settings (company_id, config_current_database_version) VALUES (1, '2.6.156')");
    $m->query("INSERT INTO assets (asset_id, asset_type, asset_name, asset_make, asset_vendor_id, asset_client_id) VALUES (7001, 'Laptop', 'mig-a', 'x', 55, 1), (7002, 'Laptop', 'mig-b', 'x', 0, 1)");
    $m->query("INSERT INTO software (software_id, software_name, software_vendor_id, software_client_id) VALUES (7101, 'mig-s', 56, 1)");

    $src = file_get_contents("$root/admin/database_updates.php");
    $start = strpos($src, "if (\$rivetit_db_version() == '2.6.156')");
    $ok($start !== false, 'a migration block gated on 2.6.156 exists');
    $depth = 0; $end = $start;
    for ($i = strpos($src, '{', $start), $len = strlen($src); $i < $len; $i++) {
        if ($src[$i] === '{') { $depth++; } elseif ($src[$i] === '}' && --$depth === 0) { $end = $i + 1; break; }
    }
    $block = substr($src, $start, $end - $start);
    $ok(str_contains($block, "'2.6.157'") && !str_contains($block, "'2.6.158'"), 'the block ends at 2.6.157 (one step)');
    $mysqli = $m;
    $rivetit_db_version = static function () use ($mysqli): string { return (string) mysqli_fetch_row(mysqli_query($mysqli, "SELECT config_current_database_version FROM settings WHERE company_id=1"))[0]; };
    eval($block);
    $ok($rivetit_db_version() === '2.6.157', 'migration advanced the version to 2.6.157');
    $ok(preg_match('/"([\d.]+)"/', (string) file_get_contents("$root/includes/database_version.php"), $lv) && version_compare($lv[1], '2.6.157', '>='), 'LATEST_DATABASE_VERSION is at least 2.6.157');

    $a = $fingerprint($m, $mig); $b = $fingerprint($f, $frs);
    $ok($a['tables'] === $b['tables'], 'same tables and table collations as a fresh import');
    $ok($a['cols'] === $b['cols'], 'same columns (type, nullability, default, order) as a fresh import' . ($a['cols'] === $b['cols'] ? '' : ' diff: ' . json_encode(array_values(array_diff($a['cols'], $b['cols']))) . ' / ' . json_encode(array_values(array_diff($b['cols'], $a['cols'])))));
    $ok($a['idx'] === $b['idx'], 'same indexes as a fresh import' . ($a['idx'] === $b['idx'] ? '' : ' diff: ' . json_encode(array_values(array_diff($a['idx'], $b['idx']))) . ' / ' . json_encode(array_values(array_diff($b['idx'], $a['idx'])))));
    $mine = ['entity_links', 'asset_vendors', 'software_vendors', 'platform_settings', 'asset_sync_state', 'integration_client_map', 'asset_retire_queue'];
    $ok(count(array_filter($a['tables'], fn ($t) => in_array(explode('|', $t)[0], $mine, true) && explode('|', $t)[1] === 'utf8mb4_general_ci')) === count($mine), 'the new tables are utf8mb4_general_ci (not the server default)');

    $ok((int) $m->query("SELECT COUNT(*) FROM asset_vendors WHERE asset_id = 7001 AND vendor_id = 55 AND vendor_role = 'support'")->fetch_row()[0] === 1
        && (int) $m->query("SELECT COUNT(*) FROM asset_vendors WHERE asset_id = 7002")->fetch_row()[0] === 0, 'the primary asset vendor is back-filled as role support (assets without one get nothing)');
    $ok((int) $m->query("SELECT COUNT(*) FROM software_vendors WHERE software_id = 7101 AND vendor_id = 56")->fetch_row()[0] === 1, 'the primary software vendor is back-filled');
    $ok((int) $m->query("SELECT asset_vendor_id FROM assets WHERE asset_id = 7001")->fetch_row()[0] === 55, 'asset_vendor_id (the primary) is untouched');

    $count = fn () => (int) $m->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE()")->fetch_row()[0];
    $before = $count();
    $m->query("UPDATE settings SET config_current_database_version = '2.6.156'");
    eval($block);
    $ok($rivetit_db_version() === '2.6.157' && $count() === $before, 'migration is idempotent (second run changes nothing)');
    $ok((int) $m->query("SELECT COUNT(*) FROM asset_vendors")->fetch_row()[0] === 1, 'back-fill is idempotent (no duplicate rows)');

    $ok((int) $m->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'settings'")->fetch_row()[0] === (int) $f->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'settings'")->fetch_row()[0], 'settings table unchanged (no new settings columns; platform_settings holds the new switches)');
} finally {
    foreach ([$mig, $frs] as $d) { $admin->query("DROP DATABASE IF EXISTS `$d`"); }
}
echo "\n" . ($n - $fails) . " passed, $fails failed\n";
exit($fails ? 1 : 0);
