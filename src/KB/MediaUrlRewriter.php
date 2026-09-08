<?php

namespace ITFlow\KB;

/**
 * Render-time rewriting of the <img src> values inside KB article HTML.
 *
 * WHY A RENDER-TIME TRANSFORMER AT ALL, when the whole point of the canonical
 * URL design is that storage needs no rewriting. Three reasons, in order of how
 * load-bearing they are:
 *
 *  1. THE API HAS NO COOKIE. agent/kb_media.php authenticates a web request
 *     with the session cookie, which is exactly why the stored URL carries no
 *     signature and survives a TinyMCE round trip. The Android app cannot send
 *     one: KbArticleDetailScreen.kt:117 hands article HTML to a WebView via
 *     loadDataWithBaseURL(), so every <img> is fetched by the system network
 *     stack with no header and no cookie of ours. So api/v1/kb.php has to turn
 *     each canonical URL into an absolute signed one at RESPONSE time. That is
 *     toSigned().
 *
 *  2. THE PORTAL CANNOT REACH /agent/. A department contact has a portal
 *     session, not an agent session, so /agent/kb_media.php would 302 them to
 *     the agent login. client/kb_article.php therefore repoints the same
 *     canonical URL at the portal's own serve endpoint. That is toPortal(), and
 *     it is the CredentialReferenceRenderer pattern (src/Knowledge/) applied to
 *     a second kind of token: rewrite for display, never for storage.
 *
 *  3. THE LEGACY CORPUS. Before this change the DOCX importer baked raw
 *     /uploads/kb/<article_id>/<name> paths straight into kb_article_content
 *     (agent/post/kb_article.php:177) and TinyMCE's uploader baked flat
 *     /uploads/kb/<name> ones (agent/kb_article_upload.php:35). Those paths are
 *     served by nginx with NO authentication at all - that is the defect this
 *     whole change exists to close - and the moment nginx starts denying
 *     /uploads/kb they become dead images. Measured on the live database on
 *     2026-09-08: 3 kb_articles rows and 2 kb_article_versions rows contain
 *     them, all article-scoped, all from the DOCX importer. A storage migration
 *     is the real fix; recognising the old shape here as well means a row that
 *     has not been migrated yet still renders, on every surface, instead of
 *     showing a broken image. This is belt to the migration's braces, and it is
 *     cheap: one extra branch in describe().
 *
 * WHAT THIS CLASS DELIBERATELY DOES NOT DO. It never decides who may see an
 * image. Every URL it emits points at a serve endpoint that re-runs the full
 * permission chain from the database - module_kb, the article's department
 * scope, the row still existing. A forged or hand-edited ?a= in stored HTML
 * therefore buys nothing: it names an article, and the endpoint checks whether
 * the caller may read THAT article.
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
     * (setup/index.php:57 writes $_SERVER['HTTP_HOST'] into it; functions.php:3938
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
        return self::rewriteSrcAttributes($html, function (array $media): string {
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

            return $signed ?? self::canonicalUrl(self::CANONICAL_PATH, $media);
        });
    }

    /**
     * Agent web pages: legacy -> canonical, and canonical -> itself.
     *
     * A no-op for content that has already been migrated, which is why it is
     * safe to call unconditionally on every render. Emits a root-relative URL
     * with LITERAL '&' separators; the caller is putting it back into an HTML
     * attribute, so rewriteSrcAttributes() escapes it on the way in.
     *
     * Note this runs on the VIEW path only. agent/modals/kb_article/kb_article_edit.php
     * loads the RAW stored HTML into TinyMCE, so editing an unmigrated article
     * still round-trips its legacy URL - normalising in the editor would quietly
     * rewrite stored content on every save, which is a storage change disguised
     * as a render change and belongs in the migration instead.
     */
    public static function toAgentCanonical(string $html): string
    {
        return self::rewriteSrcAttributes(
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
     * The portal endpoint takes the SAME query shape and re-derives access with
     * the portal's own article SQL (client_visible = 1, client_id IN (0,
     * $session_client_id), not archived). It never accepts a signature - a
     * portal request always has a cookie, so there is no portal principal type
     * at all, which is one fewer thing to get wrong.
     */
    public static function toPortal(string $html): string
    {
        return self::rewriteSrcAttributes(
            $html,
            static fn (array $media): string => self::canonicalUrl(self::PORTAL_PATH, $media)
        );
    }

    /**
     * Is there anything here worth rewriting? Mirrors
     * CredentialReferenceRenderer::containsReference() - a cheap predicate for
     * callers that want to skip work or log, never a security check.
     */
    public static function containsMedia(string $html): bool
    {
        return stripos($html, self::CANONICAL_PATH) !== false
            || stripos($html, '/uploads/kb/') !== false;
    }

    /**
     * The engine. Walks every src="..." and hands the parsed media descriptor to
     * $map, which returns the replacement URL.
     *
     * WHY ONLY DOUBLE QUOTES. Every caller runs this on HTMLPurifier OUTPUT, and
     * HTMLPurifier's generator always emits attributes as name="value" with the
     * value HTML-escaped - verified by running the bundled 4.15.0 against
     * single-quoted and unquoted input, which came back double-quoted. So
     * [^"]* cannot run past the closing quote, and a '"' inside a value is
     * impossible because it would have been escaped to &quot;.
     *
     * The pattern is linear - one unnested [^"]* - so catastrophic backtracking
     * cannot occur and PREG_BACKTRACK_LIMIT_ERROR is unreachable at these sizes
     * (largest article measured on the live database: 15,973 bytes). The ?? $html
     * is there only so a future pattern change cannot turn a PCRE failure into a
     * silent empty article.
     */
    private static function rewriteSrcAttributes(string $html, callable $map): string
    {
        if ($html === '' || !self::containsMedia($html)) {
            return $html;
        }

        $out = preg_replace_callback(
            '/\bsrc\s*=\s*"([^"]*)"/i',
            static function (array $m) use ($map): string {
                // The attribute value is HTML-escaped, so '&amp;' has to become
                // '&' before parse_url()/parse_str() see it. Decoding here and
                // re-escaping on the way out is what makes the '&' vs '&amp;'
                // ambiguity structurally impossible to get wrong, rather than a
                // thing every regex has to remember.
                $decoded = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');

                $media = self::describe($decoded);
                if ($media === null) {
                    return $m[0];
                }

                return 'src="' . htmlspecialchars($map($media), ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"';
            },
            $html
        );

        return $out ?? $html;
    }

    /**
     * Recognise a KB media URL and reduce it to the tuple the token signs:
     * ['kind' => att|img|pool, 'ref' => int, 'file' => string]. null means
     * "not KB media" and the src is left byte-identical.
     *
     * ABSOLUTE URLS ARE ALWAYS REJECTED, and that is what makes every one of
     * these transforms idempotent: toSigned() emits absolute URLs, so running it
     * twice cannot double-sign, and a legitimately external image
     * (https://example.com/logo.png) is never touched. A data: URI is rejected
     * by the same rule.
     *
     * Both the canonical shape and the pre-migration /uploads/kb one are
     * accepted; see the class comment for why the old shape is still here.
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

        if ($path === self::CANONICAL_PATH) {
            $query = [];
            parse_str($parts['query'] ?? '', $query);

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
                    ? self::descriptor(MediaToken::KIND_ATTACHMENT, $query['att'], '')
                    : null;
            }
            if (isset($query['a'])) {
                $file = $query['f'] ?? '';
                return is_string($query['a']) && is_string($file)
                    ? self::descriptor(MediaToken::KIND_IMAGE, $query['a'], $file)
                    : null;
            }
            if (isset($query['f'])) {
                return is_string($query['f'])
                    ? self::descriptor(MediaToken::KIND_POOL, '0', $query['f'])
                    : null;
            }
            return null;
        }

        // Legacy, article-scoped: /uploads/kb/<article_id>/<reference_name>
        if (preg_match('#^/uploads/kb/([0-9]+)/([^/]+)$#', $path, $m)) {
            return self::descriptor(MediaToken::KIND_IMAGE, $m[1], rawurldecode($m[2]));
        }

        // Legacy, TinyMCE pool: /uploads/kb/<reference_name>, flat.
        if (preg_match('#^/uploads/kb/([^/]+)$#', $path, $m)) {
            return self::descriptor(MediaToken::KIND_POOL, '0', rawurldecode($m[1]));
        }

        return null;
    }

    /**
     * Build and validate one descriptor.
     *
     * Validation lives here rather than at the call sites so there is exactly
     * one answer to "is this a well-formed media reference". A reference that
     * fails is left ALONE rather than repaired: a src we cannot parse is a src
     * we do not understand, and inventing a URL for it would be guessing at
     * which bytes the author meant.
     *
     * MediaToken::isValidReferenceName() is the permissive form
     * /^[A-Za-z0-9_-]+\.[A-Za-z0-9]+$/, which is what both on-disk name
     * generators actually produce - randomString() (functions.php:12) is
     * base64url, so '-' and '_' occur in a measured 6.15% of uploads.
     */
    private static function descriptor(string $kind, string $ref_raw, string $file): ?array
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

        return ['kind' => $kind, 'ref' => $ref, 'file' => $file];
    }

    /**
     * The signature-free URL for a descriptor, against a given endpoint path.
     * Literal '&' - rewriteSrcAttributes() escapes it for the attribute, and a
     * JSON caller wants it raw.
     */
    private static function canonicalUrl(string $endpoint, array $media): string
    {
        if ($media['kind'] === MediaToken::KIND_ATTACHMENT) {
            return $endpoint . '?att=' . $media['ref'];
        }
        if ($media['kind'] === MediaToken::KIND_IMAGE) {
            return $endpoint . '?a=' . $media['ref'] . '&f=' . rawurlencode($media['file']);
        }
        return $endpoint . '?f=' . rawurlencode($media['file']);
    }
}
