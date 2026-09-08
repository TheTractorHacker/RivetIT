<?php
/**
 * Authenticated serve endpoint for Knowledge Base article attachments.
 *
 * URL: /agent/kb_article_attachment.php?id=<kb_article_attachment_id>[&download=1]
 *
 * WHY THIS EXISTS - the raw /uploads/kb/... URL is not an acceptable link target:
 *
 *   1. /uploads/ is served straight off nginx with NO authentication. Anyone who
 *      holds or can guess a URL under it gets HTTP 200. KB attachments are
 *      runbooks, network diagrams and vendor docs - internal by definition.
 *   2. The stored filename is content-addressed: md5(file_contents) plus two
 *      random base64url characters (see checkFileUpload() in functions.php).
 *      Anyone already holding a copy of the same document can compute the md5
 *      and brute-force ~4,096 suffixes to find the URL. The filename is NOT a
 *      secret and must never be treated as one - authentication is the control.
 *   3. nginx now stamps `Content-Disposition: attachment` on everything under
 *      /uploads/ (deliberately - it is what stops an uploaded .svg or .html
 *      executing script at this app's origin). That made the article page's
 *      "View" link behave identically to "Download": it could never preview.
 *
 * So previewing safely requires serving the bytes from PHP, behind the session,
 * with a Content-Type this endpoint decides - not one nginx guesses from the
 * extension and not one the browser is allowed to sniff.
 *
 * WHAT CHANGED, AND WHAT DELIBERATELY DID NOT. The URL, the permission chain
 * and the response are all exactly as before. What used to be ~165 lines of
 * path resolution, magic-number sniffing, inline allow-listing and RFC 5987
 * header building now lives in agent/includes/kb_media_serve.php, because
 * agent/kb_media.php (and, in the portal, client/kb_media.php) have to make the
 * IDENTICAL "how do I put these bytes on the wire without creating stored XSS
 * at this origin" decision. That part is subtle and was measured painfully; a
 * divergence between copies of it would be a vulnerability rather than a bug,
 * so there is now exactly one copy. Read the rule there - it is the same rule
 * this file used to state, moved, not relaxed.
 *
 * The one visible difference: responses now also carry
 * `Referrer-Policy: no-referrer`, added for agent/kb_media.php's sake (its
 * capability token rides in the query string). It costs a media response
 * nothing and is strictly protective, so it is not conditionalised.
 *
 * The authorization here is deliberately NOT shared with agent/kb_media.php:
 * this endpoint keeps calling enforceClientAccess() exactly as it always has,
 * redirect-on-denial and all, rather than switching to the bool twin. Changing
 * the behaviour of a working endpoint was not in scope.
 */

// Binary endpoint: bootstrap the session, permissions and $mysqli WITHOUT the
// page chrome. agent/includes/inc_all.php cannot be used here - it pulls in
// header.php / top_nav.php / side_nav.php and would emit a full HTML document
// ahead of the file bytes. This is the same prefix inc_all.php itself starts
// with, stopped short of the layout.
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';

define('FROM_KB_MEDIA', true);
require_once $_SERVER['DOCUMENT_ROOT'] . '/agent/includes/kb_media_serve.php';

// Viewing an attachment is a READ. Deliberately level 1: requiring level 2 here
// would lock read-only KB roles out of the very articles they are allowed to
// read. lookupUserPermission() is consulted first only so that a denial can
// carry a real 403 - enforceUserPermission() answers with a 200 and an HTML
// sentence, which is right for a page and wrong for a binary fetch. The
// canonical gate still runs underneath, so this endpoint can never drift from
// the app's permission semantics.
$kb_permission_level = lookupUserPermission('module_kb');
if (!$kb_permission_level || $kb_permission_level < 1) {
    kbMediaFail(403, 'Access denied');
}
enforceUserPermission('module_kb', 1);

$kb_article_attachment_id = intval($_GET['id'] ?? 0);
if ($kb_article_attachment_id < 1) {
    kbMediaFail(404, 'Not found');
}

// Resolve the attachment AND its article in one go - the article is what
// carries the client/department scope that governs access.
$sql = mysqli_query(
    $mysqli,
    "SELECT kb_article_attachments.kb_article_attachment_name,
            kb_article_attachments.kb_article_attachment_reference_name,
            kb_articles.kb_article_id,
            kb_articles.kb_article_client_id
     FROM kb_article_attachments
     INNER JOIN kb_articles
             ON kb_articles.kb_article_id = kb_article_attachments.kb_article_attachment_kb_article_id
     WHERE kb_article_attachments.kb_article_attachment_id = $kb_article_attachment_id
     LIMIT 1"
);

if (!$sql || mysqli_num_rows($sql) === 0) {
    kbMediaFail(404, 'Not found');
}

$row = mysqli_fetch_assoc($sql);

$kb_article_id        = intval($row['kb_article_id']);
$kb_article_client_id = intval($row['kb_article_client_id']);

// Mirrors agent/kb_article.php exactly: an article scoped to a department is
// only readable by someone with access to that department. Note there is no
// kb_article_archived_at filter, also mirroring agent/kb_article.php, which
// renders archived articles and must keep their attachments reachable.
if ($kb_article_client_id > 0) {
    enforceClientAccess($kb_article_client_id);
}

// ---------------------------------------------------------------------------
// Path resolution. Built from DB values and the integer id ONLY - nothing in
// the request other than that integer ever reaches the filesystem.
// ---------------------------------------------------------------------------

// The reference name is server-generated by checkFileUpload() and re-validated
// at insert time, so it is already constrained. Validate it again anyway: a row
// is not a trust boundary, and this is the last check standing between a
// hypothetically poisoned column and an arbitrary-file read.
$reference_name = (string) $row['kb_article_attachment_reference_name'];
if (!kbMediaValidReferenceName($reference_name)) {
    kbMediaFail(404, 'Not found');
}

// realpath() + prefix containment against DOCUMENT_ROOT/uploads/kb; ends the
// request itself on anything that escapes or does not exist.
$file_path = kbMediaResolve($kb_article_id . '/' . $reference_name);

kbMediaServe($file_path, (string) $row['kb_article_attachment_name'], !empty($_GET['download']));
