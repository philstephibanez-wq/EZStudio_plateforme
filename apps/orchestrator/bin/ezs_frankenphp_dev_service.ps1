# EZS FrankenPHP DEV - service provisioning only. No implicit startup/stop.
[CmdletBinding()]
param([Parameter(Mandatory=$true)][ValidateSet('Preflight','InstallDisabled','Status','UninstallStopped')][string]$Action)
$ErrorActionPreference = 'Stop'
$serviceName = 'EZS_FrankenPHP_DEV'
$directory = 'H:\FRANKENPHP\services\EZS_FrankenPHP_DEV'
$wrapper = Join-Path $directory ($serviceName + '.exe')
$xmlPath = Join-Path $directory ($serviceName + '.xml')
$php = 'H:\FRANKENPHP\bin\frankenphp.exe'
$root = 'H:\EZS_orchestrator'
function Get-ServiceRecord { @(Get-CimInstance Win32_Service -Filter "Name='$serviceName'" -ErrorAction Stop) }
function Assert-Safe {
    if (!(Test-Path -LiteralPath $php -PathType Leaf)) { throw 'FRANKENPHP_BINARY_MISSING' }
    if (!(Test-Path -LiteralPath (Join-Path $root 'public\index.php') -PathType Leaf)) { throw 'ROOT_ENTRY_MISSING' }
    if (Get-ScheduledTask -TaskName 'EZS_ROOT_resident' -ErrorAction SilentlyContinue) { throw 'ROOT_WATCHDOG_PRESENT' }
    if (Get-ScheduledTask -TaskName 'EZS_orchestrator_supervisor' -ErrorAction SilentlyContinue) { throw 'LEGACY_SUPERVISOR_PRESENT' }
    if (!(Test-Path -LiteralPath $wrapper -PathType Leaf)) { throw 'WINSW_X64_MISSING' }
    if (!(Test-Path -LiteralPath $xmlPath -PathType Leaf)) { throw 'SERVICE_XML_MISSING' }
    [xml]$config = Get-Content -LiteralPath $xmlPath -Raw -Encoding UTF8
    if ($config.service.id -ne $serviceName -or $config.service.executable -ne $php -or
        $config.service.arguments -ne 'php-server --listen 127.0.0.1:8790 --root H:\EZS_orchestrator\public' -or
        $config.service.startmode -ne 'Manual') { throw 'SERVICE_XML_CONTRACT_MISMATCH' }
    $sha = (Get-FileHash -LiteralPath $wrapper -Algorithm SHA256).Hash
    Write-Host "WINSW_SHA256=$sha"
}
if ($Action -eq 'Status') {
    $record = @(Get-ServiceRecord)
    if ($record.Count -eq 0) { Write-Host 'EZS_DEV_SERVICE_ABSENT'; exit 0 }
    if ($record.Count -ne 1) { throw 'SERVICE_AMBIGUOUS' }
    $record[0] | Select-Object Name,State,StartMode,StartName,PathName
    exit 0
}
Assert-Safe
$records = @(Get-ServiceRecord)
if ($Action -eq 'Preflight') {
    if ($records.Count -ne 0) { throw 'SERVICE_ALREADY_EXISTS' }
    Write-Host 'EZS_DEV_SERVICE_PREFLIGHT_OK_NO_CHANGES'; exit 0
}
if ($Action -eq 'InstallDisabled') {
    if ($records.Count -ne 0) { throw 'SERVICE_ALREADY_EXISTS' }
    & $wrapper install
    if ($LASTEXITCODE -ne 0) { throw 'WINSW_INSTALL_FAILED' }
    $records = @(Get-ServiceRecord)
    if ($records.Count -ne 1) { throw 'SERVICE_INSTALL_NOT_FOUND' }
    if ($records[0].State -ne 'Stopped') { throw 'SERVICE_RUNNING_UNEXPECTEDLY' }
    if ([string]$records[0].PathName -notlike ('*' + $serviceName + '.exe*')) { throw 'SERVICE_PATH_UNEXPECTED' }
    Set-Service -Name $serviceName -StartupType Disabled -ErrorAction Stop
    Write-Host 'EZS_DEV_SERVICE_INSTALLED_DISABLED'; exit 0
}
if ($Action -eq 'UninstallStopped') {
    if ($records.Count -ne 1 -or $records[0].State -ne 'Stopped' -or
        [string]$records[0].PathName -notlike ('*' + $serviceName + '.exe*')) { throw 'SERVICE_OWNERSHIP_OR_STATE_REFUSED' }
    & $wrapper uninstall
    if ($LASTEXITCODE -ne 0) { throw 'WINSW_UNINSTALL_FAILED' }
    if (@(Get-ServiceRecord).Count -ne 0) { throw 'SERVICE_STILL_PRESENT' }
    Write-Host 'EZS_DEV_SERVICE_UNINSTALLED'; exit 0
}
