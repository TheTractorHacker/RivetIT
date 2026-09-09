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
       misconfigured install into a query storm.

       $mint_attempted is the same guard for the WRITE side, and it is needed
       for exactly the same reason. keyForSigning() runs after key() has already
       cached its null, so without this flag an install whose settings column is
       missing (the pre-2.6.78 state) would attempt an encryptSetting(), an
       UPDATE and a re-read PER SIGNED URL - about 2 x (images + attachments)
       throwing queries for one article response, instead of one failed attempt
       for the whole request. One attempt per request either way. */
    private static ?string $key_cache = null;
    private static bool $key_loaded = false;
    private static bool $mint_attempted = false;

    /**
     * The one true shape of a stored KB media filename.
     *
     * The PERMISSIVE form. It has to match every generator actually in use:
     * ALL THREE KB importers in agent/post/kb_article.php - the DOCX one,
     * the PDF one, and the newer HTML one (line 754) - name their
     * extracted images
     * bin2hex(random_bytes(16)) . '.' . ext, and
     * checkFileUpload() (functions.php:2461) names uploads
     * md5 . randomString(2) . '.' . ext where randomString()
     * (functions.php:12) is base64url - so '-' and '_' really do occur, in a
     * measured 1-(62/64)^2 = 6.15% of uploads.
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
        /* DELEGATES. functions.php::isUploadReferenceName() owns this pattern,
           because the WRITER owns it: checkFileUpload() sits beside it and is
           what generates every name this will ever be asked about, and five
           non-KB call sites (ticket attachments, the ticket API) validate with
           it too. This branch and main independently discovered the same
           6.15%-of-uploads bug in the old /^[a-zA-Z0-9]+\.[a-zA-Z0-9]+$/ and
           independently fixed it; keeping both fixes would have left two
           patterns to drift apart again, which is the whole failure mode.

           The name stays because the media layer reads better for it - a caller
           in kb_media.php is asking "is this a reference name I will serve",
           not "is this something checkFileUpload could have written". Same
           question, and now provably the same answer. */
        return isUploadReferenceName($reference_name);
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
     * reasoning about replay possible.
     *
     * The 10-digit cap is a LENGTH bound, not a range check - do not read it as
     * one. Ten digits reaches 9,999,999,999, which is about 4.7x the 2,147,483,647
     * that api_tokens.token_id / api_keys.api_key_id / users.user_id actually
     * hold (all int(11) signed, db.sql:88/123/3808). Its job is only to stop a
     * multi-kilobyte digit string being carried into a signed payload and a
     * query; the range check belongs to the database, which performs it by
     * finding no row.
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

        // keyForSigning(), not key(): this is THE ONLY path allowed to create
        // the secret, because minting writes to the settings table and this
        // path is only ever reached from an already-authenticated caller. See
        // the two functions at the bottom of this file.
        $key = self::keyForSigning();
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
        if (!self::tupleOk($kind, $ref, $file, $principal, $expires)) {
            return false;
        }

        /* DELIBERATELY NOT sign(). This used to be `$expected = self::sign(...)`,
           and that one call made the whole endpoint reachable as a settings
           WRITE by an anonymous stranger: agent/kb_media.php's signed branch
           calls verify() before any principal is resolved, verify() called
           sign(), sign() called key(), and key() minted the secret and UPDATEd
           settings.config_kb_media_key. Reproduced with a single cookieless
           curl carrying 64 zeros as the signature - the request 403'd, and the
           key had been created by the time it did.

           Verification is a READ. It uses the read-only key() and fails closed
           when there is no key, so no unauthenticated request can cause a write
           anywhere in this class. tupleOk() is re-run here rather than
           inherited from sign(), so dropping that call cost no validation. */
        $key = self::key();
        if ($key === null) {
            return false;
        }

        $expected = hash_hmac('sha256', self::payload($kind, $ref, $file, $principal, $expires), $key);

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
     * functions.php:3968 writes "https://$config_base_url/..."), so the scheme
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
     * The signing key, READ ONLY: 64 hex characters, stored ENC2-wrapped in
     * settings.config_kb_media_key and decrypted with config.php's
     * $config_settings_enc_key.
     *
     * THIS FUNCTION NEVER WRITES ANYTHING, AND THAT IS A SECURITY PROPERTY, NOT
     * A STYLE CHOICE. It is on the verification path, and the verification path
     * is reachable by a completely anonymous HTTP request: agent/kb_media.php's
     * signed branch calls verify() before it has resolved any principal. When
     * the mint lived in here, one cookieless curl with a junk signature caused
     * an UPDATE to the settings table. Minting now lives in keyForSigning()
     * below, which only sign() calls. Read the note there before moving either.
     *
     * WHY THAT COLUMN AND NOT A CONSTANT IN config.php. It has to be per
     * install and it has to survive a code deploy, so it belongs in the
     * database; and everything secret in the settings table is already wrapped
     * by encryptSetting(), whose writer fails closed (it throws at
     * functions.php:5081) rather than silently storing cleartext.
     *
     * DELIBERATELY NOT ADDED TO includes/load_global_settings.php. That file
     * does SELECT * (line 4), so the ciphertext is transiently in its $row, but
     * it must never become a page-scope global: a var_dump in a debug session
     * or a verbose error handler would then print the KB media signing key onto
     * a page. Nothing outside this class needs it.
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

        // functions.php is what defines this. Guarded rather than assumed
        // because this class is autoloaded and could in principle be reached
        // from a bootstrap that never pulled functions.php in.
        if (!function_exists('decryptSetting')) {
            return null;
        }

        // No wrapping key means decryptSetting() would return ''. Check first so
        // the failure is a clean null rather than a garbage key that signs URLs
        // nothing can verify.
        if (empty($GLOBALS['config_settings_enc_key'])) {
            return null;
        }

        self::$key_cache = self::readKey($mysqli);
        return self::$key_cache;
    }

    /**
     * The key, minting it if this install has none. THE ONLY WRITER.
     *
     * WHO MAY REACH THIS, traced rather than assumed - re-trace it if you add a
     * caller. keyForSigning() <- sign() <- signedUrl() <- exactly two places,
     * both in api/v1/kb.php: the attachment loop calls signedUrl() directly, and
     * the content rewrite calls MediaUrlRewriter::toSigned(), which is the only
     * other caller of signedUrl() in the tree. api/v1/index.php has already
     * authenticated the request (a Bearer token, or a legacy X-Api-Key that is
     * still within api_key_expire) before kb.php is included at all. So the
     * settings write can only ever be caused by a caller that has already proved
     * who it is. verify() deliberately does not come through here; see key()
     * above for what happened when it did. Cited by symbol, not by line: those
     * two call sites move, and a stale line number reads as a lie.
     *
     * LAZY PROVISIONING, RACE-FREE. The key is minted on first signing use
     * rather than by an admin action, so an install that has run the 2.6.78
     * database update serves its first API article correctly with nobody
     * visiting a settings screen. The write is a single CONDITIONAL UPDATE
     * whose WHERE re-tests emptiness, so two concurrent minters cannot both
     * win: InnoDB serializes them on the row lock and the loser's UPDATE
     * matches zero rows. Both then re-read, so both end up using whichever key
     * was actually committed. Without the condition the loser would overwrite
     * the winner and instantly invalidate every URL the winner had already
     * signed.
     *
     * LAZY IS NOT THE SAME AS SAFE TO DEPLOY IN ANY ORDER. Until
     * config_kb_media_key exists as a COLUMN, readKey() catches the
     * mysqli_sql_exception, the UPDATE below catches it too, and signing is
     * simply off - which for the Android client means every KB inline image and
     * every KB attachment link is broken, not degraded. The database update
     * must therefore land BEFORE or WITH the code, never after. That ordering
     * is a deploy-runbook obligation, not something this class can enforce.
     */
    private static function keyForSigning(): ?string
    {
        $existing = self::key();
        if ($existing !== null) {
            return $existing;
        }

        // One mint attempt per request, no matter how many URLs get signed.
        // See $mint_attempted at the top of the class for the measurement.
        if (self::$mint_attempted) {
            return null;
        }
        self::$mint_attempted = true;

        $mysqli = $GLOBALS['mysqli'] ?? null;
        if (!($mysqli instanceof \mysqli)) {
            return null;
        }
        if (!function_exists('encryptSetting') || !function_exists('decryptSetting')) {
            return null;
        }
        if (empty($GLOBALS['config_settings_enc_key'])) {
            return null;
        }

        /* ONE try AROUND THE WHOLE MINT, deliberately - all three statements can
           throw and every one of them means the same thing ("this install
           cannot sign right now"), so they share a fail-closed exit:
             - encryptSetting() throws rather than store a secret in cleartext;
             - mysqli_real_escape_string() throws a plain Error ("mysqli object
               is already closed") on a handle that was never connected or has
               been closed. This one used to sit BETWEEN the two try blocks and
               was therefore uncaught: measured on PHP 8.4 by driving this class
               with an unconnected mysqli, an anonymous verify() came out as an
               uncaught Error, i.e. an HTTP 500 in the middle of an image
               response. It is inside the try now;
             - the UPDATE throws mysqli_sql_exception when config_kb_media_key
               does not exist yet (PHP 8.1+ defaults to
               MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT and this codebase never
               calls mysqli_report - see readKey() below), which is exactly the
               pre-2.6.78 state.
           An escaping throwable here would land in the middle of what the
           caller intends to be a JSON API response. */
        try {
            $wrapped     = \encryptSetting(bin2hex(random_bytes(32)));
            $wrapped_esc = mysqli_real_escape_string($mysqli, $wrapped);
            mysqli_query(
                $mysqli,
                "UPDATE settings
                    SET config_kb_media_key = '$wrapped_esc'
                  WHERE company_id = 1
                    AND (config_kb_media_key IS NULL OR config_kb_media_key = '')"
            );
        } catch (\Throwable $e) {
            return null;
        }

        // Re-read rather than trusting the value just written: under the race
        // above this is how the loser discovers the winner's key. Assigning the
        // memo directly is correct because key() has already set $key_loaded.
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
