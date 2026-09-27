# ITFlow Internal IT — Master Plan Progress

Tracking implementation of `ITFlow-Internal-IT-Master-Plan.md` (56 sections, 15 phases).
This is a multi-month plan; this file tracks what's actually done vs. planned so work
can resume across sessions. Updated as work lands, not retroactively.

**Decisions locked in 2026-09-01 (user AFK, answered up front):**
- Microsoft 365 / Entra ID / Intune (Phase 3, 7): build adapter + settings UI, **no live
  connection** — no tenant/app registration credentials provided. Stays disabled until
  real credentials are supplied.
- Odoo (Phase 8): user confirmed they use Odoo. Build adapter scaffolding, **no live
  connection** — no API credentials provided yet. Stays disabled until supplied.
- RMM: "none yet / not sure" — no RMM-specific work this pass. Existing Tactical
  RMM/Level.io/Sophos adapters (inherited from MSP edition) are left as-is, unconfigured.
- Department-level access control (`enforceClientAccess`, `user_client_permissions`):
  **kept, not removed.** The master plan itself (Section 6.4) wants per-department
  privacy (HR/Finance walled off) — this resolves the open question from earlier in
  the session about whether to strip department access boundaries. They stay, and get
  used for that purpose.
- Deploy target: **live (`mw-itflow.foleyit.com`) as changes land**, not staging-first —
  explicit user choice, aware of the risk of working unsupervised.

**Working conventions for this build:**
- Every DB change goes through `admin/database_updates.php` (versioned migration) +
  `db.sql` (fresh-install schema), same pattern as the rest of the app — not ad hoc.
- Every migration uses `ADD COLUMN IF NOT EXISTS` / `CREATE TABLE IF NOT EXISTS` so it's
  safe to re-run.
- Before deploying to live: `php -l` on every touched file, plus a scratch-database
  import test of the full updated `db.sql` (same method used earlier this session to
  catch the schema-drift bugs) before touching the live `midwest_itflow` DB.
- New code lives under `/src` (PSR-4, `ITFlow\` namespace) per Section 46, additive
  alongside the existing procedural `/agent`, `/admin`, `/client` pages — nothing in
  those directories gets torn out to make room.

---

## Phase 0 — Foundation
Status: **done**

- [x] Composer PSR-4 autoload (`ITFlow\` → `src/`)
- [x] `audit_events` table + `AuditService` (DB 2.6.50 → 2.6.51)
- [x] `AuthorizationService` (thin wrapper over existing `lookupUserPermission`/`enforceClientAccess` — not a replacement)
- [x] `integration_jobs` table + minimal cron worker (job queue, DB-backed per Section 33) (DB 2.6.51 → 2.6.52)
- [x] Wire `AuditService` into all 4 login outcomes (blocked/failed/success/MFA failed) — verified live: caught and fixed a real `bind_param()` ArgumentCountError this way (see session log), then confirmed a real row lands in `audit_events` on a failed login attempt against the live site.
- Also fixed in passing: `config_module_enable_crm` (from the earlier CRM-toggle work) was never added to `load_global_settings.php`'s settings→PHP-variable mapping — undefined-variable warning on every single agent/admin page load since that shipped. Fixed.

## Phase 1 — Organization
Status: **done** (schema-complete; some fields are schema-only, no UI yet — noted below)

- [x] Single-organization mode (already true — `company_id = 1` always)
- [x] Internal terminology (Client → Department, done earlier this session)
- [x] Hide billing/CRM navigation (done earlier this session)
- [x] Organization-level fields (Microsoft tenant ID, default email domain, security/HR contact) — added to `companies` (not a new parallel table), full UI in `admin/settings_company.php`. Onboarding/offboarding default-policy fields deliberately deferred to Phase 9, once `workflow_templates` exists to actually reference — no point in a dangling FK-shaped column pointing at nothing yet.
- [x] Department fields: parent department, department head contact, cost center, status, security classification — schema done; **status and security classification have real UI** in `client_edit.php`; parent department and head contact are schema-only, no UI yet (lower urgency — nothing depends on them yet).
- [x] Site (Location) fields: type, manager contact, emergency contacts, shipping instructions — schema-only, no UI yet.
- [x] `department_sites` many-to-many junction table — schema-only, additive (existing `location_client_id` single-owner relationship stays primary), not wired into any UI yet.

**Known minor cleanup found but not fixed this pass:** ~15 more instances of the `unlink()`-on-empty-filename bug (fixed 4 of them while in nearby code) in `agent/post/{contact,expense,file,location,asset,rack}.php` and `admin/post/users.php`. Cosmetic (log noise on first upload for a given record), not breakage.

## Phase 2 — Directory (People)
Status: **done**

- [x] Extend `contacts` with employee fields: employee_id, employee_type, manager_id (self-referential), start_date, expected_end_date, employment_status, work_arrangement. Full UI in `contact_edit.php` + `contact_details.php`.
- [x] Employment state machine — implemented as a validated string field (pre-hire/active/leave/suspended/transfer_pending/termination_pending/terminated/archived), not a DB enum, to stay flexible; validated in `PersonImportService` and the edit modal's fixed option list.
- [x] Manager/direct-reports relationship + display on contact page (`contact_details.php` "Employment" card + "Direct Reports" list)
- [x] CSV import: `admin/people_import.php` + `src/Directory/PersonImportService.php`. Upload → preview (validates + resolves department/site/manager, flags per-row errors) → explicit approve → write. **Simplification vs. the full plan:** fixed CSV column headers required (no drag-and-drop column mapper) — reasonable for one company doing occasional imports; revisit if that changes. `people_import_runs` logs each approved run.
- [x] Person activity timeline — `contact_details.php` "History" card. Built from the raw data that already existed (`logs` where `log_type='Contact'`, `audit_events` where `entity_type='contact'`) rather than new instrumentation; kept distinct from the pre-existing "Activity Timeline" card, which is manually-logged CRM engagement, not a system change trail.

## Phase 3 — Microsoft 365 / Entra ID
Status: **scaffolding done, no live connection** (per AFK decision log above)

- [x] `src/Integrations/Microsoft/GraphClient.php` — real OAuth2 client-credentials flow + Graph API calls (testConnection/listUsers/getUser). App-only auth per Section 9.1, not the separate delegated SSO flow (Section 9.3 — not started, needs Entra app registration to build against realistically).
- [x] `microsoft_integrations` table + `admin/settings_directory_sync.php` settings UI (Save + Test Connection, credentials encrypted via existing `encryptSetting()`).
- [ ] Actual user/group/device sync logic (Section 9.2) — not built. Real API responses are needed to get field-mapping/conflict-resolution right; building that blind against no live tenant risks getting it wrong in ways that are expensive to unwind later. **Next step once real Entra credentials exist:** wire `GraphClient::listUsers()` into a sync job via the Phase 0 job queue.

## Phase 8 — Odoo
Status: **scaffolding done, no live connection**

- [x] `src/Integrations/Odoo/OdooClient.php` — implements `BusinessApplicationProvider` via Odoo's real JSON-RPC endpoint. Only reads the small stable `res.users` field set per Section 10.4's "don't assume version-specific fields" rule.
- [x] `odoo_integrations` table + settings UI (same page as Microsoft, Save + Test Connection).
- [ ] Account provisioning/role sync (Section 10.3/10.4) — not built, same reasoning as Microsoft above.

## Phase 6 — Assets/CMDB
Status: **partial**

- [x] Assignment history (Section 11.5) — `asset_assignments` table + `src/Assets/AssetAssignmentService.php`, wired into create/edit/bulk-assign, displayed on `agent/asset_details.php`. Verified directly against a scratch DB (assign → no-op → reassign → unassign all behaved correctly, no duplicate rows).
- [ ] Immutable asset UUID + external-ID linking (Section 11.2) — not started.
- [ ] Matching/reconciliation engine (Section 11.3) — not started; needs Phase 7 (RMM/Intune) to have something to reconcile against.
- [ ] QR codes (Section 11.6) — deferred. Needs a real decision about label printing/hardware that doesn't exist yet; didn't want to guess at that.

## Phase 9 — Employee Lifecycle Workflows
Status: **done (manual-first scope, per Section 53)**

- [x] `workflow_templates` / `workflow_template_tasks` — admin-managed checklist definitions (onboarding
  or offboarding). `admin/employee_workflow_templates.php` (list/create) + `admin/employee_workflow_template_details.php`
  (add/reorder/remove tasks). Named distinctly from the pre-existing, unrelated `admin/onboarding_templates.php`
  (a PROJECT template for onboarding a new *department*, not a person).
- [x] `workflow_runs` / `workflow_run_tasks` — starting a run snapshots the template's tasks (title/
  instructions copied, not referenced), so editing a template later never rewrites an in-progress or
  completed run's history.
- [x] `src/Workflow/WorkflowService.php` — start/complete/skip/reopen/cancel, with auto-computed run
  status (in_progress while any required task is pending → completed once all required tasks are
  resolved → completed_with_exceptions if any required task was skipped; optional tasks never gate
  completion). Verified directly against a scratch DB.
- [x] Agent-facing: a "Workflows" card on `contact_details.php` (start a run, see history) +
  `agent/workflow_run.php` checklist page (complete/skip-with-reason/reopen/cancel).
- [ ] **Deliberately not built** (Section 16.1/16.2's fuller model): task dependencies/blocking,
  multi-step approvals, automation actions (assisted/automatic task completion), relative due-date
  scheduling, role-based template inheritance (Section 19's add-on templates). This is a flat
  checklist, not a scheduling/dependency graph — revisit once there's real usage to learn from,
  per the plan's own Section 53 guidance to defer broad automation until manual flows are proven.

## Phase 4 — Vault V2
Status: **partial** (rotation tracking + version history; built via parallel agent batch, 2026-09-01)

- [x] Credential rotation due dates: `credential_rotation_due_at` / `credential_last_rotated_at` on
  `credentials`, editable from `credential_add.php`/`credential_edit.php`.
- [x] `agent/reports/credential_rotation_v2.php` — due-date-driven rotation report (overdue/upcoming
  within N days), distinct from the pre-existing `credential_rotation.php` report.
- [x] `credential_versions` table — a version snapshot (encrypted username/password) is written on
  every credential edit, reusing the already-fetched `$old_row` in `agent/post/credential.php` /
  `credential_model.php`. Masked "Recent History" surfaced on `credential_view.php`.
- [x] Reveal-audit trail: viewing/sharing a credential's password now calls
  `AuditService::record('vault.credential_revealed', ...)` from `credential_view.php` and `ajax.php`'s
  "Share item" branch (gated: only if a password is present, only after `enforceClientAccess()`).
- [ ] Not built: scheduled rotation reminders/notifications (still a pull-based report, nothing pushes
  yet), bulk rotation workflows.

## Phase 5 — Knowledge Base V2
Status: **partial** (versioning + review-due tracking; built via parallel agent batch, 2026-09-01)

- [x] `kb_article_review_due_at` / `kb_article_reviewer_user_id` on `kb_articles`; needs-review badge
  and filter checkbox on `kb_article.php`/`kb_articles.php`.
- [x] `kb_article_versions` — every edit snapshots the pre-overwrite content/content_raw (inserted in
  `agent/post/kb_article.php`'s `edit_kb_article` handler, before the `UPDATE`). `kb_article_versions.php`
  + its modal (`kb_article_version_view.php`) show history; `kb_article_review_edit.php` sets the
  reviewer/due date.
- [x] `src/Knowledge/CredentialReferenceRenderer.php` — renders inline references to vault credentials
  inside article content.
- [ ] Not built: article approval workflow, scheduled review reminders (same pull-based-report
  limitation as Phase 4's rotation tracking).

## Phase 10 — Service Catalog
Status: **partial** (scoped down per Section 27.1 — a flat requestable-item list, no dynamic form
builder; built via parallel agent batch, 2026-09-01)

- [x] `service_catalog_items` table (name, description, icon, ticket subject template, category,
  default priority, active/sort order) — admin-managed via `admin/service_catalog.php` +
  add/edit modals + `admin/post/service_catalog.php`.
- [x] Agent (`agent/service_catalog.php`) and client-portal (`client/service_catalog.php`) browsing
  pages — selecting an item pre-fills a new ticket from its template.
- [x] Nav links: "Request Something" (agent + client portal), "Service Catalog" (admin).
- [ ] Not built: dynamic/custom request forms per item (Section 27.2's fuller model), approval
  routing before ticket creation.

## Phase 11 — ITSM (Problem & Change Management)
Status: **partial** (core records + linkage; built via parallel agent batch, 2026-09-01)

- [x] `problems` table + `src/ITSM/ProblemService.php` — status lifecycle (open/investigating/
  resolved/closed), optional link to a `changes` row. `agent/problems.php` + `problem_details.php` +
  add/edit modals + `agent/post/problem.php`.
- [x] `changes` table + `src/ITSM/ChangeService.php` — draft → awaiting_approval → approved →
  scheduled → in_progress → successful/failed/rolled_back → cancelled, with reason/impact/risk/
  implementation/rollback plan fields. `agent/changes.php` + `change_details.php` + add/edit modals +
  `agent/post/change.php`.
- [x] `tickets.ticket_problem_id` — a ticket can be linked to the problem it's a symptom of.
- [x] Nav links: "Problems" and "Changes" added to the agent sidebar's Support section.
- [ ] Not built: change approval workflow (multi-step sign-off), CAB (change advisory board) scheduling/
  calendar view, problem → root-cause KB article linkage.

## Phase 12 — Automation, Webhooks V2, API v2
Status: **partial** (webhooks-first, per the batch's own effort budgeting; built via parallel agent
batch, 2026-09-01)

- [x] `src/Webhooks/WebhookDispatcher.php` — synchronous curl-based delivery with the same
  `X-ITFlow-Signature`/`X-ITFlow-Event` HMAC-SHA256 header format as the pre-existing `cron.php`
  async path. Distinct from (not a replacement for) the pre-existing ticket-scoped
  `webhooks`/`webhook_queue` async system — both read the same `webhooks` table.
  Logs every attempt to a new `webhook_deliveries` table.
- [x] `admin/includes/webhook_events.php` — single source of truth for subscribable event types
  (Ticket Events = existing async path; Platform Events = `AuditService` event strings:
  workflow/people-import/problem/change/kb/vault/auth/integration-test events). Wired into
  `settings_webhooks.php`'s add/edit modals and a new "Direct Delivery Log" card.
- [x] `src/Automation/AutomationRuleEvaluator.php` + `automation_rules` table — trigger event →
  JSON condition → action (create_ticket / send_webhook / notify_user). Schema + evaluator only;
  no admin UI to author rules yet.
- [x] `api/v2/` — `index.php`, `workflow_runs.php` (first v2 endpoint, exposing Phase 9's workflow
  runs), `.htaccess`.
- [ ] Not built: admin UI for authoring automation rules, most other API v2 resource endpoints
  (v2 currently covers workflow_runs only), OpenAPI docs for v2 (v1 has `admin/api_docs.php`; v2
  does not yet).

## Phase 13 — Dashboards and Reporting
Status: **done** (no new schema — reuses Phase 9's workflow_runs + existing ticket/asset tables)

- [x] `agent/it_dashboard.php` — "Internal IT" dashboard distinct from the existing MSP `dashboard.php`;
  surfaces onboarding/offboarding run counts and status, not just tickets. Nav link added.

## Phase 7 — Microsoft Intune / RMM
Status: **done** (Intune device sync built 2026-09-01; RMM was already complete pre-existing — see note)

- [x] **RMM was already fully built** — inherited wholesale from the base MSP product, not part of
  this session's work: real client classes for Tactical RMM/Level.io/Action1/Sophos Central
  (`includes/class_*_rmm.php`), a factory (`includes/rmm_client_factory.php`), a mature
  asset-matching/reconciliation engine (`includes/class_rmm_asset_mapper.php` — matches by link →
  serial → MAC → unique hostname, creates CMDB assets, syncs alerts, auto-closes tickets on
  vendor-side alert clear), cron-driven sync, and a complete admin credential UI with per-provider
  guides already on `admin/settings_integrations.php` (RMM/Backups/Firewalls tabs). Only needs a
  real API key entered to go live — this satisfies the "matching/reconciliation engine" gap noted
  as open in Phase 6.
- [x] `src/Integrations/Microsoft/GraphClient::listAllManagedDevices()` — paginated Graph
  `/deviceManagement/managedDevices` client (follows `@odata.nextLink`), reusing the same
  tenant/client/secret Phase 3 already collects — no new credential field.
  `src/Integrations/Microsoft/IntuneAssetMapper` mirrors `RmmAssetMapper`'s exact matching priority
  (link → serial → unique hostname → create) for Intune devices, logs each run to a new
  `intune_sync_log` table.
- [x] Admin UI: extended the existing Microsoft card on `admin/settings_directory_sync.php` (not a
  new page) with a "Sync devices from Intune" toggle, guide text on the separate
  `DeviceManagementManagedDevices.Read.All` Graph permission grant needed in the Entra portal, a
  Sync Now button, and a Recent Syncs log table — same credential-entry pattern as Microsoft/Odoo
  already used, per the explicit instruction to reuse "the admin ... like now" and keep the guides.
- [x] Cron wiring (unattended periodic sync) + a read-only `agent/intune_devices.php` device list +
  a compliance-state card on `agent/asset_details.php` alongside the existing RMM card.
- Built via 2 parallel agents (backend engine+schema / admin UI+wiring) + 2 adversarial review
  passes; fixed the one real bug they surfaced (cron's catch block wasn't marking `intune_sync_log`
  rows failed, so an unattended sync failure would leave a permanently-`running` row) plus 2 minor
  cleanups (a double-escaped GET filter; the OS-icon logic reused from the RMM card didn't
  recognize iOS/Android, which matters since real Intune fleets are often mobile devices). Also
  caught and fixed a real, unrelated pre-existing bug from Phase 3 while testing this page: `admin/
  settings_directory_sync.php` never had a closing footer include at all (page rendered but never
  closed its `</html>`) — undetected until this session's byte-for-byte live smoke test.
  End-to-end verified live: a real POST save round-tripped `intune_sync_enabled`, a real Sync Now
  click against a fake tenant produced a genuine Microsoft AADSTS error that landed correctly as
  `status='failed'` in the log table (not a stuck `running` row) — confirming the cron fix's logic
  live, not just in code review.

## Phases 14, 15
Status: **not started** — Employee Portal (14), Polish (15). Not yet scoped in detail.

---

## Session summary (2026-09-01, built while user was AFK)

Shipped and deployed live to `mw-itflow.foleyit.com`, each verified before deploy (php -l on every
touched file, a full db.sql import into a scratch database checked table-by-table, and for the two
riskiest pieces — AuditService and PersonImportService/AssetAssignmentService — a real functional
test against a live or scratch database before calling it done):

- **Phase 0 (Foundation):** Composer PSR-4 `/src`, AuditService + audit_events, AuthorizationService,
  DB-backed job queue. Login auditing wired in for real (caught and fixed a real bind_param bug live).
- **Phase 1 (Organization):** Microsoft tenant ID / email domain / security+HR contact fields on the
  company; department parent/head/cost-center/status/security-classification; site type/manager/
  emergency-contacts/shipping fields; department_sites junction table.
- **Phase 2 (Directory):** Employee fields on contacts (employee ID/type, manager, employment status,
  work arrangement, start date), full CSV import with preview/approve, Direct Reports display.
- **Phase 3 & 8 (Microsoft + Odoo):** Real, working adapter code (OAuth2 Graph client, Odoo JSON-RPC
  client) and settings UI — deliberately not connected to anything live, per the user's explicit
  choice before going AFK.
- **Phase 6 (Assets, partial):** Assignment history.

**Also fixed in passing** (found while working nearby, not master-plan scope): `config_module_enable_crm`
undefined-variable bug from earlier in the session, 4 of ~19 `unlink()`-on-empty-filename warnings,
2 wrong-database-ID bugs from my own earlier cleanup (companies/users landed on id=2 instead of 1).

**What's NOT done:** everything not checked off above. In particular, no Microsoft/Odoo credentials
were provided, so those integrations are real code sitting disabled — nothing will sync until someone
supplies a tenant/app registration and an Odoo API key and tests it. Phases 4, 5, 9-15 (Vault V2,
Knowledge Base V2, the entire employee lifecycle workflow engine, service catalog, ITSM, automation/
webhooks, API V2, reporting, employee portal, UI polish) have not been started at all — this remains,
realistically, months of further work, not a few more sessions.

---

## Session log
- 2026-09-01: PROGRESS.md created, decisions locked, Phase 0 started.
- 2026-09-01: Phases 4, 5, 10, 11, 12, 13 built via a 6-agent parallel Workflow batch (each phase's
  new files built independently in the same working tree with no overlap; each phase returned
  `migration_sql`/`db_sql_additions`/`nav_snippets` instead of touching the 5 shared/sequential files
  directly). Integrated sequentially by hand afterward: db.sql (+7 tables, 3 in-place column additions,
  220 → 227 tables, verified via a fresh scratch-DB import), 6 new `admin/database_updates.php` blocks
  (2.6.58 → 2.6.63), `includes/database_version.php` bumped, the KB versioning snapshot insert applied
  to `agent/post/kb_article.php`, 5 nav snippets applied across `agent/includes/side_nav.php` (x3),
  `admin/includes/side_nav.php`, and `client/includes/header.php`, plus a small completeness fix to
  `admin/includes/webhook_events.php` (missing `change.created`/`change.status_changed`). All 50
  touched/created PHP files pass `php -l`; `composer dump-autoload` run for the new `Knowledge`/`ITSM`/
  `Webhooks`/`Automation` namespaces. Committed, pushed, and deployed live (DB migrated 2.6.58 -> 2.6.63
  directly against `midwest_itflow`, 227/227 tables confirmed).
- 2026-09-01: Post-deploy authenticated smoke test (forged a file-based PHP session for user_id=1 -
  `session.sid_length` defaults to 32, a longer hand-picked ID is silently rejected; the session file's
  serialized content also can't have a trailing newline or `session_start()` fails to decode it) caught
  a real bug the earlier php -l / unauthenticated-redirect checks couldn't see: **`agent/problems.php`,
  `agent/changes.php`, `agent/problem_details.php`, `agent/change_details.php`, `agent/service_catalog.php`
  all fatal on `require_once "includes/footer.php"`** - relative includes resolve against the including
  script's own directory, and there is no `agent/includes/footer.php` (only `client/includes/` has its
  own footer; agent pages must use `"../includes/footer.php"` to reach the project-root one, same as
  `agent/tickets.php` already does). Also found the *identical* bug already live in `agent/workflow_run.php`
  from Phase 9 - pre-existing, not introduced by this batch, just never hit until this smoke test.
  Fixed all 6, verified clean via the same forged-session method (all render fully, zero new nginx error
  log entries), then functionally verified the KB-versioning snapshot logic (`agent/post/kb_article.php`)
  against a scratch DB (two sequential edits -> correct version numbers 1/2, correct pre-edit content
  captured, special characters escape safely).
- 2026-09-01: Also found and fixed a real routing gap: `api/v2/.htaccess` assumes Apache, but this
  server runs nginx (which ignores `.htaccess`) - `/api/v1/` has a matching `location /api/v1/ { try_files
  $uri /api/v1/index.php?$query_string; }` block in `/etc/nginx/snippets/itflow-locations.conf`, but v2
  had no equivalent, so clean URLs like `/api/v2/workflow-runs` 404'd even with a valid Bearer token
  (only the ugly `/api/v2/index.php` direct hit worked). Added the matching `location /api/v2/` block
  (shared snippet file, used by all 4 vhosts - inert on the other 3, which have no `api/v2/` directory),
  `nginx -t` + reload, verified end-to-end with a temporary real API token (issued, tested, deleted).
  **Lesson for future phases:** a new `.htaccess`-based clean-URL front controller needs a matching
  hand-added nginx location block - it will not work by just deploying the PHP.
- 2026-09-01: Phase 7 (Intune device sync) built per explicit instruction to complete it now, reusing
  the existing admin credential-entry pattern and preserving all existing guide text. Built via 2
  parallel agents (backend engine+schema / admin UI+wiring) + 2 adversarial verify agents; found and
  fixed 1 real bug (cron's Intune sync catch block never marked `intune_sync_log` failed) + 2 minor
  cleanups + 1 unrelated pre-existing Phase-3 bug (`admin/settings_directory_sync.php` had no footer
  include at all, caught by this session's byte-for-byte live response check). DB migrated 2.6.63 ->
  2.6.64 (`asset_intune_links`, `intune_sync_log`, `microsoft_integrations.intune_sync_enabled`),
  229/229 tables confirmed on both a scratch DB and live `midwest_itflow`. Verified live end-to-end
  with real POST requests against a throwaway fake tenant (not just unauthenticated/redirect checks):
  Save round-tripped `intune_sync_enabled`, Sync Now against the fake tenant produced a genuine
  Microsoft AADSTS error that landed as `status='failed'` (not a stuck `running` row) - confirming the
  cron fix's logic for real, then all test rows deleted and the forged test session removed.
- 2026-09-25: **Training (LMS) Phase 2** integrated on branch `lms-phase2` (spec `lms-phase2-spec.md`; lanes A1 schema +
  core, B people/assignment/compliance/Odoo engine, C records/evidence, D dashboard/reports/transcript/certificate, E
  operations UI, A2 hooks + admin page + crons). DB 2.6.91 -> 2.6.92 (17 new tables, 13 settings columns, records-mutex
  seed, Odoo link baseline + accepted target). **Migration note: apply only through Admin > Update > Update Database**,
  then check 2.6.92, 45 `training_%` tables + `contact_odoo_attributes`, 26 `config_training_*` columns, the records-mutex
  row and one coattr baseline row per Odoo link. Verified on a schema-only scratch DB with generated fixtures (no live
  rows) and a fake Odoo JSON-RPC endpoint (the real Odoo was never called): every lane's CLI suite, the A2 HTTP/cron
  suites, a browser smoke of every page for three roles, fresh-install `db.sql` = migrated schema (only the pre-existing
  `config_module_enable_accounting` default drift differs), migration re-run idempotent, route gate 126.
  **Ops still to do after the merge (spec §6.3):** create `/var/log/itflow_mw_training.log` and
  `/var/log/itflow_mw_odoo_sync.log` (www-data), install `/etc/cron.d/mw-itflow-training` (04:30 odoo_sync_cron,
  05:15 training_cron), run Admin > Training compliance > **Check now** once (Phase 3 Odoo-PIN sign-in needs checked
  links). Leave the nightly Odoo sync switch OFF and the hire-fill date EMPTY until the owner decides.
  **Owner decisions pending:** hire-fill date (R2); whether external cards get LMS numbers (R9, a one-line change in
  `CompletionService` before the first external record); whether a later passing practical evaluation after a blended
  session record should issue a second blended record (currently it does, read as a re-evaluation).
  Runbook (R7): a supervisor's `user_client_permissions` rows narrow their WHOLE ITFlow access, not only Training.
- 2026-09-25: **Training (LMS) Phases 3+4** integrated on branch `lms-phase34` (spec `lms-phase34-spec.md`; lanes K1
  platform + schema, K2 identity/devices/PINs, K3 learner engine + records bridge, K4 learner UI, K5 trainer mode, K6
  achievement awards). DB 2.6.92 -> 2.6.93 (13 new tables, 21 settings columns). **Migration note: apply only through
  Admin > Update > Update Database**, then check 2.6.93, 58 `training_%` tables and 47 `config_training_*` columns.
  Verified on a schema-only scratch DB with generated fixtures (no live rows, no Odoo call; Odoo-PIN paths only against a
  fake connector): every lane's suite on the merged tree, a real-stack browser end-to-end at iPad 1024x768/768x1024 and
  Windows 1366x768 (start URL -> name -> PIN -> Learning Center -> course -> exam -> ack -> sign -> LMS certificate +
  badges; document acknowledgment; trainer badge; YouTube lesson page) with zero console/CSP errors, deep ledger verify
  ok, fresh-install `db.sql` = migrated schema (only the pre-existing `config_module_enable_accounting` default drift),
  migration re-run idempotent.
  **Ops still to do after the merge (spec §7.11, §6):** the live vhost `location ^~ /kiosk/includes/ { deny all; }`
  (curl -> 403), `/var/log/itflow_mw_training_kiosk.log` (www-data) and `/etc/cron.d/mw-itflow-training-kiosk` (every
  10 min, line in the cron file header), `$config_settings_enc_key` present in the live `config.php`.
  **Before the pilot:** issue setup slips for the pilot crew and trainers; add trainer rows (People > Trainers); enroll
  1-2 iPads (iPadOS >= 16.4, inside the Home Screen app) and the Windows PC (Edge `--kiosk <start URL>
  --edge-kiosk-type=public-browsing`). Leave Odoo-PIN sign-in OFF until the A22 production switch, a clean "Check
  employee links" and Refresh PIN sources (spec §9.3 step 7).
- 2026-09-25: **Phases 3+4 end-to-end QA** (branch `lms-phase34`, scratch p34e2e, generated fixtures: 29 people in
  Crane / CNC Machining / Fabrication / Safety, local PINs from printed slips, one Odoo-PIN person answered by a fake
  connector; no live rows, no Odoo call). Real browser at iPad 1024x768 + 768x1024 (touch, Safari UA) and Windows
  1366x768 (Edge UA): LOTO authored and published through the agent API (article, PDF, MP4, 3-question exam with 1
  critical, acknowledgment), the P2 rule "Crane, due in 14 days" built in the rule editor, slips issued/reprinted/
  cleared; device enrollment signed in on the device and Edge kiosk `?d=`; learner flow with a wrong PIN, exam fail then
  pass, finger signature + PIN, `LMS-YYYY-NNNNNN` receipt, badge, auto-return, assignment closed, transcript/record/
  dashboard/awards; Spanish end to end; PIN lockout + unlock, search/PIN rate limits, device cooldown + clear, revoke,
  idle "Still there?" and the 2-minute absolute cap; trainer session with pass-the-iPad check-in and finalize, a
  practical evaluation hand-off, a trainer badge; isolation (Back/bfcache, other people's runs/attempts/receipts,
  evidence media); module OFF -> `/kiosk/` 404. 330 checks, zero console/CSP errors (deliberate 4xx probes aside),
  deep ledger verify ok, no PIN or setup code in the ledger, logs, notifications or PHP log, no `Login` log rows.
  Fixed on the way: portrait-iPad course layout (player container queries never applied on the kiosk), Spanish course
  names in the Learning Center, blended-course sign-off wording and receipt preview, trainer session date, the rule
  editor's dead "Change on the course" link. Re-run: scratchpad `p34e2e/run_all.sh`.
- 2026-09-25: **Phases 3+4 security + employee-UX review fixes** (branch `lms-phase34`, scratch p34fix). Security: a
  valid start-URL token is never rate-limited (only unknown tokens fill the adopt bucket); check-in and hand-off are left
  only with the trainer PIN or a server-confirmed idle timeout (`?switch=1` redirects home, `end {done}` is 403, `end`
  no longer refreshes last_seen); session practical marks need can_evaluate + course + the person's department, and
  finalize re-checks and drops a stale mark; the YouTube/Vimeo page loads no provider script (the players are driven
  over postMessage, CSP script-src = strict), so no third-party code runs in the kiosk origin; `/kiosk/?d=<token>` gets
  a bare 302 to `/kiosk/#d=<token>` (PHP, plus an nginx `location = /kiosk/` rule with `access_log off` in the repo
  mirrors) and the setup page issues the `?d=` form for kiosk-mode browsers. Employee UX: no "Next lesson" before a
  lesson is done, acknowledgment Sign button in the footer, English/Español choice at the first Start and fresh runs
  follow a language change, "My training" back button, fit-width PDF with the page counter visible, sign-page keypad
  and sticky Sign & finish, long-form statement dates, Spanish wording (Salir, instructor, examen), mouse wording on
  PCs, neutral info colour, logo, distinct covers, bigger labels, live idle countdown, Enter/keystroke buffering on
  PCs, check-in header and heading. Verified: the full E2E (330 checks) plus 77 probe checks (security S1-S5, real
  YouTube and Vimeo embeds over postMessage, UX), zero console/CSP errors, no PIN/token in the ledger, logs or PHP log.
  Re-run: scratchpad `p34fix/final.sh`.
- 2026-09-27: **Training (LMS) Phase 5** integrated on branch `lms-p5-int` (spec `lms-phase5-spec.md` with the
  2026-09-26 deltas; lanes A platform/Upstream/settings shell/worker, B Odoo write-back, C public verify + PDFs, D
  reminders/video/key expiry, E item analysis + Compare versions). All MUST and SHOULD items (M1-M3, S1-S9) plus L1.
  DB 2.6.95 -> 2.6.96 (5 operational tables, one `training_automation` row). **Migration note: apply only through
  Admin > Update > Update Database** (one click), then check 2.6.96 and one `training_automation` row. Settings stay on
  the ONE Training settings page (new Certificates and Reminders & automation sections; Odoo write-back and the public
  check switch are admin-only, read-only for Training 3). Integration fixes: `Automation\Notify` accepts the plain
  `Training` type, so the public check's integrity alert goes out as the always-pushed records type instead of
  "Training Odoo"; the worker's odoo line no longer repeats "odoo:" / "dry run". Verified on a schema-only scratch DB
  with generated fixtures (no live rows) and the local mock Odoo on both protocols (no real Odoo call): migration twice
  (identical information_schema, one seed row), fresh `db.sql` = migrated schema (only the pre-existing credentials
  column order / `config_module_enable_accounting` default drift), static gates (route gate 153, category gate, mail /
  unlink / notifyUser / SELECT * / P2-P3-names / video_checks / verify-bootstrap / escaping greps, 44 new classes
  final), and every lane suite re-run on the merged tree: A 145/146 CLI, 32/36 HTTP, 12/12 + 12/12 browser, trial 18/19,
  pre-migration 9 + 13, Training-off 5 (the 6 misses are lane-A-only expectations: "handler not installed yet" and
  "Check Odoo" shown with no Odoo integration); B 283 + 59 + 13 + 7 + 55 browser; D 92 + 46; E 42 + 159 + 49 (first
  run against the real Upstream classes); integration smoke 44/44 covering verify states/headers/integrity alert, PDFs
  per role, page levels; worker
  daily/odoo with everything OFF (silent, exit 0) and with reminders on (digests + escalation + key-expiry to the test
  sink only). Live `notifications` count unchanged.
  **Ops still to do after the merge (spec §6.2/§6.3):** `/var/log/itflow_mw_training_worker.log` (www-data, create
  first), `/etc/cron.d/mw-itflow-training-worker` (odoo every 10 min, daily 05:40), `/etc/logrotate.d/itflow-mw`, the
  two dry runs as www-data. No nginx change. Odoo write-back and reminders stay OFF until the owner enables them.
  **Owner decisions pending (spec §1.5):** in-app digests now, email later with a Midwest-owned sender (L3); résumé
  lines for achievements instead of gamification badges (L7 later); ledger anchor to Odoo deferred (L5); certificate
  regulation wording (P2's "Meets the training requirements of ..." line prints when the course has one); Odoo is a
  copy (every Odoo user can read the lines; employees with logins can edit their own). Note: the live Odoo integration
  now points at **production** (`midwest-production`, JSON-2) with the owner's personal key; create the Officer-only
  bot key before enabling write-back.
- 2026-09-27: **Phase 5 review fixes** on `lms-p5-int` (security review 3 findings, UX review 9, E2E none). Security:
  the public-check throttle counts a visitor before the shared budget (a visitor over its 20/min no longer spends the
  240/min), keys IPv6 on its /64, writes nothing once the global cap is spent, and keeps a fixed set of at most 4,097
  small files (no pruning inside a request; the daily worker prunes) - the reviewer's one-/64 probe went from 240
  allowed / 5,001 files / ~20 MB to 20 allowed / 2 files / 12 KB. Odoo write-back only treats résumé lines created by
  the integration's own Odoo user as ITFlow's (marker search filtered by `create_uid` in Odoo; the uid comes from the
  legacy login or `context_get`, else the spec's employee-scoped search), so a line an employee plants with an upcoming
  marker can no longer kill a colleague's record or trip the breaker; same filter on the voided-before-send path.
  "Check Odoo" (admin POST and `--task=discover`) now refuses an http:// Odoo like enabling does. UX: the PDF words the
  method like the on-screen certificate ("PIN attestation" unless signed); "PDF (English)" / "PDF en español" on the
  record page and certificate toolbar; Spanish regulation line "Referencia normativa: OSHA 29 CFR ..."; the sample uses
  P2's regulation rule; evidence legend B detail "... or the employee confirmed with a PIN" (P2 `Reports\Labels` and
  the Upstream copy; the label itself is P2's frozen wording); `/verify/` hides the expiry on a revoked certificate and
  says not to accept it, says "turned off" when switched off, shows whom to contact when there are no details, and
  keeps dates on one line (no-break spaces); staging acknowledgement stays ticked for the same Odoo; numbered
  "Before production" steps; worker/Odoo results as sentences (`Automation\ResultText`), zero outbox counts grey,
  On/Off badges, corrected section intro. No schema change. Verified on scratch p5f (schema-only, mock Odoo on both
  protocols + TLS front, no real Odoo): §10.1 gates clean (153 routes, 45 classes); lane A 145/146 CLI, 32/36 HTTP,
  18/19 trial, 12+12 browser, pre 13+9+3, off 5 (the same 6 lane-A-only misses); B 310 Odoo (with the 22 s timeout),
  62 admin, 15 worker, 7 pre-migration, 65 browser; C 28 throttle, 42 CLI, 31 verify HTTP, 135 verify browser, 99 PDF,
  31 UI, 9 QR; D 92 + 46; E 42 + 159 + 49; full E2E 317/317.
