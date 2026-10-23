# Running CRMS with Docker

Docker runs CRMS as five "boxes" (containers) on one computer: the website, the worker that finds and reads the handwritten lines, the scheduler, the handwriting reader (TrOCR) and the database. The same files work on **Windows 10 and 11, Linux, and Macs with Apple silicon**.

- Everything stays **on this computer**: the records, the scans and the models.
- Only **this computer** can open CRMS. Phones and other computers on the same Wi-Fi cannot.
- No graphics card is needed. Everything runs on the processor, so one page takes about 2 minutes.

**Contents:** [What you need](#what-you-need) · [First-time setup](#first-time-setup) · [Every day](#every-day) · [Back up the records](#back-up-the-records) · [Update to a newer version](#update-to-a-newer-version) · [If something goes wrong](#if-something-goes-wrong)

---

## What you need

| | Windows 10 or 11 | Mac (Apple silicon: M1 to M4) | Linux |
|---|---|---|---|
| **Docker** | [Docker Desktop](https://www.docker.com/products/docker-desktop/) | [Docker Desktop](https://www.docker.com/products/docker-desktop/), the **Apple silicon** download | [Docker Engine](https://docs.docker.com/engine/install/) with the Compose plugin |
| **Check** | Windows 10 version **22H2** or newer (press Win+R, type `winver`). **Virtualization** must be on in the BIOS. | Docker Desktop → **Settings → Resources → Memory** is at least **8 GB**. | Your user can run `docker` without `sudo` (see [If something goes wrong](#if-something-goes-wrong)). |
| **Git** | [Git for Windows](https://git-scm.com/download/win) | Type `git` in Terminal. The Mac offers to install it. | Usually there already. If not: `sudo apt install git` |

You also need:

- **About 20 GB free** on the disk.
- **Good internet the first time.** Docker downloads several GB.
- **The model zip files**, from Drive or USB. They are not on GitHub.
  - `TrOcr-50k-broken-samples.zip` (1.3 GB): **required**. This is the fine-tuned model.
  - `base.zip` (1.3 GB): recommended. The original model, for comparison.

> Docker Desktop is free for personal use, education and small businesses. Larger companies and government offices may need a paid Docker subscription. Docker Engine on Linux is free.

---

## First-time setup

Do this once on each computer. Type the commands in **PowerShell** on Windows (Start menu → type `PowerShell`), or in **Terminal** on Mac and Linux. Press Enter after each one.

### 1. Get the project

```
git clone -b docker-setup https://github.com/MiroLim999/crms-laravel-12.git
cd crms-laravel-12
```

This makes a folder called `crms-laravel-12`. Run every command after this one from inside that folder.

### 2. Make the settings file

Windows (PowerShell):

```powershell
Copy-Item .env.example .env
```

Mac and Linux:

```bash
cp .env.example .env
```

`.env` holds this computer's settings and its secret key. It never goes to GitHub.

### 3. Put the models in

First make the models folder (the same command works everywhere):

```
mkdir ml/models
```

Then unzip each model zip into it:

- **Windows:** right-click the zip → **Extract All...** → **Browse...** → choose the `crms-laravel-12\ml\models` folder → **Extract**.
- **Mac:** double-click the zip, then drag the folder it makes into `crms-laravel-12/ml/models`.
- **Linux:** `unzip TrOcr-50k-broken-samples.zip -d ml/models` (and the same for `base.zip`)

**Check:** the file `ml/models/TrOcr-50k-broken-samples/config.json` must exist. If there is an extra folder level (`ml/models/TrOcr-50k-broken-samples/TrOcr-50k-broken-samples/config.json`), move the inner folder up one level.

### 4. Build and start CRMS

```
docker compose up -d --build
```

The first time takes a while (15 to 45 minutes, depending on the internet): Docker downloads Linux, PHP, Python and the line finder, and builds the boxes. Later starts take seconds.

It is done when the prompt comes back. Check that all five boxes are running:

```
docker compose ps
```

You should see `crms-web-1`, `crms-worker-1`, `crms-scheduler-1`, `crms-ocr-1` and `crms-mysql-1`. `crms-ocr-1` and `crms-mysql-1` should say `Up` and `(healthy)`. The reader can take a minute to get there.

`crms-worker-1` (and sometimes `crms-scheduler-1`) may say **`Restarting`** for now. That is normal: the database has no tables yet. Step 6 creates them, and then they stay `Up`.

### 5. Create the secret key

```
docker compose exec web php artisan key:generate
```

You should see `Application key set successfully.` The key is saved in `.env`.

### 6. Create the tables and the first account

```
docker compose exec web php artisan migrate --seed
```

You should see a list of tables, each marked `DONE`, and then `Super Admin ready: superadmin@admin.com`.

Then restart the boxes, so that all of them use the new key:

```
docker compose restart
```

### 7. Open CRMS and change the password

1. Open **http://localhost:8080** in the browser.
2. Log in with the email **superadmin@admin.com** and the password **superadmin@admin.com**.
3. Change the password right away. The default one is written here and in the public code, so everyone knows it. Click the round badge with your initials at the top right → **Account Settings**.

### 8. Choose the model

1. In the left menu, under **System**, click **OCR Workspace**.
2. In the **Scanning policy** box (it says **Action needed**), set **Approved model** to **TrOcr-50k-broken-samples**.
3. Click **Save policy**. The model now shows **Active**.

If the list says **OCR service offline**, wait a minute and reload the page. The reader takes a moment to start.

CRMS is ready. Make the accounts for the staff under **User Accounts**.

---

## Every day

Run these commands in the project folder (`cd crms-laravel-12` first).

| To | Do this |
|---|---|
| **Start CRMS** | Start Docker Desktop (Windows and Mac). CRMS starts with it if it was running when the computer shut down. Otherwise run `docker compose up -d`. |
| **Open CRMS** | http://localhost:8080 |
| **Stop CRMS** | `docker compose down`. The records, scans and models stay. |
| **See what is running** | `docker compose ps` |
| **Watch the worker's messages** | `docker compose logs -f worker`. Ctrl+C stops watching, not the worker. |

### What is kept where

| Where | What |
|---|---|
| `.env` | this computer's settings and secret key |
| `docker-data/storage` | the scans, line pictures and logs |
| `docker-data/backups` | the database backups you make (below) |
| `ml/models` | the models |
| Docker volume `crms_mysql-data` | the database itself |

---

## Back up the records

The records are the database and the scans. Both end up in the `docker-data` folder.

1. Save the database to `docker-data/backups/crms-backup.sql`:

   ```
   docker compose exec mysql sh -c 'mysqldump -uroot -p$MYSQL_ROOT_PASSWORD --single-transaction --set-gtid-purged=OFF crms > /backups/crms-backup.sql'
   ```

   A warning about using a password on the command line is normal.

2. Copy the whole **`docker-data`** folder to a USB drive. It holds the backup and the scans.

**To restore:** copy the `docker-data` folder from the USB drive back into the project folder, start CRMS, then run:

```
docker compose exec mysql sh -c 'mysql -uroot -p$MYSQL_ROOT_PASSWORD crms < /backups/crms-backup.sql'
```

---

## Update to a newer version

```
git pull
docker compose up -d --build
docker compose exec web php artisan migrate
```

---

## If something goes wrong

| You see | Do this |
|---|---|
| `port is already allocated` or `address already in use` | Another program is using 8080 or 8081. Close it, or change the number in `docker-compose.yml`: 8080 under `web`, or 8081 under `ocr` **and** in `OCR_BROWSER_API_URL`. |
| `bind source path does not exist` with `.env` in it | Step 2 was skipped. Make `.env`, then start again. |
| A scan waits forever | The worker is not running. Run `docker compose ps`, then `docker compose logs worker`. |
| OCR Workspace says **OCR service offline** | The reader is still starting. Wait a minute and reload. Still offline? Run `docker compose logs ocr`. |
| Windows: Docker Desktop says WSL or virtualization is missing | Turn virtualization on in the BIOS, then restart. Windows 10 needs version 22H2 or newer. |
| Mac: scans stop halfway, or a box keeps restarting | Give Docker more memory: Docker Desktop → **Settings → Resources → Memory**, at least 8 GB. |
| Linux: `permission denied` with `docker.sock` in it | Run `sudo usermod -aG docker $USER`, then log out and back in. |

---

## Never do this on a computer with real records

```
docker compose down -v
```

The `-v` **deletes the database**. `docker compose down` without `-v` is safe.
