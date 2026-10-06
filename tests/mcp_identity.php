<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (pentest F-02)
/* Disposable checkout only: RIVETIT_TEST_DB=1 php tests/mcp_identity.php */
if (getenv('RIVETIT_TEST_DB') !== '1') exit(2);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../mcp_server/McpIdentityMiddleware.php';

$issuer = 'https://mcp-test.example/realm';
$subject = 'immutable-test-subject';
$handler = new class implements Psr\Http\Server\RequestHandlerInterface {
    public function handle(Psr\Http\Message\ServerRequestInterface $request): Psr\Http\Message\ResponseInterface {
        $id = $request->getAttribute('oauth.user_id');
        return new Nyholm\Psr7\Response(is_int($id) && $id > 0 ? 200 : 500);
    }
};
$audience = 'rivetit-mcp';
$middleware = new McpIdentityMiddleware($mysqli, $issuer, $audience);
$request = (new Nyholm\Psr7\ServerRequest('POST', 'https://example.test/mcp'))
    ->withAttribute('oauth.subject', $subject)
    ->withAttribute('oauth.scopes', ['mcp:read'])
    ->withAttribute('oauth.claims', ['aud' => $audience, 'iat' => time(), 'exp' => time() + 300]);
mysqli_begin_transaction($mysqli);
try {
    $stmt = $mysqli->prepare('INSERT INTO users (user_name, user_email, user_password,
        user_type, user_status, user_oidc_issuer, user_oidc_subject)
        VALUES (?, ?, ?, 1, 1, ?, ?)');
    $name = 'MCP identity test'; $email = 'mcp-test@example.test'; $password = '';
    $stmt->bind_param('sssss', $name, $email, $password, $issuer, $subject);
    $stmt->execute();
    $id = $mysqli->insert_id;
    $check = static function (int $expected, Psr\Http\Message\ServerRequestInterface $r) use ($middleware, $handler): void {
        if ($middleware->process($r, $handler)->getStatusCode() !== $expected) {
            throw new RuntimeException('Unexpected MCP identity status: ' . $expected);
        }
    };
    $check(200, $request);
    $check(403, $request->withAttribute('oauth.scopes', ['openid']));
    $check(403, $request->withAttribute('oauth.claims', ['aud' => $audience, 'iat' => time(), 'exp' => time() + 7200]));
    $check(403, $request->withAttribute('oauth.claims', ['aud' => [$audience, 'other-service'], 'iat' => time(), 'exp' => time() + 300]));
    $check(403, $request->withAttribute('oauth.subject', 'unmapped'));
    mysqli_query($mysqli, "UPDATE users SET user_status=0 WHERE user_id=$id");
    $check(403, $request);
    echo "MCP identity binding checks passed.\n";
} finally {
    mysqli_rollback($mysqli);
}
