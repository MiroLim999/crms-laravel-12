#!/bin/bash
# An encrypted backup of the records:
#
#   docker compose exec mysql crms-backup
#
# Writes docker-data/backups/crms-YYYY-MM-DD_HHMMSS.sql.gz.enc on the computer:
# the whole crms database (tables, rows, the audit log's triggers), compressed,
# then encrypted with AES-256 under the passphrase in
# docker-data/secrets/backup_passphrase. A .sha256 file beside it shows later
# whether the backup was changed or damaged.
#
# Without that passphrase the backup cannot be opened, by anyone. Keep a copy
# of it somewhere other than the backups (a password manager, a sealed note):
# a USB stick holding both is as good as an unencrypted backup.
set -euo pipefail

root_pw=$(tr -d '\r\n' < /run/secrets/db_root_password)
passphrase_file=/run/secrets/backup_passphrase
if [ ! -s "$passphrase_file" ]; then
    echo "backup: missing docker-data/secrets/backup_passphrase" >&2
    exit 1
fi

umask 077
stamp=$(date +%Y-%m-%d_%H%M%S)
out="/backups/crms-$stamp.sql.gz.enc"

MYSQL_PWD="$root_pw" mysqldump --protocol=socket -uroot \
        --single-transaction --set-gtid-purged=OFF --no-tablespaces \
        --routines --triggers --hex-blob crms \
    | gzip -9 \
    | openssl enc -aes-256-cbc -pbkdf2 -iter 600000 -salt -pass "file:$passphrase_file" -out "$out.part"
mv "$out.part" "$out"
(cd /backups && sha256sum "$(basename "$out")" > "$(basename "$out").sha256")

echo "backup: $(basename "$out") ($(du -h "$out" | cut -f1)), encrypted"
