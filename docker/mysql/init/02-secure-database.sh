#!/bin/bash
# Runs once, the first time MySQL starts on an empty volume (after
# 01-test-database.sql): the accounts, grants and encryption from
# docker/mysql/scripts/secure-database.sh. That script can be run again by hand.
bash /crms-scripts/secure-database.sh
