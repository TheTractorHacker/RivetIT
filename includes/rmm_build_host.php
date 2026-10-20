<?php

/*
 * Local agent builds: "Build on my build host" (RivetIT 26.10.57, DB 2.6.177). Server half of rivet-core docs/rmm/BUILD_HOST_PROTOCOL.md.
 *
 * ROLES. An administrator adds a build host (a name and a token shown once) and asks for a build of a version. A script on that machine polls this server (pull model, no inbound
 * connection), builds, and uploads the files in chunks. The server hashes and validates them with the existing code (RmmAdmin::uploadBinary / uploadMsiTemplate) and publishes.
 *
 * SECURITY RULES (read before changing anything)
 *  - The token is `rbh_<16 hex>_<48 hex>`: the first part is a public lookup id, the second the secret. Only sha256(salt . secret) and the salt are stored; compared with hash_equals().
 *    An unknown id costs the same hash and compare, so the answer time does not tell which part was wrong. A token authenticates the four build endpoints and nothing else.
 *  - The build host decides nothing about paths: file names come from a fixed list, storage names are random hex made here, the job directory is a random hex name. Nothing from an upload
 *    is executed or interpreted except hashing, the SHA256SUMS text, a plain byte search for the version string and the existing validators.
 *  - Every size is capped (chunk, file, job, chunk count, files per job, request body, note, log tail). Every call is rate limited and the outcome is audited.
 *  - Text from the host (note, log tail, commit, go version) is stored sanitised and escaped again on output.
 */

require_once __DIR__ . '/rmm_agent_feed.php';
require_once __DIR__ . '/rivet_time.php';
require_once __DIR__ . '/rmm_agent_build.php';

const RIVET_BH_VERSION_RE = '/^(\d{1,5})\.(\d{1,5})\.(\d{1,5})(?:-([0-9A-Za-z][0-9A-Za-z.-]{0,19}))?\z/';
const RIVET_BH_TOKEN_RE = '/^rbh_([0-9a-f]{16})_([0-9a-f]{48})\z/';
const RIVET_BH_REF_RE = '/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,59}\z/';
const RIVET_BH_FILES = ['rivetit-agent-windows-amd64.exe', 'rivetit-agent-windows-arm64.exe', 'rivetit-agent-windows-amd64.msi', 'SHA256SUMS'];
const RIVET_BH_TARGETS = ['windows-amd64', 'windows-arm64', 'linux-amd64', 'linux-arm64'];
const RIVET_BH_CHUNK_MAX = 1048576;          // raw bytes per chunk
const RIVET_BH_BODY_MAX = 1500000;           // request body (base64 of 1 MiB is 1,398,104 bytes plus the JSON envelope)
const RIVET_BH_SUMS_MAX = 16384;             // SHA256SUMS
const RIVET_BH_LEASE_CLAIM_S = 900;          // 15 minutes
const RIVET_BH_LEASE_WORK_S = 3600;          // 60 minutes without a status call
const RIVET_BH_KEEP_BUILT_S = 86400;         // a verified build that nobody publishes is dropped after a day
/** Build stages a host may report, in order, with the plain words the page shows (the page never shows host-sent text as a stage name). */
const RIVET_BH_STAGES = [
    'prepare' => ['Getting ready', 'Prepare the build'],
    'clone' => ['Fetching the source', 'Fetch the source'],
    'build_windows_amd64' => ['Building the Windows x64 agent', 'Build the Windows x64 agent'],
    'build_windows_arm64' => ['Building the Windows ARM64 agent', 'Build the Windows ARM64 agent'],
    'msi' => ['Building the installer (MSI)', 'Build the installer (MSI)'],
    'verify' => ['Checking the files', 'Check the files'],
    'upload' => ['Uploading the files', 'Upload the files'],
];
const RIVET_BH_ACTIVE = ['claimed', 'building', 'uploading', 'verifying'];
const RIVET_BH_OPEN = ['queued', 'claimed', 'building', 'uploading', 'verifying'];

function rivetBuildHostReady($mysqli = null): bool
{
    static $memo = [];
    $mysqli ??= $GLOBALS['mysqli'] ?? null;
    $key = $mysqli instanceof \mysqli ? spl_object_id($mysqli) : 0;
    if (empty($memo[$key])) {
        $ok = false;
        if ($mysqli instanceof \mysqli) {
            $a = mysqli_query($mysqli, "SHOW TABLES LIKE 'endpoint_agent_build_chunks'");
            $ok = $a && mysqli_num_rows($a) > 0;
        }
        $memo[$key] = $ok;
    }

    return $memo[$key];
}

function rivetBuildHostRoot(): string
{
    return defined('RIVET_BUILD_HOST_DIR') ? rtrim((string) RIVET_BUILD_HOST_DIR, '/') : dirname(__DIR__) . '/backups/rmm-build';
}

function rivetBuildHostJobDir(array $job, bool $create = false): ?string
{
    if (preg_match('/^[0-9a-f]{32}\z/', (string) $job['quarantine']) !== 1) {
        return null;
    }
    $root = rivetBuildHostRoot();
    $dir = $root . '/' . $job['quarantine'];
    if ($create && !is_dir($dir)) {
        if (!is_dir($root) && !@mkdir($root, 0700, true) && !is_dir($root)) {
            return null;
        }
        if (!@mkdir($dir, 0700) && !is_dir($dir)) {
            return null;
        }
    }

    return is_dir($dir) ? $dir : null;
}

/** Remove a job's quarantine directory and the chunk rows. Only regular files with names this code made are removed. */
function rivetBuildHostPurge($mysqli, array $job): void
{
    $dir = rivetBuildHostJobDir($job);
    if ($dir !== null) {
        foreach (scandir($dir) ?: [] as $f) {
            if (preg_match('/^[0-9a-f]{32}(\.\d{1,4}\.part|\.bin)\z/', $f) === 1 && is_file($dir . '/' . $f) && !is_link($dir . '/' . $f)) {
                @unlink($dir . '/' . $f);
            }
        }
        @rmdir($dir);
    }
    $id = (int) $job['job_id'];
    mysqli_query($mysqli, "DELETE c FROM endpoint_agent_build_chunks c JOIN endpoint_agent_build_files f ON f.file_id = c.file_id WHERE f.job_id = $id");
}

/** Text from the host or a user: printable ASCII and newlines/tabs only (other characters become ?), capped, secrets of known shapes masked. */
function rivetBuildHostClean(string $s, int $max, bool $multiline = false): string
{
    $s = preg_replace('/\r\n?/', "\n", $s) ?? '';
    $s = preg_replace($multiline ? '/[^\x20-\x7e\n\t]/' : '/[^\x20-\x7e]/', '?', $s) ?? '';
    $s = preg_replace('/\b(?:rbh_[0-9a-f]{16}_[0-9a-f]{48}|github_pat_[A-Za-z0-9_]{20,}|gh[pousr]_[A-Za-z0-9]{20,})\b/', '[masked]', $s) ?? '';

    return $multiline ? substr($s, -$max) : substr($s, 0, $max);
}

function rivetBuildHostAudit(string $action, string $text): void
{
    logAction('Endpoint Agent', $action, rivetBuildHostClean($text, 400));
}

// ------------------------------------------------------------------ hosts and tokens

/** @return array{token:string,prefix:string,hash:string,salt:string} */
function rivetBuildHostMakeToken(): array
{
    $prefix = bin2hex(random_bytes(8));
    $secret = bin2hex(random_bytes(24));
    $salt = bin2hex(random_bytes(16));

    return ['token' => 'rbh_' . $prefix . '_' . $secret, 'prefix' => $prefix, 'salt' => $salt, 'hash' => hash('sha256', $salt . $secret)];
}

/** @return array{ok:bool,message:string,token?:string,host_id?:int} the token is in the answer once and nowhere else */
function rivetBuildHostCreate($mysqli, string $name, int $userId): array
{
    $name = trim($name);
    if ($name === '' || strlen($name) > 80 || preg_match('/^[\w .:@()\/+-]{1,80}\z/', $name) !== 1) {
        return ['ok' => false, 'message' => 'Give the build host a short name (letters, numbers, spaces and . - _ : @).'];
    }
    $n = mysqli_query($mysqli, 'SELECT COUNT(*) FROM endpoint_agent_build_hosts');
    if ($n && (int) mysqli_fetch_row($n)[0] >= 25) {
        return ['ok' => false, 'message' => 'At most 25 build hosts. Delete one you no longer use.'];
    }
    $t = rivetBuildHostMakeToken();
    $e = static fn (string $s): string => mysqli_real_escape_string($mysqli, $s);
    mysqli_query($mysqli, "INSERT INTO endpoint_agent_build_hosts (name, token_prefix, token_hash, token_salt, enabled, created_by, created_at) VALUES ('" . $e($name) . "', '{$t['prefix']}', '{$t['hash']}', '{$t['salt']}', 1, " . (int) $userId . ', UTC_TIMESTAMP())');
    $id = (int) mysqli_insert_id($mysqli);
    if ($id <= 0) {
        return ['ok' => false, 'message' => 'The build host could not be saved.'];
    }

    return ['ok' => true, 'message' => 'Build host added. Copy the token now: it is shown only once.', 'token' => $t['token'], 'host_id' => $id];
}

function rivetBuildHostRotate($mysqli, int $hostId): ?string
{
    $t = rivetBuildHostMakeToken();
    mysqli_query($mysqli, "UPDATE endpoint_agent_build_hosts SET token_prefix = '{$t['prefix']}', token_hash = '{$t['hash']}', token_salt = '{$t['salt']}', token_rotated_at = UTC_TIMESTAMP() WHERE host_id = " . (int) $hostId);

    return mysqli_affected_rows($mysqli) === 1 ? $t['token'] : null;
}

function rivetBuildHostSetEnabled($mysqli, int $hostId, bool $on): bool
{
    mysqli_query($mysqli, 'UPDATE endpoint_agent_build_hosts SET enabled = ' . ($on ? 1 : 0) . ' WHERE host_id = ' . (int) $hostId);
    if (!$on) {   // a disabled host's open jobs are cancelled: it can no longer report on them
        foreach (rivetBuildHostJobs($mysqli, ['host_id' => $hostId, 'open' => true]) as $j) {
            rivetBuildHostCancel($mysqli, (int) $j['job_id'], 'The build host was disabled.');
        }
    }

    return mysqli_affected_rows($mysqli) >= 0;
}

function rivetBuildHostDelete($mysqli, int $hostId): void
{
    foreach (rivetBuildHostJobs($mysqli, ['host_id' => $hostId, 'limit' => 500]) as $j) {
        rivetBuildHostCancel($mysqli, (int) $j['job_id'], 'The build host was deleted.');
        rivetBuildHostPurge($mysqli, $j);
        mysqli_query($mysqli, 'DELETE FROM endpoint_agent_build_files WHERE job_id = ' . (int) $j['job_id']);
        mysqli_query($mysqli, 'DELETE FROM endpoint_agent_build_jobs WHERE job_id = ' . (int) $j['job_id']);
    }
    mysqli_query($mysqli, 'DELETE FROM endpoint_agent_build_hosts WHERE host_id = ' . (int) $hostId);
}

/** @return list<array<string,mixed>> */
function rivetBuildHostList($mysqli): array
{
    $out = [];
    $q = mysqli_query($mysqli, 'SELECT h.host_id, h.name, h.enabled, h.last_seen_at, h.last_ip, h.host_version, h.capabilities, h.created_at, h.token_rotated_at,
        (SELECT COUNT(*) FROM endpoint_agent_build_jobs j WHERE j.host_id = h.host_id AND j.state IN (\'queued\',\'claimed\',\'building\',\'uploading\',\'verifying\')) AS open_jobs
        FROM endpoint_agent_build_hosts h ORDER BY h.name, h.host_id');
    while ($q && ($r = mysqli_fetch_assoc($q))) {
        $out[] = $r;
    }

    return $out;
}

function rivetBuildHostGet($mysqli, int $hostId): ?array
{
    $q = mysqli_query($mysqli, 'SELECT * FROM endpoint_agent_build_hosts WHERE host_id = ' . (int) $hostId);

    return ($q && ($r = mysqli_fetch_assoc($q))) ? $r : null;
}

/** online (seen in the last 3 minutes) | idle (seen within a day) | offline | never | disabled */
function rivetBuildHostStatus(array $h): string
{
    if (!(int) $h['enabled']) {
        return 'disabled';
    }
    if (!is_string($h['last_seen_at'] ?? null)) {
        return 'never';
    }
    $age = time() - (int) strtotime($h['last_seen_at'] . ' UTC');

    return $age <= 180 ? 'online' : ($age <= 86400 ? 'idle' : 'offline');
}

/**
 * Authenticate an Authorization header. Returns the host row, or null (unknown, malformed, wrong secret, rotated, disabled). No distinction is given to the caller.
 *
 * @return array<string,mixed>|null
 */
function rivetBuildHostAuth($mysqli, string $authHeader, ?string &$why = null): ?array
{
    $why = 'malformed';
    $token = preg_match('/^Bearer ([^\s]{1,200})\z/i', trim($authHeader), $m) === 1 ? $m[1] : '';
    $ok = preg_match(RIVET_BH_TOKEN_RE, $token, $p) === 1;
    $row = null;
    if ($ok) {
        $why = 'unknown';
        $q = mysqli_query($mysqli, "SELECT * FROM endpoint_agent_build_hosts WHERE token_prefix = '" . mysqli_real_escape_string($mysqli, $p[1]) . "' LIMIT 1");
        $row = ($q && ($r = mysqli_fetch_assoc($q))) ? $r : null;
    }
    // the same work whether or not a row exists
    $salt = $row !== null ? (string) $row['token_salt'] : str_repeat('0', 32);
    $want = $row !== null ? (string) $row['token_hash'] : str_repeat('0', 64);
    $got = hash('sha256', $salt . ($ok ? $p[2] : str_repeat('0', 48)));
    $match = hash_equals($want, $got);
    if ($row === null || !$ok || !$match) {
        $why = $row === null ? $why : 'bad_secret';

        return null;
    }
    if (!(int) $row['enabled']) {
        $why = 'disabled';

        return null;
    }
    $why = '';

    return $row;
}

// ------------------------------------------------------------------ versions and requests

function rivetBuildHostKnownVersions($mysqli): array
{
    $v = [];
    foreach (['endpoint_agent_binaries', 'endpoint_agent_msi_templates'] as $t) {
        $q = mysqli_query($mysqli, "SELECT DISTINCT version FROM $t");
        while ($q && ($r = mysqli_fetch_row($q))) {
            $v[(string) $r[0]] = true;
        }
    }
    if (function_exists('rivetAgentFeedCached') && function_exists('rivetAgentFeedReady') && rivetAgentFeedReady($mysqli)) {
        foreach (rivetAgentFeedCached($mysqli)['releases'] as $r) {
            $v[(string) $r['version']] = true;
        }
    }

    return array_keys($v);
}

/** @return string|null the reason a version cannot be built (same rules as GitHub builds) */
function rivetBuildHostCheckVersion($mysqli, string $v): ?string
{
    if (preg_match(RIVET_BH_VERSION_RE, $v) !== 1) {
        return 'The version must look like 0.9.1 (three numbers), optionally with a suffix such as 0.9.1-rc1.';
    }
    $known = rivetBuildHostKnownVersions($mysqli);
    foreach ($known as $k) {
        if (rivetAgentFeedVersionCmp($v, $k) === 0) {
            return 'Version ' . $v . ' already exists. Choose a higher number.';
        }
    }
    $latest = null;
    foreach ($known as $k) {
        if ($latest === null || rivetAgentFeedVersionCmp($k, $latest) > 0) {
            $latest = $k;
        }
    }
    if ($latest !== null && rivetAgentFeedVersionCmp($v, $latest) < 0) {
        return 'Version ' . $v . ' is lower than the latest version ' . $latest . '. Agents only update to a higher number.';
    }
    $q = mysqli_query($mysqli, "SELECT COUNT(*) FROM endpoint_agent_build_jobs WHERE version = '" . mysqli_real_escape_string($mysqli, $v) . "' AND state IN ('queued','claimed','building','uploading','verifying','built')");
    if ($q && (int) mysqli_fetch_row($q)[0] > 0) {
        return 'A build of version ' . $v . ' is already waiting or running.';
    }
    if (function_exists('rivetAgentBuildRow') && function_exists('rivetAgentBuildIsActive') && rivetAgentBuildReady($mysqli)) {
        $row = rivetAgentBuildRow($mysqli);
        if (rivetAgentBuildIsActive($row) && rivetAgentFeedVersionCmp((string) $row['build_version'], $v) === 0) {
            return 'A build of version ' . $v . ' is already running on GitHub.';
        }
    }

    return null;
}

function rivetBuildHostNextVersion($mysqli): string
{
    $latest = null;
    foreach (rivetBuildHostKnownVersions($mysqli) as $k) {
        if (preg_match(RIVET_BH_VERSION_RE, $k) === 1 && ($latest === null || rivetAgentFeedVersionCmp($k, $latest) > 0)) {
            $latest = $k;
        }
    }
    if ($latest === null || preg_match(RIVET_BH_VERSION_RE, $latest, $m) !== 1) {
        return '0.1.0';
    }

    return $m[1] . '.' . $m[2] . '.' . ((int) $m[3] + 1);
}

/**
 * Queue a build for one host.
 *
 * @param array{ring:?string,pct:int,activate:bool,offer:bool}|null $autoOpt publish when built with these choices (null: stop at "built", an administrator presses Publish)
 * @return array{ok:bool,message:string,job_id?:int}
 */
function rivetBuildHostRequest($mysqli, int $hostId, string $version, string $ref, ?array $autoOpt, int $userId, ?array $choices = null): array
{
    if (!rivetBuildHostReady($mysqli)) {
        return ['ok' => false, 'message' => 'Run the database update first (Administration > Updates).'];
    }
    $host = rivetBuildHostGet($mysqli, $hostId);
    if ($host === null || !(int) $host['enabled']) {
        return ['ok' => false, 'message' => 'Choose an enabled build host.'];
    }
    $version = trim($version);
    $ref = trim($ref) === '' ? 'main' : trim($ref);
    if (preg_match(RIVET_BH_REF_RE, $ref) !== 1 || str_contains($ref, '..') || str_contains($ref, '//') || str_ends_with($ref, '/') || str_ends_with($ref, '.lock')) {
        return ['ok' => false, 'message' => 'The source reference must be a branch or tag name such as main or agent-v0.9.1.'];
    }
    rivetBuildHostSweep($mysqli);
    $open = mysqli_query($mysqli, 'SELECT COUNT(*) FROM endpoint_agent_build_jobs WHERE host_id = ' . $hostId . " AND state IN ('queued','claimed','building','uploading','verifying')");
    if ($open && (int) mysqli_fetch_row($open)[0] > 0) {
        return ['ok' => false, 'message' => 'This build host already has a build waiting or running. Wait for it or cancel it.'];
    }
    $err = rivetBuildHostCheckVersion($mysqli, $version);
    if ($err !== null) {
        return ['ok' => false, 'message' => $err];
    }
    $c = $choices ?? $autoOpt ?? ['ring' => null, 'pct' => 100, 'activate' => false, 'offer' => false];
    $ring = in_array($c['ring'] ?? null, ['pilot', 'stable'], true) ? $c['ring'] : '';
    mysqli_query($mysqli, "INSERT INTO endpoint_agent_build_jobs (host_id, version, source_ref, provider, auto_publish, release_ring, rollout_pct, `activate`, offer, state, quarantine, requested_by, created_at)
        VALUES ($hostId, '" . mysqli_real_escape_string($mysqli, $version) . "', '" . mysqli_real_escape_string($mysqli, $ref) . "', 'host', " . ($autoOpt !== null ? 1 : 0) . ", '$ring', " . max(0, min(100, (int) ($c['pct'] ?? 100)))
        . ', ' . (!empty($c['activate']) ? 1 : 0) . ', ' . (!empty($c['offer']) ? 1 : 0) . ", 'queued', '" . bin2hex(random_bytes(16)) . "', " . (int) $userId . ', UTC_TIMESTAMP())');
    $id = (int) mysqli_insert_id($mysqli);

    return $id > 0 ? ['ok' => true, 'message' => 'Version ' . $version . ' is queued for ' . $host['name'] . '. The build host picks it up on its next poll (within a minute).', 'job_id' => $id]
        : ['ok' => false, 'message' => 'The build could not be queued.'];
}

// ------------------------------------------------------------------ jobs

/**
 * @param array{host_id?:int,open?:bool,job_id?:int,limit?:int} $f
 * @return list<array<string,mixed>>
 */
function rivetBuildHostJobs($mysqli, array $f = []): array
{
    $w = ['1=1'];
    if (isset($f['host_id'])) {
        $w[] = 'j.host_id = ' . (int) $f['host_id'];
    }
    if (isset($f['job_id'])) {
        $w[] = 'j.job_id = ' . (int) $f['job_id'];
    }
    if (!empty($f['open'])) {
        $w[] = "j.state IN ('queued','claimed','building','uploading','verifying')";
    }
    $out = [];
    $q = mysqli_query($mysqli, 'SELECT j.*, h.name AS host_name FROM endpoint_agent_build_jobs j LEFT JOIN endpoint_agent_build_hosts h ON h.host_id = j.host_id WHERE ' . implode(' AND ', $w)
        . ' ORDER BY j.job_id DESC LIMIT ' . max(1, min(500, (int) ($f['limit'] ?? 20))));
    while ($q && ($r = mysqli_fetch_assoc($q))) {
        $out[] = $r;
    }

    return $out;
}

function rivetBuildHostSetState($mysqli, int $jobId, string $from, string $to, string $note = ''): bool
{
    mysqli_query($mysqli, "UPDATE endpoint_agent_build_jobs SET state = '$to'" . ($note !== '' ? ", note = '" . mysqli_real_escape_string($mysqli, rivetBuildHostClean($note, 500)) . "'" : '')
        . ", finished_at = " . (in_array($to, ['published', 'failed', 'expired', 'cancelled'], true) ? 'UTC_TIMESTAMP()' : 'finished_at')
        . ' WHERE job_id = ' . $jobId . " AND state = '" . mysqli_real_escape_string($mysqli, $from) . "'");

    return mysqli_affected_rows($mysqli) === 1;
}

/** Lazy expiry (called by every endpoint, the card and cron): jobs whose lease ran out, queued jobs nobody claimed in an hour, verified builds nobody published in a day. */
function rivetBuildHostSweep($mysqli): int
{
    $n = 0;
    $q = mysqli_query($mysqli, "SELECT * FROM endpoint_agent_build_jobs WHERE
        (state IN ('claimed','building','uploading') AND lease_expires_at < UTC_TIMESTAMP())
        OR (state = 'queued' AND created_at < UTC_TIMESTAMP() - INTERVAL 3600 SECOND)
        OR (state = 'verifying' AND lease_expires_at < UTC_TIMESTAMP())
        OR (state = 'built' AND finished_at IS NULL AND last_status_at < UTC_TIMESTAMP() - INTERVAL " . RIVET_BH_KEEP_BUILT_S . ' SECOND) LIMIT 100');
    while ($q && ($j = mysqli_fetch_assoc($q))) {
        $to = $j['state'] === 'built' ? 'expired' : 'expired';
        $why = $j['state'] === 'queued' ? 'The build host did not pick the job up within 60 minutes.' : ($j['state'] === 'built' ? 'The verified build was not published within a day and was removed.' : 'The build host sent nothing for too long (the job expired).');
        if (rivetBuildHostSetState($mysqli, (int) $j['job_id'], (string) $j['state'], $to, $why)) {
            rivetBuildHostPurge($mysqli, $j);
            rivetBuildHostAudit('Agent Build Host Job Expired', 'Job #' . (int) $j['job_id'] . ' (version ' . $j['version'] . '): ' . $why);
            ++$n;
        }
    }

    return $n;
}

function rivetBuildHostCancel($mysqli, int $jobId, string $note = 'Cancelled by an administrator.'): bool
{
    $j = rivetBuildHostJobs($mysqli, ['job_id' => $jobId])[0] ?? null;
    if ($j === null || !in_array($j['state'], ['queued', 'claimed', 'building', 'uploading', 'verifying', 'built'], true)) {
        return false;
    }
    if (!rivetBuildHostSetState($mysqli, $jobId, (string) $j['state'], 'cancelled', $note)) {
        return false;
    }
    rivetBuildHostPurge($mysqli, $j);

    return true;
}

function rivetBuildHostJobPublic(array $j): array
{
    return ['id' => (int) $j['job_id'], 'version' => (string) $j['version'], 'source_ref' => (string) $j['source_ref'], 'targets' => RIVET_BH_TARGETS, 'msi' => true,
        'expires_at' => gmdate('Y-m-d\TH:i:s\Z', (int) strtotime(((string) ($j['lease_expires_at'] ?? '')) !== '' ? $j['lease_expires_at'] . ' UTC' : 'now'))];
}

/** Largest allowed size of one executable or MSI. */
function rivetBuildHostMaxBytes(): int
{
    try {
        return max(1048576, (int) rivetRmmModule()->binaryStore()->maxBytes());
    } catch (\Throwable) {
        return 64 * 1048576;
    }
}

function rivetBuildHostFileCap(string $name): int
{
    return $name === 'SHA256SUMS' ? RIVET_BH_SUMS_MAX : rivetBuildHostMaxBytes();
}

// ------------------------------------------------------------------ the four endpoints (pure: array in, [http, array] out)

function rivetBuildHostTouch($mysqli, array $host, string $ip, ?array $body = null): void
{
    $cap = '';
    $ver = '';
    if (is_array($body)) {
        $ver = rivetBuildHostClean(is_string($body['host_version'] ?? null) ? $body['host_version'] : '', 40);
        $c = is_array($body['capabilities'] ?? null) ? $body['capabilities'] : [];
        $cap = array_key_exists('capabilities', $body) ? implode(',', array_keys(array_filter(['docker' => !empty($c['docker']), 'msi' => !empty($c['msi'])]))) : null;
    }
    mysqli_query($mysqli, "UPDATE endpoint_agent_build_hosts SET last_seen_at = UTC_TIMESTAMP(), last_ip = '" . mysqli_real_escape_string($mysqli, substr($ip, 0, 45)) . "'"
        . (is_array($body) ? ", host_version = '" . mysqli_real_escape_string($mysqli, $ver) . "'" . ($cap !== null ? ", capabilities = '" . mysqli_real_escape_string($mysqli, $cap) . "'" : '') : '') . ' WHERE host_id = ' . (int) $host['host_id']);
}

/** @return array{0:int,1:array<string,mixed>} */
function rivetBuildHostErr(int $http, string $code, string $msg, array $extra = []): array
{
    return [$http, ['error' => $msg, 'code' => $code] + $extra];
}

/** The job named in the body, if it is this host's. Another host's job and an unknown id look the same. */
function rivetBuildHostOwnJob($mysqli, array $host, $id): ?array
{
    if (!is_int($id) && !(is_string($id) && ctype_digit($id) && strlen($id) < 10)) {
        return null;
    }
    $j = rivetBuildHostJobs($mysqli, ['job_id' => (int) $id])[0] ?? null;

    return ($j !== null && (int) $j['host_id'] === (int) $host['host_id']) ? $j : null;
}

function rivetBuildHostPoll($mysqli, array $host, array $body, string $ip): array
{
    rivetBuildHostSweep($mysqli);
    rivetBuildHostTouch($mysqli, $host, $ip, $body);
    $caps = is_array($body['capabilities'] ?? null) ? $body['capabilities'] : [];
    if (empty($caps['docker']) || empty($caps['msi'])) {   // `--register` announces no capabilities (or false ones): records last seen and the script version, never takes a job
        return [200, ['job' => null]];
    }
    $hid = (int) $host['host_id'];
    // a job that was handed out but not yet acknowledged is handed out again (the answer may have been lost)
    $q = mysqli_query($mysqli, "SELECT * FROM endpoint_agent_build_jobs WHERE host_id = $hid AND state IN ('claimed','building','uploading','verifying') ORDER BY job_id LIMIT 1");
    if ($q && ($j = mysqli_fetch_assoc($q))) {
        return [200, ['job' => $j['state'] === 'claimed' ? rivetBuildHostJobPublic($j) : null]];
    }
    $q = mysqli_query($mysqli, "SELECT job_id FROM endpoint_agent_build_jobs WHERE host_id = $hid AND state = 'queued' ORDER BY job_id LIMIT 1");
    $r = $q ? mysqli_fetch_row($q) : null;
    if (!$r) {
        return [200, ['job' => null]];
    }
    mysqli_query($mysqli, "UPDATE endpoint_agent_build_jobs SET state = 'claimed', claimed_at = UTC_TIMESTAMP(), last_status_at = UTC_TIMESTAMP(), lease_expires_at = UTC_TIMESTAMP() + INTERVAL " . RIVET_BH_LEASE_CLAIM_S . ' SECOND WHERE job_id = ' . (int) $r[0] . " AND state = 'queued'");
    if (mysqli_affected_rows($mysqli) !== 1) {
        return [200, ['job' => null]];
    }
    $j = rivetBuildHostJobs($mysqli, ['job_id' => (int) $r[0]])[0];
    rivetBuildHostAudit('Agent Build Host Job Claimed', 'Build host "' . $host['name'] . '" took job #' . (int) $j['job_id'] . ' (version ' . $j['version'] . ', ref ' . $j['source_ref'] . ')');

    return [200, ['job' => rivetBuildHostJobPublic($j)]];
}

/**
 * Validates the optional progress fields of a status call: stage (allowlist), pct (integer 0-100), detail (at most 120 printable ASCII characters), elapsed_s (integer 0-172800).
 * Returns the cleaned values (only the keys that were sent) or an error message. Nothing the host sends is ever shown as-is: the page maps stage to its own words.
 *
 * @return array{stage?: string, pct?: int, detail?: string, elapsed_s?: int}|string
 */
function rivetBuildHostProgressFields(array $body): array|string
{
    $out = [];
    if (array_key_exists('stage', $body)) {
        if (!is_string($body['stage']) || !isset(RIVET_BH_STAGES[$body['stage']])) {
            return 'stage must be one of: ' . implode(', ', array_keys(RIVET_BH_STAGES)) . '.';
        }
        $out['stage'] = $body['stage'];
    }
    foreach (['pct' => 100, 'elapsed_s' => 172800] as $k => $max) {
        if (array_key_exists($k, $body)) {
            if (!is_int($body[$k]) || $body[$k] < 0 || $body[$k] > $max) {
                return $k . ' must be a whole number from 0 to ' . $max . '.';
            }
            $out[$k] = $body[$k];
        }
    }
    if (array_key_exists('detail', $body)) {
        if (!is_string($body['detail']) || strlen($body['detail']) > 120 || preg_match('/[^\x20-\x7e]/', $body['detail']) === 1) {
            return 'detail must be at most 120 printable characters.';
        }
        $out['detail'] = rivetBuildHostClean($body['detail'], 120);
    }

    return $out;
}

/** UPDATE fragment for the progress columns: stage and percent never move backwards for a job. Empty when nothing was sent. */
function rivetBuildHostProgressSet($mysqli, array $j, array $p): string
{
    if (!isset($p['stage']) && !isset($p['pct']) && !isset($p['detail'])) {
        return '';
    }
    $order = array_keys(RIVET_BH_STAGES);
    $set = ['progress_at = UTC_TIMESTAMP()'];
    $curIdx = array_search((string) ($j['progress_stage'] ?? ''), $order, true);
    $stageOk = !isset($p['stage']) || $curIdx === false || array_search($p['stage'], $order, true) >= $curIdx;
    if (isset($p['stage']) && $stageOk) {
        $set[] = "progress_stage = '" . mysqli_real_escape_string($mysqli, $p['stage']) . "'";
    }
    if (isset($p['pct'])) {
        $set[] = 'progress_pct = GREATEST(progress_pct, ' . (int) $p['pct'] . ')';
    }
    if (isset($p['detail']) && $stageOk) {
        $set[] = "progress_detail = '" . mysqli_real_escape_string($mysqli, $p['detail']) . "'";
    }

    return ', ' . implode(', ', $set);
}

/** A job that is no longer open for the host answers 409 with its state, so the script stops working on it. */
function rivetBuildHostGone(array $j): array
{
    return rivetBuildHostErr(409, 'job_' . $j['state'], 'This job is ' . $j['state'] . '. Stop working on it.', ['state' => (string) $j['state']]);
}

function rivetBuildHostStatusCall($mysqli, array $host, array $body, string $ip): array
{
    rivetBuildHostSweep($mysqli);
    rivetBuildHostTouch($mysqli, $host, $ip);
    $j = rivetBuildHostOwnJob($mysqli, $host, $body['job_id'] ?? null);
    if ($j === null) {
        return rivetBuildHostErr(404, 'not_found', 'No such job.');
    }
    $state = $body['state'] ?? '';
    if (!in_array($state, ['building', 'uploading', 'failed'], true)) {
        return rivetBuildHostErr(422, 'invalid', 'state must be building, uploading or failed.');
    }
    if (!in_array($j['state'], ['claimed', 'building', 'uploading'], true)) {
        return rivetBuildHostGone($j);
    }
    $note = rivetBuildHostClean(is_string($body['note'] ?? null) ? $body['note'] : '', 500);
    $prog = $state === 'failed' ? [] : rivetBuildHostProgressFields($body);   // progress is ignored for a terminal state
    if (is_string($prog)) {
        return rivetBuildHostErr(422, 'invalid', $prog);
    }
    $e = static fn (string $s): string => mysqli_real_escape_string($mysqli, $s);
    if ($state === 'failed') {
        $log = rivetBuildHostClean(is_string($body['log_tail'] ?? null) ? $body['log_tail'] : '', 4096, true);
        mysqli_query($mysqli, "UPDATE endpoint_agent_build_jobs SET state = 'failed', note = '" . $e($note) . "', log_tail = '" . $e($log) . "', finished_at = UTC_TIMESTAMP(), last_status_at = UTC_TIMESTAMP() WHERE job_id = " . (int) $j['job_id'] . " AND state IN ('claimed','building','uploading')");
        if (mysqli_affected_rows($mysqli) !== 1) {
            return rivetBuildHostGone(rivetBuildHostJobs($mysqli, ['job_id' => (int) $j['job_id']])[0]);
        }
        rivetBuildHostPurge($mysqli, $j);
        rivetBuildHostAudit('Agent Build Host Job Failed', 'Build host "' . $host['name'] . '" reported job #' . (int) $j['job_id'] . ' (version ' . $j['version'] . ') failed: ' . $note);

        return [200, ['ok' => true, 'state' => 'failed']];
    }
    mysqli_query($mysqli, "UPDATE endpoint_agent_build_jobs SET state = '$state', note = '" . $e($note) . "', last_status_at = UTC_TIMESTAMP(), lease_expires_at = UTC_TIMESTAMP() + INTERVAL " . RIVET_BH_LEASE_WORK_S . ' SECOND' . rivetBuildHostProgressSet($mysqli, $j, $prog) . ' WHERE job_id = ' . (int) $j['job_id'] . " AND state IN ('claimed','building','uploading')");
    if (mysqli_affected_rows($mysqli) !== 1 && rivetBuildHostJobs($mysqli, ['job_id' => (int) $j['job_id']])[0]['state'] !== $state) {
        return rivetBuildHostGone(rivetBuildHostJobs($mysqli, ['job_id' => (int) $j['job_id']])[0]);
    }

    return [200, ['ok' => true, 'state' => $state]];
}

function rivetBuildHostUpload($mysqli, array $host, array $body, string $ip): array
{
    rivetBuildHostSweep($mysqli);
    rivetBuildHostTouch($mysqli, $host, $ip);
    $j = rivetBuildHostOwnJob($mysqli, $host, $body['job_id'] ?? null);
    if ($j === null) {
        return rivetBuildHostErr(404, 'not_found', 'No such job.');
    }
    $name = $body['name'] ?? null;
    if (!is_string($name) || !in_array($name, RIVET_BH_FILES, true)) {
        return rivetBuildHostErr(422, 'bad_name', 'That file name is not allowed.');
    }
    $index = $body['index'] ?? null;
    $total = $body['total'] ?? null;
    $cap = rivetBuildHostFileCap($name);
    $maxChunks = (int) ceil($cap / RIVET_BH_CHUNK_MAX);
    if (!is_int($index) || !is_int($total) || $total < 1 || $total > $maxChunks || $index < 0 || $index >= $total) {
        return rivetBuildHostErr(422, 'bad_chunk', 'index and total must be whole numbers with 0 <= index < total <= ' . $maxChunks . '.');
    }
    $sha = $body['sha256_chunk'] ?? null;
    $b64 = $body['data_b64'] ?? null;
    if (!is_string($sha) || preg_match('/^[0-9a-f]{64}\z/', strtolower($sha)) !== 1 || !is_string($b64) || $b64 === '' || strlen($b64) > 1398200 || preg_match('/^[A-Za-z0-9+\/]*={0,2}\z/', $b64) !== 1) {
        return rivetBuildHostErr(422, 'bad_chunk', 'sha256_chunk and data_b64 are required and must be well formed.');
    }
    $raw = base64_decode($b64, true);
    if ($raw === false || $raw === '' || strlen($raw) > RIVET_BH_CHUNK_MAX) {
        return rivetBuildHostErr(413, 'chunk_too_large', 'A chunk is at most 1 MiB of raw data.');
    }
    if (!hash_equals(hash('sha256', $raw), strtolower($sha))) {
        rivetBuildHostAudit('Agent Build Host Chunk Refused', 'Job #' . (int) $j['job_id'] . ': chunk ' . $index . ' of ' . $name . ' does not match its sha256_chunk');

        return rivetBuildHostErr(422, 'bad_hash', 'The chunk does not match its sha256_chunk.');
    }
    if (!in_array($j['state'], ['claimed', 'building', 'uploading'], true)) {
        return rivetBuildHostGone($j);
    }
    $jid = (int) $j['job_id'];
    // the file row: created by the first chunk; later chunks must agree on the total
    $f = null;
    $q = mysqli_query($mysqli, "SELECT * FROM endpoint_agent_build_files WHERE job_id = $jid AND name = '" . mysqli_real_escape_string($mysqli, $name) . "'");
    $f = ($q && ($r = mysqli_fetch_assoc($q))) ? $r : null;
    if ($f === null) {
        $n = mysqli_query($mysqli, "SELECT COUNT(*), COALESCE(SUM(bytes),0) FROM endpoint_agent_build_files WHERE job_id = $jid");
        [$cnt, $sum] = $n ? array_map('intval', mysqli_fetch_row($n)) : [0, 0];
        if ($cnt >= count(RIVET_BH_FILES)) {
            return rivetBuildHostErr(422, 'too_many_files', 'Too many files for one job.');
        }
        if (rivetBuildHostDirCreate($j) === null) {
            return rivetBuildHostErr(500, 'storage', 'The server could not prepare its quarantine area.');
        }
        mysqli_query($mysqli, "INSERT IGNORE INTO endpoint_agent_build_files (job_id, name, storage_name, chunk_total, created_at) VALUES ($jid, '" . mysqli_real_escape_string($mysqli, $name) . "', '" . bin2hex(random_bytes(16)) . "', $total, UTC_TIMESTAMP())");
        $q = mysqli_query($mysqli, "SELECT * FROM endpoint_agent_build_files WHERE job_id = $jid AND name = '" . mysqli_real_escape_string($mysqli, $name) . "'");
        $f = ($q && ($r = mysqli_fetch_assoc($q))) ? $r : null;
        if ($f === null) {
            return rivetBuildHostErr(500, 'storage', 'The upload could not be recorded.');
        }
    }
    if ((int) $f['chunk_total'] !== $total) {
        return rivetBuildHostErr(409, 'total_mismatch', 'total differs from the first chunk of this file.');
    }
    $fid = (int) $f['file_id'];
    $q = mysqli_query($mysqli, "SELECT sha256 FROM endpoint_agent_build_chunks WHERE file_id = $fid AND chunk_index = $index");
    $have = $q ? mysqli_fetch_row($q) : null;
    if ($have) {   // a replayed chunk: the same bytes are accepted and ignored, different bytes are refused
        return hash_equals((string) $have[0], strtolower($sha)) ? [200, ['ok' => true, 'replay' => true, 'received' => (int) rivetBuildHostCount($mysqli, $fid)]]
            : rivetBuildHostErr(409, 'chunk_conflict', 'That chunk was already received with different contents.');
    }
    $n = mysqli_query($mysqli, "SELECT COALESCE(SUM(bytes),0) FROM endpoint_agent_build_files WHERE job_id = $jid");
    $jobBytes = $n ? (int) mysqli_fetch_row($n)[0] : 0;
    $fileBytes = (int) $f['bytes'] + strlen($raw);
    if ($fileBytes > $cap || $jobBytes + strlen($raw) > 3 * rivetBuildHostMaxBytes() + RIVET_BH_SUMS_MAX) {
        rivetBuildHostAudit('Agent Build Host Chunk Refused', 'Job #' . $jid . ': ' . $name . ' is over its size limit');

        return rivetBuildHostErr(413, 'too_large', 'That file is larger than the limit of ' . $cap . ' bytes.');
    }
    $dir = rivetBuildHostJobDir($j);
    $path = $dir !== null ? $dir . '/' . $f['storage_name'] . '.' . $index . '.part' : null;
    if ($path === null || @file_put_contents($path, $raw, LOCK_EX) !== strlen($raw)) {
        return rivetBuildHostErr(500, 'storage', 'The chunk could not be stored.');
    }
    @chmod($path, 0600);
    mysqli_query($mysqli, "INSERT IGNORE INTO endpoint_agent_build_chunks (file_id, chunk_index, size, sha256) VALUES ($fid, $index, " . strlen($raw) . ", '" . strtolower($sha) . "')");
    if (mysqli_affected_rows($mysqli) !== 1) {   // lost a race with an identical replay
        return [200, ['ok' => true, 'replay' => true, 'received' => (int) rivetBuildHostCount($mysqli, $fid)]];
    }
    mysqli_query($mysqli, "UPDATE endpoint_agent_build_files SET bytes = bytes + " . strlen($raw) . " WHERE file_id = $fid");
    mysqli_query($mysqli, "UPDATE endpoint_agent_build_jobs SET state = 'uploading', last_status_at = UTC_TIMESTAMP(), lease_expires_at = UTC_TIMESTAMP() + INTERVAL " . RIVET_BH_LEASE_WORK_S . " SECOND WHERE job_id = $jid AND state IN ('claimed','building','uploading')");

    return [200, ['ok' => true, 'received' => (int) rivetBuildHostCount($mysqli, $fid), 'total' => $total]];
}

function rivetBuildHostDirCreate(array $j): ?string
{
    return rivetBuildHostJobDir($j, true);
}

function rivetBuildHostCount($mysqli, int $fileId): int
{
    $q = mysqli_query($mysqli, 'SELECT COUNT(*) FROM endpoint_agent_build_chunks WHERE file_id = ' . $fileId);

    return $q ? (int) mysqli_fetch_row($q)[0] : 0;
}

/** Does the file contain the version string (the agent is built with -X main.version=<v>, a plain string in the data section)? Streams the file. */
function rivetBuildHostHasVersion(string $path, string $version): bool
{
    $h = @fopen($path, 'rb');
    if (!$h) {
        return false;
    }
    $keep = strlen($version) - 1;
    $tail = '';
    $found = false;
    while (!feof($h)) {
        $buf = fread($h, 1048576);
        if ($buf === false) {
            break;
        }
        $hay = $tail . $buf;
        $pos = strpos($hay, $version);
        while ($pos !== false) {   // not part of a longer version (0.9.1 inside 0.9.10 or 0.9.1-rc1 when 0.9.1 was asked)
            $next = $hay[$pos + strlen($version)] ?? '';
            $prev = $pos > 0 ? $hay[$pos - 1] : '';
            if (!preg_match('/[0-9A-Za-z.\-]/', $next) && !preg_match('/[0-9.]/', $prev)) {
                $found = true;
                break 2;
            }
            $pos = strpos($hay, $version, $pos + 1);
        }
        $tail = $keep > 0 ? substr($hay, -$keep) : '';
    }
    fclose($h);

    return $found;
}

/** Put one file's chunks together into <storage>.bin. @return string|null an error text */
function rivetBuildHostAssemble($mysqli, array $j, array $f, ?string &$outPath): ?string
{
    $dir = rivetBuildHostJobDir($j);
    if ($dir === null) {
        return 'The upload area is gone.';
    }
    $fid = (int) $f['file_id'];
    $total = (int) $f['chunk_total'];
    $q = mysqli_query($mysqli, "SELECT chunk_index, size, sha256 FROM endpoint_agent_build_chunks WHERE file_id = $fid ORDER BY chunk_index");
    $rows = [];
    while ($q && ($r = mysqli_fetch_assoc($q))) {
        $rows[(int) $r['chunk_index']] = $r;
    }
    if (count($rows) !== $total) {
        return $f['name'] . ' is incomplete (' . count($rows) . ' of ' . $total . ' chunks).';
    }
    $outPath = $dir . '/' . $f['storage_name'] . '.bin';
    $out = @fopen($outPath, 'wb');
    if (!$out) {
        return 'Could not assemble ' . $f['name'] . '.';
    }
    @chmod($outPath, 0600);
    $size = 0;
    for ($i = 0; $i < $total; ++$i) {
        $p = $dir . '/' . $f['storage_name'] . '.' . $i . '.part';
        $data = isset($rows[$i]) && is_file($p) && !is_link($p) ? file_get_contents($p) : false;
        if ($data === false || strlen($data) !== (int) $rows[$i]['size'] || !hash_equals((string) $rows[$i]['sha256'], hash('sha256', $data))) {
            fclose($out);
            return 'Chunk ' . $i . ' of ' . $f['name'] . ' is missing or damaged.';
        }
        fwrite($out, $data);
        $size += strlen($data);
    }
    fclose($out);

    return $size > rivetBuildHostFileCap((string) $f['name']) ? $f['name'] . ' is too large.' : null;
}

/** Parse SHA256SUMS ("<64 hex>  <name>" lines) -> [name => hex], or an error string. @return array<string,string>|string */
function rivetBuildHostParseSums(string $text): array|string
{
    $out = [];
    foreach (preg_split('/\r?\n/', trim($text)) ?: [] as $line) {
        if ($line === '') {
            continue;
        }
        if (preg_match('/^([0-9a-fA-F]{64}) [ *]([A-Za-z0-9._-]{1,80})\z/', $line, $m) !== 1) {
            return 'SHA256SUMS has a line that is not "<sha256>  <file name>".';
        }
        if (isset($out[$m[2]]) && $out[$m[2]] !== strtolower($m[1])) {
            return 'SHA256SUMS lists ' . $m[2] . ' twice with different values.';
        }
        $out[$m[2]] = strtolower($m[1]);
    }

    return $out;
}

function rivetBuildHostFail($mysqli, array $host, array $j, string $msg, string $from = 'verifying'): array
{
    rivetBuildHostSetState($mysqli, (int) $j['job_id'], $from, 'failed', $msg);
    rivetBuildHostPurge($mysqli, $j);
    rivetBuildHostAudit('Agent Build Host Verify Failed', 'Job #' . (int) $j['job_id'] . ' (version ' . $j['version'] . ') from "' . $host['name'] . '": ' . $msg . ' Nothing was stored.');

    return rivetBuildHostErr(422, 'verify_failed', $msg, ['state' => 'failed']);
}

function rivetBuildHostComplete($mysqli, array $host, array $body, string $ip, ?\RivetCore\Rmm\RmmModule $rmm = null): array
{
    rivetBuildHostSweep($mysqli);
    rivetBuildHostTouch($mysqli, $host, $ip);
    $j = rivetBuildHostOwnJob($mysqli, $host, $body['job_id'] ?? null);
    if ($j === null) {
        return rivetBuildHostErr(404, 'not_found', 'No such job.');
    }
    if (!in_array($j['state'], ['claimed', 'building', 'uploading'], true)) {
        return rivetBuildHostGone($j);
    }
    $man = $body['manifest'] ?? null;
    if (!is_array($man) || !is_array($man['files'] ?? null) || count($man['files']) > 8) {
        return rivetBuildHostErr(422, 'invalid', 'manifest.files is required.');
    }
    // one completer at a time
    if (!rivetBuildHostSetState($mysqli, (int) $j['job_id'], (string) $j['state'], 'verifying', 'Verifying the upload.')) {
        return rivetBuildHostGone(rivetBuildHostJobs($mysqli, ['job_id' => (int) $j['job_id']])[0]);
    }
    mysqli_query($mysqli, "UPDATE endpoint_agent_build_jobs SET lease_expires_at = UTC_TIMESTAMP() + INTERVAL " . RIVET_BH_LEASE_CLAIM_S . ' SECOND WHERE job_id = ' . (int) $j['job_id']);
    $commit = rivetBuildHostClean(is_string($man['commit'] ?? null) ? $man['commit'] : '', 64);
    $goV = rivetBuildHostClean(is_string($man['go_version'] ?? null) ? $man['go_version'] : '', 40);
    $built = rivetBuildHostClean(is_string($man['built_at'] ?? null) ? $man['built_at'] : '', 40);
    if (preg_match('/^[0-9a-f]{7,64}\z/', $commit) !== 1) {
        return rivetBuildHostFail($mysqli, $host, $j, 'manifest.commit must be a git commit id.');
    }
    // the manifest names only allowed files, with well formed hashes
    $mf = [];
    foreach ($man['files'] as $n => $h) {
        if (!is_string($n) || !in_array($n, RIVET_BH_FILES, true) || !is_string($h) || preg_match('/^[0-9a-fA-F]{64}\z/', $h) !== 1) {
            return rivetBuildHostFail($mysqli, $host, $j, 'manifest.files has a name that is not allowed or a hash that is not a SHA-256.');
        }
        $mf[$n] = strtolower($h);
    }
    $files = [];
    $q = mysqli_query($mysqli, 'SELECT * FROM endpoint_agent_build_files WHERE job_id = ' . (int) $j['job_id']);
    while ($q && ($r = mysqli_fetch_assoc($q))) {
        $files[(string) $r['name']] = $r;
    }
    $need = ['rivetit-agent-windows-amd64.exe', 'rivetit-agent-windows-arm64.exe', 'rivetit-agent-windows-amd64.msi', 'SHA256SUMS'];
    $paths = [];
    $hash = [];
    foreach ($need as $n) {
        if (!isset($files[$n])) {
            return rivetBuildHostFail($mysqli, $host, $j, $n . ' was not uploaded.');
        }
        $err = rivetBuildHostAssemble($mysqli, $j, $files[$n], $paths[$n]);
        if ($err !== null) {
            return rivetBuildHostFail($mysqli, $host, $j, $err);
        }
        $hash[$n] = (string) hash_file('sha256', $paths[$n]);
    }
    $sums = rivetBuildHostParseSums((string) file_get_contents($paths['SHA256SUMS']));
    if (is_string($sums)) {
        return rivetBuildHostFail($mysqli, $host, $j, $sums);
    }
    foreach ($need as $n) {
        if ($n === 'SHA256SUMS') {
            continue;
        }
        if (!isset($mf[$n]) || !hash_equals($hash[$n], $mf[$n])) {
            return rivetBuildHostFail($mysqli, $host, $j, $n . ' does not match the SHA-256 in the manifest.');
        }
        if (!isset($sums[$n]) || !hash_equals($hash[$n], $sums[$n])) {
            return rivetBuildHostFail($mysqli, $host, $j, $n . ' does not match the SHA-256 in SHA256SUMS.');
        }
    }
    foreach ($mf as $n => $h) {   // SHA256SUMS itself may be in the manifest
        if ($n === 'SHA256SUMS' && !hash_equals($hash[$n], $h)) {
            return rivetBuildHostFail($mysqli, $host, $j, 'SHA256SUMS does not match the SHA-256 in the manifest.');
        }
    }
    // the existing validators (PE, architecture, size) run in a dry check so nothing is stored before every file is known good
    $v = rivetBuildHostValidate($paths, $rmm);
    if ($v !== null) {
        return rivetBuildHostFail($mysqli, $host, $j, $v);
    }
    foreach (['rivetit-agent-windows-amd64.exe', 'rivetit-agent-windows-arm64.exe'] as $n) {
        if (!rivetBuildHostHasVersion($paths[$n], (string) $j['version'])) {
            return rivetBuildHostFail($mysqli, $host, $j, $n . ' does not contain the version ' . $j['version'] . ' (it was built with a different version).');
        }
    }
    $man2 = ['files' => $hash, 'commit' => $commit, 'go_version' => $goV, 'built_at' => $built];
    mysqli_query($mysqli, "UPDATE endpoint_agent_build_jobs SET manifest = '" . mysqli_real_escape_string($mysqli, (string) json_encode($man2)) . "', commit_sha = '" . mysqli_real_escape_string($mysqli, $commit) . "' WHERE job_id = " . (int) $j['job_id']);
    if ((int) $j['auto_publish'] !== 1) {
        rivetBuildHostSetState($mysqli, (int) $j['job_id'], 'verifying', 'built', 'Built and verified. Waiting for an administrator to press Publish.');
        mysqli_query($mysqli, 'UPDATE endpoint_agent_build_jobs SET last_status_at = UTC_TIMESTAMP() WHERE job_id = ' . (int) $j['job_id']);
        rivetBuildHostAudit('Agent Build Host Job Built', 'Job #' . (int) $j['job_id'] . ' (version ' . $j['version'] . ', commit ' . $commit . ') from "' . $host['name'] . '" is built and verified; waiting for an administrator to publish');

        return [200, ['ok' => true, 'state' => 'built']];
    }
    $r = rivetBuildHostPublish($mysqli, $rmm ?? rivetRmmModule($mysqli), (int) $j['job_id'], null);
    if (!$r['ok']) {
        return rivetBuildHostErr($r['busy'] ?? false ? 503 : 422, 'publish_failed', $r['message'], ['state' => $r['busy'] ?? false ? 'verifying' : 'failed']);
    }

    return [200, ['ok' => true, 'state' => 'published']];
}

/** Run the existing validators on the three files without storing: a scratch copy of the module's checks. @return string|null an error */
function rivetBuildHostValidate(array $paths, ?\RivetCore\Rmm\RmmModule $rmm): ?string
{
    $max = rivetBuildHostMaxBytes();
    foreach (['rivetit-agent-windows-amd64.exe' => 'amd64', 'rivetit-agent-windows-arm64.exe' => 'arm64'] as $n => $arch) {
        $r = \RivetCore\Rmm\Binaries\BinaryInspector::inspect($paths[$n], $arch, $max);
        if (is_string($r)) {
            return $n . ': ' . $r;
        }
    }
    $m = \RivetCore\Rmm\Binaries\MsiInspector::inspect($paths['rivetit-agent-windows-amd64.msi'], 'amd64', $max);
    if (is_string($m)) {
        return 'rivetit-agent-windows-amd64.msi: ' . $m;
    }

    return null;
}

/**
 * Publish a verified job (state verifying or built) through the existing RmmAdmin code, as the user who asked for the build (or $by, when an administrator presses Publish).
 *
 * @param array{id:int,name:string}|null $by
 * @return array{ok:bool,message:string,busy?:bool}
 */
function rivetBuildHostPublish($mysqli, \RivetCore\Rmm\RmmModule $rmm, int $jobId, ?array $by): array
{
    $j = rivetBuildHostJobs($mysqli, ['job_id' => $jobId])[0] ?? null;
    if ($j === null || !in_array($j['state'], ['verifying', 'built'], true)) {
        return ['ok' => false, 'message' => 'That build cannot be published.'];
    }
    $lk = mysqli_query($mysqli, "SELECT GET_LOCK('" . (defined('RIVET_AGENT_FEED_LOCK') ? RIVET_AGENT_FEED_LOCK : 'rivet_agent_feed') . "', 0)");
    if (!$lk || (int) mysqli_fetch_row($lk)[0] !== 1) {
        return ['ok' => false, 'busy' => true, 'message' => 'Another publish is running. Try again in a minute.'];
    }
    try {
        // claim it: verifying/built -> verifying, once
        if ($j['state'] === 'built' && !rivetBuildHostSetState($mysqli, $jobId, 'built', 'verifying', 'Publishing.')) {
            return ['ok' => false, 'message' => 'Another request is publishing it.'];
        }
        $uid = $by['id'] ?? (int) $j['requested_by'];
        $un = $by['name'] ?? '';
        if ($un === '') {
            $u = $uid > 0 ? mysqli_query($mysqli, 'SELECT user_name FROM users WHERE user_id = ' . (int) $uid . ' AND user_archived_at IS NULL LIMIT 1') : false;
            $ur = $u ? mysqli_fetch_row($u) : null;
            $un = $ur ? (string) $ur[0] : '';
        }
        if ($un === '') {
            rivetBuildHostSetState($mysqli, $jobId, 'verifying', 'failed', 'The user who asked for the build no longer exists, so nothing was published.');
            rivetBuildHostPurge($mysqli, $j);

            return ['ok' => false, 'message' => 'The user who asked for the build no longer exists, so nothing was published.'];
        }
        $who = rivetRmmPrincipal($uid, $un);
        if (!$rmm->authorizer()->allowed($who->userId, \RivetCore\Rmm\Authz\RmmAbility::BINARY_PUBLISH, 0)) {
            $msg = 'Administrator access is required to publish.';
            rivetBuildHostSetState($mysqli, $jobId, 'verifying', 'built', $msg . ' The verified files are kept; an administrator can press Publish.');

            return ['ok' => false, 'message' => $msg];
        }
        $o = (int) $j['auto_publish'] === 1 || $by !== null
            ? ['ring' => in_array($j['release_ring'], ['pilot', 'stable'], true) ? $j['release_ring'] : null, 'pct' => (int) $j['rollout_pct'], 'activate' => (bool) (int) $j['activate'], 'offer' => (bool) (int) $j['offer']] : null;
        $o ??= ['ring' => null, 'pct' => 100, 'activate' => false, 'offer' => false];
        $dir = rivetBuildHostJobDir($j);
        $paths = [];
        $q = mysqli_query($mysqli, 'SELECT name, storage_name FROM endpoint_agent_build_files WHERE job_id = ' . $jobId);
        while ($q && ($r = mysqli_fetch_assoc($q))) {
            $paths[(string) $r['name']] = $dir . '/' . $r['storage_name'] . '.bin';
        }
        foreach (RIVET_BH_FILES as $n) {
            if ($dir === null || !isset($paths[$n]) || !is_file($paths[$n])) {
                rivetBuildHostSetState($mysqli, $jobId, 'verifying', 'failed', 'The verified files are no longer on this server.');

                return ['ok' => false, 'message' => 'The verified files are no longer on this server.'];
            }
        }
        $admin = $rmm->admin();
        $notes = [];
        $bad = null;
        $notesText = 'Built on build host ' . ($j['host_name'] ?? '') . ' from ' . $j['source_ref'] . ' ' . substr((string) $j['commit_sha'], 0, 12);
        foreach (['rivetit-agent-windows-amd64.exe' => 'amd64', 'rivetit-agent-windows-arm64.exe' => 'arm64'] as $n => $arch) {
            $r = $admin->uploadBinary($who, $paths[$n], (string) $j['version'], $arch, ['activate' => $o['activate'], 'release_ring' => $o['offer'] ? $o['ring'] : null, 'rollout_pct' => $o['pct'], 'notes' => $notesText]);
            if (!$r->ok) {
                $bad = $n . ': ' . $r->message;
                break;
            }
            $notes[] = $n;
        }
        if ($bad === null) {
            $r = $admin->uploadMsiTemplate($who, $paths['rivetit-agent-windows-amd64.msi'], (string) $j['version'], 'amd64', ['activate' => $o['activate']]);
            if (!$r->ok) {
                $bad = 'rivetit-agent-windows-amd64.msi: ' . $r->message;
            }
        }
        if ($bad !== null) {
            rivetBuildHostSetState($mysqli, $jobId, 'verifying', 'failed', 'Publishing failed: ' . $bad);
            rivetBuildHostPurge($mysqli, $j);
            rivetBuildHostAudit('Agent Build Host Publish Failed', "$un: job #$jobId (version {$j['version']}): " . $bad);

            return ['ok' => false, 'message' => 'Publishing failed: ' . $bad];
        }
        rivetBuildHostSetState($mysqli, $jobId, 'verifying', 'published', 'Published.' . ($o['offer'] && $o['ring'] !== null ? ' Offered to the ' . $o['ring'] . ' ring at ' . $o['pct'] . '%.' : ''));
        rivetBuildHostPurge($mysqli, $j);
        rivetBuildHostAudit('Agent Build Host Published', "$un published version {$j['version']} built on \"" . ($j['host_name'] ?? '') . "\" (commit " . substr((string) $j['commit_sha'], 0, 12) . ', verified SHA-256 of every file)'
            . ($o['activate'] ? ', now used for new installers' : '') . ($o['offer'] && $o['ring'] !== null ? ', offered to the ' . $o['ring'] . ' ring at ' . $o['pct'] . '%' : ''));

        return ['ok' => true, 'message' => 'Version ' . $j['version'] . ' is published.'];
    } finally {
        mysqli_query($mysqli, "SELECT RELEASE_LOCK('" . (defined('RIVET_AGENT_FEED_LOCK') ? RIVET_AGENT_FEED_LOCK : 'rivet_agent_feed') . "')");
    }
}
