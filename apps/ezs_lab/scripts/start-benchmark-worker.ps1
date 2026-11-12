param(
    [Parameter(Mandatory=$true)][string]$Python,
    [Parameter(Mandatory=$true)][string]$Worker,
    [Parameter(Mandatory=$true)][string]$Database,
    [Parameter(Mandatory=$true)][int]$RunId,
    [Parameter(Mandatory=$true)][string]$Audio,
    [Parameter(Mandatory=$true)][string]$Signature,
    [Parameter(Mandatory=$true)][string]$Deps,
    [Parameter(Mandatory=$true)][ValidateSet("0","1")][string]$KeepUpload,
    [Parameter(Mandatory=$true)][string]$Stdout,
    [Parameter(Mandatory=$true)][string]$Stderr
)

$ErrorActionPreference = "Stop"

foreach ($path in @($Python, $Worker)) {
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
        throw "Required executable/script not found: $path"
    }
}

$stdoutDir = Split-Path -Parent $Stdout
$stderrDir = Split-Path -Parent $Stderr

foreach ($dir in @($stdoutDir, $stderrDir)) {
    if ($dir -and -not (Test-Path -LiteralPath $dir)) {
        New-Item -ItemType Directory -Force -Path $dir | Out-Null
    }
}

$arguments = @(
    $Worker,
    "--db", $Database,
    "--run-id", [string]$RunId,
    "--audio", $Audio,
    "--signature", $Signature,
    "--deps", $Deps,
    "--keep-upload", $KeepUpload
)

$process = Start-Process `
    -FilePath $Python `
    -ArgumentList $arguments `
    -WorkingDirectory (Split-Path -Parent $Worker) `
    -WindowStyle Hidden `
    -RedirectStandardOutput $Stdout `
    -RedirectStandardError $Stderr `
    -PassThru

if ($null -eq $process -or $process.Id -le 0) {
    throw "Start-Process did not return a valid child PID."
}

Write-Output ("WORKER_STARTED pid=" + $process.Id + " run=" + $RunId)
exit 0
