<?php
/**
 * Shared byte-serving core for every authenticated Knowledge Base media route.
 *
 * WHY THIS FILE EXISTS - there are now three endpoints that hand a caller the
 * raw bytes of a file under /uploads/kb (agent/kb_article_attachment.php,
 * agent/kb_media.php, client/kb_media.php). Each answers a DIFFERENT
 * authorization question - an agent session, a signed API capability, a portal
 * contact - but they must all answer the "how do I put these bytes on the wire
 * without creating stored XSS at this origin" question IDENTICALLY. That part
 * is subtle, was measured painfully (see the finfo comment below), and is the
 * part where a divergence between copies becomes a vulnerability rather than a
 * bug. So it lives here exactly once.
 *
 * Everything in this file was lifted verbatim from agent/kb_article_attachment.php
 * (the original reference implementation, lines 43-59 and 109-305 of the version
 * that shipped in commit 1c00ba3c) with its comments intact. Two things were
 * added, both flagged inline: the Referrer-Policy header, and kbMediaResolve()
 * which folds the three copies of the realpath-containment dance into one.
 *
 * WHAT THIS FILE DOES NOT DO: authorization. Not one line of it. Every caller
 * must have finished deciding "is this principal allowed to read this article"
 * BEFORE it calls kbMediaResolve()/kbMediaServe(). These functions will
 * cheerfully serve any file under /uploads/kb to anybody, because by the time
 * they run that question is settled.
 */

defined('FROM_KB_MEDIA') || die("Direct file access is not allowed");

/**
 * Bail out with a status code and a plain-text body.
 *
 * Every failure path lands here so a bad id, a deleted row or a file that has
 * gone missing from disk produces a clean status code instead of a PHP fatal or
 * a half-written response - and, just as importantly, so none of them leak
 * whether the requested id exists.
 */
function kbMediaFail(int $status, string $message): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    echo $message;
    exit;
}

/**
 * The one true shape of a stored KB media filename.
 *
 * Delegates to MediaToken so that the signer and the server cannot ever drift:
 * the signature payload's injectivity proof depends on this exact character
 * class (no "\n" is representable in it), so the rule has to have a single
 * definition rather than a copy per endpoint. functions.php pulls in the
 * Composer autoloader (functions.php:3 -> includes/redis_functions.php:10), so
 * the class is loadable from every caller of this file.
 *
 * Deliberately the PERMISSIVE form. checkFileUpload() names files
 * md5 . randomString(2) . '.' . ext, and randomString() (functions.php:12) is
 * base64url, so '-' and '_' genuinely occur - measured 1-(62/64)^2 = 6.15% of
 * uploads. The stricter /^[a-zA-Z0-9]+\.[a-zA-Z0-9]+$/ still used at
 * agent/post/kb_article.php:322 and agent/kb_article_upload.php:23 rejects
 * those, which is a pre-existing latent bug in the UPLOAD path (flagged, not
 * fixed here - it is another lane's file). The SERVE path must be permissive or
 * it would refuse to hand back files it already accepted.
 */
function kbMediaValidReferenceName(string $reference_name): bool
{
    return \ITFlow\KB\MediaToken::isValidReferenceName($reference_name);
}

/**
 * Resolve a path under DOCUMENT_ROOT/uploads/kb and prove it stayed there.
 *
 * $relative_path must already be built out of validated components by the
 * caller - an intval'd article id, a kbMediaValidReferenceName()'d filename.
 * This is the LAST line of defence, not the first: it exists so that a
 * hypothetically poisoned database column, or a future caller that forgets a
 * check, still cannot turn into an arbitrary-file read.
 *
 * Ends the request on any failure rather than returning false, so a caller
 * physically cannot forget to check the return value.
 */
function kbMediaResolve(string $relative_path): string
{
    $uploads_root = realpath($_SERVER['DOCUMENT_ROOT'] . '/uploads/kb');
    if ($uploads_root === false) {
        kbMediaFail(404, 'Not found');
    }

    $file_path = realpath($uploads_root . '/' . $relative_path);

    // realpath() has resolved every symlink and every ".." by this point, so the
    // prefix test below is a statement about the real file, not about the string
    // that was used to name it. Comparing against the root plus a separator stops
    // "/uploads/kb-something-else" passing as "/uploads/kb".
    if ($file_path === false || !is_file($file_path) || !is_readable($file_path)) {
        kbMediaFail(404, 'Not found');
    }
    if (strncmp($file_path, $uploads_root . DIRECTORY_SEPARATOR, strlen($uploads_root) + 1) !== 0) {
        kbMediaFail(403, 'Access denied');
    }

    return $file_path;
}

/**
 * Stream an already-authorized, already-contained file to the client.
 *
 * $file_path      - output of kbMediaResolve(), never a request-derived string.
 * $download_name  - the human-facing filename. Hostile input by definition.
 * $force_download - true to refuse `inline` regardless of type.
 *
 * The security rule that must never be relaxed here: `inline` is granted from a
 * short ALLOW-list of types that cannot carry script at this origin (PDF and
 * raster images). Everything else - and text/html, application/xhtml+xml and
 * image/svg+xml explicitly and unconditionally - is served as an attachment.
 * Inlining any of those three would hand an uploader stored XSS on this origin,
 * which is exactly the hole the nginx Content-Disposition header was added to
 * close.
 */
function kbMediaServe(string $file_path, string $download_name, bool $force_download): void
{
    // -----------------------------------------------------------------------
    // Content type, decided from the actual bytes.
    // -----------------------------------------------------------------------

    // Open once, up front. The same handle is used to sniff the type and to stream
    // the body, so there is no window between deciding what this file is and
    // sending it in which the file on disk could change underneath us.
    $handle = fopen($file_path, 'rb');
    if ($handle === false) {
        kbMediaFail(404, 'Not found');
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

    // -----------------------------------------------------------------------
    // Filename for the Content-Disposition header.
    // -----------------------------------------------------------------------

    // The original name came from a user upload, so it is hostile input in a header
    // context. Two independent products:
    //   - an ASCII fallback reduced to [A-Za-z0-9._-], which cannot contain a quote,
    //     a semicolon, a CR or an LF and therefore cannot break out of the header;
    //   - an RFC 5987 filename* whose rawurlencode() percent-encodes everything
    //     outside the unreserved set, control characters included, so the UTF-8 name
    //     survives without being injectable either.
    // Neither branch can carry a newline into header(), so header splitting is not
    // merely blocked by PHP's own guard - it is impossible by construction.

    // Byte-wise, not /u: an invalid-UTF-8 name would make a /u pattern return null.
    // Every byte stripped here is < 0x80, so UTF-8 sequences are left intact.
    $utf8_name = preg_replace('/[\x00-\x1F\x7F]/', '', $download_name);
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

    // -----------------------------------------------------------------------
    // Send it.
    // -----------------------------------------------------------------------

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

    /* NEW, not in the original reference implementation. agent/kb_media.php can be
       reached with a capability token in the QUERY STRING (?s=<hmac>), because the
       one hard constraint on that design is that a WebView <img> and an external
       browser send no headers we control. A query-string capability leaks through
       any Referer this response provokes - an inline PDF that loads a remote
       resource, an <img> whose document later navigates. 'no-referrer' is the only
       value that suppresses it for every request type including same-origin, and
       costs nothing here because a media response has no legitimate use for a
       referrer. It does NOT fix the other query-string exposures (nginx access
       logs, browser history, Chrome Sync for the external-browser download path) -
       those are bounded by the short TTL and by the fact that verification
       re-derives authorization from the database on every single request, so a
       replayed URL dies the moment the principal loses access. */
    header('Referrer-Policy: no-referrer');

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
}
