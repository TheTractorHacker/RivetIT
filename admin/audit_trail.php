<?php
// Bootstrap and sign-in check first: the CSV export has to answer before any of the admin shell is printed.
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/event_bus.php';
$export_requested = ($_GET['export'] ?? '') === 'csv' && !empty($session_is_admin);
if (!$export_requested) {
    require_once "includes/inc_all_admin.php";
}

/*
 * Audit trail: the structured, append-only record kept by RivetCore (sign-ins, settings and user changes, credential reveals,
 * automation runs, compliance actions...). The older "Audit log" page shows the plain activity log; this one shows audit_events.
 */

$h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$ready = rivetTableExists($mysqli, 'audit_events');

$q = trim((string) ($_GET['q'] ?? ''));
$type = trim((string) ($_GET['type'] ?? ''));
$actor = (int) ($_GET['actor'] ?? 0);
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['from'] ?? '')) ? $_GET['from'] : '';
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['to'] ?? '')) ? $_GET['to'] : '';
$page_no = max(1, (int) ($_GET['page'] ?? 1));
$per_page = 50;

$filters = ['search' => $q, 'eventType' => $type, 'actorUserId' => $actor, 'from' => $from, 'to' => $to];

// The audit rows carry actor ids only; the users table belongs to this edition, so names are looked up here (cached across calls).
$userNames = [];
$nameOf = static function (array $ids) use ($mysqli, &$userNames): void {
    $ids = array_values(array_diff(array_unique(array_filter(array_map('intval', $ids))), array_keys($userNames)));
    if (!$ids) {
        return;
    }
    $res = mysqli_query($mysqli, 'SELECT user_id, user_name FROM users WHERE user_id IN (' . implode(',', $ids) . ')');
    while ($res && ($u = mysqli_fetch_assoc($res))) {
        $userNames[(int) $u['user_id']] = (string) $u['user_name'];
    }
    foreach ($ids as $id) {
        $userNames[$id] ??= '';
    }
};

$total = 0;
$rows = [];
$groups = [];
$actors = [];
if ($ready) {
    $reader = new \RivetCore\Audit\AuditReader(rivetCoreDb($mysqli));

    if ($export_requested) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="audit-trail-' . date('Ymd-His') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['id', 'time', 'event', 'actor_id', 'actor', 'entity_type', 'entity_id', 'action', 'summary', 'ip', 'request_id', 'metadata']);
        $exported = 0;
        foreach ($reader->iterate($filters, \RivetCore\Audit\AuditReader::DEFAULT_EXPORT_CAP) as $r) {
            $nameOf([$r['actor_user_id']]);
            $line = [$r['audit_id'], $r['created_at'], $r['event_type'], $r['actor_user_id'], $r['actor_user_id'] ? ($userNames[(int) $r['actor_user_id']] ?? '') : null,
                $r['entity_type'], $r['entity_id'], $r['action'], $r['summary'], $r['ip_address'], $r['request_id'], $r['metadata_json']];
            // Spreadsheet formula injection: neutralise cells that start with a formula character.
            fputcsv($out, array_map(static fn ($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : $v, $line));
            $exported++;
        }
        fclose($out);
        rivetAudit('audit.exported', (int) $session_user_id, 'audit', 'trail', 'export', 'Audit trail exported to CSV (' . $exported . ' rows)');
        exit;
    }

    $result = $reader->page($filters, $page_no, $per_page);
    $total = $result->total;
    $pages = $result->pages;
    $page_no = $result->page;
    $rows = $result->rows;
    $nameOf(array_column($rows, 'actor_user_id'));
    foreach ($rows as &$row) {
        $row['user_name'] = $row['actor_user_id'] ? ($userNames[(int) $row['actor_user_id']] ?? null) : null;
    }
    unset($row);
    $groups = $reader->groups();
    $actorIds = $reader->actors();
    $nameOf($actorIds);
    foreach ($actorIds as $id) {
        if (($userNames[$id] ?? '') !== '') {
            $actors[] = ['user_id' => $id, 'user_name' => $userNames[$id]];
        }
    }
    usort($actors, static fn ($x, $y) => strcasecmp($x['user_name'], $y['user_name']));
}
$pages = $pages ?? 1;
$qs = static function (array $over = []) use ($q, $type, $actor, $from, $to): string {
    return '?' . http_build_query(array_filter(array_merge(['q' => $q, 'type' => $type, 'actor' => $actor ?: '', 'from' => $from, 'to' => $to], $over), static fn ($v) => $v !== '' && $v !== null));
};
$tone = static function (string $event): string {
    return match (true) {
        str_contains($event, 'failed'), str_contains($event, 'blocked') => 'danger',
        str_contains($event, 'delete'), str_contains($event, 'revealed'), str_contains($event, 'view') => 'warning text-dark',
        str_starts_with($event, 'auth.') => 'info',
        default => 'secondary',
    };
};
?>

<div class="card mb-3">
    <div class="card-header py-3 d-flex justify-content-between align-items-center">
        <h3 class="card-title mb-0"><i class="fas fa-fw fa-fingerprint me-2"></i>Audit trail</h3>
        <?php if ($ready) { ?><a class="btn btn-outline-secondary btn-sm" href="<?= $h($qs(['export' => 'csv'])) ?>"><i class="fas fa-download me-1"></i>Export CSV</a><?php } ?>
    </div>
    <div class="card-body">
        <p class="text-muted mb-0">A tamper-evident record of who did what: sign-ins, settings and user changes, credential reveals, automation runs and compliance actions. Entries are only ever added;
        old ones are removed by the retention period set under Compliance. The older <a href="audit_log.php">Audit log</a> shows the plain activity log.</p>
    </div>
</div>

<?php
// Integrity (hash chain) and the optional copy to a file / syslog: src/Audit/AuditChain.php, AuditSink.php
$chain_cols = $ready && mysqli_num_rows(mysqli_query($mysqli, "SHOW COLUMNS FROM audit_events LIKE 'row_hash'")) > 0;
if ($chain_cols) {
    $chain_last = json_decode(\ITFlow\Platform\PlatformSettings::get($mysqli, 'audit_chain_last_verify', ''), true) ?: null;
    $sink_path = \ITFlow\Platform\PlatformSettings::get($mysqli, 'audit_sink_path');
    $sink_syslog = \ITFlow\Platform\PlatformSettings::bool($mysqli, 'audit_sink_syslog');
    $sink_problem = $sink_path !== '' ? \ITFlow\Audit\AuditSink::pathProblem($sink_path, dirname(__DIR__)) : null;
    $chain_status_label = ['ok' => ['success', 'Intact'], 'broken' => ['danger', 'BROKEN'], 'empty' => ['secondary', 'Nothing sealed yet'], 'key_changed' => ['warning text-dark', 'Key changed'], 'key_unavailable' => ['warning text-dark', 'Key missing']];
?>
<div class="card mb-3" id="audit-integrity">
    <div class="card-header py-2"><h3 class="card-title mb-0"><i class="fas fa-fw fa-link me-2"></i>Integrity and copies</h3></div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-lg-6">
                <h6 class="text-muted text-uppercase small">Hash chain</h6>
                <p class="small text-muted">Every entry carries a hash of itself and of the entry before it (keyed from the settings key when there is one). The check runs every night and raises an alert when an entry was edited, removed or reordered.</p>
                <?php if ($chain_last) { [$cls, $lbl] = $chain_status_label[$chain_last['status']] ?? ['secondary', $chain_last['status']]; ?>
                    <p class="mb-2"><span class="badge text-bg-<?= $cls ?>" data-audit-chain-status="<?= $h($chain_last['status']) ?>"><?= $h($lbl) ?></span>
                        <span class="text-muted small">checked <?= $h($chain_last['at']) ?> UTC, <?= number_format((int) $chain_last['checked']) ?> entries</span></p>
                    <?php if (!empty($chain_last['reason'])) { ?><p class="small text-danger mb-2"><?= $h($chain_last['reason']) ?></p><?php } ?>
                <?php } else { ?>
                    <p class="text-muted small mb-2">Not checked yet. The first check runs with the next nightly cron, or run it now.</p>
                <?php } ?>
                <form action="post.php" method="post" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?= $h($_SESSION['csrf_token']) ?>">
                    <button type="submit" name="verify_audit_chain" class="btn btn-outline-primary btn-sm"><i class="fas fa-check-double me-1"></i>Verify now</button>
                </form>
            </div>
            <div class="col-lg-6">
                <h6 class="text-muted text-uppercase small">Copy to a file or syslog</h6>
                <form action="post.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?= $h($_SESSION['csrf_token']) ?>">
                    <div class="mb-2">
                        <label class="form-label small mb-1" for="audit_sink_path">JSON-lines file (empty = off)</label>
                        <input type="text" class="form-control form-control-sm" id="audit_sink_path" name="audit_sink_path" value="<?= $h($sink_path) ?>" placeholder="/var/log/rivetit/audit.jsonl" maxlength="255" autocomplete="off">
                        <?php if ($sink_problem) { ?><div class="small text-danger mt-1"><?= $h($sink_problem) ?></div><?php } ?>
                    </div>
                    <div class="form-check form-switch mb-2">
                        <input type="checkbox" class="form-check-input" id="audit_sink_syslog" name="audit_sink_syslog" value="1" <?= $sink_syslog ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="audit_sink_syslog">Also send each entry to syslog (facility auth, tag rivetit-audit)</label>
                    </div>
                    <button type="submit" name="save_audit_sink" class="btn btn-primary btn-sm"><i class="fas fa-check me-1"></i>Save</button>
                    <div class="small text-muted mt-2">Off by default. One JSON object per line, with the entry's hashes. The directory must exist, be writable by the web server user and sit outside the application folder.</div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php } ?>

<?php if (!$ready) { ?>
    <div class="alert alert-warning">The audit trail is not available yet. Run the database update (Administration &rarr; Update).</div>
<?php } else { ?>
<div class="card mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-3"><label class="form-label small mb-1">Search</label><input class="form-control form-control-sm" name="q" value="<?= $h($q) ?>" placeholder="summary, id, IP address"></div>
            <div class="col-md-2"><label class="form-label small mb-1">Event</label>
                <select class="form-select form-select-sm" name="type"><option value="">All events</option>
                    <?php foreach ($groups as $g => $n) { ?><option value="<?= $h($g) ?>" <?= $type === $g ? 'selected' : '' ?>><?= $h($g) ?> (<?= $n ?>)</option><?php } ?>
                </select></div>
            <div class="col-md-2"><label class="form-label small mb-1">Who</label>
                <select class="form-select form-select-sm" name="actor"><option value="">Anyone</option>
                    <?php foreach ($actors as $a) { ?><option value="<?= (int) $a['user_id'] ?>" <?= $actor === (int) $a['user_id'] ? 'selected' : '' ?>><?= $h($a['user_name']) ?></option><?php } ?>
                </select></div>
            <div class="col-md-2"><label class="form-label small mb-1">From</label><input type="date" class="form-control form-control-sm" name="from" value="<?= $h($from) ?>"></div>
            <div class="col-md-2"><label class="form-label small mb-1">To</label><input type="date" class="form-control form-control-sm" name="to" value="<?= $h($to) ?>"></div>
            <div class="col-md-1 d-flex gap-1"><button class="btn btn-primary btn-sm">Filter</button><a class="btn btn-outline-secondary btn-sm" href="audit_trail.php">Reset</a></div>
        </form>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header py-2 small text-muted"><?= number_format($total) ?> event<?= $total === 1 ? '' : 's' ?></div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Time</th><th>Event</th><th>Who</th><th>What</th><th>From</th><th></th></tr></thead>
            <tbody>
            <?php if (!$rows) { ?><tr><td colspan="6" class="text-center text-muted py-3">No events match.</td></tr><?php } ?>
            <?php foreach ($rows as $r) {
                $meta = $r['metadata_json'] !== null && $r['metadata_json'] !== '' ? json_decode((string) $r['metadata_json'], true) : null; ?>
                <tr>
                    <td class="small text-nowrap"><?= $h($r['created_at']) ?></td>
                    <td><span class="badge text-bg-<?= $tone((string) $r['event_type']) ?>"><?= $h($r['event_type']) ?></span></td>
                    <td class="small"><?= $r['actor_user_id'] ? $h($r['user_name'] ?: ('user #' . $r['actor_user_id'])) : '<span class="text-muted">system / anonymous</span>' ?></td>
                    <td class="small"><?= $h($r['summary']) ?><?= $r['entity_type'] ? ' <span class="text-muted">(' . $h($r['entity_type']) . ($r['entity_id'] !== null ? ' #' . $h($r['entity_id']) : '') . ')</span>' : '' ?></td>
                    <td class="small text-secondary"><?= $h($r['ip_address']) ?></td>
                    <td class="text-end"><?php if (is_array($meta) && $meta) { ?>
                        <button class="btn btn-link btn-sm p-0" type="button" data-bs-toggle="collapse" data-bs-target="#meta<?= (int) $r['audit_id'] ?>">details</button><?php } ?></td>
                </tr>
                <?php if (is_array($meta) && $meta) { ?>
                <tr class="collapse" id="meta<?= (int) $r['audit_id'] ?>"><td colspan="6" class="bg-body-tertiary"><pre class="small mb-0"><?= $h(json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
                    <?php if ($r['request_id']) { ?><div class="small text-muted mt-1">request <?= $h($r['request_id']) ?></div><?php } ?></td></tr>
                <?php } ?>
            <?php } ?>
            </tbody>
        </table>
    </div>
    <?php if ($pages > 1) { ?>
    <div class="card-footer d-flex justify-content-between align-items-center">
        <span class="small text-muted">Page <?= $page_no ?> of <?= $pages ?></span>
        <div class="btn-group btn-group-sm">
            <?php if ($page_no > 1) { ?><a class="btn btn-outline-secondary" href="<?= $h($qs(['page' => $page_no - 1])) ?>">&laquo; Newer</a><?php } ?>
            <?php if ($page_no < $pages) { ?><a class="btn btn-outline-secondary" href="<?= $h($qs(['page' => $page_no + 1])) ?>">Older &raquo;</a><?php } ?>
        </div>
    </div>
    <?php } ?>
</div>
<?php } ?>

<?php require_once "../includes/footer.php";
