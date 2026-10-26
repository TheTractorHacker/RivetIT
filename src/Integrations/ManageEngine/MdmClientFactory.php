<?php

namespace ITFlow\Integrations\ManageEngine;

/**
 * Builds an MdmClient for a manageengine_mdm_integrations row: decrypted secrets, the shared (encrypted) token store, optional time limit.
 *
 * Cloud: the hosts come from DataCenters (the row's data_center key); for a local mock only, config.php (never the web UI) may define
 * RIVETIT_MDM_BASE_URL and RIVETIT_MDM_ACCOUNTS_URL.
 * On-premises: server_url is vetted by RivetCore's UrlPolicy exactly like a webhook URL (public addresses, plus the internal networks an
 * administrator allowed under Administration > Webhooks; loopback, link-local and cloud metadata never) and the connection is pinned to
 * the vetted addresses. https only (plain http on loopback only behind the explicit test constant RIVETIT_MDM_ALLOW_HTTP_LOOPBACK).
 */
final class MdmClientFactory
{
    public const EDITION_CLOUD = 'cloud';
    public const EDITION_ONPREM = 'onprem';

    /**
     * @param array<string,mixed> $row a manageengine_mdm_integrations row
     * @param array<string,mixed> $options client options; ['mdm_base'=>..., 'accounts_base'=>...] are for tests only
     * @throws MdmException when an on-premises server URL is not allowed
     */
    public static function forRow(\mysqli $mysqli, array $row, ?int $deadline = null, array $options = []): ManageEngineMdmClient
    {
        $deadlineOpt = $deadline !== null ? ['deadline' => $deadline] : [];
        if (self::edition($row) === self::EDITION_ONPREM) {
            $vet = self::vetServerUrl($mysqli, (string) ($row['server_url'] ?? ''));
            if (!$vet['ok']) {
                throw new MdmException($vet['error'], MdmException::OTHER);
            }

            return ManageEngineMdmClient::onPrem($vet['url'], decryptSetting((string) ($row['api_key_enc'] ?? '')),
                $deadlineOpt + $options + ['pin' => $vet['target'], 'verify_tls' => (int) ($row['tls_verify'] ?? 1) === 1]);
        }

        $dc = DataCenters::normalize((string) ($row['data_center'] ?? ''));
        $clientId = (string) ($row['client_id'] ?? '');
        $refresh = decryptSetting((string) ($row['refresh_token_enc'] ?? ''));
        $store = new MysqliMdmTokenStore($mysqli, (int) ($row['id'] ?? 0), $dc, $clientId, $refresh);
        if (defined('RIVETIT_MDM_BASE_URL')) {
            $options += ['mdm_base' => RIVETIT_MDM_BASE_URL];
        }
        if (defined('RIVETIT_MDM_ACCOUNTS_URL')) {
            $options += ['accounts_base' => RIVETIT_MDM_ACCOUNTS_URL];
        }

        return new ManageEngineMdmClient($dc, $clientId, decryptSetting((string) ($row['client_secret_enc'] ?? '')), $refresh, $store, $deadlineOpt + $options);
    }

    /** @param array<string,mixed> $row */
    public static function edition(array $row): string
    {
        return ($row['edition'] ?? '') === self::EDITION_ONPREM ? self::EDITION_ONPREM : self::EDITION_CLOUD;
    }

    /**
     * Everything needed to talk to the server is saved (the secrets themselves are checked for presence only).
     *
     * @param array<string,mixed> $row
     */
    public static function hasCredentials(array $row): bool
    {
        if (self::edition($row) === self::EDITION_ONPREM) {
            return trim((string) ($row['server_url'] ?? '')) !== '' && !empty($row['api_key_enc']);
        }

        return trim((string) ($row['client_id'] ?? '')) !== '' && !empty($row['client_secret_enc']) && !empty($row['refresh_token_enc']);
    }

    /**
     * Vets an on-premises server URL (settings save, Test connection and every sync).
     *
     * @return array{ok:bool,error:string,url:string,target:?array{host:string,port:int,ips:list<string>}}
     */
    public static function vetServerUrl(\mysqli $mysqli, string $url): array
    {
        $fail = static fn (string $m): array => ['ok' => false, 'error' => $m, 'url' => '', 'target' => null];
        $url = trim($url);
        $parts = parse_url($url);
        if ($url === '' || !is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return $fail('Enter the MDM server address as https://host or https://host:port (no user name, query or fragment).');
        }
        $path = (string) ($parts['path'] ?? '');
        if ($path !== '' && $path !== '/') {
            return $fail(('Enter only the server address (https://host:port). ' . (defined('APP_NAME') ? APP_NAME : 'RivetIT') . ' adds /api/v1/mdm itself.'));
        }
        if (!function_exists('rivetWebhookUrlPolicy') && is_file(dirname(__DIR__, 3) . '/includes/event_bus.php')) {
            require_once dirname(__DIR__, 3) . '/includes/event_bus.php';
        }
        $loopbackTest = defined('RIVETIT_MDM_ALLOW_HTTP_LOOPBACK') && RIVETIT_MDM_ALLOW_HTTP_LOOPBACK === true;
        $scheme = strtolower((string) $parts['scheme']);
        try {
            ManageEngineMdmClient::assertTransport($url, $loopbackTest);
        } catch (MdmException) {
            return $fail('The MDM server address must start with https://.');
        }
        $policy = $loopbackTest && $scheme === 'http'
            ? new \RivetCore\Webhooks\UrlPolicy(true)
            : (function_exists('rivetWebhookUrlPolicy') ? rivetWebhookUrlPolicy($mysqli) : new \RivetCore\Webhooks\UrlPolicy(false));
        $target = $policy->vet($url);
        if ($target === null) {
            $rule = function_exists('rivetWebhookRuleText') ? rivetWebhookRuleText($mysqli) : 'public addresses only';

            return $fail('That MDM server address is not allowed (' . $rule . '). An MDM server on a private network must be inside a network listed under Administration > Webhooks > Allowed internal networks; loopback and link-local addresses are never allowed.');
        }
        $base = $scheme . '://' . (str_contains((string) $parts['host'], ':') ? '[' . trim((string) $parts['host'], '[]') . ']' : $parts['host']) . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');

        return ['ok' => true, 'error' => '', 'url' => $base, 'target' => $target];
    }

    /** The single connection row, or null before anything is saved / before the database update ran. @return array<string,mixed>|null */
    public static function row(\mysqli $mysqli): ?array
    {
        try {
            $res = mysqli_query($mysqli, 'SELECT * FROM manageengine_mdm_integrations ORDER BY id ASC LIMIT 1');
        } catch (\Throwable) {
            return null;
        }

        return $res ? (mysqli_fetch_assoc($res) ?: null) : null;
    }

    /** True when the connection is saved and switched on. Used to decide whether nav links and asset panels show. */
    public static function isEnabled(\mysqli $mysqli): bool
    {
        $row = self::row($mysqli);

        return $row !== null && (int) $row['enabled'] === 1 && self::hasCredentials($row);
    }
}
