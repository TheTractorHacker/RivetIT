<?php
/*
 * Client Portal
 * Knowledge Base - Article detail (read-only)
 */

header("Content-Security-Policy: default-src 'self'; img-src 'self' data:");

require_once "includes/inc_all.php";

if ($config_module_enable_kb != 1) {
    header("Location: index.php");
    exit();
}

//Initialize the HTML Purifier to prevent XSS
require_once "../plugins/htmlpurifier/HTMLPurifier.standalone.php";

$purifier_config = HTMLPurifier_Config::createDefault();
$purifier_config->set('Cache.DefinitionImpl', null); // Disable cache by setting a non-existent directory or an invalid one
$purifier_config->set('URI.AllowedSchemes', ['http' => true, 'https' => true]);
$purifier = new HTMLPurifier($purifier_config);

// Check for an article ID
if (!isset($_GET['id']) || !intval($_GET['id'])) {
    header("Location: kb_articles.php");
    exit();
}

$kb_article_id = intval($_GET['id']);

$sql_kb_article = mysqli_query($mysqli,
    "SELECT kb_article_id, kb_article_title, kb_article_content, kb_article_client_id, kb_article_updated_at, kb_article_created_at
     FROM kb_articles
     WHERE kb_article_id = $kb_article_id
     AND kb_article_client_visible = 1
     AND kb_article_client_id IN (0, $session_client_id)
     AND kb_article_archived_at IS NULL
     LIMIT 1"
);

$row = mysqli_fetch_assoc($sql_kb_article);

if ($row) {
    $kb_article_id = intval($row['kb_article_id']);
    $kb_article_title = nullable_htmlentities($row['kb_article_title']);
    $kb_article_content = $purifier->purify($row['kb_article_content']);
    $kb_article_updated_at = $row['kb_article_updated_at'] ?? $row['kb_article_created_at'];
} else {
    flash_alert("Article not found", "error");
    header("Location: kb_articles.php");
    exit();
}

?>

<ol class="breadcrumb d-print-none">
    <li class="breadcrumb-item">
        <a href="index.php">Home</a>
    </li>
    <li class="breadcrumb-item">
        <a href="kb_articles.php">Knowledge Base</a>
    </li>
    <li class="breadcrumb-item active">
        <?php echo $kb_article_title; ?>
    </li>
</ol>

<div class="card">
    <div class="card-body prettyContent">
        <h3><?php echo $kb_article_title; ?></h3>
        <p class="text-muted"><small>Last updated: <?php echo date('M j, Y', strtotime($kb_article_updated_at)); ?></small></p>
        <hr>
        <?php echo $kb_article_content; ?>
    </div>
</div>

<?php
require_once "includes/footer.php";
