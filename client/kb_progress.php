<?php
/**
 * Client Portal
 * Interactive KB - per-reader progress, PORTAL session.
 *
 *   GET  /client/kb_progress.php?a=<kb_article_id>
 *        -> {"ok":true,"readOnly":false,"article":13,"progress":{...}}
 *
 *   POST /client/kb_progress.php
 *        csrf_token=<token>&a=<kb_article_id>&items=<json>
 *        -> {"ok":true,"readOnly":false,"stored":3}
 *
 * The portal-session sibling of agent/kb_progress.php. Same request shape, same
 * core (agent/includes/kb_progress_store.php), a COMPLETELY different
 * authorization question - the same split agent/kb_media.php and
 * client/kb_media.php already make, and this file is modelled on the latter.
 *
 * ---------------------------------------------------------------------------
 * THIS FILE IS A SECOND PORTAL WRITE PATH, AND THAT IS A DELIBERATE, NAMED COST
 * ---------------------------------------------------------------------------
 * READ THIS BEFORE ADDING ANYTHING TO IT.
 *
 * client/post.php's 40-line header states, and grep still verifies, that it is
 * the portal's ONLY authenticated write path: the only other SQL writes
 * anywhere under client/ are in login_reset.php, which is a pre-auth page that
 * never runs inside a portal session. That is why portalPreviewBlockWrites()
 * can sit once at that file's door and cover the whole surface, instead of
 * being a check each of its 21 branches has to remember.
 *
 * The winning design therefore put the portal's progress write in a branch of
 * client/post.php, explicitly so that it could not escape that gate. It was
 * right. This build was split across parallel lanes on disjoint files and
 * client/post.php belongs to none of them, so the write is here instead. The
 * invariant is not abandoned, it is REPRODUCED: the same gate, in the same
 * position, with the same fail-closed fallback, before this file reads a single
 * request VALUE. What is lost is the "one door for the whole portal" property -
 * there are now two doors, and both have to be maintained.
 *
 * IF THE OWNER OF client/post.php WANTS THIS BACK WHERE THE DESIGN PUT IT: the
 * body below moves as a `kb_progress` branch with no changes except deleting
 * the gate block (post.php's own gate then covers it) and the bootstrap. That
 * is the preferred end state and this comment is here so it does not get lost.
 *
 * ---------------------------------------------------------------------------
 * THE RULE
 * ---------------------------------------------------------------------------
 * There is no module_kb permission on the portal side and no
 * user_client_permissions row for a contact. What decides whether a contact may
 * read an article is one WHERE clause, which lives in client/kb_articles.php's
 * list query and client/kb_article.php's detail query:
 *
 *     kb_article_client_visible = 1
 *     AND kb_article_client_id IN (0, $session_client_id)
 *     AND kb_article_archived_at IS NULL
 *
 * It is re-run against the live database on every request here, exactly as
 * client/kb_media.php does it and for the same reason: a contact must not be
 * able to record - or read back - progress against an article the portal
 * refuses to show them. A forged ?a= names an article that is then re-tested
 * against this session's own pinned department.
 *
 * ---------------------------------------------------------------------------
 * THE PREVIEWING ADMIN IS SKIPPED ENTIRELY, NOT 403'd
 * ---------------------------------------------------------------------------
 * client/includes/portal_preview.php gives a previewing admin
 * $session_contact_id = 0 AND $session_user_id = 0 (check_login.php:206 and
 * :222, each with its own comment explaining why). Principal ('c', 0) matches
 * no contacts row, so a write would be meaningless as well as refused.
 *
 * Three layers, in this order, and the order is the point:
 *
 *   1. THE RENDER LAYER NEVER ASKS. client/kb_article.php renders
 *      data-ikb-readonly="1" when portalPreviewActive() is true and
 *      js/kb_interactive.js's save() returns immediately when it is set. The
 *      admin ticks, walks, steps and copies with ZERO requests, so the audit
 *      log is not filled with Blocked Write entries for someone looking at a
 *      checkbox.
 *   2. THIS FILE ANSWERS 200 AND DOES NOTHING. If a request arrives anyway -
 *      a stale tab, a hand-typed URL, a future render-layer bug - it is a clean
 *      no-op with readOnly:true, before any database work and before any
 *      request value is read. Not a 403: the preview is supposed to be a
 *      working preview, and a red error in the console is not what an admin
 *      checking a department's article needs to see.
 *   3. portalPreviewBlockWrites() IS STILL THE BACKSTOP. It cannot fire the
 *      read-only path any more (layer 2 got there first), but it still fires
 *      its OTHER path - the one where a preview blob was present and
 *      portalPreviewResolve() has just torn it down as expired, demoted or
 *      mismatched. That is the moment the gate matters most, and it is the case
 *      layer 2 does not cover. The elseif below is the same fail-closed branch
 *      client/post.php carries for when the helper file is not loaded at all.
 */

// JSON endpoint: bootstrap the portal session and the function library WITHOUT
// the page chrome. client/includes/inc_all.php cannot be used here - it pulls
// in client/includes/header.php and would emit a full HTML document ahead of
// the JSON (and, on that path, an X-Frame-Options header this endpoint has no
// use for). Its first four requires are reproduced in its own order; the fifth
// and sixth (client/functions.php, ticket-portal helpers this file never calls,
// and header.php) are left out. Same shape as client/kb_media.php:86-93.
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';

define('FROM_KB_PROGRESS', true);
require_once $_SERVER['DOCUMENT_ROOT'] . '/agent/includes/kb_progress_store.php';

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_global_settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/client/includes/check_login.php';

/* ═════════════════════════════════════════════════════════════════════════════
   PORTAL PREVIEW - WRITE GATE. Reproduced from client/post.php:72-108, in the
   same position: above every branch and above any read of a request VALUE.
   ═════════════════════════════════════════════════════════════════════════════ */

// LAYER 2. A preview is not an error here, it is a no-op. Nothing below this
// line runs for a previewing admin - no database work, no request value read,
// no audit entry - which is what "skipped entirely" means.
if (function_exists('portalPreviewActive') && portalPreviewActive()) {
    kbProgressJson(200, [
        'ok'       => true,
        'readOnly' => true,
        'stored'   => 0,
        'progress' => new stdClass(),
    ]);
}

if (function_exists('portalPreviewBlockWrites')) {

    /*
     * LAYER 3. A no-op for everyone who is not previewing: the function returns
     * immediately when portalPreviewResolve() says this is not a preview, and
     * resolve() itself returns before touching the database when no preview
     * blob is in the session. A genuine portal contact pays one array lookup.
     *
     * Reachable here for exactly one case, since layer 2 already caught every
     * ACTIVE preview: a preview blob that WAS present and has just been torn
     * down (expired, admin demoted, user mismatch). portalPreviewBlockWrites()
     * fails closed on that, answers an XHR with clean JSON
     * {"success":false,"error":"portal_preview_expired"} and does not return.
     */
    portalPreviewBlockWrites('kb_progress');

} elseif (!empty($_SESSION['portal_preview'])) {

    /*
     * FAIL CLOSED, verbatim from client/post.php. The preview namespace is
     * populated but the helper that enforces read-only is not loaded - helpers
     * not deployed, renamed, or fataled. We cannot prove this request is safe,
     * so we refuse it. This branch can only ever ADD blocking; it grants
     * nothing and can never be reached by a real portal contact, whose session
     * has no 'portal_preview' key. The key is spelled literally because the
     * constant that names it lives in the very file whose absence this branch
     * exists to survive.
     */
    kbProgressJson(403, ['ok' => false, 'error' => 'portal_preview_read_only']);

}
/* ══ END PORTAL PREVIEW WRITE GATE ═══════════════════════════════════════════ */

// The KB module being switched off must hide progress as well as the pages.
// 404 rather than client/kb_article.php's "Location: index.php": a redirect to
// an HTML page is not an answer to a fetch().
if ($config_module_enable_kb != 1) {
    kbProgressJson(404, ['ok' => false, 'error' => 'not_found']);
}

/* check_login.php sets both of these on its real-login path and redirects
 * rather than falling through, so they are always set by the time this runs.
 * Proved again here anyway, because $session_client_id is interpolated into SQL
 * below and a value that reaches SQL should be established at the point of use
 * rather than inherited. isset() first, not `?? 0`: an unset value would mean
 * check_login.php had changed shape underneath this endpoint, and the honest
 * answer to that is to serve nothing - `?? 0` would quietly widen the article
 * scope to every company-wide article. */
if (!isset($session_client_id, $session_contact_id)) {
    kbProgressJson(403, ['ok' => false, 'error' => 'forbidden']);
}
$session_client_id  = intval($session_client_id);
$session_contact_id = intval($session_contact_id);

/* A real contact always has a positive contact_id; only the preview has 0, and
 * the preview cannot reach this line. Refuse rather than write rows under
 * principal ('c', 0), which no reader could ever select again. */
if ($session_contact_id < 1) {
    kbProgressJson(403, ['ok' => false, 'error' => 'forbidden']);
}

// ---------------------------------------------------------------------------
// Which article, and may this contact read it.
// ---------------------------------------------------------------------------

/* Read from the bag that matches the method rather than from $_REQUEST: which
   bags $_REQUEST contains is an ini setting (request_order). is_string() first,
   because PHP makes ?a[]=1 an ARRAY and intval() on an array is 1 - which would
   silently become article 1. */
$is_post    = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$article_in = $is_post ? ($_POST['a'] ?? null) : ($_GET['a'] ?? null);
$article_id = is_string($article_in) ? intval($article_in) : 0;
if ($article_id < 1) {
    kbProgressJson(400, ['ok' => false, 'error' => 'bad_article']);
}

/* The portal's article-visibility clause, copied from client/kb_article.php's
 * detail query - the query this endpoint's answers have to agree with. If they
 * ever disagreed, a contact would either see a runbook whose ticks refuse to
 * save or be able to record progress against an article the portal will not
 * show them. $session_client_id is an int (asserted above), so this
 * interpolates safely. */
try {
    $article = mysqli_fetch_assoc(mysqli_query(
        $mysqli,
        "SELECT kb_article_id
         FROM kb_articles
         WHERE kb_article_id = $article_id
           AND kb_article_client_visible = 1
           AND kb_article_client_id IN (0, $session_client_id)
           AND kb_article_archived_at IS NULL
         LIMIT 1"
    ));
} catch (\Throwable $e) {
    kbProgressJson(503, ['ok' => false, 'error' => 'unavailable']);
}

if (!$article) {
    kbProgressJson(404, ['ok' => false, 'error' => 'not_found']);
}

// ---------------------------------------------------------------------------
// Read or write. The portal principal is ('c', contact id) - NOT ('u', ...).
// A contact is not a users row and the two id spaces overlap numerically.
// ---------------------------------------------------------------------------

if (!$is_post) {
    kbProgressJson(200, [
        'ok'       => true,
        'readOnly' => false,
        'article'  => $article_id,
        /* (object), not the bare array. An EMPTY progress map is an empty PHP
           array, and json_encode() writes that as `[]` - so a reader with
           nothing saved would get a JSON ARRAY where the contract (and every
           non-empty response) says OBJECT, and the render layer would have to
           handle two shapes. Measured: without this cast the first GET of a
           fresh article returned "progress":[]. The cast is only needed at the
           top level; every nested map has at least one key by construction,
           because a block key only exists in the result if a part under it
           does. */
        'progress' => (object) kbProgressLoad($mysqli, $article_id, 'c', $session_contact_id),
    ]);
}

/* A WRITE from here on. CSRF first, before the rate counter is touched, so a
   forged cross-origin POST cannot spend a real contact's write budget. */
if (!kbProgressCsrfOk($_POST['csrf_token'] ?? null)) {
    kbProgressJson(403, ['ok' => false, 'error' => 'csrf']);
}

if (!kbProgressRateOk()) {
    kbProgressJson(429, ['ok' => false, 'error' => 'rate_limited']);
}

$items = kbProgressParseItems($_POST['items'] ?? null);
if (is_string($items)) {
    kbProgressJson(400, ['ok' => false, 'error' => $items]);
}

$saved = kbProgressSave($mysqli, $article_id, 'c', $session_contact_id, $items);
if (!$saved['ok']) {
    kbProgressJson($saved['error'] === 'unavailable' ? 503 : 400, [
        'ok'    => false,
        'error' => $saved['error'],
    ]);
}

kbProgressJson(200, [
    'ok'       => true,
    'readOnly' => false,
    'stored'   => $saved['stored'],
]);
