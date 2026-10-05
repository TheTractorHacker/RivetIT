<?php
/*
 * Readiness: the database is reachable and its schema matches this code. Redis is reported but never fails
 * readiness - every Redis feature fails open. External integrations are deliberately not checked.
 * Returns only ok/fail per check (no hostnames, versions, or error text). The checks run in RivetCore.
 */
header('Content-Type: application/json');
header('Cache-Control: no-store');

mysqli_report(MYSQLI_REPORT_OFF);
$checks = ['database' => 'fail', 'schema' => 'fail', 'redis' => 'unavailable'];

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/database_version.php';
require_once __DIR__ . '/../includes/redis_functions.php';

$ready = false;
if (isset($mysqli) && $mysqli instanceof mysqli && @$mysqli->ping() && class_exists(\RivetCore\Health\ReadinessChecker::class)) {
    $report = (new \RivetCore\Health\ReadinessChecker(
        new \ITFlow\Core\Adapter\Database\MysqliDatabaseAdapter($mysqli),
        static function () use ($mysqli): bool {
            $row = $mysqli->query('SELECT config_current_database_version AS v FROM settings WHERE company_id = 1')?->fetch_assoc();
            return $row && version_compare((string) $row['v'], LATEST_DATABASE_VERSION, '>=');
        },
        new \ITFlow\Core\Adapter\Redis\GlobalRedisClientProvider()
    ))->check();
    $ready = $report['ready'];
    $checks = $report['checks'];
}

http_response_code($ready ? 200 : 503);
echo json_encode(['status' => $ready ? 'ready' : 'not_ready', 'checks' => $checks]);
