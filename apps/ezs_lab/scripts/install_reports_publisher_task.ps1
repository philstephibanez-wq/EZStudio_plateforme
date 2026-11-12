# Install one recurring, non-overlapping MAIN report publication task.
# Run in Windows PowerShell under the MAIN user account authorized for GitHub.
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$publisher = Join-Path $root 'scripts\publish_run_reports.py'
if (-not (Test-Path -LiteralPath $publisher -PathType Leaf)) { throw 'publisher_missing' }
$python = (Get-Command python.exe -ErrorAction Stop).Source
$taskName = 'EZStudio_Run_Reports_Publisher'
$arg = '"' + $publisher + '" --producer all --limit 100'
$action = New-ScheduledTaskAction -Execute $python -Argument $arg -WorkingDirectory $root
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(2) -RepetitionInterval (New-TimeSpan -Minutes 5) -RepetitionDuration (New-TimeSpan -Days 3650)
$settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 4) -StartWhenAvailable
$principal = New-ScheduledTaskPrincipal -UserId ([System.Security.Principal.WindowsIdentity]::GetCurrent().Name) -LogonType Interactive -RunLevel Limited
Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Settings $settings -Principal $principal -Force | Out-Null
Write-Host "TASK_INSTALLED=$taskName"
Write-Host "PYTHON=$python"
Write-Host "ROOT=$root"
Write-Host 'INTERVAL_MINUTES=5'
Write-Host 'Use Get-ScheduledTaskInfo -TaskName EZStudio_Run_Reports_Publisher to inspect runs.'
