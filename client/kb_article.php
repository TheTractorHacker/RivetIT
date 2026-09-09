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
    /* Repoint every KB media URL at the PORTAL's own serve endpoint,
     * client/kb_media.php.
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
     * client/kb_media.php takes the identical query shape and re-derives access
     * with THIS page's own article SQL - the three visibility lines of the
     * $sql_kb_article query above (client_visible = 1, client_id IN (0,
     * $session_client_id), archived_at IS NULL), which it re-runs against the
     * database on every media request -
     * so a contact can never reach media belonging to an article the portal
     * would not show them, and an article hidden from the portal does not leak
     * its diagrams to anyone who can guess its id. It does not merely ignore a
     * signature, it REFUSES a request carrying one: a portal request always has
     * a cookie, so there is no portal principal type at all, and toPortal()
     * cannot emit a p/e/s-bearing URL.
     *
     * THREE SHAPES, not just <img src>. toPortal() also rewrites <a href> and
     * CSS url() inside a style attribute, because HTMLPurifier keeps both
     * (measured against the bundled 4.15.0 with the config above) and both are
     * otherwise left pointing at /uploads with no rewrite path at all.
     *
     * The same call also normalises any pre-migration raw /uploads/kb/ path, so
     * an article that has not been migrated yet renders here too instead of
     * showing broken images once nginx starts denying /uploads.
     *
     * The page CSP (default-src 'self'; img-src 'self' data:, set at the top of
     * this file) permits all three - the endpoint is same-origin, and a
     * top-level navigation from a rewritten <a href> is not governed by
     * default-src at all. */
    $kb_article_content = $purifier->purify($row['kb_article_content']);
    $kb_article_content = \ITFlow\KB\MediaUrlRewriter::toPortal($kb_article_content);
    $kb_article_updated_at = $row['kb_article_updated_at'] ?? $row['kb_article_created_at'];
} else {
    flash_alert("Article not found", "error");
    header("Location: kb_articles.php");
    exit();
}

/* ATTACHMENTS, visible to the department.
 *
 * Only reachable once the article query above has returned a row, so it is
 * already scoped: that query is the visibility test (client_visible = 1,
 * client_id IN (0, $session_client_id), archived_at IS NULL), and this runs on
 * the far side of the redirect that fires when it fails. The article is the
 * unit of visibility here - there is deliberately no per-attachment switch, so
 * "can this department read this article" is the whole of the question, the
 * same rule its inline images already follow.
 *
 * client/kb_media.php re-derives all of that from the database on every request
 * anyway, joining kb_article_attachments to kb_articles and applying the same
 * three lines, so this listing is a convenience and never the gate. A contact
 * who guesses another department's attachment id still gets a 404.
 */
$sql_attachments = mysqli_query(
    $mysqli,
    "SELECT kb_article_attachment_id, kb_article_attachment_name
     FROM kb_article_attachments
     WHERE kb_article_attachment_kb_article_id = $kb_article_id
     ORDER BY kb_article_attachment_created_at ASC"
);

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

<?php if (mysqli_num_rows($sql_attachments) > 0) { ?>
<div class="card mt-3">
    <div class="card-header">
        <h5 class="card-title mb-0"><i class="fas fa-fw fa-paperclip me-2"></i>Attachments</h5>
    </div>
    <ul class="list-group list-group-flush">
        <?php while ($att = mysqli_fetch_assoc($sql_attachments)) {
            $att_id = intval($att['kb_article_attachment_id']);
            $att_name = nullable_htmlentities($att['kb_article_attachment_name']);
        ?>
        <li class="list-group-item d-flex align-items-center justify-content-between flex-wrap gap-2">
            <span class="text-truncate me-2"><i class="fas fa-fw fa-file me-1"></i><?php echo $att_name; ?></span>
            <?php /*
                 Both links go through client/kb_media.php, never the raw
                 /uploads/kb/ path - that path is unauthenticated, and it also
                 carries a blanket Content-Disposition: attachment from nginx, so
                 "View" there could only ever download. The endpoint authenticates
                 the portal session, re-checks the article's visibility and
                 department scope, and decides inline vs attachment from the
                 file's real bytes.

                 No Delete, unlike the agent-side card this mirrors. The portal is
                 read-only by construction: client/post.php has no handler for it,
                 and during an admin portal preview every write is blocked anyway.
            */ ?>
            <span class="text-nowrap">
                <a target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary" href="/client/kb_media.php?att=<?php echo $att_id; ?>">
                    <i class="fas fa-fw fa-eye me-1"></i>View
                </a>
                <a class="btn btn-sm btn-outline-secondary" download="<?php echo $att_name; ?>" href="/client/kb_media.php?att=<?php echo $att_id; ?>&amp;download=1">
                    <i class="fas fa-fw fa-download me-1"></i>Download
                </a>
            </span>
        </li>
        <?php } ?>
    </ul>
</div>
<?php } ?>

<?php
require_once "includes/footer.php";
