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
