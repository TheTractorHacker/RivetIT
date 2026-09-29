<?php

/*
 * Knowledge Base media for the training kiosk: GET /kiosk/kb_media.php?a=<article>&f=<file> (inline image),
 * ?att=<attachment id>, or ?f=<file> (the shared editor pool), [&download=1]. Same query shape as
 * client/kb_media.php. Only a live learner session may read it, and access is re-derived per request from
 * that learner's department (KbAccess::scopeSql): no matching visible article row is a 404 in every branch,
 * because /uploads/kb/<id>/ outlives the article row. A signature parameter is refused, not ignored.
 */

$KIOSK_CSP_PROFILE = 'media';
require __DIR__ . '/includes/bootstrap.php';

use ITFlow\Training\Kiosk\Core\KioskAuth;
use ITFlow\Training\Kiosk\Learn\KbAccess;

define('FROM_KB_MEDIA', true);
require_once dirname(__DIR__) . '/agent/includes/kb_media_serve.php';

function kiosk_kb_param(string $key): ?string
{
    return isset($_GET[$key]) && is_string($_GET[$key]) ? $_GET[$key] : null;
}

$km_method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (($km_method !== 'GET' && $km_method !== 'HEAD') || intval($config_module_enable_kb ?? 0) !== 1) {
    kbMediaFail(404, 'Not found');
}
if (isset($_GET['s']) || isset($_GET['p']) || isset($_GET['e'])) {
    kbMediaFail(404, 'Not found');
}

try {
    $km_s = KioskAuth::session($kctx, ['learner'], false);
    $kctx = $kctx->withKsess($km_s);
    $km_client = KbAccess::clientId($kctx->db(), $kctx->contactId());
} catch (\Throwable) {
    kbMediaFail(404, 'Not found');
}
if ($km_client === null) {
    kbMediaFail(404, 'Not found');
}
$km_scope = KbAccess::scopeSql($km_client);

$att_param = kiosk_kb_param('att');
$a_param = kiosk_kb_param('a');
$f_param = kiosk_kb_param('f');
$force_download = !empty($_GET['download']);
$mysqli = $kctx->db();

if ($att_param !== null) {
    if ($a_param !== null || $f_param !== null || intval($att_param) < 1) {
        kbMediaFail(404, 'Not found');
    }
    $ref = intval($att_param);
    $row = mysqli_fetch_assoc(mysqli_query(
        $mysqli,
        "SELECT kb_article_attachments.kb_article_attachment_name,
                kb_article_attachments.kb_article_attachment_reference_name,
                kb_articles.kb_article_id
         FROM kb_article_attachments
         INNER JOIN kb_articles ON kb_articles.kb_article_id = kb_article_attachments.kb_article_attachment_kb_article_id
         WHERE kb_article_attachments.kb_article_attachment_id = $ref AND $km_scope
         LIMIT 1"
    ));
    if (!$row || !kbMediaValidReferenceName((string) $row['kb_article_attachment_reference_name'])) {
        kbMediaFail(404, 'Not found');
    }
    kbMediaServe(
        kbMediaResolve(intval($row['kb_article_id']) . '/' . $row['kb_article_attachment_reference_name']),
        (string) $row['kb_article_attachment_name'],
        $force_download
    );
} elseif ($a_param !== null) {
    $ref = intval($a_param);
    if ($f_param === null || $ref < 1 || !kbMediaValidReferenceName($f_param)) {
        kbMediaFail(404, 'Not found');
    }
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT kb_article_id FROM kb_articles WHERE kb_article_id = $ref AND $km_scope LIMIT 1"));
    if (!$row) {
        kbMediaFail(404, 'Not found');
    }
    kbMediaServe(kbMediaResolve($ref . '/' . $f_param), $f_param, $force_download);
} elseif ($f_param !== null) {
    // The flat editor pool has no owning row: served only when an article THIS learner may open references the file.
    if (!kbMediaValidReferenceName($f_param)) {
        kbMediaFail(404, 'Not found');
    }
    $like_canonical = mysqli_real_escape_string($mysqli, addcslashes(\ITFlow\KB\MediaUrlRewriter::CANONICAL_PATH . '?f=' . $f_param, '%_'));
    $like_legacy = mysqli_real_escape_string($mysqli, addcslashes('/uploads/kb/' . $f_param, '%_'));
    $row = mysqli_fetch_assoc(mysqli_query(
        $mysqli,
        "SELECT 1 AS ok FROM kb_articles
         WHERE $km_scope AND (kb_article_content LIKE '%$like_canonical%' OR kb_article_content LIKE '%$like_legacy%')
         LIMIT 1"
    ));
    if (!$row) {
        kbMediaFail(404, 'Not found');
    }
    kbMediaServe(kbMediaResolve($f_param), $f_param, $force_download);
}

kbMediaFail(404, 'Not found');
