<?php
require_once "includes/inc_all_admin.php";
enforceUserPermission('module_admin');

use ITFlow\Integrations\Microsoft\EntraHealth;
use ITFlow\Integrations\Microsoft\EntraStatus;

/*
 * Entra / Intune sync status: per sync its switch and schedule, the last real run (counts, classified error), the last preview (dry run),
 * and when the next scheduled run is due; the client-secret expiry; and what the group, license and Intune detail syncs stored.
 * Read-only apart from the run buttons, which post to admin/post/entra_status.php (the same handlers as the Integrations page).
 */

$ms = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM microsoft_integrations ORDER BY microsoft_integration_id DESC LIMIT 1")) ?: [];
$ms_id = (int) ($ms['microsoft_integration_id'] ?? 0);
$now = time();
$configured = $ms_id > 0 && !empty($ms['client_secret_enc']);
$syncs = $configured ? EntraStatus::syncs($mysqli, $ms, $now) : [];
$inv = $configured ? EntraStatus::inventory($mysqli, $ms_id) : ['groups' => [], 'licenses' => [], 'details' => ['devices' => 0, 'with_details' => 0, 'policies' => 0, 'noncompliant' => 0, 'apps' => 0]];
$secret = $configured ? EntraHealth::secretState($ms['secret_expires_at'] ?? null, $now) : ['state' => 'none', 'days' => null];
$secret_badge = ['none' => ['secondary', 'No date recorded'], 'ok' => ['success', 'Valid'], 'warn' => ['warning', 'Expires soon'], 'crit' => ['danger', 'Expires within 7 days'], 'expired' => ['danger', 'Expired']];
$status_badge = ['success' => 'text-bg-success', 'failed' => 'text-bg-danger', 'running' => 'text-bg-secondary'];
$ago = static fn (?int $ts): string => $ts === null ? 'never' : EntraStatus::human(max(0, time() - $ts)) . ' ago';
$extra_kinds = ['groups' => 'groups', 'licenses' => 'licenses', 'intune_details' => 'intune_details'];
?>
<div class="card mb-3">
    <div class="card-header py-3 d-flex flex-wrap align-items-center gap-2">
        <h3 class="card-title mb-0 me-auto"><i class="fab fa-fw fa-microsoft me-2"></i>Entra / Intune sync status</h3>
        <a class="btn btn-sm btn-outline-secondary" href="/admin/settings_integrations.php?tab=directorysync"><i class="fas fa-cog me-1"></i>Directory Sync settings</a>
        <a class="btn btn-sm btn-outline-secondary" href="/admin/settings_integrations.php?tab=devicesync"><i class="fas fa-laptop me-1"></i>Device Sync settings</a>
        <a class="btn btn-sm btn-outline-secondary" href="/admin/backup.php#sync-watch"><i class="fas fa-heartbeat me-1"></i>Sync watch</a>
    </div>
    <div class="card-body">
        <p class="text-muted small mb-0">
            One place for the last run of every Entra and Intune sync. <strong>Sync now</strong> runs it straight away; <strong>Preview</strong> does the whole run inside a
            transaction that is rolled back, so you see the real counts and nothing is changed. Previews are listed separately and never count as a healthy run for the
            stalled-sync alerts. Everything here only reads from Microsoft. <strong>Live behaviour is unverified:</strong> see the checklist in docs/ENTRA_INTUNE_SETUP.md.
        </p>
    </div>
</div>

<?php if (!$configured) { ?>
    <div class="alert alert-info">No Microsoft connection is saved yet. Set one up under <a href="/admin/settings_integrations.php?tab=directorysync">Administration &gt; Integrations &gt; Directory Sync</a>.</div>
<?php } else { ?>

<div class="card mb-3" id="entra-secret">
    <div class="card-header py-2"><h4 class="card-title mb-0"><i class="fas fa-fw fa-key me-2"></i>Client secret</h4></div>
    <div class="card-body">
        <span class="badge text-bg-<?= $secret_badge[$secret['state']][0] ?> me-2"><?= nullable_htmlentities($secret_badge[$secret['state']][1]) ?></span>
        <?php if ($secret['state'] === 'none') { ?>
            <span class="text-muted">No expiry date is recorded, so nobody is warned before the secret stops working. Enter it on the Directory Sync tab.</span>
        <?php } else { ?>
            Expires <strong><?= nullable_htmlentities((string) $ms['secret_expires_at']) ?></strong>
            (<?= $secret['state'] === 'expired' ? 'expired ' . abs((int) $secret['days']) . ' days ago' : (int) $secret['days'] . ' days left' ?>),
            <?= ($ms['secret_expiry_source'] ?? '') === 'graph' ? 'looked up from Entra' : 'entered by hand' ?>.
            Alerts go to the administrators 30 days and 7 days before, and when it has expired.
        <?php } ?>
    </div>
</div>

<div class="card mb-3" id="entra-syncs">
    <div class="card-header py-2"><h4 class="card-title mb-0"><i class="fas fa-fw fa-sync-alt me-2"></i>Syncs</h4></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0" style="min-width:820px">
                <thead><tr><th class="ps-3">Sync</th><th>Schedule</th><th>Last run</th><th>Result</th><th>Next</th><th style="min-width:200px">Run</th></tr></thead>
                <tbody>
                <?php foreach ($syncs as $s) {
                    $l = $s['last'];
                    $p = $s['preview']; ?>
                    <tr>
                        <td class="ps-3">
                            <strong><?= nullable_htmlentities($s['label']) ?></strong>
                            <?php if (!$s['on']) { ?><span class="badge text-bg-secondary ms-1">off</span><?php } ?>
                        </td>
                        <td class="small"><?= nullable_htmlentities($s['schedule']) ?></td>
                        <td class="small">
                            <?php if ($l) { ?>
                                <?= nullable_htmlentities($ago($l['started'])) ?>
                                <span class="text-muted">(<?= nullable_htmlentities($l['trigger']) ?><?= $l['mode'] !== '' ? ', ' . nullable_htmlentities($l['mode']) : '' ?>)</span>
                            <?php } else { ?><span class="text-muted">never</span><?php } ?>
                            <?php if ($p) { ?><div class="text-muted">preview <?= nullable_htmlentities($ago($p['started'])) ?>: <?= nullable_htmlentities($p['counts']) ?></div><?php } ?>
                        </td>
                        <td class="small">
                            <?php if ($l) { ?>
                                <span class="badge <?= $status_badge[$l['status']] ?? 'text-bg-secondary' ?>"><?= nullable_htmlentities($l['status']) ?></span>
                                <div class="text-muted"><?= nullable_htmlentities($l['counts']) ?></div>
                                <?php if ($l['status'] === 'failed') { ?>
                                    <div class="text-danger"><?php if ($l['error_label'] !== '') { ?><strong><?= nullable_htmlentities($l['error_label']) ?>.</strong> <?php } ?><?= nullable_htmlentities(mb_strimwidth($l['error'], 0, 300, '...')) ?></div>
                                    <?php if ($s['lastOk']) { ?><div class="text-muted">last success <?= nullable_htmlentities($ago($s['lastOk']['started'])) ?></div><?php } ?>
                                <?php } ?>
                            <?php } else { ?><span class="text-muted">-</span><?php } ?>
                        </td>
                        <td class="small"><?= nullable_htmlentities($s['next']) ?></td>
                        <td>
                            <?php if ($s['on']) { ?>
                            <form action="post.php" method="post" class="d-flex flex-wrap gap-1">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                <?php if ($s['key'] === 'directory') { ?>
                                    <button type="submit" name="sync_microsoft_directory" value="1" class="btn btn-success btn-sm"><i class="fas fa-sync me-1"></i>Sync now</button>
                                    <button type="submit" name="preview_microsoft_directory" value="1" class="btn btn-outline-secondary btn-sm"><i class="fas fa-eye me-1"></i>Preview</button>
                                    <?php if (!empty($ms['directory_delta_enabled'])) { ?>
                                    <button type="submit" name="fullresync_microsoft_directory" value="1" class="btn btn-outline-warning btn-sm" title="Read every user again and take a new delta token (safe: the sync is idempotent)"><i class="fas fa-redo me-1"></i>Full resync</button>
                                    <?php } ?>
                                <?php } elseif ($s['key'] === 'intune') { ?>
                                    <button type="submit" name="sync_intune_devices" value="1" class="btn btn-success btn-sm"><i class="fas fa-sync me-1"></i>Sync now</button>
                                    <button type="submit" name="preview_intune_devices" value="1" class="btn btn-outline-secondary btn-sm"><i class="fas fa-eye me-1"></i>Preview</button>
                                <?php } else { ?>
                                    <button type="submit" name="run_entra_extra" value="<?= nullable_htmlentities($s['key']) ?>" class="btn btn-success btn-sm"><i class="fas fa-sync me-1"></i>Sync now</button>
                                    <button type="submit" name="run_entra_extra" value="<?= nullable_htmlentities($s['key']) ?>:dry" class="btn btn-outline-secondary btn-sm"><i class="fas fa-eye me-1"></i>Preview</button>
                                <?php } ?>
                            </form>
                            <?php } else { ?><span class="text-muted small">switched off</span><?php } ?>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-lg-6">
        <div class="card mb-3" id="entra-licenses">
            <div class="card-header py-2"><h4 class="card-title mb-0"><i class="fas fa-fw fa-id-card me-2"></i>Licenses</h4></div>
            <div class="card-body p-0">
                <?php if (!$inv['licenses']) { ?>
                    <p class="text-muted text-center py-3 mb-0">No license data yet. Switch on license sync on the Directory Sync tab and run it.</p>
                <?php } else { ?>
                <div class="table-responsive">
                    <table class="table table-sm mb-0 align-middle">
                        <thead><tr><th class="ps-3">License</th><th class="text-end">Used</th><th class="text-end">Bought</th><th class="text-end pe-3">Free</th></tr></thead>
                        <tbody>
                        <?php foreach ($inv['licenses'] as $lic) {
                            $free = (int) $lic['units_enabled'] - (int) $lic['units_consumed']; ?>
                            <tr>
                                <td class="ps-3"><?= nullable_htmlentities($lic['friendly_name'] !== '' ? $lic['friendly_name'] : $lic['sku_part_number']) ?>
                                    <?php if ($lic['capability_status'] && $lic['capability_status'] !== 'Enabled') { ?><span class="badge text-bg-warning ms-1"><?= nullable_htmlentities($lic['capability_status']) ?></span><?php } ?>
                                    <div class="text-muted small"><?= nullable_htmlentities($lic['sku_part_number']) ?>, <?= (int) $lic['assigned'] ?> people hold it</div>
                                </td>
                                <td class="text-end"><?= (int) $lic['units_consumed'] ?></td>
                                <td class="text-end"><?= (int) $lic['units_enabled'] ?></td>
                                <td class="text-end pe-3"><span class="<?= $free < 0 ? 'text-danger fw-bold' : ($free === 0 && (int) $lic['units_enabled'] > 0 ? 'text-warning' : '') ?>"><?= $free ?></span></td>
                            </tr>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
                <?php } ?>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card mb-3" id="entra-details">
            <div class="card-header py-2"><h4 class="card-title mb-0"><i class="fas fa-fw fa-shield-alt me-2"></i>Intune compliance and apps</h4></div>
            <div class="card-body">
                <?php $d = $inv['details']; ?>
                <?php if ($d['with_details'] === 0) { ?>
                    <p class="text-muted mb-0">Nothing read yet. Switch on the compliance and app detail sync on the Device Sync tab and run it.</p>
                <?php } else { ?>
                    <p class="mb-1"><strong><?= (int) $d['with_details'] ?></strong> of <strong><?= (int) $d['devices'] ?></strong> Intune devices have had their policy states and apps read.</p>
                    <p class="mb-1"><?= (int) $d['policies'] ?> policy states stored, <span class="<?= $d['noncompliant'] > 0 ? 'text-danger fw-bold' : '' ?>"><?= (int) $d['noncompliant'] ?> not compliant, in error or in conflict</span>.</p>
                    <p class="mb-0"><?= (int) $d['apps'] ?> detected apps stored. Open a device under <a href="/agent/intune_devices.php">Intune Devices</a> to see its policies and apps.</p>
                <?php } ?>
            </div>
        </div>
        <div class="card mb-3" id="entra-groups">
            <div class="card-header py-2"><h4 class="card-title mb-0"><i class="fas fa-fw fa-users me-2"></i>Groups</h4></div>
            <div class="card-body p-0">
                <?php if (!$inv['groups']) { ?>
                    <p class="text-muted text-center py-3 mb-0">No group data yet. Switch on group sync on the Directory Sync tab and run it.</p>
                <?php } else { ?>
                <div class="table-responsive" style="max-height:340px;overflow:auto">
                    <table class="table table-sm mb-0">
                        <thead><tr><th class="ps-3">Group</th><th>Type</th><th class="text-end pe-3">Members</th></tr></thead>
                        <tbody>
                        <?php foreach ($inv['groups'] as $g) { ?>
                            <tr>
                                <td class="ps-3"><?= nullable_htmlentities($g['display_name']) ?></td>
                                <td class="small text-muted"><?= nullable_htmlentities(str_replace('_', ' ', $g['group_kind'])) ?></td>
                                <td class="text-end pe-3"><?= $g['members_synced_at'] === null ? '<span class="text-muted">not read yet</span>' : (int) $g['member_count'] ?></td>
                            </tr>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
                <?php } ?>
            </div>
        </div>
    </div>
</div>
<?php } ?>

<?php require_once "../includes/footer.php"; ?>
