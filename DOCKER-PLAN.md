# CRMS Docker Plan

Goal: run CRMS with Docker on other machines (Windows 10, Linux, MacBook Pro M4), with the setup stored on GitHub.

- **Last updated:** 2026-10-23
- **Branch for this work:** `docker-setup` (created 2026-10-23 from `main`). `main` stays the safe checkpoint.
- **Who does what:** **[You]** = you · **[Claude]** = Claude · **[Both]** = together

### How to tick a box

Change `[ ]` to `[x]` and save. Press **Ctrl+Shift+V** in VS Code to see the checklist view.
(With the "Markdown All in One" extension, put the cursor on a line and press **Alt+C**.)

---

## Progress at a glance

- [x] **Step 0:** Learn the Docker basics
- [x] **Step 1:** Rebuild the CRMS Docker files
- [ ] **Step 2:** Test on my laptop ⏭️ *next*
- [ ] **Step 2b:** "Stranger test" on my laptop
- [ ] **Upload** the `docker-setup` branch to GitHub
- [ ] **Step 6 (optional):** GitHub builds the boxes automatically
- [ ] **Step 3:** Windows 10 and Linux machines ⏸️ *later, when I'm at those machines*
- [ ] **Step 4:** MacBook Pro M4 ⏸️ *later, when I'm at the Mac*
- [ ] **Step 5:** Merge into `main` and tag `v1.0.0`

---

## Decisions already made

| Topic | Decision |
|---|---|
| Different machines | **One** set of Docker files for all of them. No separate versions. |
| Graphics card | Every machine uses the **processor** version. No graphics card add-on. (Docker can't use the AMD RX 6600 or the Mac's chip; only my RTX 4050 could.) |
| Records | Each machine is its **own separate copy**. Data stays only on that machine. |
| Safety | The app opens **only on the computer itself**, not from other devices on the Wi-Fi. |
| Models | **Not** on GitHub and **not** inside Docker. Handed over by Drive or USB into `ml/models`. |
| Open source | The code will be public. Real records, `.env` and models never go on GitHub. |

---

## ✅ Step 0: Learn the Docker basics (done)

- [x] **[You]** Install Docker Desktop. The bottom-left shows "Engine running".
- [x] **[You]** `docker run hello-world` printed "Hello from Docker!"
- [x] **[You]** Ran nginx and opened it at http://localhost:8090
- [x] **[Claude]** Tested the Mac's chip type (ARM): Kraken and TrOCR install, the model loads, and the results match the Intel/AMD version
- [x] **[Claude]** Recovered the deleted Docker files from the old image before the wipe
- [x] **[Claude]** Wiped Docker for a fresh start (19.73 GB freed)

---

## ✅ Step 1: Rebuild the CRMS Docker files [Both] (done 2026-10-23)

Claude explains each file **before** writing it. Nothing goes to GitHub in this step.

- [x] **[You]** Say **"start Step 1"**
- [x] **[Claude]** Create the `docker-setup` branch from `main`
- [x] **[You]** Choose the website address: **`localhost:8080`** (chosen 2026-10-23)
  - `localhost:8000`: same as now, but stop `serve.ps1` before starting Docker
  - `localhost:8080`: XAMPP and Docker can run at the same time
- [x] **[Claude]** `.dockerignore`: what never goes into a box (`.env`, models, records)
- [x] **[Claude]** `docker/app/Dockerfile` (+ `php.ini`, `requirements-kraken.txt`): recipe for the website, worker and scheduler
- [x] **[Claude]** `docker/ocr/Dockerfile` (+ `requirements.txt`): recipe for the handwriting reader
- [x] **[Claude]** `docker/mysql/init/01-test-database.sql`: creates the test database on first start
- [x] **[Claude]** `docker-compose.yml`: the "menu" that starts all 5 boxes together
- [x] **[Claude]** Kraken clean-up fix in `ml/line_markers.py`: a cancelled scan no longer leaves helpers holding 1.2 GB of memory (+ a Linux-only test that fails without the fix)
- [x] **[Claude]** `.gitignore`: add `/docker-data` (each machine's scans stay off GitHub)
- [x] **[Claude]** `docs/DOCKER-SETUP.md`: click-by-click guide for Windows, Mac and Linux
- [x] **[You]** Read each explanation and say OK before the next file
- [x] **[Claude]** Saved in a commit on `docker-setup` (local only, not on GitHub). `DOCKER-PLAN.md` left out on purpose.

---

## ⏭️ Step 2: Test on my laptop [Both]

- [x] **[You]** Build and start everything. The first time downloads several GB, so use good internet. (2026-10-23: about 5 min; images 2.6 + 1.8 + 1.3 GB)
  ```powershell
  cd C:\xampp\htdocs\crms-laravel-12
  docker compose up -d --build
  ```
- [x] **[You]** Check that all 5 boxes are running. `ocr` and `mysql` should say **healthy**. (Worker restarted until `migrate --seed` made the tables; now Up.)
  ```powershell
  docker compose ps
  ```
- [x] **[Claude]** All 312 PHP tests pass inside Docker (2026-10-23: 312/312)
- [x] **[Claude]** The Python tests (Kraken and OCR) pass inside Docker (66/66 Kraken in the website box, 9/9 OCR in the reader box)
- [ ] **[You]** Open the website, log in, and open each role's pages
- [ ] **[You]** The OCR Models page shows the fine-tuned model. Set it to **Active**.
- [ ] **[You]** Do a real scan: lines are found and the text is read
- [ ] **[You]** Dates and times look right (Philippine time)
- [ ] **[You]** Stop and start again. The data is still there.
  ```powershell
  docker compose down
  docker compose up -d
  ```
- [ ] **[Claude]** The normal XAMPP setup still works (Docker didn't break it)

---

## Step 2b: "Stranger test" on my laptop [You, Claude guides]

Set CRMS up again from zero, using **only** `docs/DOCKER-SETUP.md`, like someone who has never seen it.

- [ ] **[You]** Remove the Step 2 test copy so the laptop acts like a new machine.
  ⚠️ `-v` deletes the Docker test database. That's fine here because it's test data only. **Never do this on the client's machine.**
  ```powershell
  cd C:\xampp\htdocs\crms-laravel-12
  docker compose down -v
  ```
- [ ] **[You]** Make a fresh copy of the project in a new folder:
  ```powershell
  git clone -b docker-setup C:\xampp\htdocs\crms-laravel-12 C:\crms-stranger-test
  cd C:\crms-stranger-test
  ```
- [ ] **[You]** Follow `docs/DOCKER-SETUP.md` word for word. Don't use anything that isn't written there.
- [ ] **[You]** Copy the models in from the zip, as practice for the real handover
- [ ] **[You]** Log in and do a real scan
- [ ] **[You]** Write down every part of the guide that was unclear: ____________________
- [ ] **[Claude]** Fix `docs/DOCKER-SETUP.md` based on your notes
- [ ] **[You]** Clean up the test copy:
  ```powershell
  cd C:\crms-stranger-test
  docker compose down -v
  cd C:\
  Remove-Item -Recurse -Force C:\crms-stranger-test
  ```

---

## Upload the branch to GitHub [You approve]

- [ ] **[You]** Check that nothing private is included. `git status` must **not** list `.env`, `docker-data` or `ml/models`.
  ```powershell
  git status
  ```
- [x] **[You]** Upload the branch (done 2026-10-23, GitHub has `e08b397`):
  ```powershell
  git push -u origin docker-setup
  ```
- [ ] **[You]** On GitHub, the branch `docker-setup` is visible

---

## Step 6 (optional): GitHub builds the boxes automatically [Both]

Other machines then **download** ready-made boxes instead of building them. Do it before visiting the other machines if possible.

- [ ] **[Claude]** Add the GitHub robot file in `.github/workflows/`. It builds the Intel/AMD and Mac (ARM) versions.
- [ ] **[Claude]** Update `docker-compose.yml` so machines download the boxes from GitHub (ghcr.io)
- [ ] **[You]** Push, then watch the **Actions** tab on GitHub until it shows a green ✅
- [ ] **[You]** Make sure the boxes on GitHub are **public**, so other machines can download them without logging in
- [ ] **[You]** Test on my laptop: `docker compose pull` downloads instead of building

---

## Before visiting the other machines

- [ ] The `docker-setup` branch is on GitHub
- [ ] `docs/DOCKER-SETUP.md` is finished (Step 2b passed)
- [ ] The model zip is ready on Drive or USB (see the to-dos below)
- [ ] The machine owners installed Docker in advance:
  - Windows 10: version **22H2** (press Win+R, type `winver`), and **virtualization ON** in the BIOS
  - Linux: Docker Engine
  - Mac: Docker Desktop, **Apple silicon** version
- [ ] (Optional) Step 6 is done, so the machines only download

---

## ⏸️ Step 3: Windows 10 and Linux machines (later) [You, Claude guides]

Do this list once per machine.

- [ ] **[You]** Get the project:
  ```
  git clone -b docker-setup https://github.com/MiroLim999/crms-laravel-12.git
  cd crms-laravel-12
  ```
- [ ] **[You]** Follow `docs/DOCKER-SETUP.md`
- [ ] **[You]** Copy the model zip into `ml/models` and set the fine-tuned model to **Active**
- [ ] **[You]** Log in and do a real scan
- [ ] **[You]** Time for one scan: ______ (on the processor, so slower than my laptop with its graphics card; that's expected)
- [ ] **[You]** Linux only: scans save without "permission denied" errors
- [ ] **[You]** Write down any problems: ____________________

---

## ⏸️ Step 4: MacBook Pro M4 (later) [You, Claude guides]

- [ ] **[You]** Docker Desktop for Mac (**Apple silicon**) is installed. If the adviser says the trial isn't covered by Docker Desktop's free use, use **Colima** instead.
- [ ] **[You]** In Docker Desktop: **Settings → Resources → Memory**, check that it's at least **8 GB**
- [ ] **[You]** Get the project and follow `docs/DOCKER-SETUP.md` (same as Step 3)
- [ ] **[You]** Copy the model zip into `ml/models` and set the fine-tuned model to **Active**
- [ ] **[You]** Real scan works. Time for one scan: ______ (compare: about 2 min on my laptop in Docker)
- [ ] **[You]** Privacy check: on a phone connected to the same Wi-Fi, open the Mac's address. CRMS must **not** open.

---

## Step 5: Merge into `main` and tag `v1.0.0` [You approve]

- [ ] Steps 2, 2b, 3 and 4 are all ticked
- [ ] **[You approve]** Merge `docker-setup` into `main`
- [ ] **[You]** Tag the version and upload it:
  ```powershell
  git tag v1.0.0
  git push origin main --tags
  ```
- [ ] **[Claude]** Update `README.md`: Docker section and license
- [ ] **[Claude]** In `docs/DOCKER-SETUP.md`, remove `-b docker-setup` from the `git clone` command (the Docker files are on `main` by then)

---

## ✅ Optional NVIDIA GPU add-on (built and committed 2026-10-23, `e08b397`)

Only for computers with an NVIDIA card. The normal files still run on the processor everywhere.

- [x] **[Claude]** `docker-compose.gpu.yml` + a `TORCH_BUILD` option in `docker/ocr/Dockerfile` (default `cpu`, GPU build `cu124`)
- [x] **[Claude]** Tested on my RTX 4050 laptop: 120 real crops read in **37.7 s on the processor, 4.3 s on the GPU**, same text (confidence differs by at most 0.1 point). Reader image 9 GB vs 1.8 GB.
- [x] **[Claude]** Section added to `docs/DOCKER-SETUP.md`
- [ ] **[You]** Real scan with the GPU file in Docker (expect about 35 s a page)
- [x] **[You]** Say OK to commit it on `docker-setup` (done)
- [x] **[Claude]** Works on any NVIDIA model, with fallback (2026-10-24): the reader tests the card when it loads a model and switches to the processor if the card fails (old driver, unsupported card, out of memory); `OCR_DEVICE=auto|cuda|cpu`; start scripts `docker-start.ps1` / `docker-start.sh` pick GPU or processor by themselves. 4 new tests (fail on the old code). Tested on my laptop; not on another NVIDIA model, Linux or a Mac.
- [ ] Not done, you declined: half precision (fp16). Not tested: RTX 2050 (4 GB), Linux with NVIDIA Container Toolkit.

Switch on: `docker compose -f docker-compose.yml -f docker-compose.gpu.yml up -d --build`. Switch off: `docker compose up -d`.

---

## To-dos (any time)

- [ ] **License:** choose an open-source license with the adviser.
  - **MIT:** anyone can use and change it
  - **GPL:** changes must stay open source too

  `README.md` currently says "proprietary... All rights reserved", and there's no `LICENSE` file.
- [ ] **Docker license:** ask the adviser whether the registry's trial on the Mac counts as part of the capstone (education). If yes, Docker Desktop is free. If not, use Colima on the Mac.
- [ ] **Models:** zip each folder in `ml/models` (right-click → **Compress to ZIP file**):
  - [ ] `TrOcr-50k-broken-samples` (1.3 GB): **required**
  - [ ] `base` (1.3 GB): recommended, so the system works without internet
- [ ] **Models:** upload the zips to Drive and share them only with the people who need them. Don't put the link in the public README.

---

## Command cheat sheet

Run these from the project folder:

| Command | What it does |
|---|---|
| `docker compose up -d --build` | Build what changed, then start everything |
| `docker compose ps` | Show what's running |
| `docker compose logs -f worker` | Watch one box's messages (Ctrl+C stops watching) |
| `docker compose down` | Stop everything. Data, scans and models stay. |
| `docker compose exec web php artisan ...` | Run an artisan command inside the website box |
| `docker system df` | How much disk Docker uses |

⚠️ **Never** run `docker compose down -v` on a machine with real records. It deletes the database.
