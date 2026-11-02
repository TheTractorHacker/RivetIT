<?php

namespace ITFlow\Quality;

/**
 * The QMS address: vetted when an administrator saves it AND again before every call (a name can start resolving somewhere else later).
 *
 *  - https only; no user name or password, no query, no fragment; an optional path prefix (a QMS served under /qms) with plain characters only;
 *  - the host must resolve to public addresses, plus any internal network the administrator listed under Webhooks > Allowed internal networks
 *    (RivetCore's UrlPolicy, the same rule the webhooks and the MDM client use); loopback, link-local and unspecified addresses are never allowed;
 *  - the caller connects to exactly the addresses that were vetted (curl is pinned to them), so a DNS answer that changes between the check and
 *    the connection cannot redirect the request (DNS rebinding); redirects are never followed.
 *
 * Test seam: a test script may define RIVETIT_QMS_TEST_LOOPBACK as true to talk to a mock QMS on 127.0.0.1 or localhost over http (only those two hosts; every
 * other address still faces the real policy). Nothing in the application defines it.
 */
final class QmsUrl
{
    /** @return array{ok:bool, error:string, base:string, target:?array{host:string,port:int,ips:list<string>}} */
    public static function vet(\mysqli $db, string $url): array
    {
        $fail = static fn(string $m): array => ['ok' => false, 'error' => $m, 'base' => '', 'target' => null];
        $url = trim($url);
        if ($url === '' || strlen($url) > 500 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            return $fail('Enter the QMS address as https://host or https://host/path (no spaces).');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return $fail('Enter the QMS address as https://host or https://host:port/path (no user name, password, query or fragment).');
        }
        $scheme = strtolower((string) $parts['scheme']);
        $loop = defined('RIVETIT_QMS_TEST_LOOPBACK') && RIVETIT_QMS_TEST_LOOPBACK === true;
        $loopHost = $loop && in_array(strtolower(trim((string) $parts['host'], '[]')), ['127.0.0.1', 'localhost'], true);
        if ($scheme !== 'https' && !($loopHost && $scheme === 'http')) {
            return $fail('The QMS address must start with https://.');
        }
        $path = rtrim((string) ($parts['path'] ?? ''), '/');
        if ($path !== '' && (preg_match('~^(/[A-Za-z0-9_.-]+)+$~D', $path) !== 1 || str_contains($path, '..'))) {
            return $fail('The path part of the QMS address may contain only letters, digits, dots, dashes, underscores and slashes.');
        }
        if (!function_exists('rivetWebhookUrlPolicy') && is_file(dirname(__DIR__, 2) . '/includes/event_bus.php')) {
            require_once dirname(__DIR__, 2) . '/includes/event_bus.php';
        }
        $host0 = strtolower(trim((string) $parts['host'], '[]'));
        if ($loop && in_array($host0, ['127.0.0.1', 'localhost'], true)) {
            // Test seam, and ONLY for the loopback mock: every other address (a metadata service, a private network) still goes through the real policy below.
            $target = ['host' => $host0, 'port' => (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80)), 'ips' => ['127.0.0.1']];
        } else {
            $policy = function_exists('rivetWebhookUrlPolicy') ? rivetWebhookUrlPolicy($db) : new \RivetCore\Webhooks\UrlPolicy(false);
            $target = $policy->vet($url, false);
        }
        if ($target === null) {
            $rule = function_exists('rivetWebhookRuleText') ? rivetWebhookRuleText($db) : 'public addresses only';
            return $fail('That address is not allowed (' . $rule . '). A QMS on a private network must be inside a network listed under Administration > Webhooks > Allowed internal networks; loopback and link-local addresses are never allowed.');
        }
        $host = (string) $parts['host'];
        $base = $scheme . '://' . (str_contains($host, ':') && !str_starts_with($host, '[') ? '[' . $host . ']' : $host) . (isset($parts['port']) ? ':' . (int) $parts['port'] : '') . $path;
        return ['ok' => true, 'error' => '', 'base' => $base, 'target' => $target];
    }

    /** Same host and port as the base address (the token endpoint may not point somewhere else). */
    public static function sameOrigin(string $a, string $b): bool
    {
        $pa = parse_url($a);
        $pb = parse_url($b);
        if (!is_array($pa) || !is_array($pb)) {
            return false;
        }
        $port = static fn(array $p): int => (int) ($p['port'] ?? (strtolower((string) ($p['scheme'] ?? '')) === 'https' ? 443 : 80));
        return strtolower((string) ($pa['scheme'] ?? '')) === strtolower((string) ($pb['scheme'] ?? ''))
            && strtolower((string) ($pa['host'] ?? '')) === strtolower((string) ($pb['host'] ?? '')) && $port($pa) === $port($pb);
    }

    /**
     * May this link from the QMS be shown to a person? Only an https (or test http) address on the configured QMS origin, so a response can never put
     * a javascript: link, an off-site link or a link carrying credentials on the page.
     */
    public static function safeLink(string $base, mixed $link): ?string
    {
        if (!is_string($link) || $link === '' || strlen($link) > 1000 || preg_match('/[\x00-\x20\x7f\\\\]/', $link)) {
            return null;
        }
        $p = parse_url($link);
        if (!is_array($p) || isset($p['user']) || isset($p['pass']) || !in_array(strtolower((string) ($p['scheme'] ?? '')), ['https', 'http'], true)) {
            return null;
        }
        return self::sameOrigin($base, $link) ? $link : null;
    }
}
