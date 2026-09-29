<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProtectedRoutesTest extends TestCase
{
    use RefreshDatabase;

    public static function protectedPages(): array
    {
        return [
            'dashboard' => ['/dashboard'],
            'users' => ['/admin/users'],
            'create user' => ['/admin/users/create'],
            'roles' => ['/admin/roles'],
            'profile settings' => ['/settings/profile'],
        ];
    }

    #[DataProvider('protectedPages')]
    public function test_guests_are_redirected_to_login(string $uri)
    {
        $this->get($uri)->assertRedirect('/login');
    }

    public function test_guest_api_requests_are_unauthorized()
    {
        $this->getJson('/api/v1/user')->assertUnauthorized();
        $this->getJson('/api/v1/users')->assertUnauthorized();
    }
}
