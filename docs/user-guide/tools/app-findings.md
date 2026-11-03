# Application findings from writing the user guide

While documenting each module against the code and a running demo, the writers noticed behaviour that looks
like a bug or a rough edge. **None of it was changed.** It is recorded here so maintainers can triage it. Where
the guide describes such behaviour it says so in a **Note**; remove those notes once the application is fixed.

Every item was re-checked by reading the code on `main` at commit `d47f0fa6` (RivetIT 26.10.14); items that
turned out to be fixed since they were first seen have been dropped. File and line references are current at that
commit, but lines drift, so search for the surrounding code if a number no longer matches. "Not rendered" means
the claim comes from markup and needs a visual check.

## Final status (rc2-i29, on top of beta 26.10.81, DB 2.6.231)

The leftovers of the 26.10.80 pass below (everything marked not fixed, needs a decision, unconfirmed or needs a live test) were each
reproduced against a throwaway install with fictional data (`tests/browser/i29_leftovers_smoke.mjs`, run with a node Playwright driver;
logic in `tests/i29_leftovers.php`), on the code before the change and again after it. The Status column in the two tables below is now final:
**Fixed here** means it failed before this branch and passes now; **Not reproducible / already fixed** means the same test passed on the
unchanged code (the test now guards it); **Decided** gives the choice made. Nothing is left open in this list.

## Re-verification status (beta 26.10.80, DB 2.6.210)

Re-checked by reading the current code in the `beta-i29b` worktree (origin/beta d798e3054). This was a code
read, not a live run: nothing below was exercised against a running instance in this pass. The original list
below is kept as written (it describes `main` at 26.10.14); the Status column here is the current answer.

| Area | Item | Status |
|---|---|---|
| Highest priority | 1 installer skips migrations (`setup/index.php:583-587` now stamps the `db.sql` marker version and runs the rest) | already fixed |
| Highest priority | 3 log retention 0 (`admin/post/settings_security.php:146` "0 keeps everything", compliance floor) | already fixed |
| Highest priority | 4 Department Type wiped (`COALESCE(?, client_type)`), 5 People Import (location lookup includes `department_sites`), 6 IR reset limited to `user_type = 1` | already fixed |
| Highest priority | 2 credentials CSV needs only Read | owned by the security/permissions agent |
| Security and permissions | all items | see the issue #29 status table in the Security and permissions section below |
| Tickets | `&` in category group headers/counts (counts keyed on the raw label) | already fixed |
| Tickets | Force Reoccur Three Days/Biweekly (`recurring_ticket.php:193-205,338-350`) | already fixed |
| Tickets | category group with sub-categories not selectable (`ticketCategoryOptions` uses optgroups; a childless group is an option) | already fixed |
| Tickets | system notes log one minute (all system notes now `00:00:00`) | already fixed |
| Tickets | `ticket_onsite` unreachable (`ticket_edit_schedule.php` linked from `agent/ticket.php:553,712`) | already fixed |
| Tickets | Share with all agents below Full (checkbox only shown at Full, `ticket_saved_view_add.php:53`) | already fixed |
| Tickets | Resolved remapped to Closed | by design (documented in `agent/post/ticket.php:1126-1129`) |
| Tickets | `ticket_history` never displayed | already fixed (rendered from `agent/ticket.php:405`) |
| Tickets | Email Sent shown without SMTP (flag now requires SMTP host/provider) | already fixed |
| Tickets | "(v2)"/"(v1)" titles and stray "To-do" text | not reproducible (no match in `agent/`) |
| Tickets | `problem.php` falls back to internal id | already fixed (`findTicketIdForLinking` returns 0) |
| Tickets | Recurring ticket form has Assets tab | already fixed |
| Tickets | "Assigned" status lookup | already fixed (`ticketCreationStatusCandidates` is explicit; fallback is by design) |
| Tickets | automation `escalate` label, settings parser filename | already fixed |
| Tickets | mail queue red bin marks Failed | by design (flash now says "cancelled and marked as failed") |
| Projects and calendar | event click, department field name (`client_id`), repeat control removed, CSP `font-src`, `initialView` | already fixed |
| Projects and calendar | archived project Delete permission check (`lookupUserPermission(...) >= 3`) | already fixed |
| Projects and calendar | template task estimates copied; project delete releases tickets/tasks/milestones | already fixed |
| Projects and calendar | closed ticket to No-Department project | Not reproducible, already fixed: linking ticket 7001 (NULL `ticket_updated_at`) to a No-Department project succeeds and leaves the NULL alone; a ticket from another department is still refused (smoke test) |
| Projects and calendar | task un-tick after Kanban move | Not reproducible, already fixed: Kanban to Done then un-tick on Details returns the task to To Do with progress 0; completing on Details lands in Done, and moving it out of Done clears progress 100 (smoke test) |
| Projects and calendar | project list task bar | Not reproducible, already fixed: with 3 tasks (2 project-only, 1 ticket task) and 1 done the bar shows 1 of 3 (smoke test) |
| Projects and calendar | `?archived=1` default status | already fixed (`projects.php:26-27`) |
| Projects and calendar | task template delete looked up `tags` | already fixed (`getFieldById('task_templates', ...)`) |
| Departments and people | Location pickers ignore shared sites | already fixed (all pickers join `department_sites`) |
| Departments and people | restore note key, workflow redirect, CSV export access/archived | already fixed |
| Departments and people | no UI to move a person between departments | Fixed here: row menu **Move department** (Departments at Modify, access to both departments). Unassigns their devices and licence seats in the old department by default (untick to keep), keeps the location only if the new department can use it, clears the primary-contact flag, audited, and raises `employee.transferred` through `LifecycleEvents::afterChange` (only when Employee workflows auto-start is on). Reverses the earlier "No change" decision (`docs/FINDINGS-DECISIONS.md` 12). No API equivalent: web only |
| Departments and people | CRM leftovers with CRM off | already fixed (both cards gated on `config_module_enable_crm`) |
| Departments and people | `config_destructive_deletes_enable` has no writer | already fixed (Admin > Security setting) |
| Departments and people | bulk department email filter/no-box behaviour | Fixed here (one part): no recipient group ticked queues nothing (already so), archived people are never mailed (already so), but contacts of an ARCHIVED department were mailed when that department was selected; they are now excluded (`agent/post/client.php`, reproduced then fixed) |
| Infrastructure | location dropdowns, rack Model field (`name="model"`), contract permission, vendor_details, KB Delete gating, KB import title, portal credential tokens | already fixed |
| Infrastructure | Firewall/Router assets in no tab | already fixed (Network tab, `agent/assets.php:127`) |
| Infrastructure | domain expiry overwrite | already fixed |
| Infrastructure | license seat links | Fixed here (hardening): an edit already kept seat links outside the licence's department, but a forged asset/person id from another department was linked; adds and edits now link only assets and people of the licence's own department (`INSERT IGNORE ... SELECT`, reproduced then fixed) |
| Infrastructure | OTP element id collision | already fixed (`agent/js/credential_show_otp_via_id.js` targets the clicked trigger) |
| Infrastructure | Company-wide vendors need `module_financial` | Decided and fixed: Tickets, assets & docs (Support) Read lists/opens them and Modify adds, edits, archives and restores them. Still Finance-only: account number (hidden from the list, search, details and forms for roles without Financial, and never changed by their edits), Delete, and the vendor CSV. Reverses `docs/FINDINGS-DECISIONS.md` 11 |
| Infrastructure | Bulk Transfer copies and archives original | by design (documented behaviour) |
| Infrastructure | credential History `title=""` markup | already fixed (`strip_tags` on escaped values) |
| Infrastructure | TinyMCE image upload tab | by design (deliberately disabled in the file) |
| Training | achievements "once the Learning Center launches" text | already fixed (no match) |
| Training | kiosk Origin vs `config_base_url` | by design |
| Training | New License Devices tab header | Not reproducible: opened the real pop-up and the Devices tab in light and dark; the header label measures 14.68:1 (white on `#1F2937`); screenshots checked |
| Endpoints | Connect on Sophos/Action1 (`method_exists` guard), Sophos alert `vendor_warning` shown, `config_comet_auto_ticket` read, `metrics_ingest.php` present, Docker supervisord runs mail queue and parser | already fixed |
| Endpoints | RMM critical figure, script categories, "agents" wording | already fixed |
| Reports | Day-by-Day label/default, credential rotation archived, hub wording | already fixed |
| Reports | CSAT note vs rating date | already accurate: `agent/reports/csat.php` says ratings count when *submitted* and only the response rate uses tickets *closed* in range |
| Reports | Waiting on Employee tile | Fixed here: counts open tickets whose status is named Waiting on Employee / Waiting on Customer OR is flagged "waiting on someone else" (pauses SLA), so a differently named status still counts; caption now says "waiting on someone else" (`WidgetRegistry::ticketsWaitingOnCustomer`) |
| Roles/settings | Reporting text, Activate on archived user, API key Secret column, roles.php ", " join, user CSV, rows-per-page options (now 5/10/20/50/100/500), header Light override, `contacts.php` role check, Invoices start page, passkey message | already fixed |
| Roles/settings | session length minimum vs stored defaults | Decided, no change: the Security form and the login path both show/apply the effective value (session default 20160 min = 14 days; Remember-me has a 30-day minimum applied the same way everywhere, the stored default 3 is never used), so nothing disagrees any more |
| Roles/settings | Departments date range "1970" display | Fixed here (the open-ended case): All time, the open panel and a custom range all read correctly, but a custom range with only a From (or only a To) date showed `Jan 15, 2026 – Dec 31, 2099` after the page reloaded (and filled the empty input with the bound). It now shows `From Jan 15, 2026` / `Up to ...` and keeps the open end empty (`includes/date_range_picker.php`, reproduced then fixed) |
| Roles/settings | `admin/category.php` opens on Expense | by design (first tab); the tab overlap is Not reproducible: the button row wraps (`btn-group flex-wrap`), no two buttons overlap at 1440, 1024 or 390 px and the page has no horizontal scroll (smoke test, screenshots checked) |
| Roles/settings | REBRANDING.md telemetry paragraph | by design (it records that the upstream code was removed) |
| Roles/settings | portal/guest `bg-dark` card titles | Not reproducible: rendered the portal ticket title and the guest ticket title in light and dark, each measures 14.68:1 (white on `#1F2937`) |

Net result of the 26.10.80 pass: no source change was needed for any item that pass could confirm. The leftovers it could not confirm were
then settled by the rc2-i29 work described at the top of this section.

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

Status re-verified against `origin/beta` (26.10.80, DB 2.6.210) for issue #29. Statuses: **Already fixed** (guard found in current code, pinned by `tests/security_findings_i29.php`), **Fixed here**, **Not reproducible**, **Not fixed** (reason given).

| # | Finding | Status |
|---|---------|--------|
| 1 | Users edit lets an administrator demote themselves or the last administrator | Already fixed: `admin/post/users.php` refuses to move the only active administrator to a non-admin role |
| 2 | Legacy API keys act as the lowest-id agent with no admin check | Already fixed: `X-Api-Key` is refused with 401; machine callers use integration tokens as a role-less service identity (docs/API.md matches) |
| 3 | RMM `save_policy` / `delete_policy` need only level 1 | Already fixed: both need `module_rmm` level 2. Leftover fixed here: deleting a policy now asks the RMM to remove the checks it pushed (`includes/rmm_policy_cleanup.php`). A deployment the RMM cannot remove is kept, marked `remove_failed`, and the policy is kept with a "Delete anyway" choice; forcing it logs the device names and check ids left on agents. Tactical is the only integration that pushes checks |
| 4 | Adding/deleting a report schedule needs only Reporting level 1 | Already fixed: needs level 2, and schedules owned by someone else are owner/admin only |
| 5 | Reports ignore per-user department restrictions | Already fixed: `ReportScope` clauses in every report function and page; web calls pass no client id so the session scope applies |
| 6 | Restore edge cases (passphrase-encrypted manifest, old backups, `--admin-user` only) | Decided, one small fix: `deploy/restore.sh` refused an archive whose inner manifest is `backup-manifest.json.enc` with "no --passphrase-file" although it already requires one; it now decrypts it with that file. The other two are deliberate, documented limits and unchanged: a backup that predates the manifest restores data and uploads and warns that encrypted settings must be re-entered; `--admin-user` recovers the vault master key only for a same-box rollback and never `config_settings_enc_key` (`deploy/restore_admin_zip.sh` header) |
| 7 | Portal Contacts tile logs an ordinary employee out | Already fixed: tile only shown to technical contacts, and a direct visit redirects home with a message |
| 8 | Portal preview lists every department's unassigned assets | Not reproducible: the assigned-assets query requires `asset_contact_id = contact` and a real contact id |
| 9 | Switching the portal off does not end signed-in sessions | Already fixed: `client/includes/check_login.php` re-reads `config_client_portal_enable` every request |
| 10 | System replies reach employees | Not reproducible: the portal ticket view shows only `Public` and `Client` replies |
| 11 | Single contact delete never deletes the portal login | Already fixed: `contact_user_id` is selected and the login is deleted (single and bulk) |
| 12 | Bulk Delete in Archived views has no confirmation | Already fixed: every bulk delete button carries `confirm-link` (modal names the count); user Revoke/Activate/Disable and API-key revoke/delete also confirm |
| 13 | Portal-created document stores a contact id in `document_created_by` | Already fixed: stores the portal user id |
| 14 | Technician role has no Reporting or Knowledge Base rows | Fixed here (fresh installs): both installers seed Knowledge Base Modify for Technician. Reporting is deliberately NOT granted: reports are company-wide, so an administrator grants it. Existing installs are not changed (an absent row cannot be told apart from one an administrator removed), so no migration |
| 15 | Credentials CSV export needs only Read | Already fixed (earlier work): needs Full |
| 16 | IR password reset also resets Department Portal accounts | Already fixed (earlier work) |
| 17 | `contract_edit.php` enforces a `module_contracts` permission that does not exist | Not reproducible: no reference remains |
| 18 | Department CSV export ignores per-user department access | Already fixed: uses `$access_permission_query` and skips archived rows |
| 19 | `agent/contacts.php` row menu tests `role == 3` instead of a permission | Not reproducible: no such test remains |
| 20 | KB article page always shows Delete | Already fixed: shown only at `module_kb` level 3, which the handler also requires |
| 21 | Company-wide vendors need `module_financial` | Fixed here (model changed): see the Infrastructure row above. Support Read/Modify manage them; account number, Delete and CSV stay Finance |
| 22 | Users Export CSV includes archived users | Fixed here: archived users were already left out, but Department Portal logins were included; the CSV is now agents only (what the list shows). A new **Include archived users** box in the dialog adds archived agents, marked Archived |

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
