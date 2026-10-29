<?php
// php -S router for the LMS API tests: mimics the nginx rewrite of /api/v1/* to api/v1/index.php.
$p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($p, '/api/v1/')) {
    $_SERVER['SCRIPT_NAME'] = '/api/v1/index.php';
    $_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/../api/v1/index.php';
    chdir(__DIR__ . '/../api/v1');
    require __DIR__ . '/../api/v1/index.php';
    return true;
}
return false;
