<?php
// Router for `php -S` in the employee portal tests: the same /api/v1/ mapping the nginx routes file does (deploy/templates/rivetit-locations.conf).
$path = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
$root = dirname(__DIR__, 2);
if (str_starts_with($path, '/api/v1/') && !is_file($root . $path)) {
    $_SERVER['SCRIPT_NAME'] = '/api/v1/index.php';
    $_SERVER['SCRIPT_FILENAME'] = $root . '/api/v1/index.php';
    require $root . '/api/v1/index.php';
    return true;
}
return false;
