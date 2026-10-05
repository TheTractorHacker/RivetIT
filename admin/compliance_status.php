<?php
require_once "includes/inc_all_admin.php";

use ITFlow\Compliance\ComplianceService;
use RivetCore\Compliance\Framework;

$csrf = $_SESSION['csrf_token'];
$ready = ComplianceService::ready($mysqli);

$fw = (string) ($_GET['framework'] ?? '');
$fw = Framework::isValid($fw) ? $fw : '';
$fw_key = $fw !== '' ? $fw : 'all';

$assessment = $ready ? ComplianceService::assess($mysqli) : null;
$snapshots = $ready ? ComplianceService::snapshots($mysqli)->list(12) : [];

$status_badge = ['pass' => 'success', 'warn' => 'warning text-dark', 'fail' => 'danger', 'na' => 'secondary', 'error' => 'danger'];
$state_badge = ['current' => ['Current', 'success'], 'due_soon' => ['Due soon', 'warning text-dark'], 'overdue' => ['Overdue', 'danger'], 'never' => ['Never reviewed', 'secondary']];

$fix_href = static function (?string $path): ?string {
    if ($path === null || !preg_match('/^[a-z0-9_]+\.php$/', $path)) {
        return null;
    }
    if (is_file(__DIR__ . '/' . $path)) {
        return $path;
    }

    return is_file(dirname(__DIR__) . '/agent/' . $path) ? '/agent/' . $path : null;
};
$in_fw = static fn (array $controls): bool => $fw === '' || !empty($controls[$fw]);
$controls_text = static function (array $controls) use ($fw): string {
    $parts = [];
    foreach ($controls as $f => $refs) {
        if ($fw !== '' && $f !== $fw) {
            continue;
        }
        $parts[] = (Framework::LABELS[$f] ?? $f) . ': ' . implode(', ', $refs);
    }

    return implode(' · ', $parts);
};
$q = static fn (array $extra): string => http_build_query(array_filter($extra, static fn ($v) => $v !== '' && $v !== null));
?>

<div class="card mb-3">
    <div class="card-header py-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
        <h3 class="card-title mb-0"><i class="fas fa-fw fa-clipboard-list me-2"></i>Compliance status</h3>
        <?php if ($ready) { ?>
        <div class="d-flex gap-2 flex-wrap">
            <a class="btn btn-outline-secondary btn-sm" target="_blank" rel="noopener" href="compliance_report.php?<?= $q(['format' => 'html', 'framework' => $fw]) ?>"><i class="fa fa-print me-1"></i>Printable report</a>
            <a class="btn btn-outline-secondary btn-sm" href="compliance_report.php?<?= $q(['format' => 'csv', 'framework' => $fw]) ?>"><i class="fa fa-file-csv me-1"></i>Download CSV</a>
            <form action="post.php" method="post" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <button type="submit" name="take_compliance_snapshot" class="btn btn-primary btn-sm"><i class="fa fa-camera me-1"></i>Save snapshot</button>
            </form>
        </div>
        <?php } ?>
    </div>
    <div class="card-body">
        <p class="text-muted mb-1">A live view of how this installation measures up against common security controls, plus a checklist for the things software cannot see.
        It helps you prepare for an assessment. It is <strong>not</strong> a certification or an audit opinion, and the control references are indicative: confirm them against the current text of each standard.</p>
        <p class="text-muted small mb-0">Preset and retention settings are on <a href="settings_compliance.php">Compliance</a>.</p>
    </div>
</div>

<?php if (!$ready) { ?>
    <div class="alert alert-warning">Run the database update first (Administration &rarr; Update) to turn on compliance status.</div>
<?php } else { ?>

<div class="row g-3 mb-3">
    <?php foreach (['all' => 'All frameworks'] + Framework::LABELS as $key => $label) {
        $s = $assessment->summaries[$key];
        $score = $s['score'];
        $tone = $score === null ? 'secondary' : ($score >= 80 ? 'success' : ($score >= 50 ? 'warning' : 'danger'));
        $active = $key === $fw_key; ?>
    <div class="col-12 col-md-6 col-xl">
        <a class="text-decoration-none" href="?<?= $q(['framework' => $key === 'all' ? '' : $key]) ?>">
            <div class="card h-100 <?= $active ? 'border-primary' : '' ?>">
                <div class="card-body py-3">
                    <div class="small text-muted"><?= nullable_htmlentities($label) ?></div>
                    <div class="fs-2 fw-semibold text-<?= $tone ?>"><?= $score === null ? '&mdash;' : nullable_htmlentities((string) $score) . '%' ?></div>
                    <div class="small text-muted"><?= (int) $s['pass'] ?> pass &middot; <?= (int) $s['warn'] ?> attention &middot; <?= (int) $s['fail'] + (int) $s['error'] ?> fail<br><?= (int) $s['manual_current'] ?> of <?= (int) $s['manual_current'] + (int) $s['manual_due_soon'] + (int) $s['manual_overdue'] + (int) $s['manual_never'] ?> reviews current</div>
                </div>
            </div>
        </a>
    </div>
    <?php } ?>
</div>

<div class="card mb-3">
    <div class="card-header py-3"><h4 class="card-title mb-0"><i class="fas fa-fw fa-robot me-2"></i>Automatic checks <?= $fw !== '' ? '<small class="text-muted">(' . nullable_htmlentities(Framework::LABELS[$fw]) . ')</small>' : '' ?></h4></div>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 align-middle">
            <thead><tr><th>Check</th><th>Result</th><th>Finding</th><th class="d-none d-lg-table-cell">Controls</th></tr></thead>
            <tbody>
            <?php foreach ($assessment->automatic as $r) { if (!$in_fw($r['controls'])) { continue; } $href = $fix_href($r['fix_path']); ?>
                <tr>
                    <td><strong><?= nullable_htmlentities($r['title']) ?></strong><div class="small text-muted"><?= nullable_htmlentities($r['category']) ?> &middot; <?= nullable_htmlentities($r['why']) ?></div></td>
                    <td><span class="badge bg-<?= $status_badge[$r['status']] ?? 'secondary' ?>"><?= nullable_htmlentities($r['status_label']) ?></span></td>
                    <td><?= nullable_htmlentities($r['summary']) ?><?php if ($r['detail']) { ?><div class="small text-muted"><?= nullable_htmlentities($r['detail']) ?></div><?php } ?>
                        <?php if ($href && in_array($r['status'], ['warn', 'fail'], true)) { ?><a class="small" href="<?= nullable_htmlentities($href) ?>">Fix this &rarr;</a><?php } ?></td>
                    <td class="small text-muted d-none d-lg-table-cell"><?= nullable_htmlentities($controls_text($r['controls'])) ?></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header py-3"><h4 class="card-title mb-0"><i class="fas fa-fw fa-user-check me-2"></i>Manual checklist</h4></div>
    <div class="card-body pb-0"><p class="text-muted small">Things a person must do and sign off. Record each review with who did it and when; the item shows as current until its next review is due. Add the evidence (a link or where the document lives) in the note.</p></div>
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
            <thead><tr><th>Item</th><th>State</th><th>Last review</th><th class="d-none d-lg-table-cell">Controls</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($assessment->manual as $r) { if (!$in_fw($r['controls'])) { continue; } $sb = $state_badge[$r['state']] ?? [$r['state'], 'secondary']; ?>
                <tr>
                    <td><strong><?= nullable_htmlentities($r['title']) ?></strong><div class="small text-muted"><?= nullable_htmlentities($r['category']) ?> &middot; every <?= (int) $r['interval_days'] ?> days &middot; <?= nullable_htmlentities($r['why']) ?></div></td>
                    <td><span class="badge bg-<?= $sb[1] ?>"><?= nullable_htmlentities($sb[0]) ?></span></td>
                    <td class="small"><?php if ($r['reviewed_on']) { ?><?= nullable_htmlentities($r['reviewed_on']) ?> by <?= nullable_htmlentities((string) $r['reviewer_name']) ?><br>next due <?= nullable_htmlentities((string) $r['next_due_on']) ?><?php if ($r['note']) { ?><div class="text-muted"><?= nullable_htmlentities((string) $r['note']) ?></div><?php } ?><?php } else { ?>&mdash;<?php } ?></td>
                    <td class="small text-muted d-none d-lg-table-cell"><?= nullable_htmlentities($controls_text($r['controls'])) ?></td>
                    <td class="text-end"><button class="btn btn-outline-primary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#rev_<?= nullable_htmlentities($r['id']) ?>">Record review</button></td>
                </tr>
                <tr class="collapse" id="rev_<?= nullable_htmlentities($r['id']) ?>">
                    <td colspan="5" class="bg-light">
                        <form action="post.php" method="post" autocomplete="off" class="row g-2 align-items-end">
                            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                            <input type="hidden" name="item_id" value="<?= nullable_htmlentities($r['id']) ?>">
                            <div class="col-md-3"><label class="form-label small mb-0">Reviewed by</label><input class="form-control form-control-sm" name="reviewer_name" maxlength="200" required value="<?= nullable_htmlentities((string) $session_name) ?>"></div>
                            <div class="col-md-2"><label class="form-label small mb-0">Reviewed on</label><input class="form-control form-control-sm" type="date" name="reviewed_on" required value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>"></div>
                            <div class="col-md-2"><label class="form-label small mb-0">Next due <span class="text-muted">(optional)</span></label><input class="form-control form-control-sm" type="date" name="next_due_on"></div>
                            <div class="col-md-4"><label class="form-label small mb-0">Note / evidence</label><input class="form-control form-control-sm" name="note" maxlength="2000"></div>
                            <div class="col-md-1"><button class="btn btn-primary btn-sm w-100" type="submit" name="record_compliance_review">Save</button></div>
                        </form>
                    </td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header py-3"><h4 class="card-title mb-0"><i class="fas fa-fw fa-clock-rotate-left me-2"></i>Snapshots</h4></div>
    <div class="card-body">
        <p class="text-muted small">A snapshot freezes the results above so you can show how your position changed over time. One is saved automatically each month. Snapshots are kept and never deleted by retention.</p>
        <?php if (!$snapshots) { ?>
            <p class="mb-0 text-muted">No snapshots yet.</p>
        <?php } else { ?>
        <div class="table-responsive"><table class="table table-sm mb-0">
            <thead><tr><th>Taken</th><th>How</th><th>Version</th><th>All frameworks</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($snapshots as $s) { $sc = $s['summaries']['all']['score'] ?? null; ?>
                <tr>
                    <td><?= nullable_htmlentities($s['taken_at']) ?></td>
                    <td><?= $s['trigger_type'] === 'scheduled' ? 'Monthly' : 'Manual' ?></td>
                    <td><?= nullable_htmlentities((string) $s['app_version']) ?></td>
                    <td><?= $sc === null ? '&mdash;' : nullable_htmlentities((string) $sc) . '%' ?></td>
                    <td class="text-end">
                        <a class="btn btn-outline-secondary btn-sm" target="_blank" rel="noopener" href="compliance_report.php?<?= $q(['format' => 'html', 'snapshot' => (int) $s['snapshot_id']]) ?>">Report</a>
                        <a class="btn btn-outline-secondary btn-sm" href="compliance_report.php?<?= $q(['format' => 'csv', 'snapshot' => (int) $s['snapshot_id']]) ?>">CSV</a>
                    </td>
                </tr>
            <?php } ?>
            </tbody>
        </table></div>
        <?php } ?>
    </div>
</div>

<?php } ?>

<?php require_once "../includes/footer.php";
