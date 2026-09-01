<?php
require_once "includes/inc_all.php";
enforceUserPermission('module_rmm');

$sql_policies = mysqli_query($mysqli,
    "SELECT p.*,
            (SELECT COUNT(*) FROM rmm_check_deployments d WHERE d.policy_id=p.id AND d.status='active') as deployed_count
     FROM rmm_check_policies p
     ORDER BY FIELD(p.platform,'windows','linux','macos','any'), p.check_type, p.name"
);

$sql_integrations = mysqli_query($mysqli,
    "SELECT id, name, type FROM rmm_integrations WHERE enabled=1 ORDER BY name"
);
$integrations = [];
while ($r = mysqli_fetch_assoc($sql_integrations)) { $integrations[] = $r; }

$default_intg = $config_rmm_default_integration_id ?: ($integrations[0]['id'] ?? 0);

$platform_meta = [
    'windows' => ['icon' => 'fab fa-windows', 'color' => 'info',    'label' => 'Windows'],
    'linux'   => ['icon' => 'fab fa-linux',   'color' => 'warning', 'label' => 'Linux'],
    'macos'   => ['icon' => 'fab fa-apple',   'color' => 'secondary','label' => 'macOS'],
    'any'     => ['icon' => 'fas fa-globe',   'color' => 'success', 'label' => 'All Platforms'],
];
$type_meta = [
    'diskspace' => ['icon' => 'fas fa-hdd',          'label' => 'Disk Space'],
    'cpuload'   => ['icon' => 'fas fa-microchip',    'label' => 'CPU Load'],
    'memory'    => ['icon' => 'fas fa-memory',       'label' => 'Memory'],
    'ping'      => ['icon' => 'fas fa-network-wired','label' => 'Ping'],
    'winsvc'    => ['icon' => 'fas fa-cogs',         'label' => 'Windows Service'],
    'eventlog'  => ['icon' => 'fas fa-clipboard-list','label' => 'Event Log'],
    'script'    => ['icon' => 'fas fa-code',         'label' => 'Script'],
];
?>

<div class="d-flex align-items-center mb-3">
    <h4 class="mb-0 mr-auto"><i class="fas fa-heartbeat me-2"></i>Alert Check Policies</h4>
    <button class="btn btn-primary btn-sm js-open-new-policy">
        <i class="fas fa-plus me-1"></i>New Policy
    </button>
</div>

<!-- Integration selector -->
<?php if (count($integrations) > 1): ?>
<div class="card card-dark mb-2">
    <div class="card-body py-2 d-flex align-items-center" style="gap:10px">
        <label class="mb-0 text-muted small">Push to integration:</label>
        <select id="activeIntegration" class="form-control form-control-sm js-integration-select" style="max-width:250px">
            <?php foreach ($integrations as $intg): ?>
            <option value="<?= intval($intg['id']) ?>" <?= $intg['id'] == $default_intg ? 'selected' : '' ?>>
                <?= nullable_htmlentities($intg['name']) ?>
                <?= $intg['type'] === 'level' ? '(Level.io)' : '(Tactical RMM)' ?>
            </option>
            <?php endforeach; ?>
        </select>
        <small class="text-muted">Check push is only executed for Tactical RMM integrations.</small>
    </div>
</div>
<?php endif; ?>

<div id="actionMsg" class="alert d-none mb-2"></div>

<!-- Platform groups -->
<?php
mysqli_data_seek($sql_policies, 0);
$policies_by_platform = [];
while ($pol = mysqli_fetch_assoc($sql_policies)) {
    $policies_by_platform[$pol['platform']][] = $pol;
}
$platform_order = ['windows', 'linux', 'macos', 'any'];
foreach ($platform_order as $plat):
    if (empty($policies_by_platform[$plat])) continue;
    $pm = $platform_meta[$plat];
?>
<div class="card card-dark mb-3" id="platform-card-<?= $plat ?>">
    <div class="card-header py-2 d-flex align-items-center">
        <h6 class="mb-0 mr-auto">
            <i class="<?= $pm['icon'] ?> me-2 text-<?= $pm['color'] ?>"></i>
            <?= $pm['label'] ?> Checks
        </h6>
        <button class="btn btn-xs btn-<?= $pm['color'] ?> js-push-all-policies" data-platform="<?= nullable_htmlentities($plat) ?>">
            <i class="fas fa-cloud-upload-alt me-1"></i>Push All <?= $pm['label'] ?> to Agents
        </button>
    </div>
    <div class="card-body p-0">
    <table class="table table-sm table-hover mb-0" style="table-layout:fixed">
        <thead class="text-muted border-bottom" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px">
            <tr>
                <th class="ps-3" style="width:26%">Check Name</th>
                <th class="text-center" style="width:16%;font-size:12px">Type</th>
                <th class="text-center" style="width:14%;font-size:12px">Thresholds</th>
                <th class="text-center" style="width:9%;font-size:12px">Interval</th>
                <th class="text-center" style="width:11%;font-size:12px">Deployed</th>
                <th class="text-center" style="width:10%;font-size:12px">Status</th>
                <th style="width:14%"></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($policies_by_platform[$plat] as $pol):
            $pid   = intval($pol['id']);
            $tm    = $type_meta[$pol['check_type']] ?? ['icon'=>'fas fa-check','label'=>$pol['check_type']];
            $params = json_decode($pol['check_params'] ?? '{}', true) ?? [];
        ?>
        <tr id="pol-row-<?= $pid ?>">
            <td class="ps-3">
                <div class="fw-bold"><?= nullable_htmlentities($pol['name']) ?></div>
                <?php if ($pol['description']): ?>
                <div class="text-muted small"><?= nullable_htmlentities($pol['description']) ?></div>
                <?php endif; ?>
            </td>
            <td class="text-center">
                <i class="<?= $tm['icon'] ?> me-1 text-muted"></i><?= $tm['label'] ?>
                <?php if (!empty($params['disk'])): ?>
                <code class="ms-1"><?= htmlspecialchars($params['disk']) ?>:</code>
                <?php elseif (!empty($params['svc_name'])): ?>
                <code class="ms-1"><?= htmlspecialchars($params['svc_name']) ?></code>
                <?php elseif (!empty($params['ip'])): ?>
                <code class="ms-1"><?= htmlspecialchars($params['ip']) ?></code>
                <?php endif; ?>
            </td>
            <td class="text-center">
                <?php if ($pol['warning_threshold']): ?>
                <span class="badge text-bg-warning" style="font-size:90%">Warn <?= intval($pol['warning_threshold']) ?>%</span>
                <?php endif; ?>
                <?php if ($pol['critical_threshold']): ?>
                <span class="badge text-bg-danger" style="font-size:90%">Crit <?= intval($pol['critical_threshold']) ?>%</span>
                <?php endif; ?>
                <?php if (!$pol['warning_threshold'] && !$pol['critical_threshold']): ?>
                <span class="text-muted">—</span>
                <?php endif; ?>
            </td>
            <td class="text-center text-muted"><?= intval($pol['check_interval']) ?>s</td>
            <td class="text-center">
                <?php if ($pol['deployed_count'] > 0): ?>
                <span class="badge text-bg-success" style="font-size:90%"><?= intval($pol['deployed_count']) ?> agents</span>
                <?php else: ?>
                <span class="text-muted">Not deployed</span>
                <?php endif; ?>
            </td>
            <td class="text-center">
                <?= $pol['enabled']
                    ? '<span class="badge text-bg-success" style="font-size:90%">Active</span>'
                    : '<span class="badge text-bg-secondary" style="font-size:90%">Disabled</span>' ?>
            </td>
            <td class="text-end pe-2" style="white-space:nowrap">
                <button class="btn btn-xs btn-success me-1 js-push-policy" title="Push to matching agents"
                        data-policy-id="<?= $pid ?>" data-policy-name="<?= nullable_htmlentities($pol['name']) ?>">
                    <i class="fas fa-cloud-upload-alt"></i>
                </button>
                <button class="btn btn-xs btn-secondary me-1 js-edit-policy" title="Edit"
                        data-policy='<?= json_encode($pol, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>'>
                    <i class="fas fa-edit"></i>
                </button>
                <button class="btn btn-xs btn-danger js-delete-policy" title="Delete"
                        data-policy-id="<?= $pid ?>" data-policy-name="<?= nullable_htmlentities($pol['name']) ?>">
                    <i class="fas fa-trash"></i>
                </button>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endforeach; ?>

<?php if (empty($policies_by_platform)): ?>
<div class="card card-dark">
    <div class="card-body text-center text-muted py-5">
        <i class="fas fa-heartbeat fa-3x mb-3"></i>
        <p>No check policies defined. Click <strong>New Policy</strong> to get started.</p>
    </div>
</div>
<?php endif; ?>

<!-- Confirm Action Modal -->
<div class="modal fade" id="confirmActionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title mb-0">Please Confirm</h6>
                <button type="button" class="close" data-bs-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body" id="confirmActionBody"></div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary btn-sm" id="confirmActionBtn">
                    <i class="fas fa-check me-1"></i>Confirm
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal fade" id="policyModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title mb-0" id="policyModalTitle">New Check Policy</h6>
                <button type="button" class="close" data-bs-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="edit_id" value="">
                <div class="form-group">
                    <label class="text-muted small">Policy Name</label>
                    <input type="text" class="form-control form-control-sm" id="p_name" placeholder="e.g. Disk Space C: Drive">
                </div>
                <div class="row">
                    <div class="col-6">
                        <div class="form-group">
                            <label class="text-muted small">Platform</label>
                            <select class="form-control form-control-sm js-update-params-hint" id="p_platform">
                                <option value="any">All Platforms</option>
                                <option value="windows">Windows</option>
                                <option value="linux">Linux</option>
                                <option value="macos">macOS</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-group">
                            <label class="text-muted small">Check Type</label>
                            <select class="form-control form-control-sm js-update-params-hint" id="p_type">
                                <option value="diskspace">Disk Space</option>
                                <option value="cpuload">CPU Load</option>
                                <option value="memory">Memory</option>
                                <option value="ping">Ping</option>
                                <option value="winsvc">Windows Service</option>
                                <option value="eventlog">Event Log</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="row" id="threshold_row">
                    <div class="col-6">
                        <div class="form-group">
                            <label class="text-muted small">Warning Threshold %</label>
                            <input type="number" class="form-control form-control-sm" id="p_warn" min="1" max="100" value="80">
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-group">
                            <label class="text-muted small">Critical Threshold %</label>
                            <input type="number" class="form-control form-control-sm" id="p_crit" min="1" max="100" value="90">
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label class="text-muted small">Check Interval (seconds)</label>
                    <input type="number" class="form-control form-control-sm" id="p_interval" value="120" min="30">
                </div>
                <div class="form-group">
                    <label class="text-muted small" id="p_params_label">Extra Parameters (JSON)</label>
                    <input type="text" class="form-control form-control-sm font-monospace" id="p_params"
                           placeholder='{"disk":"C"}'>
                    <small class="text-muted" id="p_params_hint">For disk checks: {"disk":"C"}. For service: {"svc_name":"spooler","restart_if_stopped":true}. For ping: {"ip":"8.8.8.8"}</small>
                </div>
                <div class="form-group">
                    <label class="text-muted small">Description</label>
                    <input type="text" class="form-control form-control-sm" id="p_description">
                </div>
                <div class="form-check form-check form-switch">
                    <input type="checkbox" class="form-check-input" id="p_enabled" checked>
                    <label class="form-check-label" for="p_enabled">Enabled</label>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary btn-sm js-save-policy">
                    <i class="fas fa-check me-1"></i>Save Policy
                </button>
            </div>
        </div>
    </div>
</div>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
const CSRF   = '<?= $_SESSION['csrf_token'] ?>';
let selectedIntegration = <?= intval($default_intg) ?>;

// Delegated wiring (CSP forbids inline onclick=/onchange= attributes).
document.addEventListener('click', function (e) {
    var el;
    if ((el = e.target.closest('.js-open-new-policy'))) { openNewPolicy(); return; }
    if ((el = e.target.closest('.js-push-all-policies'))) { pushAll(el.dataset.platform); return; }
    if ((el = e.target.closest('.js-push-policy'))) { pushPolicy(parseInt(el.dataset.policyId, 10), el.dataset.policyName); return; }
    if ((el = e.target.closest('.js-edit-policy'))) { editPolicy(JSON.parse(el.dataset.policy)); return; }
    if ((el = e.target.closest('.js-delete-policy'))) { deletePolicy(parseInt(el.dataset.policyId, 10), el.dataset.policyName); return; }
    if ((el = e.target.closest('.js-save-policy'))) { savePolicy(); return; }
});
document.addEventListener('change', function (e) {
    if (e.target.closest('.js-integration-select')) { selectedIntegration = e.target.value; return; }
    if (e.target.closest('.js-update-params-hint')) { updateParamsHint(); }
});

function showMsg(text, type) {
    const el = document.getElementById('actionMsg');
    el.className = 'alert alert-' + type + ' mb-2';
    el.textContent = text;
    el.classList.remove('d-none');
    setTimeout(() => el.classList.add('d-none'), 5000);
}

let _confirmActionCallback = null;
document.getElementById('confirmActionBtn').addEventListener('click', function() {
    $('#confirmActionModal').modal('hide');
    const cb = _confirmActionCallback;
    _confirmActionCallback = null;
    if (cb) cb();
});

function confirmAction(message, callback) {
    document.getElementById('confirmActionBody').textContent = message;
    _confirmActionCallback = callback;
    $('#confirmActionModal').modal('show');
}

function doPushPolicy(policyId, cb) {
    fetch('/agent/post/rmm_check.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `csrf_token=${CSRF}&action=push_policy&policy_id=${policyId}&integration_id=${selectedIntegration}`
    }).then(r => r.json()).then(cb);
}

function pushPolicy(policyId, name) {
    confirmAction('Push "' + name + '" to all matching agents in selected integration?', () => {
        showMsg('Pushing checks…', 'info');
        doPushPolicy(policyId, d => {
            if (d.success) {
                let msg = `Pushed to ${d.pushed} agent(s), ${d.skipped} already deployed.`;
                if (d.errors && d.errors.length) msg += ' Errors: ' + d.errors.join('; ');
                showMsg(msg, d.errors && d.errors.length ? 'warning' : 'success');
                setTimeout(() => location.reload(), 1500);
            } else {
                showMsg('Failed: ' + (d.error || 'Unknown error'), 'danger');
            }
        });
    });
}

function pushAll(platform) {
    const card = document.getElementById('platform-card-' + platform);
    if (!card) return;
    const buttons = card.querySelectorAll('.btn-success[data-policy-id]');
    if (!buttons.length) return;

    confirmAction('Push all ' + buttons.length + ' ' + platform + ' check policies to matching agents?', () => {
        showMsg('Pushing checks…', 'info');
        let remaining = buttons.length;
        let totalPushed = 0, totalSkipped = 0;
        const errors = [];

        buttons.forEach(btn => {
            const pid = btn.getAttribute('data-policy-id');
            doPushPolicy(pid, d => {
                if (d.success) {
                    totalPushed += d.pushed;
                    totalSkipped += d.skipped;
                    if (d.errors && d.errors.length) errors.push(...d.errors);
                } else {
                    errors.push(d.error || 'Unknown error');
                }
                remaining--;
                if (remaining === 0) {
                    let msg = `Pushed to ${totalPushed} agent(s) total, ${totalSkipped} already deployed.`;
                    if (errors.length) msg += ' Errors: ' + errors.join('; ');
                    showMsg(msg, errors.length ? 'warning' : 'success');
                    setTimeout(() => location.reload(), 1500);
                }
            });
        });
    });
}

function deletePolicy(id, name) {
    confirmAction('Delete policy "' + name + '" and all its deployments?', () => {
        fetch('/agent/post/rmm_check.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `csrf_token=${CSRF}&action=delete_policy&policy_id=${id}`
        }).then(r => r.json()).then(d => {
            if (d.success) {
                document.getElementById('pol-row-' + id)?.remove();
                showMsg('Policy deleted.', 'success');
            } else {
                showMsg('Failed: ' + d.error, 'danger');
            }
        });
    });
}

function openNewPolicy() {
    document.getElementById('policyModalTitle').textContent = 'New Check Policy';
    document.getElementById('edit_id').value = '';
    document.getElementById('p_name').value = '';
    document.getElementById('p_platform').value = 'windows';
    document.getElementById('p_type').value = 'diskspace';
    document.getElementById('p_warn').value = '80';
    document.getElementById('p_crit').value = '90';
    document.getElementById('p_interval').value = '120';
    document.getElementById('p_params').value = '{"disk":"C"}';
    document.getElementById('p_description').value = '';
    document.getElementById('p_enabled').checked = true;
    updateParamsHint();
    $('#policyModal').modal('show');
}

function editPolicy(pol) {
    document.getElementById('policyModalTitle').textContent = 'Edit Policy';
    document.getElementById('edit_id').value       = pol.id;
    document.getElementById('p_name').value        = pol.name;
    document.getElementById('p_platform').value    = pol.platform;
    document.getElementById('p_type').value        = pol.check_type;
    document.getElementById('p_warn').value        = pol.warning_threshold || '';
    document.getElementById('p_crit').value        = pol.critical_threshold || '';
    document.getElementById('p_interval').value    = pol.check_interval;
    document.getElementById('p_params').value      = pol.check_params || '{}';
    document.getElementById('p_description').value = pol.description || '';
    document.getElementById('p_enabled').checked   = pol.enabled == 1;
    updateParamsHint();
    $('#policyModal').modal('show');
}

function updateParamsHint() {
    const type = document.getElementById('p_type').value;
    const hints = {
        diskspace: '{"disk":"C"}  — use "/" for Linux/macOS',
        cpuload:   'No extra params needed',
        memory:    'No extra params needed',
        ping:      '{"ip":"8.8.8.8"}',
        winsvc:    '{"svc_name":"spooler","restart_if_stopped":true}',
        eventlog:  '{"log_name":"Application","event_type":"ERROR","fail_count":1,"search_last_days":1}',
    };
    document.getElementById('p_params_hint').textContent = hints[type] || '';
    const noThresh = ['ping','winsvc','eventlog'].includes(type);
    document.getElementById('threshold_row').style.display = noThresh ? 'none' : '';
}

function savePolicy() {
    const params = {
        csrf_token:         CSRF,
        action:             'save_policy',
        edit_id:            document.getElementById('edit_id').value,
        name:               document.getElementById('p_name').value,
        platform:           document.getElementById('p_platform').value,
        check_type:         document.getElementById('p_type').value,
        warning_threshold:  document.getElementById('p_warn').value,
        critical_threshold: document.getElementById('p_crit').value,
        check_interval:     document.getElementById('p_interval').value,
        check_params:       document.getElementById('p_params').value,
        description:        document.getElementById('p_description').value,
        enabled:            document.getElementById('p_enabled').checked ? '1' : '',
    };
    fetch('/agent/post/rmm_check.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams(params).toString()
    }).then(r => r.json()).then(d => {
        if (d.success) {
            $('#policyModal').modal('hide');
            showMsg('Policy saved.', 'success');
            setTimeout(() => location.reload(), 800);
        } else {
            showMsg('Failed: ' + (d.error || 'Unknown error'), 'danger');
        }
    });
}
</script>

<?php require_once "../includes/footer.php"; ?>
