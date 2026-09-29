<?php

namespace App\Actions\Roles;

use App\Domain\Audit\AuditTrail;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveRole
{
    public function __construct(private readonly AuditTrail $audit) {}

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
            $isNew = $role === null;
            $role ??= new Role(['guard_name' => 'web']);
            $before = $isNew ? [] : $this->snapshot($role);
            $role->name = $name;
            $role->save();

            $role->syncPermissions($permissions);

            $after = $this->snapshot($role->fresh());
            $isNew
                ? $this->audit->record('role.created', $role, [], $after, "Role {$role->name} created")
                : $this->audit->recordChanges('role.updated', $role, $before, $after, "Role {$role->name} updated");

            return $role;
        });
    }

    /**
     * @return array{name: string, permissions: list<string>}
     */
    private function snapshot(Role $role): array
    {
        return ['name' => $role->name, 'permissions' => $role->permissions->pluck('name')->sort()->values()->all()];
    }
}
