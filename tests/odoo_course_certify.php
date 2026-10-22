<?php
/*
 * The course editor's "Certified" toggle (OdooSync\OdooAdmin::setCertified(), DB-level only - no live Odoo
 * call is exercised here, just the request-shape and DB-state guarantees that matter for the "a Training
 * manager never needs a framework Admin account for this" promise):
 *   - entity/id validation, course-not-found
 *   - uncertifying clears the mapping even when none existed
 *   - certifying with no Odoo integration configured at all (or none enabled) is refused with a plain
 *     "ask an admin" message, never a crash or an Odoo call
 *
 * SCRATCH DATABASE ONLY: never run against a live database.
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=...scratch... RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/odoo_course_certify.php
 */
if (getenv('RIVETIT_TEST_DB') !== '1') exit(2);
if (!preg_match('/scratch|test/i', (string) getenv('RIVETIT_TEST_DB_NAME'))) { fwrite(STDERR, "Refusing: DB name must contain scratch/test\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$db = new mysqli('localhost', getenv('RIVETIT_TEST_DB_USER'), getenv('RIVETIT_TEST_DB_PASS'), getenv('RIVETIT_TEST_DB_NAME'));
if ($db->connect_errno) { fwrite(STDERR, "connect failed\n"); exit(2); }
$db->query("SET SESSION sql_mode=''");

require __DIR__ . '/../vendor/autoload.php';

use ITFlow\Training\OdooSync\OdooAdmin;

$fails = 0;
$ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };

$db->query('DELETE FROM training_odoo_map');
$db->query('DELETE FROM odoo_integrations');
$db->query('DELETE FROM training_courses WHERE course_id = 777001');
$db->query("INSERT INTO training_courses (course_id, course_uid, course_name, course_kind, course_default_language, course_created_by, course_version)
    VALUES (777001, 'TESTCERT0001', 'Forklift Operator', 'training', 'en', 1, 1)");

[$t1, $m1] = OdooAdmin::setCertified($db, 'bogus-entity', 777001, true, 1);
$ok($t1 === 'error', 'an invalid entity is refused');

[$t2, $m2] = OdooAdmin::setCertified($db, 'course', 999999, true, 1);
$ok($t2 === 'error' && str_contains($m2, 'not found'), 'a nonexistent course is refused');

[$t3, $m3] = OdooAdmin::setCertified($db, 'course', 777001, false, 1);
$ok($t3 === 'success', 'un-certifying a course with no prior mapping still succeeds (nothing to clear, not an error)');

[$t4, $m4] = OdooAdmin::setCertified($db, 'course', 777001, true, 1);
$ok($t4 === 'error' && str_contains($m4, 'admin'), 'certifying with no Odoo integration configured asks for an admin, not a crash');

$db->query("INSERT INTO training_odoo_map (tomap_entity, tomap_entity_id, tomap_push, tomap_target_key, tomap_odoo_skill_id, tomap_updated_by)
    VALUES ('course', 777001, 1, 'deadbeefcafef00d', 42, 1)");
[$t5, $m5] = OdooAdmin::setCertified($db, 'course', 777001, false, 1);
$row = $db->query("SELECT tomap_odoo_skill_id FROM training_odoo_map WHERE tomap_entity = 'course' AND tomap_entity_id = 777001")->fetch_assoc();
$ok($t5 === 'success' && $row !== null && $row['tomap_odoo_skill_id'] === null, 'un-certifying an actually-mapped course clears the Odoo skill mapping');

$db->query('DELETE FROM training_odoo_map');
$db->query('DELETE FROM training_courses WHERE course_id = 777001');

echo $fails === 0 ? "ALL PASS\n" : "$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
