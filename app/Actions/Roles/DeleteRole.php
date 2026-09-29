<?php

namespace App\Actions\Roles;

use App\Domain\Audit\AuditTrail;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteRole
{
    public function __construct(private readonly AuditTrail $audit) {}

    /**
     * @throws ValidationException
     */
    public function handle(Role $role): void
    {
        if ($role->isSystem()) {
            throw ValidationException::withMessages(['role' => 'System roles cannot be deleted.']);
        }

        if ($role->users()->exists()) {
            throw ValidationException::withMessages(['role' => 'Reassign the users of this role before deleting it.']);
        }

        DB::transaction(function () use ($role): void {
            // Recorded before the row goes, with what the role granted.
            $this->audit->record('role.deleted', $role, ['name' => $role->name, 'permissions' => $role->permissions->pluck('name')->sort()->values()->all()], [], "Role {$role->name} deleted");
            $role->delete();
        });
    }
}
