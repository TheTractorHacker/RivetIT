# RivetIT endpoint agent: build status, tests and measured footprint

Source: `endpoint-agent/` (see `endpoint-agent/README.md` for architecture, security model and install/uninstall policy).
Written 2026-10-06 against Go 1.27.1.

## Verification status

| Layer | Status |
|---|---|
| Portable core (enroll, transport, check-in/seq/ring buffer, jobs verify + state machine, updater, checks logic, redaction, CLI) | unit/integration tested on Linux with `go test -race`; end-to-end against `e2e/fakeserver` |
| Windows layers (service wrapper, registry/CIM/IP Helper collectors, DPAPI + ACLs, PowerShell execution, install/uninstall, `install-windows.ps1`) | cross-compile + `go vet` clean for windows/amd64 and windows/arm64; **never executed** |
| Self-installing installer (`setup`, payload reader in `internal/embed`, exit codes, strip-token copy, idempotence) | unit/integration tested on Linux with `go test -race`, fuzzed, validated against the server's trailer vectors, and exercised end to end by `e2e/run_e2e.sh` (stamped binary against the fake server) |
| Installer's Windows layer (self-elevation via `ShellExecuteEx runas`, MessageBox, Add/Remove Programs registry entry, manifest + version info `.syso`) | cross-compile + `go vet` clean for windows/amd64 and windows/arm64; **never executed** |
| Authenticode signing, MSI | not done (documented); CI has a disabled, secret-gated signing step |

`go vet ./...` (linux, windows/amd64, windows/arm64) and `go test -race ./...` pass; `staticcheck` (installed via `go install`) reports nothing in non-test code.

## Tests

93 top-level tests/fuzz targets (plus subtests), 0 failures, with `-race`: store 9, api 11 (+1 fuzz), buffer 4, collect 13, jobs 19 (+1 fuzz; includes the server signing vectors), update 15, agent 20 (about 91 test functions plus 2 fuzz targets; the runner counts 93 top-level).
The job canonical-JSON/signature rule is validated against the server's `tests/fixtures/agent_job_signing_vectors.json` (jobs, canonical-only strings, update-manifest signature, signed check definition); a copy lives in `internal/jobs/testdata/` and the repo-level file is preferred when present.
Fuzz targets: `FuzzCanonical` (canonicaliser + signature path; 500k execs clean), `FuzzDecoders` (check-in/jobs/enroll decoders).

### Installer tests added with the self-installing installer

`internal/embed`: table tests (valid, unknown fields, 27 validation failures, expiry, truncated/wrong magic/length lies/huge length/hash mismatch/tampered payload/
junk after footer/oversize/bad JSON/invalid UTF-8), strip-copy tests, `FuzzEmbedded`, and the server's `agent_installer_trailer_vectors.json` (positive: byte-identical `Build`,
`Extract`, `UnstampedSize`; negative: all 14 rejected). Main package (`setup_test.go`, against an httptest TLS enrollment server): the stripped binary and token absence under the state dir,
idempotent re-run keeping the install id with a server that would reject a second exchange, every exit code 2/3/4/5/6, elevation passthrough and no relaunch loop, silent never prompts,
token echoed by a hostile server never reaches the log, CommandLineToArgvW round-trip of the elevated command line. The `devtools`-tagged `stamp` command exists only for tests.

## Reproducible release build (CI and local)

`make dist VERSION=X.Y.Z` (CI: `.github/workflows/endpoint-agent.yml`, tag `agent-vX.Y.Z`) produces `dist/rivetit-agent-windows-amd64.exe`, `-arm64.exe`, `rivetit-agent-linux-amd64` (test build) and
`SHA256SUMS`, with `-trimpath -buildvcs=false`, `CGO_ENABLED=0`, `-ldflags "-s -w -X main.version=... -X main.commit=..."`. The Windows resources (manifest `asInvoker`, version info) are the committed
static `rsrc_windows_{amd64,arm64}.syso`, regenerated with `make syso`, so two builds with the same toolchain, `VERSION` and `COMMIT` are byte-identical. Binaries are unsigned.

## Measured footprint (LINUX measurements; Windows numbers are unmeasured)

Host: 14-vCPU Linux VM, otherwise idle. Linux test build of the agent, running against `e2e/fakeserver` (TLS, loopback).
Reference workload: default intervals (collect 60 s, check-in 300 s), 2 server checks (`disk` on `/`, `pending_reboot`), no jobs, 11 minutes of wall time (66 samples of `/proc/<pid>`).

| Metric | Result |
|---|---|
| RSS | avg 15.1 MB, max 16.5 MB (9.4 MB before first enrollment, idle) |
| Threads | 12-13 |
| CPU | 0.11 CPU-seconds in 663 s = **~0.017 %** of one core (clock-tick resolution; the only visible activity was the 300 s check-in) |
| Binary size | windows/amd64 8.12 MB, windows/arm64 7.37 MB, linux/amd64 (test) 7.79 MB (stripped, `-trimpath`); windows/amd64 gzips to 3.37 MB |
| Check-in request body | first (with inventory, 0 buffered) 1.1-1.5 KB; steady state with 1 sample 350 B; as run in the reference workload (4 buffered 60 s samples + latest) 1.9-2.3 KB; worst-case replay (99 buffered) 28.9 KB |
| Check-in response body | 289 B (fake server config) |
| Bytes on the wire, whole run | 4 TLS connections (enroll + 3 check-ins, no keep-alive reuse across the 300 s gap): 15.4 KB received + 11.4 KB sent by the server = about 6.7 KB per exchange including TLS 1.3 handshake and a self-signed ECDSA chain (real RSA chains cost more) |

Caveats: numbers are for the Linux collector (cheap `/proc` reads). On Windows the identity query spawns PowerShell/CIM once per process life and again for the logged-in user every 15 minutes; its cost is **unmeasured**. Ingestion capacity of the real server is not measured here (server work is separate).

## Reproduce

```
cd endpoint-agent
make vet test
make dist VERSION=1.0.0             # dist/* + SHA256SUMS
./e2e/run_e2e.sh                    # self-contained end-to-end (fake server)
RIVETIT_E2E_SERVER_URL=https://scratch RIVETIT_E2E_TOKEN=... [RIVETIT_E2E_CA=ca.pem] ./e2e/run_e2e.sh   # against a real scratch server
```
