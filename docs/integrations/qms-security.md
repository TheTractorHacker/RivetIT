# QMS integration: security

Targets: OWASP ASVS Level 2, OWASP API Security Top 10, NIST SSDF, least privilege. Status: design.

## Machine credentials (integration clients)

- Table `integration_clients` (name, client id, allowed scopes, optional IP/CIDR allow-list, status, expiry). `integration_client_secrets` holds up to two active secrets per client so a rotation has an overlap period. Secrets are shown once, stored as a password hash (argon2id/bcrypt via `password_hash`), never logged.
- `POST /api/v1/oauth/token` (grant `client_credentials`, `application/x-www-form-urlencoded`). Returns a signed, short-lived (10 minute) access token with `iss`, `aud`, `sub` (client id), `scope`, `jti`, `exp`. Rate limited per client and IP. Failures return a uniform error and are audited.
- Tokens are validated on every request: signature, issuer, audience, expiry, client still active, scope. Revoking a client or secret takes effect at the next request (`jti` stored in `integration_tokens` with the client status check).
- Scopes (deny by default): `lms:read`, `lms:assign`, `lms:completions:read`, `lms:evidence:read`, `directory:read`, `quality:events`. An endpoint declares the scope it needs; there is no wildcard scope.
- A service token does not see more than its scopes allow, and for employee-facing calls it acts only on the person named in the signed per-user context.

## Per-user context

Employee-facing requests to the QMS carry the person's identity (Odoo employee ID plus QMS ID) in a signed assertion (or the user's own OIDC token where the QMS supports it). The QMS authorizes against that person; RivetIT applies department scoping (`user_client_permissions`) before it ever asks. A missing or invalid assertion fails closed. Service tokens never substitute for it.

## Outbound calls to the QMS

- HTTPS only. URL vetted by `UrlPolicy` at save time and again at every call; connection pinned to the vetted IP. No redirects followed. Private ranges only if listed in `config_webhook_allowed_networks`.
- Timeouts (connect 3 s, total 10 s), response size cap (1 MiB), bounded retries (3, exponential backoff with jitter, only for idempotent or keyed requests), `X-Correlation-Id` on every request and in every log line.
- Secrets stored with `encryptSetting` under their own context string and registered for key rotation.

## Webhooks and events

- Signature header `X-Rivet-Signature-V2: t=<unix>,v1=<hmac-sha256("<t>.<body>")>`, separate signing secret per direction, constant-time compare, 5 minute timestamp window.
- Replay protection: `quality_inbound_events` unique on (source, event id); a duplicate returns 200 without reprocessing. Processing is idempotent regardless.
- Failed deliveries retry with backoff, then dead-letter with an admin view and replay. Webhooks are never the only synchronization path; a scheduled reconciliation compares cursors.
- Payloads carry minimal data: identifiers, type, schema version, timestamps. No names, emails or PINs.

## Authorization rules

- Every new endpoint and page has an automated authorization test: wrong scope, wrong department, wrong user, module disabled.
- Object-level checks on every id: a course, assignment, completion or evidence id is only returned if the caller's scope and department rules allow it. Unknown and forbidden ids return the same 404.
- Evidence downloads stream through PHP after the same check. No filesystem paths or direct URLs are exposed.
- Errors are structured (`code`, `message`, `correlation_id`) and never include stack traces, SQL, hostnames or secrets.
- Directory endpoints return only fields the QMS needs; no PIN, phone, address or personal email.

## Portal access

Quality pages are served from the department portal, not the agent interface. A portal user reaches them only when the module is on, their department is enabled, the feature is on and their portal role allows it. Every page and action re-checks this on the server and passes the signed per-user context to the QMS.

## Kiosk

Existing device and session policy applies unchanged. Shared screens show counts, training and document acknowledgment only. Corrective-action or nonconformance details never render on a kiosk. Acknowledgments and signing re-enter the PIN.

## SSO

User SSO to the QMS uses OIDC (authorization code with PKCE) with explicit issuer and audience checks. Passwords, sessions and cookies are never shared between the applications. Machine credentials and user identities are separate.
