<?php

namespace ITFlow\Integrations\Microsoft;

/**
 * Builds a GraphClient for a microsoft_integrations row: decrypted secret, shared (encrypted) token cache, optional time limit.
 * The endpoints default to Microsoft's. For a local mock or a sovereign cloud, config.php (never the web UI) may define
 * RIVETIT_GRAPH_BASE_URL (e.g. https://graph.microsoft.us/v1.0) and RIVETIT_GRAPH_AUTHORITY_URL (e.g. https://login.microsoftonline.us).
 */
final class GraphClientFactory
{
    /**
     * @param array<string,mixed> $row a microsoft_integrations row
     * @param array<string,mixed> $options GraphClient options; ['graph_base'=>..., 'authority'=>...] are for tests only
     */
    public static function forRow(\mysqli $mysqli, array $row, ?int $deadline = null, array $options = []): GraphClient
    {
        $tenant = (string) ($row['tenant_id'] ?? '');
        $client = (string) ($row['client_id'] ?? '');
        $cache = new MysqliGraphTokenCache($mysqli, (int) ($row['microsoft_integration_id'] ?? 0), $tenant, $client);

        return new GraphClient(
            $tenant,
            $client,
            decryptSetting((string) ($row['client_secret_enc'] ?? '')),
            $cache,
            (string) ($options['graph_base'] ?? (defined('RIVETIT_GRAPH_BASE_URL') ? RIVETIT_GRAPH_BASE_URL : GraphClient::DEFAULT_GRAPH_BASE)),
            (string) ($options['authority'] ?? (defined('RIVETIT_GRAPH_AUTHORITY_URL') ? RIVETIT_GRAPH_AUTHORITY_URL : GraphClient::DEFAULT_AUTHORITY)),
            ($deadline !== null ? ['deadline' => $deadline] : []) + $options
        );
    }
}
