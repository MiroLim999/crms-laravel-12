# Docker Guide

Extra Docker help for CRMS. **The installation steps are in the [README](../README.md#installation-docker).** This page covers what to prepare first, and running, updating, backing up and fixing CRMS afterwards.

**Contents:** [Prepare the computer](#prepare-the-computer) · [Every day](#every-day) · [Updates](#updates) · [NVIDIA GPU](#nvidia-gpu) · [Database security](#database-security) · [Backup](#backup-and-restore) · [Troubleshooting](#troubleshooting)

---

## Prepare the computer

Do this before the README installation. You need about **20 GB free disk** and good internet for the first run.

| System | Do this |
| :--- | :--- |
| **Windows** | Windows must be **22H2 or newer** (`Win + R` → `winver`). Virtualization must be **Enabled** (Task Manager → Performance → CPU); if not, turn it on in the BIOS. Install [Git](https://git-scm.com/download/win) and [Docker Desktop](https://www.docker.com/products/docker-desktop/), restart, open Docker Desktop. If asked, run `wsl --update`. |
| **Mac** | Install Git (run `git --version` in Terminal and accept the prompt) and [Docker Desktop](https://www.docker.com/products/docker-desktop/) (Apple Silicon). In Docker: **Settings → Resources → Memory → 8 GB or more**. |
| **Linux** | Install Git, then Docker Desktop from yay: `yay -S docker-desktop`. Open Docker Desktop (or run `systemctl --user start docker-desktop`). |

Wait until Docker says **Engine running**, then follow the [README installation](../README.md#installation-docker).

Good to know:

- Before `migrate --seed` runs, the worker may show `Restarting`. That is normal.
- **Linux, upgrading from an older CRMS:** its containers ran as root, so files they wrote may belong to root. `docker-start.sh` says so; hand them over with `sudo chown -R 1000:1000 docker-data/storage ml/models`.
- After an update, run `docker compose run --rm migrate`: it adds what two-factor sign-in needs. The next sign-in then asks to set up the authenticator app.
- The first visit opens **Set up CRMS**, which asks for a **setup token**. See it with `docker compose exec web php artisan crms:setup-token`. It stops working once the account is made.
- `docker compose ps` should show `crms-ocr-1` and `crms-mysql-1` as `Up (healthy)`.
- Docker Desktop is free for personal use, education and small businesses. Larger companies may need a paid subscription.

---

## Every day

Run in the project folder (`C:\crms-laravel-12` on Windows, `~/crms-laravel-12` on Mac/Linux).

```bash
docker compose up -d                 # start
docker compose down                  # stop (records are kept)
docker compose ps                    # what is running
docker compose logs -f worker        # worker messages (Ctrl+C to stop watching)
```

| Where | What is kept there |
| :--- | :--- |
| `.env` | settings and secret key |
| `docker-data/storage` | scans, line pictures, logs |
| `docker-data/backups` | encrypted database backups |
| `docker-data/secrets` | database passwords and the backup passphrase (made by the start script; keep a copy somewhere safe, **not** with the backups) |
| `ml/models` | models (empty on a new computer: CRMS downloads none by itself; add them from your USB with **OCR Workspace → Add model**) |
| Docker volume `crms_mysql-data` | the database (encrypted) |
| Docker volume `crms_mysql-keyring` | the database's encryption key |

---

## Updates

Docker builds whatever is in the project folder, so first make sure the folder has the version you want.

1. **Go to the project folder.** A new terminal, including the one inside Docker Desktop, opens in your user folder (`C:\Users\yourname`), where git says `fatal: not a git repository`. Move into the project first:

   Windows (use your own project folder):

   ```powershell
   cd C:\crms-laravel-12
   ```

   Mac / Linux:

   ```bash
   cd ~/crms-laravel-12
   ```

2. **Check the branch.** Docker has no branch of its own; it uses the one checked out here.

   ```bash
   git branch --show-current          # should print: main
   git status                         # should say: working tree clean
   git pull                           # only if the new version is on GitHub and not yet on this computer
   ```

3. **Rebuild and start**, then update the database.

   Windows:

   ```powershell
   powershell -ExecutionPolicy Bypass -File .\docker-start.ps1
   docker compose run --rm migrate
   ```

   Mac / Linux:

   ```bash
   sh docker-start.sh
   docker compose run --rm migrate
   ```

Docker Desktop only has to show **Engine running**; the start script builds and starts the containers itself, so there is no need to start them first. Records and models are not touched.

The rebuild also gives the **worker** the new code. If you ever change the code without rebuilding (or a scan behaves like the old version), restart it: `docker compose restart worker`.

> **Updating past 15 November 2026:** `migrate` deletes the old correction requests (the `change_requests` tables), because saved records are now edited in **Review & Export**. The records and their current values stay, and the Audit Log keeps every old correction. Make a [backup](#backup-and-restore) first if you might need those requests as they were.

---

## NVIDIA GPU

Nothing to do: the start script uses an NVIDIA card automatically if the computer has one that works. Macs, AMD, Intel graphics and PCs without a card use the processor.

- Measured on an RTX 4050: 120 lines took **38 s on the processor, 4 s on the card**. A page takes about 35 s instead of 2 minutes.
- Needs an up-to-date NVIDIA driver (tested with 617.14) and about **9 GB more disk**. On Linux, Docker Desktop may not pass the NVIDIA card through; check `/health` and expect the processor if it says `cpu`.
- **Check it:** open http://127.0.0.1:8081/health. `"device":"cuda"` means the card is used, `"device":"cpu"` means it is not.
- If the card fails, the reader falls back to the processor by itself.
- **Force the processor:** add `-Cpu` (Windows) or `--cpu` (Mac/Linux) to the start script, or set `OCR_DEVICE=cpu` in `.env`.
- **Without the start script**, use: `docker compose -f docker-compose.yml -f docker-compose.gpu.yml up -d --build`. A plain `docker compose up -d` puts the reader back on the processor.

---

## Database security

The start script sets all of this up by itself, and checks it again on every start (`crms-secure-database`). Nothing to do by hand.

| Protection | What it means |
| :--- | :--- |
| **Encrypted on disk** | Every table, the redo and undo logs and the binary log are encrypted (AES-256). Someone who copies the `crms_mysql-data` volume, or takes the disk, cannot read the records without the key. |
| **Key kept apart** | The key is in its own volume, `crms_mysql-keyring`, not with the data. |
| **No built-in passwords** | The root, owner and app passwords are 64 random characters each, made on first start in `docker-data/secrets`. They are not in `.env`, the images or `docker inspect`. |
| **Least privilege** | The website, worker and scheduler sign in as `crms_app`: read and write rows, nothing else. They cannot create, change or drop tables, read files, or give rights to anyone. Only `docker compose run --rm migrate` signs in as the owner (`crms`). |
| **Append-only audit log** | MySQL itself refuses every change or deletion of an Audit Log entry, even through a direct database connection. |
| **No port** | The database has no port on the computer: only the other containers can reach it. |
| **Encrypted backups** | See below. |
| **Production mode** | The app containers run with `APP_ENV=production` and `APP_DEBUG=false` whatever `.env` says, so an error never shows stack traces or settings. They read `.env` read-only. |
| **OCR key of its own** | Laravel and the handwriting reader share `docker-data/secrets/ocr_upload_secret`, made by `docker-start`. The reader never sees `.env` or `APP_KEY`. Model uploads must bring `model.safetensors`: `pytorch_model.bin` can run code when loaded, so it is refused. |
| **No root in the containers** | The website, worker, scheduler and handwriting reader run as an ordinary user (`crms`), with every Linux capability dropped and `no-new-privileges`. The code in the image belongs to root, so they can read it but never change it; they write only scans, logs and models. |
| **No test tools in the running image** | The image the website runs has no PHPUnit, Faker or Composer. The tests have their own image: `docker compose run --rm --build test`. |
| **Memory and process limits** | A runaway page or upload stops its own container, not the computer: website 2 GB, worker 6 GB, reader 6 GB, scheduler 512 MB. Change them with `CRMS_WEB_MEMORY`, `CRMS_WORKER_MEMORY` and `CRMS_OCR_MEMORY` in `.env` (e.g. `8g`). |
| **Host names** | The site answers only to `localhost`, `127.0.0.1` and `APP_URL`'s host. To open it by another name or address, add it to `CRMS_TRUSTED_HOSTS` in `.env` (comma-separated). |

**Check it:**

```bash
docker compose exec mysql crms-secure-database
```

It ends with `done. N crms table(s) encrypted`, or stops with an error if anything is not encrypted.

> **Keep `docker-data/secrets` safe.** Copy it to a password manager or a separate USB stick kept apart from the backups. Without `backup_passphrase` no backup can be opened, by anyone, including you.

---

## Backup and restore

**Backup** (encrypted with the passphrase in `docker-data/secrets/backup_passphrase`):

```bash
docker compose exec mysql crms-backup
```

This writes `docker-data/backups/crms-<date>_<time>.sql.gz.enc` and a `.sha256` file beside it. Copy **both** to a USB drive. Copy `docker-data/storage` too (the scans). Keep the passphrase (`docker-data/secrets`) somewhere **else**: a USB holding both is as good as no encryption.

**Restore:** put the `.sql.gz.enc` and `.sha256` files in `docker-data/backups` and the same `backup_passphrase` in `docker-data/secrets`, start CRMS, then run (with your file's name):

```bash
docker compose exec mysql crms-restore crms-2026-11-27_220600.sql.gz.enc
```

It refuses a backup that was changed or damaged since it was made. Every table in the backup replaces the one in the database, so back up the current state first.

---

## Troubleshooting

| You see | Do this |
| :--- | :--- |
| `port is already allocated` / `address already in use` | Another program uses 8080 or 8081. Close it, or change the port in `docker-compose.yml` (8080 under `web`; 8081 under `ocr` **and** in `OCR_BROWSER_API_URL`). |
| `fatal: not a git repository` (or `docker compose` says it found no configuration file) | The terminal is not in the project folder. Run `cd` to the project folder (see [Updates](#updates)). |
| `bind source path does not exist` (mentions `.env`) | `.env` is missing. Create it (step 2), then start again. |
| `secret ... not found` / `docker-data/secrets/... no such file` | You started with `docker compose up` before the start script ever ran. Run the start script once: it makes the secrets. |
| `Access denied for user 'crms_app'` | The database was not secured yet. Run `docker compose exec mysql crms-secure-database`. |
| `CREATE command denied to user 'crms_app'` | A migration was run in the `web` container. Use `docker compose run --rm migrate` instead. |
| A scan waits forever | Worker is not running. Run `docker compose ps` and `docker compose logs worker`. |
| **OCR service offline** | Reader is still starting. Wait a minute and reload. Still offline: `docker compose logs ocr`. |
| Windows: WSL or virtualization missing | Enable virtualization in the BIOS, restart, run `wsl --update`. |
| Mac: scans stop halfway or a container keeps restarting | Docker → **Settings → Resources → Memory**: 8 GB or more. |

---

## Never run this on a computer with real records

```bash
docker compose down -v
```

The `-v` **deletes the database**. `docker compose down` without `-v` is safe.
