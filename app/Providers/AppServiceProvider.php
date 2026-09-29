<?php

namespace App\Providers;

use App\Domain\Alert\AlertSettings;
use App\Domain\Interest\InterestSettings;
use App\Domain\Settings\LoanSettings;
use App\Models\User;
use App\Support\Database\BlueprintMacros;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        BlueprintMacros::register();

        // Resolved from Settings on each resolve: the interest method for NEW loans (existing loans carry
        // their own, see InterestSettings::forLoan) and the alert threshold.
        $this->app->bind(InterestSettings::class, fn ($app) => $app->make(LoanSettings::class)->interestMethod());
        $this->app->bind(AlertSettings::class, fn ($app) => AlertSettings::fromSettings($app->make(LoanSettings::class)));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $production = $this->app->isProduction();

        // Surface lazy loading (N+1), silently discarded attributes and missing attributes outside production.
        Model::shouldBeStrict(! $production);

        // Guard against `migrate:fresh`, `db:wipe` etc. against the production database.
        DB::prohibitDestructiveCommands($production);

        Password::defaults(fn () => $production
            ? Password::min(10)->letters()->mixedCase()->numbers()
            : Password::min(8));

        // Admin has full access to every ability.
        Gate::before(fn (User $user) => $user->isAdmin() ? true : null);
    }
}
