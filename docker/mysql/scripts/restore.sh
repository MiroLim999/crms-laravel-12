#!/bin/bash
# Puts an encrypted backup back:
#
#   docker compose exec mysql crms-restore crms-2026-11-27_220600.sql.gz.enc
#
# The file must be in docker-data/backups, and docker-data/secrets/backup_passphrase
# must be the passphrase it was made with. Every table in the backup replaces
# the one in the database: make a backup of the current state first.
set -euo pipefail

name="${1:-}"
if [ -z "$name" ]; then
    echo "restore: give the backup's file name, e.g. crms-2026-11-27_220600.sql.gz.enc" >&2
    ls -1 /backups/*.sql.gz.enc 2>/dev/null | xargs -r -n1 basename >&2 || true
    exit 1
fi
file="/backups/$(basename "$name")"
[ -f "$file" ] || { echo "restore: $file not found" >&2; exit 1; }

if [ -f "$file.sha256" ]; then
    (cd /backups && sha256sum -c --quiet "$(basename "$file").sha256") \
        || { echo "restore: $name was changed or damaged since it was made" >&2; exit 1; }
fi

root_pw=$(tr -d '\r\n' < /run/secrets/db_root_password)

openssl enc -d -aes-256-cbc -pbkdf2 -iter 600000 -pass file:/run/secrets/backup_passphrase -in "$file" \
    | gunzip \
    | MYSQL_PWD="$root_pw" mysql --protocol=socket -uroot crms

# Tables restored from the backup are encrypted like every other.
crms-secure-database
echo "restore: $name restored"
