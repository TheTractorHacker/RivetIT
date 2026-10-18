<?php
/*
 * Fragment of tests/rmm_ui.php (RivetCore 1.0.0-rc.12 UI, shipped in RivetIT with rc.13): the optional MeshCentral agent. Shares that file's variables.
 *
 *   1. Administration > Endpoint agent > MeshCentral: the new fields (device group id, certificate pin with "Read it from the server", overwrite switch, the collapsed
 *      advanced corrections), saved through admin/post.php, refusals, and the pin probe against a LOCAL TLS server (the proposal is shown and never saved by the reading).
 *   2. The device and asset pages: the Mesh agent state (installed / version / node id / error) and the hints for a conflict and for a node kept because an administrator
 *      mapped it by hand. The device is simulated through the real check-in endpoint; the node-id mapping is Core's, driven by that check-in.
 *
 * The module is ON here and ends ON; the Mesh settings are put back at the end.
 */

use RivetCore\Rmm\Authz\RmmPrincipal;

$mAgent = $rmm->meshAgentSettings();
$mAgents = $rmm->meshAgents();
$mfresh = function () use ($rmm): void { $rmm->meshAgentSettings()->refresh(); $rmm->settings()->refresh(); $rmm->state()->forget(); };
$mstate = function () use ($mAgent, $mfresh): array { $mfresh(); return $mAgent->get(); };
$meshBase = function (): array {
    global $rmm;
    $c = $rmm->settings()->get(true);

    return ['mesh_url' => (string) $c['mesh_url'], 'mesh_domain' => (string) $c['mesh_domain'], 'mesh_account_template' => (string) $c['mesh_account_template'], 'mesh_policy' => (string) $c['mesh_policy'],
        'mesh_token_ttl_s' => (string) $c['mesh_token_ttl_s'], 'mesh_enabled' => (int) $c['mesh_enabled'] === 1 ? '1' : null];
};
/** The Mesh card's form, exactly as the browser sends it (the install fields only when the card printed them). */
$meshForm = function (array $over = [], bool $install = true) use ($meshBase): array {
    $f = array_filter($meshBase(), fn ($v) => $v !== null) + ['save_mesh_settings' => '1'];
    if ($install) {
        $f += ['mesh_install_present' => '1', 'mesh_group_id' => '', 'mesh_server_cert_pin' => '', 'mesh_aid' => [], 'mesh_args' => []];
    }

    return array_filter($over + $f, fn ($v) => $v !== null);
};
$adminPageM = fn (string $who = 'admin') => web($wb, 'GET', '/admin/settings_endpoint_agent.php', $SS[$who]);
$cardOf = function (string $html): string { $a = strpos($html, 'id="mesh"'); return $a === false ? '' : substr($html, $a, 60000); };

// ---- the Mesh settings as the seed leaves them: enabled, https address, a login key, no group id, no pin
$mfresh();
$g0 = $mstate();
$ok($g0['group_id'] === '' && $g0['server_cert_pin'] === '' && $g0['auto_map_overwrite'] === false && $g0['agent_ids'] === [] && $g0['install_args'] === [], 'the Mesh agent install settings start empty and off');

// ============================================================ 1. the settings card
[$c, $page] = $adminPageM();
$card = $cardOf($page);
$ok($c === 200 && $card !== '', 'the Administration page has the MeshCentral card');
foreach (['name="mesh_group_id"' => 'device group id', 'name="mesh_server_cert_pin"' => 'certificate pin', 'name="probe_mesh_pin"' => 'Read it from the server', 'name="mesh_auto_map_overwrite"' => 'overwrite switch',
    'name="mesh_aid[windows_arm64]"' => 'agent id correction (plain field)', 'name="mesh_args[windows]"' => 'install argument correction (plain field)', 'name="mesh_install_present"' => 'install fields marker'] as $needle => $what) {
    $ok(str_contains($card, $needle), "the card has the $what field");
}
$ok(str_contains($card, 'Read it from the server') && str_contains($card, 'trust on first use'), 'the pin help explains the button (trust on first use)');
$ok(str_contains($card, 'id="mesh-install-help"') && str_contains($card, 'Create the device group in MeshCentral') && str_contains($card, 'Treat the group id like an invitation key') && str_contains($card, 'Check the result'), 'step-by-step help text is on the card');
$ok(preg_match('/<details id="mesh-install-advanced"(?![^>]*\bopen\b)/', $card) === 1, 'the advanced corrections are collapsed by default');
$ok(str_contains($card, 'verify against your MeshCentral version') && str_contains($card, 'Windows ARM64 agent id (36) is the least certain'), 'the advanced section carries the "verify against your MeshCentral version" warning');
$ok(str_contains($card, 'id="mesh-install-ready"') && str_contains($card, 'Not ready: ') && str_contains($card, 'device group id'), 'the card says what is missing before the installer tick-box can appear (' . ($rmm->meshAgents()->installRefusal() ?? 'null') . ')');
$ok(!str_contains($card, '_enc') && preg_match('~name="mesh_login_key"[^>]*value=~', $card) !== 1, 'the stored login key is never printed');
$ok(str_contains($card, 'role="switch"') && str_contains($card, 'for="mc_group"') && str_contains($card, 'aria-describedby="mc_pin_help"'), 'accessible: the switch role, labels and described-by on the new fields');

// a technician cannot save them
[$c] = $adm1($meshForm(['mesh_group_id' => str_repeat('ab', 48)]), 'tech');
$ok($mstate()['group_id'] === '', 'a technician cannot save the Mesh install settings (' . $c . ')');

// the group id in MeshCentral's own mesh// form is converted; the hex form is accepted too
do {   // rc.15: an id whose URL form has both an '@' (for '+') and a '$' (for '/'), so the spelling is really exercised
    $raw48 = random_bytes(48);
    $u48 = strtr(base64_encode($raw48), ['+' => '@', '/' => '$']);   // MeshCentral's URL form: 64 characters
} while (!str_contains($u48, '@') || !str_contains($u48, '$'));
$meshForm64 = 'mesh//' . strtr(base64_encode($raw48), ['+' => '@', '/' => '$']);
[$c] = $adm1($meshForm(['mesh_group_id' => $meshForm64, 'mesh_auto_map_overwrite' => '1']));
$g = $mstate();
$ok($g['group_id'] === bin2hex($raw48) && $g['auto_map_overwrite'] === true, 'the mesh// form of the group id is saved as hex, and the overwrite switch is saved on (' . $c . ')');
[$c, $page] = $adminPageM();
$card = $cardOf($page);
$ok(str_contains($card, 'value="' . $u48 . '"') && !str_contains($card, bin2hex($raw48)) && preg_match('/name="mesh_auto_map_overwrite"[^>]*checked/', $card) === 1 && str_contains($card, 'Ready: the tick-box appears in the installer dialogs.'), 'the card shows the saved id in MeshCentral\'s URL form (mesh_group_id_url, not the hex), the switch on and "Ready"');
[$c] = $adm1($meshForm(['mesh_group_id' => strtoupper(bin2hex($raw48))]));
$ok($mstate()['group_id'] === bin2hex($raw48) && $mstate()['auto_map_overwrite'] === false, 'a hex id in capitals is normalised; an unticked switch saves off');
$audit0 = (int) $one("SELECT COUNT(*) FROM logs WHERE log_description LIKE '%edited the MeshCentral settings%Mesh agent install settings%'");
$ok($audit0 >= 2, 'saving the install settings is audited (' . $audit0 . ' lines)');

// ---- RivetCore 1.0.0-rc.15: the device group id in MeshCentral's own URL form (all values below are random placeholders made by this test)
[$c, $page] = $adminPageM();
$card = $cardOf($page);
preg_match('/<input[^>]*id="mc_group"[^>]*>/', $card, $gIn);
$gIn = $gIn[0] ?? '';
$ok($gIn !== '' && !str_contains($gIn, 'pattern=') && str_contains($gIn, 'maxlength="2000"') && str_contains($gIn, 'name="mesh_group_id"') && str_contains($gIn, 'autocomplete="new-password"'), 'rc.15: the group id input has no pattern attribute (no hex-only restriction) and takes up to 2000 characters');
$ok(str_contains($gIn, 'type="password"') && preg_match('~data-toggle="password"[^>]*aria-controls="mc_group"|aria-controls="mc_group"[^>]*data-toggle="password"~', $card) === 1, 'rc.15: the id is masked like an invitation key, with a show/hide toggle button');
$ok(str_contains($card, 'Paste the download address from MeshCentral (device group') && str_contains($card, 'Windows 64-bit installer') && str_contains($card, 'It is 64 characters, not hex') && str_contains($card, 'Treat it like an invitation key'), 'rc.15: the field help says what to paste (the download address or its meshid), that it is 64 characters not hex, and to treat it like an invitation key');
$ok(str_contains($card, 'empty = public CA certificate') && str_contains($card, 'Leave this empty when the Mesh server') && str_contains($card, 'Let\'s Encrypt') && str_contains($card, '60 to 90 days') && str_contains($card, 'Pin only a private CA or a self-signed certificate'), 'rc.15: the pin help says to leave it empty for a public CA (Let\'s Encrypt renews every 60 to 90 days) and to pin only a private CA or self-signed certificate');
$gAll = fn () => $mstate();
// the bare 64-character meshid
[$c] = $adm1($meshForm(['mesh_group_id' => $u48, 'mesh_auto_map_overwrite' => null]));
$g = $gAll();
$ok($g['group_id'] === bin2hex($raw48) && $g['group_id_url'] === $u48, 'rc.15: the bare 64-character meshid is saved (hex canonical, the URL form kept verbatim)');
$ok((string) $one("SELECT group_id_url FROM endpoint_agent_mesh_settings WHERE id=1") === $u48 && (string) $one("SELECT group_id FROM endpoint_agent_mesh_settings WHERE id=1") === bin2hex($raw48), 'rc.15: the database holds group_id (hex) and group_id_url (the URL form as pasted)');
[$c, $page] = $adminPageM();
$ok(str_contains($cardOf($page), 'value="' . $u48 . '"') && !str_contains($page, 'Warning: The pasted address'), 'rc.15: the card reads the id back from mesh_group_id_url; a bare id gives no warning');
// the whole pasted download address, same host as the saved MeshCentral address: only the meshid is taken, no warning
$u2 = strtr(base64_encode(random_bytes(48)), ['+' => '@', '/' => '$']);
$mHost = (string) parse_url((string) $rmm->settings()->get(true)['mesh_url'], PHP_URL_HOST);
[$c] = $adm1($meshForm(['mesh_group_id' => " https://$mHost/meshagents?id=4&meshid=$u2&installflags=0 \n"]));
$g = $gAll();
$ok($g['group_id_url'] === $u2 && $g['group_id'] === bin2hex(base64_decode(strtr($u2, ['@' => '+', '$' => '/']))), 'rc.15: the whole pasted download address (same host, with surrounding whitespace) is saved: only the meshid is extracted');
[$c, $page] = $adminPageM();
$ok(!str_contains($page, 'Warning: The pasted address') && str_contains($cardOf($page), 'value="' . $u2 . '"') && preg_match('/<input[^>]*id="mc_group"[^>]*meshagents/', $cardOf($page)) !== 1, 'rc.15: a pasted address for the saved host raises no warning; the card shows the meshid, not the address');
// a different host: saved, with Core\'s warning shown
$u3 = strtr(base64_encode(random_bytes(48)), ['+' => '@', '/' => '$']);
[$c] = $adm1($meshForm(['mesh_group_id' => "https://other-mesh.example.net/meshagents?id=4&meshid=$u3&installflags=0"]));
$g = $gAll();
$ok($g['group_id_url'] === $u3, 'rc.15: an address for a different host is still saved');
[$c, $page] = $adminPageM();
$ok(str_contains($page, 'Warning: The pasted address is for the host other-mesh.example.net, but the MeshCentral address is set to ' . $mHost) && str_contains($page, 'Check that both belong to the same MeshCentral server'), 'rc.15: Core\'s warning about the other host is shown with the confirmation');
[$c, $page] = $adminPageM();
$ok(!str_contains($page, 'Warning: The pasted address'), 'rc.15: the warning is shown once');
// the other accepted spellings
[$c] = $adm1($meshForm(['mesh_group_id' => "mesh//$u2"]));
$g = $gAll();
$ok($g['group_id_url'] === $u2 && $g['group_id'] === bin2hex(base64_decode(strtr($u2, ['@' => '+', '$' => '/']))), 'rc.15: mesh//<64 characters> is saved with the URL form kept');
[$c] = $adm1($meshForm(['mesh_group_id' => strtoupper(bin2hex($raw48))]));
$g = $gAll();
$ok($g['group_id'] === bin2hex($raw48) && $g['group_id_url'] === $u48, 'rc.15: hex is still accepted and the URL form is derived from it (' . $g['group_id_url'] . ')');
$ok((string) $one("SELECT group_id_url FROM endpoint_agent_mesh_settings WHERE id=1") === '', 'rc.15: a hex save does not store a URL form (it is derived on read)');
[$c, $page] = $adminPageM();
$ok(str_contains($cardOf($page), 'value="' . $u48 . '"'), 'rc.15: the card shows the derived URL form for an id saved as hex');
// hostile and malformed values are refused with one message and nothing is written; what was typed comes back escaped
$hostile = ['<script>alert(1)</script>', 'ab cd' . str_repeat('e', 59), "$u48\"onfocus=\"alert(1)", "javascript:alert(1)", "https://user:pw@mesh.example.test/meshagents?id=4&meshid=$u48", "https://$mHost/meshagents?id=4&installflags=0",
    "https://$mHost/meshagents?id=0&meshid=$u48", "https://$mHost/meshagents?id=999&meshid=$u48", substr($u48, 1), $u48 . 'A', "mesh//" . substr($u48, 2), str_repeat('z', 64) . '#', "$u48&x=1", "'$u48'", "ab\ncd", str_repeat('A', 2100), 'file:///etc/passwd', "$u48`id`"];
$stored = $gAll();
$bad = 0;
foreach ($hostile as $h) {
    [$c] = $adm1($meshForm(['mesh_group_id' => $h]));
    $g = $gAll();
    if ($g['group_id'] !== $stored['group_id'] || $g['group_id_url'] !== $stored['group_id_url']) { $bad++; echo "  accepted: " . json_encode(substr($h, 0, 70)) . "\n"; }
}
$ok($bad === 0, 'rc.15: ' . count($hostile) . ' hostile or malformed values (markup, quotes, backtick, credentials in the URL, no meshid, id 0 and 999, wrong length, extra parameters, newline, 2100 characters, file:) are all refused; the stored id never changes');
[$c] = $adm1($meshForm(['mesh_group_id' => '<script>alert(1)</script>']));
[$c, $page] = $adminPageM();
$card = $cardOf($page);
$ok(str_contains($page, 'The Mesh device group id must be the meshid') && str_contains($card, 'value="&lt;script&gt;alert(1)&lt;/script&gt;"') && !str_contains($page, '<script>alert(1)</script>'), 'rc.15: a refused value comes back escaped, with Core\'s one message');
[$c] = $adm1($meshForm(['mesh_group_id' => bin2hex($raw48)]));   // back to the id the rest of this fragment uses

// refusals write nothing at all (the group id is valid, the pin is not), and what was typed comes back
[$c] = $adm1($meshForm(['mesh_group_id' => str_repeat('cd', 40), 'mesh_server_cert_pin' => 'not-a-pin', 'mesh_auto_map_overwrite' => '1']));
$g = $mstate();
$ok($g['group_id'] === bin2hex($raw48) && $g['server_cert_pin'] === '' && $g['auto_map_overwrite'] === false, 'an invalid pin: nothing is written, not even the valid group id or the switch');
[$c, $page] = $adminPageM();
$card = $cardOf($page);
$ok(str_contains($page, 'The certificate pin must be the SHA-384') && str_contains($card, 'value="not-a-pin"') && str_contains($card, 'value="' . str_repeat('cd', 40) . '"'), 'Core\'s refusal is shown and the typed values are kept');
[$c, $page] = $adminPageM();
$ok(!str_contains($cardOf($page), 'value="not-a-pin"'), 'the kept values are shown once');
[$c] = $adm1($meshForm(['mesh_group_id' => 'xyz not hex']));
$ok($mstate()['group_id'] === bin2hex($raw48), 'an invalid group id is refused and the stored one stays');
[$c, $page] = $adminPageM();
$ok(str_contains($page, 'The Mesh device group id must be the meshid from the MeshCentral download address'), 'the group id refusal text is Core\'s');
[$c] = $adm1($meshForm(['mesh_group_id' => bin2hex($raw48), 'mesh_aid' => 'not-an-array']));
[$c, $page] = $adminPageM();
$ok(str_contains($page, 'were not sent correctly') && $mstate()['agent_ids'] === [], 'agent id boxes sent as something else than fields are refused before Core is asked');
[$c] = $adm1($meshForm(['mesh_group_id' => bin2hex($raw48), 'mesh_aid' => ['windows_arm64' => '999']]));
[$c, $page] = $adminPageM();
$ok(str_contains($page, 'Agent id for windows/arm64: type a whole number from 1 to 255') && $mstate()['agent_ids'] === [] && str_contains($cardOf($page), 'value="999"'), 'an agent id out of range is refused in plain words, nothing is saved, and what was typed is kept in the box');
[$c] = $adm1($meshForm(['mesh_group_id' => bin2hex($raw48), 'mesh_aid' => ['windows_arm64' => '12abc']]));
[$c, $page] = $adminPageM();
$ok(str_contains($page, 'whole number from 1 to 255') && $mstate()['agent_ids'] === [], 'a non-numeric agent id is refused');
[$c] = $adm1($meshForm(['mesh_group_id' => bin2hex($raw48), 'mesh_aid' => ['evil/os' => '5']]));
$ok($mstate()['agent_ids'] === [], 'a platform that is not in the list is ignored (closed set), nothing saved');
[$c] = $adm1($meshForm(['mesh_group_id' => bin2hex($raw48), 'mesh_args' => ['windows' => '-fullinstall; calc']]));
[$c, $page] = $adminPageM();
$ok(str_contains($page, 'Install arguments for windows: use at most 8 simple arguments') && $mstate()['install_args'] === [], 'an install argument with shell syntax is refused in plain words');
[$c] = $adm1($meshForm(['mesh_group_id' => bin2hex($raw48), 'mesh_args' => ['linux' => '-a -b -c -d -e -f -g -h -i']]));
$ok($mstate()['install_args'] === [], 'more than 8 install arguments are refused');
[$c] = $adm1($meshForm(['mesh_group_id' => bin2hex($raw48), 'mesh_aid' => ['windows_arm64' => '36', 'linux_amd64' => '6'], 'mesh_args' => ['linux' => '-install --installPath=/opt/mesh', 'windows' => '']]));
$g = $mstate();
ksort($g['agent_ids']);
$ok($g['agent_ids'] == ['linux/amd64' => 6, 'windows/arm64' => 36] && $g['install_args'] === ['linux' => ['-install', '--installPath=/opt/mesh']], 'the corrections are saved from the plain fields');
[$c, $page] = $adminPageM();
$card = $cardOf($page);
$ok(str_contains($card, '<span class="badge text-bg-warning">corrected</span>') && str_contains($card, '--installPath=/opt/mesh') && str_contains($card, 'name="mesh_aid[windows_arm64]" min="1" max="255" step="1" value="36"'), 'the card shows the corrections in the plain fields and marks the corrected rows');
$ok(str_contains($card, '<td class="font-monospace">windows/amd64</td><td>4</td>') && str_contains($card, '<span class="text-muted">built in</span>'), 'the effective table lists the built-in ids too');
[$c] = $adm1($meshForm(['mesh_group_id' => bin2hex($raw48), 'mesh_aid' => [], 'mesh_args' => []]));
$g = $mstate();
$ok($g['agent_ids'] === [] && $g['install_args'] === [], 'empty boxes clear the corrections');

// the old form (a page printed before the update, or any client that does not send the install fields) leaves them alone
[$c] = $adm1($meshForm(['mesh_domain' => 'corp'], false));
$g = $mstate();
$ok($g['group_id'] === bin2hex($raw48) && $rmm->settings()->get(true)['mesh_domain'] === 'corp', 'a form without the install fields saves the rest and does not touch the install settings');
[$c] = $adm1($meshForm(['mesh_domain' => ''], false));

// ---- the pin probe against a local TLS server
$tdir = sys_get_temp_dir() . '/rmm_mesh_tls_' . bin2hex(random_bytes(3));
mkdir($tdir, 0700);
$tlsProc = null;
$tlsPort = 0;
$haveOpenssl = trim((string) @shell_exec('command -v openssl')) !== '';
if ($haveOpenssl) {
    exec('openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj "/CN=mesh.test" -addext "subjectAltName=IP:127.0.0.1,DNS:localhost" -keyout ' . escapeshellarg("$tdir/k.pem") . ' -out ' . escapeshellarg("$tdir/c.pem") . ' 2>/dev/null', $oo, $rc);
    $haveOpenssl = $rc === 0 && is_file("$tdir/c.pem");
}
$ok($haveOpenssl, 'a throw-away self-signed certificate for the local Mesh stand-in');
if ($haveOpenssl) {
    $sock = stream_socket_server('tcp://127.0.0.1:0', $en, $es);
    $tlsPort = (int) explode(':', stream_socket_get_name($sock, false))[1];
    fclose($sock);
    $tlsProc = proc_open(['openssl', 's_server', '-accept', "127.0.0.1:$tlsPort", '-cert', "$tdir/c.pem", '-key', "$tdir/k.pem", '-www', '-quiet'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', "$tdir/srv.log", 'a'], 2 => ['file', "$tdir/srv.log", 'a']], $pp);
    register_shutdown_function(function () use (&$tlsProc, $tdir) { if (is_resource($tlsProc)) { proc_terminate($tlsProc); proc_close($tlsProc); } foreach (glob("$tdir/*") ?: [] as $f) { @unlink($f); } @rmdir($tdir); });
    for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', $tlsPort)) { break; } usleep(100000); }
    $fp = trim((string) shell_exec('openssl x509 -in ' . escapeshellarg("$tdir/c.pem") . ' -noout -fingerprint -sha384 | cut -d= -f2'));
    $expectPin = strtolower(str_replace(':', '', $fp));
    $ok(strlen($expectPin) === 96, 'the expected SHA-384 of the stand-in certificate (96 hex)');
    $meshUrlTls = 'https://127.0.0.1:' . $tlsPort;
    $typedPin = str_repeat('ab', 48);
    [$c, , $hd] = $adm1($meshForm(['mesh_url' => $meshUrlTls, 'mesh_group_id' => bin2hex($raw48), 'mesh_server_cert_pin' => $typedPin, 'probe_mesh_pin' => '1', 'save_mesh_settings' => null]));
    $ok(in_array($c, [200, 302], true) && $mstate()['server_cert_pin'] === '', 'Read it from the server: the typed pin is NOT saved by the button (' . $c . ')');
    $ok($rmm->settings()->get(true)['mesh_url'] === $meshUrlTls && $mstate()['group_id'] === bin2hex($raw48), 'the rest of the card was saved by it (the address the probe used)');
    $ok(str_contains($hd, 'settings_endpoint_agent.php#mesh') || $c === 200, 'the button returns to the Mesh card');
    [$c, $page] = $adminPageM();
    $card = $cardOf($page);
    $ok(str_contains($card, 'id="mc_pin_proposal"') && str_contains($card, 'Proposed pin (not saved)') && str_contains($card, '<div class="font-monospace small text-break" id="mc_pin_proposed">' . $expectPin . '</div>'), 'the proposal is shown: the SHA-384 of the server\'s certificate');
    $ok(str_contains($card, 'data-ea-use-pin="' . $expectPin . '"') && str_contains($card, 'Compare it with the hash MeshCentral shows') && preg_match('/name="mesh_server_cert_pin"[^>]*value=""/', $card) === 1, 'a "Use this pin" button carries it; the pin field itself is still empty');
    $ok($mstate()['server_cert_pin'] === '', 'the proposal is not saved by showing it');
    $ok(str_contains($card, 'data-publicly-trusted="0"') && str_contains($card, 'id="mc_pin_note"') && str_contains($card, 'This certificate is not trusted by the system certificate store (a private CA or a self-signed certificate)'), 'rc.15: a self-signed certificate: Core\'s note says it is not publicly trusted (pin_probe.note) and the proposal is offered');
    [$c, $page] = $adminPageM();
    $ok(!str_contains($cardOf($page), 'mc_pin_proposed'), 'the proposal is shown once');
    [$c] = $adm1($meshForm(['mesh_url' => $meshUrlTls, 'mesh_group_id' => bin2hex($raw48), 'mesh_server_cert_pin' => strtoupper(implode(':', str_split($expectPin, 2)))]));
    $ok($mstate()['server_cert_pin'] === $expectPin, 'the administrator confirms: pressing Save stores the pin (colons and capitals normalised)');
    $ok($rmm->meshAgents()->installRefusal() === null, 'with the address, the group id and the pin set, the installer tick-box is ready');
    [$c] = $adm1($meshForm(['mesh_url' => 'https://127.0.0.1:1', 'mesh_group_id' => bin2hex($raw48), 'mesh_server_cert_pin' => $expectPin, 'probe_mesh_pin' => '1', 'save_mesh_settings' => null]));
    [$c, $page] = $adminPageM();
    $ok(str_contains($cardOf($page), 'Could not read the certificate') && $mstate()['server_cert_pin'] === $expectPin, 'a Mesh server that does not answer: a plain explanation, the stored pin is untouched');
    [$c] = $adm1($meshForm(['mesh_url' => $meshUrlTls, 'mesh_group_id' => bin2hex($raw48), 'mesh_server_cert_pin' => $expectPin, 'probe_mesh_pin' => '1', 'save_mesh_settings' => null]), 'tech');
    [$c, $page] = $adminPageM();
    $ok(!str_contains($cardOf($page), 'mc_pin_proposed'), 'a technician cannot use the probe (nothing is shown to the administrator)');
    [$c] = $adm1($meshForm(['mesh_url' => 'https://127.0.0.1:' . $tlsPort, 'mesh_group_id' => bin2hex($raw48), 'mesh_server_cert_pin' => '']));
    $ok($mstate()['server_cert_pin'] === '', 'an empty pin clears it (the system trust store is used again)');

    // ---- rc.15: pin_probe.publicly_trusted. The system trust store of the test machine cannot be changed, and a public CA (Let's Encrypt) cannot be reached from here, so the
    // "publicly trusted" answer is produced with a LOCAL CA: a second php -S whose PHP is started with curl.cainfo = that CA (the bundle PHP's curl hands to libcurl; Core has no
    // trust-store injection of its own), against a stand-in whose certificate that CA signed. That proves the page logic and the handshake wiring, NOT Let's Encrypt itself.
    exec('openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj "/CN=rc15 local test CA" -addext "basicConstraints=critical,CA:TRUE" -keyout ' . escapeshellarg("$tdir/ca.key") . ' -out ' . escapeshellarg("$tdir/ca.pem") . ' 2>/dev/null', $oo, $rc1);
    exec('openssl req -newkey rsa:2048 -nodes -subj "/CN=mesh.public.test" -keyout ' . escapeshellarg("$tdir/p.key") . ' -out ' . escapeshellarg("$tdir/p.csr") . ' 2>/dev/null', $oo, $rc2);
    file_put_contents("$tdir/ext.cnf", "subjectAltName=IP:127.0.0.1,DNS:localhost\nbasicConstraints=CA:FALSE\nextendedKeyUsage=serverAuth\n");
    exec('openssl x509 -req -in ' . escapeshellarg("$tdir/p.csr") . ' -CA ' . escapeshellarg("$tdir/ca.pem") . ' -CAkey ' . escapeshellarg("$tdir/ca.key") . ' -CAcreateserial -days 2 -extfile ' . escapeshellarg("$tdir/ext.cnf") . ' -out ' . escapeshellarg("$tdir/p.pem") . ' 2>/dev/null', $oo, $rc3);
    $haveCa = $rc1 === 0 && $rc2 === 0 && $rc3 === 0 && is_file("$tdir/p.pem") && trim((string) @shell_exec('command -v python3')) !== '';
    $ok($haveCa, 'rc.15: a local CA and a server certificate it signed (SAN IP 127.0.0.1)');
    if ($haveCa) {
        $sock = stream_socket_server('tcp://127.0.0.1:0', $en, $es);
        $caPort = (int) explode(':', stream_socket_get_name($sock, false))[1];
        fclose($sock);
        $caProc = proc_open(['python3', '-I', __DIR__ . '/support/tls_stub_server.py', (string) $caPort, "$tdir/p.pem", "$tdir/p.key"], [0 => ['file', '/dev/null', 'r'], 1 => ['file', "$tdir/srv2.log", 'a'], 2 => ['file', "$tdir/srv2.log", 'a']], $pp2);
        register_shutdown_function(function () use (&$caProc) { if (is_resource($caProc)) { proc_terminate($caProc); proc_close($caProc); } });
        for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', $caPort)) { break; } usleep(100000); }
        $caPin = strtolower(str_replace(':', '', trim((string) shell_exec('openssl x509 -in ' . escapeshellarg("$tdir/p.pem") . ' -noout -fingerprint -sha384 | cut -d= -f2'))));
        $web2 = ea_start_php($root . '/tests/mobile_api_router.php', ['RIVETIT_WEBHOOK_ALLOW_PRIVATE' => '1'], ["session.save_path=$sdir", "curl.cainfo=$tdir/ca.pem"]);
        $wb2 = "http://127.0.0.1:{$web2['port']}";
        $adm2 = function (array $f) use ($wb2, $SS, $REF1, $fresh) { $r = web($wb2, 'POST', '/admin/post.php', $SS['admin'], $f + ['csrf_token' => 'csrftok1'], $REF1); $fresh(); return $r; };
        $card2 = function () use ($wb2, $SS, $cardOf): string { [, $pg] = web($wb2, 'GET', '/admin/settings_endpoint_agent.php', $SS['admin']); return $cardOf($pg); };
        $caUrl = 'https://127.0.0.1:' . $caPort;
        // 1. a certificate that the web server's trust bundle verifies: the note says no pin is needed and NOTHING offers to save the proposed pin
        [$c] = $adm2($meshForm(['mesh_url' => $caUrl, 'mesh_group_id' => $u48, 'mesh_server_cert_pin' => '', 'probe_mesh_pin' => '1', 'save_mesh_settings' => null]));
        $cd = $card2();
        $ok(str_contains($cd, 'id="mc_pin_proposal"') && str_contains($cd, 'data-publicly-trusted="1"') && str_contains($cd, 'Publicly trusted certificate: no pin needed') && str_contains($cd, 'This certificate is publicly trusted: no pin needed. Leave the pin empty so installs keep working when the certificate is renewed.'), 'rc.15: a certificate the trust store verifies (publicly_trusted true): the page shows Core\'s note, "no pin needed"');
        $ok(!str_contains($cd, 'data-ea-use-pin="') && !str_contains($cd, 'Use this pin') && !str_contains($cd, 'mc_pin_proposed') && !str_contains($cd, 'Proposed pin') && !str_contains($cd, $caPin), 'rc.15: a publicly trusted certificate: no "Use this pin" button, no proposed pin, the hash is not even printed');
        $ok($mstate()['server_cert_pin'] === '' && preg_match('/name="mesh_server_cert_pin"[^>]*value=""/', $cd) === 1, 'rc.15: the pin stays empty');
        // 2. the control: the very same server read by the first web server (whose trust store does not hold that CA) is NOT publicly trusted, so it is the CA that flipped the answer
        [$c] = $adm1($meshForm(['mesh_url' => $caUrl, 'mesh_group_id' => $u48, 'mesh_server_cert_pin' => '', 'probe_mesh_pin' => '1', 'save_mesh_settings' => null]));
        [$c, $pg] = $adminPageM();
        $cd = $cardOf($pg);
        $ok(str_contains($cd, 'data-publicly-trusted="0"') && str_contains($cd, 'mc_pin_proposed') && str_contains($cd, $caPin) && str_contains($cd, 'not trusted by the system certificate store'), 'rc.15: control: the same certificate read by a web server that does not trust the local CA is not publicly trusted, and the proposal is offered');
        // 3. the self-signed stand-in read by the web server that trusts only the local CA is not publicly trusted either
        [$c] = $adm2($meshForm(['mesh_url' => $meshUrlTls, 'mesh_group_id' => $u48, 'mesh_server_cert_pin' => '', 'probe_mesh_pin' => '1', 'save_mesh_settings' => null]));
        $cd = $card2();
        $ok(str_contains($cd, 'data-publicly-trusted="0"') && str_contains($cd, $expectPin), 'rc.15: a self-signed certificate is not publicly trusted even where the local CA is trusted');
        // 4. the flash after the button says the same
        [$c, , $hd] = $adm2($meshForm(['mesh_url' => $caUrl, 'mesh_group_id' => $u48, 'mesh_server_cert_pin' => '', 'probe_mesh_pin' => '1', 'save_mesh_settings' => null]));
        [, $pg] = web($wb2, 'GET', '/admin/settings_endpoint_agent.php', $SS['admin']);
        $ok(str_contains($pg, 'It is publicly trusted, so no pin is needed: leave the pin empty.'), 'rc.15: the confirmation after the button says no pin is needed');
        [$c] = $adm1($meshForm(['mesh_url' => 'https://127.0.0.1:' . $tlsPort, 'mesh_group_id' => bin2hex($raw48), 'mesh_server_cert_pin' => '']));
        $mstate();
    }
}

// ---- back to the seed's address; keep the group id for the device scenarios (the installer tests clear it)
$rmm->settings()->set(['mesh_url' => 'https://mesh.example.test', 'mesh_domain' => '']);
$mfresh();

// ============================================================ 2. the device page: the Mesh agent state and the auto-mapping
$mk = function (string $name, int $client, string $tk, ?bool $asset = true) use ($S, $q, $db, $ok, &$A): array {
    $serial = 'SER-' . $name;
    if ($asset) {
        $q("INSERT INTO assets SET asset_type='Laptop', asset_name='$name', asset_make='Dell', asset_serial='$serial', asset_client_id=$client, asset_status='Active'");
        $A[$name] = (int) $db->insert_id;
    }
    [$c, , $j] = ea_enroll($S['enroll'][$tk], ea_dev(['serial' => $serial, 'hostname' => $name, 'os' => 'windows']));
    $ok($c === 201 && !empty($j['device_id']), "$name enrolled through the real endpoint");

    return [(int) $j['device_id'], (string) $j['device_token']];
};
$caps = ['job:collect', 'job:shell', 'mesh:nodeid'];
$node = fn (string $t) => 'node//' . str_repeat($t, 8);
[$m1, $t1] = $mk('MESH1', 1, 'A');
[$m2, $t2] = $mk('MESH2', 1, 'A');
[$m3, $t3] = $mk('MESH3', 2, 'B');
[$m4, $t4] = $mk('MESH4', 1, 'A');
[$m5, $t5] = $mk('MESH5', 1, 'A');
[$m6, $t6] = $mk('MESH6', 1, 'A');
foreach ([$t1, $t2, $t3, $t4, $t5, $t6] as $t) { ea_checkin($t, ['capabilities' => $caps]); }
$mPage = fn (string $who, string $n) => web($wb, 'GET', '/agent/asset_details.php?asset_id=' . $A[$n], $SS[$who]);
$agentBlock = function (string $body): string { $a = strpos($body, 'id="rmm-mesh-agent"'); return $a === false ? '' : substr($body, $a, 3500); };
$mapRow = fn (int $dev) => $db->query("SELECT mesh_node_id, source FROM endpoint_agent_mesh_nodes WHERE device_id=$dev")->fetch_assoc();

// no report yet: an administrator sees the invitation, a technician sees nothing
[$c, $body] = $mPage('admin', 'MESH1');
$blk = $agentBlock($body);
$ok($c === 200 && str_contains($blk, 'data-state="none"') && str_contains($blk, 'No Mesh agent has been reported by this device'), 'no Mesh report yet: the administrator\'s Remote card says so');
[$c, $body] = $mPage('tech', 'MESH1');
$ok($c === 200 && !str_contains($body, 'id="rmm-mesh-agent"'), 'a technician sees no Mesh agent block for a device that never reported one');
$ok(!array_key_exists('mesh_agent', $rmm->readModel()->deviceView($m1, false, 5) ?? []), 'the device view has no mesh_agent member until the device reports one');

// installed and mapped automatically
[$c] = ea_checkin($t1, ['mesh_agent' => ['installed' => true, 'version' => '1.1.4', 'node_id' => $node('Ab3')]]);
$mr = $mapRow($m1);
$ok($c === 200 && $mr !== null && $mr['mesh_node_id'] === $node('Ab3') && $mr['source'] === 'agent', 'the check-in\'s node id was mapped automatically (source agent)');
[$c, $body] = $mPage('admin', 'MESH1');
$blk = $agentBlock($body);
$ok(str_contains($blk, 'data-state="mapped"') && str_contains($blk, 'Installed') && str_contains($blk, '1.1.4') && str_contains($blk, $node('Ab3')) && str_contains($blk, 'Mapped automatically') && !str_contains($blk, 'data-mesh-hint='), 'the Remote card shows installed, version, node id and "Mapped automatically", no warning');
$ok(str_contains($body, 'id="rmm-mesh-form"') && str_contains($body, 'value="' . $node('Ab3') . '"') && str_contains($body, 'MeshCentral node mapped'), 'the manual node mapping control is still there, filled with the node');
[$c, $body] = $mPage('tech', 'MESH1');
$ok(str_contains($agentBlock($body), 'data-state="mapped"') && !str_contains($body, 'id="rmm-mesh-form"'), 'a technician sees the state but not the mapping control');
$ok(($rmm->readModel()->deviceView($m1, false, 5)['mesh_agent']['map_state'] ?? '') === 'mapped', 'the device view says map_state mapped');
$ok((int) $one("SELECT COUNT(*) FROM logs WHERE log_action='Mesh Agent Installed'") >= 1 && (int) $one("SELECT COUNT(*) FROM logs WHERE log_action='Mesh Node Mapped'") >= 1, 'audited: Mesh Agent Installed and Mesh Node Mapped');

// conflict inside the department: the page names the other device and links to it
[$c] = ea_checkin($t2, ['mesh_agent' => ['installed' => true, 'version' => '1.1.4', 'node_id' => $node('Ab3')]]);
$ok($mapRow($m2) === null && ($rmm->readModel()->deviceView($m2, false, 5)['mesh_agent']['map_state'] ?? '') === 'conflict', 'a node already mapped to another live device is refused (conflict), whatever the settings');
[$c, $body] = $mPage('admin', 'MESH2');
$blk = $agentBlock($body);
$ok(str_contains($blk, 'data-state="conflict"') && str_contains($blk, 'data-mesh-hint="conflict"') && str_contains($blk, 'already mapped to another device') && str_contains($blk, 'href="/agent/asset_details.php?asset_id=' . $A['MESH1'] . '#rmm-overview"') && str_contains($blk, 'cloned disk image'), 'conflict: a visible warning with a link to the other device\'s page');
$ok((int) $one("SELECT COUNT(*) FROM logs WHERE log_action='Mesh Node Conflict'") === 1, 'audited once: Mesh Node Conflict');
ea_checkin($t2, ['mesh_agent' => ['installed' => true, 'version' => '1.1.4', 'node_id' => $node('Ab3')]]);
$ok((int) $one("SELECT COUNT(*) FROM logs WHERE log_action='Mesh Node Conflict'") === 1, 'the same conflict reported again is not audited again');
// across departments: flagged, but the other department's device is never named
[$c] = ea_checkin($t3, ['mesh_agent' => ['installed' => true, 'version' => '1.1.4', 'node_id' => $node('Ab3')]]);
[$c, $body] = $mPage('admin', 'MESH3');
$blk = $agentBlock($body);
$ok(str_contains($blk, 'data-mesh-hint="conflict"') && !str_contains($blk, 'asset_id=' . $A['MESH1']) && !str_contains($blk, 'device #') && str_contains($blk, 'another department'), 'conflict across departments: flagged without naming or linking the other department\'s device');
[$c, $body] = $mPage('deptb', 'MESH1');
$ok(in_array($c, [302, 403, 404], true) && !str_contains($body, $node('Ab3')) && !str_contains($body, 'id="rmm-mesh-agent"'), 'a Dept B user cannot open the Dept A device page and sees none of its Mesh data (' . $c . ')');
[$c, $body] = $mPage('deptb', 'MESH3');
$ok($c === 200 && str_contains($agentBlock($body), 'data-mesh-hint="conflict"') && !str_contains($body, 'asset_id=' . $A['MESH1']), 'the Dept B user sees the conflict on their own device and no Dept A link');
// events: the conflict and the install were published (the rmm.* webhook subscription of rmm_ui.php)
$q("UPDATE webhooks SET webhook_enabled=1");
ea_checkin($t4, ['mesh_agent' => ['installed' => true, 'version' => '1.1.5', 'node_id' => $node('Cd4')]]);
$mEvs = array_column(array_filter($deliveries(), fn ($p) => in_array($p['event'], ['rmm.mesh.agent_installed', 'rmm.mesh.node_conflict'], true)), 'event');
$q("DELETE FROM integration_jobs WHERE job_type='webhook.deliver'");
$q("UPDATE webhooks SET webhook_enabled=0");
$ok(in_array('rmm.mesh.agent_installed', $mEvs, true), 'rmm.mesh.agent_installed is published when a device reports the install');
$q("UPDATE webhooks SET webhook_enabled=1");
$q("UPDATE endpoint_agent_mesh_agents SET installed = 0 WHERE device_id=$m1");   // let the same device report an install again: a fresh install event, then the conflict of MESH5
ea_checkin($t5, ['mesh_agent' => ['installed' => true, 'version' => '1.1.4', 'node_id' => $node('Cd4')]]);
$mEvs = array_values(array_unique(array_map('json_encode', array_column(array_filter($deliveries(), fn ($p) => $p['event'] === 'rmm.mesh.node_conflict'), 'data'))));   // one per subscription; the same event
$q("DELETE FROM integration_jobs WHERE job_type='webhook.deliver'");
$q("UPDATE webhooks SET webhook_enabled=0");
$ok(count($mEvs) === 1 && (json_decode($mEvs[0], true)['node_id'] ?? '') === $node('Cd4') && (json_decode($mEvs[0], true)['other_device_id'] ?? 0) === $m4, 'rmm.mesh.node_conflict is published with the node id and the other device');
$q("UPDATE endpoint_agent_mesh_agents SET installed = 1 WHERE device_id=$m1");

// kept by hand: an administrator's mapping wins unless the overwrite switch is on
$rmm->technician()->setMeshNode($admin, $m6, $node('Ef6'));
$ok(($mapRow($m6)['source'] ?? '') !== 'agent', 'MESH6 is mapped by hand');
ea_checkin($t6, ['mesh_agent' => ['installed' => true, 'version' => '1.1.4', 'node_id' => $node('Gh7')]]);
$mr = $mapRow($m6);
$ok($mr['mesh_node_id'] === $node('Ef6') && ($rmm->readModel()->deviceView($m6, false, 5)['mesh_agent']['map_state'] ?? '') === 'kept_manual', 'the agent reported a different node: the manual mapping is kept (kept_manual)');
[$c, $body] = $mPage('admin', 'MESH6');
$blk = $agentBlock($body);
$ok(str_contains($blk, 'data-state="kept_manual"') && str_contains($blk, 'data-mesh-hint="kept_manual"') && str_contains($blk, 'mapped by hand') && str_contains($blk, $node('Gh7')) && str_contains($body, 'value="' . $node('Ef6') . '"'), 'kept_manual: a visible hint, the agent\'s node shown, the manual node still in the mapping control');
$ok(str_contains($body, 'Let the agent\'s node replace a node I mapped by hand') || str_contains($blk, 'Let the agent&#039;s node replace a node I mapped by hand') || str_contains($blk, 'replace a node I mapped by hand'), 'the hint names the switch that changes this');
$ok((int) $one("SELECT COUNT(*) FROM logs WHERE log_action='Mesh Node Differs'") === 1, 'audited once: Mesh Node Differs');
$mAgent->update(['mesh_auto_map_overwrite' => true], 1);
$mfresh();
ea_checkin($t6, ['mesh_agent' => ['installed' => true, 'version' => '1.1.6', 'node_id' => $node('Gh7')]]);
$mr = $mapRow($m6);
$ok($mr['mesh_node_id'] === $node('Gh7') && $mr['source'] === 'agent', 'with the overwrite switch on the agent\'s node replaces the manual one (audited)');
[$c, $body] = $mPage('admin', 'MESH6');
$ok(str_contains($agentBlock($body), 'data-state="mapped"') && !str_contains($agentBlock($body), 'data-mesh-hint=') && str_contains($agentBlock($body), '1.1.6'), 'the warning is gone and the new version is shown');
$mAgent->update(['mesh_auto_map_overwrite' => false], 1);
$mfresh();

// failure to install: the error is shown, the RivetIT agent is fine
ea_checkin($t5, ['mesh_agent' => ['installed' => false, 'error' => 'the Mesh server answered HTTP 404 <b>x</b>']]);
[$c, $body] = $mPage('admin', 'MESH5');
$blk = $agentBlock($body);
$ok(str_contains($blk, 'data-mesh-hint="error"') && str_contains($blk, 'The Mesh agent install failed') && str_contains($blk, 'HTTP 404 &lt;b&gt;x&lt;/b&gt;') && !str_contains($blk, '<b>x</b>') && str_contains($blk, 'Not installed'), 'an install error is shown, escaped, with the reassurance that the RivetIT agent works');
$ok((int) $one("SELECT COUNT(*) FROM logs WHERE log_action='Mesh Agent Install Failed'") === 1, 'audited: Mesh Agent Install Failed');

// Mesh switched off: a report is stored and shown, but nothing is mapped
$rmm->settings()->set(['mesh_enabled' => 0]);
$mfresh();
ea_checkin($t4, ['mesh_agent' => ['installed' => true, 'version' => '1.1.5', 'node_id' => $node('Ij8')]]);
$ok($mapRow($m4)['mesh_node_id'] === $node('Cd4') || $mapRow($m4) === null, 'MeshCentral switched off: the agent\'s new node is not mapped');
[$c, $body] = $mPage('admin', 'MESH4');
$ok($c === 200 && str_contains($agentBlock($body), 'Installed') && str_contains($body, 'MeshCentral is not configured'), 'MeshCentral switched off: the page still shows the device\'s Mesh agent state');
$rmm->settings()->set(['mesh_enabled' => 1]);
$mfresh();

// the Remote tab (rc.11) shows the same state above its buttons when remote tools are on
$rmm->remoteActions()->updateSettings($admin, ['features' => ['remote_tools' => true]]);
$fresh();
ea_checkin($t1, ['capabilities' => array_merge($caps, ['remote:services', 'remote:processes']), 'mesh_agent' => ['installed' => true, 'version' => '1.1.4', 'node_id' => $node('Ab3')]]);
[$c, $body] = $mPage('admin', 'MESH1');
$rem = (($a = strpos($body, 'id="rem-mesh"')) === false) ? '' : substr($body, $a, 5000);
$ok($c === 200 && str_contains($rem, 'id="rmm-mesh-agent"') && str_contains($rem, '1.1.4'), 'the Remote tab\'s Mesh card shows the Mesh agent state too');
$rmm->remoteActions()->updateSettings($admin, ['features' => ['remote_tools' => false]]);
$fresh();

// the device page of a device with no asset yet (the legacy page keeps being used for it)
[$c, , $hd] = web($wb, 'GET', '/agent/rmm_agent_device.php?device_id=' . $m1, $SS['admin']);
$ok($c === 302 && str_contains($hd, 'asset_details.php?asset_id=' . $A['MESH1']), 'the legacy device page forwards a linked device to its asset page');
$q("UPDATE endpoint_agent_devices SET link_state='pending_approval', asset_id=NULL WHERE device_id=$m4");
[$c, $body] = web($wb, 'GET', '/agent/rmm_agent_device.php?device_id=' . $m4, $SS['admin']);
$ok($c === 200 && str_contains($body, 'MeshCentral agent') && str_contains($body, 'id="rmm-mesh-agent"') && str_contains($body, 'unless the MeshCentral setting that lets the agent'), 'the legacy device page (device waiting for approval) has the Mesh agent card and the corrected mapping note');
$q("UPDATE endpoint_agent_devices SET link_state='linked', asset_id=" . $A['MESH4'] . " WHERE device_id=$m4");

// cleanup of this fragment: the Mesh install settings and the extra devices stay out of the other fragments' counts
$mAgent->update(['mesh_group_id' => '', 'mesh_server_cert_pin' => '', 'mesh_auto_map_overwrite' => false, 'mesh_agent_ids' => [], 'mesh_install_args' => []], 1);
$mfresh();
$rmm->settings()->set(['mesh_enabled' => 1, 'mesh_url' => 'https://mesh.example.test', 'mesh_domain' => '']);
$mfresh();
foreach ([$m1, $m2, $m3, $m4, $m5, $m6] as $dev) {
    $q("DELETE FROM endpoint_agent_mesh_agents WHERE device_id=$dev");
    $q("DELETE FROM endpoint_agent_mesh_nodes WHERE device_id=$dev");
    $q("UPDATE endpoint_agent_devices SET retired_at=UTC_TIMESTAMP() WHERE device_id=$dev");
}
$q("DELETE FROM integration_jobs WHERE job_type='webhook.deliver'");
$ok($rmm->featureOn('policies') && $rmm->enabled(), 'the module is still on at the end of the Mesh fragment');
