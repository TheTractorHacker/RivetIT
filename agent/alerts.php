<?php

/*
 * Alerts: the one alerts centre. Every source in one list (RivetIT endpoint agent, third-party RMM integrations, backup, network), one severity scale,
 * one state scale, filters kept in the URL, grouped-by-device / grouped-by-check views, and Acknowledge / Resolve / ticket per source through each
 * source's own handler. The old pages agent/rmm_alerts.php and the list view of agent/rmm_agent_alerts.php redirect here. Data layer and the
 * agent-alert reconciliation are in includes/alerts_central.php.
 */

require_once "includes/inc_all.php";
enforceUserPermission('module_rmm_alerts');
require_once dirname(__DIR__) . '/includes/alerts_central.php';
require_once dirname(__DIR__) . '/includes/rmm_ui_kit.php';

alertsCentralReconcileMirror($mysqli);
$f = alertsCentralFilters($_GET);
$clientIds = ($client_access_string && !$session_is_admin) ? array_values(array_filter(array_map('intval', explode(',', (string) $client_access_string)))) : null;
$data = alertsCentralFetch($mysqli, $f, $clientIds);
$counts = $data['counts'];
$can_ack = lookupUserPermission('module_rmm_alerts_ack') >= 1;
$can_ticket = lookupUserPermission('module_support') >= 2;

// One URL builder: every link keeps the other filters.
$url = static function (array $over = []) use ($f): string {
    $q = array_merge(['source' => $f['source'], 'status' => $f['status'], 'severity' => $f['severity'], 'client_id' => $f['client'] ?: '', 'asset_id' => $f['asset'] ?: '', 'q' => $f['q'], 'group' => $f['group']], ['page' => ''], $over);
    $defaults = ['source' => 'all', 'status' => 'new'];
    foreach ($q as $k => $v) {
        if ($v === '' || $v === null || (isset($defaults[$k]) && $v === $defaults[$k] && $k !== 'status')) { unset($q[$k]); }
    }
    if (($q['status'] ?? '') === 'new' && !isset($over['status'])) { unset($q['status']); }

    return '/agent/alerts.php' . ($q ? '?' . http_build_query($q) : '');
};

$tot = ['new' => 0, 'acknowledged' => 0, 'resolved' => 0, 'crit_open' => 0];
foreach ($counts as $src => $c) {
    if ($f['source'] !== 'all' && $f['source'] !== $src) { continue; }
    if ($f['source'] === 'all' && $src === 'network') { continue; }   // network alerts are rmm_alerts rows already counted under agent / rmm
    foreach ($tot as $k => $_) { $tot[$k] += $c[$k]; }
}
$srcLabels = ['all' => 'All sources', 'agent' => 'Endpoint agent', 'rmm' => 'RMM integrations', 'backup' => 'Backup', 'network' => 'Network'];
$statusLabels = ['new' => 'New', 'acknowledged' => 'Acknowledged', 'resolved' => 'Resolved', 'active' => 'New + acknowledged', 'all' => 'All'];

$clientsSql = "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL AND client_id IN (SELECT client_id FROM rmm_alerts WHERE client_id IS NOT NULL UNION SELECT alert_client_id FROM comet_backup_alerts WHERE alert_client_id IS NOT NULL UNION SELECT client_id FROM rmm_alert_meta)"
    . ($clientIds !== null ? ($clientIds ? ' AND client_id IN (' . implode(',', $clientIds) . ')' : ' AND 1=0') : '') . ' ORDER BY client_name';
$clientRows = [];
$cr = mysqli_query($mysqli, $clientsSql);
while ($cr && ($x = mysqli_fetch_assoc($cr))) { $clientRows[] = $x; }
$clientName = '';
foreach ($clientRows as $x) { if ((int) $x['client_id'] === $f['client']) { $clientName = $x['client_name']; } }

// Pagination of the merged list
$alerts = $data['alerts'];
$total = count($alerts);
$pages = max(1, (int) ceil($total / ALERTS_CENTRAL_PAGE_SIZE));
$page = min($f['page'], $pages);
$shown = array_slice($alerts, ($page - 1) * ALERTS_CENTRAL_PAGE_SIZE, ALERTS_CENTRAL_PAGE_SIZE);

// Active filter chips
$chips = [];
if ($f['source'] !== 'all') { $chips['Source: ' . $srcLabels[$f['source']]] = $url(['source' => 'all']); }
if ($f['status'] !== 'new') { $chips['State: ' . $statusLabels[$f['status']]] = $url(['status' => 'new']); }
if ($f['severity'] !== '') { $chips['Severity: ' . ucfirst($f['severity'])] = $url(['severity' => '']); }
if ($f['client'] > 0 && $clientName !== '') { $chips['Department: ' . $clientName] = $url(['client_id' => '']); }
if ($f['asset'] > 0) { $an = ''; $ar = mysqli_query($mysqli, 'SELECT asset_name FROM assets WHERE asset_id = ' . (int) $f['asset']); if ($ar && ($x = mysqli_fetch_assoc($ar))) { $an = $x['asset_name']; } $chips['Device: ' . ($an ?: '#' . $f['asset'])] = $url(['asset_id' => '']); }
if ($f['q'] !== '') { $chips['Search: ' . $f['q']] = $url(['q' => '']); }

$endpointFor = ['agent' => '/agent/post/rmm_automation_alr.php', 'rmm' => '/agent/post/rmm_alert.php', 'network' => '/agent/post/rmm_alert.php', 'backup' => '/agent/post/comet_alert.php'];
$stateTone = ['new' => 'danger', 'acknowledged' => 'warn', 'resolved' => 'ok'];
$sevRowClass = static fn (string $s): string => in_array($s, ['critical', 'warning', 'error'], true) ? ' rmmx-sev-' . $s : '';
$rowKey = static fn (array $a): string => $a['source'] . '-' . $a['id'];
$shortMsg = static function (array $a): string {
    $m = (string) $a['message'];
    if ($a['source'] === 'agent') {
        $m = preg_replace("/^(?:QA-UI-)?Endpoint agent check '[^']*'(?: \\w+)? on [^:]+:\\s*/u", '', $m) ?? $m;
    }

    return $m;
};
$checkOf = static function (array $a): string {
    if ($a['source'] === 'agent') { return (string) ($a['check_key'] ?? ''); }
    if ($a['source'] === 'backup') { return ($a['alert_type'] ?? '') === 'missed' ? 'backup_missed' : 'backup_failed'; }

    return '';
};

$groups = [];
if ($f['group'] !== '') {
    foreach ($shown as $a) {
        $k = $f['group'] === 'device' ? ($a['subject'] ?: '(no device)') : ($checkOf($a) ?: ($a['integration_name'] ?: $srcLabels[$a['source']] ?? 'other'));
        $groups[$k][] = $a;
    }
}
?>

<?= rmmxHead('fa-bell', 'Alerts', 'Everything that needs attention across your devices and backups: endpoint agent, RMM integrations, backup and network alerts in one list.',
    '<a href="/agent/rmm_dashboard.php" class="btn btn-outline-secondary"><i class="fas fa-tachometer-alt me-1" aria-hidden="true"></i>RMM dashboard</a>'
    . '<a href="/agent/backups.php" class="btn btn-outline-secondary"><i class="fas fa-cloud-upload-alt me-1" aria-hidden="true"></i>Backup dashboard</a>') ?>

<?= rmmxKpiGrid([
    ['icon' => 'fa-exclamation-circle', 'label' => 'New alerts', 'value' => $tot['new'], 'sub' => 'Open, not yet acknowledged', 'tone' => 'danger', 'href' => $url(['status' => 'new']), 'active' => $f['status'] === 'new', 'title' => 'Show new alerts'],
    ['icon' => 'fa-fire', 'label' => 'Critical + error open', 'value' => $tot['crit_open'], 'sub' => 'Needs action now', 'tone' => 'danger', 'href' => $url(['status' => 'new', 'severity' => 'critical']), 'active' => $f['severity'] === 'critical', 'title' => 'Show open critical alerts'],
    ['icon' => 'fa-eye', 'label' => 'Acknowledged', 'value' => $tot['acknowledged'], 'sub' => 'Someone is on it', 'tone' => 'warn', 'href' => $url(['status' => 'acknowledged']), 'active' => $f['status'] === 'acknowledged', 'title' => 'Show acknowledged alerts'],
    ['icon' => 'fa-check-circle', 'label' => 'Resolved', 'value' => $tot['resolved'], 'sub' => 'Closed', 'tone' => 'ok', 'href' => $url(['status' => 'resolved']), 'active' => $f['status'] === 'resolved', 'title' => 'Show resolved alerts'],
]) ?>

<form method="get" action="/agent/alerts.php" class="rmmx-toolbar" role="search" aria-label="Filter alerts">
    <div class="rmmx-field rmmx-grow">
        <label for="al-q">Search</label>
        <input type="search" id="al-q" name="q" value="<?= rmmxH($f['q']) ?>" class="form-control" placeholder="Message, device, check or department">
    </div>
    <div class="rmmx-field">
        <label for="al-source">Source</label>
        <select id="al-source" name="source" class="form-select" data-searchable="0">
            <?php foreach ($srcLabels as $k => $l) { ?><option value="<?= $k ?>" <?= $f['source'] === $k ? 'selected' : '' ?>><?= rmmxH($l) ?></option><?php } ?>
        </select>
    </div>
    <div class="rmmx-field">
        <label for="al-status">State</label>
        <select id="al-status" name="status" class="form-select" data-searchable="0">
            <?php foreach ($statusLabels as $k => $l) { ?><option value="<?= $k ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= rmmxH($l) ?></option><?php } ?>
        </select>
    </div>
    <div class="rmmx-field">
        <label for="al-sev">Severity</label>
        <select id="al-sev" name="severity" class="form-select" data-searchable="0">
            <option value="">Any severity</option>
            <?php foreach (ALERTS_CENTRAL_SEVERITIES as $s) { ?><option value="<?= $s ?>" <?= $f['severity'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option><?php } ?>
        </select>
    </div>
    <?php if ($clientRows) { ?>
    <div class="rmmx-field">
        <label for="al-client">Department</label>
        <select id="al-client" name="client_id" class="form-select">
            <option value="">All departments</option>
            <?php foreach ($clientRows as $c) { ?><option value="<?= (int) $c['client_id'] ?>" <?= $f['client'] === (int) $c['client_id'] ? 'selected' : '' ?>><?= rmmxH($c['client_name']) ?></option><?php } ?>
        </select>
    </div>
    <?php } ?>
    <div class="rmmx-field">
        <label for="al-group">View</label>
        <select id="al-group" name="group" class="form-select" data-searchable="0">
            <option value="">List</option>
            <option value="device" <?= $f['group'] === 'device' ? 'selected' : '' ?>>Grouped by device</option>
            <option value="check" <?= $f['group'] === 'check' ? 'selected' : '' ?>>Grouped by check</option>
        </select>
    </div>
    <div class="rmmx-actions">
        <button type="submit" class="btn btn-primary"><i class="fas fa-filter" aria-hidden="true"></i>Apply</button>
        <a href="/agent/alerts.php" class="btn btn-outline-secondary">Reset</a>
    </div>
</form>

<?= rmmxChips($chips, '/agent/alerts.php', $total . ($total === 1 ? ' alert' : ' alerts') . ($f['q'] !== '' || $chips ? ' match' : '')) ?>

<div class="rmmx-card" id="alertsCard" data-csrf="<?= rmmxH($_SESSION['csrf_token']) ?>">
    <?php if ($total === 0) { ?>
        <?= rmmxEmpty('fa-check-circle', $chips ? 'No alerts match these filters' : 'No ' . ($f['status'] === 'all' ? '' : strtolower($statusLabels[$f['status']]) . ' ') . 'alerts',
            $chips ? 'Active filters: ' . implode(', ', array_keys($chips)) . '.' : 'Nothing needs attention right now.', $chips ? '<a class="btn btn-outline-secondary" href="/agent/alerts.php">Clear filters</a>' : '') ?>
    <?php } else { ?>
    <div class="rmmx-scroll">
    <table class="rmmx-table" id="alertsTable">
        <caption class="visually-hidden">Alerts</caption>
        <thead>
            <tr>
                <th style="width:2.5rem" class="checkbox-column"><?php if ($can_ack && $f['status'] !== 'resolved') { ?><input type="checkbox" class="form-check-input js-toggle-select-all" id="selectAll" aria-label="Select all alerts on this page"><?php } ?></th>
                <th scope="col">Severity</th><th scope="col">State</th><th scope="col">Device</th><th scope="col">Department</th><th scope="col">Check</th><th scope="col">Message</th><th scope="col">Opened</th><th scope="col">Source</th><th scope="col"><span class="visually-hidden">Actions</span></th>
            </tr>
        </thead>
        <tbody>
        <?php
        $render = static function (array $a) use ($can_ack, $can_ticket, $endpointFor, $stateTone, $sevRowClass, $rowKey, $shortMsg, $checkOf, $srcLabels): void {
            $rk = $rowKey($a);
            $src = $a['source'] === 'rmm' ? ($a['integration_name'] ?: 'RMM') : $srcLabels[$a['source']];
            $icon = ['agent' => 'fa-microchip', 'rmm' => 'fa-server', 'backup' => 'fa-cloud-upload-alt', 'network' => 'fa-network-wired'][$a['source']] ?? 'fa-bell';
            $msg = $shortMsg($a);
            ?>
            <tr id="alert-row-<?= rmmxH($rk) ?>" class="<?= trim($sevRowClass($a['severity'])) ?>" data-source="<?= rmmxH($a['source']) ?>">
                <td class="checkbox-column"><?php if ($can_ack && $a['status'] !== 'resolved') { ?><input type="checkbox" class="form-check-input alert-chk" data-id="<?= (int) $a['id'] ?>" data-source="<?= rmmxH($a['source']) ?>" data-row="<?= rmmxH($rk) ?>" aria-label="Select this alert"><?php } ?></td>
                <td><?= rmmxSeverity($a['severity']) ?></td>
                <td><?= rmmxState(['new' => 'New', 'acknowledged' => 'Acknowledged', 'resolved' => 'Resolved'][$a['status']] ?? $a['status'], $stateTone[$a['status']] ?? 'neutral') ?><?php if ($a['status'] === 'acknowledged' && $a['ack_by']) { ?><div class="small text-muted">by <?= rmmxH($a['ack_by']) ?></div><?php } ?></td>
                <td style="min-width:9rem"><?php if ($a['subject_url']) { ?><a href="<?= rmmxH($a['subject_url']) ?>" class="rmmx-mono"><?= rmmxH($a['subject']) ?></a><?php } elseif ($a['subject']) { ?><span class="rmmx-mono"><?= rmmxH($a['subject']) ?></span><?php } else { ?><span class="text-muted">-</span><?php } ?></td>
                <td><?php if ($a['client_id']) { ?><a href="/agent/client_overview.php?client_id=<?= (int) $a['client_id'] ?>"><?= rmmxH($a['client_name']) ?></a><?php } else { ?><span class="text-muted">-</span><?php } ?></td>
                <td><?php if ($checkOf($a) !== '') { ?><span class="rmmx-tag rmmx-mono"><?= rmmxH($checkOf($a)) ?></span><?php } else { ?><span class="text-muted">-</span><?php } ?></td>
                <td class="rmmx-msg" title="<?= rmmxH($a['message']) ?>"><?= rmmxH(mb_strimwidth($msg, 0, 160, '…')) ?></td>
                <td><?= rmmxWhen($a['created_at']) ?></td>
                <td><span class="rmmx-tag" title="<?= rmmxH($src) ?>"><i class="fas <?= $icon ?> me-1" aria-hidden="true"></i><?= rmmxH(mb_strimwidth($src, 0, 22, '…')) ?></span></td>
                <td class="text-end text-nowrap">
                    <?php if ($a['ticket_id']) { ?><a href="/agent/ticket.php?ticket_id=<?= (int) $a['ticket_id'] ?>" class="small me-2" title="Linked ticket"><i class="fas fa-ticket-alt me-1" aria-hidden="true"></i><?= rmmxH($a['ticket_label']) ?></a>
                    <?php } elseif ($a['can_create_ticket'] && $can_ticket && $a['status'] !== 'resolved') { ?><button type="button" class="btn btn-sm btn-outline-secondary js-alert-action me-1" data-source="<?= rmmxH($a['source']) ?>" data-alert-id="<?= (int) $a['id'] ?>" data-alert-action="create_ticket" data-row-id="<?= rmmxH($rk) ?>"><i class="fas fa-ticket-alt me-1" aria-hidden="true"></i>Ticket</button><?php } ?>
                    <?php if ($can_ack && $a['status'] === 'new') { ?><button type="button" class="btn btn-sm btn-outline-secondary js-alert-action me-1" data-source="<?= rmmxH($a['source']) ?>" data-alert-id="<?= (int) $a['id'] ?>" data-alert-action="acknowledge" data-row-id="<?= rmmxH($rk) ?>"><i class="fas fa-eye me-1" aria-hidden="true"></i>Acknowledge</button><?php } ?>
                    <?php if ($can_ack && $a['status'] !== 'resolved') { ?><button type="button" class="btn btn-sm btn-success js-alert-action" data-source="<?= rmmxH($a['source']) ?>" data-alert-id="<?= (int) $a['id'] ?>" data-alert-action="resolve" data-row-id="<?= rmmxH($rk) ?>"><i class="fas fa-check me-1" aria-hidden="true"></i>Resolve</button><?php } ?>
                </td>
            </tr>
        <?php };
        if ($groups) {
            foreach ($groups as $g => $rows) { ?>
            <tr class="rmmx-group"><td colspan="10"><?= rmmxH($g) ?> <span class="text-muted fw-normal">&middot; <?= count($rows) ?> alert<?= count($rows) === 1 ? '' : 's' ?></span></td></tr>
            <?php foreach ($rows as $a) { $render($a); }
            }
        } else {
            foreach ($shown as $a) { $render($a); }
        } ?>
        </tbody>
    </table>
    </div>
    <?php if ($pages > 1) { ?>
    <nav class="d-flex align-items-center gap-2 p-2 border-top" aria-label="Alert pages">
        <span class="text-muted small me-auto">Page <?= $page ?> of <?= $pages ?> (<?= $total ?> alerts)</span>
        <a class="btn btn-sm btn-outline-secondary <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= rmmxH($url(['page' => max(1, $page - 1)])) ?>" <?= $page <= 1 ? 'aria-disabled="true" tabindex="-1"' : '' ?>>Previous</a>
        <a class="btn btn-sm btn-outline-secondary <?= $page >= $pages ? 'disabled' : '' ?>" href="<?= rmmxH($url(['page' => min($pages, $page + 1)])) ?>" <?= $page >= $pages ? 'aria-disabled="true" tabindex="-1"' : '' ?>>Next</a>
    </nav>
    <?php } ?>
    <?php } ?>
</div>

<?php if ($can_ack && $total > 0 && $f['status'] !== 'resolved') { ?>
<div class="rmmx-bar" id="alertsBar" hidden>
    <span class="rmmx-bar-count" id="alertsBarCount" aria-live="polite">0 selected</span>
    <button type="button" class="btn btn-outline-secondary js-bulk-alert-action" data-bulk-action="acknowledge"><i class="fas fa-eye me-1" aria-hidden="true"></i>Acknowledge selected</button>
    <button type="button" class="btn btn-success js-bulk-alert-action" data-bulk-action="resolve"><i class="fas fa-check me-1" aria-hidden="true"></i>Resolve selected</button>
    <button type="button" class="btn btn-outline-secondary" id="alertsBarClear">Clear</button>
</div>
<?php } ?>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">window.ALERT_ENDPOINTS = <?= json_encode($endpointFor) ?>;</script>
<script src="/agent/js/alerts_central.js?v=<?= filemtime(__DIR__ . '/js/alerts_central.js') ?>"></script>

<?php require_once "../includes/footer.php"; ?>
