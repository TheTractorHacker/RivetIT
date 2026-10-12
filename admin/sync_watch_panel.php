<?php
/*
 * Integration sync watch: the state the recovery watcher sees for every enabled RMM / Intune / UniFi integration
 * (healthy, failing, stale, dormant, muted), when it last ran, how often it is expected to, why it alerted, and the per-integration
 * controls (Mute, Expected interval, Forget history). Included by admin/backup_recovery_panel.php; the handlers are in
 * admin/post/backup_recovery.php (action sync_watch_action). Needs $mysqli and a session CSRF token.
 */

if (!isset($mysqli)) { http_response_code(404); exit; }   // only ever included by the Backup page

use ITFlow\Recovery\RecoveryAlerts;
use ITFlow\Recovery\RecoverySettings;
use ITFlow\Recovery\SyncWatch;
use ITFlow\Recovery\WatchOverrides;

$sw_rmm_on = false;
$sw_q = @mysqli_query($mysqli, 'SELECT config_module_enable_rmm FROM settings WHERE company_id = 1');
if ($sw_q && ($sw_r = mysqli_fetch_assoc($sw_q))) { $sw_rmm_on = (int) $sw_r['config_module_enable_rmm'] === 1; }
$sw_ready = WatchOverrides::ready($mysqli);
$sw_rows = SyncWatch::inspect($mysqli, $sw_rmm_on);
$sw_badge = ['ok' => ['success', 'healthy'], 'failing' => ['danger', 'failing'], 'stale' => ['warning', 'stale'], 'dormant' => ['secondary', 'dormant'], 'muted' => ['dark', 'muted']];
$sw_ago = function (?int $ts, int $now): string {
    if ($ts === null) { return 'never'; }
    return SyncWatch::human(max(0, $now - $ts)) . ' ago';
};
$sw_counts = array_count_values(array_column($sw_rows, 'state'));
$sw_alert_ts = function (string $key) use ($mysqli): ?string {
    $k = mysqli_real_escape_string($mysqli, $key);
    $r = @mysqli_query($mysqli, "SELECT alert_last_sent_at FROM recovery_alerts WHERE alert_key = '$k'");
    $row = $r ? mysqli_fetch_assoc($r) : null;
    return $row ? (string) $row['alert_last_sent_at'] : null;
};
?>
<!-- ── Integration sync watch ────────────────────────────────────────────── -->
<div class="card mb-3" id="sync-watch">
    <div class="card-header py-2">
        <h3 class="card-title"><i class="fas fa-fw fa-sync-alt me-2"></i>Integration sync watch</h3>
    </div>
    <div class="card-body">
        <p class="text-muted small mb-2">
            Watches every enabled RMM, Intune and UniFi integration through its sync log. <strong>Failing</strong>: its last
            <?= (int) RecoverySettings::get($mysqli, 'sync_error_threshold') ?> runs all ended in a real error. <strong>Stale</strong>: no run in three times its expected interval.
            <strong>Dormant</strong>: its newest run is more than <?= (int) RecoverySettings::get($mysqli, 'sync_dormant_days') ?> days old, so it is treated as no longer running and nothing is mailed.
            <strong>Muted</strong>: you silenced it. A problem is mailed at most once every <?= RecoveryAlerts::repeatHours($mysqli) ?> hours, and a recovery once.
            A sync run by an external script should write to <code>rmm_sync_log</code> / <code>unifi_sync_log</code>, or you can declare how often it runs here.
        </p>
        <?php if ($sw_rows === []): ?>
            <div class="text-muted">No enabled RMM, Intune or UniFi integration to watch.</div>
        <?php else: ?>
        <div class="mb-2">
            <?php foreach ($sw_badge as $st => [$cls, $txt]): if (empty($sw_counts[$st])) { continue; } ?>
                <span class="badge text-bg-<?= $cls ?> me-1"><?= (int) $sw_counts[$st] ?> <?= $txt ?></span>
            <?php endforeach; ?>
        </div>
        <?php if (!$sw_ready): ?>
            <div class="alert alert-warning py-2">Run the database update (Admin &gt; Update) to switch on Mute and the expected-interval override.</div>
        <?php endif; ?>
        <div class="table-responsive">
            <table class="table table-sm table-striped mb-0 align-middle">
                <thead><tr><th>Integration</th><th>State</th><th>Last run</th><th>Expected</th><th>Why</th><th style="min-width:210px">Controls</th></tr></thead>
                <tbody>
                <?php foreach ($sw_rows as $w):
                    [$cls, $txt] = $sw_badge[$w['state']] ?? ['secondary', $w['state']];
                    $ov = $w['override'];
                    $alerted = null;
                    foreach (['failing', 'stale', 'dormant'] as $k) { $alerted = $alerted ?: $sw_alert_ts("sync.{$w['source']}.{$w['id']}.$k"); }
                    $src = ['override' => 'set by an admin', 'setting' => 'from the setting above', 'inferred' => 'inferred from its runs', 'off' => 'not watched'][$w['interval_source']] ?? '';
                    $formId = 'syncwatch_' . $w['source'] . '_' . $w['id'];
                    $curS = $ov && $ov['expected_interval_s'] ? (int) $ov['expected_interval_s'] : null;
                    $curUnit = $curS !== null && $curS % 86400 === 0 ? 'd' : ($curS !== null && $curS % 3600 === 0 ? 'h' : 'm');
                    $curVal = $curS === null ? '' : ($curUnit === 'd' ? $curS / 86400 : ($curUnit === 'h' ? $curS / 3600 : (int) round($curS / 60)));
                ?>
                    <tr>
                        <td><strong><?= nullable_htmlentities($w['label']) ?></strong> <?= nullable_htmlentities($w['name']) ?></td>
                        <td><span class="badge text-bg-<?= $cls ?>"><?= $txt ?></span>
                            <?php if ($w['state'] === 'muted'): ?><small class="d-block text-muted">would be <?= nullable_htmlentities($sw_badge[$w['underlying']][1] ?? $w['underlying']) ?></small><?php endif; ?></td>
                        <td class="text-nowrap"><?= nullable_htmlentities($sw_ago($w['last_ts'], $w['now'])) ?>
                            <?php if ($w['last_status']): ?><small class="d-block text-muted"><?= nullable_htmlentities($w['last_status']) ?></small><?php endif; ?></td>
                        <td class="text-nowrap"><?= $w['interval_min'] ? 'every ' . nullable_htmlentities(SyncWatch::human($w['interval_min'] * 60)) : '-' ?>
                            <small class="d-block text-muted"><?= nullable_htmlentities($src) ?></small></td>
                        <td class="small"><?= nullable_htmlentities($w['detail']) ?>
                            <?php if ($alerted): ?><small class="d-block text-muted">last alert sent <?= nullable_htmlentities($alerted) ?></small><?php endif; ?>
                            <?php if ($ov && $ov['note'] !== ''): ?><small class="d-block text-muted">note: <?= nullable_htmlentities($ov['note']) ?></small><?php endif; ?></td>
                        <td>
                            <form action="post.php" method="post" class="d-flex flex-wrap gap-1 align-items-center m-0" id="<?= $formId ?>">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                <input type="hidden" name="sw_source" value="<?= nullable_htmlentities($w['source']) ?>">
                                <input type="hidden" name="sw_id" value="<?= (int) $w['id'] ?>">
                                <?php if ($w['state'] === 'muted'): ?>
                                    <button type="submit" name="sync_watch_action" value="unmute" class="btn btn-sm btn-outline-secondary" <?= $sw_ready ? '' : 'disabled' ?>>Unmute</button>
                                <?php else: ?>
                                    <button type="submit" name="sync_watch_action" value="mute" class="btn btn-sm btn-outline-secondary" <?= $sw_ready ? '' : 'disabled' ?>>Mute</button>
                                <?php endif; ?>
                                <?php if ($w['underlying'] === 'dormant' || $w['last_ts'] !== null): ?>
                                    <button type="submit" name="sync_watch_action" value="forget" class="btn btn-sm btn-outline-secondary" <?= $sw_ready ? '' : 'disabled' ?>
                                            title="Ignore every run so far. Nothing is deleted; the integration stays quiet until it runs again.">Forget history</button>
                                <?php endif; ?>
                            </form>
                            <details class="mt-1"><summary class="small text-muted">Expected interval</summary>
                                <form action="post.php" method="post" class="mt-1 m-0">
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                    <input type="hidden" name="sw_source" value="<?= nullable_htmlentities($w['source']) ?>">
                                    <input type="hidden" name="sw_id" value="<?= (int) $w['id'] ?>">
                                    <div class="input-group input-group-sm mb-1">
                                        <input type="number" min="1" max="9999" class="form-control" name="sw_interval" value="<?= nullable_htmlentities((string) $curVal) ?>" placeholder="blank = default" aria-label="Expected interval">
                                        <select class="form-select" name="sw_unit" aria-label="Unit">
                                            <option value="m" <?= $curUnit === 'm' ? 'selected' : '' ?>>min</option>
                                            <option value="h" <?= $curUnit === 'h' ? 'selected' : '' ?>>hours</option>
                                            <option value="d" <?= $curUnit === 'd' ? 'selected' : '' ?>>days</option>
                                        </select>
                                    </div>
                                    <input type="text" class="form-control form-control-sm mb-1" name="sw_note" maxlength="255" value="<?= nullable_htmlentities($ov['note'] ?? '') ?>" placeholder="note, e.g. external script" aria-label="Note">
                                    <button type="submit" name="sync_watch_action" value="interval" class="btn btn-sm btn-primary" <?= $sw_ready ? '' : 'disabled' ?>>Save</button>
                                </form>
                            </details>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
