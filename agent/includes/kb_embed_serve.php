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
 *                   came from RivetIT.
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
 * embed that tries every escape it can reach. Both lanes, both cases. AT THE
 * TIME OF THIS MEASUREMENT the iframe and the postMessage listener below were
 * the test harness's own, not this application's, because js/kb_interactive.js
 * had no 'embed' binder yet. That gap has SINCE BEEN CLOSED in this same
 * worktree: js/kb_interactive.js now registers one (bindEmbed(), plus
 * wireEmbedMessages()/onEmbedHeight() for the height reports this file's own
 * script appends below) and builds the iframe with the identical
 * sandbox="allow-scripts" attribute and the identical ev.source identity
 * check this measurement exercised - see the "THREE LAYERS" note a few lines
 * down for exactly what each piece does today. What this measurement does
 * NOT cover is an end-to-end headless-browser run THROUGH that real binder
 * rather than the harness's stand-in - not because no Playwright toolchain
 * exists in this environment (a Python-Playwright install works fine here
 * and was the path used for other pages' verification this round), but
 * because it was not re-run against these two endpoints specifically after
 * js/kb_interactive.js's binder landed. The response policy these two
 * endpoints send - the only thing a missing or buggy binder could not
 * weaken, since it is enforced by the browser against the HTTP response
 * regardless of what framed it - is what this measurement verifies and
 * remains true either way.
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
 * (\ITFlow\KB\InteractiveBlocks::EMBED_HEIGHT_MIN/MAX, src/KB/InteractiveBlocks.php).
 *
 * THREE LAYERS, and as of this file's own history it is worth being precise
 * about which is which rather than asserting a round number:
 *   1. WRITE TIME - InteractiveBlocks::clampHeight() bounds data-ikb-height in
 *      the stored article (that file, another lane's).
 *   2. SERVE TIME - kbEmbedClampHeight() below bounds this file's own
 *      X-Ikb-Embed-Height response header. Nothing in this codebase reads
 *      that header today - js/kb_interactive.js's bindEmbed() sizes the
 *      iframe from data-ikb-height (layer 1) instead - so this layer is
 *      defense in depth on a column that is not a trust boundary (see
 *      kbEmbedClampHeight()'s docblock), not something anything currently
 *      depends on.
 *   3. RUNTIME - js/kb_interactive.js's onEmbedHeight() clamps the postMessage
 *      the height-reporter script below sends, to these same two numbers
 *      (EMBED_HEIGHT_MIN/EMBED_HEIGHT_MAX there), before writing it to the
 *      frame's CSSOM height. This is real and wired in - wireEmbedMessages()
 *      identifies the sender with `ev.source === embeds[i].contentWindow`,
 *      exactly the check the height-reporter script's own comment below
 *      requires and for the same reason (`ev.origin` is the literal string
 *      "null" for a sandboxed opaque-origin document and proves nothing). */
const KB_EMBED_MIN_HEIGHT = 120;
const KB_EMBED_MAX_HEIGHT = 4000;

/* Refuse to serve anything larger than this. The authoring path caps a stored
 * embed at 50 MB (src/KB/HtmlImporter.php MAX_EMBED_BYTES - both must agree);
 * this is the serve-side restatement of that cap, so a row that got large by
 * some other route (a direct database edit, a future importer bug) cannot be
 * turned into an even-larger response by anyone who can guess an id. The
 * column is longtext (4 GB, DB migration 2.6.82 -> 2.6.83) - was mediumtext
 * (16 MB) when this cap was still 512 KB, which is what made the restatement
 * worth having; it still is, just against a wider ceiling now. */
const KB_EMBED_MAX_BYTES = 52428800;

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
    /* Same refuse-rather-than-degrade rule as kbEmbedServe() below, applied
     * for symmetry even though $message is always a constant string from a
     * caller in this codebase (never attacker data) and so cannot itself be
     * turned into script by a missing CSP. The point is not this call site -
     * it is that "if headers_sent(), skip the headers but echo anyway" is a
     * pattern this file must never contain anywhere, including here, so a
     * future edit that starts passing a less-trusted $message does not
     * silently inherit a fail-open path that was only ever safe by accident. */
    if (headers_sent($sent_file, $sent_line)) {
        error_log("kb_embed_serve: kbEmbedFail($status) refused - output already started at $sent_file:$sent_line");
        exit;
    }
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header("Content-Security-Policy: sandbox; default-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: private, no-store');
    echo $message;
    exit;
}

/**
 * Clamp a declared height into the range the render layer will honour.
 *
 * WHAT ACTUALLY CALLS THIS, so a future reader does not have to grep for it:
 * kbEmbedServe() below, on the way OUT, to set the X-Ikb-Embed-Height response
 * header. That is the ONLY call site in this codebase - a previous version of
 * this comment claimed a "three layers, one pair of numbers" story while this
 * function had zero callers at all. It is wired in now, and so is the third
 * layer that comment referenced: js/kb_interactive.js's bindEmbed() /
 * onEmbedHeight() (see the constants block near the top of that file). See
 * KB_EMBED_MIN_HEIGHT's own comment above for which of the three layers does
 * what today.
 *
 * \ITFlow\KB\InteractiveBlocks::clampHeight() (src/KB/InteractiveBlocks.php)
 * is the SEPARATE function that bounds kb_article_embed_height on the way IN,
 * at write time; it resets an out-of-range value to EMBED_HEIGHT_DEFAULT
 * (480) rather than pinning it to the nearer edge, which is the right choice
 * for something an author just typed. This function pins to the nearer edge
 * instead, which is the right choice for a value being reported about a row
 * that already exists: a stored row is not a trust boundary (a direct
 * database edit, or a future importer bug, could still leave the column
 * out of range), and re-deriving "closest valid height" from a corrupt value
 * is more useful to a caller than silently substituting an unrelated default.
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
 *
 * $height is kb_article_embeds.kb_article_embed_height, UNCLAMPED - clamping
 * is this function's job (via kbEmbedClampHeight()), not the caller's, so
 * every caller can pass the raw column straight through.
 *
 * REFUSING BEATS DEGRADING. The CSP set below - specifically its `sandbox`
 * directive - is the ONLY thing standing between $html and stored XSS on this
 * application's own origin (see the file header). A response that reaches the
 * browser without it is not a smaller version of this feature, it is a
 * different and dangerous one, so headers_sent() is checked FIRST, as a hard
 * precondition: if it is true, NOTHING is echoed and the request ends with no
 * body at all. There is no version of "serve anyway, minus the policy" that is
 * an acceptable degradation here.
 */
function kbEmbedServe(string $html, int $height): void
{
    if (strlen($html) > KB_EMBED_MAX_BYTES) {
        kbEmbedFail(413, 'Embed too large');
    }

    /* Raising KB_EMBED_MAX_BYTES from 512 KiB to 50 MiB (see the constant's
     * comment) made "no-store, re-fetch on every article view" a real cost
     * instead of a rounding error. This computes an ETag from the bytes
     * actually being served (not the stored kb_article_embed_sha256 column -
     * this stays correct even if a future edit path ever let those drift)
     * and answers 304 on a match, so a browser that already has this exact
     * embed cached does not re-download it on every view. Cache-Control
     * still says "private" (never a shared cache) and "no-cache" (never
     * served without asking first) - this is revalidate-every-time, not
     * cache-and-trust. */
    $etag = '"' . hash('sha256', $html) . '"';
    $if_none_match = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
    if ($if_none_match !== '' && $if_none_match === $etag) {
        if (headers_sent($sent_file, $sent_line)) {
            error_log("kb_embed_serve: refused 304 - output already started at $sent_file:$sent_line");
            exit;
        }
        http_response_code(304);
        header('ETag: ' . $etag);
        header('Cache-Control: private, no-cache');
        header('Pragma: no-cache');
        exit;
    }

    /* Checked BEFORE a single header call, not with `if (!headers_sent())`
     * wrapped around the header block below: that shape is exactly the bug
     * this function used to have - degrade to a cageless echo the moment
     * anything upstream (a notice, a stray byte, a BOM in any file the
     * bootstrap chain requires) had already produced output. Refuse instead,
     * with the file:line PHP itself attributes the stray output to, so the
     * operator can find and fix the actual leak rather than silently serving
     * unsandboxed script on this origin in the meantime. */
    if (headers_sent($sent_file, $sent_line)) {
        /* No http_response_code() call here - it would be a guaranteed no-op
         * (headers_sent() just proved a response is already underway) that
         * only adds a "Cannot set response code - headers already sent" PHP
         * warning of its own to whatever the real leak already emitted. The
         * client is left with whatever status the leaked output implied; the
         * error_log line is what actually matters for tracking this down. */
        error_log("kb_embed_serve: refused to serve - output already started at $sent_file:$sent_line, cannot guarantee the sandbox CSP");
        exit;
    }

    header('Content-Type: text/html; charset=UTF-8');

    /* Defense in depth on a column that is not a trust boundary (see
     * kbEmbedClampHeight()'s docblock). Nothing in this codebase reads this
     * header today - js/kb_interactive.js's bindEmbed() sizes the frame's
     * INITIAL height from the purified data-ikb-height attribute instead (see
     * the "THREE LAYERS" note above), which is already bounded server-side
     * and does not need a round trip to this response's headers to get a
     * number. This header is left in place anyway: it costs nothing to send
     * and is available to a future binder revision that wants the DATABASE
     * column's value specifically (which can differ from data-ikb-height if
     * the two are ever edited out of step) without adding a second request. */
    header('X-Ikb-Embed-Height: ' . kbEmbedClampHeight($height));

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

    // Authenticated content: never let a shared cache hold a copy, and never
    // served without revalidating first (see the ETag/If-None-Match block
    // above - this is what makes revalidation cheap instead of a full re-fetch).
    header('Cache-Control: private, no-cache');
    header('Pragma: no-cache');
    header('ETag: ' . $etag);

    /* Deliberately NOT sent: X-Frame-Options. includes/header.php:12 and
       client/includes/header.php:36 send DENY, which is why neither may be
       included from an endpoint that calls this function - the frame would
       simply never load and no console message would say why.
       frame-ancestors 'self' above is the modern equivalent and is what
       this response relies on. */

    echo $html;

    /* The height reporter, appended AFTER the stored bytes so that an
     * unterminated document still gets it - browsers repair the tree and the
     * script ends up in the body either way.
     *
     * postMessage's target origin is '*' because the frame CANNOT learn the
     * parent's origin: it is in an opaque origin and window.parent.origin is
     * not readable. All that leaves the frame is one integer, which is why '*'
     * is acceptable here and would not be for anything else.
     *
     * THE PARENT-SIDE LISTENER: js/kb_interactive.js's wireEmbedMessages(),
     * one `window.addEventListener('message', ...)` for the whole page,
     * wired lazily on the first embed a page actually mounts. It proves
     * identity with `ev.source === embeds[i].contentWindow` - `ev.origin` is
     * the literal string "null" for a sandboxed opaque-origin document and
     * proves nothing - and onEmbedHeight() clamps the reported value to
     * EMBED_HEIGHT_MIN/EMBED_HEIGHT_MAX (that file's copy of the two numbers
     * this file declares as KB_EMBED_MIN_HEIGHT/KB_EMBED_MAX_HEIGHT) before
     * it ever reaches the frame's CSSOM height write.
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
