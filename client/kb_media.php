<?php
/**
 * Client Portal
 * Authenticated serve endpoint for Knowledge Base media.
 *
 *   /client/kb_media.php?att=<kb_article_attachment_id>[&download=1]
 *   /client/kb_media.php?a=<kb_article_id>&f=<reference_name>
 *   /client/kb_media.php?f=<reference_name>
 *
 * The portal-session sibling of agent/kb_media.php. Same query shape, same
 * byte-serving core (agent/includes/kb_media_serve.php), a COMPLETELY different
 * authorization question.
 *
 * ---------------------------------------------------------------------------
 * WHY THE PORTAL NEEDS ITS OWN ENDPOINT
 * ---------------------------------------------------------------------------
 * kb_article_content stores /agent/kb_media.php?... , and agent/kb_media.php's
 * cookie branch runs includes/check_login.php - the AGENT session gate. A
 * department contact holds a portal session, not an agent one, so every inline
 * image in every article the portal shows would 302 to the agent login and
 * render broken. client/kb_article.php therefore rewrites the stored URL to
 * this path at render time (\ITFlow\KB\MediaUrlRewriter::toPortal), and this
 * file answers it with the portal's own rule.
 *
 * ---------------------------------------------------------------------------
 * THE RULE, AND WHY IT IS THE ARTICLE QUERY AND NOT A PERMISSION LOOKUP
 * ---------------------------------------------------------------------------
 * There is no module_kb permission on the portal side and no
 * user_client_permissions row for a contact. What decides whether a contact may
 * read an article is one WHERE clause, and it lives in exactly two places -
 * client/kb_articles.php's list query and client/kb_article.php's detail query:
 *
 *     kb_article_client_visible = 1
 *     AND kb_article_client_id IN (0, $session_client_id)
 *     AND kb_article_archived_at IS NULL
 *
 * Every branch below re-runs that same clause against the live database on
 * every single request. Three consequences follow, and each is the reason a
 * separate branch exists rather than a shared "can this session read /uploads"
 * helper:
 *
 *   - ANOTHER DEPARTMENT'S MEDIA IS UNREACHABLE. The session is pinned to one
 *     client_id (client/includes/check_login.php sets it from $_SESSION or,
 *     under preview, from the resolved preview client), and a forged ?a= names
 *     an article that is then re-tested against it.
 *   - AN ARTICLE HIDDEN FROM THE PORTAL LEAKS NOTHING. client_visible = 0 is
 *     the switch an agent uses to keep an internal runbook internal; without
 *     the clause here, its diagrams would still be fetchable by anyone who
 *     could guess the article id, which is a small integer.
 *   - AN ANONYMOUS REQUEST GETS NOTHING. check_login.php redirects to
 *     /login.php before a line of this file's own logic runs. For an <img>
 *     subresource a 302 simply means a broken image, which is the correct
 *     visible outcome for "you are not signed in".
 *
 * NO SIGNATURE BRANCH AT ALL, and a request that carries one is refused rather
 * than quietly falling back to the cookie. A portal request always has a
 * cookie, so there is no portal principal type to mint, sign or resolve - the
 * whole capability-token machinery in \ITFlow\KB\MediaToken is agent/API-side
 * and stays there. Refusing rather than ignoring keeps agent/kb_media.php's
 * "one decision per request" property true on this side too: an access-log line
 * is unambiguous about which rule admitted the request, and MediaUrlRewriter
 * cannot emit a p/e/s-bearing portal URL (canonicalUrl() builds only
 * att/a/f/download), so nothing legitimate is refused by this.
 *
 * ---------------------------------------------------------------------------
 * THE ADMIN PORTAL PREVIEW WORKS THROUGH HERE UNCHANGED
 * ---------------------------------------------------------------------------
 * client/includes/portal_preview.php gives a previewing admin
 * $session_contact_id = 0 and $session_user_id = 0 and re-derives the admin's
 * authority from the database on every request. Nothing below reads either of
 * those: the only session value this file uses is $session_client_id, which
 * check_login.php sets to the previewed department in exactly the same place it
 * sets it for a real contact. So the preview sees precisely what that
 * department's contacts see, which is the entire point of a preview, and it
 * gains nothing a contact would not have.
 */

// Binary endpoint: bootstrap the portal session and the function library
// WITHOUT the page chrome. client/includes/inc_all.php cannot be used here - it
// pulls in client/includes/header.php and would emit a full HTML document ahead
// of the file bytes. So its first four requires are reproduced in its own
// order, and only its fifth and sixth (client/functions.php, which is
// ticket-portal helpers this file never calls, and header.php) are left out.
// The kb_media_serve.php require is the one addition; it defines functions and
// emits nothing.
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';

define('FROM_KB_MEDIA', true);
require_once $_SERVER['DOCUMENT_ROOT'] . '/agent/includes/kb_media_serve.php';

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_global_settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/client/includes/check_login.php';

/**
 * Fetch a query parameter that MUST be a scalar string.
 *
 * The twin of agent/kb_media.php's kbMediaParam(), duplicated rather than
 * shared because the shared file it belongs in (agent/includes/kb_media_serve.php)
 * is not this lane's to change. Same reason it exists there: PHP happily makes
 * $_GET['f'] an ARRAY when the request says ?f[]=x, and every downstream
 * (string) cast, preg_match() and intval() then behaves in a way the validation
 * below was not written for - preg_match() on an array is a TypeError under
 * PHP 8. Returning null for anything that is not a string puts the array case
 * on the same deny path as a missing parameter.
 */
function kbMediaPortalParam(string $key): ?string
{
    return isset($_GET[$key]) && is_string($_GET[$key]) ? $_GET[$key] : null;
}

// The KB module being switched off must hide the media as well as the pages.
// 404 rather than client/kb_article.php's "Location: index.php": a redirect to
// an HTML page in the middle of what is supposed to be a PNG is not an answer.
if ($config_module_enable_kb != 1) {
    kbMediaFail(404, 'Not found');
}

/* check_login.php sets this on BOTH of its paths - intval($_SESSION['client_id'])
 * for a real login, $portal_preview_client_id for a preview - and redirects
 * rather than falling through, so it is always set by the time this line runs.
 * Proved again here anyway, because this is the value that gets interpolated
 * into SQL below and a value that reaches SQL should be established at the point
 * of use rather than inherited from another file. isset() first, not `?? 0`: an
 * unset value would mean check_login.php had changed shape underneath this
 * endpoint, and the honest answer to that is to serve nothing - `?? 0` would
 * quietly hand out every company-wide article's media instead. It also keeps
 * PHP from emitting an undefined-variable warning INTO the response body, which
 * on a binary endpoint corrupts the file being served. */
if (!isset($session_client_id)) {
    kbMediaFail(404, 'Not found');
}
$session_client_id = intval($session_client_id);

// ---------------------------------------------------------------------------
// 1. What is being asked for. Format validation ONLY - no database, no
//    filesystem, and nothing here depends on who is asking.
// ---------------------------------------------------------------------------

// See the "no signature branch" note in the header comment. Checked on isset()
// rather than on kbMediaPortalParam(), so that ?s[]=x is refused too instead of
// looking like an absent parameter.
if (isset($_GET['s']) || isset($_GET['p']) || isset($_GET['e'])) {
    kbMediaFail(404, 'Not found');
}

$att_param = kbMediaPortalParam('att');
$a_param   = kbMediaPortalParam('a');
$f_param   = kbMediaPortalParam('f');

if ($att_param !== null) {
    // An attachment is addressed by id ALONE. A request that also carries a
    // filename is refused rather than having the filename ignored: two possible
    // readings of one URL is how the URL a page emits and the object a server
    // returns drift apart.
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
    // Article-scoped inline image: /uploads/kb/<article_id>/<name> on disk.
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
    // The shared TinyMCE editor pool: /uploads/kb/<name>, flat. See the pool
    // branch at the bottom of section 2 for how it is scoped.
    $kind = \ITFlow\KB\MediaToken::KIND_POOL;
    $ref  = 0;
    $file = $f_param;
    if (!kbMediaValidReferenceName($file)) {
        kbMediaFail(404, 'Not found');
    }
} else {
    kbMediaFail(404, 'Not found');
}

// Selects inline vs attachment disposition. Same test agent/kb_media.php uses,
// deliberately: a link that renders one way on the agent page must not behave
// differently in the portal.
$force_download = !empty($_GET['download']);

/* The portal's article-visibility clause, written once and reused by all three
 * branches below. Copied from client/kb_article.php's detail query, which is
 * the query this endpoint's answers have to agree with: if they ever disagreed,
 * a contact would see either an article whose images 404 or images belonging to
 * an article the portal refuses to show. $session_client_id is an int (asserted
 * above), so this interpolates safely. */
$portal_article_scope = "kb_article_client_visible = 1
     AND kb_article_client_id IN (0, $session_client_id)
     AND kb_article_archived_at IS NULL";

// ---------------------------------------------------------------------------
// 2. Which article is this, and may this session read it. NO ROW IS A 404 in
//    every branch, stated explicitly because the failure mode is silent: the
//    /uploads/kb/<id>/ directory outlives the article row (nothing unlinks it),
//    so the row is the ONLY thing that can make a deleted or hidden article's
//    files unreachable. "Serve it anyway because the file exists" would leave
//    every image of every hidden article readable forever.
// ---------------------------------------------------------------------------

if ($kind === \ITFlow\KB\MediaToken::KIND_ATTACHMENT) {

    // Resolve the attachment AND its article in one go - the article is what
    // carries the visibility and the department scope.
    $row = mysqli_fetch_assoc(mysqli_query(
        $mysqli,
        "SELECT kb_article_attachments.kb_article_attachment_name,
                kb_article_attachments.kb_article_attachment_reference_name,
                kb_articles.kb_article_id
         FROM kb_article_attachments
         INNER JOIN kb_articles
                 ON kb_articles.kb_article_id = kb_article_attachments.kb_article_attachment_kb_article_id
         WHERE kb_article_attachments.kb_article_attachment_id = $ref
           AND $portal_article_scope
         LIMIT 1"
    ));

    if (!$row) {
        kbMediaFail(404, 'Not found');
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

    $row = mysqli_fetch_assoc(mysqli_query(
        $mysqli,
        "SELECT kb_article_id
         FROM kb_articles
         WHERE kb_article_id = $ref
           AND $portal_article_scope
         LIMIT 1"
    ));

    if (!$row) {
        kbMediaFail(404, 'Not found');
    }

    /* The capability granted by a forged ?a= is bounded twice: by the clause
       above, which re-tests the claimed article against this session's own
       department, and by the filesystem, because the article id picks the
       DIRECTORY - so claiming an article you can read does not let you reach a
       file that lives under one you cannot. That is exactly the property the
       pool below does NOT have, which is why it gets its own rule. */
    $file_path = kbMediaResolve($ref . '/' . $file);
    kbMediaServe($file_path, $file, $force_download);

} else {

    /* ------------------------------------------------------------------
       THE SHARED EDITOR POOL, IN THE PORTAL.

       agent/kb_article_upload.php writes TinyMCE's uploaded images FLAT into
       /uploads/kb/<name>: no article id in the path, no row in any table, no
       owner recorded anywhere. agent/kb_media.php can answer "any live agent
       principal" for those, because every agent can already see them today.
       The portal cannot: "any contact" would hand one department's pasted
       screenshot to every other department, and there is no owning row to scope
       against.

       So the scope is DERIVED FROM CONTENT THE CONTACT MAY ALREADY READ: this
       file is served only if some article THIS session is allowed to open
       references it. That is a genuine authorization - the contact can already
       see the bytes by opening that article - rather than a claim taken from
       the URL.

       Both stored spellings are searched, because an article that has not been
       through the storage migration still holds the raw path and its images
       must not break in the portal any more than they do on the agent page:

           /agent/kb_media.php?f=<name>     canonical (post-migration)
           /uploads/kb/<name>               legacy, flat

       Neither needs entity handling: the pool form carries no '&' to be
       escaped to '&amp;', and MediaUrlRewriter builds it with rawurlencode(),
       which is the identity function on the reference-name charset - the
       pattern is ^[A-Za-z0-9_-]+\.[A-Za-z0-9]+$ and every one of those
       characters is URI-unreserved. So the stored bytes and $file are the same
       bytes and a literal LIKE is exact.

       addcslashes() BEFORE mysqli_real_escape_string(), and in that order: '_'
       is in the reference-name charset AND is LIKE's single-character
       wildcard, so without the first call a contact could widen the match to a
       filename they are not entitled to. The escape then makes the resulting
       backslashes safe as SQL string literals.

       That is not theoretical - it was reproduced. With an article referencing
       'poolXflat-2.png' and an unreferenced 'pool_flat-2.png' sitting beside it
       on disk, the LIKE with '_' escaped matches 0 rows and the request 404s,
       while the same LIKE with '_' left raw matches 1 row and the unreferenced
       file is served. Measured 2026-09-08 on a scratch database loaded from the
       live schema.

       HOW MUCH OF THE PORTAL THIS BRANCH IS CARRYING TODAY: none. Measured on
       the live database 2026-09-08, 0 of the 7 kb_articles rows contain a flat
       /uploads/kb/<name> URL. The only writer that could create one is
       agent/kb_article_upload.php, and it does not work: it bootstraps with
       includes/inc_all.php, so it emits a whole HTML document ahead of the JSON
       TinyMCE tries to parse. It is deliberately left broken there (see its own
       header comment) because switching it on needs a schema change. So this
       branch is not repairing anything that exists now - it is here so that
       fixing that uploader later does not silently produce portal articles
       whose images cannot be served.
       ------------------------------------------------------------------ */

    $like_canonical = mysqli_real_escape_string(
        $mysqli,
        addcslashes(\ITFlow\KB\MediaUrlRewriter::CANONICAL_PATH . '?f=' . $file, '%_')
    );
    $like_legacy = mysqli_real_escape_string(
        $mysqli,
        addcslashes('/uploads/kb/' . $file, '%_')
    );

    $row = mysqli_fetch_assoc(mysqli_query(
        $mysqli,
        "SELECT 1 AS ok
         FROM kb_articles
         WHERE $portal_article_scope
           AND (kb_article_content LIKE '%$like_canonical%'
                OR kb_article_content LIKE '%$like_legacy%')
         LIMIT 1"
    ));

    if (!$row) {
        kbMediaFail(404, 'Not found');
    }

    $file_path = kbMediaResolve($file);
    kbMediaServe($file_path, $file, $force_download);
}

// kbMediaServe() always exit()s. Reaching this line means a mode fell through
// without serving or failing, which cannot happen today - so deny, loudly and
// by default, rather than returning a 200 with an empty body.
kbMediaFail(404, 'Not found');
