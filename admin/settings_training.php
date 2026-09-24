<?php
require_once "includes/inc_all_admin.php";

/*
 * Admin > Training (LMS) settings - spec §5.11.
 *
 * Renders before the 2.6.91 migration has run: everything that touches a training table or
 * column is behind $config_training_schema_ready plus a table-exists check and a try/catch,
 * so an unmigrated install shows a "run the database update" card instead of a 500.
 *
 * Forms post to admin/post.php, which dispatches to admin/post/settings_training.php by this
 * page's basename (edit_training_settings, training_ledger_verify, training_youtube_key_test,
 * training_media_purge).
 *
 * The media purge and the YouTube key test call media-pipeline classes
 * (ITFlow\Training\Media\MediaPurger / YouTubeDataApi); until those exist the cards say so.
 */

use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Ledger;
use ITFlow\Training\Core\MediaUsage;
use ITFlow\Training\Core\TrainingSettings;

$tr_ready = false;
$tr_error = false;
$tr_row = [];
$tr_head = null;
$tr_head_missing = false;    // head row gone (tamper / bad restore): the page still renders, Verify now reports it
$tr_usage = [];
$tr_live_bytes = 0;
$tr_unreferenced = null;     // null = purger not installed yet
$tr_unreferenced_error = false;

if (!empty($config_training_schema_ready)) {
    try {
        $tr_tbl = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('training_ledger_head', 'training_media', 'training_events')"));
        if (intval($tr_tbl['n'] ?? 0) === 3) {
            $tr_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_module_enable_training, config_training_languages, config_training_default_pass_pct,
                    config_training_default_max_attempts, config_training_attestation_text, config_training_video_max_mb, config_training_pdf_max_mb,
                    config_training_pdf_max_pages, config_training_image_max_mb, config_training_file_max_mb, config_training_media_budget_mb,
                    (config_training_youtube_api_key IS NOT NULL AND config_training_youtube_api_key <> '') AS youtube_key_set,
                    config_training_ledger_verified_at_utc, config_training_ledger_verify_result
                FROM settings WHERE company_id = 1")) ?: [];
            try {
                $tr_head = Ledger::head($mysqli);
            } catch (\RuntimeException $e) {
                if ($e->getMessage() !== 'ledger_uninitialized') {
                    throw $e;
                }
                $tr_head_missing = true;
            }
            $tr_usage = MediaUsage::liveByKind($mysqli);
            foreach ($tr_usage as $tr_k) {
                $tr_live_bytes += $tr_k['bytes'];
            }
            $tr_ready = true;

            if (class_exists('ITFlow\Training\Media\MediaPurger')) {
                try {
                    $tr_purger = new \ITFlow\Training\Media\MediaPurger(\ITFlow\Training\Core\Access::ctx($mysqli));
                    $tr_unreferenced = $tr_purger->unreferenced(7);
                } catch (\Throwable $e) {
                    error_log('Admin Training settings: unreferenced media lookup failed: ' . get_class($e) . ': ' . $e->getMessage());
                    $tr_unreferenced_error = true;
                }
            }
        }
    } catch (\Throwable $e) {
        error_log('Admin Training settings: ' . get_class($e) . ': ' . $e->getMessage());
        $tr_error = true;
        $tr_ready = false;
    }
}

$tr_settings = TrainingSettings::fromRow($tr_row, $tr_ready);
$tr_budget_bytes = $tr_settings->budgetBytes;
$tr_budget_pct = $tr_budget_bytes > 0 ? min(100, round($tr_live_bytes * 100 / $tr_budget_bytes, 1)) : 0;

// Projected size of one in-app backup: everything under uploads/ (zipped, but media barely
// compresses) plus the last database dump. Measured, not estimated from settings.
$tr_uploads_bytes = 0;
$tr_uploads_partial = false;
$tr_uploads_root = realpath(dirname(__DIR__) . '/uploads');
if ($tr_uploads_root && is_dir($tr_uploads_root)) {
    $tr_scan_deadline = microtime(true) + 3;
    try {
        $tr_it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tr_uploads_root, FilesystemIterator::SKIP_DOTS));
        foreach ($tr_it as $tr_f) {
            if ($tr_f->isFile() && !$tr_f->isLink()) {
                $tr_uploads_bytes += $tr_f->getSize();
            }
            if (microtime(true) > $tr_scan_deadline) {
                $tr_uploads_partial = true;
                break;
            }
        }
    } catch (\Throwable $e) {
        $tr_uploads_partial = true;
    }
}
$tr_db_dump_bytes = null;
$tr_backups = glob(dirname(__DIR__) . '/backups/itflow_*.zip') ?: [];
usort($tr_backups, fn($a, $b) => filemtime($b) - filemtime($a));
if ($tr_backups) {
    $tr_zip = new ZipArchive();
    if ($tr_zip->open($tr_backups[0]) === true) {
        $tr_stat = $tr_zip->statName('db.sql');
        if ($tr_stat !== false) {
            $tr_db_dump_bytes = (int) $tr_stat['size'];
        }
        $tr_zip->close();
    }
}
$tr_projected_bytes = $tr_uploads_bytes + (int) $tr_db_dump_bytes;

function tr_admin_fmt_bytes(int $b): string {
    if ($b < 1024) return $b . ' B';
    $u = ['KB', 'MB', 'GB', 'TB'];
    $i = -1;
    $v = $b;
    do { $v /= 1024; $i++; } while ($v >= 1024 && $i < count($u) - 1);
    return ($v >= 100 ? round($v) : rtrim(rtrim(number_format($v, 1), '0'), '.')) . ' ' . $u[$i];
}

// The module switch appears in Modules only once the Training pages are installed (the course
// pages ship after this foundation update); until then there is nothing to open.
$tr_pages_ready = is_file(dirname(__DIR__) . '/agent/training_courses.php');

$tr_kind_labels = ['pdf' => 'PDF documents', 'page' => 'PDF page images', 'video' => 'Videos', 'image' => 'Images', 'file' => 'Resource files', 'evidence' => 'Evidence'];
$tr_kind_colors = ['pdf' => 'bg-red', 'page' => 'bg-orange', 'video' => 'bg-purple', 'image' => 'bg-cyan', 'file' => 'bg-blue', 'evidence' => 'bg-secondary'];

$tr_result_line = (string) ($tr_row['config_training_ledger_verify_result'] ?? '');
$tr_result_badge = 'text-bg-secondary';
if (str_starts_with($tr_result_line, 'ok')) {
    $tr_result_badge = 'text-bg-success';
} elseif (str_starts_with($tr_result_line, 'BREAK')) {
    $tr_result_badge = 'text-bg-danger';
} elseif ($tr_result_line !== '') {
    $tr_result_badge = 'text-bg-warning';
}
$tr_verified_iso = Clock::toIso($tr_row['config_training_ledger_verified_at_utc'] ?? null, true);
$tr_head_updated_iso = $tr_head ? Clock::toIso($tr_head['updated_at_utc'], true) : null;
?>

<!-- Plain .card throughout, not .card-dark - see the note in admin/settings_module.php. -->

<?php if (!$tr_ready) { ?>
<div class="card mb-3">
    <div class="card-header py-3">
        <h3 class="card-title"><i class="fas fa-fw fa-hard-hat me-2"></i>Training (LMS)</h3>
    </div>
    <div class="card-body">
        <?php if ($tr_error) { ?>
            <div class="alert alert-danger mb-0">Training settings could not be loaded. The details were written to the server error log.</div>
        <?php } else { ?>
            <p class="mb-2">The Training database tables are not installed yet.</p>
            <p class="text-muted mb-3">Run the database update (Admin &rsaquo; Update &rsaquo; <strong>Update Database</strong>) to add them. Nothing else in the app changes until the module is switched on.</p>
            <a href="/admin/update.php" class="btn btn-primary"><i class="fas fa-fw fa-database me-2"></i>Open Update</a>
        <?php } ?>
    </div>
</div>
<?php } else { ?>

<!-- Module ------------------------------------------------------------------------------ -->
<div class="card mb-3">
    <div class="card-header py-3">
        <h3 class="card-title"><i class="fas fa-fw fa-hard-hat me-2"></i>Training (LMS)</h3>
        <div class="card-actions">
            <?php if (intval($tr_row['config_module_enable_training'] ?? 0) === 1 && $tr_pages_ready) { ?>
                <a href="/agent/training_courses.php" class="btn btn-outline-primary btn-sm"><i class="fas fa-fw fa-graduation-cap me-1"></i>Open Training</a>
            <?php } ?>
        </div>
    </div>
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
            <span class="fw-bold">Module status</span>
            <?php if (intval($tr_row['config_module_enable_training'] ?? 0) === 1) { ?>
                <span class="badge text-bg-success">On</span>
            <?php } else { ?>
                <span class="badge text-bg-secondary">Off</span>
            <?php } ?>
            <?php if ($tr_pages_ready || intval($tr_row['config_module_enable_training'] ?? 0) === 1) { ?>
                <a href="/admin/settings_module.php" class="ms-2">Change in Modules</a>
            <?php } else { ?>
                <span class="text-muted small ms-2">The switch appears in Modules once the Training pages are installed (a later update).</span>
            <?php } ?>
        </div>
        <p class="text-muted small mb-0">
            Training is visible only to roles granted the <code>module_training</code> permission (1 Read, 2 Modify, 3 Full); admins always have full access.
            Suggested role for course authors: <strong>Training Author</strong> with <code>module_training</code> = 3 only.
            Leave <code>module_kb</code> off for that role unless its department rows are set, because department scoping fails open for users without department rows.
        </p>
    </div>
</div>

<form action="post.php" method="post" autocomplete="off" id="trSettingsForm">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">

    <!-- Defaults -------------------------------------------------------------------------- -->
    <div class="card mb-3">
        <div class="card-header py-3">
            <h3 class="card-title"><i class="fas fa-fw fa-sliders-h me-2"></i>Defaults</h3>
        </div>
        <div class="card-body">
            <div class="mb-3">
                <label class="form-label">Languages offered to course authors</label>
                <?php foreach (TrainingSettings::KNOWN_LANGUAGES as $tr_code => $tr_label) { ?>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" name="training_languages[]" value="<?php echo nullable_htmlentities($tr_code); ?>" id="trLang_<?php echo nullable_htmlentities($tr_code); ?>"
                            <?php if (in_array($tr_code, $tr_settings->languages, true)) { echo 'checked'; } ?> <?php if ($tr_code === 'en') { echo 'disabled'; } ?>>
                        <label class="form-check-label" for="trLang_<?php echo nullable_htmlentities($tr_code); ?>"><?php echo nullable_htmlentities($tr_label); ?></label>
                    </div>
                <?php } ?>
                <div class="form-text">English is always offered. A course publishes Spanish only when its Spanish content is complete.</div>
            </div>
            <div class="row">
                <div class="col-sm-6 col-lg-3 mb-3">
                    <label class="form-label" for="trPassPct">Default pass mark (%)</label>
                    <input type="number" class="form-control" id="trPassPct" name="config_training_default_pass_pct" min="50" max="100" step="1" required
                           value="<?php echo intval($tr_settings->defaultPassPct); ?>">
                </div>
                <div class="col-sm-6 col-lg-3 mb-3">
                    <label class="form-label" for="trAttempts">Default attempts</label>
                    <input type="number" class="form-control" id="trAttempts" name="config_training_default_max_attempts" min="0" max="10" step="1" required
                           value="<?php echo intval($tr_settings->defaultMaxAttempts); ?>">
                    <div class="form-text">0 = unlimited.</div>
                </div>
            </div>
            <div class="mb-0">
                <label class="form-label" for="trAttestation">Default attestation text</label>
                <textarea class="form-control" id="trAttestation" name="config_training_attestation_text" rows="3" maxlength="5000"
                          placeholder="I completed this training and understand it."><?php echo nullable_htmlentities($tr_settings->attestationDefault ?? ''); ?></textarea>
                <div class="form-text">Prefills new courses; each course can change it.</div>
            </div>
        </div>
    </div>

    <!-- Media limits ---------------------------------------------------------------------- -->
    <div class="card mb-3">
        <div class="card-header py-3">
            <h3 class="card-title"><i class="fas fa-fw fa-photo-video me-2"></i>Media limits</h3>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-sm-6 col-lg-4 mb-3">
                    <label class="form-label" for="trVideoMb">Video upload (MB)</label>
                    <input type="number" class="form-control" id="trVideoMb" name="config_training_video_max_mb" min="1" max="<?php echo TrainingSettings::UPLOAD_CAP_MB; ?>" required
                           value="<?php echo intval($tr_settings->videoMaxBytes / TrainingSettings::MB); ?>">
                    <div class="form-text">At most <?php echo TrainingSettings::UPLOAD_CAP_MB; ?> MB. Longer videos go on the company YouTube channel as Unlisted.</div>
                </div>
                <div class="col-sm-6 col-lg-4 mb-3">
                    <label class="form-label" for="trPdfMb">PDF upload (MB)</label>
                    <input type="number" class="form-control" id="trPdfMb" name="config_training_pdf_max_mb" min="1" max="<?php echo TrainingSettings::UPLOAD_CAP_MB; ?>" required
                           value="<?php echo intval($tr_settings->pdfMaxBytes / TrainingSettings::MB); ?>">
                </div>
                <div class="col-sm-6 col-lg-4 mb-3">
                    <label class="form-label" for="trPdfPages">PDF pages (max)</label>
                    <input type="number" class="form-control" id="trPdfPages" name="config_training_pdf_max_pages" min="1" max="1000" required
                           value="<?php echo intval($tr_settings->pdfMaxPages); ?>">
                </div>
                <div class="col-sm-6 col-lg-4 mb-3">
                    <label class="form-label" for="trImageMb">Image upload (MB)</label>
                    <input type="number" class="form-control" id="trImageMb" name="config_training_image_max_mb" min="1" max="<?php echo TrainingSettings::UPLOAD_CAP_MB; ?>" required
                           value="<?php echo intval($tr_settings->imageMaxBytes / TrainingSettings::MB); ?>">
                </div>
                <div class="col-sm-6 col-lg-4 mb-3">
                    <label class="form-label" for="trFileMb">Resource file (MB)</label>
                    <input type="number" class="form-control" id="trFileMb" name="config_training_file_max_mb" min="1" max="<?php echo TrainingSettings::UPLOAD_CAP_MB; ?>" required
                           value="<?php echo intval($tr_settings->fileMaxBytes / TrainingSettings::MB); ?>">
                    <div class="form-text">At most <?php echo TrainingSettings::UPLOAD_CAP_MB; ?> MB.</div>
                </div>
                <div class="col-sm-6 col-lg-4 mb-3">
                    <label class="form-label" for="trBudgetMb">Media budget (MB)</label>
                    <input type="number" class="form-control" id="trBudgetMb" name="config_training_media_budget_mb" min="100" max="1048576" required
                           value="<?php echo intval($tr_settings->budgetBytes / TrainingSettings::MB); ?>">
                    <div class="form-text">Total stored training media. Uploads beyond it are refused.</div>
                </div>
            </div>
        </div>
    </div>

    <!-- YouTube Data API key -------------------------------------------------------------- -->
    <div class="card mb-3">
        <div class="card-header py-3">
            <h3 class="card-title"><i class="fab fa-fw fa-youtube me-2"></i>YouTube Data API key</h3>
        </div>
        <div class="card-body">
            <p class="text-muted small">
                Optional. With a key, a YouTube video's length and its live/embeddable status are read when the link is added.
                Without one, the length is taken from the verified play. The key is stored encrypted and never shown again.
            </p>
            <div class="d-flex align-items-center gap-2 mb-2">
                <span class="fw-bold">Status</span>
                <?php if (!empty($tr_row['youtube_key_set'])) { ?>
                    <span class="badge text-bg-success">&bull;&bull;&bull;&bull; set</span>
                <?php } else { ?>
                    <span class="badge text-bg-secondary">Not set</span>
                <?php } ?>
            </div>
            <div class="row align-items-end">
                <div class="col-lg-6 mb-2">
                    <label class="form-label" for="trYoutubeKey"><?php echo !empty($tr_row['youtube_key_set']) ? 'Replace key' : 'API key'; ?></label>
                    <input type="password" class="form-control" id="trYoutubeKey" name="config_training_youtube_api_key" maxlength="200" autocomplete="new-password" spellcheck="false"
                           placeholder="<?php echo !empty($tr_row['youtube_key_set']) ? 'Leave blank to keep the saved key' : 'Paste the key'; ?>">
                </div>
                <div class="col-lg-6 mb-2">
                    <?php if (!empty($tr_row['youtube_key_set'])) { ?>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" name="training_youtube_key_clear" value="1" id="trYoutubeKeyClear">
                            <label class="form-check-label" for="trYoutubeKeyClear">Remove the saved key</label>
                        </div>
                        <button type="submit" form="trYoutubeTestForm" name="training_youtube_key_test" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-fw fa-vial me-1"></i>Test key
                        </button>
                    <?php } ?>
                </div>
            </div>
        </div>
    </div>

    <div class="mb-4">
        <button type="submit" name="edit_training_settings" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Save</button>
    </div>
</form>

<form action="post.php" method="post" id="trYoutubeTestForm">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">
</form>

<!-- Media storage ---------------------------------------------------------------------------- -->
<div class="card mb-3">
    <div class="card-header py-3">
        <h3 class="card-title"><i class="fas fa-fw fa-hdd me-2"></i>Media storage</h3>
    </div>
    <div class="card-body">
        <div class="d-flex justify-content-between small mb-1">
            <span><?php echo nullable_htmlentities(tr_admin_fmt_bytes($tr_live_bytes)); ?> of <?php echo nullable_htmlentities(tr_admin_fmt_bytes($tr_budget_bytes)); ?> budget</span>
            <span><?php echo nullable_htmlentities((string) $tr_budget_pct); ?>%</span>
        </div>
        <div class="progress mb-2" style="height: 10px;" role="img" aria-label="Media budget usage by kind">
            <?php foreach ($tr_usage as $tr_kind => $tr_k) {
                $tr_w = $tr_budget_bytes > 0 ? max(0.5, $tr_k['bytes'] * 100 / $tr_budget_bytes) : 0; ?>
                <div class="progress-bar <?php echo nullable_htmlentities($tr_kind_colors[$tr_kind] ?? 'bg-secondary'); ?>" style="width: <?php echo nullable_htmlentities(number_format(min(100, $tr_w), 2, '.', '')); ?>%"
                     title="<?php echo nullable_htmlentities(($tr_kind_labels[$tr_kind] ?? $tr_kind) . ': ' . tr_admin_fmt_bytes($tr_k['bytes'])); ?>"></div>
            <?php } ?>
        </div>
        <?php if ($tr_usage) { ?>
            <ul class="list-inline small text-muted mb-3">
                <?php foreach ($tr_usage as $tr_kind => $tr_k) { ?>
                    <li class="list-inline-item me-3">
                        <span class="badge <?php echo nullable_htmlentities($tr_kind_colors[$tr_kind] ?? 'bg-secondary'); ?> me-1">&nbsp;</span>
                        <?php echo nullable_htmlentities(($tr_kind_labels[$tr_kind] ?? $tr_kind) . ': ' . tr_admin_fmt_bytes($tr_k['bytes']) . ' (' . $tr_k['count'] . ')'); ?>
                    </li>
                <?php } ?>
            </ul>
        <?php } else { ?>
            <p class="small text-muted mb-3">No training media stored yet.</p>
        <?php } ?>

        <div class="mb-3">
            <div class="fw-bold">Projected backup size</div>
            <div>
                about <?php echo nullable_htmlentities(tr_admin_fmt_bytes($tr_projected_bytes)); ?>
                <span class="text-muted small">(uploads <?php echo nullable_htmlentities(tr_admin_fmt_bytes($tr_uploads_bytes)); ?><?php echo $tr_uploads_partial ? '+' : ''; ?><?php
                    if ($tr_db_dump_bytes !== null) { echo ' + database ' . nullable_htmlentities(tr_admin_fmt_bytes($tr_db_dump_bytes)); } else { echo ' + database (no backup yet to measure)'; } ?>)</span>
            </div>
            <?php if ($tr_projected_bytes > 1073741824) { ?>
                <div class="alert alert-warning small mt-2 mb-0">
                    Backups are over 1 GB. A manual <strong>Download Backup</strong> may exceed Cloudflare's 100-second limit; use <strong>Save to Server</strong> instead.
                </div>
            <?php } ?>
        </div>

        <div class="fw-bold">Unreferenced media</div>
        <?php if ($tr_unreferenced === null && !$tr_unreferenced_error) { ?>
            <p class="small text-muted mb-0">Review and purge becomes available with the media pipeline update.</p>
        <?php } elseif ($tr_unreferenced_error) { ?>
            <p class="small text-danger mb-0">Could not list unreferenced media. The details were written to the server error log.</p>
        <?php } else {
            $tr_unref_bytes = 0;
            foreach ($tr_unreferenced as $tr_u) {
                $tr_unref_bytes += intval($tr_u['media_bytes'] ?? $tr_u['bytes'] ?? 0);
            } ?>
            <p class="small mb-2">
                <?php echo intval(count($tr_unreferenced)); ?> file(s), <?php echo nullable_htmlentities(tr_admin_fmt_bytes($tr_unref_bytes)); ?>, older than 7 days and not used by any draft or published version.
                Purging deletes the file only; its record and hash stay, and the purge is written to the training ledger.
            </p>
            <?php if ($tr_unreferenced) { ?>
                <details>
                    <summary class="btn btn-outline-danger btn-sm mb-2">Review &amp; purge&hellip;</summary>
                    <form action="post.php" method="post" autocomplete="off">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">
                        <div class="table-responsive" style="max-height: 320px;">
                            <table class="table table-sm table-striped mb-2">
                                <thead><tr><th class="w-1"></th><th>File</th><th>Kind</th><th class="text-end">Size</th></tr></thead>
                                <tbody>
                                <?php foreach ($tr_unreferenced as $tr_u) {
                                    $tr_uid = intval($tr_u['media_id'] ?? $tr_u['id'] ?? 0);
                                    if ($tr_uid < 1) { continue; } ?>
                                    <tr>
                                        <td><input class="form-check-input" type="checkbox" name="media_ids[]" value="<?php echo $tr_uid; ?>" checked aria-label="Select media <?php echo $tr_uid; ?>"></td>
                                        <td class="text-break"><?php echo nullable_htmlentities((string) ($tr_u['media_original_name'] ?? $tr_u['original_name'] ?? ('#' . $tr_uid))); ?></td>
                                        <td><?php echo nullable_htmlentities((string) ($tr_u['media_kind'] ?? $tr_u['kind'] ?? '')); ?></td>
                                        <td class="text-end"><?php echo nullable_htmlentities(tr_admin_fmt_bytes(intval($tr_u['media_bytes'] ?? $tr_u['bytes'] ?? 0))); ?></td>
                                    </tr>
                                <?php } ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="row align-items-end">
                            <div class="col-md-8 mb-2">
                                <label class="form-label" for="trPurgeReason">Reason (required, recorded in the ledger)</label>
                                <input type="text" class="form-control" id="trPurgeReason" name="purge_reason" minlength="5" maxlength="500" required placeholder="e.g. Old drafts replaced by new uploads">
                            </div>
                            <div class="col-md-4 mb-2">
                                <button type="submit" name="training_media_purge" class="btn btn-danger w-100"><i class="fas fa-fw fa-trash-alt me-1"></i>Purge selected</button>
                            </div>
                        </div>
                    </form>
                </details>
            <?php } ?>
        <?php } ?>
    </div>
</div>

<!-- Ledger ------------------------------------------------------------------------------------ -->
<div class="card mb-3">
    <div class="card-header py-3">
        <h3 class="card-title"><i class="fas fa-fw fa-link me-2"></i>Records ledger</h3>
    </div>
    <div class="card-body">
        <p class="text-muted small">
            Every stored file, published version and archive action is written to a hash-chained ledger. Verification re-computes
            every hash; it runs nightly (deep, with file checks, on Sundays) and on demand here. The head is also written into every backup's <code>version.txt</code>.
        </p>
        <dl class="row mb-3">
            <dt class="col-sm-3">Head</dt>
            <dd class="col-sm-9">
                <?php if ($tr_head) { ?>
                    <span class="font-monospace">#<?php echo intval($tr_head['seq']); ?> / <?php echo nullable_htmlentities(substr($tr_head['hash'], 0, 16)); ?></span>
                    <?php if ($tr_head_updated_iso) { ?><span class="text-muted small ms-2">updated <?php echo nullable_htmlentities(date('Y-m-d H:i', strtotime($tr_head_updated_iso))); ?></span><?php } ?>
                <?php } elseif ($tr_head_missing) { ?>
                    <span class="badge text-bg-danger">Ledger head missing</span>
                    <div class="small text-danger mt-1">The ledger head row is gone, which means the records were tampered with or a restore was incomplete. New media and publishes will fail until it is back. Run <strong>Verify now</strong> and restore from a backup whose <code>version.txt</code> head matches.</div>
                <?php } ?>
            </dd>
            <dt class="col-sm-3">Last verified</dt>
            <dd class="col-sm-9">
                <?php if ($tr_verified_iso) { ?>
                    <span title="<?php echo nullable_htmlentities($tr_verified_iso); ?>"><?php echo nullable_htmlentities(date('Y-m-d H:i', strtotime($tr_verified_iso))); ?></span>
                <?php } else { ?>
                    <span class="text-muted">Never</span>
                <?php } ?>
            </dd>
            <dt class="col-sm-3">Result</dt>
            <dd class="col-sm-9">
                <?php if ($tr_result_line !== '') { ?>
                    <span class="badge <?php echo $tr_result_badge; ?> font-monospace"><?php echo nullable_htmlentities($tr_result_line); ?></span>
                <?php } else { ?>
                    <span class="text-muted">&mdash;</span>
                <?php } ?>
            </dd>
        </dl>
        <form action="post.php" method="post" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">
            <button type="submit" name="training_ledger_verify" class="btn btn-outline-primary"><i class="fas fa-fw fa-check-double me-1"></i>Verify now</button>
        </form>
        <span class="text-muted small ms-2">Shallow check (no file re-hash), stops after 60 seconds.</span>
    </div>
</div>

<?php } ?>

<?php
require_once "../includes/footer.php";
