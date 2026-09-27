<?php

/*
 * Kiosk media bytes: GET|HEAD /kiosk/media.php?m=<media_id>&r=<revision_id>[&dl=1] (P3 spec §5.9).
 *
 * PHP only AUTHORIZES; nginx streams the file through the internal /uploads/training/ location
 * (X-Accel-Redirect), so Range/206 works for iPad video. Any live kiosk session role, checked with
 * touch = false: media never keeps a session alive (idle is refreshed only by real activity).
 * Every refusal is the same plain 404.
 */

$KIOSK_CSP_PROFILE = 'media';
require __DIR__ . '/includes/bootstrap.php';

use ITFlow\Training\Kiosk\Core\KioskAuth;
use ITFlow\Training\Kiosk\Learn\KioskMediaAccess;
use ITFlow\Training\Media\MediaStore;

function kiosk_media_not_found(): never
{
    header_remove('Expires');
    header_remove('Pragma');
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo 'Not found';
    exit;
}

function kiosk_media_ascii(string $name): string
{
    $ascii = preg_replace('/[^A-Za-z0-9._ -]+/', '', $name) ?? '';
    $ascii = trim(preg_replace('/\s+/', ' ', $ascii) ?? '', ' .');
    return $ascii === '' ? 'download' : substr($ascii, 0, 150);
}

$km_method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($km_method !== 'GET' && $km_method !== 'HEAD') {
    header('Allow: GET, HEAD');
    kiosk_media_not_found();
}
$km_m = $_GET['m'] ?? null;
$km_r = $_GET['r'] ?? null;
if (!is_string($km_m) || preg_match('/^[1-9][0-9]{0,9}$/D', $km_m) !== 1
    || !is_string($km_r) || preg_match('/^(0|[1-9][0-9]{0,9})$/D', $km_r) !== 1) {
    kiosk_media_not_found();
}
$km_download = ($_GET['dl'] ?? null) === '1';

try {
    $km_s = KioskAuth::session($kctx, KioskAuth::ROLES, false);
    $kctx = $kctx->withKsess($km_s);
    $km_store = new MediaStore($kctx->core);
    $km_row = $km_store->get((int) $km_m);
    if ($km_row === null
        || preg_match(MediaStore::PATH_RE, (string) $km_row['media_path']) !== 1
        || !$km_store->fileOk($km_row)
        || !KioskMediaAccess::canServe($kctx, $km_row, (int) $km_r, $km_download)) {
        kiosk_media_not_found();
    }
} catch (\ITFlow\Training\Kiosk\Core\KioskAuthException) {
    KioskAuth::clearSessionCookie();
    kiosk_media_not_found();
} catch (\Throwable $e) {
    error_log('Kiosk media: ' . get_class($e));
    kiosk_media_not_found();
}

$km_ext = (string) $km_row['media_ext'];
$km_type = MediaStore::MIME_BY_EXT[$km_ext] ?? null;
if ($km_type === null) {
    kiosk_media_not_found();
}
if ($km_ext === 'txt' || $km_ext === 'csv' || $km_ext === 'vtt') {   // vtt: a video's caption file (Captions)
    $km_type .= '; charset=utf-8';
}
$km_attachment = $km_download || in_array((string) $km_row['media_kind'], ['pdf', 'file'], true);

header_remove('Expires');
header_remove('Pragma');
header('Content-Type: ' . $km_type);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
if ($km_attachment) {
    $km_name = (string) ($km_row['media_original_name'] ?? '');
    $km_base = $km_name === '' ? ('training-' . (int) $km_row['media_id']) : pathinfo($km_name, PATHINFO_FILENAME);
    $km_base = preg_replace('/[\x00-\x1F\x7F"\\\\\/]+/u', '', mb_scrub($km_base, 'UTF-8')) ?? '';
    if (trim($km_base) === '') {
        $km_base = 'training-' . (int) $km_row['media_id'];
    }
    $km_full = mb_substr($km_base, 0, 150, 'UTF-8') . '.' . $km_ext;
    $km_ascii = kiosk_media_ascii(pathinfo($km_full, PATHINFO_FILENAME)) . '.' . $km_ext;
    header('Content-Disposition: attachment; filename="' . $km_ascii . '"; filename*=UTF-8\'\'' . rawurlencode($km_full));
} else {
    header('Content-Disposition: inline');
}
header('X-Accel-Redirect: /uploads/training/' . $km_row['media_path']);
exit;
