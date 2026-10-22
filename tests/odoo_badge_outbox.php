<?php
/*
 * DB 2.6.190: training_odoo_outbox.todoo_mode gains 'badge' and training_automation gains
 * tauto_odoo_send_badge. Regression test for a real bug this change would have hit: OutboxRepo::enqueue()
 * had a HARDCODED mode whitelist (['resume','skill','note']) that would have thrown
 * InvalidArgumentException on every attempt to queue a badge row, silently breaking the whole feature -
 * fixed to check Targets::valid($mode) instead. Also checks AutomationSettings reads/writes the new
 * column, and Targets::enabled() picks it up.
 *
 * SCRATCH DATABASE ONLY: never run against a live database.
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=...scratch... RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/odoo_badge_outbox.php
 */
if (getenv('RIVETIT_TEST_DB') !== '1') exit(2);
if (!preg_match('/scratch|test/i', (string) getenv('RIVETIT_TEST_DB_NAME'))) { fwrite(STDERR, "Refusing: DB name must contain scratch/test\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$db = new mysqli('localhost', getenv('RIVETIT_TEST_DB_USER'), getenv('RIVETIT_TEST_DB_PASS'), getenv('RIVETIT_TEST_DB_NAME'));
if ($db->connect_errno) { fwrite(STDERR, "connect failed\n"); exit(2); }
$db->query("SET SESSION sql_mode=''");

require __DIR__ . '/../vendor/autoload.php';

use ITFlow\Training\Automation\AutomationSettings;
use ITFlow\Training\OdooSync\Marker;
use ITFlow\Training\OdooSync\OutboxRepo;
use ITFlow\Training\OdooSync\Target;
use ITFlow\Training\OdooSync\Targets;

$fails = 0;
$ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };

$ok(in_array('badge', Targets::MODES, true), 'Targets::MODES includes badge');
$ok(Targets::valid('badge'), 'Targets::valid accepts badge');

$db->query('DELETE FROM training_odoo_outbox WHERE todoo_source_type = \'award\' AND todoo_source_id = 999001');
$repo = new OutboxRepo($db);
$target = Target::fromRow(['odoo_integration_id' => 1, 'base_url' => 'https://example.odoo.com', 'database_name' => 'db',
    'username' => 'u', 'api_key_enc' => 'x', 'api_protocol' => 'jsonrpc_pinned']);
$marker = Marker::for('abcd1234', 'award', 999001);

try {
    $queued = $repo->enqueue($target, 'award', 999001, 'create', 1, 'badge', $marker, gmdate('Y-m-d H:i:s'));
    $ok($queued === true, 'enqueue() accepts mode=badge and queues a new row (the regression this bug would have thrown on)');
} catch (\InvalidArgumentException $e) {
    $ok(false, 'enqueue() accepts mode=badge (threw: ' . $e->getMessage() . ')');
}

$row = $db->query("SELECT todoo_mode FROM training_odoo_outbox WHERE todoo_source_type = 'award' AND todoo_source_id = 999001")->fetch_assoc();
$ok($row !== null && $row['todoo_mode'] === 'badge', 'the queued row\'s mode is stored as badge');

$db->query('DELETE FROM training_automation WHERE tauto_id = 1');
$db->query("INSERT INTO training_automation (tauto_id, tauto_odoo_send_resume, tauto_odoo_send_skill, tauto_odoo_send_note, tauto_odoo_send_badge, tauto_version)
    VALUES (1, 1, 0, 0, 1, 1)");
$settings = AutomationSettings::load($db);
$ok((int) ($settings['tauto_odoo_send_badge'] ?? -1) === 1, 'AutomationSettings::load() reads tauto_odoo_send_badge');
$enabled = Targets::enabled($settings);
$ok(in_array('badge', $enabled, true), 'Targets::enabled() includes badge when its column is 1');

$db->query('DELETE FROM training_odoo_outbox WHERE todoo_source_type = \'award\' AND todoo_source_id = 999001');
$db->query('DELETE FROM training_automation WHERE tauto_id = 1');

echo $fails === 0 ? "ALL PASS\n" : "$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
