<?php

require_once '../../../includes/modal_header.php';

enforceUserPermission('module_kb');

$kb_article_id = intval($_GET['id']);

$sql = mysqli_query($mysqli, "SELECT * FROM kb_articles WHERE kb_article_id = $kb_article_id LIMIT 1");
$row = mysqli_fetch_assoc($sql);

$kb_article_title = nullable_htmlentities($row['kb_article_title']);
$kb_article_content = nullable_htmlentities($row['kb_article_content']);
$kb_article_client_id = intval($row['kb_article_client_id']);
if ($kb_article_client_id) {
    enforceClientAccess($kb_article_client_id);
}
$kb_article_client_visible = intval($row['kb_article_client_visible']);
$kb_article_category_id = intval($row['kb_article_category_id'] ?? 0);

$sql_client_select = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL $access_permission_query ORDER BY client_name ASC");
$sql_category_select = mysqli_query($mysqli, "SELECT kb_category_id, kb_category_name FROM kb_categories WHERE kb_category_archived_at IS NULL ORDER BY kb_category_name ASC");

ob_start();

?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fa fa-fw fa-book me-2"></i>Edit Article: <strong><?php echo $kb_article_title; ?></strong></h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
    <input type="hidden" name="kb_article_id" value="<?php echo $kb_article_id; ?>">

    <div class="modal-body">

        <div class="form-group">
            <label>Title <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-heading"></i></span>
                </div>
                <input type="text" class="form-control" name="title" maxlength="255" value="<?php echo $kb_article_title; ?>" required autofocus>
            </div>
        </div>

        <div class="row">
            <div class="col-md-4">
                <div class="form-group">
                    <label>Client</label>
                    <select class="form-control select2" name="client_id">
                        <option value="0" <?php if ($kb_article_client_id == 0) { echo "selected"; } ?>>Central (Company-wide)</option>
                        <?php
                        while ($row = mysqli_fetch_assoc($sql_client_select)) {
                            $select_client_id = intval($row['client_id']);
                            $select_client_name = nullable_htmlentities($row['client_name']);
                        ?>
                            <option value="<?php echo $select_client_id; ?>" <?php if ($kb_article_client_id == $select_client_id) { echo "selected"; } ?>><?php echo $select_client_name; ?></option>
                        <?php } ?>
                    </select>
                    <small class="form-text text-muted">Central articles appear in every client's knowledge base. Client-specific articles are only visible to that client.</small>
                </div>
            </div>
            <div class="col-md-4">
                <div class="form-group">
                    <label>Category</label>
                    <select class="form-control select2" name="category_id">
                        <option value="0" <?php if ($kb_article_category_id == 0) { echo "selected"; } ?>>Uncategorized</option>
                        <?php while ($cat_row = mysqli_fetch_assoc($sql_category_select)) { ?>
                            <option value="<?= intval($cat_row['kb_category_id']) ?>" <?php if ($kb_article_category_id == $cat_row['kb_category_id']) { echo "selected"; } ?>><?= nullable_htmlentities($cat_row['kb_category_name']) ?></option>
                        <?php } ?>
                    </select>
                </div>
            </div>
            <div class="col-md-4">
                <div class="form-group">
                    <label>Visible to Client Portal</label>
                    <select class="form-control select2" name="client_visible">
                        <option value="1" <?php if ($kb_article_client_visible == 1) { echo "selected"; } ?>>Yes</option>
                        <option value="0" <?php if ($kb_article_client_visible == 0) { echo "selected"; } ?>>No</option>
                    </select>
                    <small class="form-text text-muted">Internal-only articles are still visible to agents, but hidden from clients.</small>
                </div>
            </div>
        </div>

        <div class="form-group">
            <label>Content <strong class="text-danger">*</strong></label>
            <textarea class="form-control tinymce tinymce-builder" name="content"><?php echo $kb_article_content; ?></textarea>
        </div>

    </div>
    <div class="modal-footer">
        <button type="submit" name="edit_kb_article" class="btn btn-primary text-bold"><i class="fa fa-check me-2"></i>Save Changes</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php

require_once '../../../includes/modal_footer.php';
