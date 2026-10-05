<?php
require_once "includes/inc_all_admin.php";

require_once "../includes/database_version.php";

$updates = fetchUpdates();

$latest_version      = $updates->latest_version;
$current_version     = $updates->current_version;
$current_version_tag = $updates->current_version_tag;
$latest_version_tag  = $updates->latest_version_tag;
$git_fetch_output    = $updates->output;
$result = $updates->result;

// The updater's git remote is named by APP_UPDATE_REMOTE (includes/branding.php): "origin" by default.
$repo_branch = $updates->branch;   // the release channel's branch (Production or Beta), not config.php's old fixed value
$update_ref  = escapeshellarg(RELEASE_REMOTE . '/' . $repo_branch);
$git_log_raw = shell_exec("git log HEAD.." . $update_ref . " --pretty=format:'%h|%ar|%s'");
$channel_status = $updates->channel_status;
$channels = releaseChannels();

$git_log = '';
if (!empty($git_log_raw)) {
    foreach (explode("\n", $git_log_raw) as $git_log_line) {
        if ($git_log_line === '') {
            continue;
        }
        list($git_log_hash, $git_log_when, $git_log_subject) = array_pad(explode('|', $git_log_line, 3), 3, '');
        $git_log .= '<tr><td>' . htmlspecialchars($git_log_hash) . '</td><td>' . htmlspecialchars($git_log_when) . '</td><td>' . htmlspecialchars($git_log_subject) . '</td></tr>';
    }
}

$pending_count = 0;
foreach (explode("\n", (string) $git_log_raw) as $git_log_line) {
    if ($git_log_line !== '') {
        $pending_count++;
    }
}

$db_pending  = version_compare(LATEST_DATABASE_VERSION, CURRENT_DATABASE_VERSION, '>');
$app_pending = !$db_pending && !empty($git_log);
$pending     = $db_pending || $app_pending;

$csrf_q = urlencode($_SESSION['csrf_token']);

$last_backup_ts = 0;
foreach (glob($_SERVER['DOCUMENT_ROOT'] . '/backups/itflow_*.zip') ?: [] as $backup_file) {
    $last_backup_ts = max($last_backup_ts, (int) filemtime($backup_file));
}
$last_backup_stale = !$last_backup_ts || $last_backup_ts < time() - 86400;

$changelog_link = APP_CHANGELOG_URL !== ''
    ? '<a href="' . htmlspecialchars(APP_CHANGELOG_URL) . '" target="_blank" rel="noopener">changelog</a>'
    : 'changelog (CHANGELOG.md in the install folder)';


?>

<style>
    .upd-hero { display: flex; align-items: center; gap: 1rem; flex-wrap: wrap; }
    .upd-icon { width: 3.5rem; height: 3.5rem; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; flex: 0 0 auto; }
    .upd-icon--ok   { background: rgba(47, 179, 68, .16);  color: #2fb344; }
    .upd-icon--app  { background: rgba(66, 153, 225, .18); color: #4299e1; }
    .upd-icon--db   { background: rgba(245, 159, 0, .18);  color: #f59f00; }
    .upd-icon--fail { background: rgba(214, 57, 57, .16);  color: #d63939; }
    .upd-tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: .75rem; margin-top: 1.25rem; }
    .upd-tile { background: rgba(128, 128, 128, .08); border: 1px solid rgba(128, 128, 128, .18); border-radius: .5rem; padding: .75rem 1rem; }
    .upd-tile-label { font-size: .7rem; text-transform: uppercase; letter-spacing: .06em; opacity: .65; }
    .upd-tile-value { font-weight: 600; font-size: 1.05rem; margin-top: .15rem; word-break: break-word; }
    .upd-backup { display: flex; gap: .75rem; align-items: flex-start; padding: .9rem 1rem; border: 1px solid rgba(128, 128, 128, .25); border-radius: .5rem; background: rgba(128, 128, 128, .06); }
    .upd-backup .form-check-input { margin-top: .2rem; flex: 0 0 auto; }
    .upd-actions { display: flex; flex-wrap: wrap; gap: .75rem; align-items: center; margin-top: 1.25rem; }
    .upd-table th:last-child, .upd-table td:last-child { padding-right: 1.25rem; }
    .upd-table td { padding-top: .8rem; padding-bottom: .8rem; line-height: 1.5; }
    .upd-commits td:first-child { font-family: var(--tblr-font-monospace, monospace); font-size: .85rem; }
    #updBusy { position: fixed; inset: 0; z-index: 2000; display: none; align-items: center; justify-content: center; background: rgba(0, 0, 0, .55); }
    #updBusy.show { display: flex; }
    #updBusy .upd-busy-box { max-width: 26rem; margin: 1rem; text-align: center; padding: 2rem; border-radius: .75rem; background: var(--tblr-bg-surface, #fff); color: var(--tblr-body-color, #212529); box-shadow: 0 1rem 3rem rgba(0, 0, 0, .35); }
</style>

<?php if ($result !== 0) { ?>
    <div class="alert alert-danger">
        <strong>Could not run 'git fetch', so the update check may be out of date.</strong>
        <div class="mt-2"><i>Error details: <?php echo htmlspecialchars(implode("\n", $git_fetch_output ?: [])); ?></i></div>
        <?php /* Reuses fetchUpdates()'s already-captured output rather than running a second, unguarded git fetch: on a host where the first needed a timeout wrapper (see fetchUpdates() in functions.php) a second would hang on every load of this warning. */ ?>
        <div class="mt-2">Things to check: is Git installed, is the Git remote correct, and are web server file permissions too strict?<?php if (APP_SUPPORT_URL !== '') { ?> Ask on the <a href="<?= htmlspecialchars(APP_SUPPORT_URL) ?>" class="alert-link" target="_blank" rel="noopener">issue tracker</a> if you need help, and include the relevant PHP error logs and the <?= htmlspecialchars(APP_NAME) ?> debug output.<?php } ?></div>
    </div>
<?php } ?>

<div class="card mb-3">
    <div class="card-header py-3"><h3 class="card-title"><i class="fas fa-fw fa-code-branch me-2"></i>Release channel</h3></div>
    <div class="card-body">
        <form action="post.php" method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <div class="row g-3">
                <?php foreach ($channels as $ckey => $cdef) { ?>
                    <div class="col-md-6">
                        <label class="d-block border rounded p-3 h-100 <?= $updates->channel === $ckey ? 'border-primary' : '' ?>">
                            <input class="form-check-input me-2" type="radio" name="release_channel" value="<?= htmlspecialchars($ckey) ?>" <?= $updates->channel === $ckey ? 'checked' : '' ?>>
                            <strong><?= htmlspecialchars($cdef['label']) ?></strong>
                            <?php if ($updates->channel === $ckey) { ?><span class="badge bg-primary ms-1">This server</span><?php } ?>
                            <div class="text-secondary small mt-1"><?= htmlspecialchars($cdef['summary']) ?></div>
                            <div class="text-secondary small mt-1">Follows <code><?= htmlspecialchars(RELEASE_REMOTE . '/' . $cdef['branch']) ?></code></div>
                        </label>
                    </div>
                <?php } ?>
            </div>
            <div class="mt-3 d-flex flex-wrap align-items-center gap-3">
                <button type="submit" name="save_release_channel" class="btn btn-primary"><i class="fas fa-fw fa-check me-2"></i>Save channel</button>
                <span class="text-secondary small">
                    Running branch: <code><?= htmlspecialchars($channel_status['current_branch'] ?: 'unknown') ?></code>
                    <?php if (!$channel_status['same_branch'] && $channel_status['ref_exists'] && $channel_status['can_switch']) { ?>
                        &middot; <strong>Update App will switch this server to <code><?= htmlspecialchars($channel_status['branch']) ?></code>.</strong>
                    <?php } ?>
                </span>
            </div>
            <?php if ($channel_status['reason'] !== '') { ?>
                <div class="alert alert-warning mt-3 mb-0"><?= htmlspecialchars($channel_status['reason']) ?></div>
            <?php } ?>
            <p class="text-secondary small mt-3 mb-0">Switching channel never loses data and never installs older code: a switch that would go backwards is refused. Take a backup first (the Update App backup option does this).</p>
        </form>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <div class="upd-hero">
            <?php if ($db_pending) { ?>
                <div class="upd-icon upd-icon--db"><i class="fas fa-database"></i></div>
                <div>
                    <h3 class="mb-1">Database update needed</h3>
                    <div class="text-secondary">The code is newer than the database structure. Update the database to finish the upgrade.</div>
                </div>
            <?php } elseif ($app_pending) { ?>
                <div class="upd-icon upd-icon--app"><i class="fas fa-arrow-circle-up"></i></div>
                <div>
                    <h3 class="mb-1">Update available</h3>
                    <div class="text-secondary"><?= $pending_count ?> new change<?= $pending_count === 1 ? '' : 's' ?> ready to install.</div>
                </div>
            <?php } elseif ($result !== 0) { ?>
                <div class="upd-icon upd-icon--fail"><i class="fas fa-exclamation-triangle"></i></div>
                <div>
                    <h3 class="mb-1">Update check unavailable</h3>
                    <div class="text-secondary">The versions below are what is installed now.</div>
                </div>
            <?php } else { ?>
                <div class="upd-icon upd-icon--ok"><i class="fas fa-check"></i></div>
                <div>
                    <h3 class="mb-1">You're up to date</h3>
                    <div class="text-secondary">This install is running the latest release.</div>
                </div>
            <?php } ?>
        </div>

        <div class="upd-tiles">
            <div class="upd-tile">
                <div class="upd-tile-label"><?= htmlspecialchars(APP_NAME) ?> version</div>
                <div class="upd-tile-value"><?= htmlspecialchars(APP_VERSION) ?></div>
            </div>
            <div class="upd-tile">
                <div class="upd-tile-label">Release tag</div>
                <div class="upd-tile-value"><?php echo htmlspecialchars($current_version_tag); ?></div>
            </div>
            <div class="upd-tile">
                <div class="upd-tile-label">Latest release</div>
                <div class="upd-tile-value"><?php if (APP_RELEASES_URL !== '') { ?><a href="<?= htmlspecialchars(APP_RELEASES_URL) ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($latest_version_tag); ?></a><?php } else { echo htmlspecialchars($latest_version_tag); } ?></div>
            </div>
            <div class="upd-tile">
                <div class="upd-tile-label">Database version</div>
                <div class="upd-tile-value">
                    <?php echo CURRENT_DATABASE_VERSION; ?>
                    <?php if ($db_pending) { ?><span class="text-warning">&rarr; <?php echo LATEST_DATABASE_VERSION; ?></span><?php } ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($pending) { ?>
<div class="card mb-3">
    <div class="card-header py-3">
        <h3 class="card-title"><i class="fas fa-fw fa-shield-alt me-2"></i><?= $db_pending ? 'Update the database' : 'Install the update' ?></h3>
    </div>
    <div class="card-body">
        <p class="mb-3">
            An update changes files and, for a database update, the database structure, and it cannot be undone automatically.
            Read the <?= $changelog_link ?> for breaking changes that may need manual fixes<?php if (APP_DOCS_URL !== '') { ?> (<a href="<?= htmlspecialchars(APP_DOCS_URL) ?>" target="_blank" rel="noopener">docs</a>)<?php } ?>.
            A VM snapshot is the safest rollback if you can take one.
        </p>

        <label class="upd-backup" for="updBackup">
            <input type="checkbox" class="form-check-input" id="updBackup" checked>
            <span>
                <strong>Back up first</strong> <span class="badge bg-success ms-1">Recommended</span>
                <span class="d-block text-secondary small mt-1">
                    Saves a full backup (database and uploads) to the server's backups folder before anything changes<?= !empty($config_backup_s3_enabled) ? ', and uploads it to remote storage' : '' ?>.
                    If the backup fails the update does not run. The backup can take a few minutes on large installs.
                </span>
                <span class="d-block small mt-1 <?= $last_backup_stale ? 'text-warning' : 'text-secondary' ?>">
                    <i class="fas fa-fw fa-history me-1"></i>
                    <?= $last_backup_ts ? 'Last backup on this server: ' . htmlspecialchars(timeAgo(date('Y-m-d H:i:s', $last_backup_ts))) : 'No backup is stored on this server yet' ?>
                    &middot; <a href="backup.php">Manage backups</a>
                </span>
            </span>
        </label>

        <div class="upd-actions">
            <?php if ($db_pending) { ?>
                <a class="btn btn-primary btn-lg confirm-link" data-upd data-upd-what="database" href="post.php?update_db&csrf_token=<?php echo $csrf_q; ?>"><i class="fas fa-fw fa-database me-2"></i>Update Database</a>
            <?php } else { ?>
                <a class="btn btn-primary btn-lg confirm-link" data-upd data-upd-what="app" href="post.php?update&csrf_token=<?php echo $csrf_q; ?>"><i class="fas fa-fw fa-download me-2"></i>Update App</a>
            <?php } ?>
        </div>

        <?php if ($app_pending) { ?>
            <details class="mt-4 pt-3 border-top">
                <summary class="text-secondary small">Advanced: force update</summary>
                <p class="text-secondary small mt-2 mb-2">
                    Use this only if a normal update fails. It downloads everything and resets the code to match the remote exactly, <strong>discarding any local changes to the files</strong>.
                </p>
                <a class="btn btn-outline-danger btn-sm confirm-link" data-upd data-upd-what="force" href="post.php?update&force_update=1&csrf_token=<?php echo $csrf_q; ?>"><i class="fas fa-fw fa-hammer me-1"></i>FORCE Update App</a>
            </details>
        <?php } ?>
    </div>
</div>
<?php } ?>

<?php if (!empty($git_log)) { ?>
<div class="card mb-3">
    <div class="card-header py-3">
        <h3 class="card-title"><i class="fas fa-fw fa-code-branch me-2"></i>Pending changes <span class="badge bg-secondary ms-1"><?= $pending_count ?></span></h3>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 upd-table">
            <thead>
                <tr>
                    <th style="width: 7rem;">Commit</th>
                    <th style="width: 11rem;">When</th>
                    <th>Description</th>
                </tr>
            </thead>
            <tbody class="upd-commits">
                <?php echo $git_log; ?>
            </tbody>
        </table>
    </div>
</div>
<?php } ?>

<?php /* The check and the Update App button use different git sources: see APP_UPDATE_REMOTE in includes/branding.php. */ ?>
<p class="text-muted small">
    Checked against <code><?= htmlspecialchars(RELEASE_REMOTE . '/' . $repo_branch) ?></code>
    (the <?= htmlspecialchars($channels[$updates->channel]['label']) ?> channel on the <code><?= htmlspecialchars(RELEASE_REMOTE) ?></code> git remote of this checkout); <strong>Update App</strong> installs from the same branch.<?php if (APP_SOURCE_URL !== '') { ?>&nbsp;&middot;
    <a href="<?= htmlspecialchars(APP_SOURCE_URL) ?>" target="_blank" rel="noopener">Project repository</a><?php } ?>
</p>

<div id="updBusy" role="alertdialog" aria-live="assertive" aria-labelledby="updBusyTitle">
    <div class="upd-busy-box">
        <div class="spinner-border text-primary mb-3" role="status"></div>
        <h4 id="updBusyTitle" class="mb-2">Working...</h4>
        <div class="text-secondary" id="updBusyText"></div>
    </div>
</div>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
(function () {
    var box = document.getElementById('updBackup');
    var links = document.querySelectorAll('a[data-upd]');
    if (!links.length) { return; }
    var pendingWhat = '';

    function backupOn() { return !box || box.checked; }

    function syncLinks() {
        links.forEach(function (a) {
            var base = a.getAttribute('href').replace(/&no_backup=1/, '');
            a.setAttribute('href', backupOn() ? base : base + '&no_backup=1');
        });
    }
    if (box) { box.addEventListener('change', syncLinks); }
    syncLinks();

    var messages = {
        database: 'update the database structure',
        app: 'download and install the update',
        force: 'discard local file changes and force the update'
    };

    document.addEventListener('click', function (e) {
        var a = e.target.closest('a[data-upd]');
        if (!a) { return; }
        pendingWhat = a.getAttribute('data-upd-what');
        var body = document.querySelector('#confirmationModal .modal-body');
        if (body) {
            body.textContent = (backupOn() ? 'Take a backup, then ' : 'Skip the backup and ') + messages[pendingWhat] + '?';
        }
    }, true);

    document.addEventListener('click', function (e) {
        if (!pendingWhat || !e.target.closest('#confirmSubmitBtn')) { return; }
        document.getElementById('updBusyTitle').textContent = backupOn() ? 'Backing up, then updating...' : 'Updating...';
        document.getElementById('updBusyText').textContent = 'Please keep this page open. It reloads when the update finishes.';
        var modal = window.bootstrap && bootstrap.Modal.getInstance(document.getElementById('confirmationModal'));
        if (modal) { modal.hide(); }
        document.getElementById('updBusy').classList.add('show');
    });
})();
</script>

<?php

require_once "../includes/footer.php";
