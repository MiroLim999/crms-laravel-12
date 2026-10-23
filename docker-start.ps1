<#
.SYNOPSIS
    Builds and starts CRMS in Docker, on the NVIDIA card when this PC has one that works.

.DESCRIPTION
    Adds docker-compose.gpu.yml only when nvidia-smi answers, so a PC with an AMD or
    Intel card, or none, never gets the GPU file (Docker would refuse to start the
    reader). If Docker still cannot start the reader on the card, it starts again on
    the processor. Then it waits for the reader and says where it reads.

    Windows PowerShell does not run downloaded scripts by default, hence the
    -ExecutionPolicy Bypass. Run from the project folder:

        powershell -ExecutionPolicy Bypass -File .\docker-start.ps1
        powershell -ExecutionPolicy Bypass -File .\docker-start.ps1 -Cpu     # never use the card

    --force-recreate: a rebuilt image is not always picked up by containers that
    already exist, so every container is made afresh. The records are kept.
#>
param([switch]$Cpu)

Set-Location $PSScriptRoot

function Start-Crms([bool]$Gpu) {
    $files = @('-f', 'docker-compose.yml')
    if ($Gpu) { $files += @('-f', 'docker-compose.gpu.yml') }
    & docker compose @files up -d --build --force-recreate
    return ($LASTEXITCODE -eq 0)
}

$gpu = $false
if (-not $Cpu) {
    $smi = Get-Command nvidia-smi -ErrorAction SilentlyContinue
    if ($smi) {
        # Lists the cards; fails when the driver is missing or broken.
        $null = & $smi.Source -L 2>&1
        $gpu = ($LASTEXITCODE -eq 0)
    }
}

if ($gpu) {
    Write-Host 'NVIDIA card found: the handwriting reader will use it.' -ForegroundColor Green
} else {
    Write-Host 'No NVIDIA card in use: everything runs on the processor.' -ForegroundColor Yellow
}

$started = Start-Crms $gpu
if (-not $started -and $gpu) {
    Write-Host 'Docker could not start the reader on the NVIDIA card. Starting again on the processor.' -ForegroundColor Yellow
    $gpu = $false
    $started = Start-Crms $false
}
if (-not $started) {
    Write-Host 'Docker could not start CRMS. See the messages above.' -ForegroundColor Red
    exit 1
}

Write-Host 'Waiting for the handwriting reader...'
$device = $null
foreach ($i in 1..60) {
    try {
        $device = (Invoke-RestMethod -Uri 'http://127.0.0.1:8081/health' -TimeoutSec 3).device
        break
    } catch {
        Start-Sleep -Seconds 2
    }
}

if (-not $device) {
    Write-Host 'The reader has not answered yet. Check it with: docker compose logs ocr' -ForegroundColor Yellow
} elseif ($device -like 'cuda*') {
    Write-Host 'The reader reads on the NVIDIA card.' -ForegroundColor Green
    Write-Host 'If the card fails at the first scan, it switches to the processor by itself.'
} else {
    Write-Host 'The reader reads on the processor.' -ForegroundColor Yellow
}
Write-Host 'CRMS is running: http://localhost:8080' -ForegroundColor Green
