<?php
/*
 * Client Portal - "Ask for help" suggestions (read-only JSON).
 *
 *   GET /client/help_suggest.php?q=<what the person typed>
 *       -> {"ok":true,"kb":[{"title","url","category"}],"catalog":[{"name","url","description"}]}
 *
 * Feeds the help box on the portal home (js/portal_help.js): while someone describes a problem it offers the knowledge-base articles
 * they may already read and the service-catalog requests they may raise, so a how-to is answered without a ticket and a request starts
 * with its form prefilled. Same visibility as the pages it points at: client/kb_articles.php (client-visible, central or own department,
 * not archived) and client/service_catalog.php (active items). GET only, no write, bound parameters throughout; the portal session
 * comes from the same bootstrap client/kb_progress.php uses so no page chrome is printed ahead of the JSON.
 */

ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_global_settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/client/includes/check_login.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$q = trim((string) ($_GET['q'] ?? ''));
$q = function_exists('mb_substr') ? mb_substr($q, 0, 100) : substr($q, 0, 100);
if ((function_exists('mb_strlen') ? mb_strlen($q) : strlen($q)) < 2) {
    echo json_encode(['ok' => true, 'kb' => [], 'catalog' => []]);
    exit;
}

// LIKE metacharacters in what was typed are literal text, not wildcards.
$like = '%' . strtr($q, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
$client_id = intval($session_client_id);
$out = ['ok' => true, 'kb' => [], 'catalog' => []];

if (!empty($config_module_enable_kb)) {
    $stmt = mysqli_prepare(
        $mysqli,
        "SELECT a.kb_article_id, a.kb_article_title, c.kb_category_name
         FROM kb_articles a
         LEFT JOIN kb_categories c ON c.kb_category_id = a.kb_article_category_id
         WHERE a.kb_article_client_visible = 1
           AND a.kb_article_client_id IN (0, ?)
           AND a.kb_article_archived_at IS NULL
           AND (a.kb_article_title LIKE ? OR a.kb_article_content_raw LIKE ?)
         ORDER BY (a.kb_article_title LIKE ?) DESC, a.kb_article_title ASC
         LIMIT 4"
    );
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'isss', $client_id, $like, $like, $like);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) {
            $out['kb'][] = [
                'title' => (string) $row['kb_article_title'],
                'url' => 'kb_article.php?id=' . intval($row['kb_article_id']),
                'category' => (string) ($row['kb_category_name'] ?? ''),
            ];
        }
        mysqli_stmt_close($stmt);
    }
}

$stmt = mysqli_prepare(
    $mysqli,
    "SELECT catalog_item_id, name, description
     FROM service_catalog_items
     WHERE is_active = 1 AND (name LIKE ? OR description LIKE ?)
     ORDER BY (name LIKE ?) DESC, sort_order ASC, name ASC
     LIMIT 4"
);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, 'sss', $like, $like, $like);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) {
        $desc = trim(strip_tags((string) $row['description']));
        $out['catalog'][] = [
            'name' => (string) $row['name'],
            'url' => 'ticket_add.php?catalog_item_id=' . intval($row['catalog_item_id']),
            'description' => function_exists('mb_substr') ? mb_substr($desc, 0, 90) : substr($desc, 0, 90),
        ];
    }
    mysqli_stmt_close($stmt);
}

echo json_encode($out);
