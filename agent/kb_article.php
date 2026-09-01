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
$purifier = new HTMLPurifier($purifier_config);

$kb_article_id = intval($_GET['id']);

$sql = mysqli_query(
    $mysqli,
    "SELECT kb_articles.*, clients.client_name
     FROM kb_articles
     LEFT JOIN clients ON clients.client_id = kb_articles.kb_article_client_id
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
$kb_article_client_id = intval($row['kb_article_client_id']);
$kb_article_client_name = nullable_htmlentities($row['client_name']);
$kb_article_client_visible = intval($row['kb_article_client_visible']);
$kb_article_updated_at = $row['kb_article_updated_at'] ?? $row['kb_article_created_at'];
$kb_article_archived_at = $row['kb_article_archived_at'];

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
                        <strong>Client Portal</strong><br>
                        <?php if ($kb_article_client_visible == 1) { ?>
                            <span class="badge text-bg-success">Visible</span>
                        <?php } else { ?>
                            <span class="badge text-bg-secondary">Hidden</span>
                        <?php } ?>
                    </p>
                    <p class="mb-0">
                        <strong>Last Updated</strong><br>
                        <?php echo nullable_htmlentities(date('M d, Y g:i A', strtotime($kb_article_updated_at))); ?>
                    </p>
                </div>
                <div class="card-footer">
                    <button type="button" class="btn btn-primary btn-block ajax-modal mb-2" data-modal-size="lg" data-modal-url="modals/kb_article/kb_article_edit.php?id=<?php echo $kb_article_id; ?>">
                        <i class="fas fa-fw fa-edit me-2"></i>Edit
                    </button>
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
                        $att_ref = $att['kb_article_attachment_reference_name'];
                    ?>
                    <li class="list-group-item d-flex align-items-center justify-content-between">
                        <span class="text-truncate me-2"><i class="fas fa-fw fa-file me-1"></i><?php echo $att_name; ?></span>
                        <div class="dropdown dropleft text-center">
                            <button class="btn btn-secondary btn-sm" type="button" data-bs-toggle="dropdown" data-boundary="window">
                                <i class="fas fa-fw fa-ellipsis-v"></i>
                            </button>
                            <div class="dropdown-menu">
                                <a target="_blank" class="dropdown-item" href="../uploads/kb/<?php echo $kb_article_id; ?>/<?php echo $att_ref; ?>">
                                    <i class="fas fa-fw fa-eye me-2"></i>View
                                </a>
                                <a class="dropdown-item" download="<?php echo $att_name; ?>" href="../uploads/kb/<?php echo $kb_article_id; ?>/<?php echo $att_ref; ?>">
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
