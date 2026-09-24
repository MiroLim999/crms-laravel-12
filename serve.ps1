<#
    serve.ps1
    Starts the processes CRMS needs, each in its own window, from the repo root:
    Laravel, the queue worker that outlines and reads aligned pages, and the
    OCR service.

        .\serve.ps1            start all three
        .\serve.ps1 -Check     verify the environment and exit
        .\serve.ps1 -NoOcr     Laravel and the queue worker only
        .\serve.ps1 -Only web|worker|ocr
                               run one of them in this terminal (Kiro's tasks
                               in .vscode\tasks.json do this, one tab each)

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
    [ValidateSet('web', 'worker', 'ocr')]
    [string]$Only,
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

# A queue worker, or the loop that keeps one running (in its 5 s pause there is
# no php process), other than this script itself.
function Test-QueueWorker {
    $null -ne (Get-CimInstance Win32_Process -Filter "Name='php.exe' OR Name='powershell.exe' OR Name='pwsh.exe'" -ErrorAction SilentlyContinue |
        Where-Object { $_.ProcessId -ne $PID -and ($_.CommandLine -like '*artisan queue:work*' -or $_.CommandLine -like '*serve.ps1*-Only worker*') })
}

# --- One service in this terminal ----------------------------------------
# Each skips itself when that service is already running, so opening Kiro while
# serve.ps1's windows are still up does not start anything twice.
if ($Only) {
    Set-Location $root
    $ErrorActionPreference = 'Continue'
    switch ($Only) {
        'web' {
            if (Test-Port $AppPort) { Write-Warn "Port $AppPort is already in use - Laravel is already running."; exit 0 }
            # The warning about PHP_CLI_SERVER_WORKERS is harmless: PHP cannot
            # run several server workers on Windows ("forking is not supported
            # on this platform"), with or without --no-reload.
            php artisan serve --port=$AppPort
        }
        'worker' {
            if (Test-QueueWorker) { Write-Warn 'A queue worker is already running - not starting another.'; exit 0 }
            # Finishing Align, and Detect, queue a job that outlines every
            # handwritten line and reads it; without a worker, pages wait in
            # "queued" forever. The loop brings it back after
            # `php artisan queue:restart` (it exits so that new code loads),
            # after a crash, and once MySQL is up if it started before MySQL.
            $host.UI.RawUI.WindowTitle = 'CRMS queue worker'
            while ($true) {
                php artisan queue:work --timeout=900 --tries=1
                Write-Host 'Worker stopped. Starting again in 5 s - close this terminal to stop it.' -ForegroundColor Yellow
                Start-Sleep -Seconds 5
            }
        }
        'ocr' {
            if (Test-Port $OcrPort) { Write-Warn "Port $OcrPort is already in use - the OCR service is already running."; exit 0 }
            # 127.0.0.1 only. The service has no authentication of its own;
            # every authorization decision happens in Laravel.
            python -m uvicorn ml.api.main:app --host 127.0.0.1 --port $OcrPort
        }
    }
    exit $LASTEXITCODE
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

# --- Line detection (Kraken, its own environment) ------------------------
# Aligned pages are outlined line by line by ml\line_markers.py in
# ml\.venv-kraken. Without it, ledger templates cannot be scanned (older
# rectangle templates still work).
$krakenPython = Join-Path $root 'ml\.venv-kraken\Scripts\python.exe'
if (Test-Path $krakenPython) {
    $kraken = (& $krakenPython -c "from importlib.metadata import version; print('kraken ' + version('kraken'))" 2>$null)
    if ($LASTEXITCODE -eq 0) { Write-Good "$kraken (ml\.venv-kraken)" }
    else { Write-Warn 'ml\.venv-kraken exists but kraken does not import. Re-run ml\setup_kraken.ps1.'; $problems++ }
} else {
    Write-Warn 'Kraken environment missing. Run .\ml\setup_kraken.ps1 to scan ledger templates.'
    $problems++
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

# Each service gets its own window, running this script with -Only, so its log
# stays readable and Ctrl+C in that window stops only that service. In Kiro,
# .vscode	asks.json runs the same three commands as terminal tabs instead.
function Start-ServiceWindow([string]$Service) {
    Start-Process powershell -ArgumentList @(
        '-NoExit', '-NoProfile', '-ExecutionPolicy', 'Bypass',
        '-File', "`"$PSCommandPath`"", '-Only', $Service, '-AppPort', $AppPort, '-OcrPort', $OcrPort
    )
}

if (Test-Port $AppPort) {
    Write-Warn "Port $AppPort is already in use - assuming Laravel is already running."
} else {
    Write-Step "Laravel        -> http://127.0.0.1:$AppPort"
    Start-ServiceWindow 'web'
}

if (Test-QueueWorker) {
    Write-Warn 'A queue worker is already running - not starting another.'
} else {
    Write-Step 'Queue worker   -> line detection and reading (restarts itself)'
    Start-ServiceWindow 'worker'
}

if (-not $NoOcr) {
    if (Test-Port $OcrPort) {
        Write-Warn "Port $OcrPort is already in use - assuming the OCR service is already running."
    } else {
        Write-Step "OCR service    -> http://127.0.0.1:$OcrPort"
        Start-ServiceWindow 'ocr'
    }
}

Write-Host ('-' * 40)
Write-Host ''
Write-Host "  Open  http://127.0.0.1:$AppPort" -ForegroundColor White
Write-Host '  Stop  Ctrl+C in each window, or just close it.' -ForegroundColor Gray
Write-Host ''
