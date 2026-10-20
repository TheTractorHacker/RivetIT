<?php

/*
 * Agent updates feed (RivetIT 26.10.52): list the endpoint agent releases of the public GitHub repository (tags agent-v*), and publish one of them to THIS server from the
 * Administration page, without a shell. Nothing here decides who may publish: the stored files go through RivetCore\Rmm\Admin\RmmAdmin::uploadBinary() /
 * uploadMsiTemplate() (the same validation, size window, SHA-256, audit entry and `rmm.binary.publish` check as the upload forms and scripts/endpoint_agent_publish.php).
 * This file is (1) a small, locked-down HTTPS client, (2) the cache of the release list, (3) the checksum check that runs BEFORE anything is stored, and (4) the record of the
 * Linux assets that the Add device page turns into a one-line install command.
 *
 * SAFETY (read before changing anything)
 *  - Only https://api.github.com (the list) and https://github.com (the assets) are ever contacted. A download may be redirected, but only to the GitHub release-asset CDN hosts
 *    below, only over https on port 443, at most 5 times, never with credentials. Every hop is checked again, and its host name is resolved here and PINNED for the request
 *    (CURLOPT_RESOLVE), so a name that resolves to a private, loopback or reserved address is refused and cannot be swapped between the check and the connection.
 *  - The asset URL is BUILT from the configured repository, the tag and the asset name (each matched against a strict pattern); the URL inside the API answer is never used.
 *  - Size caps (list 2 MiB, checksum files 256 KiB, Windows files the module's binary cap, the script 256 KiB), a connect timeout, a total timeout and a low-speed cut-off.
 *  - A Windows file is stored only if its SHA-256 equals the line in the release's SHA256SUMS / SHA256SUMS.msi (and the API's digest when GitHub gives one). A mismatch, a
 *    missing line or a size that differs from the release listing stores nothing and is audited.
 *  - For the automated tests ONLY the API and asset origin can be replaced by a loopback http server: it needs BOTH the install constant that exists for loopback test servers
 *    (EA_ALLOW_INSECURE_HTTP === true, set by the scratch config.php and never in production) and RIVET_AGENT_FEED_TEST_ORIGIN in the environment.
 */

require_once __DIR__ . '/rmm_bootstrap.php';
require_once __DIR__ . '/rivet_time.php';

/** The public repository that publishes the agent. An administrator may point the page at a fork. */
const RIVET_AGENT_FEED_DEFAULT_REPO = 'TheTractorHacker/rivet-core';
/** agent-v0.9.1, agent-v0.10.0-rc1 (the version part is what RmmProtocol::BINARY_VERSION_RE accepts, without build metadata). */
const RIVET_AGENT_FEED_TAG_RE = '/^agent-v(\d{1,5}\.\d{1,5}\.\d{1,5}(?:-[0-9A-Za-z.-]{1,20})?)\z/';
const RIVET_AGENT_FEED_REPO_RE = '/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})\/[A-Za-z0-9][A-Za-z0-9._-]{0,99}\z/';   // the name starts with a letter or digit: no ".." in the address
const RIVET_AGENT_FEED_ASSET_RE = '/^[A-Za-z0-9][A-Za-z0-9._+-]{0,149}\z/';
/** The list is refreshed at most this often unless "Check for updates" is pressed (which is itself limited, see RIVET_AGENT_FEED_MIN_FORCE_S). */
const RIVET_AGENT_FEED_TTL_S = 900;
const RIVET_AGENT_FEED_MIN_FORCE_S = 20;
const RIVET_AGENT_FEED_MAX_LIST_BYTES = 2097152;
const RIVET_AGENT_FEED_MAX_SUMS_BYTES = 262144;
const RIVET_AGENT_FEED_MAX_SCRIPT_BYTES = 262144;
const RIVET_AGENT_FEED_MAX_PAGES = 3;
const RIVET_AGENT_FEED_MAX_REDIRECTS = 5;
const RIVET_AGENT_FEED_LOCK = 'rivetit_agent_feed_publish';

/** Hosts the asset download may be redirected to (GitHub's release-asset storage). The first request is always github.com. */
const RIVET_AGENT_FEED_CDN_HOSTS = ['github.com', 'objects.githubusercontent.com', 'release-assets.githubusercontent.com', 'github-releases.githubusercontent.com'];

// ------------------------------------------------------------------ the locked-down HTTPS client

/**
 * The loopback origin of the mock GitHub server of the automated tests, or null. Needs both the install constant and the environment variable (see the header).
 *
 * @return array{scheme:string,host:string,port:int,origin:string}|null
 */
function rivetAgentFeedTestOrigin(): ?array
{
    if (!defined('EA_ALLOW_INSECURE_HTTP') || EA_ALLOW_INSECURE_HTTP !== true) {
        return null;
    }
    $o = getenv('RIVET_AGENT_FEED_TEST_ORIGIN');
    if (!is_string($o) || $o === '') {
        return null;
    }
    $p = parse_url($o);
    if (!is_array($p) || ($p['scheme'] ?? '') !== 'http' || !in_array($p['host'] ?? '', ['127.0.0.1', 'localhost'], true) || !isset($p['port'])) {
        return null;
    }

    return ['scheme' => 'http', 'host' => (string) $p['host'], 'port' => (int) $p['port'], 'origin' => 'http://' . $p['host'] . ':' . (int) $p['port']];
}

/** The origin the list and the first asset request go to: GitHub, or the test server. */
function rivetAgentFeedApiBase(): string
{
    $t = rivetAgentFeedTestOrigin();

    return $t !== null ? $t['origin'] : 'https://api.github.com';
}

function rivetAgentFeedAssetBase(): string
{
    $t = rivetAgentFeedTestOrigin();

    return $t !== null ? $t['origin'] : 'https://github.com';
}

/**
 * May this URL be requested? $first is the very first request of a download (github.com only); later hops may also be the CDN hosts. Returns the parts the client needs, or an error text.
 *
 * @return array{scheme:string,host:string,port:int,path:string,test:bool}|string
 */
function rivetAgentFeedCheckUrl(string $url, string $role, bool $first)
{
    if ($url === '' || strlen($url) > 2000 || preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
        return 'The address contains characters that are not allowed.';
    }
    $p = parse_url($url);
    if (!is_array($p) || !isset($p['scheme'], $p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['fragment'])) {
        return 'The address is not a plain URL.';
    }
    $scheme = strtolower((string) $p['scheme']);
    $host = strtolower(rtrim((string) $p['host'], '.'));
    $port = isset($p['port']) ? (int) $p['port'] : ($scheme === 'https' ? 443 : 80);
    $path = ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
    $test = rivetAgentFeedTestOrigin();
    if ($test !== null && $scheme === 'http' && $host === $test['host'] && $port === $test['port']) {
        return ['scheme' => 'http', 'host' => $host, 'port' => $port, 'path' => $path, 'test' => true];
    }
    if ($scheme !== 'https' || $port !== 443) {
        return 'Only https on port 443 is allowed.';
    }
    $allowed = $role === 'api' ? ['api.github.com'] : ($first ? ['github.com'] : RIVET_AGENT_FEED_CDN_HOSTS);
    if (!in_array($host, $allowed, true)) {
        return 'The host ' . substr(preg_replace('/[^A-Za-z0-9.-]/', '?', $host), 0, 60) . ' is not an allowed GitHub host.';
    }

    return ['scheme' => 'https', 'host' => $host, 'port' => 443, 'path' => $path, 'test' => false];
}

/**
 * The public addresses of a host name, or an error text. A private, loopback, link-local or reserved address (or a name with none) is refused.
 *
 * @return list<string>|string
 */
function rivetAgentFeedResolve(string $host)
{
    $ips = [];
    foreach (@dns_get_record($host, DNS_A) ?: [] as $r) {
        if (isset($r['ip'])) {
            $ips[] = (string) $r['ip'];
        }
    }
    foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $r) {
        if (isset($r['ipv6'])) {
            $ips[] = (string) $r['ipv6'];
        }
    }
    if ($ips === []) {   // some resolvers only answer through the libc path
        $ips = array_values(array_filter((array) @gethostbynamel($host)));
    }
    if ($ips === []) {
        return 'Could not resolve ' . $host . ' (is this server offline?).';
    }
    foreach ($ips as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return $host . ' resolves to a private or reserved address; refused.';
        }
    }

    return array_slice($ips, 0, 4);
}

/**
 * One GET with the safety rules of the file header.
 *
 * $o: role api|asset, max_bytes (hard cap), to_file (write the body there instead of memory), timeout (s), etag (If-None-Match), accept.
 *
 * @param array<string,mixed> $o
 * @return array{ok:bool,status:int,body:?string,bytes:int,headers:array<string,string>,error:string,offline:bool,final_host:string}
 */
function rivetAgentFeedGet(string $url, array $o = []): array
{
    $role = (string) ($o['role'] ?? 'asset');
    // 26.10.55 (Build a new version): an API call may carry a GitHub token and a small JSON body (the workflow dispatch is a POST). The token goes ONLY to the api role, on the very first
    // request, and an answer that tries to redirect it is refused: the token is never sent to another host, and never shown or logged.
    $token = isset($o['token']) && is_string($o['token']) && $o['token'] !== '' && $role === 'api' ? $o['token'] : null;
    $post = ($o['method'] ?? 'GET') === 'POST' && $role === 'api' && $token !== null ? json_encode($o['json'] ?? new \stdClass(), JSON_UNESCAPED_SLASHES) : null;
    $max = max(1, (int) ($o['max_bytes'] ?? RIVET_AGENT_FEED_MAX_LIST_BYTES));
    $file = isset($o['to_file']) ? (string) $o['to_file'] : null;
    $out = ['ok' => false, 'status' => 0, 'body' => null, 'bytes' => 0, 'headers' => [], 'error' => '', 'offline' => false, 'final_host' => ''];
    if (!function_exists('curl_init')) {
        $out['error'] = 'The PHP curl extension is not installed.';

        return $out;
    }
    for ($hop = 0; $hop <= RIVET_AGENT_FEED_MAX_REDIRECTS; $hop++) {
        $chk = rivetAgentFeedCheckUrl($url, $role, $hop === 0);
        if (is_string($chk)) {
            $out['error'] = ($hop > 0 ? 'Redirect refused: ' : '') . $chk;
            if ($file !== null) {
                @unlink($file);   // the first hop may already have created it
            }

            return $out;
        }
        $out['final_host'] = $chk['host'];
        $resolve = [];
        if (!$chk['test']) {
            $ips = rivetAgentFeedResolve($chk['host']);
            if (is_string($ips)) {
                $out['error'] = $ips;
                $out['offline'] = str_starts_with($ips, 'Could not resolve');
                if ($file !== null) {
                    @unlink($file);
                }

                return $out;
            }
            $resolve = [$chk['host'] . ':443:' . implode(',', array_map(static fn (string $ip): string => str_contains($ip, ':') ? '[' . $ip . ']' : $ip, $ips))];
        }
        $headers = [];
        $buf = '';
        $bytes = 0;
        $tooBig = false;
        $fh = null;
        if ($file !== null) {
            $fh = @fopen($file, 'wb');
            if ($fh === false) {
                $out['error'] = 'Could not write the temporary file.';

                return $out;
            }
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            ($post !== null ? CURLOPT_POST : CURLOPT_HTTPGET) => true,
            CURLOPT_FOLLOWLOCATION => false,   // every redirect is checked by hand
            CURLOPT_PROTOCOLS => $chk['test'] ? (CURLPROTO_HTTP | CURLPROTO_HTTPS) : CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => max(5, (int) ($o['timeout'] ?? 30)),
            CURLOPT_LOW_SPEED_LIMIT => 10240,
            CURLOPT_LOW_SPEED_TIME => 30,
            CURLOPT_SSL_VERIFYPEER => !$chk['test'],
            CURLOPT_SSL_VERIFYHOST => $chk['test'] ? 0 : 2,
            CURLOPT_USERAGENT => 'RivetIT-agent-feed/' . (defined('APP_VERSION') ? APP_VERSION : '1'),
            CURLOPT_ENCODING => $role === 'api' ? '' : 'identity',
            CURLOPT_HTTPHEADER => array_values(array_filter([
                'Accept: ' . (string) ($o['accept'] ?? ($role === 'api' ? 'application/vnd.github+json' : '*/*')),
                $role === 'api' ? 'X-GitHub-Api-Version: 2022-11-28' : null,
                ($hop === 0 && !empty($o['etag'])) ? 'If-None-Match: ' . $o['etag'] : null,
                ($hop === 0 && $token !== null) ? 'Authorization: Bearer ' . $token : null,
                $post !== null ? 'Content-Type: application/json' : null,
            ])),
            CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$headers, $max, &$tooBig): int {
                $len = strlen($line);
                if (strpos($line, ':') !== false) {
                    [$k, $v] = explode(':', $line, 2);
                    $k = strtolower(trim($k));
                    if (in_array($k, ['location', 'etag', 'content-length', 'x-ratelimit-remaining', 'x-ratelimit-reset', 'retry-after', 'link'], true)) {
                        $headers[$k] = substr(trim($v), 0, 2000);
                    }
                    if ($k === 'content-length' && ctype_digit(trim($v)) && (int) trim($v) > $max) {
                        $tooBig = true;
                        return 0;   // abort before the body
                    }
                }

                return $len;
            },
            CURLOPT_WRITEFUNCTION => static function ($c, string $data) use (&$buf, &$bytes, &$fh, $max, &$tooBig): int {
                $bytes += strlen($data);
                if ($bytes > $max) {
                    $tooBig = true;
                    return -1;
                }
                if ($fh !== null) {
                    return fwrite($fh, $data) === strlen($data) ? strlen($data) : -1;
                }
                $buf .= $data;

                return strlen($data);
            },
        ]);
        if ($resolve !== []) {
            curl_setopt($ch, CURLOPT_RESOLVE, $resolve);
        }
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        }
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($fh !== null) {
            fclose($fh);
        }
        $out['status'] = $status;
        $out['headers'] = $headers;
        if ($tooBig) {
            $out['error'] = 'The file is larger than the limit of ' . \RivetCore\Rmm\Binaries\BinaryStore::human($max) . '; refused.';
            if ($file !== null) {
                @unlink($file);
            }

            return $out;
        }
        if ($ok === false) {
            $out['error'] = 'The request failed: ' . substr(preg_replace('/[^\x20-\x7e]/', '?', $err), 0, 200);
            $out['offline'] = in_array($errno, [CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT, CURLE_OPERATION_TIMEDOUT, CURLE_GOT_NOTHING, CURLE_RECV_ERROR, CURLE_SEND_ERROR], true);
            if ($file !== null) {
                @unlink($file);
            }

            return $out;
        }
        if ($token !== null && in_array($status, [301, 302, 303, 307, 308], true)) {
            $out['error'] = 'GitHub answered with a redirect; refused (a call that carries the token is never redirected).';

            return $out;
        }
        if (in_array($status, [301, 302, 303, 307, 308], true)) {
            $loc = $headers['location'] ?? '';
            if ($loc === '' || $hop >= RIVET_AGENT_FEED_MAX_REDIRECTS) {
                $out['error'] = $loc === '' ? 'A redirect without a target.' : 'Too many redirects.';
                if ($file !== null) {
                    @unlink($file);
                }

                return $out;
            }
            // a relative Location is resolved against the current origin; the result is checked like any other address
            if ($loc[0] === '/' && !str_starts_with($loc, '//')) {
                $loc = $chk['scheme'] . '://' . $chk['host'] . ($chk['test'] ? ':' . $chk['port'] : '') . $loc;
            }
            $url = $loc;
            continue;
        }
        $out['bytes'] = $bytes;
        $out['body'] = $file === null ? $buf : null;
        $out['ok'] = $status >= 200 && $status < 300;
        if (!$out['ok'] && $status !== 304) {
            $out['error'] = 'GitHub answered HTTP ' . $status . '.';
            if ($file !== null) {
                @unlink($file);
            }
        }

        return $out;
    }
    $out['error'] = 'Too many redirects.';
    if ($file !== null) {
        @unlink($file);
    }

    return $out;
}

// ------------------------------------------------------------------ the stored list (cache) and the configured repository

/** Are the two tables of migration 2.6.175 there? A page that runs before the updater must still load. */
function rivetAgentFeedReady($mysqli = null): bool
{
    static $memo = [];
    $mysqli ??= $GLOBALS['mysqli'] ?? null;
    $key = $mysqli instanceof \mysqli ? spl_object_id($mysqli) : 0;
    if (!isset($memo[$key]) || $memo[$key] === false) {
        $ok = false;
        if ($mysqli instanceof \mysqli) {
            $a = mysqli_query($mysqli, "SHOW TABLES LIKE 'endpoint_agent_feed'");
            $b = mysqli_query($mysqli, "SHOW TABLES LIKE 'endpoint_agent_linux_assets'");
            $ok = $a && $b && mysqli_num_rows($a) > 0 && mysqli_num_rows($b) > 0;
        }
        $memo[$key] = $ok;
    }

    return $memo[$key];
}

/** @return array{repo:string,releases_json:?string,etag:string,fetched_at:?string,checked_at:?string,last_error:string,rate_reset_at:?string} */
function rivetAgentFeedRow($mysqli): array
{
    $row = null;
    if (rivetAgentFeedReady($mysqli)) {
        $r = mysqli_query($mysqli, 'SELECT * FROM endpoint_agent_feed WHERE id = 1');
        $row = $r ? mysqli_fetch_assoc($r) : null;
        if (!$row) {
            mysqli_query($mysqli, "INSERT IGNORE INTO endpoint_agent_feed (id, repo) VALUES (1, '" . mysqli_real_escape_string($mysqli, RIVET_AGENT_FEED_DEFAULT_REPO) . "')");
            $r = mysqli_query($mysqli, 'SELECT * FROM endpoint_agent_feed WHERE id = 1');
            $row = $r ? mysqli_fetch_assoc($r) : null;
        }
    }

    return [
        'repo' => is_array($row) && preg_match(RIVET_AGENT_FEED_REPO_RE, (string) $row['repo']) === 1 ? (string) $row['repo'] : RIVET_AGENT_FEED_DEFAULT_REPO,
        'releases_json' => $row['releases_json'] ?? null, 'etag' => (string) ($row['etag'] ?? ''), 'fetched_at' => $row['fetched_at'] ?? null,
        'checked_at' => $row['checked_at'] ?? null, 'last_error' => (string) ($row['last_error'] ?? ''), 'rate_reset_at' => $row['rate_reset_at'] ?? null,
    ];
}

function rivetAgentFeedRepo($mysqli): string
{
    return rivetAgentFeedRow($mysqli)['repo'];
}

/**
 * Point the page at another public repository (a fork). The cached list belongs to the old one and is dropped. @return string|null error text
 */
function rivetAgentFeedSetRepo($mysqli, string $repo): ?string
{
    $repo = trim($repo);
    if (preg_match(RIVET_AGENT_FEED_REPO_RE, $repo) !== 1) {
        return 'The repository must look like owner/name (letters, digits, dots, dashes and underscores).';
    }
    if (!rivetAgentFeedReady($mysqli)) {
        return 'Run the database update first (Administration > Updates).';
    }
    rivetAgentFeedRow($mysqli);
    $e = mysqli_real_escape_string($mysqli, $repo);
    mysqli_query($mysqli, "UPDATE endpoint_agent_feed SET repo = '$e', releases_json = NULL, etag = '', fetched_at = NULL, checked_at = NULL, last_error = '', rate_reset_at = NULL WHERE id = 1");

    return mysqli_errno($mysqli) === 0 ? null : 'The repository could not be saved.';
}

/** Order two agent versions (1.2.3 < 1.2.3 stable; a pre-release sorts before its release). */
function rivetAgentFeedVersionCmp(string $a, string $b): int
{
    $norm = static function (string $v): string {
        $v = ltrim($v, 'v');
        // "0.9.1-rc1" and "0.9.1-beta.1": PHP orders dev < alpha < beta < RC < stable when the suffix is separated by a dot
        return (string) preg_replace('/-(?=[A-Za-z])/', '.', preg_replace('/^(\d+\.\d+\.\d+)-(\d)/', '$1.$2', $v));
    };

    return version_compare($norm($a), $norm($b));
}

/**
 * Reduce one GitHub release object to what the page uses. Returns null for anything that is not a published agent release.
 *
 * @param mixed $r
 * @return array<string,mixed>|null
 */
function rivetAgentFeedNormalizeRelease($r): ?array
{
    if (!is_array($r) || !is_string($r['tag_name'] ?? null) || preg_match(RIVET_AGENT_FEED_TAG_RE, $r['tag_name'], $m) !== 1 || !empty($r['draft'])) {
        return null;
    }
    $assets = [];
    foreach (is_array($r['assets'] ?? null) ? array_slice($r['assets'], 0, 80) : [] as $a) {
        if (!is_array($a) || !is_string($a['name'] ?? null) || preg_match(RIVET_AGENT_FEED_ASSET_RE, $a['name']) !== 1) {
            continue;
        }
        $digest = is_string($a['digest'] ?? null) && preg_match('/^sha256:([0-9a-fA-F]{64})\z/', $a['digest'], $dm) === 1 ? strtolower($dm[1]) : null;
        $assets[] = ['name' => $a['name'], 'size' => max(0, (int) ($a['size'] ?? 0)), 'digest' => $digest];
    }
    $body = is_string($r['body'] ?? null) ? preg_replace('/[^\P{C}\n]/u', '', mb_substr($r['body'], 0, 4000)) : '';
    $when = is_string($r['published_at'] ?? null) && preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ\z/', $r['published_at']) === 1 ? $r['published_at'] : '';

    return ['tag' => $r['tag_name'], 'version' => $m[1], 'name' => mb_substr((string) preg_replace('/[^\P{C}]/u', '', (string) ($r['name'] ?? '')), 0, 120),
        'prerelease' => !empty($r['prerelease']), 'published_at' => $when, 'notes' => (string) $body, 'assets' => $assets];
}

/**
 * The cached agent releases, newest version first, and the state of the cache. Never touches the network.
 *
 * @return array{repo:string,releases:list<array<string,mixed>>,fetched_at:?string,checked_at:?string,error:string,rate_reset_at:?string,ready:bool}
 */
function rivetAgentFeedCached($mysqli): array
{
    $row = rivetAgentFeedRow($mysqli);
    $list = [];
    $j = is_string($row['releases_json']) ? json_decode($row['releases_json'], true) : null;
    foreach (is_array($j) ? $j : [] as $r) {
        $n = rivetAgentFeedNormalizeRelease(['tag_name' => $r['tag'] ?? '', 'prerelease' => $r['prerelease'] ?? false, 'published_at' => $r['published_at'] ?? '', 'name' => $r['name'] ?? '',
            'body' => $r['notes'] ?? '', 'assets' => array_map(static fn ($a) => is_array($a) ? ['name' => $a['name'] ?? '', 'size' => $a['size'] ?? 0, 'digest' => isset($a['digest']) ? 'sha256:' . $a['digest'] : null] : [], is_array($r['assets'] ?? null) ? $r['assets'] : [])]);
        if ($n !== null) {
            $list[] = $n;
        }
    }
    usort($list, static fn (array $a, array $b): int => rivetAgentFeedVersionCmp((string) $b['version'], (string) $a['version']));

    return ['repo' => $row['repo'], 'releases' => $list, 'fetched_at' => $row['fetched_at'], 'checked_at' => $row['checked_at'], 'error' => $row['last_error'], 'rate_reset_at' => $row['rate_reset_at'],
        'ready' => rivetAgentFeedReady($mysqli)];
}

/**
 * Ask GitHub for the release list (and cache it). Without $force a fresh enough cache is returned as it is. A failure keeps the old list and records why.
 *
 * @return array{ok:bool,message:string,from_cache:bool,changed:bool}
 */
function rivetAgentFeedRefresh($mysqli, bool $force = false): array
{
    if (!rivetAgentFeedReady($mysqli)) {
        return ['ok' => false, 'message' => 'Run the database update first (Administration > Updates).', 'from_cache' => true, 'changed' => false];
    }
    $row = rivetAgentFeedRow($mysqli);
    $now = time();
    $ts = static fn (?string $d): int => $d === null || $d === '' ? 0 : (int) strtotime($d . ' UTC');
    if ($ts($row['rate_reset_at']) > $now) {
        return ['ok' => false, 'message' => 'GitHub\'s rate limit for this server is used up; the list will be asked for again after ' . rivetFormatTime($ts($row['rate_reset_at']), 'epoch', true, 'H:i') . '. Showing the saved list.', 'from_cache' => true, 'changed' => false];
    }
    if (!$force && $ts($row['fetched_at']) > $now - RIVET_AGENT_FEED_TTL_S) {
        return ['ok' => true, 'message' => 'The list was checked a few minutes ago.', 'from_cache' => true, 'changed' => false];
    }
    if ($force && $ts($row['checked_at']) > $now - RIVET_AGENT_FEED_MIN_FORCE_S) {
        return ['ok' => true, 'message' => 'Checked a moment ago; wait a few seconds before checking again.', 'from_cache' => true, 'changed' => false];
    }
    $e = static fn (string $s): string => mysqli_real_escape_string($mysqli, $s);
    $repo = $row['repo'];
    $url = rivetAgentFeedApiBase() . '/repos/' . $repo . '/releases?per_page=100';
    $keep = [];
    $etag = '';
    $unchanged = false;
    for ($page = 1; $page <= RIVET_AGENT_FEED_MAX_PAGES && $url !== ''; $page++) {
        $res = rivetAgentFeedGet($url, ['role' => 'api', 'max_bytes' => RIVET_AGENT_FEED_MAX_LIST_BYTES, 'timeout' => 20, 'etag' => $page === 1 && $row['releases_json'] !== null ? $row['etag'] : '']);
        if ($page === 1 && $res['status'] === 304) {
            $unchanged = true;
            break;
        }
        if (!$res['ok']) {
            $why = $res['error'];
            $reset = null;
            if (in_array($res['status'], [403, 429], true) && (($res['headers']['x-ratelimit-remaining'] ?? '') === '0' || isset($res['headers']['retry-after']))) {
                $until = isset($res['headers']['x-ratelimit-reset']) && ctype_digit($res['headers']['x-ratelimit-reset']) ? (int) $res['headers']['x-ratelimit-reset']
                    : $now + (int) ($res['headers']['retry-after'] ?? 900);
                $until = max($now + 30, min($until, $now + 7200));
                $reset = gmdate('Y-m-d H:i:s', $until);
                $why = 'GitHub\'s rate limit for this server is used up (60 requests an hour without a key); try again after ' . rivetFormatTime($until, 'epoch', true, 'H:i') . '.';
            } elseif ($res['status'] === 404) {
                $why = 'GitHub has no repository ' . $repo . ' (or it is private). Check the name under "Where the releases come from".';
            } elseif ($res['offline']) {
                $why = 'This server could not reach GitHub (' . $res['error'] . ') Showing the saved list.';
            }
            mysqli_query($mysqli, "UPDATE endpoint_agent_feed SET checked_at = UTC_TIMESTAMP(), last_error = '" . $e(mb_substr($why, 0, 480)) . "', rate_reset_at = " . ($reset !== null ? "'$reset'" : 'NULL') . ' WHERE id = 1');

            return ['ok' => false, 'message' => $why, 'from_cache' => true, 'changed' => false];
        }
        $j = json_decode((string) $res['body'], true);
        if (!is_array($j) || (isset($j['message']) && !array_is_list($j))) {
            mysqli_query($mysqli, "UPDATE endpoint_agent_feed SET checked_at = UTC_TIMESTAMP(), last_error = 'GitHub sent an answer this page cannot read.' WHERE id = 1");

            return ['ok' => false, 'message' => 'GitHub sent an answer this page cannot read.', 'from_cache' => true, 'changed' => false];
        }
        if ($page === 1) {
            $etag = substr((string) ($res['headers']['etag'] ?? ''), 0, 190);
        }
        foreach ($j as $rel) {
            $n = rivetAgentFeedNormalizeRelease($rel);
            if ($n !== null) {
                $keep[$n['tag']] = $n;
            }
        }
        // follow Link: <...>; rel="next" only to the same API and the same repository
        $url = '';
        if (preg_match('/<([^>]+)>;\s*rel="next"/', (string) ($res['headers']['link'] ?? ''), $lm) === 1) {
            $next = $lm[1];
            $okNext = !is_string(rivetAgentFeedCheckUrl($next, 'api', true)) && str_starts_with((string) parse_url($next, PHP_URL_PATH), '/repos/' . $repo . '/releases');
            $url = $okNext ? $next : '';
        }
    }
    if ($unchanged) {
        mysqli_query($mysqli, 'UPDATE endpoint_agent_feed SET fetched_at = UTC_TIMESTAMP(), checked_at = UTC_TIMESTAMP(), last_error = \'\', rate_reset_at = NULL WHERE id = 1');

        return ['ok' => true, 'message' => 'Nothing new on GitHub.', 'from_cache' => false, 'changed' => false];
    }
    $json = json_encode(array_values($keep), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json) || strlen($json) > 1500000) {
        $json = json_encode(array_slice(array_values($keep), 0, 40), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
    }
    mysqli_query($mysqli, "UPDATE endpoint_agent_feed SET releases_json = '" . $e($json) . "', etag = '" . $e($etag) . "', fetched_at = UTC_TIMESTAMP(), checked_at = UTC_TIMESTAMP(), last_error = '', rate_reset_at = NULL WHERE id = 1");

    return ['ok' => true, 'message' => count($keep) . ' agent release' . (count($keep) === 1 ? '' : 's') . ' found on GitHub.', 'from_cache' => false, 'changed' => true];
}

// ------------------------------------------------------------------ what is already published here

/**
 * Asset name -> what it is. Anything else in a release (the sums files, unknown files) is ignored. $version is the release's version (deb and rpm names carry it).
 *
 * @return array{kind:string,arch:string,store:bool}|null
 */
function rivetAgentFeedClassify(string $name, string $version): ?array
{
    $v = preg_quote($version, '/');
    if (preg_match('/^rivetit-agent-windows-(amd64|arm64)\.exe\z/', $name, $m) === 1) {
        return ['kind' => 'exe', 'arch' => $m[1], 'store' => true];
    }
    if (preg_match('/^rivetit-agent-windows-(amd64|arm64)\.msi\z/', $name, $m) === 1) {
        return ['kind' => 'msi', 'arch' => $m[1], 'store' => true];
    }
    if ($name === 'install-linux.sh') {
        return ['kind' => 'script', 'arch' => '', 'store' => false];
    }
    if (preg_match('/^rivetit-agent-linux-(amd64|arm64)\z/', $name, $m) === 1) {
        return ['kind' => 'binary', 'arch' => $m[1], 'store' => false];
    }
    if (preg_match('/^rivetit-agent-linux-(amd64|arm64)\.tar\.gz\z/', $name, $m) === 1) {
        return ['kind' => 'tarball', 'arch' => $m[1], 'store' => false];
    }
    if (preg_match('/^rivetit-agent_' . $v . '_(amd64|arm64)\.deb\z/', $name, $m) === 1) {
        return ['kind' => 'deb', 'arch' => $m[1], 'store' => false];
    }
    if (preg_match('/^rivetit-agent-' . $v . '-[0-9A-Za-z.]{1,12}\.(x86_64|aarch64)\.rpm\z/', $name, $m) === 1) {
        return ['kind' => 'rpm', 'arch' => $m[1] === 'x86_64' ? 'amd64' : 'arm64', 'store' => false];
    }

    return null;
}

/**
 * The state of every release against this server: which Windows files are stored, which Linux assets are recorded, and how many devices run it.
 *
 * @param list<array<string,mixed>> $releases
 * @return array{rows:list<array<string,mixed>>,latest:?string,published_max:?string,unknown_devices:int}
 */
function rivetAgentFeedStatus($mysqli, array $releases): array
{
    $rmm = rivetRmmModule($mysqli);
    $read = $rmm->readModel();
    $bins = [];
    foreach ($read->binaries(200) as $b) {
        $bins[$b['version'] . '|' . $b['arch']] = $b;
    }
    $msis = [];
    foreach ($read->msiTemplates(200) as $t) {
        $msis[$t['version'] . '|' . $t['arch']] = $t;
    }
    $rel = [];
    foreach ($read->releases(200) as $r) {
        $rel[$r['version'] . '|' . $r['arch']][] = $r;
    }
    $linux = [];
    if (rivetAgentFeedReady($mysqli)) {
        $q = mysqli_query($mysqli, 'SELECT * FROM endpoint_agent_linux_assets WHERE active = 1');
        while ($q && ($row = mysqli_fetch_assoc($q))) {
            $linux[$row['version']][] = $row;
        }
    }
    // the script, agent and tarball this server hosts count as "have" for the version too (26.10.64)
    if (function_exists('rivetLxList') || is_file(__DIR__ . '/rmm_linux_store.php')) {
        require_once __DIR__ . '/rmm_linux_store.php';
        foreach (rivetLxList($mysqli) as $hf) {
            if ((int) $hf['active'] === 1) {
                $linux[$hf['version']][] = ['kind' => $hf['kind'], 'arch' => $hf['arch'], 'hosted' => 1];
            }
        }
    }
    $counts = rivetAgentFeedDeviceCounts($mysqli);
    $rows = [];
    foreach ($releases as $r) {
        $v = (string) $r['version'];
        $plan = rivetAgentFeedPlan($r);
        $have = ['exe' => [], 'msi' => [], 'linux' => 0];
        $want = ['exe' => 0, 'msi' => 0, 'linux' => 0];
        foreach ($plan as $s) {
            if ($s['kind'] === 'exe') {
                $want['exe']++;
                if (isset($bins[$v . '|' . $s['arch']]) && $bins[$v . '|' . $s['arch']]['active']) {
                    $have['exe'][] = $s['arch'];
                }
            } elseif ($s['kind'] === 'msi') {
                $want['msi']++;
                if (isset($msis[$v . '|' . $s['arch']]) && $msis[$v . '|' . $s['arch']]['active']) {
                    $have['msi'][] = $s['arch'];
                }
            } else {
                $want['linux']++;
            }
        }
        $lk = [];
        foreach ($linux[$v] ?? [] as $lrow) {
            $lk[$lrow['kind'] . '|' . $lrow['arch']] = 1;
        }
        $have['linux'] = count($lk);
        $stored = count($have['exe']) + count($have['msi']) + $have['linux'];
        $total = $want['exe'] + $want['msi'] + $want['linux'];
        $offered = [];
        foreach (['amd64', 'arm64'] as $a) {
            foreach ($rel[$v . '|' . $a] ?? [] as $x) {
                if ($x['active']) {
                    $offered[] = $x['ring'] . ' ' . $x['rollout_pct'] . '% (' . $a . ')';
                }
            }
        }
        $current = [];
        foreach (['amd64', 'arm64'] as $a) {
            if (isset($bins[$v . '|' . $a]) && $bins[$v . '|' . $a]['is_current'] && $bins[$v . '|' . $a]['active']) {
                $current[] = $a;
            }
        }
        $rows[] = ['release' => $r, 'plan' => $plan, 'have' => $have, 'want' => $want, 'state' => $total === 0 ? 'nothing' : ($stored === 0 ? 'new' : ($stored >= $total ? 'published' : 'partial')),
            'offered' => $offered, 'current' => $current, 'devices' => (int) ($counts['by_version'][$v] ?? 0)];
    }
    $latest = null;
    foreach ($releases as $r) {
        if ($latest === null || rivetAgentFeedVersionCmp((string) $r['version'], $latest) > 0) {
            $latest = (string) $r['version'];
        }
    }
    $pubMax = null;
    foreach ($bins as $b) {
        if ($b['active'] && ($pubMax === null || rivetAgentFeedVersionCmp((string) $b['version'], $pubMax) > 0)) {
            $pubMax = (string) $b['version'];
        }
    }

    return ['rows' => $rows, 'latest' => $latest, 'published_max' => $pubMax, 'unknown_devices' => $counts['other'], 'devices_total' => $counts['total']];
}

/**
 * Enrolled devices (not revoked, not retired) per reported agent version.
 *
 * @return array{by_version:array<string,int>,other:int,total:int}
 */
function rivetAgentFeedDeviceCounts($mysqli): array
{
    $out = ['by_version' => [], 'other' => 0, 'total' => 0];
    $q = @mysqli_query($mysqli, 'SELECT agent_version, COUNT(*) AS n FROM endpoint_agent_devices WHERE revoked_at IS NULL AND retired_at IS NULL GROUP BY agent_version');
    while ($q && ($r = mysqli_fetch_assoc($q))) {
        $n = (int) $r['n'];
        $out['total'] += $n;
        if (preg_match('/^v?(\d{1,5}\.\d{1,5}\.\d{1,5}(?:-[0-9A-Za-z.-]{1,20})?)/', (string) $r['agent_version'], $m) === 1) {
            $out['by_version'][$m[1]] = ($out['by_version'][$m[1]] ?? 0) + $n;
        } else {
            $out['other'] += $n;
        }
    }

    return $out;
}

/** True when a pilot ring exists: an enrolled device is on it, or an update is already offered to it. */
function rivetAgentFeedPilotExists($mysqli): bool
{
    $q = @mysqli_query($mysqli, "SELECT 1 FROM endpoint_agent_devices WHERE ring = 'pilot' AND revoked_at IS NULL AND retired_at IS NULL LIMIT 1");
    if ($q && mysqli_num_rows($q) > 0) {
        return true;
    }
    $q = @mysqli_query($mysqli, "SELECT 1 FROM endpoint_agent_releases WHERE ring = 'pilot' AND active = 1 LIMIT 1");

    return (bool) ($q && mysqli_num_rows($q) > 0);
}

// ------------------------------------------------------------------ publishing

/**
 * The files of a release this server knows what to do with.
 *
 * @param array<string,mixed> $release
 * @return list<array{asset:string,kind:string,arch:string,store:bool,size:int,digest:?string}>
 */
function rivetAgentFeedPlan(array $release): array
{
    $plan = [];
    foreach ($release['assets'] ?? [] as $a) {
        $c = rivetAgentFeedClassify((string) $a['name'], (string) $release['version']);
        if ($c !== null) {
            $plan[] = ['asset' => (string) $a['name'], 'kind' => $c['kind'], 'arch' => $c['arch'], 'store' => $c['store'], 'size' => (int) $a['size'], 'digest' => $a['digest'] ?? null];
        }
    }
    $order = ['exe' => 1, 'msi' => 2, 'script' => 3, 'binary' => 4, 'tarball' => 5, 'deb' => 6, 'rpm' => 7];
    usort($plan, static fn (array $x, array $y): int => [$order[$x['kind']], $x['arch']] <=> [$order[$y['kind']], $y['arch']]);

    return $plan;
}

/** @return array<string,mixed>|null the cached release for a tag, or null */
function rivetAgentFeedFindRelease($mysqli, string $tag): ?array
{
    if (preg_match(RIVET_AGENT_FEED_TAG_RE, $tag) !== 1) {
        return null;
    }
    foreach (rivetAgentFeedCached($mysqli)['releases'] as $r) {
        if ($r['tag'] === $tag) {
            return $r;
        }
    }

    return null;
}

/** The download URL of one asset, built from the configured repository, the tag and the (already pattern-checked) file name. */
function rivetAgentFeedAssetUrl(string $repo, string $tag, string $asset): string
{
    return rivetAgentFeedAssetBase() . '/' . $repo . '/releases/download/' . rawurlencode($tag) . '/' . rawurlencode($asset);
}

/**
 * Parse a SHA256SUMS file: "<64 hex>  name" or "<64 hex> *name". Anything else is ignored. @return array<string,string> name => lowercase hex
 */
function rivetAgentFeedParseSums(string $text): array
{
    $out = [];
    foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
        if (preg_match('/^([0-9a-fA-F]{64}) [ *]([A-Za-z0-9][A-Za-z0-9._+-]{0,149})\s*\z/', $line, $m) === 1) {
            $out[$m[2]] = strtolower($m[1]);
        }
    }

    return $out;
}

/** A private working directory (0700) that is removed by the caller. */
function rivetAgentFeedTempDir(): ?string
{
    $base = rtrim(sys_get_temp_dir(), '/');
    $dir = $base . '/rivet-agent-feed-' . bin2hex(random_bytes(8));

    return @mkdir($dir, 0700) ? $dir : null;
}

function rivetAgentFeedRemoveDir(?string $dir): void
{
    if ($dir === null || !is_dir($dir) || !str_contains($dir, 'rivet-agent-feed-')) {
        return;
    }
    foreach (glob($dir . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($dir);
}

/**
 * The checksums of a release: SHA256SUMS and SHA256SUMS.msi, merged. A name in both with different values is an error.
 *
 * @param array<string,mixed> $release
 * @return array{sums:array<string,string>,error:string}
 */
function rivetAgentFeedChecksums(string $repo, array $release): array
{
    $sums = [];
    $found = false;
    foreach ($release['assets'] ?? [] as $a) {
        if (!in_array($a['name'], ['SHA256SUMS', 'SHA256SUMS.msi'], true)) {
            continue;
        }
        $found = true;
        $res = rivetAgentFeedGet(rivetAgentFeedAssetUrl($repo, (string) $release['tag'], (string) $a['name']), ['role' => 'asset', 'max_bytes' => RIVET_AGENT_FEED_MAX_SUMS_BYTES, 'timeout' => 30]);
        if (!$res['ok']) {
            return ['sums' => [], 'error' => 'Could not download ' . $a['name'] . ': ' . $res['error']];
        }
        foreach (rivetAgentFeedParseSums((string) $res['body']) as $n => $h) {
            if (isset($sums[$n]) && $sums[$n] !== $h) {
                return ['sums' => [], 'error' => 'SHA256SUMS and SHA256SUMS.msi disagree about ' . $n . '; nothing was stored.'];
            }
            $sums[$n] = $h;
        }
    }
    if (!$found) {
        return ['sums' => [], 'error' => 'This release has no SHA256SUMS file, so nothing can be verified. Nothing was stored.'];
    }

    return ['sums' => $sums, 'error' => ''];
}

/**
 * Options of a publish: ring (pilot|stable|none), rollout_pct, activate (use for new installers), offer (offer to enrolled agents as an update).
 *
 * @param array<string,mixed> $in
 * @return array{ring:?string,pct:int,activate:bool,offer:bool}
 */
function rivetAgentFeedOptions(array $in): array
{
    $ring = is_string($in['ring'] ?? null) ? $in['ring'] : 'stable';
    $offer = !empty($in['offer']) && in_array($ring, ['pilot', 'stable'], true);
    $pct = is_numeric($in['rollout_pct'] ?? null) ? max(0, min(100, (int) $in['rollout_pct'])) : 100;

    return ['ring' => $offer ? $ring : null, 'pct' => $pct, 'activate' => !empty($in['activate']), 'offer' => $offer];
}

/**
 * Do ONE step of a publish: one asset of one release. The page calls this once per file so it can show progress; "publish all" calls it in a loop.
 *
 * Returns ['ok'=>bool,'status'=>stored|already|recorded|refused|failed,'message'=>text,'asset'=>name].
 *
 * @param array{ring:?string,pct:int,activate:bool,offer:bool} $opt
 * @return array{ok:bool,status:string,message:string,asset:string}
 */
function rivetAgentFeedStep($mysqli, \RivetCore\Rmm\RmmModule $rmm, \RivetCore\Rmm\Authz\RmmPrincipal $who, string $tag, string $asset, array $opt): array
{
    @set_time_limit(300);
    $res = static fn (bool $ok, string $status, string $msg) => ['ok' => $ok, 'status' => $status, 'message' => $msg, 'asset' => $asset];
    $release = rivetAgentFeedFindRelease($mysqli, $tag);
    if ($release === null) {
        return $res(false, 'refused', 'That release is not in the list. Press "Check for updates" first.');
    }
    $step = null;
    foreach (rivetAgentFeedPlan($release) as $s) {
        if ($s['asset'] === $asset) {
            $step = $s;
        }
    }
    if ($step === null) {
        return $res(false, 'refused', 'That file is not part of this release.');
    }
    if (!$rmm->authorizer()->allowed($who->userId, \RivetCore\Rmm\Authz\RmmAbility::BINARY_PUBLISH, 0)) {
        return $res(false, 'refused', 'Administrator access is required.');
    }
    $repo = rivetAgentFeedRepo($mysqli);
    $version = (string) $release['version'];
    $admin = $rmm->admin();
    $read = $rmm->readModel();
    $tmp = rivetAgentFeedTempDir();
    if ($tmp === null) {
        return $res(false, 'failed', 'Could not create a private temporary folder.');
    }
    try {
        $cs = rivetAgentFeedChecksums($repo, $release);
        if ($cs['error'] !== '') {
            logAction('Endpoint Agent', 'Agent Feed Publish Refused', "{$who->userName} could not publish $asset of $tag: " . $cs['error']);

            return $res(false, 'refused', $cs['error']);
        }
        $expect = $cs['sums'][$asset] ?? null;
        $api = $step['digest'];
        if ($expect !== null && $api !== null && !hash_equals($expect, $api)) {
            logAction('Endpoint Agent', 'Agent Feed Verify Failed', "{$who->userName}: $asset of $tag: SHA256SUMS ($expect) and GitHub's digest ($api) disagree; nothing stored");
            return $res(false, 'refused', 'The checksum in SHA256SUMS and the one GitHub shows for ' . $asset . ' do not agree. Nothing was stored.');
        }
        if ($expect === null) {
            if ($step['store'] || $api === null) {
                $why = 'There is no checksum for ' . $asset . ' in the release\'s SHA256SUMS, so it cannot be verified. Nothing was stored.';
                logAction('Endpoint Agent', 'Agent Feed Publish Refused', "{$who->userName}: $asset of $tag has no checksum; nothing stored");

                return $res(false, 'refused', $why);
            }
            $expect = $api;   // a Linux package that SHA256SUMS does not list: GitHub's own digest of the same file
            $source = 'github-digest';
        } else {
            $source = 'SHA256SUMS';
        }

        // ---- a Windows file: download, VERIFY, then hand it to the ordinary publish code
        if ($step['store']) {
            $arch = $step['arch'];
            $isMsi = $step['kind'] === 'msi';
            $existing = null;
            foreach ($isMsi ? $read->msiTemplates(200) : $read->binaries(200) as $row) {
                if ($row['version'] === $version && $row['arch'] === $arch) {
                    $existing = $row;
                }
            }
            if ($existing !== null && hash_equals((string) $existing['sha256'], $expect)) {
                // already stored, byte for byte: only the choices of this publish are applied
                $id = (int) ($isMsi ? $existing['template_id'] : $existing['binary_id']);
                if (!$existing['active']) {
                    $isMsi ? $admin->msiTemplateAction($who, $id, 'activate') : $admin->binaryAction($who, $id, 'activate');
                }
                $notes = [];
                if ($opt['activate']) {
                    $r = $isMsi ? $admin->msiTemplateAction($who, $id, 'make_current') : $admin->binaryAction($who, $id, 'make_current');
                    $notes[] = $r->ok ? 'now used for new installers' : $r->message;
                }
                if (!$isMsi && $opt['offer'] && $opt['ring'] !== null) {
                    $r = $admin->binaryAction($who, $id, 'offer_update', $opt['ring'], $opt['pct']);
                    $notes[] = $r->ok ? 'offered to the ' . $opt['ring'] . ' ring at ' . $opt['pct'] . '%' : $r->message;
                }

                return $res(true, 'already', $asset . ' was already stored here with the same checksum' . ($notes ? ' (' . implode('; ', $notes) . ')' : '') . '.');
            }
            $cap = min($rmm->binaryStore()->maxBytes(), $step['size'] > 0 ? $step['size'] : $rmm->binaryStore()->maxBytes());
            $file = $tmp . '/dl.bin';
            $dl = rivetAgentFeedGet(rivetAgentFeedAssetUrl($repo, $tag, $asset), ['role' => 'asset', 'max_bytes' => $cap, 'to_file' => $file, 'timeout' => 240]);
            if (!$dl['ok']) {
                return $res(false, 'failed', 'Could not download ' . $asset . ': ' . $dl['error']);
            }
            if ($step['size'] > 0 && $dl['bytes'] !== $step['size']) {
                @unlink($file);
                logAction('Endpoint Agent', 'Agent Feed Verify Failed', "{$who->userName}: $asset of $tag was {$dl['bytes']} bytes, the release lists {$step['size']}; nothing stored");

                return $res(false, 'refused', $asset . ' is ' . $dl['bytes'] . ' bytes but the release lists ' . $step['size'] . '. Nothing was stored.');
            }
            $got = hash_file('sha256', $file);
            if (!is_string($got) || !hash_equals($expect, $got)) {
                @unlink($file);
                logAction('Endpoint Agent', 'Agent Feed Verify Failed', "{$who->userName}: $asset of $tag has SHA-256 " . (string) $got . ", the release says $expect; nothing stored");

                return $res(false, 'refused', 'The download of ' . $asset . ' does not match its SHA-256 in the release (' . substr((string) $got, 0, 12) . '... against ' . substr($expect, 0, 12) . '...). Nothing was stored.');
            }
            $notes = 'From GitHub ' . $repo . ' ' . $tag;
            $r = $isMsi
                ? $admin->uploadMsiTemplate($who, $file, $version, $arch, ['activate' => $opt['activate']])
                : $admin->uploadBinary($who, $file, $version, $arch, ['activate' => $opt['activate'], 'release_ring' => $opt['offer'] ? $opt['ring'] : null, 'rollout_pct' => $opt['pct'], 'notes' => $notes]);
            if (!$r->ok) {
                return $res(false, 'refused', $r->message);
            }
            $extra = [];
            if ($opt['activate']) {
                $extra[] = $isMsi ? 'now the template for new MSI installers' : 'now used for new installers';
            }
            if (!$isMsi && $opt['offer'] && $opt['ring'] !== null) {
                $extra[] = 'offered to the ' . $opt['ring'] . ' ring at ' . $opt['pct'] . '%';
            }
            logAction('Endpoint Agent', 'Agent Feed Published', "{$who->userName} published $asset of $repo $tag (sha256 $expect, {$dl['bytes']} bytes, verified against the release checksums)" . ($extra ? ': ' . implode(', ', $extra) : ''));

            return $res(true, 'stored', $asset . ' verified (SHA-256 ' . substr($expect, 0, 12) . '...) and stored' . ($extra ? '; ' . implode('; ', $extra) : '') . '.');
        }

        // ---- the install script, the Linux agent and the .tar.gz: downloaded, VERIFIED against the release checksums, then HOSTED on this server (26.10.64), so the install
        // command downloads them from here. The .deb / .rpm stay links (they are verified by the package manager of the machine that installs them).
        if (in_array($step['kind'], ['script', 'binary', 'tarball'], true)) {
            require_once __DIR__ . '/rmm_linux_store.php';
            if (!rivetLxReady($mysqli)) {
                return $res(false, 'failed', 'Run the database update first (Administration > Updates).');
            }
            $hosted = rivetLxFind($mysqli, $asset, $version);
            $cap = $step['kind'] === 'script' ? RIVET_LX_SCRIPT_MAX : min(rivetLxMaxBytes($asset), $step['size'] > 0 ? $step['size'] : rivetLxMaxBytes($asset));
            $file = $tmp . '/lx.bin';
            if ($hosted !== null && hash_equals((string) $hosted['sha256'], $expect)) {
                $dl = ['ok' => true, 'bytes' => (int) $hosted['size_bytes']];   // already hosted byte for byte: only the choices of this publish apply
                $file = (string) rivetLxPath($hosted);
            } else {
                $dl = rivetAgentFeedGet(rivetAgentFeedAssetUrl($repo, $tag, $asset), ['role' => 'asset', 'max_bytes' => $cap, 'to_file' => $file, 'timeout' => 240]);
                if (!$dl['ok']) {
                    return $res(false, 'failed', 'Could not download ' . $asset . ': ' . $dl['error']);
                }
                if ($step['size'] > 0 && $dl['bytes'] !== $step['size']) {
                    @unlink($file);
                    logAction('Endpoint Agent', 'Agent Feed Verify Failed', "{$who->userName}: $asset of $tag was {$dl['bytes']} bytes, the release lists {$step['size']}; nothing stored");

                    return $res(false, 'refused', $asset . ' is ' . $dl['bytes'] . ' bytes but the release lists ' . $step['size'] . '. Nothing was stored.');
                }
                $got = hash_file('sha256', $file);
                if (!is_string($got) || !hash_equals($expect, $got)) {
                    @unlink($file);
                    logAction('Endpoint Agent', 'Agent Feed Verify Failed', "{$who->userName}: $asset of $tag has SHA-256 " . (string) $got . ", the release says $expect; nothing stored");

                    return $res(false, 'refused', 'The download of ' . $asset . ' does not match its SHA-256 in the release (' . substr((string) $got, 0, 12) . '... against ' . substr($expect, 0, 12) . '...). Nothing was stored.');
                }
            }
            $r = rivetLxStore($mysqli, $file, $asset, $version, (int) $who->userId, ['activate' => $opt['activate'], 'ring' => $opt['offer'] ? $opt['ring'] : null, 'pct' => $opt['pct'], 'source' => 'github',
                'expected_sha256' => $expect, 'notes' => 'From GitHub ' . $repo . ' ' . $tag]);
            if (!$r['ok']) {
                logAction('Endpoint Agent', 'Agent Feed Publish Refused', "{$who->userName}: $asset of $tag was refused by the Linux file checks: " . (string) ($r['error'] ?? ''));

                return $res(false, 'refused', (string) ($r['error'] ?? 'The file was refused.'));
            }
            logAction('Endpoint Agent', 'Agent Feed Linux File Hosted', "{$who->userName} published $asset of $repo $tag and this server now hosts it (sha256 $expect, {$dl['bytes']} bytes, verified against the release checksums)" . ($r['current'] ? ', now the one the Add device command uses' : ''));

            return $res(true, ($r['replay'] ?? false) ? 'already' : 'stored', $asset . ' verified (SHA-256 ' . substr($expect, 0, 12) . '...) and hosted on this server' . ($r['current'] ? '; now used for the Linux install command' : '; not the current one') . '.');
        }

        // ---- a Linux package (.deb / .rpm): recorded as a link
        $size = $step['size'];
        if ($step['kind'] === 'script') {
            $file = $tmp . '/script.sh';
            $dl = rivetAgentFeedGet(rivetAgentFeedAssetUrl($repo, $tag, $asset), ['role' => 'asset', 'max_bytes' => RIVET_AGENT_FEED_MAX_SCRIPT_BYTES, 'to_file' => $file, 'timeout' => 30]);
            if (!$dl['ok']) {
                return $res(false, 'failed', 'Could not download ' . $asset . ': ' . $dl['error']);
            }
            $got = hash_file('sha256', $file);
            if (!is_string($got) || !hash_equals($expect, $got)) {
                @unlink($file);
                logAction('Endpoint Agent', 'Agent Feed Verify Failed', "{$who->userName}: $asset of $tag has SHA-256 " . (string) $got . ", the release says $expect; nothing recorded");

                return $res(false, 'refused', 'install-linux.sh does not match its SHA-256 in the release. Nothing was recorded.');
            }
            $size = $dl['bytes'];
        }
        $url = rivetAgentFeedAssetUrl($repo, $tag, $asset);
        $e = static fn (string $s): string => mysqli_real_escape_string($mysqli, $s);
        if (!rivetAgentFeedReady($mysqli)) {
            return $res(false, 'failed', 'Run the database update first (Administration > Updates).');
        }
        $q = mysqli_query($mysqli, "SELECT asset_id, sha256 FROM endpoint_agent_linux_assets WHERE version = '" . $e($version) . "' AND kind = '" . $e($step['kind']) . "' AND arch = '" . $e($step['arch']) . "'");
        $old = $q ? mysqli_fetch_assoc($q) : null;
        if ($old && !hash_equals((string) $old['sha256'], $expect)) {
            return $res(false, 'refused', $asset . ' is already recorded with a different checksum. A released version never changes; use a new version.');
        }
        if (!$old) {
            mysqli_query($mysqli, "INSERT INTO endpoint_agent_linux_assets (version, kind, arch, filename, url, sha256, size_bytes, sha_source, active, is_current, created_by, created_at) VALUES ('" . $e($version) . "', '" . $e($step['kind']) . "', '"
                . $e($step['arch']) . "', '" . $e($asset) . "', '" . $e($url) . "', '$expect', " . (int) $size . ", '" . $e($source) . "', 1, 0, " . (int) $who->userId . ', UTC_TIMESTAMP())');
            $id = (int) mysqli_insert_id($mysqli);
        } else {
            $id = (int) $old['asset_id'];
            mysqli_query($mysqli, "UPDATE endpoint_agent_linux_assets SET active = 1, url = '" . $e($url) . "' WHERE asset_id = $id");
        }
        if ($opt['activate']) {
            mysqli_query($mysqli, "UPDATE endpoint_agent_linux_assets SET is_current = 0 WHERE kind = '" . $e($step['kind']) . "' AND arch = '" . $e($step['arch']) . "'");
            mysqli_query($mysqli, "UPDATE endpoint_agent_linux_assets SET is_current = 1 WHERE asset_id = $id");
        }
        logAction('Endpoint Agent', 'Agent Feed Linux Asset Recorded', "{$who->userName} recorded $asset of $repo $tag for the Linux installer (sha256 $expect from $source, $size bytes)" . ($opt['activate'] ? ', now the one the Add device page uses' : ''));

        return $res(true, 'recorded', $asset . ' recorded' . ($step['kind'] === 'script' ? ' and verified' : '') . ' (SHA-256 ' . substr($expect, 0, 12) . '...).' . ($opt['activate'] ? '' : ' Not used for installers.'));
    } finally {
        rivetAgentFeedRemoveDir($tmp);
    }
}

/**
 * Publish every file of a release (the no-JavaScript path; the page's script calls rivetAgentFeedStep() once per file instead). Serialised by a database lock.
 *
 * @param array{ring:?string,pct:int,activate:bool,offer:bool} $opt
 * @return array{ok:bool,steps:list<array{ok:bool,status:string,message:string,asset:string}>,message:string}
 */
function rivetAgentFeedPublishAll($mysqli, \RivetCore\Rmm\RmmModule $rmm, \RivetCore\Rmm\Authz\RmmPrincipal $who, string $tag, array $opt): array
{
    $release = rivetAgentFeedFindRelease($mysqli, $tag);
    if ($release === null) {
        return ['ok' => false, 'steps' => [], 'message' => 'That release is not in the list. Press "Check for updates" first.'];
    }
    @set_time_limit(600);
    $lk = mysqli_query($mysqli, "SELECT GET_LOCK('" . RIVET_AGENT_FEED_LOCK . "', 0)");
    if (!$lk || (int) mysqli_fetch_row($lk)[0] !== 1) {
        return ['ok' => false, 'steps' => [], 'message' => 'Another publish is running. Wait for it to finish.'];
    }
    $steps = [];
    try {
        foreach (rivetAgentFeedPlan($release) as $s) {
            $steps[] = rivetAgentFeedStep($mysqli, $rmm, $who, $tag, $s['asset'], $opt);
            if ($steps[count($steps) - 1]['status'] === 'refused' && str_contains($steps[count($steps) - 1]['message'], 'Administrator access')) {
                break;
            }
        }
    } finally {
        mysqli_query($mysqli, "SELECT RELEASE_LOCK('" . RIVET_AGENT_FEED_LOCK . "')");
    }
    $bad = array_filter($steps, static fn (array $s): bool => !$s['ok']);
    $done = count($steps) - count($bad);
    logAction('Endpoint Agent', 'Agent Feed Publish', "{$who->userName} published $tag: $done of " . count($steps) . ' files done' . ($bad ? ', ' . count($bad) . ' not done' : ''));

    return ['ok' => $bad === [] && $steps !== [], 'steps' => $steps, 'message' => $steps === [] ? 'This release has no file this server can use.' : ($bad === [] ? 'All ' . count($steps) . ' files of ' . $tag . ' are in place.' : count($bad) . ' of ' . count($steps) . ' files were not stored; see below.')];
}

// ------------------------------------------------------------------ the Linux assets for the Add device page

/**
 * The current Linux assets (script, binary, tarball, deb, rpm), by kind and architecture.
 *
 * @return array{version:?string,items:list<array<string,mixed>>}
 */
function rivetAgentFeedLinuxAssets($mysqli): array
{
    $out = ['version' => null, 'items' => []];
    if (!rivetAgentFeedReady($mysqli)) {
        return $out;
    }
    $q = mysqli_query($mysqli, 'SELECT * FROM endpoint_agent_linux_assets WHERE active = 1 AND is_current = 1 ORDER BY kind, arch');
    while ($q && ($r = mysqli_fetch_assoc($q))) {
        $out['items'][] = ['kind' => $r['kind'], 'arch' => $r['arch'], 'version' => $r['version'], 'filename' => $r['filename'], 'url' => $r['url'], 'sha256' => $r['sha256'], 'size' => (int) $r['size_bytes'], 'source' => $r['sha_source']];
        if ($out['version'] === null || rivetAgentFeedVersionCmp((string) $r['version'], $out['version']) > 0) {
            $out['version'] = (string) $r['version'];
        }
    }

    // the files this server hosts itself (26.10.64): the Add device page shows them and decides from them whether a command can be made
    $out['hosted'] = ['amd64' => null, 'arm64' => null, 'rows' => []];
    if (is_file(__DIR__ . '/rmm_linux_store.php')) {
        require_once __DIR__ . '/rmm_linux_store.php';
        foreach (['amd64', 'arm64'] as $a) {
            $f = rivetLxCommandFiles($mysqli, $a);
            $out['hosted'][$a] = $f === null ? null : $f['version'];
        }
        foreach (rivetLxList($mysqli) as $r) {
            if ((int) $r['is_current'] === 1 && (int) $r['active'] === 1) {
                $out['hosted']['rows'][] = ['name' => (string) $r['name'], 'kind' => (string) $r['kind'], 'arch' => (string) $r['arch'], 'version' => (string) $r['version'], 'size' => (int) $r['size_bytes'], 'sha256' => (string) $r['sha256']];
            }
        }
    }

    return $out;
}

/** May this recorded address be linked or put into a command? https only (the automated tests' loopback origin is the one exception, see the header). */
function rivetAgentFeedUrlIsSafe($u): bool
{
    if (!is_string($u) || $u === '' || preg_match('/[\x00-\x20\x7f\\\'"`$<>]/', $u) === 1) {
        return false;
    }
    $test = rivetAgentFeedTestOrigin();

    return str_starts_with($u, 'https://') || ($test !== null && str_starts_with($u, $test['origin'] . '/'));
}

/**
 * Turn Core's Linux snippet (which tells the person to unpack the tarball first) into a self-contained command when the script and the binary for this architecture are recorded:
 * it downloads install-linux.sh and the agent from GitHub, checks BOTH against the SHA-256 recorded here (a mismatch stops everything), then runs the script with the token in a
 * root-only temporary file exactly like Core's snippet. Returns the original snippet when the assets are not recorded or Core's text is not the shape this expects.
 *
 * @param array{version:?string,items:list<array<string,mixed>>} $assets
 * @return array{command:string,self_contained:bool,version:?string}
 */
function rivetAgentFeedLinuxCommand(string $coreSnippet, array $assets, string $arch): array
{
    $arch = $arch === 'arm64' ? 'arm64' : 'amd64';
    $find = static function (string $kind, string $a) use ($assets): ?array {
        foreach ($assets['items'] as $i) {
            if ($i['kind'] === $kind && ($i['arch'] === $a || $i['arch'] === '')) {
                return $i;
            }
        }

        return null;
    };
    $script = $find('script', $arch);
    $bin = $find('binary', $arch);
    $needle = './install-linux.sh ';
    $hex = static fn ($v): bool => is_string($v) && preg_match('/\A[0-9a-f]{64}\z/', $v) === 1;
    $secure = 'rivetAgentFeedUrlIsSafe';
    if ($script === null || $bin === null || substr_count($coreSnippet, $needle) !== 1 || !$secure($script['url']) || !$secure($bin['url']) || !$hex($script['sha256']) || !$hex($bin['sha256'])) {
        return ['command' => $coreSnippet, 'self_contained' => false, 'version' => null];
    }
    $q = static fn (string $v): string => "'" . str_replace("'", "'\\''", $v) . "'";
    // Core's lines up to the token stay as they are; S (the script) and B (the agent) are two more private temporary files that the same trap removes.
    $cmd = str_replace(['trap \'rm -f "$T"', "umask 077\n"], ['trap \'rm -f "$T" "$S" "$B"', "umask 077\nS=\"\$(mktemp)\"\nB=\"\$(mktemp)\"\n"], $coreSnippet);
    if (!str_contains($cmd, 'rm -f "$T" "$S" "$B"') || !str_contains($cmd, 'B="$(mktemp)"')) {
        return ['command' => $coreSnippet, 'self_contained' => false, 'version' => null];
    }
    // Both files are fetched here with the system's trust store (a private CA given to the script with --ca is for YOUR server, not for GitHub), checked against the SHA-256 recorded
    // on this server, and only then handed to the script (--binary: a plain agent, enrolled with --server and the token file).
    $get = static fn (string $url, string $sha, string $var, string $what): string => 'curl --fail --silent --show-error --location --proto \'=https\' --proto-redir \'=https\' -o "$' . $var . '" ' . $q($url) . "\n"
        . 'printf \'%s  %s\\n\' ' . $q($sha) . ' "$' . $var . '" | sha256sum -c - >/dev/null || { echo "' . $what . ' does not match the SHA-256 recorded on the RivetIT server" >&2; exit 65; }' . "\n";
    $cmd = str_replace($needle, $get($script['url'], $script['sha256'], 'S', 'install-linux.sh') . $get($bin['url'], $bin['sha256'], 'B', 'The agent download') . 'bash "$S" --binary "$B" ', $cmd);
    // the comment line that said "run from the unpacked tarball" is no longer true
    $ver = (string) preg_replace('/[^0-9A-Za-z._-]/', '', (string) $bin['version']);
    $cmd = (string) preg_replace('/^# Run as root from the unpacked [^\n]*\n/m', '# Run as root. It downloads install-linux.sh and the agent (rivetit-agent-linux-' . $arch . ' ' . $ver . ') from GitHub and checks both against the SHA-256 recorded on this server. The token is a secret: do not paste it into tickets or chat.' . "\n", $cmd, 1);

    return ['command' => $cmd, 'self_contained' => true, 'version' => $ver];
}

/**
 * The recorded Linux files for one architecture, for the Add device page and the dialog: the install script, the plain agent, the .tar.gz, the .deb and the .rpm, each with its
 * address on GitHub, its SHA-256 and size. Nothing secret.
 *
 * @param array{version:?string,items:list<array<string,mixed>>} $assets
 * @return list<array{kind:string,label:string,filename:string,url:string,sha256:string,size:int,version:string}>
 */
function rivetAgentFeedLinuxPackages(array $assets, string $arch): array
{
    $label = ['script' => 'Install script', 'binary' => 'Agent program', 'tarball' => 'Tarball (.tar.gz)', 'deb' => 'Debian / Ubuntu (.deb)', 'rpm' => 'Red Hat / Fedora / SUSE (.rpm)'];
    $out = [];
    foreach (['script', 'binary', 'tarball', 'deb', 'rpm'] as $kind) {
        foreach ($assets['items'] as $i) {
            if ($i['kind'] === $kind && ($i['arch'] === $arch || $i['arch'] === '')) {
                $out[] = ['kind' => $kind, 'label' => $label[$kind], 'filename' => (string) $i['filename'], 'url' => (string) $i['url'], 'sha256' => (string) $i['sha256'], 'size' => (int) $i['size'], 'version' => (string) $i['version']];
                break;
            }
        }
    }

    return $out;
}
