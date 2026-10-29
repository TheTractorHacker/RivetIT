# QMS <- RivetIT directory: employees and departments

Status: implemented in the directory API v1 (issue #52, PR 2). Companion to [qms-api-contract.md](qms-api-contract.md) (the contract),
[qms-security.md](qms-security.md) (credentials, department scope) and [qms-lms-sync.md](qms-lms-sync.md) (assignments and completions).

RivetIT is the source of the employee and department list when it is connected (it in turn mirrors Odoo through the Odoo directory sync).
The QMS reads it; nothing here writes.

## What a record is

| QMS word | RivetIT record | Key |
|---|---|---|
| Employee | a contact (`contacts`, vendor contacts excluded) | `odoo_employee_id` from `contact_odoo_links` is the **identity key**; `id` is the RivetIT contact id |
| Department | a client (`clients`, CRM leads excluded) | `odoo_department_id` from `client_odoo_links`; `id` is the RivetIT client id |

Both ids are strings. `odoo_*_id` is `null` when the record is not linked to Odoo (match on it, never on name). When a record is linked to
more than one Odoo integration the lowest id is returned, as on the LMS routes.

## Endpoints (scope `directory:read`, GET only)

* `GET /api/v1/employees`, `GET /api/v1/employees/{id}`
* `GET /api/v1/departments`, `GET /api/v1/departments/{id}`

Employee fields: `id`, `odoo_employee_id`, `name`, `work_email`, `job_title`, `department_id`, `odoo_department_id`, `manager_id`,
`employment_status`, `active`, `updated_at`. Department fields: `id`, `odoo_department_id`, `name`, `parent_id`, `head_employee_id`,
`status`, `active`, `updated_at`. Nothing else is selected: no phone, PIN, address, photo, notes or portal role.

`employment_status` is the RivetIT value (`pre-hire`, `active`, `leave`, `suspended`, `transfer_pending`, `termination_pending`,
`terminated`, `archived`). `active` is `false` for `terminated`, `archived` and for every archived contact; every other status is `true`, so
key finer decisions (for example not provisioning `pre-hire`) on `employment_status`. A department's `status` is `active`, `inactive`,
`on_hold` or `archived`; `active` is `false` for `archived` and `inactive`.

## Deprovisioning (tombstones)

Deactivated people and departments are **not removed** from the feed. They stay with `active: false`, and the change moves `updated_at`,
so an incremental pull picks them up. The QMS should mark such an employee terminated, keep all their history, and stop assigning to them.
A record that is **hard-deleted** in RivetIT, or that **leaves the client's department scope** (a person moved to another department), simply
stops appearing: it cannot be reported as a tombstone. Run the periodic full pass (below) to catch those.

## Incremental sync, paging and ordering

* `limit` (default 50, max 200, larger is clamped), `cursor` (the opaque `next_cursor`) and `updated_since` behave exactly as on the LMS routes.
* Ordering is ascending `id`, always, so cursor paging is stable while rows change.
* `updated_since` is an RFC 3339 timestamp (impossible dates such as month 13 are `400 invalid_updated_since`) and is inclusive. A record's
  `updated_at` is the latest of its own update, creation and archive times and its Odoo link's update time. The row's own update time can also
  move for housekeeping writes (for example when a profile is opened), so an incremental pull may return a record that did not change in any way that
  matters; treat the feed as idempotent upserts.
* Filters on `/employees`: `department_id` (RivetIT id) and `odoo_employee_id`; both give an empty list, not an error, for anything outside the scope.
* Recommended: an incremental pull every few minutes with `updated_since` = the last pull's start minus a small overlap, plus a nightly full pass (no
  `updated_since`) as the backstop for hard deletes and scope moves.

## Department scope and enumeration

A client is limited by the same explicit department scope as the LMS routes (`IntegrationScope::allowedDepartmentIds`, read from the
database on every request): all departments, or only the listed ones. A restricted client sees only those departments and the employees whose
department is one of them. A person with **no department** is visible to all-department clients only. A restricted client with no
departments, or any unexpected scope value, sees nothing (fail closed).

An id outside the scope answers `404 not_found`, byte-for-byte the same as an unknown id (never 403), and lists simply omit it.
References are filtered too: `manager_id`, `parent_id` and `head_employee_id` are `null` unless the record they point at is also in the scope.

## Authentication and limits

* Access tokens come from `POST /oauth/token` and must carry `directory:read`. A client without the scope cannot request it; narrowing
  or revoking takes effect at the next request. No `lms:*` scope grants the directory, and `directory:read` grants nothing on `/lms`.
* An integration token reaches a route only with that route's scope (`directory:read` here); a QMS client should hold only its `lms:*` and `directory:read` scopes, so every other route is 403 for it. A user token or legacy
  key is `401` on the directory routes.
* Rate limit: 600 requests per minute per client, shared with the LMS routes (one bucket per client). `429` with `Retry-After`.
* Audit: rejected tokens are audited exactly as on the LMS routes (rate-limited per IP); successful reads are not logged per request, on
  the directory or the LMS routes. Administrator changes to a client's scopes or departments are audited.
* Errors are `{"error":{"code","message","correlation_id"}}`; send `X-Correlation-Id` to trace a call.

## Operational notes

* No database change and no module switch: it needs an integration client with `directory:read` and the Odoo directory sync for the Odoo ids.
