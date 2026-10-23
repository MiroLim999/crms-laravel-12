# Set up CRMS on another computer

For a **Windows 10/11 PC** or a **MacBook with Apple silicon (M1–M4)**. CRMS runs in Docker and opens at **http://localhost:8080**.

---

## 1. Before you go (on your laptop)

Copy this folder to a USB stick:

`C:\xampp\htdocs\crms-laravel-12\ml\models\TrOcr-50k-broken-samples`

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

Now go to **step 3**.

---

## 2B. MacBook (Apple silicon)

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

Copy the **TrOcr-50k-broken-samples** folder from the USB into the models folder:
Finder → **Go** → **Home** → `crms-laravel-12` → `ml` → `models`.

Now go to **step 3**.

---

## 3. Start CRMS (Windows and Mac)

In the same PowerShell or Terminal window, first run the start script. It takes 10–30 minutes the first time.

- Windows: `powershell -ExecutionPolicy Bypass -File .\docker-start.ps1`
- Mac: `sh docker-start.sh`

Then run these one by one:

```
docker compose exec web php artisan key:generate
docker compose exec web php artisan migrate --seed
docker compose restart
```

What each one does:

| Command | What it does |
|---|---|
| The start script | Builds the 5 boxes (website, worker, scheduler, handwriting reader, database) and starts them. It uses an NVIDIA graphics card if the computer has one that works, and the processor otherwise. Only slow the first time. |
| `docker compose exec web php artisan key:generate` | Creates this computer's secret key and saves it in `.env`. Run it only once. |
| `docker compose exec web php artisan migrate --seed` | Creates the empty database tables, the Super Admin account and the 3 default templates. |
| `docker compose restart` | Restarts the boxes so all of them use the new secret key. |

---

## 4. First login

1. Open **http://localhost:8080**.
2. Log in: `superadmin@admin.com` / `superadmin@admin.com`.
3. Change the password: initials badge (top right) → **Account Settings**.
4. **System → OCR Workspace** → **Approved model**: `TrOcr-50k-broken-samples` → **Save policy**.

Done.

---

## Every day

- **Start:** Docker Desktop → **Containers** → **crms** → ▶
- **Stop:** same place → ⏹
- **Open:** http://localhost:8080

## Get updates

Open PowerShell (Windows) or Terminal (Mac) and go to the project folder:

- Windows: `cd C:\crms-laravel-12`
- Mac: `cd ~/crms-laravel-12`

Then, one by one:

1. `git pull`
2. The start script again: Windows `powershell -ExecutionPolicy Bypass -File .\docker-start.ps1`, Mac `sh docker-start.sh`
3. `docker compose exec web php artisan migrate`

What each one does:

| Command | What it does |
|---|---|
| `git pull` | Downloads the newest code from GitHub. Records and models are not touched. |
| The start script | Rebuilds the boxes with the new code and starts them. Takes a few minutes, not the full first-time wait. |
| `docker compose exec web php artisan migrate` | Adds any new database tables or columns the new code needs. Existing records stay. |

## Never run

`docker compose down -v`: it deletes all records.

## NVIDIA graphics card

Nothing to do: the start script uses an NVIDIA card by itself when the computer has one that works, and it says so at the end ("The reader reads on the NVIDIA card"). If the card fails, the reader switches to the processor by itself.

Macs, AMD cards (like the RX 6600) and PCs without a graphics card always use the processor.

## Problem?

Take a screenshot of the error. More help is in `DOCKER.md`.
