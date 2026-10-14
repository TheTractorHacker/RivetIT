<?php

namespace ITFlow\Integrations\ManageEngine;

/**
 * Builds an MdmClient for a manageengine_mdm_integrations row: decrypted secrets, the shared (encrypted) token store, optional time limit.
 * The hosts come from DataCenters (the row's data_center key). For a local mock only, config.php (never the web UI) may define
 * RIVETIT_MDM_BASE_URL and RIVETIT_MDM_ACCOUNTS_URL.
 */
final class MdmClientFactory
{
    /**
     * @param array<string,mixed> $row a manageengine_mdm_integrations row
     * @param array<string,mixed> $options MdmClient options; ['mdm_base'=>..., 'accounts_base'=>...] are for tests only
     */
    public static function forRow(\mysqli $mysqli, array $row, ?int $deadline = null, array $options = []): MdmClient
    {
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

        return new MdmClient(
            $dc,
            $clientId,
            decryptSetting((string) ($row['client_secret_enc'] ?? '')),
            $refresh,
            $store,
            ($deadline !== null ? ['deadline' => $deadline] : []) + $options
        );
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

        return $row !== null && (int) $row['enabled'] === 1 && (string) $row['refresh_token_enc'] !== '';
    }
}
