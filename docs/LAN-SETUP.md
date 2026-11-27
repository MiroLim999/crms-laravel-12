# Office LAN setup (several PCs, one server)

How to let a few office PCs use CRMS while keeping everyone else out. The steps are done by
[`tools/secure-lan.ps1`](../tools/secure-lan.ps1) on the server PC (the one running Laragon).

## Why

Out of the box, Laragon's Windows firewall rules let **any** device on the network reach Apache
(the website) and MySQL (the database), and MySQL's `root` user has no password. Anyone on the same
Wi-Fi could open the database directly and skip the CRMS login. Over plain `http://`, passwords also
travel over the network as readable text.

## Before you start

1. Give the server PC a fixed IP address: a static IP, or a DHCP reservation on the router.
2. Write down the IP address of every office PC that should use CRMS. Give them fixed addresses too,
   otherwise they lose access when their address changes.

## Run it

On the server PC, open PowerShell **as Administrator** in the CRMS folder:

```powershell
powershell -ExecutionPolicy Bypass -File .\tools\secure-lan.ps1 -ServerIP 192.168.1.10 -AllowedIPs 192.168.1.21,192.168.1.22
```

A range also works: `-AllowedIPs 192.168.1.20-192.168.1.30`. Run it again whenever the list changes;
it replaces the old list.

What it does:

| Step | Result |
| --- | --- |
| Firewall | Laragon's "allow everyone" rules for Apache and MySQL are turned off. One new rule, *CRMS web (office PCs only)*, lets only the listed PCs reach ports 80 and 443. |
| MySQL | `bind-address=127.0.0.1` is added to `my.ini`, so the database only answers this PC. CRMS still works because it runs on this PC. |
| HTTPS | An office certificate authority and a certificate for the server IP and PC name are made in `C:\laragon\etc\crms-lan\`. |
| Apache | `C:\laragon\etc\apache2\sites-enabled\crms-lan.conf` serves CRMS on `https://<server IP>` and redirects `http://` to it. Laragon does not overwrite this file. |

## After running it

1. In Laragon: **Stop All**, then **Start All**.
2. In the CRMS `.env` file:
   ```
   APP_URL=https://192.168.1.10
   SESSION_SECURE_COOKIE=true
   ```
   Then run `php artisan config:clear`.
3. Copy `C:\laragon\etc\crms-lan\crms-office-ca.crt` to each office PC (a USB stick is fine). On
   each one, open Command Prompt as Administrator and run:
   ```
   certutil -addstore -f Root crms-office-ca.crt
   ```
   This makes the browser trust the CRMS certificate, so no warning appears.
4. On a new install, open CRMS **on the server PC first**: the one-time **Set up CRMS** page (your email and
   password) only works there. Then, on the office PCs, open `https://192.168.1.10` and sign in.

## Keep in mind

- `crms-office-ca.key` must stay on the server and stay private: anyone who has it can make
  certificates the office PCs will trust. The script lets only Administrators read it.
- If the server's IP address changes, run the script again with the new IP and `-RenewCertificate`.
  The office PCs keep the same CA certificate, so step 3 is not needed again.
- The server certificate lasts about 2 years (825 days). Renew it the same way before it ends.
- This setup does not set a MySQL password. Set one too, and put it in `DB_PASSWORD` in `.env`.

## Undo

```powershell
powershell -ExecutionPolicy Bypass -File .\tools\secure-lan.ps1 -Undo
```

This turns Laragon's original firewall rules back on, removes the CRMS rule, the `bind-address`
line and the HTTPS site, then restart Laragon. The certificates are kept.
