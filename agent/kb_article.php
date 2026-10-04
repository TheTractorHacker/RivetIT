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
/* INTERACTIVE KB BLOCKS. Registers the data-ikb vocabulary (checklist, guided
 * steps, tabs, accordion, decision tree, copy marker, sandboxed embed) so this
 * renderer keeps it instead of flattening it to prose. MUST be above the
 * constructor: HTMLPurifier refuses a raw definition once a config has been
 * used, and \ITFlow\KB\InteractiveBlocks::apply() turns that into a
 * LogicException naming the fix rather than silently skipping registration.
 *
 * All FOUR KB RENDER sites call it - here, client/kb_article.php,
 * agent/modals/kb_article/kb_article_version_view.php and api/v1/kb.php - and
 * those four are the complete set of places that turn stored article HTML
 * into a page a reader sees (grep -rn "purify(" over the tree, excluding
 * plugins/, then read off which results are display and which are storage).
 * Miss one of these four and that renderer shows a readable document with
 * every block's interactivity gone and no error anywhere. THERE IS A FIFTH
 * purify() call, agent/post/kb_article.php:811 in the HTML-import path - it
 * purifies once at STORE time so imported markup is safe before it is even
 * written to kb_article_content, and every render site purifies again on its
 * own read, so missing it would not un-render anything that already renders
 * today. It calls InteractiveBlocks::apply() on its own purifier config too
 * (it must: a config that forgot it would let the importer strip every
 * interactive block it just built out of the stored HTML), so this is a fifth
 * call site, not a fifth thing to remember. Adds attributes only;
 * measured byte-identical output on ordinary article content with and without
 * it, so it composes with the media rewriting below by construction. */
\ITFlow\KB\InteractiveBlocks::apply($purifier_config);
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
$kb_article_training_visible = intval($row['kb_article_training_visible'] ?? 0);
$kb_article_updated_at = $row['kb_article_updated_at'] ?? $row['kb_article_created_at'];
$kb_article_archived_at = $row['kb_article_archived_at'];
$kb_article_review_due_at = $row['kb_article_review_due_at'] ?? null;
$kb_article_review_due_at_display = $kb_article_review_due_at ? nullable_htmlentities(date('M d, Y', strtotime($kb_article_review_due_at))) : null;
$kb_article_reviewer_name = nullable_htmlentities($row['kb_article_reviewer_name']);
$kb_article_needs_review = $kb_article_review_due_at && strtotime($kb_article_review_due_at) < strtotime('today');

if ($kb_article_client_id > 0) {
    enforceClientAccess($kb_article_client_id);
}

/* THE READER'S SAVED PROGRESS, for the render root below.
 *
 * LOADED THROUGH THE PROGRESS WORK STREAM'S OWN STORE, not through a query
 * written here. agent/includes/kb_progress_store.php holds the schema, the key
 * grammar, the caps and the SQL exactly once precisely so that a second
 * statement of them cannot drift; kbProgressLoad() states plainly that it does
 * NO authorization and that every caller must have settled "may this principal
 * read this article" before calling it. This page has:
 * enforceUserPermission('module_kb') at the top of the file, and
 * enforceClientAccess() on the article's department above.
 *
 * THE PRINCIPAL IS A PAIR - ('u', users.user_id) here, ('c',
 * contacts.contact_id) on the portal. A department contact is not a users row
 * and the two id spaces overlap numerically, so the type character is what
 * keeps agent 7's ticks apart from contact 7's.
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
 * dropped.
 *
 * data-ikb-hashes IS COMPUTED UNCONDITIONALLY, NOT GATED ON $ikb_progress
 * BEING NON-EMPTY. An earlier version of this line read
 * `$ikb_progress === [] ? '{}' : hashesAttribute(...)` on the reasoning that
 * an empty progress map has nothing to compare against, so the hashes were
 * pointless to compute - but that reasoning is backwards: a reader's FIRST
 * visit is exactly when $ikb_progress is empty, and it is also exactly the
 * visit on which every tick they make that session gets saved with NO current
 * hash to compare against later (currentHash() in js/kb_interactive.js reads
 * data-ikb-hashes to build the "h" it sends on every save). Gating the hashes
 * on progress already existing meant the FIRST tick of every checklist was
 * permanently un-stale-checkable - reproduced end to end: a row saved that
 * way carries no part_hash, and readState()'s `stored !== ''` test can then
 * never be true for it again, on any later visit, even after the words behind
 * it change. hashesAttribute() already short-circuits to '{}' with no DOM
 * parse on an article with no blocks (partHashes() returns early on
 * !contains($html)), so there was never a real cost being saved here - only
 * the feature.
 *
 * PRIVACY: this puts ONE reader's state in the page body, which is safe only
 * because article pages are not served from a shared cache. */
$ikb_progress = [];
$ikb_progress_store = $_SERVER['DOCUMENT_ROOT'] . '/agent/includes/kb_progress_store.php';
if ($session_user_id > 0 && is_file($ikb_progress_store)) {
    if (!defined('FROM_KB_PROGRESS')) {
        define('FROM_KB_PROGRESS', true);
    }
    require_once $ikb_progress_store;
    if (function_exists('kbProgressLoad')) {
        $ikb_progress = kbProgressLoad($mysqli, $kb_article_id, 'u', $session_user_id);
    }
}
$ikb_progress_json = \ITFlow\KB\InteractiveBlocks::progressAttribute($ikb_progress);
$ikb_hashes_json = \ITFlow\KB\InteractiveBlocks::hashesAttribute($kb_article_content);

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
                <?php /*
                    THE INTERACTIVE-BLOCK RENDER ROOT.

                    These data attributes are the ONLY channel between PHP and
                    js/kb_interactive.js. Not an inline <script> carrying JSON:
                    the portal shell sends "default-src 'self'" with no nonce, so
                    an inline script does not run there at all, and one file that
                    behaves identically on both shells is worth more than a
                    per-lane special case. Not a .js config endpoint either -
                    that is XSSI-shaped, and any third-party page could
                    <script src> it to read a reader's progress. js/live_ticket.js
                    already reads its ticket id and CSRF token exactly this way.

                    This wrapper is PAGE CHROME, outside the purified string, so
                    it is not subject to the vocabulary's own attribute grammar.

                    data-ikb-endpoint is RELATIVE, so this page can only ever
                    reach /agent/kb_progress.php and the portal page only ever
                    /client/kb_progress.php. Those two endpoints answer the same
                    request shape with completely different authorization
                    questions - module_kb plus department scope here, the
                    portal's article-visibility clause there - which is the split
                    agent/kb_media.php and client/kb_media.php already make.
                    kbProgressParseItems() in agent/includes/kb_progress_store.php
                    is the definition of the request body js/kb_interactive.js
                    sends.

                    data-ikb-readonly reflects whether the article is archived:
                    agent/kb_progress.php correctly refuses a write when
                    kb_article_archived_at IS NOT NULL (a 404, not a silent
                    no-op), but the render layer still shipped live, clickable
                    tick-boxes on an archived runbook until this echoed the
                    actual state instead of a hard-coded "0" - an agent had no
                    visual signal that ticking a box on an archived article
                    would be refused. Session permission
                    (enforceUserPermission('module_kb')/enforceClientAccess())
                    is a separate axis from this; it gates whether the page
                    loads at all, not whether ITS content is still writable.
                */ ?>
                <div class="card-body prettyContent"
                     data-ikb-root
                     data-ikb-version="<?php echo \ITFlow\KB\InteractiveBlocks::VERSION; ?>"
                     data-ikb-article="<?php echo $kb_article_id; ?>"
                     data-ikb-readonly="<?php echo !empty($kb_article_archived_at) ? '1' : '0'; ?>"
                     data-ikb-endpoint="kb_progress.php"
                     data-ikb-csrf="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES); ?>"
                     data-ikb-progress="<?php echo htmlspecialchars($ikb_progress_json, ENT_QUOTES); ?>"
                     data-ikb-hashes="<?php echo htmlspecialchars($ikb_hashes_json, ENT_QUOTES); ?>">
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
                    <?php if (($config_module_enable_training ?? 0) == 1) { ?>
                    <p class="mb-2">
                        <strong>Training Portal</strong><br>
                        <?php if ($kb_article_training_visible == 1) { ?>
                            <span class="badge text-bg-success">Shown</span>
                        <?php } else { ?>
                            <span class="badge text-bg-secondary">Hidden</span>
                        <?php } ?>
                    </p>
                    <?php } ?>
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
                    <?php if (lookupUserPermission('module_kb') >= 3) { ?>
                    <a class="btn btn-danger btn-block confirm-link" href="post.php?delete_kb_article=<?php echo $kb_article_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>">
                        <i class="fas fa-fw fa-trash-alt me-2"></i>Delete
                    </a>
                    <?php } ?>
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

<?php /*
    The render layer, loaded from the page rather than from the shared footer
    loop, because only this page and the version-history modal have a render
    root and the file is inert without one. defer, so it runs after the document
    is parsed - all its work is event-driven. Cache-busted by filemtime, the same
    idiom includes/footer.php uses for its own first-party assets.
*/ ?>
<script src="/js/kb_interactive.js?v=<?php echo filemtime($_SERVER['DOCUMENT_ROOT'] . '/js/kb_interactive.js'); ?>" defer></script>

<?php
require_once "../includes/footer.php";
