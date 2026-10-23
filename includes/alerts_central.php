<?php

/**
 * The one alerts centre (agent/alerts.php): every alert source in one list.
 *
 * Sources
 *   agent   RivetIT endpoint agent. Its alerts live in TWO stores: rmm_alerts (a mirror row under the rivetit_agent integration, which is what
 *           the old central page read) and rmm_alert_meta (RivetCore's own record: state, severity, check, device). The two can disagree
 *           (a mirror write that failed is only logged, an alert adopted after the `alerting` switch came on, a record resolved in the
 *           edition's own screens), so this reads the meta record when there is one and falls back to the mirror row; a meta record with no
 *           mirror row at all is still listed. Nothing is counted twice: a mirror row and its meta record are one alert.
 *   rmm     third-party RMM integrations (Tactical, Level, Action1, Sophos...): rmm_alerts rows of every other integration.
 *   backup  Comet backup alerts.
 *   network rmm_alerts of firewall / switch / access-point assets.
 *
 * Severity is one scale: critical, error, warning, info. RivetCore's meta record says crit / warn; crit -> critical, warn -> warning.
 * State is one scale: new (open), acknowledged, resolved; meta `open` -> new.
 */

const ALERTS_CENTRAL_SOURCES = ['all', 'agent', 'rmm', 'backup', 'network'];
const ALERTS_CENTRAL_SEVERITIES = ['critical', 'error', 'warning', 'info'];
const ALERTS_CENTRAL_PAGE_SIZE = 50;

/** @return array<string,mixed> the filters of this request, whitelisted */
function alertsCentralFilters(array $q): array
{
    $src = (string) ($q['source'] ?? 'all');
    $status = (string) ($q['status'] ?? 'new');
    $sev = (string) ($q['severity'] ?? '');
    $group = (string) ($q['group'] ?? '');

    return [
        'source' => in_array($src, ALERTS_CENTRAL_SOURCES, true) ? $src : 'all',
        'status' => in_array($status, ['new', 'acknowledged', 'resolved', 'active', 'all'], true) ? $status : 'new',   // active = new + acknowledged (the agent-alerts page's default)
        'severity' => in_array($sev, ALERTS_CENTRAL_SEVERITIES, true) ? $sev : '',
        'client' => max(0, (int) ($q['client_id'] ?? 0)),
        'q' => trim((string) ($q['q'] ?? '')),
        'asset' => max(0, (int) ($q['asset_id'] ?? 0)),
        'group' => in_array($group, ['device', 'check'], true) ? $group : '',
        'page' => max(1, (int) ($q['page'] ?? 1)),
    ];
}

/** " AND <expr> = 'x'" (or IN for the combined 'active'); '' for 'all'. */
function alertsCentralStatusClause(string $expr, string $status): string
{
    if ($status === 'all') {
        return '';
    }
    if ($status === 'active') {
        return " AND $expr IN ('new','acknowledged')";
    }

    return " AND $expr = '" . ($status === 'acknowledged' ? 'acknowledged' : ($status === 'resolved' ? 'resolved' : 'new')) . "'";
}

/** SQL for the agent alert's state as one scale: the meta record when there is one, else the mirror row. */
function alertsCentralAgentStatusSql(): string
{
    return "COALESCE(CASE m.state WHEN 'open' THEN 'new' ELSE m.state END, a.status)";
}

function alertsCentralAgentSeveritySql(): string
{
    return "COALESCE(CASE m.severity WHEN 'crit' THEN 'critical' WHEN 'warn' THEN 'warning' ELSE m.severity END, NULLIF(a.severity, ''), 'warning')";
}

/**
 * @param array<string,mixed> $f filters from alertsCentralFilters()
 * @param string $clientScope '' or ' AND <col> IN (...)' is built per column below from $clientIds (null = every department)
 * @param list<int>|null $clientIds departments this user may see, null = all
 * @return array{alerts:list<array<string,mixed>>,counts:array<string,array<string,int>>,total:int}
 */
function alertsCentralFetch(\mysqli $mysqli, array $f, ?array $clientIds): array
{
    $scope = static fn (string $col): string => $clientIds === null ? '' : ($clientIds === [] ? ' AND 1=0' : " AND $col IN (" . implode(',', array_map('intval', $clientIds)) . ')');
    // The search text is only ever BOUND (prepared statement), never concatenated: the SQL carries the marker '__LIKE__' where the pattern goes. The pattern is the raw
    // text with its own \\ % _ made literal first, so a search for "%" or "_" finds those characters and a quote is just a quote.
    $likeRaw = $f['q'] === '' ? null : '%' . addcslashes($f['q'], '\\%_') . '%';
    $like = $likeRaw === null ? null : '__LIKE__';
    $run = static function (string $sql) use ($mysqli, $likeRaw) {
        $n = substr_count($sql, "'__LIKE__'");
        if ($n === 0) {
            return mysqli_query($mysqli, $sql);
        }
        $st = mysqli_prepare($mysqli, str_replace("'__LIKE__'", '?', $sql));
        if (!$st) {
            return false;
        }
        $p = array_fill(0, $n, (string) $likeRaw);
        mysqli_stmt_bind_param($st, str_repeat('s', $n), ...$p);
        mysqli_stmt_execute($st);
        $res = mysqli_stmt_get_result($st);
        mysqli_stmt_close($st);

        return $res;
    };
    $alerts = [];
    $cap = 1000;   // per source; the page paginates what comes back

    // ---- rmm_alerts: third-party RMM, network, and the agent mirror rows
    $rmmSelect = "SELECT a.*, ast.asset_name, c.client_name, u.user_name, t.ticket_prefix, t.ticket_number, i.name AS integration_name, i.type AS integration_type";
    $rmmFrom = " FROM rmm_alerts a LEFT JOIN assets ast ON ast.asset_id = a.asset_id LEFT JOIN clients c ON c.client_id = a.client_id LEFT JOIN users u ON u.user_id = a.acknowledged_by
         LEFT JOIN tickets t ON t.ticket_id = a.ticket_id LEFT JOIN rmm_integrations i ON i.id = a.integration_id";

    $map = static function (array $r, string $source): array {
        return [
            'source' => $source, 'id' => (int) $r['id'], 'severity' => $r['severity'] ?: 'info', 'message' => (string) $r['message'], 'subject' => $r['asset_name'] ?: null,
            'subject_url' => $r['asset_id'] ? '/agent/asset_details.php?asset_id=' . (int) $r['asset_id'] : null, 'client_id' => $r['client_id'] ? (int) $r['client_id'] : null,
            'client_name' => $r['client_name'], 'integration_name' => $r['integration_name'] ?? null, 'integration_type' => $r['integration_type'] ?? null, 'status' => $r['status'] ?: 'new',
            'ack_by' => $r['user_name'], 'created_at' => $r['created_at'], 'ticket_id' => $r['ticket_id'] ? (int) $r['ticket_id'] : null,
            'ticket_label' => $r['ticket_id'] ? ($r['ticket_prefix'] . $r['ticket_number']) : null, 'can_create_ticket' => !$r['ticket_id'], 'check_key' => null,
        ];
    };

    if (in_array($f['source'], ['all', 'rmm'], true)) {
        $w = "COALESCE(i.type, '') <> 'rivetit_agent'" . $scope('a.client_id');
        $w .= alertsCentralStatusClause('a.status', $f['status']);
        if ($f['severity'] !== '') { $w .= " AND a.severity = '" . $f['severity'] . "'"; }
        if ($f['client'] > 0) { $w .= ' AND a.client_id = ' . (int) $f['client']; }
        if ($f['asset'] > 0) { $w .= ' AND a.asset_id = ' . (int) $f['asset']; }
        if ($like !== null) { $w .= " AND (a.message LIKE '$like' OR ast.asset_name LIKE '$like' OR c.client_name LIKE '$like')"; }
        $res = $run("$rmmSelect $rmmFrom WHERE $w ORDER BY FIELD(a.severity,'critical','error','warning','info'), a.created_at DESC LIMIT $cap");
        while ($res && ($r = mysqli_fetch_assoc($res))) { $alerts[] = $map($r, 'rmm'); }
    }

    if ($f['source'] === 'network') {
        $w = "ast.asset_type IN ('Firewall/Router','Switch','Access Point')" . $scope('a.client_id');
        $w .= alertsCentralStatusClause('a.status', $f['status']);
        if ($f['severity'] !== '') { $w .= " AND a.severity = '" . $f['severity'] . "'"; }
        if ($f['client'] > 0) { $w .= ' AND a.client_id = ' . (int) $f['client']; }
        if ($f['asset'] > 0) { $w .= ' AND a.asset_id = ' . (int) $f['asset']; }
        if ($like !== null) { $w .= " AND (a.message LIKE '$like' OR ast.asset_name LIKE '$like' OR c.client_name LIKE '$like')"; }
        $res = $run("$rmmSelect $rmmFrom WHERE $w ORDER BY FIELD(a.severity,'critical','error','warning','info'), a.created_at DESC LIMIT $cap");
        while ($res && ($r = mysqli_fetch_assoc($res))) { $alerts[] = $map($r, 'network'); }
    }

    // ---- endpoint agent: mirror rows joined to their meta record, plus meta records that have no mirror row
    if (in_array($f['source'], ['all', 'agent'], true)) {
        $stSql = alertsCentralAgentStatusSql();
        $svSql = alertsCentralAgentSeveritySql();
        $metaJoin = ' LEFT JOIN rmm_alert_meta m ON m.meta_id = (SELECT MAX(m2.meta_id) FROM rmm_alert_meta m2 WHERE m2.alert_id = a.id) LEFT JOIN endpoint_agent_devices d ON d.device_id = m.device_id';
        $w = "i.type = 'rivetit_agent'" . $scope('a.client_id');
        $w .= alertsCentralStatusClause($stSql, $f['status']);
        if ($f['severity'] !== '') { $w .= " AND $svSql = '" . $f['severity'] . "'"; }
        if ($f['client'] > 0) { $w .= ' AND a.client_id = ' . (int) $f['client']; }
        if ($f['asset'] > 0) { $w .= ' AND a.asset_id = ' . (int) $f['asset']; }
        if ($like !== null) { $w .= " AND (COALESCE(m.message, a.message) LIKE '$like' OR ast.asset_name LIKE '$like' OR d.hostname LIKE '$like' OR c.client_name LIKE '$like' OR m.check_key LIKE '$like')"; }
        $res = $run("$rmmSelect, $stSql AS eff_status, $svSql AS eff_severity, m.check_key AS m_check, m.opened_at AS m_opened, d.hostname AS m_host, d.asset_id AS m_asset $rmmFrom $metaJoin
            WHERE $w ORDER BY FIELD($svSql,'critical','error','warning','info'), COALESCE(m.opened_at, a.created_at) DESC LIMIT $cap");
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $row = $map($r, 'agent');
            $row['status'] = (string) $r['eff_status'];
            $row['severity'] = (string) $r['eff_severity'];
            $row['created_at'] = $r['m_opened'] ?: $r['created_at'];
            $row['check_key'] = $r['m_check'];
            $row['subject'] = $r['asset_name'] ?: ($r['m_host'] ?: null);
            $row['subject_url'] = $r['asset_id'] ? '/agent/asset_details.php?asset_id=' . (int) $r['asset_id'] : ($r['m_asset'] ? '/agent/asset_details.php?asset_id=' . (int) $r['m_asset'] : null);
            $alerts[] = $row;
        }
        // orphans: a meta record whose alert_id has no rmm_alerts row
        $w = 'NOT EXISTS (SELECT 1 FROM rmm_alerts a WHERE a.id = m.alert_id) AND m.meta_id = (SELECT MAX(m2.meta_id) FROM rmm_alert_meta m2 WHERE m2.alert_id = m.alert_id)' . $scope('m.client_id');
        $stM = "CASE m.state WHEN 'open' THEN 'new' ELSE m.state END";
        $svM = "CASE m.severity WHEN 'crit' THEN 'critical' WHEN 'warn' THEN 'warning' ELSE m.severity END";
        $w .= alertsCentralStatusClause($stM, $f['status']);
        if ($f['severity'] !== '') { $w .= " AND $svM = '" . $f['severity'] . "'"; }
        if ($f['client'] > 0) { $w .= ' AND m.client_id = ' . (int) $f['client']; }
        if ($f['asset'] > 0) { $w .= ' AND d.asset_id = ' . (int) $f['asset']; }
        if ($like !== null) { $w .= " AND (m.message LIKE '$like' OR d.hostname LIKE '$like' OR c.client_name LIKE '$like' OR m.check_key LIKE '$like')"; }
        $res = $run("SELECT m.*, $stM AS eff_status, $svM AS eff_severity, d.hostname, d.asset_id, c.client_name FROM rmm_alert_meta m LEFT JOIN endpoint_agent_devices d ON d.device_id = m.device_id
            LEFT JOIN clients c ON c.client_id = m.client_id WHERE $w ORDER BY m.opened_at DESC LIMIT $cap");
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $alerts[] = ['source' => 'agent', 'id' => (int) $r['alert_id'], 'severity' => (string) $r['eff_severity'], 'message' => (string) $r['message'], 'subject' => $r['hostname'] ?: null,
                'subject_url' => $r['asset_id'] ? '/agent/asset_details.php?asset_id=' . (int) $r['asset_id'] : null, 'client_id' => $r['client_id'] ? (int) $r['client_id'] : null,
                'client_name' => $r['client_name'], 'integration_name' => 'RivetIT endpoint agent', 'integration_type' => 'rivetit_agent', 'status' => (string) $r['eff_status'], 'ack_by' => null,
                'created_at' => $r['opened_at'], 'ticket_id' => null, 'ticket_label' => null, 'can_create_ticket' => false, 'check_key' => $r['check_key']];
        }
    }

    // ---- backup (Comet)
    if (in_array($f['source'], ['all', 'backup'], true)) {
        $w = '1=1' . $scope('a.alert_client_id');
        $w .= alertsCentralStatusClause('a.alert_status', $f['status']);
        if ($f['severity'] !== '') { $w .= " AND a.alert_severity = '" . $f['severity'] . "'"; }
        if ($f['client'] > 0) { $w .= ' AND a.alert_client_id = ' . (int) $f['client']; }
        if ($f['asset'] > 0) { $w .= ' AND 1=0'; }   // backup alerts are not tied to an asset
        if ($like !== null) { $w .= " AND (a.alert_message LIKE '$like' OR a.alert_device_name LIKE '$like' OR c.client_name LIKE '$like')"; }
        $res = $run("SELECT a.*, c.client_name, u.user_name, t.ticket_prefix, t.ticket_number FROM comet_backup_alerts a LEFT JOIN clients c ON c.client_id = a.alert_client_id
            LEFT JOIN users u ON u.user_id = a.alert_acknowledged_by LEFT JOIN tickets t ON t.ticket_id = a.alert_ticket_id WHERE $w ORDER BY a.alert_created_at DESC LIMIT $cap");
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $alerts[] = ['source' => 'backup', 'id' => (int) $r['alert_id'], 'severity' => $r['alert_severity'] ?: 'critical',
                'message' => $r['alert_message'] ?: (($r['alert_type'] ?? '') === 'missed' ? 'Backup missed - ' . $r['alert_device_name'] : 'Backup failed - ' . $r['alert_device_name']),
                'subject' => $r['alert_device_name'], 'subject_url' => null, 'client_id' => $r['alert_client_id'] ? (int) $r['alert_client_id'] : null, 'client_name' => $r['client_name'],
                'integration_name' => 'Comet backup', 'integration_type' => 'comet', 'status' => $r['alert_status'] ?: 'new', 'ack_by' => $r['user_name'], 'created_at' => $r['alert_created_at'],
                'ticket_id' => $r['alert_ticket_id'] ? (int) $r['alert_ticket_id'] : null, 'ticket_label' => $r['alert_ticket_id'] ? ($r['ticket_prefix'] . $r['ticket_number']) : null,
                'can_create_ticket' => empty($r['alert_ticket_id']), 'check_key' => null, 'alert_type' => $r['alert_type'] ?? null];
        }
    }

    $rank = ['critical' => 0, 'error' => 1, 'warning' => 2, 'info' => 3];
    usort($alerts, static function (array $a, array $b) use ($rank): int {
        $d = ($rank[$a['severity']] ?? 4) <=> ($rank[$b['severity']] ?? 4);

        return $d !== 0 ? $d : strtotime((string) $b['created_at']) <=> strtotime((string) $a['created_at']);
    });

    return ['alerts' => $alerts, 'counts' => alertsCentralCounts($mysqli, $clientIds), 'total' => count($alerts)];
}

/**
 * State counts per source, ignoring the state/severity/source filters (the cards stay usable). One alert is one count: agent alerts are counted from
 * the meta record where there is one, and meta records without a mirror row are added.
 *
 * @param list<int>|null $clientIds
 * @return array<string,array<string,int>> source => [new, acknowledged, resolved, crit_open]
 */
function alertsCentralCounts(\mysqli $mysqli, ?array $clientIds): array
{
    $scope = static fn (string $col): string => $clientIds === null ? '' : ($clientIds === [] ? ' AND 1=0' : " AND $col IN (" . implode(',', array_map('intval', $clientIds)) . ')');
    $z = ['new' => 0, 'acknowledged' => 0, 'resolved' => 0, 'crit_open' => 0];
    $out = ['agent' => $z, 'rmm' => $z, 'backup' => $z, 'network' => $z];

    $r = mysqli_query($mysqli, "SELECT SUM(a.status='new') n, SUM(a.status='acknowledged') k, SUM(a.status='resolved') s, SUM(a.status='new' AND a.severity IN ('critical','error')) c
        FROM rmm_alerts a LEFT JOIN rmm_integrations i ON i.id = a.integration_id WHERE COALESCE(i.type,'') <> 'rivetit_agent'" . $scope('a.client_id'));
    if ($r && ($x = mysqli_fetch_assoc($r))) { $out['rmm'] = ['new' => (int) $x['n'], 'acknowledged' => (int) $x['k'], 'resolved' => (int) $x['s'], 'crit_open' => (int) $x['c']]; }

    $r = mysqli_query($mysqli, "SELECT SUM(ast.asset_type IN ('Firewall/Router','Switch','Access Point') AND a.status='new') n, SUM(ast.asset_type IN ('Firewall/Router','Switch','Access Point') AND a.status='acknowledged') k,
        SUM(ast.asset_type IN ('Firewall/Router','Switch','Access Point') AND a.status='resolved') s FROM rmm_alerts a LEFT JOIN assets ast ON ast.asset_id = a.asset_id WHERE 1=1" . $scope('a.client_id'));
    if ($r && ($x = mysqli_fetch_assoc($r))) { $out['network'] = ['new' => (int) $x['n'], 'acknowledged' => (int) $x['k'], 'resolved' => (int) $x['s'], 'crit_open' => 0]; }

    $st = alertsCentralAgentStatusSql();
    $sv = alertsCentralAgentSeveritySql();
    $r = mysqli_query($mysqli, "SELECT SUM($st='new') n, SUM($st='acknowledged') k, SUM($st='resolved') s, SUM($st='new' AND $sv IN ('critical','error')) c
        FROM rmm_alerts a JOIN rmm_integrations i ON i.id = a.integration_id AND i.type = 'rivetit_agent'
        LEFT JOIN rmm_alert_meta m ON m.meta_id = (SELECT MAX(m2.meta_id) FROM rmm_alert_meta m2 WHERE m2.alert_id = a.id) WHERE 1=1" . $scope('a.client_id'));
    if ($r && ($x = mysqli_fetch_assoc($r))) { $out['agent'] = ['new' => (int) $x['n'], 'acknowledged' => (int) $x['k'], 'resolved' => (int) $x['s'], 'crit_open' => (int) $x['c']]; }
    $r = mysqli_query($mysqli, "SELECT SUM(m.state='open') n, SUM(m.state='acknowledged') k, SUM(m.state='resolved') s, SUM(m.state='open' AND m.severity='crit') c FROM rmm_alert_meta m
        WHERE NOT EXISTS (SELECT 1 FROM rmm_alerts a WHERE a.id = m.alert_id) AND m.meta_id = (SELECT MAX(m2.meta_id) FROM rmm_alert_meta m2 WHERE m2.alert_id = m.alert_id)" . $scope('m.client_id'));
    if ($r && ($x = mysqli_fetch_assoc($r))) {
        $out['agent']['new'] += (int) $x['n']; $out['agent']['acknowledged'] += (int) $x['k']; $out['agent']['resolved'] += (int) $x['s']; $out['agent']['crit_open'] += (int) $x['c'];
    }

    $r = mysqli_query($mysqli, "SELECT SUM(alert_status='new') n, SUM(alert_status='acknowledged') k, SUM(alert_status='resolved') s, SUM(alert_status='new' AND alert_severity IN ('critical','error')) c
        FROM comet_backup_alerts WHERE 1=1" . $scope('alert_client_id'));
    if ($r && ($x = mysqli_fetch_assoc($r))) { $out['backup'] = ['new' => (int) $x['n'], 'acknowledged' => (int) $x['k'], 'resolved' => (int) $x['s'], 'crit_open' => (int) $x['c']]; }

    return $out;
}

/** The sidebar badge: open critical + error alerts across every source (network alerts are rmm_alerts, so already inside rmm/agent). */
function alertsCentralOpenCriticalCount(\mysqli $mysqli, ?array $clientIds = null): int
{
    $c = alertsCentralCounts($mysqli, $clientIds);

    return $c['agent']['crit_open'] + $c['rmm']['crit_open'] + $c['backup']['crit_open'];
}

/**
 * Heal the mirror: an endpoint-agent alert whose RivetCore record says resolved / acknowledged while its rmm_alerts mirror row still says new keeps
 * showing as open in every place that only reads rmm_alerts (dashboard cards, the sidebar, asset panels). Idempotent; touches only rows under the
 * rivetit_agent integration and only moves a row forward (new -> acknowledged -> resolved), never back.
 *
 * @return int rows corrected
 */
function alertsCentralReconcileMirror(\mysqli $mysqli): int
{
    $n = 0;
    $join = "JOIN rmm_integrations i ON i.id = a.integration_id AND i.type = 'rivetit_agent' JOIN rmm_alert_meta m ON m.meta_id = (SELECT MAX(m2.meta_id) FROM rmm_alert_meta m2 WHERE m2.alert_id = a.id)";
    mysqli_query($mysqli, "UPDATE rmm_alerts a $join SET a.status = 'resolved', a.resolved_at = COALESCE(a.resolved_at, m.resolved_at, NOW()) WHERE m.state = 'resolved' AND a.status <> 'resolved'");
    $n += max(0, mysqli_affected_rows($mysqli));
    mysqli_query($mysqli, "UPDATE rmm_alerts a $join SET a.status = 'acknowledged', a.acknowledged_by = COALESCE(a.acknowledged_by, m.acked_by), a.acknowledged_at = COALESCE(a.acknowledged_at, m.acked_at, NOW()) WHERE m.state = 'acknowledged' AND a.status = 'new'");
    $n += max(0, mysqli_affected_rows($mysqli));

    return $n;
}
