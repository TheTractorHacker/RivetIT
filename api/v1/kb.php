<?php
// GET /api/v1/kb/categories          - list KB categories
// GET /api/v1/kb/articles            - list KB articles (category_id, client_id, search, page, limit)
// GET /api/v1/kb/articles/{id}       - KB article detail (content + attachments)
defined('FROM_API') || die();
require_once __DIR__ . '/includes/api_permissions.php';

if ($method !== 'GET') api_error(405, 'Method not allowed');

$uid = $api_user_id;
api_require_module_permission($mysqli, $uid, 'module_kb');

// Client-scope restriction: kb_article_client_id = 0 means a company-wide article
// (visible to everyone, matching the existing client_id filter's "IN (0, ...)" pattern
// below), otherwise it must be in the caller's permitted client set.
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
         * closed. Every media URL below now points at agent/kb_media.php, which
         * re-runs the whole permission chain from the database on each request.
         *
         * The consumer is what forces the signature. KbArticleDetailScreen.kt
         * renders `content` in a WebView via loadDataWithBaseURL(), so an <img>
         * subresource is fetched by the system network stack with NO X-Api-Key
         * header; and an attachment is handed to an EXTERNAL browser
         * (KbArticleDetailScreen.kt:127), which has neither header nor cookie.
         * A capability in the URL is the only credential either of them can
         * carry. See src/KB/MediaToken.php for why that is authentication and
         * never authorization.
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
         * stores $_SERVER['HTTP_HOST']); functions.php:3938 prepends the scheme
         * the same way. Normalised the way includes/webauthn.php:135 does, in
         * case an install has hand-edited a scheme or a trailing slash into it.
         *
         * If it is empty there is nothing safe to sign against, and $_SERVER's
         * Host header is deliberately NOT used as a fallback: it is caller
         * controlled, and echoing a capability token back on a caller-chosen
         * host is a token-exfiltration primitive. Signing is simply off, and
         * every URL below degrades to the canonical root-relative form - which
         * fails CLOSED for the app (no cookie, so kb_media.php sends it to the
         * login page) instead of failing open on a raw /uploads path. */
        $kb_media_host = rtrim(preg_replace('#^https?://#i', '', trim((string) ($config_base_url ?? ''))), '/');

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
            $att_url = $kb_media_host === '' ? null : \ITFlow\KB\MediaToken::signedUrl(
                $kb_media_host,
                \ITFlow\KB\MediaToken::KIND_ATTACHMENT,
                $att_id,
                '',
                $kb_media_principal,
                \ITFlow\KB\MediaToken::TTL_ATTACH
            );

            $attachments[] = [
                'id'   => $att_id,
                'name' => $att['kb_article_attachment_name'],
                // Absolute when signed. KbArticleDetailScreen.kt:127 does
                // `if (att.url.startsWith("http")) att.url else "$baseUrl${att.url}"`,
                // so both forms work in the app with no code change there.
                'url'  => $att_url ?? '/agent/kb_media.php?att=' . $att_id,
            ];
        }

        /* CONTENT. Two changes, and the ORDER OF THE TWO IS A SECURITY
         * PROPERTY, not a style choice.
         *
         * (1) Purify. This endpoint returned kb_article_content completely raw
         *     while both HTML renderers (agent/kb_article.php:40,
         *     client/kb_article.php:47) purified - so the ONE consumer that
         *     renders it in a WebView was the one getting unfiltered stored
         *     HTML. Measured against all four live articles: purification
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
         *
         * URI.AllowedSchemes matches agent/kb_article.php:16 so the API and the
         * agent page agree on what an article may contain; a root-relative path
         * is not a scheme and is unaffected by it either way. */
        require_once __DIR__ . '/../../plugins/htmlpurifier/HTMLPurifier.standalone.php';
        $kb_purifier_config = HTMLPurifier_Config::createDefault();
        $kb_purifier_config->set('Cache.DefinitionImpl', null);
        $kb_purifier_config->set('URI.AllowedSchemes', ['data' => true, 'src' => true, 'http' => true, 'https' => true]);
        $kb_purifier_config->set('Attr.DefaultImageAlt', '');
        $kb_purifier = new HTMLPurifier($kb_purifier_config);

        $kb_content = $kb_purifier->purify((string) $row['kb_article_content']);
        if ($kb_media_host !== '') {
            $kb_content = (new \ITFlow\KB\MediaUrlRewriter($kb_media_host, $kb_media_principal))->toSigned($kb_content);
        } else {
            // No host to sign against, but a raw /uploads path must still never
            // leave this endpoint - normalise to the canonical URL, which is
            // authenticated even though this caller cannot satisfy it.
            $kb_content = \ITFlow\KB\MediaUrlRewriter::toAgentCanonical($kb_content);
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
