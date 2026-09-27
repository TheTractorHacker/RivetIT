<?php

/*
 * Training media bytes: GET|HEAD /agent/training_media.php?m=<media_id>[&dl=1] (spec §4.5).
 *
 * PHP only AUTHORIZES; nginx serves the bytes. After the checks below this script answers with
 * headers and an X-Accel-Redirect to the `internal` location /uploads/training/ (live vhost,
 * docker/nginx.conf, deploy template), so nginx streams the file with sendfile and handles
 * Range/206 itself - a 95 MB video never holds a PHP-FPM worker, and iOS gets the byte ranges
 * it insists on. /uploads/training/ is unreachable from outside: a direct request is a 404.
 *
 *   1 lean bootstrap (config, functions, check_login) and session_write_close() at once
 *   2 module off -> 404
 *   3 GET / HEAD only
 *   4 the row exists, its media_path matches MediaStore::PATH_RE and the file is on disk
 *   5 MediaAccess::canServe() - evidence never; authors any other kind; readers only what a
 *     published revision (or a live path cover) uses
 *   Every refusal is the same plain 404, so the endpoint never confirms that an id exists.
 */

ob_start();
require_once "../config.php";
require_once "../functions.php";
require_once "../includes/check_login.php";
ob_end_clean();
session_write_close();

use ITFlow\Training\Core\Access;
use ITFlow\Training\Media\MediaAccess;
use ITFlow\Training\Media\MediaStore;

function tr_media_not_found(): never
{
    header_remove('Expires');
    header_remove('Pragma');
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    echo 'Not found';
    exit;
}

/** "HazCom SDS (v3).pdf" -> ASCII fallback for filename= : letters, digits, dot, space, dash, underscore. */
function tr_media_ascii_name(string $name): string
{
    $ascii = preg_replace('/[^A-Za-z0-9._ -]+/', '', $name) ?? '';
    $ascii = trim(preg_replace('/\s+/', ' ', $ascii) ?? '', ' .');
    return $ascii === '' ? 'download' : substr($ascii, 0, 150);
}

if (!Access::enabled()) {
    tr_media_not_found();
}
$tr_method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($tr_method !== 'GET' && $tr_method !== 'HEAD') {
    header('Allow: GET, HEAD');
    http_response_code(405);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    exit;
}
$tr_m = $_GET['m'] ?? null;
if (!is_string($tr_m) || preg_match('/^[1-9][0-9]{0,9}$/D', $tr_m) !== 1 || Access::level() < 1) {
    tr_media_not_found();
}
$tr_download = ($_GET['dl'] ?? null) === '1';

try {
    $tr_ctx = Access::ctx($mysqli);
    $tr_store = new MediaStore($tr_ctx);
    $tr_row = $tr_store->get((int) $tr_m);
    if ($tr_row === null
        || preg_match(MediaStore::PATH_RE, (string) $tr_row['media_path']) !== 1
        || !$tr_store->fileOk($tr_row)
        || !MediaAccess::canServe($tr_ctx, $tr_row, $tr_download)) {
        tr_media_not_found();
    }
} catch (\Throwable $e) {
    error_log('Training media: ' . get_class($e) . ': ' . $e->getMessage());
    tr_media_not_found();
}

$tr_ext = (string) $tr_row['media_ext'];
$tr_type = MediaStore::MIME_BY_EXT[$tr_ext] ?? null;
if ($tr_type === null) {
    tr_media_not_found();
}
if ($tr_ext === 'txt' || $tr_ext === 'csv' || $tr_ext === 'vtt') {   // vtt: a video's caption file (Captions)
    $tr_type .= '; charset=utf-8';
}
$tr_kind = (string) $tr_row['media_kind'];
$tr_attachment = $tr_download || in_array($tr_kind, ['pdf', 'file'], true);

// session.cache_limiter=nocache added these at session start; this response is cacheable (private).
header_remove('Expires');
header_remove('Pragma');
header('Content-Type: ' . $tr_type);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=86400');
if ($tr_attachment) {
    $tr_name = (string) ($tr_row['media_original_name'] ?? '');
    $tr_base = $tr_name === '' ? ('training-' . (int) $tr_row['media_id']) : pathinfo($tr_name, PATHINFO_FILENAME);
    $tr_base = preg_replace('/[\x00-\x1F\x7F"\\\\\/]+/u', '', mb_scrub($tr_base, 'UTF-8')) ?? '';
    if (trim($tr_base) === '') {
        $tr_base = 'training-' . (int) $tr_row['media_id'];
    }
    $tr_full = mb_substr($tr_base, 0, 150, 'UTF-8') . '.' . $tr_ext;   // the stored type's extension, always
    $tr_ascii = tr_media_ascii_name(pathinfo($tr_full, PATHINFO_FILENAME)) . '.' . $tr_ext;
    header('Content-Disposition: attachment; filename="' . $tr_ascii . '"; filename*=UTF-8\'\'' . rawurlencode($tr_full));
} else {
    header('Content-Disposition: inline');
}
header('X-Accel-Redirect: /uploads/training/' . $tr_row['media_path']);
exit;
