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
 * The security rule that must never be relaxed here: `inline` is granted from a
 * short ALLOW-list of types that cannot carry script at this origin (PDF and
 * raster images). Everything else - and text/html, application/xhtml+xml and
 * image/svg+xml explicitly and unconditionally - is served as an attachment.
 * Inlining any of those three would hand an uploader stored XSS on this origin,
 * which is exactly the hole the nginx header was added to close.
 */

// Binary endpoint: bootstrap the session, permissions and $mysqli WITHOUT the
// page chrome. agent/includes/inc_all.php cannot be used here - it pulls in
// header.php / top_nav.php / side_nav.php and would emit a full HTML document
// ahead of the file bytes. This is the same prefix inc_all.php itself starts
// with, stopped short of the layout.
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';

/**
 * Bail out with a status code and a plain-text body.
 *
 * Every failure path lands here so a bad id, a deleted row or a file that has
 * gone missing from disk produces a clean status code instead of a PHP fatal or
 * a half-written response - and, just as importantly, so none of them leak
 * whether the requested id exists.
 */
function kbAttachmentFail(int $status, string $message): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    echo $message;
    exit;
}

// Viewing an attachment is a READ. Deliberately level 1: requiring level 2 here
// would lock read-only KB roles out of the very articles they are allowed to
// read. lookupUserPermission() is consulted first only so that a denial can
// carry a real 403 - enforceUserPermission() answers with a 200 and an HTML
// sentence, which is right for a page and wrong for a binary fetch. The
// canonical gate still runs underneath, so this endpoint can never drift from
// the app's permission semantics.
$kb_permission_level = lookupUserPermission('module_kb');
if (!$kb_permission_level || $kb_permission_level < 1) {
    kbAttachmentFail(403, 'Access denied');
}
enforceUserPermission('module_kb', 1);

$kb_article_attachment_id = intval($_GET['id'] ?? 0);
if ($kb_article_attachment_id < 1) {
    kbAttachmentFail(404, 'Not found');
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
    kbAttachmentFail(404, 'Not found');
}

$row = mysqli_fetch_assoc($sql);

$kb_article_id        = intval($row['kb_article_id']);
$kb_article_client_id = intval($row['kb_article_client_id']);

// Mirrors agent/kb_article.php exactly: an article scoped to a department is
// only readable by someone with access to that department.
if ($kb_article_client_id > 0) {
    enforceClientAccess($kb_article_client_id);
}

// ---------------------------------------------------------------------------
// Path resolution. Built from DB values and the integer id ONLY - nothing in
// the request other than that integer ever reaches the filesystem.
// ---------------------------------------------------------------------------

$uploads_root = realpath($_SERVER['DOCUMENT_ROOT'] . '/uploads/kb');
if ($uploads_root === false) {
    kbAttachmentFail(404, 'Not found');
}

// The reference name is server-generated by checkFileUpload() and re-validated
// at insert time, so it is already constrained. Validate it again anyway: a row
// is not a trust boundary, and this is the last check standing between a
// hypothetically poisoned column and an arbitrary-file read.
$reference_name = (string) $row['kb_article_attachment_reference_name'];
if (!preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9]+$/', $reference_name)) {
    kbAttachmentFail(404, 'Not found');
}

$file_path = realpath($uploads_root . '/' . $kb_article_id . '/' . $reference_name);

// realpath() has resolved every symlink and every ".." by this point, so the
// prefix test below is a statement about the real file, not about the string
// that was used to name it. Comparing against the root plus a separator stops
// "/uploads/kb-something-else" passing as "/uploads/kb".
if ($file_path === false || !is_file($file_path) || !is_readable($file_path)) {
    kbAttachmentFail(404, 'Not found');
}
if (strncmp($file_path, $uploads_root . DIRECTORY_SEPARATOR, strlen($uploads_root) + 1) !== 0) {
    kbAttachmentFail(403, 'Access denied');
}

// ---------------------------------------------------------------------------
// Content type, decided from the actual bytes.
// ---------------------------------------------------------------------------

// Open once, up front. The same handle is used to sniff the type and to stream
// the body, so there is no window between deciding what this file is and
// sending it in which the file on disk could change underneath us.
$handle = fopen($file_path, 'rb');
if ($handle === false) {
    kbAttachmentFail(404, 'Not found');
}

// From the file's contents, never its extension: the extension is attacker
// chosen at upload time, so trusting it would let a .png labelled file be
// announced as an image while containing something else entirely.
//
// Deliberately finfo::buffer() over a bounded head rather than finfo::file():
// finfo::file() pulls the file into memory to classify it, which on this
// install means a 500 MB attachment (the checkFileUpload() ceiling) tries to
// allocate 500 MB just to answer "what is this" - measured here, a 300 MB
// attachment died on `Allowed memory size exhausted` before a byte was sent.
// libmagic only ever looks at leading bytes, so a 64 KB head is the same
// answer for a fraction of the cost - verified identical across PDF, DOCX
// (including a 3 MB one, where the ZIP central directory is far past the
// head), HTML and SVG. It also cannot weaken the check below: the inline list
// requires a POSITIVE match on a magic number that lives in the first bytes,
// so a short read can only ever fall back to "attachment".
$mime_type = false;
if (class_exists('finfo')) {
    $file_head = fread($handle, 65536);
    rewind($handle);
    if ($file_head !== false && $file_head !== '') {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime_type = $finfo->buffer($file_head);
    }
    unset($file_head);
}
if (!is_string($mime_type) || $mime_type === '') {
    $mime_type = 'application/octet-stream';
}
// finfo can return "type; charset=..."; keep only the type for the comparisons
// below, then send the full string so text files keep their charset.
$mime_type_bare = strtolower(trim(explode(';', $mime_type)[0]));

// Types that are safe to render in a tab at this app's origin. ALLOW-list, not
// a deny-list: an unknown type is an attachment by construction, so a future
// upload of a type nobody thought about cannot end up inline by accident.
$inline_safe_mime_types = [
    'application/pdf',
    'image/jpeg',
    'image/pjpeg',
    'image/png',
    'image/gif',
    'image/webp',
    'image/bmp',
    'image/x-ms-bmp',
    'image/avif',
];

// Belt and braces on top of the allow-list. These are the types that turn an
// attachment into stored XSS at this origin the moment they are rendered here,
// so they are named explicitly rather than left to the allow-list's silence -
// if someone later widens that list, this still holds the line. THIS IS THE
// MOST IMPORTANT CHECK IN THE FILE.
$never_inline_mime_types = [
    'text/html',
    'application/xhtml+xml',
    'image/svg+xml',
    'text/xml',
    'application/xml',
];

$force_download = !empty($_GET['download']);

$disposition = 'attachment';
if (
    !$force_download
    && in_array($mime_type_bare, $inline_safe_mime_types, true)
    && !in_array($mime_type_bare, $never_inline_mime_types, true)
) {
    $disposition = 'inline';
}

// For those renderable-and-dangerous types, do not merely refuse to inline
// them - refuse to NAME them. Announcing the true text/html of a file is only
// safe for as long as the disposition above survives every proxy between here
// and the browser; announcing application/octet-stream is safe unconditionally.
// The bytes are unchanged, so the download itself is unaffected.
if (in_array($mime_type_bare, $never_inline_mime_types, true)) {
    $mime_type = 'application/octet-stream';
}

// ---------------------------------------------------------------------------
// Filename for the Content-Disposition header.
// ---------------------------------------------------------------------------

// The original name came from a user upload, so it is hostile input in a header
// context. Two independent products:
//   - an ASCII fallback reduced to [A-Za-z0-9._-], which cannot contain a quote,
//     a semicolon, a CR or an LF and therefore cannot break out of the header;
//   - an RFC 5987 filename* whose rawurlencode() percent-encodes everything
//     outside the unreserved set, control characters included, so the UTF-8 name
//     survives without being injectable either.
// Neither branch can carry a newline into header(), so header splitting is not
// merely blocked by PHP's own guard - it is impossible by construction.
$original_name = (string) $row['kb_article_attachment_name'];

// Byte-wise, not /u: an invalid-UTF-8 name would make a /u pattern return null.
// Every byte stripped here is < 0x80, so UTF-8 sequences are left intact.
$utf8_name = preg_replace('/[\x00-\x1F\x7F]/', '', $original_name);
// Browsers already strip directory components out of a Content-Disposition
// filename, but do not make the save path depend on them behaving.
$utf8_name = str_replace(['/', '\\'], '_', $utf8_name);
$utf8_name = substr($utf8_name, 0, 200);

$ascii_name = preg_replace('/[^A-Za-z0-9._-]/', '_', $utf8_name);
$ascii_name = trim($ascii_name, '.');
$ascii_name = substr($ascii_name, 0, 200);
if ($ascii_name === '' || $ascii_name === false) {
    $ascii_name = 'attachment';
}

$content_disposition = $disposition
    . '; filename="' . $ascii_name . '"'
    . "; filename*=UTF-8''" . rawurlencode($utf8_name);

// ---------------------------------------------------------------------------
// Send it.
// ---------------------------------------------------------------------------

$file_size = filesize($file_path);

header('Content-Type: ' . $mime_type);
header('Content-Disposition: ' . $content_disposition);
header('Content-Length: ' . $file_size);
// Always. Without it a browser may sniff past the type above and reach its own
// conclusion - which would undo the entire allow-list.
header('X-Content-Type-Options: nosniff');

/* The only response in this app served without a CSP: includes/header.php is
   deliberately not included here, because it would emit a whole HTML document
   ahead of the file bytes. So set one directly. This is belt and braces behind
   the positive inline allow-list above - if a type ever slipped through it,
   nothing here can load, run or frame anything at this origin.
   'sandbox' is deliberately NOT included: it was verified not to break Chrome's
   inline PDF viewer (headed Chromium under Xvfb - a headless browser downloads
   every PDF regardless, so headless proves nothing here), but it was not tested
   against Firefox's pdf.js, and breaking preview is the one thing this endpoint
   exists to fix. */
header("Content-Security-Policy: default-src 'none'; script-src 'none'; object-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
// Authenticated content: never let a shared cache hold a copy.
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');

// PHP-FPM here runs with output_buffering = 4096. Drop every buffer before
// streaming so the response is written straight out rather than accumulated,
// which is what keeps a 500 MB attachment from becoming 500 MB of process
// memory. fpassthru() then copies in chunks.
while (ob_get_level() > 0) {
    ob_end_clean();
}

fpassthru($handle);
fclose($handle);
exit;
