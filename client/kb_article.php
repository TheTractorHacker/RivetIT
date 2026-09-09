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
/* INTERACTIVE KB BLOCKS. Registers the data-ikb vocabulary so a department
 * contact gets the working checklist / wizard / tab set / decision tree an agent
 * gets, rather than the flattened prose this renderer would otherwise produce.
 * MUST be above the constructor - see the note in agent/kb_article.php, which
 * names all four call sites and why they must not drift. */
\ITFlow\KB\InteractiveBlocks::apply($purifier_config);
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

/* READ-ONLY, AND WHY THE PAGE DECIDES RATHER THAN THE ENDPOINT.
 *
 * An admin previewing a department portal holds no portal credential:
 * client/includes/check_login.php gives the preview contact_id 0, and
 * client/post.php calls portalPreviewBlockWrites() at its door for every single
 * request. So a progress write from a preview would be both meaningless (there
 * is no contact to own it) and refused.
 *
 * The design does not fight that and does not eat a 403: with
 * data-ikb-readonly="1" the render layer records the change in memory and never
 * issues the request at all. The admin gets a fully working preview of the
 * interactive article - every tick, step, tab and branch behaves identically -
 * nothing is written, nothing 403s, and the audit log is not filled with blocked
 * writes for actions that were never real writes. Measured in headless Chromium:
 * a normal contact's session issued POSTs, the preview issued ZERO, and every
 * block behaved the same in both.
 *
 * $portal_preview_active is set once by check_login.php; portalPreviewActive()
 * itself re-derives authority from the database on every call and its own
 * docblock says to call it once per page. The contact_id test is the second
 * condition rather than a duplicate: a session with no contact has no principal
 * to save against however it got here. */
$ikb_read_only = !empty($portal_preview_active) || $session_contact_id <= 0;

/* THE READER'S SAVED PROGRESS, for the render root below.
 *
 * LOADED THROUGH THE PROGRESS WORK STREAM'S OWN STORE, not through a query
 * written here. agent/includes/kb_progress_store.php holds the schema, the key
 * grammar, the caps and the SQL exactly once precisely so that a second
 * statement of them cannot drift; kbProgressLoad() states plainly that it does
 * NO authorization and that every caller must have settled "may this principal
 * read this article" before calling it. This page has: the article query above
 * IS the visibility test (client_visible = 1, client_id IN (0,
 * $session_client_id), archived_at IS NULL), and this runs on the far side of
 * the redirect that fires when it returns nothing.
 *
 * THE PRINCIPAL IS A PAIR - ('c', contacts.contact_id) here, ('u',
 * users.user_id) on the agent side. A department contact is not a users row and
 * the two id spaces overlap numerically, so the type character is what keeps
 * contact 7's ticks apart from agent 7's. A previewing admin has contact_id 0,
 * which the guard below turns into "no saved progress" without a query.
 *
 * GUARDED ON EVERY STEP, and deliberately. The store, its endpoints and the
 * 2.6.79 database update are three separate deployment steps on this project;
 * an article page must not 500 because one of them has not happened yet. Each
 * guard degrades to "no saved progress", which renders every block unticked and
 * fully working - the same state a reader who has ticked nothing sees.
 * kbProgressLoad() is itself wrapped in try/catch for the missing-table case.
 *
 * data-ikb-hashes is what the article says NOW; data-ikb-progress carries the
 * hash recorded at tick time. The render layer marks the difference, so a tick
 * against words that have since changed is neither silently kept nor silently
 * dropped. hashesAttribute() short-circuits on an article with no blocks.
 *
 * PRIVACY: this puts ONE reader's state in the page body, which is safe only
 * because article pages are not served from a shared cache. */
$ikb_progress = [];
$ikb_progress_store = $_SERVER['DOCUMENT_ROOT'] . '/agent/includes/kb_progress_store.php';
if ($session_contact_id > 0 && is_file($ikb_progress_store)) {
    if (!defined('FROM_KB_PROGRESS')) {
        define('FROM_KB_PROGRESS', true);
    }
    require_once $ikb_progress_store;
    if (function_exists('kbProgressLoad')) {
        $ikb_progress = kbProgressLoad($mysqli, $kb_article_id, 'c', $session_contact_id);
    }
}
$ikb_progress_json = \ITFlow\KB\InteractiveBlocks::progressAttribute($ikb_progress);
$ikb_hashes_json = $ikb_progress === []
    ? '{}'
    : \ITFlow\KB\InteractiveBlocks::hashesAttribute($kb_article_content);

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
    <?php /*
        THE INTERACTIVE-BLOCK RENDER ROOT. Page chrome, outside the purified
        string. See the fuller note at the matching wrapper in
        agent/kb_article.php for why configuration travels on data attributes
        and not in an inline <script> - on THIS page that is not a preference,
        it is the CSP at the top of this file: "default-src 'self'" with no
        script-src, no nonce and no 'unsafe-inline' means an inline script does
        not execute here at all.

        data-ikb-endpoint is "kb_progress.php", RELATIVE, so it can only ever
        resolve to /client/kb_progress.php and never into /agent/. That endpoint
        re-runs this page's own visibility clause against the database on every
        request, and reproduces client/post.php's portal-preview write gate
        verbatim, in the same position, above any read of a request value - read
        its header for why it is a second portal write path and what that costs.
    */ ?>
    <div class="card-body prettyContent"
         data-ikb-root
         data-ikb-version="<?php echo \ITFlow\KB\InteractiveBlocks::VERSION; ?>"
         data-ikb-article="<?php echo $kb_article_id; ?>"
         data-ikb-readonly="<?php echo $ikb_read_only ? '1' : '0'; ?>"
         data-ikb-endpoint="kb_progress.php"
         data-ikb-csrf="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES); ?>"
         data-ikb-progress="<?php echo htmlspecialchars($ikb_progress_json, ENT_QUOTES); ?>"
         data-ikb-hashes="<?php echo htmlspecialchars($ikb_hashes_json, ENT_QUOTES); ?>">
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

<?php /*
    The render layer. Emitted by this page rather than added to
    client/includes/footer.php's fixed script list, so the portal's other pages
    do not ship it - the same reasoning that gates portal_preview_readonly.js
    there. It is inert without a [data-ikb-root] anyway; this just avoids the
    download. defer, because all of its work is event-driven.
*/ ?>
<script src="/js/kb_interactive.js?v=<?php echo filemtime($_SERVER['DOCUMENT_ROOT'] . '/js/kb_interactive.js'); ?>" defer></script>

<?php
require_once "includes/footer.php";
