<?php

/**
 * PHPUnit bootstrap.
 *
 * Loading Composer is the easy half. The rest exists because of a trap this project
 * walked into: the values in .env are also exported as real environment variables on
 * this machine. The PHP CLI copies the process environment into $_SERVER, and
 * Laravel's env() reads $_SERVER before $_ENV and putenv(). PHPUnit's
 * <env force="true"> can only write the latter two - so without the mirroring below,
 * every <env> in phpunit.xml is silently overruled, DB_DATABASE stays pointed at the
 * development database, and RefreshDatabase drops every table in it.
 *
 * PhpHandler runs before this file (see PHPUnit's TextUI\Application::run), so $_ENV
 * already holds the configured test values by the time this executes.
 */

require __DIR__.'/../vendor/autoload.php';

foreach ($_ENV as $key => $value) {
    $_SERVER[$key] = $value;
}
