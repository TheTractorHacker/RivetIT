<?php
require_once "includes/inc_all_admin.php";

$backup_dir  = $_SERVER['DOCUMENT_ROOT'] . '/backups';
$all_backups = glob($backup_dir . '/itflow_*.zip') ?: [];
usort($all_backups, fn($a, $b) => filemtime($b) - filemtime($a));

$total         = count($all_backups);
$last_backup   = $total ? filemtime($all_backups[0]) : null;
$last_auto     = null;
$last_manual   = null;
$total_size_b  = 0;

foreach ($all_backups as $f) {
    $total_size_b += filesize($f);
    $base = basename($f);
    if ($last_auto   === null && str_contains($base, '_auto'))   $last_auto   = $f;
    if ($last_manual === null && str_contains($base, '_manual')) $last_manual = $f;
}

$total_size_mb = round($total_size_b / 1048576, 1);

function fmt_age(?int $ts): string {
    if (!$ts) return '<span class="text-muted">Never</span>';
    return '<span title="' . date('Y-m-d H:i:s', $ts) . '">' . timeAgo(date('Y-m-d H:i:s', $ts)) . '</span>';
}
?>

<!-- ── Hero card ─────────────────────────────────────────────────────────── -->
<div class="card card-dark mb-3" style="border-top:3px solid #007bff;">
    <div class="card-body">
        <div class="row align-items-center">
            <div class="col-md-4">
                <h4 class="mb-1"><i class="fas fa-database me-2 text-primary"></i>System Backup</h4>
                <p class="text-muted mb-0 small">
                    <?php if ($last_backup): ?>
                        Last backup <?= fmt_age($last_backup) ?> &middot;
                        <?= $total ?> stored &middot; <?= $total_size_mb ?> MB total
                    <?php else: ?>
                        No backups stored on server yet.
                    <?php endif; ?>
                </p>
            </div>
            <?php // Middle third matches the "Last Auto" tile's own col-4 in the
            // stat row below, so the buttons land centered directly above it. ?>
            <div class="col-md-4 text-center mt-3 mt-md-0 text-nowrap">
                <a href="post.php?backup_download_fresh=1&csrf_token=<?= $_SESSION['csrf_token'] ?>"
                   class="btn btn-sm btn-primary me-1">
                    <i class="fas fa-download me-1"></i>Download Backup
                </a>
                <a href="post.php?backup_save=1&csrf_token=<?= $_SESSION['csrf_token'] ?>"
                   class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-save me-1"></i>Save to Server
                </a>
            </div>
            <div class="col-md-4"></div>
        </div>

        <!-- Quick-stat row -->
        <div class="row mt-3 text-center">
            <div class="col-4">
                <div class="border-right py-2">
                    <div class="text-muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;">Last Manual</div>
                    <div class="fw-bold mt-1"><?= fmt_age($last_manual ? filemtime($last_manual) : null) ?></div>
                </div>
            </div>
            <div class="col-4">
                <div class="border-right py-2">
                    <div class="text-muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;">Last Auto</div>
                    <div class="fw-bold mt-1"><?= fmt_age($last_auto ? filemtime($last_auto) : null) ?></div>
                </div>
            </div>
            <div class="col-4">
                <div class="py-2">
                    <div class="text-muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;">Auto-Schedule</div>
                    <div class="fw-bold mt-1">
                        <?php if ($config_backup_auto_enabled): ?>
                            <span class="text-success"><i class="fas fa-check-circle me-1"></i><?= ucfirst($config_backup_frequency) ?></span>
                        <?php else: ?>
                            <span class="text-muted"><i class="fas fa-pause-circle me-1"></i>Off</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── Two-column row: Schedule + Master Key ─────────────────────────────── -->
<div class="row mb-3">

    <!-- Schedule settings -->
    <div class="col-lg-7 mb-3 mb-lg-0">
        <div class="card card-dark h-100">
            <div class="card-header py-2">
                <h3 class="card-title"><i class="fas fa-fw fa-calendar-alt me-2"></i>Scheduled Backups</h3>
            </div>
            <div class="card-body">
                <form action="post.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                    <div class="form-group mb-3">
                        <div class="form-check form-check form-switch">
                            <input type="checkbox" class="form-check-input" id="backup_auto"
                                   name="config_backup_auto_enabled" value="1"
                                   <?= $config_backup_auto_enabled ? 'checked' : '' ?>>
                            <label class="form-check-label" for="backup_auto">
                                Enable automatic backups via cron
                            </label>
                        </div>
                        <small class="text-muted">Requires cron to be enabled in Admin → Settings.</small>
                    </div>

                    <div class="row">
                        <div class="col-sm-6">
                            <div class="form-group">
                                <label class="text-muted small mb-1">Frequency</label>
                                <select class="form-control form-control-sm" name="config_backup_frequency">
                                    <option value="daily"  <?= $config_backup_frequency === 'daily'  ? 'selected' : '' ?>>Daily</option>
                                    <option value="weekly" <?= $config_backup_frequency === 'weekly' ? 'selected' : '' ?>>Weekly (Sunday)</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="form-group">
                                <label class="text-muted small mb-1">Keep last N backups</label>
                                <input type="number" class="form-control form-control-sm"
                                       name="config_backup_retain_count" min="1" max="90"
                                       value="<?= intval($config_backup_retain_count) ?>">
                                <small class="text-muted">Older backups are deleted automatically.</small>
                            </div>
                        </div>
                    </div>

                    <button type="submit" name="save_backup_settings" class="btn btn-primary btn-sm">
                        <i class="fas fa-check me-1"></i>Save Schedule
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Master key -->
    <div class="col-lg-5">
        <div class="card card-dark h-100">
            <div class="card-header py-2">
                <h3 class="card-title"><i class="fas fa-fw fa-key me-2"></i>Encryption Key Backup</h3>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    The master key decrypts stored credentials after a restore. Keep a copy offline in a secure location.
                </p>
                <form action="post.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <div class="input-group">
                        <input type="password" class="form-control form-control-sm"
                               placeholder="Your account password" name="password"
                               autocomplete="new-password" required>
                        <div class="input-group-append">
                            <button class="btn btn-sm btn-warning" type="submit" name="backup_master_key">
                                <i class="fas fa-key me-1"></i>Reveal
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- ── Remote Storage (S3-compatible) ─────────────────────────────────────── -->
<div class="card card-dark mb-3">
    <div class="card-header py-2">
        <h3 class="card-title"><i class="fas fa-fw fa-cloud-upload-alt me-2"></i>Remote Storage (S3-compatible)</h3>
    </div>
    <div class="card-body">
        <p class="text-muted small mb-3">
            Upload every backup (manual or auto) to an S3-compatible bucket in addition to keeping it on this server - AWS S3 itself, or a self-hosted service such as RustFS or MinIO. Point <strong>Endpoint</strong> at your provider's S3 API URL; leave it blank for real AWS S3.
        </p>
        <form action="post.php" method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

            <div class="form-group mb-3">
                <div class="form-check form-check form-switch">
                    <input type="checkbox" class="form-check-input" id="backup_s3_enabled"
                           name="config_backup_s3_enabled" value="1"
                           <?= $config_backup_s3_enabled ? 'checked' : '' ?>>
                    <label class="form-check-label" for="backup_s3_enabled">
                        Upload backups to S3-compatible storage
                    </label>
                </div>
            </div>

            <div class="row">
                <div class="col-sm-6">
                    <div class="form-group">
                        <label class="text-muted small mb-1">Endpoint URL <span class="text-muted">(blank = AWS S3)</span></label>
                        <input type="text" class="form-control form-control-sm" name="config_backup_s3_endpoint"
                               placeholder="e.g. https://s3.example.com:9000 (RustFS/MinIO)"
                               value="<?= nullable_htmlentities($config_backup_s3_endpoint) ?>">
                    </div>
                </div>
                <div class="col-sm-3">
                    <div class="form-group">
                        <label class="text-muted small mb-1">Region</label>
                        <input type="text" class="form-control form-control-sm" name="config_backup_s3_region"
                               placeholder="us-east-1" value="<?= nullable_htmlentities($config_backup_s3_region) ?>">
                    </div>
                </div>
                <div class="col-sm-3">
                    <div class="form-group">
                        <label class="text-muted small mb-1">Bucket</label>
                        <input type="text" class="form-control form-control-sm" name="config_backup_s3_bucket"
                               value="<?= nullable_htmlentities($config_backup_s3_bucket) ?>" required>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-sm-4">
                    <div class="form-group">
                        <label class="text-muted small mb-1">Access Key</label>
                        <input type="text" class="form-control form-control-sm" name="config_backup_s3_access_key"
                               autocomplete="off" value="<?= nullable_htmlentities($config_backup_s3_access_key) ?>">
                    </div>
                </div>
                <div class="col-sm-4">
                    <div class="form-group">
                        <label class="text-muted small mb-1">Secret Key</label>
                        <input type="password" class="form-control form-control-sm" name="config_backup_s3_secret_key"
                               autocomplete="new-password"
                               placeholder="<?= $config_backup_s3_secret_key ? '(saved — leave blank to keep)' : '' ?>">
                    </div>
                </div>
                <div class="col-sm-4">
                    <div class="form-group">
                        <label class="text-muted small mb-1">Key Prefix <span class="text-muted">(optional)</span></label>
                        <input type="text" class="form-control form-control-sm" name="config_backup_s3_prefix"
                               placeholder="e.g. itflow-backups/" value="<?= nullable_htmlentities($config_backup_s3_prefix) ?>">
                    </div>
                </div>
            </div>

            <div class="form-group mb-3">
                <div class="form-check form-check form-switch">
                    <input type="checkbox" class="form-check-input" id="backup_s3_path_style"
                           name="config_backup_s3_path_style" value="1"
                           <?= $config_backup_s3_path_style ? 'checked' : '' ?>>
                    <label class="form-check-label" for="backup_s3_path_style">
                        Path-style addressing
                    </label>
                </div>
                <small class="text-muted">On by default - required by most self-hosted S3-compatible services (RustFS, MinIO). Real AWS S3 works with either; turn this off only if your provider specifically requires virtual-hosted-style URLs.</small>
            </div>

            <button type="submit" name="save_backup_s3_settings" class="btn btn-primary btn-sm">
                <i class="fas fa-check me-1"></i>Save Remote Storage
            </button>
            <button type="submit" name="backup_s3_test" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-plug me-1"></i>Test Connection
            </button>
        </form>
    </div>
</div>

<!-- ── Backup history ─────────────────────────────────────────────────────── -->
<div class="card card-dark">
    <div class="card-header py-2 d-flex align-items-center">
        <h3 class="card-title mr-auto"><i class="fas fa-fw fa-history me-2"></i>Backup History</h3>
        <?php if ($total > 0): ?>
            <span class="badge text-bg-secondary"><?= $total ?> backup<?= $total !== 1 ? 's' : '' ?></span>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <?php if (empty($all_backups)): ?>
            <div class="text-center text-muted py-5">
                <i class="fas fa-archive fa-3x mb-3 d-block text-secondary"></i>
                <strong>No backups stored on server</strong><br>
                <span class="small">Use <em>Save to Server</em> above or enable auto-scheduling.</span>
            </div>
        <?php else: ?>
        <table class="table table-sm table-borderless table-hover mb-0">
            <thead style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;" class="text-muted border-bottom">
                <tr>
                    <th class="ps-3" style="width:36px;"></th>
                    <th>Filename</th>
                    <th style="width:80px;">Type</th>
                    <th style="width:80px;">Size</th>
                    <th style="width:160px;">Created</th>
                    <th style="width:110px;"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($all_backups as $i => $bfile):
                $bbase   = basename($bfile);
                $bsize   = filesize($bfile);
                $bmtime  = filemtime($bfile);
                $bdate   = date('Y-m-d H:i', $bmtime);
                $bago    = timeAgo(date('Y-m-d H:i:s', $bmtime));
                $btype   = str_contains($bbase, '_auto') ? 'Auto' : 'Manual';
                $bbadge  = $btype === 'Auto' ? 'text-bg-success' : 'text-bg-primary';
                $bmb     = $bsize >= 1048576 ? round($bsize / 1048576, 1) . ' MB'
                                             : round($bsize / 1024, 0) . ' KB';
                $is_newest = ($i === 0);
            ?>
                <tr <?= $is_newest ? 'class="table-active"' : '' ?>>
                    <td class="ps-3 text-center">
                        <i class="fas fa-file-archive text-secondary"></i>
                    </td>
                    <td>
                        <span class="small font-monospace"><?= htmlspecialchars($bbase) ?></span>
                        <?= $is_newest ? '<span class="badge text-bg-light ms-1">latest</span>' : '' ?>
                    </td>
                    <td><span class="badge <?= $bbadge ?>"><?= $btype ?></span></td>
                    <td class="text-muted small"><?= $bmb ?></td>
                    <td class="text-muted small" title="<?= $bdate ?>"><?= $bago ?></td>
                    <td class="pe-3 text-end">
                        <div class="d-inline-flex gap-1">
                            <a href="post.php?backup_serve=<?= urlencode($bbase) ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>"
                               class="btn btn-sm btn-outline-primary"
                               title="Download">
                                <i class="fas fa-download"></i>
                            </a>
                            <a href="post.php?backup_delete=<?= urlencode($bbase) ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>"
                               class="btn btn-sm btn-outline-danger confirm-link"
                               title="Delete">
                                <i class="fas fa-trash"></i>
                            </a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php require_once "../includes/footer.php"; ?>
