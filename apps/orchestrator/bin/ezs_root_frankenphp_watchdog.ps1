# R9 compatibility name: read-only ROOT HTTP observer, not a watchdog with recovery.
[CmdletBinding()]
param([switch]$Once)
$ErrorActionPreference='Stop'
$root='H:\EZS_orchestrator'
$logDir=Join-Path $root 'var\log\supervision'
if(!(Test-Path -LiteralPath $logDir -PathType Container)){
    New-Item -ItemType Directory -Force -Path $logDir|Out-Null
}
$mutex=New-Object Threading.Mutex($false,'Local\EZS_ROOT_FRANKENPHP_TICK')
$locked=$false
try {
    try{$locked=$mutex.WaitOne(0)}catch [Threading.AbandonedMutexException]{$locked=$true}
    if(!$locked){return}
    $code=0
    try {
        $response=Invoke-WebRequest -Uri 'http://127.0.0.1:8790/' -UseBasicParsing -TimeoutSec 4 -MaximumRedirection 0 -ErrorAction Stop
        $code=[int]$response.StatusCode
    }catch [System.Net.WebException]{
        if($null -ne $_.Exception.Response){$code=[int]$_.Exception.Response.StatusCode}
    }catch{}
    if($code -ge 200 -and $code -lt 400){
        Write-Output 'ROOT_WEB_REACHABLE'
        return
    }
    $json=[ordered]@{schema='ezs.root.web_observation.r9';utc=[DateTime]::UtcNow.ToString('o');event='ROOT_WEB_UNAVAILABLE';http_status=$code}|ConvertTo-Json -Compress
    [IO.File]::AppendAllText((Join-Path $logDir 'root-frankenphp-watchdog.jsonl'),$json+"`n",(New-Object Text.UTF8Encoding($false)))
    Write-Output ('ROOT_WEB_UNAVAILABLE HTTP='+$code)
}finally{
    if($locked){$mutex.ReleaseMutex()}
    $mutex.Dispose()
}
