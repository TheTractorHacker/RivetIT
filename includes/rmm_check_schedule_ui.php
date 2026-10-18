<?php

/*
 * Administration > Endpoint agent: the check options (RivetIT 26.10.53). Renders the plain-language editor that replaced the JSON textarea; the
 * translation to and from the stored check list is includes/rmm_check_schedule.php, the behaviour is js/rmm_check_schedule.js. Everything the
 * page prints is escaped; the script text and service names are user data.
 */

require_once __DIR__ . '/rmm_check_schedule.php';

function rivetCsH(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function rivetCsScriptRow(string $i, string $name, string $body, int $timeout): string
{
    $o = '<div class="border rounded p-2 mb-2 cs-script-row">'
        . '<div class="row g-2 align-items-end"><div class="col-sm-6"><label class="form-label small mb-1" for="cs_sn_' . $i . '">Name</label>'
        . '<input type="text" class="form-control form-control-sm" id="cs_sn_' . $i . '" name="cs[scripts][' . $i . '][name]" maxlength="100" value="' . rivetCsH($name) . '" placeholder="for example check_backup_share" autocomplete="off"></div>'
        . '<div class="col-sm-4"><label class="form-label small mb-1" for="cs_st_' . $i . '">Give up after</label><select class="form-select form-select-sm" id="cs_st_' . $i . '" name="cs[scripts][' . $i . '][timeout_s]">';
    $opts = RIVET_CS_SCRIPT_TIMEOUTS;
    if (!isset($opts[$timeout])) {
        $opts[$timeout] = $timeout . ' seconds (current)';
        ksort($opts);
    }
    foreach ($opts as $v => $l) {
        $o .= '<option value="' . $v . '"' . ($v === $timeout ? ' selected' : '') . '>' . rivetCsH($l) . '</option>';
    }
    $o .= '</select></div><div class="col-sm-2 text-sm-end"><button type="button" class="btn btn-sm btn-outline-danger" data-cs-remove-script>Remove</button></div></div>'
        . '<label class="form-label small mb-1 mt-2" for="cs_sb_' . $i . '">Commands to run (PowerShell on Windows, shell on Linux)</label>'
        . '<textarea class="form-control form-control-sm font-monospace" id="cs_sb_' . $i . '" name="cs[scripts][' . $i . '][script]" rows="4" maxlength="8192" spellcheck="false" placeholder="Exit code 0 means fine, 1 means warning, anything else means critical. The first line it prints is shown.">' . rivetCsH($body) . '</textarea>'
        . '</div>';

    return $o;
}

/**
 * @param array<string,mixed> $st rivetCsStateFromChecks()
 */
function rivetCsRender(array $st): string
{
    $choices = rivetCsServiceChoices();
    $presets = rivetCsPresets();
    $data = ['presets' => array_map(static fn (array $p): array => $p['values'], $presets), 'defaults' => rivetCsDefaultsValues()];
    $o = '<div id="cs_editor" data-cs="' . rivetCsH(json_encode($data)) . '">';
    $o .= '<input type="hidden" name="cs_defaults" id="cs_defaults" value="">';
    $o .= '<h5 class="mb-1">Checks the agents run</h5><p class="small text-muted mb-2">Switch on what you want watched. Each device runs these checks and reports problems as alerts. Every check is signed with this instance\'s key, so a device only runs checks that come from you.</p>';
    $o .= '<div class="d-flex flex-wrap gap-2 align-items-center mb-3" role="group" aria-label="Start from a ready-made set"><span class="small text-muted me-1">Start from:</span>';
    foreach ($presets as $k => $p) {
        $o .= '<button type="button" class="btn btn-sm btn-outline-primary" data-cs-preset="' . rivetCsH($k) . '" title="' . rivetCsH($p['about']) . '">' . rivetCsH($p['label']) . '</button>';
    }
    $o .= '<button type="button" class="btn btn-sm btn-outline-secondary" data-cs-defaults title="Go back to the built-in checks: low disk space, a waiting restart and the Windows event log.">Restore defaults</button></div>';
    $o .= '<div class="small text-muted mb-3" id="cs_preset_about" aria-live="polite"></div>';
    $o .= '<div class="row g-3">';

    // Low disk space
    $warnOpts = RIVET_CS_DISK_CHOICES;
    $w = (int) $st['disk']['warn'];
    if (!in_array($w, $warnOpts, true)) {
        $warnOpts[] = $w;
        sort($warnOpts);
    }
    $o .= '<div class="col-lg-6"><div class="border rounded p-3 h-100"><div class="form-check form-switch mb-2">'
        . '<input type="checkbox" class="form-check-input" id="cs_disk_on" name="cs[disk_on]" value="1"' . ($st['disk']['on'] ? ' checked' : '') . ' aria-describedby="cs_disk_h">'
        . '<label class="form-check-label fw-semibold" for="cs_disk_on">Low disk space</label></div>'
        . '<p class="small text-muted mb-2" id="cs_disk_h">Warns when a disk is running out of room' . ($st['disk']['mount'] !== '' ? ' (watching ' . rivetCsH($st['disk']['mount']) . ' only)' : ' (every disk on the device)') . ', and turns critical at half that much free space.</p>'
        . '<label class="form-label small mb-1" for="cs_disk_warn">Warn me when less than this is free</label><select class="form-select form-select-sm w-auto mw-100" id="cs_disk_warn" name="cs[disk_warn]">';
    foreach ($warnOpts as $v) {
        $o .= '<option value="' . $v . '"' . ($v === $w ? ' selected' : '') . '>' . $v . '% free' . (in_array($v, RIVET_CS_DISK_CHOICES, true) ? '' : ' (current)') . '</option>';
    }
    $o .= '</select></div></div>';

    // Waiting for a restart
    $o .= '<div class="col-lg-6"><div class="border rounded p-3 h-100"><div class="form-check form-switch mb-2">'
        . '<input type="checkbox" class="form-check-input" id="cs_reboot_on" name="cs[reboot_on]" value="1"' . ($st['reboot']['on'] ? ' checked' : '') . ' aria-describedby="cs_reboot_h">'
        . '<label class="form-check-label fw-semibold" for="cs_reboot_on">Waiting for a restart</label></div>'
        . '<p class="small text-muted mb-0" id="cs_reboot_h">Warns when a device has finished installing something and is waiting to be restarted. It stays a warning until the device restarts.</p></div></div>';

    // Services
    $o .= '<div class="col-12"><div class="border rounded p-3"><div class="fw-semibold mb-1">Important services stopped</div>'
        . '<p class="small text-muted mb-2" id="cs_svc_h">Raises an alert when a ticked service is not running. Tick only services that exist on the devices you manage: a device that does not have the service reports a failure for it.</p><div class="row">';
    foreach (['Windows', 'Linux'] as $os) {
        $o .= '<div class="col-md-6"><div class="small text-muted mb-1">' . $os . ' devices</div>';
        foreach ($choices as $name => [$label, $cos]) {
            if ($cos !== $os) {
                continue;
            }
            $id = 'cs_svc_' . preg_replace('/[^A-Za-z0-9]/', '_', $name);
            $o .= '<div class="form-check"><input type="checkbox" class="form-check-input" id="' . $id . '" name="cs[svc][]" value="' . rivetCsH($name) . '"' . (in_array($name, $st['services'], true) ? ' checked' : '') . '>'
                . '<label class="form-check-label" for="' . $id . '">' . rivetCsH($label) . ' <span class="text-muted small">(' . rivetCsH($name) . ')</span></label></div>';
        }
        $o .= '</div>';
    }
    $others = array_values(array_filter($st['services'], static fn (string $n): bool => !isset($choices[$n])));
    $o .= '</div><label class="form-label small mb-1 mt-2" for="cs_svc_extra">Add another service</label>'
        . '<textarea class="form-control form-control-sm" id="cs_svc_extra" name="cs[svc_extra]" rows="2" maxlength="4000" spellcheck="false" placeholder="One service name per line, for example Spooler or nginx">' . rivetCsH(implode("\n", $others)) . '</textarea>'
        . '<div class="form-text">Use the short service name (in Windows, the "Service name" shown in Services; in Linux, the name <code>systemctl</code> uses).</div></div></div>';

    // How often
    $o .= '<div class="col-lg-6"><div class="border rounded p-3 h-100"><label class="fw-semibold mb-1" for="cs_interval">How often to check</label>'
        . '<p class="small text-muted mb-2" id="cs_iv_h">Shorter means problems show up sooner, longer means less load on the devices and the server.</p><select class="form-select form-select-sm w-auto mw-100" id="cs_interval" name="cs[interval]" aria-describedby="cs_iv_h">';
    foreach (RIVET_CS_INTERVALS as $s => $l) {
        $o .= '<option value="' . $s . '"' . ((string) $s === $st['interval'] ? ' selected' : '') . '>' . $l . '</option>';
    }
    if ($st['interval'] === 'keep') {
        $o .= '<option value="keep" selected>Keep the current timing (mixed)</option>';
    }
    $o .= '<option value="defaults" hidden>Built-in timing</option></select></div></div>';

    // Checks the options cannot show
    if ($st['kept'] !== []) {
        $o .= '<div class="col-lg-6"><div class="border rounded p-3 h-100"><div class="fw-semibold mb-1">Other checks set up earlier</div>'
            . '<p class="small text-muted mb-2">These come from an earlier setup and have options this page does not offer. They keep running exactly as they are. Tick one to remove it.</p>';
        $i = 0;
        foreach ($st['kept'] as $k => $c) {
            $i++;
            $o .= '<div class="form-check"><input type="checkbox" class="form-check-input" id="cs_rm_' . $i . '" name="cs[remove][]" value="' . rivetCsH($k) . '">'
                . '<label class="form-check-label small" for="cs_rm_' . $i . '">Remove: ' . rivetCsH(rivetCsDescribe($c)) . '</label></div>';
        }
        $o .= '</div></div>';
    }

    // Advanced: scripts
    $o .= '<div class="col-12"><details class="border rounded p-3"' . ($st['scripts_on'] ? ' open' : '') . ' id="cs_adv"><summary class="fw-semibold">Advanced: run your own script</summary>'
        . '<div class="alert alert-warning small mt-2 mb-2"><strong>Careful.</strong> A script check runs on every device, with full rights (SYSTEM on Windows, root on Linux), every time the checks run. Only use commands you wrote or fully trust, and keep them quick and harmless. Each script is signed by this server, at most 8 KiB, and is stopped after the time you choose (at most 1 minute). Switching this off removes your scripts from the schedule.</div>'
        . '<div class="form-check form-switch mb-2"><input type="checkbox" class="form-check-input" id="cs_scripts_on" name="cs[scripts_on]" value="1"' . ($st['scripts_on'] ? ' checked' : '') . '>'
        . '<label class="form-check-label" for="cs_scripts_on">Run my own scripts</label></div><div id="cs_scripts_box">';
    $n = 0;
    foreach ($st['scripts'] as $s) {
        $o .= rivetCsScriptRow((string) $n++, $s['name'], $s['script'], $s['timeout_s']);
    }
    if ($st['scripts'] === []) {
        $o .= rivetCsScriptRow((string) $n++, '', '', 30);
    }
    $o .= '</div><button type="button" class="btn btn-sm btn-outline-secondary" data-cs-add-script data-next="' . $n . '">Add another script</button>'
        . '<template id="cs_script_tpl">' . rivetCsScriptRow('__I__', '', '', 30) . '</template></details></div>';

    $o .= '</div>';   // row
    $o .= '<div class="mt-3"><div class="fw-semibold" id="cs_summary" aria-live="polite"></div><div class="text-danger small" id="cs_errors" role="alert" aria-live="assertive"></div><div class="text-warning-emphasis small" id="cs_warnings" aria-live="polite"></div></div>';
    $o .= '<details class="mt-2"><summary class="small">What will be delivered (read-only)</summary><ul class="small mt-2" id="cs_lines"></ul>'
        . '<div class="small text-muted">Technical view of the signed definitions (you cannot edit this):</div><pre class="small border rounded p-2 mb-0 bg-body-tertiary" id="cs_tech" style="max-height:18rem;overflow:auto" tabindex="0"></pre></details>';
    $o .= '</div>';

    return $o;
}
