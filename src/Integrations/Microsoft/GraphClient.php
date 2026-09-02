<?php

namespace ITFlow\Integrations\Microsoft;

use ITFlow\Integrations\ConnectionResult;
use ITFlow\Integrations\ExternalUser;

/**
 * Microsoft Graph client using the app-only client-credentials OAuth2 flow
 * (Section 9.1) - suitable for an unattended background sync, not
 * per-user delegated access (that's Section 9.3's separate SSO flow).
 *
 * Scaffolding per PROGRESS.md: real, working HTTP/OAuth2 code, but no
 * tenant/app registration exists yet to test it against. Requires Graph
 * application permissions User.Read.All (and Device.Read.All /
 * DeviceManagementManagedDevices.Read.All once Phase 7/Intune uses this)
 * granted with admin consent.
 */
class GraphClient
{
    private const TOKEN_URL_TEMPLATE = 'https://login.microsoftonline.com/%s/oauth2/v2.0/token';
    private const GRAPH_BASE = 'https://graph.microsoft.com/v1.0';

    private ?string $accessToken = null;

    public function __construct(
        private readonly string $tenantId,
        private readonly string $clientId,
        private readonly string $clientSecret
    ) {
    }

    public function testConnection(): ConnectionResult
    {
        try {
            $this->authenticate();
        } catch (\RuntimeException $e) {
            return new ConnectionResult(false, $e->getMessage());
        }

        [$status, $body] = $this->request('GET', '/organization?$select=displayName,id');
        if ($status !== 200) {
            return new ConnectionResult(false, "Graph API returned HTTP $status: " . $this->extractGraphError($body));
        }

        $data = json_decode($body, true);
        $org = $data['value'][0] ?? null;

        return new ConnectionResult(true, null, ['organization' => $org['displayName'] ?? null]);
    }

    /**
     * @return ExternalUser[]
     */
    public function listUsers(?string $nextLink = null): array
    {
        $this->authenticate();

        $path = $nextLink ?? '/users?$select=id,displayName,mail,userPrincipalName,accountEnabled&$top=100';
        [$status, $body] = $this->request('GET', $path, absoluteIfFullUrl: true);

        if ($status !== 200) {
            throw new \RuntimeException("Graph API returned HTTP $status: " . $this->extractGraphError($body));
        }

        $data = json_decode($body, true);
        $users = [];
        foreach ($data['value'] ?? [] as $u) {
            $users[] = new ExternalUser(
                externalId: $u['id'],
                displayName: $u['displayName'] ?? null,
                email: $u['mail'] ?? $u['userPrincipalName'] ?? null,
                enabled: (bool) ($u['accountEnabled'] ?? false),
                raw: $u
            );
        }

        return $users;
    }

    public function getUser(string $externalId): ?ExternalUser
    {
        $this->authenticate();

        [$status, $body] = $this->request('GET', '/users/' . rawurlencode($externalId) . '?$select=id,displayName,mail,userPrincipalName,accountEnabled');
        if ($status === 404) {
            return null;
        }
        if ($status !== 200) {
            throw new \RuntimeException("Graph API returned HTTP $status: " . $this->extractGraphError($body));
        }

        $u = json_decode($body, true);

        return new ExternalUser(
            externalId: $u['id'],
            displayName: $u['displayName'] ?? null,
            email: $u['mail'] ?? $u['userPrincipalName'] ?? null,
            enabled: (bool) ($u['accountEnabled'] ?? false),
            raw: $u
        );
    }

    private function authenticate(): void
    {
        if ($this->accessToken !== null) {
            return;
        }

        $tokenUrl = sprintf(self::TOKEN_URL_TEMPLATE, $this->tenantId);
        $postFields = http_build_query([
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'scope' => 'https://graph.microsoft.com/.default',
            'grant_type' => 'client_credentials',
        ]);

        $ch = curl_init($tokenUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException("Could not reach Microsoft login endpoint: $curlError");
        }

        $data = json_decode($body, true);
        if ($status !== 200 || empty($data['access_token'])) {
            $desc = $data['error_description'] ?? $data['error'] ?? "HTTP $status";
            throw new \RuntimeException("Microsoft OAuth2 token request failed: $desc");
        }

        $this->accessToken = $data['access_token'];
    }

    /**
     * @return array{0: int, 1: string} [http status, response body]
     */
    private function request(string $method, string $path, bool $absoluteIfFullUrl = false): array
    {
        $url = ($absoluteIfFullUrl && str_starts_with($path, 'https://')) ? $path : self::GRAPH_BASE . $path;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->accessToken,
                'Content-Type: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException("Could not reach Microsoft Graph: $curlError");
        }

        return [$status, $body];
    }

    private function extractGraphError(string $body): string
    {
        $data = json_decode($body, true);
        return $data['error']['message'] ?? substr($body, 0, 200);
    }
}
