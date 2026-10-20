<?php
/*
 * Which ticket category (and optionally priority) a ticket made from an RMM alert gets.
 *
 * Settings > Integrations > RMM > "Alert tickets": one default category (settings.config_rmm_alert_ticket_category_id) plus optional routes
 * (rmm_alert_ticket_routes): a check family, a source (the RMM integration type) or a severity -> a category and an optional priority.
 * Most specific wins: check family, then source, then severity, then the default. Within one kind the lowest sort (then id) wins.
 * With nothing configured every function returns "no category" and the ticket is created exactly as before.
 *
 * rmmAlertRoutePick() is pure (no database) so it can be unit tested; rmmAlertTicketRoute() loads the rows, checks the category still
 * exists, is a Ticket category and is not archived, and falls through to the next match when it is not.
 */

const RMM_ALERT_ROUTE_SEVERITIES = ['critical' => 'Critical', 'error' => 'Error', 'warning' => 'Warning', 'info' => 'Info'];
const RMM_ALERT_ROUTE_PRIORITIES = ['High', 'Medium', 'Low'];   // the ticket priorities this install uses
const RMM_ALERT_ROUTE_SOURCES = [
    'rivetit_agent' => 'RivetIT endpoint agent', 'tactical_rmm' => 'Tactical RMM', 'level' => 'Level', 'action1' => 'Action1', 'sophos_central' => 'Sophos Central',
];
/** family slug => [label, check-key prefixes]. The slug is what is stored; the prefixes are matched case-insensitively against the start of the check key. */
const RMM_ALERT_ROUTE_FAMILIES = [
    'disk'         => ['Disk space', ['disk']],
    'service'      => ['Service', ['service', 'svc']],
    'patch'        => ['Patch', ['patch.', 'patch_', 'patch:']],
    'reboot'       => ['Pending reboot', ['pending_reboot', 'reboot']],
    'agent_update' => ['Agent update', ['agent_update', 'agent.update', 'update']],
    'log'          => ['Log rule', ['log.rule_', 'log.', 'log:']],
    'change'       => ['Change monitor', ['change.', 'change_', 'change:', 'chg']],
];

/** The check key of an alert ("disk:C", "patch.compliance"), or ''. Endpoint agent alert keys are agent:<device>:<check>:<episode>. */
function rmmAlertCheckKey(array $alert): string {
    $raw = json_decode((string) ($alert['raw_data_json'] ?? ''), true);
    if (is_array($raw) && isset($raw['check']) && is_string($raw['check'])) {
        return $raw['check'];
    }
    $k = (string) ($alert['tactical_alert_id'] ?? '');
    if (preg_match('/^agent:\d+:(.+):\d+$/', $k, $m)) {
        return $m[1];
    }
    return '';
}

/** The check family slug for a check key, or '' (matches nothing). */
function rmmAlertCheckFamily(string $check_key): string {
    $k = strtolower($check_key);
    if ($k === '') {
        return '';
    }
    foreach (RMM_ALERT_ROUTE_FAMILIES as $slug => $def) {
        foreach ($def[1] as $prefix) {
            if (strncmp($k, $prefix, strlen($prefix)) === 0) {
                return $slug;
            }
        }
    }
    return '';
}

/**
 * Pure resolver. $routes: rows with match_type, match_value, category_id, priority, sort, id. $valid_categories: category ids that may be used
 * (null = trust the rows). Returns ['category_id' => int (0 = none), 'priority' => string|null, 'matched' => 'check_prefix'|'source'|'severity'|'default'|'none'].
 */
function rmmAlertRoutePick(array $routes, string $severity, string $source, string $family, int $default_category, ?array $valid_categories = null): array {
    usort($routes, function ($a, $b) {
        return [intval($a['sort'] ?? 0), intval($a['id'] ?? 0)] <=> [intval($b['sort'] ?? 0), intval($b['id'] ?? 0)];
    });
    $wanted = ['check_prefix' => $family, 'source' => $source, 'severity' => strtolower($severity)];
    foreach ($wanted as $type => $value) {
        if ($value === '') {
            continue;
        }
        foreach ($routes as $r) {
            if (($r['match_type'] ?? '') !== $type || strtolower((string) $r['match_value']) !== $value) {
                continue;
            }
            $cid = intval($r['category_id']);
            if ($cid > 0 && ($valid_categories === null || in_array($cid, $valid_categories, true))) {
                $p = $r['priority'] ?? null;
                return ['category_id' => $cid, 'priority' => in_array($p, RMM_ALERT_ROUTE_PRIORITIES, true) ? $p : null, 'matched' => $type];
            }
        }
    }
    if ($default_category > 0 && ($valid_categories === null || in_array($default_category, $valid_categories, true))) {
        return ['category_id' => $default_category, 'priority' => null, 'matched' => 'default'];
    }
    return ['category_id' => 0, 'priority' => null, 'matched' => 'none'];
}

/** Category ids that are live Ticket categories. */
function rmmAlertTicketCategoryIds($mysqli): array {
    $ids = [];
    $q = mysqli_query($mysqli, "SELECT category_id FROM categories WHERE category_type = 'Ticket' AND category_archived_at IS NULL");
    while ($q && ($r = mysqli_fetch_assoc($q))) {
        $ids[] = intval($r['category_id']);
    }
    return $ids;
}

/** Route for one rmm_alerts row. Never throws: before the 2.6.179 update (no column / table) it answers "none". */
function rmmAlertTicketRoute($mysqli, array $alert): array {
    $none = ['category_id' => 0, 'priority' => null, 'matched' => 'none'];
    $s = @mysqli_query($mysqli, "SELECT config_rmm_alert_ticket_category_id AS c FROM settings WHERE company_id = 1");
    if (!$s) {
        return $none;
    }
    $default = intval((mysqli_fetch_assoc($s) ?: [])['c'] ?? 0);
    $routes = [];
    $q = @mysqli_query($mysqli, "SELECT id, match_type, match_value, category_id, priority, sort FROM rmm_alert_ticket_routes ORDER BY sort, id");
    while ($q && ($r = mysqli_fetch_assoc($q))) {
        $routes[] = $r;
    }
    if ($default === 0 && !$routes) {
        return $none;
    }
    $source = '';
    $iid = intval($alert['integration_id'] ?? 0);
    if ($iid > 0) {
        $i = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT type FROM rmm_integrations WHERE id = $iid"));
        $source = (string) ($i['type'] ?? '');
    }
    return rmmAlertRoutePick($routes, (string) ($alert['severity'] ?? ''), $source, rmmAlertCheckFamily(rmmAlertCheckKey($alert)), $default, rmmAlertTicketCategoryIds($mysqli));
}

/** Plain-language sentence for the settings page: what happens to a new alert. */
function rmmAlertRouteSummary(array $severities, string $default_name, int $route_count): string {
    $names = [];
    foreach ($severities as $s) {
        if (isset(RMM_ALERT_ROUTE_SEVERITIES[$s])) {
            $names[] = strtolower(RMM_ALERT_ROUTE_SEVERITIES[$s]);
        }
    }
    $sev = count($names) > 1 ? implode(', ', array_slice($names, 0, -1)) . ' and ' . end($names) : ($names[0] ?? '');
    if ($sev === '') {
        return 'Automatic ticket creation is off (no severity is selected above). Tickets made by hand from an alert still use these categories.';
    }
    $where = $default_name !== '' ? "in the \"$default_name\" category" : 'with no category';
    $s = "New alerts at severity $sev create a ticket $where";
    return $s . ($route_count > 0 ? ", except where one of the routes below applies ($route_count)." : '.');
}
