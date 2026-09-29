<?php

namespace Database\Seeders;

use App\Enums\Permission as PermissionName;
use App\Enums\SystemRole;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Idempotent: safe to re-run. Existing role permissions are never overwritten,
 * so customisations made through the admin UI survive re-seeding.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (PermissionName::names() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        // Admin receives full access through Gate::before, so no permissions are attached.
        Role::findOrCreate(SystemRole::Admin->value, 'web');

        $generalUser = Role::query()->where('guard_name', 'web')->where('name', SystemRole::GeneralUser->value)->first();

        if (! $generalUser) {
            $generalUser = Role::create(['name' => SystemRole::GeneralUser->value, 'guard_name' => 'web']);
            $generalUser->syncPermissions(array_column(PermissionName::generalUserDefaults(), 'value'));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
