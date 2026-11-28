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

# The database passwords and the backup passphrase: Docker secrets, made once
# here (64 random letters and digits each) and kept in docker-data\secrets.
# Never shown, never in .env. Keep a copy of that folder somewhere safe.
function New-CrmsSecrets {
    $dir = Join-Path $PSScriptRoot 'docker-data\secrets'
    New-Item -ItemType Directory -Force $dir | Out-Null
    foreach ($name in 'db_root_password', 'db_owner_password', 'db_app_password', 'backup_passphrase', 'ocr_upload_secret') {
        $file = Join-Path $dir $name
        # "docker compose up" before this script makes an empty folder where a
        # missing secret file should be; the containers then cannot start.
        if (Test-Path $file -PathType Container) {
            if (Get-ChildItem -Force $file) { throw "$file is a folder, not a file: remove it and run this again." }
            Remove-Item $file
        }
        if (-not (Test-Path $file) -or (Get-Item $file).Length -eq 0) {
            $bytes = New-Object byte[] 32
            [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
            $hex = -join ($bytes | ForEach-Object { $_.ToString('x2') })
            # No newline and no byte-order mark: MySQL reads the file as it is.
            [System.IO.File]::WriteAllText($file, $hex, (New-Object System.Text.UTF8Encoding $false))
            Write-Host "Made a new secret: docker-data\secrets\$name"
        }
    }
    # Only this Windows account (and administrators) may open the folder.
    $null = & icacls $dir /inheritance:r /grant:r "${env:USERNAME}:(OI)(CI)F" /grant:r 'SYSTEM:(OI)(CI)F' /grant:r 'Administrators:(OI)(CI)F' 2>&1
}
New-CrmsSecrets

# Laravel's APP_KEY, written into .env here the first time. The containers can
# only read .env (docker-compose.yml), so php artisan key:generate cannot do it.
$envFile = Join-Path $PSScriptRoot '.env'
if (-not (Test-Path $envFile)) {
    Write-Host 'There is no .env. Copy it first: Copy-Item .env.example .env'
    exit 1
}
$envText = [System.IO.File]::ReadAllText($envFile)
if ($envText -notmatch '(?m)^APP_KEY=[ \t]*\S') {
    $bytes = New-Object byte[] 32
    [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    $line = 'APP_KEY=base64:' + [Convert]::ToBase64String($bytes)
    if ($envText -match '(?m)^APP_KEY=') {
        $envText = [regex]::Replace($envText, '(?m)^APP_KEY=[^\r\n]*', $line.Replace('$', '$$'))
    } else {
        $envText = $envText.TrimEnd("`r", "`n") + "`r`n" + $line + "`r`n"
    }
    # No byte-order mark: Laravel and Docker read .env as plain UTF-8.
    [System.IO.File]::WriteAllText($envFile, $envText, (New-Object System.Text.UTF8Encoding $false))
    Write-Host 'Made a new APP_KEY in .env'
}

# The folders the containers write into, made here before Docker starts, so
# Docker never has to make them itself.
foreach ($folder in 'docker-data\storage\app', 'docker-data\storage\logs', 'docker-data\backups', 'ml\models') {
    New-Item -ItemType Directory -Force (Join-Path $PSScriptRoot $folder) | Out-Null
}

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

# Accounts, grants and encryption; also upgrades a database made before them.
Write-Host 'Securing the database...'
& docker compose exec -T mysql crms-secure-database
if ($LASTEXITCODE -ne 0) {
    Write-Host 'Could not secure the database. See the messages above; CRMS is not safe to use yet.' -ForegroundColor Red
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
