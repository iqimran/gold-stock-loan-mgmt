<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;

class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::RolesView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::RolesManage->value);
    }

    public function update(User $user, Role $role): bool
    {
        return $user->can(Permission::RolesManage->value);
    }

    public function delete(User $user, Role $role): bool
    {
        return $user->can(Permission::RolesManage->value);
    }
}
