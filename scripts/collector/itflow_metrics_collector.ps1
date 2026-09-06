<#
.SYNOPSIS
    ITFlow device metrics collector for Windows endpoints.

.DESCRIPTION
    Gathers one batch of device metrics and POSTs it to ITFlow's device-metrics
    ingest endpoint. Designed to run as a Tactical RMM *Script Check* on a 300
    second interval.

    Naming note: this subsystem is "Metrics", never "telemetry". ITFlow already
    has a `config_telemetry` setting that means anonymous phone-home usage
    reporting, and the two must never be confused.

    DESIGN RULE: a missing metric is NEVER a check failure.
    Every collector below is wrapped in its own try/catch and simply omits what
    it cannot read. A laptop with no NVIDIA card, a VM with no battery and a
    server with no Wi-Fi adapter all produce a clean partial payload. The check
    fails only when the push itself fails (network, auth, server error), which is
    the one condition actually worth alerting on.

    CPU TEMPERATURE IS DELIBERATELY NOT COLLECTED.
    MSAcpi_ThermalZoneTemperature returns "Not Supported" on most physical
    hardware and always inside a VM, and real die temperature requires a
    WinRing0-class kernel driver that Microsoft Defender flags as
    HackTool:Win32/Winring0. There is no `cpu.temperature` key in the ITFlow
    metric registry and one must never be added.

    RATES ARE COMPUTED HERE, NOT ON THE SERVER.
    Network throughput and disk IOPS come from raw cumulative performance
    counters read twice, -SampleIntervalSeconds apart, and divided by the true
    elapsed counter time. The raw counter classes (Win32_PerfRawData_*) are used
    rather than Get-Counter because Get-Counter's counter paths are localised and
    break on non-English Windows.

.PARAMETER ApiUrl
    Base URL of the ITFlow instance, e.g. https://itflow.example.com
    A full endpoint URL (.../api/v1/metrics-ingest) is also accepted.

.PARAMETER DeviceToken
    The per-device token issued for this asset (itfm1.<selector>.<verifier>).
    In Tactical, supply it per agent with a custom-field substitution, e.g.
        -ApiUrl "https://itflow.example.com" -DeviceToken "{{agent.itflow_metrics_token}}"
    Never pass a user API token here - a device token can only write its own
    asset's series and can read nothing.

.PARAMETER EnrollmentToken
    Optional. A shared, revocable enrollment token. When -DeviceToken is not
    supplied (or the stored one is rejected with 401) the script exchanges this
    for a per-device token and caches it under
    %ProgramData%\ITFlow\metrics\device_token.txt with an ACL restricted to
    SYSTEM and Administrators. This lets one check definition with one shared
    secret be deployed to the whole fleet.

.PARAMETER SampleIntervalSeconds
    Seconds between the two performance-counter snapshots used to compute rates.
    Default 5. Longer smooths spikes; shorter is noisier. Must be 1-60.

.PARAMETER TimeoutSeconds
    HTTP timeout for the push. Default 30.

.PARAMETER NeverFail
    Always exit 0, even when the push fails. Use when the check should collect
    metrics but must not raise Tactical alerts.

.PARAMETER DryRun
    Print the JSON payload instead of posting it. Nothing is sent and no token
    is required. Use this to validate a new deployment on one machine first.

.EXAMPLE
    .\itflow_metrics_collector.ps1 -ApiUrl "https://itflow.example.com" -DeviceToken "itfm1.a1b2c3d4e5f60718.Zm9vYmFy..."

.EXAMPLE
    .\itflow_metrics_collector.ps1 -ApiUrl "https://itflow.example.com" -EnrollmentToken "itfm1.0011223344556677.c2hhcmVk..."

.EXAMPLE
    .\itflow_metrics_collector.ps1 -ApiUrl "https://itflow.example.com" -DryRun

.NOTES
    Requires Windows PowerShell 5.1 (the shell Tactical RMM uses by default).
    No modules beyond what ships with Windows. Runs as SYSTEM under the agent.
#>

[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string] $ApiUrl,

    [string] $DeviceToken = '',

    [string] $EnrollmentToken = '',

    [ValidateRange(1, 60)]
    [int] $SampleIntervalSeconds = 5,

    [ValidateRange(5, 300)]
    [int] $TimeoutSeconds = 30,

    [switch] $NeverFail,

    [switch] $DryRun
)

# Non-terminating errors become catchable so every collector's try/catch
# actually catches. Each collector re-establishes its own boundary.
$ErrorActionPreference = 'Stop'
$ProgressPreference    = 'SilentlyContinue'

$script:CollectorVersion = '1.0.0'
$script:SchemaTag        = 'itflow.metrics.v1'
$script:Samples          = New-Object System.Collections.Generic.List[object]
$script:Skipped          = New-Object System.Collections.Generic.List[string]
$script:SampleStamp      = ''
$script:TokenCacheDir    = Join-Path $env:ProgramData 'ITFlow\metrics'
$script:TokenCacheFile   = Join-Path $script:TokenCacheDir 'device_token.txt'

# TLS 1.2 minimum. PowerShell 5.1 still defaults to SSL3/TLS1.0 on older builds,
# which every reasonable ITFlow deployment refuses.
try {
    $proto = [Net.SecurityProtocolType]::Tls12
    if ([Enum]::IsDefined([Net.SecurityProtocolType], 'Tls13')) {
        $proto = $proto -bor [Net.SecurityProtocolType]::Tls13
    }
    [Net.ServicePointManager]::SecurityProtocol = $proto
} catch {
    # Leave the platform default in place rather than failing the check.
}

# ---------------------------------------------------------------------------
# Small helpers
# ---------------------------------------------------------------------------

function Write-Note {
    param([string] $Message)
    Write-Output $Message
}

function Add-Skip {
    param([string] $What, [string] $Why)
    $script:Skipped.Add(('{0} ({1})' -f $What, $Why)) | Out-Null
}

<#
    Queue one reading. $Instance is the dimension member (volume "C:", core "0",
    NIC name, physical disk "0 C:", GPU index) and MUST be supplied for exactly
    the metrics the ITFlow registry declares a dimension for - the server rejects
    a dimensioned sample with no instance, and a host-level sample that carries
    one, rather than guessing.
#>
function Add-Sample {
    param(
        [Parameter(Mandatory = $true)][string] $Metric,
        [Parameter(Mandatory = $true)][double] $Value,
        [string] $Instance = '',
        [string] $Label = ''
    )

    if ([double]::IsNaN($Value) -or [double]::IsInfinity($Value)) { return }

    $entry = [ordered]@{
        metric = $Metric
        value  = [math]::Round($Value, 4)
        at     = $script:SampleStamp
    }
    if ($Instance -ne '') {
        # device_metric_instances.instance_key is VARCHAR(96).
        if ($Instance.Length -gt 96) { $Instance = $Instance.Substring(0, 96) }
        $entry['instance'] = $Instance
    }
    if ($Label -ne '') {
        # device_metric_instances.instance_label is VARCHAR(128).
        if ($Label.Length -gt 128) { $Label = $Label.Substring(0, 128) }
        $entry['label'] = $Label
    }

    $script:Samples.Add($entry) | Out-Null
}

function Clamp-Percent {
    param([double] $Value)
    if ($Value -lt 0)   { return [double] 0 }
    if ($Value -gt 100) { return [double] 100 }
    return $Value
}

<#
    Delta between two cumulative performance counter readings.

    Returns $null when the counter went backwards, which means it was reset (a
    reboot, an adapter being disabled and re-enabled, a 64-bit wrap). Returning
    $null makes the caller omit the metric for this cycle rather than emit a
    wildly wrong spike that the server would reject anyway.

    Both operands are widened to [double] first: PowerShell throws when a UInt64
    subtraction would go negative, and these counters are UInt64. Timestamp
    counters run to ~1.3e17, past double's 2^53 exact-integer range, but the
    resulting ULP is 16 x 100ns = 1.6us against a multi-second window, which is
    six orders of magnitude below anything that matters here.
#>
function Get-CounterDelta {
    param($New, $Old)
    if ($null -eq $New -or $null -eq $Old) { return $null }
    $n = [double] $New
    $o = [double] $Old
    if ($n -lt $o) { return $null }
    return ($n - $o)
}

<#
    Windows performance-counter instance names are sanitised versions of the
    device names: "(" -> "[", ")" -> "]", and "#", "/", "\" -> "_". Applying the
    same transform to a Win32_NetworkAdapter name lets a perf instance be matched
    back to a real adapter, which is how disconnected and virtual adapters are
    filtered out.
#>
function ConvertTo-PerfInstanceName {
    param([string] $Name)
    if ([string]::IsNullOrEmpty($Name)) { return '' }
    $n = $Name -replace '\(', '[' -replace '\)', ']' -replace '#', '_' -replace '/', '_' -replace '\\', '_'
    return $n.Trim()
}

function Invoke-ExeWithTimeout {
    param(
        [Parameter(Mandatory = $true)][string] $FilePath,
        [string] $Arguments = '',
        [int] $TimeoutMs = 15000
    )

    $psi = New-Object System.Diagnostics.ProcessStartInfo
    $psi.FileName               = $FilePath
    $psi.Arguments              = $Arguments
    $psi.RedirectStandardOutput = $true
    $psi.RedirectStandardError  = $true
    $psi.UseShellExecute        = $false
    $psi.CreateNoWindow         = $true

    $proc = New-Object System.Diagnostics.Process
    $proc.StartInfo = $psi
    $proc.Start() | Out-Null

    # Read asynchronously so a large stdout cannot deadlock against WaitForExit.
    $stdoutTask = $proc.StandardOutput.ReadToEndAsync()
    $stderrTask = $proc.StandardError.ReadToEndAsync()

    if (-not $proc.WaitForExit($TimeoutMs)) {
        try { $proc.Kill() } catch { }
        try { $proc.Dispose() } catch { }
        return $null
    }
    $proc.WaitForExit()

    $out  = $stdoutTask.Result
    $null = $stderrTask.Result
    $code = $proc.ExitCode
    try { $proc.Dispose() } catch { }

    if ($code -ne 0) { return $null }
    return $out
}

# ---------------------------------------------------------------------------
# Device identity
# ---------------------------------------------------------------------------

function Get-DeviceIdentity {
    $identity = [ordered]@{
        hostname     = $env:COMPUTERNAME
        machine_guid = ''
        agent_id     = ''
        os           = ''
    }

    try {
        $identity['machine_guid'] = (Get-ItemProperty -Path 'HKLM:\SOFTWARE\Microsoft\Cryptography' -Name 'MachineGuid').MachineGuid
    } catch { }

    # The Tactical RMM agent stores its own agent id here. Sending it lets the
    # server bind an enrollment to the right asset via asset_rmm_links even when
    # the ITFlow asset name and the Windows hostname disagree.
    foreach ($key in @('HKLM:\SOFTWARE\TacticalRMM', 'HKLM:\SOFTWARE\WOW6432Node\TacticalRMM')) {
        try {
            $agentId = (Get-ItemProperty -Path $key -Name 'AgentID').AgentID
            if (-not [string]::IsNullOrWhiteSpace($agentId)) {
                $identity['agent_id'] = $agentId
                break
            }
        } catch { }
    }

    try {
        $identity['os'] = (Get-CimInstance -ClassName Win32_OperatingSystem).Caption
    } catch { }

    return $identity
}

# ---------------------------------------------------------------------------
# Performance-counter snapshots (raw, cumulative - rates computed below)
# ---------------------------------------------------------------------------

function Get-PerfSnapshot {
    $snap = @{
        Processor = @{}
        Disk      = @{}
        Network   = @{}
    }

    try {
        foreach ($row in Get-CimInstance -ClassName Win32_PerfRawData_PerfOS_Processor) {
            $snap.Processor[[string] $row.Name] = $row
        }
    } catch { }

    try {
        foreach ($row in Get-CimInstance -ClassName Win32_PerfRawData_PerfDisk_PhysicalDisk) {
            $snap.Disk[[string] $row.Name] = $row
        }
    } catch { }

    try {
        foreach ($row in Get-CimInstance -ClassName Win32_PerfRawData_Tcpip_NetworkInterface) {
            $snap.Network[[string] $row.Name] = $row
        }
    } catch { }

    return $snap
}

# ---------------------------------------------------------------------------
# Collectors
# ---------------------------------------------------------------------------

<#
    Per-core and total CPU.

    `% Processor Time` is counter type PERF_100NSEC_TIMER_INV: the raw value is
    accumulated IDLE time in 100ns units, so busy% is
        100 * (1 - (dPercentProcessorTime / dTimestamp_Sys100NS))

    Total CPU is the MEAN of the per-core values rather than the "_Total"
    instance. The raw _Total row's semantics vary with how Windows aggregates a
    multi-counter instance, whereas the mean of the cores is unambiguous and is
    exactly what "CPU utilisation" is taken to mean everywhere else in ITFlow.

    LIMITATION: the legacy `Processor` counter set this class exposes covers only
    processor group 0, i.e. the first 64 logical processors. Nothing in this
    fleet is near that; a >64-thread server would report the first group only.
#>
function Collect-Cpu {
    param($Snap1, $Snap2)

    try {
        $coreValues = New-Object System.Collections.Generic.List[double]

        foreach ($name in ($Snap2.Processor.Keys | Sort-Object)) {
            if ($name -eq '_Total') { continue }
            if (-not $Snap1.Processor.ContainsKey($name)) { continue }

            $old = $Snap1.Processor[$name]
            $new = $Snap2.Processor[$name]

            $dIdle = Get-CounterDelta $new.PercentProcessorTime $old.PercentProcessorTime
            $dTime = Get-CounterDelta $new.Timestamp_Sys100NS   $old.Timestamp_Sys100NS
            if ($null -eq $dIdle -or $null -eq $dTime -or $dTime -le 0) { continue }

            $busy = Clamp-Percent (100.0 * (1.0 - ($dIdle / $dTime)))
            $coreValues.Add($busy) | Out-Null

            Add-Sample -Metric 'cpu.core.utilization' -Value $busy -Instance $name -Label ('Core ' + $name)
        }

        if ($coreValues.Count -gt 0) {
            $total = 0.0
            foreach ($v in $coreValues) { $total += $v }
            Add-Sample -Metric 'cpu.utilization' -Value (Clamp-Percent ($total / $coreValues.Count))
        } else {
            # Fall back to the WMI aggregate if the perf counters were unusable.
            $load = (Get-CimInstance -ClassName Win32_Processor | Measure-Object -Property LoadPercentage -Average).Average
            if ($null -ne $load) {
                Add-Sample -Metric 'cpu.utilization' -Value (Clamp-Percent ([double] $load))
            } else {
                Add-Skip 'cpu' 'no usable processor counters'
            }
        }
    } catch {
        Add-Skip 'cpu' $_.Exception.Message
    }
}

<#
    Memory.

    total     = Win32_OperatingSystem.TotalVisibleMemorySize (OS-visible RAM, KB)
    available = PerfOS_Memory.AvailableBytes (the real "Available MBytes":
                free + standby + zeroed), falling back to FreePhysicalMemory
    used      = total - available

    "Available" rather than "Free" on purpose: Windows keeps a large standby
    cache that is instantly reclaimable, so free-only accounting reports a
    healthy machine as ~95% used and every memory chart becomes useless.
#>
function Collect-Memory {
    try {
        $os = Get-CimInstance -ClassName Win32_OperatingSystem
        $totalBytes = [double] $os.TotalVisibleMemorySize * 1024.0
        if ($totalBytes -le 0) { Add-Skip 'memory' 'total reported as zero'; return }

        $availableBytes = $null
        try {
            $mem = Get-CimInstance -ClassName Win32_PerfRawData_PerfOS_Memory
            if ($null -ne $mem -and $null -ne $mem.AvailableBytes) {
                $availableBytes = [double] $mem.AvailableBytes
            }
        } catch { }
        if ($null -eq $availableBytes) {
            $availableBytes = [double] $os.FreePhysicalMemory * 1024.0
        }

        if ($availableBytes -gt $totalBytes) { $availableBytes = $totalBytes }
        $usedBytes = $totalBytes - $availableBytes

        Add-Sample -Metric 'memory.total_bytes'     -Value $totalBytes
        Add-Sample -Metric 'memory.available_bytes' -Value $availableBytes
        Add-Sample -Metric 'memory.used_bytes'      -Value $usedBytes
        Add-Sample -Metric 'memory.utilization'     -Value (Clamp-Percent (100.0 * $usedBytes / $totalBytes))
    } catch {
        Add-Skip 'memory' $_.Exception.Message
    }
}

<#
    Per-volume space. DriveType 3 is "Local Disk"; network shares, optical drives
    and removable media are excluded so a mounted USB stick or an empty DVD tray
    never appears as a device volume with 100% usage.

    The instance key is the drive letter with its colon ("C:"), which is stable
    across reboots and matches what the Tactical/vendor providers emit for
    disk.utilization, so both sources land on the same series.
#>
function Collect-Volumes {
    try {
        foreach ($vol in Get-CimInstance -ClassName Win32_LogicalDisk -Filter 'DriveType = 3') {
            try {
                $id    = [string] $vol.DeviceID
                $size  = [double] $vol.Size
                $free  = [double] $vol.FreeSpace
                if ($id -eq '' -or $size -le 0) { continue }
                if ($free -lt 0) { $free = 0 }
                if ($free -gt $size) { $free = $size }

                $label = $id
                if (-not [string]::IsNullOrWhiteSpace($vol.VolumeName)) {
                    $label = '{0} {1}' -f $id, $vol.VolumeName
                }

                Add-Sample -Metric 'disk.total_bytes'  -Value $size -Instance $id -Label $label
                Add-Sample -Metric 'disk.free_bytes'   -Value $free -Instance $id -Label $label
                Add-Sample -Metric 'disk.utilization'  -Value (Clamp-Percent (100.0 * ($size - $free) / $size)) -Instance $id -Label $label
            } catch {
                # One unreadable volume must not lose the others.
            }
        }
    } catch {
        Add-Skip 'volumes' $_.Exception.Message
    }
}

<#
    Physical-disk IOPS, queue length and latency.

    Reads/Writes/sec are PERF_COUNTER_COUNTER: rate = dCount / (dPerfTime / F).
    Current queue length is an instantaneous gauge and is read directly.
    Avg. Disk sec/Transfer is PERF_AVERAGE_TIMER:
        seconds = (dNumerator / Frequency_PerfTime) / dBase
    which is then reported in milliseconds.

    Instance names look like "0 C:" or "1 D: E:". "_Total" is skipped - a fleet
    average across spindles hides exactly the one slow disk you are looking for.
#>
function Collect-PhysicalDisks {
    param($Snap1, $Snap2)

    try {
        foreach ($name in ($Snap2.Disk.Keys | Sort-Object)) {
            if ($name -eq '_Total') { continue }
            if (-not $Snap1.Disk.ContainsKey($name)) { continue }

            try {
                $old = $Snap1.Disk[$name]
                $new = $Snap2.Disk[$name]

                $freq = [double] $new.Frequency_PerfTime
                $dPerf = Get-CounterDelta $new.Timestamp_PerfTime $old.Timestamp_PerfTime
                if ($null -eq $dPerf -or $freq -le 0 -or $dPerf -le 0) { continue }
                $elapsedSeconds = $dPerf / $freq
                if ($elapsedSeconds -le 0) { continue }

                $dReads  = Get-CounterDelta $new.DiskReadsPersec  $old.DiskReadsPersec
                $dWrites = Get-CounterDelta $new.DiskWritesPersec $old.DiskWritesPersec

                if ($null -ne $dReads) {
                    Add-Sample -Metric 'disk.read_iops' -Value ($dReads / $elapsedSeconds) -Instance $name -Label $name
                }
                if ($null -ne $dWrites) {
                    Add-Sample -Metric 'disk.write_iops' -Value ($dWrites / $elapsedSeconds) -Instance $name -Label $name
                }

                if ($null -ne $new.CurrentDiskQueueLength) {
                    Add-Sample -Metric 'disk.queue_length' -Value ([double] $new.CurrentDiskQueueLength) -Instance $name -Label $name
                }

                $dNum  = Get-CounterDelta $new.AvgDisksecPerTransfer      $old.AvgDisksecPerTransfer
                $dBase = Get-CounterDelta $new.AvgDisksecPerTransfer_Base $old.AvgDisksecPerTransfer_Base
                if ($null -ne $dNum -and $null -ne $dBase -and $dBase -gt 0) {
                    $latencyMs = (($dNum / $freq) / $dBase) * 1000.0
                    if ($latencyMs -ge 0) {
                        Add-Sample -Metric 'disk.latency_ms' -Value $latencyMs -Instance $name -Label $name
                    }
                }
                # dBase of 0 means the disk was completely idle across the window.
                # No transfers means no latency to report - emitting 0 would claim
                # a measurement that was never taken.
            } catch {
                # Skip the one disk, keep the rest.
            }
        }
    } catch {
        Add-Skip 'physical disks' $_.Exception.Message
    }
}

<#
    Per-NIC throughput and errors.

    Bytes Received/sec and Bytes Sent/sec are PERF_COUNTER_COUNTER, so the rate
    is dBytes / (dPerfTime / F) - computed here, on the endpoint, exactly as the
    ingest contract requires. Packet error counts are cumulative counters and are
    sent as-is, because network.errors is declared KIND_COUNTER in the registry.

    Only adapters that Windows reports as connected (NetConnectionStatus 2) are
    reported, matched by applying the performance-counter name sanitisation to
    the adapter name. That removes loopback, Teredo/ISATAP tunnels, disconnected
    Wi-Fi radios and the pile of virtual adapters a Hyper-V host accumulates. If
    that mapping yields nothing (an exotic driver naming case) the script falls
    back to every non-loopback perf instance rather than reporting no network at
    all.
#>
function Collect-Network {
    param($Snap1, $Snap2)

    try {
        $connected = @{}
        try {
            foreach ($adapter in Get-CimInstance -ClassName Win32_NetworkAdapter -Filter 'NetConnectionStatus = 2') {
                $perfName = ConvertTo-PerfInstanceName ([string] $adapter.Name)
                if ($perfName -ne '') { $connected[$perfName] = [string] $adapter.NetConnectionID }
            }
        } catch { }

        $useFilter = ($connected.Count -gt 0)

        foreach ($name in ($Snap2.Network.Keys | Sort-Object)) {
            if (-not $Snap1.Network.ContainsKey($name)) { continue }

            $lower = $name.ToLowerInvariant()
            if ($lower -like '*loopback*' -or $lower -like '*isatap*' -or $lower -like '*teredo*') { continue }
            if ($useFilter -and -not $connected.ContainsKey($name)) { continue }

            try {
                $old = $Snap1.Network[$name]
                $new = $Snap2.Network[$name]

                $freq  = [double] $new.Frequency_PerfTime
                $dPerf = Get-CounterDelta $new.Timestamp_PerfTime $old.Timestamp_PerfTime
                if ($null -eq $dPerf -or $freq -le 0 -or $dPerf -le 0) { continue }
                $elapsedSeconds = $dPerf / $freq
                if ($elapsedSeconds -le 0) { continue }

                $label = $name
                if ($connected.ContainsKey($name) -and -not [string]::IsNullOrWhiteSpace($connected[$name])) {
                    $label = $connected[$name]
                }

                $dRx = Get-CounterDelta $new.BytesReceivedPersec $old.BytesReceivedPersec
                $dTx = Get-CounterDelta $new.BytesSentPersec     $old.BytesSentPersec

                if ($null -ne $dRx) {
                    Add-Sample -Metric 'network.rx_bytes_per_s' -Value ($dRx / $elapsedSeconds) -Instance $name -Label $label
                }
                if ($null -ne $dTx) {
                    Add-Sample -Metric 'network.tx_bytes_per_s' -Value ($dTx / $elapsedSeconds) -Instance $name -Label $label
                }

                $errIn  = 0.0
                $errOut = 0.0
                if ($null -ne $new.PacketsReceivedErrors) { $errIn  = [double] $new.PacketsReceivedErrors }
                if ($null -ne $new.PacketsOutboundErrors) { $errOut = [double] $new.PacketsOutboundErrors }
                Add-Sample -Metric 'network.errors' -Value ($errIn + $errOut) -Instance $name -Label $label
            } catch {
                # Skip the one adapter, keep the rest.
            }
        }
    } catch {
        Add-Skip 'network' $_.Exception.Message
    }
}

function Collect-System {
    try {
        $os = Get-CimInstance -ClassName Win32_OperatingSystem
        $uptime = ((Get-Date) - $os.LastBootUpTime).TotalSeconds
        if ($uptime -ge 0) {
            Add-Sample -Metric 'system.uptime_seconds' -Value ([math]::Floor($uptime))
        }
    } catch {
        Add-Skip 'uptime' $_.Exception.Message
    }

    try {
        Add-Sample -Metric 'system.pending_reboot' -Value (Get-PendingRebootFlag)
    } catch {
        Add-Skip 'pending reboot' $_.Exception.Message
    }
}

<#
    Pending reboot. Each signal is probed independently so one unreadable key
    cannot suppress the others; any single hit means "reboot pending".

      CBS\RebootPending          - servicing stack staged an update
      WindowsUpdate\RebootRequired - Windows Update staged an update
      CBS\RebootInProgress       - servicing is mid-reboot sequence
      PendingFileRenameOperations - a file replacement is queued for next boot
      ComputerName mismatch      - a rename has been applied but not committed
#>
function Get-PendingRebootFlag {
    $pending = $false

    foreach ($key in @(
        'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Component Based Servicing\RebootPending',
        'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Component Based Servicing\RebootInProgress',
        'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\WindowsUpdate\Auto Update\RebootRequired',
        'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\WindowsUpdate\Auto Update\PostRebootReporting'
    )) {
        try {
            if (Test-Path -Path $key) { $pending = $true }
        } catch { }
    }

    if (-not $pending) {
        try {
            $sm = Get-ItemProperty -Path 'HKLM:\SYSTEM\CurrentControlSet\Control\Session Manager' -Name 'PendingFileRenameOperations'
            if ($null -ne $sm.PendingFileRenameOperations -and @($sm.PendingFileRenameOperations).Count -gt 0) {
                $pending = $true
            }
        } catch { }
    }

    if (-not $pending) {
        try {
            $active  = (Get-ItemProperty -Path 'HKLM:\SYSTEM\CurrentControlSet\Control\ComputerName\ActiveComputerName' -Name 'ComputerName').ComputerName
            $desired = (Get-ItemProperty -Path 'HKLM:\SYSTEM\CurrentControlSet\Control\ComputerName\ComputerName' -Name 'ComputerName').ComputerName
            if ($active -ne $desired) { $pending = $true }
        } catch { }
    }

    if ($pending) { return [double] 1 }
    return [double] 0
}

<#
    Battery charge and health.

    Charge comes from Win32_Battery.EstimatedChargeRemaining, averaged when a
    machine has more than one pack.

    Health is battery WEAR, computed from the root\wmi ACPI classes:
        health% = FullChargedCapacity / DesignedCapacity * 100
    Capacities are summed across packs before dividing so a two-battery laptop
    reports one honest figure rather than two halves. Both classes are matched on
    InstanceName so a pack's full-charge capacity is never divided by another
    pack's design capacity.

    EXPECTED TO BE ABSENT on desktops, servers and VMs - there is no battery, so
    both metrics are simply omitted. Some vendors also report DesignedCapacity as
    0 or omit BatteryStaticData entirely; that yields no health sample rather
    than a fabricated 100%.
#>
function Collect-Battery {
    $batteries = $null
    try {
        $batteries = @(Get-CimInstance -ClassName Win32_Battery)
    } catch {
        Add-Skip 'battery' $_.Exception.Message
        return
    }

    if ($null -eq $batteries -or $batteries.Count -eq 0) {
        Add-Skip 'battery' 'no battery present'
        return
    }

    try {
        $charge = ($batteries | Where-Object { $null -ne $_.EstimatedChargeRemaining } |
                   Measure-Object -Property EstimatedChargeRemaining -Average).Average
        if ($null -ne $charge) {
            Add-Sample -Metric 'battery.charge_percent' -Value (Clamp-Percent ([double] $charge))
        }
    } catch {
        Add-Skip 'battery charge' $_.Exception.Message
    }

    try {
        $designed = @{}
        foreach ($row in Get-CimInstance -Namespace 'root\wmi' -ClassName 'BatteryStaticData') {
            if ($null -ne $row.DesignedCapacity -and [double] $row.DesignedCapacity -gt 0) {
                $designed[[string] $row.InstanceName] = [double] $row.DesignedCapacity
            }
        }

        $designedTotal = 0.0
        $fullTotal     = 0.0
        foreach ($row in Get-CimInstance -Namespace 'root\wmi' -ClassName 'BatteryFullChargedCapacity') {
            $key = [string] $row.InstanceName
            if ($designed.ContainsKey($key) -and $null -ne $row.FullChargedCapacity) {
                $full = [double] $row.FullChargedCapacity
                if ($full -gt 0) {
                    $designedTotal += $designed[$key]
                    $fullTotal     += $full
                }
            }
        }

        if ($designedTotal -gt 0 -and $fullTotal -gt 0) {
            Add-Sample -Metric 'battery.health_percent' -Value (Clamp-Percent (100.0 * $fullTotal / $designedTotal))
        } else {
            Add-Skip 'battery health' 'ACPI design/full-charge capacity not reported'
        }
    } catch {
        Add-Skip 'battery health' $_.Exception.Message
    }
}

<#
    GPU - NVIDIA discrete only, and only when nvidia-smi.exe is actually present.

    There is no free, driver-less CLI that reports utilisation, VRAM and
    temperature for Intel integrated or AMD GPUs, so those machines emit no gpu.*
    samples at all. The ITFlow registry marks every gpu.* key TIER_OPTIONAL for
    exactly this reason: the UI renders "not supported" rather than a misleading
    flat zero.

    nvidia-smi is run through a hard 15s timeout because it blocks indefinitely
    when the driver is wedged, and a hung script check is worse than a missing
    metric.
#>
function Collect-Gpu {
    try {
        $smi = $null
        $candidates = @(
            (Join-Path $env:SystemRoot 'System32\nvidia-smi.exe'),
            (Join-Path ${env:ProgramFiles} 'NVIDIA Corporation\NVSMI\nvidia-smi.exe')
        )
        foreach ($candidate in $candidates) {
            if (-not [string]::IsNullOrEmpty($candidate) -and (Test-Path -Path $candidate -PathType Leaf)) {
                $smi = $candidate
                break
            }
        }
        if ($null -eq $smi) {
            try {
                $cmd = Get-Command -Name 'nvidia-smi.exe' -CommandType Application -ErrorAction SilentlyContinue
                if ($null -ne $cmd) { $smi = $cmd.Source }
            } catch { }
        }

        if ($null -eq $smi) {
            Add-Skip 'gpu' 'nvidia-smi.exe not present (no NVIDIA discrete GPU)'
            return
        }

        # NOT $args - that is an automatic variable in PowerShell.
        $smiArgs = '--query-gpu=index,name,utilization.gpu,memory.used,memory.total,temperature.gpu --format=csv,noheader,nounits'
        $out     = Invoke-ExeWithTimeout -FilePath $smi -Arguments $smiArgs -TimeoutMs 15000
        if ([string]::IsNullOrWhiteSpace($out)) {
            Add-Skip 'gpu' 'nvidia-smi returned nothing or timed out'
            return
        }

        foreach ($line in ($out -split "`r?`n")) {
            if ([string]::IsNullOrWhiteSpace($line)) { continue }
            $fields = $line.Split(',')
            if ($fields.Count -lt 6) { continue }

            try {
                # The first field is the index and the last four are the numbers;
                # everything between them is the card name, rejoined, because a
                # board name containing a comma would otherwise shift every
                # column and silently file VRAM under temperature.
                $index = $fields[0].Trim()
                $name  = ($fields[1..($fields.Count - 5)] -join ',').Trim()
                $numeric = $fields[($fields.Count - 4)..($fields.Count - 1)]
                if ($index -eq '') { continue }

                # nounits still yields "[N/A]" on unsupported fields, which is not
                # numeric - each is parsed independently so one absent field does
                # not lose the other three.
                $util = 0.0
                if ([double]::TryParse($numeric[0].Trim(), [ref] $util)) {
                    Add-Sample -Metric 'gpu.utilization' -Value (Clamp-Percent $util) -Instance $index -Label $name
                }

                $usedMib = 0.0
                if ([double]::TryParse($numeric[1].Trim(), [ref] $usedMib)) {
                    Add-Sample -Metric 'gpu.memory_used_bytes' -Value ($usedMib * 1048576.0) -Instance $index -Label $name
                }

                $totalMib = 0.0
                if ([double]::TryParse($numeric[2].Trim(), [ref] $totalMib)) {
                    Add-Sample -Metric 'gpu.memory_total_bytes' -Value ($totalMib * 1048576.0) -Instance $index -Label $name
                }

                $temp = 0.0
                if ([double]::TryParse($numeric[3].Trim(), [ref] $temp)) {
                    Add-Sample -Metric 'gpu.temperature' -Value $temp -Instance $index -Label $name
                }
            } catch {
                # Skip the one GPU line, keep the rest.
            }
        }
    } catch {
        Add-Skip 'gpu' $_.Exception.Message
    }
}

# ---------------------------------------------------------------------------
# Token cache (only used with -EnrollmentToken)
# ---------------------------------------------------------------------------

function Get-CachedToken {
    try {
        if (Test-Path -Path $script:TokenCacheFile -PathType Leaf) {
            $value = (Get-Content -Path $script:TokenCacheFile -Raw).Trim()
            if ($value -match '^itfm1\.[0-9a-f]{16}\.[A-Za-z0-9_-]{20,64}$') { return $value }
        }
    } catch { }
    return ''
}

<#
    Persist a device token with an ACL that grants only SYSTEM and the local
    Administrators group. Inheritance is switched off first, otherwise the
    default ProgramData ACE that grants Users read access survives and any
    logged-on user could read the credential straight out of the file.
#>
function Set-CachedToken {
    param([Parameter(Mandatory = $true)][string] $Token)

    try {
        if (-not (Test-Path -Path $script:TokenCacheDir -PathType Container)) {
            New-Item -Path $script:TokenCacheDir -ItemType Directory -Force | Out-Null
        }
        Set-Content -Path $script:TokenCacheFile -Value $Token -Encoding ASCII -Force

        $acl = Get-Acl -Path $script:TokenCacheFile
        $acl.SetAccessRuleProtection($true, $false)   # break inheritance, drop inherited ACEs
        foreach ($rule in @($acl.Access)) { $acl.RemoveAccessRule($rule) | Out-Null }

        foreach ($sid in @('S-1-5-18', 'S-1-5-32-544')) {   # LOCAL SYSTEM, BUILTIN\Administrators
            $account = New-Object System.Security.Principal.SecurityIdentifier($sid)
            $ace = New-Object System.Security.AccessControl.FileSystemAccessRule(
                $account, 'FullControl', 'None', 'None', 'Allow')
            $acl.AddAccessRule($ace)
        }
        Set-Acl -Path $script:TokenCacheFile -AclObject $acl
        return $true
    } catch {
        Write-Note ('WARN: could not cache the device token: ' + $_.Exception.Message)
        return $false
    }
}

# ---------------------------------------------------------------------------
# HTTP
# ---------------------------------------------------------------------------

function Resolve-IngestUrl {
    param([string] $Base)
    $u = $Base.Trim().TrimEnd('/')
    if ($u -match '/api/v1/metrics-ingest$') { return $u }
    if ($u -match '/api/v1$')                { return ($u + '/metrics-ingest') }
    return ($u + '/api/v1/metrics-ingest')
}

<#
    POST a JSON object. Returns a PSCustomObject:
      Ok       - $true on 2xx
      Status   - HTTP status code, or 0 when the request never got a response
      Body     - parsed response object when available
      Message  - human-readable failure detail

    The body is sent as a UTF-8 byte array rather than a string so Content-Length
    is the exact byte count. The server requires Content-Length and refuses
    chunked and compressed bodies, so an off-by-one here is a hard 400.
#>
function Invoke-IngestPost {
    param(
        [Parameter(Mandatory = $true)][string] $Url,
        [Parameter(Mandatory = $true)][string] $Token,
        [Parameter(Mandatory = $true)]$Payload
    )

    $json  = $Payload | ConvertTo-Json -Depth 6 -Compress
    $bytes = [System.Text.Encoding]::UTF8.GetBytes($json)

    $headers = @{
        'X-Device-Token' = $Token
        'Accept'         = 'application/json'
    }

    try {
        $response = Invoke-RestMethod -Uri $Url -Method Post -Body $bytes `
                        -ContentType 'application/json' -Headers $headers `
                        -TimeoutSec $TimeoutSeconds -UseBasicParsing
        return [PSCustomObject]@{ Ok = $true; Status = 200; Body = $response; Message = '' }
    } catch {
        $status = 0
        $body   = $null
        $text   = ''

        try {
            if ($null -ne $_.Exception.Response) {
                $status = [int] $_.Exception.Response.StatusCode
                $stream = $_.Exception.Response.GetResponseStream()
                if ($null -ne $stream) {
                    $reader = New-Object System.IO.StreamReader($stream)
                    $text   = $reader.ReadToEnd()
                    $reader.Close()
                }
                if (-not [string]::IsNullOrWhiteSpace($text)) {
                    try { $body = $text | ConvertFrom-Json } catch { }
                }
            }
        } catch { }

        $message = $_.Exception.Message
        if ($null -ne $body -and $null -ne $body.error) { $message = [string] $body.error }
        elseif (-not [string]::IsNullOrWhiteSpace($text)) { $message = $text.Substring(0, [math]::Min(300, $text.Length)) }

        return [PSCustomObject]@{ Ok = $false; Status = $status; Body = $body; Message = $message }
    }
}

function Invoke-Enrollment {
    param(
        [Parameter(Mandatory = $true)][string] $IngestUrl,
        [Parameter(Mandatory = $true)][string] $Token,
        [Parameter(Mandatory = $true)]$Identity
    )

    $url  = $IngestUrl + '/enroll'
    $json = ([ordered]@{
        hostname     = $Identity['hostname']
        agent_id     = $Identity['agent_id']
        machine_guid = $Identity['machine_guid']
    } | ConvertTo-Json -Depth 3 -Compress)
    $bytes = [System.Text.Encoding]::UTF8.GetBytes($json)

    $headers = @{
        'X-Device-Token' = $Token
        'Accept'         = 'application/json'
    }

    try {
        $response = Invoke-RestMethod -Uri $url -Method Post -Body $bytes `
                        -ContentType 'application/json' -Headers $headers `
                        -TimeoutSec $TimeoutSeconds -UseBasicParsing
        if ($null -ne $response -and $null -ne $response.data -and $null -ne $response.data.token) {
            return [string] $response.data.token
        }
        Write-Note 'ERROR: enrollment succeeded but returned no token'
        return ''
    } catch {
        $detail = $_.Exception.Message
        try {
            if ($null -ne $_.Exception.Response) {
                $stream = $_.Exception.Response.GetResponseStream()
                if ($null -ne $stream) {
                    $reader = New-Object System.IO.StreamReader($stream)
                    $text   = $reader.ReadToEnd()
                    $reader.Close()
                    if (-not [string]::IsNullOrWhiteSpace($text)) { $detail = $text }
                }
            }
        } catch { }
        Write-Note ('ERROR: enrollment failed: ' + $detail)
        return ''
    }
}

# ===========================================================================
# Main
# ===========================================================================

$identity  = Get-DeviceIdentity
$ingestUrl = Resolve-IngestUrl -Base $ApiUrl

# Two raw counter snapshots, SampleIntervalSeconds apart. Everything rate-shaped
# below is derived from the difference between them.
$snap1 = Get-PerfSnapshot
Start-Sleep -Seconds $SampleIntervalSeconds
$snap2 = Get-PerfSnapshot

# One UTC timestamp for the whole batch, truncated to the second. Second
# precision matters: device_metric_samples' primary key includes sampled_at, so
# a re-pushed batch with an identical stamp dedupes server-side instead of
# creating a near-duplicate point a fraction of a second away.
$script:SampleStamp = (Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ss') + 'Z'

Collect-Cpu           -Snap1 $snap1 -Snap2 $snap2
Collect-Memory
Collect-Volumes
Collect-PhysicalDisks -Snap1 $snap1 -Snap2 $snap2
Collect-Network       -Snap1 $snap1 -Snap2 $snap2
Collect-System
Collect-Battery
Collect-Gpu

if ($script:Samples.Count -eq 0) {
    Write-Note 'ERROR: no metrics could be collected on this device.'
    if ($NeverFail) { exit 0 }
    exit 1
}

$payload = [ordered]@{
    schema                = $script:SchemaTag
    collector_version     = $script:CollectorVersion
    device                = $identity
    collected_at          = $script:SampleStamp
    sample_window_seconds = $SampleIntervalSeconds
    samples               = @($script:Samples)
}

$summary = 'collected {0} samples over {1}s' -f $script:Samples.Count, $SampleIntervalSeconds
if ($script:Skipped.Count -gt 0) {
    $summary += '; omitted: ' + ($script:Skipped -join ', ')
}

if ($DryRun) {
    Write-Note $summary
    Write-Output ($payload | ConvertTo-Json -Depth 6)
    exit 0
}

# ------------------------------- credential --------------------------------

$token = $DeviceToken.Trim()
if ($token -eq '') { $token = Get-CachedToken }

if ($token -eq '' -and $EnrollmentToken.Trim() -ne '') {
    Write-Note 'No device token available; enrolling.'
    $token = Invoke-Enrollment -IngestUrl $ingestUrl -Token $EnrollmentToken.Trim() -Identity $identity
    if ($token -ne '') { Set-CachedToken -Token $token | Out-Null }
}

if ($token -eq '') {
    Write-Note 'ERROR: no device token. Pass -DeviceToken, or -EnrollmentToken to self-enroll.'
    if ($NeverFail) { exit 0 }
    exit 1
}

# --------------------------------- push -------------------------------------

$result = Invoke-IngestPost -Url $ingestUrl -Token $token -Payload $payload

# A 401 against a cached token means it was revoked or superseded (the device was
# re-enrolled elsewhere, or re-imaged). With an enrollment token available, mint
# a fresh one and retry exactly once - never in a loop, or a genuinely broken
# enrollment secret turns into a request storm against the server.
if (-not $result.Ok -and $result.Status -eq 401 -and $EnrollmentToken.Trim() -ne '') {
    Write-Note 'Device token rejected; re-enrolling once.'
    $token = Invoke-Enrollment -IngestUrl $ingestUrl -Token $EnrollmentToken.Trim() -Identity $identity
    if ($token -ne '') {
        Set-CachedToken -Token $token | Out-Null
        $result = Invoke-IngestPost -Url $ingestUrl -Token $token -Payload $payload
    }
}

if ($result.Ok) {
    $data = $result.Body.data
    if ($null -ne $data) {
        Write-Note ('OK: {0}; server accepted {1}, inserted {2}, duplicate {3}, rejected {4}.' -f `
            $summary, $data.accepted, $data.inserted, $data.duplicate, $data.rejected)
        if ([int] $data.rejected -gt 0) {
            Write-Note ('WARN: server rejected {0} sample(s): {1}' -f `
                $data.rejected, ($data.reject_reasons | ConvertTo-Json -Compress))
        }
    } else {
        Write-Note ('OK: ' + $summary)
    }
    exit 0
}

# Collection is switched off instance-wide (config_enable_device_metrics = 0).
# That is a deliberate administrative setting, not a device fault, so the check
# passes and says so rather than paging somebody about a toggle.
if ($result.Status -eq 503) {
    Write-Note ('SKIPPED: ' + $result.Message + ' (device metrics are disabled in ITFlow)')
    exit 0
}

Write-Note ('ERROR: push failed with HTTP {0}: {1}' -f $result.Status, $result.Message)
Write-Note $summary
if ($NeverFail) { exit 0 }
exit 1
