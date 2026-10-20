<?php

/*
 * RivetCore 1.0.0-rc.12: the install settings of the optional MeshCentral agent, printed inside the MeshCentral card of Administration > Endpoint agent
 * (admin/settings_endpoint_agent.php, id="mesh"), and the Mesh state of one device (installed / version / node id / error, with the hint for a conflict
 * or a node an administrator mapped by hand) for the device and asset pages.
 *
 * Nothing here decides anything. Core validates and saves the settings (RmmAdmin::saveMesh, which also reads the Mesh server's certificate for the pin
 * proposal and never saves that proposal), maps the node id (MeshAgentService) and says what is wrong; this file only prints the fields and the state.
 * Everything printed is escaped. The device group id and the pin are administrator-only values: the device functions below never print either.
 */

require_once __DIR__ . '/rivet_time.php';

if (!function_exists('rivetRmmMeshH')) {
    function rivetRmmMeshH($v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/** The warning Core's guide gives about everything that talks to MeshCentral itself. Shown above the advanced fields and in the step-by-step help. */
const RIVET_RMM_MESH_VERIFY_WARNING = 'Everything about talking to MeshCentral was written from its documentation and tested only against mocks and fixtures. Before a fleet roll-out, try one machine of each operating system and verify against your MeshCentral version: the download address, the agent ids, the install arguments, the uninstall arguments and where the node id can be read.';

/**
 * What the install card needs, or null when the 0023 tables are not there yet (a database that has not been updated: say so, print no fields).
 *
 * @return array{group_id:string,group_id_url:string,server_cert_pin:string,auto_map_overwrite:bool,agent_ids:array<string,int>,install_args:array<string,list<string>>,effective_agent_ids:array<string,int>,effective_install_args:array<string,list<string>>,default_agent_ids:array<string,int>,ready:bool,refusal:string}|null
 */
function rivetRmmMeshInstallView(\RivetCore\Rmm\RmmModule $rmm): ?array
{
    try {
        $m = $rmm->meshAgentSettings()->get();
        $t = $rmm->meshAgentSettings()->effectiveTables();
        $why = $rmm->meshAgents()->installRefusal();
    } catch (\Throwable) {
        return null;
    }

    return ['group_id' => $m['group_id'], 'group_id_url' => (string) ($m['group_id_url'] ?? ''), 'server_cert_pin' => $m['server_cert_pin'], 'auto_map_overwrite' => $m['auto_map_overwrite'], 'agent_ids' => $m['agent_ids'], 'install_args' => $m['install_args'],
        'effective_agent_ids' => $t['agent_ids'], 'effective_install_args' => $t['install_args'], 'default_agent_ids' => \RivetCore\Rmm\Mesh\MeshAgentCatalog::DEFAULT_AGENT_IDS,
        'ready' => $why === null, 'refusal' => (string) $why,
        'hide_available' => $rmm->meshAgentSettings()->hideAvailable(), 'hide' => $m['hide'], 'hide_tools' => $m['hide_tools']];
}

const RIVET_RMM_MESH_PLATFORMS = ['windows/amd64', 'windows/386', 'windows/arm64', 'windows/arm', 'linux/amd64', 'linux/386', 'linux/arm64', 'linux/arm'];

/** @param array<string,mixed> $v a pretty JSON object, or an empty string for none */
function rivetRmmMeshJson(array $v): string
{
    return $v === [] ? '' : (string) json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

/**
 * RivetCore 1.0.0-rc.17: how much of MeshCentral's own interface the new tab shows (Core's `mesh_hide`, MeshCentral's `hide` bitmask, appended to the launch URL).
 * Printed only when the database has migration 0026 (otherwise Core would refuse the save); Core validates the numbers (0 to 255), this prints and posts them.
 *
 * @param array<string,mixed>|null $v rivetRmmMeshInstallView()
 * @param array<string,mixed> $keep what was typed when Core refused a save
 */
function rivetRmmMeshHideFields(?array $v, array $keep = []): string
{
    $h = 'rivetRmmMeshH';
    if ($v === null || empty($v['hide_available'])) {
        return '';
    }
    $hide = (string) ($keep['mesh_hide'] ?? $v['hide']);
    $tools = (string) ($keep['mesh_hide_tools'] ?? ($v['hide_tools'] === null ? '' : $v['hide_tools']));

    return '<input type="hidden" name="mesh_hide_present" value="1">'
        . '<div class="col-lg-6"><label class="form-label" for="mc_hide">Hide MeshCentral interface elements <span class="text-muted">(0 to 255)</span></label>'
        . '<input type="number" class="form-control" id="mc_hide" name="mesh_hide" min="0" max="255" step="1" value="' . $h($hide) . '" aria-describedby="mc_hide_help">'
        . '<div class="form-text" id="mc_hide_help">31 = show only the remote screen (no MeshCentral header, tabs, footer or device list), 0 = full MeshCentral. It is MeshCentral\'s <code>hide</code> bitmask: 1 header, 2 top bar, 4 footer, 8 titles, 16 left bar and device list, 32 back button. MeshCentral does not connect by itself: click <em>Connect</em> in the new tab. Not yet checked against a live MeshCentral version: if a bit hides something else on yours, change the number.</div></div>'
        . '<div class="col-lg-6"><label class="form-label" for="mc_hide_tools">Hide for Terminal and Files <span class="text-muted">(optional)</span></label>'
        . '<input type="number" class="form-control" id="mc_hide_tools" name="mesh_hide_tools" min="0" max="255" step="1" value="' . $h($tools) . '" placeholder="same as the desktop" aria-describedby="mc_hide_tools_help">'
        . '<div class="form-text" id="mc_hide_tools_help">Empty = the same value as above. 0 opens the Terminal and Files views inside the full MeshCentral interface.</div></div>';
}

/**
 * The install settings, as form fields for the card's own form (so one Save keeps working): device group id, certificate pin (with "Read it from the server" and the
 * proposal from the last read), the overwrite switch and the collapsed advanced corrections.
 *
 * @param array<string,mixed>|null $probe the last pin proposal ({ok, pin?, subject?, not_after?, error?, url?}) or null
 */
function rivetRmmMeshInstallFields(?array $v, ?array $probe, array $keep = []): string
{
    $h = 'rivetRmmMeshH';
    // rc.15: what the field shows is the group id in MeshCentral's own 64-character URL form (Core's mesh_group_id_url; the canonical hex stays in mesh_group_id and is not printed)
    $keepGroup = (string) ($keep['mesh_group_id'] ?? (($v['group_id_url'] ?? '') !== '' ? $v['group_id_url'] : ($v['group_id'] ?? '')));
    $keepPin = (string) ($keep['mesh_server_cert_pin'] ?? ($v['server_cert_pin'] ?? ''));
    $o = '<div class="col-12"><hr class="my-1"><h5 class="mb-1" id="mesh-install-title">Install the MeshCentral agent with the RivetIT agent <span class="badge bg-secondary-lt text-secondary">optional</span></h5>'
        . '<p class="small text-muted mb-2">Off by default. When you issue an installer you can tick <strong>Also install the MeshCentral agent</strong>; the installer then pulls the Mesh agent for <em>your</em> Mesh server and device group (never a bundled binary) and the device\'s Mesh node is mapped to it automatically, so Remote works without anybody pasting a node id. A failure to install Mesh never fails the RivetIT install.</p>';
    if ($v === null) {
        return $o . '<div class="alert alert-warning mb-0" role="alert">Run the database update first (Administration &rarr; Updates): the Mesh agent install settings need it.</div></div>';
    }
    $o .= '<input type="hidden" name="mesh_install_present" value="1">';
    $o .= '<details class="mb-3" id="mesh-install-help"><summary class="small fw-bold">Set it up, step by step</summary><ol class="small ps-3 mt-2 mb-0">'
        . '<li><strong>Create the device group in MeshCentral.</strong> Sign in to your Mesh server, My Devices, <em>Add Device Group</em>, name it (for example "Acme laptops"), type <em>Manage using a software agent</em>. The devices will join this group.</li>'
        . '<li><strong>Get the group id (meshid).</strong> Open the group, click <em>Add Agent</em>, choose <em>Windows 64-bit</em> (the installer) and copy the download address it shows: <code>https://&lt;your mesh&gt;/meshagents?id=4&amp;meshid=&lt;64 characters&gt;&amp;installflags=0</code>. Paste the whole address, or just the 64 characters after <code>meshid=</code> (letters, digits, <code>@</code> and <code>$</code>: it is <strong>not</strong> hex). RivetIT reads nothing from the network. MeshCentral\'s <code>mesh//...</code> form and the 96-character hex form are accepted too. <strong>Treat the group id like an invitation key</strong>: anyone who has it and can reach your Mesh server can download an agent that joins the group.</li>'
        . '<li><strong>Decide how the installer trusts your Mesh server.</strong> A certificate from a public CA (for example Let\'s Encrypt): <strong>leave the pin empty</strong>, the installer uses the machine\'s trusted roots (a Let\'s Encrypt certificate is renewed every 60 to 90 days and a pin of it would break at each renewal). A private CA or MeshCentral\'s default self-signed certificate: pin it with <em>Read it from the server</em> below, compare the hash with the one MeshCentral shows under My Server, then save.</li>'
        . '<li><strong>Save here</strong> (MeshCentral enabled, the address, the device group id, the pin if any).</li>'
        . '<li><strong>Issue an installer with the box ticked</strong> (Endpoints &rarr; Agent Fleet &rarr; Add device, or the Deployment card). If Mesh cannot support it RivetIT says so and creates nothing.</li>'
        . '<li><strong>Run it</strong> like any RivetIT installer. On Windows the RivetIT agent installs first, then the Mesh agent. On Linux the command carries <code>--install-mesh-agent</code> with your Mesh address and group.</li>'
        . '<li><strong>Check the result</strong> on the device page: the Mesh state shows installed or the error, the node id and whether it was mapped.</li></ol></details>';
    $o .= '<div class="col-lg-6"><label class="form-label" for="mc_group">Device group id <span class="text-muted">(meshid)</span></label>'
        . '<div class="input-group"><input type="password" class="form-control font-monospace" id="mc_group" name="mesh_group_id" maxlength="2000" autocomplete="new-password" spellcheck="false" value="' . $h($keepGroup) . '" placeholder="Paste the MeshCentral download address, or its meshid" aria-describedby="mc_group_help">'
        . '<button type="button" class="btn btn-outline-secondary" data-toggle="password" id="mc_group_show" aria-label="Show or hide the device group id" aria-controls="mc_group"><i class="fas fa-eye" aria-hidden="true"></i></button></div>'
        . '<div class="form-text" id="mc_group_help">Paste the download address from MeshCentral (device group &rarr; <em>Add Agent</em> &rarr; Windows 64-bit installer) or just its <code>meshid</code>. It is 64 characters, not hex. Treat it like an invitation key: it is hidden here until you press the eye, and it is only ever printed on this page and inside the installer commands.</div></div>';
    $o .= '<div class="col-lg-6"><label class="form-label" for="mc_pin">Server certificate pin <span class="text-muted">(optional)</span></label>'
        . '<div class="input-group"><input class="form-control font-monospace" id="mc_pin" name="mesh_server_cert_pin" maxlength="100" autocomplete="off" spellcheck="false" value="' . $h($keepPin) . '" placeholder="empty = public CA certificate" aria-describedby="mc_pin_help">'
        . '<button class="btn btn-outline-secondary" type="submit" name="probe_mesh_pin" value="1" id="mc_pin_probe">Read it from the server</button></div>'
        . '<div class="form-text" id="mc_pin_help"><strong>Leave this empty when the Mesh server\'s certificate is publicly trusted</strong> (for example Let\'s Encrypt, which renews every 60 to 90 days and would break a pin). Pin only a private CA or a self-signed certificate: the SHA-384 (96 hex) or SHA-256 (64 hex) hash of the Mesh server\'s certificate. <em>Read it from the server</em> saves the other fields of this card, connects to the address above and shows the hash it found. It is trust on first use, so compare it with the hash MeshCentral shows (My Server) before you use it; nothing is saved from the reading itself.</div>';
    if ($probe !== null) {
        $note = is_string($probe['note'] ?? null) && $probe['note'] !== '' ? '<div class="small mt-1" id="mc_pin_note">' . $h($probe['note']) . '</div>' : '';
        $cert = (($probe['subject'] ?? '') !== '' ? 'Certificate for ' . $h($probe['subject']) . '. ' : '') . (($probe['not_after'] ?? '') !== '' ? 'Valid until ' . $h($probe['not_after']) . '. ' : '');
        if (!empty($probe['ok']) && is_string($probe['pin'] ?? null) && !empty($probe['publicly_trusted'])) {
            // rc.15: a certificate the system roots verify needs no pin (it changes at each renewal): show Core's note, offer nothing to save
            $o .= '<div class="alert alert-success mt-2 mb-0 py-2" role="status" id="mc_pin_proposal" data-publicly-trusted="1"><div class="small fw-bold">Publicly trusted certificate: no pin needed</div>'
                . $note . '<div class="small text-muted">' . $cert . 'Leave the pin field empty.</div></div>';
        } elseif (!empty($probe['ok']) && is_string($probe['pin'] ?? null)) {
            $o .= '<div class="alert alert-info mt-2 mb-0 py-2" role="status" id="mc_pin_proposal" data-publicly-trusted="0"><div class="small fw-bold">Proposed pin (not saved)</div>'
                . '<div class="font-monospace small text-break" id="mc_pin_proposed">' . $h($probe['pin']) . '</div>'
                . $note . '<div class="small text-muted">' . $cert . 'Compare it with the hash MeshCentral shows, then use it and press Save.</div>'
                . '<button type="button" class="btn btn-sm btn-outline-primary mt-1" data-ea-use-pin="' . $h($probe['pin']) . '" data-ea-use-pin-target="mc_pin">Use this pin</button></div>';
        } else {
            $o .= '<div class="alert alert-warning mt-2 mb-0 py-2" role="alert" id="mc_pin_proposal">Could not read the certificate: ' . $h((string) ($probe['error'] ?? 'unknown error')) . '</div>';
        }
    }
    $o .= '</div>';
    $o .= '<div class="col-12 mt-2"><div class="form-check form-switch"><input type="checkbox" class="form-check-input" role="switch" id="mc_overwrite" name="mesh_auto_map_overwrite" value="1"' . ($v['auto_map_overwrite'] ? ' checked' : '') . '><label class="form-check-label" for="mc_overwrite">Let the agent\'s node replace a node I mapped by hand</label></div>'
        . '<div class="form-text">Off: a node an administrator mapped by hand is kept and the device page says the agent reported a different one. On: the agent\'s node replaces it (audited). A node already mapped to another live device is never moved, whatever this says.</div></div>';
    // advanced
    $ids = '';
    foreach ($v['effective_agent_ids'] as $k => $n) {
        $ids .= '<tr><td class="font-monospace">' . $h($k) . '</td><td>' . (int) $n . '</td><td>' . (isset($v['agent_ids'][$k]) ? '<span class="badge text-bg-warning">corrected</span>' : '<span class="text-muted">built in</span>') . '</td></tr>';
    }
    $args = '';
    foreach ($v['effective_install_args'] as $k => $a) {
        $args .= '<tr><td class="font-monospace">' . $h($k) . '</td><td class="font-monospace">' . $h(implode(' ', $a)) . '</td><td>' . (isset($v['install_args'][$k]) ? '<span class="badge text-bg-warning">corrected</span>' : '<span class="text-muted">built in</span>') . '</td></tr>';
    }
    $idFields = '';
    foreach (RIVET_RMM_MESH_PLATFORMS as $plat) {
        $fid = 'mc_aid_' . str_replace('/', '_', $plat);
        $cur = isset($keep['mesh_aid']) ? (string) ($keep['mesh_aid'][str_replace('/', '_', $plat)] ?? '') : (isset($v['agent_ids'][$plat]) ? (string) (int) $v['agent_ids'][$plat] : '');
        $idFields .= '<div class="col-sm-6"><label class="form-label small mb-1" for="' . $fid . '">' . $h($plat) . '</label><input type="number" class="form-control form-control-sm" id="' . $fid . '" name="mesh_aid[' . $h(str_replace('/', '_', $plat)) . ']" min="1" max="255" step="1" value="' . $h($cur) . '"'
            . ' placeholder="' . $h((string) (\RivetCore\Rmm\Mesh\MeshAgentCatalog::DEFAULT_AGENT_IDS[$plat] ?? '')) . '"></div>';
    }
    $argFields = '';
    foreach (['windows' => 'Windows', 'linux' => 'Linux'] as $os => $word) {
        $cur = isset($keep['mesh_args']) ? (string) ($keep['mesh_args'][$os] ?? '') : implode(' ', (array) ($v['install_args'][$os] ?? []));
        $argFields .= '<div class="col-12"><label class="form-label small mb-1" for="mc_args_' . $os . '">' . $word . '</label><input type="text" class="form-control form-control-sm font-monospace" id="mc_args_' . $os . '" name="mesh_args[' . $os . ']" maxlength="400" spellcheck="false" autocomplete="off" value="' . $h($cur) . '" placeholder="' . $h(implode(' ', \RivetCore\Rmm\Mesh\MeshAgentCatalog::DEFAULT_INSTALL_ARGS[$os])) . '"></div>';
    }
    $o .= '<div class="col-12"><details id="mesh-install-advanced"' . (isset($keep['mesh_aid']) || isset($keep['mesh_args']) ? ' open' : '') . '><summary class="small fw-bold">Advanced: agent ids and install arguments</summary>'
        . '<div class="alert alert-warning small mt-2" role="note"><i class="fas fa-exclamation-triangle me-1" aria-hidden="true"></i>' . $h(RIVET_RMM_MESH_VERIFY_WARNING) . ' The Windows ARM64 agent id (36) is the least certain.</div>'
        . '<div class="row g-3"><div class="col-lg-6"><div class="fw-semibold mb-1" id="mc_ids_h">Agent id for each platform</div>'
        . '<div class="form-text mb-2" id="mc_ids_help">Leave a box empty to use the built-in id shown in grey. Fill it in only if MeshCentral lists a different number (whole number from 1 to 255, under My Server, Mesh Agents). The ids travel inside the installer, so a correction needs no new agent.</div>'
        . '<div class="row g-2" role="group" aria-labelledby="mc_ids_h">' . $idFields . '</div>'
        . '<div class="table-responsive mt-2"><table class="table table-sm small mb-0"><caption class="visually-hidden">Agent ids an installer carries</caption><thead><tr><th scope="col">Platform</th><th scope="col">Agent id</th><th scope="col">Source</th></tr></thead><tbody>' . $ids . '</tbody></table></div></div>'
        . '<div class="col-lg-6"><div class="fw-semibold mb-1" id="mc_args_h">Install arguments for each system</div>'
        . '<div class="form-text mb-2" id="mc_args_help">Leave a box empty to use the built-in arguments (Windows <code>-fullinstall</code>, Linux <code>-install</code>). Otherwise type the arguments separated by spaces; only simple ones are accepted (letters, digits and <code>= _ . : / @ -</code>), at most 8.</div>'
        . '<div class="row g-2" role="group" aria-labelledby="mc_args_h">' . $argFields . '</div>'
        . '<div class="table-responsive mt-2"><table class="table table-sm small mb-0"><caption class="visually-hidden">Install arguments an installer carries</caption><thead><tr><th scope="col">Platform</th><th scope="col">Arguments</th><th scope="col">Source</th></tr></thead><tbody>' . $args . '</tbody></table></div></div></div></details></div>';
    $o .= '<div class="col-12 small ' . ($v['ready'] ? 'text-success' : 'text-muted') . '" id="mesh-install-ready" role="status">' . ($v['ready'] ? '<i class="fas fa-check-circle me-1" aria-hidden="true"></i>Ready: the tick-box appears in the installer dialogs.' : 'Not ready: ' . $h($v['refusal'])) . '</div>';

    return $o . '</div>';
}

/**
 * The Mesh agent state of one device, from RmmReadModel::detail()['mesh_agent'] (null when the device never reported one). The node-mapping control is not part of this:
 * it stays where it was, next to it.
 *
 * @param array<string,mixed>|null $m MeshAgentService::stateFor()
 * @param array<string,mixed>      $opt {conflict_url?: ?string the other device's page (same department only)}
 */
function rivetRmmMeshAgentHtml(?array $m, array $opt = []): string
{
    $h = 'rivetRmmMeshH';
    if ($m === null) {
        return '<div class="small text-muted" id="rmm-mesh-agent" data-state="none">No Mesh agent has been reported by this device. Tick <em>Also install the MeshCentral agent</em> when you issue its installer to install it with the RivetIT agent.</div>';
    }
    $state = (string) ($m['map_state'] ?? '');
    $rows = '';
    $row = static fn (string $k, string $v): string => '<dt class="col-5 col-md-4 text-muted fw-normal">' . $k . '</dt><dd class="col-7 col-md-8 mb-1">' . $v . '</dd>';
    $rows .= $row('Mesh agent', !empty($m['installed']) ? '<span class="badge text-bg-success">Installed</span>' : '<span class="badge text-bg-secondary">Not installed</span>');
    if (($m['version'] ?? null) !== null) {
        $rows .= $row('Version', $h($m['version']));
    }
    $rows .= $row('Node id', ($m['node_id'] ?? null) !== null ? '<span class="font-monospace text-break">' . $h($m['node_id']) . '</span>' : '<span class="text-muted">not reported</span>');
    if ($state === 'mapped') {
        $rows .= $row('Mapping', '<span class="badge text-bg-success">Mapped automatically</span>');
    }
    if (($m['reported_at'] ?? null) !== null) {
        $rows .= $row('Last reported', $h(rivetFormatTime($m['reported_at'], 'utc') ?: (string) $m['reported_at']));
    }
    $hint = '';
    if (($m['error'] ?? null) !== null) {
        $hint .= '<div class="alert alert-danger py-2 mb-2" role="alert" data-mesh-hint="error"><i class="fas fa-times-circle me-1" aria-hidden="true"></i><strong>The Mesh agent install failed.</strong> ' . $h($m['error'])
            . ' <span class="text-muted">The RivetIT agent itself is installed and working; fix the cause in the MeshCentral settings and install the Mesh agent again.</span></div>';
    }
    if ($state === 'conflict') {
        $other = $m['conflict_device_id'] ?? null;
        $link = ($other !== null && ($opt['conflict_url'] ?? null) !== null) ? ' The other device is <a href="' . $h($opt['conflict_url']) . '">device #' . (int) $other . '</a>.' : ' The other device belongs to another department, or is not shown to you.';
        $hint .= '<div class="alert alert-warning py-2 mb-2" role="alert" data-mesh-hint="conflict"><i class="fas fa-exclamation-triangle me-1" aria-hidden="true"></i><strong>This device reported a Mesh node that is already mapped to another device, so nothing was changed.</strong>'
            . $link . ' A cloned disk image carries the same Mesh node id: check which machine really owns the node, then reinstall the Mesh agent on the other one.</div>';
    } elseif ($state === 'kept_manual') {
        $hint .= '<div class="alert alert-info py-2 mb-2" role="status" data-mesh-hint="kept_manual"><i class="fas fa-info-circle me-1" aria-hidden="true"></i><strong>The agent reported a different Mesh node than the one an administrator mapped by hand, so the mapping was kept.</strong>'
            . ' To use the agent\'s node, change the mapping below or turn on <em>Let the agent\'s node replace a node I mapped by hand</em> in the MeshCentral settings.</div>';
    }

    return '<div id="rmm-mesh-agent" data-state="' . $h($state !== '' ? $state : (!empty($m['installed']) ? 'installed' : 'reported')) . '">' . $hint . '<dl class="row small mb-0">' . $rows . '</dl></div>';
}
