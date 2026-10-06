# RivetIT endpoint agent

The Windows endpoint agent for RivetIT issue #3 ("Built-in RMM Agent with MeshCentral as Remote Software").
A single static Go binary (`rivetit-agent`, no CGO, no runtime) that runs as a Windows service and:

- enrolls with a short-lived enrollment token and receives a unique per-device credential,
- reports inventory, health metrics and check results to RivetIT (`/api/v1/agent_checkin`),
- executes **signed** maintenance jobs (PowerShell, reboot, collect) fetched from `/api/v1/agent_jobs`,
- updates itself from a **hash- and signature-verified** manifest, with automatic rollback,
- reads (never installs or manages) an existing MeshCentral agent's node id.

> **Read this first: verification status.** The agent was built on Linux without any Windows host. The portable
> core (enrollment, transport, check-in/seq/buffering, job verification and state machine, updater, checks logic,
> redaction, CLI) is unit/integration-tested on Linux and exercised end-to-end against a fake server. The thin
> Windows layers are **cross-compiled and `go vet`-clean for windows/amd64 and windows/arm64 but have never been
> executed**: see [What is Windows-only and UNVERIFIED](#what-is-windows-only-and-unverified).
> Do not roll this out fleet-wide before a pilot on real Windows machines.

## Supported platforms

Product support target (to be confirmed by a Windows pilot, see above):

| OS | Architectures |
|---|---|
| Windows 10 21H2 and later | amd64, arm64 |
| Windows 11 | amd64, arm64 |
| Windows Server 2019 / 2022 / 2025 | amd64 (arm64 where Microsoft ships it) |

What the toolchain guarantees (verified against Go's own documentation, not guessed):

- The Go wiki *MinimumRequirements* page states: "For Go 1.21 and later: Windows 10 and higher or Windows Server 2016 and higher."
  The agent is built with Go 1.27.1, so the hard floor is Windows 10 / Server 2016. The product target above is intentionally narrower
  (Server 2016 is not claimed or tested).
- The Go 1.27 release notes (go.dev/doc/go1.27) list no Windows-specific changes or new minimum OS requirement; the only minimum-OS change
  in 1.27 is for macOS (13+), which this project does not build for.
- The produced PE files report subsystem version 10.0 (`file dist/*.exe`), consistent with the above.
- arm64 binaries target baseline ARMv8.0 (`GOARM64` default `v8.0`).

Linux is **not** a supported production platform. A Linux build exists only so the agent can run end to end against a scratch
server on a developer machine (reads `/proc`, `statfs`, `/sys/class/dmi`; script execution is refused unless `RIVETIT_AGENT_TESTMODE=1`).

## Architecture

```
main.go / cmd_*.go           CLI: run install uninstall enroll rotate status version selftest
internal/agent               orchestration: enroll, sample + check-in loop, jobs poll, update, dormant state
internal/api                 wire types, HTTPS client (TLS verify, SPKI pin, caps, no redirects), backoff/jitter, Retry-After
internal/store               config.json, state.json, device token (DPAPI/0600), atomic writes, cross-process lock
internal/buffer              bounded on-disk ring of unacknowledged samples
internal/collect             Platform interface; Collector (null-never-zero), checks; collector_windows.go / collector_linux.go
internal/jobs                canonical JSON + ed25519 verify, durable job state, executor, bounded exec, redaction
internal/update              version rules, download+verify, stage/swap/rollback, probation (health check)
internal/svc                 Windows service wrapper (service_windows.go) / stubs
internal/logx                size-rotated log file
e2e/fakeserver, e2e/run_e2e.sh   contract-shaped test server and the end-to-end harness
scripts/install-windows.ps1  unattended deployment script (GPO / Intune / RMM)
```

Loop (one goroutine; jobs run on separate workers):

1. Every `collect_interval_s` (default 60 s): sample metrics + run due checks, append the sample to the on-disk ring.
2. Every `next_check_in_s` (default 300 s, server-controlled, clamped 10 s - 1 h): build **one** request from the newest sample
   (+ up to 100 older unacknowledged samples as `buffered`, oldest first), allocate `seq`, persist the exact request body, send it.
   On any failure the identical bytes (same `seq`) are re-sent after backoff; the ring keeps filling (bounded) meanwhile.
3. On success: drop the acknowledged samples, apply server config (checks, intervals), poll `agent_jobs` when `jobs_pending > 0`
   (plus a safety poll every 10th check-in), consider the update manifest.

### Identity and enrollment

- `install_id` (UUIDv4) is generated once, stored in `state.json`, and can never be overwritten by later code paths.
- `machine_guid` = `HKLM\SOFTWARE\Microsoft\Cryptography\MachineGuid`; serial/manufacturer/model from one bounded
  `Get-CimInstance Win32_BIOS/Win32_ComputerSystem` call (fixed text, 25 s cap, cached); MACs from the OS interface list.
  Anything that cannot be determined is `null`; placeholder values such as "To be filled by O.E.M." are treated as unknown.
- Re-enrolling (`rotate`/`enroll`/`install --token`) sends the **same** `install_id`, replaces the device credential and keeps `seq`.
- `status` = `pending_approval` / `ambiguous`: the credential is stored, the agent checks in at a low rate (>= 300 s) and `rivetit-agent status`
  explains what an administrator must do. `linked` = normal rates.
- **Revoked** (`401` with `code: revoked`, on check-in, jobs poll or job report): job execution is halted (running jobs are killed),
  the device token is overwritten and deleted, the local ring/in-flight data are dropped, `state.json` records `dormant`, a single clear
  ERROR is logged, and the agent makes **no further network calls** (it re-reads local state once a minute so a human `enroll` revives it).
- `invalid_token`/`expired` on a device credential: logged, long backoff, credential **not** wiped (it may be a server-side glitch);
  re-enroll with a new token.

## Build

```
make vet test                       # go vet (linux + windows/amd64 + windows/arm64) and go test -race ./...
make build VERSION=1.0.0            # dist/rivetit-agent-windows-{amd64,arm64}.exe and dist/rivetit-agent-linux-amd64 (test build)
make checksums                      # dist/SHA256SUMS
make fuzz                           # FuzzCanonical (job canonical JSON/signature path), FuzzDecoders (check-in/jobs/enroll decoders)
make e2e                            # self-contained end-to-end run against e2e/fakeserver (Linux)
```

Needs Go >= the version in `go.mod` (built and tested with 1.27.1) and network access for `golang.org/x/sys` on first build
(`go.sum` is committed). Only one third-party module is used: `golang.org/x/sys` (Windows registry/service/DPAPI/ACL APIs).
If your network does TLS inspection, point `SSL_CERT_FILE` at a bundle that includes the inspection CA for `go mod download`.

**Reproducible build notes.** `-trimpath -buildvcs=false`, `CGO_ENABLED=0`, version/commit injected with `-ldflags -X`, `-s -w` stripping,
no timestamps. Two builds with the same Go toolchain, source and `VERSION`/`COMMIT` produced byte-identical Windows executables on this machine
(checked with `sha256sum`). Pin the toolchain version in your release pipeline; a different Go version produces different bytes.

**Signing (documented, NOT performed here).** Authenticode-sign the Windows executables in your release pipeline *before* computing the
checksums/ed25519 signature, e.g.:

```
signtool sign /fd SHA256 /td SHA256 /tr http://timestamp.digicert.com /a dist\rivetit-agent-windows-amd64.exe
signtool verify /pa /v dist\rivetit-agent-windows-amd64.exe
```

`install-windows.ps1 -RequireSignature` refuses an exe without a valid Authenticode signature. **Self-update artifacts are authenticated
by the instance ed25519 key, not by Authenticode** (see Updates). Unsigned binaries trigger SmartScreen/EDR reputation friction;
code signing is strongly recommended before production.

## Install, uninstall, retirement

### Install (Windows, run as Administrator/SYSTEM)

```
set RIVETIT_ENROLL_TOKEN=<short-lived token>
rivetit-agent.exe install --server https://rivetit.example.com [--ca internal-ca.pem] [--pin-spki <hex sha256 of server SPKI>] [--department "Sales"]
```

`install` is unattended and idempotent: it saves the config (CA is copied into the protected state dir), enrolls (skipped when already
enrolled and no new token is given), copies the binary to `%ProgramFiles%\RivetIT\Agent\rivetit-agent.exe`, creates/updates the
service `RivetITAgent` ("RivetIT Agent", LocalSystem, automatic delayed start), sets recovery actions (restart after 5 s / 30 s / 60 s,
reset after 24 h, also on non-crash exits so update restarts work), and starts it.
If the machine is offline during install the one-shot enrollment token is kept (DPAPI-protected) and the service finishes enrollment,
deleting the token afterwards. A *rejected* token (invalid/expired) fails the install with exit code 3.
Prefer `RIVETIT_ENROLL_TOKEN` or `--token-file` over `--token` (command lines are visible to other processes).

`scripts/install-windows.ps1` wraps this for GPO / Intune / RMM: picks the right architecture, downloads over TLS, **verifies the SHA-256**
(required parameter) and optionally the Authenticode signature before running anything, passes the token through the environment, and
cleans up. MSI packaging is a documented follow-up; the script approach needs no WiX.

### Uninstall and retirement ownership policy

| Thing | `uninstall` | `uninstall --purge` | `uninstall --remove-meshagent` |
|---|---|---|---|
| RivetIT agent service + binaries | removed | removed | removed |
| Local state (`%ProgramData%\RivetIT\Agent`: config, token, buffers) | **kept** | deleted | kept (unless `--purge`) |
| Separately managed MeshCentral agent | **untouched** | untouched | removed via its own uninstaller (explicit opt-in only) |
| Other RMM / AV / EDR / backup agents | never touched | never touched | never touched |

Device retirement in RivetIT is a **server-side** operation: it should revoke the device credential (the next check-in gets
`401 revoked` and the agent goes dormant), cancel pending jobs for the device, and stop monitoring. The agent does not call the server
on uninstall. Uninstalling the agent without retiring the device in RivetIT leaves a device that simply stops checking in (it will show
stale/offline).
`uninstall` stops the service, deletes it, removes `.prev/.new/.failed/.old` leftovers and the binary; because the running exe cannot delete
itself, a detached `cmd.exe` removes it a few seconds after exit.

### Coexistence

The agent does not install, configure, update or remove any other RMM, MeshCentral, antivirus/EDR or backup software, and has no
code path that does so (the only action on another product is the explicit `--remove-meshagent` opt-in). Default schedule: one HTTPS request
every 5 minutes plus a 60 s local sample; checks default to 5 minutes. Heavy collectors (WMI/CIM) run at most once per process life
for static data. Add the agent exe/service to your EDR allow-listing by publisher (after signing) rather than path.

## Files and configuration

State directory: `%ProgramData%\RivetIT\Agent` on Windows (protected DACL: SYSTEM + Administrators full control, inheritance removed).
On Linux test mode pass `--state-dir DIR` (or `RIVETIT_AGENT_STATE_DIR`); directory `0700`, files `0600`.

| File | Contents |
|---|---|
| `config.json` | operator config: `server_url`, `ca_file`, `pin_spki_sha256`, `department`, `mesh_node_id` (override), `max_concurrent_jobs` (default 1), `disable_jobs`, `disable_script_checks`, `update_hosts`, `buffer_max_samples` (100), `buffer_max_bytes` (1 MiB) |
| `state.json` | `install_id`, `device_id`, status, `seq`, pinned `signing_public_key`, server config (checks/intervals), last check-in/error, update failure |
| `device.token` | the device credential: `v1:dpapi:<base64>` on Windows (`CryptProtectData`, machine scope + entropy), `v1:plain:<token>` `0600` on Linux |
| `enroll.token` | one-shot enrollment token, only between an offline install and the first successful enrollment |
| `inflight.json` | the exact unacknowledged check-in (replayed byte for byte with the same `seq`) |
| `buffer.json` | bounded ring of unacknowledged samples |
| `jobs.json` | durable job table (`job_id`, attempt, state, reason, unreported terminal output) |
| `update.json` | update probation state |
| `agent.log`, `agent.log.1` | service log (5 MiB x 2, rotated); no secrets or job output are logged |

Local kill switches (set in `config.json` by an administrator of the endpoint): `disable_jobs` (never execute server jobs) and
`disable_script_checks`.

## Monitoring and checks

Metrics: `cpu_pct` (delta of GetSystemTimes), `mem_pct` (GlobalMemoryStatusEx), `disk[].used_pct` (GetDiskFreeSpaceEx, fixed drives),
`net_rx_bps`/`net_tx_bps` (IP Helper `GetIfEntry2` octet deltas, **bits** per second). A metric that cannot be collected, or whose
rate needs a previous sample or hit a counter reset, is `null` — never `0`. Every collector call has a hard timeout (15 s; a hung
collector is abandoned and further calls fail fast until it returns, so goroutines cannot pile up).

Checks come from the server config (`config.checks[]`: `key`, `type`, `params`, `interval_s`, default 300 s, min 30 s). The latest result of
every check is included in every check-in. A failing collector gives `unknown`, not a crash or a fake `ok`.

| type | params | result |
|---|---|---|
| `service` | `name`, `expected` (default `running`), optional `startup` | ok / fail (wrong state or missing service) / warn (startup mismatch) |
| `disk` | optional `mount` (all fixed disks when omitted), `warn_free_pct`, `fail_free_pct`, `warn_free_gb`, `fail_free_gb` (defaults 20% / 10% free) | worst matching mount |
| `pending_reboot` | none | warn when CBS `RebootPending`, WU `RebootRequired` or `PendingFileRenameOperations` is set |
| `script` | `script`, `timeout_s` (<= 120, default 30), `max_output_bytes` (<= 4096) | exit 0 ok, 1 warn, other fail, timeout/launch error unknown |

`agent_update` is always reported: `ok` ("agent 1.0.0") or `fail` with the last update failure.

Script checks run with the same machinery and restrictions as jobs (bounded time and output, redaction, same account) and are treated as code
execution: **a `script` check is only run when its definition carries a `signature`** (detached ed25519 over the canonical JSON of the check object
minus `signature`, the same rule as jobs; vector `check_definition` in the server fixture) that verifies with the pinned key. Unsigned or badly signed
script checks, and any other check whose signature is present but invalid, report `unknown` ("refused") and are never executed. `disable_script_checks`
in `config.json` is a local kill switch. Note that a server holding the signing key can still sign malicious script checks: the server's signing key is
the root of trust for script execution (jobs and checks alike).

## Jobs

Types: `powershell` (`script`, `params`), `reboot` (`params.delay_s`, 5-3600, default 30), `collect` (returns the inventory as output).

**Verification before anything runs** (`internal/jobs/verify.go`): ed25519 signature over the canonical JSON of the job (keys sorted
lexicographically at every level, no insignificant whitespace, `signature` excluded, numbers as written, no HTML escaping; duplicate keys
and trailing data are rejected) using the `signing_public_key` pinned at enrollment; then `expires_at` (against the skew-corrected clock),
`issued_at` not in the future, `device_id` match when the job carries one, a known `type`, size limits. Timeout is clamped to 1-3600 s,
output cap to <= 1 MiB.

**At most once.** A `running` record is fsynced to `jobs.json` *before* the process is launched. A job found `running` after a restart is
reported `failed` (`agent_restarted`) and never re-run. A known `job_id` is never executed again whatever its `attempt` number; if its final
report was not acknowledged the stored result is re-sent. Terminal state is persisted before reporting; reports retry on transient failure
(5 attempts, jittered backoff) and are re-flushed after each check-in.

**Reboot** persists `succeeded`, reports it, and only then schedules `shutdown.exe /r /t <delay>`; it is never retried.

**Execution** (`powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -EncodedCommand <base64 UTF-16LE>`): the absolute
`System32\WindowsPowerShell\v1.0` path is used, the script and `params` (as a `$Params` object) travel inside the encoded command so no
server text is ever interpolated into a command line; minimal environment; stdin closed; no console window. Timeout kills the
process tree (`taskkill /T /F`; Linux test mode kills the process group). stdout+stderr are merged and capped at `max_output_bytes` with a
truncation marker. Concurrency is `max_concurrent_jobs` (default 1 = strictly serial). Scripts longer than ~10,000 characters do not fit
`-EncodedCommand` (command line limit) and fail with `cannot_launch`.

**Redaction** (`internal/jobs/redact.go`) removes, before output leaves the machine: `Bearer`/`Basic` credentials, `password|secret|token|api key|...=value`
assignments, JWTs, GitHub/Slack/AWS/OpenAI-style tokens, private key blocks, `user:pass@` URLs, and the literal device and enrollment tokens.
It is a safety net, not a guarantee: scripts must not print secrets.

**Account and privilege.** Jobs run as the service account, `LocalSystem`. That is a **high-privilege capability**: anyone who can submit a
`powershell` job runs code as SYSTEM on the endpoint. It must be gated server-side by its own permission, separate from inventory viewing and
remote access (issue #3), and every submission audited. Least-privilege options: run the service as a dedicated virtual account
(`NT SERVICE\RivetITAgent`, enable with `sc config RivetITAgent obj= "NT SERVICE\RivetITAgent"`) and grant it only what your jobs need — then
inventory/service/pending-reboot checks work but most maintenance does not; or split a low-privilege collector from an on-demand elevated
executor (follow-up). The default is SYSTEM because maintenance jobs generally need it.

## Updates and rollback

Check-in response `update` = `{version, url, sha256, signature, min_version}`. The agent:

1. validates (no network): version parses and is **strictly newer** (equal = no-op, older = refused as downgrade); the running version is
   >= `min_version` (else "intermediate update required"); URL is `https`, no credentials, host is the RivetIT host or listed in `update_hosts`;
   `sha256` is 64 hex; **ed25519 signature over the lower-case sha256 hex string verifies with the pinned key**;
2. downloads (<= 128 MiB, TLS, no redirects; the bearer token is only sent to the RivetIT host) into the fixed path `rivetit-agent.exe.new`
   beside the binary (never a path derived from the manifest/URL), checks SHA-256 (constant time); the file is deleted on any failure;
3. checks it is a PE (Windows) executable for this CPU (`debug/pe` Machine field) and runs `rivetit-agent.exe selftest`, which must report
   `version=<manifest version>` (this also binds the unsigned `version` field to the signed binary: a replayed old binary cannot claim a newer version);
4. writes `update.json` (probation, deadline 10 min), renames `current -> .prev` and `.new -> current` (Windows allows renaming a running exe),
   and exits with code 75; the service wrapper turns this into a non-zero service exit so the SCM recovery action restarts the *new* binary.

Health check and rollback: the new process increments a start counter in `update.json`. It is **confirmed** by its first successful
check-in (`.prev` stays as the last known good). If it restarts more than 3 times without confirming, or the 10-minute deadline passes without
a successful check-in (watchdog in the running process, or on the next start), it swaps `.prev` back, records the failure, and exits 75; the
restored binary reports the failure once, which appears as check `agent_update` = `fail` ("update to X failed: ..."). A failed version is not retried.
Residual risk: a new binary that cannot even start the Go runtime cannot roll itself back (the pre-swap `selftest` makes this unlikely);
SCM recovery restarts it but does not downgrade it. Mitigation: pilot rings (server-side staged rollout) and keeping `.prev`.

The Windows rename dance and SCM restart are exercised only by the Linux equivalent (pure functions on paths, tested).

## Server contract details (reconciled with `docs/ENDPOINT_AGENT.md`)

- `device_id` is a JSON **integer** on the real server (enroll response, and inside signed jobs where the canonical JSON keeps it as written);
  the agent accepts a number or a string everywhere and compares by text. Number and string forms are not interchangeable under the signature.
- `signing_key_id` (enroll and check-in responses) is stored; if a check-in reports a different id the agent logs an error and shows
  "signing key rotated; re-enroll required" in `status` (jobs and updates are refused until it re-enrolls, as designed).
- Every check-in response carries `status` and `matched_asset_id`; the agent applies them, so a pending device leaves low-rate mode once approved.
- The optional check-in field `update_result {version, state ok|failed|rolled_back, detail}` is sent once after an update attempt and cleared on acknowledgement.
- `426 tls_required` is treated as a configuration error: the in-flight check-in is kept and retried with a long backoff (use the https URL).
- Reboot jobs: `params.delay_s` 5-3600 (default 30; smaller values are raised to 5).
- Config checks carry `signature`; script checks require a valid one (see Monitoring).
- Testing against a real scratch server: the Linux test build reports `os=linux`, so the server's `config.php` must define `EA_ALLOW_NON_WINDOWS = true`
  (never in production). `rivetit-agent run` also has TEST-ONLY flags `--pending-interval SECONDS` and `--min-interval SECONDS` (shorten the pending re-check
  rate, default 300 s, and the 10 s interval clamp). `e2e/run_e2e.sh` documents the approval step and supports `E2E_APPROVE_CMD`.

## Transport and security model

- **TLS verification is always on.** There is no insecure flag. `https` is mandatory (plain `http` is accepted only in builds made with
  `-tags agenttest`, loopback hosts only, for developer scratch servers). TLS >= 1.2. Optional extra CA (`--ca`, added to the system pool) and
  optional SPKI pin (`--pin-spki`, checked after normal chain validation). Redirects are never followed. System proxy variables are honoured.
- Request <= 1 MiB, response <= 4 MiB, timeouts on dial/TLS/headers/total. Backoff is exponential with **full jitter** (5 s base, 15 min cap);
  `Retry-After` (seconds or HTTP date, capped at 1 h) is a floor; 4xx payload rejections drop the poisoned in-flight request rather than wedging.
- Ring buffer: <= 100 samples and <= 1 MiB of JSON, oldest dropped first (the newest sample is always kept); persisted atomically.
- Clock skew: the offset to `server_time` is applied to `collected_at` and to job expiry decisions.
- Device token: DPAPI (machine scope, entropy) inside a directory whose DACL only admits SYSTEM and Administrators; the token is never
  logged or placed on a command line, and is added to the job-output redaction list. Linux: `0600` file in a `0700` directory (re-tightened on read).
- Enrollment tokens are not persisted unless an offline install needs them (protected, deleted after use).
- No shell string is ever built from server-provided fields (see Jobs); `shutdown.exe`, `taskkill.exe`, PowerShell are invoked by absolute path with fixed argv.
- Update path traversal: staged/previous/failed names are derived only from the installed binary path; manifest version strings are strictly parsed
  (no separators). The agent downloads a raw executable; there is no archive extraction and therefore no zip-slip surface.
- The signing key is trusted on first use at enrollment over validated TLS (or the pinned SPKI/CA); rotating it requires re-enrollment.

## MeshCentral

The agent never installs or configures MeshCentral. It reports `inventory.mesh_node_id` from, in order: `config.json` `mesh_node_id`; a
`mesh_node_id.txt` file in the state dir (for deployment tooling); then the output of `"<Program Files>\Mesh Agent\MeshAgent.exe" -nodeid` (10 s cap).
Evidence and limits: MeshCentral's agent documentation (docs.meshcentral.com/meshcentral/agents) documents `C:\Program Files\Mesh Agent\` with
`MeshAgent.exe`, `meshagent.msh`, `meshagent.db` and `meshagent.log`, but **does not document where the node id is stored**; the `-nodeid` CLI option
is known from the MeshAgent README only as summarised by a web search, and was **not run here**. The `.msh` file holds the *mesh (device group) id*, not the
node id, so it is deliberately not parsed. If `-nodeid` does not behave as assumed, the field stays `null` (safe) and the override file/config keys remain.

## What is Windows-only and UNVERIFIED

Everything below compiles and vets for windows/amd64 and windows/arm64 and has **never run**:

- `internal/svc/service_windows.go` (service handler, create/update/start/stop/delete, recovery actions, non-crash failure actions)
- `internal/collect/collector_windows.go` (registry reads: MachineGuid, OS version, CPU name, pending-reboot keys; GetSystemTimes, GlobalMemoryStatusEx,
  GetDiskFreeSpaceEx/GetVolumeInformation, GetIfEntry2Ex, GetTickCount64; `mgr` service state; the PowerShell CIM identity query; MeshAgent dir lookup)
- `internal/store/perm_windows.go` (directory DACL via SDDL `D:PAI(A;OICI;FA;;;SY)(A;OICI;FA;;;BA)`, DPAPI protect/unprotect)
- `internal/jobs/exec_windows.go`, `shell_windows.go` (PowerShell `-EncodedCommand` execution, `taskkill /T /F` tree kill, minimal environment)
- `internal/agent/reboot_windows.go` (`shutdown.exe /r /t`), `cmd_install_windows.go` (install/uninstall, self-copy, delayed self-delete, `MeshAgent.exe -fulluninstall`)
- `scripts/install-windows.ps1`
- The update rename/restart sequence on a real SCM, and the whole system under EDR/AV
- Authenticode signing (not performed), MSI packaging (not built)

Windows 11 detection from the registry (`ProductName` still says "Windows 10") uses build >= 22000 and is likewise untested.

## Testing

`go test -race ./...` (see `docs/ENDPOINT_AGENT_BUILD.md` for counts and measurements). Highlights: enroll flow (new/pending/ambiguous/invalid/expired/revoked/429),
install_id stability and credential rotation, seq idempotency across restart, null-never-zero, ring bounds/replay order, backoff bounds, TLS/CA/pin/redirect/caps,
job signature (valid/tampered/wrong key/expired/wrong device/unsigned), crash-mid-job and lost-ack (no re-execution), reboot-reports-before-acting,
process-tree kill on timeout, output cap + redaction, concurrent job serialisation, updater (hash mismatch, bad signature, downgrade, min_version, selftest failure,
crash-loop and deadline rollback, last good kept, fixed staging path), credential file permissions, revoked dormancy (no traffic), and fuzzing of the canonical-JSON/signature parser and the check-in/jobs/enroll decoders.

End-to-end: `e2e/run_e2e.sh` (self-contained against `e2e/fakeserver`, or against a real scratch server with `RIVETIT_E2E_SERVER_URL`/`RIVETIT_E2E_TOKEN`/`RIVETIT_E2E_CA`).
