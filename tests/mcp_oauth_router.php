<?php
/*
 * Router script for `php -S` in the Remote MCP OAuth tests: does what the nginx/Apache rules do for /mcp and the
 * /.well-known/oauth-* metadata paths (hand them to mcp_server/index.php). Everything else (/login.php, /oauth/*.php, ...)
 * is served as a normal file. Test use only; never served by a real web server.
 */
$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
if ($path === '/mcp' || $path === '/.well-known/oauth-protected-resource' || $path === '/.well-known/oauth-authorization-server') {
    $_SERVER['SCRIPT_NAME'] = '/mcp_server/index.php';
    require dirname(__DIR__) . '/mcp_server/index.php';
    return true;
}
return false;
