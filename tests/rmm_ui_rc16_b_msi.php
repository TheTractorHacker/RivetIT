<?php
/*
 * Fragment of tests/rmm_ui.php (RivetCore 1.0.0-rc.16 UI, shipped in RivetIT 26.10.42): the MSI installer. Shares that file's variables.
 *
 *   1. Administration > Endpoint agent > MSI template: the card (limits, the prominent UNVERIFIED-on-Windows warning, the unsigned note, the docs link), the upload through
 *      admin/post.php with the REAL template built by Core's `make msi` (RC16_REAL_MSI = the path of the file `make msi` wrote to dist/; Core's wixl fixture, 11 KB, when unset),
 *      its refusals, make current / deactivate / reactivate, and the CLI path scripts/endpoint_agent_publish.php --msi.
 *   2. The Add device dialog (rivetRmmInstallerContext / Modal) and agent/post/rmm_installer.php: the Format selector, the download with format=msi: an OLE compound file with the
 *      Core InstallerStamp trailer decoding to the payload, the stored template untouched, no token left behind by a refusal, client scoping.
 *   3. The Deployment card of the Administration page: format msi, the deployment commands panel's msi block.
 *
 * The stamped copies are written to RC16_STAMP_DIR so that Core's Go trailer reader can be run over them (see the release notes). The module is ON here and ends ON; the
 * templates this fragment published are removed again.
 */

use RivetCore\Rmm\Installer\InstallerStamp;

$mRealPath = (string) getenv('RC16_REAL_MSI');
$mRealIsReal = $mRealPath !== '' && is_file($mRealPath);
if (!$mRealIsReal) {
    $mRealPath = $root . '/vendor/rivet/rivet-core/tests/Fixtures/rmm/msi/template-x64.msi';
}
$mReal = (string) file_get_contents($mRealPath);
$ok(strlen($mReal) > 8192 && str_starts_with($mReal, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"), 'the template under test is an OLE compound file (' . strlen($mReal) . ' bytes, ' . ($mRealIsReal ? 'built by Core\'s make msi' : 'Core\'s wixl fixture') . ')');
$mStampDir = getenv('RC16_STAMP_DIR') ?: sys_get_temp_dir() . '/rc16_stamped';
@mkdir($mStampDir, 0775, true);
foreach (glob("$mStampDir/*.msi") ?: [] as $f) { @unlink($f); }

$mBig = ea_start_php($root . '/tests/mobile_api_router.php', ['RIVETIT_WEBHOOK_ALLOW_PRIVATE' => '1'], ["session.save_path=$sdir", 'upload_max_filesize=16M', 'post_max_size=32M', 'memory_limit=512M']);   // a server whose limits accept the real template (PHP's default 2M does not)
$wbBig = 'http://127.0.0.1:' . $mBig['port'];
$mSession = fn (string $who) => $SS[$who];
/** A multipart POST to /admin/post.php with a file. @return array{0:int,1:string} */
$mUpload = function (string $base, string $who, string $filePath, array $f, string $field = 'msi_template', string $name = 'rivetit-agent-windows-amd64.msi') use ($SS): array {
    $post = $f + ['csrf_token' => 'csrftok1', $field => new CURLFile($filePath, 'application/octet-stream', $name)];
    $ch = curl_init($base . '/admin/post.php');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post, CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIE => 'PHPSESSID=' . $SS[$who], CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => ['Referer: ' . $base . '/admin/settings_endpoint_agent.php']]);
    $raw = (string) curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return [$code, $raw];
};
$mTmp = function (string $bytes): string { $p = tempnam(sys_get_temp_dir(), 'msi'); file_put_contents($p, $bytes); return $p; };
$mPage = fn (string $who = 'admin', ?string $base = null) => web($base ?? $wb, 'GET', '/admin/settings_endpoint_agent.php', $SS[$who]);
$mCard = function (string $html): string { $a = strpos($html, 'id="msi-templates"'); $b = $a === false ? false : strpos($html, 'id="deployment"', $a); return $a === false ? '' : substr($html, $a, $b === false ? 30000 : $b - $a); };
$mAdm = fn (array $f, string $who = 'admin') => web($wbBig, 'POST', '/admin/post.php', $SS[$who], $f + ['csrf_token' => 'csrftok1'], ['Referer: ' . $wbBig . '/admin/settings_endpoint_agent.php']);
$mRows = fn () => $rows('SELECT * FROM endpoint_agent_msi_templates ORDER BY template_id');
$mAudit = fn (string $like) => (int) $one("SELECT COUNT(*) FROM logs WHERE log_description LIKE '" . $esc($like) . "'");
$mTokens = fn () => (int) $one('SELECT COUNT(*) FROM endpoint_agent_enrollment_tokens');
$q('DELETE FROM endpoint_agent_msi_templates');
$mServiceUrl0 = (string) ($rmm->settings()->get(true)['service_url'] ?? '');
$rmm->settings()->set(['service_url' => 'https://rmm.example.test']);   // an installer needs an https service address (put back at the end)
$fresh();

// ============================================================ 1. Administration > MSI template
[$c, $page] = $mPage();
$card = $mCard($page);
$ok($c === 200 && $card !== '', 'the Administration page has the MSI template card');
$ok(str_contains($card, 'id="msi-unverified"') && str_contains($card, 'alert alert-info') && str_contains($card, 'role="note"') && str_contains($card, 'unsigned') && str_contains($card, 'SmartScreen') && str_contains($card, 'Upgrade, repair and uninstall are not yet tested') && !str_contains($card, 'UNVERIFIED on Windows'), 'the card shows the short, neutral unsigned/untested-paths note, not the old UNVERIFIED banner');
$ok(str_contains($card, 'Only x64 (amd64) is built') && str_contains($card, 'ARM64 PCs use the .exe') && str_contains($card, 'OLE compound file') && str_contains($card, 'Intel</code> is refused') && str_contains($card, 'declared'), 'the card states the amd64-only build and the checks (OLE file, platform from the package, Intel refused, declared)');
$ok(str_contains($card, 'Largest accepted upload now: <strong>2 MiB</strong>') && str_contains($card, 'upload_max_filesize') && str_contains($card, 'scripts/endpoint_agent_publish.php rivetit-agent-windows-amd64.msi --msi --version'), 'the card shows the upload limit (here PHP\'s 2 MB) and the CLI path for larger uploads');
$ok(str_contains($card, 'name="upload_msi_template"') && str_contains($card, 'name="msi_template"') && str_contains($card, 'accept=".msi"') && str_contains($card, 'enctype="multipart/form-data"') && str_contains($card, 'name="csrf_token"') && str_contains($card, 'for="msi_file"') && str_contains($card, 'No MSI template published yet'), 'the upload form: labelled file input, version, architecture, CSRF, an empty list that says what it means');
$ok(str_contains($page, 'id="dp_format"') && str_contains($page, 'data-msi-amd64="0"') && str_contains($page, 'data-msi-arm64="0"') && str_contains($page, 'No MSI template is published for x64 yet'), 'the Deployment card has the Format selector and says no template is published');

// a technician cannot publish (rmm.binary.publish is an administrator ability)
[$c] = $mUpload($wbBig, 'tech', $mRealPath, ['version' => '0.6.0', 'arch' => 'amd64', 'upload_msi_template' => '1', 'activate' => '1']);
$ok($mRows() === [], 'a technician cannot publish an MSI template (' . $c . ')');
// PHP's own limit: the real template is larger than upload_max_filesize=2M on the first server
if (strlen($mReal) > 2 * 1048576) {
    [$c] = $mUpload($wb, 'admin', $mRealPath, ['version' => '0.6.0', 'arch' => 'amd64', 'upload_msi_template' => '1', 'activate' => '1']);
    [, $pg] = $mPage();
        $pgn = str_replace('\\/', '/', $pg);   // the flash is handed to the page as a JSON string
    $ok($mRows() === [] && str_contains($pgn, 'larger than the limit of 2 MiB') && str_contains($pgn, 'scripts/endpoint_agent_publish.php --msi'), 'a file over PHP\'s upload_max_filesize is refused with the limit in words and the CLI path (nothing stored)');
}
// refusals: not an installer package, an exe, a stamped copy, a bad version, a contradicting architecture
$notMsi = $mTmp(str_repeat('MZ', 6000));
[$c] = $mUpload($wbBig, 'admin', $notMsi, ['version' => '0.6.0', 'arch' => 'amd64', 'upload_msi_template' => '1']);
[, $pg] = $mPage('admin', $wbBig);
$ok($mRows() === [], 'a file that is not a Windows Installer package is refused and nothing is stored');
unlink($notMsi);
$stamped0 = $mTmp($mReal . InstallerStamp::trailer(InstallerStamp::buildPayload(['installer_id' => 'i', 'server_url' => 'https://s.example.test', 'enrollment_token' => 'rvte1.x', 'department' => 'D', 'created_at' => gmdate('c'), 'expires_at' => gmdate('c', time() + 3600)])));
$mUpload($wbBig, 'admin', $stamped0, ['version' => '0.6.0', 'arch' => 'amd64', 'upload_msi_template' => '1']);
$ok($mRows() === [], 'a template that is already stamped with an enrollment payload is refused');
unlink($stamped0);
$mUpload($wbBig, 'admin', $mRealPath, ['version' => 'not a version', 'arch' => 'amd64', 'upload_msi_template' => '1']);
$ok($mRows() === [], 'a bad version is refused');
$mUpload($wbBig, 'admin', $mRealPath, ['version' => '0.6.0', 'arch' => 'arm64', 'upload_msi_template' => '1']);
$ok($mRows() === [], 'an x64 package declared as ARM64 is refused (the package says x64) and nothing is stored');

// the upload with the real template, made current
$auditBefore = $mAudit('%uploaded MSI template%');
[$c] = $mUpload($wbBig, 'admin', $mRealPath, ['version' => '0.6.0', 'arch' => 'amd64', 'upload_msi_template' => '1', 'activate' => '1']);
$tr = $mRows();
$ok(count($tr) === 1 && $tr[0]['version'] === '0.6.0' && $tr[0]['arch'] === 'amd64' && $tr[0]['sha256'] === hash('sha256', $mReal) && (int) $tr[0]['size_bytes'] === strlen($mReal) && (int) $tr[0]['is_current'] === 1 && (int) $tr[0]['active'] === 1, 'the template is stored: sha256 and size equal the file, current and active (' . $c . ')');
$ok($tr !== [] && $tr[0]['arch_source'] === 'summary', 'the platform was read from the package\'s summary information (arch_source ' . ($tr[0]['arch_source'] ?? '?') . ')');
$tid = (int) ($tr[0]['template_id'] ?? 0);
$stored = $rmm->binaryStore()->pathFor($tr[0] ?? ['storage_name' => 'x']);
$ok(is_file($stored) && hash_file('sha256', $stored) === hash('sha256', $mReal) && preg_match('~/bin_[0-9a-f]{32}\.bin$~', $stored) === 1, 'the stored file is byte for byte the upload, under a random name in the private directory');
$ok($mAudit('%uploaded MSI template 0.6.0 (amd64%') === $auditBefore + 1, 'the upload is audited');
[$c, $page] = $mPage('admin', $wbBig);
$card = $mCard($page);
$ok(str_contains($card, '>0.6.0<') && str_contains($card, hash('sha256', $mReal)) && str_contains($card, 'badge bg-success">Current<') && str_contains($card, 'the package') && str_contains($card, BinaryStoreHuman($mReal)), 'the list shows version, size, SHA-256, where the platform came from and the Current flag');
$ok(str_contains($page, 'data-msi-amd64="1"') && str_contains($page, 'data-msi-arm64="0"') && str_contains($page, 'Current x64 template: 0.6.0.'), 'the Deployment card now knows x64 has a template and ARM64 has none');
// uploading the identical file again is a no-op; a different file for the same version is refused
$mUpload($wbBig, 'admin', $mRealPath, ['version' => '0.6.0', 'arch' => 'amd64', 'upload_msi_template' => '1']);
$ok(count($mRows()) === 1, 'the identical file again is a no-op (one row)');
$other = $mTmp($mReal . str_repeat("\0", 512));
$mUpload($wbBig, 'admin', $other, ['version' => '0.6.0', 'arch' => 'amd64', 'upload_msi_template' => '1']);
$ok(count($mRows()) === 1 && hash_file('sha256', $stored) === hash('sha256', $mReal), 'a different file for an existing version and architecture is refused; the stored template is untouched');
unlink($other);

// make current / deactivate / reactivate
$mUpload($wbBig, 'admin', $mRealPath, ['version' => '0.6.1', 'arch' => 'amd64', 'upload_msi_template' => '1']);   // a second version (the same bytes are fine under another version)
$tr = $mRows();
$ok(count($tr) === 2 && (int) $tr[0]['is_current'] === 1 && (int) $tr[1]['is_current'] === 0, 'a second version is stored but not made current without the box');
$tid2 = (int) $tr[1]['template_id'];
$mAdm(['msi_template_action' => 'make_current', 'template_id' => $tid2], 'tech');
$ok((int) $one("SELECT is_current FROM endpoint_agent_msi_templates WHERE template_id=$tid2") === 0, 'a technician cannot change a template');
$mAdm(['msi_template_action' => 'make_current', 'template_id' => $tid2]);
$ok((int) $one("SELECT is_current FROM endpoint_agent_msi_templates WHERE template_id=$tid2") === 1 && (int) $one("SELECT is_current FROM endpoint_agent_msi_templates WHERE template_id=$tid") === 0, 'make current moves the flag (one current template per architecture)');
$mAdm(['msi_template_action' => 'deactivate', 'template_id' => $tid2]);
$ok((int) $one("SELECT active FROM endpoint_agent_msi_templates WHERE template_id=$tid2") === 0 && (int) $one("SELECT is_current FROM endpoint_agent_msi_templates WHERE template_id=$tid2") === 0 && is_file($rmm->binaryStore()->pathFor($mRows()[1])), 'deactivate: no longer used, no longer current, the file is kept');
[, $page] = $mPage('admin', $wbBig);
$card = $mCard($page);
$ok(str_contains($card, 'badge bg-secondary">Inactive<') && str_contains($card, 'value="activate"') && str_contains($card, 'data-ea-confirm="Deactivate this MSI template?') && str_contains($page, 'data-msi-amd64="0"'), 'the list shows Inactive with Reactivate; with no current template the Deployment card knows x64 has none');
$mAdm(['msi_template_action' => 'activate', 'template_id' => $tid2]);
$mAdm(['msi_template_action' => 'make_current', 'template_id' => $tid]);
$ok((int) $one("SELECT is_current FROM endpoint_agent_msi_templates WHERE template_id=$tid") === 1 && (int) $one("SELECT active FROM endpoint_agent_msi_templates WHERE template_id=$tid2") === 1, 'reactivate, and 0.6.0 current again');
$mAdm(['msi_template_action' => 'bogus', 'template_id' => $tid]);
$mAdm(['msi_template_action' => 'make_current', 'template_id' => 987654]);
$ok((int) $one("SELECT is_current FROM endpoint_agent_msi_templates WHERE template_id=$tid") === 1, 'an unknown action or template changes nothing');
$ok($mAudit('%ran \'make_current\' on MSI template%') >= 2 && $mAudit('%ran \'deactivate\' on MSI template%') === 1, 'the changes are audited');

// the CLI path
$cli = function (array $args): array {
    global $root;
    $cmd = array_merge([PHP_BINARY, "$root/scripts/endpoint_agent_publish.php"], $args);
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, "$root/scripts", getenv());
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);

    return [proc_close($p), trim($out), trim($err)];
};
[$rc, $out] = $cli([$mRealPath, '--msi', '--version', '0.6.0', '--arch', 'amd64']);
$ok($rc === 0 && str_starts_with($out, 'Already published MSI template 0.6.0 amd64') && str_contains($out, hash('sha256', $mReal)) && str_contains($out, '(current)'), 'CLI --msi: the same file again is "Already published" (' . $out . ')');
[$rc, $out] = $cli([$mRealPath, '--msi', '--version', '0.6.2', '--arch', 'amd64', '--activate']);
$ok($rc === 0 && str_starts_with($out, 'Published MSI template 0.6.2 amd64') && str_contains($out, '(platform from the summary)') && (int) $one("SELECT is_current FROM endpoint_agent_msi_templates WHERE version='0.6.2'") === 1, 'CLI --msi --activate: published and current (' . $out . ')');
$ok($mAudit('%CLI published MSI template 0.6.2%') === 1, 'CLI --msi: audited');
[$rc, , $err] = $cli([$mRealPath, '--msi', '--version', '0.6.3', '--arch', 'amd64', '--release', 'stable']);
$ok($rc === 1 && str_contains($err, 'do not apply to an MSI template') && (int) $one("SELECT COUNT(*) FROM endpoint_agent_msi_templates WHERE version='0.6.3'") === 0, 'CLI --msi with --release is refused: a template is never an agent update');
[$rc, , $err] = $cli([__FILE__, '--msi', '--version', '0.6.4', '--arch', 'amd64']);
$ok($rc === 1 && str_starts_with($err, 'Error:') && (int) $one("SELECT COUNT(*) FROM endpoint_agent_msi_templates WHERE version='0.6.4'") === 0, 'CLI --msi with a file that is not an installer package is refused (' . $err . ')');
[$rc, $out] = $cli(['--help']);
$ok($rc === 0 && str_contains($out, '<msi> --msi --version'), 'CLI --help names the --msi form');
$mAdm(['msi_template_action' => 'make_current', 'template_id' => $tid]);   // 0.6.0 current again for the rest
foreach ($mRows() as $t) { if ($t['version'] === '0.6.2') { @unlink($rmm->binaryStore()->pathFor($t)); } }   // the file the CLI stored goes with its row
$q("DELETE FROM endpoint_agent_msi_templates WHERE version IN ('0.6.2')");

// ============================================================ 2. Add device dialog and the download
rivetRmmForgetAccess();
$ctx = rivetRmmInstallerContext($db, 1, null, 1);
$ok($ctx !== null && $ctx['msi'] === ['amd64' => '0.6.0', 'arm64' => null], 'the installer context knows the current MSI template per architecture: ' . json_encode($ctx['msi'] ?? null));
$modal = rivetRmmInstallerModal($ctx);
$ok(str_contains($modal, 'name="format"') && preg_match('/name="format" id="rmm-inst-format-exe" value="exe" checked/', $modal) === 1 && str_contains($modal, 'value="msi"') && str_contains($modal, 'data-have-msi-amd64="1"') && str_contains($modal, 'data-have-msi-arm64="0"'), 'the dialog has Format: exe (checked, the default) | msi, and tells the script which architectures have a template');
$ok(str_contains($modal, 'id="rmm-inst-msi-warn"') && str_contains($modal, 'unsigned: SmartScreen') && str_contains($modal, 'Upgrade, repair and uninstall are not yet tested') && !str_contains($modal, 'Not yet verified on Windows') && str_contains($modal, 'id="rmm-inst-silent-msi"') && str_contains($modal, 'msiexec /i') && str_contains($modal, 'id="rmm-inst-format-link"') && str_contains($modal, '#msi-templates') && str_contains($modal, 'aria-describedby="rmm-inst-format-help"') && str_contains($modal, 'id="rmm-inst-format-help"'), 'the dialog carries the short unsigned/untested-paths note (not the old unverified banner), the msiexec hint, the link for administrators and an accessible description');
$ctxTech = rivetRmmInstallerContext($db, $USER['tech'], null, 1);
$ok($ctxTech === null || ($ctxTech['admin'] === false && !str_contains(rivetRmmInstallerModal($ctxTech), 'id="rmm-inst-format-link"')), 'a non-administrator gets no link to the MSI card');
$js = (string) file_get_contents($root . '/js/rmm_installer.js');
$ok(str_contains($js, 'haveMsi') && str_contains($js, "msiRadio.disabled = !msiOk") && str_contains($js, "arch === 'arm64' ? 'MSI is not available for ARM64") && str_contains($js, 'no MSI template is published for x64') && str_contains($js, "formatValue() === 'msi'") && !preg_match('/\bonclick=|\bonchange=/', $js), 'the script disables the MSI format with a reason (none published, ARM64) and has no inline handlers');
$js2 = (string) file_get_contents($root . '/js/rmm_mesh_push.js');
$ok(!preg_match('/\bwindow\.(confirm|alert|prompt)\(|[^.\w](confirm|alert|prompt)\(/', $js2), 'the push script uses no window.confirm / alert / prompt');
// the installer page in a real request (fleet) draws the dialog with the format selector
[$c, $body] = web($wb, 'GET', '/agent/rmm_fleet.php', $SS['admin']);
$ok($c === 200 && str_contains($body, 'id="rmm-inst-format-msi"') && str_contains($body, 'data-have-msi-amd64="1"'), 'the Agent Fleet page draws the dialog with the Format selector');
[$c, $body] = web($wb, 'GET', '/agent/clients.php', $SS['admin']);

// the download endpoint. A (fake) agent binary is published so the .exe path can be compared; removed again at the end.
$mFakePe = function (int $machine, string $fill): string {
    $b = str_pad('MZ' . str_repeat("\0", 0x3A) . pack('V', 128), 128, "\0") . "PE\0\0" . pack('v', $machine) . pack('v', 3) . str_repeat("\0", 12) . pack('v', 0xE0) . pack('v', 0x0022);
    $need = 8192 - strlen($b);

    return $b . substr(str_repeat(hash('sha256', $fill . $machine, true), intdiv($need, 32) + 1), 0, $need);
};
$q("DELETE FROM endpoint_agent_binaries");
$fakeExe = $mFakePe(0x8664, 'rc16');
$fp = $mTmp($fakeExe);
$pubRes = $rmm->binaryStore()->publish($fp, '1.0.0', 'amd64', 1, ['activate' => true]);
unlink($fp);
$ok(($pubRes['ok'] ?? false) === true, 'a (fake) x64 agent binary is published so the .exe path can be compared');
$dl = function (string $who, array $f) use ($wb, $SS): array {
    return web($wb, 'POST', '/agent/post/rmm_installer.php', $SS[$who], $f + ['csrf_token' => 'csrftok1', 'client_id' => 1, 'arch' => 'amd64', 'ring' => 'stable', 'ttl_hours' => 24, 'max_uses' => 1, 'action' => 'download'], ['Accept: application/octet-stream, application/json']);
};
$t0 = $mTokens();
[$c, $body, $hd] = $dl('admin', ['format' => 'zip']);
$ok($c === 422 && str_contains($body, 'Choose the installer format') && $mTokens() === $t0, 'an unknown format is refused before any token exists');
[$c, $body] = $dl('admin', ['format' => 'msi', 'arch' => 'arm64']);
$ok($c === 422 && str_contains($body, 'No MSI template is published for arm64') && $mTokens() === $t0, 'ARM64 + MSI: refused with the reason, no token left behind (' . trim(strip_tags(substr($body, 0, 150))) . ')');
$mSet = $rmm->settings();
[$c, $body, $hd] = $dl('admin', []);   // no format given: the .exe path, byte for byte as before
$ex = InstallerStamp::read($body);
$ok($c === 200 && str_starts_with($body, 'MZ') && $ex !== null && substr($body, 0, $ex['exe_length']) === $fakeExe && stripos($hd, 'RivetIT-Agent-Setup-') !== false && $mTokens() === $t0 + 1, 'no format given: the stamped .exe, as before (MZ header, the published binary, RivetIT-Agent-Setup-... name)');
[$c, $body, $hd] = $dl('admin', ['format' => 'exe']);
$ex2 = InstallerStamp::read($body);
$ok($c === 200 && str_starts_with($body, 'MZ') && $ex2 !== null && substr($body, 0, $ex2['exe_length']) === $fakeExe, 'format=exe is the same .exe path');
$t0 = $mTokens();
// deactivate the only template: an MSI installer cannot be built, and no token is left
$mAdm(['msi_template_action' => 'deactivate', 'template_id' => $tid]);
[$c, $body] = $dl('admin', ['format' => 'msi']);
$ok($c === 422 && str_contains($body, 'No MSI template is published for amd64') && $mTokens() === $t0, 'no usable template: 422 with the reason, no token');
$ctx2 = rivetRmmInstallerContext($db, 1, null, 1);
$ok($ctx2['msi'] === ['amd64' => null, 'arm64' => null], 'the dialog context sees no template once it is deactivated');
$mAdm(['msi_template_action' => 'activate', 'template_id' => $tid]);
$mAdm(['msi_template_action' => 'make_current', 'template_id' => $tid]);

[$c, $body, $hd] = $dl('admin', ['format' => 'msi', 'label' => 'rc16 msi test']);
$ok($c === 200 && str_starts_with($body, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"), 'the MSI download is an OLE compound file (D0CF11E0A1B11AE1) (' . $c . ', ' . strlen($body) . ' bytes)');
$ok(preg_match('/Content-Disposition: attachment; filename="RivetIT-Agent-[A-Za-z0-9._-]+-x64\.msi"/i', $hd) === 1 && stripos($hd, 'Content-Length: ' . strlen($body)) !== false, 'the file is named RivetIT-Agent-<department>-x64.msi and Content-Length is exact');
$read = InstallerStamp::read($body);
$ok($read !== null && $read['exe_length'] === strlen($mReal) && substr($body, 0, $read['exe_length']) === $mReal, 'the Core InstallerStamp trailer decodes and the bytes before it are the template, byte for byte');
$pl = $read['data'] ?? [];
$ok(($pl['department'] ?? '') === 'Dept A' && str_starts_with((string) ($pl['enrollment_token'] ?? ''), 'rvte1.') && str_starts_with((string) ($pl['server_url'] ?? ''), 'https://') && !empty($pl['installer_id']) && empty($pl['install_mesh_agent']), 'the payload names the department, carries an enrollment token and the https server address (no Mesh option)');
$ok(hash_file('sha256', $stored) === hash('sha256', $mReal) && filesize($stored) === strlen($mReal), 'the STORED template is untouched by the download');
$ok($mTokens() === $t0 + 1 && (int) $one("SELECT COUNT(*) FROM logs WHERE log_description LIKE '%created installer%msi%'") >= 1, 'one audited enrollment token was created for the MSI download');
file_put_contents("$mStampDir/stamped-dept-a.msi", $body);
copy($mRealPath, "$mStampDir/template.msi");
// the trailer is the exe installer's trailer: a second stamping of the same template gives a different payload (a new token), the same template bytes
[$c, $body2] = $dl('admin', ['format' => 'msi']);
$read2 = InstallerStamp::read($body2);
$ok($c === 200 && $read2 !== null && substr($body2, 0, $read2['exe_length']) === $mReal && ($read2['data']['enrollment_token'] ?? '') !== ($pl['enrollment_token'] ?? '') && ($read2['data']['installer_id'] ?? '') !== ($pl['installer_id'] ?? ''), 'a second download is a fresh token and installer id on the same template bytes');
// the Mesh option works the same: refused with Core's words when Mesh cannot support it, no token
$t1 = $mTokens();
[$c, $body3] = $dl('admin', ['format' => 'msi', 'install_mesh_agent' => '1']);
$ok($c === 422 && $mTokens() === $t1, 'MSI with the Mesh option while Mesh cannot support it: Core refuses and no token is created (' . trim(strip_tags(substr($body3, 0, 120))) . ')');
// client scoping: the Dept B user cannot get a Dept A installer, of either format
$t2 = $mTokens();
[$c, $body4] = $dl('deptb', ['format' => 'msi', 'client_id' => 1]);
[$c2] = $dl('deptb', ['client_id' => 1]);
$ok(in_array($c, [403, 404], true) && in_array($c2, [403, 404], true) && $mTokens() === $t2 && !str_starts_with($body4, "\xD0\xCF"), 'a Dept B user cannot get a Dept A installer in either format (' . $c . ', ' . $c2 . '), no token');
foreach (['viewer', 'rebootonly'] as $who) {
    [$c, $b] = $dl($who, ['format' => 'msi']);
    $ok(in_array($c, [403, 404], true) && !str_starts_with($b, "\xD0\xCF") && $mTokens() === $t2, "$who: no MSI installer (" . $c . ')');
}
// the Linux action ignores the format: it is always the exe's snippet
[$c, $b] = $dl('admin', ['action' => 'linux', 'format' => 'msi']);
$lj = json_decode($b, true);
[$c9, $b9] = $dl('admin', ['action' => 'linux']);
$lj9 = json_decode($b9, true);
$ok($c === 200 && ($lj['success'] ?? false) === true && str_contains((string) ($lj['command'] ?? ''), 'install-linux.sh') && !str_contains((string) ($lj['command'] ?? ''), 'msiexec') && $c9 === 200 && ($lj9['arch'] ?? '') === ($lj['arch'] ?? '?') && preg_replace('/rvte1\.\S+/', '', (string) $lj['command']) === preg_replace('/rvte1\.\S+/', '', (string) $lj9['command']), 'the Linux command is the same with and without the Format box (always the exe\'s snippet)');

// ============================================================ 3. the Deployment card and the deployment commands
$mAdm(['show_deploy_commands' => '1', 'client_id' => 1, 'arch' => 'amd64', 'ring' => 'stable', 'ttl_hours' => 24, 'max_uses' => 5, 'label' => 'msi cmds', 'location_id' => 0, 'format' => 'msi']);
[$c, $page] = $mPage('admin', $wbBig);
$dc = (function (string $html): string { $a = strpos($html, 'id="deploy-commands"'); return $a === false ? '' : substr($html, $a, 60000); })($page);
$ok($dc !== '' && str_contains($dc, 'Windows x64, MSI)') && str_contains($dc, 'id="deploy-msi-warning"') && str_contains($dc, 'Upgrade, repair and uninstall are not yet tested') && !str_contains($dc, 'Not yet verified on Windows'), 'the deployment commands panel for format msi opens with the short unsigned/untested-paths note');
$ok(preg_match('/id="ea_msi_msiexec"[^>]*>msiexec \/i &quot;RivetIT-Agent-[A-Za-z0-9._-]+-x64\.msi&quot; \/qn \/norestart \/l\*v/', $dc) === 1, 'the msiexec silent command for the stamped file');
$dcd = html_entity_decode($dc, ENT_QUOTES);
$ok(str_contains($dc, 'id="ea_msi_ps"') && str_contains($dcd, "format = 'msi'") && str_contains($dcd, '/api/v1/agent_installer') && str_contains($dcd, 'Start-Process -FilePath') && str_contains($dcd, 'rvte1.'), 'the PowerShell downloader (token-gated endpoint, format msi, msiexec, the token it holds)');
$ok(str_contains($dc, 'id="ea_msi_props"') && str_contains($dc, 'SERVER=') && str_contains($dc, 'ENROLL_TOKEN=') && str_contains($dc, 'CA_PEM_FILE=') && str_contains($dc, 'ENROLL_TOKEN_FILE'), 'the property form (SERVER, ENROLL_TOKEN, CA_PEM_FILE; ENROLL_TOKEN_FILE named)');
$ok(str_contains($dc, 'id="ea_msi_uninstall"') && str_contains($dc, 'REMOVE_MESHAGENT=1') && str_contains($dc, '{364538BF-62D7-4685-84C5-43B83B318852}') && str_contains($dc, 'RivetIT Agent') && str_contains($dc, 'Intune') && str_contains($dc, 'Group Policy'), 'uninstall, detection (product name and upgrade code), Intune and Group Policy notes');
$ok(!str_contains($dc, 'setup --silent') && !str_contains($dc, 'IntuneWinAppUtil'), 'the msi panel does not mix in the .exe instructions');
$ok(preg_match('/data-ea-copy-target="ea_msi_ps"/', $dc) === 1 && substr_count($dc, '<textarea') >= 4 && substr_count($dc, 'readonly') >= 4, 'each command has a copy button and a read-only box');
// the token is shown once
[$c, $page2] = $mPage('admin', $wbBig);
$ok(!str_contains($page2, 'id="deploy-commands"'), 'the commands (which hold the token) are shown once');
// exe commands: unchanged, no msi block
$mAdm(['show_deploy_commands' => '1', 'client_id' => 1, 'arch' => 'amd64', 'ring' => 'stable', 'ttl_hours' => 24, 'max_uses' => 5, 'label' => 'exe cmds', 'location_id' => 0]);
[$c, $page] = $mPage('admin', $wbBig);
$dc = (function (string $html): string { $a = strpos($html, 'id="deploy-commands"'); return $a === false ? '' : substr($html, $a, 60000); })($page);
$ok($dc !== '' && !str_contains($dc, 'deploy-msi-warning') && !str_contains($dc, 'ea_msi_') && str_contains($dc, 'setup --silent') && str_contains($dc, 'IntuneWinAppUtil') && !str_contains($dc, ', MSI)'), 'format exe (the default): the panel is the one it always was, no msi block');
// an unknown format is refused before a token exists
$t3 = $mTokens();
$mAdm(['show_deploy_commands' => '1', 'client_id' => 1, 'arch' => 'amd64', 'ring' => 'stable', 'ttl_hours' => 24, 'max_uses' => 5, 'label' => 'x', 'location_id' => 0, 'format' => 'zip']);
$ok($mTokens() === $t3, 'Deployment: an unknown format creates no token');
// ARM64 + MSI through the Deployment form
$mAdm(['show_deploy_commands' => '1', 'client_id' => 1, 'arch' => 'arm64', 'ring' => 'stable', 'ttl_hours' => 24, 'max_uses' => 5, 'label' => 'x', 'location_id' => 0, 'format' => 'msi']);
$ok($mTokens() === $t3, 'Deployment: ARM64 + MSI is refused by Core and creates no token');
// the Deployment download button, msi: the same stamped file through the admin handler
$ch = curl_init($wbBig . '/admin/post.php');
curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['download_installer' => '1', 'client_id' => 1, 'arch' => 'amd64', 'ring' => 'stable', 'ttl_hours' => 24, 'max_uses' => 1, 'label' => 'admin dl', 'location_id' => 0, 'format' => 'msi', 'csrf_token' => 'csrftok1']),
    CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIE => 'PHPSESSID=' . $SS['admin'], CURLOPT_HTTPHEADER => ['Referer: ' . $wbBig . '/admin/settings_endpoint_agent.php'], CURLOPT_TIMEOUT => 120]);
$raw = (string) curl_exec($ch);
curl_close($ch);
$r3 = InstallerStamp::read($raw);
$ok($r3 !== null && substr($raw, 0, $r3['exe_length']) === $mReal && str_starts_with($raw, "\xD0\xCF\x11\xE0"), 'Administration > Deployment > Download installer with Format MSI: the same stamped MSI');

// ---- the binary and the templates this fragment published are removed again
foreach ($rows('SELECT * FROM endpoint_agent_binaries') as $b) { @unlink($rmm->binaryStore()->pathFor($b)); }
$q('DELETE FROM endpoint_agent_binaries');
foreach ($mRows() as $t) { @unlink($rmm->binaryStore()->pathFor($t)); }
$q('DELETE FROM endpoint_agent_msi_templates');
$rmm->settings()->set(['service_url' => $mServiceUrl0]);
$fresh();
$ok($mRows() === [] && $rmm->enabled(), 'the fragment\'s templates are removed, the service address is put back; the module ends ON');

/** The size as the card prints it (BinaryStore::human). */
function BinaryStoreHuman(string $bytes): string
{
    return \RivetCore\Rmm\Binaries\BinaryStore::human(strlen($bytes));
}
