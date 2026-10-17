<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    private const string TESTING_DATABASE = 'barberhub_testing';

    /**
     * The tests refresh the schema (migrate:fresh), so they must never run
     * against another database, even if phpunit.xml stops pinning it.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        foreach (['pgsql', 'pgsql_admin'] as $connection) {
            $database = config("database.connections.{$connection}.database");

            if ($database !== self::TESTING_DATABASE) {
                throw new RuntimeException(sprintf(
                    'The tests must run on the "%s" database, but the "%s" connection points to "%s".',
                    self::TESTING_DATABASE,
                    $connection,
                    is_string($database) ? $database : 'an unknown database',
                ));
            }
        }

        return $app;
    }
}
