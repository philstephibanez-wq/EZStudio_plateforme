# Background watcher on MAIN, under the user's authenticated GitHub session.
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$stateDir = 'H:\EZStudio_Run_Reports_state'
New-Item -ItemType Directory -Force -Path $stateDir | Out-Null
$log = Join-Path $stateDir 'watcher.log'
$mutex = New-Object System.Threading.Mutex($false, 'Local\EZStudio_Run_Reports_Watcher_MAIN')
$acquired = $false
try {
    $acquired = $mutex.WaitOne(0)
    if (-not $acquired) { return }
    "[$(Get-Date -Format o)] WATCHER_START" | Out-File -FilePath $log -Append -Encoding utf8
    Push-Location $root
    try {
        & python -u (Join-Path $root 'scripts\watch_run_reports.py') --state-dir $stateDir 2>&1 |
            Out-File -FilePath $log -Append -Encoding utf8
        $code = $LASTEXITCODE
    } finally { Pop-Location }
    "[$(Get-Date -Format o)] WATCHER_EXIT=$code" | Out-File -FilePath $log -Append -Encoding utf8
    if ($code -ne 0) { exit $code }
} finally {
    if ($acquired) { $mutex.ReleaseMutex() }
    $mutex.Dispose()
}
