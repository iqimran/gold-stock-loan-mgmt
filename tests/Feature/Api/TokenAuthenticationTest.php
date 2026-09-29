<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TokenAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_user_can_obtain_a_token()
    {
        $user = $this->generalUser();

        $response = $this->postJson('/api/v1/auth/token', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'Counter PC',
        ]);

        $response->assertCreated()->assertJsonStructure(['token', 'token_type']);
        $this->assertCount(1, $user->tokens);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_token_is_not_issued_for_invalid_credentials()
    {
        $user = $this->generalUser();

        $this->postJson('/api/v1/auth/token', [
            'email' => $user->email,
            'password' => 'wrong-password',
            'device_name' => 'Counter PC',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_token_is_not_issued_for_inactive_users()
    {
        $user = User::factory()->inactive()->generalUser()->create();

        $this->postJson('/api/v1/auth/token', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'Counter PC',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_token_request_is_validated()
    {
        $this->postJson('/api/v1/auth/token', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password', 'device_name']);
    }

    public function test_token_authenticates_current_user_endpoint()
    {
        $user = $this->generalUser();
        $user->givePermissionTo('roles.view');
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/user')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.role', 'General User')
            ->assertJsonFragment(['roles.view']);
    }

    public function test_token_can_be_revoked()
    {
        $user = $this->generalUser();
        $user->givePermissionTo('roles.view');
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->deleteJson('/api/v1/auth/token')->assertNoContent();

        $this->assertCount(0, $user->fresh()->tokens);
    }

    public function test_deactivated_users_token_is_rejected_and_revoked()
    {
        $user = $this->generalUser();
        $user->givePermissionTo('roles.view');
        $token = $user->createToken('test')->plainTextToken;
        $user->update(['is_active' => false]);

        $this->withToken($token)->getJson('/api/v1/user')->assertForbidden();

        $this->assertCount(0, $user->fresh()->tokens);
    }

    public function test_api_actions_without_permission_are_forbidden()
    {
        $token = $this->generalUser()->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/users')->assertForbidden();
    }

    public function test_api_actions_with_permission_are_allowed()
    {
        $admin = $this->admin();
        $token = $admin->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/users')
            ->assertOk()
            ->assertJsonPath('data.0.email', $admin->email)
            ->assertJsonStructure(['data', 'links', 'meta']);
    }
}
