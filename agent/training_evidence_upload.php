<?php

/*
 * Training evidence scans: POST /agent/training_evidence_upload.php (multipart), Phase 2 spec §4.3.
 *
 * One file per request (field `file`): a signed sign-in sheet, a practical checklist or an outside
 * training card. The response uses the envelope of agent/training_ajax.php:
 *   {"ok":true,"data":{"media":{id, kind:'evidence', mime, ext, bytes, original_name, preview_url}, "attach_token":…}}
 *   {"ok":false,"error":{code,message,fields}}
 * The attach token (Core\Scratch, bound to this user, 8 hours) is what a record, session or
 * evaluation accepts as `evidence_token`; a media id is never accepted from the client.
 *
 * Order of checks (each one before anything more expensive):
 *   1 POST only                                                    405
 *   2 module on, module_training >= 2                              404 module_disabled / 403 forbidden
 *   3 CONTENT_LENGTH <= evidence cap + 64 KB                       413 too_large
 *   4 CSRF: X-CSRF-Token header (csrf_token field as a fallback)   403 csrf
 *   5 Sec-Fetch-Site, if the browser sent it, must be same-origin  403
 *   6 session_write_close()
 *   7 $_FILES error codes                                          413 / 400 / 500
 *   8 Records\EvidenceStore::ingestUpload: per-file cap, type sniffed from the bytes (PDF, JPEG,
 *     PNG; WebP/GIF re-encoded; HEIC refused with the iPhone guidance), images re-encoded by GD
 *     (EXIF dropped), stored under uploads/training/evidence/ (nginx: deny all)   413 / 415 / 422
 */

ob_start();
require_once "../config.php";
require_once "../functions.php";
require_once "../includes/check_login.php";
ob_end_clean();

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Access;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\RecordsSettings;
use ITFlow\Training\Media\MediaException;
use ITFlow\Training\Records\EvidenceStore;

ini_set('memory_limit', '384M');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function tr_evup_send(int $status, array $body): never
{
    if (Db::depth() > 0) {
        error_log('Training evidence upload: response sent with an open transaction');
    }
    http_response_code($status);
    try {
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    } catch (\JsonException $e) {
        error_log('Training evidence upload: response encode failed: ' . $e->getMessage());
        http_response_code(500);
        echo '{"ok":false,"error":{"code":"server","message":"Something went wrong. Try again.","fields":{}}}';
    }
    exit;
}

function tr_evup_error(int $status, string $code, string $message, array $fields = []): never
{
    tr_evup_send($status, ['ok' => false, 'error' => ['code' => $code, 'message' => $message, 'fields' => $fields === [] ? new \stdClass() : $fields]]);
}

try {
    // 1
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
        header('Allow: POST');
        throw new ApiException(405, 'validation', 'Method not allowed.');
    }
    // 2
    Access::api(2);
    // 3
    $tr_cap = RecordsSettings::fromDb($mysqli)->evidenceMaxBytes;
    $tr_cap_mb = intdiv($tr_cap, 1048576);
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $tr_cap + 65536) {
        throw new ApiException(413, 'too_large', "That scan is larger than the $tr_cap_mb MB limit for evidence.");
    }
    // 4
    $tr_token = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($tr_token === '' && is_string($_POST['csrf_token'] ?? null)) {
        $tr_token = $_POST['csrf_token'];
    }
    $tr_expected = (string) ($_SESSION['csrf_token'] ?? '');
    if ($tr_token === '' || $tr_expected === '' || !hash_equals($tr_expected, $tr_token)) {
        throw new ApiException(403, 'csrf', 'Your session changed. Try again.');
    }
    // 5
    $tr_site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? null;
    if ($tr_site !== null && $tr_site !== 'same-origin') {
        throw new ApiException(403, 'forbidden', 'Cross-site request refused.');
    }
    // 6
    session_write_close();

    // 7
    $tr_file = $_FILES['file'] ?? null;
    if (!is_array($tr_file) || !is_int($tr_file['error'] ?? null) || !is_string($tr_file['tmp_name'] ?? null)) {
        throw new ApiException(400, 'validation', 'Choose a file to upload.', ['file' => 'Required.']);
    }
    switch ($tr_file['error']) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            throw new ApiException(413, 'too_large', "That scan is larger than the $tr_cap_mb MB limit for evidence.");
        case UPLOAD_ERR_NO_FILE:
            throw new ApiException(400, 'validation', 'Choose a file to upload.', ['file' => 'Required.']);
        default:
            error_log('Training evidence upload: $_FILES error ' . intval($tr_file['error']));
            throw new ApiException(500, 'server', 'The upload did not arrive completely. Try again.');
    }
    if (!is_uploaded_file($tr_file['tmp_name'])) {
        throw new ApiException(400, 'validation', 'Choose a file to upload.', ['file' => 'Required.']);
    }

    // 8
    $tr_ctx = Access::ctx($mysqli);
    $tr_res = (new EvidenceStore($tr_ctx))->ingestUpload($tr_file['tmp_name'], (string) ($tr_file['name'] ?? ''));
    $tr_m = $tr_res['media'];
    tr_evup_send(200, ['ok' => true, 'data' => [
        'media' => [
            'id' => (int) $tr_m['media_id'],
            'kind' => 'evidence',
            'mime' => (string) $tr_m['media_mime'],
            'ext' => (string) $tr_m['media_ext'],
            'bytes' => (int) $tr_m['media_bytes'],
            'original_name' => $tr_m['media_original_name'] === null ? null : (string) $tr_m['media_original_name'],
            'preview_url' => EvidenceStore::previewUrl($tr_res['attach_token']),
        ],
        'attach_token' => $tr_res['attach_token'],
    ]]);

} catch (ApiException $e) {
    tr_evup_error($e->http, $e->errCode, $e->getMessage(), $e->fields);
} catch (MediaException $e) {
    tr_evup_error($e->http, $e->errCode, $e->getMessage(), $e->fields);
} catch (\mysqli_sql_exception $e) {
    $errno = (int) $e->getCode();
    if ($errno === 1213 || $errno === 1205) {
        tr_evup_error(409, 'busy', 'Someone else is saving; try again.');
    }
    error_log('Training evidence upload: ' . get_class($e) . ': ' . $e->getMessage());
    tr_evup_error(500, 'server', 'Something went wrong. Try again.');
} catch (\Throwable $e) {
    error_log('Training evidence upload: ' . get_class($e) . ': ' . $e->getMessage());
    tr_evup_error(500, 'server', 'Something went wrong. Try again.');
}
