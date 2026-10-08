# Reporting and dashboards

Master Plan phase 14. Everything here is additive: existing reports render as before.

## Where things live

| Piece | File |
|---|---|
| Report list, module gate and whitelisted filters per report | `src/Reports/ReportCatalog.php` |
| Saved views (CRUD, ownership) | `src/Reports/SavedReports.php`, `agent/reports/saved_views.php` |
| Toolbar on every report (saved views, CSV, print) | `agent/reports/includes/report_toolbar.php`, included by `inc_all_reports.php` |
| CSV writer, table-to-CSV | `src/Reports/ReportExport.php` (`report_send_csv()` in `functions.php` uses it) |
| Department scoping | `src/Reports/ReportScope.php` |
| Scheduled data reports | `src/Reports/ReportScheduler.php`, `cron/report_scheduler.php`, `scripts/report_render.php`, `guest/report_download.php` |
| My dashboard | `agent/my_dashboard.php`, `src/Reports/Widgets/*` |

Tables (migration 2.6.138): `saved_reports`, `report_exports`, `dashboard_layouts`, and new `report_schedules` columns
`schedule_saved_report_id`, `schedule_format`, `schedule_owner_user_id`, `schedule_last_run_at`, `schedule_last_status`.

## Saved views

A view is one report key, a name, a shared flag and a JSON object of filters. Filters are whitelisted per report in
`ReportCatalog::definitions()` (type, range, allowed values) and re-validated when stored AND when opened, so a hand-edited
row cannot inject a parameter. Anyone with Reporting access can save their own view of a report their role can read.
Shared views are visible to users who can read that report. Only the owner or an administrator can rename, share or delete.
Deleting a view un-links any schedule that used it (the schedule keeps running as the plain report).

To add a filter to a report page: add it to that report's `params` in `ReportCatalog`, otherwise it is simply not saved.

## Export

Toolbar buttons: **Export CSV** (`?export=csv`) and **Print / PDF** (`?print=1`: chrome hidden, `window.print()`; choose
"Save as PDF"). A report page that has its own `if ($report_export_csv) { report_send_csv(...) }` block keeps it (nine do). Every other
catalogued report is exported by scraping the tables it rendered (`ReportExport::fromHtml`), so no report page had to change.
CSV cells that start with `=`, `+`, `-`, `@`, tab or CR (and are not plain numbers) are prefixed with `'`.

## Scheduled reports

`schedule_format`: `html` = the original headline summary (unchanged), `csv` = download link, `tables` = the report's tables in
the email body. The mail queue (`email_queue`) has no attachment support, so a CSV schedule stores the file in
`report_exports` and emails `https://<base url>/guest/report_download.php?t=<token>`. The token is 256 random bits; only its
SHA-256 is stored; the link expires after 7 days and expired rows are purged on each scheduler run. Anyone holding the link can download the
file, so the email says not to forward it.

The report is rendered by `scripts/report_render.php` in a child process as the schedule owner (`schedule_owner_user_id`),
by including the real report page headlessly (`RIVETIT_REPORT_HEADLESS`, CLI only). The owner's role (module permission) and
department restrictions therefore apply exactly as on screen, and a deactivated owner makes the schedule fail with a recorded status.
On failure `schedule_last_status` says why, `schedule_last_sent` is not stamped and the next cron run retries.

Permissions: viewing the list needs Reporting read; adding, pausing and deleting need Reporting modify (level 2); a schedule can
only be changed by its owner or an administrator (legacy schedules with no owner by any level-2 user).

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
