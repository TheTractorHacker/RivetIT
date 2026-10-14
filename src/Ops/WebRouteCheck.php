<?php

namespace ITFlow\Ops;

/**
 * Self-check of the web server's routes, for the Server status page, the Remote MCP settings page and the Update page.
 *
 * The app asks its OWN public address for the handful of URLs in the route registry (WebRoutes) and compares each answer with
 * what the registry says it should be. A route nginx does not know shows up as a wrong status or a missing header, and is named by
 * the feature it breaks. It also compares the installed routes file with the one this checkout ships.
 *
 * Safety rules (this runs inside a web request, so it must never hurt it):
 *   - SSRF: only the host of config_base_url is ever contacted (validated, no userinfo/path/query), only registry paths (constants
 *     or real files found under the app root), GET only, no redirects followed, 3 s connect / 5 s total per request, 12 s overall,
 *     at most 4 requests in flight. Nothing comes from a visitor.
 *   - Fail soft: no result of this check can raise an exception to the caller, change a status code, or touch /health/ready.php.
 *   - It stands down with a clear note when it cannot judge: not nginx, Docker, the site cannot reach itself, or something in front
 *     (proxy, firewall) answers a known-good control request differently.
 *   - The result is cached for a few minutes so the page stays fast. Nothing here writes to nginx; the app's user cannot.
 */
final class WebRouteCheck
{
    public const TTL = 300;
    public const OVERALL_SECONDS = 12;
    public const MAX_IN_FLIGHT = 4;
    /** Must answer 404 on a correctly configured server; anything else means something in front is interfering. */
    public const CONTROL = ['method' => 'GET', 'path' => '/rivetit-route-check-control', 'status' => [404]];

    /** @var callable(list<array{url:string,method:string}>):list<array{status:int,headers:array<string,string>,error:?string}> */
    private $fetch;
    private string $host;
    private ?string $skipReason = null;

    /**
     * @param array<string,bool> $context  truth of the conditions in WebRoutes::CONDITIONS
     * @param ?callable $fetch  replaces the HTTP client (tests)
     */
    public function __construct(
        private string $appRoot,
        string $baseUrl,
        private array $context = [],
        private string $scheme = 'https',
        ?callable $fetch = null,
        private string $installedPath = WebRoutes::INSTALLED_PATH,
        private ?string $cacheDir = null,
        private int $ttl = self::TTL,
        private ?int $now = null,
        ?string $notApplicable = null,
    ) {
        $this->appRoot = rtrim($appRoot, '/');
        $this->now ??= time();
        $this->cacheDir ??= sys_get_temp_dir();
        $this->fetch = $fetch ?? [self::class, 'curlFetch'];
        $baseUrl = trim($baseUrl);
        if ($notApplicable !== null) {
            $this->skipReason = $notApplicable;
        } elseif (!in_array($scheme, ['https', 'http'], true)) {
            $this->skipReason = 'The site address uses an unsupported scheme.';
        } elseif (!preg_match('~^[A-Za-z0-9](?:[A-Za-z0-9.-]{0,251}[A-Za-z0-9])?(?::[0-9]{1,5})?$~D', $baseUrl)) {
            $this->skipReason = 'The configured site address (Settings, Base URL) is empty or not a plain host name, so the app cannot ask itself about its routes.';
        }
        $this->host = $baseUrl;
    }

    /**
     * The normal constructor for the running app: works out whether the check applies (nginx, not Docker) and the MCP conditions.
     * @param ?array<string,mixed> $server $_SERVER
     */
    public static function forApp(string $appRoot, string $baseUrl, ?\mysqli $db = null, ?array $server = null, ?bool $docker = null): self
    {
        $server ??= $_SERVER;
        $docker ??= is_file('/.dockerenv');
        $sw = strtolower((string) ($server['SERVER_SOFTWARE'] ?? ''));
        $why = null;
        if ($docker) { $why = 'This install runs in Docker: its web server routes come with the image (docker/nginx.conf), so there is nothing to apply.'; }
        elseif ($sw !== '' && !str_starts_with($sw, 'nginx')) { $why = 'This install is not served by nginx (it reports ' . substr(preg_replace('~[^A-Za-z0-9 ./_-]~', '', $sw), 0, 40) . '), so these nginx routes do not apply.'; }
        return new self($appRoot, $baseUrl, $db ? self::mcpContext($db) : [], 'https', null, WebRoutes::INSTALLED_PATH, null, self::TTL, null, $why);
    }

    /** @return array{mcp_ready:bool, mcp_builtin:bool} */
    public static function mcpContext(\mysqli $db): array
    {
        $ctx = ['mcp_ready' => false, 'mcp_builtin' => false];
        try {
            $mcp = \ITFlow\Mcp\McpConfig::load($db);
            $oauth = \ITFlow\Mcp\OAuth\OAuthConfig::load($db);
            $ctx['mcp_builtin'] = (bool) ($oauth['active'] ?? false);
            $ctx['mcp_ready'] = (bool) ($mcp['enabled'] ?? false) && ($ctx['mcp_builtin'] || (bool) ($mcp['configured'] ?? false));
        } catch (\Throwable) { /* treated as off */ }
        return $ctx;
    }

    public function domain(): string
    {
        return preg_replace('~:[0-9]+$~', '', $this->host);
    }

    public function command(): string
    {
        return WebRoutes::applyCommand($this->appRoot, $this->domain());
    }

    /** State of the installed routes file against the one this checkout ships. @return 'current'|'outdated'|'absent'|'unreadable' */
    public function fileState(): string
    {
        $tpl = $this->appRoot . '/' . WebRoutes::TEMPLATE_PATH;
        if (!is_file($tpl) || !is_readable($tpl)) { return 'unreadable'; }
        if (!file_exists($this->installedPath)) { return 'absent'; }
        if (!is_readable($this->installedPath)) { return 'unreadable'; }
        $a = @hash_file('sha256', $tpl);
        $b = @hash_file('sha256', $this->installedPath);
        return $a !== false && $a === $b ? 'current' : 'outdated';
    }

    /**
     * Run the probes (or serve the cached result). Never throws.
     * @param ?list<string> $onlyIds  limit to these registry ids (plus the control requests); null = all
     * @return array{state:string, checked:int, unknown:int, missing:list<array<string,string>>, note:string, file:string, at:int, cached:bool}
     */
    public function run(bool $refresh = false, ?array $onlyIds = null): array
    {
        $base = ['state' => 'skipped', 'checked' => 0, 'unknown' => 0, 'missing' => [], 'note' => '', 'file' => 'unreadable', 'at' => $this->now, 'cached' => false];
        try {
            $base['file'] = $this->fileState();
            if ($this->skipReason !== null) { return ['note' => $this->skipReason] + $base; }
            $key = $this->cacheKey($onlyIds, $base['file']);
            if (!$refresh && ($hit = $this->cacheRead($key)) !== null) { return ['cached' => true] + $hit; }
            $res = $this->probe($onlyIds) + $base;
            $res['file'] = $base['file'];
            $res['at'] = $this->now;
            $this->cacheWrite($key, $res);
            return $res;
        } catch (\Throwable $e) {
            return ['note' => 'The route check could not run (' . substr($e->getMessage(), 0, 100) . ').'] + $base;
        }
    }

    /** The last cached full result, without any network access (null when there is none or it expired). */
    public function cached(): ?array
    {
        try {
            if ($this->skipReason !== null) { return null; }
            $hit = $this->cacheRead($this->cacheKey(null, $this->fileState()));
            return $hit === null ? null : ['cached' => true] + $hit;
        } catch (\Throwable) { return null; }
    }

    /** @return array{state:string, checked:int, unknown:int, missing:list<array<string,string>>, note:string} */
    private function probe(?array $onlyIds): array
    {
        $deadline = microtime(true) + self::OVERALL_SECONDS;
        // 1. the two controls: PHP answers through the public address, and nothing in front rewrites a plain 404.
        $control = $this->request([WebRoutes::SENTINEL, self::CONTROL]);
        $s = $control[0];
        if ($s['error'] !== null || $s['status'] === 0) {
            return ['state' => 'skipped', 'checked' => 0, 'unknown' => 0, 'missing' => [], 'note' => 'The server could not reach its own public address (' . ($s['error'] ?? 'no answer') . '), for example because loopback or hairpin traffic is blocked or it sits behind another proxy. Route checks were skipped; run the command below on the server instead to be sure.'];
        }
        if (!in_array($s['status'], WebRoutes::SENTINEL['status'], true)) {
            return ['state' => 'skipped', 'checked' => 0, 'unknown' => 0, 'missing' => [], 'note' => "The site's own health check answered HTTP {$s['status']} through its public address, so route checks were skipped (fix the site first)."];
        }
        $c = $control[1];
        if ($c['error'] !== null || !in_array($c['status'], self::CONTROL['status'], true)) {
            return ['state' => 'skipped', 'checked' => 0, 'unknown' => 0, 'missing' => [], 'note' => 'Something in front of this server (a proxy or firewall) does not return a plain 404 for an unknown address, so its answers cannot be compared with the expected ones. Route checks were skipped.'];
        }

        // 2. the registry probes
        $todo = [];
        $unknown = 0;
        foreach (WebRoutes::all() as $e) {
            $p = $e['probe'];
            if ($p === null || ($onlyIds !== null && !in_array($e['id'], $onlyIds, true))) { continue; }
            if (isset($p['requires']) && empty($this->context[$p['requires']])) { continue; }
            $path = $this->resolvePath($p['path']);
            if ($path === null) { $unknown++; continue; }
            $todo[] = [$e, ['path' => $path] + $p];
        }
        $missing = [];
        $checked = 0;
        foreach (array_chunk($todo, self::MAX_IN_FLIGHT) as $chunk) {
            if (microtime(true) > $deadline) { $unknown += count($chunk); continue; }
            $answers = $this->request(array_map(static fn($t) => $t[1], $chunk));
            foreach ($chunk as $i => [$e, $p]) {
                $a = $answers[$i];
                $why = $this->mismatch($p, $a);
                if ($why === 'unknown') { $unknown++; continue; }
                $checked++;
                if ($why !== null) {
                    $missing[] = ['id' => $e['id'], 'feature' => $e['feature'], 'severity' => $e['severity'], 'purpose' => $e['purpose'], 'location' => WebRoutes::key($e), 'problem' => $why];
                }
            }
        }
        return ['state' => $missing ? 'missing' : 'ok', 'checked' => $checked, 'unknown' => $unknown, 'missing' => $missing, 'note' => ''];
    }

    /** @return ?string null = as expected, 'unknown' = cannot tell, otherwise what is wrong */
    private function mismatch(array $p, array $a): ?string
    {
        if ($a['error'] !== null || $a['status'] === 0) { return 'unknown'; }
        $st = $a['status'];
        if (!in_array($st, $p['status'], true)) {
            if (in_array($st, [429, 502, 503, 504], true)) { return 'unknown'; }   // busy or overloaded, not a routing answer
            return "answered HTTP $st, expected " . implode(' or ', $p['status']);
        }
        foreach ($p['headers'] ?? [] as $name => $needle) {
            $v = $a['headers'][strtolower($name)] ?? null;
            if ($v === null || stripos($v, $needle) === false) { return "the $name header is " . ($v === null ? 'missing' : 'wrong') . " (expected it to contain \"$needle\")"; }
        }
        foreach ($p['absent'] ?? [] as $name) {
            if (isset($a['headers'][strtolower($name)])) { return "answered by the application instead of the web server (it carries the $name header the web server never sends)"; }
        }
        return null;
    }

    /** @param string|array{dir:string,ext:list<string>} $p */
    private function resolvePath(string|array $p): ?string
    {
        if (is_string($p)) { return $p; }
        $dir = $this->appRoot . '/' . $p['dir'];
        if (!is_dir($dir)) { return null; }
        foreach (['', '/*', '/*/*', '/*/*/*'] as $depth) {
            foreach ($p['ext'] as $ext) {
                $files = glob($dir . $depth . '/*.' . $ext) ?: [];
                sort($files);
                foreach ($files as $f) {
                    $rel = substr($f, strlen($this->appRoot));
                    if (is_file($f) && filesize($f) > 0 && preg_match('~^/[A-Za-z0-9._/-]+$~D', $rel)) { return $rel; }
                }
            }
        }
        return null;
    }

    /** @param list<array<string,mixed>> $probes @return list<array{status:int,headers:array<string,string>,error:?string}> */
    private function request(array $probes): array
    {
        $reqs = array_map(fn($p) => ['url' => $this->scheme . '://' . $this->host . $p['path'], 'method' => $p['method'] ?? 'GET'], $probes);
        $out = ($this->fetch)($reqs);
        $res = [];
        foreach (array_keys($reqs) as $i) { $res[$i] = $out[$i] ?? ['status' => 0, 'headers' => [], 'error' => 'no answer']; }
        return $res;
    }

    /** Default HTTP client: curl_multi, bounded as described in the class comment. */
    public static function curlFetch(array $reqs): array
    {
        if (!function_exists('curl_multi_init')) {
            return array_map(static fn() => ['status' => 0, 'headers' => [], 'error' => 'PHP curl is not available'], $reqs);
        }
        $mh = curl_multi_init();
        $hs = [];
        $heads = [];
        foreach ($reqs as $i => $r) {
            $h = curl_init($r['url']);
            $heads[$i] = [];
            curl_setopt_array($h, [
                CURLOPT_CUSTOMREQUEST => $r['method'] === 'HEAD' ? 'HEAD' : 'GET',
                CURLOPT_NOBODY => $r['method'] === 'HEAD',
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_USERAGENT => 'RivetIT-route-check',
                CURLOPT_HTTPHEADER => ['Accept: */*'],
                CURLOPT_HEADERFUNCTION => static function ($ch, $line) use (&$heads, $i) {
                    if (strpos($line, ':') !== false) {
                        [$n, $v] = explode(':', $line, 2);
                        $n = strtolower(trim($n));
                        $heads[$i][$n] = isset($heads[$i][$n]) ? $heads[$i][$n] . ', ' . trim($v) : trim($v);
                    } elseif (str_starts_with($line, 'HTTP/')) {
                        $heads[$i] = [];   // a new response (e.g. after 100-continue): start again
                    }
                    return strlen($line);
                },
                // We only need the status and headers: stop after the first chunk of any body.
                CURLOPT_WRITEFUNCTION => static fn($ch, $data) => -1,
            ]);
            curl_multi_add_handle($mh, $h);
            $hs[$i] = $h;
        }
        $running = 0;
        do {
            curl_multi_exec($mh, $running);
            if ($running) { curl_multi_select($mh, 0.5); }
        } while ($running > 0);
        $out = [];
        foreach ($hs as $i => $h) {
            $err = curl_errno($h);
            $status = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
            // CURLE_WRITE_ERROR (23) is how we stop reading the body on purpose.
            $out[$i] = ['status' => $status, 'headers' => $heads[$i], 'error' => ($err === 0 || ($err === 23 && $status > 0)) ? null : curl_strerror($err)];
            curl_multi_remove_handle($mh, $h);
            curl_close($h);
        }
        curl_multi_close($mh);
        return $out;
    }

    private function cacheKey(?array $onlyIds, string $fileState): string
    {
        $tpl = @hash_file('sha256', $this->appRoot . '/' . WebRoutes::TEMPLATE_PATH) ?: 'none';
        return substr(hash('sha256', json_encode([$this->appRoot, $this->host, $this->scheme, $this->context, $onlyIds, WebRoutes::fingerprint(), $tpl, $fileState])), 0, 24);
    }

    private function cacheFile(string $key): string
    {
        return rtrim((string) $this->cacheDir, '/') . '/rivetit-webroutes-' . $key . '.json';
    }

    private function cacheRead(string $key): ?array
    {
        $f = $this->cacheFile($key);
        if (!is_file($f) || filemtime($f) < $this->now - $this->ttl) { return null; }
        $j = json_decode((string) @file_get_contents($f), true);
        return is_array($j) && isset($j['state']) ? $j : null;
    }

    private function cacheWrite(string $key, array $res): void
    {
        $f = $this->cacheFile($key);
        $tmp = $f . '.' . getmypid();
        if (@file_put_contents($tmp, json_encode($res), LOCK_EX) !== false) { @rename($tmp, $f); }
    }

    /**
     * Rows for the Server status page.
     * @param array{state:string, checked:int, unknown:int, missing:list<array<string,string>>, note:string, file:string} $r
     * @return list<array{status:string,label:string,detail:string}>
     */
    public function rows(array $r): array
    {
        $cmd = $this->command();
        $out = [];
        if ($r['state'] === 'skipped') {
            $out[] = ['status' => 'skip', 'label' => 'Web server routes', 'detail' => $r['note'] . ' Command to bring them up to date: ' . $cmd];
        } elseif ($r['state'] === 'missing') {
            $sec = false;
            $lines = [];
            foreach ($r['missing'] as $m) {
                $sec = $sec || $m['severity'] === 'security';
                $lines[] = $m['feature'] . ' (' . $m['location'] . ': ' . $m['problem'] . ')';
            }
            $out[] = ['status' => $sec ? 'fail' : 'warn', 'label' => 'Web server is missing routes (' . count($r['missing']) . ')',
                'detail' => 'These features are affected: ' . implode('; ', $lines) . '. Fix it with one command as root on the server: ' . $cmd];
        } else {
            $out[] = ['status' => 'ok', 'label' => 'Web server routes', 'detail' => "All {$r['checked']} checked routes answer correctly" . ($r['unknown'] ? " ({$r['unknown']} could not be judged right now)." : '.')];
        }
        if ($r['file'] === 'outdated') {
            $out[] = ['status' => 'warn', 'label' => 'Web server routes file', 'detail' => 'The installed routes file differs from the one shipped with this version (a newer release may need a route). Bring it up to date as root: ' . $cmd];
        } elseif ($r['file'] === 'absent' && $r['state'] !== 'skipped') {
            $out[] = ['status' => 'warn', 'label' => 'Web server routes file', 'detail' => 'This server still carries its nginx rules inline (older install) or the shared routes file is elsewhere. One command converts it safely, with a backup and automatic rollback: ' . $cmd];
        }
        return $out;
    }

    /** One short sentence for banners (MCP settings, Update page), or null when there is nothing to say. */
    public function hint(array $r): ?string
    {
        if ($this->skipReason !== null) { return null; }
        if ($r['state'] === 'missing') {
            return 'The web server is missing ' . count($r['missing']) . ' route' . (count($r['missing']) === 1 ? '' : 's') . ' this version needs. On the server, as root, run: ' . $this->command();
        }
        if ($r['file'] === 'outdated') {
            return 'The web server routes file is older than this version. On the server, as root, run: ' . $this->command();
        }
        return null;
    }
}
