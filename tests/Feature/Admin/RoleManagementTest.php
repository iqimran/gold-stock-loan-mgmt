<?php

namespace Tests\Feature\Admin;

use App\Enums\Permission;
use App\Enums\SystemRole;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_roles()
    {
        $this->actingAs($this->admin())->get('/admin/roles')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/roles/index')
                ->has('roles', 2)
                ->where('roles.0.name', 'Admin')
                ->where('roles.0.users_count', 1)
                ->where('roles.0.permissions_count', count(Permission::cases())));
    }

    public function test_admin_can_create_a_custom_role()
    {
        $this->actingAs($this->admin())->post('/admin/roles', [
            'name' => 'Auditor',
            'permissions' => [Permission::UsersView->value, Permission::RolesView->value],
        ])->assertRedirect('/admin/roles')->assertSessionHas('success');

        $role = Role::findByName('Auditor');
        $this->assertEqualsCanonicalizing(['users.view', 'roles.view'], $role->permissions->pluck('name')->all());
    }

    public function test_role_input_is_validated()
    {
        $this->actingAs($this->admin())->post('/admin/roles', [
            'name' => SystemRole::GeneralUser->value,
            'permissions' => ['not.a.permission'],
        ])->assertSessionHasErrors(['name', 'permissions.0']);
    }

    public function test_admin_can_change_general_user_permissions()
    {
        $role = Role::findByName(SystemRole::GeneralUser->value);

        $this->actingAs($this->admin())->put("/admin/roles/{$role->id}", [
            'name' => SystemRole::GeneralUser->value,
            'permissions' => [Permission::RolesView->value],
        ])->assertRedirect('/admin/roles');

        $this->assertSame(['roles.view'], $role->fresh()->permissions->pluck('name')->all());
    }

    public function test_system_roles_cannot_be_renamed()
    {
        $role = Role::findByName(SystemRole::GeneralUser->value);

        $this->actingAs($this->admin())->put("/admin/roles/{$role->id}", [
            'name' => 'Staff',
            'permissions' => [],
        ])->assertSessionHasErrors('name');

        $this->assertSame(SystemRole::GeneralUser->value, $role->fresh()->name);
    }

    public function test_admin_role_cannot_be_modified()
    {
        $role = Role::findByName(SystemRole::Admin->value);

        $this->actingAs($this->admin())->put("/admin/roles/{$role->id}", [
            'name' => SystemRole::Admin->value,
            'permissions' => [Permission::RolesView->value],
        ])->assertSessionHasErrors('name');

        $this->assertCount(0, $role->fresh()->permissions);
    }

    public function test_custom_role_can_be_deleted_when_unused()
    {
        $role = Role::create(['name' => 'Temporary', 'guard_name' => 'web']);

        $this->actingAs($this->admin())->delete("/admin/roles/{$role->id}")->assertRedirect('/admin/roles');

        $this->assertNull(Role::find($role->id));
    }

    public function test_role_in_use_cannot_be_deleted()
    {
        $role = Role::create(['name' => 'Technician', 'guard_name' => 'web']);
        $this->generalUser()->syncRoles(['Technician']);

        $this->actingAs($this->admin())->delete("/admin/roles/{$role->id}")->assertSessionHasErrors('role');

        $this->assertNotNull(Role::find($role->id));
    }

    public function test_system_roles_cannot_be_deleted()
    {
        $role = Role::findByName(SystemRole::GeneralUser->value);

        $this->actingAs($this->admin())->delete("/admin/roles/{$role->id}")->assertSessionHasErrors('role');

        $this->assertNotNull(Role::find($role->id));
    }

    public function test_general_user_cannot_manage_roles()
    {
        $user = $this->generalUser();
        $role = Role::findByName(SystemRole::GeneralUser->value);

        $this->actingAs($user)->get('/admin/roles')->assertForbidden();
        $this->actingAs($user)->post('/admin/roles', ['name' => 'Hack', 'permissions' => []])->assertForbidden();
        $this->actingAs($user)->put("/admin/roles/{$role->id}", [
            'name' => $role->name,
            'permissions' => Permission::names(),
        ])->assertForbidden();
        $this->actingAs($user)->delete("/admin/roles/{$role->id}")->assertForbidden();

        $this->assertNull(Role::where('name', 'Hack')->first());
        $this->assertFalse($user->fresh()->can(Permission::UsersView->value));
    }
}
