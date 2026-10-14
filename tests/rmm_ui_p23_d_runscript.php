<?php
/*
 * The "Run script" dialog of the asset page as the ONE place to run anything, and the single Scripts area (fragment of tests/rmm_ui.php).
 * Shares that file's variables ($ok $q $one $db $root $S $D $A $USER $SS $wb $rmm $admin $questions $overhead $fresh). The policies, scripts and alerting switches are ON
 * when it starts and the module is ON when it ends. Scripts are created through the technician API as the administrator and removed again at the end.
 *
 *   - 'What to run': Script library (N) first, a saved script (legacy) second, free-form text third (PowerShell on Windows, shell on Linux); never a lone useless option
 *   - the library list is grouped by risk class and holds only the scripts of the device's platform; the chosen script's parameter form is typed, dry run defaults ON
 *   - the form posts to the SAME handler as the Jobs-tab card (agent/post/rmm_automation_scr.php): jobs decode to library_script_id and params; approvals queue; destructive confirms
 *   - restricted viewers, switch off, department scoping
 *   - one Scripts menu entry, the Library and "Synced from ..." tabs, the old URLs, and the idempotent import of the legacy list
 */

require_once "$root/includes/rmm_scr_ui.php";

$RSW = $D['WIN1'];
$RSL = $D['LNX1'];
$rsApi = fn (string $m, array $seg, array $qs = [], ?array $body = null) => rivetRmmAutoApi($db, 1, 'admin', $m, $seg, $qs, $body);
$rsGet = fn (string $who, string $path) => web($wb, 'GET', $path, $SS[$who]);
$rsLoc = fn (string $h) => preg_match('/^Location:\s*(\S+)/mi', $h, $m) === 1 ? (string) preg_replace('~^https?://[^/]+~', '', $m[1]) : '';
$rsPost = function (string $who, array $f, string $path = '/agent/post/rmm_automation_scr.php', bool $csrf = true) use ($wb, $SS, $rsLoc) {
    if ($csrf) { $f += ['csrf_token' => 'csrftok1']; }
    if (($f['action'] ?? '') === 'run_script' && isset($f['device_id']) && !isset($f['return'])) {   // the form's hidden return field: the asset page's Jobs tab
        $f['return'] = '/agent/asset_details.php?asset_id=' . ((int) $f['device_id'] === $GLOBALS['RSL'] ? $GLOBALS['A']['LNX1'] : $GLOBALS['A']['WIN1']) . '#rmm-jobs';
    }
    [$c, $b, $h] = web($wb, 'POST', $path, $SS[$who], $f);
    $l = $rsLoc($h);
    if ($c >= 300 && $c < 400 && $l !== '') { [$c2, $b2] = web($wb, 'GET', $l, $SS[$who]); return [$c2, $b2, $l]; }

    return [$c, $b, $l];
};
$rsCreate = function (array $b) use ($rsApi, $ok): int {
    $r = $rsApi('POST', ['scripts'], [], $b + ['description' => '', 'note' => 'run-script test', 'tags' => [], 'requires_approval' => false, 'destructive' => false, 'params' => []]);
    if (!$r['ok']) { fwrite(STDERR, 'could not create ' . $b['name'] . ': ' . $r['error'] . "\n"); }

    return (int) ($r['data']['script']['script_id'] ?? 0);
};
$rsJob = fn (int $sid) => $db->query("SELECT j.*, x.script_id, x.script_version FROM endpoint_agent_jobs j JOIN rmm_job_extra x ON x.job_id=j.job_id WHERE x.script_id=$sid ORDER BY j.created_at DESC, j.job_id DESC LIMIT 1")->fetch_assoc();
$rsJobs = fn (int $sid) => (int) $one("SELECT COUNT(*) FROM rmm_job_extra WHERE script_id=$sid");
$rsFeature = function (bool $on) use ($rmm, $admin, $fresh): void { $f = $rmm->settings()->features(); $f['scripts'] = $on; $rmm->admin()->saveSettings($admin, ['features_json' => $f]); $fresh(); rivetRmmForgetAccess(); };
$rsNames = fn (array $vm) => array_column($vm['library']['scripts'], 'name');
$SECRET = 'RS-S3cr3t-77aa';

// ---------------------------------------------------------------- fixtures: one script of every kind
$idRead = $rsCreate(['name' => 'RS Disk report (Windows)', 'language' => 'powershell', 'body' => 'Write-Output "report"', 'description' => 'Lists the biggest folders.', 'tags' => ['risk:read_only', 'disk'],
    'params' => [['name' => 'top_n', 'type' => 'int', 'label' => 'How many folders', 'default' => 5, 'min' => 1, 'max' => 20, 'required' => true, 'description' => 'Folders to list.'],
        ['name' => 'folder', 'type' => 'string', 'label' => 'Start folder', 'max_length' => 120, 'default' => 'C:\\'], ['name' => 'api_token', 'type' => 'secret', 'label' => 'API token'],
        ['name' => 'format', 'type' => 'choice', 'choices' => ['table', 'csv'], 'default' => 'table']]]);
$idChange = $rsCreate(['name' => 'RS Clean temp (Windows)', 'language' => 'powershell', 'body' => 'if ($dry_run) { "would" } else { "did" }', 'tags' => ['risk:changes_system', 'cleanup'],
    'params' => [['name' => 'dry_run', 'type' => 'bool', 'default' => true, 'description' => 'Report only.'], ['name' => 'older_days', 'type' => 'int', 'default' => 30, 'min' => 1]]]);
$idDisr = $rsCreate(['name' => 'RS Reset stack (Windows)', 'language' => 'powershell', 'body' => 'Restart-Service x', 'tags' => ['risk:disruptive'], 'destructive' => true, 'requires_approval' => true,
    'params' => [['name' => 'dry_run', 'type' => 'bool', 'default' => true]]]);
$idAppr = $rsCreate(['name' => 'RS Needs sign-off (Windows)', 'language' => 'powershell', 'body' => 'Get-Date', 'tags' => ['risk:changes_system'], 'requires_approval' => true, 'params' => [['name' => 'who', 'type' => 'string']]]);
$idDestr = $rsCreate(['name' => 'RS Wipe cache (Windows)', 'language' => 'powershell', 'body' => 'Remove-Item x', 'tags' => ['risk:changes_system'], 'destructive' => true]);
$idOwn = $rsCreate(['name' => 'RS Own script (Windows)', 'language' => 'powershell', 'body' => 'Get-Date']);
$idLin = $rsCreate(['name' => 'RS Uptime (Linux)', 'language' => 'bash', 'body' => 'uptime', 'tags' => ['risk:read_only']]);
$winIds = [$idRead, $idChange, $idDisr, $idAppr, $idDestr, $idOwn];
$ok(!in_array(0, array_merge($winIds, [$idLin]), true), 'fixtures: seven library scripts (six Windows, one Linux) were created through the API');
$tok = 'tok-rs';

// ============================================================ the view-model
$vmW = $panel('WIN1');
$ok($vmW['library']['on'] === true && $vmW['library']['error'] === '', 'WIN1 (admin): the library is on and was read');
$wn = $rsNames($vmW);
$ok(in_array('RS Disk report (Windows)', $wn, true) && in_array('RS Own script (Windows)', $wn, true) && !in_array('RS Uptime (Linux)', $wn, true), 'WIN1: only the library scripts that run on Windows');
$vmL = $panel('LNX1');
$ln = $rsNames($vmL);
$ok(in_array('RS Uptime (Linux)', $ln, true) && count(array_filter($ln, fn ($n) => str_contains($n, '(Windows)'))) === 0, 'LNX1: only the scripts that run on Linux');
$ro = array_column($vmW['library']['scripts'], null, 'name')['RS Disk report (Windows)'];
$ok($ro['risk'] === 'read_only' && $ro['tags'] === ['disk'] && $ro['description'] === 'Lists the biggest folders.' && count($ro['params']) === 4 && $ro['requires_approval'] === false, 'a script carries its risk class (from the risk: tag), plain tags, description and parameters');

// ============================================================ the dialog markup, Windows, administrator
$dlg = rivetRmmUiDialogs($vmW, $tok);
$ok(str_contains($dlg, 'id="rmmRunModal"') && str_contains($dlg, 'Run a script on WIN1'), 'dialog (WIN1): rendered with the host name');
$n = count($vmW['library']['scripts']);
$ok(str_contains($dlg, '<option value="library">Script library (' . $n . ')</option>'), "dialog: first choice is \"Script library ($n)\"");
$pos = fn (string $needle) => strpos($dlg, $needle);
$ok($pos('value="library"') < $pos('value="saved"') && $pos('value="saved"') < $pos('value="text"') && str_contains($dlg, '>A saved script</option>'), 'dialog: order is library, a saved script, then text');
$ok(str_contains($dlg, '>PowerShell (type or paste)</option>') && !str_contains($dlg, 'PowerShell text'), 'dialog: the free-form wording is "PowerShell (type or paste)", not "PowerShell text"');
$ok(str_contains($dlg, '<label class="form-label" for="rmm-run-type">What to run</label>') && str_contains($dlg, 'for="rmm-run-lib-q">Find a script') && str_contains($dlg, 'for="rmm-run-lib-script">Script') && str_contains($dlg, 'id="rmm-run-lib-count" role="status" aria-live="polite"') && str_contains($dlg, 'id="rmm-run-lib-detail" aria-live="polite"'), 'dialog: labelled controls and aria-live regions for the dynamic parts');
$g1 = $pos('label="Read only'); $g2 = $pos('label="Changes the system"'); $g3 = $pos('label="Disruptive"'); $g4 = $pos('label="Your own scripts');
$ok($g1 !== false && $g1 < $g2 && $g2 < $g3 && $g3 < $g4, 'dialog: the list is grouped by risk class in the order read only, changes the system, disruptive, then scripts with no class');
$sel = substr($dlg, (int) $pos('<select class="form-select" id="rmm-run-lib-script"'), 4000);
$ok(preg_match('/label="Read only[^"]*"[^>]*><option value="' . $idRead . '"[^>]*selected>RS Disk report \(Windows\) \(PowerShell, v1\)</', $sel) === 1 && str_contains($sel, 'data-search="rs disk report (windows) lists the biggest folders. disk"'), 'dialog: the first script is preselected; the search text holds name, description and tags');
$ok(!str_contains($dlg, 'RS Uptime (Linux)'), 'dialog (Windows): a Linux script is never offered');
$blk = fn (int $id) => preg_match('~<div class="rmm-run-lib-block" data-script-id="' . $id . '"[^>]*>(.*?)(?=<div class="rmm-run-lib-block"|</div><div class="col-md-4">)~s', $dlg, $m) === 1 ? $m[1] : '';
$b1 = $blk($idRead);
$ok(str_contains($b1, 'rmm-risk-chip') && str_contains($b1, 'Read only') && str_contains($b1, 'Lists the biggest folders.') && str_contains($b1, '>disk<'), 'block: risk badge, description and tags');
$ok(preg_match('/<input class="form-control" type="number" step="1" name="param\[top_n\]" id="rmm_rl' . $idRead . '_top_n"[^>]*min="1" max="20" required/', $b1) === 1 && str_contains($b1, 'Whole number, 1 to 20') && str_contains($b1, 'Default: 5') && str_contains($b1, '(required)'), 'block: a required int has number type, min/max, the hint and the required mark');
$ok(str_contains($b1, 'type="password" autocomplete="new-password" name="param[api_token]"') && !str_contains($b1, $SECRET) && str_contains($b1, 'never stored in the job'), 'block: a secret is a password field with its note');
$ok(str_contains($b1, '<select class="form-select" name="param[format]"') && str_contains($b1, '<option value="csv">csv</option>') && str_contains($b1, 'maxlength="120"'), 'block: choice and string fields are typed and bounded');
$b2 = $blk($idChange);
$ok(preg_match('/<input disabled type="hidden" name="param\[dry_run\]" value="false"><input disabled class="form-check-input" type="checkbox" value="true" name="param\[dry_run\]" id="rmm_rl' . $idChange . '_dry_run"[^>]* checked>/', $b2) === 1
    && str_contains($b2, '>Dry run (nothing is changed)</label>') && str_contains($b2, 'Changes the system'), 'block (changing script): "Dry run (nothing is changed)" is a checkbox, ON by default, and always posts an explicit true or false');
$b3 = $blk($idDisr);
$ok(str_contains($b3, 'This script needs approval') && str_contains($b3, 'approvals queue') && str_contains($b3, 'name="confirm" value="1"') && str_contains($b3, 'required'), 'block (approval + destructive): the approvals-queue notice and a required confirm checkbox');
$ok(str_contains($blk($idAppr), 'approvals queue') && !str_contains($blk($idAppr), 'name="confirm"'), 'block: approval notice without a confirm box when the script is not destructive');
$ok(str_contains($blk($idDestr), 'name="confirm" value="1"') && !str_contains($blk($idRead), 'name="confirm"'), 'block: the confirm box only where Core requires it (destructive)');
$ok(substr_count($dlg, 'rmm-run-lib-block') >= count($vmW['library']['scripts']) && preg_match_all('/rmm-run-lib-block" data-script-id="\d+" hidden>.*?(?=<div class="rmm-run-lib-block"|<div class="col-md-4">)/s', $dlg, $hid) >= 1 && !preg_match('/<(input|select|textarea)(?![^>]*disabled)[^>]*name="param/', implode('', $hid[0])), 'blocks of the scripts not chosen are hidden and their fields disabled (not posted, not validated)');
$ok(str_contains($dlg, 'action="/agent/post/rmm_automation_scr.php"') && str_contains($dlg, 'name="action" value="run_script"') && str_contains($dlg, 'name="csrf_token" value="' . $tok . '"') && str_contains($dlg, 'name="device_id" value="' . $RSW . '"')
    && str_contains($dlg, 'value="/agent/asset_details.php?asset_id=' . $A['WIN1'] . '#rmm-jobs"'), 'dialog: the library form posts to the card\'s handler with CSRF, the device and a return to the Jobs tab');
$ok(!preg_match('/\son(click|change|submit|input|load|focus|blur)=/i', $dlg), 'dialog: no inline event handlers (CSP)');
$ok(str_contains($dlg, 'id="rmm-run-form"') && str_contains($dlg, 'id="rmm-run-text"') && str_contains($dlg, 'Runs as SYSTEM.'), 'dialog: the legacy form (saved script, text) is still there');
$strip = rivetRmmUiStrip($vmW);
$ok(preg_match('/id="rmm-act-script"[^>]*>/', $strip, $m) === 1 && !str_contains($m[0], 'disabled'), 'WIN1: the Run script button is enabled');

// ---------------------------------------------------------------- Linux
$dl = rivetRmmUiDialogs($vmL, $tok);
$sl = rivetRmmUiStrip($vmL);
$ok(preg_match('/id="rmm-act-script"[^>]*>/', $sl, $m) === 1 && !str_contains($m[0], 'disabled') && str_contains($dl, 'id="rmmRunModal"') && str_contains($dl, 'Run a script on LNX1'), 'LNX1: the Run script button is enabled and the dialog is rendered');
$ok(str_contains($dl, '>Shell (type or paste)</option>') && !str_contains($dl, 'PowerShell') && !str_contains($dl, 'A saved script') && str_contains($dl, 'Runs with bash'), 'LNX1: free-form text is "Shell (type or paste)" (job type shell); there is no PowerShell and no legacy saved script');
$ok(str_contains($dl, 'RS Uptime (Linux)') && !str_contains($dl, '(Windows)') && str_contains($dl, 'Script library ('), 'LNX1: only Linux library scripts');
$jsPanel = (string) file_get_contents($root . '/js/rmm_panel.js');
$ok(str_contains($jsPanel, "platform === 'linux' ? 'shell' : 'powershell'"), 'js/rmm_panel.js: free-form text on Linux queues the shell job type');
$ok(\RivetCore\Rmm\Job\JobTypeRegistry::withDefaults()->has('shell') && \RivetCore\Rmm\Job\JobTypeRegistry::withDefaults()->get('shell')->ability === \RivetCore\Rmm\Job\JobTypeRegistry::ABILITY_RUN_SCRIPT, 'Core: the shell job type exists for free-form Linux text and needs the free-form ability');

// ---------------------------------------------------------------- restricted viewers and the single-option cases
$vmRb = $panel('WIN1', 'rebootonly');
$dRb = rivetRmmUiDialogs($vmRb, $tok);
$ok($vmRb['perm']['run_saved'] === true && $vmRb['perm']['run_script'] === false, 'rebootonly: may run saved scripts, not free-form text (premise)');
$ok(str_contains($dRb, 'Script library (') && !str_contains($dRb, 'PowerShell (type or paste)') && !str_contains($dRb, 'id="rmm-run-text"'), 'rebootonly: the library and saved scripts are there; free-form text is not offered');
$vmV = $panel('WIN1', 'viewer');
$sV = rivetRmmUiStrip($vmV);
$ok($vmV['library']['scripts'] === [] && $vmV['library']['on'] === false && preg_match('/id="rmm-act-script"[^>]*>/', $sV, $m) === 1 && str_contains($m[0], 'disabled') && str_contains($sV, 'Your role cannot run jobs.')
    && !str_contains(rivetRmmUiDialogs($vmV, $tok), 'rmmRunModal'), 'viewer: no library is read, the Run script button is disabled with the reason, no dialog is rendered');
[$c, $page] = $rsGet('viewer', '/agent/asset_details.php?asset_id=' . $A['WIN1']);
$ok($c === 200 && !str_contains($page, 'id="rmm-run-lib-form"') && !str_contains($page, 'RS Disk report'), 'viewer (page): no library form, no script names');
[$c, $page] = $rsGet('deptb', '/agent/asset_details.php?asset_id=' . $A['WIN1']);
$ok(!str_contains($page, 'id="rmmRunModal"') && !str_contains($page, 'RS Disk report'), 'Dept B user on a Dept A device: no panel, no dialog, no script names');
[$c, $page] = $rsGet('deptb', '/agent/asset_details.php?asset_id=' . $A['LNX1']);
$ok(str_contains($page, 'id="rmm-run-lib-form"') && str_contains($page, 'RS Uptime (Linux)') && !str_contains($page, '(Windows)'), 'Dept B user on the Dept B Linux device: the Linux library dialog');

// ---------------------------------------------------------------- empty states
$retire = fn (int $id) => $rsApi('DELETE', ['scripts', $id]);
$vmL2 = $vmL; $vmL2['library']['scripts'] = [];   // the library read for a device platform that no script runs on
$dl2 = rivetRmmUiDialogs($vmL2, $tok);
$ok(!str_contains($dl2, 'id="rmm-run-lib-form"') && str_contains($dl2, 'The library has no script for Linux devices yet') && str_contains($dl2, 'href="/agent/rmm_starter_pack.php">Starter pack</a>') && str_contains($dl2, 'href="/agent/rmm_script_library.php">Scripts</a>'),
    'empty library for Linux: the dialog says so and links the Starter pack (administrator) and Scripts');
$ok(!str_contains($dl2, '<option value="library">') && str_contains($dl2, 'type="hidden" id="rmm-run-type" value="text"') && str_contains($dl2, 'class="form-control-plaintext fw-semibold"') && !str_contains($dl2, '<select class="form-select" id="rmm-run-type"'),
    'empty library: no dropdown with a single useless option, the one remaining source is shown as text');
$vmD2 = $panel('LNX1', 'deptb'); $vmD2['library']['scripts'] = [];
$dRbL = rivetRmmUiDialogs($vmD2, $tok);
$ok(str_contains($dRbL, 'Ask an administrator to add scripts') && !str_contains($dRbL, 'href="/agent/rmm_starter_pack.php"'), 'empty library, non-administrator: asks an administrator, no Starter pack link');


// ============================================================ HTTP: the page, then real runs through the card's handler
[$c, $page] = $rsGet('admin', '/agent/asset_details.php?asset_id=' . $A['WIN1']);
$ok($c === 200 && str_contains($page, 'Script library (') && str_contains($page, 'id="rmm-run-lib-form"') && str_contains($page, 'RS Clean temp (Windows)') && str_contains($page, 'rmm_panel.js') && !str_contains($page, 'PowerShell text'), 'asset page (admin, WIN1): the dialog lists the library');
$ok(strpos($page, 'id="rmmRunModal"') !== false && str_contains($page, 'id="rmm-library-run"'), 'asset page: the Jobs-tab card is still there (same handler, same data)');
// read-only script: queued job
$before = $rsJobs($idRead);
[$c, $body, $loc] = $rsPost('admin', ['action' => 'run_script', 'device_id' => $RSW, 'library_script_id' => $idRead, 'script_version' => '1', 'timeout_s' => '', 'param' => ['top_n' => '7', 'folder' => 'D:\\data', 'api_token' => $SECRET, 'format' => 'csv']],
    '/agent/post/rmm_automation_scr.php');
$j = $rsJob($idRead);
$ok($rsJobs($idRead) === $before + 1 && $j && (int) $j['device_id'] === $RSW && $j['type'] === 'powershell' && $j['state'] === 'queued' && (int) $j['script_id'] === $idRead && (int) $j['script_version'] === 1, 'run (read only): a queued powershell job for WIN1, library_script_id and version decode');
$pj = json_decode((string) $j['params_json'], true);
$ok(($pj['top_n'] ?? null) === 7 && ($pj['folder'] ?? '') === 'D:\\data' && ($pj['format'] ?? '') === 'csv' && !str_contains(json_encode($pj), $SECRET), 'run (read only): typed params decode (int is an int), the secret is not in the job row (' . json_encode($pj) . ')');
$ok(str_contains($loc, '/agent/asset_details.php?asset_id=' . $A['WIN1']) && str_contains($body, 'Job queued.') && !str_contains($body, $SECRET), 'run: back on the asset page with the "Job queued" message, no secret on the page');
// changing script: dry run ON (checkbox) and OFF (unticked posts the hidden false); one second apart so the newest job is unambiguous
$before = $rsJobs($idChange);
[, $body] = $rsPost('admin', ['action' => 'run_script', 'device_id' => $RSW, 'library_script_id' => $idChange, 'script_version' => '1', 'param' => ['dry_run' => 'true', 'older_days' => '10']]);
$j = $rsJob($idChange); $pj = json_decode((string) $j['params_json'], true);
$ok($rsJobs($idChange) === $before + 1 && ($pj['dry_run'] ?? null) === true && ($pj['older_days'] ?? null) === 10, 'run (changing, dry run ticked): the job carries dry_run true');
sleep(1);
$ch = curl_init($wb . '/agent/post/rmm_automation_scr.php');
curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => 'csrf_token=csrftok1&action=run_script&device_id=' . $RSW . '&library_script_id=' . $idChange . '&script_version=1&param%5Bdry_run%5D=false&param%5Bdry_run%5D=true&param%5Bolder_days%5D=',
    CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIE => 'PHPSESSID=' . $SS['admin']]);
curl_exec($ch); curl_close($ch);
$j = $rsJob($idChange); $pj = json_decode((string) $j['params_json'], true);
$ok($rsJobs($idChange) === $before + 2 && ($pj['dry_run'] ?? null) === true, 'the hidden "false" before the checkbox: a ticked box (both values posted) wins and means true');
sleep(1);
$ch = curl_init($wb . '/agent/post/rmm_automation_scr.php');
curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => 'csrf_token=csrftok1&action=run_script&device_id=' . $RSW . '&library_script_id=' . $idChange . '&script_version=1&param%5Bdry_run%5D=false',
    CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIE => 'PHPSESSID=' . $SS['admin']]);
curl_exec($ch); curl_close($ch);
$j = $rsJob($idChange); $pj = json_decode((string) $j['params_json'], true);
$ok($rsJobs($idChange) === $before + 3 && ($pj['dry_run'] ?? null) === false, 'an unticked box posts false: the change is made for real only when the person unticks it (' . json_encode($pj) . ' ' . $rsJobs($idChange) . '/' . $before . ')');
// approval required
$ap = (int) $one('SELECT COUNT(*) FROM rmm_approvals'); $jb = $rsJobs($idAppr);
[$c, $body, $loc] = $rsPost('admin', ['action' => 'run_script', 'device_id' => $RSW, 'library_script_id' => $idAppr, 'script_version' => '1', 'param' => ['who' => 'me']]);
$ok((int) $one('SELECT COUNT(*) FROM rmm_approvals') === $ap + 1 && $rsJobs($idAppr) === $jb && str_contains($loc, '/agent/rmm_approvals.php') && str_contains($body, 'waiting under Approvals'), 'run (approval required): the request lands in the approvals queue, no job exists, the person is taken there and told');
// destructive needs the explicit confirm
$jb = $rsJobs($idDestr);
[, $body] = $rsPost('admin', ['action' => 'run_script', 'device_id' => $RSW, 'library_script_id' => $idDestr, 'script_version' => '1']);
$ok($rsJobs($idDestr) === $jb && str_contains($body, 'destructive'), 'run (destructive) without the confirm box: refused by Core, no job');
[, $body] = $rsPost('admin', ['action' => 'run_script', 'device_id' => $RSW, 'library_script_id' => $idDestr, 'script_version' => '1', 'confirm' => '1']);
$ok($rsJobs($idDestr) === $jb + 1, 'run (destructive) with the confirm box: queued');
// refusals: CSRF, viewer, wrong department, wrong platform
$jb = $rsJobs($idRead);
$rsPost('admin', ['action' => 'run_script', 'device_id' => $RSW, 'library_script_id' => $idRead, 'script_version' => '1', 'param' => ['top_n' => '3']], '/agent/post/rmm_automation_scr.php', false);
$ok($rsJobs($idRead) === $jb, 'no CSRF token: nothing is queued');
$rsPost('viewer', ['action' => 'run_script', 'device_id' => $RSW, 'library_script_id' => $idRead, 'script_version' => '1', 'param' => ['top_n' => '3']]);
$ok($rsJobs($idRead) === $jb, 'a viewer posting the form by hand: nothing is queued');
$rsPost('deptb', ['action' => 'run_script', 'device_id' => $RSW, 'library_script_id' => $idRead, 'script_version' => '1', 'param' => ['top_n' => '3']]);
$ok($rsJobs($idRead) === $jb, 'a Dept B user posting for a Dept A device: nothing is queued');
[, $body] = $rsPost('admin', ['action' => 'run_script', 'device_id' => $RSL, 'library_script_id' => $idRead, 'script_version' => '1', 'param' => ['top_n' => '3']]);
$ok($rsJobs($idRead) === $jb && (int) $one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE device_id=$RSL AND type='powershell'") === 0, 'a Windows script posted for the Linux device: refused, no job');
// the Linux run through the dialog
$jb = $rsJobs($idLin);
$rsPost('deptb', ['action' => 'run_script', 'device_id' => $RSL, 'library_script_id' => $idLin, 'script_version' => '1']);
$jl = $rsJob($idLin);
$ok($rsJobs($idLin) === $jb + 1 && $jl && (int) $jl['device_id'] === $RSL && $jl['type'] === 'shell', 'Linux: a Dept B technician runs the Linux library script on the Dept B device (shell job)');
// the approvals notice also reaches the person
[$c, $page] = $rsGet('admin', '/agent/rmm_approvals.php');
$ok($c === 200 && str_contains($page, 'RS Needs sign-off'), 'the approvals page lists the request made from the dialog');

// ============================================================ switches: nothing is drawn, nothing is asked
$rsFeature(false);
$vmOff = $panel('WIN1');
$ok($vmOff['library']['on'] === false && $vmOff['library']['scripts'] === [], 'scripts switch off: no library in the view-model');
$dOff = rivetRmmUiDialogs($vmOff, $tok);
$ok(!str_contains($dOff, 'id="rmm-run-lib-form"') && !str_contains($dOff, 'Script library') && str_contains($dOff, 'PowerShell (type or paste)') && !str_contains($dOff, 'The library has no script'), 'scripts switch off: the dialog holds only saved scripts and text (no library, no empty-state talk)');
$qa = $questions();
rivetRmmScrDeviceLibrary($db, 1, 'admin', 'windows', $vmOff['perm']);
$qb = $questions();
$ok($qb - $qa - $overhead === 0, 'scripts switch off: the library reader asks the database nothing (' . ($qb - $qa - $overhead) . ' queries)');
[$c, $page] = $rsGet('admin', '/agent/asset_details.php?asset_id=' . $A['WIN1']);
$ok($c === 200 && !str_contains($page, 'rmm-run-lib-form') && !str_contains($page, 'RS Disk report') && str_contains($page, 'id="rmm-run-form"'), 'scripts switch off (page): no library form and no names, the legacy form stays (' . $c . ' ' . (int) str_contains($page, 'rmm-run-lib-form') . (int) str_contains($page, 'RS Disk report') . (int) str_contains($page, 'id="rmm-run-form"') . ')');
$jb = $rsJobs($idRead);
[, $body] = $rsPost('admin', ['action' => 'run_script', 'device_id' => $RSW, 'library_script_id' => $idRead, 'script_version' => '1', 'param' => ['top_n' => '3']]);
$ok($rsJobs($idRead) === $jb, 'scripts switch off: the handler queues nothing');
$rmm->admin()->disable($admin); $fresh();
$qa = $questions();
$ok(rivetRmmUiPanel($db, $A['WIN1'], 1) === null && rivetRmmScrDeviceLibrary($db, 1, 'admin', 'windows', ['run_saved' => true]) === ['on' => false, 'scripts' => [], 'error' => '', 'truncated' => false], 'module off: no panel and no library');
$qb = $questions();
$ok($qb - $qa - $overhead <= 1, 'module off: the library reader and the panel run no RMM query (' . ($qb - $qa - $overhead) . ')');
$rmm->admin()->enable($admin); $fresh();
$rsFeature(true);

// ============================================================ ONE Scripts area
$navHtml = function (string $who, string $path) use ($rsGet): string { return $rsGet($who, $path)[1]; };
$nav = $navHtml('admin', '/agent/rmm_dashboard.php');
$ok(substr_count($nav, '<span class="text-truncate">Scripts</span>') === 1 && !str_contains($nav, '>Script library<') && str_contains($nav, 'href="/agent/rmm_script_library.php"') && !str_contains($nav, 'href="/agent/rmm_scripts.php" class="dropdown-item'), 'menu: exactly one "Scripts" entry (it points at the library; the old second entry is gone)');
$ok(preg_match('~href="/agent/rmm_script_library.php" class="dropdown-item active"~', $navHtml('admin', '/agent/rmm_scripts.php')) === 1 && preg_match('~href="/agent/rmm_script_library.php" class="dropdown-item active"~', $navHtml('admin', '/agent/rmm_script_library.php')) === 1, 'menu: the Scripts entry is active on both pages (library and "Synced from")');
$ok(array_values(array_filter(rivetRmmAutoNav(), fn ($e) => $e['href'] === '/agent/rmm_script_library.php'))[0]['pages'] === ['rmm_script_library.php', 'rmm_scripts.php'], 'rivetRmmAutoNav: one entry named Scripts covering both pages');
$entries = array_column(rivetRmmAutoNav(), 'label');
$ok(in_array('Scripts', $entries, true) && !in_array('Script library', $entries, true), 'rivetRmmAutoNav: the label is "Scripts"');
$rsFeature(false);
$navOff = $navHtml('admin', '/agent/rmm_dashboard.php');
$ok(substr_count($navOff, '<span class="text-truncate">Scripts</span>') === 1 && str_contains($navOff, 'href="/agent/rmm_scripts.php" class="dropdown-item') && !str_contains($navOff, 'href="/agent/rmm_script_library.php"'), 'menu, library switch off: still one "Scripts" entry (the legacy list)');
[$c, $pg] = $rsGet('admin', '/agent/rmm_scripts.php');
$ok($c === 200 && !str_contains($pg, 'id="rmm-scripts-tabs"') && !str_contains($pg, 'id="legacy-import"') && str_contains($pg, '>Scripts</h4>'), 'library switch off: the legacy page alone, titled Scripts, no tabs, no import');
$rsFeature(true);

[$c, $lib] = $rsGet('admin', '/agent/rmm_script_library.php');
$ok($c === 200 && str_contains($lib, '<h4 class="mb-0 me-auto"><i class="fas fa-file-code me-2" aria-hidden="true"></i>Scripts</h4>') && !str_contains($lib, '>Script library<') && str_contains($lib, 'id="rmm-scripts-tabs"')
    && preg_match('~<a class="nav-link active" href="/agent/rmm_script_library.php" aria-current="page"><i class="fas fa-file-code me-1" aria-hidden="true"></i>Library</a>~', $lib) === 1 && str_contains($lib, 'href="/agent/rmm_scripts.php"><i class="fas fa-cloud-download-alt me-1" aria-hidden="true"></i>Synced from RMM</a>'),
    'library page: titled Scripts, tabs Library (active) and "Synced from RMM" (legacy rows exist)');
[$c, $leg] = $rsGet('admin', '/agent/rmm_scripts.php');
$ok($c === 200 && str_contains($leg, 'id="rmm-scripts-tabs"') && preg_match('~<a class="nav-link active" href="/agent/rmm_scripts.php" aria-current="page">~', $leg) === 1 && str_contains($leg, 'Disk cleanup') && !str_contains($leg, '>Script Library<'), 'legacy page: second tab active, rows listed, retitled Scripts');
$ok(str_contains($leg, 'id="legacy-import"') && str_contains($leg, 'name="import_legacy"') && str_contains($leg, 'name="csrf_token"') && str_contains($leg, 'Import 1') && str_contains($leg, 'for="legacy-import-risk"') && str_contains($leg, '<option value="changes_system" selected>'), 'legacy page (admin): the "Import into the library" card with the risk class picker defaulting to changes_system');
// who may import
$libBefore = (int) $one('SELECT COUNT(*) FROM rmm_scripts_v2');
[, $b] = $rsPost('tech', ['import_legacy' => '1', 'risk' => 'changes_system'], '/agent/rmm_scripts.php');
$ok((int) $one('SELECT COUNT(*) FROM rmm_scripts_v2') === $libBefore, 'import by a technician (not an RMM administrator): nothing is created');
[, $b] = $rsPost('admin', ['import_legacy' => '1', 'risk' => 'changes_system'], '/agent/rmm_scripts.php', false);
$ok((int) $one('SELECT COUNT(*) FROM rmm_scripts_v2') === $libBefore, 'import without a CSRF token: nothing is created');
[$c, $b, $loc] = $rsPost('admin', ['import_legacy' => '1', 'risk' => 'changes_system'], '/agent/rmm_scripts.php');
$imp = $db->query("SELECT * FROM rmm_scripts_v2 WHERE name='Disk cleanup'")->fetch_assoc();
$ok((int) $one('SELECT COUNT(*) FROM rmm_scripts_v2') === $libBefore + 1 && $imp && str_contains($b, '1 script imported into the library'), 'import: one library script created from the legacy row, and the person is told');
$impS = $rsApi('GET', ['scripts', (int) $imp['script_id']]);
$cur = $impS['data']['script']['current'];
$ok($impS['data']['script']['language'] === 'powershell' && $impS['data']['script']['platform'] === 'windows' && $cur['body_sha256'] === rivetRmmScrBodyHash('Get-Date') && str_contains($cur['note'], 'imported from legacy Scripts') && $impS['data']['script']['requires_approval'] === false
    && in_array('risk:changes_system', $impS['data']['script']['tags'], true) && in_array('imported', $impS['data']['script']['tags'], true), 'import: PowerShell/Windows as stored, same text (hash), note "imported from legacy Scripts", risk class changes_system, no approval for that class, tags');
$ok((int) $one("SELECT COUNT(*) FROM rmm_scripts WHERE name='Disk cleanup'") === 1, 'import: the legacy row stays');
[, $b] = $rsPost('admin', ['import_legacy' => '1', 'risk' => 'disruptive'], '/agent/rmm_scripts.php');
$ok((int) $one('SELECT COUNT(*) FROM rmm_scripts_v2') === $libBefore + 1 && (int) $one("SELECT COUNT(*) FROM rmm_scripts_v2 WHERE name='Disk cleanup'") === 1, 'import twice: idempotent (same name and text is skipped, even with another risk class picked)');
[$c, $leg] = $rsGet('admin', '/agent/rmm_scripts.php');
$ok(str_contains($leg, '>Imported</a>') && str_contains($leg, 'rmm_script_library.php?script_id=' . (int) $imp['script_id']) && !str_contains($leg, 'id="legacy-import"'), 'legacy page after the import: the row says Imported (linking the library script) and the import card is gone');
$ok((int) $one("SELECT COUNT(*) FROM logs WHERE log_type='RMM' AND log_action='Script Import'") >= 1, 'import: audited');
// a changed legacy text is a different script: importable again under the same name? (Core refuses duplicate names: reported, nothing broken)
$q("INSERT INTO rmm_scripts SET name='Legacy bash', script_type='bash', script_body='echo hi', category='Software Install', enabled=1");
$q("INSERT INTO rmm_scripts SET name='Legacy cmd', script_type='cmd', script_body='dir', category='Repair', enabled=1");
$q("INSERT INTO rmm_scripts SET name='Synced one', script_type='powershell', script_body='Get-Date', category='Repair', tactical_script_id=77, enabled=1");
[$c, $b] = $rsPost('admin', ['import_legacy' => '1', 'risk' => 'disruptive'], '/agent/rmm_scripts.php');
$lb = $db->query("SELECT * FROM rmm_scripts_v2 WHERE name='Legacy bash'")->fetch_assoc();
$lbS = $lb ? $rsApi('GET', ['scripts', (int) $lb['script_id']])['data']['script'] : [];
$ok($lb && ($lbS['language'] ?? '') === 'bash' && ($lbS['platform'] ?? '') === 'linux' && $lbS['requires_approval'] === true && $lbS['destructive'] === true && in_array('software-install', $lbS['tags'], true) && in_array('risk:disruptive', $lbS['tags'], true),
    'import: bash becomes a Linux script, the category becomes a tag, disruptive picks approval and destructive (the starter pack\'s defaults)');
$ok((int) $one("SELECT COUNT(*) FROM rmm_scripts_v2 WHERE name IN ('Legacy cmd','Synced one')") === 0 && str_contains($b, 'CMD'), 'import: CMD cannot be held by the library (reported), a script synced from a vendor RMM is never imported');
// ids limit the import; the card hides when nothing is left
$q("DELETE FROM rmm_scripts WHERE name IN ('Legacy cmd')");
// the tabs and the redirect
$q("UPDATE rmm_scripts SET name=name"); $legacyBackup = $db->query('SELECT * FROM rmm_scripts')->fetch_all(MYSQLI_ASSOC);
$q('DELETE FROM rmm_scripts');
[$c, $b, $h] = web($wb, 'GET', '/agent/rmm_scripts.php?q=disk&category=Repair', $SS['admin']);
$ok($c === 302 && $rsLoc($h) === '/agent/rmm_script_library.php?q=disk&category=Repair', 'old URL rmm_scripts.php with nothing synced or created: redirects to the Library tab and keeps the query (' . $c . ' ' . $rsLoc($h) . ')');
[$c, $lib] = $rsGet('admin', '/agent/rmm_script_library.php');
$ok($c === 200 && !str_contains($lib, 'id="rmm-scripts-tabs"'), 'no legacy rows and no vendor RMM: the library page shows no tabs');
$q("UPDATE settings SET config_rmm_default_integration_id = 0 WHERE company_id = 1");
$q("INSERT INTO rmm_integrations SET id=991, name='Acme Tactical', type='tactical_rmm'");
$q("UPDATE settings SET config_rmm_default_integration_id = 991 WHERE company_id = 1");
[$c, $lib] = $rsGet('admin', '/agent/rmm_script_library.php');
$ok($c === 200 && str_contains($lib, 'Synced from Acme Tactical</a>'), 'a vendor RMM integration provides scripts: the tab appears, labelled with the real provider name');
[$c, $leg] = web($wb, 'GET', '/agent/rmm_scripts.php', $SS['admin']);
$ok($c === 200 && str_contains($leg, 'Synced from Acme Tactical') && str_contains($leg, 'Sync from Acme Tactical'), 'the legacy page with a vendor RMM: no redirect, provider named in the tab and the sync button');
$q("UPDATE settings SET config_rmm_default_integration_id = 0 WHERE company_id = 1");
$q("DELETE FROM rmm_integrations WHERE id=991");
foreach ($legacyBackup as $row) {
    $cols = []; foreach ($row as $k => $v) { $cols[] = "`$k`=" . ($v === null ? 'NULL' : "'" . $db->real_escape_string((string) $v) . "'"); }
    $q('INSERT INTO rmm_scripts SET ' . implode(',', $cols));
}
// the run view of a legacy script run and the dashboard link still work
[$c] = $rsGet('admin', '/agent/rmm_script_run.php?run_id=999999');
$ok(in_array($c, [200, 302], true), 'rmm_script_run.php still answers (a missing run goes back to the list)');

// ============================================================ cleanup: the fixtures leave no trace in the library
foreach (array_merge($winIds, [$idLin]) as $id) { $retire($id); }
foreach (['Disk cleanup', 'Legacy bash'] as $nm) {
    $row = $db->query("SELECT script_id FROM rmm_scripts_v2 WHERE name='" . $db->real_escape_string($nm) . "'")->fetch_row();
    if ($row) { $retire((int) $row[0]); }
}
$q("DELETE FROM rmm_scripts WHERE name IN ('Legacy bash','Legacy cmd','Synced one')");
rivetRmmScrDetailForget(1);
