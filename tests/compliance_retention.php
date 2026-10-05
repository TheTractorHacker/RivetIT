<?php
/*
 * Compliance retention: runs the EXACT retention block from cron/cron.php against aged rows in a scratch database and checks what is
 * deleted and what is kept under different presets and horizons. Needs a THROWAWAY schema-only database that holds this app's full
 * schema (import db.sql and run the migrations); it never reads config.php, so it cannot touch the real database:
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=...scratch... RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/compliance_retention.php
 */
if (getenv('RIVETIT_TEST_DB') !== '1') exit(2);
if (!preg_match('/scratch|test/i', (string) getenv('RIVETIT_TEST_DB_NAME'))) { fwrite(STDERR, "Refusing: DB name must contain scratch/test\n"); exit(2); }
require_once __DIR__ . '/../vendor/autoload.php';
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETIT_TEST_DB_USER'), getenv('RIVETIT_TEST_DB_PASS'), getenv('RIVETIT_TEST_DB_NAME'));
$src = file_get_contents(__DIR__ . '/../cron/cron.php');
$a = strpos($src, '// Retention. A compliance preset'); $b = strpos($src, '// Compliance status: one saved snapshot');
if ($a === false || $b === false) { echo "FAIL  could not find the retention block\n"; exit(1); }
$block = substr($src, $a, $b - $a);
$q = fn(string $sql) => mysqli_query($mysqli, $sql);
$n = fn(string $t, string $w = '1') => (int) mysqli_fetch_row($q("SELECT COUNT(*) FROM $t WHERE $w"))[0];
$fails = 0; $ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };
function seed($q) {
    foreach (['logs','app_logs','auth_logs','audit_events','webhook_deliveries','integration_jobs'] as $t) $q("DELETE FROM $t");
    foreach ([100 => 'old', 5 => 'new'] as $d => $tag) {
        $q("INSERT INTO logs (log_type, log_action, log_description, log_created_at) VALUES ('t','a','$tag', NOW() - INTERVAL $d DAY)");
        $q("INSERT INTO app_logs (app_log_type, app_log_category, app_log_details, app_log_created_at) VALUES ('info','c','$tag', NOW() - INTERVAL $d DAY)");
        $q("INSERT INTO auth_logs (auth_log_status, auth_log_details, auth_log_created_at) VALUES (1,'$tag', NOW() - INTERVAL $d DAY)");
        $q("INSERT INTO webhook_deliveries (webhook_id, event_type, created_at) VALUES (1,'$tag', NOW() - INTERVAL $d DAY)");
        $q("INSERT INTO integration_jobs (job_type, status, created_at) VALUES ('$tag','completed', NOW() - INTERVAL $d DAY)");
    }
    foreach ([400 => 'ancient', 200 => 'mid', 5 => 'new'] as $d => $tag) $q("INSERT INTO audit_events (event_type, action, created_at) VALUES ('$tag','a', NOW() - INTERVAL $d DAY)");
}
$run = function (array $settings_row, int $config_log_retention) use ($block, $mysqli) { eval($block); };

echo "--- A: no preset, activity logs 30 days, audit trail 365 days\n";
seed($q); $run(['config_compliance_profile' => 'none', 'config_audit_retention_days' => 365], 30);
$ok($n('logs', "log_description='old'") === 0 && $n('logs', "log_description='new'") === 1, 'activity logs: 100-day-old row deleted, recent kept');
$ok($n('app_logs', "app_log_details='old'") === 0 && $n('auth_logs', "auth_log_details='old'") === 0, 'app and auth logs follow the same horizon');
$ok($n('webhook_deliveries', "event_type='old'") === 0 && $n('webhook_deliveries', "event_type='new'") === 1, 'webhook delivery log pruned at the activity horizon');
$ok($n('integration_jobs', "job_type='old'") === 0 && $n('integration_jobs', "job_type='new'") === 1, 'finished jobs pruned at the activity horizon');
$ok($n('audit_events', "event_type='ancient'") === 0 && $n('audit_events', "event_type='mid'") === 1 && $n('audit_events', "event_type='new'") === 1, 'audit trail uses ITS horizon: 400 days deleted, 200 and 5 kept');

echo "--- B: HIPAA preset (floor 2190) but the stored numbers are 30 and 30\n";
seed($q); $run(['config_compliance_profile' => 'hipaa', 'config_audit_retention_days' => 30], 30);
$ok($n('logs') === 2 && $n('app_logs') === 2 && $n('auth_logs') === 2, 'nothing in the activity logs is deleted: the preset floor beats the stored 30');
$ok($n('audit_events') === 3 && $n('webhook_deliveries') === 2 && $n('integration_jobs') === 2, 'nothing in the Core tables is deleted either');

echo "--- C: activity logs keep-forever (0), audit trail 365\n";
seed($q); $run(['config_compliance_profile' => 'none', 'config_audit_retention_days' => 365], 0);
$ok($n('logs') === 2 && $n('app_logs') === 2 && $n('webhook_deliveries') === 2 && $n('integration_jobs') === 2, 'retention 0 keeps the activity logs, delivery log and jobs (the old bug deleted everything older than today)');
$ok($n('audit_events', "event_type='ancient'") === 0 && $n('audit_events') === 2, 'the audit trail is still pruned at its own 365 days');

echo "--- D: audit trail keep-forever (0), activity logs 30\n";
seed($q); $run(['config_compliance_profile' => 'none', 'config_audit_retention_days' => 0], 30);
$ok($n('audit_events') === 3, 'audit retention 0 keeps every audit row');
$ok($n('logs', "log_description='old'") === 0, 'while the activity logs are still pruned');

echo "--- E: ISO 27001 floor (365) with activity logs 30 and audit 100\n";
seed($q); $run(['config_compliance_profile' => 'iso27001', 'config_audit_retention_days' => 100], 30);
$ok($n('logs', "log_description='old'") === 1, 'a 100-day-old activity row survives (floor 365 beats 30)');
$ok($n('audit_events', "event_type='ancient'") === 0 && $n('audit_events', "event_type='mid'") === 1, 'the 400-day-old audit row goes, the 200-day-old one stays (floor 365 beats 100, then 400 > 365)');
foreach (['logs','app_logs','auth_logs','audit_events','webhook_deliveries','integration_jobs'] as $t) $q("DELETE FROM $t");
echo $fails ? "FAILED $fails\n" : "ALL PASSED\n";
