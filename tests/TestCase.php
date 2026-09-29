<?php

namespace Tests;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Seed roles and permissions whenever RefreshDatabase migrates.
     */
    protected bool $seed = true;

    protected string $seeder = RolesAndPermissionsSeeder::class;

    /**
     * Safety net for RefreshDatabase: never wipe a database that is not a test database, whatever
     * the environment says (in-memory SQLite, or a PostgreSQL database whose name ends in "_testing").
     *
     * Runs right after the application boots and before any trait refreshes the database.
     * (Not beforeRefreshingDatabase(): the RefreshDatabase trait's own method would shadow it.)
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        $connection = config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        if (config("database.connections.{$connection}.driver") !== 'sqlite' && ! str_ends_with($database, '_testing')) {
            throw new RuntimeException("Refusing to run tests against the non-test database [{$database}]. Use SQLite or a *_testing database.");
        }
    }

    protected function admin(array $attributes = []): User
    {
        return User::factory()->admin()->create($attributes);
    }

    protected function generalUser(array $attributes = []): User
    {
        return User::factory()->generalUser()->create($attributes);
    }
}
