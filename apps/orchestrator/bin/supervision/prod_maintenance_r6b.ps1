[CmdletBinding()]
param(
    [Parameter(Mandatory=$true)][ValidateSet('Preflight','Status','Supervise','Activate','ReturnToNormal')][string]$Action,
    [switch]$Execute,
    [string]$ConfirmText=''
)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
$root='H:\EZS_orchestrator'
$site='H:\EZS_maintenance'
$php='H:\PHP\php-8.5-x64\php.exe'
$franken='H:\FRANKENPHP\bin\frankenphp.exe'
$config=Join-Path $root 'config\frankenphp\Caddyfile.prod.native'
$runtime=Join-Path $root 'runtime\supervision'
$nativeRecord=Join-Path $root 'runtime\native-sites\prod.json'
$modeFile=Join-Path $runtime 'prod-maintenance-mode-r6b.json'
$maintenanceRecord=Join-Path $runtime 'prod-maintenance-owner-r6b.json'
$logFile=Join-Path $runtime 'prod-maintenance-events-r6b.jsonl'
$router=Join-Path $root 'scripts\supervision\maintenance_router_r6b.php'
$ports=@(8501,8510,8511)
$utf8=New-Object System.Text.UTF8Encoding($false)
$mutated=$false

function Write-AtomicJson([string]$file,[hashtable]$data) {
    $tmp=$file+'.'+[Guid]::NewGuid().ToString('N')+'.tmp'
    try {
        [IO.File]::WriteAllText($tmp, (($data|ConvertTo-Json -Depth 8 -Compress)+"`n"),$utf8)
        if(Test-Path -LiteralPath $file) {
            [IO.File]::Replace($tmp,$file,$null)
        } else {
            [IO.File]::Move($tmp,$file)
        }
    } finally { if(Test-Path -LiteralPath $tmp){Remove-Item -LiteralPath $tmp -Force} }
}
function Read-Json([string]$file) {
    if(!(Test-Path -LiteralPath $file -PathType Leaf)){return $null}
    return (Get-Content -LiteralPath $file -Raw -Encoding UTF8|ConvertFrom-Json -ErrorAction Stop)
}
function Audit([string]$code,[object]$facts) {
    $line=([ordered]@{timestamp_utc=[DateTime]::UtcNow.ToString('o');action=$Action;code=$code;facts=$facts}|ConvertTo-Json -Depth 8 -Compress)+"`n"
    [IO.File]::AppendAllText($logFile,$line,$utf8)
}
function Set-Mode([string]$state,[string]$reason) {
    Write-AtomicJson $modeFile @{schema='ezs.prod-maintenance-mode.r6b';mode=$state;reason=$reason;timestamp_utc=[DateTime]::UtcNow.ToString('o')}
}
function Read-Mode {
    $m=Read-Json $modeFile
    if($null -eq $m){return 'NORMAL'}
    if($m.schema -ne 'ezs.prod-maintenance-mode.r6b' -or $m.mode -notin @('NORMAL','TRANSITION','MAINTENANCE','FAILED')){throw 'EZS_R6B_MODE_INVALID'}
    return [string]$m.mode
}
function List-Listeners {
    return @(Get-NetTCPConnection -State Listen -ErrorAction Stop | Where-Object {$_.LocalPort -in $ports})
}
function Assert-Binaries {
    foreach($p in @($php,$franken,$config,$router,(Join-Path $site 'public\index.php'),(Join-Path $site 'vendor\autoload.php'),(Join-Path $site 'templates\maintenance\index.html.twig'),(Join-Path $site 'public\assets\css\maintenance.css'),(Join-Path $site 'public\assets\vendor\jquery\jquery.min.js'),(Join-Path $root 'scripts\ezs_platform_control.ps1'))) {
        if(!(Test-Path -LiteralPath $p -PathType Leaf)){throw 'EZS_R6B_DEPENDENCY_MISSING'}
    }
    $c=& $php -l $router 2>&1
    if($LASTEXITCODE -ne 0){throw 'EZS_R6B_ROUTER_INVALID'}
    $c=& $php -l (Join-Path $site 'public\index.php') 2>&1
    if($LASTEXITCODE -ne 0){throw 'EZS_R6B_MAINTENANCE_ENTRY_INVALID'}
}
function Assert-ProdDesired {
    $intent=Read-Json (Join-Path $runtime 'desired-services.json')
    if($null -eq $intent -or $intent.schema -ne 'ezs.desired-services.v1' -or $intent.services.prod -ne 'RUNNING') {
        throw 'EZS_R6B_PROD_INTENT_NOT_RUNNING'
    }
}
function Test-ProdOwner {
    $listeners=@(List-Listeners)
    $record=Read-Json $nativeRecord
    $owners=@(Get-CimInstance Win32_Process -ErrorAction Stop|Where-Object {
        [string]$_.ExecutablePath -ieq $franken -and
        [string]$_.CommandLine -match '(?i)(?:^|\s)run(?:\s|$)' -and
        [string]$_.CommandLine.Replace('/','\').IndexOf($config.Replace('/','\'),[StringComparison]::OrdinalIgnoreCase) -ge 0
    })
    if($owners.Count -eq 0 -and $listeners.Count -eq 0 -and $null -eq $record){return @{state='ABSENT';pid=0;creation='';listeners=0}}
    if($owners.Count -ne 1 -or $null -eq $record){throw 'EZS_R6B_PROD_OWNER_AMBIGUOUS'}
    $owner=$owners[0]
    if($record.schema -ne 'ezs.native-site.owner.v1' -or $record.site -ne 'prod' -or
       [int]$record.pid -ne [int]$owner.ProcessId -or [string]$record.creation -ne [string]$owner.CreationDate) {
        throw 'EZS_R6B_PROD_OWNER_RECORD_MISMATCH'
    }
    if($listeners.Count -lt 3 -or @($listeners|Where-Object {$_.LocalPort -eq 8501}).Count -eq 0){throw 'EZS_R6B_PROD_PORTS_INCOMPLETE'}
    foreach($entry in $listeners){if([int]$entry.OwningProcess -ne [int]$owner.ProcessId){throw 'EZS_R6B_FOREIGN_LISTENER'}}
    foreach($port in $ports){if(@($listeners|Where-Object {$_.LocalPort -eq $port}).Count -eq 0){throw 'EZS_R6B_PROD_PORT_MISSING'}}
    return @{state='ATTESTED';pid=[int]$owner.ProcessId;creation=[string]$owner.CreationDate;listeners=$listeners.Count}
}
function Test-MaintenanceOwner {
    $record=Read-Json $maintenanceRecord
    if($null -eq $record){throw 'EZS_R6B_MAINTENANCE_OWNER_RECORD_MISSING'}
    if($record.schema -ne 'ezs.maintenance-owner.r6b'){throw 'EZS_R6B_MAINTENANCE_RECORD_INVALID'}
    $p=Get-CimInstance Win32_Process -Filter "ProcessId=$([int]$record.pid)" -ErrorAction Stop
    if($null -eq $p -or [string]$p.CreationDate -ne [string]$record.creation -or
       [string]$p.ExecutablePath -ine $php -or
       ([string]$p.CommandLine).IndexOf('127.0.0.1:8501',[StringComparison]::Ordinal) -lt 0 -or
       ([string]$p.CommandLine).IndexOf($router,[StringComparison]::OrdinalIgnoreCase) -lt 0){throw 'EZS_R6B_MAINTENANCE_IDENTITY_CHANGED'}
    $listeners=@(List-Listeners)
    if(@($listeners|Where-Object {$_.LocalPort -eq 8501 -and [int]$_.OwningProcess -eq [int]$record.pid}).Count -eq 0){throw 'EZS_R6B_MAINTENANCE_PORT_NOT_OWNED'}
    foreach($l in $listeners){if($l.LocalPort -eq 8501 -and [int]$l.OwningProcess -ne [int]$record.pid){throw 'EZS_R6B_FOREIGN_LISTENER'}}
    return $p
}
function Read-ProdFatalEvidence {
    # 404 is fatal only from the configured local endpoint. A native runtime
    # proven absent with all three ports free is independently fatal.
    $owner=Test-ProdOwner
    if($owner.state -eq 'ABSENT'){return @{fatal=$true;reason='NATIVE_PROD_ABSENT';owner=$owner}}
    $status=0
    try {
        $response=Invoke-WebRequest -Uri 'http://127.0.0.1:8511/api/status' -UseBasicParsing -TimeoutSec 4 -MaximumRedirection 0
        $status=[int]$response.StatusCode
    } catch [System.Net.WebException] {
        if($null -ne $_.Exception.Response){$status=[int]$_.Exception.Response.StatusCode}
    }
    if($status -eq 404){return @{fatal=$true;reason='MANDATORY_SOFT_HTTP_404';owner=$owner}}
    return @{fatal=$false;reason=('SOFT_HTTP_'+$status);owner=$owner}
}
function Probe([string]$path,[int]$expected) {
    $uri='http://127.0.0.1:8501'+$path
    try {
        $response=Invoke-WebRequest -Uri $uri -UseBasicParsing -TimeoutSec 4 -MaximumRedirection 0
        $status=[int]$response.StatusCode
    } catch [System.Net.WebException] {
        if($null -eq $_.Exception.Response){throw 'EZS_R6B_HTTP_UNREACHABLE'}
        $status=[int]$_.Exception.Response.StatusCode
    }
    if($status -ne $expected){throw ('EZS_R6B_HTTP_UNEXPECTED_'+$path.Replace('/','_')+'_'+$status)}
}
function Probe-Public([string]$path,[int]$expected) {
    $uri='https://ezscore.logandplay.com'+$path
    try {
        $resp=Invoke-WebRequest -Uri $uri -UseBasicParsing -TimeoutSec 8 -MaximumRedirection 0
        $http=[int]$resp.StatusCode
    } catch [System.Net.WebException] {
        if($null -eq $_.Exception.Response){throw 'EZS_R6B_PUBLIC_UNREACHABLE'}
        $http=[int]$_.Exception.Response.StatusCode
    }
    if($http -ne $expected){throw ('EZS_R6B_PUBLIC_HTTP_UNEXPECTED_'+$http)}
}
function Probe-Ready {
    Probe '/health' 200
    Probe '/api/status' 200
    Probe '/' 503
    $status=Invoke-WebRequest -Uri 'http://127.0.0.1:8501/assets/css/maintenance.css' -UseBasicParsing -TimeoutSec 4
    if([int]$status.StatusCode -ne 200){throw 'EZS_R6B_ASSET_UNAVAILABLE'}
    $json=Invoke-RestMethod -Uri 'http://127.0.0.1:8501/api/status' -TimeoutSec 4
    if($json.schema -ne 'ezs.service-status.v1' -or $json.service -ne 'EZS_maintenance' -or $json.state -ne 'OK' -or $null -eq $json.components){throw 'EZS_R6B_SOFT_SCHEMA_INVALID'}
}
if([Environment]::OSVersion.Platform -ne [PlatformID]::Win32NT) {throw 'EZS_R6B_WINDOWS_REQUIRED'}
if(!(Test-Path -LiteralPath $runtime)){
    if($Action -eq 'Status'){Write-Host 'EZS_R6B_MODE=UNKNOWN_RUNTIME_ABSENT';exit 0}
    New-Item -ItemType Directory -Path $runtime -Force|Out-Null
}
# Exclusive lock across all actions. Do not wait and do not mutate if busy.
$lockFile=Join-Path $runtime 'prod-maintenance-r6b.lock'
try {$lock=[IO.File]::Open($lockFile,[IO.FileMode]::OpenOrCreate,[IO.FileAccess]::ReadWrite,[IO.FileShare]::None)}
catch {throw 'EZS_R6B_LOCK_BUSY'}
try {
    if($Action -eq 'Status') {
        $mode=Read-Mode
        Write-Host "EZS_R6B_MODE=$mode"
        exit 0
    }
    Assert-Binaries
    if($Action -eq 'Preflight') {
        Assert-ProdDesired
        $mode=Read-Mode
        if($mode -ne 'NORMAL'){throw 'EZS_R6B_PREFLIGHT_MODE_NOT_NORMAL'}
        $owner=Test-ProdOwner
        if(Test-Path -LiteralPath $maintenanceRecord){throw 'EZS_R6B_MAINTENANCE_RECORD_ALREADY_PRESENT'}
        Write-Host ('EZS_R6B_PREFLIGHT_OK PROD='+$owner.state+' NO_MUTATION')
        exit 0
    }
    if($Action -eq 'Supervise') {
        $mode=Read-Mode
        if($mode -ne 'NORMAL'){Write-Host ('EZS_R6B_SUPERVISE_MODE_'+$mode);exit 0}
        Assert-ProdDesired
        $evidence=Read-ProdFatalEvidence
        if(!$evidence.fatal){Write-Host ('EZS_R6B_NO_FATAL '+$evidence.reason);exit 0}
        if(!$Execute){Write-Host ('EZS_R6B_FATAL_CANDIDATE '+$evidence.reason+' NO_MUTATION');exit 0}
        Audit 'AUTOMATED_FATAL_EVIDENCE' @{reason=$evidence.reason;owner=$evidence.owner}
    }
    if(!$Execute){throw 'EZS_R6B_EXECUTE_FLAG_REQUIRED'}
    if($Action -eq 'Activate' -and $ConfirmText -ne 'ACTIVATE_MAINTENANCE'){throw 'EZS_R6B_ACTIVATE_CONFIRMATION_REQUIRED'}
    if($Action -eq 'ReturnToNormal' -and $ConfirmText -ne 'RETURN_PROD_MANUAL'){throw 'EZS_R6B_RETURN_CONFIRMATION_REQUIRED'}
    if($Action -in @('Activate','Supervise')) {
        Assert-ProdDesired
        if((Read-Mode) -ne 'NORMAL'){throw 'EZS_R6B_ACTIVATE_MODE_NOT_NORMAL'}
        if(Test-Path -LiteralPath $maintenanceRecord){throw 'EZS_R6B_MAINTENANCE_RECORD_ALREADY_PRESENT'}
        $evidence=Read-ProdFatalEvidence
        if(!$evidence.fatal){throw 'EZS_R6B_FATAL_NOT_CONFIRMED'}
        $owner=$evidence.owner
        Audit 'PRE_MUTATION_EVIDENCE' @{reason=$evidence.reason;owner=$owner}
        Set-Mode 'TRANSITION' $evidence.reason
        $mutated=$true
        if($owner.state -eq 'ATTESTED') {
            # Independent immediate reattestation immediately before the only destructive action.
            $again=Test-ProdOwner
            if($again.state -ne 'ATTESTED' -or $again.pid -ne $owner.pid -or $again.creation -ne $owner.creation){throw 'EZS_R6B_PROD_IDENTITY_RECHECK_FAILED'}
            Stop-Process -Id $owner.pid -ErrorAction Stop
            $deadline=[DateTime]::UtcNow.AddSeconds(12)
            do {Start-Sleep -Milliseconds 300;$list=@(List-Listeners)} while($list.Count -gt 0 -and [DateTime]::UtcNow -lt $deadline)
            if($list.Count -gt 0){throw 'EZS_R6B_PROD_PORT_RELEASE_TIMEOUT'}
            if(Test-Path -LiteralPath $nativeRecord){Remove-Item -LiteralPath $nativeRecord -Force -ErrorAction Stop}
            Audit 'PROD_STOP_ATTESTED' @{pid=$owner.pid}
        }
        if(@(List-Listeners).Count -gt 0){throw 'EZS_R6B_PORTS_OCCUPIED_AFTER_STOP'}
        $stdout=Join-Path $runtime 'maintenance-php-stdout-r6b.log'
        $stderr=Join-Path $runtime 'maintenance-php-stderr-r6b.log'
        $oldEnv=[Environment]::GetEnvironmentVariable('APP_ENV','Process')
        $oldDebug=[Environment]::GetEnvironmentVariable('APP_DEBUG','Process')
        try {
            [Environment]::SetEnvironmentVariable('APP_ENV','prod','Process')
            [Environment]::SetEnvironmentVariable('APP_DEBUG','0','Process')
            $args=@('-S','127.0.0.1:8501','-t',('"'+(Join-Path $site 'public')+'"'),('"'+$router+'"'))
            $proc=Start-Process -FilePath $php -WorkingDirectory $site -ArgumentList $args -PassThru -WindowStyle Hidden -RedirectStandardOutput $stdout -RedirectStandardError $stderr -ErrorAction Stop
        } finally {
            [Environment]::SetEnvironmentVariable('APP_ENV',$oldEnv,'Process')
            [Environment]::SetEnvironmentVariable('APP_DEBUG',$oldDebug,'Process')
        }
        $info=Get-CimInstance Win32_Process -Filter "ProcessId=$($proc.Id)" -ErrorAction Stop
        if($null -eq $info){throw 'EZS_R6B_MAINTENANCE_PROCESS_NOT_FOUND'}
        Write-AtomicJson $maintenanceRecord @{schema='ezs.maintenance-owner.r6b';pid=[int]$proc.Id;creation=[string]$info.CreationDate;router=$router;created_utc=[DateTime]::UtcNow.ToString('o')}
        Start-Sleep -Milliseconds 700
        $null=Test-MaintenanceOwner
        Probe-Ready
        Audit 'MAINTENANCE_LOCAL_READY' @{pid=[int]$proc.Id;port=8501;public_check='PENDING'}
        # Public checks are independent from loopback checks; do not report MAINTENANCE prematurely.
        foreach($path in @('/health','/api/status','/')) {
            $expected=if($path -eq '/'){503}else{200}
            Probe-Public $path $expected
        }
        Set-Mode 'MAINTENANCE' 'PUBLIC_AND_LOCAL_ATTESTED'
        Audit 'MAINTENANCE_PUBLIC_READY' @{pid=[int]$proc.Id;port=8501}
        Write-Host 'EZS_R6B_MAINTENANCE_PUBLIC_ATTESTED'
        exit 0
    }
    if($Action -eq 'ReturnToNormal') {
        if((Read-Mode) -ne 'MAINTENANCE'){throw 'EZS_R6B_RETURN_MODE_NOT_MAINTENANCE'}
        Assert-ProdDesired
        $p=Test-MaintenanceOwner
        Audit 'RETURN_MANUAL_REQUESTED' @{pid=[int]$p.ProcessId}
        Set-Mode 'TRANSITION' 'MANUAL_RETURN_TO_NORMAL'
        $mutated=$true
        $again=Test-MaintenanceOwner
        if([int]$again.ProcessId -ne [int]$p.ProcessId -or [string]$again.CreationDate -ne [string]$p.CreationDate){throw 'EZS_R6B_MAINTENANCE_RECHECK_FAILED'}
        Stop-Process -Id ([int]$p.ProcessId) -ErrorAction Stop
        $deadline=[DateTime]::UtcNow.AddSeconds(12)
        do {Start-Sleep -Milliseconds 300;$list=@(List-Listeners)} while($list.Count -gt 0 -and [DateTime]::UtcNow -lt $deadline)
        if($list.Count -ne 0){throw 'EZS_R6B_RETURN_PORT_NOT_FREE'}
        Remove-Item -LiteralPath $maintenanceRecord -Force -ErrorAction Stop
        & powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -File (Join-Path $root 'scripts\ezs_platform_control.ps1') -Site prod -Action Preflight
        if($LASTEXITCODE -ne 0){throw 'EZS_R6B_PROD_PREFLIGHT_FAILED'}
        & powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -File (Join-Path $root 'scripts\ezs_platform_control.ps1') -Site prod -Action Start
        if($LASTEXITCODE -ne 0){throw 'EZS_R6B_PROD_START_FAILED'}
        $owner=Test-ProdOwner
        if($owner.state -ne 'ATTESTED'){throw 'EZS_R6B_PROD_RETURN_NOT_ATTESTED'}
        # Do not mark NORMAL unless the local facade and public Cloudflare path respond.
        $localCode=0;$publicCode=0
        try {$r=Invoke-WebRequest -Uri 'http://127.0.0.1:8501/' -UseBasicParsing -TimeoutSec 5 -MaximumRedirection 0;$localCode=[int]$r.StatusCode}
        catch [System.Net.WebException] {if($null -ne $_.Exception.Response){$localCode=[int]$_.Exception.Response.StatusCode}}
        try {$r=Invoke-WebRequest -Uri 'https://ezscore.logandplay.com/' -UseBasicParsing -TimeoutSec 8 -MaximumRedirection 0;$publicCode=[int]$r.StatusCode}
        catch [System.Net.WebException] {if($null -ne $_.Exception.Response){$publicCode=[int]$_.Exception.Response.StatusCode}}
        if($localCode -eq 0 -or $publicCode -eq 0 -or $localCode -eq 503 -or $publicCode -eq 503){throw 'EZS_R6B_NORMAL_HTTP_UNVERIFIED'}
        Set-Mode 'NORMAL' 'MANUAL_RETURN_ATTESTED'
        Audit 'NORMAL_PUBLIC_ATTESTED' @{owner=$owner;local_http=$localCode;public_http=$publicCode}
        Write-Host 'EZS_R6B_NORMAL_ATTESTED'
        exit 0
    }
} catch {
    # Preserve all original logs and ownership data for operator recovery.
    if($mutated) {
        try {Set-Mode 'FAILED' 'OPERATION_FAILED';Audit 'OPERATION_FAILED' @{type=$_.Exception.GetType().Name}} catch {}
    }
    throw
} finally {
    if($null -ne $lock){$lock.Dispose()}
}
