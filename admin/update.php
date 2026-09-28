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

// The updater's git remote is named by APP_UPDATE_REMOTE (includes/branding.php): "fork", as before.
$update_ref  = escapeshellarg(APP_UPDATE_REMOTE . '/' . $repo_branch);
$git_log_raw = shell_exec("git log $repo_branch..$update_ref --pretty=format:'%h|%ar|%s'");

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

?>

    <div class="card card-dark">
        <div class="card-header py-3">
            <h3 class="card-title"><i class="fas fa-fw fa-download me-2"></i>Update</h3>
        </div>
        <div class="card-body" style="text-align: center;">

            <!-- Check if git fetch result was successful (0), if not show a warning -->
            <?php if ($result !== 0) { ?>
                <div class="alert alert-danger">
                    <strong>WARNING: Could not find execute 'git fetch'.</strong>
                    <br><br>
                    <?php /* Reuses fetchUpdates()'s own already-captured output instead of running
                             a second, unguarded `git fetch fork` here - that second call had no
                             timeout wrapper, so on the same host/condition that made the first one
                             need one (see functions.php's fetchUpdates()), this would hang a second
                             time on every single page load of this exact warning. */ ?>
                    <i>Error details:- <?php echo htmlspecialchars(implode("\n", $git_fetch_output ?: [])); ?></i>
                    <br>
                    <br>Things to check: Is Git installed? Is the Git origin/remote correct? Are web server file permissions too strict?
                    <br>Seek support on the <a href="<?= htmlspecialchars(APP_SUPPORT_URL) ?>" target="_blank" rel="noopener">issue tracker</a> if required - include relevant PHP error logs & <?= htmlspecialchars(APP_NAME) ?> debug output
                </div>
            <?php } ?>

            <?php if (version_compare(LATEST_DATABASE_VERSION, CURRENT_DATABASE_VERSION, '>')) { ?>
                <div class="alert alert-danger">
                    <h1 class="fw-bold text-center">⚠️ DANGER ⚠️</h1>
                    <h2 class="fw-bold text-center">Do NOT run updates without first taking a backup</h2>
                    <p>VM Snapshots are highly recommended over other methods - see the <a href="<?= htmlspecialchars(APP_DOCS_URL) ?>" class="alert-link" target="_blank" rel="noopener">docs</a>. Review the <a href="<?= htmlspecialchars(APP_REPO_URL) ?>/blob/main/CHANGELOG.md" class="alert-link" target="_blank" rel="noopener">changelog</a> for breaking changes that may require manual remediation.</p>
                    <p class="text-center fw-bold">Ignore this warning at your own risk.</p>
                </div>
                <br>
                <a class="btn btn-dark btn-lg my-4" href="post.php?update_db&csrf_token=<?php echo urlencode($_SESSION['csrf_token']); ?>"><i class="fas fa-fw fa-4x fa-download mb-1"></i><h5>Update Database</h5></a>
                <br>
                <small class="text-secondary">Current DB Version: <?php echo CURRENT_DATABASE_VERSION; ?></small>
                <br>
                <small class="text-secondary">Latest DB Version: <?php echo LATEST_DATABASE_VERSION; ?></small>
                <br>
                <hr>

            <?php } else {
                if (!empty($git_log)) { ?>
                    <div class="alert alert-danger">
                        <h1 class="fw-bold text-center">⚠️ DANGER ⚠️</h1>
                        <h2 class="fw-bold text-center">Do NOT run updates without first taking a backup</h2>
                        <p>VM Snapshots are highly recommended over other methods - see the <a href="<?= htmlspecialchars(APP_DOCS_URL) ?>" class="alert-link" target="_blank" rel="noopener">docs</a>. Review the <a href="<?= htmlspecialchars(APP_REPO_URL) ?>/blob/main/CHANGELOG.md" class="alert-link" target="_blank" rel="noopener">changelog</a> for breaking changes that may require manual remediation.</p>
                        <p class="text-center fw-bold">Ignore this warning at your own risk.</p>
                    </div>

                    <a class="btn btn-primary btn-lg my-4 confirm-link" href="post.php?update&csrf_token=<?php echo urlencode($_SESSION['csrf_token']); ?>"><i class="fas fa-fw fa-4x fa-download mb-1"></i><h5>Update App</h5></a>
                    <a class="btn btn-danger btn-lg confirm-link" href="post.php?update&force_update=1&csrf_token=<?php echo urlencode($_SESSION['csrf_token']); ?>"><i class="fas fa-fw fa-4x fa-hammer mb-1"></i><h5>FORCE Update App</h5></a>

                <?php } else { ?>
                    <p><strong><?= htmlspecialchars(APP_NAME) ?> Version:<br><strong class="text-dark"><?php echo htmlspecialchars($current_version_tag); ?></strong></p>
                    <p class="text-secondary">Latest Release:<br><strong class="text-dark"><a href="<?= htmlspecialchars(APP_RELEASES_URL) ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($latest_version_tag); ?></a></strong></p>
                    <p class="text-secondary">Database Version:<br><strong class="text-dark"><?php echo CURRENT_DATABASE_VERSION; ?></strong></p>
                    <p class="text-muted">You are up to date!<br>Everything is going to be alright</p>
                    <i class="far fa-3x text-dark fa-smile-wink"></i><br>

                    <?php if (rand(1,10) == 1) { ?>
                        <br>
                        <div class="alert alert-info alert-dismissible fade show" role="alert">
                            You're up to date, but when was the last time you checked your <?= htmlspecialchars(APP_NAME) ?> backup works?
                            <button type="button" class="close" data-bs-dismiss="alert" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                        </div>
                    <?php } ?>

                <?php }
            }

            if (!empty($git_log)) { ?>
                <table class="table ">
                    <thead>
                    <tr>
                        <th>Commit</th>
                        <th>When</th>
                        <th>Description</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php
                    echo $git_log;
                    ?>
                    </tbody>
                </table>
                <?php
            }

            ?>

            <p class="text-muted small mt-3 mb-0">
                Updates come from the <code><?= htmlspecialchars(APP_UPDATE_REMOTE) ?></code> git remote of this checkout
                (branch <code><?= htmlspecialchars((string) $repo_branch) ?></code>) &middot;
                <a href="<?= htmlspecialchars(APP_REPO_URL) ?>" target="_blank" rel="noopener">Project repository</a>
            </p>

        </div>
    </div>

<?php

require_once "../includes/footer.php";

