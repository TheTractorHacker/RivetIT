# Employee self-service portal

The department portal (`/client/`) now has an employee view. Everything an employee sees is read-only and scoped to the
logged-in contact and that contact's own department; nothing takes a contact or department id from the request.
Logic lives in `src/Portal/EmployeeHome.php`; pages are thin.

## Who sees what

| Person | Home page |
|---|---|
| Employee (not a primary or technical contact) | Employee home: request search, My open requests, My devices, Waiting on me (managers only), My checklist (only if a run exists), My training due (if Training is on). Replaces the department "Recent tickets" and "Assigned assets" cards. |
| Department administrator (primary / technical contact) | The existing home page, unchanged, with the same extra sections (search, waiting on me, checklist, training) underneath. |

Sections an administrator turns off disappear; with every section off the original home page shows.

## Pages

- **Home** (`client/index.php`, `client/includes/employee_home.php`). The search box opens the catalog (`service_catalog.php?q=...`) pre-filtered; Popular and Recent shortcuts reuse `ServiceCatalogService::trending()` and `recentForContact()`.
- **My devices** (`client/assets.php`). Employees see only assets assigned to them (`asset_contact_id` = their contact). Department administrators keep the department list by default and get a **Mine / All in my department** toggle. A `?scope=all` from an employee changes nothing. Each of your own devices has **Report a problem**, which opens `ticket_add.php?asset_id=N` with the subject and asset prefilled, but only if the asset is yours; any other id prefills nothing. The portal ticket handler also drops an asset id that is not assigned to the submitter. No credentials, IPs or notes are shown.
- **My requests** (`client/tickets.php`). Always the contact's own tickets. Department administrators get a **Mine / Department** toggle (Department is the existing all-tickets page and keeps its own administrator check). `ticket.php` and the ticket actions already allow only the requester or a department administrator.
- **My checklist.** The contact's own most relevant onboarding/offboarding run (open first, then newest, never cancelled) as a read-only list: task title, owner label, state, due date. Instructions, automation settings and errors are not shown, and automated (action) tasks are left out.
- **Profile** (`client/profile.php`). Read-only title, manager, department, location, start date and a devices summary. The only editable fields are **phone and mobile**; the handler (`edit_my_contact_details`) updates the session contact only, accepts digits, spaces and `+ ( ) - . x #`, and ignores everything else posted.
- **Request onboarding** (`client/onboarding_request.php`), see below.

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

`php tests/employee_portal.php` (scratch database, see the header of the file): scoping between employees and departments, Mine filters, checklist and profile exposure, who may request onboarding, validation, idempotence, template and ticket paths, migration idempotence.
