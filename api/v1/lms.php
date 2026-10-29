<?php
// LMS API for integration clients (the QMS). Reached only from api/v1/index.php, only with a valid integration access token:
//
//   GET  /api/v1/lms/courses                          lms:read              published courses (cursor paging, updated_since)
//   GET  /api/v1/lms/courses/{id}                     lms:read
//   POST /api/v1/lms/assignments                      lms:assign            Idempotency-Key required; 201 new, 200 reused
//   GET  /api/v1/lms/assignments                      lms:read              filters: employee, odoo_employee_id, course, status, external_system, external_ref
//   GET  /api/v1/lms/assignments/{id}                 lms:read
//   GET  /api/v1/lms/completions                      lms:completions:read  filters: employee, odoo_employee_id, course, assignment, status
//   GET  /api/v1/lms/completions/{id}                 lms:completions:read
//   GET  /api/v1/lms/completions/{id}/evidence        lms:evidence:read     the stored scan, streamed; 404 when none or not servable
//
// Errors are {"error":{"code","message","correlation_id"}}; X-Correlation-Id is echoed. The caller is the integration client in
// $integration_client / $integration_scopes (set by index.php); there is no user and no session. Missing and forbidden ids are both 404.
defined('FROM_API') || die();

use ITFlow\Integration\ApiResponse;
use ITFlow\Integration\Idempotency;
use ITFlow\Integration\LmsApi;
use ITFlow\Integration\LmsApiException;
use ITFlow\Integration\LmsAssigner;
use ITFlow\Training\Media\MediaStore;

if (!isset($integration_client, $integration_scopes)) {
    ApiResponse::error(401, 'unauthorized', 'Authentication required.');
}

// Business dates and "today" use the company time zone, like the web app (the API router does not set it).
$lms_tz = mysqli_fetch_assoc(mysqli_query($mysqli, 'SELECT config_timezone FROM settings WHERE company_id = 1'));
if (!empty($lms_tz['config_timezone']) && in_array($lms_tz['config_timezone'], timezone_identifiers_list(), true)) {
    date_default_timezone_set($lms_tz['config_timezone']);
    mysqli_query($mysqli, "SET time_zone = '" . (new DateTime('now', new DateTimeZone($lms_tz['config_timezone'])))->format('P') . "'");
}

$lms_need = static function (string $scope) use ($integration_scopes): void {
    if (!in_array($scope, $integration_scopes, true)) {
        ApiResponse::error(403, 'insufficient_scope', 'The access token does not carry the scope ' . $scope . '.');
    }
};
$lms_notfound = static fn() => ApiResponse::error(404, 'not_found', 'Not found.');

if (intval($GLOBALS['config_module_enable_training'] ?? 0) !== 1) {
    ApiResponse::error(503, 'module_disabled', 'The Training module is not enabled on this server.');
}

$lms_res = $segments[1] ?? '';
$lms_id = $segments[2] ?? null;
$lms_leaf = $segments[3] ?? null;
if (count($segments) > 4 || ($lms_leaf !== null && !($lms_res === 'completions' && $lms_leaf === 'evidence'))) {
    $lms_notfound();
}
$lms_api = new LmsApi($mysqli);

try {
    switch ($lms_res) {
        case 'courses':
            if ($method !== 'GET') {
                ApiResponse::error(405, 'method_not_allowed', 'Use GET.', ['Allow' => 'GET']);
            }
            $lms_need('lms:read');
            if ($lms_id === null) {
                ApiResponse::json(200, $lms_api->courses($_GET));
            }
            $c = $lms_api->course((string) $lms_id);
            $c === null ? $lms_notfound() : ApiResponse::json(200, ['data' => $c]);

        case 'assignments':
            if ($method === 'POST' && $lms_id === null) {
                $lms_need('lms:assign');
                // body (bounded read)
                $lms_ct = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
                if ($lms_ct !== 'application/json') {
                    ApiResponse::error(415, 'unsupported_media_type', 'Send application/json.');
                }
                $lms_raw = (string) file_get_contents('php://input', false, null, 0, 65537);
                if (strlen($lms_raw) > 65536) {
                    ApiResponse::error(413, 'payload_too_large', 'The request body is limited to 64 KB.');
                }
                $lms_body = json_decode($lms_raw, true);
                if (!is_array($lms_body) || ($lms_body !== [] && array_is_list($lms_body))) {
                    ApiResponse::error(400, 'invalid_json', 'The body must be a JSON object.');
                }
                $lms_key = (string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '');
                if ($lms_key === '') {
                    ApiResponse::error(400, 'idempotency_key_required', 'POST requires an Idempotency-Key header.');
                }
                if (!Idempotency::keyValid($lms_key)) {
                    ApiResponse::error(400, 'idempotency_key_invalid', 'Idempotency-Key must be 8 to 128 characters (letters, digits . _ : ~ = -).');
                }
                $lms_cid = (int) $integration_client['client_id'];
                $lms_hash = Idempotency::requestHash('POST', '/lms/assignments', $lms_body);
                $lms_state = Idempotency::begin($mysqli, $lms_cid, $lms_key, $lms_hash);
                if ($lms_state['state'] === 'reuse') {
                    ApiResponse::error(422, 'idempotency_key_reuse', 'This Idempotency-Key was already used with a different request.');
                }
                if ($lms_state['state'] === 'busy') {
                    ApiResponse::error(409, 'idempotency_in_progress', 'A request with this Idempotency-Key is still being processed; retry shortly.', ['Retry-After' => '1']);
                }
                if ($lms_state['state'] === 'replay') {
                    header('Idempotent-Replay: true');
                    http_response_code((int) $lms_state['status']);
                    header('Content-Type: application/json; charset=utf-8');
                    header('X-Correlation-Id: ' . ApiResponse::correlationId());
                    header('Cache-Control: no-store');
                    echo $lms_state['body'];
                    exit;
                }
                // state === 'new': we own the claim. Anything but a success releases it so the same key can be retried.
                $lms_done = false;
                try {
                    $lms_r = (new LmsAssigner($mysqli, $lms_api))->assign($lms_body, $integration_client, $lms_key);
                    $lms_out = ['data' => $lms_r['assignment'] + ['reused' => $lms_r['reused'], 'source' => $lms_body['source'] ?? null]];
                    $lms_json = json_encode($lms_out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    Idempotency::finish($mysqli, $lms_cid, $lms_key, $lms_r['status'], $lms_json);
                    $lms_done = true;
                    http_response_code($lms_r['status']);
                    header('Content-Type: application/json; charset=utf-8');
                    header('X-Correlation-Id: ' . ApiResponse::correlationId());
                    header('Cache-Control: no-store');
                    if ($lms_r['status'] === 201) {
                        header('Location: /api/v1/lms/assignments/' . $lms_r['assignment']['id']);
                    }
                    echo $lms_json;
                    exit;
                } finally {
                    if (!$lms_done) {
                        Idempotency::release($mysqli, $lms_cid, $lms_key);
                    }
                }
            }
            if ($method !== 'GET') {
                ApiResponse::error(405, 'method_not_allowed', 'Use GET' . ($lms_id === null ? ' or POST' : '') . '.', ['Allow' => $lms_id === null ? 'GET, POST' : 'GET']);
            }
            $lms_need('lms:read');
            if ($lms_id === null) {
                ApiResponse::json(200, $lms_api->assignments($_GET));
            }
            if (preg_match('/^asg_([1-9][0-9]{0,9})$/D', (string) $lms_id, $m) !== 1) {
                $lms_notfound();
            }
            $a = $lms_api->assignment((int) $m[1]);
            $a === null ? $lms_notfound() : ApiResponse::json(200, ['data' => $a]);

        case 'completions':
            if ($method !== 'GET') {
                ApiResponse::error(405, 'method_not_allowed', 'Use GET.', ['Allow' => 'GET']);
            }
            if ($lms_leaf === 'evidence') {
                $lms_need('lms:evidence:read');
            } else {
                $lms_need('lms:completions:read');
            }
            if ($lms_id === null) {
                ApiResponse::json(200, $lms_api->completions($_GET));
            }
            if (preg_match('/^cmp_([1-9][0-9]{0,9})$/D', (string) $lms_id, $m) !== 1) {
                $lms_notfound();
            }
            if ($lms_leaf === null) {
                $c = $lms_api->completion((int) $m[1]);
                $c === null ? $lms_notfound() : ApiResponse::json(200, ['data' => $c]);
            }
            // evidence: streamed through PHP after the object check (uploads/training/evidence is not web-served)
            $lms_media = $lms_api->evidenceMedia((int) $m[1]);
            $lms_ext = $lms_media === null ? '' : (string) $lms_media['media_ext'];
            $lms_type = MediaStore::MIME_BY_EXT[$lms_ext] ?? null;
            if ($lms_media === null || $lms_type === null || !in_array($lms_ext, ['pdf', 'jpg', 'png'], true)) {
                $lms_notfound();
            }
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            header('Content-Type: ' . $lms_type);
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, no-store');
            header("Content-Security-Policy: default-src 'none'; sandbox");
            header('Referrer-Policy: no-referrer');
            header('X-Correlation-Id: ' . ApiResponse::correlationId());
            header('Content-Length: ' . (int) $lms_media['media_bytes']);
            header('Content-Disposition: attachment; filename="completion-' . (int) $m[1] . '-evidence.' . $lms_ext . '"');
            readfile((string) $lms_media['_absolute_path']);
            exit;

        default:
            $lms_notfound();
    }
} catch (LmsApiException $e) {
    ApiResponse::error($e->http, $e->errCode, $e->getMessage(), $e->headers);
} catch (\Throwable $e) {
    error_log('LMS API: ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    ApiResponse::error(500, 'server_error', 'The request could not be completed.');
}
