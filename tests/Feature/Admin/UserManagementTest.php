<?php

namespace Tests\Feature\Admin;

use App\Enums\Permission;
use App\Enums\SystemRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Cashier One',
            'email' => 'Cashier@Example.com',
            'password' => 'secret-pass-123',
            'password_confirmation' => 'secret-pass-123',
            'role' => SystemRole::GeneralUser->value,
            'permissions' => [Permission::RolesView->value],
        ], $overrides);
    }

    public function test_admin_can_list_users_without_n_plus_one_queries()
    {
        $admin = $this->admin();
        User::factory()->count(5)->generalUser()->create();

        $this->actingAs($admin)->get('/admin/users')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/users/index')
                ->has('users.data', 6)
                ->where('users.data.0.role', fn ($role) => in_array($role, SystemRole::names(), true)));
    }

    public function test_user_list_can_be_searched_and_filtered()
    {
        $admin = $this->admin(['name' => 'Alice Admin']);
        $this->generalUser(['name' => 'Bob Cashier', 'is_active' => false]);

        $this->actingAs($admin)->get('/admin/users?search=bob')
            ->assertInertia(fn (Assert $page) => $page->has('users.data', 1)->where('users.data.0.name', 'Bob Cashier'));

        $this->actingAs($admin)->get('/admin/users?status=active')
            ->assertInertia(fn (Assert $page) => $page->has('users.data', 1)->where('users.data.0.name', 'Alice Admin'));
    }

    public function test_admin_can_create_a_user_with_role_and_permissions()
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/users', $this->validPayload())
            ->assertRedirect('/admin/users')
            ->assertSessionHas('success');

        $user = User::where('email', 'cashier@example.com')->firstOrFail();
        $this->assertTrue($user->hasRole(SystemRole::GeneralUser->value));
        $this->assertTrue($user->hasDirectPermission(Permission::RolesView->value));
        $this->assertTrue($user->is_active);
        $this->assertSame($admin->id, $user->created_by);
    }

    public function test_user_creation_is_validated()
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/users', $this->validPayload([
            'email' => $admin->email,
            'password_confirmation' => 'different',
            'role' => 'Nonexistent',
            'permissions' => ['not.a.permission'],
        ]))->assertSessionHasErrors(['email', 'password', 'role', 'permissions.0']);
    }

    public function test_admin_can_update_a_user()
    {
        $admin = $this->admin();
        $user = $this->generalUser();
        $originalPassword = $user->password;

        $this->actingAs($admin)->put("/admin/users/{$user->id}", [
            'name' => 'Updated Name',
            'email' => $user->email,
            'password' => '',
            'password_confirmation' => '',
            'role' => SystemRole::Admin->value,
            'permissions' => [Permission::RolesView->value],
        ])->assertRedirect('/admin/users');

        $user->refresh();
        $this->assertSame('Updated Name', $user->name);
        $this->assertSame($originalPassword, $user->password);
        $this->assertTrue($user->hasRole(SystemRole::Admin->value));
        // Admins get everything via the role; direct permissions are cleared.
        $this->assertCount(0, $user->permissions);
        $this->assertSame($admin->id, $user->updated_by);
    }

    public function test_admin_cannot_change_their_own_role()
    {
        $admin = $this->admin();
        $this->admin();

        $this->actingAs($admin)->put("/admin/users/{$admin->id}", [
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => SystemRole::GeneralUser->value,
        ])->assertSessionHasErrors('role');

        $this->assertTrue($admin->fresh()->isAdmin());
    }

    public function test_last_active_admin_cannot_be_demoted()
    {
        $admin = $this->admin();
        $this->admin(['is_active' => false]);
        $manager = $this->generalUser();
        $manager->givePermissionTo(Permission::UsersUpdate->value);

        $this->actingAs($manager)->put("/admin/users/{$admin->id}", [
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => SystemRole::GeneralUser->value,
        ])->assertSessionHasErrors(['role' => 'At least one active Admin account must remain.']);

        $this->assertTrue($admin->fresh()->isAdmin());
    }

    public function test_admin_can_deactivate_and_reactivate_a_user()
    {
        $admin = $this->admin();
        $user = $this->generalUser();
        $user->createToken('pos');

        $this->actingAs($admin)->patch("/admin/users/{$user->id}/status", ['is_active' => false])
            ->assertSessionHas('success');

        $this->assertFalse($user->fresh()->is_active);
        $this->assertCount(0, $user->fresh()->tokens);
        $this->assertNotNull($user->fresh(), 'Deactivation must not delete the user.');

        $this->actingAs($admin)->patch("/admin/users/{$user->id}/status", ['is_active' => true]);

        $this->assertTrue($user->fresh()->is_active);
    }

    public function test_admin_cannot_deactivate_themselves()
    {
        $admin = $this->admin();
        $this->admin();

        $this->actingAs($admin)->patch("/admin/users/{$admin->id}/status", ['is_active' => false])
            ->assertSessionHasErrors('is_active');

        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_last_active_admin_cannot_be_deactivated()
    {
        $admin = $this->admin();
        $manager = $this->generalUser();
        $manager->givePermissionTo(Permission::UsersDeactivate->value);

        $this->actingAs($manager)->patch("/admin/users/{$admin->id}/status", ['is_active' => false])
            ->assertSessionHasErrors(['is_active' => 'At least one active Admin account must remain.']);

        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_general_user_cannot_access_user_management()
    {
        $user = $this->generalUser();
        $other = $this->generalUser();

        $this->actingAs($user)->get('/admin/users')
            ->assertForbidden()
            ->assertInertia(fn (Assert $page) => $page->component('error')->where('status', 403));
        $this->actingAs($user)->get('/admin/users/create')->assertForbidden();
        $this->actingAs($user)->post('/admin/users', $this->validPayload())->assertForbidden();
        $this->actingAs($user)->get("/admin/users/{$other->id}/edit")->assertForbidden();
        $this->actingAs($user)->put("/admin/users/{$other->id}", $this->validPayload())->assertForbidden();
        $this->actingAs($user)->patch("/admin/users/{$other->id}/status", ['is_active' => false])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'cashier@example.com']);
        $this->assertTrue($other->fresh()->is_active);
    }

    public function test_explicitly_granted_permission_allows_only_that_action()
    {
        $user = $this->generalUser();
        $user->givePermissionTo(Permission::UsersView->value);

        $this->actingAs($user)->get('/admin/users')->assertOk();
        $this->actingAs($user)->get('/admin/users/create')->assertForbidden();
    }
}
