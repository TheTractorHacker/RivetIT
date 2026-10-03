<?php
/* Offline validation of signed, audience-bound MCP access tokens. */
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../mcp_server/McpIdentityMiddleware.php';

use Firebase\JWT\JWT;
use Mcp\Server\Transport\Http\OAuth\JwksProviderInterface;
use Mcp\Server\Transport\Http\OAuth\JwtTokenValidator;

$key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
openssl_pkey_export($key, $private);
$details = openssl_pkey_get_details($key);
$provider = new class($details) implements JwksProviderInterface {
    public function __construct(private array $details) {}
    public function getJwks(string $issuer, ?string $jwksUri = null): array {
        return ['keys' => [['kty' => 'RSA', 'kid' => 'test', 'alg' => 'RS256', 'use' => 'sig',
            'n' => rtrim(strtr(base64_encode($this->details['rsa']['n']), '+/', '-_'), '='),
            'e' => rtrim(strtr(base64_encode($this->details['rsa']['e']), '+/', '-_'), '=')]]];
    }
};
$validator = new JwtTokenValidator('https://issuer.example.test', 'rivetit-mcp', $provider,
    algorithms: ['RS256']);
$claims = ['iss' => 'https://issuer.example.test', 'aud' => 'rivetit-mcp',
    'sub' => 'agent-subject', 'scope' => 'mcp:read', 'iat' => time(), 'exp' => time() + 300];
$token = static fn(array $c): string => JWT::encode($c, $private, 'RS256', 'test');
if (!$validator->validate($token($claims))->isAllowed()) throw new RuntimeException('Valid MCP token rejected');
$sharedAudienceClaims = array_replace($claims, ['aud' => ['rivetit-mcp', 'different-app']]);
if (!$validator->validate($token($sharedAudienceClaims))->isAllowed()
    || McpIdentityMiddleware::hasDedicatedAudience($sharedAudienceClaims, 'rivetit-mcp')) {
    throw new RuntimeException('Shared-audience token must be rejected by RivetIT after SDK validation');
}
foreach ([['aud' => 'different-app'], ['iss' => 'https://evil.example.test'], ['exp' => time() - 10]] as $change) {
    if ($validator->validate($token(array_replace($claims, $change)))->isAllowed()) {
        throw new RuntimeException('Invalid MCP token accepted');
    }
}
foreach (['rivetit-mcp', ['rivetit-mcp']] as $audience) {
    if (!McpIdentityMiddleware::hasDedicatedAudience(['aud' => $audience], 'rivetit-mcp')) {
        throw new RuntimeException('Dedicated MCP audience rejected');
    }
}
foreach ([null, 'different-app', ['rivetit-mcp', 'different-app'], []] as $audience) {
    if (McpIdentityMiddleware::hasDedicatedAudience(['aud' => $audience], 'rivetit-mcp')) {
        throw new RuntimeException('Non-dedicated MCP audience accepted');
    }
}
echo "MCP JWT signature, issuer, audience and expiry checks passed.\n";
