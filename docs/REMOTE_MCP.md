# Remote MCP (Experimental module)

Everything is configured in **Administration → Settings → Remote MCP**: the on/off switch, how people sign in, health checks, and linking people to agents. It is off by default. Apply database update 2.6.156 first (2.6.123 for the module itself). Turning the module off blocks the endpoint, its OAuth metadata and every OAuth endpoint.

RivetIT's MCP endpoint is `/mcp`. It exposes only **read-only** tools. It never forwards an OAuth token to the RivetIT API or uses a long-lived RivetIT API key, and billing or any other write operation is not exposed.

There are two ways for people to sign in. Pick one; they are exclusive.

| | **Built-in sign-in** (recommended) | **External identity provider** |
| --- | --- | --- |
| What you need | Nothing else | An OAuth server that issues RS256 JWT access tokens (for example Authentik) |
| Who authorizes | RivetIT: the normal agent sign-in (password, 2FA, passkey), then a consent screen | Your identity provider |
| Linking people to agents | Not needed: the person who signs in is the agent | An administrator links each token subject to an agent |
| Tokens | Opaque, issued and stored (hashed) by RivetIT | Signed JWTs verified against the provider's JWKS |
| Revoking | One click, effective on the next call | Unlink the agent; revoke at the provider for immediate token death |
| Client registration | Automatic (RFC 7591) or by an administrator | At the provider |

## Built-in sign-in

RivetIT acts as its own OAuth 2.1 authorization server, so an MCP client such as the Claude custom connector or ToolHive can connect with no static API key and no external identity provider. It is **off by default**.

### Turn it on

1. Open **Settings → Remote MCP**, tick **Turn on Remote MCP** and press **Save**. (You do not need an issuer or audience.)
2. In **Built-in sign-in**, tick **Use built-in sign-in** and press **Save**. Leave **Let MCP apps register themselves** on for Claude. Turn it off if you would rather add each app by hand (see below).
3. Make sure the web server routes the metadata address. Apache needs nothing (the bundled `.htaccess` has the rules). The supplied nginx template and `docker/nginx.conf` already contain the blocks. On an existing nginx install add the `/.well-known/oauth-authorization-server` block shown under **Setup help → Web server setup** on the page, then reload nginx. The `/oauth/*.php` addresses work with the standard PHP location and need nothing extra; the template additionally marks the token endpoints `Cache-Control: no-store`.

The address to give a client is `https://YOUR-RIVETIT-HOST/mcp` (shown with a Copy button on the page). The sign-in server details are discovered from it automatically.

### Add RivetIT to Claude

In Claude, open **Settings → Connectors → Add custom connector**, enter `https://YOUR-RIVETIT-HOST/mcp` and leave the OAuth client ID and secret empty. Claude registers itself, opens a RivetIT sign-in window, and after you approve it lists the tools. Because Claude registers itself, **Let MCP apps register themselves** must be on.

### Add RivetIT to ToolHive

Add RivetIT as a **remote MCP server** with the URL `https://YOUR-RIVETIT-HOST/mcp` and let ToolHive discover the sign-in details. ToolHive registers itself (with a loopback return address such as `http://localhost:8666/callback`) and opens your browser for the RivetIT sign-in. If you turned self-registration off, add ToolHive under **Registered apps → Add an app yourself** with the exact return address it uses, then give ToolHive the client ID shown there (its remote-server options let you supply a client ID; check ToolHive's current documentation for the flag name).

### What people see

1. The client sends them to the normal RivetIT sign-in. Password, two-factor codes, passkeys and the MFA policy all apply; a person who is already signed in goes straight on.
2. A **consent screen** shows: the app's name (which the app chose itself; RivetIT cannot verify it), whether an administrator added it or it registered itself, the **host the browser will return to** (a warning appears if that is a loopback address on their own computer), who is signed in, and exactly what the connection can and cannot do:
   - It can **read**, as that person: tickets and replies, assets (never PINs or credentials), departments and contacts (never contact PINs), knowledge base articles. Lines the person's role cannot read are marked "your role has no access".
   - It **cannot** create, change or delete anything (the only scope is `mcp:read`), cannot see more than the person can, and cannot read passwords, vault entries, PINs or API keys.
   - It lasts until removed, or 180 days.
3. **Allow** sends the browser back to the app with a one-time code; **Deny** sends it back with `access_denied`. The page cannot be framed, is protected against cross-site requests, and never redirects anywhere that is not exactly the return address the app registered.

People who log in only to a single module (Training or Knowledge Base only) are not offered the screen; they are sent to their own home page.

### Permissions: never more than the person

A connection is the person. On **every** request RivetIT re-reads the person's account, role and department restrictions. Downgrade a role, add a department limit, disable or archive the account, or turn the person into a non-agent, and the very next call changes accordingly (or stops with `401 invalid_token`). Nothing about roles is stored in a token. A disabled account also cannot refresh.

### Revoking

- **People:** **Account → Security → Connected AI tools** lists their connections, with **Remove**.
- **Administrators:** **Settings → Remote MCP → Active connections** lists who connected which app, when it was approved and last used, with **Revoke**. **Registered apps** lists every client with **Disable** (all its connections stop immediately and it cannot start new ones), **Enable** and **Delete** (removes the app and all its connections). To stop new self-registrations, untick **Let MCP apps register themselves**; existing connections keep working. To stop everything, turn the module or the built-in sign-in off.
- **Apps:** the revocation endpoint (RFC 7009) ends a connection when the app signs out. Revoking either the access or the refresh token ends the whole connection.
- Using a refresh token a second time, or an authorization code a second time, revokes the connection it belonged to.

Every registration, approval, denial, token issue, refresh, revoke and reuse detection is written to the audit log (`mcp.oauth_*` events). No code, token or verifier is ever logged or audited.

### Registering an app by hand

Under **Registered apps → Add an app yourself** enter a name, one return address per line (https, or http on `localhost`, `127.0.0.1` or `[::1]`; matched exactly), and optionally the client ID the app was given. The app is a public client, so it has no secret.

### Protocol summary

| What | Where / value |
| --- | --- |
| Resource (audience) | `https://HOST/mcp` |
| Protected resource metadata (RFC 9728) | `/.well-known/oauth-protected-resource`, `authorization_servers` = the built-in issuer |
| Authorization server metadata (RFC 8414) | `/.well-known/oauth-authorization-server` (404 unless built-in is on) |
| Issuer | `https://HOST` (from the configured base URL, never from a request header) |
| Authorization endpoint | `/oauth/authorize.php` |
| Token endpoint | `/oauth/token.php` (`authorization_code`, `refresh_token`) |
| Registration endpoint (RFC 7591) | `/oauth/register.php` (only while self-registration is on) |
| Revocation endpoint (RFC 7009) | `/oauth/revoke.php` |
| Clients | Public only (`token_endpoint_auth_method: none`); client secrets and Basic authentication are refused |
| PKCE | **S256 required**; `plain` and a missing challenge are refused |
| Redirect URIs | Exact string match against the registration; https, or http on a loopback host; no fragments, no user info, at most 5 per client |
| Resource indicators (RFC 8707) | `resource`, if sent, must be exactly `https://HOST/mcp`; tokens are issued for that audience only |
| Issuer identification (RFC 9207) | `iss` is returned on every authorization response, success or error |
| Scope | `mcp:read` only (there is no write scope). `offline_access` is accepted and ignored. Unknown scopes are refused |
| Authorization code | single use, 60 seconds, bound to the client, the redirect URI and the PKCE verifier |
| Access token | opaque, 15 minutes, audience = the MCP URL |
| Refresh token | single use and **rotated** on every refresh, 30 days; a second use revokes the connection |
| Connection lifetime | 180 days, then the person approves again |

Registration limits: at most 10 registrations per address per hour (and 30 registration requests, any outcome), 60 per hour overall, 500 self-registered apps in total (unused ones older than a week are removed). The token endpoint allows 120 requests per minute per address and 60 per client. These counters use Redis; if Redis is down they are skipped, the database limits above still apply.

### Why opaque tokens instead of signed JWTs

RivetIT issues **opaque random tokens** (256 bits) and keeps only their SHA-256 hashes, rather than signing JWTs. The resource server is the same application as the authorization server, so every request already reads the database to re-check the person's account and role; one more indexed lookup costs nothing. In return:

- there is **no signing key** to generate, store, rotate or leak, so nothing can mint a token without writing a row here;
- revocation, a disabled account and a disabled app take effect on the next call, not when a JWT expires;
- a database leak yields hashes of secrets that are useless without the preimage, and a code, token or secret is never logged;
- there is no token to pass through to another service: the token is meaningless anywhere except RivetIT's `/mcp`.

A third-party resource server would need JWTs and a JWKS endpoint; this one does not.

### Hardening notes

- Constant-time comparisons for redirect URIs, PKCE challenges and identifiers; secrets are compared by their hash in an indexed lookup.
- Strict `Content-Type` (`application/json` for registration, `application/x-www-form-urlencoded` for token and revocation), size limits, no repeated parameters, no parameters read from a URL on the token endpoint.
- Every OAuth response is `Cache-Control: no-store`. Error answers follow RFC 6749 and never include internal detail; server errors are logged by class name only.
- No CORS headers are sent: MCP clients call these endpoints from a server or a desktop app, not from a web page.
- The consent screen is sent with `X-Frame-Options: DENY` and `frame-ancestors 'none'`, needs a valid CSRF token, runs no script, and HTML-escapes the app-chosen name.

### Sign-in routes

Password sign-in (with its two-factor step) and passkey sign-in both return to the consent screen. If a sign-in route does not, you land on your normal start page: go back to the app and start the connection again (you are now signed in, so you go straight to the consent screen).

### Not tested against real clients

The flow is tested end to end by `tests/mcp_oauth_flow.php`, `tests/mcp_oauth_service.php`, `tests/mcp_oauth_admin.php` and the client-side conformance walk-through `tests/mcp_oauth_walkthrough.php`, all over real HTTP against a private test server. It has **not** yet been run against the real Claude connector or ToolHive. Try it with a dedicated low-privilege agent first.

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

**Sessions.** MCP protocol sessions (the `Mcp-Session-Id` header) are kept in a private directory under the PHP temp directory and expire after an hour. They hold protocol negotiation state only; identity and permissions always come from the bearer token. If the directory is cleared (for example by a PHP-FPM restart) clients are told "Session not found" and start a new session, as the MCP specification requires.

## External identity provider

Use this instead of the built-in sign-in if you want people to authenticate through your own provider. Use a trusted OAuth authorization server that can issue signed RS256 JWT **access tokens** for a dedicated RivetIT MCP audience. It must publish standard OAuth/OIDC discovery metadata and JWKS, support authorization-code with PKCE for the MCP client, and issue the `mcp:read` scope. Tokens must contain an immutable `sub`, the exact configured issuer and audience, and expire within one hour. The configured MCP audience must be the token's only audience, whether `aud` is a string or a one-element array. An OIDC ID token for a different audience cannot be used as an MCP access token. If the provider cannot be reached, the request is refused with `401 invalid_token`.

### Set up in the admin page

1. Open **Settings → Remote MCP**, enter the **Issuer URL** and **Audience**, turn it on (leave **Use built-in sign-in** off), and press **Save and run checks**. The checks contact your identity provider and this server and tell you in plain language what to fix: issuer typos (including a missing trailing slash), missing RSA signing keys, no PKCE, a missing `mcp:read` scope, and a web server that does not route `/mcp`.
2. Give your MCP client the address shown on the page (`https://YOUR-RIVETIT-HOST/mcp`).
3. Have the person connect once from their MCP client. They will be refused, and appear under **People who tried to connect** with the name and email from their token. Choose their agent (the one whose email matches is preselected) and press **Link**. Only tokens that already passed every signature, issuer, audience, scope and expiry check are listed. Entries are cleared after 30 days, and at most 200 are kept.
4. Have them connect again. **Linked agents** lets you unlink someone, which stops their access immediately; **Recent activity** shows their calls.

You can still link by hand under **Administration → Users → Remote MCP identity** if you already know the subject.

The server environment can override or stop all of this. `RIVETIT_MCP_ISSUER` and `RIVETIT_MCP_AUDIENCE`, if set in the PHP-FPM pool, take precedence over the saved values (the page shows them read-only). `RIVETIT_MCP_ENABLED=0` is a hard off switch that no setting can undo, for both modes. Since 2.6.123 the environment no longer has to enable it; turning it on in the admin page is enough.

The issuer must match the token's `iss` claim exactly, including any trailing slash, and the audience must match `aud` exactly. The server's protected-resource metadata is at `/.well-known/oauth-protected-resource`. The Apache `.htaccess` and the supplied Nginx template route it to the endpoint; an existing Nginx install needs the `location` blocks shown under "Web server setup" on the page.

Test the OAuth flow in an MCP client with a dedicated low-privilege agent before wider use. Confirm anonymous requests receive a 401 challenge with a `resource_metadata` URL, valid tokens can discover and call the tools, and invalid or additional audiences, expired token, missing scope, disabled user, and out-of-scope tickets fail. The external-provider path has not yet completed a live handshake against a real identity provider; the unit and HTTP tests cover signature, issuer, audience and expiry handling.

To stop all MCP access, switch it off on the Remote MCP page (or set `RIVETIT_MCP_ENABLED=0` and reload PHP-FPM for a switch no one can flip from the UI). To revoke one agent, press Unlink on that page and revoke active tokens at the identity provider if immediate token invalidation is required.

## Switching between the two modes

Switching **Use built-in sign-in** on or off changes the protected-resource metadata immediately and makes the other kind of token invalid. Connections made through the built-in sign-in are kept while it is off (they simply stop working) and work again if you switch it back on. Clients that cached the old metadata should disconnect and reconnect.

## Running the tests

The OAuth tests need a disposable database and a throwaway Redis, never a live one (the database name must contain `scratch` or `test`; `config.php` must point at it with `$config_https_only = FALSE` and `$config_enable_setup = 0`). Each starts its own `php -S` server:

```
RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=… RIVETIT_TEST_DB_USER=… RIVETIT_TEST_DB_PASS=… \
RIVETIT_REDIS_HOST=127.0.0.1 RIVETIT_REDIS_PORT=6391 php tests/mcp_oauth_flow.php
php tests/mcp_oauth_service.php   # redirect rules, single-use gates, lifecycle, housekeeping
php tests/mcp_oauth_admin.php     # the administrator page and Account > Security
php tests/mcp_oauth_walkthrough.php   # an MCP client end to end: 401, discovery, register, authorize, token, call, refresh, revoke
```
