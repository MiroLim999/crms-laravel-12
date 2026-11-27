# syntax=docker/dockerfile:1
#
# The CRMS database: the official MySQL image, set up so the records are
# encrypted on disk (docker/mysql/conf.d/security.cnf).
#
# The encryption key lives in its own volume (mysql-keyring), apart from the
# data (mysql-data): a copy of the data volume alone cannot be read.
FROM mysql:9.7

# The keyring component, loaded at startup. MySQL reads the manifest beside its
# own program and the component's settings from its plugin folder.
COPY keyring/mysqld.my /usr/sbin/mysqld.my
COPY keyring/component_keyring_file.cnf /usr/lib64/mysql/plugin/component_keyring_file.cnf

# Encryption on for every table, log and temporary file.
COPY conf.d/security.cnf /etc/mysql/conf.d/security.cnf

# The scripts below compress and encrypt backups.
RUN microdnf install -y gzip openssl && microdnf clean all

# The key's folder, owned by MySQL and closed to everyone else. A new named
# volume mounted here starts as a copy of it, so it keeps this owner.
# The key file must exist, even empty: the component refuses to start without
# it, and writes the key into it the first time MySQL starts.
RUN mkdir -p /var/lib/mysql-keyring \
    && touch /var/lib/mysql-keyring/component_keyring_file \
    && chown -R mysql:mysql /var/lib/mysql-keyring \
    && chmod 700 /var/lib/mysql-keyring \
    && chmod 600 /var/lib/mysql-keyring/component_keyring_file \
    && chmod 644 /usr/sbin/mysqld.my /usr/lib64/mysql/plugin/component_keyring_file.cnf /etc/mysql/conf.d/security.cnf

# The scripts, as commands that work from any shell (Git Bash on Windows
# rewrites paths that start with /):
#   docker compose exec mysql crms-backup
#   docker compose exec mysql crms-restore <file>
#   docker compose exec mysql crms-secure-database
COPY scripts/ /crms-scripts/
RUN chmod 755 /crms-scripts/*.sh \
    && ln -s /crms-scripts/backup.sh /usr/local/bin/crms-backup \
    && ln -s /crms-scripts/restore.sh /usr/local/bin/crms-restore \
    && ln -s /crms-scripts/secure-database.sh /usr/local/bin/crms-secure-database
