<?php
// Router for `php -S` in tests/mobile_api.php: sends /api/v1/* through the same front controller nginx uses.
$mobile_api_uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (strpos($mobile_api_uri, '/api/v1/') === 0) {
    require __DIR__ . '/../api/v1/index.php';
    return true;
}
return false;
