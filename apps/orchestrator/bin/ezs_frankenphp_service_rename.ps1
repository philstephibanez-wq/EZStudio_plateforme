# R1N4.1: stage a canonical WinSW service, never start/stop existing servers.
[CmdletBinding()]
param([Parameter(Mandatory=$true)][ValidateSet('Preflight','InstallDisabled','Status','RollbackStopped')][string]$Action)
$ErrorActionPreference = 'Stop'
$old = 'EZS_FrankenPHP_DEV'
$name = 'EZS_FrankenPHP'
$base = 'H:\FRANKENPHP\services'
$oldDir = Join-Path $base $old
$newDir = Join-Path $base $name
$oldExe = Join-Path $oldDir ($old+'.exe')
$oldXml = Join-Path $oldDir ($old+'.xml')
$newExe = Join-Path $newDir ($name+'.exe')
$newXml = Join-Path $newDir ($name+'.xml')
$stageXml = Join-Path $PSScriptRoot '..\config\frankenphp\EZS_FrankenPHP.xml'
$expectedHash = '05B82D46AD331CC16BDC00DE5C6332C1EF818DF8CEEFCD49C726553209B3A0DA'
function Record([string]$n) { @(Get-CimInstance Win32_Service -Filter "Name='$n'" -ErrorAction Stop) }
function Assert-Old {
    $r = @(Record $old)
    if ($r.Count -ne 1 -or $r[0].State -ne 'Stopped' -or $r[0].StartMode -ne 'Auto' -or
        [string]$r[0].PathName -notlike ('*'+$old+'.exe*')) { throw 'SOURCE_SERVICE_IDENTITY_OR_STATE_INVALID' }
    if (!(Test-Path -LiteralPath $oldExe -PathType Leaf) -or !(Test-Path -LiteralPath $oldXml -PathType Leaf)) {throw 'SOURCE_FILES_MISSING'}
    if ((Get-FileHash $oldExe -Algorithm SHA256).Hash -ine $expectedHash) {throw 'SOURCE_WINSW_HASH_MISMATCH'}
}
function Assert-NewXml([string]$file) {
    [xml]$x = Get-Content -LiteralPath $file -Raw -Encoding UTF8
    if ($x.service.id -ne $name -or $x.service.executable -ne 'H:\FRANKENPHP\bin\frankenphp.exe' -or
        $x.service.arguments -ne 'php-server --listen 127.0.0.1:8790 --root H:\EZS_orchestrator\public' -or
        $x.service.startmode -ne 'Manual') {throw 'NEW_SERVICE_XML_INVALID'}
}
if ($Action -eq 'Status') {
    foreach($n in @($old,$name)) { @(Record $n) | Select-Object Name,State,StartMode,StartName,PathName }
    exit 0
}
if ($Action -eq 'RollbackStopped') {
    $r = @(Record $name)
    if ($r.Count -ne 1 -or $r[0].State -ne 'Stopped' -or $r[0].StartMode -ne 'Disabled' -or
        [string]$r[0].PathName -notlike ('*'+$name+'.exe*')) {throw 'ROLLBACK_SERVICE_STATE_REFUSED'}
    Assert-Old
    if (!(Test-Path -LiteralPath $newExe -PathType Leaf) -or (Get-FileHash $newExe -Algorithm SHA256).Hash -ine $expectedHash) {throw 'ROLLBACK_BINARY_REFUSED'}
    & $newExe uninstall
    if ($LASTEXITCODE -ne 0 -or @(Record $name).Count -ne 0) {throw 'ROLLBACK_UNINSTALL_FAILED'}
    Write-Host 'EZS_R1N41_NEW_SERVICE_UNINSTALLED_OLD_UNCHANGED'
    exit 0
}
if (Get-ScheduledTask -TaskName 'EZS_ROOT_resident' -ErrorAction SilentlyContinue) {throw 'WATCHDOG_PRESENT'}
if (@(Record 'EZS_FrankenPHP_PROD').Count -ne 0) {throw 'SECOND_SERVICE_PRESENT'}
Assert-Old
if (@(Record $name).Count -ne 0) {throw 'CANONICAL_SERVICE_ALREADY_EXISTS'}
if (!(Test-Path -LiteralPath $stageXml -PathType Leaf)) {throw 'NEW_SERVICE_XML_MISSING'}
Assert-NewXml $stageXml
$ports = @(Get-NetTCPConnection -State Listen -ErrorAction Stop | Where-Object {$_.LocalPort -eq 8790})
if ($ports.Count -ne 0) {throw 'ROOT_LISTENER_PRESENT_REFUSED'}
$servers = @(Get-CimInstance Win32_Process -ErrorAction Stop | Where-Object { $_.Name -eq 'frankenphp.exe' -or $_.Name -eq 'EZS_FrankenPHP.exe' })
if ($servers.Count -ne 0) {throw 'FRANKENPHP_PROCESS_PRESENT_REFUSED'}
if ($Action -eq 'Preflight') { Write-Host 'EZS_R1N41_PREFLIGHT_OK_OLD_STOPPED'; exit 0 }
# This action does not stop, start, or reload the running FrankenPHP service.
if (!(Test-Path -LiteralPath $newDir -PathType Container)) {New-Item -ItemType Directory $newDir -ErrorAction Stop | Out-Null}
if ((Test-Path -LiteralPath $newExe) -or (Test-Path -LiteralPath $newXml)) {throw 'CANONICAL_STAGING_COLLISION'}
Copy-Item -LiteralPath $oldExe -Destination $newExe -ErrorAction Stop
if ((Get-FileHash $newExe -Algorithm SHA256).Hash -ine $expectedHash) {throw 'CANONICAL_WINSW_HASH_MISMATCH'}
Copy-Item -LiteralPath $stageXml -Destination $newXml -ErrorAction Stop
[void](New-Item -ItemType Directory -Force -Path (Join-Path $newDir 'logs'))
& $newExe install
if ($LASTEXITCODE -ne 0) {throw 'CANONICAL_INSTALL_FAILED'}
$record = @(Record $name)
if ($record.Count -ne 1 -or $record[0].State -ne 'Stopped' -or [string]$record[0].PathName -notlike ('*'+$name+'.exe*')) {throw 'NEW_SERVICE_IDENTITY_INVALID'}
Set-Service -Name $name -StartupType Disabled -ErrorAction Stop
$record = @(Record $name)
if ($record[0].State -ne 'Stopped' -or $record[0].StartMode -ne 'Disabled') {throw 'NEW_SERVICE_NOT_DISABLED'}
Assert-Old
Write-Host 'EZS_R1N41_CANONICAL_INSTALLED_DISABLED_OLD_STOPPED'
