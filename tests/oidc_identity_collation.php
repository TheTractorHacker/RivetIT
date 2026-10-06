<?php
/*
 * OIDC / SSO identities are case-sensitive: users.user_oidc_* and user_sso_* are utf8mb4_bin, so two subjects that differ only
 * by case are two accounts. Runs the EXACT 2.6.143 -> 2.6.144 migration block from admin/database_updates.php against a scratch
 * database (after forcing the columns back to the old case-insensitive collation), checks it is idempotent, then resolves a
 * sign-in by (issuer, subject) through portalOidcEligibleAccount(). Needs a THROWAWAY database with the full schema:
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=...scratch... RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/oidc_identity_collation.php
 */
if (getenv('RIVETIT_TEST_DB') !== '1') exit(2);
if (!preg_match('/scratch|test/i', (string) getenv('RIVETIT_TEST_DB_NAME'))) { fwrite(STDERR, "Refusing: DB name must contain scratch/test\n"); exit(2); }
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/oidc_portal.php';
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETIT_TEST_DB_USER'), getenv('RIVETIT_TEST_DB_PASS'), getenv('RIVETIT_TEST_DB_NAME'));
$mysqli->set_charset('utf8mb4');
$mysqli->query("SET SESSION sql_mode=''");
$fails = 0; $ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };
$coll = fn(string $col) => mysqli_fetch_row(mysqli_query($mysqli, "SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = '$col'"))[0];
$cols = ['user_oidc_issuer', 'user_oidc_subject', 'user_sso_issuer', 'user_sso_subject'];

// Pull the migration block out of the real file.
$src = file_get_contents(__DIR__ . '/../admin/database_updates.php');
$a = strpos($src, "if (\$rivetit_db_version() == '2.6.143') {");
$b = strpos($src, "config_current_database_version` = '2.6.144'");
if ($a === false || $b === false) { echo "FAIL  could not find the 2.6.143 block\n"; exit(1); }
$b = strpos($src, "\n    }", $b) + 6;
$block = substr($src, $a, $b - $a);
$version = '2.6.143';
$rivetit_db_version = function () use (&$version) { return $version; };
$run = function () use ($block, $mysqli, $rivetit_db_version) { eval($block); };

// Old shape: case-insensitive.
mysqli_query($mysqli, "ALTER TABLE users MODIFY user_oidc_issuer varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL, MODIFY user_oidc_subject varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL, MODIFY user_sso_issuer varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL, MODIFY user_sso_subject varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL");
mysqli_query($mysqli, "DELETE FROM users WHERE user_email LIKE '%@collation.test'");
$ins = "INSERT INTO users (user_name, user_email, user_password, user_auth_method, user_type, user_status, user_oidc_issuer, user_oidc_subject) VALUES ";
$ok(mysqli_query($mysqli, $ins . "('A','a@collation.test','x','oidc',2,1,'https://idp.test','Abc')"), 'old collation: first subject inserts');
$ok(!mysqli_query($mysqli, $ins . "('B','b@collation.test','x','oidc',2,1,'https://idp.test','aBC')"), 'old collation: a subject differing only by case collides (the bug)');
mysqli_query($mysqli, "DELETE FROM users WHERE user_email LIKE '%@collation.test'");

$run();
foreach ($cols as $c) { $ok($coll($c) === 'utf8mb4_bin', "$c is utf8mb4_bin after the migration"); }
$ok(mysqli_fetch_row(mysqli_query($mysqli, "SELECT config_current_database_version FROM settings"))[0] === '2.6.144', 'version stamped 2.6.144');
$ix = mysqli_fetch_row(mysqli_query($mysqli, "SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX), MAX(NON_UNIQUE) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='users' AND INDEX_NAME='idx_users_oidc_identity'"));
$ok($ix[0] === 'user_oidc_issuer,user_oidc_subject' && (int) $ix[1] === 0, 'unique index on (issuer, subject) is intact');
$t = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT IS_NULLABLE, COLUMN_DEFAULT, CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='user_oidc_subject'"));
$ok($t['IS_NULLABLE'] === 'YES' && (int) $t['CHARACTER_MAXIMUM_LENGTH'] === 255 && ($t['COLUMN_DEFAULT'] === null || $t['COLUMN_DEFAULT'] === 'NULL'), 'type, length and nullability unchanged');
$version = '2.6.143'; $run();
foreach ($cols as $c) { $ok($coll($c) === 'utf8mb4_bin', "re-run is a no-op ($c)"); }

// Two clients with subjects differing only by case: each sign-in resolves its own account.
mysqli_query($mysqli, "DELETE FROM contacts WHERE contact_email LIKE '%@collation.test'");
mysqli_query($mysqli, "DELETE FROM clients WHERE client_name LIKE 'collation-%'");
$ids = [];
foreach (['Abc' => 'lower1', 'aBC' => 'lower2'] as $sub => $tag) {
    mysqli_query($mysqli, "INSERT INTO clients (client_name) VALUES ('collation-$tag')"); $cid = $mysqli->insert_id;
    $ok(mysqli_query($mysqli, $ins . "('$tag','$tag@collation.test','x','oidc',2,1,'https://idp.test','$sub')"), "subject '$sub' inserts alongside its case variant");
    $uid = $mysqli->insert_id;
    mysqli_query($mysqli, "INSERT INTO contacts (contact_name, contact_email, contact_client_id, contact_user_id) VALUES ('$tag','$tag@collation.test',$cid,$uid)");
    $ids[$sub] = $uid;
}
$r1 = portalOidcEligibleAccount($mysqli, 'https://idp.test', 'Abc');
$r2 = portalOidcEligibleAccount($mysqli, 'https://idp.test', 'aBC');
$ok($r1 !== null && (int) $r1['user_id'] === $ids['Abc'], "subject 'Abc' resolves its own account");
$ok($r2 !== null && (int) $r2['user_id'] === $ids['aBC'], "subject 'aBC' resolves its own account");
$ok(portalOidcEligibleAccount($mysqli, 'https://idp.test', 'ABC') === null, "subject 'ABC' (a third spelling) matches nobody");
$ok(portalOidcEligibleAccount($mysqli, 'https://IDP.test', 'Abc') === null, 'issuer is compared exactly too');

mysqli_query($mysqli, "DELETE FROM contacts WHERE contact_email LIKE '%@collation.test'");
mysqli_query($mysqli, "DELETE FROM users WHERE user_email LIKE '%@collation.test'");
mysqli_query($mysqli, "DELETE FROM clients WHERE client_name LIKE 'collation-%'");
echo $fails === 0 ? "ALL PASSED\n" : "$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
