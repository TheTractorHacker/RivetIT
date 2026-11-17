<?php
declare(strict_types=1);
/*
 * Child process for tests/shared_vendors_handlers.php: runs ONE real agent POST handler file as a signed-in user against the SCRATCH
 * database and prints the outcome as JSON on shutdown (a handler ends in redirect(), which exits).
 *   php vendor_handler_case.php <appDir> <caseJsonFile>
 * case: {user_id, handler:"asset", post:{...}, get:{...}}
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
[, $app, $caseFile] = $argv;
$case = json_decode((string) file_get_contents($caseFile), true);
chdir("$app/agent");
$_SERVER = ['REQUEST_METHOD' => 'POST', 'HTTP_USER_AGENT' => 'phpunit-ish', 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost',
    'DOCUMENT_ROOT' => $app, 'SCRIPT_NAME' => '/agent/post.php', 'PHP_SELF' => '/agent/post.php', 'REQUEST_URI' => '/agent/post.php', 'HTTP_REFERER' => 'http://localhost/agent/vendors.php'] + $_SERVER;
$_SESSION = ['user_id' => $case['user_id'], 'csrf_token' => 'tok', 'logged' => true];
$_POST = ($case['post'] ?? []) + ['csrf_token' => 'tok'];
$_GET = ($case['get'] ?? []) + (isset($case['get']) ? ['csrf_token' => 'tok'] : []);
$_REQUEST = $_POST + $_GET;
register_shutdown_function(static function () {
    echo "\n@@RESULT@@" . json_encode(['alert' => $_SESSION['alert_message'] ?? null, 'type' => $_SESSION['alert_type'] ?? null]) . "\n";
});
if (!empty($case['script'])) {
    // A whole agent page or AJAX endpoint (agent/vendors.php, agent/ajax.php, ...): prints what the page prints.
    $_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/' . $case['script'];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_POST = []; $_GET = $case['get'] ?? []; $_REQUEST = $_GET;
    chdir("$app/" . dirname($case['script']));
    require "$app/" . $case['script'];
    exit;
}
if (!empty($case['modal'])) {
    // A pop-up (agent/modals/...): the real file through the real modal_header.php / check_login.php; prints {"content": html}.
    $_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/agent/modals/' . $case['modal'];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_POST = []; $_GET = $case['get'] ?? [];
    $_COOKIE['PHPSESSID'] = 'svtest';
    register_shutdown_function(static function () { echo "\n"; });
    chdir("$app/agent/modals/" . dirname($case['modal']));
    require "$app/agent/modals/" . $case['modal'];
    exit;
}
ob_start();
require "$app/config.php";
require "$app/functions.php";
require "$app/includes/load_user_session.php";
require "$app/includes/load_company_settings.php";
require "$app/includes/load_global_settings.php";
define('FROM_POST_HANDLER', true);
require_once "$app/includes/post_guard.php";   // the same guard agent/post.php loads first (also defines requireFilledPost)
foreach ((array) $case['handler'] as $h) {
    require_once "$app/agent/post/$h.php";
}
