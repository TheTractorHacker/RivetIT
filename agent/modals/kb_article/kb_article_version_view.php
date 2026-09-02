<?php

require_once '../../../includes/modal_header.php';

enforceUserPermission('module_kb');

// Initialize the HTML Purifier to prevent XSS
require_once "../../../plugins/htmlpurifier/HTMLPurifier.standalone.php";

$purifier_config = HTMLPurifier_Config::createDefault();
$purifier_config->set('Cache.DefinitionImpl', null);
$purifier_config->set('URI.AllowedSchemes', ['data' => true, 'src' => true, 'http' => true, 'https' => true]);
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
    <div class="prettyContent">
        <?php echo $kb_article_version_content; ?>
    </div>
</div>

<script src="../js/pretty_content.js"></script>

<?php
require_once '../../../includes/modal_footer.php';
