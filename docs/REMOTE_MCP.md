# Remote MCP (Experimental module)

Everything is configured in **Administration → Settings → Remote MCP**: the on/off switch, the identity provider's issuer and audience, health checks, and linking people to agents. It is off by default. Apply database update 2.6.123 first. Turning it off blocks both the endpoint and its OAuth metadata.

RivetIT's MCP endpoint is `/mcp`. It is **disabled by default** and exposes only **read-only** tools. It never forwards an OAuth token to the RivetIT API or uses a long-lived RivetIT API key. Billing changes and other write operations are not exposed.

## Tools

Every tool rechecks the signed-in agent's RivetIT role and client restrictions (the same "no rows in user client permissions means all clients" rule the agent UI uses). Out-of-scope records answer `NOT_FOUND`, exactly like a missing one.

| Tool | Needs role module | Notes |
| --- | --- | --- |
| `rivetit_my_profile` | any agent | Name and email of the signed-in agent |
| `rivetit_recent_tickets` / `rivetit_search_tickets` | Tickets (`module_support`) | Open tickets by default; subject, number or details search |
| `rivetit_get_ticket` | Tickets | Description (HTML stripped) plus the 10 most recent replies |
| `rivetit_search_assets` / `rivetit_get_asset` | Assets or Tickets | Never returns PINs, URIs or credentials |
| `rivetit_list_clients` / `rivetit_search_contacts` | Departments (`module_client`) | Contact PINs are never returned |
| `rivetit_search_kb` / `rivetit_get_kb_article` | Knowledge Base (`module_kb`) | Global articles plus the agent's allowed clients; plain text, 20,000 character cap |

Searches return at most 25 rows. Every response uses one envelope: `{"success": true|false, "request_id": "req_…", "data": …, "errors": [{"code", "message"}]}` with codes `PERMISSION_DENIED`, `NOT_FOUND`, `RATE_LIMITED` and `INTERNAL_ERROR`. Internal error text is written to the server log only. Because the failure is carried in `success`, a failed call still has `isError: false` at the MCP protocol level; clients should read the envelope.

**Audit and limits.** Each call writes an `mcp.tool_call` row to `audit_events` (tool, outcome, truncated arguments, row count, and the server-generated request id, which is also returned in the `X-Request-ID` header). Each agent is limited to 60 calls per minute using Redis; if Redis is unavailable the limit is skipped rather than blocking reads.

## Identity provider

Use a trusted OAuth authorization server that can issue signed RS256 JWT **access tokens** for a dedicated RivetIT MCP audience. It must publish standard OAuth/OIDC discovery metadata and JWKS, support authorization-code with PKCE for the MCP client, and issue the `mcp:read` scope. Tokens must contain an immutable `sub`, the exact configured issuer and audience, and expire within one hour. The configured MCP audience must be the token's only audience, whether `aud` is a string or a one-element array. An OIDC ID token for a different audience cannot be used as an MCP access token.

### Set up in the admin page

1. Open **Settings → Remote MCP**, enter the **Issuer URL** and **Audience**, turn it on, and press **Save and run checks**. The checks contact your identity provider and this server and tell you in plain language what to fix: issuer typos (including a missing trailing slash), missing RSA signing keys, no PKCE, a missing `mcp:read` scope, and a web server that does not route `/mcp`.
2. Give your MCP client the address shown on the page (`https://YOUR-RIVETIT-HOST/mcp`).
3. Have the person connect once from their MCP client. They will be refused, and appear under **People who tried to connect** with the name and email from their token. Choose their agent (the one whose email matches is preselected) and press **Link**. Only tokens that already passed every signature, issuer, audience, scope and expiry check are listed. Entries are cleared after 30 days, and at most 200 are kept.
4. Have them connect again. **Linked agents** lets you unlink someone, which stops their access immediately; **Recent activity** shows their calls.

You can still link by hand under **Administration → Users → Remote MCP identity** if you already know the subject.

The server environment can override or stop all of this. `RIVETIT_MCP_ISSUER` and `RIVETIT_MCP_AUDIENCE`, if set in the PHP-FPM pool, take precedence over the saved values (the page shows them read-only). `RIVETIT_MCP_ENABLED=0` is a hard off switch that no setting can undo. Since 2.6.123 the environment no longer has to enable it; turning it on in the admin page is enough.

The issuer must match the token's `iss` claim exactly, including any trailing slash, and the audience must match `aud` exactly. The server's protected-resource metadata is at `/.well-known/oauth-protected-resource`. The Apache `.htaccess` and the supplied Nginx template route both paths to the endpoint; an existing Nginx install needs the two `location` blocks shown under "Web server setup" on the page.

Test the OAuth flow in an MCP client with a dedicated low-privilege agent before wider use. Confirm anonymous requests receive a 401 challenge with a `resource_metadata` URL, valid tokens can discover and call the tools, and invalid or additional audiences, expired token, missing scope, disabled user, and out-of-scope tickets fail. This preview has not yet completed a live identity-provider handshake; leave it disabled until that test passes.

To stop all MCP access, switch it off on the Remote MCP page (or set `RIVETIT_MCP_ENABLED=0` and reload PHP-FPM for a switch no one can flip from the UI). To revoke one agent, press Unlink on that page and revoke active tokens at the identity provider if immediate token invalidation is required.
