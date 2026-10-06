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

        // Account writes are gated by the separate 'Allow RivetIT to change Entra accounts' setting (off by default).
        $options += ['allow_writes' => self::writesAllowed($mysqli)];

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

    /** The 'Allow RivetIT to change Entra accounts' setting. False on any doubt (column missing, no settings row). */
    public static function writesAllowed(\mysqli $mysqli): bool
    {
        try {
            $res = mysqli_query($mysqli, 'SELECT config_entra_allow_writes FROM settings WHERE company_id = 1 LIMIT 1');
            $row = $res ? mysqli_fetch_row($res) : null;
        } catch (\Throwable) {
            return false;
        }

        return $row !== null && (int) $row[0] === 1;
    }
}
