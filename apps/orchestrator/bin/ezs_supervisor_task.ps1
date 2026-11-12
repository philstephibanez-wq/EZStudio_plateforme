# Explicit opt-in Windows task management. No automatic service start at installation.
[CmdletBinding()]
param(
    [Parameter(Mandatory=$true)]
    [ValidateSet('Status','Install','Update','Remove')]
    [string] $Action
)
$ErrorActionPreference = 'Stop'
$taskName = 'EZS_orchestrator_supervisor'
$root = 'H:\EZS_orchestrator'
$runner = Join-Path $root 'scripts\ezs_supervisor_tick.ps1'
$hidden = Join-Path $root 'scripts\ezs_supervisor_hidden.vbs'
$task = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
if ($Action -eq 'Status') {
    if ($null -eq $task) { Write-Host 'EZS_SUPERVISOR_TASK_ABSENT' }
    else { Get-ScheduledTaskInfo -TaskName $taskName | Select-Object LastRunTime,LastTaskResult,NextRunTime | Format-List }
    exit 0
}
if ($Action -eq 'Update') {
    if ($null -eq $task) { throw 'EZS_SUPERVISOR_TASK_NOT_FOUND' }
    if ($task.Actions.Count -ne 1 -or
        ($task.Actions[0].Arguments -notlike '*ezs_supervisor_tick.ps1*' -and
         $task.Actions[0].Arguments -notlike '*ezs_supervisor_hidden.vbs*')) {
        throw 'EZS_SUPERVISOR_TASK_FOREIGN_REFUSED'
    }
    if (!(Test-Path -LiteralPath $hidden -PathType Leaf)) { throw 'EZS_SUPERVISOR_HIDDEN_MISSING' }
    if ((Get-Content -LiteralPath (Join-Path $root 'VERSION') -Raw).Trim() -ne '4.2c64') { throw 'EZS_SUPERVISOR_VERSION_MISMATCH' }
    $newAction = New-ScheduledTaskAction -Execute "$env:WINDIR\System32\wscript.exe" -Argument ('//B //Nologo "' + $hidden + '"') -WorkingDirectory $root
    Set-ScheduledTask -TaskName $taskName -Action $newAction | Out-Null
    Write-Host 'EZS_SUPERVISOR_TASK_UPDATED_HIDDEN'
    exit 0
}
if ($Action -eq 'Remove') {
    if ($null -eq $task) { throw 'EZS_SUPERVISOR_TASK_NOT_FOUND' }
    if ($task.Actions.Count -ne 1 -or ($task.Actions[0].Arguments -notlike '*ezs_supervisor_tick.ps1*' -and $task.Actions[0].Arguments -notlike '*ezs_supervisor_hidden.vbs*')) {
        throw 'EZS_SUPERVISOR_TASK_FOREIGN_REFUSED'
    }
    Unregister-ScheduledTask -TaskName $taskName -Confirm:$false
    Write-Host 'EZS_SUPERVISOR_TASK_REMOVED'
    exit 0
}
if ($null -ne $task) { throw 'EZS_SUPERVISOR_TASK_ALREADY_EXISTS' }
if (!(Test-Path -LiteralPath $runner -PathType Leaf)) { throw 'EZS_SUPERVISOR_RUNNER_MISSING' }
if (!(Test-Path -LiteralPath $hidden -PathType Leaf)) { throw 'EZS_SUPERVISOR_HIDDEN_MISSING' }
if (!(Test-Path -LiteralPath (Join-Path $root 'bin\console') -PathType Leaf)) { throw 'EZS_SUPERVISOR_CONSOLE_MISSING' }
if ((Get-Content -LiteralPath (Join-Path $root 'VERSION') -Raw).Trim() -ne '4.2c64') { throw 'EZS_SUPERVISOR_VERSION_MISMATCH' }
# InteractiveToken is intentional: do not change the Windows identity running PHP/WMI or SSO.
# Runs once per minute while the owning user is logged on; after reboot it resumes at logon.
$identity = [Security.Principal.WindowsIdentity]::GetCurrent().Name
$principal = New-ScheduledTaskPrincipal -UserId $identity -LogonType Interactive -RunLevel Limited
$execute = Join-Path $env:WINDIR 'System32\wscript.exe'
$arguments = '//B //Nologo "' + $hidden + '"'
$taskAction = New-ScheduledTaskAction -Execute $execute -Argument $arguments -WorkingDirectory $root
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 1) -RepetitionDuration (New-TimeSpan -Days 3650)
$atLogon = New-ScheduledTaskTrigger -AtLogOn -User $identity
$settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 10) -StartWhenAvailable
Register-ScheduledTask -TaskName $taskName -Action $taskAction -Trigger @($trigger,$atLogon) -Settings $settings -Principal $principal -Description 'EZS supervision tick; desired states control automatic recovery' | Out-Null
Write-Host 'EZS_SUPERVISOR_TASK_INSTALLED'
Write-Host 'EZS_SUPERVISOR_TASK_LOGON_REQUIRED'
