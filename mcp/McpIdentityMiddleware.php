<?php

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Nyholm\Psr7\Response;

/** Bind every validated MCP access token to one active RivetIT agent. */
final class McpIdentityMiddleware implements MiddlewareInterface
{
    public function __construct(private mysqli $db, private string $issuer) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $subject = $request->getAttribute('oauth.subject');
        $scopes = $request->getAttribute('oauth.scopes');
        $claims = $request->getAttribute('oauth.claims');
        if (!is_string($subject) || $subject === '' || strlen($subject) > 255
            || !is_array($scopes) || !in_array('mcp:read', $scopes, true)
            || !is_array($claims) || !is_int($claims['exp'] ?? null)
            || !is_int($claims['iat'] ?? null)
            || $claims['iat'] > time() + 60
            || $claims['exp'] <= $claims['iat']
            || $claims['exp'] - $claims['iat'] > 3600) {
            return new Response(403, ['Cache-Control' => 'no-store']);
        }
        $stmt = $this->db->prepare('SELECT user_id FROM users
            WHERE user_oidc_issuer = ? AND user_oidc_subject = ? AND user_type = 1
              AND user_status = 1 AND user_archived_at IS NULL LIMIT 2');
        $stmt->bind_param('ss', $this->issuer, $subject);
        $stmt->execute();
        $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        if (count($users) !== 1) {
            return new Response(403, ['Cache-Control' => 'no-store']);
        }
        return $handler->handle($request->withAttribute('oauth.user_id', (int) $users[0]['user_id']));
    }
}
