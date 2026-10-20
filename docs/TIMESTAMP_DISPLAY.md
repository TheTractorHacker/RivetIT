# Showing times: company time zone, never "UTC"

Rule (RivetIT 26.10.61): every date and time shown to a user (RMM, Endpoint agent, admin screens, and the fragments/JS they load) is shown in the **company's time zone**
(Settings > Localization, `settings.config_timezone`; `includes/inc_set_timezone.php` copies it to `$_SESSION['session_timezone']`, `date_default_timezone_set()` and
MySQL's `SET time_zone`), with the zone abbreviation where space allows (`2026-10-18 12:16 CDT`) or in a tooltip. If the setting is unreadable the server zone is used, then UTC.
A label says "UTC" only when the company zone really is UTC.

## The helper (`includes/rivet_time.php`)

| Function | Use |
|---|---|
| `rivetFormatTime($value, $storedAs = 'utc', $withZone = true, $format = 'Y-m-d H:i')` | plain text (escape in the caller). `$storedAs`: `'utc'`, `'local'`, `'epoch'`. `''` for null/zero/invalid |
| `rivetTimeIso($value, $storedAs)` | `2026-10-18T17:16:53Z` for `<time datetime>` |
| `rivetTimeEpoch($value, $storedAs)` | Unix seconds or null |
| `rivetCompanyTimezone()` / `rivetTimeZoneLabel()` | zone name / current abbreviation (`CDT`) |

* `'utc'` converts with `DateTimeZone` (DST correct). `'local'` is only labelled, never converted (no double conversion). `'epoch'` converts. A value that carries `Z` or `+hh:mm` is always read as that instant.
* A `'local'` value inside the repeated hour of a DST fall-back cannot be told apart; PHP picks the first. All other cases are exact.
* Never print a stored time raw, never hard-code `(UTC)` in a column header (the lint test `tests/rivet_time_lint.php` fails on it). Headers say what the column is ("Uploaded"); cells carry the zone.
* Durations ("Took 3 min", "5 min ago") are not times and stay as they are. Machine interfaces (REST API, JSON feeds, the agent protocol, CSV exports, audit sink files) keep UTC/ISO.
* JS: the server sends text already formatted (`expires_at_text`), or a zone name in `data-company-tz` and the script formats with `Intl.DateTimeFormat({timeZone})`. Do not use the browser's own zone.

## Which tables store what

* **Written by RivetIT with `NOW()` / `date()` / `current_timestamp()` = company-local**: `rmm_alerts`, `asset_rmm_links` (`last_seen` is converted with `date()` on import, `last_sync`), `rmm_sync_log`, `rmm_script_runs`, `rmm_remote_sessions`, `audit_events.created_at`, `logs`, tickets.
* **Written by RivetCore, `endpoint_agent_*`, and the build tables with `UTC_TIMESTAMP()` / `gmdate()` / RFC 3339**: everything else listed below.

## Audit table (page, value, stored as, shown by)

| Page / fragment | Values | Stored | Conversion |
|---|---|---|---|
| Admin > Endpoint agent > Agent updates: agent binaries table | `created_at` ("Uploaded") | utc (read model, RFC 3339) | `$dt()` = `rivetFormatTime(.., 'utc')` |
| same: MSI templates table | `created_at` ("Uploaded") | utc | `$dt()` |
| same: release feed (latest release, "Last checked", saved list) | `published_at`, `fetched_at`, `checked_at`, `rate_reset_at` | utc (GitHub `Z`; `UTC_TIMESTAMP()`) | `rivetAgentFeedWhen()` |
| same: GitHub rate-limit message, token check text | stored text | made at write time | `rivetFormatTime(.., 'epoch')` |
| same: build on GitHub box, token set time | `token_set_at`, `requested_at`, `finished_at` | utc | `rivetAgentFeedWhen()`; ages use `strtotime(.. . ' UTC')` (arithmetic only) |
| same: build hosts ("last seen"), build jobs (created) | `last_seen_at`, `created_at`, `claimed_at`, `finished_at` | utc | `rivetBuildHostUi` `$when` -> `rivetAgentFeedWhen()`; "Took/Running for" is a duration |
| Enrollment tokens (expires, last used), attempts, signing key created, devices (first seen, last check-in, offline since) | `expires_at`, `last_used_at`, `attempted_at`, `signing_key_created_at`, `first_seen_at`, `last_checkin_at`, `offline_since` | utc | `$dt()` |
| Deployment commands note, installer / add-device dialogs (AJAX JSON `expires_at_text`) | token `expires_at` | utc | `rivetFormatTime` server side; `js/rmm_installer.js`, `js/rmm_add_device.js` show `expires_at_text` |
| Agent > device page (check-ins, checks, jobs) | `last_checkin_at`, `last_collected_at`, `first_seen_at`, `last_reported_at`, job `created_at`/`finished_at` | utc | `$ts()` |
| RMM device/fleet panels, patches, software, remote tools, log search, mesh | core read models (`..._at`) | utc | `rivetRmmUiTime()` (relative text, tooltip in company zone), `rivetRmmAutoUtc()`, `rivetRmmLogWhen()`, `rivetRmmAlrWhen()` |
| RMM log search custom range (From/To) | filter inputs | typed in the company zone | `rivetTimeEpoch(.., 'local')` and back |
| RMM alerts, maintenance windows | core `opened_at`, window `starts_at`/`ends_at` | utc | `rivetRmmAlrWhen()`; the window editor shows "Company time: ..." under each local box (`js/rmm_alr.js`, `data-company-tz`) |
| Script schedules / approvals / runs | `next_run_at`, `last_run_at`, `slot_at`, `queued_at`, `expires_at`, `decided_at` | utc | `rivetRmmAutoUtc()` / `rivetRmmScrTimeBoth()`. **Cron expressions stay UTC** (the scheduler reads the five fields as UTC): the labels "cron timetable (UTC)" are input rules, not timestamps |
| Scale settings (probe time, outbox oldest, partition bound, warm start) | `rmm_storage_state.updated_at`, `rmm_metric_outbox.created_at`, epoch ints | utc / epoch | `rivetFormatTime` |
| Device "last boot", network peak time | derived epoch / core ISO | utc | `rivetFormatTime(.., 'epoch'|'utc')` |
| Legacy RMM pages: `agent/rmm_assets.php`, `rmm_asset.php`, `rmm_alerts.php`, `rmm_dashboard.php`, `rmm_scripts.php`, `rmm_script_run.php`, asset Metrics tab, ticket text from `rmm_functions.php` | `last_seen`, `created_at`, `started_at`, `finished_at`, `ticket_created_at`, `last_sync` | local | `rivetFormatTime(.., 'local')` (label only) |
| Asset Metrics tab chart axis and tooltip | ISO `Z` samples | utc | `js/asset_metrics.js` formats with `Intl` in `data-company-tz` (was the browser's zone) |
| Admin > Audit trail | `audit_events.created_at` | local (`current_timestamp()`) | `rivetFormatTime(.., 'local')`; hash-chain check time (`gmdate`) is utc |
| Training settings: PIN sources refreshed | `config_training_pin_sources_synced_at_utc` | utc | `rivetFormatTime(.., 'utc')` |

Known differences left alone on purpose: the audit CSV export and `AuditSink` files carry the raw database time (the sink labels it UTC although `audit_events.created_at` is company-local; flagged for a later fix because changing a log format needs its own release note), and
the REST API and agent protocol are UTC.

## Adding a new time to a page

1. Find the writer: `UTC_TIMESTAMP()`/`gmdate`/`Z`/epoch means `'utc'` or `'epoch'`; `NOW()`, `CURRENT_TIMESTAMP`, a column `DEFAULT current_timestamp()` or `date()` means `'local'`.
2. Print `$h(rivetFormatTime($v, 'utc'))` (or `rivetRmmUiTime()` when you want "5 min ago" with the exact time in the tooltip).
3. Do not write "(UTC)" in the header; run `php tests/rivet_time_lint.php`.
