<?php
require_once "includes/inc_all_admin.php";
enforceUserPermission('module_admin');

/*
 * Knowledge Base settings.
 *
 * Scope note (deliberate, please read before "adding the missing switches"):
 * `config_module_enable_kb` is the ONLY Knowledge Base setting that exists in the
 * `settings` table, and it is the only one any KB code reads. Two other switches were
 * requested for this page - a separate "portal can see the KB" flag and a default
 * client-visibility for new articles - but both would need new settings columns AND
 * matching reads in includes/load_global_settings.php plus enforcement edits in
 * client/kb_articles.php, client/kb_article.php, client/includes/header.php,
 * agent/modals/kb_article/kb_article_add.php and agent/post/kb_article.php. Adding the
 * columns without those would give this page two switches that save to the database and
 * change nothing anywhere in the app, so they are not rendered. What the app really does
 * enforce today is documented in the "Where the Knowledge Base appears" card below.
 */

// Live Knowledge Base state, read from the same tables/conditions agent/kb_articles.php
// uses. These are facts about the data rather than settings, and they are what lets an
// admin answer "is the KB actually being used, and who can see it?" from one page.
$kb_total = $kb_visible = $kb_internal = $kb_central = $kb_needs_review = 0;
$sql_kb_stats = mysqli_query($mysqli,
    "SELECT
        COUNT(*) AS kb_total,
        COALESCE(SUM(kb_article_client_visible = 1), 0) AS kb_visible,
        COALESCE(SUM(kb_article_client_visible = 0), 0) AS kb_internal,
        COALESCE(SUM(kb_article_client_id = 0), 0) AS kb_central,
        COALESCE(SUM(kb_article_review_due_at IS NOT NULL AND kb_article_review_due_at < CURDATE()), 0) AS kb_needs_review
     FROM kb_articles
     WHERE kb_article_archived_at IS NULL"
);
if ($sql_kb_stats && ($row = mysqli_fetch_assoc($sql_kb_stats))) {
    $kb_total        = intval($row['kb_total']);
    $kb_visible      = intval($row['kb_visible']);
    $kb_internal     = intval($row['kb_internal']);
    $kb_central      = intval($row['kb_central']);
    $kb_needs_review = intval($row['kb_needs_review']);
}

$kb_archived = 0;
$sql_kb_archived = mysqli_query($mysqli, "SELECT COUNT(*) AS kb_archived FROM kb_articles WHERE kb_article_archived_at IS NOT NULL");
if ($sql_kb_archived && ($row = mysqli_fetch_assoc($sql_kb_archived))) {
    $kb_archived = intval($row['kb_archived']);
}

$kb_categories = 0;
$sql_kb_categories = mysqli_query($mysqli, "SELECT COUNT(*) AS kb_categories FROM kb_categories WHERE kb_category_archived_at IS NULL");
if ($sql_kb_categories && ($row = mysqli_fetch_assoc($sql_kb_categories))) {
    $kb_categories = intval($row['kb_categories']);
}

// The portal only shows a Knowledge Base link when BOTH the portal and the KB module are
// on (client/includes/header.php gates the nav item on the KB module, and the portal
// itself is unreachable when config_client_portal_enable is 0).
$kb_portal_live = ($config_module_enable_kb == 1 && $config_client_portal_enable == 1);
?>

<!-- Plain .card, not the legacy AdminLTE .card-dark - see the note in
     admin/settings_module.php: .card-dark squares off the card corners and out-ranks the
     design layer's .form-control colours, painting every field white in dark mode. -->
<div class="card">
    <div class="card-header py-3">
        <h3 class="card-title"><i class="fas fa-fw fa-book me-2"></i>Knowledge Base Settings</h3>
        <div class="card-actions">
            <a href="/agent/kb_articles.php" class="btn btn-outline-primary btn-sm">
                <i class="fas fa-fw fa-list-ul me-1"></i>Browse Articles
            </a>
        </div>
    </div>
    <div class="card-body">
        <form action="post.php" method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">

            <div class="form-group">
                <div class="form-check form-check form-switch">
                    <input type="checkbox" class="form-check-input" name="config_module_enable_kb" <?php if ($config_module_enable_kb == 1) { echo "checked"; } ?> value="1" id="kbModuleEnableSwitch">
                    <label class="form-check-label" for="kbModuleEnableSwitch">Enable the Knowledge Base</label>
                </div>
                <small class="form-text text-muted">
                    Adds a Knowledge Base for agents and for departments &mdash; per-department articles plus a Central (company-wide) library.
                    Turning this off hides the Knowledge Base everywhere: the agent sidebar, the department portal, and global search.
                    Existing articles are kept, not deleted.
                </small>
            </div>

            <hr>

            <button type="submit" name="edit_kb_settings" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Save</button>

        </form>
    </div>
</div>

<div class="card mt-3">
    <div class="card-header py-3">
        <h3 class="card-title"><i class="fas fa-fw fa-eye me-2"></i>Where the Knowledge Base appears</h3>
    </div>
    <div class="card-body">
        <p class="text-muted">
            Article visibility is not a single global switch &mdash; it is decided in three places. This is what each of them is set to right now.
        </p>

        <div class="list-group list-group-flush">

            <div class="list-group-item px-0">
                <div class="row align-items-center">
                    <div class="col-auto">
                        <i class="fas fa-fw fa-lg fa-user-tie text-secondary"></i>
                    </div>
                    <div class="col">
                        <div class="fw-bold">Agents</div>
                        <div class="text-muted small">
                            Every agent with the <code>module_kb</code> permission sees all non-archived articles, department-visible or not.
                        </div>
                    </div>
                    <div class="col-auto">
                        <?php if ($config_module_enable_kb == 1) { ?>
                            <span class="badge text-bg-success">Enabled</span>
                        <?php } else { ?>
                            <span class="badge text-bg-secondary">Module off</span>
                        <?php } ?>
                    </div>
                </div>
            </div>

            <div class="list-group-item px-0">
                <div class="row align-items-center">
                    <div class="col-auto">
                        <i class="fas fa-fw fa-lg fa-building text-secondary"></i>
                    </div>
                    <div class="col">
                        <div class="fw-bold">Department portal</div>
                        <div class="text-muted small">
                            Portal users only ever see articles marked <em>Visible to Department Portal</em>, and only their own department's articles plus the Central library.
                            The portal Knowledge Base needs both the department portal and this module switched on.
                        </div>
                    </div>
                    <div class="col-auto">
                        <?php if ($kb_portal_live) { ?>
                            <span class="badge text-bg-success">Visible</span>
                        <?php } elseif ($config_module_enable_kb != 1) { ?>
                            <span class="badge text-bg-secondary">Module off</span>
                        <?php } else { ?>
                            <span class="badge text-bg-secondary">Portal off</span>
                        <?php } ?>
                    </div>
                </div>
            </div>

            <div class="list-group-item px-0">
                <div class="row align-items-center">
                    <div class="col-auto">
                        <i class="fas fa-fw fa-lg fa-file-alt text-secondary"></i>
                    </div>
                    <div class="col">
                        <div class="fw-bold">Per article</div>
                        <div class="text-muted small">
                            Each article carries its own <em>Visible to Department Portal</em> flag, set when the article is added or edited.
                            New articles default to <strong>Yes</strong> (department-visible).
                        </div>
                    </div>
                    <div class="col-auto">
                        <span class="badge text-bg-secondary"><?php echo $kb_visible; ?> of <?php echo $kb_total; ?> visible</span>
                    </div>
                </div>
            </div>

        </div>

        <div class="mt-3">
            <a href="/admin/settings_module.php" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-fw fa-cube me-1"></i>Modules &amp; Department Portal
            </a>
            <a href="/agent/kb_articles.php" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-fw fa-book me-1"></i>Manage Articles &amp; Categories
            </a>
        </div>
    </div>
</div>

<div class="card mt-3">
    <div class="card-header py-3">
        <h3 class="card-title"><i class="fas fa-fw fa-chart-bar me-2"></i>Knowledge Base contents</h3>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-6 col-md-4 col-xl-2">
                <div class="text-muted small text-uppercase">Articles</div>
                <div class="h1 mb-0"><?php echo $kb_total; ?></div>
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <div class="text-muted small text-uppercase">Department-visible</div>
                <div class="h1 mb-0"><?php echo $kb_visible; ?></div>
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <div class="text-muted small text-uppercase">Internal only</div>
                <div class="h1 mb-0"><?php echo $kb_internal; ?></div>
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <div class="text-muted small text-uppercase">Central library</div>
                <div class="h1 mb-0"><?php echo $kb_central; ?></div>
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <div class="text-muted small text-uppercase">Categories</div>
                <div class="h1 mb-0"><?php echo $kb_categories; ?></div>
            </div>
            <div class="col-6 col-md-4 col-xl-2">
                <div class="text-muted small text-uppercase">Archived</div>
                <?php if ($kb_archived > 0) { ?>
                    <a href="/admin/kb_articles_archive.php" class="h1 mb-0 d-block text-reset text-decoration-none" title="View, restore or permanently delete archived articles">
                        <?php echo $kb_archived; ?>
                    </a>
                <?php } else { ?>
                    <div class="h1 mb-0"><?php echo $kb_archived; ?></div>
                <?php } ?>
            </div>
        </div>

        <?php if ($kb_needs_review > 0) { ?>
            <!-- .alert is display:flex with a 16px gap in this cascade, so the whole sentence
                 has to live in ONE child element - loose text nodes around the link become
                 separate flex items and the trailing full stop floats off on its own. -->
            <div class="alert alert-warning mt-3 mb-0" role="alert">
                <i class="fas fa-fw fa-exclamation-triangle"></i>
                <span><strong><?php echo $kb_needs_review; ?></strong> article<?php echo $kb_needs_review == 1 ? ' is' : 's are'; ?> past their review date. <a href="/agent/kb_articles.php?filter_needs_review=1" class="alert-link">Review them</a>.</span>
            </div>
        <?php } elseif ($kb_total === 0) { ?>
            <div class="alert alert-info mt-3 mb-0" role="alert">
                <i class="fas fa-fw fa-info-circle"></i>
                <span>No articles yet. <a href="/agent/kb_articles.php" class="alert-link">Add the first one</a>.</span>
            </div>
        <?php } ?>
    </div>
</div>

<?php
require_once "../includes/footer.php";
