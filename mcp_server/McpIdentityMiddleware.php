<?php

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Nyholm\Psr7\Response;

/** Bind every validated MCP access token to one active RivetIT agent. */
final class McpIdentityMiddleware implements MiddlewareInterface
{
    public function __construct(private mysqli $db, private string $issuer, private string $audience) {}

    /** The MCP audience must be the token's only intended recipient. */
    public static function hasDedicatedAudience(array $claims, string $audience): bool
    {
        return \RivetCore\Mcp\TokenClaimsGuard::hasDedicatedAudience($claims, $audience);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $subject = $request->getAttribute('oauth.subject');
        $scopes = $request->getAttribute('oauth.scopes');
        $claims = $request->getAttribute('oauth.claims');
        if (!\RivetCore\Mcp\TokenClaimsGuard::acceptable($subject, $scopes, $claims, $this->audience)) {
            return new Response(403, ['Cache-Control' => 'no-store']);
        }
        $stmt = $this->db->prepare('SELECT user_id FROM users
            WHERE user_oidc_issuer = ? AND user_oidc_subject = ? AND user_type = 1
              AND user_status = 1 AND user_archived_at IS NULL LIMIT 2');
        $stmt->bind_param('ss', $this->issuer, $subject);
        $stmt->execute();
        $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        if (count($users) !== 1) {
            // A valid token from someone not linked yet: remember them so an administrator can link them in the UI.
            if (count($users) === 0) {
                \ITFlow\Mcp\McpIdentityLinks::recordUnlinked($this->db, $this->issuer, $subject, $claims);
            }
            return new Response(403, ['Cache-Control' => 'no-store']);
        }
        return $handler->handle($request->withAttribute('oauth.user_id', (int) $users[0]['user_id']));
    }
}
