# Reporting and dashboards

Master Plan phase 14. Everything here is additive: existing reports render as before.

## Where things live

| Piece | File |
|---|---|
| Report list, module gate and whitelisted filters per report | `src/Reports/ReportCatalog.php` |
| Saved views (CRUD, ownership) | `src/Reports/SavedReports.php`, `agent/reports/saved_views.php` |
| Toolbar on every report (saved views, CSV, print) | `agent/reports/includes/report_toolbar.php`, included by `inc_all_reports.php` |
| CSV writer, table-to-CSV | `src/Reports/ReportExport.php` (`report_send_csv()` in `functions.php` uses it) |
| PDF writer (TCPDF from `plugins/TCPDF`) | `src/Reports/ReportPdf.php`, `ReportPdfDocument.php` |
| Universal filters (department, location, technician, category, priority, status, asset) | `src/Reports/ReportFilters.php`, hooked in through `ReportScope::clause()` |
| Hub headline KPIs | `src/Reports/ReportKpis.php`, `agent/reports/index.php` |
| SLA breach / backlog / asset lifecycle | `src/Reports/Queries/*.php`, `agent/reports/{sla_breach_aging,ticket_backlog,asset_lifecycle}.php` |
| Recipient groups and schedule targets | `src/Reports/RecipientGroups.php`, `agent/reports/schedules.php` |
| Department scoping | `src/Reports/ReportScope.php` |
| Scheduled data reports | `src/Reports/ReportScheduler.php`, `cron/report_scheduler.php`, `scripts/report_render.php`, `guest/report_download.php` |
| My dashboard | `agent/my_dashboard.php`, `src/Reports/Widgets/*` |

Tables (migration 2.6.138): `saved_reports`, `report_exports`, `dashboard_layouts`, and new `report_schedules` columns
`schedule_saved_report_id`, `schedule_format`, `schedule_owner_user_id`, `schedule_last_run_at`, `schedule_last_status`.
Migration 2.6.231: `email_queue_attachments` (files that travel with a queued email). Migration 2.6.232: `report_recipient_groups`,
`report_schedule_targets` (a schedule's group + saved view pairs).

## Saved views

A view is one report key, a name, a shared flag and a JSON object of filters. Filters are whitelisted per report in
`ReportCatalog::definitions()` (type, range, allowed values) and re-validated when stored AND when opened, so a hand-edited
row cannot inject a parameter. Anyone with Reporting access can save their own view of a report their role can read.
Shared views are visible to users who can read that report. Only the owner or an administrator can rename, share or delete.
Deleting a view un-links any schedule that used it (the schedule keeps running as the plain report).

To add a filter to a report page: add it to that report's `params` in `ReportCatalog`, otherwise it is simply not saved.

## Export

Toolbar buttons: **Export CSV** (`?export=csv`), **Download PDF** (`?export=pdf`) and **Print** (`?print=1`: chrome hidden, `window.print()`).
A report page that has its own `if ($report_export_csv) { report_send_csv(...) }` block keeps it (nine do). Every other
catalogued report is exported by scraping the tables it rendered (`ReportExport::fromHtml`), so no report page had to change. Report pages
therefore draw every figure they want exported as a real `<table>` (the new reports also add a "Summary" table for their headline numbers).
CSV cells that start with `=`, `+`, `-`, `@`, tab or CR (and are not plain numbers) are prefixed with `'`.

**PDF.** `ReportPdf` turns the same rows into a PDF with the TCPDF bundled in `plugins/TCPDF` (DejaVu Sans, so accented text works):
a title, the date range and filters used, one table per section with a repeating header row, a footer with the product, the
generated time and page numbers; landscape when a table has more than six columns. Cell text is HTML-escaped before TCPDF sees it, no
images or URLs are fetched, K_TCPDF_CALLS_IN_HTML is off, output is capped at 5000 rows and 300 characters per cell. The web download
(`?export=pdf` in `inc_all_reports.php`) and the scheduler (`scripts/report_render.php --format=pdf`) use the same code.

## Universal filters

`ReportFilters` defines seven request keys: `f_client` (department), `f_location`, `f_tech`, `f_category`, `f_priority`, `f_status`, `f_asset`.
A report opts in with `'filters' => ReportFilters::ticketKeys()` (or a subset) in `ReportCatalog::definitions()`; the specs are merged into
its `params`, so they are validated, stored in saved views and replayed by schedules and headless runs like any other parameter. When a
catalogued report page loads, `inc_all_reports.php` activates the values the report declared. They become a SQL fragment appended by
`ReportScope::clause()` to every query that already applies the department scope on `ticket_client_id` (or `asset_client_id`), which
covers the shared `getXxxReport()` helpers and the report pages' own queries without editing them. A filter can only narrow: the
department restriction is applied first and the filters are ANDed to it. An explicit `$client_id` (API keys) bypasses them; dashboards and the hub never
activate them. Honoured by: Service Desk and SLA, Tickets, Tickets: Day by Day, Time by Technician, Technician Performance, Customer
Satisfaction, SLA Breach and Aging, Ticket Backlog (all seven) and Asset Lifecycle (department and location). Not honoured, by design:
Tickets by Department (already per department), Department Time Detail Audit, Included Support Issues, RMM Health (alerts, not tickets),
credential reports. Report pages with their own date or year form keep the active filters when that form is submitted (small script in the toolbar).

## Headline KPIs

`ReportKpis::headline()` feeds the hub row: open tickets, mean resolution time of tickets resolved in the last 30 days (the same
resolution-time definition as the other reports), SLA compliance of tickets created in the last 30 days (a ticket that missed any response or resolution
target, or is open past a due date with a running clock, counts as a miss; SLA-exempt categories are excluded), tickets created this month, and average CSAT
of the last 30 days (shown when CSAT is on).

## Reports added in issue #34

| Report | Answers |
|---|---|
| `sla_breach_aging` | Which tickets created in the range missed a response or resolution target, compliance by priority, technician and department, how long open ones have been overdue (under 4 h to over 7 d). |
| `ticket_backlog` | What is open now by age, status, priority, technician, department; weekly opened / closed / backlog for 4 to 52 weeks; the 25 oldest open tickets. |
| `asset_lifecycle` | Warranty expired or ending within N days (30 to 365), assets older than N years (3 to 10), by type and department. Needs the Assets module (`module_assets`). |

To register another report: add an entry to `ReportCatalog::definitions()` (key = file name in `agent/reports/`), draw tables, and add a line
to the hub and side menu. Nothing else is needed for saved views, schedules, CSV and PDF.

## Scheduled reports

`schedule_frequency`: daily, weekly, monthly, **quarterly** (3 months). `schedule_format`: `html` = the original headline summary (unchanged), `csv` = download
link, `tables` = the report's tables in the email body, **`pdf` = the report as a PDF attached to the email**. The mail queue
(`email_queue`) can carry attachments since DB 2.6.231: `addToMailQueue()` accepts `'attachments' => [['filename', 'mime', 'content']]`, stores them in
`email_queue_attachments`, `cron/mail_queue.php` attaches them when it sends and deletes the copies once the message is out (the foreign key also
removes them if the queue row is deleted). Limits: 5 attachments per message, 10 MB each, a scheduled PDF over 8 MB fails the run with a clear status. A CSV
schedule stores the file in `report_exports` and emails `https://<base url>/guest/report_download.php?t=<token>`. The token is 256 random bits; only its
SHA-256 is stored; the link expires after 7 days and expired rows are purged on each scheduler run. Anyone holding the link can download the
file, so the email says not to forward it.

**Recipient groups and targets (DB 2.6.232).** On Scheduled Reports, *Recipient groups* holds named address lists. When adding a CSV, PDF or tables schedule you can tick
groups and pick, for each, a saved view; one schedule then sends the executive view to one group and the technician view to another. A schedule
may have its own recipients, groups, or both. Each target is rendered and mailed separately. If some succeed and others fail the schedule counts as sent (so the successes are not
repeated) and its status names the failures; if all fail it is retried at the next run. Deleting a saved view makes its targets fall back to the schedule's own
report; deleting a group removes it from the schedules. Addresses in a group must be active agents or administrator-approved (checked on save and again by cron on every send,
for data schedules and groups alike).

The report is rendered by `scripts/report_render.php` in a child process as the schedule owner (`schedule_owner_user_id`),
by including the real report page headlessly (`RIVETIT_REPORT_HEADLESS`, CLI only). The owner's role (module permission) and
department restrictions therefore apply exactly as on screen, and a deactivated owner makes the schedule fail with a recorded status.
On failure `schedule_last_status` says why, `schedule_last_sent` is not stamped and the next cron run retries.

Permissions: viewing the list needs Reporting read; adding, pausing and deleting need Reporting modify (level 2); a schedule can
only be changed by its owner or an administrator (legacy schedules with no owner by any level-2 user). Recipient groups: creating needs Reporting modify; deleting
needs the creator or an administrator.

## Department restrictions

`user_client_permissions` limits a user to some departments (`$client_access_string`, ignored for administrators).
`ReportScope::clause('col')` returns `" AND col IN (...)"` for the session, or `''`. It is applied inside the shared
`getXxxReport()` helpers (where it is used only when no explicit `$client_id` is passed, so API client-scoped keys are unchanged) and in the
report pages' own queries. Cron and CLI have no session, so scheduled headline summaries stay company-wide.
Reports about company-wide money that has no department (income, expense, expense by vendor, tax, profit and loss, budget) call
`ReportScope::denyIfRestricted()`: a restricted user gets 403 and the hub hides them.

## My dashboard

`agent/my_dashboard.php` stores an ordered list of `{id, size, hidden}` per user (`dashboard_layouts.layout_widgets`).
`DashboardLayout::normalize()` runs on every read and write: unknown or duplicate ids, widgets the user may not read and bad sizes are
dropped. Widgets are defined in `WidgetRegistry::definitions()` with the modules that gate them (any-of); a widget the user cannot read is
neither offered nor queried. Every query takes the user's department scope. Rendering is `WidgetRenderer` (everything escaped; charts are inline SVG,
no script). To add a widget: add a definition, a `data()` branch and a private method returning one of the four result shapes.
Set it as the start page in Administration > Defaults > Start page.

## Tests

`tests/reports_*.php` (scratch database, see each file's header): `reports_saved_views.php`, `reports_department_scope.php`,
`reports_scheduler.php` (renderer and mail queue replaced by recorders), `reports_widgets.php`, `reports_migration.php`, and
`reports_http_walk.php` (forged-session HTTP walk against `php -S`).

Issue #34 (`tests/i34_*.php`, run with `RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=<scratch> RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=...`; the migration test also needs
`RIVETIT_TEST_DB_OLD`, a scratch database loaded from the previous release's `db.sql`): `i34_reports_migration.php`, `i34_reports_core.php` (filters, catalogue validation, PDF),
`i34_reports_data.php` (SLA / backlog / asset / KPI numbers on fixtures, with filters and department restriction), `i34_reports_helper_filters.php` (filters through the existing helpers),
`i34_reports_scheduler.php` (PDF, quarterly, groups, per-group views, policy, partial failure, queue attachments) and `i34_mail_attachment.php` (the real mailer to a throwaway local SMTP listener).
