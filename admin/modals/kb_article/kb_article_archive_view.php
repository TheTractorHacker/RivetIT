<?php

require_once '../../../includes/modal_header.php';

/*
 * Read-only content preview for an ARCHIVED article - the "Verify" action on
 * admin/kb_articles_archive.php. Deliberately its own small page rather than
 * reusing agent/kb_article.php: that page's query requires
 * kb_article_archived_at IS NULL (so an archived id 404s there, which is
 * exactly why this had to be built at all), and everything else on it -
 * Edit/Delete/Attachments/Review Schedule buttons, the version-history link -
 * is either wrong for something already archived or duplicates an action this
 * modal doesn't own. This shows the content and nothing else.
 *
 * Purified the same way agent/kb_article.php does (identical config, kept in
 * sync by hand since there is no shared helper for it - four other KB
 * renderers already do the same, see agent/kb_article.php:24), so what an
 * admin verifies here is what would actually render if the article were
 * restored, not a raw/unescaped dump of the stored column.
 */

$kb_article_id = intval($_GET['id'] ?? 0);

$sql = mysqli_query(
    $mysqli,
    "SELECT kb_articles.kb_article_title, kb_articles.kb_article_content,
            kb_articles.kb_article_client_id, kb_articles.kb_article_archived_at,
            kb_articles.kb_article_updated_at, kb_articles.kb_article_created_at,
            clients.client_name
     FROM kb_articles
     LEFT JOIN clients ON clients.client_id = kb_articles.kb_article_client_id
     WHERE kb_articles.kb_article_id = $kb_article_id
       AND kb_articles.kb_article_archived_at IS NOT NULL
     LIMIT 1"
);

$row = $sql ? mysqli_fetch_assoc($sql) : null;

ob_start();

if (!$row) {
    ?>
    <div class="modal-header bg-dark">
        <h5 class="modal-title text-white">Not found</h5>
        <button type="button" class="close text-white" data-bs-dismiss="modal"><span>&times;</span></button>
    </div>
    <div class="modal-body">
        <p class="text-secondary mb-0">
            That article is not in the archive - it may already have been restored or permanently deleted, possibly in another tab.
        </p>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
    </div>
    <?php
} else {
    require "../../../plugins/htmlpurifier/HTMLPurifier.standalone.php";

    $purifier_config = HTMLPurifier_Config::createDefault();
    $purifier_config->set('Cache.DefinitionImpl', null);
    $purifier_config->set('URI.AllowedSchemes', ['data' => true, 'src' => true, 'http' => true, 'https' => true]);
    $purifier_config->set('Attr.DefaultImageAlt', '');
    $purifier = new HTMLPurifier($purifier_config);

    $kb_article_title = nullable_htmlentities($row['kb_article_title']);
    $kb_article_content = $purifier->purify($row['kb_article_content']);
    $kb_article_content = \ITFlow\KB\MediaUrlRewriter::toAgentCanonical($kb_article_content);

    $client_id = intval($row['kb_article_client_id']);
    $client_name = $row['client_name'] !== null ? nullable_htmlentities($row['client_name']) : null;

    $archived_at_display = $row['kb_article_archived_at'] ? date('M j, Y g:i A', strtotime($row['kb_article_archived_at'])) : '';
    $updated_at = $row['kb_article_updated_at'] ?? $row['kb_article_created_at'];
    $updated_at_display = $updated_at ? date('M j, Y g:i A', strtotime($updated_at)) : '';
    ?>
    <div class="modal-header bg-dark">
        <h5 class="modal-title text-white"><i class="fas fa-fw fa-eye me-2"></i><?php echo $kb_article_title; ?></h5>
        <button type="button" class="close text-white" data-bs-dismiss="modal"><span>&times;</span></button>
    </div>
    <div class="modal-body">
        <div class="d-flex flex-wrap gap-2 mb-3">
            <?php if ($client_id === 0) { ?>
                <span class="badge text-bg-info">Central</span>
            <?php } elseif ($client_name !== null) { ?>
                <span class="badge text-bg-secondary"><?php echo $client_name; ?></span>
            <?php } else { ?>
                <span class="badge text-bg-warning">Department no longer exists</span>
            <?php } ?>
            <span class="badge text-bg-light border">Archived <?php echo $archived_at_display; ?></span>
            <?php if ($updated_at_display) { ?>
                <span class="badge text-bg-light border">Last edited <?php echo $updated_at_display; ?></span>
            <?php } ?>
        </div>

        <?php if (trim(strip_tags($kb_article_content)) === '') { ?>
            <p class="text-muted fst-italic mb-0">This article has no content.</p>
        <?php } else { ?>
            <div class="prettyContent border rounded p-3">
                <?php echo $kb_article_content; ?>
            </div>
        <?php } ?>
    </div>
    <div class="modal-footer">
        <form method="post" action="/admin/post.php" class="d-inline m-0">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="kb_article_id" value="<?php echo $kb_article_id; ?>">
            <button type="submit" name="restore_kb_article" class="btn btn-outline-success"
                    title="Restore - the article reappears exactly where it was">
                <i class="fas fa-fw fa-trash-restore me-1"></i>Restore
            </button>
        </form>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
    </div>
    <?php
}

require_once '../../../includes/modal_footer.php';
