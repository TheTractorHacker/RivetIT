# Built-in endpoint agent / RMM module in RivetIT

Status: since DB 2.6.147 the server side of the endpoint agent is the **RMM module of RivetCore** (`rivet/rivet-core` >= 1.0.0-rc.5, namespace
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
| Device REST bridges (URLs and file names frozen: enrolled agents depend on them) | `api/v1/agent_enroll.php`, `agent_checkin.php`, `agent_jobs.php`, `agent_update.php`, `agent_installer.php`, all via `api/v1/includes/agent_device_api.php` (`ea_dispatch()`) |
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
has (the 2.6.147 step never touches it and never infers it from rows). Switching on for the first time mints the instance signing key and the
integration row, and offers a feature preset (Light, Standard; default = what RivetIT has always done: monitoring, metrics, jobs, updates, and remote
sessions when MeshCentral is enabled).

While the module is **off**:

* the device endpoints (`agent_enroll`, `agent_checkin`, `agent_jobs`, `agent_update`, `agent_installer`) answer `503 module_disabled` with
  `Retry-After: 3600` and `Cache-Control: no-store`, **from `api/v1/rmm_gate.php` before
  `config.php` is loaded**: no database connection, no query, no Core class (proved by `tests/endpoint_agent_module.php`); `endpoint_devices` is not answered by the gate (401 without a token, then `404 {"code":"disabled"}`, so an anonymous caller learns nothing). Enrolled agents keep their
  credential and data and back off to about one attempt an hour; nothing is deleted, and switching on again resumes everything;
* the agent device page shows a "module is off" notice, the asset page hides its "Agent device" button, queued ingest handlers are not registered and the
  cron housekeeping block does nothing (one primary-key SELECT). The administration page stays reachable: it is where the switch is.

The gate reads a small JSON state file, `backups/rmm-state/rmm_state.json` (the denied `backups/` area; mode 0640, written atomically). It is a cache of
the database: a missing, garbled or wrong-version file means "unknown", never "off", and the request goes on the normal path. The file is rewritten
whenever the switch or a limit changes (every `RmmSettings::set`), by the 2.6.147 update step, when the administration page is opened, and by the cron
block when it disagrees with the settings row (so a restored backup is corrected within one cron tick). Override the directory with
`define('RMM_STATE_DIR', '/path')` in `config.php` (an empty string switches the fast path off) and, because the gate cannot read `config.php`, set the
same path as `RMM_GATE_STATE_DIR` in the web server environment (nginx `fastcgi_param`, Apache `SetEnv`).

One deliberate difference from Core's own default: the bridges run `DeviceApi` with `DISABLED_COMPAT` (rc.5 option; it also re-creates a missing state file on the next request), so a service that is disabled **in the database**
but has no state file still answers `403 forbidden` exactly as RivetIT always did (the golden transcripts and the acceptance suites pin that). Once the
state file exists - which every switch change, the update step and the cron block guarantee - the answer is the new `503 module_disabled`.

## 3. Permissions

No new permission keys; the nine Core abilities map onto the existing RMM module grants in `EndpointAccessPolicy`, with the rules the old
`ITFlow\EndpointAgent\Authz` applied (Administrator and Technician behaviour is unchanged):

| Ability | Needs |
| --- | --- |
| `rmm.device.view` (devices, checks, job history) | `module_rmm` >= 1, and the device's department (below) |
| `rmm.job.run_saved` (saved library script, collect, cancel, job output) and `rmm.job.reboot` | view, `module_rmm_scripts` >= 2, not a module-only login |
| `rmm.job.run_script` (free-form PowerShell) | view, `module_rmm_scripts` >= 3, not a module-only login |
| `rmm.remote.launch` | view, `module_rmm_remote_connect` >= 1, not a module-only login |
| `rmm.device.manage`, `rmm.token.manage`, `rmm.binary.publish`, `rmm.admin` | administrator (`role_is_admin`) |

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
* **config.php constants** (all optional): `EA_ALLOW_INSECURE_HTTP` (loopback test servers only), `EA_BINARY_DIR`, `EA_BINARY_MAX_BYTES`,
  `EA_ALLOW_NON_WINDOWS` (the agent's Linux test build in an integration harness, never production), `RMM_STATE_DIR`.
* **nginx / PHP-FPM.** `client_max_body_size` at least 1m for `/api/v1/agent_checkin`; PHP-FPM must pass `HTTPS` (or the proxy sets
  `X-Forwarded-Proto: https` from a private peer). The live nginx rules are not changed by this feature. The state directory is below `backups/`, which
  the shipped rules already deny.
* **Updating.** `Update Database` (2.6.147) runs Core's migration runner. Migrations 0014 and 0015 are `CREATE TABLE IF NOT EXISTS` / guarded `ALTER`s that
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
has locations) the location. The **Linux** tab creates an audited token and shows the install snippet (`InstallerService::linuxSnippet`) with a copy button; there is no Linux binary to download.

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
