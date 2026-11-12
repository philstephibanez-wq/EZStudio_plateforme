[CmdletBinding()]
param([Parameter(Mandatory=$true)][ValidateSet('Preflight','InstallDisabled','Enable','Status','Disable','Remove')][string]$Action)
$ErrorActionPreference='Stop'
$root='H:\EZS_orchestrator'
$name='EZS_ROOT_resident'
$legacy='EZS_orchestrator_supervisor'
$hidden=Join-Path $root 'scripts\ezs_supervisor_resident_hidden.vbs'
$resident=Join-Path $root 'scripts\ezs_supervisor_resident.ps1'
$watchdog=Join-Path $root 'scripts\ezs_root_frankenphp_watchdog.ps1'
$task=Get-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue
function Assert-TaskIdentity($item) {
    if ($null -eq $item -or $item.Actions.Count -ne 1) {throw 'EZS_ROOT_TASK_IDENTITY_UNKNOWN'}
    $a=$item.Actions[0]
    if ($a.Execute -notmatch '(?i)(?:^|\\)wscript\.exe$' -or
        $a.Arguments -notlike '*ezs_supervisor_resident_hidden.vbs*') {throw 'EZS_ROOT_TASK_FOREIGN_REFUSED'}
}
if ($Action -eq 'Status') {
    if ($null -eq $task) {Write-Host 'EZS_ROOT_TASK_ABSENT';exit 0}
    Assert-TaskIdentity $task
    $task | Select-Object TaskName,State
    Get-ScheduledTaskInfo -TaskName $name | Select-Object LastRunTime,LastTaskResult,NextRunTime
    exit 0
}
if ((Get-Content (Join-Path $root 'VERSION') -Raw).Trim() -ne '4.2d38') {throw 'EZS_ROOT_TASK_VERSION_MISMATCH'}
foreach($path in @($hidden,$resident,$watchdog)) {
    if (!(Test-Path -LiteralPath $path -PathType Leaf)) {throw 'EZS_ROOT_TASK_SOURCES_MISSING'}
}
if ($null -ne (Get-ScheduledTask -TaskName $legacy -ErrorAction SilentlyContinue)) {throw 'EZS_LEGACY_TASK_PRESENT_REFUSED'}
if ($Action -eq 'Preflight') {
    Assert-TaskIdentity $task
    if ($task.State -ne 'Disabled') {throw 'EZS_ROOT_TASK_MUST_REMAIN_DISABLED_PRE_ACTIVATION'}
    Write-Host 'EZS_ROOT_RESIDENT_PREFLIGHT_OK';exit 0
}
if ($Action -eq 'InstallDisabled') {
    if ($null -ne $task) {throw 'EZS_ROOT_TASK_ALREADY_EXISTS'}
    $identity=[Security.Principal.WindowsIdentity]::GetCurrent().Name
    $principal=New-ScheduledTaskPrincipal -UserId $identity -LogonType Interactive -RunLevel Limited
    $taskExecAction=New-ScheduledTaskAction -Execute (Join-Path $env:WINDIR 'System32\wscript.exe') -Argument ('//B //Nologo "'+$hidden+'"') -WorkingDirectory $root
    $trigger=New-ScheduledTaskTrigger -AtLogOn -User $identity
    $settings=New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -ExecutionTimeLimit ([TimeSpan]::Zero) -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1) -Disable
    Register-ScheduledTask -TaskName $name -Action $taskExecAction -Trigger $trigger -Settings $settings -Principal $principal -Description 'EZS ROOT 8790 FrankenPHP only' -ErrorAction Stop | Out-Null
    Write-Host 'EZS_ROOT_RESIDENT_INSTALLED_DISABLED';exit 0
}
Assert-TaskIdentity $task
if ($Action -eq 'Enable') {
    if ($task.State -ne 'Disabled') {throw 'EZS_ROOT_TASK_NOT_DISABLED'}
    $state=Get-Content (Join-Path $root 'runtime\supervision\desired-services.json') -Raw | ConvertFrom-Json
    if ($state.schema -ne 'ezs.desired-services.v1' -or $state.services.root -ne 'RUNNING') {throw 'EZS_ROOT_INTENTION_NOT_RUNNING'}
    Enable-ScheduledTask -TaskName $name | Out-Null
    Start-ScheduledTask -TaskName $name
    Write-Host 'EZS_ROOT_RESIDENT_ENABLED';exit 0
}
if ($Action -eq 'Disable') {
    Disable-ScheduledTask -TaskName $name | Out-Null
    if ((Get-ScheduledTask -TaskName $name).State -eq 'Running') {Stop-ScheduledTask -TaskName $name}
    Write-Host 'EZS_ROOT_RESIDENT_DISABLED';exit 0
}
if ($Action -eq 'Remove') {
    if ($task.State -ne 'Disabled') {throw 'EZS_ROOT_TASK_DISABLE_FIRST'}
    Unregister-ScheduledTask -TaskName $name -Confirm:$false
    Write-Host 'EZS_ROOT_RESIDENT_REMOVED';exit 0
}
