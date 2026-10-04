# Architecture

This document explains how the RivetIT codebase is structured, for a developer who has just cloned the repository. It covers the portal/role structure, authentication and session handling, the permission model, the core data model, the module toggle system, database migrations, the REST API, and third-party integrations — followed by a summary of how RivetIT differs from upstream ITFlow (the MSP product it started from).

The codebase is plain PHP (no framework) using `mysqli` and hand-written SQL throughout, backed by a single MySQL database. There is no build step for the PHP application itself.

**A note on naming**: RivetIT says "Department" instead of "Client" everywhere a human sees it (navigation, page titles, labels, emails, PDFs), but the underlying PHP variables, function names, database tables/columns, and the entire REST API were deliberately left using `client` (`clients` table, `client_id`, `module_client`, etc.) to avoid breaking the API contract and to minimize code churn. This document describes the code as it actually reads — in terms of `client`/`clients` — and internal-department semantics apply wherever you see that word. See "How RivetIT differs from upstream ITFlow" at the end for the full list of changes.

**Product name vs. internal names**: the product is RivetIT, and PHP code shows it through the constants in `includes/branding.php` (`APP_NAME`, `APP_REPO_URL`, …), never a literal; shell scripts and static files that cannot load PHP say RivetIT literally. Internal identifiers from the ITFlow days are kept on purpose because installs, integrations and the Android app depend on them: the PHP namespace `ITFlow\` (composer PSR-4 → `src/`), `itflow_*` functions, `css/itflow*.css` files, database/table/column and config names, storage keys, webhook headers, Odoo markers and backup file names. [`REBRANDING.md`](../REBRANDING.md) lists them with the reason for each. New code keeps using the existing namespace and names.

## 1. Overview: four portals, one codebase

The application is a single PHP tree split into four URL-path-based "portals," sharing one MySQL database:

| Directory | Audience | Bootstrap include | Auth gate |
|---|---|---|---|
| `agent/` | IT staff — day-to-day working UI: tickets, departments, assets, credentials, billing (if enabled), RMM, etc. | `agent/includes/inc_all.php` | Logged-in `users` row, `user_type = 1` |
| `admin/` | Staff who also hold an admin role — org settings, user/role management, integrations, DB migrations | `admin/includes/inc_all_admin.php` | Same login as agent, plus `$session_is_admin` true |
| `client/` | End users (department members) — self-service portal: tickets, assets/docs assigned to them, invoices if billing is on | `client/includes/inc_all.php` | Separate `client_logged_in` session, `users` row `user_type = 2` joined to `contacts` |
| `guest/` | Anyone with an emailed link — no login | `guest/includes/inc_all_guest.php` | Per-record random token in the URL, no session identity |

**Admin is not a separate account type.** There is no `admin/login.php`. It's an agent account (`user_type = 1`) whose assigned role has `role_is_admin = 1`; `admin/includes/inc_all_admin.php` runs the exact same login chain as `agent/` and then adds a hard check on `$session_is_admin`.

**Naming trap to be aware of**: `agent/includes/inc_all_client.php` is *not* the client-portal bootstrap. It's the include used by agent-side pages that view a single client/department record (e.g. `agent/client_overview.php`), and it enforces agent-side permissions (`enforceUserPermission('module_client')`, `enforceClientAccess()`). The actual client/department-portal login gate lives in `client/includes/check_login.php`.

Root `index.php` routes a request based on which session flag is set: `$_SESSION['logged']` → `agent/`, `$_SESSION['client_logged_in']` → `client/`, otherwise → `login.php`. Agent and admin share the `logged` flag; the client portal uses a distinct flag, and the two are mutually exclusive within one login.

The Training module (LMS, `src/Training/`) adds two more public entry points outside these portals: `kiosk/`, the learner app for shared iPads and PCs (a device enrolled by an admin gets a start URL; employees sign in with their name and a training PIN, and no agent session is ever kept on the device, see [`training-kiosk-setup.md`](training-kiosk-setup.md)), and `verify/`, the public certificate check the QR code on every certificate points to (no login, no cookies, rate-limited).

## 2. Authentication and sessions

### 2.1 Unified login endpoint

A single root-level `login.php` handles both agent and client/department-member logins against one `users` table, keyed by email + password, as a three-step state machine:

1. **Credentials** — looks up `users` by email (LEFT JOIN `user_settings`, `contacts`, `clients`) and `password_verify()`s against every `user_type` match for that email (at most one `user_type=1` row and one `user_type=2` row can share an email).
2. **Role choice** (dual-role accounts only) — if the same email matches both an agent row and a client/department row, the user picks which to sign into; tracked via a short-lived `$_SESSION['pending_dual_login']` (120s TTL), without re-sending the password.
3. **MFA** (agent path only, if TOTP is enrolled) — a `$_SESSION['pending_mfa_login']` pending state.

### 2.2 Agent/admin authentication factors

- **Password**: bcrypt via `password_verify()` against `users.user_password`.
- **TOTP 2FA**: verified with a bundled TOTP library; the secret lives in `users.user_token`. MFA is skipped only if no secret is enrolled, or a valid `rememberme` bypass cookie is presented. `user_settings.user_config_force_mfa` can force enrollment (redirects to `agent/user/mfa_enforcement.php`).
- **WebAuthn / passkeys**: agent-only, handled outside `login.php` (`passkey_auth_begin.php` / `passkey_auth_complete.php`), hard-restricted to `user_type = 1`. A verified passkey is treated as a sufficient factor on its own (bypasses TOTP) and also restores an encrypted "credential vault" key via a cookie mechanism.
- **Optional shared login key**: `settings.config_login_key_required`/`config_login_key_secret` — an install-wide extra secret checked via `?key=`, independent of the user's own credentials.
- **Remember-me**: a SHA-256-hashed cookie (`remember_tokens`, agent-only) that both bypasses 2FA at login and silently re-establishes a full session on later visits (single-use, rotated on each use).

On success, the agent path sets:

```php
$_SESSION['user_id']    = $user_id;
$_SESSION['csrf_token'] = randomString(32);
$_SESSION['logged']     = true;
session_regenerate_id(true);
```

That is the entire session contract. Everything else about the user — name, role, admin flag, permissions — is re-derived on every request, not cached in the session.

### 2.3 Per-request session loading (`includes/check_login.php` chain)

Every agent/admin page includes `includes/check_login.php`, which chains:

1. `session_init.php` — starts the session with `httponly`/`secure` cookie flags and a configurable lifetime (`settings.config_login_session_lifetime`, stored in minutes, effective lifetime clamped to 30–90 days; the stored column defaults of 480 min and 3 days are below that floor and so are raised to 30 days).
2. `auth_check.php` — the actual gate. If `$_SESSION['logged']` isn't set, attempts to auto-restore from a valid `rememberme` cookie; otherwise redirects to `login.php`.
3. `load_user_session.php` — the important one for authorization. Joins `users` → `user_settings` → `user_roles`, then sets request-scoped globals:
   - `$session_user_type`, re-verified `=== 1` (destroys the session otherwise) — this is what actually keeps client-type users out of agent pages.
   - `user_status`/archived checks — disabling or archiving a user kills their session on the very next request.
   - `$session_user_role` (= `users.user_role_id`) and `$session_user_role_display`.
   - **`$session_is_admin`** — derived from `user_roles.role_is_admin`; the single source of truth for admin-ness.
   - Per-client/department scoping globals (`$client_access_array`, `$client_access_string`, `$access_permission_query`) — see §3.3.
4. `load_company_settings.php` / `load_global_settings.php` — load the singleton `companies`/`settings` rows into globals shared by all four portals (module toggles, numbering, SMTP config, etc.).

### 2.4 Client (department-member) portal login

Client-portal identity reuses the same `users` table (`user_type = 2`), 1:1-linked to a `contacts` row via `contacts.contact_user_id`. Granting portal access is an explicit agent action: `agent/post/contact.php` inserts both the `users` row and the `contacts` row (or attaches a new `users` row to an existing contact) when an agent supplies name/email/auth method for a contact — a contact with no linked `users` row simply has no portal login.

Login goes through the same `login.php` STEP 1 password check. Once a `user_type = 2` match is selected:

- `settings.config_client_portal_enable` must be on, or login is refused outright.
- `users.user_auth_method` must be `local` for this password branch; SSO users go through `client/login_microsoft.php` instead (see Integrations).

On success:

```php
$_SESSION['client_logged_in'] = true;
$_SESSION['client_id']        = $client_id;
$_SESSION['user_id']          = $user_id;
$_SESSION['user_type']        = 2;
$_SESSION['contact_id']       = $contact_id;
$_SESSION['logged']           = true;   // set for any shared session checks
session_regenerate_id(true);
```

Every client-portal page then runs `client/includes/check_login.php`, which independently re-verifies `user_type === 2`, `user_status === 1`, and non-archived from the `users` table (the same "kill session on next request if disabled" pattern as the agent side, duplicated rather than shared), then loads company branding and the contact's own row. There is no TOTP/WebAuthn option for client-portal accounts.

### 2.5 Guest portal

`guest/includes/inc_all_guest.php` has no login/session-identity include at all. It sets its own security headers (CSP, `X-Frame-Options: DENY`, etc.) since it's the unauthenticated, link-shared surface, and individual pages authorize themselves by matching a random per-record token in the URL (`*_url_key` / `item_key` columns) rather than any session state. This is how quote approval, invoice payment, and document signing links work when emailed to someone with no account.

## 3. Permission model

There are effectively three layered systems, plus a legacy one that is mostly dead code. They answer different questions and it's easy to conflate them:

### 3.1 Module + role-based permissions (the live authorization system)

Schema: `modules` (a small fixed catalog — `module_client`, `module_support`, `module_credential`, `module_sales`, `module_financial`, `module_reporting`, `module_kb`, and others referenced in code), `user_roles` (`role_id`, `role_name`, `role_is_admin`), `user_role_permissions` (many-to-many: role × module → access level), and `users.user_role_id` tying a user to one role.

Two functions in `functions.php` drive everything:

- `lookupUserPermission($module)` — returns the caller's access level for a module (`1 = read`, `2 = write`, `3 = full`), or `false`. Admins (`$session_is_admin`) bypass entirely and always get full access.
- `enforceUserPermission($module, $check_access_level = 1)` — looks up the level and, if insufficient, **hard `exit()`s** the request with an access-denied message. Called at the top of write handlers (e.g. `agent/post/contact.php`) and throughout nav/page code to conditionally show or hide UI.

Roles are managed at `admin/roles.php` (admin-only).

**Pop-ups** (`agent/modals/*`, `/modals/*`, loaded by `js/ajax_modal.js`) all start with `includes/modal_header.php`, which checks the requested file against the folder/file → module map in `includes/modal_permissions.php` before the pop-up's own code runs (`admin/modals/*` keep their separate admin-only gate there). A folder entry covers every file in it; a file entry overrides it. Entries use view level, except pure write forms whose buttons the pages already hide from view-only roles. Asset pop-ups accept `module_assets` or `module_support`; Finance and knowledge-base pop-ups keep an `it_agent` fallback (Departments or Tickets) because they were open to every IT agent before the map. A denial is HTTP 403 with `{"ok":false,"error":"..."}`, which the pop-up loader shows as a message. **A new pop-up folder needs a map entry**; without one it falls back to "not a module-only login" and is logged to the PHP error log. Pages hide a button whose pop-up the map would refuse with `itflow_modal_allowed('folder/file.php')` (loaded by `functions.php`).

**Module-only logins** (`includes/module_access.php`): a non-admin role with none of Departments, Tickets/assets/docs and Assets (`module_assets`, DB 2.6.95) is "limited" (`itflow_is_limited_user()`). `includes/check_login.php` keeps it to an allow-list (its modules' pages, its account, notifications) and answers everything else with `itflow_render_denied()`: a 403 page in the app shell, or `{"ok":false,"error":...}` JSON for pop-ups and ajax. Asset pages accept `module_assets` or `module_support` (`itflow_can_assets()`, `enforceAssetPermission()`).

### 3.2 Module *enable* toggles — a separate axis

Whether a module *exists at all* for this install is a different question from who can use it. That's controlled by boolean columns on the singleton `settings` row (§4). Nav and page code typically checks **both** axes together, e.g. a knowledge-base link only renders if the KB module is enabled *and* the current user's role has at least read permission on `module_kb`.

### 3.3 Per-client/department scoping (`user_client_permissions`)

Schema: `user_client_permissions(user_id, client_id)` — a simple allow-list.

Two independent consumers:

1. **Read-side filtering**: `includes/load_user_session.php` builds `$client_access_array`/`$client_access_string` and a ready-to-splice `$access_permission_query = "AND clients.client_id IN (...)"`, used across roughly 90 files (client/department list pages, tickets, most reports and modals) — but only when the string is non-empty and the user isn't admin.
2. **Write-side guard**: `enforceClientAccess($client_id)` in `functions.php` — admins always pass; a non-admin user with **zero** rows in `user_client_permissions` is treated as unrestricted (can see/touch every client/department); a user with **any** rows is switched into a strict allow-list for just those. This "allow-list activates only once a row exists" nuance is easy to misread as "empty means deny" — it means the opposite.

### 3.4 Client-portal-side visibility (simpler, not module/role based)

Client (department-member) accounts have no roles or modules. Visibility inside `client/` is driven entirely by three booleans on the user's own `contacts` row, loaded at login: `contact_primary`, `contact_technical`, `contact_billing`. Billing/financial pages require primary-or-billing; technical/asset/ticket pages require primary-or-technical; the primary contact implicitly gets both. These combine with the same company-wide module-enable toggles (e.g. billing pages also require accounting to be enabled).

### 3.5 Legacy dead-ish code

`functions.php` still defines `validateAdminRole()`/`validateTechRole()`/`validateAccountantRole()`, explicitly commented as legacy, comparing a flat role integer (Admin=3/Tech=2/Accountant=1) that predates the modules/roles system above. Only a couple of call sites remain in the whole tree. Treat this as legacy being phased out, not as the real permission model.

## 4. Data model

The database is single-tenant per install (one `companies` row holding the org's own profile). `clients` is the hub nearly everything else hangs off of. The dominant relational convention is **naming, not DB-enforced foreign keys**: child tables carry an `int` column named `<entity>_client_id`, `<entity>_ticket_id`, etc., and the application joins on it in SQL — most core tables (`clients`, `contacts`, `tickets`, `ticket_replies`, `assets`, `credentials`, `invoices`, `quotes`, `projects`, `kb_articles`, …) declare **no** `CONSTRAINT` clauses. `contracts` is the one core-entity exception with a real FK to `clients`. Small many-to-many/join and tag tables (`asset_credentials`, `contact_assets`, `client_tags`, `calendar_event_attendees`, etc.) *do* declare real foreign keys with `ON DELETE CASCADE`.

Core entities, in prose form:

- **`clients`** — root entity: name, type, billing rate, net terms, lifecycle timestamps. Everything else points at it via `*_client_id`.
- **`contacts`** — belongs to a client; optionally linked to a `location`, a `vendor`, and/or a portal `users` row (`contact_user_id`); carries the `primary`/`billing`/`technical` role flags used by client-portal visibility.
- **`locations`** — a client's site/address, with an optional site contact.
- **`contracts`** — SLA terms per client: response/resolution time targets per priority tier, included support hours, signature and status fields. The one core table with a real FK.
- **`tickets`** — the join point for nearly everything: client, contact, location, asset, contract, and optionally a linked quote/invoice/project/recurring-ticket/vendor. Scheduling and appointment fields (`ticket_schedule`, `ticket_onsite`, etc.) live directly on the ticket row — there is no separate appointments table. SLA due timestamps are computed from the linked contract.
- **`ticket_replies`** — messages/notes on a ticket, including logged time worked.
- **`ticket_charges`** — billable line items on a ticket (product, labor type, quantity, price, tax); swept into invoices when billing is enabled. Distinct from the general accounting ledger.
- **`ticket_worksheets`** / **`ticket_worksheet_responses`** and a separate **`ticket_outtake_forms`** table both provide signature/sign-off capture on ticket completion, with overlapping purpose — the codebase has not been fully traced to confirm which path (or both) is the live one in current UI flows.
- **`assets`** — hardware/software inventory items belonging to a client, with optional location/contact/vendor links and RMM/warranty metadata.
- **`credentials`** — secrets belonging to a client, optionally scoped to a contact/asset/vendor/software/folder; the password column is encrypted at rest, with an optional TOTP secret field.
- **`invoices`**/**`invoice_items`**, **`quotes`** (parallel shape), **`recurring_invoices`** (cron-generated), **`payments`**, and **`expenses`** — the accounting-ledger side, distinct from `ticket_charges`.
- **`kb_articles`**/**`kb_categories`** — a `client_id = 0` article is company-wide; non-zero scopes it to one client. A visibility flag gates whether client-portal users can see it. Full-text indexed for search.
- **`projects`** — belongs to a client, has a manager (a user) and a per-install numbering sequence; tickets can optionally belong to a project.

Other tables present but not detailed here: `recurring_tickets`, `ticket_automation_rules`/`_runs`, `ticket_watchers`, per-entity audit-trail tables (`ticket_history`, `asset_history`, `credential_history`, `domain_history`, `certificate_history`), `networks`/`racks`/`rack_units`, `domains`/`certificates`, `vendors`, `software`/`software_keys`, and the RMM/UniFi integration tables described in §6.

## 5. Module / feature-toggle system

All install-wide feature flags are boolean columns on the singleton `settings` table, loaded once per request by `includes/load_global_settings.php` into globals like `$config_module_enable_kb`. Confirmed toggles:

| Setting | Gates |
|---|---|
| `config_module_enable_itdoc` | IT documentation (assets/credentials/etc.) navigation |
| `config_module_enable_ticketing` | Ticketing throughout the app |
| `config_module_enable_accounting` | Full invoicing/quotes/recurring-invoices/expenses UI (not offered in RivetIT; see §9) |
| `config_module_enable_ticket_charges` | Billable time/charges on tickets, independently of full accounting (not offered; see §9) |
| `config_module_enable_kb` | Knowledge Base, agent and client sides |
| `config_module_enable_live_chat` | Real-time chat panel on ticket view |
| `config_module_enable_payroll` | Payroll (gross-pay only, no tax withholding), admin-gated (not offered; see §9) |
| `config_module_enable_crm` | CRM: pipeline, opportunities, campaigns, segments (not offered; see §9) |
| `config_module_enable_training` | Training (LMS): courses, assignments, records, kiosk, certificates (Settings > Modules, shown once the Training schema is installed) |
| `config_module_enable_intune` | Intune device sync and the Endpoints > Intune page (toggled from Settings > Integrations) |
| `config_module_enable_rmm` | RMM integration UI (toggled from Settings > Integrations rather than the general Modules page) |
| `config_module_enable_unifi` | UniFi network integration |
| `config_client_portal_enable` | Whether the client/department portal is reachable at all |

Nav and page code generally ANDs this axis with the role-permission axis from §3.1 — a feature must be turned on for the install **and** the current user's role must have at least read access to the corresponding permission module. There is no toggle for Projects; it is an always-on core feature gated only by role permission.

## 6. Database migrations

`includes/database_version.php` defines `LATEST_DATABASE_VERSION`. Each install's current version is stored in `settings.config_current_database_version`. `admin/database_updates.php` is a flat sequence of version-gated blocks:

```php
if (CURRENT_DATABASE_VERSION == '2.6.48') {
    // ALTER/CREATE TABLE statements for this one step
    mysqli_query($mysqli, "UPDATE `settings` SET `config_current_database_version` = '2.6.49'");
}
```

Because `CURRENT_DATABASE_VERSION` is a PHP constant fixed once per request and the blocks are plain `if` (not a loop), **exactly one version step advances per invocation**. An admin upgrading across several versions needs to click "Update Database" (`admin/update.php`) repeatedly, or use the CLI equivalent (`scripts/update_cli.php --update_db`), which has the same caveat. Migration blocks increasingly use `IF NOT EXISTS` guards for idempotency.

`db.sql` is the fresh-install schema dump and is used by the installer directly rather than by running the full migration chain — a new install stamps itself as fully current without executing `database_updates.php`. In upstream ITFlow this drifted out of sync in places (e.g. an entire feature's tables present in migrations but missing from `db.sql`); if you're touching schema, treat `db.sql` and `database_updates.php` as two things that must be kept in sync by hand, not as automatically consistent, and verify a fresh install actually has the columns/tables your code expects.

## 7. REST API

`api/v1/index.php` is a flat, single-router dispatcher, entirely separate from the cookie-session auth used by the four portals above. It supports Bearer-token auth (per-user tokens in `api_tokens`, minted via `POST /api/v1/auth`) and a legacy company-wide `X-Api-Key` mechanism with its own read/write permission and optional client scoping. The API was deliberately left out of the Department rename (paths and fields still say `client`), and the RivetIT rename does not change it either — see `docs/API.md` for the full reference rather than duplicating it here.

## 8. Integrations and background jobs

### RMM (Remote Monitoring & Management)

A factory (`includes/rmm_client_factory.php`) instantiates the right vendor client based on `rmm_integrations.type`. Four providers are implemented: Tactical RMM, Level.io, Action1 (OAuth2 patch management), and Sophos Central (OAuth2, firewall inventory + alerts only). An asset mapper (`includes/class_rmm_asset_mapper.php`) matches RMM agents to RivetIT assets and syncs alerts. Sync runs on every cron cycle (`cron/cron.php`) for each enabled integration row; RMM alerts can auto-create tickets and feed the ticket-automation rule engine.

### UniFi networking

`includes/class_unifi.php` (local controller) and a cloud-controller client talk to UniFi; a mapper turns UniFi devices, WLANs (with PSK), and network configs into RivetIT assets, credentials, and networks respectively. Unlike RMM, this sync is **not** wired into the main cron dispatcher — it's a standalone CLI script (`scripts/unifi_sync_cli.php`) that needs its own separately-added cron entry.

### Accounting — QuickBooks Online

Part of the accounting module (not offered in RivetIT, see §9). One-way push (RivetIT → QBO) via OAuth2, resolving dependencies (customer before invoice, invoice before payment) through a queue drained by `cron/accounting_sync.php`, with exponential backoff and idempotency guaranteed by an entity-mapping table. Entities never sync back from QBO.

### Payments — Stripe

A provider-abstraction interface (currently Stripe-only) backs guest invoice payment, client-portal saved cards, and agent-recorded payments, plus a webhook receiver. Secrets are stored encrypted.

### Identity — Microsoft/Entra SSO (client portal login)

`client/login_microsoft.php` implements an OAuth2 authorization-code flow against Entra ID for client-portal login only, fetching the authenticating user's own profile via Microsoft Graph. Separately, Microsoft OAuth is also used for connecting mailboxes (inbound/outbound email), which is a distinct integration from portal SSO.

### Email

Inbound: a cron script polls configured mailboxes via IMAP (with OAuth2 support for Microsoft 365/Google Workspace) and creates/updates tickets from incoming mail. Outbound: a cron worker drains an app-wide mail queue via PHPMailer, supporting SMTP or OAuth-token sending.

### Real-time — SSE and push notifications

Server-Sent Events backed by Redis pub/sub deliver live ticket updates, live ticket chat, and in-app notifications to the web UI and mobile clients. Firebase Cloud Messaging (hand-rolled JWT/OAuth exchange) delivers push notifications to registered device tokens.

### Cron / background jobs

All are PHP CLI scripts under `cron/`, runnable standalone or included from the umbrella `cron/cron.php` dispatcher on an admin-configurable schedule (Settings > Cron Manager): recurring ticket/invoice/expense generation, backups, ticket automation, RMM sync, mail queue processing, inbound mail parsing, QuickBooks sync, CRM reminders, certificate/domain monitoring refresh, daily metrics rollup, and scheduled report emails. `scripts/unifi_sync_cli.php` is the one integration sync that lives outside this dispatcher pattern.

## 9. How RivetIT differs from upstream ITFlow

RivetIT started as a fork of an MSP-focused ITFlow fork (TheTractorHacker/itflow, itself a fork of itflow-org/itflow; see [`NOTICE`](../NOTICE)) and was known as "ITFlow Internal IT" before the RivetIT name. It is built for an internal IT department serving one organization's many internal departments rather than an MSP serving external billing clients. The structure described above is shared with the upstream codebase. The main differences:

- **Terminology, not code**: every human-facing "Client"/"Clients" string was renamed to "Department"/"Departments" (navigation, page titles, labels, emails, PDFs). PHP variable/function/table/column names and the entire REST API (`api/v1/*`) were deliberately left as `client` to avoid breaking the API contract and to minimize code churn — so the codebase and database still say `client_id` everywhere internally, as described throughout this document.
- **Billing modules are off**: `config_module_enable_accounting`, `config_module_enable_ticket_charges` and `config_module_enable_payroll` are off on fresh installs, and Settings > Modules no longer offers them: saving that page writes them as 0 (`admin/post/settings_module.php`). The code and tables are still there from upstream; nothing was deleted.
- **Contracts remain fully enabled**, reframed as SLA/service-terms documentation between IT and departments rather than a billing artifact.
- **CRM is off** — the MSP sales-pipeline/opportunities/leads feature doesn't fit an internal-IT use case; like billing, it is not offered in Settings > Modules and saving that page keeps `config_module_enable_crm` at 0. The code is unchanged.
- **AnyDesk remote-support integration** — a new `asset_anydesk_id` field and a "Connect via AnyDesk" quick-launch button on the asset detail page, in addition to the pre-existing generic asset URI fields.
- **Security Classification on departments** — a General/Confidential/Restricted field on departments, paired with per-user department access restrictions, as groundwork for information-classification controls.
- **Directory sync** — one-way sync of departments and employees from an external directory, with three implementations: Microsoft/Entra, Google Workspace and Odoo. None exists upstream.
- **Intune device management** — a new integration with its own module toggle, not present upstream.
- **Training (LMS)** — courses, quizzes, assignments and requirement rules, compliance records with a tamper-evident ledger, certificates with public verification, the shop-floor kiosk, and Odoo write-back (`src/Training/`, `kiosk/`, `verify/`). Not present upstream.
- **Compliance and deployment tooling** — an ISO 27001 Annex A compliance mapping document (`docs/ISO27001-COMPLIANCE.md`) and secure deployment scripts (`deploy/install.sh`, `deploy/harden.sh`, `deploy/backup.sh`, `deploy/update.sh`) ship with this edition; see those files directly for details.
- **The REST API was left out of the rename** — endpoints, auth mechanisms and field names still say `client` / `client_id`, per the naming note above, so existing integrations and the Android companion app keep working. See `docs/API.md` for the full reference.