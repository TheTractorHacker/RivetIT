<?php
/*
 * Fragment of tests/rmm_ui.php (RivetIT 26.10.54): the installer builder made obvious (Create installer button, reasons, nothing-published notices, the dialog link) and the
 * "Remote access (MeshCentral) setup" page (admin/settings_mesh_wizard.php, admin/post/settings_mesh_wizard.php, includes/rmm_mesh_wizard.php, js/rmm_mesh_wizard.js).
 *
 *   1. Create installer: administrator / token manager / technician / viewer / module-only / department-restricted, module off, nothing published;
 *   2. the wizard: who may open it, the five steps and their state, every save through admin/post.php (steps keep each other's values), the live check, the links;
 *   3. hostile input: markup in every field, forged CSRF, wrong method, other roles posting; nothing stored, nothing echoed unescaped, the secrets never printed.
 * The Mesh settings, the module switch and the fixtures it changes are put back.
 */

$mwFresh = function () use ($rmm, $fresh): void { $fresh(); $rmm->meshAgentSettings()->refresh(); };
$mwSaved = $rmm->readModel()->settingsSummary();
$mwView = rivetRmmMeshInstallView($rmm);
$mwKey = str_repeat('ab12', 16);
$mwRestore = function () use ($rmm, $admin, $mwSaved, $mwView, $q, $mwFresh) {
    $in = ['mesh_enabled' => (int) $mwSaved['mesh_enabled'], 'mesh_url' => (string) $mwSaved['mesh_url'], 'mesh_domain' => (string) $mwSaved['mesh_domain'], 'mesh_account_template' => (string) $mwSaved['mesh_account_template'],
        'mesh_policy' => (string) $mwSaved['mesh_policy'], 'mesh_token_ttl_s' => (int) $mwSaved['mesh_token_ttl_s']];
    if ($mwView !== null) { $in['mesh_group_id'] = $mwView['group_id_url'] !== '' ? $mwView['group_id_url'] : $mwView['group_id']; $in['mesh_server_cert_pin'] = $mwView['server_cert_pin']; }
    $rmm->admin()->saveMesh($admin, $in, false);
    $mwFresh();
};
$mwAdm = fn (array $f, string $who = 'admin', bool $csrf = true) => web($wb, 'POST', '/admin/post.php', $SS[$who], $f + ($csrf ? ['csrf_token' => 'csrftok1'] : []), ['Referer: ' . $wb . '/admin/settings_mesh_wizard.php']);
$mwPage = fn (string $who = 'admin') => web($wb, 'GET', '/admin/settings_mesh_wizard.php', $SS[$who]);
$mwState = fn () => rivetRmmMeshWizardState($rmm, $db);
$mwStep = fn (array $st, int $n) => $st['steps'][$n - 1];
$rmm->admin()->enable($admin);
$q('DELETE FROM endpoint_agent_binaries');
$q('DELETE FROM endpoint_agent_msi_templates');
$mwFresh();

// ================================================================ 1. Create installer
foreach (['fleet' => '/agent/rmm_fleet.php', 'dashboard' => '/agent/rmm_dashboard.php'] as $pg => $path) {
    [$c, $b] = web($wb, 'GET', $path, $SS['admin']);
    $ok($c === 200 && preg_match('/<a href="\/agent\/rmm_add_device\.php" class="btn btn-primary btn-sm" id="rmm-build-installer">.*?Create installer<\/a>/s', $b) === 1, "$pg: the administrator gets a primary Create installer button");
    $ok(str_contains($b, 'No Windows agent program is published on this server yet') && str_contains($b, '/admin/settings_endpoint_agent.php#agent-updates'), "$pg: nothing published: it says so and links to Agent updates");
    foreach (['tech', 'viewer'] as $who) {
        [$c, $b] = web($wb, 'GET', $path, $SS[$who]);
        if ($c !== 200) { $ok(true, "$pg: $who has no access to the page at all (status $c)"); continue; }
        $ok(str_contains($b, 'id="rmm-build-installer"') && str_contains($b, 'data-installer-blocked="no_right"') && str_contains($b, 'rmm.token.manage') && str_contains($b, 'id="rmm-installer-notice"')
            && !str_contains($b, 'href="/agent/rmm_add_device.php" class="btn btn-primary'), "$pg: $who sees the entry and the missing permission named, not a working button");
        $ok(!str_contains($b, '#agent-updates'), "$pg: $who is not sent into Administration");
    }
}
// published agent but no MSI: the small hint; with both: nothing
$mwTmp = sys_get_temp_dir() . '/mw_' . bin2hex(random_bytes(3)); mkdir($mwTmp);
register_shutdown_function(function () use ($mwTmp) { foreach (glob("$mwTmp/*") ?: [] as $f) { @unlink($f); } @rmdir($mwTmp); });
file_put_contents("$mwTmp/a.exe", ad_fake_pe(0x8664, 70000, 'mw'));
$r = $rmm->binaryStore()->publish("$mwTmp/a.exe", '0.9.2', 'amd64', 1, ['activate' => true]);
$ok(!empty($r['ok']), 'fixture: agent 0.9.2 published');
$mwFresh();
[, $b] = web($wb, 'GET', '/agent/rmm_fleet.php', $SS['admin']);
$ok(!str_contains($b, 'No Windows agent program is published') && str_contains($b, 'data-installer-code="no_msi"') && str_contains($b, 'No MSI template is published'), 'agent published, no MSI: only the small MSI hint');
file_put_contents("$mwTmp/t.msi", "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 16000));
$r = $rmm->binaryStore()->publishMsi("$mwTmp/t.msi", '0.9.2', 'amd64', 1, ['activate' => true]);
$mwFresh();
[, $b] = web($wb, 'GET', '/agent/rmm_fleet.php', $SS['admin']);
$ok(!str_contains($b, 'id="rmm-installer-notice"') && str_contains($b, 'Create installer'), 'everything published: no notice');
[, $b] = web($wb, 'GET', '/agent/rmm_fleet.php', $SS['admin']);
$ok(str_contains($b, 'id="rmm-inst-create-link"') && str_contains($b, 'Open the full Add device page'), 'the Add device dialog links to the installer page with a button');
$q('DELETE FROM endpoint_agent_binaries');
$q('DELETE FROM endpoint_agent_msi_templates');
$mwFresh();
[$c, $b] = $adPage('tech');
$ok(!str_contains($b, 'id="ad-root"') && str_contains($b, 'rmm.token.manage'), 'the installer page names the missing permission');
$gate = rivetRmmInstallerGate($db, 12);
$ok(!$gate['allowed'] && $gate['code'] === 'no_right' && !str_contains($gate['message'], '<a '), 'gate: a viewer gets no_right and no link into Administration');
$gate = rivetRmmInstallerGate($db, 1);
$ok($gate['allowed'] && $gate['code'] === 'ok' && $gate['admin'], 'gate: the administrator is allowed');
$rmm->admin()->disable($admin); $mwFresh();
[, $b] = web($wb, 'GET', '/agent/rmm_dashboard.php', $SS['admin']);
$ok(str_contains($b, 'switched off') && str_contains($b, 'Administration &gt; Endpoint agent'), 'dashboard, module off: the message names the setting');
$gate = rivetRmmInstallerGate($db, 12);
$ok($gate['code'] === 'module_off' && !str_contains($gate['message'], '<a '), 'gate: module off for a viewer: no link');
[, $b] = web($wb, 'GET', '/agent/rmm_dashboard.php', $SS['admin']);
$ok(str_contains($b, 'data-installer-blocked="module_off"') && str_contains($b, 'switched off'), 'dashboard with the module off: the entry stays and says why');
$rmm->admin()->enable($admin); $mwFresh();

// ================================================================ 2. the wizard
$rmm->admin()->saveMesh($admin, ['mesh_enabled' => 0, 'mesh_url' => '', 'mesh_domain' => '', 'mesh_group_id' => '', 'mesh_server_cert_pin' => '', 'mesh_policy' => 'unattended', 'mesh_token_ttl_s' => 300], false); $mwFresh();
[$c, $p] = $mwPage();
$ok($c === 200 && str_contains($p, 'id="mw-root"') && str_contains($p, 'Remote access (MeshCentral) setup') && substr_count($p, 'class="card mb-3 mw-step"') === 5, 'the wizard renders five steps for the administrator');
$st = $mwState();
$ok(!$mwStep($st, 1)['ok'] && !$mwStep($st, 2)['ok'] && !$mwStep($st, 3)['ok'] && !$mwStep($st, 4)['ok'], 'nothing configured: steps 1 to 4 are amber (to do)');
$ok(str_contains($p, $st['done'] . ' of 5 steps done') && substr_count($p, 'data-state="todo"') >= 4, 'the page shows amber and the right count');
$ok(str_contains($p, 'The MeshCentral address is not set.') && str_contains($p, 'switched off'), 'step 1 names what is missing');
foreach (['loginTokenKey', 'Add Device Group', 'Add Agent', 'Read it from the server', 'Also install the MeshCentral agent', 'Remote access</strong>', 'Troubleshooting', 'ts-home', 'ts-node', 'ts-agent', 'ts-cert', 'id="mw-live-conn"', 'name="mesh_login_key"', 'name="mesh_group_id"', 'name="mesh_server_cert_pin"'] as $needle) {
    $ok(str_contains($p, $needle), "wizard contains $needle");
}
$ok(!preg_match('/\son[a-z]+\s*=\s*["\']|javascript:/i', $p), 'no inline handlers on the wizard');
$ok(!preg_match('/[{]\s*"|"\s*:\s*[{\[]/', preg_replace('/<script.*?<\/script>/s', '', $p)), 'no JSON shown anywhere on the page');
// links
[, $ea] = web($wb, 'GET', '/admin/settings_endpoint_agent.php', $SS['admin']);
$ok(str_contains($ea, 'href="/admin/settings_mesh_wizard.php"') && str_contains($ea, 'id="rmm-mesh-wizard-link"'), 'the Endpoint agent page header links to the wizard');
[, $set] = web($wb, 'GET', '/admin/settings.php', $SS['admin']);
$ok(str_contains($set, 'settings_mesh_wizard.php'), 'Administration > Settings lists the wizard');
$ok(str_contains(file_get_contents($root . '/includes/settings_search_index.php'), '/admin/settings_mesh_wizard.php'), 'the settings search finds the wizard');
// device page, Mesh not set up
$rmm->settings()->set(['mesh_enabled' => 0]); $mwFresh();
$ap = rivetRmmUiStrip($panel('WIN1', 'admin'));
$ok(str_contains($ap, 'id="rmm-act-remote-setup"') && str_contains($ap, 'settings_mesh_wizard.php'), 'device page, Mesh not set up: the administrator gets a Set up remote access link');
$ok(!str_contains(rivetRmmUiStrip($panel('WIN1', 'tech')), 'rmm-act-remote'), 'device page, Mesh not set up: a technician sees no Remote button and no link into Administration');
$ok(str_contains(rivetRmmRemMeshHtml(['mesh' => ['configured' => false, 'views' => []], 'admin' => true]), 'settings_mesh_wizard.php'), 'the Remote tab links the administrator to the wizard');

// role matrix: nobody else opens or posts
foreach (['tech', 'rebootonly', 'viewer', 'remoteonly', 'moduleonly', 'normo', 'deptb'] as $who) {
    [$c, $b] = $mwPage($who);
    $ok(!str_contains($b, 'id="mw-root"') && !str_contains($b, 'name="mesh_login_key"'), "$who: no wizard (status $c)");
    $before = json_encode($rmm->readModel()->settingsSummary());
    $mwAdm(['mw_action' => 'save_server', 'mesh_enabled' => '1', 'mesh_url' => 'https://evil.example.test', 'mesh_login_key' => $mwKey], $who);
    $mwFresh();
    $ok(json_encode($rmm->readModel()->settingsSummary()) === $before, "$who: posting to the handler changes nothing");
}
[$c, $b] = web($wb, 'GET', '/admin/settings_mesh_wizard.php', 'nosession');
$ok(!str_contains($b, 'id="mw-root"'), 'signed out: no wizard');

// step 1 saves, keeps the group, never prints the key
[$c] = $mwAdm(['mw_action' => 'save_server', 'mesh_enabled' => '1', 'mesh_url' => 'https://mesh.example.test', 'mesh_domain' => '', 'mesh_login_key' => $mwKey]); $mwFresh();
$s = $rmm->readModel()->settingsSummary();
$ok($s['mesh_url'] === 'https://mesh.example.test' && (int) $s['mesh_enabled'] === 1 && !empty($s['mesh_login_key_set']) && $s['mesh_account_template'] === $mwSaved['mesh_account_template'], 'step 1 saved the address, switched it on, stored the key, kept the account name');
[, $p] = $mwPage();
$ok(str_contains($p, 'Saved: https://mesh.example.test') && str_contains($p, 'stored encrypted') && !str_contains($p, $mwKey) && str_contains($p, 'Saved'), 'step 1 shows done and never prints the key');
$st = $mwState();
$ok($mwStep($st, 1)['ok'] && !$mwStep($st, 2)['ok'] && $st['done'] === 1 && str_contains($p, '1 of 5 steps done') && str_contains($p, 'data-step="1" data-state="ok"'), 'live state: step 1 is green, step 2 amber');
// step 2: the whole pasted download address
$meshid = str_repeat('A', 30) . '@$' . str_repeat('b', 32);
$meshid = substr($meshid, 0, 64);
[$c] = $mwAdm(['mw_action' => 'save_group', 'mesh_group_id' => 'https://mesh.example.test/meshagents?id=4&meshid=' . $meshid . '&installflags=0']); $mwFresh();
$v = rivetRmmMeshInstallView($rmm);
$ok($v['group_id'] !== '' && $v['group_id_url'] === $meshid, 'step 2: the whole pasted download address is accepted and the id is found in it');
$s = $rmm->readModel()->settingsSummary();
$ok($s['mesh_url'] === 'https://mesh.example.test' && !empty($s['mesh_login_key_set']), 'step 2 kept the step 1 values');
[, $p] = $mwPage();
$ok(str_contains($p, 'The device group is saved.') && !str_contains($p, $meshid) && !str_contains($p, $v['group_id']), 'step 2 is green and the group id is never printed back');
// the wrong host is a warning, an unparsable value is refused and writes nothing
[$c] = $mwAdm(['mw_action' => 'save_group', 'mesh_group_id' => 'not a group']); $mwFresh();
$ok(rivetRmmMeshInstallView($rmm)['group_id_url'] === $meshid, 'step 2: garbage is refused and the saved group stays');
[, $p] = $mwPage();
$mwAdm(['mw_action' => 'save_group', 'mesh_group_id' => '']); $mwFresh();
$ok(rivetRmmMeshInstallView($rmm)['group_id_url'] === $meshid, 'step 2: saving an empty box does not remove the group');
// step 3: pin
$pin96 = str_repeat('0f', 48);
$mwAdm(['mw_action' => 'save_pin', 'mesh_server_cert_pin' => $pin96]); $mwFresh();
$ok(rivetRmmMeshInstallView($rmm)['server_cert_pin'] === $pin96 && rivetRmmMeshInstallView($rmm)['group_id_url'] === $meshid, 'step 3: a pin is saved without touching the group');
$st = $mwState();
$ok($mwStep($st, 3)['ok'] && $mwStep($st, 3)['mode'] === 'pinned', 'step 3 is green with a pin');
$mwAdm(['mw_action' => 'save_pin', 'mesh_server_cert_pin' => 'zz-not-a-pin']); $mwFresh();
$ok(rivetRmmMeshInstallView($rmm)['server_cert_pin'] === $pin96, 'step 3: a bad pin is refused and the saved pin stays');
$mwAdm(['mw_action' => 'save_pin', 'clear' => '1']); $mwFresh();
$ok(rivetRmmMeshInstallView($rmm)['server_cert_pin'] === '' && !$mwStep($mwState(), 3)['ok'], 'step 3: removing the pin leaves it amber until the certificate is checked');
// step 4 follows steps 1 and 2
$st = $mwState();
$ok($mwStep($st, 4)['ok'] && str_contains($mwStep($st, 4)['summary'], 'offered'), 'step 4 is green once steps 1 and 2 are saved');
$mwAdm(['mw_action' => 'save_group', 'clear' => '1', 'mesh_group_id' => '']); $mwFresh();
$st = $mwState();
$ok(!$mwStep($st, 2)['ok'] && !$mwStep($st, 4)['ok'], 'removing the group turns steps 2 and 4 amber');
$mwAdm(['mw_action' => 'save_group', 'mesh_group_id' => $meshid]); $mwFresh();
// step 5: a mapped device, hostile host name escaped
$evil = '<img src=x onerror=alert(1)>';
$oldHost = (string) $one('SELECT hostname FROM endpoint_agent_devices WHERE device_id = ' . (int) $D['WIN1']);
$q("UPDATE endpoint_agent_devices SET hostname = '" . $db->real_escape_string($evil) . "' WHERE device_id = " . (int) $D['WIN1']);
$hadNode = (int) $one('SELECT COUNT(*) FROM endpoint_agent_mesh_nodes WHERE device_id = ' . (int) $D['WIN1']);
if (!$hadNode) { $q('INSERT INTO endpoint_agent_mesh_nodes SET device_id = ' . (int) $D['WIN1'] . ", mesh_node_id = 'node//" . str_repeat('A', 64) . "', source = 'manual'"); }
$st = $mwState();
$ok($mwStep($st, 5)['ok'] && $st['devices']['mapped'] >= 1, 'step 5 is green when an enrolled device is connected');
[, $p] = $mwPage();
$ok(str_contains($p, '&lt;img src=x') && !str_contains($p, $evil) && str_contains($p, 'id="mw-device-list"'), 'step 5 lists the device with the host name escaped');
if (!$hadNode) { $q('DELETE FROM endpoint_agent_mesh_nodes WHERE device_id = ' . (int) $D['WIN1']); }
$q("UPDATE endpoint_agent_devices SET hostname = '" . $db->real_escape_string($oldHost) . "' WHERE device_id = " . (int) $D['WIN1']);
$ok(!$mwStep($mwState(), 5)['ok'] || $hadNode, 'step 5 is amber again without a connected device');

// the live checks
$hp = ea_start_php($root . '/tests/support/mesh_health_router.php', [], []);
$hurl = "http://127.0.0.1:{$hp['port']}";
$mwAdm(['mw_action' => 'save_server', 'mesh_enabled' => '1', 'mesh_url' => $hurl, 'mesh_domain' => '']); $mwFresh();
[$c, $b] = web($wb, 'POST', '/admin/post.php', $SS['admin'], ['mw_action' => 'live', 'csrf_token' => 'csrftok1'], ['Referer: ' . $wb . '/admin/settings_mesh_wizard.php', 'Accept: application/json']);
$j = json_decode($b, true) ?: [];
$ok($c === 200 && ($j['conn']['state'] ?? '') === 'ok' && ($j['conn']['text'] ?? '') === 'MeshCentral answered.', 'live check: a server that answers /health.ashx is green');
$ok(in_array($j['trust']['state'] ?? '', ['fail', 'none', 'private', 'public'], true), 'live check: the certificate answer is one of the known states (plain http gives no certificate)');
$mwAdm(['mw_action' => 'save_server', 'mesh_enabled' => '1', 'mesh_url' => $wb, 'mesh_domain' => '']); $mwFresh();
[$c, $b] = web($wb, 'POST', '/admin/post.php', $SS['admin'], ['mw_action' => 'live', 'csrf_token' => 'csrftok1'], ['Referer: ' . $wb . '/admin/settings_mesh_wizard.php']);
$j = json_decode($b, true) ?: [];
$ok(($j['conn']['state'] ?? '') === 'fail' && str_contains((string) $j['conn']['text'], 'did not answer'), 'live check: a server without /health.ashx is amber with the reason');
[$c, $b] = web($wb, 'POST', '/admin/post.php', $SS['admin'], ['mw_action' => 'live'], ['Referer: ' . $wb . '/admin/settings_mesh_wizard.php']);
$ok($c === 403 && !str_contains($b, 'answered'), 'live check without a CSRF token: 403');
[$c] = web($wb, 'GET', '/admin/post.php?mw_action=live', $SS['admin'], [], ['Referer: ' . $wb . '/admin/settings_mesh_wizard.php']);
$ok($c !== 500, 'live check by GET does nothing (no 500)');
// save and test: the saved-but-unreachable path
[$c, , $hd] = $mwAdm(['mw_action' => 'save_server', 'mesh_enabled' => '1', 'mesh_url' => $hurl, 'test' => '1']); $mwFresh();
[, $p] = $mwPage();
$ok(str_contains($p, 'Saved. MeshCentral answered.'), 'Save and test connection: the confirmation says MeshCentral answered');
[$c] = $mwAdm(['mw_action' => 'probe_pin']); $mwFresh();
[, $p] = $mwPage();
$ok(str_contains($p, 'id="mw_pin_result"') && str_contains($p, 'Could not read the certificate'), 'Read it from the server against a plain http address: the warning, nothing proposed to save');
$ok(rivetRmmMeshInstallView($rmm)['server_cert_pin'] === '', 'reading the certificate saved no pin');
[$c, $b] = $mwAdm(['mw_action' => 'save_server', 'mesh_enabled' => '1', 'mesh_url' => 'https://x.test'], 'admin', false);
$ok($c !== 500 && $rmm->readModel()->settingsSummary()['mesh_url'] === $hurl, 'save without a CSRF token: refused, nothing changed');

// ================================================================ 3. hostile input
$bad = ['"><script>alert(1)</script>', 'javascript:alert(1)', 'https://user:pw@mesh.example.test', 'https://mesh.example.test/?a=b', "https://mesh.example.test\r\nX: y", str_repeat('a', 600)];
foreach ($bad as $i => $u) {
    $before = $rmm->readModel()->settingsSummary()['mesh_url'];
    $mwAdm(['mw_action' => 'save_server', 'mesh_enabled' => '1', 'mesh_url' => $u]); $mwFresh();
    $ok($rmm->readModel()->settingsSummary()['mesh_url'] === $before, "hostile address #$i is refused, nothing stored");
    [, $p] = $mwPage();
    $ok(!str_contains($p, '<script>alert(1)</script>') && !preg_match('/<img[^>]*onerror/i', $p), "hostile address #$i: nothing unescaped on the page");
}
foreach (['<script>alert(1)</script>', "x'; DROP TABLE settings;--", '../../etc/passwd', str_repeat('Z', 3000)] as $i => $g) {
    $mwAdm(['mw_action' => 'save_group', 'mesh_group_id' => $g]); $mwFresh();
    $ok(rivetRmmMeshInstallView($rmm)['group_id_url'] === $meshid, "hostile device group #$i is refused, the saved group stays");
    [, $p] = $mwPage();
    $ok(!str_contains($p, '<script>alert(1)</script>'), "hostile device group #$i: not echoed unescaped");
}
foreach (['<b>', str_repeat('f', 200), "0f\n0f"] as $i => $g) {
    $mwAdm(['mw_action' => 'save_pin', 'mesh_server_cert_pin' => $g]); $mwFresh();
    $ok(rivetRmmMeshInstallView($rmm)['server_cert_pin'] === '', "hostile pin #$i is refused");
}
$mwAdm(['mw_action' => 'save_server', 'mesh_enabled' => '1', 'mesh_url' => $hurl, 'mesh_login_key' => '<script>']); $mwFresh();
$ok(!str_contains(json_encode($rmm->readModel()->settingsSummary()), '<script>'), 'a hostile login key is refused');
$mwAdm(['mw_action' => 'save_server', 'mesh_url' => ['a' => 'b'], 'mesh_login_key' => ['x']]); $mwFresh();
[$c] = $mwPage();
$ok($c === 200, 'array values in the form do not break the page');
[$c] = $mwAdm(['mw_action' => ['x'], 'csrf_token' => 'csrftok1']);
$ok($c !== 500, 'an array action is handled without a 500');
[$c] = $mwAdm(['mw_action' => 'nope']);
$ok($c !== 500, 'an unknown action is refused without a 500');
$a1 = (int) $one("SELECT COUNT(*) FROM logs WHERE log_type = 'Settings' AND log_description LIKE '%MeshCentral settings%'");
$ok($a1 >= 1, 'saves are audited by Core');
$ok(!str_contains(file_get_contents($root . '/js/rmm_mesh_wizard.js'), 'innerHTML') && !str_contains(file_get_contents($root . '/js/rmm_mesh_wizard.js'), 'eval('), 'the script never uses innerHTML or eval');

$mwRestore();
$rmm->admin()->enable($admin); $mwFresh();
