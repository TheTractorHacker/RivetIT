# QMS <-> RivetIT LMS: assignment and completion flow

Status: implemented in the LMS API v1 (issue #52, PR 1). Companion to [qms-api-contract.md](qms-api-contract.md) (the contract) and
[qms-security.md](qms-security.md) (credentials). The consumer view is the QMS repository's `docs/integrations/rivetit-lms.md`.

The employee and department feed that this flow's `odoo_employee_id` values come from is described in [qms-directory-sync.md](qms-directory-sync.md).

## Roles

| RivetIT | QMS |
|---|---|
| Course delivery, assessment, scoring, certificates, kiosk and portal training | Decides which training a quality requirement needs, and for whom |
| Owns the assignment lifecycle and the completion record (hash-chained ledger) | Keeps its own evidence copy and the compliance view; never edits RivetIT records |

## Credentials

One integration client per QMS environment (Administration > Integration clients) with the scopes it needs, normally
`lms:read lms:assign lms:completions:read lms:evidence:read`. The QMS calls `POST /api/v1/oauth/token` (client credentials),
caches the 10 minute access token and asks for a new one shortly before it expires. Rotate secrets with the overlap window
(two secrets may be active at once). The token is valid on `/api/v1/lms/*` only.

## Identifiers and the mapping table

| Thing | Id | Notes |
|---|---|---|
| Employee | `rivetit_id` (contact id) and `odoo_employee_id` | The QMS stores both in its mapping table. `odoo_employee_id` is the stable key when Odoo is the employee source; people are never matched by name. If one Odoo id is linked to two RivetIT people the call is refused (`employee_ambiguous`): send `rivetit_id`. |
| Course | `id` (12 characters) from `GET /lms/courses`; `external_code` is the code the QMS knows it by | Course ids are stable; a new published revision keeps the id and raises `revision`. |
| Assignment | `asg_<n>`, plus `(external_system, external_ref)` | The QMS puts its requirement id in `external_ref` (for example `NCR-0381`); RivetIT guarantees one assignment per pair. |
| Completion | `cmp_<n>` | Carries the assignment id and the assignment's external reference so the QMS can match without a lookup. |

## 1. Course catalog (RivetIT -> QMS)

`GET /lms/courses` (poll with `updated_since`). Only courses with a published revision that are not archived appear. Map each to a QMS
training class by `id` or `external_code`. `revision` is the published revision a learner will see; `requirements` and `departments` describe
the rules that already assign the course automatically.

## 2. Assignment (QMS -> RivetIT)

```
POST /api/v1/lms/assignments
Idempotency-Key: 6c1d2d0e-9c9e-4a3e-9f58-2a1f6c7e1d11
{"employee":{"odoo_employee_id":"1842"},"course_id":"AbCd3FgH1jKl","due_on":"2026-11-12",
 "source":"qms","external_system":"qms","external_ref":"NCR-0381"}
```

* The request goes through RivetIT's assignment engine (the same path as "Assign training" in the web UI), which records the
  `requirement.saved` and `assignment.created` events in the training ledger. The caller is recorded as a service (no user): the rule is
  named "Assigned by integration client <name>" and its note carries the source and reference.
* **201** a new open assignment. **200** the employee already had an open assignment for the course (from a rule, the UI, or an earlier
  request) or the `external_ref` already maps to this employee and course: the existing assignment is returned and `reused` is true. If the
  existing assignment had no external reference yet, this one is attached to it.
* Retrying with the same `Idempotency-Key` and body returns the stored response (`Idempotent-Replay: true`). Same key, different body:
  `422 idempotency_key_reuse`. A request that failed validation does not consume its key.
* Refusals the QMS should handle: `422 employee_not_found` / `employee_ambiguous` / `employee_mismatch` / `employee_not_eligible` (inactive, off the
  training roster), `422 course_not_published`, `422 due_on_in_past`, `409 external_ref_conflict` (that reference belongs to another
  employee or course), `409 already_current` (the employee already holds a valid completion), `409 assignment_waived`, `503 busy` (retry).

`GET /lms/assignments?external_ref=NCR-0381&external_system=qms` finds it again; `status` is `open`, `completed`, `waived` or `cancelled`
and `overdue` is derived. When the assignment closes, `completion_id` points at the completion.

## 3. Completion (RivetIT -> QMS)

Poll `GET /lms/completions?updated_since=<last sync>` (webhooks come in a later PR and are only hints). Each record has:

`course_id` and `course_revision` (the revision the learner completed), `assignment_id` and `assignment.external_ref`, `completed_on`,
`result` (`passed`; RivetIT only records successful completions), `score_pct` and `pass_mark_pct`, `expires_on` (the **recertification
date**), `evidence_ref.certificate_number`, `status`, `recorded_at`, `updated_at`, `revision`.

Completion records are **never deleted**. A correction shows up as a state change on the same record, so re-reading with `updated_since`
picks it up:

| `status` | Meaning |
|---|---|
| `completed` | A valid record. |
| `voided` | Cancelled by a RivetIT administrator with a reason (kept for the audit trail). The QMS should reopen its requirement. |
| `superseded` | A later record replaced it (`supersedes_id` on the new one points back). Use the newer record. |

`revision` increases by one for each such change and `updated_at` moves to the time of the change.

## 4. Evidence

`evidence_ref.evidence_available` tells whether a scan is attached (`certificate_number` is the reference for RivetIT-issued
certificates). `GET /lms/completions/{id}/evidence` (scope `lms:evidence:read`) streams the file as an attachment after the same check
the web evidence endpoint applies: the file must be the evidence of that completion. A missing completion, no file, a file that is not
servable and a missing file on disk are all the same 404. The QMS should **copy** the file into its own storage at sync time so its
evidence survives if the connection is later removed; the `certificate_number` and completion id are the link back.

## Reconciliation

Run a periodic full pass (`GET /lms/completions` without `updated_since`, and assignments by `external_ref` for open QMS requirements) as the
backstop for missed polls. Everything is read-only apart from `POST /lms/assignments`, which is idempotent.

## Operational notes

* Needs the Training module on, the database update to 2.6.201 and at least one integration client.
* Rate limit: 600 requests per minute per client; the token endpoint is throttled separately.
* Every token refusal, scope refusal on the token endpoint and admin action (create, edit, rotate, revoke) is written to the audit log;
  secrets and tokens are never logged.
