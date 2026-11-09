# RivetIT read-only broken-page sweep (admin / agent / client)

**Run date:** 2026-10-22. **Report-only: nothing was fixed.**

## Read this first: what this run is and is not
- It ran against **demo data on a local copy of the current `beta` code** (commit `0cb2c11f4`, app 26.10.66, DB 2.6.189), served from `127.0.0.1:8191` with its own scratch database. It did **not** run against `mwa.foleyit.com` or the beta box. We had no credentials for them, and the prompt forbids side effects on live data. Anything that depends on live data, live integrations, a configured mail server, or the real nginx/FPM stack was **not** tested.
- The demo data is thin in places. There are no invoices, quotes, document templates, vendor-linked records and so on, so pages that need those rows were loaded without them. The RMM module is off in this install.
- Findings below come from the code that is on `beta` today. Where the same defect was already seen on the unmerged `qa-ux-20261021` branch, that is noted.
- Console/network evidence comes from Chromium (Playwright). PHP warnings come from the PHP built-in server log (`display_errors` is off, so users would not see those). Every "confirmed" finding was re-checked by hand after the automated pass.

## Setup deviations (worth knowing)
- The demo build script (`docs/user-guide/tools/build-demo.sh`) failed twice on `beta`, which is a finding in itself (see **F5**). I worked around it by hand in the scratch DB only: I dropped the duplicate columns, replayed the last two seed `.sql` files, and left out the seed step that builds a backup.
- `vendor/` is git-ignored and only partly tracked, so the worktree needed the missing vendor packages copied in (from the main checkout, add-only, ignored by git). No tracked file was edited.
- Accounts: admin = Alex Morgan (role Administrator, `role_is_admin=1`). Technician = Priya Nair (role **Technician**, `role_is_admin=0`, verified, so **not** a framework admin). I added two throwaway accounts in the scratch DB: `QA-UI- Training L2` and `QA-UI- Training L3` (training permission level 2 and 3). Portal = Sophie Tran (regular staff, not primary contact) and Grace Okafor (primary contact), because the portal behaves differently for the two.

## Counts
| Role | Module | Pages loaded | Skipped | Page loads (incl. `?tab=` variants) | Tabs clicked | Modals opened |
|---|---|---|---|---|---|---|
| Admin | admin/*.php (119 files) | 97 | 22 | 98 | 11 | 4 |
| Admin | agent/*.php (160) | 152 | 8 | 162 | 26 | 2 |
| Technician | agent/*.php | 152 | 8 | 162 | 0 | 1 |
| Training L2 | training pages in agent/ | 36 | 0 | 46 | 3 | 0 |
| Training L3 | training pages in agent/ | 36 | 0 | 46 | 16 | 0 |
| Portal (Sophie, staff) | client/*.php (41) | 35 | 6 | 35 | 0 | 0 |
| Portal (Grace, primary) | client/*.php | 35 | 6 | 35 | 0 | 4 |
| Admin / Technician / Portal (Grace) | extra pass for pages that take a bare `?id=` | 9 / 6 / 5 | | | | |

- **Modal check, separate pass:** every `.ajax-modal` trigger found on each page was fetched the way the click does (GET of the modal URL), then up to two visible triggers per page were really clicked. Unique modal URLs checked: 120 (admin pages as admin), 312 (agent pages as admin), 258 (agent pages as technician). The pass only opens and closes modals. It never submits a form.
- **Static link check:** every internal `.php` link on every loaded page was checked against the file tree (about 360 page loads).
- **Role-boundary probe (GET only, no redirects followed):** technician, Training L2, portal and anonymous on all 97 admin pages that have a GET view; portal and anonymous on 151 agent pages; technician and anonymous on 34 client pages.
- `admin/settings_*.php` (35 files): 28 pages loaded; 7 were skipped (below). The 7 pages that have `?tab=` links elsewhere in the code were loaded once per tab value, and in-page tab buttons were clicked on every page that has them (37 tab clicks as admin). In-page `#anchor` links were checked for a matching target on the page (no dead anchors found).

## Skipped on purpose (36 files) and why
POST handlers (`admin|agent|client/post.php`); `admin/update.php`, `admin/database_updates.php` (update and migration runners); `client/functions.php` (an include); OAuth and SSO flows (`oauth_microsoft_mail_callback`, `outlook_calendar_callback/connect`, `login_microsoft`, `login_oidc`, `login_odoo`); `client/login_reset.php` (sends email); `agent/credential_reveal.php` (reveals secrets); `agent/workflow_run.php`, `agent/rmm_script_run.php`, `admin/automation_test.php` (they run things); `admin/cron.php`, `admin/backup.php`, `admin/backup_recovery_panel.php`, `admin/job_queue.php`, `admin/server_tasks.php`, `admin/sync_watch_panel.php`; integrations and mail (`admin/mailbox.php`, `mail_queue.php`, `mail_requests.php`, `agent/mail_requests.php`, `comet_status.php`, `settings_integrations.php`, `settings_comet.php`, `settings_mail.php`, `settings_calendar_sync.php`, `settings_manageengine_mdm.php`, `settings_unifi.php`, `settings_mesh_wizard.php`); `agent/rmm_add_device.php` (enrolment).
- No form was submitted. Modal triggers whose label or URL matched delete, archive, send, sync, check, backup, run, reset, import, update, pay, approve, disable and similar words were never clicked or fetched.
- **Not exercised at all:** the integrations, Odoo and email-sync pages above, which means the Integrations / Odoo / email-sync settings were not looked at in this run. The code was not read beyond what is quoted below.

---

## Findings

Severity key: **High** = page broken or workflow blocked for a real user; **Medium** = a control or page is broken but there is a way around it; **Low** = cosmetic, log noise, or needs a malformed URL.

### Tickets (both sides)
Pages: `agent/tickets.php`, `agent/ticket.php`, `agent/ticket_list.php`, `agent/recurring_tickets.php`, `agent/ticket_attachment_upload.php`, `agent/sse_ticket_stream.php`, `client/tickets.php`, `client/ticket.php`, `client/ticket_add.php`, `client/ticket_view_all.php`, `client/sse_ticket_stream.php`.

- Admin and technician: `tickets`, `ticket` (ticket 248), `ticket_list` (redirects to tickets), `recurring_tickets` all load with HTTP 200, no PHP message, no console error, no failed request. The "New ticket", saved-view, export and bulk-action modals all fetched and rendered with no PHP error (e.g. `modals/ticket/ticket_add_v2.php`, `ticket_export.php`, `ticket_bulk_*.php`, `ticket_saved_view_*.php`).
- `agent/ticket_attachment_upload.php` answers a plain GET with 200, no error. `agent/sse_ticket_stream.php` connects and holds the stream open (the 3.5 s probe timed out while streaming, which is the expected behaviour). I did not upload a file or watch live events.
- `agent/ticket_kanban.php` is **not a page**. It is an include that prints "Direct file access is not allowed" when opened directly (screenshot: `evidence/admin__agent_ticket_kanban_php.png`). Nothing in the app links to it. This is not a defect.
- Portal (Grace): `tickets`, `ticket_add`, `ticket_view_all`, `ticket.php?id=5` all load clean. `client/sse_ticket_stream.php?ticket_id=248` returns 404 (that ticket is not hers, so that is correct).
- **F1 (High):** as Sophie (non-primary staff), `client/ticket_view_all.php` signs the user out. See F1 below.

### Admin
**F5 (High, install/upgrade): fresh install fails at DB migration 2.6.184 and 2.6.186.**
- Where: `admin/database_updates.php` around lines 11632-11636 (2.6.184) and 11673 (2.6.186). Role: found while building the demo as the CLI (`scripts/update_cli.php --update_db`).
- What: `ADD COLUMN config_notify_enabled_types`, `config_update_notify_last_version`, `config_update_notify_last_at`, `user_config_notify_types`, `config_mail_template_header_html`, `config_mail_template_footer_html` fail with "Duplicate column name", because `db.sql` already creates those columns while the version marker in a fresh install is still older.
- Evidence: PHP fatal `Duplicate column name 'config_notify_enabled_types' in admin/database_updates.php:11632`; the update stopped at DB 2.6.183 until the columns were dropped by hand.
- Impact: a new install built from the shipped baseline, and the demo build script, cannot finish. It does not affect an existing install that is already past these versions. The same bug was found and fixed on the unmerged `qa-ux-20261021` branch, so it is still open on `beta`.

**F2 (Medium): `admin/modules.php` "New Module" and every module "Edit" button open nothing.**
- The page links to `/admin/modals/module/module_add.php`, `/admin/modals/module/module_edit.php?id=N` and `/admin/modals/modules/module_edit.php?id=N`. All return **404** (16 trigger URLs checked, every one 404). The modal never opens.
- Same defect was found on the `qa-ux-20261021` branch; still open on `beta`.

**F3 (Medium): `admin/project_template_details.php` and `admin/onboarding_template_details.php` load their script from a wrong relative path, and their breadcrumb link is dead.**
- `<script src="js/pretty_content.js">` is relative to `/admin/`, so the browser asks for `/admin/js/pretty_content.js`. The real file is `/js/pretty_content.js`. In this demo the missing file is answered with a redirect that loops (`net::ERR_TOO_MANY_REDIRECTS` on `/admin/js/users.php`); on a real server it is probably a plain 404, so the script that formats the content never loads. The loop itself may be specific to the demo router, so I only claim the wrong path.
- The breadcrumb "Admin" link goes to `admin_user.php`, which does not exist (404). Seen on both pages (`project_template_details.php:57`, `onboarding_template_details.php:45`).
- Role: admin. Page URLs: `/admin/project_template_details.php?project_template_id=1`, `/admin/onboarding_template_details.php?project_template_id=1`.

**F7 (Low): opening a template detail page without its id gives HTTP 500 or a PHP warning.** All need a malformed URL (no id), so users would not normally see it:
- `admin/document_template_details.php` with no `document_template_id`: HTTP 500 (undefined variable at line 18 produces an SQL syntax error `... LIMIT 1`). There is no demo document template, so the page was only ever loaded without a row.
- `admin/worksheet_template_details.php:5` and `admin/employee_workflow_template_details.php:6`: "Undefined array key id" warning (page still renders).

**Other admin results:**
- `admin/compliance_report.php` has no app shell on purpose (it is a standalone, printable report). It renders correctly (`evidence/admin__admin_compliance_report_php.png`). Not a defect.
- `admin/ticket_status.php` (status names) and `admin/modules.php` (module names) and the avatar link in `admin/index.php` / `users.php` use `href="#"` and do nothing when clicked (Low, cosmetic).
- Every other admin page (including all `settings_*` pages that were not skipped, and every `?tab=` variant) loaded with HTTP 200, no PHP message, no console error and no failed request. `NO-BOOTSTRAP-JS` was flagged only for the standalone report page above.
- Role boundary: technician and Training L2 got a **clean 403 page** on 92 of the 97 admin pages tested, with the app shell intact and the message "You don't have access to this page. Administration is for administrators only." (`evidence/tech_403_users.php.png`). No admin content was shown. The other 5 are alias pages that redirect to another settings/users page (for example `settings_ai.php#providers`, `users.php`, `settings_training.php#kiosk`); I did not follow those redirects to see the final 403 for the technician. Portal user and anonymous requests are redirected to `/login.php` on the 92 (and to the same 5 alias targets).

### Agent
**F4 (Medium): the full page `agent/vendor_details.php?client_id=…&vendor_id=…` returns HTTP 500 for every vendor.**
- Role: admin (the technician role also gets 500 where it has access). Evidence: `PHP Fatal error: Uncaught mysqli_sql_exception: Table 'qa_sweep_demo.vendor_contacts' doesn't exist in agent/vendor_details.php:52`; console `500` on the page request.
- `database_updates.php` creates `vendor_contacts` around line 2235 and a later step drops it (line 2273), but `vendor_details.php:52` still queries it. I found **no link in the UI that opens this page**: the vendor list, domains, software and document pages all open the vendor-detail **modal** (`modals/vendor/vendor_details.php`) instead, and that modal loads for admin. So the 500 is only reached by a typed or bookmarked URL, which is why it is Medium, not High. (For the technician, that modal returns 403 on `agent/domains.php`: clicking a registrar or host name does nothing useful, see the Technician note below.)
- The same defect was found on the `qa-ux-20261021` branch; still open on `beta`.

**F6 (Medium): two internal links point to files that do not exist (404).**
- `agent/client_details.php` is linked from `agent/alerts.php:399`, `agent/rmm_alerts.php:229`, `agent/rmm_asset.php:97`, `agent/rmm_script_run.php:52` (the department name in an alert / device row).
- `agent/client_assets.php` is linked from `agent/asset_details.php:176` (the tag badge on an asset page).
- Role: admin and technician. Found by the static link check (neither file exists in `agent/`).

**F8 (Low): modals that emit PHP warnings when opened (hidden by `display_errors` off, visible in the server log).**
- `agent/modals/recurring_ticket/recurring_ticket_add.php:281` undefined `$current_type_select` (every time "New Recurring Ticket" opens).
- `agent/modals/rack/rack_device_add.php:80-81` undefined `$rack_units`.
- `agent/modals/asset/asset_add.php:7` undefined array key `type`.
- `agent/modals/expense/expense_export.php:35,59,83` undefined `$account_filter`, `$vendor_filter`, `$category_filter`.
- `agent/modals/contact/contact_details.php:19-52`: about 30 "array offset on null" warnings when the modal is opened from the contact page trigger (the modal shows 701 characters of content, so it appears to render).
- `agent/accounts.php:103` undefined `$config_stripe_account` on **every** load of that page when Stripe is not configured.
- Also from the log: `agent/worksheet.php:13` and `agent/kb_article.php:54` (undefined array key, only without an id).

**F9 (Low): large modals taller than the viewport with no internal scroll.** At 1920x1080 the "Save current view" modal (ticket list) is about 1650 px tall, the contact modal about 1454 px, and "New Contract" about 1089 px. The page itself scrolls, nothing is cut off, but the dialog extends past the screen.

**F10 (Low): `agent/training_quiz.php` logs a 404 console error** when a lesson has no quiz (it calls `training_ajax.php?action=quiz_get&lesson_id=1`). Seen as admin, Training L2 and L3. Also `agent/training_awards.php` (L2) logs the same 404 for `action=award_list` when there is nothing to list. The page still renders.

**Expected/by design, not defects:**
- RMM pages (about 28) return a clean 403 with the shell intact because the RMM module is switched off in this install, for admin as well as technician.
- Technician gets a clean 403 on pages outside the Technician role (including the vendor-detail modal on `agent/domains.php`; clicking a registrar/host name there shows a 403, a minor dead end; accounts, expenses, revenues, payments, recurring expenses, backups, alerts, network/firewalls, most KB pages, `kb_embed`). Note: Technician has no KB permission in this seed data, so the whole knowledge base is closed to it. That is a data choice of this demo, not necessarily a product defect.
- Bare-URL probes of endpoints that need parameters (`kb_embed`, `kb_media`, `training_media`, `training_evidence`, `training_pdf`, `training_certificate`, `training_ajax`, `rmm_job_output`, `rmm_software_report_csv`) return 400/404/405, with no PHP message. `agent/calendar_feed.php` returns 401 without a token.
- With a real `?id=` the pages `certificate_details`, `change_details`, `domain_details`, `problem_details`, `kb_article` (admin), `webhook_edit`, `worksheet_template_details` all load clean. `agent/kb_embed.php?id=1` and the client one return 404 for id 1 (they take an embed id, so id 1 does not exist; not a defect).
- Training L2 and L3: all 36 training pages loaded for both, with only the quiz/award 404s above. L3 clicked 16 tabs, L2 3 tabs; no errors. I did **not** compare what L2 and L3 see on the course editor (for example the new Certification card), so that visibility rule is untested.

### Client (portal)
**F1 (High): regular portal staff are silently signed out by 15 pages.**
- Role: Sophie Tran (department staff, not the primary contact, not a technical contact). Opening any of these pages sends her to `/login.php` and destroys her session, with no message: `allowance`, `certificates`, `contact_add`, `contact_edit`, `contacts`, `contracts`, `document`, `documents`, `domains`, `invoices`, `quotes`, `recurring_invoices`, `saved_payment_methods`, `ticket_view_all`, `unpaid_invoices`.
- Cause: `client/allowance.php:17-20` (and the same pattern on the others) does `header("Location: post.php?logout")` when the contact is neither primary nor technical, i.e. the permission check is implemented as "log out".
- Re-verified by hand with a fresh login per page (the first automated pass was disturbed by the sign-out and by login throttling, so only the hand-checked list above is claimed). `assets`, `tickets`, `training`, `profile`, `index` kept the session; `compliance` and `kb_article` redirect politely to Home / the article list.
- The Sophie navigation bar shows only Home, Tickets, Request service, Assets (and the KB, Training, Profile links), none of the 15 above, so this is reached by a bookmark, a link in an email, or an in-page link I did not trace. I did not search every portal page for links that lead to them.
- Even the primary contact (Grace) is signed out when opening `contact_edit.php?id=1` and `document.php?id=1` (records outside her own department). Same fix area.
- The same defect was found on the `qa-ux-20261021` branch ("fifteen portal pages signed the contact out"); still open on `beta`.

**F11 (Low): Content-Security-Policy `default-src 'self'` on 24 portal pages blocks inline styles.** Console shows "Applying inline style violates the following Content Security Policy directive". Seen on `allowance.php`, `assets.php`, `kb_article.php`. The three pages checked by eye looked normal (`evidence/portal2_allowance.png`, `portal2_csp_assets.php.png`), so this only affects the few inline `style=` attributes (4 on `assets.php`).

**Other portal results (Grace):** `index`, `tickets`, `ticket_add`, `ticket_view_all`, `assets`, `documents`, `domains`, `contracts`, `invoices`, `quotes`, `kb_articles`, `kb_article`, `training`, `training_manage`, `service_catalog`, `profile`, `my_approvals` and the rest loaded with HTTP 200 and no PHP message. The pages that depend on data that is not in this demo (invoices, quotes, contracts) were seen empty only. `kb_embed`, `kb_media`, `kb_progress` give 404/400 for a bare URL (take parameters). `client/portal_theme.css.php` returns an empty 200 (a stylesheet; harmless).
- Role boundary: a portal user is redirected to `/login.php` on 92 of 97 admin pages (5 alias redirects) and 145 of 151 agent pages. The other 6 agent results were redirects to other pages, `agent/calendar_feed.php` 401, one 404 and `ticket_kanban.php` "Direct file access is not allowed". **No data was shown to the wrong role.** A technician hitting a portal page is redirected to login on all 33 of 34; the 34th is the empty stylesheet above.
- Anonymous requests behave the same as the portal user on every page.

---

## Summary
- **Pages that are actually broken or block a workflow (High):** F1 portal sign-out (15 pages, regular staff), F5 fresh install/upgrade fails at DB 2.6.184/2.6.186.
- **Medium:** F2 dead New/Edit on `admin/modules.php`, F3 template detail pages (wrong script path and dead breadcrumb), F4 `vendor_details.php` 500 (page not linked from the UI), F6 two dead internal links (`client_details.php`, `client_assets.php`).
- **Low / cosmetic:** F7 missing-id 500s and warnings, F8 PHP warnings in six modals plus `accounts.php`, F9 tall modals, F10 quiz/award 404s, F11 CSP inline-style errors, decorative `href="#"` links.
- **No** blank screens, no missing-footer / scripts-never-loaded pages (every normal page has bootstrap and jQuery loaded), no horizontal scroll, no JavaScript exceptions, and no role-boundary leaks were found on pages that were loaded.
- Not covered: everything under "Skipped", anything that needs data this demo lacks, the live mwa / beta servers, and the nginx/FPM layer. Several automated flags (RMM and technician 403s, bare-URL 404s, the kanban include, the compliance report) were checked and judged not defects; they are listed above so they are not re-raised.

## Files
- Scripts: `common.py` (setup, skip list), `build_targets.py`, `build_id_targets.py`, `sweep.py` (page loads, tabs, modals, links), `modals.py`, `deadlinks.py`, `boundary.py`, `analyze.py`, `portal_signout_check.py`. Run order is in the commit message; they need the scratch demo on port 8191 (`QA_BASE`, `QA_APP`, `QA_DB` can override).
- Raw results: `out/*.json`. Screenshots kept: `evidence/`.
