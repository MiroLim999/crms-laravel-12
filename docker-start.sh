#!/bin/sh
# Builds and starts CRMS in Docker, on the NVIDIA card when this computer has one
# that works. On a Mac everything runs on the processor: Docker cannot use its GPU.
#
#   sh docker-start.sh          or   sh docker-start.sh --cpu   (never use the card)
#
# The GPU file is added only when nvidia-smi answers and Docker knows the NVIDIA
# runtime (Linux needs the NVIDIA Container Toolkit for that). If Docker still
# cannot start the reader on the card, it starts again on the processor.
#
# --force-recreate: a rebuilt image is not always picked up by containers that
# already exist, so every container is made afresh. The records are kept.

cd "$(dirname "$0")" || exit 1

# The database passwords and the backup passphrase: Docker secrets, made once
# here (64 random letters and digits each) and kept in docker-data/secrets.
# Never shown, never in .env. Keep a copy of that folder somewhere safe.
make_secrets() {
    mkdir -p docker-data/secrets
    chmod 700 docker-data/secrets
    for name in db_root_password db_owner_password db_app_password backup_passphrase ocr_upload_secret; do
        file="docker-data/secrets/$name"
        if [ ! -s "$file" ]; then
            head -c 32 /dev/urandom | od -An -tx1 | tr -d ' 
' > "$file"
            echo "Made a new secret: $file"
        fi
        # Readable inside the containers (MySQL runs as its own user); the
        # folder itself is closed to other users of this computer.
        chmod 644 "$file"
    done
}
make_secrets

# Laravel's APP_KEY, written into .env here the first time. The containers can
# only read .env (docker-compose.yml), so php artisan key:generate cannot do it.
make_app_key() {
    if [ ! -f .env ]; then
        echo "There is no .env. Copy it first: cp .env.example .env"
        exit 1
    fi
    if grep -Eq '^APP_KEY=[[:space:]]*$' .env || ! grep -q '^APP_KEY=' .env; then
        key="base64:$(head -c 32 /dev/urandom | base64 | tr -d '\n')"
        awk -v key="$key" '
            /^APP_KEY=/ { print "APP_KEY=" key; done = 1; next }
            { print }
            END { if (!done) print "APP_KEY=" key }
        ' .env > .env.tmp && cat .env.tmp > .env && rm -f .env.tmp
        echo "Made a new APP_KEY in .env"
    fi
}
make_app_key

# The folders the containers write into, made here by this computer's user.
# The containers run as an ordinary user (crms, number 1000), not root; a folder
# Docker had to make itself would belong to root, and they could not write it.
make_data_folders() {
    mkdir -p docker-data/storage/app docker-data/storage/logs docker-data/backups ml/models
    # On Linux, folders an older CRMS (whose containers ran as root) wrote into
    # may still belong to root. Say how to hand them over, once.
    if [ "$(uname -s)" = Linux ]; then
        owned_by_root=$(find docker-data/storage ml/models -maxdepth 3 -uid 0 2>/dev/null | head -n 1)
        if [ -n "$owned_by_root" ]; then
            echo "Some CRMS files belong to root (from an older CRMS). Hand them to the containers' user with:"
            echo "  sudo chown -R 1000:1000 docker-data/storage ml/models"
        fi
    fi
}
make_data_folders

start_crms() {
    if [ "$1" = gpu ]; then
        docker compose -f docker-compose.yml -f docker-compose.gpu.yml up -d --build --force-recreate
    else
        docker compose -f docker-compose.yml up -d --build --force-recreate
    fi
}

mode=cpu
if [ "$1" != "--cpu" ] && command -v nvidia-smi >/dev/null 2>&1 && nvidia-smi -L >/dev/null 2>&1; then
    if docker info 2>/dev/null | grep -qi nvidia; then
        mode=gpu
    else
        echo "An NVIDIA card is here, but Docker cannot use it yet (install the NVIDIA Container Toolkit)."
    fi
fi

if [ "$mode" = gpu ]; then
    echo "NVIDIA card found: the handwriting reader will use it."
else
    echo "No NVIDIA card in use: everything runs on the processor."
fi

if ! start_crms "$mode"; then
    if [ "$mode" = gpu ]; then
        echo "Docker could not start the reader on the NVIDIA card. Starting again on the processor."
        mode=cpu
        start_crms cpu || { echo "Docker could not start CRMS. See the messages above."; exit 1; }
    else
        echo "Docker could not start CRMS. See the messages above."
        exit 1
    fi
fi

# Accounts, grants and encryption; also upgrades a database made before them.
echo "Securing the database..."
if ! docker compose exec -T mysql crms-secure-database; then
    echo "Could not secure the database. See the messages above; CRMS is not safe to use yet."
    exit 1
fi

echo "Waiting for the handwriting reader..."
device=""
i=0
while [ "$i" -lt 60 ]; do
    device=$(curl -s --max-time 3 http://127.0.0.1:8081/health | sed -n 's/.*"device":"\([^"]*\)".*/\1/p')
    [ -n "$device" ] && break
    i=$((i + 1))
    sleep 2
done

case "$device" in
    "") echo "The reader has not answered yet. Check it with: docker compose logs ocr" ;;
    cuda*) echo "The reader reads on the NVIDIA card."
           echo "If the card fails at the first scan, it switches to the processor by itself." ;;
    *) echo "The reader reads on the processor." ;;
esac
echo "CRMS is running: http://localhost:8080"
