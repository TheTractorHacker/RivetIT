<?php
require_once "includes/inc_all_admin.php";
enforceUserPermission('module_admin');

/*
 * Archived Knowledge Base articles.
 *
 * settings_kb.php has always had an "Archived" stat tile (its count query is
 * repeated here unchanged) with no way to act on it - clicking "Archive" or
 * "Delete" on agent/kb_article.php has only ever done one thing,
 * kb_article_archived_at = NOW() (agent/post/kb_article.php's delete_kb_article
 * handler), and once that runs the article vanishes: agent/kb_articles.php
 * filters WHERE kb_article_archived_at IS NULL unconditionally, so there has
 * never been anywhere in the app to see it again, restore it, or actually
 * remove it. This page is that place.
 *
 * Three actions per row, all admin-only (this page sits under
 * enforceUserPermission('module_admin'), not module_kb - archiving something
 * is a day-to-day KB action; deciding what happens to the archive is not):
 *
 *   VERIFY  read-only content preview (modals/kb_article/kb_article_archive_view.php),
 *           purified the same way the live article pages are. You cannot
 *           responsibly restore or permanently delete something you have not
 *           looked at, and there was previously no way to look at all -
 *           agent/kb_article.php's own query requires archived_at IS NULL.
 *   RESTORE clears kb_article_archived_at. The article reappears exactly
 *           where it was - same id, same content, same attachments.
 *   DELETE  the hard delete that has never existed for KB articles. Removes
 *           the kb_articles row, its kb_article_attachments rows AND their
 *           files on disk under uploads/kb/<id>/, and its kb_article_versions
 *           history. Irreversible, and the confirmation says so.
 */

$sql_archived = mysqli_query(
    $mysqli,
    "SELECT kb_articles.kb_article_id, kb_articles.kb_article_title,
            kb_articles.kb_article_client_id, kb_articles.kb_article_archived_at,
            kb_articles.kb_article_updated_at,
            clients.client_name,
            (SELECT COUNT(*) FROM kb_article_attachments
              WHERE kb_article_attachments.kb_article_attachment_kb_article_id = kb_articles.kb_article_id) AS attachment_count
     FROM kb_articles
     LEFT JOIN clients ON clients.client_id = kb_articles.kb_article_client_id
     WHERE kb_articles.kb_article_archived_at IS NOT NULL
     ORDER BY kb_articles.kb_article_archived_at DESC"
);

$archived_count = $sql_archived ? mysqli_num_rows($sql_archived) : 0;

?>

<div class="card">
    <div class="card-header py-2 d-flex align-items-center">
        <h3 class="card-title me-auto"><i class="fas fa-fw fa-archive me-2"></i>Archived Knowledge Base Articles</h3>
        <a href="/admin/settings_kb.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-fw fa-arrow-left me-1"></i>Back to Knowledge Base Settings
        </a>
    </div>

    <div class="card-body">
        <p class="text-secondary mb-0">
            An archived article is not deleted - it is hidden from the Knowledge Base everywhere
            (the agent list, the department portal, search, the API) but its content, attachments
            and version history are all still on disk until you remove it here. Restoring puts it
            straight back where it was; deleting is permanent.
        </p>
    </div>

    <?php if ($archived_count === 0) { ?>
        <div class="card-body pt-0">
            <div class="text-center text-muted py-4">
                <i class="fas fa-box-open fa-2x d-block mb-3 opacity-50"></i>
                <p class="mb-0">Nothing archived. Archived articles will appear here.</p>
            </div>
        </div>
    <?php } else { ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Article</th>
                        <th>Scope</th>
                        <th>Attachments</th>
                        <th>Archived</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($row = mysqli_fetch_assoc($sql_archived)) {
                    $kb_article_id     = intval($row['kb_article_id']);
                    $kb_article_title  = nullable_htmlentities($row['kb_article_title']);
                    $client_id         = intval($row['kb_article_client_id']);
                    $client_name       = $row['client_name'] !== null ? nullable_htmlentities($row['client_name']) : null;
                    $attachment_count  = intval($row['attachment_count']);
                    $archived_at       = $row['kb_article_archived_at'];
                    $archived_at_display = $archived_at ? date('M j, Y g:i A', strtotime($archived_at)) : '';
                    $archived_ago      = $archived_at ? timeAgo($archived_at) : '';
                ?>
                    <tr>
                        <td>
                            <div class="fw-medium"><?php echo $kb_article_title; ?></div>
                            <div class="text-muted small">Article #<?php echo $kb_article_id; ?></div>
                        </td>
                        <td>
                            <?php if ($client_id === 0) { ?>
                                <span class="badge text-bg-info">Central</span>
                            <?php } elseif ($client_name !== null) { ?>
                                <span class="badge text-bg-secondary"><?php echo $client_name; ?></span>
                            <?php } else { ?>
                                <span class="badge text-bg-warning" data-bs-toggle="tooltip" title="The department this article was scoped to no longer exists">Orphaned</span>
                            <?php } ?>
                        </td>
                        <td>
                            <?php if ($attachment_count > 0) { ?>
                                <i class="fas fa-fw fa-paperclip text-muted me-1"></i><?php echo $attachment_count; ?>
                            <?php } else { ?>
                                <span class="text-muted">&mdash;</span>
                            <?php } ?>
                        </td>
                        <td>
                            <span data-bs-toggle="tooltip" title="<?php echo $archived_at_display; ?>"><?php echo $archived_ago; ?></span>
                        </td>
                        <td class="text-end text-nowrap">
                            <a class="btn btn-sm btn-outline-secondary ajax-modal" href="#"
                               data-modal-url="modals/kb_article/kb_article_archive_view.php?id=<?php echo $kb_article_id; ?>"
                               title="Preview the article's content before deciding">
                                <i class="fas fa-fw fa-eye me-1"></i>Verify
                            </a>
                            <form method="post" action="post.php" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <input type="hidden" name="kb_article_id" value="<?php echo $kb_article_id; ?>">
                                <button type="submit" name="restore_kb_article" class="btn btn-sm btn-outline-success confirm-link"
                                        title="Restore - the article reappears exactly where it was">
                                    <i class="fas fa-fw fa-trash-restore me-1"></i>Restore
                                </button>
                            </form>
                            <form method="post" action="post.php" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <input type="hidden" name="kb_article_id" value="<?php echo $kb_article_id; ?>">
                                <button type="submit" name="delete_kb_article_permanently" class="btn btn-sm btn-outline-danger confirm-link"
                                        title="Permanently delete<?php if ($attachment_count > 0) { echo " - $attachment_count attachment" . ($attachment_count == 1 ? '' : 's') . " go with it"; } ?> - this cannot be undone">
                                    <i class="fas fa-fw fa-trash-alt me-1"></i>Delete
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    <?php } ?>
</div>

<?php
require_once "../includes/footer.php";
