-- Runs once: the first time the MySQL container starts with an empty data
-- volume (the official image runs every .sql file in this folder).
--
-- phpunit.xml points the tests at their own database, crms_test, so running
-- the tests inside Docker can never touch the real records in crms.
CREATE DATABASE IF NOT EXISTS crms_test;
GRANT ALL PRIVILEGES ON crms_test.* TO 'crms'@'%';
