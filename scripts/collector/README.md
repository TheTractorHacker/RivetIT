# RivetIT Windows metrics collector

`itflow_metrics_collector.ps1` gathers one batch of device metrics from a Windows
endpoint and POSTs it to RivetIT's device-metrics ingest endpoint. It is designed
to run as a **Tactical RMM Script Check** on a 300 second interval.

> **Naming.** This subsystem is **Metrics**, never "telemetry". RivetIT already has
> a `config_telemetry` setting, and it means something completely different —
> anonymous phone-home usage reporting (`admin/settings_telemetry.php`). Do not
> reuse the word in filenames, settings, tables or URLs.

> **Pre-RivetIT names that stay.** The script file name `itflow_metrics_collector.ps1`,
> the payload tag `itflow.metrics.v1`, the token cache `%ProgramData%\ITFlow\metrics\`
> and the Tactical custom field `itflow_metrics_token` keep their old names on purpose:
> RMM script libraries, deployed endpoints and the ingest endpoint already depend on
> them, and renaming any of them would force every device to re-enroll.

---

## 1. Why a collector exists at all

Tactical RMM cannot supply this data. Verified against the `amidaware/tacticalrmm`
and `rmmagent` sources:

* The `Agent` model has **no** `cpu_load` and **no** `mem` field in any release
  from v0.9.0 through v1.5.2. The CPU/RAM percentages the UI shows come from the
  WMI fallback blob.
* That WMI blob refreshes roughly every **3000–4000 s** (50–67 minutes), and the
  disks array every **1000–2000 s** (17–33 minutes). It is inventory, not a time
  series.
* The only genuine time series Tactical exposes is `checks.CheckHistory` — one
  integer per sample, only for `cpuload` / `memory` / `diskspace` check types, one
  HTTP call per check per agent, a `timeFilter` denominated in **days** with no
  sub-day option, and server-side pruning at 30 days.

So RivetIT's *vendor* tier (`cpu.utilization`, `memory.utilization`,
`disk.utilization`, `system.uptime_seconds`, `system.pending_reboot`) is all that
can be had from the API. Everything in the *collector* tier — per-core CPU, real
memory byte counts, disk IOPS/queue/latency, per-NIC throughput, battery — needs
an agent-side script. That is this file.

---

## 2. Parameters

| Parameter | Required | Default | Meaning |
|---|---|---|---|
| `-ApiUrl` | yes | — | Base RivetIT URL, e.g. `https://rivetit.example.com`. A full `/api/v1/metrics-ingest` URL is also accepted. |
| `-DeviceToken` | see below | `''` | The per-asset device token, `itfm1.<selector>.<verifier>`. |
| `-EnrollmentToken` | see below | `''` | Shared, revocable enrollment secret. Used to self-enroll and cache a device token when `-DeviceToken` is absent or has been revoked. |
| `-SampleIntervalSeconds` | no | `5` | Gap between the two performance-counter snapshots used to compute rates. Range 1–60. |
| `-TimeoutSeconds` | no | `30` | HTTP timeout for the push. Range 5–300. |
| `-NeverFail` | no | off | Always `exit 0`, even when the push fails. Use when the check must not raise alerts. |
| `-DryRun` | no | off | Print the JSON payload instead of posting. No token needed. |

Supply **either** `-DeviceToken` **or** `-EnrollmentToken`. Never pass a user API
token: a device token can only write its own asset's series and can read nothing
at all, which is the entire point of it being a separate credential class.

### Exit codes

| Code | Meaning | Tactical check |
|---|---|---|
| `0` | Batch pushed, or metrics deliberately disabled server-side (HTTP 503), or `-DryRun`, or `-NeverFail` | Pass |
| `1` | Push failed (network, auth, 4xx/5xx), or nothing at all could be collected | Fail |

A **missing metric is never a failure.** Every collector is individually wrapped
in `try/catch`; anything unavailable is omitted and named in the check output as
`omitted: gpu (nvidia-smi.exe not present), battery (no battery present)`. Only
the push itself can fail the check.

---

## 3. Deployment via Tactical RMM

### 3a. One-time server-side setup

1. Apply the `device_metric_tokens` migration (see the ingest endpoint's
   integration notes) and turn on **Settings → device metrics**
   (`config_enable_device_metrics = 1`). Until that flag is 1 the endpoint answers
   `503` and the collector passes its check without writing anything.
2. Mint one **enrollment** token (`token_kind = 'enrollment'`, `token_asset_id = 0`)
   and keep the plaintext — it is shown once and only the SHA-256 is stored.

### 3b. Upload the script

*Tactical → Settings → Scripts → New*

| Field | Value |
|---|---|
| Name | `RivetIT — Device Metrics Collector` |
| Shell | **PowerShell** |
| Category | `RivetIT` |
| Script | contents of `itflow_metrics_collector.ps1` |
| Default timeout | `90` seconds |

90 s leaves room for the 5 s sampling window, a 15 s worst-case `nvidia-smi`
timeout and a 30 s HTTP timeout.

### 3c. Create the check

*Tactical → Agents → (pick a policy, not individual agents) → Checks → Add →
Script Check*

| Field | Value |
|---|---|
| Script | `RivetIT — Device Metrics Collector` |
| Script arguments | `-ApiUrl "https://rivetit.example.com"` and `-EnrollmentToken "itfm1.…"` |
| Run every | `300` seconds |
| Failure threshold | `3` consecutive failures |
| Timeout | `90` seconds |

Attach the check to a **policy** covering the Windows fleet, not to 21 agents
individually, so the shared enrollment secret lives in exactly one place and can
be rotated in one edit.

A failure threshold of 3 matters: a single missed push on a laptop that closed its
lid is noise, three in a row (15 minutes) is a real collection outage.

### 3d. Per-agent tokens instead of enrollment (optional)

If you would rather not put a shared secret in the check definition, mint a device
token per asset and pass it with a Tactical custom-field substitution:

1. *Tactical → Settings → Global Settings → Custom Fields → Agent*: add a text
   field `itflow_metrics_token`.
2. Fill it per agent with that asset's device token.
3. Script arguments become:
   `-ApiUrl "https://rivetit.example.com" -DeviceToken "{{agent.itflow_metrics_token}}"`

This is more secure and much more tedious. With 21 agents, enrollment is the
sensible default; per-agent tokens are the right answer if the fleet grows or if
the enrollment secret would sit somewhere it should not.

### 3e. Validate on one machine first

```powershell
.\itflow_metrics_collector.ps1 -ApiUrl "https://rivetit.example.com" -DryRun
```

Prints the summary line and the exact JSON that would be posted, contacting
nothing. Check that the volumes, NICs and cores you expect are present and that
nothing you do not have (GPU on a VM, battery on a desktop) is being invented.

---

## 4. Enrollment and the token cache

```
        ┌── -DeviceToken supplied? ──────────────► use it
        │
  start ├── cached token at %ProgramData%\ITFlow\metrics\device_token.txt? ──► use it
        │
        └── -EnrollmentToken supplied? ──► POST /api/v1/metrics-ingest/enroll
                                             ├─ resolves the asset by Tactical agent id,
                                             │  then RMM hostname, then RivetIT asset name
                                             ├─ revokes this asset's previous device tokens
                                             └─ returns a new device token → cached
```

The cache file's ACL is rewritten to grant only `LOCAL SYSTEM` and
`BUILTIN\Administrators`, with inheritance broken — otherwise the inherited
`ProgramData` ACE that grants `Users` read access would survive and any logged-on
user could read the credential straight off disk.

On an HTTP `401` (token revoked, superseded, or the machine was re-imaged) the
script re-enrolls **exactly once** and retries. Never in a loop: a genuinely
broken enrollment secret must not turn 21 agents into a request storm.

Enrollment refuses to guess. If a hostname matches two live assets it returns
`409` and the device is not enrolled — binding a device token to the wrong asset
silently poisons another machine's history, and afterwards there is no way to
tell which readings were wrong.

---

## 5. What each Windows source provides

Every metric key below is exactly as `ITFlow\Metrics\MetricRegistry` declares it.
`dim` is the dimension: samples for a dimensioned metric carry an `instance` key,
host-level samples must not.

### CPU — `Win32_PerfRawData_PerfOS_Processor`

| Metric | dim | Instance key | Notes |
|---|---|---|---|
| `cpu.core.utilization` | core | `0`, `1`, `2`… | `% Processor Time` is counter type `PERF_100NSEC_TIMER_INV`: the raw value is accumulated **idle** time, so busy% = `100 * (1 - dPercentProcessorTime / dTimestamp_Sys100NS)`. |
| `cpu.utilization` | — | — | The **mean of the per-core values**, not the `_Total` instance. `_Total`'s raw aggregation semantics vary; the mean of the cores is unambiguous and matches what "CPU utilisation" means everywhere else in RivetIT. |

*Fallback:* if the perf counters are unusable, `cpu.utilization` falls back to
`Win32_Processor.LoadPercentage` and per-core is omitted.

*Limitation:* the legacy `Processor` counter set covers **processor group 0
only** — the first 64 logical processors. Nothing in this fleet is near that; a
>64-thread server would report group 0 only.

### Memory — `Win32_OperatingSystem` + `Win32_PerfRawData_PerfOS_Memory`

| Metric | dim | Source |
|---|---|---|
| `memory.total_bytes` | — | `TotalVisibleMemorySize` × 1024 (OS-visible RAM) |
| `memory.available_bytes` | — | `PerfOS_Memory.AvailableBytes` — the real *Available MBytes* (free + standby + zeroed) |
| `memory.used_bytes` | — | total − available |
| `memory.utilization` | — | used ÷ total × 100 |

**Available, not Free, deliberately.** Windows keeps a large instantly-reclaimable
standby cache. Free-only accounting reports a perfectly healthy machine at ~95%
used and makes every memory chart worthless. `FreePhysicalMemory` is used only as
a fallback when the perf class is unreadable.

### Volumes — `Win32_LogicalDisk` where `DriveType = 3`

| Metric | dim | Instance key |
|---|---|---|
| `disk.total_bytes` | volume | `C:`, `D:` … |
| `disk.free_bytes` | volume | `C:`, `D:` … |
| `disk.utilization` | volume | `C:`, `D:` … |

`DriveType = 3` is "Local Disk", so network shares, optical drives and removable
media are excluded — a mounted USB stick or an empty DVD tray must never appear
as a device volume at 100% usage. The instance key is the drive letter **with its
colon**, matching what the vendor providers emit, so both sources land on the same
series rather than creating `C` and `C:` side by side.

### Physical disks — `Win32_PerfRawData_PerfDisk_PhysicalDisk`

| Metric | dim | Instance key | Computation |
|---|---|---|---|
| `disk.read_iops` | disk | `0 C:`, `1 D: E:` | `PERF_COUNTER_COUNTER`: `dDiskReadsPersec ÷ (dTimestamp_PerfTime ÷ Frequency_PerfTime)` |
| `disk.write_iops` | disk | as above | same, on `DiskWritesPersec` |
| `disk.queue_length` | disk | as above | `CurrentDiskQueueLength` — an instantaneous gauge, read directly |
| `disk.latency_ms` | disk | as above | `PERF_AVERAGE_TIMER`: `((dAvgDisksecPerTransfer ÷ Frequency_PerfTime) ÷ dAvgDisksecPerTransfer_Base) × 1000` |

`_Total` is skipped. An average across spindles hides exactly the one slow disk
you are looking for.

When the base delta is zero the disk was completely idle across the window, so
**no latency sample is emitted**. Emitting `0` would claim a measurement that was
never taken and would drag the daily average toward zero for every idle machine.

### Network — `Win32_PerfRawData_Tcpip_NetworkInterface`

| Metric | dim | Instance key | Computation |
|---|---|---|---|
| `network.rx_bytes_per_s` | nic | perf instance name | `dBytesReceivedPersec ÷ elapsed seconds` |
| `network.tx_bytes_per_s` | nic | perf instance name | `dBytesSentPersec ÷ elapsed seconds` |
| `network.errors` | nic | perf instance name | `PacketsReceivedErrors + PacketsOutboundErrors`, **cumulative** — the registry declares this `KIND_COUNTER` |

Rates are computed **on the endpoint**, from two raw cumulative snapshots taken
`-SampleIntervalSeconds` apart. Raw counters are used rather than `Get-Counter`
because `Get-Counter`'s counter paths are **localised** — `\Processor(_Total)\%
Processor Time` simply does not resolve on a German or French Windows install,
and the `Win32_PerfRawData_*` class and property names are locale-independent.

Only adapters Windows reports as connected (`Win32_NetworkAdapter` with
`NetConnectionStatus = 2`) are reported. Adapter names are matched to perf
instance names by applying the same sanitisation Windows does — `(` → `[`,
`)` → `]`, and `#`, `/`, `\` → `_`. That removes loopback, Teredo and ISATAP
tunnels, disconnected Wi-Fi radios, and the pile of virtual adapters a Hyper-V
host accumulates. If the mapping yields nothing (an exotic driver naming case)
the script falls back to every non-loopback perf instance rather than reporting
no network at all.

A counter that goes **backwards** between the two snapshots (a reset, an adapter
disabled and re-enabled, a 64-bit wrap) causes that metric to be omitted for the
cycle rather than emitting a wildly wrong spike.

### System — `Win32_OperatingSystem` + registry

| Metric | dim | Source |
|---|---|---|
| `system.uptime_seconds` | — | `now − LastBootUpTime` |
| `system.pending_reboot` | — | `1` if **any** of the signals below is present, else `0` |

Pending-reboot signals, each probed independently so one unreadable key cannot
suppress the others:

* `…\Component Based Servicing\RebootPending`
* `…\Component Based Servicing\RebootInProgress`
* `…\WindowsUpdate\Auto Update\RebootRequired`
* `…\WindowsUpdate\Auto Update\PostRebootReporting`
* `HKLM:\SYSTEM\CurrentControlSet\Control\Session Manager\PendingFileRenameOperations`
* `ActiveComputerName` ≠ `ComputerName` (a rename applied but not committed)

### Battery — `Win32_Battery` + `root\wmi` ACPI classes

| Metric | dim | Source |
|---|---|---|
| `battery.charge_percent` | — | `Win32_Battery.EstimatedChargeRemaining`, averaged across packs |
| `battery.health_percent` | — | `BatteryFullChargedCapacity.FullChargedCapacity ÷ BatteryStaticData.DesignedCapacity × 100` |

Health is battery **wear**. Capacities are summed across packs *before* dividing,
so a two-battery laptop reports one honest figure rather than two halves, and the
two classes are matched on `InstanceName` so a pack's full-charge capacity is
never divided by a different pack's design capacity.

### GPU — `nvidia-smi.exe`, NVIDIA discrete only

| Metric | dim | Instance key | Source field |
|---|---|---|---|
| `gpu.utilization` | gpu | `0`, `1` … | `utilization.gpu` |
| `gpu.memory_used_bytes` | gpu | as above | `memory.used` (MiB × 1 048 576) |
| `gpu.memory_total_bytes` | gpu | as above | `memory.total` (MiB × 1 048 576) |
| `gpu.temperature` | gpu | as above | `temperature.gpu` |

`nvidia-smi.exe` is looked for at `%SystemRoot%\System32\nvidia-smi.exe` (modern
drivers), then `%ProgramFiles%\NVIDIA Corporation\NVSMI\nvidia-smi.exe` (legacy),
then on `PATH`. If it is absent, **no `gpu.*` sample is emitted at all**.

It is run behind a hard **15 second timeout** because it blocks indefinitely when
the driver is wedged, and a hung script check is worse than a missing metric.

Each of the four fields is parsed independently: `--format=…,nounits` still emits
`[N/A]` for fields a particular card does not support, and one absent field must
not lose the other three.

---

## 6. Metrics that are expected to be absent, and why

| Metric | Absent on | Why |
|---|---|---|
| **`cpu.temperature`** | **everything — it does not exist and never will** | `MSAcpi_ThermalZoneTemperature` reports "Not Supported" on most physical hardware and **always** inside a VM; `windows_exporter` deprecated its thermalzone collector for the same reason. Real die temperature requires a WinRing0-class kernel driver that Microsoft Defender flags as `HackTool:Win32/Winring0`. There is **no** `cpu.temperature` key in `MetricRegistry` and one must never be added. |
| `gpu.*` | every machine without an NVIDIA discrete card | Only NVIDIA exposes utilisation, VRAM and temperature through NVML/`nvidia-smi`. Intel integrated and AMD have no free CLI equivalent. The registry marks all four keys `TIER_OPTIONAL` so the UI renders "not supported" rather than a misleading flat zero. |
| `battery.charge_percent`, `battery.health_percent` | desktops, servers, VMs | No battery. Also omitted on machines whose vendor reports `DesignedCapacity` as 0 or omits `BatteryStaticData` — better no health sample than a fabricated 100%. |
| `disk.latency_ms` | any disk idle for the whole sampling window | The `PERF_AVERAGE_TIMER` base delta is 0, meaning zero transfers occurred. There is no latency to report. |
| `network.*` for a given adapter | disconnected NICs, loopback, Teredo/ISATAP, most virtual adapters | Filtered by `NetConnectionStatus = 2`. A server with no Wi-Fi adapter simply has no Wi-Fi series. |
| `cpu.core.utilization` for cores 64+ | >64-thread servers | The legacy `Processor` counter set covers processor group 0 only. |
| everything | first cycle after a counter reset | A counter that moved backwards is omitted for that cycle rather than reported as a spike. |

The device page distinguishes **"unsupported"** from **"nothing collected yet"**
via `GET /api/v1/metrics/devices/{id}/capabilities`. Omitting a metric here is
what makes that distinction possible — sending a zero would destroy it.

---

## 7. Payload contract

`POST {ApiUrl}/api/v1/metrics-ingest`

Headers:

```
X-Device-Token: itfm1.<selector>.<verifier>
Content-Type: application/json
Content-Length: <exact byte count — required>
```

Body:

```json
{
  "schema": "itflow.metrics.v1",
  "collector_version": "1.0.0",
  "device": {
    "hostname": "WS-042",
    "machine_guid": "8f0a…",
    "agent_id": "aBcD…",
    "os": "Microsoft Windows 11 Pro"
  },
  "collected_at": "2026-09-05T12:00:00Z",
  "sample_window_seconds": 5,
  "samples": [
    { "metric": "cpu.utilization",      "value": 12.5,       "at": "2026-09-05T12:00:00Z" },
    { "metric": "cpu.core.utilization", "value": 9.1,        "at": "2026-09-05T12:00:00Z", "instance": "0",  "label": "Core 0" },
    { "metric": "disk.free_bytes",      "value": 1.23456e11, "at": "2026-09-05T12:00:00Z", "instance": "C:", "label": "C: OS" }
  ]
}
```

Rules the server enforces:

* **`asset_id` is never sent and would be ignored.** The asset comes from the
  token and from nowhere else — a device credential must not be able to write
  another device's series.
* **`at` is UTC**, ISO-8601 with a trailing `Z`. `device_metric_samples.sampled_at`
  is stored in UTC — a deliberate divergence from RivetIT's local-time convention.
  A bare timestamp with no zone is read *as* UTC.
* One timestamp for the whole batch, truncated to the **second**. That matters:
  `(asset_id, metric_id, instance_id, sampled_at)` is the primary key, so a
  re-pushed batch with an identical stamp dedupes server-side through
  `INSERT … ON DUPLICATE KEY UPDATE` instead of creating a near-duplicate point a
  fraction of a second away. Retries are free.
* A metric with a declared dimension **must** carry `instance`; a host-level
  metric **must not**. The server rejects both mismatches rather than guessing —
  a dimensioned sample filed under the host sentinel overwrites every sibling
  volume.
* Values outside a metric's registry range are **rejected, not clamped**. A CPU
  reading of 1200% is a collection bug; clamping it to 100 would bake that bug
  into the history permanently with no way to tell later that it was ever wrong.
* Max **2000** samples and **512 KiB** per batch.
* **No gzip.** The server refuses a compressed request body — nginx has no
  request-body gunzip, so PHP would be the one inflating it, and a few KB of gzip
  can become hundreds of MB in memory. 26 devices posting a few KB every five
  minutes do not need compression.

Response (`200`):

```json
{ "data": { "asset_id": 42, "received": 47, "accepted": 47,
            "inserted": 47, "duplicate": 0, "rejected": 0,
            "reject_reasons": {}, "server_time": "2026-09-05T12:00:01Z" } }
```

| Status | Meaning | Script behaviour |
|---|---|---|
| `200` | Accepted (possibly with some samples rejected — reported, not fatal) | exit 0 |
| `401` | Token unknown, revoked, superseded or expired | re-enroll once if possible, else exit 1 |
| `403` | The bound asset is archived or gone | exit 1 |
| `411` / `413` / `415` | Missing/oversized `Content-Length`, or a compressed or non-JSON body | exit 1 |
| `422` | Every sample in the batch was rejected — a producer bug | exit 1 |
| `429` | Per-device rate limit | exit 1 |
| `503` | `config_enable_device_metrics = 0` | **exit 0** — a deliberate setting, not a device fault |

---

## 8. Troubleshooting

**Check output says `omitted: gpu (nvidia-smi.exe not present…)`**
Working as designed on any machine without an NVIDIA discrete card. Same for
`battery (no battery present)` on desktops, servers and VMs.

**`ERROR: push failed with HTTP 401`**
The device token was revoked or superseded — usually because the machine was
re-enrolled from somewhere else. With `-EnrollmentToken` set the script recovers
itself on the next run. Otherwise delete
`%ProgramData%\ITFlow\metrics\device_token.txt` and re-enroll.

**`ERROR: push failed with HTTP 409` during enrollment**
The hostname matches more than one live RivetIT asset. Archive the duplicate or
mint a device token for the right asset by hand and pass it with `-DeviceToken`.

**`ERROR: push failed with HTTP 404` during enrollment**
No asset matched. Confirm the machine has an `asset_rmm_links` row (i.e. the RMM
sync has seen it) or that a RivetIT asset's name equals the Windows hostname.

**`SKIPPED: … device metrics are disabled in RivetIT`**
`config_enable_device_metrics` is 0. The check passes on purpose.

**`WARN: server rejected N sample(s)`**
The response's `reject_reasons` names each one — `invalid_value` (out of registry
range), `unknown_metric`, `missing_instance_key`, `unexpected_instance_key`. This
is a collector or registry mismatch, and it is logged server-side to the RivetIT
audit trail as well. It should always be zero.

**Nothing at all is collected (`exit 1`, "no metrics could be collected")**
Almost always a broken WMI repository. Verify with
`Get-CimInstance Win32_OperatingSystem`; if that fails, WMI needs repair and no
monitoring tool on the box is reporting correctly.

**Check times out**
Raise the Tactical script timeout above `SampleIntervalSeconds + 15 + TimeoutSeconds`.
The default 90 s already covers the defaults with room to spare.
