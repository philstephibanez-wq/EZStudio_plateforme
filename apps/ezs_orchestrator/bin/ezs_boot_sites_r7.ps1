# R9 compatibility boundary. Never launch/stop a web site from Orchestrator.
[CmdletBinding()]
param([Parameter(Mandatory=$true)][ValidateSet('Preflight','Boot','HardStatus')][string]$Action)
$ErrorActionPreference='Stop'
if($Action -eq 'Boot') { throw 'EZS_R9_SITE_BOOT_RETIRED_USE_WINDOWS_SERVICE' }
if($Action -eq 'Preflight') {
    Write-Output 'EZS_R9_WEB_PREFLIGHT_OBSERVATION_ONLY'
    exit 0
}
# Existing GET is the TCP/HTTP hard collector. It does not launch a process.
$result=Invoke-RestMethod -Uri 'http://127.0.0.1:8790/api/v2/infrastructure/web-hard-ping' -TimeoutSec 15 -ErrorAction Stop
if($result.schema -ne 'ezs.web_hard_ping.v1'){throw 'EZS_R9_HARD_SCHEMA_INVALID'}
foreach($site in @('dev','lab','assistant','prod')){
    if($null -eq $result.sites.$site){throw ('EZS_R9_HARD_SITE_MISSING_'+$site)}
    $entry=$result.sites.$site
    Write-Output (('{0}: {1} HTTP={2}' -f $site,$entry.state,$entry.http.status))
}
Write-Output 'EZS_R9_HARD_OBSERVATION_OK'
