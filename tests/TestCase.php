<?php

namespace Tests;

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Refuse to run against anything but a test database.
     *
     * RefreshDatabase begins by dropping every table it can see, so pointing the
     * suite at the wrong connection destroys the development data rather than
     * failing. That is exactly what happened here: .env is also exported as real
     * environment variables on this machine, the PHP CLI copies those into $_SERVER,
     * Laravel's env() prefers $_SERVER, and phpunit.xml's DB_DATABASE never took
     * effect (see tests/bootstrap.php for the mirroring that fixes it).
     *
     * This check is the backstop for that class of mistake. It runs here rather than
     * in setUp() because RefreshDatabase is triggered by setUpTraits(), which is
     * already too late - the tables would be gone.
     */
    protected function refreshApplication()
    {
        parent::refreshApplication();

        $connection = config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        if (! str_ends_with($database, '_test')) {
            throw new RuntimeException(
                "Refusing to run tests against the '{$database}' database: the suite drops "
                ."every table it finds, and this name does not end in '_test'. Check that "
                .'phpunit.xml is being used and that no DB_DATABASE is exported in the '
                .'environment, which would override it.'
            );
        }
    }

    /**
     * Roles are fixed reference data that essentially everything depends on, so
     * seed them for every test rather than repeating it in each one.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }
}
