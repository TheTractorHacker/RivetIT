<?php

namespace ITFlow\Integrations\Microsoft;

/**
 * Persists the Graph access token in microsoft_integrations (token_cache_enc, token_expires_at) so a cron run, the Test
 * Connection button and the Sync Now button share one token instead of each asking Entra for a new one. The token is
 * encrypted with encryptSetting() like the client secret, and bound to the tenant + client ID it was issued for, so
 * changing either can never reuse the old app's token.
 */
final class MysqliGraphTokenCache implements GraphTokenCache
{
    public function __construct(
        private \mysqli $mysqli,
        private int $integrationId,
        private string $tenantId,
        private string $clientId
    ) {
    }

    private function binding(): string
    {
        return hash('sha256', $this->tenantId . '|' . $this->clientId);
    }

    public function get(): ?array
    {
        try {
            $res = mysqli_query($this->mysqli, 'SELECT token_cache_enc, UNIX_TIMESTAMP(token_expires_at) AS exp FROM microsoft_integrations WHERE microsoft_integration_id = ' . $this->integrationId);
        } catch (\Throwable) {
            return null; // columns not there yet (database update pending)
        }
        $row = $res ? mysqli_fetch_assoc($res) : null;
        if (!$row || empty($row['token_cache_enc']) || empty($row['exp'])) {
            return null;
        }
        $plain = decryptSetting((string) $row['token_cache_enc']);
        $data = json_decode($plain, true);
        if (!is_array($data) || ($data['k'] ?? '') !== $this->binding() || empty($data['t'])) {
            return null;
        }

        return ['token' => (string) $data['t'], 'expires_at' => (int) $row['exp']];
    }

    public function put(string $token, int $expiresAt): void
    {
        try {
            $enc = mysqli_real_escape_string($this->mysqli, encryptSetting((string) json_encode(['t' => $token, 'k' => $this->binding()])));
            mysqli_query($this->mysqli, "UPDATE microsoft_integrations SET token_cache_enc = '$enc', token_expires_at = FROM_UNIXTIME(" . (int) $expiresAt . ') WHERE microsoft_integration_id = ' . $this->integrationId);
        } catch (\Throwable) {
            // caching is an optimisation: never fail a sync because it could not be saved
        }
    }

    public function clear(): void
    {
        try {
            mysqli_query($this->mysqli, 'UPDATE microsoft_integrations SET token_cache_enc = NULL, token_expires_at = NULL WHERE microsoft_integration_id = ' . $this->integrationId);
        } catch (\Throwable) {
        }
    }
}
