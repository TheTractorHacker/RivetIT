<?php

/*
 * Administration > Endpoint agent: the cards of RivetCore 1.0.0-rc.11 (remote tools, file transfer, log collection, scale settings, partitioning, external metric store).
 * Pure rendering: rivetRmmUiScaleCards() takes everything it prints in $ctx (gathered by admin/settings_endpoint_agent.php) and returns HTML; it queries nothing.
 * Every control posts to admin/post/settings_endpoint_agent.php, which authorizes and validates through RivetCore. Behaviour (acknowledgement boxes, rows, the
 * Test button's in-page answer) is js/rmm_scale.js; every form also works without it. The external store's addresses and token are never printed.
 */

require_once __DIR__ . '/rmm_ui_render.php';
require_once __DIR__ . '/rmm_scale_admin.php';

/** A card shell for the administration page (the same markup the other cards of the page use). */
function rivetRmmScaleCard(string $id, string $icon, string $title, bool $on, string $body, ?string $pill = null): string
{
    return '<div class="card mb-3 rmm-scale-card" id="' . rmmH($id) . '"><div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">'
        . '<h4 class="card-title mb-0"><i class="fas fa-fw fa-' . rmmH($icon) . ' me-2" aria-hidden="true"></i>' . rmmH($title) . '</h4>'
        . '<span class="badge bg-' . ($on ? 'success' : 'secondary') . '">' . rmmH($pill ?? ($on ? 'On' : 'Off')) . '</span></div><div class="card-body">' . $body . '</div></div>';
}

/** A number input with its label and help. $old is the refused form of the card (what the person typed wins over the stored value). */
function rivetRmmScaleNumber(string $id, string $name, string $label, $value, string $help, array $old, int $min, int $max, string $col = 'col-sm-6 col-lg-3', string $extra = ''): string
{
    $v = array_key_exists($name, $old) ? (string) $old[$name] : (string) $value;

    return '<div class="' . $col . '"><label class="form-label" for="' . $id . '">' . rmmH($label) . '</label><input type="number" inputmode="numeric" class="form-control" id="' . $id . '" name="' . rmmH($name)
        . '" min="' . $min . '" max="' . $max . '" step="1" value="' . rmmH($v) . '" aria-describedby="' . $id . '_h"' . $extra . '><div class="form-text" id="' . $id . '_h">' . $help . '</div></div>';
}

/**
 * A number with a unit list (KB / MB / GB, minutes / hours / days). The stored whole number is shown in the largest unit that divides it exactly.
 *
 * @param array<string,int> $units unit => size in base units
 */
function rivetRmmScaleUnitField(string $id, string $name, string $label, int $value, array $units, string $help, array $old, string $col = 'col-sm-6 col-lg-4'): string
{
    if (array_key_exists($name, $old)) {
        $num = (string) $old[$name];
        $unit = (string) ($old[$name . '_unit'] ?? array_key_first($units));
    } else {
        [$n, $unit] = rivetRmmScaleUnitValue($value, $units);
        $num = (string) $n;
    }
    $opts = '';
    foreach (array_keys($units) as $u) {
        $opts .= '<option value="' . rmmH($u) . '"' . ($u === $unit ? ' selected' : '') . '>' . rmmH($u) . '</option>';
    }

    return '<div class="' . $col . '"><label class="form-label" for="' . $id . '">' . rmmH($label) . '</label><div class="input-group"><input type="number" inputmode="decimal" class="form-control" id="' . $id . '" name="' . rmmH($name)
        . '" min="0" step="any" value="' . rmmH($num) . '" aria-describedby="' . $id . '_h"><label class="visually-hidden" for="' . $id . '_u">Unit for ' . rmmH($label) . '</label><select class="form-select flex-grow-0" style="width:auto" id="' . $id . '_u" name="'
        . rmmH($name) . '_unit">' . $opts . '</select></div><div class="form-text" id="' . $id . '_h">' . $help . '</div></div>';
}

/** The acknowledgement box that goes with a risky switch: the verbatim warning, a required tick (required by the script while the switch is being turned on; the server refuses without it either way). */
function rivetRmmScaleAck(string $id, string $name, string $warning, string $label, bool $alreadyOn): string
{
    return '<div class="rmm-scale-ack border rounded p-3 mb-3' . ($alreadyOn ? ' d-none' : '') . '" data-rmm-scale-ack><p class="small mb-2" id="' . $id . '_t"><i class="fas fa-exclamation-triangle text-warning me-1" aria-hidden="true"></i><strong>Read this before you turn it on.</strong> '
        . rmmH($warning) . '</p><div class="form-check"><input type="checkbox" class="form-check-input" id="' . $id . '" name="' . rmmH($name) . '" value="1" aria-describedby="' . $id . '_t"><label class="form-check-label" for="' . $id . '">' . rmmH($label) . '</label></div></div>';
}

function rivetRmmScaleSwitch(string $id, string $name, string $label, bool $checked, string $help = '', string $attrs = ''): string
{
    return '<div class="form-check form-switch mb-2"><input type="checkbox" class="form-check-input" id="' . $id . '" name="' . rmmH($name) . '" value="1"' . ($checked ? ' checked' : '') . ($help !== '' ? ' aria-describedby="' . $id . '_h"' : '') . $attrs . '>'
        . '<label class="form-check-label fw-semibold" for="' . $id . '">' . $label . '</label>' . ($help !== '' ? '<div class="form-text" id="' . $id . '_h">' . $help . '</div>' : '') . '</div>';
}

/**
 * @param array<string,mixed> $c context from the page: csrf, module_on, features, remote{...}, transfer{...}, logs{...}, scale{...}, ext{...}, msg{card=>?array}, old{card=>array}
 */
function rivetRmmUiScaleCards(array $c): string
{
    $csrf = '<input type="hidden" name="csrf_token" value="' . rmmH($c['csrf']) . '">';
    $feat = $c['features'];
    $moduleNote = $c['module_on'] ? '' : ' <span class="text-muted">The RMM module itself is off at the moment; this takes effect when it is switched on.</span>';
    $rs = $c['rs'];                  // RemoteSettings::summary()
    $lim = $rs['limits'];
    $o = fn (string $card): array => (array) ($c['old'][$card] ?? []);
    $m = fn (string $card): string => rivetRmmScaleMsgHtml($c['msg'][$card] ?? null);
    $out = '';

    // ------------------------------------------------------------------------------------------------------------------ 1. Remote tools
    $caps = $c['caps'];
    $body = $m('remote-tools') . '<p class="small text-muted">Remote tools let a technician look at and act on a device without leaving the page: list and restart its services, list and end its processes, and open a terminal or a file browser through MeshCentral. '
        . 'Nothing runs on a device until a technician starts an action.' . $moduleNote . '</p>'
        . '<form action="post.php" method="post" autocomplete="off">' . $csrf
        . rivetRmmScaleSwitch('rt_on', 'feature_remote_tools', 'Allow the remote tools (services, processes, terminal and files) <code>remote_tools</code>', !empty($feat['remote_tools']),
            '<strong>Risk:</strong> a terminal is a shell on the device, and stopping a service or ending a process can interrupt the person using the computer. Stopping, restarting and disabling a service and every process end ask for an explicit confirmation, are sent once and never retried, and are written to the audit log with who did what on which device. The agent refuses to stop itself or a short list of critical services. Off by default.')
        . rivetRmmScaleSwitch('rt_l3', 'remote_manage_lvl3', 'Level 3 script users with remote access may use the remote tools', !empty($c['lvl3']),
            'Administrators can always use them. A technician needs RMM scripts level 3 <em>and</em> the "RMM remote connect" permission, and this box ticked. Uploading a file to a device and changing these settings stay administrator-only whatever you choose here. Off by default.')
        . '<button type="submit" name="save_remote_settings" class="btn btn-primary mt-2"><i class="fas fa-save me-1" aria-hidden="true"></i>Save remote tools settings</button></form>'
        . '<h6 class="mt-4">Devices that can use them</h6><p class="small mb-0">' . ($caps['total'] === null
            ? '<span class="text-muted">Not available yet.</span>'
            : (int) $caps['services'] . ' of ' . (int) $caps['total'] . ' enrolled devices have announced the service manager, ' . (int) $caps['processes'] . ' the process manager and ' . (int) $caps['logs'] . ' log collection. Older agents simply do not announce them; update the agent to add devices.') . '</p>';
    $out .= rivetRmmScaleCard('remote-tools', 'terminal', 'Remote tools', !empty($feat['remote_tools']), $body);

    // ------------------------------------------------------------------------------------------------------------------ 2. File transfer
    $oFt = $o('file-transfer');
    $t = $c['transfer'];
    $ftOn = !empty($feat['filetransfer']);
    $allowL = array_key_exists('allow_linux', $oFt) ? (string) $oFt['allow_linux'] : implode("\n", $rs['transfer_allow']['linux']);
    $allowW = array_key_exists('allow_windows', $oFt) ? (string) $oFt['allow_windows'] : implode("\n", $rs['transfer_allow']['windows']);
    $bytes = ['KB' => 1024, 'MB' => 1048576, 'GB' => 1073741824];
    $folderState = $t['problem'] !== null ? rivetRmmUiPill('crit', 'Problem') : ($t['writable'] ? rivetRmmUiPill('ok', 'Ready') : rivetRmmUiPill('warn', 'Not created yet'));
    $folderRows = [
        ['Folder', '<code class="rmm-wrap">' . rmmH($t['path']) . '</code>' . ($t['named'] ? ' <span class="text-muted small">(set by EA_TRANSFER_DIR in config.php)</span>' : ' <span class="text-muted small">(default, inside the application)</span>')],
        ['State', $folderState . ($t['problem'] !== null ? ' <span class="small">' . rmmH($t['problem']) . '</span>' : '')],
        ['Exists / writable by the web server user', ($t['is_dir'] ? 'exists' : 'does not exist') . ' / ' . ($t['writable'] ? 'writable' : 'not writable') . ($t['mode'] !== null ? ' &middot; mode ' . rmmH($t['mode']) : '')],
        ['Free space on its disk', $t['free_bytes'] === null ? '<span class="text-muted">unknown</span>' : rmmH(rivetRmmScaleBytes($t['free_bytes'])) . ' free, against a budget of ' . rmmH(rivetRmmScaleBytes($t['budget_bytes']))
            . ($t['enough'] === false ? ' ' . rivetRmmUiPill('warn', 'less than the budget') : '')],
    ];
    $body = $m('file-transfer') . '<p class="small text-muted">File transfer copies one file from a device to the server so a technician can download it, and lets an administrator send one file to a device. It is off until you turn it on and name the folders it may touch.' . $moduleNote . '</p>'
        . '<form action="post.php" method="post" autocomplete="off">' . $csrf
        . rivetRmmScaleSwitch('ft_on', 'feature_filetransfer', 'Allow file transfer <code>filetransfer</code>', array_key_exists('feature_filetransfer', $oFt) ? !empty($oFt['feature_filetransfer']) : $ftOn, '', ' data-rmm-scale-gate="#ft_ack" data-rmm-scale-was="' . ($ftOn ? '1' : '0') . '"')
        . '<div id="ft_ack">' . rivetRmmScaleAck('ft_accept', 'accept_filetransfer_risk', (string) $rs['file_transfer_risk'], 'I understand, and my security policy permits file transfer', $ftOn) . '</div>'
        . '<h6 class="mt-3">Folders that may be used</h6><p class="small text-muted mb-2">One folder per line, per platform, at most 10 each. Each must be a full path at least two levels deep, for example <code>/srv/shared</code> or <code>C:\Data</code>. A whole drive, <code>/etc</code>, <code>/root</code> or <code>/home</code> is refused, and nothing is allowed until you list folders here.</p>'
        . '<div class="row g-3"><div class="col-lg-6"><label class="form-label" for="ft_win">Windows devices</label><textarea class="form-control font-monospace" id="ft_win" name="allow_windows" rows="4" spellcheck="false" placeholder="C:\Data\Shared">' . rmmH($allowW) . '</textarea></div>'
        . '<div class="col-lg-6"><label class="form-label" for="ft_lin">Linux devices</label><textarea class="form-control font-monospace" id="ft_lin" name="allow_linux" rows="4" spellcheck="false" placeholder="/srv/shared">' . rmmH($allowL) . '</textarea></div></div>'
        . '<h6 class="mt-4">Limits</h6><div class="row g-3">'
        . rivetRmmScaleUnitField('ft_max', 'transfer_max_bytes', 'Largest file', (int) $lim['transfer_max_bytes'], $bytes, 'From 1 KB to 512 MB. The agent has the same hard cap. Default 50 MB.', $oFt)
        . rivetRmmScaleUnitField('ft_ttl', 'transfer_ttl_s', 'Keep a transfer for', (int) $lim['transfer_ttl_s'], ['minutes' => 60, 'hours' => 3600, 'days' => 86400], 'From 10 minutes to 7 days. The stored copy is deleted after this. Default 24 hours.', $oFt)
        . rivetRmmScaleUnitField('ft_store', 'transfer_store_max_bytes', 'Space for stored transfers', (int) $lim['transfer_store_max_bytes'], $bytes, 'From 1 MB to 100 GB. A new transfer is refused when it would not fit. Default 1 GB. 1 MB means 1,048,576 bytes.', $oFt)
        . '</div><button type="submit" name="save_filetransfer_settings" class="btn btn-primary mt-3"><i class="fas fa-save me-1" aria-hidden="true"></i>Save file transfer settings</button></form>'
        . '<h6 class="mt-4">Never transferable</h6><p class="small mb-2">These are refused on the server and again on the device, even inside an allowed folder, and cannot be changed here: password databases (<code>*.kdbx</code>), private keys and certificates (<code>id_rsa*</code>, <code>id_ed25519*</code>, <code>*.pem</code>, <code>*.key</code>, <code>*.p12</code>, <code>*.pfx</code>, <code>*.jks</code>, <code>ssh_host_*</code>), the <code>.ssh</code> and <code>.gnupg</code> folders, system credential stores (<code>/etc/shadow</code>, <code>/etc/sudoers</code>, <code>ntds.dit</code>), <code>/proc</code>, <code>/sys</code>, <code>/dev</code>, page and hibernation files, and the agent\'s own files.</p>'
        . '<h6 class="mt-4">Transfer folder</h6>' . rivetRmmUiDl($folderRows)
        . '<p class="small text-muted mt-2 mb-2">The folder sits in <code>backups/</code>, an area every web server template blocks from the internet, and it is <strong>not part of the in-app backup</strong>: transferred files are temporary copies and are deleted when they expire.</p>'
        . ($t['is_dir'] && $t['writable'] ? '' : '<form action="post.php" method="post" class="d-inline">' . $csrf . '<button type="submit" name="create_transfer_folder" class="btn btn-outline-primary btn-sm"><i class="fas fa-folder-plus me-1" aria-hidden="true"></i>Create folder</button>'
            . '<span class="small text-muted ms-2">Made by the web server user with mode 0700. Safe to press twice.</span></form>');
    $out .= rivetRmmScaleCard('file-transfer', 'exchange-alt', 'File transfer', $ftOn, $body);

    // ------------------------------------------------------------------------------------------------------------------ 3. Log collection
    $oLg = $o('log-collection');
    $lgOn = !empty($feat['logs']);
    $winRows = array_key_exists('win', $oLg) ? (array) $oLg['win'] : array_map(static fn (array $s): array => ['channel' => (string) $s['channel'], 'levels' => (array) $s['levels'],
        'event_ids' => implode(', ', (array) $s['event_ids']), 'contains' => implode("\n", (array) $s['contains'])], $rs['logs_collect']['windows']);
    $linRows = array_key_exists('lin', $oLg) ? (array) $oLg['lin'] : array_map(static fn (array $s): array => ['units' => implode(', ', (array) $s['units']), 'identifiers' => implode(', ', (array) $s['identifiers']),
        'min_level' => (string) $s['min_level'], 'contains' => implode("\n", (array) $s['contains']), 'keep' => 1], $rs['logs_collect']['linux']);
    $winRows[] = ['channel' => '', 'levels' => ['critical', 'error', 'warning'], 'event_ids' => '', 'contains' => ''];
    $linRows[] = ['units' => '', 'identifiers' => '', 'min_level' => 'warning', 'contains' => '', 'keep' => 0];
    $winRow = static function (string $i, array $r): string {
        $lv = '';
        foreach (['critical', 'error', 'warning', 'info', 'verbose'] as $l) {
            $lv .= '<div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" id="lw_' . $i . '_' . $l . '" name="log_win[' . $i . '][levels][]" value="' . $l . '"' . (in_array($l, (array) ($r['levels'] ?? []), true) ? ' checked' : '')
                . '><label class="form-check-label" for="lw_' . $i . '_' . $l . '">' . ucfirst($l) . '</label></div>';
        }

        return '<div class="rmm-scale-row" data-rmm-scale-row><div class="row g-2"><div class="col-md-4"><label class="form-label small" for="lw_' . $i . '_c">Channel</label><input class="form-control form-control-sm" id="lw_' . $i . '_c" name="log_win[' . $i . '][channel]" maxlength="100" value="'
            . rmmH($r['channel'] ?? '') . '" placeholder="System"></div><div class="col-md-8"><span class="form-label small d-block" id="lw_' . $i . '_lh">Severities</span><div role="group" aria-labelledby="lw_' . $i . '_lh">' . $lv . '</div></div>'
            . '<div class="col-md-4"><label class="form-label small" for="lw_' . $i . '_e">Only these event ids (optional)</label><input class="form-control form-control-sm" id="lw_' . $i . '_e" name="log_win[' . $i . '][event_ids]" value="' . rmmH($r['event_ids'] ?? '') . '" placeholder="41, 1074"></div>'
            . '<div class="col-md-6"><label class="form-label small" for="lw_' . $i . '_p">Only entries containing (optional, one phrase per line)</label><textarea class="form-control form-control-sm" id="lw_' . $i . '_p" name="log_win[' . $i . '][contains]" rows="2" maxlength="900">' . rmmH($r['contains'] ?? '') . '</textarea></div>'
            . '<div class="col-md-2 d-flex align-items-end"><button type="button" class="btn btn-outline-secondary btn-sm" data-rmm-scale-remove aria-label="Remove this Windows source"><i class="fas fa-times me-1" aria-hidden="true"></i>Remove</button></div></div></div>';
    };
    $linRow = static function (string $i, array $r): string {
        $opts = '';
        foreach (['critical', 'error', 'warning', 'info', 'verbose'] as $l) {
            $opts .= '<option value="' . $l . '"' . (($r['min_level'] ?? 'warning') === $l ? ' selected' : '') . '>' . ucfirst($l) . ' and worse</option>';
        }

        return '<div class="rmm-scale-row" data-rmm-scale-row><input type="hidden" name="log_lin[' . $i . '][keep]" value="' . (int) ($r['keep'] ?? 0) . '" data-rmm-scale-keep><div class="row g-2">'
            . '<div class="col-md-4"><label class="form-label small" for="ll_' . $i . '_u">Services (units, optional)</label><input class="form-control form-control-sm" id="ll_' . $i . '_u" name="log_lin[' . $i . '][units]" value="' . rmmH($r['units'] ?? '') . '" placeholder="sshd.service, nginx.service"></div>'
            . '<div class="col-md-4"><label class="form-label small" for="ll_' . $i . '_i">Program names (optional)</label><input class="form-control form-control-sm" id="ll_' . $i . '_i" name="log_lin[' . $i . '][identifiers]" value="' . rmmH($r['identifiers'] ?? '') . '" placeholder="sudo, kernel"></div>'
            . '<div class="col-md-4"><label class="form-label small" for="ll_' . $i . '_m">Severity</label><select class="form-select form-select-sm" id="ll_' . $i . '_m" name="log_lin[' . $i . '][min_level]">' . $opts . '</select></div>'
            . '<div class="col-md-8"><label class="form-label small" for="ll_' . $i . '_p">Only entries containing (optional, one phrase per line)</label><textarea class="form-control form-control-sm" id="ll_' . $i . '_p" name="log_lin[' . $i . '][contains]" rows="2" maxlength="900">' . rmmH($r['contains'] ?? '') . '</textarea></div>'
            . '<div class="col-md-4 d-flex align-items-end"><button type="button" class="btn btn-outline-secondary btn-sm" data-rmm-scale-remove aria-label="Remove this Linux source"><i class="fas fa-times me-1" aria-hidden="true"></i>Remove</button></div></div></div>';
    };
    $wHtml = '';
    foreach (array_values($winRows) as $i => $r) {
        $wHtml .= $winRow((string) $i, $r);
    }
    $lHtml = '';
    foreach (array_values($linRows) as $i => $r) {
        $lHtml .= $linRow((string) $i, $r);
    }
    $perDev = (int) $rs['logs_worst_case_bytes_per_device'];
    $fleetCap = (int) $rs['logs_fleet_cap_bytes'];
    $logLink = $lgOn ? '<a class="btn btn-outline-secondary btn-sm" href="/agent/rmm_logs.php"><i class="fas fa-stream me-1" aria-hidden="true"></i>Open the Logs page</a>' : '';
    $body = $m('log-collection') . '<p class="small text-muted">Log collection reads Windows event-log channels and Linux system-log entries on the devices and stores them here, so a technician can search them and raise alerts on a pattern. It is the heaviest optional part of the RMM and is off until you turn it on.' . $moduleNote . '</p>'
        . '<form action="post.php" method="post" autocomplete="off" data-rmm-scale-logs>' . $csrf
        . rivetRmmScaleSwitch('lg_on', 'feature_logs', 'Collect logs from devices <code>logs</code>', array_key_exists('feature_logs', $oLg) ? !empty($oLg['feature_logs']) : $lgOn, '', ' data-rmm-scale-gate="#lg_ack" data-rmm-scale-was="' . ($lgOn ? '1' : '0') . '"')
        . '<div id="lg_ack">' . rivetRmmScaleAck('lg_accept', 'accept_logs_storage', (string) $rs['logs_storage_warning'], 'I understand how much this stores, and I will watch the figures below', $lgOn) . '</div>'
        . '<h6 class="mt-3">What to collect</h6><p class="small text-muted mb-2">Choose narrow sources: warnings and errors from a few channels or services. Every device gets the list for its own platform. An empty list collects nothing, even with the switch on.</p>'
        . '<fieldset class="mb-3"><legend class="fs-6">Windows event-log channels (up to 20)</legend><div data-rmm-scale-rows="win">' . $wHtml . '</div>'
        . '<button type="button" class="btn btn-outline-primary btn-sm mt-1" data-rmm-scale-add="win"><i class="fas fa-plus me-1" aria-hidden="true"></i>Add a Windows channel</button></fieldset>'
        . '<fieldset class="mb-3"><legend class="fs-6">Linux system log (journald, up to 20)</legend><div data-rmm-scale-rows="lin">' . $lHtml . '</div>'
        . '<button type="button" class="btn btn-outline-primary btn-sm mt-1" data-rmm-scale-add="lin"><i class="fas fa-plus me-1" aria-hidden="true"></i>Add a Linux source</button><div class="form-text">A row with no service and no program name collects every service at the chosen severity.</div></fieldset>'
        . '<template data-rmm-scale-tpl="win">' . $winRow('__i__', ['channel' => '', 'levels' => ['critical', 'error', 'warning'], 'event_ids' => '', 'contains' => '']) . '</template>'
        . '<template data-rmm-scale-tpl="lin">' . $linRow('__i__', ['units' => '', 'identifiers' => '', 'min_level' => 'warning', 'contains' => '', 'keep' => 1]) . '</template>'
        . '<h6 class="mt-4">Limits</h6><div class="row g-3">'
        . rivetRmmScaleNumber('lg_ret', 'logs_retention_days', 'Keep entries for (days)', $lim['logs_retention_days'], '1 to 90. Older entries are deleted in small batches. Default 7.', $oLg, 1, 90)
        . rivetRmmScaleNumber('lg_dev', 'logs_device_max_rows', 'Most entries per device', $lim['logs_device_max_rows'], '100 to 1,000,000. The oldest are trimmed; a device that overshoots is refused and counted. Default 20,000.', $oLg, 100, 1000000)
        . rivetRmmScaleNumber('lg_fleet', 'logs_fleet_max_rows', 'Most entries for the whole fleet', $lim['logs_fleet_max_rows'], '1,000 to 200,000,000. Default 2,000,000.', $oLg, 1000, 200000000)
        . rivetRmmScaleNumber('lg_rate', 'logs_rate_per_min', 'Entries one device may send per minute', $lim['logs_rate_per_min'], '10 to 6,000. The agent applies the same budget. Default 600.', $oLg, 10, 6000)
        . rivetRmmScaleNumber('lg_int', 'logs_interval_s', 'How often a device sends (seconds)', $lim['logs_interval_s'], '30 to 3,600. Each batch is at most 200 entries. Default 60.', $oLg, 30, 3600)
        . '</div><button type="submit" name="save_logs_settings" class="btn btn-primary mt-3"><i class="fas fa-save me-1" aria-hidden="true"></i>Save log collection settings</button></form>'
        . '<h6 class="mt-4">What the limits add up to</h6>' . rivetRmmUiDl([
            ['Worst case for one device', rmmH(rivetRmmScaleBytes($perDev)) . ' <span class="text-muted small">(' . rmmH(number_format((int) $lim['logs_device_max_rows'])) . ' entries at about 600 bytes each)</span>'],
            ['Cap for the whole fleet', rmmH(rivetRmmScaleBytes($fleetCap)) . ' <span class="text-muted small">(' . rmmH(number_format((int) $lim['logs_fleet_max_rows'])) . ' entries)</span>'],
        ]) . '<p class="small text-muted mt-2 mb-2">These are the figures to compare with the free space on the database disk before you turn collection on for a large fleet. Log text can contain user names, addresses and other personal data.</p>' . $logLink;
    $out .= rivetRmmScaleCard('log-collection', 'stream', 'Log collection', $lgOn, $body);

    // ------------------------------------------------------------------------------------------------------------------ 4. Scale settings
    $oSc = $o('scale');
    $s = $c['scale'];
    $L = $s['limits'];
    $warmOn = array_key_exists('warm_on', $oSc) ? !empty($oSc['warm_on']) : (int) $L['warm_start_s'] > 0;
    $bucketOn = array_key_exists('bucket_on', $oSc) ? !empty($oSc['bucket_on']) : ((int) $L['client_bucket_burst'] > 0 && (int) $L['client_bucket_per_min'] > 0);
    $sg = $s['bucket_suggestion'];
    $body = $m('scale') . '<p class="small text-muted">These settings only matter for larger installs (hundreds to many thousands of devices). The defaults are right for a small team; change a value only when the Scale and capacity panel on the Agent fleet page tells you to. Every number is checked and a refusal is shown here with its allowed range.</p>'
        . '<form action="post.php" method="post" autocomplete="off">' . $csrf . '<input type="hidden" name="herd_form" value="1">'
        . '<h6>Read cache</h6><div class="row g-3">'
        . rivetRmmScaleNumber('sc_cf', 'cache_fresh_s', 'Keep fleet figures fresh for (seconds)', $L['cache_fresh_s'], '0 to 3,600. Counts and lists on the fleet pages are reused for this long instead of being recounted on every page view. 0 turns the cache off. Default 15.', $oSc, 0, 3600, 'col-md-6')
        . rivetRmmScaleNumber('sc_cs', 'cache_stale_s', 'Then serve the old figure for up to (seconds)', $L['cache_stale_s'], '0 to 86,400. While one page view refreshes it, others still get the old figure. Default 300.', $oSc, 0, 86400, 'col-md-6')
        . '</div><p class="small text-muted mt-2 mb-0">The cache is kept in ' . ($s['cache_shared'] ? 'Redis' : 'the database') . '.</p>'
        . '<h6 class="mt-4">Ingest workers</h6><div class="row g-3">'
        . rivetRmmScaleNumber('sc_im', 'ingest_max_running', 'Most ingest jobs running at once (0 = no limit)', $L['ingest_max_running'], '0 to 10,000, across all workers. Only used when the ingest mode is "queued" (now: <strong>' . rmmH($s['ingest_mode']) . '</strong>). Default 0.', $oSc, 0, 10000, 'col-md-6')
        . rivetRmmScaleNumber('sc_ib', 'ingest_batch', 'Ingest jobs one worker takes per pass', $L['ingest_batch'], '1 to 200. Default 50.', $oSc, 1, 200, 'col-md-6')
        . '</div>'
        . '<h6 class="mt-4">Spreading the load after an outage</h6><p class="small text-muted mb-2">When the server comes back, every device tries to check in at once. These two controls are off by default. The first admits only a growing share of check-ins for a while after the RMM module is switched back on or after a long quiet gap; the second limits how fast one department\'s devices can check in, so one noisy department cannot starve the rest. The gate in front of the API applies the warm start from a small file in the state folder (<code>rmm_herd.json</code>), so a turned-away check-in costs no database query.</p>'
        . rivetRmmScaleSwitch('sc_wo', 'warm_on', 'Warm start after an outage', $warmOn, 'On-value: 10 minutes, starting at 10 % of check-ins. Devices that are turned away simply try again a little later.', ' data-rmm-scale-toggle="#sc_warm"')
        . '<div class="row g-3 mb-3" id="sc_warm">'
        . rivetRmmScaleNumber('sc_ws', 'warm_start_s', 'Ramp length (seconds)', (int) $L['warm_start_s'] > 0 ? $L['warm_start_s'] : 600, '1 to 7,200. Default when switched on: 600.', $oSc, 0, 7200, 'col-md-4')
        . rivetRmmScaleNumber('sc_wp', 'warm_start_pct', 'Start with this share of check-ins (%)', $L['warm_start_pct'], '1 to 100. Default 10.', $oSc, 1, 100, 'col-md-4')
        . rivetRmmScaleNumber('sc_wq', 'warm_quiet_s', 'A quiet gap of this long counts as an outage (seconds)', $L['warm_quiet_s'], '30 to 86,400. Default 120.', $oSc, 30, 86400, 'col-md-4')
        . '</div>'
        . rivetRmmScaleSwitch('sc_bo', 'bucket_on', 'Limit check-ins per department', $bucketOn, 'Each department gets a bucket of tokens that refills steadily; a check-in takes one, and a device that finds the bucket empty is told to try again shortly. Your largest department has ' . (int) $sg['largest'] . ' active device' . ((int) $sg['largest'] === 1 ? '' : 's') . ', so the suggested on-values are ' . (int) $sg['per_min'] . ' per minute with a burst of ' . (int) $sg['burst'] . ' (three times the steady rate, and room for a restart).', ' data-rmm-scale-toggle="#sc_bucket"')
        . '<div class="row g-3 mb-3" id="sc_bucket">'
        . rivetRmmScaleNumber('sc_bb', 'client_bucket_burst', 'Burst (tokens)', (int) $L['client_bucket_burst'] > 0 ? $L['client_bucket_burst'] : $sg['burst'], '1 to 1,000,000: how many check-ins a department can make at once.', $oSc, 0, 1000000, 'col-md-6')
        . rivetRmmScaleNumber('sc_bm', 'client_bucket_per_min', 'Refill (check-ins per minute)', (int) $L['client_bucket_per_min'] > 0 ? $L['client_bucket_per_min'] : $sg['per_min'], '1 to 1,000,000. Both numbers must be above 0 for the limit to apply.', $oSc, 0, 1000000, 'col-md-6')
        . '</div>'
        . '<h6 class="mt-4">Check "last seen" refresh</h6><div class="row g-3">'
        . rivetRmmScaleNumber('sc_ct', 'check_touch_s', 'Do not refresh an unchanged check more often than (seconds)', $L['check_touch_s'], '0 to 3,600. A check result that changes nothing normally still updates its "last reported" time on every check-in; a value above 0 skips that write inside this window and saves one database write per check per check-in on a big fleet. 0 keeps the exact old behaviour. Default 0.', $oSc, 0, 3600, 'col-12')
        . '</div><button type="submit" name="save_scale_settings" class="btn btn-primary mt-3"><i class="fas fa-save me-1" aria-hidden="true"></i>Save scale settings</button></form>'
        . '<p class="small mt-3 mb-0"><a href="/agent/rmm_fleet.php#rmm-capacity"><i class="fas fa-server me-1" aria-hidden="true"></i>Open the Scale and capacity panel</a> (Agent fleet page) to see queue depth, how many queue workers to run, cache, load shedding and storage.</p>';
    $out .= rivetRmmScaleCard('scale', 'tachometer-alt', 'Scale settings', true, $body, 'Defaults are fine for small installs');

    // ------------------------------------------------------------------------------------------------------------------ 5. Storage and partitioning
    $oPt = $o('partitioning');
    $p = $c['partition'];
    $words = $p['words'];
    $hist = $p['history_partitions'];
    $own = $p['own_rows'];
    $probeAt = $p['probe_at'];
    $partRows = '';
    foreach ($hist as $pt) {
        $partRows .= '<tr><td class="ps-3"><code>' . rmmH($pt['name']) . '</code></td><td>' . rmmH(rivetRmmScalePartitionBound($pt['bound'])) . '</td><td class="text-end pe-3">' . rmmH(number_format((int) $pt['rows'])) . '</td></tr>';
    }
    $partitioned = $hist !== [];
    $body = $m('partitioning') . '<h6>Metric history rollups</h6>'
        . '<p class="small mb-2"><span class="badge bg-secondary me-1">Not used</span> RivetIT keeps its own hourly metric rollups (<code>device_metric_samples</code>, about ' . rmmH(number_format((int) $own['device_metric_samples'])) . ' rows, and <code>device_metric_rollups</code>, about ' . rmmH(number_format((int) $own['device_metric_rollups'])) . ' rows), '
        . 'so RivetCore\'s own daily metric rollup does not run here and there is nothing to show for it. How long samples are kept is the "retention" setting under Service and defaults.</p>'
        . '<h6 class="mt-4" id="partitioning-h">Time partitioning of the check history</h6>'
        . '<p class="small text-muted">The check history (the trend dots on the device page) is pruned by deleting old rows in small batches. On a very large install it is faster to split the table by day and remove an expired day by dropping its partition. It is optional, off by default, and only worth it for tens of thousands of devices. In RivetIT the table that matters is <code>endpoint_agent_check_history</code>.</p>'
        . '<div class="mb-3">' . ($words === null
            ? '<p class="small mb-2"><i class="fas fa-question-circle me-1" aria-hidden="true"></i>This server has not been checked yet.</p>'
            : '<p class="mb-1">' . ($words['can'] || !empty($words['history']['partitioned']) ? rivetRmmUiPill('ok', 'Possible') : rivetRmmUiPill('warn', 'Not possible')) . ' <strong>Can this server do it?</strong></p><p class="small mb-1">' . rmmH($words['summary']) . '</p>'
              . '<p class="small text-muted mb-2">Database: ' . rmmH($words['server']) . ($probeAt !== null ? ' &middot; checked ' . rmmH($probeAt) . ' UTC' : '') . '</p>')
        . '<form action="post.php" method="post" class="d-inline">' . $csrf . '<button type="submit" name="partition_probe" class="btn btn-outline-secondary btn-sm"><i class="fas fa-vial me-1" aria-hidden="true"></i>' . ($words === null ? 'Check this server' : 'Check again') . '</button>'
        . '<span class="small text-muted ms-2">Creates and removes a tiny scratch table to prove the database account may partition. Nothing else is touched.</span></form></div>';
    if ($words !== null && !empty($words['history']['warnings'])) {
        foreach ($words['history']['warnings'] as $w) {
            $body .= '<p class="small text-warning-emphasis mb-2"><i class="fas fa-exclamation-triangle me-1" aria-hidden="true"></i>' . rmmH($w) . '</p>';
        }
    }
    if ($words !== null && $words['can'] && !$partitioned) {
        $body .= '<form action="post.php" method="post" autocomplete="off" class="border rounded p-3 mb-3">' . $csrf
            . '<p class="small mb-2"><strong>Before you enable it:</strong> the table is copied once into its partitioned form. While it is copied, check-ins that write to it wait, and on a table with millions of rows that can take minutes. Do it off hours. After that, a day is removed by dropping a partition and a new day is added automatically by the nightly housekeeping. '
            . 'Two further RivetCore metric tables that RivetIT does not use (<code>rmm_metric_hourly</code>, <code>rmm_metric_daily</code>) are empty and are converted with it at the next housekeeping run; that is instant.</p>'
            . '<div class="form-check mb-2"><input type="checkbox" class="form-check-input" id="pt_ok" name="confirm_partition" value="1" data-rmm-scale-needs><label class="form-check-label" for="pt_ok">I understand the table is copied once and writers wait while it is, and I am doing this off hours</label></div>'
            . '<button type="submit" name="partition_enable" class="btn btn-primary btn-sm" id="pt_go"><i class="fas fa-database me-1" aria-hidden="true"></i>Enable partitioning</button></form>';
    }
    $body .= '<form action="post.php" method="post" autocomplete="off" class="mb-3">' . $csrf . '<div class="row g-3">'
        . rivetRmmScaleNumber('pt_span', 'partition_span_days', 'Days per partition', $c['scale']['limits']['partition_span_days'], '1 to 7. Set this before enabling; changing it later only affects partitions created afterwards. Default 1.', $oPt, 1, 7, 'col-sm-6 col-lg-4', $partitioned ? ' readonly' : '')
        . rivetRmmScaleNumber('pt_ahead', 'partition_ahead', 'Partitions created in advance', $c['scale']['limits']['partition_ahead'], '1 to 60. Housekeeping keeps this many empty partitions ready. Default 7.', $oPt, 1, 60, 'col-sm-6 col-lg-4')
        . '<div class="col-lg-4 d-flex align-items-end"><button type="submit" name="save_scale_settings" class="btn btn-outline-primary btn-sm"><i class="fas fa-save me-1" aria-hidden="true"></i>Save these two</button></div></div></form>';
    if ($partitioned) {
        $body .= '<h6>Current partitions of <code>endpoint_agent_check_history</code></h6><div class="table-responsive rmm-scale-scroll mb-2"><table class="table table-sm mb-0"><caption class="visually-hidden">Partitions of the check history table</caption>'
            . rivetRmmUiThead(['Partition', 'Holds rows', 'Rows (estimate)']) . '<tbody>' . $partRows . '</tbody></table></div>'
            . '<form action="post.php" method="post" class="mt-3">' . $csrf . '<button type="submit" name="partition_disable" class="btn btn-outline-secondary btn-sm"><i class="fas fa-power-off me-1" aria-hidden="true"></i>Turn off automatic partitioning</button></form>'
            . '<p class="small text-muted mt-2 mb-0">Turning it off stops housekeeping from converting more tables; a table that is already partitioned stays partitioned and keeps being maintained (the oldest partition is dropped when it expires). To convert it back to a plain table, run <code>ALTER TABLE endpoint_agent_check_history REMOVE PARTITIONING</code> on the database during a quiet hour; the table keeps its extended primary key.</p>';
    } else {
        $body .= '<p class="small text-muted mb-0">No table is partitioned. Expired check history is removed by deleting old rows in batches, which is fine for most installs.</p>';
    }
    $out .= rivetRmmScaleCard('partitioning', 'database', 'Metric storage and partitioning', $partitioned, $body, $partitioned ? 'Partitioned' : 'Off');

    // ------------------------------------------------------------------------------------------------------------------ 6. External metric store
    $oEx = $o('ext-store');
    $e = $c['ext'];
    $fl = $e['flags'];
    $saved = static fn (bool $has, string $what): string => $has ? '<span class="badge bg-success ms-1">saved, hidden</span>' : '<span class="badge bg-secondary ms-1">not set</span>';
    $buf = $e['buffer'];
    $bufHtml = '';
    if ($fl['enabled']) {
        $bufHtml = '<h6 class="mt-4">Buffer</h6>' . rivetRmmUiDl([
            ['Waiting to be sent', rmmH(number_format((int) $buf['batches'])) . ' batch' . ((int) $buf['batches'] === 1 ? '' : 'es') . ' (' . rmmH(number_format((int) $buf['points'])) . ' points)'],
            ['Oldest waiting', $buf['oldest'] === null ? '<span class="text-muted">nothing waiting</span>' : rmmH($buf['oldest']) . ' UTC'],
            ['Most attempts on one batch', (int) $buf['max_attempts'] . ($buf['last_error'] !== null ? ' &middot; last error: ' . rmmH($buf['last_error']) : '')],
        ]) . '<p class="small text-muted mb-0">Check-ins never wait for the store: points are queued here and sent by the minute housekeeping. If the store is down, batches wait up to 24 hours or 20,000 batches, then the oldest are dropped; your own hourly rollups still have the data. The count of points dropped is shown per request in the Scale and capacity panel.</p>';
    }
    $body = $m('ext-store') . '<div class="alert alert-secondary py-2 small"><i class="fas fa-info-circle me-1" aria-hidden="true"></i><strong>Most installs do not need this.</strong> It copies metric history to a separate time-series database (VictoriaMetrics) and is meant for installs well beyond 10,000 devices. It is another service to run, back up and secure, and another credential. Below that size, leave it off: RivetIT\'s own hourly rollups are enough.</div>'
        . '<form action="post.php" method="post" autocomplete="off" data-rmm-scale-ext>' . $csrf
        . rivetRmmScaleSwitch('ex_on', 'extstore_enabled', 'Copy metric history to an external store', $fl['enabled'], 'Off by default. Needs a write address. Reads of history use the store first and fall back to RivetIT\'s own tables.')
        . '<div class="row g-3 mt-1"><div class="col-lg-6"><label class="form-label" for="ex_w">Write address ' . $saved($fl['write'], 'write') . '</label><input type="text" class="form-control font-monospace" id="ex_w" name="extstore_write_url" maxlength="500" autocomplete="off" spellcheck="false" placeholder="'
        . ($fl['write'] ? 'A write address is saved. Type a new one to replace it.' : 'https://vm.example.com/insert/0/influx') . '" aria-describedby="ex_w_h"><div class="form-text" id="ex_w_h">https only, on a public address (or one you listed under allowed webhook networks). No user name or password in the address.</div>'
        . ($fl['write'] ? '<div class="form-check mt-1"><input type="checkbox" class="form-check-input" id="ex_cw" name="extstore_clear_write_url" value="1"><label class="form-check-label small" for="ex_cw">Remove the saved write address</label></div>' : '') . '</div>'
        . '<div class="col-lg-6"><label class="form-label" for="ex_r">Read address ' . $saved($fl['read'], 'read') . '</label><input type="text" class="form-control font-monospace" id="ex_r" name="extstore_read_url" maxlength="500" autocomplete="off" spellcheck="false" placeholder="'
        . ($fl['read'] ? 'A read address is saved. Type a new one to replace it.' : 'Optional: the write address is used when empty') . '" aria-describedby="ex_r_h"><div class="form-text" id="ex_r_h">For example <code>https://vm.example.com/select/0/prometheus</code>.</div>'
        . ($fl['read'] ? '<div class="form-check mt-1"><input type="checkbox" class="form-check-input" id="ex_cr" name="extstore_clear_read_url" value="1"><label class="form-check-label small" for="ex_cr">Remove the saved read address</label></div>' : '') . '</div>'
        . '<div class="col-lg-6"><label class="form-label" for="ex_t">Bearer token ' . $saved($fl['token'], 'token') . '</label><input type="password" class="form-control font-monospace" id="ex_t" name="extstore_token" maxlength="500" autocomplete="new-password" placeholder="'
        . ($fl['token'] ? 'A token is saved. Type a new one to replace it.' : 'Optional') . '" aria-describedby="ex_t_h"><div class="form-text" id="ex_t_h">Sent as <code>Authorization: Bearer ...</code>. Stored encrypted and never shown again. Leave empty to keep the saved one.</div>'
        . ($fl['token'] ? '<div class="form-check mt-1"><input type="checkbox" class="form-check-input" id="ex_ct" name="extstore_clear_token" value="1"><label class="form-check-label small" for="ex_ct">Remove the saved token</label></div>' : '') . '</div></div>'
        . '<div class="d-flex flex-wrap gap-2 mt-3 align-items-center"><button type="submit" name="save_extstore" class="btn btn-primary"><i class="fas fa-save me-1" aria-hidden="true"></i>Save external store settings</button>'
        . '<button type="submit" name="test_extstore" value="1" class="btn btn-outline-secondary" data-rmm-scale-test><i class="fas fa-plug me-1" aria-hidden="true"></i>Test connection</button>'
        . '<span class="small text-muted">Tests what you typed, or the saved values for any field left empty. Nothing is written to the store.</span></div>'
        . '<div class="mt-3" id="ex_live" role="status" aria-live="polite" data-rmm-scale-live></div></form>' . $bufHtml;
    $out .= rivetRmmScaleCard('ext-store', 'cloud-upload-alt', 'External metric store', $fl['enabled'], $body);

    return $out;
}
