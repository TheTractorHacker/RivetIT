<?php

require_once '../../../includes/modal_header.php';

enforceUserPermission('module_kb');

// Initialize the HTML Purifier to prevent XSS
require_once "../../../plugins/htmlpurifier/HTMLPurifier.standalone.php";

$purifier_config = HTMLPurifier_Config::createDefault();
$purifier_config->set('Cache.DefinitionImpl', null);
$purifier_config->set('URI.AllowedSchemes', ['data' => true, 'src' => true, 'http' => true, 'https' => true]);
// Kept identical to the other three KB purifier configs. See agent/kb_article.php
// for the measurement: without this, an <img> with no alt gets one synthesised
// from the src basename, which for a query-string media URL means the URL's
// parameters end up in the alt text.
$purifier_config->set('Attr.DefaultImageAlt', '');
/* INTERACTIVE KB BLOCKS. Kept identical to the other three KB purifier configs
 * (agent/kb_article.php, client/kb_article.php, api/v1/kb.php). Without it a
 * historical snapshot containing blocks would render here as flat prose, so a
 * reviewer comparing a version against the live article would be comparing two
 * different renderings rather than two versions. */
\ITFlow\KB\InteractiveBlocks::apply($purifier_config);
$purifier = new HTMLPurifier($purifier_config);

$kb_article_version_id = intval($_GET['id']);

$sql = mysqli_query($mysqli, "SELECT kb_article_versions.*, kb_articles.kb_article_title, kb_articles.kb_article_client_id, users.user_name
    FROM kb_article_versions
    LEFT JOIN kb_articles ON kb_articles.kb_article_id = kb_article_versions.kb_article_version_kb_article_id
    LEFT JOIN users ON users.user_id = kb_article_versions.kb_article_version_edited_by
    WHERE kb_article_version_id = $kb_article_version_id LIMIT 1");

$row = mysqli_fetch_assoc($sql);

$kb_article_client_id = intval($row['kb_article_client_id']);
if ($kb_article_client_id > 0) {
    enforceClientAccess($kb_article_client_id);
}

$kb_article_version_number = intval($row['kb_article_version_number']);
$kb_article_title = nullable_htmlentities($row['kb_article_title']);
$kb_article_version_editor = nullable_htmlentities($row['user_name']) ?: '<span class="text-muted">Unknown</span>';
$kb_article_version_edited_at = nullable_htmlentities(date('M d, Y g:i A', strtotime($row['kb_article_version_edited_at'])));
$kb_article_version_content = $purifier->purify($row['kb_article_version_content']);
/* Historical snapshots are the corpus MOST likely to still hold pre-migration
 * media paths - measured on the live database 2026-09-08, 2 of them do, against
 * 3 live articles - because a version row is written once and never edited
 * again. This is an agent session on the same origin, so the canonical
 * /agent/kb_media.php URL authenticates itself with the cookie exactly as it
 * does on the article page; all this call does is bring old rows up to that
 * shape at render time. A no-op on anything already canonical.
 *
 * This modal still does NOT run CredentialReferenceRenderer, so a
 * "[[credential:123]]" token in an old snapshot renders as literal text here
 * while the article page turns it into a button. That is a pre-existing
 * inconsistency, unrelated to media, and deliberately not fixed here. */
$kb_article_version_content = \ITFlow\KB\MediaUrlRewriter::toAgentCanonical($kb_article_version_content);

ob_start();
?>

<div class="modal-header bg-dark">
    <h5 class="modal-title text-white"><i class="fa fa-fw fa-history me-2"></i><?php echo $kb_article_title; ?> &mdash; Version #<?php echo $kb_article_version_number; ?></h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<div class="modal-body">
    <p class="text-secondary small mb-3">Edited by <?php echo $kb_article_version_editor; ?> on <?php echo $kb_article_version_edited_at; ?></p>
    <?php /*
        A render root, but a READ-ONLY one: no endpoint and no progress. A
        version snapshot is a historical document, its part keys may not match
        anything the live article still has, and a tick here would be a write
        against a version that no longer exists. data-ikb-readonly="1" makes the
        render layer's save() a no-op, so every block is fully walkable and
        nothing is recorded.
    */ ?>
    <div class="prettyContent"
         data-ikb-root
         data-ikb-version="<?php echo \ITFlow\KB\InteractiveBlocks::VERSION; ?>"
         data-ikb-article="<?php echo intval($row['kb_article_version_kb_article_id']); ?>"
         data-ikb-readonly="1"
         data-ikb-endpoint=""
         data-ikb-progress="{}">
        <?php echo $kb_article_version_content; ?>
    </div>
</div>

<script src="../js/pretty_content.js"></script>
<?php /*
    ajax_modal.js re-creates every injected <script> with the page nonce and runs
    them in order, so this loads inside the modal. kb_interactive.js detects that
    it is already present on the page it was opened from and simply boots the new
    root instead of re-running its own body.
*/ ?>
<script src="/js/kb_interactive.js?v=<?php echo filemtime($_SERVER['DOCUMENT_ROOT'] . '/js/kb_interactive.js'); ?>"></script>

<?php
require_once '../../../includes/modal_footer.php';
