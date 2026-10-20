<?php

/*
 * Endpoint agent check schedule, plain-language model (RivetIT 26.10.53).
 *
 * Administration > Endpoint agent used to show the whole check list as a JSON textarea. The page now offers a few ready-made checks with
 * friendly options (low disk space, waiting for a restart, important services stopped, how often to check, and your own scripts behind an
 * Advanced section). This file is the translation between those options and the check list the module stores and signs. It is pure: no
 * database, no session, no output, so tests call it directly.
 *
 * Authority: nothing the browser sends is trusted as a check definition. rivetCsBuild() constructs every check from the closed set of
 * options, from values it validated itself, and from the STORED list (for checks the options cannot express: they are kept exactly as
 * stored, or removed, never edited). The result is then validated again by RivetCore (ChecksValidator, called here per check so a message
 * can name the check, and again by RmmSettings::update() when saved). The signed output is unchanged: the same check definitions, signed per
 * device by RmmSettings::signedChecks().
 *
 * What the agent really reads (endpoint-agent/internal/collect/checks.go): service {name, expected, startup}; disk {mount, warn_free_pct,
 * fail_free_pct, warn_free_gb, fail_free_gb}; pending_reboot {}; script {script, timeout_s, max_output_bytes}. RivetCore's built-in list
 * names `expect`, `warn_pct` and `fail_pct`, which the agent ignores (it then applies its own 20 % / 10 % free defaults): such a list is read
 * as "service running" and "warn below 20 % free", which is what it does on the device today.
 */

const RIVET_CS_INTERVALS = [300 => 'Every 5 minutes', 900 => 'Every 15 minutes', 1800 => 'Every 30 minutes', 3600 => 'Every hour'];
const RIVET_CS_DISK_CHOICES = [5, 10, 15, 20];
const RIVET_CS_SCRIPT_TIMEOUTS = [10 => '10 seconds', 30 => '30 seconds', 60 => '1 minute'];
const RIVET_CS_MAX_SCRIPTS = 10;
const RIVET_CS_MAX_SERVICES = 30;
const RIVET_CS_SCRIPT_MAX_BYTES = 8192;
const RIVET_CS_SERVICE_RE = '/^[A-Za-z0-9_.@:+ -]{1,128}$/';
const RIVET_CS_KEY_RE = '/^[A-Za-z0-9_.:-]{1,100}$/';
const RIVET_CS_MOUNT_RE = '/^[A-Za-z0-9_.:\/\\\\ -]{1,200}$/';

/** @return array<string,array{0:string,1:string}> service name => [label, 'Windows'|'Linux'] */
function rivetCsServiceChoices(): array
{
    return [
        'EventLog' => ['Windows Event Log', 'Windows'],
        'wuauserv' => ['Windows Update', 'Windows'],
        'WinDefend' => ['Microsoft Defender Antivirus', 'Windows'],
        'mpssvc' => ['Windows Firewall', 'Windows'],
        'W32Time' => ['Windows Time', 'Windows'],
        'Spooler' => ['Print Spooler', 'Windows'],
        'TermService' => ['Remote Desktop', 'Windows'],
        'Mesh Agent' => ['MeshCentral remote-access agent', 'Windows'],
        'VeeamEndpointBackupSvc' => ['Veeam Agent for Windows (backup)', 'Windows'],
        'ssh' => ['SSH server (Debian, Ubuntu)', 'Linux'],
        'sshd' => ['SSH server (Red Hat, Fedora, SUSE)', 'Linux'],
        'cron' => ['Scheduled tasks, cron (Debian, Ubuntu)', 'Linux'],
        'crond' => ['Scheduled tasks, cron (Red Hat, Fedora)', 'Linux'],
        'chronyd' => ['Time sync (chrony)', 'Linux'],
        'systemd-timesyncd' => ['Time sync (systemd)', 'Linux'],
        'rsyslog' => ['System log', 'Linux'],
        'fail2ban' => ['Fail2ban', 'Linux'],
    ];
}

/** The OS a service from the choice table runs on ('windows' or 'linux'), or null for a name the table does not know. */
function rivetCsServiceOs(string $name): ?string
{
    $c = rivetCsServiceChoices()[$name] ?? null;

    return $c === null ? null : strtolower($c[1]);
}

/**
 * Upgrade a stored check list (RivetCore 1.0.0-rc.24 per-OS targeting): every service check that has no `os` yet and is in the Windows/Linux choice
 * table gets its OS, so a Linux service is no longer sent to a Windows PC. Nothing else changes: other checks, services the table does not know, and
 * any `os` already stored are left exactly as they are. Idempotent.
 *
 * @param list<array<string,mixed>> $checks
 * @return list<array<string,mixed>>
 */
function rivetCsUpgradeChecks(array $checks): array
{
    foreach ($checks as $i => $c) {
        if (!is_array($c) || ($c['type'] ?? '') !== 'service' || (isset($c['os']) && $c['os'] !== '')) {
            continue;
        }
        $name = rivetCsParams($c)['name'] ?? null;
        $os = is_string($name) ? rivetCsServiceOs($name) : null;
        if ($os !== null) {
            $checks[$i]['os'] = $os;
        }
    }

    return $checks;
}

/** @return array<string,array<string,mixed>> option sets the preset buttons fill in */
function rivetCsPresets(): array
{
    return [
        'recommended' => ['label' => 'Recommended', 'about' => 'Low disk space below 15 % free, a restart that is waiting, the Windows event log, checked every 15 minutes.',
            'values' => ['disk_on' => 1, 'disk_warn' => 15, 'reboot_on' => 1, 'svc' => ['EventLog'], 'interval' => '900']],
        'light' => ['label' => 'Light', 'about' => 'Only low disk space below 10 % free, checked every hour. The least load on the devices.',
            'values' => ['disk_on' => 1, 'disk_warn' => 10, 'reboot_on' => 0, 'svc' => [], 'interval' => '3600']],
        'strict' => ['label' => 'Strict', 'about' => 'Low disk space below 20 % free, a waiting restart, and the Windows event log, update, Defender and time services, every 5 minutes.',
            'values' => ['disk_on' => 1, 'disk_warn' => 20, 'reboot_on' => 1, 'svc' => ['EventLog', 'wuauserv', 'WinDefend', 'W32Time'], 'interval' => '300']],
    ];
}

/** The state of the options that RivetCore's built-in list stands for (what "Restore defaults" shows). */
function rivetCsDefaultsValues(): array
{
    return ['disk_on' => 1, 'disk_warn' => 20, 'reboot_on' => 1, 'svc' => ['EventLog'], 'interval' => 'defaults'];
}

function rivetCsIsInt(mixed $v, int $min, int $max): bool
{
    return (is_int($v) || (is_string($v) && preg_match('/^\d{1,9}$/', $v) === 1)) && (int) $v >= $min && (int) $v <= $max;
}

function rivetCsServiceKey(string $name, array $taken): string
{
    $slug = strtolower(preg_replace('/[^A-Za-z0-9_.-]+/', '_', $name));
    $slug = trim((string) $slug, '_') ?: 'service';
    $key = 'svc_' . substr($slug, 0, 90);
    $n = 2;
    $base = $key;
    while (isset($taken[$key])) {
        $key = $base . '_' . $n++;
    }

    return $key;
}

/** Whole-number-only params object of a stored check, whatever shape it arrived in. */
function rivetCsParams(array $c): array
{
    $p = $c['params'] ?? [];
    if ($p instanceof \stdClass) {
        $p = json_decode((string) json_encode($p), true);
    }

    return is_array($p) ? $p : [];
}

/**
 * Read a stored check list as the options. Anything the options cannot express exactly goes to `kept` (shown in words, never edited).
 *
 * @param list<array<string,mixed>> $checks
 * @return array{disk:array{on:bool,warn:int,mount:string,key:string},reboot:array{on:bool,key:string},services:list<string>,svc_keys:array<string,string>,
 *     interval:string,intervals:array<string,int>,scripts_on:bool,scripts:list<array{name:string,script:string,timeout_s:int}>,kept:array<string,array<string,mixed>>}
 */
function rivetCsStateFromChecks(array $checks): array
{
    $st = ['disk' => ['on' => false, 'warn' => 20, 'mount' => '', 'key' => 'disk_free'], 'reboot' => ['on' => false, 'key' => 'pending_reboot'], 'services' => [], 'svc_keys' => [], 'svc_os' => [],
        'interval' => '900', 'intervals' => [], 'scripts_on' => false, 'scripts' => [], 'kept' => []];
    $diskDone = $rebootDone = false;
    $seenKeys = [];
    foreach (rivetCsUpgradeChecks($checks) as $c) {
        if (!is_array($c) || !is_string($c['key'] ?? null)) {
            continue;
        }
        $key = $c['key'];
        $type = (string) ($c['type'] ?? '');
        $p = rivetCsParams($c);
        $iv = is_int($c['interval_s'] ?? null) ? $c['interval_s'] : 0;
        $keepIt = static function () use (&$st, $c, $key): void { $st['kept'][$key] = $c; };
        if (isset($seenKeys[$key])) {
            continue;   // a duplicate key cannot be stored (the module refuses it); ignore defensively
        }
        $seenKeys[$key] = 1;
        if ($type === 'disk' && !$diskDone && in_array($key, ['disk_free', 'disk_c'], true) && array_diff(array_keys($p), ['mount', 'warn_free_pct', 'fail_free_pct', 'warn_pct', 'fail_pct']) === []) {
            $w = $p['warn_free_pct'] ?? null;
            $f = $p['fail_free_pct'] ?? null;
            $okW = $w === null ? $f === null : (is_int($w) && $w >= 2 && $w <= 90 && ($f === null || $f === intdiv($w, 2)));
            $mount = $p['mount'] ?? '';
            if ($okW && is_string($mount) && ($mount === '' || preg_match(RIVET_CS_MOUNT_RE, $mount) === 1) && (isset($p['warn_free_pct']) || (!isset($p['fail_free_pct'])))) {
                $diskDone = true;
                $st['disk'] = ['on' => true, 'warn' => $w ?? 20, 'mount' => $mount, 'key' => $key];
                $st['intervals'][$key] = $iv;
                continue;
            }
        }
        if ($type === 'pending_reboot' && !$rebootDone && $key === 'pending_reboot' && $p === []) {
            $rebootDone = true;
            $st['reboot'] = ['on' => true, 'key' => $key];
            $st['intervals'][$key] = $iv;
            continue;
        }
        if ($type === 'service' && array_diff(array_keys($p), ['name', 'expect', 'expected']) === [] && in_array($c['os'] ?? '', ['', 'windows', 'linux', 'any'], true)) {
            $name = $p['name'] ?? null;
            $exp = strtolower((string) ($p['expected'] ?? $p['expect'] ?? 'running'));
            if (is_string($name) && preg_match(RIVET_CS_SERVICE_RE, $name) === 1 && $exp === 'running' && !in_array($name, $st['services'], true)) {
                $st['services'][] = $name;
                $st['svc_keys'][$name] = $key;
                $st['svc_os'][$name] = in_array($c['os'] ?? null, ['windows', 'linux'], true) ? (string) $c['os'] : '';
                $st['intervals'][$key] = $iv;
                continue;
            }
        }
        if ($type === 'script' && array_diff(array_keys($p), ['script', 'timeout_s']) === [] && preg_match(RIVET_CS_KEY_RE, $key) === 1
            && is_string($p['script'] ?? null) && $p['script'] !== '' && count($st['scripts']) < RIVET_CS_MAX_SCRIPTS) {
            $st['scripts'][] = ['name' => $key, 'script' => $p['script'], 'timeout_s' => is_int($p['timeout_s'] ?? null) ? $p['timeout_s'] : 30];
            $st['intervals'][$key] = $iv;
            $st['scripts_on'] = true;
            continue;
        }
        $keepIt();
    }
    $ivs = array_values(array_unique(array_values($st['intervals'])));
    if ($ivs === []) {
        $st['interval'] = '900';
    } elseif (count($ivs) === 1 && isset(RIVET_CS_INTERVALS[$ivs[0]])) {
        $st['interval'] = (string) $ivs[0];
    } else {
        $st['interval'] = 'keep';
    }

    return $st;
}

/** @param array<string,mixed> $c a check */
function rivetCsEveryWords(int $s): string
{
    if ($s % 3600 === 0) {
        return $s === 3600 ? 'hour' : ($s / 3600) . ' hours';
    }
    if ($s % 60 === 0) {
        return ($s / 60) . ' minutes';
    }

    return $s . ' seconds';
}

/** One check in words. */
function rivetCsDescribe(array $c): string
{
    $p = rivetCsParams($c);
    $type = (string) ($c['type'] ?? '');
    $every = ' Checked every ' . rivetCsEveryWords((int) ($c['interval_s'] ?? 0)) . '.';
    $key = (string) ($c['key'] ?? '');
    switch ($type) {
        case 'disk':
            $w = $p['warn_free_pct'] ?? ($p['warn_free_gb'] ?? null);
            $where = isset($p['mount']) && $p['mount'] !== '' ? 'on ' . $p['mount'] : 'on every disk';
            if (isset($p['warn_free_pct'])) {
                return "Low disk space $where: warn below {$p['warn_free_pct']}% free" . (isset($p['fail_free_pct']) ? ", critical below {$p['fail_free_pct']}%." : '.') . $every;
            }

            return "Low disk space $where" . ($w === null ? ': warn below 20% free, critical below 10% (built-in limits).' : '.') . $every;
        case 'pending_reboot':
            return 'Waiting for a restart: warn when the device is waiting for one.' . $every;
        case 'service':
            $osw = ['windows' => ' (Windows devices only)', 'linux' => ' (Linux devices only)'][(string) ($c['os'] ?? '')] ?? '';

            return 'Service ' . (string) ($p['name'] ?? '?') . ' must be running' . $osw . '.' . $every;
        case 'script':
            return "Your script \"$key\" (gives up after " . (int) ($p['timeout_s'] ?? 30) . ' seconds).' . $every;
        default:
            return "Other check \"$key\" ($type)." . $every;
    }
}

/** "5 checks every 15 minutes" */
function rivetCsSummary(array $list): string
{
    $n = count($list);
    if ($n === 0) {
        return 'No checks: agents will not run any checks.';
    }
    $ivs = array_values(array_unique(array_map(static fn (array $c): int => (int) ($c['interval_s'] ?? 0), $list)));
    sort($ivs);
    $when = count($ivs) === 1 ? 'every ' . rivetCsEveryWords($ivs[0]) : 'every ' . rivetCsEveryWords($ivs[0]) . ' to every ' . rivetCsEveryWords(end($ivs));

    return $n . ($n === 1 ? ' check ' : ' checks ') . $when;
}

/**
 * Build the check list from the posted options.
 *
 * @param mixed $cs the posted `cs` array (anything else is refused)
 * @param list<array<string,mixed>> $stored the list stored now (or the built-in list)
 * @return array{list:?list<array<string,mixed>>,errors:list<string>,warnings:list<string>}
 */
function rivetCsBuild(mixed $cs, array $stored): array
{
    $err = [];
    $warn = [];
    $fail = static fn (array $e): array => ['list' => null, 'errors' => $e, 'warnings' => []];
    if (!is_array($cs)) {
        return $fail(['The check options were not sent correctly. Reload the page and try again.']);
    }
    foreach (array_keys($cs) as $k) {
        if (!in_array($k, ['disk_on', 'disk_warn', 'reboot_on', 'svc', 'svc_extra', 'svc_extra_os', 'interval', 'scripts_on', 'scripts', 'remove'], true)) {
            return $fail(['The check options contain a setting this page does not know ("' . substr(preg_replace('/[^\x20-\x7e]/', '?', (string) $k), 0, 40) . '"). Reload the page and try again.']);
        }
    }
    foreach (['svc', 'scripts', 'remove'] as $k) {
        if (isset($cs[$k]) && !is_array($cs[$k])) {
            return $fail(['The check options were not sent correctly. Reload the page and try again.']);
        }
    }
    foreach (['disk_on', 'disk_warn', 'reboot_on', 'svc_extra', 'svc_extra_os', 'interval', 'scripts_on'] as $k) {
        if (isset($cs[$k]) && !is_string($cs[$k])) {
            return $fail(['The check options were not sent correctly. Reload the page and try again.']);
        }
    }
    $extraOs = (string) ($cs['svc_extra_os'] ?? 'windows');
    if (!in_array($extraOs, ['windows', 'linux'], true)) {
        return $fail(['Services: choose Windows or Linux for the service you add.']);
    }
    $st = rivetCsStateFromChecks($stored);
    $interval = (string) ($cs['interval'] ?? '');
    if ($interval === 'keep' && $st['interval'] === 'keep') {
        $ivFor = static fn (string $key): int => ($st['intervals'][$key] ?? 0) >= 30 ? (int) $st['intervals'][$key] : 900;
    } elseif (isset(RIVET_CS_INTERVALS[(int) $interval]) && (string) (int) $interval === $interval) {
        $ivFor = static fn (string $key): int => (int) $interval;
    } else {
        return $fail(['Choose how often to check.']);
    }
    $list = [];
    $keys = [];
    foreach ($st['kept'] as $k => $_) {
        $keys[$k] = 1;
    }
    // Low disk space
    if (($cs['disk_on'] ?? '') === '1') {
        $w = $cs['disk_warn'] ?? '';
        if (!rivetCsIsInt($w, 2, 90)) {
            $err[] = 'Low disk space: choose how much free space is too little (a whole number from 2 to 90 percent).';
        } else {
            $w = (int) $w;
            $key = $st['disk']['on'] ? $st['disk']['key'] : 'disk_free';
            if (isset($keys[$key])) {
                $key = 'disk_free_2';
            }
            $keys[$key] = 1;
            $params = $st['disk']['mount'] !== '' && $st['disk']['on'] ? ['mount' => $st['disk']['mount']] : [];
            $params += ['warn_free_pct' => $w, 'fail_free_pct' => max(1, intdiv($w, 2))];
            $list[] = ['key' => $key, 'type' => 'disk', 'params' => $params, 'interval_s' => $ivFor($key)];
        }
    }
    // Waiting for a restart
    if (($cs['reboot_on'] ?? '') === '1') {
        $key = $st['reboot']['on'] ? $st['reboot']['key'] : 'pending_reboot';
        if (isset($keys[$key])) {
            $key = 'pending_reboot_2';
        }
        $keys[$key] = 1;
        $list[] = ['key' => $key, 'type' => 'pending_reboot', 'params' => [], 'interval_s' => $ivFor($key)];
    }
    // Important services
    $choices = rivetCsServiceChoices();
    $names = [];
    $osOf = [];
    foreach ((array) ($cs['svc'] ?? []) as $n) {
        if (!is_string($n) || !isset($choices[$n])) {
            $err[] = 'Services: one ticked service is not in the list. Reload the page and try again.';
            continue;
        }
        $names[strtolower($n)] = $n;
        $osOf[$n] = strtolower($choices[$n][1]);   // from the Windows/Linux choice table
    }
    $extra = (string) ($cs['svc_extra'] ?? '');
    if (strlen($extra) > 4000) {
        $err[] = 'Services: the "add another service" box is too long.';
        $extra = '';
    }
    foreach (preg_split('/[\r\n,]+/', $extra) ?: [] as $n) {
        $n = trim($n);
        if ($n === '') {
            continue;
        }
        $lineOs = null;
        if (preg_match('/^(windows|linux)\s*:\s*(.+)$/i', $n, $pm) === 1) {   // "linux: nginx": the OS typed on the line wins
            $lineOs = strtolower($pm[1]);
            $n = trim($pm[2]);
        }
        if (preg_match(RIVET_CS_SERVICE_RE, $n) !== 1) {
            $err[] = 'Services: "' . substr(preg_replace('/[^\x20-\x7e]/', '?', $n), 0, 40) . '" is not a service name. Use the short service name, with letters, digits and . _ - @ : + only.';
            continue;
        }
        if (isset($names[strtolower($n)])) {
            continue;   // already ticked above (or listed twice): the first one wins
        }
        $names[strtolower($n)] = $n;
        // The OS: typed on the line, else a service the table knows, else what an earlier save stored (a stored service with no OS stays "both"), else the dropdown for a new one.
        $osOf[$n] = $lineOs ?? rivetCsServiceOs($n) ?? (isset($st['svc_keys'][$n]) ? $st['svc_os'][$n] : $extraOs);
    }
    if (count($names) > RIVET_CS_MAX_SERVICES) {
        $err[] = 'Services: watch at most ' . RIVET_CS_MAX_SERVICES . ' services.';
    }
    foreach ($names as $n) {
        $key = $st['svc_keys'][$n] ?? '';
        if ($key === '' || isset($keys[$key])) {
            $key = rivetCsServiceKey($n, $keys);
        }
        $keys[$key] = 1;
        $svc = ['key' => $key, 'type' => 'service', 'params' => ['name' => $n, 'expected' => 'running'], 'interval_s' => $ivFor($key)];
        if (($osOf[$n] ?? '') !== '') {
            $svc['os'] = $osOf[$n];
        }
        $list[] = $svc;
    }
    // Your own scripts (Advanced)
    if (($cs['scripts_on'] ?? '') === '1') {
        $i = 0;
        foreach ((array) ($cs['scripts'] ?? []) as $row) {
            $i++;
            if (!is_array($row) || array_diff(array_keys($row), ['name', 'script', 'timeout_s']) !== [] || array_filter($row, static fn ($v): bool => !is_string($v)) !== []) {
                $err[] = "Script $i: the form was not sent correctly. Reload the page and try again.";
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            $body = str_replace(["\r\n", "\r"], "\n", (string) ($row['script'] ?? ''));
            if ($name === '' && trim($body) === '') {
                continue;   // an empty row is simply ignored
            }
            if ($i > RIVET_CS_MAX_SCRIPTS) {
                $err[] = 'Scripts: at most ' . RIVET_CS_MAX_SCRIPTS . ' scripts.';
                break;
            }
            if (preg_match(RIVET_CS_KEY_RE, $name) !== 1) {
                $err[] = "Script $i: give it a short name, using letters, digits and . _ : - only (no spaces).";
                continue;
            }
            if (trim($body) === '') {
                $err[] = "Script \"$name\": type the commands to run.";
                continue;
            }
            if (strlen($body) > RIVET_CS_SCRIPT_MAX_BYTES) {
                $err[] = "Script \"$name\": the script is longer than 8 KiB (" . strlen($body) . ' bytes). Shorten it.';
                continue;
            }
            $t = $row['timeout_s'] ?? '30';
            if (!rivetCsIsInt($t, 1, 60)) {
                $err[] = "Script \"$name\": choose how long it may run (at most 1 minute).";
                continue;
            }
            if (isset($keys[$name])) {
                $err[] = "Script \"$name\": that name is already used by another check. Choose a different name.";
                continue;
            }
            $keys[$name] = 1;
            $list[] = ['key' => $name, 'type' => 'script', 'params' => ['script' => $body, 'timeout_s' => (int) $t], 'interval_s' => $ivFor($name)];
        }
    }
    // Checks the options cannot express are kept exactly as stored unless ticked for removal.
    $remove = [];
    foreach ((array) ($cs['remove'] ?? []) as $k) {
        if (is_string($k) && isset($st['kept'][$k])) {
            $remove[$k] = 1;
        }
    }
    foreach ($st['kept'] as $k => $c) {
        if (!isset($remove[$k])) {
            $list[] = rivetCsUpgradeChecks([$c])[0];
        }
    }
    if (count($list) > 50) {
        $err[] = 'There are too many checks (' . count($list) . '; the most is 50). Turn some off.';
    }
    if ($err === []) {
        // RivetCore stays the authority: every check once on its own (so a message can name it), and the whole list once.
        if (class_exists(\RivetCore\Rmm\Settings\ChecksValidator::class)) {
            foreach ($list as $c) {
                [, $e] = \RivetCore\Rmm\Settings\ChecksValidator::validate((string) json_encode([$c]));
                if ($e !== null) {
                    $err[] = 'Check "' . (string) $c['key'] . '": ' . $e;
                }
            }
            if ($err === []) {
                [, $e] = \RivetCore\Rmm\Settings\ChecksValidator::validate((string) json_encode($list));
                if ($e !== null) {
                    $err[] = $e;
                }
            }
        }
    }
    if ($err !== []) {
        return ['list' => null, 'errors' => $err, 'warnings' => []];
    }
    if ($list === []) {
        $warn[] = 'No checks are selected, so agents will not run any checks. Use Restore defaults to go back to the built-in set.';
    }
    if (($st['kept'] !== []) && count($remove) < count($st['kept'])) {
        $warn[] = (count($st['kept']) - count($remove)) . ' earlier check(s) the options above cannot show are kept as they are.';
    }

    return ['list' => $list, 'errors' => [], 'warnings' => $warn];
}
