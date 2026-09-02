<?php

require_once '../../../includes/modal_header.php';

enforceUserPermission('module_kb');

$kb_article_id = intval($_GET['id']);

$sql = mysqli_query($mysqli, "SELECT kb_article_title, kb_article_client_id, kb_article_review_due_at, kb_article_reviewer_user_id FROM kb_articles WHERE kb_article_id = $kb_article_id LIMIT 1");
$row = mysqli_fetch_assoc($sql);

$kb_article_client_id = intval($row['kb_article_client_id']);
if ($kb_article_client_id) {
    enforceClientAccess($kb_article_client_id);
}

$kb_article_title = nullable_htmlentities($row['kb_article_title']);
$kb_article_review_due_at = nullable_htmlentities($row['kb_article_review_due_at']);
$kb_article_reviewer_user_id = intval($row['kb_article_reviewer_user_id'] ?? 0);

$sql_users = mysqli_query($mysqli, "SELECT user_id, user_name FROM users WHERE user_status = 1 AND user_archived_at IS NULL ORDER BY user_name ASC");

ob_start();
?>
<div class="modal-header bg-dark">
    <h5 class="modal-title text-white"><i class="fa fa-fw fa-calendar-check me-2"></i>Review Schedule: <strong><?php echo $kb_article_title; ?></strong></h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
    <input type="hidden" name="kb_article_id" value="<?php echo $kb_article_id; ?>">

    <div class="modal-body">

        <div class="form-group">
            <label>Review Due Date</label>
            <input type="date" class="form-control" name="review_due_at" max="2999-12-31" value="<?php echo $kb_article_review_due_at; ?>">
            <small class="form-text text-muted">Once this date passes, the article is flagged "Needs Review" on the knowledge base list. Leave blank for no schedule.</small>
        </div>

        <div class="form-group">
            <label>Reviewer</label>
            <select class="form-control select2" name="reviewer_user_id" data-placeholder="Unassigned">
                <option value="0">- Unassigned -</option>
                <?php while ($user_row = mysqli_fetch_assoc($sql_users)) { ?>
                    <option value="<?= intval($user_row['user_id']) ?>" <?php if ($kb_article_reviewer_user_id == $user_row['user_id']) { echo "selected"; } ?>><?= nullable_htmlentities($user_row['user_name']) ?></option>
                <?php } ?>
            </select>
        </div>

    </div>
    <div class="modal-footer">
        <button type="submit" name="set_kb_article_review" class="btn btn-primary text-bold"><i class="fa fa-check me-2"></i>Save</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php
require_once '../../../includes/modal_footer.php';
