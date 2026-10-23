# syntax=docker/dockerfile:1
#
# The CRMS OCR service: the FastAPI app in ml/api that reads line crops with
# TrOCR. Laravel and the queue worker call it at http://ocr:8001 inside Docker;
# the browser reaches it at http://127.0.0.1:8001 to upload models.
#
# The model weights are not in the image (2.6 GB): docker-compose.yml shares
# ml/models from this computer, so models uploaded in the OCR workspace are kept.

FROM python:3.13-slim-bookworm

ENV PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1 \
    PIP_DEFAULT_TIMEOUT=120 \
    PIP_RETRIES=10 \
    PYTHONUNBUFFERED=1

# torch 2.6, the version the service runs on Windows, as a CPU build: Docker
# cannot use an Apple GPU, and a Mac has no NVIDIA card.
RUN pip install torch==2.6.0 torchvision==0.21.0 \
    --index-url https://download.pytorch.org/whl/cpu

COPY docker/ocr/requirements.txt /tmp/requirements.txt
RUN pip install -r /tmp/requirements.txt

# Same layout as the repository, so ml/api/main.py finds its neighbours
# (trocr_common.py, dataset_registry.py) and ml/models where it expects them.
WORKDIR /app
COPY ml/api ml/api
COPY ml/*.py ml/

EXPOSE 8001

# 0.0.0.0 inside the container so the other containers can reach it.
# docker-compose.yml still publishes it on 127.0.0.1 only, so on this
# computer it stays loopback-only, as serve.ps1 keeps it.
CMD ["python", "-m", "uvicorn", "ml.api.main:app", "--host", "0.0.0.0", "--port", "8001"]
