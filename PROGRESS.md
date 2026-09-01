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
Status: **not started**

- [ ] Extend `contacts` with employee fields: employee_id, employee_type, manager_id (self-referential), start_date, expected_end_date, employment_status, worker_classification, remote/hybrid/on-site
- [ ] Employment state machine (pre-hire/active/leave/suspended/transfer pending/termination pending/terminated/archived)
- [ ] Manager/direct-reports relationship + display on contact page
- [ ] CSV import (template, upload, column mapping, validation, preview, dedup, approve, write, results) — new admin page
- [ ] Person activity timeline

## Phases 3–15
Status: **not started** — Microsoft/Entra (3), Vault V2 (4), KB V2 (5), Assets/CMDB (6), Intune/RMM (7),
Odoo (8), Employee Lifecycle workflows (9), Service Catalog (10), ITSM (11), Automation/API V2 (12),
Reporting (13), Employee Portal (14), Polish (15). Not yet scoped in detail — will update this section
as each phase starts.

---

## Session log
- 2026-09-01: PROGRESS.md created, decisions locked, Phase 0 started.
