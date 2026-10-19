<?php
// Router for `php -S` in tests/rmm_ui_meshwizard.php: a stand-in "MeshCentral" that answers /health.ashx with 200 and everything else with 404.
$p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($p === '/health.ashx') { header('Content-Type: text/plain'); echo 'ok'; return true; }
http_response_code(404);
echo 'not found';
return true;
