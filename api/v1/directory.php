<?php
// Directory API for integration clients (the QMS). Reached only from api/v1/index.php, only with a valid integration access token:
//
//   GET /api/v1/departments        directory:read     departments (clients), cursor paging, updated_since
//   GET /api/v1/departments/{id}   directory:read
//   GET /api/v1/employees          directory:read     employees (contacts), cursor paging, updated_since; filters: department_id, odoo_employee_id
//   GET /api/v1/employees/{id}     directory:read
//
// The scope is exactly `directory:read`; no lms:* scope grants it and it grants no lms:* route. Deactivated records stay in the feed with
// active = false (tombstones). DEPARTMENT SCOPE: a client sees only the departments it was granted and their employees; anything outside
// answers exactly like a record that does not exist (404, or absent from a list), never 403. See src/Integration/DirectoryApi.php.
//
// Errors are {"error":{"code","message","correlation_id"}}; X-Correlation-Id is echoed. The caller is the integration client in
// $integration_client / $integration_scopes (set by index.php); there is no user and no session.
defined('FROM_API') || die();

use ITFlow\Integration\ApiResponse;
use ITFlow\Integration\DirectoryApi;
use ITFlow\Integration\IntegrationScope;
use ITFlow\Integration\LmsApiException;

if (!isset($integration_client, $integration_scopes)) {
    ApiResponse::error(401, 'unauthorized', 'Authentication required.');
}

// Timestamps are company-local in the database; the LMS routes set the company time zone the same way.
$dir_tz = mysqli_fetch_assoc(mysqli_query($mysqli, 'SELECT config_timezone FROM settings WHERE company_id = 1'));
if (!empty($dir_tz['config_timezone']) && in_array($dir_tz['config_timezone'], timezone_identifiers_list(), true)) {
    date_default_timezone_set($dir_tz['config_timezone']);
    mysqli_query($mysqli, "SET time_zone = '" . (new DateTime('now', new DateTimeZone($dir_tz['config_timezone'])))->format('P') . "'");
}

$dir_notfound = static fn() => ApiResponse::error(404, 'not_found', 'Not found.');

// $segments (index.php) drops empty AND "0" parts, so /employees/0 would read as the list; split the path again, keeping every part.
$dir_parts = explode('/', trim((string) $path, '/'));
$dir_res = $dir_parts[0] ?? '';
$dir_id = $dir_parts[1] ?? null;
if (count($dir_parts) > 2 || !in_array($dir_res, ['employees', 'departments'], true)) {
    $dir_notfound();
}
if ($method !== 'GET') {
    ApiResponse::error(405, 'method_not_allowed', 'Use GET.', ['Allow' => 'GET']);
}
if (!in_array('directory:read', $integration_scopes, true)) {
    ApiResponse::error(403, 'insufficient_scope', 'The access token does not carry the scope directory:read.');
}
if ($dir_id !== null && preg_match('/^[1-9][0-9]{0,9}$/D', (string) $dir_id) !== 1) {
    $dir_notfound();
}

try {
    // Department scope of this client (null = all, [] = none), read per request from the database; every query is filtered by it.
    $dir_api = new DirectoryApi($mysqli, IntegrationScope::allowedDepartmentIds($mysqli, $integration_client));
    if ($dir_id === null) {
        ApiResponse::json(200, $dir_res === 'employees' ? $dir_api->employees($_GET) : $dir_api->departments($_GET));
    }
    $dir_row = $dir_res === 'employees' ? $dir_api->employee((int) $dir_id) : $dir_api->department((int) $dir_id);
    $dir_row === null ? $dir_notfound() : ApiResponse::json(200, ['data' => $dir_row]);
} catch (LmsApiException $e) {
    ApiResponse::error($e->http, $e->errCode, $e->getMessage(), $e->headers);
} catch (\Throwable $e) {
    error_log('Directory API: ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    ApiResponse::error(500, 'server_error', 'The request could not be completed.');
}
