<?php
// GET /api/v1/kb/categories          - list KB categories
// GET /api/v1/kb/articles            - list KB articles (category_id, client_id, search, page, limit)
// GET /api/v1/kb/articles/{id}       - KB article detail (content + attachments)
defined('FROM_API') || die();
require_once __DIR__ . '/includes/api_permissions.php';

if ($method !== 'GET') api_error(405, 'Method not allowed');

$uid = $api_user_id;
api_require_module_permission($mysqli, $uid, 'module_kb');

/* Client-scope restriction: kb_article_client_id = 0 means a company-wide article
 * (visible to everyone, matching the existing client_id filter's "IN (0, ...)" pattern
 * below), otherwise it must be in the caller's permitted client set.
 *
 * THIS CLAUSE IS HALF OF A PAIR. The other half is kbMediaClientAccessOk() in
 * agent/includes/kb_media_auth.php, which re-checks the same question when the
 * media URLs minted below are fetched. It has to agree with THIS clause and not
 * with api_client_scope_ok(), which applies a legacy key's api_key_client_id
 * restriction to every client_id INCLUDING 0 - this endpoint deliberately does
 * not, so a department-scoped X-Api-Key can read a company-wide article. Being
 * stricter at fetch time than at read time is not "safer": it was reproduced as
 * a 200 on the article and a 403 on 100% of its images. Change the two together
 * or not at all; that function's own comment names $kb_client_scope_clause for
 * the same reason. */
$kb_client_scope_clause = "(kb_articles.kb_article_client_id = 0 OR " . api_client_scope_sql('kb_articles.kb_article_client_id') . ")";

if ($sub === 'categories') {
    $categories = [];
    $sql = mysqli_query($mysqli,
        "SELECT kb_category_id, kb_category_name, kb_category_parent_id, kb_category_client_id
         FROM kb_categories
         WHERE kb_category_archived_at IS NULL
         ORDER BY kb_category_order ASC, kb_category_name ASC"
    );
    while ($row = mysqli_fetch_assoc($sql)) {
        $categories[] = [
            'id'        => intval($row['kb_category_id']),
            'name'      => $row['kb_category_name'],
            'parent_id' => intval($row['kb_category_parent_id']),
            'client_id' => intval($row['kb_category_client_id']),
        ];
    }
    api_response(200, $categories);
}

if ($sub === 'articles') {
    if ($id !== null) {
        $row = mysqli_fetch_assoc(mysqli_query($mysqli,
            "SELECT kb_articles.*, clients.client_name, kb_categories.kb_category_name
             FROM kb_articles
             LEFT JOIN clients ON clients.client_id = kb_articles.kb_article_client_id
             LEFT JOIN kb_categories ON kb_categories.kb_category_id = kb_articles.kb_article_category_id
             WHERE kb_articles.kb_article_id = $id AND kb_article_archived_at IS NULL AND $kb_client_scope_clause LIMIT 1"
        ));
        if (!$row) api_error(404, 'Article not found');

        /* ------------------------------------------------------------------
         * MEDIA URLS FOR A CLIENT THAT CANNOT SEND A CREDENTIAL
         * ------------------------------------------------------------------
         * This endpoint used to emit "/uploads/kb/<article_id>/<file>", and
         * /uploads is served straight off nginx with no authentication at all -
         * so every attachment URL this API had ever returned was, and stayed,
         * world-readable to anyone holding the path. That is the defect being
         * closed. Every media URL below points at agent/kb_media.php whenever
         * this install can sign one; that endpoint re-runs the whole permission
         * chain from the database on each request. The one exception is the
         * pre-2.6.78 deploy gap, which has its own note on $kb_signing_on
         * below - it is the only path on which a /uploads URL still leaves
         * here, and it emits exactly the URLs this endpoint emits today.
         *
         * The consumer is what forces the signature. KbArticleDetailScreen.kt
         * renders `content` in a WebView via loadDataWithBaseURL(), so an <img>
         * subresource is fetched by the system network stack with NO X-Api-Key
         * header; and an attachment is handed to an EXTERNAL browser (the
         * ACTION_VIEW intent that KbMediaUrl.forAttachment() feeds), which has
         * neither header nor cookie. A capability in the URL is the only
         * credential either of them can carry. See src/KB/MediaToken.php for
         * why that is authentication and never authorization.
         *
         * PRINCIPAL. Name the CREDENTIAL, not the user it resolved to. A legacy
         * X-Api-Key carries its own expiry and its own api_key_client_id
         * department restriction, and index.php:189-200 resolves it to "the
         * first admin" - so signing 'u<admin_id>' would both outlive the key's
         * deletion and hand a department-scoped key an admin-scoped capability.
         * $api_token_row and $legacy_key_row are in scope because index.php
         * require's this file at file scope. 'u' is the last-resort principal
         * for the impossible case of an authenticated caller with neither row.
         */
        $kb_token_row     = $api_token_row  ?? null;
        $kb_legacy_row    = $legacy_key_row ?? null;
        if (!empty($kb_token_row['token_id'])) {
            $kb_media_principal = 't' . intval($kb_token_row['token_id']);
        } elseif (!empty($kb_legacy_row['api_key_id'])) {
            $kb_media_principal = 'k' . intval($kb_legacy_row['api_key_id']);
        } else {
            $kb_media_principal = 'u' . intval($uid);
        }

        /* $config_base_url is HOST-ONLY on this codebase (setup/index.php:57
         * stores $_SERVER['HTTP_HOST']); functions.php builds
         * "https://$config_base_url/..." literally, prepending the scheme the
         * same way this does. (Cited by content, not by line: the line number
         * that stood here pointed at ticket-attachment code.) Normalised the
         * way includes/webauthn.php does, in case an install has hand-edited a
         * scheme or a trailing slash into it.
         *
         * If it is empty there is nothing safe to sign against, and $_SERVER's
         * Host header is deliberately NOT used as a fallback: it is caller
         * controlled, and echoing a capability token back on a caller-chosen
         * host is a token-exfiltration primitive. */
        $kb_media_host = rtrim(preg_replace('#^https?://#i', '', trim((string) ($config_base_url ?? ''))), '/');

        /* ------------------------------------------------------------------
         * WHAT THIS ENDPOINT DOES WHEN IT CANNOT SIGN - i.e. THE DEPLOY GAP
         * ------------------------------------------------------------------
         * Signing needs a key in settings.config_kb_media_key, and that COLUMN
         * only exists from database version 2.6.78. The code and the database
         * update are two separate manual steps on this project (live was
         * measured at 2.6.77 on 2026-09-08, with no such column), so there is a
         * real window where this file is deployed and the key cannot exist.
         *
         * THE OLD BEHAVIOUR IN THAT WINDOW WAS A REGRESSION, NOT A DEGRADATION.
         * Every URL fell back to the canonical /agent/kb_media.php form, which
         * authenticates with a SESSION COOKIE. The Android client has none: it
         * renders content through WebView.loadDataWithBaseURL() and opens an
         * attachment in an EXTERNAL browser. So every KB image and every
         * attachment link would have broken the moment the code landed and
         * stayed broken until somebody remembered to run the database update -
         * and they all work today.
         *
         * SO THE RULE IS: WHEN WE CANNOT SIGN, EMIT THE URL EXACTLY AS STORED.
         *   - Pre-2.6.78 the stored URL is the legacy /uploads/kb/... path, and
         *     that is precisely what this endpoint emits TODAY (the deployed
         *     copy builds "/uploads/kb/$id/<reference_name>" for attachments
         *     and returns kb_article_content raw). Verified against a copy of
         *     the live database: for articles 13, 14 and 15 the set of image
         *     URLs this file emits in the gap is IDENTICAL to the set the
         *     deployed file emits - 4, 6 and 5 URLs, no difference - and the
         *     attachment URL matches character for character. So no media URL
         *     regresses, and no new exposure is created either: those bytes are
         *     already served unauthenticated at that exact path, which is the
         *     defect the rest of this change closes. (The body is additionally
         *     purified now, which the deployed copy does not do; that is a
         *     separate improvement and it changes no media URL.)
         *   - From 2.6.78 the same database update that creates the key column
         *     also rewrites storage to the canonical form
         *     (admin/database_updates.php, the 2.6.77 -> 2.6.78 block), so
         *     "as stored" means canonical and the response fails CLOSED. That
         *     branch is only reachable on a broken install: the 2.6.77 update
         *     refuses to run without $config_settings_enc_key, so anything that
         *     REACHED 2.6.78 could mint a key at the time, and only losing that
         *     key afterwards gets you here. It must not fall back to /uploads,
         *     because by then the web server is denying that prefix. Verified
         *     by blanking $config_settings_enc_key on a migrated copy of the
         *     live database: content and attachment both came back as
         *     /agent/kb_media.php?..., zero /uploads paths, and nothing was
         *     written to the settings table.
         *
         * The ordering that makes this safe is enforced, not documented: the
         * 2.6.78 block refuses to run until agent/kb_media.php is on disk. So
         * the only possible sequence is code, then database, then the nginx
         * deny - and every step is individually non-breaking.
         *
         * $kb_signing_on is PROBED rather than asked, because MediaToken keeps
         * its key private on purpose and signedUrl() already answers exactly
         * this question: it returns null on every state we care about (no host,
         * no $config_settings_enc_key, no column, no row). One probe decides it
         * once, so the attachment loop and the content rewrite below cannot
         * disagree per-URL. The probe URL is discarded; the work it does - at
         * most one key read plus one lazy mint - is work the first real
         * signature would have done anyway. */
        $kb_signing_on = $kb_media_host !== '' && \ITFlow\KB\MediaToken::signedUrl(
            $kb_media_host,
            \ITFlow\KB\MediaToken::KIND_IMAGE,
            1,
            'probe.png',
            $kb_media_principal,
            \ITFlow\KB\MediaToken::TTL_IMAGE
        ) !== null;

        $kb_storage_migrated = defined('CURRENT_DATABASE_VERSION')
            && version_compare((string) CURRENT_DATABASE_VERSION, '2.6.78', '>=');

        /* Resolved ONCE, outside the loop. realpath() of the KB upload root is
         * what the legacy fallback below is contained against, and it is also
         * the cheap way to say "this install has no KB uploads at all" (false).
         * dirname(__DIR__, 2) rather than $_SERVER['DOCUMENT_ROOT']: this file
         * is api/v1/kb.php, so that IS the application root, and it is right
         * even if a future entry point forgets to set DOCUMENT_ROOT - which is
         * a real shape here: api/v1/index.php assigns $_SERVER['DOCUMENT_ROOT']
         * itself rather than trusting the SAPI to have set one. */
        $kb_uploads_root = realpath(dirname(__DIR__, 2) . '/uploads/kb');

        $attachments = [];
        $asql = mysqli_query($mysqli,
            "SELECT kb_article_attachment_id, kb_article_attachment_name, kb_article_attachment_reference_name
             FROM kb_article_attachments
             WHERE kb_article_attachment_kb_article_id = $id
             ORDER BY kb_article_attachment_created_at ASC"
        );
        while ($att = mysqli_fetch_assoc($asql)) {
            $att_id = intval($att['kb_article_attachment_id']);

            /* The ATTACHMENT ID is what is signed and what kb_media.php looks
             * up - never the filename. The on-disk name comes only from the
             * database row, so no filename crosses the trust boundary on this
             * path at all.
             *
             * TTL_ATTACH (24h) rather than TTL_IMAGE: this URL is handed to a
             * separate browser app and may sit in a downloads list or a history
             * entry while the user tabs away, whereas an inline image is
             * refetched every time the article screen is entered.
             *
             * No &download=1. nginx used to stamp Content-Disposition:
             * attachment on everything under /uploads, so every KB attachment
             * downloaded blindly; kb_media.php's positive inline allow-list now
             * previews PDFs and images in the browser and forces the download
             * for everything else - the same behaviour the agent page's "View"
             * link has always had. */
            $att_url = $kb_signing_on ? \ITFlow\KB\MediaToken::signedUrl(
                $kb_media_host,
                \ITFlow\KB\MediaToken::KIND_ATTACHMENT,
                $att_id,
                '',
                $kb_media_principal,
                \ITFlow\KB\MediaToken::TTL_ATTACH
            ) : null;

            /* THE DEPLOY-GAP FALLBACK. See the long note on $kb_signing_on: on
             * a pre-2.6.78 install we cannot sign, and the canonical URL needs
             * a cookie the app does not have, so emit the legacy path - which
             * is exactly what the deployed copy of this endpoint emits today.
             *
             * Three conditions, and all three are needed:
             *  - !$kb_storage_migrated, so a raw /uploads URL can NEVER leave
             *    this endpoint on an install where the web server has been told
             *    to deny that prefix. The database update and the deny both
             *    come after the code, so "not migrated" implies "not denied".
             *  - isUploadReferenceName(): functions.php owns the ONE definition
             *    of a valid reference name, and it forbids '/', which is what
             *    makes the path below structurally containable. A row that
             *    fails it never had a servable file anyway.
             *  - realpath() containment plus is_file(), the same idiom
             *    agent/includes/kb_media_serve.php uses. This is strictly
             *    better than the endpoint being replaced, which built the
             *    /uploads URL from the database row without ever asking whether
             *    the bytes were there. */
            if ($att_url === null && !$kb_storage_migrated && $kb_uploads_root !== false) {
                $att_ref = (string) $att['kb_article_attachment_reference_name'];
                if (isUploadReferenceName($att_ref)) {
                    $att_disk = realpath($kb_uploads_root . '/' . $id . '/' . $att_ref);
                    if ($att_disk !== false
                        && strpos($att_disk, $kb_uploads_root . DIRECTORY_SEPARATOR) === 0
                        && is_file($att_disk)) {
                        $att_url = '/uploads/kb/' . $id . '/' . rawurlencode($att_ref);
                    }
                }
            }

            $attachments[] = [
                'id'   => $att_id,
                'name' => $att['kb_article_attachment_name'],
                /* Absolute when signed, root-relative otherwise. The app resolves
                 * both: KbMediaUrl.forAttachment() (KbMediaUrl.kt) repoints a
                 * signed kb_media.php URL onto the configured server, refuses a
                 * token-bearing URL on any other origin, and otherwise falls back
                 * to "$base$url" for a relative value - so the legacy and the
                 * canonical forms both resolve. Named by symbol: this comment used
                 * to describe an inline startsWith("http") test in
                 * KbArticleDetailScreen.kt that no longer exists. */
                'url'  => $att_url ?? '/agent/kb_media.php?att=' . $att_id,
            ];
        }

        /* CONTENT. Two changes, and the ORDER OF THE TWO IS A SECURITY
         * PROPERTY, not a style choice.
         *
         * (1) Purify. This endpoint returned kb_article_content completely raw
         *     while both HTML renderers (the $purifier->purify() calls in
         *     agent/kb_article.php and client/kb_article.php - by symbol, the
         *     line numbers moved once already) purified, so the ONE consumer
         *     that renders it in a WebView was the one getting unfiltered
         *     stored HTML. Measured against all four live articles: purification
         *     changes no tag counts at all (table/tr/td/img/p/li/strong/h1 all
         *     identical before and after) and costs 4-8ms per article; the byte
         *     delta is entity normalisation, e.g. "&mdash;" becoming a literal
         *     em dash.
         *
         * (2) THEN rewrite. HTMLPurifier fills a missing alt from the src
         *     basename, so purifying content that already held a signed URL
         *     yields, measured on the bundled 4.15.0:
         *       alt="kb_media.php?a=1&amp;f=x.png&amp;p=t4&amp;e=99&amp;s=deadbeef"
         *     - the entire capability token as copyable, screen-reader-readable
         *     text on every broken image. Purifying first means the alt is
         *     derived from the canonical URL and never sees a token.
         *     Attr.DefaultImageAlt='' is the second, independent mitigation.
         *     Re-measured 2026-09-08 on the bundled HTMLPurifier 4.15.0: with
         *     the option unset, <img src="https://host/agent/kb_media.php?a=13
         *     &amp;f=6f2b.png&amp;p=t4&amp;e=...&amp;s=<64 hex>"> came back
         *     carrying that entire string again in alt="..."; with it set to ''
         *     the same input came back alt="", and an alt the author actually
         *     wrote ("Network diagram") was untouched in both runs. All four
         *     places that purify KB article HTML set it - here,
         *     agent/kb_article.php, agent/modals/kb_article/kb_article_version_view.php
         *     and client/kb_article.php - and those four are the complete set
         *     (grep -rn 'purify(' over the tree, excluding plugins/).
         *
         * URI.AllowedSchemes matches agent/kb_article.php so the API and the
         * agent page agree on what an article may contain; a root-relative path
         * is not a scheme and is unaffected by it either way. */
        require_once __DIR__ . '/../../plugins/htmlpurifier/HTMLPurifier.standalone.php';
        $kb_purifier_config = HTMLPurifier_Config::createDefault();
        $kb_purifier_config->set('Cache.DefinitionImpl', null);
        $kb_purifier_config->set('URI.AllowedSchemes', ['data' => true, 'src' => true, 'http' => true, 'https' => true]);
        $kb_purifier_config->set('Attr.DefaultImageAlt', '');
        $kb_purifier = new HTMLPurifier($kb_purifier_config);

        $kb_content = $kb_purifier->purify((string) $row['kb_article_content']);

        /* WHAT toSigned() ACTUALLY GUARANTEES - stated precisely because the
         * openapi.yaml description used to promise more than this, and a false
         * promise in a spec is worse than a documented limitation.
         *
         * MediaUrlRewriter recognises the two shapes the writers produce -
         * /uploads/kb/<article_id>/<name> and flat /uploads/kb/<name> - in
         * src=, href= and CSS url(), and turns each into a signed absolute URL.
         * Measured 2026-09-08 by running this file's exact purifier config and
         * then the rewriter, four shapes come back with the raw path intact:
         *   <img src="/uploads/kb/13/sub/aabb.png">      nested directory
         *   <img src="/uploads/kb/13/my file.png">       purifies to my%20file.png
         *   <img src="/uploads/kb/13/archive.tar.gz">    two dots
         *   <img src="/uploads/clients/5/logo.png">      not the KB tree at all
         * The first three are unreachable from the two importers and the editor
         * uploader (every name they generate is hex or base64url plus one
         * extension) and the serve endpoints would refuse them anyway, so
         * rewriting them would swap a working URL for a guaranteed 403; the
         * fourth is a different module's file pasted into an article, is
         * outside the /uploads/kb/ deny, and keeps working. The 2.6.78 storage
         * migration leaves all four alone for the same reason and counts them
         * in a warning, so an operator finds out at update time rather than
         * from a broken image weeks later.
         *
         * When signing is off the content is emitted EXACTLY AS STORED - see
         * the $kb_signing_on note above for why that is the non-regressing
         * choice and why it cannot leak a /uploads path on an install whose web
         * server has begun denying them. */
        if ($kb_signing_on) {
            $kb_content = (new \ITFlow\KB\MediaUrlRewriter($kb_media_host, $kb_media_principal))->toSigned($kb_content);
        }

        api_response(200, [
            'id'             => intval($row['kb_article_id']),
            'title'          => $row['kb_article_title'],
            'content'        => $kb_content,
            'category_id'    => intval($row['kb_article_category_id']),
            'category_name'  => $row['kb_category_name'],
            'client_id'      => intval($row['kb_article_client_id']),
            'client_name'    => $row['client_name'],
            'client_visible' => intval($row['kb_article_client_visible']),
            'updated_at'     => $row['kb_article_updated_at'] ?? $row['kb_article_created_at'],
            'attachments'    => $attachments,
        ]);
    }

    $page   = max(1, intval($_GET['page'] ?? 1));
    $limit  = min(100, max(1, intval($_GET['limit'] ?? 30)));
    $offset = ($page - 1) * $limit;
    $search = mysqli_real_escape_string($mysqli, $_GET['search'] ?? '');

    $where = ['kb_article_archived_at IS NULL', $kb_client_scope_clause];
    if (isset($_GET['category_id'])) {
        $where[] = 'kb_article_category_id = ' . intval($_GET['category_id']);
    }
    if (isset($_GET['client_id'])) {
        $client_id_filter = intval($_GET['client_id']);
        $where[] = "kb_article_client_id IN (0, $client_id_filter)";
    }
    if ($search) {
        $where[] = "(kb_article_title LIKE '%$search%' OR MATCH(kb_article_content_raw) AGAINST ('$search'))";
    }
    $w = implode(' AND ', $where);

    $total = intval(mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT COUNT(*) AS cnt FROM kb_articles WHERE $w"))['cnt']);

    $articles = [];
    $sql = mysqli_query($mysqli,
        "SELECT kb_articles.kb_article_id, kb_articles.kb_article_title,
                kb_articles.kb_article_category_id, kb_articles.kb_article_client_id,
                kb_articles.kb_article_client_visible, kb_articles.kb_article_created_at,
                kb_articles.kb_article_updated_at,
                clients.client_name, kb_categories.kb_category_name
         FROM kb_articles
         LEFT JOIN clients ON clients.client_id = kb_articles.kb_article_client_id
         LEFT JOIN kb_categories ON kb_categories.kb_category_id = kb_articles.kb_article_category_id
         WHERE $w
         ORDER BY kb_articles.kb_article_title ASC
         LIMIT $limit OFFSET $offset"
    );
    while ($row = mysqli_fetch_assoc($sql)) {
        $articles[] = [
            'id'             => intval($row['kb_article_id']),
            'title'          => $row['kb_article_title'],
            'category_id'    => intval($row['kb_article_category_id']),
            'category_name'  => $row['kb_category_name'],
            'client_id'      => intval($row['kb_article_client_id']),
            'client_name'    => $row['client_name'],
            'client_visible' => intval($row['kb_article_client_visible']),
            'updated_at'     => $row['kb_article_updated_at'] ?? $row['kb_article_created_at'],
        ];
    }
    api_response(200, ['data' => $articles, 'total' => $total]);
}

api_error(404, 'Not found');
