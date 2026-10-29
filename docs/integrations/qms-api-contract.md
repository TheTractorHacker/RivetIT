# QMS integration: API contract (draft v1)

Status: draft for agreement with the QMS repository. **Implemented in RivetIT (PR 1 of #52):** `POST /oauth/token` and everything under `/lms`; the tables below say where shipped behaviour differs from the first draft. Not built yet: employees and departments, events/webhooks, `POST /integrations/qms/events`, and the RivetIT-side QMS client. Base paths: RivetIT `/api/v1`, QMS `/api/v1`. All JSON, UTF-8, HTTPS. Versioned by path; breaking changes get `/v2`.

## Conventions (both directions)

- Auth: `Authorization: Bearer <access token>` obtained with client credentials (`POST /oauth/token`, form-encoded, 10 minute token); scopes listed per endpoint. The token is accepted on `/lms/*` only.
- Correlation: `X-Correlation-Id` accepted and echoed; generated when absent.
- Pagination: `?limit=` (default 50, max 200) and opaque `?cursor=`; response `{"data":[...],"next_cursor":null|"..."}`.
- Incremental sync: `?updated_since=<RFC 3339 UTC>`; records carry `updated_at` and `revision`. Deactivated records stay listed with `status`.
- Idempotency: `Idempotency-Key` header (8 to 128 characters) on every `POST`; same key + same body returns the original response (with `Idempotent-Replay: true`), same key + different body returns 422 `idempotency_key_reuse`; a duplicate while the first is still running is `409 idempotency_in_progress`. Keys kept 24 hours. Only successful (2xx) results are stored, so a request that failed can be retried with the same key once fixed.
- Errors: `{"error":{"code":"forbidden","message":"...","correlation_id":"..."}}` with the right HTTP status; 429 includes `Retry-After`.
- Rate limits: per client (600 requests per minute on `/lms`; the token endpoint is throttled per IP and per client). Over the limit: `429` with `Retry-After`. The `X-RateLimit-*` headers are not sent.
- IDs: stable opaque strings. People carry `odoo_employee_id` (nullable) and `rivetit_id` (both strings; `qms_id` belongs to the QMS and RivetIT does not store it in PR 1). Courses use a 12 character id (never the database row id); assignments are `asg_<n>` and completions `cmp_<n>`. Timestamps are RFC 3339 UTC with milliseconds; `*_on` fields are plain dates in the company time zone.

## Department scope

A client is limited to the departments chosen when it is created (a department is a RivetIT client/department; a person belongs to the department on their record), or holds the explicit choice **All departments**. A client restricted to no department sees nothing. Every `/lms` endpoint is filtered by it on the server. Outside the scope an object answers exactly like one that does not exist (404 by id, filtered lists, 422 `employee_not_found` / `course_not_found` on `POST /lms/assignments`), never 403. Courses: in scope when a live rule for an allowed department or for everyone, a rule naming one of its people, or an assignment/completion of one of its people links them; an unlinked course is visible to all-department clients only. People are scoped by their current department. The QMS asks the RivetIT administrator for the departments it needs.

## RivetIT endpoints (consumed by the QMS)

| Method and path | Scope | Notes |
|---|---|---|
| `POST /oauth/token` | none | client credentials; `application/x-www-form-urlencoded` (`grant_type`, `client_id`, `client_secret`, optional `scope`) or HTTP Basic. Uniform `401 invalid_client`; `429` throttle. **Built** |
| `GET /lms/courses`, `GET /lms/courses/{id}` | `lms:read` | **Built.** Published, non-archived courses only: id, kind, code, external_code, title, description, status, revision, revision_id, **departments** (an array, from the active requirement rules; the draft's single `department` does not exist because courses are not owned by one department), est_minutes, validity_months, category, requirements, updated_at. Title and description are the published revision's text. `external_code` is the column `course_external_code`; it has no edit screen yet (set it in the database until a later PR adds one) |
| `POST /lms/assignments` | `lms:assign` | **Built.** body: `employee` object (`odoo_employee_id` and/or `rivetit_id`; if both, they must be the same person), course_id, due_on, source, external_system + external_ref (together). Created through the assignment engine (`RequirementService::createManual` -> `AssignmentService::reconcile`; `AssignmentService` has no create method of its own), actor recorded as a service. 201 new, 200 reused (an open assignment for the pair, or the same external reference). `source` is echoed and kept in the rule note, not stored on the assignment |
| `GET /lms/assignments`, `GET /lms/assignments/{id}` | `lms:read` | **Built.** Filters employee (RivetIT id), odoo_employee_id, course, status (`open`, `completed`, `waived`, `cancelled`), external_system, external_ref, updated_since. Fields: id, employee, course_id, course_revision, status, overdue, required, reason, due_on, original_due_on, external_system, external_ref, completion_id, created_at, closed_at, close_reason, updated_at, revision |
| `GET /lms/completions`, `GET /lms/completions/{id}` | `lms:completions:read` | **Built.** id, employee, course_id, course_revision, course_revision_id, assignment_id, assignment (external_system, external_ref), **completed_on** (a date, not the draft's `completed_at` timestamp), result (`passed`), score_pct, pass_mark_pct, method, status (`completed`,`voided`,`superseded`), supersedes_id, **expires_on** (recertification date), **evidence_ref** (object: certificate_number, external_issuer, external_ref, evidence_available, evidence_path), recorded_at, updated_at, revision. Filters employee, odoo_employee_id, course, assignment, status, updated_since. History is never deleted; a void or supersession bumps `revision` and `updated_at` |
| `GET /lms/completions/{id}/evidence` | `lms:evidence:read` | **Built.** Streamed as an attachment after the web endpoint's visibility check (`EvidenceStore::canServe` with a service context); 404 if none, not allowed, or the file is missing |
| `GET /employees`, `GET /employees/{id}` | `directory:read` | **Built.** id (RivetIT contact id), **odoo_employee_id** (the identity key), name, **work_email**, job_title, **department_id** (one department per person; the draft's `department_ids` list does not exist because a contact has a single `contact_client_id`), odoo_department_id, manager_id, employment_status, **active** (false = terminated or archived, deprovision), updated_at. Filters department_id, odoo_employee_id, updated_since. Name and work email were added to the draft's list at Datum's request; no phone, PIN, address, photo or notes. See [qms-directory-sync.md](qms-directory-sync.md) |
| `GET /departments`, `GET /departments/{id}` | `directory:read` | **Built.** id (the `clients.client_id`), odoo_department_id, name, parent_id, head_employee_id, status (`active`, `inactive`, `on_hold`, `archived`), **active**, updated_at. Archived and inactive departments stay in the feed (tombstones); a CRM lead is not a department |
| `POST /integrations/qms/events` | `quality:events` | *Not built yet.* inbound QMS events (signed) |

## QMS endpoints (consumed by RivetIT; proposed, to agree)

`GET /me/quality/tasks`, `GET /me/quality/documents`, `POST /me/quality/documents/{id}/acknowledgments`, `GET /me/quality/training`, `GET /quality/actions`, `GET /quality/actions/{id}`, `POST /quality/actions/{id}/responses`, `GET /quality/reports/summary`, `GET /integrations/health`. The `/me/` routes act for the person in the signed per-user context; the QMS enforces its own authorization on every one.

## Events (RivetIT -> QMS, and QMS -> RivetIT where noted)

Envelope: `{"id":"uuid","type":"...","schema_version":1,"occurred_at":"RFC3339","source":"rivetit","resource":{"type":"...","id":"..."},"data":{...}}`.
Types: `lms.assignment.created`, `lms.assignment.updated`, `lms.completion.recorded`, `lms.completion.corrected`, `employee.updated`, `employee.deactivated`, `department.updated`. Signed as in the security doc. Receivers must treat events as hints and re-fetch the resource.

## Example: assignment request

```
POST /api/v1/lms/assignments
Idempotency-Key: 6c1d2d0e-9c9e-4a3e-9f58-2a1f6c7e1d11
{"employee":{"odoo_employee_id":"1842"},"course_id":"AbCd3FgH1jKl","due_on":"2026-11-12","source":"qms","external_system":"qms","external_ref":"NCR-0381"}
```

## Open points for the QMS repository

Agree the per-user context format, event envelope, `qms_id` ownership, health payload and the `/me/` routes; confirm it can verify RivetIT-signed assertions and store the Odoo employee ID.
