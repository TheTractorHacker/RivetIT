<?php
require_once "includes/inc_all.php";
enforceUserPermission('module_rmm_scripts');

$run_id = intval($_GET['run_id'] ?? 0);
if (!$run_id) { flash_alert('No run specified', 'danger'); redirect('/agent/rmm_scripts.php'); }

$run = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT sr.*, s.name as script_name, s.script_type, s.description as script_desc,
            a.asset_name, a.asset_client_id,
            c.client_name,
            u.user_name
     FROM rmm_script_runs sr
     LEFT JOIN rmm_scripts s ON s.id = sr.script_id
     JOIN assets a ON a.asset_id = sr.asset_id
     LEFT JOIN clients c ON c.client_id = a.asset_client_id
     LEFT JOIN users u ON u.user_id = sr.user_id
     WHERE sr.id=$run_id"
));
if (!$run) { flash_alert('Script run not found', 'danger'); redirect('/agent/rmm_scripts.php'); }

if ($run['asset_client_id']) { enforceClientAccess(intval($run['asset_client_id'])); }

$badge = ['completed'=>'success','failed'=>'danger','running'=>'warning','pending'=>'secondary'][$run['status']] ?? 'secondary';
?>

<div class="d-flex align-items-center mb-3">
    <h4 class="mb-0 mr-auto">
        <i class="fas fa-code me-2"></i>Script Run #<?= $run_id ?>
    </h4>
    <a href="/agent/asset_details.php?asset_id=<?= intval($run['asset_id']) ?>" class="btn btn-secondary btn-sm me-2">
        <i class="fas fa-desktop me-1"></i><?= nullable_htmlentities($run['asset_name']) ?>
    </a>
    <a href="/agent/rmm_scripts.php" class="btn btn-secondary btn-sm">
        <i class="fas fa-arrow-left me-1"></i>Script Library
    </a>
</div>

<div class="row">
    <div class="col-md-4">
        <div class="card card-dark mb-3">
            <div class="card-header py-2">
                <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>Run Details</h6>
            </div>
            <div class="card-body p-3">
                <table class="table table-sm table-borderless mb-0">
                    <tr><td class="text-muted" style="width:40%">Script</td><td class="fw-bold"><?= nullable_htmlentities($run['script_name'] ?? 'Manual') ?></td></tr>
                    <tr><td class="text-muted">Type</td><td><?= nullable_htmlentities($run['script_type'] ?? '') ?></td></tr>
                    <tr><td class="text-muted">Asset</td>
                        <td><a href="/agent/asset_details.php?asset_id=<?= intval($run['asset_id']) ?>"><?= nullable_htmlentities($run['asset_name']) ?></a></td></tr>
                    <tr><td class="text-muted">Client</td>
                        <td><a href="/agent/client_details.php?client_id=<?= intval($run['asset_client_id']) ?>"><?= nullable_htmlentities($run['client_name']) ?></a></td></tr>
                    <tr><td class="text-muted">Run By</td><td><?= nullable_htmlentities($run['user_name']) ?></td></tr>
                    <tr><td class="text-muted">Status</td><td><span class="badge badge-<?= $badge ?>" id="run-status"><?= $run['status'] ?></span></td></tr>
                    <tr><td class="text-muted">Started</td><td class="small text-muted"><?= nullable_htmlentities($run['started_at']) ?></td></tr>
                    <?php if ($run['finished_at']): ?>
                    <tr><td class="text-muted">Finished</td><td class="small text-muted"><?= nullable_htmlentities($run['finished_at']) ?></td></tr>
                    <?php endif; ?>
                    <?php if ($run['tactical_job_id']): ?>
                    <tr><td class="text-muted">Job ID</td><td><code class="small"><?= nullable_htmlentities($run['tactical_job_id']) ?></code></td></tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

    <div class="col-md-8">
        <div class="card card-dark mb-3">
            <div class="card-header py-2 d-flex align-items-center">
                <h6 class="mb-0 mr-auto"><i class="fas fa-terminal me-2"></i>Output</h6>
                <?php if (in_array($run['status'], ['pending', 'running'])): ?>
                <button class="btn btn-xs btn-info js-refresh-output" id="refreshBtn">
                    <i class="fas fa-sync me-1"></i>Refresh
                </button>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <div id="output-container">
                <?php if ($run['status'] === 'running' || $run['status'] === 'pending'): ?>
                    <div class="text-center text-muted py-4" id="waiting-msg">
                        <i class="fas fa-spinner fa-spin fa-2x mb-2 d-block"></i>
                        Script is <?= $run['status'] ?>… Refresh to check for output.
                    </div>
                <?php elseif ($run['output']): ?>
                    <pre class="p-3 mb-0" style="background:#1a1a1a;color:#e0e0e0;font-size:12px;max-height:500px;overflow-y:auto;border-radius:0"><?= nullable_htmlentities($run['output']) ?></pre>
                <?php elseif ($run['error_message']): ?>
                    <div class="alert alert-danger m-3 mb-3">
                        <i class="fas fa-times-circle me-2"></i><?= nullable_htmlentities($run['error_message']) ?>
                    </div>
                <?php else: ?>
                    <p class="text-muted text-center py-4 mb-0">No output recorded.</p>
                <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (in_array($run['status'], ['pending', 'running'])): ?>
<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
document.addEventListener('click', function (e) {
    if (e.target.closest('.js-refresh-output')) { refreshOutput(); }
});

function refreshOutput() {
    fetch('/agent/post/rmm_run_status.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'csrf_token=<?= $_SESSION['csrf_token'] ?>&run_id=<?= $run_id ?>'
    }).then(r => r.json()).then(d => {
        if (d.status) {
            document.getElementById('run-status').textContent = d.status;
            if (d.status === 'completed') {
                document.getElementById('run-status').className = 'badge text-bg-success';
                if (d.output) {
                    document.getElementById('output-container').innerHTML =
                        '<pre class="p-3 mb-0" style="background:#1a1a1a;color:#e0e0e0;font-size:12px;max-height:500px;overflow-y:auto;">' +
                        d.output.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</pre>';
                }
            } else if (d.status === 'failed') {
                document.getElementById('run-status').className = 'badge text-bg-danger';
                if (d.error) {
                    document.getElementById('output-container').innerHTML =
                        '<div class="alert alert-danger m-3">' + d.error.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</div>';
                }
            }
        }
    });
}
// Auto-refresh every 5 seconds if still running/pending
setInterval(refreshOutput, 5000);
</script>
<?php endif; ?>

<?php require_once "../includes/footer.php"; ?>
