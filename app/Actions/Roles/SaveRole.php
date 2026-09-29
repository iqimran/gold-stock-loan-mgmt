<?php

namespace App\Actions\Roles;

use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveRole
{
    /**
     * Create a role (when $role is null) or update its name and permissions.
     *
     * @param  list<string>  $permissions
     *
     * @throws ValidationException
     */
    public function handle(?Role $role, string $name, array $permissions): Role
    {
        if ($role?->isAdmin()) {
            throw ValidationException::withMessages(['name' => 'The Admin role always has full access and cannot be modified.']);
        }

        if ($role?->isSystem() && $role->name !== $name) {
            throw ValidationException::withMessages(['name' => 'System roles cannot be renamed.']);
        }

        return DB::transaction(function () use ($role, $name, $permissions): Role {
            $role ??= new Role(['guard_name' => 'web']);
            $role->name = $name;
            $role->save();

            $role->syncPermissions($permissions);

            return $role;
        });
    }
}
