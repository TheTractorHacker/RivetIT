<?php

// Knowledge Base - article version history (master plan Phase 5, Section 14)

if (isset($_GET['client_id'])) {
    require_once "includes/inc_all_client.php";
} else {
    require_once "includes/inc_all.php";
}

enforceUserPermission('module_kb');

$kb_article_id = intval($_GET['kb_article_id']);

$sql = mysqli_query($mysqli, "SELECT kb_article_id, kb_article_title, kb_article_client_id FROM kb_articles WHERE kb_article_id = $kb_article_id LIMIT 1");

if (mysqli_num_rows($sql) == 0) {
    echo "<center><h1 class='text-secondary mt-5'>Nothing to see here</h1><a class='btn btn-lg btn-secondary mt-3' href='javascript:history.back()'><i class='fa fa-fw fa-arrow-left'></i> Go Back</a></center>";
    require_once "../includes/footer.php";
    exit;
}

$row = mysqli_fetch_assoc($sql);
$kb_article_title = nullable_htmlentities($row['kb_article_title']);
$kb_article_client_id = intval($row['kb_article_client_id']);

if ($kb_article_client_id > 0) {
    enforceClientAccess($kb_article_client_id);
}

$kb_article_url = "kb_article.php?id=$kb_article_id";
if (isset($client_id)) {
    $kb_article_url .= "&client_id=$client_id";
}

$sql_versions = mysqli_query(
    $mysqli,
    "SELECT kb_article_versions.*, users.user_name
     FROM kb_article_versions
     LEFT JOIN users ON users.user_id = kb_article_versions.kb_article_version_edited_by
     WHERE kb_article_version_kb_article_id = $kb_article_id
     ORDER BY kb_article_version_number DESC"
);

?>

<div class="alga-theme">

    <ol class="breadcrumb d-print-none">
        <li class="breadcrumb-item">
            <a href="kb_articles.php<?php if (isset($client_id)) { echo "?client_id=$client_id"; } ?>"><i class="fas fa-fw fa-book me-1"></i>Knowledge Base</a>
        </li>
        <li class="breadcrumb-item">
            <a href="<?php echo $kb_article_url; ?>"><?php echo $kb_article_title; ?></a>
        </li>
        <li class="breadcrumb-item active">Version History</li>
    </ol>

    <div class="card">
        <div class="card-header">
            <h5 class="card-title mb-0"><i class="fas fa-fw fa-history me-2"></i>Version History: <?php echo $kb_article_title; ?></h5>
        </div>
        <div class="card-body">
            <?php if (mysqli_num_rows($sql_versions) == 0) { ?>
                <p class="text-secondary text-center py-4 mb-0">No prior versions yet - versions are created automatically the next time this article is edited.</p>
            <?php } else { ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Version</th>
                                <th>Edited By</th>
                                <th>Edited At</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($version = mysqli_fetch_assoc($sql_versions)) {
                                $kb_article_version_id = intval($version['kb_article_version_id']);
                                $kb_article_version_number = intval($version['kb_article_version_number']);
                                $kb_article_version_editor = nullable_htmlentities($version['user_name']) ?: '<span class="text-muted">Unknown</span>';
                                $kb_article_version_edited_at = nullable_htmlentities(date('M d, Y g:i A', strtotime($version['kb_article_version_edited_at'])));
                            ?>
                            <tr>
                                <td>#<?php echo $kb_article_version_number; ?></td>
                                <td><?php echo $kb_article_version_editor; ?></td>
                                <td><?php echo $kb_article_version_edited_at; ?></td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-secondary ajax-modal" data-modal-size="lg" data-modal-url="modals/kb_article/kb_article_version_view.php?id=<?php echo $kb_article_version_id; ?>">
                                        <i class="fas fa-fw fa-eye me-1"></i>View
                                    </button>
                                    <form action="post.php" method="post" class="d-inline" autocomplete="off">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <input type="hidden" name="kb_article_id" value="<?php echo $kb_article_id; ?>">
                                        <button type="submit" name="restore_kb_article_version" value="<?php echo $kb_article_version_id; ?>" class="btn btn-sm btn-outline-warning confirm-link">
                                            <i class="fas fa-fw fa-undo me-1"></i>Restore
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
    </div>

</div>

<?php
require_once "../includes/footer.php";
