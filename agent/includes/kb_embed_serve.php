<?php
/**
 * Shared serve core for the interactive-KB (IKB) escape hatch.
 *
 * ===========================================================================
 * THIS FILE ECHOES HTML THAT NO FILTER HAS EVER TOUCHED. READ THIS HEADER.
 * ===========================================================================
 *
 * kb_article_embeds.kb_article_embed_untrusted_html is the only column in this
 * database that holds author-supplied HTML which is never purified. That is not
 * an oversight - purifying it would delete exactly the thing it exists to
 * carry (a small self-contained tool: a subnet calculator, a sizing sheet).
 * It is the escape hatch Blake asked for, and the ONLY reason it is not stored
 * XSS on this application's own origin is the `sandbox` directive in the
 * response Content-Security-Policy that kbEmbedServe() sends.
 *
 * FIVE RULES, AND EVERY ONE OF THEM IS LOAD-BEARING:
 *
 * 1. NEVER ADD `allow-same-origin` TO THE SANDBOX - not to the CSP directive
 *    here, not to the iframe attribute the render layer writes. Combined with
 *    the `script-src 'unsafe-inline'` below it turns this endpoint into an XSS
 *    primitive at this origin, served out of a mediumtext column any agent with
 *    module_kb write can paste into. `allow-scripts` WITHOUT `allow-same-origin`
 *    is what puts the document in an OPAQUE origin: it cannot read
 *    document.cookie, cannot reach localStorage, cannot touch parent.document,
 *    and 'self' matches nothing for it. The two tokens together also let the
 *    framed document remove its own sandbox. This coupling cannot be enforced
 *    in code; it is written here so it cannot be missed in review.
 *
 * 2. THE `sandbox` DIRECTIVE MUST STAY IN THE RESPONSE HEADER, not only on the
 *    iframe. The block's own no-JS fallback is a plain link to this URL, so a
 *    reader can and will open it TOP-LEVEL, where an iframe attribute does not
 *    exist and `frame-ancestors` protects nothing. The header is what makes
 *    that visit opaque too. This was one of three corrections the design review
 *    made mandatory.
 *
 * 3. THE HEADER SANDBOX AND THE IFRAME `sandbox` ATTRIBUTE INTERSECT. A token
 *    present in one and absent from the other is silently lost. Both must read
 *    exactly `allow-scripts`. If a token is ever added, it has to be added in
 *    BOTH places - here and in js/kb_interactive.js.
 *
 * 4. NEVER INCLUDE inc_all.php OR header.php FROM AN ENDPOINT THAT USES THIS.
 *    includes/header.php:12 and client/includes/header.php:36 both send
 *    `X-Frame-Options: DENY`, which makes the frame unloadable with no console
 *    message that explains why. The callers bootstrap config.php + functions.php
 *    + their lane's check_login.php and nothing else, exactly as
 *    agent/includes/kb_media_serve.php's callers already do. `frame-ancestors
 *    'self'` in the CSP is what replaces XFO here.
 *
 * 5. WHAT THIS FILE DOES NOT DO: authorization. Not one line. Every caller must
 *    have finished deciding "may this principal read the ARTICLE that owns this
 *    embed" before calling kbEmbedServe(). kb_article_embed_kb_article_id is
 *    the whole authorization model - the row is bound to one article, and each
 *    lane re-derives access to that article with its own rule. That is what
 *    makes this a join rather than an enumeration oracle.
 *
 * ---------------------------------------------------------------------------
 * WHY A SEPARATE ENDPOINT AND NOT srcdoc
 * ---------------------------------------------------------------------------
 * A document's CSP comes from its OWN response headers. An `<iframe srcdoc>`
 * has no response, so it INHERITS the framer's policy - and the portal's is
 * `default-src 'self'` with no nonce and no 'unsafe-inline', so an inline
 * <script> inside a srcdoc frame on a portal page does not run at all. A
 * same-origin URL framed normally does have its own response, and the framer's
 * `default-src 'self'` only decides whether the frame may LOAD (it may - the
 * URL is same-origin). That is the whole reason this file exists rather than a
 * string in the article page.
 *
 * ---------------------------------------------------------------------------
 * WHAT WAS DELIBERATELY LEFT OUT OF THE POLICY
 * ---------------------------------------------------------------------------
 *   'unsafe-eval'   The design asked for it, on the grounds that small
 *                   calculators use `new Function`. Two of the three reviewers
 *                   said drop it and they are right: nothing in the containment
 *                   argument needs it, and it is trivially added later WITH a
 *                   note saying which embed needed it. Adding a capability
 *                   because something might want it is how a cage grows bars
 *                   that do not close.
 *   allow-modals    A phishing-prompt affordance with no containment value.
 *                   alert()/prompt() drawn by a caged document look like they
 *                   came from ITFlow.
 *   allow-popups / allow-popups-to-escape-sandbox
 *                   A live exfiltration channel that `connect-src 'none'` does
 *                   NOT close: window.open() to an external collector delivers
 *                   the request even with connect-src and form-action denied.
 *                   Withheld, permanently.
 *   allow-forms / allow-top-navigation / allow-downloads
 *                   No network, no navigation, no download. A phishing UI drawn
 *                   inside the frame is inert because it has nowhere to send a
 *                   keystroke.
 *
 * ---------------------------------------------------------------------------
 * MEASURED, NOT ASSUMED. Headless Chromium (Playwright) against THESE two
 * endpoints, 2026-09-09, on a scratch database holding a deliberately hostile
 * embed that tries every escape it can reach. Both lanes, both cases:
 *
 *   FRAMED, parent under the live portal header
 *   (`default-src 'self'; img-src 'self' data:`), iframe sandbox="allow-scripts":
 *       origin = null                       <- opaque origin
 *       document.cookie   THREW SecurityError
 *       localStorage      THREW SecurityError
 *       parent.document   THREW SecurityError
 *       top.location      THREW SecurityError
 *       window.open(...)  returned null
 *       new Function(...) THREW EvalError    <- 'unsafe-eval' really is absent
 *       its own inline <script> RAN          <- the feature works
 *       postMessage arrived; e.origin was the literal string "null", so the
 *       parent identified it by ev.source === frame.contentWindow, and the
 *       height was applied
 *       BEACONS THAT REACHED AN EXTERNAL COLLECTOR: NONE. sendBeacon, fetch,
 *       XHR, <img src>, and a form submission were all attempted; every one was
 *       refused by connect-src 'none' / img-src data: / form-action 'none'.
 *       (navigator.sendBeacon returns TRUE - it only reports that the request
 *       was queued - and the request was then blocked. "accepted" is not
 *       "delivered"; the collector is the only witness that matters.)
 *
 *   TOP-LEVEL, i.e. a reader clicking the stored fallback link: identical, and
 *       still no beacons. parent/top read succeed there only because parent and
 *       top ARE the document itself at top level.
 *
 *   NEGATIVE CONTROL - the same bytes, the same policy, `sandbox` REMOVED:
 *       origin = http://127.0.0.1:8399      <- the app's real origin
 *       document.cookie = "PHPSESSID=35984baea5182d569fce1cc05b9eaa9b"
 *       localStorage = WRITABLE
 *       window.open OPENED, and the beacon ARRIVED at the external collector
 *   That one token is the difference between a caged tool and stored XSS on
 *   this application's own origin, with the session cookie readable. It is why
 *   rules 1-3 above are written the way they are.
 */

defined('FROM_KB_EMBED') || die("Direct file access is not allowed");

/* The declared-height bounds. The author's height renders before any
 * postMessage arrives - and forever, if the embed never posts one - so it has
 * to be a number a page can actually live with. 120 is about the smallest frame
 * that can show a line of text and a button; 4000 is past any screen and is the
 * point where "tall" becomes "a denial of service on the article page". The
 * same two numbers bound the data-ikb-height purifier attribute
 * (src/KB/InteractiveBlocks.php) and the postMessage clamp in
 * js/kb_interactive.js - three layers, one pair of numbers. */
const KB_EMBED_MIN_HEIGHT = 120;
const KB_EMBED_MAX_HEIGHT = 4000;

/* Refuse to serve anything larger than this. The authoring path caps a stored
 * embed at 512 KB; this is the serve-side restatement of that cap, so a row
 * that got large by some other route (a direct database edit, a future importer
 * bug) cannot be turned into a multi-megabyte response by anyone who can guess
 * an id. mediumtext holds 16 MB, which is what makes the restatement worth
 * having. */
const KB_EMBED_MAX_BYTES = 524288;

/**
 * Bail out with a status code and a plain-text body.
 *
 * Every failure path lands here so a bad id, a deleted row or an article the
 * caller may not read produces a clean status code instead of a PHP fatal or a
 * half-written document - and, just as importantly, so none of them leak
 * whether the requested id exists. ALWAYS 404 from the callers, never 403: the
 * difference between "no such embed" and "not yours" is itself information.
 *
 * The failure response carries a MINIMAL policy of its own, not the embed
 * policy: nothing here needs to run, and a text/plain body under
 * `default-src 'none'` cannot do anything even if a future edit made it HTML.
 * `sandbox` is kept so that a top-level visit to a failing URL is opaque for
 * the same reason the success path is.
 */
function kbEmbedFail(int $status, string $message): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        header("Content-Security-Policy: sandbox; default-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'");
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: private, no-store');
    }
    echo $message;
    exit;
}

/**
 * Clamp a declared height into the range the render layer will honour.
 *
 * Called on the way OUT of the database as well as on the way in, because a
 * stored row is not a trust boundary: the purifier bounds the attribute in the
 * article, this bounds the column, and js/kb_interactive.js bounds the
 * postMessage. An unbounded integer from author-controlled markup reaching the
 * frame's height is a denial of service on the article page, which is one of
 * the three things the design review required be fixed rather than inherited.
 */
function kbEmbedClampHeight(int $height): int
{
    if ($height < KB_EMBED_MIN_HEIGHT) {
        return KB_EMBED_MIN_HEIGHT;
    }
    if ($height > KB_EMBED_MAX_HEIGHT) {
        return KB_EMBED_MAX_HEIGHT;
    }
    return $height;
}

/**
 * Fetch one embed row, bound to one article.
 *
 * BOTH ids are in the WHERE clause and both come from the caller: the embed id
 * from the request, the article id from whatever the caller has ALREADY proved
 * the principal may read. A request naming an embed that belongs to a different
 * article gets no row, so an id that leaked from an internal-only runbook is
 * not a key to it.
 *
 * Returns null for "no such row", and for "the table is not there yet" - an
 * install where this code is deployed but the 2.6.79 database update has not
 * been run. PHP 8.1+ throws on a failed query (this codebase never calls
 * mysqli_report(); see src/KB/MediaToken.php:497-505, and the same behaviour
 * was re-measured here on PHP 8.4.25), so without the catch that state is an
 * uncaught fatal inside a frame instead of an empty frame.
 */
function kbEmbedFetch(mysqli $mysqli, int $embed_id, int $article_id): ?array
{
    if ($embed_id < 1 || $article_id < 1) {
        return null;
    }

    try {
        $row = mysqli_fetch_assoc(mysqli_query(
            $mysqli,
            "SELECT kb_article_embed_id,
                    kb_article_embed_kb_article_id,
                    kb_article_embed_name,
                    kb_article_embed_untrusted_html,
                    kb_article_embed_height
             FROM kb_article_embeds
             WHERE kb_article_embed_id = $embed_id
               AND kb_article_embed_kb_article_id = $article_id
             LIMIT 1"
        ));
    } catch (\Throwable $e) {
        return null;
    }

    return $row ?: null;
}

/**
 * Fetch the article id an embed belongs to, WITHOUT authorising anything.
 *
 * The two endpoints need this because the caller supplies only the embed id:
 * the fallback link stored in an article is /agent/kb_embed.php?id=17, and
 * adding the article id to it would be a second thing to keep in step. So the
 * flow is: ask which article owns this embed, then ask the LANE'S OWN RULE
 * whether this principal may read THAT article, then fetch the row again with
 * both ids bound. This function is the first step only and grants nothing -
 * every caller must run its own check on the answer before serving a byte.
 *
 * Returns 0 when there is no such embed, or when the table is absent.
 */
function kbEmbedOwningArticleId(mysqli $mysqli, int $embed_id): int
{
    if ($embed_id < 1) {
        return 0;
    }

    try {
        $row = mysqli_fetch_assoc(mysqli_query(
            $mysqli,
            "SELECT kb_article_embed_kb_article_id
             FROM kb_article_embeds
             WHERE kb_article_embed_id = $embed_id
             LIMIT 1"
        ));
    } catch (\Throwable $e) {
        return 0;
    }

    return $row ? intval($row['kb_article_embed_kb_article_id']) : 0;
}

/**
 * Send the already-authorised embed document, with its own policy.
 *
 * $html is the raw stored bytes. It is echoed VERBATIM and on purpose - see the
 * file header. Nothing below escapes, filters or rewrites it, because doing so
 * would defeat the feature; the containment is entirely in the headers.
 */
function kbEmbedServe(string $html): void
{
    if (strlen($html) > KB_EMBED_MAX_BYTES) {
        kbEmbedFail(413, 'Embed too large');
    }

    if (!headers_sent()) {
        header('Content-Type: text/html; charset=UTF-8');

        /* THE POLICY. Every directive earns its place:
         *
         *   sandbox allow-scripts   The containment. Opaque origin, so no
         *                           cookie, no storage, no parent access, and
         *                           'self' matches nothing for this document.
         *                           Scripts run - that is the whole point - but
         *                           with nowhere to send anything. In the
         *                           HEADER so a TOP-LEVEL visit to the stored
         *                           fallback link is caged too, not only the
         *                           framed one. NEVER add allow-same-origin.
         *   default-src 'none'      Nothing loads unless named below.
         *   script-src 'unsafe-inline'
         *                           The embed's own <script>, and the height
         *                           reporter appended below. NOT 'self': under
         *                           an opaque origin 'self' matches nothing, so
         *                           writing it would be a lie that reads like a
         *                           permission. No 'unsafe-eval' - see header.
         *   style-src 'unsafe-inline'
         *                           Same reasoning for <style> and style="".
         *   img-src / font-src / media-src  data: only. A remote URL would be a
         *                           beacon: the fetch itself carries data out.
         *                           data: cannot leave the document.
         *   connect-src 'none'      No fetch, no XHR, no WebSocket, no
         *                           sendBeacon.
         *   form-action 'none'      A form drawn inside cannot submit anywhere.
         *   base-uri 'none'         The document cannot re-root its own URLs.
         *   frame-ancestors 'self'  Only this app may frame it. This is what
         *                           replaces X-Frame-Options, which must NOT be
         *                           sent here (it would make the frame
         *                           unloadable). */
        header(
            "Content-Security-Policy: "
            . "sandbox allow-scripts; "
            . "default-src 'none'; "
            . "script-src 'unsafe-inline'; "
            . "style-src 'unsafe-inline'; "
            . "img-src data:; "
            . "font-src data:; "
            . "media-src data:; "
            . "connect-src 'none'; "
            . "form-action 'none'; "
            . "base-uri 'none'; "
            . "frame-ancestors 'self'"
        );

        // Always. Without it a browser may sniff past the declared type.
        header('X-Content-Type-Options: nosniff');

        /* This URL is reachable as a top-level navigation from the stored
           fallback link, and the document inside is author-controlled. Do not
           hand it the article page's URL. */
        header('Referrer-Policy: no-referrer');

        // Authenticated content: never let a shared cache hold a copy.
        header('Cache-Control: private, no-store, max-age=0');
        header('Pragma: no-cache');

        /* Deliberately NOT sent: X-Frame-Options. includes/header.php:12 and
           client/includes/header.php:36 send DENY, which is why neither may be
           included from an endpoint that calls this function - the frame would
           simply never load and no console message would say why.
           frame-ancestors 'self' above is the modern equivalent and is what
           this response relies on. */
    }

    echo $html;

    /* The height reporter, appended AFTER the stored bytes so that an
     * unterminated document still gets it - browsers repair the tree and the
     * script ends up in the body either way.
     *
     * postMessage's target origin is '*' because the frame CANNOT learn the
     * parent's origin: it is in an opaque origin and window.parent.origin is
     * not readable. All that leaves the frame is one integer, which is why '*'
     * is acceptable here and would not be for anything else. The listener in
     * js/kb_interactive.js must prove identity with `ev.source ===
     * frame.contentWindow` - ev.origin is the literal string "null" for a
     * sandboxed document and is worthless as an identity check - and must clamp
     * the value to the same bounds this file declares.
     *
     * ResizeObserver where available, plus load and resize, plus a BOUNDED
     * fallback poll. The design's version polled every second forever, which is
     * a timer running for as long as the article is open on a page that may
     * carry several embeds. Twenty ticks over ten seconds catches late async
     * layout (a web font, an image, a script that draws on a timeout) and then
     * stops. */
    echo "\n<script>(function(){"
        . "var last=0;"
        . "function h(){try{var v=Math.max("
        . "document.documentElement.scrollHeight,document.body?document.body.scrollHeight:0);"
        . "if(v>0&&v!==last){last=v;parent.postMessage({ikbEmbedHeight:v},'*');}}catch(e){}}"
        . "addEventListener('load',h);addEventListener('resize',h);"
        . "if(window.ResizeObserver){try{new ResizeObserver(h).observe(document.documentElement);}catch(e){}}"
        . "var n=0,t=setInterval(function(){h();if(++n>=20){clearInterval(t);}},500);"
        . "h();"
        . "})();</script>\n";

    exit;
}
