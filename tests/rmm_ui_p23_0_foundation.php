<?php
/*
 * RMM Phase 2 and 3 foundation (RivetCore 1.0.0-rc.10): the three sub-switches and their settings in Administration, the menu entries answered from the state
 * file, and the two new abilities in the role matrix. Included by tests/rmm_ui.php (shares its variables).
 */
use RivetCore\Rmm\Authz\RmmAbility;

$setAuto = fn(array $f, string $who = 'admin') => $adm1($f + ['save_automation_settings' => 1], $who);
$features = fn() => json_decode((string) $db->query('SELECT features_json FROM endpoint_agent_settings WHERE id=1')->fetch_row()[0], true) ?: [];
$ok($rmm->featureOn('policies') && $rmm->featureOn('scripts') && $rmm->featureOn('alerting'), 'the seed starts with policies, scripts and alerting on');
$ok(count(rivetRmmAutoNav()) === 9, 'menu: nine entries while the three sub-switches are on (incl. the starter pack)');

// a technician cannot save these settings
[$c] = $setAuto(['feature_policies' => '1'], 'tech');
$ok(($features()['alerting'] ?? false) === true && $rmm->settings()->limits()['approval_expiry_h'] === 48, 'a technician cannot save the policies, scripts and alerting settings (' . $c . ')');

// switch everything off: the menu loses the entries (state file, no query), the settings are kept
[$c] = $setAuto([]);
$f = $features();
$ok(in_array($c, [200, 302], true) && ($f['policies'] ?? true) === false && ($f['scripts'] ?? true) === false && ($f['alerting'] ?? true) === false, 'administrator: the three switches turn off (' . json_encode($f) . ')');
$a = $questions(); $nav = rivetRmmAutoNav(); $b = $questions();
$ok($nav === [] && $b - $a === $overhead, 'menu: no entries with the switches off, and the check costs no statement');
$ok(!rivetRmmFeatureOn('policies') && !$rmm->featureOn('scripts'), 'feature checks agree with the state file');
foreach ([['policies', 'feature_policies', ['/agent/rmm_policies.php']], ['scripts', 'feature_scripts', ['/agent/rmm_script_library.php', '/agent/rmm_starter_pack.php', '/agent/rmm_schedules.php', '/agent/rmm_approvals.php', '/agent/rmm_fields.php']],
    ['alerting', 'feature_alerting', ['/agent/rmm_agent_alerts.php', '/agent/rmm_maintenance.php', '/agent/rmm_escalations.php']]] as [$feat, $field, $hrefs]) {
    $setAuto([$field => '1']);
    $got = array_column(rivetRmmAutoNav(), 'href');
    $ok($got === $hrefs, "switching only $feat on shows exactly its entries: " . implode(', ', $got));
    $setAuto([]);
}
// every page answers "switched off" without a query while its switch is off
foreach (['/agent/rmm_policies.php', '/agent/rmm_script_library.php', '/agent/rmm_starter_pack.php', '/agent/rmm_schedules.php', '/agent/rmm_approvals.php', '/agent/rmm_fields.php', '/agent/rmm_agent_alerts.php', '/agent/rmm_maintenance.php', '/agent/rmm_escalations.php'] as $path) {
    if (!is_file($root . $path)) { continue; }
    [$c, $body] = web($wb, 'GET', $path, $SS['admin']);
    $ok($c === 403 && str_contains($body, 'switched off'), "$path: 403 and the switched-off notice while the sub-switch is off ($c)");
}

// switches, limits, approval setting, contact and storm control in one save
[$c, , $hd] = $setAuto(['feature_policies' => '1', 'feature_scripts' => '1', 'feature_alerting' => '1', 'approve_scripts_lvl3' => '1', 'escalation_contact' => 'noc@example.test, 12', 'approval_bulk_threshold' => 25,
    'approval_expiry_h' => 24, 'schedule_batch' => 150, 'bulk_run_max' => 300, 'storm_client_max' => 40, 'storm_global_max' => 200, 'storm_global_window_s' => 600, 'storm_client_window_s' => 600]);
$lim = $rmm->settings()->limits();
$ok(in_array($c, [200, 302], true) && $lim['approval_bulk_threshold'] === 25 && $lim['approval_expiry_h'] === 24 && $lim['schedule_batch'] === 150 && $lim['bulk_run_max'] === 300, 'administrator: the script and approval limits are saved');
$ok($rmm->featureOn('policies') && $rmm->featureOn('scripts') && $rmm->featureOn('alerting') && count(rivetRmmAutoNav()) === 9, 'administrator: the three switches turn on again and the menu is back');
$row = $db->query('SELECT config_rmm_approve_scripts_lvl3 AS a, config_rmm_escalation_contact AS c FROM settings WHERE company_id=1')->fetch_assoc();
$ok($row['a'] === '1' && $row['c'] === 'noc@example.test, 12', 'administrator: the approval setting and the escalation contact are saved (' . json_encode($row) . ')');
$storm = $rmm->alertingActions()->settings($admin)->data['storm'];
$ok($storm['storm_client_max'] === 40 && $storm['storm_global_max'] === 200, 'administrator: storm control limits are saved ' . json_encode($storm));
$ok($db->query("SELECT COUNT(*) FROM logs WHERE log_action = 'Settings Changed' AND log_description LIKE '%approval and escalation%'")->fetch_row()[0] >= 1, 'the approval and escalation change is in the audit log');

// the role matrix: with the setting on a level 3 script technician approves, a level 2 one does not, a module-only login never; alert.manage = RMM write
rivetRmmForgetAccess();
$auth = $rmm->authorizer();
$ok($auth->allowed($USER['admin'], RmmAbility::JOB_APPROVE, 0) && $auth->allowed($USER['tech'], RmmAbility::JOB_APPROVE, 0) && !$auth->allowed($USER['rebootonly'], RmmAbility::JOB_APPROVE, 0)
    && !$auth->allowed($USER['viewer'], RmmAbility::JOB_APPROVE, 0) && !$auth->allowed($USER['moduleonly'], RmmAbility::JOB_APPROVE, 0), 'approve: admin and (setting on) level 3 script roles only');
$ok($auth->allowed($USER['admin'], RmmAbility::ALERT_MANAGE, 0) && $auth->allowed($USER['tech'], RmmAbility::ALERT_MANAGE, 0) && !$auth->allowed($USER['viewer'], RmmAbility::ALERT_MANAGE, 0)
    && !$auth->allowed($USER['rebootonly'], RmmAbility::ALERT_MANAGE, 0) && !$auth->allowed($USER['moduleonly'], RmmAbility::ALERT_MANAGE, 0), 'alert.manage: admin and RMM-write roles; viewers and module-only logins no');
// ... and off again by default
[$c] = $setAuto(['feature_policies' => '1', 'feature_scripts' => '1', 'feature_alerting' => '1', 'escalation_contact' => '']);
rivetRmmForgetAccess();
$ok(!$rmm->authorizer()->allowed($USER['tech'], RmmAbility::JOB_APPROVE, 0) && $rmm->authorizer()->allowed($USER['admin'], RmmAbility::JOB_APPROVE, 0), 'approve: the technician loses it when the setting is switched off; the administrator keeps it');

// validation: a bad escalation contact is refused and nothing else changes
[$c] = $setAuto(['feature_policies' => '1', 'feature_scripts' => '1', 'feature_alerting' => '1', 'escalation_contact' => 'not an address <script>', 'approve_scripts_lvl3' => '1']);
$ok($db->query('SELECT config_rmm_approve_scripts_lvl3 FROM settings WHERE company_id=1')->fetch_row()[0] === '0', 'a bad escalation contact is refused and the approval setting is not changed');
[$c, $body] = web($wb, 'GET', '/admin/settings_endpoint_agent.php', $SS['admin']);
$ok($c === 200 && str_contains($body, 'id="automation"') && str_contains($body, 'name="feature_alerting"') && str_contains($body, 'Cost:') && str_contains($body, 'name="escalation_contact"'), 'Administration shows the card with cost notes');
$ok(!str_contains($body, '<script>alert') , 'Administration card escapes values');
