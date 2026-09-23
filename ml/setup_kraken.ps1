<#
    setup_kraken.ps1
    Builds the separate Python environment that runs Kraken line detection.

        .\ml\setup_kraken.ps1

    Why a second environment: Kraken 7 needs torch >= 2.9, while the TrOCR
    service in .venv runs torch 2.6 + CUDA 12.4. Installing Kraken into .venv
    would replace that torch and break recognition. line_markers.py runs here
    instead, and Laravel finds it at ml\.venv-kraken (override with
    LINE_MARKERS_PYTHON in .env).

    Needs `uv` on PATH (pip install uv). Downloads packages only; no page image is
    ever sent anywhere.
#>

[CmdletBinding()]
param(
    [string]$Python = '3.13'
)

# Not 'Stop': uv reports progress on stderr, which Windows PowerShell 5.1 turns
# into terminating errors. Every native call below checks $LASTEXITCODE instead.
$ErrorActionPreference = 'Continue'
$root = Split-Path -Parent $PSScriptRoot
$venv = Join-Path $root 'ml\.venv-kraken'
$requirements = Join-Path $root 'ml\requirements-kraken.txt'

if (-not (Get-Command uv -ErrorAction SilentlyContinue)) {
    throw 'uv was not found on PATH. Install it with: pip install uv'
}

if (-not (Test-Path (Join-Path $venv 'Scripts\python.exe'))) {
    Write-Host "Creating $venv" -ForegroundColor Cyan
    uv venv $venv --python $Python
    if ($LASTEXITCODE -ne 0) { throw 'uv venv failed.' }
}

# torch first, from the CPU index, so kraken's dependency on it is already met
# and uv never pulls a CUDA build from PyPI.
Write-Host 'Installing CPU torch' -ForegroundColor Cyan
uv pip install --python $venv 'torch>=2.9,<=2.14' torchvision --index-url https://download.pytorch.org/whl/cpu
if ($LASTEXITCODE -ne 0) { throw 'torch install failed.' }

Write-Host 'Installing kraken' -ForegroundColor Cyan
uv pip install --python $venv -r $requirements
if ($LASTEXITCODE -ne 0) { throw 'kraken install failed.' }

& (Join-Path $venv 'Scripts\python.exe') -c "import kraken, torch; from importlib.metadata import version; print('kraken', version('kraken'), '| torch', torch.__version__)"
if ($LASTEXITCODE -ne 0) { throw 'kraken import check failed.' }

Write-Host 'Kraken environment ready.' -ForegroundColor Green
