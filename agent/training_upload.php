<?php

/*
 * Training uploads: POST /agent/training_upload.php (multipart), spec §4.1.
 *
 * One file per request (field `file`), with `purpose` and the scope ids the purpose needs
 * (course_id [+ lesson_id], bank_id, path_id). The response uses the exact envelope of
 * agent/training_ajax.php: {"ok":true,"data":…} or {"ok":false,"error":{code,message,fields},"data"?}.
 *
 * Order of checks (each one before anything more expensive):
 *    1 POST only                                   405
 *    2 module on, module_training >= 2             404 module_disabled / 403 forbidden
 *    3 CONTENT_LENGTH <= min(post_max_size, 100 MB) 413 too_large  - above post_max_size PHP
 *      silently empties $_POST and $_FILES, which would otherwise look like a CSRF or no-file error
 *    4 CSRF: X-CSRF-Token header (the uploader always sends it; the csrf_token field is a fallback)
 *    5 Sec-Fetch-Site, if the browser sent it, must be same-origin
 *    6 session_write_close() - a long PDF render must not hold the user's session lock
 *    7 $_FILES error codes                           413 / 400 / 500
 *    8 purpose and its scope (UploadPurpose)         422 / 409 archived
 *    9 per-purpose size cap                          413
 *   10 FileValidator::classify (bytes, never the client's name or MIME)   415 / 413 / 422
 * then the purpose's action (ingest, first PDF batch, re-encode, DOCX or CSV import).
 */

ob_start();
require_once "../config.php";
require_once "../functions.php";
require_once "../includes/check_login.php";
ob_end_clean();

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Access;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\TrainingSettings;
use ITFlow\Training\Media\Captions;
use ITFlow\Training\Media\DocxImporter;
use ITFlow\Training\Media\FileValidator;
use ITFlow\Training\Media\ImageProcessor;
use ITFlow\Training\Media\MediaException;
use ITFlow\Training\Media\MediaStore;
use ITFlow\Training\Media\PdfRenderService;
use ITFlow\Training\Media\UploadPurpose;
use ITFlow\Training\Quiz\QuestionImporter;

ini_set('memory_limit', '384M');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

/** Sends the envelope and ends the request. */
function tr_upload_send(int $status, array $body): never
{
    if (Db::depth() > 0) {
        error_log('Training upload: response sent with an open transaction');
    }
    http_response_code($status);
    try {
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    } catch (\JsonException $e) {
        error_log('Training upload: response encode failed: ' . $e->getMessage());
        http_response_code(500);
        echo '{"ok":false,"error":{"code":"server","message":"Something went wrong. Try again.","fields":{}}}';
    }
    exit;
}

function tr_upload_error(int $status, string $code, string $message, array $fields = [], array $data = []): never
{
    $body = ['ok' => false, 'error' => ['code' => $code, 'message' => $message, 'fields' => $fields === [] ? new \stdClass() : $fields]];
    if ($data !== []) {
        $body['data'] = $data;
    }
    tr_upload_send($status, $body);
}

function tr_upload_ini_bytes(string $key): int
{
    $v = trim((string) ini_get($key));
    $n = (int) $v;
    return match (strtolower(substr($v, -1))) {
        'g' => $n * 1073741824,
        'm' => $n * 1048576,
        'k' => $n * 1024,
        default => $n,
    };
}

/**
 * Runs the purpose's action on a classified upload.
 *
 * @param array{name:string, tmp_name:string} $file
 * @param array{kind:string, mime:string, ext:string, warnings:list<string>, info:list<string>, meta:array} $info
 * @param array{course_id:?int, lesson_id:?int, bank_id:?int, path_id:?int} $scope
 */
function tr_upload_dispatch(Ctx $ctx, string $purpose, array $file, array $info, array $scope): array
{
    $store = new MediaStore($ctx);
    $tmp = $file['tmp_name'];
    $name = (string) $file['name'];

    $storeImage = static function () use ($store, $tmp, $name, $purpose): array {
        $img = ImageProcessor::reencodeFile($tmp);
        $m = $store->ingestBytes($img['bytes'], 'image', $img['mime'], $img['ext'], $name,
            ['width' => $img['width'], 'height' => $img['height']], $purpose);
        return [$m, $img['warnings'], $img['info']];
    };
    $mediaOut = static function (array $m, array $warnings, array $info) use ($store): array {
        $row = $store->get((int) $m['media_id']) ?? throw new \RuntimeException('stored media row missing');
        return $store->toApi($row, $warnings, $info);
    };

    switch ($purpose) {
        case 'lesson_document':
            $m = $store->ingestFile($tmp, 'pdf', 'application/pdf', 'pdf', $name, ['page_count' => $info['meta']['page_count'] ?? null], $purpose);
            $pdf = new PdfRenderService($ctx, $store);
            try {
                // One batch now (<= 8 pages / 25 s) so the first pages show at once; the uploader
                // loops pdf_render_next for the rest. A fully rendered duplicate returns done.
                $st = $pdf->renderNext((int) $m['media_id']);
            } catch (MediaException $e) {
                if ($e->errCode !== 'busy') {
                    throw $e;
                }
                $st = $pdf->status((int) $m['media_id']);   // another request is rendering this same PDF
            }
            return ['media' => $mediaOut($m, $info['warnings'], $info['info']), 'pages_ready' => $st['pages_ready'],
                    'page_count' => $st['page_count'], 'done' => $st['done']];

        case 'lesson_video':
            $p = $info['meta']['probe'];
            $m = $store->ingestFile($tmp, 'video', 'video/mp4', 'mp4', $name, [
                'width' => $p['width'], 'height' => $p['height'], 'duration_ms' => $p['duration_ms'],
                'video_codec' => $p['video_codec'], 'audio_codec' => $p['audio_codec'], 'faststart' => $p['faststart'],
            ], $purpose);
            return ['media' => $mediaOut($m, $info['warnings'], $info['info'])];

        case 'lesson_caption':
            // Captions need DB 2.6.98 (media kind 'caption'); before the update the author is told so.
            if (!Captions::schemaReady($ctx->db)) {
                throw new ApiException(409, 'update_required', 'Captions need the latest database update. Ask an administrator to run it (Admin › Update).');
            }
            $cap = $info['meta']['caption'];
            $m = $store->ingestBytes($cap['vtt'], 'caption', Captions::MIME, Captions::EXT, $name, ['duration_ms' => $cap['end_ms']], $purpose);
            return ['media' => $mediaOut($m, $info['warnings'], $info['info']),
                    'caption' => ['cues' => $cap['cues'], 'end_ms' => $cap['end_ms'], 'format' => $cap['format'], 'removed' => $cap['removed']]];

        case 'resource_file':
            if ($info['kind'] === 'image') {
                [$m, $w, $i] = $storeImage();
                return ['media' => $mediaOut($m, $w, $i)];
            }
            if ($info['kind'] === 'pdf') {
                $m = $store->ingestFile($tmp, 'pdf', 'application/pdf', 'pdf', $name, ['page_count' => $info['meta']['page_count'] ?? null], $purpose);
            } else {
                $m = $store->ingestFile($tmp, 'file', $info['mime'], $info['ext'], $name, [], $purpose);
            }
            return ['media' => $mediaOut($m, $info['warnings'], $info['info'])];

        case 'docx_import':
            return DocxImporter::import($tmp, $store);

        case 'csv_import':
            $lang = is_string($_POST['lang'] ?? null) && in_array($_POST['lang'], $ctx->settings->languages, true)
                ? $_POST['lang'] : $ctx->settings->languages[0];
            return (new QuestionImporter($ctx))->previewCsv($tmp, (int) $scope['bank_id'], $lang);

        default:
            if (!in_array($purpose, UploadPurpose::IMAGE_PURPOSES, true)) {
                throw new \LogicException("Training upload: no action for purpose '$purpose'");
            }
            [$m, $w, $i] = $storeImage();
            $out = ['media' => $mediaOut($m, $w, $i)];
            if ($purpose === 'article_image') {
                $out['location'] = MediaStore::url((int) $m['media_id']);   // TinyMCE images_upload_handler
            }
            return $out;
    }
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
    $tr_request_max = TrainingSettings::requestMaxBytes();
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $tr_request_max) {
        throw new ApiException(413, 'too_large', 'That file is larger than the ' . intdiv($tr_request_max, 1048576) . ' MB upload limit.');
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
            $tr_ini = min(tr_upload_ini_bytes('upload_max_filesize') ?: PHP_INT_MAX, $tr_request_max);
            throw new ApiException(413, 'too_large', 'That file is larger than the ' . intdiv($tr_ini, 1048576) . ' MB upload limit.');
        case UPLOAD_ERR_NO_FILE:
            throw new ApiException(400, 'validation', 'Choose a file to upload.', ['file' => 'Required.']);
        default:
            error_log('Training upload: $_FILES error ' . intval($tr_file['error']));
            throw new ApiException(500, 'server', 'The upload did not arrive completely. Try again.');
    }
    if (!is_uploaded_file($tr_file['tmp_name'])) {
        throw new ApiException(400, 'validation', 'Choose a file to upload.', ['file' => 'Required.']);
    }

    // 8
    $tr_purpose = is_string($_POST['purpose'] ?? null) ? $_POST['purpose'] : '';
    UploadPurpose::rule($tr_purpose);
    $tr_ctx = Access::ctx($mysqli);
    $tr_scope = UploadPurpose::checkScope($tr_ctx, $tr_purpose, [
        'course_id' => intval($_POST['course_id'] ?? 0),
        'lesson_id' => intval($_POST['lesson_id'] ?? 0),
        'bank_id' => intval($_POST['bank_id'] ?? 0),
        'path_id' => intval($_POST['path_id'] ?? 0),
    ]);

    // 9
    $tr_cap = UploadPurpose::maxBytes($tr_purpose, $tr_ctx->settings);
    $tr_size = max((int) ($tr_file['size'] ?? 0), (int) @filesize($tr_file['tmp_name']));
    if ($tr_size > $tr_cap) {
        throw new ApiException(413, 'too_large', UploadPurpose::tooLargeMessage($tr_purpose, $tr_cap));
    }

    // 10
    $tr_info = FileValidator::classify($tr_file['tmp_name'], $tr_purpose, (string) ($tr_file['name'] ?? ''), $tr_ctx->settings);

    $tr_data = tr_upload_dispatch($tr_ctx, $tr_purpose, ['name' => (string) ($tr_file['name'] ?? ''), 'tmp_name' => $tr_file['tmp_name']], $tr_info, $tr_scope);
    tr_upload_send(200, ['ok' => true, 'data' => $tr_data === [] ? new \stdClass() : $tr_data]);

} catch (ApiException $e) {
    tr_upload_error($e->http, $e->errCode, $e->getMessage(), $e->fields, $e->data);
} catch (MediaException $e) {
    tr_upload_error($e->http, $e->errCode, $e->getMessage(), $e->fields, $e->warnings === [] ? [] : ['warnings' => $e->warnings]);
} catch (\mysqli_sql_exception $e) {
    $errno = (int) $e->getCode();
    if ($errno === 1062) {
        tr_upload_error(422, 'validation', 'That is already in use.');
    }
    if ($errno === 1213 || $errno === 1205) {
        tr_upload_error(409, 'busy', 'Someone else is saving; try again.');
    }
    error_log('Training upload: ' . get_class($e) . ': ' . $e->getMessage());
    tr_upload_error(500, 'server', 'Something went wrong. Try again.');
} catch (\Throwable $e) {
    error_log('Training upload: ' . get_class($e) . ': ' . $e->getMessage());
    tr_upload_error(500, 'server', 'Something went wrong. Try again.');
}
