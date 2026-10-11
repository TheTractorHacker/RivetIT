<?php

/*
 * RMM administration helpers for the remote tools, file transfer, log collection, scale settings and the external metric store (RivetCore 1.0.0-rc.11).
 * Used by admin/settings_endpoint_agent.php (the cards) and admin/post/settings_endpoint_agent.php (the handlers). Everything here is plain functions with
 * no output; the cards print, Core validates and audits. Nothing here writes a Core table.
 *
 * Secrets: the external store's URLs and bearer token are NEVER returned by any function in this file except rivetRmmExtStoreStored(), which exists for the
 * Test button and the module wiring and is not used by a page that prints.
 */

require_once __DIR__ . '/rmm_bootstrap.php';   // rivetRmmExtStoreAllowHttp(), rivetWebhookUrlPolicy()

use RivetCore\Rmm\Metrics\VictoriaMetricsStore;
use RivetCore\Rmm\Storage\PartitionMaintenance;
use RivetCore\Rmm\Storage\StorageState;
use RivetCore\Webhooks\UrlPolicy;

/** Byte count in plain words (binary units, the way the limits are defined). */
function rivetRmmScaleBytes($bytes): string
{
    $b = (float) $bytes;
    foreach ([[1099511627776.0, 'TB'], [1073741824.0, 'GB'], [1048576.0, 'MB'], [1024.0, 'KB']] as [$size, $unit]) {
        if ($b >= $size) {
            $v = $b / $size;

            return rtrim(rtrim(number_format($v, $v >= 100 ? 0 : ($v >= 10 ? 1 : 2), '.', ''), '0'), '.') . ' ' . $unit;
        }
    }

    return (int) $b . ' B';
}

/**
 * A stored whole number as [number, unit] for a number-plus-unit field: the largest unit that divides it exactly, so what an administrator typed
 * comes back as typed (50 MB, not 51200 KB).
 *
 * @param array<string,int> $units unit key => size, any order
 * @return array{0:int,1:string}
 */
function rivetRmmScaleUnitValue(int $value, array $units): array
{
    arsort($units);
    foreach ($units as $k => $size) {
        if ($value >= $size && $value % $size === 0) {
            return [intdiv($value, $size), (string) $k];
        }
    }
    $smallest = array_key_last($units);

    return [intdiv($value, $units[$smallest]), (string) $smallest];
}

/** The folder file transfers are stored in, and whether the install named it (EA_TRANSFER_DIR) or it is the default inside the app's denied backups/ area. */
function rivetRmmTransferDir(): array
{
    $root = dirname(__DIR__);
    $named = defined('EA_TRANSFER_DIR') && (string) EA_TRANSFER_DIR !== '';

    return ['path' => $named ? (string) EA_TRANSFER_DIR : $root . '/backups/endpoint-transfer', 'named' => $named, 'root' => $root];
}

/**
 * State of the transfer folder for the card.
 *
 * @return array{path:string,named:bool,exists:bool,is_dir:bool,writable:bool,mode:?string,free_bytes:?int,budget_bytes:int,enough:?bool,inside_app:bool,problem:?string}
 */
function rivetRmmTransferDirStatus(int $budgetBytes, ?string $path = null): array
{
    $d = rivetRmmTransferDir();
    $p = $path ?? $d['path'];
    $exists = file_exists($p);
    $isDir = is_dir($p);
    $probe = $isDir ? $p : $p;
    while ($probe !== '' && $probe !== '/' && !file_exists($probe)) {
        $probe = dirname($probe);
    }
    $free = $probe !== '' && file_exists($probe) ? @disk_free_space($probe) : false;
    $free = $free === false ? null : (int) $free;
    $real = $isDir ? realpath($p) : false;
    $rootReal = realpath($d['root']) ?: $d['root'];
    $inside = $real !== false ? str_starts_with($real . '/', $rootReal . '/') : str_starts_with($p, $d['root'] . '/');
    $problem = null;
    if ($exists && !$isDir) {
        $problem = 'A file with this name is in the way.';
    } elseif ($isDir && !is_writable($p)) {
        $problem = 'The folder exists but the web server user cannot write to it.';
    }

    return ['path' => $p, 'named' => $d['named'] && $path === null, 'exists' => $exists, 'is_dir' => $isDir, 'writable' => $isDir && is_writable($p),
        'mode' => $isDir ? substr(sprintf('%o', fileperms($p)), -4) : null, 'free_bytes' => $free, 'budget_bytes' => $budgetBytes,
        'enough' => $free === null ? null : $free >= $budgetBytes, 'inside_app' => $inside, 'problem' => $problem];
}

/**
 * Create the transfer folder as the web user with mode 0700. Idempotent. A folder that EA_TRANSFER_DIR names is trusted; the default is created only when it
 * resolves inside the app directory (a symlinked backups/ pointing elsewhere is refused). $path is for tests: it is treated like a default, never as trusted.
 *
 * @return array{ok:bool,message:string,created:bool}
 */
function rivetRmmTransferDirCreate(?string $path = null): array
{
    $d = rivetRmmTransferDir();
    $trusted = $path === null && $d['named'];
    $p = $path ?? $d['path'];
    if ($p === '' || $p[0] !== '/' || str_contains($p, "\0") || preg_match('#(^|/)\.\.(/|$)#', $p) === 1) {
        return ['ok' => false, 'message' => 'The transfer folder must be an absolute path without "..".', 'created' => false];
    }
    $p = rtrim($p, '/');
    if (!$trusted) {
        $rootReal = realpath($d['root']);
        $probe = $p;
        while ($probe !== '' && $probe !== '/' && !file_exists($probe)) {
            $probe = dirname($probe);
        }
        $probeReal = file_exists($probe) ? realpath($probe) : false;
        if ($rootReal === false || $probeReal === false || !(str_starts_with($probeReal . '/', $rootReal . '/'))) {
            return ['ok' => false, 'message' => 'Refused: that folder is outside the application directory. To store transfers elsewhere, define EA_TRANSFER_DIR in config.php.', 'created' => false];
        }
    }
    if (file_exists($p) && !is_dir($p)) {
        return ['ok' => false, 'message' => 'A file with the name of the transfer folder is in the way.', 'created' => false];
    }
    $created = false;
    if (!is_dir($p)) {
        $old = umask(0077);
        $made = @mkdir($p, 0700, true);
        umask($old);
        if (!$made && !is_dir($p)) {
            return ['ok' => false, 'message' => 'The web server user could not create the folder. Create it yourself, owned by the web server user, mode 0700.', 'created' => false];
        }
        $created = true;
    }
    @chmod($p, 0700);
    if (!is_writable($p)) {
        return ['ok' => false, 'message' => 'The folder exists but the web server user cannot write to it.', 'created' => $created];
    }

    return ['ok' => true, 'message' => $created ? 'Transfer folder created (mode 0700).' : 'The transfer folder already exists and is ready (mode set to 0700).', 'created' => $created];
}

// ---------------------------------------------------------------------------------------------------------------------------------------------------- partitioning

/**
 * The partition probe in plain words. $probe is PartitionMaintenance::probe() (or the copy StorageState keeps).
 *
 * @param array<string,mixed> $probe
 * @return array{can:bool,summary:string,server:string,reasons:list<string>,history:array{exists:bool,partitioned:bool,convertible:bool,reasons:list<string>,warnings:list<string>,partitions:int}}
 */
function rivetRmmScaleProbeWords(array $probe): array
{
    $hist = $probe['tables']['endpoint_agent_check_history'] ?? ['exists' => false, 'partitioned' => false, 'convertible' => false, 'reasons' => ['Not checked.'], 'warnings' => [], 'partitions' => 0];
    $server = ($probe['server'] ?? 'mysql') === 'mariadb' ? 'MariaDB' : 'MySQL';
    $server .= ' ' . preg_replace('/[^0-9.].*$/', '', (string) ($probe['version'] ?? ''));
    if (empty($probe['supported'])) {
        $why = implode(' ', array_map('strval', (array) ($probe['reasons'] ?? [])));
        $summary = 'No. This database server or the database account cannot partition tables' . ($why !== '' ? ': ' . $why : '.');
    } elseif (!empty($hist['partitioned'])) {
        $summary = 'Already done. The check-history table is split into ' . (int) $hist['partitions'] . ' time partitions; expired days are removed by dropping a partition instead of deleting rows.';
    } elseif (!empty($hist['convertible'])) {
        $summary = 'Yes. This server and account can partition the check-history table.';
    } else {
        $summary = 'No, not for the check-history table: ' . implode(' ', array_map('strval', (array) $hist['reasons']));
    }

    return ['can' => !empty($probe['supported']) && !empty($hist['convertible']) && empty($hist['partitioned']), 'summary' => $summary, 'server' => $server,
        'reasons' => array_map('strval', (array) ($probe['reasons'] ?? [])), 'history' => $hist];
}

/** A partition's upper boundary as text. */
function rivetRmmScalePartitionBound(?int $ts): string
{
    return $ts === null ? 'everything later' : 'before ' . gmdate('Y-m-d', $ts) . ' UTC';
}

// ---------------------------------------------------------------------------------------------------------------------------------------------------- external metric store

/** What is stored, decrypted: for the Test button and the module wiring only, never for output. @return array{write:string,read:string,token:string,enabled:bool} */
function rivetRmmExtStoreStored($mysqli): array
{
    $out = ['write' => '', 'read' => '', 'token' => '', 'enabled' => false];
    try {
        $row = mysqli_fetch_assoc(mysqli_query($mysqli, 'SELECT config_rmm_extstore_enabled AS e, config_rmm_extstore_write_url AS w, config_rmm_extstore_read_url AS r, config_rmm_extstore_token AS t FROM settings WHERE company_id = 1'));
    } catch (\Throwable) {
        $row = null;
    }
    if ($row) {
        $out = ['write' => trim(decryptSetting((string) $row['w'])), 'read' => trim(decryptSetting((string) $row['r'])), 'token' => decryptSetting((string) $row['t']), 'enabled' => (int) $row['e'] === 1];
    }

    return $out;
}

/** Whether each secret is stored (no value). @return array{enabled:bool,write:bool,read:bool,token:bool} */
function rivetRmmExtStoreFlags($mysqli): array
{
    try {
        $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_rmm_extstore_enabled AS e, (config_rmm_extstore_write_url IS NOT NULL AND config_rmm_extstore_write_url <> '') AS w,
            (config_rmm_extstore_read_url IS NOT NULL AND config_rmm_extstore_read_url <> '') AS r, (config_rmm_extstore_token IS NOT NULL AND config_rmm_extstore_token <> '') AS t FROM settings WHERE company_id = 1"));
    } catch (\Throwable) {
        $row = null;
    }

    return ['enabled' => (int) ($row['e'] ?? 0) === 1, 'write' => (int) ($row['w'] ?? 0) === 1, 'read' => (int) ($row['r'] ?? 0) === 1, 'token' => (int) ($row['t'] ?? 0) === 1, 'ready' => $row !== null];
}

/**
 * Why an address cannot be used, in words, or null when it can. Mirrors the policy the store itself applies (https only, no user name or password in the address, no
 * query, every address it resolves to public or on the webhook allowed-networks list), so the answer is the same before and after saving.
 */
function rivetRmmExtStoreUrlProblem($mysqli, string $url): ?string
{
    $url = trim($url);
    $p = parse_url($url);
    $allowHttp = rivetRmmExtStoreAllowHttp();
    if ($url === '' || !is_array($p) || empty($p['host']) || empty($p['scheme'])) {
        return 'it is not a web address (use https://host/path).';
    }
    $scheme = strtolower((string) $p['scheme']);
    if ($scheme !== 'https' && !($scheme === 'http' && $allowHttp)) {
        return $scheme === 'http' ? 'plain http is refused; the store must be reached over https.' : 'only https addresses are accepted.';
    }
    if (isset($p['user']) || isset($p['pass'])) {
        return 'a user name or password inside the address is not allowed (put the token in the token field).';
    }
    if (isset($p['query']) || isset($p['fragment'])) {
        return 'the address must not contain a query or fragment.';
    }
    if (preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
        return 'the address contains spaces or control characters.';
    }
    if (rivetWebhookUrlPolicy($mysqli)->vet($url) !== null) {
        return null;
    }
    $host = strtolower(trim((string) $p['host'], '[]'));
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (array) (@gethostbynamel($host) ?: []);
    if ($ips === []) {
        return 'the name does not resolve to an address.';
    }
    foreach ($ips as $ip) {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            continue;
        }
        $v4 = strlen($bin) === 4 ? $ip : null;
        if (($v4 !== null && str_starts_with($v4, '127.')) || $ip === '::1') {
            return 'it points at this server itself (a loopback address); the policy never allows that.';
        }
        if (($v4 !== null && str_starts_with($v4, '169.254.')) || str_starts_with(strtolower($ip), 'fe80:')) {
            return 'it points at a link-local or cloud-metadata address; the policy never allows that.';
        }
        if (!UrlPolicy::isPublicIp($ip)) {
            return 'it points at a private or reserved network address; only public addresses are allowed unless the network is listed under the allowed webhook networks.';
        }
    }

    return 'it is not allowed by the address policy (public addresses only).';
}

/**
 * Try the store with a short timeout and say what happened. Nothing is written to the store. The token and the full address are never part of the answer.
 *
 * @return array{ok:bool,status:string,message:string}  status: ok | refused | unreachable | auth | invalid
 */
function rivetRmmExtStoreTest($mysqli, string $write, string $read, string $token): array
{
    $write = trim($write);
    $read = trim($read);
    if ($write === '') {
        return ['ok' => false, 'status' => 'invalid', 'message' => 'There is no write address to test. Enter one, or save it first.'];
    }
    foreach ([['write', $write], ['read', $read]] as [$what, $u]) {
        if ($u === '') {
            continue;
        }
        $why = rivetRmmExtStoreUrlProblem($mysqli, $u);
        if ($why !== null) {
            return ['ok' => false, 'status' => 'refused', 'message' => 'Refused before any request was made: the ' . $what . ' address is not accepted, ' . $why];
        }
    }
    $policy = rivetWebhookUrlPolicy($mysqli);
    try {
        $store = new VictoriaMetricsStore($write, $policy, null, $read !== '' ? $read : null, $token !== '' ? $token : null, null, 5, rivetRmmExtStoreAllowHttp());
    } catch (\Throwable) {
        return ['ok' => false, 'status' => 'invalid', 'message' => 'The addresses or the token have a form the store client does not accept.'];
    }
    $probeRead = static function (VictoriaMetricsStore $s): ?string {
        try {
            $s->peak(1, 'cpu.utilization', null, new \DateTimeImmutable('-1 hour'));

            return null;
        } catch (\Throwable $e) {
            $m = $e->getMessage();
            if (preg_match('/HTTP (401|403|407)/', $m) === 1) {
                return 'auth';
            }
            if (str_contains($m, 'URL policy') || str_contains($m, 'plain http')) {
                return 'refused';
            }

            return 'unreachable';
        }
    };
    $alive = $store->ping();
    $read1 = $probeRead($store);
    if ($read1 === 'auth') {
        return ['ok' => false, 'status' => 'auth', 'message' => 'The store answered but rejected the token (HTTP 401 or 403). Check the bearer token, and that the proxy in front of the store expects "Authorization: Bearer".'];
    }
    if ($read1 === 'refused') {
        return ['ok' => false, 'status' => 'refused', 'message' => 'Refused by the address policy while connecting. No data was sent.'];
    }
    if (!$alive && $read1 !== null) {
        return ['ok' => false, 'status' => 'unreachable', 'message' => 'The store did not answer within 5 seconds, or answered with an error. Check the address, the port, the TLS certificate and the firewall.'];
    }
    // The write address may live on another host than the read address: check it too, when it differs.
    $note = '';
    if ($read !== '' && rtrim($read, '/') !== rtrim($write, '/')) {
        try {
            $ws = new VictoriaMetricsStore($write, $policy, null, null, $token !== '' ? $token : null, null, 5, rivetRmmExtStoreAllowHttp());
            if (!$ws->ping()) {
                return ['ok' => false, 'status' => 'unreachable', 'message' => 'The read address answers, but the write address did not answer its health check. Check the write address.'];
            }
        } catch (\Throwable) {
            return ['ok' => false, 'status' => 'invalid', 'message' => 'The write address has a form the store client does not accept.'];
        }
    }
    if (!$alive) {
        $note = ' The health check (/health) did not return success, but a query was answered.';
    }

    return ['ok' => true, 'status' => 'ok', 'message' => 'The store answered its health check and accepted a read with this token. Nothing was written to it.' . $note];
}

// ---------------------------------------------------------------------------------------------------------------------------------------------------- page messages

/** Keep a result for the card it belongs to, shown once on the next page load in an aria-live region (the toast is not enough for a long refusal text). */
function rivetRmmScaleMsg(string $card, string $type, string $text, ?array $old = null): void
{
    $_SESSION['rmm_scale_msg'][$card] = ['type' => $type, 'text' => $text];
    if ($old !== null) {
        $_SESSION['rmm_scale_old'][$card] = $old;
    }
}

/** @return array{0:?array{type:string,text:string},1:array<string,mixed>} the message and the refused form values of a card, consumed */
function rivetRmmScaleTake(string $card): array
{
    $m = $_SESSION['rmm_scale_msg'][$card] ?? null;
    $o = $_SESSION['rmm_scale_old'][$card] ?? [];
    unset($_SESSION['rmm_scale_msg'][$card], $_SESSION['rmm_scale_old'][$card]);

    return [$m, is_array($o) ? $o : []];
}

/** The HTML of a card's message (text escaped here). */
function rivetRmmScaleMsgHtml(?array $m): string
{
    if ($m === null) {
        return '';
    }
    $cls = ['success' => 'success', 'error' => 'danger', 'warning' => 'warning', 'info' => 'info'][$m['type']] ?? 'info';

    return '<div class="alert alert-' . $cls . ' py-2 mb-3 rmm-scale-msg" role="' . ($cls === 'danger' || $cls === 'warning' ? 'alert' : 'status') . '" tabindex="-1" data-rmm-scale-msg>'
        . nullable_htmlentities($m['text']) . '</div>';
}

/** A suggested per-client token bucket from the largest department's active device count and the check-in interval. @return array{burst:int,per_min:int,largest:int} */
function rivetRmmScaleBucketSuggestion($mysqli, int $checkInIntervalS): array
{
    $largest = 0;
    try {
        $res = mysqli_query($mysqli, 'SELECT COUNT(*) AS n FROM endpoint_agent_devices WHERE revoked_at IS NULL AND retired_at IS NULL GROUP BY client_id ORDER BY n DESC LIMIT 1');
        $largest = $res ? (int) (mysqli_fetch_row($res)[0] ?? 0) : 0;
    } catch (\Throwable) {
        $largest = 0;
    }
    $steady = (int) ceil(max(1, $largest) * 60 / max(60, $checkInIntervalS));
    $perMin = max(60, $steady * 3);

    return ['per_min' => min(1000000, $perMin), 'burst' => min(1000000, max(200, $perMin * 4)), 'largest' => $largest];
}
