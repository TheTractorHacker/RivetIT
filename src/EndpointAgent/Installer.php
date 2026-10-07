<?php

namespace ITFlow\EndpointAgent;

/**
 * Per-department installer: builds the stamp payload for an enrollment token, authenticates the token-gated download
 * (POST /api/v1/agent_installer) and generates the deployment snippets shown in Administration > Endpoint agent.
 */
final class Installer
{
    // Rate limits for the token-gated download, DB backed (shares endpoint_agent_enroll_attempts with enrollment; the ip_hash salt keeps
    // the two buckets apart). Window 10 minutes.
    private const WINDOW_S = 600;
    private const IP_MAX_FAILURES = 5;
    private const IP_MAX_ATTEMPTS = 20;
    private const TOKEN_MAX_DOWNLOADS = 30;
    private const SELECTOR_MAX_FAILURES = 20;

    public static function departmentName(int $clientId): string
    {
        $n = Db::val('SELECT client_name FROM clients WHERE client_id = ?', [$clientId]);
        return $n === null ? ('Department ' . $clientId) : Enrollment::cleanText((string) $n, 200) ?? ('Department ' . $clientId);
    }

    /** File-name-safe slug of a department name: [a-z0-9-], at most 40 characters, never empty. */
    public static function slug(string $name): string
    {
        $s = strtolower((string) (function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) : $name));
        $s = trim((string) preg_replace('/[^a-z0-9]+/', '-', $s), '-');
        $s = trim(substr($s, 0, 40), '-');
        return $s === '' ? 'department' : $s;
    }

    public static function filename(string $department, string $arch): string
    {
        return 'RivetIT-Agent-Setup-' . self::slug($department) . '-' . ($arch === 'arm64' ? 'arm64' : 'x64') . '.exe';
    }

    /** CA certificate text to embed, or null when none is configured. */
    public static function caPem(): ?string
    {
        $pem = trim((string) (Config::get()['ca_pem'] ?? ''));
        return $pem === '' ? null : $pem;
    }

    /**
     * Normalise and validate pasted PEM certificates. @return array{0:?string,1:?string} [normalised PEM or null, error]
     */
    public static function normalizeCa(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [null, null];
        }
        if (strlen($text) > 8000) {
            return [null, 'The CA certificate text is too long (at most 8000 characters).'];
        }
        if (!preg_match_all('/-----BEGIN CERTIFICATE-----\s*([A-Za-z0-9+\/=\s]+?)\s*-----END CERTIFICATE-----/', $text, $m) || count($m[1]) > 5) {
            return [null, 'Paste one to five PEM certificates (-----BEGIN CERTIFICATE----- ... -----END CERTIFICATE-----).'];
        }
        $out = [];
        foreach ($m[1] as $b64) {
            $b64 = preg_replace('/\s+/', '', $b64);
            $der = base64_decode($b64, true);
            if ($der === false || $der === '') {
                return [null, 'A certificate is not valid base64.'];
            }
            $pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split($b64, 64, "\n") . "-----END CERTIFICATE-----\n";
            if (function_exists('openssl_x509_read') && @openssl_x509_read($pem) === false) {
                return [null, 'A pasted certificate could not be parsed.'];
            }
            $out[] = $pem;
        }
        return [implode('', $out), null];
    }

    /** @return array{0:?string,1:?string} [payload JSON, refusal message] */
    public static function payloadFor(array $token, string $token_plain, string $installerId): array
    {
        $base = Binaries::serviceBase();
        if ($base === null) {
            return [null, 'Set the service URL to an https:// address first: the installer needs it to find this server.'];
        }
        try {
            return [InstallerStamp::buildPayload([
                'installer_id' => $installerId,
                'server_url' => $base,
                'enrollment_token' => $token_plain,
                'department' => self::departmentName((int) $token['client_id']),
                'ca_pem' => self::caPem(),
                'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'expires_at' => (string) Db::iso($token['expires_at']),
            ]), null];
        } catch (\LengthException $e) {
            return [null, 'The installer payload is too large (department name or CA certificate).'];
        }
    }

    /** Preconditions shared by both download paths. @return string|null a refusal message */
    public static function preflight(string $arch): ?string
    {
        if (!Config::enabled()) {
            return 'The endpoint agent service is switched off. Turn it on first.';
        }
        if (!isset(Binaries::ARCHS[$arch])) {
            return 'Choose Windows x64 (amd64) or ARM64.';
        }
        if (Binaries::serviceBase() === null) {
            return 'Set the service URL to an https:// address first.';
        }
        if (Binaries::current($arch) === null) {
            return "No agent binary is published for $arch. Upload one under Agent binaries and make it current.";
        }
        return null;
    }

    // ---------------------------------------------------------------- token-gated download

    private static function ipHash(string $ip): string
    {
        return hash('sha256', 'ea-installer|' . $ip);
    }

    private static function record(string $ip, bool $ok, string $reason, string $selector): void
    {
        Db::run('INSERT INTO endpoint_agent_enroll_attempts (ip_hash, ip_text, success, reason, token_selector, attempted_at) VALUES (?, ?, ?, ?, ?, ?)',
            [self::ipHash($ip), substr($ip, 0, 64), $ok ? 1 : 0, substr('installer_' . $reason, 0, 40), substr($selector, 0, 12), Db::utcNow()]);
    }

    /** Throws 429 when this address (or this token selector) is over its budget. Counts only rows of the installer bucket. */
    public static function checkRate(string $ip, string $selector = ''): void
    {
        $since = gmdate('Y-m-d H:i:s', time() - self::WINDOW_S);
        $row = Db::one('SELECT COUNT(*) AS total, COALESCE(SUM(success = 0), 0) AS failures FROM endpoint_agent_enroll_attempts WHERE ip_hash = ? AND attempted_at > ?', [self::ipHash($ip), $since]);
        $limited = $row && ((int) $row['failures'] >= self::IP_MAX_FAILURES || (int) $row['total'] >= self::IP_MAX_ATTEMPTS);
        if (!$limited && $selector !== '') {
            $t = Db::one("SELECT COALESCE(SUM(success = 1), 0) AS ok, COALESCE(SUM(success = 0), 0) AS bad FROM endpoint_agent_enroll_attempts WHERE reason LIKE 'installer\\_%' AND token_selector = ? AND attempted_at > ?", [$selector, $since]);
            $limited = $t && ((int) $t['ok'] >= self::TOKEN_MAX_DOWNLOADS || (int) $t['bad'] >= self::SELECTOR_MAX_FAILURES);
        }
        if ($limited) {
            throw new ApiError(429, 'rate_limited', 'Too many requests. Try again later.', ['Retry-After' => (string) self::WINDOW_S]);
        }
    }

    /**
     * Authenticate an enrollment token for an installer download. Every failure (malformed, unknown, wrong secret, revoked, expired, used
     * up) is the same generic 404 to the caller; the reason is only in the audit log and the attempt table. The secret comparison runs
     * even when no row matched.
     * @return array<string,mixed> the token row
     */
    public static function authenticate(string $tokenStr, string $ip): array
    {
        $parts = explode('.', $tokenStr);
        $well = count($parts) === 3 && $parts[0] === Enrollment::TOKEN_PREFIX && preg_match('/^[0-9a-f]{12}$/', $parts[1]) && preg_match('/^[0-9a-f]{40}$/', $parts[2]);
        $selector = $well ? $parts[1] : '';
        $row = $well ? Db::one('SELECT * FROM endpoint_agent_enrollment_tokens WHERE token_selector = ?', [$selector]) : null;
        $match = hash_equals((string) ($row['token_hash'] ?? str_repeat('0', 64)), hash('sha256', $parts[2] ?? ''));
        $reason = null;
        if (!$well) {
            $reason = 'malformed';
        } elseif ($row === null || !$match) {
            $reason = 'invalid_token';
        } elseif ($row['revoked_at'] !== null) {
            $reason = 'revoked';
        } elseif (strtotime($row['expires_at'] . ' UTC') <= time()) {
            $reason = 'expired';
        } elseif ((int) $row['use_count'] >= (int) $row['max_uses']) {
            $reason = 'exhausted';
        }
        if ($reason !== null) {
            self::record($ip, false, $reason, $selector);
            Enrollment::audit('Installer Download Rejected', "Installer download rejected ($reason) from $ip" . ($selector !== '' ? " using token $selector" : ''), $row ? (int) $row['client_id'] : 0, 0);
            throw new ApiError(404, 'not_found', 'Not found.');
        }
        return $row;
    }

    public static function recordSuccess(array $token, string $ip, string $arch, string $installerId): void
    {
        self::record($ip, true, 'download', $token['token_selector']);
        Enrollment::audit('Installer Downloaded', "Installer $installerId ($arch) downloaded with token #{$token['token_id']} ({$token['token_selector']}) from $ip", (int) $token['client_id'], 0);
    }

    // ---------------------------------------------------------------- snippets

    /** A PowerShell single-quoted string literal. Control characters are refused outright. */
    public static function psQuote(string $v): string
    {
        if (preg_match('/[\x00-\x1f\x7f]/', $v)) {
            throw new \InvalidArgumentException('control character in a script value');
        }
        return "'" . str_replace("'", "''", $v) . "'";
    }

    /** Unattended deployment snippet for an RMM, Intune platform script or GPO startup script. */
    public static function powershellSnippet(string $serverUrl, string $token, string $arch, string $department): string
    {
        $arch = $arch === 'arm64' ? 'arm64' : 'amd64';
        $comment = trim((string) preg_replace('/[^A-Za-z0-9 ._-]+/', '?', $department));
        return "# RivetIT agent silent install for department: $comment\n"
            . "# Run as SYSTEM or an administrator (RMM, Intune platform script, GPO startup script). The token is a secret: do not paste it into tickets or chat.\n"
            . "\$ErrorActionPreference = 'Stop'\n"
            . '$Server = ' . self::psQuote(rtrim($serverUrl, '/')) . "\n"
            . '$Token  = ' . self::psQuote($token) . "\n"
            . '$Arch   = ' . self::psQuote($arch) . "\n"
            . "\$Exe = Join-Path \$env:TEMP ('RivetIT-Agent-Setup-' + [guid]::NewGuid().ToString('N') + '.exe')\n"
            . "try {\n"
            . "    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12\n"
            . "    \$body = @{ token = \$Token; arch = \$Arch } | ConvertTo-Json -Compress\n"
            . "    Invoke-WebRequest -Uri (\$Server + '/api/v1/agent_installer') -Method Post -ContentType 'application/json' -Body \$body -OutFile \$Exe -UseBasicParsing\n"
            . "    \$p = Start-Process -FilePath \$Exe -ArgumentList 'setup', '--silent' -Wait -PassThru\n"
            . "    if (\$p.ExitCode -ne 0) { throw \"RivetIT agent setup failed with exit code \$(\$p.ExitCode)\" }\n"
            . "    Write-Output 'RivetIT agent installed.'\n"
            . "} finally {\n"
            . "    Remove-Item -LiteralPath \$Exe -Force -ErrorAction SilentlyContinue\n"
            . "}\n";
    }
}
