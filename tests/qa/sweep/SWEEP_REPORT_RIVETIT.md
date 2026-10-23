# RivetIT read-only broken-page sweep (admin / agent / client)

**Run date:** 2026-10-22. The sweep itself was report-only. A fix pass followed (next section); the findings below are the original sweep text, each with its fix status in the table.

## Fix pass (branch `qa-sweep-fixes-20261022`, based on `qa-ux-20261021`)
Not merged, not pushed. Same setup as the sweep: fresh scratch DB, server on `127.0.0.1:8191`, demo data. All sweep scripts were re-run on this branch for every role (admin, technician, Training L2/L3, portal staff and primary contact, plus the `?id=` pass, the modal pass and the boundary probe). "Base" below is `qa-ux-20261021` as it was before this pass; "after" is this branch.

| Measure | Base | After |
|---|---|---|
| Flagged page variants (automated, all roles; most remaining are expected 403s/404s listed in the sweep) | 141 | 131 |
| Rendered pages flagged for a dead internal link (F3 breadcrumb, F6, vendor breadcrumb) | 7 | 0 |
| Console / failed-request errors from the template detail pages (F3) | 2 pages | 0 |
| PHP warning/notice/fatal lines in the server log over the same sweep | 97 | 0 |
| Portal staff (Sophie) signed out by a page, out of 35 portal pages | 0 | 0 |
| Modal URLs that fail (admin pages as admin / agent pages as admin / as technician) | 0 / 1* / 5** | 0 / 1* / 5** |
| Role-boundary results that changed (technician, L2, portal, anonymous) | | 0 |

\* a navigation race in the harness on `agent/index.php`, not a product error. \** four are the vendor modal answering a clean 403 to the technician (that role has no vendor permission in this demo) and the same race.

`php -l` (and `node --check` for the JS) was run on every touched file. No regressions: the after-run diff against base only removes lines (nothing new is flagged anywhere).

| ID | Status | What was done / why not |
|---|---|---|
| F1 portal sign-out | **Fixed on `qa-ux`, verified here** | The 15 pages now send regular staff Home with a message; 0 of 35 portal pages sign Sophie out. **Not changed, on purpose:** opening a record that is not the contact's own or no longer exists (`client/contact_edit.php:52`, `client/document.php:56`, the ticket pages and the POST handlers) still logs the user out. That is the original ITFlow "someone is probing ids" reaction, so I treated it as deliberate. Needs an owner decision if a polite message is wanted. |
| F2 `admin/modules.php` | **Fixed on `qa-ux`, verified** | The dead New/Edit buttons are gone; 0 failing modal URLs. |
| F3 template detail pages | **Fixed here** | `admin/project_template_details.php`, `admin/onboarding_template_details.php`: script path now `../js/pretty_content.js` (no more redirect loop), breadcrumb "Admin" now goes to `users.php` (same as the other template pages). |
| F4 `vendor_details.php` 500 | **Fixed on `qa-ux`, verified; one more link fixed here** | The page loads for every vendor. Its breadcrumb "Vendors" pointed to a missing `client_vendors.php`; now `vendors.php`. |
| F5 fresh-install migrations | **Fixed on `qa-ux`, verified** | The demo build now migrates 2.6.183 to 2.6.188 with no manual column drops. **Second pass, fixed:** `docs/user-guide/tools/seed/85-admin-config.php` no longer stops the demo build when no backup passphrase exists (the two `build_backup` calls are wrapped, a one-line "backups skipped" note is printed and the build goes on). `build-demo.sh` was run end to end on a fresh scratch DB and exits 0; the backup feature itself was not touched. |
| F6 dead links | **Fixed here** | `client_details.php` to `client_overview.php` in `agent/alerts.php`, `agent/rmm_alerts.php`, `agent/rmm_asset.php`, `agent/rmm_script_run.php`; `client_assets.php` to `assets.php?client_id=…&tags[]=<tag id>` in `agent/asset_details.php` and `agent/modals/asset/asset_details.php`. A source scan for the same problem found four more and fixed them: `agent/rmm_asset.php` credential link (to `credentials.php`), `agent/contact_details.php` tag badge (to `contacts.php?…&tags[]=`), `agent/global_search.php` department link in the documents results (to `files.php`), `agent/vendor_details.php` breadcrumb. |
| F7 missing id: 500 / warning | **Fixed** | `agent/worksheet.php`, `agent/kb_article.php`, `admin/worksheet_template_details.php`, `admin/employee_workflow_template_details.php` read the id with `?? 0`. `admin/document_template_details.php` was already fixed on `qa-ux`. `client/document.php` and `client/contact_edit.php` had an inverted guard (`!isset(id) && !intval(id)`, which still warns when id is missing); now `||`. |
| F8 PHP warnings in modals / `accounts.php` | **Fixed** | `recurring_ticket_add.php` (`$current_type_select`), `rack_device_add.php` (`$rack_units` was undefined, so the unit inputs had no maximum; it is now the rack's size), `asset_add.php` (`$_GET['type']`), `expense_export.php` (three filter vars), `contact_details.php` modal (a missing contact now shows "Contact not found." instead of about 30 warnings), `agent/accounts.php:103`. 0 PHP lines in the server log after the re-run. |
| F9 tall modals | **Fixed** | `js/ajax_modal.js`: when a dialog is taller than the window it gets Bootstrap's scrollable layout (header and footer stay, the body scrolls). The existing rules in `css/itflow_custom.css` already cover forms inside the dialog. Checked light and dark at 1920x1080 and 1366x768: "Save Current View" and "New Contract" fit the window with the footer visible and the body scrolling (`evidence/savedview_*`, `newcontract_*`); dialogs that already fit are untouched. |
| F10 quiz/award 404 | **Not changed (deliberate / harness)** | `quiz_get` answering 404 for a lesson with no quiz is the API's "not found" signal: the builder reads it (`agent/js/training_quiz_builder.js` shows the "no quiz" state), and the same API serves the mobile app. The `award_list` 404 on L2 came from my test ids (contact 1 is outside L2's department scope), not from the page. |
| F11 portal CSP inline styles | **Fixed** | `client/allowance.php` and `client/assets.php` fixed in the first pass. **Second pass:** `client/kb_article.php` article bodies (user-authored HTML kept by HTMLPurifier) no longer send inline `style=""` to a CSP that forbids it. New `includes/kb_portal_styles.php` runs after purification and `MediaUrlRewriter`: `text-align`, bold, italic, underline, strike-through and indents become utility classes (`.kb-ta-center`, `.kb-bold`, ... in `css/itflow_custom.css`) and every other `style` attribute is dropped. The CSP is unchanged (no `style-src` relaxation). `kb_portal_csp_check.py` (7 assertions: no `style=` left, no CSP console violation, classes present, computed `text-align` is applied, text intact) passes, and the sweep no longer flags `client/kb_article.php`. |
| Decorative `href="#"` links | **Fixed where it was a real dead link** | `admin/ticket_status.php`: the five built-in status names are plain text now; editable ones stay links. `admin/modules.php` was fixed on `qa-ux`. The user-avatar anchor in `admin/users.php`/`admin/index.php` is only a tooltip holder and was left. |
| 5 alias admin pages (technician) | **Followed, clean** | `ai_provider`, `index`, `settings_rmm`, `settings_training_compliance`, `settings_training_kiosk` redirect to their target, and the technician and Training L2 both end on the clean 403 page with the app shell ("You don't have access to this page"). The portal user ends on the login page. No admin content shown. (`out_base/alias_follow.json`.) |

**Seen while checking, not in the sweep:** on `admin/project_template_details.php` the "Add Ticket Template" button sat against the left edge of its card and there were two breadcrumb rows (`evidence/projtemplate_1366_dark.png`). **Fixed in the second pass** (button column wraps with a top margin below xl; the shell back-crumb now hides the legacy row).

**Files touched in this pass (28):** admin: `employee_workflow_template_details`, `onboarding_template_details`, `project_template_details`, `ticket_status`, `worksheet_template_details`. agent: `accounts`, `alerts`, `asset_details`, `contact_details`, `global_search`, `kb_article`, `rmm_alerts`, `rmm_asset`, `rmm_script_run`, `vendor_details`, `worksheet`, modals `asset/asset_add`, `asset/asset_details`, `contact/contact_details`, `expense/expense_export`, `rack/rack_device_add`, `recurring_ticket/recurring_ticket_add`. client: `allowance`, `assets`, `contact_edit`, `document`. `css/itflow_portal.css`, `js/ajax_modal.js`. No schema change, no `vendor/` edit.

Raw before/after results: `out_base/` and `out_after/` (JSON and analysis text). Re-run with `env_up.sh`, `run_all.sh <dir>`, `analyze.py`, `alias_follow.py`, `ui_check.py`.

---

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

---

# Design review (visual, light + dark, 3 widths)

Ran on the local scratch demo (fictional data, local `beta` code plus the fixes above), **not** mwa. Method: every page in the sweep was screenshotted
full-page as admin, technician and portal user in light and dark at 1920x1080, 1366x768 and 768x1024 (tab views and every modal that opens too),
DOM probes ran on each page (font size < 12px, overflow past parent/viewport, overlapping controls, button height mismatch, truncated text without
tooltip, low-res images, raw keys/`undefined`, light surfaces in dark mode, empty vertical bands, date/number formats), and a pixel-based contrast
probe ran on every page. Contact sheets were then opened **one by one with the image viewer** and a verdict written per page/modal
(`results/design_review_log.tsv`, 879 lines, 70 distinct finding IDs DD1-DD74). The first batch of admin sheets was re-viewed after the image
viewer returned blanks for the first read; those lines are marked "re-viewed" in the log.

## Screenshots reviewed
| Role | Theme | 1920x1080 | 1366x768 | 768x1024 |
|---|---|---|---|---|
| Admin (252 pages/variants, +37 tab views at 1920) | light | 289 | 252 (sheets of 3) | 252 (sheets of 3) |
| Admin | dark | 289 | 252 (probes + sampled sheets) | 252 (probes) |
| Technician (152 pages) | light / dark | 152 / 152 | 152 / 152 (hash-identical to the admin render on 58, clean 403 on 42: verdict inherited, the other 52 viewed) | 152 / 152 (same) |
| Portal (37 pages) | light / dark | 37 / 37 | 37 / 37 | 37 / 37 |
| Ajax modals (464 distinct modal URLs) | light | 464 | 464 captured | - |
| Ajax modals | dark | 447 | - | - |

2,718 page screenshots + 911 modal screenshots captured. **Honest limits:** dark 1366/768 views of admin and the technician/portal dark views at 1366 and 768 were reviewed through
the DOM/contrast probes and the shared templates rather than every sheet; the 768 contact sheets of the technician were reviewed through the
admin render (same template). A few full-page captures show the fixed sidebar/sticky bar repeated mid-page (capture artefact, logged as such).

## Design defects (ID | severity | page | viewport+theme | rule | before | status | after | files)
Before/after pairs are in the scratch dir (`tests/qa/sweep/shots/`, left = before, right = after) and reproduced by `verify_design_fixes.py` (it asserts the computed fact).

| ID | Sev | Page | Viewport/theme | Rule | Before | Status | After | Files |
|---|---|---|---|---|---|---|---|---|
| DD35 | High | agent/tickets, ticket list | all | raw entities shown | category pill `Access &amp; Accounts` (double-escaped) | **fixed** | `Access & Accounts` | agent/ticket_list.php |
| DD-x1 | High | agent/ticket_list | all | raw entities (found by the typing test) | assignee name with `&"'` shown `A&amp;B &quot;q&quot;` | **fixed** | shown once-escaped; confirmed no XSS (see typing section) | agent/ticket_list.php, agent/ticket.php |
| DD67 | High | expense bulk delete modal | all | broken control | two stacked confirm blocks; "Yes, Delete!" submitted nothing (no `name`) so bulk delete could not work from it | **fixed** | single header/body/footer, Delete button carries the handler name | agent/modals/expense/expense_bulk_delete.php |
| DD26 | High | agent/document_details | light | contrast | document title dark text on dark navy band (~1.2:1) | **fixed** | title inherits white | css/itflow_custom.css |
| DD34 | High | agent/racks | light | contrast | rack table head dark text on dark band | **fixed** | white head text | css/itflow_custom.css |
| DD61 | High | agent/training_records | 768 | layout | person names one letter per line | **fixed** | name cell min 9rem | css/itflow_training_ops.css |
| DD15 | Med | all switch pages | light/dark | invisible state | unchecked switch/checkbox white on white / near-invisible | **fixed** | visible border + track | css/itflow_custom.css |
| DD17 | Med | every TinyMCE editor | dark | dark surface stays light | white editor in dark theme | **fixed** | oxide-dark skin via one wrapper | js/tinymce_theme.js, includes/footer.php, client/includes/footer.php |
| DD27/72 | Med | every `.btn-light/.btn-default` | dark | consistency/contrast | white Cancel/Close slab outweighs the primary | **fixed** | surface-token button | css/itflow_custom.css |
| DD73 | Med | merge/transfer modals | dark | contrast | `.alert-dark` text nearly invisible | **fixed** | readable | css/itflow_custom.css |
| DD1 | Med | ~12 admin pages | all | duplicate chrome | two breadcrumb rows stacked | **fixed** | legacy trail hidden when shell back-crumb exists | css/itflow_custom.css |
| DD68 | Med | 14 admin modals (New User, Edit user, New Role, tags, rules...) | all | inconsistency | white header while siblings are dark navy | **fixed** | same dark header and white close | admin/modals/** (14 files) |
| DD66 | Med | 17 Export CSV modals | all | empty band | empty white body between header and footer | **fixed** | one explanatory line | agent/modals/*/*_export.php |
| DD69/51 | Med | SLA calendar add, payment provider add, sla_policies | all | typography | alert split into flex fragments, stray "." on its own line | **fixed** | alerts render as normal text | css/itflow_custom.css |
| DD5 | Med | ~25 list pages | all | wrong feedback | "No results match your filters." with no filter applied | **fixed** | "Nothing here yet." when no filter | includes/filter_footer.php |
| DD29 | Med | agent invoice/quote/recurring_invoice without id | all | blank page | header only, nothing | **fixed** | "No invoice selected" | agent/invoice.php, quote.php, recurring_invoice.php |
| DD45 | Med | portal saved payment methods, ticket not found | all | raw text | unstyled "Stripe payment error..." / "Ticket ID not found!" | **fixed** | alert with next step | client/saved_payment_methods.php, client/ticket.php |
| DD46/65 | Med | portal training | 768 | collision | title under icon colliding with 4-button toolbar | **fixed** | wraps, title on its own row | client/training.php |
| DD47/52/54/63 | Med | ~30 pages | 768 | layout | tables clipped without cue; 3/9 and 4/8 detail columns ~110px wide | **fixed** | scroll-shadow cue; columns stack below 1100px | css/itflow_custom.css |
| DD55/60 | Low | KPI tiles (backups, RMM) | 768 | truncation | labels cut ("Warni...") | **fixed** | labels wrap | css/itflow_custom.css |
| DD50 | Low | admin tag | 768 | overlap | type tabs sit on the search box | **fixed** | toolbar wraps | css/itflow_custom.css |
| DD16 | Low | settings_custom_fields | all | raw key | heading `client_assets Fields` | **fixed** | "Asset fields" | admin/settings_custom_fields.php |
| DD4 | Low | admin canned responses | all | text | preview glued lines ("Hello,Your") | **fixed** | spaces kept | admin/canned_responses.php |
| DD32 | Low | agent products | all | text | "NewService" no space | **fixed** | spaced | agent/products.php |
| DD22/36 | Low | webhook platform tiles; training fleet alert | all | truncation / raw path | mid-word cut, no tooltip; repo path as link text | **fixed** | tooltip; code style | admin/webhook_new.php, agent/training_device_fleet.php |
| DD-x2 | High | whole app | all | layout | one 300+ char value made cards/tables thousands of px wide (page scrolled sideways); 30+ custom links pushed the user menu off the bar | **fixed** | wraps; icons wrap inside the bar | css/itflow_custom.css |
| DD-x3 | High | admin contract templates list | all | blank data | SLA/rate/hours columns always blank (wrong column names) + 8 warnings per row | **fixed** | real values | admin/contract_template.php |

**Second pass (everything that was listed as "Not fixed" now has a fix, or an exact reason below).** Owner decisions were made with Tabler defaults. `verify_polish_fixes.py` (45 assertions, light/dark, 1920/1366/768) covers each item and passes.
| ID | Fix |
|---|---|
| DD2 api_docs dark | `admin/api_docs.php` passes `?theme=dark` to the embedded reference when the user is in dark; `api/v1/docs.php` then renders Scalar with `darkMode`/`forceDarkModeState: 'dark'`, hides its own toggle and darkens the page header/help strip; the iframe frame follows `--bs-body-bg`. Both the reference body and the panel measure dark. (Light and the public `/api/v1/docs` URL are unchanged.) |
| DD3 audit-trail toolbar | All filter controls and buttons are standard size (no `-sm`), same height as the date inputs. |
| DD6 page-size select | Visible "Rows per page" label, a real `<label for>`, capped width (`includes/filter_footer.php`). |
| DD7 identity-provider forms | Every OIDC text input has an icon like the Entra block. |
| DD8 breadcrumbs | `admin/includes/inc_all_admin.php` gives the ten pages that had none (payment_method, payment_provider, saved_payment_method, tax, payroll_*, kb_articles_archive, ticket_automation_log, settings_mail_templates, people_import, portal_users) the shell back-crumb pattern; exactly one breadcrumb row on each. |
| DD9/13 empty states | `tax`, `payment_provider` use the shared empty state ("Nothing here yet." + next step); `labor_type` shows a row message instead of a header-only table; `campaigns`, `segments` show an empty-state block; `portal_users` and `agent/notifications` no longer print the generic filter empty state on top of their own (`$suppress_filter_empty`). |
| DD14/48 | `project_template_details` button column wraps below xl. |
| DD18 | `settings_notification` Save buttons are one size. |
| DD19 | `settings_telemetry` Installation ID is left-aligned like the content below. |
| DD20 | `ticket_template_details` stacks below xl (the 25% Tasks column overflowed). |
| DD21 | `worksheet_template`, `labor_type` and `agent/contracts` row actions are a kebab menu like every other list (Edit/Delete inside). |
| DD23 | `js/a11y.js` adds a hover tooltip (`title`) from the existing accessible name to every icon-only `.btn`, so sighted users see what screen readers already announced. |
| DD24/25 | `asset_details`: breadcrumb moved above the integration banners; the asset name uses non-breaking hyphens so it wraps between words, not inside `LT-EXEC-01`. |
| DD28/57 | Folder tree count badges stay on the row (flex), no overlap at 768. |
| DD30 | New `includes/kb_excerpt.php`: block ends become spaces, entities decoded once, a leading copy of the title is dropped (agent and portal KB cards). |
| DD33 | Gantt stretches `column_width` to the card width on load/resize. |
| DD37 | `.trq-card:empty { display:none }`: the quiz page for a lesson with no quiz no longer shows blank white shells. |
| DD38 | `vendor_details`: a vendor without contacts shows an empty-state card instead of a blank right pane. |
| DD41 | The Training "no access" card now has a "Back to dashboard" button (the standard 403 offers a way out). |
| DD42/43/44 | Portal: `allowance`/`contracts` no longer wrap their card in an extra `container-fluid mt-3` (cards line up with the hero); dates on `documents`, `contracts`, `compliance`, `kb_articles`, `kb_article`, `allowance` are ISO like the rest of the portal; `my_approvals` uses the same icon+title header as the other portal pages (no breadcrumb row). |
| DD53 | Wrapped badges (rule/condition/action pills) use a normal radius instead of turning into circles. |
| DD56/59 | Tablet filter rows: selects and the "Needs Follow-up" button get room (`min-width`, wrap), no more "All Accour". |
| DD64 | Ticket list Subject column keeps at least 220px (it was one word per line at 1366); the table scrolls inside its card with the existing cue. |
| DD70/71 | `js/ajax_modal.js`: a bulk modal opened with nothing selected ("Set Status for 0 Assets") disables its submit and says "Nothing is selected"; every form modal now puts the cursor in its first plain field. |
| DD74 | Contract documents modal has one file picker (the leftover `custom-file` markup rendered a second one). |
**Not changed, with the exact reason:** DD10 the module list shows raw module keys because those keys are the identifiers the permission system and the API use; renaming the display would hide the value an admin has to type elsewhere (deliberate). DD62 the Training rule save bar is `position: sticky` with an opaque background and shadow; it covers the rows only while they are scrolled behind it, by design for long forms (not a defect). DD56 the CSAT status pill ("Needs follow-up") is partly outside the card at 768 only inside the horizontally scrolling table, which now has the scroll-shadow cue. Kanban/Gantt columns overflow at 768 by design (they scroll). DD11 withdrawn (not reproduced). The portal's logout when opening an unowned record and the F10 404 stay as they were (deliberate).
Verified **false positives** from the automated probes: tiny `th`/badge text at 11.5px (design token, 654 hits), "light surfaces in dark" for TinyMCE (now fixed), compliance report print page (white by design).

## Design proposals (not built)
P-D1 breadcrumbs on every admin page (done for the ten pages that lacked one); P-D2 one empty-state component (icon, sentence, next step) used by all lists and header-only cards; P-D3 one modal-footer pattern with a disabled primary until a selection exists for bulk modals; P-D4 consistent date format (user setting); P-D5 tablet-first layouts for the 15 densest pages (asset, contact, project, vendor details); P-D6 visible labels or tooltips for icon-only row actions; P-D7 dark-theme visual regression test in CI (screenshot diff of the 40 key pages); (P-D8, a migration for `campaigns`/`segments`, was withdrawn: those pages read `crm_campaigns`/`crm_segments`, which exist in `db.sql` and in the 2.6.30 migration; see I11.)

---

# Input and keyboard testing

All typed with real keystrokes (`page.keyboard.type`, Tab/Esc/arrows, double-click, back/forward), on the scratch demo only; destructive controls only on `QA-UI-` records; Integrations/Odoo/mail/backup/update forms skipped.
Inventory (`forms_enum.py`): 258 page-level POST forms (1,668 fields) and 246 distinct modal forms (2,355 fields). The matrix tested the **safe subset after filtering dangerous
paths and repeated per-record variants: 143 forms (123 modals, 20 page forms), 303 fields, 2,080 input cases** (+424 harness exceptions from navigation races, not counted as results).

## Matrix (form x input class -> result), counts of cases accepted | rejected
| Class | text | textarea | number | email/url/tel |
|---|---|---|---|---|
| empty | 5+ accepted where optional / required rejected with the browser bubble | same | rejected when required | rejected when required |
| whitespace-only | 78 | 108 | accepted→ **guarded server-side** | |
| 1 char / 300 / 5,000 chars | all accepted or cleanly rejected (maxlength); 5,000 into a short column was a **500** (fixed) | accepted | | |
| unicode/emoji/RTL, accents | 91 accepted, 0 corrupted on reload | | | |
| quotes `' " \` \\` | accepted, rendered once-escaped | | | |
| `<script>`, `<img onerror>`, HTML | 157 accepted, **0 executed, 0 live markup** | | | |
| `&amp;` / `&lt;` literals | shown literally as typed (correct) | | | |
| SQL metacharacters | 91 accepted, no error, stored inert | | | |
| leading/trailing spaces | accepted and trimmed | | | |
| negative / zero / decimal / `abc` / `1e309` / huge | | | 31 neg, 31 huge: huge → **500 (fixed)** | invalid e-mail rejected; `javascript:` URL accepted as text (not rendered as link) |
Full per-class numbers: `python3 input_matrix_report.py`.

## Defects found by typing/keyboard and their status
| ID | Sev | Found by | Defect | Status | Files |
|---|---|---|---|---|---|
| I1 | High | typing 99999999999999999999 in numbers | blank HTTP 500 on about ten handlers (ticket status order, task estimate, SLA policy, labor rate, custom link order, contract value, service catalog sort...) | **fixed once** in the shared post guard: readable "number too large" message | includes/post_guard.php |
| I2 | High | 5,000 chars in Problem title | HTTP 500 (core adapter wraps the DB error so the existing "too long" guard missed it) | **fixed** (guard now looks through wrapped exceptions) | includes/post_guard.php |
| I3 | Med | whitespace in required Description | service and contract-template descriptions saved as blank (the guard only covered name/subject/title) | **fixed** + helper `requireFilledPost()`; 16 other automated "accepted" flags were false positives (checked: company name, prefixes unchanged) | includes/post_guard.php, agent/post/service.php, admin/post/contract_template.php |
| I4 | Med | keyboard: Tab out of a modal then Esc | Esc did nothing when focus was on the page body | **fixed** | js/ajax_modal.js |
| I5 | High | stored-XSS typing test | assignee name double-escaped in ticket list | **fixed** | agent/ticket_list.php |
| I6 | Med | server log during matrix | 8 "Undefined array key" warnings per row on contract templates (blank columns) | **fixed** (DD-x3) | admin/contract_template.php |
| I7 | Low | server log | warnings on unchecked checkboxes/missing keys: asset interface, asset, document, domain, ticket project, ticket-create log line used an undefined variable | **fixed** | agent/post/*.php (6 files) |
| I8 | Low | server log | `admin/post.php` included a non-existent `post/template_library.php` on every post from that page | **fixed** (include only if file exists) | admin/post.php |
| I9 | High | data growth from the matrix | 34 custom links pushed the user menu off the bar (sideways page scroll) | **fixed** | css/itflow_custom.css |
| I10 | High | 300/5,000-char values | one long value widened tables/cards past the viewport | **fixed** | css/itflow_custom.css |
| I11 | Med | server log | `agent/campaigns.php` and `agent/segments.php` "read tables that do not exist" | **retracted, no defect**: I looked for `campaigns`/`segments`; the pages read `crm_campaigns`, `crm_segments` and `crm_campaign_recipients`, which `db.sql` creates and the 2.6.30 migration (`admin/database_updates.php`) creates with `CREATE TABLE IF NOT EXISTS` (columns match what the pages read). Both pages load with no warning on the fresh install and on the demo that was upgraded 2.6.183 to 2.6.188. No migration and no DB version bump were needed (a new migration would also collide with the `2.6.189` already used on `beta`). The only real gap was the header-only empty card, fixed under DD9. | agent/campaigns.php, agent/segments.php |
| I12 | High | typing, Template Library page | the "New" buttons on `admin/template_library.php` (ticket, canned response, worksheet, contract, project, document, onboarding, vendor, license templates) open the real add modals, but `admin/post.php` picks its handler from the Referer (`template_library.php`) and no such handler existed: Create ended on a blank `post.php` page and nothing was saved | **fixed**: `admin/post/template_library.php` loads the template handlers (each reacts only to its own button). Verified: before, 0 rows and a blank page; after, row created and flash shown (`verify_polish_fixes.py`) | admin/post/template_library.php |
| I13 | Med | typing whitespace in required fields, checked on the database | blank values were stored for: custom link URL, campaign subject, ticket-template task name, onboarding checklist task name, worksheet template name (add/edit), service description on edit | **fixed** with `requireFilledPost()`; `whitespace_required_check.py` (11 assertions, reads the DB) passes. Fields where a blank is valid or defaulted are deliberate: number prefixes, hourly rate (0), campaign/segment name ("Untitled ...") | admin/post/custom_link.php, agent/post/campaign.php, admin/post/ticket_template.php, onboarding_template.php, worksheet_template.php, agent/post/service.php |
| I14 | Low | server log | `agent/vendor_details.php` read leftover `$contact_*` variables (5 warnings per load, and the vendor's own email/phone/description were never shown) | **fixed**: uses the vendor's own fields | agent/vendor_details.php |

Not defects (checked): Tab leaving the last control of a modal goes to the browser chrome (normal Bootstrap behaviour, nothing on the page behind receives focus); `javascript:` typed into a URL field is stored as text and never rendered as a link; literal `&amp;` typed by a user is displayed literally.

## Keyboard/interaction results (`keyboard_tests.py`, 10 modals, all assert)
Focus moves into the modal on open; Tab never lands on the page behind; every focused control shows a visible ring (TomSelect included); Esc closes (now also after Tabbing out);
focus returns to the trigger; the dialog fits 900 and 400 px wide while open and at 200% text size with the submit button reachable; double-click on Create saves **one** record;
back/forward after submit does not resubmit; ArrowRight moves between tabs and the tab strip has `role=tablist`. Not tested: browser min-font-size settings, screen-reader output, datepicker popup typing, TinyMCE keyboard shortcuts.

## Stored-XSS check requested for agent/ticket.php and agent/ticket_list.php
A department/ticket category and a user name containing `<script>window.__xss=1</script><img src=x onerror=...>A&B "q" 's' é日` were typed with the keyboard, saved, attached to ticket 248 and
rendered on the ticket list, ticket detail, department tickets, dashboard and user list. Result (`xss_store_test.py`, 18 assertions, all pass): **no script executed, no live markup,
values shown exactly once-escaped.** The two security-review flags are **false positives**: those values are encoded once upstream (`nullable_htmlentities`), so the extra `htmlspecialchars`
produced the double escaping visible in DD35/DD-x1. The raw board/group labels that are not pre-encoded still go through `htmlspecialchars`.

## What was not tested
Android; mwa/beta servers; the dangerous/integration forms; ~110 modal forms that are per-record repeats (same template as a tested one); the in-modal TinyMCE/AI features; file upload content types; the full matrix after the very last fixes (the failing cases were re-run by `verify_input_fixes.py`).

---

# Third pass: user-reported defects (queued by the coordinator)
Each has an asserting script in this folder (light and dark, 1920x1080 and 1366x768 unless noted). Before/after screenshots are in `shots/` (downscaled).

| Item | Cause / reading | Fix | Check |
|---|---|---|---|
| Org chart | the d3 toggle is centred on the card's bottom edge (half over the title line); long titles clipped at one line; the count badge was a bare number; the chart was not refitted after render/expand | toggle moved just under the card (`nodeButtonY`), wider child spacing; titles wrap to two lines and every card has a full-text tooltip; count badge is a labelled pill ("4 direct reports"); toggles have `role=button`, `aria-expanded`, accessible names and Enter/Space; `fit()` on open, after Expand all and Collapse all. `js/vendor/org-chart` is untouched | `orgchart_check.py` (24 assertions: no toggle/title overlap, labels, Fit keeps every node in the frame, controls, search, keyboard). Only the 23-person demo chart was available; 1 / 2 / 50+ node charts were not generated |
| TomSelect (New Ticket etc.) | shared init gave every select a typing caret and listed the empty "- Choose a Template -" row; Asset / Location / Vendor on the Assignment tab started with zero options and no explanation until a department was chosen (they populate via ajax after that) | `js/app.js`: short single selects have no input (click opens, arrows/Enter work); long lists keep search inside the open list (`dropdown_input`); placeholder rows are not listed again (value `0` placeholders are hidden, still submit); `clear_button` on optional ones; chevron + neutral focus ring (`css/itflow_custom.css` 17). `tickets_add_modal.js`: the three dependent selects are disabled with "Select a department first", fill and enable when a department is chosen, and disable again when cleared | `ts_dropdown_check.py` (30 assertions, includes recurring-ticket modal and portal form). Other bespoke TomSelect configs (contact search in `tickets_add_modal.js`, `asset_edit_modal.js`, `share_modal.js`, `asset_inline_edit.js`, `payroll_job_hours_add.php`) are intentionally different (async search / tags) and were left |
| Bulk-select checkbox column | table cells ignore `min-width`, so the header box and the row boxes could sit up to 8px apart; group-header chevron was 8px right of the box; three pages (`files`, `api_keys`, `mail_queue`, `rmm_assets`) used `pe-0` cells instead of the shared class | one 40px column (content width holds it open), same padding in header and rows, vertically centred; chevron on the same centre line; the four pages now use `.checkbox-column` | `checkbox_align_check.py`: 21 list pages, light/dark, 2 sizes, sidebar open/collapsed (centre x within 1px, centred in the row) |
| Ticket list "Board" column | Board is the **top-level parent of the ticket's category** (derived from it; no separate field). Category is the ticket's own category. For a top-level category they are identical | Board column removed from the list; Category pill (category colour) stays. The Board filter, the Boards nav and kanban are untouched. No column chooser, saved view, export or API shows it | `ticket_ui_check.py` |
| Ticket page: reply editor | `min_height` 200 (+autoresize) left a tall empty band; TinyMCE's own blue focus frame | `min_height` 130 (grows to 600); focus frame uses the app primary. **The floating green bubble is not in this codebase** (no fixed-position widget in the app, scratch run found none): it is injected from outside (browser extension or an embed script added in a custom footer), so it cannot be fixed here | `ticket_ui_check.py` |
| Ticket page: Time Entry | tinted card, Start/Reset glued and left-aligned, JS replaced the labels with bare icons | same surface as sibling cards, equal-width Start (primary) / Reset (outline) with a gap, centred; the JS keeps "Start"/"Pause" labels. Behaviour unchanged | `ticket_ui_check.py` |
| Split buttons | caret segment had no divider; menu hover/active filled with the theme colour | shared CSS: divider, focus ring, neutral hover/active, aria-label on the reply caret (25 files use the pattern) | `ticket_ui_check.py` |
| Projects | `.input-group-append` wrappers meant the inner button kept rounded corners and the outer corner of the search bar was inconsistent (every list page); kanban lanes had uneven tops, handles floated outside cards; "In Progress" lane icon is `fa-spinner`, which read as a stuck loader (it was never a load failure) | shared search-bar joins; kanban: equal 280px lanes that scroll sideways, sticky lane header, padded flex cards, handle inside the card, dashed ghost drop target, lane icon changed to an hourglass; project header cards padded, buttons 34px+, New Task 40px | `projects_ui_check.py` (kanban geometry, drag and drop of a QA-UI- task and back, 14 list search bars) |
| MDM "Users to contacts" | contact picker list was an absolutely positioned child of the table cell (stacked under the next row); toolbar buttons mixed styles; explainer text broken into fragments; KPI cards red/amber at 0 | body-level picker (fixed position, flips up near the bottom, 36-44px two-line rows, highlight, "No match", arrows/Enter/Esc, listbox roles); Map disabled until a contact is chosen; one primary action; explainer rewritten as one info alert with an ON/OFF chip and Settings link; table scrolls inside its card; KPI cards green at 0 and fully clickable; subtitle badge. Seeded with `seed_mdm.sql` (fake rows, nothing calls ManageEngine); Map/Ignore/Rescan were **not** run | `mdm_ui_check.py` |

**Queued but not done in this run (each is a feature-sized change, so they stay open):** unified Alerts page + removing the Agent alerts / RMM Alerts duplicate + Agent alerts missing from Dashboard > Alerts; Managed devices sidebar regrouping; Agent alerts page polish; RMM Starter policies, Starter pack, script form, Hypervisors and Software report redesigns (the RMM state file lives under the host's `/etc/rivetit`, so a scratch RMM install needs its own state directory, which I did not set up); Calendar redesign; Training overview / Learning paths / People archive (Odoo-sync safety review) ; inline ticket status change; Assets list sort/filters/status badges. Nothing was started on these, so nothing is half-done.


## Regression results for the final tree (third pass)
| Run | Result |
|---|---|
| `run_all.sh` (admin, technician, Training L2/L3, portal staff and primary contact, `?id=` pass, modal pass, boundary) + `analyze.py` | 132 flagged variants (base 141; all remaining are the expected 403/404/401 notes). Against the previous after-run only two lines changed: `admin/settings_rmm.php` (text "Warning:" in a legitimate settings paragraph, not a PHP message) and `admin/ticket_status.php` (page scrolled sideways because a very long badge value from the typing matrix kept `white-space: nowrap`; **fixed**, rule 24). Boundary: 0 changes. Modals: failures 0 / 1 / 3 (before 0 / 1 / 5). PHP warning/notice/fatal lines in the server log: 0 |
| `run_asserts.sh` (13 asserting scripts) | all exit 0: verify_design_fixes 23, verify_input_fixes 4, xss_store_test 17, kb_portal_csp_check 7, verify_polish_fixes 47, whitespace_required_check 11, keyboard_tests 83, orgchart_check 24, ts_dropdown_check 38, checkbox_align_check 137, projects_ui_check 30, mdm_ui_check 18, ticket_ui_check 24 (`results/asserts_third_pass.txt`) |
| Input matrix (143 forms, 303 fields, 2,080 keystroke cases, 4 shards) + report | `RESULT: PASS`, no hard failures (`results/input_matrix_third_pass.txt`). The first run of this pass found two more real defects, both fixed: `agent/post/quote_model.php` read an absent `quote_discount` (warning on every quote add) and `js/tinymce_ikb.js` threw "tinymce is not defined" when its page was replaced while it loaded |
| `php -l` / `node --check` / `py_compile` / `bash -n` on all 207+ changed files | clean |


---

# RMM pass (branch `qa-sweep-fixes-clean-20261023`)
Scratch RMM install: own scratch DB (`qa_rmm_demo`), own app copy, port 8191. **No `/etc/rivetit` or other system file was touched**: the module's on/off state file lives in `<app>/backups/rmm-state/rmm_state.json` (`RMM_STATE_DIR` default), so the scratch copy has its own; it was switched on there and in the scratch DB (`settings.config_module_enable_rmm`, `endpoint_agent_settings.enabled/features_json`). Seeds (QA-UI-, nothing contacts a device, host, ManageEngine or any service): `seed_rmm_alerts.sql`, `seed_rmm_hv.sql`. **No migration was added** (so no DB version bump; beta is at 2.6.191, this branch's `LATEST_DATABASE_VERSION` is unchanged).

## Shared kit (built first, then every page converted)
`css/itflow_rmm_ui.css` + `includes/rmm_ui_kit.php` (`rmmxHead`, `rmmxKpi`/`rmmxKpiGrid`, toolbar, segmented control, filter chips, table, pills, state dot, sticky action bar, info alert, empty state, `rmmxPlatform`). `rivetRmmUiPill()` and `rivetRmmUiCard()`/`rivetRmmUiThead()` and `rivetRmmAutoHeader()` now emit the kit's classes, so every RMM page that uses them (policies, scripts, schedules, approvals, patching, logs, fleet...) picks up one badge system (same height/radius, soft tint, AA text in light and dark), one card radius and one page header.

| Item | Result |
|---|---|
| (1) scratch RMM install | done as above |
| (2) unified Alerts | **Canonical page: `agent/alerts.php`** (it already merged RMM and backup and has the sidebar badge; the other two were the odd ones). Sources: Endpoint agent, RMM integrations, Backup, Network, one severity scale (critical/error/warning/info; RivetCore `crit`/`warn` map to critical/warning), one state scale (new = RivetCore `open`, acknowledged, resolved, plus a combined "new + acknowledged"), KPI cards that are filters, search, department, device (`asset_id`), grouped by device / check, 50 per page, bulk bar. Row actions go through each source's own handler (the agent source through RivetCore's `ack_alert`/`resolve_alert`, so the meta record and the mirror row both change). Old URLs redirect with their filters: `rmm_alerts.php` (status, severity, client_id, asset_id, q), plain list requests of `rmm_agent_alerts.php` (state, severity, client_id, view, page); one alert's detail page, device and check drill-downs of `rmm_agent_alerts.php` are kept (deep links from e-mails, notifications and tickets use them). In-app links (RMM dashboard, asset panel, client overview, log rules...) point at the new page. |
| (2) "agent alerts missing from Dashboard > Alerts" | **Cause found in code, not reproducible through the normal bridge path.** Endpoint-agent alerts live in two stores: `rmm_alerts` (mirror row under the `rivetit_agent` integration, all the old central page read) and `rmm_alert_meta` (RivetCore's own record). The mirror write is best-effort (a failure is only logged), alerts adopted later (`adoptOpen`) or resolved in the edition's own screens can leave the two disagreeing, and a meta record without a mirror row never reached any page that read `rmm_alerts`. The unified page reads the meta record when there is one, lists meta-only records, counts each alert once, and `alertsCentralReconcileMirror()` moves a stale mirror row forward (new -> acknowledged -> resolved) so dashboard cards and asset panels agree again. Seeded cases: 8 open critical + the orphan, 2 acknowledged, 1 resolved with a stale mirror row; the page, the KPI cards and the sidebar badge agree (`rmm_alerts_check.py`). 5,000 extra alerts: page answers in < 4 s and paginates. I did not have the user's data, so I cannot say which of these three it was for them. |
| (2) sidebar | one **Alerts** item with a badge of open critical + error alerts across every source (tooltip says so); "Agent alerts" and "RMM Alerts" are gone from Managed devices |
| (3) Managed devices regrouping | five collapsible groups (`<details>`, keyboard accessible, state remembered per browser, the group with the current page open): Overview (RMM Dashboard, Agent Fleet), Devices (Assets, Hypervisors, Add device, Network, Mobile devices, Intune), Automation (Policies, Scripts, Starter pack, Schedules, Approvals, Check policies, Maintenance windows, Escalation policies, Log rules), Patching & software (compliance, approvals, patch policies, catalog, assignments, report), Settings & logs (Custom fields, Logs). Same permission and module-off hiding as before; asserted that every page is still in the sidebar. "Training clipped under the logo", a "double scrollbar" and the status-bar URL were **not reproduced** (the nav has exactly one scroller, Training sits below the logo; the URL is the browser's own link preview) |
| (4) Agent alerts polish | folded into the unified page: aligned toolbar, severity as the only strong colour, state as dot + word, message trimmed (full text in a tooltip), relative time with the full timestamp in a tooltip, device/check not wrapped, Ticket link, Acknowledge neutral, Resolve success, bulk selection with a sticky bar, sticky header, empty state naming the active filters |
| (5) Starter policies | Segmented state filter with counts and pressed state, install notice as a readable info alert, groups with counts and collapse, "Pack:" / "Pack add-on:" shown as a small tag (display only), OS shown as icon + name (the old `fa-brands` class drew a broken glyph with this app's icon font), per-row Install / Update / Reset instead of dashes, "Why" as a clear disclosure with a callout, sticky action bar with "Install selected (n)" |
| (6) Starter pack | short intro + "Safe by default" list, KPI cards that are real filters (platform, risk) with matching colours, toolbar with one height, risk toggles, chips, state next to the item, OS icons, checkbox column aligned, bar with Plan (tooltip), Select read-only/all, Clear, Install selected (n), a separated destructive "Uninstall untouched"; room under the bar |
| (7) Script form | Publish / Cancel in a sticky bar (40px), dashed full-width "Add a parameter", empty state, rules as a list (help text no longer overflows), invalid/duplicate parameter names shown under the field, unsaved-changes guard, saving disables the button (existing `data-rmm-once`). No Save-draft exists, none added |
| (8) Hypervisors | KPI cards (hosts, VMs, running, hosts with problems, ignored), toolbar + chips, status pill instead of a tinted row (left stripe for problems, error as a trimmed line with the full text in a tooltip), running ratio with a meter, neutral cluster tag (red only for "no quorum"), relative last report, sortable headers with `aria-sort` (`js/rmm_table_sort.js`), named open-device links, info alert when the feature is off |
| (9)/(10) Deployed against expected / Software report | same kit: 7 clickable KPI tiles (`—` for no data, semantic colours, tooltips), toolbar, table in a card, empty state with "Assign a package" / "Open the catalog". Gutter and radius asserted on 14 RMM pages in light/dark at 1920/1366/768: none starts at x=0 (the flush-left layout was **not reproduced** in the scratch install; the assertion would catch it) |
| Security finding (from review) | the first version of `includes/alerts_central.php` escaped the search text with `mysqli_real_escape_string` and *then* doubled backslashes, so a quote in the search became `\\'` and could break out of the string. **Fixed**: the pattern is built from the raw text (`addcslashes`) and **bound** through a prepared statement; severity/state/source/group are whitelisted, ids cast. `rmm_sqli_check.py` sends `'`, `\'`, `\\'`, `%27`, `' OR 1=1 --`, `');SELECT SLEEP(3)--`, `UNION SELECT`, `%`, `_`, 6,000 characters, NUL, a DROP TABLE in the search, source, status, severity, client_id, asset_id, group, page, sort, order and per_page: 200, no SQL error text, no delay, no table dropped, `%` and `_` literal, `client_id=16 OR 1=1` read as 16. A later edit removed the escaping helper while a severity branch still used it (warning on valid severities only); found by the sweep's log check, fixed, and `severity=critical` is now asserted on every source |
| Other fixes found on the way | `rmmH()` printed "Array" for a list value (starter pack `cert_paths`); custom link / starter-pack escaping paths audited (no other LIKE built from request text in this branch) |

## Regression for the RMM pass (RMM module on, all roles)
- `run_all.sh`: admin, technician, L2, L3, portal x2, `?id=` pass, modals, boundary. 110 flagged variants, all expected (403/404/400 bare endpoints, technician has no RMM permission); every RMM page loads for the admin; modal failures 0 / 1 / 5 as before; PHP warning/notice lines: 0 after the two fixes above (`results/analysis_rmm_pass.txt`).
- All 16 asserting scripts exit 0 (`results/asserts_rmm_pass.txt`): the 13 earlier ones plus `rmm_alerts_check.py` (28), `rmm_sqli_check.py` (14), `rmm_ui_check.py` (97). The typing matrix was **not** re-run for this pass (the RMM forms are not in its inventory); `whitespace_required_check.py` was.
- Screenshots (downscaled, kept out of git): `/home/sysadmin/qa-rmm-shots/`. I did not capture "before" images of the RMM pages before changing them, so only after-images exist; the problems are described above.
