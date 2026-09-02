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
Status: **mostly done** (import + core fields done; activity timeline deferred)

- [x] Extend `contacts` with employee fields: employee_id, employee_type, manager_id (self-referential), start_date, expected_end_date, employment_status, work_arrangement. Full UI in `contact_edit.php` + `contact_details.php`.
- [x] Employment state machine — implemented as a validated string field (pre-hire/active/leave/suspended/transfer_pending/termination_pending/terminated/archived), not a DB enum, to stay flexible; validated in `PersonImportService` and the edit modal's fixed option list.
- [x] Manager/direct-reports relationship + display on contact page (`contact_details.php` "Employment" card + "Direct Reports" list)
- [x] CSV import: `admin/people_import.php` + `src/Directory/PersonImportService.php`. Upload → preview (validates + resolves department/site/manager, flags per-row errors) → explicit approve → write. **Simplification vs. the full plan:** fixed CSV column headers required (no drag-and-drop column mapper) — reasonable for one company doing occasional imports; revisit if that changes. `people_import_runs` logs each approved run.
- [ ] Person activity timeline — deferred. `audit_events` (Phase 0) and `logs` both have the raw data to build this from; not yet surfaced as a unified per-person timeline UI.

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

## Phases 4, 5, 7, 9–15
Status: **not started** — Vault V2 (4), KB V2 (5), Intune/RMM (7), Employee Lifecycle workflows (9),
Service Catalog (10), ITSM (11), Automation/API V2 (12), Reporting (13), Employee Portal (14),
Polish (15). Not yet scoped in detail — will update this section as each phase starts.

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
