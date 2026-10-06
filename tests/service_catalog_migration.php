<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (pentest F-02)
/*
 * Migration 2.6.133 (service catalog approvals + request forms): applies from 2.6.132, is idempotent (second run changes
 * nothing and errors on nothing), and leaves exactly the schema db.sql creates for a fresh install.
 * Scratch database holding this app's schema (import db.sql); it never reads config.php:
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=...scratch... RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/service_catalog_migration.php
 */
if (getenv('RIVETIT_TEST_DB') !== '1') exit(2);
if (!preg_match('/scratch|test/i', (string) getenv('RIVETIT_TEST_DB_NAME'))) { fwrite(STDERR, "Refusing: DB name must contain scratch/test\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETIT_TEST_DB_USER'), getenv('RIVETIT_TEST_DB_PASS'), getenv('RIVETIT_TEST_DB_NAME'));
if ($mysqli->connect_errno) { fwrite(STDERR, "connect failed\n"); exit(2); }
$q = fn(string $sql) => mysqli_query($mysqli, $sql);
$fails = 0; $ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };

$tables = ['service_catalog_fields', 'service_catalog_approval_steps', 'service_catalog_requests', 'service_catalog_request_approvals'];
$shape = function () use ($mysqli, $q, $tables) {
    $out = [];
    foreach (array_merge($tables, ['service_catalog_items', 'tickets']) as $t) {
        $res = $q("SHOW COLUMNS FROM `$t`");
        if (!$res) { $out[$t] = null; continue; }
        $cols = [];
        while ($c = mysqli_fetch_assoc($res)) {
            if ($t === 'tickets' && $c['Field'] !== 'ticket_catalog_item_id') continue;
            if ($t === 'service_catalog_items' && !in_array($c['Field'], ['requires_approval', 'risk_score', 'auto_approve_below'], true)) continue;
            $cols[$c['Field']] = $c['Type'] . '|' . $c['Null'] . '|' . $c['Default'];
        }
        $idx = [];
        $res = $q("SHOW INDEX FROM `$t`");
        while ($i = mysqli_fetch_assoc($res)) { if (in_array($t, ['tickets']) && $i['Key_name'] !== 'idx_tickets_catalog_item') continue; $idx[$i['Key_name'] . '.' . $i['Seq_in_index']] = $i['Column_name']; }
        ksort($idx);
        $out[$t] = ['cols' => $cols, 'idx' => $idx];
    }
    return $out;
};

// Fresh-install shape (db.sql was imported into this scratch DB)
$fresh = $shape();
$ok(count(array_filter($fresh)) === 6, 'db.sql creates all four tables and the new columns');
// Rewind to a 2.6.132 database: drop everything this migration adds
foreach ($tables as $t) { $q("DROP TABLE `$t`"); }
$q("ALTER TABLE service_catalog_items DROP COLUMN requires_approval, DROP COLUMN risk_score, DROP COLUMN auto_approve_below");
$q("ALTER TABLE tickets DROP INDEX idx_tickets_catalog_item"); $q("ALTER TABLE tickets DROP COLUMN ticket_catalog_item_id");
$q("DELETE FROM settings"); $q("INSERT INTO settings SET company_id = 1, config_current_database_version = '2.6.132'");
$q("INSERT INTO service_catalog_items SET name = 'Existing item', ticket_subject_template = 's'");
$ok($shape()['service_catalog_fields'] === null, 'rewound: the tables are gone');

define('CURRENT_DATABASE_VERSION', '2.6.132');
require __DIR__ . '/../includes/database_version.php';
$ok(version_compare(LATEST_DATABASE_VERSION, '2.6.133', '>='), 'LATEST_DATABASE_VERSION covers this migration');
ob_start(); require __DIR__ . '/../admin/database_updates.php'; ob_end_clean();
$version = fn() => mysqli_fetch_row($q("SELECT config_current_database_version FROM settings WHERE company_id = 1"))[0];
$ok($version() === LATEST_DATABASE_VERSION, 'run 1: version advanced to ' . LATEST_DATABASE_VERSION);
$after1 = $shape();
$ok($after1 === $fresh, 'run 1: schema equals a fresh db.sql install (columns, types, defaults, indexes)');
$ok(mysqli_fetch_row($q("SELECT requires_approval, risk_score, auto_approve_below FROM service_catalog_items WHERE name='Existing item'")) === ['0', '0', '0'], 'existing items come out with approval off');

// Idempotent: run the same step again on a database that already has everything
$q("UPDATE settings SET config_current_database_version = '2.6.132'");
$q("INSERT INTO service_catalog_requests SET catalog_item_id = 1, ticket_id = 1, status = 'approved'");
ob_start(); require __DIR__ . '/../admin/database_updates.php'; ob_end_clean();
$ok($version() === LATEST_DATABASE_VERSION && $shape() === $fresh, 'run 2: idempotent (same schema, version again at latest)');
$ok(mysqli_fetch_row($q("SELECT COUNT(*) FROM service_catalog_requests"))[0] === '1', 'run 2: existing rows are untouched');
$q("DELETE FROM service_catalog_requests");

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
