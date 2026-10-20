<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Refuse unsafe database configuration before a testing trait can migrate it.
     * RefreshDatabase and DatabaseMigrations deliberately erase their database;
     * they must never run against an operator's development or production data.
     */
    public function createApplication()
    {
        $application = parent::createApplication();
        $connection = $application['db']->connection();
        $databaseName = $connection->getDatabaseName();

        if (! $application->environment('testing') || ! str_ends_with($databaseName, '_test')) {
            throw new RuntimeException('Tests require APP_ENV=testing and a dedicated database ending in _test. Clear cached configuration before testing.');
        }

        if (! in_array($connection->getDriverName(), ['mariadb', 'mysql'], true)) {
            throw new RuntimeException('Integration tests require MariaDB; SQLite does not verify the application locking rules.');
        }

        $server = $connection->selectOne('SELECT VERSION() AS server_version');

        if (! str_contains(strtolower($server->server_version), 'mariadb')) {
            throw new RuntimeException('The integration test connection must point to a MariaDB server.');
        }

        return $application;
    }
}
