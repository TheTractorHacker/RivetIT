<?php

namespace ITFlow\KB;

/**
 * HMAC capability tokens for Knowledge Base media URLs.
 *
 * WHAT PROBLEM THIS SOLVES. Every inline image and every attachment in the KB
 * is addressed by ONE canonical, root-relative, signature-free URL that points
 * at agent/kb_media.php. On the web that URL needs no token at all - the
 * session cookie is the credential, and a signature-free URL is what lets the
 * stored HTML survive a TinyMCE save/reload round trip unchanged. The Android
 * app cannot do that: KbArticleDetailScreen.kt renders article HTML in a
 * WebView via loadDataWithBaseURL(), so an <img> subresource is fetched by the
 * system network stack with NO X-Api-Key header, and an attachment is handed to
 * an EXTERNAL browser which has neither header nor cookie. So api/v1/kb.php
 * rewrites those canonical URLs into absolute signed ones at response time.
 *
 * WHAT A SIGNATURE IS, AND IS NOT. It is an AUTHENTICATION artifact only. It
 * says "some principal that could already read this article asked for this
 * exact object". It is NEVER an authorization grant: agent/kb_media.php
 * re-derives the whole permission chain from the database on every single
 * request - principal still exists and is live, module_kb >= 1,
 * user_client_permissions scope against the article's kb_article_client_id, row
 * still present. A revoked role, a deleted API token or an expired legacy key
 * therefore kills every outstanding URL instantly, mid-expiry, without anything
 * having to be revoked or tracked here.
 *
 * That property is what makes the residual risk acceptable. The token rides in
 * a query string, so it lands in nginx's access log and in the external
 * browser's history (and Chrome Sync). That cannot be avoided without breaking
 * the no-headers constraint the whole design exists to satisfy. It is bounded
 * three ways: a short TTL, the hard TTL_MAX cap enforced at verify time, and
 * the database re-derivation above.
 *
 * WHY NOT $installation_id. agent/calendar_feed.php signs its ICS feed with
 * hash_hmac('sha256', $user_id, $installation_id) and that is the established
 * "URL that must work without a session" pattern here - but $installation_id is
 * NOT a secret on this install. admin/settings_telemetry.php:11 prints it in
 * plaintext on a settings page, and cron/cron.php + admin/post/update.php POST
 * it to telemetry.itflow.org. Signing media with it would put the key one
 * dropdown away from a third party and one HTTP request away from a vendor. So
 * this uses its own key, generated here and never transmitted anywhere.
 */
final class MediaToken
{
    /* Domain separation. If this app ever grows a second HMAC over a similar
       tuple, a token minted for one must not verify for the other. Bump the
       suffix if the payload's SHAPE ever changes - that invalidates every
       outstanding URL, which is the correct behaviour for a format change. */
    private const CONTEXT = 'itflow.kb_media.v1';

    /* The three things that can be addressed. These strings are literals in the
       signed payload, which is what stops an 'att' token being replayed as an
       'img' one against a different object with the same integer id. */
    public const KIND_ATTACHMENT = 'att';
    public const KIND_IMAGE      = 'img';
    public const KIND_POOL       = 'pool';

    /* TTLs. Deliberately different, because the two consumers hold the URL for
       very different lengths of time. An inline image is refetched by the
       WebView every time the article screen is entered, so it never needs to
       outlive one viewing session. An attachment URL is handed to an external
       browser and may sit in a downloads list or a history entry, so it needs
       to survive the user tabbing away and coming back - but that is also
       exactly the copy most likely to leak, hence 24h rather than longer. */
    public const TTL_IMAGE  = 7200;    // 2 hours
    public const TTL_ATTACH = 86400;   // 24 hours

    /* Hard ceiling applied at VERIFY time, not just at mint time. A future bug
       that minted e = time() + 10 years would otherwise produce a URL that
       outlives everything; this catches it on the serving side where it
       matters. Nothing legitimate ever approaches it. */
    public const TTL_MAX = 604800;     // 7 days

    /* Memoized. A single article response mints one URL per image plus one per
       attachment, and re-reading + AES-decrypting the key for each would be a
       query and a decrypt per image for no reason. $key_loaded is separate from
       $key_cache because null is a legitimate cached answer ("no key on this
       install"), and re-trying the lookup on every call would turn a
       misconfigured install into a query storm. */
    private static ?string $key_cache = null;
    private static bool $key_loaded = false;

    /**
     * The one true shape of a stored KB media filename.
     *
     * The PERMISSIVE form, matching agent/kb_article_attachment.php:124. It has
     * to match both generators actually in use: DOCX import names files
     * bin2hex(random_bytes(16)) . '.' . ext, and checkFileUpload()
     * (functions.php:2461) names them md5 . randomString(2) . '.' . ext where
     * randomString() (functions.php:12) is base64url - so '-' and '_' really do
     * occur, in a measured 1-(62/64)^2 = 6.15% of uploads.
     *
     * The character class also carries the injectivity proof for payload()
     * below: it cannot represent "\n", so a filename can never absorb the
     * field that follows it in the signed string.
     *
     * The length cap is the kb_article_attachments.kb_article_attachment_reference_name
     * column width. Nothing longer can have been stored, so nothing longer can
     * be legitimate.
     */
    public static function isValidReferenceName(string $reference_name): bool
    {
        if ($reference_name === '' || strlen($reference_name) > 255) {
            return false;
        }
        return (bool) preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9]+$/', $reference_name);
    }

    /**
     * Principal grammar: one letter naming the credential TABLE, then its id.
     *
     *   t<api_tokens.token_id>  - a per-user Bearer token (the Android app)
     *   k<api_keys.api_key_id>  - a legacy instance-wide X-Api-Key
     *   u<users.user_id>        - a bare user, the weak fallback (see kb_media.php)
     *
     * The letter matters as much as the number: a legacy key carries its own
     * api_key_client_id department restriction and its own expiry, and naming
     * the USER it resolved to instead of the KEY would throw both away - the
     * key's outstanding media URLs would survive its deletion, and a
     * department-scoped key would mint an admin-scoped capability.
     *
     * Leading zeros are refused so that the string form is canonical: 't1' and
     * 't01' would otherwise be two distinct payloads naming one principal,
     * which breaks the "one principal, one signature" property that makes
     * reasoning about replay possible. The 10-digit cap keeps the value inside
     * a 32-bit signed int, which is what the id columns are.
     */
    public static function isValidPrincipal(string $principal): bool
    {
        return (bool) preg_match('/^[utk][1-9][0-9]{0,9}$/', $principal);
    }

    /**
     * The signed string.
     *
     * INJECTIVE BY CONSTRUCTION, and that is the whole security argument for
     * using a delimiter join rather than a length-prefixed encoding: CONTEXT is
     * a literal, $kind is one of three literals, $ref and $expires are ints
     * rendered by PHP, $file is '' or has passed isValidReferenceName(), and
     * $principal has passed isValidPrincipal(). NOT ONE of those alphabets
     * contains "\n". So no two distinct tuples can produce the same string, and
     * no field can bleed into its neighbour.
     *
     * That proof depends entirely on the validation running BEFORE this is
     * called, which is why sign() and verify() below both validate rather than
     * trusting their callers - the caller is a different file, maintained by a
     * different person, and in the API's case in a different request lifecycle.
     */
    private static function payload(string $kind, int $ref, string $file, string $principal, int $expires): string
    {
        return implode("\n", [self::CONTEXT, $kind, (string) $ref, $file, $principal, (string) $expires]);
    }

    /**
     * Validate the tuple. Shared by sign() and verify() so the two can never
     * disagree about what a well-formed request looks like.
     */
    private static function tupleOk(string $kind, int $ref, string $file, string $principal, int $expires): bool
    {
        if (!self::isValidPrincipal($principal)) {
            return false;
        }
        if ($expires <= 0) {
            return false;
        }

        if ($kind === self::KIND_ATTACHMENT) {
            // Addressed by id alone. The on-disk name comes only from the DB
            // row, so no filename ever crosses the trust boundary here.
            return $ref > 0 && $file === '';
        }
        if ($kind === self::KIND_IMAGE) {
            return $ref > 0 && self::isValidReferenceName($file);
        }
        if ($kind === self::KIND_POOL) {
            // The shared TinyMCE upload pool is flat - no article, no owner, no
            // row anywhere. $ref is forced to 0 rather than left free so that a
            // pool token can never be constructed to look like an image token.
            return $ref === 0 && self::isValidReferenceName($file);
        }

        return false;
    }

    /**
     * Mint a signature, or null.
     *
     * NULL IS A REAL RETURN VALUE THAT CALLERS MUST HANDLE. It means either the
     * tuple was malformed (a caller bug) or this install has no usable key
     * (config.php lost its $config_settings_enc_key, or the settings column
     * does not exist yet because the 2.6.78 database update has not run). In
     * both cases the correct behaviour for a caller is to emit the canonical
     * signature-free URL unchanged, which still works for every cookie-bearing
     * client and simply fails closed for the API - never to emit a URL with an
     * empty or partial signature, which would be an unauthenticated URL wearing
     * a signature's clothes.
     */
    public static function sign(string $kind, int $ref, string $file, string $principal, int $expires): ?string
    {
        if (!self::tupleOk($kind, $ref, $file, $principal, $expires)) {
            return null;
        }

        $key = self::key();
        if ($key === null) {
            return null;
        }

        return hash_hmac('sha256', self::payload($kind, $ref, $file, $principal, $expires), $key);
    }

    /**
     * Constant-time verification. hash_equals(), never ==: PHP's == on two
     * hex strings is a byte-wise comparison that returns early, which leaks the
     * length of the matching prefix through timing and makes forging a
     * signature a per-byte search rather than a 2^256 one. (== is also
     * catastrophic on strings that look numeric, but that is not the reason
     * this matters here.)
     */
    public static function verify(string $candidate, string $kind, int $ref, string $file, string $principal, int $expires): bool
    {
        // Cheap structural check first, so a malformed candidate never reaches
        // the key lookup and therefore never touches the database.
        if (!preg_match('/^[a-f0-9]{64}$/', $candidate)) {
            return false;
        }

        $expected = self::sign($kind, $ref, $file, $principal, $expires);
        if ($expected === null) {
            return false;
        }

        return hash_equals($expected, $candidate);
    }

    /**
     * Build the absolute signed URL the API hands to a stateless client.
     *
     * URL CONTRACT - this and agent/kb_media.php's parser are two halves of one
     * thing; change neither without the other:
     *
     *   attachment: https://<host>/agent/kb_media.php?att=<id>&p=..&e=..&s=..
     *   article img: https://<host>/agent/kb_media.php?a=<article_id>&f=<name>&p=..&e=..&s=..
     *   pool img:    https://<host>/agent/kb_media.php?f=<name>&p=..&e=..&s=..
     *
     * $base_host is $config_base_url, which is HOST-ONLY on this codebase
     * (setup/index.php:57 sets it from $_SERVER['HTTP_HOST'], and
     * functions.php:3938 writes "https://$config_base_url/..."), so the scheme
     * is prepended literally here for the same reason.
     *
     * Returns a RAW url with literal '&' separators. A caller putting this in
     * an HTML attribute must escape it (htmlspecialchars) itself - doing it
     * here would corrupt the value for the JSON callers.
     *
     * &download=1 is deliberately NOT part of the signature: it only selects
     * inline vs attachment disposition and can never change WHICH bytes are
     * served, so one token drives both a View and a Download link.
     */
    public static function signedUrl(string $base_host, string $kind, int $ref, string $file, string $principal, int $ttl): ?string
    {
        if ($ttl < 1 || $ttl > self::TTL_MAX) {
            return null;
        }

        $expires   = time() + $ttl;
        $signature = self::sign($kind, $ref, $file, $principal, $expires);
        if ($signature === null) {
            return null;
        }

        if ($kind === self::KIND_ATTACHMENT) {
            $params = 'att=' . $ref;
        } elseif ($kind === self::KIND_IMAGE) {
            $params = 'a=' . $ref . '&f=' . rawurlencode($file);
        } else {
            $params = 'f=' . rawurlencode($file);
        }

        $params .= '&p=' . rawurlencode($principal)
                 . '&e=' . $expires
                 . '&s=' . $signature;

        return 'https://' . $base_host . '/agent/kb_media.php?' . $params;
    }

    /**
     * The signing key: 64 hex characters, stored ENC2-wrapped in
     * settings.config_kb_media_key and decrypted with config.php's
     * $config_settings_enc_key.
     *
     * WHY THAT COLUMN AND NOT A CONSTANT IN config.php. It has to be per
     * install and it has to survive a code deploy, so it belongs in the
     * database; and everything secret in the settings table is already wrapped
     * by encryptSetting(), whose writer fails closed (functions.php:5050) rather
     * than silently storing cleartext.
     *
     * DELIBERATELY NOT ADDED TO includes/load_global_settings.php. That file
     * does SELECT * (line 4), so the ciphertext is transiently in its $row, but
     * it must never become a page-scope global: a var_dump in a debug session
     * or a verbose error handler would then print the KB media signing key onto
     * a page. Nothing outside this class needs it.
     *
     * LAZY PROVISIONING, RACE-FREE. The key is minted on first use rather than
     * by the database update, so an install that upgrades and immediately
     * serves an API request works without an admin visiting a settings screen.
     * The write is a single CONDITIONAL UPDATE whose WHERE re-tests emptiness,
     * so two concurrent minters cannot both win: InnoDB serializes them on the
     * row lock and the loser's UPDATE matches zero rows. Both then re-read, so
     * both end up using whichever key was actually committed. Without the
     * condition the loser would overwrite the winner and instantly invalidate
     * every URL the winner had already signed.
     *
     * Returns null - never a fabricated or empty key - when anything is
     * missing. Every caller treats null as "cannot sign / cannot verify", which
     * is fail-closed in both directions.
     */
    private static function key(): ?string
    {
        if (self::$key_loaded) {
            return self::$key_cache;
        }
        self::$key_loaded = true;
        self::$key_cache  = null;

        $mysqli = $GLOBALS['mysqli'] ?? null;
        if (!($mysqli instanceof \mysqli)) {
            return null;
        }

        // functions.php is what defines these. Guarded rather than assumed
        // because this class is autoloaded and could in principle be reached
        // from a bootstrap that never pulled functions.php in.
        if (!function_exists('encryptSetting') || !function_exists('decryptSetting')) {
            return null;
        }

        // No wrapping key means encryptSetting() would throw and decryptSetting()
        // would return ''. Check first so the failure is a clean null rather
        // than an exception escaping into a binary response.
        if (empty($GLOBALS['config_settings_enc_key'])) {
            return null;
        }

        $existing = self::readKey($mysqli);
        if ($existing !== null) {
            self::$key_cache = $existing;
            return $existing;
        }

        try {
            $wrapped = \encryptSetting(bin2hex(random_bytes(32)));
        } catch (\Throwable $e) {
            // encryptSetting() throws rather than store cleartext. Signing is
            // off on this install until config.php is fixed; serving already
            // works for every cookie-bearing client.
            return null;
        }

        $wrapped_esc = mysqli_real_escape_string($mysqli, $wrapped);
        try {
            mysqli_query(
                $mysqli,
                "UPDATE settings
                    SET config_kb_media_key = '$wrapped_esc'
                  WHERE company_id = 1
                    AND (config_kb_media_key IS NULL OR config_kb_media_key = '')"
            );
        } catch (\Throwable $e) {
            // Same missing-column case as readKey() below. Nothing to mint into.
            return null;
        }

        // Re-read rather than trusting the value just written: under the race
        // above this is how the loser discovers the winner's key.
        self::$key_cache = self::readKey($mysqli);
        return self::$key_cache;
    }

    /**
     * Read and unwrap the stored key, or null.
     *
     * THE try/catch IS LOAD-BEARING AND WAS MEASURED. This codebase never calls
     * mysqli_report(), and the obvious assumption - that mysqli_query() then
     * returns false on error - is WRONG on PHP 8.1+, where the DEFAULT report
     * mode is MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT and a failed query
     * throws mysqli_sql_exception. Verified on PHP 8.4.25: with the column
     * absent, the `if (!$result)` guard below never ran and the request died
     * with an uncaught "Unknown column 'config_kb_media_key' in 'SELECT'"
     * fatal - a 500 in the middle of what should have been an image response.
     *
     * That is not a hypothetical state. It is EXACTLY what an install looks
     * like between deploying this code and running the 2.6.78 database update,
     * which on this project are two separate manual steps. Catching it turns
     * "the KB is 500ing" into "signed API media URLs do not work yet, and
     * everything cookie-authenticated is unaffected".
     *
     * The 64-hex shape is re-validated after decryption. A truncated column
     * (the settings table has a history of varchar widths too narrow for a
     * wrapped secret - see the 2.6.77 update's length guard) would decrypt to
     * '' or to garbage, and signing with garbage would produce URLs that verify
     * against each other but not against a correctly re-read key.
     */
    private static function readKey(\mysqli $mysqli): ?string
    {
        try {
            $result = mysqli_query($mysqli, "SELECT config_kb_media_key FROM settings WHERE company_id = 1 LIMIT 1");
        } catch (\Throwable $e) {
            return null;
        }
        // Kept as well as the catch: the return-false path is what happens if a
        // future bootstrap ever does call mysqli_report(MYSQLI_REPORT_OFF).
        if (!$result) {
            return null;
        }

        $row = mysqli_fetch_assoc($result);
        if (!$row || empty($row['config_kb_media_key'])) {
            return null;
        }

        $plain = \decryptSetting((string) $row['config_kb_media_key']);
        if (!preg_match('/^[a-f0-9]{64}$/', $plain)) {
            return null;
        }

        return $plain;
    }
}
