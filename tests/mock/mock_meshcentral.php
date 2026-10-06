<?php
/* Minimal stand-in for a MeshCentral server for tests/endpoint_agent_authz.php: implements ONLY what RivetIT calls, GET /health.ashx.
 * MOCK_MESH_MODE=ok (default) answers 200, error answers 500, slow stalls past RivetIT's timeout. Never a real MeshCentral. */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$mode = getenv('MOCK_MESH_MODE') ?: 'ok';
if ($path === '/health.ashx') {
    if ($mode === 'slow') { sleep(12); }
    if ($mode === 'error') { http_response_code(500); echo 'unhealthy'; return true; }
    echo 'OK';
    return true;
}
http_response_code(404);
echo 'not found';
return true;
