<?php
/* Alert ticket routes (RivetIT 26.10.62): the pure resolver, check key / family detection and the plain-language summary. No database, no server.
 *   php tests/rmm_alert_routes_unit.php */
require dirname(__DIR__) . '/includes/rmm_alert_routes.php';
$fails = 0; $passes = 0;
$ok = function (bool $c, string $l) use (&$fails, &$passes) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; $c ? $passes++ : $fails++; };
$r = fn($t, $v, $c, $p = null, $s = 0, $id = 0) => ['id' => $id, 'match_type' => $t, 'match_value' => $v, 'category_id' => $c, 'priority' => $p, 'sort' => $s];
$all = [1, 2, 3, 4, 5, 6];

// nothing configured: unchanged behaviour
$p = rmmAlertRoutePick([], 'critical', 'tactical_rmm', 'disk', 0, $all);
$ok($p['category_id'] === 0 && $p['priority'] === null && $p['matched'] === 'none', 'nothing configured -> no category, no priority');
// default only
$p = rmmAlertRoutePick([], 'warning', '', '', 3, $all);
$ok($p['category_id'] === 3 && $p['matched'] === 'default', 'default only -> the default category');
// severity beats default; unmatched severity falls to default
$routes = [$r('severity', 'warning', 4)];
$ok(rmmAlertRoutePick($routes, 'warning', '', '', 3, $all)['category_id'] === 4, 'severity route beats the default');
$ok(rmmAlertRoutePick($routes, 'critical', '', '', 3, $all)['category_id'] === 3, 'a different severity uses the default');
$ok(rmmAlertRoutePick($routes, 'WARNING', '', '', 3, $all)['category_id'] === 4, 'severity compares case-insensitively');
// precedence: check family > source > severity > default
$routes = [$r('severity', 'critical', 1), $r('source', 'rivetit_agent', 2), $r('check_prefix', 'disk', 5, 'Low')];
$p = rmmAlertRoutePick($routes, 'critical', 'rivetit_agent', 'disk', 6, $all);
$ok($p['category_id'] === 5 && $p['priority'] === 'Low' && $p['matched'] === 'check_prefix', 'check family beats source and severity (and carries its priority)');
$p = rmmAlertRoutePick($routes, 'critical', 'rivetit_agent', 'service', 6, $all);
$ok($p['category_id'] === 2 && $p['matched'] === 'source' && $p['priority'] === null, 'source beats severity');
$p = rmmAlertRoutePick($routes, 'critical', 'tactical_rmm', '', 6, $all);
$ok($p['category_id'] === 1 && $p['matched'] === 'severity', 'severity beats the default');
$p = rmmAlertRoutePick($routes, 'info', 'tactical_rmm', '', 6, $all);
$ok($p['category_id'] === 6 && $p['matched'] === 'default', 'no route matches -> the default');
// ordering inside a kind: sort then id; duplicates cannot be saved but the resolver is still deterministic
$routes = [$r('severity', 'error', 2, null, 20, 7), $r('severity', 'error', 1, null, 10, 9)];
$ok(rmmAlertRoutePick($routes, 'error', '', '', 0, $all)['category_id'] === 1, 'lowest sort wins inside one kind');
// a category that no longer exists / is not a Ticket category is skipped, falling to the next match
$routes = [$r('check_prefix', 'disk', 99), $r('severity', 'critical', 2)];
$p = rmmAlertRoutePick($routes, 'critical', '', 'disk', 3, [2, 3]);
$ok($p['category_id'] === 2 && $p['matched'] === 'severity', 'a route to a deleted category falls through to the next most specific');
$p = rmmAlertRoutePick([], 'critical', '', '', 99, [2, 3]);
$ok($p['category_id'] === 0, 'a deleted default category gives no category');
// priority is restricted to known values
$p = rmmAlertRoutePick([$r('severity', 'info', 2, "High'; DROP TABLE tickets;--")], 'info', '', '', 0, $all);
$ok($p['category_id'] === 2 && $p['priority'] === null, 'an unknown priority is ignored');
// empty values never match
$ok(rmmAlertRoutePick([$r('source', '', 2)], 'info', '', '', 0, $all)['category_id'] === 0, 'an empty source never matches an empty source route');

// check keys and families
$ok(rmmAlertCheckKey(['raw_data_json' => json_encode(['check' => 'disk:C'])]) === 'disk:C', 'check key from the raw data');
$ok(rmmAlertCheckKey(['tactical_alert_id' => 'agent:12:service:Spooler:3', 'raw_data_json' => '{}']) === 'service:Spooler', 'check key from the agent alert key');
$ok(rmmAlertCheckKey(['tactical_alert_id' => '4711', 'raw_data_json' => null]) === '', 'a Tactical alert has no check key');
$ok(rmmAlertCheckKey(['raw_data_json' => '{not json']) === '', 'bad raw data is no check key');
foreach (['disk_c' => 'disk', 'DISK:C' => 'disk', 'service:Spooler' => 'service', 'svc_1' => 'service', 'patch.compliance' => 'patch', 'patch.install_failed' => 'patch',
          'pending_reboot' => 'reboot', 'agent_update' => 'agent_update', 'log.rule_7' => 'log', 'change.created' => 'change', 'cpu' => '', '' => ''] as $k => $f) {
    $ok(rmmAlertCheckFamily((string) $k) === $f, "check '$k' -> family '" . ($f ?: 'none') . "'");
}
// summary sentence
$ok(rmmAlertRouteSummary(['critical', 'warning'], 'Maintenance', 0) === 'New alerts at severity critical and warning create a ticket in the "Maintenance" category.', 'summary: two severities and a category');
$ok(rmmAlertRouteSummary(['critical'], '', 2) === 'New alerts at severity critical create a ticket with no category, except where one of the routes below applies (2).', 'summary: one severity, no category, routes');
$ok(strpos(rmmAlertRouteSummary([], 'Maintenance', 0), 'off') !== false, 'summary: no severity means automatic creation is off');
$ok(rmmAlertRouteSummary(['critical', 'error', 'warning'], 'X', 0) === 'New alerts at severity critical, error and warning create a ticket in the "X" category.', 'summary: three severities');
echo "\n$passes passed, $fails failed\n";
exit($fails ? 1 : 0);
