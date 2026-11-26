<?php

namespace Tests\Feature;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class DatabaseTestGuardTest extends TestCase
{
    public function test_database_migrations_are_blocked_outside_the_testing_environment(): void
    {
        $test = new class('unused') extends DatabaseTraitTestCase
        {
            use DatabaseMigrations;
        };
        $this->app->instance('env', 'local');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Database tests require the testing environment.');

        $test->bootTraits($this->app);
    }

    public function test_refresh_database_is_blocked_for_a_different_database(): void
    {
        $test = new class('unused') extends DatabaseTraitTestCase
        {
            use RefreshDatabase;

            public function beginDatabaseTransaction(): never
            {
                self::fail('Database trait attempted to begin a transaction before the guard rejected setup.');
            }
        };
        config([
            'database.default' => 'guard_probe',
            'database.connections.guard_probe' => ['driver' => 'pgsql', 'database' => 'rank_it'],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Database tests require the PostgreSQL database rank_it_test.');

        $test->bootTraits($this->app);
    }
}

abstract class DatabaseTraitTestCase extends TestCase
{
    public function bootTraits(Application $app): void
    {
        $this->app = $app;

        $this->setUpTraits();
    }

    public function artisan($command, $parameters = []): never
    {
        self::fail("Database trait attempted to run {$command} before the guard rejected setup.");
    }
}
