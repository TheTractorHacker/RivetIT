<?php

/*
 * Training evidence scans: GET|HEAD /agent/training_evidence.php?m=<media_id>[&dl=1]  or  ?a=<attach_token>
 * (Phase 2 spec §4.3, §8 "Evidence").
 *
 * Evidence lives under uploads/training/evidence/, which nginx denies outright (not even the
 * internal X-Accel location serves it), so PHP streams the bytes itself after authorizing:
 *
 *   1 lean bootstrap (config, functions, check_login), module on and module_training >= 1,
 *     session_write_close()
 *   2 ?m=<id>: the row must be kind 'evidence' AND Records\EvidenceStore::canServe - referenced by
 *     a completion or evaluation whose person is in the viewer's (fail-closed) scope, or by a
 *     session in scope;
 *     ?a=<token>: the uploader's own attach token (preview before the record is saved)
 *   3 the file is on disk with the recorded size
 *   Every refusal is the same plain 404, so the endpoint never confirms that an id exists.
 *
 * Headers: Content-Type from MediaStore::MIME_BY_EXT, nosniff, private no-store, a sandbox CSP;
 * inline for JPEG/PNG, attachment for PDF (and always with dl=1), with a sanitized file name.
 */

ob_start();
require_once "../config.php";
require_once "../functions.php";
require_once "../includes/check_login.php";
ob_end_clean();
session_write_close();

use ITFlow\Training\Core\Access;
use ITFlow\Training\Media\MediaStore;
use ITFlow\Training\People\Scope;
use ITFlow\Training\Records\EvidenceStore;

function tr_evidence_not_found(): never
{
    header_remove('Expires');
    header_remove('Pragma');
    header_remove('Content-Disposition');
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    echo 'Not found';
    exit;
}

/** Display name -> ASCII fallback for filename= : letters, digits, dot, space, dash, underscore. */
function tr_evidence_ascii_name(string $name): string
{
    $ascii = preg_replace('/[^A-Za-z0-9._ -]+/', '', $name) ?? '';
    $ascii = trim(preg_replace('/\s+/', ' ', $ascii) ?? '', ' .');
    return $ascii === '' ? 'scan' : substr($ascii, 0, 150);
}

if (!Access::enabled() || Access::level() < 1) {
    tr_evidence_not_found();
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
$tr_a = $_GET['a'] ?? null;
$tr_download = ($_GET['dl'] ?? null) === '1';

try {
    $tr_ctx = Access::ctx($mysqli);
    $tr_store = new MediaStore($tr_ctx);
    $tr_row = null;
    if (is_string($tr_m) && preg_match('/^[1-9][0-9]{0,9}$/D', $tr_m) === 1) {
        $tr_row = $tr_store->get((int) $tr_m);
        if ($tr_row === null || !EvidenceStore::canServe($tr_ctx, Scope::forCtx($tr_ctx), $tr_row)) {
            tr_evidence_not_found();
        }
    } elseif (is_string($tr_a) && preg_match(EvidenceStore::TOKEN_RE, $tr_a) === 1) {
        $tr_id = EvidenceStore::previewMediaId($tr_ctx, $tr_a);
        $tr_row = $tr_id === null ? null : $tr_store->get($tr_id);
    }
    if ($tr_row === null
        || (string) $tr_row['media_kind'] !== EvidenceStore::KIND
        || preg_match(MediaStore::PATH_RE, (string) $tr_row['media_path']) !== 1
        || !str_starts_with((string) $tr_row['media_path'], 'evidence/')
        || !$tr_store->fileOk($tr_row)) {
        tr_evidence_not_found();
    }
    $tr_file = $tr_store->absolutePath($tr_row);
} catch (\Throwable $e) {
    error_log('Training evidence: ' . get_class($e) . ': ' . $e->getMessage());
    tr_evidence_not_found();
}

$tr_ext = (string) $tr_row['media_ext'];
$tr_type = MediaStore::MIME_BY_EXT[$tr_ext] ?? null;
if ($tr_type === null || !in_array($tr_ext, ['pdf', 'jpg', 'png'], true)) {
    tr_evidence_not_found();
}
$tr_attachment = $tr_download || $tr_ext === 'pdf';

// session.cache_limiter=nocache added these at session start; this response sets its own.
header_remove('Expires');
header_remove('Pragma');
header('Content-Type: ' . $tr_type);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Content-Length: ' . (int) $tr_row['media_bytes']);
if ($tr_attachment) {
    $tr_name = (string) ($tr_row['media_original_name'] ?? '');
    $tr_base = $tr_name === '' ? ('scan-' . (int) $tr_row['media_id']) : pathinfo($tr_name, PATHINFO_FILENAME);
    $tr_base = preg_replace('/[\x00-\x1F\x7F"\\\\\/;]+/u', '', mb_scrub($tr_base, 'UTF-8')) ?? '';
    if (trim($tr_base) === '') {
        $tr_base = 'scan-' . (int) $tr_row['media_id'];
    }
    $tr_full = mb_substr($tr_base, 0, 150, 'UTF-8') . '.' . $tr_ext;   // the stored type's extension, always
    $tr_ascii = tr_evidence_ascii_name(pathinfo($tr_full, PATHINFO_FILENAME)) . '.' . $tr_ext;
    header('Content-Disposition: attachment; filename="' . $tr_ascii . '"; filename*=UTF-8\'\'' . rawurlencode($tr_full));
} else {
    header('Content-Disposition: inline');
}
if ($tr_method === 'HEAD') {
    exit;
}
while (ob_get_level() > 0) {
    ob_end_clean();
}
readfile($tr_file);
exit;
