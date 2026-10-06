<?php

/*
 * Renders one report headlessly, as one user, and prints CSV or email HTML on stdout. CLI only.
 * Spawned by cron/report_scheduler.php (one child per schedule, so a report's own exit() cannot end the cron run):
 *
 *   php scripts/report_render.php --user=ID --report=KEY --format=csv|html [--params=BASE64(JSON)]
 *
 * It loads the owner's real session globals (role, department restrictions), then includes the report page in
 * agent/reports/ with RIVETIT_REPORT_HEADLESS set, so the page enforces its own module permission and applies the
 * same department scoping as in the browser. Failure is reported on stderr with exit code 2.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

use ITFlow\Reports\ReportCatalog;
use ITFlow\Reports\ReportExport;

$opts = getopt('', ['user:', 'report:', 'format:', 'params::']);
$fail = static function (string $msg): void {
    fwrite(STDERR, $msg . "\n");
    exit(2);
};

$uid = (int) ($opts['user'] ?? 0);
$key = (string) ($opts['report'] ?? '');
$format = (string) ($opts['format'] ?? 'csv');
if ($uid <= 0 || !preg_match('/^[a-z0-9_]+$/', $key) || !in_array($format, ['csv', 'html'], true)) {
    $fail('usage: report_render.php --user=ID --report=KEY --format=csv|html [--params=BASE64]');
}

require_once dirname(__DIR__) . '/vendor/autoload.php';
if (!ReportCatalog::exists($key)) {
    $fail("unknown report '$key'");
}
$root = dirname(__DIR__);
$page = $root . '/agent/reports/' . $key . '.php';
if (!is_file($page)) {
    $fail("report page missing for '$key'");
}
$params = json_decode((string) base64_decode((string) ($opts['params'] ?? ''), true), true);
$params = ReportCatalog::sanitizeParams($key, is_array($params) ? $params : []);

define('RIVETIT_REPORT_HEADLESS', true);
$_SERVER['DOCUMENT_ROOT'] = $root;
$_SERVER['HTTP_USER_AGENT'] = 'report-render';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/agent/reports/' . $key . '.php';
$_SERVER['REQUEST_URI'] = $_SERVER['SCRIPT_NAME'];

chdir($root . '/agent/reports');
require_once $root . '/config.php';
require_once $root . '/includes/inc_set_timezone.php';
require_once $root . '/functions.php';

$_SESSION = ['user_id' => $uid, 'csrf_token' => bin2hex(random_bytes(8))];
// csv = the report's own export (same file as its Export CSV button); html = the page as drawn on screen, tables only.
$_GET = $params + ($format === 'csv' ? ['export' => 'csv'] : []);
$_POST = [];

// The owner must still be an active agent (load_user_session.php would redirect and exit silently otherwise).
$owner = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT user_type, user_status, user_archived_at FROM users WHERE user_id = $uid"));
if (!$owner || intval($owner['user_type']) !== 1 || intval($owner['user_status']) !== 1 || $owner['user_archived_at'] !== null) {
    $fail('owner is missing or inactive');
}
require_once $root . '/includes/load_user_session.php';
require_once $root . '/includes/load_company_settings.php';
require_once $root . '/includes/load_global_settings.php';

ob_start();
register_shutdown_function(static function () use ($format, $key) {
    $csvSent = !empty($GLOBALS['report_csv_sent']);
    $out = '';
    while (ob_get_level() > 0) {
        $out = ob_get_clean() . $out;
    }
    if (!$csvSent && stripos($out, 'access to this page') !== false && stripos($out, '<table') === false) {
        fwrite(STDERR, "denied: the owner's role cannot open this report\n");
        exit(2);
    }
    if ($csvSent) {
        $csv = $out;
        if ($format === 'csv') {
            echo $csv;
            return;
        }
        $rows = array_map('str_getcsv', array_filter(preg_split('/\r\n|\n/', ltrim($csv, "\xEF\xBB\xBF")), static fn ($l) => $l !== ''));
    } else {
        $rows = ReportExport::fromHtml($out);
        if ($format === 'csv') {
            if ($rows === []) {
                fwrite(STDERR, "report produced no tabular data\n");
                exit(2);
            }
            echo ReportExport::toCsv([], $rows);
            return;
        }
    }
    if ($rows === []) {
        fwrite(STDERR, "report produced no tabular data\n");
        exit(2);
    }
    echo ReportExport::emailHtmlFromRows((string) ReportCatalog::label($key), $rows);
});

include $page;
