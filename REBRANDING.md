# Rebranding: ITFlow Internal IT → RivetIT

<!-- INTEGRATOR: first draft written by lane D. Complete the sections marked INTEGRATOR once lanes A-D are
     merged: confirm the lane A/C details (updater constant name, $config_app_name handling, webhook header
     duplicates, env-var aliases in scripts/setup_cli.php) and paste the final classified search into
     "Remaining itflow matches". -->

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

## Environment variables

| Variable | Status | Read by |
|---|---|---|
| `RIVETIT_<CONSTANT>` (e.g. `RIVETIT_APP_NAME`, `RIVETIT_APP_REPO_URL`) | New. Overrides any constant in `includes/branding.php` when no earlier `define()` exists; empty means "use the default". | `includes/branding.php` |
| `RIVETIT_APP_NAME`, `RIVETIT_APP_REPO_URL`, `RIVETIT_APP_WEBSITE_URL`, `RIVETIT_APP_DOCS_URL`, `RIVETIT_APP_SUPPORT_URL` | Passed from `.env` into the app container by `docker-compose.yml` (others can be added the same way). | Docker Compose |
| `RIVETIT_DB_PASSWORD`, `RIVETIT_ADMIN_PASSWORD` | New, preferred. Installer secrets passed in the environment instead of on argv. | `scripts/setup_cli.php` |
| `ITFLOW_DB_PASSWORD`, `ITFLOW_ADMIN_PASSWORD` | **Deprecated, still honoured.** Existing automation keeps working. `deploy/install.sh` and `docker/entrypoint.sh` set both names. No removal date; remove only in a release that announces it. | `scripts/setup_cli.php` |
| `RIVETIT_CONTAINER_PREFIX` | New. Container-name prefix (default `rivetit`), only needed to run two stacks on one Docker host. | `docker-compose.yml` |

<!-- INTEGRATOR: confirm lane C's setup_cli.php reads RIVETIT_* first, then ITFLOW_*, treats an empty value as
     unset, and does not refuse when both are set (install.sh / entrypoint.sh set both to the same value). -->

## Updater source

- **Admin > Update** runs `git fetch fork` and compares `HEAD` with `fork/<$repo_branch>` (`fetchUpdates()`
  in `functions.php`, `admin/update.php`). The `fork` remote is this repository,
  `TheTractorHacker/ITFlow-Internal-IT`, branch `main` (`$repo_branch` in `config.php`). **The rename did not
  change where updates come from**, and no RivetIT repository exists yet, so nothing points at one.
- The remote name is now a constant, `APP_UPDATE_REMOTE` (default `fork`), so a future move is a
  configuration change: add the new repository as a git remote on each install (or re-point `fork`), then set
  the constant (or `RIVETIT_APP_UPDATE_REMOTE`).
  <!-- INTEGRATOR: confirm the constant name and default from lane A (includes/branding.php) and that lane C's
       fetchUpdates() falls back to 'fork' when it is undefined. -->
- `deploy/update.sh` wraps `scripts/update_cli.php` and uses a plain `git pull` of the checkout's own
  upstream (usually `origin`, the same repository). `deploy/install.sh` clones `REPO_URL`
  (`https://github.com/TheTractorHacker/ITFlow-Internal-IT.git`).
- The "Latest Release" link on Admin > Update used to point at the old MSP fork (`TheTractorHacker/itflow`);
  it now follows `APP_REPO_URL`. <!-- INTEGRATOR: lane A. -->

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
| Webhook headers `X-ITFlow-Signature`, `X-ITFlow-Event` | `cron/cron.php`, `src/Webhooks/WebhookDispatcher.php` | Receivers verify them. RivetIT-named duplicates may be added alongside, never instead. <!-- INTEGRATOR: record whether lane C added X-RivetIT-* duplicates. --> |
| Stripe metadata `itflow_invoice_id`, `itflow_client_id`, `itflow_client_name`, `itflow_invoice_number` | payment code in `agent/`, `client/`, `guest/`, `cron/` | Read back by the payment webhook, including for payments created before the rename. |
| KB media HMAC context `itflow.kb_media.v1` | `src/KB/MediaToken.php` | Every signed KB media URL already issued (web, portal, Android). |
| Metrics wire tag `itflow.metrics.v1`, collector `itflow_metrics_collector.ps1`, token cache `%ProgramData%\ITFlow\metrics\`, Tactical field `{{agent.itflow_metrics_token}}` | `api/v1/metrics_ingest.php`, `scripts/collector/` | Deployed collectors send the tag and keep their token there; renaming forces every endpoint to re-enrol. |
| localStorage `itflow.sidebar.folded`, `itflow.training.transcript.showRevoked`; BroadcastChannel `itflow-training` | `js/shell.js`, `js/training_common.js`, `agent/js/` | Saved per-user preferences, and cross-tab sync between old and new cached JS during a rollout. (Session and cookie names such as `PHPSESSID`, `rememberme`, `user_encryption_session_key` carry no brand and are unchanged.) |
| WebAuthn `rp.id`, TOTP secrets | `agent/user/` | Only the display name (`rp.name`, otpauth label) says RivetIT; `rp.id` never changes. |
| Android package `com.foleyit.itflow(.beta)` | `.well-known/assetlinks.json`, notification settings | The companion app's application id (separate repository). |
| Ledger actor `itflow-training-worker` | `src/Training/Automation/WorkerCtx.php` | Written into the hash-chained training ledger; kept for uniform history. |
| Temp and lock names `itflow_*_cron_*`, `itflow_mail_queue_*`, `itflow_training_*`, `itflow-*-media-*` | `cron/`, `src/Training/`, `src/KB/` | A rename mid-rollout lets an old and a new run overlap and orphans scratch directories. |
| Parser placeholder `itflow-guest@example.com` | `cron/ticket_email_parser.php` | Internal placeholder address. |

### Files, folders and server names

| Identifier | Where | Why it stays |
|---|---|---|
| In-app backup files `itflow_<14 digits>_(manual\|auto).zip` | `admin/post/backup.php`, `admin/backup.php`, `cron/` | Globbed for the list and pruning, regex-checked for download; renamed files would vanish from the list and never be pruned. (The text inside `version.txt` / the dump header may say RivetIT.) |
| `deploy/backup.sh` archives `backup-<DB_NAME>-<ts>.tar.gz.enc` and `backup-manifest.json` | `deploy/` | Named after the database; `restore.sh` parses the manifest. Docs examples say `backup-itflow-…` because `itflow` is the Docker default database name. |
| Mail folder `ITFlow` | `cron/ticket_email_parser.php` | Processed mail is moved there in every monitored mailbox; a rename would silently create a second folder. |
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
not redirected. <!-- INTEGRATOR: confirm the Settings > Telemetry wording (lane A) says the data goes to the
upstream ITFlow project. -->

## Follow-ups (not part of the rename)

- **Android companion app** (separate repository): rename the app and its strings; keep the package id and
  every API contract listed above.
- **`$config_app_name`** lives in each install's `config.php` (setup wrote `ITFlow Internal IT`) and is used in
  e-mail subjects and bodies. <!-- INTEGRATOR: record how lane C maps the legacy default to APP_NAME at runtime. -->
- Live data that only changes through the app: a setup-seeded custom link "Docs → docs.itflow.org" may still
  exist in `custom_links` on older installs.
- The mail folder name could become a setting (then it can say RivetIT on new installs).
- Hard-coded company-specific values that are not product branding: the kiosk PWA name ("Midwest Training"
  in `kiosk/manifest.json`), `https://foleyit.com/ticket-terms` on the signature pages.
- Enable GitHub private vulnerability reporting on the repository, which `SECURITY.md` and the issue
  templates rely on.
- Pre-existing, unrelated to the rename: `deploy/install.sh` writes `/etc/nginx/conf.d/itflow-rate-limit.conf`
  with different comment lines than `deploy/harden.sh` expects, so `harden.sh` (step 7, nginx) refuses that
  file on a box built by `install.sh` unless run with `--skip-nginx`.

## Remaining `itflow` matches

<!-- INTEGRATOR: after merging A-D, run
       git grep -n -i -I -E "itflow|it flow" -- ':!vendor' ':!plugins'
     and classify every match below as KEEP (with one of: compatibility identifier, attribution, license,
     historical, upstream reference, server-level name, repository URL) or fix it. The audit
     (scratchpad rivetit/audit.md, sections 3, 4 and 6) is the starting point. Lane D's residue is below. -->

Lane D files (docs, deploy, Docker, repository), after the rename:

| File | Matches kept | Reason |
|---|---|---|
| `README.md`, `SECURITY.md`, `composer.json`, `docs/DEPLOYMENT.md` | repository URL `TheTractorHacker/ITFlow-Internal-IT` and the clone directory | repository URL (see Repository and links) |
| `README.md`, `NOTICE`, `CONTRIBUTING.md`, `CHANGELOG.md` (new entry), `docs/API.md`, `docs/ARCHITECTURE.md`, `docs/ISO27001-COMPLIANCE.md`, `SECURITY.md` | ITFlow, itflow-org, TheTractorHacker/itflow, "ITFlow Internal IT" | attribution / upstream reference / former name |
| `composer.json`, `docs/ARCHITECTURE.md`, `CONTRIBUTING.md` | `ITFlow\` namespace, `itflow_*` names | compatibility identifier |
| `CHANGELOG.md` (entries before the rename), `PROGRESS.md`, `ITFlow_Internal_IT_Modernization_Master_Plan.md`, `docs/MSP_DESIGN_PORT_PLAN.md`, `docs/REDESIGN_ARCHITECTURE_REPORT.md` | all | historical |
| `db.sql` | dump header `itflow_dev` | historical |
| `docker-compose.yml`, `.env.example`, `docker/Dockerfile`, `docker/entrypoint.sh` | `itflow_db_data`, `DB_NAME`/`DB_USER` default `itflow`, backup example, in-image file names, `ITFLOW_DB_PASSWORD` alias, `/var/log/itflow-restore.log` | compatibility identifier / server-level name |
| `deploy/*.sh`, `deploy/lib/common.sh`, `deploy/templates/*`, `deploy/README.md` | log, cron, unit, passphrase, fail2ban, nginx zone and drop-in names; byte-compared deployed text; `ITFLOW_*` aliases; `_ITFLOW_TMPFILES`; live-box host names; repository URL | server-level name / compatibility identifier |
