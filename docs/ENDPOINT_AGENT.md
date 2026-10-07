# Built-in endpoint agent (server side)

Status: first release, Windows only. This document covers the RivetIT server: schema, API, jobs, MeshCentral, administration,
security model, operations and tests. The Windows agent itself is built and documented separately (`endpoint-agent/`).

## 1. Scope and decisions

| Decision | Choice |
| --- | --- |
| Supported OS | Windows 10 21H2 and later, Windows 11, Windows Server 2016, 2019, 2022, 2025. Architectures amd64 and arm64. macOS, Linux, patch orchestration and software deployment are follow-up issues. |
| Agent runtime | A single static Go binary installed as a Windows service (built by the agent project). |
| Device authentication | A per-device random 256-bit token. Only its SHA-256 is stored. It can be rotated or revoked at any time. There is no instance-wide device key. |
| Job and update trust | Ed25519. The instance signs jobs, check definitions and update manifests; agents pin the public key received at enrollment. |
| Remote access | MeshCentral, reached with a short login token minted per click (section 9). |
| Unmatched devices | Wait for administrator approval by default. Auto-creating assets is an explicit policy switch. |
| Existing RMM links | Unchanged. Tactical RMM, Level, Action1 and Sophos keep working per endpoint; enrolling the agent is optional. |

Reuse, not a parallel system: agent devices appear as an `asset_rmm_links` row (integration type `rivetit_agent`, created on first enable),
metrics go through `ITFlow\Metrics\MetricIngestService` into `device_metric_samples` (so the Performance tab, rollups and retention apply),
alerts are rows in `rmm_alerts`, and tickets come from the existing alert-to-ticket path (`createTicketFromRmmAlert`, cron auto-ticketing,
automation rules) and are closed by the existing conservative auto-close.

## 2. Architecture

```
 Windows agent --TLS--> /api/v1/agent_enroll | agent_checkin | agent_jobs   (device credential, own auth + body caps)
                                |
         src/EndpointAgent/  Enrollment  Devices  Checkin  Checks  Jobs  Updates  Signer  Mesh  Authz  Actions
                                |
   endpoint_agent_* tables --- asset_rmm_links / device_metric_* / rmm_alerts / tickets (existing)
                                |
 Technicians: Administration > Endpoint agent, RMM > device page, asset page, /api/v1/endpoint_devices (same Authz/Actions code)
```

Tables (migration 2.6.145, plus `endpoint_agent_binaries` and release `arch`/`binary_id` and the `ca_pem` setting from 2.6.146, all `utf8mb4_general_ci`, config in its own one-row table because `settings` is at the row-size limit):
`endpoint_agent_settings`, `_enrollment_tokens`, `_enroll_attempts`, `_devices`, `_checkins` (idempotency), `_checks`, `_jobs`,
`_mesh_nodes`, `_releases`. Times in these tables are UTC (`Y-m-d H:i:s`); the UI labels them UTC.

## 3. Enrollment and identity policy

Enrollment tokens: `rvte1.<12 hex selector>.<40 hex secret>`. Short-lived (admin cap, default 72 h), single or multi use (max uses), scoped
to a department and optional location and update ring, revocable. Only a hash is stored; the token is shown once. Comparison is constant
time (`hash_equals`, also on a miss). Creation, use, revocation and every failed attempt are audited. Failed or excessive attempts from one
address are rate limited from the database (10 failures or 60 attempts per 10 minutes, 429 with `Retry-After`), so it works without Redis.

Identity rules:

* Same `install_id` enrolling again: same device, credential rotated, `device_id` kept (re-enrollment).
* New `install_id` but the same `machine_guid`, or the same BIOS serial: a reinstall of the same machine, same device (a Windows reinstall changes
  the MachineGuid but not the serial).
* Same `install_id` but a different machine (different guid or serial): 409 `conflict`. Two machines are never merged.
* Linking to an asset uses stable identifiers only, in the order of the existing RMM sync rules: serial, then MAC. Junk serials ("To be filled by
  O.E.M.", "0", ...) never match. A hostname match alone is only a suggestion.
* Not linked automatically, waiting in `pending_approval` (response status `pending_approval` or `ambiguous`): no match, hostname-only match,
  several matches, serial and MAC pointing at different assets, a match in a different department than the token, a match that another live
  device already owns, or an asset that was retired. The administrator sees the reason and the candidates and can link to a chosen asset, create
  an asset, or reject (credential revoked).
* Renamed device: `hostname` follows the device; the asset name is never overwritten (the UI flags the difference). Department change is an
  administrator action that moves the device, its asset and its open alerts; a later re-enrollment with another department's token does not move it back.
* Retired asset: the device goes back to `pending_approval` (reason `asset_retired`) instead of silently relinking.
* Revoked or retired devices cannot re-enroll until an administrator uses "Allow re-enroll".

## 4. Check-in, status and ingestion

`POST /api/v1/agent_checkin` (see `api/v1/openapi.yaml`). Idempotent by `(device_id, seq)` through `endpoint_agent_checkins`: a retry is
acknowledged (liveness still updates) but never reprocessed. A missing or null metric produces no sample row, never a zero; out-of-range values are
dropped, not clamped. `collected_at` is rejected if more than 5 minutes in the future or older than the retention window. Caps: 1 MiB body (413),
100 checks, 100 buffered samples (invalid ones are dropped individually), 64 KiB inventory. The response also carries `status` and
`matched_asset_id` so a pending device learns when it was approved. Network rates arrive in bits per second and are stored as bytes per second.

Status (explicit timestamps on every page): `online` = checked in within `offline_after_s` (default 900 s); `offline` = quiet longer, up to
`stale_after_s` (default 7 days); `stale` = longer still; `never` = enrolled, no check-in. The cron housekeeping flips the existing RMM link to
`offline` (with `rmm_status_changed_at`, which drives the existing asset_offline automation).

Collection defaults: check-in every 300 s, sampling every 60 s, inventory on change and at least daily, 3 default checks (disk C:, pending reboot,
EventLog service). Retention: check-in window 30 days (also the oldest accepted `collected_at`), jobs 180 days, metric samples per the existing
metrics retention. All are settings.

## 5. Checks and alerts

A `fail` or `warn` result counts as bad, `ok` as good, `unknown` changes nothing. An alert opens after `failure_debounce` (3) consecutive bad results
and resolves after `recovery_debounce` (2) good ones. The alert key `agent:<device>:<check>:<episode>` is unique per integration, so re-delivery can
never create a second row; a new failure after recovery is a new episode. Ticketing then follows the existing rules (one ticket per alert).
Check definitions delivered to agents are signed; script checks are bounded (8 KiB, 60 s) and only run when the signature verifies.

## 6. Jobs

States: `queued`, `running`, `succeeded`, `failed`, `timed_out`, `cancelled`, plus `expired`. Every job has a uuid, an `attempt`, a recorded execution
account (`SYSTEM`), timeout, output limit and destructive flag. Free text from a device never reaches a script: scripts come only from authorized
RivetIT users or the saved script library, parameters are scalar values passed as structured data, and the audit log keeps a script hash, not the script.

Lost acknowledgements:

* Destructive jobs (every reboot, and any flagged job) are offered at most once. If the result never arrives (offered but never reported running,
  or running past deadline + 60 s) they become `failed` with reason `result_lost` and are never re-sent. A late real result from the agent replaces it.
* Harmless jobs offered but never reported running are re-offered with `attempt + 1` after the acknowledgement wait (default 120 s), at most 3 attempts,
  then `failed` / `never_started`. A job that reported running is never re-offered; past its deadline it is `timed_out` / `no_result_by_deadline`.
* A reboot job takes `params.delay_s` (5 to 3600, default 30).
* Output is redacted (bearer strings, Authorization headers, JWTs, password/secret/token/key assignments, PEM keys, cloud key shapes, 64-hex tokens,
  RivetIT's own credential formats) before it is stored, then size capped at the configured limit with a truncated flag. Redaction is pattern based and
  best effort; do not print secrets from maintenance scripts.

Signing: Ed25519 over the canonical JSON of the job object without `signature` (fields: `job_id`, `device_id`, `attempt`, `type`, `script`, `params`,
`timeout_s`, `max_output_bytes`, `issued_at`, `expires_at`). Canonical JSON: UTF-8, object keys sorted by their UTF-8 bytes recursively, arrays in order,
no whitespace, strings escape only `\" \\ \b \f \n \r \t` and other code points below U+0020 as lowercase `\u00xx`, all else raw, integers only.
`tests/fixtures/agent_job_signing_vectors.json` pins the rule (jobs, canonical-only strings, update manifest, check definition) with a fixed test key; it is
generated by `tests/fixtures/generate_agent_job_signing_vectors.php` and must stay stable. Update manifests are signed over the lowercase hex SHA-256 string.

Key rotation: Administration > Endpoint agent > Signing key > Rotate. The private key is encrypted at rest (`encryptSetting`). Agents trust the key they
received at enrollment, so after a rotation every device must re-enroll (Rotate credential, then a new enrollment token) before it accepts jobs or
updates; until then it rejects them (fail safe). Plan rotations like a credential reset.

## 7. Permissions

No new permission keys; viewing, running and remote access are independent grants of the existing RMM modules and are enforced in
`ITFlow\EndpointAgent\Authz` for the web pages, the web handlers and the REST API alike:

| Action | Needs |
| --- | --- |
| View devices, checks, job history | `module_rmm` >= 1 and the device's department |
| Job output | additionally `module_rmm_scripts` >= 2 |
| Reboot, collect, saved library script | `module_rmm_scripts` >= 2 (and view) |
| Free-form PowerShell | `module_rmm_scripts` >= 3 (and view) |
| Remote session | `module_rmm_remote_connect` >= 1 (and view) |
| Settings, tokens, approval, revoke, retire, MeshCentral node mapping | administrator |

Department access follows the web rule (administrators, department 0 and users with no department rows pass; otherwise a row is required). A device
outside the caller's departments is a 404, indistinguishable from a missing one. Module-only (limited) logins can view but never run, reboot or open a
remote session on an agent device, and the legacy shared API key is refused on the technician API.

## 8. Administration

Administration > Settings > Endpoint agent: enable switch (off by default), service URL, intervals, debounce, retention, job limits, unmatched-asset policy,
check schedule (signed JSON), coexistence policy text, signing key, enrollment tokens (create, list, revoke, recent rejected attempts), approval queue with the
explanation and candidates, device list (status with timestamps, version, linked asset, ring, rotate, revoke, retire, allow re-enroll), releases and rings,
MeshCentral settings. The device page (RMM > device, linked from the asset page) shows inventory, the latest sample ("no data" for unreadable values, never 0),
the existing performance charts, checks, jobs with a run form (script editor, timeout, destructive flag and confirmation), reboot and the remote launch button.

## 8a. Deploying the agent (per-department installer)

Step by step:

1. **Build or upload the binary.** Build with `cd endpoint-agent && make build VERSION=1.2.0` (produces `dist/rivetit-agent-windows-amd64.exe` and `...-arm64.exe`; Authenticode-sign them first if you can).
   Upload each under Administration > Endpoint agent > Agent binaries (architecture, version, "Make current for installers"), or run
   `sudo -u www-data php scripts/endpoint_agent_publish.php dist/rivetit-agent-windows-amd64.exe --version 1.2.0 --arch amd64 --activate`
   (add `--release pilot --rollout 10` to also offer it to enrolled agents). The server accepts only a PE file whose machine type matches the architecture (0x8664 amd64, 0xAA64 arm64),
   up to 64 MiB and not above PHP's `upload_max_filesize`/`post_max_size` (the page prints the effective limit; use the CLI for larger files), and refuses a file that is already a stamped installer.
   Files are stored under `backups/endpoint-agent/` (or `EA_BINARY_DIR` in `config.php`) with random names; the shipped nginx rules and `.htaccess` deny that directory over HTTP. Deleting only deactivates.
   Once a version is published its contents never change: re-uploading the same file is a no-op, a different file under the same version is refused.
2. **Set the service URL (https) and, if your certificate comes from a private CA, paste the CA certificate (PEM)** in the service settings. Switch the service on.
3. **Create the department installer.** Deployment card: department, architecture, optional location, ring, token lifetime (hours), max uses (default 25), label, then **Download installer**.
   Each click creates an enrollment token (audited with the actor, department, installer id, token id) and downloads `RivetIT-Agent-Setup-<department-slug>-<x64|arm64>.exe`.
   **Show deployment commands** creates a token and shows it once with ready-to-paste commands.
4. **Run it.** One PC: run the exe as administrator (or `RivetIT-Agent-Setup-...exe setup`). Fleet: paste the PowerShell snippet into your RMM, an Intune platform script or a GPO startup script. It POSTs the token to
   `/api/v1/agent_installer`, saves the exe to `%TEMP%`, runs `Setup.exe setup --silent`, checks the exit code and deletes the file. The service is `RivetITAgent`, the file `%ProgramFiles%\RivetIT\Agent\rivetit-agent.exe`
   (Intune detection rule: either one). The token is a secret for the department until it expires or is revoked: keep the snippet out of tickets and shared script shares.
5. **Approve and verify.** Devices that match an asset by serial or MAC link themselves; the rest wait under "Waiting for approval". Check Devices (status, version) and the RMM device page.
6. **Uninstall.** `"%ProgramFiles%\RivetIT\Agent\rivetit-agent.exe" uninstall` (add `--purge` to delete local state), then retire the device in RivetIT (section 11).

Token-gated download for scripts: `POST /api/v1/agent_installer` with `{"token":"rvte1....","arch":"amd64"}` (JSON or form body, or the token as `Authorization: Bearer`). A token in the query string is refused. A token that is unknown, wrong, revoked, expired or used up gets the same
generic 404. The download does not use up an enrollment use (enrolling does). Limits per 10 minutes, from the database: 10 failures or 30 attempts per address, 30 downloads or 20 failures per token selector (429 + `Retry-After`).
Every success and rejection is audited (token selector only, never the token). The file is streamed in 64 KiB chunks with an exact `Content-Length`; nginx should not buffer it (`X-Accel-Buffering: no` is sent).

Self-update hosting: a binary offered as an update becomes an `endpoint_agent_releases` row with `arch` and `binary_id`; the manifest `url` is then `<service_url>/api/v1/agent_update?arch=&version=` and the SHA-256 is the binary's.
`GET /api/v1/agent_update` (device credential) streams the unstamped file only for the release the manifest offers that very device now (its architecture, active release and binary, ring and rollout, hash matches), otherwise a generic 404.
External https releases (Agent updates and rings form) are unchanged.

### Stamped installer format (contract with the agent)

```
stamped_exe = <original exe bytes> || payload || footer
payload     = UTF-8 JSON object, at most 16384 bytes:
              {"version":1,"installer_id":"<uuid>","server_url":"https://host[/prefix]","enrollment_token":"rvte1.<selector>.<secret>",
               "department":"<name>","ca_pem":null|"<PEM text>","created_at":"RFC3339 UTC","expires_at":"RFC3339 UTC"}
footer      = 52 bytes: uint32 big-endian payload length || 32-byte raw SHA-256 of the payload || 16 ASCII bytes "RIVETIT-EMBED-v1"
```
The agent reads the last 52 bytes of its own executable, checks the magic, the length bound and the SHA-256, parses the JSON and (no arguments, or `setup [--silent]`) installs and enrolls unattended. The base binary is stored unstamped; stamping happens per download
(`ITFlow\EndpointAgent\InstallerStamp`). The SHA-256 detects truncation and corruption; it is not authentication: the payload carries the enrollment token, which is the secret. Expiry is the token's expiry.
`tests/fixtures/agent_installer_trailer_vectors.json` (generated by `tests/fixtures/generate_agent_installer_trailer_vectors.php`) pins exact stamped bytes, boundary sizes and negative cases for the agent's tests.
The embedded CA is the optional "CA certificate (PEM)" setting (public information).

## 9. MeshCentral

Setup:

1. On the MeshCentral server run `node node_modules/meshcentral --loginTokenKey` (add `--loginTokenGen` to create or replace it; only one key exists, regenerating
   revokes the old one). Paste the hex value into Endpoint agent > MeshCentral > Login token key (write-only, stored encrypted).
2. Create ONE limited MeshCentral user (default `rivetit-support`) that is a member of only the device groups technicians may control. Do not use an administrator account.
3. Enter the MeshCentral address (https), domain (empty for the default) and policy, then "Save and test connection".
4. Map each device to its node: an administrator pastes the `node//...` id on the device page, or the agent reports `mesh_node_id` in its inventory (adopted only when no
   administrator mapping exists). The mapping lives in `endpoint_agent_mesh_nodes`, separate from the asset name.

Mechanism (verified against MeshCentral's documented login-token support, `docs.meshcentral.com/meshcentral/tokens`, and its cookie format): RivetIT builds
`AES-256-GCM({"u":"user/<domain>/<account>","a":3,"time":<now-120>})` with the first 32 bytes of the key, packs `IV(12) || tag(16) || ciphertext`, base64, `+`
to `@` and `/` to `$`, and returns `https://<server>/<domain/>?login=<token>&gotonode=<node>&viewmode=11`. The token is minted at the click, never stored or logged
(only a random safe session id is). MeshCentral applies its own validity window (default about an hour) and does not make the token strictly single use, so keep
the account's rights minimal. Hosting and SSO model: MeshCentral stays on its own host and is reached directly by the technician's browser; RivetIT is the front door
that decides who may launch (role, department, device mapping, online state) and audits every launch and refusal. Attended versus unattended consent is enforced by
the MeshCentral device group's own consent setting; the RivetIT policy field records the intent.

Handling: unmapped device 404 `unmapped`; offline-looking device 409 `device_offline` (the UI offers "launch anyway"); MeshCentral down, slow (4 s timeout) or refusing
connections 503 `mesh_unavailable`; not configured 409. The address passes the shared SSRF policy (public addresses, plus networks an administrator allowed under Webhooks >
Internal network access), no redirects, DNS pinned. Not verified here: a real MeshCentral server, see section 15.

## 10. Updates and rollback

Releases (version, https URL on the RivetIT host, SHA-256, min_version, ring, rollout %) are signed at serve time. A device is offered the highest release that is newer than
what it runs, whose `min_version` is not above its running version, in its ring (pilot devices also get stable releases), inside the deterministic rollout bucket
(`crc32(device_id|version) % 100 < pct`, so raising the percentage only adds devices), and not previously reported failed by that device. The agent reports the last
attempt in the optional check-in field `update_result` (`ok`, `failed`, `rolled_back`); failed versions are not re-offered until cleared. Rollback: the agent keeps the previous
binary and rolls itself back when the new one fails its self-test; server side, set the release inactive or its rollout to 0, and publish a fixed version with a higher number.

## 11. Retirement, uninstall and ownership

Retire (or revoke): the credential is revoked immediately, queued jobs are cancelled, open alerts for the device are resolved, the RMM link is removed so monitoring stops, the
asset and its history are kept. A MeshCentral agent, an RMM agent, antivirus, EDR or backup agent installed separately is never removed or reconfigured by RivetIT or by the
RivetIT agent; removing them requires an explicit decision outside this feature. Uninstalling the RivetIT agent removes only the RivetIT service and its state; the device row
stays until retired.

## 12. Coexistence

The agent reads inventory and health only through Windows APIs, runs jobs only on request, and does not install, remove or alter other management tools. Avoid overlapping
schedules with Tactical or Level checks by not enabling the same check type in both (the admin check list is per instance); metrics from several tools for one asset are kept
separate by integration. Antivirus/EDR exclusions for the agent path are the customer's decision.

## 13. Security model

| Concern | Control |
| --- | --- |
| Device credential | 256-bit random, hash only stored, constant-time confirm, rotatable, revocable, expiring (1 year), 401 codes `invalid_token`, `revoked`, `expired` |
| Enrollment abuse | short-lived scoped tokens, max uses, DB rate limit, audited failures, no timing difference between a miss and a near miss |
| IDOR | device id always from the credential; job report scoped by device; technician API checks role then department; hidden devices are 404 |
| SQL injection | every new query is a prepared statement (`Db`); admin forms bind parameters |
| XSS | all device-supplied text (hostnames, inventory, check detail, job output, users) is escaped on every page; CSP nonce on inline scripts, no inline handlers |
| Script injection | scripts come only from authorized users or the library; parameters are scalars passed as data; device strings are never templated into scripts |
| Replay | jobs carry `job_id`, `device_id`, attempt and expiry inside the signature; the server rejects conflicting or repeated results (409/idempotent); check-ins are idempotent by seq |
| Signature checks | Ed25519 detached; private key encrypted at rest; public key pinned by the agent at enrollment |
| SSRF | MeshCentral address through the shared URL policy, https only, no redirects, DNS pinned, short timeout |
| Transport | TLS required (`426 tls_required` otherwise; behind a proxy `X-Forwarded-Proto: https` from a private peer); `EA_ALLOW_INSECURE_HTTP` exists for loopback tests only |
| Secrets | MeshCentral key and signing key via `encryptSetting`; tokens and keys are never logged; audit stores session ids and script hashes |
| Installer download | enrollment token required, never in the URL (400), generic 404 for every unusable token, DB rate limits per address and token, audited without the token, constant-time secret comparison, streamed with exact length, file names built only from `[a-z0-9-]`, stored file size and SHA-256 verified before any byte is sent |
| Binary upload | admin only, CSRF, PE and machine-type checks, size cap, random storage names, directory denied over HTTP, no path from user input ever reaches the filesystem (`bin_<32 hex>.bin` pattern enforced) |
| Resource limits | body caps (16 KiB enroll, 1 MiB check-in, 256 KiB job report), item caps, output cap, per-device rate limit (Redis, fail open) |

Operations notes: the nginx `client_max_body_size` must be at least 1m for `/api/v1/agent_checkin`; PHP-FPM must pass `HTTPS` (or the proxy header). The live nginx rules are not
changed by this feature.

## 14. Tests and end-to-end guide

Automated (scratch database only, real HTTP against `php -S`; see the header of `tests/endpoint_agent_lib.php`):

```
RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=scratch_x RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/endpoint_agent_enroll.php
... endpoint_agent_checkin.php | endpoint_agent_jobs.php | endpoint_agent_authz.php | endpoint_agent_migration.php
php tests/endpoint_agent_deploy_unit.php            # no database; installer format vectors, PE validation, names, quoting
... endpoint_agent_deploy_http.php                   # real HTTP: uploads, installer download, agent_installer, agent_update
php tests/load/agent_ingest_load.php 200 10 16 8        # load and cost measurement
LC_ALL=C scripts/check_openapi_drift.sh
```
`config.php` of the scratch install must define `EA_ALLOW_INSECURE_HTTP` and `$config_enable_setup = 0`. Each script wipes its own rows; run them one at a time.

Manual end to end: enable the service, create a token for a department, install the agent with that token, watch the device appear (linked, or in the approval queue), check the
asset's RMM card and Performance tab, make a check fail three times and confirm one alert and one ticket, recover it, queue a harmless PowerShell job and read its output, queue a
reboot (confirm, expect no retry if the result is lost), revoke the device and confirm check-in and job fetch return 401 `revoked`, map a MeshCentral node and launch a session.

## 15. Reference workload, measured capacity and limits of verification

Reference workload: a Windows workstation checks in every 300 s with 6 metric readings (CPU, memory, two volumes, network in and out), 3 check results and,
once a day or on change, a full inventory (about 2 KB of JSON for a check-in, 3 to 4 KB with inventory). Measured with `tests/load/agent_ingest_load.php 200 10 16 8`
on the shared 14-core scratch machine (load average about 9 from other jobs, MariaDB on the same box, `php -S` with 8 workers, no nginx or PHP-FPM tuning):

| Measure | Result |
| --- | --- |
| Throughput | 2000 check-ins from 200 devices, 16 in flight: 12.0 s, **167 check-ins/s**, 0 errors |
| Latency | p50 60 ms, p95 235 ms, p99 956 ms, max 1.7 s |
| Database cost per steady-state check-in | **27 statements** (14 SELECT, 3 INSERT, 10 UPDATE), one idempotency row, 6 metric sample rows |
| With inventory | 27 statements, 13 sample rows (adds uptime, memory total, per-volume sizes) |
| Enrollment | about 0.9 devices/s on the same loaded box (includes asset creation and signing), irrelevant at fleet rollout speeds |

Reading it honestly: at one check-in per device per 300 s, 167 check-ins/s is the arrival rate of about 50,000 devices, so ingestion is not the limit for an MSP-sized fleet;
a single database writer, the metric rollup cron and `device_metric_samples` growth (about 6 rows per check-in, 1,700 rows per device per day) are what to watch. These numbers come
from a shared scratch machine with an unrelated load and a single-host database, so treat them as a floor you can reproduce with the script, not a guarantee. The agent's own CPU,
memory and network use are measured by the agent project, not here. A rarely seen outlier: on the same box one earlier run saw 20 s stalls when other jobs on the machine were
creating and dropping databases (table-cache metadata locks); the check-in path itself holds its device row lock only for the length of one transaction.

Not verified in this environment: a real MeshCentral server (only a local mock of `/health.ashx`, and the token format is built from MeshCentral's documented and published cookie
code), and a real Windows agent (the Linux test build of the agent runs against this server in the end-to-end harness, see the report). The load numbers come from a shared scratch machine.

## 16. Contract notes

The device contract is implemented as specified. Additions: `signing_key_id` in the enroll and check-in responses; `status` and `matched_asset_id` in every check-in response;
`device_id` inside signed jobs; `signature` on every config check; optional `update_result` in check-ins; HTTP 426 `tls_required`, 404 `not_found` and 429 `rate_limited`
error codes.
