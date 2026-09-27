<?php
defined('TRAINING_AUTOMATION_PAGE') || exit;

/*
 * Training settings › Video watch card (Phase 5 S6, spec §5.4). Included by the one Training
 * settings page (admin/settings_training.php) and its Training-3 twin (agent/training_settings.php)
 * when this file exists. Editable on both pages (Training-3 territory).
 *
 * Inputs: the same as reminders.php ($ta, $ta_form_action, $ta_csrf, $ta_can_edit, $mysqli,
 * $config_base_url). GET makes no network call: the list is VideoWatch::report(), read from the
 * current revisions and training_video_watch. "Check now" posts ta_video_run (ReminderAdmin runs
 * VideoWatch::run(20, 20) synchronously). Course and lesson names come from the database and are
 * echoed through nullable_htmlentities(); ids and counts through intval().
 */

use ITFlow\Training\Automation\Notify;
use ITFlow\Training\Automation\WorkerCtx;
use ITFlow\Training\Core\Clock;
use ITFlow\Training\Reminders\VideoWatch;
use ITFlow\Training\Upstream\Links;

$ta_vid_action = isset($ta_form_action) && is_string($ta_form_action) && $ta_form_action !== '' ? $ta_form_action : 'post.php';
$ta_vid_csrf = isset($ta_csrf) && is_string($ta_csrf) ? $ta_csrf : (string) ($_SESSION['csrf_token'] ?? '');
$ta_vid_edit = !isset($ta_can_edit) || $ta_can_edit === true;
$ta_vid_ready = !empty($ta['ready']);
$ta_vid_on = $ta_vid_ready && intval($ta['tauto_video_recheck_enabled'] ?? 1) === 1;

$ta_vid_report = null;
$ta_vid_error = false;
if ($ta_vid_ready) {
    try {
        $ta_vid_report = (new VideoWatch(WorkerCtx::build($mysqli, (string) ($config_base_url ?? '')), new Notify($mysqli)))->report();
    } catch (\Throwable $e) {
        error_log('Training video watch card: ' . get_class($e) . ': ' . $e->getMessage());
        $ta_vid_error = true;
    }
}

/** A UTC DATETIME(3) as local "M j, Y g:i A", or null. */
$ta_vid_time = static function (?string $utc): ?string {
    $iso = Clock::toIso($utc, true);
    return $iso === null ? null : date('M j, Y g:i A', (int) strtotime($iso));
};
?>
<div class="card mb-3" id="video-watch">
    <div class="card-header py-3">
        <h3 class="card-title"><i class="fas fa-fw fa-video me-2" aria-hidden="true"></i>Video watch</h3>
        <div class="card-actions">
            <?php if ($ta_vid_on) { ?>
                <span class="badge text-bg-success">On</span>
            <?php } else { ?>
                <span class="badge text-bg-secondary">Off</span>
            <?php } ?>
        </div>
    </div>
    <div class="card-body">
        <?php if (!$ta_vid_ready) { ?>
            <p class="text-muted mb-0">Run the database update (Admin &rsaquo; Update &rsaquo; <strong>Update Database</strong>) to use the video watch.</p>
        <?php } else { ?>
            <p class="mb-2">
                Every day, the YouTube and Vimeo videos in published courses are checked. When a video is private, deleted, has embedding turned off,
                or (Vimeo) changed length on two checks at least an hour apart, the course's responsible person and Training managers get one
                notification. The check never changes a course or blocks publishing.
            </p>

            <form action="<?php echo nullable_htmlentities($ta_vid_action); ?>" method="post" autocomplete="off" data-ts-label="Video watch">
                <input type="hidden" name="csrf_token" value="<?php echo nullable_htmlentities($ta_vid_csrf); ?>">
                <input type="hidden" name="ta_video_version" value="<?php echo intval($ta['tauto_version'] ?? 0); ?>">
                <fieldset <?php if (!$ta_vid_edit) { echo 'disabled'; } ?>>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" role="switch" name="ta_video_enabled" value="1" id="taVideoEnabled" <?php if ($ta_vid_on) { echo 'checked'; } ?>>
                        <label class="form-check-label" for="taVideoEnabled">Check published videos every day</label>
                    </div>
                </fieldset>
                <?php if ($ta_vid_edit) { ?>
                    <button type="submit" name="ta_video_save" value="1" class="btn btn-primary"><i class="fas fa-check me-2" aria-hidden="true"></i>Save video watch</button>
                <?php } else { ?>
                    <p class="small text-muted mb-0"><i class="fas fa-fw fa-lock me-1" aria-hidden="true"></i>Read only. Ask a Training manager or an administrator to change this.</p>
                <?php } ?>
            </form>

            <hr class="my-3">

            <?php if ($ta_vid_error) { ?>
                <div class="alert alert-danger mb-0">The watched videos could not be listed. The details were written to the server error log.</div>
            <?php } elseif ($ta_vid_report !== null) { ?>
                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                    <div class="small">
                        <?php if (intval($ta_vid_report['videos']) === 0) { ?>
                            No published course uses a YouTube or Vimeo video.
                        <?php } else { ?>
                            Watching <strong><?php echo intval($ta_vid_report['videos']); ?></strong> <?php echo intval($ta_vid_report['videos']) === 1 ? 'video' : 'videos'; ?>
                            in <?php echo intval($ta_vid_report['courses']); ?> published <?php echo intval($ta_vid_report['courses']) === 1 ? 'course' : 'courses'; ?>.
                            Last checked: <?php $ta_vid_last = $ta_vid_time($ta_vid_report['last_checked_at_utc']); echo $ta_vid_last !== null ? nullable_htmlentities($ta_vid_last) : 'never'; ?>.
                            <?php if (intval($ta_vid_report['never_checked']) > 0 && $ta_vid_last !== null) { ?>
                                <?php echo intval($ta_vid_report['never_checked']); ?> not checked yet.
                            <?php } ?>
                        <?php } ?>
                    </div>
                    <?php if ($ta_vid_edit && intval($ta_vid_report['videos']) > 0) { ?>
                        <form action="<?php echo nullable_htmlentities($ta_vid_action); ?>" method="post" class="ms-auto">
                            <input type="hidden" name="csrf_token" value="<?php echo nullable_htmlentities($ta_vid_csrf); ?>">
                            <button type="submit" name="ta_video_run" value="1" class="btn btn-outline-primary btn-sm"><i class="fas fa-fw fa-sync-alt me-1" aria-hidden="true"></i>Check now</button>
                        </form>
                    <?php } ?>
                </div>
                <?php if (intval($ta_vid_report['videos']) > 0) { ?>
                    <p class="small text-muted mb-2">Check now looks at up to 20 videos, oldest check first, and takes up to 20 seconds.</p>
                <?php } ?>

                <?php if ($ta_vid_report['bad'] === []) { ?>
                    <?php if (intval($ta_vid_report['videos']) > 0) { ?>
                        <p class="mb-0 text-success"><i class="fas fa-fw fa-check-circle me-1" aria-hidden="true"></i>No video problems found.</p>
                    <?php } ?>
                <?php } else { ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-vcenter mb-0">
                            <caption class="small">Videos with a problem. A problem is reported after two bad checks at least an hour apart.</caption>
                            <thead>
                                <tr>
                                    <th scope="col">Video</th>
                                    <th scope="col">Problem</th>
                                    <th scope="col" class="text-end">Bad checks</th>
                                    <th scope="col">First seen</th>
                                    <th scope="col">Used in</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($ta_vid_report['bad'] as $ta_vid_b) { ?>
                                    <tr>
                                        <td class="text-nowrap">
                                            <i class="fab fa-fw <?php echo $ta_vid_b['provider'] === 'vimeo' ? 'fa-vimeo-v' : 'fa-youtube'; ?> me-1" aria-hidden="true"></i><?php echo nullable_htmlentities(VideoWatch::providerLabel($ta_vid_b['provider'])); ?>
                                            <div class="small text-muted font-monospace"><?php echo nullable_htmlentities($ta_vid_b['id']); ?></div>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo intval($ta_vid_b['streak']) >= 2 ? 'text-bg-danger' : 'text-bg-warning'; ?>"><?php echo nullable_htmlentities(ucfirst(VideoWatch::statusLabel($ta_vid_b['status']))); ?></span>
                                            <div class="small text-muted"><?php echo intval($ta_vid_b['streak']) >= 2 ? ($ta_vid_b['alerted'] ? 'Reported' : 'Reporting on the next check') : 'Waiting for a second check'; ?></div>
                                        </td>
                                        <td class="text-end"><?php echo intval($ta_vid_b['streak']); ?></td>
                                        <td class="text-nowrap small"><?php $ta_vid_first = $ta_vid_time($ta_vid_b['first_bad_at_utc']); echo $ta_vid_first !== null ? nullable_htmlentities($ta_vid_first) : '&mdash;'; ?></td>
                                        <td class="small">
                                            <?php foreach ($ta_vid_b['courses'] as $ta_vid_c) { ?>
                                                <div>
                                                    <a href="<?php echo nullable_htmlentities(Links::courseBuilder(intval($ta_vid_c['id']))); ?>"><?php echo nullable_htmlentities($ta_vid_c['name']); ?></a>
                                                    <?php if ($ta_vid_c['lessons'] !== []) { ?>
                                                        <span class="text-muted">&middot; <?php echo nullable_htmlentities(implode(', ', $ta_vid_c['lessons'])); ?></span>
                                                    <?php } ?>
                                                </div>
                                            <?php } ?>
                                        </td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="small text-muted mt-2 mb-0">Fix the video in YouTube or Vimeo, then play it once in the course builder before republishing.</p>
                <?php } ?>
            <?php } ?>
        <?php } ?>
    </div>
</div>
