<?php
/*
 * Client Portal
 * Knowledge Base - Central (company-wide) + client-specific articles
 */

header("Content-Security-Policy: default-src 'self'");

// inc_all.php streams the page chrome (header.php) before control returns
// here, so the redirect below needs output buffered - otherwise header()
// silently fails ("headers already sent") and the page just dies with no
// content and no redirect.
ob_start();
require_once "includes/inc_all.php";

if ($config_module_enable_kb != 1) {
    ob_end_clean();
    header("Location: index.php");
    exit();
}

$q = isset($_GET['q']) && is_string($_GET['q']) ? sanitizeInput($_GET['q']) : '';
// what the person typed, for showing back to them (sanitizeInput() output is SQL-escaped, so it would show stray backslashes)
$q_display = isset($_GET['q']) && is_string($_GET['q']) ? mb_substr(trim(strip_tags($_GET['q'])), 0, 200) : '';

if ($q) {
    $q_escaped = mysqli_real_escape_string($mysqli, $q);
    // a typed % or _ is a letter to search for here, not a wildcard
    $q_like = str_replace(['%', '_'], ['\\%', '\\_'], $q);
    $kb_search_query = "AND (kb_article_title LIKE '%$q_like%' OR MATCH(kb_article_content_raw) AGAINST ('$q_escaped'))";
} else {
    $kb_search_query = '';
}

$kb_articles_sql = mysqli_query(
    $mysqli,
    "SELECT kb_articles.*, kb_categories.kb_category_name
     FROM kb_articles
     LEFT JOIN kb_categories ON kb_categories.kb_category_id = kb_articles.kb_article_category_id
     WHERE kb_article_client_visible = 1
     AND kb_article_client_id IN (0, $session_client_id)
     AND kb_article_archived_at IS NULL
     $kb_search_query
     ORDER BY kb_category_name IS NULL, kb_category_name ASC, kb_article_title ASC"
);

// Group articles by category for the card grid
$kb_groups = [];
while ($row = mysqli_fetch_assoc($kb_articles_sql)) {
    $group_name = $row['kb_category_name'] ?? 'Uncategorized';
    $kb_groups[$group_name][] = $row;
}
// "Uncategorized" group always last
if (isset($kb_groups['Uncategorized'])) {
    $uncategorized = $kb_groups['Uncategorized'];
    unset($kb_groups['Uncategorized']);
    $kb_groups['Uncategorized'] = $uncategorized;
}

?>

<div class="row">
    <div class="col">
        <h3><i class="fas fa-book me-2"></i>Knowledge Base</h3>
    </div>
</div>

<div class="row mt-3">
    <div class="col-md-12">
        <form autocomplete="off" class="mb-3">
            <div class="input-group portal-search-group">
                <input type="search" class="form-control" name="q" value="<?= nullable_htmlentities($q_display) ?>" placeholder="Search Knowledge Base" aria-label="Search Knowledge Base" maxlength="200">
                <div class="input-group-append">
                    <button class="btn btn-primary" aria-label="Search"><i class="fa fa-search"></i></button>
                </div>
            </div>
            <?php if ($q !== '') { ?>
                <div class="small text-secondary mt-2">Showing results for <strong><?= nullable_htmlentities($q_display) ?></strong>. <a href="kb_articles.php">Show all articles</a></div>
            <?php } ?>
        </form>

        <?php if (empty($kb_groups)) { ?>
            <p class="text-muted text-center py-4"><?= $q !== '' ? 'No articles match your search. Try different words, or ' : 'No articles are available yet.' ?><?= $q !== '' ? '<a href="ticket_add.php">raise a ticket</a>.' : '' ?></p>
        <?php } ?>

        <?php foreach ($kb_groups as $group_name => $articles) { ?>
            <h5 class="mt-3 mb-3"><i class="fas fa-fw fa-folder text-secondary me-2"></i><?= nullable_htmlentities($group_name) ?></h5>
            <div class="row">
                <?php foreach ($articles as $row) {
                    $kb_article_id = intval($row['kb_article_id']);
                    $kb_article_title = nullable_htmlentities($row['kb_article_title']);
                    $kb_article_client_id = intval($row['kb_article_client_id']);
                    $kb_article_updated_at = $row['kb_article_updated_at'] ?? $row['kb_article_created_at'];

                    require_once __DIR__ . '/../includes/kb_excerpt.php';
                    $kb_article_preview = kbExcerpt($row['kb_article_content_raw'] ?? '', $row['kb_article_title'] ?? '');
                ?>
                    <div class="col-md-4 mb-4">
                        <div class="card h-100">
                            <div class="card-body">
                                <h5 class="card-title">
                                    <a href="kb_article.php?id=<?= $kb_article_id ?>"><?= $kb_article_title ?></a>
                                    <?php if ($kb_article_client_id == 0) { ?>
                                        <span class="badge text-bg-info ms-2">Central</span>
                                    <?php } ?>
                                </h5>
                                <p class="card-text text-muted small"><?= nullable_htmlentities($kb_article_preview) ?></p>
                            </div>
                            <div class="card-footer d-flex align-items-center justify-content-between bg-white">
                                <small class="text-muted"><?= date('Y-m-d', strtotime($kb_article_updated_at)) ?></small>
                                <a href="kb_article.php?id=<?= $kb_article_id ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-eye me-1" aria-hidden="true"></i>View
                                </a>
                            </div>
                        </div>
                    </div>
                <?php } ?>
            </div>
        <?php } ?>
    </div>
</div>

<?php
require_once "includes/footer.php";
