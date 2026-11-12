[CmdletBinding()]
param([Parameter(Mandatory=$true)][ValidateSet('Preflight','Status','Start','Stop')][string]$Action)
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
$root='H:\EZS_orchestrator'
$assistant='H:\EZStudioLab_assistant'
$start=Join-Path $assistant 'scripts\start.ps1'
$stop=Join-Path $assistant 'scripts\stop.ps1'
$config=Join-Path $root 'config\frankenphp\Caddyfile.assistant.native'
$franken='H:\FRANKENPHP\bin\frankenphp.exe'
function Assert-Canonical {
 foreach($f in @($start,$stop)){if(!(Test-Path -LiteralPath $f -PathType Leaf)){throw ('ASSISTANT_CANONICAL_SCRIPT_MISSING '+$f)}}
 $a=Get-Content -LiteralPath $start -Raw -Encoding UTF8 -ErrorAction Stop
 $b=Get-Content -LiteralPath $stop -Raw -Encoding UTF8 -ErrorAction Stop
 if($a -notmatch 'llama-server\.exe' -or $a -notmatch '8720' -or $a -notmatch 'start-web\.ps1'){throw 'ASSISTANT_START_LLAMA_CONTRACT_INVALID'}
 if($b -notmatch 'llama-server\.exe' -or $b -notmatch '8720' -or $b -notmatch 'ASSISTANT_SAFE_STOP_REFUSED'){throw 'ASSISTANT_STOP_LLAMA_CONTRACT_INVALID'}
}
Assert-Canonical
if($Action -eq 'Preflight'){Write-Host 'ASSISTANT_LLAMA_CANONICAL_PREFLIGHT_OK';exit 0}
if($Action -eq 'Status') {
 $processes=@(Get-CimInstance Win32_Process -ErrorAction Stop)
 $web=@($processes|Where-Object {
   [string]$_.ExecutablePath -ieq $franken -and
   [string]$_.CommandLine.Replace('/','\').IndexOf($config.Replace('/','\'),[StringComparison]::OrdinalIgnoreCase) -ge 0
 })
 $llama=@($processes|Where-Object {
   [string]$_.ExecutablePath -match '(?i)[\\/]llama-server\.exe$' -and
   [string]$_.CommandLine -match '(?i)(?:^|\s)(?:--port\s+)?8720(?:\s|$)'
 })
 $listeners=@(Get-NetTCPConnection -State Listen -ErrorAction Stop|Where-Object {$_.LocalPort -in @(8712,8713,8720)})
 $ports=@($listeners|Select-Object -ExpandProperty LocalPort -Unique)
 $valid=($web.Count -eq 1 -and $llama.Count -eq 1 -and $ports.Count -eq 3)
 if($valid){
  foreach($port in @(8712,8713)){
   if(@($listeners|Where-Object {$_.LocalPort -eq $port -and $_.OwningProcess -eq [int]$web[0].ProcessId}).Count -lt 1){$valid=$false}
  }
  if(@($listeners|Where-Object {$_.LocalPort -eq 8720 -and $_.OwningProcess -eq [int]$llama[0].ProcessId}).Count -lt 1){$valid=$false}
 }
 $hard=if($valid){'RUNNING'}elseif($web.Count -eq 0 -and $llama.Count -eq 0 -and $ports.Count -eq 0){'ABSENT'}else{'UNVERIFIED'}
 $owned=if($hard -eq 'RUNNING'){1}elseif($hard -eq 'ABSENT'){0}else{2}
 $reason=if($hard -eq 'RUNNING'){'HARD_ATTESTED'}elseif($hard -eq 'ABSENT'){'NATIVE_ABSENT'}elseif($web.Count -gt 1 -or $llama.Count -gt 1){'MULTIPLE_MATCHING_PROCESSES'}elseif($web.Count -eq 0 -and $llama.Count -gt 0){'LLAMA_WITHOUT_WEB'}elseif($web.Count -gt 0 -and $llama.Count -eq 0){'WEB_WITHOUT_LLAMA'}elseif($ports.Count -lt 3){'PORTS_INCOMPLETE'}else{'FOREIGN_LISTENER'}
 Write-Host ('SITE=assistant INTENT=RUNNING OWNED='+$owned+' LISTENERS='+$ports.Count)
 Write-Host ('HARD_SITE=assistant STATE='+$hard+' PROCESSES='+($web.Count+$llama.Count)+' PORTS='+$ports.Count+' EXPECTED=3 REASON='+$reason+' RECORD=True')
 Write-Host 'ASSISTANT_LLAMA_STATUS_READONLY'
 exit 0
}
# Assistant owns its web/Llama internals. Only its canonical scripts may mutate them.
$entry=if($Action -eq 'Start'){$start}else{$stop}
& powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -File $entry
if($LASTEXITCODE -ne 0){throw ('ASSISTANT_CANONICAL_'+$Action+'_FAILED_'+$LASTEXITCODE)}
Write-Host ('ASSISTANT_LLAMA_COUPLED_'+$Action.ToUpperInvariant()+'_SCRIPT_OK')
