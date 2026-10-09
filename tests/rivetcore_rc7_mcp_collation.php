<?php
/* DB-free: database step 2.6.155 (RivetCore rc.7 Migration0017) exists, is gated on the class and on 2.6.154, follows the previous highest step; db.sql columns are utf8mb4_bin. */
$root = dirname(__DIR__);
$fail = 0;
$ok = function ($c, $m) use (&$fail) { echo ($c ? 'ok   ' : 'FAIL ') . $m . "\n"; if (!$c) $fail++; };
$upd = file_get_contents("$root/admin/database_updates.php");
$ok(strpos($upd, "if (\$rivetit_db_version() == '2.6.154') {") !== false, 'step gated on 2.6.154');
$a = strpos($upd, "if (\$rivetit_db_version() == '2.6.153') {");
$b = strpos($upd, "if (\$rivetit_db_version() == '2.6.154') {");
$ok($a !== false && $b !== false && $b > $a, '2.6.155 step comes after the previous highest (2.6.154) step');
$blk = substr($upd, $b);
$ok(strpos($blk, 'class_exists(\RivetCore\Mcp\Migration\Migration0017McpIdentityBinaryCollation::class)') !== false, 'gated on Migration0017 class_exists');
$ok(strpos($blk, 'CoreMigrations::all()') !== false && strpos($blk, 'MysqliDatabaseAdapter($mysqli)') !== false && strpos($blk, 'SystemClock') !== false, 'uses MigrationRunner/CoreMigrations/MysqliDatabaseAdapter/SystemClock');
$g = strpos($blk, 'class_exists('); $v = strpos($blk, "= '2.6.155'");
$ok($g !== false && $v !== false && $g < $v, 'version bump to 2.6.155 sits inside the class_exists guard');
require "$root/includes/database_version.php";
$ok(version_compare(LATEST_DATABASE_VERSION, '2.6.155', '>='), 'LATEST_DATABASE_VERSION >= 2.6.155 (' . LATEST_DATABASE_VERSION . ')');
$sql = file_get_contents("$root/db.sql");
$ok(preg_match('/CREATE TABLE `mcp_unlinked_identities` \((.*?)\n\) ENGINE/s', $sql, $m) === 1
    && strpos($m[1], '`issuer` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL') !== false
    && strpos($m[1], '`subject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL') !== false, 'db.sql issuer/subject are utf8mb4_bin');
exit($fail ? 1 : 0);
