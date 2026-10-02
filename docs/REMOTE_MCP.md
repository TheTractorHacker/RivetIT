# Remote MCP (preview)

RivetIT's MCP endpoint is `/mcp`. It is **disabled by default** and exposes only two read-only tools: the agent's profile and recent open tickets within that agent's existing ticket and department permissions. It never forwards an OAuth token to the RivetIT API or uses a long-lived RivetIT API key. Billing changes and other write operations are not exposed.

## Identity provider

Use a trusted OAuth authorization server that can issue signed RS256 JWT **access tokens** for a dedicated RivetIT MCP audience. It must publish standard OAuth/OIDC discovery metadata and JWKS, support authorization-code with PKCE for the MCP client, and issue the `mcp:read` scope. Tokens must contain an immutable `sub`, the exact configured issuer and audience, and expire within one hour. An OIDC ID token for a different audience cannot be used as an MCP access token.

Configure the PHP-FPM pool with these environment variables, then reload PHP-FPM. Keep the endpoint off until the identity provider and one test agent are ready:

```ini
env[RIVETIT_MCP_ENABLED] = 1
env[RIVETIT_MCP_ISSUER] = https://YOUR-AUTH-SERVER/REALM
env[RIVETIT_MCP_AUDIENCE] = YOUR-DEDICATED-MCP-AUDIENCE
```

The issuer must match the token's `iss` claim exactly, including any trailing slash. The audience must match `aud` exactly. Configure the authorization server to allow the client's redirect URI according to that client's OAuth setup. The server's protected-resource metadata is at `/.well-known/oauth-protected-resource`; the MCP client connects to `https://YOUR-RIVETIT-HOST/mcp`. The supplied Nginx template and root `.htaccess` route both paths to the same PHP endpoint.

In **Administration → Users**, edit the test agent and enter the same issuer plus that agent's immutable `sub` under **Remote MCP identity**. This is a manual one-to-one mapping to an active RivetIT agent. Clearing both fields revokes the mapping; disabling or archiving the agent also denies requests. RivetIT checks the signature, issuer, audience, expiry, `mcp:read` scope, and agent mapping on every request. The existing agent role and department limits are checked again by the ticket tool.

Test the OAuth flow in an MCP client with a dedicated low-privilege agent before wider use. Confirm anonymous requests receive a 401 challenge with a `resource_metadata` URL, valid tokens can discover and call the two tools, and invalid audience, expired token, missing scope, disabled user, and out-of-scope tickets fail. This preview has not yet completed a live identity-provider handshake; leave it disabled until that test passes.

To stop all MCP access, set `RIVETIT_MCP_ENABLED=0` and reload PHP-FPM. To revoke one agent, clear its mapping in Administration → Users and revoke active tokens at the identity provider if immediate token invalidation is required.
