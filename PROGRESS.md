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
