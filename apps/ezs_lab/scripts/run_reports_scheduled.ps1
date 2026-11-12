# Persistent unattended run-report publishing, MAIN only.
# Scheduled by Windows Task Scheduler under the GitHub-authenticated MAIN user.
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$stateDir = 'H:\EZStudio_Run_Reports_state'
New-Item -ItemType Directory -Force -Path $stateDir | Out-Null
$lock = Join-Path $stateDir 'publisher.lock'
$log = Join-Path $stateDir 'publisher.log'
$mutex = New-Object System.Threading.Mutex($false, 'Local\EZStudio_Run_Reports_MAIN')
$acquired = $false
try {
    $acquired = $mutex.WaitOne(0)
    if (-not $acquired) { return }
    $timestamp = Get-Date -Format 'yyyy-MM-ddTHH:mm:ssK'
    "[$timestamp] REPORT_EXPORT_BEGIN" | Out-File -FilePath $log -Append -Encoding utf8
    Push-Location $root
    try {
        $exitCode = 0
        foreach ($producer in @('assistant', 'lab')) {
            & python (Join-Path $root 'scripts\publish_run_reports.py') --producer $producer --limit 15 --state-dir $stateDir 2>&1 |
                Out-File -FilePath $log -Append -Encoding utf8
            if ($LASTEXITCODE -ne 0) { $exitCode = 1 }
        }
    } finally {
        Pop-Location
    }
    $timestamp = Get-Date -Format 'yyyy-MM-ddTHH:mm:ssK'
    "[$timestamp] REPORT_EXPORT_EXIT=$exitCode" | Out-File -FilePath $log -Append -Encoding utf8
    if ($exitCode -ne 0) { exit $exitCode }
} finally {
    if ($acquired) { $mutex.ReleaseMutex() }
    $mutex.Dispose()
}
