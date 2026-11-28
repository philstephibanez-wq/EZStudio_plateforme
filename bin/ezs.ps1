param(
    [Parameter(Position = 0)]
    [string]$Action = "help",
    [Alias("help")][switch]$h
)

$ErrorActionPreference = "Stop"
$PlatformRoot = "H:\EZStudio_plateforme"

if ($h -or ($Action -in @("-h", "--help", "help", "/?"))) {
    Write-Host "EZStudio Plateforme - Lanceur unifie (v5.1)" -ForegroundColor Cyan
    Write-Host ""
    Write-Host "Usage :" -ForegroundColor Yellow
    Write-Host "  ezs <commande>"
    Write-Host ""
    Write-Host "Commandes disponibles :" -ForegroundColor Yellow
    Write-Host "  start     Demarre FrankenPHP (port 8000) et le consommateur centralise Orchestrator"
    Write-Host "  stop      Arrete proprement FrankenPHP et le consommateur centralise Orchestrator"
    Write-Host "  restart   Redemarre l ensemble de la plateforme"
    Write-Host "  status    Affiche l etat des services (FrankenPHP, Orchestrator Queue)"
    Write-Host "  -h        Affiche cette aide"
    exit 0
}

$frankenBin = "H:\FRANKENPHP\bin\frankenphp.exe"
$caddyConfig = "$PlatformRoot\config\frankenphp\Caddyfile"
$phpBin = "H:\FRANKENPHP\bin\php.exe"

function Get-FrankenProcess {
    Get-CimInstance Win32_Process -Filter "Name = 'frankenphp.exe'" -ErrorAction SilentlyContinue |
        Where-Object { $_.CommandLine -match "EZStudio_plateforme" }
}

function Get-OrchestratorQueueProcess {
    Get-CimInstance Win32_Process -Filter "Name = 'php.exe'" -ErrorAction SilentlyContinue |
        Where-Object { $_.CommandLine -match "orchestrator:queue:consume" }
}

function Show-Status {
    $fp = Get-FrankenProcess
    $oq = Get-OrchestratorQueueProcess
    Write-Host "=== Statut EZStudio Plateforme ===" -ForegroundColor Cyan
    Write-Host ("Serveur Web FrankenPHP (8000) : " + $(if ($fp) { "ACTIF (PID $($fp.ProcessId -join ', '))" } else { "ARRETE" })) -ForegroundColor $(if ($fp) { "Green" } else { "Red" })
    Write-Host ("Orchestrator Queue Consumer   : " + $(if ($oq) { "ACTIF (PID $($oq.ProcessId -join ', '))" } else { "ARRETE" })) -ForegroundColor $(if ($oq) { "Green" } else { "Yellow" })
}

switch ($Action.ToLower()) {
    "start" {
        Write-Host "[EZS] Demarrage de la plateforme sur FrankenPHP..." -ForegroundColor Cyan
        if (-not (Get-FrankenProcess)) {
            Start-Process -FilePath $frankenBin -ArgumentList "run", "--config", $caddyConfig, "--adapter", "caddyfile" -WorkingDirectory $PlatformRoot -WindowStyle Hidden
            Start-Sleep -Seconds 2
        }
        if (-not (Get-OrchestratorQueueProcess)) {
            $env:APP_ID = "orchestrator"
            Start-Process -FilePath $phpBin -ArgumentList "bin/console", "orchestrator:queue:consume", "--loop" -WorkingDirectory $PlatformRoot -WindowStyle Hidden
            Remove-Item Env:APP_ID -ErrorAction SilentlyContinue
            Start-Sleep -Seconds 1
        }
        Show-Status
    }
    "stop" {
        Write-Host "[EZS] Arret de la plateforme..." -ForegroundColor Yellow
        Get-FrankenProcess | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
        Get-OrchestratorQueueProcess | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction Stop }
        Get-CimInstance Win32_Process -Filter "Name = 'python.exe'" -ErrorAction SilentlyContinue | Where-Object { $_.CommandLine -match "EZStudio_plateforme" } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
        Start-Sleep -Seconds 1
        if (Get-OrchestratorQueueProcess) { throw 'EZS_CONSUMER_STOP_FAILED' }
        $previousApp = $env:APP_ID
        try {
            $env:APP_ID = 'orchestrator'
            & $phpBin -d opcache.enable_cli=0 "$PlatformRoot\bin\console" orchestrator:queue:consume --seal-interrupted
            if ($LASTEXITCODE -ne 0) { throw 'EZS_STOP_SEAL_FAILED' }
        } finally { $env:APP_ID = $previousApp }
        Remove-Item -Path "$PlatformRoot\apps\ezs_lab\var\cache\*" -Recurse -Force -ErrorAction SilentlyContinue
        Write-Host "[EZS] Plateforme arretee." -ForegroundColor Green
    }
    "restart" {
        & $PSCommandPath stop
        & $PSCommandPath start
    }
    "status" {
        Show-Status
    }
    default {
        Write-Host "Commande inconnue : $Action (utilisez 'ezs -h' pour l aide)" -ForegroundColor Red
        exit 1
    }
}