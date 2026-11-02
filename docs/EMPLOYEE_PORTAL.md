# Employee self-service portal

The department portal (`/client/`) now has an employee view. Everything an employee sees is read-only and scoped to the
logged-in contact and that contact's own department; nothing takes a contact or department id from the request.
Logic lives in `src/Portal/EmployeeHome.php`; pages are thin.

## Who sees what

| Person | Home page |
|---|---|
| Employee (not a primary or technical contact) | Employee home: announcements, request search, My open requests, My devices (OS, last seen), Help articles (popular and recently updated), Waiting on me (managers only), My checklist (only if a run exists), My training due (if Training is on). Replaces the department stat tiles, "Recent tickets" and "Assigned assets" cards. |
| Department administrator (primary / technical contact) | The existing home page, unchanged, with the same extra sections (search, waiting on me, checklist, training) underneath. |

Sections an administrator turns off disappear (eight keys: `requests`, `approvals`, `devices`, `onboarding`, `training`, `catalog`, `announcements`, `kb`); with every section off the original home page shows.

## Pages

- **Home** (`client/index.php`, `client/includes/employee_home.php`). The search box opens the catalog (`service_catalog.php?q=...`) pre-filtered; Popular and Recent shortcuts reuse `ServiceCatalogService::trending()` and `recentForContact()`.
- **My devices** (`client/assets.php`). Employees see only assets assigned to them (`asset_contact_id` = their contact). Department administrators keep the department list by default and get a **Mine / All in my department** toggle. A `?scope=all` from an employee changes nothing. Each of your own devices has **Report a problem**, which opens `ticket_add.php?asset_id=N` with the subject and asset prefilled, but only if the asset is yours; any other id prefills nothing. The portal ticket handler also drops an asset id that is not assigned to the submitter. No credentials, IPs or notes are shown.
- **My requests** (`client/tickets.php`). Always the contact's own tickets. Department administrators get a **Mine / Department** toggle (Department is the existing all-tickets page and keeps its own administrator check). `ticket.php` and the ticket actions already allow only the requester or a department administrator.
- **My checklist.** The contact's own most relevant onboarding/offboarding run (open first, then newest, never cancelled) as a read-only list: task title, owner label, state, due date. Instructions, automation settings and errors are not shown, and automated (action) tasks are left out.
- **Profile** (`client/profile.php`). Read-only title, manager, department, location, start date and a devices summary. The only editable fields are **phone and mobile**; the handler (`edit_my_contact_details`) updates the session contact only, accepts digits, spaces and `+ ( ) - . x #`, and ignores everything else posted.
- **Request onboarding** (`client/onboarding_request.php`), see below.

## Announcements (DB 2.6.221)

Administration > Settings > Portal announcements (`admin/portal_announcements.php`, administrators only, audit-logged). Title, plain-text body (escaped on output, never HTML), importance (information / important / urgent), who sees it (all departments or one department), publish and expiry time (blank = now / never) and pin. Employees see what is published, not expired and not removed, for all departments or their own, pinned and urgent first, at the top of the home page. A department id of 0 (admin preview) sees only the all-department ones. Invalid input (bad or impossible dates, expiry before publish, unknown department or importance, over-long text) is refused with a message and saves nothing.

## Help articles and device facts (DB 2.6.222)

- Home **Help articles** (needs the Knowledge Base module): Popular = most read in the last 30 days, New and updated = newest changes. Only articles the portal already lets that contact read (client visible, company-wide or their department, not archived). Reads are counted in `kb_article_views` when a real contact opens `kb_article.php` (once per contact per article per hour; rows older than 180 days are pruned). A company-wide article's count includes reads from every department; only the aggregate is shown.
- **My devices** (`assets.php`, employees): a card per device with type, make/model, serial, OS (asset OS, else the RMM link's), status, warranty (date and days left; in warranty / ends soon (60 days) / ended), purchase and in-service dates, and **last seen** (newest of the built-in agent's last check-in and the RMM link's last sync) with "Restart needed" when the agent reports it. No notes, IPs, credentials or logged-in user.

## Notification feed (DB 2.6.223)

`client/notifications.php` plus a badge on the nav. Built from existing data, nothing is sent: announcements, staff replies and closures on the contact's own requests (30 days), approvals waiting on them, checklist tasks due within 3 days or overdue, and device warranties ending within 60 days (or ended in the last 30). **Mark all as read** (`mark_feed_read` in `client/post.php`, keyed on the session contact) stores a time in `portal_feed_state`; "sticky" items (approval, due task, warranty) stay unread until dealt with.

## Employee API (DB 2.6.223) - UNVERIFIED LIVE

`api/v1/portal_auth`, `portal_home`, `portal_devices[/id]`, `portal_checklist`, `portal_announcements`, `portal_notifications` (see `docs/API.md`, `api/v1/openapi.yaml`). A portal login signs in with `POST /portal_auth` and gets a token stored hashed in `portal_api_tokens`; it never touches `api_tokens`, so it cannot reach any staff endpoint and a staff token cannot reach these. Each request re-checks that the login is active, not archived, the portal is on and the contact and department are not archived; tokens idle for 90 days expire; sign-out revokes. Single sign-on accounts have no local password and cannot use it. No mobile app screens use it yet (the Android/iOS apps are separate repos).

## Verifying live (what Blake must do)

Everything above was exercised on a scratch install only (`tests/employee_portal_i32.php`, `tests/browser/employee_portal_i32_smoke.mjs`). On beta / mwa after the update (migration 2.6.137 carries the earlier portal settings; 2.6.221-2.6.224 are new):

1. Apply the database update; check Settings > Employee portal shows eight sections and Portal announcements opens.
2. Sign in to the portal as a real employee (not an administrator) and a real department administrator; confirm the home pages, one announcement for all departments and one for a single department, Help articles, My devices with a device that has an agent, and the notification badge.
3. `curl -s -X POST https://HOST/api/v1/portal_auth -d '{"username":"EMAIL","password":"PW"}'` then `GET /api/v1/portal_home` with the token; try a staff token on `/portal_home` and the employee token on `/me` (both must be 401).
4. If the install enforces 2FA for portal logins, confirm the API asks for the code.

## Onboarding requests

Off by default. Switch it on under Administration > Settings > Employee portal (`admin/settings_portal.php`).

- **Who can ask:** the setting is on AND the contact is a manager (has at least one active direct report) or a department administrator. Ordinary employees and the admin preview never qualify. The nav entry, page and handler all check this.
- **Form:** full name, personal or work email, start date (within 30 days back and one year ahead), manager, role/title, notes. A manager can only name themselves; a department administrator can pick any active contact of the department. Everything is validated again on the server.
- **What it does:** creates the new hire in the requester's own department as a `pre-hire` contact (start date, manager, title, notes; no portal login) and fires the usual hire event (`LifecycleEvents::afterChange`, which only emits when automatic lifecycle detection is on). Then:
  - if an active onboarding workflow template is chosen in the settings, it starts a run with `WorkflowService::startRunIfNone()`. A manager approval task in the template waits for the new hire's manager as usual;
  - otherwise it opens a ticket with the details, assigned by the normal ticket defaults.
- **Idempotent:** the same email in the same department that is already a pre-hire is reused (same contact, same run, no second ticket). An email that belongs to an active person is refused. The same email in another department is a different person.

## Settings and migration

Migration **2.6.137** adds three `settings` columns (idempotent `ADD COLUMN IF NOT EXISTS`, also in `db.sql`):

| Column | Default | Meaning |
|---|---|---|
| `config_portal_home_sections` | all six keys | Comma list of enabled sections: `requests`, `approvals`, `devices`, `onboarding`, `training`, `catalog`. |
| `config_portal_onboarding_requests` | `0` | Allow onboarding requests from the portal. |
| `config_portal_onboarding_template_id` | `0` | Onboarding template to start; `0` opens a ticket instead. |

Before the migration runs, the portal reads these as "all sections on, onboarding requests off".

## Tests

`php tests/employee_portal_i32.php` (scratch database plus a throwaway `php -S` server for the API; runs `tests/browser/employee_portal_i32_seed.php` first): announcements validation and scoping, help articles, device facts, the feed, the employee API and migrations 2.6.221-2.6.224. `tests/browser/employee_portal_i32_smoke.mjs`: real-browser check of the home, announcements admin page, feed and devices at 1280px and 390px, light and dark.

`php tests/employee_portal.php` (scratch database, see the header of the file): scoping between employees and departments, Mine filters, checklist and profile exposure, who may request onboarding, validation, idempotence, template and ticket paths, migration idempotence.
