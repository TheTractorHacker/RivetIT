<?php

namespace ITFlow\KB;

/**
 * Render-time rewriting of the KB media URLs inside KB article HTML.
 *
 * WHY A RENDER-TIME TRANSFORMER AT ALL, when the whole point of the canonical
 * URL design is that storage needs no rewriting. Three reasons, in order of how
 * load-bearing they are:
 *
 *  1. THE API HAS NO COOKIE. agent/kb_media.php authenticates a web request
 *     with the session cookie, which is exactly why the stored URL carries no
 *     signature and survives a TinyMCE round trip. The Android app cannot send
 *     one: KbArticleDetailScreen.kt hands article HTML to a WebView via
 *     loadDataWithBaseURL(), so every <img> is fetched by the system network
 *     stack with no header and no cookie of ours. So api/v1/kb.php has to turn
 *     each canonical URL into an absolute signed one at RESPONSE time. That is
 *     toSigned().
 *
 *  2. THE PORTAL CANNOT REACH /agent/. A department contact has a portal
 *     session, not an agent session, so /agent/kb_media.php would 302 them to
 *     the agent login. client/kb_article.php therefore repoints the same
 *     canonical URL at the portal's own serve endpoint, client/kb_media.php.
 *     That is toPortal(), and it is the CredentialReferenceRenderer pattern
 *     (src/Knowledge/) applied to a second kind of token: rewrite for display,
 *     never for storage.
 *
 *  3. THE LEGACY CORPUS. Before this change the DOCX importer baked raw
 *     /uploads/kb/<article_id>/<name> paths straight into kb_article_content
 *     and TinyMCE's uploader baked flat /uploads/kb/<name> ones
 *     (agent/kb_article_upload.php). Those paths are served by nginx with NO
 *     authentication at all - that is the defect this whole change exists to
 *     close - and the moment nginx starts denying /uploads/kb they become dead
 *     images. Measured on the live database on 2026-09-08: 3 kb_articles rows
 *     and 2 kb_article_versions rows contain them, all article-scoped, all from
 *     the DOCX importer. A storage migration is the real fix; recognising the
 *     old shape here as well means a row that has not been migrated yet still
 *     renders, on every surface, instead of showing a broken image. This is
 *     belt to the migration's braces, and it is cheap: one extra branch in
 *     describe(). All three of those articles are kb_article_client_id = 0 AND
 *     kb_article_client_visible = 1, i.e. every one of them renders in the
 *     department portal, which is why the portal transform below has to
 *     recognise the legacy shape too and not just the canonical one.
 *
 * WHAT THIS CLASS DELIBERATELY DOES NOT DO. It never decides who may see an
 * image. Every URL it emits points at a serve endpoint that re-runs the full
 * permission chain from the database - module_kb (or, in the portal, the
 * article's client-visible scope), the article's department scope, the row
 * still existing. A forged or hand-edited ?a= in stored HTML therefore buys
 * nothing: it names an article, and the endpoint checks whether the caller may
 * read THAT article.
 *
 * ---------------------------------------------------------------------------
 * WHICH SHAPES ARE REWRITTEN, AND WHY THE WALKER IS ANCHORED ON TAGS
 * ---------------------------------------------------------------------------
 * Three carriers of a media URL survive HTMLPurifier and are all rewritten:
 *
 *   <img src="...">                     the overwhelmingly common one
 *   <a href="...">                      a HYPERLINKED attachment or diagram
 *   style="background-image:url(...)"   purifier keeps CSS url() properties
 *
 * All three were measured on 2026-09-08 by running the bundled HTMLPurifier
 * 4.15.0 under the real config from client/kb_article.php: an <a href> and a
 * style="background-image:url(...)" both survive purification (the CSS is
 * normalised to url(&quot;...&quot;)), while srcset is dropped outright, which
 * is why srcset is not handled below.
 *
 * The live corpus contains none of those two shapes TODAY - measured the same
 * day against the live database: 0 kb_articles rows match 'href="/uploads/' and
 * 0 match 'url(' - so this is not repairing existing rows. It is closing a
 * shape an author can create at any time from TinyMCE's link dialog, and one
 * that has no other rewrite path: after nginx starts denying /uploads/kb an
 * unrewritten href is a permanently dead link, and on the Android app a
 * hyperlinked attachment is handed to an EXTERNAL browser, which has neither a
 * cookie nor a signature.
 *
 * The walker matches START TAGS first and only rewrites inside one. The earlier
 * version scanned the whole document for a bare src="..." run, and that had two
 * measured consequences:
 *
 *   - DESYNC. HTMLPurifier does NOT escape a double quote in a TEXT node
 *     (measured: '<p>Set src="the path</p>' survives byte-identical). A single
 *     unbalanced quote in prose therefore flipped quote parity and made the
 *     regex swallow the NEXT real <img>'s src, so that image was silently left
 *     at its raw /uploads path - in the API's JSON, which promises the opposite.
 *   - PROSE. '<pre>&lt;img src="/uploads/kb/13/x.png"&gt;</pre>' - a runbook
 *     showing markup, which is exactly what this KB holds - had its VISIBLE
 *     TEXT rewritten, so the documentation displayed something that was never
 *     written.
 *
 * Anchoring on '<' fixes both, because purifier escapes a text-node '<' to
 * '&lt;' (measured) so a '<' in its output always starts a real tag, and it
 * escapes a '>' inside an attribute value to '&gt;' (measured) so [^>]* cannot
 * run past the end of the tag it started in. Those two facts are the whole
 * proof: inside a matched tag, quotes are balanced by construction.
 *
 * IF THE INPUT IS NOT PURIFIER OUTPUT the walker degrades to MISSING rewrites,
 * never to corrupting the document: a tag is returned byte-identical unless a
 * media URL was actually recognised inside it, so a mis-delimited tag simply
 * goes unrewritten. All four call sites (agent/kb_article.php,
 * agent/modals/kb_article/kb_article_version_view.php, client/kb_article.php,
 * api/v1/kb.php) run this on purifier output; that is a precondition for
 * completeness, not for safety.
 */
final class MediaUrlRewriter
{
    /** The one canonical endpoint path that may appear in kb_article_content. */
    public const CANONICAL_PATH = '/agent/kb_media.php';

    /** The portal's own serve endpoint, which never accepts a signature. */
    public const PORTAL_PATH = '/client/kb_media.php';

    private string $base_host;
    private string $principal;

    /**
     * $base_host is $config_base_url, which is HOST-ONLY on this codebase
     * (setup/index.php:57 writes $_SERVER['HTTP_HOST'] into it; functions.php
     * builds "https://$config_base_url/..." the same way). $principal is the
     * MediaToken principal string for the API caller - 't<token_id>',
     * 'k<api_key_id>' or 'u<user_id>'.
     */
    public function __construct(string $base_host, string $principal)
    {
        $this->base_host = $base_host;
        $this->principal = $principal;
    }

    /**
     * API: canonical (or legacy) -> absolute signed URL.
     *
     * MUST BE CALLED AFTER HTMLPurifier, NEVER BEFORE. HTMLPurifier fills a
     * missing alt attribute from the src basename, so purifying content that
     * already contained a signed URL yields, measured on PHP 8.4 with the
     * bundled HTMLPurifier 4.15.0:
     *
     *   alt="kb_media.php?a=1&amp;f=x.png&amp;p=t4&amp;e=99&amp;s=deadbeef"
     *
     * i.e. the entire capability token in copyable, screen-reader-readable text
     * on every broken image. Purifying first and signing second means the alt is
     * derived from the canonical URL and never sees a token. (Attr.DefaultImageAlt
     * is set to '' in every KB purifier config as well, so the alt is empty
     * rather than a filename - two mitigations because either alone would be one
     * refactor away from being lost.)
     *
     * When signing is unavailable - no key on this install, because config.php
     * lost $config_settings_enc_key or the 2.6.78 database update has not run -
     * MediaToken::signedUrl() returns null and this falls back to the canonical
     * SIGNATURE-FREE URL. That fails closed for the app (no cookie, so the serve
     * endpoint redirects to login and the image is broken) rather than emitting
     * a raw /uploads/kb path, which would fail OPEN and re-introduce the exact
     * defect this change closes.
     */
    public function toSigned(string $html): string
    {
        return self::rewriteMediaUrls($html, function (array $media): string {
            $ttl = $media['kind'] === MediaToken::KIND_ATTACHMENT
                ? MediaToken::TTL_ATTACH
                : MediaToken::TTL_IMAGE;

            $signed = MediaToken::signedUrl(
                $this->base_host,
                $media['kind'],
                $media['ref'],
                $media['file'],
                $this->principal,
                $ttl
            );

            if ($signed === null) {
                return self::canonicalUrl(self::CANONICAL_PATH, $media);
            }

            /* &download=1 rides OUTSIDE the signature by design (see
             * MediaToken::signedUrl()'s comment and agent/kb_media.php's
             * $force_download): it selects inline-vs-attachment disposition and
             * can never change which bytes are served, so appending it here
             * cannot invalidate the token. Preserving it matters because the
             * only URLs that carry it are <a href> "Download" links, and href
             * is a shape this class now rewrites. */
            return $media['download'] ? $signed . '&download=1' : $signed;
        });
    }

    /**
     * Agent web pages: legacy -> canonical, and canonical -> itself.
     *
     * A no-op for content that has already been migrated, which is why it is
     * safe to call unconditionally on every render. Emits a root-relative URL
     * with LITERAL '&' separators; the caller is putting it back into an HTML
     * attribute, so rewriteMediaUrls() escapes it on the way in.
     *
     * Note this runs on the VIEW path only. agent/modals/kb_article/kb_article_edit.php
     * loads the RAW stored HTML into TinyMCE, so editing an unmigrated article
     * still round-trips its legacy URL - normalising in the editor would quietly
     * rewrite stored content on every save, which is a storage change disguised
     * as a render change and belongs in the migration instead.
     */
    public static function toAgentCanonical(string $html): string
    {
        return self::rewriteMediaUrls(
            $html,
            static fn (array $media): string => self::canonicalUrl(self::CANONICAL_PATH, $media)
        );
    }

    /**
     * Department portal: canonical (or legacy) -> the portal's own endpoint.
     *
     * Root-relative rather than the bare 'kb_media.php?...' a relative rewrite
     * would give: identical resolution from /client/kb_article.php, but immune
     * to the page ever moving or acquiring a <base> tag. Everything else in the
     * stored corpus is already root-relative, so this stays consistent with it.
     *
     * client/kb_media.php takes the SAME query shape and re-derives access with
     * the portal's own article SQL (client_visible = 1, client_id IN (0,
     * $session_client_id), not archived). It never accepts a signature - a
     * portal request always has a cookie, so there is no portal principal type
     * at all, which is one fewer thing to get wrong.
     */
    public static function toPortal(string $html): string
    {
        return self::rewriteMediaUrls(
            $html,
            static fn (array $media): string => self::canonicalUrl(self::PORTAL_PATH, $media)
        );
    }

    /**
     * Is there anything here worth rewriting? Mirrors
     * CredentialReferenceRenderer::containsReference() - a cheap predicate for
     * callers that want to skip work or log, never a security check. It answers
     * on the RAW bytes, so a media path whose characters were entity-encoded
     * would be missed here exactly as it is missed by the per-tag early-out in
     * rewriteMediaUrls(); no producer in this codebase emits that shape, and
     * describe() is where the real recognition happens.
     */
    public static function containsMedia(string $html): bool
    {
        return stripos($html, self::CANONICAL_PATH) !== false
            || stripos($html, '/uploads/kb/') !== false;
    }

    /**
     * The engine. Walks every START TAG and rewrites the media URLs inside it,
     * handing each parsed media descriptor to $map, which returns the
     * replacement URL. See the class comment for why the anchor is the tag and
     * not the attribute.
     *
     * Every pattern here is linear - one unnested negated class each - so
     * catastrophic backtracking cannot occur and PREG_BACKTRACK_LIMIT_ERROR is
     * unreachable at these sizes. Measured on 2026-09-08 against the live
     * database's own worst case: the largest KB content blob on the install is
     * a 75,077-byte kb_article_versions row (largest kb_article_content is
     * 61,482), and a synthetic blob of that size carrying 525 images - an order
     * of magnitude more than any real article - costs 2.8 ms through toPortal()
     * and 5.1 ms through toSigned() on this PHP 8.4.25. Content with no media
     * at all costs 0.06 ms, because containsMedia() short-circuits it.
     *
     * The `?? $html` fallbacks are there only so that a future pattern change
     * cannot turn a PCRE failure into a silently empty article.
     */
    private static function rewriteMediaUrls(string $html, callable $map): string
    {
        if ($html === '' || !self::containsMedia($html)) {
            return $html;
        }

        $out = preg_replace_callback(
            '/<[a-zA-Z][^>]*>/',
            static function (array $m) use ($map): string {
                /* Per-tag early-out, the same cheap predicate containsMedia()
                 * applies to the whole document. Most tags in an article carry
                 * no URL at all, and skipping them here makes "a tag with no
                 * media comes back byte-identical" structural rather than a
                 * property of the rewriting code below happening to be a no-op. */
                if (!self::containsMedia($m[0])) {
                    return $m[0];
                }
                return self::rewriteTag($m[0], $map);
            },
            $html
        );

        return $out ?? $html;
    }

    /**
     * Rewrite the media URLs inside ONE start tag.
     *
     * $tag begins at '<' and ends at the first '>', so - on purifier output,
     * where a '>' inside an attribute value is escaped to '&gt;' - every
     * attribute it contains is complete and every quote in it is balanced. That
     * is what makes the [^"]* below safe: it cannot leave the tag.
     */
    private static function rewriteTag(string $tag, callable $map): string
    {
        // src= and href=. Only these two: purifier drops srcset entirely
        // (measured), and <link>/<base> are not in its allowed element set, so
        // href only ever reaches here on <a> and <area>.
        $tag = preg_replace_callback(
            '/\b(src|href)\s*=\s*"([^"]*)"/i',
            static function (array $m) use ($map): string {
                $replacement = self::mapAttributeUrl($m[2], $map);
                return $replacement === null
                    ? $m[0]
                    : $m[1] . '="' . $replacement . '"';
            },
            $tag
        ) ?? $tag;

        // style="...url(...)...". Purifier keeps background-image and
        // list-style-image and normalises both to url("...") with the quotes
        // entity-escaped inside the attribute (measured).
        $tag = preg_replace_callback(
            '/\bstyle\s*=\s*"([^"]*)"/i',
            static function (array $m) use ($map): string {
                $replacement = self::mapStyleAttribute($m[1], $map);
                return $replacement === null
                    ? $m[0]
                    : 'style="' . $replacement . '"';
            },
            $tag
        ) ?? $tag;

        return $tag;
    }

    /**
     * One src/href attribute value, escaped in and escaped out. null means
     * "not KB media, leave the attribute byte-identical".
     */
    private static function mapAttributeUrl(string $escaped_value, callable $map): ?string
    {
        /* The attribute value is HTML-escaped, so '&amp;' has to become '&'
         * before parse_url()/parse_str() see it. Decoding here and re-escaping
         * on the way out is what makes the '&' vs '&amp;' ambiguity
         * structurally impossible to get wrong, rather than a thing every regex
         * has to remember. */
        $decoded = html_entity_decode($escaped_value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $media = self::describe($decoded);
        if ($media === null) {
            return null;
        }

        return htmlspecialchars($map($media), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * One style attribute value. Returns the re-escaped value, or null when no
     * url() in it was KB media - in which case the caller leaves the attribute
     * byte-identical rather than round-tripping it through the entity
     * decoder/encoder, which would normalise unrelated entities for no reason.
     */
    private static function mapStyleAttribute(string $escaped_value, callable $map): ?string
    {
        $css = html_entity_decode($escaped_value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $changed = false;

        /* Accepts the three CSS url() spellings. Purifier only ever emits the
         * double-quoted one, but this also runs on whatever a future producer
         * writes, and an unquoted url() is the shape a hand-written style
         * attribute usually has. The unquoted branch stops at whitespace and at
         * ')' , which is exactly CSS's own rule for an unquoted url token. */
        $out = preg_replace_callback(
            '/\burl\(\s*(?:"([^"\r\n]*)"|\'([^\'\r\n]*)\'|([^"\'()\s\r\n]*))\s*\)/i',
            static function (array $m) use ($map, &$changed): string {
                /* ?? '' on every group: PHP omits trailing groups that did
                 * not participate, so url("") - alternative 1 matching EMPTY -
                 * would otherwise read an unset $m[2]. */
                $url = ($m[1] ?? '') !== ''
                    ? $m[1]
                    : ((($m[2] ?? '') !== '') ? $m[2] : ($m[3] ?? ''));
                if ($url === '') {
                    return $m[0];
                }

                $media = self::describe($url);
                if ($media === null) {
                    return $m[0];
                }

                $replacement = $map($media);

                /* A CSS url("...") is a second quoting context stacked inside
                 * the HTML attribute, and this class does not own every byte of
                 * $replacement: toSigned() interpolates $base_host, which comes
                 * from config.php's $config_base_url and is ultimately whatever
                 * HTTP_HOST said at setup time. Rather than invent a CSS escape,
                 * refuse: a character that could close the url() or the string
                 * means the original is left exactly as it was. Every URL this
                 * class builds for a well-formed install is unaffected - the
                 * reference name charset is [A-Za-z0-9_-.] and everything else
                 * is digits, hex or rawurlencode() output. */
                if (strpbrk($replacement, "\"'()\\ \t\r\n") !== false) {
                    return $m[0];
                }

                $changed = true;
                return 'url("' . $replacement . '")';
            },
            $css
        );

        if ($out === null || !$changed) {
            return null;
        }

        return htmlspecialchars($out, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Recognise a KB media URL and reduce it to the tuple the token signs:
     * ['kind' => att|img|pool, 'ref' => int, 'file' => string,
     *  'download' => bool]. null means "not KB media" and the URL is left
     * byte-identical.
     *
     * ABSOLUTE URLS ARE ALWAYS REJECTED, and that is what makes every one of
     * these transforms idempotent: toSigned() emits absolute URLs, so running it
     * twice cannot double-sign, and a legitimately external image
     * (https://example.com/logo.png) is never touched. A data: URI is rejected
     * by the same rule.
     *
     * PORTAL_PATH is not accepted either, so toPortal() is idempotent for the
     * same reason and an agent page never turns a pasted /client/ URL into
     * anything - it renders as the dead link it is, which is visible, rather
     * than being quietly repaired.
     *
     * Both the canonical shape and the pre-migration /uploads/kb one are
     * accepted; see the class comment for why the old shape is still here. Only
     * the two shapes the two writers actually produce are recognised:
     * /uploads/kb/<digits>/<name> and flat /uploads/kb/<name>. A deeper path
     * such as /uploads/kb/13/sub/x.png is deliberately NOT recognised, because
     * the serve endpoints cannot serve it either - kbMediaValidReferenceName()
     * forbids a '/' - so inventing a URL for it would produce a 404 that looks
     * like a bug in the endpoint rather than an unrecognised path.
     */
    private static function describe(string $url): ?array
    {
        if ($url === '' || strlen($url) > 2048) {
            return null;
        }

        /* parse_url() SILENTLY REWRITES CONTROL CHARACTERS in what it returns -
         * measured on PHP 8.4.25, parse_url("/uploads/kb/13/x\0.png") comes back
         * with path "/uploads/kb/13/x_.png", NUL replaced by '_'. So a src
         * carrying a control character can be recognised here under a name that
         * is not the name on disk. The consequence is a 404, not a wrong file:
         * the substituted name still lives in the same article directory, which
         * the caller has already been authorised for, and the serve endpoint
         * re-validates it against the same character class before touching the
         * filesystem. Worth knowing about, because it is invisible otherwise. */
        $parts = parse_url($url);
        if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
            return null;
        }

        $path = $parts['path'] ?? '';

        $query = [];
        parse_str($parts['query'] ?? '', $query);

        /* Disposition, carried through every transform. agent/kb_media.php
         * decides on `!empty($_GET['download'])`, so this uses the identical
         * test rather than a stricter one - if the two disagreed, a link would
         * change behaviour purely by being rendered. */
        $download = !empty($query['download']);

        if ($path === self::CANONICAL_PATH) {
            // Same precedence agent/kb_media.php applies, and it has to stay
            // that way: if the two disagreed about which parameter wins, the
            // tuple signed here would not be the tuple checked there and every
            // such URL would 403.
            /* is_string(), not just isset(): parse_str() turns "?f[]=x" into an
             * ARRAY, and a (string) cast on that raises "Array to string
             * conversion" and yields the literal "Array". Requiring a string
             * puts the array case on the same "leave it alone" path as a
             * missing parameter, silently and without a warning in the log.
             * agent/kb_media.php's kbMediaParam() guards the same hole from the
             * other side, for the same reason. */
            if (isset($query['att'])) {
                return is_string($query['att'])
                    ? self::descriptor(MediaToken::KIND_ATTACHMENT, $query['att'], '', $download)
                    : null;
            }
            if (isset($query['a'])) {
                $file = $query['f'] ?? '';
                return is_string($query['a']) && is_string($file)
                    ? self::descriptor(MediaToken::KIND_IMAGE, $query['a'], $file, $download)
                    : null;
            }
            if (isset($query['f'])) {
                return is_string($query['f'])
                    ? self::descriptor(MediaToken::KIND_POOL, '0', $query['f'], $download)
                    : null;
            }
            return null;
        }

        // Legacy, article-scoped: /uploads/kb/<article_id>/<reference_name>
        if (preg_match('#^/uploads/kb/([0-9]+)/([^/]+)$#', $path, $m)) {
            return self::descriptor(MediaToken::KIND_IMAGE, $m[1], rawurldecode($m[2]), $download);
        }

        // Legacy, TinyMCE pool: /uploads/kb/<reference_name>, flat.
        if (preg_match('#^/uploads/kb/([^/]+)$#', $path, $m)) {
            return self::descriptor(MediaToken::KIND_POOL, '0', rawurldecode($m[1]), $download);
        }

        return null;
    }

    /**
     * Build and validate one descriptor.
     *
     * Validation lives here rather than at the call sites so there is exactly
     * one answer to "is this a well-formed media reference". A reference that
     * fails is left ALONE rather than repaired: a URL we cannot parse is a URL
     * we do not understand, and inventing one for it would be guessing at which
     * bytes the author meant.
     *
     * MediaToken::isValidReferenceName() delegates to functions.php's
     * isUploadReferenceName(), which is the SINGLE definition of a valid
     * reference name in this codebase: /^[A-Za-z0-9_-]+\.[A-Za-z0-9]+$/ plus a
     * 255-character cap. It is deliberately the permissive form, because that
     * is what both on-disk name generators actually produce - randomString()
     * (functions.php:12) is base64url, so '-' and '_' occur in a measured 6.15%
     * of uploads.
     */
    private static function descriptor(string $kind, string $ref_raw, string $file, bool $download): ?array
    {
        if (!ctype_digit($ref_raw) || strlen($ref_raw) > 10) {
            return null;
        }
        $ref = (int) $ref_raw;

        if ($kind === MediaToken::KIND_ATTACHMENT) {
            if ($ref < 1) {
                return null;
            }
            $file = '';
        } elseif ($kind === MediaToken::KIND_IMAGE) {
            if ($ref < 1 || !MediaToken::isValidReferenceName($file)) {
                return null;
            }
        } else {
            // Pool files have no owning record at all, so ref is pinned to 0 -
            // the same pinning MediaToken::tupleOk() enforces, so that a pool
            // token can never be shaped like an image token.
            $ref = 0;
            if (!MediaToken::isValidReferenceName($file)) {
                return null;
            }
        }

        return ['kind' => $kind, 'ref' => $ref, 'file' => $file, 'download' => $download];
    }

    /**
     * The signature-free URL for a descriptor, against a given endpoint path.
     * Literal '&' - the attribute and CSS writers above escape it for their own
     * context, and a JSON caller wants it raw.
     */
    private static function canonicalUrl(string $endpoint, array $media): string
    {
        if ($media['kind'] === MediaToken::KIND_ATTACHMENT) {
            $params = 'att=' . $media['ref'];
        } elseif ($media['kind'] === MediaToken::KIND_IMAGE) {
            $params = 'a=' . $media['ref'] . '&f=' . rawurlencode($media['file']);
        } else {
            $params = 'f=' . rawurlencode($media['file']);
        }

        if ($media['download']) {
            $params .= '&download=1';
        }

        return $endpoint . '?' . $params;
    }
}
