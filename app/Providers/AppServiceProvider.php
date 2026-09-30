<?php

namespace App\Providers;

use App\Domain\Alert\AlertSettings;
use App\Domain\Interest\InterestSettings;
use App\Domain\Settings\LoanSettings;
use App\Models\User;
use App\Support\Database\BlueprintMacros;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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

        // The /up health check (load balancers, Docker) also fails when the database is unreachable.
        Event::listen(DiagnosingHealth::class, fn () => DB::select('select 1'));

        $this->configureRateLimiting();
    }

    /**
     * Rate limits (docs/02 "Security"). Per user when signed in, else per IP. Generous for normal counter
     * work; they stop scripted abuse, credential stuffing and export flooding.
     */
    private function configureRateLimiting(): void
    {
        $key = fn (Request $request): string => $request->user()?->getAuthIdentifier() !== null
            ? 'user:'.$request->user()->getAuthIdentifier()
            : 'ip:'.$request->ip();

        // Sign-in form: LoginRequest already locks an email+IP pair after 5 failures; this caps one IP
        // trying many accounts.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(20)->by('ip:'.$request->ip()));
        // Password reset request / reset / confirmation: guessing or mail flooding.
        RateLimiter::for('password', fn (Request $request) => Limit::perMinute(6)->by('ip:'.$request->ip()));
        // Authenticated API.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($key($request)));
        // PDF / Excel generation is heavy and exports sensitive data.
        RateLimiter::for('exports', fn (Request $request) => Limit::perMinute(20)->by($key($request)));
        // Money- and custody-changing actions: payments, reversals, loan activate/close/cancel, collateral release.
        RateLimiter::for('financial', fn (Request $request) => Limit::perMinute(30)->by($key($request)));
    }
}
