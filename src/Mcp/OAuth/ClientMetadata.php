<?php

namespace ITFlow\Mcp\OAuth;

/**
 * Pure rules for OAuth Client ID Metadata Documents (draft-ietf-oauth-client-id-metadata-document, docs/REMOTE_MCP.md):
 * what a client_id URL may look like, what a fetched document must contain, how long it may be cached and how a requested
 * redirect URI is matched against it. No network, no database: CimdFetcher fetches, OAuthService decides.
 *
 * Everything in a document is the client's own claim. Nothing here trusts it beyond these checks, and callers must escape
 * every value before showing it.
 */
final class ClientMetadata
{
    public const MAX_URL = 512;
    public const MAX_BYTES = 65536;
    public const MIN_TTL = 300;        // seconds; a shorter Cache-Control is raised to this
    public const MAX_TTL = 86400;      // seconds; a longer one is lowered to this
    public const DEFAULT_TTL = 3600;   // no usable Cache-Control
    public const MAX_REDIRECT_URIS = 10;
    public const MAX_CLIENTS = 500;    // metadata-document clients kept at once

    /** Does this client_id look like a metadata-document URL (as opposed to a registered client ID)? */
    public static function looksLikeUrl(string $clientId): bool
    {
        return str_contains($clientId, '://');
    }

    /** The internal client ID (fits mcp_oauth_clients.client_id, 64 ascii chars) a metadata-document URL is stored under. */
    public static function internalId(string $url): string
    {
        return 'cimd_' . substr(hash('sha256', $url), 0, 58);
    }

    /**
     * Is $url usable as a client_id: https, a host, a path, no fragment, no credentials, no dot segments, printable ASCII only.
     * Nothing is normalised: the document's own client_id must equal this exact string.
     *
     * @return array{ok:true, host:string, authority:string}|array{ok:false, error:string}
     */
    public static function checkUrl(string $url): array
    {
        $bad = static fn (string $e): array => ['ok' => false, 'error' => $e];
        if ($url === '' || strlen($url) > self::MAX_URL || preg_match('/[^\x21-\x7e]/', $url) || str_contains($url, '\\')) {
            return $bad('not a plain ASCII URL of at most ' . self::MAX_URL . ' characters');
        }
        if (!str_starts_with($url, 'https://')) {
            return $bad('not an https URL');
        }
        if (str_contains($url, '#')) {
            return $bad('has a fragment');
        }
        $p = parse_url($url);
        if (!is_array($p) || empty($p['host']) || isset($p['user']) || isset($p['pass'])) {
            return $bad('has no host, or has credentials');
        }
        $host = (string) $p['host'];
        if ($host !== strtolower($host) || !preg_match('/^(?:[a-z0-9]([a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*|\[[0-9a-f:.]+\])$/D', $host)) {
            return $bad('host is not a lower-case name or IP address');
        }
        if (isset($p['port']) && ($p['port'] < 1 || $p['port'] > 65535)) {
            return $bad('bad port');
        }
        $path = (string) ($p['path'] ?? '');
        if (strlen($path) < 2) {
            return $bad('has no path');
        }
        foreach (explode('/', $path) as $segment) {
            $decoded = rawurldecode($segment);
            if ($decoded === '.' || $decoded === '..' || str_contains($decoded, '/') || str_contains($decoded, '\\')) {
                return $bad('has a dot segment or an encoded slash');
            }
        }

        return ['ok' => true, 'host' => $host, 'authority' => $host . (isset($p['port']) && (int) $p['port'] !== 443 ? ':' . $p['port'] : '')];
    }

    /**
     * Validate a fetched document (already JSON-decoded) against the URL it was fetched from.
     *
     * Unsupported grant types, response types and extra fields are NOT errors: Claude's own document lists the jwt-bearer grant,
     * which this server simply never issues. What is refused is anything that would make the client confidential.
     *
     * @return array{ok:true, name:?string, redirect_uris:list<string>}|array{ok:false, error:string}
     */
    public static function checkDocument(mixed $doc, string $url): array
    {
        $bad = static fn (string $e): array => ['ok' => false, 'error' => $e];
        if (!is_array($doc) || array_is_list($doc)) {
            return $bad('the document is not a JSON object');
        }
        if (($doc['client_id'] ?? null) !== $url) {
            return $bad('client_id does not equal the URL it was fetched from');
        }
        foreach (['client_secret', 'client_secret_expires_at', 'jwks', 'jwks_uri', 'client_assertion'] as $forbidden) {
            if (array_key_exists($forbidden, $doc)) {
                return $bad('the document carries confidential-client material (' . $forbidden . ')');
            }
        }
        $method = $doc['token_endpoint_auth_method'] ?? 'none';
        if ($method !== 'none') {
            return $bad('token_endpoint_auth_method is not "none" (public clients only)');
        }
        if (array_key_exists('grant_types', $doc) && (!is_array($doc['grant_types']) || !in_array('authorization_code', $doc['grant_types'], true))) {
            return $bad('grant_types does not include authorization_code');
        }
        if (array_key_exists('response_types', $doc) && (!is_array($doc['response_types']) || !in_array('code', $doc['response_types'], true))) {
            return $bad('response_types does not include code');
        }
        $uris = $doc['redirect_uris'] ?? null;
        if (!is_array($uris) || $uris === [] || !array_is_list($uris) || count($uris) > self::MAX_REDIRECT_URIS) {
            return $bad('redirect_uris must be a list of 1 to ' . self::MAX_REDIRECT_URIS . ' addresses');
        }
        foreach ($uris as $u) {
            if (!is_string($u) || !OAuthService::redirectUriValid($u)) {
                return $bad('redirect_uris must be https, or http on localhost, 127.0.0.1 or [::1], without a fragment');
            }
        }
        $name = $doc['client_name'] ?? null;
        $clean = is_string($name) && self::plainText($name) ? mb_substr(trim($name), 0, 100) : null;
        if ($clean === '') {
            $clean = null;
        }

        return ['ok' => true, 'name' => $clean, 'redirect_uris' => array_values(array_unique($uris))];
    }

    /**
     * Is $text plain, honest display text: valid UTF-8 with no control characters and no invisible formatting characters
     * (zero-width and bidirectional overrides, U+202E and friends), which could make a name read as something else on the
     * approval screen. Names that fail are replaced by the host name (documents) or refused (registration).
     */
    public static function plainText(string $text): bool
    {
        return mb_check_encoding($text, 'UTF-8') && !preg_match('/[\x00-\x1f\x7f]/', $text) && !preg_match('/[\p{Cf}\p{Zl}\p{Zp}\p{Co}\p{Cn}]/u', $text);
    }

    /**
     * Is a client still allowed under the CURRENT trust policy? Checked on every MCP request and every token request, so
     * switching the policy off or removing a host cuts existing connections at once, not when the access token expires.
     * A registered or administrator-added client does not depend on the policy (switching self-registration off only stops
     * new registrations). A metadata-document client needs the mode not to be off and, in "hosts" mode, its host to be listed.
     *
     * @param array{mode:string, hosts:list<string>} $policy from OAuthConfig::cimd()
     */
    public static function policyAllows(string $registrationType, ?string $sourceUrl, array $policy): bool
    {
        if ($registrationType !== 'metadata') {
            return true;
        }
        if ($policy['mode'] === 'off' || $sourceUrl === null || $sourceUrl === '') {
            return false;
        }
        $shape = self::checkUrl($sourceUrl);
        if (!$shape['ok']) {
            return false;
        }

        return $policy['mode'] === 'any' || self::hostListed($shape['authority'], $policy['hosts']);
    }

    /** Seconds a document may be reused, from its Cache-Control header, clamped to MIN_TTL..MAX_TTL. */
    public static function cacheTtl(?string $cacheControl): int
    {
        if ($cacheControl === null || !preg_match('/(?:^|[\s,])max-age\s*=\s*"?(\d{1,9})"?/i', $cacheControl, $m)) {
            return self::DEFAULT_TTL;
        }

        return max(self::MIN_TTL, min(self::MAX_TTL, (int) $m[1]));
    }

    /** Is the URL's host (plus a non-default port) one of the administrator's trusted hosts? */
    public static function hostListed(string $authority, array $hosts): bool
    {
        return in_array($authority, $hosts, true);
    }

    /**
     * Does the requested redirect URI match one the document lists? Exact string match, except that an entry on a loopback
     * address (http, localhost / 127.0.0.1 / [::1]) matches any port, as RFC 8252 section 7.3 requires for native apps (Claude Code
     * picks a new port every session). Host, path and query must still be identical, and a hosted https entry never gets a wildcard.
     *
     * @param list<mixed> $entries
     */
    public static function redirectMatches(array $entries, string $requested): bool
    {
        if (!OAuthService::redirectUriValid($requested)) {
            return false;
        }
        $r = parse_url($requested);
        if (!is_array($r) || (isset($r['port']) && ($r['port'] < 1 || $r['port'] > 65535))) {
            return false;
        }
        foreach ($entries as $entry) {
            if (!is_string($entry)) {
                continue;
            }
            if (hash_equals($entry, $requested)) {
                return true;
            }
            $e = parse_url($entry);
            if (!is_array($e) || strtolower((string) ($e['scheme'] ?? '')) !== 'http' || strtolower((string) ($r['scheme'] ?? '')) !== 'http') {
                continue;
            }
            $host = strtolower((string) ($e['host'] ?? ''));
            if (!in_array($host, ['localhost', '127.0.0.1', '[::1]'], true) || $host !== strtolower((string) ($r['host'] ?? ''))) {
                continue;
            }
            if (($e['path'] ?? '/') === ($r['path'] ?? '/') && ($e['query'] ?? null) === ($r['query'] ?? null)) {
                return true;
            }
        }

        return false;
    }
}
