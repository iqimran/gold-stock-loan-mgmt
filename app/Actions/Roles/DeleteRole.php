<?php

namespace App\Actions\Roles;

use App\Models\Role;
use Illuminate\Validation\ValidationException;

class DeleteRole
{
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

        $role->delete();
    }
}
