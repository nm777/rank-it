<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['inference.jev_key' => null]);
    }

    protected function setUpTraits(): array
    {
        $traits = class_uses_recursive(static::class);

        if (isset($traits[DatabaseMigrations::class]) || isset($traits[RefreshDatabase::class])) {
            $this->ensureSafeTestDatabase();
        }

        return parent::setUpTraits();
    }

    private function ensureSafeTestDatabase(): void
    {
        if (! $this->app->environment('testing')) {
            throw new RuntimeException('Database tests require the testing environment.');
        }

        $database = 'rank_it_test';
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'pgsql'
            || $connection->getDatabaseName() !== $database
            || $connection->selectOne('select current_database() as db', [], useReadPdo: false)?->db !== $database) {
            throw new RuntimeException("Database tests require the PostgreSQL database {$database}.");
        }
    }
}
