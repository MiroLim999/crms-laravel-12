<#
    serve.ps1
    Starts the two processes CRMS needs, each in its own window, from the repo root.

        .\serve.ps1            start both
        .\serve.ps1 -Check     verify the environment and exit
        .\serve.ps1 -NoOcr     Laravel only

    Apache is NOT used. Laravel is served by `php artisan serve` on port 8000, so
    the only XAMPP module that has to be running is MySQL. Sitting in htdocs is
    incidental - nothing here is served by Apache.

    The OCR service is meant to stay up for the whole working session: Staff
    scanning depends on it and it costs almost nothing while idle. CRMS never starts
    or stops it - the OCR workspace only reports whether it answers - so this script
    (or a supervisor in a deployment) is how it gets running.
#>

[CmdletBinding()]
param(
    [switch]$Check,
    [switch]$NoOcr,
    [int]$AppPort = 8000,
    [int]$OcrPort = 8001
)

$ErrorActionPreference = 'Stop'
$root = $PSScriptRoot

function Write-Step($message) { Write-Host "  $message" -ForegroundColor Cyan }
function Write-Good($message) { Write-Host "  OK    $message" -ForegroundColor Green }
function Write-Warn($message) { Write-Host "  WARN  $message" -ForegroundColor Yellow }
function Write-Bad ($message) { Write-Host "  FAIL  $message" -ForegroundColor Red }

function Test-Port([int]$Port) {
    $null -ne (Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue)
}

Write-Host ''
Write-Host 'CRMS - environment check' -ForegroundColor White
Write-Host ('-' * 40)

$problems = 0

# --- PHP -----------------------------------------------------------------
try {
    $php = (& php -r 'echo PHP_VERSION;' 2>$null)
    Write-Good "PHP $php"
} catch {
    Write-Bad 'php not found on PATH. Add C:\xampp\php to PATH.'
    $problems++
}

# --- MySQL ---------------------------------------------------------------
# Required: Apache is not, MySQL is. Laravel cannot boot a page without it.
if (Test-Port 3306) {
    Write-Good 'MySQL is listening on 3306'
} else {
    Write-Warn 'MySQL is not running. Start it in the XAMPP Control Panel.'
    $problems++
}

# --- Python + the ML stack ----------------------------------------------
if (-not $NoOcr) {
    try {
        $py = (& python --version 2>&1)
        Write-Good "$py"
    } catch {
        Write-Bad 'python not found on PATH.'
        $problems++
    }

    # One probe for every import the service needs, so a missing package is named
    # here instead of surfacing as a traceback in a window that closes.
    # importlib.util is a submodule: `import importlib` alone does not bind it.
    $probe = @'
import importlib.util
import sys

required = ("uvicorn", "fastapi", "torch", "transformers", "pandas", "matplotlib", "tqdm", "PIL")
missing = [name for name in required if importlib.util.find_spec(name) is None]

if missing:
    print("MISSING " + " ".join(missing))
    raise SystemExit(1)

import torch

if torch.cuda.is_available():
    print("CUDA yes - " + torch.cuda.get_device_name(0))
else:
    print("CUDA no - running on CPU")
'@

    $probeFile = Join-Path $env:TEMP 'crms-probe.py'
    Set-Content -Path $probeFile -Value $probe -Encoding UTF8
    try {
        $out = (& python $probeFile 2>&1) -join "`n"
        if ($out -like 'MISSING*') {
            Write-Bad ($out -replace '^MISSING ', 'Python packages missing: ')
            Write-Host '        pip install -r ml\requirements.txt -r ml\api\requirements.txt' -ForegroundColor Gray
            $problems++
        } else {
            Write-Good $out
            if ($out -like '*CPU*') {
                Write-Warn 'Training will be very slow on CPU. See the GPU note in ml\requirements.txt.'
            }
        }
    } finally {
        Remove-Item $probeFile -ErrorAction SilentlyContinue
    }
}

Write-Host ('-' * 40)

if ($Check) {
    Write-Host ''
    if ($problems -eq 0) { Write-Host 'Ready.' -ForegroundColor Green }
    else { Write-Host "$problems problem(s) above." -ForegroundColor Yellow }
    exit $problems
}

if ($problems -gt 0) {
    Write-Host ''
    Write-Warn "Starting anyway with $problems unresolved problem(s)."
}

Write-Host ''
Write-Host 'Starting' -ForegroundColor White
Write-Host ('-' * 40)

# Each service gets its own window with -NoExit, so its log stays readable and
# Ctrl+C in that window stops only that service.
if (Test-Port $AppPort) {
    Write-Warn "Port $AppPort is already in use - assuming Laravel is already running."
} else {
    Write-Step "Laravel        -> http://127.0.0.1:$AppPort"
    Start-Process powershell -ArgumentList @(
        '-NoExit', '-Command',
        "Set-Location '$root'; php artisan serve --port=$AppPort"
    )
}

if (-not $NoOcr) {
    if (Test-Port $OcrPort) {
        Write-Warn "Port $OcrPort is already in use - assuming the OCR service is already running."
    } else {
        Write-Step "OCR service    -> http://127.0.0.1:$OcrPort"
        # 127.0.0.1 only. The service has no authentication of its own; every
        # authorization decision happens in Laravel.
        Start-Process powershell -ArgumentList @(
            '-NoExit', '-Command',
            "Set-Location '$root'; python -m uvicorn ml.api.main:app --host 127.0.0.1 --port $OcrPort"
        )
    }
}

Write-Host ('-' * 40)
Write-Host ''
Write-Host "  Open  http://127.0.0.1:$AppPort" -ForegroundColor White
Write-Host '  Stop  Ctrl+C in each window, or just close it.' -ForegroundColor Gray
Write-Host ''
