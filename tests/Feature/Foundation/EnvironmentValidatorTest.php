<?php

namespace Tests\Feature\Foundation;

use App\Support\Environment\EnvironmentValidator;
use Tests\TestCase;

class EnvironmentValidatorTest extends TestCase
{
    private function validator(): EnvironmentValidator
    {
        return $this->app->make(EnvironmentValidator::class);
    }

    public function test_testing_configuration_is_valid(): void
    {
        $result = $this->validator()->check();

        $this->assertSame([], $result['errors']);
    }

    public function test_missing_app_key_is_reported(): void
    {
        config(['app.key' => '']);

        $this->assertContains('APP_KEY is not set. Run `php artisan key:generate`.', $this->validator()->check(false)['errors']);
    }

    public function test_non_pgsql_database_is_rejected_outside_testing(): void
    {
        config(['app.env' => 'local', 'database.default' => 'sqlite']);

        $errors = $this->validator()->check(false)['errors'];

        $this->assertContains('DB_CONNECTION must use the pgsql driver (current: sqlite).', $errors);
    }

    public function test_production_requires_debug_disabled_and_warns_about_http(): void
    {
        config(['app.env' => 'production', 'app.debug' => true, 'app.url' => 'http://shop.test']);

        $result = $this->validator()->check(false);

        $this->assertContains('APP_DEBUG must be false in production.', $result['errors']);
        $this->assertContains('APP_URL should use https:// in production.', $result['warnings']);
    }

    public function test_unreachable_database_is_reported(): void
    {
        config([
            'database.connections.broken' => ['driver' => 'sqlite', 'database' => '/nonexistent/path/db.sqlite', 'prefix' => ''],
            'database.default' => 'broken',
        ]);

        $errors = $this->validator()->check()['errors'];

        $this->assertNotEmpty(array_filter($errors, fn (string $error) => str_starts_with($error, 'Unable to connect to the [broken] database')));
    }

    public function test_command_succeeds_for_valid_configuration(): void
    {
        $this->artisan('app:check-environment')
            ->expectsOutputToContain('Environment configuration is valid.')
            ->assertSuccessful();
    }

    public function test_command_fails_for_invalid_configuration(): void
    {
        config(['app.key' => '']);

        $this->artisan('app:check-environment', ['--skip-database' => true])->assertFailed();
    }
}
