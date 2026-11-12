# R9: ROOT resident supervises ROOT only. Site hosting belongs to EZS_FrankenPHP.
[CmdletBinding()]
param([ValidateRange(15,300)][int]$IntervalSeconds=60)
$ErrorActionPreference='Stop'
$root='H:\EZS_orchestrator'
$watchdog=Join-Path $root 'scripts\ezs_root_frankenphp_watchdog.ps1'
$logDir=Join-Path $root 'var\log\supervision'
New-Item -ItemType Directory -Force -Path $logDir | Out-Null
$mutex=New-Object Threading.Mutex($false,'Local\EZS_ROOT_RESIDENT_SINGLETON')
$locked=$false
try {
    try {$locked=$mutex.WaitOne(0)} catch [Threading.AbandonedMutexException] {$locked=$true}
    if (!$locked) {throw 'EZS_ROOT_RESIDENT_ALREADY_RUNNING'}
    if (!(Test-Path -LiteralPath $watchdog -PathType Leaf)) {throw 'ROOT_FRANKENPHP_WATCHDOG_MISSING'}
    while ($true) {
        $start=[DateTime]::UtcNow
        try {
            & $watchdog -Once
            if (!$?) {throw 'ROOT_FRANKENPHP_WATCHDOG_FAILED'}
        } catch {
            $utc=[DateTime]::UtcNow.ToString('o')
            Add-Content -LiteralPath (Join-Path $logDir 'watchdog-resident.log') -Value "$utc ROOT_RESIDENT_TICK_ERROR $($_.Exception.Message)" -Encoding UTF8
        }
        $elapsed=[int]([DateTime]::UtcNow-$start).TotalSeconds
        Start-Sleep -Seconds ([Math]::Max(1,$IntervalSeconds-$elapsed))
    }
} finally {
    if ($locked) {$mutex.ReleaseMutex()}
    $mutex.Dispose()
}
