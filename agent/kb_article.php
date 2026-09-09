<?php

if (isset($_GET['client_id'])) {
    require_once "includes/inc_all_client.php";
} else {
    require_once "includes/inc_all.php";
}

enforceUserPermission('module_kb');

// Initialize the HTML Purifier to prevent XSS
require "../plugins/htmlpurifier/HTMLPurifier.standalone.php";

$purifier_config = HTMLPurifier_Config::createDefault();
$purifier_config->set('Cache.DefinitionImpl', null);
$purifier_config->set('URI.AllowedSchemes', ['data' => true, 'src' => true, 'http' => true, 'https' => true]);
/* KB media URLs carry their parameters in a query string, and HTMLPurifier
 * fills a MISSING alt attribute from the src basename. Measured on the bundled
 * 4.15.0: <img src="/agent/kb_media.php?a=13&f=x.png"> with no alt purifies to
 * alt="kb_media.php?a=13&amp;f=x.png", and on the API side - where the same URL
 * carries &p=&e=&s= - the whole capability token would land in the alt, visible
 * on a broken image and captured by select-all-copy. '' yields alt="" instead.
 * An alt the author actually wrote is untouched either way. Set in all four KB
 * purifier configs (here, client/kb_article.php, the version-history modal and
 * api/v1/kb.php) so the behaviour cannot differ between renderers. */
$purifier_config->set('Attr.DefaultImageAlt', '');
$purifier = new HTMLPurifier($purifier_config);

$kb_article_id = intval($_GET['id']);

$sql = mysqli_query(
    $mysqli,
    "SELECT kb_articles.*, clients.client_name, reviewer.user_name AS kb_article_reviewer_name
     FROM kb_articles
     LEFT JOIN clients ON clients.client_id = kb_articles.kb_article_client_id
     LEFT JOIN users reviewer ON reviewer.user_id = kb_articles.kb_article_reviewer_user_id
     WHERE kb_article_id = $kb_article_id
     LIMIT 1"
);

if (mysqli_num_rows($sql) == 0) {
    echo "<center><h1 class='text-secondary mt-5'>Nothing to see here</h1><a class='btn btn-lg btn-secondary mt-3' href='javascript:history.back()'><i class='fa fa-fw fa-arrow-left'></i> Go Back</a></center>";
    require_once "../includes/footer.php";
    exit;
}

$row = mysqli_fetch_assoc($sql);

$kb_article_title = nullable_htmlentities($row['kb_article_title']);
$kb_article_content = $purifier->purify($row['kb_article_content']);
/* Normalise any PRE-MIGRATION media path in the stored HTML to the canonical
 * authenticated URL before display. New content never needs this: the DOCX
 * importer and the TinyMCE uploader both write /agent/kb_media.php?... now, and
 * for those this call is a no-op (verified: toAgentCanonical() is idempotent
 * and leaves an already-canonical src byte-identical). It exists for the rows
 * written before that change - measured on the live database 2026-09-08, 3
 * kb_articles rows (15 URLs) and 2 kb_article_versions rows (9 URLs) still hold
 * raw /uploads/kb/<id>/<file> paths. Those work today only because /uploads is
 * served without authentication, which is the hole this whole change closes;
 * once nginx denies it they would be broken images on this page.
 *
 * BELT, WITH BRACES ELSEWHERE. Storage itself is fixed by the 2.6.77 -> 2.6.78
 * database update, which rewrites those same rows in place (verified against a
 * full copy of the live tables: 3 + 2 rows migrated, 0 legacy paths left, and
 * a re-run left the content hash unchanged). This call stays anyway - it costs
 * 0.06 ms on content with no media because the rewriter short-circuits on
 * containsMedia() - and it is what covers a row restored from a pre-migration
 * backup.
 *
 * RENDER-TIME ONLY, deliberately. The edit modal loads the RAW stored HTML into
 * TinyMCE on purpose; see the note at the top of
 * agent/modals/kb_article/kb_article_edit.php for why normalising there would
 * be a storage change wearing a render change's clothes. The migration is what
 * fixes storage. */
$kb_article_content = \ITFlow\KB\MediaUrlRewriter::toAgentCanonical($kb_article_content);
$kb_article_content = (new \ITFlow\Knowledge\CredentialReferenceRenderer())->render($kb_article_content);
$kb_article_client_id = intval($row['kb_article_client_id']);
$kb_article_client_name = nullable_htmlentities($row['client_name']);
$kb_article_client_visible = intval($row['kb_article_client_visible']);
$kb_article_updated_at = $row['kb_article_updated_at'] ?? $row['kb_article_created_at'];
$kb_article_archived_at = $row['kb_article_archived_at'];
$kb_article_review_due_at = $row['kb_article_review_due_at'] ?? null;
$kb_article_review_due_at_display = $kb_article_review_due_at ? nullable_htmlentities(date('M d, Y', strtotime($kb_article_review_due_at))) : null;
$kb_article_reviewer_name = nullable_htmlentities($row['kb_article_reviewer_name']);
$kb_article_needs_review = $kb_article_review_due_at && strtotime($kb_article_review_due_at) < strtotime('today');

if ($kb_article_client_id > 0) {
    enforceClientAccess($kb_article_client_id);
}

$kb_articles_url = "kb_articles.php";
if (isset($client_id)) {
    $kb_articles_url .= "?client_id=$client_id";
}

$sql_attachments = mysqli_query(
    $mysqli,
    "SELECT * FROM kb_article_attachments WHERE kb_article_attachment_kb_article_id = $kb_article_id ORDER BY kb_article_attachment_created_at ASC"
);

?>

<div class="alga-theme">

    <ol class="breadcrumb d-print-none">
        <li class="breadcrumb-item">
            <a href="<?php echo $kb_articles_url; ?>"><i class="fas fa-fw fa-book me-1"></i>Knowledge Base</a>
        </li>
        <li class="breadcrumb-item active">
            <?php echo $kb_article_title; ?>
            <?php if (!empty($kb_article_archived_at)) { ?>
                <span class="text-danger ms-2">(Archived)</span>
            <?php } ?>
            <?php if ($kb_article_needs_review) { ?>
                <span class="badge text-bg-warning ms-2" data-bs-toggle="tooltip" title="Review was due <?php echo $kb_article_review_due_at_display; ?>"><i class="fas fa-fw fa-exclamation-triangle"></i> Needs Review</span>
            <?php } ?>
        </li>
    </ol>

    <div class="row">

        <div class="col-md-9">
            <div class="card">
                <div class="card-header">
                    <div class="h4 mb-0"><?php echo $kb_article_title; ?></div>
                </div>
                <div class="card-body prettyContent">
                    <?php echo $kb_article_content; ?>
                </div>
            </div>
        </div>

        <div class="col-md-3 d-print-none">
            <div class="card card-sidebar">
                <div class="card-header">
                    <h5 class="card-title mb-0"><i class="fas fa-fw fa-info-circle me-2"></i>Details</h5>
                </div>
                <div class="card-body">
                    <p class="mb-2">
                        <strong>Scope</strong><br>
                        <?php if ($kb_article_client_id == 0) { ?>
                            <span class="badge text-bg-info">Central (Company-wide)</span>
                        <?php } else { ?>
                            <a href="client_overview.php?client_id=<?php echo $kb_article_client_id; ?>"><?php echo $kb_article_client_name; ?></a>
                        <?php } ?>
                    </p>
                    <p class="mb-2">
                        <strong>Department Portal</strong><br>
                        <?php if ($kb_article_client_visible == 1) { ?>
                            <span class="badge text-bg-success">Visible</span>
                        <?php } else { ?>
                            <span class="badge text-bg-secondary">Hidden</span>
                        <?php } ?>
                    </p>
                    <p class="mb-2">
                        <strong>Last Updated</strong><br>
                        <?php echo nullable_htmlentities(date('M d, Y g:i A', strtotime($kb_article_updated_at))); ?>
                    </p>
                    <p class="mb-0">
                        <strong>Review Schedule</strong>
                        <a href="#" class="ajax-modal ms-1" data-modal-url="modals/kb_article/kb_article_review_edit.php?id=<?php echo $kb_article_id; ?>" title="Edit review schedule"><i class="fas fa-fw fa-edit"></i></a>
                        <br>
                        <?php if ($kb_article_review_due_at_display) { ?>
                            <span class="<?php echo $kb_article_needs_review ? 'text-danger' : ''; ?>"><?php echo $kb_article_review_due_at_display; ?></span>
                        <?php } else { ?>
                            <span class="text-muted">No review scheduled</span>
                        <?php } ?>
                        <?php if ($kb_article_reviewer_name) { ?>
                            <br><span class="text-secondary small">Reviewer: <?php echo $kb_article_reviewer_name; ?></span>
                        <?php } ?>
                    </p>
                </div>
                <div class="card-footer">
                    <button type="button" class="btn btn-primary btn-block ajax-modal mb-2" data-modal-size="lg" data-modal-url="modals/kb_article/kb_article_edit.php?id=<?php echo $kb_article_id; ?>">
                        <i class="fas fa-fw fa-edit me-2"></i>Edit
                    </button>
                    <a class="btn btn-secondary btn-block mb-2" href="kb_article_versions.php?kb_article_id=<?php echo $kb_article_id; ?><?php if (isset($client_id)) { echo "&client_id=$client_id"; } ?>">
                        <i class="fas fa-fw fa-history me-2"></i>Version History
                    </a>
                    <a class="btn btn-danger btn-block confirm-link" href="post.php?delete_kb_article=<?php echo $kb_article_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>">
                        <i class="fas fa-fw fa-trash-alt me-2"></i>Delete
                    </a>
                </div>
            </div>

            <div class="card card-sidebar mt-3 d-print-none">
                <div class="card-header">
                    <h5 class="card-title mb-0"><i class="fas fa-fw fa-paperclip me-2"></i>Attachments</h5>
                </div>
                <?php if (mysqli_num_rows($sql_attachments) > 0) { ?>
                <ul class="list-group list-group-flush">
                    <?php while ($att = mysqli_fetch_assoc($sql_attachments)) {
                        $att_id = intval($att['kb_article_attachment_id']);
                        $att_name = nullable_htmlentities($att['kb_article_attachment_name']);
                    ?>
                    <li class="list-group-item d-flex align-items-center justify-content-between">
                        <span class="text-truncate me-2"><i class="fas fa-fw fa-file me-1"></i><?php echo $att_name; ?></span>
                        <div class="dropdown dropleft text-center">
                            <button class="btn btn-secondary btn-sm" type="button" data-bs-toggle="dropdown" data-boundary="window">
                                <i class="fas fa-fw fa-ellipsis-v"></i>
                            </button>
                            <div class="dropdown-menu">
                                <?php /*
                                     Both links go through kb_article_attachment.php, never the raw
                                     /uploads/kb/ path. That path is unauthenticated, and its filename
                                     is md5(contents) + 2 random chars - guessable by anyone who
                                     already holds the same document. It also carries a blanket
                                     Content-Disposition: attachment from nginx, so "View" there could
                                     only ever download. The endpoint authenticates, re-checks the
                                     article's department scope, and decides inline vs attachment from
                                     the file's real bytes.
                                */ ?>
                                <a target="_blank" rel="noopener" class="dropdown-item" href="kb_article_attachment.php?id=<?php echo $att_id; ?>">
                                    <i class="fas fa-fw fa-eye me-2"></i>View
                                </a>
                                <a class="dropdown-item" download="<?php echo $att_name; ?>" href="kb_article_attachment.php?id=<?php echo $att_id; ?>&amp;download=1">
                                    <i class="fas fa-fw fa-download me-2"></i>Download
                                </a>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item text-danger confirm-link" href="post.php?delete_kb_article_attachment=<?php echo $att_id; ?>&kb_article_id=<?php echo $kb_article_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>">
                                    <i class="fas fa-fw fa-trash me-2"></i>Delete
                                </a>
                            </div>
                        </div>
                    </li>
                    <?php } ?>
                </ul>
                <?php } ?>
                <div class="card-footer">
                    <button type="button" class="btn btn-secondary btn-block ajax-modal" data-modal-url="modals/kb_article/kb_article_attachment_add.php?kb_article_id=<?php echo $kb_article_id; ?>">
                        <i class="fas fa-fw fa-upload me-2"></i>Upload Attachment
                    </button>
                </div>
            </div>
        </div>

    </div>

</div>

<?php
require_once "../includes/footer.php";
