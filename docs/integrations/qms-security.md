# QMS integration: security

Targets: OWASP ASVS Level 2, OWASP API Security Top 10, NIST SSDF, least privilege. Status: design; the machine credentials (PR 1 of #52) and the SSO provider (#55) are implemented.

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
