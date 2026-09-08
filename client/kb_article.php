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
/* Without this, an <img> with no alt gets one synthesised from the src
 * basename - which for a KB media URL is "kb_media.php?a=13&amp;f=x.png".
 * Harmless-looking here, but it is the same setting that stops a signed API URL
 * leaking its whole capability token into alt text, and the four KB purifier
 * configs are kept identical on this point so no renderer can drift. */
$purifier_config->set('Attr.DefaultImageAlt', '');
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
    /* Repoint every KB media URL at the PORTAL's own serve endpoint.
     *
     * WHY THIS PAGE NEEDS A TRANSFORMER AND THE AGENT PAGE DOES NOT. The
     * canonical URL stored in kb_article_content is /agent/kb_media.php?..., and
     * it is signature-free on purpose - on the web the session cookie is the
     * credential, which is what lets the stored HTML survive a TinyMCE save and
     * reload unchanged. But a department contact holds a PORTAL session, not an
     * agent one: /agent/kb_media.php would run includes/check_login.php and 302
     * them to the agent login, i.e. every image in every article a department
     * can read would be broken. So the URL is rewritten for display only, in
     * the \ITFlow\Knowledge\CredentialReferenceRenderer spirit - the database
     * row is never touched, and an agent editing the same article still sees
     * and re-saves the canonical /agent/ form.
     *
     * /client/kb_media.php takes the identical query shape and re-derives access
     * with THIS page's own article SQL (client_visible = 1, client_id IN (0,
     * $session_client_id), not archived), so a contact can never reach media
     * belonging to an article the portal would not show them. It accepts no
     * signature at all: a portal request always has a cookie, so there is no
     * portal principal type to get wrong.
     *
     * The same call also normalises any pre-migration raw /uploads/kb/ path, so
     * an article that has not been migrated yet renders here too instead of
     * showing broken images once nginx starts denying /uploads.
     *
     * The page CSP (default-src 'self'; img-src 'self' data:, set at the top of
     * this file) permits it - the endpoint is same-origin. */
    $kb_article_content = $purifier->purify($row['kb_article_content']);
    $kb_article_content = \ITFlow\KB\MediaUrlRewriter::toPortal($kb_article_content);
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
