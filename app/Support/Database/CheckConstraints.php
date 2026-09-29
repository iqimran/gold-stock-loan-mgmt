<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

/**
 * Adds named CHECK constraints for financial invariants.
 *
 * Applied on PostgreSQL (the application database, enforced by app:check-environment). SQLite, used by
 * the default in-memory test suite, cannot add constraints to an existing table, so it is skipped there;
 * run `php artisan test -c phpunit.pgsql.xml` to exercise them. Dropping the table drops its constraints.
 */
class CheckConstraints
{
    /**
     * @param  array<string, string>  $constraints  constraint name => SQL boolean expression
     */
    public static function add(string $table, array $constraints): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($constraints as $name => $expression) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }
}
