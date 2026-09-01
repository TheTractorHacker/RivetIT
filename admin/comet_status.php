<?php
require_once "includes/inc_all_admin.php";
require_once "../includes/comet.php";

if (!$config_comet_enabled) {
    flash_alert('Comet integration is not enabled. Configure it under Settings → Comet Backup.', 'warning');
    redirect('settings_comet.php');
}

// Load all Comet users and build a client-name lookup from mappings
$comet_users = comet_get_users();
$maps_sql    = mysqli_query($mysqli, "SELECT m.map_comet_username, c.client_id, c.client_name FROM comet_client_map m JOIN clients c ON m.map_client_id = c.client_id");
$client_for  = []; // comet_username => ['client_id'=>, 'client_name'=>]
while ($m = mysqli_fetch_assoc($maps_sql)) {
    $client_for[$m['map_comet_username']] = ['id' => intval($m['client_id']), 'name' => nullable_htmlentities($m['client_name'])];
}

// Counts for summary bar
$total_devices = 0; $ok = 0; $warn = 0; $fail = 0; $unknown = 0;
$device_rows   = []; // flat list for the table

if ($comet_users) {
    foreach ($comet_users as $username => $userdata) {
        $last_jobs = comet_last_jobs_per_device($username);
        $devices   = $userdata['Devices'] ?? [];
        foreach ($devices as $devid => $devdata) {
            // Jobs are keyed by Comet's DeviceID (the same $devid here), not
            // by friendly name — BackupJobDetail has no name field at all.
            $dev_name = $devdata['FriendlyName'] ?? $devid;
            $job      = $last_jobs[$devid] ?? null;
            $status   = $job ? intval($job['Status']) : 0;
            $total_devices++;
            // Use the real Comet SDK status codes (see includes/comet.php),
            // not made-up ones — 5001/5003/5004/5007 never match an actual
            // job status, so every real success/failure fell through to the
            // "Unknown" bucket below despite the per-row badge (which does
            // use the correct helpers) showing the right status.
            if (!$job)                              $unknown++;
            elseif (comet_is_success($status))      $ok++;
            elseif ($status === COMET_JOB_FAILED_WARNING) $warn++;
            elseif (comet_is_failed($status))       $fail++;
            else                                    $unknown++;
            $device_rows[] = [
                'username'    => $username,
                'dev_name'    => $dev_name,
                'status'      => $status,
                'start'       => $job ? intval($job['StartTime']) : 0,
                'end'         => $job ? intval($job['EndTime'])   : 0,
                'size'        => $job ? intval($job['UploadSize'] ?? $job['TotalSize'] ?? 0) : 0,
                'error'       => $job['ErrorString'] ?? '',
                'job_type'    => $job ? intval($job['Classification'] ?? 4001) : 0,
                'client'      => $client_for[$username] ?? null,
            ];
        }
    }
    // Sort: failures first, then warnings, then unknown, then success; within each group newest-last-job first
    usort($device_rows, function($a, $b) {
        $ao = comet_status_sort_rank($a['status'], $a['status'] > 0);
        $bo = comet_status_sort_rank($b['status'], $b['status'] > 0);
        return $ao !== $bo ? $ao - $bo : $b['start'] - $a['start'];
    });
}
?>

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

<!-- Device table -->
<div class="card card-dark">
    <div class="card-header py-2 d-flex align-items-center">
        <h3 class="card-title mr-auto"><i class="fas fa-fw fa-cloud-upload-alt me-2"></i>Device Backup Status</h3>
        <a href="settings_comet.php" class="btn btn-sm btn-outline-secondary">
            <i class="fas fa-cog me-1"></i>Settings
        </a>
    </div>
    <div class="card-body p-0">
        <?php if (!$comet_users): ?>
            <div class="text-center text-muted py-4">
                <i class="fas fa-exclamation-triangle fa-2x mb-2 d-block"></i>
                Could not reach Comet server. Check connection settings.
            </div>
        <?php elseif (empty($device_rows)): ?>
            <div class="text-center text-muted py-4">No devices found in Comet.</div>
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
                    <td class="fw-bold small"><?= htmlspecialchars($dr['dev_name']) ?></td>
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
