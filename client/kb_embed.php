<?php
/**
 * Client Portal
 * Interactive KB escape hatch - serve endpoint, PORTAL session.
 *
 *   /client/kb_embed.php?id=<kb_article_embed_id>
 *
 * The portal-session sibling of agent/kb_embed.php. Same query shape, same
 * serve core (agent/includes/kb_embed_serve.php), a COMPLETELY different
 * authorization question - the same split client/kb_media.php makes against
 * agent/kb_media.php, and this file is modelled on it.
 *
 * READ agent/includes/kb_embed_serve.php's header BEFORE CHANGING ANYTHING
 * HERE. It is the security document for both endpoints: this response echoes
 * HTML that no filter has ever touched, and the only thing that makes that safe
 * is the `sandbox` directive in the policy that file sends.
 *
 * ---------------------------------------------------------------------------
 * BOOTSTRAP: config + functions + load_global_settings + check_login, NO CHROME
 * ---------------------------------------------------------------------------
 * NOT client/includes/inc_all.php and NOT client/includes/header.php. Two
 * independent reasons and either alone is fatal here:
 *   - they emit a whole HTML document ahead of this one;
 *   - client/includes/header.php:36 sends `X-Frame-Options: DENY`, which would
 *     make the frame unloadable with no console message that explains why.
 * `frame-ancestors 'self'` in the served policy is what replaces XFO.
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
 * The embed row is bound to ONE article (kb_article_embed_kb_article_id) and
 * that clause is re-run against the live database, on every request, against
 * the article that owns it. An internal-only runbook therefore does not leak
 * its embedded tool to a department, and another department's tool is
 * unreachable even with its id - which, being a small integer, is guessable.
 *
 * ---------------------------------------------------------------------------
 * THE PREVIEWING ADMIN WORKS THROUGH HERE UNCHANGED, AND GAINS NOTHING
 * ---------------------------------------------------------------------------
 * This is a READ, so there is no write gate to apply and nothing to skip.
 * client/includes/portal_preview.php gives a previewing admin
 * $session_contact_id = 0 and $session_user_id = 0; nothing below reads either.
 * The only session value used is $session_client_id, which check_login.php sets
 * to the previewed department in exactly the same place it sets it for a real
 * contact. So the preview sees precisely what that department's contacts see,
 * which is the entire point of a preview.
 *
 * EVERY FAILURE IS 404, for the same reason as the agent endpoint: the
 * difference between "no such embed" and "not yours" is itself information.
 *
 * ---------------------------------------------------------------------------
 * REACHABILITY: BOTH THE IFRAME AND THE NO-JS FALLBACK LINK WORK TODAY
 * ---------------------------------------------------------------------------
 * js/kb_interactive.js's bindEmbed() builds the iframe's src as a RELATIVE
 * URL ('kb_embed.php?id=' + id, no leading slash) - the same pattern
 * data-ikb-endpoint already used for the progress endpoints. That resolves
 * against the CURRENT PAGE: on client/kb_article.php it resolves to THIS
 * file, on agent/kb_article.php to agent/kb_embed.php, with no rewrite step
 * and no per-lane branch in the binder. So a department contact with
 * JavaScript - the normal case - already reaches this file, and every piece
 * of authorization and every query below is real and independently correct:
 * the client/kb_media.php pattern applied to embeds, exactly as asked.
 *
 * THE NO-JS FALLBACK LINK USES THE SAME RESOLUTION, deliberately, not a
 * hard-coded absolute URL: \ITFlow\KB\HtmlImporter::embedBlock()
 * (src/KB/HtmlImporter.php) stores it as the identical relative
 * 'kb_embed.php?id=N' the iframe uses - see that method's docblock for why
 * it departs from the kb_media convention (an absolute canonical URL
 * rewritten per-lane by MediaUrlRewriter) on purpose. No MediaUrlRewriter
 * case is needed here: a portal contact with JavaScript off or unavailable
 * still lands on THIS file directly, with no bounce to the agent login page.
 */

// See the bootstrap note above. Same four requires, same order, as
// client/kb_media.php:86-93.
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';

define('FROM_KB_EMBED', true);
require_once $_SERVER['DOCUMENT_ROOT'] . '/agent/includes/kb_embed_serve.php';

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_global_settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/client/includes/check_login.php';

// The KB module being switched off must hide the embeds as well as the pages.
if ($config_module_enable_kb != 1) {
    kbEmbedFail(404, 'Not found');
}

/* check_login.php sets this on BOTH of its paths - intval($_SESSION['client_id'])
 * for a real login, $portal_preview_client_id for a preview - and redirects
 * rather than falling through, so it is always set by the time this line runs.
 * Proved again here anyway, because this is the value that gets interpolated
 * into SQL below. isset() first, not `?? 0`: an unset value would mean
 * check_login.php had changed shape underneath this endpoint, and the honest
 * answer to that is to serve nothing - `?? 0` would quietly hand out every
 * company-wide article's embedded tools instead. */
if (!isset($session_client_id)) {
    kbEmbedFail(404, 'Not found');
}
$session_client_id = intval($session_client_id);

/* is_string() first, because PHP makes ?id[]=1 an ARRAY and intval() on an
   array is 1 - which would silently become embed 1. Same guard, same reason, as
   client/kb_media.php's kbMediaPortalParam(). */
$id_param = isset($_GET['id']) && is_string($_GET['id']) ? $_GET['id'] : null;
$embed_id = $id_param !== null ? intval($id_param) : 0;
if ($embed_id < 1) {
    kbEmbedFail(404, 'Not found');
}

// Which article owns this embed. Grants nothing on its own - see the function's
// own comment; the visibility clause below is what decides.
$article_id = kbEmbedOwningArticleId($mysqli, $embed_id);
if ($article_id < 1) {
    kbEmbedFail(404, 'Not found');
}

/* The portal's article-visibility clause, copied from client/kb_article.php's
 * detail query - the query this endpoint's answers have to agree with. If they
 * ever disagreed, a contact would see either an article whose embedded tool is
 * a permanently empty frame, or a tool belonging to an article the portal
 * refuses to show. $session_client_id is an int (asserted above), so this
 * interpolates safely. NO ROW IS A 404. */
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
    kbEmbedFail(404, 'Not found');
}

if (!$article) {
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
