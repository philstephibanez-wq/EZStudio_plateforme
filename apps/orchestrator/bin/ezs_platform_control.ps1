[CmdletBinding()]
param(
    [Parameter(Mandatory=$true)][ValidateSet('prod','dev','lab','assistant')][string]$Site,
    [Parameter(Mandatory=$true)][ValidateSet('Preflight','Status','Start','Stop')][string]$Action
)
$ErrorActionPreference = 'Stop'
$root='H:\EZS_orchestrator'
$native=Join-Path $root 'scripts\ezs_native_site.ps1'
$assistant=Join-Path $root 'scripts\ezs_assistant_stack.ps1'
$exe='H:\FRANKENPHP\bin\frankenphp.exe'
if ($Site -eq 'prod' -and $Action -eq 'Stop') {
    throw 'PROD_STOP_PROTECTED_REFUSED'
}
$target=if($Site -eq 'assistant'){$assistant}else{$native}
if(!(Test-Path -LiteralPath $target -PathType Leaf)) {throw ('PLATFORM_DELEGATE_MISSING '+$target)}
# Verify a native PHP configuration without launching any process.
# The Assistant delegates its own Llama lifecycle to canonical start.ps1 and stop.ps1.
if($Site -ne 'assistant' -and $Action -in @('Preflight','Start')) {
    $config=Join-Path $root ('config\frankenphp\Caddyfile.'+$Site+'.native')
    if(!(Test-Path -LiteralPath $exe -PathType Leaf)) {throw 'FRANKENPHP_EXE_MISSING'}
    if(!(Test-Path -LiteralPath $config -PathType Leaf)) {throw 'CADDY_CONFIG_MISSING'}
    $content=Get-Content -LiteralPath $config -Raw -Encoding UTF8
    if($content -match '(?i)\bphp_fastcgi\b|\bphp-cgi\b'){throw 'CADDY_CONFIG_FASTCGI_REFUSED'}
    # Caddy/FrankenPHP parses the configuration. No service is started.
    # Windows PowerShell 5.1 converts native stderr warnings to error records when
    # redirecting 2>&1. Caddy emits formatting warnings on stderr even on exit 0.
    # Temporarily permit those records, but keep strict execution and the native exit gate.
    $previousErrorActionPreference=$ErrorActionPreference
    try {
        $ErrorActionPreference='Continue'
        $null=& $exe adapt --config $config --adapter caddyfile 2>&1
        $adaptExitCode=$LASTEXITCODE
    } finally {
        $ErrorActionPreference=$previousErrorActionPreference
    }
    if($adaptExitCode -ne 0){throw ('FRANKENPHP_ADAPT_FAILED '+$Site+' exit='+$adaptExitCode)}
}
$args=@('-NoProfile','-ExecutionPolicy','Bypass','-File',$target,'-Action',$Action)
if($Site -ne 'assistant'){$args+=@('-Site',$Site)}
& powershell.exe @args
if($LASTEXITCODE -ne 0){throw ('PLATFORM_'+$Site.ToUpperInvariant()+'_'+$Action.ToUpperInvariant()+'_FAILED_'+$LASTEXITCODE)}
Write-Host ('EZS_PLATFORM_'+$Site.ToUpperInvariant()+'_'+$Action.ToUpperInvariant()+'_OK')
