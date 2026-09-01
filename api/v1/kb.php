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

        $attachments = [];
        $asql = mysqli_query($mysqli,
            "SELECT kb_article_attachment_id, kb_article_attachment_name, kb_article_attachment_reference_name
             FROM kb_article_attachments
             WHERE kb_article_attachment_kb_article_id = $id
             ORDER BY kb_article_attachment_created_at ASC"
        );
        while ($att = mysqli_fetch_assoc($asql)) {
            $attachments[] = [
                'id'   => intval($att['kb_article_attachment_id']),
                'name' => $att['kb_article_attachment_name'],
                'url'  => "/uploads/kb/$id/" . $att['kb_article_attachment_reference_name'],
            ];
        }

        api_response(200, [
            'id'             => intval($row['kb_article_id']),
            'title'          => $row['kb_article_title'],
            'content'        => $row['kb_article_content'],
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
