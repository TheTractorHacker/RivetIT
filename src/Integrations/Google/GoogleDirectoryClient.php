<?php

namespace ITFlow\Integrations\Google;

use ITFlow\Integrations\ConnectionResult;

/**
 * Google Workspace Admin SDK Directory API client, authenticated as a
 * service account with domain-wide delegation (the standard way an
 * unattended background sync reads a Workspace directory - there is no
 * "app-only" Graph-style client-credentials flow for Google, so this signs
 * its own JWT-bearer assertion instead per
 * https://developers.google.com/identity/protocols/oauth2/service-account,
 * exchanges it at Google's OAuth token endpoint, then makes plain GET
 * calls against admin.googleapis.com/admin/directory/v1 - confirmed live
 * against Google's published REST reference for users.list and
 * orgunits.list on 2026-09-09 (endpoint hosts, query params, and response
 * shapes below are copied from there, not guessed).
 *
 * PHP has no built-in JWT signing, and per ground rules this app adds no
 * Composer dependency for it - openssl_sign() with the service account's
 * RSA private key (parsed out of the pasted JSON key) does the RS256
 * signing directly, mirroring how GraphClient hand-rolls its own OAuth2
 * client-credentials POST rather than pulling in a library.
 *
 * EXTERNAL SETUP THIS REQUIRES (this app cannot grant any of this for you -
 * it must be configured in the Google Cloud and Workspace Admin consoles,
 * the same honesty GraphClient's own docblock and the Microsoft card on
 * the Directory Sync settings tab already give for Entra admin consent):
 *   1. A GCP project, with the Admin SDK API enabled for it.
 *   2. A service account created in that project, with a JSON key
 *      downloaded (IAM & Admin -> Service Accounts -> Keys -> Add key ->
 *      JSON) - paste that whole file's contents into the Google Workspace
 *      card's "Service Account JSON" field.
 *   3. Domain-wide delegation authorized for that service account's
 *      numeric Client ID in the Workspace Admin console (Security -> API
 *      controls -> Domain-wide delegation -> Add new), with EXACTLY these
 *      two OAuth scopes (space-separated in the one "OAuth scopes" field
 *      the Admin console gives you):
 *        https://www.googleapis.com/auth/admin.directory.user.readonly
 *        https://www.googleapis.com/auth/admin.directory.orgunit.readonly
 *   4. A real, non-suspended super admin's email address in that Workspace
 *      to put in "Delegated Admin Email" - Google requires the JWT "sub"
 *      claim to be an actual admin for domain-wide delegation to work at
 *      all; the service account cannot impersonate itself.
 */
class GoogleDirectoryClient
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const DIRECTORY_BASE = 'https://admin.googleapis.com/admin/directory/v1';

    // Read-only is deliberate - this integration only ever pulls from
    // Google, mirroring OdooClient/GraphClient's one-way-pull posture.
    private const SCOPES = [
        'https://www.googleapis.com/auth/admin.directory.user.readonly',
        'https://www.googleapis.com/auth/admin.directory.orgunit.readonly',
    ];

    private ?string $accessToken = null;
    private int $tokenExpiresAt = 0;
    private ?array $serviceAccount = null;

    public function __construct(
        private readonly string $serviceAccountJson, // full contents of the downloaded key file, already decrypted by the caller
        private readonly string $delegatedAdminEmail,
        private readonly ?string $workspaceDomain = null // optional - restricts users.list to one domain; org units are always customer-scoped (Google's orgunits.list has no domain param)
    ) {
    }

    public function testConnection(): ConnectionResult
    {
        try {
            $this->authenticate();
        } catch (\RuntimeException $e) {
            return new ConnectionResult(false, $e->getMessage());
        }

        [$status, $body] = $this->request(self::DIRECTORY_BASE . '/users?' . $this->scopeParam() . '&maxResults=1&projection=basic');

        if ($status !== 200) {
            return new ConnectionResult(false, "Google Admin SDK returned HTTP $status: " . $this->extractGoogleError($body));
        }

        $data = json_decode($body, true);

        return new ConnectionResult(true, null, ['sampleUser' => $data['users'][0]['primaryEmail'] ?? null]);
    }

    /**
     * @return array[] raw Admin SDK orgunit resources, e.g.
     *   [{name, orgUnitPath, parentOrgUnitPath, orgUnitId, description}, ...]
     *   per https://developers.google.com/workspace/admin/directory/reference/rest/v1/orgunits/list
     */
    public function listOrgUnits(): array
    {
        $this->authenticate();

        // type=all returns the whole org unit tree in one call (not just direct
        // children of the root), matching how OdooClient::listDepartments()
        // hands GoogleDirectoryMapper::syncDepartments() the full flat list to
        // do its own two-pass parent resolution over.
        [$status, $body] = $this->request(self::DIRECTORY_BASE . '/customer/my_customer/orgunits?type=all');

        if ($status !== 200) {
            throw new \RuntimeException("Google Admin SDK returned HTTP $status listing org units: " . $this->extractGoogleError($body));
        }

        $data = json_decode($body, true);

        return $data['organizationUnits'] ?? [];
    }

    /**
     * Fully paginated. Returns raw Admin SDK user resources (NOT wrapped in
     * ExternalUser - GoogleDirectoryMapper::syncEmployees() needs the
     * provider-native shape, e.g. organizations[].title, phones[], the same
     * way OdooClient::listEmployees() hands OdooDirectoryMapper raw
     * hr.employee dicts rather than ExternalUser objects), e.g.
     *   [{id, primaryEmail, name:{fullName,...}, orgUnitPath, suspended,
     *     organizations:[{title, primary, ...}], phones:[{value, type, ...}]}, ...]
     * per https://developers.google.com/workspace/admin/directory/reference/rest/v1/users/list
     *
     * projection=full is used deliberately, even though organizations/phones
     * are standard (non-custom-schema) fields already included at the
     * default "basic" projection per Google's docs - "full" is the
     * unambiguous, explicit choice for "give us everything" rather than
     * relying on that basic/full distinction staying exactly as documented.
     *
     * @return array[]
     */
    public function listUsers(): array
    {
        $this->authenticate();

        $users = [];
        $pageToken = null;

        do {
            $url = self::DIRECTORY_BASE . '/users?' . $this->scopeParam() . '&maxResults=200&projection=full&viewType=admin_view';
            if ($pageToken !== null) {
                $url .= '&pageToken=' . rawurlencode($pageToken);
            }

            [$status, $body] = $this->request($url);
            if ($status !== 200) {
                throw new \RuntimeException("Google Admin SDK returned HTTP $status listing users: " . $this->extractGoogleError($body));
            }

            $data = json_decode($body, true);
            foreach ($data['users'] ?? [] as $u) {
                $users[] = $u;
            }

            $pageToken = $data['nextPageToken'] ?? null;
        } while ($pageToken !== null);

        return $users;
    }

    // users.list is scoped either to one domain or to the whole customer -
    // Google's API does not accept both params at once. Org units have no
    // such param at all (see listOrgUnits()'s fixed my_customer path).
    private function scopeParam(): string
    {
        if ($this->workspaceDomain !== null && trim($this->workspaceDomain) !== '') {
            return 'domain=' . rawurlencode(trim($this->workspaceDomain));
        }

        return 'customer=my_customer';
    }

    private function parseServiceAccount(): array
    {
        if ($this->serviceAccount !== null) {
            return $this->serviceAccount;
        }

        $data = json_decode($this->serviceAccountJson, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Google service account JSON is not valid JSON - paste the full, unmodified contents of the downloaded key file.');
        }

        foreach (['client_email', 'private_key'] as $required) {
            if (empty($data[$required])) {
                throw new \RuntimeException("Google service account JSON is missing \"$required\" - re-download the key file from the GCP console (IAM & Admin -> Service Accounts -> Keys).");
            }
        }

        if (($data['type'] ?? '') !== 'service_account') {
            throw new \RuntimeException('This does not look like a Google service account key file (missing "type": "service_account").');
        }

        $this->serviceAccount = $data;

        return $data;
    }

    private function authenticate(): void
    {
        // 60s safety margin so a request that starts just before expiry doesn't
        // get a token that dies mid-flight.
        if ($this->accessToken !== null && time() < $this->tokenExpiresAt - 60) {
            return;
        }

        $sa = $this->parseServiceAccount();

        if (trim($this->delegatedAdminEmail) === '') {
            throw new \RuntimeException('A delegated admin email is required - Google needs the JWT "sub" claim to be a real Workspace admin for domain-wide delegation to work.');
        }

        $now = time();
        $jwt = $this->buildAssertion($sa, $now);

        $postFields = http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]);

        $ch = curl_init(self::TOKEN_URL);
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
            throw new \RuntimeException("Could not reach Google's OAuth token endpoint: $curlError");
        }

        $data = json_decode($body, true);
        if ($status !== 200 || empty($data['access_token'])) {
            $desc = $data['error_description'] ?? $data['error'] ?? "HTTP $status";
            throw new \RuntimeException(
                "Google OAuth2 token request failed: $desc. Check that domain-wide delegation is authorized in the " .
                'Workspace Admin console for this service account\'s Client ID with the admin.directory.user.readonly ' .
                'and admin.directory.orgunit.readonly scopes, and that "' . $this->delegatedAdminEmail . '" is a real, ' .
                'non-suspended super admin in this Workspace.'
            );
        }

        $this->accessToken = $data['access_token'];
        $this->tokenExpiresAt = $now + intval($data['expires_in'] ?? 3600);
    }

    // Builds and RS256-signs the JWT-bearer assertion per
    // https://developers.google.com/identity/protocols/oauth2/service-account#authorizingrequests
    private function buildAssertion(array $sa, int $now): string
    {
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss' => $sa['client_email'],
            'sub' => $this->delegatedAdminEmail,
            'scope' => implode(' ', self::SCOPES),
            'aud' => self::TOKEN_URL,
            'iat' => $now,
            'exp' => $now + 3600, // Google's documented max - it rejects anything longer
        ];

        $signingInput = $this->base64UrlEncode(json_encode($header)) . '.' . $this->base64UrlEncode(json_encode($claims));

        $privateKey = openssl_pkey_get_private($sa['private_key']);
        if ($privateKey === false) {
            throw new \RuntimeException('Could not load the private key from the Google service account JSON: ' . openssl_error_string());
        }

        $signature = '';
        $signed = openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        if (!$signed) {
            throw new \RuntimeException('Failed to RS256-sign the Google service account JWT: ' . openssl_error_string());
        }

        return $signingInput . '.' . $this->base64UrlEncode($signature);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * @return array{0: int, 1: string} [http status, response body]
     */
    private function request(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->accessToken],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException("Could not reach Google Admin SDK Directory API: $curlError");
        }

        return [$status, $body];
    }

    private function extractGoogleError(string $body): string
    {
        $data = json_decode($body, true);

        return $data['error']['message'] ?? substr($body, 0, 200);
    }
}
