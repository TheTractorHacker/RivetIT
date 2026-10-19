# Built-in endpoint agent / RMM module in RivetIT

Status: since DB 2.6.150 the server side of the endpoint agent is the **RMM module of RivetCore** (`rivet/rivet-core` >= 1.0.0-rc.5, namespace
`RivetCore\Rmm`). RivetIT keeps the feature, the tables, the URLs and the behaviour; the PHP that implements it (and the Windows agent, `endpoint-agent/`)
no longer lives here. This page covers only what is RivetIT's own: how it is wired, the permission mapping, the module switch, operations and tests.

Where the rest is:

| Topic | Document (in the rivet-core repository) |
| --- | --- |
| What the module is, its contracts, key classes, behaviour | `docs/modules/rmm.md` |
| Wire protocol (enroll, check-in, jobs, update, installer), credential formats, signing, error codes | `docs/rmm/PROTOCOL.md`, `docs/rmm/openapi-device.yaml` |
| Module switch, state file, load shedding, capacity | `docs/rmm/CAPACITY.md` |
| Building and releasing the Windows agent | `docs/rmm/AGENT_BUILD.md`, `endpoint-agent/README.md` |
| Features, scaling, asset page redesign | `docs/rmm/FEATURES.md`, `docs/rmm/SCALING.md`, `docs/rmm/ASSET_PAGE_REDESIGN.md` |
| Design and decisions | `docs/design/endpoint-module-extraction.md`, `docs/architecture/ADR-010-endpoint-agent-module.md` |

## 1. How RivetIT wires it

| Piece | File |
| --- | --- |
| Composition root: builds `RivetCore\Rmm\RmmModule` from the adapters below | `includes/rmm_bootstrap.php` (`rivetRmmModule()`, `rivetRmmEnabled()`, `rivetRmmSyncState()`, `rivetRmmHousekeeping()`, `rivetRmmRequest()`) |
| Adapters for the Core contracts | `src/Core/Adapter/Endpoint/`: `EndpointTenancy` (clients, locations, department scope), `EndpointAssets` (`assets`, `asset_interfaces`), `EndpointBridge` (`rmm_integrations`, `asset_rmm_links`, `rmm_alerts`, `rmm_scripts`, `rmm_remote_sessions`, ticket auto-close), `EndpointSecretBox` (`encryptSetting` format, unchanged), `EndpointAudit` (the `logs` rows and the audit trail), `EndpointMetricSink` (`MetricIngestService` into `device_metric_samples`), `EndpointAccessPolicy` (the nine `rmm.*` abilities), `EndpointModuleState` |
| Device REST bridges (URLs and file names frozen: enrolled agents depend on them) | `api/v1/agent_enroll.php`, `agent_checkin.php`, `agent_jobs.php`, `agent_update.php`, `agent_installer.php`, and (RivetCore 1.0.0-rc.11) `agent_transfer.php` and `agent_logs.php`, all via `api/v1/includes/agent_device_api.php` (`ea_dispatch()`) |
| Technician REST API (user API token; the legacy shared key is refused) | `api/v1/endpoint_devices.php` |
| Pre-bootstrap gate | `api/v1/rmm_gate.php`, the first include of `api/v1/index.php` |
| Administration page and its POST handler | `admin/settings_endpoint_agent.php`, `admin/post/settings_endpoint_agent.php` |
| Device page and its JSON actions | `agent/rmm_agent_device.php`, `agent/post/rmm_agent.php`, the endpoint-agent branch of `agent/post/rmm_remote.php` |
| Housekeeping | the "built-in endpoint agent" block of `cron/cron.php` (`rivetRmmHousekeeping()`), job handlers through `rivetRegisterJobHandlers()` |
| Publishing a binary from the shell | `scripts/endpoint_agent_publish.php` (a wrapper around `BinaryStore::publish()`) |

Everything between the edition and the module is data: Core ships read models and validated operations (`RmmReadModel`, `RmmAdmin`,
`TechnicianActions`), RivetIT renders its own pages and keeps CSRF, session, flash messages and navigation.

## 2. The module switch

Administration > Settings > Endpoint agent has an **RMM module** card: the master switch (`endpoint_agent_settings.enabled`, the same column the
"Turn on the endpoint agent service" checkbox below it has always written). Default: **off** on a fresh install; an existing install keeps the value it
has (the 2.6.150 step never touches it and never infers it from rows). Switching on for the first time mints the instance signing key and the
integration row, and offers a feature preset (Light, Standard; default = what RivetIT has always done: monitoring, metrics, jobs, updates, and remote
sessions when MeshCentral is enabled).

While the module is **off**:

* the device endpoints (`agent_enroll`, `agent_checkin`, `agent_jobs`, `agent_update`, `agent_installer`, `agent_transfer`, `agent_logs`) answer `503 module_disabled` with
  `Retry-After: 3600` and `Cache-Control: no-store`, **from `api/v1/rmm_gate.php` before
  `config.php` is loaded**: no database connection, no query, no Core class (proved by `tests/endpoint_agent_module.php`); `endpoint_devices` is not answered by the gate (401 without a token, then `404 {"code":"disabled"}`, so an anonymous caller learns nothing). Enrolled agents keep their
  credential and data and back off to about one attempt an hour; nothing is deleted, and switching on again resumes everything;
* the agent device page shows a "module is off" notice, the asset page hides its "Agent device" button, queued ingest handlers are not registered and the
  cron housekeeping block does nothing (one primary-key SELECT). The administration page stays reachable: it is where the switch is.

The gate reads a small JSON state file, `backups/rmm-state/rmm_state.json` (the denied `backups/` area; mode 0640, written atomically). It is a cache of
the database: a missing, garbled or wrong-version file means "unknown", never "off", and the request goes on the normal path. The file is rewritten
whenever the switch or a limit changes (every `RmmSettings::set`), by the 2.6.150 update step, when the administration page is opened, and by the cron
block when it disagrees with the settings row (so a restored backup is corrected within one cron tick). Override the directory with
`define('RMM_STATE_DIR', '/path')` in `config.php` (an empty string switches the fast path off) and, because the gate cannot read `config.php`, set the
same path as `RMM_GATE_STATE_DIR` in the web server environment (nginx `fastcgi_param`, Apache `SetEnv`).

One deliberate difference from Core's own default: the bridges run `DeviceApi` with `DISABLED_COMPAT` (rc.5 option; it also re-creates a missing state file on the next request), so a service that is disabled **in the database**
but has no state file still answers `403 forbidden` exactly as RivetIT always did (the golden transcripts and the acceptance suites pin that). Once the
state file exists - which every switch change, the update step and the cron block guarantee - the answer is the new `503 module_disabled`.

## 3. Permissions

No new permission keys; the fifteen Core abilities map onto the existing RMM module grants in `EndpointAccessPolicy`, with the rules the old
`ITFlow\EndpointAgent\Authz` applied (Administrator and Technician behaviour is unchanged):

| Ability | Needs |
| --- | --- |
| `rmm.device.view` (devices, checks, job history) | `module_rmm` >= 1, and the device's department (below) |
| `rmm.job.run_saved` (saved library script, collect, cancel, job output) and `rmm.job.reboot` | view, `module_rmm_scripts` >= 2, not a module-only login |
| `rmm.job.run_script` (free-form PowerShell) | view, `module_rmm_scripts` >= 3, not a module-only login |
| `rmm.remote.launch` | view, `module_rmm_remote_connect` >= 1, not a module-only login |
| `rmm.device.manage`, `rmm.token.manage`, `rmm.binary.publish`, `rmm.admin` | administrator (`role_is_admin`) |
| `rmm.remote.manage` (RivetCore 1.0.0-rc.11: service and process managers, downloading a file from a device, the Mesh terminal and files views) | administrator; a role with `module_rmm_scripts` >= 3 **and** `module_rmm_remote_connect` >= 1 only while Administration > Endpoint agent > Remote tools has "Level 3 script users with remote access may use the remote tools" on (`settings.config_rmm_remote_manage_lvl3`, off by default); never a module-only login. Sending a file **to** a device and the remote-tools settings stay `rmm.admin` (administrators only), reading collected logs needs `rmm.device.view` + `rmm.job.run_saved`, log rules `rmm.alert.manage` |

| `rmm.patch.view` (RivetCore 1.0.0-rc.20: patch status, history, compliance, policies) | like `rmm.device.view`: `module_rmm` >= 1 (a module-only login may read) |
| `rmm.patch.manage` (scan, approve, decline, dry run, approval rules, rings and patch policies) | like `rmm.job.run_saved`: view, `module_rmm_scripts` >= 2, not a module-only login. Rings and patch policies apply to every department, so Core also requires access to every department for them; approval rules of a restricted user are limited to their own departments |
| `rmm.patch.install` (a real installation) | view, `module_rmm_scripts` >= 3, not a module-only login: stronger than reboot on purpose, so a level 2 script technician approves and dry-runs but cannot install. A restart after patching also needs `rmm.job.reboot`; the second person who approves a request needs `rmm.job.approve` |
| `rmm.software.view` (RivetCore 1.0.0-rc.21: the catalog, assignments, deployment state, history and report) | like `rmm.device.view`: `module_rmm` >= 1 (a module-only login may read) |
| `rmm.software.manage` (edit the catalog, ask a device what it has, a dry run) | like `rmm.job.run_saved`: view, `module_rmm_scripts` >= 2, not a module-only login. The catalog is fleet-wide, so Core also requires access to every department to change it |
| `rmm.software.deploy` (assignments, a real install or removal, deciding a software approval) | view, `module_rmm_scripts` >= 3, not a module-only login: stronger than reboot on purpose (same bar as `rmm.patch.install`), so a level 2 script technician edits the catalog and dry-runs but cannot deploy. The allowed installer host list and lowering a package's two-person flag stay `rmm.admin`; the second person who approves a request needs `rmm.job.approve` |

An inactive account (disabled, archived, not an agent user) is denied everything. Department access (`EndpointTenancy::visibleClientIds()`):
administrators and users with no `user_client_permissions` row see every department; otherwise only the listed ones, plus devices without a department.
A device outside the caller's departments is a 404, indistinguishable from a missing one. Module-only (limited) logins may view but never run, reboot or open
a remote session. The reason strings of a denial are Core's generic ones ("Your role cannot run jobs on devices."); the REST `code` values are unchanged.

## 4. Operations

* **Agent binaries.** The Windows agent is built and released from the rivet-core repository (`endpoint-agent/`, tags `agent-v*`, GitHub Releases of
  rivet-core). Download the executables from there and upload them under Administration > Endpoint agent > Agent binaries, or on the server:
  `sudo -u www-data php scripts/endpoint_agent_publish.php rivetit-agent-windows-amd64.exe --version 1.2.0 --arch amd64 --activate [--release pilot --rollout 10]`.
  Files are stored under `backups/endpoint-agent/` (or `EA_BINARY_DIR` in `config.php`) with random names; the shipped nginx rules and `.htaccess` deny that
  directory over HTTP. The previous RivetIT-hosted agent release (`agent-v0.1.0-beta.1` on this repository) remains as history.
* **config.php constants** (all optional): `EA_ALLOW_INSECURE_HTTP` (loopback test servers only), `EA_BINARY_DIR`, `EA_TRANSFER_DIR` (file transfers, default `backups/endpoint-transfer`), `EA_BINARY_MAX_BYTES`,
  `EA_ALLOW_NON_WINDOWS` (the agent's Linux test build in an integration harness, never production), `RMM_STATE_DIR`.
* **nginx / PHP-FPM.** `client_max_body_size` at least 1m for `/api/v1/agent_checkin`; PHP-FPM must pass `HTTPS` (or the proxy sets
  `X-Forwarded-Proto: https` from a private peer). The live nginx rules are not changed by this feature. The state directory is below `backups/`, which
  the shipped rules already deny.
* **Transfer folder (rc.11).** Files copied from or to a device are stored in `backups/endpoint-transfer/` (or `EA_TRANSFER_DIR`): mode 0700, private to the web user, inside the `backups/` area that
  every shipped nginx template and `.htaccess` denies (`tests/nginx_private_paths.php` pins that), named `xfer_<random>.bin`, deleted when the transfer expires (`transfer_ttl_s`, 24 hours by default) by the cron
  housekeeping. It is not part of the in-app backup or `deploy/backup.sh` (transient data). DB step 2.6.161 creates it when the process running the update owns `backups/`; otherwise the first transfer creates it
  (as the web user) or Administration > Endpoint agent > File transfer shows its state with a "Create folder" button. Give it a disk with room for `transfer_store_max_bytes` (1 GiB by default).
  nginx: the agent sends chunks of at most 1 MiB, so `client_max_body_size` must be at least 2m for `/api/v1/agent_transfer` (the shipped templates use 500M; a hand-written vhost with the 1m default would
  refuse full chunks). The live nginx rules are not changed by this feature.
* **Several queue workers (rc.11, scale S6).** `RivetCore\Jobs\JobClaimer` makes claiming safe for any number of workers: `SELECT ... FOR UPDATE SKIP LOCKED` on MariaDB 10.6+ and MySQL 8.0.1+, a conditional-UPDATE
  lease on older servers (either way a job is never run twice), with an exact per-queue limit (`ingest_max_running`). `cron/integration_worker.php` still runs ONE worker by default (its cron guard); to run N, add N-1
  more cron lines with their own number: `* * * * * php /path/cron/integration_worker.php --worker=2` ... `--worker=N`. How many: the Performance and capacity panel (Endpoints > Agent Fleet) shows the autoscaling hint
  (`CapacityReport::build()['autoscale']`: queue depth, jobs per second, recommended workers). It matters only when `ingest_mode` is `queued` (the module's load-shedding switches to it under load);
  with sync ingest the check-in writes directly. Housekeeping takes back the jobs of a worker that died after a two-minute lease.
* **Cron (rc.11).** Nothing new to schedule: the "built-in endpoint agent" block of `cron/cron.php` calls `Housekeeping::run()`, which now also prunes collected logs (retention, per-device and fleet caps, in batches
  of 2000), recovers log-rule alerts, deletes expired transfers, maintains time partitions when partitioning is on, prunes the read cache table, reclaims dead workers' `rmm.ingest` jobs and flushes the external
  metric buffer when that is on. Keep the cron's PHP time limit above 30 seconds (the CLI default is unlimited). RivetIT writes device metrics to its own `device_metric_*` tables, so Core's daily metric rollup is
  not used here (housekeeping skips it); `cron/metrics_rollup.php` keeps doing that job.
* **Redis (rc.11).** When Redis answers, the read cache (fleet counters 15 s fresh / 5 min stale, the active releases) and the per-client token buckets use it, shared by every web worker and the cron
  (`EndpointRedisProvider`); both fail open when it is down and then Core uses its own tables. A writer that changes `endpoint_agent_releases` or `endpoint_agent_devices` directly must call
  `rivetRmmModule()->updates()->releasesChanged()` / `->cache()->invalidate('fleet', true)` (Core's own writers do).
* **Updating.** `Update Database` (2.6.150) runs Core's migration runner. Migrations 0014 and 0015 are `CREATE TABLE IF NOT EXISTS` / guarded `ALTER`s that
  change nothing on an install that has the tables; 0016 adds five columns (`features_json`, `limits_json`, `shed_level`, `ingest_mode`, `max_devices`) to
  `endpoint_agent_settings` with defaults that reproduce today's behaviour. Run `composer install --no-dev` first (the in-app Update and
  `deploy/update.sh` do) so rivet-core 1.0.0-rc.5 is present: without it the step waits and retries.

## 5. Tests

Automated, scratch database only (a name containing `scratch`; real HTTP against `php -S`; see the header of `tests/endpoint_agent_lib.php`). Run one suite at
a time; each wipes its own rows.

```
RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=scratch_x RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... RIVETIT_REDIS_PORT=<throwaway redis> php tests/endpoint_agent_enroll.php
... endpoint_agent_checkin.php | endpoint_agent_installer_ui.php | endpoint_agent_jobs.php | endpoint_agent_authz.php | endpoint_agent_migration.php | endpoint_agent_deploy_http.php
php tests/endpoint_agent_deploy_unit.php               # no database
RIVET_CORE_DIR=/path/to/rivet-core php tests/endpoint_agent_golden.php   # golden HTTP transcripts recorded from the pre-adoption code, replayed against the bridges
php tests/endpoint_agent_module.php                    # module switch, gate with zero queries, fail-safe state file, fresh vs existing install
php tests/rmm_ui.php                                    # asset panel, fleet, Phase 2/3 and (tests/rmm_ui_p68_*.php) the remote tools, logs and scale settings pages
tests/real_agent_proof/run.sh                           # the real Linux agent under systemd against RivetIT's bridges (docker; header says how)
RIVETCORE_PHPUNIT_AUTOLOAD=/path/to/rivet-core/vendor/autoload.php RIVETCORE_TEST_DB_NAME=scratch_x ... phpunit -c tests/core/phpunit.xml   # Endpoint*ConformanceTest = Core's adapter kit
php tests/load/agent_ingest_load.php 200 10 16 8        # load and cost measurement
```

`config.php` of the scratch install must define `EA_ALLOW_INSECURE_HTTP` (for the 426 transcripts the golden runner starts a second server with
`EA_TEST_NO_INSECURE=1`: `if (getenv('EA_TEST_NO_INSECURE') !== '1') { define('EA_ALLOW_INSECURE_HTTP', true); }`) and `$config_enable_setup = 0`.
`tests/support/endpoint_compat.php` keeps the old `ITFlow\EndpointAgent\*` names as thin forwards to `RivetCore\Rmm` so the suites, which seed rows and call
pure helpers in-process, run unchanged. The suites need `RMM_STATE_DIR` unset or empty in the scratch `config.php` unless they set it themselves
(`endpoint_agent_module.php` does, through the `RMM_TEST_STATE_DIR` environment variable the scratch config may honour).

Manual end to end: switch the module on, create a token for a department, install the agent with it, watch the device appear (linked, or in the approval
queue), check the asset's RMM card and Performance tab, make a check fail three times and confirm one alert and one ticket, recover it, queue a harmless
PowerShell job and read its output, queue a reboot, revoke the device and confirm check-in returns 401 `revoked`, map a MeshCentral node and launch a session.

## 6. Unverified in this environment

A real MeshCentral server (only a local mock), a real Windows agent and the Windows installer on a Windows host, and the Linux agent against this
exact build (run `endpoint-agent/e2e/run_e2e.sh` from rivet-core with `RIVETIT_E2E_SERVER_URL` against a scratch install). The wire format is pinned by the golden
transcripts, the signing and installer vectors, and Core's own tests; enrolled agents need no re-enrollment.

## 7. Asset page panel and Agent Fleet (T10a)

Design: rivet-core `docs/rmm/ASSET_PAGE_REDESIGN.md` and the mockup `docs/rmm/mockups/asset-page-app-style.html`. Phase 0 scope: everything on these pages is read from data
the agent already sends; where a view needs data that is not stored, the card says so (it is never drawn from invented numbers).

| Piece | Files |
| --- | --- |
| View-models (read-only; the first call is the module state file) | `includes/rmm_ui.php`: `rivetRmmUiPanel()`, `rivetRmmUiFleet()` |
| HTML | `includes/rmm_ui_render.php`; styles `css/itflow_rmm.css` (`.rmm-*`, on top of the existing `.ifm-*` / `.it-*` rules); behaviour `js/rmm_panel.js` (linked by `includes/footer.php` only when a page rendered the panel) |
| Asset page | `agent/asset_details.php` (agent-linked assets show the panel instead of the vendor card), Performance section = the existing `agent/includes/asset/metrics_tab.php` |
| Fleet page | `agent/rmm_fleet.php` (Endpoints menu, only with the module on) |
| Lazy job output | `agent/rmm_job_output.php` (JSON; `rmm.job.run_saved`; 404 outside the user's departments or with the module off) |
| Actions | unchanged: `agent/post/rmm_agent.php` -> `TechnicianActions` |

**Real data:** status, last check-in, agent version and ring, OS, uptime, reboot flag (device row); CPU, memory, per-volume and network readings (last check-in sample); volume
sizes, hardware and adapters (inventory blob); checks with "steady for / since" and alert links; open and resolved alerts; job history and output; Mesh state and recent
sessions; Performance charts from `device_metric_*` (Metrics subsystem); fleet counts, approvals with reasons, offline/stale lists, rings and versions against the hosted
build, job failures, capacity report. **Empty states, by design:** software (Phase 1) and services (Phase 6) in Inventory, battery (absent unless an agent reports it).
**Not built, needs stored data:** live polling document, per-check history/trend, network bar against the 24 h peak, Activity tab, tags, patches.

**Cost.** The asset panel adds about 25 statements per view for an administrator (about 15 are the policy and tenancy adapters repeating the user and department lookups
for each of five abilities; `deviceView()` reads the device, checks and jobs twice). Module off: zero. Follow-ups in Core: `RmmReadModel::deviceView()` loses its richer
`checks` (the array union keeps `detail()`'s), a single-job and a fleet-wide failed-jobs read model, and a request-scoped memo in the authorizer.

Tests: `php tests/rmm_ui.php` (scratch database, same environment as the other suites; 150+ assertions: view-models, permission matrix, states, escaping, module off with zero
statements, real pages over HTTP) and the browser smoke `tests/browser/rmm_seed.php` + `tests/browser/rmm_smoke.mjs` (see the headers; desktop and 390 px, light and dark,
screenshots in `SMOKE_OUT/shots`).

## 8. Add device / Download installer (T11)

The installer used to be reachable only from Administration > Endpoint agent > Deployment. It is now one dialog, **Add device**, available from three places (each shown only
while the module is on and the user has `rmm.token.manage` or `rmm.admin`; otherwise nothing renders and `js/rmm_installer.js` is not requested):

1. **Endpoints > Agent Fleet**: the primary "Add device" button in the page header (and in the empty state when no device is enrolled).
2. **A department's page**: Department actions (the three dots) > "Download agent installer", with that department fixed.
3. **Endpoints menu > Download installer**: opens `/agent/rmm_fleet.php?installer=1`, which opens the dialog by itself.

What the user does: click **Add device**, pick the department (preselected from `?client_id=` or when only one exists), leave Windows and x64 as they are (ARM64 is one click),
click **Download installer**. The dialog then shows the next steps: copy the file to the PC, double-click it, accept the administrator prompt, and for a deployment tool run
`<file>.exe setup --silent`. Defaults: stable ring, token valid 24 hours, 1 PC; the "several PCs" switch sets 25; Advanced has the lifetime, the PC count, the label and (when the department
has locations) the location. The **Linux** tab creates an audited token and shows the install snippet (`InstallerService::linuxSnippet`) with a copy button; there is no Linux binary to download. (Since 26.10.52 the Linux command downloads and verifies everything itself when the Linux files are recorded, and the full page with the MSI, the silent-install commands and the Linux packages is **Add device / Build installer**: see section 17.)

Server side: `agent/post/rmm_installer.php` (POST + session CSRF only) calls `RmmAdmin::downloadInstaller()` / `deploymentCommands()`, the same Core path and audit entry
("Installer Created") as the Administration card, so permissions, department scope, the stamped file and every refusal are Core's. A refusal creates no token. If no current binary exists for the
chosen architecture, the dialog says so and links to Administration > Endpoint agent > Agent binaries (administrators).

**Publish the agent** (Administration > Endpoint agent > Agent binaries): drag one or both agent `.exe` files into the drop zone. The architecture is read from each file's own headers, the version
from the file name (`rivetit-agent-1.4.0-windows-arm64.exe`; or type it), and the files become current by default. Each file goes through `RmmAdmin::uploadBinary()`. The single-file form is
kept under "Advanced". Fetching the agent from a GitHub release URL was deliberately **not** built: release assets redirect from github.com to another host, so a github.com-only pin cannot hold
without relaxing the SSRF rules, and the upload covers the same need.

Tests: `tests/endpoint_agent_installer_ui.php` (visibility matrix, CSRF/method/permission/module-off refusals, streamed `.exe` with `MZ`, current binary byte for byte, `InstallerStamp` trailer,
file name, token lifetime/uses, audit rows, Linux command, multi-file publish) and the "installer dialog" checks of `tests/browser/rmm_smoke.mjs` (seed with `RMM_SMOKE_BASE_URL=http://127.0.0.1:<port>`,
which also publishes placeholder binaries).

## 9. Remote tools, file transfer and log collection (RivetCore 1.0.0-rc.11, RMM Phases 6 and 8)

Everything here is **off by default** and dormant until an administrator turns it on under Administration > Settings > Endpoint agent (the cards **Remote tools**, **File transfer**, **Log collection**). The
three sub-switches are Core's `remote_tools`, `filetransfer` and `logs` (`RmmSettings::FEATURES`). An agent that does not announce the capabilities (`remote:services`, `remote:processes`, `remote:files`,
`logs:collect`; older agents) is answered exactly as before, and the golden transcripts replay byte for byte (`tests/endpoint_agent_golden.php`, 10 files). Operator guide: rivet-core `docs/rmm/REMOTE_AND_LOGS.md`.

| Piece | Where | Needs |
| --- | --- | --- |
| Device bridges | `api/v1/agent_transfer.php` (GET and POST, raw body of at most 1 MiB, header `X-Transfer-Token`), `api/v1/agent_logs.php` (POST, JSON, at most 200 entries and 256 KiB); routed in `api/v1/index.php` above the bearer parsing; the pre-bootstrap gate (`api/v1/rmm_gate.php`, from Core's rc.11 template) answers both while the module is off | device credential (+ the one-time transfer token) |
| Remote tab of the asset page | `includes/rmm_rem_ui.php`, `agent/post/rmm_remote_tools.php`, `js/rmm_remote.js`: **Services** (list, start, stop, restart, enable, disable; stop/restart/disable ask for a confirmation), **Processes** (list with CPU and memory, end a process by id and name, confirmation), **Files** (the administrator-allowed folders as starting points and a path field, download with the SHA-256 the server verified, upload for administrators with a confirmation and the size caps, the transfer list), **Remote access** (MeshCentral desktop, terminal and files) | module + `remote_tools` (+ `filetransfer` for Files) + `rmm.remote.manage`; upload `rmm.admin`; Mesh also `rmm.remote.launch` |
| Logs | `agent/rmm_logs.php` (fleet search: device, time range, level, source, event id, text, pagination; department scoped by Core), the **Logs** tab of the asset page, `includes/rmm_log_ui.php` | module + `logs` + `rmm.device.view` + `rmm.job.run_saved` |
| Log rules | `agent/rmm_log_rules.php`, `agent/post/rmm_logs.php`: phrase rules (never a regular expression) with a count in a window; a match reports the check `log.rule_<id>` through the alert engine, so debounce, maintenance windows and escalation apply and the alert shows on **Agent alerts** | module + `logs` + `rmm.alert.manage` |
| Administration cards | `admin/settings_endpoint_agent.php`, `admin/post/settings_endpoint_agent.php`, `includes/rmm_scale_admin.php`, `includes/rmm_scale_cards.php`, `js/rmm_scale.js`: the three switches with Core's risk and storage texts and the required acknowledgements, allowed folders per platform, transfer and log limits, the transfer-folder status, log sources | administrator |

The old `agent/post/rmm_remote.php` (the vendor-RMM "Remote Connect" handler) is untouched. Every action is authorised on the server on every call (role, the device's department, the ability, the switch) by Core's
`RemoteActions` / `LogActions`; the pages only decide what to draw. Another department's device is the same 404 as a missing one. Every request, refusal and completion is audited (the activity log, type Endpoint Agent), and
`rmm.file.transferred` and `rmm.log.rule_matched` are on the event bus (webhooks and event rules; both are in the event picker).

**What is verified.** The pages and handlers over HTTP with a simulated device (`tests/rmm_ui_p68_remote.php`, `tests/rmm_ui_p68_logs.php`, `tests/rmm_ui_p68_admin.php`, loaded by `tests/rmm_ui.php`) and in a
real browser at 1366 and 390 px, light and dark (`tests/browser/rmm_p68_*_smoke.mjs`); and, end to end with the **real Linux agent** built from RivetCore v1.0.0-rc.11 under a real systemd in a container,
talking TLS through nginx to RivetIT's own bridges on a scratch install (`tests/real_agent_proof/run.sh`, 39 checks): service list, a unit really restarted, the agent's own service refused, process list and a
kill that only succeeds when the name matches, a download with a verified SHA-256 and identical bytes, `/etc/shadow` and a symlink to it refused, an upload hash-verified by the agent, an overwrite refused,
a journald line shipped, filtered and turned into an alert by a log rule, and the audit rows. **Not verified:** any Windows code path (cross-compiled and vetted only), a real MeshCentral server (the `viewmode`
values 11/12/13 are Core's reading of MeshCentral's URL scheme, checked against a stub), macOS.

## 10. Scale settings and the Performance and capacity panel (RivetCore 1.0.0-rc.11, S3 to S8)

Administration > Settings > Endpoint agent > the cards **Scale settings**, **Metric storage and partitioning** and **External metric store** (all limits are validated by `RmmSettings::update`, only changed
values are written) and the read-only **Performance and capacity** panel on the Agent Fleet page (`rivetRmmUiCapacity()`):

* **What changed without any setting:** a steady check-in takes fewer statements (S3), fleet counters and the active releases are cached for 15 seconds (5 minutes stale-while-revalidate; `cache_fresh_s` 0 turns
  it off), job claiming is safe for several workers (S6), housekeeping does more (see Operations).
* **Redis:** when Redis answers, the cache and the token buckets use it (`EndpointRedisProvider`); otherwise Core's tables.
* **Partitioning (opt-in, `partitioning`):** the card runs Core's capability probe and says in words what it found (server version, privileges, foreign keys, unique keys) and which tables it would convert; "Enable"
  needs a confirmation because the one-time table copy blocks writers on a big table (do it off hours). In RivetIT it concerns `endpoint_agent_check_history`; Core's `rmm_metric_hourly` and `rmm_metric_daily`
  are converted too but stay empty, because **RivetIT writes device metrics to its own `device_metric_*` tables**, so Core's daily metric rollup is **not used** and the card says so instead of showing a status.
  Verified on MariaDB 11.8 (`tests/rmm_ui_p68_admin.php`: converted, housekeeping and check-ins still work, refused when the probe says no).
* **Herd control (off by default):** warm start after the module is switched on again or after a quiet gap (`warm_start_s`, `warm_start_pct`, `warm_quiet_s`; the gate reads `rmm_herd.json` in the state directory before
  any database work) and a token bucket per department for check-ins and enrollment (`client_bucket_burst`, `client_bucket_per_min`).
* **Queue workers:** the panel shows the autoscaling hint ("run N queue workers", from `CapacityReport`); see "Several queue workers" in Operations for how to run them.
* **External metric store (off by default, S4):** only for installs far beyond 10,000 devices; another service to run and secure. Switch, write URL, read URL and bearer token live in
  `settings.config_rmm_extstore_*`, sealed with `encryptSetting()` (registered for the key rotation, never echoed back; empty input keeps the stored value, a checkbox clears it). **Test connection** vets the URLs with
  the same `UrlPolicy` as webhooks (https, public addresses, no redirects, the connection pinned) and pings with a short timeout; it never prints the token. History is written to a bounded outbox
  (`rmm_metric_outbox`, flushed by housekeeping) and read from the store with RivetIT's own tables as the fallback; a check-in never waits for it. Plain http to a loopback fake server is possible only in the
  automated tests (it needs `EA_ALLOW_INSECURE_HTTP` and `RIVETIT_WEBHOOK_ALLOW_PRIVATE=1`, neither of which a production install has).
* **Not done:** the S9 validation (24 hours, 10,000 devices, reference hardware) is Core's and has not been run; nothing here was load-tested beyond the scratch suites.

## 11. The optional MeshCentral agent and the starter pack (RivetCore 1.0.0-rc.13, DB 2.6.162)

**Migration.** DB 2.6.162 runs Core's `0023_rmm_mesh_agent` through the Core runner (three tables: `endpoint_agent_mesh_settings`, `endpoint_agent_mesh_agents`, `endpoint_agent_installer_options`; nothing existing altered, idempotent). The starter pack has no migration and no table. Nothing is switched on by the update.

**Also install the MeshCentral agent (rc.12).** A tick-box in the Add device dialog (Agent Fleet, a department's page, the Endpoints menu) and in Administration > Endpoint agent > Deployment, **off by default** and drawn only while MeshCentral is switched on and has an address. It is passed as the trailing bool `installMeshAgent` to `RmmAdmin::downloadInstaller()` / `deploymentCommands()`; Core refuses (and creates no token) when Mesh cannot support it (not https, no device group id) and the dialog shows its text. A ticked Windows installer carries two extra payload members after `expires_at` (`install_mesh_agent`, `mesh`); an unticked one is exactly what it was. The Linux command gets `--install-mesh-agent --mesh-url ... --mesh-group-id ... [--mesh-cert-pin ...]`, so it holds the group id (a secret): shown once. The scripted `agent_installer` download serves the same choice because Core remembers it per enrollment token.

**Settings** (Administration > Endpoint agent > MeshCentral): device group id (hex or `mesh//...`), certificate pin with **Read it from the server** (Core's `saveMesh(..., mesh_probe_pin)`: the other fields of the card are saved, the pin field is NOT, and the proposal is shown once with a "Use this pin" button; trust on first use, compare it with MeshCentral's own value), the overwrite switch, and, collapsed, the agent-id and install-argument corrections with Core's "verify against your MeshCentral version" warning. Invalid input writes nothing and the typed values come back.

**Device page.** The Remote card (and the Remote tab, and the legacy device page) shows `deviceView()['mesh_agent']`: installed, version, node id, error, and visible hints for `conflict` (with a link to the other device only when it is in the same department) and `kept_manual`. The manual node mapping control is unchanged. Events `rmm.mesh.agent_installed` and `rmm.mesh.node_conflict` are in the picker.

**Scripts area and the Run script dialog** (26.10.45). Endpoints has one **Scripts** entry. Its page (`agent/rmm_script_library.php`) has a **Library** tab (the RivetCore library: the starter pack and your own scripts) and, only when the older list (`agent/rmm_scripts.php`, table `rmm_scripts`) holds rows or a vendor RMM integration is configured, a **Synced from <provider>** tab; the old URLs keep working (the legacy URL goes to the Library tab when there is nothing of its own to show). Administrators with the script-write level can **Import into the library** the scripts that were written by hand on the older list: each becomes a signed library script (language from the stored type, tags from the old category plus `risk:<class>`, note "imported from legacy Scripts"); a script whose name and text are already in the library is skipped, the older rows stay and say *Imported*. On the asset page the top **Run script** button opens one dialog: *What to run* is **Script library (N)** (grouped by risk class, searchable, the chosen script's typed parameter form, **Dry run (nothing is changed)** ticked by default, the approvals notice and the confirm box for destructive scripts; posts to the same handler as the Jobs-tab card, `agent/post/rmm_automation_scr.php`), **A saved script** (the older list, Windows) and **PowerShell (type or paste)** on Windows or **Shell (type or paste)** on Linux (the Core `powershell` and `shell` job types, ability `rmm.job.run_script`). Linux devices have the button too. Code: `includes/rmm_ui_render.php` (`rivetRmmUiRunDialog`), `includes/rmm_scr_ui.php` (`rivetRmmScrDeviceLibrary`, `rivetRmmScrRunDialogLibrary`, `rivetRmmRunParamInputs`, the tab strip and `rivetRmmScrImportLegacy`), `js/rmm_panel.js`.

**Starter pack page** (Endpoints > Scripts > Starter pack; administrators; needs the `scripts` sub-switch; `agent/rmm_starter_pack.php`, handler `agent/post/rmm_starter.php`, `includes/rmm_starter_ui.php`). Browse the 73 scripts, 3 check template sets and 3 policy drafts with platform, risk, tag and search filters and counts; tick items; **Plan** (add / update / park for review / skip, with Core's reasons, writes nothing); **Install selected**; **Uninstall untouched** (preview first). The policy parameters (backup service, time server, SSH unit, certificate paths, uptime days) are one form. With no instance signing key the blocker is shown first and Install is disabled. The handler never sends an empty selection (Core reads it as the whole pack). In the script library a starter script has a **Starter** badge and a risk chip (from the `starter` and `risk:*` tags), and a version whose note starts `starter-updated|` is marked **Update waiting for review** with a link to the differences. Event `rmm.starter.installed` is in the picker.

**Proof and what is not proven.** `tests/rmm_ui_rc13_mesh.php` (settings, pin probe against a local TLS server, device page states, auto-mapping through real check-ins), `tests/rmm_ui_rc13_starter.php`, the Mesh section of `tests/endpoint_agent_installer_ui.php` (stamped payload decoded with `InstallerStamp`), `tests/browser/rmm_rc13_smoke.mjs`, and `tests/real_agent_proof/run_rc13.sh` (the real Linux agent built from rc.13 against RivetIT's own bridges with a mock Mesh server, and a starter script run on it). **Mock-only / unverified:** everything about talking to a real MeshCentral server (download address and flags, agent ids, install and uninstall arguments, where the node id can be read), and every Windows path (the Windows starter scripts are only parse-checked in Core).

## 12. The Mesh device group id in MeshCentral's URL form (RivetCore 1.0.0-rc.15, DB 2.6.163)

**Migration.** DB 2.6.163 runs Core's `0024_rmm_mesh_group_url` through the Core runner: one `ADD COLUMN`, `endpoint_agent_mesh_settings.group_id_url varchar(64) NOT NULL DEFAULT ''` (added only when missing, nothing else altered, idempotent). Rows saved with rc.12 to rc.14 keep working: the URL form is derived from their hex. `db.sql` carries the column. Nothing is switched on by the update.

**What changed in the card.** Against a real MeshCentral the `meshid` of the download address (`https://<mesh>/meshagents?id=4&meshid=<64 characters>&installflags=0`) turned out to be a 64-character base64 string in MeshCentral's URL spelling (`@` for `+`, `$` for `/`), not hex. The **Device group id** field now takes the whole pasted download address, the bare 64 characters, `mesh//...` or hex, and is sent to Core's `mesh_group_id` exactly as typed (surrounding whitespace trimmed; no hex-only restriction, no `pattern`). Core refuses the rest with one message, and a pasted address for another host than the saved MeshCentral address is a **warning** (`warnings` in the save result, shown with the confirmation, the save itself succeeds). The field shows `mesh_group_id_url` (the canonical hex stays in `mesh_group_id`), masked like a password with a show toggle, because the id works like an invitation key. The installer payload's `mesh.meshid` is the URL form.

**Certificate pin.** The pin field's help says to leave it **empty** for a publicly trusted certificate (for example Let's Encrypt, renewed every 60 to 90 days, which would break a pin) and to pin only a private CA or a self-signed certificate. *Read it from the server* shows Core's `pin_probe.note`; when `pin_probe.publicly_trusted` is true the page says no pin is needed and offers no "Use this pin" button.

**Agent.** The rc.15 Go agent accepts the URL form (and the old hex). An agent older than rc.15 that is given the URL form records a Mesh error (the RivetIT install is unaffected): issue the installer again from the updated server. **Still unverified** against a real MeshCentral: the Linux agent ids, the install and uninstall arguments and where the node id can be read; nothing was installed from a real server.


## 13. Push the MeshCentral agent to an enrolled device, and the MSI installer (RivetCore 1.0.0-rc.16, DB 2.6.164)

**Migration.** DB 2.6.164 runs Core's `0025_rmm_msi_templates` through the Core runner: one new table, `endpoint_agent_msi_templates` (`CREATE TABLE IF NOT EXISTS`, nothing existing altered; the DDL is Core's, `db.sql` carries the same table). Until it is applied everything else works; only publishing an MSI template needs it.

**Install or remove the MeshCentral agent on a device that is already enrolled.** The Mesh options live only in the stamped installer, so an agent installed without them (or one that updated itself) never got a Mesh agent. On the device page (the Remote access card on Overview, the Remote tab, and the legacy device page) **Install MeshCentral agent** sends a signed `mesh_install` job; **Remove MeshCentral agent** (shown while the device reports the Mesh agent) sends `mesh_uninstall`. The buttons follow Core's `deviceView()['mesh_install']` (`available`, `agent_supports`, `installed`), the `remote_tools` sub-switch and the `rmm.remote.manage` ability; a device whose agent did not announce `job:mesh_install` gets a disabled button and "update the agent first". Both ask first in one dialog (`#rmmMeshPushConfirm`); Remove needs its box ticked, and an install on a device that already reports the agent is only sent with the *force* box. The request carries nothing but `force`: the address, the device group id and the pin come from the saved Mesh settings when Core creates the job, and the group id never appears in a page, a job row or a result. Answers are Core's: `queued`, `already_installed` (nothing queued), `already_queued` (the same job), `unsupported`, `not_configured`, `feature_disabled`. The page polls the job (`agent/post/rmm_mesh_push.php`, action `job_result`) and prints its outcome in words, with the device's text escaped; the same outcome is in the Jobs list ("View result"). The node id is mapped by Core from the device's next check-in, exactly as for an installer-time install; reload the page then. Handler `agent/post/rmm_mesh_push.php`, markup `includes/rmm_mesh_push.php`, script `js/rmm_mesh_push.js` (linked only on a page that drew a control).

**Bulk install on Agent Fleet.** The toolbar button **Install MeshCentral agent** opens a dialog with a target: everything the user may see, a department, a tag or a group (a group, tag or department filter on the page preselects it; status, ring and text filters cannot narrow a bulk install and the dialog says so). The first call is unconfirmed and Core answers `confirmation_required` with the `device_count` it would reach; that number is shown in the confirm box, and only after the box is ticked is the confirmed call sent. The answer is Core's counts: queued, already installed, already queued, agent too old, no grant for that department, other. Above `approval_bulk_threshold` Core **refuses** (`approval_required`: the approval queue only knows script runs) and the text is shown as it is; more than `bulk_run_max` devices is `too_many`. It is safe to repeat.

**MSI installer.** *Add device* (Agent Fleet, a department's page, the Endpoints menu) and *Administration > Endpoint agent > Deployment* have a **Format: exe | msi** selector. `exe` is the default and is byte for byte what it was. `msi` is offered only for an architecture that has a current MSI template (ARM64 normally has none: the build makes x64 only), otherwise it is disabled with the reason; the download is the same endpoint with `format=msi` (`RivetIT-Agent-<department>-x64.msi`, a copy of the template with the department's payload appended, the stored template never changed). *Show deployment commands* with Format MSI adds the msi block: the `msiexec` command for the stamped file, a PowerShell downloader for an RMM, Intune platform script or GPO startup script, the property form for the unstamped template, the uninstall script and the detection data (product name and UpgradeCode).

**Administration > Endpoint agent > MSI template** uploads the template (`rivetit-agent-windows-amd64.msi` from the agent release, or `make msi` in RivetCore), lists version, size, SHA-256, where the platform was read from (`the package` or `declared by the uploader`) and the current flag, and makes a template current, deactivates or reactivates it. Core checks that the file is a Windows Installer package, its size, that it is not already stamped and the platform in its summary information. Largest accepted upload: the lower of Core's cap and PHP's `upload_max_filesize` / `post_max_size` (the real template is about 3.8 MB, so PHP's default 2M refuses it with the limit in words). For larger uploads or automation: `sudo -u www-data php scripts/endpoint_agent_publish.php rivetit-agent-windows-amd64.msi --msi --version 1.2.0 --arch amd64 --activate` (`--release` / `--rollout` do not apply: a template is never an agent update).

**UNVERIFIED on Windows, stated on every MSI surface.** The MSI has never been installed on a real Windows PC: whether `msiexec` accepts a package with the payload appended after the end of the compound file, what `[OriginalDatabase]` is on repair and upgrade, whether the cached copy keeps the trailer, and the whole custom-action flow are not proven (RivetCore `docs/rmm/MSI_VERIFY.md` is the checklist). The package is unsigned (SmartScreen or an application-control policy may warn or block it). amd64 only. What is proven here: the template is built by `make msi` and read back by Core's Go reader, a stamped copy made by this server decodes with Core's `InstallerStamp` and with Core's Go trailer reader (stripping it gives the template back byte for byte), and a real Linux agent installed without Mesh is pushed `mesh_install` and `mesh_uninstall` jobs through this edition's handler against a mock Mesh server.

## 14. The Remote button opens the remote screen in a new tab (RivetCore 1.0.0-rc.17, DB 2.6.171)

**The URL.** `<mesh>/<domain>/?login=<token>&gotonode=<node>&viewmode=<11|12|13>&hide=<mesh_hide>`. `hide` is MeshCentral's own bitmask (read from MeshCentral's page source: 1 masthead, 2 top bar, 4 footer, 8 page titles, 16 left bar and device list, 32 back button); the default **31** leaves only the device view, as Tactical RMM's "Take control" does. **Administration > Endpoint agent > MeshCentral > Hide MeshCentral interface elements** sets it (0 to 255; 0 omits the parameter, which is the URL of earlier versions); an optional second value applies to Terminal and Files (empty = same; 0 allowed). Core migration `0026_rmm_mesh_hide` (two columns on `endpoint_agent_mesh_settings`); until it runs the default 31 is used and the fields are not drawn.

**The tab.** `js/rmm_launch.js` opens a blank tab synchronously in the click (a browser blocks a tab opened after an asynchronous request), the launch call runs, and the tab is sent to the URL when it answers; a failed launch closes the blank tab and shows the error; a refused tab shows an **Open remote session** link. Used by the asset panel, the Remote tab (Desktop, Terminal, Files), the legacy device page and the Tactical asset page. Every click opens a new tab.

**The node id (RivetCore 1.0.0-rc.18).** `gotonode` is the node's 64-character MeshCentral id **without** the `node//` prefix (what the device page's address bar shows after `gotonode=`), `@` and `$` literal, e.g. `...?login=<token>&gotonode=<64 characters>&viewmode=11&hide=31`. The agent reports the same 48 bytes as 96 hex characters, which MeshCentral does not find; every stored form is converted when the link is built and when an id is saved or reported, and migration `0027_rmm_mesh_node_ids` rewrites the stored ids to `node//<64 characters>`. In the Mesh node field paste the address bar of the device in MeshCentral, the node id, or the 64 characters. **Not verified against a live MeshCentral server** with this exact link.

**Connect.** MeshCentral does not connect by itself from a URL (its page only selects the device and the view): the tab shows the Desktop with MeshCentral's own **Connect** button. **Not verified on a live MeshCentral:** `tests/browser/rmm_rc17_remote_smoke.mjs` runs against a mock Mesh server, and the `hide` bits are from MeshCentral's source, not from a live server of every version.

## 15. Patch management (RivetCore 1.0.0-rc.20, RMM Phase 4, DB 2.6.173)

**Detect first, install gated.** A device reports which updates it is missing (agent 0.8.0, job `patch_scan`, read only, nothing downloaded); a person or a rule approves them; an installation (`patch_install`) happens only for approved
updates, only where a patch policy allows it, only inside an install window and never faster than the limits. The agent never restarts a machine by itself. The model, the safety defaults and what is not done are in rivet-core
`docs/rmm/PATCHING.md`. Everything is **off by default**: the sub-switch is Core's `patching` (`RmmSettings::FEATURES`), set under Administration > Settings > Endpoint agent > **Patch management**, which shows Core's cost text.
An agent that does not announce `patch:scan` / `patch:install` is never offered a patch job, and the golden transcripts replay unchanged.

| Piece | Where | Needs |
| --- | --- | --- |
| Migration | DB step 2.6.173 runs the Core runner (`0028_rmm_patching`): eight `rmm_patch_*` tables (`CREATE TABLE IF NOT EXISTS`, nothing existing altered, the three default rings pilot 0 / broad 7 / last 14 days seeded when there is none). `db.sql` carries the tables. Skipped, and retried, until a package that ships 0028 is installed | |
| Patches tab of the asset page | `includes/rmm_pat_ui.php` (`rivetRmmPatPanelSection()`), `agent/post/rmm_patching.php`, `js/rmm_patches.js`: scan state, missing updates with kind, severity, size and status, filters, tick boxes with Approve / Decline / Dry run / Install, the effective patch policy, install history | module + `patching` + `rmm.patch.view`; buttons by `manage` / `install` |
| Patch compliance | `agent/rmm_patches.php`: devices, compliant percentage, overdue, never scanned, scan out of date, restart pending, failed installs, by department / site / group, the devices to look at (linked to their Patches tab), the ring distribution | `rmm.patch.view` |
| Patch approvals | `agent/rmm_patch_updates.php`: updates still waiting for a decision (or approved / declined / failed), one row per update id across the fleet, approve or decline across a department; requests that wait for a second person (kind `patch` of the ordinary approvals queue; the Approvals page lists them too) | view; `rmm.patch.manage` to decide; `rmm.job.approve` for the second person |
| Patch policies | `agent/rmm_patch_policies.php`: policies (ring, approval mode and days per kind, installs by schedule, daily or maintenance-window install window with a time zone, restart policy, longest install, second person), where each applies (everything, department, site, group, tag, device), rings, approval rules | view; `rmm.patch.manage` (policies and rings: access to every department) |
| Administration card | `admin/settings_endpoint_agent.php`, `admin/post/settings_endpoint_agent.php`: the switch, the six limits, and **live installs by schedule** (`patch_live_install`, off by default; switching it on needs an acknowledgement and is audited) | administrator |
| Menu | Endpoints: Patch compliance, Patch approvals, Patch policies, each only while the module and `patching` are on and the user holds the ability | |

Every action is authorised on the server on every call by Core's `PatchActions` through the technician API (`rivetRmmAutoApi()`): role, the device's department, the ability, the switch, the confirmations (a real install needs `confirm`, a restart afterwards
its own confirmation and `rmm.job.reboot`). Another department's device is the same 404 as a missing one. Events `rmm.patch.scan_completed`, `.approved`, `.install_started`, `.install_finished`, `.install_failed` are on the event bus and in the picker (29 `rmm.*`
events). The REST routes (`endpoint_devices/{id}/patches...`, `endpoint_devices/patches...`, `endpoint_devices/patch_policies...`) need no routing here; `api/v1/endpoint_devices.php` hands them to Core.

**Roll-out.** Publish agent 0.8.0, switch patching on, let the first scans arrive, put a few machines in a pilot ring, approve by hand, read a dry run, then install on one machine. **The Windows install path has never been run on a real machine**
(the Windows scan has, read only; Linux apt is proven end to end on a container with real systemd and real apt); dnf, yum and zypper have never run on a real host. Keep `patch_live_install` off until you have done that.
**Verified here:** the pages, handlers and REST routes over HTTP with a simulated device (`tests/rmm_ui_rc20_patching.php`, loaded by `tests/rmm_ui.php`), the migration and its replays (`tests/endpoint_agent_migration.php`), the three abilities
(`tests/core/EndpointAccessPolicyConformanceTest.php`).

## 16. Software deployment (RivetCore 1.0.0-rc.21, RMM Phase 5, DB 2.6.174)

**Install what you assigned, check that it is there, show the difference.** An administrator keeps a catalog of packages; a package is assigned to a scope (everyone, a department, a site, a group, a tag, one device); the agent reports what each machine has
(`software_detect`, read only); the scheduler fills the difference (`software_install`, install or remove one package), only where it is allowed and never faster than the limits; a report shows deployed against expected and exports CSV. The model, the safety
defaults and what is not done are in rivet-core `docs/rmm/SOFTWARE_DEPLOY.md`. Everything is **off by default**: the sub-switch is Core's `software_deploy` (`RmmSettings::FEATURES`), set under Administration > Settings > Endpoint agent > **Software deployment**,
which shows Core's cost text, and **live installs are a second, separate switch** (`software_live_install`, off): until it is on the scheduler only queues dry runs. An agent that does not announce `software:detect` / `software:install` is never offered a
software job, and the golden transcripts replay unchanged.

| Piece | Where | Needs |
| --- | --- | --- |
| Migration | DB step 2.6.174 runs the Core runner (`0029_rmm_software_deploy`): eight tables `rmm_software_hosts`, `rmm_packages`, `rmm_package_versions`, `rmm_package_assignments`, `rmm_package_installs`, `rmm_package_history`, `rmm_package_jobs`, `rmm_deploy_state` (`CREATE TABLE IF NOT EXISTS`, nothing existing altered, no row inserted). `db.sql` carries the tables. Skipped, and retried, until a package that ships 0029 is installed | |
| Deployment block of the Software tab | `includes/rmm_sw_ui.php` (`rivetRmmSwPanelSection()`), `agent/post/rmm_software_deploy.php`, `js/rmm_software.js`: what the device should have and has (state per package: compliant, scheduled, pending, failed, missing, still present, not detected), Detect now, dry run, install and remove with their confirmations, install history. The asset page's existing software inventory stays below it | module + `software_deploy` + `rmm.software.view`; buttons by `manage` / `deploy` |
| Software catalog | `agent/rmm_software.php`: packages (on/off, second person), their **immutable** versions (apt or dnf package name; msi or exe with https address, SHA-256, size, silent arguments, product code and detection rule), retire/restore, and the allowed installer hosts | view; `rmm.software.manage` (all departments); hosts: `rmm.admin` |
| Software assignments | `agent/rmm_software_assignments.php`: assign a package (optionally one version) to a scope, remove an assignment (uninstalls nothing), requests that wait for a second person, the ring explanation | view; `rmm.software.deploy` |
| Software report | `agent/rmm_software_report.php` and `agent/rmm_software_report_csv.php`: deployed against expected by package, department, ring or device, filters, and a CSV (a cell that starts with `= + - @` is prefixed with an apostrophe) | `rmm.software.view` |
| Approvals | a `two_person` package makes every assignment and every real run wait for a different user; the ordinary Approvals page lists them as kind **Software** and the assignments page shows them with Approve / Reject / Cancel | `rmm.software.deploy` + `rmm.job.approve` |
| Administration card | `admin/settings_endpoint_agent.php`, `admin/post/settings_endpoint_agent.php`: the switch, five limits and **live installs by schedule** (acknowledgement required, audited by name) | administrator |
| Menu | Endpoints: Software catalog, Software assignments, Software report, each only while the module and `software_deploy` are on and the user holds the ability | |

Every action is authorised on the server on every call by Core's `DeployActions` through the technician API (`rivetRmmAutoApi()`): role, the device's department, the ability, the switch, the confirmations (a real run needs `confirm`), the two-person rule and
the audit. Another department's device is the same 404 as a missing one. **Nothing the agent runs is typed in the edition**: the forms carry ids and the choice install / remove / dry run; the address, hash, arguments and package name of a job are rebuilt by
Core from the catalog row (extra fields in a request are ignored, and a test proves it). Events `rmm.deploy.started`, `.finished`, `.failed` are on the event bus and in the picker (32 `rmm.*` events).

**Roll-out.** Publish an agent built from rc.21, switch software deployment on, allow the installer host (Windows only; apt and dnf need none), add a small package and a version, assign it to a pilot ring or one device, read the dry run, then install on one
machine. **The Windows paths (MSI and EXE, the hash-pinned download, the registry reads) have never been run on a real machine**; Linux apt is proven end to end on a container with real systemd and real apt; dnf and yum are fixtures only. Keep
`software_live_install` off until you have done that.
**Verified here:** the pages, handlers, CSV and REST routes over HTTP with a simulated device (`tests/rmm_ui_rc21_software.php`, loaded by `tests/rmm_ui.php`), the migration and its replays (`tests/endpoint_agent_migration.php`), the three abilities
(`tests/core/EndpointAccessPolicyConformanceTest.php`), and a real-browser smoke (`tests/browser/rmm_rc21_software_smoke.mjs`).

## 17. Agent updates from GitHub, the Add device / Build installer page, and "Install now" on one device (RivetIT 26.10.52, DB 2.6.175)

No new RivetCore: this release is RivetIT's own, on rc.21. Everything that used to need a shell (build, copy, `scripts/endpoint_agent_publish.php`) is now a click, and the way to get an installer onto a machine is one page.

**Agent updates card** (Administration > Endpoint agent, `#agent-updates`; `includes/rmm_agent_feed.php`, `includes/rmm_agent_feed_ui.php`, `js/rmm_agent_feed.js`, handler `agent_feed=...` in `admin/post/settings_endpoint_agent.php`). It lists the releases of the public GitHub repository `TheTractorHacker/rivet-core` whose tag is
`agent-v*` (the repository can be changed in the card: another repository needs the "I trust this repository" box, is audited, and clears the saved list). For each release: version, pre-release flag, date, the files, whether it is already published here (Published / Partly / Not published, what is current, what is offered), and **how many enrolled devices report that version** (retired and revoked devices are not counted; versions not in the list are summed under the table). A badge says **New version available** when the newest release is newer than the newest published agent.
**Check for updates** asks GitHub (`GET /repos/<repo>/releases`, up to 3 pages, `If-None-Match` with the saved ETag so an unchanged list is a 304). The list is **cached** in `endpoint_agent_feed` (15 minutes; the button is limited to once every 20 s); the page itself never calls GitHub, so opening it is free and works offline. GitHub's unauthenticated rate limit (60 an hour) is read from the answer's headers and shown with the time it ends; while it lasts GitHub is not asked again. An offline server, an HTTP error, a malformed or oversized answer (over 2 MiB) all keep the saved list and say why.
**Publish** (per release; ring dropdown, rollout slider 0 to 100, "Use for new installers", "Offer to enrolled agents"; the ring defaults to **Pilot when a pilot device or a pilot release exists**, otherwise Stable, 100%) runs one file at a time so the page shows progress and which file failed:
* `rivetit-agent-windows-amd64.exe` / `-arm64.exe` are downloaded, their SHA-256 is compared with `SHA256SUMS` (and with the digest GitHub shows, when it does; a disagreement refuses) **before anything is stored**, then handed to `RmmAdmin::uploadBinary()`: the same code, PE checks, size cap, audit entry and `rmm.binary.publish` check as Agent binaries and `scripts/endpoint_agent_publish.php`. "Offer" passes the ring and percentage to it, which creates the signed update release.
* `rivetit-agent-windows-amd64.msi` is verified against `SHA256SUMS.msi` (or `SHA256SUMS`) and handed to `RmmAdmin::uploadMsiTemplate()`.
* The Linux files (`install-linux.sh`, `rivetit-agent-linux-<arch>`, `.tar.gz`, `.deb`, `.rpm`) are **recorded, not copied** (`endpoint_agent_linux_assets`: address built from repository + tag + file name, SHA-256 from `SHA256SUMS`, or GitHub's digest for the `.deb`/`.rpm` that the real release's sums files do not list). `install-linux.sh` is downloaded once (256 KiB cap) and verified.
* Re-publishing a stored file with the same hash is "already stored" (the ring, percentage and "make current" are still applied); a different file for a stored version is refused (a released version never changes). A database lock stops two publishes at once. Without JavaScript the same button posts once and does every file in one request.
**What may be contacted (SSRF).** Only `https://api.github.com` for the list and `https://github.com/<repo>/releases/download/<tag>/<file>` for a file; the URL is **built here** from the configured repository, the tag and the file name (each matched against a strict pattern), never taken from the API answer. A download may be redirected only to GitHub's asset hosts (`objects.githubusercontent.com`, `release-assets.githubusercontent.com`, `github-releases.githubusercontent.com`), https on port 443 only, no credentials in the address, at most 5 hops, each hop checked again and its name resolved here; a name that resolves to a private, loopback or reserved address is refused and the address checked is the one connected to (`CURLOPT_RESOLVE`). Size caps (list 2 MiB, checksum files 256 KiB, script 256 KiB, Windows files the module cap and the size the release lists), connect and total timeouts and a low-speed cut-off. For the automated tests only, `RIVET_AGENT_FEED_TEST_ORIGIN` points both at a loopback http mock **and only when `EA_ALLOW_INSECURE_HTTP === true` is also set in config.php** (never in production).
**Trust.** The checksums come from the same repository as the files: they protect against a damaged or swapped download, **not against a repository you should not trust**. Whoever controls the configured repository can publish an agent that your devices will install when it is offered, which is why changing the repository needs a confirmation. Publishing needs `rmm.binary.publish` (administrators); the cache, the repository and the record of Linux files are administrator-only.

**Add device / Build installer** (`agent/rmm_add_device.php`, Endpoints menu; `includes/rmm_add_device_ui.php`, `js/rmm_add_device.js`; endpoint `agent/post/rmm_installer.php`, the same as the Add device dialog; `rmm.token.manage` / administrators). One page: pick the department (and location), the platform (Windows x64, Windows ARM64, Linux x64, Linux ARM64), the ring, and (Advanced) the token lifetime and number of machines. Windows: **Download .exe installer** and **Download .msi installer** (a copy of the published template with the department's enrollment appended; unsigned and not verified on Windows), **Show silent-install commands** (PowerShell for Intune / GPO / an RMM; with an MSI template also the `msiexec` line, the PowerShell MSI downloader, the properties form and the uninstall) and copy buttons. Linux: **Create install command** and the recorded packages with their SHA-256, a copy-link button and the `apt install` / `dnf install` line. Every button creates its own audited token, shown once. The dialog (Agent Fleet > Add device) is unchanged and links here.
**The Linux command is now one command.** Core's own snippet tells the person to unpack the release tarball first. When the Linux files are recorded, the command downloads `install-linux.sh` and the agent itself, checks **both** against the SHA-256 recorded on this server (exit 65 and nothing installed on a mismatch), then runs the script with `--binary` and the token in a root-only temporary file (a private CA given with `--ca` is for your server; GitHub is fetched with the system trust store). Without recorded files you get Core's text unchanged. Two gaps found and fixed on the way: a Linux command no longer needs a **Windows** agent to be published (Core's `issue()` demanded one; the handler now makes the token through the ordinary token action in that case), and the unsigned/unverified MSI warnings stay on every MSI surface.
**Verified end to end** (`tests/rmm_ui_addevice.php`): the stamped `.exe` and `.msi` downloaded through the endpoint are the published bytes plus a trailer that decodes to the server address, the department and a token, and **that token enrolls a real device through `/api/v1/agent_enroll` once** (the second use is refused); the Linux command is valid shell and, run with a stand-in `curl` and install script, hands `--binary --server --token-file` to the script, and stops with exit 65 when the agent or the script is tampered with. **Not verified:** a real Linux host running the generated command against a real GitHub (the same script path is proven by `tests/real_agent_proof/`), the MSI on Windows.

**"Install now" on one device does not need the global live-install switch.** This is how the code already behaved (Core's `patch_live_install` / `software_live_install` limits are read only by the scheduler); 26.10.52 makes it explicit and locks it with tests. A technician with `rmm.patch.install` (Patches tab, **Install now**) or `rmm.software.deploy` (Software tab, **Install / remove now**) acts on ONE device, always behind the per-action confirmation box and dialog, and the action is audited (`Patch Install Queued`, `Software Job Queued`). The global switch (Administration, off by default, acknowledged and audited when turned on) keeps gating only the **scheduler's automatic installs across the fleet**: with it off the scheduler queues dry runs only. The Administration cards and both tabs now say so. Tests: `tests/rmm_ui_rc20_patching.php` and `tests/rmm_ui_rc21_software.php` assert the switch is off and a technician installs anyway, audited once, and the scheduler section still queues only dry runs.

**Tests.** `tests/endpoint_agent_feed.php` (the feed, with a mock GitHub, `tests/support/mock_github.php`: URL policy, redirects, caps, ETag, rate limit, offline, pagination, hostile release data, every refusal of a publish, a good publish through Core, the Linux command run for real with stand-ins, the handler: administrator only, CSRF, hostile input, escaping, new code on an old schema; with `FEED_REAL_DIR=<the real agent-v0.9.1 files>` it also publishes the real release, served by the mock), `tests/rmm_ui_addevice.php` (loaded by `tests/rmm_ui.php`), the 2.6.175 migration and replays in `tests/endpoint_agent_migration.php`, and `tests/browser/rmm_agentfeed_smoke.mjs` (Chrome: check, cancel, publish with progress, a tampered download, rate limit, offline, pilot default, the Add device page, 390 px). Run the browser smoke with a mock: `MOCK_GH_DIR=<dir> php -S 127.0.0.1:<p> tests/support/mock_github.php`, the install's web server started with `RIVET_AGENT_FEED_TEST_ORIGIN=http://127.0.0.1:<p>`, `php tests/browser/rmm_seed.php` first.

## 18. The check schedule without JSON (RivetIT 26.10.53, no database change)

Nothing in the RMM forms asks for JSON any more. The pieces:

**Check schedule** (Administration > Endpoint agent, Service and defaults; `includes/rmm_check_schedule.php` the translation, `includes/rmm_check_schedule_ui.php` the markup, `js/rmm_check_schedule.js` the behaviour, handler `save_agent_settings` and `cs_preview` in `admin/post/settings_endpoint_agent.php`).

* The options: **Low disk space** (warn below 5, 10, 15 or 20 % free; critical at half that; the drive stays what it was, `C:` on the built-in list), **Waiting for a restart**, **Important services stopped** (a checklist of common Windows and Linux services plus an "add another service" box, one name per line), **How often to check** (5, 15, 30 minutes or hourly) and, collapsed and off by default, **Advanced: run your own script** (a name, the commands, "give up after" 10 s, 30 s or 1 minute; at most 8 KiB, at most 10 scripts). Three presets fill the options (Recommended, Light, Strict) and **Restore defaults** clears the stored list so the built-in list applies again. A service that a device does not have shows as a failure for that device, so tick only what every device has.
* **What is authoritative.** The browser sends options (`cs[disk_on]`, `cs[disk_warn]`, `cs[svc][]`, ...), never check definitions. `rivetCsBuild()` makes every check from a closed set of fields it validated itself (a whole-number test, allow-lists, a service-name pattern, size caps) and from the **stored** list for checks the options cannot express; unknown fields and wrong types are refused. The list is then validated by RivetCore (`ChecksValidator`, once per check so a message can name it, once as a whole) and again by `RmmSettings::update()` on save. A refusal saves nothing. A posted `checks_json` is not read.
* **Signing is unchanged.** The stored list is the same shape as before and `RmmSettings::signedChecks()` signs each check per device with the instance key (`Signer::checkMessage`). The test verifies every delivered check against the instance public key and that a changed script no longer verifies.
* **Live preview.** After every change the page posts the options to the same builder (`cs_preview=1`, administrator only, CSRF) and shows the summary line, plain-language errors and a read-only list of what will be delivered, with the technical definitions in a collapsed `<pre>`. It saves nothing.
* **Stored values.** Checks the options cannot express (a CPU check, thresholds, a service that should be stopped, a second disk) are shown as "Other checks set up earlier", kept byte for byte, and removed only by ticking Remove. Timing: if the stored checks run at different intervals the page offers "Keep the current timing". Parameter names: the agent reads `expected`, `warn_free_pct` and `fail_free_pct` (`endpoint-agent/internal/collect/checks.go`); the built-in list in RivetCore names `expect`, `warn_pct` and `fail_pct`, which the agent ignores (it uses 20 % and 10 % free). The page reads those as running / 20 % free and writes the names the agent uses.
* **Not offered:** "warn after N days" for a waiting restart. The agent's restart check has no age; it warns as soon as the device is waiting.

**Tactical RMM check policies** (`agent/rmm_checks.php`, handler `agent/post/rmm_check.php`, builder `includes/rmm_policy_check_params.php`): plain fields per kind of check; the handler builds the stored settings (closed set of fields, ranges, patterns) and does not read a raw `check_params`. **RivetCore policies** (`includes/rmm_pol_ui.php`): the older check types (service, disk, restart, script) have plain fields (`rivetRmmPolLegacyParams()`); settings an old policy had that the form does not offer are named on the row and dropped when it is saved. **MeshCentral** (`includes/rmm_mesh_cards.php`): agent id corrections are one number box per platform and install argument corrections one box per operating system; the handler rejects out-of-range values and anything outside the closed platform list before RivetCore validates them.

**Finding the installer.** The Agent updates card and the Agent fleet page have an **Add device / Build installer** button next to the existing Endpoints menu entry.

**Tests.** `tests/rmm_check_schedule.php`, `tests/browser/rmm_checks_smoke.mjs` (with `tests/browser/rmm_checks_extra.php`), and the updated `tests/endpoint_agent_authz.php`, `tests/rmm_ui_p23_a_policies.php` (inside `tests/rmm_ui.php`) and `tests/rmm_ui_rc13_mesh.php`.

## 19. Create installer and the Remote access (MeshCentral) setup page (RivetIT 26.10.54, no database change)

**Create installer.** `rivetRmmInstallerGate()` (includes/rmm_installer.php) says whether the viewer may create installers and why not (`module_off`, `no_right`). `rivetRmmInstallerCreateButton()` / `rivetRmmInstallerNotice()` draw the primary button in the header of the RMM dashboard and the Agent fleet page and, under it, the missing permission (`rmm.token.manage`) or setting, or "no Windows agent published" (link to Agent updates for administrators), or the small "no MSI template" hint. The button is never hidden. The decision to allow is unchanged (`rivetRmmInstallerAllowed`; the page, the endpoint and RivetCore check again).

**Remote access (MeshCentral) setup** (`admin/settings_mesh_wizard.php`; administrators). State: `rivetRmmMeshWizardState()` (saved settings, `MeshAgentService::installRefusal()` through `rivetRmmInstallerMesh()`, read-only device counts). Handler `admin/post/settings_mesh_wizard.php` (`mw_action`: `save_server`, `save_group`, `probe_pin`, `save_pin`, `live`): CSRF-checked, administrators only (admin/post.php), each save starts from the saved values and changes only its own fields through `RmmAdmin::saveMesh`; `live` (JSON, saves and audits nothing) runs `MeshService::probe()` and `probePin()` after the page loads and the page turns steps 1 and 3 green or amber. The login-token key and the device group id are never printed back. Troubleshooting is condensed from sections 11 to 14 above and RivetCore `docs/rmm/REMOTE_AND_LOGS.md`. Still unverified against a live MeshCentral: the exact `--loginTokenKey` workflow wording (it follows this repo's existing text).

**Tests.** `tests/rmm_ui_meshwizard.php` (run by `tests/rmm_ui.php`), `tests/browser/rmm_meshwizard_smoke.mjs`.


## 20. Build a new agent version from the Agent updates card (RivetIT 26.10.55, DB 2.6.176)

The card could only fetch finished releases; now it can start the build too. Files: `includes/rmm_agent_build.php` (rules), `includes/rmm_agent_build_ui.php` (the box), handler actions `build_*` in `admin/post/settings_endpoint_agent.php`, `js/rmm_agent_feed.js` (polling), `cron/agent_build_check.php`, table `endpoint_agent_build` (one row).

**Flow.** Version box (latest known + 1 patch) -> **Build** -> `POST https://api.github.com/repos/<configured repo>/actions/workflows/endpoint-agent.yml/dispatches` `{"ref":"main","inputs":{"version":"X.Y.Z[-suffix]"}}` (HTTP 204) -> the run is found through `GET .../actions/workflows/endpoint-agent.yml/runs?event=workflow_dispatch&branch=main` (the oldest run created since the request; one build at a time makes that unambiguous), then followed by number (`GET .../actions/runs/<id>`) -> state `queued` / `building` / `finished` / `failed` -> on success the release list is refreshed and the release shows with Publish. A run that never appears within 15 minutes, or a build not seen to finish within 3 hours, is shown as failed and stops blocking.

**Version rules (server side).** `X.Y.Z` with an optional `-suffix` (letters, digits, dot, dash; up to 20 characters, starting with a letter or digit); refused when it already exists in the release list (read again just before) or is lower than the highest known version (a pre-release sorts before its release: 0.9.1 is accepted after 0.9.1-rc1).

**Publish when built.** Ticked at build time with the ring / rollout / current / offer choices; stored in the row; carried out once (the row is claimed first) by the card's poll or by the cron job, as the user who started the build, through `rivetAgentFeedPublishAll()` (so RmmAdmin's permission check, the checksums and the audit rows are the same as a click on Publish). If the release does not show up within 30 minutes of the run finishing, or the user no longer exists, it gives up with a note and nothing is published.

**Token.** Fine-grained personal access token, resource owner = the repository's owner, only that repository, permission **Actions: read and write** (nothing else; Metadata read is added by GitHub). Saved with `encryptSetting($token, 'agent_build.github_token')`; format-checked (`github_pat_...` or a classic `ghp_`-style token); shown to nobody; **Test token** reads the workflow (proves the token can see it, and warns when the workflow is disabled; start permission is proven by Build); **Remove token**; removed when the release repository changes. Rotate it by saving a new one before the expiry GitHub shows. `rivetAgentFeedGet()` sends the token only to the `api` role (api.github.com, https, port 443, resolved address checked and pinned) on the first request and refuses a redirect of such a call. Error texts for GitHub failures (401 / 403 / 404 / 422 / offline) are written here; GitHub's own answer text is never echoed.

**Without a token** the box offers **Start the build on GitHub** (`https://github.com/<repo>/actions/workflows/endpoint-agent.yml`) and three plain steps.

**Cron.** `cron/agent_build_check.php` every five minutes (www-data, a log file that exists): exits at once with no network when no build is running; otherwise polls, refreshes the list on completion and runs the automatic publish. `deploy/install.sh` adds it for new installs.

**Tests.** `tests/endpoint_agent_build.php`, `tests/endpoint_agent_migration.php` (DB 2.6.176), `tests/browser/rmm_agentbuild_smoke.mjs` with `tests/browser/rmm_agentbuild_extra.php`.

## 21. Administration > Endpoint agent as tabs (RivetIT 26.10.56, no database change)

The page was one long scroll of cards. `admin/settings_endpoint_agent.php` now prints each card into the bucket of a tab with `$eaTab('name')` (output buffering; the cards, forms and field names are untouched) and `rivetEaRenderTabs()` in `includes/rmm_ea_tabs.php` assembles them. Tabs and cards: **overview** (module, settings, pending, devices), **updates** (agent-updates incl. Build a new version, binaries, msi-templates, deployment, releases), **checks** (the check editor), **remote** (remote-tools, file-transfer, mesh), **inventory** (inventory, log-collection, scale, partitioning, ext-store), **patching**, **software**, **security** (trust = CA + coexistence, automation, signing, tokens). The one-time token box and the deployment commands print above the tabs.

- **Behaviour** (`js/rmm_ea_tabs.js`, no inline handlers): `role=tablist/tab/tabpanel`, arrows/Home/End, `#tab-<name>` or any card id in the hash opens the right tab (also on `hashchange`), unknown hash opens Overview. Without the script all panes are shown.
- **Save returns to its tab.** Every post form in a pane gets `<input type="hidden" name="ea_tab">` (PHP, so it works without JS; the script refreshes the value from the pressed button's pane). `admin/post/settings_endpoint_agent.php` turns a valid `ea_tab` into the redirect target for the plain `redirect()` calls; handlers that already redirect to `#card-id` keep working because that id resolves to its tab.
- **One form, several tabs.** The "Service and defaults" form is `#ea_settings_form`; the check editor (Checks) and the CA / coexistence fields (Security and policy) are linked to it with the HTML `form=` attribute and have their own Save button (`save_agent_settings`). `js/rmm_check_schedule.js` finds its form by that id.
- **Attention dots** (`$eaDots` at the end of the page): Overview (service off, state-file mismatch, devices waiting), Agent updates (no binary for either architecture), Remote and Mesh (Mesh off or no address), Security and policy (no active token). Patching and Software deployment have no dot: nothing on the page says what is pending there.
- **Tests.** `tests/browser/rmm_eatabs_smoke.mjs` (tab list/aria, every card once in the right pane, keyboard, hashes, Save from Checks/Security/Overview, token create, dots, phone width, no inline handlers); `tests/rmm_ui.php` asserts the cards by id and the tab structure.

