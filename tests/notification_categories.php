<?php
/*
 * In-app notification category muting (Settings > Notifications "In-App Notifications" card, and the user's own
 * preferences): a separate on/off axis from the mobile push categories, gating whether appNotify() creates a
 * notification row at all - not just whether it pushes. Also covers the daily throttle for the cron "Update
 * available" notice (previously resent on every cron run with no gate at all).
 * Needs a THROWAWAY database that holds this app's schema (import db.sql and run the database updates); it never
 * reads config.php:
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=...scratch... RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/notification_categories.php
 */
if (getenv('RIVETIT_TEST_DB') !== '1') exit(2);
if (!preg_match('/scratch|test/i', (string) getenv('RIVETIT_TEST_DB_NAME'))) { fwrite(STDERR, "Refusing: DB name must contain scratch/test\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETIT_TEST_DB_USER'), getenv('RIVETIT_TEST_DB_PASS'), getenv('RIVETIT_TEST_DB_NAME'));
if ($mysqli->connect_errno) { fwrite(STDERR, "connect failed\n"); exit(2); }
$mysqli->query("SET SESSION sql_mode=''");
$GLOBALS['mysqli'] = $mysqli;

require __DIR__ . '/../includes/module_access.php';
require __DIR__ . '/../functions.php';

$fails = 0;
$ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };
$q = fn(string $sql) => mysqli_query($mysqli, $sql);
$one = fn(string $sql) => mysqli_fetch_row($q($sql))[0] ?? null;

$mysqli->query("INSERT INTO user_roles (role_id, role_name, role_is_admin) VALUES (1,'Admin',1) ON DUPLICATE KEY UPDATE role_is_admin=1");
$mysqli->query("INSERT INTO users (user_id, user_name, user_email, user_password, user_type, user_status, user_role_id) VALUES (1,'Test Admin','ta@example.test','x',1,1,1) ON DUPLICATE KEY UPDATE user_name='Test Admin'");
$mysqli->query("INSERT INTO settings (company_id) VALUES (1) ON DUPLICATE KEY UPDATE config_notify_enabled_types = NULL, config_update_notify_last_version = NULL, config_update_notify_last_at = NULL");
$mysqli->query("INSERT INTO user_settings (user_id) VALUES (1) ON DUPLICATE KEY UPDATE user_config_notify_types = NULL");

// 1. Default (no config anywhere): every category, including "system" (Cron/Update), is allowed.
$ok(notify_allowed_for_user(1, 'Update'), 'Update allowed by default');
$ok(notify_allowed_for_user(1, 'Cron'), 'Cron allowed by default');
$ok(notify_allowed_for_user(1, 'Some Unmapped Type'), 'an unmapped type is always allowed (never muted)');

// 2. Global admin setting disables "system" (the category Cron/Update/etc. live in)
$withoutSystem = array_values(array_diff(array_keys(push_notification_categories()), ['system']));
$mysqli->query("UPDATE settings SET config_notify_enabled_types = '" . $mysqli->real_escape_string(json_encode($withoutSystem)) . "' WHERE company_id = 1");
$ok(!notify_allowed_for_user(1, 'Update'), 'Update blocked once the admin disables "system" globally');
$ok(!notify_allowed_for_user(1, 'Cron'), 'Cron blocked once the admin disables "system" globally');
$ok(notify_allowed_for_user(1, 'Ticket'), 'an unrelated category (Ticket) is unaffected');
$mysqli->query("UPDATE settings SET config_notify_enabled_types = NULL WHERE company_id = 1");

// 3. Per-user mute, independent of the admin's global setting
$mysqli->query("UPDATE user_settings SET user_config_notify_types = '" . $mysqli->real_escape_string(json_encode($withoutSystem)) . "' WHERE user_id = 1");
$ok(!notify_allowed_for_user(1, 'Update'), 'Update blocked once THIS USER mutes "system" personally');
$ok(notify_allowed_for_user(1, 'Ticket'), 'Ticket still allowed for that same user (different category)');
$mysqli->query("UPDATE user_settings SET user_config_notify_types = NULL WHERE user_id = 1");

// 4. Hostile/malformed stored config: never fatals, always falls back to "allow"
$mysqli->query("UPDATE settings SET config_notify_enabled_types = 'not valid json {{{' WHERE company_id = 1");
$ok(notify_allowed_for_user(1, 'Update'), 'malformed global JSON falls back to allow, not a crash');
$mysqli->query("UPDATE user_settings SET user_config_notify_types = '\"just a string, not an array\"' WHERE user_id = 1");
$ok(notify_allowed_for_user(1, 'Update'), 'malformed per-user JSON (valid JSON, wrong shape) falls back to allow');
$mysqli->query("UPDATE settings SET config_notify_enabled_types = NULL WHERE company_id = 1");
$mysqli->query("UPDATE user_settings SET user_config_notify_types = NULL WHERE user_id = 1");

// 5. appNotify() actually skips the INSERT (and so the push/SSE publish downstream of it) when muted - not just a cosmetic hide
$before = (int) $one("SELECT COUNT(*) FROM notifications WHERE notification_user_id = 1 AND notification_type = 'Update'");
$mysqli->query("UPDATE user_settings SET user_config_notify_types = '" . $mysqli->real_escape_string(json_encode($withoutSystem)) . "' WHERE user_id = 1");
appNotify('Update', 'test notice while muted', '/admin/update.php');
$after = (int) $one("SELECT COUNT(*) FROM notifications WHERE notification_user_id = 1 AND notification_type = 'Update'");
$ok($before === $after, 'appNotify() created no row at all for a muted user/category');
$mysqli->query("UPDATE user_settings SET user_config_notify_types = NULL WHERE user_id = 1");
appNotify('Update', 'test notice while unmuted', '/admin/update.php');
$after2 = (int) $one("SELECT COUNT(*) FROM notifications WHERE notification_user_id = 1 AND notification_type = 'Update'");
$ok($after2 === $after + 1, 'appNotify() created a row once unmuted again');

// 6. The cron "Update available" daily-throttle math (cron/cron.php): same condition the real code evaluates
$due = fn(?string $lastVersion, ?string $lastAt, string $latest): bool =>
    $lastVersion !== $latest || $lastAt === null || strtotime($lastAt) <= (time() - 86400);
$ok($due(null, null, 'v1.2.3'), 'first-ever check is due');
$ok(!$due('v1.2.3', date('Y-m-d H:i:s'), 'v1.2.3'), 'same version just notified: not due (this is the hourly-spam fix)');
$ok(!$due('v1.2.3', date('Y-m-d H:i:s', time() - 3600), 'v1.2.3'), 'same version, 1h ago: not due');
$ok($due('v1.2.3', date('Y-m-d H:i:s', time() - 86400), 'v1.2.3'), 'same version, exactly 24h ago: due again');
$ok($due('v1.2.3', date('Y-m-d H:i:s'), 'v1.2.4'), 'a brand-new version is due immediately regardless of the throttle');

echo $fails === 0 ? "ALL PASS\n" : "$fails FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
