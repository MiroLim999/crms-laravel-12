<#
.SYNOPSIS
    Locks the CRMS server PC down for use by a few office PCs on the LAN (Laragon on Windows).

.DESCRIPTION
    Run on the PC that hosts CRMS, in PowerShell opened as Administrator. It:
      1. Firewall: turns off Laragon's "allow everyone" rules for Apache and MySQL and adds one
         rule letting only the listed office PCs reach the website (ports 80 and 443).
      2. MySQL: listens on 127.0.0.1 only, so no other device can open the database directly.
      3. HTTPS: makes a small office certificate authority and a certificate for this PC's
         IP address and name, and adds an Apache site that serves CRMS over HTTPS
         (plain http:// is redirected to https://).
    Every step can be reversed with -Undo (the certificates are kept).

.EXAMPLE
    .\tools\secure-lan.ps1 -ServerIP 192.168.1.10 -AllowedIPs 192.168.1.21,192.168.1.22

.EXAMPLE
    .\tools\secure-lan.ps1 -Undo
#>
[CmdletBinding(DefaultParameterSetName = 'Apply')]
param(
    # This server PC's fixed LAN address (set a static IP or a DHCP reservation first).
    [Parameter(Mandatory, ParameterSetName = 'Apply')]
    [string] $ServerIP,

    # The office PCs allowed to open CRMS. Single addresses or ranges like 192.168.1.20-192.168.1.30.
    [Parameter(Mandatory, ParameterSetName = 'Apply')]
    [string[]] $AllowedIPs,

    # The CRMS folder (the one holding "public"). Defaults to the folder this script belongs to.
    [Parameter(ParameterSetName = 'Apply')]
    [string] $AppPath = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path,

    [string] $LaragonPath = 'C:\laragon',

    # Make a new server certificate even if one exists (e.g. after the server IP changed).
    [Parameter(ParameterSetName = 'Apply')]
    [switch] $RenewCertificate,

    [Parameter(Mandatory, ParameterSetName = 'Undo')]
    [switch] $Undo
)

$ErrorActionPreference = 'Stop'

$principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Run this in PowerShell opened as Administrator (right-click > Run as administrator).'
}

$stateDir   = Join-Path $LaragonPath 'etc\crms-lan'
$stateFile  = Join-Path $stateDir 'firewall-state.json'
$vhostFile  = Join-Path $LaragonPath 'etc\apache2\sites-enabled\crms-lan.conf'
$ruleName   = 'CRMS web (office PCs only)'
$marker     = '# crms-lan'

$apacheDir = Get-ChildItem (Join-Path $LaragonPath 'bin\apache') -Directory | Sort-Object Name | Select-Object -Last 1
$mysqlDir  = Get-ChildItem (Join-Path $LaragonPath 'bin\mysql') -Directory | Sort-Object Name | Select-Object -Last 1
if (-not $apacheDir -or -not $mysqlDir) { throw "Apache or MySQL not found under $LaragonPath\bin." }
$myIni   = Join-Path $mysqlDir.FullName 'my.ini'
$openssl = Join-Path $apacheDir.FullName 'bin\openssl.exe'

function Get-LaragonServerRules {
    Get-NetFirewallApplicationFilter |
        Where-Object { $_.Program -match '\\(httpd|mysqld)\.exe$' } |
        Get-NetFirewallRule |
        Where-Object { $_.Direction -eq 'Inbound' -and $_.DisplayName -ne $ruleName }
}

# ---------------------------------------------------------------- Undo
if ($Undo) {
    if (Test-Path $stateFile) {
        $names = Get-Content $stateFile -Raw | ConvertFrom-Json
        foreach ($name in $names) {
            Get-NetFirewallRule -Name $name -ErrorAction SilentlyContinue | Enable-NetFirewallRule
        }
        Remove-Item $stateFile
        Write-Host "Firewall: re-enabled $(@($names).Count) Laragon rule(s)."
    }
    Get-NetFirewallRule -DisplayName $ruleName -ErrorAction SilentlyContinue | Remove-NetFirewallRule
    Write-Host "Firewall: removed '$ruleName'."

    if (Test-Path $myIni) {
        $lines = Get-Content $myIni
        $out = foreach ($line in $lines) {
            if ($line -like "*$marker*" -and $line -notmatch '^#crms-lan# ') { continue }   # lines we added
            if ($line -match '^#crms-lan# (.*)$') { $Matches[1]; continue }               # lines we commented out
            $line
        }
        Set-Content -Path $myIni -Value $out -Encoding ASCII
        Write-Host 'MySQL: bind-address change removed.'
    }

    if (Test-Path $vhostFile) { Remove-Item $vhostFile; Write-Host 'Apache: removed the CRMS HTTPS site.' }

    Write-Host ''
    Write-Host 'Done. In Laragon click "Stop All" then "Start All". Certificates were kept in' $stateDir
    return
}

# ---------------------------------------------------------------- Checks
$parsed = $null
if (-not [System.Net.IPAddress]::TryParse($ServerIP, [ref] $parsed)) { throw "ServerIP '$ServerIP' is not an IP address." }
foreach ($entry in $AllowedIPs) {
    foreach ($part in ($entry -split '-')) {
        if (-not [System.Net.IPAddress]::TryParse($part.Trim(), [ref] $parsed)) { throw "AllowedIPs entry '$entry' is not an IP address or range." }
    }
}
$publicDir = Join-Path $AppPath 'public'
if (-not (Test-Path (Join-Path $publicDir 'index.php'))) { throw "No public\index.php in '$AppPath'. Pass -AppPath with the CRMS folder." }
$localIPs = (Get-NetIPAddress -AddressFamily IPv4).IPAddress
if ($localIPs -notcontains $ServerIP) {
    Write-Warning "$ServerIP is not an address of this PC right now ($($localIPs -join ', ')). Continuing anyway."
}
New-Item -ItemType Directory -Force $stateDir | Out-Null

# ---------------------------------------------------------------- 1. Firewall
$laragonRules = @(Get-LaragonServerRules | Where-Object { $_.Enabled -eq 'True' })
if ($laragonRules.Count) {
    $known = @()
    if (Test-Path $stateFile) { $known = @(Get-Content $stateFile -Raw | ConvertFrom-Json) }
    $all = @($known + ($laragonRules | ForEach-Object { $_.Name })) | Select-Object -Unique
    ConvertTo-Json -InputObject $all | Set-Content $stateFile -Encoding UTF8
    $laragonRules | Disable-NetFirewallRule
}
Get-NetFirewallRule -DisplayName $ruleName -ErrorAction SilentlyContinue | Remove-NetFirewallRule
New-NetFirewallRule -DisplayName $ruleName -Direction Inbound -Action Allow -Protocol TCP `
    -LocalPort 80, 443 -RemoteAddress $AllowedIPs -Profile Any `
    -Program (Join-Path $apacheDir.FullName 'bin\httpd.exe') | Out-Null
Write-Host "Firewall: turned off $($laragonRules.Count) open Apache/MySQL rule(s); website allowed only from $($AllowedIPs -join ', ')."

# ---------------------------------------------------------------- 2. MySQL on 127.0.0.1 only
$lines = Get-Content $myIni
if (-not ($lines -match '^\s*bind-address\s*=\s*127\.0\.0\.1')) {
    $out = New-Object System.Collections.Generic.List[string]
    $section = ''
    foreach ($line in $lines) {
        if ($line -match '^\s*\[(.+)\]') { $section = $Matches[1] }
        if ($section -eq 'mysqld' -and $line -match '^\s*bind-address\s*=') { $out.Add("#crms-lan# $line"); continue }
        $out.Add($line)
        if ($line -match '^\s*\[mysqld\]') { $out.Add("bind-address=127.0.0.1 $marker") }
    }
    Set-Content -Path $myIni -Value $out -Encoding ASCII
}
Write-Host 'MySQL: listens on 127.0.0.1 only (the CRMS website still reaches it).'

# ---------------------------------------------------------------- 3. HTTPS certificate
$caKey = Join-Path $stateDir 'crms-office-ca.key'
$caCrt = Join-Path $stateDir 'crms-office-ca.crt'
$svKey = Join-Path $stateDir 'server.key'
$svCrt = Join-Path $stateDir 'server.crt'
$hostName = $env:COMPUTERNAME.ToLower()

function Invoke-OpenSsl([string[]] $arguments) {
    # openssl prints progress on stderr; Windows PowerShell would treat that as an error.
    $ErrorActionPreference = 'Continue'
    $output = & $openssl @arguments 2>&1 | ForEach-Object { "$_" }
    if ($LASTEXITCODE -ne 0) { throw "openssl failed: $($arguments -join ' ')`n$($output -join "`n")" }
}

if (-not (Test-Path $caCrt)) {
    $caCnf = Join-Path $stateDir 'ca.cnf'
    @(
        '[req]', 'distinguished_name = dn', '[dn]',
        '[v3_ca]', 'basicConstraints = critical,CA:TRUE', 'keyUsage = critical,keyCertSign,cRLSign', 'subjectKeyIdentifier = hash'
    ) | Set-Content $caCnf -Encoding ASCII
    Invoke-OpenSsl @('req', '-x509', '-new', '-nodes', '-newkey', 'rsa:2048', '-sha256', '-days', '3650',
        '-keyout', $caKey, '-out', $caCrt, '-subj', '/CN=CRMS Office CA', '-config', $caCnf, '-extensions', 'v3_ca')
    $RenewCertificate = $true
}

if ($RenewCertificate -or -not (Test-Path $svCrt)) {
    $svCnf = Join-Path $stateDir 'server.cnf'
    $svCsr = Join-Path $stateDir 'server.csr'
    @(
        '[req]', 'distinguished_name = dn', '[dn]',
        '[v3_server]', 'basicConstraints = CA:FALSE', 'keyUsage = critical,digitalSignature,keyEncipherment',
        'extendedKeyUsage = serverAuth', "subjectAltName = IP:$ServerIP, DNS:$hostName"
    ) | Set-Content $svCnf -Encoding ASCII
    Invoke-OpenSsl @('req', '-new', '-nodes', '-newkey', 'rsa:2048', '-keyout', $svKey, '-out', $svCsr,
        '-subj', "/CN=$ServerIP", '-config', $svCnf)
    Invoke-OpenSsl @('x509', '-req', '-in', $svCsr, '-CA', $caCrt, '-CAkey', $caKey, '-CAcreateserial',
        '-out', $svCrt, '-days', '825', '-sha256', '-extfile', $svCnf, '-extensions', 'v3_server')
    Remove-Item $svCsr
}

# Only Administrators and SYSTEM may read the private keys.
foreach ($key in @($caKey, $svKey)) {
    icacls $key /inheritance:r /grant:r 'Administrators:F' 'SYSTEM:F' | Out-Null
}
Write-Host "HTTPS: certificate for https://$ServerIP and https://$hostName ready."

# ---------------------------------------------------------------- 4. Apache site
$web  = $publicDir -replace '\\', '/'
$cert = $svCrt -replace '\\', '/'
$key  = $svKey -replace '\\', '/'
@"
# Written by tools/secure-lan.ps1 - CRMS for office PCs over HTTPS. Remove with: secure-lan.ps1 -Undo
<VirtualHost *:80>
    ServerName $ServerIP
    ServerAlias $hostName
    Redirect permanent / https://$ServerIP/
</VirtualHost>

<VirtualHost *:443>
    ServerName $ServerIP
    ServerAlias $hostName
    DocumentRoot "$web"
    SSLEngine on
    SSLCertificateFile "$cert"
    SSLCertificateKeyFile "$key"
    Header always set Strict-Transport-Security "max-age=31536000"
    <Directory "$web">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
"@ | Set-Content $vhostFile -Encoding ASCII

& (Join-Path $apacheDir.FullName 'bin\httpd.exe') -d $apacheDir.FullName -t
if ($LASTEXITCODE -ne 0) { throw "Apache rejected $vhostFile. Fix it or run -Undo." }

# ---------------------------------------------------------------- Next steps
Write-Host ''
Write-Host 'Done. Next:' -ForegroundColor Green
Write-Host '  1. In Laragon click "Stop All" then "Start All".'
Write-Host "  2. In $AppPath\.env set:"
Write-Host "       APP_URL=https://$ServerIP"
Write-Host '       SESSION_SECURE_COOKIE=true'
Write-Host '     then run: php artisan config:clear'
Write-Host "  3. Copy $caCrt to each office PC (USB is fine) and run there, as Administrator:"
Write-Host '       certutil -addstore -f Root crms-office-ca.crt'
Write-Host "  4. On the office PCs open https://$ServerIP"
Write-Host ''
Write-Host "Keep $caKey private - anyone with it can make certificates the office PCs will trust."
