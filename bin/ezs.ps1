param(
    [Parameter(Position = 0)]
    [string]$Action = "help",
    [Alias("help")][switch]$h
)

$ErrorActionPreference = "Stop"
$PlatformRoot = "H:\EZStudio_plateforme"

if ($h -or ($Action -in @("-h", "--help", "help", "/?"))) {
    Write-Host "EZStudio Plateforme - Lanceur unifie (v5.0)" -ForegroundColor Cyan
    Write-Host ""
    Write-Host "Usage :" -ForegroundColor Yellow
    Write-Host "  ezs <commande>"
    Write-Host ""
    Write-Host "Commandes disponibles :" -ForegroundColor Yellow
    Write-Host "  start     Demarre FrankenPHP (port 8000) et le worker Assistant Python"
    Write-Host "  stop      Arrete proprement FrankenPHP et le worker Assistant Python"
    Write-Host "  restart   Redemarre l ensemble de la plateforme"
    Write-Host "  status    Affiche l etat des services (FrankenPHP, Worker Python, Llama 8720)"
    Write-Host "  -h        Affiche cette aide"
    exit 0
}

$frankenBin = "H:\FRANKENPHP\bin\frankenphp.exe"
$caddyConfig = "$PlatformRoot\config\frankenphp\Caddyfile"
$pythonBin = "H:\Python\pythoncore-3.14-64\python.exe"
$workerScript = "$PlatformRoot\apps\ezs_assistant\runtime\profile_ai_requests_worker.py"

function Get-FrankenProcess {
    Get-CimInstance Win32_Process -Filter "Name = 'frankenphp.exe'" -ErrorAction SilentlyContinue |
        Where-Object { $_.CommandLine -match "EZStudio_plateforme" }
}

function Get-AssistantWorkerProcess {
    Get-CimInstance Win32_Process -Filter "Name = 'python.exe'" -ErrorAction SilentlyContinue |
        Where-Object { $_.CommandLine -match "profile_ai_requests_worker\.py" }
}

function Show-Status {
    $fp = Get-FrankenProcess
    $wk = Get-AssistantWorkerProcess
    $llamaOk = $false
    try {
        $r = Invoke-WebRequest -Uri ("http://127.0.0.1:" + 8720 + "/health") -UseBasicParsing -TimeoutSec 2 -ErrorAction Stop
        if ($r.StatusCode -eq 200) { $llamaOk = $true }
    } catch {}

    Write-Host "=== Statut EZStudio Plateforme ===" -ForegroundColor Cyan
    Write-Host ("Serveur Web FrankenPHP (8000) : " + $(if ($fp) { "ACTIF (PID $($fp.ProcessId -join ', '))" } else { "ARRETE" })) -ForegroundColor $(if ($fp) { "Green" } else { "Red" })
    Write-Host ("Worker Assistant Python       : " + $(if ($wk) { "ACTIF (PID $($wk.ProcessId -join ', '))" } else { "ARRETE" })) -ForegroundColor $(if ($wk) { "Green" } else { "Yellow" })
    Write-Host ("Serveur IA Llama (8720)       : " + $(if ($llamaOk) { "ACTIF" } else { "ARRETE" })) -ForegroundColor $(if ($llamaOk) { "Green" } else { "Yellow" })
}

switch ($Action.ToLower()) {
    "start" {
        Write-Host "[EZS] Demarrage de la plateforme sur FrankenPHP..." -ForegroundColor Cyan
        if (-not (Get-FrankenProcess)) {
            Start-Process -FilePath $frankenBin -ArgumentList "run", "--config", $caddyConfig, "--adapter", "caddyfile" -WorkingDirectory $PlatformRoot -WindowStyle Hidden
            Start-Sleep -Seconds 2
        }
        if (-not (Get-AssistantWorkerProcess) -and (Test-Path $workerScript)) {
            Start-Process -FilePath $pythonBin -ArgumentList "-B", "-u", $workerScript -WorkingDirectory "$PlatformRoot\apps\ezs_assistant" -WindowStyle Hidden
            Start-Sleep -Seconds 1
        }
        Show-Status
    }
    "stop" {
        Write-Host "[EZS] Arret de la plateforme..." -ForegroundColor Yellow
        Get-FrankenProcess | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
        Get-AssistantWorkerProcess | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
        Start-Sleep -Seconds 1
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
