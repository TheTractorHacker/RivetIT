<?php
/* Run with: php tests/oidc_portal.php */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/oidc_portal.php';

use Firebase\JWT\JWT;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function rejects(callable $call, string $message): void
{
    try {
        $call();
    } catch (Throwable $error) {
        return;
    }
    throw new RuntimeException($message);
}

$issuer = 'https://identity.example.test/realms/staff';
check(portalOidcIssuerValid($issuer), 'HTTPS path issuer should be accepted');
check(portalOidcIssuerValid($issuer . '/'), 'Provider issuer with trailing slash should be accepted');
check(!portalOidcIssuerValid('http://identity.example.test'), 'HTTP issuer must be rejected');
check(!portalOidcIssuerValid('https://user@identity.example.test'), 'URL credentials must be rejected');
check(portalOidcEndpointValid($issuer . '/protocol/openid-connect/token', $issuer), 'Same-origin endpoint should work');
check(!portalOidcEndpointValid('https://evil.example.test/token', $issuer), 'Cross-origin endpoint must be rejected');
check(portalOidcTokenAuthMethod([]) === 'client_secret_basic', 'Default token auth must be Basic');
check(portalOidcTokenAuthMethod(['token_endpoint_auth_methods_supported' => ['client_secret_post']]) === 'client_secret_post', 'POST token auth should be supported');
rejects(fn () => portalOidcTokenAuthMethod(['token_endpoint_auth_methods_supported' => ['none']]), 'Public token auth accepted');

$private = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
openssl_pkey_export($private, $privatePem);
$details = openssl_pkey_get_details($private);
$keys = ['keys' => [[
    'kty' => 'RSA', 'kid' => 'test-key', 'use' => 'sig', 'alg' => 'RS256',
    'n' => portalOidcBase64Url($details['rsa']['n']),
    'e' => portalOidcBase64Url($details['rsa']['e']),
]]];
$access = 'temporary-access-token';
$claims = [
    'iss' => $issuer, 'sub' => 'stable-user-42', 'aud' => 'rivetit-client',
    'iat' => time(), 'exp' => time() + 300, 'nonce' => 'browser-nonce',
    'at_hash' => portalOidcBase64Url(substr(hash('sha256', $access, true), 0, 16)),
];
$sign = static fn (array $body): string => JWT::encode($body, $privatePem, 'RS256', 'test-key');
$token = $sign($claims);
check(portalOidcValidateToken($token, $keys, $issuer, 'rivetit-client', 'browser-nonce', $access)
    === 'stable-user-42', 'Valid signed ID token should resolve the subject');
check(portalOidcValidateToken($sign([...$claims, 'azp' => 'rivetit-client']), $keys, $issuer,
    'rivetit-client', 'browser-nonce', $access) === 'stable-user-42', 'Matching authorized party should be accepted');
rejects(fn () => portalOidcValidateToken($token, $keys, $issuer, 'other-client', 'browser-nonce', $access), 'Wrong audience accepted');
rejects(fn () => portalOidcValidateToken($token, $keys, $issuer, 'rivetit-client', 'other-nonce', $access), 'Wrong nonce accepted');
rejects(fn () => portalOidcValidateToken($token, $keys, $issuer, 'rivetit-client', 'browser-nonce', 'other-access'), 'Wrong access token accepted');
rejects(fn () => portalOidcValidateToken($sign([...$claims, 'iss' => 'https://other.example.test']), $keys, $issuer,
    'rivetit-client', 'browser-nonce', $access), 'Wrong issuer accepted');
rejects(fn () => portalOidcValidateToken($sign([...$claims, 'exp' => time() - 300]), $keys, $issuer,
    'rivetit-client', 'browser-nonce', $access), 'Expired token accepted');
rejects(fn () => portalOidcValidateToken($sign(array_diff_key($claims, ['exp' => true])), $keys, $issuer,
    'rivetit-client', 'browser-nonce', $access), 'ID token without expiration accepted');
rejects(fn () => portalOidcValidateToken($sign(array_diff_key($claims, ['iat' => true])), $keys, $issuer,
    'rivetit-client', 'browser-nonce', $access), 'ID token without issued-at time accepted');
rejects(fn () => portalOidcValidateToken($sign([...$claims, 'exp' => (string) (time() + 300)]), $keys, $issuer,
    'rivetit-client', 'browser-nonce', $access), 'String expiration accepted');
rejects(fn () => portalOidcValidateToken($sign([...$claims, 'iat' => time() + 300, 'nbf' => time() - 1]), $keys, $issuer,
    'rivetit-client', 'browser-nonce', $access), 'Future issued-at time accepted when nbf is present');
rejects(fn () => portalOidcValidateToken($sign([...$claims, 'iat' => time() + 3600, 'exp' => time() + 600]), $keys, $issuer,
    'rivetit-client', 'browser-nonce', $access), 'Issued-at time after expiration accepted');
rejects(fn () => portalOidcValidateToken($sign([...$claims, 'azp' => 'other-client']), $keys, $issuer,
    'rivetit-client', 'browser-nonce', $access), 'Wrong authorized party accepted');
rejects(fn () => portalOidcValidateToken($sign([...$claims, 'aud' => ['rivetit-client', 'other-client'], 'azp' => 'rivetit-client']), $keys, $issuer,
    'rivetit-client', 'browser-nonce', $access), 'Untrusted extra audience accepted');
rejects(fn () => portalOidcValidateToken(substr($token, 0, -8) . 'abcdefgh', $keys, $issuer,
    'rivetit-client', 'browser-nonce', $access), 'Tampered signature accepted');

echo "OpenID Connect token and URL checks passed.\n";
