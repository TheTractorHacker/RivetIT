<?php
require_once "includes/inc_all.php";
enforceUserPermission('module_rmm');
require_once "../includes/comet.php";

if (!$config_comet_enabled) {
    ?>
    <div class="card card-dark">
        <div class="card-body text-center text-muted py-5">
            <i class="fas fa-cloud-upload-alt fa-3x mb-3 d-block"></i>
            Comet Backup integration is not enabled.<br>Ask an admin to configure it under Settings &rarr; Comet Backup.
        </div>
    </div>
    <?php
    require_once "../includes/footer.php";
    exit;
}

// Load all Comet users and build a client-name lookup from mappings
$comet_users = comet_get_users();
$maps_sql    = mysqli_query($mysqli, "SELECT m.map_comet_username, c.client_id, c.client_name FROM comet_client_map m JOIN clients c ON m.map_client_id = c.client_id");
$client_for  = []; // comet_username => ['id'=>, 'name'=>]
while ($m = mysqli_fetch_assoc($maps_sql)) {
    $client_for[$m['map_comet_username']] = ['id' => intval($m['client_id']), 'name' => nullable_htmlentities($m['client_name'])];
}

// Open backup alerts per device, so the table can flag a device that's
// already been escalated (and the dashboard can link straight into the
// unified Alerts page instead of just guessing from current status).
$open_alerts_sql = mysqli_query($mysqli,
    "SELECT alert_device_name, alert_type, alert_ticket_id FROM comet_backup_alerts WHERE alert_resolved_at IS NULL"
);
$open_alert_for = [];
while ($oa = mysqli_fetch_assoc($open_alerts_sql)) {
    $open_alert_for[$oa['alert_device_name']] = $oa;
}
$open_alert_count = count($open_alert_for);

$filter_client_id = intval($_GET['client_id'] ?? 0);
$filter_search     = trim((string) ($_GET['q'] ?? ''));

// Client-scope restriction, matching agent/alerts.php's pattern: a tech restricted via
// user_client_permissions must only see backup devices for their permitted clients.
$access_client_ids = ($client_access_string && !$session_is_admin) ? array_map('intval', explode(',', $client_access_string)) : null;

// Counts for summary bar
$total_devices = 0; $ok = 0; $warn = 0; $fail = 0; $unknown = 0;
$device_rows   = []; // flat list for the table

if ($comet_users) {
    foreach ($comet_users as $username => $userdata) {
        $last_jobs = comet_last_jobs_per_device($username);
        $devices   = $userdata['Devices'] ?? [];
        $client    = $client_for[$username] ?? null;

        // Client filter applies before counting, so the summary boxes match what's shown
        if ($filter_client_id && (!$client || $client['id'] !== $filter_client_id)) {
            continue;
        }

        // Client-scope restriction (see $access_client_ids above) - applied before
        // counting for the same reason as the explicit filter above.
        if ($access_client_ids !== null && (!$client || !in_array($client['id'], $access_client_ids, true))) {
            continue;
        }

        foreach ($devices as $devid => $devdata) {
            // Jobs are keyed by Comet's DeviceID (the same $devid here), not
            // by friendly name — BackupJobDetail has no name field at all.
            $dev_name = $devdata['FriendlyName'] ?? $devid;

            if ($filter_search !== '' && stripos($dev_name . ' ' . $username . ' ' . ($client['name'] ?? ''), $filter_search) === false) {
                continue;
            }

            $job      = $last_jobs[$devid] ?? null;
            $status   = $job ? intval($job['Status']) : 0;
            $total_devices++;
            if (!$job)                                    $unknown++;
            elseif (comet_is_success($status))            $ok++;
            elseif ($status === COMET_JOB_FAILED_WARNING)  $warn++;
            elseif (comet_is_failed($status))              $fail++;
            else                                           $unknown++;
            $device_rows[] = [
                'username'    => $username,
                'dev_name'    => $dev_name,
                'status'      => $status,
                'start'       => $job ? intval($job['StartTime']) : 0,
                'end'         => $job ? intval($job['EndTime'])   : 0,
                'size'        => $job ? intval($job['UploadSize'] ?? $job['TotalSize'] ?? 0) : 0,
                'error'       => $job['ErrorString'] ?? '',
                'job_type'    => $job ? intval($job['Classification'] ?? 4001) : 0,
                'client'      => $client,
                'open_alert'  => $open_alert_for[$dev_name] ?? null,
            ];
        }
    }
    // Sort: open alerts first (missed/failed), then failures, warnings, unknown, success
    usort($device_rows, function($a, $b) {
        $alert_rank = fn($r) => $r['open_alert'] ? 0 : 1;
        $aa = $alert_rank($a); $bb = $alert_rank($b);
        if ($aa !== $bb) return $aa - $bb;
        $ao = comet_status_sort_rank($a['status'], $a['status'] > 0);
        $bo = comet_status_sort_rank($b['status'], $b['status'] > 0);
        return $ao !== $bo ? $ao - $bo : $b['start'] - $a['start'];
    });
}

$sql_clients = mysqli_query($mysqli, "SELECT DISTINCT client_id, client_name FROM clients WHERE client_archived_at IS NULL ORDER BY client_name");
?>

<div class="d-flex align-items-center mb-3">
    <h4 class="mb-0 mr-auto"><i class="fas fa-cloud-upload-alt me-2"></i>Backup Dashboard</h4>
    <?php if ($open_alert_count > 0) { ?>
    <a href="/agent/alerts.php?source=backup&status=new" class="btn btn-sm btn-outline-danger">
        <i class="fas fa-bell me-1"></i><?= $open_alert_count ?> Open Alert<?= $open_alert_count !== 1 ? 's' : '' ?>
    </a>
    <?php } ?>
</div>

<!-- Summary row -->
<div class="row mb-3">
    <?php foreach ([
        ['label'=>'Total Devices', 'val'=>$total_devices,  'icon'=>'desktop',         'color'=>'primary'],
        ['label'=>'Healthy',       'val'=>$ok,             'icon'=>'check-circle',    'color'=>'success'],
        ['label'=>'Warnings',      'val'=>$warn,           'icon'=>'exclamation-triangle','color'=>'warning'],
        ['label'=>'Failed',        'val'=>$fail,           'icon'=>'times-circle',    'color'=>'danger'],
        ['label'=>'Unknown',       'val'=>$unknown,        'icon'=>'question-circle', 'color'=>'secondary'],
    ] as $s): ?>
    <div class="col">
        <div class="info-box shadow-sm mb-0">
            <span class="info-box-icon bg-<?= $s['color'] ?>" style="font-size:1.4rem;">
                <i class="fas fa-<?= $s['icon'] ?>"></i>
            </span>
            <div class="info-box-content">
                <span class="info-box-text"><?= $s['label'] ?></span>
                <span class="info-box-number"><?= $s['val'] ?></span>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Filter bar -->
<div class="card card-dark mb-2">
    <div class="card-body py-2">
        <form method="get" class="d-flex flex-wrap align-items-center" style="gap:6px">
            <?php if (mysqli_num_rows($sql_clients) > 0) { ?>
            <select name="client_id" class="form-control form-control-sm me-2 auto-submit-select" style="max-width:200px">
                <option value="">All Clients</option>
                <?php while ($cl = mysqli_fetch_assoc($sql_clients)) { ?>
                <option value="<?= $cl['client_id'] ?>" <?= $filter_client_id == $cl['client_id'] ? 'selected' : '' ?>>
                    <?= nullable_htmlentities($cl['client_name']) ?>
                </option>
                <?php } ?>
            </select>
            <?php } ?>
            <input type="text" name="q" value="<?= htmlspecialchars($filter_search) ?>" class="form-control form-control-sm me-2" placeholder="Search device, user, client…" style="max-width:220px">
            <button type="submit" class="btn btn-sm btn-secondary me-2"><i class="fas fa-search"></i></button>
            <?php if ($filter_client_id || $filter_search !== '') { ?>
            <a href="?" class="btn btn-sm btn-outline-secondary"><i class="fas fa-times me-1"></i>Clear</a>
            <?php } ?>
        </form>
    </div>
</div>

<!-- Device table -->
<div class="card card-dark">
    <div class="card-header py-2 d-flex align-items-center">
        <h3 class="card-title mr-auto"><i class="fas fa-fw fa-cloud-upload-alt me-2"></i>Device Backup Status</h3>
    </div>
    <div class="card-body p-0">
        <?php if (!$comet_users): ?>
            <div class="text-center text-muted py-4">
                <i class="fas fa-exclamation-triangle fa-2x mb-2 d-block"></i>
                Could not reach Comet server. Contact an admin to check connection settings.
            </div>
        <?php elseif (empty($device_rows)): ?>
            <div class="text-center text-muted py-4">No devices match your filters.</div>
        <?php else: ?>
        <table class="table table-sm table-borderless table-hover mb-0">
            <thead style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;" class="text-muted border-bottom">
                <tr>
                    <th class="ps-3" style="width:36px;"></th>
                    <th>Device</th>
                    <th>Comet User</th>
                    <th>Client</th>
                    <th>Status</th>
                    <th>Last Backup</th>
                    <th>Size</th>
                    <th>Error</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($device_rows as $dr):
                $sc  = comet_status_class($dr['status']);
                $sl  = comet_status_label($dr['status']);
                $si  = comet_status_icon($dr['status']);
                $ago = $dr['start'] ? timeAgo(date('Y-m-d H:i:s', $dr['start'])) : '—';
                $full= $dr['start'] ? date('Y-m-d H:i', $dr['start']) : '';
                $sz  = $dr['size'] ? comet_fmt_bytes($dr['size']) : '—';
                $err = $dr['error'] ? htmlspecialchars(mb_strimwidth($dr['error'], 0, 80, '…')) : '';
            ?>
                <tr>
                    <td class="ps-3 text-center">
                        <i class="fas fa-<?= $si ?> text-<?= $sc ?>"></i>
                    </td>
                    <td class="fw-bold small">
                        <?= htmlspecialchars($dr['dev_name']) ?>
                        <?php if ($dr['open_alert']) { ?>
                        <a href="/agent/ticket.php?ticket_id=<?= intval($dr['open_alert']['alert_ticket_id']) ?>"
                           class="badge text-bg-danger ms-1" title="Open <?= $dr['open_alert']['alert_type'] === 'missed' ? 'missed-backup' : 'failed-backup' ?> alert — click to view ticket">
                            <i class="fas fa-bell"></i>
                        </a>
                        <?php } ?>
                    </td>
                    <td class="text-muted small"><?= htmlspecialchars($dr['username']) ?></td>
                    <td class="small">
                        <?php if ($dr['client']): ?>
                            <a href="/agent/client_overview.php?client_id=<?= $dr['client']['id'] ?>">
                                <?= $dr['client']['name'] ?>
                            </a>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge badge-<?= $sc ?>"><?= $sl ?></span>
                    </td>
                    <td class="text-muted small" title="<?= $full ?>"><?= $ago ?></td>
                    <td class="text-muted small"><?= $sz ?></td>
                    <td class="text-danger small"><?= $err ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php require_once "../includes/footer.php"; ?>
