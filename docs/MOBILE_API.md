# Mobile API: approvals, catalog requests, workflow tasks, attachments

Server-side endpoints for the RivetIT Android app. They live under `/api/v1/` next to the rest of the API (flat per-resource files, see `api/v1/index.php`) and are described in `api/v1/openapi.yaml` (tag **Mobile**). There is no schema change: everything reads and writes the existing `service_catalog_*`, `workflow_*` and `ticket_attachments` tables through `ServiceCatalogService` and `WorkflowService`.

## Common rules

- **Auth**: `Authorization: Bearer <token>` from `POST /api/v1/auth`. No token or a bad one is `401`.
- **Module-only (limited) logins** get `403` on all four endpoints (they may only use `me`, `notifications` and their own modules).
- **Legacy `X-Api-Key`** is refused (`403`) on `approvals`, `service_catalog` and `workflow_tasks` because the decision must be attributable to a person; `ticket_attachments` accepts it like the other ticket endpoints.
- **Permissions** mirror the web pages: catalog and attachments need Tickets (`module_support`, view for GET, edit for POST); workflow tasks need Departments (`module_client`, view for GET, edit for POST; `scope=all` needs edit); approvals need whichever of the two covers the kind. Admin roles pass everything. Department (client) access is enforced on every record, like `enforceClientAccess()`.
- **Errors** are `{"error": "message"}`. Validation is `422`. Anything the caller may not see is answered like something that does not exist (`404` for approvals/tasks, `403` for tickets/attachments), so ids cannot be probed.
- Rate limit: the usual per-token limit (300/min).

## Approvals

`GET /api/v1/approvals.php`

```json
{"items":[{"kind":"catalog_request","id":12,"title":"New laptop","requester":"Dana Tech","summary":"T1003 - Laptop request (Dept A)","risk_score":5,"step":"Step 1","ticket_id":41,"requested_at":"2026-10-06 09:12:00","due_at":null,"fields":[{"label":"Reason","value":"Need it"}]}],"counts":{"total":1}}
```

`kind` is `catalog_request` (`id` = request id) or `workflow_task` (`id` = run task id; `risk_score`, `step` and `ticket_id` are null). Only approvals the caller may decide right now are listed: catalog steps addressed to them (administrators also see every other pending request and decide it as an override, like the web inbox) and workflow approval tasks they are an approver for (administrators: all), in runs that are not paused or cancelled.

`POST /api/v1/approvals.php` with `{"kind":"catalog_request","id":12,"decision":"approve","comment":"ok"}` returns `{"ok":true,"status":"approved"}` (`status` may be `rejected`, or `pending_approval` when a further catalog step is now waiting). `comment` is required for `reject`. Errors: `422` validation, `404` unknown / already decided / not yours (one identical answer), `409` the approval was decided between the check and the write. State machine, ticket hold/release, SLA, notifications and audit (`logs` row, `workflow.approval_*` audit event) are the web paths.

```sh
curl -s -H "Authorization: Bearer $TOKEN" https://itflow.example/api/v1/approvals.php
curl -s -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"kind":"workflow_task","id":4,"decision":"reject","comment":"Not this month"}' https://itflow.example/api/v1/approvals.php
```

## Service catalog

`GET /api/v1/service_catalog.php` returns `{"items":[{"id","name","description","icon","requires_approval","risk_score","fields":[{"key","label","type","options","required","placeholder","show_if"}]}],"popular":[ids],"recent":[ids]}`. Active items only. `popular` is the top five of the last 30 days, `recent` the caller's last five distinct items. `type` is `text|textarea|select|checkbox|date|number`; `options` is only filled for `select`. `show_if` is always `null` (the catalog has no conditional fields yet).

`POST` with `{"catalog_item_id":1,"answers":{"reason":"Need it","qty":2,"rush":true},"client_id":1}` returns `201 {"ok":true,"ticket_id":41,"status":"created"|"pending_approval"}`. Validation is `ServiceCatalogService::validateInput` (required, number, date `Y-m-d`, select choice, 500/5000 length). Answers for keys that are not defined fields are ignored and never stored. JSON booleans and numbers are accepted for checkbox and number fields. The caller is the requester (`ticket_created_by`, `requested_by_user_id`); the ticket contact is the client's primary contact (none when `client_id` is null/0, an internal request). `403` when the department is outside the caller's access, `404` unknown or inactive item, `422` validation (`error` is the messages joined, `errors` the list).

```sh
curl -s -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"catalog_item_id":1,"answers":{"reason":"Need it"},"client_id":1}' https://itflow.example/api/v1/service_catalog.php
```

## Workflow tasks

`GET /api/v1/workflow_tasks.php?scope=mine` returns `{"items":[{"id","run_id","run_title","task_title","instructions","due_at","status","type","blocked_by":[titles],"contact_name","assignee"}],"counts":{"open","overdue"}}`. Open tasks only (`status` is `pending`, `blocked`, `running`, `action_failed` or `rejected`; `type` is `manual|approval|action`) of onboarding/offboarding runs that are in progress or paused. `mine` = assigned to the caller plus unassigned tasks, always limited to runs whose department the caller may access. `scope=all` lists everyone's open tasks and needs Departments edit access (`403` otherwise).

`POST` with `{"id":3,"action":"complete"}` or `{"id":2,"action":"skip","reason":"Not needed"}` returns `{"ok":true,"status":"completed"|"skipped"}`. `WorkflowService` rules apply: a blocked task cannot be completed or skipped, an approval task cannot be completed by hand, only an administrator may skip an approval, skipping needs a reason (these are `422` with the service's message). A task that does not exist or whose run is in a department the caller cannot access is `404`.

## Ticket attachments

- `GET /api/v1/ticket_attachments.php?ticket_id=41` returns `{"items":[{"id","name","size","mime","created_at","uploaded_by"}]}` (`uploaded_by` is the reply author for reply attachments, otherwise null: the table does not record uploaders). `mime` comes from the stored extension.
- `GET /api/v1/ticket_attachments.php?id=7&download=1` streams the file with `Content-Type`, `Content-Length`, `Content-Disposition: attachment; filename="..."; filename*=UTF-8''...` (control characters, quotes and slashes are stripped from the name), `X-Content-Type-Options: nosniff`, `Cache-Control: no-store` and a sandboxing `Content-Security-Policy`.
- `POST` multipart with `ticket_id`, optional `reply_id` (must belong to the ticket) and one `file` returns `201 {"ok":true,"id":7}`. Rules: the web extension allow-list (`jpg jpeg gif png webp pdf txt md doc docx odt csv xls xlsx ods pptx odp zip tar gz xml msg json wav mp3 ogg mov mp4 av1 ovpn`), every extension segment checked (`shell.php.png` is refused), content sniffing (an image extension must hold an image; PHP/HTML/script content is refused whatever it is called), the client's Content-Type is ignored, a random stored name under `uploads/tickets/<ticket_id>/`, the display name reduced to its last path component, and the PHP `upload_max_filesize`/`post_max_size` limits (`413`) on top of the 500 MB application cap.
- For a ticket outside the caller's departments, and for an unknown attachment id, the answer is the same `403`.

```sh
curl -s -H "Authorization: Bearer $TOKEN" -F ticket_id=41 -F file=@photo.png https://itflow.example/api/v1/ticket_attachments.php
curl -s -H "Authorization: Bearer $TOKEN" -o photo.png 'https://itflow.example/api/v1/ticket_attachments.php?id=7&download=1'
```

## Notifications and push

An approval request keeps its recipients and message; only the identifiers are new. The in-app notification action is `/agent/service_catalog_approvals.php?request_id=N` (catalog) or `workflow_run.php?run_id=R&approval_task=N` (workflow; the web pages ignore the extra parameter). The push data is `{"type":"approval","kind":"catalog_request"|"workflow_task","id":"N","action":"..."}` (FCM data values are strings), and `GET /api/v1/notifications` returns such entries with `"type":"approval"`, `"kind"` and `"ref_id"`; all other notifications are unchanged. Notifications created before this release have no id and keep their old type.

## Tests

`tests/mobile_api.php` runs real HTTP against a throwaway `php -S` and real API tokens on a scratch database (see the header of the file).
