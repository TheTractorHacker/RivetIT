<?php
/*
 * Readiness: the database is reachable and its schema matches this code. Redis is reported but never
 * fails readiness - every Redis feature fails open. External integrations are deliberately not checked.
 * Returns only ok/fail per check (no hostnames, versions, or error text).
 */
header('Content-Type: application/json');
header('Cache-Control: no-store');

mysqli_report(MYSQLI_REPORT_OFF);
$checks = ['database' => 'fail', 'schema' => 'fail', 'redis' => 'unavailable'];

require_once __DIR__ . '/../config.php';
if (isset($mysqli) && $mysqli instanceof mysqli && @$mysqli->ping()) {
    $checks['database'] = 'ok';
    require_once __DIR__ . '/../includes/database_version.php';
    $res = $mysqli->query('SELECT config_current_database_version AS v FROM settings WHERE company_id = 1');
    $row = $res ? $res->fetch_assoc() : null;
    if ($row && version_compare((string) $row['v'], LATEST_DATABASE_VERSION, '>=')) {
        $checks['schema'] = 'ok';
    }
}

require_once __DIR__ . '/../includes/redis_functions.php';
try {
    $redis = getRedisClient();
    if ($redis && (string) $redis->ping() === 'PONG') $checks['redis'] = 'ok';
} catch (Throwable) { /* reported as unavailable */ }

$ready = $checks['database'] === 'ok' && $checks['schema'] === 'ok';
http_response_code($ready ? 200 : 503);
echo json_encode(['status' => $ready ? 'ready' : 'not_ready', 'checks' => $checks]);
