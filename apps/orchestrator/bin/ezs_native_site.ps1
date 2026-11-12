[CmdletBinding()]
param(
    [Parameter(Mandatory=$true)][ValidateSet('Start','Stop','Status','Preflight')][string]$Action,
    [Parameter(Mandatory=$true)][ValidateSet('prod','dev','lab')][string]$Site
)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
$root='H:\EZS_orchestrator'
$exe='H:\FRANKENPHP\bin\frankenphp.exe'
$config=Join-Path $root ('config\frankenphp\Caddyfile.'+$Site+'.native')
$ports=if($Site -eq 'prod'){@(8501,8510,8511)}elseif($Site -eq 'dev'){@(8502,8602)}else{@(8702,8701)}
$stateDir=Join-Path $root 'runtime\native-sites'
$recordFile=Join-Path $stateDir ($Site+'.json')
$utf8=New-Object Text.UTF8Encoding($false)
function Owned {
    @(Get-CimInstance Win32_Process -ErrorAction Stop|Where-Object {
        [string]$_.ExecutablePath -ieq $exe -and
        [string]$_.CommandLine -match '(?i)(?:^|\s)run(?:\s|$)' -and
        [string]$_.CommandLine.Replace('/','\').IndexOf($config.Replace('/','\'),[StringComparison]::OrdinalIgnoreCase) -ge 0
    })
}
function Listeners {
    @(Get-NetTCPConnection -State Listen -ErrorAction Stop|Where-Object {$_.LocalPort -in $ports})
}
function Record {
    if(!(Test-Path -LiteralPath $recordFile -PathType Leaf)){return $null}
    $s=Get-Content -LiteralPath $recordFile -Raw -Encoding UTF8 -ErrorAction Stop|ConvertFrom-Json -ErrorAction Stop
    if($s.schema -ne 'ezs.native-site.owner.v1' -or $s.site -ne $Site){throw 'OWNER_RECORD_INVALID'}
    return $s
}
function Assert-Owner($p,$record) {
    if($null -eq $record -or [int]$record.pid -ne [int]$p.ProcessId -or
        [string]$record.creation -ne [string]$p.CreationDate -or
        [string]$record.config -ine $config){throw 'PROCESS_IDENTITY_CHANGED'}
}
function Assert-Entries {
    if(!(Test-Path -LiteralPath $exe -PathType Leaf)){throw 'FRANKENPHP_EXE_MISSING'}
    if(!(Test-Path -LiteralPath $config -PathType Leaf)){throw 'NATIVE_CONFIG_MISSING'}
    $caddyText=Get-Content -LiteralPath $config -Raw -Encoding UTF8 -ErrorAction Stop
    if($caddyText -match '(?i)\bphp_fastcgi\b|\bphp-cgi\b'){throw 'NATIVE_CONFIG_LEGACY_FASTCGI_REFUSED'}
    $entries=if($Site -eq 'prod'){@('H:\EZScore\public\index.php','H:\EZS_orchestrator\runtime_components\prod_gateway_php\router.php')}elseif($Site -eq 'dev'){@('H:\EZScore_dev\public\index.php')}else{@('H:\EZStudio_lab\public\index.php')}
    foreach($f in $entries){if(!(Test-Path -LiteralPath $f -PathType Leaf)){throw ('PHP_ENTRY_MISSING '+$f)}}
}
function Assert-No-Foreign($owned,$listeners) {
    if($owned.Count -gt 1){throw 'MULTIPLE_OWNED'}
    if($listeners.Count -gt 0 -and $owned.Count -eq 0){throw 'FOREIGN_LISTENER'}
    if($owned.Count -eq 1){
        foreach($listener in $listeners){
            if([int]$listener.OwningProcess -ne [int]$owned[0].ProcessId){throw 'FOREIGN_LISTENER'}
        }
    }
}
$owned=@(Owned);$listeners=@(Listeners)
# The INTENT field is retained for read compatibility with older API clients.
# It represents the current command scope, never a persisted desired state.
$intent='RUNNING'
if($Action -eq 'Status'){
    $record=Record
    $actual=$owned.Count
    $uniquePorts=@($listeners|Select-Object -ExpandProperty LocalPort -Unique)
    $identityOk=$false
    $recordOk=$false
    $reason='NATIVE_ABSENT'
    if($actual -eq 1){
        if($null -eq $record){$reason='OWNER_RECORD_MISSING'}
        else {try {Assert-Owner $owned[0] $record;$recordOk=$true}catch{$reason='OWNER_RECORD_MISMATCH'}}
        $identityOk=$true
        foreach($listener in $listeners){
            if([int]$listener.OwningProcess -ne [int]$owned[0].ProcessId){$identityOk=$false}
        }
        if(!$identityOk){$reason='FOREIGN_LISTENER'}
        elseif($recordOk -and $uniquePorts.Count -eq $ports.Count){
            $complete=$true
            foreach($port in $ports){if($port -notin $uniquePorts){$complete=$false}}
            if($complete){$reason='HARD_ATTESTED'}else{$reason='PORTS_INCOMPLETE'}
        }elseif($recordOk){$reason='PORTS_INCOMPLETE'}
    }elseif($actual -gt 1){$reason='MULTIPLE_MATCHING_PROCESSES'}
    elseif($listeners.Count -gt 0){$reason='LISTENER_WITHOUT_MATCHING_PROCESS'}
    elseif($null -ne $record){$reason='STALE_OWNER_RECORD'}
    $hard=if($reason -eq 'HARD_ATTESTED'){'RUNNING'}elseif($actual -eq 0 -and $listeners.Count -eq 0 -and $null -eq $record){'ABSENT'}else{'UNVERIFIED'}
    # Legacy readers expect a bounded 0/1/2 OWNER count; 2 means unverified, not two actual PIDs.
    $legacyCount=if($hard -eq 'RUNNING'){1}elseif($hard -eq 'ABSENT'){0}else{2}
    Write-Host "SITE=$Site INTENT=$intent OWNED=$legacyCount LISTENERS=$($uniquePorts.Count)"
    Write-Host "HARD_SITE=$Site STATE=$hard PROCESSES=$actual PORTS=$($uniquePorts.Count) EXPECTED=$($ports.Count) REASON=$reason RECORD=$recordOk"
    $owned|Select-Object ProcessId,CreationDate
    $listeners|Select-Object LocalAddress,LocalPort,OwningProcess
    exit 0
}
Assert-Entries
Assert-No-Foreign $owned $listeners
$record=Record
if($Action -eq 'Preflight'){
    Write-Host "NATIVE_PREFLIGHT_SITE=$Site OWNED=$($owned.Count) LISTENERS=$($listeners.Count)"
    if($owned.Count -eq 1 -and $listeners.Count -ne $ports.Count){throw 'NATIVE_PARTIAL_RUNNING_REFUSED'}
    if($owned.Count -eq 0 -and $null -ne $record){throw 'ALREADY_RUNNING_OR_STALE_OWNER_REFUSED'}
    exit 0
}
if($Action -eq 'Start'){
    if($owned.Count -ne 0 -or $listeners.Count -ne 0 -or $null -ne $record){throw 'ALREADY_RUNNING_OR_STALE_OWNER_REFUSED'}
    if($Site -eq 'dev' -and [string]$env:EZSCORE_MEDIA_ROOT -eq ''){throw 'DEV_MEDIA_ROOT_ENV_MISSING'}
    if(!(Test-Path -LiteralPath $stateDir -PathType Container)){New-Item -ItemType Directory -Path $stateDir -Force|Out-Null}
    $oldEnv=[Environment]::GetEnvironmentVariable('APP_ENV','Process')
    $oldDbg=[Environment]::GetEnvironmentVariable('APP_DEBUG','Process')
    try{
        [Environment]::SetEnvironmentVariable('APP_ENV',$(if($Site -eq 'prod'){'prod'}else{'dev'}),'Process')
        [Environment]::SetEnvironmentVariable('APP_DEBUG',$(if($Site -eq 'prod'){'0'}else{'1'}),'Process')
        $p=Start-Process -FilePath $exe -ArgumentList @('run','--config',('"'+$config+'"'),'--adapter','caddyfile') -WorkingDirectory $root -PassThru -WindowStyle Hidden -ErrorAction Stop
    }finally{
        [Environment]::SetEnvironmentVariable('APP_ENV',$oldEnv,'Process')
        [Environment]::SetEnvironmentVariable('APP_DEBUG',$oldDbg,'Process')
    }
    $info=Get-CimInstance Win32_Process -Filter "ProcessId=$($p.Id)" -ErrorAction Stop
    if($null -eq $info -or [string]$info.ExecutablePath -ine $exe){throw 'START_PROCESS_NOT_FOUND'}
    $recordObject=[ordered]@{schema='ezs.native-site.owner.v1';site=$Site;pid=[int]$p.Id;creation=[string]$info.CreationDate;config=$config}
    [IO.File]::WriteAllText($recordFile,((ConvertTo-Json -Compress $recordObject)+"`n"),$utf8)
    Write-Host "NATIVE_SITE_STARTED=$Site PID=$($p.Id)"
    exit 0
}
if($Action -eq 'Stop'){
    if($Site -eq 'prod'){throw 'PROD_STOP_PROTECTED_REFUSED'}
    if($owned.Count -ne 1 -or $null -eq $record){throw 'NO_UNAMBIGUOUS_OWNED_PROCESS'}
    Assert-Owner $owned[0] $record
    $again=@(Owned);$tcp=@(Listeners)
    if($again.Count -ne 1 -or [int]$again[0].ProcessId -ne [int]$record.pid -or
       [string]$again[0].CreationDate -ne [string]$record.creation){throw 'PROCESS_IDENTITY_REATTEST_FAILED'}
    Assert-No-Foreign $again $tcp
    Stop-Process -Id ([int]$record.pid) -ErrorAction Stop
    Remove-Item -LiteralPath $recordFile -Force -ErrorAction Stop
    Write-Host "NATIVE_SITE_STOP_REQUESTED=$Site"
}
