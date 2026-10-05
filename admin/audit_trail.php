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

$where = ['1=1'];
$types = '';
$args = [];
if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $where[] = '(e.summary LIKE ? OR e.event_type LIKE ? OR e.entity_id LIKE ? OR e.ip_address LIKE ? OR e.metadata_json LIKE ?)';
    $types .= 'sssss';
    array_push($args, $like, $like, $like, $like, $like);
}
if ($type !== '') {
    $where[] = '(e.event_type = ? OR e.event_type LIKE ?)';
    $types .= 'ss';
    array_push($args, $type, addcslashes($type, '%_\\') . '.%');
}
if ($actor > 0) {
    $where[] = 'e.actor_user_id = ?';
    $types .= 'i';
    $args[] = $actor;
}
if ($from !== '') {
    $where[] = 'e.created_at >= ?';
    $types .= 's';
    $args[] = $from . ' 00:00:00';
}
if ($to !== '') {
    $where[] = 'e.created_at <= ?';
    $types .= 's';
    $args[] = $to . ' 23:59:59';
}
$whereSql = implode(' AND ', $where);

$run = static function (string $sql, string $types, array $args) use ($mysqli): array {
    $st = $mysqli->prepare($sql);
    if (!$st) {
        return [];
    }
    if ($types !== '') {
        $st->bind_param($types, ...$args);
    }
    $st->execute();
    $res = $st->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $st->close();

    return $rows;
};

$total = 0;
$rows = [];
$groups = [];
$actors = [];
if ($ready) {
    $base = "FROM audit_events e LEFT JOIN users u ON u.user_id = e.actor_user_id WHERE $whereSql";

    if ($export_requested) {
        $all = $run("SELECT e.audit_id, e.created_at, e.event_type, e.actor_user_id, u.user_name, e.entity_type, e.entity_id, e.action, e.summary, e.ip_address, e.request_id, e.metadata_json $base ORDER BY e.audit_id DESC LIMIT 50000", $types, $args);
        if (function_exists('rivetAudit')) {
            rivetAudit('audit.exported', (int) $session_user_id, 'audit', 'trail', 'export', 'Audit trail exported to CSV (' . count($all) . ' rows)');
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="audit-trail-' . date('Ymd-His') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['id', 'time', 'event', 'actor_id', 'actor', 'entity_type', 'entity_id', 'action', 'summary', 'ip', 'request_id', 'metadata']);
        foreach ($all as $r) {
            // Spreadsheet formula injection: neutralise cells that start with a formula character.
            $line = array_map(static fn ($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : $v, array_values($r));
            fputcsv($out, $line);
        }
        fclose($out);
        exit;
    }

    $count = $run("SELECT COUNT(*) AS n $base", $types, $args);
    $total = (int) ($count[0]['n'] ?? 0);
    $pages = max(1, (int) ceil($total / $per_page));
    $page_no = min($page_no, $pages);
    $offset = ($page_no - 1) * $per_page;
    $rows = $run("SELECT e.*, u.user_name $base ORDER BY e.audit_id DESC LIMIT $per_page OFFSET $offset", $types, $args);
    foreach ($run("SELECT SUBSTRING_INDEX(event_type, '.', 1) AS grp, COUNT(*) AS n FROM audit_events GROUP BY grp ORDER BY grp", '', []) as $g) {
        $groups[$g['grp']] = (int) $g['n'];
    }
    $actors = $run("SELECT DISTINCT u.user_id, u.user_name FROM audit_events e JOIN users u ON u.user_id = e.actor_user_id ORDER BY u.user_name", '', []);
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
