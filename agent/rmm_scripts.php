<?php
require_once "includes/inc_all.php";
enforceUserPermission('module_rmm');

$categories = ['Maintenance', 'Repair', 'Inventory', 'Security', 'Software Install'];
$filter_cat  = sanitizeInput($_GET['category'] ?? '');
$filter_q    = sanitizeInput($_GET['q'] ?? '');

// Resolve the default RMM integration so sync labels name the real provider.
$rmm_default_provider_name = 'RMM';
if (!empty($config_rmm_default_integration_id)) {
    $rmm_intg = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT name, type FROM rmm_integrations WHERE id=" . intval($config_rmm_default_integration_id) . " LIMIT 1"
    ));
    if ($rmm_intg) {
        $rmm_default_provider_name = $rmm_intg['name'] ?: (
            ['tactical_rmm' => 'Tactical RMM', 'level' => 'Level.io',
             'action1' => 'Action1', 'sophos_central' => 'Sophos Central'][$rmm_intg['type']] ?? 'RMM'
        );
    }
}

// Handle add/edit/delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token'])) {
    if (!validateCSRFToken($_POST['csrf_token'])) { flash_alert('Invalid CSRF token', 'danger'); redirect(); }

    if (isset($_POST['save_script'])) {
        enforceUserPermission('module_rmm_scripts', 2);
        $sid         = intval($_POST['script_id'] ?? 0);
        $name        = sanitizeInput($_POST['name']);
        $category    = sanitizeInput($_POST['category']);
        $description = sanitizeInput($_POST['description']);
        $script_type = sanitizeInput($_POST['script_type']);
        $script_body = mysqli_real_escape_string($mysqli, $_POST['script_body'] ?? '');
        $tac_id      = intval($_POST['tactical_script_id'] ?? 0);
        $enabled     = isset($_POST['enabled']) ? 1 : 0;
        $name_esc    = mysqli_real_escape_string($mysqli, $name);
        $cat_esc     = mysqli_real_escape_string($mysqli, $category);
        $desc_esc    = mysqli_real_escape_string($mysqli, $description);
        $type_esc    = mysqli_real_escape_string($mysqli, $script_type);
        $tac_val     = $tac_id ? $tac_id : 'NULL';

        if ($sid > 0) {
            mysqli_query($mysqli, "UPDATE rmm_scripts SET name='$name_esc', category='$cat_esc', description='$desc_esc', script_type='$type_esc', script_body='$script_body', tactical_script_id=$tac_val, enabled=$enabled WHERE id=$sid");
            logAction('RMM', 'Script Edit', "$session_name edited script $name");
            flash_alert('Script updated');
        } else {
            mysqli_query($mysqli, "INSERT INTO rmm_scripts SET name='$name_esc', category='$cat_esc', description='$desc_esc', script_type='$type_esc', script_body='$script_body', tactical_script_id=$tac_val, enabled=$enabled, created_by=$session_user_id");
            logAction('RMM', 'Script Create', "$session_name created script $name");
            flash_alert('Script created');
        }
        redirect();
    }

    if (isset($_POST['delete_script'])) {
        enforceUserPermission('module_rmm_scripts', 3);
        $sid = intval($_POST['script_id']);
        $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT name FROM rmm_scripts WHERE id=$sid"));
        if ($row) {
            mysqli_query($mysqli, "DELETE FROM rmm_scripts WHERE id=$sid");
            logAction('RMM', 'Script Delete', "$session_name deleted script {$row['name']}");
            flash_alert('Script deleted');
        }
        redirect();
    }
}

// Build scripts query
$scripts_where = "1=1";
if ($filter_cat)  { $scripts_where .= " AND s.category='" . mysqli_real_escape_string($mysqli, $filter_cat) . "'"; }
if ($filter_q)    {
    $sq = mysqli_real_escape_string($mysqli, $filter_q);
    $scripts_where .= " AND (s.name LIKE '%$sq%' OR s.description LIKE '%$sq%')";
}

$sql_scripts = mysqli_query($mysqli,
    "SELECT s.*, u.user_name,
            (SELECT COUNT(*) FROM rmm_script_runs sr WHERE sr.script_id = s.id) as run_count,
            (SELECT COUNT(*) FROM rmm_script_runs sr WHERE sr.script_id = s.id AND sr.status='failed') as fail_count
     FROM rmm_scripts s
     LEFT JOIN users u ON u.user_id = s.created_by
     WHERE $scripts_where
     ORDER BY s.category, s.name"
);

// Category counts
$cat_counts = [];
$cr = mysqli_query($mysqli, "SELECT category, COUNT(*) as c FROM rmm_scripts GROUP BY category");
while ($r = mysqli_fetch_assoc($cr)) { $cat_counts[$r['category']] = $r['c']; }
$total_count = array_sum($cat_counts);

// Online assets for "Run on Asset" modal
$sql_online_assets = mysqli_query($mysqli,
    "SELECT arl.id as link_id, arl.tactical_agent_id, arl.hostname, a.asset_name, a.asset_id, c.client_name
     FROM asset_rmm_links arl
     JOIN assets a ON a.asset_id = arl.asset_id
     LEFT JOIN clients c ON c.client_id = a.asset_client_id
     WHERE arl.rmm_status='online' AND arl.tactical_agent_id IS NOT NULL AND arl.tactical_agent_id != ''
     ORDER BY c.client_name, arl.hostname"
);

// Recent runs
$sql_runs = mysqli_query($mysqli,
    "SELECT sr.*, s.name as sname, a.asset_name, a.asset_id, u.user_name
     FROM rmm_script_runs sr
     LEFT JOIN rmm_scripts s ON s.id = sr.script_id
     LEFT JOIN assets a ON a.asset_id = sr.asset_id
     LEFT JOIN users u ON u.user_id = sr.user_id
     ORDER BY sr.started_at DESC LIMIT 25"
);
?>

<div class="d-flex align-items-center mb-3">
    <h4 class="mb-0 mr-auto"><i class="fas fa-code me-2"></i>Script Library</h4>
    <?php if (lookupUserPermission('module_rmm_scripts') >= 1): ?>
    <button id="syncScriptsBtn" class="btn btn-info btn-sm me-2 js-sync-scripts">
        <i class="fas fa-cloud-download-alt me-1"></i>Sync from <?= nullable_htmlentities($rmm_default_provider_name) ?>
    </button>
    <button class="btn btn-primary btn-sm js-open-new-script" data-bs-toggle="modal" data-bs-target="#scriptModal">
        <i class="fas fa-plus me-1"></i>New Script
    </button>
    <?php endif; ?>
</div>
<div id="syncBar" class="alert alert-info d-none mb-3">
    <i class="fas fa-spinner fa-spin me-2"></i><span id="syncBarText">Syncing scripts...</span>
</div>

<!-- Category + search filter bar -->
<div class="card card-dark mb-2">
    <div class="card-body py-2">
        <form method="get" class="d-flex flex-wrap align-items-center" style="gap:6px">
            <div class="btn-group btn-group-sm me-2">
                <a href="?<?= $filter_q ? 'q='.urlencode($filter_q) : '' ?>"
                   class="btn <?= !$filter_cat ? 'btn-secondary' : 'btn-outline-secondary' ?>">
                    All <span class="badge text-bg-light ms-1"><?= $total_count ?></span>
                </a>
                <?php foreach ($categories as $cat): ?>
                <a href="?category=<?= urlencode($cat) ?><?= $filter_q ? '&q='.urlencode($filter_q) : '' ?>"
                   class="btn <?= $filter_cat === $cat ? 'btn-secondary' : 'btn-outline-secondary' ?>">
                    <?= $cat ?>
                    <?php if (isset($cat_counts[$cat])): ?>
                    <span class="badge text-bg-light ms-1"><?= $cat_counts[$cat] ?></span>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
            <input type="hidden" name="category" value="<?= htmlspecialchars($filter_cat) ?>">
            <input type="text" name="q" value="<?= htmlspecialchars($filter_q) ?>"
                   class="form-control form-control-sm" placeholder="Search scripts…" style="max-width:200px">
            <button type="submit" class="btn btn-sm btn-secondary"><i class="fas fa-search"></i></button>
            <?php if ($filter_cat || $filter_q): ?>
            <a href="?" class="btn btn-sm btn-outline-secondary"><i class="fas fa-times me-1"></i>Clear</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="card card-dark mb-3">
    <div class="card-body p-0">
        <?php if (mysqli_num_rows($sql_scripts) === 0): ?>
        <div class="text-center text-muted py-5">
            <i class="fas fa-code fa-3x mb-3"></i>
            <p class="mb-1">No scripts found.</p>
            <?php if (!$filter_cat && !$filter_q): ?>
            <p class="small">Click <strong>Sync from <?= nullable_htmlentities($rmm_default_provider_name) ?></strong> to import your script library, or add scripts manually.</p>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <table class="table table-sm table-hover mb-0">
            <thead class="text-muted border-bottom" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px">
                <tr>
                    <th class="ps-3">Name</th>
                    <th>Category</th>
                    <th>Type</th>
                    <th>Runs</th>
                    <th>Tact. ID</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php while ($scr = mysqli_fetch_assoc($sql_scripts)):
                $sid = intval($scr['id']);
                $type_icon = ['powershell'=>'fa-terminal','cmd'=>'fa-terminal','python'=>'fa-python','bash'=>'fa-linux'][$scr['script_type']] ?? 'fa-code';
                $type_label = ['powershell'=>'PowerShell','cmd'=>'CMD','python'=>'Python','bash'=>'Bash'][$scr['script_type']] ?? $scr['script_type'];
            ?>
            <tr>
                <td class="ps-3">
                    <div class="fw-bold"><?= nullable_htmlentities($scr['name']) ?></div>
                    <?php if ($scr['description']): ?>
                    <div class="text-muted small"><?= nullable_htmlentities(substr($scr['description'], 0, 80)) ?></div>
                    <?php endif; ?>
                </td>
                <td><span class="badge text-bg-secondary"><?= nullable_htmlentities($scr['category']) ?></span></td>
                <td class="small text-muted">
                    <i class="fas <?= $type_icon ?> me-1"></i><?= $type_label ?>
                </td>
                <td class="small text-center">
                    <?php if ($scr['run_count'] > 0): ?>
                    <span class="fw-bold"><?= intval($scr['run_count']) ?></span>
                    <?php if ($scr['fail_count'] > 0): ?>
                    <span class="text-danger small"> (<?= intval($scr['fail_count']) ?> failed)</span>
                    <?php endif; ?>
                    <?php else: ?>
                    <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <td class="small text-muted"><?= $scr['tactical_script_id'] ? intval($scr['tactical_script_id']) : '—' ?></td>
                <td><?= $scr['enabled']
                    ? '<span class="badge text-bg-success">Active</span>'
                    : '<span class="badge text-bg-secondary">Disabled</span>' ?>
                </td>
                <td class="text-end pe-2" style="white-space:nowrap">
                    <?php if (lookupUserPermission('module_rmm_scripts') >= 1): ?>
                    <button class="btn btn-xs btn-success me-1 js-open-run-modal" data-script='<?= json_encode(['id'=>$scr['id'],'name'=>$scr['name'],'tactical_script_id'=>$scr['tactical_script_id']], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>'
                            title="Run on Asset" <?= !$scr['tactical_script_id'] ? 'disabled title="No Tactical ID — sync scripts first"' : '' ?>>
                        <i class="fas fa-play"></i>
                    </button>
                    <?php if ($scr['script_body']): ?>
                    <button class="btn btn-xs btn-dark me-1 js-preview-script" data-script='<?= json_encode(['name'=>$scr['name'],'body'=>$scr['script_body'],'type'=>$scr['script_type']], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>'
                            title="View Script">
                        <i class="fas fa-eye"></i>
                    </button>
                    <?php endif; ?>
                    <button class="btn btn-xs btn-secondary me-1 js-edit-script" data-script='<?= json_encode($scr, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>' title="Edit">
                        <i class="fas fa-edit"></i>
                    </button>
                    <form method="post" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="script_id" value="<?= $sid ?>">
                        <button type="submit" name="delete_script" class="btn btn-xs btn-danger confirm-link" title="Delete">
                            <i class="fas fa-trash"></i>
                        </button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<!-- Recent Runs -->
<div class="card card-dark">
    <div class="card-header py-2 d-flex align-items-center">
        <h6 class="mb-0 mr-auto"><i class="fas fa-history me-2"></i>Recent Script Runs</h6>
    </div>
    <div class="card-body p-0">
    <?php if (mysqli_num_rows($sql_runs) === 0): ?>
        <p class="text-muted text-center py-3 mb-0 small">No script runs yet.</p>
    <?php else: ?>
    <table class="table table-sm table-hover mb-0">
        <thead class="text-muted border-bottom" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px">
            <tr>
                <th class="ps-3">Script</th>
                <th>Asset</th>
                <th>Run By</th>
                <th>Status</th>
                <th>Started</th>
                <th>Output Preview</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php while ($run = mysqli_fetch_assoc($sql_runs)):
            $run_badge = ['completed'=>'success','failed'=>'danger','running'=>'warning','pending'=>'secondary'][$run['status']] ?? 'secondary';
        ?>
        <tr>
            <td class="ps-3 small fw-bold"><?= nullable_htmlentities($run['sname'] ?? 'Manual') ?></td>
            <td class="small">
                <a href="/agent/asset_details.php?asset_id=<?= intval($run['asset_id']) ?>">
                    <?= nullable_htmlentities($run['asset_name']) ?>
                </a>
            </td>
            <td class="small text-muted"><?= nullable_htmlentities($run['user_name']) ?></td>
            <td><span class="badge badge-<?= $run_badge ?>"><?= $run['status'] ?></span></td>
            <td class="small text-muted" style="white-space:nowrap"><?= nullable_htmlentities(substr($run['started_at'], 0, 16)) ?></td>
            <td class="small" style="max-width:250px;overflow:hidden">
                <?php if ($run['output']): ?>
                <code class="text-muted" style="display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:250px;font-size:11px">
                    <?= nullable_htmlentities(substr(trim($run['output']), 0, 150)) ?>
                </code>
                <?php elseif ($run['error_message']): ?>
                <span class="text-danger small"><?= nullable_htmlentities(substr($run['error_message'], 0, 100)) ?></span>
                <?php else: ?>—<?php endif; ?>
            </td>
            <td>
                <a href="/agent/rmm_script_run.php?run_id=<?= intval($run['id']) ?>" class="btn btn-xs btn-secondary">
                    <i class="fas fa-eye"></i>
                </a>
            </td>
        </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
    <?php endif; ?>
    </div>
</div>

<!-- Script Add/Edit Modal -->
<div class="modal fade" id="scriptModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="scriptModalTitle">New Script</h5>
                <button type="button" class="close" data-bs-dismiss="modal">&times;</button>
            </div>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="script_id" id="edit_script_id" value="">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="text-muted small">Script Name</label>
                                <input type="text" class="form-control form-control-sm" name="name" id="s_name" required>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label class="text-muted small">Category</label>
                                <select class="form-control form-control-sm" name="category" id="s_category">
                                    <?php foreach ($categories as $cat): ?>
                                    <option><?= $cat ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label class="text-muted small">Script Type</label>
                                <select class="form-control form-control-sm" name="script_type" id="s_type">
                                    <option value="powershell">PowerShell</option>
                                    <option value="cmd">CMD / Batch</option>
                                    <option value="python">Python</option>
                                    <option value="bash">Bash</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="text-muted small">Description</label>
                        <input type="text" class="form-control form-control-sm" name="description" id="s_description">
                    </div>
                    <div class="form-group">
                        <label class="text-muted small">Tactical RMM Script ID</label>
                        <input type="number" class="form-control form-control-sm" name="tactical_script_id" id="s_tactical_id" placeholder="Leave blank to assign after syncing">
                    </div>
                    <div class="form-group">
                        <label class="text-muted small">Script Body <small class="text-muted">(stored for reference; execution happens via Tactical)</small></label>
                        <textarea class="form-control form-control-sm" name="script_body" id="s_body" rows="8" style="font-family:monospace;font-size:12px"></textarea>
                    </div>
                    <div class="form-check form-check form-switch">
                        <input type="checkbox" class="form-check-input" id="s_enabled" name="enabled" value="1" checked>
                        <label class="form-check-label" for="s_enabled">Enabled</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="save_script" class="btn btn-primary btn-sm">
                        <i class="fas fa-check me-1"></i>Save
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Script Preview Modal -->
<div class="modal fade" id="previewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title mb-0" id="previewModalTitle"></h6>
                <button type="button" class="close" data-bs-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body p-0">
                <pre id="previewBody" style="background:#111;color:#e0e0e0;font-size:12px;margin:0;padding:16px;max-height:500px;overflow-y:auto;border-radius:0 0 4px 4px"></pre>
            </div>
        </div>
    </div>
</div>

<!-- Run on Asset Modal -->
<div class="modal fade" id="runModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title mb-0"><i class="fas fa-play me-2"></i>Run Script on Asset</h6>
                <button type="button" class="close" data-bs-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-2">Script: <strong id="runScriptName"></strong></p>
                <div class="form-group mb-2">
                    <label class="text-muted small">Select Online Asset</label>
                    <select class="form-control form-control-sm" id="runAssetSelect">
                        <option value="">— Choose asset —</option>
                        <?php while ($oa = mysqli_fetch_assoc($sql_online_assets)): ?>
                        <option value="<?= intval($oa['link_id']) ?>">
                            <?= nullable_htmlentities($oa['client_name'] ? $oa['client_name'].' — ' : '') ?><?= nullable_htmlentities($oa['hostname'] ?: $oa['asset_name']) ?>
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div id="runModalMsg" class="mt-2"></div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success btn-sm js-run-script-now">
                    <i class="fas fa-play me-1"></i>Run Now
                </button>
            </div>
        </div>
    </div>
</div>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
const CSRF = '<?= $_SESSION['csrf_token'] ?>';
let _runScriptId = 0;

// Delegated wiring (CSP forbids inline onclick= attributes).
document.addEventListener('click', function (e) {
    var el;
    if ((el = e.target.closest('.js-sync-scripts'))) { syncScripts(); return; }
    if ((el = e.target.closest('.js-open-new-script'))) { openNewScript(); return; }
    if ((el = e.target.closest('.js-open-run-modal'))) { openRunModal(JSON.parse(el.dataset.script)); return; }
    if ((el = e.target.closest('.js-preview-script'))) { previewScript(JSON.parse(el.dataset.script)); return; }
    if ((el = e.target.closest('.js-edit-script'))) { editScript(JSON.parse(el.dataset.script)); return; }
    if ((el = e.target.closest('.js-run-script-now'))) { runScriptNow(); return; }
});

function openNewScript() {
    document.getElementById('scriptModalTitle').textContent = 'New Script';
    ['edit_script_id','s_name','s_description','s_body','s_tactical_id'].forEach(id => {
        document.getElementById(id).value = '';
    });
    document.getElementById('s_enabled').checked = true;
}

function editScript(scr) {
    document.getElementById('scriptModalTitle').textContent = 'Edit Script';
    document.getElementById('edit_script_id').value    = scr.id;
    document.getElementById('s_name').value            = scr.name;
    document.getElementById('s_description').value     = scr.description || '';
    document.getElementById('s_body').value            = scr.script_body || '';
    document.getElementById('s_tactical_id').value     = scr.tactical_script_id || '';
    document.getElementById('s_enabled').checked       = scr.enabled == 1;
    document.getElementById('s_category').value        = scr.category;
    document.getElementById('s_type').value            = scr.script_type;
    $('#scriptModal').modal('show');
}

function previewScript(scr) {
    document.getElementById('previewModalTitle').textContent = scr.name + ' (' + scr.type + ')';
    document.getElementById('previewBody').textContent = scr.body || '(no script body stored)';
    $('#previewModal').modal('show');
}

function openRunModal(scr) {
    _runScriptId = scr.id;
    document.getElementById('runScriptName').textContent = scr.name;
    document.getElementById('runAssetSelect').value = '';
    document.getElementById('runModalMsg').innerHTML = '';
    $('#runModal').modal('show');
}

function runScriptNow() {
    const linkId = document.getElementById('runAssetSelect').value;
    if (!linkId) { document.getElementById('runModalMsg').innerHTML = '<div class="alert alert-warning p-2 small">Please select an asset.</div>'; return; }
    const msg = document.getElementById('runModalMsg');
    msg.innerHTML = '<div class="text-muted small"><i class="fas fa-spinner fa-spin me-1"></i>Running…</div>';
    fetch('/agent/post/rmm_script_run.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'csrf_token=' + CSRF + '&script_id=' + _runScriptId + '&link_id=' + linkId
    }).then(r => r.json()).then(d => {
        if (d.success) {
            $('#runModal').modal('hide');
            window.location.href = '/agent/rmm_script_run.php?run_id=' + d.run_id;
        } else {
            msg.innerHTML = '<div class="alert alert-danger p-2 small">' + (d.error || 'Failed') + '</div>';
        }
    }).catch(() => {
        msg.innerHTML = '<div class="alert alert-danger p-2 small">Network error.</div>';
    });
}

function syncScripts() {
    const btn = document.getElementById('syncScriptsBtn');
    const bar = document.getElementById('syncBar');
    const txt = document.getElementById('syncBarText');
    btn.disabled = true;
    bar.classList.remove('d-none');
    bar.className = bar.className.replace(/alert-\w+/g, 'alert') + ' alert-info';
    txt.textContent = 'Importing scripts from <?= htmlspecialchars($rmm_default_provider_name, ENT_QUOTES) ?>...';
    fetch('/agent/post/rmm_sync.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'csrf_token=' + CSRF + '&action=sync_scripts&integration_id=<?= intval($config_rmm_default_integration_id) ?>'
    }).then(r => r.json()).then(d => {
        btn.disabled = false;
        if (d.success) {
            txt.textContent = `Sync complete: ${d.imported} new, ${d.updated} updated (${d.total} total from <?= htmlspecialchars($rmm_default_provider_name, ENT_QUOTES) ?>).`;
            bar.classList.replace('alert-info', 'alert-success');
            setTimeout(() => location.reload(), 2000);
        } else {
            txt.textContent = 'Sync failed: ' + (d.error || 'Unknown error');
            bar.classList.replace('alert-info', 'alert-danger');
        }
    }).catch(() => {
        btn.disabled = false;
        txt.textContent = 'Network error.';
        bar.classList.replace('alert-info', 'alert-danger');
    });
}
</script>

<?php require_once "../includes/footer.php"; ?>
