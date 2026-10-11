<?php
/*
 * Fragment of tests/rmm_ui.php (RivetCore 1.0.0-rc.13 UI): the Starter pack page (agent/rmm_starter_pack.php, agent/post/rmm_starter.php, js/rmm_starter.js) and the Starter
 * badge, the risk chip and the "update waiting for review" marker in the script library. Shares that file's variables. The module is ON and the policies, scripts and alerting
 * switches are ON when it starts (the contract of the fragments); everything it installs is uninstalled or retired at the end.
 *
 * Everything goes through the real pages and the real handler over HTTP (forged sessions, CSRF); Core decides (rmm.admin, the safety rules of the pack) and the database is read
 * back to prove what happened. The catalog numbers are compared with Core's own public manifest, not with numbers typed here.
 */

require_once "$root/includes/rmm_starter_ui.php";

$SPAGE = '/agent/rmm_starter_pack.php';
$SHAND = '/agent/post/rmm_starter.php';
$manifest = json_decode((string) file_get_contents($root . '/vendor/rivet/rivet-core/docs/rmm/starter-pack.manifest.json'), true);
$mScripts = $manifest['scripts'];
$mById = array_column($mScripts, null, 'id');
$sGet = fn (string $who, string $qs = '') => web($wb, 'GET', $SPAGE . $qs, $SS[$who]);
$sLoc = fn (string $h) => preg_match('/^Location:\s*(\S+)/mi', $h, $m) === 1 ? (string) preg_replace('~^https?://[^/]+~', '', $m[1]) : '';
$sPost = function (string $who, array $f, bool $csrf = true, ?string $ret = null) use ($wb, $SS, $SHAND) {
    if ($csrf) { $f += ['csrf_token' => 'csrftok1']; }
    if ($ret !== null) { $f['return'] = $ret; }

    return web($wb, 'POST', $SHAND, $SS[$who], $f);
};
/** post, follow the redirect with the same session (the flash and the stored result show up there): [final status, body, location] */
$sPf = function (string $who, array $f, ?string $ret = null) use ($sPost, $sLoc, $wb, $SS) {
    [$c, $b, $h] = $sPost($who, $f, true, $ret);
    $l = $sLoc($h);
    if ($c >= 300 && $c < 400 && $l !== '') { [$c2, $b2] = web($wb, 'GET', $l, $SS[$who]); return [$c2, $b2, $l]; }

    return [$c, $b, $l];
};
$sJson = function (string $who, array $f) use ($wb, $SS, $SHAND) {
    $ch = curl_init($wb . $SHAND);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($f + ['csrf_token' => 'csrftok1']), CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIE => 'PHPSESSID=' . $SS[$who], CURLOPT_HTTPHEADER => ['Accept: application/json'], CURLOPT_TIMEOUT => 60]);
    $raw = (string) curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return [$code, json_decode($raw, true) ?: [], $raw];
};
$sCount = fn (string $t) => (int) $one("SELECT COUNT(*) FROM $t");
$starterState = fn () => $sCount('rmm_scripts_v2') . '/' . $sCount('rmm_script_versions') . '/' . $sCount('rmm_policies');
$flat = fn (string $html) => html_entity_decode(strip_tags($html));
$sRows = fn (string $html) => (int) preg_match_all('/<input class="form-check-input starter-pick" type="checkbox" name="ids\[\]" id="st_pick_(?:win|lin)-/', $html);
$sid = fn (string $item) => (int) $one("SELECT s.script_id FROM rmm_scripts_v2 s JOIN rmm_script_versions v ON v.script_id = s.script_id WHERE v.note LIKE 'starter|rivet-core-starter|" . $esc($item) . "|%' LIMIT 1");
$libScripts0 = $sCount('rmm_scripts_v2');
$libPolicies0 = $sCount('rmm_policies');

// ============================================================ switches and abilities
$ok(is_file($root . '/agent/rmm_starter_pack.php') && is_file($root . '/agent/post/rmm_starter.php') && is_file($root . '/js/rmm_starter.js'), 'the page, its handler and its script exist');
$ok(in_array('/agent/rmm_starter_pack.php', array_column(rivetRmmAutoNav(), 'href'), true) && in_array('/agent/rmm_starter_pack.php', array_column(rivetRmmAutoNav($USER['admin']), 'href'), true), 'menu: the Starter pack entry is there for the administrator while the scripts switch is on');
$ok(!in_array('/agent/rmm_starter_pack.php', array_column(rivetRmmAutoNav($USER['tech']), 'href'), true) && in_array('/agent/rmm_script_library.php', array_column(rivetRmmAutoNav($USER['tech']), 'href'), true), 'menu: a technician sees the Script library but not the Starter pack');
foreach (['tech', 'viewer', 'rebootonly', 'remoteonly', 'moduleonly', 'normo', 'deptb'] as $who) {
    [$c, $body] = $sGet($who);
    $before = $starterState();
    [$jc, $j] = $sJson($who, ['action' => 'install', 'ids' => ['lin-disk-usage']]);
    $ok(in_array($c, [302, 403], true) && !str_contains($body, 'name="ids[]"') && !str_contains($body, 'Install selected'), "$who: no Starter pack page ($c)");
    $ok(in_array($jc, [302, 403, 404], true) && $starterState() === $before && ($j['success'] ?? false) !== true, "$who: the handler refuses and installs nothing ($jc)");
}
[$c, $body] = $sGet('tech');
$ok($c === 403 && str_contains($body, 'Administrator access required'), 'a technician with script rights gets the plain reason, not an empty page');
[$c, $body] = $sGet('admin');
$ok($c === 200 && str_contains($body, 'id="starter-form"') && str_contains($body, 'rmm_starter.js') && str_contains($body, 'itflow_rmm_automation.css'), 'administrator: the page, its form and its script');
$ok(!str_contains($body, 'id="starter-blocker"') && !str_contains($body, 'Nothing can be installed yet'), 'with a signing key there is no blocker');
$web403 = fn (string $b) => str_contains($b, 'switched off');
$setFeat = function (bool $scripts) use ($adm1, $fresh, $rmm) {
    $f = $rmm->settings()->features();
    $post = ['save_automation_settings' => 1];
    foreach (['policies', 'alerting'] as $k) { if (!empty($f[$k])) { $post['feature_' . $k] = '1'; } }
    if ($scripts) { $post['feature_scripts'] = '1'; }
    $adm1($post);
    $fresh();
};
$setFeat(false);
[$c, $body] = $sGet('admin');
$ok($c === 403 && $web403($body) && !in_array('/agent/rmm_starter_pack.php', array_column(rivetRmmAutoNav($USER['admin']), 'href'), true), 'scripts switch off: the page says so and the menu entry is gone');
$before = $starterState();
[$jc, $j] = $sJson('admin', ['action' => 'install', 'ids' => ['lin-disk-usage']]);
$ok($jc === 404 && str_contains((string) ($j['error'] ?? ''), 'switched off') && $starterState() === $before, 'scripts switch off: the handler answers 404 and installs nothing');
$a = $questions();
ob_start();
$ctxOff = @rivetRmmAutoPage($db, 'scripts', 1, 'admin');   // prints the "switched off" notice, which this test does not want on its output
ob_end_clean();
$b = $questions();
$ok($ctxOff === null && $b - $a === $overhead, 'scripts switch off: the page gate asks the database nothing');
$setFeat(true);
$rmm->admin()->disable($admin);
$fresh();
[$c, $body] = $sGet('admin');
$ok($c === 403 && (str_contains($body, 'RMM module off') || str_contains($body, 'switched off')), 'module off: the page says so');
[$jc, $j] = $sJson('admin', ['action' => 'plan', 'ids' => ['lin-disk-usage']]);
$ok($jc === 404 && $starterState() === $before, 'module off: the handler answers 404');
$rmm->admin()->enable($admin);
$fresh();
$ok($rmm->featureOn('scripts'), 'the scripts switch is on again');

// ============================================================ the catalog page
[$c, $body] = $sGet('admin');
$cnt = $manifest['pack']['id'] ?? '';
$risk = array_count_values(array_column($mScripts, 'risk'));
$byPlat = array_count_values(array_column($mScripts, 'platform'));
foreach ([count($mScripts) . ' scripts', $byPlat['windows'] . ' Windows', $byPlat['linux'] . ' Linux', $risk['read_only'] . ' read only', $risk['changes_system'] . ' change the system', $risk['disruptive'] . ' disruptive', count($manifest['policies']) . ' policy drafts', count($manifest['check_sets']) . ' check template sets'] as $chip) {
    $ok(str_contains($body, $chip), "counts: \"$chip\"");
}
$ok($sRows($body) === count($mScripts) && substr_count($body, 'data-risk="policy"') === count($manifest['policies']), 'every script (' . count($mScripts) . ') and every policy draft (' . count($manifest['policies']) . ') has a checkbox');
$ok(substr_count($body, '>Not installed<') >= count($mScripts) + count($manifest['policies']) && str_contains($body, '0 installed'), 'nothing is installed at the start; every item says "Not installed"');
$ok(substr_count($body, 'rmm-risk-chip') >= count($mScripts), 'every script has a risk chip (a word and an icon)');
$ok(str_contains($body, 'Needs approval') && str_contains($body, 'Disruptive') && str_contains($body, 'Changes the system') && str_contains($body, 'Read only'), 'risk words and the approval note are printed');
$ok(str_contains($body, 'id="starter-card-params"') && str_contains($body, 'name="param[backup_service]"') && str_contains($body, 'name="param[ntp_server]"') && str_contains($body, 'name="param[ssh_service]"') && str_contains($body, '<textarea class="form-control font-monospace" id="starter_param_cert_paths" name="param[cert_paths]"'), 'the policy parameter form: backup_service, ntp_server, ssh_service and cert_paths (a list: a textarea)');
$ok(str_contains($body, 'Used by: ') && str_contains($body, 'Starter: Linux Server baseline') && str_contains($body, 'aria-describedby="starter_param_ntp_server_help"'), 'each parameter says what it does and which drafts use it');
foreach (['starter-plan', 'starter-install', 'starter-uninstall'] as $btn) { $ok(str_contains($body, 'id="' . $btn . '"'), "the $btn button"); }
$ok(!str_contains($body, 'id="starter-install" disabled') && str_contains($body, 'data-rmm-confirm="Install the selected items?'), 'Install asks first and is enabled with a signing key');
$ok(str_contains($body, 'Check template sets') && str_contains($body, 'Workstation baseline (Windows)') && str_contains($body, 'type="hidden" name="csrf_token" value="csrftok1"'), 'the check template sets are listed and the form carries the CSRF token');
$ok(str_contains($body, '<caption class="visually-hidden">Starter pack scripts</caption>') && str_contains($body, 'aria-label="Select every script shown"') && str_contains($body, 'aria-label="Select ' . $mScripts[0]['name'] . '"'), 'accessible: table caption, labelled select-all and row checkboxes');
$ok(!preg_match('/\son(click|change|submit|load|input|focus|blur)=/i', $body), 'no inline event handlers (CSP)');
$ok(!str_contains($body, 'echo "') && !str_contains($body, 'Get-CimInstance') && !str_contains($body, '#!/bin/bash'), 'the catalog page never prints a script\'s text');

// filters
$expect = fn (callable $f) => count(array_filter($mScripts, $f));
[$c, $b] = $sGet('admin', '?platform=linux');
$ok($sRows($b) === $byPlat['linux'] && str_contains($b, $byPlat['linux'] . ' of ' . count($mScripts) . ' shown'), 'filter platform=linux: ' . $byPlat['linux'] . ' rows');
[$c, $b] = $sGet('admin', '?risk[]=disruptive');
$ok($sRows($b) === $risk['disruptive'], 'filter risk=disruptive: ' . $risk['disruptive'] . ' rows');
[$c, $b] = $sGet('admin', '?risk[]=disruptive&risk[]=changes_system&platform=windows');
$n = $expect(fn ($s) => $s['platform'] === 'windows' && in_array($s['risk'], ['disruptive', 'changes_system'], true));
$ok($sRows($b) === $n, "filter platform + two risks: $n rows");
[$c, $b] = $sGet('admin', '?tag=network');
$n = $expect(fn ($s) => in_array('network', $s['tags'], true));
$ok($sRows($b) === $n && $n > 0 && str_contains($b, '<option value="network" selected>'), "filter tag=network: $n rows, the tag selected");
[$c, $b] = $sGet('admin', '?q=defender');
$n = $expect(fn ($s) => str_contains(mb_strtolower($s['name'] . ' ' . $s['description']), 'defender'));
$ok($sRows($b) === $n && $n > 0, "search 'defender': $n rows");
[$c, $b] = $sGet('admin', '?q=zzzzzzzz');
$ok($c === 200 && $sRows($b) === 0 && str_contains($b, 'No script matches'), 'a search with no match shows an empty state');
[$c, $b] = $sGet('admin', '?platform=plan9&risk[]=bogus&tag=' . urlencode('<script>alert(1)</script>') . '&q=' . urlencode('"><img src=x onerror=1>'));
$ok($c === 200 && !str_contains($b, '<script>alert(1)') && !str_contains($b, '<img src=x') && $sRows($b) === 0, 'hostile filter values are escaped and match nothing');
[$c, $b] = $sGet('admin', '?platform=bogus&risk[]=bogus');
$ok($c === 200 && $sRows($b) === count($mScripts), 'unknown platform and risk values are ignored (everything is shown)');
$ok(!str_contains($b, '<option value="starter"') && !str_contains($b, '<option value="risk:'), 'the tag list leaves out the pack\'s own starter and risk tags');

// ============================================================ plan writes nothing, install writes what the plan said
$before = $starterState();
[$c, $body, $loc] = $sPf('admin', ['action' => 'plan', 'ids' => ['win-flush-dns', 'lin-disk-usage', 'policy-linux-server']], $SPAGE . '?platform=linux');
$ok($c === 200 && str_starts_with($loc, $SPAGE . '?platform=linux') && str_ends_with($loc, '#starter-result'), 'plan: back to the page with the same filter, at the result (' . $loc . ')');
$ok($starterState() === $before, 'plan: nothing was written (scripts/versions/policies ' . $before . ')');
$flatBody = $flat($body);
$ok(str_contains($body, 'id="starter-result"') && str_contains($flatBody, 'Plan: nothing was changed') && substr_count($body, 'badge text-bg-success') >= 3, 'plan: a result card with three "Add" lines');
$ok(str_contains($flatBody, 'Flush DNS') && str_contains($flatBody, 'Disk usage by filesystem (Linux)') && str_contains($flatBody, 'Starter: Linux Server baseline') && str_contains($flatBody, 'Not installed') && str_contains($flatBody, 'Planned 3 items. Nothing was changed.'), 'plan: each item with Core\'s reason, and the flash');
$ok(substr_count($body, 'checked') >= 3 && preg_match('/id="st_pick_lin-disk-usage"[^>]*checked/', $body) === 1, 'plan: the same items are still ticked, ready to install');
[$c, $body2] = $sGet('admin');
$ok(!str_contains($body2, 'id="starter-result"'), 'the result is shown once');
// refusals
$before = $starterState();
[$c, $b] = $sPf('admin', ['action' => 'plan']);
$ok(str_contains($flat($b), 'Tick at least one item first') && $starterState() === $before, 'nothing ticked: refused with the reason (an empty selection would mean the whole pack to Core)');
[$jc, $j] = $sJson('admin', ['action' => 'install', 'ids' => ['../../etc/passwd', 'UPPER', str_repeat('a', 80)]]);
$ok($jc === 422 && str_contains((string) ($j['error'] ?? ''), 'Tick at least one item') && $starterState() === $before, 'hostile ids are dropped by the handler, so the selection is empty and refused');
[$jc, $j] = $sJson('admin', ['action' => 'install', 'ids' => ['no-such-item']]);
$ok($jc === 422 && str_contains((string) ($j['error'] ?? ''), 'Unknown starter item no-such-item') && $starterState() === $before, 'an unknown item is Core\'s refusal, shown as it is');
[$jc, $j] = $sJson('admin', ['action' => 'bogus', 'ids' => ['lin-disk-usage']]);
$ok($jc === 422 && $starterState() === $before, 'an unknown action is refused');
[$c, $b, $h] = $sPost('admin', ['action' => 'install', 'ids' => ['lin-disk-usage']], false);
$ok($starterState() === $before && !str_contains($h, 'rmm_starter_pack'), 'no CSRF token: nothing happens');
[$c, $b] = web($wb, 'GET', $SHAND, $SS['admin']);
$ok($c === 405 && $starterState() === $before, 'GET on the handler: 405');
// install, with policy parameters
$q("UPDATE webhooks SET webhook_enabled=1");
$q("DELETE FROM integration_jobs WHERE job_type='webhook.deliver'");
$sel = ['lin-disk-usage', 'lin-top-processes', 'win-flush-dns', 'win-restart-service', 'policy-linux-server'];
[$c, $body, $loc] = $sPf('admin', ['action' => 'install', 'ids' => $sel, 'param' => ['ntp_server' => 'time.example.test', 'ssh_service' => 'ssh', 'cert_paths' => "/etc/ssl/a.pem\n  /etc/ssl/b.pem \n\n", 'backup_service' => 'veeam-agent', 'uptime_warn_days' => '20']], $SPAGE);
$flatBody = $flat($body);
$ok($c === 200 && str_contains($flatBody, 'Install result') && str_contains($flatBody, 'Install finished for 5 items.') && substr_count($body, 'Added') >= 5, 'install: a result card with five "Added" lines');
$ok($sCount('rmm_scripts_v2') === $libScripts0 + 4 && $sCount('rmm_policies') === $libPolicies0 + 1, 'install: four scripts and one policy draft exist');
foreach (['lin-disk-usage', 'lin-top-processes', 'win-flush-dns', 'win-restart-service'] as $item) {
    $scId = $sid($item);
    $row = $db->query("SELECT s.name, s.language, s.requires_approval, s.destructive, s.tags_json, s.current_version, v.signature, v.note FROM rmm_scripts_v2 s JOIN rmm_script_versions v ON v.script_id = s.script_id AND v.version = s.current_version WHERE s.script_id = $scId")->fetch_assoc();
    $m = $mById[$item];
    $tags = json_decode((string) $row['tags_json'], true);
    $ok($scId > 0 && $row['name'] === $m['name'] && in_array('starter', $tags, true) && in_array('risk:' . $m['risk'], $tags, true) && $row['signature'] !== '' && (int) $row['requires_approval'] === (int) $m['requires_approval'] && (int) $row['destructive'] === (int) $m['destructive']
        && str_starts_with($row['note'], 'starter|rivet-core-starter|' . $item . '|'), "installed $item: the pack's name, risk tag, approval flags, a signature and the marker note");
}
$ok((int) $one("SELECT requires_approval FROM rmm_scripts_v2 WHERE script_id=" . $sid('win-restart-service')) === 1 && (int) $one("SELECT destructive FROM rmm_scripts_v2 WHERE script_id=" . $sid('win-restart-service')) === 1, 'a disruptive script needs approval and is destructive');
$pol = $db->query("SELECT * FROM rmm_policies WHERE name='Starter: Linux Server baseline'")->fetch_assoc();
$assign = (int) $one("SELECT COUNT(*) FROM rmm_policy_assignments WHERE policy_id=" . (int) $pol['policy_id']);
$ok($pol !== null && (int) $pol['enabled'] === 0 && $assign === 0 && str_ends_with($pol['description'], ']') && str_contains($pol['description'], '[starter|rivet-core-starter|policy-linux-server|'), 'the policy draft is switched off, assigned to nothing and carries the marker');
$pj = (string) $pol['body_json'];
$ok(str_contains($pj, 'time.example.test') && str_contains($pj, '/etc/ssl/a.pem') && str_contains($pj, '/etc/ssl/b.pem') && str_contains($pj, 'veeam-agent') && str_contains($pj, '"ssh"'), 'the policy parameters from the form reached the draft (time server, SSH unit, certificate paths, backup service)');
$ok(!str_contains($pj, '  /etc/ssl') && !str_contains($pj, 'b.pem '), 'certificate paths were trimmed line by line');
$stEv = array_values(array_filter($deliveries(), fn ($p) => $p['event'] === 'rmm.starter.installed'));
$ok($stEv !== [] && ($stEv[0]['data']['action'] ?? '') === 'install' && ($stEv[0]['data']['added'] ?? 0) === 5 && ($stEv[0]['data']['user_id'] ?? 0) === 1 && (int) ($stEv[0]['device_id'] ?? 0) === 0, 'rmm.starter.installed is published once for the install (added 5, by user 1)');
$ok((int) $one("SELECT COUNT(*) FROM logs WHERE log_action LIKE 'Starter Pack%'") >= 6 && !str_contains((string) $one("SELECT GROUP_CONCAT(log_description) FROM logs WHERE log_action LIKE 'Starter Pack%'"), 'Get-CimInstance'), 'audited per item and as a whole, with no script text in the log');
$nEv = count($stEv);
// idempotent
$before = $starterState();
[$c, $b] = $sPf('admin', ['action' => 'install', 'ids' => $sel], $SPAGE);
$ok($starterState() === $before && substr_count($b, 'Already installed') >= 5 && !str_contains($flat($b), 'Added'), 'install again: five "Already installed", nothing written');
$ok(count(array_filter($deliveries(), fn ($p) => $p['event'] === 'rmm.starter.installed')) === $nEv, 'install again: no second event');
[$c, $b] = $sGet('admin');
$ok(str_contains($b, '5 installed') && substr_count($b, '>Installed<') >= 5 && preg_match('/href="\/agent\/rmm_script_library\.php\?script_id=' . $sid('lin-disk-usage') . '"/', $b) === 1 && str_contains($b, 'rmm_policies.php?policy_id=' . (int) $pol['policy_id']), 'the catalog now shows five installed, with links to the script and the policy');

// ============================================================ the library: badge, chip, plain tags, notes in words
[$c, $lib] = web($wb, 'GET', '/agent/rmm_script_library.php?tag=starter', $SS['admin']);
$ok($c === 200 && substr_count($lib, 'rmm-starter-badge') === 4 && str_contains($lib, 'id="scr-starter-link"'), 'script library, tag=starter: four rows with the Starter badge, and the Starter pack link for the administrator');
$ok(str_contains($lib, 'Read only') && str_contains($lib, 'Disruptive') && str_contains($lib, 'Changes the system') && substr_count($lib, 'rmm-risk-chip') === 4, 'script library: the risk chips come from the risk:* tags');
$ok(!str_contains($lib, '>risk:read_only<') && !str_contains($lib, '>starter<'), 'script library: the pack\'s own tags are not repeated as plain tags');
[$c, $lib] = web($wb, 'GET', '/agent/rmm_script_library.php', $SS['admin']);
$ok(substr_count($lib, 'rmm-starter-badge') === 4 && str_contains($lib, 'Say hello') && !preg_match('~Say hello.{0,400}rmm-starter-badge~s', $lib), 'script library: a script of the administrator\'s own has no badge');
[$c, $lib] = web($wb, 'GET', '/agent/rmm_script_library.php', $SS['tech']);
$ok($c === 200 && substr_count($lib, 'rmm-starter-badge') === 4 && !str_contains($lib, 'id="scr-starter-link"') && !str_contains($lib, 'Update waiting for review'), 'script library, technician: the badge, no link to the Starter pack page');
$dId = $sid('lin-disk-usage');
[$c, $page] = web($wb, 'GET', '/agent/rmm_script_library.php?script_id=' . $dId, $SS['admin']);
$ok(str_contains($page, 'rmm-starter-badge') && str_contains($page, 'Installed from the starter pack (rivet-core-starter 1.0.0)') && !str_contains($page, 'starter|rivet-core-starter|lin-disk-usage|'), 'script page: the Starter badge, and the version note in words instead of the marker');

// ============================================================ admin edits are protected; the pack's newer text is parked for review
$edited = "#!/bin/bash\n# edited by the administrator\ndf -h\nexit 0\n";
[$c, $b] = $sPf('admin', ['action' => 'plan', 'ids' => ['lin-disk-usage']], $SPAGE);
$ok(substr_count($b, 'Already installed') >= 1, 'before the edit the plan says "Already installed"');
$hpLib = fn (array $f, string $ret) => web($wb, 'POST', '/agent/post/rmm_automation_scr.php', $SS['admin'], $f + ['csrf_token' => 'csrftok1', 'return' => $ret]);
[$c] = $hpLib(['action' => 'save_script', 'script_id' => $dId, 'name' => 'Disk usage by filesystem (Linux)', 'description' => 'edited', 'body' => $edited, 'note' => 'my change', 'tags' => 'starter, risk:read_only, disk', 'timeout_s' => '30'], '/agent/rmm_script_library.php?script_id=' . $dId);
$ok((int) $one("SELECT current_version FROM rmm_scripts_v2 WHERE script_id=$dId") === 2 && (string) $one("SELECT body FROM rmm_script_versions WHERE script_id=$dId AND version=2") === $edited, 'the administrator saved version 2 of the installed script');
[$c, $b] = $sGet('admin');
$ok(str_contains($b, '>Edited by you<') && str_contains($b, 'the pack will not overwrite it'), 'the catalog shows the script as edited by you');
[$c, $b] = $sPf('admin', ['action' => 'plan', 'ids' => ['lin-disk-usage']], $SPAGE);
$ok(str_contains($flat($b), 'Park for review') && str_contains($flat($b), 'the script keeps running what it ran'), 'the plan says the pack\'s text would be parked, not applied');
$vcount = $sCount('rmm_script_versions');
[$c, $b] = $sPf('admin', ['action' => 'install', 'ids' => ['lin-disk-usage']], $SPAGE);
$ok(str_contains($flat($b), 'Parked for review') && $sCount('rmm_script_versions') === $vcount + 1 && (int) $one("SELECT current_version FROM rmm_scripts_v2 WHERE script_id=$dId") === 2 && (string) $one("SELECT body FROM rmm_script_versions WHERE script_id=$dId AND version=2") === $edited, 'install: the pack\'s text is a further version; the edited version 2 is still current and untouched');
$ok(str_starts_with((string) $one("SELECT note FROM rmm_script_versions WHERE script_id=$dId AND version=3"), 'starter-updated|rivet-core-starter|lin-disk-usage|'), 'version 3 carries the starter-updated note');
[$c, $b] = $sGet('admin');
$ok(str_contains($b, '>Update waiting for review<') && str_contains($b, '1 update waiting for review') && str_contains($b, 'Review the update'), 'the catalog marks the update waiting for review and links to it');
[$c, $b] = $sPf('admin', ['action' => 'install', 'ids' => ['lin-disk-usage']], $SPAGE);
$ok(str_contains($flat($b), 'already parked as version 3') && $sCount('rmm_script_versions') === $vcount + 1, 'installing again does not park a second copy');
// the library shows the marker in the list and on the script page, with the link to the differences
[$c, $lib] = web($wb, 'GET', '/agent/rmm_script_library.php', $SS['admin']);
$ok(substr_count($lib, 'rmm-starter-waiting') === 1 && str_contains($lib, 'script_id=' . $dId . '#scr-versions') && str_contains($lib, 'Update waiting for review'), 'script library: the "update waiting for review" marker on that one script');
[$c, $lib] = web($wb, 'GET', '/agent/rmm_script_library.php', $SS['tech']);
$ok(!str_contains($lib, 'Update waiting for review'), 'script library: a technician does not get the marker (administrators act on it)');
[$c, $page] = web($wb, 'GET', '/agent/rmm_script_library.php?script_id=' . $dId, $SS['admin']);
$ok(str_contains($page, 'id="scr-starter-waiting"') && str_contains($page, 'The starter pack has an update for this script, waiting for your review') && str_contains($page, 'from=2&amp;to=3#scr-diff') && substr_count($page, 'Update waiting for review') >= 1 && str_contains($page, 'Compare with the current version'), 'script page: the notice, the marker on version 3 and the link to the differences');
[$c, $page] = web($wb, 'GET', '/agent/rmm_script_library.php?script_id=' . $dId . '&from=2&to=3', $SS['admin']);
$ok(str_contains($page, 'id="scr-diff"') && str_contains($page, 'edited by the administrator') && str_contains($page, 'Differences: version 2 to version 3'), 'the differences page compares the administrator\'s text with the pack\'s update');
// retire is respected
$rid = $sid('lin-top-processes');
$hpLib(['action' => 'retire_script', 'script_id' => $rid], '/agent/rmm_script_library.php');
[$c, $b] = $sPf('admin', ['action' => 'plan', 'ids' => ['lin-top-processes']], $SPAGE);
$ok(str_contains($flat($b), 'Skip') && str_contains($flat($b), 'retired this script; it stays retired'), 'a script an administrator retired is skipped by the plan');
$hpLib(['action' => 'restore_script', 'script_id' => $rid], '/agent/rmm_script_library.php');

// ============================================================ policy drafts: an edited, switched-on or assigned draft is left alone
$polId = (int) $pol['policy_id'];
$db->query("UPDATE rmm_policies SET enabled=1 WHERE policy_id=$polId");
[$c, $b] = $sPf('admin', ['action' => 'install', 'ids' => ['policy-linux-server'], 'param' => ['ntp_server' => 'other.example.test']], $SPAGE);
$ok(str_contains($flat($b), 'switched on or assigned') && !str_contains((string) $one("SELECT body_json FROM rmm_policies WHERE policy_id=$polId"), 'other.example.test'), 'a policy draft that is switched on is not changed by new parameters');
$db->query("UPDATE rmm_policies SET enabled=0 WHERE policy_id=$polId");
[$c, $b] = $sPf('admin', ['action' => 'install', 'ids' => ['policy-linux-server'], 'param' => ['ntp_server' => 'other.example.test', 'ssh_service' => 'ssh', 'cert_paths' => "/etc/ssl/a.pem\n/etc/ssl/b.pem", 'backup_service' => 'veeam-agent', 'uptime_warn_days' => '20']], $SPAGE);
$ok(str_contains($flat($b), 'Updated') && str_contains((string) $one("SELECT body_json FROM rmm_policies WHERE policy_id=$polId"), 'other.example.test'), 'an untouched, switched-off draft takes new parameters');
[$c, $b] = $sPf('admin', ['action' => 'install', 'ids' => ['policy-workstation'], 'param' => ['uptime_warn_days' => 'many']], $SPAGE);
$ok(str_contains($flat($b), 'Blocked') && str_contains($flat($b), 'uptime_warn_days'), 'a bad parameter value is Core\'s message: Blocked, naming the parameter');
$ok((int) $one("SELECT COUNT(*) FROM rmm_policies WHERE name='Starter: Workstation baseline'") === 0, 'a bad parameter creates no draft');

// ============================================================ uninstall: preview first, then only the untouched items
$before = $starterState();
[$c, $b] = $sPf('admin', ['action' => 'uninstall_preview', 'ids' => ['lin-disk-usage', 'lin-top-processes', 'win-flush-dns', 'win-restart-service', 'policy-linux-server']], $SPAGE);
$fb = $flat($b);
$ok($starterState() === $before && str_contains($fb, 'Uninstall preview: nothing was changed') && substr_count($fb, 'Would be retired') === 3 && str_contains($fb, 'Would be deleted') && str_contains($fb, 'An administrator changed this script, so it was left alone'), 'preview: three scripts would be retired, the draft deleted, the edited script left alone; nothing written');
$ok(str_contains($b, 'id="starter-uninstall-go"') && str_contains($b, 'name="action" value="uninstall"') && substr_count($b, 'name="ids[]" value="') === 5, 'the preview carries a confirm form with the same five ids');
[$c, $b] = $sPf('admin', ['action' => 'uninstall', 'ids' => ['lin-disk-usage', 'lin-top-processes', 'win-flush-dns', 'win-restart-service', 'policy-linux-server']], $SPAGE);
$fb = $flat($b);
$ok(str_contains($fb, 'Uninstall result') && substr_count($fb, 'Retired') >= 3 && str_contains($fb, 'Deleted'), 'uninstall: the result says retired and deleted');
$ok((int) $one("SELECT COUNT(*) FROM rmm_scripts_v2 WHERE retired_at IS NOT NULL AND script_id IN (" . $sid('lin-top-processes') . ',' . $sid('win-flush-dns') . ',' . $sid('win-restart-service') . ")") === 3 && (int) $one("SELECT retired_at IS NULL FROM rmm_scripts_v2 WHERE script_id=$dId") === 1
    && (int) $one("SELECT COUNT(*) FROM rmm_policies WHERE policy_id=$polId") === 0, 'only the untouched scripts were retired; the edited one is still active; the untouched draft is gone');
$ok($sCount('rmm_script_versions') >= $vcount, 'uninstall keeps the history');
$evU = array_values(array_filter($deliveries(), fn ($p) => $p['event'] === 'rmm.starter.installed' && ($p['data']['action'] ?? '') === 'uninstall'));
$ok(count($evU) >= 1 && ($evU[0]['data']['removed'] ?? 0) === 4, 'rmm.starter.installed is published for the uninstall too (removed 4)');
[$c, $b] = $sGet('admin');
$ok(substr_count($b, '>Retired<') >= 3, 'the catalog shows the retired scripts');
$q("UPDATE webhooks SET webhook_enabled=0");
$q("DELETE FROM integration_jobs WHERE job_type='webhook.deliver'");

// ============================================================ no signing key: shown first, nothing can be installed
$keys = $db->query('SELECT signing_private_key_enc, signing_public_key FROM endpoint_agent_settings WHERE id=1')->fetch_assoc();
$q("UPDATE endpoint_agent_settings SET signing_private_key_enc=NULL, signing_public_key='' WHERE id=1");
$fresh();
[$c, $b] = $sGet('admin');
$ok($c === 200 && str_contains($b, 'id="starter-blocker"') && str_contains($b, 'Nothing can be installed yet') && str_contains($b, 'The instance signing key is not available') && str_contains($b, '/admin/settings_endpoint_agent.php#signing') && preg_match('/id="starter-install"[^>]*disabled/', $b) === 1, 'no signing key: the blocker is the first thing shown, with the link, and Install is disabled');
$ok(strpos($b, 'id="starter-blocker"') < strpos($b, 'id="starter-form"'), 'the blocker comes before the catalog');
$before = $starterState();
[$jc, $j] = $sJson('admin', ['action' => 'install', 'ids' => ['win-flush-dns']]);
$ok($starterState() === $before, 'no signing key: even a forged Install installs nothing (' . $jc . ')');
[$c, $b] = $sPf('admin', ['action' => 'plan', 'ids' => ['win-flush-dns']], $SPAGE);
$ok(str_contains($flat($b), 'Blocked') && str_contains($flat($b), 'The instance signing key is not available'), 'no signing key: a plan can still be made and says Blocked with the reason');
$q("UPDATE endpoint_agent_settings SET signing_private_key_enc=" . ($keys['signing_private_key_enc'] === null ? 'NULL' : "'" . $esc($keys['signing_private_key_enc']) . "'") . ", signing_public_key='" . $esc($keys['signing_public_key']) . "' WHERE id=1");
$fresh();
[$c, $b] = $sGet('admin');
$ok(!str_contains($b, 'id="starter-blocker"'), 'the signing key is back: no blocker');

// ============================================================ hostile parameter values are escaped when the form is shown again
[$c, $b] = $sPf('admin', ['action' => 'plan', 'ids' => ['policy-linux-server'], 'param' => ['ntp_server' => '"><script>alert(7)</script>', 'cert_paths' => "<img src=x onerror=1>\n/etc/ok.pem"]], $SPAGE);
$ok(!str_contains($b, '<script>alert(7)') && !str_contains($b, '<img src=x') && (str_contains($b, '&lt;script&gt;alert(7)') || str_contains($b, '&quot;&gt;&lt;script')), 'a hostile parameter value is escaped wherever it is shown again');

// ---- clean up what this fragment made, so the other fragments and the next run see the library as it was
$db->query("UPDATE rmm_scripts_v2 SET retired_at=NULL WHERE script_id=$dId");
$ok($rmm->enabled() && $rmm->featureOn('scripts'), 'the module and the scripts switch are on at the end of the starter fragment');
