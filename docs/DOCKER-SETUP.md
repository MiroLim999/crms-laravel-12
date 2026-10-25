# Running CRMS with Docker

Docker runs CRMS as five "boxes" (containers) on one computer: the website, the worker that finds and reads the handwritten lines, the scheduler, the handwriting reader (TrOCR) and the database. The same files work on **Windows 10 and 11, Macs with Apple silicon (M1 to M4), and Linux**. CRMS opens at **http://localhost:8080**.

- Everything stays **on this computer**: the records, the scans and the models.
- Only **this computer** can open CRMS. Phones and other computers on the same Wi-Fi cannot.
- No graphics card is needed. Everything runs on the processor, so one page takes about 2 minutes.

**Contents:** [Before you go](#1-before-you-go) · [Windows](#2a-windows-pc) · [Mac](#2b-mac-apple-silicon) · [Linux](#2c-linux) · [Start CRMS](#3-start-crms) · [First login](#4-first-login) · [Every day](#every-day) · [Get updates](#get-updates) · [NVIDIA graphics card](#nvidia-graphics-card) · [Back up the records](#back-up-the-records) · [If something goes wrong](#if-something-goes-wrong)

---

## 1. Before you go

The models are not on GitHub, so bring them yourself. Copy one of these model folders from your laptop to a USB stick:

`C:\xampp\htdocs\crms-laravel-12\ml\models\TrOcr-50k-broken-samples`

For a quick installation, the original TrOCR base model can also be downloaded into `ml\models\base` by running `python ml\download_trocr.py` from the project folder. The base model works with CRMS, but the fine-tuned `TrOcr-50k-broken-samples` model is recommended for better handwriting accuracy.

(Or bring the zip from Drive: `TrOcr-50k-broken-samples.zip`, 1.3 GB. `base.zip`, the original model for comparison, is optional.)

The other computer also needs:

- **About 20 GB free** on the disk.
- **Good internet the first time.** Docker downloads several GB.

> Docker Desktop is free for personal use, education and small businesses. Larger companies and government offices may need a paid Docker subscription. Docker Engine on Linux is free.

---

## 2A. Windows PC

**Check the PC**

1. Press **Win + R**, type `winver`, press Enter. It must say **22H2** or newer.
2. Press **Ctrl + Shift + Esc** → **Performance** → **CPU**. It must say **Virtualization: Enabled**.
   If not, turn on **Intel Virtualization Technology** (or **SVM** on AMD) in the BIOS.

**Install two programs**

1. **Git:** https://git-scm.com/download/win → install, click **Next** on every screen.
2. **Docker Desktop:** https://www.docker.com/products/docker-desktop/ → **Windows – AMD64** → install → restart the PC.
3. Open **Docker Desktop**, skip signing in, and wait until it says **Engine running**.
   If it asks to update WSL, open PowerShell and run `wsl --update`.

**Get CRMS.** Open **PowerShell** (Start → type `PowerShell`) and run these one by one:

```powershell
cd C:\
git clone -b docker-setup https://github.com/MiroLim999/crms-laravel-12.git
cd C:\crms-laravel-12
Copy-Item .env.example .env
mkdir ml\models
```

Copy the **TrOcr-50k-broken-samples** folder from the USB into `C:\crms-laravel-12\ml\models`.
(From a zip: right-click it → **Extract All...** → **Browse...** → choose `C:\crms-laravel-12\ml\models` → **Extract**.)

**Check:** the file `ml\models\TrOcr-50k-broken-samples\config.json` must exist for the fine-tuned model, or `ml\models\base\config.json` must exist for the base model. If there is an extra folder level (`...\TrOcr-50k-broken-samples\TrOcr-50k-broken-samples\config.json`), move the inner folder up one level.

Now go to **step 3**.

---

## 2B. Mac (Apple silicon)

**Install two programs**

1. **Git:** open **Terminal** (Cmd + Space → type `Terminal`), type `git --version`, press Enter.
   If the Mac offers to install developer tools, click **Install** and wait.
2. **Docker Desktop:** https://www.docker.com/products/docker-desktop/ → **Mac – Apple Silicon** → open the file → drag Docker into **Applications**.
3. Open **Docker** from Applications, skip signing in, and wait until it says **Engine running**.
4. In Docker: **Settings** (gear icon) → **Resources** → **Memory**: at least **8 GB** → **Apply**.

**Get CRMS.** In **Terminal**, run these one by one:

```bash
cd ~
git clone -b docker-setup https://github.com/MiroLim999/crms-laravel-12.git
cd ~/crms-laravel-12
cp .env.example .env
mkdir ml/models
```

Copy the **TrOcr-50k-broken-samples** or **base** folder from the USB into the models folder:
Finder → **Go** → **Home** → `crms-laravel-12` → `ml` → `models`.
(From a zip: double-click it, then drag the folder it makes into `models`.)

**Check:** the file `ml/models/TrOcr-50k-broken-samples/config.json` must exist for the fine-tuned model, or `ml/models/base/config.json` must exist for the base model. If there is an extra folder level, move the inner folder up one level.

Now go to **step 3**.

---

## 2C. Linux

1. Install [Docker Engine](https://docs.docker.com/engine/install/) with the Compose plugin, and Git (`sudo apt install git` if it is missing).
2. Let your user run `docker` without `sudo`: `sudo usermod -aG docker $USER`, then log out and back in.
3. Get CRMS:

   ```bash
   cd ~
   git clone -b docker-setup https://github.com/MiroLim999/crms-laravel-12.git
   cd ~/crms-laravel-12
   cp .env.example .env
   mkdir ml/models
   unzip TrOcr-50k-broken-samples.zip -d ml/models
   ```

   (Or copy the folder from the USB into `ml/models`.) The file `ml/models/TrOcr-50k-broken-samples/config.json` must exist for the fine-tuned model, or `ml/models/base/config.json` must exist for the base model.

Now go to **step 3**.

---

## 3. Start CRMS

In the same PowerShell or Terminal window, first run the start script. It takes 10 to 45 minutes the first time, depending on the internet.

- Windows: `powershell -ExecutionPolicy Bypass -File .\docker-start.ps1`
- Mac and Linux: `sh docker-start.sh`

Then run these one by one:

```
docker compose exec web php artisan key:generate
docker compose exec web php artisan migrate --seed
docker compose restart
```

What each one does:

| Command | What it does | You should see |
|---|---|---|
| The start script | Builds the 5 boxes and starts them. It uses an NVIDIA graphics card if the computer has one that works, and the processor otherwise. Only slow the first time. | At the end, where the reader reads (card or processor) |
| `docker compose exec web php artisan key:generate` | Creates this computer's secret key and saves it in `.env`. Run it only once. | `Application key set successfully.` |
| `docker compose exec web php artisan migrate --seed` | Creates the empty database tables, the Super Admin account and the 3 default templates. | Tables marked `DONE`, then `Super Admin ready: superadmin@admin.com` |
| `docker compose restart` | Restarts the boxes so all of them use the new secret key. | |

To check the boxes, run `docker compose ps`. You should see `crms-web-1`, `crms-worker-1`, `crms-scheduler-1`, `crms-ocr-1` and `crms-mysql-1`. `crms-ocr-1` and `crms-mysql-1` should say `Up` and `(healthy)`; the reader can take a minute to get there. Before the `migrate --seed` command, `crms-worker-1` (and sometimes `crms-scheduler-1`) may say **`Restarting`**. That is normal: the database has no tables yet.

---

## 4. First login

1. Open **http://localhost:8080**.
2. Log in: `superadmin@admin.com` / `superadmin@admin.com`.
3. Change the password right away (it is written here and in the public code): initials badge (top right) → **Account Settings**.
4. **System → OCR Workspace** → **Approved model**: select the model folder you installed, either `TrOcr-50k-broken-samples` or `base` → **Save policy**. The model now shows **Active**.
   If it says **OCR service offline**, wait a minute and reload the page. The reader takes a moment to start.

CRMS is ready. Make the accounts for the staff under **User Accounts**.

---

## Every day

| To | Do this |
|---|---|
| **Start CRMS** | Docker Desktop → **Containers** → **crms** → ▶. Or `docker compose up -d` in the project folder. |
| **Stop CRMS** | Same place → ⏹. Or `docker compose down`. The records, scans and models stay. |
| **Open CRMS** | http://localhost:8080 |
| **See what is running** | `docker compose ps` |
| **Watch the worker's messages** | `docker compose logs -f worker`. Ctrl+C stops watching, not the worker. |

Run the commands in the project folder (`cd C:\crms-laravel-12` on Windows, `cd ~/crms-laravel-12` on Mac and Linux).

### What is kept where

| Where | What |
|---|---|
| `.env` | this computer's settings and secret key (never goes to GitHub) |
| `docker-data/storage` | the scans, line pictures and logs |
| `docker-data/backups` | the database backups you make (below) |
| `ml/models` | the models |
| Docker volume `crms_mysql-data` | the database itself |

---

## Get updates

In the project folder, run these one by one:

1. `git pull`
2. The start script again: Windows `powershell -ExecutionPolicy Bypass -File .\docker-start.ps1`, Mac and Linux `sh docker-start.sh`
3. `docker compose exec web php artisan migrate`

| Command | What it does |
|---|---|
| `git pull` | Downloads the newest code from GitHub. Records and models are not touched. |
| The start script | Rebuilds the boxes with the new code and starts them. Takes a few minutes, not the full first-time wait. |
| `docker compose exec web php artisan migrate` | Adds any new database tables or columns the new code needs. Existing records stay. |

---

## NVIDIA graphics card

**Nothing to do:** the start script uses an NVIDIA card by itself when the computer has one that works, and says so at the end ("The reader reads on the NVIDIA card"). Macs, AMD cards (like the RX 6600), Intel graphics and PCs without a graphics card always use the processor, and CRMS runs fine there.

Measured on an RTX 4050 laptop, reading 120 real line pictures: **38 seconds on the processor, 4 seconds on the card**, with the same text. A page then takes about 35 seconds instead of about 2 minutes, because finding the lines still uses the processor.

**What the card needs**

- An up-to-date NVIDIA driver. Tested with driver 617.14. Older drivers may not work.
- **Linux only:** the [NVIDIA Container Toolkit](https://docs.nvidia.com/datacenter/cloud-native/container-toolkit/latest/install-guide.html). On Windows, Docker Desktop needs nothing extra.
- **About 9 GB more disk space.** The reader's image is 9 GB with the card's software in it, instead of 1.8 GB.

**Check that it works:** open **http://127.0.0.1:8081/health**. Near the start it says `"device":"cuda"` when the card is used, and `"device":"cpu"` when it is not.

**If the card fails** at the first scan (a driver too old, a card too new for its CUDA version, too little memory), the reader switches to the processor by itself and logs why.

**Never use the card:** add `-Cpu` to the Windows start script (`--cpu` on Mac and Linux), or put `OCR_DEVICE=cpu` in `.env`.

**Without the start script:** add `-f docker-compose.yml -f docker-compose.gpu.yml` right after `docker compose` for every command that starts or builds CRMS:

```
docker compose -f docker-compose.yml -f docker-compose.gpu.yml up -d --build
```

A plain `docker compose up -d` (without the two `-f` parts) quietly puts the reader back on the processor. Nothing is lost, but check `/health` if scans suddenly feel slow.

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

## If something goes wrong

| You see | Do this |
|---|---|
| `port is already allocated` or `address already in use` | Another program is using 8080 or 8081. Close it, or change the number in `docker-compose.yml`: 8080 under `web`, or 8081 under `ocr` **and** in `OCR_BROWSER_API_URL`. |
| `bind source path does not exist` with `.env` in it | The `.env` file was not made. Make it (step 2), then start again. |
| A scan waits forever | The worker is not running. Run `docker compose ps`, then `docker compose logs worker`. |
| OCR Workspace says **OCR service offline** | The reader is still starting. Wait a minute and reload. Still offline? Run `docker compose logs ocr`. |
| Windows: Docker Desktop says WSL or virtualization is missing | Turn virtualization on in the BIOS, then restart. Windows 10 needs version 22H2 or newer. Run `wsl --update`. |
| Mac: scans stop halfway, or a box keeps restarting | Give Docker more memory: Docker Desktop → **Settings → Resources → Memory**, at least 8 GB. |
| Linux: `permission denied` with `docker.sock` in it | Run `sudo usermod -aG docker $USER`, then log out and back in. |

Something else? Take a screenshot of the error.

---

## Never run this on a computer with real records

```
docker compose down -v
```

The `-v` **deletes the database**. `docker compose down` without `-v` is safe.
