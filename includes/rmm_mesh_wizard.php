<?php

/*
 * "Remote access (MeshCentral) setup" (RivetIT 26.10.54): one page that walks an administrator through every step MeshCentral needs, in plain words, with the live
 * state of each step (green tick = done, amber = still to do):
 *   1 connect the MeshCentral server (address, domain, login-token key, Test connection)
 *   2 create the device group and paste its address or id
 *   3 trust: a publicly trusted certificate needs nothing, a private one is pinned ("Read it from the server")
 *   4 tick "Also install the MeshCentral agent" in the installer
 *   5 test Remote on an enrolled device
 * plus Troubleshooting (docs/ENDPOINT_AGENT.md sections 11 to 14, RivetCore docs/rmm/REMOTE_AND_LOGS.md).
 *
 * Nothing here decides anything. Every save is RivetCore\Rmm\Admin\RmmAdmin::saveMesh() (validation, encryption of the login key, the audit entry), the connection test and
 * the certificate reading are RivetCore\Rmm\Mesh\MeshService::probe() / probePin() (network policy, no redirects, short timeouts), the state of step 4 is
 * MeshAgentService::installRefusal(). This file prints the fields, reads the state, and counts devices (read only). Handler: admin/post/settings_mesh_wizard.php.
 * Everything printed is escaped. The login-token key is never printed (only "stored encrypted"); the device group id is never printed back (only "saved").
 */

require_once __DIR__ . '/rmm_mesh_cards.php';
require_once __DIR__ . '/rmm_installer.php';

/** Where the wizard lives. */
const RIVET_RMM_MESH_WIZARD_URL = '/admin/settings_mesh_wizard.php';

/**
 * A small link to the wizard (the Endpoint agent page header, the device page when Mesh is not set up).
 */
function rivetRmmMeshWizardLink(string $label = 'Remote access (MeshCentral) setup', string $class = 'btn btn-outline-secondary btn-sm', string $id = 'rmm-mesh-wizard-link'): string
{
    return '<a href="' . RIVET_RMM_MESH_WIZARD_URL . '" class="' . rivetRmmMeshH($class) . '" id="' . rivetRmmMeshH($id) . '"><i class="fas fa-desktop me-1" aria-hidden="true"></i>' . rivetRmmMeshH($label) . '</a>';
}

/**
 * What each step looks like right now, from the saved settings and a read-only count of devices. The network checks (does MeshCentral answer, is the certificate
 * publicly trusted) are not run here: the page asks for them after it has loaded (action `live` of the handler) and the last answer is kept in the session.
 *
 * @param array<string,mixed>|null $trust the last certificate check ({url, public, ok, at}) or null
 * @return array{db_ready:bool,cfg:array<string,mixed>,view:?array<string,mixed>,steps:list<array<string,mixed>>,done:int,all_done:bool,devices:array<string,mixed>}
 */
function rivetRmmMeshWizardState(\RivetCore\Rmm\RmmModule $rmm, \mysqli $mysqli, ?array $trust = null): array
{
    $cfg = $rmm->readModel()->settingsSummary();
    $view = rivetRmmMeshInstallView($rmm);
    $url = trim((string) ($cfg['mesh_url'] ?? ''));
    $enabled = (int) ($cfg['mesh_enabled'] ?? 0) === 1;
    $keySet = !empty($cfg['mesh_login_key_set']);
    $pin = $view === null ? '' : trim((string) $view['server_cert_pin']);
    $groupSet = $view !== null && (string) $view['group_id'] !== '';
    $inst = rivetRmmInstallerMesh($rmm, $cfg);

    // 1: server
    $miss1 = [];
    if (!$enabled) {
        $miss1[] = 'Remote sessions through MeshCentral are switched off.';
    }
    if ($url === '') {
        $miss1[] = 'The MeshCentral address is not set.';
    }
    if (!$keySet) {
        $miss1[] = 'The login-token key is not set.';
    }
    $s1 = ['n' => 1, 'id' => 'server', 'title' => 'Connect your MeshCentral server', 'ok' => $miss1 === [],
        'summary' => $miss1 === [] ? 'Saved: ' . $url . ($cfg['mesh_domain'] !== '' ? ' (domain ' . $cfg['mesh_domain'] . ')' : '') . '. The login-token key is stored encrypted.' : implode(' ', $miss1)];

    // 2: group
    $s2 = ['n' => 2, 'id' => 'group', 'title' => 'Create the device group and paste its address', 'ok' => $groupSet,
        'summary' => $view === null ? 'The database update has to be run first (Administration > Updates).' : ($groupSet ? 'The device group is saved.' : 'No device group is saved yet.')];

    // 3: trust
    $trustMatches = $trust !== null && ($trust['url'] ?? '') === $url && $url !== '';
    if ($pin !== '') {
        $s3 = ['n' => 3, 'id' => 'trust', 'title' => 'Tell the installer how to trust the server', 'ok' => true, 'summary' => 'A pin is saved: the installer accepts only that exact certificate.', 'mode' => 'pinned'];
    } elseif ($trustMatches && !empty($trust['ok']) && !empty($trust['public'])) {
        $s3 = ['n' => 3, 'id' => 'trust', 'title' => 'Tell the installer how to trust the server', 'ok' => true, 'summary' => 'The server has a publicly trusted certificate, so no pin is needed.', 'mode' => 'public'];
    } elseif ($trustMatches && !empty($trust['ok'])) {
        $s3 = ['n' => 3, 'id' => 'trust', 'title' => 'Tell the installer how to trust the server', 'ok' => false, 'summary' => 'The server uses a private or self-signed certificate. Read it from the server and save the pin.', 'mode' => 'private'];
    } else {
        $s3 = ['n' => 3, 'id' => 'trust', 'title' => 'Tell the installer how to trust the server', 'ok' => false,
            'summary' => $url === '' ? 'Finish step 1 first.' : 'Not checked yet. The page checks the certificate when it loads; or press the check button below.', 'mode' => 'unknown'];
    }

    // 4: installer tick box
    $s4ok = $s1['ok'] && $s2['ok'] && $inst['show'] && $inst['ready'];
    $s4 = ['n' => 4, 'id' => 'installer', 'title' => 'Tick "Also install the MeshCentral agent" in the installer', 'ok' => $s4ok,
        'summary' => $s4ok ? 'The tick box is offered in the installer (Create installer).' : ($inst['hint'] !== '' ? $inst['hint'] : 'Finish steps 1 and 2 and the box appears in the installer.')];

    // 5: a device
    $dev = rivetRmmMeshWizardDevices($mysqli);
    $s5 = ['n' => 5, 'id' => 'test', 'title' => 'Test Remote on an enrolled device', 'ok' => $dev['mapped'] > 0,
        'summary' => $dev['mapped'] > 0 ? $dev['mapped'] . ' enrolled ' . ($dev['mapped'] === 1 ? 'device is' : 'devices are') . ' connected to MeshCentral, so Remote can open a session.'
            : ($dev['enrolled'] === 0 ? 'No device is enrolled yet.' : 'No enrolled device is connected to MeshCentral yet.')];

    $steps = [$s1, $s2, $s3, $s4, $s5];
    $done = count(array_filter($steps, static fn (array $s): bool => $s['ok']));

    return ['db_ready' => $view !== null, 'cfg' => $cfg, 'view' => $view, 'steps' => $steps, 'done' => $done, 'all_done' => $done === 5, 'devices' => $dev];
}

/**
 * Read-only counts for step 5. Devices that are retired or revoked do not count. The five most recently connected ones are listed (host names are escaped when printed).
 *
 * @return array{enrolled:int,agent_installed:int,mapped:int,errors:int,list:list<array{id:int,host:string}>}
 */
function rivetRmmMeshWizardDevices(\mysqli $mysqli): array
{
    $out = ['enrolled' => 0, 'agent_installed' => 0, 'mapped' => 0, 'errors' => 0, 'list' => []];
    try {
        $r = $mysqli->query('SELECT COUNT(*) AS enrolled, COALESCE(SUM(n.device_id IS NOT NULL), 0) AS mapped, COALESCE(SUM(m.installed = 1), 0) AS agent_installed, COALESCE(SUM(m.error <> \'\'), 0) AS errors'
            . ' FROM endpoint_agent_devices d LEFT JOIN endpoint_agent_mesh_nodes n ON n.device_id = d.device_id LEFT JOIN endpoint_agent_mesh_agents m ON m.device_id = d.device_id'
            . ' WHERE d.retired_at IS NULL AND d.revoked_at IS NULL');
        if ($r instanceof \mysqli_result) {
            $row = $r->fetch_assoc();
            $out = ['enrolled' => (int) ($row['enrolled'] ?? 0), 'agent_installed' => (int) ($row['agent_installed'] ?? 0), 'mapped' => (int) ($row['mapped'] ?? 0), 'errors' => (int) ($row['errors'] ?? 0), 'list' => []];
        }
        $r = $mysqli->query('SELECT d.device_id, d.hostname FROM endpoint_agent_devices d JOIN endpoint_agent_mesh_nodes n ON n.device_id = d.device_id'
            . ' WHERE d.retired_at IS NULL AND d.revoked_at IS NULL ORDER BY n.updated_at DESC, d.device_id DESC LIMIT 5');
        while ($r instanceof \mysqli_result && ($row = $r->fetch_assoc())) {
            $out['list'][] = ['id' => (int) $row['device_id'], 'host' => (string) $row['hostname']];
        }
    } catch (\Throwable) {
        // tables of an older database: no counts
    }

    return $out;
}

/** The badge of one step. */
function rivetRmmMeshWizardBadge(bool $ok, int $n): string
{
    return $ok
        ? '<span class="badge text-bg-success" id="mw-badge-' . $n . '" data-state="ok"><i class="fas fa-check-circle me-1" aria-hidden="true"></i>Done</span>'
        : '<span class="badge text-bg-warning" id="mw-badge-' . $n . '" data-state="todo"><i class="fas fa-exclamation-circle me-1" aria-hidden="true"></i>To do</span>';
}

/** A step card. */
function rivetRmmMeshWizardCard(array $s, string $body): string
{
    $h = 'rivetRmmMeshH';

    return '<section class="card mb-3 mw-step" id="step-' . $s['n'] . '" data-step="' . $s['n'] . '" data-state="' . ($s['ok'] ? 'ok' : 'todo') . '" aria-labelledby="mw-h-' . $s['n'] . '">'
        . '<div class="card-header d-flex align-items-center gap-2 flex-wrap"><span class="badge bg-secondary-lt text-secondary fs-6">' . (int) $s['n'] . '</span>'
        . '<h3 class="card-title mb-0 me-auto" id="mw-h-' . $s['n'] . '">' . $h($s['title']) . '</h3>' . rivetRmmMeshWizardBadge((bool) $s['ok'], (int) $s['n']) . '</div>'
        . '<div class="card-body"><p class="mb-3" id="mw-sum-' . $s['n'] . '" role="status">' . $h($s['summary']) . '</p>' . $body . '</div></section>';
}

/** A labelled field with a one-sentence explanation. */
function rivetRmmMeshWizardField(string $id, string $label, string $control, string $help, string $col = 'col-lg-6'): string
{
    return '<div class="' . $col . '"><label class="form-label" for="' . $id . '">' . $label . '</label>' . $control . '<div class="form-text" id="' . $id . '-help">' . $help . '</div></div>';
}

/**
 * The page.
 *
 * @param array<string,mixed> $st rivetRmmMeshWizardState()
 * @param array<string,mixed>|null $probe the last certificate reading ({ok, pin?, subject?, not_after?, publicly_trusted?, note?, error?}), shown once
 * @param array<string,string> $keep what was typed when Core refused a save (address, domain, group)
 */
function rivetRmmMeshWizardPage(array $st, string $csrf, ?array $probe = null, array $keep = []): string
{
    $h = 'rivetRmmMeshH';
    $cfg = $st['cfg'];
    $view = $st['view'];
    $steps = $st['steps'];
    $url = (string) ($keep['mesh_url'] ?? $cfg['mesh_url'] ?? '');
    $dom = (string) ($keep['mesh_domain'] ?? $cfg['mesh_domain'] ?? '');
    $keySet = !empty($cfg['mesh_login_key_set']);
    $tok = '<input type="hidden" name="csrf_token" value="' . $h($csrf) . '">';
    $f = static fn (int $n, string $action): string => '<form action="post.php" method="post" autocomplete="off" class="row g-3" id="mw-form-' . $n . '-' . $action . '">' . '<input type="hidden" name="mw_action" value="' . $action . '">';

    $head = '<div class="d-flex align-items-center flex-wrap gap-2 mb-3"><h3 class="mb-0 me-auto"><i class="fas fa-fw fa-desktop me-2" aria-hidden="true"></i>Remote access (MeshCentral) setup</h3>'
        . '<span class="badge ' . ($st['all_done'] ? 'text-bg-success' : 'text-bg-warning') . ' fs-6" id="mw-progress" role="status">' . (int) $st['done'] . ' of 5 steps done</span>'
        . '<a class="btn btn-outline-secondary btn-sm" href="/admin/settings_endpoint_agent.php#mesh"><i class="fas fa-cog me-1" aria-hidden="true"></i>All MeshCentral settings</a></div>'
        . '<p class="text-muted">Remote access lets a technician open a screen, a terminal or the files of a device from its page in RivetIT. It runs through your own MeshCentral server. '
        . 'Follow the steps in order: a green tick means the step is done and checked, amber means something is still missing. Nothing is switched on until you save a step.</p>';
    if (!$st['db_ready']) {
        $head .= '<div class="alert alert-warning" role="alert">The database has to be updated first (Administration &gt; Updates): the device group and certificate settings need it.</div>';
    }

    // ---- 1 server
    $b1 = $f(1, 'save_server') . $tok
        . '<div class="col-12"><div class="form-check form-switch"><input type="checkbox" class="form-check-input" role="switch" id="mw_on" name="mesh_enabled" value="1"' . ((isset($keep['mesh_enabled']) ? $keep['mesh_enabled'] === '1' : (int) $cfg['mesh_enabled'] === 1) || !$keySet && $url === '' ? ' checked' : '') . '>'
        . '<label class="form-check-label" for="mw_on">Turn on remote access through MeshCentral</label></div><div class="form-text">Technicians can only open a remote session while this is on.</div></div>'
        . rivetRmmMeshWizardField('mw_url', 'MeshCentral address', '<input type="url" class="form-control" id="mw_url" name="mesh_url" maxlength="500" value="' . $h($url) . '" placeholder="https://mesh.example.com" aria-describedby="mw_url-help">',
            'The web address you sign in to MeshCentral with; it must start with https://.', 'col-lg-7')
        . rivetRmmMeshWizardField('mw_dom', 'Domain <span class="text-muted">(usually empty)</span>', '<input class="form-control" id="mw_dom" name="mesh_domain" maxlength="100" value="' . $h($dom) . '" aria-describedby="mw_dom-help">',
            'Fill this in only if your MeshCentral uses a named domain; most servers do not.', 'col-lg-5')
        . rivetRmmMeshWizardField('mw_key', 'Login-token key ' . ($keySet ? '<span class="badge bg-success">stored encrypted</span>' : ''),
            '<input type="password" class="form-control font-monospace" id="mw_key" name="mesh_login_key" autocomplete="new-password" spellcheck="false" placeholder="' . ($keySet ? 'Leave empty to keep the stored key' : 'Paste the long key here') . '" aria-describedby="mw_key-help">',
            'A long secret code from your MeshCentral server that lets RivetIT sign a technician in without a password; it is stored encrypted and never shown again.', 'col-12')
        . '<div class="col-12"><details class="small"><summary class="fw-bold">How do I get the login-token key?</summary><ol class="mt-2 mb-0 ps-3">'
        . '<li>Open a terminal on the computer that runs your MeshCentral server, in the MeshCentral folder.</li>'
        . '<li>Run <code>node node_modules/meshcentral --loginTokenKey</code>. It prints one long key made of the digits 0-9 and the letters a-f.</li>'
        . '<li>Copy that key and paste it into the box above, then press <strong>Save and test connection</strong>. If your MeshCentral documentation says the server must be restarted after creating the key, restart it.</li>'
        . '<li>RivetIT signs in as one limited MeshCentral user named <code>' . $h((string) ($cfg['mesh_account_template'] ?? 'rivetit-support')) . '</code>. Make sure that user exists in MeshCentral and can see your device group (step 2).</li></ol></details></div>'
        . '<div class="col-12 d-flex flex-wrap gap-2 align-items-center"><button class="btn btn-primary" name="test" value="1" type="submit" id="mw-save-test"><i class="fas fa-plug me-1" aria-hidden="true"></i>Save and test connection</button>'
        . '<button class="btn btn-outline-secondary" type="submit" id="mw-save-only"><i class="fas fa-save me-1" aria-hidden="true"></i>Save only</button>'
        . '<button class="btn btn-outline-secondary d-none" type="button" id="mw-retest" data-mw-live="1"><i class="fas fa-sync-alt me-1" aria-hidden="true"></i>Test again</button></div>'
        . '<div class="col-12 small" id="mw-live-conn" role="status" aria-live="polite" data-has-url="' . ($url !== '' ? '1' : '0') . '"></div></form>';

    // ---- 2 group
    $b2 = '<ol class="small ps-3"><li>Sign in to MeshCentral and open <strong>My Devices</strong>.</li><li>Press <strong>Add Device Group</strong>, give it a name (for example &quot;Acme laptops&quot;) and choose <strong>Manage using a software agent</strong>.</li>'
        . '<li>Open the new group, press <strong>Add Agent</strong>, choose <strong>Windows 64-bit (installer)</strong> and copy the download address it shows. You can paste the whole address below.</li></ol>'
        . $f(2, 'save_group') . $tok
        . rivetRmmMeshWizardField('mw_group', 'Device group address or id', '<div class="input-group"><input type="password" class="form-control font-monospace" id="mw_group" name="mesh_group_id" maxlength="2000" autocomplete="new-password" spellcheck="false" value="' . $h((string) ($keep['mesh_group_id'] ?? '')) . '" placeholder="' . ($st['steps'][1]['ok'] ? 'Saved. Paste a new address only to change it' : 'https://mesh.example.com/meshagents?id=4&amp;meshid=...') . '" aria-describedby="mw_group-help">'
            . '<button type="button" class="btn btn-outline-secondary" id="mw_group_show" aria-controls="mw_group" aria-label="Show or hide the device group address">Show</button></div>',
            'Paste the whole download address from MeshCentral (RivetIT finds the group id inside it) or just the long id; it works like an invitation key, so it is hidden here and never shown again.', 'col-12')
        . '<div class="col-12"><button class="btn btn-primary" type="submit" id="mw-save-group"' . ($st['db_ready'] ? '' : ' disabled') . '><i class="fas fa-save me-1" aria-hidden="true"></i>Save device group</button>'
        . ($st['steps'][1]['ok'] ? ' <button class="btn btn-outline-danger ms-2" type="submit" name="clear" value="1" id="mw-clear-group" data-confirm="Remove the saved device group? New installers will no longer offer the MeshCentral agent.">Remove the saved group</button>' : '') . '</div></form>';

    // ---- 3 trust
    $pin = $view === null ? '' : (string) $view['server_cert_pin'];
    $keepPin = (string) ($keep['mesh_server_cert_pin'] ?? $pin);
    $probeHtml = '';
    if ($probe !== null) {
        if (!empty($probe['ok']) && is_string($probe['pin'] ?? null) && !empty($probe['publicly_trusted'])) {
            $probeHtml = '<div class="alert alert-success mt-3 mb-0 py-2" role="status" id="mw_pin_result" data-publicly-trusted="1"><div class="fw-bold">Publicly trusted certificate: no pin needed</div>'
                . '<div class="small">Leave the pin empty. A certificate from a public authority is renewed every few months, and a pin would break at each renewal.</div></div>';
        } elseif (!empty($probe['ok']) && is_string($probe['pin'] ?? null)) {
            $probeHtml = '<div class="alert alert-info mt-3 mb-0 py-2" role="status" id="mw_pin_result" data-publicly-trusted="0"><div class="fw-bold">Proposed pin (not saved yet)</div>'
                . '<div class="font-monospace small text-break" id="mw_pin_proposed">' . $h($probe['pin']) . '</div>'
                . ((($probe['subject'] ?? '') !== '') ? '<div class="small text-muted">Certificate for ' . $h($probe['subject']) . (($probe['not_after'] ?? '') !== '' ? ', valid until ' . $h($probe['not_after']) : '') . '.</div>' : '')
                . '<div class="small">Compare it with the certificate hash MeshCentral shows (My Server, then Certificate hashes), then use it and save.</div>'
                . '<button type="button" class="btn btn-sm btn-outline-primary mt-1" id="mw_use_pin" data-mw-use-pin="' . $h($probe['pin']) . '">Use this pin</button></div>';
        } else {
            $probeHtml = '<div class="alert alert-warning mt-3 mb-0 py-2" role="alert" id="mw_pin_result">Could not read the certificate: ' . $h((string) ($probe['error'] ?? 'unknown error')) . '</div>';
        }
    }
    $b3 = '<div class="row g-3"><div class="col-lg-6"><div class="border rounded p-3 h-100"><h4 class="h6">My server has a normal, publicly trusted certificate</h4>'
        . '<p class="small text-muted mb-2">For example one from Let&#039;s Encrypt. <strong>Leave the pin empty.</strong> Nothing to save.</p>'
        . '<span class="small" id="mw-live-trust" role="status" aria-live="polite"></span></div></div>'
        . '<div class="col-lg-6"><div class="border rounded p-3 h-100"><h4 class="h6">My server has its own (private or self-signed) certificate</h4>'
        . '<p class="small text-muted mb-2">This is MeshCentral&#039;s default. Read the certificate from the server and save its pin, so the installer accepts only that certificate.</p>'
        . $f(3, 'probe_pin') . $tok . '<div class="col-12"><button class="btn btn-outline-primary" type="submit" id="mw_probe" ' . ($url === '' ? 'disabled' : '') . '><i class="fas fa-certificate me-1" aria-hidden="true"></i>Read it from the server</button></div></form></div></div></div>'
        . $probeHtml
        . $f(3, 'save_pin') . $tok
        . rivetRmmMeshWizardField('mw_pin', 'Certificate pin <span class="text-muted">(only for a private certificate)</span>', '<input class="form-control font-monospace" id="mw_pin" name="mesh_server_cert_pin" maxlength="100" autocomplete="off" spellcheck="false" value="' . $h($keepPin) . '" aria-describedby="mw_pin-help">',
            'A fingerprint of the server&#039;s certificate; leave it empty if the certificate is publicly trusted.', 'col-lg-8 mt-3')
        . '<div class="col-12"><button class="btn btn-primary" type="submit" id="mw-save-pin"' . ($st['db_ready'] ? '' : ' disabled') . '><i class="fas fa-save me-1" aria-hidden="true"></i>Save pin</button>'
        . ($pin !== '' ? ' <button class="btn btn-outline-secondary ms-2" type="submit" name="clear" value="1" id="mw-clear-pin">Remove the pin (public certificate)</button>' : '') . '</div></form>';

    // ---- 4 installer
    $b4 = '<p class="small mb-2">When you create an installer, tick <strong>Also install the MeshCentral agent</strong>. After the RivetIT agent is installed, the installer fetches the MeshCentral agent from your own server, so Remote works without anyone typing a device id. '
        . 'If the MeshCentral part fails, the RivetIT agent is still installed and the device page shows what went wrong.</p>'
        . '<a class="btn btn-' . ($steps[3]['ok'] ? 'primary' : 'outline-secondary') . '" href="' . RIVET_RMM_ADD_DEVICE_URL . '" id="mw-create-installer"><i class="fas fa-tools me-1" aria-hidden="true"></i>Create installer</a>';

    // ---- 5 test
    $d = $st['devices'];
    $list = '';
    foreach ($d['list'] as $x) {
        $list .= '<li><a href="/agent/rmm_agent_device.php?device_id=' . (int) $x['id'] . '">' . $h($x['host'] !== '' ? $x['host'] : 'device #' . $x['id']) . '</a></li>';
    }
    $b5 = '<ol class="small ps-3"><li>Create an installer with the box from step 4 ticked and run it on one test PC.</li><li>Wait a few minutes. The PC appears in <a href="/agent/rmm_fleet.php">Agent fleet</a> and its page shows the MeshCentral agent as installed.</li>'
        . '<li>Open the device and press <strong>Remote access</strong>. A new tab opens on MeshCentral&#039;s remote screen; press MeshCentral&#039;s own <strong>Connect</strong> button once.</li></ol>'
        . '<ul class="small list-unstyled mb-2" id="mw-devices"><li>Enrolled devices: <strong id="mw-n-enrolled">' . (int) $d['enrolled'] . '</strong></li>'
        . '<li>Reporting the MeshCentral agent as installed: <strong id="mw-n-installed">' . (int) $d['agent_installed'] . '</strong></li>'
        . '<li>Connected to MeshCentral (Remote works): <strong id="mw-n-mapped">' . (int) $d['mapped'] . '</strong></li>'
        . ($d['errors'] > 0 ? '<li class="text-warning-emphasis">MeshCentral agent install errors: <strong id="mw-n-errors">' . (int) $d['errors'] . '</strong> (open the device page for the reason)</li>' : '') . '</ul>'
        . ($list !== '' ? '<div class="small fw-semibold">Try Remote on:</div><ul class="small" id="mw-device-list">' . $list . '</ul>' : '')
        . '<a class="btn btn-outline-primary btn-sm" href="/agent/rmm_fleet.php"><i class="fas fa-satellite me-1" aria-hidden="true"></i>Open Agent fleet</a>';

    $o = $head
        . rivetRmmMeshWizardCard($steps[0], $b1) . rivetRmmMeshWizardCard($steps[1], $b2) . rivetRmmMeshWizardCard($steps[2], $b3) . rivetRmmMeshWizardCard($steps[3], $b4) . rivetRmmMeshWizardCard($steps[4], $b5)
        . rivetRmmMeshWizardTroubleshooting();

    return '<div id="mw-root" data-post-url="post.php" data-csrf="' . $h($csrf) . '" data-all-done="' . ($st['all_done'] ? '1' : '0') . '">' . $o . '</div>';
}

/** The troubleshooting list, from docs/ENDPOINT_AGENT.md sections 11 to 14 and RivetCore docs/rmm/REMOTE_AND_LOGS.md. */
function rivetRmmMeshWizardTroubleshooting(): string
{
    $item = static fn (string $id, string $title, string $body): string => '<details class="border rounded p-2 mb-2" id="' . $id . '"><summary class="fw-bold">' . $title . '</summary><div class="small mt-2">' . $body . '</div></details>';

    return '<section class="card mb-3" id="troubleshooting" aria-labelledby="mw-ts-h"><div class="card-header"><h3 class="card-title mb-0" id="mw-ts-h"><i class="fas fa-life-ring me-2" aria-hidden="true"></i>Troubleshooting</h3></div><div class="card-body">'
        . $item('ts-home', 'Remote opens MeshCentral&#039;s home page instead of the device',
            '<p class="mb-1">MeshCentral could not find the device from the id RivetIT sent.</p><ul class="mb-0"><li>Open the device in MeshCentral and copy the address bar. On the device page in RivetIT (Remote access card) paste it into the <strong>Mesh node</strong> box and save: RivetIT accepts the whole address, or the 64 characters after <code>gotonode=</code>.</li>'
            . '<li>Check that the user RivetIT signs in as (<code>rivetit-support</code> unless you changed it) can see the device group in MeshCentral.</li><li>Expect to press MeshCentral&#039;s <strong>Connect</strong> button once in the new tab; MeshCentral does not connect by itself.</li></ul>')
        . $item('ts-node', 'The Remote button is grey: &quot;not mapped to a MeshCentral node&quot;',
            '<p class="mb-1">The device is not linked to a MeshCentral device yet.</p><ul class="mb-0"><li>Install the MeshCentral agent on it: on the device page press <strong>Install MeshCentral agent</strong> (the RivetIT agent must be up to date), or reinstall with the box from step 4 ticked. Once the agent reports its id the device is linked automatically.</li>'
            . '<li>Or link it by hand: paste the MeshCentral id as described above.</li><li>If the device page says the agent reported a <em>different</em> id than the one you entered, change the id there, or turn on &quot;Let the agent&#039;s node replace a node I mapped by hand&quot; in <a href="/admin/settings_endpoint_agent.php#mesh">All MeshCentral settings</a>.</li>'
            . '<li>Two devices with the same id usually means a cloned disk image: check which machine really owns the id and reinstall the MeshCentral agent on the other one.</li></ul>')
        . $item('ts-agent', 'The MeshCentral agent was not installed',
            '<p class="mb-1">The RivetIT agent is installed and working even when the MeshCentral part fails; the device page shows the reason. Common ones:</p><ul class="mb-0"><li><strong>The Mesh server answered HTTP 404</strong> or <strong>sent a web page instead of an installer</strong>: the device group address is wrong. Copy it again from MeshCentral (device group, Add Agent) and save it in step 2.</li>'
            . '<li><strong>The Mesh installer exited with a code</strong>: the last lines are in the agent&#039;s <code>install.log</code> (Windows <code>%ProgramData%\\RivetIT\\Agent\\install.log</code>, Linux <code>/var/lib/rivetit-agent/install.log</code>).</li>'
            . '<li><strong>No Mesh agent id is known for this platform</strong>: add the id under the advanced part of <a href="/admin/settings_endpoint_agent.php#mesh">All MeshCentral settings</a>.</li>'
            . '<li>The installer on the PC was made before you finished steps 1 to 3: create a new installer, or press <strong>Install MeshCentral agent</strong> on the device page.</li></ul>')
        . $item('ts-cert', 'Certificate errors',
            '<ul class="mb-0"><li><strong>The Mesh server certificate does not match the pinned hash</strong>: the pin is wrong or the certificate was renewed. Read it from the server again (step 3) and save the new pin; for a certificate that renews itself (Let&#039;s Encrypt) remove the pin instead.</li>'
            . '<li><strong>Certificate signed by unknown authority</strong>: the certificate is private or self-signed and no pin is saved. Read it from the server and save the pin (step 3).</li>'
            . '<li>The connection test in step 1 fails: check the address starts with <code>https://</code>, that the server is reachable from this RivetIT server, and that RivetIT&#039;s network rules allow it.</li></ul>')
        . '<p class="small text-muted mt-3 mb-0">' . rivetRmmMeshH(RIVET_RMM_MESH_VERIFY_WARNING) . '</p></div></section>';
}
