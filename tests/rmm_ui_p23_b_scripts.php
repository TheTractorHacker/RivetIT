<?php
/*
 * RMM Phase 2 UI, group B: the script library, schedules, approvals and the "Run a library script" card of the asset page (fragment of tests/rmm_ui.php).
 * Shares that file's variables ($ok $q $one $db $root $S $D $A $USER $SS $wb $rmm $admin $questions $overhead $fresh). The policies, scripts and alerting switches
 * are ON when it starts, and the module is ON when it ends. Everything is created through the REAL pages' handler (agent/post/rmm_automation_scr.php) with CSRF.
 */

use RivetCore\Rmm\Authz\RmmPrincipal;

require_once "$root/includes/rmm_scr_ui.php";

$L = $D['LNX1'];
$Wd = $D['WIN1'];
$SECRET = 'S3cr3t-VALUE-9f2c1';
$HANDLER = '/agent/post/rmm_automation_scr.php';
$gp = function (string $who, string $path) use ($wb, $SS) {
    $r = web($wb, 'GET', $path, $SS[$who]);
    if (getenv('P23_DUMP')) { static $n = 0; file_put_contents(getenv('P23_DUMP') . '/' . sprintf('%03d', ++$n) . '_' . $who . '_' . preg_replace('/[^a-z0-9]+/i', '_', $path) . '.html', $r[1]); }
    return $r;
};
$hp = function (string $who, array $f, ?string $ret = null, bool $csrf = true) use ($wb, $SS, $HANDLER) {
    if ($csrf) { $f += ['csrf_token' => 'csrftok1']; }
    if ($ret !== null) { $f['return'] = $ret; }
    return web($wb, 'POST', $HANDLER, $SS[$who], $f);
};
$locOf = fn(string $h) => preg_match('/^Location:\s*(\S+)/mi', $h, $m) === 1 ? (string) preg_replace('~^https?://[^/]+~', '', $m[1]) : '';
/** post, follow the redirect with the same session (the flash shows up there): [status of the final page, body, location] */
$pf = function (string $who, array $f, ?string $ret = null) use ($hp, $locOf, $gp) {
    [$c, $b, $h] = $hp($who, $f, $ret);
    $l = $locOf($h);
    if ($c >= 300 && $c < 400 && $l !== '') { [$c2, $b2] = $gp($who, $l); return [$c2, $b2, $l]; }
    return [$c, $b, $l];
};
$apiAs = fn(string $who, string $m, array $seg, array $qs = [], ?array $body = null) => rivetRmmAutoApi($db, $USER[$who], $who, $m, $seg, $qs, $body);
$scriptId = fn(string $name) => (int) $one("SELECT script_id FROM rmm_scripts_v2 WHERE name='" . $db->real_escape_string($name) . "'");
$dump = function (array $tables) use ($db) { $o = ''; foreach ($tables as $t) { $r = $db->query("SELECT * FROM $t"); while ($r && ($row = $r->fetch_assoc())) { $o .= json_encode($row) . "\n"; } } return $o; };
$jobsOf = fn(int $sid) => (int) $one("SELECT COUNT(*) FROM rmm_job_extra WHERE script_id=$sid");

// ============================================================ the pure helpers
$d = rivetRmmScrDiff("a\nb\nc\nd", "a\nB\nc\nd\ne");
$ok($d['added'] === 2 && $d['removed'] === 1, 'diff: one line replaced, one appended = 2 added, 1 removed');
$signs = implode('', array_column($d['rows'], 't'));
$ok($signs === ' -+ +' || $signs === ' -+ ' . '+' || str_contains($signs, '-+'), 'diff: rows are ordered with the removed line before the added line (' . $signs . ')');
$d0 = rivetRmmScrDiff("x\ny", "x\ny");
$ok($d0['added'] === 0 && $d0['removed'] === 0 && !$d0['truncated'], 'diff: identical texts have no changes');
$dn = rivetRmmScrDiff('', "one\ntwo");
$ok($dn['added'] === 2 && $dn['removed'] === 0, 'diff: from nothing, everything is added');
$big = rivetRmmScrDiff(implode("\n", range(1, 3000)), implode("\n", range(3001, 6000)));
$ok($big['truncated'] === true && count($big['rows']) <= 2400, 'diff: a very large change is cut short and says so');
$tbl = rivetRmmScrDiffTable($d);
$ok(str_contains($tbl, 'rmm-diff-add') && str_contains($tbl, 'rmm-diff-del') && str_contains($tbl, 'visually-hidden">added line') && str_contains($tbl, '<span aria-hidden="true">+</span>') && str_contains($tbl, '<span aria-hidden="true">-</span>'), 'diff table: added and removed lines carry a sign AND a class (and a text for screen readers)');
$ok(str_contains(rivetRmmScrDiffTable(rivetRmmScrDiff('<b>x</b>', '<i>y</i>')), '&lt;i&gt;y&lt;/i&gt;'), 'diff table: line text is escaped');
$ok(rivetRmmScrCronWords('*/15 * * * *') === 'Every 15 minutes' && rivetRmmScrCronWords('30 2 * * *') === 'Every day at 02:30 UTC' && rivetRmmScrCronWords('0 6 * * 1-5') === 'Every Monday to Friday at 06:00 UTC'
    && rivetRmmScrCronWords('0 6 * * 1') === 'Every Monday at 06:00 UTC' && rivetRmmScrCronWords('5 4 12 * *') === 'On day 12 of every month at 04:05 UTC' && rivetRmmScrCronWords('1,2 3 4 5 6') === '1,2 3 4 5 6', 'cron in words: the common shapes, the expression itself otherwise');
$ok(rivetRmmScrCadence(['kind' => 'interval', 'interval_s' => 21600]) === 'Every 6 hours' && rivetRmmScrCadence(['kind' => 'interval', 'interval_s' => 90]) === 'Every 90 seconds' && rivetRmmScrCadence(['kind' => 'interval', 'interval_s' => 86400]) === 'Every 1 day', 'cadence in words for intervals');
[$nx, $computed] = rivetRmmScrNextRun(['kind' => 'cron', 'cron' => '0 6 * * *', 'next_run_at' => null], 1_800_000_000);
$ok($computed === true && $nx !== null && str_ends_with($nx, 'T06:00:00Z'), 'next run: computed with Core\'s CronSchedule (and marked "about") when the row has none');
$ok(rivetRmmScrNextRun(['kind' => 'interval', 'interval_s' => 60, 'next_run_at' => '2026-10-10 10:00:00'])[1] === false, 'next run: the stored time is used as it is');
$tr = rivetRmmScrReadParams([]);
$ok($tr === [], 'reading parameters with no definition gives nothing');

// ============================================================ publish a bash script through the real form post (typed JSON: ints as ints, params as a list of definitions)
$bashV1 = "#!/bin/bash\necho \"hello \$RIVETIT_PARAM_greeting\"\nexit 0\n";
[$c, $body, $loc] = $pf('admin', ['action' => 'save_script', 'name' => 'Say hello', 'description' => 'Greets a person <b>loudly</b>', 'language' => 'bash', 'body' => str_replace("\n", "\r\n", $bashV1), 'note' => 'first version',
    'tags' => 'demo, hello', 'timeout_s' => '120', 'pdef' => [0 => ['name' => 'greeting', 'type' => 'string', 'required' => '1', 'label' => 'Greeting', 'max_length' => '40', 'default' => 'world'], 1 => ['name' => 'token', 'type' => 'secret', 'label' => 'API token']]],
    '/agent/rmm_script_library.php?new=1');
$sid = $scriptId('Say hello');
$ok($c === 200 && $sid > 0 && str_contains($loc, 'script_id=' . $sid) && str_contains($body, 'Script published as version 1.'), 'publish: the script exists and the person lands on its page with a flash (' . $c . ' ' . $loc . ')');
$row = $db->query("SELECT * FROM rmm_scripts_v2 WHERE script_id=$sid")->fetch_assoc();
$ver = $db->query("SELECT * FROM rmm_script_versions WHERE script_id=$sid AND version=1")->fetch_assoc();
$ok($row['language'] === 'bash' && $row['platform'] === 'linux' && (int) $row['timeout_s'] === 120 && json_decode($row['tags_json'], true) === ['demo', 'hello'] && (int) $row['owner_id'] === 1, 'publish: language, platform, timeout (an int), tags and owner are stored');
$ok($ver !== null && $ver['body'] === $bashV1 && $ver['body_sha256'] === hash('sha256', $bashV1) && $ver['signature'] !== '', 'publish: the text is stored with line endings normalised, its SHA-256 and a signature');
$schema = json_decode($ver['params_schema_json'], true);
$ok(count($schema) === 2 && $schema[0]['name'] === 'greeting' && $schema[0]['max_length'] === 40 && $schema[0]['required'] === true && $schema[0]['default'] === 'world' && $schema[1]['type'] === 'secret' && !isset($schema[1]['default']), 'publish: the parameter definitions are typed (max_length is an int, required a bool, a secret has no default)');
$ok(str_contains($body, 'Say hello') && str_contains($body, 'Version') && str_contains($body, 'id="scr-run"') && str_contains($body, 'id="scr-edit"') && str_contains($body, 'rmm_scr.js'), 'script page (admin): details, run panel, editor and the page script');
$ok(str_contains($body, 'Greets a person &lt;b&gt;loudly&lt;/b&gt;') && !str_contains($body, '<b>loudly</b>'), 'script page: a hostile description is shown as text');

// ---- saving: a changed text makes version 2; settings only does not; the draft survives a refusal
$bashV2 = "#!/bin/bash\necho \"hello \$RIVETIT_PARAM_greeting\"\necho done\nexit 0\n";
$editPost = fn(string $text, array $over = []) => array_replace(['action' => 'save_script', 'script_id' => $sid, 'name' => 'Say hello', 'description' => 'Greets a person <b>loudly</b>', 'body' => $text, 'note' => 'adds a line', 'tags' => 'demo, hello', 'timeout_s' => '120',
    'pdef' => [0 => ['name' => 'greeting', 'type' => 'string', 'required' => '1', 'label' => 'Greeting', 'max_length' => '40', 'default' => 'world'], 1 => ['name' => 'token', 'type' => 'secret', 'label' => 'API token']]], $over);
[$c, $body] = $pf('admin', $editPost($bashV1), '/agent/rmm_script_library.php?script_id=' . $sid);
$ok($c === 200 && str_contains($body, 'unchanged') && (int) $one("SELECT current_version FROM rmm_scripts_v2 WHERE script_id=$sid") === 1, 'saving the same text and parameters again makes no new version and says so');
[$c, $body] = $pf('admin', $editPost($bashV1, ['timeout_s' => '200', 'requires_approval' => '0']), '/agent/rmm_script_library.php?script_id=' . $sid);
$ok((int) $one("SELECT current_version FROM rmm_scripts_v2 WHERE script_id=$sid") === 1 && (int) $one("SELECT timeout_s FROM rmm_scripts_v2 WHERE script_id=$sid") === 200, 'a settings-only save changes the timeout without a new version');
[$c, $body] = $pf('admin', $editPost($bashV2), '/agent/rmm_script_library.php?script_id=' . $sid);
$ok($c === 200 && str_contains($body, 'new version (2)') && (int) $one("SELECT current_version FROM rmm_scripts_v2 WHERE script_id=$sid") === 2 && (int) $one("SELECT COUNT(*) FROM rmm_script_versions WHERE script_id=$sid") === 2, 'a changed text makes version 2 (immutable: version 1 is still there)');
$ok($one("SELECT body FROM rmm_script_versions WHERE script_id=$sid AND version=1") === $bashV1, 'version 1 is unchanged');
$bad = $editPost("echo not-saved-because-bad-param\n", ['pdef' => [0 => ['name' => 'path', 'type' => 'string']]]);
[$c, $body] = $pf('admin', $bad, '/agent/rmm_script_library.php?script_id=' . $sid);
$ok(str_contains($body, 'role="alert"') || str_contains($body, 'alert'), 'a refused save shows an alert') ;
$ok(str_contains($body, 'reserved word') && (int) $one("SELECT current_version FROM rmm_scripts_v2 WHERE script_id=$sid") === 2, 'a reserved parameter name is refused with Core\'s own words and nothing is saved');
$ok(str_contains($body, 'not-saved-because-bad-param'), 'the refused text is back in the editor (nothing typed is lost)');
[$c, $body] = $gp('admin', '/agent/rmm_script_library.php?script_id=' . $sid);
$ok(!str_contains($body, 'not-saved-because-bad-param'), 'the draft is shown once');
$beforeCsrf = (int) $one("SELECT COUNT(*) FROM rmm_scripts_v2");
[$c] = $hp('admin', ['action' => 'save_script', 'name' => 'No csrf', 'language' => 'bash', 'body' => 'echo x'], null, false);
[$c2] = $hp('admin', ['action' => 'save_script', 'name' => 'Bad csrf', 'language' => 'bash', 'body' => 'echo x', 'csrf_token' => 'nope']);
$ok((int) $one("SELECT COUNT(*) FROM rmm_scripts_v2") === $beforeCsrf, 'a missing or wrong CSRF token creates nothing (' . $c . ', ' . $c2 . ')');
[$c, $body] = $pf('admin', ['action' => 'save_script', 'name' => 'Say hello', 'language' => 'bash', 'body' => 'echo dup'], '/agent/rmm_script_library.php?new=1');
$ok(str_contains($body, 'A script with that name exists.'), 'a duplicate name is refused with Core\'s message');
[$c, $body] = $pf('admin', ['action' => 'save_script', 'name' => 'Way too big', 'language' => 'powershell', 'body' => str_repeat('a', 10001)], '/agent/rmm_script_library.php?new=1');
$ok(str_contains($body, 'at most 10000 characters') && $scriptId('Way too big') === 0, 'a PowerShell script over 10,000 characters is refused');

// ---- the diff between two versions, on the page
[$c, $body] = $gp('admin', "/agent/rmm_script_library.php?script_id=$sid&from=1&to=2");
$ok($c === 200 && str_contains($body, 'id="scr-diff"') && str_contains($body, 'rmm-diff-add') && str_contains($body, 'echo done') && str_contains($body, '1 line added, 0 lines removed.'), 'diff page: the new line is marked added and counted');
preg_match('/<tr class="rmm-diff-add">.*?<\/tr>/s', $body, $m);
$ok(isset($m[0]) && str_contains($m[0], 'echo done') && str_contains($m[0], 'visually-hidden">added line'), 'diff page: the added row holds the changed line');
[$c, $body] = $gp('admin', "/agent/rmm_script_library.php?script_id=$sid&from=2&to=1");
$ok(str_contains($body, '0 lines added, 1 line removed.') && str_contains($body, 'rmm-diff-del'), 'diff page: the other way round, the line is removed');
[$c, $body] = $gp('admin', "/agent/rmm_script_library.php?script_id=$sid&from=1&to=9");
$ok(str_contains($body, 'Pick two versions of this script.'), 'diff page: an unknown version is refused politely');
[$c, $body] = $gp('admin', "/agent/rmm_script_library.php?script_id=$sid&view=1");
$ok(str_contains($body, 'id="scr-version"') && str_contains($body, 'hello $RIVETIT_PARAM_greeting') && !str_contains($body, 'echo done</pre>') , 'version page: version 1 text is shown');
$ok(str_contains($body, substr(hash('sha256', $bashV1), 0, 16)) && str_contains($body, 'data-rmm-copy="' . hash('sha256', $bashV1) . '"'), 'versions: first 16 characters of the hash shown, the full hash on the copy button');

// ---- who sees what on the library pages
$pages = ['admin' => [true, true, true], 'tech' => [true, true, true], 'rebootonly' => [false, true, true], 'viewer' => [false, false, false], 'moduleonly' => [false, false, false], 'deptb' => [true, true, true]];
foreach ($pages as $who => [$canWrite, $canSeeText, $canRun]) {
    [$c, $list] = $gp($who, '/agent/rmm_script_library.php');
    [$c2, $page] = $gp($who, "/agent/rmm_script_library.php?script_id=$sid");
    $ok($c === 200 && $c2 === 200 && str_contains($list, 'Say hello'), "$who: library list and script page render (200)");
    $ok(str_contains($list, 'New script') === $canWrite, "$who: the New script button is " . ($canWrite ? '' : 'not ') . 'drawn');
    $ok((str_contains($page, 'id="scr-edit"') && str_contains($page, 'name="body"')) === $canWrite, "$who: the editor is " . ($canWrite ? '' : 'not ') . 'drawn');
    $ok(str_contains($page, 'echo done') === $canSeeText, "$who: the script text is " . ($canSeeText ? '' : 'not ') . 'in the page');
    $ok(str_contains($page, 'id="scr-run-form"') === $canRun, "$who: the run panel is " . ($canRun ? '' : 'not ') . 'drawn');
}
[$c, $page] = $gp('viewer', "/agent/rmm_script_library.php?script_id=$sid");
$ok(str_contains($page, 'role may not read library scripts') && str_contains($page, 'Greeting') && !str_contains($page, 'scr-diff') && !str_contains($page, 'Recent runs'), 'viewer: metadata and parameters, a reason for the hidden text, no runs');
[$c, $page] = $gp('viewer', "/agent/rmm_script_library.php?script_id=$sid&view=1");
$ok(!str_contains($page, 'echo "hello') && !str_contains($page, 'hello $RIVETIT') , 'viewer: asking for a version\'s text by URL shows none');
[$c, $page] = $gp('rebootonly', "/agent/rmm_script_library.php?script_id=$sid");
$ok(str_contains($page, 'Script text (version 2)') && !str_contains($page, 'Re-sign') , 'rebootonly (run saved scripts, cannot publish): reads the text, no editor, no re-sign');
[$c, $page] = $gp('admin', "/agent/rmm_script_library.php?script_id=$sid");
$ok(str_contains($page, 'Re-sign all versions') && str_contains($page, 'rotated'), 'admin: Re-sign is offered, with the reason in words');
[$c, $page] = $gp('tech', "/agent/rmm_script_library.php?script_id=$sid");
$ok(!str_contains($page, 'Re-sign all versions'), 'tech: no re-sign (administrators only)');
[$c, $page] = $gp('admin', '/agent/rmm_script_library.php?new=1');
$ok($c === 200 && str_contains($page, 'id="scr_counter"') && str_contains($page, 'RIVETIT_PARAM_&lt;name&gt;') && str_contains($page, 'data-rmm-add="#scr-params"') && str_contains($page, '102,400') && str_contains($page, '10,000'), 'new script page: counter with both limits, parameter explanation, add-parameter button');
[$c, $page] = $gp('viewer', '/agent/rmm_script_library.php?new=1');
$ok($c === 200 && !str_contains($page, 'name="body"') && str_contains($page, 'cannot publish'), 'viewer: the new-script page is a notice, not a form');
[$c, $page] = $gp('admin', '/agent/rmm_script_library.php?script_id=999999');
$ok($c === 200 && str_contains($page, 'Script not found'), 'an unknown script id says so');
// hostile name on the list
[$c, $body] = $pf('admin', ['action' => 'save_script', 'name' => '<script>alert(1)</script>', 'language' => 'bash', 'body' => "echo hostile\n"], '/agent/rmm_script_library.php?new=1');
[$c, $list] = $gp('admin', '/agent/rmm_script_library.php?retired=1');
$ok(!str_contains($list, '<script>alert(1)') && str_contains($list, '&lt;script&gt;alert(1)&lt;/script&gt;'), 'a hostile script name is escaped on the list and the flash');
// filters and paging
[$c, $list] = $gp('admin', '/agent/rmm_script_library.php?q=hello&language=bash&tag=demo');
$ok(str_contains($list, 'Say hello') && !str_contains($list, 'alert(1)'), 'list: text, language and tag filters narrow the list');
[$c, $list] = $gp('admin', '/agent/rmm_script_library.php?language=powershell');
$ok(str_contains($list, 'No script matches'), 'list: an empty filter result says so');
[$c, $list] = $gp('admin', '/agent/rmm_script_library.php?q=' . urlencode('"><img src=x onerror=alert(2)>'));
$ok(!str_contains($list, '<img src=x') , 'list: a hostile search text is escaped');

// ---- retire and restore
$hid = $scriptId('<script>alert(1)</script>');
[$c, $list] = $pf('tech', ['action' => 'retire_script', 'script_id' => $hid], '/agent/rmm_script_library.php');
$ok($hid > 0 && str_contains($list, 'Script retired.') && $one("SELECT retired_at IS NOT NULL FROM rmm_scripts_v2 WHERE script_id=$hid") === '1', 'tech (scripts level 3) can retire a script');
[$c, $list] = $gp('admin', '/agent/rmm_script_library.php');
$ok(!str_contains($list, 'alert(1)'), 'a retired script is hidden from the default list');
[$c, $list] = $gp('admin', '/agent/rmm_script_library.php?retired=1');
$ok(str_contains($list, 'Retired') && str_contains($list, 'Restore'), 'with "include retired" it shows, marked Retired, with a Restore button');
[$c, $page] = $gp('admin', "/agent/rmm_script_library.php?script_id=$hid");
$ok(str_contains($page, 'This script is retired') && !str_contains($page, 'id="scr-run-form"') && !str_contains($page, 'name="body"'), 'a retired script page: notice, no run panel, no editor');
[$c, $list] = $pf('rebootonly', ['action' => 'restore_script', 'script_id' => $hid], '/agent/rmm_script_library.php?retired=1');
$ok(str_contains($list, 'role') && $one("SELECT retired_at IS NOT NULL FROM rmm_scripts_v2 WHERE script_id=$hid") === '1', 'a role without publish rights cannot restore (Core refuses, nothing changes)');
[$c, $list] = $pf('admin', ['action' => 'restore_script', 'script_id' => $hid], '/agent/rmm_script_library.php?retired=1');
$ok(str_contains($list, 'Script restored.') && $one("SELECT retired_at IS NULL FROM rmm_scripts_v2 WHERE script_id=$hid") === '1', 'restore brings it back');
[$c, $list] = $pf('viewer', ['action' => 'save_script', 'name' => 'Viewer made', 'language' => 'bash', 'body' => 'echo v'], '/agent/rmm_script_library.php?new=1');
$ok($scriptId('Viewer made') === 0, 'a viewer cannot publish through a forged post');
[$c, $list] = $pf('moduleonly', ['action' => 'save_script', 'name' => 'Module only made', 'language' => 'bash', 'body' => 'echo v'], '/agent/rmm_script_library.php?new=1');
$ok($scriptId('Module only made') === 0, 'a module-only login cannot publish through a forged post');
[$c, $list] = $pf('admin', ['action' => 'no_such_action'], '/agent/rmm_script_library.php');
$ok(str_contains($list, 'Unknown action.'), 'an unknown action is refused');
[$c, $b] = web($wb, 'GET', $HANDLER, $SS['admin']);
$ok($c === 405, 'the handler answers GET with 405');

// ---- re-sign
$sig1 = $one("SELECT signature FROM rmm_script_versions WHERE script_id=$sid AND version=1");
[$c, $list] = $pf('admin', ['action' => 'resign_script', 'script_id' => $sid], "/agent/rmm_script_library.php?script_id=$sid");
$ok(str_contains($list, 'Signed 2 version(s)'), 're-sign: admin re-signs every version');
[$c, $list] = $pf('tech', ['action' => 'resign_script', 'script_id' => $sid], "/agent/rmm_script_library.php?script_id=$sid");
$ok(!str_contains($list, 'Signed 2 version(s)') && str_contains($list, 'role'), 're-sign: a technician is refused with Core\'s words');

// ============================================================ the run panel and running a script (device and target)
[$c, $page] = $gp('admin', "/agent/rmm_script_library.php?script_id=$sid");
$ok(str_contains($page, 'data-rmm-confirm="Run the script &quot;Say hello&quot;, version 2, on') && str_contains($page, 'data-scr-go="1"') && str_contains($page, 'rmmAutoConfirm'), 'run panel: the confirmation sentence names the script and the version (built on the server)');
foreach (['device', 'tag', 'group', 'client', 'site', 'policy', 'all'] as $t) { $ok(str_contains($page, '<option value="' . $t . '"'), "run panel: target type $t is offered"); }
$ok(str_contains($page, 'name="param[greeting]"') && str_contains($page, 'type="password"') && str_contains($page, 'name="param[token]"') && str_contains($page, '{{field.name}}'), 'run panel: a text input for the parameter, a password input for the secret, and the placeholder hint');
$ok(str_contains($page, 'data-rmm-when="#scr_run_version=2"') && str_contains($page, 'data-rmm-when="#scr_run_version=1"'), 'run panel: parameters per version');
$ok(str_contains($page, 'name="timeout_s"') && str_contains($page, 'Version 2 (current)'), 'run panel: timeout and the version choice (current preselected)');

// run on LNX1 (Linux, Dept B) through the target form: one device in the department
$before = $jobsOf($sid);
[$c, $body, $loc] = $pf('admin', ['action' => 'run_script', 'script_id' => $sid, 'script_version' => '2', 'timeout_s' => '60', 'tgt_type' => 'client', 'tgt_id_client' => '2', 'param' => ['greeting' => 'planet', 'token' => $SECRET]], "/agent/rmm_script_library.php?script_id=$sid");
$ok($c === 200 && str_contains($body, '1 job queued for 1 device.') && $jobsOf($sid) === $before + 1, 'run on a department: one job for the one Linux device, and the flash gives the counts');
$job = $db->query("SELECT j.*, x.script_version FROM endpoint_agent_jobs j JOIN rmm_job_extra x ON x.job_id=j.job_id WHERE x.script_id=$sid ORDER BY j.created_at DESC LIMIT 1")->fetch_assoc();
$ok($job && (int) $job['device_id'] === $L && $job['type'] === 'shell' && $job['state'] === 'queued' && (int) $job['script_version'] === 2 && (int) $job['timeout_s'] === 60 && (int) $job['created_by'] === 1, 'the job: shell type, for LNX1, queued, version 2, timeout 60, created by the signed-in user');
$params = json_decode((string) $job['params_json'], true);
$ok(($params['greeting'] ?? '') === 'planet' && !str_contains(json_encode($params), $SECRET), 'the job row holds the plain parameter and a marker, never the secret (' . json_encode($params) . ')');
$ok(!str_contains($dump(['endpoint_agent_jobs', 'rmm_job_extra', 'rmm_approvals', 'rmm_schedules']), $SECRET), 'the secret value is in no job, extra, approval or schedule row in plain text');
$ok(!str_contains($body, $SECRET), 'the secret value is in no page text or flash');
// capability: a device that announced no job:shell is not offered the job, one that did is (creating the job is still allowed)
[$c, , $j] = ea_checkin($S['token']['LNX1'], ['capabilities' => ['job:collect']]);
$ok($c === 200 && (int) ($j['jobs_pending'] ?? -1) === 0, 'Core: LNX1 announced only job:collect, so the shell job is created but not offered to it (jobs_pending 0)');
[$c, , $j] = ea_checkin($S['token']['LNX1'], ['capabilities' => ['job:collect', 'job:shell']]);
$ok($c === 200 && (int) ($j['jobs_pending'] ?? 0) >= 1, 'Core: once LNX1 announces job:shell the job is offered (jobs_pending >= 1)');
// run on "all": only Linux devices can run bash; the rest are not reached
$before = $jobsOf($sid);
[$c, $body] = $pf('admin', ['action' => 'run_script', 'script_id' => $sid, 'script_version' => '2', 'tgt_type' => 'all', 'param' => ['greeting' => 'everyone']], "/agent/rmm_script_library.php?script_id=$sid");
$ok(preg_match('/(\d+) jobs? queued for (\d+) devices?/', $body, $m) === 1 && $jobsOf($sid) === $before + (int) $m[1] && (int) $m[1] >= 0, 'run on all devices: counts in the flash match the jobs created (' . ($m[0] ?? 'none') . ')');
// a bash script on a Windows device: Core says so, verbatim
[$c, $body] = $pf('admin', ['action' => 'run_script', 'script_id' => $sid, 'script_version' => '2', 'device_id' => $Wd, 'param' => ['greeting' => 'x']], '/agent/asset_details.php?asset_id=' . $A['WIN1'] . '#rmm-jobs');
$ok(str_contains($body, 'cannot run on a windows device') , 'a bash script cannot be run on a Windows device: Core\'s message is shown as it is');
// validation
[$c, $body] = $pf('admin', ['action' => 'run_script', 'script_id' => $sid, 'script_version' => '2', 'tgt_type' => 'client', 'tgt_id_client' => '2', 'param' => ['greeting' => str_repeat('x', 41)]], "/agent/rmm_script_library.php?script_id=$sid");
$ok(str_contains($body, 'Parameter greeting') && str_contains($body, 'longer than 40'), 'a parameter that breaks its rule is refused in Core\'s words');
[$c, $body] = $pf('admin', ['action' => 'run_script', 'script_id' => $sid, 'script_version' => '2', 'tgt_type' => 'tag', 'tgt_id_tag' => '99999', 'param' => ['greeting' => 'x']], "/agent/rmm_script_library.php?script_id=$sid");
$ok(str_contains($body, 'does not exist') || str_contains($body, 'No device'), 'a target that does not exist is refused');
[$c, $body] = $pf('admin', ['action' => 'run_script', 'script_id' => $sid, 'script_version' => '77', 'tgt_type' => 'all'], "/agent/rmm_script_library.php?script_id=$sid");
$ok(str_contains($body, 'not found'), 'an unknown version is refused');
// permissions: a viewer cannot run
$before = $jobsOf($sid);
[$c, $body] = $pf('viewer', ['action' => 'run_script', 'script_id' => $sid, 'script_version' => '2', 'tgt_type' => 'client', 'tgt_id_client' => '2', 'param' => ['greeting' => 'x']], "/agent/rmm_script_library.php?script_id=$sid");
$ok($jobsOf($sid) === $before, 'a viewer\'s forged run creates no job');
[$c, $body] = $pf('deptb', ['action' => 'run_script', 'script_id' => $sid, 'script_version' => '2', 'device_id' => $Wd, 'param' => ['greeting' => 'x']], '/agent/rmm_script_library.php');
$ok($jobsOf($sid) === $before, 'a run on a device of another department creates no job (' . (str_contains($body, 'Device not found') ? 'same 404 as a missing device' : 'refused') . ')');
// recent runs on the script page
[$c, $page] = $gp('admin', "/agent/rmm_script_library.php?script_id=$sid");
$ok(str_contains($page, 'id="scr-runs"') && str_contains($page, 'LNX1') && str_contains($page, 'Queued'), 'script page: recent runs list the device, version and state');
$ok(!str_contains($page, $SECRET), 'script page: no secret');

// ---- destructive script: confirmation checkbox and Core's refusal without it
[$c, $body] = $pf('admin', ['action' => 'save_script', 'name' => 'Wipe temp', 'language' => 'bash', 'body' => "rm -rf /tmp/rivet-demo\n", 'destructive' => '1'], '/agent/rmm_script_library.php?new=1');
$did = $scriptId('Wipe temp');
[$c, $page] = $gp('admin', "/agent/rmm_script_library.php?script_id=$did");
$ok($did > 0 && str_contains($page, 'data-rmm-confirm-check="I confirm this destructive script may run now."') && str_contains($page, 'name="confirm"') && str_contains($page, 'marked destructive'), 'destructive script: the dialog has the extra required checkbox and the sentence says so');
$before = $jobsOf($did);
[$c, $body] = $pf('admin', ['action' => 'run_script', 'script_id' => $did, 'script_version' => '1', 'tgt_type' => 'client', 'tgt_id_client' => '2'], "/agent/rmm_script_library.php?script_id=$did");
$ok(str_contains($body, 'destructive') && $jobsOf($did) === $before, 'destructive script without the confirmation: Core refuses and nothing is queued');
[$c, $body] = $pf('admin', ['action' => 'run_script', 'script_id' => $did, 'script_version' => '1', 'tgt_type' => 'client', 'tgt_id_client' => '2', 'confirm' => '1'], "/agent/rmm_script_library.php?script_id=$did");
$ok(str_contains($body, '1 job queued') && $jobsOf($did) === $before + 1, 'destructive script with the confirmation is queued');

// ============================================================ approvals
$q("UPDATE settings SET config_rmm_approve_scripts_lvl3 = 1 WHERE company_id = 1");
rivetRmmForgetAccess();
[$c, $body] = $pf('tech', ['action' => 'save_script', 'name' => 'Needs sign-off', 'language' => 'bash', 'body' => "echo signed-off-text <not-html>\n", 'requires_approval' => '1', 'pdef' => [0 => ['name' => 'who', 'type' => 'string'], 1 => ['name' => 'pw', 'type' => 'secret']]], '/agent/rmm_script_library.php?new=1');
$aid = $scriptId('Needs sign-off');
$ok($aid > 0, 'a technician (scripts level 3) publishes a script that needs approval');
[$c, $page] = $gp('tech', "/agent/rmm_script_library.php?script_id=$aid");
$ok(str_contains($page, 'This script needs approval') , 'run panel: says the run needs a second person');
$before = $jobsOf($aid);
[$c, $body, $loc] = $pf('tech', ['action' => 'run_script', 'script_id' => $aid, 'script_version' => '1', 'device_id' => $L, 'param' => ['who' => 'me']], '/agent/asset_details.php?asset_id=' . $A['LNX1'] . '#rmm-jobs');
$ap = $db->query("SELECT * FROM rmm_approvals WHERE script_id=$aid ORDER BY approval_id DESC LIMIT 1")->fetch_assoc();
$ok($ap && $ap['state'] === 'pending_approval' && (int) $ap['requested_by'] === 10 && (int) $ap['device_count'] === 1 && $jobsOf($aid) === $before, 'a run of a script that needs approval lands in the queue as pending and creates no job');
$ok(str_contains($loc, '/agent/rmm_approvals.php') && str_contains($body, 'needs a second person to approve it') && str_contains($body, 'id="approval-' . $ap['approval_id'] . '"'), 'the person is taken to the Approvals page where the request is waiting, with a message');
$apid = (int) $ap['approval_id'];
// the page as the requester (also an approver by the level-3 setting)
$ok(str_contains($body, 'You cannot approve your own request') && str_contains($body, 'Cancel this request') && str_contains($body, 'id="approvals-pending-count"'), 'requester: cannot approve their own request (disabled, with the words), can cancel; the pending badge shows');
$ok(str_contains($body, 'signed-off-text &lt;not-html&gt;') && str_contains($body, 'Needs sign-off') && str_contains($body, substr(hash('sha256', "echo signed-off-text <not-html>\n"), 0, 16)) && str_contains($body, '<details>'), 'approval details: script name, hash and the escaped text preview in an expandable row');
$ok(preg_match('/<tr id="approval-' . $apid . '">.*?<\/tr>/s', $body, $m) === 1 && str_contains($m[0], '<time'), 'approval row: expires with a relative and an absolute time');
// own approval refused by Core
$api = $apiAs('tech', 'POST', ['approvals', $apid, 'approve'], [], ['note' => '']);
$ok($api['status'] === 403 && $api['code'] === 'own_request', 'Core: the requester cannot approve their own request (403 own_request)');
[$c, $body] = $pf('tech', ['action' => 'decide_approval', 'approval_id' => $apid, 'decision' => 'approve'], '/agent/rmm_approvals.php?state=pending_approval');
$ok(str_contains($body, 'someone other than the person who made it') && $jobsOf($aid) === $before, 'the handler shows that refusal and queues nothing');
// who sees approve controls
[$c, $page] = $gp('admin', '/agent/rmm_approvals.php');
$ok($c === 200 && str_contains($page, 'name="decision" value="approve"') && str_contains($page, 'name="decision" value="reject"') && str_contains($page, 'name="note"') && str_contains($page, 'data-rmm-confirm="Approve this request?'), 'admin: Approve (with a confirmation that repeats the summary), Reject and a note field');
$ok(str_contains($page, 'Cancel this request') && str_contains($page, 'Needs sign-off'), 'admin may also cancel a request');
$ok(str_contains($page, 'signed-off-text'), 'admin sees the script preview');
[$c, $page] = $gp('viewer', '/agent/rmm_approvals.php');
$ok($c === 200 && str_contains($page, 'Needs sign-off') && !str_contains($page, 'name="decision"') && str_contains($page, 'cannot approve or reject') && !str_contains($page, 'signed-off-text') && str_contains($page, 'may not read library scripts'), 'viewer: sees the queue, no approve/reject, and the script text is hidden with the reason');
[$c, $page] = $gp('rebootonly', '/agent/rmm_approvals.php');
$ok($c === 200 && !str_contains($page, 'name="decision"'), 'rebootonly: no approve/reject (lower grant)');
[$c, $page] = $gp('moduleonly', '/agent/rmm_approvals.php');
$ok($c === 200 && !str_contains($page, 'name="decision"') && !str_contains($page, 'Cancel this request'), 'module-only login: view only');
// approve as admin: the job appears
[$c, $body] = $pf('admin', ['action' => 'decide_approval', 'approval_id' => $apid, 'decision' => 'approve', 'note' => 'looks fine'], '/agent/rmm_approvals.php?state=pending_approval');
$ok(str_contains($body, 'Approved. 1 job queued for 1 device.') && $jobsOf($aid) === $before + 1, 'approving a run queues its job immediately and the flash gives the counts');
$ap = $db->query("SELECT * FROM rmm_approvals WHERE approval_id=$apid")->fetch_assoc();
$ok($ap['state'] === 'approved' && (int) $ap['decided_by'] === 1 && $ap['decision_note'] === 'looks fine', 'the decision, the decider and the note are stored');
$jobA = $db->query("SELECT j.* FROM endpoint_agent_jobs j JOIN rmm_job_extra x ON x.job_id=j.job_id WHERE x.approval_id=$apid")->fetch_assoc();
$ok($jobA && (int) $jobA['created_by'] === 10 && (int) $jobA['device_id'] === $L, 'the approved job is queued as the requester');
[$c, $body] = $pf('admin', ['action' => 'decide_approval', 'approval_id' => $apid, 'decision' => 'approve'], '/agent/rmm_approvals.php?state=approved');
$ok(str_contains($body, 'already approved') && $jobsOf($aid) === $before + 1, 'a decided request cannot be decided twice and creates no second job');
[$c, $page] = $gp('admin', '/agent/rmm_approvals.php?state=approved');
$ok(str_contains($page, 'looks fine') && str_contains($page, 'Approved') && str_contains($page, '1 job(s) queued'), 'the approved list shows the note, the decider and the result');
// reject, cancel, expire
$mkReq = function (string $who = 'tech') use ($pf, $aid, $L) { $pf($who, ['action' => 'run_script', 'script_id' => $aid, 'script_version' => '1', 'device_id' => $L, 'param' => ['who' => 'again']], '/agent/rmm_script_library.php'); return (int) $GLOBALS['one']("SELECT MAX(approval_id) FROM rmm_approvals"); };
$one2 = $mkReq();
[$c, $body] = $pf('admin', ['action' => 'decide_approval', 'approval_id' => $one2, 'decision' => 'reject', 'note' => 'not now'], '/agent/rmm_approvals.php?state=pending_approval');
$ok(str_contains($body, 'Request rejected.') && $one("SELECT state FROM rmm_approvals WHERE approval_id=$one2") === 'rejected', 'reject with a note');
[$c, $page] = $gp('admin', '/agent/rmm_approvals.php?state=rejected');
$ok(str_contains($page, 'not now'), 'the rejected list shows the note');
$three = $mkReq();
[$c, $body] = $pf('tech', ['action' => 'cancel_approval', 'approval_id' => $three], '/agent/rmm_approvals.php?state=pending_approval');
$ok(str_contains($body, 'Request cancelled') && $one("SELECT state FROM rmm_approvals WHERE approval_id=$three") === 'cancelled', 'the requester cancels their own request');
[$c, $body] = $pf('tech', ['action' => 'cancel_approval', 'approval_id' => $three], '/agent/rmm_approvals.php?state=pending_approval');
$ok(str_contains($body, 'Only a pending request can be cancelled'), 'a cancelled request cannot be cancelled again');
$four = $mkReq();
$q("UPDATE rmm_approvals SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR) WHERE approval_id=$four");
[$c, $body] = $pf('admin', ['action' => 'decide_approval', 'approval_id' => $four, 'decision' => 'approve'], '/agent/rmm_approvals.php?state=pending_approval');
$ok(str_contains($body, 'has expired') && $one("SELECT state FROM rmm_approvals WHERE approval_id=$four") === 'expired', 'an expired request cannot be approved');
[$c, $body] = $pf('admin', ['action' => 'decide_approval', 'approval_id' => $four, 'decision' => 'approve'], '/agent/rmm_approvals.php?state=expired');
$ok(str_contains($body, 'already expired'), 'and stays expired');
[$c, $page] = $gp('admin', '/agent/rmm_approvals.php?state=all&kind=run');
$ok(str_contains($page, 'Rejected') && str_contains($page, 'Cancelled') && str_contains($page, 'Expired') && str_contains($page, 'Approved'), 'the "all" filter lists every state');
[$c, $page] = $gp('admin', '/agent/rmm_approvals.php?state=pending_approval&kind=schedule');
$ok(str_contains($page, 'Nothing is waiting for approval'), 'a filter with no result says so');
$ok(!str_contains($dump(['rmm_approvals', 'rmm_job_extra']), $SECRET), 'no secret in approvals');
// a literal secret in an approved run is refused (it would have to be stored in plain text)
[$c, $body] = $pf('tech', ['action' => 'run_script', 'script_id' => $aid, 'script_version' => '1', 'device_id' => $L, 'param' => ['who' => 'x', 'pw' => $SECRET]], '/agent/rmm_script_library.php');
$ok(str_contains($body, 'must be a {{field.name}} placeholder') && !str_contains($body, $SECRET) && !str_contains($dump(['rmm_approvals']), $SECRET), 'a literal secret in a run that needs approval is refused, and is not stored or shown');
// a request for a Dept A device (powershell): the other department must not see it
[$c, $body] = $pf('tech', ['action' => 'save_script', 'name' => 'Win sign-off', 'language' => 'powershell', 'body' => "Get-Date # win-secret-body\n", 'requires_approval' => '1'], '/agent/rmm_script_library.php?new=1');
$winId = $scriptId('Win sign-off');
$pf('tech', ['action' => 'run_script', 'script_id' => $winId, 'script_version' => '1', 'device_id' => $Wd], '/agent/rmm_script_library.php');
$winAp = (int) $one("SELECT MAX(approval_id) FROM rmm_approvals WHERE script_id=$winId");
$ok($winAp > 0, 'a Dept A request exists (powershell on WIN1)');
[$c, $page] = $gp('deptb', '/agent/rmm_approvals.php?state=all');
$ok($c === 200 && !str_contains($page, 'Win sign-off') && !str_contains($page, 'win-secret-body'), 'deptb does not see the Dept A request');
[$c, $page] = $gp('admin', '/agent/rmm_approvals.php');
$ok(str_contains($page, 'Win sign-off') && str_contains($page, 'win-secret-body'), 'admin sees it, with the preview');
[$c, $page] = $pf('deptb', ['action' => 'decide_approval', 'approval_id' => $winAp, 'decision' => 'approve'], '/agent/rmm_approvals.php');
$ok($one("SELECT state FROM rmm_approvals WHERE approval_id=$winAp") === 'pending_approval', 'deptb cannot decide a request outside the department (nothing changes)');
// level-3 setting off: the technician is no longer an approver
$q("UPDATE settings SET config_rmm_approve_scripts_lvl3 = 0 WHERE company_id = 1");
rivetRmmForgetAccess();
[$c, $page] = $gp('tech', '/agent/rmm_approvals.php');
$ok($c === 200 && !str_contains($page, 'name="decision"') && str_contains($page, 'Cancel this request') || str_contains($page, 'cannot approve'), 'technician with the level-3 setting off: no approve/reject');
[$c, $body] = $pf('tech', ['action' => 'decide_approval', 'approval_id' => $winAp, 'decision' => 'approve'], '/agent/rmm_approvals.php');
$ok($one("SELECT state FROM rmm_approvals WHERE approval_id=$winAp") === 'pending_approval', 'and a forged approval by that technician changes nothing');

// ============================================================ the asset page card
[$c, $page] = $gp('admin', '/agent/asset_details.php?asset_id=' . $A['LNX1']);
$ok($c === 200 && str_contains($page, 'id="rmm-library-run"') && str_contains($page, 'Say hello') && !str_contains($page, 'Win sign-off') && str_contains($page, 'name="device_id" value="' . $L . '"') && str_contains($page, 'rmm_scr.js'), 'asset page (Linux device): the card lists the bash scripts and not the PowerShell one');
$ok(str_contains($page, 'Only scripts that run on a Linux device are listed') && str_contains($page, 'data-rmm-when="#rmm-ds-script='), 'asset page: the parameter forms are rendered per script (shown by the selection)');
$ok(strpos($page, 'id="rmm-library-run"') < strpos($page, 'id="rmm-jobs-card"'), 'asset page: the card is at the top of the Jobs tab');
[$c, $page] = $gp('admin', '/agent/asset_details.php?asset_id=' . $A['WIN1']);
$ok(str_contains($page, 'id="rmm-library-run"') && str_contains($page, 'Win sign-off') && !str_contains($page, '>Say hello (Bash'), 'asset page (Windows device): only PowerShell scripts');
foreach (['viewer', 'moduleonly', 'deptb'] as $who) {
    [$c, $page] = $gp($who, '/agent/asset_details.php?asset_id=' . $A['WIN1']);
    $ok(!str_contains($page, 'id="rmm-library-run"'), "asset page ($who): no run card");
}
[$c, $page] = $gp('tech', '/agent/asset_details.php?asset_id=' . $A['LNX1']);
$ok(str_contains($page, 'id="rmm-library-run"'), 'asset page (technician): run card');
[$c, $page] = $gp('admin', '/agent/asset_details.php?asset_id=' . $A['NEVER']);
$ok(!str_contains($page, 'id="rmm-library-run"'), 'asset page: a device that never checked in is not usable, no card');
// run from the card
$before = $jobsOf($sid);
[$c, $body, $loc] = $pf('admin', ['action' => 'run_script', 'device_id' => $L, 'library_script_id' => $sid, 'script_version' => '2', 'timeout_s' => '', 'param' => ['greeting' => 'card', 'token' => $SECRET]], '/agent/asset_details.php?asset_id=' . $A['LNX1'] . '#rmm-jobs');
$ok(str_contains($loc, '/agent/asset_details.php?asset_id=' . $A['LNX1']) && str_contains($body, 'Job queued.') && $jobsOf($sid) === $before + 1, 'run from the card: a job for this device, back on the asset page with a flash');
$ok(!str_contains($body, $SECRET) && !str_contains($dump(['endpoint_agent_jobs', 'rmm_job_extra']), $SECRET), 'run from the card: no secret in the page or the rows');
[$c, $body] = $pf('admin', ['action' => 'run_script', 'device_id' => $L, 'library_script_id' => $did, 'script_version' => '1'], '/agent/asset_details.php?asset_id=' . $A['LNX1'] . '#rmm-jobs');
$ok(str_contains($body, 'destructive'), 'run from the card: a destructive script needs its confirmation');
$before = (int) $one("SELECT COUNT(*) FROM rmm_approvals");
[$c, $body, $loc] = $pf('tech', ['action' => 'run_script', 'device_id' => $L, 'library_script_id' => $aid, 'script_version' => '1', 'param' => ['who' => 'card']], '/agent/asset_details.php?asset_id=' . $A['LNX1'] . '#rmm-jobs');
$ok((int) $one("SELECT COUNT(*) FROM rmm_approvals") === $before + 1 && str_contains($body, 'waiting under Approvals'), 'run from the card of a script that needs approval: 202 handled, the person is told it waits');

// ============================================================ schedules
[$c, $page] = $gp('admin', '/agent/rmm_schedules.php');
$ok($c === 200 && str_contains($page, 'No schedules yet') && str_contains($page, 'New schedule'), 'schedules: empty state with a New schedule button (admin)');
[$c, $page] = $gp('admin', '/agent/rmm_schedules.php?new=1');
$ok($c === 200 && str_contains($page, 'id="sch-form"') && str_contains($page, '*/15 * * * *') && str_contains($page, '30 2 * * *') && str_contains($page, '0 6 * * 1-5') && str_contains($page, 'name="interval_unit"') && str_contains($page, 'name="overlap"')
    && str_contains($page, 'name="expires_h"') && str_contains($page, 'name="jitter_min"') && str_contains($page, 'data-rmm-when="#sch_script=' . $sid . '"'), 'schedule form: script, pinned version, parameters, target, interval or cron with examples, start, spread, overlap, expiry, timeout, enabled, confirm');
$ok(str_contains($page, 'The current version is 2') && str_contains($page, 'placeholder="{{field.name}}"'), 'schedule form: current version stated; a secret parameter takes a placeholder');
foreach (['tech', 'viewer', 'moduleonly', 'rebootonly'] as $who) {
    [$c, $page] = $gp($who, '/agent/rmm_schedules.php?new=1');
    $ok($c === 200 && !str_contains($page, 'id="sch-form"') && str_contains($page, 'Only administrators'), "$who: the schedule form is a notice");
    [$c, $page] = $gp($who, '/agent/rmm_schedules.php');
    $ok($c === 200 && !str_contains($page, 'New schedule'), "$who: the schedule list has no New schedule button");
}
$cnt = (int) $one("SELECT COUNT(*) FROM rmm_schedules");
[$c, $body] = $pf('tech', ['action' => 'save_schedule', 'name' => 'Tech made', 'library_script_id' => $sid, 'script_version' => '2', 'tgt_type' => 'client', 'tgt_id_client' => '2', 'kind' => 'interval', 'interval_value' => '1', 'interval_unit' => 'hour', 'overlap' => 'skip', 'param' => ['greeting' => 'x']], '/agent/rmm_schedules.php');
$ok((int) $one("SELECT COUNT(*) FROM rmm_schedules") === $cnt && str_contains($body, 'role'), 'a technician\'s forged schedule post is refused by Core (administrators only)');
// validation messages
$schPost = fn(array $over = []) => $over + ['action' => 'save_schedule', 'name' => 'Hourly hello', 'library_script_id' => $sid, 'script_version' => '2', 'tgt_type' => 'client', 'tgt_id_client' => '2', 'kind' => 'interval', 'interval_value' => '1', 'interval_unit' => 'hour',
    'start_in_min' => '0', 'jitter_min' => '0', 'overlap' => 'skip', 'enabled' => '1', 'param' => ['greeting' => 'scheduled']];
[$c, $body] = $pf('admin', $schPost(['interval_value' => '0']), '/agent/rmm_schedules.php?new=1');
$ok(str_contains($body, 'interval_s is 60 to') && (int) $one("SELECT COUNT(*) FROM rmm_schedules") === $cnt, 'an interval under a minute is refused with Core\'s words');
[$c, $body] = $pf('admin', $schPost(['kind' => 'cron', 'cron' => '61 * * * *']), '/agent/rmm_schedules.php?new=1');
$ok(str_contains($body, 'Cron minute') && (int) $one("SELECT COUNT(*) FROM rmm_schedules") === $cnt, 'a bad cron expression is refused with Core\'s words');
[$c, $body] = $pf('admin', $schPost(['param' => ['greeting' => 'x', 'token' => $SECRET]]), '/agent/rmm_schedules.php?new=1');
$ok(str_contains($body, 'must be a {{field.name}} placeholder') && !str_contains($body, $SECRET) && (int) $one("SELECT COUNT(*) FROM rmm_schedules") === $cnt, 'a literal secret in a schedule is refused and not stored or shown');
[$c, $body] = $pf('admin', $schPost(['name' => '']), '/agent/rmm_schedules.php?new=1');
$ok(str_contains($body, 'needs a name'), 'a schedule without a name is refused');
// create the schedule: next_run_at is the start
[$c, $body, $loc] = $pf('admin', $schPost(), '/agent/rmm_schedules.php?new=1');
$schId = (int) $one("SELECT schedule_id FROM rmm_schedules WHERE name='Hourly hello'");
$sch = $db->query("SELECT * FROM rmm_schedules WHERE schedule_id=$schId")->fetch_assoc();
$ok($schId > 0 && str_contains($loc, 'schedule_id=' . $schId) && str_contains($body, 'Schedule saved.') && (int) $sch['interval_s'] === 3600 && (int) $sch['script_version'] === 2 && $sch['target_type'] === 'client' && (int) $sch['target_id'] === 2 && $sch['approved_at'] !== null && (int) $sch['enabled'] === 1, 'schedule created: hourly, pinned to version 2, client 2, approved, enabled');
$ok(json_decode($sch['params_json'], true) === ['greeting' => 'scheduled'], 'schedule parameters stored as an object');
[$c, $page] = $gp('admin', '/agent/rmm_schedules.php');
$ok(str_contains($page, 'Hourly hello') && str_contains($page, 'Every 1 hour') && str_contains($page, 'Department Dept B') && str_contains($page, 'Active') && str_contains($page, 'Say hello</a> v2') && str_contains($page, 'Pause') && str_contains($page, 'Delete'), 'schedule list: name, script and version, target, cadence in words, state, actions');
// pause / resume
[$c, $body] = $pf('admin', ['action' => 'toggle_schedule', 'schedule_id' => $schId, 'enabled' => '0'], '/agent/rmm_schedules.php');
$ok(str_contains($body, 'Schedule paused.') && str_contains($body, 'Paused') && (int) $one("SELECT enabled FROM rmm_schedules WHERE schedule_id=$schId") === 0 && str_contains($body, 'not while paused'), 'pause: the state shows Paused and there is no next run');
[$c, $body] = $pf('admin', ['action' => 'toggle_schedule', 'schedule_id' => $schId, 'enabled' => '1'], '/agent/rmm_schedules.php');
$ok(str_contains($body, 'Schedule resumed.') && (int) $one("SELECT enabled FROM rmm_schedules WHERE schedule_id=$schId") === 1, 'resume');
// a newer script version than the pinned one
[$c, $body] = $pf('admin', $editPost($bashV2 . "echo three\n"), "/agent/rmm_script_library.php?script_id=$sid");
[$c, $page] = $gp('admin', "/agent/rmm_schedules.php?schedule_id=$schId");
$ok(str_contains($page, 'A newer version (3) exists. This schedule keeps running version 2'), 'schedule detail: warns that a newer script version exists');
[$c, $page] = $gp('admin', "/agent/rmm_schedules.php?schedule_id=$schId&edit=1");
$ok(str_contains($page, 'A newer version (3) exists') && str_contains($page, 'value="hourly hello"') === false && str_contains($page, 'value="Hourly hello"') && str_contains($page, 'value="scheduled"'), 'schedule edit form: warns about the newer version and is filled in');
// editing keeps the next run unless the timing changes
$nextBefore = $one("SELECT next_run_at FROM rmm_schedules WHERE schedule_id=$schId");
[$c, $body] = $pf('admin', $schPost(['schedule_id' => $schId, 'start_in_min' => '', 'jitter_min' => '5', 'name' => 'Hourly hello']), "/agent/rmm_schedules.php?schedule_id=$schId&edit=1");
$ok(str_contains($body, 'Schedule saved.') && (int) $one("SELECT jitter_s FROM rmm_schedules WHERE schedule_id=$schId") === 300 && $one("SELECT next_run_at FROM rmm_schedules WHERE schedule_id=$schId") === $nextBefore, 'editing the jitter keeps the next run time (the timing did not change)');

// ---- housekeeping turns the due slot into one job per device; history appears on the page
$q("UPDATE endpoint_agent_jobs SET state='cancelled', finished_at=UTC_TIMESTAMP() WHERE device_id=$L AND state='queued'");
$q("UPDATE rmm_schedules SET next_run_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE schedule_id=$schId");
$hk = $rmm->housekeeping()->run();
$jobsSch = (int) $one("SELECT COUNT(*) FROM rmm_job_extra WHERE schedule_id=$schId");
$ok($jobsSch === 1 && (int) ($hk['jobs_created'] ?? 1) >= 0, 'housekeeping: the due slot made exactly one job (one device in the department): ' . $jobsSch);
$rh = $rmm->housekeeping()->run();
$ok((int) $one("SELECT COUNT(*) FROM rmm_job_extra WHERE schedule_id=$schId") === 1, 'a second housekeeping pass does not make a second job for the same slot');
[$c, $page] = $gp('admin', "/agent/rmm_schedules.php?schedule_id=$schId");
$ok($c === 200 && str_contains($page, 'Result history per device') && str_contains($page, 'LNX1') && str_contains($page, 'Queued') && str_contains($page, 'Last runs') && str_contains($page, 'id="sch_hdev"'), 'schedule detail: the per-device history lists LNX1 with the job state; last runs and a device filter');
[$c, $page] = $gp('admin', "/agent/rmm_schedules.php?schedule_id=$schId&device_id=$Wd");
$ok(str_contains($page, 'No device has run it yet'), 'schedule detail: filtering to a device that never ran it shows nothing');
[$c, $page] = $gp('admin', "/agent/rmm_schedules.php");
$ok(str_contains($page, 'job') && preg_match('/1 job/', $page) === 1, 'schedule list: last run summary');
// finish the job so overlap does not apply
$q("UPDATE endpoint_agent_jobs SET state='succeeded', exit_code=0, started_at=UTC_TIMESTAMP(), finished_at=UTC_TIMESTAMP() WHERE job_id IN (SELECT job_id FROM rmm_job_extra WHERE schedule_id=$schId)");
[$c, $page] = $gp('admin', "/agent/rmm_schedules.php?schedule_id=$schId");
$ok(str_contains($page, 'Succeeded'), 'history shows the finished state');

// ---- the maintenance window gate (alerting on): the script is held for a device in a suppress window and runs after it
$ok($rmm->featureOn('alerting') && $rmm->featureOn('scripts'), 'alerting and scripts are both on');
$w = $rmm->alertingActions()->createWindow($admin, ['name' => 'Patch night', 'mode' => 'suppress', 'scope_type' => 'device', 'scope_id' => $L, 'kind' => 'once', 'starts_at' => gmdate('Y-m-d H:i', time() - 600), 'ends_at' => gmdate('Y-m-d H:i', time() + 3600)]);
$winId2 = (int) ($w->data['window']['window_id'] ?? 0);
$ok($w->ok && $winId2 > 0, 'a suppress maintenance window is open for LNX1 (' . $w->message . ')');
$jobsBefore = (int) $one("SELECT COUNT(*) FROM rmm_job_extra WHERE schedule_id=$schId");
$q("UPDATE rmm_schedules SET jitter_s=0, next_run_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 MINUTE) WHERE schedule_id=$schId");
$q("UPDATE rmm_schedule_runs SET state='done', finished_at=UTC_TIMESTAMP() WHERE schedule_id=$schId AND state='running'");
$rmm->housekeeping()->run();
$ok((int) $one("SELECT COUNT(*) FROM rmm_job_extra WHERE schedule_id=$schId") === $jobsBefore, 'maintenance window open: the scheduled script is held back (no job for LNX1)');
$rmm->housekeeping()->run();
$ok((int) $one("SELECT COUNT(*) FROM rmm_job_extra WHERE schedule_id=$schId") === $jobsBefore, 'still held on the next pass while the window is open');
$run = $db->query("SELECT * FROM rmm_schedule_runs WHERE schedule_id=$schId ORDER BY run_id DESC LIMIT 1")->fetch_assoc();
$ok($run && $run['state'] === 'running', 'the run stays open (waiting), not closed');
[$c, $page] = $gp('admin', "/agent/rmm_schedules.php?schedule_id=$schId");
$ok(str_contains($page, 'Skipped: maintenance window'), 'the schedule page has a column for runs held by a maintenance window');
$uw = $rmm->alertingActions()->updateWindow($admin, $winId2, ['starts_at' => gmdate('Y-m-d H:i', time() - 7200), 'ends_at' => gmdate('Y-m-d H:i', time() - 60)]);
$ok($uw->ok, 'the window is ended (' . $uw->message . ')');
$hk2 = $rmm->housekeeping()->run();
$ok((int) $one("SELECT COUNT(*) FROM rmm_job_extra WHERE schedule_id=$schId") === $jobsBefore + 1, 'after the window ended the held script ran on LNX1 (one job)');
[$c, $page] = $gp('admin', "/agent/rmm_schedules.php?schedule_id=$schId");
$ok(substr_count($page, 'LNX1') >= 2, 'the history lists both runs for LNX1');

// ---- schedules that need approval
[$c, $body, $loc] = $pf('admin', $schPost(['name' => 'Needs approval sched', 'library_script_id' => $aid, 'script_version' => '1', 'param' => ['who' => 'cron']]), '/agent/rmm_schedules.php?new=1');
$schA = (int) $one("SELECT schedule_id FROM rmm_schedules WHERE name='Needs approval sched'");
$sa = $db->query("SELECT * FROM rmm_schedules WHERE schedule_id=$schA")->fetch_assoc();
$ok($schA > 0 && $sa['approved_at'] === null && $sa['approval_id'] !== null && str_contains($loc, '/agent/rmm_approvals.php') && str_contains($body, 'does not run until a second person approves'), 'a schedule of a script that needs approval is saved but waits; the person lands on the approval');
$sapp = (int) $sa['approval_id'];
$ok($one("SELECT kind FROM rmm_approvals WHERE approval_id=$sapp") === 'schedule' && $one("SELECT state FROM rmm_approvals WHERE approval_id=$sapp") === 'pending_approval', 'the approval is of kind schedule, pending');
[$c, $page] = $gp('admin', '/agent/rmm_schedules.php');
$ok(str_contains($page, 'Waiting for approval') && str_contains($page, 'approval-' . $sapp), 'schedule list: "Waiting for approval" with a link to the request');
[$c, $page] = $gp('admin', "/agent/rmm_schedules.php?schedule_id=$schA");
$ok(str_contains($page, 'See the approval request') && str_contains($page, 'after it is approved'), 'schedule detail: says it waits, links the request');
$q("UPDATE rmm_schedules SET next_run_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE schedule_id=$schA");
$rmm->housekeeping()->run();
$ok((int) $one("SELECT COUNT(*) FROM rmm_job_extra WHERE schedule_id=$schA") === 0, 'an unapproved schedule creates no job even when due');
[$c, $page] = $gp('admin', '/agent/rmm_approvals.php?kind=schedule');
$ok(str_contains($page, 'Schedule') && str_contains($page, 'Needs approval sched'), 'the approvals queue lists the schedule request');
$q("UPDATE settings SET config_rmm_approve_scripts_lvl3 = 1 WHERE company_id = 1");
rivetRmmForgetAccess();
$api = $apiAs('admin', 'POST', ['approvals', $sapp, 'approve'], [], ['note' => '']);
$ok($api['status'] === 403 && $api['code'] === 'own_request', 'the administrator who saved the schedule cannot approve it');
[$c, $body] = $pf('tech', ['action' => 'decide_approval', 'approval_id' => $sapp, 'decision' => 'approve'], '/agent/rmm_approvals.php');
$ok(str_contains($body, 'Approved. The schedule can run now.') && $one("SELECT approved_at IS NOT NULL FROM rmm_schedules WHERE schedule_id=$schA") === '1', 'another approver approves the schedule: it may run now');
$rmm->housekeeping()->run();
$ok((int) $one("SELECT COUNT(*) FROM rmm_job_extra WHERE schedule_id=$schA") === 1, 'once approved the due schedule makes its job');
$q("UPDATE settings SET config_rmm_approve_scripts_lvl3 = 0 WHERE company_id = 1");
rivetRmmForgetAccess();
// delete
[$c, $page] = $gp('admin', "/agent/rmm_schedules.php");
$ok(str_contains($page, 'data-rmm-confirm="Delete the schedule') , 'delete asks for confirmation (in-page dialog)');
[$c, $body] = $pf('admin', ['action' => 'delete_schedule', 'schedule_id' => $schA], '/agent/rmm_schedules.php');
$ok(str_contains($body, 'Schedule deleted.') && (int) $one("SELECT COUNT(*) FROM rmm_schedules WHERE schedule_id=$schA") === 0, 'delete removes the schedule');
[$c, $body] = $pf('tech', ['action' => 'delete_schedule', 'schedule_id' => $schId], '/agent/rmm_schedules.php');
$ok((int) $one("SELECT COUNT(*) FROM rmm_schedules WHERE schedule_id=$schId") === 1, 'a technician cannot delete a schedule');
// cron schedule and next-run display
[$c, $body] = $pf('admin', $schPost(['name' => 'Weekday cron', 'kind' => 'cron', 'cron' => '0 6 * * 1-5', 'start_in_min' => '']), '/agent/rmm_schedules.php?new=1');
$cr = (int) $one("SELECT schedule_id FROM rmm_schedules WHERE name='Weekday cron'");
[$c, $page] = $gp('admin', '/agent/rmm_schedules.php');
$ok($cr > 0 && str_contains($page, 'Every Monday to Friday at 06:00 UTC') && str_contains($page, 'cron 0 6 * * 1-5'), 'cron schedule: shown in words with the expression');
// other department
[$c, $page] = $gp('deptb', '/agent/rmm_schedules.php');
$ok($c === 200 && str_contains($page, 'Hourly hello') , 'deptb sees the schedule that targets its own department');
[$c, $body] = $pf('admin', $schPost(['name' => 'Dept A only', 'library_script_id' => $winId, 'script_version' => '1', 'tgt_type' => 'device', 'tgt_id_device' => $Wd, 'kind' => 'interval', 'param' => [], 'confirm' => '1']), '/agent/rmm_schedules.php?new=1');
$sdA = (int) $one("SELECT schedule_id FROM rmm_schedules WHERE name='Dept A only'");
$ok($sdA > 0, 'a schedule for a Dept A device exists (' . substr(strip_tags($body), 0, 0) . ')');
[$c, $page] = $gp('deptb', '/agent/rmm_schedules.php');
$ok(!str_contains($page, 'Dept A only') && str_contains($page, 'not shown to you'), 'deptb does not see the Dept A schedule, and is told some are hidden');
[$c, $page] = $gp('deptb', "/agent/rmm_schedules.php?schedule_id=$sdA");
$ok(str_contains($page, 'Schedule not found') && !str_contains($page, 'Dept A only'), 'deptb gets "not found" for it (same as a missing one)');

// ============================================================ hostile text everywhere, secrets nowhere
$allPages = ['/agent/rmm_script_library.php?retired=1', "/agent/rmm_script_library.php?script_id=$sid", "/agent/rmm_script_library.php?script_id=$sid&from=1&to=3", '/agent/rmm_schedules.php', "/agent/rmm_schedules.php?schedule_id=$schId", '/agent/rmm_approvals.php?state=all', '/agent/asset_details.php?asset_id=' . $A['LNX1']];
$leak = false;
foreach ($allPages as $pth) { foreach (['admin', 'tech'] as $who) { [, $b] = $gp($who, $pth); if (str_contains($b, $SECRET)) { $leak = true; } } }
$ok(!$leak, 'the secret parameter value appears on no page for the administrator or the technician');
$ok(!str_contains($dump(['rmm_schedules', 'rmm_approvals', 'rmm_job_extra', 'endpoint_agent_jobs', 'audit_log', 'logs']), $SECRET), 'the secret value is in no audit, log, approval, schedule or job row');

// ============================================================ switches: sub-switch off, module off
$features = $rmm->settings()->features();
$features['scripts'] = false;
$rmm->admin()->saveSettings($admin, ['features_json' => $features]); $fresh();
$cnt = (int) $one("SELECT COUNT(*) FROM rmm_scripts_v2");
foreach (['/agent/rmm_script_library.php', '/agent/rmm_schedules.php', '/agent/rmm_approvals.php'] as $pth) {
    [$c, $page] = $gp('admin', $pth);
    $ok($c === 403 && str_contains($page, 'switched off') && !str_contains($page, 'Say hello'), "scripts switch off: $pth is the switched-off notice");
}
[$c, $body] = $pf('admin', ['action' => 'save_script', 'name' => 'Off made', 'language' => 'bash', 'body' => 'echo off'], '/agent/rmm_script_library.php');
$ok($scriptId('Off made') === 0 && (int) $one("SELECT COUNT(*) FROM rmm_scripts_v2") === $cnt && str_contains($body, 'switched off'), 'scripts switch off: the handler refuses and nothing is written');
[$c, $page] = $gp('admin', '/agent/asset_details.php?asset_id=' . $A['LNX1']);
$ok(!str_contains($page, 'id="rmm-library-run"'), 'scripts switch off: no run card on the asset page');
$vmOff = $panel('LNX1');
$ok($vmOff['features']['scripts'] === false && rivetRmmScrDeviceSection($vmOff, $db, 'tok') === '', 'scripts switch off: the hook draws nothing');
$ok(array_filter(rivetRmmAutoNav(), fn($n) => str_contains($n['href'], 'script_library')) === [], 'scripts switch off: the menu entries are gone');
$features['scripts'] = true;
$rmm->admin()->saveSettings($admin, ['features_json' => $features]); $fresh();
[$c, $page] = $gp('admin', '/agent/rmm_script_library.php');
$ok($c === 200 && str_contains($page, 'Say hello'), 'switched on again: the data is all still there');
$vmOn = $panel('LNX1', 'admin');
$rmm->admin()->disable($admin); $fresh();
$a = $questions();
$h1 = rivetRmmScrDeviceSection($vmOn, $db, 'tok');
$nav = rivetRmmAutoNav();
$b = $questions();
$ok($h1 === '' && $nav === [] && $b - $a === $overhead, 'module OFF: the asset hook and the menu answer nothing and cost zero statements (' . ($b - $a - $overhead) . ' extra)');
foreach (['/agent/rmm_script_library.php', '/agent/rmm_schedules.php', '/agent/rmm_approvals.php'] as $pth) {
    [$c, $page] = $gp('admin', $pth);
    $ok($c === 403 && str_contains($page, 'RMM module is switched off'), "module off: $pth is the 403 notice");
}
[$c, $body] = $pf('admin', ['action' => 'save_script', 'name' => 'Module off made', 'language' => 'bash', 'body' => 'echo off'], '/agent/rmm_script_library.php');
$ok($scriptId('Module off made') === 0 && str_contains($body, 'switched off'), 'module off: the handler refuses');
$rmm->admin()->enable($admin); $fresh();
[$c, $page] = $gp('admin', '/agent/rmm_approvals.php');
$ok($c === 200, 'module on again: the pages work');
