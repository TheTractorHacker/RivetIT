<?php
/**
 * Interactive KB escape hatch - serve endpoint, AGENT session.
 *
 *   /agent/kb_embed.php?id=<kb_article_embed_id>
 *
 * Framed by js/kb_interactive.js's bindEmbed() as
 *
 *   <iframe sandbox="allow-scripts" referrerpolicy="no-referrer" loading="lazy"
 *           src="kb_embed.php?id=17" ...>
 *
 * RELATIVE, deliberately, not '/agent/kb_embed.php?id=17': the same markup and
 * the same binder run on both agent/kb_article.php and client/kb_article.php,
 * so a relative src resolves to THIS file on the agent page and to
 * client/kb_embed.php on the portal page with no per-lane branch in that
 * binder. Also reachable TOP-LEVEL, because the block's no-JS fallback in the
 * stored article is an ordinary (absolute) link to this same URL shape. Both
 * cases are caged; see agent/includes/kb_embed_serve.php's header, which is
 * the security document for this endpoint and should be read before changing
 * a line of it.
 *
 * The portal-session sibling is client/kb_embed.php. Same query shape, same
 * serve core, a COMPLETELY different authorization question - exactly the split
 * agent/kb_media.php and client/kb_media.php already make.
 *
 * ---------------------------------------------------------------------------
 * BOOTSTRAP: config + functions + check_login, AND NOTHING ELSE
 * ---------------------------------------------------------------------------
 * NOT includes/inc_all.php and NOT includes/header.php. Two independent reasons
 * and either alone is fatal here:
 *   - they emit a whole HTML document ahead of this one;
 *   - includes/header.php:12 sends `X-Frame-Options: DENY`, which would make
 *     the frame unloadable with no console message that explains why.
 * `frame-ancestors 'self'` in the served policy is what replaces XFO.
 *
 * ---------------------------------------------------------------------------
 * THE RULE
 * ---------------------------------------------------------------------------
 * The row is bound to ONE article (kb_article_embed_kb_article_id), and this
 * endpoint re-derives access to THAT article on every request with the agent
 * lane's own rule - the same rule agent/kb_progress.php applies and the same
 * one agent/kb_article.php applies before rendering the block that frames this:
 * a live agent session, module_kb >= 1, and the article's department scope.
 * Nothing is trusted from the URL beyond "which embed is being asked for".
 *
 * That binding is the whole authorization model. Without it the id would be a
 * bare small integer naming a document with no owner, and the endpoint would be
 * an enumeration oracle over every tool any agent ever pasted into any article.
 *
 * EVERY FAILURE IS 404. "No such embed", "that embed belongs to an article in a
 * department you cannot see" and "the KB module is switched off" are one
 * answer, because the difference between them is itself information.
 */

// See the bootstrap note above: these four requires, in this order, and no others.
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';

define('FROM_KB_EMBED', true);
require_once $_SERVER['DOCUMENT_ROOT'] . '/agent/includes/kb_embed_serve.php';

/* check_login.php does the whole agent-session chain (live, enabled,
   non-archived, user_type 1) and redirects to /login.php if any of it fails.
   For a frame that 302 means the login page renders inside the frame, which is
   the correct visible outcome for "you are not signed in". */
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';

// The KB module being switched off must hide the embeds as well as the pages.
if ($config_module_enable_kb != 1) {
    kbEmbedFail(404, 'Not found');
}

/* is_string() first, because PHP makes ?id[]=1 an ARRAY and intval() on an
   array is 1 - which would silently become embed 1. Same guard, same reason, as
   client/kb_media.php's kbMediaPortalParam(). */
$id_param = isset($_GET['id']) && is_string($_GET['id']) ? $_GET['id'] : null;
$embed_id = $id_param !== null ? intval($id_param) : 0;
if ($embed_id < 1) {
    kbEmbedFail(404, 'Not found');
}

/* module_kb >= 1. Level 1, not 2: viewing an embedded tool is a READ, and
   agent/kb_article.php's own enforceUserPermission('module_kb') call gates the
   page that frames it at level 1. Requiring write here would hide the tool from
   every read-only KB role that can already read the article around it. (Named
   by symbol, not by line: that file belongs to another lane and its line
   numbers move.)

   lookupUserPermission() is consulted first only so the denial can carry a real
   status code - enforceUserPermission() answers 200 with an HTML sentence,
   which is wrong inside a frame. The canonical gate still runs underneath, so
   this endpoint cannot drift from the app's semantics. Same shape as
   agent/kb_media.php:272-283. */
$kb_permission_level = lookupUserPermission('module_kb');
if (!$kb_permission_level || $kb_permission_level < 1) {
    kbEmbedFail(404, 'Not found');
}
enforceUserPermission('module_kb', 1);

// Which article owns this embed. Grants nothing on its own - see the function's
// own comment; the check below is what decides.
$article_id = kbEmbedOwningArticleId($mysqli, $embed_id);
if ($article_id < 1) {
    kbEmbedFail(404, 'Not found');
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
    kbEmbedFail(404, 'Not found');
}

if (!$article) {
    kbEmbedFail(404, 'Not found');
}

/* The department scope, evaluated in PHP against the array
   includes/load_user_session.php:52-58 has already loaded for this request.
   Same rule as enforceClientAccess() (functions.php:3469) and
   kbMediaClientAccessOk() (agent/includes/kb_media_auth.php:148): client_id 0
   is company-wide, an admin role bypasses, a user with ZERO
   user_client_permissions rows is allowed all departments (the app-wide meaning
   of "no restriction configured" - making this endpoint stricter than the page
   that frames it would break working installs while fixing nothing), otherwise
   the (user, client) row must exist. */
$article_client_id = intval($article['kb_article_client_id']);

$kb_client_ok = ($article_client_id === 0)
    || (isset($session_is_admin) && $session_is_admin === true)
    || empty($client_access_array)
    || in_array($article_client_id, array_map('intval', $client_access_array), true);

if (!$kb_client_ok) {
    kbEmbedFail(404, 'Not found');
}

// Re-fetch with BOTH ids bound, so the row that is served is provably the row
// whose article was just authorised.
$embed = kbEmbedFetch($mysqli, $embed_id, $article_id);
if (!$embed) {
    kbEmbedFail(404, 'Not found');
}

kbEmbedServe(
    (string) ($embed['kb_article_embed_untrusted_html'] ?? ''),
    intval($embed['kb_article_embed_height'] ?? 0)
);
