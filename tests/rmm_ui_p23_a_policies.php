<?php
/*
 * Fragment of tests/rmm_ui.php (Phase 2 and 3, group A): policies, custom fields and the "Policy and fields" tab of the asset page.
 * Shares the parent's variables ($ok $q $one $db $root $S $D $A $USER $SS $wb $rmm $admin $questions $overhead $fresh) and its web server.
 * Real handler, real forged sessions, real check-ins; what is written is read back from the Core tables.
 */
use RivetCore\Rmm\Authz\RmmPrincipal as PaPrincipal;

$paPost = fn(string $who, array $f, bool $csrf = true) => web($wb, 'POST', '/agent/post/rmm_automation_pol.php', $SS[$who], $f + ($csrf ? ['csrf_token' => 'csrftok1'] : ['csrf_token' => 'wrong']));
$paGet = fn(string $who, string $path) => web($wb, 'GET', $path, $SS[$who]);
$paFeatures = function (array $on) use ($rmm, $admin, $fresh): void {
    $f = $rmm->settings()->features();
    foreach (['policies', 'scripts', 'alerting'] as $k) { $f[$k] = in_array($k, $on, true); }
    $rmm->admin()->saveSettings($admin, ['features_json' => $f]);
    $fresh();
    rivetRmmForgetAccess();
};
$paPolicies = fn() => (int) $one('SELECT COUNT(*) FROM rmm_policies');
$paBody = fn(string $name) => json_decode((string) $one("SELECT body_json FROM rmm_policies WHERE name='" . $db->real_escape_string($name) . "'"), true);
$paId = fn(string $name) => (int) $one("SELECT policy_id FROM rmm_policies WHERE name='" . $db->real_escape_string($name) . "'");
$paFlash = fn(string $html) => html_entity_decode(strip_tags($html));

// ============================================================ pages render, controls per role
$q('DELETE FROM rmm_policy_assignments'); $q('DELETE FROM rmm_policy_versions'); $q('DELETE FROM rmm_policies');
$q('DELETE FROM rmm_custom_field_values'); $q('DELETE FROM rmm_custom_fields');
[$c, $b] = $paGet('admin', '/agent/rmm_policies.php');
$ok($c === 200 && str_contains($b, 'No policies yet') && str_contains($b, 'New policy'), 'policies page (admin, none yet): 200, empty state and the New policy button');
[$c, $b] = $paGet('admin', '/agent/rmm_policies.php?new=1');
$ok($c === 200 && str_contains($b, 'id="pol-form"') && str_contains($b, 'name="ci_mode"') && str_contains($b, 'name="ft_software_inventory"') && str_contains($b, 'name="ring"') && str_contains($b, 'data-rmm-add="#pol-checks"'), 'new policy form: intervals, features, ring and the repeating check list');
$ok(str_contains($b, '<option value="cpu">cpu (Windows and Linux)</option>') && str_contains($b, 'value="service"') && str_contains($b, 'name="chk[__i__][p][cpu][window_s]"') && str_contains($b, 'name="chk[__i__][th][warn_val]"') && str_contains($b, 'name="chk[__i__][params_json]"'), 'check types come from the catalog: platforms in the label, param inputs from the schema, thresholds, JSON box for legacy types');
foreach (['tech', 'viewer', 'rebootonly'] as $w) {
    [$c, $b] = $paGet($w, '/agent/rmm_policies.php');
    $ok($c === 200 && !str_contains($b, 'New policy') && !str_contains($b, 'name="action" value="delete_policy"'), "policies page ($w): readable, no write controls");
}
[$c, $b] = $paGet('tech', '/agent/rmm_policies.php?new=1');
$ok($c === 200 && !str_contains($b, 'id="pol-form"') && str_contains($b, 'Only administrators'), 'new policy form (technician): not offered');
[$c, $b] = $paGet('normo', '/agent/rmm_policies.php');
$ok($c === 403, 'policies page (no RMM role): denied');
[$c, $b] = $paGet('moduleonly', '/agent/rmm_policies.php');
$ok(in_array($c, [200, 403], true) && !str_contains($b, 'New policy'), 'policies page (module-only login): no write controls (' . $c . ')');
[$c, $b] = $paGet('admin', '/agent/rmm_policies.php?policy_id=99999');
$ok($c === 200 && str_contains($b, 'That policy does not exist'), 'unknown policy id: a plain notice');

// ============================================================ create through the real handler
$n0 = $paPolicies();
$new = ['action' => 'save_policy', 'policy_id' => 0, 'name' => 'P23 Alpha', 'description' => 'Alpha policy', 'enabled' => '1',
    'ci_mode' => 'override', 'ci_value' => '120', 'co_mode' => '', 'ft_software_inventory' => 'off', 'ft_checks' => 'on', 'ring' => 'pilot',
    'chk' => [0 => ['key' => 'cpu_load', 'mode' => 'override', 'type' => 'cpu', 'interval_s' => '60', 'p' => ['cpu' => ['window_s' => '5', 'warn_pct' => '85']],
        'th' => ['warn_op' => 'gt', 'warn_val' => '80', 'crit_op' => 'gt', 'crit_val' => '95', 'hysteresis' => '5', 'for_samples' => '2', 'for_minutes' => '', 'ignore' => ''], 'flap_on' => '1', 'flap' => ['window' => '10', 'high_pct' => '60', 'low_pct' => '20'], 'ignore_parent' => '1'],
        1 => ['key' => 'spooler', 'mode' => 'override', 'type' => 'service', 'interval_s' => '300', 'params_json' => '{"name": "Spooler"}'],
        2 => ['key' => 'old_check', 'mode' => 'disable', 'type' => '', 'interval_s' => '']]];
[$c, , $h] = $paPost('admin', $new, false);
$ok($paPolicies() === $n0, 'a wrong CSRF token creates nothing');
[$c, , $h] = $paPost('tech', $new);
$ok($paPolicies() === $n0, 'a technician (not an administrator) cannot create a policy through the handler');
[$c, , $h] = $paPost('admin', $new);
$pidA = $paId('P23 Alpha');
$ok($c === 302 && $pidA > 0 && str_contains($h, 'policy_id=' . $pidA), 'create: redirected to the new policy page');
$body = $paBody('P23 Alpha');
$s = $body['settings'] ?? [];
$ok(($s['interval.check_in_s'] ?? null) === ['mode' => 'override', 'value' => 120] && !isset($s['interval.collect_s']), 'stored: check-in interval 120 as a number; collection interval not set');
$ok(($s['feature.software_inventory']['mode'] ?? '') === 'disable' && ($s['feature.checks'] ?? null) === ['mode' => 'override', 'value' => true] && ($s['agent.ring']['value'] ?? '') === 'pilot', 'stored: feature off = disable, feature on = override true, ring pilot');
$cpu = $s['check.cpu_load']['value'] ?? [];
$ok(($cpu['type'] ?? '') === 'cpu' && ($cpu['interval_s'] ?? 0) === 60 && ($cpu['params']['window_s'] ?? 0) === 5 && ($cpu['params']['warn_pct'] ?? 0) === 85 && ($cpu['params']['thresholds'] ?? []) === ['warn' => ['op' => 'gt', 'value' => 80], 'crit' => ['op' => 'gt', 'value' => 95], 'hysteresis' => 5, 'for_samples' => 2, 'for_minutes' => 0]
    && ($cpu['params']['flap'] ?? []) === ['window' => 10, 'high_pct' => 60, 'low_pct' => 20] && ($cpu['params']['ignore_parent'] ?? null) === true, 'stored: CPU check with typed params, thresholds, flap and "ignore parent"');
$ok(($s['check.spooler']['value']['params'] ?? null) === ['name' => 'Spooler'] && ($s['check.old_check']['mode'] ?? '') === 'disable' && $one("SELECT version FROM rmm_policies WHERE policy_id=$pidA") === '1', 'stored: legacy check with free-form params, a removed check, version 1');
$ok((int) $one("SELECT COUNT(*) FROM audit_events WHERE 1=0") === 0, 'audit table reachable');

// ============================================================ validation shown to the user (nothing written, form kept)
$bad = $new; $bad['name'] = 'P23 Bad'; $bad['ci_value'] = '5000';
[$c, , $h] = $paPost('admin', $bad);
[$c2, $b] = $paGet('admin', '/agent/rmm_policies.php?new=1');
$ok($paId('P23 Bad') === 0 && str_contains($paFlash($b), 'Check-in interval must be from 60 to 3600 seconds') && str_contains($b, 'value="P23 Bad"') && str_contains($b, 'value="5000"') && str_contains($b, 'value="cpu_load"'), 'out-of-range interval: refused with the clamp in words, nothing stored, the form comes back filled in');
$bad = $new; $bad['name'] = 'P23 Bad'; $bad['chk'][0]['p']['cpu']['window_s'] = '99';
[$c, , $h] = $paPost('admin', $bad);
[$c2, $b] = $paGet('admin', '/agent/rmm_policies.php?new=1');
$ok($paId('P23 Bad') === 0 && str_contains($paFlash($b), 'window_s must be a whole number from 1 to 30'), 'RivetCore\'s own validation message is shown (cpu window)');
$bad = $new; $bad['name'] = 'P23 Bad'; $bad['chk'][0]['th']['crit_val'] = '70';
[$c, , $h] = $paPost('admin', $bad);
[$c2, $b] = $paGet('admin', '/agent/rmm_policies.php?new=1');
$ok($paId('P23 Bad') === 0 && str_contains($paFlash($b), 'crit must be beyond thresholds.warn'), 'inverted thresholds: Core\'s message is shown');
$bad = $new; $bad['name'] = 'P23 Bad'; $bad['chk'][1]['params_json'] = '[1,2]';
[$c, , $h] = $paPost('admin', $bad); [$c2, $b] = $paGet('admin', '/agent/rmm_policies.php?new=1');
$ok($paId('P23 Bad') === 0 && str_contains($paFlash($b), 'must be a JSON object'), 'legacy check JSON that is not an object: refused with a hint');
$bad = $new; $bad['name'] = 'P23 Bad'; $bad['chk'][0]['key'] = 'bad key!';
[$c, , $h] = $paPost('admin', $bad); [$c2, $b] = $paGet('admin', '/agent/rmm_policies.php?new=1');
$ok($paId('P23 Bad') === 0 && str_contains($paFlash($b), 'A check key is 1 to 100 letters'), 'check key rule is enforced');
$bad = $new; $bad['name'] = 'P23 Alpha';
[$c, , $h] = $paPost('admin', $bad); [$c2, $b] = $paGet('admin', '/agent/rmm_policies.php?new=1');
$ok($paPolicies() === $n0 + 1 && str_contains($paFlash($b), 'A policy with that name exists'), 'duplicate name: Core\'s message');

// ============================================================ assign to Dept A, then a real check-in carries the policy
[$c, $b] = $paGet('admin', '/agent/rmm_policies.php?policy_id=' . $pidA);
$ok($c === 200 && str_contains($b, 'name="as_type"') && str_contains($b, 'name="priority"') && str_contains($b, 'name="enforce"') && str_contains($b, 'not assigned to anything'), 'policy page (admin): settings editor filled in, "Assign to" form, empty assignments');
$ok(str_contains($b, 'value="cpu_load"') && str_contains($b, 'value="120"') && str_contains($b, 'value="Spooler"') === false && str_contains($b, '&quot;name&quot;: &quot;Spooler&quot;') || str_contains($b, '{&quot;name&quot;:&quot;Spooler&quot;}'), 'policy page: the stored settings are shown in the editor');
$ok(str_contains($b, 'The more specific scope wins') && str_contains($b, 'enforce'), 'policy page: the precedence is explained');
[$c, , $h] = $paPost('tech', ['action' => 'assign_policy', 'policy_id' => $pidA, 'as_type' => 'client', 'as_id_client' => 1, 'priority' => 100]);
$ok((int) $one("SELECT COUNT(*) FROM rmm_policy_assignments WHERE policy_id=$pidA") === 0, 'a technician cannot assign a policy');
[$c, , $h] = $paPost('admin', ['action' => 'assign_policy', 'policy_id' => $pidA, 'as_type' => 'client', 'as_id_client' => 1, 'priority' => '100']);
$ok((int) $one("SELECT COUNT(*) FROM rmm_policy_assignments WHERE policy_id=$pidA AND scope_type='client' AND scope_id=1 AND priority=100 AND enforce=0") === 1, 'assign: stored as client 1, priority 100, not enforced');
[$c, , $h] = $paPost('admin', ['action' => 'assign_policy', 'policy_id' => $pidA, 'as_type' => 'client', 'as_id_client' => 1, 'priority' => '9999999']);
[$c2, $b] = $paGet('admin', '/agent/rmm_policies.php?policy_id=' . $pidA);
$ok(str_contains($paFlash($b), 'Priority must be a whole number from 0 to 1000000') && $one("SELECT priority FROM rmm_policy_assignments WHERE policy_id=$pidA") === '100', 'assign: a priority out of range is refused');
$ok(str_contains($b, 'Department Dept A') && str_contains($b, 'name="action" value="unassign_policy"'), 'policy page: the assignment is listed with its scope label and a remove button');
$ci = ea_checkin($S['token']['WIN1'], ['capabilities' => ['check:cpu', 'check:service', 'policies']]);
if (getenv('P23_DBG')) { echo substr($ci[3], 0, 1500), "\n"; }
$cfg = $ci[2]['config'] ?? [];
$keys = array_column($cfg['checks'] ?? [], 'key');
$ok($ci[0] === 200 && ($ci[2]['next_check_in_s'] ?? 0) === 120 && in_array('cpu_load', $keys, true) && in_array('spooler', $keys, true), 'check-in response carries the policy: next_check_in_s 120 and its check templates (status ' . $ci[0] . ')');
$cl = null; foreach ($cfg['checks'] ?? [] as $x) { if (($x['key'] ?? '') === 'cpu_load') { $cl = $x; } }
$ok($cl !== null && $cl['type'] === 'cpu' && $cl['interval_s'] === 60 && ($cl['params']['thresholds']['warn']['value'] ?? 0) === 80, 'check-in response: the CPU check keeps its interval and thresholds');
$ok(($cfg['policy']['applied'] ?? false) === true && ($cfg['policy']['features']['software_inventory'] ?? true) === false, 'check-in response: policy block says applied and software inventory off');
$ci2 = ea_checkin($S['token']['LNX1'], ['capabilities' => ['check:cpu', 'check:service', 'policies']]);
$ok(!in_array('cpu_load', array_column($ci2[2]['config']['checks'] ?? [], 'key'), true) && ($ci2[2]['next_check_in_s'] ?? 0) !== 120, 'a Dept B device is not reached by the Dept A assignment');

// ============================================================ the asset page tab
[$c, $b] = $paGet('admin', '/agent/asset_details.php?asset_id=' . $A['WIN1']);
$ok($c === 200 && str_contains($b, 'id="rmm-tab-policy"') && str_contains($b, 'id="rmm-pane-policy"') && str_contains($b, 'id="rmm-policy-effective"'), 'asset page: the "Policy and fields" tab is drawn');
$t = $paFlash(substr($b, (int) strpos($b, 'id="rmm-policy-effective"'), 9000));
$ok(str_contains($t, 'P23 Alpha') && str_contains($t, '120 seconds') && str_contains($t, 'Department Dept A') && str_contains($t, 'Check cpu_load') && str_contains($t, 'warn when the value is above 80') && str_contains($t, 'Checks this device receives'), 'asset tab: the policy is the source of the interval and the checks, with its scope');
$ok(str_contains($b, '/agent/rmm_policies.php?policy_id=' . $pidA), 'asset tab: link to the policy page');
[$c, $b] = $paGet('admin', '/agent/asset_details.php?asset_id=' . $A['LNX1']);
$ok(str_contains($b, 'No policy reaches this device; it follows the global configuration.'), 'asset tab (device with no policy): the empty state');
[$c, $b] = $paGet('deptb', '/agent/asset_details.php?asset_id=' . $A['WIN1']);
$ok(!str_contains($b, 'rmm-policy-effective') && !str_contains($b, 'P23 Alpha'), 'asset tab: a Dept B user sees nothing of a Dept A device');
[$c, $b] = $paGet('viewer', '/agent/asset_details.php?asset_id=' . $A['WIN1']);
$ok(str_contains($b, 'id="rmm-policy-effective"') && !str_contains($b, 'value="set_device_field"'), 'asset tab (viewer): reads, no write forms');

// a device-scoped policy at higher priority, and the "why"
$bodyB = ['action' => 'save_policy', 'policy_id' => 0, 'name' => 'P23 Device', 'description' => '', 'enabled' => '1', 'ci_mode' => 'override', 'ci_value' => '600', 'ft_script_checks' => 'off'];
$paPost('admin', $bodyB); $pidB = $paId('P23 Device');
$paPost('admin', ['action' => 'assign_policy', 'policy_id' => $pidB, 'as_type' => 'device', 'as_id_device' => $D['WIN1'], 'priority' => '50']);
[$c, $b] = $paGet('admin', '/agent/asset_details.php?asset_id=' . $A['WIN1']);
$t = $paFlash(substr($b, (int) strpos($b, 'id="rmm-policy-effective"'), 12000));
$ok(str_contains($t, '600 seconds') && str_contains($t, 'Device WIN1') && str_contains($t, 'Also set by P23 Alpha') && str_contains($t, 'a more specific scope wins'), 'asset tab: the device policy overrides the department one and the table says why');
$ci = ea_checkin($S['token']['WIN1'], ['capabilities' => ['check:cpu', 'check:service', 'policies']]);
$ok(($ci[2]['next_check_in_s'] ?? 0) === 600 && in_array('cpu_load', array_column($ci[2]['config']['checks'] ?? [], 'key'), true), 'check-in: the more specific interval wins, the department\'s checks still apply');
// explain a device
[$c, $b] = $paGet('admin', '/agent/rmm_policies.php?policy_id=' . $pidA . '&explain_device=' . $D['WIN1']);
$ok(str_contains($b, 'id="pol-explain"') && str_contains($paFlash($b), 'Settings in force') && str_contains($b, 'WIN1'), 'policy page: "Explain a device" shows the effective policy');
[$c, $b] = $paGet('deptb', '/agent/rmm_policies.php?policy_id=' . $pidA . '&explain_device=' . $D['WIN1']);
$ok(str_contains($b, 'does not exist, or you cannot see it') && !str_contains($b, 'Settings in force') && !str_contains($b, '>WIN1<'), 'explain a Dept A device as a Dept B user: the same answer as "missing"');

// ============================================================ Dept B sees nothing of the Dept A assignments
[$c, $b] = $paGet('deptb', '/agent/rmm_policies.php');
$ok($c === 200 && str_contains($b, 'P23 Alpha') && !str_contains($b, 'Dept A') && !str_contains($b, 'WIN1'), 'policy list (Dept B): the policies are listed, their Dept A and device assignments are not');
[$c, $b] = $paGet('deptb', '/agent/rmm_policies.php?policy_id=' . $pidA);
$ok($c === 200 && !str_contains($b, 'Dept A') && !str_contains($b, 'WIN1') && str_contains($b, 'not assigned to anything'), 'policy page (Dept B): no Dept A assignment, no Dept A device in the pickers');
[$c, $b] = $paGet('admin', '/agent/rmm_policies.php');
$ok(str_contains($b, 'Department Dept A') && str_contains($b, 'Device WIN1'), 'policy list (admin): who each policy reaches');

// ============================================================ edit makes a version; toggle; versions panel
$edit = $new; $edit['policy_id'] = $pidA; $edit['ci_value'] = '180';
[$c, , $h] = $paPost('admin', $edit);
$ok($one("SELECT version FROM rmm_policies WHERE policy_id=$pidA") === '2' && (int) $one("SELECT COUNT(*) FROM rmm_policy_versions WHERE policy_id=$pidA") === 2 && ($paBody('P23 Alpha')['settings']['interval.check_in_s']['value'] ?? 0) === 180, 'edit: a changed setting makes version 2 and keeps version 1');
$edit['description'] = 'renamed only';
$paPost('admin', $edit);
$ok($one("SELECT version FROM rmm_policies WHERE policy_id=$pidA") === '2' && $one("SELECT description FROM rmm_policies WHERE policy_id=$pidA") === 'renamed only', 'edit: a description-only change makes no new version');
[$c, $b] = $paGet('tech', '/agent/rmm_policies.php?policy_id=' . $pidA);
$t = $paFlash($b);
$ok(str_contains($b, 'id="pol-versions"') && str_contains($t, 'View settings of version 1') && str_contains($t, 'Check-in interval') && str_contains($t, 'First version') && str_contains($t, '120 seconds') && str_contains($t, '180 seconds') && !str_contains($b, '"interval.check_in_s"'), 'versions panel: both versions, what changed, a readable settings table (no raw JSON), read-only for a technician');
$ok(!str_contains($b, 'id="pol-form"') && str_contains($b, 'Read only'), 'policy page (technician): settings are a table, not a form');
$paPost('admin', ['action' => 'toggle_policy', 'policy_id' => $pidA, 'enabled' => 0]);
$ok($one("SELECT enabled FROM rmm_policies WHERE policy_id=$pidA") === '0' && $one("SELECT version FROM rmm_policies WHERE policy_id=$pidA") === '2', 'toggle: switched off, no new version');
$ci = ea_checkin($S['token']['WIN1'], ['capabilities' => ['check:cpu', 'check:service', 'policies']]);
$ok(!in_array('cpu_load', array_column($ci[2]['config']['checks'] ?? [], 'key'), true), 'a policy that is off reaches no device at the next check-in');
$paPost('admin', ['action' => 'toggle_policy', 'policy_id' => $pidA, 'enabled' => 1]);
$ok($one("SELECT enabled FROM rmm_policies WHERE policy_id=$pidA") === '1', 'toggle: switched on again');

// ============================================================ unassign, delete, hostile names
$aid = (int) $one("SELECT assignment_id FROM rmm_policy_assignments WHERE policy_id=$pidB");
$paPost('tech', ['action' => 'unassign_policy', 'policy_id' => $pidB, 'assignment_id' => $aid]);
$ok($one("SELECT COUNT(*) FROM rmm_policy_assignments WHERE assignment_id=$aid") === '1', 'a technician cannot remove an assignment');
$paPost('admin', ['action' => 'unassign_policy', 'policy_id' => $pidB, 'assignment_id' => $aid]);
$ok($one("SELECT COUNT(*) FROM rmm_policy_assignments WHERE assignment_id=$aid") === '0', 'unassign: the assignment is gone');
$paPost('admin', ['action' => 'save_policy', 'policy_id' => 0, 'name' => 'Evil <script>alert(1)</script>', 'description' => '<img src=x onerror=alert(2)>', 'enabled' => '1']);
$pidE = $paId('Evil <script>alert(1)</script>');
[$c, $b] = $paGet('admin', '/agent/rmm_policies.php');
$ok($pidE > 0 && str_contains($b, 'Evil &lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($b, '<script>alert(1)') && !str_contains($b, '<img src=x'), 'hostile policy name and description render as text in the list');
[$c, $b] = $paGet('admin', '/agent/rmm_policies.php?policy_id=' . $pidE);
$ok(!str_contains($b, '<script>alert(1)') && !str_contains($b, '<img src=x') && str_contains($b, 'data-rmm-confirm="Delete the policy &quot;Evil &lt;script&gt;'), 'hostile name on the policy page and in the delete confirmation is escaped');
$paPost('tech', ['action' => 'delete_policy', 'policy_id' => $pidE]);
$ok($paId('Evil <script>alert(1)</script>') === $pidE, 'a technician cannot delete a policy');
$paPost('admin', ['action' => 'delete_policy', 'policy_id' => $pidE]);
$paPost('admin', ['action' => 'delete_policy', 'policy_id' => $pidB]);
$ok($paId('Evil <script>alert(1)</script>') === 0 && $paId('P23 Device') === 0 && (int) $one("SELECT COUNT(*) FROM rmm_policy_versions WHERE policy_id=$pidE") === 0, 'delete: the policies and their versions are gone');
$paPost('admin', ['action' => 'bogus']);
$ok($paPolicies() === 1, 'an unknown action changes nothing');

// ============================================================ custom fields
[$c, $b] = $paGet('admin', '/agent/rmm_fields.php');
$ok($c === 200 && str_contains($b, 'No custom fields yet') && str_contains($b, 'id="fld-new"') && str_contains($b, '{{field.name}}'), 'fields page (admin): empty state, the New field form and the placeholder hint');
$fld = fn(array $f) => $paPost('admin', ['action' => 'save_field', 'field_id' => 0] + $f);
$fld(['name' => 'vpn_gateway', 'label' => 'VPN gateway', 'scope' => 'client', 'type' => 'text', 'default' => 'none', 'description' => 'Where the VPN ends']);
$fld(['name' => 'site_note', 'scope' => 'site', 'type' => 'text']);
$fld(['name' => 'asset_tier', 'scope' => 'device', 'type' => 'list', 'options' => "gold\nsilver\nbronze", 'default' => 'silver']);
$fld(['name' => 'api_secret', 'scope' => 'client', 'type' => 'secret']);
$fld(['name' => 'Bad Name', 'scope' => 'client', 'type' => 'text']);
[$c, $b] = $paGet('admin', '/agent/rmm_fields.php');
$ok((int) $one('SELECT COUNT(*) FROM rmm_custom_fields') === 4 && str_contains($paFlash($b), 'A field name is lower case letters'), 'fields: four defined, an invalid name is refused with the rule');
$fid = fn(string $n) => (int) $one("SELECT field_id FROM rmm_custom_fields WHERE name='$n'");
$ok($one("SELECT options_json FROM rmm_custom_fields WHERE name='asset_tier'") === '["gold","silver","bronze"]' && $one("SELECT default_value FROM rmm_custom_fields WHERE name='api_secret'") === null, 'stored: list options one per line; a secret has no default');
$paPost('admin', ['action' => 'save_field', 'field_id' => $fid('api_secret'), 'label' => 'x', 'default' => 'oops']);
$ok($one("SELECT default_value FROM rmm_custom_fields WHERE name='api_secret'") === null, 'a secret field refuses a default');
$paPost('admin', ['action' => 'save_field', 'field_id' => $fid('vpn_gateway'), 'label' => 'VPN gw', 'description' => 'changed', 'default' => '']);
$ok($one("SELECT label FROM rmm_custom_fields WHERE name='vpn_gateway'") === 'VPN gw' && $one("SELECT default_value FROM rmm_custom_fields WHERE name='vpn_gateway'") === null && $one("SELECT scope FROM rmm_custom_fields WHERE name='vpn_gateway'") === 'client', 'edit: label, description and default change; the scope stays');
$paPost('tech', ['action' => 'save_field', 'field_id' => 0, 'name' => 'tech_field', 'scope' => 'client', 'type' => 'text']);
$ok($fid('tech_field') === 0, 'a technician cannot define a field');
$q("INSERT INTO locations SET location_name='HQ <b>North</b>', location_client_id=1"); $paSiteA = (int) $db->insert_id;
$q("INSERT INTO locations SET location_name='B Branch', location_client_id=2"); $paSiteB = (int) $db->insert_id;
// values (rmm.device.manage is an administrator ability in RivetIT)
$paPost('tech', ['action' => 'set_field_value', 'field_id' => $fid('vpn_gateway'), 'scope_id' => 1, 'value' => 'tech-value']);
$ok((int) $one("SELECT COUNT(*) FROM rmm_custom_field_values WHERE field_id={$fid('vpn_gateway')}") === 0, 'a technician cannot set a department value (administrators only)');
$paPost('admin', ['action' => 'set_field_value', 'field_id' => $fid('vpn_gateway'), 'scope_id' => 1, 'value' => '10.0.0.1']);
$ok($one("SELECT value_text FROM rmm_custom_field_values WHERE field_id={$fid('vpn_gateway')} AND scope_id=1") === '10.0.0.1', 'an administrator sets a department value');
$paPost('tech', ['action' => 'set_field_value', 'field_id' => $fid('api_secret'), 'scope_id' => 1, 'value' => 'tech-secret']);
$ok((int) $one("SELECT COUNT(*) FROM rmm_custom_field_values WHERE field_id={$fid('api_secret')}") === 0, 'a technician cannot set a secret value');
$paPost('admin', ['action' => 'set_field_value', 'field_id' => $fid('api_secret'), 'scope_id' => 1, 'value' => 'S3cret-Value-XYZ']);
$ok((int) $one("SELECT COUNT(*) FROM rmm_custom_field_values WHERE field_id={$fid('api_secret')} AND value_enc IS NOT NULL AND value_text IS NULL") === 1, 'an administrator sets a secret value (stored sealed)');
$paPost('admin', ['action' => 'set_field_value', 'field_id' => $fid('site_note'), 'scope_id' => $paSiteA, 'value' => 'north wing']);
$ok($one("SELECT value_text FROM rmm_custom_field_values WHERE field_id={$fid('site_note')}") === 'north wing', 'a site value is stored (the department is derived from the site)');
$paPost('admin', ['action' => 'set_field_value', 'field_id' => $fid('vpn_gateway'), 'scope_id' => 2, 'value' => '']);
[$c, $b] = $paGet('admin', '/agent/rmm_fields.php');
$ok(str_contains($paFlash($b), 'Enter a value, or use Clear value') && (int) $one("SELECT COUNT(*) FROM rmm_custom_field_values WHERE field_id={$fid('vpn_gateway')}") === 1, 'an empty value without "Clear" is refused');
$ok(str_contains($b, '10.0.0.1') && str_contains($b, 'Dept A') && str_contains($b, 'north wing') && !str_contains($b, 'S3cret-Value-XYZ') && !str_contains($b, 'tech-secret') && str_contains($b, 'never shown'), 'fields page: values listed; the secret is shown only as set, never as a value');
$ok(str_contains($b, 'type="password"') && str_contains($b, 'Write-only'), 'fields page: the secret input is a write-only password box');
[$c, $b] = $paGet('deptb', '/agent/rmm_fields.php');
$ok($c === 200 && !str_contains($b, '10.0.0.1') && !str_contains($b, 'Dept A') && !str_contains($b, 'north wing') && !str_contains($b, 'id="fld-new"'), 'fields page (Dept B): no Dept A values, no definition controls');
$ok(!str_contains($b, 'HQ') && !str_contains($b, 'north wing'), 'fields page (Dept B): no Dept A site');
[$c, $b] = $paGet('admin', '/agent/rmm_fields.php');
$ok(str_contains($b, 'HQ &lt;b&gt;North&lt;/b&gt;') && !str_contains($b, 'HQ <b>'), 'a hostile site name renders as text in the value picker');
[$c, $b] = $paGet('viewer', '/agent/rmm_fields.php');
$ok($c === 200 && !str_contains($b, 'name="action" value="set_field_value"') && !str_contains($b, 'id="fld-new"'), 'fields page (viewer): read only');
// device values on the asset tab
$paPost('tech', ['action' => 'set_device_field', 'device_id' => $D['WIN1'], 'field_id' => $fid('asset_tier'), 'value' => 'gold']);
$ok((int) $one("SELECT COUNT(*) FROM rmm_custom_field_values WHERE field_id={$fid('asset_tier')}") === 0, 'a technician cannot set a device value');
$paPost('admin', ['action' => 'set_device_field', 'device_id' => $D['WIN1'], 'field_id' => $fid('asset_tier'), 'value' => 'gold']);
$ok($one("SELECT value_text FROM rmm_custom_field_values WHERE field_id={$fid('asset_tier')} AND scope_id={$D['WIN1']}") === 'gold', 'a device value is set through the asset tab handler');
$paPost('admin', ['action' => 'set_device_field', 'device_id' => $D['WIN1'], 'field_id' => $fid('asset_tier'), 'value' => 'platinum']);
$ok($one("SELECT value_text FROM rmm_custom_field_values WHERE field_id={$fid('asset_tier')} AND scope_id={$D['WIN1']}") === 'gold', 'a value outside the list is refused');
$paPost('deptb', ['action' => 'set_device_field', 'device_id' => $D['WIN1'], 'field_id' => $fid('asset_tier'), 'value' => 'bronze']);
$ok($one("SELECT value_text FROM rmm_custom_field_values WHERE field_id={$fid('asset_tier')} AND scope_id={$D['WIN1']}") === 'gold', 'a Dept B user cannot set a value on a Dept A device');
[$c, $b] = $paGet('admin', '/agent/asset_details.php?asset_id=' . $A['WIN1']);
$t = $paFlash(substr($b, (int) strpos($b, 'id="rmm-policy-fields"'), 9000));
$ok(str_contains($t, 'asset_tier') && str_contains($t, 'gold') && str_contains($t, 'vpn_gateway') && str_contains($t, '10.0.0.1') && str_contains($t, 'north wing') === false && str_contains($b, 'name="action" value="set_device_field"') && str_contains($b, 'rmm-policy#') === false, 'asset tab: fields of the device, its department and site with their values; a form for the device-scoped one');
$ok(!str_contains($b, 'S3cret-Value-XYZ') && preg_match('/api_secret.{0,400}Set/s', $b) === 1, 'asset tab: the secret shows as set, never its value');
$ok(str_contains($b, 'value="/agent/asset_details.php?asset_id=' . $A['WIN1'] . '#rmm-policy"'), 'asset tab forms return to the tab');
$paPost('admin', ['action' => 'set_device_field', 'device_id' => $D['WIN1'], 'field_id' => $fid('asset_tier'), 'clear' => '1']);
$ok((int) $one("SELECT COUNT(*) FROM rmm_custom_field_values WHERE field_id={$fid('asset_tier')}") === 0, 'clearing a device value removes it');
foreach (['viewer', 'tech'] as $w) {
    [$c, $b] = $paGet($w, '/agent/asset_details.php?asset_id=' . $A['WIN1']);
    $ok(!str_contains($b, 'name="action" value="set_device_field"') && str_contains($b, 'id="rmm-policy-fields"'), "asset tab ($w): the fields are read only");
}
// hostile
$fld(['name' => 'evil_field', 'label' => '<script>alert(3)</script>', 'description' => '"><img src=x onerror=alert(4)>', 'scope' => 'client', 'type' => 'text']);
[$c, $b] = $paGet('admin', '/agent/rmm_fields.php');
$ok(str_contains($b, '&lt;script&gt;alert(3)') && !str_contains($b, '<script>alert(3)') && !str_contains($b, '<img src=x'), 'hostile field label and description are escaped');
// delete says how many values go
$ok(str_contains($b, 'Its 1 value goes with it') || str_contains($b, 'Its 1 value'), 'delete confirmation names the number of values');
$paPost('admin', ['action' => 'delete_field', 'field_id' => $fid('vpn_gateway')]);
$ok($fid('vpn_gateway') === 0 && (int) $one('SELECT COUNT(*) FROM rmm_custom_field_values WHERE field_id NOT IN (SELECT field_id FROM rmm_custom_fields)') === 0, 'delete: the field and its values are gone');

// ============================================================ switches: sub-switch off and module off
$paFeatures(['scripts', 'alerting']);
[$c, $b] = $paGet('admin', '/agent/rmm_policies.php');
$ok($c === 403 && str_contains($b, 'switched off') && !str_contains($b, 'P23 Alpha'), 'policies switch off: the page is the notice');
$cnt = $paPolicies();
$paPost('admin', ['action' => 'save_policy', 'policy_id' => 0, 'name' => 'P23 Off']);
$paPost('admin', ['action' => 'toggle_policy', 'policy_id' => $pidA, 'enabled' => 0]);
$ok($paPolicies() === $cnt && $one("SELECT enabled FROM rmm_policies WHERE policy_id=$pidA") === '1', 'policies switch off: the handler refuses and changes nothing');
[$c, $b] = $paGet('admin', '/agent/asset_details.php?asset_id=' . $A['WIN1']);
$ok(str_contains($b, 'id="rmm-policy-fields"') && !str_contains($b, 'id="rmm-policy-effective"'), 'policies switch off: the tab keeps only the fields card');
[$c, $b] = $paGet('admin', '/agent/rmm_fields.php');
$ok($c === 200, 'the fields page needs the scripts switch, not the policies switch');
$paFeatures(['policies', 'alerting']);
[$c, $b] = $paGet('admin', '/agent/rmm_fields.php');
$ok($c === 403 && str_contains($b, 'switched off'), 'scripts switch off: the fields page is the notice');
$paPost('admin', ['action' => 'save_field', 'field_id' => 0, 'name' => 'off_field', 'scope' => 'client', 'type' => 'text']);
$ok($fid('off_field') === 0, 'scripts switch off: the field handler refuses');
[$c, $b] = $paGet('admin', '/agent/asset_details.php?asset_id=' . $A['WIN1']);
$ok(str_contains($b, 'id="rmm-policy-effective"') && !str_contains($b, 'id="rmm-policy-fields"'), 'scripts switch off: the tab keeps only the effective policy');
$paFeatures(['alerting']);
[$c, $b] = $paGet('admin', '/agent/asset_details.php?asset_id=' . $A['WIN1']);
$ok(!str_contains($b, 'id="rmm-tab-policy"'), 'both switches off: no Policy and fields tab');
$paFeatures(['policies', 'scripts', 'alerting']);
// module off
$rmm->admin()->disable($admin); $fresh();
$a1 = $questions();
$pc = rivetRmmEnabled($db) || rivetRmmFeatureOn('policies', $db) ? 1 : null; $pc2 = rivetRmmFeatureOn('scripts', $db) ? 1 : null;
$a2 = $questions();
$ok($pc === null && $pc2 === null && $a2 - $a1 === $overhead, 'module OFF: both gates answer from the state file with zero statements');
[$c, $b] = $paGet('admin', '/agent/rmm_policies.php');
$ok($c === 403 && !str_contains($b, 'P23 Alpha'), 'module OFF: the policies page is the 403 notice');
[$c, $b] = $paGet('admin', '/agent/rmm_fields.php');
$ok($c === 403, 'module OFF: the fields page is the 403 notice');
$cnt = $paPolicies();
$paPost('admin', ['action' => 'save_policy', 'policy_id' => 0, 'name' => 'P23 ModOff']);
$paPost('admin', ['action' => 'delete_policy', 'policy_id' => $pidA]);
$ok($paPolicies() === $cnt && $paId('P23 Alpha') === $pidA, 'module OFF: the handler refuses, nothing is written or deleted');
$rmm->admin()->enable($admin); $fresh(); rivetRmmForgetAccess();
[$c, $b] = $paGet('admin', '/agent/rmm_policies.php');
$ok($c === 200 && str_contains($b, 'P23 Alpha'), 'module back ON: the page works again');
