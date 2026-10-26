# Docker Guide

Extra Docker help for CRMS. **The installation steps are in the [README](../README.md#installation-docker).** This page covers what to prepare first, and running, updating, backing up and fixing CRMS afterwards.

**Contents:** [Prepare the computer](#prepare-the-computer) · [Every day](#every-day) · [Updates](#updates) · [NVIDIA GPU](#nvidia-gpu) · [Backup](#backup-and-restore) · [Troubleshooting](#troubleshooting)

---

## Prepare the computer

Do this before the README installation. You need about **20 GB free disk** and good internet for the first run.

| System | Do this |
| :--- | :--- |
| **Windows** | Windows must be **22H2 or newer** (`Win + R` → `winver`). Virtualization must be **Enabled** (Task Manager → Performance → CPU); if not, turn it on in the BIOS. Install [Git](https://git-scm.com/download/win) and [Docker Desktop](https://www.docker.com/products/docker-desktop/), restart, open Docker Desktop. If asked, run `wsl --update`. |
| **Mac** | Install Git (run `git --version` in Terminal and accept the prompt) and [Docker Desktop](https://www.docker.com/products/docker-desktop/) (Apple Silicon). In Docker: **Settings → Resources → Memory → 8 GB or more**. |
| **Linux** | Install Git, then Docker Desktop from the AUR: `yay -S docker-desktop`. Open Docker Desktop (or run `systemctl --user start docker-desktop`). |

Wait until Docker says **Engine running**, then follow the [README installation](../README.md#installation-docker).

Good to know:

- Before `migrate --seed` runs, the worker may show `Restarting`. That is normal.
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
| `docker-data/backups` | database backups |
| `ml/models` | models |
| Docker volume `crms_mysql-data` | the database |

---

## Updates

```bash
git pull
sh docker-start.sh        # Windows: powershell -ExecutionPolicy Bypass -File .\docker-start.ps1
docker compose exec web php artisan migrate
```

Records and models are not touched.

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

## Backup and restore

**Backup:**

```bash
docker compose exec mysql sh -c 'mysqldump -uroot -p$MYSQL_ROOT_PASSWORD --single-transaction --set-gtid-purged=OFF crms > /backups/crms-backup.sql'
```

Then copy the whole **`docker-data`** folder to a USB drive. (A password warning is normal.)

**Restore:** copy `docker-data` back into the project folder, start CRMS, then run:

```bash
docker compose exec mysql sh -c 'mysql -uroot -p$MYSQL_ROOT_PASSWORD crms < /backups/crms-backup.sql'
```

---

## Troubleshooting

| You see | Do this |
| :--- | :--- |
| `port is already allocated` / `address already in use` | Another program uses 8080 or 8081. Close it, or change the port in `docker-compose.yml` (8080 under `web`; 8081 under `ocr` **and** in `OCR_BROWSER_API_URL`). |
| `bind source path does not exist` (mentions `.env`) | `.env` is missing. Create it (step 2), then start again. |
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
