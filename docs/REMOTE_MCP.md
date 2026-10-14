# Remote MCP (Experimental module)

Everything is configured in **Administration → Settings → Remote MCP**: the on/off switch, how people sign in, health checks, and linking people to agents. It is off by default. Apply database update 2.6.156 first (2.6.123 for the module itself; 2.6.167 for client metadata documents, see below). Turning the module off blocks the endpoint, its OAuth metadata and every OAuth endpoint.

RivetIT's MCP endpoint is `/mcp`. It exposes only **read-only** tools. It never forwards an OAuth token to the RivetIT API or uses a long-lived RivetIT API key, and billing or any other write operation is not exposed.

There are two ways for people to sign in. Pick one; they are exclusive.

| | **Built-in sign-in** (recommended) | **External identity provider** |
| --- | --- | --- |
| What you need | Nothing else | An OAuth server that issues RS256 JWT access tokens (for example Authentik) |
| Who authorizes | RivetIT: the normal agent sign-in (password, 2FA, passkey), then a consent screen | Your identity provider |
| Linking people to agents | Not needed: the person who signs in is the agent | An administrator links each token subject to an agent |
| Tokens | Opaque, issued and stored (hashed) by RivetIT | Signed JWTs verified against the provider's JWKS |
| Revoking | One click, effective on the next call | Unlink the agent; revoke at the provider for immediate token death |
| Client registration | Automatic (RFC 7591), the client's published metadata document (CIMD), or by an administrator | At the provider |

## Recommended secure setup

This is the setup we run and recommend. Each step says why, so you can judge a deviation.

1. **Built-in sign-in on, self-registration OFF.** Under **Settings → Remote MCP → Built-in sign-in** untick **Let MCP apps register themselves**. With it on, *any* program that can reach your server can create an app entry with any name and any return address (for example "Claude" returning to a page the author controls), and then ask a person to approve it; the person only sees a name the program chose. Switching it off stops new registrations only: apps already registered, and their connections, keep working.
2. **Claude by its published identity (CIMD), "Only the hosts listed", host `claude.ai`.** Press **Trust Claude (claude.ai)** once. Claude is then identified by a document RivetIT fetches from `https://claude.ai/oauth/mcp-oauth-client-metadata`, and the approval screen names `claude.ai`, which a program cannot fake. **Never use "Any https website"**: anyone on the internet could then make an entry appear here by pointing a sign-in link at a page they control (RivetIT warns people on the approval screen, but the attacker chooses the name and the return address). Trusting a host trusts everything that host serves at any path, so list only hosts you would trust with that.
3. **Other apps (ToolHive, others) by hand.** Under **Registered apps → Add an app yourself** enter the name and the exact return addresses and give the app the client ID shown. A hand-added app is never deleted by "Delete unused duplicates".
4. **Use a dedicated low-privilege agent first.** A connection is exactly the person who approved it and nothing more (their role and department limits are re-read on every call), so the safest way to try it is an agent whose role can read little.
5. **Run RivetIT behind your TLS reverse proxy with the real client address configured.** Rate limits count the address RivetIT sees (`REMOTE_ADDR`, or the forwarded address only when **Administration → Settings → Security → Reverse proxies on your side** (or Cloudflare) says how many proxies to trust, in which case the address that many hops from the right is used, not a value the client can pick). Leave it on "Not set" with the legacy `CONST_GET_IP_METHOD = HTTP_X_FORWARDED_FOR` in `config.php` and the *first* forwarded entry is believed, which a client can forge: do not combine that with these limits. If every request arrives from the proxy's address, all visitors share one set of buckets: set the number of proxies. IPv6 clients are counted per /64.
6. **Keep Redis running.** Rate limits live in Redis and fail open when it is down (an abuse guard, not access control). The one exception is a client-metadata fetch in "Any https website" mode, which is refused without Redis. In the recommended "listed hosts" mode a fetch still goes ahead.

### What the read-only tools expose, and the prompt-injection caveat

A connection can read, **as that person**: tickets and their replies, assets (name, type, make, model, serial; never PINs, URIs or credentials), departments and contacts (never contact PINs), and knowledge base articles; at most 25 rows per search, 60 calls a minute. It cannot create, change or delete anything, and it never sees vault entries, passwords or API keys.

What an AI tool does with that text is the AI tool's business. **Ticket replies, contact notes and article bodies are written by customers and other people, and an AI tool that reads them can be tricked by instructions hidden in them** (prompt injection). Because the connection is read-only the damage is limited to what the AI tool is willing to do with what it read, for example repeating it somewhere you did not intend or acting through *other* tools you gave it (a mail or chat connector, for instance). Do not give an AI tool that reads RivetIT a second connector that can send data out unless you are comfortable with that path, and approve connections only for people whose role should read all of this.

### Seeing and ending connections

- **Settings → Remote MCP → Active connections** lists every live connection: person, app, approved, last used, ends. **Revoke** ends one at once.
- **Registered apps** shows, per app, how many connections and people it has and when it was last used. **Disable** blocks an app and every connection it has, and keeps the entry so it cannot come back (use this for a metadata-document app). **Delete** removes the app and ends all its connections; when connections are active the page makes you tick a box naming how many connections and people will stop, and the server refuses the delete without it. Duplicates (same name and return addresses) are marked, and **Delete unused duplicates** removes only self-registered copies that never had a connection and were never used, always leaving one copy of each app.
- Each person sees and removes their own under **Account → Security → Connected AI tools**.
- Changing the metadata-document mode to Off, or removing a host from the trusted list, **cuts live connections on their very next request** (their access token stops working at once; nothing waits for the 15 minutes). The consent is kept, so adding the host back brings the same connection back. Revoke the connection to end it for good.
- Disabling an app, revoking a connection, disabling or archiving the person, or changing the person's role to a non-agent also takes effect on the next request. Re-enabling a disabled person revives their unexpired connections (the consent was theirs and has not ended): revoke them when you offboard someone rather than only disabling the account.

### What to check in the audit log

Filter **Administration → Audit trail** for `mcp.oauth_*` and `mcp.tool_call`:

- `mcp.oauth_client_registered`: a new app. With self-registration off the only ones you should see are your own hand-added apps and `claude.ai` (source `metadata_document`). Anything else is worth a look.
- `mcp.oauth_consent_granted` / `mcp.oauth_token_issued`: who approved which app and when. Unfamiliar person or app? Revoke it.
- `mcp.oauth_reuse_detected`: a one-time code or a refresh token was used twice, so the connection was revoked automatically. It can be a client that retried after a timeout, or a stolen token; ask the person.
- `mcp.oauth_cimd_fetch_failed`: a client metadata document was refused (reason and host, never its content). Repeated failures for a host you do not recognise mean someone is probing the sign-in address.
- `mcp.oauth_settings_changed`, `mcp.oauth_client_disabled`, `mcp.oauth_client_deleted`, `mcp.oauth_grant_revoked`: administrative changes, with who made them.
- `mcp.tool_call`: every read (tool, outcome, truncated arguments). A person reading far more than usual, or at hours they do not work, is the sign to revoke.

## Built-in sign-in

RivetIT acts as its own OAuth 2.1 authorization server, so an MCP client such as the Claude custom connector or ToolHive can connect with no static API key and no external identity provider. It is **off by default**.

### Turn it on

1. Open **Settings → Remote MCP**, tick **Turn on Remote MCP** and press **Save**. (You do not need an issuer or audience.)
2. In **Built-in sign-in**, tick **Use built-in sign-in** and press **Save**. Leave **Let MCP apps register themselves** on for Claude, or use **Client ID Metadata Documents** instead (see below). Turn self-registration off if you would rather add each app by hand.
3. Make sure the web server routes the metadata address. Apache needs nothing (the bundled `.htaccess` has the rules). The supplied nginx template and `docker/nginx.conf` already contain the blocks. On an existing nginx install add the `/.well-known/oauth-authorization-server` block shown under **Setup help → Web server setup** on the page, then reload nginx. The `/oauth/*.php` addresses work with the standard PHP location and need nothing extra; the template additionally marks the token endpoints `Cache-Control: no-store`.

The address to give a client is `https://YOUR-RIVETIT-HOST/mcp` (shown with a Copy button on the page). The sign-in server details are discovered from it automatically.

### Add RivetIT to Claude

In Claude, open **Settings → Connectors → Add custom connector**, enter `https://YOUR-RIVETIT-HOST/mcp` and leave the OAuth client ID and secret empty. Claude registers itself, opens a RivetIT sign-in window, and after you approve it lists the tools. Because Claude registers itself, **Let MCP apps register themselves** must be on.

To use Claude's published identity instead of self-registration, see **Client ID Metadata Documents** below: press **Trust Claude (claude.ai)** once and Claude connects as itself.

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
- **Administrators:** **Settings → Remote MCP → Active connections** lists who connected which app, when it was approved and last used, with **Revoke**. **Registered apps** lists every client with how many connections and people it has and when it was last used, and **Disable** (all its connections stop immediately and it cannot start new ones), **Enable** and **Delete** (removes the app and all its connections; the page and the server require an explicit confirmation naming the active connections and people). Duplicates are marked and **Delete unused duplicates** removes only never-connected, never-used self-registered copies. To stop new self-registrations, untick **Let MCP apps register themselves**; existing connections keep working. To stop everything, turn the module or the built-in sign-in off.
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
| Authorization server metadata (RFC 8414) | `/.well-known/oauth-authorization-server` (404 unless built-in is on); `client_id_metadata_document_supported: true` while client metadata documents are on |
| Issuer | `https://HOST` (from the configured base URL, never from a request header) |
| Authorization endpoint | `/oauth/authorize.php` |
| Token endpoint | `/oauth/token.php` (`authorization_code`, `refresh_token`) |
| Registration endpoint (RFC 7591) | `/oauth/register.php` (only while self-registration is on) |
| Revocation endpoint (RFC 7009) | `/oauth/revoke.php` |
| Clients | Public only (`token_endpoint_auth_method: none`); client secrets and Basic authentication are refused. A `client_id` may be a registered ID or, when allowed, the https URL of a client metadata document |
| PKCE | **S256 required**; `plain` and a missing challenge are refused |
| Redirect URIs | Exact string match against the registration; https, or http on a loopback host; no fragments, no user info, at most 5 per client (10 in a metadata document, where a loopback entry also matches any port) |
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
- Self-registering the same app again (same address, same name, same return addresses) within 10 minutes returns the client the address already has instead of creating a copy (Claude registered five times in one sign-in). A different name, return address or address never matches. Names containing control, zero-width or bidirectional-override characters are refused (or, in a metadata document, replaced by the host name).
- Every request to `/mcp` is checked against the database: token not expired, consent not revoked or expired, client enabled and still allowed by the current metadata-document policy, person an active agent, audience equal to the MCP URL, scope `mcp:read`. Times are compared by the database in UTC (`UTC_TIMESTAMP()`), never by parsing a stored time in PHP, so neither the PHP nor the database session time zone can change validity (`tests/mcp_oauth_utc.php`, `tests/mcp_oauth_hardening.php`).
- An authorization code and a refresh token are each redeemed by one atomic update, so of any number of parallel requests at most one wins; using either a second time revokes the connection it belonged to. This is strict: a client that retries a refresh after losing the response loses its connection and must be approved again.
- Rate limits (Redis, per address; IPv6 per /64): authorization 120 a minute, token 120 a minute and 60 a minute per address and client, revocation 120 a minute, registration 30 requests an hour per address and 60 in all (10 successful registrations per address an hour). Metadata fetches are counted only when a fetch really goes out: 20 a minute per address and 120 a minute in all. At most 30 `mcp.oauth_cimd_fetch_failed` audit rows are written per 10 minutes.
- The metadata-document fetch never decompresses a response (the 64 KB cap counts bytes on the wire), follows no redirect, and is pinned to the addresses that were vetted (IPv4-mapped and other embedded-IPv4 forms are judged by the embedded address).
- The MCP protocol session directory (in the system temp directory, under a name that can be guessed) is used only if it is a real directory owned by the web user and closed to everyone else; otherwise `/mcp` answers 503. Session ids are random UUIDs and carry no identity: a session id used with another person's token answers as that person.
- The authorization endpoint answers a generic page, never an exception message, if the database fails.
- The consent screen is sent with `X-Frame-Options: DENY` and `frame-ancestors 'none'`, needs a valid CSRF token, runs no script, and HTML-escapes the app-chosen name.

### Sign-in routes

Password sign-in (with its two-factor step) and passkey sign-in both return to the consent screen. If a sign-in route does not, you land on your normal start page: go back to the app and start the connection again (you are now signed in, so you go straight to the consent screen).

### Not tested against real clients

The flow is tested end to end by `tests/mcp_oauth_flow.php`, `tests/mcp_oauth_service.php`, `tests/mcp_oauth_admin.php`, the client-side conformance walk-through `tests/mcp_oauth_walkthrough.php` and, for client metadata documents, `tests/mcp_oauth_cimd.php` (against a private HTTPS mock of a document host), all over real HTTP against a private test server. It has **not** yet been run against the real Claude connector (hosted or Claude Code) or ToolHive: in particular RivetIT has never fetched `https://claude.ai/oauth/mcp-oauth-client-metadata` itself, only a person has read that document. Try it with a dedicated low-privilege agent first.

## Client ID Metadata Documents (Claude's published identity)

With **Dynamic Client Registration** (DCR, RFC 7591) Claude registers a brand-new client with RivetIT on every fresh connection, and nobody can tell that client from any other program that registered the same way. With a **Client ID Metadata Document** (CIMD, [draft-ietf-oauth-client-id-metadata-document](https://datatracker.ietf.org/doc/draft-ietf-oauth-client-id-metadata-document/), adopted by the [MCP authorization specification](https://modelcontextprotocol.io/specification/2025-11-25/basic/authorization#client-id-metadata-documents)) the client's `client_id` is the address of a JSON document it publishes. RivetIT fetches that document, so the approval screen can say "requested by **claude.ai**" and mean it: the document is served from that host over https.

CIMD is **off by default** and needs database update 2.6.167 (one new table, `mcp_oauth_client_documents`; the setting lives in `mcp_oauth_config`).

### Turn it on for Claude

Open **Settings → Remote MCP → Client ID Metadata Documents** and press **Trust Claude (claude.ai)**. That sets the mode to "Only the hosts listed" and adds `claude.ai` to the list. Then add RivetIT to Claude as described above. Claude uses CIMD only when the server's metadata advertises `client_id_metadata_document_supported: true` **and** `none` in `token_endpoint_auth_methods_supported`; RivetIT's does both once CIMD is on, and otherwise Claude falls back to DCR.

What the official documentation says (re-read on 2026-10-14; RivetIT relies on nothing else):

- [Authentication for connectors](https://claude.com/docs/connectors/building/authentication.md): the hosted Claude apps (claude.ai on the web, Desktop, mobile, Cowork) use the single redirect URI `https://claude.ai/api/mcp/auth_callback`. `https://claude.com/api/mcp/auth_callback` is **not** in the official documentation, so RivetIT does not special-case it: it is accepted only if a client's own document lists it (Claude's does not). Claude Code is a native client that "uses an RFC 8252 loopback redirect on an ephemeral port that varies per session", declared in its own CIMD as `http://localhost/callback` and `http://127.0.0.1/callback`, and the server must "match both with the port component ignored", `localhost` included. The consent screen must display the redirect URI's hostname clearly.
- [Lazy authentication, "Identify Claude with a client ID metadata document"](https://claude.com/docs/connectors/building/lazy-authentication.md): the authorization endpoint should fetch the `client_id` URL, check that the document's own `client_id` equals that URL, check `redirect_uri` against the document's `redirect_uris` (loopback without the port), and "name the host of the `client_id` URL as the app asking for access, not the document's `client_name`, because the document is self-asserted".
- Claude's published documents (fetched the same day): `https://claude.ai/oauth/mcp-oauth-client-metadata` (client name `Claude`, redirect `https://claude.ai/api/mcp/auth_callback`, `token_endpoint_auth_method` `none`, and a `grant_types` list that includes the `jwt-bearer` grant, which RivetIT ignores and never issues) and `https://claude.ai/oauth/claude-code-client-metadata` (Claude Code, the two loopback callbacks above).

### The three modes

| Mode | What happens |
| --- | --- |
| **Off** (default) | A URL as `client_id` is refused without being fetched. Registration and manually added apps work as before. |
| **Only the hosts listed** | Only a `client_id` on a listed host (default list: `claude.ai`; add or remove hosts on the page; a host with a non-standard port is listed as `host:port`) is fetched. Others are refused without being fetched. |
| **Any https website** | Any https `client_id` is fetched. The page carries a warning, and the consent screen shows an **Unverified application** notice for any host the administrator did not list. Anyone on the internet can then create an entry in **Registered apps** by pointing a sign-in link at a page they control (people still sign in and approve), so prefer the listed hosts. |

Changing the mode or removing a host takes effect at once, everywhere: new approvals, refreshes and document fetches are refused, **and an access token already issued stops working on the very next request** (the token validator re-checks on every call that the app's client is enabled and, for a metadata-document app, that the mode is not Off and its host is still trusted). The connection itself is kept, so trusting the host again brings it back; **Revoke** it to end it for good. Switching **self-registration** off is different: it only stops new registrations, and apps already registered keep working.

### What RivetIT checks and how it fetches

The `client_id` must be an `https` URL with a path (not just `/`), no fragment, no user name or password, no dot segments (also when percent-encoded), printable ASCII, at most 512 characters, with a lower-case host. It is compared as written: the document's `client_id` must equal that exact string.

The fetch uses the same guard as webhooks (RivetCore `UrlPolicy`): https only; the host must resolve **only** to public addresses (loopback, private, link-local, cloud-metadata, carrier-grade NAT and similar are refused; the administrator's webhook "internal networks" list does **not** apply here); the connection is pinned to the addresses that were checked; no redirects are followed; no proxy; **5 seconds** for the whole transfer; **64 KB** maximum; the response must be `200` and `application/json`. A failed fetch cannot be made to look like success: nothing is stored for a document that does not validate.

The document must be a JSON object with `redirect_uris` (1 to 10 entries, each https or http on `localhost`, `127.0.0.1` or `[::1]`, no fragment or user info), and `token_endpoint_auth_method` `none` (or absent). It is refused if it carries `client_secret`, `client_secret_expires_at`, `jwks` or `jwks_uri`, or any other auth method (`client_secret_basic`, `client_secret_post`, `private_key_jwt`, ...), or if `grant_types` / `response_types` are present without `authorization_code` / `code`. Other grant types in the list (Claude lists `jwt-bearer`) and unknown fields are ignored: RivetIT only ever issues authorization-code and refresh-token grants to a public client, whatever the document says.

The requested `redirect_uri` must match a listed entry exactly, **except** that an entry on a loopback address (`http`, `localhost`, `127.0.0.1` or `[::1]`) matches any port (RFC 8252 section 7.3): host, path and query must still be identical, and an https entry never gets a wildcard. This applies **only** to metadata-document clients. A registered or manually added client keeps exact matching. The authorization code is bound to the exact redirect URI the client sent, port included.

The validated document is cached per its `Cache-Control: max-age`, never shorter than **5 minutes** and never longer than **24 hours** (1 hour when it says nothing), and fetched again after that. **It fails closed**: if the cached copy has expired and the fetch fails or the new document is not acceptable, the authorization is refused. At the token endpoint a fetch failure answers a retryable `503 temporarily_unavailable` (the refresh token is not used up); a document that now fails validation answers `invalid_client`.

Everything in the document is the client's own claim. The name is shown only as text, HTML-escaped, truncated to 100 characters and always next to the host; no logo, icon or other image from the document is ever fetched or displayed. The consent screen's headline fact is the host of the `client_id` URL.

### How the client appears and how it is managed

A metadata-document client is stored like any other client (its `client_id` in RivetIT is an internal hash, `cimd_...`, never accepted from a client directly) and appears in **Registered apps** marked **Metadata document**, read-only: its source URL, when it was first seen, when its document was last fetched, when the cached copy expires, the last problem, and its return addresses. **Disable** blocks it even though its document is still valid (nothing is fetched for a disabled client); **Delete** removes it, its cached document and all its connections, and it can reappear the next time it connects while its host is trusted, so use Disable to block it for good. Unused entries older than a week are removed automatically (disabled ones and ones with connections are kept). At most 500 are kept.

Consent, codes, tokens, refresh rotation and reuse detection, revocation (the revocation endpoint accepts the URL as `client_id`), the per-person **Connected AI tools** list (which also names the host) and the audit trail work exactly as for any other client. The audit trail records the first sight of a client (`mcp.oauth_client_registered`, with the host and the return hosts) and every refused document (`mcp.oauth_cimd_fetch_failed`, with the host and a short reason; never the document, a secret, or a token; at most three rows per document in ten minutes). Every fetch that actually goes out is limited to 20 a minute per address and 120 a minute in all (a request served from the cached document costs nothing).

### Claude Code (loopback)

Claude Code connects from your computer, on a different local port every session (for example `http://localhost:3118/callback`). Its published document lists `http://localhost/callback` and `http://127.0.0.1/callback`, so RivetIT accepts any port for those two paths and nothing else. The consent screen says the browser will return to an address "on this computer".

**Known caveat.** Any program on the same computer can open a port and send the same sign-in request with Claude Code's `client_id`, and the loopback redirect cannot tell it apart (the MCP specification calls this out, and the consent screen warns about it). The person still has to sign in and press Allow, the access is read-only and limited to what that person can read, and RivetIT shows exactly where the browser will return, but the person is trusting that they started the tool themselves. If you do not want that risk, leave CIMD off or do not list hosts whose clients use loopback redirects.

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
RIVETIT_TEST_DB_SOCKET=/path/to/scratch.sock \
RIVETIT_REDIS_HOST=127.0.0.1 RIVETIT_REDIS_PORT=6391 php tests/mcp_oauth_flow.php
php tests/mcp_oauth_service.php   # redirect rules, single-use gates, lifecycle, housekeeping
php tests/mcp_oauth_admin.php     # the administrator page and Account > Security
php tests/mcp_oauth_walkthrough.php   # an MCP client end to end: 401, discovery, register, authorize, token, call, refresh, revoke
php tests/mcp_oauth_cimd.php   # client metadata documents: URL rules, SSRF policy, document validation, caching, trust modes, full flow
php tests/mcp_oauth_hardening.php   # security review: immediate revocation, time zones, dedupe, app deletion, races, rate-limit keys, session dir
php tests/mcp_oauth_utc.php   # source guard (no database): no NOW() on UTC columns, no PHP-zone parsing of stored times
```

`tests/mcp_oauth_cimd.php` starts a private HTTPS server (`tests/mcp_oauth_cimd_mock.php`, self-signed certificate on 127.0.0.1) as the document host. For that test only, the PHP processes get `RIVETIT_WEBHOOK_ALLOW_PRIVATE=1` (the existing webhook switch that lets the SSRF policy reach a private address) and `RIVETIT_CIMD_TEST_CA_FILE=<certificate>`. Neither is ever set by default; never set them on a real server.
