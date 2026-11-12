# Explicit, reversible task cutover. No action on PROD or remote tasks.
[CmdletBinding()]
param([Parameter(Mandatory=$true)][ValidateSet('Preflight','Activate','Status')][string]$Action,
      [string]$BackupDirectory = 'H:\temp\EZS_WATCHDOG_RESIDENT_R1G_TASK_BACKUP')
$ErrorActionPreference = 'Stop'
$root = 'H:\EZS_orchestrator'
$name = 'EZS_orchestrator_supervisor'
$task = Get-ScheduledTask -TaskName $name -ErrorAction Stop
if ($task.Actions.Count -ne 1) { throw 'EZS_RESIDENT_TASK_ACTION_COUNT' }
$original = [string]$task.Actions[0].Arguments
$newVbs = Join-Path $root 'scripts\ezs_supervisor_resident_hidden.vbs'
$oldVbs = Join-Path $root 'scripts\ezs_supervisor_hidden.vbs'
if ($Action -eq 'Status') { $task | Select-Object TaskName,State; Get-ScheduledTaskInfo -TaskName $name; exit 0 }
if ((Get-Content (Join-Path $root 'VERSION') -Raw).Trim() -ne '4.2d33') { throw 'EZS_RESIDENT_VERSION' }
if (-not (Test-Path $newVbs)) { throw 'EZS_RESIDENT_VBS_MISSING' }
if ($Action -eq 'Preflight') {
    if ($original -notlike '*ezs_supervisor_hidden.vbs*') { throw 'EZS_RESIDENT_TASK_NOT_BASELINE' }
    Write-Host 'EZS_RESIDENT_TASK_PREFLIGHT_OK'; exit 0
}
if ($original -notlike '*ezs_supervisor_hidden.vbs*') { throw 'EZS_RESIDENT_TASK_NOT_BASELINE' }
if (Test-Path $BackupDirectory) { throw 'EZS_RESIDENT_BACKUP_ALREADY_EXISTS' }
New-Item -ItemType Directory -Path $BackupDirectory -Force | Out-Null
Export-ScheduledTask -TaskName $name | Set-Content -LiteralPath (Join-Path $BackupDirectory 'task-before-r1g.xml') -Encoding UTF8
# Do not modify/stop the old running process; next trigger transitions to resident.
$action = New-ScheduledTaskAction -Execute (Join-Path $env:WINDIR 'System32\wscript.exe') -Argument ('//B //Nologo "' + $newVbs + '"') -WorkingDirectory $root
# Zero time limit allows residence. Existing per-minute trigger acts as crash retry.
$settings = $task.Settings
$settings.ExecutionTimeLimit = 'PT0S'
$settings.MultipleInstances = 'IgnoreNew'
try {
    Set-ScheduledTask -TaskName $name -Action $action -Settings $settings | Out-Null
} catch {
    Write-Host 'EZS_RESIDENT_TASK_UPDATE_FAILED_RESTORE_XML_FROM_BACKUP'
    throw
}
Write-Host 'EZS_RESIDENT_TASK_ACTIVATED_CHECK_PID_AND_TASK_STATE'
