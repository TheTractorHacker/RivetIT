<?php

/*
 * Embedded HTML for the training kiosk: GET /kiosk/kb_embed.php?id=<kb_article_embed_id>. The kiosk sibling of
 * client/kb_embed.php (same serve core, same query shape; js/kb_interactive.js builds the frame's src relative, so
 * on /kiosk/kb_article.php it lands here). READ agent/includes/kb_embed_serve.php first: this response echoes
 * HTML no filter has touched, and the only thing making that safe is the `sandbox` directive it sends.
 *
 * Authorization: a live learner session, and the article that owns the embed must pass the learner's department
 * rule (KbAccess::scopeSql), re-run against the database on every request. Every failure is a 404.
 *
 * The 'media' profile sends no page CSP and no fetch-metadata guard (this is a framed document), and it does not
 * touch the device row. The bootstrap's X-Frame-Options: DENY is removed below because the serve core replaces it
 * with `frame-ancestors 'self'`; nothing else here may print before kbEmbedServe().
 */

$KIOSK_CSP_PROFILE = 'media';
require __DIR__ . '/includes/bootstrap.php';

use ITFlow\Training\Kiosk\Core\KioskAuth;
use ITFlow\Training\Kiosk\Learn\KbAccess;

define('FROM_KB_EMBED', true);
require_once dirname(__DIR__) . '/agent/includes/kb_embed_serve.php';

$ke_method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (($ke_method !== 'GET' && $ke_method !== 'HEAD') || intval($config_module_enable_kb ?? 0) !== 1) {
    kbEmbedFail(404, 'Not found');
}

$ke_id_param = isset($_GET['id']) && is_string($_GET['id']) ? $_GET['id'] : null;
$ke_embed_id = $ke_id_param !== null && preg_match('/^[1-9][0-9]{0,9}$/D', $ke_id_param) === 1 ? (int) $ke_id_param : 0;
if ($ke_embed_id < 1) {
    kbEmbedFail(404, 'Not found');
}

try {
    $ke_session = KioskAuth::session($kctx, ['learner'], false);
    $kctx = $kctx->withKsess($ke_session);
    $ke_db = $kctx->db();
    $ke_client = KbAccess::clientId($ke_db, $kctx->contactId());
} catch (\Throwable) {
    kbEmbedFail(404, 'Not found');
}
if ($ke_client === null) {
    kbEmbedFail(404, 'Not found');
}

$ke_article_id = kbEmbedOwningArticleId($ke_db, $ke_embed_id);
if ($ke_article_id < 1) {
    kbEmbedFail(404, 'Not found');
}
try {
    $ke_article = mysqli_fetch_assoc(mysqli_query(
        $ke_db,
        'SELECT kb_article_id FROM kb_articles WHERE kb_article_id = ' . $ke_article_id . ' AND ' . KbAccess::scopeSql($ke_client) . ' LIMIT 1'
    ));
} catch (\Throwable) {
    kbEmbedFail(404, 'Not found');
}
if (!$ke_article) {
    kbEmbedFail(404, 'Not found');
}

$ke_embed = kbEmbedFetch($ke_db, $ke_embed_id, $ke_article_id);
if (!$ke_embed) {
    kbEmbedFail(404, 'Not found');
}

header_remove('X-Frame-Options');
kbEmbedServe(
    (string) ($ke_embed['kb_article_embed_untrusted_html'] ?? ''),
    intval($ke_embed['kb_article_embed_height'] ?? 0)
);
