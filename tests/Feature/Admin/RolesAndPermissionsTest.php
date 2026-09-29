<?php

namespace Tests\Feature\Admin;

use App\Enums\Permission;
use App\Enums\SystemRole;
use App\Models\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission as PermissionModel;
use Tests\TestCase;

class RolesAndPermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_system_roles_and_all_permissions()
    {
        $this->assertEqualsCanonicalizing(SystemRole::names(), Role::pluck('name')->all());
        $this->assertEqualsCanonicalizing(Permission::names(), PermissionModel::pluck('name')->all());
    }

    public function test_general_user_role_receives_only_baseline_permissions()
    {
        $role = Role::findByName(SystemRole::GeneralUser->value);

        $this->assertEqualsCanonicalizing(
            array_column(Permission::generalUserDefaults(), 'value'),
            $role->permissions->pluck('name')->all(),
        );
        $this->assertFalse($role->hasPermissionTo(Permission::UsersView->value));
    }

    public function test_seeder_is_idempotent_and_preserves_customisations()
    {
        $role = Role::findByName(SystemRole::GeneralUser->value);
        $role->syncPermissions([Permission::RolesView->value]);

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertSame(2, Role::count());
        $this->assertSame(count(Permission::cases()), PermissionModel::count());
        $this->assertSame([Permission::RolesView->value], $role->fresh()->permissions->pluck('name')->all());
    }

    public function test_admin_has_every_ability()
    {
        $admin = $this->admin();

        foreach (Permission::names() as $permission) {
            $this->assertTrue($admin->can($permission), "Admin should have [{$permission}]");
        }

        $this->assertTrue($admin->can('an-ability-that-does-not-exist'));
    }

    public function test_general_user_only_has_explicitly_granted_permissions()
    {
        $user = $this->generalUser();

        $this->assertFalse($user->can(Permission::UsersView->value));
        $this->assertFalse($user->can(Permission::RolesView->value));

        $user->givePermissionTo(Permission::RolesView->value);

        $this->assertTrue($user->fresh()->can(Permission::RolesView->value));
    }
}
