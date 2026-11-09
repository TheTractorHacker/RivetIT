<?php
require_once "includes/inc_all.php";
enforceUserPermission('module_client');

// Filter params
$filter_compliance = sanitizeInput($_GET['compliance'] ?? '');
$filter_client_id  = intval($_GET['client_id'] ?? 0);

// Build WHERE clause
$where = "1=1";
if ($filter_compliance) { $where .= " AND LOWER(ail.compliance_state)='" . strtolower($filter_compliance) . "'"; }
if ($filter_client_id)  { $where .= " AND a.asset_client_id=$filter_client_id"; }
// Client-restricted techs should only see assets for clients they have access to
if ($client_access_string && !$session_is_admin) { $where .= " AND a.asset_client_id IN ($client_access_string)"; }

$sql_links = mysqli_query($mysqli,
    "SELECT ail.*, a.asset_id, a.asset_name, a.asset_type, a.asset_client_id, c.client_name
     FROM asset_intune_links ail
     JOIN assets a ON a.asset_id = ail.asset_id
     LEFT JOIN clients c ON c.client_id = a.asset_client_id
     WHERE $where
     ORDER BY (LOWER(ail.compliance_state)='compliant') ASC, ail.hostname ASC"
);

$intune_link_rows = [];
while ($row = mysqli_fetch_assoc($sql_links)) {
    $intune_link_rows[] = $row;
}

// Counts for stat cards
$cnt = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT
       SUM(LOWER(compliance_state)='compliant') as compliant,
       SUM(LOWER(compliance_state)='noncompliant') as noncompliant,
       COUNT(*) as total
     FROM asset_intune_links ail
     JOIN assets a ON a.asset_id = ail.asset_id
     WHERE 1=1" . ($client_access_string && !$session_is_admin ? " AND a.asset_client_id IN ($client_access_string)" : "")
));

$sql_clients = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL" . ($client_access_string && !$session_is_admin ? " AND client_id IN ($client_access_string)" : '') . " ORDER BY client_name ASC");
$clients_list = [];
while ($c = mysqli_fetch_assoc($sql_clients)) $clients_list[] = $c;
?>

<div class="d-flex align-items-center mb-3">
    <h4 class="mb-0 mr-auto"><i class="fab fa-microsoft me-2"></i>Intune Devices</h4>
    <a href="/admin/settings_integrations.php?tab=directorysync" class="btn btn-secondary btn-sm">
        <i class="fas fa-cog me-1"></i>Settings
    </a>
</div>

<!-- Stat cards -->
<div class="row mb-3">
    <div class="col-md-4">
        <div class="small-box bg-success">
            <div class="inner"><h3><?= intval($cnt['compliant'] ?? 0) ?></h3><p>Compliant</p></div>
            <div class="icon"><i class="fas fa-check-circle"></i></div>
            <a href="?compliance=compliant" class="small-box-footer">Filter <i class="fas fa-arrow-circle-right"></i></a>
        </div>
    </div>
    <div class="col-md-4">
        <div class="small-box bg-danger">
            <div class="inner"><h3><?= intval($cnt['noncompliant'] ?? 0) ?></h3><p>Not Compliant</p></div>
            <div class="icon"><i class="fas fa-times-circle"></i></div>
            <a href="?compliance=noncompliant" class="small-box-footer">Filter <i class="fas fa-arrow-circle-right"></i></a>
        </div>
    </div>
    <div class="col-md-4">
        <div class="small-box bg-info">
            <div class="inner"><h3><?= intval($cnt['total'] ?? 0) ?></h3><p>Total Devices</p></div>
            <div class="icon"><i class="fab fa-microsoft"></i></div>
            <a href="?" class="small-box-footer">Show All <i class="fas fa-arrow-circle-right"></i></a>
        </div>
    </div>
</div>

<!-- Filter bar -->
<div class="card card-dark mb-2">
    <div class="card-body py-2">
        <form method="get" class="d-flex flex-wrap align-items-center gap-2">
            <select name="client_id" class="form-select auto-submit-select w-auto">
                <option value="">All Departments</option>
                <?php foreach ($clients_list as $cl): ?>
                <option value="<?= intval($cl['client_id']) ?>" <?= $filter_client_id == $cl['client_id'] ? 'selected' : '' ?>>
                    <?= nullable_htmlentities($cl['client_name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <select name="compliance" class="form-select auto-submit-select w-auto">
                <option value="">All Compliance States</option>
                <option value="compliant"    <?= $filter_compliance === 'compliant'    ? 'selected' : '' ?>>Compliant</option>
                <option value="noncompliant" <?= $filter_compliance === 'noncompliant' ? 'selected' : '' ?>>Not Compliant</option>
            </select>
            <?php if ($filter_compliance || $filter_client_id): ?>
                <a href="?" class="btn btn-secondary">Clear Filters</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Device table -->
<div class="card card-dark">
    <div class="card-body p-0">
        <?php if (count($intune_link_rows) === 0): ?>
            <div class="text-center text-muted py-5">
                <i class="fab fa-microsoft fa-3x mb-3"></i>
                <p>No Intune devices found. Sync devices from <a href="/admin/settings_integrations.php?tab=directorysync">Microsoft integration settings</a>.</p>
            </div>
        <?php else: ?>
        <div class="table-responsive"><table class="table table-hover table-sm mb-0" id="intune-devices-table" style="min-width:760px;">
            <thead class="text-muted small border-bottom" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;">
                <tr>
                    <th class="ps-3">Compliance</th>
                    <th>Hostname</th>
                    <th>Department</th>
                    <th>OS</th>
                    <th>Primary User</th>
                    <th>Intune Sync</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($intune_link_rows as $row):
                $compliance = strtolower((string) ($row['compliance_state'] ?? ''));
                if ($compliance === 'compliant') {
                    $badge = 'text-bg-success'; $icon = 'fa-circle text-success'; $label = 'Compliant';
                } elseif ($compliance === 'noncompliant') {
                    $badge = 'text-bg-danger'; $icon = 'fa-circle text-danger'; $label = 'Not Compliant';
                } else {
                    $badge = 'text-bg-secondary'; $icon = 'fa-question-circle text-muted';
                    $label = $row['compliance_state'] ? nullable_htmlentities(ucfirst(trim(preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', (string) $row['compliance_state'])))) : 'Unknown';
                }
            ?>
            <tr>
                <td class="ps-3">
                    <span class="badge <?= $badge ?>"><i class="fas <?= $compliance === 'compliant' ? 'fa-check' : ($compliance === 'noncompliant' ? 'fa-times' : 'fa-question') ?> me-1" aria-hidden="true"></i><?= $label ?></span>
                </td>
                <td>
                    <a href="/agent/asset_details.php?client_id=<?= intval($row['asset_client_id']) ?>&asset_id=<?= intval($row['asset_id']) ?>" class="fw-bold">
                        <?= nullable_htmlentities($row['hostname']) ?>
                    </a>
                </td>
                <td>
                    <?php if ($row['asset_client_id']): ?>
                        <a href="/agent/client_overview.php?client_id=<?= intval($row['asset_client_id']) ?>">
                            <?= nullable_htmlentities($row['client_name']) ?>
                        </a>
                    <?php else: ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <td class="text-muted small"><?= nullable_htmlentities(trim(($row['os_name'] ?? '') . ' ' . ($row['os_version'] ?? ''))) ?: '—' ?></td>
                <td class="text-muted small"><?= nullable_htmlentities($row['primary_user_upn']) ?: '—' ?></td>
                <td class="text-muted small">
                    <?php
                    if ($row['intune_last_sync_at']) {
                        $sync_ts = strtotime($row['intune_last_sync_at']);
                        $sync_days = $sync_ts ? (int) floor((time() - $sync_ts) / 86400) : null;
                        $sync_rel = $sync_days === null ? '' : ($sync_days < 1 ? 'today' : ($sync_days === 1 ? 'yesterday' : $sync_days . ' days ago'));
                        ?><span title="<?= nullable_htmlentities($row['intune_last_sync_at']) ?>"><?= $sync_rel !== '' ? $sync_rel : nullable_htmlentities($row['intune_last_sync_at']) ?></span><?php
                    } else { echo '—'; } ?>
                </td>
                <td class="text-end pe-2">
                    <a href="/agent/asset_details.php?client_id=<?= intval($row['asset_client_id']) ?>&asset_id=<?= intval($row['asset_id']) ?>" class="btn btn-sm btn-outline-primary" title="Open this asset">
                        Open asset<i class="fas fa-arrow-right ms-1" aria-hidden="true"></i>
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
</div>

<?php require_once "../includes/footer.php"; ?>
