# Built-in endpoint agent / RMM module in RivetIT

Status: since DB 2.6.147 the server side of the endpoint agent is the **RMM module of RivetCore** (`rivet/rivet-core` >= 1.0.0-rc.4, namespace
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
  `Retry-After: 3600` and `Cache-Control: no-store`, and `endpoint_devices` answers `404 {"code":"disabled"}`, **from `api/v1/rmm_gate.php` before
  `config.php` is loaded**: no database connection, no query, no Core class (proved by `tests/endpoint_agent_module.php`). Enrolled agents keep their
  credential and data and back off to about one attempt an hour; nothing is deleted, and switching on again resumes everything;
* the agent device page shows a "module is off" notice, the asset page hides its "Agent device" button, queued ingest handlers are not registered and the
  cron housekeeping block does nothing (one primary-key SELECT). The administration page stays reachable: it is where the switch is.

The gate reads a small JSON state file, `backups/rmm-state/rmm_state.json` (the denied `backups/` area; mode 0640, written atomically). It is a cache of
the database: a missing, garbled or wrong-version file means "unknown", never "off", and the request goes on the normal path. The file is rewritten
whenever the switch or a limit changes (every `RmmSettings::set`), by the 2.6.147 update step, when the administration page is opened, and by the cron
block when it disagrees with the settings row (so a restored backup is corrected within one cron tick). Override the directory with
`define('RMM_STATE_DIR', '/path')` in `config.php` (an empty string switches the fast path off) and, because the gate cannot read `config.php`, set the
same path as `RMM_GATE_STATE_DIR` in the web server environment (nginx `fastcgi_param`, Apache `SetEnv`).

One deliberate difference from Core's own default: the bridges run `DeviceApi` in its compatibility mode, so a service that is disabled **in the database**
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
  `deploy/update.sh` do) so rivet-core 1.0.0-rc.4 is present: without it the step waits and retries.

## 5. Tests

Automated, scratch database only (a name containing `scratch`; real HTTP against `php -S`; see the header of `tests/endpoint_agent_lib.php`). Run one suite at
a time; each wipes its own rows.

```
RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=scratch_x RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... RIVETIT_REDIS_PORT=<throwaway redis> php tests/endpoint_agent_enroll.php
... endpoint_agent_checkin.php | endpoint_agent_jobs.php | endpoint_agent_authz.php | endpoint_agent_migration.php | endpoint_agent_deploy_http.php
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
