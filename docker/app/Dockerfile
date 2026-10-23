# syntax=docker/dockerfile:1
#
# The CRMS application image. docker-compose.yml starts it three times with a
# different command each: the website (php artisan serve), the queue worker
# that outlines and reads aligned pages (php artisan queue:work), and the
# scheduler that deletes unsubmitted pages (php artisan schedule:work).
#
# It holds Kraken as well as PHP because the queue worker runs
# ml/line_markers.py as a child process (app/Services/Lines/LineMarkers.php),
# so both have to live in the same container.
#
# Built in two stages: Node builds the CSS and JavaScript, then only the built
# files are copied into the final image, which has no Node in it.

# ---------------------------------------------------------------------------
# Stage 1: front-end files (npm run build)
# ---------------------------------------------------------------------------
FROM node:22-bookworm-slim AS assets

WORKDIR /src

# The package lists first, on their own: Docker reuses this step's result on
# the next build as long as they are unchanged, so npm ci only reruns when a
# package changes, not on every code edit.
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

# Then the code. Tailwind reads the Blade views and scripts to decide which
# CSS classes to keep, so it needs the whole project, not just resources/.
COPY . .
RUN npm run build

# ---------------------------------------------------------------------------
# Stage 2: the image that runs
# ---------------------------------------------------------------------------
# Debian 12 ("bookworm") with Python 3.13, the version both Windows Python
# environments use. Debian 12's own PHP is 8.2, the same as XAMPP's 8.2.12.
FROM python:3.13-slim-bookworm

# PHP and the extensions Laravel uses. pdo_mysql comes from php8.2-mysql;
# pcntl, which lets queue:work enforce --timeout, is built into php8.2-cli.
# gd is for the tests: UploadedFile::fake()->image() draws its images with it.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        php8.2-cli \
        php8.2-mysql \
        php8.2-mbstring \
        php8.2-xml \
        php8.2-curl \
        php8.2-zip \
        php8.2-intl \
        php8.2-bcmath \
        php8.2-gd \
        php8.2-opcache \
        php8.2-readline \
        unzip \
    && rm -rf /var/lib/apt/lists/*

# Same limits and OPcache settings as C:\xampp\php\php.ini.
COPY docker/app/php.ini /etc/php/8.2/cli/conf.d/99-crms.ini

# Kraken in its own virtual environment, like ml/.venv-kraken on Windows: it
# needs torch 2.14, while the OCR service (its own image) keeps torch 2.6.
# CPU builds of torch: Docker cannot use an Apple GPU, and a Mac has no NVIDIA
# card. Retries because large downloads from PyTorch's index sometimes reset.
ENV PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1 \
    PIP_DEFAULT_TIMEOUT=120 \
    PIP_RETRIES=10
COPY docker/app/requirements-kraken.txt /tmp/requirements-kraken.txt
RUN python -m venv /opt/kraken \
    && /opt/kraken/bin/pip install torch==2.14.0 torchvision==0.29.0 \
        --index-url https://download.pytorch.org/whl/cpu \
    && /opt/kraken/bin/pip install -r /tmp/requirements-kraken.txt \
    && /opt/kraken/bin/python -c "import kraken, torch; print('kraken ready, torch', torch.__version__)"

# Laravel finds Kraken here (config/services.php reads LINE_MARKERS_PYTHON).
# "cpu" because this image has no GPU build of torch to try.
ENV LINE_MARKERS_PYTHON=/opt/kraken/bin/python \
    LINE_MARKERS_DEVICE=cpu

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Composer packages, before the code for the same reason as npm ci above.
# Development packages stay in, so the tests can run inside the container.
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-progress --prefer-dist \
        --no-scripts --no-autoloader

COPY . .
COPY --from=assets /src/public/build public/build
COPY --from=assets /src/public/vendor/pdfjs public/vendor/pdfjs

# .dockerignore keeps storage/ out of the image, so its folders are made here.
# docker-compose.yml then shares storage/app and storage/logs with this
# computer, so scans and logs outlive the container.
RUN mkdir -p storage/app/private storage/app/public \
        storage/framework/cache/data storage/framework/sessions \
        storage/framework/testing storage/framework/views \
        storage/logs bootstrap/cache \
    && composer dump-autoload --optimize --no-interaction

EXPOSE 8000

# --no-reload: without it artisan serve ignores PHP_CLI_SERVER_WORKERS and
# drops the settings docker-compose.yml passes in. On Linux the workers are
# real, so the website answers several requests at once (Windows cannot).
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000", "--no-reload"]
