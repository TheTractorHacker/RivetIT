<?php

// Default Column Sortby Filter
$sort = "kb_article_title";
$order = "ASC";

// If client_id is in URI then show client Side Bar and client header
if (isset($_GET['client_id'])) {
    require_once "includes/inc_all_client.php";
    $kb_scope_query = "AND kb_article_client_id IN (0, $client_id)";
} else {
    require_once "includes/inc_all.php";

    if (isset($_GET['filter_client_id']) && $_GET['filter_client_id'] !== '') {
        $filter_client_id = intval($_GET['filter_client_id']);
        $kb_scope_query = "AND kb_article_client_id = $filter_client_id";
    } else {
        $filter_client_id = '';
        $kb_scope_query = '';
    }
}

enforceUserPermission('module_kb');

if (isset($_GET['filter_category_id']) && $_GET['filter_category_id'] !== '') {
    $filter_category_id = intval($_GET['filter_category_id']);
    $kb_category_filter_query = "AND kb_article_category_id = $filter_category_id";
} else {
    $filter_category_id = '';
    $kb_category_filter_query = '';
}

if ($q) {
    $q_escaped = mysqli_real_escape_string($mysqli, $q);
    $kb_search_query = "AND (kb_article_title LIKE '%$q%' OR MATCH(kb_article_content_raw) AGAINST ('$q_escaped'))";
} else {
    $kb_search_query = "AND kb_article_title LIKE '%$q%'";
}

$sql = mysqli_query(
    $mysqli,
    "SELECT SQL_CALC_FOUND_ROWS kb_articles.*, clients.client_name, kb_categories.kb_category_name
     FROM kb_articles
     LEFT JOIN clients ON clients.client_id = kb_articles.kb_article_client_id
     LEFT JOIN kb_categories ON kb_categories.kb_category_id = kb_articles.kb_article_category_id
     WHERE 1=1
     $kb_search_query
     AND kb_article_archived_at IS NULL
     $kb_scope_query
     $kb_category_filter_query
     ORDER BY kb_category_name IS NULL, kb_category_name ASC, kb_article_title ASC
     LIMIT $record_from, $record_to"
);

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

if (!isset($client_id)) {
    $sql_client_select = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL $access_permission_query ORDER BY client_name ASC");
}

$sql_category_filter_select = mysqli_query($mysqli, "SELECT kb_category_id, kb_category_name FROM kb_categories WHERE kb_category_archived_at IS NULL ORDER BY kb_category_name ASC");

// Group articles by category for the card grid
$kb_groups = [];
while ($row = mysqli_fetch_assoc($sql)) {
    $group_name = $row['kb_category_name'] ?? 'Uncategorized';
    $kb_groups[$group_name][] = $row;
}
// "Uncategorized" group always last
if (isset($kb_groups['Uncategorized'])) {
    $uncategorized = $kb_groups['Uncategorized'];
    unset($kb_groups['Uncategorized']);
    $kb_groups['Uncategorized'] = $uncategorized;
}

?>

<div class="alga-theme">

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-book me-2"></i>Knowledge Base</h3>
        <div class="card-tools">
            <button type="button" class="btn btn-secondary ajax-modal" data-modal-url="modals/kb_category/kb_category_manage.php">
                <i class="fas fa-folder me-2"></i>Categories
            </button>
            <button type="button" class="btn btn-primary ajax-modal" data-modal-size="lg" data-modal-url="modals/kb_article/kb_article_add.php<?php if (isset($client_id)) { echo "?client_id=$client_id"; } ?>">
                <i class="fas fa-plus me-2"></i>New Article
            </button>
        </div>
    </div>
    <div class="card-body">

        <form autocomplete="off" class="filter-bar">
            <?php if (isset($client_id)) { ?>
                <input type="hidden" name="client_id" value="<?php echo $client_id; ?>">
            <?php } ?>
            <div class="row align-items-center">
                <div class="col-auto mb-2">
                    <div class="input-group">
                        <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search Knowledge Base">
                        <div class="input-group-append">
                            <button class="btn btn-dark"><i class="fa fa-search"></i></button>
                        </div>
                    </div>
                </div>
                <div class="col-auto mb-2">
                    <select class="form-control select2 auto-submit-select" name="filter_category_id" data-placeholder="All Categories" style="width: 200px">
                        <option value="">- All Categories -</option>
                        <option value="0" <?php if ($filter_category_id === 0) { echo "selected"; } ?>>Uncategorized</option>
                        <?php
                        while ($row = mysqli_fetch_assoc($sql_category_filter_select)) {
                            $select_category_id = intval($row['kb_category_id']);
                            $select_category_name = nullable_htmlentities($row['kb_category_name']);
                        ?>
                            <option value="<?php echo $select_category_id; ?>" <?php if ($filter_category_id === $select_category_id) { echo "selected"; } ?>><?php echo $select_category_name; ?></option>
                        <?php } ?>
                    </select>
                </div>
                <?php if (!isset($client_id)) { ?>
                    <div class="col-auto mb-2">
                        <select class="form-control select2 auto-submit-select" name="filter_client_id" data-placeholder="All Articles" style="width: 220px">
                            <option value="">- All Articles -</option>
                            <option value="0" <?php if ($filter_client_id === 0) { echo "selected"; } ?>>Central (Company-wide)</option>
                            <?php
                            while ($row = mysqli_fetch_assoc($sql_client_select)) {
                                $select_client_id = intval($row['client_id']);
                                $select_client_name = nullable_htmlentities($row['client_name']);
                            ?>
                                <option value="<?php echo $select_client_id; ?>" <?php if ($filter_client_id === $select_client_id) { echo "selected"; } ?>><?php echo $select_client_name; ?></option>
                            <?php } ?>
                        </select>
                    </div>
                <?php } ?>
            </div>
        </form>

        <hr>

        <?php if ($num_rows[0] == 0) { ?>
            <p class="text-secondary text-center py-4">No articles found.</p>
        <?php } ?>

        <?php foreach ($kb_groups as $group_name => $articles) { ?>
            <h5 class="mt-3 mb-3"><i class="fas fa-fw fa-folder text-secondary me-2"></i><?= nullable_htmlentities($group_name) ?></h5>
            <div class="row">
                <?php foreach ($articles as $row) {
                    $kb_article_id = intval($row['kb_article_id']);
                    $kb_article_title = nullable_htmlentities($row['kb_article_title']);
                    $kb_article_client_id = intval($row['kb_article_client_id']);
                    $kb_article_client_name = nullable_htmlentities($row['client_name']);
                    $kb_article_client_visible = intval($row['kb_article_client_visible']);
                    $kb_article_updated_at = $row['kb_article_updated_at'] ?? $row['kb_article_created_at'];

                    $kb_article_preview = strip_tags($row['kb_article_content_raw'] ?? '');
                    $kb_article_preview = trim(preg_replace('/\s+/', ' ', $kb_article_preview));
                    if (mb_strlen($kb_article_preview) > 150) {
                        $kb_article_preview = mb_substr($kb_article_preview, 0, 150) . '...';
                    }

                    $kb_article_url = "kb_article.php?id=$kb_article_id";
                    if (isset($client_id)) {
                        $kb_article_url .= "&client_id=$client_id";
                    }
                ?>
                    <div class="col-md-4 mb-4">
                        <div class="card h-100">
                            <div class="card-body">
                                <h5 class="card-title">
                                    <a class="text-dark" href="<?= $kb_article_url ?>"><?= $kb_article_title ?></a>
                                </h5>
                                <p class="card-text text-secondary small"><?= nullable_htmlentities($kb_article_preview) ?></p>
                            </div>
                            <div class="card-footer d-flex align-items-center justify-content-between bg-white">
                                <div>
                                    <?php if ($kb_article_client_id == 0) { ?>
                                        <span class="badge text-bg-info">Central</span>
                                    <?php } else { ?>
                                        <span class="badge text-bg-secondary"><?= $kb_article_client_name ?></span>
                                    <?php } ?>
                                    <?php if ($kb_article_client_visible == 1) { ?>
                                        <i class="fas fa-fw fa-check text-success" data-bs-toggle="tooltip" title="Visible in client portal"></i>
                                    <?php } else { ?>
                                        <i class="fas fa-fw fa-eye-slash text-muted" data-bs-toggle="tooltip" title="Hidden from client portal"></i>
                                    <?php } ?>
                                    <small class="text-secondary ms-1"><?= nullable_htmlentities(date('M j, Y', strtotime($kb_article_updated_at))) ?></small>
                                </div>
                                <div class="dropdown dropleft text-center">
                                    <button class="btn btn-secondary btn-sm" data-bs-toggle="dropdown" data-boundary="window">
                                        <i class="fas fa-ellipsis-h"></i>
                                    </button>
                                    <div class="dropdown-menu">
                                        <a class="dropdown-item" href="<?= $kb_article_url ?>">
                                            <i class="fas fa-fw fa-eye me-2"></i>View
                                        </a>
                                        <a class="dropdown-item ajax-modal" href="#" data-modal-size="lg" data-modal-url="modals/kb_article/kb_article_edit.php?id=<?= $kb_article_id ?>">
                                            <i class="fas fa-fw fa-edit me-2"></i>Edit
                                        </a>
                                        <div class="dropdown-divider"></div>
                                        <a class="dropdown-item text-danger text-bold confirm-link" href="post.php?delete_kb_article=<?= $kb_article_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                            <i class="fas fa-fw fa-trash me-2"></i>Delete
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php } ?>
            </div>
        <?php } ?>

        <?php require_once "../includes/filter_footer.php"; ?>

    </div>
</div>

</div>

<?php
require_once "../includes/footer.php";
?>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '', ENT_QUOTES) ?>">
// The category/client filter selects used to rely on an inline onchange="this.form.submit()"
// attribute, which this app's CSP (script-src with a nonce, no unsafe-inline) silently blocks.
document.querySelectorAll('.auto-submit-select').forEach(function (select) {
    select.addEventListener('change', function () {
        select.form.submit();
    });
});
</script>
