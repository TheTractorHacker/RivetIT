# Application findings from writing the user guide

While documenting each module against the code and a running demo, the writers noticed behaviour that looks
like a bug or a rough edge. **None of it was changed.** It is recorded here so maintainers can triage it. Where
the guide describes such behaviour it says so in a **Note**; remove those notes once the application is fixed.

Every item was re-checked by reading the code on `main` at commit `d47f0fa6` (RivetIT 26.10.14); items that
turned out to be fixed since they were first seen have been dropped. File and line references are current at that
commit, but lines drift, so search for the surrounding code if a number no longer matches. "Not rendered" means
the claim comes from markup and needs a visual check.

## Highest priority: data loss, exposed secrets, broken fresh installs

1. **Browser installer skips database migrations.** `setup/index.php:567` stamps `LATEST_DATABASE_VERSION`
   (2.6.121) onto a schema that `db.sql` declares as 2.6.100 (`-- RIVETIT_SCHEMA_VERSION`). Migrations 2.6.101 to
   2.6.121 never run, so columns they add are missing: for example `training_lessons.lesson_requires_previous`
   (saving a course lesson fails with "Unknown column") and `settings.config_training_device_code_days` (`/kiosk/`
   reports "not set up"/404 because `KioskSettings::fromDb` finds the schema not ready). `scripts/setup_cli.php`
   and `deploy/install.sh` do this correctly (the CLI stamps the marker version and `install.sh` repeats
   `update_cli.php --update_db` until current). The Docker path uses the browser installer. Related: a single
   `update_cli.php --update_db` run, and a single click of **Update Database**, applies one version step only.
2. **Credentials CSV export** needs only Credentials **Read** (`agent/post/credential.php:561`) and writes the
   decrypted password and the raw OTP secret column.
3. **Log retention** of `0` or blank (`admin/post/settings_security.php:41`, no minimum) makes the nightly job
   delete every row older than today from the audit, app and auth logs (`cron/cron.php:87,135-141`).
   `mail_log` is never pruned.
4. **Edit Department wipes Type.** The form has no Type field, but `agent/post/client.php:268` writes
   `client_type` from `$_POST['type'] ?? ''`, so every save clears it.
5. **People Import update overwrites unlisted fields.** `src/Directory/PersonImportService.php:216-224` sets
   title, phones, manager, start date and more from the CSV row, with blanks when a cell is empty. Its `site`
   column looks locations up with `location_client_id`, so shared sites always error.
6. **IR password reset also resets Department Portal accounts** (`admin/post/users.php:408-428`, no
   `user_type = 1` filter) although the dialog says agent passwords.

## Security and permissions

- Users edit (`admin/post/users.php:99-177`) lets an administrator demote themselves or the last administrator;
  the Roles page has a guard, this does not.
- Legacy API keys act as the lowest-id active agent with no admin check (`api/v1/index.php:183-189`), while
  `docs/API.md:64,100` says "first active admin". Scopes and an IP allow-list now apply.
- `agent/post/rmm_check.php`: `save_policy` (:159) and `delete_policy` (:203) need only RMM level 1 (only
  `push_policy` needs 2); delete leaves the pushed checks on the agents.
- `agent/reports/schedules.php:18`: adding or deleting a report schedule needs only Reporting level 1.
- Reports ignore per-user department restrictions (the dashboard honours them): no `agent/reports/*.php` file uses
  `client_access_string`.
- Restore edge cases: restoring an admin-panel `.zip` now recovers `settings_enc_key` from the manifest, but not
  when the manifest is passphrase-encrypted and no passphrase is given, for backups taken before manifests
  existed, or when `deploy/restore_admin_zip.sh` is run with only `--admin-user`.
- Portal: **Contacts** on Home (`client/index.php:412`) logs an ordinary employee out (`client/contacts.php:15-19`).
  Preview mode lists every department's unassigned assets (`client/includes/check_login.php:223`,
  `client/index.php:151-155`). Switching the portal off only blocks new sign-ins; pages do not re-check
  `config_client_portal_enable`. System replies reach employees (`client/ticket.php:352` hides only Internal).
- `agent/post/contact.php:1003-1007`: single delete reads an undefined `contact_user_id`, so the portal login is
  never deleted (and a PHP warning is raised).
- Bulk **Delete** in Archived views has no confirmation (assets, certificates, contacts, credentials, domains,
  files, locations, networks, network drives, printers, products, recurring tickets, vendors). Revoke, Activate
  and Disable on a user and bulk delete of API keys also act at once; bulk key delete does not check for active keys.
- `client/post.php:1490`: a portal-created document stores a contact id in `document_created_by`, which the agent
  views read as a user id.
- Technician built-in role has no Reporting or Knowledge Base rows (`scripts/setup_cli.php:427-430`).

## Functional bugs

**Service desk and tickets**
- Category names containing `&` show as `&amp;` in group headers and the group counts read 0 (`agent/ticket_list.php:~199-219`).
- Force Reoccur has no rule for Three Days or Biweekly (`agent/post/recurring_ticket.php:~190-204,~329-343`): the
  ticket is created, then the handler fails without advancing the next run, so a second click duplicates it.
- A category group that has sub-categories cannot be chosen in ticket forms (`functions.php ticketCategoryOptions`).
- Automatic system notes each log one minute of time worked (`agent/post/ticket.php:501,997,1081,...`).
- `ticket_onsite` can no longer be set from the UI (`agent/modals/ticket/ticket_edit_schedule.php` is unreachable).
- **Share with all agents** does nothing below Full (`agent/post/ticket_saved_view.php:12`).
- Resolved is remapped to Closed (`agent/post/ticket.php:2197,2640`), so the auto-close-resolved setting only
  affects tickets left Resolved by other means.
- `ticket_history` is written but never displayed (`agent/ticket.php:407`). **Email Sent** shows without SMTP.
- New Ticket form titles read "(v2)"/"(v1)" and the Assignment tab has the stray text "To-do: project, etc."
- `agent/post/problem.php:5-16`: linking falls back to the internal ticket id when no ticket number matches.
- New Recurring Ticket form has no Assets tab outside a department workspace.
- `functions.php:4414` looks for a status named "Assigned" (none is seeded) and silently falls back to the first
  active status.
- `admin/ticket_automation.php:63-76`: the `escalate` action shows as a raw key; set-status and assign-to show ids.
- `admin/settings_ticket.php:46` labels the parser `cron_ticket_email_parser.php` (the file is `cron/ticket_email_parser.php`).
- Mail queue red bin marks a message Failed rather than deleting it (`admin/post/mail_queue.php:27-31`).

**Projects and calendar**
- Clicking a calendar event does nothing (`agent/calendar.php:241-251` fires a jQuery click on an anchor whose
  handler is a native listener in `js/ajax_modal.js`); events cannot be edited or deleted from the calendar page.
- Saving an event from the application-level calendar clears its department (`calendar_event_edit.php:160` names
  the field `client`, `agent/post/event_model.php:11` reads `client_id`).
- Repeat is disabled in the event form and `calendar.php` never expands repeats.
- Calendar previous/next arrows are invisible: the CSP in `includes/header.php:22` has no `font-src`, and
  `agent/calendar.php:205` passes the unknown option `defaultView`.
- Delete on archived projects is hidden from non-administrators: `lookupUserPermission("module_support" >= 3)`
  passes a boolean (`agent/projects.php:281`, `project_details.php:403`).
- The project list task bar counts only ticket tasks (`agent/projects.php:205-209`).
- `?archived=1` with the default Open status is always empty.
- Un-ticking a task on Details after a Kanban move to Done leaves it in Done (`agent/post/task.php:265`);
  moving it out of Done keeps progress at 100 (`agent/ajax.php:2221`).
- Linking a closed ticket to a No-Department project always fails with "Cannot merge into that ticket."
  (`agent/post/project.php:444`); a NULL `ticket_updated_at` is written as an empty string (:449).
- A project created from a template does not copy task time estimates (`agent/post/project.php:~177`); undoing a
  completed ticket task adds a one-minute note (`agent/post/task.php:269`); deleting a project leaves its tickets,
  tasks and milestones as orphans (:348).
- `admin/post/ticket_template.php:104` looks up `tags` instead of `task_templates` when deleting a task template.

**Departments and people**
- Person Location pickers list only `location_client_id = department`, so shared sites (as in the demo) never
  appear (`contact_add.php:8`, `contact_edit.php:258`, `contacts.php:170`, bulk assign).
- No UI moves a person to another department (only People Import can).
- `agent/post/contact.php:345`: restoring a note reads `$_GET['unarchive_contact_note']` (wrong key).
- `agent/post/contact.php:1568` redirects to `../workflow_run.php` after **Start workflow**; from `/agent/post.php`
  that resolves to `/workflow_run.php` (not a page), so the user lands on the start page rather than the checklist
  (reproduced by the writer).
- Department CSV export ignores per-user department access and includes archived rows; import creates a legacy
  per-department location; bulk department email has no archived filter and mails everyone when no box is ticked.
- CRM leftovers with CRM off: person page "Activity Timeline / Log Activity" and department overview "Open Opportunities".
- `config_destructive_deletes_enable` (needed for Delete on people, printers, drives, software, vendors, locations)
  has no setting that writes it.

**Infrastructure and documentation**
- Location dropdowns list only `location_client_id = department` (New/Edit Asset, Bulk Assign Location, Rack
  Add/Edit, Networks filter); the inline Location cell in the Assets list does include company-wide sites.
- Firewall/Router assets appear in no Assets tab (`agent/assets.php:134,137`).
- Rack **Model** is never saved: the form field is `make`, `agent/post/rack.php:19,66` reads `model`.
- `contract_edit.php:3` and `contract_documents.php:3` enforce a `module_contracts` permission that does not exist,
  so only administrators get in.
- Company-wide vendors need `module_financial` for details, add and edit.
- `agent/vendor_details.php` requires files that do not exist; global search links to it (`agent/ajax.php:220`).
- Editing a domain overwrites a past or blank expiry with the WHOIS result (`agent/post/domain.php:82-91`);
  editing a license drops seat links outside its department (`agent/post/software.php:147`); Bulk Transfer copies
  an asset to a new id and archives the original (`agent/post/asset.php:397-470`).
- `client/assets.php:88` shows a green badge only for status Active, so Deployed assets look grey.
- Credentials: the TOTP `otp_<id>` element id collides between the list and the dialog (hovering in the dialog
  writes the code into the row behind); the History tab puts markup inside a `title=""` attribute
  (`credential_edit.php:313-322`); the TinyMCE image upload tab spins forever and leaves a stray file in
  `uploads/kb/` (`agent/kb_article_upload.php`, documented in the file as deliberately broken).
- `agent/modals/kb_article/kb_article_import_pdf.php:82` is headed "Import Word Document".
- Printer **Location** lists ignore `department_sites` (`printer_add.php:10`). Per-row Delete on printers and
  drives is gated, but bulk Delete on the Archived view is not.
- `agent/kb_article.php:324` always shows **Delete** (the handler needs Full, and it only archives).
- `client/kb_article.php` does not render `[[credential:ID]]` tokens, so employees see the raw text.
- `agent/post/credential.php`'s OTP export column is the stored secret value, which may be ciphertext.

**Training**
- `agent/training_achievements.php:7,50` and `agent/training_paths.php:224` say badges are awarded "once the
  Learning Center launches", but the award code already runs.
- Kiosk device adoption compares the browser `Origin` with `config_base_url` (`KioskRouter.php:252,276`), so a
  `127.0.0.1` origin against a `localhost` base is refused (by design, but surprising on test servers).
- The Devices tab of the New License pop-up draws its header dark on dark (markup unchanged; not rendered).

**Endpoints and reports**
- **Connect** on a Sophos or Action1 device fails: `agent/post/rmm_remote.php:63` calls `buildRemoteUrl`, which
  only Tactical and Level implement, and the handler catches only `RuntimeException`.
- Acknowledging or resolving a Sophos alert returns a `vendor_warning` (no `ackAlert`) that the UI never shows
  (`agent/post/rmm_alert.php:53,72,83`).
- `config_comet_auto_ticket` is saved but never read by `includes/comet.php` or `comet_webhook.php`.
- `/api/v1/metrics-ingest` has a handler file but no route, no `device_metric_tokens` table and no UI for tokens.
- Custom fields (`admin/settings_custom_fields.php`) are stored but never read; the page is reachable only from the
  Settings hub and search, not the side navigation.
- Docker installs run only `cron/cron.php` (`docker/supervisord.conf:54`); `deploy/install.sh` also schedules
  `mail_queue.php` and `ticket_email_parser.php`.
- RMM: the dashboard "critical" figure counts errors too (`agent/rmm_dashboard.php:27`); the Script Library category
  filter knows five categories (`agent/rmm_scripts.php:5`); the check policies list reads "1 agents"
  (`agent/rmm_checks.php:136`); **Ack All Shown** acts only on ticked rows; the **More RMM actions** button uses
  `btn-outline-light` (not rendered).
- Reports: the Day-by-Day dropdown says "This month" while the default is the last 30 days
  (`agent/reports/ticket_day_breakdown.php:8-14,48-50`); credential rotation includes archived credentials
  (`credential_rotation.php:11-16`); the hub says Time by Technician is "per month" but the page is yearly
  (`agent/reports/index.php:74`); the dashboard **Waiting on Employee** tile is 0 unless a status has that name (or
  "Waiting on Customer"); the CSAT note says tiles are scoped to closed tickets, but the rating totals use the
  rating date (`functions.php:2124-2200`).
- Roles: the role editor says Reporting "Open every report" although each report also needs read access to its own
  area (`admin/modals/role/role_lib.php:178-186`); an archived user's row menu offers Activate, which leaves the user
  archived (`admin/users.php:211`); the API keys **Secret** column shows the last four characters of the stored
  hash (`admin/api_keys.php:116`); `$extended_log_description` is built but never logged
  (`admin/post/users.php:143,186`); Users **Export CSV** includes archived users; `roles.php:97` joins member names
  with "," and no space; a kiosk-only role is sent to Devices & PINs, which then requires Training Read
  (`includes/module_access.php:149`, `src/Training/Core/Access.php:150`).
- Preferences offers page sizes 10/25/50/100 while list footers offer 5 to 500 (`user_preferences.php:60`,
  `includes/filter_footer.php:26-31`); a footer value outside the four resets to 10 on save.
- Departments **Date range** still shows `1970-01-01 - 2099-12-31` in the running app: `js/app.js:1138-1160` `setDisplay` writes "All Time" but the Litepicker constructor then overwrites the field with the raw range.
- The effective session length is a 30-day minimum applied in code (`includes/session_init.php`, `includes/load_global_settings.php`) while the stored defaults (`config_login_session_lifetime` 480, `config_login_remember_me_expire` 3) differ.
- `admin/settings_default.php` offers Invoices as a Start Page option even when accounting is off.
- `includes/header.php:56`: choosing Light cannot override a company-wide dark default.
- `agent/contacts.php:529`: the row menu tests `$session_user_role == 3` instead of a permission.
- `js/webauthn_signin.js:57-58` tells users to type their email and click the passkey button again, but the sign-in
  page has no email field for that.
- `includes/filter_header.php`: the first click on a different column heading sorts in the opposite direction.
- `admin/category.php` opens on the billing "Expense" tab (not rendered: tab row overlap at 1440 px).
- `REBRANDING.md:273-278` still says telemetry reports to `telemetry.itflow.org`; the code sends nothing.
- Dark-on-dark card titles in the portal ticket and guest pages (`bg-dark` headers; not rendered).
