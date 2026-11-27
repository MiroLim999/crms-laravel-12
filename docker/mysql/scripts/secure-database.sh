#!/bin/bash
# Locks the CRMS database down. Safe to run again at any time:
#
#   docker compose exec mysql crms-secure-database
#
# It runs by itself the first time MySQL starts on an empty volume
# (docker/mysql/init/02-secure-database.sh). On a database made before this
# script existed, run it once by hand: it also replaces the old built-in
# passwords and encrypts the tables that were made unencrypted.
#
# What it sets up:
#   crms      owner: creates and changes tables. Only the one-off migrate
#             container signs in with it (docker compose run --rm migrate).
#   crms_app  the website, worker and scheduler: read and write rows, nothing
#             else (no CREATE, ALTER, DROP, TRIGGER, GRANT, FILE).
#   Every table in crms and crms_test encrypted on disk, and MySQL's own tables.
#   It fails loudly if any CRMS table is still unencrypted afterwards.
#
# Passwords come from the secret files (docker-data/secrets on the computer,
# made by docker-start). They are letters and digits only, so they can be
# written into SQL without quoting problems; anything else is refused.
set -euo pipefail

secret() {
    local file="/run/secrets/$1"
    if [ ! -r "$file" ]; then
        echo "secure-database: missing secret $file" >&2
        exit 1
    fi
    local value
    value=$(tr -d '\r\n' < "$file")
    if ! printf '%s' "$value" | grep -Eq '^[A-Za-z0-9]{24,}$'; then
        echo "secure-database: $1 must be 24 or more letters and digits" >&2
        exit 1
    fi
    printf '%s' "$value"
}

root_pw=$(secret db_root_password)
owner_pw=$(secret db_owner_password)
app_pw=$(secret db_app_password)

as_root() { MYSQL_PWD="$root_pw" mysql --protocol=socket -uroot "$@"; }

# A database made before the secrets existed still has the old built-in root
# password from docker-compose.yml: replace it with the secret one.
if ! as_root -e 'SELECT 1' >/dev/null 2>&1; then
    legacy='crms-root-local'
    if MYSQL_PWD="$legacy" mysql --protocol=socket -uroot -e 'SELECT 1' >/dev/null 2>&1; then
        echo "secure-database: replacing the old built-in root password"
        MYSQL_PWD="$legacy" mysql --protocol=socket -uroot <<SQL
ALTER USER IF EXISTS 'root'@'%' IDENTIFIED BY '$root_pw';
ALTER USER IF EXISTS 'root'@'localhost' IDENTIFIED BY '$root_pw';
SQL
    else
        echo "secure-database: cannot sign in as root with docker-data/secrets/db_root_password" >&2
        exit 1
    fi
fi

echo "secure-database: accounts"
as_root <<SQL
CREATE DATABASE IF NOT EXISTS crms;
CREATE DATABASE IF NOT EXISTS crms_test;

-- Owner: migrations only.
CREATE USER IF NOT EXISTS 'crms'@'%' IDENTIFIED BY '$owner_pw';
ALTER USER 'crms'@'%' IDENTIFIED BY '$owner_pw';
GRANT ALL PRIVILEGES ON crms.* TO 'crms'@'%';
GRANT ALL PRIVILEGES ON crms_test.* TO 'crms'@'%';

-- The running app: rows only. The tests (crms_test, never real records) make
-- and drop their own tables, so it may do that there.
CREATE USER IF NOT EXISTS 'crms_app'@'%' IDENTIFIED BY '$app_pw';
ALTER USER 'crms_app'@'%' IDENTIFIED BY '$app_pw';
REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'crms_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON crms.* TO 'crms_app'@'%';
GRANT ALL PRIVILEGES ON crms_test.* TO 'crms_app'@'%';

-- New tables in either schema are encrypted by default.
ALTER SCHEMA crms DEFAULT ENCRYPTION = 'Y';
ALTER SCHEMA crms_test DEFAULT ENCRYPTION = 'Y';

-- MySQL's own tables (accounts, grants).
ALTER TABLESPACE mysql ENCRYPTION = 'Y';

FLUSH PRIVILEGES;
SQL

echo "secure-database: encrypting tables made before encryption was on"
as_root -N -B -e "
    SELECT CONCAT('ALTER TABLE \`', t.TABLE_SCHEMA, '\`.\`', t.TABLE_NAME, '\` ENCRYPTION = ''Y'';')
    FROM information_schema.TABLES t
    JOIN information_schema.INNODB_TABLESPACES s
      ON s.NAME = CONCAT(t.TABLE_SCHEMA, '/', t.TABLE_NAME)
    WHERE t.TABLE_SCHEMA IN ('crms', 'crms_test')
      AND t.TABLE_TYPE = 'BASE TABLE'
      AND s.ENCRYPTION = 'N'" | as_root

left=$(as_root -N -B -e "
    SELECT COUNT(*) FROM information_schema.INNODB_TABLESPACES
    WHERE (NAME LIKE 'crms/%' OR NAME LIKE 'crms\_test/%' OR NAME = 'mysql')
      AND ENCRYPTION = 'N'")
if [ "$left" != "0" ]; then
    echo "secure-database: $left tablespace(s) are still not encrypted" >&2
    exit 1
fi

tables=$(as_root -N -B -e "
    SELECT COUNT(*) FROM information_schema.INNODB_TABLESPACES
    WHERE NAME LIKE 'crms/%' AND ENCRYPTION = 'Y'")
echo "secure-database: done. $tables crms table(s) encrypted; crms_app can read and write rows only."
