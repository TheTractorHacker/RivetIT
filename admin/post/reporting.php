<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

/*
 * Administration > Reporting / Power BI (RivetCore issue #91, phase 4). Administrators only: the reporting views hold ALL clients' data, so this is
 * never opened to a technician scoped to some clients. admin/post.php includes this file only for an administrator; every action re-checks it.
 *
 * Secrets: the database administrator's login is used once (src/Reporting/ReportingAdmin.php) and forgotten; the generated reporting password is
 * shown ONCE, in the response to the POST, with Cache-Control: no-store. It is not put in the session, the database, the audit rows, the logs or an error text.
 */

use ITFlow\Audit\AuditService;
use ITFlow\Reporting\EditionViews;
use ITFlow\Reporting\ReportingAdmin;
use RivetCore\Reporting\ReportingRole;

if (!isset($session_is_admin) || !$session_is_admin) {
    http_response_code(403);
    exit('Administrators only.');
}

$rp_fail = static function (string $message): void {
    flash_alert(nullable_htmlentities($message), 'error');
    redirect();
};
$rp_admin = static fn (): array => [
    'host' => trim((string) ($_POST['db_host'] ?? '')),
    'port' => (int) ($_POST['db_port'] ?? 3306),
    'user' => trim((string) ($_POST['admin_user'] ?? '')),
    'pass' => (string) ($_POST['admin_pass'] ?? ''),
];
$rp_account = static function (): array {
    $user = trim((string) ($_POST['account_user'] ?? ''));
    $host = trim((string) ($_POST['account_host'] ?? ''));

    return [$user === '' ? ReportingRole::DEFAULT_USER : $user, $host];
};
$rp_audit = static function (string $event, string $summary, array $meta) use ($session_user_id): void {
    // Account name and host part only. NEVER a password (the generated one or the administrator's).
    AuditService::record($event, (int) $session_user_id, 'reporting_account', (string) ($meta['account'] ?? ''), 'update', $summary, $meta);
};

if (isset($_POST['provision_reporting'])) {
    validateCSRFToken($_POST['csrf_token']);
    [$user, $host] = $rp_account();
    if ($host === '') {
        $rp_fail('Enter the host part of the account: the address of the Power BI gateway or machine that will connect. Using % lets any address try.');
    }
    $existing = (int) (mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rpt_devices'"))[0] ?? 0);
    if ($existing === 0) {
        $rp_fail('The reporting views do not exist yet. Run the database update first (Administration > Update).');
    }
    try {
        $dbName = (string) (mysqli_fetch_row(mysqli_query($mysqli, 'SELECT DATABASE()'))[0] ?? '');
        $res = ReportingAdmin::provisionMariadb($rp_admin(), $dbName, $user, $host, !empty($_POST['require_tls']), !empty($_POST['include_audit']));
    } catch (\InvalidArgumentException $e) {
        $rp_fail($e->getMessage());
    }
    $rp_audit('reporting.account.created', 'Reporting account created or rotated', ['account' => $user, 'host' => $host, 'tls' => !empty($_POST['require_tls']), 'audit_view' => !empty($_POST['include_audit'])]);
    logAction('Reporting', 'Edit', "$session_name created or rotated the reporting account $user@$host");
    // Shown once, in this response only.
    $rp_secret = ['kind' => 'mariadb', 'user' => $res['user'], 'host' => $res['host'], 'password' => $res['password'], 'db_host' => (string) ($_POST['public_host'] ?? ''), 'db_port' => (int) ($_POST['db_port'] ?? 3306), 'database' => $dbName, 'tls' => !empty($_POST['require_tls'])];
    unset($res);
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    require __DIR__ . '/../reporting_secret.php';
    exit;
}

if (isset($_POST['drop_reporting'])) {
    validateCSRFToken($_POST['csrf_token']);
    [$user, $host] = $rp_account();
    if (trim((string) ($_POST['confirm_name'] ?? '')) !== $user) {
        $rp_fail('Type the account name exactly to confirm.');
    }
    try {
        ReportingAdmin::dropMariadb($rp_admin(), $user, $host);
    } catch (\InvalidArgumentException $e) {
        $rp_fail($e->getMessage());
    }
    $rp_audit('reporting.account.dropped', 'Reporting account dropped', ['account' => $user, 'host' => $host]);
    logAction('Reporting', 'Delete', "$session_name dropped the reporting account $user@$host");
    flash_alert('The reporting account was dropped.');
    redirect();
}

if (isset($_POST['provision_reporting_pg']) || isset($_POST['drop_reporting_pg'])) {
    validateCSRFToken($_POST['csrf_token']);
    [$user] = $rp_account();
    $drop = isset($_POST['drop_reporting_pg']);
    if ($drop && trim((string) ($_POST['confirm_name'] ?? '')) !== $user) {
        $rp_fail('Type the account name exactly to confirm.');
    }
    try {
        require_once dirname(__DIR__, 2) . '/includes/event_bus.php';
        require_once dirname(__DIR__, 2) . '/includes/rmm_bootstrap.php';
        $rmm = rivetRmmModule($mysqli);
        if ($rmm->settings()->dataStore() !== \RivetCore\Rmm\Store\RmmDataStoreKind::POSTGRES) {
            $rp_fail('The RMM data store is not PostgreSQL.');
        }
        $cfg = $rmm->dataStoreSwitch()->postgresConfig();
        if ($cfg === null) {
            $rp_fail('No PostgreSQL connection is saved.');
        }
        $res = ReportingAdmin::provisionPostgres($cfg, $user, $drop);
    } catch (\InvalidArgumentException $e) {
        $rp_fail($e->getMessage());
    }
    $rp_audit($drop ? 'reporting.account.dropped' : 'reporting.account.created', $drop ? 'PostgreSQL reporting role dropped' : 'PostgreSQL reporting role created or rotated', ['account' => $user, 'store' => 'postgres']);
    if ($drop) {
        flash_alert('The PostgreSQL reporting role was dropped.');
        redirect();
    }
    $rp_secret = ['kind' => 'postgres', 'user' => $user, 'host' => '', 'password' => $res['password'], 'db_host' => $cfg->host, 'db_port' => $cfg->port, 'database' => $cfg->database, 'tls' => true];
    unset($res);
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    require __DIR__ . '/../reporting_secret.php';
    exit;
}

if (isset($_POST['test_reporting'])) {
    validateCSRFToken($_POST['csrf_token']);
    $host = trim((string) ($_POST['test_host'] ?? ''));
    $port = (int) ($_POST['test_port'] ?? 3306);
    $user = trim((string) ($_POST['account_user'] ?? '')) ?: ReportingRole::DEFAULT_USER;
    $dbName = (string) (mysqli_fetch_row(mysqli_query($mysqli, 'SELECT DATABASE()'))[0] ?? '');
    if ($host === '' || preg_match('/^[A-Za-z0-9_.:\-]{1,255}$/', $host) !== 1 || $port < 1 || $port > 65535 || (string) ($_POST['test_pass'] ?? '') === '') {
        $rp_fail('Enter the server host and port and the reporting account\'s password.');
    }
    $checks = ReportingAdmin::testConnection($host, $port, $dbName, $user, (string) $_POST['test_pass'], !empty($_POST['test_tls']));
    $allOk = !array_filter($checks, static fn ($c) => !$c['ok']);
    $rp_audit('reporting.account.tested', 'Reporting account connection tested', ['account' => $user, 'result' => $allOk ? 'pass' : 'fail']);
    $_SESSION['reporting_test_result'] = $checks;   // names and messages only; the message text has the password removed
    redirect();
}

if (isset($_POST['rebuild_reporting_views'])) {
    validateCSRFToken($_POST['csrf_token']);
    $r = EditionViews::install($mysqli);
    $rp_audit('reporting.views.rebuilt', 'Edition reporting views rebuilt', ['account' => '', 'created' => count($r['created']), 'errors' => count($r['errors'])]);
    if ($r['errors']) {
        flash_alert(nullable_htmlentities(implode(' ', $r['errors'])), 'error');
    } else {
        flash_alert('The reporting views were rebuilt.' . ($r['warning'] ? ' ' . nullable_htmlentities($r['warning']) : ''), $r['warning'] ? 'warning' : 'success');
    }
    redirect();
}
