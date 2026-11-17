<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Refuses to boot a test against any database that is not a test one.
     *
     * Every feature test refreshes the database, which drops every table. The
     * test database is chosen in phpunit.xml, but Laravel ignores phpunit.xml
     * once `php artisan optimize` or `config:cache` has cached the config — and
     * the cached config names the real database. On 2026-11-12 that is exactly
     * how a test run emptied hrmd_tallysheet. The check runs here, after the
     * application boots and before any trait touches the database, and in the
     * base class so no test file can override it.
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        if ($database === ':memory:' || str_ends_with($database, '_testing')) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Refusing to run tests against the "%s" database: tests empty the database they use. '
            .'The config is probably cached — run `php artisan config:clear` (or `optimize:clear`) and try again. '
            .'Tests only run against a database whose name ends in "_testing".',
            $database,
        ));
    }
}
