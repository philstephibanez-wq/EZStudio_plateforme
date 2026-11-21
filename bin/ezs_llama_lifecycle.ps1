[CmdletBinding()]
param([Parameter(Mandatory=$true)][ValidateSet('start','stop','status')][string]$Action)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
$platform='H:\EZStudio_plateforme'
$exe=Join-Path $platform 'apps\ezs_assistant\runtime\ai\bin\llama-server.exe'
$model='H:\AIModels\assistant\bench\qwen2.5-1.5b-instruct-q4_k_m\qwen2.5-1.5b-instruct-q4_k_m.gguf'
$port=8720
$stateDir='H:\EZStudio_plateforme\var\runtime\ezs_assistant\llama'
$ledger=Join-Path $stateDir 'owner.json'
function Fail([string]$why) { throw "EZS_LLAMA_$why" }
function Resolve-NvidiaVulkanDevice {
    # Vulkan numbering is not stable. Refuse missing or ambiguous RTX 2060.
    $psi=New-Object System.Diagnostics.ProcessStartInfo
    $psi.FileName=$exe
    $psi.Arguments='--list-devices'
    $psi.WorkingDirectory=Split-Path $exe
    $psi.UseShellExecute=$false
    $psi.CreateNoWindow=$true
    $psi.RedirectStandardOutput=$true
    $psi.RedirectStandardError=$true
    $probe=New-Object System.Diagnostics.Process
    $probe.StartInfo=$psi
    try {
        if(-not $probe.Start()){Fail 'GPU_PROBE_START_FAILED'}
        $outTask=$probe.StandardOutput.ReadToEndAsync()
        $errTask=$probe.StandardError.ReadToEndAsync()
        if(-not $probe.WaitForExit(10000)){
            try{$probe.Kill()}catch{}
            Fail 'GPU_PROBE_TIMEOUT'
        }
        $listing=$outTask.GetAwaiter().GetResult()+"`n"+$errTask.GetAwaiter().GetResult()
        if($probe.ExitCode -ne 0){Fail 'GPU_PROBE_FAILED'}
        $matchesFound=@([regex]::Matches($listing,'(?im)^\s*(Vulkan\d+):\s*NVIDIA GeForce RTX 2060\s*\('))
        if($matchesFound.Count -ne 1){Fail 'NVIDIA_RTX2060_MISSING_OR_AMBIGUOUS'}
        $device=$matchesFound[0].Groups[1].Value
        Write-Host "EZS_LLAMA_GPU_SELECTED=$device NAME=NVIDIA_GEFORCE_RTX_2060"
        return $device
    } finally { $probe.Dispose() }
}
function Proc([int]$processId) {
    @(Get-CimInstance Win32_Process -Filter "ProcessId = $processId" -ErrorAction Stop) | Select-Object -First 1
}
function ListenPid {
    $listeners=@(Get-NetTCPConnection -State Listen -LocalPort $port -ErrorAction SilentlyContinue)
    if($listeners.Count -eq 0){return 0}
    $owners=@($listeners | Select-Object -ExpandProperty OwningProcess -Unique)
    if($owners.Count -ne 1 -or [int]$owners[0] -le 0){Fail 'PORT_OWNER_AMBIGUOUS'}
    return [int]$owners[0]
}
function Healthy {
    try {
        $r=Invoke-RestMethod -Uri "http://127.0.0.1:$port/health" -TimeoutSec 2 -ErrorAction Stop
        return ($r.status -eq 'ok' -or $r.status -eq 'ready')
    } catch {return $false}
}
function ReadOwner {
    if(!(Test-Path -LiteralPath $ledger -PathType Leaf)){return $null}
    try {
        $o=Get-Content -LiteralPath $ledger -Raw -Encoding UTF8 | ConvertFrom-Json
        if([int]$o.pid -le 0 -or [string]::IsNullOrWhiteSpace([string]$o.creation)) {Fail 'LEDGER_INVALID'}
        return $o
    } catch {Fail 'LEDGER_INVALID'}
}
function Matches([object]$p,[object]$o) {
    if($null -eq $p){return $false}
    if([int]$p.ProcessId -ne [int]$o.pid){return $false}
    if(([string]$p.ExecutablePath).Replace('/','\') -ine $exe.Replace('/','\')){return $false}
    if([string]$p.CreationDate -ne [string]$o.creation){return $false}
    $cmd=[string]$p.CommandLine
    if($cmd -notmatch '(?i)(?:^|\s)--port\s+8720(?:\s|$)'){return $false}
    if($cmd.IndexOf($model,[StringComparison]::OrdinalIgnoreCase) -lt 0){return $false}
    return $true
}
function CurrentOwner {
    $o=ReadOwner
    if($null -eq $o){return $null}
    $p=Proc ([int]$o.pid)
    if($null -eq $p){return [pscustomobject]@{ ledger=$o; process=$null; valid=$true }}
    return [pscustomobject]@{ ledger=$o; process=$p; valid=(Matches $p $o) }
}
if($Action -eq 'status') {
    $pidListen=ListenPid
    $own=CurrentOwner
    $managed=($null -ne $own -and $own.valid -and $null -ne $own.process -and [int]$own.ledger.pid -eq $pidListen)
    $health=Healthy
    Write-Host "EZS_LLAMA_STATUS LISTENER_PID=$pidListen HEALTH=$health MANAGED=$managed"
    exit 0
}
if($Action -eq 'start') {
    if(!(Test-Path -LiteralPath $exe -PathType Leaf)){Fail 'EXE_MISSING'}
    if(!(Test-Path -LiteralPath $model -PathType Leaf)){Fail 'MODEL_MISSING'}
    $own=CurrentOwner
    if($null -ne $own -and !$own.valid){Fail 'LEDGER_PID_REUSED_OR_IDENTITY_CHANGED'}
    $listener=ListenPid
    if($listener -ne 0){
        if($null -ne $own -and $null -ne $own.process -and [int]$own.ledger.pid -eq $listener -and (Healthy)) {
            Write-Host "EZS_LLAMA_ALREADY_MANAGED_READY PID=$listener"
            exit 0
        }
        Fail "PORT_8720_IN_USE_PID_$listener`_NOT_MANAGED"
    }
    if($null -ne $own -and $null -ne $own.process){Fail 'OWNED_PROCESS_WITHOUT_LISTENER_REFUSED'}
    # Refuse any other running llama-server, including a manually launched instance still loading.
    $running=@(Get-CimInstance Win32_Process -Filter "Name = 'llama-server.exe'" -ErrorAction SilentlyContinue)
    if($running.Count -gt 0){Fail 'OTHER_LLAMA_PROCESS_PRESENT'}
    if(!(Test-Path -LiteralPath $stateDir -PathType Container)){New-Item -ItemType Directory -Path $stateDir -Force | Out-Null}
    $logDir=Join-Path $platform 'var\log\ezs_assistant\llama'
    if(!(Test-Path -LiteralPath $logDir -PathType Container)){New-Item -ItemType Directory -Path $logDir -Force | Out-Null}
    $stamp=Get-Date -Format 'yyyyMMdd-HHmmss'
    $stdout=Join-Path $logDir "llama-$stamp.out.log"
    $stderr=Join-Path $logDir "llama-$stamp.err.log"
    $nativeLog=Join-Path $logDir "llama-native-$stamp.log"
    $nvidiaDevice=Resolve-NvidiaVulkanDevice
    $argsLine='-m "'+$model+'" --host 127.0.0.1 --port 8720 --ctx-size 4096 --parallel 1 -dev '+$nvidiaDevice+' -ngl 99 --log-file "'+$nativeLog+'"'
    $child=Start-Process -FilePath $exe -ArgumentList $argsLine -WorkingDirectory (Split-Path $exe) -WindowStyle Hidden -PassThru -RedirectStandardOutput $stdout -RedirectStandardError $stderr
    $p=$null
    $owner=$null
    for($i=0;$i -lt 50;$i++) {
        Start-Sleep -Milliseconds 100
        $p=Proc ([int]$child.Id)
        if($null -eq $p){Fail 'SPAWN_EXITED_BEFORE_IDENTITY_CAPTURE'}
        if(-not $p.CreationDate){continue}
        $candidate=[ordered]@{pid=[int]$p.ProcessId; creation=[string]$p.CreationDate; exe=$exe; model=$model; port=8720; native_log=$nativeLog}
        if(Matches $p ([pscustomobject]$candidate)){
            $owner=$candidate
            break
        }
    }
    if($null -eq $owner){Fail 'SPAWN_IDENTITY_MISMATCH'}
    $owner | ConvertTo-Json -Depth 3 | Set-Content -LiteralPath $ledger -Encoding UTF8
    for($i=0;$i -lt 120;$i++) {
        $now=Proc ([int]$owner.pid)
        if($null -eq $now){Fail "SPAWN_EXITED_LOG_$stderr"}
        if(!(Matches $now ([pscustomobject]$owner))){Fail 'SPAWN_IDENTITY_CHANGED'}
        $portPid=ListenPid
        if($portPid -ne 0 -and $portPid -ne [int]$owner.pid){Fail 'PORT_FOREIGN_DURING_START'}
        if($portPid -eq [int]$owner.pid -and (Healthy)) {
            Write-Host "EZS_LLAMA_MANAGED_READY PID=$($owner.pid) MODEL=QWEN2.5_1.5B"
            exit 0
        }
        Start-Sleep -Milliseconds 500
    }
    Fail "START_TIMEOUT_LOG_$stderr"
}
if($Action -eq 'stop') {
    $own=CurrentOwner
    if($null -eq $own){
        if((ListenPid) -ne 0){Write-Warning 'LLAMA_FOREIGN_LISTENER_LEFT_UNTOUCHED'}
        Write-Host 'EZS_LLAMA_NOT_MANAGED'
        exit 0
    }
    if(!$own.valid){Fail 'STOP_REFUSED_PID_REUSED_OR_IDENTITY_CHANGED'}
    if($null -eq $own.process){Remove-Item -LiteralPath $ledger -Force;Write-Host 'EZS_LLAMA_STALE_LEDGER_CLEARED';exit 0}
    $listener=ListenPid
    if($listener -ne 0 -and $listener -ne [int]$own.ledger.pid){Fail 'STOP_REFUSED_FOREIGN_PORT'}
    Stop-Process -Id ([int]$own.ledger.pid) -ErrorAction Stop
    for($i=0;$i -lt 30;$i++) {
        Start-Sleep -Milliseconds 200
        if($null -eq (Proc ([int]$own.ledger.pid))){break}
    }
    if($null -ne (Proc ([int]$own.ledger.pid))){Fail 'STOP_PROCESS_STILL_RUNNING'}
    Remove-Item -LiteralPath $ledger -Force
    Write-Host "EZS_LLAMA_MANAGED_STOPPED PID=$($own.ledger.pid)"
}
