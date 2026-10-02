<?php
/* Department Portal Odoo authorization-code client. No browser-supplied host is used. */

function portalOdooBaseUrl(string $value): ?string
{
    $value = rtrim(trim($value), '/');
    $parts = parse_url($value);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
        || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['query']) || isset($parts['fragment'])
        || !in_array($parts['path'] ?? '', ['', '/'], true)) {
        return null;
    }
    return $value;
}

function portalOdooBase64Url(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

function portalOdooPendingValid(mixed $pending, mixed $state, string $issuer,
    int $integrationId, int $now): bool
{
    if (!is_array($pending) || !is_string($state) || strlen($state) !== 43
        || !is_string($pending['state'] ?? null)
        || !hash_equals($pending['state'], $state)
        || !is_string($pending['verifier'] ?? null)
        || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $pending['verifier'])) {
        return false;
    }
    $created = $pending['created'] ?? null;
    return is_int($created) && $created <= $now && $now - $created <= 300
        && ($pending['issuer'] ?? null) === $issuer
        && ($pending['integration_id'] ?? null) === $integrationId;
}

function portalOdooPost(string $baseUrl, string $database, string $path,
    string $secret, array $fields): array
{
    if (portalOdooBaseUrl($baseUrl) !== $baseUrl
        || !preg_match('/^[A-Za-z0-9_.-]{1,100}$/D', $database)
        || !in_array($path, ['/rivetit/sso/token', '/rivetit/sso/health'], true)) {
        throw new RuntimeException('invalid_config');
    }
    $ch = curl_init($baseUrl . $path);
    if ($ch === false) {
        throw new RuntimeException('provider_unavailable');
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields, '', '&', PHP_QUERY_RFC3986),
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Bearer ' . $secret,
            'X-Odoo-Database: ' . $database,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($status === 400 || $status === 401 || $status === 403) {
        throw new RuntimeException('provider_rejected');
    }
    if (!is_string($body) || strlen($body) > 4096 || $status !== 200) {
        throw new RuntimeException('provider_unavailable');
    }
    $json = json_decode($body, true, 8);
    if (!is_array($json)) {
        throw new RuntimeException('invalid_provider_response');
    }
    return $json;
}

function portalOdooTokenExchange(string $baseUrl, string $database, string $clientId, string $secret,
    string $code, string $verifier, string $callback): array
{
    return portalOdooPost($baseUrl, $database, '/rivetit/sso/token', $secret, [
        'client_id' => $clientId, 'code' => $code,
        'code_verifier' => $verifier, 'redirect_uri' => $callback,
    ]);
}

function portalOdooHealthCheck(string $baseUrl, string $database, string $clientId, string $secret): array
{
    return portalOdooPost($baseUrl, $database, '/rivetit/sso/health', $secret,
        ['client_id' => $clientId]);
}

function portalOdooIdentityValid(array $identity, string $issuer, string $database, int $companyId): bool
{
    if (($identity['issuer'] ?? null) !== $issuer
        || ($identity['database'] ?? null) !== $database
        || ($identity['company_id'] ?? null) !== $companyId) {
        return false;
    }
    foreach (['user_id', 'employee_id'] as $key) {
        if (!is_int($identity[$key] ?? null) || $identity[$key] <= 0) {
            return false;
        }
    }
    return true;
}

function portalOdooEligibleAccount(mysqli $mysqli, int $integrationId, int $employeeId): ?array
{
    // The sync link is keyed by integration and immutable Odoo employee ID.
    // LIMIT 2 deliberately fails closed if old data contains duplicate links.
    $sql = "SELECT users.user_id, contacts.contact_id, contacts.contact_client_id
        FROM contact_odoo_links AS links
        INNER JOIN contacts ON contacts.contact_id = links.contact_id
        INNER JOIN users ON users.user_id = contacts.contact_user_id
        INNER JOIN clients ON clients.client_id = contacts.contact_client_id
        WHERE links.odoo_integration_id = ? AND links.odoo_employee_id = ?
          AND users.user_auth_method = 'odoo' AND users.user_type = 2
          AND users.user_status = 1 AND users.user_archived_at IS NULL
          AND contacts.contact_archived_at IS NULL
          AND contacts.contact_employment_status = 'active'
          AND clients.client_archived_at IS NULL AND clients.client_status = 'Active'
        LIMIT 2";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('ii', $integrationId, $employeeId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    return count($rows) === 1 ? $rows[0] : null;
}
