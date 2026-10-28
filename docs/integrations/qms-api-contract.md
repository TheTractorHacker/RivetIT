# QMS integration: API contract (draft v1)

Status: draft for agreement with the QMS repository. Base paths: RivetIT `/api/v1`, QMS `/api/v1`. All JSON, UTF-8, HTTPS. Versioned by path; breaking changes get `/v2`.

## Conventions (both directions)

- Auth: `Authorization: Bearer <access token>` obtained with client credentials; scopes listed per endpoint.
- Correlation: `X-Correlation-Id` accepted and echoed; generated when absent.
- Pagination: `?limit=` (default 50, max 200) and opaque `?cursor=`; response `{"data":[...],"next_cursor":null|"..."}`.
- Incremental sync: `?updated_since=<RFC 3339 UTC>`; records carry `updated_at` and `revision`. Deactivated records stay listed with `status`.
- Idempotency: `Idempotency-Key` header on every `POST`; same key + same body returns the original response, same key + different body returns 422 `idempotency_key_reuse`. Keys kept 24 hours.
- Errors: `{"error":{"code":"forbidden","message":"...","correlation_id":"..."}}` with the right HTTP status; 429 includes `Retry-After`.
- Rate limits: per client, headers `X-RateLimit-Limit`, `X-RateLimit-Remaining`.
- IDs: stable opaque strings. People carry `odoo_employee_id` (nullable), `rivetit_id`, `qms_id` (nullable).

## RivetIT endpoints (consumed by the QMS)

| Method and path | Scope | Notes |
|---|---|---|
| `POST /oauth/token` | none | client credentials |
| `GET /lms/courses`, `GET /lms/courses/{id}` | `lms:read` | published revisions only: id, title, description, status, revision, department, est_minutes, category, requirements, external_code |
| `POST /lms/assignments` | `lms:assign` | body: employee (odoo_employee_id or rivetit_id), course_id, due_on, source, external_ref, external_system. Validates both ids, reuses an open assignment, created via AssignmentService. 201 new, 200 reused |
| `GET /lms/assignments`, `GET /lms/assignments/{id}` | `lms:read` | filter employee, course, status, external_ref, updated_since |
| `GET /lms/completions`, `GET /lms/completions/{id}` | `lms:completions:read` | id, employee, course_id, course_revision, assignment_id, completed_at, status (`completed`,`voided`,`superseded`), score_pct, evidence_ref, updated_at, revision. History is never deleted |
| `GET /lms/completions/{id}/evidence` | `lms:evidence:read` | streamed after object check; 404 if none or not allowed |
| `GET /employees`, `GET /employees/{id}` | `directory:read` | id, odoo_employee_id, department_ids, manager_id, employment_status, job_title, updated_at. No contact details |
| `GET /departments`, `GET /departments/{id}` | `directory:read` | id, odoo_department_id, parent_id, head_employee_id, status, updated_at |
| `POST /integrations/qms/events` | `quality:events` | inbound QMS events (signed) |

## QMS endpoints (consumed by RivetIT; proposed, to agree)

`GET /me/quality/tasks`, `GET /me/quality/documents`, `POST /me/quality/documents/{id}/acknowledgments`, `GET /me/quality/training`, `GET /quality/actions`, `GET /quality/actions/{id}`, `POST /quality/actions/{id}/responses`, `GET /quality/reports/summary`, `GET /integrations/health`. The `/me/` routes act for the person in the signed per-user context; the QMS enforces its own authorization on every one.

## Events (RivetIT -> QMS, and QMS -> RivetIT where noted)

Envelope: `{"id":"uuid","type":"...","schema_version":1,"occurred_at":"RFC3339","source":"rivetit","resource":{"type":"...","id":"..."},"data":{...}}`.
Types: `lms.assignment.created`, `lms.assignment.updated`, `lms.completion.recorded`, `lms.completion.corrected`, `employee.updated`, `employee.deactivated`, `department.updated`. Signed as in the security doc. Receivers must treat events as hints and re-fetch the resource.

## Example: assignment request

```
POST /api/v1/lms/assignments
Idempotency-Key: 6c1d2d0e-9c9e-4a3e-9f58-2a1f6c7e1d11
{"employee":{"odoo_employee_id":"1842"},"course_id":"crs_torque_basics","due_on":"2026-11-12","source":"qms","external_system":"qms","external_ref":"NCR-0381"}
```

## Open points for the QMS repository

Agree the per-user context format, event envelope, `qms_id` ownership, health payload and the `/me/` routes; confirm it can verify RivetIT-signed assertions and store the Odoo employee ID.
