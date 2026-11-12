param(
    [Parameter(Position=0)]
    [ValidateSet("start", "stop", "restart", "status")]
    [string]$Action = "start"
)

$PlatformRoot = (Resolve-Path "$PSScriptRoot\..").Path
Set-Location $PlatformRoot

$pythonBin = "H:\Python\pythoncore-3.14-64\python.exe"
$frankenBin = "H:\FRANKENPHP\bin\frankenphp.exe"
$caddyConfig = "$PlatformRoot\config\frankenphp\Caddyfile"
$workerScript = "$PlatformRoot\apps\ezs_assistant\runtime\profile_ai_requests_worker.py"

function Get-AssistantWorker {
    Get-CimInstance Win32_Process -Filter "Name = 'python.exe'" -ErrorAction SilentlyContinue |
        Where-Object { $_.CommandLine -match "profile_ai_requests_worker\.py" }
}

function Get-FrankenProcess {
    Get-CimInstance Win32_Process -Filter "Name = 'frankenphp.exe'" -ErrorAction SilentlyContinue |
        Where-Object { $_.CommandLine -match "EZStudio_plateforme" }
}

function Get-LlamaProcess {
    Get-CimInstance Win32_Process -Filter "Name = 'llama-server.exe'" -ErrorAction SilentlyContinue |
        Where-Object { $_.CommandLine -match "8720" }
}

switch ($Action) {
    "stop" {
        Write-Host "[EZS] Arret de la plateforme..." -ForegroundColor Yellow
        Get-FrankenProcess | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
        Get-AssistantWorker | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
        Get-LlamaProcess | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
        Write-Host "[EZS] Plateforme arretee." -ForegroundColor Green
    }
    "status" {
        Write-Host "=== Statut EZStudio Plateforme ===" -ForegroundColor Cyan
        $fp = Get-FrankenProcess
        Write-Host "Serveur Web FrankenPHP (8000) : $(if ($fp) { "ACTIF (PID $($fp.ProcessId))" } else { 'ARRETE' })" -ForegroundColor $(if ($fp) { 'Green' } else { 'Red' })
        $w = Get-AssistantWorker
        Write-Host "Worker Assistant Python       : $(if ($w) { "ACTIF (PID $($w.ProcessId))" } else { 'ARRETE' })" -ForegroundColor $(if ($w) { 'Green' } else { 'Red' })
        $llama = (Test-NetConnection -ComputerName 127.0.0.1 -Port 8720 -WarningAction SilentlyContinue).TcpTestSucceeded
        Write-Host "Serveur IA Llama (8720)       : $(if ($llama) { 'ACTIF' } else { 'INACTIF' })" -ForegroundColor $(if ($llama) { 'Green' } else { 'Yellow' })
    }
    "restart" {
        & $PSCommandPath stop
        Remove-Item -Recurse -Force "$PlatformRoot\var\cache\*" -ErrorAction SilentlyContinue
        & $PSCommandPath start
    }
    "start" {
        Write-Host "[EZS] Demarrage de la plateforme sur FrankenPHP..." -ForegroundColor Cyan
        $w = Get-AssistantWorker
        if (-not $w) {
            Start-Process -FilePath $pythonBin -ArgumentList "-B", "-u", $workerScript, "--poll-seconds", "5" -WorkingDirectory "$PlatformRoot\apps\ezs_assistant" -WindowStyle Hidden
        }
        $llamaUp = (Test-NetConnection -ComputerName 127.0.0.1 -Port 8720 -WarningAction SilentlyContinue).TcpTestSucceeded
        if (-not $llamaUp) {
            $llamaExe = "H:\EZStudioLab_assistant\runtime\ai\bin\llama-server.exe"
            $llamaModel = "H:\AIModels\assistant\bench\qwen2.5-1.5b-instruct-q4_k_m\qwen2.5-1.5b-instruct-q4_k_m.gguf"
            if ((Test-Path $llamaExe) -and (Test-Path $llamaModel)) {
                Start-Process -FilePath $llamaExe -ArgumentList "-m", $llamaModel, "--host", "127.0.0.1", "--port", "8720", "-c", "4096" -WorkingDirectory (Split-Path $llamaExe) -WindowStyle Hidden
            }
        }
        $fp = Get-FrankenProcess
        if (-not $fp) {
            Start-Process -FilePath $frankenBin -ArgumentList "run", "--config", $caddyConfig, "--adapter", "caddyfile" -WorkingDirectory $PlatformRoot -WindowStyle Hidden
        }
        for ($i = 0; $i -lt 10; $i++) {
            Start-Sleep -Seconds 1
            if ((Test-NetConnection -ComputerName 127.0.0.1 -Port 8720 -WarningAction SilentlyContinue).TcpTestSucceeded) { break }
        }
        & $PSCommandPath status
    }
}