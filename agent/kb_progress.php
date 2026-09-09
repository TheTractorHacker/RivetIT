<?php
/**
 * Interactive KB - per-reader progress, AGENT session.
 *
 *   GET  /agent/kb_progress.php?a=<kb_article_id>
 *        -> {"ok":true,"readOnly":false,"article":13,"progress":{...}}
 *
 *   POST /agent/kb_progress.php
 *        csrf_token=<token>&a=<kb_article_id>&items=<json>
 *        -> {"ok":true,"readOnly":false,"stored":3}
 *
 * The agent-session sibling of client/kb_progress.php. Same request shape, same
 * core (agent/includes/kb_progress_store.php), a COMPLETELY different
 * authorization question - which is the same split agent/kb_media.php and
 * client/kb_media.php already make, for the same reason.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS IS A FILE AND NOT A BRANCH IN agent/ajax.php
 * ---------------------------------------------------------------------------
 * SAY THIS PLAINLY, because the design says otherwise. The winning design puts
 * the agent write in a `?kb_progress` branch of agent/ajax.php and the portal
 * write in a branch of client/post.php, and its reasoning for the portal half
 * is sound and is honoured in the other file (read client/kb_progress.php's
 * header). This build was split across parallel lanes on disjoint files, and
 * agent/ajax.php and client/post.php belong to neither of them, so the
 * endpoints are new files instead. Two consequences, both handled rather than
 * inherited:
 *
 *   - agent/ajax.php's 1970 lines bootstrap ../plugins/totp/totp.php and every
 *     global its branches need; this file bootstraps the four requires it
 *     actually uses and emits nothing but JSON. That is a small win, not a
 *     reason - the reason is lane ownership.
 *   - the portal half has a real invariant to keep (client/post.php is the
 *     portal's ONLY authenticated write path, with the preview gate at its
 *     door). client/kb_progress.php reproduces that gate verbatim rather than
 *     escaping it. If these endpoints are later folded into ajax.php/post.php,
 *     everything below moves as-is; nothing here depends on being a file.
 *
 * ---------------------------------------------------------------------------
 * THE RULE
 * ---------------------------------------------------------------------------
 * Identical to what agent/kb_article.php enforces before it renders the same
 * article, because progress is only meaningful for an article the agent can
 * actually open:
 *
 *   - includes/check_login.php - live, enabled, non-archived, user_type 1.
 *   - module_kb >= 1. LEVEL 1, NOT 2, and deliberately: ticking a checkbox
 *     records the READER'S OWN state, not a change to the article, and
 *     agent/kb_article.php's own enforceUserPermission('module_kb') call gates
 *     the page itself at level 1. Requiring write here would lock every
 *     read-only KB role out of the runbook feature they can already read.
 *     (Named by symbol, not by line: that file belongs to another lane and its
 *     line numbers move.)
 *   - the article's department scope, when it has one. Same rule as
 *     enforceClientAccess() (functions.php:3469) and kbMediaClientAccessOk()
 *     (agent/includes/kb_media_auth.php:148): an article with client_id 0 is
 *     company-wide and needs no scope, an admin role bypasses, a user with ZERO
 *     user_client_permissions rows is allowed all departments, otherwise the
 *     (user, client) row must exist. Evaluated in PHP against
 *     $client_access_array, which includes/load_user_session.php:52-58 has
 *     already loaded for this request - so this is a FOURTH statement of the
 *     rule but not a fourth QUERY, and it names the three originals it must
 *     stay in step with.
 *   - an archived article is refused. agent/kb_article.php still renders one
 *     (it is how an agent reviews history), but recording new progress against
 *     something withdrawn is not a thing the reader can act on.
 */

// JSON endpoint: bootstrap the agent session and the function library WITHOUT
// the page chrome. includes/inc_all.php cannot be used here - it pulls in
// includes/header.php and would emit a full HTML document ahead of the JSON.
// Same four requires, same order, as agent/kb_media.php's session branch.
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';

define('FROM_KB_PROGRESS', true);
require_once $_SERVER['DOCUMENT_ROOT'] . '/agent/includes/kb_progress_store.php';

/* check_login.php does the whole agent-session chain and redirects to
   /login.php if any of it fails. A fetch() sees an opaque redirect to HTML,
   which the render layer treats as "saving is unavailable" - the correct
   visible outcome for "your session ended". */
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';

// The KB module being switched off must hide progress as well as the pages.
if ($config_module_enable_kb != 1) {
    kbProgressJson(404, ['ok' => false, 'error' => 'not_found']);
}

/* lookupUserPermission() first so that a denial can carry a real status code -
   enforceUserPermission() answers with HTTP 200 and an HTML sentence, which is
   right for a page and wrong for a fetch(). The canonical gate still runs
   underneath, so this endpoint can never drift from the app's permission
   semantics. Exactly the shape agent/kb_media.php:272-283 uses. */
$kb_permission_level = lookupUserPermission('module_kb');
if (!$kb_permission_level || $kb_permission_level < 1) {
    kbProgressJson(403, ['ok' => false, 'error' => 'forbidden']);
}
enforceUserPermission('module_kb', 1);

$session_user_id = intval($session_user_id);
if ($session_user_id < 1) {
    kbProgressJson(403, ['ok' => false, 'error' => 'forbidden']);
}

// ---------------------------------------------------------------------------
// Which article, and may this agent read it.
// ---------------------------------------------------------------------------

/* Read from the bag that matches the method rather than from $_REQUEST: which
   bags $_REQUEST contains is an ini setting (request_order), so depending on it
   would make this endpoint's parsing a property of php.ini. is_string() first,
   because PHP happily makes ?a[]=1 an ARRAY and intval() on an array is 1 -
   which would silently become article 1. */
$is_post    = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$article_in = $is_post ? ($_POST['a'] ?? null) : ($_GET['a'] ?? null);
$article_id = is_string($article_in) ? intval($article_in) : 0;
if ($article_id < 1) {
    kbProgressJson(400, ['ok' => false, 'error' => 'bad_article']);
}

try {
    $article = mysqli_fetch_assoc(mysqli_query(
        $mysqli,
        "SELECT kb_article_id, kb_article_client_id
         FROM kb_articles
         WHERE kb_article_id = $article_id
           AND kb_article_archived_at IS NULL
         LIMIT 1"
    ));
} catch (\Throwable $e) {
    kbProgressJson(503, ['ok' => false, 'error' => 'unavailable']);
}

if (!$article) {
    kbProgressJson(404, ['ok' => false, 'error' => 'not_found']);
}

$article_client_id = intval($article['kb_article_client_id']);

/* The department scope, stated in PHP against the array check_login.php already
   loaded. See the header comment for the three implementations this must agree
   with. The empty() branch is a fail-OPEN default and it looks wrong in
   isolation: it is the app-wide meaning of "no restriction configured", and
   making this endpoint stricter than the article page that links to it would
   break working installs while fixing nothing. */
$kb_client_ok = ($article_client_id === 0)
    || (isset($session_is_admin) && $session_is_admin === true)
    || empty($client_access_array)
    || in_array($article_client_id, array_map('intval', $client_access_array), true);

if (!$kb_client_ok) {
    kbProgressJson(403, ['ok' => false, 'error' => 'forbidden']);
}

// ---------------------------------------------------------------------------
// Read or write. The agent principal is ('u', user id).
// ---------------------------------------------------------------------------

if (!$is_post) {
    kbProgressJson(200, [
        'ok'       => true,
        'readOnly' => false,
        'article'  => $article_id,
        /* An empty PHP array here (nothing saved yet) or one whose part keys
           happen to be the numeric strings "0","1",... (a real, reachable
           shape - kbProgressValidContentKey() allows it) would both encode as
           a JSON ARRAY under plain json_encode(), where the contract says
           OBJECT at every level. kbProgressJson() forces object encoding at
           every nesting level (JSON_FORCE_OBJECT) precisely so a call site
           does not have to reason about array_is_list() case by case - no
           (object) cast needed here. */
        'progress' => kbProgressLoad($mysqli, $article_id, 'u', $session_user_id),
    ]);
}

/* A WRITE from here on. CSRF first, before the rate counter is touched, so a
   forged cross-origin POST cannot spend a real agent's write budget. */
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

$saved = kbProgressSave($mysqli, $article_id, 'u', $session_user_id, $items);
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
