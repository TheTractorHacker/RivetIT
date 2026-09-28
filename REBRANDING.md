# Rebranding: ITFlow Internal IT → RivetIT

In September 2026 the application formerly called **ITFlow Internal IT** (repository
`TheTractorHacker/ITFlow-Internal-IT`, itself a fork of the MSP-focused `TheTractorHacker/itflow`, a fork of
`itflow-org/itflow`) was renamed **RivetIT**. This was a rename and a product identity, **not a rewrite**: no
feature, route, API endpoint or field, integration, database table or column, or stored record changed. This
file records what was renamed, what deliberately was not, and why, so later work does not "finish the job"
by renaming something that installs depend on.

## Identity

| Item | Value | Constant |
|---|---|---|
| Name | RivetIT | `APP_NAME` |
| Description | RivetIT is a free and open-source internal IT operations platform for managing service requests, users, devices, documentation, automation, integrations, and employee training from one centralized system. | `APP_DESCRIPTION` |
| Short description | Open-source internal IT operations platform. | `APP_SHORT_DESCRIPTION` |
| Tagline | Everything your IT department needs. One platform. | `APP_TAGLINE` |
| License | GPL-3.0 (unchanged; composer.json says `GPL-3.0-only`, the non-deprecated SPDX id for the same thing) | `APP_LICENSE` |
| Upstream | ITFlow, https://github.com/itflow-org/itflow | `APP_UPSTREAM_NAME`, `APP_UPSTREAM_URL` |

All of these live in **`includes/branding.php`**, loaded first by `functions.php` and
`includes/app_version.php`. It also holds the project links (`APP_REPO_URL`, `APP_WEBSITE_URL`,
`APP_DOCS_URL`, `APP_SUPPORT_URL`) and the brand asset paths (`APP_LOGO_URL`, `APP_LOGO_DARK_URL`,
`APP_LOGO_MARK_URL`, `APP_FAVICON_URL`, all under `/img/branding/`).

Rules that follow from it:

- **PHP output never contains the product name or the repository URL as a literal**; it uses the constants,
  escaped like the surrounding code. A rename or a repository move is then a one-file change.
- **Files that cannot load PHP say RivetIT literally**: shell scripts under `deploy/` and `docker/`, systemd
  and nginx templates, Markdown, YAML, static JS/CSS/JSON.
- **The company still brands the install.** The company name and an uploaded logo or favicon (Settings >
  Company / Theme) keep precedence wherever a page already showed them; the RivetIT mark is the fallback.
- **Version numbers were not reset**: `APP_VERSION` stays `26.09`, the database stays `2.6.99`.
- **Terminology**: the UI already said "Department" for clients; the rename did not change any other
  terminology. MSP-only modules (invoicing, quotes, payments, payroll, CRM) stay in the code, switched off
  (Settings > Modules does not offer them and saves them as 0); they are not advertised as features.

## What says RivetIT now

Every place where the software names itself says RivetIT through `APP_NAME`: the app chrome and footer
("RivetIT 26.09 · Docs · Source"), login ("Welcome to RivetIT", with the RivetIT mark when no company logo is set),
setup ("Welcome to RivetIT"), the About / debug page ("RivetIT | Version 26.09", Source, License, "Based on
ITFlow"), Admin > Update, settings help text, notifications, e-mails about the system, the API reference, PDFs, the
kiosk and the certificate check page. The root `/favicon.ico` and `img/branding/` (`logo.svg`, `logo-dark.svg`,
`logo-mark.svg`, `favicon.svg`, `favicon.ico`) are the RivetIT mark; pages with no uploaded favicon link
`/favicon.ico` and `APP_FAVICON_URL`.

These strings changed as well. They are display-only: nothing reads them back, matches them or hashes them.

| String | Now | What stays |
|---|---|---|
| Authenticator label (the `otpauth://totp/` label prefix) | `RivetIT:<email>` for new enrolments | The secret; existing authenticator entries keep their old label and keep working |
| Passkey `rp.name` | `RivetIT` | `rp.id` (existing passkeys are bound to it) |
| Calendar feed | `PRODID:-//RivetIT//Ticket Schedule//EN`, file `rivetit-schedule.ics` | The subscription URL and token |
| Test push titles | "RivetIT Test Notification" | |
| Stripe PaymentIntent description | Starts with `RivetIT:` | Metadata keys `itflow_*` |
| API token label for passkey logins | "RivetIT Android (passkey)" (new tokens) | Existing tokens and their labels |
| Outgoing User-Agent (US Census / Nominatim geocoding) | `RivetIT/26.09 (+<APP_REPO_URL>; Open-source internal IT operations platform)` | |
| Outgoing User-Agent (YouTube / Vimeo lookups for training videos) | `RivetIT-Training/1.0` | |
| Training PDFs | Creator "RivetIT Training"; the transcript footer adds "with RivetIT" | The company is the certificate issuer |
| Public certificate check note (EN / ES) | "Records are kept in RivetIT by <company>" | Verify codes (`itflow-training-cert-key\|v1`) |
| Odoo write-back wording | "Record of truth: RivetIT", "RivetIT ref: <marker>", "voided in RivetIT" | The `[ITFLOW:…]` marker; lines written before the rename keep their old wording |
| Kiosk web-app manifest name | "RivetIT Training" (was the company name "Midwest Training") | Kiosks already added to a home screen keep their installed name |
| In-app backup `version.txt` and SQL dump header | "RivetIT Backup Metadata", "RivetIT Version", "-- RivetIT DB Dump" | File names `itflow_<ts>_<type>.zip`; restore only logs `version.txt` |
| QuickBooks generic service item | "RivetIT Services", created only on a first-ever sync | An item already mapped keeps its name |
| New installs (`setup/index.php`, `scripts/setup_cli.php`) | `$config_app_name = 'RivetIT'`; seeded "Docs" custom link = `APP_DOCS_URL` | Existing installs' config and custom links |
| Webhooks | `X-RivetIT-Signature` / `X-RivetIT-Event` added | `X-ITFlow-Signature` / `X-ITFlow-Event` |

### `$config_app_name` on existing installs

Each install's `config.php` holds `$config_app_name`. Setup wrote `ITFlow` upstream and `ITFlow Internal IT` in
this fork, and about 25 e-mail subjects and bodies use it. `functions.php` now normalises it once, right after it
loads: `appDisplayName()` returns `APP_NAME` for an empty value or **exactly** `ITFlow` or `ITFlow Internal IT`
(after trimming), and returns any other name unchanged. So existing installs send "RivetIT …" e-mails and nobody
has to edit `config.php`, while a name an administrator chose on purpose is kept. The config key is unchanged, and
`config.php` is never rewritten. Places that show the product itself, such as the footer, setup and the About page,
use `APP_NAME` directly.

## Environment variables

| Variable | Status | Read by |
|---|---|---|
| `RIVETIT_<CONSTANT>` (e.g. `RIVETIT_APP_NAME`, `RIVETIT_APP_REPO_URL`) | New. Overrides any constant in `includes/branding.php` when no earlier `define()` exists; empty means "use the default". | `includes/branding.php` |
| `RIVETIT_APP_NAME`, `RIVETIT_APP_REPO_URL`, `RIVETIT_APP_WEBSITE_URL`, `RIVETIT_APP_DOCS_URL`, `RIVETIT_APP_SUPPORT_URL` | Passed from `.env` into the app container by `docker-compose.yml` (others can be added the same way). | Docker Compose |
| `RIVETIT_DB_PASSWORD`, `RIVETIT_ADMIN_PASSWORD` | New, preferred. Installer secrets passed in the environment instead of on argv. | `scripts/setup_cli.php` |
| `ITFLOW_DB_PASSWORD`, `ITFLOW_ADMIN_PASSWORD` | **Deprecated, still honoured.** Existing automation keeps working. `deploy/install.sh` and `docker/entrypoint.sh` set both names. No removal date; remove only in a release that announces it. | `scripts/setup_cli.php` |
| `RIVETIT_CONTAINER_PREFIX` | New. Container-name prefix (default `rivetit`), only needed to run two stacks on one Docker host. | `docker-compose.yml` |

`scripts/setup_cli.php` tries `RIVETIT_*` first and then `ITFLOW_*`, and treats an empty value as unset. When both
are set it uses the `RIVETIT_*` value and does not complain; `install.sh` and the entrypoint set both to the same value.
An empty `RIVETIT_ADMIN_PASSWORD` still means "prompt". `--help` documents both names.

## Updater source

- **Admin > Update** runs `git fetch fork` and compares `HEAD` with `fork/<$repo_branch>` (`fetchUpdates()`
  in `functions.php`, `admin/update.php`). The `fork` remote is this repository,
  `TheTractorHacker/ITFlow-Internal-IT`, branch `main` (`$repo_branch` in `config.php`). **The rename did not
  change where updates come from**, and no RivetIT repository exists yet, so nothing points at one.
- The remote name is now a constant, `APP_UPDATE_REMOTE` (default `fork`, in `includes/branding.php`), so a
  future move is a configuration change: add the new repository as a git remote on each install (or re-point
  `fork`), then set the constant (or `RIVETIT_APP_UPDATE_REMOTE`). `fetchUpdates()` uses it and falls back to
  `'fork'` if it is not defined. The git log on Admin > Update also uses it. Both pass the remote and ref through
  `escapeshellarg()`. The page names the remote and branch it compares against.
- `deploy/update.sh` wraps `scripts/update_cli.php` and uses a plain `git pull` of the checkout's own
  upstream (usually `origin`, the same repository). `deploy/install.sh` clones `REPO_URL`
  (`https://github.com/TheTractorHacker/ITFlow-Internal-IT.git`).
- The "Latest Release" link on Admin > Update used to point at the old MSP fork (`TheTractorHacker/itflow`); it
  now opens `APP_RELEASES_URL` (`APP_REPO_URL . '/tags'`: the updater compares git tags). The page's docs,
  changelog and support links follow `APP_DOCS_URL`, `APP_REPO_URL` and `APP_SUPPORT_URL`.
- Not changed, and unrelated to the rename: the force-update paths (`admin/post/update.php`,
  `scripts/update_cli.php --force_update`) still run `git reset --hard origin/master`, an upstream leftover. This
  repository's branch is `main`.

## Repository and links

The repository is still `https://github.com/TheTractorHacker/ITFlow-Internal-IT`, so `git clone` still
creates an `ITFlow-Internal-IT` directory, and the docs say so. When the project moves (for example to a
RivetIT organization), change it in these places:

| Where | What |
|---|---|
| `includes/branding.php` | `APP_REPO_URL` (the docs/support/website links derive from it), or set `RIVETIT_APP_REPO_URL` |
| `deploy/install.sh` | `REPO_URL` (the clone source for fresh boxes) |
| `deploy/templates/itflow-backup.service`, `.timer` | `Documentation=` |
| `composer.json` | `homepage`, `support.*` |
| `README.md`, `SECURITY.md`, `docs/DEPLOYMENT.md` | clone commands, badges, advisory links |
| each install | the `fork` / `origin` git remotes, then `APP_UPDATE_REMOTE` if the remote name changes |

## Identifiers kept for compatibility

Every item below still says `itflow` (or `ITFlow`) on purpose. Renaming any of them breaks existing installs,
integrations, stored data or the Android companion app, for no visible benefit.

### Code

| Identifier | Where | Why it stays |
|---|---|---|
| PHP namespace `ITFlow\` | composer PSR-4 `"ITFlow\\": "src/"`, ~500 files | Autoloading of every class under `src/`; renaming is a ~500-file refactor. New classes use it too. |
| `itflow_*` functions (roles, module access, nav, pop-up permissions; ~52 names) | `includes/`, `functions.php`, portals | Shared API across all portals and the pop-up permission map. |
| `css/itflow*.css` files (26) and the `itflow-access-denied` class | `css/`, every page head | The cascade order is load-bearing (Tabler shim layering); files and classes are never renamed. |
| JS globals and ids (`window.itflowChartTheme`, `window.ITFlowKB`, `window.itflowRoleEditor`, `#itflowSidebarToggle`, …) | `js/`, `agent/js/` | Cross-file contracts between PHP-rendered markup and scripts. |
| PHP constants (`ITFLOW_MODAL_DEFAULT_REQUIREMENT`, `ITFLOW_FULL_AGENT_MODULES`, `ITFLOW_TRAINING_*`, test constants) | `includes/`, `src/Training/` | Referenced across files and by the test harnesses. |
| Database name examples, tables, columns, `settings`/config keys (`$config_*`), `db.sql` header (`itflow_dev`) | everywhere | Never renamed for branding (brief). The `db.sql` dump header is historical. |

### Security, crypto and integrations

| Identifier | Where | Why it stays |
|---|---|---|
| API key-derivation salt `'itflow_enc'` | `api/v1/auth.php`, `api/v1/credentials.php` | Wraps the vault key inside every issued API/mobile token; changing it locks the Android app out of credentials. |
| KDF/HMAC labels `itflow-training-*\|v1`, `itflow-training-cert-key\|v1`, `itflow-training-odoo\|` | `src/Training/Kiosk/Core/KioskKeys.php`, `Records/CertSecret.php`, `OdooSync/Marker.php` | Learner PINs, kiosk tokens, certificate verify codes and the Odoo install id are derived from them. |
| Odoo write-back markers `[ITFLOW:<inst8>:C…]` and `Marker::RE` / `RE_ANY` | `src/Training/OdooSync/` | Idempotency: existing Odoo lines are found by this exact marker. (The human wording around it, e.g. "Record of truth", may say RivetIT.) |
| Webhook headers `X-ITFlow-Signature`, `X-ITFlow-Event` | `cron/cron.php`, `src/Webhooks/WebhookDispatcher.php` | Receivers verify them. Both senders now also send `X-RivetIT-Signature` and `X-RivetIT-Event` with the same values (same HMAC-SHA256 of the body); receivers can switch whenever they like. The old names are never dropped silently. |
| Stripe metadata `itflow_invoice_id`, `itflow_client_id`, `itflow_client_name`, `itflow_invoice_number` | payment code in `agent/`, `client/`, `guest/`, `cron/` | Read back by the payment webhook, including for payments created before the rename. |
| KB media HMAC context `itflow.kb_media.v1` | `src/KB/MediaToken.php` | Every signed KB media URL already issued (web, portal, Android). |
| Metrics wire tag `itflow.metrics.v1`, collector `itflow_metrics_collector.ps1`, token cache `%ProgramData%\ITFlow\metrics\`, Tactical field `{{agent.itflow_metrics_token}}` | `api/v1/metrics_ingest.php`, `scripts/collector/` | Deployed collectors send the tag and keep their token there; renaming forces every endpoint to re-enrol. |
| localStorage `itflow.sidebar.folded`, `itflow.training.transcript.showRevoked`; BroadcastChannel `itflow-training` | `js/shell.js`, `js/training_common.js`, `agent/js/` | Saved per-user preferences, and cross-tab sync between old and new cached JS during a rollout. (Session and cookie names such as `PHPSESSID`, `rememberme`, `user_encryption_session_key` carry no brand and are unchanged.) |
| WebAuthn `rp.id`, TOTP secrets | `agent/user/` | Only the display name (`rp.name`, otpauth label) says RivetIT; `rp.id` never changes. |
| Android package `com.foleyit.itflow(.beta)` | `.well-known/assetlinks.json`, notification settings | The companion app's application id (separate repository). |
| Ledger actor `itflow-training-worker` | `src/Training/Automation/WorkerCtx.php` | Written into the hash-chained training ledger; kept for uniform history. |
| Temp and lock names `itflow_*_cron_*`, `itflow_mail_queue_*`, `itflow_training_*`, `itflow-*-media-*` | `cron/`, `src/Training/`, `src/KB/` | A rename mid-rollout lets an old and a new run overlap and orphans scratch directories. |
| Parser placeholder `itflow-guest@example.com` | `cron/ticket_email_parser.php` | Placeholder sender stored on parsed rows; existing rows may match it. |
| Signed collector token prefix `itfm1.` | `api/v1/metrics_ingest.php`, collector | Part of issued device and enrolment tokens (it does not contain "itflow", listed for completeness). |

### Files, folders and server names

| Identifier | Where | Why it stays |
|---|---|---|
| In-app backup files `itflow_<14 digits>_(manual\|auto).zip` | `admin/post/backup.php`, `admin/backup.php`, `cron/` | Globbed for the list and pruning, regex-checked for download; renamed files would vanish from the list and never be pruned. (The text inside `version.txt` / the dump header may say RivetIT.) |
| `deploy/backup.sh` archives `backup-<DB_NAME>-<ts>.tar.gz.enc` and `backup-manifest.json` | `deploy/` | Named after the database; `restore.sh` parses the manifest. Docs examples say `backup-itflow-…` because `itflow` is the Docker default database name. |
| Mail folder `ITFlow` | `cron/ticket_email_parser.php` | Processed mail is moved there in every monitored mailbox; a rename would silently create a second folder. The NDR log line and the Microsoft 365 setup steps (Admin > Mail) name the folder, and the setup steps say why it keeps the old name. Making it a setting is a follow-up. |
| `/etc/cron.d/itflow` | `admin/cron.php`, `admin/post/cron.php` | The Cron Manager rewrites exactly this file through a sudoers rule. |
| Deploy tooling names: `/var/log/itflow-{install,backup,restore,update,cron}.log`, `/etc/cron.d/itflow-<domain>`, `itflow-backup.service` / `.timer` (and the template file names), `/etc/itflow/backup-passphrase`, fail2ban `jail.d/itflow.local` + `filter.d/itflow-auth.conf` (jail `[itflow-auth]`), nginx `conf.d/itflow-rate-limit.conf` and zone `itflow_login`, `99-itflow-hardening.ini/.cnf`, `51-itflow-unattended-upgrades`, `deploy/templates/jail-itflow.local` | `deploy/` | Existing boxes have them installed and enabled; re-runs must find the same files, and the vhosts reference the zone. |
| **Text** of files the deploy scripts compare byte for byte: `deploy/templates/php-hardening.ini`, `mariadb-hardening.cnf`, `jail-itflow.local`, and the inline fail2ban filter, rate-limit and unattended-upgrades heredocs in `harden.sh` / `install.sh` | `deploy/` | A changed comment would make the next `install.sh` on a shared box or `harden.sh` run restart PHP-FPM / MariaDB / fail2ban (dropping every other instance's connections and in-memory bans), and `harden.sh` refuses a rate-limit file that differs. Their header comments keep saying ITFlow-Internal-IT. |
| Live-box names: `/var/www/mw-itflow.foleyit.com`, database `midwest_itflow`, `/var/log/itflow_mw_*.log`, `/etc/cron.d/mw-itflow-*`, `/etc/nginx/snippets/itflow-locations.conf`, sibling installs (`itflow.foleyit.com`, `beta-itflow.foleyit.com`) | comments, admin help, docs | Facts about the server this repository is deployed on, not product branding. |
| In-image paths `zz-itflow-overrides.ini`, `sites-available/itflow.conf`, `supervisor/conf.d/itflow.conf` | `docker/Dockerfile` | Internal to the image; renaming changes nothing a user sees. |
| Internal shell array `_ITFLOW_TMPFILES`, installer fallback DB name `itflow` | `deploy/lib/common.sh`, `deploy/install.sh` | Internal names; the fallback only applies when a domain yields no usable name. |

### Docker

- **Renamed**: containers `rivetit-web` and `rivetit-db` (`${RIVETIT_CONTAINER_PREFIX:-rivetit}-web|-db`), and
  the locally built image tag `rivetit-web:local`. On `docker compose up -d --build` Compose recreates both
  containers under the new names; scripts should use `docker compose exec app|db` (service names).
- **Kept**: the volume key **`itflow_db_data`** (the data), the `DB_NAME` / `DB_USER` defaults **`itflow`**
  (the existing database and user), the service keys **`app`** and **`db`** (`DB_HOST: db`), and **no top-level
  `name:`** (it would change the project prefix of the volume and orphan the data). `.dockerignore` has no
  brand strings.

## Legal and attribution

- `LICENSE` (GPL-3.0 text) is untouched. Copyright notices, file headers that credit ITFlow, and
  third-party licenses under `vendor/` and `plugins/` are untouched.
- `NOTICE` (new) credits ITFlow and its contributors (itflow-org), the MSP fork `TheTractorHacker/itflow`
  (TractorHacker / Foley IT) and the GPL, and states that RivetIT is an independent project.
- The README's Credits section, `SECURITY.md` (upstream policy for shared code) and the About / debug page
  keep the upstream attribution. Product chrome (footer, login, setup) shows RivetIT only.
- `CHANGELOG.md` entries from before the rename, `PROGRESS.md`, `ITFlow_Internal_IT_Modernization_Master_Plan.md`,
  `docs/MSP_DESIGN_PORT_PLAN.md` and `docs/REDESIGN_ARCHITECTURE_REPORT.md` are historical records: each got a
  one-line banner and is otherwise unchanged. Git history was not rewritten.
- Code comments that identify upstream behaviour ("upstream ITFlow did X") are kept; comments that merely
  named the old product were updated where it helped.

## Removed or redirected

- `.github/FUNDING.yml` (a Sponsor button for upstream's `services.itflow.org`) was removed; the project has
  no funding link of its own yet.
- `.github/dash.png` and `.github/readme.gif` (upstream MSP demo screenshots, referenced nowhere) were removed.
- Issue templates, the first-interaction workflow, `SECURITY.md` and `CODE_OF_CONDUCT.md` pointed at the
  upstream ITFlow forum and security advisories; they now point at this repository. The upstream SonarCloud
  badge was dropped from `SECURITY.md` because it measures `itflow-org/itflow`, not this repository.

## Telemetry

The optional telemetry (off by default, Settings > Telemetry) still reports to the upstream endpoint
`https://telemetry.itflow.org` (`cron/cron.php`, `admin/post/update.php`, `scripts/setup_cli.php`); it was
not redirected. Every place that offers it says so: Settings > Telemetry ("RivetIT is built on ITFlow, and
telemetry is sent to the upstream ITFlow project (telemetry.itflow.org), not to RivetIT"), the setup wizard's
telemetry step and the `setup_cli.php` prompt. The "details" links point at the upstream ITFlow telemetry docs and are
labelled as upstream.

## Follow-ups (not part of the rename)

- **Android companion app** (separate repository): rename the app and its strings; keep the package id and
  every API contract listed above.
- **Live data that only changes through the app:** older installs have a setup-seeded custom link "Docs →
  https://docs.itflow.org" in `custom_links`, and the live install has one (checked read-only on 2026-09-28). It
  shows in the navigation. Point it at `APP_DOCS_URL` or remove it in Admin > Custom Links; the rename does not
  touch stored data.
- **Mail folder name:** the parser's `ITFlow` folder could become a setting; then new installs could use a RivetIT
  name while existing mailboxes keep theirs.
- **Company-specific values that are not product branding:** `https://foleyit.com/ticket-terms` is hard-coded on
  the signature pages (`guest/outtake_sign.php`, `guest/worksheet_sign.php`, `agent/modals/ticket/outtake_sign.php`)
  and should become a setting. The kiosk manifest no longer carries the company name, and `KioskSettings::fromRow()`
  no longer defaults to it.
- **Cron Manager:** `admin/cron.php` / `admin/post/cron.php` build job lines from
  `/var/www/itflow.foleyit.com/cron/`, the sibling install's path, not this checkout's. This is pre-existing and not
  branding, but it is worth checking.
- **Repository hygiene:** enable GitHub private vulnerability reporting on the repository (`SECURITY.md` and the issue
  templates rely on it). `SECURITY.md` keeps upstream's 72-hour acknowledgement promise; adjust it if needed.
- Pre-existing, unrelated to the rename: `deploy/install.sh` writes `/etc/nginx/conf.d/itflow-rate-limit.conf`
  with different comment lines than `deploy/harden.sh` expects, so `harden.sh` (step 7, nginx) refuses that
  file on a box built by `install.sh` unless run with `--skip-nginx`.

## Remaining `itflow` matches

The final search ran on the merged tree:

```
git grep -n -i -I -E 'itflow|it flow' -- . ':!vendor' ':!plugins'      # contents
git ls-files | grep -i itflow | grep -v -E '^(vendor|plugins)/'         # file names
```

It found **3530 matching lines in 656 files, plus 29 file names**. **Every match is KEEP** with one of the reasons below; **none is CHANGE**. No product-name "ITFlow" is left in the interface by accident. A sweep of the rendered pages confirmed this: every admin, agent, user and report page, the logged-out, portal, kiosk, verify and API-doc pages, and the update page (with `git fetch` stubbed), 269 pages in all. It found "itflow" in the visible text only where the "User-visible KEEPs" list below says it should be.

### Count per reason

A line is counted once, under its strongest reason (compatibility, then server, repository, upstream, attribution, migration, historical).

| Reason | Lines |
|---|---:|
| compatibility identifier, grouped (lines that contain only the namespace, CSS file, function, JS global and constant names below) | 2830 |
| compatibility identifier | 154 |
| internal identifier: server / deploy name | 129 |
| repository URL (the real, current repository) | 30 |
| attribution / licensing | 28 |
| upstream reference | 20 |
| migration note (explains the rename, a kept name or a legacy value) | 194 |
| historical | 142 |
| false positive ("create/edit flows") | 3 |
| **total** | **3530** |

File names: 29. That is 24 `css/itflow*.css` files (compatibility), `scripts/collector/itflow_metrics_collector.ps1` (compatibility), the three `deploy/templates/` files `itflow-backup.service`, `itflow-backup.timer` and `jail-itflow.local` (server / deploy names, installed under the same names), and `ITFlow_Internal_IT_Modernization_Master_Plan.md` (historical).

### Identifier classes (summarised, not listed line by line)

Grouped identifiers, counted per occurrence. Renaming any of them is a refactor with no visible benefit, and it risks breaking the autoloader, the CSS cascade, or cross-file contracts:

| Class | Occurrences | Files |
|---|---:|---:|
| PHP namespace `ITFlow\` (composer PSR-4 `"ITFlow\\": "src/"`) | 2294 | 497 |
| `css/itflow*.css` file names (page heads, comments, docs) | 416 | 99 |
| `itflow_*` PHP functions and globals (module access, roles, nav, pop-up map) | 275 | 58 |
| JS globals and DOM ids (`itflowChartTheme`, `ITFlowKB`, `itflowRoleEditor`, `#itflowSidebarToggle`, …) | 52 | 14 |
| PHP constants (`ITFLOW_MODAL_DEFAULT_REQUIREMENT`, `ITFLOW_FULL_AGENT_MODULES`, `ITFLOW_TRAINING_TEST_*`) and `_ITFLOW_TMPFILES` | 42 | 6 |
| CSS class `itflow-access-denied` | 3 | 2 |

Specific identifier classes. The count is the lines that contain at least one match of the class (lines in the whole-file KEEPs further down are not counted):

| Identifier | Why it stays | Lines | Files |
|---|---|---:|---:|
| API token → vault-key salt `'itflow_enc'` | wraps the vault key in every issued API/mobile token | 4 | 2 |
| KDF/HMAC labels `itflow-training-*\|v1`, `itflow-training-cert-key\|v1`, `itflow-training-odoo\|` | learner PINs, kiosk tokens, certificate codes, Odoo install id | 12 | 3 |
| Odoo marker `[ITFLOW:<inst8>:…]`, `Marker::RE` / `RE_ANY` | write-back idempotency | 7 | 2 |
| Webhook headers `X-ITFlow-Signature` / `X-ITFlow-Event` | receivers verify them; `X-RivetIT-*` sent alongside | 6 | 2 |
| Stripe metadata `itflow_client_id`, `itflow_client_name`, `itflow_invoice_number`, `itflow_invoice_id` | the payment webhook reads them back | 24 | 6 |
| KB media HMAC context `itflow.kb_media.v1` | every issued signed media URL | 1 | 1 |
| Metrics wire tag `itflow.metrics.v1` | deployed collectors send it | 4 | 3 |
| Collector script `itflow_metrics_collector.ps1` | imported into RMM script libraries | 7 | 2 |
| Collector token cache `%ProgramData%\ITFlow\metrics` | deployed endpoints keep their token there | 5 | 2 |
| Tactical field `{{agent.itflow_metrics_token}}` | created by admins in their RMM | 4 | 2 |
| In-app backup names `itflow_<14 digits>_(manual\|auto).zip` | globbed, pruned and regex-checked | 11 | 6 |
| localStorage `itflow.sidebar.folded`, `itflow.training.transcript.showRevoked` | saved per-user preferences | 3 | 3 |
| BroadcastChannel `'itflow-training'` | cross-tab sync while old and new cached JS mix | 5 | 3 |
| Lock / temp / scratch prefixes `itflow_*` (cron, training) | old and new runs must not overlap | 14 | 11 |
| Temp placeholders `itflow-(docx\|html\|pdf)-media-`, `itflow-pdf-`, `itflow-trpdf` | internal, never shown | 5 | 4 |
| Ledger actor `itflow-training-worker` | written into the hash-chained ledger | 1 | 1 |
| Android application id `com.foleyit.itflow(.beta)` | the companion app (separate repository) | 3 | 2 |
| Parser placeholder `itflow-guest@example.com` | stored on parsed rows | 2 | 1 |
| Env aliases `ITFLOW_DB_PASSWORD`, `ITFLOW_ADMIN_PASSWORD` | deprecated, still honoured | 11 | 4 |
| Docker volume key `itflow_db_data` | the database lives in it | 6 | 3 |
| Docker / installer DB name and user default `itflow` (and backups named `backup-itflow-…` after it) | existing stacks | 13 | 5 |
| cron.d names `/etc/cron.d/itflow` (Cron Manager), `itflow-<domain>`, `mw-itflow-*`, `itflow-beta` | installed on existing boxes | 5 | 5 |
| Log files `/var/log/itflow-*.log`, `/var/log/itflow_mw_*.log`, `itflow_rmm_sync.log` | cron/logrotate on existing boxes | 21 | 14 |
| systemd `itflow-backup.service` / `.timer` | enabled on existing boxes | 14 | 4 |
| Passphrase `/etc/itflow/backup-passphrase` | existing boxes | 15 | 3 |
| fail2ban `jail-itflow.local`, `jail.d/itflow.local`, `[itflow-auth]` | existing boxes | 23 | 4 |
| Drop-ins and snippets `99-itflow-hardening.*`, `51-itflow-unattended-upgrades`, `itflow-rate-limit.conf` / zone `itflow_login`, `itflow-locations.conf`, in-image `itflow.conf` / `zz-itflow-overrides.ini` | existing boxes; vhosts reference the zone | 23 | 7 |
| Live-box hosts and paths (`mw-itflow.foleyit.com`, `itflow.foleyit.com`, `beta-itflow.foleyit.com`, `/var/www/…`) | server facts | 21 | 13 |
| Database names (`midwest_itflow`, `midwest_itflow_scratch` / `_verify`, `itflow_beta`) | server facts | 4 | 3 |
| Example second-install DB name `itflow2` | database names are never renamed | 1 | 1 |
| Android repository path in `scripts/check_openapi_drift.sh` | separate repository | 1 | 1 |
| Repository URL `TheTractorHacker/ITFlow-Internal-IT` | the real repository until the project moves | 24 | 8 |
| Clone directory `ITFlow-Internal-IT` | what `git clone` creates today | 6 | 2 |
| Upstream links `itflow-org/itflow` | attribution | 9 | 5 |
| MSP fork `TheTractorHacker/itflow` | attribution | 2 | 2 |
| Telemetry endpoint `telemetry.itflow.org` | opt-in, upstream, labelled as such | 8 | 6 |
| `docs.itflow.org` links | upstream docs, labelled as such | 4 | 4 |
| `db.sql` dump header `itflow_dev` | historical | 1 | 1 |

### Whole-file KEEPs

| File | Lines | Reason |
|---|---:|---|
| `REBRANDING.md` | 153 | migration note (this file) |
| `docs/REDESIGN_ARCHITECTURE_REPORT.md` | 42 | historical (banner on top) |
| `docs/MSP_DESIGN_PORT_PLAN.md` | 32 | historical (banner on top) |
| `CHANGELOG.md` (entries before the rename) | 26 | historical: never rewritten |
| `CHANGELOG.md` (the RivetIT entry) | 22 | migration note: describes the rename and the names that were kept |
| `PROGRESS.md` | 19 | historical (banner on top) |
| `ITFlow_Internal_IT_Modernization_Master_Plan.md` | 16 | historical (banner on top) |
| `NOTICE` | 14 | attribution / licensing |

### User-visible KEEPs

These still show "itflow" to someone, on purpose:

- **Admin > Cron Manager:** the heading names `/etc/cron.d/itflow`, the file it writes through sudo. The job lines use the sibling install path `/var/www/itflow.foleyit.com` (see Follow-ups).
- **Admin > Debug / About:** the "Based on ITFlow" credit row, the "upstream ITFlow guide" link for error logs, the Source row (the repository URL `…/ITFlow-Internal-IT`), and the server facts it reports: host name, web root and database name.
- **Settings > Telemetry, the setup wizard and `setup_cli.php`:** they say telemetry goes to the upstream ITFlow project (`telemetry.itflow.org`) and link its docs, labelled as upstream.
- **Settings > Notifications:** the Android package name `com.foleyit.itflow`, which must match the real app.
- **Training settings > Worker:** the log path `/var/log/itflow_mw_training_worker.log` and the cron file `/etc/cron.d/mw-itflow-training-worker`.
- **Mail:** processed mail still goes into the `ITFlow` folder of every monitored mailbox. The Microsoft 365 steps on Admin > Mail name it and say why, and the parser's NDR log line names it.
- **Backups:** Admin > Backup and the setup restore list files named `itflow_<timestamp>_<manual|auto>.zip`.
- **Links to the repository:** the footer "Source" link, Admin > Update and the README point at `…/TheTractorHacker/ITFlow-Internal-IT` until the project moves.
- **Outside the app:**
  - Odoo lines carry the `[ITFLOW:…]` marker, and lines written before the rename say "Record of truth: ITFlow".
  - Stripe shows the `itflow_*` metadata keys.
  - Webhook receivers get `X-ITFlow-*` next to `X-RivetIT-*`.
  - RMM admins see `itflow_metrics_collector.ps1`, `{{agent.itflow_metrics_token}}` and `%ProgramData%\ITFlow\metrics`.
  - Authenticator entries and home-screen kiosk apps created before the rename keep their old names.
- **Live data, not code:** the "Docs → https://docs.itflow.org" custom link on older installs, including the live one (see Follow-ups).

### Listed individually

Every other line that names the old product or the upstream project in prose, as opposed to an identifier, is listed here with its reason. Lines holding both prose and an identifier are listed too.

| File:line | Reason | Why |
|---|---|---|
| `.env.example:10` | migration note | explains the kept DB defaults |
| `.env.example:12` | compatibility | volume key |
| `CONTRIBUTING.md:21` | migration note | contributor rule: keep the legacy identifiers |
| `CONTRIBUTING.md:43` | attribution | license and credit |
| `README.md:47` | attribution | lineage / former name |
| `README.md:183` | repository | clone directory note |
| `README.md:236` | migration note | link to REBRANDING.md |
| `README.md:254` | attribution | Credits |
| `README.md:255` | attribution | Credits |
| `README.md:256` | attribution | MSP fork credit TheTractorHacker/itflow |
| `README.md:259` | attribution | Credits (former name) |
| `README.md:260` | migration note | Credits -> REBRANDING.md |
| `README.md:261` | attribution | Credits (independence statement) |
| `README.md:262` | attribution | upstream credit itflow-org/itflow |
| `SECURITY.md:8` | upstream reference | upstream security policy for shared code |
| `SECURITY.md:25` | upstream reference | report upstream issues upstream |
| `admin/database_updates.php:1998` | historical | upstream forum bug reference in a migration comment |
| `admin/database_updates.php:3949` | historical | upstream migration comment |
| `admin/database_updates.php:5751` | historical | upstream migration comment |
| `admin/database_updates.php:7428` | historical | migration comment (written before the rename; migrations are history) |
| `admin/debug.php:518` | upstream reference | upstream ITFlow log-gathering guide, labelled "upstream ITFlow guide" |
| `admin/debug.php:552` | attribution | About/debug "Based on ITFlow" credit row |
| `admin/post.php:29` | historical | upstream example URL in a comment |
| `admin/post/update.php:273` | upstream reference | upstream telemetry endpoint (opt-in, labelled as upstream) |
| `admin/settings_mail.php:608` | compatibility | Microsoft 365 setup step names the kept "ITFlow" processed-mail folder and says why |
| `admin/settings_telemetry.php:18` | upstream reference | says telemetry goes to the upstream ITFlow project |
| `admin/settings_telemetry.php:29` | upstream reference | docs of the upstream telemetry service the data goes to, labelled |
| `agent/js/training_video_frame.js:7` | compatibility | BroadcastChannel name |
| `agent/js/training_video_frame.js:35` | compatibility | BroadcastChannel name |
| `agent/training_video_frame.php:18` | compatibility | BroadcastChannel name |
| `api/v1/auth.php:260` | compatibility | comment on the kept salt |
| `api/v1/client_tabs.php:57` | false positive | "edit flows" |
| `api/v1/clients.php:29` | false positive | "edit flows" |
| `api/v1/search.php:55` | false positive | "edit flows" |
| `cron/backup_cron.php:7` | server / deploy name | live-box topology comment |
| `cron/backup_cron.php:44` | migration note | explains a kept name / the former name |
| `cron/cron.php:1331` | upstream reference | upstream telemetry endpoint (opt-in, labelled as upstream) |
| `cron/cron.php:1334` | historical | commented-out upstream log line |
| `cron/cron.php:1364` | migration note | explains a kept name / the former name |
| `cron/cron.php:1469` | compatibility | comment on the kept webhook headers |
| `cron/ticket_email_parser.php:452` | compatibility | NDR log text naming the mail folder, which is still called "ITFlow" |
| `cron/ticket_email_parser.php:577` | migration note | explains why the mail folder keeps its name |
| `cron/ticket_email_parser.php:579` | compatibility | mailbox folder name "ITFlow" (renaming creates a second folder in every mailbox) |
| `cron/ticket_email_parser.php:691` | compatibility | comment naming the kept "ITFlow" mail folder |
| `cron/ticket_email_parser.php:693` | compatibility | commented-out log line naming the kept "ITFlow" mail folder |
| `cron/ticket_email_parser.php:795` | compatibility | comment naming the kept "ITFlow" mail folder |
| `cron/ticket_email_parser.php:800` | compatibility | mailbox folder name "ITFlow" (renaming creates a second folder in every mailbox) |
| `cron/ticket_email_parser.php:809` | compatibility | mailbox folder name "ITFlow" (renaming creates a second folder in every mailbox) |
| `cron/ticket_email_parser.php:814` | compatibility | exception text naming the kept "ITFlow" mail folder |
| `css/itflow_motion.css:308` | compatibility | comment naming the kept localStorage key |
| `db.sql:4` | historical | fresh-install schema dump header |
| `deploy/README.md:23` | migration note | explains the kept server-level names |
| `deploy/README.md:181` | migration note | upgrade path for installs made under the old name |
| `deploy/harden.sh:402` | migration note | explains the byte-compared deployed text |
| `deploy/harden.sh:407` | server / deploy name | byte-compared deployed text (fail2ban filter heredoc) |
| `deploy/harden.sh:517` | server / deploy name | byte-compared deployed text (unattended-upgrades heredoc) |
| `deploy/harden.sh:522` | server / deploy name | byte-compared deployed text (unattended-upgrades heredoc) |
| `deploy/harden.sh:562` | server / deploy name | byte-compared deployed text (rate-limit heredoc; harden.sh refuses a differing file) |
| `deploy/harden.sh:563` | server / deploy name | byte-compared deployed text (rate-limit heredoc) |
| `deploy/install.sh:559` | server / deploy name | byte-compared deployed text (rate-limit heredoc) |
| `deploy/install.sh:867` | compatibility | env alias |
| `deploy/templates/itflow-backup.service:3` | migration note | explains the kept unit names |
| `deploy/templates/jail-itflow.local:1` | server / deploy name | byte-compared deployed text |
| `deploy/templates/jail-itflow.local:25` | server / deploy name | byte-compared deployed text |
| `deploy/templates/mariadb-hardening.cnf:1` | server / deploy name | byte-compared deployed text |
| `deploy/templates/nginx-vhost.conf.template:78` | server / deploy name | live vhost family |
| `deploy/templates/php-hardening.ini:1` | server / deploy name | byte-compared deployed text |
| `docker/Dockerfile:58` | migration note | explains the kept in-image names |
| `docker/entrypoint.sh:105` | compatibility | env alias |
| `docs/API.md:31` | attribution | lineage sentence (why the API says client) |
| `docs/API.md:546` | upstream reference | upstream behaviour |
| `docs/ARCHITECTURE.md:3` | upstream reference | intro: points to the upstream-differences section |
| `docs/ARCHITECTURE.md:7` | upstream reference | cross-reference to the upstream-differences section |
| `docs/ARCHITECTURE.md:9` | migration note | explains kept identifiers |
| `docs/ARCHITECTURE.md:197` | upstream reference | upstream behaviour |
| `docs/ARCHITECTURE.md:237` | upstream reference | section on upstream differences |
| `docs/ARCHITECTURE.md:239` | attribution | lineage paragraph |
| `docs/ISO27001-COMPLIANCE.md:138` | upstream reference | upstream reference |
| `functions.php:16` | migration note | docblock of appDisplayName() |
| `functions.php:22` | migration note | legacy $config_app_name values mapped to APP_NAME at runtime |
| `functions.php:168` | upstream reference | upstream config.php docs, labelled |
| `includes/branding.php:16` | attribution | brand config credits upstream ITFlow |
| `includes/branding.php:17` | migration note | brand config explains the kept identifiers |
| `includes/branding.php:41` | repository | the configured repository |
| `includes/branding.php:58` | attribution | upstream attribution constant |
| `includes/branding.php:59` | attribution | upstream attribution constant |
| `includes/sla_functions.php:16` | server / deploy name | sibling install DB name in a comment |
| `js/training_common.js:698` | compatibility | BroadcastChannel name |
| `js/training_common.js:705` | compatibility | BroadcastChannel name |
| `scripts/collector/README.md:12` | migration note | explains the kept collector names |
| `scripts/setup_cli.php:109` | compatibility | env alias in --help |
| `scripts/setup_cli.php:146` | compatibility | env alias |
| `scripts/setup_cli.php:483` | upstream reference | CLI telemetry prompt names the upstream receiver |
| `scripts/setup_cli.php:521` | upstream reference | upstream telemetry endpoint (opt-in, labelled as upstream) |
| `setup/index.php:747` | upstream reference | upstream telemetry endpoint (opt-in, labelled as upstream) |
| `setup/index.php:1586` | upstream reference | says telemetry goes to the upstream ITFlow project |
| `setup/index.php:1592` | upstream reference | docs of the upstream telemetry service, labelled |
| `src/KB/MediaToken.php:41` | upstream reference | security rationale: the telemetry key goes to upstream |
| `src/KB/MediaToken.php:50` | migration note | explains why the HMAC context keeps its name |
| `src/Training/Kiosk/Core/KioskKeys.php:11` | compatibility | comment on the kept KDF labels |
| `src/Training/OdooSync/Marker.php:21` | compatibility | comment on the kept marker |
| `src/Training/OdooSync/PayloadBuilder.php:16` | migration note | Odoo lines written before the rename keep their wording |
| `src/Training/Records/CertSecret.php:18` | compatibility | comment on the kept cert-key label |
| `src/Webhooks/WebhookDispatcher.php:71` | compatibility | comment on the kept webhook headers |
