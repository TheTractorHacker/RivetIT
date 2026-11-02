# QMS integration: overview

Status: built on the RivetIT side (issues #52 and #55), everything off by default. The checklist at the end is the source of truth for progress; what needs the real QMS is marked there. Setup: [qms-configuration.md](qms-configuration.md). What the QMS must implement: `docs/QUALITY_CONTRACT.md`.

## Purpose

RivetIT does not become a quality management system. A separate open-source QMS owns quality records and workflows (corrective actions, controlled documents, nonconformances, reports). RivetIT provides:

- the **Quality module**: employee and manager screens in the department portal and kiosk that show the signed-in person's own QMS items, and send their responses to the QMS API;
- the **LMS** (the Training module): course delivery, assignments, completions and evidence, exposed to the QMS through a new `/api/v1/lms/` API;
- the **directory**: employees and departments, synchronized from Odoo.

## Decisions

| Topic | Decision |
|---|---|
| Identity flow | Odoo -> RivetIT -> QMS. RivetIT is the hub for Midwest. The QMS also supports direct Odoo and standalone use, but that is not turned on at Midwest. |
| Shared person key | The Odoo employee ID. Each system also stores its own ID and the others'. People are never matched by name or email alone. |
| Machine credential | A new integration-client credential (OAuth 2.0 client credentials, short-lived signed tokens, named scopes). The old API keys and the `X-Api-Key` header are gone: keys were converted into API keys without a secret (DB 2.6.204; an administrator creates one) and no API key runs as an administrator. |
| Per-user authorization | A service token identifies the calling system only. Every employee-facing request carries a per-user context, and the QMS and RivetIT each enforce their own rules. A service token never widens what a user may see. |
| Module state | Enforced on the server: routes, API, cron, workers and event processing all re-check the setting. The UI hides things as a convenience only. |
| Module switch | `settings.config_module_enable_quality`. QMS URL, secrets and intervals live in side tables because `settings` is near the MariaDB row-size limit. |
| Disabling Quality | Hides navigation, rejects portal routes, stops Quality cron and event processing. Keeps settings, mappings and history. The LMS is not affected. |
| Edition boundary | Built in the RivetIT tree (`src/`, edition-owned). RivetCore tables are not altered. The LMS is not in RivetMSP, so Quality is RivetIT-only unless that changes. |
| Where it lives | The department portal (`client/`) and the kiosk. There are no Quality pages or navigation in the agent interface, so IT staff do not work in it. The only agent-side surface is the administrator settings page (Settings > Modules > Quality) for setup, health and errors. |
| Who can use it | Portal and kiosk users, by department (`quality_departments`) and portal role (`contact_portal_role`), using the existing portal and kiosk sign-in. Managers see only their own department. The agent role module `module_quality` is used only to decide which administrators may change the settings. |
| Writes to the QMS | Only through its authenticated API. Never to its database. |

## What exists and is reused

Module toggle pattern (Training), role permission rows, `rivetEmitEvent` and the job queue with retries and dead-lettering, `WebhookDispatcher` HMAC signing, `UrlPolicy` SSRF checks, `encryptSetting`, the API v1 front controller and department scoping helpers, the Odoo directory mapper and link tables, the Training services, kiosk device and PIN security, the `--if-*` design tokens.

## What is built new

1. `quality` module switch, role module `module_quality`, admin page, audit events.
2. QMS API client (`src/Quality/Client`): pinned HTTPS, timeouts, bounded retries with backoff, correlation IDs, pagination, structured errors, versioned contract.
3. Integration-client credential and token endpoint (`/api/v1/oauth/token`), named scopes, rotation, revoke, audit.
4. `/api/v1/lms/*`, `/api/v1/employees`, `/api/v1/departments` with `updated_since` cursors, plus an `Idempotency-Key` store.
5. Inbound event receiver with signature, timestamp window, replay table and event log; outbound events through the existing bus.
6. Department portal and kiosk pages (employee and manager), no agent-side pages.
7. Tests, OpenAPI file, remaining docs.

## Database changes (additive)

`settings.config_module_enable_quality`; `modules` row `module_quality`; `quality_integrations` (URL, encrypted secrets, status); `quality_features`; `quality_departments`; `quality_inbound_events` (unique source + event id, payload hash); `quality_sync_state`; `integration_clients`, `integration_client_secrets`, `integration_tokens`; `api_idempotency`; `training_assignments.tassign_external_system`, `tassign_external_ref` (unique together); optional `training_courses.course_external_code`. Every step is idempotent, mirrored in `db.sql`, and takes the next DB version after the one on beta when it is built.

## Risks to keep in view

- Assignments must be created through `AssignmentService` so the open-assignment guard and the hash-chained ledger stay valid. No direct SQL.
- Evidence is streamed through PHP only. nginx blocks the uploads folder.
- Training permission code reads web-session globals. API handlers need an explicit user context.
- A QMS on a private network needs `config_webhook_allowed_networks`.
- If RivetIT is down the QMS keeps its own directory copy and catches up by reconciliation; webhooks are never the only path.
- People with no Odoo link (contractors) are handled explicitly: RivetIT ID only, or excluded from Quality by setting.

## Phases and checklist

- [x] Audit of the codebase
- [x] UI mockup approved (content only; real pages use RivetIT styles)
- [x] Architecture, credential design, hub decision (this document)
- [x] API contract (`qms-api-contract.md`, `docs/QUALITY_CONTRACT.md`, OpenAPI). *Agreement with the QMS repository is still to be confirmed by its owner.*
- [x] Phase 1: module switch (`settings.config_module_enable_quality`, DB 2.6.230), admin settings page (Administration > Quality), portal route and navigation gating, cron gating, API route gating (no agent navigation). Role module `module_quality` is deliberately not added: only administrators reach the settings page.
- [x] Phase 2: integration clients and token endpoint (PR 1 of #52)
- [x] Single sign-on provider (#55): RivetIT as an OpenID Connect provider for the QMS (`qms-sso.md`); the Quality module switch and the department list are enforced in `QualityAccessPolicy`
- [x] Phase 3: QMS API client (`src/Quality/QmsClient.php`): pinned HTTPS, client credentials, per-user signed context, bounded retries, correlation ids, pagination; connection test and health on the admin page
- [x] Phase 4: LMS API (courses, assignments, completions, evidence), idempotency (PR 1 of #52)
- [x] Phase 5: employees and departments API, Odoo ID mapping, incremental sync (`qms-directory-sync.md`)
- [x] Phase 6: outbound signed events with outbox, retry, dead letter, replay and reconciliation; inbound receiver with signature, replay protection and ID mapping; sync health; integration error log (DB 2.6.231)
- [x] Phase 7: employee and manager department portal pages with the "QMS unavailable" state
- [x] Phase 8: kiosk page (counts, required training, document acknowledgment with PIN; no corrective-action details)
- [x] Phase 9: tests (`tests/quality_module.php`, `tests/quality_ssrf.php`, mock QMS `tests/support/mock_qms.php`), docs
- [ ] End-to-end check against the real QMS (Datum). Needs the Datum side, see the go-live checklist in `qms-configuration.md`.

## Where things are

| Piece | Where |
|---|---|
| Module switch, settings, gate | `src/Quality/QualityConfig.php`, `settings.config_module_enable_quality`, `quality_settings`, `quality_features`, `quality_departments` |
| QMS client, address vetting | `src/Quality/QmsClient.php`, `src/Quality/QmsUrl.php` |
| Per-user context | `src/Quality/UserContext.php` |
| Portal views | `client/quality.php`, `client/quality_manage.php`, `client/quality_action.php`, `src/Quality/QualityService.php`, `src/Quality/Reauth.php` |
| Kiosk | `kiosk/quality.php`, `src/Training/Kiosk/Strings/quality.php` |
| Events | `src/Quality/Outbox.php`, `src/Quality/SyncWorker.php`, `src/Quality/InboundEvents.php`, `api/v1/quality_events.php`, `cron/quality_worker.php` |
| Health and log | `src/Quality/SyncHealth.php`, `src/Quality/QualityLog.php`, `admin/quality.php`, `admin/post/quality.php` |
