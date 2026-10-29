# RivetIT — REST API Reference

This document describes the REST API served by RivetIT — the same
companion API used by the mobile app, RMM scripts and other
integrations. It's a plain JSON-over-HTTP API: no SDK is required, just an
HTTP client and one of the two auth methods below.

- **Base path:** `/api/v1` (relative to your RivetIT install host, e.g.
  `https://your-instance.example.com/api/v1`)
- **Format:** JSON request and response bodies, except where a specific
  endpoint documents multipart form uploads or Server-Sent Events (SSE).
- **Versioning:** the API is versioned as a whole (`v1` in the path). There
  is currently one version; breaking changes would ship as `/api/v2`.
- **Machine-readable spec:** the full OpenAPI 3.0 document is served live at
  `GET /api/v1/openapi.yaml` and mirrors exactly what the running instance
  supports — import it into Swagger UI, Postman, or Insomnia to explore and
  try requests.
- **Human-readable in-app reference:** `GET /api/v1/docs` uses a locally
  hosted Redoc viewer for the same spec (also embedded in **Admin > API Docs**).
  Use its navigation and search to browse endpoint parameters and schemas.

This document is a narrative companion to those two — it explains what each
resource is for and how the pieces fit together. For the full request/response
JSON Schema of any individual endpoint, use the OpenAPI spec or `/docs`
rather than treating the tables below as exhaustive schemas.

## A note on "clients" vs. "departments"

RivetIT is built for internal IT departments running one organization with
many internal departments. It started from ITFlow, whose data model was made
for an MSP billing external clients, and the admin UI was renamed throughout —
"Client" / "Clients" became "Department" / "Departments" everywhere a person
sees it.

The API was **deliberately excluded** from that rename, to keep it a stable,
documented contract for existing integrations. Every endpoint path, query
parameter, and JSON field still says `client` / `client_id` — that is not a
bug or leftover, it's intentional API stability.

This document uses "department" in prose, since that's what a department
maps to conceptually for this edition's users — but every code block, path,
and field name below is shown verbatim as the API actually expects it
(`/clients/{id}`, `client_id`, etc.), so you can copy it directly into a
request.

## Billing modules are off by default

Ticket charges are an optional billing feature, off by default in this
edition and enabled at **Settings > Modules**. Quotes, invoices, expenses,
products, and their financial report endpoints are no longer exposed through
the RivetIT API. Their REST paths return `404`, and their legacy PHP files
have been removed.

---

## Authentication

Every endpoint other than a short public list (below) requires one of two kinds of credential, sent on every request. They are
different things and are not interchangeable:

| Credential | Header | Identifies | Use it for |
|---|---|---|---|
| **Integration client access token** (primary for machines) | `Authorization: Bearer <access token>` from `POST /oauth/token` | A *machine client* (Client ID + secret), with scopes and department limits | Scripts, RMM tooling, the QMS, any server-to-server integration |
| User session token | `Authorization: Bearer <token>` from `POST /auth` | A *person* signed in (the mobile app) | The Android/iOS app and anything acting as one technician |
| Legacy API key (**deprecated**) | `X-Api-Key: <key>` | The *migrated integration client* the key was converted to | Existing scripts only, until they move to a Client ID + secret |

**There is one machine-credential system: integration clients.** The legacy API keys of earlier versions were converted into
integration clients automatically (DB 2.6.204). Their key strings keep working through `X-Api-Key` during a deprecation period, and
then the header is switched off.

**User session tokens (`api_tokens`) are unrelated to this and are unchanged**: they identify a signed-in person on a device, are
issued by `POST /auth`, carry that person's role and permissions, and are what the mobile app uses. Nothing in the unification
touches them.

### Bearer tokens (recommended for new integrations)

This is the primary method for anything acting as a specific person — the
mobile app, a browser-based integration, or any tool where "who did this"
matters. Obtain a token by posting credentials to `POST /auth`:

```bash
curl -s -X POST https://your-instance.example.com/api/v1/auth \
  -H "Content-Type: application/json" \
  -d '{"username": "tech@example.com", "password": "hunter2", "device_name": "iPhone 15"}'
```

The response is one of:

- `{"token": "...", "user": {...}}` — login succeeded, use this token as
  `Authorization: Bearer <token>` on subsequent requests.
- `{"requires_2fa": true}` — the account has TOTP enabled; resubmit the same
  request with a `totp_code` field.
- `401` — bad credentials.

Passkeys/WebAuthn are also supported as a login method: `GET /auth` (no body,
unauthenticated) returns a WebAuthn assertion challenge; complete it
client-side and post the resulting `passkey_response` plus the
`challenge_token` you were given back to `POST /auth`.

To log out and revoke the current token, call `DELETE /auth`.

A bearer token is also required (not just accepted) for a small number of
endpoints that a legacy API key is explicitly denied on — see below.

### Legacy API keys (deprecated: now integration clients)

`X-Api-Key: <key>` was the older, instance-level mechanism. Since DB 2.6.204 there is no separate API-key system: **every key was
converted into an integration client** (Administration > Integration clients, flagged "uses the old X-Api-Key header"), with

| Legacy setting | Becomes |
|---|---|
| Read Only | the scopes `api:<resource>:read` for every general resource |
| Read & Write | `api:<resource>:read` and `:write` |
| Read & Write + Delete | `:read`, `:write` and `:delete` (a read-only key never had delete, whatever its flag said) |
| Department: all / one / several | the client's department scope: all departments / that department / those departments (a key limited to "several" with no departments left reaches nothing) |
| Allowed IPs, expiry, revoked | the client's IP allow-list, expiry (the key stopped at 00:00 of its expire date, and still does) and status |

The same key string authenticates through `X-Api-Key`: it resolves to the migrated client and from then on is subject to exactly the
same scope, department, IP, expiry, rate-limit (the same `int:<client id>` bucket as the client's access tokens) and audit rules as
any other client. Differences from the old behaviour, all deliberate:

- **It no longer acts as the first administrator.** Machine clients act as a fixed, disabled, role-less service identity
  ("API integration service"); their only authority is their scopes and departments (see [Acting identity](#acting-identity-of-a-machine-client)).
- Only the **header** is read. `?api_key=` and a key inside the JSON body are no longer accepted (a secret in a URL or body ends up in logs and proxies).
- Routes that act for a person, or that always demanded a user token, answer 403: `/credentials`, `/me`, `/notifications`, `/approvals`,
  `/endpoint_devices`, `/service_catalog`, `/workflow_tasks`. An unknown route is 403 too (deny by default).
- `/lms`, `/employees` and `/departments` need an access token, never `X-Api-Key`.
- Every `X-Api-Key` response carries `Deprecation: true`, an `X-Api-Key-Notice` header and (when an administrator set a removal date)
  a `Sunset` header. Every use is audit-logged with the client id and counted (last used, request count, last IP), visible on
  Administration > Integration clients so you can see which integrations still use the old header.
- **When an administrator switches the header off, or the removal date passes, `X-Api-Key` answers `401`** with a message explaining
  to authenticate with the Client ID and secret. Access tokens are unaffected.

To migrate an integration: on Administration > Integration clients open the migrated client, press **Create secret**, and change the
integration to `POST /api/v1/oauth/token` + `Authorization: Bearer`. The client keeps its scopes and departments.

```bash
# deprecated, still works until switched off
curl -s https://your-instance.example.com/api/v1/tickets -H "X-Api-Key: your-legacy-key-here"
# preferred
TOKEN=$(curl -s -X POST https://your-instance.example.com/api/v1/oauth/token \
  -d grant_type=client_credentials -d client_id=ic_0123456789abcdef0123 --data-urlencode client_secret="$SECRET" | jq -r .access_token)
curl -s https://your-instance.example.com/api/v1/tickets -H "Authorization: Bearer $TOKEN"
```

### Integration clients (machine credentials for the whole API)

Another system (the QMS) authenticates with an **integration client**, created in *Administration > Integration clients*
(client id `ic_...`, a secret shown once, scopes ticked per client, optional IP allow-list and expiry). It exchanges
the client id and secret for a 10 minute access token:

```bash
curl -s -X POST https://rivetit.example.com/api/v1/oauth/token \
  -d grant_type=client_credentials -d client_id=ic_0123456789abcdef0123 --data-urlencode client_secret="$SECRET" \
  -d "scope=lms:read lms:completions:read"          # scope is optional; omitted = every scope the client holds
# {"access_token":"eyJ...","token_type":"Bearer","expires_in":600,"scope":"lms:read lms:completions:read"}
curl -s https://rivetit.example.com/api/v1/lms/courses -H "Authorization: Bearer $ACCESS_TOKEN"
```

Rules that matter to an integrator:

- **Department scope.** On the general API the scope applies exactly as it did to legacy keys (all / one / several departments; a restricted client with none reaches nothing; list endpoints, by-id reads, search, KB, relationships and reports are filtered). Resources that hold no department data (for example ticket categories, company-wide KB articles) are reachable by any client holding their scope. A client is limited to the departments chosen when it is created (a department is a RivetIT client/department; a person belongs to the department on their record), or holds the explicit choice **All departments**. A client restricted to no department sees nothing. Records outside the scope are indistinguishable from records that do not exist: a by-id read is 404, lists are filtered, and a create for an employee or course outside it answers 422 `employee_not_found` / `course_not_found`. A course is in scope when it has a live requirement rule for an allowed department (or for everyone), a rule naming a person of an allowed department, or an assignment or completion of such a person; the `departments` and `requirements` of a course list only what touches the allowed departments.
- **A token reaches a route only if it holds that route's scope** (deny by default, no wildcard, no implication between scopes); a route with no scope answers 403. It never acts as a user.
  A user token or legacy key is not accepted on `/lms`, `/employees` or `/departments` (401).
- Scopes: `lms:read`, `lms:assign`, `lms:completions:read`, `lms:evidence:read`, `directory:read` (`quality:events` is reserved for later), and, for the general API,
  **`api:<resource>:read`, `api:<resource>:write`, `api:<resource>:delete`** per resource: `alerts`, `appointments`, `assets`, `clients`, `contacts`, `contracts`, `dashboard`, `kb`, `milestones`,
  `outtakes`, `projects`, `relationships`, `reports`, `search`, `tasks`, `ticket_attachments`, `ticket_categories`, `ticket_views`, `tickets` (also `/statuses`) and `worksheets` (also `tickets/charges`).
  Method decides the action: `GET`/`HEAD` need read; `POST`, `PUT`, `PATCH` need write; `DELETE` needs delete. `write` does not include `read`, `delete` does not include `write`.
  `directory:read` is the only scope that opens `/employees` and `/departments`, and it opens nothing else. A route needs its scope in the token; removing a scope from the
  client takes effect at the next request, and so does revoking the client or the secret that issued the token. A token for a client holding many scopes is large (about 2 KB); ask for fewer with the `scope` parameter if a proxy limits header size.
- Person-bound routes are never available to a client, whatever it holds: `/credentials`, `/me`, `/notifications`, `/approvals`, `/endpoint_devices`, `/service_catalog`, `/workflow_tasks`.
  A client may also hold the `sso` capability (OpenID Connect sign-in to the QMS, `docs/integrations/qms-sso.md`); it is not an API scope,
  is never issued in a token here, and an SSO token or ID token is refused on every `/api/v1` route.
- A client holds at most two active secrets, so rotate by adding the new secret (the old one keeps working for the overlap you
  choose) and switching over.
- Token endpoint failures are one uniform `401 invalid_client` and are throttled (`429`, `Retry-After`).
- Errors on these routes are `{"error":{"code","message","correlation_id"}}`; send `X-Correlation-Id` (echoed) to trace a call.
- Lists take `limit` (default 50, max 200), `cursor` (the opaque `next_cursor`) and `updated_since` (RFC 3339).
- `POST` needs an `Idempotency-Key` header (8 to 128 characters). Same key and same body replays the original response
  (`Idempotent-Replay: true`); same key and a different body is `422 idempotency_key_reuse`. Records are kept 24 hours; only
  successful results are stored, so a corrected retry with the same key is processed normally.

See [integrations/qms-lms-sync.md](integrations/qms-lms-sync.md) for the assignment and completion flow and [integrations/qms-directory-sync.md](integrations/qms-directory-sync.md) for the directory feed.

#### Acting identity of a machine client

Legacy keys ran as "the first active administrator", so every key carried a whole administrator's authority. Integration clients do not.
On the general API a client acts as one fixed **service identity** ("API integration service"): a *disabled*, *role-less* user that cannot
sign in, owns no user token, and is not an administrator. It is the user recorded in `created_by`-style columns and the audit trail for
machine writes (the log text names the client, and the legacy-header audit lines carry the client id). Because it holds no role, every
role/permission check inside an endpoint is decided by the client's **scopes** instead (`api:<resource>:read|write|delete`) and its
department scope; there is no path from a client to more than it was granted. An administrator can see the identity under Administration > Users
(disabled); do not enable it or give it a role.

### Public endpoints (no credentials required)

A short list of endpoints is intentionally reachable without any auth header:

- `POST /auth`, `GET /auth` — you need these *to get* a token.
- `POST /oauth/token` — the client-credentials token endpoint for integration clients (above).
- `POST /crash-reports` — mobile crash reporting; must work even when login
  is failing or a token has expired. A token is honored for correlation when
  present but never required.
- `GET /csat` — an aggregate, anonymized satisfaction-rating summary meant
  for embedding on a public page.
- `GET /openapi`, `GET /docs` — the spec and human-readable reference
  themselves.

Everything else requires an integration access token, a user bearer token or (deprecated) a migrated API key.

---

## Conventions

These shapes are consistent across the whole API; per-endpoint deviations
(if any) are called out in the OpenAPI spec.

**Paged lists** return a JSON object:

```json
{ "data": [ /* items */ ], "total": 137 }
```

Some paged endpoints additionally echo `page` and `limit`. Pagination is
via `?page=` (default `1`) and `?limit=` (default `20`) query parameters
where supported.

**Bare-array lists.** A number of list endpoints intentionally return a raw
JSON array instead of the `{data, total}` envelope — typically small,
unpaged reference/lookup lists (ticket statuses, ticket categories, saved
views, worksheet templates, asset types) or one-off sub-resource lists
(a client's tickets/assets/locations/credentials/contracts/files, a ticket's
worksheets/outtakes). Each endpoint's description in the OpenAPI spec notes
"(bare array)" where this applies — check there when in doubt.

**Creates** return the new record's id:

```json
{ "id": 4521 }
```

sometimes with a few extra fields (e.g. ticket creation also returns
`number` and any `attachments` saved with it).

**Simple mutations** (status change, sign, mark read, acknowledge, etc.)
return:

```json
{ "ok": true }
```

**Errors** are a JSON object with a single `error` string, alongside the
matching HTTP status code:

```json
{ "error": "Ticket not found" }
```

Common status codes: `400` bad request/validation, `401` missing or invalid
credentials, `403` authenticated but not permitted (wrong scope, read-only
key, wrong department), `404` not found, `429` rate limited.

**Rate limiting.** Each caller (per user token, or per integration client whether it presents an access token or the deprecated `X-Api-Key`) is limited to 300
requests per 60 seconds. Exceeding it returns `429` with a `Retry-After`
header telling you how many seconds to wait before retrying.

**Search parameter.** Where an endpoint accepts a `search` query parameter,
it's a general substring filter over that resource's obvious fields (name,
subject, etc.) — exact fields are resource-specific.

---

## Endpoint reference

Full request/response schemas for every endpoint below live in the OpenAPI
spec (`GET /api/v1/openapi.yaml`) and the in-app reference (`GET
/api/v1/docs`). The tables here give you the shape of each resource and a
one-line description of every operation so you can find what you need
quickly.

### Auth

Password and passkey login, and logout. See [Authentication](#authentication)
above for the full flow.

| Method | Path | Description |
|---|---|---|
| POST | `/auth` | Password login (or passkey completion) — returns a bearer token, or `requires_2fa` |
| GET | `/auth` | Begin a WebAuthn/passkey assertion — returns a challenge |
| DELETE | `/auth` | Logout — revoke the current bearer token |

### Profile

The signed-in user's own profile. `GET /me` and the profile-update
operations require a bearer token — a legacy API key is denied here, since
there's no specific user for it to represent.

| Method | Path | Description |
|---|---|---|
| GET | `/me` | Current user profile |
| PUT | `/me` | Update profile/password, or register an FCM push token or biometric key |
| POST | `/me` | Alias of `PUT /me` for profile/password updates (FCM push and biometric key registration require `PUT`) |

### Dashboard

A single rollup endpoint for the agent home screen / dashboard: open-ticket
counters and a personal queue.

| Method | Path | Description |
|---|---|---|
| GET | `/dashboard` | Agent dashboard counters (open/mine/overdue/due-today/onsite) plus the caller's ticket queue |

### Clients

Departments — their profile, and the sub-resources scoped to one department:
tickets, assets, locations, credentials (names only), contracts, files, and
included-hours allowance. Remember: the path and field names stay `client` /
`client_id` per the [terminology note](#a-note-on-clients-vs-departments) above.

| Method | Path | Description |
|---|---|---|
| GET | `/clients` | List departments (paged, searchable) |
| GET | `/clients/{id}` | Department detail — profile, contacts, primary location |
| GET | `/clients/{id}/tickets` | Recent tickets for a department (bare array) |
| GET | `/clients/{id}/assets` | Assets for a department (bare array) |
| GET | `/clients/{id}/locations` | Locations for a department (bare array) |
| GET | `/clients/{id}/credentials` | Credential names/URIs for a department — no secrets (bare array) |
| GET | `/clients/{id}/contracts` | Contracts for a department (bare array) |
| GET | `/clients/{id}/files` | Signed outtake/pickup forms for a department (bare array) |
| GET | `/clients/{id}/allowance` | **New.** Included support-hours allowance vs. usage for a calendar month, rolled up across the department's active contracts plus a per-contract breakdown. Accepts optional `month` (1-12) and `year` query params, defaulting to the current month. Remote and onsite hours are tracked separately; a department with no allowance configured on any active contract gets `included: null` for that delivery method rather than a zero. |

### Contacts

People at a department (as opposed to the department/organization record
itself).

| Method | Path | Description |
|---|---|---|
| GET | `/contacts` | List contacts (paged, searchable, filterable by `client_id`) |

### Tickets

The core help-desk resource: listing/creating/reading tickets, replying
(public reply, internal note, or portal/website), logging time, changing
status, uploading attachments, an in-ticket live chat (including an SSE
stream), and billable charges on a ticket (billing module only).

| Method | Path | Description |
|---|---|---|
| GET | `/tickets` | List tickets — paged; filterable by `status`, `mine`, `assigned`, `priority`, `board`, `onsite`, `category_id`, `client_id`, `contact_id`, `overdue`, `due_today`, plus `search`; `priority`, `assigned` (`me`, `unassigned`, agent ids), `board`, `category_id` and `onsite` take a comma list (`priority=high,medium`): OR inside one filter, AND across filters |
| POST | `/tickets` | Create a ticket (JSON, or multipart to attach files at creation) |
| GET | `/tickets/{id}` | Ticket detail, including replies and attachments |
| POST | `/tickets/{id}/reply` | Add a reply, internal note, or portal/client reply (JSON or multipart with files) |
| DELETE | `/tickets/{id}/reply/{replyId}` | Delete a reply and its attachments |
| POST | `/tickets/{id}/time` | Log time against a ticket (recorded as an internal reply) |
| POST | `/tickets/{id}/status` | Change ticket status (moving to a Closed-type status resolves it) |
| POST | `/tickets/{id}/attachments` | Upload one or more attachments to a ticket (multipart) |
| GET | `/tickets/{id}/chat` | Live-chat messages for a ticket; add `?stream=1` for a `text/event-stream` SSE feed instead of a JSON snapshot |
| POST | `/tickets/{id}/chat` | Send a live-chat message (optionally on behalf of a contact via `contact_id`) |
| GET | `/tickets/{id}/charges` | List billable charges on a ticket, with a running total (billing module) |
| POST | `/tickets/{id}/charges` | Add a charge to a ticket (billing module) |

### Ticket Meta

Small reference/lookup lists used to populate ticket filters and forms —
all bare arrays, all effectively static per instance.

| Method | Path | Description |
|---|---|---|
| GET | `/statuses` | Active ticket statuses (bare array) |
| GET | `/ticket-categories` | Ticket categories (bare array) |
| GET | `/ticket-views` | Saved ticket views, pre-translated into list query params (bare array). Each item has `id`, `name`, `icon`, `params` (all strings; a view with several priorities, agents, boards or categories gives a comma list: `priority` `high,medium`, `assigned` `me,unassigned,7`, `board` `3,4`, `category_id` `5,6`; a view assigned to only the caller keeps the older `mine` = `1`), `shared` (shared with all agents), `is_default` and `default_scope` (`personal`, `shared` or null): the view that opens by default for the caller |

### Worksheets

Structured, fillable forms attached to a ticket (built from a template),
with a signature step. Creating one and listing templates live under
`/tickets/{id}`; everything else addresses the worksheet directly by its
own id.

| Method | Path | Description |
|---|---|---|
| GET | `/tickets/{id}/worksheets` | List worksheets attached to a ticket (bare array) |
| POST | `/tickets/{id}/worksheets` | Create a worksheet on a ticket from a template |
| GET | `/worksheets/{id}` | Worksheet detail — fields and current responses |
| DELETE | `/worksheets/{id}` | Delete a worksheet |
| POST | `/worksheets/{id}/sign` | Sign a worksheet |
| POST | `/worksheets/{id}/complete` | Mark a worksheet complete or incomplete, without signing |
| POST | `/worksheets/{id}/responses` | Save worksheet field responses (marks the worksheet complete) |
| GET | `/worksheet-templates` | List worksheet templates available to build a worksheet from (bare array) |

### Outtakes

Device pickup/outtake forms — the paper trail for handing a device to or
from a department, with a signature step. Same shape as Worksheets:
create/list under the ticket, everything else by the outtake's own id.

| Method | Path | Description |
|---|---|---|
| GET | `/tickets/{id}/outtakes` | List device-outtake (pickup) forms for a ticket (bare array) |
| POST | `/tickets/{id}/outtake` | Create a device-outtake form on a ticket |
| GET | `/outtakes/{id}` | Outtake form detail |
| DELETE | `/outtakes/{id}` | Delete an outtake form |
| POST | `/outtakes/{id}/sign` | Sign an outtake form |

### Appointments

Scheduled entries against a ticket (site visits, scheduled calls, etc.).

| Method | Path | Description |
|---|---|---|
| GET | `/appointments` | List appointments — filterable by `when` (`past`/`today`/`future`), `mine`, `client_id` (bare array) |
| POST | `/appointments` | Create an appointment (ticket schedule entry) |

### Assets

Managed devices/equipment tracked per department.

| Method | Path | Description |
|---|---|---|
| GET | `/assets` | List assets — paged; filterable by `type`, `client_id`, plus `search` |
| GET | `/assets/types` | Distinct asset types currently in use (bare array of strings) |
| GET | `/assets/{id}` | Asset detail |

### Credentials

Stored logins/secrets for a department. Listing only ever exposes names and
URIs — the decrypted secret is a separate, deliberately harder call.

| Method | Path | Description |
|---|---|---|
| GET | `/credentials` | List credentials — names/URIs only, no secrets; paged, filterable by `client_id`. Requires a user token — a legacy API key is denied. |
| GET | `/credentials/{id}` | Decrypted credential detail. Requires a user token (legacy key denied) **and** a signed biometric step-up challenge — see below. |

**Biometric step-up for `/credentials/{id}`.** Beyond normal auth, this call
requires two additional headers proving a fresh biometric/passkey unlock on
the device:

- `X-Biometric-Challenge-Token` — the `challengeToken` returned by `GET /auth`
- `X-Biometric-Signature` — a base64 signature of the challenge bytes,
  produced by the device's registered biometric key

This is the one read endpoint in the API with an extra proof-of-presence
step, because it's the one endpoint that returns plaintext secrets.

### Knowledge Base

Internal documentation, organized into categories.

| Method | Path | Description |
|---|---|---|
| GET | `/kb/categories` | List KB categories (bare array) |
| GET | `/kb/articles` | List KB articles — paged, filterable by `category_id`, `client_id`, plus `search` |
| GET | `/kb/articles/{id}` | KB article detail — content and attachments |

### Search

One endpoint, fanning out across several resource types.

| Method | Path | Description |
|---|---|---|
| GET | `/search` | Global search across tickets, departments, and assets. Requires `q` (min length 2). |

### Reports

Operational reporting. Most accept
a `year` query parameter, some also `month`; each returns a report-specific
JSON object — see the OpenAPI spec or `/docs` for each report's exact shape.

| Method | Path | Description |
|---|---|---|
| GET | `/reports/time` | Time logged, grouped by department (`period=week\|month\|all`, `mine`) |
| GET | `/reports/tickets` | Ticket volume by month for a year |
| GET | `/reports/tickets-by-client` | Ticket counts by department |
| GET | `/reports/time-by-tech` | Time logged, grouped by technician |
| GET | `/reports/tech-performance` | Technician performance summary |
| GET | `/reports/technician-performance` | Detailed technician performance report |
| GET | `/reports/service-desk` | Service-desk report |
| GET | `/reports/csat` | CSAT report — rating distribution/trend, per-technician and per-department breakdowns, raw feedback |
| GET | `/reports/rmm-health` | RMM fleet health report |
| GET | `/reports/unbilled-tickets` | Departments with unbilled, billable closed tickets (billing module) |
| GET | `/reports/expiring` | Domains or certificates expiring within N days (`type=domains\|certificates`, `days`, default 30) |
| GET | `/reports/overview` | Open-ticket breakdown by priority/status/category, plus average resolution time |

### Alerts

Combined feed of RMM and backup alerts, with an acknowledge/resolve action.

| Method | Path | Description |
|---|---|---|
| GET | `/alerts` | List alerts — filterable by `status` (default `new`), `severity`, `source` (`rmm`/`backup`/`all`), `client_id` |
| POST | `/alerts` | Acknowledge or resolve an alert |

### Notifications

In-app notifications, including an SSE stream for live delivery.

| Method | Path | Description |
|---|---|---|
| GET | `/notifications` | List undismissed notifications (paged) |
| POST | `/notifications/{id}/read` | Mark a single notification read/dismissed |
| POST | `/notifications/read-all` | Mark all notifications read/dismissed |
| GET | `/notifications/stream` | Server-Sent Events stream of new notifications |

### Integration (LMS)

Integration-client token only (see [Integration clients](#integration-clients-machine-credentials-for-the-whole-api)); not available to user tokens or the deprecated API-key header. Every endpoint below is filtered by the client's department scope; outside it, an id answers 404 exactly like an unknown id.

| Method | Path | Scope | Description |
|---|---|---|---|
| POST | `/oauth/token` | none | Client-credentials grant; form-encoded `grant_type`, `client_id`, `client_secret`, optional `scope`. Returns a 10 minute access token. |
| GET | `/lms/courses` | `lms:read` | Published, non-archived courses (latest published revision): `id`, `kind`, `code`, `external_code`, `title`, `description`, `status`, `revision`, `departments`, `est_minutes`, `validity_months`, `category`, `requirements`, `updated_at`. |
| GET | `/lms/courses/{id}` | `lms:read` | One course. Unpublished, archived and unknown are all 404. |
| POST | `/lms/assignments` | `lms:assign` | Body `employee` (`odoo_employee_id` and/or `rivetit_id`), `course_id`, `due_on`, optional `source`, `external_system` + `external_ref`. Needs `Idempotency-Key`. 201 new, 200 an open assignment (or the same external reference) already existed. Created through the assignment engine (ledger stays valid). |
| GET | `/lms/assignments` | `lms:read` | Filters `employee`, `odoo_employee_id`, `course`, `status`, `external_system`, `external_ref`, `updated_since`. |
| GET | `/lms/assignments/{id}` | `lms:read` | One assignment (`asg_<n>`). |
| GET | `/lms/completions` | `lms:completions:read` | Filters `employee`, `odoo_employee_id`, `course`, `assignment`, `status` (`completed`, `voided`, `superseded`), `updated_since`. Records are never deleted. |
| GET | `/lms/completions/{id}` | `lms:completions:read` | One completion (`cmp_<n>`): course and revision, assignment, `completed_on`, `result`, `score_pct`, `expires_on` (recertification date), `evidence_ref` (certificate number, file available), `status`, `updated_at`, `revision`. |
| GET | `/lms/completions/{id}/evidence` | `lms:evidence:read` | The completion's evidence file (PDF/JPEG/PNG) as an attachment; 404 when none or not servable. |

### Integration (directory)

Integration-client token with scope `directory:read` only; not available to user tokens or API keys, and no `lms:*` scope grants it. Filtered by the client's department scope like the LMS routes: an id outside it answers 404 exactly like an unknown id, and a list simply omits it. `GET` only. Same `limit`, `cursor`, `updated_since`, error format and 600 requests/minute limit as the LMS routes.

| Method | Path | Scope | Description |
|---|---|---|---|
| GET | `/employees` | `directory:read` | Employees (contacts, vendors excluded), ascending id. Terminated and archived people stay in the feed with `active: false`. Fields: `id`, `odoo_employee_id`, `name`, `work_email`, `job_title`, `department_id`, `odoo_department_id`, `manager_id`, `employment_status`, `active`, `updated_at`. Filters `department_id`, `odoo_employee_id`, `updated_since`. |
| GET | `/employees/{id}` | `directory:read` | One employee. |
| GET | `/departments` | `directory:read` | Departments (clients; CRM leads excluded), ascending id. Archived and inactive departments stay with `active: false`. Fields: `id`, `odoo_department_id`, `name`, `parent_id`, `head_employee_id`, `status`, `active`, `updated_at`. |
| GET | `/departments/{id}` | `directory:read` | One department. |

### Diagnostics

| Method | Path | Description |
|---|---|---|
| POST | `/crash-reports` | Report a mobile app crash (logged to App Logs under `mobile_crash`). Public — see [Authentication](#authentication). |

### Public

| Method | Path | Description |
|---|---|---|
| GET | `/csat` | Aggregate CSAT rating summary for public embedding (e.g. a marketing-site trust badge) — average rating, breakdown, and anonymized 4-5 star testimonial snippets only, never department/contact/ticket identifiers. No auth. Cached 5 minutes. |

### Meta

| Method | Path | Description |
|---|---|---|
| GET | `/validate_api_key` | Validate the presented token/key — legacy compatibility probe |
| GET | `/openapi` | This OpenAPI 3.0 spec, as YAML. Public. |
| GET | `/docs` | This human-readable HTML reference, self-contained. Public. |

### Not available yet (unreleased)

`api/v1/metrics_ingest.php` (a device-metrics push endpoint, `POST /api/v1/metrics-ingest`) exists in the source
tree but is **unreleased**: it has no route in `index.php`, no token table in the shipped schema and no
administration screen, so the live API does not serve it and it is deliberately absent from the tables above and
from the OpenAPI spec. See `docs/FINDINGS-DECISIONS.md`.

---

## Example requests

A handful of common flows, end to end. All examples assume
`https://your-instance.example.com` as the host and a bearer token already
obtained via `/auth` stored as `$TOKEN`.

### Login

```bash
curl -s -X POST https://your-instance.example.com/api/v1/auth \
  -H "Content-Type: application/json" \
  -d '{
        "username": "tech@example.com",
        "password": "hunter2",
        "device_name": "iPhone 15"
      }'
```

```json
{ "token": "eyJ...", "user": { "id": 4, "name": "Alex Tech", "email": "tech@example.com", "type": 3 } }
```

If the account has TOTP enabled, the first response is `{"requires_2fa": true}` —
resend the same body with a `totp_code` field added.

### List open tickets assigned to me

```bash
curl -s "https://your-instance.example.com/api/v1/tickets?status=open&mine=1&limit=50" \
  -H "Authorization: Bearer $TOKEN"
```

```json
{
  "data": [
    { "id": 512, "number": 1042, "subject": "VPN keeps dropping", "priority": "high", "status": "In Progress", "client": "Finance", "assigned_to": "Alex Tech", "created_at": "2026-08-30 14:02:11" }
  ],
  "total": 1,
  "page": 1,
  "limit": 50
}
```

Note `client` here is the department name — the `client_id` filter and
`client` field name are unchanged from upstream ITFlow, per the
[terminology note](#a-note-on-clients-vs-departments) above.

### Create a ticket

```bash
curl -s -X POST https://your-instance.example.com/api/v1/tickets \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
        "subject": "New laptop setup for onboarding",
        "details": "New hire starts Monday, needs a laptop imaged and ready.",
        "client_id": 7,
        "priority": "medium",
        "category_id": 3
      }'
```

```json
{ "id": 891, "number": 1043, "attachments": [] }
```

To attach files at creation time, send the same fields as
`multipart/form-data` instead of JSON, with one or more file parts alongside
the form fields.

### Reply to a ticket

```bash
curl -s -X POST https://your-instance.example.com/api/v1/tickets/891/reply \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
        "reply": "Imaged and dropped off at the front desk, ready for Monday.",
        "type": "reply",
        "time_worked": "00:45:00"
      }'
```

```json
{ "id": 2201, "type": "reply", "ticket_status": { "id": 2, "name": "Resolved" }, "attachments": [] }
```

`type` selects the reply kind: `reply` (visible to the department/portal),
`note` (internal only), or `client` (posted as if from the portal/website).
Include `time_worked` (`HH:MM:SS`) to log time in the same call, or use
`POST /tickets/{id}/time` to log time without adding a visible reply.

---

## See also

- Live OpenAPI spec: `GET /api/v1/openapi.yaml`
- Interactive human-readable reference: `GET /api/v1/docs` (also linked from
  **Admin > API Docs**)
- Machine credentials (former API keys included): **Admin > Integration clients** (Admin > API Keys is now a read-only pointer)
- Module toggles (including billing): **Settings > Modules**
