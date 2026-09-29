<?php

namespace Tests\Feature\Security;

use App\Enums\SystemRole;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Authorization regression sweep over EVERY authenticated web and API route (mirrors the Inventory POS).
 *
 * A signed-in user without any permission must be refused (403) everywhere except the self-service
 * routes below. A new module route that forgets its policy / permission check fails here.
 */
class RouteAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /** Routes every signed-in user may use: own account, dashboard welcome, sign-out, own API token. */
    private const SELF_SERVICE = [
        'dashboard', 'settings', 'settings/profile', 'settings/password', 'settings/appearance',
        'verify-email', 'email/verification-notification', 'confirm-password', 'logout',
        'api/v1/user', 'api/v1/auth/token',
    ];

    /**
     * @return list<array{0: string, 1: string, 2: bool}> [method, uri template, is API]
     */
    private function protectedRoutes(): array
    {
        $routes = [];

        /** @var RouteDefinition $route */
        foreach (Route::getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();
            $api = in_array('auth:sanctum', $middleware, true);

            if ((! $api && ! in_array('auth', $middleware, true)) || in_array($route->uri(), self::SELF_SERVICE, true)) {
                continue;
            }

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $routes[] = [$method, $route->uri(), $api];
            }
        }

        return $routes;
    }

    /**
     * @return array<string, int|string>
     */
    private function fixtures(): array
    {
        return [
            'customer' => Customer::factory()->create()->customer_no,
            'loan' => Loan::factory()->create()->loan_no,
            'user' => User::factory()->create()->id,
            'role' => Role::findByName(SystemRole::GeneralUser->value)->id,
        ];
    }

    private function url(string $uri, array $fixtures): string
    {
        return '/'.preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => (string) ($fixtures[$m[1]] ?? 1), $uri);
    }

    public function test_every_protected_route_refuses_a_user_without_permissions(): void
    {
        $fixtures = $this->fixtures();
        $nobody = User::factory()->create();
        $leaks = [];
        $routes = $this->protectedRoutes();

        foreach ($routes as [$method, $uri, $api]) {
            $api ? Sanctum::actingAs($nobody) : $this->actingAs($nobody);
            $status = ($api ? $this->json($method, $this->url($uri, $fixtures)) : $this->call($method, $this->url($uri, $fixtures)))->getStatusCode();

            if ($status !== 403) {
                $leaks[] = "{$method} {$uri} → {$status}";
            }
        }

        $this->assertNotEmpty($routes, 'Route discovery found no protected routes.');
        $this->assertSame([], $leaks, 'Routes reachable without permission:');
    }

    public function test_guests_are_redirected_to_login_and_api_guests_are_unauthenticated(): void
    {
        foreach ($this->protectedRoutes() as [$method, $uri, $api]) {
            if ($api) {
                $this->json($method, '/'.preg_replace('/\{(\w+)\??\}/', '1', $uri))->assertUnauthorized();
            } elseif ($method === 'GET') {
                $this->get('/'.preg_replace('/\{(\w+)\??\}/', '1', $uri))->assertRedirect('/login');
            }
        }
    }

    public function test_admin_has_full_access(): void
    {
        $fixtures = $this->fixtures();
        $admin = $this->admin();

        foreach ($this->protectedRoutes() as [$method, $uri, $api]) {
            // Signed links (email verification) reject forged URLs for everyone, by design.
            if ($method !== 'GET' || str_starts_with($uri, 'verify-email/')) {
                continue;
            }

            $api ? Sanctum::actingAs($admin) : $this->actingAs($admin);
            $status = ($api ? $this->getJson($this->url($uri, $fixtures)) : $this->get($this->url($uri, $fixtures)))->getStatusCode();

            $this->assertNotSame(403, $status, "GET {$uri}");
        }
    }

    public function test_deactivated_users_are_signed_out(): void
    {
        $user = $this->generalUser(['is_active' => false]);

        $this->actingAs($user)->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }
}
