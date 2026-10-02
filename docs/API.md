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

Accounting/billing (invoices, quotes, expenses, ticket charges) is an
optional module, off by default in this edition, toggled at **Settings >
Modules**. With it off, the Invoices, Quotes, and Expenses endpoints — and
the charge sub-resource under Tickets — return empty lists or `404` rather
than an error. Enable the module first if your integration depends on
billing data.

---

## Authentication

Every endpoint other than a short public list (below) requires one of two
credentials, sent on every request:

| Method | Header | Identifies |
|---|---|---|
| Bearer token | `Authorization: Bearer <token>` | A specific user, issued by `POST /auth` |
| Legacy API key | `X-Api-Key: <key>` | The instance, resolved to the first active admin user |

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

### Legacy API keys

`X-Api-Key: <key>` is the older, instance-level mechanism, managed at
**Admin > API Keys**. It doesn't identify a specific
user — it resolves to the instance's first active admin — so it's best
suited to scripts and server-to-server integrations (RMM scripts, backup
tooling, etc.) rather than anything that needs to act as a particular
technician.

Two scoping controls apply to a legacy key:

- **Client scope** — a key can optionally be pinned to one department
  (`client_id`) at creation time, restricting it to that department's data.
  Left unset, it can see all departments.
- **Permission (read / read-write)** — new in this release. Each key has a
  `Permission` of **Read Only** or **Read & Write** (the default). A
  read-only key is accepted only for `GET` requests; any other HTTP method
  is rejected with `403 {"error": "This API key is read-only"}` before the
  request is routed to its handler. Bearer-token auth is unaffected by this
  setting — it only constrains the legacy key mechanism.

A legacy key is **denied** on two resources regardless of its
permission/scope settings, because they act on a specific person's identity
or secrets rather than instance-wide data:

- `/me` (current user profile)
- `/credentials` (client credentials — both the list and detail endpoints)

```bash
curl -s https://your-instance.example.com/api/v1/tickets \
  -H "X-Api-Key: your-legacy-key-here"
```

### Public endpoints (no credentials required)

A short list of endpoints is intentionally reachable without any auth header:

- `POST /auth`, `GET /auth` — you need these *to get* a token.
- `POST /crash-reports` — mobile crash reporting; must work even when login
  is failing or a token has expired. A token is honored for correlation when
  present but never required.
- `GET /csat` — an aggregate, anonymized satisfaction-rating summary meant
  for embedding on a public page.
- `GET /openapi`, `GET /docs` — the spec and human-readable reference
  themselves.

Everything else requires a bearer token or API key.

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

**Rate limiting.** Each caller (per token or API key) is limited to 300
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
| GET | `/tickets` | List tickets — paged; filterable by `status`, `mine`, `priority`, `onsite`, `category_id`, `client_id`, `contact_id`, `overdue`, `due_today`, plus `search` |
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
| GET | `/ticket-views` | Saved ticket views, pre-translated into list query params (bare array) |

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

### Invoices

**Billing module only** — see [above](#billing-modules-are-off-by-default).

| Method | Path | Description |
|---|---|---|
| GET | `/invoices` | List invoices — paged; filterable by `client_id`, `status` |
| GET | `/invoices/{id}` | Invoice detail with line items |

### Quotes

**Billing module only.**

| Method | Path | Description |
|---|---|---|
| GET | `/quotes` | List quotes — paged, searchable, filterable by `client_id` |
| GET | `/quotes/{id}` | Quote detail with line items |

### Expenses

**Billing module only.**

| Method | Path | Description |
|---|---|---|
| GET | `/expenses` | List expenses (paged) |
| POST | `/expenses` | Create an expense (multipart; `description` and `amount` required, `receipt` file optional) |

### Products

Catalog of products/services offered as selectable charge line items.

| Method | Path | Description |
|---|---|---|
| GET | `/products` | List products/services for charge selection — searchable, filterable by `type` (bare array) |

### Search

One endpoint, fanning out across several resource types.

| Method | Path | Description |
|---|---|---|
| GET | `/search` | Global search across tickets, departments, and assets. Requires `q` (min length 2). |

### Reports

Operational and (where billing is enabled) financial reporting. Most accept
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
| GET | `/reports/mrr` | Monthly recurring revenue report (billing module) |
| GET | `/reports/rmm-health` | RMM fleet health report |
| GET | `/reports/unbilled-tickets` | Departments with unbilled, billable closed tickets (billing module) |
| GET | `/reports/clients-with-balance` | Departments carrying an outstanding balance (billing module) |
| GET | `/reports/income-summary` | Income summary for a year (billing module) |
| GET | `/reports/expense-summary` | Expense summary for a year (billing module) |
| GET | `/reports/profit-loss` | Monthly profit and loss for a year (billing module) |
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
- Legacy API key management: **Admin > API Keys**
- Module toggles (including billing): **Settings > Modules**
