<?php

namespace ITFlow\Integrations\ManageEngine;

/**
 * Persists the access token in manageengine_mdm_integrations (token_cache_enc, token_expires_at), encrypted with encryptSetting()
 * like the client secret, and bound to the data center + client id + refresh token it was issued for: changing any of them can
 * never reuse the old token. Only a hash of the refresh token is part of the binding, never the token itself.
 */
final class MysqliMdmTokenStore implements MdmTokenStore
{
    public function __construct(
        private \mysqli $mysqli,
        private int $integrationId,
        private string $dataCenter,
        private string $clientId,
        private string $refreshToken
    ) {
    }

    private function binding(): string
    {
        return hash('sha256', $this->dataCenter . '|' . $this->clientId . '|' . $this->refreshToken);
    }

    public function get(): ?array
    {
        try {
            $res = mysqli_query($this->mysqli, 'SELECT token_cache_enc, UNIX_TIMESTAMP(token_expires_at) AS exp FROM manageengine_mdm_integrations WHERE id = ' . $this->integrationId);
        } catch (\Throwable) {
            return null;
        }
        $row = $res ? mysqli_fetch_assoc($res) : null;
        if (!$row || empty($row['token_cache_enc']) || empty($row['exp'])) {
            return null;
        }
        $data = json_decode(decryptSetting((string) $row['token_cache_enc']), true);
        if (!is_array($data) || ($data['k'] ?? '') !== $this->binding() || empty($data['t'])) {
            return null;
        }

        return ['token' => (string) $data['t'], 'expires_at' => (int) $row['exp']];
    }

    public function put(string $token, int $expiresAt): void
    {
        try {
            $enc = mysqli_real_escape_string($this->mysqli, encryptSetting((string) json_encode(['t' => $token, 'k' => $this->binding()])));
            mysqli_query($this->mysqli, "UPDATE manageengine_mdm_integrations SET token_cache_enc = '$enc', token_expires_at = FROM_UNIXTIME(" . (int) $expiresAt . ') WHERE id = ' . $this->integrationId);
        } catch (\Throwable) {
            // caching is an optimisation: never fail a sync because it could not be saved
        }
    }

    public function clear(): void
    {
        try {
            mysqli_query($this->mysqli, 'UPDATE manageengine_mdm_integrations SET token_cache_enc = NULL, token_expires_at = NULL WHERE id = ' . $this->integrationId);
        } catch (\Throwable) {
        }
    }
}
