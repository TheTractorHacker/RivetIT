# QMS integration: security

Targets: OWASP ASVS Level 2, OWASP API Security Top 10, NIST SSDF, least privilege. Status: implemented on the RivetIT side: machine credentials (PR 1 of #52), the SSO provider (#55), and the Quality module (module switch, QMS client, per-user context, signed events, portal and kiosk pages).

## Machine credentials (integration clients)

- Table `integration_clients` (name, client id, allowed scopes, optional IP/CIDR allow-list, status, expiry). `integration_client_secrets` holds up to two active secrets per client so a rotation has an overlap period. Secrets are shown once, stored as a password hash (argon2id/bcrypt via `password_hash`), never logged.
- `POST /api/v1/oauth/token` (grant `client_credentials`, `application/x-www-form-urlencoded`). Returns a signed, short-lived (10 minute) access token with `iss`, `aud`, `sub` (client id), `scope`, `jti`, `exp`. Rate limited per client and IP. Failures return a uniform error and are audited.
- Tokens are validated on every request: signature, issuer, audience, expiry, client still active, scope. Revoking a client or secret takes effect at the next request (`jti` stored in `integration_tokens` with the client status check).
- Scopes (deny by default): `lms:read`, `lms:assign`, `lms:completions:read`, `lms:evidence:read`, `directory:read`, `quality:events`, and for the general REST API `api:<resource>:read`, `api:<resource>:write`, `api:<resource>:delete` (one per resource and action; see `docs/API.md`). An endpoint declares the scope it needs; there is no wildcard scope and no scope implies another. A client may additionally hold the `sso` capability (OpenID Connect sign-in, see below); it is not an API scope and is never issued in an API token.
- **Department scope (least privilege, OWASP API1 BOLA).** Each client has an explicit department scope: `integration_clients.client_all_departments` (1 = all departments, 0 = restricted, the column default) plus rows in `integration_client_departments`. A restricted client with no rows sees nothing. The scope is set on creation (a choice is required, no silent default), edited by administrators only, audited with department ids, and read from the database on every request so a change bites tokens already issued. It is enforced inside the SQL of every `/lms` endpoint (courses, assignments, create, completions, evidence); out-of-scope ids answer exactly like unknown ids (404, or the same 422 on create) so existence is not disclosed. `IntegrationScope::allowedDepartmentIds()` returns null for all departments. The directory endpoints (`/employees`, `/departments`, scope `directory:read`) use the same helper: a department-limited client sees only its departments and their employees, a person with no department is visible to all-department clients only, and `manager_id`, `parent_id` and `head_employee_id` are null when they point outside the scope.
- **One machine credential: the API key (Client ID + secret).** The legacy `api_keys` / `X-Api-Key` mechanism is removed: the header answers 401 and is never read, compared or logged (an audit line records the sending address). Keys of earlier versions were converted into API keys at DB 2.6.204 (same permissions mapped to `api:*` scopes, same department scope, IP allow-list and expiry) **without a secret**, so they cannot authenticate until an administrator creates one. Machine clients on the general API act as a fixed, disabled, role-less service identity, never as an administrator; role checks inside endpoints are decided by scopes. Person-bound routes (`/credentials`, `/me`, `/notifications`, `/approvals`, `/endpoint_devices`, `/service_catalog`, `/workflow_tasks`) are never available to a client. User-session tokens (`api_tokens`, the mobile app) are a different mechanism and unchanged.
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
- Directory endpoints return only fields the QMS needs: name, work email, job title, department and Odoo ids, employment status. No PIN, phone, address, photo or notes. An integration access token reaches a route only if it holds that route's scope (`lms:*` on `/lms`, `directory:read` on `/employees` and `/departments`, `api:<resource>:read|write|delete` on the general API); `directory:read` grants nothing on `/lms` and no `lms:*` scope grants the directory. A QMS client should hold only its `lms:*` / `directory:read` scopes.

## Quality module (built)

- **One switch, enforced on the server.** `settings.config_module_enable_quality` (default 0) is read from the database on every request by the portal pages, the kiosk page, `POST /api/v1/integrations/qms/events` (`503 module_disabled`), the cron worker (exits), the SSO policy (`module_disabled`, administrators included) and the navigation. Hiding a link is only a convenience. Switching off keeps settings, mappings and history and does not touch the LMS.
- **QMS address.** `QmsUrl::vet()` on save and before every call: https only, no credentials/query/fragment, path of plain characters, public addresses plus administrator-listed internal networks (RivetCore `UrlPolicy`), loopback/link-local/metadata never; the connection is pinned to the vetted addresses (DNS rebinding), redirects are never followed, response size capped at 1 MiB, the token address must be on the same host. Links the QMS returns are shown only when `https` on the QMS's own host (no `javascript:`, no off-site links).
- **Machine credential.** OAuth 2.0 client credentials (`client_secret_basic`); the client secret, both signing secrets and the cached access token are sealed with `encryptSetting()` and registered in `ColumnRegistry` for key rotation; secrets are write-only on the admin page and never logged (the error log scrubs bearer tokens, secrets, signatures and URL queries).
- **Per-user context.** Employee and manager requests carry `X-Rivet-User-Context`, an ES256 JWT (typ `rivetit-user-context+jwt`, audience = the QMS address, 60 seconds, `jti`), signed with the SSO provider's key, naming the person (Odoo employee id, RivetIT id), department and role (`employee`/`manager`). The client refuses a `/me` or `/quality` request without one and refuses to send one on a system call; a context is never issued for a person who is not eligible right now (module off, department not enabled, inactive, not a portal user). The department and the manager role come from the contact's own record, never from the request. The QMS authorizes per person (`docs/QUALITY_CONTRACT.md` section 3).
- **Events.** HMAC-SHA256 over `"<t>.<body>"` with one secret per direction; constant-time compare; 5 minute window; replay table `quality_inbound_events` (UNIQUE source + id; same id with a different body is `409`); 64 KiB cap; scope `quality:events` is also required inbound. Outbound events carry identifiers only, have deterministic ids, retry with backoff and dead-letter after 8 attempts.
- **Re-authentication.** Acknowledging a document or answering an action needs a sign-in younger than the configured age or the password again (5 misses lock it for that sign-in); the kiosk requires the PIN (`PinGate::stepUp`).
- **Shared screens.** The kiosk shows counts, required training and document titles only; corrective actions never render there; the portal pages are refused for kiosk-flagged sessions.
- **Authorization tests.** `tests/quality_module.php` covers module off, department not enabled, wrong scope, missing context, forged audience, other-department session, non-manager, CSRF, replay, bad signature, stale and future timestamps, oversized and wrong-type bodies, ambiguous ids and name-only matching.

## Portal access

Quality pages are served from the department portal, not the agent interface. A portal user reaches them only when the module is on, their department is enabled, the feature is on and their portal role allows it. Every page and action re-checks this on the server and passes the signed per-user context to the QMS.

## Kiosk

Existing device and session policy applies unchanged. Shared screens show counts, training and document acknowledgment only. Corrective-action or nonconformance details never render on a kiosk. Acknowledgments and signing re-enter the PIN.

## SSO

User SSO to the QMS: RivetIT is an OpenID Connect provider (authorization code with PKCE S256 only, confidential client, ES256 ID tokens). Full design, claims, key rotation, threat notes and the validation checklist Datum must implement: `qms-sso.md`. In short:

- The QMS is an integration client holding the `sso` capability plus a registration (exact redirect URIs, login address). The capability is not an API scope: it never appears in an API token, and a client with only `sso` cannot obtain one. SSO access tokens (opaque, hashed at rest, 5 minutes) are refused everywhere under `/api/v1`; LMS tokens and API keys are refused at `/oidc/userinfo`.
- The person is the department-portal session of a contact. No session means the normal portal sign-in first, then back to a request that is kept server side (never in a URL). Refused, audited, with a plain page that reflects nothing from the request: provider off, contact inactive, archived or terminated, not a portal user, kiosk or agent sessions, department inactive, portal off. A portal sign-in older than the configured age (default 8 hours) forces re-authentication.
- `QualityAccessPolicy::allows()` is the one place that decides, called at authorize, token and every userinfo call. The Quality module switch and the per-department list plug in there when Phase 1 exists.
- Codes: single use, 60 seconds, bound to client, redirect URI, PKCE challenge, nonce and person, burned on first presentation; replay revokes the tokens issued from the code and is audited. No refresh tokens, no implicit, password or client-credentials grant at the OIDC endpoints.
- ID token claims are an identifier set (`sub` = Odoo employee id when linked, otherwise `rivetit:<contact id>`; `rivetit_id`), timing, `nonce`, `amr`, and the display name only on request. No email, phone or PIN.
- The signing key is encrypted at rest under its own context, registered for settings-key rotation, rotated from the admin page with a 15 minute overlap. The issuer comes from the configured base URL only.
- Rate limits on Redis (fail open) plus database counters (independent of Redis); every issue and refusal is an audit row; uniform error responses; `no-store` on token and userinfo; frame, referrer and CSP headers on the authorize pages.
- Passwords, sessions and cookies are never shared between the applications. Machine credentials and user identities are separate.

The SSO sign-in is per person and is unaffected by an integration client's department scope: the scope limits only what a machine client can read or create, never who may sign in. User SSO to the QMS uses OIDC (authorization code with PKCE) with explicit issuer and audience checks. Passwords, sessions and cookies are never shared between the applications. Machine credentials and user identities are separate.
