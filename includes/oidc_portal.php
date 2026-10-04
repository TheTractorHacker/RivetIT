<?php
/* Department Portal OpenID Connect authorization-code client. */

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;

function portalOidcIssuerValid(string $issuer): bool
{
    $parts = parse_url($issuer);
    return is_array($parts)
        && ($parts['scheme'] ?? '') === 'https'
        && !empty($parts['host'])
        && !isset($parts['user']) && !isset($parts['pass'])
        && !isset($parts['query']) && !isset($parts['fragment']);
}

function portalOidcEndpointValid(string $endpoint, string $issuer): bool
{
    if (!portalOidcIssuerValid($issuer)) {
        return false;
    }
    $a = parse_url($issuer);
    $b = parse_url($endpoint);
    return is_array($b)
        && ($b['scheme'] ?? '') === 'https'
        && strcasecmp((string) ($b['host'] ?? ''), (string) $a['host']) === 0
        && ($b['port'] ?? 443) === ($a['port'] ?? 443)
        && !isset($b['user']) && !isset($b['pass']) && !isset($b['fragment']);
}

function portalOidcHttp(string $url, ?array $post = null, ?string $bearer = null, ?array $basic = null): array
{
    $ch = curl_init($url);
    $headers = ['Accept: application/json'];
    if ($bearer !== null) {
        $headers[] = 'Authorization: Bearer ' . $bearer;
    }
    if ($basic !== null) {
        $headers[] = 'Authorization: Basic ' . base64_encode(rawurlencode($basic[0]) . ':' . rawurlencode($basic[1]));
    }
    if ($post !== null) {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_MAXREDIRS => 0,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post, '', '&', PHP_QUERY_RFC3986));
    }
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($body === false || $status !== 200 || strlen($body) > 262144) {
        // Endpoint path, HTTP status and the standard OAuth error code only; never the body, URL query or credentials.
        $path = (string) parse_url($url, PHP_URL_PATH);
        $code = '';
        if (is_string($body)) {
            $decoded = json_decode($body, true, 8);
            if (is_array($decoded) && is_string($decoded['error'] ?? null) && preg_match('/^[a-z_]{1,40}$/', $decoded['error'])) {
                $code = ' ' . $decoded['error'];
            }
        }
        throw new RuntimeException('The identity provider did not return a valid response from ' . $path
            . ' (' . ($body === false ? 'no response, ' . curl_errno($ch) : 'HTTP ' . $status) . $code . ').');
    }
    $json = json_decode($body, true, 16);
    if (!is_array($json)) {
        throw new RuntimeException('The identity provider returned invalid JSON.');
    }
    return $json;
}

function portalOidcDiscovery(string $issuer): array
{
    if (!portalOidcIssuerValid($issuer)) {
        throw new RuntimeException('Invalid identity provider issuer.');
    }
    $document = portalOidcHttp(rtrim($issuer, '/') . '/.well-known/openid-configuration');
    if (($document['issuer'] ?? null) !== $issuer) {
        throw new RuntimeException('Identity provider issuer mismatch.');
    }
    foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri', 'userinfo_endpoint'] as $key) {
        if (!is_string($document[$key] ?? null) || !portalOidcEndpointValid($document[$key], $issuer)) {
            throw new RuntimeException('Identity provider endpoint is invalid.');
        }
    }
    return $document;
}

function portalOidcTokenAuthMethod(array $document): string
{
    $methods = $document['token_endpoint_auth_methods_supported'] ?? ['client_secret_basic'];
    if (!is_array($methods)) {
        throw new RuntimeException('Invalid token authentication metadata.');
    }
    if (in_array('client_secret_basic', $methods, true)) {
        return 'client_secret_basic';
    }
    if (in_array('client_secret_post', $methods, true)) {
        return 'client_secret_post';
    }
    throw new RuntimeException('The provider does not support a confidential client secret.');
}

function portalOidcBase64Url(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

function portalOidcValidateToken(string $idToken, array $keys, string $issuer, string $clientId, string $nonce, string $accessToken): string
{
    $pieces = explode('.', $idToken);
    if (count($pieces) !== 3 || strlen($idToken) > 32768) {
        throw new RuntimeException('Invalid identity token.');
    }
    $encodedHeader = base64_decode(strtr($pieces[0], '-_', '+/'), true);
    $header = is_string($encodedHeader) ? json_decode($encodedHeader, true) : null;
    $alg = is_array($header) ? ($header['alg'] ?? null) : null;
    if (!in_array($alg, ['RS256', 'ES256'], true)) {
        throw new RuntimeException('Unsupported identity token signature.');
    }
    $claims = JWT::decode($idToken, JWK::parseKeySet($keys, $alg));
    if (($claims->iss ?? null) !== $issuer || !is_string($claims->sub ?? null)
        || $claims->sub === '' || strlen($claims->sub) > 255) {
        throw new RuntimeException('Identity token issuer or subject mismatch.');
    }
    $exp = $claims->exp ?? null;
    $iat = $claims->iat ?? null;
    if (!(is_int($exp) || (is_float($exp) && is_finite($exp)))
        || !(is_int($iat) || (is_float($iat) && is_finite($iat)))
        || $exp <= time() || $iat > time() + 60 || $iat >= $exp) {
        throw new RuntimeException('Identity token lifetime is invalid.');
    }
    $audiences = is_array($claims->aud ?? null) ? $claims->aud : [$claims->aud ?? null];
    // No additional trusted ID-token audiences are configured for this client.
    if (count($audiences) !== 1 || $audiences[0] !== $clientId
        || (isset($claims->azp) && $claims->azp !== $clientId)
        || !is_string($claims->nonce ?? null)
        || !hash_equals($nonce, $claims->nonce)) {
        throw new RuntimeException('Identity token audience or browser binding mismatch.');
    }
    if (isset($claims->at_hash)) {
        $expected = portalOidcBase64Url(substr(hash('sha256', $accessToken, true), 0, 16));
        if (!is_string($claims->at_hash) || !hash_equals($expected, $claims->at_hash)) {
            throw new RuntimeException('Identity token access-token binding mismatch.');
        }
    }
    return $claims->sub;
}

function portalOidcEligibleAccount(mysqli $mysqli, string $issuer, string $subject): ?array
{
    $sql = "SELECT users.user_id, users.user_email, contacts.contact_id, contacts.contact_client_id
        FROM users
        INNER JOIN contacts ON contacts.contact_user_id = users.user_id
        INNER JOIN clients ON clients.client_id = contacts.contact_client_id
        WHERE users.user_oidc_issuer = ? AND users.user_oidc_subject = ? AND users.user_auth_method = 'oidc'
          AND users.user_type = 2 AND users.user_status = 1 AND users.user_archived_at IS NULL
          AND contacts.contact_archived_at IS NULL AND clients.client_archived_at IS NULL
        LIMIT 2";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('ss', $issuer, $subject);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    return count($rows) === 1 ? $rows[0] : null;
}

/*
 * First sign-in linking (optional, admin-enabled). Only a login already set to OpenID Connect with no subject
 * stored yet is eligible, only on a provider-verified email that matches exactly one such login, and the
 * subject is claimed with a single conditional UPDATE so two racing sign-ins cannot both link.
 * Returns the account row like portalOidcEligibleAccount(), or null.
 */
function portalOidcLinkByVerifiedEmail(mysqli $mysqli, string $issuer, string $subject, array $userinfo, ?string &$why = null, bool $requireVerified = true): ?array
{
    $email = $userinfo['email'] ?? null;
    if (!is_string($email) || $email === '' || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $why = 'provider_sent_no_valid_email';
        return null;
    }
    if ($requireVerified && ($userinfo['email_verified'] ?? null) !== true) {
        $why = 'email_verified_is_' . (array_key_exists('email_verified', $userinfo) ? gettype($userinfo['email_verified']) : 'missing');
        return null;
    }
    $sql = "SELECT users.user_id, users.user_email, contacts.contact_id, contacts.contact_client_id
        FROM users
        INNER JOIN contacts ON contacts.contact_user_id = users.user_id
        INNER JOIN clients ON clients.client_id = contacts.contact_client_id
        WHERE users.user_auth_method = 'oidc' AND (users.user_oidc_subject IS NULL OR users.user_oidc_subject = '')
          AND LOWER(users.user_email) = LOWER(?)
          AND users.user_type = 2 AND users.user_status = 1 AND users.user_archived_at IS NULL
          AND contacts.contact_archived_at IS NULL AND clients.client_archived_at IS NULL
        LIMIT 2";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    if (count($rows) !== 1) {
        $why = count($rows) === 0 ? 'no_blank_oidc_login_with_that_email' : 'more_than_one_login_with_that_email';
        return null;
    }
    $userId = (int) $rows[0]['user_id'];
    try {
        $claim = $mysqli->prepare("UPDATE users SET user_oidc_issuer = ?, user_oidc_subject = ?
            WHERE user_id = ? AND user_auth_method = 'oidc' AND (user_oidc_subject IS NULL OR user_oidc_subject = '')");
        $claim->bind_param('ssi', $issuer, $subject, $userId);
        $claim->execute();
    } catch (mysqli_sql_exception $e) {
        $why = 'subject_already_linked_elsewhere';
        return null;
    }
    if ($claim->affected_rows !== 1) {
        $why = 'login_already_linked';
        return null;
    }
    return $rows[0];
}
