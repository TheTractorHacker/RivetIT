<?php

// TinyMCE image upload endpoint for Knowledge Base article content.
// Expects a multipart POST with a single "file" field, returns
// {"location": "..."} on success or {"error": {"message": "..."}} on failure.
//
// ---------------------------------------------------------------------------
// THIS ENDPOINT DOES NOT CURRENTLY WORK, AND IS DELIBERATELY LEFT THAT WAY HERE
// ---------------------------------------------------------------------------
// includes/inc_all.php below is the FULL PAGE bootstrap: it pulls in
// includes/header.php (which echoes <!DOCTYPE html> at line 70, unbuffered),
// top_nav.php, side_nav.php and inc_wrapper.php. So the bytes this script
// actually returns are a complete HTML document followed by the JSON, and
// TinyMCE's built-in uploader JSON.parse()s the response body and rejects it.
// The sibling endpoint agent/ticket_attachment_upload.php:7-8 names this exact
// trap in its own header comment and uses the lean config+functions+check_login
// chain instead to avoid it.
//
// Evidence that it has never worked on this install, measured 2026-09-08:
// /uploads/kb/ contains 15 files and every one of them is inside a per-article
// subdirectory written by the DOCX importer - there is not a single flat pool
// file at the top level - and no content column in the database
// (kb_article_content, kb_article_version_content, document_content,
// ticket_details, ticket_reply) contains a flat /uploads/kb/<name> URL.
//
// It is NOT fixed here on purpose. Making it work would switch on a write path
// into the shared editor pool, and the pool has an accepted, unclosed
// authorization gap documented at length in agent/kb_media.php's KIND_POOL
// branch: a pool file has no article id, no row and no owner, so any
// authenticated agent can read any pool file whose name they can guess. It
// would also switch on an upload endpoint with NO validateCSRFToken() call -
// note that ticket_attachment_upload.php:17 has one and this does not, and that
// TinyMCE's built-in uploader sends no CSRF token, so wiring one in is part of
// the same job. Turning all of that on is a deliberate decision with a schema
// change attached (a row per pool file, recording what it belongs to), not a
// side effect of a URL change.
//
// What IS changed here is the URL this would hand back once someone does fix
// it, so the fix cannot silently reintroduce the unauthenticated /uploads/kb
// path this whole change exists to remove.

require_once "includes/inc_all.php";

enforceUserPermission('module_kb');

header('Content-Type: application/json');

$allowed = ['jpg', 'jpeg', 'gif', 'png', 'webp'];

if (empty($_FILES['file'])) {
    http_response_code(400);
    echo json_encode(['error' => ['message' => 'No file uploaded']]);
    exit;
}

$ref_name = checkFileUpload($_FILES['file'], $allowed);

/* One definition, shared with the writer. agent/kb_media.php validates the ?f=
   it is asked to serve with the same test, so a name this endpoint accepts is by
   construction a name that endpoint will serve - they could previously disagree.
   See functions.php::isUploadReferenceName() for the 6.15%-of-uploads bug the
   old inline regex caused. */
if (!isUploadReferenceName($ref_name)) {
    http_response_code(400);
    echo json_encode(['error' => ['message' => 'Invalid or disallowed file']]);
    exit;
}

$upload_dir = $_SERVER['DOCUMENT_ROOT'] . "/uploads/kb/";
mkdirMissing($_SERVER['DOCUMENT_ROOT'] . "/uploads/");
mkdirMissing($upload_dir);

/* The return value was previously discarded, so a failed move still answered
   200 with a location pointing at a file that was never written. That mattered
   little while nginx would 404 the path anyway; it matters more now that the
   location names a PHP endpoint, because kb_media.php would run the whole
   permission chain and only then discover there are no bytes. */
if (!move_uploaded_file($_FILES['file']['tmp_name'], $upload_dir . $ref_name)) {
    http_response_code(500);
    echo json_encode(['error' => ['message' => 'Could not store the uploaded file']]);
    exit;
}

/* The file itself still lives at /uploads/kb/<ref_name>; only the URL changes.
   /uploads/ is served directly by nginx with no authentication of any kind, so
   handing a raw path to the editor baked an unauthenticated URL into
   kb_article_content (and into document_content, ticket_details and every other
   column whose editor carries the shared .tinymce class - js/app.js:388 sets
   the selector, js/app.js:466 sets this upload URL, and 16 files carry that
   class: 14 agent/admin-side plus client/ticket.php and client/ticket_add.php,
   which load js/portal_tinymce_init.js instead and set no images_upload_url,
   so a contact never reaches here).

   No signature and no host: this string is stored verbatim in article HTML and
   has to survive a TinyMCE edit round trip, exactly as the DOCX importer's URLs
   do - see the long comment at agent/post/kb_article.php's docx image loop.
   Signing happens at response time in api/v1/kb.php, for clients with no cookie.

   Single parameter, so there is no '&' here and none of the &-vs-&amp; entity
   ambiguity the article-scoped ?a=<id>&amp;f=<name> form has to be careful about. */
echo json_encode(['location' => "/agent/kb_media.php?f=$ref_name"]);
