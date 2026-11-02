# Quality integration: the contract the QMS (Datum) must implement

Version 1. Tracking issues: #52 (Quality module and LMS API) and #55 (RivetIT as OpenID Connect provider). This page is the exact list of what the standalone quality management system has to provide so RivetIT's Quality module works against it. RivetIT's side is built and tested against a mock that behaves exactly as described here (`tests/support/mock_qms.php`, a runnable reference for every rule below). Nothing in this document changes the QMS database: RivetIT only ever uses the QMS's HTTP API.

Companion pages: [integrations/qms-overview.md](integrations/qms-overview.md) (design), [integrations/qms-configuration.md](integrations/qms-configuration.md) (setting it up), [integrations/qms-security.md](integrations/qms-security.md), [integrations/qms-sso.md](integrations/qms-sso.md) (sign-in and its validation checklist), [integrations/qms-lms-sync.md](integrations/qms-lms-sync.md) and [integrations/qms-directory-sync.md](integrations/qms-directory-sync.md) (the RivetIT APIs the QMS calls).

Words: **must** is a requirement of this contract; RivetIT will not work correctly without it. Base paths: the QMS serves `/api/v1/...` under the address an administrator saved in RivetIT (a path prefix such as `https://quality.example.com/qms` is allowed; every path below is relative to `<address>/api/v1`).

## 1. Transport and conventions

- HTTPS only (TLS 1.2 or later, a certificate that validates for the host). RivetIT does not follow redirects: a `3xx` is an error. RivetIT connects to the address it vetted at call time, so the QMS must answer on the address it publishes.
- JSON, UTF-8. `Content-Type: application/json` on request bodies. Responses larger than 1 MiB are discarded by RivetIT; page large lists.
- `X-Correlation-Id` on every RivetIT request (letters, digits, `.`, `_`, `-`, at most 64 characters). The QMS **must** echo it in the response header and put it in its own log line. RivetIT writes it to its integration error log, so an administrator can match the two sides.
- Errors: `{"error":{"code":"snake_case","message":"text","correlation_id":"..."}}` with the right HTTP status. RivetIT shows people a generic message plus the correlation id and shows administrators the `code`; it never shows `message` text to people.
- Pagination on every list: `?limit=` (default 50, max 200) and an opaque `?cursor=`; response `{"data":[...],"next_cursor":null|"..."}`.
- Dates are `YYYY-MM-DD`; timestamps are RFC 3339 UTC with milliseconds (`2026-11-01T14:03:22.418Z`).
- Timeouts RivetIT applies (settings): connect 3 s, total 10 s (portal requests use a shorter budget). A GET, and any POST that carries an `Idempotency-Key`, is retried up to 3 times with exponential backoff and jitter on a network failure, `408`, `429` (honouring `Retry-After`, capped at 5 s), `502`, `503` and `504`. Nothing else is retried. The QMS **must** therefore make every POST idempotent on the `Idempotency-Key`.

## 2. The machine credential (service token)

The QMS is an OAuth 2.0 authorization server for RivetIT as a client.

| | |
|---|---|
| `POST /oauth/token` | form body `grant_type=client_credentials&scope=<space separated scopes>`; client authentication `client_secret_basic` (HTTP Basic; id and secret URL-encoded first, RFC 6749 2.3.1). |
| Success | `200 {"access_token":"...","token_type":"Bearer","expires_in":300}`. `access_token` at most 4096 characters. RivetIT caches it (sealed) until 60 seconds before it expires and asks for a new one after a `401`. |
| Failure | `401 {"error":"invalid_client"}` uniformly; `400 invalid_scope`/`invalid_request`. |
| Scopes RivetIT asks for | `quality:read quality:write` (configurable on the RivetIT admin page). |

The token identifies the **system** calling. It does not identify a person and it **must never be enough to read or write a person's items** (section 3). The token endpoint **must** be on the same host as the API (RivetIT refuses a token address on another host).

## 3. The per-user context (who the request is for)

Every request for a person's items (`/me/...` and `/quality/...`, sections 5 and 6) carries, in addition to the bearer token:

```
X-Rivet-User-Context: <JWT>
```

A request to those paths **without** a valid context **must** be answered `403 {"error":{"code":"user_context_required"}}`. Requests to `/integrations/...` never carry one.

The JWT is signed by RivetIT with the same ES256 (P-256) key it publishes for single sign-on, so the QMS needs no second trust relationship:

1. Discover the keys: fetch `https://<RivetIT>/.well-known/openid-configuration`, read `jwks_uri`, fetch the JWKS (cache it, honouring `Cache-Control`; refetch once when a `kid` is unknown; a retired key stays published for 15 minutes after rotation).
2. Header: `alg` **must** be `ES256` (reject `none` and every symmetric algorithm), `typ` **must** be `rivetit-user-context+jwt` (a user context is never mistaken for an ID token or an access token), `kid` selects the key.
3. Verify the signature, then these claims, rejecting the request on any failure:

| Claim | Value and rule |
|---|---|
| `iss` | RivetIT's issuer, exactly the `issuer` in the discovery document. |
| `aud` | **Must equal the QMS's own base address** as RivetIT calls it (for example `https://quality.example.com`, no trailing slash). Refuse a context issued for another audience. |
| `sub` | The person: the **Odoo employee id** as a string (`"1842"`) when the person is linked to Odoo, otherwise `"rivetit:<contact id>"`. A `rivetit:` prefix means "not linked to Odoo". Never an e-mail address or a name. |
| `rivetit_id` | RivetIT's contact id (string). Stable. |
| `odoo_employee_id` | The Odoo employee id, or null. |
| `qms_id` | The QMS's own id for this person if the QMS has told RivetIT (section 8), else null. |
| `dept` | `{"rivetit_id":"18","odoo_id":"77"}`: the person's department in RivetIT and in Odoo (`odoo_id` may be null). |
| `roles` | `["employee"]`, or `["employee","manager"]` for a department manager. **Authorize manager endpoints from this claim only.** |
| `scp` | What this request may do, e.g. `["me:read"]`, `["manager:read"]`, `["documents:acknowledge"]`, `["actions:respond"]`. The QMS **must** refuse an operation the scope does not name. |
| `iat`, `nbf`, `exp` | `exp - iat` is 60 seconds. Allow at most 30 seconds of clock skew. |
| `jti` | Unique per context. Keep seen `jti` values for the lifetime of the token (about 2 minutes) and refuse a repeat on a **write** (`POST`) request. |
| `cid` | The correlation id of the request. |

There is no name, e-mail, phone or PIN in a context. If the QMS needs a display name it already has it from its own person record, found by the identifiers above.

**Authorization is the QMS's job, per person.** For `/me/...` the QMS returns only items that belong to the person in `sub` and only what that person may see under the QMS's own rules. For `/quality/...` it requires `manager` in `roles`, limits everything to the department in `dept`, and treats the `department` query parameter RivetIT also sends as a hint only: if it disagrees with `dept`, answer `403`. A service token never widens what a person may see.

Matching a person: use the Odoo employee id (`sub` when numeric) or `rivetit_id`. **Never match by name or e-mail.** An unmatched person goes to the QMS's review queue (the QMS answers `403` or an empty list; it must not create an account from a context).

## 4. System endpoint

| | |
|---|---|
| `GET /integrations/health` | Bearer token only. `200 {"status":"ok","version":"1"}`. `status` is `ok`, `healthy` or `up` when the QMS is well; anything else is shown to the administrator as "reported status X". May also carry `"last_event_at"` and `"time"`. RivetIT calls this from the admin "Test connection" button and every sync pass (default every 5 minutes). |

## 5. Employee endpoints (`/me/quality/...`, for the person in the context)

Common list response: `{"data":[ ...items... ],"next_cursor":null}`. RivetIT keeps only the fields below (anything else is dropped), truncates text (title 200 characters), and shows a link only when its address is `https` on the QMS's own host.

| Endpoint | Items |
|---|---|
| `GET /me/quality/tasks` | `id`, `title`, `status` (`open`, `in_progress`, `overdue`, `done`), `due_on`, `priority`, `overdue` (bool), `url` |
| `GET /me/quality/actions` | `id`, `title`, `status`, `due_on`, `overdue`, `requires_response` (bool: show the response box), `owner` (display text, optional), `url` |
| `GET /me/quality/documents` | `id`, `title`, `version`, `status` (`pending_acknowledgment` or `acknowledged`), `due_on`, `acknowledged_on`, `url` |
| `GET /me/quality/training` | `id`, `title`, `status`, `due_on`, `course_id` (RivetIT LMS course id, if the requirement is a RivetIT course), `url` |
| `GET /me/quality/notifications` | `id`, `title`, `created_at`, `read` (bool), `url` |
| `GET /me/quality/history` | `id`, `title`, `event`, `occurred_at`, `url` |
| `POST /me/quality/documents/{id}/acknowledgments` | body `{"acknowledged":true}`; **requires** an `Idempotency-Key` header and scope `documents:acknowledge`. `201` recorded; `200` with `Idempotent-Replay: true` for a repeated key; `404` for a document that is not the person's to acknowledge (same answer as unknown). The QMS records who, which version and when. |
| `POST /quality/actions/{id}/responses` | body `{"text":"..."}` (1 to 4000 characters); **requires** `Idempotency-Key` and scope `actions:respond`; only the person the action is assigned to (per the QMS's rules). `201` recorded. |

`Idempotency-Key`: 8 to 128 characters of `A-Za-z0-9._:-`. Same key and same body returns the original outcome with `Idempotent-Replay: true`; same key with a different body is `422 idempotency_key_reuse`; a repeat while the first is running is `409`. Keep keys at least 24 hours. Only successful (2xx) outcomes need to be stored.

## 6. Manager endpoints (`/quality/...`, require `manager` in `roles`)

All limited to the department in `dept`; a non-manager gets `403 not_a_manager`.

| Endpoint | Response |
|---|---|
| `GET /quality/reports/summary` | `{"data":{"open_actions":4,"overdue_actions":1,"overdue_tasks":2,"pending_acknowledgments":7,"training_compliance_pct":91.6,"as_of":"2026-11-01T10:00:00Z"}}` (integers; percentage 0 to 100) |
| `GET /quality/actions` | corrective actions of the department (fields as in 5, plus `owner`) |
| `GET /quality/tasks?status=overdue` | overdue tasks of the department (fields as in 5) |
| `GET /quality/training/compliance` | `id`, `title`, `assigned`, `completed`, `overdue`, `pct` per requirement or course |
| `GET /quality/documents/acknowledgments?status=pending` | `id`, `title`, `version`, `pending_count`, `due_on` |
| `GET /quality/actions/{id}` | one action in the department (`404` otherwise; unknown and forbidden answer alike) |

## 7. Events

Events are **hints**. Both sides treat them as "something changed, go and look": the receiver re-reads the resource from the sender's API. Delivery is at-least-once, unordered, and never the only synchronization path (RivetIT also reconciles on a schedule; the QMS must too, with `updated_since` on the RivetIT LMS and directory APIs).

### 7.1 Envelope (both directions)

```json
{"id":"5f2c1d9e-3b7a-5c41-8e0a-0a1b2c3d4e5f","type":"employee.updated","schema_version":1,
 "occurred_at":"2026-11-01T14:03:22.418Z","source":"rivetit",
 "resource":{"type":"employee","id":"13"},
 "data":{"rivetit_id":"13","odoo_employee_id":"1842","department_id":"18","odoo_department_id":"77","active":true,"updated_at":"2026-11-01T14:03:22.418Z"}}
```

`id` is unique for the event and **deterministic** for RivetIT events (the same change always has the same id), so the receiver dedupes on it. `schema_version` is 1; a receiver that sees a higher number answers `422 unsupported_schema_version`; an unknown `type` at a known version is acknowledged and ignored.

### 7.2 Signature (both directions)

```
X-Rivet-Signature-V2: t=<unix seconds>,v1=<lowercase hex hmac-sha256>
v1 = HMAC-SHA256(secret, "<t>.<raw request body bytes>")
```

One secret per direction (created on the RivetIT admin page, shown once). The receiver **must**: compute the HMAC over the raw bytes it received; compare in constant time; refuse a `t` more than 300 seconds old or 60 seconds in the future; answer `401 invalid_signature` uniformly. Test vector:

```
secret = qsk_exampleSecretDoNotUse
t      = 1793500000
body   = {"id":"5f2c1d9e-3b7a-5c41-8e0a-0a1b2c3d4e5f","type":"employee.updated","schema_version":1,"occurred_at":"2026-11-01T14:03:22.418Z","source":"rivetit","resource":{"type":"employee","id":"13"},"data":{"rivetit_id":"13","odoo_employee_id":"1842","department_id":"18","odoo_department_id":"77","active":true,"updated_at":"2026-11-01T14:03:22.418Z"}}
header = t=1793500000,v1=eb6c6837bb9f51d8a510764647b54f5b154e6457a0876faad02f7dec3bc7dabe
```

(The timestamp window makes the vector fail verification today; check the HMAC only.)

### 7.3 RivetIT to the QMS: `POST /integrations/rivetit/events`

Headers: `X-Rivet-Signature-V2`, `X-Rivet-Event-Id` (= `id`), `X-Rivet-Event-Type` (= `type`), `X-Correlation-Id`. No bearer token (the signature authenticates the sender). The QMS answers `2xx` (`200`, `202` or `204`) once it has **stored** the event (process it afterwards if it likes). `409` or `200` for an `id` it has seen is fine. Retry behaviour on RivetIT's side: network failure, `408`, `429`, `5xx` are retried after 1 min, 5 min, 15 min, 1 h, 4 h, 12 h, 24 h (with jitter); after 8 attempts, or at once for any other `4xx`, the event is dead-lettered and an administrator replays it.

| `type` | `resource` | `data` (identifiers, status, timestamps only; no names, e-mail, phone, PIN) |
|---|---|---|
| `lms.assignment.created`, `lms.assignment.updated` | `assignment` / `asg_<n>` | `employee {rivetit_id, odoo_employee_id}`, `course_id`, `status`, `revision`, `external_system`, `external_ref`, `due_on`, `updated_at` |
| `lms.completion.recorded`, `lms.completion.corrected` | `completion` / `cmp_<n>` | `employee`, `course_id`, `assignment_id`, `status` (`completed`, `voided`, `superseded`), `revision`, `completed_on`, `expires_on`, `updated_at` |
| `employee.updated`, `employee.deactivated` | `employee` / contact id | `rivetit_id`, `odoo_employee_id`, `department_id`, `odoo_department_id`, `active`, `updated_at`. `employee.deactivated` means de-provision: **keep the person's history** and stop assigning new work. |
| `department.updated` | `department` / client id | `rivetit_id`, `odoo_department_id`, `status`, `active`, `updated_at` |

Only people and departments that an administrator enabled for Quality produce events. On first start RivetIT sends nothing from the past; the QMS does its baseline from the RivetIT APIs (`GET /lms/...`, `GET /employees`, `GET /departments`; see qms-lms-sync.md and qms-directory-sync.md). An administrator can queue a full resync (one event per record), and the QMS can ask for one (7.4).

### 7.4 The QMS to RivetIT: `POST /api/v1/integrations/qms/events` (on RivetIT)

Authenticated twice: the QMS's integration-client bearer token (RivetIT Administration > API Keys; scope `quality:events`; token from RivetIT's `POST /api/v1/oauth/token`) **and** the HMAC header with the inbound secret. `Content-Type: application/json`, body at most 64 KiB, `schema_version` 1.

| Answer | Meaning |
|---|---|
| `200 {"status":"processed"\|"ignored"\|"duplicate","id":"..."}` | accepted (a duplicate of an event already seen is not processed again) |
| `400 invalid_event` | not a valid envelope |
| `401 invalid_signature` / `401 invalid_token` | bad or stale signature; bad token |
| `403 insufficient_scope` | token lacks `quality:events` |
| `409 event_id_reuse` | the same `id` with a different body |
| `413`, `415` | too large; not `application/json` |
| `422 unsupported_schema_version` / `event_rejected` | version unknown; the event could not be applied (the person or department is unknown or ambiguous) |
| `503 module_disabled` | the Quality module (or "accept events") is off in RivetIT: retry later |

| `type` | `data` | Effect in RivetIT |
|---|---|---|
| `qms.employee.mapped` | `qms_id`, and **`rivetit_id` or `odoo_employee_id`** (never a name) | stores the QMS's id for the person; it travels as `qms_id` in later user contexts. An Odoo id shared by two people is refused (`422`): send `rivetit_id`. A `qms_id` already mapped to someone else is refused. |
| `qms.department.mapped` | `qms_id`, `rivetit_id` | stores the QMS's id for the department |
| `qms.sync.requested` | `streams`: any of `assignments`, `completions`, `employees`, `departments` (default all) | queues a full resync of those streams on the next pass |
| `qms.requirement.changed` | `external_ref` | recorded; nothing to do (RivetIT polls assignments by reference) |

### 7.5 Using the RivetIT LMS API from the QMS

Create training from a quality requirement with `POST /api/v1/lms/assignments` (`Idempotency-Key` required; `external_system: "qms"` and `external_ref: "<your requirement id>"` give one assignment per reference). Read progress with `GET /lms/assignments`, `GET /lms/completions?updated_since=` and `GET /lms/completions/{id}/evidence`. Employees and departments: `GET /employees`, `GET /departments` (scope `directory:read`). Full contract: qms-api-contract.md.

## 8. People and ids

The Odoo employee id is the shared key. RivetIT stores the QMS's id for a person only when the QMS sends `qms.employee.mapped`. Contractors without an Odoo id use `rivetit:<contact id>` as `sub` and `rivetit_id` as the key. Deactivation never deletes history on either side.

## 9. Single sign-on

RivetIT is the OpenID Connect provider and the QMS is the relying party; the whole flow, claims and the validation checklist are in [integrations/qms-sso.md](integrations/qms-sso.md). It is independent of the machine credential in section 2: a sign-in token never calls these APIs and these APIs never accept a sign-in token.

## 10. What the QMS team must build (checklist)

1. OAuth client-credentials token endpoint (section 2) and the health endpoint (4).
2. User-context verification (3) on every `/me` and `/quality` route, including the `jti` replay cache for writes, per-person authorization and the manager rule from `roles`/`dept`.
3. The employee and manager endpoints (5, 6) with the field names above, `Idempotency-Key` on the two POSTs.
4. The event receiver `POST /integrations/rivetit/events` (7.2, 7.3): signature, store-then-acknowledge, dedupe on `id`.
5. An event sender to RivetIT (7.4) for `qms.employee.mapped` at least, and a scheduled reconciliation against the RivetIT APIs.
6. Relying-party sign-in (qms-sso.md checklist): discovery, PKCE, state and nonce, strict issuer/audience/signature/expiry/nonce checks, match by Odoo id, unmatched people to the review queue, its own short session, local logout.
7. Tests against RivetIT's mock behaviour (`tests/support/mock_qms.php`) and, before going live, the checklist in qms-configuration.md.
