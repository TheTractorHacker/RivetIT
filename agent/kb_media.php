<?php
/**
 * Authenticated serve endpoint for ALL Knowledge Base media.
 *
 * URLs (see \ITFlow\KB\MediaToken::signedUrl() - that function and this parser
 * are two halves of one contract; change neither without the other):
 *
 *   /agent/kb_media.php?att=<kb_article_attachment_id>[&download=1]
 *   /agent/kb_media.php?a=<kb_article_id>&f=<reference_name>
 *   /agent/kb_media.php?f=<reference_name>
 *
 * each optionally carrying &p=<principal>&e=<unix expiry>&s=<hmac-sha256>.
 *
 * WHY THIS EXISTS. /uploads/ is served straight off nginx with no
 * authentication whatsoever - anyone who guesses or has ever seen a path gets
 * HTTP 200. KB articles are runbooks, network diagrams and vendor docs, and
 * until now their inline images were addressed by their raw filesystem path,
 * both in the stored article HTML and in api/v1/kb.php's attachment URLs. The
 * filename is not a secret and must never be treated as one: checkFileUpload()
 * names files md5(contents) plus two random characters, so anyone already
 * holding a copy of the same document can compute the md5 and brute-force
 * ~4,096 suffixes. Authentication is the control.
 *
 * ---------------------------------------------------------------------------
 * TWO CREDENTIALS, ONE STORED URL
 * ---------------------------------------------------------------------------
 * The stored URL in kb_article_content carries NO signature, NO expiry and NO
 * principal. That is deliberate and it is the load-bearing decision of the
 * whole design:
 *
 *   - On the web the session cookie is the credential. The URL is same-origin
 *     and the cookie is SameSite=Lax (includes/session_init.php:24), so an
 *     <img> subresource on a same-origin page sends it. Nothing has to be
 *     rewritten at render time, on the article page, in the portal, in the
 *     version-history modal, or - the case that actually forces this - inside
 *     the TinyMCE editor iframe. With convert_urls:false (js/app.js:433)
 *     TinyMCE posts back byte-identical what it was handed, so a canonical URL
 *     round-trips through an edit unchanged. A SIGNED url in storage would
 *     either expire while the editor was open or get permanently baked into
 *     kb_article_content on save.
 *
 *   - For the API/Android there is no cookie and no header we control: a
 *     WebView <img> under loadDataWithBaseURL() and an external browser opening
 *     an attachment both send nothing custom. So api/v1/kb.php rewrites the
 *     canonical URLs into absolute signed ones at RESPONSE time, and the
 *     signature is what authenticates that request.
 *
 * ---------------------------------------------------------------------------
 * A SIGNATURE AUTHENTICATES. IT NEVER AUTHORIZES.
 * ---------------------------------------------------------------------------
 * The signed branch below re-derives the entire permission chain from the
 * database on every single request: the principal still exists and still
 * resolves to an enabled, non-archived agent; module_kb >= 1; the article's
 * department scope against user_client_permissions; the row still present. A
 * revoked role, a deleted token or an expired legacy key therefore kills every
 * outstanding URL instantly, mid-expiry. Nothing is cached, nothing is trusted
 * from the URL beyond "which object is being asked for, by whom".
 *
 * The residual risk that cannot be designed away: the capability is in a query
 * string, so it lands in nginx's access log and in the external browser's
 * history. Bounded by a short TTL, by TTL_MAX enforced at verify time, by
 * Referrer-Policy: no-referrer on the response, and above all by the
 * re-derivation - a replayed URL is worth exactly as much as the principal it
 * names is still worth.
 *
 * ---------------------------------------------------------------------------
 * ONE DECISION PER REQUEST
 * ---------------------------------------------------------------------------
 * The branch is taken on the PRESENCE of ?s=, not on its validity, and not on
 * its SHAPE either - isset($_GET['s']) is the test, so ?s[]=x is a 403 rather
 * than a quiet fall-through to the cookie (it used to be the latter; see the
 * comment at the branch itself). A bad signature is 403 even for a logged-in
 * agent sitting in front of the article; it never falls back to the cookie.
 * That is what makes an access-log line unambiguous about which rule admitted
 * or refused a request, and it removes the class of bug where a broken
 * signature quietly works for exactly the people who would never notice.
 */

// Binary endpoint: bootstrap $mysqli, the settings and the function library
// WITHOUT the page chrome. agent/includes/inc_all.php cannot be used here - it
// pulls in header.php / top_nav.php / side_nav.php and would emit a full HTML
// document ahead of the file bytes. check_login.php is deliberately NOT here:
// the signed branch must never touch the session.
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';

define('FROM_KB_MEDIA', true);
require_once $_SERVER['DOCUMENT_ROOT'] . '/agent/includes/kb_media_serve.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/agent/includes/kb_media_auth.php';

/**
 * Fetch a query parameter that MUST be a scalar string.
 *
 * PHP happily makes $_GET['f'] an array when the request says ?f[]=x, and every
 * downstream (string) cast, preg_match() and intval() then behaves in a way the
 * validation below was not written for - preg_match() on an array raises a
 * TypeError under PHP 8. Returning null for anything that is not a string means
 * the array case lands on the same deny path as a missing parameter.
 */
function kbMediaParam(string $key): ?string
{
    return isset($_GET[$key]) && is_string($_GET[$key]) ? $_GET[$key] : null;
}

// ---------------------------------------------------------------------------
// 1. What is being asked for. Format validation ONLY - no database, no
//    filesystem, and nothing here depends on who is asking.
// ---------------------------------------------------------------------------

$att_param = kbMediaParam('att');
$a_param   = kbMediaParam('a');
$f_param   = kbMediaParam('f');

if ($att_param !== null) {
    // An attachment is addressed by id ALONE. A request that also carries a
    // filename is refused rather than having the filename ignored: two possible
    // readings of one URL is how a signed tuple and a served object drift apart.
    if ($a_param !== null || $f_param !== null) {
        kbMediaFail(404, 'Not found');
    }
    $kind = \ITFlow\KB\MediaToken::KIND_ATTACHMENT;
    $ref  = intval($att_param);
    $file = '';
    if ($ref < 1) {
        kbMediaFail(404, 'Not found');
    }
} elseif ($a_param !== null) {
    // Article-scoped inline image: /uploads/kb/<article_id>/<name>. Written by
    // the DOCX importer (agent/post/kb_article.php) and by attachment uploads.
    if ($f_param === null) {
        kbMediaFail(404, 'Not found');
    }
    $kind = \ITFlow\KB\MediaToken::KIND_IMAGE;
    $ref  = intval($a_param);
    $file = $f_param;
    if ($ref < 1 || !kbMediaValidReferenceName($file)) {
        kbMediaFail(404, 'Not found');
    }
} elseif ($f_param !== null) {
    // The shared TinyMCE editor pool: /uploads/kb/<name>, flat, no article id,
    // no database row, no owner. See the authorization note in section 4.
    $kind = \ITFlow\KB\MediaToken::KIND_POOL;
    $ref  = 0;
    $file = $f_param;
    if (!kbMediaValidReferenceName($file)) {
        kbMediaFail(404, 'Not found');
    }
} else {
    kbMediaFail(404, 'Not found');
}

// Selects inline vs attachment disposition. Deliberately OUTSIDE the signature:
// it can never change which bytes are served, so one token drives both the
// article page's "View" and "Download" links.
$force_download = !empty($_GET['download']);

// ---------------------------------------------------------------------------
// 2. Who is asking. Exactly one of these two blocks runs, and it ends with
//    $auth_user_id set to a live user (or the request already dead).
// ---------------------------------------------------------------------------

/* THE BRANCH IS TAKEN ON PRESENCE, NOT ON SHAPE - see the invariant at the top
   of this file. isset($_GET['s']) rather than kbMediaParam('s') !== null,
   because kbMediaParam() returns null for anything that is not a string, so
   ?s[]=x used to make $signature null and take the SESSION branch - while this
   file claimed in as many words that a request carrying ?s= never falls back to
   the cookie. Verified by driving the four lines below with $_GET set to each
   shape: 's' absent -> session branch; 's' a string (including '') -> signed
   branch; 's' a list, an assoc array, or repeated -> 403 here. No privilege was
   ever gained by the old behaviour (the session branch is itself fully
   authenticated and the same agent could fetch the URL with no ?s= at all), but
   an access-log line was not unambiguous about which rule admitted the request,
   which is precisely the property the invariant exists to provide. */
$has_signature = isset($_GET['s']);
$signature     = kbMediaParam('s');
if ($has_signature && $signature === null) {
    kbMediaFail(403, 'Access denied');
}

// Whether the article must still be un-archived. Each mode mirrors ITS OWN
// consumer's article query - that is the rule:
//   - the signed branch serves the API, and api/v1/kb.php's article-detail
//     query filters on kb_article_archived_at IS NULL, so an archived
//     article's media must be just as unreachable as the article itself.
//   - the session branch serves agent/kb_article.php, which deliberately has NO
//     archived filter and renders archived articles: it assigns
//     $kb_article_archived_at at line 73 and guards the "(Archived)" badge on
//     it at lines 103-104. Adding the filter here would break every image in
//     every archived article on the page that shows them.
$require_live_article = false;

if ($has_signature) {

    // -- SIGNED BRANCH -------------------------------------------------------
    // No session is touched anywhere in here.

    $require_live_article = true;

    $principal   = kbMediaParam('p') ?? '';
    $expires_raw = kbMediaParam('e') ?? '';

    /* Format-validate EVERY field before building any string or running any
       query. This ordering is not tidiness - it is the proof that
       MediaToken::payload()'s delimiter-joined encoding is injective. Once we
       know $file matches [A-Za-z0-9_-]+\.[A-Za-z0-9]+ and $principal matches
       [utk][0-9]+ and the rest are integers, no field can contain the "\n" that
       separates them, so no two distinct tuples can collide. Anything malformed
       is 403 with zero database hits, which also makes this endpoint useless as
       an oracle for probing ids. */
    if (!preg_match('/^[a-f0-9]{64}$/', $signature)) {
        kbMediaFail(403, 'Access denied');
    }
    if (!\ITFlow\KB\MediaToken::isValidPrincipal($principal)) {
        kbMediaFail(403, 'Access denied');
    }
    // A LENGTH bound, not a range check. 10 digits reaches 9,999,999,999 - about
    // 4.7x the 2,147,483,647 of a 32-bit signed int, so do not read this as one
    // (an earlier version of this comment did, and was simply wrong). Its job is
    // to stop a multi-kilobyte digit string being carried into intval() and into
    // the signed payload; a value that is merely large still fails the two
    // expiry comparisons below, which is where the real bound lives.
    if ($expires_raw === '' || strlen($expires_raw) > 10 || !ctype_digit($expires_raw)) {
        kbMediaFail(403, 'Access denied');
    }
    $expires = intval($expires_raw);

    // Rebuilt from the request's PARSED fields, never from the raw query string.
    if (!\ITFlow\KB\MediaToken::verify($signature, $kind, $ref, $file, $principal, $expires)) {
        kbMediaFail(403, 'Access denied');
    }

    $now = time();
    if ($expires < $now) {
        kbMediaFail(403, 'Link expired');
    }
    // A cheap cap on a future minting bug: nothing legitimate is ever signed
    // for longer than TTL_MAX, so a token that claims to be is refused on the
    // serving side regardless of what minted it.
    if ($expires > $now + \ITFlow\KB\MediaToken::TTL_MAX) {
        kbMediaFail(403, 'Access denied');
    }

    // THE STEP THAT MAKES THIS AUTHENTICATION RATHER THAN AUTHORIZATION.
    $resolved = kbMediaResolvePrincipal($principal);
    if ($resolved === null) {
        kbMediaFail(403, 'Access denied');
    }

    $auth_user_id  = $resolved['user_id'];
    $key_client_id = $resolved['key_client_id'];

    // Viewing is a READ - level 1 deliberately, so a read-only KB role can see
    // the images in the articles it is allowed to read. Skipped for the pool;
    // see section 4.
    if ($kind !== \ITFlow\KB\MediaToken::KIND_POOL && !kbMediaHasModuleKb($auth_user_id, 1)) {
        kbMediaFail(403, 'Access denied');
    }

} else {

    // -- SESSION BRANCH ------------------------------------------------------
    // Same prefix agent/kb_article_attachment.php uses. check_login.php does
    // the whole agent-session chain (live, enabled, non-archived, user_type 1)
    // and redirects to /login.php if any of it fails. For an <img> subresource
    // that 302 simply means a broken image, which is the correct visible
    // outcome for "you are not logged in".
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';

    $auth_user_id  = intval($session_user_id);
    $key_client_id = 0;

    if ($kind !== \ITFlow\KB\MediaToken::KIND_POOL) {
        /* lookupUserPermission() is consulted first only so that a denial can
           carry a real 403 - enforceUserPermission() answers with a 200 and an
           HTML sentence, which is right for a page and wrong for a binary
           fetch. The canonical gate still runs underneath, so this endpoint can
           never drift from the app's permission semantics. */
        $kb_permission_level = lookupUserPermission('module_kb');
        if (!$kb_permission_level || $kb_permission_level < 1) {
            kbMediaFail(403, 'Access denied');
        }
        enforceUserPermission('module_kb', 1);
    }
}

// ---------------------------------------------------------------------------
// 3. Department scope + path, per mode. Both branches converge here, so the
//    scope rule has exactly one implementation regardless of how the caller
//    authenticated.
//
//    kbMediaClientAccessOk() rather than enforceClientAccess(): the canonical
//    version flash_alert()s and redirect()s to clients.php, which turns a
//    permission denial into a 302 to an HTML page in the middle of what is
//    supposed to be a PNG. Identical rule, bool return, real status code. The
//    older agent/kb_article_attachment.php still calls enforceClientAccess()
//    and is deliberately left alone - changing its behaviour was out of scope.
// ---------------------------------------------------------------------------

if ($kind === \ITFlow\KB\MediaToken::KIND_ATTACHMENT) {

    // Resolve the attachment AND its article in one go - the article is what
    // carries the department scope that governs access.
    $archived_clause = $require_live_article ? 'AND kb_articles.kb_article_archived_at IS NULL' : '';
    $row = mysqli_fetch_assoc(mysqli_query(
        $mysqli,
        "SELECT kb_article_attachments.kb_article_attachment_name,
                kb_article_attachments.kb_article_attachment_reference_name,
                kb_articles.kb_article_id,
                kb_articles.kb_article_client_id
         FROM kb_article_attachments
         INNER JOIN kb_articles
                 ON kb_articles.kb_article_id = kb_article_attachments.kb_article_attachment_kb_article_id
         WHERE kb_article_attachments.kb_article_attachment_id = $ref
         $archived_clause
         LIMIT 1"
    ));

    if (!$row) {
        kbMediaFail(404, 'Not found');
    }

    if (!kbMediaClientAccessOk($auth_user_id, intval($row['kb_article_client_id']), $key_client_id)) {
        kbMediaFail(403, 'Access denied');
    }

    /* The reference name is server-generated and re-validated at insert time,
       so it is already constrained. Validate it again anyway: a row is not a
       trust boundary, and this is the last check standing between a
       hypothetically poisoned column and an arbitrary-file read. */
    $reference_name = (string) $row['kb_article_attachment_reference_name'];
    if (!kbMediaValidReferenceName($reference_name)) {
        kbMediaFail(404, 'Not found');
    }

    $file_path = kbMediaResolve(intval($row['kb_article_id']) . '/' . $reference_name);
    kbMediaServe($file_path, (string) $row['kb_article_attachment_name'], $force_download);

} elseif ($kind === \ITFlow\KB\MediaToken::KIND_IMAGE) {

    /* NO ROW IS A 404, stated explicitly because the failure mode is silent: if
       an article is deleted but its /uploads/kb/<id>/ directory survives on
       disk (nothing here unlinks it), the article row is the ONLY thing that
       can make those files unreachable. Falling back to "serve it anyway
       because the file exists" would leave every image of every deleted article
       readable forever. */
    $archived_clause = $require_live_article ? 'AND kb_article_archived_at IS NULL' : '';
    $row = mysqli_fetch_assoc(mysqli_query(
        $mysqli,
        "SELECT kb_article_client_id
         FROM kb_articles
         WHERE kb_article_id = $ref
         $archived_clause
         LIMIT 1"
    ));

    if (!$row) {
        kbMediaFail(404, 'Not found');
    }

    if (!kbMediaClientAccessOk($auth_user_id, intval($row['kb_article_client_id']), $key_client_id)) {
        kbMediaFail(403, 'Access denied');
    }

    /* The capability granted by a forged ?a= is bounded by the filesystem: the
       article id picks the DIRECTORY, so claiming an article you can read does
       not let you reach a file that lives under one you cannot. That is exactly
       the property the pool below does NOT have. */
    $file_path = kbMediaResolve($ref . '/' . $file);
    kbMediaServe($file_path, $file, $force_download);

} else {

    /* ------------------------------------------------------------------
       THE SHARED EDITOR POOL - and the one place this design accepts a real
       gap rather than papering over it.

       agent/kb_article_upload.php writes TinyMCE's uploaded images FLAT into
       /uploads/kb/<name>. There is no article id in the path, no row in any
       table, and no owner recorded anywhere - so there is nothing to scope
       against. Worse, js/app.js:466 wires that one upload URL to the `.tinymce`
       class, and 16 files carry that class - 14 agent-side (10 under agent/, 4
       under admin/: KB articles, IT documents, ticket replies, contract and
       document templates, bulk emails) plus client/ticket.php and
       client/ticket_add.php in the portal, which use a separate init in
       js/portal_tinymce_init.js that sets no images_upload_url, so contacts
       never write to the pool. Counted with
       `grep -rln 'class="[^"]*\btinymce\b' --include=*.php`, re-run against
       this tree; the same 16/14 is stated in agent/kb_article_upload.php, in
       the comment above its json_encode() of the editor's `location`.

       So the rule here is "any live agent principal", with NO module check, and
       both branches agree on it. Two things follow, stated plainly:

         - Requiring module_kb would be WRONG, not stricter: it would blank out
           the inline images in ticket replies and IT documents for every agent
           whose role lacks KB access, which is a functional break with no
           security benefit (they can already see those images today, and the
           file has no KB affiliation to enforce).

         - Any authenticated agent can therefore read any pool file whose name
           they can guess, and the name is md5(contents) + 2 random chars. That
           is a real, unclosed gap. It is narrower than the status quo it
           replaces - today those bytes are readable by ANYONE on the internet
           with no session at all - but it is not zero. Closing it properly
           needs the pool to stop being flat: a row per uploaded file recording
           what it belongs to. That is a schema change and a migration of the
           existing pool, and it is deliberately not in this change.

       The article id is deliberately NOT accepted as scope for a pool file. For
       ?a=<id>&f=<name> the id selects the directory, so a forged id can only
       reach files you could already read; for a flat pool file the id would be
       a pure authorization CLAIM with no relationship to the bytes, i.e. a
       forgery would actually work.
       ------------------------------------------------------------------ */

    /* A department-scoped legacy X-Api-Key cannot be satisfied here: a pool file
       belongs to no department, so there is no value that could match the key's
       api_key_client_id. Deny rather than treat "no department" as "any
       department".

       THIS BRANCH IS REACHABLE. An earlier version of this comment claimed it
       was not; a reviewer reached it with one request. Any article whose stored
       content holds a flat /uploads/kb/<name> pool image, fetched through the
       API with a department-scoped legacy key, lands here and 403s that image
       while the article itself returns 200. The deny is kept anyway, and the
       asymmetry with the article rule above is deliberate: an article carries a
       kb_article_client_id that api/v1/kb.php's $kb_client_scope_clause has
       already scoped, so honouring its client_id-0 exemption is re-checking a
       decision the API itself made - whereas a pool file carries nothing at
       all. A picture pasted into ANOTHER department's ticket reply lives in the
       same flat bucket, so letting a department-scoped credential read it by
       name would hand it exactly the data its restriction exists to withhold.

       The practical blast radius today is small but NOT zero, and the honest
       version is worth writing down. Measured on the live install 2026-09-08:
       /uploads/kb holds 20 files, 17 of them inside the per-article
       subdirectories 13/14/15/16/18 and 3 FLAT at the top level (three copies
       of one 3,553-byte PNG under different checkFileUpload() suffixes). So the
       BYTES do reach the pool. What does not reach the editor is the URL:
       agent/kb_article_upload.php requires includes/inc_all.php, which emits a
       full HTML document ahead of its JSON, so TinyMCE never receives a usable
       `location` and cannot have stored one - and the comment block in that
       file records a database sweep of kb_article_content,
       kb_article_version_content, document_content, ticket_details and
       ticket_reply finding no flat /uploads/kb/<name> URL in any of them. (That
       sweep is theirs, not re-run here - this session had no database access.)
       If that endpoint is ever fixed, this deny becomes visible as broken
       images for legacy-key callers, and the correct fix then is the schema
       change described above - a row per pool file recording what it belongs
       to - not relaxing this line. */
    if ($key_client_id > 0) {
        kbMediaFail(403, 'Access denied');
    }

    $file_path = kbMediaResolve($file);
    kbMediaServe($file_path, $file, $force_download);
}

// kbMediaServe() always exit()s. Reaching this line means a mode fell through
// without serving or failing, which cannot happen today - so deny, loudly and
// by default, rather than returning a 200 with an empty body.
kbMediaFail(404, 'Not found');
